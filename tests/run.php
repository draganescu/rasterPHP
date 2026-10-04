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
// (batch A adds its tests here)

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
		."\tstatic function api() { return array('boom' => 'visitor', 'needs' => 'visitor', 'inner' => 'visitor', 'down' => 'visitor'); }\n"
		."\tstatic function listens() { return array('route_set' => 'trip'); }\n"
		."\tfunction boom() { throw new RuntimeException('boom secret'); }\n"
		."\tfunction needs(\$a, \$b) { return \$a.\$b; }\n"
		."\tfunction inner() { return str_repeat('x'); }\n"
		."\tfunction down() { throw new PDOException('the database secret is unreachable'); }\n"
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
		// a database that can't be reached: try again later
		list($status, $body) = http('GET', "$prod/api/zzboom/down");
		same(503, $status, $body);
		check(strpos($body, 'secret') === false, $body);
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
	} finally {
		array_map('unlink', glob("$bad*") ?: array());
	}
});

// ## 2.1.8 batch C: list SQL
// (batch C adds its tests here)

// ## 2.1.8 batch D: accounts
// (batch D adds its tests here)

// ## 2.1.8 batch E: template output
// (batch E adds its tests here)

// ## 2.1.8 batch F: upgrade tooling
// (batch F adds its tests here)

// ## 2.1.8 batch G: MCP themes
// (batch G adds its tests here)

// ## 2.1.8 batch H: row loop
// (batch H adds its tests here)

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
