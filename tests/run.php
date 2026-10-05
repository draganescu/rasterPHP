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
require_once BASE.'tools/project.php';
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
// a port nobody is listening on, so test runs in parallel never collide
function free_port() {
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	return (int)substr($name, strrpos($name, ':') + 1);
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
class test_rows {
	function rows() {
		return array(array('photo' => 'new.jpg', 'link' => 'x\3 onmouseover=alert(1) x=\3', 'gone' => false, 'more' => 'b$1'));
	}
}
test('attributes in render rows change only the wrapped tag, and only that attribute', function () {
	$file = sys_get_temp_dir().'/raster-rows-'.getmypid().'.html';
	file_put_contents($file, '<!-- render.test_rows.rows -->'
		.'<!-- print.@src.photo --><img srcset="a.jpg 2x" data-src="a.jpg" src="a.jpg"><!-- /print.@src.photo -->'
		.'<!-- print.@href.link --><a href="#"><img src="keep.jpg"></a><!-- /print.@href.link -->'
		.'<!-- print.@title.gone --><b title="x" id="g">g</b><!-- /print.@title.gone -->'
		.'<!-- print.+class.more --><i class="a">i</i><!-- /print.+class.more -->'
		.'<!-- /render.test_rows.rows -->');
	controller::instance()->objects['test_rows'] = new test_rows();
	try {
		$html = controller::render_view($file);
	} finally {
		unset(controller::instance()->objects['test_rows']);
		unlink($file);
	}
	check(strpos($html, 'srcset="a.jpg 2x"') !== false, "srcset kept: $html");
	check(preg_match('/data-src="[^"]*a\.jpg"/', $html) && preg_match('/ src="[^"]*new\.jpg"/', $html), "only src set: $html");
	check(strpos($html, 'keep.jpg') !== false, "nested tag left alone: $html");
	// a backslash in the value is text, not a backreference that closes the attribute
	check(preg_match('#<a href="[^"]*x\\\\3 onmouseover=alert\(1\) x=\\\\3">#', $html), "attribute breakout: $html");
	check(strpos($html, '<b id="g">') !== false, "false removes the attribute: $html");
	check(strpos($html, 'class="a b$1"') !== false, "append: $html");
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

test('a page request loads no command line tools and looks up no core overrides', function () use ($root) {
	$dir = sys_get_temp_dir().'/raster-loads-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php $GLOBALS["looked_up"] = array(); spl_autoload_register(function ($c) { $GLOBALS["looked_up"][] = $c; }, true, true);'
		.' register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(array("looked_up" => array_values(array_unique($GLOBALS["looked_up"])), "included" => get_included_files()))); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg("$dir/db.sqlite");
	shell_exec("$env ".escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /about 2>&1');
	$out = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	check(is_array($out), 'no report from the render');
	same(array(), array_values(array_filter($out['included'], function ($f) { return strpos($f, '/system/tools/') !== false; })), 'tools loaded');
	same(array(), array_values(array_intersect($out['looked_up'], array('the_config', 'the_log', 'the_template', 'the_controller', 'the_database'))), 'autoloader asked');
});

// ## Integration tests

$port = free_port();
$base = "http://127.0.0.1:$port";
$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_MCP_TOKEN' => 'test-token', 'RASTER_MAIL' => "log://$maildir", 'PATH' => getenv('PATH')));
register_shutdown_function(function () use ($server, $db, $maildir) {
	proc_terminate($server);
	array_map('unlink', glob("$db*") ?: array());
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
	$port = free_port();
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

test('a production page reads the schema and each page row once, and counts nothing', function () use ($root, $db) {
	$dir = sys_get_temp_dir().'/raster-queries-'.getmypid();
	@mkdir($dir);
	// logging starts when the CMS starts the page (cms_editor), once the
	// database is set up and before any query
	file_put_contents("$dir/prepend.php", '<?php spl_autoload_register(function ($c) { if ($c === "cms_editor" && class_exists("R", false)) R::startLogging(); }, true, true);'
		.' register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(class_exists("R", false) ? R::getLogs() : array())); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db);
	shell_exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --apply');
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	$html = shell_exec("$env ".escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /');
	$logs = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	check(strpos((string)$html, '</html>') !== false, 'the page did not render');
	check(is_array($logs) && $logs, 'no queries logged');
	$queries = array_values(array_filter($logs, function ($l) { return is_string($l) && preg_match('/^\s*(SELECT|PRAGMA|INSERT|UPDATE|DELETE)/i', $l); }));
	$repeated = array_keys(array_filter(array_count_values(preg_grep('/sqlite_master|PRAGMA/i', $queries)), function ($n) { return $n > 1; }));
	same(array(), $repeated, 'schema read twice');
	same(array(), array_values(preg_grep('/count\(/i', $queries)), 'lists counted');
	same(array(), array_keys(array_filter(array_count_values(preg_grep('/FROM `?[a-z0-9]+page`?/i', $queries)), function ($n) { return $n > 1; })), 'a page row read twice');
	$pdo = new PDO("sqlite:$db");
	same('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
});
test('a site under a path: RASTER_URL with a folder', function () use ($root, $db) {
	$port = free_port();
	$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_URL' => "http://127.0.0.1:$port/preview/abc/", 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		$base = "http://127.0.0.1:$port/preview/abc";
		list($status, $body) = http('GET', "$base/about");
		same(200, $status, 'page under the folder');
		check(strpos($body, "href=\"$base/about\"") !== false, 'links carry the folder');
		check(!preg_match('#(href|src|action)=["\'](http://127\.0\.0\.1:'.$port.')?/(?!preview/abc/)#', $body), 'no link escapes the folder');
		list($status, , $headers) = http('GET', $base.'?x=1');
		same(301, $status, 'the folder without its slash');
		check((bool)preg_grep('#^Location: /preview/abc/\?x=1$#i', $headers), 'redirects into the folder');
		$css = "$base/application/views/default/style.css";
		list($status, $body, $headers) = http('GET', $css);
		same(200, $status, 'static file under the folder');
		check((bool)preg_grep('#^Content-Type: text/css#i', $headers), 'css type');
		$modified = current(preg_grep('#^Last-Modified:#i', $headers));
		check((bool)$modified, 'last modified');
		same(304, http('GET', $css, null, array('If-Modified-Since: '.trim(substr($modified, 14))))[0], 'not modified');
		list($status, $part, $headers) = http('GET', $css, null, array('Range: bytes=0-9'));
		same(206, $status, 'range');
		same(substr($body, 0, 10), $part);
		check((bool)preg_grep('#^Content-Range: bytes 0-9/'.strlen($body).'$#i', $headers), 'content range');
		same(403, http('GET', "$base/application/config/the_app.php")[0], 'private paths still refused');
		same(403, http('GET', "$base/system/boot.php")[0]);
	} finally {
		proc_terminate($server);
	}
});

test('raster serve passes site_url with a folder to the router', function () use ($root, $db) {
	$port = free_port();
	$config = "$root/application/config/the_app.php";
	$original = file_get_contents($config);
	file_put_contents($config, $original."\nconfig::set('site_url')->to('http://127.0.0.1:$port/shop/');\n");
	$env = array('RASTER_DB' => $db, 'PATH' => getenv('PATH'));
	$server = proc_open(array(PHP_BINARY, "$root/bin/raster", 'serve', "--host=127.0.0.1", "--port=$port"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	try {
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
		same(200, http('GET', "http://127.0.0.1:$port/shop/about")[0], 'page under the folder');
		same(200, http('GET', "http://127.0.0.1:$port/shop/application/views/default/style.css")[0], 'static file under the folder');
	} finally {
		file_put_contents($config, $original);
		// serve starts php -S through a shell, so the server is found by its
		// address ([-] keeps the pattern from matching pkill's own shell)
		proc_terminate($server);
		exec('pkill -f '.escapeshellarg("[-]S 127.0.0.1:$port"));
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
		array_map('unlink', glob("$db*") ?: array());
	}
});

// ## What is never served: one list, three servers

test('private_paths: the rules, and the apps they name', function () use ($root) {
	require_once BASE.'private_paths.php';
	$apps = private_paths::apps($root);
	check(in_array('application', $apps), 'application is an app folder');
	check(!in_array('system', $apps) && !in_array('media', $apps), 'system/ and media/ are not, though system/ has a config/');
	foreach (array('/system/boot.php', '/bin/raster', '/tests/run.php', '/.git/HEAD', '/composer.phar',
		'/application/config/the_app.php', '/application/models/x.php', '/application/data/raster.sqlite',
		'/application/data/raster.sqlite-wal', '/application/i18n/ro/common.php',
		'/application/views/default/index.html', '/application/views/default/news.rss', '/README.md') as $path) {
		check(private_paths::blocked($path), "$path must be private");
	}
	foreach (array('/index.php', '/', '/about', '/application/views/default/style.css', '/media/x.jpg') as $path) {
		check(!private_paths::blocked($path), "$path must be served");
	}
	// no rule names an app folder, so a site's folders can be called anything
	check(private_paths::blocked('/anything/config/x.txt'), 'config/ under any folder is private');
	check(private_paths::blocked('/anything/views/page.html'), 'and so is a raw view');
	// case never gets around a rule (macOS and Windows disks ignore it)
	foreach (array('/SYSTEM/boot.php', '/application/DATA/raster.SQLITE', '/Composer.PHAR', '/application/Config/x') as $path) {
		check(private_paths::blocked($path), "$path must be private");
	}
	// .well-known is the one public dot folder: certificates, security.txt
	check(!private_paths::blocked('/.well-known/acme-challenge/abc'), '.well-known is public');
	check(!private_paths::blocked('/.well-known'), 'and the folder itself');
	check(private_paths::blocked('/.well-known/.env'), 'but not a dotfile inside it');
	check(private_paths::blocked('/.well-known/x.php'), 'nor code');
	check(private_paths::blocked('/.well-knownx/y'), 'nor a folder that only starts like it');
});
test('private_paths: every server config carries every rule', function () use ($root) {
	require_once BASE.'private_paths.php';
	foreach (private_paths::servers() as $server) {
		$config = private_paths::config($server, array('host' => 'x.example', 'root' => '/srv/x'));
		check(strlen($config) > 100, "$server config is empty");
		foreach (private_paths::rules() as $rule) {
			check(strpos($config, $rule['why']) !== false, "$server does not mention rule '{$rule['id']}'");
		}
		check(strpos($config, 'phar') !== false, "$server does not refuse .phar");
		check(strpos($config, 'well-known') !== false, "$server does not keep .well-known public");
	}
	// the .htaccess in the repository is what the generator prints
	same(trim(private_paths::config('apache')), trim(file_get_contents("$root/.htaccess")));
	same(array(), raster_project::htaccess_gaps($root));
	foreach (private_paths::rules() as $rule) {
		foreach ($rule['apache'] as $line) check(strpos($line, '[F,L,NC]') !== false, "{$rule['id']} ignores case on Apache");
	}
	// a rule that is only in a comment is not in force
	$tmp = sys_get_temp_dir().'/raster-htaccess-'.getmypid();
	@mkdir($tmp);
	file_put_contents("$tmp/.htaccess", preg_replace('/^RewriteRule \(\^\|\/\)/m', '# $0', private_paths::config('apache')));
	same(array('dotfiles'), raster_project::htaccess_gaps($tmp), 'a commented-out rule is missing');
	unlink("$tmp/.htaccess"); rmdir($tmp);
	$missing = 0;
	try { private_paths::config('iis'); } catch (InvalidArgumentException $e) { $missing = 1; }
	same(1, $missing, 'an unknown server is an error');
});

// ## Closing tags carry the full name; lint repairs short ones

// the structural problems only: no model is looked up
function structure($html) {
	return raster_inspector::blocks($html)[1];
}
test('a short closing tag is an error lint can repair', function () {
	$fix = function ($html) {
		$problems = structure($html);
		check(count($problems) === 1, "one problem in $html, got ".count($problems));
		check(strpos($problems[0]['message'], 'needs the full name') !== false, $problems[0]['message']);
		check(isset($problems[0]['fix']), 'and it carries a fix');
		return $problems[0]['fix']['replacement'];
	};
	same('<!-- /render.a.b -->', $fix('<!-- render.a.b -->x<!-- /render -->'));
	same("<!-- /render.cms.menu('order=name') -->", $fix("<!-- render.cms.menu('order=name') -->x<!-- /render.cms.menu -->"));
	same('<!-- /print.@src.cms.photo -->', $fix('<!-- print.@src.cms.photo --><img src="a.jpg"><!-- /print -->'));
	same('<!-- /res.head -->', $fix('<!-- res.head -->x<!-- /res -->'));
	// an argument may contain a parenthesis; the fix is the opening's name exactly
	$problems = structure("<!-- render.a.list --><!-- render.b.x('a)b') -->X<!-- /render --><!-- /render -->");
	same(2, count($problems));
	same("<!-- /render.b.x('a)b') -->", $problems[0]['fix']['replacement'], 'the inner one first');
	same('<!-- /render.a.list -->', $problems[1]['fix']['replacement']);
	// what needs a decision gets no fix
	$problems = structure('<!-- render.a.b --><!-- print.x -->y<!-- /render -->');
	check(!empty($problems) && !isset($problems[0]['fix']), 'a short tag that would cross another block');
	$problems = structure('<!-- /render -->');
	check(count($problems) === 1 && strpos($problems[0]['message'], 'never opened') !== false, 'a stray closing tag');
	check(!isset($problems[0]['fix']));
	same(array(), structure("<!-- render.cms.menu('order=name') -->x<!-- /render.cms.menu('order=name') -->"), 'the full form is fine');
	same(array(), structure('<!-- remove -->x<!-- /remove -->'), 'and remove has no name to write');
});
test('a keyword typo is a warning, never an automatic fix', function () {
	// <!-- div.card --> is as likely an ordinary comment as a misspelled dry
	foreach (array('<!-- prnit.cms.title -->', '<!-- div.card -->x<!-- /div.card -->', '<!-- row.header -->') as $html) {
		$problems = structure($html);
		check(!empty($problems), "$html is reported");
		foreach ($problems as $problem) {
			same('warning', $problem['severity'], $html);
			check(!isset($problem['fix']), "$html must not be rewritten");
		}
	}
});
test('the engine renders only the full form', function () {
	check(!method_exists('template', 'expand_closings'), 'the engine has no second parser for closing tags');
});

// ## Deprecations a site keeps on purpose

test('allow_deprecated counts a use apart instead of warning', function () use ($root) {
	$views = "$root/application/views/default";
	$file = "$views/zz_deprecated.html";
	file_put_contents($file, '<!-- render.validation.not_empty(\'email\') -->x<!-- /render.validation.not_empty(\'email\') -->');
	try {
		$found = raster_project::deprecations($root, 'application');
		$mine = array_values(array_filter($found, function ($d) { return strpos($d['file'], 'zz_deprecated') !== false; }));
		same(1, count($mine), 'the use is found');
		same(false, $mine[0]['allowed'], 'and is not allowed by default');
		config::set('allow_deprecated')->to(array('legacy-validation-regions' => '#zz_deprecated#'));
		$found = raster_project::deprecations($root, 'application');
		$mine = array_values(array_filter($found, function ($d) { return strpos($d['file'], 'zz_deprecated') !== false; }));
		same(true, $mine[0]['allowed'], 'a pattern over the path allows it');
		config::set('allow_deprecated')->to(array('legacy-validation-regions' => '#nothing-like-this#'));
		$found = raster_project::deprecations($root, 'application');
		$mine = array_values(array_filter($found, function ($d) { return strpos($d['file'], 'zz_deprecated') !== false; }));
		same(false, $mine[0]['allowed'], 'a pattern that does not match does not');
	} finally {
		config::set('allow_deprecated')->to(array());
		unlink($file);
	}
});

// ## The vocabulary: what a template may name

test('signatures are read from the tokens', function () {
	$shape = function ($code) {
		$tokens = token_get_all("<?php class x { $code }");
		foreach ($tokens as $i => $t) {
			if (is_array($t) && $t[0] === T_FUNCTION) {
				for ($j = $i + 1; $j < count($tokens); $j++) {
					if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) return raster_inspector::signature($tokens, $j + 1);
				}
			}
		}
		return null;
	};
	$none = $shape('function a() {}');
	same(array(0, 0, false), array($none['required'], $none['total'], $none['variadic']));
	$one = $shape('function a($x) {}');
	same(array(1, 1), array($one['required'], $one['total']));
	$default = $shape('function a($x, $y = 3) {}');
	same(array(1, 2), array($default['required'], $default['total']));
	// a default that is itself a call, so the parser must follow the nesting
	$nested = $shape('function a($x, $y = array(1, 2), $z = null) {}');
	same(array(1, 3), array($nested['required'], $nested['total']));
	$typed = $shape('function a(string $x, ?int $y = null): array {}');
	same(array(1, 2), array($typed['required'], $typed['total']));
	$variadic = $shape('function a($x, ...$rest) {}');
	same(array(1, 2, true), array($variadic['required'], $variadic['total'], $variadic['variadic']));
	$reference = $shape('function a(&$x) {}');
	same(array(1, 1), array($reference['required'], $reference['total']));
	same('a(x, y = …)', raster_inspector::signature_text('a', $default));
	same('a(x, …rest)', raster_inspector::signature_text('a', $variadic));
});
test('lint checks the number of arguments', function () {
	// site.nav() in the starter app takes none
	check(has_problem(lint_html("<!-- render.site.nav(1) -->x<!-- /render.site.nav(1) -->"), 'takes 0 argument(s), 1 given'));
	same(array(), lint_html("<!-- render.site.nav -->x<!-- /render.site.nav -->"));
	// the starter app's site model has year() and nav()
	check(has_problem(lint_html('<!-- print.site.yaer /-->'), "did you mean 'year'?"), 'and names the nearest method');
	check(!has_problem(lint_html('<!-- print.site.nothing_like_it /-->'), 'did you mean'), 'but only when there is one');
});
test('the vocabulary is read from the code', function () {
	$vocabulary = (new raster_inspector())->vocabulary();
	check(isset($vocabulary['models']['site']), 'the app model: '.implode(', ', array_keys($vocabulary['models'])));
	check(isset($vocabulary['models']['cms']) && $vocabulary['models']['cms']['bundled'], 'the bundled ones');
	check(isset($vocabulary['models']['cms']['methods']['style']), 'with their methods');
	foreach ($vocabulary['models'] as $name => $model) {
		foreach ($model['methods'] as $method => $shape) {
			check(isset($shape['reads']) && array_key_exists('needs', $shape), "$name.$method has no shape");
		}
	}
	same(array('session', 'self', 'if'), $vocabulary['engine_models']);
	check(in_array('slug', $vocabulary['reserved']['fields']) && in_array('style', $vocabulary['reserved']['fields']));
	check(in_array('launch', $vocabulary['events']['sent']) && in_array('cms.item_saved', $vocabulary['events']['sent']));
	// cms has __call (print.cms.<field> is any field), so lint cannot check its names
	check($vocabulary['models']['cms']['any_method'] === true, 'cms takes any method');
	check($vocabulary['models']['site']['any_method'] === false, 'an ordinary model does not');
});
test('lint_source lints markup that is not on disk', function () {
	$inspector = new raster_inspector();
	same(array(), $inspector->lint_source('<!-- dry._layout.head /-->'), 'dry resolves against the theme');
	$problems = $inspector->lint_source('<!-- print.nosuch.x /-->', 'draft.html');
	same(1, count($problems));
	same('draft.html', $problems[0]['file'], 'reported under the name it would be saved as');
});
test('describe answers by section, and never with a secret', function () use ($root) {
	require_once BASE.'tools/describe.php';
	$all = raster_describe::site();
	same(raster_describe::sections(), array_keys($all));
	same('application', $all['site']['app']);
	check(count($all['pages']) > 3 && count($all['views']) > 3);
	same(array('site', 'schema'), array_keys(raster_describe::site(array('site', 'schema'))));
	// the settings whitelist decides what an agent sees
	config::set('mcp_token')->to('super-secret-token-value');
	$settings = raster_describe::site(array('settings'))['settings'];
	check(strpos(json_encode($settings), 'super-secret') === false, 'the token must not be in describe');
	check(!array_key_exists('mail', $settings), 'nor the mail DSN, which can hold a password');
	config::set('mcp_token')->to(null);
});

// ## Field types

test('a mock-up gives a template field its type', function () {
	foreach (array('14' => 'int', '-3' => 'int', '0' => 'int', '4.50' => 'number', '2026-10-10' => 'date', '2026-10-10 19:00' => 'datetime',
		'2026-10-10T19:00:00' => 'datetime', '19:00' => 'time', '14 lei' => 'text', '0721 000 000' => 'text', '07' => 'text', '' => 'text', 'Jazz night' => 'text') as $example => $type) {
		same($type, cms_types::of_example($example), "'$example':");
	}
	same(array('int', 'number', 'bool', 'text'), array(cms_types::of_default(0), cms_types::of_default(0.0), cms_types::of_default(false), cms_types::of_default('')));
});

test('values are stored as their type, or refused naming the field', function () {
	same(4, cms_types::clean('int', ' 4 ', 'guests'));
	same(4, cms_types::clean('int', 4.0, 'guests'));
	same(4.5, cms_types::clean('number', '4.50', 'price'));
	same(1, cms_types::clean('bool', 'yes', 'newsletter'));
	same(0, cms_types::clean('bool', '', 'newsletter'), 'an unticked box');
	same('2026-10-05', cms_types::clean('date', '5 Oct 2026', 'date'));
	same('2026-10-05 19:30:00', cms_types::clean('datetime', '2026-10-05 19:30', 'starts'));
	same('09:05', cms_types::clean('time', '9:05', 'time'));
	same('19:00', cms_types::clean('time', '7pm', 'time'));
	same(null, cms_types::clean('date', '', 'date'), 'empty is null, not text');
	same('', cms_types::clean('text', null, 'name'));
	same(date('Y-m-d'), cms_types::clean('date', 'today', 'date'), 'date>=today');
	same('12:00', cms_types::clean('time', 'noon', 'time'));
	// PHP reads a lone letter as a time zone and moves 31 Feb into March; a
	// date is not a time of day; 1e19 is past an int, 1e999 is INF; 01234
	// would lose its zero
	foreach (array(array('int', '2.5'), array('int', 'many'), array('int', 1e19), array('int', '01234'), array('number', '4,50'), array('number', '1e999'), array('bool', 'maybe'),
		array('date', '2026-02-30'), array('date', '31 Feb 2026'), array('date', '20261005'), array('date', 'a'), array('date', 'x'), array('datetime', 'eat'),
		array('time', '25:00'), array('time', 'a'), array('time', '2026-10-05')) as $bad) {
		try {
			cms_types::clean($bad[0], $bad[1], 'x');
			throw new Exception("'{$bad[1]}' passed as {$bad[0]}");
		} catch (cms_type_error $e) {
			same('x', $e->field);
			check(strpos($e->getMessage(), "'x' must be") === 0, $e->getMessage());
		}
	}
});

test('reads give PHP types; templates print text with the mock-up\'s decimals', function () {
	same(array(3, 4.5, true, null, ''), array(cms_types::read('int', '3'), cms_types::read('number', '4.5'), cms_types::read('bool', 1), cms_types::read('date', null), cms_types::read('text', null)));
	// as SQLite and MySQL report columns
	foreach (array('INTEGER' => 'int', 'int(11)' => 'int', 'int(11) unsigned' => 'int', 'tinyint(3) unsigned' => 'int', 'tinyint(1)' => 'bool', 'BOOLEAN' => 'bool',
		'REAL' => 'number', 'double' => 'number', 'date' => 'date', 'datetime' => 'datetime', 'time' => 'time', 'TEXT' => 'text', 'varchar(191)' => 'text', 'NUMERIC' => 'text') as $column => $type) {
		same($type, cms_types::of_column($column), "$column:");
	}
	// a record's row keeps its numbers, which the template prints with its
	// mock-up's decimals
	same(array('price' => 24.0, 'name' => 'Mug', 'raster_escape' => array('name')), cms_records::for_template(array('html' => array()), array('price' => 24.0, 'name' => 'Mug')));
	same('4.50', cms_types::show(4.5, '14.50'));
	same('4.5', cms_types::show(4.5, 'Price'));
	same('12', cms_types::show(12.0, '0'));
	same(array('1', '0', '', '3'), array(cms_types::show(true), cms_types::show(false), cms_types::show(null), cms_types::show(3)));
});

// ## RedBean loads without a MySQL driver

test('the ORM does not need pdo_mysql for an SQLite site', function () {
	check(defined('RB_PDO_MYSQL_ATTR_INIT_COMMAND'), 'rb.php defines it whatever drivers PHP has');
	check(in_array('sqlite', PDO::getAvailableDrivers()), 'and SQLite is enough to get here');
});

// ## 2.1.8 batch A: page cache

// a PHP process of its own with the cache on, as a request for /about would
// be; $code runs after it
function cache_php($code) {
	global $root;
	return array(PHP_BINARY, '-r', 'require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli("/about"); config::set("page_cache")->to(true); raster_cache::$cacheable = true; http_response_code(200); '.$code);
}
function cache_run($code) {
	$process = proc_open(cache_php($code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	proc_close($process);
	return $out;
}
function cache_empty() {
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
}

test('a cached page is never read half written', function () {
	cache_empty();
	// one process rebuilds the page after content changes, as busy pages are,
	// while another reads it the way serve() does
	$writer = proc_open(cache_php('$end = microtime(true) + 2; for ($i = 0; microtime(true) < $end; $i++) { raster_cache::bump(); raster_cache::serve(); raster_cache::store(str_repeat($i % 2 ? "a" : "b", $i % 2 ? 300000 : 200000)); }'), array(1 => array('file', '/dev/null', 'w')), $pipes);
	$out = cache_run('$file = raster_cache::dir().raster_cache::key(); $end = microtime(true) + 2; $reads = 0; $short = 0;
		while (microtime(true) < $end) {
			$handle = @fopen($file, "r");
			if (!$handle) continue;
			$meta = fgets($handle);
			$body = stream_get_contents($handle);
			fclose($handle);
			if ($meta === false) continue;
			$reads++;
			if (strlen($body) !== 300000 && strlen($body) !== 200000) $short++;
		}
		echo "$reads $short";');
	proc_close($writer);
	cache_empty();
	list($reads, $short) = explode(' ', trim($out)) + array(0, -1);
	check((int)$reads > 50, "too few reads to tell: $out");
	same('0', $short, "of $reads reads, pages cut short:");
});

test('a page whose render overlapped a content change is not served as fresh', function () {
	cache_empty();
	// the request starts (and finds nothing cached), an editor saves while it
	// renders, and it stores the page it built from the old content
	cache_run('raster_cache::serve(); util::content_changed(); raster_cache::store("old page");');
	$next = cache_run('raster_cache::serve(); echo "miss";');
	cache_empty();
	same('miss', $next, 'the next visitor gets the old page as a hit:');
});

test('many cache bumps at once never bring an old version back', function () {
	cache_empty();
	cache_run('raster_cache::bump();');
	$bumpers = array();
	for ($i = 0; $i < 6; $i++) $bumpers[] = proc_open(cache_php('for ($i = 0; $i < 400; $i++) raster_cache::bump();'), array(1 => array('file', '/dev/null', 'w')), $pipes);
	$seen = cache_run('$seen = array(); $end = microtime(true) + 1.5;
		while (microtime(true) < $end) { $v = raster_cache::version(); if (!$seen || end($seen) !== $v) $seen[] = $v; }
		echo json_encode($seen);');
	foreach ($bumpers as $process) proc_close($process);
	$seen = json_decode($seen, true);
	check(is_array($seen) && count($seen) > 1, 'the version changed');
	foreach ($seen as $v) check($v !== 0 && $v !== '' && $v !== '0', 'a version read as empty: '.json_encode($v));
	same(count($seen), count(array_unique($seen, SORT_REGULAR)), 'a version came back:');
	cache_empty();
});

test('a content change deletes the cached pages it made old, and leaves pages being written', function () {
	cache_empty();
	cache_run('raster_cache::serve(); raster_cache::store("a page");');
	$pages = function () { return count(preg_grep('/\/[0-9a-f]{40}$/', glob(APPBASE.'data/cache/*') ?: array())); };
	same(1, $pages());
	// a page another request is writing right now
	$writing = APPBASE.'data/cache/'.str_repeat('a', 40).'.'.str_repeat('b', 12).'.tmp';
	file_put_contents($writing, 'half');
	cache_run('util::content_changed();');
	same(0, $pages(), 'cached pages left behind after a content change:');
	check(is_file($writing), 'a page being written was deleted');
	cache_empty();
});

test('tracking parameters alone still use the cache', function () {
	$was = array(config::get('page_cache'), isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null, isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : null);
	config::set('page_cache')->to(true);
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$cacheable = function ($query) { $_SERVER['QUERY_STRING'] = $query; raster_cache::$cacheable = null; return raster_cache::cacheable(); };
	try {
		same(true, $cacheable(''));
		foreach (array('utm_source=x', 'utm_source=news&utm_medium=email&utm_campaign=oct', 'fbclid=IwAR0', 'gclid=abc', 'msclkid=1', 'utm_content=a&fbclid=b&') as $query) same(true, $cacheable($query), $query);
		foreach (array('x=1', 'utm_source=x&page=2', 'lang=ro', 'fbclid2=1', 'gclid[]=1') as $query) same(false, $cacheable($query), $query);
	} finally {
		config::set('page_cache')->to($was[0]);
		$_SERVER['REQUEST_METHOD'] = $was[1];
		$_SERVER['QUERY_STRING'] = $was[2];
		raster_cache::$cacheable = null;
	}
});

test('empty filter pages, pages past the last one and unknown filters are not cached', function () use ($root, $db) {
	cache_empty();
	$port = free_port();
	$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		$get = function ($path) use ($port) {
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port$path");
			return $status.' '.(current(preg_grep('/^X-Raster-Cache/i', $headers)) ?: 'not cached');
		};
		foreach (array('/news/news_items/headline/nobody-wrote-this', '/news/news_page/999', '/news/news_items/no_such_field/x', '/news/news_page/2x') as $path) {
			same('200 not cached', $get($path), $path);
			same('200 not cached', $get($path), $path);
		}
		same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		// lists with items are cached as before
		foreach (array('/news/news_items/headline/Filler%201', '/news/news_page/2', '/news') as $path) {
			same('200 X-Raster-Cache: miss', $get($path), $path);
			same('200 X-Raster-Cache: hit', $get($path), $path);
		}
		// a link with only tracking parameters is the same page
		same('200 X-Raster-Cache: hit', $get('/news?utm_source=newsletter&fbclid=x'));
		same('200 not cached', $get('/news?x=1'));
	} finally {
		proc_terminate($server);
		cache_empty();
	}
});

// a production server on its own port, for the cache tests over HTTP
function cache_server($db, $test) {
	global $root;
	$port = free_port();
	$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	$get = function ($path) use ($port) {
		list($status, $body, $headers) = http('GET', "http://127.0.0.1:$port$path");
		return array($status.' '.(current(preg_grep('/^X-Raster-Cache/i', $headers)) ?: 'not cached'), $body);
	};
	try {
		$test($get);
	} finally {
		proc_terminate($server);
		cache_empty();
	}
}

test('other spellings of a list or item URL are not cached', function () use ($db) {
	cache_empty();
	cache_server($db, function ($get) {
		// each answers as the page it stands for, but there is no end to them
		foreach (array('/news/news_page/-1', '/news/news_page/0', '/news/news_page/1', '/news/news_page/02', '/news/news_page/2/abc', '/news/news_page/2/abc/def',
			'/news/news_items/headline/Filler%201/junk', '/news/news_items/headline/Filler%201/news_page/-5', '/news/news_items/news_page/2/headline/Filler%201',
			'/news/news_items/headline/Filler%201/headline/Filler%201', '/news/news_item/01', '/news/news_item/001', '/news/news_item/1/junk', '/news/news_item/1/x/y') as $path) {
			same('200 not cached', $get($path)[0], $path);
			same('200 not cached', $get($path)[0], $path);
		}
		same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		// the URLs the site's own links use are cached
		foreach (array('/news/news_item/1', '/news/news_page/2', '/news/news_items/headline/Filler%201') as $path) {
			same('200 X-Raster-Cache: miss', $get($path)[0], $path);
			same('200 X-Raster-Cache: hit', $get($path)[0], $path);
		}
	});
});

test('a typed filter spelled another way is not cached', function () use ($db) {
	cache_empty();
	cache_server($db, function ($get) {
		// the same date, the same id, but there is no end to the spellings
		foreach (array('/news/news_items/date/25%20Sep%202026', '/news/news_items/date/2026-09-25%2000:00', '/news/news_items/date/%202026-09-25',
			'/news/news_items/id/+1') as $path) {
			check(strpos($get($path)[1], 'Raster runs on PHP 8') !== false, "$path lists the item");
			same('200 not cached', $get($path)[0], $path);
		}
		same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		// the spelling the site prints is cached
		foreach (array('/news/news_items/date/2026-09-25', '/news/news_items/id/1') as $path) {
			same('200 X-Raster-Cache: miss', $get($path)[0], $path);
			same('200 X-Raster-Cache: hit', $get($path)[0], $path);
		}
	});
});

test('a tracking link does not leave its parameters in the page every visitor gets', function () use ($db) {
	cache_empty();
	cache_server($db, function ($get) {
		list($first, $body) = $get('/news?utm_source=attacker&utm_campaign=evil');
		same('200 X-Raster-Cache: miss', $first);
		check(strpos($body, 'news_page/2') !== false, 'the list has a link to page 2');
		list($next, $body) = $get('/news');
		same('200 X-Raster-Cache: hit', $next);
		check(strpos($body, 'attacker') === false && strpos($body, 'evil') === false, 'the cached page carries the tracking link\'s parameters');
	});
});

test('in production, made-up URLs of a list with no items are not cached', function () use ($db) {
	cache_empty();
	$empty = sys_get_temp_dir().'/raster-test-empty-'.getmypid().'.sqlite';
	copy($db, $empty);
	$pdo = new PDO("sqlite:$empty");
	$pdo->exec('DELETE FROM newsdata');
	$pdo = null;
	try {
		cache_server($empty, function ($get) {
			foreach (array('/news/news_items/headline/r1', '/news/news_items/headline/r2', '/news/news_page/500') as $path) {
				same('200 not cached', $get($path)[0], $path);
				same('200 not cached', $get($path)[0], $path);
			}
			same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		});
	} finally {
		array_map('unlink', glob("$empty*") ?: array());
	}
});

test('in production, made-up URLs of a list with no table yet are not cached', function () use ($db) {
	cache_empty();
	$bare = sys_get_temp_dir().'/raster-test-bare-'.getmypid().'.sqlite';
	copy($db, $bare);
	$pdo = new PDO("sqlite:$bare");
	$pdo->exec('DROP TABLE newsdata');
	$pdo = null;
	try {
		cache_server($bare, function ($get) {
			// before schema --apply the list keeps its mock-up
			foreach (array('/news/news_items/headline/r1', '/news/news_items/nonsense/x', '/news/news_page/7') as $path) {
				same('200 not cached', $get($path)[0], $path);
				same('200 not cached', $get($path)[0], $path);
			}
			same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		});
	} finally {
		array_map('unlink', glob("$bare*") ?: array());
	}
});

test('a content change deletes temporary cache files left long ago', function () {
	cache_empty();
	$dir = APPBASE.'data/cache/';
	if (!is_dir($dir)) mkdir($dir, 0775, true);
	$old = $dir.str_repeat('c', 40).'.'.str_repeat('d', 12).'.tmp';
	$new = $dir.str_repeat('e', 40).'.'.str_repeat('f', 12).'.tmp';
	file_put_contents($old, 'left by a request that died');
	touch($old, time() - 7200);
	file_put_contents($new, 'being written');
	cache_run('util::content_changed();');
	check(!is_file($old), 'a temporary file from two hours ago is still there');
	check(is_file($new), 'a page being written was deleted');
	cache_empty();
});

test('a view name in other letter case is not cached', function () use ($db) {
	cache_empty();
	cache_server($db, function ($get) {
		// on a disk that ignores case (macOS, Windows) these find about.html
		// and news.html and answer; on one that doesn't they are 404s. Either
		// way there is no end to them, so none is kept
		foreach (array('/ABOUT', '/About', '/aBoUt', '/NEWS', '/News/news_page/2', '/NEWS/news_items/headline/Filler%201', '/NEWS.rss') as $path) {
			list($status) = explode(' ', $get($path)[0]);
			check(in_array($status, array('200', '404')), "$path answers $status");
			same($status.' not cached', $get($path)[0], $path);
		}
		same(0, count(glob(APPBASE.'data/cache/*') ?: array()), 'cache files');
		// the spelling of the view's own file is cached
		foreach (array('/about', '/news/news_page/2', '/news/news_items/headline/Filler%201', '/news.rss') as $path) {
			same('200 X-Raster-Cache: miss', $get($path)[0], $path);
			same('200 X-Raster-Cache: hit', $get($path)[0], $path);
		}
	});
});

// ## 2.1.8 batch B: errors and /api

// A server as PHP runs without a php.ini (the official Docker image): it
// shows errors and buffers nothing. Errors PHP logs go to the file.
function b_server($env) {
	global $root;
	$port = free_port();
	$log = sys_get_temp_dir().'/raster-b-'.getmypid().'-'.$port.'.log';
	$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-d', 'html_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$root/index.php"),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array_merge(array('PATH' => getenv('PATH')), $env));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	register_shutdown_function(function () use ($process, $log) { proc_terminate($process); @unlink($log); });
	return array("http://127.0.0.1:$port", $log);
}
function b_header($headers, $name) {
	foreach ($headers as $h) if (stripos($h, $name.':') === 0) return trim(substr($h, strlen($name) + 1));
	return null;
}
// a model that breaks in every way /api and the request can see
function b_with_boom($fn) {
	global $root;
	$dir = "$root/application/models/zzboom";
	@mkdir($dir);
	file_put_contents("$dir/zzboom.php", "<?php\nclass zzboom {\n"
		."\tstatic function api() { return array('boom' => 'visitor', 'needs' => 'visitor', 'inner' => 'visitor', 'down' => 'visitor', 'echoes' => 'visitor', 'sql' => 'visitor', 'mine' => 'member'); }\n"
		."\tstatic function listens() { return array('route_set' => 'trip'); }\n"
		."\tfunction boom() { throw new RuntimeException('boom secret'); }\n"
		."\tfunction needs(\$a, \$b) { return \$a.\$b; }\n"
		."\tfunction inner() { return str_repeat('x'); }\n"
		."\tfunction down() { throw new PDOException('the database secret is unreachable'); }\n"
		."\tfunction echoes() { echo 'partial secret'; throw new RuntimeException('after output'); }\n"
		."\tfunction sql() { database::instance(); return R::getAll('SELECT * FROM no_such_table_zz'); }\n"
		."\tfunction mine() { return 'mine'; }\n"
		."\tfunction trip() { if (isset(\$_GET['trip'])) throw new RuntimeException('listener secret'); }\n"
		."}\n");
	try { $fn(); } finally { @unlink("$dir/zzboom.php"); @rmdir($dir); }
}
$b_db = sys_get_temp_dir().'/raster-b-'.getmypid().'.sqlite';
shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($b_db).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --apply 2>&1');
register_shutdown_function(function () use ($b_db) { array_map('unlink', glob("$b_db*") ?: array()); });

test('cms answers /api only for what its api() lists (#32)', function () use ($base, $root) {
	foreach (array('setup', 'route', 'inject_toolbar', 'login', 'login_message') as $method) {
		list($status, $body) = http('GET', "$base/api/cms/$method");
		same(404, $status, "/api/cms/$method");
		check(strpos($body, 'no users') === false, 'login_message answered');
	}
	same(200, http('GET', "$base/api/cms/style")[0], 'style is listed');
	same(403, http('POST', "$base/api/cms/editor_save_field", 'type=aboutpage', array('Content-Type: application/x-www-form-urlencoded'))[0], 'editor endpoints still answer 403 themselves');
	same(array('editor_save_field' => 'visitor', 'style' => 'visitor', 'logout' => 'visitor'), array_intersect_key(api::offered('cms'), array('editor_save_field' => 1, 'style' => 1, 'logout' => 1)));
	// a public method an override adds is not offered unless listed
	$dir = "$root/application/models/the_cms";
	@mkdir($dir);
	file_put_contents("$dir/the_cms.php", "<?php\nclass the_cms extends cms {\n\tfunction secret_report() { return 'secret'; }\n}\n");
	try {
		list($status, $body) = http('GET', "$base/api/cms/secret_report");
		same(404, $status, 'an override\'s public method');
		check(strpos($body, 'secret') === false);
		same(200, http('GET', "$base/api/cms/style")[0], 'the override keeps what cms lists');
		// an override that lists its own methods adds to what cms lists
		file_put_contents("$dir/the_cms.php", "<?php\nclass the_cms extends cms {\n\tstatic function api() { return array('report' => 'visitor'); }\n\tfunction report() { return 'r'; }\n}\n");
		same(200, http('GET', "$base/api/cms/report")[0], 'its own method');
		same(200, http('GET', "$base/api/cms/style")[0], 'and still what cms lists');
		same(403, http('POST', "$base/api/cms/editor_save_field", 'type=aboutpage', array('Content-Type: application/x-www-form-urlencoded'))[0], 'the editor too');
	} finally {
		@unlink("$dir/the_cms.php");
		@rmdir($dir);
	}
});

test('api_open is gone: a model without api() offers nothing, whatever the config says (#24)', function () {
	if (!class_exists('zzlisted', false)) eval('class zzlisted { function ping() { return 1; } }');
	config::set('api_open')->to(true);
	try {
		same(array(), api::offered('zzlisted'));
	} finally {
		config::set('api_open')->to(null);
	}
	check(!is_file(BASE.'upgrades/2.1.1.php'), 'the 2.1.1 step that wrote it');
	check(!array_key_exists('api-open', include BASE.'tools/deprecations.php'), 'its deprecation entry');
});

test('an /api method that throws answers JSON, logged, with no trace in production (#74)', function () use ($b_db) {
	list($prod, $log) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $b_db));
	b_with_boom(function () use ($prod, $log) {
		list($status, $body, $headers) = http('GET', "$prod/api/zzboom/boom");
		same(500, $status, $body);
		same(array('error' => 'server error'), json_decode($body, true), $body);
		same('application/json', b_header($headers, 'Content-Type'));
		check(strpos((string)@file_get_contents($log), 'boom secret') !== false && strpos((string)@file_get_contents($log), '/api/zzboom/boom') !== false, 'logged with its URL');
		// too few arguments is the caller's mistake
		list($status, $body) = http('GET', "$prod/api/zzboom/needs/x");
		same(400, $status, $body);
		check(strpos($body, 'Stack trace') === false && strpos($body, '{') === 0, $body);
		same(200, http('GET', "$prod/api/zzboom/needs/x/y")[0]);
		// an ArgumentCountError from inside the method is not the caller's
		same(500, http('GET', "$prod/api/zzboom/inner")[0]);
		// a database error while the database is there is a bug, not an outage
		list($status, $body) = http('GET', "$prod/api/zzboom/down");
		same(500, $status, $body);
		check(strpos($body, 'secret') === false, $body);
		list($status, $body) = http('GET', "$prod/api/zzboom/sql");
		same(500, $status, $body);
		same(array('error' => 'server error'), json_decode($body, true), $body);
		// what a method printed before it threw doesn't turn the 500 into a 200
		list($status, $body) = http('GET', "$prod/api/zzboom/echoes");
		same(500, $status, $body);
		same(array('error' => 'server error'), json_decode($body, true), $body);
		// a listener that throws outside the render shows nothing of itself
		list($status, $body) = http('GET', "$prod/about?trip=1");
		same(500, $status);
		check(strpos($body, 'listener secret') === false && strpos($body, 'Stack trace') === false, $body);
		check(strpos((string)@file_get_contents($log), 'listener secret') !== false, 'PHP logs it');
	});
});

test('development shows what went wrong on /api (#74)', function () use ($base) {
	b_with_boom(function () use ($base) {
		list($status, $body) = http('GET', "$base/api/zzboom/boom");
		same(500, $status);
		$json = json_decode($body, true);
		same('server error', $json['error']);
		check(strpos($json['exception'], 'boom secret') !== false && is_array($json['trace']), $body);
	});
});

test('the fallback 404 prints after the session starts (#74)', function () use ($b_db) {
	list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $b_db));
	list($status, $body) = http('GET', "$prod/nothing-here", null, array('Cookie: PHPSESSID=abcdefabcdefabcdefabcdefab'));
	same(404, $status);
	check(strpos($body, '404 Not Found') !== false, $body);
	check(strpos($body, 'Warning') === false && strpos($body, 'session') === false, $body);
});

test('doctor warns when PHP shows errors in production; the command line keeps them (#74)', function () use ($root) {
	$doctor = function ($display) use ($root) {
		return shell_exec('RASTER_ENV=production '.escapeshellarg(PHP_BINARY).' -d display_errors='.$display.' '.escapeshellarg("$root/bin/raster").' doctor 2>&1');
	};
	check(strpos($doctor('1'), 'display_errors') !== false, 'on');
	check(strpos($doctor('0'), 'display_errors') === false, 'off');
	$code = 'require "system/boot.php"; boot::$appname = "application"; boot::cli(); echo ini_get("display_errors");';
	same('1', shell_exec('cd '.escapeshellarg($root).' && RASTER_ENV=production '.escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code)));
});

test('a database that can\'t be reached answers 503 outside development, logged, never cached (#65)', function () use ($b_db, $root) {
	$cache = "$root/application/data/cache";
	$had_cache = is_dir($cache);
	$bad = sys_get_temp_dir().'/raster-b-down-'.getmypid().'.sqlite';
	file_put_contents($bad, str_repeat('this is not a database ', 100));
	list($prod, $log) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $bad));
	try {
		foreach (array(1, 2) as $time) {
			list($status, $body, $headers) = http('GET', "$prod/about");
			same(503, $status, "request $time");
			check(strpos($body, '<!-- print.') === false && strpos($body, 'Write HTML') === false, 'no mock-up');
			check(b_header($headers, 'X-Raster-Cache') !== 'hit', 'not from the cache');
		}
		same(503, http('GET', "$prod/news.rss")[0], 'feeds too');
		// /api too: a read during the outage is not an empty table
		b_with_boom(function () use ($prod) {
			list($status, $body) = http('GET', "$prod/api/zzboom/needs/x/y");
			same(503, $status, $body);
			same(array('error' => 'database unavailable'), json_decode($body, true), $body);
			// who is asking can't be known either: an outage, not "log in"
			list($status, $body) = http('GET', "$prod/api/zzboom/mine", null, array('Cookie: PHPSESSID=abcdefabcdefabcdefabcdefab'));
			same(503, $status, $body);
		});
		check(strpos((string)@file_get_contents($log), 'database') !== false, 'logged');
		// back up: the real page, at once
		copy($b_db, $bad);
		list($status, $body, $headers) = http('GET', "$prod/about");
		same(200, $status);
		same('miss', b_header($headers, 'X-Raster-Cache'));
		check(strpos($body, '<!-- print.') === false);
	} finally {
		array_map('unlink', glob("$bad*") ?: array());
		if (!$had_cache) exec('rm -rf '.escapeshellarg($cache));
	}
	// a database that is there but has no tables yet still shows the template
	$empty = sys_get_temp_dir().'/raster-b-empty-'.getmypid().'.sqlite';
	touch($empty);
	list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $empty, 'RASTER_URL' => 'http://example.test/'));
	try {
		list($status, $body) = http('GET', "$prod/");
		same(200, $status);
		check(strpos($body, 'Write HTML. Get a CMS.') !== false, 'template default');
	} finally {
		array_map('unlink', glob("$empty*") ?: array());
		if (!$had_cache) exec('rm -rf '.escapeshellarg($cache));
	}
	// development says so plainly instead
	$bad = sys_get_temp_dir().'/raster-b-down-dev-'.getmypid().'.sqlite';
	file_put_contents($bad, str_repeat('this is not a database ', 100));
	list($dev) = b_server(array('RASTER_ENV' => 'development', 'RASTER_DB' => $bad));
	try {
		list($status, $body) = http('GET', "$dev/about");
		same(500, $status);
		check(strpos($body, 'database') !== false, $body);
		b_with_boom(function () use ($dev) {
			list($status, $body) = http('GET', "$dev/api/zzboom/needs/x/y");
			same(503, $status, $body);
			$json = json_decode($body, true);
			check(isset($json['exception']) && strpos($json['exception'], 'not a database') !== false, $body);
		});
	} finally {
		array_map('unlink', glob("$bad*") ?: array());
	}
});

test('the editor\'s endpoints refuse a list where they take one value, and change nothing (#139)', function () use ($base, $root, $db) {
	// the editor 'editor login and csrf' made
	list($status, , $headers) = http('POST', "$base/login", 'login=editor&password=correct+horse', array('Content-Type: application/x-www-form-urlencoded'));
	same(303, $status, 'logged in');
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	check(preg_match('/"csrf":"([a-f0-9]+)"/', http('GET', "$base/about", null, array("Cookie: $cookie"))[1], $m), 'editor missing');
	$form = array('Content-Type: application/x-www-form-urlencoded', "Cookie: $cookie");
	$post = function ($endpoint, $body) use ($base, $form, $m) {
		list($status, $text) = http('POST', "$base/api/cms/$endpoint", $body.'&csrf='.$m[1], $form);
		check(strpos($text, 'Warning') === false && strpos($text, 'Array to string') === false, "$endpoint: $text");
		return array($status, json_decode($text, true), $text);
	};
	$refused = function ($endpoint, $body) use ($post) {
		list($status, $json, $text) = $post($endpoint, $body);
		same(400, $status, "$endpoint $body: $text");
		check(isset($json['error']), $text);
	};
	$saved = function ($endpoint, $body) use ($post) {
		list($status, $json, $text) = $post($endpoint, $body);
		same(200, $status, "$endpoint $body: $text");
		return $json;
	};
	// what the server stored, asked over MCP
	$revisions = function () { return count(mcp_call('page_history', array('page' => '/about', 'limit' => 100))['structuredContent']['revisions']); };
	$total = function ($collection) { return mcp_call('list_items', array('collection' => $collection))['structuredContent']['total']; };
	$item = function ($collection, $id) { return mcp_call('get_item', array('collection' => $collection, 'id' => $id))['structuredContent']; };
	// a page field
	$before = $revisions();
	$refused('editor_save_field', 'type=aboutpage&slug=/about&field=heading&value[]=x');
	$refused('editor_save_field', 'type[]=aboutpage&slug=/about&field=heading&value=x');
	$refused('editor_save_field', 'type=aboutpage&slug=/about&field[]=heading&value=x');
	$refused('editor_save_field', 'type=aboutpage&slug[]=/about&field=heading&value=x');
	$refused('editor_save_field', 'type=aboutpage&slug=/about&field=heading&value=x&example[]=1');
	$refused('editor_history', 'type[]=aboutpage');
	$refused('editor_restore', 'type=aboutpage&slug=/about&revision[]=1');
	same($before, $revisions(), 'no revision was added');
	// items: id[]=<the second> must not reach item 1
	$first = $saved('editor_save_item', 'collection=news&id=0&fields[headline]=Array+first')['id'];
	$second = $saved('editor_save_item', 'collection=news&id=0&fields[headline]=Array+second')['id'];
	$items = $total('news');
	$refused('editor_delete_item', 'collection=news&id[]='.$second);
	$refused('editor_delete_item', 'collection[]=news&id='.$second);
	same($items, $total('news'), 'nothing deleted');
	$refused('editor_save_item', 'collection=news&id='.$first.'&fields[slug][]=x');
	$refused('editor_save_item', 'collection=news&id='.$first.'&fields[headline][]=x');
	$refused('editor_save_item', 'collection=news&id[]='.$second.'&fields[headline]=x');
	$refused('editor_save_item', 'collection[]=news&id='.$first.'&fields[headline]=x');
	$news = array($item('news', $first), $item('news', $second));
	same(array('Array first', 'Array second'), array_column($news, 'headline'));
	same(array('array-first', 'array-second'), array_column($news, 'slug'));
	same($items, $total('news'), 'nothing added');
	// one value still saves
	same('Array saved', $saved('editor_save_item', 'collection=news&id='.$second.'&fields[headline]=Array+saved')['headline']);
	// a record type's list field takes a list; its actions take input[...]
	$dir = "$root/application/models/zzlist";
	@mkdir($dir);
	file_put_contents("$dir/zzlist.php", "<?php\nclass zzlist {\n"
		."\tstatic function types() { return array('zzlist' => array('fields' => array('name' => '', 'lines' => array()), 'actions' => array('mark' => 'editor'))); }\n"
		."\tstatic function mark(\$item, \$input) { return cms_records::update('zzlist', \$item['id'], array('name' => 'marked '.(isset(\$input['note']) ? \$input['note'] : ''))); }\n"
		."}\n");
	try {
		$one = $saved('editor_save_item', 'collection=zzlist&id=0&fields[name]=one&fields[lines][0][sku]=a&fields[lines][1][sku]=b')['id'];
		$two = $saved('editor_save_item', 'collection=zzlist&id=0&fields[name]=two')['id'];
		$refused('editor_save_item', 'collection=zzlist&id='.$one.'&fields[name][]=x');
		$refused('editor_action', 'collection=zzlist&id[]='.$two.'&action=mark');
		$refused('editor_action', 'collection=zzlist&id='.$two.'&action[]=mark');
		$refused('editor_action', 'collection[]=zzlist&id='.$two.'&action=mark');
		$refused('editor_delete_item', 'collection=zzlist&id[]='.$two);
		same(2, $total('zzlist'));
		same('two', $item('zzlist', $two)['name']);
		same(array('name' => 'one', 'lines' => array(array('sku' => 'a'), array('sku' => 'b'))), array_intersect_key($item('zzlist', $one), array('name' => 1, 'lines' => 1)));
		same('marked ok', $saved('editor_action', 'collection=zzlist&id='.$two.'&action=mark&input[note]=ok')['name']);
	} finally {
		@unlink("$dir/zzlist.php");
		@rmdir($dir);
		exec('RASTER_DB='.escapeshellarg($db).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' schema --drop=zzlistdata --force');
	}
});

// ## 2.1.8 batch C: list SQL

// the adapter throws on bad SQL, where a fluid R::find returns nothing
function sql_titles($table, $conditions, $order) {
	$columns = cms_store::columns($table);
	list($sql, $bindings) = cms_store::conditions_sql($conditions, $columns);
	return R::getDatabaseAdapter()->getCol("SELECT title FROM $table WHERE 1 = 1 $sql ORDER BY ".cms_store::order_sql($order, $columns), $bindings);
}

test('fields named like SQL words sort, filter, rename and drop', function () {
	cms_store::connect();
	cms_types::ensure('zzwordsdata', array('title' => 'text', 'when' => 'date', 'from' => 'text', 'group' => 'int', 'to' => 'text'));
	try {
		foreach (array(array('Rome', '2026-12-01', 'Paris', 3), array('Oslo', '2026-10-01', 'Berlin', 1), array('Lima', '2026-11-01', 'Paris', 5)) as $row) {
			R::getDatabaseAdapter()->exec('INSERT INTO zzwordsdata (title, `when`, `from`, `group`) VALUES (?, ?, ?, ?)', $row);
		}
		same(array('Oslo', 'Lima', 'Rome'), sql_titles('zzwordsdata', array(), 'when'), 'order=when');
		same(array('Rome', 'Lima'), sql_titles('zzwordsdata', array(array('from', '=', 'Paris')), '-when'), 'from=Paris');
		same(array('Lima', 'Rome'), sql_titles('zzwordsdata', array(array('group', '>', '2')), '-group'), 'group>2');
		same(array('Oslo'), sql_titles('zzwordsdata', array(array('from', '!=', 'Paris')), 'oldest'), 'from!=Paris');
		same(array('Rome', 'Oslo', 'Lima'), sql_titles('zzwordsdata', array(array('when', '!=', '')), 'oldest'), 'when is not empty');
		$schema = new raster_schema();
		same('renamed zzwordsdata.from to where', $schema->rename('zzwordsdata', 'from', 'where'));
		same('moved zzwordsdata.where into to', $schema->rename('zzwordsdata', 'where', 'to'));
		same(array('Rome', 'Lima'), sql_titles('zzwordsdata', array(array('to', '=', 'Paris')), 'oldest'));
		same('dropped zzwordsdata.group', $schema->drop('zzwordsdata', 'group'));
		check(!array_key_exists('group', cms_store::columns('zzwordsdata')), 'group is gone');
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzwordsdata');
		cms_store::forget();
	}
});

test('cms_records::find filters and sorts by fields named like SQL words', function () use ($root) {
	$dir = "$root/application/models/zzwords";
	@mkdir($dir);
	file_put_contents("$dir/zzwords.php", '<?php class zzwords { static function types() { return array("zzword" => array("fields" => array("title" => "", "when" => "", "from" => "", "group" => 0), "types" => array("when" => "date"))); } }');
	cms_records::forget();
	try {
		cms_records::create('zzword', array('title' => 'Rome', 'when' => '2026-12-01', 'from' => 'Paris', 'group' => 3));
		cms_records::create('zzword', array('title' => 'Oslo', 'when' => '2026-10-01', 'from' => 'Berlin', 'group' => 1));
		cms_records::create('zzword', array('title' => 'Lima', 'when' => '2026-11-01', 'from' => 'Paris', 'group' => 5));
		same(array('Lima', 'Rome'), array_column(cms_records::find('zzword', array('from' => 'Paris'), 'when'), 'title'));
		same(array('Rome'), array_column(cms_records::find('zzword', array('from' => 'Paris', 'group' => 3), '-group'), 'title'));
		same(array('Rome', 'Lima', 'Oslo'), array_column(cms_records::find('zzword', array(), '-when'), 'title'));
	} finally {
		unlink("$dir/zzwords.php");
		rmdir($dir);
		cms_records::forget();
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzworddata');
		cms_store::forget();
	}
});

test('published_at is compared with an empty string only while it is text', function () {
	// typed (2.1.7 and later): MySQL refuses '' for a DATETIME
	foreach (array('DATETIME', 'datetime') as $declared) {
		list($sql) = cms_store::published_sql(array('enabled' => 'BOOLEAN', 'published_at' => $declared));
		check(strpos($sql, "''") === false, "$declared: $sql");
		$order = cms_store::order_sql('newest', array('published_at' => $declared));
		check(strpos($order, "''") === false, "$declared: $order");
	}
	// a site whose column is still text keeps the test
	list($sql) = cms_store::published_sql(array('published_at' => 'TEXT'));
	check(strpos($sql, "published_at = ''") !== false, $sql);
	check(strpos(cms_store::order_sql('newest', array('published_at' => 'TEXT')), "published_at = ''") !== false);
	// and both list what they should on SQLite
	cms_store::connect();
	$future = date('Y-m-d H:i:s', time() + 86400);
	$past = date('Y-m-d H:i:s', time() - 86400);
	R::getDatabaseAdapter()->exec('CREATE TABLE zzoldpubdata (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, published_at TEXT, updated_at TEXT)');
	cms_types::ensure('zznewpubdata', array('title' => 'text', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
	try {
		foreach (array('zzoldpubdata' => array('Empty' => '', 'Never' => null, 'Past' => $past, 'Future' => $future), 'zznewpubdata' => array('Never' => null, 'Past' => $past, 'Future' => $future)) as $table => $rows) {
			foreach ($rows as $title => $published) R::getDatabaseAdapter()->exec("INSERT INTO $table (title, published_at, updated_at) VALUES (?, ?, ?)", array($title, $published, '2026-01-01 10:00:00'));
			same(count($rows) - 1, cms_store::count_published($table), $table);
		}
		list($sql, $bindings) = cms_store::published_sql(cms_store::columns('zzoldpubdata'));
		same(array('Past', 'Never', 'Empty'), R::getDatabaseAdapter()->getCol("SELECT title FROM zzoldpubdata WHERE $sql ORDER BY ".cms_store::order_sql('newest', cms_store::columns('zzoldpubdata')), $bindings));
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzoldpubdata');
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zznewpubdata');
		cms_store::forget();
	}
});

test('a time reads without seconds, as MySQL gives it', function () {
	same(array('19:00', '19:00', '09:05', null), array(cms_types::read('time', '19:00:00'), cms_types::read('time', '19:00'), cms_types::read('time', '09:05:30'), cms_types::read('time', null)));
	same('2026-10-10 19:00:00', cms_types::read('datetime', '2026-10-10 19:00:00'), 'a datetime keeps them');
});

// ## 2.1.8 batch D: accounts

test('raster user keeps the role and password it is not given (#71)', function () use ($root) {
	$db = sys_get_temp_dir().'/raster-user-'.getmypid().'.sqlite';
	$env = 'RASTER_ENV=development RASTER_DB='.escapeshellarg($db);
	$raster = function ($args) use ($env, $root) {
		exec("$env ".escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' '.$args.' 2>&1', $out, $code);
		same(0, $code, implode("\n", $out));
		return implode("\n", $out);
	};
	$account = function ($login, $password) use ($env, $root) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); authentication::connect();'
			.' $u = authentication::find('.var_export($login, true).'); echo $u->role, " ", authentication::check_login('.var_export($login, true).', '.var_export($password, true).') ? "ok" : "fail";';
		return trim(shell_exec("$env ".escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1'));
	};
	try {
		$raster('user reader@example.test --role=member --password=first-password');
		same('member ok', $account('reader@example.test', 'first-password'));
		// a password reset keeps the role
		$out = $raster('user reader@example.test --password=second-password');
		check(strpos($out, 'saved as member') !== false && strpos($out, 'role kept') !== false, $out);
		same('member ok', $account('reader@example.test', 'second-password'));
		// a role change keeps the password, and prints none
		$out = $raster('user reader@example.test --role=editor');
		check(strpos($out, 'saved as editor') !== false && strpos($out, 'password kept') !== false, $out);
		check(strpos($out, 'Password:') === false, "no new password: $out");
		same('editor ok', $account('reader@example.test', 'second-password'));
		// neither: nothing changes
		$out = $raster('user reader@example.test');
		check(strpos($out, 'saved as editor') !== false && strpos($out, 'Password:') === false, $out);
		same('editor ok', $account('reader@example.test', 'second-password'));
		// a new account is still an admin with a random password
		$out = $raster('user owner@example.test');
		check(preg_match('/saved as admin\. Password: ([a-f0-9]{18})/', $out, $m), $out);
		same('admin ok', $account('owner@example.test', $m[1]));
	} finally {
		array_map('unlink', glob("$db*") ?: array());
	}
});
test('after a lock runs out, wrong passwords are counted from zero (#73)', function () {
	authentication::connect();
	$id = authentication::save_user('relock@example.test', 'right password', 'member');
	$wrong = function ($times) {
		for ($i = 0; $i < $times; $i++) same(false, authentication::check_login('relock@example.test', 'wrong'));
	};
	$expire = function () use ($id) {
		R::exec('UPDATE user SET failed_at = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - 16 * 60), $id));
	};
	$wrong(5);
	same(false, authentication::check_login('relock@example.test', 'right password'), 'locked');
	$expire();
	$wrong(1);
	same($id, authentication::check_login('relock@example.test', 'right password'), 'one wrong guess after the lock ran out locked it again');
	$wrong(5);
	same(false, authentication::check_login('relock@example.test', 'right password'), 'five new wrong passwords lock it again');
	$expire();
	$wrong(4);
	same($id, authentication::check_login('relock@example.test', 'right password'), 'four wrong passwords after the lock ran out locked it');
});

// ## 2.1.8 batch E: template output
test('a form shown again keeps $100, \\1 and $0 as typed (#66)', function () {
	$typed = 'Table for $20 a head, not $100 \\1 $0 \\\\2 ${1}';
	$e = htmlspecialchars($typed, ENT_QUOTES, 'UTF-8');
	$out = template::instance()->fill_form('<form method="post"><input name="name"><input name="phone" value="x" /><p class="spa_name">n</p></form>', array('name' => $typed, 'phone' => '\\1 $0', 'extra' => $typed), true);
	check(strpos($out, '<input name="name" value="'.$e.'">') !== false, 'input value: '.$out);
	check(strpos($out, 'name="phone" value="\\1 $0" />') !== false, 'a value of \\1 $0: '.$out);
	check(strpos($out, '<p class="spa_name">'.$e.'</p>') !== false, 'spa_ text: '.$out);
	check(strpos($out, '<form method="post">'."\n".'<input type="hidden" name="extra" value="'.$e.'">') !== false, 'hidden input: '.$out);
});
test('an attribute added to a tag keeps $1 and \\1 (#66)', function () {
	same('<a class="x" href="/pay?$1=\\1&amp;$0">p</a>', template::set_attribute('<a class="x">p</a>', 'href', '/pay?$1=\\1&$0'));
	same('<img src="a.jpg" alt="$5 \\1"/>', template::set_attribute('<img src="a.jpg" />', 'alt', '$5 \\1'));
});
test('a link a visitor typed is no script, whatever bytes hide the scheme (#67)', function () {
	foreach (array("javascript:alert(1)", " javascript:x", "java\tscript:x", "java\nscript:x", "java\rscript:x", "\x01javascript:x", "\x00javascript:x", "j\x0Bavascript:x",
		"JaVaScRiPt:x", "&#106;avascript:x", "&#106avascript:x", "&#x6A;avascript:x", "javascript&colon;x", "java&Tab;script:x", "data:text/html,x", "vbscript:x", " v b s c r i p t :x", "\x1Fdata:x",
		"&#1;javascript:x", "java&#13;script:x", "&#x1F;javascript:x", "&#x0D;javascript:x", "&#0;javascript:x", "java&#x09script:x", "&#32;javascript:x") as $link) {
		same(true, template::script_link($link), json_encode($link));
	}
	foreach (array("https://example.com/", "/about", "mailto:a@b.co", "about.html", "#top", "javascripts/app.js", "?q=data:x", "") as $link) {
		same(false, template::script_link($link), json_encode($link));
	}
});
test('the editor\'s clean() drops the same script links (#67)', function () use ($root) {
	$node = trim((string)shell_exec('command -v node 2>/dev/null'));
	if ($node === '') return; // no node here: tests/editor-browser.js covers it in a browser
	$js = file_get_contents("$root/system/models/cms/editor/editor.js");
	check(preg_match('/\tfunction scriptLink\(href\) \{.*?\n\t\}\n/s', $js, $m), 'editor.js has scriptLink()');
	$cases = array("javascript:x" => true, "java\tscript:x" => true, "java\nscript:x" => true, "\x01javascript:x" => true, "vbscript:x" => true, "data:x" => true,
		"&#106avascript:x" => true, "https://example.com/" => false, "/about" => false);
	$file = sys_get_temp_dir().'/raster-clean-'.getmypid().'.js';
	file_put_contents($file, $m[0]."\nvar cases = ".json_encode(array_keys($cases)).";\nprocess.stdout.write(JSON.stringify(cases.map(scriptLink)));\n");
	$out = shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
	unlink($file);
	same(array_values($cases), json_decode((string)$out, true), (string)$out);
});
test('arrays and bad bytes fail required; a field named tags[] takes a list (#70)', function () use ($base) {
	$v = validation::get();
	$r = new ReflectionMethod($v, 'check_field');
	$r->setAccessible(true);
	$saved = $_POST;
	$cases = array(
		array(array('required' => true, 'type' => 'text'), array('x'), array('required')),
		array(array('type' => 'text'), array('x'), array('required')),
		array(array('type' => 'text', 'pattern' => '[0-9 ]+'), "\xFF12", array('required')),
		array(array('type' => 'text'), "caf\xC3", array('required')),
		array(array('type' => 'text', 'list' => true), array('a', 'b'), array()),
		array(array('type' => 'text', 'list' => true, 'required' => true), array('', ''), array('required')),
		array(array('type' => 'text', 'list' => true), array("\xFF"), array('required')),
		array(array('type' => 'text'), 'Café $5', array()),
		array(array('type' => 'text', 'pattern' => '(a+)+b'), str_repeat('a', 40000).'c', array('pattern')),
	);
	try {
		foreach ($cases as $i => $case) {
			$_POST = array('f' => $case[1]);
			same($case[2], $r->invoke($v, 'f', $case[0]), "case $i");
		}
	} finally { $_POST = $saved; }
	same(array('type' => 'checkbox', 'list' => true), array_intersect_key(template::instance()->constraints('<input type="checkbox" name="tags[]" value="a">')['tags'], array('list' => 1, 'type' => 1)));
	check(!isset(template::instance()->constraints('<input name="tags">')['tags']['list']), 'a plain name is no list');
});

// ## 2.1.8 batch F: upgrade tooling
// (batch F adds its tests here)

// ## 2.1.8 batch G: MCP themes

// MCP view tools take a theme only as a folder directly under views/ (#72)
function mcp_stdio_calls($calls) {
	global $root, $db;
	$process = proc_open(array(PHP_BINARY, "$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'PATH' => getenv('PATH')));
	foreach ($calls as $i => $call) {
		fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 100 + $i, 'method' => 'tools/call', 'params' => array('name' => $call[0], 'arguments' => $call[1])))."\n");
	}
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	proc_close($process);
	$answers = array();
	foreach (array_filter(explode("\n", $out)) as $line) {
		$message = json_decode($line, true);
		if (isset($message['id']) && $message['id'] >= 100) $answers[$message['id'] - 100] = $message['result'];
	}
	return $answers;
}
test('mcp view tools refuse a theme outside the views folder', function () use ($root) {
	$outside = sys_get_temp_dir().'/raster-theme-'.getmypid();
	@mkdir($outside);
	file_put_contents("$outside/secret.json", '{"secret":"sk-live-123"}');
	file_put_contents("$outside/index.html", '<p>sk-live-123</p>');
	$link = "$root/application/views/zzlink";
	symlink($outside, $link);
	try {
		// over HTTP with the content token: nothing read or listed outside views/
		foreach (array('..', '../..', '../../media', 'default/../test', 'default/_email', '/tmp', $outside, 'nope', 'zzlink', '.', 'default ', "default\0") as $theme) {
			foreach (array(
				array('read_view', array('view' => '.mcp.json', 'theme' => $theme)),
				array('read_view', array('view' => 'secret.json', 'theme' => $theme)),
				array('read_view', array('view' => 'index.html', 'theme' => $theme)),
				array('list_views', array('theme' => $theme)),
				array('check_view', array('content' => '<p>x</p>', 'theme' => $theme)),
			) as $call) {
				$result = mcp_call($call[0], $call[1]);
				check(!empty($result['isError']), "{$call[0]} accepted theme ".json_encode($theme));
				check(strpos(json_encode($result), 'sk-live-123') === false, 'nothing from outside');
			}
		}
		$refused = mcp_call('read_view', array('view' => 'index.html', 'theme' => '../..'));
		check(strpos($refused['content'][0]['text'], 'theme') !== false, 'the error names the theme: '.$refused['content'][0]['text']);
		// a real second theme still works, and so does the site's own
		$other = mcp_call('read_view', array('view' => 'index.html', 'theme' => 'test'));
		check(empty($other['isError']), 'another theme: '.json_encode($other));
		same('test', $other['structuredContent']['theme']);
		check(in_array('index.html', mcp_call('list_views', array('theme' => 'test'))['structuredContent']['views']), 'list_views in another theme');
		same('default', mcp_call('list_views')['structuredContent']['theme']);
		same('default', mcp_call('list_views', array('theme' => ''))['structuredContent']['theme'], 'an empty theme is the site\'s');
		// over stdio, write_view can't write outside views/ either
		$good = '<p>pwned</p>';
		$answers = mcp_stdio_calls(array(
			array('write_view', array('view' => 'zz-pwned.html', 'content' => $good, 'theme' => '../../media')),
			array('write_view', array('view' => 'zz-pwned.html', 'content' => $good, 'theme' => '..')),
			array('write_view', array('view' => 'zz-pwned.html', 'content' => $good, 'theme' => 'zzlink')),
			array('write_view', array('view' => 'zz-pwned.html', 'content' => $good, 'theme' => 'default/_email')),
		));
		foreach (array(0, 1, 2, 3) as $i) check(!empty($answers[$i]['isError']), "write_view $i was not refused: ".json_encode(isset($answers[$i]) ? $answers[$i] : null));
		check(!file_exists("$root/media/zz-pwned.html"), 'nothing written into media/');
		check(!file_exists("$root/application/zz-pwned.html"), 'nothing written above views/');
		check(!file_exists("$outside/zz-pwned.html"), 'nothing written through a link');
		check(!file_exists("$root/application/views/default/_email/zz-pwned.html"), 'nor in a folder inside a theme');
	} finally {
		@unlink($link);
		foreach (array('media', 'application', 'application/views/default/_email') as $folder) @unlink("$root/$folder/zz-pwned.html");
		foreach (glob("$outside/*") as $file) unlink($file);
		@rmdir($outside);
	}
});

// a view file that is a link is followed only while it stays inside the theme (#72)
test('mcp view tools refuse a view file linked outside the theme', function () use ($root) {
	$outside = sys_get_temp_dir().'/raster-viewlink-'.getmypid();
	@mkdir($outside);
	file_put_contents("$outside/secret.json", '{"secret":"sk-live-456"}');
	file_put_contents("$outside/target.html", '<p>untouched</p>');
	$theme = "$root/application/views/default";
	$links = array(
		"$theme/zzr25file.json" => "$outside/secret.json",
		"$theme/zzr25target.html" => "$outside/target.html",
		"$theme/zzr25gone.html" => "$outside/not-there.html",
		"$theme/zzr25inside.html" => "$theme/index.html",
	);
	foreach ($links as $link => $target) symlink($target, $link);
	try {
		foreach (array('zzr25file.json', 'zzr25target.html', 'zzr25gone.html') as $view) {
			$result = mcp_call('read_view', array('view' => $view));
			check(!empty($result['isError']), "read_view followed $view out of the theme");
			check(strpos(json_encode($result), 'sk-live-456') === false, 'nothing from outside');
			check(!empty(mcp_call('check_view', array('content' => '<p>x</p>', 'view' => $view))['isError']), "check_view took $view");
		}
		$listed = mcp_call('list_views')['structuredContent']['views'];
		check(!in_array('zzr25file.json', $listed) && !in_array('zzr25target.html', $listed) && !in_array('zzr25gone.html', $listed), 'list_views lists no link leading out: '.json_encode($listed));
		// a link that stays inside the theme still works
		check(in_array('zzr25inside.html', $listed), 'a link inside the theme is listed');
		$inside = mcp_call('read_view', array('view' => 'zzr25inside.html'));
		check(empty($inside['isError']), 'a link inside the theme reads: '.json_encode($inside));
		$answers = mcp_stdio_calls(array(
			array('write_view', array('view' => 'zzr25target.html', 'content' => '<p>pwned</p>')),
			array('write_view', array('view' => 'zzr25gone.html', 'content' => '<p>pwned</p>')),
		));
		foreach (array(0, 1) as $i) check(!empty($answers[$i]['isError']), "write_view $i went through a link: ".json_encode(isset($answers[$i]) ? $answers[$i] : null));
		same('<p>untouched</p>', file_get_contents("$outside/target.html"), 'the target is not overwritten');
		check(!file_exists("$outside/not-there.html"), 'nor created through a dangling link');
	} finally {
		foreach (array_keys($links) as $link) @unlink($link);
		foreach (glob("$outside/*") as $file) unlink($file);
		@rmdir($outside);
	}
});

// ## 2.1.8 batch H: row loop
class test_many {
	function rows() {
		$rows = array();
		for ($i = 1; $i <= 2000; $i++) $rows[] = array('n' => "r$i");
		return $rows;
	}
	function word() { return 'filled'; }
	function nothing() { return false; }
	function link() { return '/go'; }
}
test('a print in each of 2,000 rows fills every row, each copy decided on its own (#54)', function () {
	$file = sys_get_temp_dir().'/raster-many-'.getmypid().'.html';
	file_put_contents($file, '<ul><!-- render.test_many.rows --><li>'
		.'<!-- print.feed.site_url -->SITE<!-- /print.feed.site_url -->'
		.'<!-- print.if.static --><b>STAFF</b><!-- /print.if.static -->'
		.'<!-- print.if.shown --><i>shown</i><!-- /print.if.shown -->'
		.'<!-- print.test_many.word -->MOCK<!-- /print.test_many.word -->'
		.'<!-- print.test_many.word /-->'
		.'<!-- print.test_many.nothing --><em><!-- print.n -->r0<!-- /print.n --></em><!-- /print.test_many.nothing -->'
		.'<!-- print.@href.test_many.link --><a href="#">a</a><!-- /print.@href.test_many.link -->'
		.'</li><!-- /render.test_many.rows --></ul>');
	controller::instance()->objects['test_many'] = new test_many();
	try {
		$started = microtime(true);
		$html = controller::render_view($file, array('shown' => true));
		$took = microtime(true) - $started;
	} finally {
		unset(controller::instance()->objects['test_many']);
		unlink($file);
	}
	same(2000, substr_count($html, '<li>'), 'rows:');
	same(0, substr_count($html, '<!-- print.'), 'annotations left:');
	same(0, substr_count($html, 'SITE'), 'mock-up site urls left:');
	same(0, substr_count($html, 'STAFF'), 'hidden blocks shown:');
	same(2000, substr_count($html, '<i>shown</i>'), 'shown blocks:');
	same(4000, substr_count($html, 'filled'), 'printed values:');
	same(0, substr_count($html, 'MOCK'), 'mock-up values left:');
	// false keeps each copy's own default, its row's value
	same(1, substr_count($html, '<em>r1</em>'));
	same(1, substr_count($html, '<em>r2000</em>'));
	same(2000, preg_match_all('#<a href="[^"]*/go">#', $html), 'attributes set:');
	check($took < 2, "rendered in {$took}s");
});

// ## 2.1.8 wave 2: sign-up, account email change

test('create_user never changes an account that has the login; save_user still does (#77)', function () {
	authentication::connect();
	$id = authentication::create_user('only-once@example.test', 'first password', 'member', 'First');
	check($id > 0, 'a new account');
	same(null, authentication::create_user('ONLY-ONCE@example.test', 'second password', 'admin', 'Second'), 'the same email, in other letters');
	$user = R::load('user', $id);
	same(array('member', 'First'), array((string)$user->role, (string)$user->name), 'nothing changed');
	check(password_verify('first password', $user->password), 'the password is kept');
	same(1, (int)R::count('user', ' LOWER(email) = ? ', array('only-once@example.test')));
	same($id, authentication::save_user('only-once@example.test', 'second password', null), 'raster user updates it');
	check(password_verify('second password', R::load('user', $id)->password));
	same('member', (string)R::load('user', $id)->role, 'and keeps the role it is not given');
});
test('a transaction ends quietly when MySQL already committed it, as it does on a new table (#77)', function () {
	$finish = new ReflectionMethod('cms_records', 'finish');
	$finish->setAccessible(true);
	// a driver that isn't SQLite, after its implicit commit: nothing is open
	$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	$pdo->exec('CREATE TABLE t (n INTEGER)');
	$pdo->beginTransaction();
	$pdo->exec('INSERT INTO t VALUES (1)');
	$pdo->commit();
	$finish->invoke(null, $pdo, false, 'COMMIT');
	$finish->invoke(null, $pdo, false, 'ROLLBACK');
	// one still open is committed, or rolled back
	$pdo->beginTransaction();
	$pdo->exec('INSERT INTO t VALUES (2)');
	$finish->invoke(null, $pdo, false, 'COMMIT');
	$pdo->beginTransaction();
	$pdo->exec('INSERT INTO t VALUES (3)');
	$finish->invoke(null, $pdo, false, 'ROLLBACK');
	same(false, $pdo->inTransaction());
	same(array('1', '2'), array_map('strval', $pdo->query('SELECT n FROM t ORDER BY n')->fetchAll(PDO::FETCH_COLUMN)));
});

// ## 2.1.8 wave 2: newsletter sign-ups
// (wave 2 newsletter part adds its tests here)

// ## 2.1.8 wave 2: content saves and the cache bump
// (wave 2 content part adds its tests here)

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
