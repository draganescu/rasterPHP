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

	// every slug an item has had, in every language, so an old or another
	// language's slug answers 301 to the right one (#138, #200). One row per
	// slug: type is the item's table (menudata), item its id, lang the
	// language code the slug is in. Multilingual only: record_slug() writes
	// it, resolve_slug() reads it, schema --apply makes it.
	static $slughistory = array('type' => '', 'item' => 0, 'lang' => '', 'slug' => '', 'created_at' => '');
	// slug_item's answers this request (and cms::item_visible's), forgotten
	// when an item is saved or deleted
	static $slug_items = array();

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
	// hidden fields, its owner). $lang: the language the slug is for (null:
	// the default); in a translation language the translated title,
	// headline or name comes first (title__ro); an item with none of the
	// three has the first field with a translation (an FAQ's question__ro);
	// then as for the default.
	static function slug_source($values, $skip = array(), $lang = null) {
		if ($lang !== null && i18n::enabled() && !i18n::is_default($lang)) {
			$named = false;
			foreach (array('title', 'headline', 'name') as $field) {
				if (in_array($field, $skip, true)) continue;
				$column = cms_types::column($field, $lang);
				if (!empty($values[$column]) && is_string($values[$column])) return $values[$column];
				if (!empty($values[$field])) $named = true;
			}
			if (!$named) {
				foreach ($values as $field => $value) {
					if (in_array($field, $skip, true) || in_array($field, self::$system_fields) || strpos($field, '__') !== false) continue;
					$column = cms_types::column($field, $lang);
					if (isset($values[$column]) && is_string($values[$column]) && trim(strip_tags($values[$column])) !== '') return $values[$column];
				}
			}
		}
		foreach (array('title', 'headline', 'name') as $field) {
			if (!empty($values[$field]) && !in_array($field, $skip, true)) return $values[$field];
		}
		foreach ($values as $field => $value) {
			if (in_array($field, $skip, true) || in_array($field, self::$system_fields)) continue;
			// nor a skipped field's translation (token__ro)
			if (strpos($field, '__') !== false && in_array(strstr($field, '__', true), $skip, true)) continue;
			if (is_string($value) && trim(strip_tags($value)) !== '') return $value;
		}
		return 'item';
	}

	// A free slug made from $text, in the language $lang (null: the
	// default). In the default language it is unique among the other rows'
	// slug. In a translation language it is unique among their slugs in
	// that language, which for a row with no slug__ro is its slug (the
	// address it has in that language): so one /ro/ address is one item.
	static function unique_slug($type, $text, $id, $lang = null) {
		$base = self::slugify($text);
		if (!preg_match('/^[a-z0-9_]+$/', $type) || !self::table_exists($type) || !array_key_exists('slug', self::columns($type))) return $base;
		// every slug taken by another row, in one query that the slug index
		// answers: the base and base-<n>. SQLite uses an index for GLOB, not
		// for its LIKE, which ignores case. A slug holds only a-z, 0-9 and -,
		// so nothing in it is a wildcard. MySQL compares without case.
		$sqlite = R::getDatabaseAdapter()->getDatabase()->getDatabaseType() !== 'mysql';
		$column = $lang === null ? 'slug' : self::slug_column(self::write_language($lang) ?: i18n::default_language());
		if ($column === 'slug' || !array_key_exists($column, self::columns($type))) {
			$rows = R::getCol("SELECT slug FROM `$type` WHERE (slug = ? OR slug ".($sqlite ? 'GLOB' : 'LIKE').' ?) AND id != ?', array($base, $sqlite ? "$base-*" : "$base-%", (int)$id));
		} else {
			$like = $sqlite ? 'GLOB' : 'LIKE';
			$pattern = $sqlite ? "$base-*" : "$base-%";
			$found = R::getAll("SELECT slug, `$column` AS translated FROM `$type` WHERE (slug = ? OR slug $like ? OR `$column` = ? OR `$column` $like ?) AND id != ?", array($base, $pattern, $base, $pattern, (int)$id));
			$rows = array();
			foreach ($found as $row) $rows[] = (string)$row['translated'] !== '' ? $row['translated'] : (string)$row['slug'];
		}
		$taken = array_flip(array_map('strtolower', $rows));
		$slug = $base;
		for ($i = 2; isset($taken[$slug]); $i++) $slug = $base.'-'.$i;
		return $slug;
	}

	// the column an item's slug in $lang is in: slug, or slug__ro when
	// multilingual is on and $lang is a translation language
	static function slug_column($lang = null) {
		return cms_types::column('slug', $lang);
	}

	// an item's slug in $lang, from a row with its slug columns: slug__ro,
	// or its slug while it has none ('' when it has neither, and is at its id)
	static function slug_in($row, $lang) {
		$column = self::slug_column($lang);
		if (isset($row[$column]) && (string)$row[$column] !== '') return (string)$row[$column];
		return isset($row['slug']) ? (string)$row['slug'] : '';
	}

	// language => slug column, of the site's languages whose column $columns has
	static function slug_columns($columns) {
		$slugs = array();
		foreach (i18n::available() as $lang) {
			$column = self::slug_column($lang);
			if (array_key_exists($column, $columns) && !in_array($column, $slugs, true)) $slugs[$lang] = $column;
		}
		return $slugs ?: array(i18n::default_language() => 'slug');
	}

	// The item of $type whose slug in $lang (null: i18n::current()) is $slug,
	// as a row of its id and slug columns, or null. An item with a slug__ro
	// of its own is found by it before one that is at its default slug in
	// Romanian. Multilingual only (null off, with no query).
	static function slug_item($type, $slug, $lang = null) {
		if (!i18n::enabled() || !preg_match('/^[a-z0-9_]+$/', $type) || (string)$slug === '') return null;
		$lang = $lang === null ? i18n::current() : (string)$lang;
		$key = $type."\n".$lang."\n".$slug;
		if (array_key_exists($key, self::$slug_items)) return self::$slug_items[$key];
		if (!self::table_exists($type)) return null;
		$columns = self::columns($type);
		if (!array_key_exists('slug', $columns)) return null;
		$select = 'id, `'.implode('`, `', self::slug_columns($columns)).'`';
		$column = self::slug_column($lang);
		if ($column === 'slug' || !array_key_exists($column, $columns)) {
			$row = R::getRow("SELECT $select FROM `$type` WHERE slug = ? ORDER BY id LIMIT 1", array((string)$slug));
		} else {
			$row = R::getRow("SELECT $select FROM `$type` WHERE `$column` = ? OR ((`$column` IS NULL OR `$column` = '') AND slug = ?) ORDER BY CASE WHEN `$column` = ? THEN 0 ELSE 1 END, id LIMIT 1", array((string)$slug, (string)$slug, (string)$slug));
		}
		return self::$slug_items[$key] = $row ?: null;
	}

	// Gives the item $bean the slug it lacks in each translation language,
	// made from its translated title, headline or name (slug__ro from
	// title__ro), once it has one: an item with no translated text keeps its
	// default slug in that language too, unless another item's address there
	// already is that slug, and a slug once made stays (a new one is written
	// as slug, with lang). Multilingual only. In development
	// the column is made when it is first needed.
	static function translate_slugs($type, $bean, $skip = array()) {
		if (!i18n::enabled() || !array_key_exists('slug', self::columns($type))) return;
		$values = $bean->export();
		$default = self::slug_source($values, $skip);
		foreach (i18n::available() as $lang) {
			$column = self::slug_column($lang);
			if ($column === 'slug' || !empty($values[$column])) continue;
			$source = self::slug_source($values, $skip, $lang);
			if ($source === $default) {
				// no words of its own there, so its address there is its
				// default slug, unless that is already another item's
				// address in the language (a slug__ro made from its
				// translation): then a free one in that language, so one
				// address is one item
				if (!array_key_exists($column, self::columns($type)) || (string)$values['slug'] === '') continue;
				if (!R::getCell("SELECT COUNT(*) FROM `$type` WHERE `$column` = ? AND id != ?", array((string)$values['slug'], (int)$bean->id))) continue;
				$bean->$column = self::unique_slug($type, $values['slug'], (int)$bean->id, $lang);
				continue;
			}
			if (!array_key_exists($column, self::columns($type))) {
				if (database::$frozen) continue;
				cms_types::ensure($type, array(), array('slug'));
			}
			$bean->$column = self::unique_slug($type, $source, (int)$bean->id, $lang);
		}
	}

	// Remembers that item $id of $type had $slug in $lang (null: the default
	// language) in slughistory, so its old address answers 301 to the one it
	// has now. True when it wrote a row: one per slug, and saving the same
	// slug again adds none. Multilingual only; in development the table is
	// made on the first write, frozen it waits for schema --apply.
	static function record_slug($type, $id, $slug, $lang = null) {
		if (!i18n::enabled() || (string)$slug === '' || !(int)$id) return false;
		$lang = self::write_language($lang) ?: i18n::default_language();
		if (!self::table_exists('slughistory')) {
			if (database::$frozen) return false;
			cms_types::ensure('slughistory', array_map(array('cms_types', 'of_default'), self::$slughistory));
			self::index_history();
		}
		$seen = R::getCell('SELECT COUNT(*) FROM slughistory WHERE `type` = ? AND item = ? AND lang = ? AND slug = ?', array($type, (int)$id, $lang, (string)$slug));
		if ($seen) return false;
		$row = R::dispense('slughistory');
		$row->type = $type;
		$row->item = (int)$id;
		$row->lang = $lang;
		$row->slug = (string)$slug;
		$row->created_at = R::isoDateTime();
		R::store($row);
		return true;
	}

	// slughistory's index on (type, slug), what resolve_slug looks it up by;
	// true when it made it. MySQL indexes TEXT columns by their start.
	static function index_history() {
		$adapter = R::getDatabaseAdapter();
		if ($adapter->getDatabase()->getDatabaseType() !== 'mysql') {
			if ($adapter->getCell("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'slughistory_type_slug'")) return false;
			$adapter->exec('CREATE INDEX IF NOT EXISTS `slughistory_type_slug` ON `slughistory` (`type`, `slug`)');
			return true;
		}
		if ($adapter->getCell("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'slughistory' AND index_name = 'slughistory_type_slug'")) return false;
		$adapter->exec('CREATE INDEX `slughistory_type_slug` ON `slughistory` (`type`(64), `slug`(191))');
		return true;
	}

	// after a save of item $bean: each language's slug it was given or
	// changed to, and the one it had, if slughistory doesn't have it yet (a
	// slug from before multilingual was on). $before is the item as it was
	// (null for a new one).
	static function record_slugs($type, $bean, $before) {
		if (!i18n::enabled()) return;
		$after = $bean->export();
		foreach (i18n::available() as $lang) {
			$column = self::slug_column($lang);
			$now = isset($after[$column]) ? (string)$after[$column] : '';
			$was = $before && isset($before[$column]) ? (string)$before[$column] : '';
			if ($before && $now === $was) continue;
			if ($was !== '') self::record_slug($type, $bean->id, $was, $lang);
			if ($now !== '') self::record_slug($type, $bean->id, $now, $lang);
		}
	}

	// What an item URL's $slug means in $lang (null: i18n::current()). Null:
	// show the URL as it is, since it is the item's slug in $lang (or the
	// default slug of an item with none in $lang), or no item's at all (the
	// list then answers 404), or multilingual is off. array('id' => n,
	// 'slug' => s): $slug is item n's slug in another language, or one it
	// had (slughistory), and s its slug in $lang, for a 301. A slug an item
	// has now wins over one another item had. A number no item has as its
	// slug is an id, as always (#90).
	static function resolve_slug($type, $slug, $lang = null) {
		if (!i18n::enabled() || !preg_match('/^[a-z0-9_]+$/', $type) || (string)$slug === '' || !self::table_exists($type)) return null;
		$lang = $lang === null ? i18n::current() : (string)$lang;
		$slug = (string)$slug;
		$columns = self::columns($type);
		if (!array_key_exists('slug', $columns)) return null;
		$slugs = self::slug_columns($columns);
		$select = 'id, `'.implode('`, `', $slugs).'`';
		$where = implode(' OR ', array_map(function ($column) { return "`$column` = ?"; }, $slugs));
		$rows = R::getAll("SELECT $select FROM `$type` WHERE $where ORDER BY id", array_fill(0, count($slugs), $slug));
		foreach ($rows as $row) {
			if (self::slug_in($row, $lang) === $slug) return null;
		}
		if (!$rows && self::table_exists('slughistory')) {
			// the newest of the items still there that had it, a published
			// one before a draft
			$live = array_key_exists('enabled', $columns) ? "CASE WHEN t.enabled = '0' THEN 1 ELSE 0 END, " : '';
			$id = R::getCell("SELECT h.item FROM slughistory h JOIN `$type` t ON t.id = h.item WHERE h.`type` = ? AND h.slug = ? ORDER BY {$live}h.id DESC LIMIT 1", array($type, $slug));
			if ($id) $rows = R::getAll("SELECT $select FROM `$type` WHERE id = ?", array((int)$id));
		}
		if (!$rows || self::slug_in($rows[0], $lang) === $slug) return null;
		return array('id' => (int)$rows[0]['id'], 'slug' => self::slug_in($rows[0], $lang));
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
	// $lang: the language the list is shown in: its filters match the
	// translations (conditions_sql; null: as it always was).
	static function list_where($name, $conditions, $filters = array(), $uri = array(), $lang = null) {
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
		list($more, $more_bindings) = self::conditions_sql($compare, $fields, 'c', $lang);
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

	// The SQL a list compares and sorts $field by in $lang (D8): `name`, or
	// in a translation language whose column the table has (name__ro) the
	// translation, and the default language's value where it is empty, so
	// an untranslated item still matches and sorts by its own words. Null,
	// off and the default language: `name`, as it always was.
	static function field_sql($field, $columns, $lang = null) {
		if ($lang !== null && $lang !== '' && i18n::suffix($lang) !== '') {
			$column = cms_types::column($field, $lang);
			if (array_key_exists($column, $columns)) return "COALESCE(NULLIF(`$column`, ''), `$field`)";
		}
		return "`$field`";
	}

	// SQL for conditions on columns the table has; others are left out.
	// $lang: the language the values are in (null: the default); a field
	// with a translation in it compares as field_sql says
	static function conditions_sql($conditions, $columns, $prefix = 'c', $lang = null) {
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
			$column = self::field_sql($field, $columns, $lang);
			if ($value === null) {
				$sql .= $operator === '=' ? " AND $column IS NULL " : ($operator === '!=' ? " AND $column IS NOT NULL " : ' AND 1 = 0 ');
				continue;
			}
			$name = ':'.$prefix.$i.'_'.$field;
			// a field nobody filled is not equal to anything
			$sql .= $operator === '!=' ? " AND ($column IS NULL OR $column != $name) " : " AND $column $operator $name ";
			$bindings[$name] = $value;
		}
		return array($sql, $bindings);
	}

	// order=newest|oldest|<field>|-<field> (minus means descending), or
	// several separated by commas: order=date,time or order=-date,-time.
	// $lang: the language to sort in (null: the default); a field with a
	// translation in it sorts as field_sql says
	static function order_sql($order, $columns, $lang = null) {
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
			$parts[] = self::field_sql($field, $columns, $lang).($desc ? ' DESC' : ' ASC');
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
		self::$slug_items = array();
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
	// language the values are in (null: the default): in a translation
	// language each translated field is stored in its column (name__ro) and
	// a shared one is refused (field_shared). $values and $allowed always
	// name the fields themselves (name). A markup collection's translated
	// fields are the ones with translation columns; a declared type's are
	// what it declares (cms_records::translated_fields).
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
		$lang = self::write_language($lang);
		if ($info) {
			$allowed = $who === 'model' ? array_merge(array_keys($info['fields']), array('owner')) : array_values(array_intersect($allowed, cms_records::writable($info)));
		}
		// slug, enabled (0 = draft) and published_at can always be set, except by visitors
		if ($who !== 'visitor') $allowed = array_merge($allowed, array('slug', 'enabled', 'published_at'));
		// one value, like every other field: slug[]=x is refused, not 'array'
		if (isset($values['slug'])) $values['slug'] = self::unique_slug($type, cms_types::clean('text', $values['slug'], 'slug') ?: 'item', (int)$id, $lang);
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
		// the column each value goes to: the field's own, or in a
		// translation its column there (name__ro, slug__ro), all or nothing
		$columns = $values;
		if ($lang !== '') {
			$translated = $info ? cms_records::translated_fields($info) : cms_types::translated($type);
			$columns = array();
			foreach ($values as $field => $value) {
				// a slug has one per language (slug__ro)
				if ($field === 'slug') { $columns[self::slug_column($lang)] = $value; continue; }
				if (!in_array($field, $translated, true)) throw new cms_refused(array('field_shared'));
				$columns[cms_types::column($field, $lang)] = $value;
			}
			$missing = array_diff_key($columns, self::columns($type));
			// a slug's column comes with its index
			$slug = self::slug_column($lang);
			if (isset($missing[$slug]) && !database::$frozen) {
				cms_types::ensure($type, array(), array('slug'));
				unset($missing[$slug]);
			}
			if ($missing && database::$frozen) {
				throw new InvalidArgumentException("No column for the $lang translation of '".implode("', '", array_keys($missing))."' yet: run php bin/raster schema --apply");
			}
			if ($missing) {
				$add = array();
				foreach ($missing as $column => $value) {
					$field = substr($column, 0, -strlen(i18n::suffix($lang)));
					$add[$column] = isset($types[$field]) ? $types[$field] : 'text';
				}
				cms_types::ensure($type, $add);
			}
		}
		// development keeps the language of each save from the first
		// multilingual one on
		if (i18n::enabled() && !database::$frozen && self::table_exists($type) && !array_key_exists('saved_lang', $types)) {
			cms_types::ensure($type, array('saved_lang' => 'text'));
			$types = cms_types::of_table($type);
		}
		if ($id) {
			$bean = R::findOne($type, ' id = ? ', array((int)$id));
			if (!$bean) throw new InvalidArgumentException("No item $id");
		} else {
			$bean = R::dispense($type);
			$bean->enabled = 1;
		}
		$before = $id ? self::export_item($bean) : null;
		foreach ($columns as $field => $value) {
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
		// multilingual: slug__ro from the Romanian title, once there is one
		self::translate_slugs($type, $bean, $skip);
		if ($info && !$id) {
			$bean->created_at = R::isoDateTime();
			// a record remembers who made it, so they can read it later (a
			// booking staff type in for a caller belongs to nobody)
			if ($info['owner'] && !isset($values['owner']) && $who !== 'editor') {
				$user = authentication::user();
				$bean->owner = $user ? (int)$user['id'] : 0;
			}
			if ($info['owner'] && $bean->owner === null) $bean->owner = 0;
			// frozen, a declared field without its column yet (one a newer
			// release declares, before schema --apply) is left to the model
			$have = database::$frozen ? self::columns($type) : null;
			foreach ($info['fields'] as $field => $default) {
				if ($have !== null && !array_key_exists($field, $have)) continue;
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
		// a bundled record's stored language (#200 D13) on a frozen database
		// whose column schema --apply hasn't made yet: left out, so the
		// booking, reply or order still goes in (its mail is then in the
		// default). A site's own field called lang fails as any missing one
		if ($info && database::$frozen && isset($bean->lang) && in_array($type, array('bookingdata', 'rsvpdata', 'contactmessagedata', 'orderdata'), true) && !array_key_exists('lang', self::columns($type))) unset($bean->lang);
		// the language the save changed: the default's code for a save
		// without one. Only while multilingual and once the table has the
		// column, so off a save stores what it always did
		$saved_lang = $lang !== '' ? $lang : i18n::default_language();
		if (i18n::enabled() && isset($types['saved_lang'])) $bean->saved_lang = $saved_lang;
		$bean->updated_at = R::isoDateTime();
		R::store($bean);
		self::$slug_items = array();
		// every slug it has had, for the redirects from the old ones (#138)
		self::record_slugs($type, $bean, $before);
		util::content_changed();
		$item = self::export_item($bean);
		if ($info) $item = cms_records::decode($info, $item);
		$event = array('collection' => self::collection_of($type, $name), 'created' => !$id, 'item' => $item);
		if (i18n::enabled()) $event['lang'] = $saved_lang;
		cms_records::dispatch('cms.item_saved', $event);
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
		self::$slug_items = array();
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
