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
	exec('rm -rf '.escapeshellarg($tmp).' '.escapeshellarg("$root/demo/data/cache").' '.escapeshellarg("$root/demo/data/mail"));
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
	return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/Raster_Admin.csrf = "([a-f0-9]+)"/', $html, $m) ? $m[1] : null);
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
test(array('A9', 'B6'), 'links are rewritten', function () use ($base) {
	$body = http('GET', "$base/about")[1];
	has($body, 'href="'.$base.'/menu"');
	has($body, 'href="'.$base.'/"');
	has($body, 'href="'.$base.'/journal.rss"');
	has($body, 'href="'.$base.'/feed.json"');
	has($body, 'href="'.$base.'/docs/setup"');
});
test(array('A10', 'A11'), 'assets are served, code and raw views are not', function () use ($base) {
	same(200, http('GET', "$base/demo/views/cafe/style.css")[0]);
	same(200, http('GET', "$base/demo/views/cafe/img/logo.svg")[0]);
	foreach (array('/demo/views/cafe/index.html', '/demo/views/cafe/journal.rss', '/demo/views/cafe/feed.json', '/demo/config/the_app.php', '/demo/models/cafe/cafe.php', '/demo/i18n/ro/common.php', '/demo/data/x.sqlite', '/demo//data/x.sqlite-journal', '/system/boot.php', '/bin/raster', '/.git/HEAD', '/AGENTS.md', '/docs/rto-spec.md', '/tests/demo.php') as $path) {
		same(403, http('GET', $base.$path)[0], $path);
	}
});
test('A12', 'query strings do not change the route', function () use ($base) {
	has(http('GET', "$base/about?utm=x")[1], '<h1>About us</h1>');
});

// ## B. Formats

test('B1', 'RSS', function () use ($base) {
	http('GET', "$base/journal");
	mcp($base, 'create_item', array('collection' => 'journal', 'fields' => array('title' => 'Tea & <cake>', 'summary' => '<p>Cups & saucers</p>', 'author' => 'Bogdan', 'body' => '<p>Long</p>')));
	list($status, $body, $headers) = http('GET', "$base/journal.rss");
	same(200, $status);
	has(header_value($headers, 'Content-Type'), 'application/rss+xml');
	$doc = new DOMDocument();
	check(@$doc->loadXML($body), 'RSS is not well-formed');
	has($body, 'Tea &amp; &lt;cake&gt;');
	same(2, $doc->getElementsByTagName('item')->length);
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
	lacks(http('GET', "$base/about")[1], 'Raster Café.</p>', 'replace is scoped to /lab');
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
});
test('D4', 'bots that fill the honeypot get a fake success', function () use ($base) {
	$before = count(mails());
	list($status, , $headers) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'raster_hp' => 'buy now', 'email' => 'bot@spam.test', 'message' => 'spam'));
	same(303, $status);
	same($before, count(mails()), 'nothing sent');
});
$bad_booking = array('raster_form' => 'reservation.book', 'name' => 'Your name', 'email' => 'nope', 'phone' => 'abc', 'date' => '2031-01-01', 'guests' => '12', 'seating' => 'terrace', 'occasion' => 'birthday', 'newsletter' => 'yes', 'notes' => str_repeat('x', 301), 'password' => 'secret');
test(array('D5', 'D6', 'D7', 'D8', 'D9', 'D10', 'D11', 'D12', 'C19'), 'rules from the HTML, and regions', function () use ($base, $bad_booking) {
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
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'A', 'email' => '', 'date' => ''));
	has($body, 'Tell us your name (2 to 80 letters).', 'minlength');
	has($body, 'We need a valid email to confirm.', 'required');
});
test('D13', 'an application rule', function () use ($base) {
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.book', 'name' => 'Ana', 'email' => 'ana@example.com', 'date' => '2026-10-05', 'guests' => '2', 'terms' => '1'));
	has($body, 'We are closed on Mondays.');
});
test(array('D14', 'D19'), 'rule names from older Raster, two forms on one page', function () use ($base) {
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => '', 'message' => 'Hi'));
	has($body, 'Your email, please.');
	lacks($body, 'That email does not look right.', 'email_format passes on empty');
	lacks($body, 'We need a valid email to confirm.', 'the other form stays quiet');
	lacks($body, 'Please enter a valid email address.', 'the footer form stays quiet');
	list(, $body) = http('POST', "$base/visit", array('raster_form' => 'reservation.contact', 'email' => 'bad', 'message' => 'Hi'));
	has($body, 'That email does not look right.');
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
	http('GET', "$base/newsletter-confirm?token={$t[1]}");
	database::instance('cms');
	$token = R::findOne('subscriber', ' email = ? ', array('reader@example.com'))->token;
	$body = http('GET', "$base/newsletter-unsubscribe?token=$token")[1];
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
test(array('E8', 'K1'), 'pagination for collections and for models', function () use ($base) {
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
	check(cookie_from(http('GET', "$base/")[2]) === '', 'visitor got a cookie');
	list($status, , $headers) = http('GET', "$base/members");
	same(303, $status);
	same("$base/login?next=%2Fmembers", header_value($headers, 'Location'));
});
test(array('G1', 'G2', 'G3', 'G9', 'D15'), 'sign up', function () use ($base) {
	list(, $body) = http('POST', "$base/register", array('raster_form' => 'authentication.register', 'email' => 'maria@example.com', 'password' => 'short', 'password_again' => 'other'));
	has($body, 'Use at least 8 characters.');
	has($body, 'The passwords are different.');
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
	same(303, http('POST', "$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'maria@example.com'))[0]);
	$mail = last_mail();
	same('Reset your Raster Café password', $mail['subject']);
	has($mail['html'], 'src="'.$base.'/demo/views/cafe/img/logo.svg"', 'relative images become absolute');
	check(preg_match('/token=([a-f0-9]{48})/', $mail['text'], $t), 'reset link');
	same(303, http('POST', "$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'nobody@example.com'))[0], 'same answer for unknown emails');
	has(http('GET', "$base/reset?token=0000")[1], 'This link has expired or was already used.');
	has(http('POST', "$base/reset?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'reset password', 'password_again' => 'nope'))[1], 'The passwords are different.');
	list($status, , $headers) = http('POST', "$base/reset?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'reset password', 'password_again' => 'reset password'));
	same("$base/members?done=password_changed", header_value($headers, 'Location'));
	has(http('GET', "$base/reset?token={$t[1]}")[1], 'This link has expired or was already used.');
	login($base, 'maria@example.com', 'reset password');
});
test('G14', 'five wrong passwords lock the account', function () use ($base) {
	raster(array('user', 'locked@cafe.test', '--role=member', '--password=right password'));
	for ($i = 0; $i < 5; $i++) http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'locked@cafe.test', 'password' => 'wrong'));
	has(http('POST', "$base/login", array('raster_form' => 'authentication.login', 'login' => 'locked@cafe.test', 'password' => 'right password'))[1], 'Wrong email or password.');
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

test(array('E17', 'E18', 'E19', 'E20'), 'the editor toolbar and its endpoints', function () use ($base) {
	$member = login($base, 'maria@example.com', 'reset password');
	lacks(http('GET', "$base/about", null, array("Cookie: $member"))[1], 'Raster_Admin', 'members get no toolbar');
	same(403, http('POST', "$base/api/cms/edit_data", array('name' => 'menu'), array("Cookie: $member"))[0]);
	same(403, http('POST', "$base/api/cms/edit_data", array('name' => 'menu'))[0]);
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$page = http('GET', "$base/about", null, array("Cookie: $staff"))[1];
	has($page, 'Raster_Admin.page_variables');
	$token = token_in($page);
	$h = array("Cookie: $staff");
	has(http('POST', "$base/api/cms/edit_variable", array('page' => 'aboutpage', 'name' => 'heading', 'csrf' => $token), $h)[1], 'name="raster_action" value="save_page"');
	has(http('POST', "$base/api/cms/edit_data", array('name' => 'menu', 'csrf' => $token), $h)[1], 'data_editor');
	has(http('POST', "$base/api/cms/edit_item", array('name' => 'menu', 'did' => 1, 'csrf' => $token), $h)[1], 'name="raster_action" value="save_data"');
	has(http('POST', "$base/api/cms/add_item", array('name' => 'menu', 'csrf' => $token), $h)[1], 'name="raster_action" value="add_data"');
	has(http('POST', "$base/about", array('raster_action' => 'save_page', 'variable_name' => 'heading', 'raster_page_value' => 'Edited in the page', 'csrf' => $token), $h)[1], '<h1>Edited in the page</h1>');
	http('POST', "$base/menu", array('raster_action' => 'add_data', 'data_name' => 'menu', 'name' => 'Zebra cake', 'description' => 'Stripes', 'price' => '12', 'category' => 'cakes', 'csrf' => $token), $h);
	$item = null;
	foreach (mcp($base, 'list_items', array('collection' => 'menu', 'limit' => 100))['items'] as $row) if ($row['name'] === 'Zebra cake') $item = $row;
	check($item, 'add_data');
	http('POST', "$base/menu", array('raster_action' => 'save_data', 'data_name' => 'menu', 'data_id' => $item['id'], 'name' => 'Zebra torte', 'description' => 'Stripes', 'price' => '13', 'category' => 'cakes', 'csrf' => $token), $h);
	same('Zebra torte', mcp($base, 'get_item', array('collection' => 'menu', 'id' => $item['id']))['name']);
	has(http('POST', "$base/api/cms/remove_item", array('name' => 'menu', 'did' => $item['id'], 'csrf' => $token), $h)[1], '"removed":true');
	same(404, http('GET', "$base/menu/menu_item/{$item['id']}")[0]);
	mcp($base, 'update_page', array('page' => 'about', 'fields' => array('heading' => 'About us')));
});
test('E12', 'editors see drafts', function () use ($base) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	has(http('GET', "$base/events", null, array("Cookie: $staff"))[1], 'Secret tasting');
});
test('E21', 'media upload and crop', function () use ($base, $root) {
	$staff = login($base, 'staff@cafe.test', 'staff password');
	$token = token_in(http('GET', "$base/about", null, array("Cookie: $staff"))[1]);
	$image = imagecreatetruecolor(40, 30);
	imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 60));
	ob_start(); imagepng($image); $png = ob_get_clean();
	$boundary = 'raster'.bin2hex(random_bytes(4));
	$body = "--$boundary\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n"
		."--$boundary\r\nContent-Disposition: form-data; name=\"img\"; filename=\"cake.png\"\r\nContent-Type: image/png\r\n\r\n$png\r\n--$boundary--\r\n";
	list($status, $response) = http('POST', "$base/api/cms/upload_media", $body, array("Cookie: $staff", "Content-Type: multipart/form-data; boundary=$boundary"));
	$upload = json_decode($response, true);
	same('success', $upload['status'], $response);
	same(40, $upload['width']);
	check(preg_match('#/media/[a-f0-9]{16}\.png$#', $upload['url']), 'random file name');
	$fake = "--$boundary\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n--$boundary\r\nContent-Disposition: form-data; name=\"img\"; filename=\"shell.png\"\r\nContent-Type: image/png\r\n\r\n<?php echo 1;\r\n--$boundary--\r\n";
	same('error', json_decode(http('POST', "$base/api/cms/upload_media", $fake, array("Cookie: $staff", "Content-Type: multipart/form-data; boundary=$boundary"))[1], true)['status']);
	$crop = json_decode(http('POST', "$base/api/cms/crop_media", array('csrf' => $token, 'imgUrl' => $upload['url'], 'imgInitW' => 40, 'imgInitH' => 30, 'imgW' => 40, 'imgH' => 30, 'imgX1' => 5, 'imgY1' => 5, 'cropW' => 20, 'cropH' => 10), array("Cookie: $staff"))[1], true);
	same('success', $crop['status']);
	same(array(20, 10), array_slice(getimagesize($root.'/media/'.basename($crop['url'])), 0, 2));
	same('error', json_decode(http('POST', "$base/api/cms/crop_media", array('csrf' => $token, 'imgUrl' => 'file:///etc/passwd'), array("Cookie: $staff"))[1], true)['status']);
});

// ## H. Newsletter

test(array('H1', 'H2', 'H3', 'H4', 'H5'), 'double opt-in', function () use ($base) {
	list($status, , $headers) = http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => 'fan@example.com'));
	same("$base/journal?done=check_email", header_value($headers, 'Location'));
	has(http('GET', "$base/journal?done=check_email")[1], 'Almost there: check your inbox to confirm.');
	$mail = last_mail();
	same('Confirm your Raster Café letters', $mail['subject']);
	preg_match('/token=([a-f0-9]{40})/', $mail['text'], $t);
	$count = count(mails());
	same("$base/journal?done=check_email", header_value(http('POST', "$base/journal", array('raster_form' => 'newsletter.signup', 'email' => 'reader@example.com'))[2], 'Location'), 'the same answer for subscribers');
	same($count, count(mails()), 'no mail to people already subscribed');
	has(http('GET', "$base/newsletter-confirm?token={$t[1]}")[1], 'Letters go to fan@example.com.');
	has(http('GET', "$base/newsletter-confirm?token=".str_repeat('a', 40))[1], 'This link is not valid');
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
	check(preg_match('#href="'.preg_quote($base, '#').'/newsletter-unsubscribe\?token=[a-f0-9]{40}"#', $issue['html']), 'per-reader unsubscribe link');
	check($sent[0]['html'] !== $sent[1]['html'], 'each reader has their own link');
	has(raster(array('send', '/journal/journal_item/opening-day'), array('RASTER_URL' => "$base/"))[1], 'was already sent');
	same(0, raster(array('send', '/journal/journal_item/opening-day', '--again', '--dry-run'), array('RASTER_URL' => "$base/"))[0]);
});
test(array('H7', 'H8'), 'unsubscribing', function () use ($base) {
	$issue = last_mail();
	check(preg_match('/^List-Unsubscribe: <([^>]+)>/m', $issue['raw'], $u), 'List-Unsubscribe');
	has($issue['raw'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click');
	$page = http('GET', $u[1])[1];
	has($page, '<button>Unsubscribe</button>');
	has(http('POST', $u[1], 'List-Unsubscribe=One-Click', array('Content-Type: application/x-www-form-urlencoded'))[1], 'You are unsubscribed');
	has(http('GET', $u[1])[1], 'You are unsubscribed', 'stays unsubscribed');
	has(http('GET', "$base/newsletter-unsubscribe?token=".str_repeat('b', 40))[1], 'This link is not valid');
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

test(array('I3', 'I4'), 'SMTP', function () use ($root, $tmp) {
	$port = free_port();
	$transcript = "$tmp/smtp.log";
	$smtp = proc_open(array(PHP_BINARY, "$root/tests/fake_smtp.php", (string)$port, $transcript), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	try {
		$send = function ($dsn) use ($root) {
			$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "demo"; boot::cli();'
				.' echo mail::send_view("_email/contact", "owner@cafe.test", array("email" => "a@b.co", "message" => "Hi\n.\nDot line")) ? "sent" : "failed: ".mail::$last_error;';
			return shell_exec('RASTER_MAIL='.escapeshellarg($dsn).' RASTER_MAIL_FROM='.escapeshellarg('Café <hello@cafe.test>').' '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1');
		};
		same('sent', $send("smtp://user:pass@127.0.0.1:$port"), 'localhost may skip TLS');
		$log = file_get_contents($transcript);
		has($log, 'C: AUTH LOGIN');
		has($log, 'C: '.base64_encode('user'));
		has($log, 'C: MAIL FROM:<hello@cafe.test>');
		has($log, 'C: RCPT TO:<owner@cafe.test>');
		has($log, 'From: Café <hello@cafe.test>');
		has($send("smtp://user:pass@127.0.0.2:$port"), 'does not offer STARTTLS');
		same('sent', $send("smtp://user:pass@127.0.0.2:$port?insecure=1"));
	} finally {
		proc_terminate($smtp);
	}
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
	has(implode("\n", $headers), 'Set-Cookie: lang=ro');
	has(http('GET', "$base/lab", null, array('Cookie: lang=ro'))[1], '>Meniu</a>');
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
	same(array('events', 'journal', 'menu'), $collections);
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
	list($code, $out) = raster(array('schema', '--json'));
	same(0, $code, $out);
	$status = json_decode($out, true);
	same(false, $status['drift'], $out);
	$original = file_get_contents("$views/about.html");
	with_file("$views/about.html", str_replace(array('print.cms.heading', '/print.cms.heading'), array('print.cms.title', '/print.cms.title'), $original), function () use ($base) {
		http('GET', "$base/about");
		same(1, raster(array('schema', '--check'))[0], 'drift after renaming');
		list(, $out) = raster(array('schema'));
		has($out, '--rename=aboutpage.heading:title');
		same(0, raster(array('schema', '--rename=aboutpage.heading:title'))[0]);
		has(http('GET', "$base/about")[1], '<h1>About us</h1>');
	});
	raster(array('schema', '--rename=aboutpage.title:heading', '--drop=aboutpage.title'));
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
	has(raster(array('lint', '--all-themes'))[1], "theme 'cafe'");
	list($code, $out) = raster(array('render', '/about'));
	same(0, $code);
	has($out, '<h1>About us</h1>');
	same(1, raster(array('render', '/nope'))[0]);
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
	same('miss', header_value(http('GET', "$prod/about", null, array('Cookie: lang=ro'))[2], 'X-Raster-Cache'), 'one copy per language');
	has(http('GET', "$prod/about", null, array('Cookie: lang=ro'))[1], '>Meniu</a>');
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

// ## No PHP warnings, notices or deprecations on any request

$log = is_file("$tmp/php-errors.log") ? file_get_contents("$tmp/php-errors.log") : '';
if (preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $log, $problems)) {
	$failed[] = 'PHP reported problems while serving pages:'."\n    ".implode("\n    ", array_unique($problems[0]));
}

// ## Coverage: every feature in demo/README.md has a passing test

$readme = file_get_contents("$root/demo/README.md");
preg_match_all('/^\| ([A-Z]\d+) \|/m', $readme, $ids);
$missing = array_diff($ids[1], array_keys($covered));
echo "\n\n$passed passed, ".count($failed)." failed; ".(count($ids[1]) - count($missing))."/".count($ids[1])." features covered\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
if ($missing) echo "  Not covered: ".implode(', ', $missing)."\n";
exit($failed || $missing ? 1 : 0);
