<?php
// #CMS content store
//
// Reads and writes CMS content. Shared by the in-page editor, the MCP
// endpoint and the command line so all three follow the same rules:
// - page fields are versioned: every save stores a new revision of the page
// - only fields that exist in the templates can be written; to add a field,
//   add an annotation to a view (the markup is the schema)
// - records of a type a model declares (records.php) are checked by that
//   model before every write, whoever makes it
require_once __DIR__.'/records.php';

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

	static function count_published($type, $filters = array(), $conditions = array()) {
		$columns = self::columns($type);
		list($sql, $bindings) = self::published_sql($columns);
		foreach ($filters as $key => $value) $conditions[] = array($key, '=', $value);
		list($more, $more_bindings) = self::conditions_sql($conditions, $columns, 'f');
		return (int)R::count($type, $sql.$more, $bindings + $more_bindings);
	}

	// ##List options
	//
	// What render.cms.<name>('…') and pagination.links('cms.<name>', '…')
	// take, as key=value pairs joined by &:
	//
	//   featured=1            the field equals the value
	//   stylist=?stylist      the value of ?stylist in the URL; left out when
	//                         the URL has none or it is empty (stylist=? for
	//                         a parameter named like the field)
	//   date>=today           also >, <, <= and != ; today, today+7, today-30
	//                         and now are dates (2026-10-03, 2026-10-03 18:30:00)
	//   order=date,-time      newest, oldest, a field, -field for descending,
	//                         several separated by commas
	//   limit=3
	//
	// conditions: list of (field, operator, value), the values resolved;
	// options: order and limit; fields: what a field the list mentions starts
	// as when the list adds it (the value of featured=1, empty for the rest).
	static function list_options($argument) {
		$out = array('conditions' => array(), 'options' => array(), 'fields' => array());
		foreach (explode('&', (string)$argument) as $chunk) {
			if (trim($chunk) === '') continue;
			if (!preg_match('/^([^=<>!]*?)\s*(>=|<=|!=|<>|=|>|<)\s*(.*)$/s', $chunk, $m)) $m = array($chunk, trim($chunk), '=', '');
			list(, $field, $operator, $value) = $m;
			$field = trim($field);
			if ($field === '') continue;
			// order and limit shape the list, they are not fields
			if (in_array($field, array('order', 'limit')) && $operator === '=') {
				$out['options'][$field] = $value;
				continue;
			}
			if ($operator === '<>') $operator = '!=';
			$dynamic = $value !== '' && ($value[0] === '?' || preg_match('/^(today([+-]\d+)?|now)$/', $value));
			if (!isset($out['fields'][$field])) $out['fields'][$field] = $operator === '=' && !$dynamic ? $value : '';
			$value = self::list_value($value, $field);
			if ($value === null) continue;
			$out['conditions'][] = array($field, $operator, $value);
		}
		return $out;
	}

	// a value as the list compares it; null leaves the condition out
	static function list_value($value, $field) {
		$value = (string)$value;
		if ($value !== '' && $value[0] === '?') {
			$name = substr($value, 1) !== '' ? substr($value, 1) : $field;
			$asked = isset($_GET[$name]) && is_string($_GET[$name]) ? trim($_GET[$name]) : '';
			return $asked === '' ? null : $asked;
		}
		if ($value === 'now') return date('Y-m-d H:i:s');
		if (preg_match('/^today(?:([+-])(\d+))?$/', $value, $m)) {
			return date('Y-m-d', isset($m[1]) ? strtotime($m[1].(int)$m[2].' days') : time());
		}
		return $value;
	}

	// the fields a list asks to be equal to a value: what a new item added to
	// that list starts with
	static function list_equals($argument) {
		$equals = array();
		foreach (self::list_options($argument)['conditions'] as $condition) {
			if ($condition[1] === '=') $equals[$condition[0]] = $condition[2];
		}
		return $equals;
	}

	// SQL for conditions on columns the table has; others are left out
	static function conditions_sql($conditions, $columns, $prefix = 'c') {
		$sql = '';
		$bindings = array();
		foreach (array_values($conditions) as $i => $condition) {
			list($field, $operator, $value) = $condition;
			if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) continue;
			if (!in_array($operator, array('=', '!=', '<', '<=', '>', '>='), true)) continue;
			$name = ':'.$prefix.$i.'_'.$field;
			// a field nobody filled is not equal to anything
			$sql .= $operator === '!=' ? " AND ($field IS NULL OR $field != $name) " : " AND $field $operator $name ";
			$bindings[$name] = $value;
		}
		return array($sql, $bindings);
	}

	// order=newest|oldest|<field>|-<field> (minus means descending), or
	// several separated by commas: order=date,time or order=-date,-time
	static function order_sql($order, $columns) {
		$parts = array();
		foreach (explode(',', (string)$order) as $part) {
			$part = trim($part);
			if ($part === 'newest') {
				$parts[] = array_key_exists('published_at', $columns) ? "CASE WHEN published_at IS NULL OR published_at = '' THEN updated_at ELSE published_at END DESC, id DESC" : 'id DESC';
				continue;
			}
			if ($part === 'oldest') { $parts[] = 'id ASC'; continue; }
			if ($part === '') continue;
			$desc = $part[0] === '-';
			$field = ltrim($part, '-');
			if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) continue;
			$parts[] = $field.($desc ? ' DESC' : ' ASC');
		}
		if (!$parts) return 'id ASC';
		$sql = implode(', ', $parts);
		return preg_match('/\bid (ASC|DESC)$/', $sql) ? $sql : $sql.', id ASC';
	}

	static function connect() {
		database::instance('cms');
		if (!database::configured()) {
			throw new RuntimeException('No database connection for the '.config::get('environment').' environment (see application/config/db/)');
		}
	}

	// A frozen schema (production) can't change during a request, so its
	// tables and columns are read once and kept. `raster schema --apply` and
	// each MCP tool call start over with forget().
	static $tables = null;
	static $columns = array();

	static function forget() {
		self::$tables = null;
		self::$columns = array();
	}

	static function table_exists($type) {
		try {
			if (!R::getRedBean()->isFrozen()) return in_array($type, R::inspect());
			if (self::$tables === null) self::$tables = R::inspect();
			return in_array($type, self::$tables);
		} catch (Exception $e) {
			return false;
		}
	}

	static function columns($type) {
		if (!self::table_exists($type)) return array();
		try {
			if (!R::getRedBean()->isFrozen()) return R::inspect($type);
			if (!isset(self::$columns[$type])) self::$columns[$type] = R::inspect($type);
			return self::$columns[$type];
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
		cms_records::dispatch('cms.page_saved', array('type' => $type, 'slug' => (string)$page->slug, 'changed' => array_keys($values), 'fields' => $saved));
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
		$info = cms_records::for_table($type);
		$items = array_values(array_map(array('cms_store', 'export_item'), $items));
		if ($info) {
			foreach ($items as $key => $item) $items[$key] = cms_records::shown($info, cms_records::decode($info, $item));
		}
		return array('total' => (int)R::count($type), 'items' => $items);
	}

	static function get_item($type, $id) {
		if (!self::table_exists($type)) return null;
		$bean = R::findOne($type, ' id = ? ', array((int)$id));
		if (!$bean) return null;
		$info = cms_records::for_table($type);
		return $info ? cms_records::shown($info, cms_records::decode($info, self::export_item($bean))) : self::export_item($bean);
	}

	// Creates ($id 0) or changes an item. $allowed lists the fields the caller
	// may write. $who is who asks: editor (the in-page editor and MCP), visitor
	// (a form) or model (the model's own code, which may write readonly and
	// hidden fields and the owner). Records of a declared type go through the
	// model's check() first.
	static function save_item($type, $id, $values, $allowed, $who = 'editor') {
		$info = cms_records::for_table($type);
		if (!$info) return self::store_item($type, $id, $values, $allowed, $who, null);
		cms_records::ensure($info);
		// the check and the write happen together, so two bookings can't both
		// take the last seats
		return cms_records::transaction(function () use ($type, $id, $values, $allowed, $who, $info) {
			return cms_store::store_item($type, $id, $values, $allowed, $who, $info);
		});
	}

	static function store_item($type, $id, $values, $allowed, $who, $info) {
		if ($info) {
			$allowed = $who === 'model' ? array_merge(array_keys($info['fields']), array('owner')) : array_values(array_intersect($allowed, cms_records::writable($info)));
		}
		// slug, enabled (0 = draft) and published_at can always be set, except by visitors
		if ($who !== 'visitor') $allowed = array_merge($allowed, array('slug', 'enabled', 'published_at'));
		if (isset($values['slug'])) $values['slug'] = self::unique_slug($type, $values['slug'] ?: 'item', (int)$id);
		if (isset($values['published_at']) && $values['published_at'] !== '' && strtotime($values['published_at']) === false) {
			throw new InvalidArgumentException("published_at must be a date like 2026-10-01 09:00");
		}
		if (isset($values['published_at']) && $values['published_at'] !== '') $values['published_at'] = date('Y-m-d H:i:s', strtotime($values['published_at']));
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				if ($info && array_key_exists($field, $info['fields'])) {
					throw new InvalidArgumentException("'$field' can't be changed here: the {$info['model']} model sets it");
				}
				throw new InvalidArgumentException("Unknown field '$field'. ".($info ? "The {$info['model']} model declares" : 'Fields come from the templates;')." known fields: ".implode(', ', $allowed));
			}
			// lists (line items, say) are stored as JSON
			if ($info && in_array($field, $info['lists'])) {
				if (is_string($value)) $value = json_decode($value, true);
				if (!is_array($value)) throw new InvalidArgumentException("'$field' is a list");
				$values[$field] = json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}
		if ($id) {
			$bean = R::findOne($type, ' id = ? ', array((int)$id));
			if (!$bean) throw new InvalidArgumentException("No item $id");
		} else {
			$bean = R::dispense($type);
			$bean->enabled = '1';
		}
		$before = $id ? self::export_item($bean) : null;
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
		if ($info && !$id) {
			$bean->created_at = R::isoDateTime();
			// a record remembers who made it, so they can read it later (a
			// booking staff type in for a caller belongs to nobody)
			if ($info['owner'] && !isset($values['owner']) && $who !== 'editor') {
				$user = authentication::user();
				$bean->owner = $user ? (int)$user['id'] : 0;
			}
			if ($info['owner'] && $bean->owner === null) $bean->owner = 0;
			foreach ($info['fields'] as $field => $default) {
				if ($bean->$field === null) $bean->$field = is_array($default) ? '[]' : (string)$default;
			}
		}
		if ($info) {
			$after = cms_records::decode($info, $bean->export());
			cms_records::check($info, $after, $before ? cms_records::decode($info, $before) : null);
		}
		$bean->updated_at = R::isoDateTime();
		R::store($bean);
		util::content_changed();
		$item = self::export_item($bean);
		if ($info) $item = cms_records::decode($info, $item);
		cms_records::dispatch('cms.item_saved', array('collection' => self::collection_of($type), 'created' => !$id, 'item' => $item));
		return $info && $who !== 'model' ? cms_records::shown($info, $item) : $item;
	}

	static function delete_item($type, $id) {
		$info = cms_records::for_table($type);
		if ($info) {
			return cms_records::transaction(function () use ($type, $id) { return cms_store::remove_item($type, $id); });
		}
		return self::remove_item($type, $id);
	}

	static function remove_item($type, $id) {
		$bean = self::table_exists($type) ? R::findOne($type, ' id = ? ', array((int)$id)) : null;
		if (!$bean) return false;
		$item = self::export_item($bean);
		$info = cms_records::for_table($type);
		if ($info) {
			$item = cms_records::decode($info, $item);
			cms_records::check($info, null, $item);
		}
		R::trash($bean);
		util::content_changed();
		cms_records::dispatch('cms.item_deleted', array('collection' => self::collection_of($type), 'item' => $item));
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
