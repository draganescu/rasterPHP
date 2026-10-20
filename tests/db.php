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

// ## PHP children
//
// A suite starts hundreds of PHP processes (php -S servers, bin/raster, MCP
// sessions, race workers), and each one compiles the whole framework again.
// With opcache's file cache they read the compiled code from one directory
// per run instead, which is removed when the run ends. Timestamps are still
// checked on every file, so a test that writes a PHP file and runs it sees
// the new code (opcache never caches a file changed in the last two seconds).
// RASTER_TEST_OPCACHE=off runs the children as they were.

// the -d options for a child PHP: an array, empty when opcache is not there
function php_flags() {
	static $flags = null;
	if ($flags !== null) return $flags;
	$flags = array();
	if (getenv('RASTER_TEST_OPCACHE') === 'off' || !extension_loaded('Zend OPcache')) return $flags;
	$dir = sys_get_temp_dir().'/raster-opcache-'.getmypid();
	// caches of runs that were killed
	foreach (glob(sys_get_temp_dir().'/raster-opcache-*') ?: array() as $old) {
		$pid = (int)substr($old, strrpos($old, '-') + 1);
		if ($pid !== getmypid() && !file_exists("/proc/$pid") && !(function_exists('posix_kill') && @posix_kill($pid, 0))) exec('rm -rf '.escapeshellarg($old));
	}
	@mkdir($dir, 0775, true);
	foreach (array('opcache.enable=1', 'opcache.enable_cli=1', "opcache.file_cache=$dir", 'opcache.file_cache_only=1', 'opcache.validate_timestamps=1', 'opcache.revalidate_freq=0') as $option) {
		array_push($flags, '-d', $option);
	}
	register_shutdown_function(function () use ($dir) { exec('rm -rf '.escapeshellarg($dir)); });
	return $flags;
}

// a PHP command line as an array for proc_open: the binary, the flags above, then the arguments
function php_argv() {
	return array_merge(array(PHP_BINARY), php_flags(), func_get_args());
}

// the same as a string for a shell: the escaped binary and flags, to put
// where escapeshellarg(PHP_BINARY) went
function php_cmd() {
	return implode(' ', array_map('escapeshellarg', array_merge(array(PHP_BINARY), php_flags())));
}

// ## Fixtures a killed run left behind
//
// The tests write views and model stubs into the checkout and take them away
// when they end. A run that is killed in between leaves them, and they break
// the next run (a stub model that is "installed", a view that is "extra").
// So a suite starts by removing what its tests create: test_sweep($root,
// array('application/views/default/zz*', ...)) with paths (and * patterns)
// relative to the checkout. Only what git does not track is removed, so
// nothing real can go; with no git, nothing is.
function test_sweep($root, $patterns) {
	$listing = shell_exec('git -C '.escapeshellarg($root).' ls-files -z 2>/dev/null');
	if (!$listing) return array();
	$tracked = array_flip(array_filter(explode("\0", $listing)));
	$removed = array();
	foreach ($patterns as $pattern) {
		foreach (glob("$root/$pattern") ?: array() as $path) {
			$relative = substr($path, strlen($root) + 1);
			if (isset($tracked[$relative])) continue;
			if (is_link($path)) { @unlink($path); $removed[] = $relative; continue; }
			if (is_dir($path)) {
				foreach ($tracked as $file => $unused) if (strpos($file, "$relative/") === 0) continue 2;
				exec('rm -rf '.escapeshellarg($path));
			} else {
				@unlink($path);
			}
			$removed[] = $relative;
		}
	}
	return $removed;
}

// The stamp a browser would post: a form shown $ago seconds ago, signed with
// the key the suites give their servers (RASTER_SECRET=test-secret).
function stamp_for_tests($ago = 60) {
	$time = time() - $ago;
	return $time.'.'.hash_hmac('sha256', 'raster_ts|'.$time, 'test-secret');
}
