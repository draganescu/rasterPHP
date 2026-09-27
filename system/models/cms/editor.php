<?php
// #The in-page editor, server side
//
// Editors (roles editor and admin) edit the page itself. While they are
// logged in, the CMS marks everything it prints (see template::$marks), this
// class checks where each mark landed, and adds the editor script with the
// details of every mark. The script saves through the editor_* methods of
// the cms model (POST /api/cms/editor_…, with the session token), which use
// cms_store like MCP and the command line: every page save is a revision,
// only fields the templates define can be written, and the same events fire.
class cms_editor {

	// ##Marking

	static function start() {
		if (!cms::loggedin() || config::get('format', 'html') !== 'html') return;
		template::instance()->marks = array();
	}

	static function editing() {
		return template::instance()->marks !== null;
	}

	// what page_field returns for a page field while an editor is looking
	static function field($type, $slug, $name, $value, $default) {
		$template = template::instance();
		$shown = ($value === false || $value === null) ? $default : $value;
		$id = $template->mark(array(
			'kind' => 'field', 'type' => $type, 'slug' => $slug, 'field' => $name,
			'value' => (string)$shown, 'default' => trim((string)$default),
			'rich' => (bool)preg_match('/<[a-z][^>]*>/i', $shown.$default),
			'attr' => $template->current_attr !== null ? substr($template->current_attr, 1) : null,
		));
		if ($template->current_attr !== null) {
			$template->pending_mark = $id;
			return $value;
		}
		return '<!--raster:s '.$id.'-->'.$shown.'<!--raster:e '.$id.'-->';
	}

	// Marks can't stay where the page can't show them as editable: in <head>,
	// inside a tag, or in <title>, <script>, <style>, <textarea> and
	// <option>. Those are taken out and their fields listed as hidden (the
	// editor offers them in its page panel).
	static function settle($html) {
		$template = template::instance();
		if (!preg_match_all('/<!--raster:(s|a) (\d+)-->/', $html, $found, PREG_OFFSET_CAPTURE)) return $html;
		$body = stripos($html, '<body');
		$hidden = array();
		foreach ($found[0] as $i => $match) {
			$id = (int)$found[2][$i][0];
			if (self::out_of_reach($html, $match[1], $body)) $hidden[$id] = true;
		}
		foreach (array_keys($hidden) as $id) {
			$template->marks[$id]['hidden'] = true;
			$html = str_replace(array('<!--raster:s '.$id.'-->', '<!--raster:e '.$id.'-->', '<!--raster:a '.$id.'-->'), '', $html);
		}
		return $html;
	}

	protected static function out_of_reach($html, $offset, $body) {
		if ($body === false || $offset < $body) return true;
		$before = substr($html, 0, $offset);
		$lt = strrpos($before, '<');
		$gt = strrpos($before, '>');
		if ($lt !== false && ($gt === false || $lt > $gt)) return true;
		foreach (array('title', 'script', 'style', 'textarea', 'option') as $tag) {
			if (!preg_match_all('/<(\/?)'.$tag.'\b/i', $before, $tags)) continue;
			if (end($tags[1]) === '') return true;
		}
		return false;
	}

	// the script and what it needs, before </body>
	static function inject($page_type, $slug) {
		$template = template::instance();
		if ($template->marks === null || stripos($template->output, '</body>') === false) return;
		$template->output = self::settle($template->output);
		$user = authentication::user();
		$config = array(
			'api' => config::get('link_uri').'api/cms/',
			'csrf' => util::csrf_token(),
			'page' => array('type' => $page_type, 'slug' => $slug),
			'user' => array('name' => $user ? ($user['name'] ?: strtok((string)$user['email'], '@')) : '', 'role' => $user ? $user['role'] : ''),
			'marks' => (object)$template->marks,
			'strings' => self::strings(),
			'lang' => class_exists('i18n') ? i18n::detect() : '',
		);
		$script = '<script id="raster-editor-config" type="application/json">'
			.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
			.'</script><script src="'.config::get('link_uri').'api/cms/editor_script?v='.urlencode(self::version()).'" defer></script>';
		$template->output = preg_replace('#</body>#i', $script."\n</body>", $template->output, 1);
	}

	static function version() {
		$file = BASE.'models/cms/editor/editor.js';
		return is_file($file) ? substr(md5_file($file), 0, 8) : '0';
	}

	// the editor's own words: English in editor.js, other languages in
	// system/models/cms/editor/lang/<lang>.php, and your own in
	// <app>/i18n/<lang>/raster_editor.php
	static function strings() {
		$lang = class_exists('i18n') ? i18n::detect() : '';
		if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', (string)$lang)) return array();
		$strings = array();
		foreach (array(BASE.'models/cms/editor/lang/'.$lang.'.php', APPBASE.'i18n/'.$lang.'/raster_editor.php') as $file) {
			if (is_file($file)) {
				$more = include $file;
				if (is_array($more)) $strings = array_merge($strings, $more);
			}
		}
		return (object)$strings;
	}

	// ##Saving

	protected static function fail($message, $status = 400) {
		http_response_code($status);
		return array('error' => $message);
	}

	protected static function page_type() {
		$type = strtolower((string)util::post('type'));
		if (!preg_match('/^[a-z0-9]+page$/', $type) || !cms_store::table_exists($type)) return null;
		return $type;
	}

	// type, slug, field, value
	static function save_field() {
		cms::require_admin(true);
		$type = self::page_type();
		$field = (string)util::post('field');
		if (!$type) return self::fail('Unknown page');
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $field) || cms::reserved($field, 'field') || !array_key_exists($field, cms_store::columns($type))) {
			return self::fail("Unknown field '$field'");
		}
		$slug = $type === 'sitepage' ? 'site' : (string)util::post('slug');
		try {
			$saved = cms_store::update_page($type, $slug, array($field => (string)util::post('value')), array($field));
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		}
		return array('value' => (string)$saved['fields'][$field], 'revision' => $saved['revision']);
	}

	// collection, id (0 for a new item), fields[name]=value
	static function save_item() {
		cms::require_admin(true);
		$collection = (string)util::post('collection');
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection) || cms::reserved($collection, 'collection')) return self::fail('Unknown collection');
		$type = cms::collection_type($collection);
		$columns = cms_store::columns($type);
		if (!$columns) return self::fail('Unknown collection');
		$fields = util::post('fields');
		if (!is_array($fields)) $fields = array();
		unset($fields['id'], $fields['updated_at']);
		$allowed = array_diff(array_keys($columns), array('id', 'updated_at'));
		try {
			return cms_store::save_item($type, (int)util::post('id'), $fields, $allowed);
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		}
	}

	// collection, id: answers with the item, so the editor can bring it back
	static function delete_item() {
		cms::require_admin(true);
		$collection = (string)util::post('collection');
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) return self::fail('Unknown collection');
		$type = cms::collection_type($collection);
		$item = cms_store::get_item($type, (int)util::post('id'));
		if (!$item) return self::fail('No such item', 404);
		cms_store::delete_item($type, (int)util::post('id'));
		return array('deleted' => true, 'item' => $item);
	}

	// type: the page's revisions, newest first
	static function history() {
		cms::require_admin(true);
		$type = self::page_type();
		if (!$type) return self::fail('Unknown page');
		$revisions = cms_store::page_history($type, 30);
		// times with their offset, so the browser can say "5 minutes ago"
		foreach ($revisions as $key => $revision) {
			$time = strtotime((string)$revision['updated_at']);
			if ($time) $revisions[$key]['updated_at'] = date(DATE_ATOM, $time);
		}
		return array('revisions' => $revisions);
	}

	// type, slug, revision: a new revision with the old one's values
	static function restore() {
		cms::require_admin(true);
		$type = self::page_type();
		if (!$type) return self::fail('Unknown page');
		$old = R::findOne($type, ' id = ? ', array((int)util::post('revision')));
		if (!$old) return self::fail('No such revision', 404);
		$values = $old->export();
		foreach (cms_store::$system_fields as $field) unset($values[$field]);
		$slug = $type === 'sitepage' ? 'site' : (string)util::post('slug');
		try {
			return cms_store::update_page($type, $slug, $values, array_keys($values));
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		}
	}

	// image: a picture, already cropped and sized by the browser
	static function upload() {
		cms::require_admin(true);
		$types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
		if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return self::fail('The upload did not arrive');
		if ($_FILES['image']['size'] > 12 * 1024 * 1024) return self::fail('Pictures can be up to 12 MB');
		$info = @getimagesize($_FILES['image']['tmp_name']);
		if (!$info || !isset($types[$info[2]])) return self::fail('Only JPEG, PNG, GIF and WebP pictures can be used');
		$folder = trim(config::get('raster_media_folder', 'media'), '/');
		$dir = dirname(BASE).'/'.$folder.'/';
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		$name = date('Ymd').'-'.bin2hex(random_bytes(6)).'.'.$types[$info[2]];
		if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir.$name)) return self::fail('The picture could not be stored', 500);
		// from the site's root, so the address survives a new domain or a static export
		$path = rtrim((string)parse_url(config::get('base_uri'), PHP_URL_PATH), '/');
		return array('url' => $path.'/'.$folder.'/'.$name, 'width' => $info[0], 'height' => $info[1]);
	}

	static function script() {
		header('Content-Type: application/javascript; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, max-age=31536000');
		readfile(BASE.'models/cms/editor/editor.js');
		return false;
	}
}
