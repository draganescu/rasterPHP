<?php
// #Schema
//
// Compares the content model the templates describe with the tables the
// database has. Templates are the source of truth:
// - missing: a field in a template with no column yet
// - orphan: a column no template uses any more (often a renamed field)
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
			$tables[] = $this->compare('collection', $collection['type'], $collection['fields'], array(
				'name' => $collection['name'], 'views' => $collection['views'],
			));
		}

		// tables in the database that no template uses
		$known = array_map(function ($t) { return $t['table']; }, $tables);
		$unused = array();
		foreach (R::inspect() as $table) {
			if (!preg_match('/(page|data)$/', $table)) continue;
			if (in_array($table, $known) || in_array($table, array('usersdata', 'rasterdata'))) continue;
			$unused[] = $table;
		}

		$drift = !empty($unused);
		foreach ($tables as $table) {
			if ((!$table['exists'] && $table['fields']) || $table['missing'] || $table['orphans']) $drift = true;
		}

		// tables the bundled models use (accounts, subscribers). A fluid
		// database creates them when needed; a frozen one needs --apply.
		$system = array();
		foreach ($this->system_tables() as $name => $fields) {
			$columns = cms_store::table_exists($name) ? cms_store::columns($name) : array();
			$missing = array_values(array_diff(array_keys($fields), array_keys($columns)));
			$system[] = array('table' => $name, 'exists' => (bool)$columns, 'missing' => $missing);
			if ($missing && database::$frozen) $drift = true;
		}

		return array(
			'environment' => config::get('environment'),
			'frozen' => database::$frozen,
			'drift' => $drift,
			'tables' => $tables,
			'system_tables' => $system,
			'unused_tables' => $unused,
			'site_url' => (bool)config::get('trusted_url'),
		);
	}

	function system_tables() {
		$tables = array();
		foreach (array('authentication', 'newsletter') as $model) {
			if (class_exists($model) && method_exists($model, 'schema')) $tables += $model::schema();
		}
		return $tables;
	}

	protected function compare($kind, $type, $fields, $meta) {
		$exists = cms_store::table_exists($type);
		$columns = $exists ? cms_store::columns($type) : array();
		$missing = array();
		$status = array();
		foreach ($fields as $name => $field) {
			$has = array_key_exists($name, $columns);
			if (!$has) $missing[] = $name;
			$status[$name] = array('default' => $field['default'], 'in_database' => $has);
		}
		$orphans = array();
		foreach (array_keys($columns) as $column) {
			if (in_array($column, cms_store::$system_fields)) continue;
			if (!array_key_exists($column, $fields)) $orphans[] = $column;
		}

		// An orphan next to a new field is most likely a renamed annotation.
		// In development the new column may already exist (created by the
		// first request after the rename) still holding its template default.
		$renames = array();
		if ($orphans) {
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
				if (!in_array($name, $missing) && R::getCell("SELECT COUNT(*) FROM $type WHERE $name IS NULL") > 0) $added_later[] = $name;
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
		}

		return $meta + array(
			'kind' => $kind,
			'table' => $type,
			'exists' => $exists,
			'rows' => $exists ? (int)R::count($type) : 0,
			'fields' => $status,
			'missing' => $missing,
			'orphans' => $orphans,
			'rename_candidates' => $renames,
		);
	}

	// Creates missing tables and columns with the template defaults, the
	// same way the CMS would on a request in development
	function apply() {
		$status = $this->status();
		$changes = array();
		$was_frozen = database::$frozen;
		R::freeze(false);
		try {
			foreach ($status['tables'] as $table) {
				if ($table['kind'] === 'collection' && $table['exists']) {
					$columns = cms_store::columns($table['table']);
					foreach (array('slug', 'published_at') as $system) {
						if (!array_key_exists($system, $columns)) {
							$latest = cms_store::latest($table['table']);
							$latest->$system = '';
							R::store($latest);
							$changes[] = "added {$table['table']}.$system";
						}
					}
				}
				if ($table['exists'] && !$table['missing']) continue;
				if (!$table['exists'] && !$table['fields']) continue; // a page with collections only
				$bean = $table['exists'] ? cms_store::latest($table['table']) : null;
				if (!$bean) {
					$bean = R::dispense($table['table']);
					if ($table['kind'] === 'page') $bean->slug = $table['slug'];
					if ($table['kind'] === 'collection') {
						$bean->enabled = '1';
						$bean->published_at = '';
						$bean->slug = cms_store::slugify(cms_store::slug_source(array_map(function ($f) { return $f['default']; }, $table['fields'])));
					}
					$changes[] = "created table {$table['table']}";
				}
				foreach ($table['fields'] as $name => $field) {
					if ($field['in_database']) continue;
					$bean->$name = trim($field['default']);
					if ($table['exists']) $changes[] = "added {$table['table']}.$name";
				}
				$bean->updated_at = R::isoDateTime();
				R::store($bean);
			}
			// bundled model tables: a row with every column, then removed
			foreach ($status['system_tables'] as $table) {
				if (!$table['missing']) continue;
				$fields = $this->system_tables()[$table['table']];
				$bean = R::dispense($table['table']);
				foreach ($fields as $name => $default) $bean->$name = $default;
				R::store($bean);
				R::trash($bean);
				$changes[] = $table['exists'] ? "added {$table['table']}.".implode(", {$table['table']}.", $table['missing']) : "created table {$table['table']}";
			}
		} finally {
			R::freeze($was_frozen);
		}
		return $changes;
	}

	function rename($table, $from, $to) {
		foreach (array($table, $from, $to) as $name) {
			if (!preg_match('/^[a-z0-9_]+$/', $name)) throw new InvalidArgumentException("Invalid name '$name'");
		}
		cms_store::connect();
		if (!array_key_exists($from, cms_store::columns($table))) throw new InvalidArgumentException("$table has no column $from");
		if (!array_key_exists($to, cms_store::columns($table))) {
			R::exec("ALTER TABLE $table RENAME COLUMN $from TO $to");
			return "renamed $table.$from to $to";
		}
		// the new column already exists (created with the template default by a
		// request in development): move the content over, then drop the old one
		R::exec("UPDATE $table SET $to = $from WHERE $from IS NOT NULL");
		R::exec("ALTER TABLE $table DROP COLUMN $from");
		return "moved $table.$from into $to";
	}

	function drop($table, $column = null, $force = false) {
		// refuse to drop what the templates still use
		if (!$force) {
			foreach ($this->status()['tables'] as $used) {
				if ($used['table'] !== $table) continue;
				if ($column === null) throw new InvalidArgumentException("$table is used by the templates; remove the annotations first (or pass --force)");
				if (isset($used['fields'][$column])) throw new InvalidArgumentException("$table.$column is used by the templates; remove the annotation first (or pass --force)");
			}
		}
		if ($column === null) {
			if (!preg_match('/^[a-z0-9]+(page|data)$/', $table) || in_array($table, array('usersdata', 'rasterdata'))) {
				throw new InvalidArgumentException("Only CMS page and collection tables can be dropped");
			}
			cms_store::connect();
			if (!cms_store::table_exists($table)) throw new InvalidArgumentException("There is no table $table");
			R::exec("DROP TABLE $table");
			return "dropped table $table";
		}
		foreach (array($table, $column) as $name) {
			if (!preg_match('/^[a-z0-9_]+$/', $name)) throw new InvalidArgumentException("Invalid name '$name'");
		}
		if (in_array($column, cms_store::$system_fields)) throw new InvalidArgumentException("$column is managed by Raster");
		cms_store::connect();
		if (!array_key_exists($column, cms_store::columns($table))) throw new InvalidArgumentException("$table has no column $column");
		R::exec("ALTER TABLE $table DROP COLUMN $column");
		return "dropped $table.$column";
	}
}
