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
require_once __DIR__.'/types.php';
require_once __DIR__.'/records.php';

class cms_store {

	// columns RedBean or Raster manage, never edited as content
	static $system_fields = array('id', 'slug', 'updated_at', 'enabled', 'published_at', 'saved_lang');

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

	// the text a slug is made from: title, headline or name, else the first
	// field. $skip lists fields a public URL must never carry (a record's
	// hidden fields, its owner).
	static function slug_source($values, $skip = array()) {
		foreach (array('title', 'headline', 'name') as $field) {
			if (!empty($values[$field]) && !in_array($field, $skip, true)) return $values[$field];
		}
		foreach ($values as $field => $value) {
			if (in_array($field, $skip, true) || in_array($field, self::$system_fields)) continue;
			if (is_string($value) && trim(strip_tags($value)) !== '') return $value;
		}
		return 'item';
	}

	static function unique_slug($type, $text, $id) {
		$base = self::slugify($text);
		if (!preg_match('/^[a-z0-9_]+$/', $type) || !self::table_exists($type) || !array_key_exists('slug', self::columns($type))) return $base;
		// every slug taken by another row, in one query that the slug index
		// answers: the base and base-<n>. SQLite uses an index for GLOB, not
		// for its LIKE, which ignores case. A slug holds only a-z, 0-9 and -,
		// so nothing in it is a wildcard. MySQL compares without case.
		$sqlite = R::getDatabaseAdapter()->getDatabase()->getDatabaseType() !== 'mysql';
		$rows = R::getCol("SELECT slug FROM `$type` WHERE (slug = ? OR slug ".($sqlite ? 'GLOB' : 'LIKE').' ?) AND id != ?', array($base, $sqlite ? "$base-*" : "$base-%", (int)$id));
		$taken = array_flip(array_map('strtolower', $rows));
		$slug = $base;
		for ($i = 2; isset($taken[$slug]); $i++) $slug = $base.'-'.$i;
		return $slug;
	}

	// SQL that hides drafts (enabled = 0) and posts published in the future
	static function published_sql($columns, $include_drafts = false) {
		$sql = ' 1 = 1 ';
		$bindings = array();
		if ($include_drafts) return array($sql, $bindings);
		if (array_key_exists('enabled', $columns)) $sql .= " AND (enabled IS NULL OR enabled != '0') ";
		if (array_key_exists('published_at', $columns)) {
			$sql .= " AND (published_at IS NULL".self::empty_text_sql($columns)." OR published_at <= :raster_now) ";
			$bindings[':raster_now'] = date('Y-m-d H:i:s');
		}
		return array($sql, $bindings);
	}

	// " OR published_at = ''" while the column is still text (a table made
	// before 2.1.7). A typed column holds NULL for an empty value, and MySQL
	// refuses to compare a DATETIME with ''
	static function empty_text_sql($columns) {
		return cms_types::of_column($columns['published_at']) === 'text' ? " OR published_at = ''" : '';
	}

	static function count_published($type, $filters = array(), $conditions = array()) {
		$columns = self::columns($type);
		list($sql, $bindings) = self::published_sql($columns);
		foreach ($filters as $key => $value) $conditions[] = array($key, '=', $value);
		list($more, $more_bindings) = self::conditions_sql($conditions, $columns, 'f');
		return (int)R::count($type, $sql.$more, $bindings + $more_bindings);
	}

	// ##A list's rows
	//
	// What render.cms.<name>('…') shows and pagination.links('cms.<name>', '…')
	// counts are built here, once, so the pager counts the rows the list
	// shows (#88).

	// how many a page of the list holds, and whether it is a limit list:
	// limit=3 shows at most three, the same three on every page of the site
	static function page_size($name, $options) {
		if (isset($options['limit']) && (int)$options['limit'] > 0) return array((int)$options['limit'], true);
		$size = (int)config::get($name.'_page_size') ?: ((int)config::get('raster_page_size') ?: 10);
		return array($size, false);
	}

	// The WHERE of a list of $name, array($sql, $bindings), or null when it
	// lists nothing whoever asks (owner=me with nobody logged in, an id or
	// slug the table can't have). $conditions are its list options' (as
	// list_options gives them), $filters equal values from the URL
	// (/news/news_items/tag/php) and the item asked for (id or slug), $uri
	// the ones of those the URL asked for. Drafts and scheduled items for
	// editors only; a private type's records for editors and their owners.
	static function list_where($name, $conditions, $filters = array(), $uri = array()) {
		$fields = self::columns(cms::collection_type($name));
		$record = cms_records::info($name);
		$compare = array();
		$mine = false;
		foreach ($conditions as $condition) {
			if ($condition === array('owner', '=', 'me')) $mine = true;
			if ($condition[1] === '=') $filters[$condition[0]] = $condition[2];
			else $compare[] = $condition;
		}
		list($sql, $bindings) = self::published_sql($fields, cms::loggedin());
		if ($record) {
			list($visible, $more) = cms_records::visibility_sql($record);
			$sql .= $visible;
			$bindings += $more;
			// owner=me: the logged in person's own records, editors included
			// (their /account shows what they made, not everyone's)
			if (isset($filters['owner']) && $filters['owner'] === 'me') {
				$filters['owner'] = cms_records::owner_me($record);
				if ($filters['owner'] === null) return null;
			}
		}
		// a record's hidden fields are no filter for visitors (no asking
		// "did this email book?"), and neither is owner, except the
		// template's own owner=me (#89)
		if ($record && !cms::loggedin()) {
			$secret = array_merge($record['hidden'], $mine ? array() : array('owner'));
			if ($mine && isset($uri['owner'])) raster_cache::skip();
			foreach ($secret as $hidden) {
				// a filter the URL asks for and the list ignores
				if (isset($uri[$hidden])) raster_cache::skip();
				unset($filters[$hidden]);
			}
			$secret[] = 'owner';
			$compare = array_filter($compare, function ($c) use ($secret) { return !in_array($c[0], $secret, true); });
		}
		foreach ($filters as $key => $value) {
			if (!array_key_exists($key, $fields) || !preg_match('/^[a-z0-9_]+$/', $key)) {
				if ($key === 'id' || $key === 'slug') return null;
				// /news/news_items/nonsense/x lists everything; it answers,
				// but every made-up field would be a page of its own
				if (array_key_exists($key, $uri)) raster_cache::skip();
				continue;
			}
			$compare[] = array($key, '=', $value);
		}
		// featured=1, date>=today, guests>4, each as its field's type
		list($more, $more_bindings) = self::conditions_sql($compare, $fields);
		return array($sql.$more, $bindings + $more_bindings);
	}

	// ##List options
	//
	// What render.cms.<name>('…') and pagination.links('cms.<name>', '…')
	// take, as key=value pairs joined by &:
	//
	//   featured=1            the field equals the value
	//   stylist=?stylist      the value of ?stylist in the URL; left out when
	//                         the URL has none or it is empty
	//   date>=today           also >, <, <= and != ; today is the date the
	//                         page is shown (2026-10-03). Values compare as
	//                         the field's type: guests>4 as numbers
	//   order=date,-time      newest, oldest, a field, -field for descending,
	//                         several separated by commas
	//   limit=3
	//
	// conditions: list of (field, operator, value), the values resolved;
	// options: order and limit; fields: what a field the list mentions starts
	// as when the list adds it (the value of featured=1, empty for the rest).
	// Model code (cms_records::find) passes $from_url false: there a ?x
	// value is a mistake, not the URL's, and throws.
	static function list_options($argument, $from_url = true) {
		$out = array('conditions' => array(), 'options' => array(), 'fields' => array());
		foreach (explode('&', (string)$argument) as $chunk) {
			if (trim($chunk) === '') continue;
			if (!preg_match('/^([^=<>!]*?)\s*(>=|<=|!=|=|>|<)\s*(.*)$/s', $chunk, $m)) $m = array($chunk, trim($chunk), '=', '');
			list(, $field, $operator, $value) = $m;
			$field = trim($field);
			if ($field === '') continue;
			// order and limit shape the list, they are not fields
			if (in_array($field, array('order', 'limit')) && $operator === '=') {
				$out['options'][$field] = $value;
				continue;
			}
			$dynamic = $value === 'today' || ($value !== '' && $value[0] === '?');
			if (!$from_url && $value !== '' && $value[0] === '?') throw new InvalidArgumentException("find() takes values, not $value: pass the value");
			if (!isset($out['fields'][$field])) $out['fields'][$field] = $operator === '=' && !$dynamic ? $value : '';
			$value = self::list_value($value);
			if ($value === null) continue;
			$out['conditions'][] = array($field, $operator, $value);
		}
		return $out;
	}

	// a value as the list compares it; null leaves the condition out
	static function list_value($value) {
		$value = (string)$value;
		if ($value !== '' && $value[0] === '?') {
			$name = substr($value, 1);
			$asked = isset($_GET[$name]) && is_string($_GET[$name]) ? trim($_GET[$name]) : '';
			return $asked === '' ? null : $asked;
		}
		return $value === 'today' ? date('Y-m-d') : $value;
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
			// the value as the field's type: guests>4 compares numbers, and a
			// value that can't be one (guests=many) matches nothing
			try {
				$value = cms_types::clean(cms_types::of_column($columns[$field]), $value, $field);
			} catch (InvalidArgumentException $e) {
				$sql .= $operator === '!=' ? '' : ' AND 1 = 0 ';
				continue;
			}
			if ($value === null) {
				$sql .= $operator === '=' ? " AND `$field` IS NULL " : ($operator === '!=' ? " AND `$field` IS NOT NULL " : ' AND 1 = 0 ');
				continue;
			}
			$name = ':'.$prefix.$i.'_'.$field;
			// a field nobody filled is not equal to anything
			$sql .= $operator === '!=' ? " AND (`$field` IS NULL OR `$field` != $name) " : " AND `$field` $operator $name ";
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
				$parts[] = array_key_exists('published_at', $columns) ? "CASE WHEN published_at IS NULL".self::empty_text_sql($columns)." THEN updated_at ELSE published_at END DESC, id DESC" : 'id DESC';
				continue;
			}
			if ($part === 'oldest') { $parts[] = 'id ASC'; continue; }
			if ($part === '') continue;
			$desc = $part[0] === '-';
			$field = ltrim($part, '-');
			if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) continue;
			$parts[] = "`$field`".($desc ? ' DESC' : ' ASC');
		}
		if (!$parts) return 'id ASC';
		$sql = implode(', ', $parts);
		return preg_match('/\bid`? (ASC|DESC)$/', $sql) ? $sql : $sql.', id ASC';
	}

	static function connect() {
		database::instance();
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
		cms_types::forget();
	}

	// Why the database doesn't answer, or null when it does. table_exists()
	// can't tell a missing table from a database that is down; this asks
	// once, at the start of the request (cms::setup).
	static function unreachable() {
		try {
			$tables = R::inspect();
		} catch (Exception $e) {
			return $e->getMessage();
		}
		if (R::getRedBean()->isFrozen()) self::$tables = $tables;
		return null;
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

	// ##Languages (#200, D8)

	// $row (a page row or an item, as read_row gives it) as it reads in
	// $lang (null: i18n::current()): each translated field replaced by its
	// translation, or kept as the default language's value when that is
	// empty. The translations stay in the row (headline__ro). For showing
	// only, never for a write: a save of it would copy one language into
	// another.
	static function localized($row, $type, $lang = null) {
		if (!is_array($row) || !i18n::enabled()) return $row;
		$lang = $lang === null ? i18n::current() : (string)$lang;
		if ($lang === '' || i18n::is_default($lang)) return $row;
		foreach (cms_types::translated($type) as $field) {
			$column = cms_types::column($field, $lang);
			if (isset($row[$column]) && !is_array($row[$column]) && (string)$row[$column] !== '') $row[$field] = $row[$column];
		}
		return $row;
	}

	// the language a page or item is written in: '' for the default (and
	// whenever multilingual is off, so a write is what it always was), the
	// listed code for a translation; a language the site doesn't list is
	// refused
	static function write_language($lang) {
		if ($lang === null || $lang === '' || !i18n::enabled() || i18n::is_default($lang)) return '';
		foreach (i18n::available() as $code) {
			if (strtolower($code) === strtolower((string)$lang)) return $code;
		}
		throw new InvalidArgumentException("Unknown language '$lang'; the site's languages: ".implode(', ', i18n::available()));
	}

	// ##Pages

	static function page_values($type) {
		$page = self::latest($type);
		if (!$page) return null;
		$values = cms_types::read_row($type, $page->export());
		foreach (self::$system_fields as $field) unset($values[$field]);
		return array(
			'revision' => (int)$page->id,
			'updated_at' => $page->updated_at,
			'fields' => $values,
		);
	}

	// Stores a new revision of the page with the given fields changed.
	// $allowed lists the fields the templates define; others are rejected.
	// $lang is the language the values are in (null: the default): in a
	// translation language each translated field is stored in its column
	// (headline__ro) and a shared one is refused (field_shared). $values
	// and $allowed always name the fields themselves (headline).
	static function update_page($type, $slug, $values, $allowed, $lang = null) {
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidArgumentException("Unknown field '$field'. Fields come from the templates; known fields: ".implode(', ', $allowed));
			}
		}
		// the newest revision is read and the next one stored together, so
		// two saves at once (one field each) never lose one of them: the
		// transaction locks the database for writing
		return cms_records::transaction(function () use ($type, $slug, $values, $lang) {
			return cms_store::store_page($type, $slug, $values, $lang);
		});
	}

	static function store_page($type, $slug, $values, $lang = null) {
		$lang = self::write_language($lang);
		$columns = $values;
		if ($lang !== '') {
			// a translation: each field into its column, all or nothing
			$translated = cms_types::translated($type);
			$columns = array();
			foreach ($values as $field => $value) {
				if (!in_array($field, $translated, true)) throw new cms_refused(array('field_shared'));
				$columns[cms_types::column($field, $lang)] = $value;
			}
			$have = self::columns($type);
			$missing = array_diff_key($columns, $have);
			if ($missing && database::$frozen) {
				throw new InvalidArgumentException("No column for the $lang translation of '".implode("', '", array_keys($missing))."' yet: run php bin/raster schema --apply");
			}
			if ($missing) {
				$base = cms_types::of_table($type);
				$add = array();
				foreach ($missing as $column => $value) {
					$field = substr($column, 0, -strlen(i18n::suffix($lang)));
					$add[$column] = isset($base[$field]) ? $base[$field] : 'text';
				}
				cms_types::ensure($type, $add);
			}
		}
		// development keeps the language of each revision from the first
		// multilingual save on
		if (i18n::enabled() && !database::$frozen && !array_key_exists('saved_lang', cms_types::of_table($type))) cms_types::ensure($type, array('saved_lang' => 'text'));
		$latest = self::latest($type);
		if ($latest) {
			$page = R::duplicate($latest);
		} else {
			$page = R::dispense($type);
			$page->slug = $slug;
		}
		$types = cms_types::of_table($type);
		foreach ($columns as $field => $value) {
			$page->$field = cms_types::clean(isset($types[$field]) ? $types[$field] : 'text', $value, $field);
		}
		// the language the revision changed: the default's code for a save
		// without one. Only while multilingual and once the table has the
		// column, so off a save stores what it always did
		$saved_lang = $lang !== '' ? $lang : i18n::default_language();
		if (i18n::enabled() && isset($types['saved_lang'])) $page->saved_lang = $saved_lang;
		$page->updated_at = R::isoDateTime();
		R::store($page);
		util::content_changed();
		$saved = self::page_values($type);
		$event = array('type' => $type, 'slug' => (string)$page->slug, 'changed' => array_keys($values), 'fields' => $saved);
		if (i18n::enabled()) $event['lang'] = $saved_lang;
		cms_records::dispatch('cms.page_saved', $event);
		return $saved;
	}

	// the page's revisions, newest first; multilingual, each says the
	// language it changed (saved_lang, '' for one older than languages)
	static function page_history($type, $limit = 20) {
		if (!self::table_exists($type)) return array();
		$revisions = R::find($type, ' ORDER BY id DESC LIMIT '.max(1, (int)$limit));
		$languages = i18n::enabled();
		$history = array();
		foreach ($revisions as $revision) {
			$values = cms_types::read_row($type, $revision->export());
			foreach (self::$system_fields as $field) unset($values[$field]);
			$entry = array('revision' => (int)$revision->id, 'updated_at' => $revision->updated_at, 'fields' => $values);
			if ($languages) $entry['saved_lang'] = (string)$revision->saved_lang;
			$history[] = $entry;
		}
		return $history;
	}

	// ##Collections

	// a stored item, each field as its type
	static function export_item($bean) {
		$item = cms_types::read_row($bean->getMeta('type'), $bean->export());
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
	// model's check() first. $name is the collection's own name, for the
	// event (team_members, whose table is teammembersdata). $lang is the
	// language the values are in (null: the default); ignored for now.
	static function save_item($type, $id, $values, $allowed, $who = 'editor', $name = null, $lang = null) {
		$info = cms_records::for_table($type);
		if ($info) cms_records::ensure($info);
		// the checks and the write happen together, so two bookings can't
		// both take the last seats, and two items with one title can't both
		// take its slug
		return cms_records::transaction(function () use ($type, $id, $values, $allowed, $who, $info, $name, $lang) {
			return cms_store::store_item($type, $id, $values, $allowed, $who, $info, $name, $lang);
		});
	}

	static function store_item($type, $id, $values, $allowed, $who, $info, $name = null, $lang = null) {
		if ($info) {
			$allowed = $who === 'model' ? array_merge(array_keys($info['fields']), array('owner')) : array_values(array_intersect($allowed, cms_records::writable($info)));
		}
		// slug, enabled (0 = draft) and published_at can always be set, except by visitors
		if ($who !== 'visitor') $allowed = array_merge($allowed, array('slug', 'enabled', 'published_at'));
		// one value, like every other field: slug[]=x is refused, not 'array'
		if (isset($values['slug'])) $values['slug'] = self::unique_slug($type, cms_types::clean('text', $values['slug'], 'slug') ?: 'item', (int)$id);
		$types = cms_types::of_table($type);
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
				continue;
			}
			// the field's type: 'guests' => '4' is stored as 4, 'many' is refused
			$values[$field] = cms_types::clean(isset($types[$field]) ? $types[$field] : 'text', $value, $field);
		}
		if ($id) {
			$bean = R::findOne($type, ' id = ? ', array((int)$id));
			if (!$bean) throw new InvalidArgumentException("No item $id");
		} else {
			$bean = R::dispense($type);
			$bean->enabled = 1;
		}
		$before = $id ? self::export_item($bean) : null;
		foreach ($values as $field => $value) {
			$bean->$field = $value;
		}
		if (!empty($bean->published_at) && strtotime($bean->published_at) > time()) {
			raster_cache::schedule(strtotime($bean->published_at));
		}
		// never from a hidden field: the slug is in the record's public URL
		$skip = $info ? array_merge($info['hidden'], array('owner', 'created_at')) : array();
		if (empty($bean->slug)) {
			$bean->slug = self::unique_slug($type, self::slug_source($bean->export(), $skip), (int)$bean->id);
		}
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
				if ($bean->$field === null) $bean->$field = is_array($default) ? '[]' : cms_types::clean($info['types'][$field], $default, $field);
			}
		}
		if ($info) {
			$after = cms_records::decode($info, cms_types::read_row($type, $bean->export()));
			$before = $before ? cms_records::decode($info, $before) : null;
			// the model sees who writes while it fills its fields and checks
			$writer = cms_records::$writer;
			cms_records::$writer = $who;
			try {
				// fields the model works out (a booking's end), stored as their type
				$filled = cms_records::fill($info, $after, $before);
				foreach ($filled as $field => $value) {
					$bean->$field = in_array($field, $info['lists'], true)
						? json_encode(array_values((array)$value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
						: cms_types::clean(isset($types[$field]) ? $types[$field] : 'text', $value, $field);
				}
				// a new record's slug comes from what fill() works out too (a
				// contact message's title, so never its email), unless it was given
				if ($filled && !$id && !isset($values['slug'])) $bean->slug = self::unique_slug($type, self::slug_source($bean->export(), $skip), 0);
				if ($filled) $after = cms_records::decode($info, cms_types::read_row($type, $bean->export()));
				cms_records::check($info, $after, $before);
			} finally {
				cms_records::$writer = $writer;
			}
		}
		$bean->updated_at = R::isoDateTime();
		R::store($bean);
		util::content_changed();
		$item = self::export_item($bean);
		if ($info) $item = cms_records::decode($info, $item);
		cms_records::dispatch('cms.item_saved', array('collection' => self::collection_of($type, $name), 'created' => !$id, 'item' => $item));
		return $info && $who !== 'model' ? cms_records::shown($info, $item) : $item;
	}

	static function delete_item($type, $id, $name = null) {
		return cms_records::transaction(function () use ($type, $id, $name) { return cms_store::remove_item($type, $id, $name); });
	}

	static function remove_item($type, $id, $name = null) {
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
		cms_records::dispatch('cms.item_deleted', array('collection' => self::collection_of($type, $name), 'item' => $item));
		return true;
	}

	// The collection whose item an event is about: a declared type's own
	// name, else the name the caller knows. Only code that passes a bare
	// table gets its name back from the table (newsdata -> news), which has
	// lost any underscores (teammembersdata, #33).
	static function collection_of($type, $name = null) {
		$info = cms_records::for_table($type);
		if ($info) return $info['name'];
		return $name !== null ? $name : preg_replace('/data$/', '', $type);
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
