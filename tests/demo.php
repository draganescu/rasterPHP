<?php
// The demo café test suite: php tests/demo.php
//
// Drives the demo app (demo/) over HTTP, the command line, MCP and a fake
// SMTP server. Every feature has an ID in demo/README.md; the run fails
// when an ID there has no passing test here.

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/raster-demo-'.getmypid();
@mkdir($tmp, 0775, true);
$db = "$tmp/cafe.sqlite";
$maildir = "$tmp/mail";
putenv('RASTER_APP=demo');
putenv("RASTER_DB=$db");
putenv("RASTER_MAIL=log://$maildir");
putenv('RASTER_ENV');

require_once $root.'/system/boot.php';
boot::$appname = 'demo';
boot::cli();
require_once BASE.'tools/inspector.php';
require_once BASE.'tools/schema.php';

// ## Harness

$passed = 0; $failed = array(); $covered = array();
function test($ids, $name, $fn) {
	global $passed, $failed, $covered;
	try {
		$fn();
		$passed++;
		foreach ((array)$ids as $id) $covered[$id] = true;
		echo ".";
	} catch (Throwable $e) {
		$failed[] = implode(',', (array)$ids)." $name: ".$e->getMessage().' (line '.$e->getLine().')';
		echo "F";
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
// a port nobody is listening on
function free_port() {
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	return (int)substr($name, strrpos($name, ':') + 1);
}
function server($port, $env) {
	global $root, $servers;
	$env = array_merge(array('PATH' => getenv('PATH'), 'RASTER_APP' => 'demo'), $env);
	global $tmp;
	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', "error_log=$tmp/php-errors.log", '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	$servers[] = $process;
	return "http://127.0.0.1:$port";
}
register_shutdown_function(function () use ($tmp, $root) {
	global $servers;
	foreach ($servers as $process) proc_terminate($process);
	exec('rm -rf '.escapeshellarg($tmp).' '.escapeshellarg("$root/demo/data/cache").' '.escapeshellarg("$root/demo/data/mail").' '.escapeshellarg("$root/demo/data/changes.log"));
	foreach (glob("$root/media/*") ?: array() as $file) if (basename($file) !== '.gitignore' && filemtime($file) > time() - 3600) @unlink($file);
});

function http($method, $url, $body = null, $headers = array()) {
	if (is_array($body)) {
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
	list($status, , $headers) = http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => $login, 'password' => $password));
	same(303, $status, "login $login");
	return cookie_from($headers);
}
function raster($args, $env = array()) {
	global $root;
	$env = array_merge(getenv(), $env);
	$process = proc_open(array_merge(array(PHP_BINARY, "$root/bin/raster"), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, $env);
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
		preg_match('/^Subject: =\?UTF-8\?B\?([^?]+)\?=/m', $raw, $s);
		preg_match('/^To: (.*)$/m', $raw, $t);
		$mails[] = array('raw' => $raw, 'to' => trim($t[1]), 'subject' => isset($s[1]) ? base64_decode($s[1]) : '', 'text' => $part('text/plain'), 'html' => $part('text/html'));
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
	$process = proc_open(array(PHP_BINARY, "$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, getenv());
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
	exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $status);
	unlink($file);
	return $status === 0;
}
function with_file($path, $content, $fn) {
	$existed = file_exists($path);
	$original = $existed ? file_get_contents($path) : null;
	file_put_contents($path, $content);
	try { return $fn(); } finally { if ($existed) file_put_contents($path, $original); else unlink($path); }
}

$port = free_port();
$base = server($port, array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token'));
$views = "$root/demo/views/cafe";

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
	has(between(http('GET', "$base/lab/color/red")[1], 'param'), '<p class="color">red</p>');
});
test(array('A7', 'A8'), 'unknown pages and partials are 404', function () use ($base) {
	same(404, http('GET', "$base/nothing-here")[0]);
	same(404, http('GET', "$base/_layout")[0]);
	same(404, http('GET', "$base/_email/reservation")[0]);
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
	same(404, http('GET', "$base/api/reservation/schema")[0], 'static methods are private');
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
	foreach (array('reservation.book', 'reservation.contact', 'newsletter.signup') as $owner) has($body, 'name="raster_form" value="'.$owner.'"');
	same(3, substr_count($body, 'name="raster_hp"'));
	lacks($body, 'name="csrf"', 'no session, no token');
	lacks($body, 'class="error"');
	lacks($body, 'Thank you, your table is booked');
});
test('D3', 'posts from other sites are refused', function () use ($base) {
	same(403, http('POST', "$base/visit", array('raster_form' => 'reservation.contact'), array('Origin: https://evil.example'))[0]);
	same(403, http('POST', "$base/visit", array('raster_form' => 'reservation.contact'), array('Sec-Fetch-Site: cross-site'))[0]);
	same(403, http('POST', "$base/visit", array('raster_form' => 'reservation.contact'), array('Referer: https://evil.example/page'))[0]);
	same(403, http('POST', "$base/api/cafe/hours", array('x' => 1), array('Origin: https://evil.example'))[0]);
	$host = parse_url($base, PHP_URL_HOST).':'.parse_url($base, PHP_URL_PORT);
	same(200, http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => ''), array("Origin: http://$host", 'Sec-Fetch-Site: same-origin'))[0], 'browsers posting from the site are welcome');
});
test('D4', 'bots that fill the honeypot get a fake success', function () use ($base) {
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'raster_hp' => 'buy now', 'email' => 'bot@spam.test', 'message' => 'spam'));
	same(303, $status);
	same($before, count(mails()), 'nothing sent');
});
$bad_booking = array('raster_form' => 'reservation.book', 'name' => 'Your name', 'email' => 'nope', 'phone' => 'abc', 'date' => '2031-01-01', 'guests' => '12', 'seating' => 'terrace', 'occasion' => 'birthday', 'newsletter' => 'yes', 'notes' => str_repeat('x', 301), 'password' => 'secret');
test(array('D5', 'D6', 'D7', 'D8', 'D9', 'D10', 'D11', 'D12', 'D24', 'D25'), 'rules from the HTML, and regions', function () use ($base, $bad_booking) {
	list($status, $body) = http('POST', "$base/visit", $bad_booking);
	same(200, $status);
	has($body, 'Please replace the example name with yours.');
	has($body, 'We need a valid email to confirm.');
	has($body, 'Digits and spaces only');
	has($body, 'Pick a date between 2026 and 2030.');
	has($body, 'Tables are for 1 to 8 guests.');
	has($body, 'Keep notes under 300 characters.');
	has($body, 'Please agree to the house rules.');
	lacks($body, 'Tell us your name', 'name is valid');
	has($body, 'For groups over 8, please call us.', "field('guests', 'max')");
	has($body, '<p class="error-count">7 problems</p>', 'validation::errors()');
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Ana', 'email' => 'a@b.co', 'date' => '2026-10-07', 'guests' => '0', 'terms' => '1'));
	lacks($body, 'For groups over 8', "field('guests', 'max') only for max");
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'A', 'email' => '', 'date' => ''));
	has($body, 'Tell us your name (2 to 80 letters).', 'minlength');
	has($body, 'We need a valid email to confirm.', 'required');
	foreach (array(array('guests' => '0'), array('guests' => '5 people'), array('date' => '2025-12-31'), array('date' => '31/12/2026')) as $bad) {
		list(, $body) = http('POST', "$base/visit", array_merge(array('raster_form' => 'reservation.book', 'name' => 'Ana', 'email' => 'a@b.co', 'date' => '2026-10-07', 'guests' => '2', 'terms' => '1'), $bad));
		has($body, isset($bad['guests']) ? 'Tables are for 1 to 8 guests.' : 'Pick a date between 2026 and 2030.', json_encode($bad));
	}
});
test(array('D13', 'C19'), 'an application rule (its region runs before the form model)', function () use ($base) {
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Ana', 'email' => 'ana@example.com', 'date' => '2026-10-05', 'guests' => '2', 'terms' => '1'));
	has($body, 'We are closed on Mondays.');
});
test(array('D14', 'D19', 'D23'), 'rule names from older Raster, two forms on one page', function () use ($base) {
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => '', 'message' => 'Hi'));
	has($body, 'Your email, please.');
	lacks($body, 'That email does not look right.', 'email_format passes on empty');
	lacks($body, 'We need a valid email to confirm.', 'the other form stays quiet');
	lacks($body, 'Please enter a valid email address.', 'the footer form stays quiet');
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => 'bad', 'message' => 'Hi', 'website' => 'not a url'));
	has($body, 'That email does not look right.');
	has($body, 'A web address starts with https://', 'type="url"');
});
test('D16', 'the form is filled again, never with passwords', function () use ($base, $bad_booking) {
	list(, $body) = http('POST', "$base/visit", $bad_booking);
	has($body, 'value="nope"');
	has($body, '<option value="terrace" selected>');
	has($body, 'value="birthday" checked');
	check(!preg_match('/value="none"[^>]*checked/', $body), 'old radio still checked');
	has($body, 'name="newsletter" value="yes" checked');
	has($body, 'name="guests" required min="1" max="8" value="12"');
	has($body, '<textarea name="notes" maxlength="300">'.str_repeat('x', 301).'</textarea>');
	lacks($body, 'secret');
});
test(array('D17', 'I1', 'I2', 'C23'), 'a valid booking: stored, emailed, redirected, alert shown', function () use ($base) {
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Ana <b>Pop</b>', 'email' => 'ana@example.com', 'phone' => '+40 721 000 000', 'date' => '2026-10-07', 'guests' => '3', 'seating' => 'window', 'notes' => 'Near the <script>window</script>', 'terms' => '1'));
	same(303, $status);
	same("$base/visit?done=booked", header_value($headers, 'Location'));
	has(http('GET', "$base/visit?done=booked")[1], 'Thank you, your table is booked.');
	$mail = last_mail();
	same('staff@cafe.test', $mail['to']);
	same('New reservation', $mail['subject']);
	has($mail['html'], 'Ana &lt;b&gt;Pop&lt;/b&gt;', 'values in emails are escaped');
	has($mail['html'], 'Near the &lt;script&gt;');
	has($mail['text'], 'booked a table for 3 on 2026-10-07');
	database::instance('cms');
	same(1, (int)R::count('reservationdata'));
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
	list($status, $body) = http('POST', "$base/api/reservation/contact", array('email' => 'x@example.com', 'message' => 'via api'));
	same(404, $status, 'a form handler the model does not list');
	// and one a model does list is not a form submission there
	$dir = dirname(__DIR__).'/demo/models/zzform';
	@mkdir($dir);
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
	has($menu, 'href="'.$base.'/menu/menu_items/category/cakes/"');
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
	$front = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$tmp/front/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, "$tmp/front", array('RASTER_DB' => $db, 'PATH' => getenv('PATH')));
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
		proc_terminate($front);
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
	same(403, http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => 'a@b.co', 'message' => 'x'), array("Cookie: $cookie"))[0], 'no token, no post');
	same(303, http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => 'a@b.co', 'message' => 'x', 'csrf' => $token), array("Cookie: $cookie"))[0]);
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
	has($body, 'Ana &lt;b&gt;Pop&lt;/b&gt;', 'staff see reservations, escaped');
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
	same(303, http('POST', "$base/account", array('raster_form' => 'authentication.logout', 'csrf' => token_in($page)), array("Cookie: $a"))[0]);
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
	same('ok $2y$', shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1'));
	$legacy = sys_get_temp_dir().'/raster-legacy-'.getmypid().'.sqlite';
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); database::instance();'
		.' $o = R::dispense("usersdata"); $o->username = "admin"; $o->password = md5("admin pass"); R::store($o);'
		.' authentication::connect(); echo authentication::check_login("admin", "admin pass") ? "migrated" : "no";';
	same('migrated', shell_exec('RASTER_DB='.escapeshellarg($legacy).' '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1'));
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
	has($page, "<!--raster:a $id--><img class=\"photo wide\" src=\"img/about.jpg\"");
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
	has($menu, '<!--raster:ma photo src--><img class="photo" src="img/menu/flat-white.jpg" alt="">');
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
test(array('E30'), 'the editor lists the admin pages protected lets this person open', function () use ($base, $db, $maildir, $views) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$config = editor_config(http('GET', "$base/about", null, array("Cookie: $staff"))[1]);
	same(array(array('url' => "$base/staff", 'title' => 'Staff', 'current' => false)), $config['admin']);
	$config = editor_config(http('GET', "$base/staff", null, array("Cookie: $staff"))[1]);
	same(true, $config['admin'][0]['current']);
	$titles = function ($base, $cookie) {
		return array_column(editor_config(http('GET', "$base/about", null, array("Cookie: $cookie"))[1])['admin'], 'title');
	};
	// a view under a protected prefix is listed by its <title>, mock-up text included
	@mkdir("$views/staff");
	try {
		with_file("$views/staff/rota.html", '<!doctype html><html><head><title><!-- print.cms.rota_title -->Rota<!-- /print.cms.rota_title --> &amp; shifts</title></head><body><p>Rota</p></body></html>', function () use ($base, $db, $maildir, $staff, $titles) {
			same(array('Rota & shifts', 'Staff'), $titles($base, $staff));
			// a stricter pattern keeps it to admins
			raster(array('user', 'boss@cafe.test', '--role=admin', '--password=boss password'));
			$strict = server(free_port(), array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'CAFE_ADMIN_PAGE' => 'staff/rota'));
			same(array('Staff'), $titles($strict, login($strict, 'staff@cafe.test', 'staff password')), 'editors do not see admin pages');
			same(array('Rota & shifts', 'Staff'), $titles($strict, login($strict, 'boss@cafe.test', 'boss password')));
			list($code, $out) = raster(array('describe', '--json', '--sections=admin_pages'), array('RASTER_DB' => $db, 'CAFE_ADMIN_PAGE' => 'staff/rota'));
			same(array('admin_pages' => array(
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
	list(, $history) = $save('editor_history', array('type' => 'aboutpage'));
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
	same(40, $upload['width']);
	check(preg_match('#/media/\d{8}-[a-f0-9]{12}\.png$#', $upload['url']), 'a new file name');
	check(is_file($root.'/media/'.basename($upload['url'])));
	list($status, $response) = http('POST', "$base/api/cms/editor_upload", $multipart('shell.png', '<?php echo 1;'), $type);
	same(400, $status);
	has($response, 'Only JPEG, PNG, GIF and WebP');
	// a page field in an attribute: <!-- print.@src.cms.photo -->
	http('POST', "$base/api/cms/editor_save_field", array('csrf' => $token, 'type' => 'aboutpage', 'slug' => '/about', 'field' => 'photo', 'value' => $upload['url']), $h);
	has(http('GET', "$base/about")[1], '<img class="photo wide" src="'.$upload['url'].'" alt="The café from across the street">');
	same($upload['url'], mcp($base, 'get_page', array('page' => '/about'))['fields']['photo'], 'MCP knows the field');
	http('POST', "$base/api/cms/editor_save_field", array('csrf' => $token, 'type' => 'aboutpage', 'slug' => '/about', 'field' => 'photo', 'value' => ''), $h);
	has(http('GET', "$base/about")[1], '<img class="photo wide" src="img/about.jpg"', 'empty shows the template\'s picture');
	// an item photo
	$item = null;
	foreach (mcp($base, 'list_items', array('collection' => 'menu', 'limit' => 100))['items'] as $row) if ($row['name'] === 'Americano') $item = $row;
	http('POST', "$base/api/cms/editor_save_item", array('csrf' => $token, 'collection' => 'menu', 'id' => $item['id'], 'fields' => array('photo' => $upload['url'])), $h);
	has(http('GET', "$base/menu/menu_item/americano")[1], '<img class="photo wide" src="'.$upload['url'].'"');
	http('POST', "$base/api/cms/editor_save_item", array('csrf' => $token, 'collection' => 'menu', 'id' => $item['id'], 'fields' => array('photo' => '')), $h);
	has(http('GET', "$base/menu/menu_item/americano")[1], '<img class="photo wide" src="img/menu/flat-white.jpg"', 'empty keeps the mock-up');
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
	// a certificate for 127.0.0.2, trusted through openssl.cafile
	$cert = "$tmp/smtp-cert.pem";
	exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=127.0.0.2 -addext subjectAltName=IP:127.0.0.2 -keyout '.escapeshellarg($cert).' -out '.escapeshellarg("$cert.crt").' 2>/dev/null', $o, $code);
	same(0, $code, 'openssl');
	file_put_contents($cert, file_get_contents("$cert.crt"), FILE_APPEND);
	$smtp = function ($mode, $host) use ($root, $tmp, $cert) {
		$port = free_port();
		$transcript = "$tmp/smtp-$mode-$host.log";
		$process = proc_open(array(PHP_BINARY, "$root/tests/fake_smtp.php", (string)$port, $transcript, $mode, $cert, $host), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		for ($i = 0; $i < 100 && !@fsockopen($host, $port); $i++) usleep(50000);
		$GLOBALS['servers'][] = $process;
		return array($port, $transcript);
	};
	$send = function ($dsn, $from = true) use ($root, $cert) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli();'
			.' echo mail::send_view("_email/contact", "owner@cafe.test", array("email" => "a@b.co", "message" => "Hi")) ? "sent" : "failed: ".mail::$last_error;';
		$env = 'RASTER_MAIL='.escapeshellarg($dsn).($from ? ' RASTER_MAIL_FROM='.escapeshellarg('Café <hello@cafe.test>') : ' RASTER_MAIL_FROM=');
		return shell_exec($env.' '.escapeshellarg(PHP_BINARY).' -d openssl.cafile='.escapeshellarg("$cert.crt").' -r '.escapeshellarg($code).' 2>&1');
	};
	// plain text is fine on this machine
	list($port, $log) = $smtp('plain', '127.0.0.1');
	same('sent', $send("smtp://user:pass@127.0.0.1:$port"), 'localhost may skip TLS');
	$transcript = file_get_contents($log);
	has($transcript, 'AUTH IN PLAIN TEXT');
	has($transcript, 'C: '.base64_encode('user'));
	has($transcript, 'C: MAIL FROM:<hello@cafe.test>');
	has($transcript, 'C: RCPT TO:<owner@cafe.test>');
	has($transcript, 'From: Café <hello@cafe.test>');
	// but not to another host, unless you insist
	list($port, $log) = $smtp('plain', '127.0.0.2');
	has($send("smtp://user:pass@127.0.0.2:$port"), 'does not offer STARTTLS');
	lacks((string)@file_get_contents($log), 'C: AUTH', 'no password sent in plain text');
	same('sent', $send("smtp://user:pass@127.0.0.2:$port?insecure=1"));
	// STARTTLS upgrades before the password
	list($port, $log) = $smtp('starttls', '127.0.0.2');
	same('sent', $send("smtp://user:pass@127.0.0.2:$port"));
	$transcript = file_get_contents($log);
	has($transcript, 'TLS STARTED');
	has($transcript, 'AUTH OVER TLS');
	lacks($transcript, 'AUTH IN PLAIN TEXT');
	// smtps is TLS from the first byte
	list($port, $log) = $smtp('smtps', '127.0.0.2');
	same('sent', $send("smtps://user:pass@127.0.0.2:$port"));
	has(file_get_contents($log), 'AUTH OVER TLS');
	// the sender from config mail_from when RASTER_MAIL_FROM is not set
	list($port, $log) = $smtp('plain', '127.0.0.1');
	same('sent', $send("smtp://127.0.0.1:$port", false));
	has(file_get_contents($log), 'From: Raster Café <hello@cafe.test>');
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
	check(!empty($refused['result']['isError']), 'and calling it anyway is an error');
	has($refused['result']['content'][0]['text'], 'Unknown tool');
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
	same(array('events', 'faq', 'journal', 'menu', 'reservation'), $collections);
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
	$process = proc_open(array(PHP_BINARY, "$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes, $root, getenv());
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
	same(array('site', 'routing', 'pages', 'admin_pages', 'collections', 'vocabulary', 'settings', 'lint', 'schema', 'views'), array_keys($all));
	same('demo', $all['site']['app']);
	same('cafe', $all['site']['theme']);
	check(in_array('/menu', array_map(function ($p) { return $p['url']; }, $all['pages'])), 'the pages are there');
	same(array('events', 'faq', 'journal', 'menu', 'reservation'), array_map(function ($c) { return $c['name']; }, $all['collections']));
	same(4, $all['collections'][3]['page_size'], 'menu_page_size');
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
	check(in_array('reservation.booked', $vocabulary['events']['sent']), 'the demo sends it');
	check(isset($vocabulary['events']['listened_to']['reservation.booked']), 'and cafe listens');
	has($vocabulary['events']['listened_to']['reservation.booked'][0], 'cafe.subscribe_guest');
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

test(array('F1', 'F2', 'F4', 'F5'), 'schema: status, check, rename, drop', function () use ($base, $views) {
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
	has($text, 'reservation.booked -> cafe.subscribe_guest');
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
test('N9', 'raster export: the site as static files', function () use ($tmp) {
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
	foreach (array('login/index.html', 'members/index.html', 'register/index.html', 'account/index.html', 'ro/journal.rss') as $file) check(!file_exists("$out/$file"), "should not export $file");
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
	has($live, 'name="raster_form" value="reservation.book"');
	lacks($live, 'Call us on', 'print.if.static is hidden on the live site');
	// a form the template doesn't wrap stops the export, and nothing is written
	with_file("$views/export-probe.html", '<!doctype html><html><body><!-- render.reservation.contact --><form method="post"><input name="email"><button>Send</button></form><!-- /render.reservation.contact --></body></html>', function () use ($tmp) {
		list($code, $output) = raster(array('export', "$tmp/probe"));
		same(1, $code, $output);
		has($output, 'The reservation.contact form (/export-probe) needs PHP. Wrap it in <!-- print.if.live -->');
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
test('N4', 'serve', function () use ($root) {
	$port = free_port();
	$process = proc_open(array(PHP_BINARY, "$root/bin/raster", 'serve', "--port=$port", '--host=127.0.0.1'), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, getenv());
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	try {
		has(http('GET', "http://127.0.0.1:$port/about")[1], 'About us');
	} finally {
		proc_terminate($process);
		exec("pkill -f 'php -S 127.0.0.1:$port' 2>/dev/null");
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
		has($body, 'This page could not be shown.');
		lacks($body, 'render.cafe.hours', 'no details for visitors');
	});
	// any exception, not only template errors: a named query that doesn't exist
	@mkdir("$root/demo/models/zzquery");
	try {
		with_file("$root/demo/models/zzquery/zzquery.php", '<?php class zzquery { function rows() { return database::instance("zzquery")->no_such_query(); } }', function () use ($loud, $views, $tmp) {
			with_file("$views/zz-query.html", '<p><!-- render.zzquery.rows -->row<!-- /render.zzquery.rows --></p>', function () use ($loud, $tmp) {
				list($status, $body) = http('GET', "$loud/zz-query");
				same(500, $status);
				has($body, 'This page could not be shown.');
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
			.' echo mail::send_view("_email/contact", "owner@cafe.test", array("email" => "a@b.co", "message" => "Hello there")) ? "sent" : "failed: ".mail::$last_error;';
		return shell_exec($env.' '.escapeshellarg(PHP_BINARY).' '.$ini.' -r '.escapeshellarg($code).' 2>&1');
	};
	$before = count(glob("$root/demo/data/mail/*.eml") ?: array());
	same('sent', $send('RASTER_MAIL='));
	same($before + 1, count(glob("$root/demo/data/mail/*.eml") ?: array()), 'development logs to data/mail');
	$out = "$tmp/sendmail.txt";
	same('sent', $send('RASTER_MAIL=mail://', '-d '.escapeshellarg('sendmail_path=tee -a '.$out.' >/dev/null')));
	$message = file_get_contents($out);
	has($message, 'To: owner@cafe.test');
	has($message, 'From: Raster Café <hello@cafe.test>');
	has($message, 'Subject: =?UTF-8?B?');
});
test(array('J7', 'J8'), 'a language per domain, the cookie name', function () use ($base) {
	has(http('GET', "$base/lab", null, array('Host: ro.localhost'))[1], '>Meniu</a>');
	has(http('GET', "$base/lab", null, array('Host: localhost'))[1], '>Menu</a>');
	$headers = http('GET', "$base/lab?lang=ro")[2];
	has(implode("\n", $headers), 'Set-Cookie: cafe_lang=ro');
	lacks(implode("\n", $headers), 'Set-Cookie: lang=');
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
test(array('C38', 'C39'), 'a booking subscribes the guest through an event; site_overview shows the wiring', function () use ($base) {
	$book = function ($newsletter) use ($base) {
		$fields = array('raster_form' => 'reservation.book', 'name' => 'Ioana', 'email' => 'ioana@example.com', 'phone' => '0721 000 001', 'date' => '2026-10-08', 'guests' => '2', 'seating' => 'window', 'terms' => '1');
		if ($newsletter) $fields['newsletter'] = 'yes';
		same(303, http('POST', "$base/visit", $fields)[0]);
	};
	$before = count(mails());
	$book(false);
	same($before + 1, count(mails()), 'only the staff email');
	$book(true);
	$sent = array_slice(mails(), $before + 1);
	same(2, count($sent));
	same('staff@cafe.test', $sent[0]['to']);
	same('ioana@example.com', $sent[1]['to']);
	same('Confirm your Raster Café letters', $sent[1]['subject']);
	database::instance('cms');
	$subscriber = R::findOne('subscriber', ' email = ? ', array('ioana@example.com'));
	same('pending', $subscriber->status);
	same('/visit', $subscriber->source);
	same('Ioana', $subscriber->name);
	$events = mcp($base, 'site_overview')['events'];
	same(array('cafe.subscribe_guest'), $events['reservation.booked']);
	same(array('cafe.count_feed'), $events['executed_feed_items']);
	check(!isset($events['launch']), 'the framework\'s own plumbing is left out');
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
	with_file($file, file_get_contents($file)."\nevent::bind('reservation.booked')->to('cafe', 'no_such_method');\nevent::bind('reservation.bookd')->to('cafe', 'welcome');\nevent::bind('done')->to('ghost', 'boo');\n", function () {
		list($code, $out) = raster(array('lint'));
		same(1, $code, $out);
		has($out, "'reservation.booked' is bound to cafe.no_such_method, which is not a public method of cafe");
		has($out, "Nothing sends 'reservation.bookd'");
		has($out, "model 'ghost', which does not exist");
		lacks($out, "Nothing sends 'done'");
	});
	list($code, $out) = raster(array('lint'));
	same(0, $code, $out);
});


test('C43', 'named queries: a missing name is an error, and lint finds it', function () use ($root) {
	database::instance('cafe');
	same(1, count(database::instance()->count_category('cakes')), 'the model name sticks for later calls');
	try {
		database::instance('cafe')->count_categry('coffee');
		check(false, 'a misspelled query should throw');
	} catch (BadMethodCallException $e) {
		has($e->getMessage(), "No query named 'count_categry': add models/cafe/sql/count_categry.sql, or \$queries['count_categry'] in models/sql.php");
	}
	$file = "$root/demo/models/zz_queries/zz_queries.php";
	@mkdir(dirname($file));
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
	same('staging', config::environment_for('staging.cafe.test', '10.0.0.5', false, $servers));
	same('production', config::environment_for('staging.cafe.test.evil.example', '10.0.0.5', false, $servers), 'whole names only');
	same('production', config::environment_for('cafe.test', '10.0.0.5', false, $servers));
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); echo config::get("environment");';
	same('staging', shell_exec('RASTER_ENV=staging '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code)));
});
test(array('F3', 'F6', 'I5', 'L3', 'L4', 'L5', 'E13', 'J6'), 'production: frozen schema, trusted address, page cache', function () use ($root, $tmp, $maildir) {
	$prod_db = "$tmp/prod.sqlite";
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => $prod_db, 'RASTER_URL' => 'https://cafe.example/');
	same(1, raster(array('schema', '--check'), $env)[0], 'a new database differs from the templates');
	list($code, $out) = raster(array('schema', '--apply'), $env);
	same(0, $code, $out);
	has($out, 'created table user');
	has($out, 'created table subscriber');
	has($out, 'created table reservation', 'app models declare their tables');
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
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($prod_db).' '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
	list(, $body, $headers) = http('GET', "$prod/about");
	same('miss', header_value($headers, 'X-Raster-Cache'), 'edits clear the cache');
	has($body, '<h1>Cached no more</h1>');
	// a scheduled item clears the cache when it is published
	$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli(); cms_store::connect(); R::freeze(false); cms_store::save_item("eventsdata", 0, array("title" => "Soon", "date" => "2026-10-20", "published_at" => date("Y-m-d H:i:s", time() + 2)), array("title", "date"));';
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($prod_db).' '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
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
		return shell_exec('RASTER_URL= '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
	};
	check($key(true) !== $key(false), 'http and https share a cache entry');
});

test(array('L7', 'L3'), 'page cache: skipped paths, time to live, turned off', function () use ($tmp, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => "$tmp/prod.sqlite", 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir");
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
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => "$tmp/prod.sqlite", 'RASTER_URL' => 'https://cafe.example/', 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'demo-token');
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
	$types = array_values(array_filter($overview['collections'], function ($c) { return $c['name'] === 'reservation'; }));
	same('reservation', $types[0]['declared_by']);
	same(false, $types[0]['public']);
	same(array('confirm', 'cancel'), $types[0]['actions']);
	check(in_array('status', $types[0]['fields']) && in_array('guests', $types[0]['fields']), 'the model\'s fields');
	same(array('staff.html', 'account.html'), array_values(array_intersect(array('staff.html', 'account.html'), $types[0]['used_in'])), 'where views show it');
	$described = array_values(array_filter(mcp($base, 'describe', array('sections' => array('collections')))['collections'], function ($c) { return $c['name'] === 'reservation'; }));
	same('visitor', $described[0]['create']);
	same(array('status'), $described[0]['readonly']);
	database::instance('cms');
	foreach (R::find('reservationdata') as $row) check($row->name !== 'Ana' || $row->notes !== 'Window seat', 'the mock-up is never stored as a record');
	list($code, $out) = raster(array('schema'));
	has($out, 'records reservation  (declared by the reservation model');
});

test(array('R2', 'R4'), 'a form stores a record; visitors never see records, editors see them all', function () use ($base) {
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Radu <em>R</em>', 'email' => 'radu@example.com', 'date' => '2026-11-03', 'guests' => '2', 'seating' => 'inside', 'terms' => '1', 'status' => 'confirmed', 'owner' => '1'));
	same(303, $status);
	same("$base/visit?done=booked", header_value($headers, 'Location'));
	database::instance('cms');
	$row = R::findOne('reservationdata', ' email = ? ', array('radu@example.com'));
	same('new', $row->status, 'a visitor can\'t set a readonly field');
	same(0, (int)$row->owner, 'nor the owner');
	check($row->created_at !== '', 'records get created_at');
	same('', (string)$row->occasion, 'fields the type does not declare are not stored');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$body = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
	has($body, 'Radu &lt;em&gt;R&lt;/em&gt;', 'what visitors typed prints as text');
	// /reservation lists bookings, and so gives them item URLs; anyone can open it
	list($status, $body) = http('GET', "$base/reservation");
	same(200, $status);
	lacks($body, 'Radu', 'visitors see no records');
	lacks($body, 'class="booking"', 'not even the mock-up');
	same(404, http('GET', "$base/reservation/reservation_item/{$row->id}")[0], 'item URLs are not a way in');
	same(200, http('GET', "$base/reservation/reservation_item/{$row->id}", null, array("Cookie: $staff"))[0], 'but staff can open them');
	lacks(http('GET', "$base/reservation/reservation_items/status/new")[1], 'class="booking"', 'nor filter addresses');
	lacks(http('GET', "$base/sitemap.xml")[1], 'reservation', 'nor the sitemap');
	with_file(dirname(__DIR__).'/demo/views/cafe/zz-feed.rss', '<rss><channel><!-- render.feed.items(\'reservation\') --><item><!-- print.name -->x<!-- /print.name --></item><!-- /render.feed.items(\'reservation\') --></channel></rss>', function () use ($base) {
		lacks(http('GET', "$base/zz-feed.rss")[1], 'Radu', 'nor feeds');
	});
});

test('R3', 'check() runs on every write: the form, the editor, MCP and the model\'s own code', function () use ($base) {
	$book = function ($name, $guests) use ($base) {
		return http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => $name, 'email' => 'big@example.com', 'date' => '2026-12-01', 'guests' => (string)$guests, 'terms' => '1'));
	};
	for ($i = 0; $i < 2; $i++) same(303, $book("Group $i", 8)[0]);
	list($status, $body) = $book('One too many', 5);
	same(200, $status);
	has($body, 'We are full that day.', 'the problem is an alert the template words');
	has($body, 'value="One too many"', 'and the form keeps what was typed');
	database::instance('cms');
	same(2, (int)R::count('reservationdata', ' date = ? ', array('2026-12-01')));
	$group = R::findOne('reservationdata', ' name = ? ', array('Group 0'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$token = token_in(http('GET', "$base/staff", null, $h)[1]);
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'reservation', 'id' => $group->id, 'fields' => array('guests' => '8', 'name' => 'Group 0 (8)'), 'csrf' => $token), $h);
	same(200, $status, 'the booking itself is not counted twice');
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'reservation', 'id' => 0, 'fields' => array('name' => 'Phone booking', 'date' => '2026-12-01', 'guests' => '6'), 'csrf' => $token), $h);
	same(422, $status);
	same(array('fully_booked'), json_decode($body, true)['problems']);
	$refused = json_decode(http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'create_item', 'arguments' => array('collection' => 'reservation', 'fields' => array('name' => 'Agent', 'date' => '2026-12-01', 'guests' => '6'))))), array('Authorization: Bearer demo-token', 'Content-Type: application/json'))[1], true)['result'];
	check(!empty($refused['isError']), 'MCP is refused too');
	has($refused['content'][0]['text'], 'fully_booked');
	cms_records::forget();
	try {
		cms_records::create('reservation', array('name' => 'Code', 'date' => '2026-12-01', 'guests' => 5));
		check(false, 'the model\'s own code is checked');
	} catch (cms_refused $e) {
		same(array('fully_booked'), $e->problems);
	}
});

test('R5', 'owners read their own records', function () use ($base) {
	$member = login($base, 'maria@example.com', 'reset password');
	$h = array("Cookie: $member");
	$token = token_in(http('GET', "$base/visit", null, $h)[1]);
	same(303, http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Maria', 'email' => 'maria@example.com', 'date' => '2026-11-20', 'guests' => '3', 'terms' => '1', 'csrf' => $token), $h)[0]);
	$account = http('GET', "$base/account", null, $h)[1];
	has($account, '2026-11-20, 3 guests: new');
	lacks($account, 'Radu', 'only their own');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$staff_account = http('GET', "$base/account", null, array("Cookie: $staff"))[1];
	lacks($staff_account, '2026-11-20', 'owner=me: staff see their own bookings on /account, not everyone\'s');
	has(http('GET', "$base/staff", null, array("Cookie: $staff"))[1], 'Maria', 'while /staff lists every booking');
	database::instance('cms');
	$user = R::findOne('user', ' email = ? ', array('maria@example.com'));
	same((int)$user->id, (int)R::findOne('reservationdata', ' date = ? ', array('2026-11-20'))->owner);
});

test(array('R6', 'R8'), 'readonly fields and actions: buttons for the roles allowed, editor_action, run_action, refusals', function () use ($base) {
	database::instance('cms');
	$booking = R::findOne('reservationdata', ' email = ? ', array('radu@example.com'));
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$page = http('GET', "$base/staff", null, $h)[1];
	$config = editor_config($page);
	list($id, $mark) = mark_of($config, 'item', function ($m) use ($booking) { return $m['collection'] === 'reservation' && $m['id'] === (int)$booking->id; });
	check($id !== null, 'the booking is marked for the editor');
	same(true, $mark['record']);
	same(array('confirm', 'cancel'), $mark['actions']);
	check(in_array('status', $mark['readonly']), 'status is shown, not edited');
	list(, $list) = mark_of($config, 'collection', function ($m) { return $m['collection'] === 'reservation'; });
	same(true, $list['addable'], 'staff_add: a card for a booking taken over the phone');
	list(, $field) = mark_of($config, 'item_field', function ($m) use ($id) { return $m['item'] === (int)$id && $m['field'] === 'status'; });
	same(true, $field['readonly'], 'the status is marked readonly: shown, updated by actions, never editable');
	$token = token_in($page);
	list($status, $body) = http('POST', "$base/api/cms/editor_save_item", array('collection' => 'reservation', 'id' => $booking->id, 'fields' => array('status' => 'confirmed'), 'csrf' => $token), $h);
	same(400, $status);
	has(json_decode($body, true)['error'], "'status' can't be changed here");
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'reservation', 'id' => $booking->id, 'action' => 'confirm', 'csrf' => $token), $h);
	same(200, $status);
	same('confirmed', json_decode($body, true)['status']);
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'reservation', 'id' => $booking->id, 'action' => 'refund', 'csrf' => $token), $h);
	same(400, $status);
	has(json_decode($body, true)['error'], 'no action');
	same('cancelled', mcp($base, 'run_action', array('collection' => 'reservation', 'id' => (int)$booking->id, 'action' => 'cancel'))['status']);
	list($status, $body) = http('POST', "$base/api/cms/editor_action", array('collection' => 'reservation', 'id' => $booking->id, 'action' => 'confirm', 'csrf' => $token), $h);
	same(422, $status);
	same(array('already_cancelled'), json_decode($body, true)['problems']);
	$member = login($base, 'maria@example.com', 'reset password');
	same(403, http('POST', "$base/api/cms/editor_action", array('collection' => 'reservation', 'id' => $booking->id, 'action' => 'confirm'), array("Cookie: $member"))[0], 'members have no actions');
	same(404, http('GET', "$base/api/reservation/confirm")[0], 'actions are static: /api never reaches them');
	same(404, http('GET', "$base/api/reservation/check")[0]);
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
test('R14', 'staff see bookings grouped by status, and one evening at a time', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	database::instance('cms');
	$new = R::findOne('reservationdata', ' status = ? AND date >= ? ', array('new', date('Y-m-d')));
	$cancelled = R::findOne('reservationdata', ' status = ? ', array('cancelled'));
	check($new && $cancelled, 'bookings in both states');
	$page = http('GET', "$base/staff", null, $h)[1];
	$confirm = staff_section($page, 'To confirm');
	has($confirm, util::e($new->name), 'a new booking is to confirm');
	same((int)R::count('reservationdata', ' status = ? AND date >= ? ', array('new', date('Y-m-d'))), booking_rows($confirm), 'only new ones to come are to confirm');
	lacks($confirm, util::e($cancelled->name), 'not the cancelled ones');
	has($page, 'href="'.$base.'/reservation/reservation_items/date/'.$new->date.'/"', 'each date links to its evening');
	$evening = http('GET', "$base/reservation/reservation_items/date/{$new->date}/", null, $h)[1];
	has($evening, util::e($new->name));
	same((int)R::count('reservationdata', ' date = ? ', array($new->date)), booking_rows($evening), 'only that evening');
	$open = http('GET', "$base/reservation/reservation_items/status/new", null, $h)[1];
	has($open, util::e($new->name));
	same((int)R::count('reservationdata', ' status = ? ', array('new')), booking_rows($open), 'only what is to confirm');
	check(booking_rows($open) < (int)R::count('reservationdata'), 'which is not all of them');
	same((int)R::count('reservationdata'), booking_rows(http('GET', "$base/reservation", null, $h)[1]), 'every booking on /reservation');
});

test(array('R15', 'D26'), 'list options: filters from the URL, dates, several orders; get forms keep what was asked', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$h = array("Cookie: $staff");
	$day = function ($n) { return date('Y-m-d', strtotime("$n days")); };
	cms_records::create('reservation', array('name' => 'Past Pia', 'date' => $day(-3), 'seating' => 'window', 'guests' => 1));
	cms_records::create('reservation', array('name' => 'Gone Gus', 'date' => $day(-3), 'seating' => 'window', 'guests' => 1, 'status' => 'cancelled'));
	cms_records::create('reservation', array('name' => 'Zed Window', 'date' => $day(40), 'seating' => 'window', 'guests' => 1));
	cms_records::create('reservation', array('name' => 'Abe Window', 'date' => $day(40), 'seating' => 'window', 'guests' => 1));
	cms_records::create('reservation', array('name' => 'Ida Inside', 'date' => $day(40), 'seating' => 'inside', 'guests' => 1));
	cms_records::create('reservation', array('name' => 'Early Window', 'date' => $day(39), 'seating' => 'window', 'guests' => 1));

	$page = http('GET', "$base/staff", null, $h)[1];
	$names = booking_names(staff_section($page, 'To confirm'));
	check(!in_array('Past Pia', $names), 'past bookings are not to confirm: '.implode(', ', $names));
	same(array('Early Window', 'Abe Window', 'Ida Inside', 'Zed Window'), array_values(array_intersect($names, array('Early Window', 'Abe Window', 'Ida Inside', 'Zed Window'))), 'order=date,name');
	$past = booking_names(staff_section($page, 'Past evenings'));
	check(in_array('Past Pia', $past), 'date<today lists the past');
	check(!in_array('Gone Gus', $past), 'status!=cancelled leaves cancelled out');
	foreach ($past as $name) check(!in_array($name, $names), "$name is not both past and to come");

	$window = http('GET', "$base/staff?seating=window", null, $h)[1];
	$names = booking_names(staff_section($window, 'To confirm'));
	check(in_array('Abe Window', $names) && !in_array('Ida Inside', $names), 'seating=?seating filters by the URL');
	has($window, '<option value="window" selected>', 'the get form keeps the choice');
	same(array('Early Window'), booking_names(staff_section(http('GET', "$base/staff?seating=window&day=".$day(39), null, $h)[1], 'To confirm')), 'two filters from the URL');
	has(http('GET', "$base/staff?day=".$day(39), null, $h)[1], 'name="day" value="'.$day(39).'"');
	same(booking_names(staff_section($page, 'To confirm')), booking_names(staff_section(http('GET', "$base/staff?seating=", null, $h)[1], 'To confirm')), 'an empty parameter filters nothing');

	// the same in code
	$list = cms_store::list_options('status!=cancelled&date<today&guests>=2&seating=?seating&order=-date,name&limit=5');
	same(array(array('status', '!=', 'cancelled'), array('date', '<', $day(0)), array('guests', '>=', '2')), $list['conditions'], 'no ?seating in the URL here');
	same(array('order' => '-date,name', 'limit' => '5'), $list['options']);
	same('date DESC, name ASC, id ASC', cms_store::order_sql('-date,name', array('date' => 1, 'name' => 1)));
	same('id ASC', cms_store::order_sql('nope,-nope', array('date' => 1)));
	// pages of a list filtered from the URL keep the query
	has(http('GET', "$base/menu?category=cakes")[1], 'menu_page/2?category=cakes', 'pagination keeps the query');
});
test(array('R16', 'N14', 'M17'), 'staff add bookings on the lists a new one shows in, and agents render as staff', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$config = editor_config(http('GET', "$base/staff?seating=window", null, array("Cookie: $staff"))[1]);
	$lists = array();
	foreach ($config['marks'] as $mark) if ($mark['kind'] === 'collection') $lists[$mark['list']] = $mark;
	$upcoming = $lists['status=new&date>=today&seating=?seating&date=?day&order=date,name'];
	same(true, $upcoming['addable'], 'staff_add: a card for a new booking');
	same(array('status' => 'new', 'seating' => 'window'), $upcoming['filters'], 'which starts with the filters the URL gave');
	$info = cms_records::info('reservation');
	$info['staff_add'] = false;
	same(false, cms_records::addable($info), 'without staff_add, bookings come from the form only');

	list($code, $out) = raster(array('render', '/staff?seating=window', '--as=editor'));
	same(0, $code, $out);
	has($out, 'class="booking"');
	has($out, "reservation('status=new&date>=today&seating=?seating&date=?day&order=date,name') + new");
	check(strpos(staff_section($out, 'To confirm'), 'Ida Inside') === false, 'the query string reached the page');
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

	$answers = mcp_stdio(array(array('render_url', array('url' => '/staff?seating=window', 'as' => 'editor', 'limit' => 200)), array('render_url', array('url' => '/staff', 'limit' => 200))));
	same('editor', $answers[0]['editor']['role']);
	same(array("reservation('status=new&date>=today&seating=?seating&date=?day&order=date,name') + new", "reservation('status!=cancelled&date<today&seating=?seating&date=?day&order=-date,name&limit=20') + new"), $answers[0]['editor']['lists']);
	check($answers[0]['editor']['items'] > 0, 'the agenda\'s bookings are items too');
	same(null, $answers[0]['errors'], 'the summary is not an error');
	check(!isset($answers[1]['editor']), 'no editor summary for visitors');
});
test('R18', 'a model view of records stays editable: listed() rows, nested in the model\'s rows', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$tomorrow = date('Y-m-d', strtotime('+1 day'));
	cms_records::create('reservation', array('name' => 'Tom Tomorrow', 'date' => $tomorrow, 'seating' => 'inside', 'guests' => 1));
	cms_records::create('reservation', array('name' => 'Bea <b>Bold</b>', 'date' => $tomorrow, 'guests' => 1));
	$page = http('GET', "$base/staff", null, array("Cookie: $staff"))[1];
	$config = editor_config($page);
	$tom = array_values(array_filter($config['marks'], function ($m) { return $m['kind'] === 'item' && isset($m['values']['name']) && $m['values']['name'] === 'Tom Tomorrow'; }));
	same(2, count($tom), 'Tom is an item in To confirm and in the agenda');
	same('reservation', $tom[1]['collection']);
	same(array('confirm', 'cancel'), $tom[1]['actions'], 'with the type\'s actions');
	check(in_array('status', $tom[1]['readonly'], true) && !in_array('name', $tom[1]['readonly'], true), 'readonly as in a render.cms list');
	check(in_array('raster_detail_link', array_keys(cms_records::listed('reservation', cms_records::find('reservation', array('name' => 'Tom Tomorrow')))[0])), 'each row links to its own page');
	$lists = array_filter($config['marks'], function ($m) { return $m['kind'] === 'collection'; });
	same(2, count($lists), 'the agenda adds no lists of its own: new bookings come from render.cms lists');
	$agenda = substr($page, strpos($page, '<h3>By evening</h3>'));
	has($agenda, 'class="agenda-booking"');
	has($agenda, 'Bea &lt;b&gt;Bold&lt;/b&gt;', 'listed rows print what visitors typed as text');

	// a value the model adds to a row prints, and is not editable
	$info = cms_records::info('reservation');
	check(cms_records::locked($info, 'seats_left') && !cms_records::locked($info, 'name'), 'fields the type does not store are read-only');
	same(array(), array_diff(array_keys(cms_records::listed('reservation', array(array('id' => 1, 'name' => 'x')))), array(0)), 'listed() returns the rows and nothing else');
});
test('R19', 'a record\'s own page is where editors edit what a model view links to', function () {
	database::instance('cms');
	$abe = R::findOne('reservationdata', ' name = ? ', array('Abe Window'));
	list($code, $out) = raster(array('render', '/reservation/reservation_item/'.$abe->slug, '--as=editor'));
	same(0, $code, $out);
	has($out, 'Abe Window');
	has($out, '1 items, lists:');
	lacks($out, '+ new', 'no card on an item\'s page');
	list($code, $out) = raster(array('render', '/reservation/reservation_item/'.$abe->slug));
	same(1, $code, 'visitors get a 404');
	has($out, 'HTTP 404');
	lacks($out, 'Abe Window');
});
test('R17', 'lint: records nobody lists, and staff pages listing what a model reads', function () {
	$inspector = new raster_inspector();
	$views = $inspector->theme_dir();
	$models = APPBASE.'models';
	@mkdir("$models/bookrows");
	try {
		with_file("$models/bookrows/bookrows.php", "<?php\nclass bookrows {\n\tfunction rows() {\n\t\treturn cms_records::find('reservation', array(), 'date');\n\t}\n\tfunction counts() { return array(); }\n\tfunction agenda() {\n\t\treturn cms_records::listed('reservation', cms_records::find('reservation'));\n\t}\n}\n", function () use ($inspector, $views) {
			$problems = $inspector->lint_source('<!-- render.bookrows.rows --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.rows --><!-- render.bookrows.counts --><p>y</p><!-- /render.bookrows.counts -->', 'staff.html');
			check(has_message($problems, "shows reservation records the bookrows model reads itself without cms_records::listed()"), 'an admin page listing model rows');
			same(1, count($problems), 'only the method that reads records');
			$problems = $inspector->lint_source('<!-- render.bookrows.agenda --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.agenda -->', 'staff.html');
			same(array(), $problems, 'records handed back through cms_records::listed are fine');
			same(array(), $inspector->lint_source('<!-- render.bookrows.rows --><p><!-- print.name -->x<!-- /print.name --></p><!-- /render.bookrows.rows -->', 'thanks.html'), 'pages for visitors may');
		});
	} finally { @rmdir("$models/bookrows"); }
	$listed = function () use ($inspector) { return has_message($inspector->lint_types(), "Visitors make 'reservation' records with a form, but no view lists them"); };
	check(!$listed(), 'the café lists its bookings');
	$originals = array();
	foreach (array('staff.html', 'reservation.html', 'account.html') as $view) $originals[$view] = file_get_contents("$views/$view");
	try {
		foreach ($originals as $view => $html) file_put_contents("$views/$view", str_replace('render.cms.reservation(', 'render.cms.elsewhere(', $html));
		check($listed(), 'a form type no view lists');
	} finally {
		foreach ($originals as $view => $html) file_put_contents("$views/$view", $html);
	}
});
test('M18', 'MCP over stdio: a PHP error in site code answers the call', function () {
	$models = APPBASE.'models';
	@mkdir("$models/stopper");
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
	@mkdir($probe_dir);
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
			@mkdir($plain);
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
				$lint = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname($probe_dir, 3).'/bin/raster').' lint 2>&1');
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

test(array('R12', 'R13'), 'schema --apply creates record tables in production; make model writes a model from a form', function () use ($tmp, $root) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => "$tmp/records-prod.sqlite");
	list($code, $out) = raster(array('schema', '--apply'), $env);
	same(0, $code, $out);
	has($out, 'created table reservationdata');
	$pdo = new PDO("sqlite:$tmp/records-prod.sqlite");
	same(0, (int)$pdo->query('SELECT COUNT(*) FROM reservationdata')->fetchColumn(), 'no row left behind');
	$columns = array_map(function ($c) { return $c['name']; }, $pdo->query('PRAGMA table_info(reservationdata)')->fetchAll(PDO::FETCH_ASSOC));
	foreach (array('status', 'guests', 'owner', 'created_at', 'enabled', 'slug') as $column) check(in_array($column, $columns), "column $column");
	list($code, $out) = raster(array('schema', '--check'), $env);
	same(0, $code, $out);
	// the contact form on /visit, as a type
	list($code, $out) = raster(array('make', 'model', 'reservation', '--from=visit.html'));
	same(1, $code);
	has($out, 'exists; --force replaces it');
	list($code, $code_out) = raster(array('make', 'model', 'reservation', '--from=visit', '--form=contact', '--dry-run'));
	same(0, $code, $code_out);
	has($code_out, "'email' => '',");
	has($code_out, "'message' => '',");
	has($code_out, 'static function check($type, $after, $before)');
	has($code_out, "return cms_records::submit('reservation', 'reservation_sent');");
	has($code_out, 'function contact()');
	lacks($code_out, "'name' => ''", 'only the contact form\'s fields');
	check(php_parses($code_out), 'the code parses');
	list($code, $out) = raster(array('make', 'model', 'Bad!', '--from=visit.html'));
	same(1, $code);
	same(2, raster(array('make', 'model'))[0], 'usage');
});

test('C48', '/api answers only what a model lists, for the roles it names', function () use ($base, $root) {
	// listed for visitors
	same(200, http('GET', "$base/api/cafe/hours")[0]);
	// public methods a model doesn't list are not there, whatever they return
	same(404, http('GET', "$base/api/cafe/stamp")[0], 'an event handler');
	same(404, http('GET', "$base/api/reservation/booked")[0], 'a listener');
	same(404, http('GET', "$base/api/reservation/book")[0], 'a form handler');
	// an override is only reached by the name it overrides, and that name
	// only when api_system_models lists it
	same(404, http('GET', "$base/api/the_feed/generator")[0], 'never addressed as the_<model>');
	same(404, http('GET', "$base/api/feed/generator")[0]);
	// listed for staff: one evening's bookings, private records
	list($status, $body) = http('GET', "$base/api/reservation/day/2026-12-01");
	same(401, $status, 'visitors are asked to log in');
	lacks($body, 'Group');
	$member = login($base, 'maria@example.com', 'reset password');
	same(403, http('GET', "$base/api/reservation/day/2026-12-01", null, array("Cookie: $member"))[0], 'members are not staff');
	$staff = login($base, 'staff@cafe.test', 'staff password');
	list($status, $body) = http('GET', "$base/api/reservation/day/2026-12-01", null, array("Cookie: $staff"));
	same(200, $status);
	check((bool)preg_grep('/^Group /', array_column(json_decode($body, true), 'name')), 'staff get the bookings');
	// the vocabulary tells agents what each model offers
	$vocabulary = json_decode(raster(array('vocabulary', '--json'))[1], true);
	same(array('day' => 'editor'), $vocabulary['models']['reservation']['api']);
	same(array(), $vocabulary['models']['secret']['api'], 'a model that lists nothing offers nothing');
	check(!isset($vocabulary['models']['cms']['api']) && !isset($vocabulary['models']['feed']['api']), 'bundled models, overridden or not, guard themselves');
	has(raster(array('vocabulary'))[1], '/api: day (editor)');
	// api_open, which the 2.1.1 upgrade writes for older sites: models that
	// list nothing answer as before, models that list keep their list
	$dir = "$root/demo/models/zzopen";
	@mkdir($dir);
	try {
		with_file("$dir/zzopen.php", "<?php\nclass zzopen { function ping() { return 'pong'; } }\n", function () use ($base) {
			$open = array('CAFE_API_OPEN' => 'on', 'RASTER_APP' => 'demo');
			lacks(raster(array('render', '/api/zzopen/ping'))[1], 'pong', 'closed by default');
			same('"pong"', trim(raster(array('render', '/api/zzopen/ping'), $open)[1]), 'open with api_open');
			has(raster(array('render', '/api/cafe/stamp'), $open)[1], 'unknown method', 'a model that lists keeps its list');
			has(raster(array('render', '/api/reservation/day/2026-12-01'), $open)[1], 'not allowed', 'and its roles');
			has(raster(array('render', '/api/the_feed/generator'), $open)[1], 'unknown model', 'an override is still never addressed directly');
			same('open', json_decode(raster(array('vocabulary', '--json'), $open)[1], true)['models']['zzopen']['api']);
			has(raster(array('vocabulary'), $open)[1], '/api: every public method, to anyone');
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
	same(array('int', 'bool', 'date', 'text'), array($types['reservation']['guests'], $types['reservation']['newsletter'], $types['reservation']['date'], $types['reservation']['name']));
	database::instance('cms');
	cms_store::forget();
	same(array('REAL', 'DATE', 'TIME', 'BOOLEAN'), array(cms_store::columns('menudata')['price'], cms_store::columns('eventsdata')['date'], cms_store::columns('eventsdata')['starts'], cms_store::columns('menudata')['enabled']), 'the columns are declared as their types');
	$overview = mcp($base, 'site_overview');
	foreach ($overview['collections'] as $c) $listed[$c['name']] = isset($c['types']) ? $c['types'] : array();
	same(array('price' => 'number', 'featured' => 'int'), $listed['menu'], 'only the fields that aren\'t text');
	same(array('date' => 'date', 'starts' => 'time'), $listed['events']);
	$described = json_decode(raster(array('describe', '--json', '--sections=collections'))[1], true);
	foreach ($described['collections'] as $c) if ($c['name'] === 'reservation') same(array('date' => 'date', 'guests' => 'int', 'newsletter' => 'bool'), $c['types'] + array());
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
	has($body, "'price' must be a number");
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

test('T4', 'models, MCP and /api read ints, floats and bools; the model needs no casts', function () use ($base) {
	same(303, http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Tudor', 'email' => 'tudor@example.com', 'date' => '2026-11-20', 'guests' => '3', 'newsletter' => 'yes', 'terms' => '1'))[0]);
	$booking = cms_records::find('reservation', array('email' => 'tudor@example.com'))[0];
	same(array(3, true, '2026-11-20'), array($booking['guests'], $booking['newsletter'], $booking['date']));
	check(is_int($booking['id']) && is_int($booking['owner']), 'ids are ints');
	same(array(), cms_records::find('reservation', array('email' => 'tudor@example.com', 'newsletter' => false)), 'find() filters by type too');
	same(3, mcp($base, 'get_item', array('collection' => 'reservation', 'id' => $booking['id']))['guests']);
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$day = json_decode(http('GET', "$base/api/reservation/day/2026-11-20", null, array("Cookie: $staff"))[1], true);
	$tudor = array_values(array_filter($day, function ($b) { return $b['name'] === 'Tudor'; }))[0];
	same(array(3, true), array($tudor['guests'], $tudor['newsletter']));
	// MCP takes values as their types, and a whole number for an int
	same(4, mcp($base, 'update_item', array('collection' => 'reservation', 'id' => $booking['id'], 'fields' => array('guests' => 4)))['guests']);
	try {
		mcp($base, 'update_item', array('collection' => 'reservation', 'id' => $booking['id'], 'fields' => array('guests' => 'many')));
		throw new Exception('many guests were stored');
	} catch (Exception $e) {
		has($e->getMessage(), "'guests' must be a whole number, like 4");
	}
	mcp($base, 'delete_item', array('collection' => 'reservation', 'id' => $booking['id']));
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
	list($status, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Half', 'email' => 'half@example.com', 'date' => '2026-11-21', 'guests' => '2.5', 'terms' => '1'));
	same(200, $status);
	has($body, 'We seat whole guests');
	same(array(), cms_records::find('reservation', array('email' => 'half@example.com')), 'nothing stored');
	lacks(raster(array('lint'))[1], 'guests_invalid', 'lint knows Raster raises it');
	// any model's numbers print with the mock-up's decimals, records included
	$model = dirname(__DIR__).'/demo/models/zzprices/zzprices.php';
	@mkdir(dirname($model));
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
	same(303, http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Box', 'email' => 'box@example.com', 'date' => '2026-11-22', 'guests' => '2', 'newsletter' => 'subscribe', 'terms' => '1'))[0]);
	$box = cms_records::find('reservation', array('email' => 'box@example.com'))[0];
	same(true, $box['newsletter']);
	mcp($base, 'delete_item', array('collection' => 'reservation', 'id' => $box['id']));
});

test('T6', 'schema --apply converts a column whose type changed when every value fits, and names the values that don\'t', function () use ($base) {
	$view = dirname(__DIR__).'/demo/views/cafe/zz-retype.html';
	$markup = function ($mock) { return "<!-- render.cms.zzretype --><p><!-- print.n -->$mock<!-- /print.n --></p><!-- /render.cms.zzretype -->"; };
	with_file($view, $markup('5'), function () use ($base, $view, $markup) {
		http('GET', "$base/zz-retype");
		cms_store::forget();
		same('INT', cms_store::columns('zzretypedata')['n']);
		file_put_contents($view, $markup('five'));
		list(, $out) = raster(array('schema'));
		has($out, 'n (int in the database, text in the templates; --apply converts it)');
		has(raster(array('schema', '--apply'))[1], 'zzretypedata.n is now text (was int)');
		$lots = mcp($base, 'create_item', array('collection' => 'zzretype', 'fields' => array('n' => 'lots')));
		file_put_contents($view, $markup('5'));
		list(, $out) = raster(array('schema', '--apply'));
		has($out, 'kept zzretypedata.n as text: "lots" can\'t be int; change them and run --apply again');
		cms_store::forget();
		same('TEXT', cms_store::columns('zzretypedata')['n']);
		// a column SQLite won't drop (it has an index) stays as it was, with
		// nothing half done
		mcp($base, 'delete_item', array('collection' => 'zzretype', 'id' => $lots['id']));
		R::exec('CREATE INDEX zz_n ON zzretypedata (n)');
		has(raster(array('schema', '--apply'))[1], 'kept zzretypedata.n as it was:');
		cms_store::forget();
		same(array('TEXT', false), array(cms_store::columns('zzretypedata')['n'], isset(cms_store::columns('zzretypedata')['raster_n_retyped'])));
		R::exec('DROP INDEX zz_n');
	});
	raster(array('schema', '--drop=zzretypedata', '--force'));
});

// ## 2.1.8 batch A: page cache
// (batch A adds its tests here)

// ## 2.1.8 batch B: errors and /api
// (batch B adds its tests here)

// ## 2.1.8 batch C: list SQL
// (batch C adds its tests here)

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
// (batch E adds its tests here)

// ## 2.1.8 batch F: upgrade tooling
// (batch F adds its tests here)

// ## 2.1.8 batch G: MCP themes

test('M19', 'the MCP view tools take a theme only as a folder directly under views/', function () use ($base, $root, $views) {
	$themes = dirname($views);
	$outside = sys_get_temp_dir().'/raster-demo-theme-'.getmypid();
	@mkdir($outside);
	file_put_contents("$outside/secret.json", '{"secret":"sk-live-123"}');
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
		$answers = mcp_stdio(array(
			array('write_view', array('view' => 'zz-pwned.html', 'content' => '<p>x</p>', 'theme' => '../../media')),
			array('write_view', array('view' => 'zz-pwned.html', 'content' => '<p>x</p>', 'theme' => 'zzlinked')),
		));
		check(isset($answers[0]['error']) && isset($answers[1]['error']), 'write_view is refused');
		check(!file_exists("$root/media/zz-pwned.html"), 'nothing in media/');
		check(!file_exists("$outside/zz-pwned.html"), 'nothing through the link');
	} finally {
		@unlink("$themes/zzlinked");
		@unlink("$root/media/zz-pwned.html");
		foreach (glob("$outside/*") as $file) unlink($file);
		@rmdir($outside);
	}
});

// ## 2.1.8 batch H: row loop
// (batch H adds its tests here)

// ## No PHP warnings, notices or deprecations on any request

$log = is_file("$tmp/php-errors.log") ? file_get_contents("$tmp/php-errors.log") : '';
if (preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $log, $problems)) {
	$failed[] = 'PHP reported problems while serving pages:'."\n    ".implode("\n    ", array_unique($problems[0]));
}

// ## Coverage: every feature in demo/README.md has a passing test

$readme = file_get_contents("$root/demo/README.md");
preg_match_all('/^\| ([A-Z]\d+) \|/m', $readme, $ids);
$missing = array_diff($ids[1], array_keys($covered));
$unknown = array_diff(array_keys($covered), $ids[1]);
if ($unknown) $failed[] = 'tests name features that demo/README.md does not list: '.implode(', ', $unknown);
echo "\n\n$passed passed, ".count($failed)." failed; ".(count($ids[1]) - count($missing))."/".count($ids[1])." features covered\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
if ($missing) echo "  Not covered: ".implode(', ', $missing)."\n";
exit($failed || $missing ? 1 : 0);
