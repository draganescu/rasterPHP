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
	// the largest picture upload() takes; doctor checks PHP's limits against
	// it, and the nginx config in private_paths.php allows a little more
	const UPLOAD_MB = 12;

	// ##Marking

	static function start() {
		if (!cms::loggedin() || config::get('format', 'html') !== 'html') return;
		template::instance()->marks = array();
	}

	static function editing() {
		return template::instance()->marks !== null;
	}

	// what page_field returns for a page field while an editor is looking.
	// $language (multilingual only) adds what the mark says about the
	// content language: lang, and in a translation shared (one value for
	// every language), untranslated and fallback (the default language's
	// value, shown while there is no translation)
	static function field($type, $slug, $name, $value, $default, $language = null) {
		$template = template::instance();
		$shown = ($value === false || $value === null) ? $default : $value;
		$mark = array(
			'kind' => 'field', 'type' => $type, 'slug' => $slug, 'field' => $name,
			'value' => (string)$shown, 'default' => trim((string)$default),
			'rich' => (bool)preg_match('/<[a-z][^>]*>/i', $shown.$default),
			'attr' => $template->current_attr !== null ? substr($template->current_attr, 1) : null,
		);
		$id = $template->mark($language ? $mark + $language : $mark);
		if ($template->current_attr !== null) {
			$template->pending_mark = $id;
			return $value;
		}
		return '<!--raster:s '.$id.'-->'.$shown.'<!--raster:e '.$id.'-->';
	}

	// Marks can't stay where the page can't show them as editable: in <head>,
	// inside a tag, or in <title>, <script>, <style>, <textarea> and
	// <option>. Those are taken out and their fields listed as hidden (the
	// editor offers them in its page panel). One pass from the top carries
	// what it saw from mark to mark (the last < and >, which of those tags
	// is open), so a long list costs no more than it does for visitors.
	static function settle($html) {
		$template = template::instance();
		if (!preg_match_all('/<!--raster:(s|a) (\d+)-->/', $html, $found, PREG_OFFSET_CAPTURE)) return $html;
		preg_match_all('/<(\/?)(title|script|style|textarea|option)\b/i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$body = stripos($html, '<body');
		$hidden = array();
		$from = 0; $lt = false; $gt = false; $next = 0; $open = array();
		foreach ($found[0] as $i => $match) {
			$offset = $match[1];
			$part = substr($html, $from, $offset - $from);
			if (($at = strrpos($part, '<')) !== false) $lt = $from + $at;
			if (($at = strrpos($part, '>')) !== false) $gt = $from + $at;
			$from = $offset;
			for (; $next < count($tags) && $tags[$next][0][1] < $offset; $next++) $open[strtolower($tags[$next][2][0])] = $tags[$next][1][0] === '';
			$in_tag = $lt !== false && ($gt === false || $lt > $gt);
			if ($body === false || $offset < $body || $in_tag || in_array(true, $open, true)) $hidden[(int)$found[2][$i][0]] = true;
		}
		if (!$hidden) return $html;
		foreach (array_keys($hidden) as $id) $template->marks[$id]['hidden'] = true;
		return preg_replace_callback('/<!--raster:(s|e|a) (\d+)-->/', function ($m) use ($hidden) {
			return isset($hidden[(int)$m[2]]) ? '' : $m[0];
		}, $html);
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
			'admin' => self::admin_pages(),
			'strings' => self::strings(),
			'lang' => class_exists('i18n') ? i18n::detect() : '',
			// the site's clock, in its time zone: scheduled items go live by it
			'now' => date('Y-m-d H:i:s'),
		);
		// multilingual: lang is the page's language, the one the editor
		// writes; its own words are in editor_lang (D9), and the dock
		// offers editor_languages to choose from
		if (class_exists('i18n') && i18n::enabled()) {
			$config['editor_lang'] = self::editor_lang();
			$config['languages'] = i18n::available();
			$config['editor_languages'] = self::editor_languages();
		}
		$script = '<script id="raster-editor-config" type="application/json">'
			.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
			.'</script><script src="'.config::get('link_uri').'api/cms/editor_script?v='.urlencode(self::version()).'" defer></script>';
		// the config holds stored text: put it in as it is, not as a
		// replacement pattern that would read $5 or \1 as a group
		$at = stripos($template->output, '</body>');
		if ($at !== false) $template->output = substr_replace($template->output, $script."\n", $at, 0);
	}

	// What the in-page editor can do on a rendered page, read back from its
	// config, for `raster render --as=editor` and MCP render_url: the role,
	// how many fields and items it can edit, and each list (+ new when it
	// ends with a card for a new item). null when the page has no editor.
	static function summary($html) {
		if (!preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', (string)$html, $m)) return null;
		$config = json_decode($m[1], true);
		if (!is_array($config)) return null;
		$summary = array('role' => isset($config['user']['role']) ? $config['user']['role'] : '', 'fields' => 0, 'items' => 0, 'lists' => array());
		foreach ((array)$config['marks'] as $mark) {
			$kind = isset($mark['kind']) ? $mark['kind'] : '';
			if ($kind === 'field' && empty($mark['hidden'])) $summary['fields']++;
			if ($kind === 'item') $summary['items']++;
			// lists render from the last in the page to the first
			if ($kind === 'collection') array_unshift($summary['lists'], $mark['collection'].($mark['list'] !== '' ? "('{$mark['list']}')" : '').(!empty($mark['addable']) ? ' + new' : ''));
		}
		return $summary;
	}

	// the staff pages this person may open, for the editor's Admin menu
	protected static function admin_pages() {
		$uri = trim((string)strtok((string)config::get('uri_string'), '?'), '/');
		$pages = array();
		foreach (authentication::all_admin_pages() as $page) {
			if (!authentication::can($page['role'])) continue;
			$pages[] = array('url' => $page['url'], 'title' => $page['title'], 'current' => $uri === $page['view']);
		}
		return $pages;
	}

	static function version() {
		$file = BASE.'models/cms/editor/editor.js';
		return is_file($file) ? substr(md5_file($file), 0, 8) : '0';
	}

	// the editor's own words: English in editor.js, other languages in
	// system/models/cms/editor/lang/<lang>.php, and your own in
	// <app>/i18n/<lang>/raster_editor.php. Multilingual, in the editor's
	// own language (editor_lang()), whatever the page's is
	static function strings() {
		$lang = class_exists('i18n') ? (i18n::enabled() ? self::editor_lang() : i18n::detect()) : '';
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

	// ##The editor's own language (D9)

	// the languages the editor has words for: English, the ones in
	// editor/lang/, and the site's own raster_editor.php dictionaries
	static function editor_languages() {
		$codes = array('en');
		foreach (glob(BASE.'models/cms/editor/lang/*.php') ?: array() as $file) $codes[] = basename($file, '.php');
		foreach (glob(APPBASE.'i18n/*/raster_editor.php') ?: array() as $file) $codes[] = basename(dirname($file));
		$codes = array_map('strtolower', $codes);
		return array_values(array_unique(array_filter($codes, function ($code) { return (bool)preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code); })));
	}

	// The language of the editor's words, separate from the page's: the
	// one the person chose (user.editor_lang), else the first of the
	// browser's (Accept-Language) the editor has words for, else the
	// site's default language when it has, else English. Asked once per
	// request (the config and the words both need it)
	static function editor_lang() {
		static $known = null;
		if ($known !== null) return $known;
		return $known = self::pick_editor_lang();
	}

	protected static function pick_editor_lang() {
		$have = self::editor_languages();
		$user = authentication::user();
		if ($user && !empty($user['id'])) {
			try {
				$chosen = strtolower((string)R::load('user', (int)$user['id'])->editor_lang);
				if (in_array($chosen, $have, true)) return $chosen;
			} catch (Exception $e) {
				log::warning('editor: '.$e->getMessage());
			}
		}
		if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			foreach (i18n::parse_header($_SERVER['HTTP_ACCEPT_LANGUAGE']) as $candidate) {
				$candidate = strtolower($candidate);
				if (in_array($candidate, $have, true)) return $candidate;
				if (in_array(substr($candidate, 0, 2), $have, true)) return substr($candidate, 0, 2);
			}
		}
		$default = strtolower(i18n::default_language());
		return in_array($default, $have, true) ? $default : 'en';
	}

	// lang: the editor's own language from now on, for this account ('' to
	// follow the browser again)
	static function set_lang() {
		cms::require_admin(true);
		$lang = util::post('lang');
		if (is_array($lang)) return self::fail("'lang' takes one value");
		$lang = strtolower((string)$lang);
		if ($lang !== '' && !in_array($lang, self::editor_languages(), true)) return self::fail("The editor has no words in '$lang'");
		$user = authentication::user();
		if (!$user || empty($user['id'])) return self::fail('Unknown account');
		try {
			cms_records::transaction(function () use ($user, $lang) {
				$bean = R::load('user', (int)$user['id']);
				$bean->editor_lang = $lang;
				R::store($bean);
			});
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		} catch (Throwable $e) {
			return self::broken($e);
		}
		return array('editor_lang' => $lang);
	}

	// ##Saving

	protected static function fail($message, $status = 400) {
		http_response_code($status);
		return array('error' => $message);
	}

	// a mistake in the code (a TypeError, not a refusal): logged, and a plain
	// 500 for the editor to show
	protected static function broken($e) {
		log::error('editor: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
		return self::fail('Something went wrong; it is in the error log', 500);
	}

	// a check() or an action said no: the names, and a line to show
	protected static function refused($e) {
		http_response_code(422);
		return array('error' => $e->getMessage(), 'problems' => $e->problems);
	}

	// a value that isn't of its field's type: the problem type_<type>
	// (type_number), which the editor words as it words a record's
	// refusals, with the field
	protected static function mistyped($e) {
		http_response_code(400);
		return array('error' => $e->getMessage(), 'problems' => array('type_'.$e->type), 'field' => $e->field);
	}

	// an upload refused: upload_missing, upload_too_big, upload_not_a_picture
	// or upload_not_stored, for the editor to word
	protected static function refused_upload($problem, $status = 400) {
		http_response_code($status);
		return array('error' => $problem, 'problems' => array($problem));
	}

	// Each name the editor posts holds one value (lists come only under
	// fields, examples and input): id[]=99 would be read as item 1, and
	// value[]=x stored as 'Array'. Every endpoint that reads one of these refuses that first.
	protected static function one_value() {
		foreach (array('type', 'slug', 'field', 'value', 'example', 'collection', 'id', 'action', 'revision') as $name) {
			if (is_array(util::post($name))) return self::fail("'$name' takes one value");
		}
		return null;
	}

	// the page's table, from type and slug: only the page the slug names,
	// and only one the person may open, so a page 'protected' keeps for
	// admins is not an editor's to read or change. The site's own fields
	// (sitepage) are on every page.
	protected static function page_type() {
		$type = strtolower((string)util::post('type'));
		if (!preg_match('/^[a-z0-9]+page$/', $type) || !cms_store::table_exists($type)) return null;
		if ($type === 'sitepage') return $type;
		$slug = (string)util::post('slug');
		if (cms::page_type($slug) !== $type || !authentication::may_open($slug === 'home' ? '/' : $slug)) return null;
		return $type;
	}

	// The content language of a save or a history call (multilingual): the
	// page's own, its URL's (/ro/api/cms/…, the address the editor's
	// config gives it). The editor posts it too, and a page open in
	// another language than the address says is refused (false). null
	// when the site isn't multilingual.
	protected static function language() {
		if (!i18n::enabled()) return null;
		$lang = i18n::current();
		$posted = util::post('lang');
		return !is_array($posted) && strtolower((string)$posted) === strtolower($lang) ? $lang : false;
	}

	// type, slug, field, value, and lang when multilingual. A field with a
	// translation is saved in the page's language; a shared one (a photo,
	// a link) is one value for every language
	static function save_field() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$type = self::page_type();
		$field = (string)util::post('field');
		if (!$type) return self::fail('Unknown page');
		// headline__ro is a language's column, never a field of its own
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $field) || strpos($field, '__') !== false || cms::reserved($field, 'field') || !array_key_exists($field, cms_store::columns($type))) {
			return self::fail("Unknown field '$field'");
		}
		$lang = self::language();
		if ($lang === false) return self::fail('This page is in '.i18n::current().': reload it');
		$write = $lang !== null && !i18n::is_default($lang) && in_array($field, cms_types::translated($type), true) ? $lang : null;
		$slug = $type === 'sitepage' ? 'site' : (string)util::post('slug');
		try {
			$saved = cms_store::update_page($type, $slug, array($field => (string)util::post('value')), array($field), $write);
		} catch (cms_refused $e) {
			return self::refused($e);
		} catch (cms_type_error $e) {
			return self::mistyped($e);
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		} catch (Throwable $e) {
			return self::broken($e);
		}
		// printed the way the page prints it (4.50 for a 14.50 mock-up), so the
		// editor shows what was stored, not what was typed
		$column = $write === null ? $field : cms_types::column($field, $write);
		return array('value' => cms_types::show($saved['fields'][$column], (string)util::post('example')), 'revision' => $saved['revision']);
	}

	// field => mock-up, from the item's mark: the decimals a number prints with
	protected static function examples() {
		$examples = util::post('examples');
		return is_array($examples) ? array_filter($examples, 'is_string') : array();
	}

	// collection, id (0 for a new item), fields[name]=value
	static function save_item() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$collection = (string)util::post('collection');
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection) || cms::reserved($collection, 'collection')) return self::fail('Unknown collection');
		$type = cms::collection_type($collection);
		$record = cms_records::info($collection);
		if ($record) cms_records::ensure($record);
		$columns = cms_store::columns($type);
		if (!$columns) return self::fail('Unknown collection');
		$fields = util::post('fields');
		if (!is_array($fields)) $fields = array();
		unset($fields['id'], $fields['updated_at']);
		$allowed = $record ? cms_records::writable($record) : array_diff(array_keys($columns), array('id', 'updated_at'));
		try {
			// the page prints text, so the editor gets the item as text too
			return cms_types::show_row(cms_store::save_item($type, (int)util::post('id'), $fields, $allowed, 'editor', $collection), self::examples());
		} catch (cms_refused $e) {
			return self::refused($e);
		} catch (cms_type_error $e) {
			return self::mistyped($e);
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		} catch (Throwable $e) {
			return self::broken($e);
		}
	}

	// collection, id: answers with the item, so the editor can bring it back
	static function delete_item() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$collection = (string)util::post('collection');
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection) || cms::reserved($collection, 'collection')) return self::fail('Unknown collection');
		$type = cms::collection_type($collection);
		$item = cms_store::get_item($type, (int)util::post('id'));
		if (!$item) return self::fail('No such item', 404);
		// a record's hidden fields never reach the editor, also on the way out
		$record = cms_records::info($collection);
		if ($record) $item = cms_records::shown($record, $item);
		try {
			cms_store::delete_item($type, (int)util::post('id'), $collection);
		} catch (cms_refused $e) {
			return self::refused($e);
		} catch (Throwable $e) {
			return self::broken($e);
		}
		return array('deleted' => true, 'item' => cms_types::show_row($item, self::examples()));
	}

	// collection, id, action, input[name]=value: runs one of the actions the
	// record's type declares, if the editor's role allows it
	static function action() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$collection = (string)util::post('collection');
		if (!cms_records::info($collection)) return self::fail('Unknown collection');
		$input = util::post('input');
		try {
			$item = cms_records::act($collection, (int)util::post('id'), (string)util::post('action'), is_array($input) ? $input : array());
			return cms_types::show_row(cms_records::shown(cms_records::info($collection), $item), self::examples());
		} catch (cms_refused $e) {
			return self::refused($e);
		} catch (cms_type_error $e) {
			return self::mistyped($e);
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		} catch (Throwable $e) {
			return self::broken($e);
		}
	}

	// type, and lang when multilingual: the page's revisions, newest
	// first, each with the language it changed (saved_lang)
	static function history() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$type = self::page_type();
		if (!$type) return self::fail('Unknown page');
		if (self::language() === false) return self::fail('This page is in '.i18n::current().': reload it');
		$revisions = cms_store::page_history($type, 30);
		// times with their offset, so the browser can say "5 minutes ago"
		foreach ($revisions as $key => $revision) {
			$time = strtotime((string)$revision['updated_at']);
			if ($time) $revisions[$key]['updated_at'] = date(DATE_ATOM, $time);
			$revisions[$key]['fields'] = cms_types::show_row($revision['fields']);
		}
		return array('revisions' => $revisions);
	}

	// type, slug, revision: a new revision with the old one's values, the
	// whole row, every language's translations included
	static function restore() {
		cms::require_admin(true);
		if ($bad = self::one_value()) return $bad;
		$type = self::page_type();
		if (!$type) return self::fail('Unknown page');
		$old = R::findOne($type, ' id = ? ', array((int)util::post('revision')));
		if (!$old) return self::fail('No such revision', 404);
		$values = $old->export();
		foreach (cms_store::$system_fields as $field) unset($values[$field]);
		$slug = $type === 'sitepage' ? 'site' : (string)util::post('slug');
		try {
			$saved = cms_store::update_page($type, $slug, $values, array_keys($values));
			$saved['fields'] = cms_types::show_row($saved['fields']);
			return $saved;
		} catch (cms_type_error $e) {
			return self::mistyped($e);
		} catch (Exception $e) {
			return self::fail($e->getMessage());
		} catch (Throwable $e) {
			return self::broken($e);
		}
	}

	// image: a picture, already cropped and sized by the browser
	static function upload() {
		cms::require_admin(true);
		$types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
		// one too big for PHP's own limits (upload_max_filesize) arrives as an error
		$error = empty($_FILES['image']) || is_array($_FILES['image']['error']) ? UPLOAD_ERR_NO_FILE : $_FILES['image']['error'];
		if (in_array($error, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) return self::refused_upload('upload_too_big');
		if ($error !== UPLOAD_ERR_OK) return self::refused_upload('upload_missing');
		if ($_FILES['image']['size'] > self::UPLOAD_MB * 1024 * 1024) return self::refused_upload('upload_too_big');
		$info = @getimagesize($_FILES['image']['tmp_name']);
		if (!$info || !isset($types[$info[2]])) return self::refused_upload('upload_not_a_picture');
		$folder = trim(config::get('raster_media_folder', 'media'), '/');
		$dir = dirname(BASE).'/'.$folder.'/';
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		$name = date('Ymd').'-'.bin2hex(random_bytes(6)).'.'.$types[$info[2]];
		if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir.$name)) return self::refused_upload('upload_not_stored', 500);
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
