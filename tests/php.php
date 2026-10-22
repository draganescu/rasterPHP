<?php
// How the suites start PHP children (php -S servers, bin/raster, MCP
// sessions, race workers). Each child would compile the whole framework
// again; with opcache's file cache they read the compiled code from one
// directory per run instead.
//
// Timestamps are still checked on every file (validate_timestamps=1,
// revalidate_freq=0), and opcache never caches a file changed in the last two
// seconds (file_update_protection=2), so a test that writes a PHP file and
// runs it sees the new code. Known gap: a test that restores an OLD
// modification time on a PHP file it changed (touch with a past time) can get
// stale code, because opcache only compares times for equality.
// RASTER_TEST_OPCACHE=off runs the children as they were.

// the -d options for a child PHP: an array, empty when opcache is not there
function php_flags() {
	static $flags = null, $lock = null;
	if ($flags !== null) return $flags;
	$flags = array();
	if (getenv('RASTER_TEST_OPCACHE') === 'off' || !extension_loaded('Zend OPcache')) return $flags;
	$base = sys_get_temp_dir();
	// caches of runs that ended or were killed: the ones nobody holds the lock of
	foreach (glob("$base/raster-opcache-*", GLOB_NOESCAPE) ?: array() as $old) {
		$handle = @fopen("$old/.lock", 'c');
		if (!$handle) { if (is_dir($old) && filemtime($old) < time() - 60) exec('rm -rf '.escapeshellarg($old)); continue; }
		if (flock($handle, LOCK_EX | LOCK_NB)) { exec('rm -rf '.escapeshellarg($old)); }
		fclose($handle);
	}
	// a name nobody could have made first, made here or not used at all: the
	// children run the compiled code they find in it, so a folder another
	// user made in the shared temp folder would run their code as ours
	$dir = "$base/raster-opcache-".bin2hex(random_bytes(16));
	if (!@mkdir($dir, 0700)) return $flags;
	$lock = fopen("$dir/.lock", 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $flags;
	foreach (array('opcache.enable=1', 'opcache.enable_cli=1', "opcache.file_cache=$dir", 'opcache.file_cache_only=1', 'opcache.validate_timestamps=1', 'opcache.revalidate_freq=0', 'opcache.file_update_protection=2') as $option) {
		array_push($flags, '-d', $option);
	}
	register_shutdown_function(function () use ($dir, &$lock) { exec('rm -rf '.escapeshellarg($dir)); fclose($lock); });
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

// ## Stopping what a run started
//
// PHP skips shutdown functions when a signal ends it, which left the
// servers running after a run stopped with Ctrl-C, kill or a timeout: exit
// instead, so each suite's shutdown function stops them. A run killed
// outright runs nothing, so tests/watch.php, started here, stops them then.
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGTERM, SIGHUP) as $signal) pcntl_signal($signal, function ($signal) { exit(128 + $signal); });
}
$test_watch = proc_open(array(PHP_BINARY, __DIR__.'/watch.php', (string)getmypid()), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $test_watch_pipes);
