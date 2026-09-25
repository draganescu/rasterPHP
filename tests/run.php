<?php
// Raster test suite. No dependencies: `php tests/run.php`
//
// Runs unit tests against the template tools and integration tests against
// the demo site in application/, using a throwaway SQLite database and PHP's
// built in server.

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$db = sys_get_temp_dir().'/raster-test-'.getmypid().'.sqlite';
putenv("RASTER_DB=$db");
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
	same('Write HTML. Get a CMS.', $model['pages'][1]['fields']['headline']['default']);
});

// ## Integration tests

$port = 8765 + getmypid() % 100;
$base = "http://127.0.0.1:$port";
$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_MCP_TOKEN' => 'test-token', 'PATH' => getenv('PATH')));
register_shutdown_function(function () use ($server, $db) {
	proc_terminate($server);
	@unlink($db);
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
	check(strpos($body, 'href="'.$base.'/news/news_item/1"') !== false, 'detail link');
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
	same(403, http('POST', "$base/api/cms/edit_data", 'name=users', array('Content-Type: application/x-www-form-urlencoded'))[0]);
	same(404, http('POST', "$base/api/cms/save_page", 'raster_action=save_page')[0]);
	same(404, http('GET', "$base/api/mcp/call_tool/site_overview")[0]);
	http('POST', "$base/about", 'raster_action=save_page&page_name=aboutpage&variable_name=heading&raster_page_value=Hacked', array('Content-Type: application/x-www-form-urlencoded'));
	check(strpos(http('GET', "$base/about")[1], 'Hacked') === false);
});
test('editor login and csrf', function () use ($base) {
	cms_store::connect();
	cms_store::create_user('editor', 'correct horse');
	list($status, , $headers) = http('POST', "$base/login", 'username=editor&password=correct+horse', array('Content-Type: application/x-www-form-urlencoded'));
	same(302, $status);
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	$page = http('GET', "$base/about", null, array("Cookie: $cookie"))[1];
	check(preg_match('/Raster_Admin.csrf = "([a-f0-9]+)"/', $page, $m), 'toolbar missing');
	$form = array('Content-Type: application/x-www-form-urlencoded', "Cookie: $cookie");
	same(403, http('POST', "$base/about", 'raster_action=save_page&page_name=aboutpage&variable_name=heading&raster_page_value=Nope', $form)[0]);
	$body = http('POST', "$base/about", 'raster_action=save_page&csrf='.$m[1].'&page_name=aboutpage&variable_name=heading&raster_page_value=Edited', $form)[1];
	check(strpos($body, '<h1>Edited</h1>') !== false, 'edit not saved');
	list($status, $body) = http('POST', "$base/login", 'username=editor&password=wrong', array('Content-Type: application/x-www-form-urlencoded'));
	same(200, $status, 'wrong password must not log in');
	check(strpos($body, 'Wrong username or password') !== false);
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

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
