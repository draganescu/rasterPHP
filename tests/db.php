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

require_once __DIR__.'/php.php';

// ## Fixtures a killed run left behind
//
// The tests write views and model stubs into the checkout and take them away
// when they end. A run that is killed in between leaves them, and they break
// the next run (a stub model that is "installed", a view that is "extra").
//
// So a test that writes one names it first: test_fixture($path). The run
// keeps a list of those paths in the temp folder (one list per run and
// checkout) and holds a lock on it while it lives; a run that ends well
// deletes its list. A suite starts with test_sweep(): for each list whose run
// is gone (its lock is free) it MOVES the paths on it, to a folder in the
// temp folder, and says so. A path is only touched when it is inside the
// checkout, not tracked by git, and not changed since the list was last
// written (so work a developer did there afterwards stays). Paths named
// zz* or _zz* (the tests' own naming) are also moved when found by pattern.
// Names a developer may have made on purpose (a booking model, rsvp.html)
// are never swept by pattern.
function test_fixtures_dir($root) {
	return sys_get_temp_dir().'/raster-fixtures-'.sha1(realpath($root));
}

// the path written without . and .. parts
function test_normal_path($path) {
	$parts = array();
	foreach (explode('/', $path) as $part) {
		if ($part === '' || $part === '.') continue;
		if ($part === '..') array_pop($parts); else $parts[] = $part;
	}
	return '/'.implode('/', $parts);
}

// a path as the checkout's file for git: its folder's real path plus its
// name, relative to the checkout's real path, or null when it is outside
function test_relative($root, $path) {
	$base = realpath($root);
	$path = test_normal_path($path);
	$folder = realpath(dirname($path));
	if ($base === false || $folder === false) return null;
	$full = ($folder === '/' ? '' : $folder).'/'.basename($path);
	return strpos($full, $base.'/') === 0 ? substr($full, strlen($base) + 1) : null;
}

// the newest modification time in a file or a folder with all inside it
function test_newest($path) {
	$stat = @lstat($path);
	$newest = $stat ? (int)$stat['mtime'] : 0;
	if (is_dir($path) && !is_link($path)) {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) $newest = max($newest, (int)@$file->getMTime());
	}
	return $newest;
}

// the files git tracks, as the keys of an array (null when git can't say)
function test_tracked($root) {
	static $tracked = array();
	if (!isset($tracked[$root])) {
		$listing = shell_exec('git -C '.escapeshellarg($root).' ls-files -z 2>/dev/null');
		$tracked[$root] = $listing ? array_flip(array_filter(explode("\0", $listing))) : null;
	}
	return $tracked[$root];
}

function test_is_tracked($tracked, $relative) {
	if (isset($tracked[$relative])) return true;
	foreach ($tracked as $file => $unused) if (strpos($file, "$relative/") === 0) return true;
	return false;
}

// moves a leftover out of the checkout, to raster-swept-<date> in the temp folder
function test_move_aside($root, $relative) {
	$to = sys_get_temp_dir().'/raster-swept-'.date('Y-m-d').'/'.$relative;
	if (file_exists($to) || is_link($to)) $to .= '.'.uniqid();
	@mkdir(dirname($to), 0775, true);
	exec('mv '.escapeshellarg(realpath($root).'/'.$relative).' '.escapeshellarg($to).' 2>/dev/null', $out, $code);
	if ($code === 0) echo "moved leftover test fixture: $relative -> $to\n";
	return $code === 0;
}

// the paths of the dead runs' lists, and the suite's own zz* names, moved aside
function test_sweep($root, $patterns = array('zz*', '_zz*'), $folders = array()) {
	$tracked = test_tracked($root);
	if ($tracked === null || realpath($root) === false) return;
	// this run's own list first, locked, so no other run's sweep takes it
	$own = test_own_list($root);
	$moved = array();
	$sweep = function ($relative, $limit) use ($root, $tracked, &$moved) {
		if ($relative === null || isset($moved[$relative]) || test_is_tracked($tracked, $relative)) return;
		$path = realpath($root).'/'.$relative;
		if (!file_exists($path) && !is_link($path)) return;
		if ($limit !== null && test_newest($path) > $limit) return;
		if (test_move_aside($root, $relative)) $moved[$relative] = true;
	};
	$alive = false;
	foreach (glob(test_fixtures_dir($root).'/*.list', GLOB_NOESCAPE) ?: array() as $list) {
		if ($own && $list === $own[1]) continue;
		$handle = @fopen($list, 'r+');
		if (!$handle) continue;
		if (flock($handle, LOCK_EX | LOCK_NB)) {
			// each path is written just after it is named, so a little leeway
			$limit = filemtime($list) + 2;
			foreach (array_unique(array_filter(explode("\n", (string)stream_get_contents($handle)))) as $path) $sweep(test_relative($root, $path), $limit);
			unlink($list);
		} else {
			// another run is going in this checkout: its zz* files are not leftovers
			$alive = true;
		}
		fclose($handle);
	}
	if ($alive) return;
	// zz* and _zz* in the folders the tests write to
	foreach ($folders as $folder) {
		foreach (@scandir(realpath($root)."/$folder") ?: array() as $name) {
			foreach ($patterns as $pattern) if (fnmatch($pattern, $name)) $sweep(test_relative($root, realpath($root)."/$folder/$name"), null);
		}
	}
}

// this run's list of fixtures: array(handle, path), made and locked once
// (null when it can't be). A list another run's sweep unlinked between the
// open and the lock is made again.
function test_own_list($root) {
	static $own = false;
	if ($own !== false) return $own;
	$own = null;
	$dir = test_fixtures_dir($root);
	@mkdir($dir, 0775, true);
	$file = "$dir/".getmypid().'.list';
	for ($try = 0; $try < 5; $try++) {
		$handle = @fopen($file, 'c+');
		if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) return $own;
		$stat = @stat($file);
		if ($stat && $stat['ino'] === fstat($handle)['ino']) {
			register_shutdown_function(function () use ($handle, $file) { fclose($handle); @unlink($file); });
			return $own = array($handle, $file);
		}
		fclose($handle);
	}
	return $own;
}

// names a path this run is about to create in the checkout
function test_fixture($path) {
	global $root;
	$own = test_own_list($root);
	if ($own) { fseek($own[0], 0, SEEK_END); fwrite($own[0], test_normal_path($path)."\n"); fflush($own[0]); }
}

// The stamp a browser would post: a form shown $ago seconds ago, signed with
// the key the suites give their servers (RASTER_SECRET=test-secret).
function stamp_for_tests($ago = 60) {
	$time = time() - $ago;
	return $time.'.'.hash_hmac('sha256', 'raster_ts|'.$time, 'test-secret');
}
