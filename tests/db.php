<?php
// The databases a test suite runs on. SQLite files by default; with
//
//   RASTER_DB=mysql://root@127.0.0.1:3306/raster php tests/demo.php
//
// every database a suite asks for is a new, empty MySQL database on that
// server (raster_<pid>_<n>, after the name in the address), dropped when the
// suite ends. The suites read RASTER_DB before they set it themselves.

require_once dirname(__DIR__).'/system/database.php';

$test_mysql = strpos((string)getenv('RASTER_DB'), 'mysql://') === 0 ? getenv('RASTER_DB') : null;
$test_databases = array();

// a test that only means something on SQLite: it is counted as skipped on
// MySQL, with the reason, instead of failing or vanishing
class test_skipped extends Exception {}
function sqlite_only($reason) {
	global $test_mysql;
	if ($test_mysql) throw new test_skipped("SQLite only: $reason");
}
function on_mysql() {
	global $test_mysql;
	return (bool)$test_mysql;
}

// RASTER_DB for a fresh, empty database: the SQLite file given, or a new
// MySQL database when the suite runs on MySQL. The same file gives the same
// database again.
function test_db($file) {
	global $test_mysql, $test_databases;
	if (!$test_mysql) return $file;
	if (isset($test_databases[$file])) return test_server($test_databases[$file]);
	$url = parse_url($test_mysql);
	$name = (isset($url['path']) && trim($url['path'], '/') !== '' ? trim($url['path'], '/') : 'raster').'_'.getmypid().'_'.count($test_databases);
	$server = test_pdo(test_server('mysql'));
	$server->exec("DROP DATABASE IF EXISTS `$name`");
	$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
	$test_databases[$file] = $name;
	return test_server($name);
}

// the MySQL server's address, with another database name
function test_server($name) {
	global $test_mysql;
	return 'mysql://'.preg_replace('#/.*$#', '', substr($test_mysql, 8))."/$name";
}

// a database that can't be reached: a file that is not a database, or a
// MySQL database that is gone
function test_db_break($db) {
	if (strpos($db, 'mysql://') !== 0) return file_put_contents($db, str_repeat('not a database ', 200));
	test_pdo(test_server('mysql'))->exec('DROP DATABASE IF EXISTS `'.basename($db).'`');
}

// a copy of a database, as copy() makes of an SQLite file
function test_db_copy($from, $file) {
	if (strpos($from, 'mysql://') !== 0) {
		copy($from, $file);
		return $file;
	}
	$to = test_db($file);
	$pdo = test_pdo($from);
	$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.basename($to).'` CHARACTER SET utf8mb4');
	foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
		$pdo->exec('CREATE TABLE `'.basename($to)."`.`$table` LIKE `$table`");
		$pdo->exec('INSERT INTO `'.basename($to)."`.`$table` SELECT * FROM `$table`");
	}
	return $to;
}

// a PDO connection to what a RASTER_DB value names
function test_pdo($db) {
	$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
	if (strpos($db, 'mysql://') !== 0) return new PDO("sqlite:$db", null, null, $options);
	$c = database::mysql_url($db);
	return new PDO($c['dsn'], $c['user'], $c['password'], $options);
}

// the column names of a table, on either database
function test_columns($pdo, $table) {
	if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
	return array_map(function ($c) { return $c['name']; }, $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC));
}

register_shutdown_function(function () {
	global $test_mysql, $test_databases;
	if (!$test_databases) return;
	$server = test_pdo(test_server('mysql'));
	foreach ($test_databases as $name) $server->exec("DROP DATABASE IF EXISTS `$name`");
});
