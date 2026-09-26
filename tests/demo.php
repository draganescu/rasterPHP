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
function between($html, $id) {
	return preg_match('#<section id="'.$id.'">(.*?)</section>#s', $html, $m) ? $m[1] : '';
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
	same(1, (int)R::count('reservation'));
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
	same(200, $status);
	same('', $body);
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
	check(preg_match('#<ol class="priciest">\s*<li>Crème brûlée 18</li>\s*<li>Flat white 14</li></ol>#', $ordering), "order=-price: $ordering");
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
	same(400, $save('editor_save_field', array('type' => 'userpage', 'field' => 'password', 'value' => 'x'))[0], 'only CMS page tables');
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
	same(11, count($tools));
	$batch = json_decode($call(array(array('jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'), array('jsonrpc' => '2.0', 'id' => 5, 'method' => 'nope')))[1], true);
	same(-32601, $batch[1]['error']['code']);
	same(-32700, json_decode(http('POST', "$base/mcp", 'not json', array('Authorization: Bearer demo-token'))[1], true)['error']['code']);
});
test('M4', 'every MCP tool', function () use ($base) {
	$overview = mcp($base, 'site_overview');
	$urls = array_map(function ($p) { return $p['url']; }, $overview['pages']);
	foreach (array('site', '/', '/about', '/menu', '/docs/setup') as $url) check(in_array($url, $urls), "overview lacks $url");
	$collections = array_map(function ($c) { return $c['name']; }, $overview['collections']);
	same(array('events', 'faq', 'journal', 'menu'), $collections);
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
test('N5', 'raster export: the site as static files', function () use ($tmp) {
	$out = "$tmp/static";
	list($code, $output) = raster(array('export', $out, '--url=https://cafe.example/'));
	same(0, $code, $output);
	has($output, 'linked to https://cafe.example/');
	has($output, 'Left out (they need an account): /members, /staff');
	has($output, 'The reservation.book form (/visit) needs the PHP site');
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
	$left = trim(shell_exec('grep -rl "127.0.0.1" '.escapeshellarg($out).' 2>/dev/null'));
	same('', $left, 'no local addresses left');
	same(1, raster(array('export', $out))[0], 'a folder with files needs --clean');
	list($code, $output) = raster(array('export', $out, '--clean', '--skip=/lab'));
	same(0, $code, $output);
	check(!file_exists("$out/lab/index.html"), '--skip');
	has(file_get_contents("$out/index.html"), 'href="/menu"', 'root-relative links without --url');
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
	has($body, '<tr><td>Flat white</td><td>14 lei</td></tr>');
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
test(array('C36', 'C37'), 'the log console, and strict templates off', function () use ($base, $db, $maildir, $views) {
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
