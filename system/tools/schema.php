<?php
// #Schema
//
// Compares the content model the templates describe with the tables the
// database has. Templates are the source of truth:
// - missing: a field in a template with no column yet
// - orphan: a column no template uses any more (often a renamed field)
// - retyped: a column of another type than the field now has (its mock-up
//   went from 14 to 14 lei); --apply converts it when every value fits
// - narrow: a MySQL text column too small for what SQLite would hold
//   (varchar(191), TEXT); --apply makes it LONGTEXT
// - translations (#200, D3): a column <field>__<lang> holds a field's
//   translation. Multilingual on, a translated field of a page or an item
//   without one for every language is drift that --apply adds
//   (missing_translations), and so is a table without saved_lang.
//   Off, and for a language or field no longer used, they are kept
//   translations: never orphans, never renamed, never drift, so turning
//   multilingual on again brings them back. An item's slug in a language
//   (slug__ro) is drift the same way, and --apply indexes it; slughistory
//   (the slugs items had) is listed with the system tables only when on.
//
// In development missing columns appear on the next request that renders
// them. In production the database is frozen: run `raster schema --apply`.
class raster_schema {

	public $inspector;

	function __construct($inspector = null) {
		$this->inspector = $inspector ?: new raster_inspector();
		require_once BASE.'models/cms/cms.php';
	}

	// Every table the templates need, with field status against the database
	function status() {
		cms_store::connect();
		$model = $this->inspector->content_model();
		$tables = array();

		foreach ($model['pages'] as $page) {
			$tables[] = $this->compare('page', $page['type'], $page['fields'], array(
				'view' => $page['view'], 'url' => $page['url'], 'slug' => $page['slug'],
			));
		}
		foreach ($model['collections'] as $collection) {
			$meta = array('name' => $collection['name'], 'views' => $collection['views']);
			// a type a model declares: records, never a mock-up row
			$fields = $collection['fields'];
			if (isset($collection['model'])) {
				$meta['model'] = $collection['model'];
				// every column a record needs, Raster's own included
				foreach (cms_records::columns(cms_records::info($collection['name'])) as $column => $type) {
					if (!isset($fields[$column])) $fields[$column] = array('default' => '', 'type' => $type);
				}
			}
			$tables[] = $this->compare(isset($collection['model']) ? 'record' : 'collection', $collection['type'], $fields, $meta);
		}

		// tables in the database that no template uses; the ones the
		// bundled and the app's models declare with schema() are theirs
		$declared = $this->system_tables();
		$known = array_map(function ($t) { return $t['table']; }, $tables);
		$unused = array();
		foreach (R::inspect() as $table) {
			if (!preg_match('/(page|data)$/', $table)) continue;
			if (in_array($table, $known) || in_array($table, array('usersdata', 'rasterdata')) || isset($declared[$table])) continue;
			$unused[] = $table;
		}

		$drift = !empty($unused);
		$kept = array();
		foreach ($tables as $i => $table) {
			if ((!$table['exists'] && $table['fields']) || $table['missing'] || $table['orphans'] || $table['retyped']) $drift = true;
			if ($table['narrow'] && database::$frozen) $drift = true;
			if (!empty($table['missing_translations'])) $drift = true;
			if ($table['kept']) $kept[$table['table']] = $table['kept'];
			unset($tables[$i]['kept']);
		}

		// tables the bundled models use (accounts, subscribers). A fluid
		// database creates them when needed; a frozen one needs --apply.
		$system = array();
		foreach ($declared as $name => $fields) {
			$columns = cms_store::table_exists($name) ? cms_store::columns($name) : array();
			$missing = array_values(array_diff(array_keys($fields), array_keys($columns)));
			$narrow = cms_types::narrow($name, array_map(array('cms_types', 'of_default'), $fields));
			$system[] = array('table' => $name, 'exists' => (bool)$columns, 'missing' => $missing, 'narrow' => $narrow);
			if (($missing || $narrow) && database::$frozen) $drift = true;
		}

		$status = array(
			'environment' => config::get('environment'),
			'frozen' => database::$frozen,
			'drift' => $drift,
			'tables' => $tables,
			'system_tables' => $system,
			'unused_tables' => $unused,
			'site_url' => (bool)config::get('trusted_url'),
		);
		// table => the translation columns kept (see the top); only when
		// there are some or multilingual is on, so a site that never had
		// languages reads as it always did
		if ($kept || i18n::enabled()) $status['translations'] = $kept;
		return $status;
	}

	function system_tables() {
		$tables = array();
		// bundled models (accounts, subscribers), then the app's own, that
		// declare schema(): the first to name a table keeps it
		foreach (array(true, false) as $bundled) {
			foreach (controller::models() as $model => $found) {
				if ($found['bundled'] !== $bundled || empty($found['declares']['schema'])) continue;
				$class = controller::model_class($model);
				if ($class && method_exists($class, 'schema')) $tables += $class::schema();
			}
		}
		// every slug an item has had, for the redirects (#138); multilingual
		// only, so a site without languages lists what it always did
		if (i18n::enabled()) $tables += array('slughistory' => cms_store::$slughistory);
		return $tables;
	}

	protected function compare($kind, $type, $fields, $meta) {
		$exists = cms_store::table_exists($type);
		$columns = $exists ? cms_store::columns($type) : array();
		$missing = array();
		$retyped = array();
		$status = array();
		foreach ($fields as $name => $field) {
			$has = array_key_exists($name, $columns);
			if (!$has) $missing[] = $name;
			$status[$name] = array('default' => $field['default'], 'type' => $field['type'], 'in_database' => $has);
			if ($has && cms_types::of_column($columns[$name]) !== $field['type']) {
				$retyped[] = array('field' => $name, 'from' => cms_types::of_column($columns[$name]), 'to' => $field['type']);
			}
		}
		$orphans = array();
		$kept = array();
		$suffixes = array();
		foreach (i18n::available() as $lang) {
			if (i18n::suffix($lang) !== '') $suffixes[] = i18n::suffix($lang);
		}
		foreach (array_keys($columns) as $column) {
			if (in_array($column, cms_store::$system_fields)) continue;
			if ($kind === 'record' && in_array($column, array('created_at', 'owner'))) continue;
			if (array_key_exists($column, $fields)) continue;
			// a record's stored language (#200 D13), declared only on: kept
			// when multilingual is off again, like a translation
			if ($kind === 'record' && $column === 'lang') {
				$kept[] = $column;
				continue;
			}
			$base = self::translation_of($column);
			if ($base === null) {
				$orphans[] = $column;
				continue;
			}
			// an item's slug in a language (slug__ro) is Raster's, as the slug is
			if ($base === 'slug' && $kind !== 'page') {
				if (!in_array(substr($column, strlen($base)), $suffixes, true)) $kept[] = $column;
				continue;
			}
			// on, a field's own translation belongs to it, and one of a
			// field no template has any more goes with that field's orphan
			$known = in_array(substr($column, strlen($base)), $suffixes, true);
			if ($known && array_key_exists($base, $fields)) continue;
			if ($known && !array_key_exists($base, $fields) && array_key_exists($base, $columns) && i18n::enabled()) {
				$orphans[] = $column;
				continue;
			}
			$kept[] = $column;
		}
		// what multilingual needs here: a column per language for each
		// translated field (D7), and the language each revision or item save
		// is in: pages, collections' items and records alike
		$missing_translations = array();
		if (i18n::enabled() && $fields) {
			foreach ($fields as $name => $field) {
				if (empty($field['translate'])) continue;
				foreach (i18n::available() as $lang) {
					$column = cms_types::column($name, $lang);
					if ($column !== $name && !array_key_exists($column, $columns)) $missing_translations[] = $column;
				}
			}
			if (!array_key_exists('saved_lang', $columns)) $missing_translations[] = 'saved_lang';
		}
		// an item's slug in each language (slug__ro), looked up as its slug is
		$missing_slugs = array();
		if (i18n::enabled() && $kind !== 'page') {
			foreach (i18n::available() as $lang) {
				$column = cms_store::slug_column($lang);
				if ($column !== 'slug' && !array_key_exists($column, $columns)) $missing_slugs[] = $column;
			}
		}

		// An orphan next to a new field is most likely a renamed annotation.
		// In development the new column may already exist (created by the
		// first request after the rename) still holding its template default.
		$renames = array();
		$plain = array_values(array_filter($orphans, function ($column) { return raster_schema::translation_of($column) === null; }));
		if ($plain) {
			$orphans_all = $orphans;
			$orphans = $plain;
			$latest = cms_store::latest($type);
			$fresh = $missing;
			foreach ($fields as $name => $field) {
				if (!in_array($name, $missing) && $latest && trim((string)$latest->$name) === trim($field['default'])) {
					$fresh[] = $name;
				}
			}
			// a column added after the others is NULL in the older rows
			$added_later = array();
			foreach ($fresh as $name) {
				if (!in_array($name, $missing) && R::getCell("SELECT COUNT(*) FROM `$type` WHERE `$name` IS NULL") > 0) $added_later[] = $name;
			}
			if (count($orphans) === 1 && count($missing) === 0 && count($added_later) === 1) {
				$renames[] = array('from' => $orphans[0], 'to' => $added_later[0]);
			} elseif (count($orphans) === 1 && count($missing) === 1) {
				$renames[] = array('from' => $orphans[0], 'to' => $missing[0]);
			} else {
				foreach ($orphans as $old) {
					$value = $latest ? trim((string)$latest->$old) : null;
					foreach ($fresh as $new) {
						if (count($orphans) === 1 && count($fresh) === 1 || ($value !== null && $value === trim($fields[$new]['default']))) {
							$renames[] = array('from' => $old, 'to' => $new);
							break;
						}
					}
				}
			}
			$orphans = $orphans_all;
		}

		// the slug stays as it is: it is indexed whole when it's a varchar,
		// and never longer than 80 characters
		$narrow = $exists ? cms_types::narrow($type, array_diff_key(array_map(function ($f) { return $f['type']; }, $fields), array('slug' => 1))) : array();

		$table = $meta + array(
			'kind' => $kind,
			'table' => $type,
			'exists' => $exists,
			'rows' => $exists ? (int)R::count($type) : 0,
			'fields' => $status,
			'missing' => $missing,
			'retyped' => $retyped,
			'narrow' => $narrow,
			'orphans' => $orphans,
			'rename_candidates' => $renames,
		);
		if (i18n::enabled()) {
			$table['translated'] = array_keys(array_filter($fields, function ($f) { return !empty($f['translate']); }));
			$table['missing_translations'] = $missing_translations;
		}
		if (i18n::enabled() && $kind !== 'page') $table['missing_translations'] = array_merge(isset($table['missing_translations']) ? $table['missing_translations'] : array(), $missing_slugs);
		return $table + array('kept' => $kept);
	}

	// the field a translation column is of (headline for headline__ro,
	// name for name__pt_br), or null for any other column. __ is in no
	// field's name (D3), so the shape alone says it.
	static function translation_of($column) {
		return preg_match('/^([a-z0-9]+(?:_[a-z0-9]+)*_?)__([a-z]{2,3}(?:_[a-z0-9]{2,8})?)$/', $column, $m) ? $m[1] : null;
	}

	// Creates missing tables and columns, each of its field's type, with the
	// template defaults, the same way the CMS would on a request in
	// development; converts retyped columns whose values all fit
	function apply() {
		$status = $this->status();
		$changes = array();
		$was_frozen = database::$frozen;
		R::freeze(false);
		try {
			foreach ($status['tables'] as $table) {
				foreach (cms_types::widen($table['table'], $table['narrow']) as $field => $type) {
					$changes[] = "widened {$table['table']}.$field to ".cms_types::sql($type);
				}
				foreach ($table['retyped'] as $retype) {
					try {
						$bad = cms_types::retype($table['table'], $retype['field'], $retype['to']);
					} catch (RuntimeException $e) {
						$changes[] = 'kept '.$e->getMessage();
						continue;
					}
					$changes[] = $bad
						? "kept {$table['table']}.{$retype['field']} as {$retype['from']}: ".implode(', ', array_map('json_encode', $bad))." can't be {$retype['to']}; change them and run --apply again"
						: "{$table['table']}.{$retype['field']} is now {$retype['to']} (was {$retype['from']})";
				}
				// records: every column the model declares, and no row left behind
				if ($table['kind'] === 'record') {
					$translations = isset($table['missing_translations']) ? $table['missing_translations'] : array();
					if ($table['exists'] && !$table['missing'] && !$translations) continue;
					cms_records::ensure(cms_records::info($table['name']), true);
					if (in_array('saved_lang', $translations, true)) cms_types::ensure($table['table'], array('saved_lang' => 'text'));
					if (!$table['exists'] || $table['missing']) $changes[] = $table['exists'] ? "added {$table['table']}.".implode(", {$table['table']}.", $table['missing']) : "created table {$table['table']}";
					if ($table['exists']) {
						foreach ($translations as $column) $changes[] = "added {$table['table']}.$column";
					}
					continue;
				}
				// multilingual: each translated field's column per language,
				// and saved_lang; empty, so each language shows the default
				// until it is translated
				$translated = isset($table['translated']) ? $table['translated'] : array();
				if ($table['exists'] && !$table['missing'] && !empty($table['missing_translations'])) {
					$system = in_array('saved_lang', $table['missing_translations'], true) ? array('saved_lang' => 'text') : array();
					cms_types::ensure($table['table'], $system, $translated);
					foreach ($table['missing_translations'] as $column) $changes[] = "added {$table['table']}.$column";
					continue;
				}
				if ($table['exists'] && !$table['missing']) continue;
				if (!$table['exists'] && !$table['fields']) continue; // a page with collections only
				$system = $table['kind'] === 'page' ? array('slug', 'updated_at') : array('slug', 'enabled', 'published_at', 'updated_at');
				if (isset($table['translated'])) $system[] = 'saved_lang';
				$types = array_intersect_key(cms_types::$system, array_flip($system));
				foreach ($table['fields'] as $name => $field) $types[$name] = $field['type'];
				cms_types::ensure($table['table'], $types, $translated);
				if ($table['exists']) {
					foreach ((array)(isset($table['missing_translations']) ? $table['missing_translations'] : array()) as $column) $changes[] = "added {$table['table']}.$column";
				}
				$bean = $table['exists'] ? cms_store::latest($table['table']) : null;
				if (!$bean) {
					$bean = R::dispense($table['table']);
					if ($table['kind'] === 'page') $bean->slug = $table['slug'];
					if ($table['kind'] === 'collection') {
						$bean->enabled = 1;
						$bean->slug = cms_store::slugify(cms_store::slug_source(array_map(function ($f) { return $f['default']; }, $table['fields'])));
					}
					$changes[] = "created table {$table['table']}";
				}
				foreach ($table['fields'] as $name => $field) {
					if ($field['in_database']) continue;
					$bean->$name = cms_types::clean($field['type'], $field['default'], $name);
					if ($table['exists']) $changes[] = "added {$table['table']}.$name";
				}
				$bean->updated_at = R::isoDateTime();
				R::store($bean);
			}
			// every table with slugs gets its index, new or made before 2.1.10;
			// multilingual, also a slug column per language (slug__ro), each
			// with its own index
			foreach ($status['tables'] as $table) {
				if (!cms_store::table_exists($table['table']) || !array_key_exists('slug', cms_store::columns($table['table']))) continue;
				if (cms_types::index_slug($table['table'])) $changes[] = "indexed {$table['table']}.slug";
				if (!i18n::enabled() || $table['kind'] === 'page') continue;
				$had = cms_store::columns($table['table']);
				cms_types::ensure($table['table'], array(), array('slug'));
				foreach (cms_store::slug_columns(cms_store::columns($table['table'])) as $column) {
					if ($column === 'slug') continue;
					if (!array_key_exists($column, $had) && !in_array("added {$table['table']}.$column", $changes, true)) $changes[] = "added {$table['table']}.$column";
					if (cms_types::index_slug($table['table'], $column)) $changes[] = "indexed {$table['table']}.$column";
				}
			}
			// bundled and model-declared tables: each column declared as the
			// type of its default (TEXT, INT), with no row written. A stored
			// row would size MySQL's columns by its values (varchar(191),
			// tinyint) and frozen, they'd never grow.
			foreach ($status['system_tables'] as $table) {
				$types = array_map(array('cms_types', 'of_default'), $this->system_tables()[$table['table']]);
				foreach (cms_types::widen($table['table'], $types) as $field => $type) {
					$changes[] = "widened {$table['table']}.$field to ".cms_types::sql($type);
				}
				if (!$table['missing']) continue;
				cms_types::ensure($table['table'], $types);
				$changes[] = $table['exists'] ? "added {$table['table']}.".implode(", {$table['table']}.", $table['missing']) : "created table {$table['table']}";
			}
		} finally {
			R::freeze($was_frozen);
			cms_store::forget();
		}
		return $changes;
	}

	// with $dry_run, both check everything and say what they would do. A
	// field's translation columns go with it: headline__ro to title__ro,
	// whether multilingual is on or they are kept translations
	function rename($table, $from, $to, $dry_run = false) {
		foreach (array($table, $from, $to) as $name) {
			if (!preg_match('/^[a-z0-9_]+$/', $name)) throw new InvalidArgumentException("Invalid name '$name'");
		}
		cms_store::connect();
		if (!array_key_exists($from, cms_store::columns($table))) throw new InvalidArgumentException("$table has no column $from");
		$said = $this->rename_column($table, $from, $to, $dry_run);
		$also = array();
		foreach (array_keys(cms_store::columns($table)) as $column) {
			if (self::translation_of($column) !== $from) continue;
			$suffix = substr($column, strlen($from));
			$also[] = $this->rename_column($table, $column, $to.$suffix, $dry_run, false);
		}
		cms_store::forget();
		return $said.($also ? ' (and '.implode(', ', $also).')' : '');
	}

	// one column renamed, or moved into one that exists already (created
	// with the template default by a request in development): the content
	// goes over, then the old one is dropped
	protected function rename_column($table, $from, $to, $dry_run, $named = true) {
		$what = $named ? "$table.$from" : $from;
		cms_store::forget();
		if (!array_key_exists($to, cms_store::columns($table))) {
			if ($dry_run) return ($named ? 'would rename ' : '')."$what to $to";
			R::exec("ALTER TABLE `$table` RENAME COLUMN `$from` TO `$to`");
			return ($named ? 'renamed ' : '')."$what to $to";
		}
		if ($dry_run) return ($named ? 'would move ' : '')."$what into $to";
		R::exec("UPDATE `$table` SET `$to` = `$from` WHERE `$from` IS NOT NULL");
		R::exec("ALTER TABLE `$table` DROP COLUMN `$from`");
		return ($named ? 'moved ' : '')."$what into $to";
	}

	function drop($table, $column = null, $force = false, $dry_run = false) {
		foreach (array($table, (string)$column) as $name) {
			if (!preg_match('/^[a-z0-9_]*$/', $name)) throw new InvalidArgumentException("Invalid name '$name'");
		}
		// only CMS page and collection tables, a column as much as the whole
		// table; what a model declares (schema(), types()) is the model's,
		// --force or not
		$what = $column === null ? "Only CMS page and collection tables can be dropped" : "Only columns of CMS page and collection tables can be dropped";
		if (!preg_match('/^[a-z0-9]+(page|data)$/', $table) || in_array($table, array('usersdata', 'rasterdata'))) throw new InvalidArgumentException($what);
		$declared = $this->system_tables();
		if (isset($declared[$table])) throw new InvalidArgumentException("$what; $table is declared by a model's schema()");
		$record = cms_records::for_table($table);
		if ($record && ($column === null || array_key_exists($column, cms_records::columns($record)))) {
			throw new InvalidArgumentException(($column === null ? "$table holds the records" : "$table.$column is declared")." of the type {$record['name']} in models/{$record['model']}; change its types() instead");
		}
		// refuse to drop what the templates still use
		if (!$force) {
			foreach ($this->status()['tables'] as $used) {
				if ($used['table'] !== $table) continue;
				if ($column === null) throw new InvalidArgumentException("$table is used by the templates; remove the annotations first (or pass --force)");
				if (isset($used['fields'][$column])) throw new InvalidArgumentException("$table.$column is used by the templates; remove the annotation first (or pass --force)");
			}
		}
		if ($column === null) {
			cms_store::connect();
			if (!cms_store::table_exists($table)) throw new InvalidArgumentException("There is no table $table");
			if ($dry_run) return "would drop table $table";
			R::exec("DROP TABLE `$table`");
			return "dropped table $table";
		}
		if (in_array($column, cms_store::$system_fields)) throw new InvalidArgumentException("$column is managed by Raster");
		cms_store::connect();
		if (!array_key_exists($column, cms_store::columns($table))) throw new InvalidArgumentException("$table has no column $column");
		if ($dry_run) return "would drop $table.$column";
		// a slug column per language (slug__ro) has an index, which SQLite
		// won't drop the column under; MySQL drops it with the column
		if (strpos($column, 'slug__') === 0 && R::getDatabaseAdapter()->getDatabase()->getDatabaseType() !== 'mysql') R::exec("DROP INDEX IF EXISTS `{$table}_{$column}`");
		R::exec("ALTER TABLE `$table` DROP COLUMN `$column`");
		return "dropped $table.$column";
	}
}
