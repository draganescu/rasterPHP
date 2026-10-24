<?php
// #Field types
//
// Every CMS field is one of seven types: text, int, number, bool, date,
// datetime or time. The column says which (SQLite keeps the type a column
// was declared with), so whoever writes, the value is checked and stored
// the same way: filters and order compare numbers as numbers and dates as
// dates, and models, MCP and /api read PHP ints, floats and bools.
//
// Where a field's type comes from:
// - a template field: the shape of its mock-up content. 14 is an int, 4.50 a
//   number, 2026-10-10 a date, 2026-10-10 19:00 a datetime, 19:00 a time.
//   Anything else is text: 14 lei, 0721 000 000, Jazz night.
// - a record field: the PHP type of the model's default. 0 is an int, 0.0 a
//   number, false a bool, '' text. 'types' => array('date' => 'date') in the
//   declaration names the rest.
//
// Templates still print text: a float shows as many decimals as its mock-up
// (4.50 stays 4.50), a bool prints 1 or 0, an empty value prints nothing.
class cms_types {

	static $types = array('text', 'int', 'number', 'bool', 'date', 'datetime', 'time');

	// what each type is declared as, in SQLite and in MySQL. RedBean knows
	// none of these names but LONGTEXT (and MySQL's DOUBLE, which only ever
	// holds numbers), so it never widens them; SQLite gives them the affinity
	// they need. Text is LONGTEXT in MySQL: TEXT stops at 64 KB, and an SQLite
	// text has no limit, so a long page would fit one and not the other.
	static $sql = array(
		'text' => 'TEXT', 'int' => 'INT', 'number' => 'REAL', 'bool' => 'BOOLEAN',
		'date' => 'DATE', 'datetime' => 'DATETIME', 'time' => 'TIME',
	);
	static $mysql = array(
		'text' => 'LONGTEXT', 'int' => 'INT', 'number' => 'DOUBLE', 'bool' => 'TINYINT(1)',
		'date' => 'DATE', 'datetime' => 'DATETIME', 'time' => 'TIME',
	);

	// Raster's own columns
	static $system = array(
		'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime',
		'updated_at' => 'datetime', 'created_at' => 'datetime', 'owner' => 'int',
		'saved_lang' => 'text',
	);

	// table => field => type, read once per request (see forget)
	protected static $tables = array();

	// ##Where types come from

	static function of_example($value) {
		$value = trim((string)$value);
		if (preg_match('/^-?(0|[1-9]\d{0,17})$/', $value)) return 'int';
		if (preg_match('/^-?(0|[1-9]\d*)\.\d+$/', $value)) return 'number';
		if (preg_match('/^\d{4}-\d\d-\d\d$/', $value)) return 'date';
		if (preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?$/', $value)) return 'datetime';
		if (preg_match('/^\d\d:\d\d$/', $value)) return 'time';
		return 'text';
	}

	static function of_default($default) {
		if (is_bool($default)) return 'bool';
		if (is_int($default)) return 'int';
		if (is_float($default)) return 'number';
		return 'text';
	}

	// a declared column type back to its field type, as SQLite (INTEGER for
	// id) or MySQL (int(11), tinyint(1), double) reports it
	static function of_column($declared) {
		$declared = strtoupper(trim((string)$declared));
		if ($declared === 'TINYINT(1)' || $declared === 'BOOLEAN') return 'bool';
		$declared = trim(preg_replace('/\(.*\)| UNSIGNED/', '', $declared));
		if (in_array($declared, array('INT', 'INTEGER', 'BIGINT', 'MEDIUMINT', 'SMALLINT', 'TINYINT'), true)) return 'int';
		if (in_array($declared, array('REAL', 'DOUBLE', 'FLOAT'), true)) return 'number';
		if (in_array($declared, array('DATE', 'DATETIME', 'TIME'), true)) return strtolower($declared);
		return 'text';
	}

	// what a type is declared as in this database
	static function sql($type) {
		return R::getDatabaseAdapter()->getDatabase()->getDatabaseType() === 'mysql' ? self::$mysql[$type] : self::$sql[$type];
	}

	// field => type of a table as it is in the database
	static function of_table($table) {
		if (!isset(self::$tables[$table])) {
			self::$tables[$table] = array_map(array('cms_types', 'of_column'), cms_store::columns($table));
		}
		return self::$tables[$table];
	}

	static function forget() {
		self::$tables = array();
	}

	// ##Languages (#200, D3, D7)

	// the column that holds $field in $lang (null: i18n::current()):
	// headline in the default language and with multilingual off,
	// headline__ro, name__pt_br otherwise
	static function column($field, $lang = null) {
		return $field.i18n::suffix($lang);
	}

	// whether a field of $type (text, int, ...) printed as element content
	// ($attribute null) or into the attribute $attribute (alt, src, ...) has
	// a translation per language (D7): text, as content or in an attribute
	// that holds words. A photo's src, a link's href and every number, date
	// and bool are shared by every language. Cheap, so page_field can ask it
	// on a page request; the content model asks it too.
	static function translates($type, $attribute = null) {
		if ($type !== 'text') return false;
		if ($attribute === null || $attribute === '') return true;
		return in_array(strtolower(ltrim((string)$attribute, '@')), array('alt', 'title', 'placeholder', 'aria-label', 'content'), true);
	}

	// the fields of $table that have a translation per language (D7), as
	// its columns say: headline when headline__ro is there. The others are
	// shared by every language. Empty when multilingual is off.
	static function translated($table) {
		if (!i18n::enabled()) return array();
		$columns = self::of_table($table);
		$fields = array();
		foreach (i18n::available() as $lang) {
			$suffix = i18n::suffix($lang);
			if ($suffix === '') continue;
			foreach ($columns as $column => $type) {
				$field = substr($column, 0, -strlen($suffix));
				if (substr($column, -strlen($suffix)) === $suffix && isset($columns[$field]) && !in_array($field, $fields, true)) $fields[] = $field;
			}
		}
		return $fields;
	}

	// ##Columns

	// Creates the table and the columns it lacks, each declared as its type.
	// Columns that exist are left as they are; schema --apply retypes them.
	// $translated names the fields (of $types, or already in the table) that
	// also get a column per translation language when multilingual is on
	// (headline__ro), of the field's type. Off it is ignored, so the SQL is
	// what it always was. Raster declares a translation column before any
	// value is stored in it: a column RedBean makes itself takes its type
	// from the value, and name__id would also get an index and an attempt
	// at a foreign key to a table name_ (and, in MySQL, an int column).
	static function ensure($table, $types, $translated = array()) {
		if (!preg_match('/^[a-z0-9_]+$/', $table)) throw new InvalidArgumentException("Invalid table '$table'");
		if (!cms_store::table_exists($table)) {
			R::getWriter()->createTable($table);
			cms_store::forget();
		}
		$have = cms_store::columns($table);
		foreach ($types as $field => $type) {
			if (array_key_exists($field, $have) || $field === 'id') continue;
			if (!preg_match('/^[a-z0-9_]+$/', $field)) throw new InvalidArgumentException("Invalid field '$field'");
			// the adapter, not R::exec: a fluid RedBean ignores some failed SQL
			R::getDatabaseAdapter()->exec("ALTER TABLE `$table` ADD `$field` ".self::sql($type));
		}
		if ($translated && i18n::enabled()) {
			cms_store::forget();
			$now = cms_store::columns($table);
			foreach ((array)$translated as $field) {
				if (!preg_match('/^[a-z0-9_]+$/', $field) || strpos($field, '__') !== false) throw new InvalidArgumentException("Invalid field '$field'");
				$type = isset($types[$field]) ? $types[$field] : (isset($now[$field]) ? self::of_column($now[$field]) : 'text');
				foreach (i18n::available() as $lang) {
					$column = self::column($field, $lang);
					if ($column === $field || array_key_exists($column, $now)) continue;
					R::getDatabaseAdapter()->exec("ALTER TABLE `$table` ADD `$column` ".self::sql($type));
					$now[$column] = $type;
					// each language's slug is looked up as the slug is
					if ($field === 'slug') self::index_slug($table, $column);
				}
			}
		}
		cms_store::forget();
		if (isset($types['slug']) && !array_key_exists('slug', $have)) self::index_slug($table);
	}

	// Columns of a table that MySQL sized by the values of the first row
	// stored in fluid mode (varchar(191) for text, tinyint for a number), or
	// that Raster 2.2.30 and earlier declared TEXT, and that a frozen database
	// never grows: field => the type ($types) they should be. SQLite's
	// columns take any length.
	static function narrow($table, $types) {
		if (R::getDatabaseAdapter()->getDatabase()->getDatabaseType() !== 'mysql' || !cms_store::table_exists($table)) return array();
		$columns = cms_store::columns($table);
		$narrow = array();
		foreach ($types as $field => $type) {
			if (!isset($columns[$field])) continue;
			$declared = strtolower((string)$columns[$field]);
			if ($type === 'text' && preg_match('/^(varchar|char|tinytext|text|mediumtext)\b/', $declared)) $narrow[$field] = 'text';
			if ($type === 'int' && preg_match('/^(tinyint|smallint|mediumint)\b/', $declared)) $narrow[$field] = 'int';
		}
		return $narrow;
	}

	// Declares the narrow columns (see narrow) as their type; returns them.
	// Every value fits: LONGTEXT and INT hold more than what they were.
	static function widen($table, $types) {
		$narrow = self::narrow($table, $types);
		foreach ($narrow as $field => $type) {
			R::getDatabaseAdapter()->exec("ALTER TABLE `$table` MODIFY `$field` ".self::sql($type));
		}
		if ($narrow) cms_store::forget();
		return $narrow;
	}

	// An index on the slug, so opening an item and picking a free slug don't
	// read the whole table. Made with a new table, and by schema --apply for
	// tables made before 2.1.10. True when it was missing and is made now.
	// $column is the slug column (slug__ro has one of its own, named
	// <table>_slug__ro).
	static function index_slug($table, $column = 'slug') {
		if (!preg_match('/^[a-z0-9_]+$/', $table)) throw new InvalidArgumentException("Invalid table '$table'");
		if (!preg_match('/^slug(__[a-z0-9_]+)?$/', $column)) throw new InvalidArgumentException("Invalid slug column '$column'");
		$adapter = R::getDatabaseAdapter();
		if ($adapter->getDatabase()->getDatabaseType() !== 'mysql') {
			if ($adapter->getCell("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", array("{$table}_{$column}"))) return false;
			$adapter->exec("CREATE INDEX IF NOT EXISTS `{$table}_{$column}` ON `$table` ($column)");
			return true;
		}
		$where = 'table_schema = DATABASE() AND table_name = ? AND column_name = ?';
		if ($adapter->getCell("SELECT COUNT(*) FROM information_schema.statistics WHERE $where", array($table, $column))) return false;
		// MySQL indexes a TEXT column by its start; a slug is at most 80 characters
		$kind = strtolower((string)$adapter->getCell("SELECT data_type FROM information_schema.columns WHERE $where", array($table, $column)));
		$adapter->exec("CREATE INDEX `{$table}_{$column}` ON `$table` ($column".(strpos($kind, 'text') !== false ? '(191)' : '').')');
		return true;
	}

	// Changes the type of a column whose stored values all convert; returns
	// the values that don't (and changes nothing) otherwise. In SQLite it all
	// happens in one transaction; MySQL can't roll back a change of columns,
	// so there the column made for the copy is removed again if it fails.
	// Throws when the database refuses (an indexed column, an SQLite older
	// than 3.35), leaving the column as it was.
	static function retype($table, $field, $type) {
		$rows = R::getAll("SELECT id, `$field` AS v FROM `$table`");
		$values = array();
		$bad = array();
		foreach ($rows as $row) {
			try {
				$values[$row['id']] = self::clean($type, $row['v'], $field);
			} catch (InvalidArgumentException $e) {
				$bad[] = $row['v'];
			}
		}
		if ($bad) return array_slice(array_unique($bad), 0, 5);
		$temp = "raster_{$field}_retyped";
		$adapter = R::getDatabaseAdapter();
		$mysql = $adapter->getDatabase()->getDatabaseType() === 'mysql';
		if (!$mysql && version_compare($adapter->getCell('SELECT sqlite_version()'), '3.35.0', '<')) {
			throw new RuntimeException("$table.$field as it was: SQLite ".$adapter->getCell('SELECT sqlite_version()')." can't drop a column; 3.35 or newer can");
		}
		// a copy column left by an earlier attempt. The adapter, not R::exec:
		// a fluid RedBean ignores some failed SQL, and this must not
		if (array_key_exists($temp, cms_store::columns($table))) $adapter->exec("ALTER TABLE `$table` DROP COLUMN `$temp`");
		// MySQL commits each change of columns at once: no transaction there
		if (!$mysql) $adapter->startTransaction();
		try {
			$adapter->exec("ALTER TABLE `$table` ADD `$temp` ".self::sql($type));
			foreach ($values as $id => $value) $adapter->exec("UPDATE `$table` SET `$temp` = ? WHERE id = ?", array($value, $id));
			$adapter->exec("ALTER TABLE `$table` DROP COLUMN `$field`");
			$adapter->exec("ALTER TABLE `$table` RENAME COLUMN `$temp` TO `$field`");
			if (!$mysql) $adapter->commit();
		} catch (Exception $e) {
			if (!$mysql) $adapter->rollback();
			cms_store::forget();
			if ($mysql && array_key_exists($temp, cms_store::columns($table)) && array_key_exists($field, cms_store::columns($table))) {
				$adapter->exec("ALTER TABLE `$table` DROP COLUMN `$temp`");
			}
			cms_store::forget();
			throw new RuntimeException("$table.$field as it was: ".$e->getMessage());
		}
		cms_store::forget();
		return array();
	}

	// ##Values

	// A value as it is stored: int, float, 1 or 0, Y-m-d, Y-m-d H:i:s, H:i,
	// text, or null for an empty value that isn't text. Throws when the value
	// isn't one of the type, naming the field and an example.
	static function clean($type, $value, $field) {
		if (is_array($value) || is_object($value)) throw new cms_type_error($field, 'text', 'is a list, not one value');
		if ($type === 'text') {
			if (is_bool($value)) return $value ? '1' : '0';
			return $value === null ? '' : self::media_stored((string)$value);
		}
		if (is_string($value)) $value = trim($value);
		if ($value === null || $value === '') return $type === 'bool' ? 0 : null;
		switch ($type) {
			case 'int':
				if (is_int($value)) return $value;
				if (is_float($value) && floor($value) == $value && abs($value) < 1e18) return (int)$value;
				// not 01234: a leading zero says it is a code (a postcode, a
				// phone number), and as an int it would quietly lose it
				if (is_string($value) && preg_match('/^[+-]?(0|[1-9]\d{0,17})$/', $value)) return (int)$value;
				break;
			case 'number':
				if (is_string($value) && is_numeric($value)) $value = (float)$value;
				// 1e999 is a number to PHP, but INF to the database
				if ((is_int($value) || is_float($value)) && is_finite((float)$value)) return (float)$value;
				break;
			case 'bool':
				if (is_bool($value)) return $value ? 1 : 0;
				$word = strtolower((string)$value);
				if (in_array($word, array('1', 'true', 'yes', 'on'), true)) return 1;
				if (in_array($word, array('0', 'false', 'no', 'off'), true)) return 0;
				break;
			case 'date':
			case 'datetime':
				$time = self::timestamp($value);
				if ($time !== null) return date($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', $time);
				break;
			case 'time':
				if (is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m)) return sprintf('%02d:%s', $m[1], $m[2]);
				// 8pm, noon; not a date, which has no time of day
				$time = self::timestamp($value, true);
				if ($time !== null) return date('H:i', $time);
				break;
		}
		throw new cms_type_error($field, $type);
	}

	// What strtotime makes of a date or time, when there is one in it. PHP
	// reads a lone letter as a military time zone ('a' is now in zone A), and
	// moves a date that doesn't exist (31 Feb) into March; both are refused.
	// today, now and next friday are dates.
	protected static function timestamp($value, $time_of_day = false) {
		if (!is_string($value) || preg_match('/^\d+$/', $value)) return null;
		$parsed = date_parse($value);
		if ($parsed['error_count'] || $parsed['warning_count']) return null;
		$date = $parsed['year'] !== false || $parsed['month'] !== false || $parsed['day'] !== false || isset($parsed['relative']);
		$hour = $parsed['hour'] !== false;
		if ($time_of_day ? !$hour : (!$date && !$hour && !empty($parsed['zone_type']))) return null;
		$time = strtotime($value);
		return $time === false ? null : $time;
	}

	// a stored value as PHP has it
	static function read($type, $value) {
		if ($value === null) return $type === 'bool' ? false : ($type === 'text' ? '' : null);
		switch ($type) {
			case 'int': return (int)$value;
			case 'number': return (float)$value;
			case 'bool': return (bool)(int)$value;
			// MySQL gives 19:00:00 for what SQLite keeps as 19:00
			case 'time': return substr((string)$value, 0, 5);
			case 'text': return self::media_shown((string)$value);
			default: return (string)$value;
		}
	}

	// ##Uploads
	// A picture's address is stored from the site's root, /media/<file>, and
	// shown under the folder the site runs in now. A site moves between
	// folders (a builder previews it under /preview/<id>/, then it is
	// published at a domain's root), and its database goes with it, so an
	// address saved under one folder is shown under the next. Only a value
	// that is a whole address of a file in media/ is touched.
	protected static function media_file($value) {
		$folder = trim(config::get('raster_media_folder', 'media'), '/');
		if (strlen($value) > 300 || !preg_match('~^(/[^\s"\'<>?#]*)?/'.preg_quote($folder, '~').'/([^/\s"\'<>?#]+)$~', $value, $m)) return null;
		return is_file(dirname(BASE).'/'.$folder.'/'.$m[2]) ? '/'.$folder.'/'.$m[2] : null;
	}

	static function media_stored($value) {
		$file = self::media_file($value);
		return $file === null ? $value : $file;
	}

	static function media_shown($value) {
		$file = self::media_file($value);
		return $file === null ? $value : rtrim((string)parse_url(config::get('base_uri'), PHP_URL_PATH), '/').$file;
	}

	// a row of a table, every field read as its type
	static function read_row($table, $row) {
		$types = self::of_table($table);
		foreach ($row as $field => $value) {
			if (isset($types[$field]) && !is_array($value)) $row[$field] = self::read($types[$field], $value);
		}
		return $row;
	}

	// a value for a template: text. $example is the mock-up a float takes its
	// decimals from.
	static function show($value, $example = null) {
		if ($value === null) return '';
		if (is_bool($value)) return $value ? '1' : '0';
		if (is_float($value) && $example !== null && preg_match('/^-?\d+\.(\d+)$/', trim((string)$example), $m)) {
			return number_format($value, strlen($m[1]), '.', '');
		}
		return is_scalar($value) ? (string)$value : $value;
	}

	static function show_row($row, $examples = array()) {
		foreach ($row as $field => $value) {
			if (!is_array($value)) $row[$field] = self::show($value, isset($examples[$field]) ? $examples[$field] : null);
		}
		return $row;
	}
}

// a value that isn't of its field's type
class cms_type_error extends InvalidArgumentException {
	public $field;
	public $type;

	static $examples = array(
		'int' => 'a whole number, like 4', 'number' => 'a number, like 4.50', 'bool' => 'yes or no (1 or 0)',
		'date' => 'a date, like 2026-10-05', 'datetime' => 'a date and time, like 2026-10-05 19:30', 'time' => 'a time, like 19:30',
		'text' => 'text',
	);

	function __construct($field, $type, $problem = null) {
		$this->field = $field;
		$this->type = $type;
		parent::__construct("'$field' ".($problem ?: 'must be '.self::$examples[$type]));
	}
}
