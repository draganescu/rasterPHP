<?php
// #CMS content store
//
// Reads and writes CMS content. Shared by the in-page editor, the MCP
// endpoint and the command line so all three follow the same rules:
// - page fields are versioned: every save stores a new revision of the page
// - only fields that exist in the templates can be written; to add a field,
//   add an annotation to a view (the markup is the schema)
class cms_store {

	// columns RedBean or Raster manage, never edited as content
	static $system_fields = array('id', 'slug', 'updated_at', 'enabled', 'published_at');

	// ##Slugs, drafts and order

	static function slugify($text) {
		$text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
		if (function_exists('transliterator_transliterate')) {
			$text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
		} else {
			$text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
		}
		$text = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
		return substr($text, 0, 80) ?: 'item';
	}

	// the text a slug is made from: title, headline or name, else the first field
	static function slug_source($values) {
		foreach (array('title', 'headline', 'name') as $field) {
			if (!empty($values[$field])) return $values[$field];
		}
		foreach ($values as $field => $value) {
			if (!in_array($field, self::$system_fields) && is_string($value) && trim(strip_tags($value)) !== '') return $value;
		}
		return 'item';
	}

	static function unique_slug($type, $text, $id) {
		$base = self::slugify($text);
		$slug = $base;
		for ($i = 2; self::table_exists($type) && array_key_exists('slug', self::columns($type)) && R::count($type, ' slug = ? AND id != ? ', array($slug, (int)$id)) > 0; $i++) {
			$slug = $base.'-'.$i;
		}
		return $slug;
	}

	// SQL that hides drafts (enabled = 0) and posts published in the future
	static function published_sql($columns, $include_drafts = false) {
		$sql = ' 1 = 1 ';
		$bindings = array();
		if ($include_drafts) return array($sql, $bindings);
		if (array_key_exists('enabled', $columns)) $sql .= " AND (enabled IS NULL OR enabled != '0') ";
		if (array_key_exists('published_at', $columns)) {
			$sql .= " AND (published_at IS NULL OR published_at = '' OR published_at <= :raster_now) ";
			$bindings[':raster_now'] = date('Y-m-d H:i:s');
		}
		return array($sql, $bindings);
	}

	static function count_published($type, $filters = array()) {
		$columns = self::columns($type);
		list($sql, $bindings) = self::published_sql($columns);
		foreach ($filters as $key => $value) {
			if (!array_key_exists($key, $columns) || !preg_match('/^[a-z0-9_]+$/', $key)) continue;
			$sql .= ' AND '.$key.' = :f_'.$key.' ';
			$bindings[':f_'.$key] = $value;
		}
		return (int)R::count($type, $sql, $bindings);
	}

	// order=newest|oldest|<field>|-<field> (minus means descending)
	static function order_sql($order, $columns) {
		$order = trim((string)$order);
		if ($order === 'newest') return array_key_exists('published_at', $columns) ? "CASE WHEN published_at IS NULL OR published_at = '' THEN updated_at ELSE published_at END DESC, id DESC" : 'id DESC';
		if ($order === 'oldest' || $order === '') return 'id ASC';
		$desc = $order[0] === '-';
		$field = ltrim($order, '-');
		if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) return 'id ASC';
		return $field.($desc ? ' DESC' : ' ASC').', id ASC';
	}

	static function connect() {
		database::instance('cms');
		if (!database::configured()) {
			throw new RuntimeException('No database connection for the '.config::get('environment').' environment (see application/config/db/)');
		}
	}

	static function table_exists($type) {
		try {
			return in_array($type, R::inspect());
		} catch (Exception $e) {
			return false;
		}
	}

	static function columns($type) {
		if (!self::table_exists($type)) return array();
		try {
			return R::inspect($type);
		} catch (Exception $e) {
			return array();
		}
	}

	static function latest($type) {
		if (!self::table_exists($type)) return null;
		return R::findOne($type, ' ORDER BY id DESC ');
	}

	static function clean_value($value) {
		if (is_bool($value)) return $value ? '1' : '0';
		if ($value === null) return '';
		if (!is_scalar($value)) throw new InvalidArgumentException('Field values must be strings');
		return (string)$value;
	}

	// ##Pages

	static function page_values($type) {
		$page = self::latest($type);
		if (!$page) return null;
		$values = $page->export();
		foreach (self::$system_fields as $field) unset($values[$field]);
		return array(
			'revision' => (int)$page->id,
			'updated_at' => $page->updated_at,
			'fields' => $values,
		);
	}

	// Stores a new revision of the page with the given fields changed.
	// $allowed lists the fields the templates define; others are rejected.
	static function update_page($type, $slug, $values, $allowed) {
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidArgumentException("Unknown field '$field'. Fields come from the templates; known fields: ".implode(', ', $allowed));
			}
		}
		$latest = self::latest($type);
		if ($latest) {
			$page = R::duplicate($latest);
		} else {
			$page = R::dispense($type);
			$page->slug = $slug;
		}
		foreach ($values as $field => $value) {
			$page->$field = self::clean_value($value);
		}
		$page->updated_at = R::isoDateTime();
		R::store($page);
		util::content_changed();
		$saved = self::page_values($type);
		event::dispatch('cms.page_saved', array('type' => $type, 'slug' => (string)$page->slug, 'changed' => array_keys($values), 'fields' => $saved));
		return $saved;
	}

	static function page_history($type, $limit = 20) {
		if (!self::table_exists($type)) return array();
		$revisions = R::find($type, ' ORDER BY id DESC LIMIT '.max(1, (int)$limit));
		$history = array();
		foreach ($revisions as $revision) {
			$values = $revision->export();
			foreach (self::$system_fields as $field) unset($values[$field]);
			$history[] = array('revision' => (int)$revision->id, 'updated_at' => $revision->updated_at, 'fields' => $values);
		}
		return $history;
	}

	// ##Collections

	static function export_item($bean) {
		$item = $bean->export();
		$item['id'] = (int)$item['id'];
		return $item;
	}

	static function list_items($type, $limit = 50, $offset = 0) {
		if (!self::table_exists($type)) return array('total' => 0, 'items' => array());
		$items = R::find($type, ' ORDER BY id ASC LIMIT '.max(1, (int)$limit).' OFFSET '.max(0, (int)$offset));
		return array(
			'total' => (int)R::count($type),
			'items' => array_values(array_map(array('cms_store', 'export_item'), $items)),
		);
	}

	static function get_item($type, $id) {
		if (!self::table_exists($type)) return null;
		$bean = R::findOne($type, ' id = ? ', array((int)$id));
		return $bean ? self::export_item($bean) : null;
	}

	static function save_item($type, $id, $values, $allowed) {
		// slug, enabled (0 = draft) and published_at can always be set
		$allowed = array_merge($allowed, array('slug', 'enabled', 'published_at'));
		if (isset($values['slug'])) $values['slug'] = self::unique_slug($type, $values['slug'] ?: 'item', (int)$id);
		if (isset($values['published_at']) && $values['published_at'] !== '' && strtotime($values['published_at']) === false) {
			throw new InvalidArgumentException("published_at must be a date like 2026-10-01 09:00");
		}
		if (isset($values['published_at']) && $values['published_at'] !== '') $values['published_at'] = date('Y-m-d H:i:s', strtotime($values['published_at']));
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidArgumentException("Unknown field '$field'. Fields come from the templates; known fields: ".implode(', ', $allowed));
			}
		}
		if ($id) {
			$bean = R::findOne($type, ' id = ? ', array((int)$id));
			if (!$bean) throw new InvalidArgumentException("No item $id");
		} else {
			$bean = R::dispense($type);
			$bean->enabled = '1';
		}
		foreach ($values as $field => $value) {
			$bean->$field = self::clean_value($value);
		}
		if (!empty($bean->published_at) && strtotime($bean->published_at) > time()) {
			raster_cache::schedule(strtotime($bean->published_at));
		}
		if (empty($bean->slug)) {
			$bean->slug = self::unique_slug($type, self::slug_source($bean->export()), (int)$bean->id);
		}
		if ($bean->published_at === null) $bean->published_at = '';
		$bean->updated_at = R::isoDateTime();
		R::store($bean);
		util::content_changed();
		$item = self::export_item($bean);
		event::dispatch('cms.item_saved', array('collection' => self::collection_of($type), 'created' => !$id, 'item' => $item));
		return $item;
	}

	static function delete_item($type, $id) {
		$bean = self::table_exists($type) ? R::findOne($type, ' id = ? ', array((int)$id)) : null;
		if (!$bean) return false;
		$item = self::export_item($bean);
		R::trash($bean);
		util::content_changed();
		event::dispatch('cms.item_deleted', array('collection' => self::collection_of($type), 'item' => $item));
		return true;
	}

	// newsdata -> news
	static function collection_of($type) {
		return preg_replace('/data$/', '', $type);
	}

	// ##Users (kept for older code; see the authentication model)

	static function create_user($username, $password) {
		return authentication::save_user($username, $password, 'admin');
	}

	static function check_login($username, $password) {
		authentication::connect();
		return authentication::check_login($username, $password);
	}

	static function has_users() {
		authentication::connect();
		return authentication::has_users();
	}
}
