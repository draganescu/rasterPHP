<?php
// Raster test suite. No dependencies: `php tests/run.php`
//
// Runs unit tests against the template tools and integration tests against
// the demo site in application/, using a throwaway SQLite database and PHP's
// built in server.

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$db = sys_get_temp_dir().'/raster-test-'.getmypid().'.sqlite';
$maildir = sys_get_temp_dir().'/raster-mail-'.getmypid();
putenv("RASTER_DB=$db");
putenv("RASTER_MAIL=log://$maildir");
putenv('RASTER_ENV=development');

require_once $root.'/system/boot.php';
boot::$appname = 'application';
boot::cli();
require_once BASE.'tools/inspector.php';
require_once BASE.'tools/schema.php';
require_once BASE.'models/cms/cms.php';

$passed = 0; $failed = array(); $current = '';
function test($name, $fn) {
	global $passed, $failed, $current;
	$current = $name;
	try { $fn(); $passed++; echo "."; }
	catch (Throwable $e) { $failed[] = "$name: ".$e->getMessage(); echo "F"; }
}
function check($condition, $message = 'assertion failed') {
	if (!$condition) throw new Exception($message);
}
function same($expected, $actual, $message = '') {
	if ($expected !== $actual) throw new Exception(trim($message.' expected '.var_export($expected, true).', got '.var_export($actual, true)));
}
function lint_html($html) {
	$file = sys_get_temp_dir().'/raster-lint-'.getmypid().'.html';
	file_put_contents($file, $html);
	$inspector = new raster_inspector();
	$problems = $inspector->lint_path($file);
	unlink($file);
	return $problems;
}
function has_problem($problems, $needle, $line = null) {
	foreach ($problems as $p) {
		if (strpos($p['message'], $needle) !== false && ($line === null || $p['line'] === $line)) return true;
	}
	return false;
}

// ## Unit tests

test('parse_call without arguments', function () {
	same(array('latest', array()), template::parse_call('latest'));
});
test('parse_call with literal arguments', function () {
	same(array('latest', array(3, 'news', true, null, 1.5)), template::parse_call("latest(3, 'news', true, null, 1.5)"));
	same(array('f', array("it's")), template::parse_call("f('it\\'s')"));
});
test('parse_call negative numbers', function () {
	same(array('f', array(-1, -2.5)), template::parse_call('f(-1, -2.5)'));
	same(false, template::parse_call("f(-'a')"));
});
test('page types never collide', function () {
	$types = array_map(array('cms', 'page_type'), array('home', '/home', '/about', '/about-us', '/aboutus', '/about/us', '/news/news_item'));
	same(count($types), count(array_unique($types)));
	same('homepage', cms::page_type('home'));
	same('aboutpage', cms::page_type('/about'));
	same('home', cms::slug_for_uri('/index'));
});
test('parse_call rejects code', function () {
	same(false, template::parse_call('latest(system("id"))'));
	same(false, template::parse_call('latest($x)'));
	same(false, template::parse_call('a;b'));
	same(false, template::parse_call('latest(1,)'));
});
test('slugs', function () {
	same('home', cms::slug_for_uri('/'));
	same('/about', cms::slug_for_uri('/about/'));
	same('/news', cms::slug_for_uri('/news/news_page/2'));
	same('/news', cms::slug_for_uri('/news/news_items/tag/php'));
	same('/news/news_item', cms::slug_for_uri('/news/news_item/3'));
	same('aboutpage', cms::page_type('/about'));
	same('teammembersdata', cms::collection_type('team_members'));
});
test('lint: valid template has no problems', function () {
	same(array(), lint_html("<h1><!-- print.cms.title -->Hi<!-- /print.cms.title --></h1><!-- print.site.year /-->"));
});
test('lint: unclosed block with position', function () {
	$p = lint_html("<p>\n  <!-- print.cms.intro -->text</p>");
	check(has_problem($p, 'is never closed', 2), 'unclosed not reported on line 2');
	same(3, $p[0]['column']);
});
test('lint: spacing mistakes', function () {
	check(has_problem(lint_html('<!--print.cms.x-->a<!--/print.cms.x-->'), 'write it exactly as <!-- print.cms.x -->'));
});
test('lint: typos, unknown models and methods', function () {
	check(has_problem(lint_html('<!-- prnt.cms.x -->a<!-- /prnt.cms.x -->'), "did you mean 'print'"));
	check(has_problem(lint_html('<!-- print.ghost.x /-->'), "Model 'ghost' not found"));
	check(has_problem(lint_html('<!-- print.site.nope /-->'), "no public method 'nope'"));
	check(has_problem(lint_html('<!-- print.title /-->'), 'must name a model and a method'));
});
test('lint: arguments with spaces are checked', function () {
	check(has_problem(lint_html("<!-- print.nosuch.method(1, 'a b') /-->"), "Model 'nosuch' not found"));
	check(has_problem(lint_html("<!-- render.site.nav(1, 2) -->x"), 'is never closed'));
});
test('lint: reserved cms names', function () {
	check(has_problem(lint_html('<!-- print.cms.style -->x<!-- /print.cms.style -->'), 'reserved'));
	check(has_problem(lint_html('<!-- render.cms.users --><!-- print.username -->u<!-- /print.username --><!-- /render.cms.users -->'), 'reserved'));
	same(array(), lint_html('<link href="<!-- print.cms.style /-->">'));
});
test('lint: crossing blocks', function () {
	check(has_problem(lint_html('<!-- render.cms.a --><!-- remove --><!-- /render.cms.a --><!-- /remove -->'), 'blocks must nest'));
});
test('lint: attribute directives need the attribute', function () {
	check(has_problem(lint_html('<!-- render.cms.a --><!-- print.@href.link --><span>x</span><!-- /print.@href.link --><!-- /render.cms.a -->'), "no href="));
	same(array(), lint_html('<!-- render.cms.a --><!-- print.@href.link --><a href="#">x</a><!-- /print.@href.link --><!-- /render.cms.a -->'));
});
test('lint: filter links are built in', function () {
	same(array(), lint_html('<!-- render.cms.a --><!-- print.@href.raster_filter@author --><a href="#">x</a><!-- /print.@href.raster_filter@author --><!-- /render.cms.a -->'));
});
test('lint: dry sources must exist', function () {
	check(has_problem(lint_html('<!-- dry._layout.nothing /-->'), 'has no <!-- res.nothing -->'));
	same(array(), lint_html('<!-- dry._layout.head /-->'));
});
test('lint: demo theme is clean', function () {
	$inspector = new raster_inspector();
	same(array(), $inspector->lint());
});
test('content model from the demo theme', function () {
	$inspector = new raster_inspector();
	$model = $inspector->content_model();
	$pages = array();
	foreach ($model['pages'] as $page) $pages[$page['url']] = array_keys($page['fields']);
	same(array('title', 'headline', 'intro'), $pages['/']);
	same(array('title', 'heading', 'body'), $pages['/about']);
	same(array('headline', 'date', 'summary', 'body'), array_keys($model['collections']['news']['fields']));
	foreach ($model['pages'] as $page) if ($page['url'] === '/') same('Write HTML. Get a CMS.', $page['fields']['headline']['default']);
	same(array('site_name', 'site_footer'), $pages['site']);
});

// ## Integration tests

$port = 8765 + getmypid() % 100;
$base = "http://127.0.0.1:$port";
$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_MCP_TOKEN' => 'test-token', 'RASTER_MAIL' => "log://$maildir", 'PATH' => getenv('PATH')));
register_shutdown_function(function () use ($server, $db, $maildir) {
	proc_terminate($server);
	@unlink($db);
	array_map('unlink', glob("$maildir/*") ?: array());
	@rmdir($maildir);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

function http($method, $url, $body = null, $headers = array()) {
	$context = stream_context_create(array('http' => array(
		'method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
		'header' => implode("\r\n", $headers), 'content' => $body,
	)));
	$content = @file_get_contents($url, false, $context);
	preg_match('/\d{3}/', $http_response_header[0], $m);
	return array((int)$m[0], $content, $http_response_header);
}
function mcp_call($name, $arguments = array()) {
	global $base;
	list($status, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $arguments))), array('Authorization: Bearer test-token', 'Content-Type: application/json'));
	same(200, $status, 'mcp status');
	$response = json_decode($body, true);
	return $response['result'];
}

test('pages render', function () use ($base) {
	foreach (array('/', '/about', '/news', '/news/news_item/1', '/news/news_page/2', '/login') as $url) {
		list($status, $body) = http('GET', $base.$url);
		same(200, $status, $url);
		check(strpos($body, '<!-- print.') === false && strpos($body, '<!-- render.') === false, "$url leaks annotations");
	}
});
test('template defaults become content', function () use ($base) {
	list(, $body) = http('GET', "$base/");
	check(strpos($body, '<h1>Write HTML. Get a CMS.</h1>') !== false);
	check(strpos($body, 'Placeholder') === false, 'remove block leaked');
	check(strpos($body, 'href="'.$base.'/news/news_item/raster-runs-on-php-8"') !== false, 'detail link');
	check(strpos($body, 'href="'.$base.'/news"') !== false, '.html links rewritten');
});
test('missing pages and items are 404', function () use ($base) {
	same(404, http('GET', "$base/nothing-here")[0]);
	same(404, http('GET', "$base/news/news_item/999")[0]);
	same(404, http('GET', "$base/_layout")[0]);
});
test('collection urls only for views that render the collection', function () use ($base) {
	same(404, http('GET', "$base/about/zzz_page/9")[0]);
	same(404, http('GET', "$base/_layout/x_page")[0]);
	same(200, http('GET', "$base/news/news_page/1")[0]);
});
test('users collection is never rendered', function () {
	$cms = new cms();
	$r = new ReflectionMethod($cms, 'collection');
	$r->setAccessible(true);
	same(false, $r->invoke($cms, 'users', array()));
});
test('only application models and cms over /api', function () use ($base) {
	same(404, http('GET', "$base/api/pagination/paginate/api.load")[0]);
	same(404, http('GET', "$base/api/welcome/hello")[0]);
	same(200, http('GET', "$base/api/site/year")[0]);
});
test('host header is sanitized and loopback names need a local client', function () use ($base) {
	list($status, $body) = http('GET', "$base/", null, array("Host: evil.com'</script><script>alert(1)</script>"));
	check(strpos($body, '<script>alert(1)') === false, 'host reflected');
});
test('code and data are not served', function () use ($base) {
	foreach (array('/application//data/x.sqlite-journal', '/application/views/default/index.html', '/system/boot.php', '/application/config/db/development.php', '/application/data/raster.sqlite', '/bin/raster', '/.git/config', '/AGENTS.md') as $url) {
		same(403, http('GET', $base.$url)[0], $url);
	}
	same(200, http('GET', "$base/application/views/default/style.css")[0]);
});
test('visitors get no session cookie', function () use ($base) {
	list(, , $headers) = http('GET', "$base/");
	check(!preg_grep('/^Set-Cookie/i', $headers));
});
test('schema matches after rendering', function () {
	$schema = new raster_schema();
	$status = $schema->status();
	same(false, $status['drift']);
});
test('anonymous users cannot edit', function () use ($base) {
	same(403, http('POST', "$base/api/cms/editor_save_item", 'collection=users', array('Content-Type: application/x-www-form-urlencoded'))[0]);
	same(404, http('POST', "$base/api/cms/save_page", 'x=1')[0]);
	same(404, http('GET', "$base/api/mcp/call_tool/site_overview")[0]);
	http('POST', "$base/about", 'raster_action=save_page&page_name=aboutpage&variable_name=heading&raster_page_value=Hacked', array('Content-Type: application/x-www-form-urlencoded'));
	check(strpos(http('GET', "$base/about")[1], 'Hacked') === false);
});
test('editor login and csrf', function () use ($base) {
	cms_store::connect();
	cms_store::create_user('editor', 'correct horse');
	list($status, , $headers) = http('POST', "$base/login", 'login=editor&password=correct+horse', array('Content-Type: application/x-www-form-urlencoded'));
	same(303, $status);
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	$page = http('GET', "$base/about", null, array("Cookie: $cookie"))[1];
	check(preg_match('/"csrf":"([a-f0-9]+)"/', $page, $m), 'editor missing');
	$form = array('Content-Type: application/x-www-form-urlencoded', "Cookie: $cookie");
	same(403, http('POST', "$base/api/cms/editor_save_field", 'type=aboutpage&slug=/about&field=heading&value=Nope', $form)[0]);
	same(200, http('POST', "$base/api/cms/editor_save_field", 'type=aboutpage&slug=/about&field=heading&value=Edited&csrf='.$m[1], $form)[0]);
	check(strpos(http('GET', "$base/about")[1], '<h1>Edited</h1>') !== false, 'edit not saved');
	list($status, $body) = http('POST', "$base/login", 'login=editor&password=wrong', array('Content-Type: application/x-www-form-urlencoded'));
	same(200, $status, 'wrong password must not log in');
	check(strpos($body, 'Wrong email or password') !== false);
});
test('mcp requires a token', function () use ($base) {
	same(401, http('POST', "$base/mcp", '{}')[0]);
	same(401, http('POST', "$base/mcp", '{}', array('Authorization: Bearer wrong'))[0]);
});
test('mcp initialize and tools', function () use ($base) {
	list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array('protocolVersion' => '2025-06-18'))), array('Authorization: Bearer test-token'));
	$r = json_decode($body, true);
	same('2025-06-18', $r['result']['protocolVersion']);
	list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list')), array('Authorization: Bearer test-token'));
	check(count(json_decode($body, true)['result']['tools']) >= 10);
});
test('mcp edits pages with revisions', function () use ($base) {
	$overview = mcp_call('site_overview')['structuredContent'];
	check(count($overview['pages']) >= 3);
	$result = mcp_call('update_page', array('page' => '/', 'fields' => array('headline' => 'Edited by an agent')));
	same('Edited by an agent', $result['structuredContent']['fields']['headline']);
	check(strpos(http('GET', "$base/")[1], '<h1>Edited by an agent</h1>') !== false, 'page not updated');
	$history = mcp_call('page_history', array('page' => 'index'))['structuredContent']['revisions'];
	check(count($history) >= 2, 'no revision history');
	same('Write HTML. Get a CMS.', $history[1]['fields']['headline']);
});
test('mcp rejects fields that are not in templates', function () {
	$result = mcp_call('update_page', array('page' => '/about', 'fields' => array('made_up' => 'x')));
	check(!empty($result['isError']));
	check(strpos($result['content'][0]['text'], 'known fields: title, heading, body') !== false);
});
test('mcp collections', function () use ($base) {
	$item = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => 'Agents can publish', 'date' => '2026-09-26', 'summary' => '<p>Hi</p>', 'body' => '<p>Body</p>')))['structuredContent'];
	check(strpos(http('GET', "$base/news")[1], 'Agents can publish') !== false);
	list($status, $body) = http('GET', "$base/news/news_item/{$item['id']}");
	same(200, $status);
	check(strpos($body, '<h1>Agents can publish</h1>') !== false);
	mcp_call('update_item', array('collection' => 'news', 'id' => $item['id'], 'fields' => array('headline' => 'Renamed')));
	same('Renamed', mcp_call('get_item', array('collection' => 'news', 'id' => $item['id']))['structuredContent']['headline']);
	mcp_call('delete_item', array('collection' => 'news', 'id' => $item['id']));
	same(404, http('GET', "$base/news/news_item/{$item['id']}")[0]);
});
test('mcp lint and schema tools', function () {
	same(0, mcp_call('lint_templates')['structuredContent']['errors']);
	same(false, mcp_call('schema_status')['structuredContent']['drift']);
});
test('frozen database falls back to template defaults', function () use ($root, $db) {
	$view = "$root/application/views/default/about.html";
	$original = file_get_contents($view);
	file_put_contents($view, str_replace('</main>', '<p><!-- print.cms.frozen_test -->Fallback<!-- /print.cms.frozen_test --></p></main>', $original));
	try {
		$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db);
		$html = shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' render /about');
		check(strpos($html, '<p>Fallback</p>') !== false, 'frozen render failed');
		exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --check', $out, $code);
		same(1, $code, 'schema --check should report drift');
		exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --apply', $out, $code);
		exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --check', $out, $code);
		same(0, $code, 'schema --apply did not fix drift');
	} finally {
		file_put_contents($view, $original);
		exec('RASTER_DB='.escapeshellarg($db).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --drop=aboutpage.frozen_test');
	}
});
test('mcp over stdio', function () use ($root, $db) {
	$input = json_encode(array('jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array('name' => 'get_page', 'arguments' => array('page' => '/about'))))."\n";
	$process = proc_open(array(PHP_BINARY, "$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'PATH' => getenv('PATH')));
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$response = json_decode(stream_get_contents($pipes[1]), true);
	proc_close($process);
	same(7, $response['id']);
	same('/about', $response['result']['structuredContent']['url']);
});


// ## Forms, accounts, newsletter, feeds

function form_post($url, $fields, $headers = array()) {
	return http('POST', $url, http_build_query($fields), array_merge(array('Content-Type: application/x-www-form-urlencoded'), $headers));
}
function last_mail() {
	global $maildir;
	$files = glob("$maildir/*.eml");
	sort($files);
	if (!$files) return null;
	$raw = file_get_contents(end($files));
	$text = '';
	if (preg_match('/Content-Type: text\/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $raw, $m)) $text = base64_decode($m[1]);
	return array('raw' => $raw, 'text' => $text);
}
function location($headers) {
	foreach ($headers as $h) if (stripos($h, 'Location:') === 0) return trim(substr($h, 9));
	return null;
}

test('lint: form conventions', function () {
	check(has_problem(lint_html('<form method="post"><input name="a"></form>'), 'not inside a render block'));
	check(has_problem(lint_html("<!-- render.site.nav --><form method=\"post\"><input name=\"a\" required><!-- render.validation.field('b') -->x<!-- /render.validation.field('b') --></form><!-- /render.site.nav -->"), "no input named b"));
	check(has_problem(lint_html("<!-- print.validation.alert('nobody_raises_this') -->x<!-- /print.validation.alert('nobody_raises_this') -->"), 'No model raises'));
});
test('posts from other sites and bots are refused', function () use ($base) {
	same(403, form_post("$base/about", array('email' => 'a@b.co'), array('Origin: https://evil.example'))[0]);
	same(403, form_post("$base/about", array('email' => 'a@b.co'), array('Sec-Fetch-Site: cross-site'))[0]);
	same(303, form_post("$base/about", array('raster_hp' => 'spam', 'email' => 'a@b.co'))[0]);
	list(, $body) = http('GET', "$base/about");
	check(strpos($body, 'name="raster_form" value="newsletter.signup"') !== false, 'form owner not injected');
});
test('validation regions and redisplay', function () use ($base) {
	list($status, $body) = form_post("$base/register", array('raster_form' => 'authentication.register', 'email' => 'not-an-email', 'password' => 'short', 'password_again' => 'other'));
	same(200, $status);
	check(strpos($body, 'Enter a valid email address.') !== false, 'field error');
	check(strpos($body, 'Use at least 8 characters.') !== false, 'minlength error');
	check(strpos($body, 'The passwords are different.') !== false, 'matches error');
	check(strpos($body, 'value="not-an-email"') !== false, 'value kept');
	check(strpos($body, 'value="short"') === false, 'password refilled');
	check(strpos($body, 'Please enter a valid email address.') === false, 'other form validated');
});
test('accounts: register, protected pages, reset by email', function () use ($base) {
	list($status, , $headers) = http('GET', "$base/account");
	same(303, $status);
	check(strpos(location($headers), '/login?next=%2Faccount') !== false, 'login redirect');
	list($status, , $headers) = form_post("$base/register", array('raster_form' => 'authentication.register', 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse 1', 'password_again' => 'correct horse 1'));
	same(303, $status);
	check(strpos(location($headers), '/account?done=registered') !== false, 'after_login');
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	list(, $body) = http('GET', "$base/account?done=registered", null, array("Cookie: $cookie"));
	check(strpos($body, 'Welcome! Your account is ready.') !== false, 'registered alert');
	check(strpos($body, 'value="ada@example.com"') !== false, 'account form filled');
	check(strpos(http('GET', "$base/", null, array("Cookie: $cookie"))[1], '>Account</a>') !== false, 'if.logged_in');
	same(true, strpos(form_post("$base/register", array('raster_form' => 'authentication.register', 'email' => 'ada@example.com', 'password' => 'another pass 1', 'password_again' => 'another pass 1'))[1], 'already an account') !== false);

	same(303, form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'ada@example.com'))[0]);
	$mail = last_mail();
	check($mail && preg_match('/token=([a-f0-9]{48})/', $mail['text'], $t), 'reset email');
	check(strpos($mail['raw'], 'Subject: =?UTF-8?B?'.base64_encode('Reset your password').'?=') !== false, 'subject from <title>');
	same(303, form_post("$base/reset?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'brand new pass', 'password_again' => 'brand new pass'))[0]);
	check(strpos(http('GET', "$base/reset?token={$t[1]}")[1], 'expired or was already used') !== false, 'token single use');
	same(303, form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => 'ada@example.com', 'password' => 'brand new pass'))[0]);
	list($status, , $headers) = form_post("$base/login?next=//evil.example", array('raster_form' => 'authentication.login', 'login' => 'ada@example.com', 'password' => 'brand new pass'));
	check(strpos(location($headers), 'evil.example') === false, 'open redirect');
	// members don't get the editor toolbar
	check(strpos(http('GET', "$base/about", null, array("Cookie: $cookie"))[1], 'Raster_Admin') === false, 'member got toolbar');
});
test('newsletter: double opt-in, send, one-click unsubscribe', function () use ($base, $root, $db, $maildir) {
	list($status, , $headers) = form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'reader@example.com'));
	same(303, $status);
	check(strpos(location($headers), 'done=check_email') !== false);
	$mail = last_mail();
	check($mail && preg_match('/token=([a-f0-9]{40})/', $mail['text'], $t), 'confirmation email');
	check(strpos(http('GET', "$base/newsletter-confirm?token={$t[1]}")[1], 'You are subscribed') !== false, 'confirm');
	$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_MAIL='.escapeshellarg("log://$maildir").' RASTER_URL='.escapeshellarg("$base/");
	$out = shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' send /about --dry-run 2>&1');
	check(strpos($out, 'to 1 subscriber') !== false, "dry run: $out");
	$out = shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' send /about 2>&1');
	check(strpos($out, 'Sent "About" to 1 subscriber') !== false, "send: $out");
	$mail = last_mail();
	check(preg_match('/List-Unsubscribe: <([^>]+)>/', $mail['raw'], $u), 'List-Unsubscribe');
	check(strpos($mail['raw'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') !== false);
	$html = base64_decode(preg_replace('/.*Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--.*/s', '$1', $mail['raw']));
	check(strpos($html, '<form') === false && strpos($html, '<script') === false && strpos($html, '<base') === false, 'issue cleaned up');
	$again = shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' send /about 2>&1');
	check(strpos($again, 'already sent') !== false, 'double send guard');
	check(strpos(http('POST', $u[1], 'List-Unsubscribe=One-Click', array('Content-Type: application/x-www-form-urlencoded'))[1], 'You are unsubscribed') !== false, 'one-click');
});
test('collections: slugs, drafts, scheduling, order', function () use ($base) {
	$item = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => 'Ünïcode & Friends', 'summary' => '<p>x</p>')))['structuredContent'];
	same('unicode-friends', $item['slug']);
	same(200, http('GET', "$base/news/news_item/unicode-friends")[0]);
	mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => 'Secret draft', 'enabled' => '0')));
	mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => 'From the future', 'published_at' => '2099-01-01 10:00')));
	list(, $body) = http('GET', "$base/news");
	check(strpos($body, 'Secret draft') === false && strpos($body, 'From the future') === false, 'hidden items shown');
	same(404, http('GET', "$base/news/news_item/secret-draft")[0]);
	check(strpos($body, 'Ünïcode') < strpos($body, 'Raster runs on PHP 8'), 'order=newest');
});
test('feeds are well-formed and escaped', function () use ($base) {
	list($status, $body, $headers) = http('GET', "$base/news.rss");
	same(200, $status);
	check((bool)preg_grep('/^Content-Type: application\/rss\+xml/i', $headers), 'rss content type');
	$doc = new DOMDocument();
	check(@$doc->loadXML($body), 'rss is not well-formed XML');
	check(strpos($body, 'Ünïcode &amp; Friends') !== false, 'rss escaping');
	check(strpos($body, 'From the future') === false, 'future item in feed');
	list(, $body) = http('GET', "$base/sitemap.xml");
	check(@$doc->loadXML($body) && strpos($body, "<loc>$base/news/news_item/unicode-friends</loc>") !== false, 'sitemap');
	check(strpos($body, '/login') === false, 'sitemap lists login');
});
test('site-wide fields', function () use ($base) {
	mcp_call('update_page', array('page' => 'site', 'fields' => array('site_name' => 'Acme')));
	check(strpos(http('GET', "$base/")[1], '>Acme</a>') !== false && strpos(http('GET', "$base/news")[1], '>Acme</a>') !== false, 'site_name everywhere');
});
test('pagination', function () use ($base) {
	for ($i = 1; $i <= 11; $i++) mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => "Filler $i")));
	list(, $body) = http('GET', "$base/news");
	check(preg_match_all('/<a class="page ?\w*" href="[^"]*news_page\/2"/', $body) >= 1, 'page 2 link');
	list($status, $body) = http('GET', "$base/news/news_page/2");
	same(200, $status);
	check(strpos($body, 'class="page current"') !== false, 'current page');
});
test('i18n picks languages', function () {
	same(array('ro-RO', 'ro', 'en'), i18n::parse_header('ro-RO,ro;q=0.9,en;q=0.8'));
});
test('page cache in production', function () use ($root, $db) {
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db);
	shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --apply');
	$port = 8865 + getmypid() % 100;
	$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		$cache = function () use ($port) { return current(preg_grep('/^X-Raster-Cache/i', http('GET', "http://127.0.0.1:$port/about")[2])); };
		same('X-Raster-Cache: miss', $cache());
		same('X-Raster-Cache: hit', $cache());
		util::content_changed();
		same('X-Raster-Cache: miss', $cache());
	} finally {
		proc_terminate($server);
		foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	}
});

// ## Regressions from review

test('forged Host header never gets a reset link', function () use ($base, $maildir) {
	$before = count(glob("$maildir/*.eml") ?: array());
	form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'ada@example.com'), array('Host: evil.example'));
	$after = glob("$maildir/*.eml") ?: array();
	check(count($after) === $before || strpos(file_get_contents(end($after)), 'evil.example') === false, 'reset link used the forged host');
});
test('/api does not run form models', function () use ($base) {
	same(403, form_post("$base/api/cms/login", array('login' => 'editor', 'password' => 'correct horse'), array('Origin: https://evil.example'))[0]);
	list(, , $headers) = form_post("$base/api/cms/login", array('login' => 'editor', 'password' => 'correct horse'));
	check(!preg_grep('/^Set-Cookie/i', $headers), 'logged in through /api');
});
test('item URLs only under their collection', function () use ($base) {
	same(404, http('GET', "$base/zzz/news_item/raster-runs-on-php-8")[0]);
});
test('a password reset ends other sessions', function () use ($base, $maildir) {
	list(, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => 'ada@example.com', 'password' => 'brand new pass'));
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	check(strpos(http('GET', "$base/account", null, array("Cookie: $cookie"))[1], 'Logged in as') !== false, 'session A works');
	form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'ada@example.com'));
	preg_match('/token=([a-f0-9]{48})/', last_mail()['text'], $t);
	form_post("$base/reset?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'third password', 'password_again' => 'third password'));
	same(303, http('GET', "$base/account", null, array("Cookie: $cookie"))[0], 'old session still logged in');
});
test('feed links, raw views and json lists', function () use ($base, $root) {
	check(strpos(http('GET', "$base/")[1], 'href="'.$base.'/news.rss"') !== false, 'rss link rewritten');
	same(403, http('GET', "$base/application/views/default/news.rss")[0]);
	$view = "$root/application/views/default/zz_feed.json";
	file_put_contents($view, "[<!-- render.feed.items('news') -->{\"title\": \"<!-- print.headline -->x<!-- /print.headline -->\"}<!-- /render.feed.items('news') -->]");
	try {
		list($status, $body) = http('GET', "$base/zz_feed.json");
		same(200, $status);
		$list = json_decode($body, true);
		check(is_array($list) && count($list) > 1, "json list: $body");
	} finally {
		unlink($view);
	}
});
test('pagination follows filters', function () use ($base) {
	list(, $body) = http('GET', "$base/news/news_items/headline/Filler%201");
	check(strpos($body, 'news_page/2') === false, 'filtered list shows extra pages');
});
test('frozen database: accounts and newsletter after schema --apply', function () use ($root) {
	$db = sys_get_temp_dir().'/raster-frozen-'.getmypid().'.sqlite';
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' RASTER_URL=http://example.test/';
	$raster = escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster");
	try {
		exec("$env $raster schema --apply 2>&1", $out, $code);
		same(0, $code, implode("\n", $out));
		exec("$env $raster user admin@example.test --password=secret-pass 2>&1", $out, $code);
		same(0, $code, implode("\n", $out));
		$result = shell_exec("$env ".escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); authentication::connect(); echo authentication::check_login("admin@example.test", "secret-pass") ? "ok" : "fail";').' 2>&1');
		same('ok', trim($result));
		exec("$env $raster schema --check 2>&1", $out, $code);
		same(0, $code, 'drift after apply');
	} finally {
		@unlink($db);
	}
});

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
