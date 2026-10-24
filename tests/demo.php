<?php
// The demo café test suite: php tests/demo.php
//
// Drives the demo app (demo/) over HTTP, the command line, MCP and a fake
// SMTP server. Every feature has an ID in demo/README.md; the run fails
// when an ID there has no passing test here. On MySQL:
//   RASTER_DB=mysql://root@127.0.0.1:3306/raster php tests/demo.php

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
require __DIR__.'/db.php';
// what a killed run left in the checkout (see test_sweep() in db.php). The
// test that turns the login page off by renaming it; a run killed inside it
// leaves login.html under another name.
$off = "$root/demo/views/cafe/login.html.off";
$git_files = test_tracked($root);
if (is_file($off) && $git_files !== null && !test_is_tracked($git_files, 'demo/views/cafe/login.html.off')) {
	if (is_file("$root/demo/views/cafe/login.html")) test_move_aside($root, 'demo/views/cafe/login.html.off');
	else { rename($off, "$root/demo/views/cafe/login.html"); echo "put back demo/views/cafe/login.html\n"; }
}
test_sweep($root, array('zz*', '_zz*'), array('demo/views/cafe', 'demo/views', 'demo/models', 'media', ''));
$tmp = sys_get_temp_dir().'/raster-demo-'.getmypid();
@mkdir($tmp, 0775, true);
$db = test_db("$tmp/cafe.sqlite");
$maildir = "$tmp/mail";
putenv('RASTER_APP=demo');
putenv("RASTER_DB=$db");
putenv("RASTER_MAIL=log://$maildir");
putenv('RASTER_ENV');
putenv('RASTER_SECRET=test-secret');

require_once $root.'/system/boot.php';
boot::$appname = 'demo';
boot::cli();
require_once BASE.'tools/inspector.php';
require_once BASE.'tools/schema.php';

// ## Harness

$passed = 0; $failed = array(); $skipped = array(); $covered = array();
function test($ids, $name, $fn) {
	global $passed, $failed, $skipped, $covered, $servers;
	// RASTER_TEST_ONLY=Q runs the tests with an ID starting with Q, or a name with those words
	$only = getenv('RASTER_TEST_ONLY');
	if ($only) {
		// a letter and digits is an ID (Q, Q1), anything else words of the name
		$match = !preg_match('/^[A-Z]\d*$/', $only) && stripos($name, $only) !== false;
		foreach ((array)$ids as $id) if (stripos($id, $only) === 0) $match = true;
		if (!$match) return;
	}
	// servers a test starts for itself stop when it is done; the suite's own
	// (started before the first test) run to the end
	$running = array_keys($servers);
	try {
		$fn();
		$passed++;
		foreach ((array)$ids as $id) $covered[$id] = true;
		echo ".";
	} catch (test_skipped $e) {
		$skipped[] = implode(',', (array)$ids)." $name: ".$e->getMessage();
		foreach ((array)$ids as $id) $covered[$id] = true;
		echo "s";
	} catch (Throwable $e) {
		$failed[] = implode(',', (array)$ids)." $name: ".$e->getMessage().' (line '.$e->getLine().')';
		echo "F";
	}
	foreach (array_diff_key($servers, array_flip($running)) as $key => $process) {
		stop_server($key, $process);
		unset($servers[$key]);
	}
}
function check($condition, $message = 'assertion failed') { if (!$condition) throw new Exception($message); }
function same($expected, $actual, $message = '') {
	if ($expected !== $actual) throw new Exception(trim($message.' expected '.var_export($expected, true).', got '.var_export($actual, true)));
}
function has($haystack, $needle, $message = '') {
	if (strpos((string)$haystack, $needle) === false) throw new Exception(trim($message.' missing: '.$needle));
}
function lacks($haystack, $needle, $message = '') {
	if (strpos((string)$haystack, $needle) !== false) throw new Exception(trim($message.' unexpected: '.$needle));
}

$servers = array();
// stops a server (or any process) a test started. With PHP_CLI_SERVER_WORKERS
// the workers outlive the terminated parent, so its children go first: by
// process, not by port, so another run's server on a port is never touched.
function stop_server($key, $process) {
	if (!is_resource($process)) return;
	$status = proc_get_status($process);
	if ($status['running']) exec('pkill -TERM -P '.(int)$status['pid'].' 2>/dev/null');
	proc_terminate($process);
	proc_close($process);
}
// a port nobody is listening on
function free_port() {
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	return (int)substr($name, strrpos($name, ':') + 1);
}
function server($port, $env) {
	global $root, $servers;
	$env = array_merge(array('PATH' => getenv('PATH'), 'RASTER_APP' => 'demo', 'RASTER_SECRET' => 'test-secret', 'CAFE_SPAM_LIMITS' => 'off'), $env);
	global $tmp;
	$process = proc_open(php_argv('-d', 'error_reporting=-1', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', "error_log=$tmp/php-errors.log", '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	// a server that is not running did not start (the port may be another run's)
	if (!proc_get_status($process)['running']) {
		proc_close($process);
		throw new Exception("the server on port $port did not start");
	}
	$servers[$port] = $process;
	return "http://127.0.0.1:$port";
}
// tests/fake_smtp.php on 127.0.0.1:$port, stopped with the servers; the
// first connection when it answers, or false at once when it stopped (it
// couldn't listen)
function fake_smtp($port, $log, $mode, $cert = '') {
	global $root, $servers;
	$process = $servers['smtp-'.count($servers).'-'.$port] = proc_open(php_argv("$root/tests/fake_smtp.php", (string)$port, $log, $mode, $cert), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	for ($up = false, $i = 0; $i < 100 && !($up = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)); $i++) {
		if (!proc_get_status($process)['running']) return false;
		usleep(50000);
	}
	return $up;
}
// files the run puts in media/ (an upload is named by the server), deleted
// when it ends and nothing else there; a run killed outright leaves them to
// the next run's test_sweep()
$media = array();
function media_made($url) {
	global $root, $media;
	$file = "$root/media/".basename($url);
	test_fixture($file);
	$media[] = $file;
}
register_shutdown_function(function () use ($tmp, $root) {
	global $servers, $media;
	foreach ($servers as $key => $process) stop_server($key, $process);
	exec('rm -rf '.escapeshellarg($tmp).' '.escapeshellarg("$root/demo/data/cache").' '.escapeshellarg("$root/demo/data/mail").' '.escapeshellarg("$root/demo/data/changes.log"));
	foreach ($media as $file) @unlink($file);
});

function http($method, $url, $body = null, $headers = array()) {
	if (is_array($body)) {
		// as a browser posts: the form's own stamp, shown a minute ago
		if (isset($body['raster_form']) && !isset($body['raster_ts'])) $body['raster_ts'] = stamp_for_tests(60);
		$body = http_build_query($body);
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
	}
	$context = stream_context_create(array('http' => array(
		'method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
		'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 20,
	)));
	$content = @file_get_contents($url, false, $context);
	preg_match('/\d{3}/', $http_response_header[0], $m);
	return array((int)$m[0], (string)$content, $http_response_header);
}
function header_value($headers, $name) {
	foreach ($headers as $h) if (stripos($h, $name.':') === 0) return trim(substr($h, strlen($name) + 1));
	return null;
}
function cookie_from($headers) {
	$cookies = array();
	foreach ($headers as $h) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) && $m[2] !== 'deleted' && $m[2] !== '') $cookies[$m[1]] = $m[1].'='.$m[2];
	return implode('; ', $cookies);
}
function token_in($html) {
	return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/"csrf":"([a-f0-9]+)"/', $html, $m) ? $m[1] : null);
}
function login($base, $login, $password) {
	// the suite logs in from one address far more often than login_per_ip
	// lets a person in an hour; 'login per address' tests the limit itself
	login_clear();
	list($status, , $headers) = http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => $login, 'password' => $password));
	same(303, $status, "login $login");
	return cookie_from($headers);
}
function login_clear() {
	cms_store::connect();
	if (in_array('formhit', R::inspect())) R::exec("DELETE FROM formhit WHERE form = 'authentication.login'");
}
function raster($args, $env = array()) {
	global $root;
	$env = array_merge(getenv(), $env);
	$process = proc_open(array_merge(php_argv("$root/bin/raster"), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, $env);
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	return array(proc_close($process), $out);
}
// runs a PHP script of the site (demo/seed.php) with the suite's database
function raster_php($script) {
	return raster_php_env($script, array());
}
function raster_php_env($script, $env) {
	global $root;
	$process = proc_open(php_argv($script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array_merge(getenv(), $env));
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	return array(proc_close($process), $out);
}
function mails() {
	global $maildir;
	$files = glob("$maildir/*.eml") ?: array();
	sort($files);
	$mails = array();
	foreach ($files as $file) {
		$raw = file_get_contents($file);
		$part = function ($type) use ($raw) {
			return preg_match('/Content-Type: '.preg_quote($type, '/').'; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $raw, $m) ? base64_decode($m[1]) : '';
		};
		// headers may be folded over several lines, in encoded words
		$head = preg_replace('/\r\n[ \t]+/', ' ', substr($raw, 0, (int)strpos($raw, "\r\n\r\n")));
		preg_match('/^Subject: (.*)$/m', $head, $s);
		preg_match('/^To: (.*)$/m', $head, $t);
		$mails[] = array('raw' => $raw, 'to' => trim($t[1]), 'subject' => isset($s[1]) ? mb_decode_mimeheader(trim($s[1])) : '', 'text' => $part('text/plain'), 'html' => $part('text/html'));
	}
	return $mails;
}
function last_mail() { $m = mails(); return $m ? end($m) : null; }
function mcp($base, $name, $arguments = array()) {
	list($status, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $arguments))), array('Authorization: Bearer demo-token', 'Content-Type: application/json'));
	same(200, $status, "mcp $name");
	$result = json_decode($body, true)['result'];
	if (!empty($result['isError'])) throw new Exception("mcp $name: ".$result['content'][0]['text']);
	return $result['structuredContent'];
}
// MCP over stdio, where an agent has the files: one process, several calls
function mcp_stdio($calls) {
	global $root;
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, getenv());
	fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array()))."\n");
	foreach ($calls as $i => $call) {
		fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 100 + $i, 'method' => 'tools/call', 'params' => array('name' => $call[0], 'arguments' => isset($call[1]) ? $call[1] : array())))."\n");
	}
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	proc_close($process);
	$answers = array();
	foreach (array_filter(explode("\n", $out)) as $line) {
		$message = json_decode($line, true);
		if (!isset($message['id']) || $message['id'] < 100) continue;
		$result = $message['result'];
		$answers[$message['id'] - 100] = !empty($result['isError'])
			? array('error' => $result['content'][0]['text'])
			: $result['structuredContent'];
	}
	if ($err !== '') $answers['stderr'] = $err;
	return $answers;
}
// MCP over stdio one call at a time, so a test can change the site between
// calls of one session, as an agent does. A call with no answer in time
// fails instead of hanging the run.
class mcp_session {
	public $process, $pipes, $id = 100, $errors;
	function __construct($env = array()) {
		global $root, $tmp;
		$this->errors = "$tmp/mcp-session-".uniqid().'.log';
		$this->process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $this->errors, 'w')), $this->pipes, $root, array_merge(getenv(), $env));
		stream_set_blocking($this->pipes[1], false);
	}
	// one line out, one line back (null when there is none in time)
	function line($line, $seconds = 30) {
		fwrite($this->pipes[0], $line."\n");
		fflush($this->pipes[0]);
		$answer = '';
		$until = microtime(true) + $seconds;
		while (substr($answer, -1) !== "\n" && microtime(true) < $until) {
			$read = array($this->pipes[1]); $none = null;
			if (stream_select($read, $none, $none, 0, 200000)) {
				$chunk = fread($this->pipes[1], 65536);
				if ($chunk === '' || $chunk === false) { if (feof($this->pipes[1])) break; continue; }
				$answer .= $chunk;
			}
		}
		return substr($answer, -1) === "\n" ? json_decode($answer, true) : null;
	}
	function call($name, $arguments = array(), $seconds = 30) {
		$message = $this->line(json_encode(array('jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $arguments ?: new stdClass()))), $seconds);
		if ($message === null) throw new Exception("mcp $name: no answer in {$seconds}s");
		$result = $message['result'];
		return !empty($result['isError']) ? array('error' => $result['content'][0]['text']) : $result['structuredContent'];
	}
	function close() {
		if (!is_resource($this->process)) return;
		@fclose($this->pipes[0]);
		if (proc_get_status($this->process)['running']) proc_terminate($this->process, 9);
		@fclose($this->pipes[1]);
		proc_close($this->process);
		@unlink($this->errors);
	}
}
function between($html, $id) {
	return preg_match('#<section id="'.$id.'">(.*?)</section>#s', $html, $m) ? $m[1] : '';
}
function has_message($problems, $needle) {
	foreach ($problems as $p) if (strpos($p['message'], $needle) !== false) return true;
	return false;
}
function php_parses($code) {
	$file = tempnam(sys_get_temp_dir(), 'raster-php');
	file_put_contents($file, $code);
	exec(php_cmd().' -l '.escapeshellarg($file).' 2>&1', $out, $status);
	unlink($file);
	return $status === 0;
}
function with_file($path, $content, $fn) {
	test_fixture($path);
	$existed = file_exists($path);
	$original = $existed ? file_get_contents($path) : null;
	file_put_contents($path, $content);
	try { return $fn(); } finally { if ($existed) file_put_contents($path, $original); else unlink($path); }
}

$port = free_port();
$base = server($port, array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token'));
$views = "$root/demo/views/cafe";
// the spam tests: the limits stay on (every other server has CAFE_SPAM_LIMITS=off, see server())
$spam_base = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_SPAM_LIMITS' => 'on'));

// what the café takes bookings for: the Dining room's sittings, the salon's
// stylists and services (demo/seed.php, as a fresh demo is set up)
list($seeded_code, $seeded) = raster_php("$root/demo/seed.php");
if ($seeded_code !== 0) exit("demo/seed.php failed: $seeded\n");
cms_records::forget();
booking::forget();

// The date of the café's coming $weekday (tue…sun), $weeks weeks on (1 is
// the next one), never a Monday or 25 December, when it is closed. Within
// booking_horizon (60 days) for $weeks up to 8.
function next_open($weekday, $weeks = 1) {
	$date = date('Y-m-d', strtotime("next $weekday +".($weeks - 1).' weeks'));
	while (substr($date, 5) === '12-25') $date = date('Y-m-d', strtotime("$date +7 days"));
	return $date;
}
// the same weekday $weeks weeks back, for bookings staff made in the past
function past_open($weekday, $weeks = 1) {
	$date = date('Y-m-d', strtotime("last $weekday -".($weeks - 1).' weeks'));
	while (substr($date, 5) === '12-25') $date = date('Y-m-d', strtotime("$date -7 days"));
	return $date;
}
// posts the booking form on /visit for that day (the page shows its times)
function book($base, $fields, $headers = array()) {
	$fields += array('raster_form' => 'booking.form', 'resource' => 'dining-room', 'name' => 'Guest', 'email' => 'guest@example.com', 'time' => '19:00', 'people' => '2', 'terms' => '1');
	return http('POST', "$base/visit?date=".rawurlencode((string)$fields['date']), $fields, $headers);
}
// a booking as staff make one in code: the Dining room's 19:00 unless said
function staff_booking($values) {
	return cms_records::create('booking', $values + array('resource' => 'dining-room', 'time' => '19:00', 'people' => 1));
}

// ## A. Requests and routing

test(array('A1', 'E1', 'E3'), 'home page with page fields and seeded collections', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/");
	same(200, $status);
	has($body, '<h1>Good coffee, slow mornings.</h1>');
	has($body, 'Flat white');
	has($body, 'Jazz night');
	lacks($body, 'Mock-up dish');
	lacks($body, '<!-- print.');
	lacks($body, '<!-- render.');
});
test(array('A2', 'A3', 'A4'), 'pages by file name, nested views, /index', function () use ($base) {
	has(http('GET', "$base/about")[1], '<h1>About us</h1>');
	has(http('GET', "$base/docs/setup")[1], 'This page lives at <code>docs/setup.html</code>');
	has(http('GET', "$base/index")[1], 'Good coffee, slow mornings.');
});
test(array('A5', 'A6'), 'routes file and URL keys', function () use ($base) {
	has(http('GET', "$base/specials")[1], '<h1>Menu</h1>');
	same(404, http('GET', "$base/my-specials")[0], 'routes are anchored');
	// a routed URL with an extension its view has no file for is a 404 page,
	// in HTML and in the site's theme (#120)
	foreach (array('/specials.json', '/print/menu.txt', '/about.json') as $url) {
		list($status, $body, $headers) = http('GET', $base.$url);
		same(404, $status, $url);
		has(header_value($headers, 'Content-Type'), 'text/html', $url);
		has($body, '/demo/views/cafe/', "$url: the 404 page with its base");
	}
	has(header_value(http('GET', "$base/journal/journal_page/2.rss")[2], 'Content-Type'), 'application/rss+xml', 'page 2 of the feed is journal.rss');
	has(between(http('GET', "$base/lab/color/red")[1], 'param'), '<p class="color">red</p>');
	// a key from the URL is text, in the page and in its script
	$lab = http('GET', "$base/lab/color/%22%3Balert(1)%3B%3Cimg%20src%3Dx%3E")[1];
	has($lab, '<p class="color">&quot;;alert(1);&lt;img src=x&gt;</p>');
	has($lab, 'var labColor = "&quot;;alert(1);&lt;img src=x&gt;";');
	lacks($lab, '<img src=x>');
});
test(array('A18', 'M4'), 'a route\'s parameter URLs show the route\'s page fields (#110)', function () use ($base) {
	mcp($base, 'update_page', array('page' => '/specials', 'fields' => array('heading' => 'Today only')));
	try {
		has(http('GET', "$base/specials")[1], '<h1>Today only</h1>');
		foreach (array('/specials/day/friday', '/specials/day/saturday', '/specials/menu_page/2') as $url) {
			has(http('GET', $base.$url)[1], '<h1>Today only</h1>', $url);
		}
		// development made no table for those URLs, so schema has none to drop
		list($code, $out) = raster(array('schema', '--json'));
		same(0, $code, $out);
		same(array(), array_values(preg_grep('/^specials/', json_decode($out, true)['unused_tables'])), $out);
		// MCP knows the page by any URL of the route
		same('Today only', mcp($base, 'get_page', array('page' => '/specials/day/friday'))['fields']['heading']);
		mcp($base, 'update_page', array('page' => '/specials/day/sunday', 'fields' => array('heading' => 'Sunday too')));
		has(http('GET', "$base/specials")[1], '<h1>Sunday too</h1>');
		// the menu's own page is still its own
		has(http('GET', "$base/menu")[1], '<h1>Menu</h1>');
	} finally {
		mcp($base, 'update_page', array('page' => '/specials', 'fields' => array('heading' => 'Menu')));
	}
});
test(array('A7', 'A8'), 'unknown pages and partials are 404', function () use ($base) {
	same(404, http('GET', "$base/nothing-here")[0]);
	same(404, http('GET', "$base/_layout")[0]);
	same(404, http('GET', "$base/_email/booking_new")[0]);
});
test(array('A9', 'B6', 'B7'), 'links are rewritten', function () use ($base) {
	$body = http('GET', "$base/about")[1];
	has($body, 'href="'.$base.'/menu"');
	has($body, 'href="'.$base.'/"');
	has($body, 'href="'.$base.'/journal.rss"');
	has($body, 'href="'.$base.'/feed.json"');
	has($body, 'href="'.$base.'/hours.txt"');
	has($body, 'href="'.$base.'/docs/setup"');
});
test(array('A10', 'A11'), 'assets are served, code and raw views are not', function () use ($base) {
	same(200, http('GET', "$base/demo/views/cafe/style.css")[0]);
	has(http('GET', "$base/about")[1], "<base href='$base/demo/views/cafe/' />", 'pages point assets at the theme');
	same(200, http('GET', "$base/demo/views/cafe/img/logo.svg")[0]);
	foreach (array('/demo/views/cafe/index.html', '/demo/views/cafe/journal.rss', '/demo/views/cafe/feed.json', '/demo/config/the_app.php', '/demo/models/cafe/cafe.php', '/demo/i18n/ro/common.php', '/demo/data/x.sqlite', '/demo//data/x.sqlite-journal', '/system/boot.php', '/bin/raster', '/.git/HEAD', '/AGENTS.md', '/docs/rto-spec.md', '/tests/demo.php') as $path) {
		same(403, http('GET', $base.$path)[0], $path);
	}
});
test('A16', 'the private extensions, composer.phar included, are never served', function () use ($base, $root) {
	// the rules are in system/private_paths.php; index.php, .htaccess and the
	// configs `raster deploy` prints all come from there
	same(403, http('GET', "$base/composer.phar")[0], 'a phar in the site folder');
	foreach (array('/x.lock', '/x.ini', '/x.bak', '/demo/views/cafe/style.css.bak') as $path) {
		same(403, http('GET', $base.$path)[0], $path);
	}
	same(200, http('GET', "$base/index.php")[0], 'the entry point is the exception');
	// and the same check without a web server
	require_once "$root/system/private_paths.php";
	$apps = private_paths::apps($root);
	check(in_array('demo', $apps), 'demo is an app folder');
	check(!in_array('system', $apps), 'system is not');
	foreach (array('/composer.phar', '/system/boot.php', '/demo/data/x.sqlite-wal', '/.git/HEAD') as $path) {
		check(private_paths::blocked($path), "$path is private");
	}
	check(!private_paths::blocked('/index.php'), 'index.php is not');
	check(!private_paths::blocked('/demo/views/cafe/style.css'), 'theme assets are not');
});
test('A12', 'query strings do not change the route', function () use ($base) {
	has(http('GET', "$base/about?utm=x")[1], '<h1>About us</h1>');
});

// ## B. Formats

test(array('B1', 'C35'), 'RSS', function () use ($base) {
	http('GET', "$base/journal");
	mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Tea & <cake>', 'summary' => '<p>Cups & saucers</p>', 'author' => 'Bogdan', 'body' => '<p>Long</p>')));
	list($status, $body, $headers) = http('GET', "$base/journal.rss");
	same(200, $status);
	has(header_value($headers, 'Content-Type'), 'application/rss+xml');
	$doc = new DOMDocument();
	check(@$doc->loadXML($body), 'RSS is not well-formed');
	has($body, 'Tea &amp; &lt;cake&gt;');
	same(2, $doc->getElementsByTagName('item')->length);
	check(preg_match('/<pubDate>[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}<\/pubDate>/', $body), 'RFC 822 dates');
	has($body, "<link>$base/</link>", 'print.feed.site_url');
	has($body, '<generator>Raster Café feeds</generator>', 'the_feed override');
});
test('B2', 'Atom', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/journal.atom");
	same(200, $status);
	has(header_value($headers, 'Content-Type'), 'application/xml');
	$doc = new DOMDocument();
	check(@$doc->loadXML($body), 'Atom is not well-formed');
	same(2, $doc->getElementsByTagName('entry')->length);
});
test('B3', 'JSON feed', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/feed.json");
	has(header_value($headers, 'Content-Type'), 'application/json');
	$feed = json_decode($body, true);
	check(is_array($feed), "invalid JSON: $body");
	same(2, count($feed['items']));
	same('Tea & <cake>', $feed['items'][0]['title']);
	check(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $feed['items'][0]['date_published']), 'ISO dates');
});
test(array('B4', 'O1'), 'sitemap', function () use ($base) {
	list(, $body) = http('GET', "$base/sitemap.xml");
	$doc = new DOMDocument();
	check(@$doc->loadXML($body), 'sitemap is not well-formed');
	has($body, "<loc>$base/about</loc>");
	has($body, "<loc>$base/docs/setup</loc>");
	has($body, "<loc>$base/journal/journal_item/opening-day</loc>");
	foreach (array('/login', '/members', '/staff', '/account') as $private) lacks($body, "<loc>$base$private</loc>");
	// the account and newsletter pages are left out where the settings put
	// them (the newsletter's under /letters), though sitemap_skip doesn't name them
	foreach (array('/register', '/forgot', '/password/new', '/letters/confirm', '/letters/stop') as $page) lacks($body, "<loc>$base$page</loc>");
	foreach (array('login', 'register', 'forgot', 'password/new', 'letters/confirm', 'letters/stop') as $page) check(!in_array($page, config::get('sitemap_skip'), true), "sitemap_skip still names $page");
	// sitemap_skip adds to them
	lacks($body, "<loc>$base/lab</loc>");
});
test('B5', 'plain text', function () use ($base) {
	list(, $body, $headers) = http('GET', "$base/hours.txt");
	has(header_value($headers, 'Content-Type'), 'text/plain');
	has($body, "Monday: closed\nTuesday to Friday: 8:00 to 18:00\nWeekend: 9:00 to 16:00");
});

// ## C. The engine (lab.html)

$lab = null;
test(array('C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'), 'print, render, empty, false, remove, dry', function () use ($base, &$lab) {
	$lab = http('GET', "$base/lab")[1];
	has($lab, '<h1>Lab: every engine feature on one page</h1>');
	has(between($lab, 'escaping'), '<em>house blend</em>');
	has(between($lab, 'nested'), '<h3>Coffee</h3>');
	lacks(between($lab, 'empty'), 'You should not see this');
	has(between($lab, 'empty'), 'This mock-up stays because the model returned false.');
	has(between($lab, 'empty'), 'Default text stays too.');
	lacks($lab, 'mockup');
	list(, , $headers) = http('GET', "$base/lab");
	same(null, header_value($headers, 'X-Side-Effect'), 'models inside remove never run');
	has($lab, 'class="site-header"');
	has($lab, 'class="site-footer"');
});
test(array('C8', 'C9', 'C18'), 'print.if, print.self, nested model calls in every copy', function () use (&$lab) {
	has(between($lab, 'self-if'), 'Hello from the café');
	has(between($lab, 'self-if'), 'Specials today.');
	lacks(between($lab, 'self-if'), 'Sold out.');
	same(3, substr_count(between($lab, 'attributes'), '<em class="copy">Hello from the café</em>'));
});
test(array('C11', 'C12', 'C13'), 'attributes, nested rows, repeated tags', function () use (&$lab) {
	has(between($lab, 'attributes'), '<span class="day closed">Monday</span>');
	has(between($lab, 'attributes'), '<span class="day open">Weekend</span>');
	has(between($lab, 'nested'), '<ul><li>Espresso</li><li>Flat white</li></ul>');
	has(between($lab, 'nested'), '<ul><li>Carrot cake</li></ul>');
	has(between($lab, 'repeated'), 'https://example.com/a and again https://example.com/a');
	has(between($lab, 'repeated'), 'https://example.com/b and again https://example.com/b');
});
test('C45', 'a short closing tag is ignored by the engine, and lint says how to write it', function () use ($views) {
	with_file("$views/zz-short.html", "<!-- render.cms.journal('limit=1') --><!-- print.title -->t<!-- /print.title --><!-- /render -->", function () {
		list($code, $out) = raster(array('lint'));
		same(1, $code, 'an error');
		has($out, "write <!-- /render.cms.journal('limit=1') -->");
	});
});
test(array('C14', 'C15', 'C16', 'C17'), 'arguments, escaping, replace, memory', function () use ($base, &$lab) {
	has(between($lab, 'args'), '[3,"soup",true,-1,null]');
	has(between($lab, 'escaping'), '&lt;script&gt;alert(&quot;no&quot;)&lt;/script&gt;');
	has(between($lab, 'replace'), 'Brewed at Raster Café.');
	has(http('GET', "$base/docs/setup")[1], 'Only the lab replaces {{cafe}}.', 'replace is scoped to /lab');
	has(between($lab, 'memory'), '<p class="recalled">remembered</p>');
	lacks(between($lab, 'memory'), '<span>remembered</span>');
});
test(array('C20', 'C21'), 'SQL files and bound queries', function () use ($base, &$lab) {
	has(between($lab, 'database'), "<p class=\"bound\">it's bound</p>");
	has(http('GET', "$base/menu")[1], 'Coffee: 1');
});
test('C22', 'application events', function () use ($base) {
	same('served', header_value(http('GET', "$base/about")[2], 'X-Cafe'));
});
test('C24', 'the JSON api', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/api/cafe/hours");
	same(200, $status);
	has(header_value($headers, 'Content-Type'), 'application/json');
	same('Monday', json_decode($body, true)[0]['day']);
	same('"1"', http('GET', "$base/api/cafe/category_count/coffee")[1], 'URL segments become arguments');
	same(404, http('GET', "$base/api/pagination/pages/cms.menu")[0], 'system models are private');
	same(404, http('GET', "$base/api/mcp/handle")[0]);
	same(404, http('GET', "$base/api/cafe/nope")[0]);
	same(404, http('GET', "$base/api/cafe/listens")[0], 'static methods are private');
	same(404, http('GET', "$base/api/booking/types")[0], 'a bundled model with no api() offers nothing');
});
test('C25', 'broken templates stop with a list of errors in development', function () use ($base, $views) {
	with_file("$views/zz-broken.html", "<html><body><!-- print.cms.title -->x\n<!-- print.cafe.nope /--></body></html>", function () use ($base) {
		list($status, $body, $headers) = http('GET', "$base/zz-broken");
		same(500, $status);
		same('2', header_value($headers, 'X-Raster-Template-Errors'));
		has($body, 'is never closed');
		has(html_entity_decode($body, ENT_QUOTES), "Model 'cafe' has no public method 'nope'");
	});
	with_file("$views/_zz_part.html", "<!-- res.bit --><!--print.cafe.color--><!-- /res.bit -->", function () use ($base, $views) {
		with_file("$views/zz-uses-part.html", "<html><body><!-- dry._zz_part.bit /--></body></html>", function () use ($base) {
			same(500, http('GET', "$base/zz-uses-part")[0], 'errors in partials count');
		});
	});
});

// ## D. Forms and validation

test(array('D1', 'D18', 'D19'), 'hidden fields on every post form', function () use ($base) {
	$body = http('GET', "$base/visit")[1];
	foreach (array('booking.form', 'contact.form', 'newsletter.signup') as $owner) has($body, 'name="raster_form" value="'.$owner.'"');
	same(3, substr_count($body, 'name="raster_hp"'));
	lacks($body, 'name="csrf"', 'no session, no token');
	lacks($body, 'class="error"');
	lacks($body, 'Thank you, we have your request');
});
test('D3', 'posts from other sites are refused', function () use ($base) {
	same(403, http('POST', "$base/visit", array('raster_form' => 'contact.form'), array('Origin: https://evil.example'))[0]);
	same(403, http('POST', "$base/visit", array('raster_form' => 'contact.form'), array('Sec-Fetch-Site: cross-site'))[0]);
	same(403, http('POST', "$base/visit", array('raster_form' => 'contact.form'), array('Referer: https://evil.example/page'))[0]);
	same(403, http('POST', "$base/api/cafe/hours", array('x' => 1), array('Origin: https://evil.example'))[0]);
	$host = parse_url($base, PHP_URL_HOST).':'.parse_url($base, PHP_URL_PORT);
	same(200, http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => ''), array("Origin: http://$host", 'Sec-Fetch-Site: same-origin'))[0], 'browsers posting from the site are welcome');
});
test('D4', 'bots that fill the honeypot get a fake success', function () use ($base) {
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'raster_hp' => 'buy now', 'email' => 'bot@spam.test', 'message' => 'spam'));
	same(303, $status);
	same($before, count(mails()), 'nothing sent');
});
$bad_booking = array('raster_form' => 'booking.form', 'resource' => 'dining-room', 'name' => 'Your name', 'email' => 'nope', 'phone' => 'abc', 'date' => '2031-01-01', 'time' => '21:00', 'people' => '12', 'newsletter' => 'yes', 'notes' => str_repeat('x', 301), 'password' => 'secret');
test(array('D5', 'D6', 'D7', 'D8', 'D9', 'D10', 'D11', 'D12', 'D24'), 'rules from the HTML, and regions', function () use ($base, $bad_booking) {
	list($status, $body) = http('POST', "$base/visit", $bad_booking);
	same(200, $status);
	has($body, 'Please replace the example name with yours.');
	has($body, 'We need a valid email to confirm.');
	has($body, 'Digits and spaces only');
	has($body, 'Pick a date between 2026 and 2030.');
	has($body, 'Tables are for 1 to 8 people.');
	has($body, 'Keep notes under 300 characters.');
	has($body, 'Please agree to the house rules.');
	lacks($body, 'Tell us your name', 'name is valid');
	has($body, 'For groups over 8, please call us.', "field('people', 'max')");
	$day = next_open('tue', 2);
	list(, $body) = book($base, array('name' => 'Ana', 'email' => 'a@b.co', 'date' => $day, 'people' => '0'));
	lacks($body, 'For groups over 8', "field('people', 'max') only for max");
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'booking.form', 'name' => 'A', 'email' => '', 'date' => ''));
	has($body, 'Tell us your name (2 to 80 letters).', 'minlength');
	has($body, 'We need a valid email to confirm.', 'required');
	foreach (array(array('people' => '0'), array('people' => '5 people'), array('date' => '2025-12-31'), array('date' => '31/12/2026')) as $bad) {
		list(, $body) = book($base, array_merge(array('name' => 'Ana', 'email' => 'a@b.co', 'date' => $day), $bad));
		has($body, isset($bad['people']) ? 'Tables are for 1 to 8 people.' : 'Pick a date between 2026 and 2030.', json_encode($bad));
	}
});
test(array('D13', 'C19', 'D25'), 'an application rule (its region runs before the form model); validation::errors()', function () use ($base) {
	$before = count(mails());
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'ana@example.com', 'message' => 'Cheap pills at https://spam.example/now'));
	has($body, 'Please leave out web links: write the address in words.');
	same($before, count(mails()), 'nothing sent');
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'bad', 'website' => 'not a url', 'message' => 'See www.spam.example'));
	has($body, '<p class="error-count">3 problems</p>', 'validation::errors(): the email, the website and the link');
});
test(array('D14', 'D19', 'D23'), 'rule names from older Raster, two forms on one page', function () use ($base) {
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => '', 'message' => 'Hi'));
	has($body, 'Your email, please.');
	lacks($body, 'That email does not look right.', 'email_format passes on empty');
	lacks($body, 'We need a valid email to confirm.', 'the other form stays quiet');
	lacks($body, 'Please enter a valid email address.', 'the footer form stays quiet');
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'bad', 'message' => 'Hi', 'website' => 'not a url'));
	has($body, 'That email does not look right.');
	has($body, 'A web address starts with https://', 'type="url"');
});
test('D16', 'the form is filled again, never with passwords', function () use ($base, $bad_booking) {
	list(, $body) = http('POST', "$base/visit", $bad_booking);
	has($body, 'value="nope"');
	has($body, 'name="newsletter" value="yes" checked');
	has($body, 'name="people" required min="1" max="8" value="12"');
	has($body, '<textarea name="notes" maxlength="300">'.str_repeat('x', 301).'</textarea>');
	lacks($body, 'secret');
	// the day's times are radio buttons: the one chosen comes back chosen
	list(, $body) = book($base, array('email' => 'nope', 'date' => next_open('tue', 2), 'time' => '21:00'));
	has($body, 'name="time" value="21:00" checked');
	check(!preg_match('/value="19:00"[^>]*checked/', $body), 'only the time chosen');
	// a select
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'bad', 'subject' => 'An event', 'message' => 'Hi'));
	has($body, '<option value="An event" selected>');
	check(!preg_match('/value="A table"[^>]*selected/', $body), 'only the option chosen');
});
test(array('D17', 'I1', 'I2', 'C23'), 'a valid booking: stored, emailed, redirected, alert shown', function () use ($base) {
	$day = next_open('tue');
	$before = count(mails());
	list($status, , $headers) = book($base, array('name' => 'Ana <b>Pop</b>', 'email' => 'ana@example.com', 'phone' => '+40 721 000 000', 'date' => $day, 'people' => '3', 'notes' => 'Near the <script>window</script>'));
	same(303, $status);
	same("$base/visit?done=booked", header_value($headers, 'Location'));
	has(http('GET', "$base/visit?done=booked")[1], 'Thank you, we have your request.');
	$sent = array_slice(mails(), $before);
	same(array('ana@example.com', 'staff@cafe.test'), array_column($sent, 'to'), 'the guest, then staff');
	same(array('We have your booking request', 'New booking request'), array_column($sent, 'subject'));
	$mail = $sent[1];
	has($mail['html'], 'Ana &lt;b&gt;Pop&lt;/b&gt;', 'values in emails are escaped');
	has($mail['html'], 'Near the &lt;script&gt;');
	has($mail['text'], "asked for Dining room on $day at 19:00, for 3");
	has($mail['html'], 'href="'.$base.'/staff"', 'links in emails are absolute');
	database::instance('cms');
	same(1, (int)R::count('bookingdata'));
});
test('D20', 'forms filled with data and spa_ classes', function () use ($base) {
	http('POST', "$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'reader@example.com'));
	preg_match('/token=([a-f0-9]{40})/', last_mail()['text'], $t);
	http('GET', "$base/letters/confirm?token={$t[1]}");
	database::instance('cms');
	$token = R::findOne('subscriber', ' email = ? ', array('reader@example.com'))->token;
	$body = http('GET', "$base/letters/stop?token=$token")[1];
	has($body, '<strong class="spa_email">reader@example.com</strong>');
	has($body, 'name="token" value="'.$token.'"');
});
test('D21', '/api never runs form models', function () use ($base) {
	$before = count(mails());
	// the café's own model: a public method its api() doesn't list (a
	// listener that subscribes a guest) is not reachable
	list($status, $body) = http('POST', "$base/api/cafe/subscribe_guest", array('email' => 'x@example.com', 'name' => 'via api'));
	same(404, $status, "a public method of the site's own model that its api() does not list");
	same(404, http('POST', "$base/api/cafe/problem_count")[0], 'nor a method the templates call');
	// bundled models aren't reachable at /api at all
	same(404, http('POST', "$base/api/contact/form", array('email' => 'x@example.com', 'message' => 'via api'))[0], 'nor a bundled form handler');
	same(404, http('POST', "$base/api/booking/form", array('name' => 'x'))[0], 'nor a bundled one');
	// and one a model does list is not a form submission there
	$dir = dirname(__DIR__).'/demo/models/zzform';
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/zzform.php", "<?php\nclass zzform {\n\tstatic function api() { return array('send' => 'visitor'); }\n\tfunction send() { return validation::get()->submitted() ? 'ran' : false; }\n}\n", function () use ($base) {
			list($status, $body) = http('POST', "$base/api/zzform/send", array('email' => 'x@example.com'));
			same(200, $status);
			same('', $body);
		});
	} finally {
		@rmdir($dir);
	}
	same($before, count(mails()));
});

// ## E. The CMS

test(array('E4', 'E5', 'E6'), 'slugs, items by slug and id, missing items', function () use ($base) {
	$item = mcp($base, 'create_item', array('collection' => 'menu', 'fields' => array('name' => 'Crème brûlée', 'description' => 'Burnt sugar', 'price' => '18', 'category' => 'cakes', 'featured' => '1')));
	same('creme-brulee', $item['slug']);
	has(http('GET', "$base/menu/menu_item/creme-brulee")[1], '<h1>Crème brûlée</h1>');
	has(http('GET', "$base/menu/menu_item/{$item['id']}")[1], '<h1>Crème brûlée</h1>');
	same(404, http('GET', "$base/menu/menu_item/does-not-exist")[0]);
	same(404, http('GET', "$base/other/menu_item/creme-brulee")[0], 'only under its own collection');
	has(http('GET', "$base/menu")[1], 'href="'.$base.'/menu/menu_item/creme-brulee"');
});
test(array('E7', 'E9', 'E10', 'E11'), 'filter links, order, limit, template filters', function () use ($base) {
	foreach (array(array('Americano', 'coffee', '0'), array('Brownie', 'cakes', '1'), array('Cortado', 'coffee', '0'), array('Donut', 'cakes', '1'), array('Espresso', 'coffee', '0')) as $d) {
		mcp($base, 'create_item', array('collection' => 'menu', 'fields' => array('name' => $d[0], 'description' => 'Tasty', 'price' => '10', 'category' => $d[1], 'featured' => $d[2])));
	}
	$menu = http('GET', "$base/menu")[1];
	has($menu, 'href="'.$base.'/menu/menu_items/category/cakes"');
	check(strpos($menu, 'Americano') < strpos($menu, 'Brownie'), 'order=name');
	$cakes = http('GET', "$base/menu/menu_items/category/cakes")[1];
	has($cakes, 'Brownie');
	lacks($cakes, 'Americano');
	preg_match('#<h2>Featured</h2>(.*?)</section>#s', http('GET', "$base/")[1], $featured);
	same(3, substr_count($featured[1], 'class="card"'), 'limit=3');
	lacks($featured[1], 'Americano', 'featured=1');
	has($featured[1], 'Brownie');
});
test(array('E8', 'K1', 'E9', 'E24'), 'pagination for collections and for models', function () use ($base) {
	$page1 = http('GET', "$base/menu")[1];
	has($page1, 'page 1 of 2');
	has($page1, '<a class="page disabled" href="'.$base.'/menu">&larr;</a>');
	has($page1, '<a class="page current" href="'.$base.'/menu">1</a>');
	list($status, $page2) = http('GET', "$base/menu/menu_page/2");
	same(200, $status);
	has($page2, 'page 2 of 2');
	has($page2, '<a class="page disabled" href="'.$base.'/menu/menu_page/2">&rarr;</a>');
	has(http('GET', "$base/menu/menu_items/category/cakes")[1], '<h1>Menu</h1>');
	lacks(http('GET', "$base/menu/menu_items/category/cakes")[1], 'menu_page/2', 'filters shrink the pages');
	$ordering = between(http('GET', "$base/lab")[1], 'ordering');
	check(preg_match('#<ol class="priciest">\s*<li>Crème brûlée 18.00</li>\s*<li>Flat white 14.50</li></ol>#', $ordering), "order=-price: $ordering");
	has($ordering, 'Opening day', 'order=oldest');
	lacks($ordering, 'coffee has pages', 'pagination follows the filter argument');
	has($ordering, 'the menu has 2 pages');
	$lab = http('GET', "$base/lab?page=3")[1];
	has(between($lab, 'guestbook'), '<li class="guest">Gabi</li>');
	has(between($lab, 'guestbook'), '<a class="page current" href="'.$base.'/lab?page=3">3</a>');
});
test(array('E12', 'E13'), 'drafts and scheduled items', function () use ($base) {
	mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Secret tasting', 'date' => '2026-11-01', 'summary' => 'x', 'enabled' => '0')));
	mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Future gig', 'date' => '2026-12-01', 'summary' => 'x', 'published_at' => '2099-01-01 10:00')));
	$events = http('GET', "$base/events")[1];
	lacks($events, 'Secret tasting');
	lacks($events, 'Future gig');
	same(404, http('GET', "$base/events/events_item/secret-tasting")[0]);
	lacks(http('GET', "$base/journal.rss")[1], 'Future gig');
});
test(array('E2', 'E14', 'E15', 'M4'), 'site-wide fields, revisions, empty values', function () use ($base) {
	mcp($base, 'update_page', array('page' => 'site', 'fields' => array('site_name' => 'Café Raster')));
	has(http('GET', "$base/")[1], 'Café Raster</a>');
	has(http('GET', "$base/journal")[1], 'Café Raster</a>');
	mcp($base, 'update_page', array('page' => '/about', 'fields' => array('body' => '<p>We <em>love</em> <a href="https://example.com">coffee</a>.</p>')));
	has(http('GET', "$base/about")[1], '<p>We <em>love</em> <a href="https://example.com">coffee</a>.</p>', 'values can be HTML');
	mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => 'Our story')));
	mcp($base, 'update_page', array('page' => 'about', 'fields' => array('heading' => '')));
	has(http('GET', "$base/about")[1], '<h1>About us</h1>', 'an empty value shows the default');
	$history = mcp($base, 'page_history', array('page' => '/about', 'limit' => 5))['revisions'];
	same('Our story', $history[1]['fields']['heading']);
});
test('E16', 'a new annotation becomes a new column in development', function () use ($base, $views) {
	$original = file_get_contents("$views/about.html");
	with_file("$views/about.html", str_replace('<h2>Opening hours</h2>', '<p class="motto"><!-- print.cms.motto -->Slow is fine.<!-- /print.cms.motto --></p><h2>Opening hours</h2>', $original), function () use ($base) {
		has(http('GET', "$base/about")[1], '<p class="motto">Slow is fine.</p>');
		database::instance('cms');
		check(array_key_exists('motto', R::inspect('aboutpage')), 'column not created');
	});
	raster(array('schema', '--drop=aboutpage.motto'));
});

// ## G. Accounts

test(array('G18', 'G6'), 'no cookies for visitors; protected pages send them to log in', function () use ($base) {
	list(, $home, $headers) = http('GET', "$base/");
	check(cookie_from($headers) === '', 'visitor got a cookie');
	has($home, '<a href="'.$base.'/login">Log in</a>', 'if.logged_out');
	list($status, , $headers) = http('GET', "$base/members");
	same(303, $status);
	same("$base/login?next=%2Fmembers", header_value($headers, 'Location'));
});
test('G6', 'protected pages: dot segments, double slashes and case', function () use ($root, $db, $tmp) {
	// nginx, Apache and Caddy pass the raw path to PHP; this front controller
	// does too, without the checks the router in index.php makes for php -S
	@mkdir("$tmp/front");
	file_put_contents("$tmp/front/index.php", '<?php chdir('.var_export($root, true).'); require "system/boot.php"; boot::$appname = "demo"; boot::up();');
	$port = free_port();
	$front = proc_open(php_argv('-S', "127.0.0.1:$port", "$tmp/front/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, "$tmp/front", array('RASTER_DB' => $db, 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	try {
		$front_base = "http://127.0.0.1:$port";
		same(303, http('GET', "$front_base/staff")[0], '/staff');
		same(303, http('GET', "$front_base//staff")[0], '//staff');
		same(404, http('GET', "$front_base/./staff")[0], '/./staff');
		same(404, http('GET', "$front_base/%2E/staff")[0], '/%2E/staff');
		check(http('GET', "$front_base/staff/.")[0] !== 200, '/staff/.');
		// a disk that ignores case renders members.html for /MEMBERS; elsewhere it is a 404
		check(in_array(http('GET', "$front_base/MEMBERS")[0], array(303, 404)), '/MEMBERS');
	} finally {
		proc_terminate($front); proc_close($front);
	}
});
test(array('G1', 'G2', 'G3', 'G9', 'D15'), 'sign up', function () use ($base) {
	list(, $body) = http('POST', "$base/register", array('raster_form' => 'authentication.register', 'email' => 'maria@example.com', 'password' => 'short', 'password_again' => 'other'));
	has($body, 'Use at least 8 characters.');
	has($body, 'The passwords are different.');
	has(http('POST', "$base/register", array('raster_form' => 'authentication.register', 'email' => 'nine@example.com', 'password' => 'ninechars', 'password_again' => 'ninechars'))[1], 'For this café, passwords need at least 10 characters.', 'password_min_length');
	list($status, , $headers) = http('POST', "$base/register", array('raster_form' => 'authentication.register', 'name' => 'Maria <i>M</i>', 'email' => 'maria@example.com', 'password' => 'long password', 'password_again' => 'long password'));
	same(303, $status);
	same("$base/members?done=registered", header_value($headers, 'Location'));
	$members = http('GET', "$base/members?done=registered", null, array('Cookie: '.cookie_from($headers)))[1];
	has($members, 'Welcome! Your account is ready.');
	has($members, 'Hello <strong>Maria &lt;i&gt;M&lt;/i&gt;</strong>, you are a member.');
	has(http('POST', "$base/register", array('raster_form' => 'authentication.register', 'email' => 'maria@example.com', 'password' => 'another pass', 'password_again' => 'another pass'))[1], 'There is already an account with that email.');
});
test(array('G4', 'G5', 'G8', 'C10', 'D2', 'D22'), 'log in, next, flags, session values, tokens', function () use ($base) {
	has(http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'maria@example.com', 'password' => 'wrong'))[1], 'Wrong email or password.');
	list($status, , $headers) = http('POST', "$base/login?next=%2Fabout", array('raster_form' => 'authentication.login', 'login' => 'maria@example.com', 'password' => 'long password'));
	same("$base/about", header_value($headers, 'Location'));
	list(, , $headers) = http('POST', "$base/login?next=//evil.example", array('raster_form' => 'authentication.login', 'login' => 'maria@example.com', 'password' => 'long password'));
	lacks(header_value($headers, 'Location'), 'evil');
	$cookie = cookie_from($headers);
	$members = http('GET', "$base/members", null, array("Cookie: $cookie"))[1];
	check(preg_match('/Your session number is (\d+)\./', $members, $m) && $m[1] > 0, 'print.session.uid');
	has($members, '<a href="'.$base.'/account">Account</a>');
	has($members, 'Your card is active.', 'if.is_member');
	lacks($members, 'You run this place.', 'if.is_admin');
	lacks($members, '>Staff</a>');
	lacks($members, 'Log in</a>');
	$token = token_in($members);
	check($token !== null, 'logged in forms carry the token');
	same(403, http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'a@b.co', 'message' => 'x'), array("Cookie: $cookie"))[0], 'no token, no post');
	same(303, http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'a@b.co', 'message' => 'x', 'csrf' => $token), array("Cookie: $cookie"))[0]);
});
test(array('G7', 'G16'), 'roles: members, editors, the command line', function () use ($base) {
	list($code, $out) = raster(array('user', 'staff@cafe.test', '--role=editor', '--password=staff password', '--name=Sam'));
	same(0, $code, $out);
	list($code, $out) = raster(array('users'));
	has($out, 'editor   staff@cafe.test');
	has($out, 'member   maria@example.com');
	same(1, raster(array('user', 'x@cafe.test', '--role=boss', '--password=whatever1'))[0]);
	$member = login($base, 'maria@example.com', 'long password');
	same(403, http('GET', "$base/staff", null, array("Cookie: $member"))[0]);
	$staff = login($base, 'staff@cafe.test', 'staff password');
	list($status, $body) = http('GET', "$base/staff", null, array("Cookie: $staff"));
	same(200, $status);
	has($body, 'Ana &lt;b&gt;Pop&lt;/b&gt;', 'staff see bookings, escaped');
	has($body, '>Staff</a>');
});
test(array('G10', 'G11', 'G13'), 'account changes and logging out', function () use ($base) {
	$a = login($base, 'maria@example.com', 'long password');
	$b = login($base, 'maria@example.com', 'long password');
	$page = http('GET', "$base/account", null, array("Cookie: $a"))[1];
	has($page, 'name="email" required value="maria@example.com"');
	$token = token_in($page);
	has(http('POST', "$base/account", array('raster_form' => 'authentication.account', 'name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'newer password', 'current_password' => 'wrong', 'csrf' => $token), array("Cookie: $a"))[1], 'Your current password is not right.');
	list($status, , $headers) = http('POST', "$base/account", array('raster_form' => 'authentication.account', 'name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'newer password', 'current_password' => 'long password', 'csrf' => $token), array("Cookie: $a"));
	same(303, $status);
	$a = cookie_from($headers) ?: $a;
	has(http('GET', "$base/account?done=account_saved", null, array("Cookie: $a"))[1], 'Saved.');
	same(303, http('GET', "$base/members", null, array("Cookie: $b"))[0], 'the other session ended');
	$page = http('GET', "$base/account", null, array("Cookie: $a"))[1];
	list($status, , $headers) = http('POST', "$base/account", array('raster_form' => 'authentication.logout', 'csrf' => token_in($page)), array("Cookie: $a"));
	same(303, $status);
	$expired = null;
	foreach ($headers as $h) if (preg_match('/^Set-Cookie:\s*PHPSESSID=(deleted)?;/i', $h)) $expired = $h;
	same(true, $expired !== null && stripos($expired, 'HttpOnly') !== false && stripos($expired, 'SameSite=Lax') !== false, "the session cookie is expired with its own settings: $expired");
	same(303, http('GET', "$base/members", null, array("Cookie: $a"))[0], 'logged out');
});
test(array('G12'), 'forgot and reset by email', function () use ($base) {
	list($status, , $headers) = http('POST', "$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'maria@example.com'));
	same(303, $status);
	has(http('GET', header_value($headers, 'Location'))[1], 'If there is an account with that email, a reset link is on its way.');
	$mail = last_mail();
	has($mail['text'], "$base/password/new?token=", 'reset_page setting');
	same('Reset your Raster Café password', $mail['subject']);
	has($mail['html'], 'src="'.$base.'/demo/views/cafe/img/logo.svg"', 'relative images become absolute');
	check(preg_match('/token=([a-f0-9]{48})/', $mail['text'], $t), 'reset link');
	same(303, http('POST', "$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'nobody@example.com'))[0], 'same answer for unknown emails');
	has(http('GET', "$base/password/new?token=0000")[1], 'This link has expired or was already used.');
	has(http('POST', "$base/password/new?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'reset password', 'password_again' => 'nope'))[1], 'The passwords are different.');
	list($status, , $headers) = http('POST', "$base/password/new?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'reset password', 'password_again' => 'reset password'));
	same("$base/members?done=password_changed", header_value($headers, 'Location'));
	has(http('GET', "$base/password/new?token={$t[1]}")[1], 'This link has expired or was already used.');
	login($base, 'maria@example.com', 'reset password');
});
test('G14', 'five wrong passwords lock the account', function () use ($base) {
	raster(array('user', 'locked@cafe.test', '--role=member', '--password=right password'));
	for ($i = 0; $i < 5; $i++) http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'locked@cafe.test', 'password' => 'wrong'));
	has(http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'locked@cafe.test', 'password' => 'right password'))[1], 'Wrong email or password.');
	database::instance('cms');
	R::exec("UPDATE user SET failed_at = ? WHERE email = 'locked@cafe.test'", array(date('Y-m-d H:i:s', time() - 16 * 60)));
	same(303, http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'locked@cafe.test', 'password' => 'right password'))[0], 'the lock lifts after 15 minutes');
});
test('G17', 'accounts from older versions', function () use ($root, $db) {
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); authentication::connect();'
		.' $u = R::dispense("user"); $u->username = "oldtimer"; $u->email = ""; $u->password = md5("old password"); $u->role = "admin"; R::store($u);'
		.' echo authentication::check_login("oldtimer", "old password") ? "ok " : "fail "; echo substr(R::findOne("user", " username = ? ", array("oldtimer"))->password, 0, 4);';
	same('ok $2y$', shell_exec(php_cmd().' -r '.escapeshellarg($code).' 2>&1'));
	$legacy = test_db(sys_get_temp_dir().'/raster-legacy-'.getmypid().'.sqlite');
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); database::instance();'
		.' $o = R::dispense("usersdata"); $o->username = "admin"; $o->password = md5("admin pass"); R::store($o);'
		.' authentication::connect(); echo authentication::check_login("admin", "admin pass") ? "migrated" : "no";';
	same('migrated', shell_exec('RASTER_DB='.escapeshellarg($legacy).' '.php_cmd().' -r '.escapeshellarg($code).' 2>&1'));
	@unlink($legacy);
});

// ## E (editors). The in-page editor

function editor_config($html) {
	return preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $html, $m) ? json_decode($m[1], true) : null;
}
function mark_of($config, $kind, $test) {
	foreach ($config['marks'] as $id => $mark) if ($mark['kind'] === $kind && $test($mark)) return array($id, $mark);
	return array(null, null);
}
test(array('E17', 'E20', 'E27'), 'the editor: only for editors, marks where the page shows content', function () use ($base) {
	$member = login($base, 'maria@example.com', 'reset password');
	lacks(http('GET', "$base/about", null, array("Cookie: $member"))[1], 'raster-editor-config', 'members get no editor');
	lacks(http('GET', "$base/about")[1], 'raster:', 'visitors get the plain page');
	same(403, http('POST', "$base/api/cms/editor_save_field", array('type' => 'aboutpage', 'field' => 'heading', 'value' => 'x'), array("Cookie: $member"))[0]);
	same(403, http('POST', "$base/api/cms/editor_save_field", array('type' => 'aboutpage', 'field' => 'heading', 'value' => 'x'))[0]);
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$page = http('GET', "$base/about", null, array("Cookie: $staff"))[1];
	$config = editor_config($page);
	check($config, 'the editor config');
	same(array('type' => 'aboutpage', 'slug' => '/about'), $config['page']);
	same('Sam', $config['user']['name']);
	has($page, '/api/cms/editor_script?v=');
	list($id, $heading) = mark_of($config, 'field', function ($m) { return $m['field'] === 'heading'; });
	same('aboutpage', $heading['type']);
	has($page, "<h1><!--raster:s $id-->About us<!--raster:e $id--></h1>");
	list($id, $photo) = mark_of($config, 'field', function ($m) { return $m['field'] === 'photo'; });
	same('src', $photo['attr']);
	has($page, "<!--raster:a $id--><img class=\"photo wide\" src=\"img/about.jpg?v=");
	list($id, $body) = mark_of($config, 'field', function ($m) { return $m['field'] === 'body'; });
	same(true, $body['rich']);
	// a field in <head> can't be edited in place: the panel offers it
	list($id, $description) = mark_of($config, 'field', function ($m) { return $m['field'] === 'site_description'; });
	same(true, $description['hidden']);
	same('sitepage', $description['type']);
	has($page, '<meta name="description" content="A small café in București: good coffee, cake and quiet corners.">');
	// collections: every item and field, and the mock-up for new items
	$menu = http('GET', "$base/menu", null, array("Cookie: $staff"))[1];
	$config = editor_config($menu);
	list($list_id, $list) = mark_of($config, 'collection', function ($m) { return $m['collection'] === 'menu'; });
	same('img/menu/flat-white.jpg', $list['fields']['photo']);
	has($menu, '<template data-raster-mockup="'.$list_id.'">');
	has($menu, '<!--raster:ma photo src--><img class="photo" src="img/menu/flat-white.jpg?v=');
	has($menu, '<!--raster:m name-->Flat white<!--raster:/m-->');
	list($item_id, $item) = mark_of($config, 'item', function ($m) { return $m['values']['name'] === 'Americano'; });
	check($item, 'an item mark');
	list($field_id) = mark_of($config, 'item_field', function ($m) use ($item_id) { return $m['item'] == $item_id && $m['field'] === 'name'; });
	has($menu, "<!--raster:s $field_id-->Americano<!--raster:e $field_id-->");
	list($attr_id) = mark_of($config, 'item_attr', function ($m) use ($item_id) { return $m['item'] == $item_id && $m['field'] === 'photo'; });
	has($menu, "<!--raster:a $attr_id--><img class=\"photo\"");
	// the script
	list($status, $js, $headers) = http('GET', "$base/api/cms/editor_script");
	same(200, $status);
	has(header_value($headers, 'Content-Type'), 'application/javascript');
	has($js, 'raster-editor-config');
});
test('E34', 'the editor\'s dock moves to the top and back, and remembers where', function () use ($base) {
	// the flick itself is tried in a browser: tests/editor-browser.js
	$js = http('GET', "$base/api/cms/editor_script")[1];
	has($js, "localStorage.getItem('raster-dock')");
	has($js, "addEventListener('wheel'");
	has($js, '.layer.top .dock{');
});
test(array('E30'), 'the editor lists the admin pages protected lets this person open', function () use ($base, $db, $maildir, $views) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$config = editor_config(http('GET', "$base/about", null, array("Cookie: $staff"))[1]);
	same(array(
		array('url' => "$base/staff/setup", 'title' => 'Booking setup', 'current' => false),
		array('url' => "$base/staff/events", 'title' => 'Event replies', 'current' => false),
		array('url' => "$base/staff/bookings", 'title' => 'Every booking', 'current' => false),
		array('url' => "$base/staff", 'title' => 'Staff', 'current' => false),
	), $config['admin'], 'by title; a booking\'s own page (booking_item) is no admin page, nor a reply\'s (rsvp_item)');
	$config = editor_config(http('GET', "$base/staff", null, array("Cookie: $staff"))[1]);
	same(array(false, false, false, true), array_column($config['admin'], 'current'));
	$titles = function ($base, $cookie) {
		return array_column(editor_config(http('GET', "$base/about", null, array("Cookie: $cookie"))[1])['admin'], 'title');
	};
	// a view under a protected prefix is listed by its <title>, mock-up text included
	@mkdir("$views/staff");
	try {
		with_file("$views/staff/rota.html", '<!doctype html><html><head><title><!-- print.cms.rota_title -->Rota<!-- /print.cms.rota_title --> &amp; shifts</title></head><body><p>Rota</p></body></html>', function () use ($base, $db, $maildir, $staff, $titles) {
			same(array('Booking setup', 'Event replies', 'Every booking', 'Rota & shifts', 'Staff'), $titles($base, $staff));
			// a stricter pattern keeps it to admins
			raster(array('user', 'boss@cafe.test', '--role=admin', '--password=boss password'));
			$strict = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_ADMIN_PAGE' => 'staff/rota'));
			same(array('Booking setup', 'Event replies', 'Every booking', 'Staff'), $titles($strict, login($strict, 'staff@cafe.test', 'staff password')), 'editors do not see admin pages');
			same(array('Booking setup', 'Event replies', 'Every booking', 'Rota & shifts', 'Staff'), $titles($strict, login($strict, 'boss@cafe.test', 'boss password')));
			list($code, $out) = raster(array('describe', '--json', '--sections=admin_pages'), array('RASTER_DB' => $db, 'CAFE_ADMIN_PAGE' => 'staff/rota'));
			same(array('admin_pages' => array(
				array('url' => '/staff/setup', 'view' => 'staff/setup', 'title' => 'Booking setup', 'role' => 'editor'),
				array('url' => '/staff/events', 'view' => 'staff/events', 'title' => 'Event replies', 'role' => 'editor'),
				array('url' => '/staff/bookings', 'view' => 'staff/bookings', 'title' => 'Every booking', 'role' => 'editor'),
				array('url' => '/staff/rota', 'view' => 'staff/rota', 'title' => 'Rota & shifts', 'role' => 'admin'),
				array('url' => '/staff', 'view' => 'staff', 'title' => 'Staff', 'role' => 'editor'),
			)), json_decode($out, true));
		});
	} finally { @rmdir("$views/staff"); }
});
test(array('E18', 'E19', 'E28'), 'the editor saves pages and items, keeps revisions and restores them', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$token = token_in(http('GET', "$base/about", null, $h)[1]);
	same(403, http('POST', "$base/api/cms/editor_save_field", array('type' => 'aboutpage', 'field' => 'heading', 'value' => 'No token'), $h)[0], 'the session token is needed');
	$save = function ($method, $fields) use ($base, $h, $token) {
		list($status, $body) = http('POST', "$base/api/cms/$method", array_merge($fields, array('csrf' => $token)), $h);
		return array($status, json_decode($body, true));
	};
	list($status, $saved) = $save('editor_save_field', array('type' => 'aboutpage', 'slug' => '/about', 'field' => 'heading', 'value' => 'Edited in the page'));
	same(200, $status);
	same('Edited in the page', $saved['value']);
	has(http('GET', "$base/about")[1], '<h1>Edited in the page</h1>');
	list($status, $error) = $save('editor_save_field', array('type' => 'aboutpage', 'slug' => '/about', 'field' => 'made_up', 'value' => 'x'));
	same(400, $status);
	has($error['error'], "Unknown field 'made_up'");
	same(400, $save('editor_save_field', array('type' => 'userpage', 'field' => 'password', 'value' => 'x'))[0], 'a page table that does not exist');
	// a table that does exist but is not a page: the name must end in "page"
	list($status, $error) = $save('editor_save_field', array('type' => 'user', 'field' => 'role', 'value' => 'admin'));
	same(400, $status, 'only CMS page tables');
	has($error['error'], 'Unknown page');
	same(400, $save('editor_save_field', array('type' => 'subscriber', 'field' => 'email', 'value' => 'x@y.zz'))[0]);
	list(, $history) = $save('editor_history', array('type' => 'aboutpage', 'slug' => '/about'));
	check(count($history['revisions']) >= 2, 'revisions');
	same('Edited in the page', $history['revisions'][0]['fields']['heading']);
	check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $history['revisions'][0]['updated_at']), 'times with their offset');
	list($status) = $save('editor_restore', array('type' => 'aboutpage', 'slug' => '/about', 'revision' => $history['revisions'][1]['revision']));
	same(200, $status);
	has(http('GET', "$base/about")[1], '<h1>About us</h1>', 'restored');
	$save('editor_save_field', array('type' => 'aboutpage', 'slug' => '/about', 'field' => 'heading', 'value' => 'About us'));
	// site-wide fields, including one that only lives in <head>
	$save('editor_save_field', array('type' => 'sitepage', 'field' => 'site_description', 'value' => 'Coffee & cake'));
	has(http('GET', "$base/visit")[1], '<meta name="description" content="Coffee & cake">', 'HTML views print values as they are');
	$save('editor_save_field', array('type' => 'sitepage', 'field' => 'site_description', 'value' => 'A small café in București: good coffee, cake and quiet corners.'));
	// items: add, change, hide, delete
	list($status, $item) = $save('editor_save_item', array('collection' => 'menu', 'id' => 0, 'fields' => array('name' => 'Zebra cake', 'description' => 'Stripes', 'price' => '12', 'category' => 'cakes')));
	same(200, $status);
	same('zebra-cake', $item['slug']);
	list(, $item) = $save('editor_save_item', array('collection' => 'menu', 'id' => $item['id'], 'fields' => array('name' => 'Zebra torte', 'price' => '13')));
	same('Zebra torte', $item['name']);
	same('Stripes', $item['description'], 'other fields stay');
	$save('editor_save_item', array('collection' => 'menu', 'id' => $item['id'], 'fields' => array('enabled' => '0')));
	same(404, http('GET', "$base/menu/menu_item/{$item['id']}")[0], 'hidden from visitors');
	list(, $error) = $save('editor_save_item', array('collection' => 'menu', 'id' => $item['id'], 'fields' => array('secret' => 'x')));
	has($error['error'], "Unknown field 'secret'");
	list($status, $deleted) = $save('editor_delete_item', array('collection' => 'menu', 'id' => $item['id']));
	same(200, $status);
	same('Zebra torte', $deleted['item']['name'], 'the item comes back so it can be undone');
	same(404, $save('editor_delete_item', array('collection' => 'menu', 'id' => $item['id']))[0]);
	same(400, $save('editor_save_item', array('collection' => 'users', 'id' => 0, 'fields' => array('name' => 'x')))[0]);
});
test('E12', 'editors see drafts', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	has(http('GET', "$base/events", null, array("Cookie: $staff"))[1], 'Secret tasting');
});
test(array('E21', 'E29', 'C44'), 'pictures: upload, page and item photos, empty values keep the mock-up', function () use ($base, $root) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$token = token_in(http('GET', "$base/about", null, $h)[1]);
	$image = imagecreatetruecolor(40, 30);
	imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 60));
	ob_start(); imagepng($image); $png = ob_get_clean();
	$boundary = 'raster'.bin2hex(random_bytes(4));
	$multipart = function ($name, $bytes) use ($boundary, $token) {
		return "--$boundary\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n"
			."--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"$name\"\r\nContent-Type: image/png\r\n\r\n$bytes\r\n--$boundary--\r\n";
	};
	$type = array("Cookie: $staff", "Content-Type: multipart/form-data; boundary=$boundary");
	list($status, $response) = http('POST', "$base/api/cms/editor_upload", $multipart('cake.png', $png), $type);
	same(200, $status, $response);
	$upload = json_decode($response, true);
	if (isset($upload['url'])) media_made($upload['url']);
	same(40, $upload['width']);
	check(preg_match('#/media/\d{8}-[a-f0-9]{12}\.png$#', $upload['url']), 'a new file name');
	check(is_file($root.'/media/'.basename($upload['url'])));
	list($status, $response) = http('POST', "$base/api/cms/editor_upload", $multipart('shell.png', '<?php echo 1;'), $type);
	if (isset(json_decode($response, true)['url'])) media_made(json_decode($response, true)['url']);
	same(400, $status);
	same(array('upload_not_a_picture'), json_decode($response, true)['problems'], 'a named refusal the editor words');
	// a page field in an attribute: <!-- print.@src.cms.photo -->
	http('POST', "$base/api/cms/editor_save_field", array('csrf' => $token, 'type' => 'aboutpage', 'slug' => '/about', 'field' => 'photo', 'value' => $upload['url']), $h);
	has(http('GET', "$base/about")[1], '<img class="photo wide" src="'.$upload['url'].'" alt="The café from across the street">');
	same($upload['url'], mcp($base, 'get_page', array('page' => '/about'))['fields']['photo'], 'MCP knows the field');
	// saved while the site ran under another folder (a builder's preview), then moved
	$file = '/media/'.basename($upload['url']);
	http('POST', "$base/api/cms/editor_save_field", array('csrf' => $token, 'type' => 'aboutpage', 'slug' => '/about', 'field' => 'photo', 'value' => '/preview/abc'.$file), $h);
	has(http('GET', "$base/about")[1], '<img class="photo wide" src="'.$file.'"', 'a picture saved under another folder shows under this one');
	http('POST', "$base/api/cms/editor_save_field", array('csrf' => $token, 'type' => 'aboutpage', 'slug' => '/about', 'field' => 'photo', 'value' => ''), $h);
	has(http('GET', "$base/about")[1], '<img class="photo wide" src="img/about.jpg?v=', 'empty shows the template\'s picture');
	// an item photo
	$item = null;
	foreach (mcp($base, 'list_items', array('collection' => 'menu', 'limit' => 100))['items'] as $row) if ($row['name'] === 'Americano') $item = $row;
	http('POST', "$base/api/cms/editor_save_item", array('csrf' => $token, 'collection' => 'menu', 'id' => $item['id'], 'fields' => array('photo' => $upload['url'])), $h);
	has(http('GET', "$base/menu/menu_item/americano")[1], '<img class="photo wide" src="'.$upload['url'].'"');
	http('POST', "$base/api/cms/editor_save_item", array('csrf' => $token, 'collection' => 'menu', 'id' => $item['id'], 'fields' => array('photo' => '')), $h);
	has(http('GET', "$base/menu/menu_item/americano")[1], '<img class="photo wide" src="img/menu/flat-white.jpg?v=', 'empty keeps the mock-up');
});

// ## H. Newsletter

test(array('H1', 'H2', 'H3', 'H4', 'H5', 'H11'), 'double opt-in', function () use ($base) {
	list($status, , $headers) = http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => 'fan@example.com', 'name' => 'Fan Club'));
	database::instance('cms');
	same('Fan Club', R::findOne('subscriber', ' email = ? ', array('fan@example.com'))->name, 'the optional name is kept');
	same("$base/journal?done=check_email", header_value($headers, 'Location'));
	has(http('GET', "$base/journal?done=check_email")[1], 'Almost there: check your inbox to confirm.');
	$mail = last_mail();
	same('Confirm your Raster Café letters', $mail['subject']);
	has($mail['text'], "$base/letters/confirm?token=", 'newsletter_confirm_page setting');
	preg_match('/token=([a-f0-9]{40})/', $mail['text'], $t);
	$count = count(mails());
	same("$base/journal?done=check_email", header_value(http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => 'reader@example.com'))[2], 'Location'), 'the same answer for subscribers');
	same($count, count(mails()), 'no mail to people already subscribed');
	$confirm = http('GET', "$base/letters/confirm?token={$t[1]}")[1];
	has($confirm, '<h1>You are subscribed</h1>');
	has($confirm, 'Letters go to fan@example.com.');
	has(http('GET', "$base/letters/confirm?token=".str_repeat('a', 40))[1], 'This link is not valid');
	has(http('GET', "$base/")[1], '2 readers get our letters.');
});
test(array('H6', 'H9'), 'sending an issue', function () use ($base) {
	same(2, raster(array('send', '/journal/journal_item/opening-day'), array('RASTER_URL' => ''))[0], 'needs RASTER_URL');
	$before = count(mails());
	list($code, $out) = raster(array('send', '/journal/journal_item/opening-day', '--dryrun'), array('RASTER_URL' => "$base/"));
	same(2, $code, $out);
	has($out, "send doesn't take --dryrun");
	same($before, count(mails()), 'a mistyped --dry-run sent the issue');
	list($code, $out) = raster(array('send', '/journal/journal_item/opening-day', '--dry-run'), array('RASTER_URL' => "$base/"));
	has($out, 'Would send "Journal" to 2 subscriber(s)');
	list($code, $out) = raster(array('send', '/journal/journal_item/opening-day', '--to=test@example.com'), array('RASTER_URL' => "$base/"));
	same(0, $code, $out);
	same('test@example.com', last_mail()['to']);
	$before = count(mails());
	list($code, $out) = raster(array('send', '/journal/journal_item/opening-day', '--subject=Our first letter'), array('RASTER_URL' => "$base/"));
	same(0, $code, $out);
	has($out, 'Sent "Our first letter" to 2 subscriber(s)');
	$sent = array_slice(mails(), $before);
	same(2, count($sent));
	$issue = $sent[0];
	same('Our first letter', $issue['subject']);
	foreach (array('<form', '<script', '<nav', '<base') as $gone) lacks($issue['html'], $gone);
	has($issue['html'], 'href="'.$base.'/journal"');
	check(preg_match('#href="'.preg_quote($base, '#').'/letters/stop\?token=[a-f0-9]{40}"#', $issue['html']), 'per-reader unsubscribe link');
	check($sent[0]['html'] !== $sent[1]['html'], 'each reader has their own link');
	has(raster(array('send', '/journal/journal_item/opening-day'), array('RASTER_URL' => "$base/"))[1], 'was already sent');
	$before = count(mails());
	list($code, $out) = raster(array('send', '/journal/journal_item/opening-day', '--again'), array('RASTER_URL' => "$base/"));
	same(0, $code, $out);
	same($before + 2, count(mails()), '--again sends again');
	has(raster(array('send', '/about', '--dry-run'), array('RASTER_URL' => "$base/"))[1], 'the page has no unsubscribe link');
});
test(array('H7', 'H8'), 'unsubscribing', function () use ($base) {
	$issue = last_mail();
	check(preg_match('/^List-Unsubscribe: <([^>]+)>/m', $issue['raw'], $u), 'List-Unsubscribe');
	has($issue['raw'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click');
	$page = http('GET', $u[1])[1];
	has($page, '<button>Unsubscribe</button>');
	has(http('POST', $u[1], 'List-Unsubscribe=One-Click', array('Content-Type: application/x-www-form-urlencoded'))[1], 'You are unsubscribed');
	has(http('GET', $u[1])[1], 'You are unsubscribed', 'stays unsubscribed');
	has(http('GET', "$base/letters/stop?token=".str_repeat('b', 40))[1], 'This link is not valid');
});
test('H10', 'single opt-in', function () use ($db, $maildir) {
	$single = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_DOUBLE_OPT_IN' => 'off'));
	$before = count(mails());
	same("$single/about?done=subscribed", header_value(http('POST', "$single/about", array('raster_form' => 'newsletter.signup', 'email' => 'quick@example.com'))[2], 'Location'));
	same($before, count(mails()), 'no confirmation email');
});

// ## G15. Registration can be turned off

test('G15', 'registration off', function () use ($db, $maildir) {
	$closed = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_REGISTRATION' => 'off'));
	lacks(http('GET', "$closed/register")[1], 'name="password_again"');
	same(200, http('POST', "$closed/register", array('raster_form' => 'authentication.register', 'email' => 'late@example.com', 'password' => 'long password', 'password_again' => 'long password'))[0]);
});

// ## I. Mail over SMTP

test(array('I3', 'I4', 'I8', 'I9'), 'SMTP: plain on localhost, STARTTLS and smtps elsewhere', function () use ($root, $tmp) {
	// "another host" is 0.0.0.0: mail counts only localhost, 127.0.0.1 and
	// ::1 as this machine, and Linux and macOS alike connect to 0.0.0.0 on
	// 127.0.0.1, where the fake server listens. (127.0.0.2 needs an alias
	// on macOS's lo0.) A certificate for it, trusted through openssl.cafile.
	$cert = "$tmp/smtp-cert.pem";
	exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=0.0.0.0 -addext subjectAltName=IP:0.0.0.0 -keyout '.escapeshellarg($cert).' -out '.escapeshellarg("$cert.crt").' 2>/dev/null', $o, $code);
	same(0, $code, 'openssl');
	file_put_contents($cert, file_get_contents("$cert.crt"), FILE_APPEND);
	$smtp = function ($mode) use ($root, $tmp, $cert) {
		$port = free_port();
		$transcript = "$tmp/smtp-$mode-$port.log";
		if (!fake_smtp($port, $transcript, $mode, $cert)) throw new Exception("the fake SMTP server did not answer on 127.0.0.1:$port");
		return array($port, $transcript);
	};
	$send = function ($dsn, $from = true) use ($root, $cert) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli();'
			.' echo mail::send_view("_email/contact_received", "owner@cafe.test", array("email" => "a@b.co", "message" => "Hi")) ? "sent" : "failed: ".mail::$last_error;';
		$env = 'RASTER_MAIL='.escapeshellarg($dsn).($from ? ' RASTER_MAIL_FROM='.escapeshellarg('Café <hello@cafe.test>') : ' RASTER_MAIL_FROM=');
		return shell_exec($env.' '.php_cmd().' -d openssl.cafile='.escapeshellarg("$cert.crt").' -r '.escapeshellarg($code).' 2>&1');
	};
	// plain text is fine on this machine
	list($port, $log) = $smtp('plain');
	same('sent', $send("smtp://user:pass@127.0.0.1:$port"), 'localhost may skip TLS');
	$transcript = file_get_contents($log);
	has($transcript, 'AUTH IN PLAIN TEXT');
	has($transcript, 'C: '.base64_encode('user'));
	has($transcript, 'C: MAIL FROM:<hello@cafe.test>');
	has($transcript, 'C: RCPT TO:<owner@cafe.test>');
	has($transcript, 'From: =?UTF-8?B?'.base64_encode('Café').'?= <hello@cafe.test>');
	// but not to another host, unless you insist
	list($port, $log) = $smtp('plain');
	// said here, or every step below fails with a less clear reason
	if (!@fsockopen('0.0.0.0', $port, $errno, $errstr, 1)) throw new Exception("0.0.0.0:$port does not reach the fake server on 127.0.0.1:$port ($errstr); these steps need a system that connects to 0.0.0.0 on this machine, as Linux and macOS do");
	has($send("smtp://user:pass@0.0.0.0:$port"), 'does not offer STARTTLS');
	lacks((string)@file_get_contents($log), 'C: AUTH', 'no password sent in plain text');
	same('sent', $send("smtp://user:pass@0.0.0.0:$port?insecure=1"));
	// STARTTLS upgrades before the password
	list($port, $log) = $smtp('starttls');
	same('sent', $send("smtp://user:pass@0.0.0.0:$port"));
	$transcript = file_get_contents($log);
	has($transcript, 'TLS STARTED');
	has($transcript, 'AUTH OVER TLS');
	lacks($transcript, 'AUTH IN PLAIN TEXT');
	// smtps is TLS from the first byte
	list($port, $log) = $smtp('smtps');
	same('sent', $send("smtps://user:pass@0.0.0.0:$port"));
	has(file_get_contents($log), 'AUTH OVER TLS');
	// a refused password is named in the error, never written into it
	list($port, $log) = $smtp('badauth');
	$out = $send("smtp://user:s3cret-pass@127.0.0.1:$port");
	has($out, 'failed: SMTP error after AUTH LOGIN password: 535');
	lacks($out, base64_encode('s3cret-pass'));
	lacks($out, base64_encode('user'));
	// the sender from config mail_from when RASTER_MAIL_FROM is not set
	list($port, $log) = $smtp('plain');
	same('sent', $send("smtp://127.0.0.1:$port", false));
	has(file_get_contents($log), 'From: =?UTF-8?B?'.base64_encode('Raster Café').'?= <hello@cafe.test>');
});

// ## J. Languages

test(array('J1', 'J2', 'J3', 'J4', 'J5'), 'languages', function () use ($base) {
	$en = http('GET', "$base/lab")[1];
	has(between($en, 'language'), '<p class="language">en</p>');
	has($en, '<a class="lang active" href="'.$base.'/lab?lang=en">en</a>');
	list(, $ro, $headers) = http('GET', "$base/lab?lang=ro");
	has(between($ro, 'language'), 'Bine ați venit la Raster Café');
	has($ro, '>Meniu</a>');
	has($ro, '<a class="lang active" href="'.$base.'/lab?lang=ro">ro</a>');
	has(implode("\n", $headers), 'Set-Cookie: cafe_lang=ro');
	has(http('GET', "$base/lab", null, array('Cookie: cafe_lang=ro'))[1], '>Meniu</a>');
	has(http('GET', "$base/lab", null, array('Accept-Language: ro-RO,ro;q=0.9,en;q=0.5'))[1], '>Meniu</a>');
	has(http('GET', "$base/lab", null, array('Accept-Language: de-DE'))[1], '>Menu</a>', 'unknown languages fall back');
});

// ## M. MCP

test(array('M1', 'M2', 'M3'), 'the MCP endpoint', function () use ($base) {
	same(401, http('POST', "$base/mcp", '{}')[0]);
	same(401, http('POST', "$base/mcp", '{}', array('Authorization: Bearer wrong'))[0]);
	same(405, http('GET', "$base/mcp", null, array('Authorization: Bearer demo-token'))[0]);
	$call = function ($payload) use ($base) {
		return http('POST', "$base/mcp", json_encode($payload), array('Authorization: Bearer demo-token', 'Content-Type: application/json'));
	};
	$init = json_decode($call(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array('protocolVersion' => '2025-03-26')))[1], true);
	same('2025-03-26', $init['result']['protocolVersion']);
	same('raster', $init['result']['serverInfo']['name']);
	same(202, $call(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'))[0]);
	same(array(), json_decode($call(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'))[1], true)['result']);
	$tools = json_decode($call(array('jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'))[1], true)['result']['tools'];
	$names = array_map(function ($t) { return $t['name']; }, $tools);
	foreach (array('site_overview', 'get_page', 'update_page', 'page_history', 'list_items', 'get_item',
		'create_item', 'update_item', 'delete_item', 'lint_templates', 'schema_status',
		'describe', 'vocabulary', 'annotations', 'list_views', 'read_view', 'check_view', 'render_url', 'clear_cache') as $name) {
		check(in_array($name, $names), "tools/list lacks $name");
	}
	// writing templates stays off over HTTP until the site turns it on
	check(!in_array('write_view', $names), 'write_view is not offered over HTTP');
	$refused = json_decode($call(array('jsonrpc' => '2.0', 'id' => 30, 'method' => 'tools/call', 'params' => array('name' => 'write_view', 'arguments' => array('view' => 'x.html', 'content' => 'x'))))[1], true);
	same(-32602, $refused['error']['code'], 'and calling it anyway is an error');
	has($refused['error']['message'], 'Unknown tool');
	same(count($names), count(array_unique($names)), 'no tool is listed twice');
	$batch = json_decode($call(array(array('jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'), array('jsonrpc' => '2.0', 'id' => 5, 'method' => 'nope')))[1], true);
	same(-32601, $batch[1]['error']['code']);
	same(-32700, json_decode(http('POST', "$base/mcp", 'not json', array('Authorization: Bearer demo-token'))[1], true)['error']['code']);
});
test('M4', 'every MCP tool', function () use ($base) {
	$overview = mcp($base, 'site_overview');
	$urls = array_map(function ($p) { return $p['url']; }, $overview['pages']);
	foreach (array('site', '/', '/about', '/menu', '/docs/setup') as $url) check(in_array($url, $urls), "overview lacks $url");
	$collections = array_map(function ($c) { return $c['name']; }, $overview['collections']);
	same(array('booking', 'bookingclosed', 'bookinghours', 'bookingresource', 'bookingservice', 'contactmessage', 'events', 'faq', 'journal', 'menu', 'rsvp'), $collections);
	same('About us', mcp($base, 'get_page', array('page' => '/about'))['fields']['heading']);
	$item = mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'MCP post', 'author' => 'Agent')));
	same('Agent', mcp($base, 'get_item', array('collection' => 'journal', 'id' => $item['id']))['author']);
	same('Edited', mcp($base, 'update_item', array('collection' => 'journal', 'id' => $item['id'], 'fields' => array('title' => 'Edited')))['title']);
	same($item['id'], mcp($base, 'delete_item', array('collection' => 'journal', 'id' => $item['id']))['deleted']);
	check(mcp($base, 'list_items', array('collection' => 'journal'))['total'] >= 2);
	same(0, mcp($base, 'lint_templates')['errors']);
	check(array_key_exists('drift', mcp($base, 'schema_status')));
});
test('M5', 'MCP refuses fields that are not in the templates', function () use ($base) {
	list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'update_page', 'arguments' => array('page' => '/about', 'fields' => array('made_up' => 'x'))))), array('Authorization: Bearer demo-token'));
	$result = json_decode($body, true)['result'];
	check(!empty($result['isError']));
	has($result['content'][0]['text'], "Unknown field 'made_up'");
	has(json_decode(http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'get_page', 'arguments' => array('page' => '/nope')))), array('Authorization: Bearer demo-token'))[1], true)['result']['content'][0]['text'], 'Unknown page');
});
test('M6', 'MCP over stdio', function () use ($root) {
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes, $root, getenv());
	fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array()))."\n");
	fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'))."\n");
	fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array('name' => 'get_page', 'arguments' => array('page' => 'site'))))."\n");
	fclose($pipes[0]);
	$lines = array_filter(explode("\n", stream_get_contents($pipes[1])));
	proc_close($process);
	same(2, count($lines), 'notifications get no answer');
	same('Café Raster', json_decode(end($lines), true)['result']['structuredContent']['fields']['site_name']);
});

test('M10', 'describe: the site in one call', function () use ($base) {
	$all = mcp($base, 'describe');
	// all but the vocabulary, which is most of a full answer and has its own tool
	same(array('site', 'routing', 'pages', 'admin_pages', 'collections', 'settings', 'lint', 'schema', 'views'), array_keys($all));
	same('demo', $all['site']['app']);
	same('cafe', $all['site']['theme']);
	check(in_array('/menu', array_map(function ($p) { return $p['url']; }, $all['pages'])), 'the pages are there');
	$names = array_map(function ($c) { return $c['name']; }, $all['collections']);
	same(array('booking', 'bookingclosed', 'bookinghours', 'bookingresource', 'bookingservice', 'contactmessage', 'events', 'faq', 'journal', 'menu', 'rsvp'), $names);
	same(4, $all['collections'][array_search('menu', $names)]['page_size'], 'menu_page_size');
	same(50, $all['collections'][array_search('booking', $names)]['page_size'], 'booking_page_size');
	same(0, $all['lint']['errors']);
	check(count($all['views']) > 20, 'the view files');
	// the extra routes of the demo
	$patterns = array_map(function ($r) { return $r['pattern']; }, $all['routing']['extra_routes']);
	check(in_array('specials', $patterns) && in_array('print/menu', $patterns), implode(', ', $patterns));
	// settings an agent needs, and nothing that could be a secret
	same('cafe', $all['settings']['theme']);
	same(10, $all['settings']['password_min_length']);
	foreach (array('mcp_token', 'mail') as $secret) check(!array_key_exists($secret, $all['settings']), "$secret must not be in describe");
	check(strpos(json_encode($all['settings']), 'demo-token') === false, 'and the token is nowhere in it');
	// by section, and an unknown section is an error
	$some = mcp($base, 'describe', array('sections' => array('site', 'lint')));
	same(array('site', 'lint'), array_keys($some));
	same(array('vocabulary'), array_keys(mcp($base, 'describe', array('sections' => array('vocabulary')))), 'the vocabulary when asked for');
	list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'describe', 'arguments' => array('sections' => array('site'))))), array('Authorization: Bearer demo-token', 'Content-Type: application/json'));
	check(strpos(json_decode($body, true)['result']['content'][0]['text'], "\n") === false, 'the text is compact JSON');
	list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'describe', 'arguments' => array('sections' => array('nope'))))), array('Authorization: Bearer demo-token', 'Content-Type: application/json'));
	has(json_decode($body, true)['result']['content'][0]['text'], 'Unknown section');
});
test('M11', 'vocabulary: every name a template may call', function () use ($base) {
	$vocabulary = mcp($base, 'vocabulary');
	check(isset($vocabulary['models']['cafe']), 'the app model');
	check(isset($vocabulary['models']['authentication']), 'and the bundled ones');
	check($vocabulary['models']['authentication']['bundled'], 'marked as bundled');
	check(!$vocabulary['models']['cafe']['bundled']);
	// signatures, so an agent knows what to pass
	same('category_count(category)', $vocabulary['models']['cafe']['methods']['category_count']['reads']);
	same(1, $vocabulary['models']['cafe']['methods']['category_count']['needs']);
	same('links(source, filter = …)', $vocabulary['models']['pagination']['methods']['links']['reads']);
	same(1, $vocabulary['models']['pagination']['methods']['links']['needs']);
	same(0, $vocabulary['models']['cafe']['methods']['hours']['needs']);
	// the queries that are files, not PHP
	check(in_array('count_category', $vocabulary['models']['cafe']['queries']), 'sql/ queries');
	check(in_array('dish_names', $vocabulary['shared_queries']), 'models/sql.php: '.implode(', ', $vocabulary['shared_queries']));
	// what the engine answers itself, and what it fills in
	same(array('session', 'self', 'if'), $vocabulary['engine_models']);
	check(in_array('raster_detail_link', $vocabulary['row_keys_from_raster']));
	// names the CMS keeps
	foreach (array('slug', 'id', 'enabled', 'published_at', 'style', 'login') as $name) {
		check(in_array($name, $vocabulary['reserved']['fields']), "$name is reserved");
	}
	same(array('users', 'raster'), $vocabulary['reserved']['collections']);
	// events: what is sent, and who listens
	foreach (array('requested', 'confirmed', 'declined', 'cancelled', 'rescheduled') as $step) check(in_array("booking.$step", $vocabulary['events']['sent']), "the booking model sends booking.$step");
	check(isset($vocabulary['events']['listened_to']['booking.requested']), 'and cafe listens');
	has($vocabulary['events']['listened_to']['booking.requested'][0], 'cafe.subscribe_guest');
});
test('M12', 'annotations: the grammar over MCP', function () use ($base) {
	$grammar = mcp($base, 'annotations');
	same(2, $grammar['version']);
	same(array('open', 'close', 'self_closing', 'note', 'in_scripts'), array_keys($grammar['spelling']));
	same('<!-- /{name} -->', $grammar['spelling']['close']);
	has($grammar['structure']['full_close'], 'carries the whole name', 'a closing tag is not shortened');
	check($grammar['keywords']['render']['repeats']);
	check(!$grammar['keywords']['render']['self_closing']);
	// the same data lint checks against
	same(array_keys($grammar['keywords']), array('print', 'render', 'remove', 'res', 'dry'));
});
test('M13', 'list_views and read_view', function () use ($base) {
	$views = mcp($base, 'list_views');
	same('cafe', $views['theme']);
	check(in_array('about.html', $views['views']) && in_array('docs/setup.html', $views['views']), 'nested views too');
	check(in_array('journal.rss', $views['views']), 'and feeds');
	$about = mcp($base, 'read_view', array('view' => 'about.html'));
	has($about['content'], '<!-- print.cms.heading -->');
	same(strlen($about['content']), $about['bytes']);
	same(1, count(mcp($base, 'list_views', array('theme' => 'print'))['views']), 'another theme');
	// nothing outside the theme folder, and nothing that is not a view
	foreach (array('../../../system/boot.php', '/etc/passwd', 'about.php', '', 'nope.html', '../about.html', 'docs/../../_layout.html') as $bad) {
		$error = null;
		try { mcp($base, 'read_view', array('view' => $bad)); } catch (Exception $e) { $error = $e->getMessage(); }
		check($error !== null, "read_view accepted '$bad'");
	}
});
test('M14', 'check_view lints a draft and writes nothing', function () use ($base, $views) {
	$bad = mcp($base, 'check_view', array('content' => "<p><!-- print.cafe.category_count -->0<!-- /print.cafe.category_count --></p>", 'view' => 'draft.html'));
	same(1, $bad['errors']);
	has($bad['problems'][0]['message'], 'needs 1 argument');
	same('draft.html', $bad['problems'][0]['file']);
	$good = mcp($base, 'check_view', array('content' => "<p><!-- print.cafe.category_count('cakes') -->0<!-- /print.cafe.category_count('cakes') --></p>"));
	same(0, $good['errors']);
	// a dry reference resolves against the theme, as it would once written
	same(0, mcp($base, 'check_view', array('content' => '<!-- dry._layout.head /-->'))['errors']);
	same(1, mcp($base, 'check_view', array('content' => '<!-- dry._layout.nothing /-->'))['errors']);
	check(!file_exists("$views/draft.html"), 'check_view writes nothing');
});
test('M15', 'write_view refuses markup that does not lint', function () use ($views) {
	$good = "<h1><!-- print.cms.heading -->Board<!-- /print.cms.heading --></h1>\n<ul><!-- render.cms.menu('order=name&limit=2') --><li><!-- print.name -->Dish<!-- /print.name --></li><!-- /render.cms.menu('order=name&limit=2') --></ul>\n";
	$bad = str_replace('order=name&limit=2', "order=name&limit=2') --><!-- render.cms.menu('x=1", $good);
	$answers = mcp_stdio(array(
		array('write_view', array('view' => 'zz-board.html', 'content' => $bad)),
		array('list_views'),
		array('write_view', array('view' => 'zz-board.html', 'content' => $good)),
		array('read_view', array('view' => 'zz-board.html')),
		array('write_view', array('view' => 'zz-board.html', 'content' => $good)),
		array('write_view', array('view' => '../zz-escape.html', 'content' => $good)),
		array('write_view', array('view' => 'zz-board.php', 'content' => $good)),
		array('write_view', array('view' => 'docs/../../zz-deep.html', 'content' => $good)),
	));
	try {
		same(false, $answers[0]['written'], 'the broken one is refused');
		check($answers[0]['errors'] >= 1, 'with the problems');
		check(!in_array('zz-board.html', $answers[1]['views']), 'and nothing was written');
		same(true, $answers[2]['written']);
		same(true, $answers[2]['created']);
		check(is_file("$views/zz-board.html"), 'the file is there');
		has($answers[3]['content'], 'print.cms.heading');
		same(false, $answers[4]['created'], 'writing again replaces it');
		check(isset($answers[5]['error']), 'no path outside the theme');
		check(isset($answers[6]['error']), 'and only view extensions');
		check(isset($answers[7]['error']), "nor a path that climbs back out through ..");
		check(!file_exists(dirname($views).'/zz-escape.html'), 'nothing escaped');
		check(!file_exists(dirname(dirname($views)).'/zz-deep.html'), 'nothing escaped deeper either');
		// the new annotation is a new field, and the answer says so
		check(isset($answers[2]['schema']['drift']), 'the schema is reported');
	} finally {
		@unlink("$views/zz-board.html");
	}
});
test('M16', 'render_url renders without a web server', function () use ($base) {
	$page = mcp($base, 'render_url', array('url' => '/about'));
	same(true, $page['ok']);
	same(200, $page['status']);
	has($page['html'], '<h1>About us</h1>');
	same(strlen($page['html']), $page['bytes'], 'not truncated');
	$short = mcp($base, 'render_url', array('url' => '/about', 'limit' => 200));
	same(true, $short['truncated']);
	same(200, strlen($short['html']));
	$missing = mcp($base, 'render_url', array('url' => '/nope'));
	same(false, $missing['ok']);
	same(404, $missing['status']);
	$error = null;
	try { mcp($base, 'render_url', array('url' => 'about')); } catch (Exception $e) { $error = $e->getMessage(); }
	has((string)$error, 'starts with /');
	// the server is still answering: rendering happens in its own process
	same(0, mcp($base, 'lint_templates')['errors']);
});

// ## F. Schema, and N. the command line

test(array('F1', 'F2', 'F4', 'F5', 'F8'), 'schema: status, check, rename, drop, --dry-run', function () use ($base, $views) {
	http('GET', "$base/faq");
	list($code, $out) = raster(array('schema', '--json'));
	same(0, $code, $out);
	$status = json_decode($out, true);
	same(false, $status['drift'], $out);
	mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => 'Our story')));
	$original = file_get_contents("$views/about.html");
	with_file("$views/about.html", str_replace(array('print.cms.heading', '/print.cms.heading'), array('print.cms.title', '/print.cms.title'), $original), function () use ($base) {
		http('GET', "$base/about");
		same(1, raster(array('schema', '--check'))[0], 'drift after renaming');
		list(, $out) = raster(array('schema'));
		has($out, '--rename=aboutpage.heading:title');
		list($code, $out) = raster(array('schema', '--rename=aboutpage.heading:title', '--dry-run'));
		same(0, $code, $out);
		has($out, 'would move aboutpage.heading into title', 'the GET made the new column');
		same(1, raster(array('schema', '--check'))[0], '--dry-run renamed it');
		same(0, raster(array('schema', '--rename=aboutpage.heading:title'))[0]);
		has(http('GET', "$base/about")[1], '<h1>Our story</h1>', 'the content moved');
	});
	raster(array('schema', '--rename=aboutpage.title:heading', '--drop=aboutpage.title'));
	has(http('GET', "$base/about")[1], '<h1>Our story</h1>', 'and back');
	mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => 'About us')));
	list($code, $out) = raster(array('schema', '--drop=aboutpage.heading'));
	same(1, $code);
	has($out, 'is used by the templates');
	database::instance('cms');
	R::exec('CREATE TABLE IF NOT EXISTS oldpage (id INTEGER PRIMARY KEY, x TEXT)');
	has(raster(array('schema'))[1], 'table oldpage');
	list($code, $out) = raster(array('schema', '--drop=oldpage', '--dry-run'));
	same(0, $code, $out);
	has($out, 'would drop table oldpage');
	has(raster(array('schema'))[1], 'table oldpage', '--dry-run dropped it');
	list($code, $out) = raster(array('schema', '--apply', '--dry-run'));
	same(0, $code, $out);
	has($out, 'nothing applied');
	same(0, raster(array('schema', '--drop=oldpage'))[0]);
	same(0, raster(array('schema', '--check'))[0], raster(array('schema'))[1]);
});
test(array('N1', 'N2', 'N3', 'E22'), 'help, lint and render', function () use ($views) {
	same(0, raster(array('help'))[0]);
	same(2, raster(array('frobnicate'))[0]);
	list($code, $out) = raster(array('lint', '--json'));
	same(0, $code, $out);
	same(0, json_decode($out, true)['errors']);
	with_file("$views/zz-lint.html", '<!-- print.cms.style -->x<!-- /print.cms.style --><!-- render.cms.users --><!-- /render.cms.users --><!--print.cms.x-->', function () {
		list($code, $out) = raster(array('lint'));
		same(1, $code);
		has($out, "'style' is reserved");
		has($out, "'users' is reserved");
		has($out, 'write it exactly as');
	});
	has(raster(array('lint', '--all-themes'))[1], "themes 'cafe', 'print'");
	has(raster(array('lint', '--theme=print'))[1], "No errors, 0 warning(s) in theme 'print' (1 views)");
	// warnings: forms nobody handles, alerts nobody raises, emails without a view
	with_file("$views/zz-warn.html", '<form method="post"><input name="x"></form><!-- print.validation.alert(\'nobody_raises_this\') -->x<!-- /print.validation.alert(\'nobody_raises_this\') -->', function () {
		list($code, $out) = raster(array('lint'));
		same(0, $code, 'warnings are not errors');
		has($out, 'no model handles it');
		has($out, "No model raises 'nobody_raises_this'");
	});
	list($code, $out) = raster(array('render', '/about'));
	same(0, $code);
	has($out, '<h1>About us</h1>');
	same(1, raster(array('render', '/nope'))[0]);
});
test('C46', 'lint checks the arguments, and names the nearest method', function () use ($views) {
	with_file("$views/zz-args.html", "<p><!-- print.cafe.category_count -->0<!-- /print.cafe.category_count --></p>\n"
		."<p><!-- print.cafe.category_count('a', 'b') -->0<!-- /print.cafe.category_count('a', 'b') --></p>\n"
		."<p><!-- print.cafe.dishes_between(11) -->x<!-- /print.cafe.dishes_between(11) --></p>\n"
		."<p><!-- print.cafe.categry_count('a') -->0<!-- /print.cafe.categry_count('a') --></p>\n"
		."<p><!-- print.cafe.hours /--></p>\n"
		."<p><!-- print.cafe.guestbook -->none<!-- /print.cafe.guestbook --></p>\n", function () {
		list($code, $out) = raster(array('lint'));
		same(1, $code);
		has($out, 'cafe.category_count(category) needs 1 argument(s), 0 given');
		has($out, 'cafe.category_count(category) takes 1 argument(s), 2 given');
		has($out, 'cafe.dishes_between(low, high) needs 2 argument(s), 1 given');
		has($out, "did you mean 'category_count'?");
		lacks($out, 'cafe.hours', 'a method with no arguments is fine');
		lacks($out, 'cafe.guestbook', 'and so is one whose argument has a default');
	});
});
test('C47', 'a link inside an alert goes to the route, not the view file', function () use ($base, $views) {
	$alert = "<!-- print.validation.alert('linked') --><p>Done. <a href=\"about.html\">See it</a></p><!-- /print.validation.alert('linked') -->";
	with_file("$views/zz-alert-link.html", "<!doctype html>\n<html>\n<body>\n$alert\n</body>\n</html>\n", function () use ($base) {
		list($code, $body) = http('GET', "$base/zz-alert-link?done=linked");
		same(200, $code);
		has($body, 'See it', 'the alert shows when done names it');
		lacks($body, 'href="about.html"', 'the view file never reaches the page');
		has($body, 'href="'.$base.'/about"', 'the block a listener put back was fixed up too');
	});
});
test('N5', 'annotations: the grammar as data', function () {
	list($code, $out) = raster(array('annotations', '--json'));
	same(0, $code, $out);
	$grammar = json_decode($out, true);
	same(2, $grammar['version']);
	check(!isset($grammar['spelling']['short_close']), 'closing tags carry the full name');
	has($grammar['structure']['full_close'], 'lint --fix');
	check($grammar['keywords']['render']['repeats'], 'render repeats its content');
	check(!$grammar['keywords']['render']['self_closing'], 'render cannot self-close');
	check($grammar['keywords']['print']['self_closing'], 'print can');
	check(!$grammar['keywords']['remove']['name'], 'remove takes no name');
	// the same list the inspector lints with
	same(array_keys($grammar['keywords']), raster_inspector::keywords());
	same($grammar['references']['builtin_models'], raster_inspector::builtin_models());
	has(raster(array('annotations'))[1], 'may self-close');
});
test('N6', 'lint --fix repairs what is mechanical', function () use ($views) {
	// spacing the engine cannot read, and short closing tags
	$broken = "<!--print.cms.heading-->T<!-- /print.cms.heading -->\n<!--  render.cms.journal  --><!-- print.title -->x<!-- /print --><!-- /render -->\n<!-- render.cms.menu('order=name') --><!-- print.name -->x<!-- /print.name --><!--/render.cms.menu-->\n";
	with_file("$views/zz-fix.html", $broken, function () use ($views) {
		same(1, raster(array('lint'))[0], 'errors before');
		list($code, $out) = raster(array('lint', '--fix'));
		same(0, $code, $out);
		has($out, '<!--print.cms.heading--> -> <!-- print.cms.heading -->');
		has($out, '<!-- /render --> -> <!-- /render.cms.journal -->');
		$fixed = file_get_contents("$views/zz-fix.html");
		has($fixed, '<!-- print.cms.heading -->');
		has($fixed, '<!-- render.cms.journal --><!-- print.title -->x<!-- /print.title --><!-- /render.cms.journal -->');
		has($fixed, "<!-- /render.cms.menu('order=name') -->", 'spaced out, then written in full');
		same(0, raster(array('lint'))[0], 'and nothing is left');
	});
	// what needs a decision is reported, not guessed at: a typo may be an
	// ordinary comment, an unclosed block needs its closing tag placed
	with_file("$views/zz-fix.html", "<!-- prnit.cms.intro -->I<!-- /print.cms.intro -->\n<!-- render.cms.journal -->", function () use ($views) {
		list($code, $out) = raster(array('lint', '--fix'));
		same(1, $code);
		has($out, 'never closed');
		has($out, "did you mean 'print'");
		lacks($out, 'fixed');
		has(file_get_contents("$views/zz-fix.html"), 'prnit', 'the typo is left as written');
	});
});
test('N12', 'vocabulary on the command line', function () {
	list($code, $out) = raster(array('vocabulary', '--json'));
	same(0, $code, $out);
	$vocabulary = json_decode($out, true);
	same('category_count(category)', $vocabulary['models']['cafe']['methods']['category_count']['reads']);
	$text = raster(array('vocabulary'))[1];
	has($text, 'category_count(category)');
	has($text, 'sql/: count_category, dishes_between');
	has($text, 'reserved field names');
	has($text, 'booking.requested -> cafe.subscribe_guest');
});
test('N13', 'describe on the command line', function () {
	list($code, $out) = raster(array('describe', '--json'));
	same(0, $code, $out);
	$described = json_decode($out, true);
	same('demo', $described['site']['app']);
	same(10, count($described));
	list($code, $out) = raster(array('describe', '--sections=site,vocabulary'));
	same(0, $code, $out);
	has($out, '## site');
	has($out, '## vocabulary');
	lacks($out, '## pages');
	has($out, 'described demo/ in ');
});
test('N7', 'deploy prints the server configuration', function () use ($root) {
	list($code, $apache) = raster(array('deploy', '--config=apache'));
	same(0, $code, $apache);
	same(trim(file_get_contents("$root/.htaccess")), trim($apache), 'the .htaccess in the repository is this file');
	foreach (array('nginx', 'caddy') as $server) {
		list($code, $out) = raster(array('deploy', "--config=$server", '--host=cafe.example.com', '--root=/srv/cafe'));
		same(0, $code, $out);
		has($out, 'cafe.example.com');
		has($out, '/srv/cafe');
		has($out, 'phar', 'the private extensions are in there');
		lacks($out, '/demo/', 'no rule names an app folder, so adding an app needs no change');
	}
	// nginx takes 1 MB bodies by default; the editor sends pictures up to 12 MB (#115)
	list($code, $nginx) = raster(array('deploy', '--config=nginx'));
	check(preg_match('/^    client_max_body_size (\d+)m;$/m', $nginx, $m) && (int)$m[1] > 12, 'a body size above the editor\'s 12 MB: '.$nginx);
	has($nginx, "    gzip on;\n");
	has($nginx, 'gzip_types text/css');
	check(preg_match('#location /media/ \{[^}]*expires 1y;#', $nginx), 'media/ is cached for long: its file names never repeat');
	same(2, raster(array('deploy'))[0], 'no --config is a usage error');
	same(1, raster(array('deploy', '--config=iis'))[0]);
});
test('N8', 'doctor: the rules on disk, and intentional deprecations', function () use ($root) {
	list($code, $out) = raster(array('doctor'));
	has($out, '.htaccess has every rule');
	// the demo keeps the older validation regions on purpose (D14)
	has($out, 'kept on purpose');
	lacks($out, 'use(s) of deprecated features'."\n    regions", 'so they are not a warning');
	// a .htaccess from an older Raster is missing the newer rules
	with_file("$root/.htaccess", "RewriteEngine on\nRewriteRule (^|/)\\. - [F,L]\n", function () {
		has(raster(array('doctor'))[1], 'is missing');
	});
});
test(array('N9', 'O1'), 'raster export: the site as static files', function () use ($tmp) {
	$out = "$tmp/static";
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
	has($output, 'linked to https://cafe.example/');
	has($output, 'Left out (they need an account): /members, /staff');
	lacks($output, 'form');
	has($output, 'Pages link to what the export leaves out: /login');
	foreach (array('index.html', 'about/index.html', 'menu/index.html', 'menu/menu_page/2/index.html', 'menu/menu_item/americano/index.html', 'menu/menu_items/category/cakes/index.html', 'journal.rss', 'feed.json', 'sitemap.xml', 'hours.txt', '404.html', 'demo/views/cafe/style.css', 'demo/views/cafe/img/menu/flat-white.jpg', 'print/menu/index.html', 'ro/index.html', 'ro/menu/index.html', '.raster-export.json') as $file) {
		check(is_file("$out/$file"), "missing $file");
	}
	foreach (array('login/index.html', 'members/index.html', 'register/index.html', 'account/index.html', 'forgot/index.html', 'password/new/index.html', 'letters/confirm/index.html', 'letters/stop/index.html', 'ro/journal.rss') as $file) check(!file_exists("$out/$file"), "should not export $file");
	$home = file_get_contents("$out/index.html");
	has($home, "<base href='https://cafe.example/demo/views/cafe/' />");
	has($home, 'href="https://cafe.example/menu"');
	has($home, '<a class="lang" href="https://cafe.example/ro/">ro</a>', 'the language switcher');
	lacks($home, 'raster-editor', 'no editor');
	has(file_get_contents("$out/404.html"), '<h1>We looked everywhere</h1>');
	$ro = file_get_contents("$out/ro/menu/index.html");
	has($ro, '>Meniu</a>');
	has($ro, 'href="https://cafe.example/ro/about"', 'links stay in the language');
	has($ro, 'href="https://cafe.example/journal.rss"', 'feeds are shared');
	has($ro, '<a class="lang" href="https://cafe.example/menu">en</a>');
	lacks(file_get_contents("$out/events/index.html"), 'Secret tasting', 'no drafts');
	has(file_get_contents("$out/journal.rss"), '<link>https://cafe.example/journal/journal_item/');
	$left = trim((string)shell_exec('grep -rl "127.0.0.1" '.escapeshellarg($out).' 2>/dev/null'));
	same('', $left, 'no local addresses left');
	list($code, $output) = raster(array('export', $out, '--clean', '--skip=/lab'));
	same(0, $code, $output);
	check(!file_exists("$out/lab/index.html"), '--skip');
	has(file_get_contents("$out/index.html"), 'href="/menu"', 'root-relative links without --url');
	mkdir("$tmp/not-an-export");
	touch("$tmp/not-an-export/notes.txt");
	same(1, raster(array('export', "$tmp/not-an-export"))[0], 'a folder with other files is left alone');
});
test('N10', 'print.if.live and print.if.static: forms stay out of a static export', function () use ($tmp, $base, $views) {
	$visit = file_get_contents("$tmp/static/visit/index.html");
	has($visit, 'Call us on <a href="tel:+40721000000">', 'print.if.static shows in the export');
	lacks($visit, '<form', 'print.if.live is hidden in the export');
	lacks(file_get_contents("$tmp/static/index.html"), 'name="raster_form"');
	$live = http('GET', "$base/visit")[1];
	has($live, 'name="raster_form" value="booking.form"');
	lacks($live, 'Call us on', 'print.if.static is hidden on the live site');
	// a form the template doesn't wrap stops the export, and nothing is written
	with_file("$views/export-probe.html", '<!doctype html><html><body><!-- render.contact.form --><form method="post"><input name="email"><button>Send</button></form><!-- /render.contact.form --></body></html>', function () use ($tmp) {
		list($code, $output) = raster(array('export', "$tmp/probe"));
		same(1, $code, $output);
		has($output, 'The contact.form form (/export-probe) needs PHP. Wrap it in <!-- print.if.live -->');
		has($output, 'Nothing was written');
		check(!file_exists("$tmp/probe/index.html"));
	});
});
test('N11', 'exporting again writes only what changed', function () use ($tmp, $base) {
	$out = "$tmp/incremental";
	same(0, raster(array('export', $out, '--url=https://cafe.example/'))[0]);
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
	has($output, 'Nothing changed since the last export');
	$about = filemtime("$out/about/index.html");
	touch("$out/extra.txt");
	$item = mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Exported once', 'author' => 'Ana')));
	sleep(1);
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
	check(preg_match('/\((\d+) written, 0 removed, (\d+) unchanged\)/', $output, $m), $output);
	check($m[1] > 0 && $m[1] < $m[2], 'a few files written: '.$m[0]);
	check(is_file("$out/journal/journal_item/exported-once/index.html"), 'the new item');
	same($about, filemtime("$out/about/index.html"), 'unchanged pages are not written again');
	mcp($base, 'delete_item', array('collection' => 'journal', 'id' => $item['id']));
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	has($output, 'removed');
	check(!file_exists("$out/journal/journal_item/exported-once/index.html"), 'the deleted item is removed');
	check(!is_dir("$out/journal/journal_item/exported-once"), 'and its folder');
	check(is_file("$out/extra.txt"), 'files the export did not write are kept');
	$manifest = json_decode(file_get_contents("$out/.raster-export.json"), true);
	check(isset($manifest['files']['about/index.html']) && !empty($manifest['fingerprint']));
});
test('N11', 'a new day exports again: a list of what is coming drops what is past', function () use ($tmp, $base, $views) {
	// Etc/GMT+12 is UTC-12 and Pacific/Kiritimati UTC+14: their dates always
	// differ, so moving the site's time zone moves it to another day
	$yesterday = array('CAFE_TIMEZONE' => 'Etc/GMT+12');
	$tomorrow = array('CAFE_TIMEZONE' => 'Pacific/Kiritimati');
	$day = (new DateTime('now', new DateTimeZone('Etc/GMT+12')))->format('Y-m-d');
	$out = "$tmp/daily";
	with_file("$views/coming.html", "<!doctype html><html><body><!-- render.cms.events('date>=today&order=date') --><p><!-- print.title -->Jazz night<!-- /print.title --> <!-- print.date -->2026-10-10<!-- /print.date --></p><!-- /render.cms.events('date>=today&order=date') --></body></html>", function () use ($base, $out, $yesterday, $tomorrow, $day) {
		$gig = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Last night gig', 'date' => $day, 'summary' => 'x')));
		try {
			list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'), $yesterday);
			same(0, $code, $output);
			has(file_get_contents("$out/coming/index.html"), 'Last night gig', 'on its day the event is coming');
			has(raster(array('export', $out, '--url=https://cafe.example/'), $yesterday)[1], 'Nothing changed since the last export');
			$about = filemtime("$out/about/index.html");
			sleep(1);
			list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'), $tomorrow);
			same(0, $code, $output);
			lacks($output, 'Nothing changed since the last export');
			lacks(file_get_contents("$out/coming/index.html"), 'Last night gig', 'the next day it is past');
			same($about, filemtime("$out/about/index.html"), 'pages that did not change are not written again');
		} finally {
			mcp($base, 'delete_item', array('collection' => 'events', 'id' => $gig['id']));
		}
	});
});
test('N11', 'an export that crosses midnight records the day its pages were made on', function () use ($tmp, $root) {
	// the clock moves to the next day between rendering and saving the
	// fingerprint: the next day's export must still run
	$out = "$tmp/midnight";
	$script = "$tmp/midnight.php";
	file_put_contents($script, '<?php require '.var_export("$root/system/boot.php", true).'; boot::$appname = "demo"; boot::cli();'
		.' require_once BASE."tools/inspector.php"; require_once BASE."tools/export.php";'
		.' class late_export extends raster_export { protected function collect_pages() { date_default_timezone_set("Pacific/Kiritimati"); parent::collect_pages(); } }'
		.' @mkdir('.var_export($out, true).', 0775, true);'
		.' $e = (new late_export('.var_export($root, true).', '.var_export($out, true).', "https://cafe.example/"))->run();'
		.' echo $e->errors ? implode("\n", $e->errors) : "exported";');
	$process = proc_open(php_argv($script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array_merge(getenv(), array('CAFE_TIMEZONE' => 'Etc/GMT+12')));
	$output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	same(0, proc_close($process), $output);
	same('exported', trim($output));
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'), array('CAFE_TIMEZONE' => 'Pacific/Kiritimati'));
	same(0, $code, $output);
	lacks($output, 'Nothing changed since the last export', 'the next day exports again');
});
test('N16', 'a page that answers an error stops the export, and the last export stays', function () use ($tmp, $root, $views) {
	$out = "$tmp/failing";
	same(0, raster(array('export', $out, '--url=https://cafe.example/'))[0]);
	$manifest = file_get_contents("$out/.raster-export.json");
	$original = file_get_contents("$views/about.html");
	test_fixture("$root/demo/models/zzthrows"); @mkdir("$root/demo/models/zzthrows");
	$boom = '<!-- print.zzthrows.boom -->x<!-- /print.zzthrows.boom -->';
	with_file("$root/demo/models/zzthrows/zzthrows.php", "<?php\nclass zzthrows\n{\n\tfunction boom() { throw new RuntimeException('boom'); }\n}\n", function () use ($out, $views, $original, $boom) {
		// a model that throws, on About
		with_file("$views/about.html", str_replace('<h2>Opening hours</h2>', $boom.'<h2>Opening hours</h2>', $original), function () use ($out) {
			list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
			same(1, $code, $output);
			has($output, '/about answered 500');
			has($output, 'Nothing was written');
			check(is_file("$out/about/index.html"), 'the page from the last export was removed');
			check(is_file("$out/index.html"));
		});
		// the same for the 404 page: its 404.html stays
		$not_found = file_get_contents("$views/404.html");
		with_file("$views/404.html", str_replace('<h1>', $boom.'<h1>', $not_found), function () use ($out) {
			check(is_file("$out/404.html"), 'the last export has a 404.html');
			list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
			same(1, $code, $output);
			has($output, 'The 404 page answered 500');
			has($output, 'Nothing was written');
			check(is_file("$out/404.html"), 'the 404.html from the last export was removed');
		});
	});
	@rmdir("$root/demo/models/zzthrows");
	same($manifest, file_get_contents("$out/.raster-export.json"), 'the list of files is the last export\'s');
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
});
test('N17', 'commands refuse options they do not take, and say which they do', function () use ($views) {
	list($code, $out) = raster(array('lint', '--jsn'));
	same(2, $code, $out);
	has($out, "lint doesn't take --jsn");
	has($out, '--json');
	has($out, '--all-themes');
	list($code, $out) = raster(array('version', '--dry-run'));
	same(2, $code, $out);
	has($out, "version doesn't take --dry-run");
	// what the parser can't read is refused too, not taken as a folder or a URL
	same(2, raster(array('export', '/tmp/raster-never', '--dry_run'))[0]);
	check(!file_exists('/tmp/raster-never'), 'the export ran');
	// a typo of --dry-run on schema --drop drops nothing
	database::instance('cms');
	R::exec('CREATE TABLE IF NOT EXISTS zzkeptpage (id INTEGER PRIMARY KEY, x TEXT)');
	same(2, raster(array('schema', '--drop=zzkeptpage', '--dryrun'))[0]);
	check(in_array('zzkeptpage', R::inspect()), 'the table was dropped');
	R::exec('DROP TABLE zzkeptpage');
	// every option the help names is one the command takes
	has(raster(array('help'))[1], '--dry-run');
	same(0, raster(array('lint', '--theme=print', '--json'))[0]);
});
test('N4', 'serve', function () use ($root) {
	$port = free_port();
	$process = proc_open(php_argv("$root/bin/raster", 'serve', "--port=$port", '--host=127.0.0.1'), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, getenv());
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	try {
		has(http('GET', "http://127.0.0.1:$port/about")[1], 'About us');
	} finally {
		proc_terminate($process); proc_close($process);
		// serve's own php -S outlives the terminated parent; match it by
		// port, since the binary may be php8.2, and [-] keeps pkill from
		// matching the shell that runs it
		exec("pkill -f -- '[-]S 127.0.0.1:$port' 2>/dev/null");
	}
});

// ## More routing, engine and CMS features

test(array('A13', 'C26'), 'a custom 404 page and the route_not_found event', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/no/such/page");
	same(404, $status);
	has($body, '<h1>We looked everywhere</h1>');
	same('yes', header_value($headers, 'X-Cafe-Missing'));
	same(null, header_value(http('GET', "$base/about")[2], 'X-Cafe-Missing'));
});
test('A19', 'the café words its refusals: a 403 page with an alert for each, a 500 page; never the framework\'s words', function () use ($base) {
	list($status, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'a@b.test', 'message' => 'Hi'), array('Origin: https://evil.example'));
	same(403, $status);
	has($body, '<h1>Not this way</h1>');
	has($body, 'That form came from another website');
	lacks($body, 'open a long time', 'only the alert that was raised');
	lacks($body, 'Forbidden');
	// a member on a staff page: the account's alert
	raster(array('user', 'a19.member@example.com', '--role=member', '--password=member password'));
	$member = login($base, 'a19.member@example.com', 'member password');
	list($status, $body) = http('GET', "$base/staff", null, array("Cookie: $member"));
	same(403, $status);
	has($body, "Your account can't open this page.");
	lacks($body, 'You do not have access');
	// a logged in post without the session token
	list($status, $body) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'a@b.test', 'message' => 'Hi'), array("Cookie: $member"));
	same(403, $status);
	has($body, 'That form was open a long time.');
});
test('A14', 'a route to a view in another theme', function () use ($base) {
	list($status, $body) = http('GET', "$base/print/menu");
	same(200, $status);
	has($body, '<title>Menu (print)</title>');
	has($body, "<base href='$base/demo/views/print/'");
	has($body, '<tr><td>Flat white</td><td>14.50 lei</td></tr>');
	same(200, http('GET', "$base/demo/views/print/print.css")[0], 'the other theme\'s assets');
});
test('A15', 'rewrite off: links go through index.php', function () use ($db, $maildir) {
	$plain = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_REWRITE' => 'off'));
	list($status, $body) = http('GET', "$plain/index.php/menu");
	same(200, $status);
	has($body, '<h1>Menu</h1>');
	has($body, 'href="'.$plain.'/index.php/about"');
	has($body, 'href="'.$plain.'/index.php/menu?lang=ro"', 'the language switcher');
	has($body, 'href="'.$plain.'/index.php/menu/menu_page/2"', 'the collection pager');
	has(http('GET', "$plain/index.php/lab")[1], 'href="'.$plain.'/index.php/lab?page=2"', 'a model\'s pager');
	lacks($body, 'href="'.$plain.'/about"');
});
test(array('C27', 'C28', 'C29', 'C30', 'C31', 'C32', 'C33'), 'scripts, dry placeholders, attributes, strings, named queries, events', function () use ($base) {
	list(, $lab, $headers) = http('GET', "$base/lab/color/red");
	has(between($lab, 'script'), '<script>var labColor = "red";</script>');
	has(between($lab, 'dry-block'), '<p class="note">From the _bits partial.</p>');
	lacks($lab, 'This placeholder is replaced');
	$tricky = between($lab, 'tricky');
	has($tricky, '<a class="tricky" href="https://example.com/?q=&quot;quotes&quot;&amp;x=&lt;y&gt;">escaped link</a>');
	check(preg_match('#<a class="tricky"\s*>mock-up label</a>#', $tricky), "false removes the attribute and keeps the mock-up: $tricky");
	has(between($lab, 'banner'), '<p class="banner">Open today</p>');
	lacks($lab, 'Mock-up banner');
	has(between($lab, 'named-query'), '<p class="coffee">Americano, Cortado, Espresso, Flat white</p>');
	has(between($lab, 'named-query'), '<p class="injection">none</p>', 'placeholders are quoted');
	has(between($lab, 'named-query'), '<p class="range">Flat white</p>', ':named placeholders, the calling model\'s sql/ folder');
	same('yes', header_value($headers, 'X-Cafe-Done'), 'an app binding to a core event');
	same('served', header_value($headers, 'X-Cafe'));
	has(between($lab, 'events'), '<p class="secret">the secret is safe</p>');
	lacks($lab, 'the secret leaked');
});
test(array('C36', 'C37', 'C49'), 'the log console, and strict templates off', function () use ($base, $db, $maildir, $views, $root, $tmp) {
	$loud = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_LOG' => 'on', 'CAFE_STRICT' => 'off'));
	has(http('GET', "$loud/about")[1], 'console.log("info: Event: route_found");');
	lacks(http('GET', "$base/about")[1], 'console.log', 'only when enabled');
	// a misspelled annotation: lint error, ignored when rendering
	with_file("$views/zz-broken.html", '<p><!--print.cafe.hours-->still here</p>', function () use ($loud, $base) {
		same(500, http('GET', "$base/zz-broken")[0], 'strict by default in development');
		list($status, $body, $headers) = http('GET', "$loud/zz-broken");
		same(200, $status, 'strict off renders anyway');
		has($body, 'still here');
		same(null, header_value($headers, 'X-Raster-Template-Errors'));
	});
	// a block that is never closed can't be rendered at all
	with_file("$views/zz-broken.html", '<p><!-- render.cafe.hours -->unclosed</p>', function () use ($loud) {
		list($status, $body) = http('GET', "$loud/zz-broken");
		same(500, $status);
		has($body, '<h1>Something burnt</h1>', 'the café\'s own 500 page (error_document_500)');
		lacks($body, 'render.cafe.hours', 'no details for visitors');
	});
	// any exception, not only template errors: a named query that doesn't exist
	test_fixture("$root/demo/models/zzquery"); @mkdir("$root/demo/models/zzquery");
	try {
		with_file("$root/demo/models/zzquery/zzquery.php", '<?php class zzquery { function rows() { return database::instance("zzquery")->no_such_query(); } }', function () use ($loud, $views, $tmp) {
			with_file("$views/zz-query.html", '<p><!-- render.zzquery.rows -->row<!-- /render.zzquery.rows --></p>', function () use ($loud, $tmp) {
				list($status, $body) = http('GET', "$loud/zz-query");
				same(500, $status);
				has($body, '<h1>Something burnt</h1>');
				lacks($body, 'no_such_query', 'no details for visitors');
				has(file_get_contents("$tmp/php-errors.log"), 'Raster error: BadMethodCallException', 'logged');
			});
		});
	} finally {
		@rmdir("$root/demo/models/zzquery");
	}
});
test(array('E23', 'B8'), 'raster_page_size for collections without their own, feed_limit', function () use ($base) {
	for ($i = 1; $i <= 5; $i++) mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => "Note $i", 'author' => 'Dan')));
	$total = mcp($base, 'list_items', array('collection' => 'journal'))['total'];
	check($total > 5 && $total <= 10, "journal has $total items");
	same(5, substr_count(http('GET', "$base/journal")[1], '<article class="post">'), 'raster_page_size is 5');
	same($total - 5, substr_count(http('GET', "$base/journal/journal_page/2")[1], '<article class="post">'));
	$doc = new DOMDocument();
	$doc->loadXML(http('GET', "$base/journal.rss")[1]);
	same(5, $doc->getElementsByTagName('item')->length, 'feed_limit is 5');
});
test('E25', 'an item page falls back to the collection view', function () use ($base) {
	http('GET', "$base/faq");
	mcp($base, 'create_item', array('collection' => 'faq', 'fields' => array('question' => 'Do you have oat milk?', 'answer' => '<p>Always.</p>')));
	list($status, $body) = http('GET', "$base/faq/faq_item/is-there-wifi");
	same(200, $status);
	has($body, 'Is there wifi?');
	lacks($body, 'oat milk', 'only that item');
	has(http('GET', "$base/faq/faq_item/do-you-have-oat-milk")[1], '<p>Always.</p>');
	same(404, http('GET', "$base/faq/faq_item/nope")[0]);
});
test('E26', 'the built-in editor login page and logging out', function () use ($base, $views) {
	rename("$views/login.html", "$views/login.html.off");
	try {
		list($status, $body) = http('GET', "$base/login");
		same(200, $status);
		has($body, '<title>Raster CMS Login</title>');
		list($status, , $headers) = http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'staff@cafe.test', 'password' => 'staff password'));
		same(303, $status);
		check(cookie_from($headers) !== '', 'logged in');
		has(http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'staff@cafe.test', 'password' => 'wrong password'))[1], 'Wrong username or password.');
	} finally {
		rename("$views/login.html.off", "$views/login.html");
	}
	same("$base/api/cms/style/output/true", json_decode(http('GET', "$base/api/cms/style")[1], true));
	list(, $css, $headers) = http('GET', "$base/api/cms/style/output/true");
	has(header_value($headers, 'Content-Type'), 'text/css');
	check(strlen($css) > 100, 'the login style');
	// the toolbar's Log out posts with the token
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$token = token_in(http('GET', "$base/about", null, array("Cookie: $staff"))[1]);
	http('GET', "$base/api/cms/logout", null, array("Cookie: $staff"));
	same(200, http('GET', "$base/staff", null, array("Cookie: $staff"))[0], 'GET does not log out');
	same(403, http('POST', "$base/api/cms/logout", array('x' => 1), array("Cookie: $staff"))[0], 'the token is needed');
	same(200, http('POST', "$base/api/cms/logout", array('csrf' => $token), array("Cookie: $staff"))[0]);
	same(303, http('GET', "$base/staff", null, array("Cookie: $staff"))[0], 'logged out');
});
test(array('G19', 'G20', 'G21'), 'login_page, usernames, raster user defaults', function () use ($base, $db, $maildir) {
	$k = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_LOGIN_PAGE' => 'visit'));
	same("$k/visit?next=%2Fmembers", header_value(http('GET', "$k/members")[2], 'Location'));
	same(0, raster(array('user', 'barista', '--role=member', '--password=barista password'))[0]);
	$cookie = login($base, 'barista', 'barista password');
	same(200, http('GET', "$base/members", null, array("Cookie: $cookie"))[0], 'logged in by username');
	list($code, $out) = raster(array('user', 'owner@cafe.test'));
	same(0, $code, $out);
	check(preg_match("/saved as admin\\. Password: ([a-f0-9]{18})/", $out, $m), "admin with a random password: $out");
	$owner = login($base, 'owner@cafe.test', $m[1]);
	has(http('GET', "$base/members", null, array("Cookie: $owner"))[1], 'You run this place.');
});
test(array('I6', 'I7'), 'mail: the default log folder and PHP mail()', function () use ($root, $tmp) {
	$send = function ($env, $ini = '') use ($root) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli();'
			.' echo mail::send_view("_email/contact_received", "owner@cafe.test", array("email" => "a@b.co", "message" => "Hello there")) ? "sent" : "failed: ".mail::$last_error;';
		return shell_exec($env.' '.php_cmd().' '.$ini.' -r '.escapeshellarg($code).' 2>&1');
	};
	$before = count(glob("$root/demo/data/mail/*.eml") ?: array());
	same('sent', $send('RASTER_MAIL='));
	same($before + 1, count(glob("$root/demo/data/mail/*.eml") ?: array()), 'development logs to data/mail');
	// a sendmail that keeps what it is given (and takes the -f it is passed)
	$out = "$tmp/sendmail.txt";
	file_put_contents("$tmp/sendmail-keep.sh", "#!/bin/sh\ncat >> ".escapeshellarg($out)."\n");
	chmod("$tmp/sendmail-keep.sh", 0755);
	same('sent', $send('RASTER_MAIL=mail://', '-d '.escapeshellarg("sendmail_path=$tmp/sendmail-keep.sh")));
	$message = file_get_contents($out);
	has($message, 'To: owner@cafe.test');
	has($message, 'From: =?UTF-8?B?'.base64_encode('Raster Café').'?= <hello@cafe.test>');
	has($message, 'Subject: ');
});
test(array('J7', 'J8'), 'a language per domain, the cookie name', function () use ($base) {
	has(http('GET', "$base/lab", null, array('Host: ro.localhost'))[1], '>Meniu</a>');
	has(http('GET', "$base/lab", null, array('Host: localhost'))[1], '>Menu</a>');
	$headers = http('GET', "$base/lab?lang=ro")[2];
	has(implode("\n", $headers), 'Set-Cookie: cafe_lang=ro');
	lacks(implode("\n", $headers), 'Set-Cookie: lang=');
});
test('J7', 'a language per domain with the site address set (#96)', function () use ($db, $maildir) {
	$port = free_port();
	$k = server($port, array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'RASTER_URL' => "http://localhost:$port/"));
	// the site's own domain_language domain keeps its language and its links
	$body = http('GET', "$k/lab", null, array("Host: ro.localhost:$port"))[1];
	has($body, '>Meniu</a>');
	has($body, "href=\"http://ro.localhost:$port/menu\"");
	same(200, http('POST', "$k/visit", array('raster_form' => 'contact.form', 'email' => ''), array("Host: ro.localhost:$port", "Origin: http://ro.localhost:$port"))[0], 'a form sent from that domain');
	// any other host gets the site's address
	$body = http('GET', "$k/lab", null, array('Host: evil.example'))[1];
	has($body, "href=\"http://localhost:$port/menu\"");
	lacks($body, 'evil.example');
	// a made-up subdomain of it is another host
	lacks(http('GET', "$k/lab", null, array("Host: evil.ro.localhost:$port"))[1], 'evil.ro.localhost');
});
test('L6', 'site_url in config', function () use ($db, $maildir) {
	$k = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_SITE_URL' => 'https://knob.example/'));
	$body = http('GET', "$k/about", null, array('Host: evil.example'))[1];
	has($body, 'href="https://knob.example/menu"');
	lacks($body, 'evil.example');
});
test(array('M7', 'M8', 'M9'), 'MCP: invalid requests, versions, slugs, the token in config', function () use ($base, $db, $maildir) {
	$call = function ($url, $token, $payload) {
		return json_decode(http('POST', "$url/mcp", json_encode($payload), array("Authorization: Bearer $token", 'Content-Type: application/json'))[1], true);
	};
	same(-32600, $call($base, 'demo-token', array('jsonrpc' => '2.0', 'id' => 9))['error']['code']);
	same('2025-06-18', $call($base, 'demo-token', array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array('protocolVersion' => '1999-01-01')))['result']['protocolVersion'], 'unknown versions get the newest');
	$item = mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Slug me', 'author' => 'Ana')));
	same('a-better-slug', mcp($base, 'update_item', array('collection' => 'journal', 'id' => $item['id'], 'fields' => array('slug' => 'a-better-slug')))['slug']);
	has(http('GET', "$base/journal/journal_item/a-better-slug")[1], 'Slug me');
	mcp($base, 'update_item', array('collection' => 'journal', 'id' => $item['id'], 'fields' => array('enabled' => '0')));
	same(404, http('GET', "$base/journal/journal_item/a-better-slug")[0], 'enabled is writable');
	mcp($base, 'delete_item', array('collection' => 'journal', 'id' => $item['id']));
	$k = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_MCP_TOKEN' => 'knob-token'));
	same(array(), $call($k, 'knob-token', array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'))['result']);
	same(401, http('POST', "$k/mcp", '{}', array('Authorization: Bearer demo-token'))[0]);
});
test(array('F7', 'H12'), 'schema --drop --force, send without a title', function () use ($base) {
	list($code, $out) = raster(array('schema', '--drop=faqpage.heading', '--force'));
	same(0, $code, $out);
	has(raster(array('schema'))[1], 'heading');
	same(1, raster(array('schema', '--check'))[0], 'the templates still want it');
	http('GET', "$base/faq");
	same(0, raster(array('schema', '--check'))[0], 'development adds it back');
	list($code, $out) = raster(array('send', '/hours.txt', '--dry-run'), array('RASTER_URL' => "$base/"));
	same(2, $code, $out);
	has($out, 'The page has no <title>');
});


// ## Events: models talking to each other

function demo_echo($payload) {
	return isset($payload['stop']) ? false : $payload;
}
test(array('P2', 'C38', 'C39'), 'a guest books; emails and the newsletter follow through booking.requested', function () use ($base) {
	$day = next_open('wed');
	$book = function ($newsletter, $time) use ($base, $day) {
		$fields = array('name' => 'Ioana', 'email' => 'ioana@example.com', 'phone' => '0721 000 001', 'date' => $day, 'time' => $time);
		if ($newsletter) $fields['newsletter'] = 'yes';
		list($status, , $headers) = book($base, $fields);
		same(303, $status);
		same("$base/visit?done=booked", header_value($headers, 'Location'));
	};
	$before = count(mails());
	$book(false, '19:00');
	$sent = array_slice(mails(), $before);
	same(array('ioana@example.com', 'staff@cafe.test'), array_column($sent, 'to'), 'the guest\'s request and staff\'s, no newsletter');
	check((bool)preg_match('#'.preg_quote($base, '#').'/booking\?token=[a-f0-9]{64}#', $sent[0]['html']), 'the guest\'s email has their link: '.$sent[0]['text']);
	lacks($sent[1]['html'], 'token=', 'staff\'s has none');
	$book(true, '21:00');
	$sent = array_slice(mails(), $before + 2);
	same(3, count($sent));
	same(array('ioana@example.com', 'staff@cafe.test', 'ioana@example.com'), array_column($sent, 'to'));
	same('Confirm your Raster Café letters', $sent[2]['subject'], 'then the newsletter\'s confirmation');
	database::instance('cms');
	$subscriber = R::findOne('subscriber', ' email = ? ', array('ioana@example.com'));
	same('pending', $subscriber->status);
	same('/visit', $subscriber->source);
	same('Ioana', $subscriber->name);
	$events = mcp($base, 'site_overview')['events'];
	same(array('booking.requested_mail', 'cafe.subscribe_guest'), $events['booking.requested'], 'the booking model\'s own email, a framework binding a site may unbind, and the café\'s');
	same(array('cafe.count_feed'), $events['executed_feed_items']);
	check(!isset($events['launch']), 'the framework\'s own plumbing is left out');
	// a phone booking staff take with the box ticked subscribes nobody
	$count = (int)R::count('subscriber');
	staff_booking(array('name' => 'Phone', 'email' => 'phone@example.com', 'date' => $day, 'newsletter' => true));
	same($count, (int)R::count('subscriber'), 'who is staff');
});
test(array('C34', 'C40'), 'payloads, vetoes, unbinding; executed_ events use the model\'s name and carry the result', function () use ($base) {
	event::bind('demo.ping')->to(null, 'demo_echo');
	same(true, event::dispatch('demo.ping', array('n' => 1)));
	same(array('n' => 1), event::result('demo.ping', '', 'demo_echo'), 'the listener got the payload');
	same(false, event::dispatch('demo.ping', array('stop' => true)), 'a listener returning false');
	event::unbind('demo.ping')->from(null, 'demo_echo');
	same(true, event::dispatch('demo.ping', array('stop' => true)), 'unbound');
	list(, $rss, $headers) = http('GET', "$base/journal.rss");
	$doc = new DOMDocument();
	$doc->loadXML($rss);
	same((string)$doc->getElementsByTagName('item')->length, header_value($headers, 'X-Cafe-Feed-Items'), 'executed_feed_items, though the_feed answered');
});
test('C41', 'the bundled models send events from every path', function () use ($base, $root) {
	@unlink("$root/demo/data/changes.log");
	$item = mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Evented', 'author' => 'Ana')));
	mcp($base, 'update_item', array('collection' => 'journal', 'id' => $item['id'], 'fields' => array('title' => 'Evented again')));
	mcp($base, 'delete_item', array('collection' => 'journal', 'id' => $item['id']));
	same("journal {$item['id']} created\njournal {$item['id']} updated\njournal {$item['id']} deleted\n", file_get_contents("$root/demo/data/changes.log"), 'cms.item_saved and cms.item_deleted over MCP');
	same(303, http('POST', "$base/register", array('raster_form' => 'authentication.register', 'name' => 'Eve <b>', 'email' => 'eve@example.com', 'password' => 'long password', 'password_again' => 'long password'))[0]);
	$mail = last_mail();
	same('eve@example.com', $mail['to']);
	same('Welcome to Raster Café', $mail['subject']);
	has($mail['html'], 'Hi Eve &lt;b&gt;,', 'authentication.registered carries the user');
});
test('C42', 'lint checks the event wiring', function () use ($root) {
	$file = "$root/demo/config/the_events.php";
	with_file($file, file_get_contents($file)."\nevent::bind('booking.requested')->to('cafe', 'no_such_method');\nevent::bind('booking.requestd')->to('cafe', 'welcome');\nevent::bind('done')->to('ghost', 'boo');\n", function () {
		list($code, $out) = raster(array('lint'));
		same(1, $code, $out);
		has($out, "'booking.requested' is bound to cafe.no_such_method, which is not a public method of cafe");
		has($out, "Nothing sends 'booking.requestd'");
		has($out, "model 'ghost', which does not exist");
		lacks($out, "Nothing sends 'done'");
	});
	list($code, $out) = raster(array('lint'));
	same(0, $code, $out);
});


test('C43', 'named queries: a missing name is an error, and lint finds it', function () use ($root) {
	same(1, count(database::instance('cafe')->count_category('cakes')), 'a named call');
	// the name is for that call only: the shared instance is left as it was
	database::instance('cafe');
	try {
		database::instance()->count_category('cakes');
		check(false, 'the model name stuck for later calls');
	} catch (BadMethodCallException $e) {
		has($e->getMessage(), "No query named 'count_category': add models/<model>/sql/count_category.sql");
	}
	try {
		database::instance('cafe')->count_categry('coffee');
		check(false, 'a misspelled query should throw');
	} catch (BadMethodCallException $e) {
		has($e->getMessage(), "No query named 'count_categry': add models/cafe/sql/count_categry.sql, or \$queries['count_categry'] in models/sql.php");
	}
	$file = "$root/demo/models/zz_queries/zz_queries.php";
	test_fixture(dirname($file)); @mkdir(dirname($file));
	try {
		file_put_contents($file, "<?php\nclass zz_queries {\n\tfunction a() { return database::instance('cafe')->count_category('coffee'); }\n\tfunction b() { return database::instance()->query('SELECT 1'); }\n\tfunction c() { return database::instance('cafe')->count_categry('coffee'); }\n\tfunction d() { return database::instance()->dish_names('coffee'); }\n\tfunction e() { return database::instance()->nothing_here(); }\n}\n");
		list($code, $out) = raster(array('lint'));
		same(1, $code, $out);
		has($out, "demo/models/zz_queries/zz_queries.php:5:1: error: No query named 'count_categry': add models/cafe/sql/count_categry.sql");
		has($out, "demo/models/zz_queries/zz_queries.php:7:1: error: No query named 'nothing_here': add models/zz_queries/sql/nothing_here.sql");
		lacks($out, "'count_category'", 'the SQL file exists');
		lacks($out, "'dish_names'", 'models/sql.php has it');
		lacks($out, "'query'", 'database methods are not queries');
	} finally {
		@unlink($file);
		@rmdir(dirname($file));
	}
});

// ## L. Environments, production, cache

test(array('L1', 'L2'), 'environments', function () use ($root) {
	$servers = array('localhost' => 'development', '127\.0\.0\.1' => 'development', 'staging\.cafe\.test' => 'staging');
	same('development', config::environment_for('localhost:8000', '127.0.0.1', false, $servers));
	same('production', config::environment_for('localhost', '10.0.0.5', false, $servers), 'loopback names need a local client');
	// and so does every name under localhost (the café maps ro.localhost)
	$cafe = array('ro\.localhost' => 'development');
	same('production', config::environment_for('ro.localhost', '203.0.113.9', false, $cafe), 'ro.localhost from another machine');
	same('production', config::environment_for('ro.localhost.', '203.0.113.9', false, $cafe + array('ro\.localhost\.' => 'development')), 'with the root dot too');
	same('development', config::environment_for('ro.localhost:8000', '127.0.0.1', false, $cafe), 'ro.localhost from this machine');
	same('staging', config::environment_for('staging.cafe.test', '10.0.0.5', false, $servers));
	same('production', config::environment_for('staging.cafe.test.evil.example', '10.0.0.5', false, $servers), 'whole names only');
	same('production', config::environment_for('cafe.test', '10.0.0.5', false, $servers));
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); echo config::get("environment");';
	same('staging', shell_exec('RASTER_ENV=staging '.php_cmd().' -r '.escapeshellarg($code)));
});
test(array('F3', 'F6', 'I5', 'L3', 'L4', 'L5', 'E13', 'J6'), 'production: frozen schema, trusted address, page cache', function () use ($root, $tmp, $maildir) {
	$prod_db = test_db("$tmp/prod.sqlite");
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => $prod_db, 'RASTER_URL' => 'https://cafe.example/');
	same(1, raster(array('schema', '--check'), $env)[0], 'a new database differs from the templates');
	list($code, $out) = raster(array('schema', '--apply'), $env);
	same(0, $code, $out);
	has($out, 'created table user');
	has($out, 'created table subscriber');
	has($out, 'created table bookingdata', 'the bundled booking model\'s, where views use it');
	same(0, raster(array('schema', '--check'), $env)[0]);
	raster(array('user', 'boss@cafe.example', '--password=boss password'), $env);
	$prod = server(free_port(), array_merge($env, array('RASTER_MAIL' => "log://$maildir")));
	// links use the configured address, whatever the Host header says
	list($status, $body, $headers) = http('GET', "$prod/about", null, array('Host: evil.example'));
	same(200, $status);
	has($body, 'href="https://cafe.example/menu"');
	lacks($body, 'evil.example');
	// the cache (that forged request cached /about under the real address)
	same('hit', header_value(http('GET', "$prod/about")[2], 'X-Raster-Cache'));
	same('miss', header_value(http('GET', "$prod/docs/setup")[2], 'X-Raster-Cache'));
	same('hit', header_value(http('GET', "$prod/docs/setup")[2], 'X-Raster-Cache'));
	same(null, header_value(http('GET', "$prod/about?x=1")[2], 'X-Raster-Cache'), 'query strings are not cached');
	same('miss', header_value(http('GET', "$prod/about", null, array('Cookie: cafe_lang=ro'))[2], 'X-Raster-Cache'), 'one copy per language');
	has(http('GET', "$prod/about", null, array('Cookie: cafe_lang=ro'))[1], '>Meniu</a>');
	$boss = login($prod, 'boss@cafe.example', 'boss password');
	same(null, header_value(http('GET', "$prod/about", null, array("Cookie: $boss"))[2], 'X-Raster-Cache'), 'logged in pages are not cached');
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); cms_store::connect(); cms_store::update_page("aboutpage", "/about", array("heading" => "Cached no more"), array("heading"));';
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($prod_db).' '.php_cmd().' -r '.escapeshellarg($code));
	list(, $body, $headers) = http('GET', "$prod/about");
	same('miss', header_value($headers, 'X-Raster-Cache'), 'edits clear the cache');
	has($body, '<h1>Cached no more</h1>');
	// a scheduled item clears the cache when it is published
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); cms_store::connect(); R::freeze(false); cms_store::save_item("eventsdata", 0, array("title" => "Soon", "date" => "2026-10-20", "published_at" => date("Y-m-d H:i:s", time() + 2)), array("title", "date"));';
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($prod_db).' '.php_cmd().' -r '.escapeshellarg($code));
	http('GET', "$prod/events");
	lacks(http('GET', "$prod/events")[1], 'Soon');
	sleep(3);
	has(http('GET', "$prod/events")[1], 'Soon', 'published on time');
	// frozen: templates ahead of the database show their defaults
	$views = "$root/demo/views/cafe";
	with_file("$views/about.html", str_replace('<h2>Opening hours</h2>', '<p class="motto"><!-- print.cms.motto -->Slow is fine.<!-- /print.cms.motto --></p><h2>Opening hours</h2>', file_get_contents("$views/about.html")), function () use ($prod) {
		has(http('GET', "$prod/about?fresh=1")[1], '<p class="motto">Slow is fine.</p>');
	});
	// emails with links need the site address
	$bare = server(free_port(), array('RASTER_ENV' => 'production', 'RASTER_DB' => $prod_db, 'RASTER_MAIL' => "log://$maildir"));
	$before = count(mails());
	same(303, http('POST', "$bare/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'boss@cafe.example'))[0]);
	same($before, count(mails()), 'no reset link without RASTER_URL');
	has(file_get_contents("$tmp/php-errors.log"), 'Raster error: Password reset email failed', 'the failure is in the error log without log::enable()');
	same(404, http('POST', "$bare/mcp", '{}', array('Authorization: Bearer x'))[0], 'MCP is off without a token');
	// the protocol is part of the cache key
	$key = function ($https) use ($root) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; '.($https ? '$_SERVER["HTTPS"] = "on"; ' : '').'boot::cli("/about"); echo raster_cache::key();';
		return shell_exec('RASTER_URL= '.php_cmd().' -r '.escapeshellarg($code));
	};
	check($key(true) !== $key(false), 'http and https share a cache entry');
});

test(array('L7', 'L3'), 'page cache: skipped paths, time to live, turned off', function () use ($tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir");
	$short = server(free_port(), array_merge($env, array('CAFE_CACHE_TTL' => '1')));
	http('GET', "$short/lab");
	same(null, header_value(http('GET', "$short/lab")[2], 'X-Raster-Cache'), 'page_cache_skip');
	http('GET', "$short/login");
	same(null, header_value(http('GET', "$short/login")[2], 'X-Raster-Cache'), 'the login page is never cached');
	http('GET', "$short/faq");
	same('hit', header_value(http('GET', "$short/faq")[2], 'X-Raster-Cache'));
	sleep(2);
	same('miss', header_value(http('GET', "$short/faq")[2], 'X-Raster-Cache'), 'page_cache_ttl');
	$off = server(free_port(), array_merge($env, array('CAFE_PAGE_CACHE' => 'off')));
	http('GET', "$off/faq");
	same(null, header_value(http('GET', "$off/faq")[2], 'X-Raster-Cache'), 'page_cache off');
});

test('L8', 'page cache: cleared by hand after editing files directly', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token');
	$prod = server(free_port(), $env);
	$faq = "$root/demo/views/cafe/faq.html";
	with_file($faq, str_replace('</body>', '<p>Edited by hand</p></body>', file_get_contents($faq)), function () use ($prod, $env) {
		http('GET', "$prod/faq");
		list(, $body, $headers) = http('GET', "$prod/faq");
		same('hit', header_value($headers, 'X-Raster-Cache'));
		lacks($body, 'Edited by hand', 'a view edited outside Raster is not seen until the cache is cleared');
		list($code, $out) = raster(array('cache', 'clear'), $env);
		same(0, $code, $out);
		has($out, 'Page cache cleared');
		list(, $body, $headers) = http('GET', "$prod/faq");
		same('miss', header_value($headers, 'X-Raster-Cache'), 'raster cache clear');
		has($body, 'Edited by hand');
		same('hit', header_value(http('GET', "$prod/faq")[2], 'X-Raster-Cache'));
		$cleared = mcp($prod, 'clear_cache');
		same(true, $cleared['ok']);
		same(true, $cleared['page_cache']);
		check($cleared['removed'] >= 1, 'clear_cache deletes the cached pages');
		same('miss', header_value(http('GET', "$prod/faq")[2], 'X-Raster-Cache'), 'MCP clear_cache');
		same(true, mcp($prod, 'describe', array('sections' => array('site')))['site']['page_cache'], 'describe says the cache is on');
	});
	same(2, raster(array('cache'), $env)[0], 'cache without clear is a usage error');
	has(raster(array('help'))[1], 'cache clear');
	$init = json_decode(http('POST', "$prod/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize')), array('Authorization: Bearer demo-token'))[1], true);
	has($init['result']['instructions'], 'clear_cache', 'agents are told when to clear the cache');
	same(false, json_decode(raster(array('describe', '--json', '--sections=site'))[1], true)['site']['page_cache'], 'off in development');
});

// ## R. Records: types a model declares, stored and shown by the CMS

test('R1', 'a model declares a type: a collection with its fields, no mock-up row', function () use ($base) {
	$overview = mcp($base, 'site_overview');
	$types = array_values(array_filter($overview['collections'], function ($c) { return $c['name'] === 'booking'; }));
	same('booking', $types[0]['declared_by'], 'the bundled model, through the_booking');
	same(false, $types[0]['public']);
	same(array('confirm', 'decline', 'cancel'), $types[0]['actions']);
	check(in_array('status', $types[0]['fields']) && in_array('people', $types[0]['fields']) && in_array('newsletter', $types[0]['fields']), 'the model\'s fields, the_booking\'s too');
	same(array('staff.html', 'account.html'), array_values(array_intersect(array('staff.html', 'account.html'), $types[0]['used_in'])), 'where views show it');
	$described = array_values(array_filter(mcp($base, 'describe', array('sections' => array('collections')))['collections'], function ($c) { return $c['name'] === 'booking'; }));
	same('visitor', $described[0]['create']);
	same(array('ends', 'status', 'changed_at'), $described[0]['readonly']);
	database::instance('cms');
	foreach (R::find('bookingdata') as $row) check($row->name !== 'Ana' || $row->notes !== 'Window seat', 'the mock-up is never stored as a record');
	list($code, $out) = raster(array('schema'));
	has($out, 'records booking  (declared by the booking model');
	has($out, 'records bookingresource  (declared by the booking model, shown in staff/setup.html)');
});

test(array('R2', 'R4'), 'a form stores a record; visitors never see records, editors see them all', function () use ($base) {
	list($status, , $headers) = book($base, array('name' => 'Radu <em>R</em>', 'email' => 'radu@example.com', 'date' => next_open('thu'), 'occasion' => 'birthday', 'status' => 'confirmed', 'ends' => '23:59', 'owner' => '1'));
	same(303, $status);
	same("$base/visit?done=booked", header_value($headers, 'Location'));
	database::instance('cms');
	$row = R::findOne('bookingdata', ' email = ? ', array('radu@example.com'));
	same('requested', $row->status, 'a visitor can\'t set a readonly field');
	same('21:00', substr((string)$row->ends, 0, 5), 'nor what the model works out');
	same(0, (int)$row->owner, 'nor the owner');
	check($row->created_at !== '', 'records get created_at');
	same('', (string)$row->occasion, 'fields the type does not declare are not stored');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$body = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
	has($body, 'Radu &lt;em&gt;R&lt;/em&gt;', 'what visitors typed prints as text');
	// /staff/bookings lists bookings, and so gives them item URLs; visitors get the login page
	same(303, http('GET', "$base/staff/bookings")[0]);
	list($status, $body) = http('GET', "$base/booking/booking_item/{$row->id}");
	same(303, $status, 'item URLs are not a way in');
	lacks($body, 'Radu');
	same(200, http('GET', "$base/booking/booking_item/{$row->id}", null, array("Cookie: $staff"))[0], 'but staff can open them');
	list($code, $out) = raster(array('render', "/booking/booking_item/{$row->id}"));
	lacks($out, 'Radu', 'and, protected or not, records are private');
	lacks(http('GET', "$base/sitemap.xml")[1], 'booking', 'nor the sitemap');
	with_file(dirname(__DIR__).'/demo/views/cafe/zz-feed.rss', '<rss><channel><!-- render.feed.items(\'booking\') --><item><!-- print.name -->x<!-- /print.name --></item><!-- /render.feed.items(\'booking\') --></channel></rss>', function () use ($base) {
		lacks(http('GET', "$base/zz-feed.rss")[1], 'Radu', 'nor feeds');
	});
	with_file(dirname(__DIR__).'/demo/views/cafe/zz-list.html', '<html><body><!-- render.cms.booking --><p class="booking"><!-- print.name -->x<!-- /print.name --></p><!-- /render.cms.booking --></body></html>', function () use ($base) {
		lacks(http('GET', "$base/zz-list")[1], 'Radu', 'nor a list on a page anyone opens');
		lacks(http('GET', "$base/zz-list/booking_items/status/requested")[1], 'class="booking"', 'nor its filter addresses');
	});
});

test('R3', 'check() runs on every write: the form, the editor, MCP and the model\'s own code', function () use ($base) {
	$day = next_open('fri');
	for ($i = 0; $i < 2; $i++) same(303, book($base, array('name' => "Group $i", 'email' => "group$i@example.com", 'date' => $day, 'people' => '8'))[0]);
	list($status, $body) = book($base, array('name' => 'One too many', 'email' => 'big@example.com', 'date' => $day, 'people' => '5'));
	same(200, $status);
	has($body, 'That sitting is full now.', 'the problem is an alert the template words');
	has($body, 'value="One too many"', 'and the form keeps what was typed');
	database::instance('cms');
	same(2, (int)R::count('bookingdata', ' date = ? ', array($day)));
	$group = R::findOne('bookingdata', ' name = ? ', array('Group 0'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$token = token_in(http('GET', "$base/staff", null, $h)[1]);
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'booking', 'id' => $group->id, 'fields' => array('people' => '8', 'name' => 'Group 0 (8)'), 'csrf' => $token), $h);
	same(200, $status, 'the booking itself is not counted twice');
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'booking', 'id' => 0, 'fields' => array('name' => 'Phone booking', 'date' => $day, 'time' => '19:00', 'resource' => 'dining-room', 'people' => '6'), 'csrf' => $token), $h);
	same(422, $status);
	same(array('fully_booked'), json_decode($body, true)['problems']);
	$refused = json_decode(http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'create_item', 'arguments' => array('collection' => 'booking', 'fields' => array('name' => 'Agent', 'date' => $day, 'time' => '19:00', 'resource' => 'dining-room', 'people' => '6'))))), array('Authorization: Bearer demo-token', 'Content-Type: application/json'))[1], true)['result'];
	check(!empty($refused['isError']), 'MCP is refused too');
	has($refused['content'][0]['text'], 'fully_booked');
	cms_records::forget();
	try {
		staff_booking(array('name' => 'Code', 'date' => $day, 'people' => 5));
		check(false, 'the model\'s own code is checked');
	} catch (cms_refused $e) {
		same(array('fully_booked'), $e->problems);
	}
});

test('R5', 'owners read their own records', function () use ($base) {
	$day = next_open('sat');
	$member = login($base, 'maria@example.com', 'reset password');
	$h = array("Cookie: $member");
	$token = token_in(http('GET', "$base/visit", null, $h)[1]);
	same(303, book($base, array('name' => 'Maria', 'email' => 'maria@example.com', 'date' => $day, 'people' => '3', 'csrf' => $token), $h)[0]);
	$account = http('GET', "$base/account", null, $h)[1];
	has($account, "$day at 19:00, 3 people: requested");
	lacks($account, 'Radu', 'only their own');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$staff_account = http('GET', "$base/account", null, array("Cookie: $staff"))[1];
	lacks($staff_account, "$day at 19:00", 'owner=me: staff see their own bookings on /account, not everyone\'s');
	has(http('GET', "$base/staff", null, array("Cookie: $staff"))[1], 'Maria', 'while /staff lists every booking');
	database::instance('cms');
	$user = R::findOne('user', ' email = ? ', array('maria@example.com'));
	same((int)$user->id, (int)R::findOne('bookingdata', ' date = ? AND name = ? ', array($day, 'Maria'))->owner);
});

test(array('R6', 'R8'), 'readonly fields and actions: buttons for the roles allowed, editor_action, run_action, refusals', function () use ($base) {
	database::instance('cms');
	$booking = R::findOne('bookingdata', ' email = ? ', array('radu@example.com'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$page = http('GET', "$base/staff", null, $h)[1];
	$config = editor_config($page);
	list($id, $mark) = mark_of($config, 'item', function ($m) use ($booking) { return $m['collection'] === 'booking' && $m['id'] === (int)$booking->id; });
	check($id !== null, 'the booking is marked for the editor');
	same(true, $mark['record']);
	same(array('confirm', 'decline', 'cancel'), $mark['actions']);
	check(in_array('status', $mark['readonly']), 'status is shown, not edited');
	list(, $list) = mark_of($config, 'collection', function ($m) { return $m['collection'] === 'booking'; });
	same(true, $list['addable'], 'staff_add: a card for a booking taken over the phone');
	list(, $field) = mark_of($config, 'item_field', function ($m) use ($id) { return $m['item'] === (int)$id && $m['field'] === 'status'; });
	same(true, $field['readonly'], 'the status is marked readonly: shown, updated by actions, never editable');
	$token = token_in($page);
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'booking', 'id' => $booking->id, 'fields' => array('status' => 'confirmed'), 'csrf' => $token), $h);
	same(400, $status);
	has(json_decode($body, true)['error'], "'status' can't be changed here");
	$before = count(mails());
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'booking', 'id' => $booking->id, 'action' => 'confirm', 'csrf' => $token), $h);
	same(200, $status);
	same('confirmed', json_decode($body, true)['status']);
	same(array('Your booking is confirmed'), array_column(array_slice(mails(), $before), 'subject'), 'the guest is told, with a new link');
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'booking', 'id' => $booking->id, 'action' => 'refund', 'csrf' => $token), $h);
	same(400, $status);
	has(json_decode($body, true)['error'], 'no action');
	same('cancelled', mcp($base, 'run_action', array('collection' => 'booking', 'id' => (int)$booking->id, 'action' => 'cancel'))['status']);
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'booking', 'id' => $booking->id, 'action' => 'confirm', 'csrf' => $token), $h);
	same(422, $status);
	same(array('already_cancelled'), json_decode($body, true)['problems']);
	$member = login($base, 'maria@example.com', 'reset password');
	same(403, http('POST', "$base/api/cms/editor_action", array('collection' => 'booking', 'id' => $booking->id, 'action' => 'confirm'), array("Cookie: $member"))[0], 'members have no actions');
	same(404, http('GET', "$base/api/booking/confirm")[0], 'actions are static: /api never reaches them');
	same(404, http('GET', "$base/api/booking/check")[0]);
});

function staff_section($page, $title) {
	$from = strpos($page, "<h3>$title</h3>");
	$to = strpos($page, '<h3>', $from + 1);
	return substr($page, $from, $to === false ? strlen($page) - $from : $to - $from);
}
function booking_names($html) {
	preg_match_all('#<tr class="booking"><td>(?:<!--raster:s \d+-->)?([^<]*)#', preg_replace('#<template data-raster-mockup.*?</template>#s', '', $html), $m);
	return $m[1];
}
function booking_rows($html) { return substr_count(preg_replace('#<template data-raster-mockup.*?</template>#s', '', $html), 'class="booking"'); }
test('R14', 'staff see the requests to come, one day at a time, and every booking filtered by status', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	database::instance('cms');
	$new = R::findOne('bookingdata', ' status = ? AND date >= ? ', array('requested', date('Y-m-d')));
	$cancelled = R::findOne('bookingdata', ' status = ? ', array('cancelled'));
	check($new && $cancelled, 'bookings in both states');
	$page = http('GET', "$base/staff", null, $h)[1];
	$requests = staff_section($page, 'Requests');
	has($requests, util::e($new->name), 'a request to come');
	same((int)R::count('bookingdata', ' status = ? AND date >= ? ', array('requested', date('Y-m-d'))), booking_rows($requests), 'only requests to come');
	lacks($requests, util::e($cancelled->name), 'not the cancelled ones');
	$day = http('GET', "$base/staff?day={$new->date}", null, $h)[1];
	has(staff_section($day, 'Requests'), util::e($new->name));
	same((int)R::count('bookingdata', ' status = ? AND date = ? ', array('requested', $new->date)), booking_rows(staff_section($day, 'Requests')), 'only that day');
	$open = http('GET', "$base/staff/bookings?status=requested", null, $h)[1];
	has($open, util::e($new->name));
	same((int)R::count('bookingdata', ' status = ? ', array('requested')), booking_rows($open), 'only the requests');
	check(booking_rows($open) < (int)R::count('bookingdata'), 'which is not all of them');
	has($open, '<option value="requested" selected>', 'the filter form keeps the choice');
	same(min(50, (int)R::count('bookingdata')), booking_rows(http('GET', "$base/staff/bookings", null, $h)[1]), 'every booking on /staff/bookings, 50 to a page');
	same(303, http('GET', "$base/staff/bookings?status=requested")[0], 'for staff only');
});

test(array('R15', 'D26'), 'list options: filters from the URL, dates, several orders; get forms keep what was asked', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$past = past_open('tue', 2);
	$later = next_open('sat', 6);
	staff_booking(array('name' => 'Past Pia', 'date' => $past));
	staff_booking(array('name' => 'Gone Gus', 'date' => $past, 'time' => '21:00', 'status' => 'cancelled'));
	staff_booking(array('name' => 'Zed Window', 'date' => $later, 'time' => '21:00'));
	staff_booking(array('name' => 'Abe Window', 'date' => $later));
	staff_booking(array('name' => 'Ida Inside', 'date' => $later, 'resource' => 'ana', 'service' => 'cut', 'time' => '10:00'));
	// the Friday before $later: on a Friday, next_open('fri', 6) is a week after next_open('sat', 6)
	$early = next_open('fri', 6);
	if ($early >= $later) $early = next_open('fri', 5);
	staff_booking(array('name' => 'Early Window', 'date' => $early, 'time' => '21:00'));

	$page = http('GET', "$base/staff", null, $h)[1];
	$names = booking_names(staff_section($page, 'Requests'));
	check(!in_array('Past Pia', $names), 'past bookings are not requests to answer: '.implode(', ', $names));
	same(array('Early Window', 'Ida Inside', 'Abe Window', 'Zed Window'), array_values(array_intersect($names, array('Early Window', 'Abe Window', 'Ida Inside', 'Zed Window'))), 'order=date,time');
	$gone = booking_names(staff_section($page, 'Past'));
	check(in_array('Past Pia', $gone), 'date<today lists the past');
	check(!in_array('Gone Gus', $gone), 'status!=cancelled leaves cancelled out');
	foreach ($gone as $name) check(!in_array($name, $names), "$name is not both past and to come");

	$room = http('GET', "$base/staff?resource=dining-room", null, $h)[1];
	$names = booking_names(staff_section($room, 'Requests'));
	check(in_array('Abe Window', $names) && !in_array('Ida Inside', $names), 'resource=?resource filters by the URL');
	has($room, '<option value="dining-room" selected>', 'the get form keeps the choice');
	same(array('Early Window'), booking_names(staff_section(http('GET', "$base/staff?resource=dining-room&day=$early", null, $h)[1], 'Requests')), 'two filters from the URL');
	has(http('GET', "$base/staff?day=$later", null, $h)[1], 'name="day" value="'.$later.'"');
	same(booking_names(staff_section($page, 'Requests')), booking_names(staff_section(http('GET', "$base/staff?resource=", null, $h)[1], 'Requests')), 'an empty parameter filters nothing');

	// the same in code
	$list = cms_store::list_options('status!=cancelled&date<today&people>=2&resource=?resource&order=-date,-time&limit=5');
	same(array(array('status', '!=', 'cancelled'), array('date', '<', date('Y-m-d')), array('people', '>=', '2')), $list['conditions'], 'no ?resource in the URL here');
	same(array('order' => '-date,-time', 'limit' => '5'), $list['options']);
	same('`date` DESC, `name` ASC, id ASC', cms_store::order_sql('-date,name', array('date' => 1, 'name' => 1)));
	same('id ASC', cms_store::order_sql('nope,-nope', array('date' => 1)));
	// pages of a list filtered from the URL keep the query
	has(http('GET', "$base/menu?category=cakes")[1], 'menu_page/2?category=cakes', 'pagination keeps the query');
});
test(array('R16', 'N14', 'M17'), 'staff add bookings on the lists a new one shows in, and agents render as staff', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$config = editor_config(http('GET', "$base/staff?resource=dining-room", null, array("Cookie: $staff"))[1]);
	$lists = array();
	foreach ($config['marks'] as $mark) if ($mark['kind'] === 'collection') $lists[$mark['list']] = $mark;
	$upcoming = $lists['status=requested&date>=today&resource=?resource&date=?day&order=date,time'];
	same(true, $upcoming['addable'], 'staff_add: a card for a new booking');
	same(array('status' => 'requested', 'resource' => 'dining-room'), $upcoming['filters'], 'which starts with the filters the URL gave');
	$info = cms_records::info('booking');
	$info['staff_add'] = false;
	same(false, cms_records::addable($info), 'without staff_add, bookings come from the form only');

	list($code, $out) = raster(array('render', '/staff?resource=dining-room', '--as=editor'));
	same(0, $code, $out);
	has($out, 'class="booking"');
	has($out, "booking('status=requested&date>=today&resource=?resource&date=?day&order=date,time') + new");
	check(strpos(staff_section($out, 'Requests'), 'Ida Inside') === false, 'the query string reached the page');
	list($code, $out) = raster(array('render', '/account', '--as=maria@example.com'));
	same(0, $code, $out);
	has($out, 'In-page editor: not on this page', 'a member gets no editor');
	list($code, $out) = raster(array('render', '/staff'));
	lacks($out, 'class="booking"', 'visitors see no bookings');
	lacks($out, 'In-page editor', 'no summary without --as');
	same(2, raster(array('render', '/staff', '--as=nobody@example.com'))[0], 'an unknown account');
	list($code, $out) = raster(array('render', '/staff', '--as=editor'), array('RASTER_ENV' => 'production', 'RASTER_URL' => 'https://cafe.example/'));
	same(2, $code, 'never in production');
	has($out, '--as only works outside production');
	lacks($out, 'class="booking"');

	$answers = mcp_stdio(array(array('render_url', array('url' => '/staff?resource=dining-room', 'as' => 'editor', 'limit' => 200)), array('render_url', array('url' => '/staff', 'limit' => 200))));
	same('editor', $answers[0]['editor']['role']);
	same(array("booking('status=requested&date>=today&resource=?resource&date=?day&order=date,time') + new", "booking('date<today&status!=cancelled&resource=?resource&date=?day&order=-date,-time&limit=20') + new"), $answers[0]['editor']['lists']);
	check($answers[0]['editor']['items'] > 0, 'the agenda\'s bookings are items too');
	same(null, $answers[0]['errors'], 'the summary is not an error');
	check(!isset($answers[1]['editor']), 'no editor summary for visitors');
});
test('R18', 'a model view of records stays editable: listed() rows, nested in the model\'s rows', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	// the first open day from tomorrow, inside the agenda's seven days
	$soon = date('Y-m-d', strtotime('+1 day'));
	if (date('N', strtotime($soon)) === '1' || substr($soon, 5) === '12-25') $soon = date('Y-m-d', strtotime('+2 days'));
	staff_booking(array('name' => 'Tom Tomorrow', 'date' => $soon, 'time' => '21:00'));
	staff_booking(array('name' => 'Bea <b>Bold</b>', 'date' => $soon, 'time' => '21:00'));
	$page = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
	$config = editor_config($page);
	$tom = array_values(array_filter($config['marks'], function ($m) { return $m['kind'] === 'item' && isset($m['values']['name']) && $m['values']['name'] === 'Tom Tomorrow'; }));
	same(2, count($tom), 'Tom is an item in Requests and in the agenda');
	same('booking', $tom[1]['collection']);
	same(array('confirm', 'decline', 'cancel'), $tom[1]['actions'], 'with the type\'s actions');
	check(in_array('status', $tom[1]['readonly'], true) && !in_array('name', $tom[1]['readonly'], true), 'readonly as in a render.cms list');
	check(in_array('raster_detail_link', array_keys(cms_records::listed('booking', cms_records::find('booking', array('name' => 'Tom Tomorrow')))[0])), 'each row links to its own page');
	$lists = array_filter($config['marks'], function ($m) { return $m['kind'] === 'collection'; });
	same(2, count($lists), 'the agenda adds no lists of its own: new bookings come from render.cms lists');
	$agenda = substr($page, strpos($page, '<h3>By day</h3>'));
	has($agenda, 'class="agenda-booking"');
	has($agenda, 'Bea &lt;b&gt;Bold&lt;/b&gt;', 'listed rows print what visitors typed as text');

	// a value the model adds to a row prints, and is not editable
	$info = cms_records::info('booking');
	check(cms_records::locked($info, 'resource_name') && !cms_records::locked($info, 'name'), 'fields the type does not store are read-only');
	same(array(), array_diff(array_keys(cms_records::listed('booking', array(array('id' => 1, 'name' => 'x')))), array(0)), 'listed() returns the rows and nothing else');

	// the agenda asks find() for the coming days only, so 1,000 past
	// bookings don't push them out (#85)
	database::instance('cms');
	R::begin();
	for ($i = 0; $i < 1001; $i++) R::exec('INSERT INTO bookingdata (name, date, time, ends, people, resource, status) VALUES (?, ?, ?, ?, 1, ?, ?)', array("Past $i", '2020-01-07', '19:00', '21:00', 'dining-room', 'confirmed'));
	R::commit();
	try {
		$page = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
		has(staff_section($page, 'By day'), 'Tom Tomorrow', 'the agenda still shows the coming days');
	} finally {
		R::exec('DELETE FROM bookingdata WHERE date = ? AND name LIKE ?', array('2020-01-07', 'Past %'));
	}
});
test('R19', 'a record\'s own page is where editors edit what a model view links to', function () use ($base) {
	database::instance('cms');
	$abe = R::findOne('bookingdata', ' name = ? ', array('Abe Window'));
	list($code, $out) = raster(array('render', '/booking/booking_item/'.$abe->slug, '--as=editor'));
	same(0, $code, $out);
	has($out, 'Abe Window');
	has($out, '1 items, lists:');
	lacks($out, '+ new', 'no card on an item\'s page');
	list($code, $out) = raster(array('render', '/booking/booking_item/'.$abe->slug));
	// a protected page answers its redirect, as the web does (#195)
	same(1, $code, 'visitors are sent to sign in');
	has($out, 'HTTP 303');
	has($out, '/login?next=%2Fbooking%2Fbooking_item%2F');
	lacks($out, 'Abe Window');
	same(303, http('GET', "$base/booking/booking_item/{$abe->slug}")[0], 'and the page itself is protected (booking_item)');
});
test('R17', 'lint: records nobody lists, and pages listing what a model reads (#125)', function () {
	$inspector = new raster_inspector();
	$views = $inspector->theme_dir();
	$models = APPBASE.'models';
	test_fixture("$models/bookrows"); @mkdir("$models/bookrows");
	try {
		with_file("$models/bookrows/bookrows.php", "<?php\nclass bookrows {\n\tfunction rows() {\n\t\treturn cms_records::find('booking', array(), 'date');\n\t}\n\tfunction counts() { return array(); }\n\tfunction agenda() {\n\t\treturn cms_records::listed('booking', cms_records::find('booking'));\n\t}\n}\n", function () use ($inspector, $views) {
			$problems = $inspector->lint_source('<!-- render.bookrows.rows --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.rows --><!-- render.bookrows.counts --><p>y</p><!-- /render.bookrows.counts -->', 'staff.html');
			check(has_message($problems, "shows booking records the bookrows model reads itself without cms_records::listed()"), 'an admin page listing model rows');
			same(1, count($problems), 'only the method that reads records');
			$problems = $inspector->lint_source('<!-- render.bookrows.agenda --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.agenda -->', 'staff.html');
			same(array(), $problems, 'records handed back through cms_records::listed are fine');
			// any page: a false bool would print the mock-up, and visitor text as HTML (#125)
			$problems = $inspector->lint_source('<!-- render.bookrows.rows --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.rows -->', 'thanks.html');
			check(has_message($problems, 'shows booking records the bookrows model reads itself without cms_records::listed(), so a false bool prints the mock-up and what visitors typed prints as HTML'), 'a page for visitors too');
			same(1, count($problems));
			same(array(), $inspector->lint_source('<!-- render.bookrows.agenda --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.agenda -->', 'thanks.html'), 'listed() rows print as records do');
		});
	} finally { @rmdir("$models/bookrows"); }
	$listed = function () use ($inspector) { return has_message($inspector->lint_types(), "Visitors make 'booking' records with a form, but no view lists them"); };
	check(!$listed(), 'the café lists its bookings');
	$originals = array();
	foreach (array('staff.html', 'staff/bookings.html', 'account.html', 'booking_item.html') as $view) $originals[$view] = file_get_contents("$views/$view");
	try {
		foreach ($originals as $view => $html) file_put_contents("$views/$view", str_replace(array('render.cms.booking(', 'render.cms.booking -->'), array('render.cms.elsewhere(', 'render.cms.elsewhere -->'), $html));
		check($listed(), 'a form type no view lists');
	} finally {
		foreach ($originals as $view => $html) file_put_contents("$views/$view", $html);
	}
});
test('M18', 'MCP over stdio: a PHP error in site code answers the call', function () {
	$models = APPBASE.'models';
	test_fixture("$models/stopper"); @mkdir("$models/stopper");
	try {
		with_file("$models/stopper/stopper.php", "<?php\nclass stopper {\n\tstatic function listens() { return array('cms.item_deleted' => 'broken'); }\n\tfunction broken(\$deleted) { echo 'stray output'; return no_such_function(); }\n}\n", function () {
			$item = mcp_stdio(array(array('create_item', array('collection' => 'menu', 'fields' => array('name' => 'Stopper test', 'price' => '1')))));
			check(isset($item[0]['id']), 'other calls work');
			$answers = mcp_stdio(array(array('delete_item', array('collection' => 'menu', 'id' => $item[0]['id'])), array('clear_cache')));
			has($answers[0]['error'], 'Error: Call to undefined function no_such_function()');
			has($answers[0]['error'], 'stopper.php:4');
			check(isset($answers[1]['ok']), 'the server goes on, and what the code printed did not break the answers');
		});
	} finally { @rmdir("$models/stopper"); }
});

test('M20', 'one MCP session sees the fields, collections, methods, api() and types() written during it (#105)', function () use ($views) {
	$cafe = APPBASE.'models/cafe/cafe.php';
	$booking = APPBASE.'models/the_booking/the_booking.php';
	$session = new mcp_session();
	try {
		// the first calls load the models and read the content model
		check(!isset($session->call('site_overview')['error']));
		check(isset($session->call('vocabulary')['models']['cafe']));
		// a field and a collection the view adds can be written at once
		$view = "<h1><!-- print.cms.zzmotto -->Motto<!-- /print.cms.zzmotto --></h1>\n<ul><!-- render.cms.zzperks --><li><!-- print.title -->Perk<!-- /print.title --></li><!-- /render.cms.zzperks --></ul>\n";
		same(true, $session->call('write_view', array('view' => 'zz-motto.html', 'content' => $view))['written']);
		$page = $session->call('update_page', array('page' => '/zz-motto', 'fields' => array('zzmotto' => 'Good coffee')));
		check(!isset($page['error']), 'update_page: '.json_encode($page));
		$item = $session->call('create_item', array('collection' => 'zzperks', 'fields' => array('title' => 'Free refills')));
		check(isset($item['id']), 'create_item: '.json_encode($item));
		// a model gains a method and lists it in api(); a record type gains a field
		$source = file_get_contents($cafe);
		$more = str_replace("'bookings' => 'editor');", "'bookings' => 'editor', 'zzfresh' => 'visitor');\n\t}\n\tfunction zzfresh() {\n\t\treturn 'Fresh today';", $source);
		with_file($cafe, $more, function () use ($session, $booking) {
			with_file($booking, str_replace("\t\treturn \$types;", "\t\t\$types['booking']['fields']['zzallergies'] = '';\n\t\treturn \$types;", file_get_contents($booking)), function () use ($session) {
				$vocabulary = $session->call('vocabulary');
				check(isset($vocabulary['models']['cafe']['methods']['zzfresh']), 'vocabulary lacks the new method');
				same('visitor', ((array)$vocabulary['models']['cafe']['api'])['zzfresh'] ?? null, 'vocabulary has the old api()');
				same(0, $session->call('check_view', array('content' => '<p><!-- print.cafe.zzfresh /--></p>'))['errors'], 'check_view refuses the new method');
				$overview = $session->call('site_overview');
				$fields = array();
				foreach ($overview['collections'] as $c) if ($c['name'] === 'booking') $fields = $c['fields'];
				check(in_array('zzallergies', $fields), 'site_overview has the old types(): '.json_encode($fields));
			});
		});
		// and back as it was
		check(!isset($session->call('vocabulary')['models']['cafe']['methods']['zzfresh']), 'the method is gone again');
	} finally {
		$session->close();
		@unlink("$views/zz-motto.html");
	}
});

test('M20', 'a model with a syntax error during an MCP session: ping answers, other calls get an error, and the fix counts (#194)', function () {
	$cafe = APPBASE.'models/cafe/cafe.php';
	$session = new mcp_session();
	try {
		check(isset($session->call('vocabulary')['models']['cafe']));
		check(!isset($session->call('render_url', array('url' => '/'))['error']));
		// an agent halfway through editing a model
		with_file($cafe, file_get_contents($cafe)."\n}}} broken(\n", function () use ($session) {
			$ping = $session->line('{"jsonrpc":"2.0","id":3,"method":"ping"}', 15);
			check($ping !== null, 'ping got no answer');
			same(3, $ping['id']);
			check(isset($ping['result']), 'ping: '.json_encode($ping));
			$vocabulary = $session->line('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"vocabulary","arguments":{}}}', 15);
			check($vocabulary !== null, 'vocabulary got no answer');
			same(4, $vocabulary['id']);
			same(-32603, $vocabulary['error']['code'] ?? null, json_encode($vocabulary));
			has($vocabulary['error']['message'], 'cafe.php');
			has($vocabulary['error']['message'], 'Parse error');
			same(null, $session->line('{"jsonrpc":"2.0","method":"notifications/initialized"}', 2), 'a notification still gets nothing back');
			$batch = $session->line('[{"jsonrpc":"2.0","id":5,"method":"tools/list"},{"jsonrpc":"2.0","method":"notifications/x"}]', 15);
			same(array(5), array_column((array)$batch, 'id'), 'a batch gets an error for each request: '.json_encode($batch));
		});
		// fixed, the next call answers again
		check(isset($session->call('vocabulary')['models']['cafe']), 'vocabulary after the fix');
	} finally {
		$session->close();
	}
});

// A second model, only while these tests run, for what the café's
// bookings don't need: hidden fields, lists, transactions, public records
$probe_dir = dirname(__DIR__).'/demo/models/probe';
$probe = <<<'PHP'
<?php
class probe {
	static function types() {
		return array('ticket' => array(
			'fields' => array('title' => '', 'lines' => array(), 'secret' => '', 'stock' => 0, 'link' => ''),
			'hidden' => array('secret'),
			'public' => true,
			'create' => 'visitor',
			'surprise' => true,
		));
	}
	function send() {
		return cms_records::submit('ticket', 'sent');
	}
	static function check($type, $after, $before) {
		if ($after && (int)$after['stock'] < 0) cms_records::refuse('sold_out');
	}
	function seen($saved) {
		if ($saved['collection'] === 'ticket') file_put_contents(APPBASE.'data/probe.log', $saved['item']['title']."\n", FILE_APPEND);
	}
}
PHP;
test(array('R7', 'R9', 'R10', 'R11'), 'hidden fields, lists, transactions and lint, with a probe model', function () use ($base, $probe_dir, $probe, $root) {
	test_fixture($probe_dir); @mkdir($probe_dir);
	$log = "$root/demo/data/probe.log";
	@unlink($log);
	try {
		with_file("$probe_dir/probe.php", $probe, function () use ($base, $log, $root, $probe_dir) {
			cms_records::forget();
			require_once "$probe_dir/probe.php";
			event::bind('cms.item_saved')->to('probe', 'seen');
			$ticket = cms_records::create('ticket', array('title' => 'Mugs', 'stock' => 1, 'secret' => 'tok_123', 'lines' => array(array('name' => '<i>Mug</i>', 'qty' => 2))));
			same(array(array('name' => '<i>Mug</i>', 'qty' => 2)), $ticket['lines'], 'lists come back as lists');
			same('tok_123', $ticket['secret'], 'the model sees hidden fields');
			// a public URL never carries a hidden field, even when it is the
			// first text a record has
			$fair = cms_records::create('ticket', array('secret' => 'ana@example.com', 'link' => 'Spring fair'));
			$bare = cms_records::create('ticket', array('secret' => 'tok_456'));
			cms_records::delete('ticket', $fair['id']);
			cms_records::delete('ticket', $bare['id']);
			same('spring-fair', $fair['slug'], 'the slug skips hidden fields:');
			same('item', $bare['slug'], 'with only a hidden field, the slug is item:');
			same('24.00', cms_records::create('ticket', array('title' => '24.00'))['title'], 'text fields keep what they are given');
			$shown = mcp($base, 'get_item', array('collection' => 'ticket', 'id' => $ticket['id']));
			check(!array_key_exists('secret', $shown), 'MCP never sees hidden fields');
			same('<i>Mug</i>', $shown['lines'][0]['name']);
			try {
				mcp($base, 'update_item', array('collection' => 'ticket', 'id' => $ticket['id'], 'fields' => array('secret' => 'x')));
				check(false, 'MCP cannot write a hidden field');
			} catch (Exception $e) { has($e->getMessage(), "'secret' can't be changed here"); }
			// all or nothing, and events wait for the commit
			file_put_contents($log, '');
			try {
				cms_records::transaction(function () use ($ticket, $log) {
					cms_records::update('ticket', $ticket['id'], array('stock' => 0));
					cms_records::create('ticket', array('title' => 'Rolled back', 'stock' => 5));
					same('', file_get_contents($log), 'no event before the commit');
					cms_records::update('ticket', $ticket['id'], array('stock' => -1));
				});
				check(false, 'the transaction should have been refused');
			} catch (cms_refused $e) { same(array('sold_out'), $e->problems); }
			same(1, (int)cms_records::get('ticket', $ticket['id'])['stock'], 'rolled back');
			same(array(), cms_records::find('ticket', array('title' => 'Rolled back')));
			same('', file_get_contents($log), 'and nothing was announced');
			cms_records::transaction(function () use ($ticket) {
				cms_records::update('ticket', $ticket['id'], array('stock' => 0));
				cms_records::create('ticket', array('title' => 'Kept', 'stock' => 5));
			});
			same("Mugs\nKept\n", file_get_contents($log), 'events after the commit');
			// lists render as nested rows; public records show to visitors
			$views = "$root/demo/views/cafe";
			with_file("$views/zz-tickets.html", "<html><body><!-- render.cms.ticket('order=oldest') --><h2><!-- print.title -->T<!-- /print.title --></h2><!-- print.lines --><p><!-- print.name -->n<!-- /print.name --> x<!-- print.qty -->1<!-- /print.qty --></p><!-- /print.lines --><!-- /render.cms.ticket('order=oldest') --></body></html>", function () use ($base) {
				$body = http('GET', "$base/zz-tickets")[1];
				has($body, '<h2>Mugs</h2><p>&lt;i&gt;Mug&lt;/i&gt; x2</p>', 'nested rows, escaped');
				lacks($body, 'tok_123');
				$staff = login($base, 'staff@cafe.test', 'staff password');
				lacks(http('GET', "$base/zz-tickets", null, array("Cookie: $staff"))[1], 'tok_123', 'the editor never gets hidden values');
			});
			// a form that leaves fields out keeps the type's defaults; a link a
			// visitor typed can't run script; hidden fields are no filter
			with_file("$views/zz-ticket-form.html", "<html><body><!-- render.probe.send --><form method=\"post\"><input name=\"title\" required><input name=\"link\"><button>Go</button></form><!-- /render.probe.send --><!-- render.cms.ticket('title=Form') --><!-- print.@href.link --><a class=\"t\" href=\"#\">x</a><!-- /print.@href.link --><!-- /render.cms.ticket('title=Form') --></body></html>", function () use ($base) {
				same(303, http('POST', "$base/zz-ticket-form", array('raster_form' => 'probe.send', 'title' => 'Form', 'link' => 'javascript:alert(1)'))[0]);
				$row = cms_records::find('ticket', array('title' => 'Form'))[0];
				same(0, (int)$row['stock'], 'the default');
				same('[]', json_encode($row['lines']));
				$page = http('GET', "$base/zz-ticket-form")[1];
				has($page, '>x</a>', 'the record is shown');
				lacks($page, 'javascript:', 'but not a script link a visitor typed');
			});
			with_file("$views/ticket.html", "<html><body><!-- render.cms.ticket --><p><!-- print.title -->T<!-- /print.title --></p><!-- /render.cms.ticket --></body></html>", function () use ($base) {
				same(http('GET', "$base/ticket/ticket_items/secret/nope")[1], http('GET', "$base/ticket/ticket_items/secret/tok_123")[1], 'hidden fields are no filter for visitors');
				has(http('GET', "$base/ticket/ticket_items/title/Mugs")[1], '<p>Mugs</p>', 'other fields are');
				lacks(http('GET', "$base/ticket/ticket_items/title/Kept")[1], '<p>Mugs</p>');
			});
			with_file("$views/zz-tickets.rss", "<rss><channel><!-- render.feed.items('ticket') --><item><title><!-- print.title -->t<!-- /print.title --></title><!-- print.secret -->s<!-- /print.secret --></item><!-- /render.feed.items('ticket') --></channel></rss>", function () use ($base) {
				$feed = http('GET', "$base/zz-tickets.rss")[1];
				has($feed, '<title>Mugs</title>', 'a public type is in feeds');
				lacks($feed, 'tok_123', 'without its hidden fields');
			});
			// a model with an ordinary types() method declares nothing, and breaks nothing
			$plain = dirname($probe_dir).'/zzplain';
			test_fixture($plain); @mkdir($plain);
			try {
				with_file("$plain/zzplain.php", "<?php\nclass zzplain { function types() { return array('widget' => array('fields' => array('a' => ''))); } }\n", function () use ($base) {
					same(200, http('GET', "$base/menu")[0]);
					$names = array_map(function ($c) { return $c['name']; }, mcp($base, 'site_overview')['collections']);
					check(!in_array('widget', $names), 'not a type');
				});
			} finally { @rmdir($plain); }
			// lint: static hooks, action methods, keys that mean nothing
			$problems = (new raster_inspector())->lint_types();
			check(has_message($problems, "The type 'ticket' has 'surprise', which means nothing"), 'unknown keys');
			with_file("$probe_dir/probe.php", str_replace(array("'surprise' => true,", 'static function check(', "'link' => ''"), array("'actions' => array('ship' => 'editor', 'check' => 'admin'),", 'function check(', "'link' => '', 'pay' => '', 'pay_id' => ''"), file_get_contents("$probe_dir/probe.php")), function () use ($probe_dir) {
				$lint = shell_exec(php_cmd().' '.escapeshellarg(dirname($probe_dir, 3).'/bin/raster').' lint 2>&1');
				has($lint, 'probe::check() must be static');
				has($lint, "has the action 'ship' but probe has no public static function ship(");
				has($lint, "'check' can't be an action of 'ticket'");
				has($lint, "has 'pay' and 'pay_id'");
			});
		});
	} finally {
		@unlink($log);
		@rmdir($probe_dir);
		cms_records::forget();
		database::instance('cms');
		if (cms_store::table_exists('ticketdata')) R::exec('DROP TABLE ticketdata');
	}
});

test('R5', 'owner=me on a public type lists only the viewer\'s own records, members included (#89)', function () use ($base, $root) {
	$dir = "$root/demo/models/zzreviews";
	$views = "$root/demo/views/cafe";
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/zzreviews.php", "<?php\nclass zzreviews {\n\tstatic function types() {\n\t\treturn array('zzreview' => array('fields' => array('title' => '', 'email' => ''), 'hidden' => array('email'), 'public' => true, 'owner' => true));\n\t}\n}\n", function () use ($base, $views) {
			cms_records::forget();
			database::instance('cms');
			login($base, 'maria@example.com', 'reset password');
			$maria = (int)R::findOne('user', ' email = ? ', array('maria@example.com'))->id;
			$staff = (int)R::findOne('user', ' email = ? ', array('staff@cafe.test'))->id;
			cms_records::create('zzreview', array('title' => 'Maria one', 'email' => 'm@example.com', 'owner' => $maria));
			cms_records::create('zzreview', array('title' => 'Maria two', 'email' => 'm@example.com', 'owner' => $maria));
			cms_records::create('zzreview', array('title' => 'Staff one', 'email' => 's@example.com', 'owner' => $staff));
			with_file("$views/zz-myreviews.html", "<html><body><!-- render.cms.zzreview('owner=me&order=oldest') --><p><!-- print.title -->T<!-- /print.title --></p><!-- /render.cms.zzreview('owner=me&order=oldest') --></body></html>", function () use ($base) {
				$member = login($base, 'maria@example.com', 'reset password');
				$page = http('GET', "$base/zz-myreviews", null, array("Cookie: $member"))[1];
				has($page, '<p>Maria one</p>', 'a member sees their own');
				has($page, '<p>Maria two</p>');
				lacks($page, 'Staff one', 'and not another account\'s');
				$editor = login($base, 'staff@cafe.test', 'staff password');
				$page = http('GET', "$base/zz-myreviews", null, array("Cookie: $editor"))[1];
				has($page, 'Staff one', 'an editor sees their own');
				lacks($page, 'Maria', 'and only their own');
				lacks(http('GET', "$base/zz-myreviews")[1], '<p>', 'a visitor has none');
			});
			with_file("$views/zzreview.html", "<html><body><!-- render.cms.zzreview('order=oldest') --><p><!-- print.title -->T<!-- /print.title --></p><!-- /render.cms.zzreview('order=oldest') --></body></html>", function () use ($base) {
				$member = login($base, 'maria@example.com', 'reset password');
				has(http('GET', "$base/zzreview/zzreview_items/email/nobody@example.com", null, array("Cookie: $member"))[1], '<p>Staff one</p>', 'a URL still can\'t filter by a hidden field');
				has(http('GET', "$base/zzreview/zzreview_items/owner/999", null, array("Cookie: $member"))[1], '<p>Maria one</p>', 'nor by owner');
			});
		});
	} finally {
		@rmdir($dir);
		cms_records::forget();
		database::instance('cms');
		if (cms_store::table_exists('zzreviewdata')) R::exec('DROP TABLE zzreviewdata');
	}
});

test(array('R12', 'R13'), 'schema --apply creates record tables in production; make model writes a model from a form', function () use ($tmp, $root) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/records-prod.sqlite"));
	list($code, $out) = raster(array('schema', '--apply'), $env);
	same(0, $code, $out);
	foreach (array('booking', 'bookingresource', 'bookinghours', 'bookingclosed', 'bookingservice', 'contactmessage') as $type) has($out, "created table {$type}data");
	$pdo = test_pdo($env['RASTER_DB']);
	same(0, (int)$pdo->query('SELECT COUNT(*) FROM bookingdata')->fetchColumn(), 'no row left behind');
	same(0, (int)$pdo->query('SELECT COUNT(*) FROM bookingresourcedata')->fetchColumn(), 'nothing to book until demo/seed.php');
	$columns = test_columns($pdo, 'bookingdata');
	foreach (array('status', 'people', 'ends', 'token_hashes', 'newsletter', 'owner', 'created_at', 'enabled', 'slug') as $column) check(in_array($column, $columns), "column $column");
	list($code, $out) = raster(array('schema', '--check'), $env);
	same(0, $code, $out);
	// a fresh demo takes bookings once it is seeded, and seeding again adds nothing
	list($code, $out) = raster_php_env("$root/demo/seed.php", $env);
	same(0, $code, $out);
	has($out, 'added bookingresource Dining room');
	same(3, (int)$pdo->query('SELECT COUNT(*) FROM bookingresourcedata')->fetchColumn());
	// and its two evenings take replies, beside the mock-up's past one
	has($out, "added event Jazz night\nadded event Poetry evening");
	$coming = $pdo->prepare('SELECT title, places FROM eventsdata WHERE date >= ? ORDER BY date');
	$coming->execute(array(date('Y-m-d')));
	same(array('Poetry evening' => '4', 'Jazz night' => '40'), array_map('strval', $coming->fetchAll(PDO::FETCH_KEY_PAIR)));
	same(array(0, ''), raster_php_env("$root/demo/seed.php", $env));
	// a catering request form, as a type
	$views = "$root/demo/views/cafe";
	// a model that exists is not written over
	with_file("$views/zz-exists.html", '<html><body><!-- render.cafe.ask --><form method="post"><input name="email"><button>Ask</button></form><!-- /render.cafe.ask --></body></html>', function () {
		list($code, $out) = raster(array('make', 'model', 'cafe', '--from=zz-exists'));
		same(1, $code);
		has($out, 'exists; --force replaces it');
	});
	$code_out = with_file("$views/zz-inquiry.html", '<html><body><form method="get"><input name="q"></form><!-- render.inquiry.contact --><form method="post"><input type="email" name="email" required><textarea name="message" required></textarea><button>Ask</button></form><!-- /render.inquiry.contact --></body></html>', function () {
		list($code, $code_out) = raster(array('make', 'model', 'inquiry', '--from=zz-inquiry', '--form=contact', '--dry-run'));
		same(0, $code, $code_out);
		return $code_out;
	});
	has($code_out, "'email' => '',");
	has($code_out, "'message' => '',");
	has($code_out, 'static function check($type, $after, $before)');
	has($code_out, "return cms_records::submit('inquiry', 'inquiry_sent');");
	has($code_out, 'function contact()');
	lacks($code_out, "'name' => ''", 'only the contact form\'s fields');
	check(php_parses($code_out), 'the code parses');
	// each input gives its field a type: decimals, whole numbers, a box, dates and times (#104)
	$party = "$root/demo/models/zzparty";
	test_fixture($party);
	list($typed, $next) = with_file("$views/zz-typed.html", '<html><body><!-- render.zzparty.ask --><form method="post"><input type="number" name="budget" step="0.01"><input type="number" name="guests" min="1"><input type="number" name="hours" step="any"><input type="checkbox" name="newsletter" value="yes"><input type="date" name="day"><input type="time" name="hour"><input type="datetime-local" name="when"><input type="email" name="email" required><button>Ask</button></form><!-- /render.zzparty.ask --></body></html>', function () use ($party) {
		try {
			list($code, $out) = raster(array('make', 'model', 'zzparty', '--from=zz-typed'));
			same(0, $code, $out);
			return array((string)@file_get_contents("$party/zzparty.php"), $out);
		} finally {
			@unlink("$party/zzparty.php");
			@rmdir($party);
		}
	});
	foreach (array("'budget' => 0.0,", "'guests' => 0,", "'hours' => 0.0,", "'newsletter' => false,", "'day' => '',", "'hour' => '',", "'when' => '',", "'email' => '',") as $line) has($typed, $line);
	has($typed, "'types' => array('day' => 'date', 'hour' => 'time', 'when' => 'datetime'),");
	check(php_parses($typed), 'the code parses');
	// the alerts a refused value raises, for the view to word
	foreach (array('budget', 'guests', 'hours', 'day', 'hour', 'when') as $field) has($next, "print.validation.alert('{$field}_invalid')");
	lacks($next, "alert('email_invalid')", 'text is never refused for its type');
	lacks($next, "alert('newsletter_invalid')", 'a box is yes or no');
	list($code, $out) = raster(array('make', 'model', 'Bad!', '--from=visit.html'));
	same(1, $code);
	same(2, raster(array('make', 'model'))[0], 'usage');
});

test('C48', '/api answers only what a model lists, for the roles it names', function () use ($base, $root) {
	// listed for visitors
	same(200, http('GET', "$base/api/cafe/hours")[0]);
	// public methods a model doesn't list are not there, whatever they return
	same(404, http('GET', "$base/api/cafe/stamp")[0], 'an event handler');
	same(404, http('GET', "$base/api/cafe/subscribe_guest")[0], 'a listener');
	same(404, http('GET', "$base/api/contact/form")[0], 'a form handler');
	same(404, http('GET', "$base/api/booking/agenda")[0], 'a bundled model\'s staff region');
	// an override is only reached by the name it overrides, and that name
	// only when api_system_models lists it
	same(404, http('GET', "$base/api/the_feed/generator")[0], 'never addressed as the_<model>');
	same(404, http('GET', "$base/api/feed/generator")[0]);
	// listed for staff: one day's bookings, private records
	$day = next_open('fri');
	list($status, $body) = http('GET', "$base/api/cafe/bookings/$day");
	same(401, $status, 'visitors are asked to log in');
	lacks($body, 'Group');
	$member = login($base, 'maria@example.com', 'reset password');
	same(403, http('GET', "$base/api/cafe/bookings/$day", null, array("Cookie: $member"))[0], 'members are not staff');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	list($status, $body) = http('GET', "$base/api/cafe/bookings/$day", null, array("Cookie: $staff"));
	same(200, $status);
	$rows = json_decode($body, true);
	check((bool)preg_grep('/^Group /', array_column($rows, 'name')), 'staff get the bookings');
	check(!array_key_exists('token_hashes', $rows[0]), 'without the guests\' links');
	// staff methods refuse what another site started, on every verb (#122)
	foreach (array('Origin: https://evil.test', 'Origin: null', 'Sec-Fetch-Site: cross-site', 'Referer: https://evil.test/') as $header) {
		list($status, $body) = http('GET', "$base/api/cafe/bookings/$day", null, array("Cookie: $staff", $header));
		same(403, $status, "staff GET with $header");
		same(array('error' => 'cross_site', 'problems' => array('cross_site')), json_decode($body, true));
	}
	same(200, http('GET', "$base/api/cafe/hours", null, array('Origin: https://evil.test'))[0], 'a visitor method answers other sites');
	// the vocabulary tells agents what each model offers
	$vocabulary = json_decode(raster(array('vocabulary', '--json'))[1], true);
	same(array('hours' => 'visitor', 'category_count' => 'visitor', 'bookings' => 'editor'), $vocabulary['models']['cafe']['api']);
	check(empty($vocabulary['models']['booking']['api']), 'the booking model has no /api: guests use forms, staff the editor and MCP');
	same(array(), $vocabulary['models']['secret']['api'], 'a model that lists nothing offers nothing');
	same('visitor', $vocabulary['models']['cms']['api']['editor_save_field'], 'the vocabulary lists what cms offers');
	check(!isset($vocabulary['models']['cms']['api']['setup']), 'and nothing it doesn\'t list');
	check(!isset($vocabulary['models']['feed']['api']), 'bundled models /api doesn\'t reach list nothing');
	has(raster(array('vocabulary'))[1], '/api: hours (visitor), category_count (visitor), bookings (editor)');
	// a model that lists nothing offers nothing
	$dir = "$root/demo/models/zzopen";
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/zzopen.php", "<?php\nclass zzopen { function ping() { return 'pong'; } }\n", function () use ($base) {
			lacks(raster(array('render', '/api/zzopen/ping'))[1], 'pong', 'closed');
			same(404, http('GET', "$base/api/zzopen/ping")[0]);
			// a member method: members yes, visitors asked to log in
			file_put_contents(__DIR__.'/../demo/models/zzopen/zzopen.php', "<?php\nclass zzopen {\n\tstatic function api() { return array('ping' => 'member'); }\n\tfunction ping() { return 'pong'; }\n}\n");
			same(401, http('GET', "$base/api/zzopen/ping")[0]);
			$member = login($base, 'maria@example.com', 'reset password');
			same('"pong"', http('GET', "$base/api/zzopen/ping", null, array("Cookie: $member"))[1]);
			// an entry without a role offers nothing, and lint says so
			file_put_contents(__DIR__.'/../demo/models/zzopen/zzopen.php', "<?php\nclass zzopen {\n\tstatic function api() { return array('ping', 'editor'); }\n\tfunction ping() { return 'pong'; }\n\tfunction editor() { return 1; }\n}\n");
			same(404, http('GET', "$base/api/zzopen/ping")[0], 'a forgotten => never opens a method');
			same(404, http('GET', "$base/api/zzopen/editor")[0]);
			has(raster(array('lint'))[1], "zzopen::api() lists 'ping' without a role");
			// lint checks what api() offers
			file_put_contents(__DIR__.'/../demo/models/zzopen/zzopen.php', "<?php\nclass zzopen {\n\tstatic function api() { return array('ping' => 'visitor', 'nope' => 'visitor', 'hidden' => 'visitor', 'odd' => 'boss'); }\n\tfunction ping() { return 'pong'; }\n\tstatic function hidden() { return 1; }\n\tfunction odd() { return 1; }\n}\n");
			list($code, $out) = raster(array('lint'));
			same(1, $code);
			has($out, "zzopen::api() offers 'nope', but zzopen has no such method");
			has($out, "zzopen::api() offers 'hidden', which /api can't call");
			has($out, "zzopen::api() gives 'odd' the role 'boss'");
			lacks($out, "offers 'ping'");
			file_put_contents(__DIR__.'/../demo/models/zzopen/zzopen.php', "<?php\nclass zzopen {\n\tfunction api() { return array(); }\n}\n");
			has(raster(array('lint'))[1], 'zzopen::api() must be static');
		});
	} finally {
		@rmdir($dir);
	}
});

// ## T. Field types

test('T1', 'a template field is of its mock-up\'s type, a record field of its default\'s or the one types() names; schema, describe and site_overview say so', function () use ($base) {
	http('GET', "$base/menu");
	http('GET', "$base/events");
	$schema = json_decode(raster(array('schema', '--json'))[1], true);
	$types = array();
	foreach ($schema['tables'] as $table) {
		if (isset($table['name'])) foreach ($table['fields'] as $name => $field) $types[$table['name']][$name] = $field['type'];
	}
	same('number', $types['menu']['price'], 'price is 14.50 in menu.html');
	same('int', $types['menu']['featured'], 'featured=1 in index.html');
	same('text', $types['menu']['name']);
	same(array('date', 'time'), array($types['events']['date'], $types['events']['starts']));
	same(array('int', 'bool', 'date', 'time', 'text'), array($types['booking']['people'], $types['booking']['newsletter'], $types['booking']['date'], $types['booking']['time'], $types['booking']['name']));
	database::instance('cms');
	cms_store::forget();
	same(on_mysql() ? array('DOUBLE', 'DATE', 'TIME', 'TINYINT(1)') : array('REAL', 'DATE', 'TIME', 'BOOLEAN'), array_map('strtoupper', array(cms_store::columns('menudata')['price'], cms_store::columns('eventsdata')['date'], cms_store::columns('eventsdata')['starts'], cms_store::columns('menudata')['enabled'])), 'the columns are declared as their types');
	$overview = mcp($base, 'site_overview');
	foreach ($overview['collections'] as $c) $listed[$c['name']] = isset($c['types']) ? $c['types'] : array();
	same(array('price' => 'number', 'featured' => 'int'), $listed['menu'], 'only the fields that aren\'t text');
	same(array('date' => 'date', 'starts' => 'time', 'places' => 'int', 'rsvp_until' => 'datetime'), $listed['events']);
	$described = json_decode(raster(array('describe', '--json', '--sections=collections'))[1], true);
	foreach ($described['collections'] as $c) if ($c['name'] === 'booking') same(array('date' => 'date', 'time' => 'time', 'ends' => 'time', 'people' => 'int', 'token_expires' => 'datetime', 'changed_at' => 'datetime', 'newsletter' => 'bool'), $c['types'] + array());
});

test('T2', 'values are stored as their type, whoever writes them, or refused naming the field', function () use ($base) {
	$gig = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Typed gig', 'date' => '12 Dec 2026', 'starts' => '8pm', 'summary' => 'x')));
	same(array('2026-12-12', '20:00'), array($gig['date'], $gig['starts']), 'MCP');
	try {
		mcp($base, 'create_item', array('collection' => 'menu', 'fields' => array('name' => 'Free lunch', 'price' => 'cheap')));
		throw new Exception('a price that is no number was stored');
	} catch (Exception $e) {
		has($e->getMessage(), "'price' must be a number, like 4.50");
	}
	try {
		mcp($base, 'update_item', array('collection' => 'events', 'id' => $gig['id'], 'fields' => array('date' => '2026-02-30')));
		throw new Exception('a date that does not exist was stored');
	} catch (Exception $e) {
		has($e->getMessage(), "'date' must be a date, like 2026-10-05");
	}
	// the in-page editor posts text, and gets the item back as text
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$token = token_in(http('GET', "$base/menu", null, $h)[1]);
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'menu', 'id' => 0, 'fields' => array('name' => 'Typed tart', 'price' => ' 9.5 ', 'category' => 'cakes'), 'csrf' => $token), $h);
	same(200, $status);
	$tart = json_decode($body, true);
	same(array('9.5', '1'), array($tart['price'], $tart['enabled']));
	same(9.5, cms_store::get_item('menudata', $tart['id'])['price'], 'stored as a number');
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'menu', 'id' => $tart['id'], 'fields' => array('price' => 'nine'), 'csrf' => $token), $h);
	same(400, $status);
	same(array('type_number'), json_decode($body, true)['problems'], 'a name the editor words: '.$body);
	same('price', json_decode($body, true)['field']);
	// with the mock-ups from the item's mark, it comes back as the page prints it
	list(, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'menu', 'id' => $tart['id'], 'fields' => array('price' => '9.5'), 'examples' => array('price' => '14.50'), 'csrf' => $token), $h);
	same('9.50', json_decode($body, true)['price']);
	has(http('GET', "$base/menu", null, $h)[1], '"examples":{', 'item marks carry their mock-ups');
	mcp($base, 'delete_item', array('collection' => 'events', 'id' => $gig['id']));
	mcp($base, 'delete_item', array('collection' => 'menu', 'id' => $tart['id']));
});

test('T3', 'lists filter and sort by type: numbers as numbers, dates as dates', function () use ($base) {
	$platter = mcp($base, 'create_item', array('collection' => 'menu', 'fields' => array('name' => 'Big platter', 'price' => '100', 'category' => 'plates')));
	$tea = mcp($base, 'create_item', array('collection' => 'menu', 'fields' => array('name' => 'Mint tea', 'price' => '9.5', 'category' => 'tea')));
	try {
		// as text, "9.50" sorts above "18.00" and "100.00" below both
		check(preg_match('#<ol class="priciest">\s*<li>Big platter 100.00</li>\s*<li>Crème brûlée 18.00</li></ol>#', between(http('GET', "$base/lab")[1], 'ordering'), $m), 'order=-price');
		with_file(dirname(__DIR__).'/demo/views/cafe/zz-types.html', "<ul><!-- render.cms.menu('price>=10&price<=18&order=price&limit=50') --><li><!-- print.name -->Dish<!-- /print.name --></li><!-- /render.cms.menu('price>=10&price<=18&order=price&limit=50') --></ul>", function () use ($base) {
			$listed = http('GET', "$base/zz-types")[1];
			lacks($listed, 'Mint tea', '9.5 < 10');
			lacks($listed, 'Big platter', '100 > 18');
			check(strpos($listed, 'Flat white') < strpos($listed, 'Crème brûlée'), '14.50 before 18');
			// a value that can't be a number matches nothing
			file_put_contents(dirname(__DIR__).'/demo/views/cafe/zz-types.html', "<ul><!-- render.cms.menu('price=?max') --><li><!-- print.name -->Dish<!-- /print.name --></li><!-- /render.cms.menu('price=?max') --></ul>");
			lacks(http('GET', "$base/zz-types?max=lots")[1], '<li>');
			has(http('GET', "$base/zz-types?max=100.0")[1], '<li>Big platter</li>', '100.0 is 100');
		});
		$late = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Late show', 'date' => '2026-10-10', 'starts' => '9:30pm', 'summary' => 'x')));
		$early = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Early show', 'date' => '2026-10-10', 'starts' => '18:00', 'summary' => 'x')));
		$events = http('GET', "$base/events")[1];
		check(strpos($events, 'Early show') < strpos($events, 'Late show'), 'order=date,starts: 18:00 before 21:30');
		mcp($base, 'delete_item', array('collection' => 'events', 'id' => $late['id']));
		mcp($base, 'delete_item', array('collection' => 'events', 'id' => $early['id']));
	} finally {
		mcp($base, 'delete_item', array('collection' => 'menu', 'id' => $platter['id']));
		mcp($base, 'delete_item', array('collection' => 'menu', 'id' => $tea['id']));
	}
});

test(array('P9', 'T4'), 'the_booking adds a field; models, MCP and /api read ints, floats and bools, so the model needs no casts', function () use ($base) {
	same('the_booking', cms_records::info('booking')['class'], 'the café\'s override of the bundled model');
	$day = next_open('sun');
	same(303, book($base, array('name' => 'Tudor', 'email' => 'tudor@example.com', 'date' => $day, 'people' => '3', 'newsletter' => 'yes'))[0]);
	$booking = cms_records::find('booking', array('email' => 'tudor@example.com'))[0];
	same(array(3, true, $day, '19:00'), array($booking['people'], $booking['newsletter'], $booking['date'], $booking['time']));
	check(is_int($booking['id']) && is_int($booking['owner']), 'ids are ints');
	same(array(), cms_records::find('booking', array('email' => 'tudor@example.com', 'newsletter' => false)), 'find() filters by type too');
	same(3, mcp($base, 'get_item', array('collection' => 'booking', 'id' => $booking['id']))['people']);
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$rows = json_decode(http('GET', "$base/api/cafe/bookings/$day", null, array("Cookie: $staff"))[1], true);
	$tudor = array_values(array_filter($rows, function ($b) { return $b['name'] === 'Tudor'; }))[0];
	same(array(3, true), array($tudor['people'], $tudor['newsletter']));
	// MCP takes values as their types, and a whole number for an int
	same(4, mcp($base, 'update_item', array('collection' => 'booking', 'id' => $booking['id'], 'fields' => array('people' => 4)))['people']);
	try {
		mcp($base, 'update_item', array('collection' => 'booking', 'id' => $booking['id'], 'fields' => array('people' => 'many')));
		throw new Exception('many people were stored');
	} catch (Exception $e) {
		has($e->getMessage(), "'people' must be a whole number, like 4");
	}
	mcp($base, 'delete_item', array('collection' => 'booking', 'id' => $booking['id']));
});

test('T5', 'templates print text: a number with its mock-up\'s decimals, nothing for an empty value; a form value of the wrong type raises <field>_invalid', function () use ($base) {
	$menu = http('GET', "$base/menu")[1];
	has($menu, '<p class="price">10.00 lei', 'the mock-up (14.50) has two decimals, so 10 prints 10.00');
	has(http('GET', "$base/menu/menu_item/flat-white")[1], '14.50 lei');
	$untimed = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Untimed', 'date' => '2026-10-11', 'summary' => 'x')));
	same(null, mcp($base, 'get_item', array('collection' => 'events', 'id' => $untimed['id']))['starts'], 'an empty time is null to models');
	has(http('GET', "$base/events/events_item/untimed")[1], '2026-10-11 at </p>', 'and prints nothing');
	mcp($base, 'delete_item', array('collection' => 'events', 'id' => $untimed['id']));
	// HTML lets 2.5 through a number input; the int field doesn't
	list($status, $body) = book($base, array('name' => 'Half', 'email' => 'half@example.com', 'date' => next_open('sun'), 'people' => '2.5'));
	same(200, $status);
	has($body, 'We seat whole people');
	same(array(), cms_records::find('booking', array('email' => 'half@example.com')), 'nothing stored');
	lacks(raster(array('lint'))[1], 'people_invalid', 'lint knows Raster raises it');
	// any model's numbers print with the mock-up's decimals, records included
	$model = dirname(__DIR__).'/demo/models/zzprices/zzprices.php';
	test_fixture(dirname($model)); @mkdir(dirname($model));
	try {
		with_file($model, '<?php class zzprices { function rows() { return array(array("price" => 24.0), array("price" => 9.5)); } }', function () use ($base) {
			with_file(dirname(__DIR__).'/demo/views/cafe/zz-prices.html', '<!-- render.zzprices.rows --><b><!-- print.price -->24.00<!-- /print.price --></b><!-- /render.zzprices.rows -->', function () use ($base) {
				has(http('GET', "$base/zz-prices")[1], '<b>24.00</b>'."\n".'<b>9.50</b>');
			});
		});
	} finally {
		@rmdir(dirname($model));
	}
	// a ticked box sends its own value
	same(303, book($base, array('name' => 'Box', 'email' => 'box@example.com', 'date' => next_open('sun'), 'newsletter' => 'subscribe'))[0]);
	$box = cms_records::find('booking', array('email' => 'box@example.com'))[0];
	same(true, $box['newsletter']);
	mcp($base, 'delete_item', array('collection' => 'booking', 'id' => $box['id']));
});

test('T6', 'schema --apply converts a column whose type changed when every value fits, and names the values that don\'t', function () use ($base) {
	$view = dirname(__DIR__).'/demo/views/cafe/zz-retype.html';
	$markup = function ($mock) { return "<!-- render.cms.zzretype --><p><!-- print.n -->$mock<!-- /print.n --></p><!-- /render.cms.zzretype -->"; };
	with_file($view, $markup('5'), function () use ($base, $view, $markup) {
		http('GET', "$base/zz-retype");
		cms_store::forget();
		// MariaDB still says INT(11)
		same('INT', preg_replace('/\(\d+\)/', '', strtoupper(cms_store::columns('zzretypedata')['n'])));
		file_put_contents($view, $markup('five'));
		list(, $out) = raster(array('schema'));
		has($out, 'n (int in the database, text in the templates; --apply converts it)');
		has(raster(array('schema', '--apply'))[1], 'zzretypedata.n is now text (was int)');
		$lots = mcp($base, 'create_item', array('collection' => 'zzretype', 'fields' => array('n' => 'lots')));
		file_put_contents($view, $markup('5'));
		list(, $out) = raster(array('schema', '--apply'));
		has($out, 'kept zzretypedata.n as text: "lots" can\'t be int; change them and run --apply again');
		cms_store::forget();
		same(on_mysql() ? 'LONGTEXT' : 'TEXT', strtoupper(cms_store::columns('zzretypedata')['n']));
	});
	raster(array('schema', '--drop=zzretypedata', '--force'));
});
test('T6', 'schema --apply leaves a column SQLite won\'t drop as it was, with nothing half done', function () use ($base) {
	sqlite_only('SQLite refuses to drop a column with an index; MySQL drops it, and its TEXT columns take no plain index');
	$view = dirname(__DIR__).'/demo/views/cafe/zz-retype.html';
	$markup = function ($mock) { return "<!-- render.cms.zzretype --><p><!-- print.n -->$mock<!-- /print.n --></p><!-- /render.cms.zzretype -->"; };
	with_file($view, $markup('five'), function () use ($base, $view, $markup) {
		http('GET', "$base/zz-retype");
		// every value would fit an int: only the index is in the way
		R::exec('DELETE FROM zzretypedata');
		file_put_contents($view, $markup('5'));
		R::exec('CREATE INDEX zz_n ON zzretypedata (n)');
		has(raster(array('schema', '--apply'))[1], 'kept zzretypedata.n as it was:');
		cms_store::forget();
		same(array('TEXT', false), array(cms_store::columns('zzretypedata')['n'], isset(cms_store::columns('zzretypedata')['raster_n_retyped'])));
		R::exec('DROP INDEX zz_n');
	});
	raster(array('schema', '--drop=zzretypedata', '--force'));
});

// ## 2.1.8 batch A: page cache

test(array('L9', 'L10'), 'page cache: made-up list URLs are not kept, a change deletes old pages, tracking links are hits', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token');
	$prod = server(free_port(), $env);
	$cache = function ($path) use ($prod) {
		list($status, , $headers) = http('GET', "$prod$path");
		return $status.' '.(header_value($headers, 'X-Raster-Cache') ?: 'not kept');
	};
	$pages = function () use ($root) { return count(preg_grep('#/[0-9a-f]{40}$#', glob("$root/demo/data/cache/*") ?: array())); };
	mcp($prod, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Notes on caching', 'author' => 'Mara')));
	raster(array('cache', 'clear'), $env);
	same('200 miss', $cache('/journal/journal_items/author/Mara'));
	same('200 hit', $cache('/journal/journal_items/author/Mara'));
	// L9: a filter nothing matches, a page past the last one and a field the
	// list doesn't have all answer, and none of them is kept
	// nor are other spellings of a list's own URLs
	foreach (array('/journal/journal_items/author/nobody-1', '/journal/journal_items/author/nobody-2', '/journal/journal_page/99999', '/journal/journal_items/flavour/x',
		'/journal/journal_page/-1', '/journal/journal_page/1', '/journal/journal_items/author/Mara/junk', '/journal/journal_items/author/Mara/journal_page/-5') as $path) {
		same('200 not kept', $cache($path), $path);
		same('200 not kept', $cache($path), $path);
	}
	same(1, $pages(), 'only the real list is cached');
	// nor a typed filter spelled another way than the site prints it
	mcp($prod, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Pi day', 'date' => '2027-03-14', 'summary' => 'x')));
	raster(array('cache', 'clear'), $env);
	same('200 miss', $cache('/events/events_items/date/2027-03-14'));
	same('200 hit', $cache('/events/events_items/date/2027-03-14'));
	foreach (array('/events/events_items/date/14%20Mar%202027', '/events/events_items/date/2027-03-14%2000:00') as $path) {
		same('200 not kept', $cache($path), $path);
		same('200 not kept', $cache($path), $path);
	}
	same(1, $pages(), 'only the date as the site prints it is cached');
	// a number is spelled with its mock-up's decimals (3.50, not 3.5), also when
	// another list on the page doesn't print it
	mcp($prod, 'create_item', array('collection' => 'menu', 'fields' => array('name' => 'Pi tart', 'price' => 3.5, 'category' => 'cake')));
	$menu = "$root/demo/views/cafe/menu.html";
	$view = file_get_contents($menu);
	try {
		file_put_contents($menu, str_replace("<!-- render.cms.menu('order=name') -->", "<!-- render.cms.menu('limit=1') --><i><!-- print.name -->Dish<!-- /print.name --></i><!-- /render.cms.menu('limit=1') -->\n<!-- render.cms.menu('order=name') -->", $view));
		raster(array('cache', 'clear'), $env);
		same('200 miss', $cache('/menu/menu_items/price/3.50'));
		same('200 hit', $cache('/menu/menu_items/price/3.50'));
		foreach (array('/menu/menu_items/price/3.5', '/menu/menu_items/price/3.500', '/menu/menu_items/price/+3.50', '/menu/menu_items/price/03.50') as $path) {
			has(http('GET', "$prod$path")[1], 'Pi tart', $path);
			same('200 not kept', $cache($path), $path);
		}
		same(1, $pages(), 'only the price as the site prints it is cached');
	} finally {
		file_put_contents($menu, $view);
	}
	raster(array('cache', 'clear'), $env);
	$cache('/journal/journal_items/author/Mara');
	// L10: links with only tracking parameters are the same page
	foreach (array('utm_source=newsletter&utm_medium=email&utm_campaign=october', 'fbclid=IwAR0x', 'gclid=Cj0K', 'msclkid=5a2b') as $query) {
		same('200 hit', $cache("/journal/journal_items/author/Mara?$query"), $query);
	}
	same('200 not kept', $cache('/journal/journal_items/author/Mara?utm_source=x&page=2'), 'any other parameter still skips the cache');
	// and the page every visitor then gets doesn't carry the tracking values
	list($status, $body, $headers) = http('GET', "$prod/journal?utm_source=attacker");
	same('200 miss', $status.' '.header_value($headers, 'X-Raster-Cache'));
	list($status, $body, $headers) = http('GET', "$prod/journal");
	same('200 hit', $status.' '.header_value($headers, 'X-Raster-Cache'));
	check(strpos($body, 'attacker') === false, 'the cached /journal carries a tracking link\'s values');
	// L9: a content change deletes the pages cached before it
	mcp($prod, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'More notes', 'author' => 'Mara')));
	same(0, $pages(), 'pages cached before a content change are deleted');
	same('200 miss', $cache('/journal/journal_items/author/Mara'));
});

// ## Page cache and the clock (#158 group 8)

// runs PHP with the demo booted, in the environment given
function demo_php($code, $env) {
	global $root;
	$prefix = '';
	foreach ($env as $name => $value) $prefix .= $name.'='.escapeshellarg($value).' ';
	return shell_exec($prefix.php_cmd().' -r '.escapeshellarg('require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); '.$code).' 2>&1');
}

test('L13', 'raster render never reads or writes the page cache, and a protected page answers its redirect', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir");
	$prod = server(free_port(), $env);
	raster(array('cache', 'clear'), $env);
	http('GET', "$prod/faq");
	same('hit', header_value(http('GET', "$prod/faq")[2], 'X-Raster-Cache'));
	$faq = "$root/demo/views/cafe/faq.html";
	with_file($faq, str_replace('</body>', '<p>Edited by hand</p></body>', file_get_contents($faq)), function () use ($env) {
		list($code, $out) = raster(array('render', '/faq'), $env);
		same(0, $code, $out);
		has($out, 'Edited by hand', 'render printed the cached page');
	});
	raster(array('render', '/about'), $env);
	same('miss', header_value(http('GET', "$prod/about")[2], 'X-Raster-Cache'), 'render stored the page');
	// a protected page: the redirect the web gets, and exit 1
	list($code, $out) = raster(array('render', '/staff'), $env);
	same(1, $code, $out);
	has($out, 'HTTP 303');
	has($out, 'Location: https://cafe.example/login?next=%2Fstaff');
	lacks($out, '<html', 'the protected page itself');
	$answers = mcp_stdio(array(array('render_url', array('url' => '/staff'))));
	same(303, $answers[0]['status']);
	same(false, $answers[0]['ok']);
	has($answers[0]['errors'], '/login?next=%2Fstaff');
	same('', $answers[0]['html']);
	// the account a page is rendered as still sees it
	same(0, raster(array('render', '/staff', '--as=editor'))[0]);
});

test('L14', 'several scheduled items each clear the page cache when their time comes, in the site\'s time zone', function () use ($root, $tmp, $maildir) {
	// far from UTC, so a time read in the wrong zone is hours off
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir", 'CAFE_TIMEZONE' => 'Pacific/Kiritimati');
	$prod = server(free_port(), $env);
	raster(array('cache', 'clear'), $env);
	$now = time();
	// dated before every other event, so both are on the first page
	$out = demo_php('cms_store::connect(); R::freeze(false); foreach (array("First due" => 2, "Second due" => 4) as $title => $later) cms_store::save_item("eventsdata", 0, array("title" => $title, "date" => "2000-01-01", "published_at" => date("Y-m-d H:i:s", '.$now.' + $later)), array("title", "date")); echo date_default_timezone_get();', $env);
	same('Pacific/Kiritimati', $out);
	// other tests schedule items for 2099
	$next = function () use ($root, $now) {
		return array_values(array_filter(file("$root/demo/data/cache/next", FILE_IGNORE_NEW_LINES), function ($time) use ($now) { return $time < $now + 3600; }));
	};
	same(array((string)($now + 2), (string)($now + 4)), $next(), 'every time is kept');
	http('GET', "$prod/events");
	list(, $body, $headers) = http('GET', "$prod/events");
	same('hit', header_value($headers, 'X-Raster-Cache'));
	lacks($body, 'First due');
	while (time() < $now + 3) usleep(100000);
	$body = http('GET', "$prod/events")[1];
	has($body, 'First due', 'the first is published on time');
	lacks($body, 'Second due');
	same(array((string)($now + 4)), $next(), 'the later time stays');
	same('hit', header_value(http('GET', "$prod/events")[2], 'X-Raster-Cache'));
	while (time() < $now + 5) usleep(100000);
	list(, $body, $headers) = http('GET', "$prod/events");
	same('miss', header_value($headers, 'X-Raster-Cache'));
	has($body, 'Second due', 'the second is published on time');
	same(array(), $next(), 'nothing left to wait for');
	demo_php('cms_store::connect(); R::exec("DELETE FROM eventsdata WHERE title IN (\'First due\', \'Second due\')");', $env);
});

test('L15', 'cache clear says so when it can\'t write the cache, and upgrade clears it', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir");
	$prod = server(free_port(), $env);
	$dir = "$root/demo/data/cache";
	raster(array('cache', 'clear'), $env);
	http('GET', "$prod/faq");
	// the cache belongs to the web server's user, and this is someone else
	foreach (glob("$dir/*") as $file) chmod($file, 0444);
	chmod($dir, 0555);
	try {
		list($code, $out) = raster(array('cache', 'clear'), $env);
		same(1, $code, $out);
		has($out, "error: can't write $dir/version");
		lacks($out, 'Page cache cleared');
		$answers = mcp_stdio(array(array('clear_cache')));
		has(isset($answers[0]['error']) ? $answers[0]['error'] : '', "Can't write $dir/version");
	} finally {
		chmod($dir, 0775);
		foreach (glob("$dir/*") as $file) chmod($file, 0664);
	}
	same('hit', header_value(http('GET', "$prod/faq")[2], 'X-Raster-Cache'));
	list($code, $out) = raster(array('upgrade'), $env);
	same(0, $code, $out);
	same('miss', header_value(http('GET', "$prod/faq")[2], 'X-Raster-Cache'), 'the first request after an upgrade');
});

test('L16', 'a cached page keeps the headers it was made with, has Last-Modified and answers 304', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir", 'CAFE_TIMEZONE' => 'Pacific/Kiritimati');
	$prod = server(free_port(), $env);
	raster(array('cache', 'clear'), $env);
	list(, $first, $miss) = http('GET', "$prod/faq");
	same('miss', header_value($miss, 'X-Raster-Cache'));
	list($status, $body, $hit) = http('GET', "$prod/faq");
	same(200, $status);
	same('hit', header_value($hit, 'X-Raster-Cache'));
	same($first, $body);
	foreach (array('X-Cafe', 'X-Cafe-Done', 'Content-Type') as $name) same(header_value($miss, $name), header_value($hit, $name), $name);
	same(1, count(preg_grep('/^X-Cafe:/i', $hit)), 'a header is sent once');
	$modified = header_value($hit, 'Last-Modified');
	same($modified, header_value($miss, 'Last-Modified'));
	check(preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', (string)$modified), 'an HTTP date: '.$modified);
	check(abs(strtotime($modified) - time()) < 30, 'in GMT, whatever the site\'s time zone: '.$modified);
	list($status, $body, $headers) = http('GET', "$prod/faq", null, array("If-Modified-Since: $modified"));
	same(304, $status);
	same('', $body);
	same('hit', header_value($headers, 'X-Raster-Cache'));
	same(200, http('GET', "$prod/faq", null, array('If-Modified-Since: '.gmdate('D, d M Y H:i:s', strtotime($modified) - 60).' GMT'))[0], 'an older copy gets the page');
	same(200, http('GET', "$prod/faq", null, array('If-Modified-Since: yesterday-ish'))[0], 'a date that is not one');
	// a session cookie is never kept, nor sent again
	foreach ($hit as $header) check(stripos($header, 'Set-Cookie:') !== 0, $header);
});

test('L17', 'theme assets carry a version; a versioned file is kept a year, the rest and every page are checked each time', function () use ($root, $tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/prod.sqlite"), 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir");
	$prod = server(free_port(), $env);
	raster(array('cache', 'clear'), $env);
	list(, $miss, $headers) = http('GET', "$prod/faq");
	same('no-cache', header_value($headers, 'Cache-Control'));
	check((bool)preg_match('#href="style\.css\?v=[0-9a-f]{8}"#', $miss), 'the stylesheet has a version');
	list(, $hit, $headers) = http('GET', "$prod/faq");
	same('hit', header_value($headers, 'X-Raster-Cache'));
	same('no-cache', header_value($headers, 'Cache-Control'), 'a cached copy says the same');
	same($miss, $hit);
	preg_match('#style\.css\?v=[0-9a-f]{8}#', $hit, $m);
	list($status, , $headers) = http('GET', "$prod/demo/views/cafe/".$m[0]);
	same(200, $status);
	same('public, max-age=31536000, immutable', header_value($headers, 'Cache-Control'));
	same('no-cache', header_value(http('GET', "$prod/demo/views/cafe/style.css")[2], 'Cache-Control'));
});

// ## 2.1.8 batch B: errors and /api

test('C51', 'cms offers over /api only what its api() lists: the editor endpoints, style and logout; an override adds nothing unlisted', function () use ($base, $root) {
	foreach (array('setup', 'route', 'inject_toolbar', 'login', 'login_message') as $method) {
		list($status, $body) = http('GET', "$base/api/cms/$method");
		same(404, $status, "/api/cms/$method");
		lacks($body, 'There are no users');
	}
	same(403, http('POST', "$base/api/cms/editor_save_item", array('collection' => 'menu'))[0], 'editor endpoints check the caller themselves');
	same(200, http('GET', "$base/api/cms/style")[0]);
	same(200, http('GET', "$base/api/cms/editor_script")[0]);
	$dir = "$root/demo/models/the_cms";
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/the_cms.php", "<?php\nclass the_cms extends cms\n{\n\tfunction takings() { return 'today: 1200 lei'; }\n}\n", function () use ($base) {
			list($status, $body) = http('GET', "$base/api/cms/takings");
			same(404, $status, 'a public method of the_cms');
			lacks($body, 'lei');
			same(200, http('GET', "$base/api/cms/style")[0], 'what cms lists still answers');
		});
	} finally {
		@rmdir($dir);
	}
});

test('C50', '/api errors answer JSON: 500 logged with the URL, 503 when the database is down, 400 for missing arguments; development adds the trace', function () use ($base, $root, $tmp, $maildir) {
	$prod_db = test_db("$tmp/b-prod.sqlite");
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => $prod_db, 'RASTER_URL' => 'https://cafe.example/');
	raster(array('schema', '--apply'), $env);
	$prod = server(free_port(), array_merge($env, array('RASTER_MAIL' => "log://$maildir")));
	$dir = "$root/demo/models/zzwebhook";
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/zzwebhook.php", "<?php\nclass zzwebhook\n{\n\tstatic function api() { return array('paid' => 'visitor', 'down' => 'visitor'); }\n"
			."\tfunction paid(\$order, \$amount) { throw new RuntimeException('signature mismatch for sk_live_123'); }\n"
			."\tfunction down() { throw new PDOException('SQLSTATE[HY000] [2002] Connection refused'); }\n}\n", function () use ($base, $prod, $tmp) {
			list($status, $body, $headers) = http('POST', "$prod/api/zzwebhook/paid/7/120", '{}', array('Content-Type: application/json'));
			same(500, $status, $body);
			same('{"error":"server error"}', $body);
			has(header_value($headers, 'Content-Type'), 'application/json');
			$log = file_get_contents("$tmp/php-errors.log");
			has($log, 'signature mismatch for sk_live_123');
			has($log, '/api/zzwebhook/paid/7/120', 'logged with the URL');
			list($status, $body) = http('POST', "$prod/api/zzwebhook/paid/7", '{}', array('Content-Type: application/json'));
			same(400, $status, $body);
			lacks($body, 'sk_live');
			// a database error while the database is there is a bug: 500, not 503
			list($status, $body) = http('GET', "$prod/api/zzwebhook/down");
			same(500, $status, $body);
			lacks($body, 'Connection refused');
			// development: the trace, for whoever is writing the model
			list($status, $body) = http('POST', "$base/api/zzwebhook/paid/7/120", '{}', array('Content-Type: application/json'));
			same(500, $status);
			$json = json_decode($body, true);
			has($json['exception'], 'signature mismatch');
			check(count($json['trace']) > 0, 'a trace');
		});
	} finally {
		@rmdir($dir);
	}
});

test('L11', 'a database that can\'t be reached answers 503 outside development, with error_document_503, logged and never cached', function () use ($tmp, $maildir) {
	$prod_db = test_db("$tmp/b-prod.sqlite");
	$down = test_db("$tmp/b-down.sqlite");
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => $prod_db, 'RASTER_URL' => 'https://cafe.example/');
	raster(array('schema', '--apply'), $env);
	test_db_break($down);
	// no RASTER_URL: this server's own address keeps its pages apart in the cache
	$prod = server(free_port(), array('RASTER_ENV' => 'production', 'RASTER_DB' => $down, 'RASTER_MAIL' => "log://$maildir"));
	foreach (array(1, 2) as $time) {
		list($status, $body, $headers) = http('GET', "$prod/menu");
		same(503, $status, "request $time");
		has($body, 'Back in a moment', 'the site\'s own 503 page');
		lacks($body, '<!-- print.');
		lacks($body, 'Flat white', 'no mock-up');
		same(null, header_value($headers, 'X-Raster-Cache'), 'not cached');
	}
	same(503, http('GET', "$prod/journal.rss")[0], 'feeds too');
	// /api too, before the method runs: the provider tries again later
	list($status, $body) = http('GET', "$prod/api/cafe/category_count/coffee");
	same(503, $status, $body);
	same('{"error":"database unavailable"}', $body);
	has(file_get_contents("$tmp/php-errors.log"), 'Raster error: the database can\'t be reached');
	// back up: the real menu at once, nothing stale in the cache
	test_db_copy($prod_db, "$tmp/b-down.sqlite");
	list($status, $body, $headers) = http('GET', "$prod/menu");
	same(200, $status);
	same('miss', header_value($headers, 'X-Raster-Cache'));
	lacks($body, '<!-- print.');
	// raster render answers the same, and exits 1
	test_db_break($down);
	list($code, $out) = raster(array('render', '/menu'), array_merge($env, array('RASTER_DB' => $down)));
	same(1, $code);
	has($out, 'HTTP 503');
});

// ## 2.1.8 batch C: list SQL

test('E31', 'fields named like SQL words (when, from, group) sort, filter and link to their filter pages', function () use ($base) {
	$row = '<li><!-- print.@href.raster_filter@from --><a href="#"><!-- print.title -->Rome<!-- /print.title --> from <!-- print.from -->Paris<!-- /print.from --></a><!-- /print.@href.raster_filter@from --> <!-- print.when -->2026-12-01<!-- /print.when --> <!-- print.group -->3<!-- /print.group --></li>';
	$list = function ($options) use ($row) { return "<!-- render.cms.zztrips('$options') -->$row<!-- /render.cms.zztrips('$options') -->"; };
	$titles = function ($html, $class) {
		preg_match('#<(ul|ol) class="'.$class.'">(.*?)</\1>#s', $html, $m);
		preg_match_all('#>(\w+) from #', isset($m[2]) ? $m[2] : '', $t);
		return $t[1];
	};
	try {
		with_file(dirname(__DIR__).'/demo/views/cafe/zztrips.html', '<ul class="by-when">'.$list('order=when').'</ul><ol class="big">'.$list('group>2&order=-group').'</ol>', function () use ($base, $titles) {
			http('GET', "$base/zztrips");
			mcp($base, 'create_item', array('collection' => 'zztrips', 'fields' => array('title' => 'Oslo', 'when' => '2026-10-01', 'from' => 'Berlin', 'group' => '1')));
			mcp($base, 'create_item', array('collection' => 'zztrips', 'fields' => array('title' => 'Lima', 'when' => '2026-11-01', 'from' => 'Paris', 'group' => '5')));
			list($status, $html) = http('GET', "$base/zztrips");
			same(200, $status);
			same(array('Oslo', 'Lima', 'Rome'), $titles($html, 'by-when'), 'order=when');
			same(array('Lima', 'Rome'), $titles($html, 'big'), 'group>2&order=-group');
			has($html, 'href="'.$base.'/zztrips/zztrips_items/from/Paris"');
			list($status, $html) = http('GET', "$base/zztrips/zztrips_items/from/Paris");
			same(200, $status);
			same(array('Lima', 'Rome'), $titles($html, 'by-when'), 'the filter page');
		});
	} finally {
		raster(array('schema', '--drop=zztripsdata', '--force'));
	}
});

test('T7', 'a time prints 19:00 whatever the database gives back; lists hide drafts and order by newest whether published_at is typed or still text', function () use ($base) {
	$show = mcp($base, 'create_item', array('collection' => 'events', 'fields' => array('title' => 'Seconds show', 'date' => '2026-10-10', 'starts' => '19:00', 'summary' => 'x')));
	cms_store::connect();
	try {
		// MySQL hands a TIME back with its seconds
		R::exec('UPDATE eventsdata SET starts = ? WHERE id = ?', array('19:00:00', $show['id']));
		$events = http('GET', "$base/events")[1];
		check(preg_match('#Seconds show.*?2026-10-10</span>\s*at\s*<span[^>]*>19:00<|Seconds show.*?2026-10-10 at 19:00<#s', $events), 'prints 19:00');
		lacks($events, '19:00:00');
	} finally {
		mcp($base, 'delete_item', array('collection' => 'events', 'id' => $show['id']));
	}
	// a table made before 2.1.7 keeps a text published_at, empty for "now"
	R::exec('CREATE TABLE zzolddata (id INTEGER PRIMARY KEY '.(on_mysql() ? 'AUTO_INCREMENT' : 'AUTOINCREMENT').', title TEXT, slug TEXT, enabled TEXT, published_at TEXT, updated_at TEXT)');
	try {
		foreach (array('Empty' => '', 'Past' => date('Y-m-d H:i:s', time() - 86400), 'Future' => '2099-01-01 10:00:00') as $title => $published) {
			R::exec('INSERT INTO zzolddata (title, slug, enabled, published_at, updated_at) VALUES (?, ?, ?, ?, ?)', array($title, strtolower($title), '1', $published, '2026-01-01 10:00:00'));
		}
		with_file(dirname(__DIR__).'/demo/views/cafe/zzold.html', "<ul><!-- render.cms.zzold('order=newest') --><li><!-- print.title -->Old<!-- /print.title --></li><!-- /render.cms.zzold('order=newest') --></ul>", function () use ($base) {
			list($status, $html) = http('GET', "$base/zzold");
			same(200, $status);
			check(preg_match('#<li>Past</li>\s*<li>Empty</li>\s*</ul>#', $html), 'newest first, nothing scheduled: '.$html);
		});
	} finally {
		raster(array('schema', '--drop=zzolddata', '--force'));
	}
});

// ## 2.1.8 batch D: accounts

test('G22', 'raster user changes only what it is given (#71)', function () use ($base) {
	same(0, raster(array('user', 'keeper@cafe.test', '--role=member', '--password=first password'))[0]);
	list($code, $out) = raster(array('user', 'keeper@cafe.test', '--password=second password'));
	same(0, $code, $out);
	has($out, "saved as member (role kept)");
	has(raster(array('users'))[1], 'member   keeper@cafe.test', 'a password reset made them');
	login($base, 'keeper@cafe.test', 'second password');
	list($code, $out) = raster(array('user', 'keeper@cafe.test', '--role=editor'));
	same(0, $code, $out);
	has($out, 'saved as editor (password kept)');
	lacks($out, 'Password:');
	has(raster(array('users'))[1], 'editor   keeper@cafe.test');
	login($base, 'keeper@cafe.test', 'second password');
});
test('G23', 'once a lock runs out, five new wrong passwords lock again (#73)', function () use ($base) {
	raster(array('user', 'relock@cafe.test', '--role=member', '--password=right password'));
	$wrong = function ($times) use ($base) {
		for ($i = 0; $i < $times; $i++) http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'relock@cafe.test', 'password' => 'wrong'));
	};
	$expire = function () {
		database::instance('cms');
		R::exec("UPDATE user SET failed_at = ? WHERE email = 'relock@cafe.test'", array(date('Y-m-d H:i:s', time() - 16 * 60)));
	};
	$wrong(5);
	$expire();
	$wrong(1);
	login($base, 'relock@cafe.test', 'right password');
	$wrong(5);
	has(http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'relock@cafe.test', 'password' => 'right password'))[1], 'Wrong email or password.', 'locked again');
	$expire();
	$wrong(4);
	login($base, 'relock@cafe.test', 'right password');
});

// ## 2.1.8 batch E: template output
test('D27', 'a form shown again keeps $100, \\1 and $0 exactly as typed (#66)', function () use ($base) {
	$name = 'Table for $20 a head, not $100 \\1';
	list($status, $body) = book($base, array('name' => $name, 'email' => '', 'phone' => '\\1 $0', 'date' => next_open('tue', 3), 'notes' => 'US$12.50 \\2'));
	same(200, $status);
	has($body, 'We need a valid email to confirm.');
	has($body, 'value="Table for $20 a head, not $100 \\1"', 'the name');
	has($body, 'name="phone" pattern="\\+?[0-9 ]{6,15}" value="\\1 $0"', 'the phone');
	has($body, '>US$12.50 \\2</textarea>', 'the notes');
});
test('E32', 'the editor gets stored text with $5 and backslashes as it is (#66)', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$before = mcp($base, 'get_page', array('page' => '/about'))['fields']['heading'];
	try {
		foreach (array('Only $5 today, was $12. Path C:\\new', 'Say \\"hello\\" for $5 \\1') as $text) {
			mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => $text)));
			$page = http('GET', "$base/about", null, array("Cookie: $staff"))[1];
			$config = editor_config($page);
			check(is_array($config), 'the editor config is valid JSON for '.$text);
			list(, $mark) = mark_of($config, 'field', function ($m) { return $m['field'] === 'heading'; });
			same($text, $mark['value'], 'what the editor shows, and Duplicate copies');
		}
	} finally { mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => $before))); }
});
test('C52', 'a link a visitor typed is no script, whatever hides the scheme (#67)', function () use ($base, $views) {
	$links = array("java\tscript:alert(1)", "java\nscript:alert(2)", "\x01javascript:alert(3)", "&#106avascript:alert(4)", "vbscript:msgbox(5)", "data:text/html,6", "&#1;javascript:alert(7)", "java&#13;script:alert(8)", "&#x1F;javascript:alert(9)");
	$ids = array();
	// staff may book far ahead: a Wednesday's sitting in 2030
	foreach ($links as $i => $link) $ids[] = staff_booking(array('name' => "Zz link $i", 'email' => 'zz-links@example.com', 'date' => '2030-03-06', 'notes' => $link))['id'];
	$ids[] = staff_booking(array('name' => 'Zz link ok', 'email' => 'zz-links@example.com', 'date' => '2030-03-06', 'notes' => 'https://example.com/menu'))['id'];
	try {
		with_file("$views/zz-links.html", "<html><body><!-- render.cms.booking('email=zz-links@example.com&order=oldest') --><!-- print.@href.notes --><a class=\"zz\" href=\"#\">x</a><!-- /print.@href.notes --><!-- /render.cms.booking('email=zz-links@example.com&order=oldest') --></body></html>", function () use ($base) {
			$staff = login($base, 'staff@cafe.test', 'staff password');
			$page = http('GET', "$base/zz-links", null, array("Cookie: $staff"))[1];
			same(9, substr_count($page, '<a class="zz">'), 'every script link is dropped');
			same(1, substr_count($page, '<a class="zz" href="https://example.com/menu">'), 'the web address keeps its link');
			has($page, 'href="https://example.com/menu"');
			// the editor's config carries the text as data, for its Details panel
			$links = preg_replace('#<script id="raster-editor-config".*?</script>#s', '', $page);
			foreach (array('script:', 'data:', 'alert') as $bad) lacks($links, $bad);
		});
	} finally { foreach ($ids as $id) cms_records::delete('booking', $id); }
});
test('D28', 'a field sent as name[] or with a bad byte fails required; a field named tags[] takes a list (#70)', function () use ($base, $views) {
	database::instance('cms');
	$count = (int)R::count('bookingdata');
	$good = array('raster_form' => 'booking.form', 'resource' => 'dining-room', 'name' => 'Zz Ana', 'email' => 'zz@example.com', 'date' => next_open('tue', 3), 'time' => '19:00', 'people' => '2', 'terms' => '1');
	$body = http('POST', "$base/visit", http_build_query(array_merge($good, array('name' => array('x'), 'email' => array('not-an-email')))), array('Content-Type: application/x-www-form-urlencoded'))[1];
	has($body, 'Tell us your name (2 to 80 letters).', 'name[] fails required');
	has($body, 'We need a valid email to confirm.', 'email[] too');
	list($status, $body) = http('POST', "$base/visit", http_build_query($good).'&phone=%FFnot+a+phone', array('Content-Type: application/x-www-form-urlencoded'));
	same(200, $status, 'a bad byte is refused');
	has($body, 'Digits and spaces only', 'like a value without it');
	list($status, $body) = http('POST', "$base/visit", http_build_query($good).'&notes=caf%C3', array('Content-Type: application/x-www-form-urlencoded'));
	has($body, 'Keep notes under 300 characters.', 'on a field with no pattern too');
	same($count, (int)R::count('bookingdata'), 'nothing was stored');
	$form = '<html><body><!-- render.contact.form --><form method="post"><input type="email" name="email" required><label><input type="checkbox" name="tags[]" value="cakes" required> Cakes</label><label><input type="checkbox" name="tags[]" value="coffee"> Coffee</label><!-- render.validation.field(\'tags\') --><p class="error">Pick a topic.</p><!-- /render.validation.field(\'tags\') --><textarea name="message" required></textarea><!-- render.validation.field(\'message\') --><p class="error">Write a message.</p><!-- /render.validation.field(\'message\') --><button>Send</button></form><!-- /render.contact.form --></body></html>';
	with_file("$views/zz-topics.html", $form, function () use ($base) {
		$send = function ($extra) use ($base) { return http('POST', "$base/zz-topics", 'raster_form=contact.form&email=zz%40example.com'.$extra, array('Content-Type: application/x-www-form-urlencoded')); };
		list($status, , $headers) = $send('&tags%5B%5D=cakes&tags%5B%5D=coffee&message=Hi');
		same(303, $status, 'tags[] is a list the form asked for');
		has(header_value($headers, 'Location'), 'done=sent');
		list($status, $body) = $send('&message=Hi');
		same(200, $status);
		has($body, 'Pick a topic.', 'and still required');
		list($status, $body) = $send('&tags%5B%5D=cakes&message%5B%5D=Hi');
		same(200, $status, 'message[] is not');
		has($body, 'Write a message.');
		lacks($body, 'Pick a topic.');
	});
});

// ## 2.1.8 batch F: upgrade tooling

test('A17', 'theme files of every kind are served: no old .htaccess below the root', function () use ($base, $root, $views) {
	// application/.htaccess and system/.htaccess once allowed only a few
	// extensions on Apache; the root .htaccess has every rule on its own
	exec('git -C '.escapeshellarg($root).' ls-files', $files);
	same(array('.htaccess'), array_values(preg_grep('#(^|/)\.htaccess$#', $files)), 'only the root .htaccess');
	foreach (array('a17.webp', 'a17.woff2', 'a17.ico', 'a17.pdf') as $file) {
		with_file("$views/img/$file", "x", function () use ($base, $file) {
			same(200, http('GET', "$base/demo/views/cafe/img/$file")[0], $file);
		});
	}
	same(200, http('GET', "$base/demo/views/cafe/img/logo.svg")[0]);
	foreach (array('/demo/config/the_app.php', '/demo/data/x', '/demo/models/cafe/cafe.php', '/system/VERSION') as $path) {
		same(403, http('GET', $base.$path)[0], $path);
	}
});

test('N15', 'raster upgrade takes an app with no version file as current', function () use ($root) {
	// a second app made by hand: a copy of the demo's settings, no version file
	$app = "$root/zzn15";
	test_fixture($app);
	mkdir("$app/models", 0775, true);
	exec('cp -R '.escapeshellarg("$root/demo/config").' '.escapeshellarg("$app/config"));
	@unlink("$app/config/raster-version");
	try {
		$version = trim(file_get_contents("$root/system/VERSION"));
		$env = array('RASTER_APP' => 'zzn15');
		has(raster(array('upgrade', '--dry-run'), $env)[1], 'zzn15/ has no config/raster-version: upgrade takes it as Raster '.$version.' and runs no steps');
		check(!is_file("$app/config/raster-version"));
		list($code, $out) = raster(array('upgrade'), $env);
		same(0, $code, $out);
		same("zzn15/ had no config/raster-version: taken as Raster $version, no upgrade steps run (for an app from Raster 1.x, write 1.0.0 in that file and run upgrade again)", trim($out));
		same("$version\n", file_get_contents("$app/config/raster-version"));
		has(raster(array('upgrade'), $env)[1], "zzn15/ is at Raster $version, nothing to change");
	} finally {
		exec('rm -rf '.escapeshellarg($app));
	}
});

// ## 2.1.8 batch G: MCP themes

test('M19', 'the MCP view tools take a theme only as a folder directly under views/', function () use ($base, $root, $views) {
	$themes = dirname($views);
	$outside = sys_get_temp_dir().'/raster-demo-theme-'.getmypid();
	@mkdir($outside);
	file_put_contents("$outside/secret.json", '{"secret":"sk-live-123"}');
	test_fixture("$themes/zzlinked");
	symlink($outside, "$themes/zzlinked");
	try {
		// over HTTP with the content token, the site's .mcp.json and files
		// outside the site stay out of reach
		foreach (array(
			array('read_view', array('view' => '.mcp.json', 'theme' => '../..')),
			array('read_view', array('view' => 'secret.json', 'theme' => 'zzlinked')),
			array('read_view', array('view' => 'secret.json', 'theme' => $outside)),
			array('list_views', array('theme' => '../../..')),
			array('list_views', array('theme' => 'cafe/docs')),
			array('check_view', array('content' => '<!-- dry._layout.head /-->', 'theme' => '../views/cafe')),
		) as $call) {
			$error = null;
			try { mcp($base, $call[0], $call[1]); } catch (Exception $e) { $error = $e->getMessage(); }
			check($error !== null, "{$call[0]} accepted theme '{$call[1]['theme']}'");
			has($error, 'theme', 'a clear error');
			lacks($error, 'sk-live-123');
		}
		// the site's other theme is still there to read
		same('print', mcp($base, 'read_view', array('view' => mcp($base, 'list_views', array('theme' => 'print'))['views'][0], 'theme' => 'print'))['theme']);
		// over stdio, write_view writes nowhere but a theme
		media_made('zz-pwned.html');
		$answers = mcp_stdio(array(
			array('write_view', array('view' => 'zz-pwned.html', 'content' => '<p>x</p>', 'theme' => '../../media')),
			array('write_view', array('view' => 'zz-pwned.html', 'content' => '<p>x</p>', 'theme' => 'zzlinked')),
		));
		check(isset($answers[0]['error']) && isset($answers[1]['error']), 'write_view is refused');
		check(!file_exists("$root/media/zz-pwned.html"), 'nothing in media/');
		check(!file_exists("$outside/zz-pwned.html"), 'nothing through the link');
	} finally {
		@unlink("$themes/zzlinked");
		foreach (glob("$outside/*") as $file) unlink($file);
		@rmdir($outside);
	}
});

// ## 2.1.8 batch H: row loop
test('C53', 'a print inside a render block fills every row of a long list: 1,500 rows, staff-only blocks hidden in all of them (#54)', function () use ($base, $views, $root) {
	$dir = "$root/demo/models/zzmany";
	test_fixture($dir); @mkdir($dir);
	$row = '<li><!-- print.n -->r0<!-- /print.n --> <!-- print.feed.site_url -->SITE<!-- /print.feed.site_url -->'
		.'<!-- print.if.is_editor --><b>STAFF</b><!-- /print.if.is_editor --><!-- print.if.logged_out --><i>guest</i><!-- /print.if.logged_out --></li>';
	try {
		with_file("$dir/zzmany.php", "<?php\nclass zzmany {\n\tfunction rows() { \$rows = array(); for (\$i = 1; \$i <= 1500; \$i++) \$rows[] = array('n' => \"r\$i\"); return \$rows; }\n}\n", function () use ($base, $views, $row) {
			with_file("$views/zz-many.html", "<!doctype html>\n<html>\n<body>\n<ul><!-- render.zzmany.rows -->$row<!-- /render.zzmany.rows --></ul>\n</body>\n</html>\n", function () use ($base) {
				list($code, $body) = http('GET', "$base/zz-many");
				same(200, $code, $body);
				same(1500, substr_count($body, '<li>'), 'rows:');
				has($body, '<li>r1500 ');
				lacks($body, '<!-- print.', 'every copy is filled');
				lacks($body, 'SITE', 'the site address is in every row');
				lacks($body, 'STAFF', 'a staff-only block shows in no row');
				same(1500, substr_count($body, '<i>guest</i>'));
			});
		});
	} finally {
		@rmdir($dir);
	}
});

// ## 2.1.8 wave 2: sign-up, account email change

// posts each request at the same moment and answers [code, body, location] for each
function posts_at_once($requests) {
	$multi = curl_multi_init();
	$handles = array();
	foreach ($requests as $r) {
		// as a browser posts: the form's own stamp, shown a minute ago
		if (isset($r[1]['raster_form']) && !isset($r[1]['raster_ts'])) $r[1]['raster_ts'] = stamp_for_tests(60);
		$handle = curl_init($r[0]);
		curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => isset($r[2]) ? array('Cookie: '.$r[2]) : array(), CURLOPT_POSTFIELDS => http_build_query($r[1])));
		curl_multi_add_handle($multi, $handle);
		$handles[] = $handle;
	}
	do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.05); } while ($running);
	$answers = array();
	foreach ($handles as $handle) {
		$raw = curl_multi_getcontent($handle);
		$size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$location = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $m) ? $m[1] : null;
		$answers[] = array(curl_getinfo($handle, CURLINFO_HTTP_CODE), substr($raw, $size), $location);
		curl_multi_remove_handle($multi, $handle);
	}
	curl_multi_close($multi);
	return $answers;
}

test('G24', 'two sign-ups at once with one email make one account (#77)', function () use ($base, $db, $maildir) {
	cms_store::connect();
	// a second server on the same database, so the two posts really overlap
	$other = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir"));
	for ($round = 1; $round <= 3; $round++) {
		$email = "twice$round@example.com";
		$form = array('raster_form' => 'authentication.register', 'email' => $email, 'password' => 'long password', 'password_again' => 'long password');
		$answers = posts_at_once(array(array("$base/register", $form + array('name' => 'First')), array("$other/register", $form + array('name' => 'Second'))));
		usort($answers, function ($a, $b) { return $a[0] - $b[0]; });
		same(1, (int)R::count('user', ' LOWER(email) = ? ', array($email)), "round $round: accounts with $email");
		same(array(200, 303), array($answers[0][0], $answers[1][0]), "round $round: one sign-up wins");
		has($answers[0][1], 'There is already an account with that email.', "round $round: the other is told");
		has($answers[1][2], '?done=registered', "round $round");
		$winner = R::findOne('user', ' LOWER(email) = ? ', array($email));
		check(password_verify('long password', $winner->password), "round $round: the account keeps its password");
	}
});
test('G24', 'two members changing to one email at once: one gets it (#77)', function () use ($base, $db, $maildir) {
	cms_store::connect();
	$other = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir"));
	for ($round = 1; $round <= 2; $round++) {
		$wanted = "shared$round@example.com";
		$requests = array();
		foreach (array(array('a', $base), array('b', $other)) as $who) {
			$email = "mover{$who[0]}$round@example.com";
			same(0, raster(array('user', $email, '--role=member', '--password=old password'))[0]);
			$cookie = login($who[1], $email, 'old password');
			$token = token_in(http('GET', "$who[1]/account", null, array("Cookie: $cookie"))[1]);
			// a new password too, so each save hashes one, as a member changing both would
			$requests[] = array("$who[1]/account", array('raster_form' => 'authentication.account', 'name' => 'Mover', 'email' => $wanted, 'password' => 'new password', 'current_password' => 'old password', 'csrf' => $token), $cookie);
		}
		$answers = posts_at_once($requests);
		usort($answers, function ($a, $b) { return $a[0] - $b[0]; });
		same(1, (int)R::count('user', ' LOWER(email) = ? ', array($wanted)), "round $round: accounts with $wanted");
		same(array(200, 303), array($answers[0][0], $answers[1][0]), "round $round: one change wins");
		has($answers[0][1], 'Another account uses that email.', "round $round: the other is told");
	}
});
test('G24', 'a member changing to an email while someone signs up with it, in other letters: one account has it (#77)', function () use ($base, $db, $maildir) {
	cms_store::connect();
	$other = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir"));
	for ($round = 1; $round <= 3; $round++) {
		$wanted = "contested$round@example.com";
		$member = "changer$round@example.com";
		same(0, raster(array('user', $member, '--role=member', '--password=old password'))[0]);
		$cookie = login($base, $member, 'old password');
		$token = token_in(http('GET', "$base/account", null, array("Cookie: $cookie"))[1]);
		$change = array('raster_form' => 'authentication.account', 'name' => 'Changer', 'email' => $wanted, 'password' => 'new password', 'current_password' => 'old password', 'csrf' => $token);
		$signup = array('raster_form' => 'authentication.register', 'name' => 'Newcomer', 'email' => strtoupper($wanted), 'password' => 'long password', 'password_again' => 'long password');
		$answers = posts_at_once(array(array("$base/account", $change, $cookie), array("$other/register", $signup)));
		same(1, (int)R::count('user', ' LOWER(email) = ? ', array($wanted)), "round $round: accounts with $wanted");
		$codes = array($answers[0][0], $answers[1][0]);
		sort($codes);
		same(array(200, 303), $codes, "round $round: one wins");
		$loser = $answers[0][0] === 200 ? $answers[0][1] : $answers[1][1];
		check(strpos($loser, 'Another account uses that email.') !== false || strpos($loser, 'There is already an account with that email.') !== false, "round $round: the other is told");
	}
});

// ## 2.1.8 wave 2: newsletter sign-ups

test('H13', 'signing up twice: one subscriber, one mail within 5 minutes, the same link later, mailed by a listener; a reset asked twice sends one mail', function () use ($base) {
	$before = count(mails());
	foreach (array('Again@example.com', 'again@example.com ') as $email) {
		same("$base/journal?done=check_email", header_value(http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => $email))[2], 'Location'), 'the same answer both times:');
	}
	database::instance('cms');
	same(1, (int)R::getCell('SELECT COUNT(*) FROM subscriber WHERE email = ?', array('again@example.com')), 'subscribers:');
	same(array('again@example.com'), array_column(array_slice(mails(), $before), 'to'), 'mails within 5 minutes:');
	// six minutes later the same link goes again
	R::exec('UPDATE subscriber SET confirm_sent_at = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() - 6 * 60), 'again@example.com'));
	same("$base/journal?done=check_email", header_value(http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => 'again@example.com'))[2], 'Location'));
	$sent = array_slice(mails(), $before);
	same(array('again@example.com', 'again@example.com'), array_column($sent, 'to'));
	$links = array();
	foreach ($sent as $mail) $links[] = preg_match('/token=([a-f0-9]{40})/', $mail['text'], $t) ? $t[1] : '';
	same(array_fill(0, 2, R::getCell('SELECT token FROM subscriber WHERE email = ?', array('again@example.com'))), $links, 'both mails carry the link that works:');
	has(http('GET', "$base/letters/confirm?token={$links[0]}")[1], '<h1>You are subscribed</h1>');
	same(array('newsletter.confirmation_mail'), mcp($base, 'site_overview')['events']['newsletter.subscribed'], 'who sends the confirmation:');
	// a reset asked twice: one mail, and its link still works
	raster(array('user', 'twice-reset@cafe.test', '--role=member', '--password=first password'));
	$before = count(mails());
	foreach (array(1, 2) as $round) {
		list($status, , $headers) = http('POST', "$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'twice-reset@cafe.test'));
		same("$base/forgot?done=reset_sent", header_value($headers, 'Location'), "reset $round:");
	}
	$sent = array_slice(mails(), $before);
	same(array('twice-reset@cafe.test'), array_column($sent, 'to'), 'reset mails:');
	check(preg_match('/token=([a-f0-9]{48})/', $sent[0]['text'], $t), 'reset link');
	same("$base/members?done=password_changed", header_value(http('POST', "$base/password/new?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'second password', 'password_again' => 'second password'))[2], 'Location'), 'the first link works:');
});

test('H14', 'an interrupted send goes on where it stopped (#86)', function () use ($base) {
	database::instance('cms');
	$readers = R::getCol("SELECT email FROM subscriber WHERE status = 'confirmed' ORDER BY id");
	check(count($readers) >= 3, 'readers: '.count($readers));
	$got = function ($since) {
		$to = array();
		foreach (array_slice(mails(), $since) as $mail) if ($mail['subject'] === 'Journal') $to[] = $mail['to'];
		return array_count_values($to);
	};
	$before = count(mails());
	// a model that stops the run when the second mail is handed over, as a
	// dropped SSH session would
	$dir = dirname(__DIR__).'/demo/models/zzstop';
	test_fixture($dir); @mkdir($dir);
	try {
		with_file("$dir/zzstop.php", "<?php\nclass zzstop {\n\tstatic \$n = 0;\n\tstatic function listens() { return array('mail.sent' => 'stop'); }\n\tfunction stop() { if (++self::\$n === (int)getenv('ZZ_STOP_AFTER')) exit(3); }\n}\n", function () use ($base) {
			same(3, raster(array('send', '/journal'), array('RASTER_URL' => "$base/", 'ZZ_STOP_AFTER' => '2'))[0], 'the run was stopped');
		});
	} finally { @rmdir($dir); }
	same(2, array_sum($got($before)), 'mailed before the stop');
	list($code, $out) = raster(array('send', '/journal', '--dry-run'), array('RASTER_URL' => "$base/"));
	has($out, 'Would send "Journal" to '.(count($readers) - 1).' subscriber(s) who don\'t have it yet');
	list($code, $out) = raster(array('send', '/journal'), array('RASTER_URL' => "$base/"));
	same(0, $code, $out);
	has($out, 'Sent "Journal" to '.(count($readers) - 1).' more subscriber(s), '.count($readers).' in all');
	$counts = $got($before);
	same(1, $counts[$readers[0]], 'the first reader got it once:');
	// the second was handed over as the run was stopped, before it was noted
	same(2, $counts[$readers[1]], 'the second reader:');
	foreach (array_slice($readers, 2) as $email) same(1, isset($counts[$email]) ? $counts[$email] : 0, "$email:");
	list($code, $out) = raster(array('send', '/journal'), array('RASTER_URL' => "$base/"));
	same(1, $code, $out);
	has($out, '/journal was already sent');
	has($out, 'to '.count($readers).' subscribers');
});
test('H15', 'readers a send failed get it on a plain rerun, over one SMTP connection, and no password is printed (#86)', function () use ($base, $root, $tmp) {
	database::instance('cms');
	$readers = R::getCol("SELECT email FROM subscriber WHERE status = 'confirmed' ORDER BY id");
	$port = free_port();
	$log = "$tmp/smtp-resume.log";
	check($up = fake_smtp($port, $log, 'plain'), 'the fake SMTP server answers');
	// it serves one connection at a time: let this one go
	fclose($up);
	// an '@' in the password: SMTP takes the last one as the end of it, and
	// so must the printed destination
	$env = array('RASTER_URL' => "$base/", 'RASTER_MAIL' => "smtp://letters:s3cret@pass@127.0.0.1:$port");
	// accepted deliveries per address, and SMTP sessions, in the transcript so far
	$accepted = function () use ($log) {
		$text = (string)@file_get_contents($log);
		preg_match_all('/^C: RCPT TO:<([^>]+)>\nS: 250 /m', $text, $m);
		return array(array_count_values($m[1]), substr_count($text, "C: EHLO "));
	};
	file_put_contents("$log.refuse", $readers[1]."\n");
	list($code, $out) = raster(array('send', '/about', '--dry-run'), $env);
	has($out, 'via smtp://127.0.0.1:'.$port);
	lacks($out, 'pass@');
	list($code, $out) = raster(array('send', '/about'), $env);
	same(1, $code, $out);
	has($out, $readers[1].': ');
	has($out, 'Sent "About" to '.(count($readers) - 1).' subscriber(s), 1 failed', $out);
	lacks($out, 'pass@');
	list($counts, $connections) = $accepted();
	same(1, $connections, 'one connection for the whole run:');
	has((string)file_get_contents($log), 'C: '.base64_encode('s3cret@pass')."\n", 'the whole password is sent:');
	same(count($readers) - 1, array_sum($counts));
	unlink("$log.refuse");
	list($code, $out) = raster(array('send', '/about'), $env);
	same(0, $code, $out);
	has($out, 'Sent "About" to 1 more subscriber(s), '.count($readers).' in all');
	list($counts) = $accepted();
	foreach ($readers as $email) same(1, isset($counts[$email]) ? $counts[$email] : 0, "$email:");
	same(1, raster(array('send', '/about'), $env)[0], 'sent to everyone: once only');
	list($code, $out) = raster(array('send', '/about', '--again'), $env);
	same(0, $code, $out);
	has($out, 'Sent "About" to '.count($readers).' subscriber(s)');
	list($counts) = $accepted();
	foreach ($readers as $email) same(2, $counts[$email], "--again mails $email again:");
	list($code, $out) = raster(array('send', '/about', '--to=test@example.com'), $env);
	same(0, $code, $out);
	lacks($out, 'pass@');
});

// ## 2.1.8 wave 2: content saves and the cache bump

// MCP calls sent all at once, spread over $bases (each its own server on the
// demo's database, as two PHP workers are); the answers in the same order
function mcp_at_once($bases, $calls) {
	$multi = curl_multi_init();
	$handles = array();
	foreach ($calls as $i => $call) {
		$handle = curl_init($bases[$i % count($bases)].'/mcp');
		curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Authorization: Bearer demo-token', 'Content-Type: application/json'),
			CURLOPT_POSTFIELDS => json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $call[0], 'arguments' => $call[1])))));
		curl_multi_add_handle($multi, $handle);
		$handles[] = $handle;
	}
	do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.05); } while ($running);
	$answers = array();
	foreach ($handles as $handle) {
		$answer = json_decode(curl_multi_getcontent($handle), true);
		if (!isset($answer['result']) || !empty($answer['result']['isError'])) throw new Exception('mcp: '.curl_multi_getcontent($handle));
		$answers[] = $answer['result']['structuredContent'];
		curl_multi_remove_handle($multi, $handle);
	}
	curl_multi_close($multi);
	return $answers;
}

test('E33', 'items saved at once with one title each get their own slug and page, and page saves at once keep each other\'s fields (#77)', function () use ($base, $db, $maildir) {
	$second = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token'));
	$about = mcp($base, 'get_page', array('page' => '/about'))['fields'];
	$ids = array();
	try {
		$slugs = array();
		for ($round = 0; $round < 4; $round++) {
			$calls = array();
			for ($i = 0; $i < 4; $i++) $calls[] = array('create_item', array('collection' => 'journal', 'fields' => array('title' => 'Harvest supper', 'author' => 'Ana', 'summary' => "<p>Round $round, editor $i</p>")));
			foreach (mcp_at_once(array($base, $second), $calls) as $item) {
				$ids[] = $item['id'];
				$slugs[] = $item['slug'];
			}
		}
		same(16, count(array_unique($slugs)), 'distinct slugs of 16:');
		foreach (array_slice($slugs, -4) as $slug) {
			list($status, $body) = http('GET', "$base/journal/journal_item/$slug");
			same(200, $status);
			same(1, substr_count($body, '<article'), "/journal/journal_item/$slug shows one article:");
		}
		// two editors on one page, one field each
		for ($round = 1; $round <= 4; $round++) {
			mcp_at_once(array($base, $second), array(
				array('update_page', array('page' => '/about', 'fields' => array('heading' => "Heading $round"))),
				array('update_page', array('page' => '/about', 'fields' => array('body' => "<p>Body $round</p>"))),
			));
			$fields = mcp($base, 'get_page', array('page' => '/about'))['fields'];
			same(array("Heading $round", "<p>Body $round</p>"), array($fields['heading'], $fields['body']), "round $round:");
		}
	} finally {
		foreach ($ids as $id) mcp($base, 'delete_item', array('collection' => 'journal', 'id' => $id));
		mcp($base, 'update_page', array('page' => '/about', 'fields' => array('heading' => $about['heading'], 'body' => $about['body'])));
	}
});

$changed_seen = array();
function demo_content_changed() {
	global $changed_seen, $db;
	$other = test_pdo($db);
	$changed_seen[] = (int)$other->query('SELECT COUNT(*) FROM journaldata')->fetchColumn();
}
test('L12', 'content_changed and the page cache wait for the commit: a rolled back write leaves cached pages and sends nothing, a committed one clears them once (#81)', function () use ($db, $maildir) {
	global $changed_seen;
	$cached = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_PAGE_CACHE' => 'on'));
	http('GET', "$cached/journal");
	same('hit', header_value(http('GET', "$cached/journal")[2], 'X-Raster-Cache'));
	cms_store::connect();
	$changed_seen = array();
	event::bind('content_changed')->to(null, 'demo_content_changed');
	$kept = array();
	try {
		$rows = R::count('journaldata');
		try {
			cms_records::transaction(function () {
				cms_store::save_item('journaldata', 0, array('title' => 'Never printed', 'author' => 'Ana'), array('title', 'author'));
				staff_booking(array('name' => 'Rolled back', 'date' => next_open('thu', 4), 'people' => 2));
				cms_records::refuse('changed_my_mind');
			});
			check(false, 'refused');
		} catch (cms_refused $e) {
			same(array('changed_my_mind'), $e->problems);
		}
		same(array(), $changed_seen, 'content_changed after a rollback:');
		list(, $body, $headers) = http('GET', "$cached/journal");
		same('hit', header_value($headers, 'X-Raster-Cache'), 'the cached page stays');
		lacks($body, 'Never printed');
		cms_records::transaction(function () use (&$kept) {
			$kept[] = cms_store::save_item('journaldata', 0, array('title' => 'Printed after the commit', 'author' => 'Ana'), array('title', 'author'))['id'];
			$kept[] = cms_store::save_item('journaldata', 0, array('title' => 'Printed too', 'author' => 'Ana'), array('title', 'author'))['id'];
		});
		same(array($rows + 2), $changed_seen, 'content_changed once, its listener seeing the rows:');
		list(, $body, $headers) = http('GET', "$cached/journal");
		same('miss', header_value($headers, 'X-Raster-Cache'));
		has($body, 'Printed after the commit');
	} finally {
		event::unbind('content_changed')->from(null, 'demo_content_changed');
		foreach ($kept as $id) cms_store::delete_item('journaldata', $id);
	}
});

// ## Login links

test('G25', 'raster login-link logs in once, in production too, never from the page cache', function () use ($tmp, $maildir) {
	$port = free_port();
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => test_db("$tmp/login-link.sqlite"), 'RASTER_URL' => "http://127.0.0.1:$port/");
	same(0, raster(array('schema', '--apply'), $env)[0]);
	raster(array('user', 'owner@cafe.example', '--role=editor', '--password=owner password'), $env);
	$site = server($port, array_merge($env, array('RASTER_MAIL' => "log://$maildir")));
	http('GET', "$site/");
	same('hit', header_value(http('GET', "$site/")[2], 'X-Raster-Cache'));
	list($code, $link) = raster(array('login-link', 'owner@cafe.example'), $env);
	same(0, $code, $link);
	list($status, , $headers) = http('GET', trim($link));
	same(303, $status);
	same(null, header_value($headers, 'X-Raster-Cache'), 'the link was answered from the page cache');
	same("$site/", header_value($headers, 'Location'));
	check(cookie_from($headers) !== '', 'the link did not log in');
	list(, $link) = raster(array('login-link', 'owner@cafe.example', '--next=/staff'), $env);
	list(, , $headers) = http('GET', trim($link));
	same("$site/staff", header_value($headers, 'Location'));
	same(200, http('GET', "$site/staff", null, array('Cookie: '.cookie_from($headers)))[0], 'logged in as the editor');
	list(, , $headers) = http('GET', trim($link));
	same('', cookie_from($headers), 'a link works once');
	same("$site/", header_value($headers, 'Location'));
	same(2, raster(array('login-link', 'nobody@cafe.example'), $env)[0]);
});

// ## Phase 1 groundwork: mail after the answer, encoded headers

test('I10', 'a form that sends mail answers at once, even when the mail server never does (#87)', function () use ($root, $tmp, $db) {
	$port = free_port();
	$log = "$tmp/smtp-silent.log";
	check($up = fake_smtp($port, $log, 'silent'), 'the silent SMTP server takes connections');
	fclose($up);
	// workers of their own, so a held session would show as a held request
	$site = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "smtp://127.0.0.1:$port", 'PHP_CLI_SERVER_WORKERS' => '4'));
	raster(array('user', 'waiting@cafe.test', '--role=member', '--password=waiting password'));
	$cookie = login($site, 'waiting@cafe.test', 'waiting password');
	$token = token_in(http('GET', "$site/visit", null, array("Cookie: $cookie"))[1]);
	check($token !== null, 'the form carries the token');
	$body = http_build_query(array('raster_form' => 'contact.form', 'email' => 'a@b.co', 'message' => 'Is the terrace open?', 'csrf' => $token, 'raster_ts' => stamp_for_tests(60)));
	$url = parse_url($site);
	$started = microtime(true);
	$socket = fsockopen($url['host'], $url['port'], $errno, $errstr, 2);
	stream_set_timeout($socket, 20);
	fwrite($socket, "POST /visit HTTP/1.1\r\nHost: {$url['host']}:{$url['port']}\r\nCookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n$body");
	$status = fgets($socket);
	$answered = microtime(true) - $started;
	same('HTTP/1.1 303', substr((string)$status, 0, 12), 'the answer:');
	check($answered < 1, "answered in {$answered}s");
	// the visitor's next request is not held by the session
	$next = microtime(true);
	same(200, http('GET', "$site/members", null, array("Cookie: $cookie"))[0], 'still logged in');
	$waited = microtime(true) - $next;
	check($waited < 1, "the next request waited {$waited}s");
	while (!feof($socket) && fgets($socket) !== false);
	fclose($socket);
	$took = microtime(true) - $started;
	check($took < 10, "the request ended after {$took}s");
	has((string)@file_get_contents($log), 'CONNECTED', 'the mail was tried');
	has((string)@file_get_contents("$tmp/php-errors.log"), 'Raster error: Mail: SMTP error after connect', 'the failure is logged');
});

test('I10', 'a visitor who leaves before a long page has loaded still has the mail sent', function () use ($root, $tmp) {
	$dir = "$tmp/mail-left";
	$script = "$tmp/left-early.php";
	file_put_contents($script, '<?php require '.var_export("$root/system/boot.php", true).'; boot::$appname = "demo"; boot::cli();'
		.' ob_start(); mail::send("ana@example.com", "Left early", "<p>Hi</p>"); echo str_repeat("x", 4 << 20);');
	$port = free_port();
	$process = proc_open(php_argv('-S', "127.0.0.1:$port", $script), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root,
		array('PATH' => getenv('PATH'), 'RASTER_APP' => 'demo', 'RASTER_MAIL' => "log://$dir", 'RASTER_ENV' => ''));
	$GLOBALS['servers']['left-early-'.$port] = $process;
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	$socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
	fwrite($socket, "GET / HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n");
	fread($socket, 100);
	fclose($socket);
	for ($i = 0; $i < 60 && !glob("$dir/*.eml"); $i++) usleep(50000);
	same(1, count(glob("$dir/*.eml") ?: array()), 'mails sent:');
});

test('I11', 'mail headers are encoded: names, long subjects, and the sender for bounces (#124)', function () use ($root, $tmp) {
	$dir = "$tmp/mail-headers";
	$args = "$tmp/sendmail-args.txt";
	$sendmail = "$tmp/fake-sendmail.sh";
	file_put_contents($sendmail, "#!/bin/sh\necho \"\$@\" > ".escapeshellarg($args)."\ncat >/dev/null\n");
	chmod($sendmail, 0755);
	$subject = trim(str_repeat('Ședință lungă despre cafea ', 6));
	$send = function ($dsn, $ini = '') use ($root, $subject) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli();'
			.' echo mail::send("Ana Pâr <ana@example.com>", '.var_export($subject, true).', "<p>Hi</p>") ? "sent" : "failed: ".mail::$last_error;';
		return shell_exec('RASTER_MAIL_FROM= RASTER_MAIL='.escapeshellarg($dsn).' '.php_cmd().' '.$ini.' -r '.escapeshellarg($code).' 2>&1');
	};
	same('sent', $send("log://$dir"));
	$files = glob("$dir/*.eml");
	same(1, count($files));
	$raw = file_get_contents($files[0]);
	$head = "\r\n".substr($raw, 0, strpos($raw, "\r\n\r\n"));
	has($head, "\r\nFrom: =?UTF-8?B?".base64_encode('Raster Café').'?= <hello@cafe.test>', 'the sender name:');
	check(preg_match('/^To: ([^\r\n]*)/m', $head, $to) && strpos($to[1], '=?UTF-8?B?') !== false && strpos($to[1], 'Pâr') === false, 'the recipient name: '.$to[1]);
	check(preg_match('/^Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m', $head, $m), 'a subject');
	preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/', $m[1], $words);
	check(count($words[0]) > 1, 'a long subject is split: '.$m[1]);
	foreach ($words[0] as $word) check(strlen($word) <= 75, 'an encoded word of '.strlen($word).' characters');
	same($subject, mb_decode_mimeheader(preg_replace('/\r\n[ \t]+/', ' ', $m[1])), 'the subject reads back:');
	check(!preg_match('/[\x80-\xff]/', $head), 'no raw 8-bit bytes in the headers');
	same('sent', $send('mail://', '-d '.escapeshellarg("sendmail_path=$sendmail")));
	has((string)@file_get_contents($args), '-fhello@cafe.test', 'sendmail is told the sender:');
});

// ## P. Bookings: the bundled booking model, as the café and its salon use it

// the newest email to $to, and the guest's link in it (the raw token)
function mail_to($to) {
	foreach (array_reverse(mails()) as $mail) if ($mail['to'] === $to) return $mail;
	return null;
}
function link_token($mail) {
	return $mail && preg_match('#/booking\?token=([a-f0-9]{64})#', $mail['html'], $m) ? $m[1] : null;
}
test('P1', 'setup records make the café\'s slots: sittings Tuesday to Sunday, closed Mondays and on 25 December, times as radio buttons', function () use ($base) {
	$page = http('GET', "$base/visit?date=".next_open('tue'))[1];
	has($page, 'name="time" value="19:00"');
	has($page, 'name="time" value="21:00"');
	has($page, '19:00 to 21:00, ', 'a sitting lasts until the next one');
	$form = substr($page, strpos($page, 'value="booking.form"'));
	$form = substr($form, 0, strpos($form, '</form>'));
	lacks($form, '<script', 'no JavaScript to book');
	lacks(http('GET', "$base/visit?date=".date('Y-m-d', strtotime('next monday')))[1], 'name="time"', 'Mondays have no hours row');
	has($page, '<button class="day" name="date" value="'.next_open('tue').'">', 'the days with a free table, as a GET form');
	lacks($page, 'value="'.date('Y-m-d', strtotime('next monday')).'"');
	// 25 December is beyond the horizon of two months most of the year: the
	// slots say what is closed whoever asks
	$christmas = date('Y').'-12-25' < date('Y-m-d') ? (date('Y') + 1).'-12-25' : date('Y').'-12-25';
	cms_records::forget();
	booking::forget();
	same(array(), booking::starts($christmas, 'dining-room'), 'closed on 25 December');
	// the setup is records staff edit in the page
	list($code, $out) = raster(array('render', '/staff/setup', '--as=editor'));
	same(0, $code, $out);
	foreach (array('bookingresource', 'bookinghours', 'bookingclosed', 'bookingservice') as $type) has($out, "$type('", "the $type list is editable");
	has($out, 'Dining room');
});
test('P3', 'the last places: one booking gets them, the rest are told the sitting is full, even when they post at once', function () use ($base, $db, $maildir) {
	$day = next_open('thu', 2);
	foreach (array(6, 6, 6) as $i => $people) same(303, book($base, array('name' => "Party $i", 'email' => "party$i@example.com", 'date' => $day, 'time' => '21:00', 'people' => (string)$people))[0]);
	same(303, book($base, array('name' => 'Last two', 'email' => 'last2@example.com', 'date' => $day, 'time' => '21:00', 'people' => '2'))[0], '18 and 2 is 20');
	list($status, $body) = book($base, array('name' => 'One more', 'email' => 'onemore@example.com', 'date' => $day, 'time' => '21:00', 'people' => '1'));
	same(200, $status);
	has($body, 'That sitting is full now.');
	has(http('GET', "$base/visit?date=$day")[1], '21:00 to 23:00, 0 places left', 'the times say so');
	// four guests post for the last two places at once, through two servers
	foreach (array(6, 6, 6) as $i => $people) same(303, book($base, array('name' => "Crowd $i", 'email' => "crowd$i@example.com", 'date' => $day, 'people' => (string)$people))[0]);
	$other = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir"));
	$requests = array();
	for ($i = 0; $i < 4; $i++) {
		$requests[] = array(($i % 2 ? $other : $base)."/visit?date=$day", array('raster_form' => 'booking.form', 'resource' => 'dining-room', 'name' => "Racer $i", 'email' => "racer$i@example.com", 'date' => $day, 'time' => '19:00', 'people' => '2', 'terms' => '1'));
	}
	$answers = posts_at_once($requests);
	$booked = array_filter($answers, function ($a) { return $a[0] === 303 && strpos((string)$a[2], 'done=booked') !== false; });
	same(1, count($booked), 'one gets the last two: '.json_encode(array_map(function ($a) { return $a[0]; }, $answers)));
	foreach ($answers as $a) if ($a[0] !== 303) has($a[1], 'That sitting is full now.');
	database::instance('cms');
	same(20, (int)R::getCell("SELECT SUM(people) FROM bookingdata WHERE date = ? AND time LIKE '19:00%' AND status IN ('requested', 'confirmed')", array($day)));
});
test(array('P4', 'R8'), 'the staff page from the recipe: counts, requests, an agenda with the places left, actions in place, a phone booking from the card', function () use ($base) {
	same(303, http('GET', "$base/staff")[0], 'visitors get the login page');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	database::instance('cms');
	$requests = (int)R::count('bookingdata', ' status = ? AND date >= ? ', array('requested', date('Y-m-d')));
	$page = http('GET', "$base/staff", null, $h)[1];
	has($page, "<p>$requests requests to answer, ", 'the count for the heading');
	$room = http('GET', "$base/staff?resource=dining-room", null, $h)[1];
	check((bool)preg_match('#<li class="slot  (free|full)">19:00: \d+ places left</li>#', staff_section($room, 'By day')), 'the agenda shows the places left');
	list($code, $out) = raster(array('render', '/staff', '--as=editor'));
	same(0, $code, $out);
	has($out, "booking('status=requested&date>=today&resource=?resource&date=?day&order=date,time') + new", 'the lists are editable, with a card for a new booking');
	// a phone booking from the card: staff skip the notice, the guest gets the email, staff none
	$token = token_in($page);
	$day = next_open('sun', 2);
	$before = count(mails());
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'booking', 'id' => 0, 'fields' => array('name' => 'Phoned in', 'email' => 'phoned@example.com', 'date' => $day, 'time' => '21:00', 'resource' => 'dining-room', 'people' => '4'), 'csrf' => $token), $h);
	same(200, $status, $body);
	$phoned = json_decode($body, true);
	same(array('requested', '23:00'), array($phoned['status'], $phoned['ends']));
	same(array('phoned@example.com'), array_column(array_slice(mails(), $before), 'to'), 'the guest gets their request and link; staff aren\'t emailed about their own');
	check(link_token(mail_to('phoned@example.com')) !== null, 'with the link');
	// an action in place
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'booking', 'id' => $phoned['id'], 'action' => 'confirm', 'csrf' => $token), $h);
	same(200, $status);
	same('confirmed', json_decode($body, true)['status']);
	same('Your booking is confirmed', mail_to('phoned@example.com')['subject']);
	// moving it in the page keeps it confirmed and tells the guest
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'booking', 'id' => $phoned['id'], 'fields' => array('time' => '19:00'), 'csrf' => $token), $h);
	same(200, $status, $body);
	same(array('confirmed', '21:00'), array(json_decode($body, true)['status'], json_decode($body, true)['ends']));
	same('Your booking has moved', mail_to('phoned@example.com')['subject']);
});
test('P5', 'needs attention: a day closed under its bookings is flagged, and confirming one is refused with the reason', function () use ($base) {
	$day = next_open('wed');
	database::instance('cms');
	$ioana = R::findOne('bookingdata', ' email = ? AND date = ? AND status = ? ', array('ioana@example.com', $day, 'requested'));
	check((bool)$ioana, 'Ioana\'s request (P2)');
	$closed = mcp($base, 'create_item', array('collection' => 'bookingclosed', 'fields' => array('date' => $day, 'note' => 'Plumbing')));
	try {
		$staff = login($base, 'staff@cafe.test', 'staff password');
		$page = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
		$attention = staff_section($page, 'Needs attention');
		same(2, substr_count($attention, 'Ioana'), 'both of Ioana\'s requests that day');
		check((bool)preg_match('#<td class="attention">(?:<!--[^>]*-->)?closed#', $attention), 'with the reason: '.$attention);
		check((bool)preg_match('#<p>\d+ requests to answer, [1-9]\d* bookings that need attention\.</p>#', $page), 'counted for the heading');
		try {
			mcp($base, 'run_action', array('collection' => 'booking', 'id' => (int)$ioana->id, 'action' => 'confirm'));
			check(false, 'a booking on a closed day was confirmed');
		} catch (Exception $e) {
			has($e->getMessage(), 'closed');
		}
		same('requested', R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($ioana->id)), 'nothing changed');
		// the editor says why in the café's words
		$editor = file_get_contents(dirname(__DIR__).'/demo/i18n/en/raster_editor.php');
		has($editor, "'problem_closed'");
		same('cancelled', mcp($base, 'run_action', array('collection' => 'booking', 'id' => (int)$ioana->id, 'action' => 'cancel'))['status'], 'cancel goes through');
		same(1, substr_count(staff_section(http('GET', "$base/staff", null, array("Cookie: $staff"))[1], 'Needs attention'), 'Ioana'), 'the cancelled one needs attention no more');
	} finally {
		mcp($base, 'delete_item', array('collection' => 'bookingclosed', 'id' => $closed['id']));
	}
});
test(array('P6', 'O1'), 'the manage link: a GET shows the booking, a POST from the page cancels or moves it; dead after a cancel, never cached, never in the sitemap or an export', function () use ($base, $db, $maildir, $tmp) {
	$day = next_open('sun', 3);
	same(303, book($base, array('name' => 'Lia', 'email' => 'lia@example.com', 'date' => $day))[0]);
	$raw = link_token(mail_to('lia@example.com'));
	check($raw !== null, 'the request email has the link');
	database::instance('cms');
	$id = (int)R::getCell('SELECT id FROM bookingdata WHERE email = ?', array('lia@example.com'));
	$before = count(mails());
	list($status, $body, $headers) = http('GET', "$base/booking?token=$raw");
	same(200, $status);
	has($body, '<dd>Lia</dd>');
	has($body, "<dd>$day at 19:00 to 21:00</dd>");
	same('same-origin', header_value($headers, 'Referrer-Policy'));
	http('GET', "$base/booking?token=$raw&do=cancel");
	http('HEAD', "$base/booking?token=$raw");
	same('requested', R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($id)), 'a GET changes nothing');
	same($before, count(mails()), 'and sends nothing');
	// Change: a GET form picks the day and keeps the token; the times are the booking's
	$later = next_open('sun', 4);
	$change = http('GET', "$base/booking?token=$raw&date=$later")[1];
	has($change, 'name="token" value="'.$raw.'"', 'the day form keeps the link');
	has($change, 'name="time" value="21:00"');
	list($status, , $headers) = http('POST', "$base/booking?token=$raw&date=$later", array('raster_form' => 'booking.manage', 'do' => 'change', 'time' => '21:00'));
	same(303, $status);
	same("$base/booking?done=rescheduled", header_value($headers, 'Location'), 'without the token');
	same(array("$later 21:00", 'requested'), array(R::getCell('SELECT date FROM bookingdata WHERE id = ?', array($id)).' '.substr((string)R::getCell('SELECT time FROM bookingdata WHERE id = ?', array($id)), 0, 5), R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($id))));
	same(array('lia@example.com', 'staff@cafe.test'), array_column(array_slice(mails(), $before), 'to'), 'booking_changed to the guest, booking_new to staff');
	has(http('GET', "$base/booking?done=rescheduled")[1], 'Your booking has moved.');
	lacks(http('GET', "$base/booking?done=rescheduled")[1], 'This link no longer works');
	has(http('GET', "$base/booking?token=$raw")[1], "<dd>$later at 21:00 to 23:00</dd>", 'the first email\'s link follows the booking');
	// a post from another site is refused
	same(403, http('POST', "$base/booking?token=$raw", array('raster_form' => 'booking.manage', 'do' => 'cancel'), array('Origin: https://evil.example'))[0]);
	// Cancel
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/booking?token=$raw", array('raster_form' => 'booking.manage', 'do' => 'cancel'));
	same(303, $status);
	same("$base/booking?done=cancelled", header_value($headers, 'Location'));
	same('cancelled', R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($id)));
	same(array('Booking cancelled', 'Booking cancelled'), array_column(array_slice(mails(), $before), 'subject'), 'to the guest and to staff');
	has(http('GET', "$base/booking?token=$raw")[1], 'This link no longer works', 'dead after a cancel');
	has(http('GET', "$base/booking?token=nope")[1], 'This link no longer works');
	// never cached, never listed
	$cached = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_PAGE_CACHE' => 'on'));
	same(303, book($cached, array('name' => 'Cache', 'email' => 'cache@example.com', 'date' => $day))[0]);
	$fresh = link_token(mail_to('cache@example.com'));
	foreach (array(1, 2) as $n) check(header_value(http('GET', "$cached/booking?token=$fresh")[2], 'X-Raster-Cache') !== 'hit', 'never a cache hit');
	foreach (array(1, 2) as $n) check(header_value(http('GET', "$cached/visit?date=$day")[2], 'X-Raster-Cache') !== 'hit', 'nor the times');
	lacks(http('GET', "$base/sitemap.xml")[1], '/booking<', 'not in the sitemap');
	check(is_file("$tmp/static/visit/index.html") && !file_exists("$tmp/static/booking/index.html"), 'nor in the export');
	// too_late: within booking_cutoff (120 minutes) of the start the page tells the guest to phone
	// (a start of its own today, half an hour from now, unless that is past 23:00)
	$start = time() + 1800;
	if (date('Y-m-d', $start) !== date('Y-m-d') || date('H:i', $start) > '22:59') return;
	$hours = mcp($base, 'create_item', array('collection' => 'bookinghours', 'fields' => array('resource' => 'dining-room', 'days' => 'daily', 'starts' => date('H:i', $start), 'ends' => date('H:i', $start), 'every' => 60)));
	try {
		$soon = staff_booking(array('name' => 'Soon', 'email' => 'soon@example.com', 'date' => date('Y-m-d'), 'time' => date('H:i', $start)));
		$late = link_token(mail_to('soon@example.com'));
		has(http('GET', "$base/booking?token=$late")[1], "It's too close to your booking to change it here.");
		has(http('POST', "$base/booking?token=$late", array('raster_form' => 'booking.manage', 'do' => 'cancel'))[1], "It's too close to your booking");
		same('requested', R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($soon['id'])), 'and it stays');
		cms_records::update('booking', $soon['id'], array('status' => 'cancelled'));
	} finally {
		mcp($base, 'delete_item', array('collection' => 'bookinghours', 'id' => $hours['id']));
	}
});
test('P7', 'the salon: two stylists, two services of different lengths, one booking at a time each', function () use ($base) {
	$day = next_open('tue', 3);
	$page = http('GET', "$base/salon?resource=ana&service=colour&date=$day")[1];
	has($page, 'name="time" value="10:00"> 10:00 to 11:30', 'Colour lasts 90 minutes');
	has($page, 'name="time" value="17:30"> 17:30 to 19:00', 'the last start');
	has($page, '<option value="ana" selected>Ana</option></select>', 'only Ana offers colour');
	lacks($page, '<option value="mihai"');
	// both cut: the select keeps the stylist the URL chose, and only that one
	foreach (array('ana' => 'Ana', 'mihai' => 'Mihai') as $slug => $name) {
		$cut = http('GET', "$base/salon?resource=$slug&service=cut&date=$day")[1];
		$stylists = substr($cut, strpos($cut, '<select name="resource">'));
		$stylists = substr($stylists, 0, strpos($stylists, '</select>'));
		has($stylists, '<option value="'.$slug.'" selected>'.$name.'</option>', 'the stylist stays chosen');
		same(1, substr_count($stylists, 'selected'), $stylists);
	}
	foreach (array('date' => $day, 'resource' => 'ana', 'service' => 'colour') as $name => $value) has($page, '<input type="hidden" name="'.$name.'" value="'.$value.'">', 'what the URL chose comes back as hidden inputs');
	$salon = function ($resource, $service, $date, $fields) use ($base) {
		$choice = array('resource' => $resource, 'service' => $service, 'date' => $date);
		return http('POST', "$base/salon?".http_build_query($choice), array('raster_form' => 'booking.form') + $fields + $choice);
	};
	same(303, $salon('ana', 'colour', $day, array('name' => 'Clara', 'email' => 'clara@example.com', 'time' => '10:00'))[0]);
	$cut = http('GET', "$base/salon?resource=ana&service=cut&date=$day")[1];
	foreach (array('10:00', '10:30', '11:00') as $time) has($cut, '<label class="slot  full"><input type="radio" name="time" value="'.$time.'">', "$time is taken by the colour");
	has($cut, '<label class="slot  free"><input type="radio" name="time" value="11:30">', 'free once it ends');
	list($status, $body) = $salon('ana', 'cut', $day, array('name' => 'Dan', 'email' => 'dan@example.com', 'time' => '10:30'));
	same(200, $status);
	has($body, 'That time was just taken.');
	lacks(http('GET', "$base/salon?resource=mihai&service=cut&date=$day")[1], 'name="time"', 'Mihai doesn\'t work on Tuesdays');
	has(http('GET', "$base/salon?resource=mihai&service=cut&date=".next_open('wed', 3))[1], 'value="10:00"> 10:00 to 10:30', 'and does on Wednesdays');
	list(, $body) = $salon('mihai', 'colour', next_open('wed', 3), array('name' => 'Eve', 'email' => 'eve@example.com', 'time' => '10:00'));
	has($body, 'Pick a service the stylist offers: only Ana does colour.');
	list(, $body) = http('POST', "$base/salon?date=$day", array('raster_form' => 'booking.form', 'date' => $day, 'name' => 'Eve', 'email' => 'eve@example.com', 'time' => '10:00'));
	has($body, 'Pick a stylist.', 'with several resources one must be chosen');
	database::instance('cms');
	same(array('11:30'), array(substr((string)R::getCell("SELECT ends FROM bookingdata WHERE email = 'clara@example.com'"), 0, 5)));
});
test('P8', 'a logged in guest manages their own bookings from /account, without a token', function () use ($base) {
	$member = login($base, 'maria@example.com', 'reset password');
	$h = array("Cookie: $member");
	database::instance('cms');
	$maria = R::findOne('bookingdata', ' name = ? AND status = ? ', array('Maria', 'requested'));
	check((bool)$maria, 'Maria\'s booking (R5)');
	$account = http('GET', "$base/account", null, $h)[1];
	has($account, '<form method="get" action="'.$base.'/booking" class="inline"><button class="secondary" name="booking" value="'.$maria->id.'">Change or cancel</button></form>');
	$page = http('GET', "$base/booking?booking={$maria->id}", null, $h)[1];
	has($page, '<dd>Maria</dd>');
	lacks(http('GET', "$base/booking?booking={$maria->id}")[1], '<dd>Maria</dd>', 'not for visitors');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	has(http('GET', "$base/booking?booking={$maria->id}", null, array("Cookie: $staff"))[1], 'This link no longer works', 'nor for another account: it is the owner\'s');
	list($status, , $headers) = http('POST', "$base/booking?booking={$maria->id}", array('raster_form' => 'booking.manage', 'do' => 'cancel', 'csrf' => token_in($page)), $h);
	same(303, $status);
	same("$base/booking?booking={$maria->id}&done=cancelled", header_value($headers, 'Location'));
	same('cancelled', R::getCell('SELECT status FROM bookingdata WHERE id = ?', array($maria->id)));
	has(http('GET', "$base/account", null, $h)[1], 'people: cancelled');
});

// ## Q. Spam checks on the bundled forms, and the contact form

// forgets the counts, so a test starts with an hour of room
function spam_forget() {
	database::instance('cms');
	if (in_array('formhit', R::inspect())) R::exec('DELETE FROM formhit');
}
function post_to($base, $path, $fields, $ago = 60, $headers = array()) {
	return http('POST', "$base$path", $fields + array('raster_ts' => stamp_for_tests($ago)), $headers);
}

test('Q1', 'a post without a fresh stamp gets the form again, with what was typed', function () use ($spam_base) {
	spam_forget();
	$mails = count(mails());
	$try_again = 'That went by a bit quickly';
	foreach (array('' => 'no stamp', 1 => 'a stamp one second old') as $ago => $why) {
		$fields = array('raster_form' => 'newsletter.signup', 'email' => "held$ago@example.com");
		list($status, $body) = $ago === '' ? post_to($spam_base, '/journal', $fields + array('raster_ts' => '')) : post_to($spam_base, '/journal', $fields, $ago);
		same(200, $status, $why);
		has($body, $try_again, $why);
		has($body, 'value="held'.$ago.'@example.com"', "$why: what was typed comes back");
	}
	database::instance('cms');
	same(0, (int)R::getCell("SELECT COUNT(*) FROM subscriber WHERE email LIKE 'held%'"), 'no subscriber');
	same($mails, count(mails()), 'no mail');
	// sign-up
	list($status, $body) = post_to($spam_base, '/register', array('raster_form' => 'authentication.register', 'name' => 'Bot', 'email' => 'held-bot@example.com', 'password' => 'long enough password', 'password_again' => 'long enough password'), 1);
	same(200, $status, 'register');
	has($body, $try_again, 'register:');
	has($body, 'value="held-bot@example.com"');
	same(0, (int)R::getCell('SELECT COUNT(*) FROM user WHERE email = ?', array('held-bot@example.com')), 'no account');
	// booking
	$day = next_open('tue');
	list($status, $body) = book($spam_base, array('date' => $day, 'name' => 'Held Guest', 'email' => 'held-guest@example.com', 'raster_ts' => ''));
	same(200, $status, 'booking');
	has($body, $try_again, 'booking:');
	has($body, 'value="held-guest@example.com"');
	same(0, (int)R::count('bookingdata', ' email = ? ', array('held-guest@example.com')), 'no booking');
	// the same posts with a stamp as a browser has are taken
	list($status, , $headers) = book($spam_base, array('date' => $day, 'name' => 'Real Guest', 'email' => 'real-guest@example.com'));
	same(303, $status);
	same(1, (int)R::count('bookingdata', ' email = ? ', array('real-guest@example.com')), 'the booking');
});

test('Q1', 'forgot: too many asks answer as for an unknown address', function () use ($spam_base) {
	spam_forget();
	raster(array('user', 'forgetful@cafe.test', '--role=member', '--password=first password'));
	$answers = array();
	$counts = array();
	for ($i = 1; $i <= 4; $i++) {
		// the link sent before is old enough that a new ask sends a new mail,
		// so only the limit can explain a 4th ask that sends none
		database::instance('cms');
		R::exec('UPDATE user SET reset_expires = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() - 7200), 'forgetful@cafe.test'));
		// each ask names the same address; the 4th is over the limit of 3 for it
		list($status, , $headers) = post_to($spam_base, '/forgot', array('raster_form' => 'authentication.forgot', 'email' => 'forgetful@cafe.test'));
		same(303, $status, "ask $i");
		same("$spam_base/forgot?done=reset_sent", header_value($headers, 'Location'), "ask $i answers the same");
		$counts[$i] = count(mails());
	}
	check($counts[3] > $counts[2], 'the 3rd ask sent a mail');
	same($counts[3], $counts[4], 'the 4th ask sent nothing');
});

test('Q2', 'too many posts from one place: slow_down, and nothing stored', function () use ($spam_base, $db, $maildir, $views, $root) {
	spam_forget();
	// one email four times: the 4th is over the limit of 3 for it
	for ($i = 1; $i <= 3; $i++) {
		same(303, post_to($spam_base, '/journal', array('raster_form' => 'newsletter.signup', 'email' => 'again@example.com'))[0], "post $i");
	}
	list($status, $body) = post_to($spam_base, '/journal', array('raster_form' => 'newsletter.signup', 'email' => 'again@example.com'));
	same(200, $status);
	has($body, "That's a lot from one place");
	lacks($body, 'That went by a bit quickly');
	has($body, 'value="again@example.com"');
	// six people behind one address (an office, a phone carrier) are all taken:
	// the per-IP count only flags them
	$office = array('X-Forwarded-For: 203.0.113.70');
	for ($i = 1; $i <= 6; $i++) {
		same(303, post_to($spam_base, '/journal', array('raster_form' => 'newsletter.signup', 'email' => "crowd$i@example.com"), 60, $office)[0], "crowd $i");
	}
	database::instance('cms');
	same(6, (int)R::getCell("SELECT COUNT(*) FROM subscriber WHERE email LIKE 'crowd%'"), 'six subscribers');
	// a record form too (cms_records::submit, as bookings are)
	$model = "<?php\nclass zzshared\n{\n\tstatic function types() {\n\t\treturn array('zzshared' => array('fields' => array('title' => ''), 'create' => 'visitor'));\n\t}\n\tfunction form() { return cms_records::submit('zzshared', 'sent'); }\n}\n";
	$view = "<html><body>\n<!-- print.validation.alert('try_again') --><p>Again, please.</p><!-- /print.validation.alert('try_again') -->\n<!-- print.validation.alert('slow_down') --><p>Slow down.</p><!-- /print.validation.alert('slow_down') -->\n<!-- render.zzshared.form -->\n<form method=\"post\"><input name=\"title\" required maxlength=\"50\"><button>Send</button></form>\n<!-- /render.zzshared.form -->\n</body></html>\n";
	// spam_ip_blocks on holds them back again
	$strict = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_SPAM_LIMITS' => 'on', 'CAFE_SPAM_IP_BLOCKS' => 'on'));
	test_fixture("$root/demo/models/zzshared"); @mkdir("$root/demo/models/zzshared");
	try {
		with_file("$views/zz-shared.html", $view, function () use ($spam_base, $strict, $root, $model, $office) {
			with_file("$root/demo/models/zzshared/zzshared.php", $model, function () use ($spam_base, $strict, $office) {
				for ($i = 1; $i <= 6; $i++) {
					same(303, post_to($spam_base, '/zz-shared', array('raster_form' => 'zzshared.form', 'title' => "Shared $i"), 60, $office)[0], "record $i");
				}
				database::instance('cms');
				same(6, (int)R::count('zzshareddata'), 'six records');
				spam_forget();
				for ($i = 1; $i <= 5; $i++) {
					same(303, post_to($strict, '/zz-shared', array('raster_form' => 'zzshared.form', 'title' => "Strict $i"), 60, $office)[0], "spam_ip_blocks, record $i");
				}
				list($status, $body) = post_to($strict, '/zz-shared', array('raster_form' => 'zzshared.form', 'title' => 'Strict 6'), 60, $office);
				same(200, $status, 'spam_ip_blocks: the 6th');
				has($body, 'Slow down.');
				has($body, 'value="Strict 6"');
				database::instance('cms');
				same(0, (int)R::count('zzshareddata', ' title = ? ', array('Strict 6')), 'not stored');
			});
		});
	} finally {
		@rmdir("$root/demo/models/zzshared");
		database::instance('cms');
		R::exec('DROP TABLE IF EXISTS zzshareddata');
	}
	spam_forget();
	for ($i = 1; $i <= 5; $i++) {
		same(303, post_to($strict, '/journal', array('raster_form' => 'newsletter.signup', 'email' => "strict$i@example.com"), 60, $office)[0], "spam_ip_blocks, post $i");
	}
	list($status, $body) = post_to($strict, '/journal', array('raster_form' => 'newsletter.signup', 'email' => 'strict6@example.com'), 60, $office);
	same(200, $status);
	has($body, "That's a lot from one place");
	has($body, 'value="strict6@example.com"');
	database::instance('cms');
	same(0, (int)R::getCell('SELECT COUNT(*) FROM subscriber WHERE email = ?', array('strict6@example.com')), 'no subscriber');
	same(5, (int)R::getCell("SELECT COUNT(*) FROM subscriber WHERE email LIKE 'strict%'"), 'the five before');
	// the main server has the limits off: the same post is taken there
	global $base;
	same(303, post_to($base, '/journal', array('raster_form' => 'newsletter.signup', 'email' => 'again@example.com'))[0]);
});

test('Q1', 'a record form is covered without code, and a type can turn it off', function () use ($spam_base, $root, $views) {
	spam_forget();
	$model = function ($spam) {
		return "<?php\nclass zzspam\n{\n\tstatic function types() {\n\t\treturn array('zzspam' => array('fields' => array('title' => '', 'note' => ''), 'create' => 'visitor'".($spam ? '' : ", 'spam' => false")."));\n\t}\n\tfunction form() { return cms_records::submit('zzspam', 'sent'); }\n}\n";
	};
	$view = "<html><body>\n<!-- print.validation.alert('try_again') --><p>Again, please.</p><!-- /print.validation.alert('try_again') -->\n<!-- print.validation.alert('slow_down') --><p>Slow down.</p><!-- /print.validation.alert('slow_down') -->\n<!-- render.zzspam.form -->\n<form method=\"post\"><input name=\"title\" required maxlength=\"50\"><input name=\"note\"><button>Send</button></form>\n<!-- /render.zzspam.form -->\n</body></html>\n";
	test_fixture("$root/demo/models/zzspam"); @mkdir("$root/demo/models/zzspam");
	try {
		with_file("$views/zz-spam.html", $view, function () use ($spam_base, $root, $model) {
			foreach (array(true => 'held', false => 'stored') as $spam => $outcome) {
				with_file("$root/demo/models/zzspam/zzspam.php", $model($spam), function () use ($spam_base, $spam, $outcome) {
					$title = "T-$outcome";
					list($status, $body) = http('POST', "$spam_base/zz-spam", array('raster_form' => 'zzspam.form', 'title' => $title, 'raster_ts' => ''));
					database::instance('cms');
					if ($spam) {
						same(200, $status);
						has($body, 'Again, please.');
						has($body, 'value="'.$title.'"');
						same(0, (int)R::count('zzspamdata'), 'a post without a stamp is held');
					} else {
						same(303, $status);
						same(1, (int)R::count('zzspamdata'), 'stored when the type says spam is false');
					}
				});
			}
		});
	} finally {
		@rmdir("$root/demo/models/zzspam");
		R::exec('DROP TABLE IF EXISTS zzspamdata');
	}
});

test('Q1', 'editors are never held', function () use ($spam_base) {
	spam_forget();
	raster(array('user', 'spam-editor@cafe.test', '--role=editor', '--password=editor password'));
	$cookie = login($spam_base, 'spam-editor@cafe.test', 'editor password');
	database::instance('cms');
	$hits = (int)R::getCell('SELECT COUNT(*) FROM formhit');
	$csrf = token_in(http('GET', "$spam_base/visit", null, array("Cookie: $cookie"))[1]);
	check($csrf, 'the editor has a session token');
	list($status) = book($spam_base, array('date' => next_open('wed'), 'name' => 'By Phone', 'email' => 'by-phone@example.com', 'raster_ts' => '', 'csrf' => $csrf), array("Cookie: $cookie"));
	same(303, $status);
	same(1, (int)R::count('bookingdata', ' email = ? ', array('by-phone@example.com')), 'stored');
	same($hits, (int)R::getCell('SELECT COUNT(*) FROM formhit'), 'and not counted');
});

test('Q3', 'the café\'s contact form stores and mails the message', function () use ($base) {
	database::instance('cms');
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'name' => 'Ana Pop', 'email' => 'terrace@example.com', 'phone' => '+40 721 000 000', 'subject' => 'A table', 'message' => 'Is the terrace open on Sunday?'));
	same(303, $status);
	same("$base/visit?done=sent", header_value($headers, 'Location'));
	has(http('GET', "$base/visit?done=sent")[1], 'Thanks, we will write back soon.');
	$sent = array_slice(mails(), $before);
	same(array('staff@cafe.test'), array_column($sent, 'to'), 'staff get one email, the guest none');
	same('A message from the website', $sent[0]['subject']);
	has($sent[0]['raw'], "\r\nReply-To: \"Ana Pop\" <terrace@example.com>\r\n", 'Reply-To is the guest');
	has($sent[0]['html'], 'Is the terrace open on Sunday?');
	has($sent[0]['html'], 'href="'.$base.'/visit"', 'where it was sent from');
	has($sent[0]['html'], 'href="'.$base.'/staff"', 'and the staff page');
	same('new', R::getCell('SELECT status FROM contactmessagedata WHERE email = ?', array('terrace@example.com')));
	raster(array('user', 'staff@cafe.test', '--role=editor', '--password=staff password', '--name=Sam'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$page = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
	has(staff_section($page, 'New'), 'Is the terrace open on Sunday?', 'listed on /staff for the editor');
	lacks(http('GET', "$base/staff")[1], 'Is the terrace open', 'and not for anyone else');
	// the message's own page is for staff too
	same(303, http('GET', "$base/contactmessage/contactmessage_item/".R::getCell('SELECT id FROM contactmessagedata WHERE email = ?', array('terrace@example.com')))[0]);
});
test('Q4', 'a link-stuffed message is kept aside as spam, and staff can say it was not', function () use ($base) {
	database::instance('cms');
	$before = count(mails());
	// bbcode links: the café\'s own rule (D13) turns plain web addresses back, this is what it lets through
	$links = '[url=a.example]a[/url] [url=b.example]b[/url] [url=c.example]c[/url] cheap watches';
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'contact.form', 'name' => 'Linker', 'email' => 'linker@example.com', 'message' => $links));
	same(303, $status);
	same("$base/visit?done=sent", header_value($headers, 'Location'), 'the visitor is thanked all the same');
	same($before, count(mails()), 'nobody is emailed');
	$id = (int)R::getCell('SELECT id FROM contactmessagedata WHERE email = ?', array('linker@example.com'));
	same('spam', R::getCell('SELECT status FROM contactmessagedata WHERE id = ?', array($id)));
	has((string)R::getCell('SELECT spam FROM contactmessagedata WHERE id = ?', array($id)), 'too_many_links');
	raster(array('user', 'staff@cafe.test', '--role=editor', '--password=staff password', '--name=Sam'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$page = http('GET', "$base/staff", null, $h)[1];
	has(staff_section($page, 'Spam'), 'cheap watches', 'in the Spam list');
	lacks(staff_section($page, 'New'), 'cheap watches');
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'contactmessage', 'id' => $id, 'action' => 'not_spam', 'csrf' => token_in($page)), $h);
	same(200, $status, $body);
	same('new', json_decode($body, true)['status']);
	same($before, count(mails()), 'marking it not spam mails nobody');
	$page = http('GET', "$base/staff", null, $h)[1];
	has(staff_section($page, 'New'), 'cheap watches', 'now in the new list');
	lacks(staff_section($page, 'Spam'), 'cheap watches');
});
test('Q5', 'the staff page lists messages with the in-page editor', function () use ($base) {
	same(303, http('POST', "$base/visit", array('raster_form' => 'contact.form', 'email' => 'editable@example.com', 'message' => 'Do you have high chairs?'))[0]);
	list($code, $out) = raster(array('render', '/staff', '--as=editor'));
	same(0, $code, $out);
	// a message is a record the editor marks, with their buttons
	has($out, '"collection":"contactmessage"', 'the messages are editable in the page');
	has($out, '"actions":["done","spam","not_spam"]', 'with their actions');
});

// ## S. Event replies: the bundled rsvp model, as the café's evenings use it

// one of the evenings demo/seed.php adds, as the model reads it (read fresh)
function cafe_event($title) {
	database::instance('cms');
	$id = (int)R::getCell('SELECT id FROM eventsdata WHERE title = ? AND date >= ? ORDER BY id', array($title, date('Y-m-d')));
	check($id > 0, "the seeded $title (demo/seed.php)");
	cms_store::forget();
	rsvp::forget();
	return rsvp::item($id);
}
function event_page($base, $event) {
	return "$base/events/events_item/{$event['slug']}";
}
// posts the reply form on the event's page
function reply($base, $event, $fields, $headers = array()) {
	$fields += array('raster_form' => 'rsvp.form', 'name' => 'Guest', 'answer' => 'yes', 'people' => '1');
	return http('POST', event_page($base, $event), $fields, $headers);
}
// the guest's link in an rsvp email (the raw token)
function rsvp_token($mail) {
	return $mail && preg_match('#/rsvp\?token=([a-f0-9]{64})#', $mail['html'], $m) ? $m[1] : null;
}
function reply_row($email) {
	database::instance('cms');
	return R::getRow('SELECT * FROM rsvpdata WHERE email = ?', array($email)) ?: null;
}
// run with RASTER_TEST_ONLY=S, not alone: S2, S4 and S6 build on each other's replies
test('S1', 'the café\'s events take replies, with the places left', function () use ($base) {
	$jazz = cafe_event('Jazz night');
	same(array(40, '20:00', date('Y-m-d', strtotime('+14 days'))), array($jazz['places'], $jazz['starts'], $jazz['date']), 'demo/seed.php:');
	$page = http('GET', event_page($base, $jazz))[1];
	has($page, '40 of 40 places left');
	has($page, 'Replies close '.date('Y-m-d H:i', $jazz['deadline']).'.', 'no rsvp_until: when it starts');
	has($page, 'value="rsvp.form"', 'the form');
	$form = substr($page, strpos($page, 'value="rsvp.form"'));
	$form = substr($form, 0, strpos($form, '</form>'));
	lacks($form, '<script', 'no JavaScript to reply');
	has($form, 'name="answer" value="maybe"');
	lacks(http('GET', "$base/events")[1], 'value="rsvp.form"', 'the list takes no replies');
	$before = count(mails());
	list($status, , $headers) = reply($base, $jazz, array('name' => 'Ana', 'email' => 'ana.rsvp@example.com', 'people' => '2'));
	same(303, $status);
	same(event_page($base, $jazz).'?done=going', header_value($headers, 'Location'));
	$after = http('GET', header_value($headers, 'Location'))[1];
	has($after, "You're in.");
	has($after, '38 of 40 places left');
	same(array('ana.rsvp@example.com', 'staff@cafe.test'), array_column(array_slice(mails(), $before), 'to'), 'rsvp_going to the guest, rsvp_new to staff');
	same("You're in: Jazz night", mail_to('ana.rsvp@example.com')['subject']);
	check(rsvp_token(mail_to('ana.rsvp@example.com')) !== null, 'with the link');
	same('New reply: Jazz night', mail_to('staff@cafe.test')['subject']);
	lacks(mail_to('staff@cafe.test')['html'], '/rsvp?token=', 'staff get no link');
	same(array('going', '2'), array(reply_row('ana.rsvp@example.com')['status'], (string)reply_row('ana.rsvp@example.com')['people']));
	// a party over rsvp_max_people (6) is for a phone call
	list($status, $body) = reply($base, $jazz, array('name' => 'Big', 'email' => 'big.rsvp@example.com', 'people' => '7'));
	same(200, $status);
	has($body, 'For more than 6, call us');
	same(null, reply_row('big.rsvp@example.com'), 'nothing stored');
});
test('S2', 'a full evening has a waitlist that moves', function () use ($base) {
	$poetry = cafe_event('Poetry evening');
	same(array(4, '19:00'), array($poetry['places'], $poetry['starts']), 'demo/seed.php:');
	same(date('Y-m-d', strtotime('+9 days')).' 18:00', date('Y-m-d H:i', $poetry['deadline']), 'replies close the day before');
	same(303, reply($base, $poetry, array('name' => 'Petra', 'email' => 'petra.rsvp@example.com', 'people' => '3'))[0]);
	list($status, , $headers) = reply($base, $poetry, array('name' => 'Radu', 'email' => 'radu.rsvp@example.com', 'people' => '2'));
	same(303, $status);
	same(event_page($base, $poetry).'?done=waiting', header_value($headers, 'Location'), '3 and 2 is more than 4');
	has(http('GET', header_value($headers, 'Location'))[1], "you're on the waitlist");
	same('You are on the waitlist: Poetry evening', mail_to('radu.rsvp@example.com')['subject']);
	has(mail_to('radu.rsvp@example.com')['html'], 'number 1 on the waitlist');
	// a party that fits goes in ahead of one that doesn't
	same(303, reply($base, $poetry, array('name' => 'Ilie', 'email' => 'ilie.rsvp@example.com', 'people' => '1'))[0]);
	same(array('going', 'waiting', 'going'), array(reply_row('petra.rsvp@example.com')['status'], reply_row('radu.rsvp@example.com')['status'], reply_row('ilie.rsvp@example.com')['status']));
	has(http('GET', event_page($base, $poetry))[1], 'rsvp-state full', 'the page says it is full');
	// Petra comes with one, by her link: two places free, and Radu's two move in
	$raw = rsvp_token(mail_to('petra.rsvp@example.com'));
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'yes', 'people' => '1'));
	same(303, $status);
	same("$base/rsvp?done=changed", header_value($headers, 'Location'));
	same(array('going', '1'), array(reply_row('petra.rsvp@example.com')['status'], (string)reply_row('petra.rsvp@example.com')['people']));
	same('going', reply_row('radu.rsvp@example.com')['status'], 'promoted');
	$sent = array_slice(mails(), $before);
	same(array('petra.rsvp@example.com', 'staff@cafe.test', 'radu.rsvp@example.com'), array_column($sent, 'to'));
	same('A place is yours: Poetry evening', $sent[2]['subject']);
	check(rsvp_token($sent[2]) !== null, 'with a link of their own');
});
test(array('S3', 'O1'), 'the reply link: a GET changes nothing, a POST changes the reply; never cached, never in the sitemap or an export', function () use ($base, $db, $maildir, $tmp) {
	$jazz = cafe_event('Jazz night');
	same(303, reply($base, $jazz, array('name' => 'Lena', 'email' => 'lena.rsvp@example.com', 'people' => '2'))[0]);
	$raw = rsvp_token(mail_to('lena.rsvp@example.com'));
	check($raw !== null, 'the email has the link');
	$before = count(mails());
	list($status, $body, $headers) = http('GET', "$base/rsvp?token=$raw");
	same(200, $status);
	has($body, '<dd>Lena</dd>');
	has($body, '<dd>Jazz night, '.$jazz['date'].' at 20:00</dd>');
	same('same-origin', header_value($headers, 'Referrer-Policy'));
	http('GET', "$base/rsvp?token=$raw&do=change&answer=no");
	http('HEAD', "$base/rsvp?token=$raw");
	same('going', reply_row('lena.rsvp@example.com')['status'], 'a GET changes nothing');
	same($before, count(mails()), 'and sends nothing');
	same(403, http('POST', "$base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'no'), array('Origin: https://evil.example'))[0], 'a post from another site');
	same('going', reply_row('lena.rsvp@example.com')['status']);
	list($status, , $headers) = http('POST', "$base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'maybe'));
	same(303, $status);
	same("$base/rsvp?done=changed", header_value($headers, 'Location'), 'without the token');
	same('maybe', reply_row('lena.rsvp@example.com')['status']);
	same(array('lena.rsvp@example.com', 'staff@cafe.test'), array_column(array_slice(mails(), $before), 'to'), 'rsvp_noted to the guest, rsvp_new to staff');
	same('We have your answer: Jazz night', mail_to('lena.rsvp@example.com')['subject']);
	$done = http('GET', "$base/rsvp?done=changed")[1];
	has($done, 'Done: we have emailed you your reply.');
	lacks($done, 'This link no longer works');
	has(http('GET', "$base/rsvp?token=$raw")[1], '<dd class="status">maybe</dd>', 'a maybe reply\'s link still works');
	has(http('GET', "$base/rsvp?token=nope")[1], 'This link no longer works');
	// never cached, never listed
	$cached = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_PAGE_CACHE' => 'on'));
	foreach (array(1, 2) as $n) check(header_value(http('GET', "$cached/rsvp?token=$raw")[2], 'X-Raster-Cache') !== 'hit', 'never a cache hit');
	foreach (array(1, 2) as $n) check(header_value(http('GET', event_page($cached, $jazz))[2], 'X-Raster-Cache') !== 'hit', 'nor the places left');
	lacks(http('GET', "$base/sitemap.xml")[1], '/rsvp<', 'not in the sitemap');
	$out = "$tmp/static-rsvp";
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
	check(!file_exists("$out/rsvp/index.html") && !file_exists("$out/staff/events/index.html"), 'nor in the export');
	$exported = file_get_contents("$out/events/events_item/{$jazz['slug']}/index.html");
	has($exported, 'Call us to reply');
	lacks($exported, 'places left', 'a static page can\'t count');
});
// run with RASTER_TEST_ONLY=S, not alone
test('S4', 'the deadline: places given back, none taken', function () use ($base) {
	$poetry = cafe_event('Poetry evening');
	// full (S2): a yes of one waits
	same(303, reply($base, $poetry, array('name' => 'Sorin', 'email' => 'sorin.rsvp@example.com'))[0]);
	same('waiting', reply_row('sorin.rsvp@example.com')['status']);
	$until = $poetry['rsvp_until'];
	mcp($base, 'update_item', array('collection' => 'events', 'id' => $poetry['id'], 'fields' => array('rsvp_until' => date('Y-m-d H:i', time() - 3600))));
	try {
		$page = http('GET', event_page($base, $poetry))[1];
		has($page, 'rsvp-state closed');
		has($page, 'Replies are closed', 'the form says so first');
		list($status, $body) = reply($base, $poetry, array('name' => 'Tudor', 'email' => 'tudor.rsvp@example.com'));
		same(200, $status);
		has($body, 'Replies are closed');
		same(null, reply_row('tudor.rsvp@example.com'), 'a new yes is refused');
		// Petra can't come after all: her place is given back, and nobody moves in
		$before = count(mails());
		$raw = rsvp_token(mail_to('petra.rsvp@example.com'));
		list($status) = http('POST', "$base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'no'));
		same(303, $status);
		same('no', reply_row('petra.rsvp@example.com')['status']);
		same('waiting', reply_row('sorin.rsvp@example.com')['status'], 'freed places are staff\'s to give from the deadline');
		same(array('petra.rsvp@example.com', 'staff@cafe.test'), array_column(array_slice(mails(), $before), 'to'));
		// and asking for more is refused
		$raw = rsvp_token(mail_to('ilie.rsvp@example.com'));
		has(http('POST', "$base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'people' => '2'))[1], 'Replies are closed: you can still tell us you can');
		same('1', (string)reply_row('ilie.rsvp@example.com')['people']);
	} finally {
		mcp($base, 'update_item', array('collection' => 'events', 'id' => $poetry['id'], 'fields' => array('rsvp_until' => $until)));
	}
	same('going', reply_row('sorin.rsvp@example.com')['status'], 'the deadline moved back: saving the event moves the waitlist in');
	same('A place is yours: Poetry evening', mail_to('sorin.rsvp@example.com')['subject']);
});
test('S5', 'replying twice emails the link to the address that replied', function () use ($base, $spam_base) {
	$jazz = cafe_event('Jazz night');
	same(303, reply($base, $jazz, array('name' => 'Mara', 'email' => 'Mara.Rsvp@Example.com'))[0]);
	$before = count(mails());
	list($status, $body) = reply($base, $jazz, array('name' => 'Not Mara', 'email' => 'mara.rsvp@example.com', 'people' => '4'));
	same(200, $status);
	has($body, "You've replied already: we've emailed you a link to change your reply.");
	database::instance('cms');
	same(1, (int)R::getCell('SELECT COUNT(*) FROM rsvpdata WHERE LOWER(email) = ?', array('mara.rsvp@example.com')), 'nothing stored');
	$sent = array_slice(mails(), $before);
	same(array('Mara.Rsvp@Example.com'), array_column($sent, 'to'), 'to the reply\'s own address');
	same('Your reply: Jazz night', $sent[0]['subject']);
	$raw = rsvp_token($sent[0]);
	has(http('GET', "$base/rsvp?token=$raw")[1], '<dd>Mara</dd>', 'the link opens Mara\'s reply');
	has(reply($base, $jazz, array('name' => 'Not Mara', 'email' => 'mara.rsvp@example.com'))[1], "You've replied already");
	same($before + 1, count(mails()), 'once every five minutes');
	// without a stamp, nothing is looked up or mailed
	list($status, $body) = reply($spam_base, $jazz, array('name' => 'Bot', 'email' => 'mara.rsvp@example.com', 'raster_ts' => ''));
	same(200, $status);
	has($body, 'That went by a bit quickly');
	lacks($body, "You've replied already");
	same($before + 1, count(mails()));
});
// run with RASTER_TEST_ONLY=S, not alone
test('S6', 'the staff page: counts, waitlist, attention, a phone reply', function () use ($base) {
	same(303, http('GET', "$base/staff/events")[0], 'visitors get the login page');
	raster(array('user', 'staff@cafe.test', '--role=editor', '--password=staff password'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$jazz = cafe_event('Jazz night');
	$poetry = cafe_event('Poetry evening');
	database::instance('cms');
	$waiting = (int)R::getCell("SELECT COUNT(*) FROM rsvpdata WHERE status = 'waiting'");
	$page = http('GET', "$base/staff/events", null, $h)[1];
	has($page, "<p>$waiting replies waiting (past events too), 0 that need attention.</p>", 'the counts for the heading');
	has($page, '<h3>Jazz night, '.$jazz['date'].' at 20:00</h3>');
	$going = (int)R::getCell("SELECT SUM(people) FROM rsvpdata WHERE event = ? AND status = 'going'", array($jazz['id']));
	has($page, "$going going, ".(40 - $going).' of 40 places left', 'the jazz night\'s counts');
	has($page, '<h3>Poetry evening, '.$poetry['date'].' at 19:00</h3>');
	has($page, '<option value="">All events</option>');
	foreach (array($jazz, $poetry) as $event) has($page, '<option value="'.$event['id'].'">'.$event['title'].', '.$event['date'].'</option>', 'the select lists the evenings');
	$picked = http('GET', "$base/staff/events?event={$poetry['id']}", null, $h)[1];
	$select = substr($picked, strpos($picked, '<select name="event">'));
	$select = substr($select, 0, strpos($select, '</select>'));
	has($select, '<option value="'.$poetry['id'].'" selected>', 'the chosen event stays chosen');
	same(1, substr_count($select, 'selected'), $select);
	list($code, $out) = raster(array('render', "/staff/events?event={$poetry['id']}", '--as=editor'));
	same(0, $code, $out);
	has($out, "rsvp('event=?event&order=status,waiting_since') + new", 'the replies are editable, with a card for a phone reply');
	has($out, '"actions":["promote"]');
	// a phone reply from the card: full, so it waits, and promote can't make room
	$token = token_in($page);
	$before = count(mails());
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'rsvp', 'id' => 0, 'fields' => array('name' => 'Phoned in', 'event' => (string)$poetry['id'], 'answer' => 'yes', 'people' => '1'), 'csrf' => $token), $h);
	same(200, $status, $body);
	$phoned = json_decode($body, true);
	same('waiting', $phoned['status'], 'staff never go over places');
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'rsvp', 'id' => $phoned['id'], 'action' => 'promote', 'csrf' => $token), $h);
	same(422, $status, $body);
	same(array('not_enough_places'), json_decode($body, true)['problems']);
	has(file_get_contents(dirname(__DIR__).'/demo/i18n/en/raster_editor.php'), "'problem_not_enough_places'", 'in the café\'s words');
	$until = $poetry['rsvp_until'];
	$save = function ($fields) use ($base, $h, $token, $poetry) {
		list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'events', 'id' => $poetry['id'], 'fields' => $fields, 'csrf' => $token), $h);
		same(200, $status, $body);
	};
	try {
		// past the deadline a place raised in the page moves nobody in: staff give it
		$save(array('rsvp_until' => date('Y-m-d H:i', time() - 3600)));
		$save(array('places' => '5'));
		same('waiting', reply_row_by_id($phoned['id'])['status']);
		list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'rsvp', 'id' => $phoned['id'], 'action' => 'promote', 'csrf' => $token), $h);
		same(200, $status, $body);
		same('going', json_decode($body, true)['status'], 'promoted out of turn');
		// places lowered under the people going: nobody moved, nobody emailed, all flagged
		$save(array('places' => '4'));
		same('going', reply_row_by_id($phoned['id'])['status']);
		same($before, count(mails()), 'a phone reply with no email, and an edit of the event, mail nobody');
		$page = http('GET', "$base/staff/events", null, $h)[1];
		$attention = staff_section($page, 'Needs attention');
		same((int)R::getCell("SELECT COUNT(*) FROM rsvpdata WHERE event = ? AND status = 'going'", array($poetry['id'])), substr_count($attention, 'over_places'), 'every going reply of it: '.$attention);
		check((bool)preg_match('#<p>\d+ replies waiting \(past events too\), [1-9]\d* that need attention\.</p>#', $page), 'counted for the heading');
	} finally {
		mcp($base, 'delete_item', array('collection' => 'rsvp', 'id' => $phoned['id']));
		$save(array('places' => '4', 'rsvp_until' => $until));
	}
});
function reply_row_by_id($id) {
	database::instance('cms');
	return R::getRow('SELECT * FROM rsvpdata WHERE id = ?', array($id));
}
test('S7', 'a member sees their replies on /account and changes one without a token', function () use ($base) {
	raster(array('user', 'rsvp.member@example.com', '--role=member', '--password=member password'));
	$member = login($base, 'rsvp.member@example.com', 'member password');
	$h = array("Cookie: $member");
	$jazz = cafe_event('Jazz night');
	$token = token_in(http('GET', event_page($base, $jazz), null, $h)[1]);
	same(303, reply($base, $jazz, array('name' => 'Member', 'email' => 'rsvp.member@example.com', 'people' => '2', 'csrf' => $token), $h)[0]);
	$id = (int)reply_row('rsvp.member@example.com')['id'];
	$account = http('GET', "$base/account", null, $h)[1];
	has($account, '<form method="get" action="'.$base.'/rsvp" class="inline"><button class="secondary" name="reply" value="'.$id.'">Change my reply</button></form>');
	$page = http('GET', "$base/rsvp?reply=$id", null, $h)[1];
	has($page, '<dd>Member</dd>');
	lacks(http('GET', "$base/rsvp?reply=$id")[1], '<dd>Member</dd>', 'not for visitors');
	raster(array('user', 'staff@cafe.test', '--role=editor', '--password=staff password'));
	has(http('GET', "$base/rsvp?reply=$id", null, array('Cookie: '.login($base, 'staff@cafe.test', 'staff password')))[1], 'This link no longer works', 'nor for another account: it is the owner\'s');
	list($status, , $headers) = http('POST', "$base/rsvp?reply=$id", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'no', 'csrf' => token_in($page)), $h);
	same(303, $status);
	same("$base/rsvp?reply=$id&done=changed", header_value($headers, 'Location'));
	same('no', reply_row('rsvp.member@example.com')['status']);
	has(http('GET', "$base/account", null, $h)[1], 'no, 2 people: no');
});

// ## P1.C: head tags, html lang and dir, the switcher (#117 #138)

test(array('J1', 'J2', 'J3'), 'the café\'s Romanian pages say lang="ro" (#138)', function () use ($base) {
	has(http('GET', "$base/about")[1], "<!doctype html>\n<html lang=\"en\" dir=\"ltr\">");
	has(http('GET', "$base/about?lang=ro")[1], '<html lang="ro" dir="ltr">');
	has(http('GET', "$base/menu", null, array('Cookie: cafe_lang=ro'))[1], '<html lang="ro" dir="ltr">');
	lacks(http('GET', "$base/menu?lang=ro")[1], 'hreflang', 'template-only languages: no language links in <head>');
});

// ## P1.D: export through the live prefixes (#137)

// a stand-in for the live site with /<lang>/ addresses (P1.B), so the crawl
// is tested on its own: the export's server is replaced by canned pages
function p1d_fake_export($root, $out, $multilingual) {
	global $tmp;
	$script = "$tmp/p1d-export.php";
	file_put_contents($script, '<?php require '.var_export("$root/system/boot.php", true).'; boot::$appname = "demo"; boot::cli();'
		.' require_once BASE."tools/inspector.php"; require_once BASE."tools/export.php";'
		.' config::set("multilingual")->to('.var_export($multilingual, true).');'
		.' class p1d_export extends raster_export {'
		.'  public $asked = array();'
		.'  protected function start_server() { $this->base = "http://127.0.0.1:9/"; }'
		.'  protected function stop_server() {}'
		.'  protected function fetch($path, $headers = array()) {'
		.'   $this->asked[] = $path.($headers ? " ".implode(" ", $headers) : "");'
		.'   $site = array("/" => array("menu", "ro/"), "/menu" => array("", "menu/menu_item/coffee", "ro/menu"), "/menu/menu_item/coffee" => array("menu", "ro/menu/menu_item/cafea"),'
		.'    "/ro/" => array("ro/", "ro/menu", ""), "/ro/menu" => array("ro/", "ro/menu/menu_item/cafea", "menu", "about?lang=en", "login"), "/ro/menu/menu_item/cafea" => array("ro/menu", "ro/login"));'
		.'   if ($path === "/ro") return array(301, "", "text/html");'
		.'   if (!isset($site[$path])) return array(404, "<!doctype html><p>".(strpos($path, "/ro/") === 0 ? "Negasit" : "Not found")."</p>", "text/html");'
		.'   if (!i18n::enabled()) $site[$path] = preg_grep("#^ro/#", $site[$path], PREG_GREP_INVERT);'
		.'   $html = "<!doctype html><html><body>"; foreach ($site[$path] as $link) $html .= "<a href=\"".$this->base.$link."\">x</a>";'
		.'   return array(200, $html."</body></html>", "text/html; charset=utf-8");'
		.'  }'
		.' }'
		.' @mkdir('.var_export($out, true).', 0775, true);'
		.' $e = (new p1d_export('.var_export($root, true).', '.var_export($out, true).', "https://cafe.example/"))->run();'
		.' echo json_encode(array("errors" => $e->errors, "asked" => $e->asked));');
	list($code, $output) = raster_php($script);
	same(0, $code, $output);
	$result = json_decode($output, true);
	check(is_array($result), $output);
	return $result;
}
test('N9', 'P1.D export: a multilingual site is crawled from each language\'s own address', function () use ($root, $tmp) {
	$out = "$tmp/p1d-multilingual";
	$result = p1d_fake_export($root, $out, true);
	same(array(), $result['errors']);
	foreach (array('index.html', 'menu/index.html', 'menu/menu_item/coffee/index.html', 'ro/index.html', 'ro/menu/index.html', 'ro/menu/menu_item/cafea/index.html', '404.html', 'ro/404.html') as $file) {
		check(is_file("$out/$file"), "missing $file");
	}
	check(!file_exists("$out/ro/menu/menu_item/coffee/index.html"), 'the default paths are not fetched again in ro');
	check(in_array('/ro/', $result['asked']), 'the language\'s home is /ro/ (D5)');
	check(!in_array('/ro', $result['asked']), 'not /ro, which redirects');
	check(!in_array('/ro/login', $result['asked']), '/ro/login is left out like /login');
	same(array(), preg_grep('/Cookie:/', $result['asked']), 'no language cookie');
	$ro = file_get_contents("$out/ro/menu/index.html");
	has($ro, 'href="https://cafe.example/ro/menu/menu_item/cafea"');
	has($ro, 'href="https://cafe.example/menu"', 'the link to English is left as the page has it');
	has($ro, 'href="https://cafe.example/about?lang=en"', 'no ?lang= rewriting');
	has(file_get_contents("$out/ro/404.html"), 'Negasit');
	lacks(file_get_contents("$out/404.html"), 'Negasit');
});
test('N9', 'P1.D export: multilingual off still fetches the pages again with the language cookie', function () use ($root, $tmp) {
	$out = "$tmp/p1d-off";
	$result = p1d_fake_export($root, $out, false);
	same(array(), $result['errors']);
	check(in_array('/menu Cookie: cafe_lang=ro', $result['asked']), implode(', ', $result['asked']));
	same(array(), preg_grep('#^/ro(/|$)#', $result['asked']), 'no /ro/ addresses are crawled');
	check(is_file("$out/ro/menu/index.html") && is_file("$out/ro/menu/menu_item/coffee/index.html"), 'each page again in /ro/');
	check(!file_exists("$out/ro/404.html"), 'one 404 page');
	// the page in ro links to ro pages
	has(file_get_contents("$out/ro/menu/index.html"), 'href="https://cafe.example/ro/menu/menu_item/coffee"');
});
test('N9', 'P1.D export: the demo in multilingual mode stays in /ro/ (needs the live prefixes)', function () use ($root, $tmp) {
	$config = "$root/demo/config/the_app.php";
	$out = "$tmp/p1d-demo";
	with_file($config, file_get_contents($config)."\nconfig::set('multilingual')->to(true);\n", function () use ($out) {
		list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
		same(0, $code, $output);
		foreach (array('index.html', 'menu/index.html', 'ro/index.html', 'ro/menu/index.html', 'ro/about/index.html', '404.html') as $file) check(is_file("$out/$file"), "missing $file");
		foreach (array('ro/index.html', 'ro/menu/index.html', 'ro/about/index.html') as $file) {
			$html = file_get_contents("$out/$file");
			lacks($html, '?lang=', "$file: no ?lang= links");
			// every link in the page but the language switcher stays in /ro/
			preg_match_all('#<a\b(?![^>]*class="lang)[^>]*href="https://cafe\.example/([^"]*)"#', $html, $links);
			check($links[1], "$file has links");
			foreach ($links[1] as $link) check(strpos($link, 'ro/') === 0 || preg_match('#^(demo|media)/|\.(rss|atom|xml|json|txt)$#', $link), "$file links to /$link");
		}
		has(file_get_contents("$out/ro/menu/index.html"), 'href="https://cafe.example/ro/about"');
		lacks(file_get_contents("$out/index.html"), 'href="https://cafe.example/ro/menu/menu_item/', 'the English pages link in English');
	});
});
test('N9', 'P1.D export: the site\'s address comes from site_url when --url and RASTER_URL are not set (#137)', function () use ($tmp) {
	$out = "$tmp/p1d-site-url";
	list($code, $output) = raster(array('export', $out), array('RASTER_URL' => '', 'CAFE_SITE_URL' => 'https://cafe.example/shop/'));
	same(0, $code, $output);
	has($output, 'linked to https://cafe.example/shop/');
	has(file_get_contents("$out/index.html"), 'href="https://cafe.example/shop/menu"');
	list($code, $output) = raster(array('export', $out, '--url=https://other.example/'), array('RASTER_URL' => '', 'CAFE_SITE_URL' => 'https://cafe.example/shop/'));
	has($output, 'linked to https://other.example/', '--url wins');
});

// ## No PHP warnings, notices or deprecations on any request

$log = is_file("$tmp/php-errors.log") ? file_get_contents("$tmp/php-errors.log") : '';
if (preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $log, $problems)) {
	$failed[] = 'PHP reported problems while serving pages:'."\n    ".implode("\n    ", array_unique($problems[0]));
}

// ## Coverage: every feature in demo/README.md has a passing test

$readme = file_get_contents("$root/demo/README.md");
preg_match_all('/^\| ([A-Z]\d+) \|/m', $readme, $ids);
// a filtered run covers only some features, so the gate is for full runs
$only = getenv('RASTER_TEST_ONLY');
$missing = array_diff($ids[1], array_keys($covered));
$unknown = array_diff(array_keys($covered), $ids[1]);
if ($unknown) $failed[] = 'tests name features that demo/README.md does not list: '.implode(', ', $unknown);
echo "\n\n$passed passed, ".count($failed)." failed".($skipped ? ', '.count($skipped).' skipped' : '')."; ".(count($ids[1]) - count($missing))."/".count($ids[1])." features covered\n";
foreach ($skipped as $skip) echo "  - $skip\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
if ($missing && !$only) echo "  Not covered: ".implode(', ', $missing)."\n";
exit($failed || ($missing && !$only) ? 1 : 0);
