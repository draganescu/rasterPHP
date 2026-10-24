<?php
// Raster test suite. No dependencies: `php tests/run.php`
//
// Runs unit tests against the template tools and integration tests against
// the demo site in application/, using a throwaway SQLite database and PHP's
// built in server. On MySQL:
//   RASTER_DB=mysql://root@127.0.0.1:3306/raster php tests/run.php
// Only some tests: RASTER_TEST_ONLY='page cache' php tests/run.php

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
require __DIR__.'/db.php';
// what a killed run left in the checkout (see test_sweep() in db.php)
test_sweep($root, array('zz*', '_zz*'), array('application/views/default', 'application/views/default/_email', 'application/views', 'application/models', 'system/models'));
$db = test_db(sys_get_temp_dir().'/raster-test-'.getmypid().'.sqlite');
$maildir = sys_get_temp_dir().'/raster-mail-'.getmypid();
putenv("RASTER_DB=$db");
putenv("RASTER_MAIL=log://$maildir");
putenv('RASTER_ENV=development');
putenv('RASTER_SECRET=test-secret');

require_once $root.'/system/boot.php';
boot::$appname = 'application';
boot::cli();
require_once BASE.'tools/inspector.php';
require_once BASE.'tools/schema.php';
require_once BASE.'tools/project.php';
require_once BASE.'models/cms/cms.php';

$passed = 0; $failed = array(); $skipped = array(); $current = '';
function test($name, $fn) {
	global $passed, $failed, $skipped, $current;
	// RASTER_TEST_ONLY=<words> runs only the tests whose name has them
	if (getenv('RASTER_TEST_ONLY') && stripos($name, getenv('RASTER_TEST_ONLY')) === false) return;
	$current = $name;
	try { $fn(); $passed++; echo "."; }
	catch (test_skipped $e) { $skipped[] = "$name: ".$e->getMessage(); echo "s"; }
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
	// a literal route's URLs are its page, whatever parameters follow (#110)
	same('/blog/post', cms::slug_for_uri('/blog/post/id/7', 'blog/post'));
	same('/blog/post', cms::slug_for_uri('/blog/post', 'blog/post'));
	same('/specials', cms::slug_for_uri('/specials/day/friday/menu_page/2', 'specials'));
	same('/specials', cms::slug_for_uri('specials/day/friday', '/specials/'));
	// a pattern that isn't literal keeps the URL's own slug
	same('/blog/7', cms::slug_for_uri('/blog/7', 'blog/[0-9]+'));
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
// every new site starts from this theme: a plain page in the browser's own colours, so there is no look to drift back to (raster-builder#161)
test('the starter style has no colours', function () use ($root) {
	$css = file_get_contents("$root/application/views/default/style.css");
	check(!preg_match('/#[0-9a-f]{3,8}\b|\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix)\(/i', $css, $m), 'a colour in the starter: '.($m[0] ?? ''));
	check(!preg_match('/(^|[;{\s])(color|background(-color)?|border(-[a-z]+)?-color)\s*:/i', $css, $m), 'the starter sets a colour: '.($m[0] ?? ''));
	check(stripos($css, 'prefers-color-scheme') === false, 'the starter picks a dark mode');
});
test('content model from the demo theme', function () {
	$inspector = new raster_inspector();
	$model = $inspector->content_model();
	$pages = array();
	foreach ($model['pages'] as $page) $pages[$page['url']] = array_keys($page['fields']);
	same(array('title', 'headline', 'intro'), $pages['/']);
	same(array('title', 'heading', 'body'), $pages['/about']);
	same(array('headline', 'date', 'summary', 'body'), array_keys($model['collections']['news']['fields']));
	foreach ($model['pages'] as $page) if ($page['url'] === '/') same('Hello, and welcome', $page['fields']['headline']['default']);
	same(array('site_name', 'site_footer'), $pages['site']);
});
// the inspector reads a view as the engine does: script-form annotations are
// annotations, and what is inside remove blocks is gone (#108)
test('lint and the content model read script-form annotations and skip remove blocks', function () {
	check(has_problem(lint_html('<script>var t = "/*- print.site.no_such_method /-*/";</script>'), "no public method 'no_such_method'"), 'a script-form call is linted');
	same(array(), array_values(array_filter(lint_html('<style>/*- print.site.year /-*/ /* ok -*/</style>'), function ($p) { return $p['severity'] === 'error'; })), 'a valid one is clean');
	// a CSS or JS comment that starts with /*- but ends with */ is no annotation
	// and must not run on to the next --> and swallow a real one
	$stray = lint_html('<style>/*- header */ h1{color:red}</style><h1><!-- print.site.year -->T<!-- /print.site.year --></h1>');
	same(array(), array_values(array_filter($stray, function ($p) { return $p['severity'] === 'error'; })), 'an unclosed /*- comment hides nothing: '.json_encode($stray));
	// a long comment (an old mock-up left in, a big inline svg after a stray
	// /*-) still lets the annotations after it be read
	$long = '<!-- old mock-up: '.str_repeat("<p>lorem ipsum</p>\n", 2000).' --><h1><!-- print.site.no_such_method -->T<!-- /print.site.no_such_method --></h1>';
	check(has_problem(lint_html($long), "no public method 'no_such_method'"), 'an annotation after a 38 KB comment is linted');
	$tokens = raster_inspector::tokenize('<style>/*- layout */ body{}</style><svg>'.str_repeat('<path d="M0 0L10 10"/>', 4000).'</svg><!-- print.cms.title /-->');
	same('print.cms.title', $tokens ? $tokens[count($tokens) - 1]['name'] : null, 'an annotation after a stray /*- and a big svg is read');
	$fix = lint_html('<script>var t = "/*-print.site.year /-*/";</script>');
	check(has_problem($fix, 'write it exactly as /*- print.site.year /-*/'), 'its fix keeps the script form: '.json_encode($fix));
	$dir = sys_get_temp_dir().'/raster-probe-'.getmypid();
	@mkdir("$dir/probe", 0775, true);
	file_put_contents("$dir/probe/probe.html", '<style>/*- header */ h1{color:red}</style><h1><!-- print.cms.heading -->Hi<!-- /print.cms.heading --></h1>'
		.'<script>var tagline = "/*- print.cms.tagline /-*/";</script>'
		.'<!-- remove --><p><!-- print.cms.old_blurb -->x<!-- /print.cms.old_blurb --></p>'
		.'<!-- render.cms.ghosts --><b><!-- print.name -->Boo<!-- /print.name --></b><!-- /render.cms.ghosts --><!-- /remove -->'
		.'<!-- render.cms.zzprobes --><!-- print.title -->A<!-- /print.title --><!-- remove --><!-- print.ghost -->g<!-- /print.ghost --><!-- /remove --><!-- /render.cms.zzprobes -->');
	try {
		$model = (new raster_inspector($dir, 'probe'))->content_model();
		$fields = array();
		foreach ($model['pages'] as $page) if ($page['view'] === 'probe.html') $fields = array_keys($page['fields']);
		same(array('heading', 'tagline'), $fields, 'page fields');
		check(!isset($model['collections']['ghosts']), 'a collection inside remove is not one');
		same(array('title'), array_keys($model['collections']['zzprobes']['fields']), 'a field inside remove is not one');
	} finally {
		@unlink("$dir/probe/probe.html");
		@rmdir("$dir/probe");
		@rmdir($dir);
	}
});

test('a page request loads no command line tools and looks up no core overrides', function () use ($root) {
	$dir = sys_get_temp_dir().'/raster-loads-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php $GLOBALS["looked_up"] = array(); spl_autoload_register(function ($c) { $GLOBALS["looked_up"][] = $c; }, true, true);'
		.' register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(array("looked_up" => array_values(array_unique($GLOBALS["looked_up"])), "included" => get_included_files()))); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite"));
	shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /about 2>&1');
	$out = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	check(is_array($out), 'no report from the render');
	same(array(), array_values(array_filter($out['included'], function ($f) { return strpos($f, '/system/tools/') !== false; })), 'tools loaded');
	same(array(), array_values(array_intersect($out['looked_up'], array('the_config', 'the_log', 'the_template', 'the_controller', 'the_database'))), 'autoloader asked');
});

// ## One model lookup (#35 #42)

// a model's files, written for one test and removed after it
function temp_model($dir, $files) {
	test_fixture($dir);
	@mkdir($dir, 0775, true);
	foreach ($files as $name => $source) file_put_contents("$dir/$name", $source);
}
function remove_dir($dir) {
	if (!is_dir($dir)) return;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
	rmdir($dir);
}

test('one lookup: app beats bundled, the_ overrides bundled', function () {
	same('system', controller::model('feed')['bundled'] ? 'system' : 'app');
	$app = APPBASE.config::get('models_path', 'models');
	temp_model(BASE.'models/zz', array('zz.php' => "<?php\nclass zz\n{\n\tstatic function listens() {\n\t\treturn array('zz.happened' => 'noted');\n\t}\n\tfunction noted() {}\n}\n"));
	temp_model("$app/the_zz", array('the_zz.php' => "<?php\nclass the_zz extends zz\n{\n}\n"));
	temp_model("$app/feed", array('feed.php' => "<?php\nclass feed\n{\n}\n"));
	try {
		controller::forget_models();
		$zz = controller::model('zz');
		same('the_zz', $zz['class']);
		same(BASE.'models/zz/zz.php', $zz['parent']);
		same(array("$app/the_zz", BASE.'models/zz'), $zz['dirs']);
		same('the_zz', controller::model_class('zz'));
		check(class_exists('zz', false), 'the bundled zz is loaded as the parent');
		// the site's own feed, in a fresh process: the bundled one is never read,
		// and zz's listens() is bound once, for zz
		$out = race_php('$m = controller::model("feed"); echo json_encode(array($m["bundled"], controller::model_class("feed"), get_included_files(), event::bindings()["zz.happened"]));', 1);
		$out = json_decode($out[0], true);
		check(is_array($out), 'the process said: '.json_encode($out));
		same(false, $out[0]);
		same('feed', $out[1]);
		check(!in_array(BASE.'models/feed/feed.php', $out[2], true), 'the bundled feed.php was loaded');
		same(array('zz.noted'), $out[3], 'zz.happened listeners:');
	} finally {
		remove_dir(BASE.'models/zz');
		remove_dir("$app/the_zz");
		remove_dir("$app/feed");
		controller::forget_models();
	}
});

test('listeners run in order: the framework\'s, the_events.php, the site\'s listens(), core (#27)', function () {
	// this process: the framework's bindings, a site's, then core
	event::bind('done')->to('site', 'zz_done');
	event::bind('route_set')->to('site', 'zz_set');
	try {
		$bindings = event::bindings();
		same(array('cms.setup', 'authentication.setup', 'site.zz_set'), $bindings['route_set'], 'route_set:');
		same(array('site.zz_done', 'controller.output', 'log.output'), $bindings['done'], 'done:');
	} finally {
		event::unbind('done')->from('site', 'zz_done');
		event::unbind('route_set')->from('site', 'zz_set');
	}
	// a bundled model's listens() goes with the framework's bindings, before
	// the site's, so a site listener sees what the bundled one did
	$app = APPBASE.config::get('models_path', 'models');
	$events = APPBASE.'config/the_events.php';
	$kept = file_get_contents($events);
	// the starter site's is empty: a binding needs the <?php
	$php = strpos($kept, '<?php') === 0 ? $kept : "<?php\n";
	temp_model(BASE.'models/zzpay', array('zzpay.php' => "<?php\nclass zzpay\n{\n\tstatic function listens() {\n\t\treturn array('zz.paid' => 'mark');\n\t}\n\tfunction mark() { echo 'bundled '; }\n}\n"));
	temp_model("$app/zzshop", array('zzshop.php' => "<?php\nclass zzshop\n{\n\tstatic function listens() {\n\t\treturn array('zz.paid' => 'see');\n\t}\n\tfunction see() { echo 'listens '; }\n\tfunction bound() { echo 'the_events '; }\n}\n"));
	$paid = 'echo json_encode(event::bindings()["zz.paid"])."\n"; event::dispatch("zz.paid");';
	try {
		file_put_contents($events, $php."\nevent::bind('zz.paid')->to('zzshop', 'bound');\n");
		$out = explode("\n", race_php($paid, 1)[0]);
		same(array('zzpay.mark', 'zzshop.bound', 'zzshop.see'), json_decode($out[0], true), 'zz.paid listeners:');
		same('bundled the_events listens ', $out[1], 'zz.paid ran:');
		// an override's listens() counts for the bundled model, in its place
		temp_model("$app/the_zzpay", array('the_zzpay.php' => "<?php\nclass the_zzpay extends zzpay\n{\n}\n"));
		$out = explode("\n", race_php($paid, 1)[0]);
		same('bundled the_events listens ', $out[1], 'zz.paid ran, with the_zzpay:');
		remove_dir("$app/the_zzpay");
		// and the site can unbind it in the_events.php
		file_put_contents($events, $php."\nevent::unbind('zz.paid')->from('zzpay', 'mark');\n");
		$out = explode("\n", race_php($paid, 1)[0]);
		same(array('zzshop.see'), json_decode($out[0], true), 'zz.paid listeners, zzpay.mark unbound:');
		same('listens ', $out[1], 'zz.paid ran, zzpay.mark unbound:');
	} finally {
		file_put_contents($events, $kept);
		remove_dir(BASE.'models/zzpay');
		remove_dir("$app/the_zzpay");
		remove_dir("$app/zzshop");
		controller::forget_models();
	}
});

test('the_mail reaches mail sent by newsletter', function () use ($maildir) {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/the_mail", array('the_mail.php' => "<?php\nclass the_mail extends mail\n{\n\tstatic function send(\$to, \$subject, \$html, \$text = null, \$headers = array()) {\n\t\treturn parent::send(\$to, '[T] '.\$subject, \$html, \$text, \$headers);\n\t}\n}\n"));
	$dir = "$maildir-the-mail";
	try {
		$out = race_php('newsletter::subscribe("a@b.test");', 1, array('RASTER_MAIL' => "log://$dir"));
		same('', implode('', $out), 'the process said:');
		$mails = glob("$dir/*.eml") ?: array();
		same(1, count($mails), 'mails:');
		check(preg_match('/^Subject: (.*(?:\r?\n[ \t].*)*)/m', file_get_contents($mails[0]), $m), 'no subject');
		same('[T] ', substr(mb_decode_mimeheader($m[1]), 0, 4), 'the subject starts:');
	} finally {
		remove_dir("$app/the_mail");
		remove_dir($dir);
		controller::forget_models();
	}
});

test('warm requests load no model that only listens', function () use ($root) {
	$app = APPBASE.config::get('models_path', 'models');
	$dir = sys_get_temp_dir().'/raster-warm-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(get_included_files())); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite"));
	$render = function () use ($env, $dir, $root) {
		@unlink("$dir/out.json");
		$page = shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /about 2>&1');
		$included = json_decode((string)@file_get_contents("$dir/out.json"), true);
		check(is_array($included), 'no report from the render: '.$page);
		return $included;
	};
	temp_model("$app/zzlisten", array('zzlisten.php' => "<?php\nclass zzlisten\n{\n\tstatic function listens() {\n\t\treturn array('zz.never' => 'heard');\n\t}\n\tfunction heard() {}\n}\n"));
	try {
		$render();
		$included = $render();
		$listening = array_values(array_filter($included, function ($f) {
			return preg_match('#/models/([^/]+)/\1\.php$#', $f) && strpos(file_get_contents($f), 'function listens(') !== false;
		}));
		same(array(), $listening, 'models loaded only for their listens():');
		$out = race_php('echo json_encode(array(event::bindings()["zz.never"], in_array('.var_export("$app/zzlisten/zzlisten.php", true).', get_included_files(), true)));', 1);
		same(array(array('zzlisten.heard'), false), json_decode($out[0], true), 'bound from the cache, without the file:');
		// a model added in development counts on the next request
		temp_model("$app/zzlater", array('zzlater.php' => "<?php\nclass zzlater\n{\n\tstatic function listens() {\n\t\treturn array('zz.later' => 'heard');\n\t}\n\tfunction heard() {}\n}\n"));
		$out = race_php('$b = event::bindings(); echo json_encode(isset($b["zz.later"]) ? $b["zz.later"] : null);', 1);
		same(array('zzlater.heard'), json_decode($out[0], true), 'zz.later listeners:');
	} finally {
		remove_dir("$app/zzlisten");
		remove_dir("$app/zzlater");
		array_map('unlink', glob("$dir/*") ?: array());
		@rmdir($dir);
		controller::forget_models();
	}
});

test('a broken, stale or unwritable models.json never stops a page', function () use ($root) {
	$cache = raster_cache::dir().'models.json';
	$dir = sys_get_temp_dir().'/raster-stale-'.getmypid();
	@mkdir($dir);
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite"));
	$render = function () use ($env, $root) {
		// the form stamps differ by the second they were made in
		return preg_replace('/(name="raster_ts" value=")[^"]*/', '$1', (string)shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' render /about 2>&1'));
	};
	$data = function () {
		$all = array();
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPBASE.'data', FilesystemIterator::SKIP_DOTS)) as $file) {
			if (strpos($file->getPathname(), APPBASE.'data/cache/') !== 0 && strpos($file->getFilename(), 'raster.sqlite') !== 0) $all[] = $file->getPathname();
		}
		sort($all);
		return $all;
	};
	$before = $data();
	try {
		$fresh = $render();
		check(strpos($fresh, '<html') !== false, 'the page: '.$fresh);
		foreach (array('{"models": {"key"', '{"models":{"key":"2.2.6","value":{"feed":"x"}}}', '"just a string"') as $saved) {
			file_put_contents($cache, $saved);
			same($fresh, $render(), "the page with models.json $saved:");
			$read = json_decode((string)file_get_contents($cache), true);
			check(isset($read['models']['value']['feed']['declares']), 'models.json is written again: '.file_get_contents($cache));
		}
		// a data/ folder the server can't write to: a fresh scan every time
		@unlink($cache);
		chmod(raster_cache::dir(), 0555);
		if (is_writable(raster_cache::dir())) throw new test_skipped('this user writes to read-only folders');
		same($fresh, $render(), 'the page with a read-only cache:');
		check(!is_file($cache), 'models.json was written');
	} finally {
		@chmod(raster_cache::dir(), 0775);
		array_map('unlink', glob("$dir/*") ?: array());
		@rmdir($dir);
	}
	same($before, $data(), 'files written outside data/cache:');
});

test('bundled types count only where views use them', function () {
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme').'/zzbook-test.html';
	temp_model(BASE.'models/zzbook', array('zzbook.php' => "<?php\nclass zzbook\n{\n\tstatic function types() {\n\t\treturn array('zzbook' => array('fields' => array('name' => '')));\n\t}\n}\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		check(!isset(cms_records::types()['zzbook']), 'zzbook counts without a view');
		test_fixture($view);
		file_put_contents($view, "<!-- render.zzbook.form -->\n<p>x</p>\n<!-- /render.zzbook.form -->\n");
		controller::forget_models();
		cms_records::forget();
		check(isset(cms_records::types()['zzbook']), 'zzbook does not count with a view that renders it');
	} finally {
		@unlink($view);
		remove_dir(BASE.'models/zzbook');
		controller::forget_models();
		cms_records::forget();
	}
});

test('an override\'s own types count without a view', function () {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model(BASE.'models/zzpost', array('zzpost.php' => "<?php\nclass zzpost\n{\n}\n"));
	temp_model("$app/the_zzpost", array('the_zzpost.php' => "<?php\nclass the_zzpost extends zzpost\n{\n\tstatic function types() {\n\t\treturn array('zzoutbox' => array('fields' => array('to' => '')));\n\t}\n}\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		check(isset(cms_records::types()['zzoutbox']), 'the_zzpost\'s zzoutbox does not count');
	} finally {
		remove_dir(BASE.'models/zzpost');
		remove_dir("$app/the_zzpost");
		controller::forget_models();
		cms_records::forget();
	}
});

test('the MCP vocabulary sees a model added while the server runs', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$names = function () {
		$answer = (new mcp())->call_tool('vocabulary', array());
		return json_encode($answer);
	};
	try {
		check(strpos($names(), 'zznew') === false, 'zznew is there before it is written');
		temp_model("$app/zznew", array('zznew.php' => "<?php\nclass zznew\n{\n\tfunction hello() {}\n}\n"));
		check(strpos($names(), 'zznew') !== false, 'zznew is not in the second answer');
	} finally {
		remove_dir("$app/zznew");
		controller::forget_models();
		cms_records::forget();
	}
});

test('a site model named like a bundled one wins and lint says so', function () use ($root, $db) {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/newsletter", array('newsletter.php' => "<?php\nclass newsletter\n{\n}\n"));
	try {
		$out = (string)shell_exec('RASTER_ENV=development RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' lint 2>&1');
		check(strpos($out, 'models/newsletter shadows the bundled newsletter') !== false, 'lint said: '.$out);
	} finally {
		remove_dir("$app/newsletter");
		controller::forget_models();
	}
});

test('required emails come from the model', function () {
	$inspector = new raster_inspector();
	$forgot = "<!-- render.authentication.forgot --><form method=\"post\"><input type=\"email\" name=\"email\" required></form><!-- /render.authentication.forgot -->";
	// the test theme has no _email/ folder; the default theme has the view
	check(has_problem($inspector->lint_source($forgot, 'view', 'test'), 'which does not exist'), 'the missing _email/password_reset');
	check(!has_problem($inspector->lint_source($forgot, 'view', 'default'), 'which does not exist'), 'the default theme has _email/password_reset');
	// what the bundled models say they need
	$pages = controller::required('pages');
	foreach (array('login', 'register', 'forgot', 'reset', 'account', 'newsletter-confirm', 'newsletter-unsubscribe') as $page) check(in_array($page, $pages, true), "$page is not among the required pages: ".json_encode($pages));
	$settings = controller::required('settings');
	foreach (array('registration', 'password_min_length', 'newsletter_double_opt_in', 'newsletter_confirm_page') as $setting) check(in_array($setting, $settings, true), "$setting is not among the required settings");
	same(array('_email/newsletter_confirm'), controller::required('emails')['render.newsletter.signup']);
	// a site model's requires() counts the same way
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/zzneeds", array('zzneeds.php' => "<?php\nclass zzneeds\n{\n\tstatic function requires() {\n\t\treturn array('emails' => array('render.zzneeds.ask' => array('_email/zz_answer')), 'pages' => array('zz-thanks'), 'settings' => array('zz_staff'));\n\t}\n\tfunction ask() { return false; }\n}\n"));
	// a bundled model's only where a view uses it
	temp_model(BASE.'models/zzunused', array('zzunused.php' => "<?php\nclass zzunused\n{\n\tstatic function requires() {\n\t\treturn array('pages' => array('zz-unused'), 'emails' => array('render.zzunused.ask' => array('_email/zz_unused')));\n\t}\n}\n"));
	try {
		controller::forget_models();
		$problems = $inspector->lint_source('<!-- render.zzneeds.ask -->x<!-- /render.zzneeds.ask -->', 'view', 'default');
		check(has_problem($problems, 'render.zzneeds.ask sends an email written in'), 'lint said: '.json_encode($problems));
		check(has_problem($problems, 'zz_answer'), 'it names the view');
		check(in_array('zz-thanks', controller::required('pages'), true), 'the site model\'s page');
		check(in_array('zz_staff', controller::required('settings'), true), 'the site model\'s setting');
		check(!in_array('zz-unused', controller::required('pages'), true), 'a bundled model no view uses');
		// a draft that uses it is linted for its emails all the same: the
		// tag in it is the use (MCP checks views before they are written)
		check(has_problem($inspector->lint_source('<!-- render.zzunused.ask -->x<!-- /render.zzunused.ask -->', 'view', 'default'), 'zz_unused'), 'the draft\'s missing email view');
	} finally {
		remove_dir("$app/zzneeds");
		remove_dir(BASE.'models/zzunused');
		controller::forget_models();
	}
});

// ## Integration tests

$port = free_port();
$base = "http://127.0.0.1:$port";
$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_MCP_TOKEN' => 'test-token', 'RASTER_MAIL' => "log://$maildir", 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
register_shutdown_function(function () use ($server, $db, $maildir) {
	proc_terminate($server); proc_close($server);
	array_map('unlink', glob("$db*") ?: array());
	array_map('unlink', glob("$maildir/*") ?: array());
	@rmdir($maildir);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

function http($method, $url, $body = null, $headers = array()) {
	// as a browser posts: the form's own stamp, shown a minute ago
	if (is_string($body) && strpos($body, 'raster_form=') !== false && strpos($body, 'raster_ts=') === false) $body .= '&raster_ts='.stamp_for_tests(60);
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
test('theme assets carry a version, pages and files say how long to keep them', function () use ($base, $root) {
	list(, $body, $headers) = http('GET', "$base/about");
	check((bool)preg_grep('#^Cache-Control: no-cache$#i', $headers), 'pages are checked each time');
	check((bool)preg_match('#href="style\.css\?v=([0-9a-f]{8})"#', $body, $m), 'the stylesheet has a version');
	$version = $m[1];
	list($status, , $headers) = http('GET', "$base/application/views/default/style.css?v=$version");
	same(200, $status);
	check((bool)preg_grep('#^Cache-Control: public, max-age=31536000, immutable$#i', $headers), 'a versioned file is kept');
	list(, , $headers) = http('GET', "$base/application/views/default/style.css");
	check((bool)preg_grep('#^Cache-Control: no-cache$#i', $headers), 'a file without a version is checked each time');
	same(403, http('GET', "$base/application/views/default/about.html?v=1")[0], 'raw views are still refused');
	// a changed file gets a new version
	$css = "$root/application/views/default/style.css";
	$time = filemtime($css);
	touch($css, $time + 10);
	try {
		list(, $body) = http('GET', "$base/about");
		check(strpos($body, 'href="style.css?v=') !== false && strpos($body, "style.css?v=$version") === false, 'a new version after a change');
	} finally {
		touch($css, $time);
	}
});
test('version_assets: only relative URLs to real theme files', function () {
	$html = '<link href="style.css"><img src="nope.png"><a href="https://x.example/style.css"></a><img src="/style.css">'
		.'<img src="style.css?x=1"><a href="#top"></a><img src="data:image/png;base64,AA"><a href="about.html"></a><a href="../../config/the_app.php"></a>'
		.'<img srcset="style.css 1x, nope.png 2x, style.css 3x"><video poster=\'style.css\'></video>';
	$out = controller::version_assets($html);
	preg_match('#style\.css\?v=[0-9a-f]{8}#', $out, $m);
	$v = $m[0];
	same('<link href="'.$v.'"><img src="nope.png"><a href="https://x.example/style.css"></a><img src="/style.css">'
		.'<img src="style.css?x=1"><a href="#top"></a><img src="data:image/png;base64,AA"><a href="about.html"></a><a href="../../config/the_app.php"></a>'
		.'<img srcset="'.$v.' 1x, nope.png 2x, '.$v.' 3x"><video poster=\''.$v.'\'></video>', $out);
	same($out, controller::version_assets($out), 'a second pass changes nothing');
});
test('template defaults become content', function () use ($base) {
	list(, $body) = http('GET', "$base/");
	check(strpos($body, '<h1>Hello, and welcome</h1>') !== false);
	check(strpos($body, 'href="'.$base.'/news/news_item/hello-world"') !== false, 'detail link');
	check(strpos($body, 'href="'.$base.'/news"') !== false, '.html links rewritten');
});
test('an extension on a routed or missing URL is never HTML labelled as another type (#120)', function () use ($base) {
	$type = function ($headers) { return (string)current(preg_grep('/^Content-Type:/i', $headers)); };
	// a missing page is the 404 page, as HTML
	list($status, $body, $headers) = http('GET', "$base/nothing-here.json");
	same(404, $status);
	check(stripos($type($headers), 'text/html') !== false, $type($headers));
	// a routed URL renders the routed view in that format: news.rss
	list($status, $body, $headers) = http('GET', "$base/news/news_page/2.rss");
	same(200, $status);
	check(stripos($type($headers), 'application/rss+xml') !== false && strpos($body, '<rss') !== false, $type($headers));
	// and is a 404 when the view has no file in it
	list($status, , $headers) = http('GET', "$base/news/news_page/2.json");
	same(404, $status);
	check(stripos($type($headers), 'text/html') !== false, $type($headers));
	// real format views are unchanged
	same(200, http('GET', "$base/news.rss")[0]);
	same(200, http('GET', "$base/sitemap.xml")[0]);
});
test('missing pages and items are 404', function () use ($base) {
	same(404, http('GET', "$base/nothing-here")[0]);
	same(404, http('GET', "$base/news/news_item/999")[0]);
	same(404, http('GET', "$base/_layout")[0]);
});
test('collection urls only for views that render the collection', function () use ($base, $root) {
	same(404, http('GET', "$base/about/zzz_page/9")[0]);
	same(404, http('GET', "$base/_layout/x_page")[0]);
	same(200, http('GET', "$base/news/news_page/1")[0]);
	// a view in a _ folder that renders a collection is still a partial
	$view = "$root/application/views/default/_email/zz_digest.html";
	test_fixture($view);
	file_put_contents($view, "<h1>zz digest</h1><!-- render.cms.news --><p><!-- print.headline -->x<!-- /print.headline --></p><!-- /render.cms.news -->");
	try {
		foreach (array('/_email/zz_digest', '/_email/zz_digest/news_page/1', '/_email/zz_digest/news_items/headline/x', '/_email/zz_digest/news_page/1.rss') as $url) {
			list($status, $body) = http('GET', $base.$url);
			same(404, $status, $url);
			check(strpos((string)$body, 'zz digest') === false, "$url shows the partial");
		}
	} finally {
		unlink($view);
	}
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
test('editors reach no accounts table and no page kept for admins', function () use ($base, $root) {
	authentication::connect();
	authentication::save_user('pages-editor@example.test', 'editor password', 'editor');
	$app = "$root/application/config/the_app.php";
	$settings = file_get_contents($app);
	file_put_contents("$root/application/views/default/zzsecret.html", '<!doctype html><html><head><title>Secret</title></head><body><p><!-- print.cms.note -->Mock<!-- /print.cms.note --></p></body></html>');
	file_put_contents($app, $settings."\nconfig::set('protected')->to(array('account' => 'member', 'zzsecret' => 'admin'));\n");
	try {
		cms_store::update_page('zzsecretpage', '/zzsecret', array('note' => 'Only for admins'), array('note'));
		list(, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => 'pages-editor@example.test', 'password' => 'editor password'));
		$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
		check(preg_match('/"csrf":"([a-f0-9]+)"/', http('GET', "$base/about", null, array("Cookie: $cookie"))[1], $m), 'editor missing');
		$post = function ($endpoint, $fields) use ($base, $cookie, $m) {
			list($status, $text) = form_post("$base/api/cms/$endpoint", $fields + array('csrf' => $m[1]), array("Cookie: $cookie"));
			return array($status, json_decode($text, true), $text);
		};
		same(403, http('GET', "$base/zzsecret", null, array("Cookie: $cookie"))[0], 'the page itself');
		foreach (array(array('editor_history', array('type' => 'zzsecretpage', 'slug' => '/zzsecret')),
			array('editor_save_field', array('type' => 'zzsecretpage', 'slug' => '/zzsecret', 'field' => 'note', 'value' => 'Changed')),
			// a slug the editor may open, with another page's table
			array('editor_save_field', array('type' => 'zzsecretpage', 'slug' => '/about', 'field' => 'note', 'value' => 'Changed')),
			array('editor_restore', array('type' => 'zzsecretpage', 'slug' => '/zzsecret', 'revision' => 1))) as $call) {
			list($status, $json, $text) = $post($call[0], $call[1]);
			same(400, $status, $call[0].' '.json_encode($call[1]).': '.$text);
			same('Unknown page', $json['error']);
		}
		same('Only for admins', cms_store::page_values('zzsecretpage')['fields']['note'], 'nothing changed');
		same(200, $post('editor_history', array('type' => 'aboutpage', 'slug' => '/about'))[0], 'a page the editor may open');
		// the accounts of the first versions, by any spelling of the name
		foreach (array('users', 'users_', 'u_sers', 'raster') as $collection) {
			foreach (array('editor_delete_item', 'editor_save_item') as $endpoint) {
				list($status, $json, $text) = $post($endpoint, array('collection' => $collection, 'id' => 1));
				same(400, $status, "$endpoint $collection: $text");
				same('Unknown collection', $json['error']);
			}
		}
	} finally {
		file_put_contents($app, $settings);
		@unlink("$root/application/views/default/zzsecret.html");
		R::exec('DROP TABLE IF EXISTS zzsecretpage');
	}
});
test('staff /api methods refuse cross-site requests on every verb (#122)', function () use ($base, $root) {
	$dir = "$root/application/models/zzstaff";
	test_fixture($dir);
	@mkdir($dir);
	file_put_contents("$dir/zzstaff.php", "<?php\nclass zzstaff {\n\tstatic function api() { return array('close_day' => 'editor', 'hours' => 'visitor'); }\n\tfunction close_day() { return 'closed'; }\n\tfunction hours() { return 'open'; }\n}\n");
	cms_store::connect();
	cms_store::create_user('editor', 'correct horse');
	try {
		list(, , $headers) = http('POST', "$base/login", 'login=editor&password=correct+horse', array('Content-Type: application/x-www-form-urlencoded'));
		$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
		foreach (array('Origin: https://evil.test', 'Origin: null', 'Sec-Fetch-Site: cross-site', 'Referer: https://evil.test/page') as $header) {
			list($status, $body) = http('GET', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie", $header));
			same(403, $status, "GET with $header");
			same(array('error' => 'cross_site', 'problems' => array('cross_site')), json_decode($body, true), "GET with $header");
		}
		list($status, $body) = http('DELETE', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie", 'Origin: https://evil.test'));
		same(403, $status, 'DELETE');
		same(array('error' => 'cross_site', 'problems' => array('cross_site')), json_decode($body, true), 'DELETE');
		same(403, http('POST', "$base/api/zzstaff/close_day", '', array("Cookie: $cookie", 'Origin: https://evil.test'))[0], 'POST');
		// the same site, as staff
		same(array(200, '"closed"'), array_slice(http('GET', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie")), 0, 2), 'same-site GET');
		same(200, http('GET', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie", "Origin: $base"))[0], 'same-site GET with Origin');
		same(200, http('GET', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie", 'Origin: null', 'Sec-Fetch-Site: same-origin'))[0], 'Origin null that the browser says is the same site');
		// a visitor method answers anyone, from anywhere
		same(array(200, '"open"'), array_slice(http('GET', "$base/api/zzstaff/hours", null, array('Origin: https://evil.test')), 0, 2), 'visitor method cross-site');
		same(200, http('GET', "$base/api/zzstaff/hours", null, array("Cookie: $cookie", 'Sec-Fetch-Site: cross-site'))[0], 'visitor method cross-site, logged in');
	} finally {
		@unlink("$dir/zzstaff.php");
		@rmdir($dir);
	}
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
	same('Hello, and welcome', $history[1]['fields']['headline']);
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
	test_fixture($view);
	file_put_contents($view, str_replace('</main>', '<p><!-- print.cms.frozen_test -->Fallback<!-- /print.cms.frozen_test --></p></main>', $original));
	try {
		$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db);
		$html = shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' render /about');
		check(strpos($html, '<p>Fallback</p>') !== false, 'frozen render failed');
		exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --check', $out, $code);
		same(1, $code, 'schema --check should report drift');
		$out = array();
		exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply 2>&1', $out, $code);
		same(0, $code, 'schema --apply: '.implode("\n", $out));
		exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --check', $out, $code);
		same(0, $code, 'schema --apply did not fix drift');
	} finally {
		test_fixture($view);
		file_put_contents($view, $original);
		exec('RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --drop=aboutpage.frozen_test');
	}
});
test('schema --apply declares model tables by their types, and a long text or big number fits in production', function () use ($root) {
	$db = test_db(sys_get_temp_dir().'/raster-model-tables-'.getmypid().'.sqlite');
	$raster = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster");
	exec("$raster schema --apply 2>&1", $out, $code);
	same(0, $code, 'schema --apply: '.implode("\n", $out));
	$pdo = test_pdo($db);
	same(0, (int)$pdo->query('SELECT COUNT(*) FROM user')->fetchColumn(), 'no row is left behind');
	$long = str_repeat('a long name ', 40);
	$code = 'require '.var_export("$root/system/boot.php", true).'; boot::$appname = "application"; boot::cli(); authentication::connect();'
		.' $u = R::dispense("user"); $u->email = "long@example.com"; $u->name = '.var_export($long, true).'; $u->failed_count = 300; R::store($u);'
		.' $s = R::dispense("subscriber"); $s->email = "long@example.com"; $s->name = '.var_export($long, true).'; R::store($s);';
	$output = shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' -r '.escapeshellarg($code).' 2>&1');
	same('', trim((string)$output), 'storing in production');
	same(array($long, '300'), array_map('strval', $pdo->query("SELECT name, failed_count FROM user WHERE email = 'long@example.com'")->fetch(PDO::FETCH_NUM)));
	same($long, $pdo->query("SELECT name FROM subscriber WHERE email = 'long@example.com'")->fetchColumn());
});
test('a page field holds text longer than 64 KB in production, and schema --apply widens the TEXT columns 2.2.30 made', function () use ($root) {
	$db = test_db(sys_get_temp_dir().'/raster-long-text-'.getmypid().'.sqlite');
	$raster = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster");
	exec("$raster schema --apply 2>&1", $out, $code);
	same(0, $code, 'schema --apply: '.implode("\n", $out));
	// a page table and one of its text fields
	$status = json_decode(shell_exec("$raster schema --json 2>/dev/null"), true);
	$table = $field = null;
	foreach ($status['tables'] as $t) {
		if ($t['kind'] !== 'page' || !$t['exists']) continue;
		foreach ($t['fields'] as $name => $f) if ($f['type'] === 'text' && $name !== 'slug') { $table = $t['table']; $field = $name; break 2; }
	}
	check($table, 'a page with a text field');
	$pdo = test_pdo($db);
	if (on_mysql()) {
		same('longtext', strtolower($pdo->query("SHOW COLUMNS FROM `$table` LIKE '$field'")->fetch(PDO::FETCH_ASSOC)['Type']));
		// the way 2.2.30 declared it
		$pdo->exec("ALTER TABLE `$table` MODIFY `$field` TEXT");
		$out = array();
		exec("$raster schema --check 2>&1", $out, $code);
		same(1, $code, 'schema --check reports the TEXT column');
		check(strpos(implode("\n", $out), "$field (a MySQL column too small for long text; run --apply)") !== false, implode("\n", $out));
		$out = array();
		exec("$raster schema --apply 2>&1", $out, $code);
		same(0, $code, 'schema --apply: '.implode("\n", $out));
		check(strpos(implode("\n", $out), "widened $table.$field to LONGTEXT") !== false, implode("\n", $out));
		same('longtext', strtolower($pdo->query("SHOW COLUMNS FROM `$table` LIKE '$field'")->fetch(PDO::FETCH_ASSOC)['Type']));
		$out = array();
		exec("$raster schema --check 2>&1", $out, $code);
		same(0, $code, 'no drift after --apply: '.implode("\n", $out));
	}
	$long = str_repeat('A long page. ', 8000); // 104 KB
	$code = 'require '.var_export("$root/system/boot.php", true).'; boot::$appname = "application"; boot::cli(); cms_store::connect();'
		.' $b = R::findOne('.var_export($table, true).') ?: R::dispense('.var_export($table, true).'); $b->'.$field.' = '.var_export($long, true).'; R::store($b);';
	$output = shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' -r '.escapeshellarg($code).' 2>&1');
	same('', trim((string)$output), 'storing in production');
	same($long, $pdo->query("SELECT `$field` FROM `$table`")->fetchColumn());
});
test('schema --apply widens the small MySQL columns that 2.1.8 made for model tables', function () use ($root) {
	if (!on_mysql()) throw new test_skipped('MySQL only: SQLite columns take any length');
	$db = test_db(sys_get_temp_dir().'/raster-narrow-tables-'.getmypid().'.sqlite');
	// the way 2.1.8 made them: a row of the defaults, stored and deleted
	$code = 'require '.var_export("$root/system/boot.php", true).'; boot::$appname = "application"; boot::cli(); authentication::connect();'
		.' foreach (authentication::schema() as $t => $fields) { $b = R::dispense($t); foreach ($fields as $f => $v) $b->$f = $v; R::store($b); R::trash($b); }';
	same('', trim((string)shell_exec('RASTER_DB='.escapeshellarg($db).' '.php_cmd().' -r '.escapeshellarg($code).' 2>&1')), 'making the old tables');
	$pdo = test_pdo($db);
	check(stripos($pdo->query("SHOW COLUMNS FROM user LIKE 'name'")->fetch(PDO::FETCH_ASSOC)['Type'], 'varchar') === 0, 'the old name column is a varchar');
	$raster = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster");
	exec("$raster schema --check 2>&1", $out, $code);
	same(1, $code, 'schema --check reports the small columns');
	$out = array();
	exec("$raster schema --apply 2>&1", $out, $code);
	same(0, $code, 'schema --apply: '.implode("\n", $out));
	check(strpos(implode("\n", $out), 'widened user.name to LONGTEXT') !== false, implode("\n", $out));
	same('longtext', strtolower($pdo->query("SHOW COLUMNS FROM user LIKE 'name'")->fetch(PDO::FETCH_ASSOC)['Type']));
	// MariaDB still says int(11)
	same('int', preg_replace('/\(\d+\)/', '', strtolower($pdo->query("SHOW COLUMNS FROM user LIKE 'failed_count'")->fetch(PDO::FETCH_ASSOC)['Type'])));
	$out = array();
	exec("$raster schema --check 2>&1", $out, $code);
	same(0, $code, 'no drift after --apply: '.implode("\n", $out));
});
test('sending on a frozen database widens the small newsletterissue columns 2.2.0 made', function () use ($root) {
	$db = test_db(sys_get_temp_dir().'/raster-narrow-issue-'.getmypid().'.sqlite');
	$boot = 'require '.var_export("$root/system/boot.php", true).'; boot::$appname = "application"; boot::cli(); newsletter::connect();';
	// the way 2.2.0 stored an issue sent to one reader: fluid, sized by its values
	$old = ' $i = R::dispense("newsletterissue"); $i->url = "/old"; $i->subject = "Hi"; $i->recipients = 1; $i->sent_at = R::isoDateTime(); R::store($i);';
	same('', trim((string)shell_exec('RASTER_DB='.escapeshellarg($db).' '.php_cmd().' -r '.escapeshellarg($boot.$old).' 2>&1')), 'making the old table');
	$pdo = test_pdo($db);
	if (on_mysql()) {
		check(stripos($pdo->query("SHOW COLUMNS FROM newsletterissue LIKE 'recipients'")->fetch(PDO::FETCH_ASSOC)['Type'], 'tinyint') === 0, 'the old recipients column is a tinyint');
		check(stripos($pdo->query("SHOW COLUMNS FROM newsletterissue LIKE 'subject'")->fetch(PDO::FETCH_ASSOC)['Type'], 'varchar') === 0, 'the old subject column is a varchar');
	}
	// in production: a subject longer than 191, then 300 readers reached
	$long = str_repeat('A long subject ', 20);
	$send = ' $issue = newsletter::send_issue("/new", "<html></html>", '.var_export($long, true).');'
		.' for ($n = 0; $n < 300; $n++) { $d = R::dispense("newsletterdelivery"); $d->issue = (int)$issue->id; $d->email = "r$n@example.com"; $d->sent_at = R::isoDateTime(); R::store($d); }'
		.' $issue->incomplete = 1; R::store($issue);'
		.' $issue = newsletter::send_issue("/new", "<html></html>", '.var_export($long, true).');'
		.' echo $issue->recipients, " ", $issue->incomplete;';
	same('300 0', trim((string)shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' '.php_cmd().' -r '.escapeshellarg($boot.$send).' 2>&1')));
	same(array($long, '300'), array_map('strval', $pdo->query("SELECT subject, recipients FROM newsletterissue WHERE url = '/new'")->fetch(PDO::FETCH_NUM)));
	if (on_mysql()) {
		// MariaDB still says int(11)
		same('int', preg_replace('/\(\d+\)/', '', strtolower($pdo->query("SHOW COLUMNS FROM newsletterissue LIKE 'recipients'")->fetch(PDO::FETCH_ASSOC)['Type'])));
		same('longtext', strtolower($pdo->query("SHOW COLUMNS FROM newsletterissue LIKE 'subject'")->fetch(PDO::FETCH_ASSOC)['Type']));
	}
});
test('mcp over stdio', function () use ($root, $db) {
	$input = json_encode(array('jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array('name' => 'get_page', 'arguments' => array('page' => '/about'))))."\n";
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$response = json_decode(stream_get_contents($pipes[1]), true);
	proc_close($process);
	same(7, $response['id']);
	same('/about', $response['result']['structuredContent']['url']);
});
test('a malformed MCP message is answered and the stdio server goes on (#134)', function () use ($root, $db) {
	$lines = array(
		'{"jsonrpc":"2.0","id":1,"method":["x"]}',
		'{"jsonrpc":"2.0","id":2,"method":"ping"}',
		'[{"jsonrpc":"2.0","id":3,"method":"ping"},{"jsonrpc":"2.0","id":4,"method":"nope"}]',
		'[1,2]',
		'[]',
		'"just a string"',
		'{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool"}}',
		'{"jsonrpc":"2.0","id":6,"method":"initialize","params":{}}',
	);
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
	fwrite($pipes[0], implode("\n", $lines)."\n");
	fclose($pipes[0]);
	$out = array_values(array_filter(explode("\n", stream_get_contents($pipes[1]))));
	$errors = stream_get_contents($pipes[2]);
	same(0, proc_close($process), $errors);
	same(count($lines), count($out), 'one answer a line: '.implode("\n", $out).$errors);
	$answers = array_map(function ($line) { return json_decode($line, true); }, $out);
	same(-32600, $answers[0]['error']['code'], 'a method that is not a string');
	same(1, $answers[0]['id']);
	same(2, $answers[1]['id'], 'the next ping is answered');
	same(array(3, 4), array_column($answers[2], 'id'), 'a batch gets a list back');
	same(-32601, $answers[2][1]['error']['code']);
	same(array(-32600, -32600), array_map(function ($a) { return $a['error']['code']; }, $answers[3]), 'a batch of non-messages');
	same(-32600, $answers[4]['error']['code'], 'an empty batch');
	same(-32600, $answers[5]['error']['code'], 'a message that is not an object');
	same(-32602, $answers[6]['error']['code'], 'an unknown tool is a JSON-RPC error');
	check(strpos((string)$answers[6]['error']['message'], 'no_such_tool') !== false, 'missing '.'no_such_tool');
	same(trim(file_get_contents($root.'/system/VERSION')), $answers[7]['result']['serverInfo']['version']);
});
test('a malformed MCP message over HTTP gets JSON-RPC errors (#134)', function () use ($base) {
	list($status, $body) = http('POST', "$base/mcp", '[1,2]', array('Authorization: Bearer test-token', 'Content-Type: application/json'));
	same(200, $status, $body);
	same(array(-32600, -32600), array_map(function ($a) { return $a['error']['code']; }, json_decode($body, true)));
	list($status, $body) = http('POST', "$base/mcp", '{"jsonrpc":"2.0","id":1,"method":{"a":1}}', array('Authorization: Bearer test-token'));
	same(-32600, json_decode($body, true)['error']['code'], $body);
});
test('MCP over HTTP finds the token where Apache and PHP-FPM put it (#106)', function () {
	// mod_php and php -S: the header
	same('right', mcp::bearer(array('HTTP_AUTHORIZATION' => 'Bearer right')));
	// PHP-FPM behind Apache with the shipped .htaccess: the rewrite sets it,
	// and the rewrite to index.php renames it; the header's own name is empty
	same('right', mcp::bearer(array('REDIRECT_HTTP_AUTHORIZATION' => 'Bearer right')));
	same('right', mcp::bearer(array('HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer right')));
	same('right', mcp::bearer(array('HTTP_AUTHORIZATION' => 'bearer   right ')));
	same('', mcp::bearer(array('HTTP_AUTHORIZATION' => 'Basic cmlnaHQ=')));
	same('', mcp::bearer(array()));
	// the Apache config passes the header on, and doctor misses the line
	$apache = private_paths::config('apache');
	check(strpos((string)$apache, private_paths::APACHE_AUTHORIZATION) !== false, 'missing '.private_paths::APACHE_AUTHORIZATION);
	$dir = sys_get_temp_dir().'/raster-htaccess-'.getmypid();
	@mkdir($dir);
	try {
		file_put_contents("$dir/.htaccess", $apache);
		same(array(), raster_project::htaccess_gaps($dir));
		file_put_contents("$dir/.htaccess", str_replace(private_paths::APACHE_AUTHORIZATION, '', $apache));
		same(array('authorization'), raster_project::htaccess_gaps($dir));
	} finally {
		@unlink("$dir/.htaccess");
		@rmdir($dir);
	}
});
test('render_url runs PHP itself under PHP-FPM (#107)', function () use ($root) {
	// under PHP-FPM, PHP_BINARY is php-fpm, which can't run bin/raster
	foreach (array('fpm-fcgi', 'cgi-fcgi', 'apache2handler') as $sapi) {
		$php = mcp::php_binary($sapi);
		check(is_file($php) && is_executable($php), "$sapi: no PHP to run: $php");
		check(strpos(basename($php), 'fpm') === false, "$sapi: $php");
		same('cli', shell_exec(escapeshellarg($php).' -r '.escapeshellarg('echo PHP_SAPI;')), $php);
	}
	same(PHP_BINARY, mcp::php_binary('cli'));
});
test('render_url keeps the environment and reads a page that logs a lot (#107)', function () use ($root) {
	$app = APPBASE.config::get('models_path', 'models');
	$views = APPBASE.config::get('views_path', 'views').'/'.config::get('theme');
	temp_model("$app/zzloud", array('zzloud.php' => "<?php\nclass zzloud\n{\n\tfunction env() { return (string)getenv('ZZ_SITE_SETTING'); }\n\tfunction noise() { for (\$i = 0; \$i < 3000; \$i++) error_log(str_repeat('x', 60).\" \$i\"); return 'done'; }\n}\n"));
	test_fixture("$views/zz-loud.html");
	file_put_contents("$views/zz-loud.html", "<p>env=<!-- print.zzloud.env /--> <!-- print.zzloud.noise /--></p>\n");
	putenv('ZZ_SITE_SETTING=from-the-environment');
	// a server of its own, given up on after a while: a hang fails the test
	// instead of stopping the run
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, getenv());
	try {
		fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'render_url', 'arguments' => array('url' => '/zz-loud'))))."\n");
		fclose($pipes[0]);
		$read = array($pipes[1]); $none = null;
		check(stream_select($read, $none, $none, 30) === 1, 'render_url gave no answer in 30 s');
		$answer = json_decode(stream_get_contents($pipes[1]), true)['result'];
		check(empty($answer['isError']), json_encode($answer));
		$page = $answer['structuredContent'];
		same(true, $page['ok'], json_encode($page));
		check(strpos((string)$page['html'], 'env=from-the-environment') !== false, 'missing '.'env=from-the-environment');
		check(strpos((string)$page['html'], 'done') !== false, 'missing '.'done');
		check(strpos((string)$page['errors'], 'x 2999') !== false, 'missing '.'x 2999');
	} finally {
		if (proc_get_status($process)['running']) proc_terminate($process, 9);
		proc_close($process);
		putenv('ZZ_SITE_SETTING');
		@unlink("$views/zz-loud.html");
		remove_dir("$app/zzloud");
		controller::forget_models();
	}
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

test('mail goes to the whole address, never to a <...> inside a quoted one', function () {
	same('ana@example.com', mail::address('Ana <ana@example.com>'));
	same('ana@example.com', mail::address('"Ana, Pop" <ana@example.com>'));
	same('ana@example.com', mail::address('ana@example.com'));
	$quoted = '"1<victim@example.com>"@attacker.example';
	same($quoted, mail::address($quoted));
	same('"<victim@example.com>"@attacker.example', mail::address('"<victim@example.com>"@attacker.example'));
	// an address that is neither is refused, not cut down to its <...>
	same(false, mail::send('"x" <victim@example.com> junk', 'Hi', '<p>Hi</p>'));
});
test('the sitemap leaves out the items and pages that need an account, in any case', function () use ($root) {
	$theme = "$root/application/views/default";
	file_put_contents("$theme/zzinsider.html", '<!-- render.cms.zzinsider --><h2><!-- print.title -->Plans<!-- /print.title --></h2><!-- /render.cms.zzinsider -->');
	file_put_contents("$theme/zzinsider_item.html", '<!-- render.cms.zzinsider --><h1><!-- print.title -->Plans<!-- /print.title --></h1><!-- /render.cms.zzinsider -->');
	file_put_contents("$theme/Zzstaffroom.html", '<p>Rota</p>');
	$protected = config::get('protected');
	try {
		cms_store::connect();
		cms_store::save_item('zzinsiderdata', 0, array('title' => 'Secret merger plans'), array('title'));
		$urls = array_column((new feed())->pages(), 'url');
		check(preg_grep('#/zzinsider/zzinsider_item/secret-merger-plans$#', $urls), 'an open collection is listed: '.json_encode($urls));
		config::set('protected')->to(array('zzinsider' => 'member', 'zzstaffroom' => 'editor'));
		$urls = array_column((new feed())->pages(), 'url');
		same(array(), array_values(preg_grep('#zzinsider|Zzstaffroom#i', $urls)), 'protected: '.json_encode($urls));
	} finally {
		config::set('protected')->to($protected);
		foreach (array('zzinsider.html', 'zzinsider_item.html', 'Zzstaffroom.html') as $file) @unlink("$theme/$file");
		R::exec('DROP TABLE IF EXISTS zzinsiderdata');
	}
});
test('one page has one address: a trailing slash and index.php/ redirect (#131)', function () use ($base) {
	foreach (array('/about/' => '/about', '/index.php/about' => '/about', '/index.php/about/' => '/about', '/news/news_page/2/' => '/news/news_page/2',
		'/about/?x=1&y=a%20b' => '/about?x=1&y=a%20b', '/news/news_items/headline/Filler%201/' => '/news/news_items/headline/Filler%201') as $asked => $address) {
		list($status, , $headers) = http('GET', $base.$asked);
		same(301, $status, $asked);
		same($base.$address, location($headers), $asked);
	}
	same(200, http('GET', "$base/")[0]);
	same(200, http('GET', "$base/about")[0]);
	// a form sent to the slashed address is not lost in a redirect
	same(200, http('POST', "$base/about/", 'x=1', array('Content-Type: application/x-www-form-urlencoded'))[0]);
});
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
	check(strpos($mail['raw'], "\r\nSubject: Reset your password\r\n") !== false, 'subject from <title>');
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
	// what was typed at sign-up shows on the page as text, never as markup
	cms_store::connect();
	R::exec('UPDATE subscriber SET email = ? WHERE email = ?', array('"<img src=x onerror=alert(1)>"@example.com', 'reader@example.com'));
	$page = http('GET', "$base/newsletter-confirm?token={$t[1]}")[1];
	check(strpos($page, '<img src=x') === false && strpos($page, '&quot;&lt;img src=x onerror=alert(1)&gt;&quot;@example.com') !== false, 'the address is escaped');
	R::exec('UPDATE subscriber SET email = ? WHERE email = ?', array('reader@example.com', '"<img src=x onerror=alert(1)>"@example.com'));
	$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_MAIL='.escapeshellarg("log://$maildir").' RASTER_URL='.escapeshellarg("$base/");
	$out = shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' send /about --dry-run 2>&1');
	check(strpos($out, 'to 1 subscriber') !== false, "dry run: $out");
	$out = shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' send /about 2>&1');
	check(strpos($out, 'Sent "About" to 1 subscriber') !== false, "send: $out");
	$mail = last_mail();
	check(preg_match('/List-Unsubscribe: <([^>]+)>/', $mail['raw'], $u), 'List-Unsubscribe');
	check(strpos($mail['raw'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') !== false);
	$html = base64_decode(preg_replace('/.*Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--.*/s', '$1', $mail['raw']));
	check(strpos($html, '<form') === false && strpos($html, '<script') === false && strpos($html, '<base') === false, 'issue cleaned up');
	$again = shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' send /about 2>&1');
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
	// a slug ending like one of SQLite's side files is a page (#98)
	$journal = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => 'My travel journal')))['structuredContent'];
	same('my-travel-journal', $journal['slug']);
	same(200, http('GET', "$base/news/news_item/my-travel-journal")[0]);
	mcp_call('delete_item', array('collection' => 'news', 'id' => $journal['id']));
	check(strpos($body, 'Ünïcode') < strpos($body, 'Hello, world'), 'order=newest');
});
test('an item titled only with digits opens at its own slug (#90)', function () use ($base) {
	http('GET', "$base/news");
	$year = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => '1984')))['structuredContent'];
	$one = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => '1')))['structuredContent'];
	same('1984', $year['slug']);
	same('1', $one['slug']);
	check(strpos(http('GET', "$base/news/news_item/1984")[1], '<h1>1984</h1>') !== false, 'the item titled 1984');
	// item 1 is the mock-up; the link /news/news_item/1 is the item titled 1
	check(strpos(http('GET', "$base/news/news_item/1")[1], '<h1>1</h1>') !== false, 'the item titled 1, not item id 1');
	// an id no slug shadows still opens its item
	check(strpos(http('GET', "$base/news/news_item/{$year['id']}")[1], '<h1>1984</h1>') !== false, 'by id');
	mcp_call('delete_item', array('collection' => 'news', 'id' => $year['id']));
	mcp_call('delete_item', array('collection' => 'news', 'id' => $one['id']));
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
	shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply');
	$port = free_port();
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		$cache = function () use ($port) { return current(preg_grep('/^X-Raster-Cache/i', http('GET', "http://127.0.0.1:$port/about")[2])); };
		same('X-Raster-Cache: miss', $cache());
		same('X-Raster-Cache: hit', $cache());
		util::content_changed();
		same('X-Raster-Cache: miss', $cache());
	} finally {
		proc_terminate($server); proc_close($server);
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
	shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply');
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	$html = shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /');
	$logs = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	check(strpos((string)$html, '</html>') !== false, 'the page did not render');
	check(is_array($logs) && $logs, 'no queries logged');
	$queries = array_values(array_filter($logs, function ($l) { return is_string($l) && preg_match('/^\s*(SELECT|PRAGMA|SHOW|DESCRIBE|INSERT|UPDATE|DELETE)/i', $l); }));
	$repeated = array_keys(array_filter(array_count_values(preg_grep('/sqlite_master|PRAGMA|SHOW|DESCRIBE/i', $queries)), function ($n) { return $n > 1; }));
	same(array(), $repeated, 'schema read twice');
	same(array(), array_values(preg_grep('/count\(/i', $queries)), 'lists counted');
	same(array(), array_keys(array_filter(array_count_values(preg_grep('/FROM `?[a-z0-9]+page`?/i', $queries)), function ($n) { return $n > 1; })), 'a page row read twice');
	if (!on_mysql()) same('wal', test_pdo($db)->query('PRAGMA journal_mode')->fetchColumn());
});
test('a site under a path: RASTER_URL with a folder', function () use ($root, $db) {
	$port = free_port();
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_URL' => "http://127.0.0.1:$port/preview/abc/", 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
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
		proc_terminate($server); proc_close($server);
	}
});

test('raster serve passes site_url with a folder to the router', function () use ($root, $db) {
	$port = free_port();
	$config = "$root/application/config/the_app.php";
	$original = file_get_contents($config);
	file_put_contents($config, $original."\nconfig::set('site_url')->to('http://127.0.0.1:$port/shop/');\n");
	$env = array('RASTER_DB' => $db, 'PATH' => getenv('PATH'));
	$server = proc_open(php_argv("$root/bin/raster", 'serve', "--host=127.0.0.1", "--port=$port"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	try {
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
		same(200, http('GET', "http://127.0.0.1:$port/shop/about")[0], 'page under the folder');
		same(200, http('GET', "http://127.0.0.1:$port/shop/application/views/default/style.css")[0], 'static file under the folder');
	} finally {
		file_put_contents($config, $original);
		// serve starts php -S through a shell, so the server is found by its
		// address ([-] keeps the pattern from matching pkill's own shell)
		proc_terminate($server); proc_close($server);
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
	same(404, http('GET', "$base/zzz/news_item/hello-world")[0]);
});
test('a password reset ends other sessions', function () use ($base, $maildir) {
	list(, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => 'ada@example.com', 'password' => 'brand new pass'));
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	check(strpos(http('GET', "$base/account", null, array("Cookie: $cookie"))[1], 'Logged in as') !== false, 'session A works');
	// the reset the forged Host test asked for was minutes ago, not seconds
	cms_store::connect();
	R::exec('UPDATE user SET reset_expires = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() + 3600 - 6 * 60), 'ada@example.com'));
	form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'ada@example.com'));
	preg_match('/token=([a-f0-9]{48})/', last_mail()['text'], $t);
	form_post("$base/reset?token={$t[1]}", array('raster_form' => 'authentication.reset', 'token' => $t[1], 'password' => 'third password', 'password_again' => 'third password'));
	same(303, http('GET', "$base/account", null, array("Cookie: $cookie"))[0], 'old session still logged in');
});
test('accounts: login links from the command line', function () use ($base, $root, $db) {
	$raster = 'RASTER_DB='.escapeshellarg($db).' RASTER_URL='.escapeshellarg("$base/").' '.php_cmd().' '.escapeshellarg("$root/bin/raster");
	exec("$raster user link@example.test --role=member --password=link-pass-1 2>&1", $out, $code);
	same(0, $code, implode("\n", $out));
	$link = function ($args) use ($raster) { return trim((string)shell_exec("$raster login-link $args 2>/dev/null")); };
	$cookie_of = function ($headers) {
		$set = preg_grep('/^Set-Cookie/i', $headers);
		return $set ? preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current($set)) : null;
	};
	// the link logs in, and the page comes again without the token
	$url = $link('link@example.test');
	check(preg_match('#^'.preg_quote("$base/", '#').'\?raster_login=[a-f0-9]{48}$#', $url), "link: $url");
	list($status, , $headers) = http('GET', $url);
	same(303, $status);
	same("$base/", location($headers));
	$cookie = $cookie_of($headers);
	check($cookie && strpos(http('GET', "$base/account", null, array("Cookie: $cookie"))[1], 'Logged in as') !== false, 'link did not log in');
	// once only
	list($status, , $headers) = http('GET', $url);
	same(303, $status);
	same(null, $cookie_of($headers), 'link used twice');
	// a bad token only drops the parameters; others stay
	list($status, , $headers) = http('GET', "$base/about?raster_login=nope&lang=en&next=%2Faccount");
	same(303, $status);
	same("$base/about?lang=en", location($headers));
	same(null, $cookie_of($headers), 'bad token logged in');
	// next goes to a page on the site, and nowhere else
	$url = $link('link@example.test --next=/account');
	check(strpos($url, '&next=%2Faccount') !== false, "next: $url");
	list(, , $headers) = http('GET', $url);
	same("$base/account", location($headers));
	check($cookie_of($headers) !== null, 'link with next did not log in');
	check(strpos($link('link@example.test --next=//evil.example'), 'next=') === false, 'unsafe next in the link');
	foreach (array('//evil.example', 'https://evil.example/') as $evil) {
		list(, , $headers) = http('GET', $link('link@example.test').'&next='.rawurlencode($evil));
		same("$base/", location($headers), $evil);
	}
	// a link more than a minute old does nothing
	$url = $link('link@example.test');
	authentication::connect();
	R::exec('UPDATE user SET login_expires = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() - 1), 'link@example.test'));
	list($status, , $headers) = http('GET', $url);
	same("$base/", location($headers));
	same(null, $cookie_of($headers), 'expired link logged in');
	// accounts are made by `raster user`, never by a link
	exec("$raster login-link nobody@example.test 2>&1", $out, $code);
	same(2, $code);
});
test('feed links, raw views and json lists', function () use ($base, $root) {
	check(strpos(http('GET', "$base/")[1], 'href="'.$base.'/news.rss"') !== false, 'rss link rewritten');
	same(403, http('GET', "$base/application/views/default/news.rss")[0]);
	$view = "$root/application/views/default/zz_feed.json";
	test_fixture($view);
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
	$db = test_db(sys_get_temp_dir().'/raster-frozen-'.getmypid().'.sqlite');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' RASTER_URL=http://example.test/';
	$raster = php_cmd().' '.escapeshellarg("$root/bin/raster");
	try {
		exec("$env $raster schema --apply 2>&1", $out, $code);
		same(0, $code, implode("\n", $out));
		exec("$env $raster user admin@example.test --password=secret-pass 2>&1", $out, $code);
		same(0, $code, implode("\n", $out));
		$result = shell_exec("$env ".php_cmd().' -r '.escapeshellarg('require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); authentication::connect(); echo authentication::check_login("admin@example.test", "secret-pass") ? "ok" : "fail";').' 2>&1');
		same('ok', trim($result));
		exec("$env $raster schema --check 2>&1", $out, $code);
		same(0, $code, 'drift after apply');
	} finally {
		array_map('unlink', glob("$db*") ?: array());
	}
});
test('the comment at the top of bin/raster lists what raster help lists (#49)', function () use ($root) {
	// per command: the options and the <placeholders> it names
	$names = function ($text) {
		preg_match_all('/--([a-z\-]+)/', $text, $options);
		preg_match_all('/<[^>]+>/', $text, $placeholders);
		$names = array_values(array_unique(array_merge($options[1], $placeholders[0])));
		sort($names);
		return $names;
	};
	$header = array();
	preg_match_all('#^//   php bin/raster (\S+)(.*)$#m', file_get_contents("$root/bin/raster"), $lines, PREG_SET_ORDER);
	foreach ($lines as $line) $header[$line[1]] = $names($line[2]);
	$help = array();
	$command = null;
	foreach (explode("\n", (string)shell_exec(php_cmd().' '.escapeshellarg("$root/bin/raster").' help')) as $line) {
		// a command's lines are its own and the indented ones below it; the
		// usage is what comes before the description's wide gap
		if (preg_match('#^  php bin/raster (\S+)#', $line, $m)) $command = $m[1];
		elseif (!preg_match('#^ {8}#', $line)) $command = null;
		if ($command === null) continue;
		$parts = preg_split('/\s{2,}/', trim($line));
		$usage = preg_match('#^(php bin/raster|--)#', $parts[0]) ? $parts[0] : '';
		// options named only in a description are taken too, as bin/raster does
		preg_match_all('/--[a-z\-]+/', $line, $options);
		$help[$command] = array_merge(isset($help[$command]) ? $help[$command] : array(), array($usage), $options[0]);
	}
	foreach ($help as $command => $texts) $help[$command] = $names(implode(' ', $texts));
	ksort($header);
	ksort($help);
	same(array_keys($help), array_keys($header), 'the same commands');
	foreach ($help as $command => $expected) same($expected, $header[$command], "$command:");
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
	// SQLite's side files, not every URL that ends like one (#98)
	foreach (array('/raster.sqlite-journal', '/x/raster.sqlite-wal', '/raster.SQLITE-SHM') as $path) check(private_paths::blocked($path), "$path must be private");
	foreach (array('/news/news_item/my-travel-journal', '/reading-journal', '/data-wal') as $path) check(!private_paths::blocked($path), "$path must be served");
});
test('lint warns about a view whose address the server refuses (#98)', function () use ($root) {
	$dir = "$root/application/views/default/docs";
	test_fixture($dir);
	@mkdir($dir);
	file_put_contents("$dir/config.html", '<p>Settings</p>');
	file_put_contents("$dir/setup.html", '<p>Setup</p>');
	try {
		$problems = (new raster_inspector())->lint();
		check(has_problem($problems, '/docs/config is refused'), 'lint said: '.json_encode($problems));
		check(!has_problem($problems, '/docs/setup is refused'), 'an ordinary page');
	} finally {
		remove_dir($dir);
	}
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
	test_fixture($file);
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

test('a picture keeps working when the site moves to another folder', function () use ($root) {
	$name = 'zz-move-'.bin2hex(random_bytes(3)).'.png';
	file_put_contents("$root/media/$name", 'png');
	$base = config::get('base_uri');
	try {
		// saved in a builder preview, under /preview/abc/
		same("/media/$name", cms_types::clean('text', "/preview/abc/media/$name", 'photo'), 'stored from the site root');
		same("/media/$name", cms_types::clean('text', "/media/$name", 'photo'));
		config::set('base_uri')->to('https://club.example/');
		same("/media/$name", cms_types::read('text', "/preview/abc/media/$name"), 'an address saved under an old folder');
		config::set('base_uri')->to('http://127.0.0.1:8080/preview/abc/');
		same("/preview/abc/media/$name", cms_types::read('text', "/media/$name"), 'shown under the current folder');
		// left alone: not a whole address, not a file in media/, another site
		foreach (array("see /media/$name", '/media/zz-missing.png', "https://elsewhere.example/media/$name", "/media/$name?x=1", 'Penny Black') as $value) {
			same($value, cms_types::clean('text', $value, 'photo'), $value);
			same($value, cms_types::read('text', $value), $value);
		}
	} finally {
		config::set('base_uri')->to($base);
		unlink("$root/media/$name");
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

test('RASTER_DB takes a MySQL address', function () {
	same(array('dsn' => 'mysql:host=db.example;port=3307;dbname=shop;charset=utf8mb4', 'user' => 'shop', 'password' => 'p@ss:w/rd'), database::mysql_url('mysql://shop:p%40ss%3Aw%2Frd@db.example:3307/shop'));
	same(array('dsn' => 'mysql:host=127.0.0.1;dbname=raster;charset=utf8mb4', 'user' => 'root', 'password' => null), database::mysql_url('mysql://root@127.0.0.1/raster'));
	same(null, database::mysql_url('/tmp/site.sqlite'));
	try {
		database::mysql_url('mysql://root@127.0.0.1');
		check(false, 'an address without a database');
	} catch (InvalidArgumentException $e) {
		check(strpos($e->getMessage(), 'mysql://user:password@host:3306/database') !== false, $e->getMessage());
	}
	// a site whose config reads RASTER_DB as a file is on MySQL all the same
	// (nothing listens on port 1; on SQLite this would be a file named so)
	$code = 'require "'.dirname(__DIR__).'/system/boot.php"; boot::$appname = "application"; boot::cli(); database::instance(); try { R::inspect(); } catch (Exception $e) { echo $e->getMessage(); }';
	$out = (string)shell_exec('RASTER_DB=mysql://nobody@127.0.0.1:1/none '.php_cmd().' -r '.escapeshellarg($code).' 2>&1');
	same('Could not connect to database (none).', $out);
});
test('the ORM does not need pdo_mysql for an SQLite site', function () {
	check(defined('RB_PDO_MYSQL_ATTR_INIT_COMMAND'), 'rb.php defines it whatever drivers PHP has');
	check(in_array('sqlite', PDO::getAvailableDrivers()), 'and SQLite is enough to get here');
});

// ## 2.1.8 batch A: page cache

// a PHP process of its own with the cache on, as a request for /about would
// be; $code runs after it
function cache_php($code) {
	global $root;
	return php_argv('-r', 'require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli("/about"); config::set("page_cache")->to(true); raster_cache::$cacheable = true; http_response_code(200); '.$code);
}
// what the page cache keeps; models.json is the model lookup's, not a page
function cached_pages() {
	return array_values(array_filter(glob(APPBASE.'data/cache/*') ?: array(), function ($f) { return basename($f) !== 'models.json'; }));
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
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
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
		same(0, count(cached_pages()), 'cache files');
		// lists with items are cached as before
		foreach (array('/news/news_items/headline/Filler%201', '/news/news_page/2', '/news') as $path) {
			same('200 X-Raster-Cache: miss', $get($path), $path);
			same('200 X-Raster-Cache: hit', $get($path), $path);
		}
		// a link with only tracking parameters is the same page
		same('200 X-Raster-Cache: hit', $get('/news?utm_source=newsletter&fbclid=x'));
		same('200 not cached', $get('/news?x=1'));
	} finally {
		proc_terminate($server); proc_close($server);
		cache_empty();
	}
});

// a production server on its own port, for the cache tests over HTTP
function cache_server($db, $test) {
	global $root;
	$port = free_port();
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	$get = function ($path) use ($port) {
		list($status, $body, $headers) = http('GET', "http://127.0.0.1:$port$path");
		return array($status.' '.(current(preg_grep('/^X-Raster-Cache/i', $headers)) ?: 'not cached'), $body);
	};
	try {
		$test($get);
	} finally {
		proc_terminate($server); proc_close($server);
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
		same(0, count(cached_pages()), 'cache files');
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
			check(strpos($get($path)[1], 'Hello, world') !== false, "$path lists the item");
			same('200 not cached', $get($path)[0], $path);
		}
		same(0, count(cached_pages()), 'cache files');
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
	$empty = test_db_copy($db, sys_get_temp_dir().'/raster-test-empty-'.getmypid().'.sqlite');
	$pdo = test_pdo($empty);
	$pdo->exec('DELETE FROM newsdata');
	$pdo = null;
	try {
		cache_server($empty, function ($get) {
			foreach (array('/news/news_items/headline/r1', '/news/news_items/headline/r2', '/news/news_page/500') as $path) {
				same('200 not cached', $get($path)[0], $path);
				same('200 not cached', $get($path)[0], $path);
			}
			same(0, count(cached_pages()), 'cache files');
		});
	} finally {
		array_map('unlink', glob("$empty*") ?: array());
	}
});

test('in production, made-up URLs of a list with no table yet are not cached', function () use ($db) {
	cache_empty();
	$bare = test_db_copy($db, sys_get_temp_dir().'/raster-test-bare-'.getmypid().'.sqlite');
	$pdo = test_pdo($bare);
	$pdo->exec('DROP TABLE newsdata');
	$pdo = null;
	try {
		cache_server($bare, function ($get) {
			// before schema --apply the list keeps its mock-up
			foreach (array('/news/news_items/headline/r1', '/news/news_items/nonsense/x', '/news/news_page/7') as $path) {
				same('200 not cached', $get($path)[0], $path);
				same('200 not cached', $get($path)[0], $path);
			}
			same(0, count(cached_pages()), 'cache files');
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
	test_fixture($new);
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
		same(0, count(cached_pages()), 'cache files');
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
	$process = proc_open(php_argv('-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-d', 'html_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$root/index.php"),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array_merge(array('PATH' => getenv('PATH'), 'RASTER_SECRET' => 'test-secret'), $env));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	register_shutdown_function(function () use ($process, $log) { proc_terminate($process); proc_close($process); @unlink($log); });
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
	test_fixture($dir);
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
$b_db = test_db(sys_get_temp_dir().'/raster-b-'.getmypid().'.sqlite');
shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($b_db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply 2>&1');
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
	test_fixture($dir);
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
	same("<!doctype html><meta charset='utf-8'><title>404</title><h1>404</h1>", $body, 'the bare status, no words (#38)');
});

test('doctor warns when PHP shows errors in production; the command line keeps them (#74)', function () use ($root) {
	$doctor = function ($display) use ($root) {
		return shell_exec('RASTER_ENV=production '.php_cmd().' -d display_errors='.$display.' '.escapeshellarg("$root/bin/raster").' doctor 2>&1');
	};
	check(strpos($doctor('1'), 'display_errors') !== false, 'on');
	check(strpos($doctor('0'), 'display_errors') === false, 'off');
	$code = 'require "system/boot.php"; boot::$appname = "application"; boot::cli(); echo ini_get("display_errors");';
	same('1', shell_exec('cd '.escapeshellarg($root).' && RASTER_ENV=production '.php_cmd().' -d display_errors=1 -r '.escapeshellarg($code)));
});

test('a database that can\'t be reached answers 503 outside development, logged, never cached (#65)', function () use ($b_db, $root) {
	$cache = "$root/application/data/cache";
	$had_cache = is_dir($cache);
	$bad = test_db(sys_get_temp_dir().'/raster-b-down-'.getmypid().'.sqlite');
	test_db_break($bad);
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
		test_db_copy($b_db, sys_get_temp_dir().'/raster-b-down-'.getmypid().'.sqlite');
		list($status, $body, $headers) = http('GET', "$prod/about");
		same(200, $status);
		same('miss', b_header($headers, 'X-Raster-Cache'));
		check(strpos($body, '<!-- print.') === false);
	} finally {
		array_map('unlink', glob("$bad*") ?: array());
		if (!$had_cache) exec('rm -rf '.escapeshellarg($cache));
	}
	// a database that is there but has no tables yet still shows the template
	$empty = test_db(sys_get_temp_dir().'/raster-b-empty-'.getmypid().'.sqlite');
	if (!on_mysql()) touch($empty);
	list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $empty, 'RASTER_URL' => 'http://example.test/'));
	try {
		list($status, $body) = http('GET', "$prod/");
		same(200, $status);
		check(strpos($body, 'Hello, and welcome') !== false, 'template default');
	} finally {
		array_map('unlink', glob("$empty*") ?: array());
		if (!$had_cache) exec('rm -rf '.escapeshellarg($cache));
	}
	// development says so plainly instead
	$bad = test_db(sys_get_temp_dir().'/raster-b-down-dev-'.getmypid().'.sqlite');
	test_db_break($bad);
	list($dev) = b_server(array('RASTER_ENV' => 'development', 'RASTER_DB' => $bad));
	try {
		list($status, $body) = http('GET', "$dev/about");
		same(500, $status);
		check(strpos($body, 'database') !== false, $body);
		b_with_boom(function () use ($dev) {
			list($status, $body) = http('GET', "$dev/api/zzboom/needs/x/y");
			same(503, $status, $body);
			$json = json_decode($body, true);
			check(isset($json['exception']) && strpos($json['exception'], on_mysql() ? 'Could not connect to database' : 'not a database') !== false, $body);
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
	test_fixture($dir);
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
		exec('RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --drop=zzlistdata --force');
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

// --drop takes only what the templates own: a table a model declares with
// schema() or types() is neither unused nor droppable, and neither are the
// accounts' columns (#109)
test('schema --drop leaves the tables and columns models declare alone', function () use ($root) {
	$dir = "$root/application/models/zzweather";
	test_fixture($dir);
	@mkdir($dir);
	file_put_contents("$dir/zzweather.php", '<?php class zzweather { static function schema() { return array("zzweatherdata" => array("city" => "", "degrees" => 0)); }'
		.' static function types() { return array("zzreading" => array("fields" => array("city" => "", "rain" => 0))); } }');
	cms_records::forget();
	$schema = new raster_schema();
	$refused = function ($table, $column = null, $force = false) use ($schema) {
		try { $schema->drop($table, $column, $force); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
		return null;
	};
	try {
		$changes = $schema->apply();
		check(in_array('created table zzweatherdata', $changes), json_encode($changes));
		R::getDatabaseAdapter()->exec("INSERT INTO zzweatherdata (city, degrees) VALUES ('Oslo', 4)");
		check(!in_array('zzweatherdata', $schema->status()['unused_tables']), 'a schema() table is not unused');
		check(!in_array('zzreadingdata', $schema->status()['unused_tables']), 'nor a types() table');
		foreach (array(array('zzweatherdata'), array('zzweatherdata', 'city'), array('zzweatherdata', null, true), array('zzweatherdata', 'city', true),
			array('user', 'password'), array('user', 'password', true), array('subscriber', 'email', true),
			array('zzreadingdata', null, true), array('zzreadingdata', 'rain', true), array('zzreadingdata', 'created_at', true)) as $drop) {
			check($refused($drop[0], isset($drop[1]) ? $drop[1] : null, !empty($drop[2])) !== null, 'refused: '.json_encode($drop));
		}
		same(1, (int)R::count('zzweatherdata'), 'the row is still there');
		check(array_key_exists('password', cms_store::columns('user')), 'and the password column');
		// a column the type no longer declares is the templates' business again
		cms_types::ensure('zzreadingdata', array('old' => 'text'));
		same('dropped zzreadingdata.old', $schema->drop('zzreadingdata', 'old'));
	} finally {
		@unlink("$dir/zzweather.php");
		@rmdir($dir);
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzweatherdata');
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzreadingdata');
		cms_records::forget();
		cms_store::forget();
	}
});

test('cms_records::find filters and sorts by fields named like SQL words', function () use ($root) {
	$dir = "$root/application/models/zzwords";
	test_fixture($dir);
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
		// list options, as render.cms takes them (#85)
		same(array('Lima', 'Rome'), array_column(cms_records::find('zzword', 'when>=2026-11-01', 'when'), 'title'));
		same(array('Rome', 'Lima'), array_column(cms_records::find('zzword', 'from!=Berlin&order=-when'), 'title'), 'order from the options');
		same(array('Oslo'), array_column(cms_records::find('zzword', 'group<3&from=Berlin'), 'title'));
		same(array('Rome'), array_column(cms_records::find('zzword', 'when>=2026-10-15&order=-when&limit=1'), 'title'), 'limit from the options');
		same(array(), cms_records::find('zzword', 'when>=today&when<2026-01-01'), 'today is a date');
		try {
			cms_records::find('zzword', 'whenn>=2026-11-01');
			check(false, 'a field the type does not have');
		} catch (InvalidArgumentException $e) { same("zzword has no field 'whenn'", $e->getMessage()); }
		// every record unless a limit is given (#85)
		R::begin();
		for ($i = 0; $i < 1005; $i++) R::exec('INSERT INTO zzworddata (title, `when`, `from`, `group`) VALUES (?, ?, ?, ?)', array("Old $i", '2020-01-01', 'Paris', 0));
		R::commit();
		same(1008, count(cms_records::find('zzword')), 'no cap at 1,000');
		same(array('Rome'), array_column(cms_records::find('zzword', array(), '-when', 1), 'title'), 'the limit argument');
		same(array('Oslo', 'Lima', 'Rome'), array_column(cms_records::find('zzword', 'when>=2026-01-01', 'when'), 'title'), 'the coming ones, past 1,000 old ones');
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
	R::getDatabaseAdapter()->exec('CREATE TABLE zzoldpubdata (id INTEGER PRIMARY KEY '.(on_mysql() ? 'AUTO_INCREMENT' : 'AUTOINCREMENT').', title TEXT, published_at TEXT, updated_at TEXT)');
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
	$db = test_db(sys_get_temp_dir().'/raster-user-'.getmypid().'.sqlite');
	$env = 'RASTER_ENV=development RASTER_DB='.escapeshellarg($db);
	$raster = function ($args, $more = '') use ($env, $root) {
		exec("$env $more ".php_cmd().' '.escapeshellarg("$root/bin/raster").' '.$args.' 2>&1', $out, $code);
		same(0, $code, implode("\n", $out));
		return implode("\n", $out);
	};
	$account = function ($login, $password) use ($env, $root) {
		$code = 'require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); authentication::connect();'
			.' $u = authentication::find('.var_export($login, true).'); echo $u->role, " ", authentication::check_login('.var_export($login, true).', '.var_export($password, true).') ? "ok" : "fail";';
		return trim(shell_exec("$env ".php_cmd().' -r '.escapeshellarg($code).' 2>&1'));
	};
	try {
		$raster('user reader@example.test --role=member --password=first-password');
		same('member ok', $account('reader@example.test', 'first-password'));
		// the password from standard input, or the environment, out of the process list
		$stdin = tempnam(sys_get_temp_dir(), 'raster-pw-');
		file_put_contents($stdin, "piped password\n");
		$out = $raster('user piped@example.test --role=member --password=- < '.escapeshellarg($stdin));
		unlink($stdin);
		check(strpos($out, 'Password:') === false && strpos($out, 'note:') === false, $out);
		same('member ok', $account('piped@example.test', 'piped password'));
		$raster('user envpass@example.test --role=member', 'RASTER_PASSWORD='.escapeshellarg('from the env'));
		same('member ok', $account('envpass@example.test', 'from the env'));
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

test('wrong passwords sent at once each count against the lock', function () {
	authentication::connect();
	$id = authentication::save_user('burst@example.test', 'right password', 'member');
	race_php('authentication::check_login("burst@example.test", "wrong ".$n);', 4);
	reconnect();
	same('4', (string)R::getCell('SELECT failed_count FROM user WHERE id = ?', array($id)), 'four guesses at once, four tries counted');
	race_php('authentication::check_login("burst@example.test", "wrong ".$n);', 4);
	reconnect();
	same(false, authentication::check_login('burst@example.test', 'right password'), 'eight wrong guesses lock it');
});
test('one address gets login_per_ip tries an hour, whatever the accounts', function () use ($base) {
	authentication::connect();
	authentication::save_user('per-ip@example.test', 'right password', 'member');
	spam_clear();
	$login = function ($who, $password) use ($base) {
		return form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => $who, 'password' => $password));
	};
	// 20 tries at 20 accounts, none of them locked, most of them not there
	for ($i = 0; $i < 20; $i++) check(strpos($login("nobody$i@example.test", 'guess')[1], 'Wrong email or password') !== false, "try $i");
	list($status, $body) = $login('per-ip@example.test', 'right password');
	same(200, $status, 'the 21st, the right password included, is not checked');
	check(strpos($body, 'Too many tries from here') !== false && strpos($body, 'Wrong email or password') === false, $body);
	same(0, (int)R::getCell('SELECT failed_count FROM user WHERE email = ?', array('per-ip@example.test')), 'and counts nothing against the account');
	spam_clear();
	same(303, $login('per-ip@example.test', 'right password')[0], 'a new hour, or another address');
});
test('a login that works leaves what changed meanwhile alone', function () {
	authentication::connect();
	$id = authentication::save_user('stale@example.test', 'right password', 'member');
	$user = authentication::find('stale@example.test');
	// another request renames the account while this login hashes
	R::exec('UPDATE user SET name = ? WHERE id = ?', array('Renamed', $id));
	same($id, authentication::check_login('stale@example.test', 'right password'));
	same('Renamed', (string)R::getCell('SELECT name FROM user WHERE id = ?', array($id)));
});
test('an old md5 password logs in once and is rehashed, but 1.x\'s admin/admin never does', function () {
	authentication::connect();
	foreach (array('legacyadmin' => 'legacyadmin', 'legacybob' => 'bobs secret') as $name => $password) {
		R::exec('DELETE FROM user WHERE username = ?', array($name));
		$user = R::dispense('user');
		$user->username = $name; $user->email = ''; $user->name = $name; $user->role = 'admin';
		$user->password = md5($password);
		R::store($user);
	}
	same(false, authentication::check_login('legacyadmin', 'legacyadmin'), 'a password that is the account\'s name');
	$id = authentication::check_login('legacybob', 'bobs secret');
	check($id > 0, 'an old account logs in');
	check(strpos((string)R::getCell('SELECT password FROM user WHERE id = ?', array($id)), '$') === 0, 'and its password is rehashed');
});
test('the account form\'s current password counts against the lock', function () use ($base) {
	authentication::connect();
	authentication::save_user('guess@example.test', 'right password', 'member');
	list(, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => 'guess@example.test', 'password' => 'right password'));
	$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
	$page = http('GET', "$base/account", null, array("Cookie: $cookie"))[1];
	check(preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m), 'the account form');
	$change = function ($current) use ($base, $cookie, $m) {
		return form_post("$base/account", array('raster_form' => 'authentication.account', 'csrf' => $m[1], 'name' => 'Guess', 'email' => 'guess@example.test', 'password' => 'a new password', 'current_password' => $current), array("Cookie: $cookie"));
	};
	for ($i = 0; $i < 5; $i++) check(strpos($change('wrong '.$i)[1], 'Your current password is not right') !== false, "wrong guess $i");
	check(strpos($change('right password')[1], 'Your current password is not right') !== false, 'locked after five wrong guesses');
	same(false, authentication::check_login('guess@example.test', 'right password'), 'and so is the login');
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
test('the editor leaves Enter to the browser in a list or a table, and makes a line break in a paragraph (#114)', function () use ($root) {
	$node = trim((string)shell_exec('command -v node 2>/dev/null'));
	if ($node === '') return; // no node here: tests/editor-browser.js covers it in a browser
	$js = file_get_contents("$root/system/models/cms/editor/editor.js");
	check(preg_match('/\tfunction takesBlocks\(el\) \{.*?\n\t\}\n/s', $js, $m), 'editor.js has takesBlocks()');
	$cases = array('DIV' => true, 'TD' => true, 'UL' => true, 'OL' => true, 'DL' => true, 'TABLE' => true, 'TBODY' => true,
		'P' => false, 'H2' => false, 'LI' => false, 'SPAN' => false, 'A' => false,
		// the editor's own wrapper: a <div> is judged by its parent, a <span> never takes blocks
		'DIV in ARTICLE' => true, 'DIV in TD' => true, 'DIV in LI' => false, 'SPAN in ARTICLE' => false, 'SPAN in P' => false);
	$file = sys_get_temp_dir().'/raster-blocks-'.getmypid().'.js';
	file_put_contents($file, $m[0]."\nvar cases = ".json_encode(array_keys($cases)).";\n"
		."function el(t, wrap, parent) { return { tagName: t, nodeType: 1, parentNode: parent || null, hasAttribute: function (a) { return !!wrap && a === 'data-raster-wrap'; } }; }\n"
		."process.stdout.write(JSON.stringify(cases.map(function (c) { var p = c.split(' in '); return takesBlocks(p[1] ? el(p[0], true, el(p[1])) : el(p[0])); })));\n");
	$out = shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
	unlink($file);
	same(array_values($cases), json_decode((string)$out, true), (string)$out);
	// every field the editor makes knows it: those found in the page, those
	// of an item added or copied in the page, and those of the new-item card
	check(substr_count($js, 'inline: !holdsBlocks(node)') === 1 && strpos($js, 'field.inline = !holdsBlocks(field.el)') !== false, 'adopt() and scan() set inline');
	check(strpos($js, 'clean(node.innerHTML, !holdsBlocks(node))') !== false, 'a new item is cleaned for where it sits');
});
test('a rich field that already holds paragraphs keeps them, in an <li> or an <a> card too', function () use ($root) {
	$node = trim((string)shell_exec('command -v node 2>/dev/null'));
	if ($node === '') return;
	$js = file_get_contents("$root/system/models/cms/editor/editor.js");
	$code = '';
	foreach (array('isBlock', 'takesBlocks', 'holdsBlocks') as $name) {
		check(preg_match('/\tfunction '.$name.'\(\w+\) \{.*?\n\t\}\n/s', $js, $m), "editor.js has $name()");
		$code .= $m[0];
	}
	// "A holding P": an <a> whose children are a <p>; "DIV in LI": the editor's wrapper
	$cases = array('LI holding P P' => true, 'A holding P P' => true, 'DT holding UL' => true, 'DIV in LI holding P P' => true,
		'LI' => false, 'LI holding EM' => false, 'A holding SPAN' => false, 'DIV in LI' => false, 'SPAN in LI holding EM' => false,
		'P' => false, 'H2 holding EM' => false, 'ARTICLE' => true, 'DIV in TD' => true);
	$file = sys_get_temp_dir().'/raster-holds-'.getmypid().'.js';
	file_put_contents($file, $code."\nvar cases = ".json_encode(array_keys($cases)).";\n"
		."function el(t, wrap, parent, kids) { return { tagName: t, nodeType: 1, parentNode: parent || null, childNodes: (kids || []).map(function (k) { return el(k); }), hasAttribute: function (a) { return !!wrap && a === 'data-raster-wrap'; } }; }\n"
		."process.stdout.write(JSON.stringify(cases.map(function (c) { var h = c.split(' holding '), kids = h[1] ? h[1].split(' ') : [], p = h[0].split(' in '); return holdsBlocks(p[1] ? el(p[0], true, el(p[1]), kids) : el(p[0], false, null, kids)); })));\n");
	$out = shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
	unlink($file);
	same(array_values($cases), json_decode((string)$out, true), (string)$out);
	// Enter in the new-item card follows the same rule
	check(strpos($js, "!holdsBlocks(fresh)") !== false, 'the new-item card asks holdsBlocks()');
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
	$process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
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
	test_fixture($link);
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

// the confirmation mails logged in $dir: address => the tokens their links carry
function confirmation_links($dir) {
	$links = array();
	foreach (glob("$dir/*.eml") ?: array() as $file) {
		$raw = file_get_contents($file);
		if (!preg_match('/^To: (.*)$/m', $raw, $to)) continue;
		$text = preg_match('/Content-Type: text\/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $raw, $m) ? base64_decode($m[1]) : '';
		$links[trim($to[1])][] = preg_match('/token=([a-f0-9]{40})/', $text, $t) ? $t[1] : '';
	}
	return $links;
}
$subscribed_seen = array();
function test_newsletter_subscribed($payload) {
	global $subscribed_seen;
	$subscribed_seen[] = $payload['email'].' '.$payload['status'];
}

test('many sign-ups at once with the same addresses leave one subscriber each', function () use ($maildir) {
	reconnect();
	newsletter::subscribe('race-seed@example.com');
	$dir = "$maildir-race";
	array_map('unlink', glob("$dir/*.eml") ?: array());
	$out = race_php('for ($i = 0; $i < 25; $i++) newsletter::subscribe("race$i@example.com", "Reader $n", "/race");', 8, array('RASTER_MAIL' => "log://$dir"));
	same('', implode('', $out), 'the processes said:');
	$counts = array();
	foreach (R::getAll("SELECT email, COUNT(*) AS n FROM subscriber WHERE email LIKE 'race%@example.com' AND email != 'race-seed@example.com' GROUP BY email") as $row) $counts[$row['email']] = (int)$row['n'];
	same(25, count($counts), 'addresses:');
	same(array(), array_filter($counts, function ($n) { return $n > 1; }), 'addresses stored more than once:');
	// every confirmation an address got carries the one link that works
	$links = confirmation_links($dir);
	same(25, count($links), 'addresses that got a confirmation:');
	foreach ($links as $email => $tokens) {
		same(array(R::getCell('SELECT token FROM subscriber WHERE email = ?', array($email))), array_values(array_unique($tokens)), "the links $email got:");
	}
	// each one is noted as sent, under the lock, before it goes (#213)
	same(25, array_sum(array_map('count', $links)), 'confirmations sent for 25 addresses:');
});

test('signing up again within 5 minutes sends nothing; later, the same link again (#77 #47)', function () use ($maildir) {
	cms_store::connect();
	$before = count(glob("$maildir/*.eml"));
	same('pending', newsletter::subscribe('twice@example.com', 'Twice'));
	$token = R::getCell('SELECT token FROM subscriber WHERE email = ?', array('twice@example.com'));
	same('pending', newsletter::subscribe('Twice@Example.com ', 'Twice again'), 'the same answer:');
	same($token, R::getCell('SELECT token FROM subscriber WHERE email = ?', array('twice@example.com')), 'the token after the second sign-up:');
	same(1, (int)R::getCell('SELECT COUNT(*) FROM subscriber WHERE email = ?', array('twice@example.com')));
	same($before + 1, count(glob("$maildir/*.eml")), 'mails within 5 minutes:');
	R::exec('UPDATE subscriber SET confirm_sent_at = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() - 6 * 60), 'twice@example.com'));
	same('pending', newsletter::subscribe('twice@example.com', 'Twice'));
	same($before + 2, count(glob("$maildir/*.eml")), 'mails 6 minutes later:');
	same(array($token, $token), confirmation_links($maildir)['twice@example.com'], 'the links the two mails carry:');
});

test('a sign-up inside a transaction is mailed after the commit, and not at all after a rollback (#77)', function () use ($maildir) {
	global $subscribed_seen;
	cms_store::connect();
	$subscribed_seen = array();
	event::bind('newsletter.subscribed')->to(null, 'test_newsletter_subscribed');
	try {
		$before = count(glob("$maildir/*.eml"));
		try {
			cms_records::transaction(function () {
				same('pending', newsletter::subscribe('rolled@example.com', 'Rolled'));
				cms_records::refuse('changed_my_mind');
			});
		} catch (cms_refused $e) {}
		same(0, (int)R::getCell('SELECT COUNT(*) FROM subscriber WHERE email = ?', array('rolled@example.com')), 'subscribers after the rollback:');
		same($before, count(glob("$maildir/*.eml")), 'mails after the rollback:');
		same(array(), $subscribed_seen, 'newsletter.subscribed after the rollback:');
		$inside = null;
		cms_records::transaction(function () use (&$inside, $maildir) {
			same('pending', newsletter::subscribe('kept@example.com', 'Kept'));
			$inside = array(count(glob("$maildir/*.eml")), $GLOBALS['subscribed_seen']);
		});
		same(array($before, array()), $inside, 'mails and events before the commit:');
		same(array('kept@example.com pending'), $subscribed_seen, 'newsletter.subscribed after the commit:');
		same($before + 1, count(glob("$maildir/*.eml")), 'mails after the commit:');
		same(array(R::getCell('SELECT token FROM subscriber WHERE email = ?', array('kept@example.com'))), confirmation_links($maildir)['kept@example.com']);
	} finally {
		event::unbind('newsletter.subscribed')->from(null, 'test_newsletter_subscribed');
	}
});

test('the confirmation mail comes from a listener a site can unbind (#77)', function () use ($maildir) {
	cms_store::connect();
	$bindings = event::bindings();
	$listeners = isset($bindings['newsletter.subscribed']) ? $bindings['newsletter.subscribed'] : array();
	check(in_array('newsletter.confirmation_mail', $listeners, true), 'newsletter.subscribed listeners: '.json_encode($listeners));
	$before = count(glob("$maildir/*.eml"));
	event::unbind('newsletter.subscribed')->from('newsletter', 'confirmation_mail');
	try {
		same('pending', newsletter::subscribe('own-mail@example.com'));
		same($before, count(glob("$maildir/*.eml")), 'mails with the listener unbound:');
	} finally {
		event::bind('newsletter.subscribed')->to('newsletter', 'confirmation_mail');
	}
	// confirmed or unknown addresses get nothing, whoever sends the event
	event::dispatch('newsletter.subscribed', array('email' => 'nobody@example.com', 'name' => '', 'status' => 'pending', 'source' => ''));
	event::dispatch('newsletter.subscribed', array('email' => 'own-mail@example.com', 'name' => '', 'status' => 'confirmed', 'source' => ''));
	same($before, count(glob("$maildir/*.eml")), 'mails for events that ask for none:');
});

// ## 2.1.8 wave 2: content saves and the cache bump

// Several PHP processes running $code at the same moment on the test
// database, the way requests that arrive together reach one SQLite file.
// Each boots the app, waits for one start time (so none is done before the
// last has booted), then runs $code with $n set to its number, from 0.
// Returns what each printed, errors included.
function race_php($code, $count = 4, $env = array()) {
	global $root, $db, $maildir;
	reconnect();
	$start = sprintf('%.6F', microtime(true) + 0.5 + 0.1 * $count);
	$env = array_merge(array('RASTER_DB' => $db, 'RASTER_ENV' => 'development', 'RASTER_MAIL' => "log://$maildir", 'PATH' => getenv('PATH')), $env);
	$processes = array();
	for ($n = 0; $n < $count; $n++) {
		$script = 'require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); cms_store::connect(); $n = '.$n.';'
			.' while (microtime(true) < '.$start.') usleep(100); '.$code;
		$process = proc_open(php_argv('-r', $script), array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $root, $env);
		$processes[$n] = array($process, $pipes[1]);
	}
	$out = array();
	foreach ($processes as $n => $process) {
		$out[$n] = stream_get_contents($process[1]);
		proc_close($process[0]);
	}
	return $out;
}
// This process opens the test database again. Tests above copy() the file,
// and closing any handle on it drops SQLite's locks in this process: other
// processes then take this one for gone and delete the WAL index (-shm)
// under it ("disk I/O error").
function reconnect() {
	cms_store::connect();
	R::getDatabaseAdapter()->getDatabase()->close();
}
// a content_changed listener: what another connection to the database sees
// when it runs
$changed_seen = array();
function test_content_changed() {
	global $changed_seen, $db;
	$other = test_pdo($db);
	$changed_seen[] = (int)$other->query('SELECT COUNT(*) FROM wavetwodata')->fetchColumn();
}

test('items created at once with one title all get their own slug', function () {
	reconnect();
	cms_store::save_item('wavetwodata', 0, array('title' => 'Seed'), array('title'));
	$out = race_php('for ($i = 0; $i < 15; $i++) cms_store::save_item("wavetwodata", 0, array("title" => "Harvest supper"), array("title"));', 6);
	same('', implode('', $out), 'the processes said:');
	$slugs = R::getCol('SELECT slug FROM wavetwodata WHERE title = ?', array('Harvest supper'));
	same(90, count($slugs), 'items:');
	same(90, count(array_unique($slugs)), 'distinct slugs of 90:');
});

test('a collection table has an index on slug, and a new item finds a free slug in one query', function () {
	reconnect();
	// as the CMS makes a collection's table on its first render
	cms_types::ensure('slugindexdata', array('title' => 'text') + array_intersect_key(cms_types::$system, array_flip(array('slug', 'enabled', 'published_at', 'updated_at'))));
	same(false, cms_types::index_slug('slugindexdata'), 'the new table already has its index');
	cms_store::save_item('slugindexdata', 0, array('title' => 'Same'), array('title'));
	for ($i = 2; $i <= 60; $i++) R::exec("INSERT INTO slugindexdata (title, slug) VALUES ('Same', ?)", array("same-$i"));
	R::exec("INSERT INTO slugindexdata (title, slug) VALUES ('Other', 'same-thing')");
	cms_store::columns('slugindexdata');
	R::startLogging();
	$slug = cms_store::unique_slug('slugindexdata', 'Same', 0);
	$queries = array_filter(R::getLogs(), function ($line) { return is_string($line) && stripos($line, 'slugindexdata') !== false && stripos($line, 'slug') !== false && stripos($line, 'select') !== false; });
	R::stopLogging();
	same('same-61', $slug);
	same(1, count($queries), 'queries for one slug: '.implode(' | ', $queries));
	same('same-thing-2', cms_store::unique_slug('slugindexdata', 'Same thing', 0), 'a slug that starts like another is its own');
	R::exec('CREATE TABLE slugoldindexdata (id INTEGER PRIMARY KEY '.(on_mysql() ? 'AUTO_INCREMENT' : 'AUTOINCREMENT').', title TEXT, slug TEXT)');
	cms_store::forget();
	same(true, cms_types::index_slug('slugoldindexdata'), 'a table made before has none');
	same(false, cms_types::index_slug('slugoldindexdata'), 'and has it once made');
});

test('two page saves at once, each changing its own field, keep both changes', function () {
	cms_store::connect();
	cms_store::update_page('wavetwopage', '/wave-two', array('a' => 'a-0', 'b' => 'b-0'), array('a', 'b'));
	$out = race_php('$field = $n ? "b" : "a"; for ($i = 1; $i <= 40; $i++) cms_store::update_page("wavetwopage", "/wave-two", array($field => "$field-$i"), array("a", "b"));', 2);
	same('', implode('', $out), 'the processes said:');
	// each revision starts from the one before: a field never goes back to
	// an older value
	$last = array('a' => 0, 'b' => 0);
	$lost = 0;
	foreach (R::getAll('SELECT a, b FROM wavetwopage ORDER BY id') as $row) {
		foreach ($last as $field => $seen) {
			$value = (int)substr($row[$field], 2);
			if ($value < $seen) $lost++;
			$last[$field] = max($seen, $value);
		}
	}
	$newest = cms_store::page_values('wavetwopage');
	same(array('a' => 'a-40', 'b' => 'b-40'), array('a' => $newest['fields']['a'], 'b' => $newest['fields']['b']), 'the newest revision:');
	same(0, $lost, 'revisions that lost the other save:');
});

test('a rolled back transaction neither throws the page cache away nor sends content_changed (#81)', function () {
	global $changed_seen;
	cms_store::connect();
	$changed_seen = array();
	event::bind('content_changed')->to(null, 'test_content_changed');
	try {
		raster_cache::bump();
		$version = raster_cache::version();
		$rows = R::count('wavetwodata');
		$refused = null;
		try {
			cms_records::transaction(function () {
				cms_store::save_item('wavetwodata', 0, array('title' => 'Never kept'), array('title'));
				cms_store::update_page('wavetwopage', '/wave-two', array('a' => 'a-never'), array('a', 'b'));
				cms_records::refuse('changed_my_mind');
			});
		} catch (cms_refused $e) {
			$refused = $e->problems;
		}
		same(array('changed_my_mind'), $refused);
		same($rows, R::count('wavetwodata'), 'rows:');
		same(array(), $changed_seen, 'content_changed listeners ran, seeing rows:');
		same($version, raster_cache::version(), 'the cache version moved:');
	} finally {
		event::unbind('content_changed')->from(null, 'test_content_changed');
	}
});

test('after a commit, content_changed comes once and its listeners see the new rows (#81)', function () {
	global $changed_seen;
	cms_store::connect();
	$changed_seen = array();
	event::bind('content_changed')->to(null, 'test_content_changed');
	try {
		$version = raster_cache::version();
		$rows = R::count('wavetwodata');
		cms_records::transaction(function () {
			$item = cms_store::save_item('wavetwodata', 0, array('title' => 'Kept'), array('title'));
			cms_store::save_item('wavetwodata', 0, array('title' => 'Kept too'), array('title'));
			cms_store::save_item('wavetwodata', $item['id'], array('title' => 'Kept, renamed'), array('title'));
			cms_store::update_page('wavetwopage', '/wave-two', array('a' => 'a-kept'), array('a', 'b'));
		});
		same(array($rows + 2), $changed_seen, 'content_changed, with the rows another connection saw:');
		check($version !== raster_cache::version(), 'the cache version moved');
		// a markup item deleted inside a rolled back transaction stays
		$changed_seen = array();
		$item = R::findOne('wavetwodata', ' title = ? ', array('Kept too'));
		try {
			cms_records::transaction(function () use ($item) {
				cms_store::delete_item('wavetwodata', $item->id);
				throw new RuntimeException('stop');
			});
		} catch (RuntimeException $e) {}
		check(R::findOne('wavetwodata', ' title = ? ', array('Kept too')) !== null, 'the item is still there');
		same(array(), $changed_seen, 'content_changed after the delete was rolled back:');
		// outside a transaction it comes at once, as before
		util::content_changed();
		same(array($rows + 2), $changed_seen);
	} finally {
		event::unbind('content_changed')->from(null, 'test_content_changed');
	}
});

// ## Phase 1 groundwork: the site's time zone, mail after the answer

// Applies config timezone as a request would (config::initialize), runs
// $fn, then puts PHP's own zone back
function with_timezone($zone, $fn) {
	$was = date_default_timezone_get();
	config::set('timezone')->to($zone);
	try {
		config::initialize();
		return $fn();
	} finally {
		config::set('timezone')->to(null);
		date_default_timezone_set($was);
	}
}

test('the site time zone sets today and published_at (#84)', function () use ($root, $db) {
	cms_store::connect();
	// UTC+14; before 10:00 UTC its date is still UTC's, so then the test
	// takes Niue (UTC-11), whose date is UTC's yesterday until 11:00
	$zone = (int)gmdate('G') >= 10 ? 'Pacific/Kiritimati' : 'Pacific/Niue';
	$there = new DateTime('now', new DateTimeZone($zone));
	check($there->format('Y-m-d') !== gmdate('Y-m-d'), "$zone and UTC have different dates now");
	$dir = "$root/application/models/zztz";
	test_fixture($dir);
	@mkdir($dir);
	file_put_contents("$dir/zztz.php", '<?php class zztz { static function types() { return array("zztzday" => array("fields" => array("title" => "", "day" => ""), "types" => array("day" => "date"), "public" => true)); } }');
	$view = "$root/application/views/default/zz-tz.html";
	test_fixture($view);
	file_put_contents($view, "<html><body><ul><!-- render.cms.zztzday('day>=today&day<=today') --><li><!-- print.title -->x<!-- /print.title --></li><!-- /render.cms.zztzday('day>=today&day<=today') --></ul>"
		."<ol><!-- render.cms.news('limit=100') --><li><!-- print.headline -->x<!-- /print.headline --></li><!-- /render.cms.news('limit=100') --></ol></body></html>");
	$config = "$root/application/config/the_app.php";
	$settings = file_get_contents($config);
	// the page as a request on a UTC server renders it
	$render = function () use ($root, $db) {
		$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development';
		return (string)shell_exec("$env ".php_cmd().' -d date.timezone=UTC '.escapeshellarg("$root/bin/raster").' render /zz-tz 2>&1');
	};
	cms_records::forget();
	try {
		cms_records::create('zztzday', array('title' => 'UTC today', 'day' => gmdate('Y-m-d')));
		cms_records::create('zztzday', array('title' => 'Site today', 'day' => $there->format('Y-m-d')));
		// a minute off on the site's clock is hours off on UTC's: ahead of
		// UTC a minute ago is still to come by UTC; behind it a minute from
		// now has long passed by UTC
		$ahead = $there->getOffset() > 0;
		$stamp_at = (clone $there)->modify($ahead ? '-1 minute' : '+1 minute')->format('Y-m-d H:i:s');
		cms_store::save_item('newsdata', 0, array('headline' => 'Zone news', 'published_at' => $stamp_at), array('headline', 'published_at'));
		$utc = $render();
		check(strpos($utc, '<li>UTC today</li>') !== false && strpos($utc, 'Site today') === false, "without a time zone today is UTC's: $utc");
		check((strpos($utc, 'Zone news') !== false) === !$ahead, $ahead ? 'and the news is still scheduled' : 'and the news is already live');
		file_put_contents($config, $settings."\nconfig::set('timezone')->to('$zone');\n");
		$zoned = $render();
		check(strpos($zoned, '<li>Site today</li>') !== false, "today is the site's date: $zoned");
		check(strpos($zoned, 'UTC today') === false, "an item dated today in UTC is not today on the site: $zoned");
		check((strpos($zoned, '<li>Zone news</li>') !== false) === $ahead, $ahead ? 'published a minute ago on the site clock is live' : 'published a minute from now on the site clock is scheduled');
		// stamps follow it too
		$stamp = with_timezone($zone, function () {
			$item = cms_records::create('zztzday', array('title' => 'Stamped', 'day' => ''));
			return $item['created_at'];
		});
		check(abs(strtotime($stamp.' '.$zone) - time()) < 120, "created_at is stamped on the site's clock: $stamp");
		// an unknown zone keeps PHP's own
		$errors = ini_get('error_log');
		$logged = sys_get_temp_dir().'/raster-tz-log-'.getmypid().'.txt';
		ini_set('error_log', $logged);
		try {
			$kept = with_timezone('Mars/Olympus', function () { return date_default_timezone_get(); });
		} finally {
			ini_set('error_log', (string)$errors);
		}
		same(date_default_timezone_get(), $kept, 'an unknown zone:');
		check(strpos((string)@file_get_contents($logged), "Unknown timezone 'Mars/Olympus'") !== false, 'an unknown zone is logged');
		@unlink($logged);
	} finally {
		file_put_contents($config, $settings);
		unlink("$dir/zztz.php");
		rmdir($dir);
		unlink($view);
		cms_records::forget();
		R::exec("DELETE FROM newsdata WHERE headline = 'Zone news'");
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zztzdaydata');
		cms_store::forget();
	}
});

test('an unknown mail transport never shows its password', function () {
	$before = getenv('RASTER_MAIL');
	putenv('RASTER_MAIL=bogus://user:s3cret@mail.example.test:25');
	$errors = ini_get('error_log');
	$logged = sys_get_temp_dir().'/raster-dsn-log-'.getmypid().'.txt';
	ini_set('error_log', $logged);
	try {
		same(false, mail::send('a@example.test', 'Hi', '<p>Hi</p>'), 'an unknown transport:');
		check(strpos((string)mail::$last_error, 'bogus://mail.example.test:25') !== false, 'the error names where mail was going: '.mail::$last_error);
		check(strpos((string)mail::$last_error, 's3cret') === false, 'the error has no password');
		check(strpos((string)@file_get_contents($logged), 's3cret') === false, 'the log has no password');
	} finally {
		ini_set('error_log', (string)$errors);
		putenv($before === false ? 'RASTER_MAIL' : "RASTER_MAIL=$before");
		@unlink($logged);
	}
});

// ## SQL for bundled models (#94 #127 #95)
// Last: they add accounts and subscribers, which the schema tests above
// would count as drift.

test('a model\'s queries survive calls into bundled models (#94)', function () use ($maildir) {
	$app = APPBASE.config::get('models_path', 'models');
	$dir = sys_get_temp_dir().'/raster-zzq-'.getmypid();
	@mkdir($dir);
	// an email that prints a CMS field, as the bundled emails do
	file_put_contents("$dir/email.html", "<html><head><title>Hi</title></head><body><!-- print.cms.zzq_line -->Line<!-- /print.cms.zzq_line --></body></html>");
	// a view that calls the CMS between two of zzq's own queries
	file_put_contents("$dir/page.html", "<p><!-- print.zzq.after /--></p><h1><!-- print.cms.zzq_heading -->H<!-- /print.cms.zzq_heading --></h1><p><!-- print.zzq.again /--></p>");
	temp_model("$app/zzq", array(
		'zzq.php' => "<?php\nclass zzq\n{\n\tstatic function types() {\n\t\treturn array('zzqnote' => array('fields' => array('name' => '')));\n\t}\n"
			."\tfunction after() {\n\t\t\$seen = array();\n"
			."\t\tcms_records::find('zzqnote');\n\t\t\$seen[] = database::instance()->one()[0]['one'];\n"
			."\t\tnewsletter::subscribe('zzq@example.test');\n\t\t\$seen[] = database::instance()->one()[0]['one'];\n"
			."\t\tmail::send_view(".var_export("$dir/email.html", true).", 'zzq@example.test');\n\t\t\$seen[] = database::instance()->one()[0]['one'];\n"
			."\t\treturn implode(',', \$seen);\n\t}\n"
			."\tfunction again() {\n\t\treturn 'again '.database::instance()->one()[0]['one'];\n\t}\n}\n",
	));
	temp_model("$app/zzq/sql", array('one.sql' => "SELECT 1 AS one\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		reconnect();
		cms_records::find('zzqnote');
		same('1', (string)database::instance('zzq')->one()[0]['one'], 'named, after find():');
		newsletter::subscribe('zzq-direct@example.test');
		same('1', (string)database::instance('zzq')->one()[0]['one'], 'named, after subscribe():');
		// a name applies to that call only
		check(database::instance()->current_model !== 'zzq', 'the name stuck to the shared instance');
		$html = controller::render_view("$dir/page.html");
		check(strpos($html, '<p>1,1,1</p>') !== false, 'zzq\'s own query from its template method: '.$html);
		check(strpos($html, '<p>again 1</p>') !== false, 'zzq\'s own query after the view printed a CMS field: '.$html);
	} finally {
		remove_dir("$app/zzq");
		array_map('unlink', glob("$dir/*") ?: array());
		@rmdir($dir);
		controller::forget_models();
		cms_records::forget();
	}
});

test('bundled and the_ queries are found (#127 #41)', function () {
	check(database::query_file('newsletter', 'confirmed_count') !== null, 'the bundled newsletter has no confirmed_count.sql');
	same(null, database::query_file('newsletter', 'no_such_query'));
	$app = APPBASE.config::get('models_path', 'models');
	temp_model(BASE.'models/zzq', array('zzq.php' => "<?php\nclass zzq\n{\n}\n"));
	temp_model(BASE.'models/zzq/sql', array('one.sql' => "SELECT 1 AS one\n", 'two.sql' => "SELECT 2 AS two\n"));
	temp_model("$app/the_zzq", array('the_zzq.php' => "<?php\nclass the_zzq extends zzq\n{\n}\n"));
	temp_model("$app/the_zzq/sql", array('two.sql' => "SELECT 22 AS two\n"));
	try {
		controller::forget_models();
		same("$app/the_zzq/sql/two.sql", database::query_file('zzq', 'two'), 'the override\'s file wins:');
		same(BASE.'models/zzq/sql/one.sql', database::query_file('zzq', 'one'), 'the bundled file is still found:');
		same('22', (string)database::instance('zzq')->two()[0]['two']);
		same('1', (string)database::instance('zzq')->one()[0]['one']);
		$vocabulary = (new raster_inspector())->vocabulary();
		check(in_array('confirmed_count', $vocabulary['models']['newsletter']['queries'], true), 'vocabulary: '.json_encode($vocabulary['models']['newsletter']['queries']));
		same(array('one', 'two'), $vocabulary['models']['zzq']['queries']);
	} finally {
		remove_dir(BASE.'models/zzq');
		remove_dir("$app/the_zzq");
		controller::forget_models();
	}
});

test('percent signs in SQL files run as written (#95)', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$shared = "$app/sql.php";
	check(!is_file($shared), 'the starter has a models/sql.php');
	temp_model("$app/zzpct", array('zzpct.php' => "<?php\nclass zzpct\n{\n}\n"));
	// the day of the month, written as each database writes it
	$day = on_mysql() ? "DATE_FORMAT(%s, '%%d')" : "strftime('%%d', %s)";
	temp_model("$app/zzpct/sql", array(
		'pct.sql' => 'SELECT '.sprintf($day, "'2026-10-05'")." AS d\n",
		'like.sql' => "SELECT name FROM (SELECT 'pea soup' AS name) AS t WHERE name LIKE '%soup%'\n",
		'bound.sql' => 'SELECT '.sprintf($day, '?')." AS d\n",
	));
	file_put_contents($shared, "<?php\n\$queries['zzquoted'] = \"SELECT '%s' AS v\";\n");
	try {
		controller::forget_models();
		same('05', (string)database::instance('zzpct')->pct()[0]['d']);
		same('pea soup', (string)database::instance('zzpct')->like()[0]['name']);
		same('07', (string)database::instance('zzpct')->bound('2026-10-07')[0]['d']);
		// models/sql.php keeps its '%s', quoted by the driver
		same("it's", (string)database::instance()->zzquoted("it's")[0]['v']);
	} finally {
		remove_dir("$app/zzpct");
		@unlink($shared);
		controller::forget_models();
	}
});

test('find() refuses ?param and handles owner=me (#176)', function () {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/zzown", array('zzown.php' => "<?php\nclass zzown\n{\n\tstatic function types() {\n\t\treturn array('zzown' => array('fields' => array('title' => ''), 'owner' => true));\n\t}\n}\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		reconnect();
		$member = authentication::create_user('zzown-member@example.test', 'a long password', 'member', 'Member');
		$other = authentication::create_user('zzown-other@example.test', 'a long password', 'member', 'Other');
		cms_records::create('zzown', array('title' => 'mine', 'owner' => $member));
		cms_records::create('zzown', array('title' => 'theirs', 'owner' => $other));
		$thrown = null;
		try { cms_records::find('zzown', 'title=?b'); } catch (InvalidArgumentException $e) { $thrown = $e->getMessage(); }
		same('find() takes values, not ?b: pass the value', $thrown);
		$titles = 'echo json_encode(array_merge(array_column(cms_records::find("zzown", "owner=me"), "title"), array("|"), array_column(cms_records::find("zzown", array("owner" => "me")), "title")));';
		$out = race_php('define("RASTER_RENDER_AS", "zzown-member@example.test"); '.$titles, 1);
		same(array('mine', '|', 'mine'), json_decode($out[0], true), 'as the member: '.$out[0]);
		$out = race_php($titles, 1);
		same(array('|'), json_decode($out[0], true), 'logged out: '.$out[0]);
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzowndata');
		remove_dir("$app/zzown");
		controller::forget_models();
		cms_records::forget();
	}
});

// ## Bundled booking: setup and slots

// A view that uses the bundled booking model, so its types count, with the
// guest's manage page (booking.html) and the six emails. $fn runs with
// them; the views, the booking tables and what was read of them are gone
// after.
function booking_site($fn) {
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzbook.html';
	$slots = "render.booking.slots('date=?date&resource=?resource&service=?service&people=?people')";
	$days = "render.booking.days('resource=?resource&service=?service')";
	$services = "render.booking.services('resource=?resource')";
	test_fixture($view);
	file_put_contents($view, "<html><head><title>Book</title></head><body>"
		."<ul><!-- $slots --><li class=\"slot\"><!-- print.time -->19:00<!-- /print.time -->-<!-- print.ends -->21:00<!-- /print.ends --></li><!-- /$slots --></ul>"
		."<ol><!-- $days --><li class=\"day\"><!-- print.date -->2026-10-20<!-- /print.date --></li><!-- /$days --></ol>"
		."<p><!-- render.booking.resources --><b class=\"resource\"><!-- print.name -->Ana<!-- /print.name --></b><!-- /render.booking.resources --></p>"
		."<p><!-- $services --><i class=\"service\"><!-- print.name -->Cut<!-- /print.name --></i><!-- /$services --></p>"
		."<!-- render.booking.form --><form method=\"post\"><input name=\"name\" required><input type=\"email\" name=\"email\"><input name=\"phone\">"
		."<input type=\"date\" name=\"date\" required><input name=\"time\" required><input type=\"number\" name=\"people\"><input name=\"resource\">"
		."<textarea name=\"notes\"></textarea><button>Book</button></form><!-- /render.booking.form -->"
		."</body></html>");
	// the guest's manage page, and the emails, each with its name as the subject
	$theme = dirname($view);
	$alerts = '';
	foreach (array('link_invalid', 'too_late', 'cancelled', 'rescheduled', 'fully_booked', 'not_a_slot') as $alert) {
		$alerts .= "<!-- print.validation.alert('$alert') --><p>ALERT $alert</p><!-- /print.validation.alert('$alert') -->";
	}
	$manage = "render.booking.slots('date=?date&for=manage')";
	test_fixture("$theme/booking.html");
	file_put_contents("$theme/booking.html", "<html><head><title>Your booking</title></head><body>$alerts"
		."<!-- render.booking.manage --><p class=\"booking\"><!-- print.name -->Ana<!-- /print.name --> <!-- print.email -->ana@example.test<!-- /print.email -->"
		." <!-- print.date -->2026-10-20<!-- /print.date --> <!-- print.time -->19:00<!-- /print.time --> <!-- print.status -->requested<!-- /print.status --></p>"
		."<form method=\"post\"><input type=\"hidden\" name=\"do\" value=\"cancel\"><button>Cancel</button></form>"
		."<form method=\"post\"><input type=\"hidden\" name=\"do\" value=\"change\"><input name=\"time\"><button>Change</button></form><!-- /render.booking.manage -->"
		."<ul><!-- $manage --><li class=\"slot\"><!-- print.time -->19:00<!-- /print.time --></li><!-- /$manage --></ul>"
		."</body></html>");
	$emails = array('booking_requested', 'booking_new', 'booking_confirmed', 'booking_declined', 'booking_cancelled', 'booking_changed');
	foreach ($emails as $email) {
		test_fixture("$theme/_email/$email.html");
		file_put_contents("$theme/_email/$email.html", "<html><head><title>$email</title></head><body><p><!-- print.self.name -->Ana<!-- /print.self.name -->,"
			." <!-- print.self.date -->2026-10-20<!-- /print.self.date --> <!-- print.self.time -->19:00<!-- /print.self.time --> <!-- print.self.resource_name -->room<!-- /print.self.resource_name -->"
			." <!-- print.self.status -->requested<!-- /print.self.status --> by <!-- print.self.who -->guest<!-- /print.self.who --></p>"
			."<p>Manage: <!-- print.self.manage_url -->none<!-- /print.self.manage_url --></p></body></html>");
	}
	// staff emails go to the sender, as on a site that sets only that
	putenv('RASTER_MAIL_FROM=staff@zz.test');
	controller::forget_models();
	cms_records::forget();
	cms_store::connect();
	if (class_exists('booking', false)) booking::forget();
	// this process booted without a view that uses booking, so it binds the
	// listeners system/config/events.php binds for a site that has one
	$listeners = array('cms.item_saved' => 'saved');
	foreach (array('requested', 'confirmed', 'declined', 'cancelled', 'rescheduled') as $step) $listeners["booking.$step"] = $step.'_mail';
	foreach ($listeners as $event => $method) event::bind($event)->to('booking', $method);
	try {
		return $fn();
	} finally {
		foreach ($listeners as $event => $method) event::unbind($event)->from('booking', $method);
		putenv('RASTER_MAIL_FROM');
		foreach (array('bookingresource', 'bookinghours', 'bookingclosed', 'bookingservice', 'booking') as $type) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS {$type}data");
		cms_store::forget();
		@unlink($view);
		@unlink("$theme/booking.html");
		foreach ($emails as $email) @unlink("$theme/_email/$email.html");
		controller::forget_models();
		cms_records::forget();
		if (class_exists('booking', false)) booking::forget();
	}
}
// a page of the site as a request renders it, and how the command ended
function booking_render($url) {
	global $root, $db;
	$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development';
	return (string)shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).' 2>&1; echo "[exit $?]"');
}
// what a setup write was refused for, or array() when it was stored
function booking_refused($type, $values, $id = 0) {
	try {
		$id ? cms_records::update($type, $id, $values) : cms_records::create($type, $values);
	} catch (cms_refused $e) {
		return $e->problems;
	}
	return array();
}

test('booking types count only where views use the model', function () use ($root) {
	controller::forget_models();
	cms_records::forget();
	check(cms_records::info('bookingresource') === null, 'bookingresource is declared without a view that uses booking');
	$dir = sys_get_temp_dir().'/raster-booking-loads-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(get_included_files())); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite"));
	shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /about 2>&1');
	$included = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	check(is_array($included), 'no report from the render');
	same(array(), array_values(array_filter($included, function ($f) { return strpos($f, '/system/models/booking/') !== false; })), 'booking files loaded:');
	booking_site(function () {
		same('booking', cms_records::info('bookinghours')['model']);
		same('time', cms_records::info('bookinghours')['types']['starts']);
		same(true, booking::ready());
	});
});

test('a site without booking binds no booking listener and loads no booking file when it saves a record', function () {
	$code = 'cms_store::save_item("zzthing", 0, array("title" => "x"), array("title"), "editor");'
		.' echo json_encode(array("bound" => array_values(preg_grep("/^booking\\./", call_user_func_array("array_merge", array_values(event::bindings())))),'
		.' "loaded" => array_values(preg_grep("~/models/booking/~", get_included_files()))));';
	$out = json_decode(race_php($code, 1)[0], true);
	check(is_array($out), 'no report from the save');
	same(array(), $out['bound'], 'booking listeners bound:');
	same(array(), $out['loaded'], 'booking files loaded:');
	R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzthing');
});

test('a site booking model or type keeps the name', function () {
	$app = APPBASE.config::get('models_path', 'models');
	booking_site(function () use ($app) {
		// the site's own booking model, in a process of its own: this one has
		// the bundled class already
		temp_model("$app/booking", array('booking.php' => "<?php\nclass booking\n{\n\tstatic function types() {\n\t\treturn array('booking' => array('fields' => array('name' => '')));\n\t}\n}\n"));
		try {
			$out = race_php('require_once BASE."tools/inspector.php"; echo json_encode(array(controller::model("booking")["bundled"], cms_records::info("bookingresource") === null, cms_records::info("booking")["model"], array_column((new raster_inspector())->lint_models(), "message")));', 1);
		} finally {
			remove_dir("$app/booking");
			controller::forget_models();
		}
		$out = json_decode($out[0], true);
		check(is_array($out), 'the process said: '.json_encode($out));
		same(false, $out[0], 'bundled:');
		same(true, $out[1], 'bookingresource is undeclared:');
		same('booking', $out[2]);
		check(has_problem(array_map(function ($m) { return array('message' => $m); }, $out[3]), 'models/booking shadows the bundled booking'), 'lint: '.json_encode($out[3]));
		// a type named booking in another model
		temp_model("$app/zzsalon", array('zzsalon.php' => "<?php\nclass zzsalon\n{\n\tstatic function types() {\n\t\treturn array('booking' => array('fields' => array('name' => '', 'stylist' => '')));\n\t}\n}\n"));
		try {
			controller::forget_models();
			cms_records::forget();
			same('zzsalon', cms_records::info('booking')['model']);
			check(cms_records::info('bookinghours') === null, 'the bundled types are declared beside the site\'s booking');
			same(false, booking::ready());
			check(has_problem((new raster_inspector())->lint_models(), "zzsalon declares a type 'booking'"), 'lint warns about the type');
			$page = booking_render('/zzbook?date='.date('Y-m-d', strtotime('next tuesday')));
			check(strpos($page, '[exit 0]') !== false, 'the page renders: '.$page);
			check(strpos($page, 'class="slot"') === false && strpos($page, 'class="resource"') === false, 'no rows: '.$page);
		} finally {
			remove_dir("$app/zzsalon");
			controller::forget_models();
			cms_records::forget();
		}
	});
});

test('booking: sittings: the starts of the hours rows', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		cms_records::create('bookingresource', array('name' => 'Dining room', 'people' => 20));
		cms_records::create('bookinghours', array('days' => 'tue,wed,thu,fri,sat,sun', 'starts' => '19:00', 'ends' => '21:00', 'every' => 120));
		$day = booking::day($tue, 'dining-room');
		same(array('19:00', '21:00'), array_column($day, 'time'));
		same(array('21:00', '23:00'), array_column($day, 'ends'));
		same(array($tue, $tue), array_column($day, 'date'));
		same('20', $day[0]['left_people']);
		same('', $day[0]['left_bookings']);
		same('0', $day[0]['full']);
		same('free', $day[0]['state']);
		same(array(), booking::day(date('Y-m-d', strtotime('next monday')), 'dining-room'));
		// 19 seated at 19:00 leave one place: a party of two doesn't fit
		$day = booking::day($tue, 'dining-room', '', 2, array(array('time' => '19:00', 'ends' => '21:00', 'people' => 19)));
		same(array('1', '1', 'full'), array($day[0]['left_people'], $day[0]['full'], $day[0]['state']));
		same(array('20', '0'), array($day[1]['left_people'], $day[1]['full']));
		same(array(), booking::day($tue, 'no-such-room'));
	});
});

test('booking: appointments: a grid, the union of rows, mixed service lengths', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		cms_records::create('bookingresource', array('name' => 'Ana', 'bookings' => 1));
		cms_records::create('bookingresource', array('name' => 'Mihai', 'bookings' => 1));
		cms_records::create('bookinghours', array('resource' => 'ana', 'days' => 'tue', 'starts' => '09:00', 'ends' => '11:00', 'every' => 30));
		cms_records::create('bookinghours', array('resource' => 'ana', 'days' => 'tue', 'starts' => '10:00', 'ends' => '12:00', 'every' => 15));
		cms_records::create('bookingservice', array('name' => 'Colour', 'minutes' => 90, 'resources' => 'ana'));
		$want = array('09:00', '09:30');
		for ($t = 600; $t <= 720; $t += 15) $want[] = booking::clock($t);
		$starts = booking::starts($tue, 'ana');
		same($want, array_column($starts, 'time'));
		// a start in both rows keeps the smaller every
		same(15, $starts[2]['every'], '10:00 every:');
		same(30, $starts[1]['every'], '09:30 every:');
		same(array(), booking::starts($tue, 'mihai'));
		$colour = array_column(booking::day($tue, 'ana', 'colour'), 'ends', 'time');
		same('13:00', $colour['11:30']);
		same('10:30', $colour['09:00']);
		same(false, booking::offers('colour', 'mihai'));
		same(true, booking::offers('colour', 'ana'));
		same(90, booking::length('ana', 'colour', 15));
		same(15, booking::length('ana', '', 15));
		// a colour 10:00-11:30 takes Ana's one chair until 11:30
		$state = array_column(booking::day($tue, 'ana', '', 1, array(array('time' => '10:00', 'ends' => '11:30', 'people' => 1))), 'state', 'time');
		same(array('free', 'full', 'full', 'free'), array($state['09:30'], $state['10:00'], $state['11:15'], $state['11:30']));
	});
});

test('booking: peak counts the busiest minute, not the sum', function () {
	$rows = array(
		array('time' => '10:00', 'ends' => '10:30', 'people' => 2),
		array('time' => '11:00', 'ends' => '11:30', 'people' => 2),
		array('time' => '09:00', 'ends' => '10:00', 'people' => 5),
	);
	same(array('people' => 2, 'bookings' => 1), booking::peak($rows, 600, 690));
	// 09:00-10:00 ends where 10:00-10:30 starts: never both at once
	same(array('people' => 5, 'bookings' => 1), booking::peak($rows, 570, 615));
	same(array('people' => 0, 'bookings' => 0), booking::peak($rows, 630, 660));
	$rows[] = array('time' => '09:50', 'ends' => '10:10', 'people' => 2);
	same(array('people' => 7, 'bookings' => 2), booking::peak($rows, 570, 615));
	// a span ending at midnight is read as 24:00
	same(array('people' => 3, 'bookings' => 1), booking::peak(array(array('time' => '23:30', 'ends' => '00:00', 'people' => 3)), 1410, 1440));
});

test('booking: closed days and seasons', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		$day = function ($n) use ($tue) { return date('Y-m-d', strtotime("$tue +$n days")); };
		foreach (array('Ana', 'Mihai', 'Terrace') as $name) cms_records::create('bookingresource', array('name' => $name, 'bookings' => 1));
		cms_records::create('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'every' => 30));
		cms_records::create('bookingclosed', array('date' => $tue, 'note' => 'Holiday'));
		cms_records::create('bookingclosed', array('date' => $day(1), 'resource' => 'ana'));
		cms_records::create('bookingclosed', array('date' => $day(2), 'until' => $day(4)));
		same(array(), booking::starts($tue, 'ana'));
		same(array(), booking::starts($tue, 'mihai'));
		same(true, booking::is_closed($tue, 'mihai'));
		same(array(), booking::starts($day(1), 'ana'));
		same(array('09:00', '09:30', '10:00'), array_column(booking::starts($day(1), 'mihai'), 'time'));
		foreach (array(2, 3, 4) as $n) same(true, booking::is_closed($day($n), 'mihai'), "day $n:");
		same(false, booking::is_closed($day(5), 'mihai'));
		same(3, count(booking::starts($day(5), 'ana')));
		$year = (int)date('Y') + 1;
		cms_records::create('bookinghours', array('resource' => 'terrace', 'starts' => '12:00', 'ends' => '12:00', 'every' => 60, 'from' => "$year-06-01", 'until' => "$year-08-31"));
		check(in_array('12:00', array_column(booking::starts("$year-07-07", 'terrace'), 'time'), true), 'the season applies in July');
		check(!in_array('12:00', array_column(booking::starts("$year-09-01", 'terrace'), 'time'), true), 'the season is over in September');
		check(!in_array('12:00', array_column(booking::starts("$year-07-07", 'ana'), 'time'), true), 'the terrace row is the terrace\'s');
	});
});

test('booking: slots skip what is too soon or too far', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		cms_records::create('bookingresource', array('name' => 'Dining room', 'people' => 20));
		cms_records::create('bookinghours', array('starts' => '18:00', 'ends' => '19:00', 'every' => 30));
		$now = booking::starts_at($tue, '18:00');
		same(array('19:00'), array_column(booking::day($tue, 'dining-room', '', 1, array(), $now), 'time'));
		config::set('booking_notice')->to(90);
		try {
			same(array(), booking::day($tue, 'dining-room', '', 1, array(), $now));
		} finally {
			config::set('booking_notice')->to(null);
		}
		check(count(booking::day(date('Y-m-d', strtotime('+60 days')), 'dining-room')) === 3, 'today + 60 days has slots');
		same(array(), booking::day(date('Y-m-d', strtotime('+61 days')), 'dining-room'));
		same(array(), booking::day(date('Y-m-d', strtotime('-1 day')), 'dining-room'));
		config::set('booking_horizon')->to(70);
		try {
			check(count(booking::day(date('Y-m-d', strtotime('+61 days')), 'dining-room')) === 3, 'booking_horizon 70 reaches day 61');
		} finally {
			config::set('booking_horizon')->to(null);
		}
		same(array(), booking::day('2026-02-30', 'dining-room'));
		same(array(), booking::day('<b>', 'dining-room'));
	});
});

test('booking: the time zone across DST', function () {
	booking_site(function () {
		with_timezone('Europe/Bucharest', function () {
			cms_records::create('bookingresource', array('name' => 'Night'));
			cms_records::create('bookinghours', array('starts' => '02:00', 'ends' => '04:30', 'every' => 30));
			$spring = array_column(booking::starts('2027-03-28', 'night'), 'time');
			same(array('02:00', '02:30', '04:00', '04:30'), $spring, 'the spring day:');
			$autumn = array_column(booking::starts('2026-10-25', 'night'), 'time');
			same(1, count(array_keys($autumn, '03:00')), 'the autumn day: '.json_encode($autumn));
			same(10800, booking::starts_at('2026-10-25', '04:00') - booking::starts_at('2026-10-25', '02:00'));
			$now = booking::starts_at('2027-03-28', '02:30');
			// wall 04:00 is 30 real minutes after 02:30 that day
			same(array('04:30'), array_column(booking::day('2027-03-28', 'night', '', 1, array(), $now), 'time'));
		});
	});
});

test('booking: a span ends by midnight', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		cms_records::create('bookingresource', array('name' => 'Late', 'bookings' => 1));
		cms_records::create('bookinghours', array('starts' => '22:00', 'ends' => '23:30', 'every' => 30));
		cms_records::create('bookingservice', array('name' => 'Colour', 'minutes' => 90));
		cms_records::create('bookingservice', array('name' => 'Cut', 'minutes' => 30));
		same(array('22:00' => '23:30', '22:30' => '00:00'), array_column(booking::day($tue, 'late', 'colour'), 'ends', 'time'));
		same('00:00', array_column(booking::day($tue, 'late', 'cut'), 'ends', 'time')['23:30']);
		same(1440, booking::minutes('00:00', true));
		same(0, booking::minutes('00:00'));
		same(1140, booking::minutes('19:00:00'));
		same('00:00', booking::clock(1440));
		same('09:05', booking::clock(545));
	});
});

test('booking: setup records are checked', function () {
	booking_site(function () {
		same(array('every_invalid'), booking_refused('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'every' => 0)));
		same(array('hours_invalid'), booking_refused('bookinghours', array('starts' => '11:00', 'ends' => '10:00')));
		same(array('hours_invalid'), booking_refused('bookinghours', array('starts' => '09:00')));
		same(array('days_invalid'), booking_refused('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'days' => 'mon,funday')));
		same(array('days_invalid'), booking_refused('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'days' => '')));
		same(array('season_invalid'), booking_refused('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'from' => '2027-08-31', 'until' => '2027-06-01')));
		same(array('limit_invalid'), booking_refused('bookingresource', array('name' => 'Ana', 'people' => -1)));
		same(array('limit_invalid'), booking_refused('bookingresource', array('name' => 'Ana', 'bookings' => -2)));
		same(array('minutes_invalid'), booking_refused('bookingservice', array('name' => 'Cut', 'minutes' => 0)));
		same(array('closed_invalid'), booking_refused('bookingclosed', array('note' => 'no date')));
		same(array('closed_invalid'), booking_refused('bookingclosed', array('date' => '2027-01-05', 'until' => '2027-01-01')));
		same(array(), booking_refused('bookinghours', array('starts' => '09:00', 'ends' => '10:00', 'days' => 'Mon, tue')));
		same(array(), booking_refused('bookingclosed', array('date' => '2027-01-05')));
		same(array(), booking::setup()['resources']);
		$ana = cms_records::create('bookingresource', array('name' => 'Ana'));
		// a write forgets the setup it read
		same(array('ana'), array_keys(booking::setup()['resources']));
		same(array('limit_invalid'), booking_refused('bookingresource', array('people' => -1), $ana['id']));
		$hours = booking::setup()['hours'];
		same(array('every_invalid'), booking_refused('bookinghours', array('every' => -5), $hours[0]['id']));
	});
});

test('render.booking.slots, days, resources and services read the URL', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		cms_records::create('bookingresource', array('name' => 'Ana', 'bookings' => 1));
		cms_records::create('bookingresource', array('name' => 'Mihai', 'bookings' => 1));
		cms_records::create('bookingresource', array('name' => 'Old', 'bookings' => 1, 'active' => false));
		cms_records::create('bookinghours', array('days' => 'tue,wed,thu,fri,sat,sun', 'starts' => '09:00', 'ends' => '11:00', 'every' => 30));
		cms_records::create('bookingservice', array('name' => 'Cut', 'minutes' => 30));
		cms_records::create('bookingservice', array('name' => 'Colour', 'minutes' => 90, 'resources' => 'ana'));
		$page = booking_render("/zzbook?date=$tue&resource=ana&service=cut");
		check(strpos($page, '[exit 0]') !== false, 'the page renders: '.$page);
		check(strpos($page, '<li class="slot">09:00-09:30</li>') !== false, 'the 09:00 slot: '.$page);
		same(5, substr_count($page, 'class="slot"'), 'slots:');
		preg_match_all('#<li class="day">(\d{4}-\d\d-\d\d)</li>#', $page, $m);
		same(14, count($m[1]), 'days:');
		foreach ($m[1] as $date) check(date('D', strtotime($date)) !== 'Mon', "$date is a Monday");
		preg_match_all('#<b class="resource">([^<]*)</b>#', $page, $m);
		same(array('Ana', 'Mihai'), $m[1]);
		preg_match_all('#<i class="service">([^<]*)</i>#', $page, $m);
		same(array('Cut', 'Colour'), $m[1]);
		preg_match_all('#<i class="service">([^<]*)</i>#', booking_render('/zzbook?resource=mihai'), $m);
		same(array('Cut'), $m[1]);
		// several stylists and none chosen, or a stylist with services and none
		// chosen: no times yet
		same(0, substr_count(booking_render("/zzbook?date=$tue"), 'class="slot"'));
		same(0, substr_count(booking_render("/zzbook?date=$tue&resource=ana"), 'class="slot"'));
		same(array('Cut'), array_column((new booking())->services('resource=mihai'), 'name'));
		same(array('Ana'), array_column((new booking())->resources('service=colour'), 'name'));
		same('bookingresource', (new booking())->resources()[0]['raster_record']);
		same(array(), (new booking())->slots("date=$tue&resource=ana&service=cut&people=0"));
		// a salon grid over the whole horizon stays quick
		cms_records::create('bookinghours', array('resource' => 'ana', 'starts' => '09:00', 'ends' => '18:00', 'every' => 15));
		cms_records::create('bookinghours', array('resource' => 'mihai', 'starts' => '09:00', 'ends' => '18:00', 'every' => 15));
		$start = microtime(true);
		$days = (new booking())->days('limit=60&resource=ana&service=colour');
		$took = microtime(true) - $start;
		check($took < 1, "days took $took s");
		same(60, count($days));
		check((int)$days[0]['slots_free'] > 0, 'slots_free: '.json_encode($days[0]));
	});
});

// ## Bundled booking: the booking type

// The café: a Dining room for 20, sittings at 19:00 and 21:00 Tuesday to
// Sunday. Gives the next Tuesday.
function booking_cafe() {
	cms_records::create('bookingresource', array('name' => 'Dining room', 'people' => 20));
	cms_records::create('bookinghours', array('resource' => 'dining-room', 'days' => 'tue,wed,thu,fri,sat,sun', 'starts' => '19:00', 'ends' => '21:00', 'every' => 120));
	return date('Y-m-d', strtotime('next tuesday'));
}
// The salon: Ana and Mihai take one booking at a time, starts every 15
// minutes from 09:00 to 17:30 Tuesday to Saturday; Cut (30) from both,
// Colour (90) from Ana. Gives the next Tuesday.
function booking_salon() {
	foreach (array('Ana', 'Mihai') as $name) {
		cms_records::create('bookingresource', array('name' => $name, 'bookings' => 1));
		cms_records::create('bookinghours', array('resource' => strtolower($name), 'days' => 'tue,wed,thu,fri,sat', 'starts' => '09:00', 'ends' => '17:30', 'every' => 15));
	}
	cms_records::create('bookingservice', array('name' => 'Cut', 'minutes' => 30, 'resources' => 'ana,mihai'));
	cms_records::create('bookingservice', array('name' => 'Colour', 'minutes' => 90, 'resources' => 'ana'));
	return date('Y-m-d', strtotime('next tuesday'));
}
// a booking as a guest's form stores it (each guest with an address of
// their own), and as staff store it in the page or over MCP
function booking_guest($values, $id = 0) {
	static $n = 0;
	$n++;
	if (!$id) $values = array_merge(array('name' => "Guest $n", 'email' => "guest$n@example.test"), $values);
	return cms_store::save_item('bookingdata', $id, $values, cms_records::writable(cms_records::info('booking')), 'visitor');
}
function booking_staff($values, $id = 0) {
	if (!$id) $values = array_merge(array('name' => 'Phone booking'), $values);
	return cms_store::save_item('bookingdata', $id, $values, cms_records::writable(cms_records::info('booking')), 'editor');
}
// the problems $fn was refused for, or array() when it went through
function booking_problems($fn) {
	try {
		$fn();
	} catch (cms_refused $e) {
		return $e->problems;
	}
	return array();
}
// whether a write would be refused, without storing it
function booking_would_refuse($fn) {
	try {
		cms_records::transaction(function () use ($fn) {
			$fn();
			throw new RuntimeException('roll back');
		});
	} catch (cms_refused $e) {
		return $e->problems;
	} catch (RuntimeException $e) {
		return array();
	}
}
// $fn as a logged in editor of this process
function booking_as_editor($fn) {
	$user = new ReflectionProperty('authentication', 'user');
	$user->setAccessible(true);
	$user->setValue(null, array('id' => 0, 'name' => 'Staff', 'email' => '', 'username' => '', 'role' => 'editor'));
	try {
		return $fn();
	} finally {
		$user->setValue(null, false);
	}
}
// one slot's places left, as the template gets them
function booking_left($date, $time, $resource = 'dining-room') {
	foreach ((new booking())->slots("date=$date&resource=$resource") as $slot) {
		if ($slot['time'] === $time) return $slot['left_people'];
	}
	return null;
}

test('booking: records fill their own fields, and check() knows who writes', function () {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/zzfill", array('zzfill.php' => "<?php\nclass zzfill\n{\n\tstatic \$seen = array();\n"
		."\tstatic function types() {\n\t\treturn array('zzfill' => array('fields' => array('title' => '', 'words' => 0, 'tags' => array()), 'readonly' => array('words')));\n\t}\n"
		."\tstatic function fill(\$type, \$after, \$before) {\n\t\treturn array('words' => str_word_count(\$after['title']), 'tags' => array(cms_records::writer()), 'nope' => 'x');\n\t}\n"
		."\tstatic function check(\$type, \$after, \$before) {\n\t\tself::\$seen[] = array(cms_records::writer(), \$after['words']);\n\t\treturn \$after && \$after['words'] > 5 ? array('too_long') : array();\n\t}\n}\n"));
	temp_model("$app/zzfillbad", array('zzfillbad.php' => "<?php\nclass zzfillbad\n{\n\tstatic function types() {\n\t\treturn array('zzfillbad' => array('fields' => array('title' => '')));\n\t}\n\tfunction fill(\$type, \$after, \$before) {\n\t\treturn array('title' => 'never');\n\t}\n}\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		cms_store::connect();
		$item = cms_records::create('zzfill', array('title' => 'two words'));
		same(array(2, array('model')), array($item['words'], $item['tags']));
		// the editor can't write words, but the model fills it on the editor's write
		$item = cms_store::save_item('zzfilldata', $item['id'], array('title' => 'now three words'), array('title'), 'editor');
		same(3, $item['words']);
		same(array('editor'), $item['tags']);
		same(array(array('model', 2), array('editor', 3)), zzfill::$seen);
		same(null, cms_records::writer());
		// a refusal puts the writer back too
		try {
			cms_store::save_item('zzfilldata', 0, array('title' => 'one two three four five six'), array('title'), 'visitor');
			check(false, 'six words were stored');
		} catch (cms_refused $e) {
			same(array('too_long'), $e->problems);
		}
		same(null, cms_records::writer());
		check(!cms_records::is_action_method('zzfill', 'fill'), 'fill is never an action');
		// an ordinary fill() is no hook: it fills nothing, and lint says so
		same('', cms_records::create('zzfillbad', array('title' => ''))['title']);
		check(has_problem((new raster_inspector())->lint_types(), 'zzfillbad::fill() must be static'), 'lint');
	} finally {
		foreach (array('zzfilldata', 'zzfillbaddata') as $table) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $table");
		cms_store::forget();
		remove_dir("$app/zzfill");
		remove_dir("$app/zzfillbad");
		controller::forget_models();
		cms_records::forget();
	}
});

test('booking: a booking is a request that holds its place', function () use ($base) {
	booking_site(function () use ($base) {
		$tue = booking_cafe();
		$b = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 2));
		same('requested', $b['status']);
		same('21:00', $b['ends']);
		same('dining-room', $b['resource']);
		same(2, $b['people']);
		check($b['changed_at'] !== '' && $b['changed_at'] !== null, 'changed_at is set');
		check(!array_key_exists('token_hashes', $b), 'a guest gets no hidden fields back');
		$info = cms_records::info('booking');
		same(array('ends', 'status', 'changed_at'), $info['readonly']);
		same(array('token_hashes', 'token_expires'), $info['hidden']);
		same(array('confirm' => 'editor', 'decline' => 'editor', 'cancel' => 'editor'), $info['actions']);
		same(array('visitor', true, true, false), array($info['create'], $info['staff_add'], $info['owner'], $info['public']));
		same('18', booking_left($tue, '19:00'));
		same('20', booking_left($tue, '21:00'));
		// a guest can't write the model's fields
		foreach (array('status' => 'confirmed', 'ends' => '23:59') as $field => $value) {
			try {
				booking_guest(array('date' => $tue, 'time' => '21:00', $field => $value));
				check(false, "a guest wrote $field");
			} catch (InvalidArgumentException $e) {
				check(!($e instanceof cms_refused), $e->getMessage());
			}
		}
		// nor through the form, which leaves them out
		list($status, , $headers) = form_post("$base/zzbook", array('raster_form' => 'booking.form', 'name' => 'Form Guest', 'email' => 'form@example.test',
			'date' => $tue, 'time' => '21:00', 'people' => '3', 'status' => 'confirmed', 'ends' => '23:59'));
		same(303, $status);
		check(strpos((string)location($headers), 'done=booked') !== false, 'redirect: '.location($headers));
		$stored = cms_records::find('booking', array('email' => 'form@example.test'));
		same(1, count($stored));
		same(array('requested', '23:00', 3), array($stored[0]['status'], $stored[0]['ends'], $stored[0]['people']));
		same($tue.' 21:00:00', $stored[0]['token_expires']);
	});
});

test('booking: capacity: people at the busiest minute', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$wed = date('Y-m-d', strtotime("$tue +1 day"));
		$first = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		$four = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 4));
		same('0', booking_left($tue, '19:00'));
		same(array('fully_booked'), booking_problems(function () use ($tue) { booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 1)); }));
		// the 19:00 sitting ends when the 21:00 one starts
		same(array(), booking_problems(function () use ($tue) { booking_guest(array('date' => $tue, 'time' => '21:00', 'people' => 12)); }));
		// cancelled and declined bookings free their places
		cms_records::act('booking', $first['id'], 'cancel', array(), true);
		cms_records::act('booking', $four['id'], 'decline', array(), true);
		same('12', booking_left($tue, '19:00'));
		same(array(), booking_problems(function () use ($tue) { booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 12)); }));
		same(array('fully_booked'), booking_problems(function () use ($wed) { booking_staff(array('date' => $wed, 'time' => '19:00', 'people' => 21)); }));
		same(array(), booking_problems(function () use ($wed) { booking_staff(array('date' => $wed, 'time' => '19:00', 'people' => 20)); }));
	});
});

// The MCP stdio server is one process across many calls, while the setup
// changes in the browser beside it: each booking reads the setup again.
test('booking: a long-lived process checks a booking against the setup as it is now', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$wed = date('Y-m-d', strtotime("$tue +1 day"));
		// the table is there for the other process to write
		cms_records::create('bookingclosed', array('date' => '2020-01-01', 'note' => 'Long ago'));
		$mcp = new mcp();
		$book = function ($date, $people) use ($mcp) {
			$answer = $mcp->call_tool('create_item', array('collection' => 'booking', 'fields' => array('name' => 'Agent', 'date' => $date, 'time' => '19:00', 'people' => $people)));
			return empty($answer['isError']) ? 'stored' : $answer['content'][0]['text'];
		};
		same('stored', $book($tue, 8));
		check(isset(booking::setup()['resources']['dining-room']), 'the setup is read');
		// another process writes the setup
		R::exec('UPDATE bookingresourcedata SET people = 10 WHERE slug = ?', array('dining-room'));
		check(strpos($book($tue, 8), 'fully_booked') !== false, 'fully_booked');
		R::exec('INSERT INTO bookingcloseddata (date, note) VALUES (?, ?)', array($wed, 'Holiday'));
		check(strpos($book($wed, 2), 'not_a_slot') !== false, 'not_a_slot');
		R::exec('UPDATE bookingresourcedata SET active = 0 WHERE slug = ?', array('dining-room'));
		check(strpos($book($tue, 1), 'resource_inactive') !== false, 'resource_inactive');
		same(1, count(cms_records::find('booking')));
	});
});

test('booking: capacity: a bookings limit across slot boundaries', function () {
	booking_site(function () {
		$tue = booking_salon();
		$cut = function ($time) use ($tue) {
			return function () use ($tue, $time) { booking_guest(array('date' => $tue, 'time' => $time, 'resource' => 'ana', 'service' => 'cut')); };
		};
		$colour = booking_guest(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana', 'service' => 'colour'));
		same('11:30', $colour['ends']);
		same(array('fully_booked'), booking_problems($cut('09:45')));
		same(array(), booking_problems($cut('09:30')));
		same(array('fully_booked'), booking_problems($cut('11:15')));
		same(array(), booking_problems($cut('11:30')));
		// Mihai's chair is his own
		same(array(), booking_problems(function () use ($tue) { booking_guest(array('date' => $tue, 'time' => '10:00', 'resource' => 'mihai', 'service' => 'cut')); }));
		// what the slots show as full is what check() refuses
		$slots = (new booking())->slots("date=$tue&resource=ana&service=cut");
		same(35, count($slots));
		foreach ($slots as $slot) {
			$refused = booking_would_refuse($cut($slot['time']));
			same($slot['full'] === '1', $refused === array('fully_booked'), "{$slot['time']} full {$slot['full']}, refused ".json_encode($refused).':');
		}
		// both limits: two bookings at once fill a studio for four
		cms_records::create('bookingresource', array('name' => 'Studio', 'people' => 4, 'bookings' => 2));
		cms_records::create('bookinghours', array('resource' => 'studio', 'starts' => '18:00', 'ends' => '18:00', 'every' => 60));
		$studio = function () use ($tue) { booking_guest(array('date' => $tue, 'time' => '18:00', 'resource' => 'studio')); };
		same(array(), booking_problems($studio));
		same(array(), booking_problems($studio));
		same(array('fully_booked'), booking_problems($studio));
	});
});

test('booking: start rules', function () {
	booking_site(function () {
		$tue = date('Y-m-d', strtotime('next tuesday'));
		$refused = function ($values) { return booking_problems(function () use ($values) { booking_guest($values); }); };
		same(array('not_open'), $refused(array('date' => $tue, 'time' => '19:00')));
		booking_cafe();
		same(array('not_a_slot'), $refused(array('date' => $tue, 'time' => '19:30')));
		same(array('not_a_slot'), $refused(array('date' => date('Y-m-d', strtotime('next monday')), 'time' => '19:00')));
		same(array('too_many'), $refused(array('date' => $tue, 'time' => '19:00', 'people' => 13)));
		same(array('people_invalid'), $refused(array('date' => $tue, 'time' => '19:00', 'people' => 0)));
		same(array('email_invalid'), $refused(array('date' => $tue, 'time' => '19:00', 'email' => '')));
		same(array('email_invalid'), $refused(array('date' => $tue, 'time' => '19:00', 'email' => 'not an address')));
		same(array('name_invalid'), $refused(array('date' => $tue, 'time' => '19:00', 'name' => '  ')));
		same(array(), $refused(array('date' => $tue, 'time' => '19:00', 'email' => 'twice@example.test')));
		same(array('already_booked'), $refused(array('date' => $tue, 'time' => '19:00', 'email' => 'twice@example.test')));
		$closed = cms_records::create('bookingclosed', array('date' => $tue));
		same(array('not_a_slot'), $refused(array('date' => $tue, 'time' => '21:00')));
		cms_records::delete('bookingclosed', $closed['id']);
		// with several resources, the guest names one
		booking_salon();
		same(array('resource_invalid'), $refused(array('date' => $tue, 'time' => '19:00')));
		same(array('resource_invalid'), $refused(array('date' => $tue, 'time' => '19:00', 'resource' => 'nobody')));
		same(array('service_unavailable'), $refused(array('date' => $tue, 'time' => '10:00', 'resource' => 'mihai', 'service' => 'colour')));
		same(array('service_unavailable'), $refused(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana')));
		same(array('service_unavailable'), $refused(array('date' => $tue, 'time' => '19:00', 'resource' => 'dining-room', 'service' => 'cut')));
		same(array(), $refused(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana', 'service' => 'colour')));
		// a span that would end after midnight is not a slot
		cms_records::create('bookingresource', array('name' => 'Late', 'bookings' => 1));
		cms_records::create('bookinghours', array('resource' => 'late', 'starts' => '23:00', 'ends' => '23:00', 'every' => 90));
		same(array('not_a_slot'), $refused(array('date' => $tue, 'time' => '23:00', 'resource' => 'late')));
		// notice and horizon
		$soon = time() + 1800;
		cms_records::create('bookingresource', array('name' => 'Counter', 'people' => 4));
		cms_records::create('bookinghours', array('resource' => 'counter', 'starts' => date('H:i', $soon), 'ends' => date('H:i', $soon), 'every' => 1));
		same(array('too_soon'), $refused(array('date' => date('Y-m-d', $soon), 'time' => date('H:i', $soon), 'resource' => 'counter')));
		same(array('too_far'), $refused(array('date' => date('Y-m-d', strtotime('+61 days', $soon)), 'time' => date('H:i', $soon), 'resource' => 'counter')));
		same(array(), $refused(array('date' => date('Y-m-d', strtotime('+3 days', $soon)), 'time' => date('H:i', $soon), 'resource' => 'counter')));
		$mihai = booking::setup()['resources']['mihai'];
		cms_records::update('bookingresource', $mihai['id'], array('active' => false));
		same(array('resource_inactive'), $refused(array('date' => $tue, 'time' => '10:00', 'resource' => 'mihai', 'service' => 'cut')));
	});
});

test('booking: an inactive resource is not counted when a booking fills its resource and people', function () {
	booking_site(function () {
		// the café switched its terrace off for winter: the dining room is
		// still the one resource a guest books
		$tue = booking_cafe();
		cms_records::create('bookingresource', array('name' => 'Terrace', 'people' => 10, 'active' => false));
		same(2, count((new booking())->slots("date=$tue")));
		$made = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 2));
		same(array('dining-room', 2), array($made['resource'], $made['people']));
	});
	booking_site(function () {
		// a salon counts no people, even with a café's room switched off
		$tue = booking_salon();
		cms_records::create('bookingresource', array('name' => 'Terrace', 'people' => 10));
		cms_records::create('bookinghours', array('resource' => 'terrace', 'starts' => '19:00', 'ends' => '19:00', 'every' => 60));
		$kept = booking_staff(array('date' => $tue, 'time' => '19:00', 'resource' => 'terrace', 'people' => 6));
		cms_records::update('bookingresource', booking::setup()['resources']['terrace']['id'], array('active' => false));
		$made = booking_guest(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana', 'service' => 'cut', 'people' => 3));
		same(1, $made['people']);
		// a booking on the switched-off room keeps its people
		same(6, booking::cancel($kept, array())['people']);
	});
});

test('booking: staff skip notice and horizon, never capacity', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$soon = time() + 600;
		cms_records::create('bookinghours', array('resource' => 'dining-room', 'starts' => date('H:i', $soon), 'ends' => date('H:i', $soon), 'every' => 1));
		$at = array('date' => date('Y-m-d', $soon), 'time' => date('H:i', $soon));
		$staff = function ($values) { return booking_problems(function () use ($values) { booking_staff($values); }); };
		same(array('too_soon'), booking_problems(function () use ($at) { booking_guest($at); }));
		same(array(), $staff($at));
		same(array(), $staff(array('date' => date('Y-m-d', strtotime('+90 days', $soon))) + $at));
		same(array(), $staff(array('date' => $tue, 'time' => '19:00', 'people' => 13)));
		// a walk-in, written down after
		same(array(), $staff(array('date' => date('Y-m-d', strtotime('-1 day', $soon))) + $at));
		// no email for a booking taken over the phone, but a real one if any
		same(array('email_invalid'), $staff(array('date' => $tue, 'time' => '21:00', 'email' => 'nope')));
		same(array('fully_booked'), $staff(array('date' => $tue, 'time' => '19:00', 'people' => 8)));
		same(array('people_invalid'), $staff(array('date' => $tue, 'time' => '21:00', 'people' => 0)));
		$made = mcp_call('create_item', array('collection' => 'booking', 'fields' => array('name' => 'Agent booking', 'date' => $tue, 'time' => '21:00', 'people' => 13)));
		check(empty($made['isError']), json_encode($made));
		same('requested', $made['structuredContent']['status']);
		$full = mcp_call('create_item', array('collection' => 'booking', 'fields' => array('name' => 'Agent booking', 'date' => $tue, 'time' => '21:00', 'people' => 8)));
		check(!empty($full['isError']) && strpos(json_encode($full), 'fully_booked') !== false, json_encode($full));
		$confirmed = cms_records::act('booking', $made['structuredContent']['id'], 'confirm', array(), true);
		same('confirmed', $confirmed['status']);
	});
});

test('booking: confirm, decline and cancel', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$b = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 6));
		$act = function ($id, $action) { return booking_problems(function () use ($id, $action) { cms_records::act('booking', $id, $action, array(), true); }); };
		same('confirmed', cms_records::act('booking', $b['id'], 'confirm', array(), true)['status']);
		same(array('already_confirmed'), $act($b['id'], 'confirm'));
		same(array('already_confirmed'), $act($b['id'], 'decline'));
		same('14', booking_left($tue, '19:00'));
		same('cancelled', cms_records::act('booking', $b['id'], 'cancel', array(), true)['status']);
		same('20', booking_left($tue, '19:00'));
		same(array('already_cancelled'), $act($b['id'], 'confirm'));
		same(array('already_cancelled'), $act($b['id'], 'cancel'));
		same(array('already_cancelled'), $act($b['id'], 'decline'));
		// staff can't write a status back by hand either
		same(array('already_cancelled'), booking_problems(function () use ($b) { cms_records::update('booking', $b['id'], array('status' => 'requested')); }));
		$d = booking_guest(array('date' => $tue, 'time' => '21:00'));
		same('declined', cms_records::act('booking', $d['id'], 'decline', array(), true)['status']);
		same(array('already_declined'), $act($d['id'], 'confirm'));
		same(array('already_declined'), $act($d['id'], 'cancel'));
		// an action reads the booking again: the page that showed it may be old
		$stale = booking_guest(array('date' => $tue, 'time' => '21:00'));
		cms_records::act('booking', $stale['id'], 'cancel', array(), true);
		same(array('already_cancelled'), booking_problems(function () use ($stale) { booking::confirm($stale, array()); }));
		check(cms_records::get('booking', $d['id'])['changed_at'] !== '', 'changed_at follows the status');
		// only editors run them
		same(array('not_allowed'), booking_problems(function () use ($stale) { cms_records::act('booking', $stale['id'], 'confirm'); }));
	});
});

test('booking: rules run only when the slot changes, and on confirm', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$b = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 2));
		$other = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 2));
		cms_records::create('bookingclosed', array('date' => $tue, 'note' => 'Private party'));
		$saved = booking_staff(array('phone' => '0721 000 000', 'notes' => 'window seat'), $b['id']);
		same('window seat', $saved['notes']);
		same(array('not_a_slot'), booking_problems(function () use ($b) { booking_staff(array('people' => 3), $b['id']); }));
		same(array('closed'), booking_problems(function () use ($b) { cms_records::act('booking', $b['id'], 'confirm', array(), true); }));
		same('cancelled', cms_records::act('booking', $b['id'], 'cancel', array(), true)['status']);
		same('declined', cms_records::act('booking', $other['id'], 'decline', array(), true)['status']);
	});
});

test('booking: moving a booking frees its old place in the same write', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$wed = date('Y-m-d', strtotime("$tue +1 day"));
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		$four = booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 4));
		cms_records::act('booking', $four['id'], 'confirm', array(), true);
		same('0', booking_left($tue, '19:00'));
		// it doesn't count against itself
		same(3, booking_staff(array('people' => 3), $four['id'])['people']);
		booking_staff(array('people' => 4), $four['id']);
		$moved = booking::move($four['id'], array('time' => '21:00', 'name' => 'ignored'), 'staff');
		same(array('confirmed', '21:00', '23:00'), array($moved['status'], $moved['time'], $moved['ends']));
		same('4', booking_left($tue, '19:00'));
		same('16', booking_left($tue, '21:00'));
		check(!isset(booking::$moved[$four['id']]), "the model's own move is not left for saved()");
		// the in-page editor's change of time works out the end again
		$back = booking_staff(array('time' => '19:00'), $four['id']);
		same(array('19:00', '21:00', 'confirmed'), array($back['time'], $back['ends'], $back['status']));
		same('0', booking_left($tue, '19:00'));
		same(array('fully_booked'), booking_problems(function () use ($four) { booking_staff(array('people' => 5), $four['id']); }));
		// a guest's move asks staff again
		cms_records::update('booking', $four['id'], array('changed_at' => '2020-01-01 10:00:00'));
		$guest = booking::move($four['id'], array('date' => $wed, 'time' => '21:00'), 'guest');
		same(array('requested', $wed, '21:00'), array($guest['status'], $guest['date'], $guest['time']));
		check($guest['changed_at'] > '2020-01-01 10:00:00', 'changed_at: '.$guest['changed_at']);
		same($wed.' 21:00:00', $guest['token_expires']);
		same('staff', booking::$acting);
		// a guest move is checked as a guest's
		same(array('too_many'), booking_problems(function () use ($four) { booking::move($four['id'], array('people' => 13), 'guest'); }));
		same('4', booking_left($tue, '19:00'));
	});
});

test('booking: needs attention', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$wed = date('Y-m-d', strtotime("$tue +1 day"));
		$thu = date('Y-m-d', strtotime("$tue +2 days"));
		$fri = date('Y-m-d', strtotime("$tue +3 days"));
		cms_records::create('bookingresource', array('name' => 'Terrace', 'people' => 10));
		cms_records::create('bookinghours', array('resource' => 'terrace', 'starts' => '12:00', 'ends' => '12:00', 'every' => 120));
		cms_records::create('bookingresource', array('name' => 'Ana', 'bookings' => 1));
		cms_records::create('bookinghours', array('resource' => 'ana', 'starts' => '10:00', 'ends' => '12:00', 'every' => 30));
		cms_records::create('bookingservice', array('name' => 'Colour', 'minutes' => 90, 'resources' => 'ana'));
		$closed = booking_staff(array('date' => $tue, 'time' => '19:00', 'resource' => 'dining-room', 'people' => 2));
		$moved = booking_staff(array('date' => $wed, 'time' => '21:00', 'resource' => 'dining-room', 'people' => 2));
		$over = booking_staff(array('date' => $thu, 'time' => '19:00', 'resource' => 'dining-room', 'people' => 16));
		$fine = booking_staff(array('date' => $fri, 'time' => '19:00', 'resource' => 'dining-room', 'people' => 2));
		$terrace = booking_staff(array('date' => $tue, 'time' => '12:00', 'resource' => 'terrace', 'people' => 2));
		$colour = booking_staff(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana', 'service' => 'colour'));
		$setup = booking::setup();
		cms_records::create('bookingclosed', array('date' => $tue, 'resource' => 'dining-room'));
		cms_records::update('bookinghours', $setup['hours'][0]['id'], array('ends' => '20:00'));
		cms_records::update('bookingresource', $setup['resources']['dining-room']['id'], array('people' => 10));
		cms_records::update('bookingresource', $setup['resources']['terrace']['id'], array('active' => false));
		cms_records::update('bookingservice', $setup['services']['colour']['id'], array('active' => false));
		$why = array();
		foreach (array($closed, $moved, $over, $fine, $terrace, $colour) as $b) $why[] = booking::reason(cms_records::get('booking', $b['id']));
		same(array('closed', 'not_a_slot', 'over_capacity', '', 'resource_inactive', 'service_unavailable'), $why);
		$b = new booking();
		same('0', $b->count('attention'), 'logged out:');
		same('0', $b->count());
		same(array(), $b->agenda());
		same(array(), $b->attention());
		booking_as_editor(function () use ($b, $tue, $thu, $fri) {
			same('5', $b->count('attention'));
			same('6', $b->count());
			same('0', $b->count('status=confirmed'));
			$listed = $b->attention();
			same(array('service_unavailable', 'resource_inactive', 'closed', 'not_a_slot', 'over_capacity'), array_column($listed, 'attention'));
			$days = $b->agenda('from=today&days=14');
			$reasons = array();
			foreach ($days as $day) foreach ($day['bookings'] as $row) $reasons[$row['name'].' '.$row['date'].' '.$row['time']] = $row['attention'];
			same(6, count($reasons));
			same('over_capacity', $reasons["Phone booking $thu 19:00"]);
			same('', $reasons["Phone booking $fri 19:00"]);
			$tuesday = array_values(array_filter($days, function ($day) use ($tue) { return $day['date'] === $tue; }))[0];
			same(array('Ana', 'Terrace', 'Dining room'), array_column($tuesday['bookings'], 'resource_name'));
			same('Colour', $tuesday['bookings'][0]['service_name']);
			// the Dining room's agenda: its slots and whether it is closed
			$room = $b->agenda('from=today&days=14&resource=dining-room');
			$closed = array_column($room, 'closed', 'date');
			same('1', $closed[$tue]);
			same('0', $closed[$thu]);
			$thursday = array_values(array_filter($room, function ($day) use ($thu) { return $day['date'] === $thu; }))[0];
			same(array('19:00'), array_column($thursday['slots'], 'time'));
			same(array('0', '1', 'full'), array($thursday['slots'][0]['left_people'], $thursday['slots'][0]['full'], $thursday['slots'][0]['state']));
		});
	});
});

test('booking: concurrent last place: one gets it, the rest fully_booked', function () {
	booking_site(function () {
		$tue = booking_cafe();
		// the table is made before the race: on MySQL a new table commits
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 8));
		$out = race_php('try { $b = cms_store::save_item("bookingdata", 0, array("name" => "Racer $n", "email" => "racer$n@example.test", "date" => '.var_export($tue, true).', "time" => "19:00", "people" => 4), cms_records::writable(cms_records::info("booking")), "visitor"); echo "id ", $b["id"]; } catch (cms_refused $e) { echo implode(",", $e->problems); }', 6);
		same(1, count(preg_grep('/^id \d+$/', $out)), 'one booking: '.json_encode($out));
		same(5, count(array_keys($out, 'fully_booked')), 'refused: '.json_encode($out));
		reconnect();
		$total = 0;
		foreach (cms_records::find('booking', array('date' => $tue, 'time' => '19:00')) as $b) $total += $b['people'];
		same(20, $total);
	});
});

test('booking: agenda and attention rows are records', function () {
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzstaff.html';
	booking_site(function () use ($view) {
		$tue = booking_cafe();
		booking_guest(array('date' => $tue, 'time' => '19:00', 'people' => 2, 'name' => 'Ana Popescu', 'phone' => '0721 123 456'));
		$agenda = "render.booking.agenda('from=today&days=10')";
		test_fixture($view);
		file_put_contents($view, "<html><head><title>Staff</title></head><body><h1>Requests: <!-- print.booking.count('status=requested') -->3<!-- /print.booking.count('status=requested') --></h1>"
			."<!-- $agenda --><h2><!-- print.date -->2026-10-20<!-- /print.date --></h2><ul><!-- print.bookings --><li class=\"booking\"><!-- print.name -->Ana<!-- /print.name --> <!-- print.time -->19:00<!-- /print.time --></li><!-- /print.bookings --></ul><!-- /$agenda -->"
			."</body></html>");
		try {
			booking_as_editor(function () {
				foreach ((new booking())->agenda('from=today&days=10') as $day) {
					foreach ($day['bookings'] as $row) {
						same('booking', $row['raster_record']);
						check(strpos($row['raster_detail_link'], '/booking/booking_item/ana-popescu') !== false, $row['raster_detail_link']);
						check(!array_key_exists('token_hashes', $row) && !array_key_exists('token_expires', $row), 'hidden fields reach the agenda');
					}
				}
			});
			global $root, $db;
			$out = (string)shell_exec('RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development '.php_cmd().' '.escapeshellarg("$root/bin/raster").' render /zzstaff --as=editor 2>&1');
			check(strpos($out, 'Requests: 1</h1>') !== false, 'the count: '.$out);
			check(strpos($out, '<li class="booking"') !== false && strpos($out, 'Ana Popescu') !== false, 'the agenda: '.$out);
			check(preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $out, $m) === 1, 'no editor: '.$out);
			$items = array_filter(json_decode($m[1], true)['marks'], function ($mark) { return isset($mark['kind']) && $mark['kind'] === 'item' && $mark['collection'] === 'booking'; });
			same(1, count($items), 'booking items the editor can edit:');
			$item = array_values($items)[0];
			same(array('confirm', 'decline', 'cancel'), $item['actions']);
			check(in_array('status', $item['readonly'], true) && !array_key_exists('token_hashes', $item['values']), json_encode($item));
			// visitors get no rows
			$out = booking_render('/zzstaff');
			check(strpos($out, 'Ana Popescu') === false && strpos($out, 'Requests: 0</h1>') !== false, 'a visitor: '.$out);
		} finally {
			@unlink($view);
		}
	});
});

test('booking: the_booking adds a field', function () use ($base) {
	$app = APPBASE.config::get('models_path', 'models');
	booking_site(function () use ($app, $base) {
		temp_model("$app/the_booking", array('the_booking.php' => "<?php\nclass the_booking extends booking\n{\n\tstatic function types() {\n\t\t\$types = parent::types();\n\t\t\$types['booking']['fields']['dietary'] = '';\n\t\treturn \$types;\n\t}\n}\n"));
		$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzbook.html';
		$html = file_get_contents($view);
		test_fixture($view);
		file_put_contents($view, str_replace('<textarea name="notes">', '<input name="dietary"><textarea name="notes">', $html));
		try {
			controller::forget_models();
			cms_records::forget();
			same('the_booking', cms_records::info('booking')['class']);
			check(array_key_exists('dietary', cms_records::info('booking')['fields']), 'dietary is declared');
			$tue = booking_cafe();
			list($status, $body) = form_post("$base/zzbook", array('raster_form' => 'booking.form', 'name' => 'Vegan Guest', 'email' => 'vegan@example.test', 'date' => $tue, 'time' => '19:00', 'people' => '2', 'dietary' => 'vegan'));
			same(303, $status, $body);
			$stored = cms_records::find('booking', array('email' => 'vegan@example.test'));
			same('vegan', $stored[0]['dietary']);
			same('21:00', $stored[0]['ends']);
		} finally {
			test_fixture($view);
			file_put_contents($view, $html);
			remove_dir("$app/the_booking");
			controller::forget_models();
			cms_records::forget();
		}
	});
});

test('booking: resources and services in use keep their slugs', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$room = booking::setup()['resources']['dining-room'];
		$b = booking_guest(array('date' => $tue, 'time' => '19:00'));
		same(array('resource_in_use'), booking_refused('bookingresource', array('slug' => 'main-room'), $room['id']));
		same(array('resource_in_use'), booking_problems(function () use ($room) { cms_records::delete('bookingresource', $room['id']); }));
		same(array(), booking_refused('bookingresource', array('name' => 'Main room'), $room['id']));
		same('dining-room', cms_records::get('bookingresource', $room['id'])['slug']);
		// a booking in the past doesn't hold it
		cms_records::act('booking', $b['id'], 'cancel', array(), true);
		same(true, cms_records::delete('bookingresource', $room['id']));
		$tue = booking_salon();
		$cut = booking::setup()['services']['cut'];
		$b = booking_guest(array('date' => $tue, 'time' => '10:00', 'resource' => 'ana', 'service' => 'cut'));
		same(array('service_in_use'), booking_refused('bookingservice', array('slug' => 'trim'), $cut['id']));
		same(array('service_in_use'), booking_problems(function () use ($cut) { cms_records::delete('bookingservice', $cut['id']); }));
		same(array(), booking_refused('bookingservice', array('name' => 'Trim'), $cut['id']));
		cms_records::act('booking', $b['id'], 'cancel', array(), true);
		same(array(), booking_refused('bookingservice', array('slug' => 'trim'), $cut['id']));
		same(true, cms_records::delete('bookingservice', $cut['id']));
	});
});

test('a GET form: the URL chooses the options a model lists, and keeps the values it prints', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	temp_model("$app/zzpick", array('zzpick.php' => "<?php\nclass zzpick\n{\n\tfunction rows() {\n\t\treturn array(array('id' => '3', 'name' => 'Ana'), array('id' => '5', 'name' => 'Mihai'));\n\t}\n}\n"));
	test_fixture("$theme/zzpick.html");
	file_put_contents("$theme/zzpick.html", '<html><head><title>Pick</title></head><body>'
		// a form per row, its hidden input printed by the model
		.'<!-- render.zzpick.rows --><form method="get" class="row"><!-- print.@value.id --><input type="hidden" name="event" value="1"><!-- /print.@value.id --><button>Open</button></form><!-- /render.zzpick.rows -->'
		// a filter whose options the model lists, the mock-up's value one the URL may ask for
		.'<form method="get" class="by-id"><select name="event"><option value="">All</option><!-- render.zzpick.rows --><!-- print.@value.id --><option value="3"><!-- print.name -->Ana<!-- /print.name --></option><!-- /print.@value.id --><!-- /render.zzpick.rows --></select></form>'
		// options without a value, chosen by their text; a page field's carries the editor's marks
		// radios the model lists
		.'<form method="get" class="by-radio"><!-- render.zzpick.rows --><label><!-- print.@value.id --><input type="radio" name="pick" value="3"><!-- /print.@value.id --> <!-- print.name -->Ana<!-- /print.name --></label><!-- /render.zzpick.rows --></form>'
		.'<form method="get" class="by-text"><select name="stylist"><option value="">Anyone</option><!-- render.zzpick.rows --><option><!-- print.name -->Ana<!-- /print.name --></option><!-- /render.zzpick.rows --><option><!-- print.cms.zz_house -->The house<!-- /print.cms.zz_house --></option></select></form>'
		.'</body></html>');
	controller::forget_models();
	$select = function ($page, $class) {
		$form = substr($page, strpos($page, 'class="'.$class.'"'));
		return substr($form, 0, strpos($form, '</form>'));
	};
	try {
		$page = contact_render('/zzpick?event=3');
		check(strpos($page, '[exit 0]') !== false, $page);
		check(strpos($page, 'name="event" value="3">') !== false && strpos($page, 'name="event" value="5">') !== false, 'each row keeps its own value: '.$page);
		$page = contact_render('/zzpick?event=5');
		check(strpos($page, 'name="event" value="3">') !== false && strpos($page, 'name="event" value="5">') !== false, 'each row keeps its own value: '.$page);
		$by_id = $select($page, 'by-id');
		check(strpos($by_id, '<option value="5" selected>Mihai') !== false && substr_count($by_id, 'selected') === 1, 'the model\'s option is chosen, once: '.$by_id);
		$by_id = $select(contact_render('/zzpick?event=3'), 'by-id');
		check(strpos($by_id, '<option value="3" selected>Ana') !== false && substr_count($by_id, 'selected') === 1, 'a mock-up that matches marks one row: '.$by_id);
		$by_id = $select(contact_render('/zzpick?event='), 'by-id');
		check(strpos($by_id, '<option value="" selected>All') !== false && substr_count($by_id, 'selected') === 1, '?event= chooses only the empty one: '.$by_id);
		$by_radio = $select(contact_render('/zzpick?pick=5'), 'by-radio');
		check(strpos($by_radio, 'value="5" checked>') !== false && substr_count($by_radio, 'checked') === 1, 'the model\'s radio is chosen, once: '.$by_radio);
		$by_text = $select(contact_render('/zzpick?stylist=Mihai'), 'by-text');
		check(strpos($by_text, '<option selected>Mihai</option>') !== false && substr_count($by_text, 'selected') === 1, 'by its text: '.$by_text);
		foreach (array(null, 'editor') as $as) {
			$page = contact_render('/zzpick?stylist=The%20house', $as);
			$by_text = $select($page, 'by-text');
			check(preg_match('#<option selected>The house#', $by_text) === 1 && substr_count($by_text, 'selected') === 1, "by its text, as $as: $by_text");
			// for an editor the field was marked while the form was filled: the marks
			// come out of an <option> only after (cms_editor::settle), and the field
			// is listed as hidden
			if ($as) check(preg_match('#"field":"zz_house"[^}]*"hidden":true#', $page) === 1, 'the field was marked: '.$page);
		}
		// the editor's mock-up of a new item is no option to choose; a single select chooses one
		$t = template::instance();
		$mockup = $t->fill_form('<form method="get"><select name="event"><option value="3">Ana</option><template data-raster-mockup="1"><option value="3">Mock</option></template></select></form>', array('event' => '3'), false, false, 'choices');
		check(strpos($mockup, '<template data-raster-mockup="1"><option value="3">Mock</option></template>') !== false && substr_count($mockup, 'selected') === 1, $mockup);
		$twice = $t->fill_form('<form method="get"><select name="event"><option value="3">Ana</option><option value="3">Ana again</option></select></form>', array('event' => '3'), false, false, 'choices');
		check(substr_count($twice, 'selected') === 1 && strpos($twice, '<option value="3" selected>Ana</option>') !== false, $twice);
		$many = $t->fill_form('<form method="get"><select name="event[]" multiple><option value="3">Ana</option><option value="5">Mihai</option></select></form>', array('event' => array('3', '5')), false, false, 'choices');
		same(2, substr_count($many, 'selected'), $many);
		// an option's text the editor marks (<!--raster:s 3-->) is compared without the marks
		$marked = template::instance()->fill_form('<form method="get"><select name="stylist"><option>Anyone</option><option><!--raster:s 3-->Ana<!--raster:e 3--></option></select></form>', array('stylist' => 'Ana'), false, false, 'choices');
		check(strpos($marked, '<option selected><!--raster:s 3-->Ana') !== false && substr_count($marked, 'selected') === 1, $marked);
	} finally {
		@unlink("$theme/zzpick.html");
		remove_dir("$app/zzpick");
		controller::forget_models();
	}
});
test('a nested render block runs before its parent, even when its tag appears earlier in the page (#97)', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	temp_model("$app/zznest", array('zznest.php' => "<?php\nclass zznest\n{\n\tfunction rows() {\n\t\treturn array(array('name' => 'Ada'), array('name' => 'Bob'));\n\t}\n\tfunction outer() {\n\t\treturn array(array('title' => 'Outer'));\n\t}\n\tfunction side() {\n\t\treturn array(array('title' => 'Side'));\n\t}\n}\n"));
	test_fixture("$theme/zznest.html");
	test_fixture("$theme/zznestside.html");
	$rows = '<!-- render.zznest.rows --><i><!-- print.name -->n<!-- /print.name --></i><!-- /render.zznest.rows -->';
	// the list at the top, and again inside another block
	file_put_contents("$theme/zznest.html", '<html><head><title>Nest</title></head><body>'
		.'<ul><!-- render.zznest.rows --><li><!-- print.name -->n<!-- /print.name --></li><!-- /render.zznest.rows --></ul>'
		.'<div class="outer"><!-- render.zznest.outer --><b><!-- print.title -->t<!-- /print.title --></b>'.$rows.'<!-- /render.zznest.outer --></div>'
		.'</body></html>');
	// two sibling blocks, each with the list inside
	file_put_contents("$theme/zznestside.html", '<html><head><title>Nest</title></head><body>'
		.'<div class="outer"><!-- render.zznest.outer --><b><!-- print.title -->t<!-- /print.title --></b>'.$rows.'<!-- /render.zznest.outer --></div>'
		.'<div class="side"><!-- render.zznest.side --><b><!-- print.title -->t<!-- /print.title --></b>'.$rows.'<!-- /render.zznest.side --></div>'
		.'</body></html>');
	controller::forget_models();
	try {
		// rows are joined with a line break
		$page = str_replace("\n", '', contact_render('/zznest'));
		check(strpos($page, '[exit 0]') !== false, $page);
		check(strpos($page, '<ul><li>Ada</li><li>Bob</li></ul>') !== false, 'the list at the top: '.$page);
		check(strpos($page, '<div class="outer"><b>Outer</b><i>Ada</i><i>Bob</i></div>') !== false, 'the list inside the other block: '.$page);
		$page = str_replace("\n", '', contact_render('/zznestside'));
		check(strpos($page, '[exit 0]') !== false, $page);
		check(strpos($page, '<div class="outer"><b>Outer</b><i>Ada</i><i>Bob</i></div>') !== false, 'the first sibling: '.$page);
		check(strpos($page, '<div class="side"><b>Side</b><i>Ada</i><i>Bob</i></div>') !== false, 'the second sibling: '.$page);
	} finally {
		@unlink("$theme/zznest.html");
		@unlink("$theme/zznestside.html");
		remove_dir("$app/zznest");
		controller::forget_models();
	}
});
test('booking: forms shown again keep choices (#80)', function () {
	$template = template::instance();
	$select = '<form method="get"><select name="stylist"><option value="">Anyone</option><option>Ana</option><option> Mihai  Pop </option></select></form>';
	$filled = $template->fill_form($select, array('stylist' => 'Ana'));
	check(strpos($filled, '<option selected>Ana</option>') !== false && substr_count($filled, 'selected') === 1, $filled);
	$filled = $template->fill_form($select, array('stylist' => 'Mihai Pop'));
	check(strpos($filled, '<option selected> Mihai  Pop </option>') !== false && substr_count($filled, 'selected') === 1, $filled);
	$filled = $template->fill_form($select, array('stylist' => ''));
	check(strpos($filled, '<option value="" selected>Anyone</option>') !== false && substr_count($filled, 'selected') === 1, $filled);
	$box = '<form method="post"><input name="email"><input type="checkbox" name="newsletter" value="yes" checked><input type="radio" name="seating" value="window" checked></form>';
	// a post without the box: the visitor unticked it
	$filled = $template->fill_form($box, array('email' => 'a@b.test'), false, true);
	check(strpos($filled, 'checked') === false, $filled);
	// the same values given by a model, or from the URL, leave it as designed
	same(2, substr_count($template->fill_form($box, array('email' => 'a@b.test')), 'checked'));
	// form_state() fills from the post
	$_POST = array('email' => 'a@b.test', 'seating' => 'window');
	$template->current_block = $box;
	$filled = $template->form_state();
	$_POST = array();
	check(strpos($filled, 'name="newsletter" value="yes">') !== false && strpos($filled, 'value="window" checked') !== false, $filled);
	// the booking form shown again after fully_booked keeps the chosen time
	$times = '<form method="post"><input type="radio" name="time" value="19:00"><input type="radio" name="time" value="21:00" checked><input name="people"></form>';
	$filled = $template->fill_form($times, array('time' => '19:00', 'people' => '4'), false, true);
	check(strpos($filled, 'value="19:00" checked') !== false && substr_count($filled, 'checked') === 1, $filled);
});

test('booking: the booking form takes the day from the URL', function () {
	booking_site(function () {
		$tue = booking_salon();
		$page = booking_render("/zzbook?date=$tue&resource=ana&service=cut&time=");
		check(strpos($page, '[exit 0]') !== false, $page);
		check(preg_match('#<input type="date" name="date" required value="'.$tue.'">#', $page) === 1, 'the date: '.$page);
		check(strpos($page, '<input name="resource" value="ana">') !== false, 'the resource: '.$page);
		check(strpos($page, '<input type="hidden" name="service" value="cut">') !== false, 'the service: '.$page);
		check(strpos($page, '<input name="time" required>') !== false, 'an empty time stays empty: '.$page);
	});
	booking_site(function () {
		// nothing to book yet
		$page = booking_render('/zzbook');
		check(strpos($page, '[exit 0]') !== false && strpos($page, '<form method="post"') !== false, $page);
		same(array(), (new booking())->slots('date='.date('Y-m-d', strtotime('next tuesday'))));
	});
});

// ## Bundled booking: the guest

// A server of its own for the guest's pages: its mail goes where
// booking_mails() reads it, from staff@zz.test, and it links to itself.
function booking_server($env = array()) {
	global $root, $db, $maildir;
	$port = free_port();
	$log = sys_get_temp_dir().'/raster-booking-'.getmypid().'-'.$port.'.log';
	$env = array_merge(array('PATH' => getenv('PATH'), 'RASTER_SECRET' => 'test-secret', 'RASTER_DB' => $db, 'RASTER_ENV' => 'development', 'RASTER_MAIL' => "log://$maildir-booking",
		'RASTER_MAIL_FROM' => 'staff@zz.test', 'RASTER_URL' => "http://127.0.0.1:$port/"), $env);
	$process = proc_open(php_argv('-d', 'log_errors=1', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$root/index.php"),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	register_shutdown_function(function () use ($process, $log) { proc_terminate($process); proc_close($process); @unlink($log); });
	return array("http://127.0.0.1:$port", $log);
}
// The mails $fn sent, from this process and from booking_server()'s, in
// order: array('to', 'view' (the subject), 'text', 'raw')
function booking_mails($fn) {
	global $maildir;
	$dir = "$maildir-booking";
	@mkdir($dir);
	array_map('unlink', glob("$dir/*.eml") ?: array());
	$was = getenv('RASTER_MAIL');
	putenv("RASTER_MAIL=log://$dir");
	try {
		$fn();
	} finally {
		putenv("RASTER_MAIL=$was");
	}
	// a server sends after its answer
	for ($i = 0, $seen = -1; $i < 20; $i++) {
		$count = count(glob("$dir/*.eml") ?: array());
		if ($count === $seen) break;
		$seen = $count;
		usleep(150000);
	}
	$files = glob("$dir/*.eml") ?: array();
	sort($files);
	$mails = array();
	foreach ($files as $file) {
		$raw = file_get_contents($file);
		preg_match('/^To: (.*)$/m', $raw, $to);
		preg_match('/^Subject: (.*)$/m', $raw, $subject);
		$text = preg_match('/Content-Type: text\/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $raw, $m) ? base64_decode($m[1]) : '';
		$mails[] = array('to' => trim($to[1]), 'view' => mb_decode_mimeheader(trim($subject[1])), 'text' => $text, 'raw' => $raw);
	}
	return $mails;
}
// "view to" of each mail, for same()
function booking_sent($mails) {
	return array_map(function ($m) { return $m['view'].' '.$m['to']; }, $mails);
}
// the raw token of a mail's manage link
function booking_token($mail) {
	return preg_match('/token=([a-f0-9]{64})/', $mail['text'], $m) ? $m[1] : null;
}
// the manage page as a request in this process: $get is the URL's query,
// $post (when given) what its form sent. Gives the rows, the alerts raised
// and where it redirected to.
function booking_manage($get, $post = null) {
	$saved = array($_GET, $_POST, isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
	$_GET = $get;
	$_POST = $post === null ? array() : $post + array('raster_form' => 'booking.manage');
	$_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
	$template = template::instance();
	$call = $template->current_call;
	$template->current_call = 'booking.manage';
	$raised = new ReflectionProperty('validation', 'raised');
	$raised->setAccessible(true);
	$raised->setValue(validation::get(), array());
	booking::forget();
	try {
		$rows = (new booking())->manage();
		return array($rows, array_keys($raised->getValue(validation::get())));
	} finally {
		list($_GET, $_POST, $_SERVER['REQUEST_METHOD']) = $saved;
		$template->current_call = $call;
		$raised->setValue(validation::get(), array());
	}
}
// what booking events were sent while $fn ran, as array(event, payload)
$booking_seen = array();
function test_booking_seen($payload) {
	global $booking_seen;
	$event = new ReflectionProperty('event', 'current_event');
	$event->setAccessible(true);
	$booking_seen[] = array($event->getValue(event::instance()), $payload);
}
function booking_events($fn) {
	global $booking_seen;
	$booking_seen = array();
	$names = array('booking.requested', 'booking.confirmed', 'booking.declined', 'booking.cancelled', 'booking.rescheduled');
	foreach ($names as $name) event::bind($name)->to(null, 'test_booking_seen');
	try {
		$fn();
	} finally {
		foreach ($names as $name) event::unbind($name)->from(null, 'test_booking_seen');
	}
	return $booking_seen;
}
list($booking_base, $booking_log) = booking_server();

test('booking: every guest email carries a fresh link; only its hash is kept', function () use ($booking_base) {
	booking_site(function () use ($booking_base) {
		$tue = booking_cafe();
		cms_records::ensure(cms_records::info('booking'), true);
		$mails = booking_mails(function () use ($booking_base, $tue) {
			list($status, , $headers) = form_post("$booking_base/zzbook", array('raster_form' => 'booking.form', 'name' => 'Link Guest', 'email' => 'link@example.test', 'date' => $tue, 'time' => '19:00', 'people' => '2'));
			same(303, $status);
			check(strpos((string)location($headers), 'done=booked') !== false, location($headers));
		});
		same(array('booking_requested link@example.test', 'booking_new staff@zz.test'), booking_sent($mails));
		$raw = booking_token($mails[0]);
		check($raw !== null, 'no link: '.$mails[0]['text']);
		check(strpos($mails[0]['text'], "$booking_base/booking?token=$raw") !== false, $mails[0]['text']);
		check(strpos($mails[1]['text'], 'token=') === false, 'staff got a link: '.$mails[1]['text']);
		$b = cms_records::find('booking', array('email' => 'link@example.test'))[0];
		same(array(hash('sha256', $raw)), $b['token_hashes']);
		check(strpos(json_encode($b), $raw) === false, 'the raw token is stored');
		$mails = booking_mails(function () use ($b) { cms_records::act('booking', $b['id'], 'confirm', array(), true); });
		same(array('booking_confirmed link@example.test'), booking_sent($mails));
		$second = booking_token($mails[0]);
		check($second !== null && $second !== $raw, 'a fresh link');
		same(array(hash('sha256', $raw), hash('sha256', $second)), cms_records::get('booking', $b['id'])['token_hashes']);
		foreach (array($raw, $second) as $token) {
			list($rows, $alerts) = booking_manage(array('token' => $token));
			same(array(), $alerts);
			same(array('Link Guest', 'confirmed', 'Dining room'), array($rows[0]['name'], $rows[0]['status'], $rows[0]['resource_name']));
			check(!array_key_exists('token_hashes', $rows[0]) && !array_key_exists('token_expires', $rows[0]), json_encode($rows[0]));
		}
	});
});

test('booking: the raw token is in no event, log or cache', function () use ($booking_log, $db) {
	booking_site(function () use ($booking_log, $db) {
		$tue = booking_cafe();
		cms_records::ensure(cms_records::info('booking'), true);
		$raw = null;
		$events = booking_events(function () use ($tue, &$raw) {
			$mails = booking_mails(function () use ($tue) { booking_staff(array('email' => 'quiet@example.test', 'date' => $tue, 'time' => '19:00')); });
			$raw = booking_token($mails[0]);
		});
		check($raw !== null, 'no link');
		same('booking.requested', $events[0][0]);
		$payload = $events[0][1];
		same('staff', $payload['who']);
		check(!array_key_exists('token_hashes', $payload) && !array_key_exists('token_expires', $payload), json_encode($payload));
		check(!preg_match('/[a-f0-9]{64}/', json_encode($payload)), 'a token in the payload: '.json_encode($payload));
		cache_empty();
		list($prod, $prod_log) = booking_server(array('RASTER_ENV' => 'production'));
		foreach (array(1, 2) as $n) {
			list($status, $body, $headers) = http('GET', "$prod/booking?token=$raw");
			same(200, $status, $body);
			check(strpos($body, 'quiet@example.test') !== false, 'the booking: '.$body);
			check(b_header($headers, 'X-Raster-Cache') !== 'hit', "cached the $n. time");
			same('same-origin', b_header($headers, 'Referrer-Policy'));
		}
		foreach (array_merge(cached_pages(), array($booking_log, $prod_log)) as $file) {
			check(strpos((string)@file_get_contents($file), $raw) === false, "the token is in $file");
		}
		same(array(), array_values(array_filter(cached_pages(), function ($f) { return strpos(basename($f), 'version') === false; })), 'a page was cached');
		cache_empty();
	});
});

test('booking: a GET to the manage link changes nothing', function () use ($booking_base) {
	booking_site(function () use ($booking_base) {
		$tue = booking_cafe();
		$mails = booking_mails(function () use ($tue) { booking_guest(array('email' => 'scan@example.test', 'date' => $tue, 'time' => '19:00')); });
		$raw = booking_token($mails[0]);
		$b = cms_records::find('booking', array('email' => 'scan@example.test'))[0];
		$mails = booking_mails(function () use ($booking_base, $raw) {
			same(200, http('GET', "$booking_base/booking?token=$raw")[0]);
			http('HEAD', "$booking_base/booking?token=$raw");
			same(200, http('GET', "$booking_base/booking?token=$raw&do=cancel")[0]);
		});
		same(array(), booking_sent($mails));
		same('requested', cms_records::get('booking', $b['id'])['status']);
		// another site's form
		$mails = booking_mails(function () use ($booking_base, $raw) {
			same(403, form_post("$booking_base/booking?token=$raw", array('raster_form' => 'booking.manage', 'do' => 'cancel'), array('Origin: https://evil.test'))[0]);
		});
		same(array(), booking_sent($mails));
		same('requested', cms_records::get('booking', $b['id'])['status']);
		$mails = booking_mails(function () use ($booking_base, $raw) {
			list($status, , $headers) = form_post("$booking_base/booking?token=$raw", array('raster_form' => 'booking.manage', 'do' => 'cancel'));
			same(303, $status);
			same("$booking_base/booking?done=cancelled", location($headers));
		});
		same('cancelled', cms_records::get('booking', $b['id'])['status']);
		same(array('booking_cancelled scan@example.test', 'booking_cancelled staff@zz.test'), booking_sent($mails));
		// after the cancel, the page that says so
		list($status, $body) = http('GET', "$booking_base/booking?done=cancelled");
		check(strpos($body, 'ALERT cancelled') !== false && strpos($body, 'ALERT link_invalid') === false, $body);
	});
});

test('booking: links sent to an address stop working when staff change it', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$mails = booking_mails(function () use ($tue) { booking_guest(array('email' => 'typo@example.test', 'date' => $tue, 'time' => '19:00')); });
		$raw = booking_token($mails[0]);
		$b = cms_records::find('booking', array('email' => 'typo@example.test'))[0];
		same(array(), booking_manage(array('token' => $raw))[1]);
		// a note keeps the link; a new address ends it
		cms_records::update('booking', $b['id'], array('notes' => 'Window seat', 'email' => 'TYPO@example.test'));
		same(array(), booking_manage(array('token' => $raw))[1], 'the same address in other letters');
		cms_records::update('booking', $b['id'], array('email' => 'right@example.test'));
		same(array(array(), array('link_invalid')), booking_manage(array('token' => $raw)));
		same(null, booking::by_token($raw));
	});
});
test('booking: expired, used and foreign links are invalid', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$link = function ($email) use ($tue) {
			$mails = booking_mails(function () use ($tue, $email) { booking_guest(array('email' => $email, 'date' => $tue, 'time' => '19:00')); });
			return array(booking_token($mails[0]), cms_records::find('booking', array('email' => $email))[0]);
		};
		list($raw, $b) = $link('old@example.test');
		same(array(), booking_manage(array('token' => $raw))[1]);
		cms_records::update('booking', $b['id'], array('token_expires' => date('Y-m-d H:i:s', time() - 60)));
		same(array(array(), array('link_invalid')), booking_manage(array('token' => $raw)));
		same(null, booking::by_token($raw));
		list($raw, $b) = $link('cancel@example.test');
		cms_records::act('booking', $b['id'], 'cancel', array(), true);
		same(array(array(), array('link_invalid')), booking_manage(array('token' => $raw)));
		list($raw, $b) = $link('decline@example.test');
		cms_records::act('booking', $b['id'], 'decline', array(), true);
		same(array('link_invalid'), booking_manage(array('token' => $raw))[1]);
		same(array('link_invalid'), booking_manage(array('token' => 'xyz'))[1]);
		same(array('link_invalid'), booking_manage(array('token' => str_repeat('a', 64)))[1]);
		same(array('link_invalid'), booking_manage(array())[1]);
		same(array(array(), array()), booking_manage(array('done' => 'cancelled')));
	});
});

test('booking: cutoff: too_late within booking_cutoff', function () use ($booking_base) {
	booking_site(function () use ($booking_base) {
		$start = time() + 100 * 60;
		$date = date('Y-m-d', $start);
		$time = date('H:i', $start);
		cms_records::create('bookingresource', array('name' => 'Dining room', 'people' => 20));
		cms_records::create('bookinghours', array('resource' => 'dining-room', 'starts' => $time, 'ends' => $time, 'every' => 1));
		$mails = booking_mails(function () use ($date, $time) { booking_staff(array('email' => 'late@example.test', 'date' => $date, 'time' => $time)); });
		$raw = booking_token($mails[0]);
		$b = cms_records::find('booking', array('email' => 'late@example.test'))[0];
		list($status, $body) = http('GET', "$booking_base/booking?token=$raw");
		check(strpos($body, 'late@example.test') !== false && strpos($body, 'ALERT too_late') !== false, $body);
		list($status, $body) = form_post("$booking_base/booking?token=$raw", array('raster_form' => 'booking.manage', 'do' => 'cancel'));
		same(200, $status);
		check(strpos($body, 'ALERT too_late') !== false, $body);
		same('requested', cms_records::get('booking', $b['id'])['status']);
		config::set('booking_cutoff')->to(60);
		try {
			list(, $alerts) = booking_manage(array('token' => $raw), array('do' => 'cancel'));
			same(array(), $alerts);
		} finally {
			config::set('booking_cutoff')->to(null);
		}
		same('cancelled', cms_records::get('booking', $b['id'])['status']);
	});
});

test('booking: guest reschedule goes back to requested; old links follow the booking', function () use ($booking_base) {
	booking_site(function () use ($booking_base) {
		$tue = booking_cafe();
		$wed = date('Y-m-d', strtotime("$tue +1 day"));
		$mails = booking_mails(function () use ($tue) { booking_guest(array('email' => 'move@example.test', 'date' => $tue, 'time' => '19:00', 'people' => 4)); });
		$first = booking_token($mails[0]);
		$b = cms_records::find('booking', array('email' => 'move@example.test'))[0];
		cms_records::act('booking', $b['id'], 'confirm', array(), true);
		same('16', booking_left($tue, '19:00'));
		$events = array();
		$mails = booking_mails(function () use ($booking_base, $first, $wed, &$events) {
			// the page lists the new day's times for this booking
			$page = http('GET', "$booking_base/booking?token=$first&date=$wed")[1];
			check(strpos($page, '<li class="slot">21:00</li>') !== false, 'the new day: '.$page);
			list($status, , $headers) = form_post("$booking_base/booking?token=$first&date=$wed", array('raster_form' => 'booking.manage', 'do' => 'change', 'time' => '21:00'));
			same(303, $status);
			same("$booking_base/booking?done=rescheduled", location($headers));
		});
		$moved = cms_records::get('booking', $b['id']);
		same(array('requested', $wed, '21:00', "$wed 21:00:00"), array($moved['status'], $moved['date'], $moved['time'], $moved['token_expires']));
		same(array('booking_changed move@example.test', 'booking_new staff@zz.test'), booking_sent($mails));
		same('20', booking_left($tue, '19:00'));
		list($rows) = booking_manage(array('token' => $first));
		same(array($wed, '21:00'), array($rows[0]['date'], $rows[0]['time']));
		// the event, in this process
		$events = booking_events(function () use ($first, $tue) {
			booking_mails(function () use ($first, $tue) { booking_manage(array('token' => $first, 'date' => $tue), array('do' => 'change', 'time' => '19:00')); });
		});
		same(array('booking.rescheduled', 'guest'), array($events[0][0], $events[0][1]['who']));
		// into a full sitting: nothing written, nothing sent
		booking_staff(array('date' => $wed, 'time' => '19:00', 'people' => 20));
		$before = cms_records::get('booking', $b['id']);
		$mails = booking_mails(function () use ($first, $wed) {
			list($rows, $alerts) = booking_manage(array('token' => $first, 'date' => $wed), array('do' => 'change', 'time' => '19:00'));
			same(array('fully_booked'), $alerts);
			same('19:00', $rows[0]['time']);
		});
		same(array(), booking_sent($mails));
		same($before, cms_records::get('booking', $b['id']));
		// the same slot again
		$mails = booking_mails(function () use ($first, $tue) {
			same(array(), booking_manage(array('token' => $first, 'date' => $tue), array('do' => 'change', 'time' => '19:00'))[1]);
		});
		same(array(), booking_sent($mails));
		same($before, cms_records::get('booking', $b['id']));
	});
});

test('booking: a logged in guest manages their own booking', function () use ($booking_base) {
	booking_site(function () use ($booking_base) {
		$tue = booking_cafe();
		cms_records::ensure(cms_records::info('booking'), true);
		$member = function ($email) use ($booking_base) {
			list(, , $headers) = form_post("$booking_base/register", array('raster_form' => 'authentication.register', 'name' => 'Member', 'email' => $email, 'password' => 'correct horse 1', 'password_again' => 'correct horse 1'));
			return preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
		};
		$mine = $member('zzbook-owner-'.getmypid().'@example.test');
		$other = $member('zzbook-other-'.getmypid().'@example.test');
		booking_mails(function () use ($booking_base, $mine, $tue) {
			$page = http('GET', "$booking_base/zzbook", null, array("Cookie: $mine"))[1];
			check(preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m) === 1, 'no csrf: '.$page);
			same(303, form_post("$booking_base/zzbook", array('raster_form' => 'booking.form', 'csrf' => $m[1], 'name' => 'Owner', 'email' => 'owner@example.test', 'date' => $tue, 'time' => '19:00', 'people' => '2'), array("Cookie: $mine"))[0]);
		});
		$b = cms_records::find('booking', array('email' => 'owner@example.test'))[0];
		check((int)$b['owner'] > 0, 'no owner');
		$page = http('GET', "$booking_base/booking?booking={$b['id']}", null, array("Cookie: $mine"))[1];
		check(strpos($page, 'owner@example.test') !== false && strpos($page, 'ALERT link_invalid') === false, $page);
		$page = http('GET', "$booking_base/booking?booking={$b['id']}", null, array("Cookie: $other"))[1];
		check(strpos($page, 'owner@example.test') === false && strpos($page, 'ALERT link_invalid') !== false, $page);
		check(strpos(http('GET', "$booking_base/booking?booking={$b['id']}")[1], 'ALERT link_invalid') !== false, 'logged out');
		$page = http('GET', "$booking_base/booking?booking={$b['id']}", null, array("Cookie: $mine"))[1];
		check(preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m) === 1, 'no csrf: '.$page);
		list($status, , $headers) = form_post("$booking_base/booking?booking={$b['id']}", array('raster_form' => 'booking.manage', 'csrf' => $m[1], 'do' => 'cancel'), array("Cookie: $mine"));
		same(303, $status);
		same("$booking_base/booking?booking={$b['id']}&done=cancelled", location($headers));
		same('cancelled', cms_records::get('booking', $b['id'])['status']);
	});
});

test('booking: every email is sent once per change', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$b = booking_guest(array('email' => 'once@example.test', 'date' => $tue, 'time' => '19:00'));
		$mails = booking_mails(function () use ($b) {
			cms_records::act('booking', $b['id'], 'confirm', array(), true);
			same(array('already_confirmed'), booking_problems(function () use ($b) { cms_records::act('booking', $b['id'], 'confirm', array(), true); }));
		});
		same(array('booking_confirmed once@example.test'), booking_sent($mails));
		$events = booking_events(function () use ($b, &$mails) {
			$mails = booking_mails(function () use ($b) { booking_staff(array('time' => '21:00'), $b['id']); });
		});
		same(array('booking_changed once@example.test'), booking_sent($mails));
		check(booking_token($mails[0]) !== null, 'the changed email has a link');
		same(array(array('booking.rescheduled', 'staff', 'confirmed')), array_map(function ($e) { return array($e[0], $e[1]['who'], $e[1]['status']); }, $events));
		// the model's own move inside a transaction of the site's code: its
		// events come at that commit, and still once
		$hashes = count(cms_records::get('booking', $b['id'])['token_hashes']);
		$events = booking_events(function () use ($b, &$mails) {
			$mails = booking_mails(function () use ($b) {
				cms_records::transaction(function () use ($b) { booking::move($b['id'], array('time' => '19:00'), 'staff'); });
			});
		});
		same(array('booking_changed once@example.test'), booking_sent($mails));
		same(array('booking.rescheduled'), array_column($events, 0));
		same($hashes + 1, count(cms_records::get('booking', $b['id'])['token_hashes']), 'one new link');
		$events = booking_events(function () use ($b, &$mails) {
			$mails = booking_mails(function () use ($b) { booking_staff(array('time' => '21:00'), $b['id']); });
		});
		same(array('booking_changed once@example.test'), booking_sent($mails), 'an editor move after it:');
		same(array('booking.rescheduled'), array_column($events, 0));
		$mails = booking_mails(function () use ($b) { booking_staff(array('notes' => 'by the window', 'phone' => '0721'), $b['id']); });
		same(array(), booking_sent($mails));
		$mails = booking_mails(function () use ($tue) { booking_staff(array('date' => $tue, 'time' => '21:00')); });
		same(array(), booking_sent($mails));
		$quiet = cms_records::find('booking', array('name' => 'Phone booking'))[0];
		same(array(), $quiet['token_hashes']);
		$mails = booking_mails(function () use ($tue) { booking_staff(array('name' => 'Phoned', 'email' => 'phoned@example.test', 'date' => $tue, 'time' => '21:00')); });
		same(array('booking_requested phoned@example.test'), booking_sent($mails));
		check(booking_token($mails[0]) !== null, 'no link');
		// a staff move of a cancelled booking tells nobody
		cms_records::act('booking', $b['id'], 'cancel', array(), true);
		$mails = booking_mails(function () use ($b) { booking_staff(array('time' => '19:00'), $b['id']); });
		same(array(), booking_sent($mails));
	});
});

test('booking: booking events wait for the commit', function () {
	booking_site(function () {
		$tue = booking_cafe();
		cms_records::ensure(cms_records::info('booking'), true);
		$mails = array();
		$events = booking_events(function () use ($tue, &$mails) {
			$mails = booking_mails(function () use ($tue) {
				booking_problems(function () use ($tue) {
					cms_records::transaction(function () use ($tue) {
						cms_records::create('booking', array('name' => 'Rolled back', 'email' => 'rolled@example.test', 'date' => $tue, 'time' => '19:00'));
						cms_records::refuse('changed_my_mind');
					});
				});
			});
		});
		same(array(), $events);
		same(array(), booking_sent($mails));
		same(array(), cms_records::find('booking', array('email' => 'rolled@example.test')));
		// committed, they come
		$events = booking_events(function () use ($tue, &$mails) {
			$mails = booking_mails(function () use ($tue) {
				cms_records::transaction(function () use ($tue) {
					cms_records::create('booking', array('name' => 'Kept', 'email' => 'kept@example.test', 'date' => $tue, 'time' => '19:00'));
				});
			});
		});
		same(array('booking.requested'), array_column($events, 0));
		same(array('booking_requested kept@example.test'), booking_sent($mails));
	});
});

test('booking: a site can unbind a booking email', function () {
	booking_site(function () {
		$tue = booking_cafe();
		$b = booking_guest(array('email' => 'unbound@example.test', 'date' => $tue, 'time' => '19:00'));
		event::unbind('booking.confirmed')->from('booking', 'confirmed_mail');
		try {
			$mails = booking_mails(function () use ($b) { cms_records::act('booking', $b['id'], 'confirm', array(), true); });
		} finally {
			event::bind('booking.confirmed')->to('booking', 'confirmed_mail');
		}
		same(array(), booking_sent($mails));
		same('confirmed', cms_records::get('booking', $b['id'])['status']);
		$other = booking_guest(array('email' => 'bound@example.test', 'date' => $tue, 'time' => '19:00'));
		$mails = booking_mails(function () use ($other) { cms_records::act('booking', $other['id'], 'confirm', array(), true); });
		same(array('booking_confirmed bound@example.test'), booking_sent($mails));
	});
});

test("booking: bundled listeners are bound where views use booking, not to a site's own booking model", function () {
	booking_site(function () {
		$app = APPBASE.config::get('models_path', 'models');
		$bound = 'echo json_encode(call_user_func_array("array_merge", array_values(event::bindings())));';
		$out = json_decode(race_php($bound, 1)[0], true);
		check(in_array('booking.confirmed_mail', $out, true) && in_array('booking.saved', $out, true), 'the bundled model: '.json_encode($out));
		temp_model("$app/booking", array('booking.php' => "<?php\nclass booking\n{\n\tstatic function types() {\n\t\treturn array('booking' => array('fields' => array('name' => '')));\n\t}\n}\n"));
		try {
			$out = json_decode(race_php($bound, 1)[0], true);
		} finally {
			remove_dir("$app/booking");
			controller::forget_models();
		}
		check(is_array($out), 'no bindings');
		same(array(), array_values(preg_grep('/^booking\./', $out)), 'bound to the site model:');
	});
});

test('booking: requires(): emails, pages, settings and records', function () {
	booking_site(function () {
		$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
		$messages = function ($view) {
			return array_map(function ($p) { return $p['severity'].': '.$p['message']; }, (new raster_inspector())->lint_file($view));
		};
		// no resource yet
		check(preg_grep('/^warning: .*bookingresource/', $messages('zzbook.html')) !== array(), 'no records: '.json_encode($messages('zzbook.html')));
		booking_cafe();
		same(array(), array_values(preg_grep('/bookingresource/', $messages('zzbook.html'))));
		$new = "$theme/_email/booking_new.html";
		$html = file_get_contents($new);
		unlink($new);
		try {
			check(preg_grep('/^error: render\.booking\.form sends an email written in .*booking_new\.html/', $messages('zzbook.html')) !== array(), json_encode($messages('zzbook.html')));
			check(preg_grep('/^error: render\.booking\.manage sends an email written in .*booking_new\.html/', $messages('booking.html')) !== array(), json_encode($messages('booking.html')));
		} finally {
			test_fixture($new);
			file_put_contents($new, $html);
		}
		same(array(), array_values(preg_grep('/^error/', $messages('zzbook.html'))));
		check(in_array('booking', controller::required('pages'), true), 'pages: '.json_encode(controller::required('pages')));
		$urls = array_column((new feed())->pages(), 'url');
		check(!preg_grep('#/booking$#', $urls) && preg_grep('#/zzbook$#', $urls), 'sitemap: '.json_encode($urls));
		config::set('booking_manage_page')->to('my-booking');
		try {
			same(array('my-booking'), array_values(array_intersect(array('booking', 'my-booking'), controller::required('pages'))));
		} finally {
			config::set('booking_manage_page')->to(null);
		}
		require_once BASE.'tools/describe.php';
		config::set('booking_cutoff')->to(90);
		try {
			$settings = raster_describe::site(array('settings'))['settings'];
		} finally {
			config::set('booking_cutoff')->to(null);
		}
		same(90, $settings['booking_cutoff']);
		check(!preg_grep('/protected/', $messages('booking.html')), json_encode($messages('booking.html')));
		$protected = config::get('protected');
		config::set('protected')->to(array('account' => 'member', 'booking' => 'editor'));
		try {
			check(preg_grep('/^warning: .*render\.booking\.manage.*protected/', $messages('booking.html')) !== array(), json_encode($messages('booking.html')));
		} finally {
			config::set('protected')->to($protected);
		}
	});
});

test('lint warns when a checked form has no try_again alert', function () {
	$form = "<!-- render.newsletter.signup -->\n<form method=\"post\"><input type=\"email\" name=\"email\" required></form>\n<!-- /render.newsletter.signup -->\n";
	$try = "<!-- print.validation.alert('try_again') --><p>Again.</p><!-- /print.validation.alert('try_again') -->\n";
	$slow = "<!-- print.validation.alert('slow_down') --><p>Slow.</p><!-- /print.validation.alert('slow_down') -->\n";
	$problems = lint_html($form);
	check(has_problem($problems, "render.newsletter.signup can turn a post away as spam; add print.validation.alert('try_again')"), 'warns with neither alert');
	check(has_problem(lint_html($try.$form), 'can turn a post away as spam'), 'warns with only try_again');
	check(has_problem(lint_html($slow.$form), 'can turn a post away as spam'), 'warns with only slow_down');
	check(!has_problem(lint_html($try.$slow.$form), 'can turn a post away as spam'), 'quiet with both');
	check(!has_problem(lint_html("<p>No form here.</p>"), 'can turn a post away as spam'), 'quiet without the region');
});

test('lint counts try_again and slow_down alerts in the fragments a view includes', function () {
	$views = sys_get_temp_dir().'/raster-lint-theme-'.getmypid();
	mkdir($views.'/zz', 0777, true);
	$alerts = "<!-- print.validation.alert('try_again') --><p>Again.</p><!-- /print.validation.alert('try_again') -->\n<!-- print.validation.alert('slow_down') --><p>Slow.</p><!-- /print.validation.alert('slow_down') -->\n";
	test_fixture("$views/zz/_layout.html");
	file_put_contents("$views/zz/_layout.html", "<!-- res.header -->\n$alerts<!-- /res.header -->\n<!-- res.bare -->\n<p>Nothing.</p>\n<!-- /res.bare -->\n");
	try {
		$form = "<!-- render.newsletter.signup -->\n<form method=\"post\"><input type=\"email\" name=\"email\" required></form>\n<!-- /render.newsletter.signup -->\n";
		$inspector = new raster_inspector($views, 'zz');
		check(!has_problem($inspector->lint_source("<!-- dry._layout.header /-->\n$form"), 'can turn a post away as spam'), 'quiet when the header fragment has both alerts');
		check(has_problem($inspector->lint_source("<!-- dry._layout.bare /-->\n$form"), 'can turn a post away as spam'), 'still warns when the fragment has neither');
	} finally {
		unlink("$views/zz/_layout.html");
		rmdir("$views/zz");
		rmdir($views);
	}
});

test('lint warns about raw find() rows on any page (#125)', function () {
	$app = APPBASE.config::get('models_path', 'models');
	temp_model("$app/zzraw", array('zzraw.php' => "<?php\nclass zzraw\n{\n\tstatic function types() {\n\t\treturn array('zzraw' => array('fields' => array('name' => '', 'newsletter' => false)));\n\t}\n\tfunction rows() {\n\t\treturn cms_records::find('zzraw');\n\t}\n\tfunction text() {\n\t\treturn array_map(function (\$r) { return cms_records::for_template(cms_records::info('zzraw'), \$r); }, cms_records::find('zzraw'));\n\t}\n\tfunction escaped() {\n\t\t\$rows = array();\n\t\tforeach (cms_records::find('zzraw') as \$r) \$rows[] = array('name' => util::e(\$r['name']), 'newsletter' => \$r['newsletter'] ? '1' : '0');\n\t\treturn \$rows;\n\t}\n\tfunction listed() {\n\t\treturn cms_records::listed('zzraw', cms_records::find('zzraw'));\n\t}\n}\n"));
	try {
		controller::forget_models();
		cms_records::forget();
		$lint = function ($method) {
			return lint_html("<!-- render.zzraw.$method --><p><!-- print.name -->x<!-- /print.name --> <!-- print.newsletter -->1<!-- /print.newsletter --></p><!-- /render.zzraw.$method -->");
		};
		check(has_problem($lint('rows'), 'so a false bool prints the mock-up and what visitors typed prints as HTML'), 'a page for visitors: '.json_encode($lint('rows')));
		same(array(), $lint('listed'), 'listed() rows');
		same(array(), $lint('text'), 'rows made text with for_template(), as the booking manage page does');
		same(array(), $lint('escaped'), 'rows a model builds and escapes itself (the shop\'s receipt)');
	} finally {
		remove_dir("$app/zzraw");
		controller::forget_models();
		cms_records::forget();
	}
});

test('the pager counts what the list shows (#88)', function () {
	global $root, $db;
	$app = APPBASE.config::get('models_path', 'models');
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzpager.html';
	temp_model("$app/zzmine", array('zzmine.php' => "<?php\nclass zzmine\n{\n\tstatic function types() {\n\t\treturn array('zzmine' => array('fields' => array('title' => ''), 'owner' => true));\n\t}\n}\n"));
	$pager = function ($filter, $class) {
		$links = "render.pagination.links('cms.zzmine'".($filter === '' ? '' : ", '$filter'").")";
		return "<!-- $links --><nav class=\"$class\"><!-- print.current -->1<!-- /print.current --> of <!-- print.total -->1<!-- /print.total --></nav><!-- /$links -->";
	};
	test_fixture($view);
	file_put_contents($view, "<html><head><title>Pager</title></head><body>"
		."<!-- render.cms.zzmine('owner=me&order=title') --><p class=\"mine\"><!-- print.title -->x<!-- /print.title --></p><!-- /render.cms.zzmine('owner=me&order=title') -->"
		.$pager('owner=me', 'mine').$pager('', 'all').$pager('limit=3', 'top')
		."<!-- render.cms.zzmine('order=title&limit=3') --><i class=\"top\"><!-- print.title -->x<!-- /print.title --></i><!-- /render.cms.zzmine('order=title&limit=3') -->"
		."</body></html>");
	try {
		controller::forget_models();
		cms_records::forget();
		reconnect();
		$member = authentication::create_user('zzpager-member@example.test', 'a long password', 'member', 'Member');
		$other = authentication::create_user('zzpager-other@example.test', 'a long password', 'member', 'Other');
		for ($i = 1; $i <= 12; $i++) cms_records::create('zzmine', array('title' => sprintf('Mine %02d', $i), 'owner' => $member));
		for ($i = 1; $i <= 9; $i++) cms_records::create('zzmine', array('title' => "Theirs $i", 'owner' => $other));
		// two of theirs are drafts: only editors see and count them
		R::exec("UPDATE zzminedata SET enabled = 0 WHERE title IN ('Theirs 8', 'Theirs 9')");
		$render = function ($url, $as = null) use ($root, $db) {
			$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development';
			$cmd = "$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).($as ? ' '.escapeshellarg("--as=$as") : '').' 2>&1';
			return (string)shell_exec($cmd);
		};
		$nav = function ($html, $class) {
			return preg_match('#<nav class="'.$class.'">(\d+) of (\d+)</nav>#', $html, $m) ? "$m[1] of $m[2]" : '';
		};
		$page = $render('/zzpager', 'zzpager-member@example.test');
		// ten to a page (raster_page_size)
		same(10, substr_count($page, '<p class="mine"'), $page);
		same('1 of 2', $nav($page, 'mine'), 'owner=me counts the member\'s 12');
		same('1 of 2', $nav($page, 'all'), 'the member reads their own 12 only');
		same('', $nav($page, 'top'), 'a limit list is one page');
		$visitor = $render('/zzpager');
		same('', $nav($visitor, 'all'), 'a visitor gets no pager over private records');
		same('', $nav($visitor, 'mine'));
		$staff = $render('/zzpager', 'editor');
		same('1 of 3', $nav($staff, 'all'), 'editors count all 21, drafts included');
		same('2 of 3', $nav($render('/zzpager/zzmine_page/2', 'editor'), 'all'));
		preg_match_all('#<i class="top">([^<]*)</i>#', $page, $top);
		$second = $render('/zzpager/zzmine_page/2', 'zzpager-member@example.test');
		preg_match_all('#<i class="top">([^<]*)</i>#', $second, $top2);
		same(array('Mine 01', 'Mine 02', 'Mine 03'), $top[1]);
		same($top[1], $top2[1], 'a limit list doesn\'t take the page offset');
		preg_match_all('#<p class="mine">([^<]*)</p>#', $second, $last);
		same(array('Mine 11', 'Mine 12'), $last[1], 'the last page of my bookings');
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzminedata');
		R::exec("DELETE FROM user WHERE email LIKE 'zzpager-%'");
		@unlink($view);
		remove_dir("$app/zzmine");
		controller::forget_models();
		cms_records::forget();
	}
});

test('editor: marks settle in one pass over the page, however long (#53)', function () {
	$template = template::instance();
	$saved = $template->marks;
	try {
		$template->marks = array();
		for ($i = 1; $i <= 12; $i++) $template->mark(array('kind' => 'field'));
		$m = function ($id) { return '<!--raster:s '.$id.'-->x<!--raster:e '.$id.'-->'; };
		$html = '<html><head><title>'.$m(1).'</title></head><BODY class="a">'.$m(2)
			.'<a title="'.$m(3).'">'.$m(4).'</a><script>var a = "'.$m(5).'";</script>'.$m(6)
			.'<TEXTAREA>'.$m(7).'</textarea><select><option>'.$m(8).'</option></select><p>'.$m(9)
			.'<style>'.$m(10).'</STYLE><!--raster:a 11--><img src="a.jpg"><img alt="<!--raster:a 12-->"></p></body></html>';
		$out = cms_editor::settle($html);
		$hidden = array();
		foreach ($template->marks as $id => $mark) if (!empty($mark['hidden'])) $hidden[] = $id;
		same(array(1, 3, 5, 7, 8, 10, 12), $hidden);
		foreach (array(2, 4, 6, 9) as $id) check(strpos($out, $m($id)) !== false, "mark $id kept: $out");
		check(strpos($out, '<!--raster:a 11--><img') !== false && strpos($out, 'raster:s 1-->') === false, $out);
		// a long list: one forward pass, not a copy of the page per mark
		$template->marks = array();
		$rows = '';
		for ($i = 0; $i < 2000; $i++) {
			$item = $template->mark(array('kind' => 'item'));
			$title = $template->mark(array('kind' => 'item_field'));
			$photo = $template->mark(array('kind' => 'item_attr'));
			$rows .= '<!--raster:s '.$item.'--><li class="dish"><h3>'.$m($title).'</h3><!--raster:a '.$photo.'--><img src="d.jpg" alt="Dish"></li><!--raster:e '.$item.'-->';
		}
		$start = microtime(true);
		$out = cms_editor::settle('<html><head><title>Menu</title></head><body><ul>'.$rows.'</ul></body></html>');
		$took = microtime(true) - $start;
		same(0, count(array_filter($template->marks, function ($mark) { return !empty($mark['hidden']); })));
		check($took < 0.25, 'settle took '.round($took * 1000).' ms for 6,000 marks');
	} finally {
		$template->marks = $saved;
	}
});

// ## Spam checks: the stamp

class test_stampform {
	function form() { return false; }
}
// the forms of $html as a page shows them (not as an email): with their hidden fields
function stamped($html) {
	$previous = template::swap(null);
	try {
		$template = template::instance();
		$template->output = $html;
		$template->index_forms();
		return $template->output;
	} finally {
		template::swap($previous);
	}
}
test('every post form gets a signed stamp', function () {
	$out = stamped('<!-- render.test_stampform.form --><form method="post"><input name="a"></form><!-- /render.test_stampform.form -->'
		.'<!-- render.test_stampform.form --><form method="get"><input name="q"></form><!-- /render.test_stampform.form -->');
	same(1, substr_count($out, 'name="raster_ts"'), $out);
	check(preg_match('/name="raster_ts" value="(\d{10}\.[0-9a-f]{64})"/', $out, $m) === 1, $out);
	$age = validation_spam::stamp_age($m[1]);
	check(is_int($age) && $age >= 0 && $age <= 2, var_export($age, true));
	// the get form is the second one, and has none
	check(strpos(substr($out, strpos($out, 'method="get"')), 'raster_ts') === false, $out);
});
test('a stamp is checked by its signature', function () {
	same(false, validation_spam::stamp_age('123.abc'));
	same(false, validation_spam::stamp_age(time().'.'.str_repeat('0', 64)));
	same(false, validation_spam::stamp_age(array('x')));
	same(null, validation_spam::stamp_age(''));
	same(null, validation_spam::stamp_age(null));
	same(30, validation_spam::stamp_age(validation_spam::stamp(time() - 30)));
});
test('the key: RASTER_SECRET, else data/secret made once, by the site', function () use ($root, $db) {
	$file = APPBASE.'data/secret';
	$kept = is_file($file) ? file_get_contents($file) : null;
	@unlink($file);
	$was = getenv('RASTER_SECRET');
	$server = null;
	try {
		// a web request (here php -S, as PHP-FPM would) makes the file, as
		// the web server's user
		$port = free_port();
		$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'PATH' => getenv('PATH')));
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
		list($status, $body) = http('GET', "http://127.0.0.1:$port/login");
		same(200, $status);
		check((bool)preg_match('/name="raster_ts" value="([^"]+)"/', $body, $stamp), $body);
		check(is_file($file), 'the site made data/secret');
		$made = (string)file_get_contents($file);
		check((bool)preg_match('/^[0-9a-f]{64}$/', $made), "key: $made");
		if (DIRECTORY_SEPARATOR === '/') same('0600', substr(sprintf('%o', fileperms($file)), -4));
		http('GET', "http://127.0.0.1:$port/login");
		same($made, (string)file_get_contents($file), 'a second request keeps the key');
		// a command reads the key the site made, and the page's stamp is
		// signed with it
		putenv('RASTER_SECRET');
		util::$secret = null;
		same($made, util::secret());
		$age = validation_spam::stamp_age($stamp[1]);
		check(is_int($age) && $age >= 0 && $age <= 5, var_export($age, true));
		putenv('RASTER_SECRET=from-the-environment');
		util::$secret = null;
		same('from-the-environment', util::secret());
	} finally {
		if ($server) {
			proc_terminate($server);
			proc_close($server);
		}
		putenv($was === false ? 'RASTER_SECRET' : "RASTER_SECRET=$was");
		util::$secret = null;
		if ($kept === null) @unlink($file); else file_put_contents($file, $kept);
	}
});
test('a command makes no key file, and still stamps', function () use ($root, $db) {
	$file = APPBASE.'data/secret';
	$kept = is_file($file) ? file_get_contents($file) : null;
	@unlink($file);
	$was = getenv('RASTER_SECRET');
	try {
		putenv('RASTER_SECRET');
		util::$secret = null;
		$key = util::secret();
		check((bool)preg_match('/^[0-9a-f]{64}$/', $key), "key: $key");
		check(!file_exists($file), 'the command line made data/secret');
		same($key, util::secret(), 'one key for the whole process');
		same(30, validation_spam::stamp_age(validation_spam::stamp(time() - 30)));
		// raster render, as someone other than the web server may run it
		$out = (string)shell_exec('RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development '.php_cmd().' '.escapeshellarg("$root/bin/raster").' render /login 2>&1');
		check((bool)preg_match('/name="raster_ts" value="\d+\.[0-9a-f]{64}"/', $out), $out);
		check(!file_exists($file), 'raster render made data/secret');
		// the next command gets another key: nothing is kept between them
		util::$secret = null;
		check(util::secret() !== $key, 'a key kept between commands');
	} finally {
		putenv($was === false ? 'RASTER_SECRET' : "RASTER_SECRET=$was");
		util::$secret = null;
		if ($kept === null) @unlink($file); else file_put_contents($file, $kept);
	}
});
test('a key file that is empty or partial is not trusted', function () {
	$file = APPBASE.'data/secret';
	$kept = is_file($file) ? file_get_contents($file) : null;
	$was = getenv('RASTER_SECRET');
	$errors = ini_get('error_log');
	$logged = sys_get_temp_dir().'/raster-secret-log-'.getmypid().'.txt';
	ini_set('error_log', $logged);
	try {
		putenv('RASTER_SECRET');
		foreach (array('', 'abc123', str_repeat('g', 64)) as $bad) {
			file_put_contents($file, $bad);
			util::$secret = null;
			same('', util::secret(), 'contents: '.var_export($bad, true));
			same('', validation_spam::stamp());
		}
	} finally {
		ini_set('error_log', (string)$errors);
		putenv($was === false ? 'RASTER_SECRET' : "RASTER_SECRET=$was");
		util::$secret = null;
		if ($kept === null) @unlink($file); else file_put_contents($file, $kept);
	}
	same(3, substr_count((string)@file_get_contents($logged), 'Raster warning: data/secret is not a valid key'), 'each bad file is logged');
	@unlink($logged);
});
test('the stamp is not a field', function () {
	same(array(), template::instance()->constraints('<input name="raster_ts">'));
	$filled = template::instance()->fill_form('<input type="hidden" name="raster_ts" value="">', array('raster_ts' => 'x'));
	check(strpos($filled, 'value="x"') === false, $filled);
});
test('emails and exports carry no stamp', function () {
	$file = sys_get_temp_dir().'/raster-stamp-'.getmypid().'.html';
	file_put_contents($file, '<!-- render.test_stampform.form --><form method="post"><input name="a"></form><!-- /render.test_stampform.form -->');
	controller::instance()->objects['test_stampform'] = new test_stampform();
	$html = '<!-- render.test_stampform.form --><form method="post"><input name="a"></form><!-- /render.test_stampform.form -->';
	try {
		$email = controller::render_view($file);
		check(strpos($email, 'raster_form') !== false && strpos($email, 'raster_ts') === false, $email);
		putenv('RASTER_EXPORT=1');
		check(strpos(stamped($html), 'raster_ts') === false, 'export');
		putenv('RASTER_EXPORT');
		check(strpos(stamped($html), 'raster_ts') !== false, 'page');
		// a form nothing owns is read by nobody
		check(strpos(stamped('<form method="post"><input name="a"></form>'), 'raster_ts') === false, 'no owner');
	} finally {
		putenv('RASTER_EXPORT');
		unset(controller::instance()->objects['test_stampform']);
		unlink($file);
	}
});
test('a cached page with two forms serves fresh stamps to both', function () use ($root, $db) {
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzstamps.html';
	test_fixture($view);
	file_put_contents($view, '<html><head><title>Two forms</title></head><body>'
		.'<!-- render.newsletter.signup --><form method="post"><input type="email" name="email"><button>Join</button></form><!-- /render.newsletter.signup -->'
		.'<!-- render.authentication.register --><form method="post"><input name="name"><input type="email" name="email"><input type="password" name="password"><button>Sign up</button></form><!-- /render.authentication.register -->'
		.'</body></html>');
	$env = 'RASTER_ENV=production RASTER_SECRET=test-secret RASTER_DB='.escapeshellarg($db);
	shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply');
	foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	$port = free_port();
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, array('RASTER_DB' => $db, 'RASTER_ENV' => 'production', 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		$stamps = function ($body) { preg_match_all('/name="raster_ts" value="([^"]*)"/', $body, $m); return $m[1]; };
		list(, $first) = http('GET', "http://127.0.0.1:$port/zzstamps");
		sleep(1);
		list(, $second, $headers) = http('GET', "http://127.0.0.1:$port/zzstamps");
		check((bool)preg_grep('/^X-Raster-Cache: hit/i', $headers), 'the second answer is the cache: '.implode(' | ', $headers));
		$made = $stamps($second);
		same(2, count($made), 'two forms');
		same($made[0], $made[1], 'one stamp for the whole page');
		$age = validation_spam::stamp_age($made[0]);
		check(is_int($age) && $age >= 0 && $age <= 1, var_export($age, true));
		check($made[0] !== $stamps($first)[0], 'not the first answer\'s stamp');
		$files = array_filter(glob(APPBASE.'data/cache/*') ?: array(), function ($f) { return basename($f) !== 'next' && is_file($f) && strpos(file_get_contents($f), 'Two forms') !== false; });
		same(1, count($files), 'the cached copy');
		$copy = file_get_contents(current($files));
		same(2, substr_count($copy, '{{raster:ts}}'));
		check(!preg_match('/raster_ts" value="\d/', $copy), 'no stamp kept on disk');
	} finally {
		proc_terminate($server); proc_close($server);
		unlink($view);
		foreach (glob(APPBASE.'data/cache/*') ?: array() as $f) unlink($f);
	}
});

// ## Spam checks: suspect()

// What suspect() says of a post to the form $owner, sent as a browser sends
// it: with a stamp made 60 s ago unless $fields has raster_ts (null: none),
// from $server's REMOTE_ADDR (each post its own address unless given) and
// headers. A fresh validation object, as a new request has.
function spam_post($fields, $server = array(), $checked = null, $owner = 'zz.form') {
	static $n = 0;
	$n++;
	$template = template::instance();
	$kept = array($_SERVER, $_POST, $template->current_call, $template->forms);
	$validation = isset(controller::instance()->objects['validation']) ? controller::instance()->objects['validation'] : null;
	try {
		$_SERVER = $server + array('REMOTE_ADDR' => '192.0.2.'.($n % 250 + 1)) + $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if (!array_key_exists('raster_ts', $fields)) $fields['raster_ts'] = stamp_for_tests(60);
		if ($fields['raster_ts'] === null) unset($fields['raster_ts']);
		$_POST = array('raster_form' => $owner) + $fields;
		$template->current_call = $owner;
		$template->forms[$owner] = array_fill_keys(array_diff(array_keys($fields), template::$internal_fields), array());
		unset(controller::instance()->objects['validation']);
		return validation::get()->suspect($checked);
	} finally {
		list($_SERVER, $_POST, $template->current_call, $template->forms) = $kept;
		if ($validation) controller::instance()->objects['validation'] = $validation;
		else unset(controller::instance()->objects['validation']);
	}
}
// settings for $fn, put back after
function with_settings($settings, $fn) {
	$kept = array();
	foreach ($settings as $name => $value) {
		$kept[$name] = config::get($name);
		config::set($name)->to($value);
	}
	try {
		return $fn();
	} finally {
		foreach ($kept as $name => $value) config::set($name)->to($value);
	}
}
// what PHP's error log gets while $fn runs: array(result, log)
function spam_logged($fn) {
	$errors = ini_get('error_log');
	$logged = sys_get_temp_dir().'/raster-spam-log-'.getmypid().'.txt';
	@unlink($logged);
	ini_set('error_log', $logged);
	validation_spam::$warned = array();
	try {
		$result = $fn();
	} finally {
		ini_set('error_log', (string)$errors);
	}
	$log = (string)@file_get_contents($logged);
	@unlink($logged);
	return array($result, $log);
}
// an empty formhit that is there: a missing table would also count 0
function spam_clear() {
	cms_store::connect();
	validation_spam::$ready = false;
	check(validation_spam::salt() !== null, 'the spam tables are made');
	R::exec('DELETE FROM formhit');
	check(in_array('formhit', R::inspect()), 'formhit is there');
}
function spam_hits() {
	return (int)R::getCell('SELECT COUNT(*) FROM formhit');
}

test('suspect: the stamp', function () {
	same(array('no_stamp'), spam_post(array('message' => 'Hi', 'raster_ts' => null)));
	same(array('stamp_invalid'), spam_post(array('message' => 'Hi', 'raster_ts' => time().'.'.str_repeat('a', 64))));
	same(array('stamp_invalid'), spam_post(array('message' => 'Hi', 'raster_ts' => 'nonsense')));
	same(array('too_fast'), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(1))));
	same(array('stamp_old'), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(90000))));
	same(array(), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(60))));
	with_settings(array('spam_min_seconds' => false), function () {
		same(array(), spam_post(array('message' => 'Hi', 'raster_ts' => null)), 'spam_min_seconds false');
		same(array(), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(90000))), 'spam_min_seconds false');
	});
	with_settings(array('spam_max_age' => false), function () {
		same(array(), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(90000))), 'spam_max_age false');
		same(array('too_fast'), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(1))), 'the rest still checked');
	});
	with_settings(array('spam_min_seconds' => 10), function () {
		same(array('too_fast'), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(5))));
	});
	// a form that wasn't sent, and a GET, have nothing to suspect
	$template = template::instance();
	$kept = array($_SERVER, $_POST, $template->current_call);
	try {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('raster_form' => 'zz.other', 'message' => 'Hi');
		$template->current_call = 'zz.form';
		unset(controller::instance()->objects['validation']);
		same(array(), validation::get()->suspect(), 'another form was sent');
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset(controller::instance()->objects['validation']);
		same(array(), validation::get()->suspect(), 'a GET');
	} finally {
		list($_SERVER, $_POST, $template->current_call) = $kept;
		unset(controller::instance()->objects['validation']);
	}
});

test('suspect: limits per IP and per address, per form', function () {
	spam_clear();
	for ($i = 1; $i <= 5; $i++) same(array(), spam_post(array('email' => "guest$i@example.com"), array('REMOTE_ADDR' => '203.0.113.7')), "post $i");
	same(array('too_many_from_ip'), spam_post(array('email' => 'guest6@example.com'), array('REMOTE_ADDR' => '203.0.113.7')), 'the 6th');
	same(array(), spam_post(array('email' => 'guest7@example.com'), array('REMOTE_ADDR' => '203.0.113.7'), null, 'zz.other'), 'another form counts apart');
	same(21, spam_hits(), 'for each post a row for the IP, one for the address, and one for the two together');
	spam_clear();
	for ($i = 1; $i <= 3; $i++) same(array(), spam_post(array('email' => 'ana@example.com')), "address post $i");
	same(array('too_many_from_email'), spam_post(array('email' => 'ANA@example.com ')), 'the 4th, written another way');
	with_settings(array('spam_per_email' => false), function () {
		same(array(), spam_post(array('email' => 'ana@example.com')), 'spam_per_email false');
	});
	with_settings(array('spam_per_ip' => false), function () {
		spam_clear();
		for ($i = 1; $i <= 7; $i++) same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.8')), "spam_per_ip false, post $i");
		same(0, spam_hits(), 'nothing is counted that nothing checks');
	});
	with_settings(array('spam_per_ip' => 1), function () {
		spam_clear();
		same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.9')));
		same(array('too_many_from_ip'), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.9')));
	});
	// a post that fails valid() is never counted: the model asks only after it
	spam_clear();
	$template = template::instance();
	$kept = array($_SERVER, $_POST, $template->current_call, $template->forms);
	try {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$template->current_call = 'zz.form';
		$template->forms['zz.form'] = array('email' => array('required' => true, 'type' => 'email'));
		$asked = 0;
		foreach (array('', 'not an address', 'ana@example.com', '', 'bo@example.com') as $email) {
			$_POST = array('raster_form' => 'zz.form', 'raster_ts' => stamp_for_tests(60), 'email' => $email);
			unset(controller::instance()->objects['validation']);
			$v = validation::get();
			if (!$v->valid()) continue;
			$v->suspect();
			same(array(), $v->suspect(), 'asked twice, counted once');
			$asked++;
		}
		same(2, $asked);
		same(3 * $asked, spam_hits(), 'an IP, an address and a pair row for each post that was asked about');
	} finally {
		list($_SERVER, $_POST, $template->current_call, $template->forms) = $kept;
		unset(controller::instance()->objects['validation']);
	}
});

test('suspect: IPv6 counts by /64', function () {
	spam_clear();
	for ($i = 1; $i <= 5; $i++) same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => "2001:db8:1:2::$i")), "post $i");
	same(array('too_many_from_ip'), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '2001:db8:1:2::6')), 'the 6th of the /64');
	same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '2001:db8:1:3::1')), 'the next /64');
	// IPv4 written as IPv6 is IPv4: two of them count apart
	spam_clear();
	for ($i = 1; $i <= 5; $i++) spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '::ffff:203.0.113.7'));
	same(array('too_many_from_ip'), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '::ffff:203.0.113.7')), 'the 6th from one mapped address');
	same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '::ffff:203.0.113.8')), 'another mapped address');
	same(array('too_many_from_ip'), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.7')), 'the same address written as IPv4');
});

test('a trusted proxy\'s header is read, an untrusted one ignored', function () {
	$kept = $_SERVER;
	try {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.9';
		$_SERVER['HTTP_FLY_CLIENT_IP'] = '198.51.100.44';
		same('198.51.100.1', validation_spam::client_ip(), 'no trusted_proxies: a private address is a proxy');
		with_settings(array('trusted_proxies' => array('10.0.0.0/8')), function () {
			same('198.51.100.1', validation_spam::client_ip(), 'the last entry that is not a proxy');
			$_SERVER['HTTP_X_FORWARDED_FOR'] = 'nonsense';
			same('10.0.0.2', validation_spam::client_ip(), 'a header that is not an IP');
			unset($_SERVER['HTTP_X_FORWARDED_FOR']);
			same('10.0.0.2', validation_spam::client_ip(), 'no header');
			with_settings(array('client_ip_header' => 'Fly-Client-IP'), function () {
				same('198.51.100.44', validation_spam::client_ip(), 'a single-value header');
			});
		});
		with_settings(array('trusted_proxies' => array('10.0.0.3')), function () {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
			same('10.0.0.2', validation_spam::client_ip(), 'a proxy that is not trusted');
		});
		with_settings(array('trusted_proxies' => array('10.0.0.0/8')), function () {
			$_SERVER['REMOTE_ADDR'] = '::ffff:10.0.0.2';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '::ffff:198.51.100.1, ::ffff:10.0.0.9';
			same('198.51.100.1', validation_spam::client_ip(), 'a mapped proxy is in its IPv4 range, and its header is unmapped');
		});
		unset($_SERVER['HTTP_X_FORWARDED_FOR']);
		$_SERVER['REMOTE_ADDR'] = '::ffff:10.0.0.2';
		same('10.0.0.2', validation_spam::client_ip(), 'a mapped REMOTE_ADDR');
		with_settings(array('trusted_proxies' => array('fd00::/8')), function () {
			$_SERVER['REMOTE_ADDR'] = 'fd12::1';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::7';
			same('2001:db8::7', validation_spam::client_ip(), 'IPv6');
			// a port, or brackets, as some proxies write them
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::1]';
			same('2001:db8::1', validation_spam::client_ip(), 'IPv6 in brackets');
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::1]:443';
			same('2001:db8::1', validation_spam::client_ip(), 'IPv6 in brackets, with a port');
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1:5678, [fd12::9]:80';
			same('198.51.100.1', validation_spam::client_ip(), 'IPv4 with a port, behind a proxy with one');
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '[nonsense]:80';
			same('fd12::1', validation_spam::client_ip(), 'brackets around what is not an IP');
		});
		with_settings(array('trusted_proxies' => array('10.0.0.0/8')), function () {
			$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4:5678';
			same('1.2.3.4', validation_spam::client_ip(), 'IPv4 with a port');
		});
	} finally {
		$_SERVER = $kept;
	}
	check(validation_spam::in_range('10.1.2.3', '10.0.0.0/8'));
	check(!validation_spam::in_range('11.1.2.3', '10.0.0.0/8'));
	check(validation_spam::in_range('192.168.1.130', '192.168.1.128/25'));
	check(!validation_spam::in_range('192.168.1.127', '192.168.1.128/25'));
	check(validation_spam::in_range('10.0.0.2', '10.0.0.2'));
	check(validation_spam::in_range('2001:db8::1', '2001:db8::/32'));
	check(!validation_spam::in_range('2001:db9::1', '2001:db8::/32'));
	check(!validation_spam::in_range('10.0.0.2', '::/0'), 'IPv4 is never in an IPv6 range');
	check(!validation_spam::in_range('10.0.0.2', 'nonsense/8'));
	check(!validation_spam::in_range('', '10.0.0.0/8'));
});

test('with no trusted_proxies, a private address is a proxy and a public one is not', function () {
	$kept = $_SERVER;
	try {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
		foreach (array('10.1.2.3', '172.16.0.1', '172.31.255.254', '192.168.1.1', '127.0.0.1', '169.254.1.1', '::1', 'fdaa::1', 'fc00::5', 'fe80::1', '::ffff:10.0.0.2') as $remote) {
			$_SERVER['REMOTE_ADDR'] = $remote;
			same('203.0.113.9', validation_spam::client_ip(), "the header, from $remote");
		}
		foreach (array('198.51.100.7', '172.32.0.1', '2001:db8::1', 'fec0::1') as $remote) {
			$_SERVER['REMOTE_ADDR'] = $remote;
			same($remote, validation_spam::client_ip(), "a public $remote: the header is ignored");
		}
		// the visitor wrote the first entry, the proxy the last
		$_SERVER['REMOTE_ADDR'] = 'fdaa::1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9';
		same('203.0.113.9', validation_spam::client_ip(), 'the last entry the proxy wrote');
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.9';
		same('203.0.113.9', validation_spam::client_ip(), 'past a second private hop');
		with_settings(array('trusted_proxies' => array()), function () {
			same('fdaa::1', validation_spam::client_ip(), 'array(): trust nobody');
		});
		with_settings(array('trusted_proxies' => array('10.0.0.0/8')), function () {
			same('fdaa::1', validation_spam::client_ip(), 'a list replaces the default');
			$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
			same('203.0.113.9', validation_spam::client_ip(), 'and trusts what it names');
		});
	} finally {
		$_SERVER = $kept;
	}
});

test('no raw IP is stored, logged or kept past the day', function () {
	spam_clear();
	$log = log::instance();
	log::enable();
	try {
		list(, $logged) = spam_logged(function () {
			for ($i = 0; $i < 3; $i++) spam_post(array('email' => 'ip@example.com'), array('REMOTE_ADDR' => '203.0.113.7'));
		});
		$console = $log->javascript_console();
	} finally {
		log::disable();
	}
	$stored = json_encode(array(R::getAll('SELECT * FROM formhit'), R::getAll('SELECT * FROM formsalt')));
	check(strpos($stored, '203.0.113.7') === false && strpos($stored, 'ip@example.com') === false, $stored);
	check(strpos($stored, '203.0.113') === false, 'not even part of it');
	check(strpos($console.$logged, '203.0.113.7') === false, $console.$logged);
	same(9, spam_hits());
	same(1, (int)R::getCell('SELECT COUNT(*) FROM formsalt'), 'one salt');
	$salt = R::getCell('SELECT salt FROM formsalt');
	R::exec('UPDATE formsalt SET day = ?', array(date('Y-m-d', strtotime('-1 day'))));
	same(array(), spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.7')));
	same(1, (int)R::getCell('SELECT COUNT(*) FROM formsalt'), 'the old salt is gone');
	check(R::getCell('SELECT salt FROM formsalt') !== $salt, 'a new salt');
	same(date('Y-m-d'), R::getCell('SELECT day FROM formsalt'));
	same(1, spam_hits(), "only the new post's row is left");
	// never backwards: a request that started before midnight keeps the new salt
	$tomorrow = date('Y-m-d', strtotime('+1 day'));
	R::exec('UPDATE formsalt SET day = ?', array($tomorrow));
	$salt = R::getCell('SELECT salt FROM formsalt');
	same($salt, validation_spam::salt(), 'a stored day after today is kept');
	same($tomorrow, R::getCell('SELECT day FROM formsalt'));
	R::exec('UPDATE formsalt SET day = ?', array(date('Y-m-d')));
});

test('suspect: links and the blocklist', function () {
	same(array('too_many_links'), spam_post(array('message' => 'http://a.example https://b.example and www.c.example')));
	same(array('too_many_links'), spam_post(array('message' => '[url=x]a[/url] [URL=y]b[/URL] HTTP://c.example')), 'any case');
	same(array(), spam_post(array('message' => 'http://a.example and https://b.example')));
	same(array(), spam_post(array('message' => 'http://a.example https://b.example www.c.example'), array(), array()), 'array(): no text check');
	same(array(), spam_post(array('name' => 'http://a.example https://b.example www.c.example', 'message' => 'Hi'), array(), array('message')), 'only the fields named');
	same(array('too_many_links'), spam_post(array('name' => 'http://a.example https://b.example', 'message' => 'www.c.example'), array(), array('name', 'message')), 'fields are counted together');
	with_settings(array('spam_max_links' => 0), function () {
		same(array('too_many_links'), spam_post(array('message' => 'see www.a.example')), '0 allows none');
		same(array(), spam_post(array('message' => 'no links')));
	});
	with_settings(array('spam_max_links' => false), function () {
		same(array(), spam_post(array('message' => 'http://a.example https://b.example www.c.example http://d.example')), 'false is off');
	});
	with_settings(array('spam_blocklist' => array('casino', 'pills.example')), function () {
		same(array('blocked'), spam_post(array('message' => 'Casino night!')));
		same(array(), spam_post(array('message' => 'We like casinos')), 'a word matches whole');
		same(array('blocked'), spam_post(array('message' => 'see https://shop.pills.example/x')), 'a subdomain');
		same(array('blocked'), spam_post(array('message' => 'see www.pills.example')));
		same(array('blocked'), spam_post(array('email' => 'a@pills.example', 'name' => 'Ana', 'message' => 'Hi'), array(), array('name', 'subject', 'message')), "the email's domain, whatever the fields say");
		// a hold-type form (newsletter, sign-up, booking) would answer
		// try_again to that address forever, so array() checks no text
		same(array(), spam_post(array('email' => 'b@pills.example', 'message' => 'Casino night!'), array(), array()), 'array(): no blocklist, the email included');
		same(array(), spam_post(array('email' => 'a@notpills.example', 'message' => 'see https://notpills.example/x')), 'another domain');
		same(array(), spam_post(array('message' => 'Hi')));
		same(array('too_many_links', 'blocked'), spam_post(array('message' => 'http://casino.example https://b.example http://pills.example')), 'every check runs');
	});
	same(array(), spam_post(array('message' => 'Casino night!')), 'an empty blocklist checks nothing');
	same(array('no_stamp', 'too_many_links'), spam_post(array('message' => 'http://a.example https://b.example www.c.example', 'raster_ts' => null)), 'in the order of the table');
});

test('checks that can\'t run pass and log', function () {
	sqlite_only('drops a table under a frozen connection');
	cms_store::connect();
	R::exec('DROP TABLE IF EXISTS formhit');
	cms_store::forget();
	validation_spam::$ready = false;
	R::freeze(true);
	try {
		list($reasons, $logged) = spam_logged(function () {
			$first = spam_post(array('email' => 'frozen@example.com', 'message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.7'));
			spam_post(array('email' => 'frozen@example.com', 'message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.7'));
			return $first;
		});
		same(array(), $reasons, 'a good stamp, and limits that cannot be counted');
		check(strpos($logged, 'Raster warning: spam: ') !== false, $logged);
		same(1, substr_count($logged, 'Raster warning: spam: '), 'once per request: '.$logged);
		check(strpos($logged, '203.0.113.7') === false && strpos($logged, 'frozen@example.com') === false, $logged);
		same(array('no_stamp'), spam_post(array('message' => 'Hi', 'raster_ts' => null)), 'the stamp needs no table');
	} finally {
		R::freeze(false);
		cms_store::forget();
		validation_spam::$ready = false;
	}
	// no key: the file isn't one (the command line makes a key of its own
	// when there is no file, so a broken one is the way to have none here)
	$file = APPBASE.'data/secret';
	$kept = is_file($file) ? file_get_contents($file) : null;
	$was = getenv('RASTER_SECRET');
	file_put_contents($file, 'abc');
	try {
		putenv('RASTER_SECRET');
		util::$secret = null;
		list($reasons, $logged) = spam_logged(function () {
			return array(spam_post(array('message' => 'Hi', 'raster_ts' => null)), spam_post(array('message' => 'Hi', 'raster_ts' => stamp_for_tests(1))));
		});
		same(array(array(), array()), $reasons, 'no key: the stamp check passes');
		same(1, substr_count($logged, 'Raster warning: spam: '), $logged);
	} finally {
		putenv($was === false ? 'RASTER_SECRET' : "RASTER_SECRET=$was");
		util::$secret = null;
		if ($kept === null) @unlink($file); else file_put_contents($file, $kept);
	}
});

test('editors are never flagged or counted', function () {
	spam_clear();
	$reasons = booking_as_editor(function () {
		$all = array();
		for ($i = 0; $i < 7; $i++) $all[] = spam_post(array('email' => 'staff@example.com', 'message' => 'http://a.example https://b.example www.c.example', 'raster_ts' => null), array('REMOTE_ADDR' => '203.0.113.7'));
		return $all;
	});
	same(array_fill(0, 7, array()), $reasons);
	same(0, spam_hits());
	spam_post(array('message' => 'Hi'), array('REMOTE_ADDR' => '203.0.113.7'));
	same(1, spam_hits(), "a visitor's post is counted");
});

test('doctor warns when many people seem to share one address', function () use ($root) {
	$warned = function ($environment = 'production') use ($root) {
		foreach (raster_project::doctor($root, 'application', $environment) as $c) {
			if ($c['status'] === 'warn' && strpos($c['title'], 'Many people seem to share one address') !== false) {
				check(strpos($c['detail'], 'set `trusted_proxies` and `client_ip_header`') !== false, json_encode($c));
				return true;
			}
		}
		return false;
	};
	spam_clear();
	R::exec('UPDATE formsalt SET proxied = 0');
	// a private address is a proxy now: six people behind it, each with
	// their own address in the header, are six visitors
	for ($i = 1; $i <= 6; $i++) spam_post(array('email' => "behind$i@example.com"), array('REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => "203.0.113.$i"));
	check(!$warned(), 'six visitors behind a private proxy');
	// and one visitor behind it with six emails is one address
	for ($i = 1; $i <= 6; $i++) spam_post(array('email' => "one$i@example.com"), array('REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99'));
	check($warned(), 'one visitor behind a private proxy, six emails');
	spam_clear();
	R::exec('UPDATE formsalt SET proxied = 0');
	// one person posting again and again is one address
	for ($i = 1; $i <= 8; $i++) spam_post(array('email' => 'again@example.com'), array('REMOTE_ADDR' => '198.51.100.7'));
	check(!$warned(), 'one email, many times');
	// five people on one address is a family or an office
	for ($i = 1; $i <= 5; $i++) spam_post(array('email' => "office$i@example.com"), array('REMOTE_ADDR' => '198.51.100.8'));
	check(!$warned(), 'five emails');
	// spread over two forms, each counts apart
	spam_post(array('email' => 'office6@example.com'), array('REMOTE_ADDR' => '198.51.100.8'), null, 'zz.other');
	check(!$warned(), 'the 6th on another form');
	spam_post(array('email' => 'office6@example.com'), array('REMOTE_ADDR' => '198.51.100.8'));
	check($warned(), 'six emails from one address, one form');
	check(!$warned('development'), 'production only, as before');
	R::exec('UPDATE formsalt SET proxied = 0');
	with_settings(array('spam_per_ip' => false), function () {
		for ($i = 1; $i <= 7; $i++) spam_post(array('email' => "free$i@example.com"), array('REMOTE_ADDR' => '198.51.100.9'));
	});
	check(!$warned(), 'nothing is noted when nothing counts by IP');
	R::exec('DROP TABLE formsalt');
	cms_store::forget();
	validation_spam::$ready = false;
	check(!$warned(), 'no table');
});

test('doctor warns about a key file the site cannot use', function () use ($root) {
	$file = APPBASE.'data/secret';
	$kept = is_file($file) ? file_get_contents($file) : null;
	$was = getenv('RASTER_SECRET');
	$warned = function () use ($root) {
		foreach (raster_project::doctor($root, 'application', 'development') as $c) {
			if ($c['status'] === 'warn' && strpos($c['title'], "this user can't read data/secret (or it isn't a key)") !== false) return true;
		}
		return false;
	};
	try {
		putenv('RASTER_SECRET');
		@unlink($file);
		check(!$warned(), 'no file');
		file_put_contents($file, str_repeat('ab', 32));
		check(!$warned(), 'a key');
		file_put_contents($file, 'abc');
		check($warned(), 'not a key');
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			file_put_contents($file, str_repeat('ab', 32));
			chmod($file, 0200);
			check($warned(), "a key this user can't read");
			chmod($file, 0600);
		}
		putenv('RASTER_SECRET=from-the-environment');
		file_put_contents($file, 'abc');
		check(!$warned(), 'RASTER_SECRET is used instead');
	} finally {
		putenv($was === false ? 'RASTER_SECRET' : "RASTER_SECRET=$was");
		util::$secret = null;
		if (is_file($file)) chmod($file, 0600);
		if ($kept === null) @unlink($file); else file_put_contents($file, $kept);
	}
});

test('describe lists the spam settings that are set', function () {
	require_once BASE.'tools/describe.php';
	with_settings(array('spam_per_ip' => 10, 'trusted_proxies' => array('10.0.0.0/8'), 'spam_ip_blocks' => true), function () {
		$site = raster_describe::site(array('settings'));
		same(10, $site['settings']['spam_per_ip']);
		same(true, $site['settings']['spam_ip_blocks']);
		same(array('10.0.0.0/8'), $site['settings']['trusted_proxies']);
	});
});

test('hold() asks again, or to slow down', function () {
	$raised = new ReflectionProperty('validation', 'raised');
	$raised->setAccessible(true);
	$template = template::instance();
	$kept = array($template->current_block, $_POST);
	try {
		$template->current_block = '<form method="post"><input name="email"></form>';
		$_POST = array('email' => 'ana@example.com');
		unset(controller::instance()->objects['validation']);
		$v = validation::get();
		$html = $v->hold(array('too_fast'));
		check(strpos($html, 'value="ana@example.com"') !== false, $html);
		same(array('try_again'), array_keys($raised->getValue($v)));
		unset(controller::instance()->objects['validation']);
		$v = validation::get();
		$v->hold(array('no_stamp', 'too_many_from_email'));
		same(array('slow_down'), array_keys($raised->getValue($v)));
	} finally {
		list($template->current_block, $_POST) = $kept;
		unset(controller::instance()->objects['validation']);
	}
});

test('holds(): the per-IP count flags, it does not hold, unless spam_ip_blocks', function () {
	same(array(), validation_spam::holds(array('too_many_from_ip')));
	same(array('no_stamp', 'too_many_from_email'), validation_spam::holds(array('no_stamp', 'too_many_from_ip', 'too_many_from_email')), 'the rest kept, in order');
	same(array(), validation_spam::holds(array()));
	with_settings(array('spam_ip_blocks' => true), function () {
		same(array('too_many_from_ip'), validation_spam::holds(array('too_many_from_ip')), 'spam_ip_blocks true');
	});
});

test('six posts from one address with six emails are all taken, and asks still mail', function () use ($base, $maildir) {
	spam_clear();
	$from = function ($ip) { return array('X-Forwarded-For: '.$ip); };
	// the test server answers 127.0.0.1, a proxy by default, so the header is the visitor
	for ($i = 1; $i <= 6; $i++) {
		list($status, , $headers) = form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => "shared$i@example.com"), $from('203.0.113.50'));
		same(303, $status, "newsletter $i");
		check(strpos((string)location($headers), 'done=check_email') !== false, "newsletter $i: ".location($headers));
	}
	cms_store::connect();
	same(6, (int)R::getCell("SELECT COUNT(*) FROM subscriber WHERE email LIKE 'shared%@example.com'"), 'six subscribers');
	for ($i = 1; $i <= 6; $i++) {
		list($status, , $headers) = form_post("$base/register", array('raster_form' => 'authentication.register', 'name' => "Shared $i", 'email' => "shared$i@example.com", 'password' => 'correct horse 1', 'password_again' => 'correct horse 1'), $from('203.0.113.51'));
		same(303, $status, "register $i");
		check(strpos((string)location($headers), 'done=registered') !== false, "register $i: ".location($headers));
	}
	same(6, (int)R::getCell("SELECT COUNT(*) FROM user WHERE email LIKE 'shared%@example.com'"), 'six accounts');
	$mails = function () use ($maildir) { return count(glob("$maildir/*.eml") ?: array()); };
	for ($i = 1; $i <= 6; $i++) {
		$before = $mails();
		list($status, , $headers) = form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => "shared$i@example.com"), $from('203.0.113.52'));
		same(303, $status, "forgot $i");
		same($before + 1, $mails(), "ask $i from the shared address mails");
	}
	// per address it still stops: the 4th ask for one email (one asked above), from anywhere, mails nothing
	for ($i = 2; $i <= 4; $i++) {
		R::exec('UPDATE user SET reset_expires = ? WHERE email = ?', array(date('Y-m-d H:i:s', time() - 7200), 'shared1@example.com'));
		$before = $mails();
		same(303, form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'shared1@example.com'), $from("198.51.100.$i"))[0], "ask $i for one email");
		same($before + ($i < 4 ? 1 : 0), $mails(), $i < 4 ? "ask $i mails" : 'the 4th ask mails nothing');
	}
	// the counts were by the header: one row per post for 203.0.113.50 alone
	same(6, (int)R::getCell("SELECT COUNT(*) FROM formhit WHERE form = 'newsletter.signup' AND kind = 'ip'"), 'one visitor on the newsletter');
	same(1, (int)R::getCell("SELECT COUNT(DISTINCT hash) FROM formhit WHERE form = 'newsletter.signup' AND kind = 'ip'"));
	// another header is another visitor: were the header ignored, both would be 127.0.0.1
	same(303, form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'shared7@example.com'), $from('203.0.113.53'))[0]);
	same(2, (int)R::getCell("SELECT COUNT(DISTINCT hash) FROM formhit WHERE form = 'newsletter.signup' AND kind = 'ip'"), 'two headers, two visitors');
});

// ## Bundled contact

// A view with the contact form (zzcontact.html, an alert for each problem)
// and the staff email, so the bundled contact model counts. $fn runs with
// the theme's folder; the views, the messages and the counts are gone after.
function contact_site($fn) {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	$alerts = '';
	foreach (array('sent', 'name_invalid', 'email_invalid', 'phone_invalid', 'subject_invalid', 'message_invalid') as $alert) {
		$alerts .= "<!-- print.validation.alert('$alert') --><p>ALERT $alert</p><!-- /print.validation.alert('$alert') -->";
	}
	test_fixture("$theme/zzcontact.html");
	file_put_contents("$theme/zzcontact.html", "<html><head><title>Write to us</title></head><body>$alerts"
		."<!-- render.contact.form --><form method=\"post\"><input name=\"name\"><input type=\"email\" name=\"email\" required><input name=\"phone\"><input name=\"subject\">"
		."<textarea name=\"message\" required></textarea><button>Send</button></form><!-- /render.contact.form --></body></html>");
	test_fixture("$theme/_email/contact_received.html");
	file_put_contents("$theme/_email/contact_received.html", "<html><head><title>Message: <!-- print.self.subject -->none<!-- /print.self.subject --></title></head><body>"
		."<p>From <!-- print.self.name -->Ana<!-- /print.self.name --> on <!-- print.self.page_url -->page<!-- /print.self.page_url --></p>"
		."<p><!-- print.self.message -->Hi<!-- /print.self.message --></p><p>All of them: <!-- print.self.staff_url -->staff<!-- /print.self.staff_url --></p></body></html>");
	controller::forget_models();
	cms_records::forget();
	cms_store::connect();
	spam_clear();
	// this process booted without a view that uses contact, so it binds the
	// listener system/config/events.php binds for a site that has one
	event::bind('contact.received')->to('contact', 'staff_mail');
	try {
		return $fn($theme);
	} finally {
		event::unbind('contact.received')->from('contact', 'staff_mail');
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS contactmessagedata');
		cms_store::forget();
		@unlink("$theme/zzcontact.html");
		@unlink("$theme/_email/contact_received.html");
		controller::forget_models();
		cms_records::forget();
	}
}
// what the contact form at $base answers to $fields, sent as a browser
// sends it: array(status, body, where it redirected to)
function contact_post($base, $fields) {
	list($status, $body, $headers) = form_post("$base/zzcontact", array('raster_form' => 'contact.form') + $fields);
	return array($status, (string)$body, (string)location($headers));
}
// the contact events a request to the test server sent while $fn ran, as
// array(event, payload): a site model of the server's listens to them
function contact_seen($fn) {
	$app = APPBASE.config::get('models_path', 'models');
	$file = sys_get_temp_dir().'/raster-contact-seen-'.getmypid().'.jsonl';
	@unlink($file);
	$write = 'file_put_contents('.var_export($file, true).', json_encode(array(%s, $message))."\n", FILE_APPEND);';
	temp_model("$app/zzcontactseen", array('zzcontactseen.php' => "<?php\nclass zzcontactseen\n{\n"
		."\tstatic function listens() {\n\t\treturn array('contact.received' => 'received', 'contact.flagged' => 'flagged');\n\t}\n"
		."\tfunction received(\$message) {\n\t\t".sprintf($write, "'contact.received'")."\n\t}\n"
		."\tfunction flagged(\$message) {\n\t\t".sprintf($write, "'contact.flagged'")."\n\t}\n}\n"));
	try {
		$fn();
	} finally {
		remove_dir("$app/zzcontactseen");
		controller::forget_models();
	}
	$seen = array();
	foreach (array_filter(explode("\n", (string)@file_get_contents($file))) as $line) $seen[] = json_decode($line, true);
	@unlink($file);
	return $seen;
}
// the contact events this process sent while $fn ran, as array(event, payload)
function contact_events($fn) {
	global $booking_seen;
	$booking_seen = array();
	$names = array('contact.received', 'contact.flagged');
	foreach ($names as $name) event::bind($name)->to(null, 'test_booking_seen');
	try {
		$fn();
	} finally {
		foreach ($names as $name) event::unbind($name)->from(null, 'test_booking_seen');
	}
	return $booking_seen;
}
// what a write was refused for, or array() when it went through
function contact_refused($fn) {
	try {
		$fn();
	} catch (cms_refused $e) {
		return $e->problems;
	}
	return array();
}
function contact_render($url, $as = null) {
	global $root, $db;
	$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_ENV=development';
	return (string)shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).($as ? ' --as='.escapeshellarg($as) : '').' 2>&1; echo "[exit $?]"');
}

test('contact types count only where views use the model', function () use ($root) {
	controller::forget_models();
	cms_records::forget();
	check(cms_records::info('contactmessage') === null, 'contactmessage is declared without a view that uses contact');
	$dir = sys_get_temp_dir().'/raster-contact-loads-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(get_included_files())); });');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite"));
	shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render /about 2>&1');
	$included = json_decode((string)@file_get_contents("$dir/out.json"), true);
	array_map('unlink', glob("$dir/*") ?: array());
	@rmdir($dir);
	check(is_array($included), 'no report from the render');
	same(array(), array_values(array_filter($included, function ($f) { return strpos($f, '/system/models/contact/') !== false; })), 'contact files loaded:');
	contact_site(function () {
		$info = cms_records::info('contactmessage');
		same('contact', $info['model']);
		same(array('title', 'name', 'email', 'phone', 'subject', 'message', 'page', 'status', 'spam'), array_keys($info['fields']));
		same(array('visitor', false, false, false, false), array($info['create'], $info['public'], $info['owner'], $info['staff_add'], $info['spam']));
		same(array('done' => 'editor', 'spam' => 'editor', 'not_spam' => 'editor'), $info['actions']);
		same(true, contact::ready());
	});
});

test('a site contact model still answers and the bundled one loads nothing', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$view = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default').'/zzown.html';
	// a site's own, as AGENTS.md and the wiki taught: in processes of their
	// own, since this one may have the bundled class already
	temp_model("$app/contact", array('contact.php' => "<?php\nclass contact\n{\n\tfunction send() {\n\t\treturn array(array('x' => 'own'));\n\t}\n}\n"));
	test_fixture($view);
	file_put_contents($view, '<html><head><title>Own</title></head><body><!-- render.contact.send --><p class="own"><!-- print.x -->mock<!-- /print.x --></p><!-- /render.contact.send --></body></html>');
	try {
		controller::forget_models();
		$out = race_php('require_once BASE."tools/inspector.php"; echo json_encode(array(controller::model("contact")["bundled"], cms_records::info("contactmessage") === null, array_column((new raster_inspector())->lint_models(), "message"), array_values(preg_grep("~/system/models/contact/~", get_included_files()))));', 1);
		$page = contact_render('/zzown');
	} finally {
		remove_dir("$app/contact");
		@unlink($view);
		controller::forget_models();
		cms_records::forget();
	}
	$out = json_decode($out[0], true);
	check(is_array($out), 'the process said: '.json_encode($out));
	same(false, $out[0], 'bundled:');
	same(true, $out[1], 'contactmessage is undeclared:');
	check(has_problem(array_map(function ($m) { return array('message' => $m); }, $out[2]), 'models/contact shadows the bundled contact'), 'lint: '.json_encode($out[2]));
	same(array(), $out[3], 'bundled contact files loaded:');
	check(strpos($page, '<p class="own">own</p>') !== false && strpos($page, '[exit 0]') !== false, $page);
});

test('contact: a message is stored and mailed once, after the answer', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		$mails = array();
		$seen = contact_seen(function () use ($booking_base, &$mails) {
			$mails = booking_mails(function () use ($booking_base) {
				list($status, , $location) = contact_post($booking_base, array('name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '0721 000 000', 'subject' => 'Hello', 'message' => 'Is the terrace open?'));
				same(303, $status);
				check(strpos($location, 'done=sent') !== false, $location);
			});
		});
		$rows = cms_records::find('contactmessage');
		same(1, count($rows));
		$m = $rows[0];
		same(array('Ana', 'ana@example.com', '0721 000 000', 'Hello', 'Is the terrace open?', '/zzcontact', 'new', ''), array($m['name'], $m['email'], $m['phone'], $m['subject'], $m['message'], $m['page'], $m['status'], $m['spam']));
		// the slug is the date and time, never the email
		check(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $m['title']) === 1, $m['title']);
		check(preg_match('/^\d{4}-\d\d-\d\d-\d\d-\d\d(-\d+)?$/', $m['slug']) === 1, $m['slug']);
		same(array('Message: Hello staff@zz.test'), booking_sent($mails));
		same(1, preg_match_all('/^Reply-To: /m', $mails[0]['raw']));
		check(strpos($mails[0]['raw'], "\r\nReply-To: \"Ana\" <ana@example.com>\r\n") !== false, $mails[0]['raw']);
		check(preg_match('/^From: staff@zz\.test\r$/m', $mails[0]['raw']) === 1, 'From stays the site\'s sender');
		check(strpos($mails[0]['text'], "$booking_base/zzcontact") !== false && strpos($mails[0]['text'], "$booking_base/staff") !== false, $mails[0]['text']);
		same(array('contact.received'), array_column($seen, 0));
		same(array('visitor', 'new', '/zzcontact'), array($seen[0][1]['who'], $seen[0][1]['status'], $seen[0][1]['page']));
		check(!array_key_exists('title', $seen[0][1]), 'the payload has the title');
		check(strpos(json_encode($seen), '127.0.0.1') === false, 'an IP in the payload');
		// contact_staff_email, else the sender
		$payload = contact::payload($m, 'visitor');
		$mails = booking_mails(function () use ($payload) {
			with_settings(array('contact_staff_email' => 'desk@zz.test'), function () use ($payload) { (new contact())->staff_mail($payload); });
		});
		same(array('Message: Hello desk@zz.test'), booking_sent($mails));
	});
});

test('contact: a suspected message is stored as spam and not mailed', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		$mails = array();
		$seen = contact_seen(function () use ($booking_base, &$mails) {
			$mails = booking_mails(function () use ($booking_base) {
				list($status, , $location) = contact_post($booking_base, array('email' => 'bot@example.com', 'message' => 'Buy now', 'raster_ts' => ''));
				same(303, $status);
				check(strpos($location, 'done=sent') !== false, 'a bot learns nothing: '.$location);
			});
		});
		$rows = cms_records::find('contactmessage');
		same(1, count($rows));
		same(array('spam', 'no_stamp'), array($rows[0]['status'], $rows[0]['spam']));
		same(array(), booking_sent($mails));
		same(array('contact.flagged'), array_column($seen, 0));
		same('no_stamp', $seen[0][1]['spam']);
	});
});

test('contact: the 6th message from one address is kept as spam, too_many_from_ip', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		booking_mails(function () use ($booking_base) {
			for ($i = 1; $i <= 6; $i++) {
				list($status, $body) = form_post("$booking_base/zzcontact", array('raster_form' => 'contact.form', 'email' => "office$i@example.com", 'message' => "Hello $i"), array('X-Forwarded-For: 203.0.113.60'));
				same(303, $status, "message $i");
			}
		});
		$rows = cms_records::find('contactmessage');
		usort($rows, function ($a, $b) { return $a['id'] - $b['id']; });
		same(6, count($rows));
		same(array('new', 'new', 'new', 'new', 'new', 'spam'), array_column($rows, 'status'));
		same('too_many_from_ip', $rows[5]['spam']);
	});
});

test('contact: header injection is refused and Reply-To is one header', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		$mails = booking_mails(function () use ($booking_base) {
			foreach (array('name' => array("Ana\r\nBcc: x@evil.test", 'name_invalid'), 'subject' => array("Hi\nBcc: x@evil.test", 'subject_invalid'), 'phone' => array("0721\rBcc: x@evil.test", 'phone_invalid')) as $field => $case) {
				list($status, $body) = contact_post($booking_base, array($field => $case[0], 'email' => "$field@example.com", 'message' => 'Hi'));
				same(200, $status, $field);
				check(strpos($body, "ALERT {$case[1]}") !== false, "$field: $body");
			}
			list($status) = contact_post($booking_base, array('email' => "a@b.test\nBcc: c@d.test", 'message' => 'Hi'));
			same(200, $status, 'email');
		});
		same(array(), booking_sent($mails));
		same(array(), cms_records::find('contactmessage'));
		// from any writer
		same(array('email_invalid'), contact_refused(function () { cms_records::create('contactmessage', array('email' => "a@b.test\nBcc: c@d.test", 'message' => 'Hi')); }));
		same(array('email_invalid'), contact_refused(function () { cms_records::create('contactmessage', array('email' => '', 'message' => 'Hi')); }));
		// addresses PHP's filter takes that would carry more than one
		// address, or address syntax, into Reply-To
		$quoted = '"x>,evil@evil.test,<y"@x.com';
		check((bool)filter_var($quoted, FILTER_VALIDATE_EMAIL), 'the filter takes it, so the check must be on top');
		foreach (array($quoted, '"ana"@example.com', 'ana@[192.0.2.1]', 'a b@example.com', 'a(b)@example.com', 'a,b@example.com', 'a;b@example.com', 'a:b@example.com', 'a\\b@example.com', 'a@b@example.com') as $email) {
			same(array('email_invalid'), contact_refused(function () use ($email) { cms_records::create('contactmessage', array('email' => $email, 'message' => 'Hi')); }), $email);
		}
		$mails = booking_mails(function () use ($booking_base, $quoted) {
			list($status, $body) = contact_post($booking_base, array('name' => 'Ana', 'email' => $quoted, 'message' => 'Hi quoted'));
			same(200, $status, 'a quoted local part');
			check(strpos($body, 'ALERT email_invalid') !== false, $body);
		});
		same(array(), booking_sent($mails));
		same(array(), cms_records::find('contactmessage'));
		same(array('name_invalid', 'message_invalid'), contact_refused(function () { cms_records::create('contactmessage', array('name' => "Ana\0", 'email' => 'a@b.test', 'message' => '')); }));
		// quotes, brackets and commas are dropped from the name
		$mails = booking_mails(function () use ($booking_base) {
			same(303, contact_post($booking_base, array('name' => 'Ana "The Boss" <x>, y', 'email' => 'boss@example.com', 'message' => 'Hi there'))[0]);
		});
		same(1, count($mails));
		same(1, preg_match_all('/^Reply-To: /m', $mails[0]['raw']));
		check(strpos($mails[0]['raw'], "\r\nReply-To: \"Ana The Boss x y\" <boss@example.com>\r\n") !== false, $mails[0]['raw']);
		check(stripos($mails[0]['raw'], "\nBcc:") === false, 'a Bcc header');
		same('a@b.test', contact::reply_to('', 'a@b.test'));
		// a name that isn't ASCII is encoded whole, outside the quotes
		$mails = booking_mails(function () {
			with_settings(array('contact_staff_email' => 'desk@zz.test'), function () {
				(new contact())->staff_mail(array('name' => 'Ana Café', 'email' => 'ana@example.com', 'subject' => '', 'message' => 'Hi', 'page' => '/', 'who' => 'visitor'));
			});
		});
		same(1, count($mails));
		check(strpos($mails[0]['raw'], "\r\nReply-To: =?UTF-8?B?".base64_encode('Ana Café')."?= <ana@example.com>\r\n") !== false, $mails[0]['raw']);
		// a group (Ana: x@y; …) or a trailing backslash can't add an address
		spam_clear();
		$mails = booking_mails(function () use ($booking_base) {
			same(303, contact_post($booking_base, array('name' => 'Ana: bad@evil.test; Café', 'email' => 'group@example.com', 'message' => 'Hi group'))[0]);
			same(303, contact_post($booking_base, array('name' => 'Ana\\', 'email' => 'slash@example.com', 'message' => 'Hi slash'))[0]);
		});
		same(2, count($mails));
		foreach (array('group@example.com', 'slash@example.com') as $n => $email) {
			same(1, preg_match_all('/^Reply-To: ([^\r\n]*)\r$/m', $mails[$n]['raw'], $line), $email);
			$reply = $line[1][0];
			check(substr($reply, -strlen("<$email>")) === "<$email>", $reply);
			same(1, substr_count($reply, '@'), 'one address: '.$reply);
		}
		same('"Ana" <slash@example.com>', contact::reply_to('Ana\\', 'slash@example.com'));
	});
});

test('mail: a name that isn\'t plain ASCII is encoded whole, an ASCII one as written', function () use ($maildir) {
	$dir = "$maildir-names";
	@mkdir($dir);
	$was = array(getenv('RASTER_MAIL'), getenv('RASTER_MAIL_FROM'));
	putenv("RASTER_MAIL=log://$dir");
	try {
		putenv('RASTER_MAIL_FROM=Raster Café <hello@cafe.test>');
		check(mail::send('Ana: bad@evil.test; Pârvu <ana@example.com>', 'Hi', '<p>Hi</p>'), (string)mail::$last_error);
		putenv('RASTER_MAIL_FROM=Raster Cafe <hello@cafe.test>');
		check(mail::send('Ana Pop <ana@example.com>', 'Hi', '<p>Hi</p>'), (string)mail::$last_error);
		$files = glob("$dir/*.eml");
		sort($files);
		same(2, count($files));
		$first = file_get_contents($files[0]);
		check(strpos($first, "From: =?UTF-8?B?".base64_encode('Raster Café')."?= <hello@cafe.test>\r\n") !== false, $first);
		check(strpos($first, "\r\nTo: =?UTF-8?B?".base64_encode('Ana: bad@evil.test; Pârvu')."?= <ana@example.com>\r\n") !== false, $first);
		$second = file_get_contents($files[1]);
		check(strpos($second, "From: Raster Cafe <hello@cafe.test>\r\n") !== false && strpos($second, "\r\nTo: Ana Pop <ana@example.com>\r\n") !== false, $second);
		// a long name: words of at most 75 characters, split between characters
		$words = (new ReflectionMethod('mail', 'words'));
		$words->setAccessible(true);
		$long = str_repeat('Café ', 20);
		$encoded = $words->invoke(null, $long);
		// joined by a folding break, so no header line grows past the limit
		foreach (explode("\r\n ", $encoded) as $word) check(strlen($word) <= 75 && preg_match('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $word), $word);
		same($long, mb_decode_mimeheader(str_replace("\r\n ", ' ', $encoded)));
		same(1, count(explode("\r\n ", $words->invoke(null, 'Ana Café'))), 'a short name is one word, unchanged');
		// 200 emoji make a header far past 998 characters unless it is folded
		putenv('RASTER_MAIL_FROM=Raster Cafe <hello@cafe.test>');
		check(mail::send(str_repeat("\u{1F600}", 200).' <ana@example.com>', 'Hi', '<p>Hi</p>'), (string)mail::$last_error);
		$files = glob("$dir/*.eml");
		sort($files);
		$folded = file_get_contents(end($files));
		$head = substr($folded, 0, strpos($folded, "\r\n\r\n"));
		foreach (explode("\r\n", $head) as $line) check(strlen($line) <= 998, 'header line of '.strlen($line).' characters');
		check(strpos($head, "\r\n =?UTF-8?B?") !== false, 'the long name is folded');
	} finally {
		putenv($was[0] === false ? 'RASTER_MAIL' : "RASTER_MAIL={$was[0]}");
		putenv($was[1] === false ? 'RASTER_MAIL_FROM' : "RASTER_MAIL_FROM={$was[1]}");
		array_map('unlink', glob("$dir/*") ?: array());
		@rmdir($dir);
	}
});

test('contact: lengths and a double click', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		list($status, $body) = contact_post($booking_base, array('email' => 'long@example.com', 'message' => str_repeat('a', 5001)));
		same(200, $status);
		check(strpos($body, 'ALERT message_invalid') !== false, $body);
		list($status, $body) = contact_post($booking_base, array('email' => 'long@example.com', 'subject' => str_repeat('s', 201), 'message' => 'Hi'));
		same(200, $status);
		check(strpos($body, 'ALERT subject_invalid') !== false, $body);
		same(array(), cms_records::find('contactmessage'));
		with_settings(array('contact_max_length' => 10), function () {
			same(array('message_invalid'), contact_refused(function () { cms_records::create('contactmessage', array('email' => 'a@b.test', 'message' => 'ăăăăăăăăăăă')); }));
			same(array(), contact_refused(function () { cms_records::create('contactmessage', array('email' => 'a@b.test', 'message' => 'ăăăăăăăăăă')); }), 'ten characters, twenty bytes');
		});
		R::exec('DELETE FROM contactmessagedata');
		spam_clear();
		$mails = booking_mails(function () use ($booking_base) {
			foreach (array(1, 2) as $click) {
				list($status, , $location) = contact_post($booking_base, array('email' => 'twice@example.com', 'message' => 'Do you have oat milk?'));
				same(303, $status, "click $click");
				check(strpos($location, 'done=sent') !== false, $location);
			}
		});
		same(1, count(cms_records::find('contactmessage')));
		same(1, count($mails));
	});
});

test('contact: a frozen database without the spam tables still takes messages', function () {
	sqlite_only('drops a table under a frozen connection');
	contact_site(function () {
		cms_records::ensure(cms_records::info('contactmessage'), true);
		R::exec('DROP TABLE IF EXISTS formhit');
		cms_store::forget();
		validation_spam::$ready = false;
		R::freeze(true);
		$template = template::instance();
		$kept = array($_SERVER, $_POST, $template->current_call, $template->forms);
		try {
			list($done, $logged) = spam_logged(function () use ($template) {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
				$_POST = array('raster_form' => 'contact.form', 'raster_ts' => stamp_for_tests(60), 'email' => 'frozen@example.com', 'message' => 'Still open?');
				$template->current_call = 'contact.form';
				$template->forms['contact.form'] = array('email' => array('type' => 'email', 'required' => true), 'message' => array('required' => true));
				unset(controller::instance()->objects['validation']);
				return with_settings(array('contact_staff_email' => 'desk@zz.test'), function () { return (new contact())->form(); });
			});
		} finally {
			list($_SERVER, $_POST, $template->current_call, $template->forms) = $kept;
			unset(controller::instance()->objects['validation']);
			R::freeze(false);
			cms_store::forget();
			validation_spam::$ready = false;
		}
		same(false, $done, 'the form answered sent');
		$rows = cms_records::find('contactmessage');
		same(1, count($rows));
		same(array('new', ''), array($rows[0]['status'], $rows[0]['spam']));
		check(strpos($logged, 'Raster warning: spam: ') !== false, $logged);
		check(strpos($logged, '203.0.113.9') === false && strpos($logged, 'frozen@example.com') === false, $logged);
	});
});

test('contact actions: done, spam, not_spam', function () {
	contact_site(function () {
		cms_records::ensure(cms_records::info('contactmessage'), true);
		$m = cms_records::create('contactmessage', array('email' => 'a@b.test', 'message' => 'Hi'));
		same(array('new', '', ''), array($m['status'], $m['page'], $m['spam']));
		same('done', cms_records::act('contactmessage', $m['id'], 'done', array(), true)['status']);
		$s = cms_records::act('contactmessage', $m['id'], 'spam', array(), true);
		same(array('spam', 'staff'), array($s['status'], $s['spam']));
		same(array('not_done_spam'), contact_refused(function () use ($m) { cms_records::act('contactmessage', $m['id'], 'done', array(), true); }));
		$mails = array();
		$events = contact_events(function () use ($m, &$mails) {
			$mails = booking_mails(function () use ($m) {
				$n = cms_records::act('contactmessage', $m['id'], 'not_spam', array(), true);
				same(array('new', ''), array($n['status'], $n['spam']));
			});
		});
		same(array(), booking_sent($mails), 'staff are looking at it:');
		same(array('contact.received'), array_column($events, 0));
		same('staff', $events[0][1]['who']);
		same(array('not_spam_invalid'), contact_refused(function () use ($m) { cms_records::act('contactmessage', $m['id'], 'not_spam', array(), true); }));
		// a message longer than a lowered contact_max_length can still be marked done
		with_settings(array('contact_max_length' => 1), function () use ($m) { same('done', cms_records::act('contactmessage', $m['id'], 'done', array(), true)['status']); });
		// a write from the editor or MCP keeps new and no page, whatever a
		// form left behind
		contact::$reasons = array('no_stamp');
		$e = cms_store::save_item('contactmessagedata', 0, array('email' => 'staff@zz.test', 'message' => 'Called about a table'), cms_records::writable(cms_records::info('contactmessage')), 'editor');
		contact::$reasons = array();
		same(array('new', '', ''), array($e['status'], $e['spam'], $e['page']));
	});
});

test('contact: spam older than contact_spam_days is deleted when a message arrives', function () use ($booking_base) {
	contact_site(function () use ($booking_base) {
		cms_records::ensure(cms_records::info('contactmessage'), true);
		$old = cms_records::create('contactmessage', array('email' => 'old@zz.test', 'message' => 'old spam', 'status' => 'spam', 'spam' => 'no_stamp'));
		$recent = cms_records::create('contactmessage', array('email' => 'recent@zz.test', 'message' => 'recent spam', 'status' => 'spam', 'spam' => 'no_stamp'));
		$done = cms_records::create('contactmessage', array('email' => 'done@zz.test', 'message' => 'answered', 'status' => 'done'));
		foreach (array(array($old, 31), array($recent, 29), array($done, 400)) as $aged) {
			R::exec('UPDATE contactmessagedata SET created_at = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - $aged[1] * 86400), $aged[0]['id']));
		}
		booking_mails(function () use ($booking_base) {
			same(303, contact_post($booking_base, array('email' => 'new@example.com', 'message' => 'Hello'))[0]);
		});
		same(null, cms_records::get('contactmessage', $old['id']));
		check(cms_records::get('contactmessage', $recent['id']) !== null, 'spam within 30 days is kept');
		check(cms_records::get('contactmessage', $done['id']) !== null, 'real messages stay');
		same(3, count(cms_records::find('contactmessage')));
	});
});

test('contact: messages and count are for staff only', function () {
	contact_site(function ($theme) {
		cms_records::ensure(cms_records::info('contactmessage'), true);
		cms_records::create('contactmessage', array('name' => 'Ana', 'email' => 'ana@zz.test', 'message' => 'A table for two?'));
		cms_records::create('contactmessage', array('email' => 'bot@zz.test', 'message' => 'Buy now', 'status' => 'spam', 'spam' => 'no_stamp'));
		test_fixture("$theme/zzstaff.html");
		file_put_contents("$theme/zzstaff.html", '<html><head><title>Messages</title></head><body><h1>New: <!-- print.contact.count -->3<!-- /print.contact.count --></h1>'
			.'<ul><!-- render.contact.messages --><li class="message"><!-- print.@href.raster_detail_link --><a href="#"><!-- print.email -->a@b<!-- /print.email --></a><!-- /print.@href.raster_detail_link --></li><!-- /render.contact.messages --></ul></body></html>');
		try {
			$out = contact_render('/zzstaff');
			check(strpos($out, '[exit 0]') !== false && strpos($out, 'New: </h1>') !== false, 'a visitor\'s count: '.$out);
			check(strpos($out, 'class="message"') === false && strpos($out, 'ana@zz.test') === false, 'a visitor sees messages: '.$out);
			$out = contact_render('/zzstaff', 'editor');
			check(strpos($out, 'New: 1</h1>') !== false, 'the count: '.$out);
			check(strpos($out, 'ana@zz.test') !== false && strpos($out, 'bot@zz.test') === false, 'the new ones: '.$out);
			check(preg_match('#href="[^"]*/contactmessage/contactmessage_item/\d{4}-#', $out) === 1, 'the detail link: '.$out);
			check(preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $out, $m) === 1, 'no editor: '.$out);
			$items = array_values(array_filter(json_decode($m[1], true)['marks'], function ($mark) { return isset($mark['kind']) && $mark['kind'] === 'item' && $mark['collection'] === 'contactmessage'; }));
			same(1, count($items), 'messages the editor can edit:');
			same(array('done', 'spam', 'not_spam'), $items[0]['actions']);
			check(in_array('status', $items[0]['readonly'], true), json_encode($items[0]));
		} finally {
			@unlink("$theme/zzstaff.html");
		}
	});
});

test('lint: contact staff page not protected', function () {
	contact_site(function ($theme) {
		check(has_problem((new raster_inspector())->lint_types(), "Visitors make 'contactmessage' records"), 'no staff page yet');
		test_fixture("$theme/zzstaff.html");
		file_put_contents("$theme/zzstaff.html", '<html><head><title>Messages</title></head><body><ul><!-- render.contact.messages(\'status=spam\') --><li><!-- print.email -->a@b<!-- /print.email --></li><!-- /render.contact.messages(\'status=spam\') --></ul></body></html>');
		try {
			check(has_problem((new raster_inspector())->lint_path("$theme/zzstaff.html"), "lists contact messages but isn't protected for editors"), 'unprotected');
			with_settings(array('protected' => array('account' => 'member', 'zzstaff' => 'editor')), function () use ($theme) {
				check(!has_problem((new raster_inspector())->lint_path("$theme/zzstaff.html"), "isn't protected for editors"), 'protected');
			});
			// render.contact.messages lists the type, as render.cms.contactmessage does
			check(!has_problem((new raster_inspector())->lint_types(), "Visitors make 'contactmessage' records"), 'listed by render.contact.messages');
		} finally {
			@unlink("$theme/zzstaff.html");
		}
	});
});

test('lint reports a theme with render.contact.form and no _email/contact_received', function () {
	contact_site(function ($theme) {
		check(!has_problem((new raster_inspector())->lint_path("$theme/zzcontact.html"), 'contact_received'), 'the email is there');
		unlink("$theme/_email/contact_received.html");
		check(has_problem((new raster_inspector())->lint_path("$theme/zzcontact.html"), '_email/contact_received'), 'the email is missing');
		// a suspect post is thanked with sent, so try_again and slow_down never show there
		check(!has_problem(lint_html('<!-- render.contact.form --><form method="post"><input name="email"></form><!-- /render.contact.form -->'), 'can turn a post away as spam'), 'lint asks contact for try_again');
	});
});

// ## Bundled rsvp: places and the waitlist

// The site's events collection with the two fields rsvp reads (places,
// rsvp_until) and render.rsvp.event and render.rsvp.form, with their
// alerts, on the item's page (events_item.html),
// and a page that shows an event by ?event= (zzrsvp.html), so the bundled
// rsvp model counts, with the guest's manage page (rsvp.html) and the six
// emails. eventsdata is made with the mock-up's types. $fn runs with the
// theme's folder; the views, the tables and the listeners are gone after.
function rsvp_site($fn) {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	$region = '<!-- render.rsvp.event --><p class="rsvp">event=<!-- print.event -->1<!-- /print.event --> places=<!-- print.places -->40<!-- /print.places -->'
		.' going=<!-- print.going -->0<!-- /print.going --> left=<!-- print.left -->40<!-- /print.left --> waiting=<!-- print.waiting -->0<!-- /print.waiting -->'
		.' state=<!-- print.state -->open<!-- /print.state --> deadline=<!-- print.deadline -->2026-11-20 18:00<!-- /print.deadline --></p><!-- /render.rsvp.event -->';
	// the reply form, with an alert for each thing it can say
	foreach (array('going', 'waiting', 'noted', 'already_replied', 'closed', 'full', 'too_many', 'people_invalid', 'not_open', 'try_again', 'slow_down') as $alert) {
		$region .= "<!-- print.validation.alert('$alert') --><p>ALERT $alert</p><!-- /print.validation.alert('$alert') -->";
	}
	$region .= '<!-- render.rsvp.form --><form method="post"><input name="name" required><input type="email" name="email" required><input name="phone">'
		.'<input type="radio" name="answer" value="yes"><input type="radio" name="answer" value="no"><input type="radio" name="answer" value="maybe">'
		.'<input type="number" name="people" min="1"><textarea name="notes"></textarea><button>Reply</button></form><!-- /render.rsvp.form -->';
	test_fixture("$theme/events_item.html");
	file_put_contents("$theme/events_item.html", '<html><head><title>Event</title></head><body><!-- render.cms.events --><h1><!-- print.title -->Jazz night<!-- /print.title --></h1>'
		.'<p><!-- print.date -->2026-11-20<!-- /print.date --> at <!-- print.starts -->20:00<!-- /print.starts -->, <!-- print.places -->40<!-- /print.places --> places,'
		.' replies until <!-- print.rsvp_until -->2026-11-20 18:00<!-- /print.rsvp_until --></p><!-- /render.cms.events -->'.$region.'</body></html>');
	test_fixture("$theme/zzrsvp.html");
	file_put_contents("$theme/zzrsvp.html", '<html><head><title>Reply</title></head><body>'.$region.'</body></html>');
	// the guest's manage page, and the emails, each with its name as the subject
	$alerts = '';
	foreach (array('link_invalid', 'closed', 'full_for_more', 'changed') as $alert) {
		$alerts .= "<!-- print.validation.alert('$alert') --><p>ALERT $alert</p><!-- /print.validation.alert('$alert') -->";
	}
	test_fixture("$theme/rsvp.html");
	file_put_contents("$theme/rsvp.html", "<html><head><title>Your reply</title></head><body>$alerts"
		.'<!-- render.rsvp.manage --><p class="reply"><!-- print.name -->Ana<!-- /print.name --> <!-- print.answer -->yes<!-- /print.answer --> <!-- print.people -->2<!-- /print.people -->'
		.' <!-- print.status -->going<!-- /print.status --> <!-- print.event_title -->Jazz night<!-- /print.event_title --> <!-- print.position -->1<!-- /print.position --></p>'
		.'<form method="post"><input type="hidden" name="do" value="change"><input type="radio" name="answer" value="yes"><input type="radio" name="answer" value="no">'
		.'<input type="radio" name="answer" value="maybe"><input type="number" name="people"><button>Change</button></form><!-- /render.rsvp.manage --></body></html>');
	// the staff page: the counts, what needs attention, every event to come
	// with its replies, and one event's replies (?event=) with the card for a
	// phone reply
	$list = "render.cms.rsvp('event=?event&order=status,waiting_since')";
	file_put_contents("$theme/zzrsvpstaff.html", '<html><head><title>Replies</title></head><body>'
		."<h1>waiting=<!-- print.rsvp.count('status=waiting') -->3<!-- /print.rsvp.count('status=waiting') --> attention=<!-- print.rsvp.count('attention') -->1<!-- /print.rsvp.count('attention') --></h1>"
		.'<!-- render.rsvp.attention --><p class="attention"><!-- print.name -->Ana<!-- /print.name -->: <!-- print.attention -->moved<!-- /print.attention --></p><!-- /render.rsvp.attention -->'
		.'<!-- render.rsvp.events --><section class="event"><h2><!-- print.event_title -->Jazz night<!-- /print.event_title --> going=<!-- print.going -->4<!-- /print.going -->'
		.' left=<!-- print.left -->6<!-- /print.left --> waiting=<!-- print.waiting -->0<!-- /print.waiting --></h2><ul><!-- print.replies --><li class="reply"><!-- print.name -->Ana<!-- /print.name -->'
		.' <!-- print.status -->going<!-- /print.status --> <!-- print.position -->1<!-- /print.position --></li><!-- /print.replies --></ul></section><!-- /render.rsvp.events -->'
		.'<form method="get"><select name="event"><!-- render.rsvp.events --><!-- print.@value.event --><option value="">'
		.'<!-- print.event_title -->Jazz night<!-- /print.event_title --></option><!-- /print.@value.event --><!-- /render.rsvp.events --></select><button>Show</button></form>'
		."<!-- $list --><p class=\"phone\"><!-- print.name -->Ana<!-- /print.name --> <!-- print.people -->2<!-- /print.people --></p><!-- /$list --></body></html>");
	$emails = array('rsvp_going', 'rsvp_waiting', 'rsvp_promoted', 'rsvp_noted', 'rsvp_link', 'rsvp_new');
	foreach ($emails as $email) {
		test_fixture("$theme/_email/$email.html");
		file_put_contents("$theme/_email/$email.html", "<html><head><title>$email</title></head><body><p><!-- print.self.name -->Ana<!-- /print.self.name -->:"
			.' <!-- print.self.event_title -->Jazz night<!-- /print.self.event_title --> <!-- print.self.status -->going<!-- /print.self.status --> by <!-- print.self.who -->guest<!-- /print.self.who --></p>'
			.'<p>Position: <!-- print.self.position -->1<!-- /print.self.position -->.</p><p>Manage: <!-- print.self.manage_url -->none<!-- /print.self.manage_url --></p></body></html>');
	}
	controller::forget_models();
	cms_records::forget();
	cms_store::connect();
	cms_types::ensure('eventsdata', array('title' => 'text', 'date' => 'date', 'starts' => 'time', 'places' => 'int', 'rsvp_until' => 'datetime',
		'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
	if (class_exists('rsvp', false)) rsvp::forget();
	// this process booted without a view that uses rsvp, so it binds the
	// listeners system/config/events.php binds for a site that has one
	$listeners = array('cms.item_saved' => 'saved', 'cms.item_deleted' => 'deleted', 'rsvp.replied' => 'replied_mail', 'rsvp.changed' => 'changed_mail', 'rsvp.promoted' => 'promoted_mail');
	foreach ($listeners as $event => $method) event::bind($event)->to('rsvp', $method);
	try {
		return $fn($theme);
	} finally {
		foreach ($listeners as $event => $method) event::unbind($event)->from('rsvp', $method);
		foreach (array('rsvpdata', 'eventsdata') as $table) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $table");
		cms_store::forget();
		@unlink("$theme/events_item.html");
		@unlink("$theme/zzrsvp.html");
		@unlink("$theme/rsvp.html");
		@unlink("$theme/zzrsvpstaff.html");
		foreach ($emails as $email) @unlink("$theme/_email/$email.html");
		controller::forget_models();
		cms_records::forget();
		if (class_exists('rsvp', false)) {
			rsvp::forget();
			rsvp::$posting = null;
			rsvp::$freed = array();
			rsvp::$changed = array();
			rsvp::$links = array();
			rsvp::$made = array();
		}
	}
}
// an event as an editor saves it in the page: ten days ahead at 20:00,
// 10 places, unless $values say otherwise
function rsvp_event($values = array()) {
	static $n = 0;
	$n++;
	$values = array_merge(array('title' => "Night $n", 'date' => date('Y-m-d', strtotime('+10 days')), 'starts' => '20:00', 'places' => 10), $values);
	return cms_store::save_item('eventsdata', 0, $values, array_keys($values), 'editor');
}
// A reply, each guest with an address of their own: as model code writes
// it ('model'), as staff in the page or over MCP ('editor'), or as the form
// stores it ('visitor', for the page's event: rsvp::$posting, else the
// event the values name)
function rsvp_reply($values, $who = 'model') {
	static $n = 0;
	$n++;
	$values = array_merge(array('name' => "Guest $n", 'email' => "guest$n@example.test"), $values);
	if ($who === 'model') return cms_records::create('rsvp', $values);
	$posting = rsvp::$posting;
	if ($who === 'visitor' && $posting === null && isset($values['event'])) rsvp::$posting = (int)$values['event'];
	try {
		return cms_store::save_item('rsvpdata', 0, $values, cms_records::writable(cms_records::info('rsvp')), $who);
	} finally {
		rsvp::$posting = $posting;
	}
}
// a change as the guest's own link makes it (the model's write, for a guest)
function rsvp_as_guest($fn) {
	$acting = rsvp::$acting;
	rsvp::$acting = 'guest';
	try {
		return $fn();
	} finally {
		rsvp::$acting = $acting;
	}
}
function rsvp_status($id) {
	$reply = cms_records::get('rsvp', $id);
	return $reply ? $reply['status'] : null;
}
// the rsvp events this process sent while $fn ran, as array(event, payload)
function rsvp_events($fn) {
	global $booking_seen;
	$booking_seen = array();
	$names = array('rsvp.replied', 'rsvp.changed', 'rsvp.promoted');
	foreach ($names as $name) event::bind($name)->to(null, 'test_booking_seen');
	try {
		$fn();
	} finally {
		foreach ($names as $name) event::unbind($name)->from(null, 'test_booking_seen');
	}
	return $booking_seen;
}
// the files a page's render includes, from a process of its own
function rsvp_loads($url, $env) {
	global $root;
	$dir = sys_get_temp_dir().'/raster-rsvp-loads-'.getmypid();
	@mkdir($dir);
	file_put_contents("$dir/prepend.php", '<?php register_shutdown_function(function () { file_put_contents('.var_export("$dir/out.json", true).', json_encode(get_included_files())); });');
	shell_exec("$env ".php_cmd().' -d auto_prepend_file='.escapeshellarg("$dir/prepend.php").' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).' 2>&1');
	$included = json_decode((string)@file_get_contents("$dir/out.json"), true);
	@unlink("$dir/out.json");
	@unlink("$dir/prepend.php");
	@unlink("$dir/db.sqlite");
	@rmdir($dir);
	check(is_array($included), "no report from the render of $url");
	return $included;
}
// render.rsvp.event's line on a page, as a request renders it
function rsvp_line($url) {
	$page = booking_render($url);
	check(strpos($page, '[exit 0]') !== false, "$url: $page");
	return preg_match('#<p class="rsvp">(.*?)</p>#', $page, $m) ? $m[1] : null;
}

test('rsvp types count only where views use the model', function () use ($db) {
	controller::forget_models();
	cms_records::forget();
	check(cms_records::info('rsvp') === null, 'rsvp is declared without a view that uses rsvp');
	$dir = sys_get_temp_dir().'/raster-rsvp-loads-'.getmypid();
	@mkdir($dir);
	$included = rsvp_loads('/about', 'RASTER_ENV=production RASTER_DB='.escapeshellarg(test_db("$dir/db.sqlite")));
	same(array(), array_values(preg_grep('~/system/models/rsvp/~', $included)), 'rsvp files loaded:');
	rsvp_site(function () use ($db) {
		$info = cms_records::info('rsvp');
		same('rsvp', $info['model']);
		same(array('name', 'email', 'phone', 'event', 'answer', 'people', 'notes', 'status', 'waiting_since', 'changed_at', 'event_at', 'token_hashes', 'link_sent_at'), array_keys($info['fields']));
		same(array('visitor', false, true, true, true), array($info['create'], $info['public'], $info['owner'], $info['staff_add'], $info['spam']));
		same(array('status', 'waiting_since', 'changed_at', 'event_at'), $info['readonly']);
		same(array('token_hashes', 'link_sent_at'), $info['hidden']);
		same(array('promote' => 'editor'), $info['actions']);
		same(array('datetime', 'datetime', 'datetime', 'int', 'int'), array($info['types']['waiting_since'], $info['types']['event_at'], $info['types']['link_sent_at'], $info['types']['event'], $info['types']['people']));
		same(true, rsvp::ready());
		$event = rsvp_event(array('title' => 'Jazz night'));
		// in production: in development the page's template check reads what
		// every bundled model requires
		$included = rsvp_loads('/events/events_item/'.$event['slug'], 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db));
		check(preg_grep('~/system/models/rsvp/rsvp\.php$~', $included), 'rsvp was not used: '.json_encode($included));
		same(array(), array_values(preg_grep('~/system/models/booking/~', $included)), 'booking files loaded:');
	});
});

test('a site without rsvp binds no rsvp listener and loads no rsvp file when it saves a record', function () {
	$code = 'cms_store::save_item("zzthing", 0, array("title" => "x"), array("title"), "editor");'
		.' echo json_encode(array("bound" => array_values(preg_grep("/^rsvp\\./", call_user_func_array("array_merge", array_values(event::bindings())))),'
		.' "loaded" => array_values(preg_grep("~/models/rsvp/~", get_included_files()))));';
	$out = json_decode(race_php($code, 1)[0], true);
	check(is_array($out), 'no report from the save');
	same(array(), $out['bound'], 'rsvp listeners bound:');
	same(array(), $out['loaded'], 'rsvp files loaded:');
	R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzthing');
});

test('rsvp: a yes takes places, no and maybe take none', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 10));
		$yes = rsvp_reply(array('event' => $event['id'], 'answer' => 'yes', 'people' => 4));
		same('going', $yes['status']);
		$no = rsvp_reply(array('event' => $event['id'], 'answer' => 'no', 'people' => 3));
		$maybe = rsvp_reply(array('event' => $event['id'], 'answer' => 'maybe', 'people' => 2));
		same(array('no', ''), array($no['status'], (string)$no['waiting_since']));
		same(array('maybe', ''), array($maybe['status'], (string)$maybe['waiting_since']));
		// the reply being written is left out
		same(4, rsvp::going($event['id'], 0));
		same(0, rsvp::going($event['id'], $yes['id']));
		check((string)$yes['changed_at'] !== '', 'changed_at');
		same(date('Y-m-d H:i:s', strtotime($event['date'].' 20:00')), (string)$yes['event_at']);
		// empty and 0 are no limit
		foreach (array('', 0) as $places) {
			$open = rsvp_event(array('places' => $places));
			same('going', rsvp_reply(array('event' => $open['id'], 'answer' => 'yes', 'people' => 50), 'editor')['status'], "places '$places':");
		}
	});
});

test('rsvp: a yes that doesn\'t fit waits, or is full', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 4));
		rsvp_reply(array('event' => $event['id'], 'people' => 3));
		$waiting = rsvp_reply(array('event' => $event['id'], 'people' => 2), 'visitor');
		same('waiting', $waiting['status']);
		check((string)$waiting['waiting_since'] !== '', 'waiting_since');
		with_settings(array('rsvp_waitlist' => false), function () use ($event) {
			same(array('full'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'people' => 2), 'visitor'); }));
			same(array('full'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'people' => 2), 'editor'); }));
		});
		// a party that could never fit, whoever writes it
		same(array('too_many'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'people' => 5), 'visitor'); }));
		same(array('too_many'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'people' => 5), 'editor'); }));
		// rsvp_max_people (6) is for guests only
		$open = rsvp_event(array('places' => 0));
		same(array('too_many'), booking_problems(function () use ($open) { rsvp_reply(array('event' => $open['id'], 'people' => 7), 'visitor'); }));
		same('going', rsvp_reply(array('event' => $open['id'], 'people' => 7), 'editor')['status']);
	});
});

test('rsvp: the rules, for guests and staff', function () {
	rsvp_site(function () {
		$event = rsvp_event();
		$guest = function ($values) { return booking_problems(function () use ($values) { rsvp_reply($values, 'visitor'); }); };
		same(array('not_open'), $guest(array('answer' => 'yes')));
		$draft = rsvp_event(array('enabled' => 0));
		$scheduled = rsvp_event(array('published_at' => date('Y-m-d H:i', strtotime('+1 day'))));
		$undated = rsvp_event(array('date' => ''));
		foreach (array($draft, $scheduled, $undated) as $closed) same(array('not_open'), $guest(array('event' => $closed['id'])), $closed['title']);
		same(array('not_open'), booking_problems(function () use ($undated) { rsvp_reply(array('event' => $undated['id']), 'editor'); }), 'staff, no date:');
		same(array('name_invalid'), $guest(array('event' => $event['id'], 'name' => '')));
		same(array('name_invalid'), $guest(array('event' => $event['id'], 'name' => "Ana\nBcc: x@evil.test")));
		same(array('email_invalid'), $guest(array('event' => $event['id'], 'email' => '')));
		same(array('email_invalid'), $guest(array('event' => $event['id'], 'email' => 'not an address')));
		same('', (string)rsvp_reply(array('event' => $event['id'], 'name' => 'Phone reply', 'email' => ''), 'editor')['email']);
		same(array('email_invalid'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'email' => 'nope'), 'editor'); }));
		same(array('answer_invalid'), $guest(array('event' => $event['id'], 'answer' => 'perhaps')));
		same(array('people_invalid'), $guest(array('event' => $event['id'], 'answer' => 'yes', 'people' => 0)));
		same(array(), $guest(array('event' => $event['id'], 'answer' => 'no', 'people' => 0)), 'people on a no:');
		rsvp_reply(array('event' => $event['id'], 'email' => 'ana@example.com'), 'visitor');
		same(array('already_replied'), $guest(array('event' => $event['id'], 'email' => 'ANA@example.com')));
		same(array('already_replied'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'email' => 'Ana@Example.com'), 'editor'); }));
		// the same address for another event is another reply
		same(array(), $guest(array('event' => rsvp_event()['id'], 'email' => 'ana@example.com')));
		// a visitor never chooses the event: the page's is written
		$other = rsvp_event();
		rsvp::$posting = $event['id'];
		$reply = rsvp_reply(array('event' => $other['id'], 'email' => 'mihai@example.com'), 'visitor');
		rsvp::$posting = null;
		same($event['id'], $reply['event']);
	});
});

test('rsvp: the deadline takes no places but takes them back', function () {
	rsvp_site(function () {
		$tomorrow = date('Y-m-d', strtotime('+1 day'));
		$event = rsvp_event(array('date' => $tomorrow, 'starts' => '20:00', 'rsvp_until' => date('Y-m-d H:i', time() - 3600)));
		same(array('closed'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id']), 'visitor'); }));
		$id = rsvp_reply(array('event' => $event['id'], 'people' => 3))['id'];
		rsvp_as_guest(function () use ($id) {
			same(2, cms_records::update('rsvp', $id, array('people' => 2))['people']);
			same(array('closed'), booking_problems(function () use ($id) { cms_records::update('rsvp', $id, array('people' => 3)); }));
			same('no', cms_records::update('rsvp', $id, array('answer' => 'no'))['status']);
			same(array('closed'), booking_problems(function () use ($id) { cms_records::update('rsvp', $id, array('answer' => 'yes')); }));
		});
		same('going', rsvp_reply(array('event' => $event['id']), 'editor')['status'], 'staff after the deadline:');
		// rsvp_closes minutes before the start, for an event without rsvp_until
		with_settings(array('rsvp_closes' => 120), function () {
			$soon = time() + 90 * 60;
			$event = rsvp_event(array('date' => date('Y-m-d', $soon), 'starts' => date('H:i', $soon)));
			same(array('closed'), booking_problems(function () use ($event) { rsvp_reply(array('event' => $event['id']), 'visitor'); }));
			same(strtotime(date('Y-m-d H:i', $soon)) - 7200, rsvp::item($event['id'])['deadline']);
		});
		// no start time: 00:00 of its date; a deadline after the start is the start
		$day = date('Y-m-d', strtotime('+3 days'));
		$event = rsvp::item(rsvp_event(array('date' => $day, 'starts' => ''))['id']);
		same(array('00:00', strtotime("$day 00:00"), strtotime("$day 00:00")), array($event['starts'], $event['starts_at'], $event['deadline']));
		$event = rsvp::item(rsvp_event(array('date' => $day, 'starts' => '20:00', 'rsvp_until' => "$day 21:00"))['id']);
		same(strtotime("$day 20:00"), $event['deadline']);
		$event = rsvp::item(rsvp_event(array('date' => $day, 'starts' => '20:00', 'rsvp_until' => "$day 18:00"))['id']);
		same(strtotime("$day 18:00"), $event['deadline']);
		// once it has started a guest changes nothing
		$started = rsvp_event(array('date' => date('Y-m-d', time() - 3600), 'starts' => date('H:i', time() - 3600)));
		$id = rsvp_reply(array('event' => $started['id'], 'people' => 2))['id'];
		rsvp_as_guest(function () use ($id) {
			same(array('closed'), booking_problems(function () use ($id) { cms_records::update('rsvp', $id, array('answer' => 'no')); }));
		});
	});
});

test('rsvp: two yeses for the last place at once', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 10));
		// the table is made before the race: on MySQL a new table commits
		rsvp_reply(array('event' => $event['id'], 'people' => 9));
		$out = race_php('rsvp::$posting = '.(int)$event['id'].'; try { $r = cms_store::save_item("rsvpdata", 0, array("name" => "Racer $n", "email" => "racer$n@example.test", "answer" => "yes", "people" => 1),'
			.' cms_records::writable(cms_records::info("rsvp")), "visitor"); echo $r["status"]; } catch (cms_refused $e) { echo implode(",", $e->problems); }', 6);
		same(1, count(array_keys($out, 'going')), 'going: '.json_encode($out));
		same(5, count(array_keys($out, 'waiting')), 'waiting: '.json_encode($out));
		reconnect();
		same(10, rsvp::going($event['id'], 0));
	});
});

test('rsvp: promotion moves every party that fits, oldest first, once', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 10));
		$e = $event['id'];
		$going = array();
		foreach (array(3, 3, 4) as $people) $going[] = rsvp_reply(array('event' => $e, 'people' => $people))['id'];
		$waiting = array();
		foreach (array(4, 2, 1) as $people) $waiting[$people] = rsvp_reply(array('event' => $e, 'people' => $people))['id'];
		same(array('waiting', 'waiting', 'waiting'), array_map('rsvp_status', array_values($waiting)));
		// a going party of 3 says no, as staff code writes it: with 3 free the
		// 4 is skipped, and the 2 and the 1 move in
		$seen = rsvp_events(function () use ($going) {
			cms_records::update('rsvp', $going[0], array('answer' => 'no'));
		});
		same(array('going', 'going', 'waiting'), array(rsvp_status($waiting[2]), rsvp_status($waiting[1]), rsvp_status($waiting[4])));
		same(array('rsvp.changed', 'rsvp.promoted', 'rsvp.promoted'), array_column($seen, 0));
		same(array($waiting[2], $waiting[1]), array($seen[1][1]['id'], $seen[2][1]['id']));
		same(array('waitlist', 'waitlist'), array($seen[1][1]['who'], $seen[2][1]['who']));
		same(10, rsvp::going($e, 0));
		same(array($waiting[4]), array_map('intval', array_column(database::instance('rsvp')->queue($e), 'id')));
		// again: nobody moves, nothing is sent
		$seen = rsvp_events(function () use ($e, &$moved) { $moved = rsvp::promote_waiting($e); });
		same(array(), $moved);
		same(array(), $seen);
	});
});

test('rsvp: places freed by a no, a smaller party, a delete, a move', function () {
	rsvp_site(function () {
		// places 4, a going party of 4, and a waiting couple that fits only
		// once two places are free
		$setup = function () {
			$event = rsvp_event(array('places' => 4));
			$going = rsvp_reply(array('event' => $event['id'], 'people' => 4))['id'];
			$waiting = rsvp_reply(array('event' => $event['id'], 'people' => 2))['id'];
			same('waiting', rsvp_status($waiting));
			return array($event['id'], $going, $waiting);
		};
		list($e, $going, $waiting) = $setup();
		cms_records::update('rsvp', $going, array('answer' => 'no'));
		same('going', rsvp_status($waiting), 'after a no:');
		list($e, $going, $waiting) = $setup();
		cms_records::update('rsvp', $going, array('people' => 3));
		same('waiting', rsvp_status($waiting), 'one place free:');
		cms_store::save_item('rsvpdata', $going, array('people' => 2), array('people'), 'editor');
		same('going', rsvp_status($waiting), 'after a smaller party:');
		list($e, $going, $waiting) = $setup();
		cms_records::delete('rsvp', $going);
		same('going', rsvp_status($waiting), 'after a delete:');
		list($e, $going, $waiting) = $setup();
		cms_store::save_item('rsvpdata', $going, array('event' => rsvp_event()['id']), array('event'), 'editor');
		same('going', rsvp_status($waiting), 'after a move:');
		// a waiting party that shrinks keeps its place in the queue, and moves
		// in once it fits
		$event = rsvp_event(array('places' => 4));
		rsvp_reply(array('event' => $event['id'], 'people' => 3));
		$waiting = rsvp_reply(array('event' => $event['id'], 'people' => 4))['id'];
		R::exec('UPDATE rsvpdata SET waiting_since = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - 600), $waiting));
		$since = (string)cms_records::get('rsvp', $waiting)['waiting_since'];
		$reply = cms_records::update('rsvp', $waiting, array('people' => 2));
		same(array('waiting', $since), array($reply['status'], (string)$reply['waiting_since']));
		cms_records::update('rsvp', $waiting, array('people' => 1));
		same('going', rsvp_status($waiting), 'a party that shrank to fit:');
		// a no, then a yes again, joins at the end
		$event = rsvp_event(array('places' => 2));
		rsvp_reply(array('event' => $event['id'], 'people' => 2));
		$first = rsvp_reply(array('event' => $event['id']))['id'];
		$second = rsvp_reply(array('event' => $event['id']))['id'];
		R::exec('UPDATE rsvpdata SET waiting_since = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - 600), $first));
		R::exec('UPDATE rsvpdata SET waiting_since = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - 300), $second));
		$reply = cms_records::update('rsvp', $first, array('answer' => 'no'));
		same(array('no', ''), array($reply['status'], (string)$reply['waiting_since']));
		same('waiting', cms_records::update('rsvp', $first, array('answer' => 'yes'))['status']);
		same(array($second, $first), array_map('intval', array_column(database::instance('rsvp')->queue($event['id']), 'id')));
	});
});

test('rsvp: places raised in the page promotes; lowered moves nobody', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 10));
		rsvp_reply(array('event' => $event['id'], 'people' => 10));
		$waiting = array(rsvp_reply(array('event' => $event['id'], 'people' => 1))['id'], rsvp_reply(array('event' => $event['id'], 'people' => 1))['id']);
		$seen = rsvp_events(function () use ($event) {
			cms_store::save_item('eventsdata', $event['id'], array('places' => 12), array('places'), 'editor');
		});
		same(array('going', 'going'), array_map('rsvp_status', $waiting));
		same(array('rsvp.promoted', 'rsvp.promoted'), array_column($seen, 0));
		// under the people going: nobody moves, nothing is sent
		$seen = rsvp_events(function () use ($event) {
			cms_store::save_item('eventsdata', $event['id'], array('places' => 2), array('places'), 'editor');
		});
		same(array(), $seen);
		same(array('going'), array_values(array_unique(array_column(cms_records::find('rsvp', array('event' => $event['id'])), 'status'))));
		same(12, rsvp::going($event['id'], 0));
	});
});

test('rsvp: nothing promotes from the deadline; promote goes out of turn', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('date' => date('Y-m-d', strtotime('+1 day')), 'places' => 2, 'rsvp_until' => date('Y-m-d H:i', time() - 3600)));
		$going = rsvp_reply(array('event' => $event['id'], 'people' => 2))['id'];
		$waiting = rsvp_reply(array('event' => $event['id'], 'people' => 1))['id'];
		$big = rsvp_reply(array('event' => $event['id'], 'people' => 2))['id'];
		cms_records::update('rsvp', $going, array('answer' => 'no'));
		same(array('waiting', 'waiting'), array(rsvp_status($waiting), rsvp_status($big)));
		same(array(), rsvp::promote_waiting($event['id']));
		// staff move one in themselves, out of turn and after the deadline
		$seen = rsvp_events(function () use ($waiting) {
			same('going', cms_records::act('rsvp', $waiting, 'promote', array(), true)['status']);
		});
		same(array('rsvp.promoted'), array_column($seen, 0));
		same('staff', $seen[0][1]['who']);
		same(array('not_enough_places'), booking_problems(function () use ($big) { cms_records::act('rsvp', $big, 'promote', array(), true); }));
		same('waiting', rsvp_status($big));
		same(array('not_waiting'), booking_problems(function () use ($waiting) { cms_records::act('rsvp', $waiting, 'promote', array(), true); }));
	});
});

test('rsvp: a going guest is never moved out', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 4));
		rsvp_reply(array('event' => $event['id'], 'people' => 1));
		$id = rsvp_reply(array('event' => $event['id'], 'people' => 2), 'visitor')['id'];
		// 1 + 4 doesn't fit: the guest keeps the 2 places they have
		rsvp_as_guest(function () use ($id) {
			same(array('full_for_more'), booking_problems(function () use ($id) { cms_records::update('rsvp', $id, array('people' => 4)); }));
		});
		same(array('full_for_more'), booking_problems(function () use ($id) { cms_store::save_item('rsvpdata', $id, array('people' => 4), array('people'), 'editor'); }), 'staff:');
		$reply = cms_records::get('rsvp', $id);
		same(array('going', 2), array($reply['status'], $reply['people']));
		cms_store::save_item('eventsdata', $event['id'], array('places' => 2), array('places'), 'editor');
		same('going', rsvp_status($id));
	});
});

test('rsvp: a party already in may shrink or change its email under lowered places', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 6));
		$id = rsvp_reply(array('event' => $event['id'], 'people' => 4), 'visitor')['id'];
		cms_store::save_item('eventsdata', $event['id'], array('places' => 2), array('places'), 'editor');
		rsvp_as_guest(function () use ($id) {
			same(array('going', 3), array(cms_records::update('rsvp', $id, array('people' => 3))['status'], cms_records::get('rsvp', $id)['people']));
		});
		same('fixed@example.test', cms_store::save_item('rsvpdata', $id, array('email' => 'fixed@example.test'), array('email'), 'editor')['email']);
		// staff's party over rsvp_max_people, and a guest's link shrinking it
		$open = rsvp_event(array('places' => 0));
		$big = rsvp_reply(array('event' => $open['id'], 'people' => 9), 'editor')['id'];
		rsvp_as_guest(function () use ($big) {
			same(8, cms_records::update('rsvp', $big, array('people' => 8))['people']);
			same(array('too_many'), booking_problems(function () use ($big) { cms_records::update('rsvp', $big, array('people' => 9)); }));
		});
	});
});

test('rsvp: a refused write frees no places', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 4));
		$id = rsvp_reply(array('event' => $event['id'], 'people' => 4))['id'];
		// a smaller party with a bad email: refused, and nothing is noted
		same(array('email_invalid'), booking_problems(function () use ($id) { cms_records::update('rsvp', $id, array('people' => 2, 'email' => 'nope')); }));
		same(array(), rsvp::$freed);
		same(array(4, 'going'), array(cms_records::get('rsvp', $id)['people'], rsvp_status($id)));
	});
});

test('rsvp: other edits always go through', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 4));
		$id = rsvp_reply(array('event' => $event['id'], 'people' => 4))['id'];
		cms_store::save_item('eventsdata', $event['id'], array('places' => 2, 'enabled' => 0), array('places', 'enabled'), 'editor');
		$reply = cms_store::save_item('rsvpdata', $id, array('phone' => '0721 000 000', 'notes' => 'phoned them'), array('phone', 'notes'), 'editor');
		same(array('0721 000 000', 'phoned them', 'going', 4), array($reply['phone'], $reply['notes'], $reply['status'], $reply['people']));
		// and when the event is gone
		cms_store::delete_item('eventsdata', $event['id']);
		same('call back', cms_store::save_item('rsvpdata', $id, array('notes' => 'call back'), array('notes'), 'editor')['notes']);
	});
});

test('rsvp events wait for the commit, with no hidden fields', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('title' => 'Jazz night', 'places' => 2));
		// the table is made before the transaction: on MySQL a new table
		// commits, so the reply it was made for could not be rolled back
		cms_records::ensure(cms_records::info('rsvp'));
		$seen = rsvp_events(function () use ($event) {
			try {
				cms_records::transaction(function () use ($event) {
					rsvp_reply(array('event' => $event['id']), 'editor');
					cms_records::refuse('zz_stop');
				});
			} catch (cms_refused $e) {
			}
		});
		same(array(), $seen, 'a rolled back reply:');
		same(0, count(cms_records::find('rsvp')));
		$seen = rsvp_events(function () use ($event, &$id, &$waiting) {
			$id = rsvp_reply(array('event' => $event['id'], 'people' => 2), 'editor')['id'];
			$waiting = rsvp_reply(array('event' => $event['id'], 'people' => 1), 'editor')['id'];
			cms_store::save_item('rsvpdata', $id, array('people' => 1), array('people'), 'editor');
			// a note changes nothing the events are about
			cms_store::save_item('rsvpdata', $id, array('notes' => 'by the window'), array('notes'), 'editor');
		});
		same(array('rsvp.replied', 'rsvp.replied', 'rsvp.changed', 'rsvp.promoted'), array_column($seen, 0));
		foreach ($seen as $one) {
			check(!array_key_exists('token_hashes', $one[1]) && !array_key_exists('link_sent_at', $one[1]), $one[0].' has hidden fields: '.json_encode($one[1]));
			same(array('Jazz night', $event['date'], '20:00'), array($one[1]['event_title'], $one[1]['event_date'], $one[1]['event_starts']), $one[0]);
		}
		same(array('staff', 'staff', 'waitlist'), array($seen[0][1]['who'], $seen[2][1]['who'], $seen[3][1]['who']));
		same(array('going', 2, 1), array($seen[2][1]['was'], $seen[2][1]['was_people'], $seen[2][1]['people']));
		same($waiting, $seen[3][1]['id']);
	});
});

test('render.rsvp.event: places, going, left, state', function () use ($db) {
	rsvp_site(function () use ($db) {
		$event = rsvp_event(array('title' => 'Jazz night', 'places' => 10, 'rsvp_until' => date('Y-m-d', strtotime('+10 days')).' 18:00'));
		rsvp_reply(array('event' => $event['id'], 'people' => 4));
		rsvp_reply(array('event' => $event['id'], 'answer' => 'maybe', 'people' => 3));
		$line = 'event='.$event['id'].' places=10 going=4 left=6 waiting=0 state=open deadline='.$event['date'].' 18:00';
		same($line, rsvp_line('/events/events_item/'.$event['slug']));
		same($line, rsvp_line('/zzrsvp?event='.$event['id']));
		$full = rsvp_event(array('places' => 2));
		rsvp_reply(array('event' => $full['id'], 'people' => 2));
		rsvp_reply(array('event' => $full['id'], 'people' => 1));
		same('event='.$full['id'].' places=2 going=2 left=0 waiting=1 state=full deadline='.$full['date'].' 20:00', rsvp_line('/zzrsvp?event='.$full['id']));
		$closed = rsvp_event(array('date' => date('Y-m-d', strtotime('+1 day')), 'rsvp_until' => date('Y-m-d H:i', time() - 60)));
		check(strpos(rsvp_line('/zzrsvp?event='.$closed['id']), 'state=closed') !== false, 'closed');
		$over = rsvp_event(array('date' => date('Y-m-d', time() - 3600), 'starts' => date('H:i', time() - 3600)));
		check(strpos(rsvp_line('/zzrsvp?event='.$over['id']), 'state=over') !== false, 'over');
		$open = rsvp_event(array('places' => ''));
		same('event='.$open['id'].' places= going=0 left= waiting=0 state=open deadline='.$open['date'].' 20:00', rsvp_line('/zzrsvp?event='.$open['id']));
		// no event, or one visitors can't see: no row
		$draft = rsvp_event(array('enabled' => 0));
		foreach (array('/zzrsvp', '/zzrsvp?event=999999', '/zzrsvp?event='.$draft['id'], '/zzrsvp?event=x') as $url) same(null, rsvp_line($url), $url);
		// never page-cached
		cache_empty();
		cache_server($db, function ($get) use ($event) {
			foreach (array(1, 2) as $time) {
				list($status, $body) = $get('/events/events_item/'.$event['slug']);
				same('200 not cached', $status);
				check(strpos($body, 'left=6') !== false, $body);
			}
		});
	});
});

test('a site rsvp model or type keeps the name', function () {
	$app = APPBASE.config::get('models_path', 'models');
	rsvp_site(function () use ($app) {
		// the site's own rsvp model, in a process of its own: this one has
		// the bundled class already
		temp_model("$app/rsvp", array('rsvp.php' => "<?php\nclass rsvp\n{\n\tstatic function types() {\n\t\treturn array('rsvp' => array('fields' => array('name' => '')));\n\t}\n}\n"));
		try {
			$out = race_php('$b = event::bindings(); echo json_encode(array(controller::model("rsvp")["bundled"], array_keys(cms_records::info("rsvp")["fields"]),'
				.' array_merge(isset($b["cms.item_saved"]) ? $b["cms.item_saved"] : array(), isset($b["cms.item_deleted"]) ? $b["cms.item_deleted"] : array())));', 1);
		} finally {
			remove_dir("$app/rsvp");
			controller::forget_models();
		}
		$out = json_decode($out[0], true);
		check(is_array($out), 'the process said: '.json_encode($out));
		same(false, $out[0], 'bundled:');
		same(array('name'), $out[1]);
		same(array(), array_values(preg_grep('/^rsvp\./', $out[2])), 'rsvp listeners bound:');
		// a type named rsvp in another model
		temp_model("$app/zzparty", array('zzparty.php' => "<?php\nclass zzparty\n{\n\tstatic function types() {\n\t\treturn array('rsvp' => array('fields' => array('name' => '', 'guests' => 0)));\n\t}\n}\n"));
		try {
			controller::forget_models();
			cms_records::forget();
			same('zzparty', cms_records::info('rsvp')['model']);
			same(false, rsvp::ready());
			check(has_problem((new raster_inspector())->lint_models(), "zzparty declares a type 'rsvp'"), 'lint warns about the type');
			same(array(), (new rsvp())->event());
		} finally {
			remove_dir("$app/zzparty");
			controller::forget_models();
			cms_records::forget();
		}
	});
});

// ## Bundled rsvp: the guest's link

// the guest's manage page as a request in this process, as booking_manage()
// asks booking's: the rows and the alerts raised
function rsvp_manage($get, $post = null) {
	$saved = array($_GET, $_POST, isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
	$_GET = $get;
	$_POST = $post === null ? array() : $post + array('raster_form' => 'rsvp.manage');
	$_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
	$template = template::instance();
	$call = $template->current_call;
	$template->current_call = 'rsvp.manage';
	$raised = new ReflectionProperty('validation', 'raised');
	$raised->setAccessible(true);
	$raised->setValue(validation::get(), array());
	rsvp::forget();
	try {
		$rows = (new rsvp())->manage();
		return array($rows, array_keys($raised->getValue(validation::get())));
	} finally {
		list($_GET, $_POST, $_SERVER['REQUEST_METHOD']) = $saved;
		$template->current_call = $call;
		$raised->setValue(validation::get(), array());
	}
}
// a reply staff add in the page, and the raw token of the guest's email:
// array(id, token, mails)
function rsvp_linked($values) {
	$mails = booking_mails(function () use ($values, &$id) { $id = (int)rsvp_reply($values, 'editor')['id']; });
	return array($id, $mails ? booking_token($mails[0]) : null, $mails);
}
// the place in the queue an email says
function rsvp_position($mail) {
	return preg_match('/Position: (\d*)\./', $mail['text'], $m) ? $m[1] : null;
}

test('rsvp: every guest email carries a fresh link; only its hash is kept', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('title' => 'Jazz night'));
		// staff added it: no rsvp_new, even with an address for staff
		list($id, $raw, $mails) = with_settings(array('rsvp_staff_email' => 'staff@zz.test'), function () use ($event) {
			return rsvp_linked(array('event' => $event['id'], 'email' => 'link@example.test', 'people' => 2));
		});
		same(array('rsvp_going link@example.test'), booking_sent($mails));
		check($raw !== null, 'no link: '.$mails[0]['text']);
		check(strpos($mails[0]['text'], "/rsvp?token=$raw") !== false && strpos($mails[0]['text'], 'Jazz night') !== false, $mails[0]['text']);
		same('', rsvp_position($mails[0]), 'a going reply has no place in the queue:');
		$r = cms_records::get('rsvp', $id);
		same(array(hash('sha256', $raw)), $r['token_hashes']);
		check(strpos(json_encode($r), $raw) === false, 'the raw token is stored');
		same($id, (int)rsvp::by_token($raw)['id']);
		same(array(), rsvp::$links, 'a link outlives its email:');
		// a phone reply without an address: nothing sent, no link
		$mails = booking_mails(function () use ($event, &$quiet) { $quiet = rsvp_reply(array('event' => $event['id'], 'name' => 'Phone reply', 'email' => ''), 'editor')['id']; });
		same(array(), booking_sent($mails));
		same(array(), cms_records::get('rsvp', $quiet)['token_hashes']);
		same(array(), rsvp::issue(cms_records::get('rsvp', $quiet)));
	});
});

test('rsvp: links sent to an address stop working when staff change it', function () {
	rsvp_site(function () {
		$event = rsvp_event();
		list($id, $raw) = rsvp_linked(array('event' => $event['id'], 'email' => 'typo@example.test'));
		same($id, (int)rsvp::by_token($raw)['id']);
		cms_records::update('rsvp', $id, array('notes' => 'Phoned them'));
		same($id, (int)rsvp::by_token($raw)['id'], 'a note keeps the link');
		cms_records::update('rsvp', $id, array('email' => 'right@example.test'));
		same(null, rsvp::by_token($raw), 'a new address ends it');
	});
});
test('rsvp: a GET to the manage link changes nothing', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		$event = rsvp_event();
		list($id, $raw) = rsvp_linked(array('event' => $event['id'], 'email' => 'scan@example.test'));
		$before = cms_records::get('rsvp', $id);
		$mails = booking_mails(function () use ($booking_base, $raw) {
			list($status, $body, $headers) = http('GET', "$booking_base/rsvp?token=$raw");
			same(200, $status, $body);
			check(strpos($body, '<p class="reply">') !== false && strpos($body, 'ALERT link_invalid') === false, $body);
			same('same-origin', b_header($headers, 'Referrer-Policy'));
			http('HEAD', "$booking_base/rsvp?token=$raw");
			same(200, http('GET', "$booking_base/rsvp?token=$raw&do=change&answer=no")[0]);
		});
		same(array(), booking_sent($mails));
		same($before, cms_records::get('rsvp', $id));
		// another site's form
		$mails = booking_mails(function () use ($booking_base, $raw) {
			same(403, form_post("$booking_base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'no'), array('Origin: https://evil.test'))[0]);
		});
		same(array(), booking_sent($mails));
		same($before, cms_records::get('rsvp', $id));
		$mails = booking_mails(function () use ($booking_base, $raw) {
			list($status, , $headers) = form_post("$booking_base/rsvp?token=$raw", array('raster_form' => 'rsvp.manage', 'do' => 'change', 'answer' => 'no'));
			same(303, $status);
			same("$booking_base/rsvp?done=changed", location($headers));
		});
		same('no', rsvp_status($id));
		same(array('rsvp_noted scan@example.test'), booking_sent($mails));
		// after the change, the page that says so
		list(, $body) = http('GET', "$booking_base/rsvp?done=changed");
		check(strpos($body, 'ALERT changed') !== false && strpos($body, 'ALERT link_invalid') === false, $body);
	});
});

test('rsvp: the raw token is in no event, log or cache', function () use ($booking_log) {
	rsvp_site(function () use ($booking_log) {
		$event = rsvp_event(array('places' => 2));
		cms_records::ensure(cms_records::info('rsvp'), true);
		$raws = array();
		$seen = rsvp_events(function () use ($event, &$raws, &$going) {
			$mails = booking_mails(function () use ($event, &$going) {
				$going = rsvp_reply(array('event' => $event['id'], 'people' => 2), 'editor')['id'];
				rsvp_reply(array('event' => $event['id']), 'editor');
				rsvp::change($going, array('answer' => 'no'), 'guest');
			});
			same(array('rsvp_going', 'rsvp_waiting', 'rsvp_noted', 'rsvp_promoted'), array_column($mails, 'view'));
			$raws = array_map('booking_token', $mails);
		});
		same(4, count(array_unique(array_filter($raws))), 'a link in each: '.json_encode($raws));
		same(array('rsvp.replied', 'rsvp.replied', 'rsvp.changed', 'rsvp.promoted'), array_column($seen, 0));
		foreach ($seen as $one) {
			check(!preg_match('/[a-f0-9]{64}/', json_encode($one[1])), "a token in $one[0]: ".json_encode($one[1]));
			check(!array_key_exists('manage_url', $one[1]), "$one[0] has the link");
		}
		$rows = json_encode(R::getAll('SELECT * FROM rsvpdata'));
		foreach ($raws as $raw) check(strpos($rows, $raw) === false, 'a raw token in rsvpdata');
		cache_empty();
		list($prod, $prod_log) = booking_server(array('RASTER_ENV' => 'production'));
		foreach (array(1, 2) as $n) {
			list($status, $body, $headers) = http('GET', "$prod/rsvp?token=$raws[0]");
			same(200, $status, $body);
			check(strpos($body, '<p class="reply">') !== false, 'the reply: '.$body);
			check(b_header($headers, 'X-Raster-Cache') !== 'hit', "cached the $n. time");
			same('same-origin', b_header($headers, 'Referrer-Policy'));
		}
		$logs = glob(APPBASE.'data/logs/*') ?: array();
		foreach (array_merge(cached_pages(), $logs, array($booking_log, $prod_log)) as $file) {
			foreach ($raws as $raw) check(strpos((string)@file_get_contents($file), $raw) === false, "a token is in $file");
		}
		same(array(), array_values(array_filter(cached_pages(), function ($f) { return strpos(basename($f), 'version') === false; })), 'a page was cached');
		cache_empty();
	});
});

test('rsvp: dead links: gone, hidden, started; a no reply\'s link lives', function () {
	rsvp_site(function () {
		$event = rsvp_event();
		$e = $event['id'];
		list($id, $raw) = rsvp_linked(array('event' => $e));
		same(array(), rsvp_manage(array('token' => $raw), array('do' => 'change', 'answer' => 'no'))[1]);
		same('no', rsvp_status($id));
		list($rows, $alerts) = rsvp_manage(array('token' => $raw));
		same(array(), $alerts);
		same('no', $rows[0]['status']);
		check(!array_key_exists('token_hashes', $rows[0]) && !array_key_exists('link_sent_at', $rows[0]), json_encode($rows[0]));
		same(array(), rsvp_manage(array('token' => $raw), array('do' => 'change', 'answer' => 'yes', 'people' => '2'))[1]);
		same(array('going', 2), array(rsvp_status($id), cms_records::get('rsvp', $id)['people']));
		$save = function ($values) use ($e) { cms_store::save_item('eventsdata', $e, $values, array_keys($values), 'editor'); };
		$save(array('enabled' => 0));
		same(array(array(), array('link_invalid')), rsvp_manage(array('token' => $raw)));
		$save(array('enabled' => 1));
		same(array(), rsvp_manage(array('token' => $raw))[1]);
		// its start moved to an hour ago
		$ago = time() - 3600;
		$save(array('date' => date('Y-m-d', $ago), 'starts' => date('H:i', $ago)));
		same(array(array(), array('link_invalid')), rsvp_manage(array('token' => $raw)));
		same(array(array(), array('link_invalid')), rsvp_manage(array('token' => $raw), array('do' => 'change', 'answer' => 'no')));
		same('going', rsvp_status($id));
		same(array('link_invalid'), booking_problems(function () use ($id) { rsvp::change($id, array('answer' => 'no'), 'guest'); }));
		same('going', rsvp_status($id));
		$save(array('date' => date('Y-m-d', strtotime('+10 days')), 'starts' => '20:00'));
		same(array(), rsvp_manage(array('token' => $raw))[1]);
		cms_store::delete_item('eventsdata', $e);
		same(array(array(), array('link_invalid')), rsvp_manage(array('token' => $raw)));
		same(null, rsvp::by_token($raw));
		same(array('link_invalid'), rsvp_manage(array('token' => 'xyz'))[1]);
		same(array('link_invalid'), rsvp_manage(array('token' => str_repeat('a', 64)))[1]);
		same(array('link_invalid'), rsvp_manage(array())[1]);
		same(array(array(), array()), rsvp_manage(array('done' => 'changed')));
	});
});

test('rsvp: the guest\'s change, the deadline and the waitlist', function () {
	rsvp_site(function () {
		with_settings(array('rsvp_max_people' => 10), function () {
			$event = rsvp_event(array('places' => 10));
			rsvp_reply(array('event' => $event['id'], 'people' => 5));
			list($id, $first) = rsvp_linked(array('event' => $event['id'], 'email' => 'change@example.test', 'people' => 2));
			// 2 to 3 fits: rsvp_going again, with a new link
			$mails = booking_mails(function () use ($first, &$alerts) { $alerts = rsvp_manage(array('token' => $first), array('do' => 'change', 'answer' => 'yes', 'people' => '3'))[1]; });
			same(array(), $alerts);
			same(array('rsvp_going change@example.test'), booking_sent($mails));
			$second = booking_token($mails[0]);
			check($second !== null && $second !== $first, 'a fresh link');
			same(array(hash('sha256', $first), hash('sha256', $second)), cms_records::get('rsvp', $id)['token_hashes']);
			foreach (array($first, $second) as $token) same($id, (int)rsvp::by_token($token)['id']);
			// 3 to 9 doesn't fit: the guest keeps the 3 places they have
			$mails = booking_mails(function () use ($second, &$result) { $result = rsvp_manage(array('token' => $second), array('do' => 'change', 'answer' => 'yes', 'people' => '9')); });
			same(array('full_for_more'), $result[1]);
			same('3', (string)$result[0][0]['people']);
			same(array(), booking_sent($mails));
			same(array('going', 3), array(rsvp_status($id), cms_records::get('rsvp', $id)['people']));
			// past the deadline: places given back, none taken
			cms_store::save_item('eventsdata', $event['id'], array('rsvp_until' => date('Y-m-d H:i', time() - 60)), array('rsvp_until'), 'editor');
			same(array(), rsvp_manage(array('token' => $second), array('do' => 'change', 'answer' => 'yes', 'people' => '1'))[1]);
			same(1, cms_records::get('rsvp', $id)['people']);
			same(array('closed'), rsvp_manage(array('token' => $second), array('do' => 'change', 'answer' => 'yes', 'people' => '2'))[1]);
			same(1, cms_records::get('rsvp', $id)['people']);
			// the same answer and people again: nothing written or sent
			$before = cms_records::get('rsvp', $id);
			$mails = booking_mails(function () use ($second, &$alerts) { $alerts = rsvp_manage(array('token' => $second), array('do' => 'change', 'answer' => 'yes', 'people' => '1'))[1]; });
			same(array(), $alerts);
			same(array(), booking_sent($mails));
			same($before, cms_records::get('rsvp', $id));
		});
		// a going guest's no moves a waiting one in, in the same change
		with_settings(array('rsvp_staff_email' => 'staff@zz.test'), function () {
			$event = rsvp_event(array('places' => 2));
			list($id, $raw) = rsvp_linked(array('event' => $event['id'], 'email' => 'leaving@example.test', 'people' => 2));
			$waiting = rsvp_reply(array('event' => $event['id'], 'email' => 'next@example.test'), 'editor')['id'];
			same('waiting', rsvp_status($waiting));
			$mails = booking_mails(function () use ($raw) { rsvp_manage(array('token' => $raw), array('do' => 'change', 'answer' => 'no')); });
			same(array('rsvp_noted leaving@example.test', 'rsvp_new staff@zz.test', 'rsvp_promoted next@example.test'), booking_sent($mails));
			check(booking_token($mails[0]) !== null && booking_token($mails[2]) !== null, 'the guests get a link');
			check(strpos($mails[1]['text'], 'token=') === false, 'staff got a link: '.$mails[1]['text']);
			same(array('no', 'going'), array(rsvp_status($id), rsvp_status($waiting)));
		});
	});
});

test('rsvp_waiting carries the position', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 2));
		rsvp_reply(array('event' => $event['id'], 'people' => 2), 'editor');
		$ids = array();
		$positions = array();
		foreach (array(1, 2, 3) as $n) {
			$mails = booking_mails(function () use ($event, &$ids) { $ids[] = rsvp_reply(array('event' => $event['id']), 'editor')['id']; });
			same(array('rsvp_waiting'), array_column($mails, 'view'));
			$positions[] = rsvp_position($mails[0]);
		}
		same(array('1', '2', '3'), $positions);
		$mails = booking_mails(function () use ($ids) { rsvp::change($ids[0], array('answer' => 'no'), 'guest'); });
		same(array('rsvp_noted'), array_column($mails, 'view'));
		same('', rsvp_position($mails[0]));
		$mails = booking_mails(function () use ($ids) { rsvp::change($ids[2], array('people' => 2), 'guest'); });
		same(array('rsvp_waiting'), array_column($mails, 'view'));
		same('2', rsvp_position($mails[0]));
	});
});

test('rsvp: a logged in guest manages their own reply', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		$event = rsvp_event();
		$member = function ($email) use ($booking_base) {
			list(, , $headers) = form_post("$booking_base/register", array('raster_form' => 'authentication.register', 'name' => 'Member', 'email' => $email, 'password' => 'correct horse 1', 'password_again' => 'correct horse 1'));
			return preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie/i', $headers)));
		};
		$email = 'zzrsvp-owner-'.getmypid().'@example.test';
		$mine = $member($email);
		$other = $member('zzrsvp-other-'.getmypid().'@example.test');
		try {
			$owner = (int)R::getCell('SELECT id FROM user WHERE email = ?', array($email));
			check($owner > 0, 'no account');
			$id = cms_records::create('rsvp', array('name' => 'Owner', 'email' => 'owner@example.test', 'event' => $event['id'], 'owner' => $owner))['id'];
			$page = http('GET', "$booking_base/rsvp?reply=$id", null, array("Cookie: $mine"))[1];
			check(strpos($page, '<p class="reply">Owner') !== false && strpos($page, 'ALERT link_invalid') === false, $page);
			$page = http('GET', "$booking_base/rsvp?reply=$id", null, array("Cookie: $other"))[1];
			check(strpos($page, '<p class="reply">') === false && strpos($page, 'ALERT link_invalid') !== false, $page);
			check(strpos(http('GET', "$booking_base/rsvp?reply=$id")[1], 'ALERT link_invalid') !== false, 'logged out');
			$page = http('GET', "$booking_base/rsvp?reply=$id", null, array("Cookie: $mine"))[1];
			check(preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m) === 1, 'no csrf: '.$page);
			$mails = booking_mails(function () use ($booking_base, $id, $m, $mine) {
				list($status, , $headers) = form_post("$booking_base/rsvp?reply=$id", array('raster_form' => 'rsvp.manage', 'csrf' => $m[1], 'do' => 'change', 'answer' => 'maybe'), array("Cookie: $mine"));
				same(303, $status);
				same("$booking_base/rsvp?reply=$id&done=changed", location($headers));
			});
			same('maybe', rsvp_status($id));
			same(array('rsvp_noted owner@example.test'), booking_sent($mails));
		} finally {
			R::exec("DELETE FROM user WHERE email LIKE 'zzrsvp-%'");
		}
	});
});

test('every rsvp email is sent once per change', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('title' => 'Jazz night'));
		list($id) = rsvp_linked(array('event' => $event['id'], 'email' => 'once@example.test'));
		$mails = booking_mails(function () use ($id) { cms_store::save_item('rsvpdata', $id, array('notes' => 'by the window', 'phone' => '0721'), array('notes', 'phone'), 'editor'); });
		same(array(), booking_sent($mails), 'a note:');
		$mails = booking_mails(function () use ($event) { cms_store::save_item('eventsdata', $event['id'], array('title' => 'Jazz night, late'), array('title'), 'editor'); });
		same(array(), booking_sent($mails), 'the event:');
		$mails = booking_mails(function () use ($id) { cms_store::save_item('rsvpdata', $id, array('answer' => 'no'), array('answer'), 'editor'); });
		same(array('rsvp_noted once@example.test'), booking_sent($mails), 'staff\'s no:');
		check(booking_token($mails[0]) !== null, 'no link');
		// a guest's change, without rsvp_staff_email: nothing for staff
		$mails = booking_mails(function () use ($id) { rsvp::change($id, array('answer' => 'yes'), 'guest'); });
		same(array('rsvp_going once@example.test'), booking_sent($mails), 'the guest\'s yes:');
		// the model's change inside a transaction of the site's code: once
		$mails = booking_mails(function () use ($id) {
			cms_records::transaction(function () use ($id) { rsvp::change($id, array('answer' => 'maybe'), 'staff'); });
		});
		same(array('rsvp_noted once@example.test'), booking_sent($mails), 'in a transaction:');
		$mails = booking_mails(function () use ($id) { rsvp::change($id, array('answer' => 'no'), 'guest'); });
		same(array('rsvp_noted once@example.test'), booking_sent($mails), 'maybe to no:');
		// a no with another party: nothing changed for them
		$mails = booking_mails(function () use ($id) { rsvp::change($id, array('people' => 3), 'guest'); });
		same(array(), booking_sent($mails), 'a no\'s party:');
		same(3, cms_records::get('rsvp', $id)['people']);
	});
});

test('a site can unbind an rsvp email', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 1));
		rsvp_reply(array('event' => $event['id']), 'editor');
		$waiting = rsvp_reply(array('event' => $event['id'], 'email' => 'unbound@example.test'), 'editor')['id'];
		event::unbind('rsvp.promoted')->from('rsvp', 'promoted_mail');
		try {
			$mails = booking_mails(function () use ($event) { cms_store::save_item('eventsdata', $event['id'], array('places' => 2), array('places'), 'editor'); });
		} finally {
			event::bind('rsvp.promoted')->to('rsvp', 'promoted_mail');
		}
		same(array(), booking_sent($mails));
		same('going', rsvp_status($waiting));
		$other = rsvp_reply(array('event' => $event['id'], 'email' => 'bound@example.test'), 'editor')['id'];
		$mails = booking_mails(function () use ($event) { cms_store::save_item('eventsdata', $event['id'], array('places' => 3), array('places'), 'editor'); });
		same(array('rsvp_promoted bound@example.test'), booking_sent($mails));
		same('going', rsvp_status($other));
	});
});

test('rsvp: a mail that fails is logged without the address', function () {
	rsvp_site(function () {
		$event = rsvp_event();
		$was = getenv('RASTER_MAIL');
		putenv('RASTER_MAIL=zz://nowhere');
		try {
			list($reply, $log) = spam_logged(function () use ($event) { return rsvp_reply(array('event' => $event['id'], 'email' => 'ana@example.test'), 'editor'); });
		} finally {
			putenv("RASTER_MAIL=$was");
		}
		same('going', rsvp_status($reply['id']), 'the reply is kept:');
		$lines = array_values(preg_grep('/rsvp: /', explode("\n", $log)));
		check($lines !== array(), 'nothing logged: '.$log);
		foreach ($lines as $line) check(strpos($line, '@') === false && !preg_match('/[a-f0-9]{64}/', $line), 'logged: '.$line);
	});
});

test('rsvp requires(): emails, pages, settings', function () {
	rsvp_site(function ($theme) {
		$messages = function ($view) {
			return array_map(function ($p) { return $p['severity'].': '.$p['message']; }, (new raster_inspector())->lint_file($view));
		};
		$noted = "$theme/_email/rsvp_noted.html";
		$html = file_get_contents($noted);
		unlink($noted);
		try {
			check(preg_grep('/^error: render\.rsvp\.manage sends an email written in .*rsvp_noted\.html/', $messages('rsvp.html')) !== array(), json_encode($messages('rsvp.html')));
		} finally {
			test_fixture($noted);
			file_put_contents($noted, $html);
		}
		same(array(), array_values(preg_grep('/^error/', $messages('rsvp.html'))));
		check(in_array('rsvp', controller::required('pages'), true), 'pages: '.json_encode(controller::required('pages')));
		$urls = array_column((new feed())->pages(), 'url');
		check(!preg_grep('#/rsvp$#', $urls) && preg_grep('#/zzrsvp$#', $urls), 'sitemap: '.json_encode($urls));
		with_settings(array('rsvp_manage_page' => 'my-reply'), function () {
			same(array('my-reply'), array_values(array_intersect(array('rsvp', 'my-reply'), controller::required('pages'))));
		});
		require_once BASE.'tools/describe.php';
		$settings = with_settings(array('rsvp_staff_email' => 'staff@zz.test'), function () { return raster_describe::site(array('settings'))['settings']; });
		same('staff@zz.test', $settings['rsvp_staff_email']);
		check(!preg_grep('/protected/', $messages('rsvp.html')), json_encode($messages('rsvp.html')));
		with_settings(array('protected' => array('account' => 'member', 'rsvp' => 'editor')), function () use ($messages) {
			check(preg_grep('/^warning: .*render\.rsvp\.manage.*protected/', $messages('rsvp.html')) !== array(), json_encode($messages('rsvp.html')));
		});
	});
});

// ## Bundled rsvp: the form

// the reply form as a request in this process, as rsvp_manage() asks the
// manage page: $get is the URL's query, $post what the form sent (stamped
// a minute ago unless it names raster_ts), from $ip. A fresh validation
// object, as a new request has. Gives the alerts raised.
function rsvp_form($get, $post = null, $ip = '192.0.2.10') {
	$template = template::instance();
	$kept = array($_GET, $_POST, $_SERVER, $template->current_call, $template->forms);
	$validation = isset(controller::instance()->objects['validation']) ? controller::instance()->objects['validation'] : null;
	$_GET = $get;
	$_POST = array();
	$_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
	$_SERVER['REMOTE_ADDR'] = $ip;
	if ($post !== null) {
		if (!array_key_exists('raster_ts', $post)) $post['raster_ts'] = stamp_for_tests(60);
		$_POST = array('raster_form' => 'rsvp.form') + $post;
	}
	$template->current_call = 'rsvp.form';
	$template->forms['rsvp.form'] = array_fill_keys(array('name', 'email', 'phone', 'answer', 'people', 'notes'), array());
	unset(controller::instance()->objects['validation']);
	rsvp::forget();
	try {
		(new rsvp())->form();
		$raised = new ReflectionProperty('validation', 'raised');
		$raised->setAccessible(true);
		return array_keys($raised->getValue(validation::get()));
	} finally {
		list($_GET, $_POST, $_SERVER, $template->current_call, $template->forms) = $kept;
		if ($validation) controller::instance()->objects['validation'] = $validation;
		else unset(controller::instance()->objects['validation']);
	}
}
// a post of the reply form to $url on booking_server(): a yes for one
// unless $values say otherwise. Gives status, body and where it redirected.
function rsvp_post($url, $values, $headers = array()) {
	list($status, $body, $answer) = form_post($url, $values + array('raster_form' => 'rsvp.form', 'answer' => 'yes', 'people' => '1'), $headers);
	return array($status, $body, location($answer));
}

test('rsvp form: a yes, a waitlist and a no', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		spam_clear();
		$event = rsvp_event(array('title' => 'Jazz night', 'places' => 2));
		$other = rsvp_event(array('title' => 'Other night'));
		$page = "$booking_base/events/events_item/{$event['slug']}";
		list($status, $body) = http('GET', $page);
		same(200, $status, $body);
		check(strpos($body, '<form method="post"') !== false && strpos($body, 'ALERT not_open') === false && strpos($body, 'ALERT closed') === false, $body);
		// the event is the page's, whatever the post names
		$mails = booking_mails(function () use ($page, $other) {
			list($status, , $location) = rsvp_post($page, array('name' => 'Ana', 'email' => 'ana.form@example.test', 'people' => '2', 'event' => (string)$other['id']));
			same(array(303, "$page?done=going"), array($status, $location));
		});
		$ana = cms_records::find('rsvp', array('email' => 'ana.form@example.test'));
		same(1, count($ana));
		same(array((int)$event['id'], 'going', 2, 0), array($ana[0]['event'], $ana[0]['status'], $ana[0]['people'], (int)$ana[0]['owner']));
		same(array('rsvp_going ana.form@example.test'), booking_sent($mails));
		same((int)$ana[0]['id'], (int)rsvp::by_token(booking_token($mails[0]))['id'], 'the going email\'s link:');
		same(array(), cms_records::find('rsvp', array('event' => $other['id'])));
		// full: the next yes waits, at the head of the queue
		$mails = booking_mails(function () use ($page) {
			list($status, , $location) = rsvp_post($page, array('name' => 'Bo', 'email' => 'bo.form@example.test'));
			same(array(303, "$page?done=waiting"), array($status, $location));
		});
		same(array('rsvp_waiting bo.form@example.test'), booking_sent($mails));
		same('1', rsvp_position($mails[0]));
		$mails = booking_mails(function () use ($page) {
			list($status, , $location) = rsvp_post($page, array('name' => 'Cy', 'email' => 'cy.form@example.test', 'answer' => 'no'));
			same(array(303, "$page?done=noted"), array($status, $location));
		});
		same(array('rsvp_noted cy.form@example.test'), booking_sent($mails));
		same(array('waiting', 'no'), array(cms_records::find('rsvp', array('email' => 'bo.form@example.test'))[0]['status'], cms_records::find('rsvp', array('email' => 'cy.form@example.test'))[0]['status']));
		list(, $body) = http('GET', "$page?done=noted");
		check(strpos($body, 'ALERT noted') !== false, $body);
		// on any page by ?event=, and the redirect keeps it
		list($status, , $location) = rsvp_post("$booking_base/zzrsvp?event={$other['id']}", array('name' => 'Di', 'email' => 'di.form@example.test', 'event' => (string)$event['id']));
		same(array(303, "$booking_base/zzrsvp?event={$other['id']}&done=going"), array($status, $location));
		same((int)$other['id'], cms_records::find('rsvp', array('email' => 'di.form@example.test'))[0]['event']);
		// a post that names an event on a page without one replies to nothing
		list($status, $body) = rsvp_post("$booking_base/zzrsvp", array('name' => 'Ed', 'email' => 'ed.form@example.test', 'event' => (string)$event['id']));
		same(200, $status);
		check(strpos($body, 'ALERT not_open') !== false, $body);
		same(array(), cms_records::find('rsvp', array('email' => 'ed.form@example.test')));
	});
});

test('rsvp form: not_open, closed, too_many', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		spam_clear();
		$draft = rsvp_event(array('enabled' => 0));
		$undated = rsvp_event(array('date' => ''));
		$closed = rsvp_event(array('rsvp_until' => date('Y-m-d H:i', strtotime('-1 hour'))));
		$open = rsvp_event();
		foreach (array('' => 'no event', "?event={$draft['id']}" => 'a draft', "?event={$undated['id']}" => 'no date', '?event=999999' => 'no such item') as $query => $case) {
			list($status, $body) = http('GET', "$booking_base/zzrsvp$query");
			same(200, $status, $case);
			check(strpos($body, 'ALERT not_open') !== false, "$case: $body");
		}
		list(, $body) = http('GET', "$booking_base/zzrsvp?event={$closed['id']}");
		check(strpos($body, 'ALERT closed') !== false && strpos($body, 'ALERT not_open') === false, $body);
		list(, $body) = http('GET', "$booking_base/zzrsvp?event={$open['id']}");
		check(strpos($body, 'ALERT closed') === false && strpos($body, 'ALERT not_open') === false, $body);
		$mails = booking_mails(function () use ($booking_base, $closed, $draft, $open) {
			list($status, $body) = rsvp_post("$booking_base/zzrsvp?event={$closed['id']}", array('name' => 'Late', 'email' => 'late.form@example.test'));
			same(200, $status);
			check(strpos($body, 'ALERT closed') !== false, $body);
			list($status, $body) = rsvp_post("$booking_base/zzrsvp?event={$draft['id']}", array('name' => 'Draft', 'email' => 'draft.form@example.test'));
			check(strpos($body, 'ALERT not_open') !== false, $body);
			list($status, $body) = rsvp_post("$booking_base/zzrsvp?event={$open['id']}", array('name' => 'Seven', 'email' => 'seven.form@example.test', 'people' => '7', 'notes' => 'all of us'));
			same(200, $status);
			check(strpos($body, 'ALERT too_many') !== false, $body);
			check(strpos($body, 'value="Seven"') !== false && strpos($body, 'value="7"') !== false && strpos($body, '>all of us</textarea>') !== false, 'the typed values: '.$body);
		});
		same(array(), booking_sent($mails));
		same(array(), cms_records::find('rsvp'));
	});
});

test('already_replied mails the link to the reply\'s address only, once per five minutes', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		spam_clear();
		$event = rsvp_event();
		$page = "$booking_base/events/events_item/{$event['slug']}";
		$first = booking_mails(function () use ($page) {
			same(303, rsvp_post($page, array('name' => 'Ana', 'email' => 'ana@example.com', 'people' => '2'))[0]);
		});
		$ana = cms_records::find('rsvp', array('email' => 'ana@example.com'))[0];
		$again = function ($values = array(), $headers = array()) use ($page) {
			$result = null;
			$mails = booking_mails(function () use ($page, $values, $headers, &$result) {
				$result = rsvp_post($page, $values + array('name' => 'Someone else', 'email' => 'Ana@Example.com', 'people' => '4'), $headers);
			});
			return array($result[0], $result[1], $mails);
		};
		list($status, $body, $mails) = $again();
		same(200, $status);
		check(strpos($body, 'ALERT already_replied') !== false, $body);
		same(array('rsvp_link ana@example.com'), booking_sent($mails));
		$raw = booking_token($mails[0]);
		check($raw !== null && $raw !== booking_token($first[0]), 'a new link: '.$mails[0]['text']);
		same((int)$ana['id'], (int)rsvp::by_token($raw)['id']);
		same((int)$ana['id'], (int)rsvp::by_token(booking_token($first[0]))['id'], 'the first link still works:');
		$stored = cms_records::find('rsvp');
		same(1, count($stored));
		same(array('Ana', 2), array($stored[0]['name'], $stored[0]['people']));
		check($stored[0]['link_sent_at'] !== '', 'link_sent_at');
		// within five minutes: the same answer, and nothing sent
		list($status, $body, $mails) = $again();
		check(strpos($body, 'ALERT already_replied') !== false, $body);
		same(array(), booking_sent($mails));
		// later, the link again
		spam_clear();
		// a post the rules refuse anyway says nothing about the address, and mails nothing
		list($status, $body, $mails) = $again(array('answer' => 'bogus'));
		same(200, $status);
		check(strpos($body, 'ALERT already_replied') === false, $body);
		same(array(), booking_sent($mails));
		$back = function () use ($ana) { cms_records::update('rsvp', $ana['id'], array('link_sent_at' => date('Y-m-d H:i:s', time() - 360))); };
		$back();
		list(, $body, $mails) = $again();
		check(strpos($body, 'ALERT already_replied') !== false, $body);
		same(array('rsvp_link ana@example.com'), booking_sent($mails));
		// without a stamp, nothing is looked up or mailed
		$back();
		list($status, $body, $mails) = $again(array('raster_ts' => ''));
		same(200, $status);
		check(strpos($body, 'ALERT try_again') !== false && strpos($body, 'ALERT already_replied') === false, $body);
		same(array(), booking_sent($mails));
		// the 4th post with the address in an hour is held, and mails nothing
		spam_clear();
		$back();
		$sent = array();
		for ($i = 1; $i <= 4; $i++) {
			list($status, $body, $mails) = $again();
			$sent = array_merge($sent, booking_sent($mails));
			if ($i < 4) check(strpos($body, 'ALERT already_replied') !== false, "post $i: $body");
		}
		check(strpos($body, 'ALERT slow_down') !== false && strpos($body, 'ALERT already_replied') === false, $body);
		same(array('rsvp_link ana@example.com'), $sent);
		same(1, count(cms_records::find('rsvp')));
	});
});

test('rsvp form: a post without a fresh stamp gets the form again', function () use ($booking_base) {
	rsvp_site(function () use ($booking_base) {
		spam_clear();
		$event = rsvp_event(array('places' => 20));
		$page = "$booking_base/events/events_item/{$event['slug']}";
		$mails = booking_mails(function () use ($page) {
			foreach (array('' => 'no stamp', stamp_for_tests(1) => 'a stamp 1 s old') as $stamp => $case) {
				list($status, $body) = rsvp_post($page, array('name' => 'Quick', 'email' => 'quick@example.test', 'raster_ts' => $stamp));
				same(200, $status, $case);
				check(strpos($body, 'ALERT try_again') !== false && strpos($body, 'value="Quick"') !== false, "$case: $body");
			}
		});
		same(array(), booking_sent($mails));
		same(array(), cms_records::find('rsvp'));
		// many people on one address: each reply is taken
		booking_mails(function () use ($page) {
			for ($i = 1; $i <= 7; $i++) same(303, rsvp_post($page, array('name' => "Office $i", 'email' => "office$i@example.test"), array('X-Forwarded-For: 203.0.113.70'))[0], "reply $i");
		});
		same(7, count(cms_records::find('rsvp')));
		// unless spam_ip_blocks
		spam_clear();
		with_settings(array('spam_ip_blocks' => true), function () use ($event) {
			for ($i = 1; $i <= 6; $i++) {
				$alerts = rsvp_form(array('event' => (string)$event['id']), array('name' => "Block $i", 'email' => "block$i@example.test", 'answer' => 'yes', 'people' => '1'), '203.0.113.71');
				same($i < 6 ? array() : array('slow_down'), $alerts, "post $i");
			}
		});
		same(12, count(cms_records::find('rsvp')));
	});
});

test('rsvp form: a reply inside the site\'s own transaction sends its event and email once', function () {
	rsvp_site(function () {
		spam_clear();
		$event = rsvp_event();
		$mails = array();
		$seen = rsvp_events(function () use ($event, &$mails) {
			$mails = booking_mails(function () use ($event) {
				cms_records::transaction(function () use ($event) {
					same(array(), rsvp_form(array('event' => (string)$event['id']), array('name' => 'Inside', 'email' => 'inside@example.test', 'answer' => 'yes', 'people' => '2')));
				});
			});
		});
		same(array('rsvp.replied'), array_column($seen, 0));
		same('guest', $seen[0][1]['who']);
		same(array('rsvp_going inside@example.test'), booking_sent($mails));
		check(booking_token($mails[0]) !== null, 'no link: '.$mails[0]['text']);
		// and a reply staff add after it, in the same request, gets its own
		$mails = booking_mails(function () use ($event) { rsvp_reply(array('event' => $event['id'], 'email' => 'after@example.test'), 'editor'); });
		same(array('rsvp_going after@example.test'), booking_sent($mails));
	});
});

test('rsvp form: the_rsvp adds a field', function () use ($booking_base) {
	$app = APPBASE.config::get('models_path', 'models');
	rsvp_site(function ($theme) use ($app, $booking_base) {
		spam_clear();
		temp_model("$app/the_rsvp", array('the_rsvp.php' => "<?php\nclass the_rsvp extends rsvp\n{\n\tstatic function types() {\n\t\t\$types = parent::types();\n\t\t\$types['rsvp']['fields']['dietary'] = '';\n\t\treturn \$types;\n\t}\n}\n"));
		$view = "$theme/zzrsvp.html";
		$html = file_get_contents($view);
		file_put_contents($view, str_replace('<textarea name="notes">', '<input name="dietary"><textarea name="notes">', $html));
		try {
			controller::forget_models();
			cms_records::forget();
			same('the_rsvp', cms_records::info('rsvp')['class']);
			$event = rsvp_event();
			list($status, $body) = rsvp_post("$booking_base/zzrsvp?event={$event['id']}", array('name' => 'Vegan Guest', 'email' => 'vegan.rsvp@example.test', 'people' => '2', 'dietary' => 'vegan'));
			same(303, $status, $body);
			$stored = cms_records::find('rsvp', array('email' => 'vegan.rsvp@example.test'));
			same(array('vegan', 'going', (int)$event['id']), array($stored[0]['dietary'], $stored[0]['status'], $stored[0]['event']));
		} finally {
			file_put_contents($view, $html);
			remove_dir("$app/the_rsvp");
			controller::forget_models();
			cms_records::forget();
		}
	});
});

test('lint: rsvp form alerts and the event\'s fields', function () {
	rsvp_site(function ($theme) {
		$messages = function ($html) {
			return array_map(function ($p) { return $p['severity'].': '.$p['message']; }, lint_html($html));
		};
		$form = '<!-- render.rsvp.form --><form method="post"><input name="name"><input type="email" name="email"><button>Reply</button></form><!-- /render.rsvp.form -->';
		$alerts = "<!-- print.validation.alert('try_again') --><p>Again.</p><!-- /print.validation.alert('try_again') -->"
			."<!-- print.validation.alert('slow_down') --><p>Slow.</p><!-- /print.validation.alert('slow_down') -->";
		check(preg_grep('/^warning: .*render\.rsvp\.form can turn a post away as spam/', $messages($form)) !== array(), json_encode($messages($form)));
		$clean = $messages($alerts.$form);
		same(array(), array_values(preg_grep('/spam|rsvp_collection|date|places/', $clean)), 'events has date and places:');
		$gig = "$theme/zzgig.html";
		try {
			with_settings(array('rsvp_collection' => 'zzgig'), function () use ($gig, $messages, $alerts, $form) {
				file_put_contents($gig, '<html><body><!-- render.cms.zzgig --><h1><!-- print.title -->Gig<!-- /print.title --></h1><!-- /render.cms.zzgig --></body></html>');
				check(preg_grep("/^error: .*render\.rsvp\.form.*'zzgig'.*date/", $messages($alerts.$form)) !== array(), json_encode($messages($alerts.$form)));
				file_put_contents($gig, '<html><body><!-- render.cms.zzgig --><h1><!-- print.title -->Gig<!-- /print.title --></h1><p><!-- print.date -->2026-11-20<!-- /print.date --></p><!-- /render.cms.zzgig --></body></html>');
				$found = $messages($alerts.$form);
				same(array(), array_values(preg_grep("/^error: .*'zzgig'/", $found)));
				check(preg_grep("/^warning: .*render\.rsvp\.form.*'zzgig'.*places/", $found) !== array(), json_encode($found));
			});
		} finally {
			@unlink($gig);
		}
		$link = "$theme/_email/rsvp_link.html";
		$html = file_get_contents($link);
		unlink($link);
		try {
			check(preg_grep('/^error: render\.rsvp\.form sends an email written in .*rsvp_link\.html/', $messages($alerts.$form)) !== array(), json_encode($messages($alerts.$form)));
		} finally {
			file_put_contents($link, $html);
		}
		same(array(), array_values(preg_grep('/^error/', $messages($alerts.$form))));
	});
});

// ## Bundled rsvp: for staff

test('render.rsvp.events: counts and replies, for staff only', function () {
	rsvp_site(function () {
		$later = rsvp_event(array('title' => 'Later', 'date' => date('Y-m-d', strtotime('+12 days')), 'places' => 4));
		$sooner = rsvp_event(array('title' => 'Sooner', 'date' => date('Y-m-d', strtotime('+5 days')), 'starts' => '19:30', 'places' => 10));
		$past = rsvp_event(array('title' => 'Past', 'date' => date('Y-m-d', strtotime('-3 days'))));
		// staff see what they hid
		$draft = rsvp_event(array('title' => 'Draft', 'date' => date('Y-m-d', strtotime('+8 days')), 'enabled' => 0, 'places' => ''));
		rsvp_reply(array('event' => $sooner['id'], 'people' => 3));
		rsvp_reply(array('event' => $sooner['id'], 'people' => 2));
		rsvp_reply(array('event' => $sooner['id'], 'answer' => 'maybe', 'people' => 2));
		rsvp_reply(array('event' => $sooner['id'], 'answer' => 'no'));
		rsvp_reply(array('event' => $later['id'], 'people' => 4));
		$first = rsvp_reply(array('event' => $later['id'], 'people' => 2));
		$second = rsvp_reply(array('event' => $later['id'], 'people' => 1));
		same(array('waiting', 'waiting'), array($first['status'], $second['status']));
		rsvp_reply(array('event' => $past['id'], 'people' => 2));
		rsvp_reply(array('event' => $draft['id'], 'people' => 2));
		$r = new rsvp();
		same(array(), $r->events(), 'a visitor:');
		booking_as_editor(function () use ($r, $sooner, $later, $draft, $first, $second) {
			$rows = $r->events();
			same(array('Sooner', 'Draft', 'Later'), array_column($rows, 'event_title'));
			$counts = function ($row) {
				return array_intersect_key($row, array_flip(array('event', 'event_date', 'event_starts', 'places', 'going', 'left', 'waiting', 'maybe', 'no')));
			};
			same(array('event' => (string)$sooner['id'], 'event_date' => $sooner['date'], 'event_starts' => '19:30', 'places' => '10', 'going' => '5', 'left' => '5', 'waiting' => '0', 'maybe' => '1', 'no' => '1'), $counts($rows[0]));
			same(array('event' => (string)$draft['id'], 'event_date' => $draft['date'], 'event_starts' => '20:00', 'places' => '', 'going' => '2', 'left' => '', 'waiting' => '0', 'maybe' => '0', 'no' => '0'), $counts($rows[1]));
			same(array('event' => (string)$later['id'], 'event_date' => $later['date'], 'event_starts' => '20:00', 'places' => '4', 'going' => '4', 'left' => '0', 'waiting' => '2', 'maybe' => '0', 'no' => '0'), $counts($rows[2]));
			same(array(4, 1, 3), array_map(function ($row) { return count($row['replies']); }, $rows));
			$positions = array_column($rows[2]['replies'], 'position', 'name');
			same(array('1', '2'), array($positions[$first['name']], $positions[$second['name']]));
			same(array('', '', '', '', 'event_hidden', '', '', ''), array_merge(array_column($rows[0]['replies'], 'attention'), array_column($rows[1]['replies'], 'attention'), array_column($rows[2]['replies'], 'attention')));
			foreach ($rows as $row) {
				foreach ($row['replies'] as $reply) {
					same('rsvp', $reply['raster_record']);
					check(strpos($reply['raster_detail_link'], '/rsvp/rsvp_item/') !== false, $reply['raster_detail_link']);
					check(!array_key_exists('token_hashes', $reply) && !array_key_exists('link_sent_at', $reply), 'hidden fields reach the staff page: '.json_encode($reply));
					same($row['event'], (string)$reply['event']);
				}
			}
			// from= another day takes the past in too
			same(array('Past', 'Sooner', 'Draft', 'Later'), array_column($r->events('from='.date('Y-m-d', strtotime('-7 days'))), 'event_title'));
		});
	});
});

test('rsvp: needs attention: gone, hidden, over places, moved', function () {
	rsvp_site(function () {
		$gone = rsvp_event(array('title' => 'Gone'));
		$hidden = rsvp_event(array('title' => 'Hidden'));
		$over = rsvp_event(array('title' => 'Over', 'places' => 10));
		$moved = rsvp_event(array('title' => 'Moved'));
		$fine = rsvp_event(array('title' => 'Fine'));
		$replies = array(
			rsvp_reply(array('event' => $gone['id'], 'name' => 'Gone guest')),
			rsvp_reply(array('event' => $hidden['id'], 'name' => 'Hidden guest')),
			rsvp_reply(array('event' => $over['id'], 'name' => 'Over one', 'people' => 4)),
			rsvp_reply(array('event' => $over['id'], 'name' => 'Over two', 'people' => 4)),
			rsvp_reply(array('event' => $moved['id'], 'name' => 'Moved guest')),
			rsvp_reply(array('event' => $fine['id'], 'name' => 'Fine guest')),
		);
		// a no takes no places: nothing to fix there
		rsvp_reply(array('event' => $gone['id'], 'name' => 'Gone no', 'answer' => 'no'));
		rsvp_reply(array('event' => $over['id'], 'name' => 'Over maybe', 'answer' => 'maybe'));
		$mails = booking_mails(function () use ($gone, $hidden, $over, $moved) {
			cms_store::delete_item('eventsdata', $gone['id']);
			cms_store::save_item('eventsdata', $hidden['id'], array('enabled' => 0), array('enabled'), 'editor');
			cms_store::save_item('eventsdata', $over['id'], array('places' => 5), array('places'), 'editor');
			cms_store::save_item('eventsdata', $moved['id'], array('date' => date('Y-m-d', strtotime('+11 days'))), array('date'), 'editor');
		});
		same(array(), booking_sent($mails), 'editing the event mails nobody:');
		// nobody is moved out
		same(array_fill(0, 6, 'going'), array_map(function ($reply) { return rsvp_status($reply['id']); }, $replies));
		rsvp::forget();
		$r = new rsvp();
		same(array(), $r->attention(), 'a visitor:');
		same('', $r->count('attention'), 'a visitor:');
		booking_as_editor(function () use ($r, $replies) {
			$why = array_column($r->attention(), 'attention', 'name');
			ksort($why);
			same(array('Gone guest' => 'event_gone', 'Hidden guest' => 'event_hidden', 'Moved guest' => 'moved', 'Over one' => 'over_places', 'Over two' => 'over_places'), $why);
			same('5', $r->count('attention'));
			foreach ($r->attention() as $row) {
				same('rsvp', $row['raster_record']);
				check(!array_key_exists('token_hashes', $row), json_encode($row));
			}
			// the staff page's lists say the same of the events still there
			$listed = array();
			foreach ($r->events() as $event) $listed += array_column($event['replies'], 'attention', 'name');
			ksort($listed);
			same(array('Fine guest' => '', 'Hidden guest' => 'event_hidden', 'Moved guest' => 'moved', 'Over maybe' => '', 'Over one' => 'over_places', 'Over two' => 'over_places'), $listed);
			// staff phoned the guest and noted it: their save says the guest knows the new start
			cms_store::save_item('rsvpdata', $replies[4]['id'], array('notes' => 'Phoned, fine'), array('notes'), 'editor');
			rsvp::forget();
			same('4', $r->count('attention'));
			check(!in_array('Moved guest', array_column($r->attention(), 'name'), true), json_encode($r->attention()));
		});
	});
});

test('print.rsvp.count: options and privacy', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('places' => 2));
		$other = rsvp_event();
		rsvp_reply(array('event' => $event['id'], 'people' => 2));
		rsvp_reply(array('event' => $event['id']));
		rsvp_reply(array('event' => $event['id']));
		rsvp_reply(array('event' => $event['id'], 'answer' => 'no'));
		rsvp_reply(array('event' => $other['id']));
		$r = new rsvp();
		same('', $r->count());
		same('', $r->count('status=going'));
		booking_as_editor(function () use ($r, $event) {
			same('2', $r->count());
			same('2', $r->count('status=waiting'));
			same('2', $r->count('status=going'));
			same('1', $r->count('status=going&event='.$event['id']));
			same('4', $r->count('event='.$event['id']));
			same('0', $r->count('attention'));
		});
	});
});

test('rsvp staff page in the editor', function () {
	rsvp_site(function ($theme) {
		$event = rsvp_event(array('title' => 'Jazz night', 'places' => 2));
		rsvp_reply(array('event' => $event['id'], 'name' => 'Ana Popescu', 'people' => 2));
		rsvp_reply(array('event' => $event['id'], 'name' => 'Ion Waits'));
		// a visitor (the page isn't protected here) gets no rows and no counts
		$out = contact_render('/zzrsvpstaff');
		check(strpos($out, '[exit 0]') !== false && strpos($out, 'waiting= attention=</h1>') !== false, 'a visitor: '.$out);
		check(strpos($out, 'Ana Popescu') === false && strpos($out, 'class="event"') === false && strpos($out, 'class="attention"') === false, 'a visitor: '.$out);
		// a member, logged in, gets nothing either: these are editors' regions
		$out = contact_render('/zzrsvpstaff', 'member');
		check(strpos($out, '[exit 0]') !== false && strpos($out, 'waiting= attention=</h1>') !== false, 'a member: '.$out);
		check(strpos($out, 'Ana Popescu') === false && strpos($out, 'class="event"') === false && strpos($out, 'class="attention"') === false, 'a member: '.$out);
		$out = contact_render('/zzrsvpstaff?event='.$event['id'], 'editor');
		check(strpos($out, '[exit 0]') !== false && strpos($out, 'waiting=1 attention=0</h1>') !== false, 'the counts: '.$out);
		check(strpos($out, 'Jazz night going=2 left=0 waiting=1</h2>') !== false && strpos($out, 'Ion Waits') !== false, 'the event: '.$out);
		check(strpos($out, '<option value="'.$event['id'].'"') !== false, 'the event to choose: '.$out);
		// the GET form keeps the asked-for event chosen, once, among the options the model lists
		$quiz = rsvp_event(array('title' => 'Quiz'));
		foreach (array($event['id'] => 'Jazz night', $quiz['id'] => 'Quiz') as $id => $title) {
			$picked = contact_render('/zzrsvpstaff?event='.$id, 'editor');
			$select = substr($picked, strpos($picked, '<select name="event">'));
			$select = substr($select, 0, strpos($select, '</select>'));
			check(preg_match('#<option value="'.$id.'" selected>(<!--[^>]*-->)?'.$title.'#', $select) === 1 && substr_count($select, 'selected') === 1, "$title chosen: $select");
		}
		cms_store::delete_item('eventsdata', $quiz['id']);
		rsvp::forget();
		check(preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $out, $m) === 1, 'no editor: '.$out);
		$marks = json_decode($m[1], true)['marks'];
		// both replies, in the event's row and in the event's list
		$items = array_values(array_filter($marks, function ($mark) { return isset($mark['kind']) && $mark['kind'] === 'item' && $mark['collection'] === 'rsvp'; }));
		same(4, count($items), 'replies the editor can edit:');
		foreach ($items as $item) {
			same(array('promote'), $item['actions']);
			check(in_array('status', $item['readonly'], true) && !array_key_exists('token_hashes', $item['values']), json_encode($item));
		}
		// the list's card for a phone reply starts with the event
		$lists = array_values(array_filter($marks, function ($mark) { return isset($mark['kind']) && $mark['kind'] === 'collection' && $mark['collection'] === 'rsvp'; }));
		same(1, count($lists));
		same(true, $lists[0]['addable']);
		same(array('event' => (string)$event['id']), $lists[0]['filters']);
		// lint: a staff page belongs behind the login
		$lint = function () use ($theme) { return (new raster_inspector())->lint_path("$theme/zzrsvpstaff.html"); };
		check(has_problem($lint(), "lists rsvp replies but isn't protected for editors"), 'unprotected');
		check(!has_problem($lint(), 'without cms_records::listed()'), json_encode($lint()));
		check(has_problem(lint_html('<!-- render.rsvp.attention --><p><!-- print.name -->Ana<!-- /print.name --></p><!-- /render.rsvp.attention -->'), "lists rsvp replies but isn't protected for editors"), 'attention unprotected');
		with_settings(array('protected' => array('account' => 'member', 'zzrsvpstaff' => 'editor')), function () use ($lint) {
			check(!has_problem($lint(), "isn't protected for editors"), 'protected');
			check(!has_problem($lint(), 'without cms_records::listed()'), json_encode($lint()));
		});
		// render.rsvp.events lists the type, as render.cms.rsvp does
		same(array('render.rsvp.events'), controller::required('lists')['rsvp']);
		$staff = file_get_contents("$theme/zzrsvpstaff.html");
		unlink("$theme/zzrsvpstaff.html");
		try {
			check(has_problem((new raster_inspector())->lint_types(), "Visitors make 'rsvp' records"), 'no staff page yet');
			file_put_contents("$theme/zzrsvpstaff.html", '<html><head><title>Replies</title></head><body><!-- render.rsvp.events --><p><!-- print.event_title -->Jazz night<!-- /print.event_title --></p><!-- /render.rsvp.events --></body></html>');
			check(!has_problem((new raster_inspector())->lint_types(), "Visitors make 'rsvp' records"), 'listed by render.rsvp.events');
		} finally {
			file_put_contents("$theme/zzrsvpstaff.html", $staff);
		}
	});
});

// ## Form and field values (#158 group 5)

test('a line break counts once for minlength and maxlength, as browsers count it (#111)', function () {
	$v = validation::get();
	$r = new ReflectionMethod($v, 'check_field');
	$r->setAccessible(true);
	$saved = $_POST;
	try {
		// 12 characters to the browser, 14 as posted
		$_POST = array('notes' => "aaaa\r\naaaa\r\naa");
		same(array(), $r->invoke($v, 'notes', array('type' => 'textarea', 'maxlength' => '12')), 'at the limit');
		same(array(), $r->invoke($v, 'notes', array('type' => 'textarea', 'minlength' => '12')), 'at the minimum');
		$_POST = array('notes' => "aaaa\r\naaaa\r\naaa");
		same(array('maxlength'), $r->invoke($v, 'notes', array('type' => 'textarea', 'maxlength' => '12')), 'one over');
		$_POST = array('notes' => "aaaa\r\naaaa\r\na");
		same(array('minlength'), $r->invoke($v, 'notes', array('type' => 'textarea', 'minlength' => '12')), 'one under');
		// a lone CR, as old Macs sent it, is one character too
		$_POST = array('notes' => "aaaa\raaaa\raa");
		same(array(), $r->invoke($v, 'notes', array('type' => 'textarea', 'maxlength' => '12')), 'CR');
	} finally { $_POST = $saved; }
});

test('a required file input passes when the file arrived (#112)', function () {
	$v = validation::get();
	$r = new ReflectionMethod($v, 'check_field');
	$r->setAccessible(true);
	$saved = array($_POST, $_FILES);
	$file = function ($error) { return array('name' => 'cv.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php-cv', 'error' => $error, 'size' => $error === UPLOAD_ERR_OK ? 1200 : 0); };
	$one = template::instance()->constraints('<input type="file" name="cv" required>')['cv'];
	$many = template::instance()->constraints('<input type="file" name="photos[]" multiple required>')['photos'];
	try {
		$_POST = array();
		$_FILES = array('cv' => $file(UPLOAD_ERR_OK));
		same(array(), $r->invoke($v, 'cv', $one), 'attached');
		$_FILES = array('cv' => $file(UPLOAD_ERR_NO_FILE));
		same(array('required'), $r->invoke($v, 'cv', $one), 'sent without a file');
		$_FILES = array();
		same(array('required'), $r->invoke($v, 'cv', $one), 'no upload at all');
		// a file too big for the server never arrived
		$_FILES = array('cv' => $file(UPLOAD_ERR_INI_SIZE));
		same(array('required'), $r->invoke($v, 'cv', $one), 'too big');
		// photos[]: PHP lists each part of every file
		$_FILES = array('photos' => array('name' => array('a.jpg', ''), 'type' => array('image/jpeg', ''), 'tmp_name' => array('/tmp/php-a', ''), 'error' => array(UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE), 'size' => array(900, 0)));
		same(array(), $r->invoke($v, 'photos', $many), 'one of several');
		$_FILES = array('photos' => array('name' => array(''), 'type' => array(''), 'tmp_name' => array(''), 'error' => array(UPLOAD_ERR_NO_FILE), 'size' => array(0)));
		same(array('required'), $r->invoke($v, 'photos', $many), 'none of several');
		// without required, no file is fine
		$_FILES = array();
		same(array(), $r->invoke($v, 'cv', array('type' => 'file')), 'optional');
	} finally { list($_POST, $_FILES) = $saved; }
});

test('a number page field keeps its mock-up\'s decimals on every view (#92)', function () use ($db) {
	cms_store::connect();
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	test_fixture("$theme/zzfee.html");
	file_put_contents("$theme/zzfee.html", '<html><head><title>Fees</title></head><body><p>Corkage: <!-- print.cms.zz_corkage -->14.50<!-- /print.cms.zz_corkage --> lei</p></body></html>');
	$prod = function ($url) use ($db) {
		global $root;
		return (string)shell_exec('RASTER_DB='.escapeshellarg($db).' RASTER_ENV=production '.php_cmd().' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).' 2>&1');
	};
	try {
		$out = contact_render('/zzfee');
		check(strpos($out, 'Corkage: 14.50 lei') !== false, 'the first view: '.$out);
		$out = contact_render('/zzfee');
		check(strpos($out, 'Corkage: 14.50 lei') !== false, 'the second view: '.$out);
		cms_store::update_page('zzfeepage', '/zzfee', array('zz_corkage' => '4.5'), array('zz_corkage'));
		$out = contact_render('/zzfee');
		check(strpos($out, 'Corkage: 4.50 lei') !== false, 'after a save: '.$out);
		$out = $prod('/zzfee');
		check(strpos($out, 'Corkage: 4.50 lei') !== false, 'in production: '.$out);
	} finally {
		unlink("$theme/zzfee.html");
		R::exec('DROP TABLE IF EXISTS zzfeepage');
	}
});

test('a collection emptied by deleting its items stays empty (#91)', function () use ($db) {
	cms_store::connect();
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	test_fixture("$theme/zzgone.html");
	$view = function ($extra = '') use ($theme) {
		file_put_contents("$theme/zzgone.html", '<html><head><title>Gone</title></head><body><ul><!-- render.cms.zzgone --><li><!-- print.title -->Sample evening<!-- /print.title -->'.$extra.'</li><!-- /render.cms.zzgone --></ul></body></html>');
	};
	$prod = function ($url) use ($db) {
		global $root;
		return (string)shell_exec('RASTER_DB='.escapeshellarg($db).' RASTER_ENV=production '.php_cmd().' '.escapeshellarg("$root/bin/raster").' render '.escapeshellarg($url).' 2>&1');
	};
	$view();
	try {
		// a new collection starts with its mock-up item
		$out = contact_render('/zzgone');
		check(strpos($out, '<li>Sample evening</li>') !== false, 'a new list: '.$out);
		same(1, (int)R::count('zzgonedata'));
		foreach (R::getCol('SELECT id FROM zzgonedata') as $id) cms_store::delete_item('zzgonedata', $id);
		$out = $prod('/zzgone');
		check(strpos($out, 'Sample evening') === false && strpos($out, '<ul></ul>') !== false, 'production shows nothing: '.$out);
		$out = contact_render('/zzgone');
		check(strpos($out, 'Sample evening') === false && strpos($out, '<ul></ul>') !== false, 'development shows nothing: '.$out);
		same(0, (int)R::count('zzgonedata'), 'and stores nothing');
		// an editor still gets the card to add the next one
		$out = contact_render('/zzgone', 'editor');
		check(strpos($out, 'data-raster-mockup') !== false && strpos($out, 'zzgone + new') !== false, 'the new-item card: '.$out);
		// a field added to the view of an emptied list is a column, with no item to fill
		$view('<span><!-- print.zz_where -->Upstairs<!-- /print.zz_where --></span>');
		$out = contact_render('/zzgone');
		check(strpos($out, 'Sample evening') === false && strpos($out, '[exit 0]') !== false, $out);
		check(array_key_exists('zz_where', cms_store::columns('zzgonedata')), 'the new column');
		same(0, (int)R::count('zzgonedata'));
	} finally {
		unlink("$theme/zzgone.html");
		R::exec('DROP TABLE IF EXISTS zzgonedata');
	}
});

// ## P0.C: the framework prints no English (#38 #128)

// the starter's the_events.php for the length of $fn, with these settings
// added (it is read on every request, after the_app.php)
function p0c_settings($php, $fn) {
	$events = APPBASE.'config/the_events.php';
	$kept = file_get_contents($events);
	file_put_contents($events, (strpos($kept, '<?php') === 0 ? $kept : "<?php\n")."\n".$php."\n");
	try { return $fn(); } finally { file_put_contents($events, $kept); }
}
// a session cookie for a new account with this role
function p0c_login($base, $role) {
	cms_store::connect();
	$email = "zzp0c-$role@example.test";
	authentication::create_user($email, 'a long password', $role, ucfirst($role));
	list($status, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => $email, 'password' => 'a long password'));
	same(303, $status, "login as $role");
	return preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie:\s*PHPSESSID/i', $headers)));
}
// the body of a refusal without a view: the code, and no words
function p0c_bare($code) {
	return "<!doctype html><meta charset='utf-8'><title>$code</title><h1>$code</h1>";
}
function p0c_multipart($fields, $file = null) {
	$boundary = 'zzp0c'.bin2hex(random_bytes(4));
	$body = '';
	foreach ($fields as $name => $value) $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
	if ($file) $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"{$file[0]}\"\r\nContent-Type: image/png\r\n\r\n{$file[1]}\r\n";
	return array($body."--$boundary--\r\n", "Content-Type: multipart/form-data; boundary=$boundary");
}

test('P0.C: without a view, a refusal is the bare status with no words (#38)', function () use ($base, $root) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp0c_staff.html");
	file_put_contents("$theme/zzp0c_staff.html", '<!doctype html><html><head><title>Rota</title></head><body><p>Rota</p></body></html>');
	try {
		p0c_settings("config::set('protected')->to(array('account' => 'member', 'zzp0c_staff' => 'editor'));", function () use ($base) {
			list($status, $body) = form_post("$base/about", array('email' => 'a@b.co'), array('Origin: https://evil.example'));
			same(array(403, p0c_bare(403)), array($status, $body), 'a post from another site');
			list($status, $body) = http('GET', "$base/zzp0c-nothing-here");
			same(array(404, p0c_bare(404)), array($status, $body), 'a 404');
			list($status, $body) = http('GET', "$base/application/config/the_app.php");
			same(array(403, p0c_bare(403)), array($status, $body), 'a private file (index.php)');
			$member = p0c_login($base, 'member');
			list($status, $body) = http('GET', "$base/zzp0c_staff", null, array("Cookie: $member"));
			same(array(403, p0c_bare(403)), array($status, $body), 'a page the account may not open');
			list($status, $body) = form_post("$base/about", array('email' => 'a@b.co'), array("Cookie: $member"));
			same(array(403, p0c_bare(403)), array($status, $body), 'a logged in post without the token');
			// /api answers the alert as JSON, for the editor to word
			list($status, $body) = form_post("$base/api/cms/editor_save_field", array('type' => 'aboutpage'), array('Origin: https://evil.example'));
			same(array(403, array('error' => 'cross_site', 'problems' => array('cross_site'))), array($status, json_decode($body, true)), $body);
			list($status, $body) = form_post("$base/api/cms/editor_save_field", array('type' => 'aboutpage'));
			same(array(403, array('error' => 'not_allowed', 'problems' => array('not_allowed'))), array($status, json_decode($body, true)), $body);
			list($status, $body) = form_post("$base/api/cms/editor_save_field", array('type' => 'aboutpage'), array("Cookie: $member"));
			same(array(403, array('error' => 'form_expired', 'problems' => array('form_expired'))), array($status, json_decode($body, true)), $body);
		});
	} finally {
		@unlink("$theme/zzp0c_staff.html");
	}
});

test('P0.C: a site words its 403 and 500 pages with a view, an alert for each refusal (#38)', function () use ($base, $root, $b_db) {
	$theme = "$root/application/views/default";
	$alerts = '';
	foreach (array('cross_site' => 'zz from elsewhere', 'form_expired' => 'zz open too long', 'not_allowed' => 'zz not for your account') as $name => $words) {
		$alerts .= "<!-- print.validation.alert('$name') --><p>$words</p><!-- /print.validation.alert('$name') -->";
	}
	$views = array(
		'zzp0c_staff.html' => '<!doctype html><html><head><title>Rota</title></head><body><p>Rota</p></body></html>',
		// a form on the 403 page never sees the post that was refused
		'zzp0c_403.html' => '<!doctype html><html><head><title>No</title></head><body><h1>zz our own 403</h1>'.$alerts
			.'<!-- print.if.logged_in --><p>zz signed in</p><!-- /print.if.logged_in --><!-- print.if.logged_out --><p>zz signed out</p><!-- /print.if.logged_out -->'
			.'<!-- print.if.live --><p>zz live</p><!-- /print.if.live -->'
			.'<!-- render.newsletter.signup --><form method="post"><input type="email" name="email" required><button>Join</button></form><!-- /render.newsletter.signup --></body></html>',
		'zzp0c_500.html' => '<!doctype html><html><head><title>Oops</title></head><body><h1>zz our own 500</h1></body></html>',
		'zzp0c_broken.html' => '<!doctype html><html><head><title>Broken</title></head><body><p><!-- render.cms.zzp0cnope -->unclosed</p></body></html>',
	);
	foreach ($views as $file => $html) { test_fixture("$theme/$file"); file_put_contents("$theme/$file", $html); }
	try {
		p0c_settings("config::set('protected')->to(array('account' => 'member', 'zzp0c_staff' => 'editor'));\nconfig::set('error_document_403')->to('zzp0c_403');\nconfig::set('error_document_500')->to('zzp0c_500');", function () use ($base, $b_db, $theme) {
			$shows = function ($body, $only) {
				check(strpos($body, '<h1>zz our own 403</h1>') !== false, $body);
				foreach (array('zz from elsewhere', 'zz open too long', 'zz not for your account') as $words) same($words === $only, strpos($body, $words) !== false, "$words in $body");
				check(strpos($body, 'Forbidden') === false && strpos($body, 'access to this page') === false && strpos($body, 'has expired') === false, $body);
			};
			list($status, $body) = form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'zzp0c-refused@example.test'), array('Origin: https://evil.example'));
			same(403, $status);
			$shows($body, 'zz from elsewhere');
			check(strpos($body, '<p>zz live</p>') !== false, 'if.live, refused before the route was found: '.$body);
			cms_store::connect();
			same(0, (int)R::count('subscriber', ' email = ? ', array('zzp0c-refused@example.test')), 'the refused post signed nobody up');
			$member = p0c_login($base, 'member');
			list($status, $body) = form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'zzp0c-refused@example.test'), array("Cookie: $member"));
			same(403, $status);
			$shows($body, 'zz open too long');
			same(0, (int)R::count('subscriber', ' email = ? ', array('zzp0c-refused@example.test')));
			list($status, $body) = http('GET', "$base/zzp0c_staff", null, array("Cookie: $member"));
			same(403, $status);
			$shows($body, 'zz not for your account');
			// the flags the page had stay on its 403 page
			check(strpos($body, '<p>zz signed in</p>') !== false && strpos($body, 'zz signed out') === false && strpos($body, '<p>zz live</p>') !== false, 'the member\'s flags: '.$body);
			// a page that breaks in production: the site's 500 page, no details
			list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $b_db));
			list($status, $body) = http('GET', "$prod/zzp0c_broken");
			same(500, $status);
			check(strpos($body, '<h1>zz our own 500</h1>') !== false && strpos($body, 'zzp0cnope') === false && strpos($body, 'could not be shown') === false, $body);
			// and a 500 page that breaks too is the bare status
			file_put_contents("$theme/zzp0c_500.html", '<p><!-- render.cms.zzp0cnope -->unclosed</p>');
			list($status, $body) = http('GET', "$prod/zzp0c_broken");
			same(array(500, p0c_bare(500)), array($status, $body));
		});
	} finally {
		foreach (array_keys($views) as $file) @unlink("$theme/$file");
	}
});

test('P0.C: the login page words why nobody can log in, from alerts login_message raises', function () use ($base, $root) {
	$fresh = test_db(sys_get_temp_dir().'/raster-p0c-'.getmypid().'.sqlite');
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($fresh).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply 2>&1');
	try {
		list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $fresh));
		list($status, $body) = http('GET', "$prod/login");
		same(200, $status);
		check(strpos($body, '<p class="notice">There are no users yet.') !== false, 'the starter words no_users: '.$body);
		check(strpos($body, 'No database is configured') === false, $body);
	} finally {
		if (strpos($fresh, 'mysql://') !== 0) array_map('unlink', glob("$fresh*") ?: array());
	}
	// the model raises them and prints nothing
	$source = file_get_contents(BASE.'models/cms/cms.php');
	check(strpos($source, "raise('no_users')") !== false && strpos($source, "raise('no_database')") !== false && strpos($source, 'There are no users') === false, 'cms.php');
});

test('P0.C: off is identical: the login page with accounts, and a page that passes the checks, are as before', function () use ($base, $root) {
	cms_store::connect();
	authentication::create_user('zzp0c-member@example.test', 'a long password', 'member', 'Member');
	$theme = "$root/application/views/default";
	// the login view as 2.2.45 had it: the alerts this package added taken out
	$view = file_get_contents("$theme/login.html");
	$old = preg_replace("#<!-- print\.validation\.alert\('no_(database|users)'\) -->.*?<!-- /print\.validation\.alert\('no_(database|users)'\) -->#", '', $view);
	check($old !== $view && strpos($old, '<!-- print.cms.login_message /-->') !== false, 'the alerts are in the starter\'s login.html');
	// the same URL renders both, so whatever earlier tests stored (the site's
	// name, accounts) is the same for each
	$clean = function ($html) { return preg_replace('/name="raster_ts" value="[^"]*"/', '', $html); };
	file_put_contents("$theme/login.html", $old);
	try {
		list(, $before) = http('GET', "$base/login");
	} finally {
		file_put_contents("$theme/login.html", $view);
	}
	list($status, $now) = http('GET', "$base/login");
	same(200, $status);
	same($clean($before), $clean($now), 'byte for byte, the stamp aside');
	// a post from this site, a mail with a <title>: untouched
	same(303, form_post("$base/about", array('raster_form' => 'newsletter.signup', 'email' => 'zzp0c-ok@example.test'))[0]);
	$dir = "$theme/_email";
	test_fixture("$dir/zzp0c_titled.html");
	file_put_contents("$dir/zzp0c_titled.html", '<html><head><title>Your table</title></head><body><p>Hi</p></body></html>');
	try {
		same(true, mail::send_view('_email/zzp0c_titled', 'ana@example.test'));
		check(strpos(last_mail()['raw'], "\r\nSubject: Your table\r\n") !== false, last_mail()['raw']);
	} finally {
		@unlink("$dir/zzp0c_titled.html");
	}
});

test('P0.C: a mail without a <title> takes the site\'s name, else the host, no English (#38)', function () use ($root) {
	cms_store::connect();
	$dir = "$root/application/views/default/_email";
	test_fixture("$dir/zzp0c_untitled.html");
	file_put_contents("$dir/zzp0c_untitled.html", '<html><body><p>Hi</p></body></html>');
	cms_types::ensure('sitepage', array('slug' => 'text', 'updated_at' => 'datetime', 'site_name' => 'text'));
	$last = (int)R::getCell('SELECT MAX(id) FROM sitepage');
	$host = config::get('host');
	config::set('host')->to('zzp0c.example');
	try {
		cms_store::update_page('sitepage', 'site', array('site_name' => 'Zz &amp; Bakery'), array('site_name'));
		same(true, mail::send_view('_email/zzp0c_untitled', 'ana@example.test'));
		check(strpos(last_mail()['raw'], "\r\nSubject: Zz & Bakery\r\n") !== false, last_mail()['raw']);
		cms_store::update_page('sitepage', 'site', array('site_name' => ''), array('site_name'));
		same(true, mail::send_view('_email/zzp0c_untitled', 'ana@example.test'));
		check(strpos(last_mail()['raw'], "\r\nSubject: zzp0c.example\r\n") !== false, last_mail()['raw']);
		check(strpos(file_get_contents(BASE.'models/mail/mail.php'), 'Message from') === false, 'no English subject');
	} finally {
		config::set('host')->to($host);
		R::exec('DELETE FROM sitepage WHERE id > ?', array($last));
		@unlink("$dir/zzp0c_untitled.html");
	}
});

test('P0.C: the editor\'s refusals are names it words, in Romanian too, and a site rewords them (#128)', function () use ($base, $root, $db) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp0c_menu.html");
	file_put_contents("$theme/zzp0c_menu.html", '<!doctype html><html><head><title>Menu</title></head><body><p><!-- print.cms.zzp0c_opens -->2026-10-05<!-- /print.cms.zzp0c_opens --></p>'
		.'<!-- render.cms.zzp0cmenu --><p><!-- print.name -->Tart<!-- /print.name --> <!-- print.price -->4.50<!-- /print.price --></p><!-- /render.cms.zzp0cmenu --></body></html>');
	$words = "$root/application/i18n/ro/raster_editor.php";
	$made = !is_dir(dirname($words));
	test_fixture($words);
	@mkdir(dirname($words), 0777, true);
	file_put_contents($words, "<?php\nreturn array('problem_upload_too_big' => 'zz poza noastră e prea mare');\n");
	try {
		p0c_settings("config::set('languages')->to(array('en', 'ro'));", function () use ($base, $root, $db) {
			$editor = p0c_login($base, 'editor');
			$page = http('GET', "$base/zzp0c_menu?lang=ro", null, array("Cookie: $editor"))[1];
			check(preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $page, $m), 'the editor: '.$page);
			$config = json_decode($m[1], true);
			$post = function ($endpoint, $fields) use ($base, $editor, $config) {
				list($status, $body) = form_post("$base/api/cms/$endpoint", $fields + array('csrf' => $config['csrf']), array("Cookie: $editor"));
				return array($status, json_decode($body, true), $body);
			};
			// a value that isn't of its field's type: type_<type>, with the field
			list($status, $json, $body) = $post('editor_save_item', array('collection' => 'zzp0cmenu', 'id' => 0, 'fields' => array('name' => 'Cheap tart', 'price' => 'cheap')));
			same(array(400, array('type_number'), 'price'), array($status, $json['problems'], $json['field']), $body);
			list($status, $json, $body) = $post('editor_save_field', array('type' => $config['page']['type'], 'slug' => $config['page']['slug'], 'field' => 'zzp0c_opens', 'value' => 'soon'));
			same(array(400, array('type_date'), 'zzp0c_opens'), array($status, $json['problems'], $json['field']), $body);
			// uploads
			$upload = function ($file, $url) use ($editor, $config) {
				list($body, $type) = p0c_multipart(array('csrf' => $config['csrf']), $file);
				list($status, $text) = http('POST', "$url/api/cms/editor_upload", $body, array("Cookie: $editor", $type));
				return array($status, json_decode($text, true));
			};
			same(array(400, array('error' => 'upload_missing', 'problems' => array('upload_missing'))), $upload(null, $base), 'no picture');
			same(array(400, array('error' => 'upload_not_a_picture', 'problems' => array('upload_not_a_picture'))), $upload(array('notes.png', '<?php echo 1;'), $base), 'not a picture');
			// over PHP's own limit, the file never arrives whole: too big, not missing
			$port = free_port();
			$small = proc_open(php_argv('-d', 'upload_max_filesize=1K', '-d', 'post_max_size=2K', '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root,
				array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'PATH' => getenv('PATH')));
			try {
				for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
				same(array(400, array('error' => 'upload_too_big', 'problems' => array('upload_too_big'))), $upload(array('big.png', str_repeat('x', 1400)), "http://127.0.0.1:$port"), 'too big');
				// over post_max_size PHP drops the whole post, the token too: still too big, not a stale page
				same(array(400, array('error' => 'upload_too_big', 'problems' => array('upload_too_big'))), $upload(array('huge.png', str_repeat('x', 4096)), "http://127.0.0.1:$port"), 'over post_max_size');
			} finally {
				proc_terminate($small); proc_close($small);
			}
			// each has its words: in English in editor.js, in Romanian in lang/ro.php, and the site's own win
			$js = file_get_contents(BASE.'models/cms/editor/editor.js');
			preg_match_all('/\b(problem_(?:type|upload|form|not)_[a-z_]+):/', $js, $names);
			$names = array_unique($names[1]);
			foreach (array('type_int', 'type_number', 'type_bool', 'type_date', 'type_datetime', 'type_time', 'type_text', 'upload_too_big', 'upload_missing', 'upload_not_a_picture', 'upload_not_stored', 'form_expired', 'not_allowed') as $name) {
				check(in_array("problem_$name", $names, true), "editor.js words problem_$name");
				check(isset($config['strings']["problem_$name"]), "the Romanian editor words problem_$name");
			}
			same('{field} trebuie să fie un număr, de exemplu 4.50', $config['strings']['problem_type_number']);
			same('zz poza noastră e prea mare', $config['strings']['problem_upload_too_big'], 'raster_editor.php rewords it');
			// "not a picture" goes through the words too
			check(strpos($js, "'not a picture'") === false && substr_count($js, "problem('upload_not_a_picture')") === 2, 'editor.js');
		});
	} finally {
		@unlink("$theme/zzp0c_menu.html");
		@unlink($words);
		if ($made) @rmdir(dirname($words));
		cms_store::connect();
		R::exec('DROP TABLE IF EXISTS zzp0cmenudata');
		R::exec('DROP TABLE IF EXISTS zzp0cmenupage');
	}
});

test('P0.C: a 503 page keeps its if.live block (#38)', function () use ($root) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp0c_503.html");
	file_put_contents("$theme/zzp0c_503.html", '<!doctype html><html><head><title>Back soon</title></head><body><h1>zz back soon</h1><!-- print.if.live --><p>zz call us</p><!-- /print.if.live --><!-- print.if.static --><p>zz static</p><!-- /print.if.static --></body></html>');
	$gone = test_db(sys_get_temp_dir().'/raster-p0c-gone-'.getmypid().'.sqlite');
	shell_exec('RASTER_ENV=production RASTER_DB='.escapeshellarg($gone).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' schema --apply 2>&1');
	test_db_break($gone);
	try {
		p0c_settings("config::set('error_document_503')->to('zzp0c_503');", function () use ($gone) {
			list($prod) = b_server(array('RASTER_ENV' => 'production', 'RASTER_DB' => $gone));
			list($status, $body) = http('GET', "$prod/about");
			same(503, $status, $body);
			check(strpos($body, '<h1>zz back soon</h1>') !== false && strpos($body, '<p>zz call us</p>') !== false && strpos($body, 'zz static') === false, $body);
		});
	} finally {
		@unlink("$theme/zzp0c_503.html");
		if (strpos($gone, 'mysql://') !== 0) array_map('unlink', glob("$gone*") ?: array());
	}
});

test('P0.C: system/ prints no English sentence to visitors (#38)', function () {
	// echo, exit and print of a quoted string with two words in a row; the
	// command line (system/tools) and the development-only pages are not
	// for visitors
	$found = array();
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE, FilesystemIterator::SKIP_DOTS)) as $file) {
		$path = $file->getPathname();
		if (substr($path, -4) !== '.php' || strpos($path, BASE.'tools/') === 0 || strpos($path, BASE.'libraries/') === 0) continue;
		foreach (file($path) as $i => $line) {
			if (!preg_match('/\b(echo|exit|print)\b\s*\(?\s*(["\'])(.*?)(?<!\\\\)\2/', $line, $m)) continue;
			// the words people read, tags aside
			if (!preg_match('/[A-Za-z]{2,} [A-Za-z]{2,}/', strip_tags($m[3]))) continue;
			// development only: the database error and the template errors
			if (strpos($line, 'No database') !== false || preg_match('/template errors|php bin\/raster lint|font:/i', $line)) continue;
			$found[] = substr($path, strlen(BASE)).':'.($i + 1).': '.trim($line);
		}
	}
	same(array(), $found);
});

// ## P0.A: lint, schema and the engine agree (#28 #36 #132 #133)

test('P0.A names: lint reads the engine\'s character classes (#28)', function () {
	$grammar = raster_inspector::grammar();
	same(template::$names, $grammar['names']);
	same(template::DRY_DEPTH, $grammar['dry_depth']);
	foreach (array('model', 'key', 'field', 'attribute', 'view', 'res') as $kind) same(template::$names[$kind], raster_inspector::name($kind), $kind);
	// each kind takes digits and dashes
	foreach (array('model' => 'zz-p0a2', 'key' => 'line-2', 'field' => 'line-2', 'attribute' => 'data-x2', 'view' => '_p0a-28b2/nav-2', 'res' => 'nav-2') as $kind => $name) {
		check((bool)preg_match('/^'.template::$names[$kind].'+$/', $name), "$kind: $name");
	}
	// a key inside a render block: the attribute forms and colons too
	foreach (array('line2', '@data-x.key', '@aria-label.key', '+class.state-2', '@xml:lang.lang2') as $key) {
		check((bool)preg_match('/^'.template::$names['key'].'+$/', $key), $key);
	}
	same(array('key' => 'line-2', 'attribute' => 'data-x2', 'append' => false, 'builtin' => false), raster_inspector::data_key('@data-x2.line-2'));
	same(array('model' => 'zzp0a2', 'method' => 'rows'), raster_inspector::model_reference('zzp0a2.rows'));
});

test('P0.A names: row keys and attributes with digits and dashes print, nested too (#28)', function () {
	$app = APPBASE.config::get('models_path', 'models');
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	temp_model("$app/zzp0a2", array('zzp0a2.php' => "<?php\nclass zzp0a2\n{\n\tfunction rows() {\n\t\treturn array(array('line2' => 'L2', 'line-3' => 'L3', 'a:b' => 'AB', 'kids-2' => array(array('line2' => 'K2', 'line-3' => 'K3'))));\n\t}\n}\n"));
	test_fixture("$theme/zzp0arows.html");
	file_put_contents("$theme/zzp0arows.html", '<html><head><title>Rows</title></head><body><ul><!-- render.zzp0a2.rows --><li>'
		.'<!-- print.line2 -->m2<!-- /print.line2 --> <!-- print.line-3 -->m3<!-- /print.line-3 -->'
		.'<!-- print.@data-x.line2 --><b data-x="mock">b</b><!-- /print.@data-x.line2 -->'
		.'<!-- print.@aria-label.line-3 --><i aria-label="mock">i</i><!-- /print.@aria-label.line-3 -->'
		.'<!-- print.@xml:lang.line2 --><q xml:lang="en">q</q><!-- /print.@xml:lang.line2 -->'
		.'<!-- print.+class.line-3 --><s class="a">s</s><!-- /print.+class.line-3 -->'
		.'<!-- print.kids-2 --><em><!-- print.line2 -->k2<!-- /print.line2 -->|<!-- print.@data-x.line-3 --><u data-x="mock">u</u><!-- /print.@data-x.line-3 --></em><!-- /print.kids-2 -->'
		.'</li><!-- /render.zzp0a2.rows --></ul></body></html>');
	test_fixture("$theme/zzp0acolon.html");
	file_put_contents("$theme/zzp0acolon.html", '<html><head><title>Colon</title></head><body><!-- render.zzp0a2.rows --><p><!-- print.a:b -->mock<!-- /print.a:b --></p><!-- /render.zzp0a2.rows --></body></html>');
	controller::forget_models();
	try {
		same(array(), (new raster_inspector())->lint_file('zzp0arows.html'), 'lint passes it');
		// a plain key with a colon is no key: lint says so, and the engine
		// keeps it as 2.2.45 did
		check(has_problem((new raster_inspector())->lint_file('zzp0acolon.html'), "'a:b' is never printed"), 'lint');
		// in production, where template errors don't stop the page
		global $root, $db;
		$page = (string)shell_exec('RASTER_DB='.escapeshellarg($db).' RASTER_ENV=production '.php_cmd().' '.escapeshellarg("$root/bin/raster").' render /zzp0acolon 2>&1');
		check(strpos($page, 'AB') === false && strpos($page, '<!-- print.a:b -->mock<!-- /print.a:b -->') !== false, 'never printed: '.$page);
		$page = contact_render('/zzp0arows');
		check(strpos($page, '[exit 0]') !== false, $page);
		check(strpos($page, '<li>L2 L3<b data-x="L2">b</b><i aria-label="L3">i</i><q xml:lang="L2">q</q><s class="a L3">s</s><em>K2|<u data-x="K3">u</u></em></li>') !== false, 'every key and attribute set: '.$page);
		check(strpos($page, 'mock') === false && strpos($page, '<!-- print.') === false, 'no mock-up and no tags left: '.$page);
	} finally {
		@unlink("$theme/zzp0arows.html");
		@unlink("$theme/zzp0acolon.html");
		remove_dir("$app/zzp0a2");
		controller::forget_models();
	}
});

test('P0.A names: lint reports a row key the engine never prints (#28)', function () {
	check(has_problem(lint_html('<!-- render.cms.zzp0akeys --><p><!-- print.na!me -->x<!-- /print.na!me --></p><!-- /render.cms.zzp0akeys -->'), "'na!me' is never printed"));
	same(array(), lint_html('<!-- render.cms.zzp0akeys --><p><!-- print.name2 -->x<!-- /print.name2 --></p><!-- /render.cms.zzp0akeys -->'));
});

test('P0.A names: dry and res names with digits and dashes insert their fragment (#28)', function () {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	test_fixture("$theme/_zzp0a28b2.html");
	test_fixture("$theme/zzp0adry.html");
	file_put_contents("$theme/_zzp0a28b2.html", '<!-- res.nav2 --><nav>Second nav</nav><!-- /res.nav2 --><!-- res.side-3 --><aside>Side three</aside><!-- /res.side-3 -->');
	file_put_contents("$theme/zzp0adry.html", '<html><head><title>Dry</title></head><body><!-- dry._zzp0a28b2.nav2 /--><!-- dry._zzp0a28b2.side-3 /--><!-- res.own-2 --><p>Own</p><!-- /res.own-2 --></body></html>');
	try {
		same(array(), (new raster_inspector())->lint_file('zzp0adry.html'), 'lint passes it');
		$page = contact_render('/zzp0adry');
		check(strpos($page, '<body><nav>Second nav</nav><aside>Side three</aside><p>Own</p></body>') !== false, 'both fragments, and the res tags gone: '.$page);
	} finally {
		@unlink("$theme/_zzp0a28b2.html");
		@unlink("$theme/zzp0adry.html");
	}
});

test('P0.A nested dry: the page shows the field lint and schema list, five levels deep (#36)', function () {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	foreach (array('_zzp0aouter.html', '_zzp0ainner.html', '_zzp0achain.html', 'zzp0anest.html', 'zzp0achain.html') as $view) test_fixture("$theme/$view");
	file_put_contents("$theme/_zzp0aouter.html", '<!-- res.box --><div class="box"><!-- dry._zzp0ainner.deep /--></div><!-- /res.box -->');
	file_put_contents("$theme/_zzp0ainner.html", '<!-- res.deep --><p><!-- print.cms.zz_deep_field -->Deep text<!-- /print.cms.zz_deep_field --></p><!-- /res.deep -->');
	file_put_contents("$theme/zzp0anest.html", '<html><head><title>Nest</title></head><body><!-- dry._zzp0aouter.box /--></body></html>');
	// six levels: the sixth stays a comment, in the page and in the content model
	$chain = '';
	for ($i = 1; $i <= 6; $i++) $chain .= "<!-- res.l$i --><i><!-- print.cms.zz_l$i -->level $i<!-- /print.cms.zz_l$i --></i>".($i < 6 ? '<!-- dry._zzp0achain.l'.($i + 1).' /-->' : '')."<!-- /res.l$i -->";
	file_put_contents("$theme/_zzp0achain.html", $chain);
	file_put_contents("$theme/zzp0achain.html", '<html><head><title>Chain</title></head><body><!-- dry._zzp0achain.l1 /--></body></html>');
	try {
		$inspector = new raster_inspector();
		same(array(), $inspector->lint_file('zzp0anest.html'), 'lint passes it');
		$fields = array();
		foreach ($inspector->content_model()['pages'] as $page) $fields[$page['view']] = array_keys($page['fields']);
		same(array('zz_deep_field'), $fields['zzp0anest.html'], 'schema lists the field');
		same(array('zz_l1', 'zz_l2', 'zz_l3', 'zz_l4', 'zz_l5'), $fields['zzp0achain.html'], 'five levels');
		$page = contact_render('/zzp0anest');
		check(strpos($page, '<div class="box"><p>Deep text</p></div>') !== false, 'the page shows it: '.$page);
		check(strpos($page, 'dry.') === false && strpos($page, 'res.') === false, 'no tags left: '.$page);
		$page = contact_render('/zzp0achain');
		check(strpos($page, '<i>level 1</i><i>level 2</i><i>level 3</i><i>level 4</i><i>level 5</i><!-- dry._zzp0achain.l6 /-->') !== false, 'five levels and the sixth as a comment: '.$page);
		// and the editor's MCP sees the same field
		same('Deep text', mcp_call('get_page', array('page' => '/zzp0anest'))['structuredContent']['fields']['zz_deep_field']);
	} finally {
		foreach (array('_zzp0aouter.html', '_zzp0ainner.html', '_zzp0achain.html', 'zzp0anest.html', 'zzp0achain.html') as $view) @unlink("$theme/$view");
		cms_store::connect();
		foreach (array('/zzp0anest', '/zzp0achain') as $slug) R::exec('DROP TABLE IF EXISTS '.cms::page_type($slug));
	}
});

test('P0.A one level of dry renders as it did: no second pass without a dry in the fragment', function () {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	test_fixture("$theme/_zzpaone.html");
	test_fixture("$theme/zzpaone.html");
	// a res inside the fragment kept its marks in 2.2.45, and still does
	file_put_contents("$theme/_zzpaone.html", '<!-- res.outer --><div><!-- res.inner --><b>x</b><!-- /res.inner --></div><!-- /res.outer -->');
	file_put_contents("$theme/zzpaone.html", '<html><head><title>One</title></head><body><!-- dry._zzpaone.outer /--></body></html>');
	// one level further down, the fragment's marks stay the same way
	test_fixture("$theme/zzpatwo.html");
	file_put_contents("$theme/_zzpaone.html", file_get_contents("$theme/_zzpaone.html").'<!-- res.wrap --><main><!-- res.mark --><i>m</i><!-- /res.mark --><!-- dry._zzpaone.outer /--></main><!-- /res.wrap -->');
	file_put_contents("$theme/zzpatwo.html", '<html><head><title>Two</title></head><body><!-- dry._zzpaone.wrap /--></body></html>');
	try {
		$page = contact_render('/zzpaone');
		check(strpos($page, '<body><div><!-- res.inner --><b>x</b><!-- /res.inner --></div></body>') !== false, $page);
		$page = contact_render('/zzpatwo');
		check(strpos($page, '<body><main><!-- res.mark --><i>m</i><!-- /res.mark --><div><!-- res.inner --><b>x</b><!-- /res.inner --></div></main></body>') !== false, $page);
	} finally {
		@unlink("$theme/_zzpaone.html");
		@unlink("$theme/zzpaone.html");
		@unlink("$theme/zzpatwo.html");
	}
});

test('P0.A slugs: _page and _items are list segments only where the router serves the list (#132)', function () {
	same('/landing_page', cms::slug_for_uri('/landing_page'));
	same('/landing_items', cms::slug_for_uri('/landing_items/'));
	// a list URL is one route() sends to a view that renders the list:
	// docs.html doesn't exist, so /docs/news_page is docs/news_page.html's
	same('/docs/news_page', cms::slug_for_uri('/docs/news_page'));
	same('/news', cms::slug_for_uri('/news/news_page/2'));
	same('/news', cms::slug_for_uri('/news/news_items/tag/x'));
	same('/news', cms::slug_for_uri('/news/news_page'));
	same('/news', cms::slug_for_uri('/news/news_items'));
	same('/news', cms::slug_for_uri('/news_page/2'));
	// about.html renders no news list
	same('/about/news_page/2', cms::slug_for_uri('/about/news_page/2'));
	$inspector = new raster_inspector();
	foreach (array('landing_page.html' => '/landing_page', 'landing_items.html' => '/landing_items', 'index.html' => '/', 'about.html' => '/about', 'docs/setup.html' => '/docs/setup') as $view => $url) {
		same(cms::slug_for_uri($url), $inspector->slug_for_view($view), $view);
	}
	same('/news/news_item', $inspector->slug_for_view('news_item.html'));
});

test('P0.A landing.html and landing_page.html keep their own values, and MCP edits the right one (#132)', function () use ($base) {
	$theme = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default');
	test_fixture("$theme/zzland.html");
	test_fixture("$theme/zzland_page.html");
	file_put_contents("$theme/zzland.html", '<html><head><title>Landing</title></head><body><p><!-- print.cms.zz_pitch -->Come for the coffee<!-- /print.cms.zz_pitch --></p></body></html>');
	file_put_contents("$theme/zzland_page.html", '<html><head><title>Landing page</title></head><body><p><!-- print.cms.zz_pitch -->Stay for the cake<!-- /print.cms.zz_pitch --></p></body></html>');
	try {
		$pages = array();
		foreach ((new raster_inspector())->content_model()['pages'] as $page) $pages[$page['view']] = $page;
		same(cms::page_type(cms::slug_for_uri('/zzland_page')), $pages['zzland_page.html']['type'], 'schema names the table the page reads');
		check($pages['zzland_page.html']['type'] !== $pages['zzland.html']['type'], 'two tables');
		check(strpos(http('GET', "$base/zzland")[1], '<p>Come for the coffee</p>') !== false, '/zzland');
		check(strpos(http('GET', "$base/zzland_page")[1], '<p>Stay for the cake</p>') !== false, '/zzland_page');
		$saved = mcp_call('update_page', array('page' => '/zzland_page', 'fields' => array('zz_pitch' => 'Edited by the agent')));
		same('Edited by the agent', $saved['structuredContent']['fields']['zz_pitch']);
		check(strpos(http('GET', "$base/zzland_page")[1], '<p>Edited by the agent</p>') !== false, 'the edit shows');
		check(strpos(http('GET', "$base/zzland")[1], '<p>Come for the coffee</p>') !== false, 'the other page keeps its own');
		same('Come for the coffee', mcp_call('get_page', array('page' => '/zzland'))['structuredContent']['fields']['zz_pitch']);
		// a list's URLs with nothing after the segment are still the list's page
		$heading = mcp_call('get_page', array('page' => '/news'))['structuredContent']['fields']['heading'];
		mcp_call('update_page', array('page' => '/news', 'fields' => array('heading' => 'Zz news heading')));
		foreach (array('/news', '/news/news_page', '/news/news_page/2', '/news/news_items') as $url) {
			check(strpos(http('GET', $base.$url)[1], 'Zz news heading') !== false, "$url shows the edit");
		}
		mcp_call('update_page', array('page' => '/news', 'fields' => array('heading' => $heading)));
	} finally {
		@unlink("$theme/zzland.html");
		@unlink("$theme/zzland_page.html");
		cms_store::connect();
		foreach (array('/zzland', '/zzland_page') as $slug) R::exec('DROP TABLE IF EXISTS '.cms::page_type($slug));
	}
});

test('P0.A a routed view of another theme is linted, gets its table and MCP edits it (#133)', function () use ($root, $base) {
	$views = APPBASE.config::get('views_path', 'views');
	$routes = APPBASE.'config/the_routes.php';
	$original = file_get_contents($routes);
	test_fixture("$views/zzp0aprint");
	@mkdir("$views/zzp0aprint");
	$view = "$views/zzp0aprint/zzmenu.html";
	$good = '<html><head><title>Print</title></head><body><h1><!-- print.cms.zz_print_title -->Print title<!-- /print.cms.zz_print_title --></h1></body></html>';
	file_put_contents($view, str_replace('</h1>', '</h1><!-- print.site.zz_no_such_thing /-->', $good));
	file_put_contents("$views/zzp0aprint/zzlist.html", '<html><head><title>List</title></head><body><ul><!-- render.cms.zzp0alist --><li><!-- print.name -->Soup<!-- /print.name --></li><!-- /render.cms.zzp0alist --></ul></body></html>');
	file_put_contents($routes, $original."\ncontroller::route('zzp0aprint/menu')->to('zzmenu')->from('zzp0aprint');\ncontroller::route('zzp0aprint/list')->to('zzlist')->from('zzp0aprint');\n");
	$db = test_db(sys_get_temp_dir().'/raster-p0a-routed-'.getmypid().'.sqlite');
	$raster = function ($args, $env = '') use ($root, $db) {
		exec(trim($env.' RASTER_DB='.escapeshellarg($db)).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' '.$args.' 2>&1', $out, $code);
		return array($code, implode("\n", $out));
	};
	try {
		// lint, MCP lint_templates and describe read the routed theme by default
		list($code, $out) = $raster('lint');
		same(1, $code, $out);
		check(strpos($out, 'zzp0aprint/zzmenu.html') !== false && strpos($out, "no public method 'zz_no_such_thing'") !== false, $out);
		check(strpos($out, "themes 'default', 'zzp0aprint'") !== false, $out);
		check(mcp_call('lint_templates')['structuredContent']['errors'] > 0, 'lint_templates');
		list(, $out) = $raster('describe --json --sections=lint');
		check(json_decode($out, true)['lint']['errors'] > 0, 'describe: '.$out);
		// --theme is that theme alone
		same(0, $raster('lint --theme=default')[0]);
		file_put_contents($view, $good);
		same(0, $raster('lint')[0], 'clean once fixed');
		// schema knows the page, and --apply makes its table in production
		$table = cms::page_type('/zzp0aprint/menu');
		list($code, $out) = $raster('schema --apply', 'RASTER_ENV=production');
		same(0, $code, $out);
		list($code, $out) = $raster('schema --json', 'RASTER_ENV=production');
		$status = json_decode($out, true);
		$tables = array();
		foreach ($status['tables'] as $t) $tables[$t['table']] = $t;
		check(isset($tables[$table]) && $tables[$table]['exists'], "$table: ".$out);
		check(!in_array($table, $status['unused_tables'], true), 'not unused');
		same(0, $raster('schema --check', 'RASTER_ENV=production')[0], 'no drift');
		check(test_pdo($db)->query("SELECT COUNT(*) FROM $table") !== false, 'the table is there');
		list(, $out) = $raster('describe --json --sections=pages');
		$described = array();
		foreach (json_decode($out, true)['pages'] as $page) $described[$page['url']] = $page;
		same('zzp0aprint', $described['/zzp0aprint/menu']['theme']);
		// a routed view with only a list is a page too, and lists its collection
		same('zzp0aprint', $described['/zzp0aprint/list']['theme']);
		$model = (new raster_inspector())->content_model();
		same(array('zzp0aprint/zzlist.html'), $model['collections']['zzp0alist']['views']);
		// MCP finds a routed page by its address, never by its view's name
		check(!empty(mcp_call('get_page', array('page' => 'zzmenu'))['isError']), 'zzmenu is the site theme\'s /zzmenu, which is no page');
		check(!empty(mcp_call('get_page', array('page' => '/zzmenu'))['isError']), '/zzmenu');
		same($table, $described['/zzp0aprint/menu']['table']);
		// MCP reads and edits it, and the page shows the edit
		same('Print title', mcp_call('get_page', array('page' => '/zzp0aprint/menu'))['structuredContent']['fields']['zz_print_title']);
		same('Printed menu', mcp_call('update_page', array('page' => '/zzp0aprint/menu', 'fields' => array('zz_print_title' => 'Printed menu')))['structuredContent']['fields']['zz_print_title']);
		check(strpos(http('GET', "$base/zzp0aprint/menu")[1], '<h1>Printed menu</h1>') !== false, 'the page shows it');
	} finally {
		file_put_contents($routes, $original);
		@unlink($view);
		@unlink("$views/zzp0aprint/zzlist.html");
		@rmdir("$views/zzp0aprint");
		cms_store::connect();
		R::exec('DROP TABLE IF EXISTS '.cms::page_type('/zzp0aprint/menu'));
		if (strpos($db, 'mysql://') !== 0) array_map('unlink', glob("$db*") ?: array());
	}
});

// ## P0.B: one place parses collection and item URLs (#40 #37 #33)

$p0b_events = array();
function test_p0b_event($payload) {
	global $p0b_events;
	$p0b_events[] = $payload['collection'];
}

test('P0.B collection URLs: one parser, and what it reads', function () {
	same(array('path' => '/news', 'name' => 'news', 'kind' => 'page', 'rest' => array('2')), cms::collection_uri('/news/news_page/2'));
	same(array('path' => '/news', 'name' => 'news', 'kind' => 'items', 'rest' => array('tag', 'php', 'news_page', '2')), cms::collection_uri('/news/news_items/tag/php/news_page/2'));
	same(array('path' => '/', 'name' => 'landing', 'kind' => 'page', 'rest' => array()), cms::collection_uri('/landing_page'));
	same(null, cms::collection_uri('/about'));
	// a list is on a page: the first segment counts last, as cms::route() and slug_for_uri() read it (#132)
	same(array('path' => '/landing_page', 'name' => 'landing', 'kind' => 'page', 'rest' => array('2')), cms::collection_uri('/landing_page/landing_page/2'));
	same(2, cms::collection_request('landing', '/landing_page/landing_page/2')['page']);
	same(null, cms::collection_uri('/'));
	// a slug or a filter value after it is not read as another list
	same('news', cms::collection_uri('/news/news_items/tag/my_page')['name']);
	same(array('item' => 'raster-runs', 'filters' => array(), 'page' => 0), cms::collection_request('news', '/news/news_item/raster-runs'));
	same(array('item' => null, 'filters' => array('tag' => 'php'), 'page' => 2), cms::collection_request('news', '/news/news_items/tag/php/news_page/2'));
	// a page is the list's wherever it shows; an item and filters are under its own name
	same(array('item' => null, 'filters' => array(), 'page' => 2), cms::collection_request('news', '/archive/news_page/2'));
	same(array('item' => null, 'filters' => array(), 'page' => 0), cms::collection_request('news', '/archive/news_item/3'));
	same(array('item' => null, 'filters' => array(), 'page' => 0), cms::collection_request('events', '/news/news_item/3'));
	same('/news/news_item', cms::item_path('news'));
	same('/team_members/team_members_item/{slug}', cms::item_path('team_members', '{slug}'));
	same('/', cms::page_path('index.html'));
	same('/docs/setup', cms::page_path('docs/setup.html'));
	same(null, cms::page_path('_layout.html'));
	same(null, cms::page_path('_email/welcome.html'));
	same(null, cms::page_path('news.rss'));
});

// the parsers of 2.2.45 (slug_for_uri as P0.A left it), kept here to prove the one parser reads every
// address the site's links spell as they did
test('P0.B off is identical: the old copies and the one parser agree', function () {
	$segments = function ($uri) {
		$s = explode('/', rtrim($uri, '/'));
		array_shift($s);
		return $s ?: array('');
	};
	$param = function ($s, $name, $v = false) {
		$value = false;
		if (in_array($name, $s) && array_key_exists(array_search($name, $s) + 1, $s)) $value = $s[array_search($name, $s) + 1];
		return $value ?: $v;
	};
	$old_slug = function ($uri) {
		$slug = '/'.trim(preg_replace('#/+#', '/', (string)$uri), '/');
		if ($slug === '/' || $slug === '/'.config::get('default_view', 'index')) return 'home';
		// as P0.A left it: a list URL is its view's page when the view renders the list
		if (preg_match('#^/(.+?)/([a-z0-9_]+)_(page|items)(/.*)?$#', $slug, $m) && cms::renders_collection($m[1], $m[2])) {
			$slug = '/'.$m[1];
		} elseif (preg_match('#^/([a-z0-9_]+)_(page|items)/.+$#', $slug, $m)) {
			$slug = '/'.$m[1];
		} elseif (preg_match('#^(?:/.*?)?/([a-z0-9_]+)_item(/.*)?$#', $slug, $m)) {
			$slug = file_exists(controller::build_view_path($m[1].'_item')) ? '/'.$m[1].'/'.$m[1].'_item' : '/'.$m[1];
		}
		return $slug;
	};
	$old_route = function ($uri) {
		if (!preg_match('#^/(.+?)/([a-z0-9_]+)_(item|items|page)(/|$)#', $uri, $m)) return null;
		return array($m[1], $m[2], $m[3]);
	};
	$old_canonical = function ($segments, $name) {
		$segments[count($segments) - 1] = preg_replace('/\.(rss|atom|xml|json|txt)$/', '', (string)end($segments));
		$page = function ($value) { return (bool)preg_match('/^[1-9][0-9]*$/', $value) && $value !== '1'; };
		foreach ($segments as $i => $segment) {
			$rest = array_slice($segments, $i + 1);
			if ($segment === $name.'_page') return count($rest) === 1 && $page($rest[0]);
			if ($segment === $name.'_item') return count($rest) === 1 && (!ctype_digit($rest[0]) || $rest[0] === (string)(int)$rest[0]);
			if ($segment === $name.'_items') {
				if (count($rest) % 2) return false;
				$seen = array();
				for ($j = 0; $j < count($rest); $j += 2) {
					if (isset($seen[$rest[$j]])) return false;
					$seen[$rest[$j]] = true;
					if ($rest[$j] === $name.'_page') return $j + 2 === count($rest) && $page($rest[$j + 1]);
				}
				return true;
			}
		}
		return true;
	};
	$urls = array('/', '/index', '/about', '/docs/setup', '/news', '/news/news_page/2', '/news/news_page/02', '/news/news_page/1', '/news/news_page',
		'/news/news_items/tag/php', '/news/news_items/tag/php/news_page/2', '/news/news_items/headline/Filler%201', '/news/news_items/tag/php/tag/js',
		'/news/news_items/tag', '/news/news_item/3', '/news/news_item/007', '/news/news_item/raster-runs', '/news/news_item', '/news/news_item/a/b',
		'/landing_page', '/landing_items/', '/docs/news_page', '/archive/news_page/2', '/events/events_item/jazz-night', '/events/events_items/date/2026-10-10',
		'/menu/menu_items/price/14.50', '/news/news_page/2.rss', '/journal/journal_page/2.rss', '/team_members/team_members_item/ada', '/news_page/2',
		// a page with two paged lists: the pager keeps the other list's page in its links
		'/news/news_page/2/events_page/2', '/news/events_page/2/news_page/2', '/events/events_item/jazz/news_page/2', '/news/news_items/tag/php/events_page/3',
		'/news/news_items/tag/php/news_page/2/events_page/3', '/events/events_page/3/news_page/2', '/news/news_item/3/events_page/2', '/menu/news_page/2/menu_items/price/4');
	$uri_segments = config::get('uri_segments');
	try {
		foreach ($urls as $url) {
			same($old_slug($url), cms::slug_for_uri($url), "slug of $url");
			$parsed = cms::collection_uri($url);
			$route = $parsed && $parsed['path'] !== '/' ? array(ltrim($parsed['path'], '/'), $parsed['name'], $parsed['kind']) : null;
			same($old_route($url), $route, "route of $url");
			$s = $segments($url);
			config::set('uri_segments')->to($s);
			foreach (array('news', 'events', 'menu', 'journal', 'team_members', 'landing') as $name) {
				same($old_canonical($s, $name), cms::canonical_uri($name), "$name kept at $url");
				$asked = cms::collection_request($name, $url);
				same((int)$param($s, $name.'_page', 0), $asked['page'], "$name page at $url");
				same($param($s, $name) == $name.'_item' ? (string)$param($s, $name.'_item') : null, $asked['item'], "$name item at $url");
				$filters = array();
				if ($param($s, $name) == $name.'_items') {
					$start = array_search($name.'_items', $s);
					foreach ($s as $key => $value) if ($key > $start && ($key - $start) % 2 == 0) $filters[$s[$key - 1]] = $value;
					unset($filters[$name.'_page']);
				}
				same($filters, $asked['filters'], "$name filters at $url");
			}
		}
	} finally {
		config::set('uri_segments')->to($uri_segments);
	}
	// the pager's links, filter links and _item views, as each copy made them
	$old_page = function ($name, $n, $uri) {
		$base = preg_replace('#/'.preg_quote($name, '#').'_page/\d+/?$#', '', rtrim($uri, '/'));
		if ($base === '') $base = '/';
		$link = rtrim(config::get('link_uri'), '/');
		return $n <= 1 ? $link.($base === '/' ? '/' : $base) : $link.rtrim($base, '/').'/'.$name.'_page/'.$n;
	};
	foreach ($urls as $url) {
		foreach (array('news', 'events') as $name) {
			foreach (array(1, 2, 3) as $n) same($old_page($name, $n, $url), cms::page_link($name, $n, $url), "page $n of $name at $url");
		}
	}
	foreach (array(array('tag'), array('tag', 'year'), array('tag', 'empty'), array('empty')) as $fields) {
		$item = array('tag' => 'a b/ç', 'year' => 2026, 'empty' => '');
		$old = config::get('link_uri').'news/news_items/';
		foreach ($fields as $field) $old .= $field.'/'.rawurlencode(isset($item[$field]) ? $item[$field] : '').'/';
		same(rtrim($old, '/'), cms::filter_link('news', $fields, $item), implode(',', $fields));
	}
	foreach (array('news_item', 'staff/booking_item', 'news', 'docs/news_items', 'item', '_item', 'a/b/x2_item') as $view) {
		$feed = preg_match('/^([a-z0-9_]+)_item$/', basename($view), $m) ? $m[1] : null;
		$inspector = preg_match('/^(?:(.*)\/)?([a-z0-9_]+)_item$/', $view, $m) ? $m[2] : null;
		same($feed, cms::item_view($view), "feed: $view");
		same($inspector, cms::item_view($view), "inspector: $view");
		same((bool)preg_match('/_item$/', $view), cms::item_view($view) !== null || $view === '_item', "Admin menu: $view");
	}
	// item links: the slug, encoded, else the id
	foreach (array(array('id' => 3, 'slug' => 'raster-runs'), array('id' => 4, 'slug' => ''), array('id' => 5, 'slug' => 'a b/ç')) as $item) {
		same(config::get('link_uri').'news/news_item/'.(!empty($item['slug']) ? rawurlencode($item['slug']) : $item['id']), cms::item_url('news', $item));
		same(rtrim(config::get('link_uri'), '/').'/news/news_item/'.(!empty($item['slug']) ? rawurlencode($item['slug']) : $item['id']), cms::item_url('news', $item));
	}
});

test('P0.B a page with a list, its pager, filters and item links renders as it did', function () use ($base) {
	$made = array();
	for ($i = 1; $i <= 11; $i++) $made[] = mcp_call('create_item', array('collection' => 'news', 'fields' => array('headline' => "P0B filler $i")))['structuredContent']['id'];
	try {
		list(, $body) = http('GET', "$base/news");
		check(preg_match('#href="[^"]*/news/news_page/2"#', $body), 'page 2 link');
		check(preg_match('#href="[^"]*/news/news_item/[a-z0-9-]+"#', $body), 'item links');
		list($status, $body) = http('GET', "$base/news/news_page/2");
		same(200, $status);
		check(strpos($body, 'class="page current"') !== false, 'current page');
		list($status) = http('GET', "$base/news/news_item/no-such-post");
		same(404, $status);
		list(, $body) = http('GET', "$base/news/news_items/headline/P0B%20filler%201");
		check(strpos($body, 'P0B filler 1<') !== false && strpos($body, 'P0B filler 2<') === false, 'the filter: '.$body);
		check(strpos($body, 'news_page/2') === false, 'a filtered list has one page');
	} finally {
		foreach ($made as $id) mcp_call('delete_item', array('collection' => 'news', 'id' => $id));
	}
});

test('P0.B one placeholder for item URLs: {slug} in describe, MCP and the inspector (#37)', function () use ($root) {
	$inspector = new raster_inspector();
	same('/news/news_item/{slug}', $inspector->url_for_view('news_item.html'));
	$overview = mcp_call('site_overview')['structuredContent'];
	foreach ($overview['collections'] as $collection) same('/'.$collection['name'].'/'.$collection['name'].'_item/{slug}', $collection['item_url'], 'MCP');
	require_once BASE.'tools/describe.php';
	foreach (raster_describe::site(array('collections'))['collections'] as $collection) {
		same('/'.$collection['name'].'/'.$collection['name'].'_item/{slug}', $collection['item_url'], 'describe');
	}
	$agents = file_get_contents("$root/AGENTS.md");
	check(strpos($agents, '<slug or id>') === false && strpos($agents, '_item/<slug>') === false && strpos($agents, '_item/{id}') === false, 'AGENTS.md');
});

test('P0.B the pattern Faq protects /faq, and the sitemap, the Admin menu and lint agree (#40)', function () use ($root) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp0bfaq.html");
	file_put_contents("$theme/zzp0bfaq.html", '<html><head><title>Staff FAQ</title></head><body><p>Answers</p></body></html>');
	$config = APPBASE.'config/the_app.php';
	$original = file_get_contents($config);
	$protected = config::get('protected');
	try {
		config::set('protected')->to(array('account' => 'member', 'Zzp0bfaq' => 'editor'));
		same(array('Zzp0bfaq' => 'editor'), authentication::protection('/zzp0bfaq'));
		same(array('Zzp0bfaq' => 'editor'), authentication::protection('zzp0bfaq/more'));
		same(array(), authentication::protection('/about'));
		same(false, authentication::may_open('/zzp0bfaq'));
		$urls = array_column((new feed())->pages(), 'url');
		same(array(), array_values(preg_grep('#zzp0bfaq#i', $urls)), 'the sitemap leaves it out: '.json_encode($urls));
		$admin = array_column(authentication::all_admin_pages(), 'role', 'view');
		same('editor', isset($admin['zzp0bfaq']) ? $admin['zzp0bfaq'] : null, 'the Admin menu lists it: '.json_encode($admin));
		check((new raster_inspector())->admin_view('zzp0bfaq.html'), 'lint reads it as an admin page');
		require_once BASE.'tools/describe.php';
		$described = array_column(raster_describe::site(array('admin_pages'))['admin_pages'], 'url', 'view');
		same('/zzp0bfaq', isset($described['zzp0bfaq']) ? $described['zzp0bfaq'] : null, 'describe lists it');
		// and the page itself sends a visitor to the login page
		file_put_contents($config, $original."\nconfig::set('protected')->to(array('account' => 'member', 'Zzp0bfaq' => 'editor'));\n");
		$page = contact_render('/zzp0bfaq');
		check(strpos($page, 'HTTP 303') !== false && strpos($page, 'Answers') === false, $page);
		$page = contact_render('/zzp0bfaq', 'editor');
		check(strpos($page, 'Answers') !== false, $page);
	} finally {
		file_put_contents($config, $original);
		config::set('protected')->to($protected);
		@unlink("$theme/zzp0bfaq.html");
	}
});

test('P0.B saving a team_members item sends collection team_members, and lint flags teammembers beside it (#33)', function () use ($root) {
	global $p0b_events;
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp0bteam.html");
	test_fixture("$theme/zzp0bteam2.html");
	file_put_contents("$theme/zzp0bteam.html", '<html><head><title>Team</title></head><body><!-- render.cms.zzp0b_members --><h2><!-- print.name -->Ada<!-- /print.name --></h2><!-- /render.cms.zzp0b_members --></body></html>');
	$p0b_events = array();
	event::bind('cms.item_saved')->to(null, 'test_p0b_event');
	event::bind('cms.item_deleted')->to(null, 'test_p0b_event');
	try {
		same(array(), (new raster_inspector())->lint_collections(), 'one name, one table');
		$mcp = new mcp();
		$answer = $mcp->call_tool('create_item', array('collection' => 'zzp0b_members', 'fields' => array('name' => 'Grace')));
		check(empty($answer['isError']), json_encode($answer));
		$id = json_decode($answer['content'][0]['text'], true)['id'];
		$mcp->call_tool('update_item', array('collection' => 'zzp0b_members', 'id' => $id, 'fields' => array('name' => 'Grace H')));
		$mcp->call_tool('delete_item', array('collection' => 'zzp0b_members', 'id' => $id));
		same(array('zzp0b_members', 'zzp0b_members', 'zzp0b_members'), $p0b_events, 'MCP');
		// the model's own code, and code that only has the table
		$p0b_events = array();
		$item = cms_store::save_item('zzp0bmembersdata', 0, array('name' => 'Linus'), array('name'), 'editor', 'zzp0b_members');
		cms_store::delete_item('zzp0bmembersdata', $item['id'], 'zzp0b_members');
		cms_store::save_item('zzp0bmembersdata', 0, array('name' => 'Bare'), array('name'));
		same(array('zzp0b_members', 'zzp0b_members', 'zzp0bmembers'), $p0b_events, 'cms_store');
		// a declared type is called by its own name, whatever name the editor posts
		temp_model(APPBASE.'models/zzp0brec', array('zzp0brec.php' => "<?php\nclass zzp0brec\n{\n\tstatic function types() {\n\t\treturn array('zzp0brec' => array('fields' => array('name' => '')));\n\t}\n}\n"));
		try {
			controller::forget_models();
			cms_records::forget();
			same('zzp0brec', cms_store::collection_of('zzp0brecdata', 'zzp0b_rec'));
			same('zzp0brec', cms_store::collection_of('zzp0brecdata'));
		} finally {
			remove_dir(APPBASE.'models/zzp0brec');
			controller::forget_models();
			cms_records::forget();
		}
		// a second name for the same table is an error, next to the first
		file_put_contents("$theme/zzp0bteam2.html", "<html><head><title>Team 2</title></head><body>\n<!-- render.cms.zzp0bmembers --><h2><!-- print.name -->Ada<!-- /print.name --></h2><!-- /render.cms.zzp0bmembers --></body></html>");
		$problems = (new raster_inspector())->lint_collections();
		same(1, count($problems), json_encode($problems));
		same('error', $problems[0]['severity']);
		check(strpos($problems[0]['message'], "'zzp0b_members' and 'zzp0bmembers' share one table, zzp0bmembersdata") !== false, $problems[0]['message']);
		same(array('application/views/default/zzp0bteam2.html', 2), array($problems[0]['file'], $problems[0]['line']));
		check(has_problem((new raster_inspector())->lint(), 'share one table'), 'lint reports it');
	} finally {
		event::unbind('cms.item_saved')->from(null, 'test_p0b_event');
		event::unbind('cms.item_deleted')->from(null, 'test_p0b_event');
		@unlink("$theme/zzp0bteam.html");
		@unlink("$theme/zzp0bteam2.html");
		cms_store::connect();
		R::exec('DROP TABLE IF EXISTS zzp0bmembersdata');
	}
});

// ## P1.B: /<lang>/ URLs, links, redirects, cache

// English (the default) and Romanian on the starter for the length of $fn,
// written to the_events.php (read on every request, after the_app.php),
// with fixture views and a Romanian dictionary
function p1b_site($settings, $fn) {
	global $root;
	$theme = "$root/application/views/default";
	$views = array(
		'zzp1b_about' => '<!doctype html><html><head><title>About</title></head><body>'
			.'<h1><!-- print.i18n.zzp1b(\'hello\') -->Hello<!-- /print.i18n.zzp1b(\'hello\') --></h1>'
			.'<a href="index.html">Home</a> <a href="zzp1b_menu.html">Menu</a> <a href="/zzp1b_menu">Root</a> <a href="/">Root home</a>'
			.' <a href="/zzp1b_menu?x=1#top">Query</a> <a href="/ro/zzp1b_menu">Named</a> <a data-href="/zzp1b_menu">Data</a> <a href="/index.php/zzp1b_menu">Index</a>'
			.'<form method="post" action="/zzp1b_about"><button>Go</button><button formaction="/zzp1b_menu">Other</button></form>'
			.'<a href="/media/x.jpg">Photo</a> <a href="/application/views/default/style.css">CSS</a> <a href="/system/x">Sys</a>'
			.' <a href="/bin/raster">File</a> <a href="/zzp1b_menu.pdf">PDF</a> <a href="//cdn.example/x">CDN</a> <a href="https://example.com/about">Out</a>'
			.'</body></html>',
		'zzp1b_menu' => '<!doctype html><html><head><title>Menu</title></head><body>'
			.'<h1><!-- print.i18n.zzp1b(\'menu\') -->Menu<!-- /print.i18n.zzp1b(\'menu\') --></h1>'
			.'<a href="zzp1b_about.html">About</a> <a href="/preview/p1b42/zzp1b_about">In the folder</a> <a href="/zzp1b_about">Outside</a></body></html>',
		'zzp1b_staff' => '<!doctype html><html><head><title>Rota</title></head><body><p>Rota</p></body></html>',
		'_email/zzp1b_note' => '<!doctype html><html><head><title>Note</title></head><body>'
			.'<a href="/zzp1b_menu">Root</a> <a href="zzp1b_menu.html">Menu</a> <a href="/media/x.jpg">Photo</a> <a href="/index.php/zzp1b_menu">Index</a></body></html>',
	);
	foreach ($views as $name => $html) {
		test_fixture("$theme/$name.html");
		file_put_contents("$theme/$name.html", $html);
	}
	$i18n = "$root/application/i18n";
	$made = !is_dir($i18n);
	@mkdir("$i18n/ro", 0775, true);
	file_put_contents("$i18n/ro/zzp1b.php", "<?php\nreturn array('hello' => 'Bună ziua', 'menu' => 'Meniu');\n");
	$events = APPBASE.'config/the_events.php';
	$kept = file_get_contents($events);
	file_put_contents($events, (strpos($kept, '<?php') === 0 ? $kept : "<?php\n")."\n".$settings."\n");
	try {
		return $fn();
	} finally {
		file_put_contents($events, $kept);
		foreach (array_keys($views) as $name) @unlink("$theme/$name.html");
		@unlink("$i18n/ro/zzp1b.php");
		@rmdir("$i18n/ro");
		if ($made) @rmdir($i18n);
	}
}
// a server of its own; {port} in $env is its port
function p1b_server($env, $fn) {
	global $root, $db;
	$port = free_port();
	$env = $env + array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'RASTER_ENV' => 'development', 'PATH' => getenv('PATH'));
	foreach ($env as $name => $value) $env[$name] = str_replace('{port}', $port, $value);
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		return $fn($port);
	} finally {
		proc_terminate($server); proc_close($server);
	}
}
function p1b_header($headers, $name) {
	foreach ($headers as $h) if (stripos($h, $name.':') === 0) return trim(substr($h, strlen($name) + 1));
	return null;
}
// every link to a page of the site starts with $prefix (theme files aside)
function p1b_links_carry($body, $base, $prefix, $what) {
	// a link with hreflang names another language on purpose: the <head>
	// alternates and the switcher (P1.C)
	$body = preg_replace('/<(a|link)\b[^>]*\shreflang=[^>]*>/i', '', $body);
	preg_match_all('#(?:href|action)=["\']('.preg_quote($base, '#').'[^"\']*)["\']#', $body, $m);
	check(count($m[1]) > 0, "$what has no links");
	foreach ($m[1] as $url) {
		if (strpos($url, "$base/application/") === 0) continue;
		check(strpos($url, $prefix) === 0, "$what: $url doesn't carry $prefix");
	}
}
$p1b_on = "config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);";

test('P1.B: /ro/ URLs: the prefix is taken off, /ro → /ro/, /ro/menu/ → /ro/menu, /en/menu → /menu, ?lang= → 301', function () use ($base, $p1b_on) {
	p1b_site($p1b_on, function () use ($base) {
		$redirect = function ($path) use ($base) {
			list($status, , $headers) = http('GET', $base.$path);
			check(!preg_grep('/^Set-Cookie/i', $headers), "$path sets a cookie");
			return $status.' '.location($headers);
		};
		same("301 $base/ro/", $redirect('/ro'));
		same("301 $base/ro/?x=1", $redirect('/ro?x=1'));
		same("301 $base/ro/zzp1b_menu", $redirect('/ro/zzp1b_menu/'));
		same("301 $base/ro/zzp1b_menu?x=1", $redirect('/ro/zzp1b_menu/?x=1'));
		// the default language has no prefix
		same("301 $base/zzp1b_menu", $redirect('/en/zzp1b_menu'));
		same("301 $base/", $redirect('/en/'));
		same("301 $base/", $redirect('/en'));
		// ?lang= is the same page in that language, the rest of the query kept, no cookie
		same("301 $base/ro/zzp1b_menu?x=1", $redirect('/zzp1b_menu?lang=ro&x=1'));
		same("301 $base/zzp1b_menu", $redirect('/ro/zzp1b_menu?lang=en'));
		same("301 $base/ro/", $redirect('/?lang=ro'));
		same("301 $base/ro/zzp1b_menu", $redirect('/ro/zzp1b_menu/?lang=RO'));
		same("301 $base/ro/zzp1b_menu", $redirect('/zzp1b_menu?lang=ro-RO'));
		// an address that looks like another site's stays on this one
		check(strpos($redirect('//evil.example/x?lang=ro'), "301 $base/ro/") === 0, 'open redirect');
		check(strpos($redirect('/ro//evil.example?lang=en'), "301 $base/") === 0, 'open redirect');
		// a language the site doesn't have is no prefix and no redirect
		same(404, http('GET', "$base/de/zzp1b_menu")[0]);
		same(200, http('GET', "$base/ro/zzp1b_menu?lang=xx")[0]);
		same(404, http('GET', "$base/ro/nothing-here")[0]);
		foreach (array('/ro/' => 'ro', '/ro/zzp1b_menu' => 'ro', '/zzp1b_menu' => 'en', '/' => 'en', '/ro/news.rss' => 'ro') as $path => $lang) {
			list($status, , $headers) = http('GET', $base.$path);
			same(200, $status, $path);
			same($lang, p1b_header($headers, 'Content-Language'), $path);
			check(!preg_grep('/^Set-Cookie/i', $headers), "$path sets a cookie");
		}
		// JSON views and refusals without a view carry no language
		same(null, p1b_header(http('GET', "$base/ro/api/site/year")[2], 'Content-Language'));
	});
});

test('P1.B: /ro/about renders the Romanian template text, and every link carries /ro/', function () use ($base, $p1b_on) {
	p1b_site($p1b_on, function () use ($base) {
		list($status, $body, $headers) = http('GET', "$base/ro/zzp1b_about");
		same(200, $status);
		same('ro', p1b_header($headers, 'Content-Language'));
		check(strpos($body, '<h1>Bună ziua</h1>') !== false, 'the Romanian text');
		// links to pages, by file name and by root path, get the language's address
		foreach (array('<a href="'.$base.'/ro/">Home</a>', '<a href="'.$base.'/ro/zzp1b_menu">Menu</a>', '<a href="'.$base.'/ro/zzp1b_menu">Root</a>',
			'<a href="'.$base.'/ro/">Root home</a>', '<a href="'.$base.'/ro/zzp1b_menu?x=1#top">Query</a>', '<a href="'.$base.'/ro/zzp1b_menu">Named</a>',
			'action="'.$base.'/ro/zzp1b_about"', '<a href="'.$base.'/ro/zzp1b_menu">Index</a>', 'formaction="'.$base.'/ro/zzp1b_menu"') as $link) {
			check(strpos($body, $link) !== false, "missing $link");
		}
		// links to files keep theirs
		foreach (array('<a data-href="/zzp1b_menu">', '<a href="/media/x.jpg">', '<a href="/application/views/default/style.css">', '<a href="/system/x">',
			'<a href="/bin/raster">', '<a href="/zzp1b_menu.pdf">', '<a href="//cdn.example/x">', '<a href="https://example.com/about">') as $link) {
			check(strpos($body, $link) !== false, "changed: $link");
		}
		p1b_links_carry($body, $base, "$base/ro/", '/ro/zzp1b_about');
		// the starter's own pages, layout and all
		foreach (array('/ro/about', '/ro/login', '/ro/', '/ro/news') as $path) {
			list($status, $body) = http('GET', $base.$path);
			same(200, $status, $path);
			p1b_links_carry($body, $base, "$base/ro/", $path);
		}
		// the default language: root links stay as written
		list(, $body, $headers) = http('GET', "$base/zzp1b_about");
		same('en', p1b_header($headers, 'Content-Language'));
		check(strpos($body, '<h1>Hello</h1>') !== false && strpos($body, '<a href="/zzp1b_menu">Root</a>') !== false && strpos($body, 'href="'.$base.'/zzp1b_menu"') !== false, 'English page');
		// an email to an account made on /ro/ links to /ro/ (since P4.A the
		// account's stored language decides, not the page asking)
		cms_store::connect();
		with_settings(array('multilingual' => true, 'languages' => array('en', 'ro')), function () {
			i18n::with('ro', function () { return authentication::create_user('zzp1b-reset@example.test', 'a long password', 'member', 'Ana'); });
		});
		same(303, form_post("$base/ro/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'zzp1b-reset@example.test'))[0]);
		$mail = last_mail();
		check($mail && strpos($mail['text'], "$base/ro/reset?token=") !== false, 'the reset link: '.($mail ? $mail['text'] : 'no mail'));
	});
});

test('P1.B: an email rendered in a language: every page link carries its prefix, through fix_links', function () use ($root) {
	p1b_site('', function () use ($root) {
		$was = array(config::get('languages'), config::get('multilingual'));
		config::set('languages')->to(array('en', 'ro'));
		config::set('multilingual')->to(true);
		try {
			$base = config::get('link_base');
			$html = i18n::with('ro', function () { return controller::render_view('_email/zzp1b_note'); });
			check(strpos($html, 'href="'.$base.'ro/zzp1b_menu">Root') !== false, $html);
			check(strpos($html, 'href="'.$base.'ro/zzp1b_menu">Menu') !== false, $html);
			check(strpos($html, '/ro/media/') === false && strpos($html, 'media/x.jpg') !== false, $html);
			same($base, config::get('link_uri'), 'with() puts link_uri back');
			$html = i18n::with('en', function () { return controller::render_view('_email/zzp1b_note'); });
			check(strpos($html, 'href="'.$base.'zzp1b_menu">Menu') !== false && strpos($html, '/ro/') === false, $html);
			// without rewrites the index file is in link_uri once, not twice
			config::set('link_base')->to($base.'index.php/');
			$html = i18n::with('ro', function () { return controller::render_view('_email/zzp1b_note'); });
			check(strpos($html, 'href="'.$base.'index.php/ro/zzp1b_menu">Index') !== false, $html);
		} finally {
			config::set('link_base')->to($base);
			config::set('languages')->to($was[0]);
			config::set('multilingual')->to($was[1]);
		}
	});
});

test('P1.B: protected /ro/ pages: /ro/staff goes to /ro/login and back, and stays protected', function () use ($base, $p1b_on) {
	p1b_site($p1b_on."\nconfig::set('protected')->to(array('account' => 'member', 'zzp1b_staff' => 'editor'));", function () use ($base) {
		list($status, , $headers) = http('GET', "$base/ro/zzp1b_staff");
		same(303, $status);
		same("$base/ro/login?next=%2Fzzp1b_staff", location($headers));
		// in other letter case it is not the prefix, and no page
		same(404, http('GET', "$base/RO/zzp1b_staff")[0]);
		// other spellings of the same path stay protected, or are no page
		foreach (array('/%72o/zzp1b_staff', '/ro%2Fzzp1b_staff', '/ro//zzp1b_staff', '/ro/zzp1b_staff/') as $path) {
			list($status, , $headers) = http('GET', $base.$path);
			check(in_array($status, array(301, 303), true) && strpos((string)location($headers), "$base/ro/") === 0, "$path: $status ".location($headers));
		}
		foreach (array('/ro/zzp1b_staff%0A', '/ro/zzp1b_staff%00', '/ro/../zzp1b_staff', '/ro/./zzp1b_staff') as $path) {
			list($status, $body) = http('GET', $base.$path);
			check($status !== 200 && strpos((string)$body, 'Rota') === false, "$path answers $status");
		}
		cms_store::connect();
		authentication::create_user('zzp1b-editor@example.test', 'a long password', 'editor', 'Ed');
		authentication::create_user('zzp1b-member@example.test', 'a long password', 'member', 'Mo');
		list($status, , $headers) = form_post("$base/ro/login?next=%2Fzzp1b_staff", array('raster_form' => 'authentication.login', 'login' => 'zzp1b-editor@example.test', 'password' => 'a long password'));
		same(303, $status);
		same("$base/ro/zzp1b_staff", location($headers));
		$cookie = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie:\s*PHPSESSID/i', $headers)));
		list($status, $body) = http('GET', "$base/ro/zzp1b_staff", null, array("Cookie: $cookie"));
		same(200, $status);
		check(strpos($body, 'Rota') !== false);
		list(, , $headers) = form_post("$base/ro/login", array('raster_form' => 'authentication.login', 'login' => 'zzp1b-member@example.test', 'password' => 'a long password'));
		$member = preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie:\s*PHPSESSID/i', $headers)));
		same(403, http('GET', "$base/ro/zzp1b_staff", null, array("Cookie: $member"))[0], 'a member');
		same(403, http('GET', "$base/ro/zzp1b_staff/rota", null, array("Cookie: $member"))[0], 'under it');
	});
});

test('P1.B: /ro/api and /ro/mcp answer as /api and /mcp, and /ro/ forms pass the cross-site check', function () use ($base, $p1b_on) {
	p1b_site($p1b_on, function () use ($base) {
		list($status, $body) = http('GET', "$base/ro/api/site/year");
		same(200, $status);
		same(http('GET', "$base/api/site/year")[1], $body);
		same(404, http('GET', "$base/ro/api/welcome/hello")[0]);
		list($status, $body) = http('POST', "$base/ro/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list')), array('Authorization: Bearer test-token', 'Content-Type: application/json'));
		same(200, $status);
		check(strpos($body, '"describe"') !== false, $body);
		same(401, http('POST', "$base/ro/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list')), array('Content-Type: application/json'))[0], 'no token');
		list($status, $body) = form_post("$base/ro/zzp1b_about", array('x' => '1'), array('Origin: https://evil.example'));
		same(403, $status, 'a post from another site');
		same(200, form_post("$base/ro/zzp1b_about", array('x' => '1'), array("Origin: $base"))[0], 'from this site');
	});
});

test('P1.B: a /ro/ page is cached under its own key, with its Content-Language', function () use ($p1b_on) {
	cache_empty();
	p1b_site($p1b_on, function () {
		p1b_server(array('RASTER_ENV' => 'production'), function ($port) {
			$get = function ($path) use ($port) {
				list($status, $body, $headers) = http('GET', "http://127.0.0.1:$port$path");
				return array($status.' '.(p1b_header($headers, 'X-Raster-Cache') ?: 'not cached').' '.p1b_header($headers, 'Content-Language'), $body);
			};
			same('200 miss en', $get('/zzp1b_about')[0]);
			same('200 miss ro', $get('/ro/zzp1b_about')[0]);
			same('200 hit en', $get('/zzp1b_about')[0]);
			list($answer, $body) = $get('/ro/zzp1b_about');
			same('200 hit ro', $answer);
			check(strpos($body, '<h1>Bună ziua</h1>') !== false, 'the Romanian copy');
			check(strpos($get('/zzp1b_about')[1], '<h1>Hello</h1>') !== false, 'the English copy');
			same(2, count(cached_pages()));
			// a redirect is never kept
			same('301 not cached ', $get('/ro/zzp1b_about/')[0]);
		});
	});
	cache_empty();
});

test('P1.B: previewPathTest: /preview/<id>/ro/menu with RASTER_URL=http://127.0.0.1:<port>/preview/<id>/', function () use ($p1b_on) {
	p1b_site($p1b_on, function () {
		p1b_server(array('RASTER_URL' => 'http://127.0.0.1:{port}/preview/p1b42/'), function ($port) {
			$site = "http://127.0.0.1:$port/preview/p1b42";
			list($status, $body, $headers) = http('GET', "$site/ro/zzp1b_menu");
			same(200, $status);
			same('ro', p1b_header($headers, 'Content-Language'));
			check(strpos($body, '<h1>Meniu</h1>') !== false, 'the Romanian text');
			check(strpos($body, '<a href="'.$site.'/ro/zzp1b_about">About</a>') !== false, 'a link by file name');
			check(strpos($body, '<a href="'.$site.'/ro/zzp1b_about">In the folder</a>') !== false, 'a root link in the folder');
			check(strpos($body, '<a href="/zzp1b_about">Outside</a>') !== false, 'a root link outside the folder is left alone');
			$redirect = function ($path) use ($port) {
				list($status, , $headers) = http('GET', "http://127.0.0.1:$port$path");
				return $status.' '.location($headers);
			};
			same("301 $site/ro/", $redirect('/preview/p1b42/ro'));
			same("301 $site/ro/zzp1b_menu", $redirect('/preview/p1b42/ro/zzp1b_menu/'));
			same("301 $site/ro/zzp1b_menu", $redirect('/preview/p1b42/zzp1b_menu?lang=ro'));
			same("301 $site/zzp1b_menu", $redirect('/preview/p1b42/en/zzp1b_menu'));
			same('301 /preview/p1b42/', $redirect('/preview/p1b42'));
			same(200, http('GET', "$site/ro/")[0]);
			list($status, $body) = http('GET', "$site/zzp1b_menu");
			same(200, $status);
			check(strpos($body, '<h1>Menu</h1>') !== false && strpos($body, '<a href="'.$site.'/zzp1b_about">About</a>') !== false, 'English');
		});
	});
});

test('P1.B: a domain_language host is its language, with no prefix', function () use ($p1b_on) {
	p1b_site($p1b_on."\nconfig::set('domain_language')->to(array('zzp1b-ro.test' => 'ro'));", function () {
		p1b_server(array('RASTER_URL' => 'http://127.0.0.1:{port}/'), function ($port) {
			$ro = array("Host: zzp1b-ro.test:$port");
			list($status, $body, $headers) = http('GET', "http://127.0.0.1:$port/zzp1b_about", null, $ro);
			same(200, $status);
			same('ro', p1b_header($headers, 'Content-Language'));
			check(strpos($body, '<h1>Bună ziua</h1>') !== false, 'Romanian');
			check(strpos($body, '<a href="http://zzp1b-ro.test:'.$port.'/zzp1b_menu">Menu</a>') !== false && strpos($body, '<a href="/zzp1b_menu">Root</a>') !== false, 'links on its own host, no prefix');
			same(404, http('GET', "http://127.0.0.1:$port/ro/zzp1b_about", null, $ro)[0], 'no prefix on that host');
			// one language, one address: /ro/ on the site's address goes to the language's domain
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port/ro/zzp1b_about?x=1");
			same("301 http://zzp1b-ro.test/zzp1b_about?x=1", $status.' '.location($headers));
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port/zzp1b_about?lang=en", null, $ro);
			same("301 http://127.0.0.1:$port/zzp1b_about", $status.' '.location($headers), 'English is at the site address');
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port/zzp1b_about?lang=ro", null, $ro);
			same("301 http://zzp1b-ro.test:$port/zzp1b_about", $status.' '.location($headers));
			// another host: exact names only
			list(, $body, $headers) = http('GET', "http://127.0.0.1:$port/zzp1b_about", null, array("Host: www.zzp1b-ro.test:$port"));
			same('en', p1b_header($headers, 'Content-Language'));
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port/zzp1b_about?lang=ro");
			same("301 http://zzp1b-ro.test/zzp1b_about", $status.' '.location($headers));
		});
	});
});

test('P1.B: off is identical: no prefix, no header, no redirect, same cache key and links', function () use ($base) {
	$answers = array();
	foreach (array('', "config::set('languages')->to(array('en', 'ro'));", "config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(false);",
		"config::set('languages')->to(array('en'));\nconfig::set('multilingual')->to(true);") as $settings) {
		$answers[] = p1b_site($settings, function () use ($base, $settings) {
			if ($settings !== '') {
				same(404, http('GET', "$base/ro/zzp1b_about")[0], $settings);
				list($status, , $headers) = http('GET', "$base/zzp1b_about/");
				same("301 $base/zzp1b_about", $status.' '.location($headers));
			}
			if (strpos($settings, "'ro'") !== false) {
				// ?lang= still picks the template's language and remembers it
				list($status, $body, $headers) = http('GET', "$base/zzp1b_about?lang=ro");
				same(200, $status);
				check(strpos($body, '<h1>Bună ziua</h1>') !== false && (bool)preg_grep('/^Set-Cookie: lang=ro/i', $headers), 'the cookie');
			}
			list($status, $body, $headers) = http('GET', "$base/zzp1b_about");
			same(null, p1b_header($headers, 'Content-Language'));
			check(strpos($body, '<a href="/zzp1b_menu">Root</a>') !== false && strpos($body, '<a href="/">Root home</a>') !== false, 'root links as written');
			return array($status, $body, array_values(preg_grep('/^Date:/i', $headers, PREG_GREP_INVERT)));
		});
	}
	same($answers[0], $answers[1], 'languages without multilingual');
	same($answers[0], $answers[2], 'multilingual false');
	same($answers[0], $answers[3], 'multilingual with one language');
	// in this process (no multilingual): link_base is link_uri, the URL names no language,
	// and the cache key is what it was
	same(config::get('link_uri'), config::get('link_base'));
	same('', config::get('url_language'));
	$was = config::get('languages');
	config::set('languages')->to(array('en', 'ro'));
	try {
		same(sha1(config::get('protocol').'://'.config::get('host').'|'.config::get('uri_string').'|'.i18n::detect()), raster_cache::key());
		// on, the language is in the key
		config::set('multilingual')->to(true);
		$default = raster_cache::key();
		config::set('url_language')->to('ro');
		check(raster_cache::key() !== $default, 'a key per language');
	} finally {
		config::set('languages')->to($was);
		config::set('multilingual')->to(false);
		config::set('url_language')->to('');
	}
});

// ## P1.C: head tags, html lang and dir, the switcher (#117 #138)

// multilingual on, as config::initialize() leaves it on /ro/menu (P1.B)
function p1c_on($settings = array()) {
	return $settings + array('multilingual' => true, 'languages' => array('en', 'ro'), 'url_language' => 'ro',
		'link_base' => 'http://cafe.test/', 'link_uri' => 'http://cafe.test/ro/', 'uri_string' => '/menu');
}
// i18n::detect() as a new request starts it
function p1c_forget_language() {
	$detected = new ReflectionProperty('i18n', 'detected');
	$detected->setAccessible(true);
	$detected->setValue(null, null);
}

test('P1.C: in multilingual mode <head> gets the canonical link, one hreflang per language and x-default; nothing when off', function () {
	$links = function () { return template::language_links(); };
	same("\n<link rel=\"canonical\" href=\"http://cafe.test/ro/menu\">"
		."\n<link rel=\"alternate\" hreflang=\"en\" href=\"http://cafe.test/menu\">"
		."\n<link rel=\"alternate\" hreflang=\"ro\" href=\"http://cafe.test/ro/menu\">"
		."\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"http://cafe.test/menu\">", with_settings(p1c_on(), $links));
	// the default language's page is its own canonical, and the home page is /ro/
	check(strpos(with_settings(p1c_on(array('url_language' => '', 'link_uri' => 'http://cafe.test/')), $links), "\n<link rel=\"canonical\" href=\"http://cafe.test/menu\">\n") === 0, 'default language');
	check(strpos(with_settings(p1c_on(array('uri_string' => '/')), $links), '<link rel="canonical" href="http://cafe.test/ro/">') !== false, 'home page');
	// a page that writes its own keeps them
	same('', with_settings(p1c_on(), function () { return template::language_links('<head><link rel="canonical" href="/x"><link rel="alternate" hreflang="en" href="/y">'); }));
	// off, with languages too, and one language only (D1): nothing
	same('', with_settings(p1c_on(array('multilingual' => false)), $links));
	same('', with_settings(p1c_on(array('languages' => array('en'))), $links));
	// a refusal (the 404 page) and a URL with a query (?page=2) are no page's address
	same('', with_settings(p1c_on(), function () { return template::language_links('', 404); }), 'a 404');
	$get = $_GET;
	$_GET = array('page' => '2');
	try {
		same('', with_settings(p1c_on(), $links), '?page=2');
		// a tracking parameter is the same page: the links stay
		$_GET = array('utm_source' => 'newsletter', 'fbclid' => 'x');
		check(strpos(with_settings(p1c_on(), $links), 'rel="canonical"') !== false, '?utm_source keeps the canonical');
	} finally {
		$_GET = $get;
	}
});

test('P1.C: the base goes after any <head …> tag, and an exact <head> gets what 2.2.45 gave it (#117)', function () {
	$base = "<base href='/v/' />\n<script>var BASE = \"/\"</script>";
	$page = "<!doctype html>\n<html lang=\"en\">\n<head>\n<title>x</title></head><body><header>h</header></body></html>";
	same(str_replace('<head>', "<head>\n".$base, $page), template::insert_in_head($page, $base));
	same('<head prefix="og: http://ogp.me/ns#">'."\n$base<title>x</title>", template::insert_in_head('<head prefix="og: http://ogp.me/ns#"><title>x</title>', $base));
	same("<HEAD>\n$base", template::insert_in_head('<HEAD>', $base));
	same('<header>h</header>', template::insert_in_head('<header>h</header>', $base));
	// a <head> in a comment or a script before the real one is not it
	same("<!-- <head> --><script>var t = '<head>';</script><head>\n$base<title>x</title>", template::insert_in_head("<!-- <head> --><script>var t = '<head>';</script><head><title>x</title>", $base));
	same('<!-- <head> unclosed', template::insert_in_head('<!-- <head> unclosed', $base));
});

test('P1.C: a page with <head prefix=…> gets its base, and //host links are left as written (#117)', function () use ($base, $root) {
	$view = "$root/application/views/default/zzp1c_head.html";
	test_fixture($view);
	file_put_contents($view, "<!doctype html>\n<html lang=\"en\">\n<head prefix=\"og: http://ogp.me/ns#\">\n<link rel=\"stylesheet\" href=\"style.css\"><title>x</title></head>\n<body>"
		.'<a href="//cdn.example.com/guide.html">g</a> <a href="//cdn.example.com/x.html?su=1">x</a> <img src="//cdn.example.com/p.png"> <a href="about.html">a</a> <a href="about.html#team">t</a></body></html>');
	try {
		list($status, $html) = http('GET', "$base/zzp1c_head");
		same(200, $status, $html);
		check(strpos($html, "<head prefix=\"og: http://ogp.me/ns#\">\n<base href='$base/application/views/default/' />") !== false, $html);
		check(preg_match('/href="style\.css\?v=[0-9a-f]{8}"/', $html) === 1, 'the stylesheet resolves: '.$html);
		check(strpos($html, '<a href="//cdn.example.com/guide.html">g</a> <a href="//cdn.example.com/x.html?su=1">x</a> <img src="//cdn.example.com/p.png">') !== false, $html);
		check(strpos($html, '<a href="'.$base.'/about">a</a> <a href="'.$base.'/about#team">t</a>') !== false, 'the workaround for #anchors: '.$html);
		same(false, strpos($html, 'hreflang'), 'off: no language links');
	} finally {
		@unlink($view);
	}
});

test('P1.C: print.i18n.dir, and <html lang dir> follows the language on the starter', function () use ($base) {
	same('ltr', i18n::dir('en'));
	same('rtl', i18n::dir('ar'));
	same('rtl', i18n::dir('he-IL'));
	same('ltr', (new i18n())->dir('ro'));
	// off: the mock-up's, and no switcher
	$home = http('GET', "$base/")[1];
	check(strpos($home, "<!doctype html>\n<html lang=\"en\" dir=\"ltr\">\n<head>") === 0, $home);
	same(false, strpos($home, '?lang='), 'no switcher with no languages');
	p0c_settings("config::set('languages')->to(array('en', 'ro', 'ar'));", function () use ($base) {
		list(, $ro) = http('GET', "$base/?lang=ro");
		check(strpos($ro, '<html lang="ro" dir="ltr">') !== false, $ro);
		list(, $ar) = http('GET', "$base/about?lang=ar");
		check(strpos($ar, '<html lang="ar" dir="rtl">') !== false, $ar);
		// the starter's switcher, now that there is more than one language
		check(strpos($ar, '<a class="nav-link" href="'.$base.'/about?lang=en" hreflang="en">en</a>') !== false, $ar);
		check(strpos($ar, '<a class="nav-link active" href="'.$base.'/about?lang=ar" hreflang="ar">ar</a>') !== false, $ar);
		same(false, strpos($ar, 'hreflang="x-default"'), 'template-only languages: no language links in <head>');
	});
	p0c_settings("config::set('languages')->to(array('en'));", function () use ($base) {
		same(false, strpos(http('GET', "$base/")[1], '?lang='), 'one language: no switcher');
	});
});

test('P1.C: the switcher\'s rows have url and current: the language\'s own address when multilingual, ?lang= otherwise', function () {
	$rows = with_settings(p1c_on(), function () { return (new i18n())->languages(); });
	same(array(
		array('code' => 'en', 'url' => 'http://cafe.test/menu', 'state' => '', 'current' => false),
		array('code' => 'ro', 'url' => 'http://cafe.test/ro/menu', 'state' => 'active', 'current' => true),
	), $rows);
	$get = $_GET;
	$_GET['lang'] = 'ro';
	p1c_forget_language();
	try {
		$rows = with_settings(array('multilingual' => false, 'languages' => array('en', 'ro'), 'link_uri' => 'http://cafe.test/', 'uri_string' => '/menu'), function () { return (new i18n())->languages(); });
	} finally {
		$_GET = $get;
		p1c_forget_language();
	}
	same(array(
		array('code' => 'en', 'url' => 'http://cafe.test/menu?lang=en', 'state' => '', 'current' => false),
		array('code' => 'ro', 'url' => 'http://cafe.test/menu?lang=ro', 'state' => 'active', 'current' => true),
	), $rows);
});

test('P1.C: off is identical: starter pages are as 2.2.45 made them, the html tag\'s dir aside', function () use ($base, $root) {
	$theme = "$root/application/views/default";
	$kept = array();
	foreach (array('_layout.html', 'index.html', 'about.html') as $file) $kept[$file] = file_get_contents("$theme/$file");
	// the views as 2.2.45 had them: <html lang="en">, and no switcher
	$old = array();
	foreach ($kept as $file => $view) {
		$old[$file] = str_replace('<!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr"><!-- /print.@dir.i18n.dir --><!-- /print.@lang.i18n.language -->', '<html lang="en">', $view);
		$old[$file] = preg_replace('#<!-- print\.if\.languages -->.*?<!-- /print\.if\.languages -->#', '', $old[$file]);
	}
	check($old['_layout.html'] !== $kept['_layout.html'] && strpos($old['index.html'], '<html lang="en">') !== false, 'the starter has the switcher and the annotated html tag');
	$clean = function ($html) { return preg_replace('/name="raster_ts" value="[^"]*"/', '', $html); };
	$pages = array('/', '/about');
	$before = array();
	try {
		foreach ($old as $file => $view) file_put_contents("$theme/$file", $view);
		foreach ($pages as $page) $before[$page] = $clean(http('GET', $base.$page)[1]);
	} finally {
		foreach ($kept as $file => $view) file_put_contents("$theme/$file", $view);
	}
	foreach ($pages as $page) {
		list($status, $now) = http('GET', $base.$page);
		same(200, $status);
		check(substr_count($now, '<html lang="en" dir="ltr">') === 1, $now);
		same($before[$page], str_replace('<html lang="en" dir="ltr">', '<html lang="en">', $clean($now)), "$page byte for byte");
	}
});

test('P1.C: lint warns on href="#…" and href="?…", which leave the page (anchor_link, #117)', function () use ($root) {
	$p = lint_html("<p>\n<a href=\"#prices\">Prices</a> <a class=\"x\" href='?sort=price'>Sort</a></p>");
	same(2, count($p), json_encode($p));
	check(has_problem($p, 'href="#prices" leaves the page', 2) && has_problem($p, '<page>.html#prices'), json_encode($p));
	check(has_problem($p, 'href="?sort=price" leaves the page') && has_problem($p, '<page>.html?sort=price'), json_encode($p));
	same('warning', $p[0]['severity']);
	// a link a print sets, mock-up content and page links are fine
	same(array(), lint_html('<!-- render.site.nav --><!-- print.+class.state --><!-- print.@href.url --><a class="page" href="#">1</a><!-- /print.@href.url --><!-- /print.+class.state --><!-- /render.site.nav -->'));
	same(array(), lint_html('<!-- remove --><a href="#x">x</a><!-- /remove --><a href="about.html#team">t</a> <a href="/about?x=1">a</a> <a href="https://example.com/#x">e</a>'));
	// the hint names the view
	$inspector = new raster_inspector();
	check(has_problem($inspector->lint_source('<a href="#team">Team</a>', 'application/views/default/docs/setup.html'), 'Write href="docs/setup.html#team"'), 'the view\'s own name');
	// emails have no <base>
	same(array(), $inspector->lint_source('<a href="#x">x</a>', 'application/views/default/_email/welcome.html'));
	// nor a page with a <base> of its own
	same(array(), lint_html('<head><base href="/"></head><a href="#x">x</a>'));
});

test('P1.C: off is identical for an email: its <html> is the mock-up\'s, whatever language the visitor picked', function () use ($root) {
	$view = "$root/application/views/default/_email/password_reset.html";
	$now = file_get_contents($view);
	// its words are dictionary keys (P5.A), its <html> stays as written
	check(strpos($now, "<!doctype html>\n<html lang=\"en\" dir=\"ltr\">\n<head>") === 0, 'no language annotation on the email\'s <html>');
	$vars = array('name' => 'Ana', 'reset_url' => 'https://cafe.test/reset?token=x');
	$render = function () use ($vars) { return controller::render_view('_email/password_reset', $vars); };
	$html = $render();
	test_fixture($view);
	file_put_contents($view, str_replace('<html lang="en" dir="ltr">', '<html lang="en">', $now));
	try {
		same($render(), str_replace('<html lang="en" dir="ltr">', '<html lang="en">', $html), 'as 2.2.45 rendered it, the dir aside');
	} finally {
		file_put_contents($view, $now);
	}
	// a visitor reading in Romanian doesn't change a staff email's lang (P4 gives emails their language)
	$get = $_GET;
	$_GET['lang'] = 'ro';
	p1c_forget_language();
	try {
		same($html, with_settings(array('languages' => array('en', 'ro')), $render));
	} finally {
		$_GET = $get;
		p1c_forget_language();
	}
});

test('P1.C: a /ro/ page links to the other languages from <head> and the switcher, and BASE is its language\'s address, where /api answers too', function () use ($base, $p1b_on) {
	p1b_site($p1b_on, function () use ($base) {
		list($status, $body) = http('GET', "$base/ro/zzp1b_about");
		same(200, $status);
		check(strpos($body, "<link rel=\"canonical\" href=\"$base/ro/zzp1b_about\">\n<link rel=\"alternate\" hreflang=\"en\" href=\"$base/zzp1b_about\">\n<link rel=\"alternate\" hreflang=\"ro\" href=\"$base/ro/zzp1b_about\">\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"$base/zzp1b_about\">") !== false, $body);
		check(strpos($body, '<script>var BASE = "'.$base.'/ro/"</script>') !== false, $body);
		// the starter: <html lang>, the switcher's rows, and the editor's API under /ro/
		list(, $about) = http('GET', "$base/ro/about");
		check(strpos($about, '<html lang="ro" dir="ltr">') !== false, $about);
		check(strpos($about, '<a class="nav-link" href="'.$base.'/about" hreflang="en">en</a>') !== false && strpos($about, '<a class="nav-link active" href="'.$base.'/ro/about" hreflang="ro">ro</a>') !== false, $about);
		$plain = http('GET', "$base/api/cms/style");
		$ro = http('GET', "$base/ro/api/cms/style");
		// the same answer, with its own address in the language (/ro/api/cms/style/output/true)
		same(array($plain[0], $plain[1]), array($ro[0], str_replace('\\/ro\\/', '\\/', $ro[1])), 'BASE + api/… answers as /api/… does');
		// the default language: canonical is its own address, BASE the site's
		// none on the 404 page, nor on an address with a query
		list($status, $missing) = http('GET', "$base/ro/zzp1c_nothing");
		same(404, $status);
		same(false, strpos($missing, 'hreflang'), $missing);
		same(false, strpos(http('GET', "$base/ro/zzp1b_about?x=1")[1], 'rel="canonical"'), 'a query');
		list(, $en) = http('GET', "$base/zzp1b_about");
		check(strpos($en, "<link rel=\"canonical\" href=\"$base/zzp1b_about\">") !== false && strpos($en, '<script>var BASE = "'.$base.'/"</script>') !== false, $en);
	});
});

// ## P1.A: the language API, the switch, doctor

// the i18n model's state for one request, put back afterwards: what
// detect() picked, and the request's $_GET, $_COOKIE and Accept-Language
function p1a_request($settings, $request, $fn) {
	$detected = new ReflectionProperty('i18n', 'detected');
	$detected->setAccessible(true);
	$kept = array($_GET, $_COOKIE, isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null, $detected->getValue());
	$request += array('get' => array(), 'cookie' => array(), 'accept' => null);
	$_GET = $request['get'];
	$_COOKIE = $request['cookie'];
	if ($request['accept'] === null) unset($_SERVER['HTTP_ACCEPT_LANGUAGE']); else $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $request['accept'];
	$detected->setValue(null, null);
	$settings += array('multilingual' => false, 'languages' => null, 'domain_language' => null, 'url_language' => null, 'link_base' => null, 'host' => config::get('host'), 'link_uri' => config::get('link_uri'), 'uri_string' => config::get('uri_string'), 'site_url' => null);
	try {
		return with_settings($settings, $fn);
	} finally {
		list($_GET, $_COOKIE, $accept) = $kept;
		if ($accept === null) unset($_SERVER['HTTP_ACCEPT_LANGUAGE']); else $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $accept;
		$detected->setValue(null, $kept[3]);
	}
}

function p1a_with_uri($uri, $fn) {
	return with_settings(array('uri_string' => $uri), $fn);
}

test('P1.A: off, the language API gives the one-language answer', function () {
	p1a_request(array(), array('get' => array('lang' => 'ro')), function () {
		same(false, i18n::enabled());
		same(array(), i18n::available());
		same('', i18n::default_language());
		same('', i18n::current());
		same('', i18n::detect());
		same(true, i18n::is_default());
		same('', i18n::suffix());
		same('', i18n::suffix('ro'));
		same('', i18n::prefix('ro'));
		same('ltr', i18n::dir());
		same('', i18n::date_order(), 'dates are read as before without languages (D14)');
		same(array(), i18n::alternates());
		same(false, (new i18n())->language());
	});
	// multilingual alone, or with one language, is off (D1)
	foreach (array(null, array('ro'), array('en', 'not a code')) as $languages) {
		p1a_request(array('multilingual' => true, 'languages' => $languages), array(), function () use ($languages) {
			same(false, i18n::enabled(), json_encode($languages));
			same('', i18n::prefix('ro'));
			same('', i18n::suffix('ro'));
		});
	}
});

test('P1.A: off, detect() picks as in 2.2.45: ?lang=, the domain, the cookie, the browser, the default', function () {
	$settings = array('languages' => array('en', 'ro', 'pt-BR'), 'domain_language' => array('example.ro' => 'ro'), 'host' => 'example.com');
	$cases = array(
		array(array(), 'en'),
		array(array('get' => array('lang' => 'ro')), 'ro'),
		array(array('get' => array('lang' => 'RO-ro')), 'ro'),
		array(array('get' => array('lang' => 'pt-br')), 'pt-BR'),
		array(array('get' => array('lang' => 'de'), 'cookie' => array('lang' => 'ro')), 'ro'),
		array(array('cookie' => array('lang' => 'ro'), 'accept' => 'en'), 'ro'),
		array(array('accept' => 'de-DE,ro;q=0.9,en;q=0.8'), 'ro'),
		// a region is matched whole, or by a listed two-letter code
		array(array('accept' => 'pt-PT'), 'en'),
		array(array('accept' => 'ro-MD'), 'ro'),
		array(array('accept' => 'de'), 'en'),
	);
	foreach ($cases as $case) {
		list($request, $expected) = $case;
		p1a_request($settings, $request, function () use ($expected, $request) {
			same($expected, i18n::detect(), json_encode($request));
			same($expected, i18n::current(), 'current() is detect() when off');
			same($expected, (new i18n())->language());
			// template-only: no columns and no addresses of its own
			same('', i18n::suffix());
			same('', i18n::prefix());
		});
	}
	// the domain wins over the cookie, a subdomain too
	foreach (array('example.ro', 'www.example.ro:8080') as $host) {
		p1a_request(array('host' => $host) + $settings, array('cookie' => array('lang' => 'en')), function () use ($host) {
			same('ro', i18n::detect(), $host);
		});
	}
	// a cookie of its own name
	p1a_request($settings + array('language_cookie' => 'cafe_lang'), array('cookie' => array('lang' => 'ro', 'cafe_lang' => 'pt-br')), function () {
		same('pt-BR', i18n::detect());
	});
	p1a_request($settings, array(), function () {
		same('en', i18n::default_language());
		same(true, i18n::is_default());
		same(false, i18n::is_default('ro'));
		same(true, i18n::is_default('EN'));
		same('dmy', i18n::date_order());
		same('mdy', i18n::date_order('en-US'));
		same('dmy', i18n::date_order('ro'));
	});
});

test('P1.A: multilingual, the address picks the language and names its column and prefix', function () {
	$settings = array('multilingual' => true, 'languages' => array('en', 'ro', 'pt-BR', 'id'), 'link_base' => 'https://example.com/', 'link_uri' => 'https://example.com/ro/', 'host' => 'example.com', 'uri_string' => '/menu?x=1');
	p1a_request($settings + array('url_language' => 'ro'), array('get' => array('lang' => 'en'), 'cookie' => array('lang' => 'en'), 'accept' => 'en'), function () {
		same(true, i18n::enabled());
		same('ro', i18n::current(), 'neither ?lang=, the cookie nor the browser choose (D4)');
		same('ro', i18n::detect(), 'detect() is current()');
		same(false, i18n::is_default());
		same('__ro', i18n::suffix());
		same('', i18n::suffix('en'));
		same('__pt_br', i18n::suffix('pt-BR'));
		same('__id', i18n::suffix('id'));
		same('ro/', i18n::prefix());
		same('pt-br/', i18n::prefix('pt-BR'));
		same('', i18n::prefix('en'));
		same(array(
			array('lang' => 'en', 'url' => 'https://example.com/menu'),
			array('lang' => 'ro', 'url' => 'https://example.com/ro/menu'),
			array('lang' => 'pt-BR', 'url' => 'https://example.com/pt-br/menu'),
			array('lang' => 'id', 'url' => 'https://example.com/id/menu'),
		), i18n::alternates());
		same('https://example.com/ro/', i18n::alternates('')[1]['url'], 'a home page is /ro/ (D5)');
		same('https://example.com/ro/news/news_item/x', i18n::alternates('/news/news_item/x')[1]['url']);
		// each segment encoded, as the one-address redirect writes it
		same('https://example.com/ro/caf%C3%A9/a%20b%3F', i18n::alternates('/café/a b?')[1]['url']);
		same('https://example.com/ro/meniu/ciorb%C4%83', p1a_with_uri('/meniu/ciorbă', function () { return i18n::alternates()[1]['url']; }));
		same('ro', (new i18n())->language());
	});
	// no language in the address is the default; an unknown one too
	foreach (array('', 'de', null) as $url_language) {
		p1a_request($settings + array('url_language' => $url_language), array('get' => array('lang' => 'ro')), function () use ($url_language) {
			same('en', i18n::current(), var_export($url_language, true));
			same(true, i18n::is_default());
			same('', i18n::suffix());
			same('', i18n::prefix());
		});
	}
	p1a_request($settings + array('url_language' => 'RO'), array(), function () {
		same('ro', i18n::current(), 'as the site lists it');
	});
});

test('P1.A: multilingual, a domain_language host is its language\'s address', function () {
	$settings = array('multilingual' => true, 'languages' => array('en', 'ro', 'de'), 'domain_language' => array('example.ro' => 'ro'), 'site_url' => 'https://example.com/', 'uri_string' => '/menu', 'protocol' => 'https');
	// on the main host the Romanian pages are on their own domain, though /ro/ answers too
	p1a_request($settings + array('host' => 'example.com', 'link_base' => 'https://example.com/', 'link_uri' => 'https://example.com/'), array(), function () {
		same('ro/', i18n::prefix('ro'), 'link_uri on /ro/ here keeps the prefix');
		same(array(
			array('lang' => 'en', 'url' => 'https://example.com/menu'),
			array('lang' => 'ro', 'url' => 'https://example.ro/menu'),
			array('lang' => 'de', 'url' => 'https://example.com/de/menu'),
		), i18n::alternates());
		same('https://example.ro/', i18n::with('ro', function () { return config::get('link_uri'); }));
		same('https://example.com/de/', i18n::with('de', function () { return config::get('link_uri'); }));
	});
	// on the Romanian host the others are on the site's address, with its
	// folder and index file
	p1a_request($settings + array('host' => 'example.ro:8443', 'url_language' => 'ro', 'rewrite' => false, 'link_base' => 'https://example.ro:8443/shop/index.php/', 'link_uri' => 'https://example.ro:8443/shop/index.php/'), array(), function () {
		same('ro', i18n::current());
		same('', i18n::prefix(), 'no prefix on its own host');
		same(array(
			array('lang' => 'en', 'url' => 'https://example.com/shop/index.php/menu'),
			array('lang' => 'ro', 'url' => 'https://example.ro:8443/shop/index.php/menu'),
			array('lang' => 'de', 'url' => 'https://example.com/shop/index.php/de/menu'),
		), i18n::alternates());
		same('https://example.com/shop/index.php/', i18n::with('en', function () { return config::get('link_uri'); }));
		same('https://example.ro:8443/shop/index.php/', i18n::with('ro', function () { return config::get('link_uri'); }));
		same('https://example.ro:8443/shop/index.php/', config::get('link_uri'));
	});
	// on the Romanian host with no site address the others have none: left
	// out, and with() keeps the links where they are
	p1a_request(array('site_url' => null, 'host' => 'example.ro', 'url_language' => 'ro', 'link_base' => 'https://example.ro/', 'link_uri' => 'https://example.ro/') + $settings, array(), function () {
		same(array(array('lang' => 'ro', 'url' => 'https://example.ro/menu')), i18n::alternates());
		same(array('en', 'https://example.ro/'), i18n::with('en', function () { return array(i18n::current(), config::get('link_uri')); }));
	});
	// a site address that is itself a language's domain doesn't count
	p1a_request(array('site_url' => 'https://example.ro/', 'host' => 'example.ro', 'url_language' => 'ro', 'link_base' => 'https://example.ro/', 'link_uri' => 'https://example.ro/') + $settings, array(), function () {
		same(array('ro'), array_column(i18n::alternates(), 'lang'));
	});
	// a language on its own domain from another language's domain
	p1a_request(array('domain_language' => array('example.ro' => 'ro', 'example.de' => 'de'), 'host' => 'example.de', 'url_language' => 'de', 'link_base' => 'https://example.de/', 'link_uri' => 'https://example.de/') + $settings, array(), function () {
		same(array('https://example.com/menu', 'https://example.ro/menu', 'https://example.de/menu'), array_column(i18n::alternates(), 'url'));
	});
});

test('P1.A: a preview of a site with language domains serves every language under its prefix', function () {
	$on = "config::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\nconfig::set('domain_language')->to(array('zzp1a-ro.test' => 'ro'));";
	// the site's own address is site_url; the preview's RASTER_URL is another host
	p1b_site($on."\nconfig::set('site_url')->to('https://zzp1a-site.test/');", function () {
		p1b_server(array('RASTER_URL' => 'http://127.0.0.1:{port}/preview/abc/'), function ($port) {
			$base = "http://127.0.0.1:$port/preview/abc/";
			list($status, $body, $headers) = http('GET', $base.'ro/zzp1b_menu');
			same(200, $status, 'not sent to the language\'s domain');
			check(strpos($body, '<h1>Meniu</h1>') !== false, 'Romanian');
			check(strpos($body, 'href="'.$base.'ro/zzp1b_about"') !== false, 'links under /preview/abc/ro/: '.$body);
			check(strpos($body, 'zzp1a-ro.test') === false, 'nothing points at the domain');
			list($status, , $headers) = http('GET', $base.'zzp1b_menu?lang=ro');
			same('301 '.$base.'ro/zzp1b_menu', $status.' '.location($headers));
			list($status, , $headers) = http('GET', $base.'ro');
			same('301 '.$base.'ro/', $status.' '.location($headers));
		});
	});
	// without site_url, RASTER_URL is the site's own address, and the language lives on its domain
	p1b_site($on, function () {
		p1b_server(array('RASTER_URL' => 'http://127.0.0.1:{port}/preview/abc/'), function ($port) {
			list($status, , $headers) = http('GET', "http://127.0.0.1:$port/preview/abc/ro/zzp1b_menu");
			same('301 http://zzp1a-ro.test/preview/abc/zzp1b_menu', $status.' '.location($headers));
		});
	});
	// in the API: a host that is neither the site's address nor a language's domain
	p1a_request(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.ro' => 'ro'), 'site_url' => 'https://example.com/', 'protocol' => 'http', 'host' => 'localhost:8000', 'link_base' => 'http://localhost:8000/', 'link_uri' => 'http://localhost:8000/', 'uri_string' => '/menu'), array(), function () {
		same(array('http://localhost:8000/menu', 'http://localhost:8000/ro/menu'), array_column(i18n::alternates(), 'url'));
		same('http://localhost:8000/ro/', i18n::address('ro'));
	});
});

test('P1.A: i18n::with() runs in a language, nests, and puts everything back, also after an exception', function () {
	$settings = array('multilingual' => true, 'languages' => array('en', 'ro', 'fr'), 'link_base' => 'https://example.com/', 'link_uri' => 'https://example.com/', 'host' => 'example.com', 'url_language' => '');
	p1a_request($settings, array(), function () {
		$seen = array();
		$result = i18n::with('ro', function () use (&$seen) {
			$seen[] = array(i18n::current(), i18n::detect(), i18n::suffix(), i18n::prefix(), config::get('link_uri'));
			$inner = i18n::with('fr', function () use (&$seen) {
				$seen[] = array(i18n::current(), i18n::detect(), i18n::suffix(), i18n::prefix(), config::get('link_uri'));
				return 'inner';
			});
			$seen[] = array(i18n::current(), i18n::detect(), i18n::suffix(), i18n::prefix(), config::get('link_uri'));
			return $inner.' outer';
		});
		same('inner outer', $result);
		same(array(
			array('ro', 'ro', '__ro', 'ro/', 'https://example.com/ro/'),
			array('fr', 'fr', '__fr', 'fr/', 'https://example.com/fr/'),
			array('ro', 'ro', '__ro', 'ro/', 'https://example.com/ro/'),
		), $seen);
		same('en', i18n::current());
		same('https://example.com/', config::get('link_uri'));
		// the default, by name or as a language the site doesn't list
		same(array('en', 'https://example.com/'), i18n::with('EN', function () { return array(i18n::current(), config::get('link_uri')); }));
		same(array('en', 'https://example.com/'), i18n::with('de', function () { return array(i18n::current(), config::get('link_uri')); }));
		same('ro', i18n::with('ro-RO', function () { return i18n::current(); }));
		try {
			i18n::with('ro', function () {
				i18n::with('fr', function () { throw new RuntimeException('boom'); });
			});
			check(false, 'the exception goes through');
		} catch (RuntimeException $e) {
			same('boom', $e->getMessage());
		}
		same('en', i18n::current(), 'put back after the exception');
		same('https://example.com/', config::get('link_uri'));
	});
	// off: the language changes, the links don't
	p1a_request(array('languages' => array('en', 'ro')), array('cookie' => array('lang' => 'en')), function () {
		$link = config::get('link_uri');
		same(array('ro', 'ro', '', '', $link), i18n::with('ro', function () { return array(i18n::current(), i18n::detect(), i18n::suffix(), i18n::prefix(), config::get('link_uri')); }));
		same('en', i18n::detect(), 'and detect() answers as before afterwards');
	});
	p1a_request(array(), array(), function () {
		same('', i18n::with('ro', function () { return i18n::current(); }), 'no languages: nothing to run in');
	});
});

test('P1.A: dir() names the right-to-left languages', function () {
	foreach (array('ar', 'he', 'he-IL', 'fa', 'ur', 'yi', 'ckb', 'AR-eg') as $lang) same('rtl', i18n::dir($lang), $lang);
	foreach (array('en', 'ro', 'pt-br', 'id', '', 'arn') as $lang) same('ltr', i18n::dir($lang), $lang);
	p1a_request(array('multilingual' => true, 'languages' => array('en', 'ar'), 'url_language' => 'ar'), array(), function () {
		same('rtl', i18n::dir());
	});
});

test('P1.A: dictionaries in the current language, and the older $lang form', function () use ($root) {
	$dir = APPBASE.'i18n';
	$made = !is_dir($dir);
	test_fixture("$dir/zz");
	@mkdir("$dir/zz", 0775, true);
	file_put_contents("$dir/zz/zzp1a.php", "<?php\nreturn array('hello' => 'Zalut');\n");
	file_put_contents("$dir/zz/zzp1aold.php", "<?php\n\$lang = array('hello' => 'Zold');\n");
	try {
		$fresh = function () { return new i18n(); };
		// template-only: ?lang= picks it
		p1a_request(array('languages' => array('en', 'zz')), array('get' => array('lang' => 'zz')), function () use ($fresh) {
			same('Zalut', $fresh()->zzp1a('hello'));
			same('Zold', $fresh()->zzp1aold('hello'), 'the older form still works until 3.0.0');
			same(false, $fresh()->zzp1a('missing'));
		});
		p1a_request(array('languages' => array('en', 'zz')), array(), function () use ($fresh) {
			same(false, $fresh()->zzp1a('hello'), 'the default language keeps the template text');
			same('Zalut', i18n::with('zz', function () use ($fresh) { return $fresh()->zzp1a('hello'); }), 'with() renders in its language');
		});
		// multilingual: the address picks it, ?lang= doesn't
		p1a_request(array('multilingual' => true, 'languages' => array('en', 'zz'), 'url_language' => 'zz'), array('get' => array('lang' => 'en')), function () use ($fresh) {
			same('Zalut', $fresh()->zzp1a('hello'));
		});
		p1a_request(array('multilingual' => true, 'languages' => array('en', 'zz'), 'url_language' => ''), array('get' => array('lang' => 'zz')), function () use ($fresh) {
			same(false, $fresh()->zzp1a('hello'));
		});
		// the deprecation finds the older form, and only it
		$found = array_column(array_filter(raster_project::deprecations($root, 'application'), function ($d) { return $d['id'] === 'i18n-lang-variable'; }), 'file');
		same(array('application/i18n/zz/zzp1aold.php'), $found);
	} finally {
		remove_dir("$dir/zz");
		if ($made) @rmdir($dir);
	}
});

test('P1.A: off, the page cache key is the one 2.2.45 made', function () {
	$key = function ($language) { return sha1(config::get('protocol').'://'.config::get('host').'|'.config::get('uri_string').'|'.$language); };
	p1a_request(array(), array('get' => array('lang' => 'ro')), function () use ($key) {
		same($key(''), raster_cache::key(), 'no languages');
	});
	p1a_request(array('languages' => array('en', 'ro')), array('cookie' => array('lang' => 'ro')), function () use ($key) {
		same($key('ro'), raster_cache::key(), 'template-only: one copy per language, as detected');
	});
	p1a_request(array('languages' => array('en', 'ro')), array(), function () use ($key) {
		same($key('en'), raster_cache::key());
	});
});

test('P1.A: describe lists the multilingual setting, off by default', function () {
	require_once BASE.'tools/describe.php';
	$settings = raster_describe::site(array('settings'))['settings'];
	check(array_key_exists('multilingual', $settings), 'listed');
	same(false, $settings['multilingual']);
});

test('P1.A: doctor fails on multilingual without two languages and on a code it can\'t read', function () use ($root) {
	$titles = function ($settings) use ($root) {
		return with_settings($settings, function () use ($root) {
			$found = array();
			foreach (raster_project::doctor($root, 'application', 'development') as $c) {
				// (the missing language columns are P5.B's check, tested on its own)
				if (preg_match('/language|multilingual/i', $c['title']) && strpos($c['title'], 'column') === false) $found[] = $c['status'].' '.$c['title'];
			}
			return $found;
		});
	};
	same(array(), $titles(array('multilingual' => false, 'languages' => null)), 'nothing to say without languages');
	same(array('fail multilingual is on with fewer than two languages'), $titles(array('multilingual' => true, 'languages' => array('ro'))));
	same(array('fail multilingual is on with fewer than two languages'), $titles(array('multilingual' => true, 'languages' => null)));
	same(array('ok Multilingual: en, ro'), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'))));
	same(array("fail Language code(s) Raster can't read: 'en_US', 'Romanian'", 'fail multilingual is on with fewer than two languages'),
		$titles(array('multilingual' => true, 'languages' => array('en_US', 'ro'), 'domain_language' => array('example.ro' => 'Romanian'))));
	same(array(), $titles(array('multilingual' => false, 'languages' => array('en', 'pt-br'), 'domain_language' => array('example.ro' => 'pt-br'))));
	// a language's own domain needs the site's address to link the others on
	same(array('ok Multilingual: en, ro', "fail domain_language needs the site's address"), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.ro' => 'ro'), 'site_url' => null)));
	same(array('ok Multilingual: en, ro', "fail domain_language needs the site's address"), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.ro' => 'ro'), 'site_url' => 'https://example.ro/')));
	same(array('ok Multilingual: en, ro'), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.ro' => 'ro'), 'site_url' => 'https://example.com/')));
	// every language on its own domain needs no site address to link the others on
	same(array('ok Multilingual: en, ro'), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.com' => 'en', 'example.ro' => 'ro'), 'site_url' => 'https://example.com/')));
	same(array('ok Multilingual: en, ro'), $titles(array('multilingual' => true, 'languages' => array('en', 'ro'), 'domain_language' => array('example.com' => 'en', 'example.ro' => 'ro'), 'site_url' => null)));
});

// ## P2.C: the coding MCP builds multilingual sites (#159)

// The starter with a fixture page, its own database and the_app.php as it
// was, put back afterwards: what an agent finds before it adds a language.
// The page has a heading (translated), a price (an int: shared) and a
// dictionary word.
function p2c_site($fn) {
	global $root;
	$theme = "$root/application/views/default";
	$views = array(
		'zzp2c_menu' => '<!doctype html><!-- print.@lang.i18n.language --><html lang="en"><!-- /print.@lang.i18n.language --><head><title>Menu</title></head><body>'
			.'<h1><!-- print.cms.zzp2c_heading -->Our menu<!-- /print.cms.zzp2c_heading --></h1>'
			.'<p class="price"><!-- print.cms.zzp2c_price -->14<!-- /print.cms.zzp2c_price --></p>'
			.'<button><!-- print.i18n.zzp2c(\'order\') -->Order now<!-- /print.i18n.zzp2c(\'order\') --></button>'
			.'</body></html>',
	);
	foreach ($views as $name => $html) {
		test_fixture("$theme/$name.html");
		file_put_contents("$theme/$name.html", $html);
	}
	$config = APPBASE.'config/the_app.php';
	$kept = file_get_contents($config);
	// the dictionaries as they were: an agent may write any of them
	$i18n = APPBASE.'i18n';
	$made = !is_dir($i18n);
	$dictionaries = array();
	foreach (glob("$i18n/*/*.php") ?: array() as $file) $dictionaries[$file] = file_get_contents($file);
	$db = sys_get_temp_dir().'/raster-p2c-'.getmypid().'.sqlite';
	try {
		return $fn($db);
	} finally {
		file_put_contents($config, $kept);
		foreach (array_keys($views) as $name) @unlink("$theme/$name.html");
		foreach (glob("$i18n/*/*.php") ?: array() as $file) {
			if (!isset($dictionaries[$file])) unlink($file);
		}
		foreach ($dictionaries as $file => $source) file_put_contents($file, $source);
		foreach (glob("$i18n/*", GLOB_ONLYDIR) ?: array() as $dir) @rmdir($dir);
		if ($made) @rmdir($i18n);
		array_map('unlink', glob("$db*") ?: array());
	}
}

// MCP over stdio one call at a time, as an agent works: each answer can
// decide the next call
class p2c_mcp {
	public $process, $pipes, $id = 100;
	function __construct($env = array()) {
		global $root, $db;
		$env = $env + array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'RASTER_ENV' => 'development', 'PATH' => getenv('PATH'));
		$this->process = proc_open(php_argv("$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $this->pipes, $root, $env);
	}
	function message($method, $params = array()) {
		fwrite($this->pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params ?: new stdClass()))."\n");
		fflush($this->pipes[0]);
		$line = fgets($this->pipes[1]);
		if ($line === false) throw new Exception("mcp $method: no answer");
		return json_decode($line, true);
	}
	// the answer, or array('error' => the message) when the tool refused
	function call($name, $arguments = array()) {
		$result = $this->message('tools/call', array('name' => $name, 'arguments' => $arguments ?: new stdClass()))['result'];
		return !empty($result['isError']) ? array('error' => $result['content'][0]['text']) : $result['structuredContent'];
	}
	function close() {
		@fclose($this->pipes[0]);
		@fclose($this->pipes[1]);
		proc_close($this->process);
	}
}
function p2c_session($env, $fn) {
	$mcp = new p2c_mcp($env);
	try {
		return $fn($mcp);
	} finally {
		$mcp->close();
	}
}
// the statements a config file runs, without its comments and blank lines
function p2c_statements($source) {
	$out = '';
	foreach (token_get_all($source) as $token) {
		if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG), true)) continue;
		$out .= is_array($token) ? $token[1] : $token;
	}
	return $out;
}

test('P2.C: set_languages writes the two settings in their canonical form and leaves the rest of the file', function () {
	$source = "<?php\n// config::set('languages')->to(array('fr'));\nconfig::set('theme')->to('default');\nconfig::set( 'languages' )->to(array(\n\t'en',\n\t'de', // German\n));\nconfig::set('protected')->to(array('x' => 'member'));\n";
	$lines = array('multilingual' => "config::set('multilingual')->to(true);", 'languages' => "config::set('languages')->to(array('en', 'ro'));");
	same("<?php\n// config::set('languages')->to(array('fr'));\nconfig::set('theme')->to('default');\nconfig::set('languages')->to(array('en', 'ro'));\nconfig::set('protected')->to(array('x' => 'member'));\nconfig::set('multilingual')->to(true);\n", mcp::config_lines($source, $lines), 'replaced where it was, the commented example kept, the missing one added');
	same(mcp::config_lines($source, $lines), mcp::config_lines(mcp::config_lines($source, $lines), $lines), 'a second run changes nothing');
	// a setting set twice keeps the first place, once
	same("<?php\nconfig::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);\n\$x = 1;\n", mcp::config_lines("<?php\nconfig::set('languages')->to(array('ro'));\n\$x = 1;\nconfig::set(\"languages\")->to(array('de'));\n", $lines));
	// null removes both, whole lines, and nothing else
	same("<?php\nconfig::set('theme')->to('default');\n// config::set('multilingual')->to(true);\n", mcp::config_lines("<?php\nconfig::set('multilingual')->to(true);\nconfig::set('theme')->to('default');\n  config::set('languages')->to(array('en', 'ro'));\n// config::set('multilingual')->to(true);\n", array('multilingual' => null, 'languages' => null)));
	// without a config::set, at the start of PHP: never after a return or a closing tag
	same("<?php\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\n\$a = 1;\nreturn;\n?>\n<p>x</p>", mcp::config_lines("<?php\n\$a = 1;\nreturn;\n?>\n<p>x</p>", $lines));
	// after the last config::set, inside PHP, when HTML follows
	same("<?php\nconfig::set('db')->to('keep');\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\n?>\n<p>html</p>\n", mcp::config_lines("<?php\nconfig::set('db')->to('keep');\n?>\n<p>html</p>\n", $lines));
	// a statement a closing tag ends is only that statement
	$closed = "<?php config::set('languages')->to(array('en')) ?>\n<?php\nconfig::set('db')->to('keep');";
	same("<?php config::set('languages')->to(array('en', 'ro')); ?>\n<?php\nconfig::set('db')->to('keep');\nconfig::set('multilingual')->to(true);", mcp::config_lines($closed, $lines));
	same("<?php  ?>\n<?php\nconfig::set('db')->to('keep');", mcp::config_lines($closed, array('multilingual' => null, 'languages' => null)));
	same("<?php config::set('db')->to('keep');\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro')); ?>x", mcp::config_lines("<?php config::set('db')->to('keep') ?>x", $lines), 'a ; for the statement before');
	// \config::set too, and the file's own line endings
	same("<?php\r\nconfig::set('theme')->to('x');\r\nconfig::set('languages')->to(array('en', 'ro'));\r\nconfig::set('multilingual')->to(true);\r\n", mcp::config_lines("<?php\r\nconfig::set('theme')->to('x');\r\n\\config::set('languages')->to(array('de'));\r\n", $lines));
	// refused rather than guessed: the body of an if or else without braces
	foreach (array("<?php\nif (\$x) config::set('languages')->to(array('de'));\n", "<?php\nif (\$x) {} else config::set('multilingual')->to(true);\n") as $braceless) {
		try {
			mcp::config_lines($braceless, $lines);
			check(false, 'changed: '.$braceless);
		} catch (InvalidArgumentException $e) {
			check(strpos($e->getMessage(), 'without braces') !== false, $e->getMessage());
		}
	}
	// in a function, a closure or an if block it would count only when that runs: refused
	foreach (array("<?php\nif (\$x) {\n\tconfig::set('languages')->to(array('de'));\n}\n", "<?php\nfunction zz() { config::set('multilingual')->to(true); }\n", "<?php\n\$f = function () { config::set('languages')->to(array('de')); };\n") as $nested) {
		try {
			mcp::config_lines($nested, $lines);
			check(false, 'changed: '.$nested);
		} catch (InvalidArgumentException $e) {
			check(strpos($e->getMessage(), 'inside a block') !== false, $e->getMessage());
		}
	}
	// after a leading declare and namespace, which must stay first
	$declared = mcp::config_lines("<?php\ndeclare(strict_types=1);\n\$a = 1;\n", $lines);
	same("<?php\ndeclare(strict_types=1);\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\n\n\$a = 1;\n", $declared);
	check(mcp::compiles($declared) && !mcp::compiles("<?php\nconfig::set('x')->to(1);\ndeclare(strict_types=1);\n"), 'compiles() tells them apart');
	check(mcp::compiles(mcp::config_lines("<?php declare(strict_types=1);\nnamespace zz;\n", $lines)), 'after a namespace line');
	// never after a top-level return, where nothing runs
	same("<?php\nconfig::set('theme')->to('x');\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\nreturn;\nconfig::set('db')->to(1);\n", mcp::config_lines("<?php\nconfig::set('theme')->to('x');\nreturn;\nconfig::set('db')->to(1);\n", $lines));
	same("<?php\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\n\$a = 1;\nreturn \$a;\n", mcp::config_lines("<?php\n\$a = 1;\nreturn \$a;\n", $lines));
	// a file with only an open tag, or nothing yet
	$fresh = "<?php\nconfig::set('multilingual')->to(true);\nconfig::set('languages')->to(array('en', 'ro'));\n";
	same($fresh, mcp::config_lines('<?php', $lines));
	same($fresh, mcp::config_lines('', $lines));
	// a single-quoted name reads no escapes but \\ and \'
	same(array("a\\nb", "it's", "a\nb"), array_column(mcp::config_statements("<?php config::set('a\\nb')->to(1); config::set('it\\'s')->to(1); config::set(\"a\\nb\")->to(1);"), 'name'));
	// what PHP would make of each result: every other statement as it was
	foreach (array($source, $closed, "<?php\nconfig::set('db')->to('keep');\n?>\n<p>html</p>\n") as $file) {
		$after = mcp::config_lines($file, $lines);
		same(array_values(array_filter(array_column(mcp::config_statements($file), 'name'), function ($n) { return !in_array($n, array('languages', 'multilingual'), true); })), array_values(array_filter(array_column(mcp::config_statements($after), 'name'), function ($n) { return !in_array($n, array('languages', 'multilingual'), true); })));
		same(1, count(array_filter(mcp::config_statements($after), function ($s) { return $s['name'] === 'languages'; })));
	}
});

test('P2.C: set_languages over MCP: on, one language, off; bad codes refused, production refuses', function () {
	p2c_site(function ($db) {
		$config = APPBASE.'config/the_app.php';
		$before = file_get_contents($config);
		p2c_session(array('RASTER_DB' => $db), function ($mcp) use ($config, $before) {
			$on = $mcp->call('set_languages', array('languages' => array('EN', 'ro')));
			check(!isset($on['error']), json_encode($on));
			same(true, $on['enabled']);
			same('en', $on['default']);
			same(array('en', 'ro'), $on['languages']);
			same(array('en' => '/', 'ro' => '/ro/'), $on['urls']);
			check(is_int($on['columns_added']), 'columns_added');
			check(array_key_exists('ro', $on['missing']), 'missing per translation language: '.json_encode($on['missing']));
			$written = file_get_contents($config);
			check(strpos($written, "config::set('multilingual')->to(true);\n") !== false && strpos($written, "config::set('languages')->to(array('en', 'ro'));\n") !== false, $written);
			same(p2c_statements($before), str_replace(array("config::set('multilingual')->to(true);", "config::set('languages')->to(array('en','ro'));"), '', p2c_statements($written)), 'the rest of the file is as it was');
			// the next call reads the file again
			same(true, $mcp->call('describe', array('sections' => array('languages')))['languages']['enabled']);
			// one language: languages set, multilingual off
			$one = $mcp->call('set_languages', array('languages' => array('ro')));
			same(false, $one['enabled']);
			same('ro', $one['default']);
			same(array(), $one['urls']);
			$written = file_get_contents($config);
			check(strpos($written, "config::set('multilingual')->to(false);") !== false && strpos($written, "config::set('languages')->to(array('ro'));") !== false, $written);
			// [] takes both out; nothing else changed
			same(false, $mcp->call('set_languages', array('languages' => array()))['enabled']);
			same(p2c_statements($before), p2c_statements(file_get_contents($config)));
			foreach (array(array('en', 'en'), array('english'), array('en_US'), array('en', ''), array('ro' => 'ro'), 'en,ro', array(array('en'))) as $bad) {
				check(isset($mcp->call('set_languages', array('languages' => $bad))['error']), 'refused: '.json_encode($bad));
			}
			same(p2c_statements($before), p2c_statements(file_get_contents($config)), 'a refusal writes nothing');
		});
		// production refuses, and writes nothing
		p2c_session(array('RASTER_DB' => $db, 'RASTER_ENV' => 'production'), function ($mcp) use ($config, $before) {
			$refused = $mcp->call('set_languages', array('languages' => array('en', 'ro')));
			check(isset($refused['error']) && strpos($refused['error'], 'production') !== false, json_encode($refused));
			$refused = $mcp->call('write_strings', array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('order' => 'Comandă')));
			check(isset($refused['error']) && strpos($refused['error'], 'production') !== false, json_encode($refused));
			same($before, file_get_contents($config));
			check(!is_file(APPBASE.'i18n/ro/zzp2c.php'), 'no dictionary written');
		});
	});
});

test('P2.C: read_strings and write_strings: the agent sends words, Raster writes the PHP', function () {
	p2c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$read = $mcp->call('read_strings', array('file' => 'zzp2c', 'lang' => 'ro'));
			same(array(), $read['strings']);
			same(array('order'), $read['used_keys'], 'the keys the views print');
			same(array('order'), $read['missing_keys']);
			// words that look like PHP stay words
			$php = "<?php system('id'); ?>";
			$written = $mcp->call('write_strings', array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('order' => 'Comandă acum', 'extra.key-1' => $php)));
			same(array('written' => 2, 'unused_keys' => array('extra.key-1')), $written, 'a key no view prints is a warning');
			$file = APPBASE.'i18n/ro/zzp2c.php';
			$source = file_get_contents($file);
			same("<?php\nreturn ".var_export(array('order' => 'Comandă acum', 'extra.key-1' => $php), true).";\n", $source, 'var_export, nothing else');
			same(array('order' => 'Comandă acum', 'extra.key-1' => $php), include $file);
			// it merges
			$mcp->call('write_strings', array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('order' => 'Comandă')));
			$read = $mcp->call('read_strings', array('file' => 'zzp2c', 'lang' => 'ro'));
			same(array('order' => 'Comandă', 'extra.key-1' => $php), $read['strings']);
			same(array(), $read['missing_keys']);
			// what is refused
			foreach (array(
				array('file' => '../zzp2c', 'lang' => 'ro', 'strings' => array('a' => 'b')),
				array('file' => 'Zzp2c', 'lang' => 'ro', 'strings' => array('a' => 'b')),
				array('file' => 'zzp2c', 'lang' => 'de', 'strings' => array('a' => 'b')),
				array('file' => 'zzp2c', 'lang' => '../ro', 'strings' => array('a' => 'b')),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('a b' => 'c')),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array("a'b" => 'c')),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('a' => array('b'))),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('a' => 3)),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('x')),
				array('file' => "zzp2c\n", 'lang' => 'ro', 'strings' => array('a' => 'b')),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array("a\n" => 'b')),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('big' => str_repeat('x', 1048577))),
				array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array()),
			) as $arguments) {
				check(isset($mcp->call('write_strings', $arguments)['error']), 'refused: '.json_encode($arguments));
			}
			same(array('order' => 'Comandă', 'extra.key-1' => $php), include $file, 'nothing written by a refusal');
			check(!is_file(APPBASE.'i18n/de/zzp2c.php'));
			check(isset($mcp->call('read_strings', array('file' => "zzp2c\n", 'lang' => 'ro'))['error']), 'a name ends where it ends');
			// a dictionary or a language folder that links out of i18n/ is refused
			$outside = sys_get_temp_dir().'/raster-p2c-i18n-'.getmypid();
			@mkdir($outside);
			symlink("$outside/target.php", APPBASE.'i18n/ro/zzp2clink.php');
			try {
				check(isset($mcp->call('write_strings', array('file' => 'zzp2clink', 'lang' => 'ro', 'strings' => array('order' => 'x')))['error']), 'a file linking out');
				check(!file_exists("$outside/target.php"), 'nothing written through it');
			} finally {
				unlink(APPBASE.'i18n/ro/zzp2clink.php');
			}
			$real = APPBASE.'i18n/ro';
			rename($real, "$real.zzkept");
			@mkdir($outside);
			symlink($outside, $real);
			try {
				check(isset($mcp->call('write_strings', array('file' => 'zzp2c', 'lang' => 'ro', 'strings' => array('order' => 'x')))['error']), 'a folder linking out');
				same(array(), glob("$outside/*"), 'nothing written there');
			} finally {
				unlink($real);
				rename("$real.zzkept", $real);
				@rmdir($outside);
			}
			// a key with no text in the template: used, never missing, in both tools
			file_put_contents(APPBASE.'views/default/zzp2c_empty.html', '<p><!-- print.i18n.zzp2c(\'blank\') /--></p>');
			test_fixture(APPBASE.'views/default/zzp2c_empty.html');
			try {
				$read = $mcp->call('read_strings', array('file' => 'zzp2c', 'lang' => 'ro'));
				check(in_array('blank', $read['used_keys'], true) && !in_array('blank', $read['missing_keys'], true), json_encode($read));
				check(!in_array('blank', array_column($mcp->call('translation_status', array('lang' => 'ro'))['missing'], 'field'), true), 'nor in translation_status');
			} finally {
				unlink(APPBASE.'views/default/zzp2c_empty.html');
			}
			// the editor's own words
			$editor = $mcp->call('read_strings', array('file' => 'raster_editor', 'lang' => 'ro'));
			check(in_array('save', $editor['used_keys'], true), 'the editor\'s keys');
			same(array(), $editor['missing_keys'], 'the editor has words of its own');
			same(array(), $mcp->call('write_strings', array('file' => 'raster_editor', 'lang' => 'ro', 'strings' => array('save' => 'Păstrează', 'action_confirm' => 'Confirmă')))['unused_keys']);
			same('Păstrează', (include APPBASE.'i18n/ro/raster_editor.php')['save']);
			unlink(APPBASE.'i18n/ro/raster_editor.php');
			// translation_status counts the dictionary
			$status = $mcp->call('translation_status', array('lang' => 'ro'));
			// (the starter's own words are keys too, untranslated here)
			$left = array_filter($status['missing'], function ($row) { return $row['kind'] === 'strings' && $row['where'] === 'zzp2c'; });
			same(array(), array_values($left), 'translated words are done');
			check(isset($mcp->call('translation_status', array('lang' => 'en'))['error']), 'the default language is refused');
			check(isset($mcp->call('translation_status', array('lang' => 'de'))['error']), 'a language the site lacks too');
		});
	});
});

test('P2.C: off, the content tools answer as before', function () {
	p2c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$page = $mcp->call('update_page', array('page' => '/zzp2c_menu', 'fields' => array('zzp2c_heading' => 'Lunch')));
			same(array('url', 'view', 'revision', 'updated_at', 'fields'), array_keys($page));
			same('Lunch', $page['fields']['zzp2c_heading']);
			same(14, $page['fields']['zzp2c_price']);
			check(isset($mcp->call('get_page', array('page' => '/zzp2c_menu', 'lang' => 'ro'))['error']), 'no other language to ask for');
			check(isset($mcp->call('update_page', array('page' => '/zzp2c_menu', 'fields' => array('zzp2c_heading__ro' => 'x')))['error']), 'a translation column is never a field');
			$check = $mcp->call('check_view', array('content' => '<h1><!-- print.cms.zzp2c_new -->New<!-- /print.cms.zzp2c_new --></h1>', 'view' => 'zzp2c_menu.html'));
			same(array('view', 'errors', 'warnings', 'problems'), array_keys($check), 'no translate report');
			same(array('enabled' => false, 'multilingual_setting' => false, 'default' => '', 'list' => array(), 'urls' => array(), 'missing' => array()), $mcp->call('describe', array('sections' => array('languages')))['languages']);
		});
		// no column for another language is made
		$pdo = new PDO('sqlite:'.$db);
		$table = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'zzp2cmenu%page'")->fetchColumn();
		$names = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
		check($names && !preg_grep('/__/', $names), json_encode($names));
	});
});

test('P2.C: an agent turns a one-language site into a bilingual one over MCP (needs P2.A, P2.B)', function () {
	p2c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			// the page exists, in English, before languages are turned on
			$mcp->call('update_page', array('page' => '/zzp2c_menu', 'fields' => array('zzp2c_heading' => 'Our lunch menu')));
			$on = $mcp->call('set_languages', array('languages' => array('en', 'ro')));
			same(array('en' => '/', 'ro' => '/ro/'), $on['urls']);
			check($on['columns_added'] > 0, 'translation columns: '.json_encode($on));
			check($on['missing']['ro'] > 0, json_encode($on));
			// the page says which field is translated and which is shared
			$page = $mcp->call('get_page', array('page' => '/zzp2c_menu', 'lang' => 'ro'));
			same(array('value' => '', 'default_value' => 'Our lunch menu', 'translated' => false, 'shared' => false), $page['fields']['zzp2c_heading']);
			same(array('value' => 14, 'default_value' => 14, 'translated' => false, 'shared' => true), $page['fields']['zzp2c_price']);
			$refused = $mcp->call('update_page', array('page' => '/zzp2c_menu', 'fields' => array('zzp2c_price' => 12), 'lang' => 'ro'));
			same("'zzp2c_price' is shared by every language; update it without lang", isset($refused['error']) ? $refused['error'] : json_encode($refused));
			// translate everything translation_status lists, a page at a time
			$status = $mcp->call('translation_status', array('lang' => 'ro'));
			same($on['missing']['ro'], $status['total']);
			$kinds = array_unique(array_column($status['missing'], 'kind'));
			foreach (array('page', 'site', 'strings') as $kind) check(in_array($kind, $kinds, true), "$kind is listed: ".json_encode($kinds));
			$pages = array();
			$strings = array();
			$offset = 0;
			do {
				$batch = $mcp->call('translation_status', array('lang' => 'ro', 'limit' => 5, 'offset' => $offset));
				foreach ($batch['missing'] as $missing) {
					same(false, $missing['shared']);
					same(mb_strlen($missing['default_value']) <= 300, true);
					// an email's words are a dictionary's too (the starter's emails)
					if ($missing['kind'] === 'strings' || $missing['kind'] === 'email') $strings[$missing['where']][$missing['field']] = 'RO '.$missing['default_value'];
					else $pages[$missing['where']][$missing['field']] = 'RO '.$missing['default_value'];
				}
				$offset += 5;
			} while ($offset < $batch['total']);
			$pages['/zzp2c_menu']['zzp2c_heading'] = 'Meniul nostru de prânz';
			$strings['zzp2c']['order'] = 'Comandă acum';
			foreach ($pages as $where => $fields) {
				$saved = $mcp->call('update_page', array('page' => $where, 'fields' => $fields, 'lang' => 'ro'));
				check(!isset($saved['error']), "$where: ".json_encode($saved));
			}
			foreach ($strings as $file => $words) {
				same(count($words), $mcp->call('write_strings', array('file' => $file, 'lang' => 'ro', 'strings' => $words))['written']);
				// no PHP from the agent: the file is var_export's of its words
				$path = APPBASE."i18n/ro/$file.php";
				same("<?php\nreturn ".var_export(include $path, true).";\n", file_get_contents($path), $file);
			}
			// nothing left, and the English page is as it was
			$status = $mcp->call('translation_status', array('lang' => 'ro'));
			same(0, $status['total'], json_encode($status['missing']));
			check($status['done'] > 0, 'done counts the translations');
			same(array('ro' => 0), $mcp->call('describe', array('sections' => array('languages')))['languages']['missing']);
			same('Our lunch menu', $mcp->call('get_page', array('page' => '/zzp2c_menu'))['fields']['zzp2c_heading']);
			same(array('value' => 'Meniul nostru de prânz', 'default_value' => 'Our lunch menu', 'translated' => true, 'shared' => false), $mcp->call('get_page', array('page' => '/zzp2c_menu', 'lang' => 'ro'))['fields']['zzp2c_heading']);
			// the Romanian revision, apart from the English ones
			$history = $mcp->call('page_history', array('page' => '/zzp2c_menu', 'lang' => 'ro'));
			same(array('ro'), array_values(array_unique(array_column($history['revisions'], 'saved_lang'))));
			// /ro/ is Romanian, / still English
			$ro = $mcp->call('render_url', array('url' => '/ro/zzp2c_menu'));
			same(200, $ro['status']);
			check(strpos($ro['html'], '<h1>Meniul nostru de prânz</h1>') !== false, 'the Romanian heading');
			check(strpos($ro['html'], '<button>Comandă acum</button>') !== false, 'the Romanian word');
			check(strpos($ro['html'], 'lang="ro"') !== false, 'html lang');
			check(strpos($ro['html'], '>14<') !== false, 'the shared price');
			$en = $mcp->call('render_url', array('url' => '/zzp2c_menu'));
			check(strpos($en['html'], '<h1>Our lunch menu</h1>') !== false && strpos($en['html'], '<button>Order now</button>') !== false, 'English stays');
			// as the editor sees it: the language its saves go to
			same('ro', $mcp->call('render_url', array('url' => '/ro/zzp2c_menu', 'as' => 'editor'))['editing_lang']);
			same('en', $mcp->call('render_url', array('url' => '/zzp2c_menu', 'as' => 'editor'))['editing_lang']);
		});
	});
});

test('P2.C: check_view and write_view say which new fields are translated (needs P2.A)', function () {
	p2c_site(function ($db) {
		$other = APPBASE.'views/zzp2ctheme';
		test_fixture($other);
		@mkdir($other);
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$draft = '<!doctype html><html><head><title>Menu</title></head><body><h1><!-- print.cms.zzp2c_heading -->Our menu<!-- /print.cms.zzp2c_heading --></h1>'
				.'<h2><!-- print.cms.zzp2c_sub -->Lunch<!-- /print.cms.zzp2c_sub --></h2><p><!-- print.cms.zzp2c_seats -->40<!-- /print.cms.zzp2c_seats --></p>'
				.'<!-- print.@href.cms.zzp2c_map --><a href="https://maps.example/x">Map</a><!-- /print.@href.cms.zzp2c_map --></body></html>';
			$check = $mcp->call('check_view', array('content' => $draft, 'view' => 'zzp2c_menu.html'));
			same(array(
				array('field' => 'zzp2c_sub', 'translate' => true),
				array('field' => 'zzp2c_seats', 'translate' => false),
				array('field' => 'zzp2c_map', 'translate' => false),
			), $check['new_fields'], 'the heading is not new; a link is shared');
			$written = $mcp->call('write_view', array('content' => $draft, 'view' => 'zzp2c_menu.html'));
			same(true, $written['written']);
			same($check['new_fields'], $written['new_fields']);
			same(array(), $mcp->call('check_view', array('content' => $draft, 'view' => 'zzp2c_menu.html'))['new_fields'], 'written, nothing is new');
			// a draft without a name is compared with every page
			same(array(array('field' => 'zzp2c_other', 'translate' => true)), $mcp->call('check_view', array('content' => $draft.'<p><!-- print.cms.zzp2c_other -->x<!-- /print.cms.zzp2c_other --></p>'))['new_fields']);
			// a view of a theme no route serves is no page: nothing of it is stored
			same(array(), $mcp->call('check_view', array('content' => $draft, 'view' => 'zzp2c_menu.html', 'theme' => 'zzp2ctheme'))['new_fields']);
		});
		@rmdir($other);
	});
});

test('P2.C: describe, site_overview and raster describe --json have the languages block', function () use ($root) {
	p2c_site(function ($db) use ($root) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$overview = $mcp->call('site_overview');
			same(false, $overview['languages']['enabled']);
			check(array_key_exists('languages', $mcp->call('describe')), 'in describe by default');
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$languages = $mcp->call('describe', array('sections' => array('languages')))['languages'];
			same(array('enabled', 'multilingual_setting', 'default', 'list', 'urls', 'missing'), array_keys($languages));
			same(array(true, true, 'en', array('en', 'ro'), array('en' => '/', 'ro' => '/ro/')), array($languages['enabled'], $languages['multilingual_setting'], $languages['default'], $languages['list'], $languages['urls']));
			check(is_int($languages['missing']['ro']) && $languages['missing']['ro'] >= 1, 'the dictionary word at least: '.json_encode($languages));
			same($languages, $mcp->call('site_overview')['languages']);
			same($languages['missing']['ro'], $mcp->call('translation_status', array('lang' => 'ro'))['total']);
		});
		$out = array();
		exec('RASTER_DB='.escapeshellarg($db).' '.implode(' ', array_map('escapeshellarg', php_argv("$root/bin/raster", 'describe', '--json'))).' 2>/dev/null', $out);
		$described = json_decode(implode("\n", $out), true);
		same(true, $described['languages']['enabled']);
		same(array('en' => '/', 'ro' => '/ro/'), $described['languages']['urls']);
		check(isset($described['languages']['missing']['ro']), json_encode($described['languages']));
	});
});

test('P2.C: read_asset and write_asset: a theme\'s CSS, JS and SVG, with write_view\'s path checks (#159)', function () use ($root, $base) {
	$theme = "$root/application/views/default";
	$outside = sys_get_temp_dir().'/raster-p2c-asset-'.getmypid();
	@mkdir($outside);
	file_put_contents("$outside/secret.css", 'body{} /* sk-live-789 */');
	test_fixture("$theme/zzp2c_link.css");
	symlink("$outside/secret.css", "$theme/zzp2c_link.css");
	test_fixture("$theme/zzp2c_dir");
	symlink($outside, "$theme/zzp2c_dir");
	$css = "$theme/style.css";
	$kept = file_get_contents($css);
	$version = APPBASE.'data/cache/version';
	try {
		p2c_session(array(), function ($mcp) use ($theme, $kept, $version, $outside) {
			$read = $mcp->call('read_asset', array('path' => 'style.css'));
			same(array('path' => 'style.css', 'theme' => 'default', 'bytes' => strlen($kept), 'content' => $kept), $read);
			$cache = @file_get_contents($version);
			$written = $mcp->call('write_asset', array('path' => 'style.css', 'content' => $kept."\n.zz { color: red; }\n"));
			same(array('written' => true, 'path' => 'style.css', 'created' => false, 'bytes' => strlen($kept) + 21), $written);
			check(@file_get_contents($version) !== $cache, 'the page cache is cleared');
			same($kept."\n.zz { color: red; }\n", file_get_contents("$theme/style.css"));
			$made = $mcp->call('write_asset', array('path' => 'zzp2c.js', 'content' => 'console.log(1);'));
			same(true, $made['created']);
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 2 2"><a xlink:href="about.html"><circle cx="1" cy="1" r="1"/></a></svg>';
			same(true, $mcp->call('write_asset', array('path' => 'zzp2c.svg', 'content' => $svg))['written']);
			same($svg, $mcp->call('read_asset', array('path' => 'zzp2c.svg'))['content']);
			// an SVG that would run something, or isn't XML, is not written
			foreach (array(
				'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><SCRIPT>alert(1)</SCRIPT></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg" xmlns:s="http://www.w3.org/2000/svg"><s:script>alert(1)</s:script></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
				'<svg xmlns="http://www.w3.org/2000/svg"><rect ONCLICK="alert(1)"/></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><a href=" java&#x09;script:alert(1)"><rect/></a></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>',
				'<svg>&lt;',
				'not xml at all',
				'<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
				'<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>',
				'<?xml-stylesheet href="x.css"?><svg xmlns="http://www.w3.org/2000/svg"/>',
				'<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><p xmlns="http://www.w3.org/1999/xhtml">x</p></foreignObject></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><a><set attributeName="href" to="javascript:alert(1)"/></a></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a><animate attributeName="xlink:href" values="x"/></a></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><iframe srcdoc="x"/></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><a href="data:text/html,x"><rect/></a></svg>',
				'<svg xmlns="http://www.w3.org/2000/svg"><a title="see javascript:x"><rect/></a></svg>',
			) as $bad) {
				$refused = $mcp->call('write_asset', array('path' => 'zzp2c.svg', 'content' => $bad));
				check(isset($refused['error']), 'written: '.$bad);
			}
			same($svg, file_get_contents("$theme/zzp2c.svg"), 'the file is left as it was');
			$declared = '<?xml version="1.0" encoding="UTF-8"?>'."\n".$svg;
			same(true, $mcp->call('write_asset', array('path' => 'zzp2c.svg', 'content' => $declared))['written'], 'the XML declaration is fine');
			check(isset($mcp->call('write_asset', array('path' => 'zzp2c.js', 'content' => str_repeat('x', 2097153)))['error']), 'at most 2 MB');
			// only theme files that aren't views, inside the theme
			foreach (array("zzp2c.css\n", 'zzp2c.php.css', 'zzp2c.PHP.js', 'zzp2c.phtml.svg', '../../config/the_app.php', '../zz.css', '../../../zz.css', '/etc/passwd.css', "style.css\0.css", 'about.html', 'zzp2c.php', 'zzp2c.CSS', 'zzp2c_link.css', 'zzp2c_dir/secret.css', 'nope/zz.css', '', '.') as $path) {
				check(isset($mcp->call('read_asset', array('path' => $path))['error']), 'read: '.json_encode($path));
				check(isset($mcp->call('write_asset', array('path' => $path, 'content' => 'x'))['error']), 'written: '.json_encode($path));
			}
			check(strpos(file_get_contents("$outside/secret.css"), 'sk-live-789') !== false, 'nothing written through a link');
			foreach (array('..', '../..', 'nope', 'default/../default') as $other) {
				check(isset($mcp->call('read_asset', array('path' => 'style.css', 'theme' => $other))['error']), "theme $other");
			}
		});
		// over HTTP, reading is offered and writing waits for mcp_write_views
		list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list')), array('Authorization: Bearer test-token'));
		$names = array_column(json_decode($body, true)['result']['tools'], 'name');
		check(in_array('read_asset', $names, true) && !in_array('write_asset', $names, true), json_encode($names));
		// so do the tools that write the dictionaries and the config
		check(in_array('read_strings', $names, true) && in_array('translation_status', $names, true), 'reading is offered');
		check(!in_array('write_strings', $names, true) && !in_array('set_languages', $names, true), 'writing files waits for mcp_write_views');
		foreach (array(array('set_languages', array('languages' => array('en', 'ro'))), array('write_strings', array('file' => 'zzp2c', 'lang' => 'en', 'strings' => array('a' => 'b')))) as $call) {
			list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $call[0], 'arguments' => $call[1]))), array('Authorization: Bearer test-token'));
			same(-32602, json_decode($body, true)['error']['code'], $call[0]);
		}
		check(strpos(file_get_contents(APPBASE.'config/the_app.php'), "'multilingual'") === false, 'the config is untouched');
		list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'write_asset', 'arguments' => array('path' => 'style.css', 'content' => 'x')))), array('Authorization: Bearer test-token'));
		same(-32602, json_decode($body, true)['error']['code']);
		same(strlen($kept) + 21, strlen(file_get_contents($css)));
		check(strpos(json_encode(mcp_call('read_asset', array('path' => 'zzp2c_link.css'))), 'sk-live-789') === false, 'nothing read through a link over HTTP');
	} finally {
		file_put_contents($css, $kept);
		foreach (array('zzp2c.js', 'zzp2c.svg', 'zzp2c_link.css', 'zzp2c_dir') as $name) @unlink("$theme/$name");
		@unlink("$outside/secret.css");
		@rmdir($outside);
	}
});

test('P2.C: tools/list and describe grow by less than 1,000 characters (#161)', function () {
	// The baseline is tools/list over stdio at the P2 kickoff (c247d94):
	// 10954 characters, these 21 tools. P2.C added the six below. A later
	// release that adds tools measures its own growth: it moves the
	// baseline to the size before its change and adds its tools to the
	// list, so this test keeps saying what each step costs. describe's own
	// growth is its languages block; the rest of describe is other
	// packages' (the starter's views, lint).
	$kickoff = array('site_overview', 'get_page', 'update_page', 'page_history', 'list_items', 'get_item', 'create_item', 'update_item', 'run_action', 'delete_item',
		'lint_templates', 'schema_status', 'describe', 'vocabulary', 'annotations', 'list_views', 'read_view', 'check_view', 'write_view', 'render_url', 'clear_cache');
	$added = array('set_languages', 'translation_status', 'read_strings', 'write_strings', 'read_asset', 'write_asset');
	p2c_site(function ($db) use ($kickoff, $added) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) use ($kickoff, $added) {
			$list = $mcp->message('tools/list')['result'];
			same(array(), array_values(array_diff(array_column($list['tools'], 'name'), $kickoff, $added)), 'a tool this baseline does not know: move the baseline (see above)');
			$tools = strlen(json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$languages = strlen(json_encode(array('languages' => $mcp->call('describe', array('sections' => array('languages')))['languages']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) - 2;
			// P3.D's lang on the item tools (46 characters) is P3's growth,
			// and P4.C's "record, email" (15) P4's, each held to its own
			// budget by its test
			$growth = $tools - 10954 - 46 - 15 + $languages;
			check($growth < 1000, "tools/list is $tools characters and the languages block $languages: $growth more than at the kickoff");
			$instructions = $mcp->message('initialize')['result']['instructions'];
			check(strpos($instructions, 'Making a site multilingual') !== false && strpos($instructions, 'write_asset') !== false, 'the recipe and the asset tools');
			preg_match('/Making a site multilingual.*$/s', $instructions, $m);
			check(str_word_count($m[0]) <= 120, 'the recipe is at most 120 words: '.str_word_count($m[0]));
		});
	});
});

// ## P2.B: page reads and writes in a language, the editor

// The checkout copied to a folder of its own for the P2.B tests, made
// once, so their fixture views and settings never touch the checkout.
function p2b_copy() {
	global $root;
	static $dir = null;
	if ($dir !== null) return $dir;
	$dir = sys_get_temp_dir().'/raster-p2b-'.getmypid();
	exec('rm -rf '.escapeshellarg($dir));
	mkdir($dir, 0775, true);
	foreach (array('index.php', 'system', 'application', 'bin', 'tests') as $part) {
		exec('cp -R '.escapeshellarg("$root/$part").' '.escapeshellarg("$dir/$part"));
	}
	register_shutdown_function(function () use ($dir) { exec('rm -rf '.escapeshellarg($dir)); });
	return $dir;
}
// the copy multilingual (en, ro), with the fixture page /zzp2babout
function p2b_site($fn, $settings = "config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);") {
	$dir = p2b_copy();
	$theme = "$dir/application/views/default";
	file_put_contents("$theme/zzp2babout.html", '<!doctype html><html><head><title><!-- print.cms.zzp2b_title -->About us<!-- /print.cms.zzp2b_title --></title></head><body>'
		.'<h1><!-- print.cms.zzp2b_headline -->Our story<!-- /print.cms.zzp2b_headline --></h1>'
		.'<p class="intro"><!-- print.cms.zzp2b_intro -->Coffee since 2001.<!-- /print.cms.zzp2b_intro --></p>'
		.'<!-- print.@src.cms.zzp2b_photo --><img src="photo.jpg" alt="Cup"><!-- /print.@src.cms.zzp2b_photo -->'
		.'<!-- print.@href.cms.zzp2b_link --><a href="https://cafe.example"><!-- print.cms.zzp2b_link -->https://cafe.example<!-- /print.cms.zzp2b_link --></a><!-- /print.@href.cms.zzp2b_link -->'
		.'<footer><!-- print.cms.site_zzp2b_tagline -->Slow coffee<!-- /print.cms.site_zzp2b_tagline --></footer></body></html>');
	$events = "$dir/application/config/the_events.php";
	$kept = file_get_contents($events);
	file_put_contents($events, (strpos($kept, '<?php') === 0 ? $kept : "<?php\n")."\n".$settings."\n");
	try {
		return $fn($dir);
	} finally {
		file_put_contents($events, $kept);
	}
}
// a server for the copy
function p2b_server($dir, $fn, $env = array()) {
	global $db;
	$port = free_port();
	$env = $env + array('RASTER_DB' => $db, 'RASTER_SECRET' => 'test-secret', 'RASTER_ENV' => 'development', 'PATH' => getenv('PATH'));
	foreach ($env as $name => $value) $env[$name] = str_replace('{port}', $port, $value);
	$server = proc_open(php_argv('-S', "127.0.0.1:$port", "$dir/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $dir, $env);
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
	try {
		return $fn("http://127.0.0.1:$port");
	} finally {
		proc_terminate($server); proc_close($server);
	}
}
// PHP run in the copy, booted as the command line is, multilingual; what
// it echoes, decoded from JSON
function p2b_php($dir, $code) {
	global $db;
	reconnect();
	$script = 'require "'.$dir.'/system/boot.php"; boot::$appname = "application"; boot::cli(); cms_store::connect();'
		.' config::set("languages")->to(array("en", "ro")); config::set("multilingual")->to(true); '.$code;
	$process = proc_open(php_argv('-r', $script), array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $dir,
		array('RASTER_DB' => $db, 'RASTER_ENV' => 'development', 'PATH' => getenv('PATH')));
	$out = stream_get_contents($pipes[1]);
	proc_close($process);
	$json = json_decode($out, true);
	check(is_array($json), 'the child said: '.$out);
	return $json;
}
// an editor's session cookie on $base; $accept is the browser's Accept-Language
function p2b_login($base, $email = 'zzp2b-editor@example.test') {
	cms_store::connect();
	authentication::create_user($email, 'a long password', 'editor', 'Ed');
	list($status, , $headers) = form_post("$base/login", array('raster_form' => 'authentication.login', 'login' => $email, 'password' => 'a long password'));
	same(303, $status, 'login');
	return preg_replace('/^Set-Cookie:\s*([^;]+).*$/i', '$1', current(preg_grep('/^Set-Cookie:\s*PHPSESSID/i', $headers)));
}
// the editor's config on a page, decoded, and the raw JSON
function p2b_config($url, $cookie, $headers = array()) {
	$page = http('GET', $url, null, array_merge(array("Cookie: $cookie"), $headers))[1];
	check(preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', (string)$page, $m) === 1, 'no editor: '.$page);
	return array(json_decode($m[1], true), $m[1], $page);
}
// the mark of a page field in a config
function p2b_mark($config, $field) {
	foreach ($config['marks'] as $mark) if ($mark['kind'] === 'field' && $mark['field'] === $field) return $mark;
	return null;
}
// the tables and columns the P2.B tests made, taken away again
function p2b_clean($site_last) {
	cms_store::connect();
	R::exec('DROP TABLE IF EXISTS zzp2baboutpage');
	R::exec('DROP TABLE IF EXISTS zzp2bunitpage');
	R::exec('DELETE FROM sitepage WHERE id > ?', array($site_last));
	$columns = cms_store::columns('sitepage');
	foreach (array_keys($columns) as $column) {
		if (strpos($column, 'zzp2b') !== false) R::exec("ALTER TABLE sitepage DROP COLUMN `$column`");
	}
	// a page shown in development in a language ensures the translation
	// columns of what it prints, the layout's site_* fields too
	cms_store::forget();
	foreach (R::inspect() as $table) {
		if (!preg_match('/page$/', $table)) continue;
		foreach (array_keys(cms_store::columns($table)) as $column) {
			if (preg_match('/__[a-z]{2,3}(_[a-z0-9]{2,8})?$/', $column)) R::exec("ALTER TABLE `$table` DROP COLUMN `$column`");
		}
		cms_store::forget();
	}
	cms_store::forget();
}

test('P2.B: localized() shows each translated field in the language, the default language\'s value while it is empty', function () {
	$out = p2b_php(p2b_copy(), 'R::exec("DROP TABLE IF EXISTS zzp2bunitpage");
		cms_types::ensure("zzp2bunitpage", array("slug" => "text", "updated_at" => "datetime", "headline" => "text", "intro" => "text", "photo" => "text"), array("headline", "intro"));
		$row = array("headline" => "Our story", "headline__ro" => "Povestea noastră", "intro" => "Since 2001", "intro__ro" => "", "photo" => "a.jpg");
		$null = array("intro__ro" => null) + $row;
		echo json_encode(array(
			"translated" => cms_types::translated("zzp2bunitpage"),
			"ro" => cms_store::localized($row, "zzp2bunitpage", "ro"),
			"null" => cms_store::localized($null, "zzp2bunitpage", "ro")["intro"],
			"en" => cms_store::localized($row, "zzp2bunitpage", "en") === $row,
			"current" => i18n::with("ro", function () use ($row) { return cms_store::localized($row, "zzp2bunitpage"); })["headline"],
			"default" => cms_store::localized($row, "zzp2bunitpage") === $row,
			"off" => with_off(function () use ($row) { return cms_store::localized($row, "zzp2bunitpage", "ro") === $row; }),
		));
		R::exec("DROP TABLE zzp2bunitpage");
		function with_off($fn) { config::set("multilingual")->to(false); try { return $fn(); } finally { config::set("multilingual")->to(true); } }
	');
	sort($out['translated']);
	same(array('headline', 'intro'), $out['translated']);
	// the translation, or the default's value; the translations stay in the row
	same(array('headline' => 'Povestea noastră', 'headline__ro' => 'Povestea noastră', 'intro' => 'Since 2001', 'intro__ro' => '', 'photo' => 'a.jpg'), $out['ro']);
	same('Since 2001', $out['null']);
	same('Povestea noastră', $out['current'], 'null is the current language');
	same(true, $out['en'], 'the default language is the row as it is');
	same(true, $out['default'], 'the default when no language is current');
	same(true, $out['off'], 'off is the row as it is');
});

test('P2.B: update_page() in a language writes its column, refuses a shared field, keeps saved_lang and sends lang', function () {
	$out = p2b_php(p2b_copy(), 'R::exec("DROP TABLE IF EXISTS zzp2bunitpage");
		function p2b_saved($payload) { $GLOBALS["p2b_events"][] = $payload; }
		event::bind("cms.page_saved")->to(null, "p2b_saved");
		cms_types::ensure("zzp2bunitpage", array("slug" => "text", "updated_at" => "datetime", "headline" => "text", "intro" => "text", "photo" => "text"), array("headline", "intro"));
		$all = array("headline", "intro", "photo");
		cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline" => "Our story", "photo" => "a.jpg"), $all);
		$ro = cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline" => "Povestea noastră"), $all, "ro");
		$revisions = (int)R::count("zzp2bunitpage");
		$refused = null;
		try { cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline" => "x", "photo" => "b.jpg"), $all, "ro"); } catch (cms_refused $e) { $refused = $e->problems; }
		$unknown = null;
		try { cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline" => "x"), $all, "de"); } catch (InvalidArgumentException $e) { $unknown = $e->getMessage(); }
		$named = null;
		try { cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline__ro" => "x"), $all, "ro"); } catch (InvalidArgumentException $e) { $named = $e->getMessage(); }
		echo json_encode(array(
			"fields" => array_intersect_key($ro["fields"], array_flip(array("headline", "headline__ro", "photo"))),
			"revisions" => array($revisions, (int)R::count("zzp2bunitpage")),
			"refused" => $refused, "unknown" => $unknown, "named" => $named,
			"saved_lang" => R::getCol("SELECT saved_lang FROM zzp2bunitpage ORDER BY id"),
			"history" => array_map(function ($r) { return array($r["saved_lang"], array_key_exists("saved_lang", $r["fields"])); }, cms_store::page_history("zzp2bunitpage")),
			"events" => array_map(function ($e) { return array($e["lang"], $e["changed"]); }, $GLOBALS["p2b_events"]),
		));
		R::exec("DROP TABLE zzp2bunitpage");
	');
	// the default language's value stays, the translation is beside it
	same(array('headline' => 'Our story', 'photo' => 'a.jpg', 'headline__ro' => 'Povestea noastră'), $out['fields']);
	// nothing stored when one of the fields is shared by every language
	same(array('field_shared'), $out['refused']);
	same(array(2, 2), $out['revisions'], 'refused writes store no revision');
	check(strpos((string)$out['unknown'], "Unknown language 'de'") === 0, (string)$out['unknown']);
	check(strpos((string)$out['named'], "Unknown field 'headline__ro'") === 0, 'a column is not a field: '.$out['named']);
	same(array('en', 'ro'), $out['saved_lang']);
	same(array(array('ro', false), array('en', false)), $out['history'], 'the history says the language, not as a field');
	same(array(array('en', array('headline', 'photo')), array('ro', array('headline'))), $out['events']);
});

test('P2.B: two saves at once in two languages keep each other\'s change', function () {
	global $db;
	$dir = p2b_copy();
	p2b_php($dir, 'R::exec("DROP TABLE IF EXISTS zzp2bunitpage");
		cms_types::ensure("zzp2bunitpage", array("slug" => "text", "updated_at" => "datetime", "headline" => "text", "intro" => "text"), array("headline", "intro"));
		cms_store::update_page("zzp2bunitpage", "/zzp2bunit", array("headline" => "Our story", "intro" => "Since 2001"), array("headline", "intro"));
		echo "[]";');
	try {
		reconnect();
		$start = sprintf('%.6F', microtime(true) + 0.8);
		$saves = array('array("headline" => "Our whole story"), array("headline", "intro")', 'array("intro" => "Din 2001"), array("headline", "intro"), "ro"');
		$processes = array();
		foreach ($saves as $n => $save) {
			$script = 'require "'.$dir.'/system/boot.php"; boot::$appname = "application"; boot::cli(); cms_store::connect();'
				.' config::set("languages")->to(array("en", "ro")); config::set("multilingual")->to(true);'
				.' while (microtime(true) < '.$start.') usleep(100); cms_store::update_page("zzp2bunitpage", "/zzp2bunit", '.$save.'); echo "ok";';
			$process = proc_open(php_argv('-r', $script), array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $dir, array('RASTER_DB' => $db, 'RASTER_ENV' => 'development', 'PATH' => getenv('PATH')));
			$processes[$n] = array($process, $pipes[1]);
		}
		foreach ($processes as $n => $process) {
			same('ok', stream_get_contents($process[1]), "save $n");
			proc_close($process[0]);
		}
		$latest = R::getRow('SELECT headline, intro, intro__ro FROM zzp2bunitpage ORDER BY id DESC LIMIT 1');
		same(array('headline' => 'Our whole story', 'intro' => 'Since 2001', 'intro__ro' => 'Din 2001'), $latest);
	} finally {
		R::exec('DROP TABLE IF EXISTS zzp2bunitpage');
		cms_store::forget();
	}
});

test('P2.B: production before schema --apply: a save in a language is refused, never written to the default\'s column', function () {
	$dir = p2b_copy();
	cms_store::connect();
	R::exec('DROP TABLE IF EXISTS zzp2bfrozenpage');
	cms_types::ensure('zzp2bfrozenpage', array('slug' => 'text', 'updated_at' => 'datetime', 'zzp2b_note' => 'text', 'zzp2b_seats' => 'int'));
	R::exec("INSERT INTO zzp2bfrozenpage (slug, updated_at, zzp2b_note, zzp2b_seats) VALUES ('/zzp2bfrozen', '2026-10-01 10:00:00', 'Open daily', 12)");
	file_put_contents("$dir/application/views/default/zzp2bfrozen.html", '<!doctype html><html><head><title>Frozen</title></head><body>'
		.'<p><!-- print.cms.zzp2b_note -->Open daily<!-- /print.cms.zzp2b_note --></p><p><!-- print.cms.zzp2b_seats -->12<!-- /print.cms.zzp2b_seats --></p></body></html>');
	try {
		p2b_site(function ($dir) {
			p2b_server($dir, function ($base) {
				$editor = p2b_login($base, 'zzp2b-frozen@example.test');
				list($config) = p2b_config("$base/ro/zzp2bfrozen", $editor);
				$post = function ($fields) use ($base, $editor, $config) {
					list($status, $body) = form_post("$base/ro/api/cms/editor_save_field", $fields + array('type' => 'zzp2bfrozenpage', 'slug' => '/zzp2bfrozen', 'lang' => 'ro', 'csrf' => $config['csrf']), array("Cookie: $editor"));
					return array($status, json_decode($body, true));
				};
				list($status, $json) = $post(array('field' => 'zzp2b_note', 'value' => 'Deschis zilnic'));
				same(array(422, array('translation_columns_missing')), array($status, $json['problems']));
				same('Open daily', R::getCell('SELECT zzp2b_note FROM zzp2bfrozenpage ORDER BY id DESC LIMIT 1'), 'the English is untouched');
				// a number is one value in every language
				same(200, $post(array('field' => 'zzp2b_seats', 'value' => '14'))[0]);
			}, array('RASTER_ENV' => 'production', 'RASTER_URL' => 'http://127.0.0.1:{port}/'));
		});
		$js = file_get_contents(BASE.'models/cms/editor/editor.js');
		check(strpos($js, 'problem_translation_columns_missing:') !== false, 'worded in English');
		check(isset((include BASE.'models/cms/editor/lang/ro.php')['problem_translation_columns_missing']), 'and in Romanian');
	} finally {
		@unlink("$dir/application/views/default/zzp2bfrozen.html");
		R::exec('DROP TABLE IF EXISTS zzp2bfrozenpage');
		cms_store::forget();
	}
});

test('P2.B: an editor on /ro/ saves Romanian, /about keeps English, and an empty Romanian field shows English', function () {
	$site_last = (int)R::getCell('SELECT MAX(id) FROM sitepage');
	try {
		p2b_site(function ($dir) {
			p2b_server($dir, function ($base) {
				// the first prints make the fields and their Romanian columns (development)
				same(200, http('GET', "$base/zzp2babout")[0]);
				$columns = cms_store::columns('zzp2baboutpage');
				foreach (array('zzp2b_headline__ro', 'zzp2b_intro__ro', 'zzp2b_title__ro') as $column) check(isset($columns[$column]), "$column: ".implode(', ', array_keys($columns)));
				check(!isset($columns['zzp2b_photo__ro']), 'a photo (@src) is shared');
				// printed as a link's text and in its href: one value, as the content model says
				check(isset($columns['zzp2b_link']) && !isset($columns['zzp2b_link__ro']), 'a link printed in its href too is shared');
				check(isset(cms_store::columns('sitepage')['site_zzp2b_tagline__ro']), 'site fields too');
				$editor = p2b_login($base);
				list($config) = p2b_config("$base/ro/zzp2babout", $editor);
				same('ro', $config['lang'], 'the content language');
				same($base.'/ro/api/cms/', $config['api'], 'saves go to the page\'s language');
				same(array('en', 'ro'), $config['languages']);
				$mark = p2b_mark($config, 'zzp2b_headline');
				// the value is what Romanian holds (nothing): an undo puts that back,
				// never the English the page shows meanwhile
				same(array('ro', false, true, 'Our story', ''), array($mark['lang'], $mark['shared'], $mark['untranslated'], $mark['fallback'], $mark['value']), json_encode($mark));
				check(strpos(http('GET', "$base/ro/zzp2babout", null, array("Cookie: $editor"))[1], 'Our story<!--raster:e') !== false, 'the editor sees the English');
				same(array('ro', true), array(p2b_mark($config, 'zzp2b_photo')['lang'], p2b_mark($config, 'zzp2b_photo')['shared']));
				check(!isset(p2b_mark($config, 'zzp2b_photo')['untranslated']), 'a shared field is never untranslated');
				$post = function ($endpoint, $fields, $lang = 'ro') use ($base, $editor, $config) {
					$url = $lang === 'ro' ? "$base/ro/api/cms/$endpoint" : "$base/api/cms/$endpoint";
					list($status, $body) = form_post($url, $fields + array('csrf' => $config['csrf']), array("Cookie: $editor"));
					return array($status, json_decode($body, true), $body);
				};
				$field = array('type' => $config['page']['type'], 'slug' => $config['page']['slug']);
				list($status, $json, $body) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Povestea noastră', 'lang' => 'ro'));
				same(200, $status, $body);
				same('Povestea noastră', $json['value']);
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], '<h1>Povestea noastră</h1>') !== false, 'Romanian on /ro/');
				check(strpos(http('GET', "$base/zzp2babout")[1], '<h1>Our story</h1>') !== false, 'English stays on /');
				// an empty translation shows the default language's words
				$post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => '', 'lang' => 'ro'));
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], '<h1>Our story</h1>') !== false, 'English while the Romanian is empty');
				$post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Povestea noastră', 'lang' => 'ro'));
				// site fields alike
				list($status, , $body) = $post('editor_save_field', array('type' => 'sitepage', 'slug' => 'site', 'field' => 'site_zzp2b_tagline', 'value' => 'Cafea fără grabă', 'lang' => 'ro'));
				same(200, $status, $body);
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], '<footer>Cafea fără grabă</footer>') !== false, 'the site field in Romanian');
				check(strpos(http('GET', "$base/zzp2babout")[1], '<footer>Slow coffee</footer>') !== false, 'and in English');
				// a shared field is one value for every language
				list($status, , $body) = $post('editor_save_field', $field + array('field' => 'zzp2b_photo', 'value' => '/media/b.jpg', 'lang' => 'ro'));
				same(200, $status, $body);
				check(strpos(http('GET', "$base/zzp2babout")[1], 'src="/media/b.jpg"') !== false, 'the photo changed everywhere');
				// the page's language is the URL's: a page open in another one is refused
				list($status, $json) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Wrong', 'lang' => 'en'));
				same(400, $status);
				list($status) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Wrong'));
				same(400, $status, 'no lang');
				list($status) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Wrong', 'lang' => 'ro'), 'en');
				same(400, $status, '/api/ is the default language');
				// a language's column is never a field of its own
				list($status, $json) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline__ro', 'value' => 'Wrong', 'lang' => 'ro'));
				same(array(400, "Unknown field 'zzp2b_headline__ro'"), array($status, $json['error']));
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], 'Wrong') === false, 'nothing refused was stored');
				// the English page edits English
				list($en) = p2b_config("$base/zzp2babout", $editor);
				same(array('en', null), array(p2b_mark($en, 'zzp2b_headline')['lang'], isset(p2b_mark($en, 'zzp2b_headline')['shared']) ? true : null));
				list($status, , $body) = $post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Our whole story', 'lang' => 'en'), 'en');
				same(200, $status, $body);
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], '<h1>Povestea noastră</h1>') !== false, 'the Romanian stays');
				// the history says which language each revision changed
				list($status, $json, $body) = $post('editor_history', $field + array('lang' => 'ro'));
				same(200, $status, $body);
				$langs = array_map(function ($r) { return $r['saved_lang']; }, $json['revisions']);
				// the newest first: the English save, the photo (shared, so the
				// default language's), the three Romanian ones
				same(array('en', 'en', 'ro', 'ro', 'ro'), array_slice($langs, 0, 5), json_encode($langs));
				// restoring brings back the whole row, every language
				$old = $json['revisions'][1];
				same('Povestea noastră', $old['fields']['zzp2b_headline__ro']);
				$post('editor_save_field', $field + array('field' => 'zzp2b_headline', 'value' => 'Altă poveste', 'lang' => 'ro'));
				list($status, , $body) = $post('editor_restore', $field + array('revision' => $old['revision']), 'ro');
				same(200, $status, $body);
				check(strpos(http('GET', "$base/ro/zzp2babout")[1], '<h1>Povestea noastră</h1>') !== false, 'the Romanian is back');
				check(strpos(http('GET', "$base/zzp2babout")[1], '<h1>Our story</h1>') !== false, 'and the English of then');
			});
		});
	} finally {
		p2b_clean($site_last);
	}
});

test('P2.B: the editor\'s own words: an English editor on /ro/ sees English, the browser\'s otherwise, and the dock\'s choice wins', function () {
	$site_last = (int)R::getCell('SELECT MAX(id) FROM sitepage');
	try {
		p2b_site(function ($dir) {
			p2b_server($dir, function ($base) {
				$editor = p2b_login($base, 'zzp2b-words@example.test');
				// English browser on the Romanian page: English words, Romanian content
				list($config) = p2b_config("$base/ro/zzp2babout", $editor, array('Accept-Language: en-GB,en;q=0.9'));
				same(array('ro', 'en'), array($config['lang'], $config['editor_lang']));
				same(array(), (array)$config['strings'], 'English is editor.js\'s own');
				check(in_array('ro', $config['editor_languages'], true) && $config['editor_languages'][0] === 'en', json_encode($config['editor_languages']));
				// a Romanian browser on the English page: Romanian words
				list($config) = p2b_config("$base/zzp2babout", $editor, array('Accept-Language: ro-RO,ro;q=0.9'));
				same(array('en', 'ro'), array($config['lang'], $config['editor_lang']));
				same('Editează', $config['strings']['edit']);
				same('editezi: {lang}', $config['strings']['editing_lang']);
				// a language the editor has no words in falls back to the default's
				list($config) = p2b_config("$base/ro/zzp2babout", $editor, array('Accept-Language: de'));
				same('en', $config['editor_lang']);
				// the dock's picker: the account's choice wins over the browser
				$post = function ($fields, $headers = array()) use ($base, $editor, $config) {
					list($status, $body) = form_post("$base/ro/api/cms/editor_set_lang", $fields, array_merge(array("Cookie: $editor"), $headers));
					return array($status, json_decode($body, true));
				};
				same(403, $post(array('lang' => 'ro'))[0], 'the session token');
				same(array(400, "The editor has no words in 'xx'"), array($post(array('lang' => 'xx', 'csrf' => $config['csrf']))[0], $post(array('lang' => 'xx', 'csrf' => $config['csrf']))[1]['error']));
				same(array(200, array('editor_lang' => 'ro')), $post(array('lang' => 'RO', 'csrf' => $config['csrf'])));
				same('ro', R::getCell('SELECT editor_lang FROM user WHERE email = ?', array('zzp2b-words@example.test')));
				list($config) = p2b_config("$base/ro/zzp2babout", $editor, array('Accept-Language: en'));
				same(array('ro', 'Editează'), array($config['editor_lang'], $config['strings']['edit']));
				// '' follows the browser again
				same(200, $post(array('lang' => '', 'csrf' => $config['csrf']))[0]);
				same('en', p2b_config("$base/ro/zzp2babout", $editor, array('Accept-Language: en'))[0]['editor_lang']);
			});
		});
	} finally {
		p2b_clean($site_last);
	}
});

test('P2.B: off, the editor\'s config JSON is byte for byte what it was, and saves are what they were', function () use ($base) {
	$theme = APPBASE.'views/default';
	test_fixture("$theme/zzp2boff.html");
	file_put_contents("$theme/zzp2boff.html", '<!doctype html><html><head><title>Off</title></head><body><h1><!-- print.cms.zzp2b_off -->Plain<!-- /print.cms.zzp2b_off --></h1></body></html>');
	$GLOBALS['p2b_events'] = array();
	try {
		$editor = p2b_login($base, 'zzp2b-off@example.test');
		// the 2.2.45 config: these keys, in this order, and nothing else
		$keys = array('api', 'csrf', 'page', 'user', 'marks', 'admin', 'strings', 'lang', 'now');
		$mark_keys = array('kind', 'type', 'slug', 'field', 'value', 'default', 'rich', 'attr');
		$same = function ($raw) use ($keys, $mark_keys) {
			$config = json_decode($raw);
			$rebuilt = new stdClass();
			foreach ($keys as $key) {
				check(property_exists($config, $key), "no $key");
				$rebuilt->$key = $config->$key;
			}
			foreach ((array)$config->marks as $mark) {
				if ($mark->kind === 'field') same($mark_keys, array_keys((array)$mark));
			}
			same(json_encode($rebuilt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $raw);
			return json_decode($raw, true);
		};
		// no languages
		$config = $same(p2b_config("$base/zzp2boff", $editor, array('Accept-Language: ro'))[1]);
		same(array('', array()), array($config['lang'], $config['strings']));
		// template-only languages: ?lang= picks the editor's words, as before
		p0c_settings("config::set('languages')->to(array('en', 'ro'));", function () use ($base, $editor, $same) {
			$config = $same(p2b_config("$base/zzp2boff?lang=ro", $editor)[1]);
			same('ro', $config['lang']);
			same((include BASE.'models/cms/editor/lang/ro.php'), $config['strings']);
			// a save is what it was: no saved_lang, no lang in the event, a stray lang ignored
			event::bind('cms.page_saved')->to(null, 'p2b_off_saved');
			list($status, $body) = form_post("$base/api/cms/editor_save_field", array('type' => 'zzp2boffpage', 'slug' => '/zzp2boff', 'field' => 'zzp2b_off', 'value' => 'Saved', 'lang' => 'xx', 'csrf' => $config['csrf']), array("Cookie: $editor"));
			same(200, $status, $body);
			same(array('value' => 'Saved', 'revision' => (int)R::getCell('SELECT MAX(id) FROM zzp2boffpage')), json_decode($body, true));
			check(!array_key_exists('saved_lang', cms_store::columns('zzp2boffpage')), 'no saved_lang column');
			cms_store::update_page('zzp2boffpage', '/zzp2boff', array('zzp2b_off' => 'Again'), array('zzp2b_off'), 'ro');
			same('Again', R::getCell('SELECT zzp2b_off FROM zzp2boffpage ORDER BY id DESC LIMIT 1'), 'a language is ignored off');
			same(array('type', 'slug', 'changed', 'fields'), array_keys($GLOBALS['p2b_events'][0]));
			same(array('revision', 'updated_at', 'fields'), array_keys(cms_store::page_history('zzp2boffpage')[0]));
			// editor_set_lang is a multilingual site's: off it changes nothing
			$had = array_key_exists('editor_lang', cms_store::columns('user'));
			list($status, $body) = form_post("$base/api/cms/editor_set_lang", array('lang' => 'ro', 'csrf' => $config['csrf']), array("Cookie: $editor"));
			same(array(422, array('not_multilingual')), array($status, json_decode($body, true)['problems']), $body);
			if (!$had) check(!array_key_exists('editor_lang', cms_store::columns('user')), 'no editor_lang column');
			else same('', (string)R::getCell('SELECT editor_lang FROM user WHERE email = ?', array('zzp2b-off@example.test')), 'nothing stored');
			// the account's editor_lang is a multilingual site's column only:
			// off, schema --check asks nothing new of an existing database
			check(!array_key_exists('editor_lang', authentication::schema()['user']), 'off: no editor_lang');
			with_settings(array('languages' => array('en', 'ro'), 'multilingual' => true), function () {
				check(array_key_exists('editor_lang', authentication::schema()['user']), 'on: editor_lang');
			});
		});
	} finally {
		@unlink("$theme/zzp2boff.html");
		R::exec('DROP TABLE IF EXISTS zzp2boffpage');
		cms_store::forget();
	}
});
function p2b_off_saved($payload) { $GLOBALS['p2b_events'][] = $payload; }

// ## P2.A: translation columns, schema, lint

// settings that make a site multilingual, for with_settings()
function p2a_on($languages = array('en', 'ro')) {
	return array('multilingual' => true, 'languages' => $languages);
}
// the columns of a table, as the database has them now
function p2a_columns($table) {
	cms_store::forget();
	return array_keys(cms_store::columns($table));
}
// table => the translation columns (x__ro) each CMS table has now, which
// off are all kept translations; empty on a site that never had languages
function p2a_translation_columns() {
	cms_store::connect();
	cms_store::forget();
	$found = array();
	foreach (R::inspect() as $table) {
		if (!preg_match('/(page|data)$/', $table)) continue;
		$columns = array_values(array_filter(array_keys(cms_store::columns($table)), function ($c) { return raster_schema::translation_of($c) !== null; }));
		if ($columns) $found[$table] = $columns;
	}
	return $found;
}
// the SQL $fn sends
function p2a_sql($fn) {
	R::startLogging();
	try {
		$fn();
		return R::getLogs();
	} finally {
		R::stopLogging();
	}
}
// takes out what schema --apply added ("added t.c" lines), and the tables
function p2a_undo($changes, $tables = array()) {
	cms_store::connect();
	foreach ($changes as $change) {
		if (!preg_match('/^added ([a-z0-9_]+)\.([a-z0-9_]+)$/', $change, $m) || in_array($m[1], $tables, true)) continue;
		cms_store::forget();
		// a slug column per language has an index, which SQLite drops first (P3.B)
		if (strpos($m[2], 'slug__') === 0 && R::getDatabaseAdapter()->getDatabase()->getDatabaseType() !== 'mysql') R::getDatabaseAdapter()->exec("DROP INDEX IF EXISTS `{$m[1]}_{$m[2]}`");
		if (array_key_exists($m[2], cms_store::columns($m[1]))) R::getDatabaseAdapter()->exec("ALTER TABLE `{$m[1]}` DROP COLUMN `{$m[2]}`");
	}
	foreach ($tables as $table) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS `$table`");
	cms_store::forget();
}

test('P2.A: translates() is the D7 rule: text as content or in an attribute that holds words', function () {
	foreach (array(null, 'alt', 'title', 'placeholder', 'aria-label', 'content', '@alt', 'ALT') as $attribute) {
		same(true, cms_types::translates('text', $attribute), 'text in '.var_export($attribute, true));
	}
	foreach (array('src', 'href', 'data-id', 'class', 'value', 'datetime', 'srcset', '+class') as $attribute) {
		same(false, cms_types::translates('text', $attribute), "text in $attribute");
	}
	foreach (array('int', 'number', 'bool', 'date', 'datetime', 'time') as $type) {
		same(false, cms_types::translates($type), $type);
		same(false, cms_types::translates($type, 'alt'), "$type in alt");
	}
});

test('P2.A: ensure() adds a column per translation language only when multilingual is on', function () {
	cms_store::connect();
	$types = array('slug' => 'text', 'headline' => 'text', 'price' => 'int');
	$fresh = function ($translated) use ($types) {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2aensurepage');
		cms_store::forget();
		return p2a_sql(function () use ($types, $translated) { cms_types::ensure('zzp2aensurepage', $types, $translated); });
	};
	try {
		// off, languages without the switch, and one language: the SQL is
		// the same with or without $translated
		$plain = $fresh(array());
		same($plain, $fresh(array('headline')), 'off');
		foreach (array(array('multilingual' => false, 'languages' => array('en', 'ro')), p2a_on(array('en'))) as $settings) {
			same($plain, with_settings($settings, function () use ($fresh) { return $fresh(array('headline')); }), json_encode($settings));
			same(array(), with_settings($settings, function () { return cms_types::translated('zzp2aensurepage'); }));
		}
		same(array('id', 'slug', 'headline', 'price'), p2a_columns('zzp2aensurepage'));
		// on: <field><suffix> for every translation language, of the field's type
		with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () use ($types) {
			cms_types::ensure('zzp2aensurepage', $types, array('headline', 'price'));
			same(array('id', 'slug', 'headline', 'price', 'headline__ro', 'headline__pt_br', 'price__ro', 'price__pt_br'), p2a_columns('zzp2aensurepage'));
			same('text', cms_types::of_table('zzp2aensurepage')['headline__pt_br']);
			same('int', cms_types::of_table('zzp2aensurepage')['price__ro'], 'the type of its field');
			// again: nothing new, nothing dropped
			$again = p2a_sql(function () use ($types) { cms_types::ensure('zzp2aensurepage', $types, array('headline', 'price')); });
			check(!preg_grep('/ALTER/i', $again), 'no ALTER the second time: '.json_encode($again));
			same(array('headline', 'price'), cms_types::translated('zzp2aensurepage'));
			// a field already in the table, named only in $translated
			cms_types::ensure('zzp2aensurepage', array(), array('slug'));
			check(in_array('slug__ro', p2a_columns('zzp2aensurepage')), 'slug__ro');
			$refused = null;
			try { cms_types::ensure('zzp2aensurepage', array(), array('head__line')); } catch (InvalidArgumentException $e) { $refused = $e->getMessage(); }
			check($refused !== null, 'a name with __ is refused');
		});
		// off again: translated() is empty and the columns stay
		same(array(), cms_types::translated('zzp2aensurepage'));
		check(in_array('headline__ro', p2a_columns('zzp2aensurepage')), 'kept');
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2aensurepage');
		cms_store::forget();
	}
});

// D3: RedBean reads x_id as a link to an x record. Raster declares every
// translation column before a value is stored in it, and then name__id
// (Indonesian) is a plain text column, fluid and frozen. On MySQL a column
// RedBean made itself would be an int; nothing in Raster lets it.
test('P2.A: name__id is a plain text column, fluid and frozen (SQLite and MySQL)', function () {
	cms_store::connect();
	$frozen = R::getRedBean()->isFrozen();
	try {
		with_settings(p2a_on(array('en', 'id')), function () {
			R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2aidpage');
			cms_store::forget();
			cms_types::ensure('zzp2aidpage', array('slug' => 'text', 'name' => 'text'), array('name'));
			same('name__id', cms_types::column('name', 'id'));
			same(array('id', 'slug', 'name', 'name__id'), p2a_columns('zzp2aidpage'));
			same('text', cms_types::of_table('zzp2aidpage')['name__id']);
			same(array('name'), cms_types::translated('zzp2aidpage'));
			foreach (array(false, true) as $freeze) {
				R::freeze($freeze);
				foreach (array('Selamat datang', '12', '', 'Kopi & kue') as $value) {
					$bean = R::dispense('zzp2aidpage');
					$bean->slug = '/zzp2aid';
					$bean->name = 'Welcome';
					$bean->name__id = $value;
					$id = R::store($bean);
					$row = R::load('zzp2aidpage', $id);
					same($value, (string)$row->name__id, ($freeze ? 'frozen' : 'fluid').' '.json_encode($value));
					same('Welcome', $row->name, 'and its field');
					$row->name__id = $value.'!';
					R::store($row);
					same($value.'!', (string)R::load('zzp2aidpage', $id)->name__id, 'updated');
					same($value.'!', (string)R::getCell('SELECT name__id FROM zzp2aidpage WHERE id = ?', array($id)), 'as SQL reads it');
				}
			}
			R::freeze(false);
			same('text', cms_types::of_column(cms_store::columns('zzp2aidpage')['name__id']), 'still text');
			same(array('id', 'slug', 'name', 'name__id'), p2a_columns('zzp2aidpage'), 'nothing added');
			// no link: no foreign key or index on it
			if (R::getDatabaseAdapter()->getDatabase()->getDatabaseType() === 'mysql') {
				same(0, (int)R::getCell("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'zzp2aidpage' AND column_name = 'name__id'"));
				same(0, (int)R::getCell("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'zzp2aidpage' AND column_name = 'name__id'"));
			} else {
				same(0, (int)R::getCell("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = 'zzp2aidpage' AND sql LIKE '%name\_\_id%' ESCAPE '\\'"));
				same(array(), R::getAll('PRAGMA foreign_key_list(zzp2aidpage)'));
			}
		});
	} finally {
		R::freeze($frozen);
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2aidpage');
		cms_store::forget();
	}
});

test('P2.A: saved_lang and __ are reserved everywhere', function () {
	check(in_array('saved_lang', cms_store::$system_fields, true), 'cms_store::$system_fields');
	same('text', cms_types::$system['saved_lang']);
	$grammar = raster_inspector::grammar();
	check(in_array('saved_lang', $grammar['references']['reserved_fields'], true), 'annotations');
	foreach (array(array('saved_lang', 'field'), array('head__ro', 'field'), array('a__b', 'field'), array('news__ro', 'collection'), array('team__members', 'collection')) as $name) {
		same(true, cms::reserved($name[0], $name[1]), json_encode($name));
	}
	same(false, cms::reserved('team_members', 'collection'));
	same(false, cms::reserved('head_line', 'field'));
	check(has_problem(lint_html('<p><!-- print.cms.saved_lang -->x<!-- /print.cms.saved_lang --></p>'), "'saved_lang' is reserved by the CMS"), 'lint');
});

test('P2.A: double_underscore is an error, multilingual or not', function () {
	foreach (array(array(), p2a_on()) as $settings) {
		with_settings($settings, function () {
			$errors = function ($html) {
				return array_values(array_map(function ($p) { return $p['message']; }, array_filter(lint_html($html), function ($p) { return $p['severity'] === 'error'; })));
			};
			$said = "has __ in it, which Raster keeps for translation columns";
			$field = $errors('<p><!-- print.cms.head__line -->x<!-- /print.cms.head__line --></p>');
			check(count($field) === 1 && strpos($field[0], "'head__line' $said") !== false && strpos($field[0], 'give the field a name') !== false, json_encode($field));
			$collection = $errors('<ul><!-- render.cms.news__ro --><li><!-- print.title -->T<!-- /print.title --></li><!-- /render.cms.news__ro --></ul>');
			check(count($collection) === 1 && strpos($collection[0], "'news__ro' $said") !== false && strpos($collection[0], 'give the collection a name') !== false, json_encode($collection));
			$key = $errors('<ul><!-- render.cms.zzp2anews --><li><!-- print.sub__title -->T<!-- /print.sub__title --></li><!-- /render.cms.zzp2anews --></ul>');
			check(count($key) === 1 && strpos($key[0], "'sub__title' $said") !== false, json_encode($key));
			same(array(), $errors('<p><!-- print.cms.head_line -->x<!-- /print.cms.head_line --></p>'), 'one _ is fine');
		});
	}
});

test('P2.A: the content model says which fields are translated, every place they print', function () use ($root) {
	$theme = "$root/application/views/default";
	foreach (array('zzp2amodel.html', 'zzp2aother.html') as $view) test_fixture("$theme/$view");
	file_put_contents("$theme/zzp2amodel.html", '<html><head><title>M</title></head><body>'
		.'<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>'
		.'<!-- print.@src.cms.photo --><!-- print.@alt.cms.photo_alt --><img src="a.jpg" alt="A cup"><!-- /print.@alt.cms.photo_alt --><!-- /print.@src.cms.photo -->'
		.'<!-- print.@placeholder.cms.ask --><input placeholder="Your name"><!-- /print.@placeholder.cms.ask -->'
		.'<p><!-- print.cms.seats -->14<!-- /print.cms.seats --></p>'
		// a link's text and its href: one value, shared
		.'<!-- print.@href.cms.site_link --><a href="https://example.com"><!-- print.cms.site_link -->https://example.com<!-- /print.cms.site_link --></a><!-- /print.@href.cms.site_link -->'
		.'<p><!-- print.cms.site_motto -->Good coffee<!-- /print.cms.site_motto --></p>'
		.'<ul><!-- render.cms.zzp2adishes --><li><!-- print.name -->Soup<!-- /print.name --><!-- print.@src.photo --><img src="s.jpg"><!-- /print.@src.photo -->'
		.'<!-- print.price -->4.50<!-- /print.price --><!-- print.@title.note --><b title="Hot">!</b><!-- /print.@title.note --></li><!-- /render.cms.zzp2adishes --></ul>'
		.'</body></html>');
	// site_motto printed into an href on another page is shared too
	file_put_contents("$theme/zzp2aother.html", '<html><head><title>O</title></head><body><!-- print.@href.cms.site_motto --><a href="/x">x</a><!-- /print.@href.cms.site_motto -->'
		.'<ul><!-- render.cms.zzp2adishes --><li><!-- print.@data-name.name --><i data-name="Soup">i</i><!-- /print.@data-name.name --></li><!-- /render.cms.zzp2adishes --></ul></body></html>');
	try {
		foreach (array(array(), p2a_on()) as $settings) {
			$model = with_settings($settings, function () { return (new raster_inspector())->content_model(); });
			$page = null; $site = null;
			foreach ($model['pages'] as $p) {
				if ($p['view'] === 'zzp2amodel.html') $page = $p;
				if ($p['type'] === 'sitepage') $site = $p;
			}
			$translate = array_map(function ($f) { return $f['translate']; }, $page['fields']);
			same(array('headline' => true, 'photo' => false, 'photo_alt' => true, 'ask' => true, 'seats' => false), $translate, 'page fields, '.json_encode($settings));
			same(false, $site['fields']['site_link']['translate'], 'text and href: shared');
			same(false, $site['fields']['site_motto']['translate'], 'content here, an href there: shared');
			same(true, $site['fields']['site_name']['translate'], "the starter's site_name");
			$dishes = array_map(function ($f) { return $f['translate']; }, $model['collections']['zzp2adishes']['fields']);
			same(array('name' => false, 'photo' => false, 'price' => false, 'note' => true), $dishes, 'collection fields (name is in a data- attribute on the other page)');
			foreach ($model['collections'] as $name => $collection) {
				foreach ($collection['fields'] as $field => $info) check(array_key_exists('translate', $info), "$name.$field has the key");
				if (isset($collection['model'])) foreach ($collection['fields'] as $info) same(false, $info['translate'], "a record type's fields are shared until P3: $name");
			}
		}
	} finally {
		foreach (array('zzp2amodel.html', 'zzp2aother.html') as $view) @unlink("$theme/$view");
	}
});

test('P2.A: off is identical: schema output and the content model\'s public views', function () use ($root, $db) {
	// describe and site_overview pick their keys: no translate in them
	require_once BASE.'tools/describe.php';
	$described = json_encode(raster_describe::site(array('pages', 'collections')));
	check(strpos($described, 'translate') === false, 'describe: '.$described);
	// a site without languages: schema's status has exactly 2.2.45's keys;
	// translations only when some table holds them (an earlier test's)
	$left = p2a_translation_columns();
	$status = (new raster_schema())->status();
	same(array_merge(array('environment', 'frozen', 'drift', 'tables', 'system_tables', 'unused_tables', 'site_url'), $left ? array('translations') : array()), array_keys($status));
	if ($left) same(array_intersect_key($left, $status['translations']), $status['translations'], 'only columns that are there');
	foreach ($status['tables'] as $table) {
		check(!array_key_exists('missing_translations', $table) && !array_key_exists('translated', $table) && !array_key_exists('kept', $table), $table['table']);
	}
	// and lint says nothing of languages
	$problems = (new raster_inspector())->lint();
	check(!has_problem($problems, 'the same in every language') && !has_problem($problems, "names one language on every language's pages"), 'no language warnings off');
});

test('P2.A: schema --apply adds exactly the translation columns; off they are kept translations, never orphans or drift', function () use ($root, $db) {
	$theme = "$root/application/views/default";
	$view = "$theme/zzp2aschema.html";
	test_fixture($view);
	$write = function ($headline) use ($view) {
		file_put_contents($view, '<html><head><title>S</title></head><body>'
			."<h1><!-- print.cms.$headline -->Hello<!-- /print.cms.$headline --></h1>"
			.'<!-- print.@src.cms.photo --><!-- print.@alt.cms.photo_alt --><img src="a.jpg" alt="A cup"><!-- /print.@alt.cms.photo_alt --><!-- /print.@src.cms.photo -->'
			.'<p><!-- print.cms.seats -->14<!-- /print.cms.seats --></p></body></html>');
	};
	$write('headline');
	$type = cms::page_type('/zzp2aschema');
	$schema = new raster_schema();
	$table = function ($status) use ($type) {
		foreach ($status['tables'] as $t) if ($t['table'] === $type) return $t;
		return null;
	};
	$changes = array();
	$raster = function ($args) use ($root, $db) {
		exec('RASTER_ENV=development RASTER_DB='.escapeshellarg($db).' '.php_cmd().' '.escapeshellarg("$root/bin/raster").' '.$args.' 2>&1', $out, $code);
		return array($code, implode("\n", $out));
	};
	try {
		// off: what 2.2.45 said, and the table made as it was
		$status = $schema->status();
		check(p2a_translation_columns() ? !isset($status['translations'][$type]) : !array_key_exists('translations', $status), 'no translations key off, on a site that never had any');
		same(array('view', 'url', 'slug', 'kind', 'table', 'exists', 'rows', 'fields', 'missing', 'retyped', 'narrow', 'orphans', 'rename_candidates'), array_keys($table($status)), 'the keys of 2.2.45');
		$changes = $schema->apply();
		same(array('id', 'slug', 'updated_at', 'headline', 'photo', 'photo_alt', 'seats'), p2a_columns($type), 'off: no translation columns');
		$drift_before = $schema->status()['drift'];
		// on: the translated fields' columns, saved_lang, and nothing else
		$on = with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () use ($schema, $table, $type, &$changes) {
			$status = $schema->status();
			$t = $table($status);
			same(array('headline', 'photo_alt'), $t['translated']);
			same(array('headline__ro', 'headline__pt_br', 'photo_alt__ro', 'photo_alt__pt_br', 'saved_lang'), $t['missing_translations']);
			same(true, $status['drift'], 'missing translations are drift');
			same(array(), $status['translations'], 'on, the key is there');
			$applied = $schema->apply();
			$changes = array_merge($changes, $applied);
			foreach (array('headline__ro', 'headline__pt_br', 'photo_alt__ro', 'photo_alt__pt_br', 'saved_lang') as $column) check(in_array("added $type.$column", $applied, true), "added $column: ".json_encode($applied));
			same(array('id', 'slug', 'updated_at', 'headline', 'photo', 'photo_alt', 'seats', 'saved_lang', 'headline__ro', 'headline__pt_br', 'photo_alt__ro', 'photo_alt__pt_br'), p2a_columns($type));
			$t = $table($schema->status());
			same(array(), $t['missing_translations']);
			same(array(), $t['orphans']);
			// a second --apply adds nothing here
			same(array(), array_values(array_filter($schema->apply(), function ($c) use ($type) { return strpos($c, $type) !== false; })));
			// translations a language no longer listed holds: kept
			return with_settings(p2a_on(array('en', 'ro')), function () use ($schema, $table) { return array($table($schema->status()), $schema->status()['translations']); });
		});
		same(array(), $on[0]['orphans'], 'pt-br dropped from languages: no orphans');
		same(array('headline__pt_br', 'photo_alt__pt_br'), $on[1][$type], 'kept');
		// off again: kept translations, no drift, no orphans, no renames
		$status = $schema->status();
		$t = $table($status);
		same(array(), $t['orphans']);
		same(array(), $t['rename_candidates']);
		same(array(), $t['missing']);
		check(!array_key_exists('missing_translations', $t), 'off: no missing translations');
		same(array('headline__ro', 'headline__pt_br', 'photo_alt__ro', 'photo_alt__pt_br'), $status['translations'][$type]);
		same($drift_before, $status['drift'], 'no drift from them');
		check(!in_array($type, $status['unused_tables']), 'used');
		list($code, $out) = $raster('schema');
		check(strpos($out, 'kept translations: headline__ro, headline__pt_br, photo_alt__ro, photo_alt__pt_br') !== false, $out);
		check(strpos($out, "--drop=$type") === false && strpos($out, "--rename=$type") === false, 'nothing to drop or rename: '.$out);
		check(!preg_match('/-\s+headline__ro/', $out), 'not listed as an orphan');
		list($code, $out) = $raster('schema --json');
		same(array('headline__ro', 'headline__pt_br', 'photo_alt__ro', 'photo_alt__pt_br'), json_decode($out, true)['translations'][$type], $out);
		// --check says what it said before the translations were made (earlier
		// tests may leave drift of their own; these columns add none)
		same($drift_before ? 1 : 0, $raster('schema --check')[0], '--check passes as it did');
		// a renamed field: its old column is the orphan and the rename, never its translations
		$write('title');
		$t = $table($schema->status());
		same(array('headline'), $t['orphans']);
		same(array(array('from' => 'headline', 'to' => 'title')), $t['rename_candidates']);
		with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () use ($schema, $table) {
			$t = $table($schema->status());
			same(array('headline', 'headline__ro', 'headline__pt_br'), $t['orphans'], 'on: the old field and its translations go together');
			same(array(array('from' => 'headline', 'to' => 'title')), $t['rename_candidates']);
		});
	} finally {
		@unlink($view);
		p2a_undo($changes, array($type));
	}
});

test('P2.A: untranslatable_text warns on words no annotation prints, multilingual on only', function () {
	$html = "<!doctype html>\n<!-- print.@lang.i18n.language --><html lang=\"en\"><!-- /print.@lang.i18n.language -->\n<head><title>Our café</title>\n"
		."<style>body::after { content: 'styled words'; }</style><script>var said = 'script words';</script></head><body>\n"
		."<h1><!-- print.cms.headline -->Mock-up words<!-- /print.cms.headline --></h1>\n"
		."<p>   </p><p>14:00 – 2026, 4.50 € · © → | 12%</p><p>x</p>\n"
		."<!-- a comment with words -->\n"
		."<!-- remove --><p>Lorem ipsum dolor</p><!-- /remove -->\n"
		."<!-- print.@alt.cms.photo_alt --><img src=\"a.jpg\" alt=\"Alt mock-up\"><!-- /print.@alt.cms.photo_alt -->\n"
		."<p><!-- print.i18n.zzp2a('hello') -->Hello<!-- /print.i18n.zzp2a('hello') --></p>\n"
		."<!-- render.cms.zzp2alist --><li><!-- print.name -->Soup<!-- /print.name --></li><!-- /render.cms.zzp2alist -->\n"
		."<p>Opening hours</p>\n"
		."<!-- print.if.logged_in --><p>Members only</p><!-- /print.if.logged_in -->\n"
		."<img src=\"b.jpg\" alt=\"Our team\"><input placeholder=\"Your email\">\n"
		."<!-- print.@href.cms.link --><a href=\"/x\">Read more</a><!-- /print.@href.cms.link -->\n"
		."<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><meta name=\"description\" content=\"Coffee and cake\">\n"
		."</body></html>";
	$warned = function ($html) {
		$lines = array();
		foreach (lint_html($html) as $p) if (strpos($p['message'], 'is the same in every language') !== false) $lines[$p['line']] = $p['message'];
		return $lines;
	};
	same(array(), $warned($html), 'off: nothing');
	$lines = with_settings(p2a_on(), function () use ($warned, $html) { return $warned($html); });
	same(array(3, 12, 13, 14, 15, 16), array_keys($lines), json_encode($lines));
	check(strpos($lines[3], "'Our café' is the same in every language") !== false, $lines[3]);
	check(strpos($lines[3], "print.cms.<name>") !== false && strpos($lines[3], "print.i18n.<file>('key')") !== false, 'suggests both');
	check(strpos($lines[12], "'Opening hours'") !== false, $lines[12]);
	check(strpos($lines[14], "alt=\"Our team\"") !== false, 'a fixed alt: '.$lines[14]);
	check(strpos($lines[16], 'content="Coffee and cake"') !== false, 'a description: '.$lines[16]);
	// an email or an address alone, code, and a button's words
	$more = "<html><body>\n<input placeholder=\"you@example.com\"><input placeholder=\"https://example.com\">\n<a href=\"/x\">www.example.com</a> <span>hello@example.com</span>\n"
		."<pre>echo hello world</pre><code>print.cms.headline</code>\n<input type=\"submit\" value=\"Send it\">\n<input type=\"text\" value=\"Typed words\">\n<button type=\"submit\">Go now</button>\n</body></html>";
	$lines = with_settings(p2a_on(), function () use ($warned, $more) { return $warned($more); });
	same(array(5, 7), array_keys($lines), json_encode($lines));
	check(strpos($lines[5], 'value="Send it"') !== false, $lines[5]);
	// it never floods: ten lines, then one that counts the rest
	$many = '<html><body>'.str_repeat("\n<p>Fixed words</p>", 25).'</body></html>';
	$problems = array_values(array_filter(with_settings(p2a_on(), function () use ($many) { return lint_html($many); }), function ($p) { return strpos($p['message'], 'is the same in every language') !== false || strpos($p['message'], 'more line(s) of this view') !== false; }));
	same(11, count($problems), json_encode($problems));
	check(strpos($problems[10]['message'], '15 more line(s) of this view') !== false, $problems[10]['message']);
	// emails are read too: each goes out in its recipient's language (P4.C)
	$file = sys_get_temp_dir().'/raster-p2a-'.getmypid().'/_email';
	@mkdir($file, 0775, true);
	file_put_contents("$file/zz.html", '<html><body><p>Fixed words</p></body></html>');
	try {
		$problems = with_settings(p2a_on(), function () use ($file) { return (new raster_inspector())->lint_path("$file/zz.html"); });
		check(strpos(json_encode($problems), "'Fixed words' is the same in every language") !== false, json_encode($problems));
	} finally {
		@unlink("$file/zz.html");
		@rmdir($file);
		@rmdir(dirname($file));
	}
});

test('P2.A: html_lang_fixed warns on an <html> whose lang no print.i18n.language prints, multilingual on only', function () {
	$fixed = '<!doctype html><html lang="en"><head><title><!-- print.cms.t -->T<!-- /print.cms.t --></title></head><body></body></html>';
	$none = '<!doctype html><html><head></head><body></body></html>';
	$printed = '<!doctype html><!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr"><!-- /print.@dir.i18n.dir --><!-- /print.@lang.i18n.language --><head></head><body></body></html>';
	$said = "names one language on every language's pages";
	same(false, has_problem(lint_html($fixed), $said), 'off');
	with_settings(p2a_on(), function () use ($fixed, $none, $printed, $said) {
		check(has_problem(lint_html($fixed), '<html lang="en"> '.$said.': wrap it in <!-- print.@lang.i18n.language --><html lang="en"><!-- /print.@lang.i18n.language -->'), json_encode(lint_html($fixed)));
		check(has_problem(lint_html($none), '<html> '.$said), 'no lang at all');
		same(false, has_problem(lint_html($printed), $said), 'the starter\'s form');
		// a print closed before the <html> sets nothing on it
		$closed = '<!doctype html><!-- print.@lang.i18n.language --><!-- /print.@lang.i18n.language --><html lang="en"><head></head><body></body></html>';
		check(has_problem(lint_html($closed), $said), 'closed early');
		$self = '<!doctype html><!-- print.@lang.i18n.language --><!-- print.cms.note /--><html lang="en"><head></head><body></body></html>';
		check(has_problem(lint_html($self), $said), 'a self-closing print between');
		$comment = '<!doctype html><!-- print.@lang.i18n.language --><!-- a note --><html lang="en"><head></head><body></body></html>';
		same(false, has_problem(lint_html($comment), $said), 'a plain comment between is fine');
	});
});

test('P2.A: missing_translation_key warns on a dictionary word a language lacks, multilingual on only', function () use ($root) {
	$dirs = array("$root/application/i18n", "$root/application/i18n/ro", "$root/application/i18n/de");
	$made = array();
	foreach ($dirs as $dir) if (!is_dir($dir)) { mkdir($dir); $made[] = $dir; }
	foreach (array('ro', 'de') as $lang) test_fixture("$root/application/i18n/$lang/zzp2a.php");
	file_put_contents("$root/application/i18n/ro/zzp2a.php", "<?php return array('hello' => 'Salut', 'bye' => 'Pa');");
	file_put_contents("$root/application/i18n/de/zzp2a.php", "<?php return array('hello' => 'Hallo');");
	$html = "<p><!-- print.i18n.zzp2a('hello') -->Hello<!-- /print.i18n.zzp2a('hello') --></p>\n"
		."<p><!-- print.i18n.zzp2a('bye') -->Bye<!-- /print.i18n.zzp2a('bye') --></p>\n"
		."<p><!-- print.i18n.zzp2anone('hi') -->Hi<!-- /print.i18n.zzp2anone('hi') --></p>\n"
		."<!-- print.@alt.i18n.zzp2a('cup') --><img src=\"a.jpg\" alt=\"A cup\"><!-- /print.@alt.i18n.zzp2a('cup') -->\n"
		.'<p><!-- print.i18n.language -->en<!-- /print.i18n.language --></p>';
	$keys = function ($html) {
		$found = array();
		foreach (lint_html($html) as $p) if (preg_match("/^print\.i18n\.(\w+\('\w+'\)) has no entry in ([a-z, ]+) \(/", $p['message'], $m)) $found[$p['line']] = $m[1].' '.$m[2];
		return $found;
	};
	try {
		same(array(), $keys($html), 'off');
		same(array(2 => "zzp2a('bye') de", 3 => "zzp2anone('hi') de, ro", 4 => "zzp2a('cup') de, ro"), with_settings(p2a_on(array('en', 'de', 'ro')), function () use ($keys, $html) { return $keys($html); }));
		$one = with_settings(p2a_on(array('en', 'de')), function () use ($html) { return lint_html($html); });
		check(has_problem($one, "print.i18n.zzp2a('bye') has no entry in de (application/i18n/de/zzp2a.php), so that language's pages show the template's text: add 'bye' => '…'"), json_encode($one));
	} finally {
		foreach (array('ro', 'de') as $lang) @unlink("$root/application/i18n/$lang/zzp2a.php");
		foreach (array_reverse($made) as $dir) @rmdir($dir);
	}
});

test('P2.A: the starter linted multilingual: warnings only, a few per view', function () {
	$errors = function ($problems) { return array_values(array_filter($problems, function ($p) { return $p['severity'] === 'error'; })); };
	$off = (new raster_inspector())->lint();
	$on = with_settings(p2a_on(), function () { return (new raster_inspector())->lint(); });
	same($errors($off), $errors($on), 'no new errors');
	$per_file = array();
	foreach ($on as $p) if (strpos($p['message'], 'is the same in every language') !== false || strpos($p['message'], 'more line(s) of this view') !== false) $per_file[$p['file']] = (isset($per_file[$p['file']]) ? $per_file[$p['file']] : 0) + 1;
	foreach ($per_file as $file => $count) check($count <= 11, "$file: $count");
	// emails are read too (P4.C): the starter's words are dictionary keys
	// (P5.A), its <html lang> is fixed
	check(preg_grep('#_email/#', array_keys($per_file)) === array(), 'the emails: '.json_encode(array_keys($per_file)));
	check(array_filter($on, function ($p) { return strpos($p['file'], '_email/password_reset') !== false && strpos($p['message'], 'names one language') !== false; }) !== array(), 'the email\'s <html> is linted');
});

test('P2.A: schema --rename moves a field\'s translation columns with it', function () {
	cms_store::connect();
	$schema = new raster_schema();
	try {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2arenamepage');
		cms_store::forget();
		with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () {
			cms_types::ensure('zzp2arenamepage', array('slug' => 'text', 'headline' => 'text', 'title__pt_br' => 'text'), array('headline'));
		});
		R::getDatabaseAdapter()->exec("INSERT INTO zzp2arenamepage (slug, headline, headline__ro, headline__pt_br) VALUES ('/zz', 'Hello', 'Salut', 'Olá')");
		// title__pt_br is there already: that one is a move
		$columns = p2a_columns('zzp2arenamepage');
		same('would rename zzp2arenamepage.headline to title (and headline__ro to title__ro, headline__pt_br into title__pt_br)', $schema->rename('zzp2arenamepage', 'headline', 'title', true));
		same($columns, p2a_columns('zzp2arenamepage'), '--dry-run changes nothing');
		// off: kept translations move too
		same('renamed zzp2arenamepage.headline to title (and headline__ro to title__ro, headline__pt_br into title__pt_br)', $schema->rename('zzp2arenamepage', 'headline', 'title'));
		$now = p2a_columns('zzp2arenamepage');
		sort($now);
		same(array('id', 'slug', 'title', 'title__pt_br', 'title__ro'), $now);
		$row = R::getRow('SELECT * FROM zzp2arenamepage');
		same(array('Hello', 'Salut', 'Olá'), array($row['title'], $row['title__ro'], $row['title__pt_br']));
		// on: the same
		same('renamed zzp2arenamepage.title to name (and title__pt_br to name__pt_br, title__ro to name__ro)', with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () use ($schema) { return $schema->rename('zzp2arenamepage', 'title', 'name'); }));
		// a field without translations reads as before
		same('renamed zzp2arenamepage.slug to slug2', $schema->rename('zzp2arenamepage', 'slug', 'slug2'));
	} finally {
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp2arenamepage');
		cms_store::forget();
	}
});

// ## P2.D: models carry no page words (#127)

test('P2.D: the starter\'s nav: site::nav() gives each link its address and state, the layout its words (#127)', function () use ($base, $root, $p1b_on) {
	$model = file_get_contents("$root/application/models/site/site.php");
	foreach (array("'Home'", "'News'", "'About'", "'label'") as $word) check(strpos($model, $word) === false, "site.php still has $word");
	require_once "$root/application/models/site/site.php";
	same(array(array(
		'home' => array(array('url' => rtrim(config::get('link_uri'), '/').'/', 'state' => '')),
		'news' => array(array('url' => rtrim(config::get('link_uri'), '/').'/news', 'state' => '')),
		'about' => array(array('url' => rtrim(config::get('link_uri'), '/').'/about', 'state' => '')),
	)), with_settings(array('uri_string' => 'nowhere'), function () { return (new site())->nav(); }));
	$nav = function ($body) {
		preg_match_all('#<a class="nav-link[^"]*" href="[^"]*">(Home|News|About)</a>#', $body, $m);
		return implode("\n", $m[0]);
	};
	same('<a class="nav-link active" href="'.$base.'/">Home</a>'."\n".'<a class="nav-link" href="'.$base.'/news">News</a>'."\n".'<a class="nav-link" href="'.$base.'/about">About</a>', $nav(http('GET', "$base/")[1]));
	same('<a class="nav-link" href="'.$base.'/">Home</a>'."\n".'<a class="nav-link active" href="'.$base.'/news">News</a>'."\n".'<a class="nav-link" href="'.$base.'/about">About</a>', $nav(http('GET', "$base/news")[1]));
	// multilingual, the links keep the page's language
	p1b_site($p1b_on, function () use ($base, $nav) {
		same('<a class="nav-link" href="'.$base.'/ro/">Home</a>'."\n".'<a class="nav-link" href="'.$base.'/ro/news">News</a>'."\n".'<a class="nav-link active" href="'.$base.'/ro/about">About</a>', $nav(http('GET', "$base/ro/about")[1]));
	});
});

test('P2.D: newsletter\'s fixed queries are sql/ files and show in vocabulary (#127)', function () use ($root) {
	$vocabulary = (new raster_inspector())->vocabulary();
	foreach (array('confirmed_count', 'confirmed_subscribers', 'unreached_subscribers') as $query) {
		check(database::query_file('newsletter', $query) !== null, "no $query.sql");
		check(in_array($query, $vocabulary['models']['newsletter']['queries'], true), 'vocabulary: '.json_encode($vocabulary['models']['newsletter']['queries']));
	}
	check(strpos(file_get_contents("$root/system/models/newsletter/newsletter.php"), "R::find('subscriber'") === false, 'no confirmed-subscriber SQL left in newsletter.php');
});

// ## P3 kickoff: the contracts the P3 packages code against

test('P3 kickoff: off, and in the default language, the $lang arguments change no SQL string', function () {
	$columns = array('id' => 'INTEGER', 'slug' => 'TEXT', 'slug__ro' => 'TEXT', 'name' => 'TEXT', 'name__ro' => 'TEXT', 'price' => 'INT', 'published_at' => 'DATETIME', 'updated_at' => 'DATETIME');
	$conditions = array(array('name', '=', 'Flat white'), array('price', '>', '4'), array('name', '!=', 'x'), array('slug', '=', 'flat-white'));
	// today's SQL, written out once so a change to it can't hide
	same(array(" AND `name` = :c0_name  AND `price` > :c1_price  AND (`name` IS NULL OR `name` != :c2_name)  AND `slug` = :c3_slug ", array(':c0_name' => 'Flat white', ':c1_price' => 4, ':c2_name' => 'x', ':c3_slug' => 'flat-white')), cms_store::conditions_sql($conditions, $columns));
	same('`name` ASC, `price` DESC, id ASC', cms_store::order_sql('name,-price', $columns));
	$settings = array(
		'off' => array('multilingual' => false, 'languages' => array()),
		'languages, switch off' => array('multilingual' => false, 'languages' => array('en', 'ro')),
		'one language' => array('multilingual' => true, 'languages' => array('en')),
		'on' => array('multilingual' => true, 'languages' => array('en', 'ro')),
	);
	foreach ($settings as $what => $setting) {
		with_settings($setting, function () use ($what, $columns, $conditions) {
			$conditions_sql = cms_store::conditions_sql($conditions, $columns);
			$order_sql = cms_store::order_sql('name,-price,newest', $columns);
			// on, only the default language (and null) is promised to be today's
			$langs = $what === 'on' ? array(null, 'en') : array(null, 'en', 'ro', 'pt-br');
			foreach ($langs as $lang) {
				same($conditions_sql, cms_store::conditions_sql($conditions, $columns, 'c', $lang), "$what, conditions in ".var_export($lang, true));
				same($order_sql, cms_store::order_sql('name,-price,newest', $columns, $lang), "$what, order in ".var_export($lang, true));
				if ($what !== 'on') {
					same('slug', cms_store::slug_column($lang), "$what, slug column");
					same(null, cms_store::resolve_slug('zzp3kickdata', 'flat-white', $lang), "$what, resolve_slug");
					same('menu/menu_item/flat-white', cms::path_in('menu/menu_item/flat-white', $lang), "$what, path_in");
				}
			}
		});
	}
	with_settings($settings['on'], function () {
		same('slug', cms_store::slug_column('en'));
		same('slug__ro', cms_store::slug_column('ro'));
	});
});

// takes an index away if it is there: SQLite knows DROP INDEX IF EXISTS;
// MySQL doesn't, and MariaDB, which the adapter also reports as mysql,
// takes the same ALTER TABLE
function p3_drop_index($table, $index) {
	$adapter = R::getDatabaseAdapter();
	if ($adapter->getDatabase()->getDatabaseType() !== 'mysql') {
		$adapter->exec("DROP INDEX IF EXISTS `$index`");
		return;
	}
	if ($adapter->getCell('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', array($table, $index))) $adapter->exec("ALTER TABLE `$table` DROP INDEX `$index`");
}

test('P3 kickoff: off, list_where and index_slug send the SQL they always did', function () {
	cms_store::connect();
	$adapter = R::getDatabaseAdapter();
	try {
		cms_types::ensure('zzp3kickdata', array('slug' => 'text', 'name' => 'text', 'price' => 'int', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
		with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () {
			$conditions = cms_store::list_options('price>4&order=name')['conditions'];
			$plain = cms_store::list_where('zzp3kick', $conditions, array('slug' => 'flat-white'), array());
			foreach (array('en', 'ro') as $lang) same($plain, cms_store::list_where('zzp3kick', $conditions, array('slug' => 'flat-white'), array(), $lang), "list_where in $lang");
		});
		// the slug index: the same statements with and without the column named
		p3_drop_index('zzp3kickdata', 'zzp3kickdata_slug');
		$plain = p2a_sql(function () { same(true, cms_types::index_slug('zzp3kickdata')); });
		p3_drop_index('zzp3kickdata', 'zzp3kickdata_slug');
		same($plain, p2a_sql(function () { same(true, cms_types::index_slug('zzp3kickdata', 'slug')); }));
		same(false, cms_types::index_slug('zzp3kickdata', 'slug'), 'made once');
		try {
			cms_types::index_slug('zzp3kickdata', 'name');
			check(false, 'only a slug column is indexed as one');
		} catch (InvalidArgumentException $e) {
			check(true);
		}
	} finally {
		$adapter->exec('DROP TABLE IF EXISTS zzp3kickdata');
		cms_store::forget();
	}
});

// ## P3.A: items in a language, translated sort and filter

// a cms.item_saved listener, bound once
function p3a_saved($payload) { $GLOBALS['p3a_events'][] = $payload; }
function p3a_events() {
	static $bound = false;
	if (!$bound) event::bind('cms.item_saved')->to(null, 'p3a_saved');
	$bound = true;
	$GLOBALS['p3a_events'] = array();
}
// the SQL $fn sends, with the clock taken out (updated_at differs a second later)
function p3a_sql($fn) {
	return preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', 'T', json_encode(p2a_sql($fn)));
}
// a model declaring three record types: one names its translated fields,
// one its shared ones, one neither
function p3a_model($fn) {
	global $root;
	$dir = "$root/application/models/zzp3a";
	temp_model($dir, array('zzp3a.php' => '<?php class zzp3a { static function types() { return array('
		.'"zzp3adish" => array("fields" => array("name" => "", "description" => "", "price" => 0, "lines" => array(), "email" => ""), "public" => true, "translate" => array("name", "description", "price", "lines", "nonsense")),'
		.'"zzp3aguest" => array("fields" => array("name" => "", "email" => "", "secret" => "", "lines" => array(), "seats" => 0), "hidden" => array("secret"), "shared" => array("email")),'
		.'"zzp3abook" => array("fields" => array("name" => "", "note" => ""))'
		.'); } }'));
	cms_records::forget();
	try {
		return $fn();
	} finally {
		@unlink("$dir/zzp3a.php");
		@rmdir($dir);
		cms_store::connect();
		foreach (array('zzp3adishdata', 'zzp3aguestdata', 'zzp3abookdata') as $table) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS `$table`");
		cms_records::forget();
		cms_store::forget();
	}
}

test('P3.A: a translation language sorts and filters by COALESCE(NULLIF(x__ro, \'\'), x); off and the default, today\'s strings', function () {
	$columns = array('id' => 'INTEGER', 'slug' => 'TEXT', 'slug__ro' => 'TEXT', 'name' => 'TEXT', 'name__ro' => 'TEXT', 'name__pt_br' => 'TEXT', 'note' => 'TEXT', 'price' => 'INT', 'published_at' => 'DATETIME', 'updated_at' => 'DATETIME');
	$conditions = array(array('name', '=', 'Flat white'), array('price', '>', '4'), array('name', '!=', 'x'), array('slug', '=', 'flat-white'), array('note', '=', 'hot'), array('name', '=', ''));
	$today = array(" AND `name` = :c0_name  AND `price` > :c1_price  AND (`name` IS NULL OR `name` != :c2_name)  AND `slug` = :c3_slug  AND `note` = :c4_note  AND `name` = :c5_name ", array(':c0_name' => 'Flat white', ':c1_price' => 4, ':c2_name' => 'x', ':c3_slug' => 'flat-white', ':c4_note' => 'hot', ':c5_name' => ''));
	$order = '`name` ASC, `note` DESC, CASE WHEN published_at IS NULL THEN updated_at ELSE published_at END DESC, id DESC';
	foreach (array(array('multilingual' => false, 'languages' => array()), array('multilingual' => false, 'languages' => array('en', 'ro')), p2a_on(array('en'))) as $settings) {
		with_settings($settings, function () use ($columns, $conditions, $today, $order) {
			foreach (array(null, 'en', 'ro', 'pt-br') as $lang) {
				same($today, cms_store::conditions_sql($conditions, $columns, 'c', $lang));
				same($order, cms_store::order_sql('name,-note,newest', $columns, $lang));
				same('`name`', cms_store::field_sql('name', $columns, $lang));
			}
		});
	}
	with_settings(p2a_on(array('en', 'ro', 'pt-br')), function () use ($columns, $conditions, $today, $order) {
		foreach (array(null, 'en', 'EN') as $lang) {
			same($today, cms_store::conditions_sql($conditions, $columns, 'c', $lang), var_export($lang, true));
			same($order, cms_store::order_sql('name,-note,newest', $columns, $lang));
		}
		// the translation where there is one, the default's value where it
		// is empty; a field without a column in the language as today; the
		// bindings as they were
		$ro = "COALESCE(NULLIF(`name__ro`, ''), `name`)";
		same(array(" AND $ro = :c0_name  AND `price` > :c1_price  AND ($ro IS NULL OR $ro != :c2_name)  AND COALESCE(NULLIF(`slug__ro`, ''), `slug`) = :c3_slug  AND `note` = :c4_note  AND $ro = :c5_name ", $today[1]),
			cms_store::conditions_sql($conditions, $columns, 'c', 'ro'));
		same("$ro ASC, `note` DESC, CASE WHEN published_at IS NULL THEN updated_at ELSE published_at END DESC, id DESC", cms_store::order_sql('name,-note,newest', $columns, 'ro'));
		same("$ro DESC, id ASC", cms_store::order_sql('-name', $columns, 'ro'));
		same("COALESCE(NULLIF(`name__pt_br`, ''), `name`) ASC, `slug` ASC, id ASC", cms_store::order_sql('name,slug', $columns, 'pt-br'), 'pt-br has no slug column');
		same('`name`', cms_store::field_sql('name', $columns, 'de'), 'a language the site doesn\'t list');
		same(array(" AND COALESCE(NULLIF(`name__ro`, ''), `name`) = :f0_name ", array(':f0_name' => 'Cafea')), cms_store::conditions_sql(array(array('name', '=', 'Cafea')), $columns, 'f', 'ro'));
	});
});

test('P3.A: types() translate and shared: translated_fields, the content model, ensure', function () {
	p3a_model(function () {
		same(array('name', 'description'), cms_records::translated_fields(cms_records::info('zzp3adish')), 'translate: its text fields, no int, no list, nothing unknown');
		same(array('name'), cms_records::translated_fields(cms_records::info('zzp3aguest')), 'shared: every other text field but lists and hidden ones');
		same(array(), cms_records::translated_fields(cms_records::info('zzp3abook')), 'neither: none');
		same(array(), cms_records::info('zzp3adish')['unknown'], 'translate and shared are known keys');
		$fields = (new raster_inspector())->content_model()['collections']['zzp3adish']['fields'];
		same(array('name' => true, 'description' => true, 'price' => false, 'lines' => false, 'email' => false), array_map(function ($f) { return $f['translate']; }, $fields));
		cms_store::connect();
		// off: the columns it always had, and the same SQL
		$off = p3a_sql(function () { cms_records::ensure(cms_records::info('zzp3adish')); });
		same(array('id', 'name', 'description', 'price', 'lines', 'email', 'slug', 'enabled', 'published_at', 'updated_at', 'created_at'), p2a_columns('zzp3adishdata'));
		R::getDatabaseAdapter()->exec('DROP TABLE zzp3adishdata');
		cms_store::forget();
		same($off, with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () { return p3a_sql(function () { cms_records::ensure(cms_records::info('zzp3adish')); }); }));
		// on: a column per language for the translated ones
		with_settings(p2a_on(), function () {
			cms_records::ensure(cms_records::info('zzp3adish'));
			$columns = p2a_columns('zzp3adishdata');
			check(in_array('name__ro', $columns) && in_array('description__ro', $columns) && !in_array('email__ro', $columns) && !in_array('price__ro', $columns), implode(', ', $columns));
			$again = p2a_sql(function () { cms_records::ensure(cms_records::info('zzp3adish')); });
			same(array(), preg_grep('/ALTER/i', $again), 'nothing the second time');
		});
	});
});

test('P3.A: save_item in a language writes its column, refuses a shared field, keeps saved_lang and sends lang; off as before', function () {
	cms_store::connect();
	$type = 'zzp3amenudata';
	p3a_events();
	try {
		cms_types::ensure($type, array('slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime', 'name' => 'text', 'photo' => 'text'));
		$allowed = array('name', 'photo');
		// off: lang changes no SQL, no saved_lang, the event as it was
		$item = cms_store::save_item($type, 0, array('name' => 'Flat white', 'photo' => 'a.jpg'), $allowed, 'editor', 'zzp3a_menu');
		$id = $item['id'];
		foreach (array(array('multilingual' => false, 'languages' => array()), array('multilingual' => false, 'languages' => array('en', 'ro'))) as $settings) {
			with_settings($settings, function () use ($type, $id, $allowed) {
				$plain = p3a_sql(function () use ($type, $id, $allowed) { cms_store::save_item($type, $id, array('name' => 'Flat white'), $allowed); });
				same($plain, p3a_sql(function () use ($type, $id, $allowed) { cms_store::save_item($type, $id, array('name' => 'Flat white'), $allowed, 'editor', null, 'ro'); }));
				check(strpos($plain, 'saved_lang') === false && strpos($plain, '__ro') === false, $plain);
			});
		}
		same(array('id', 'slug', 'enabled', 'published_at', 'updated_at', 'name', 'photo'), p2a_columns($type), 'off: no columns added');
		foreach ($GLOBALS['p3a_events'] as $event) same(array('collection', 'created', 'item'), array_keys($event));
		$GLOBALS['p3a_events'] = array();
		with_settings(p2a_on(), function () use ($type, $id, $allowed) {
			cms_types::ensure($type, array(), array('name'));
			$en = cms_store::save_item($type, $id, array('photo' => 'b.jpg'), $allowed, 'editor', 'zzp3a_menu');
			same('en', $en['saved_lang'], 'saved_lang, made in development');
			$ro = cms_store::save_item($type, $id, array('name' => 'Cafea cu lapte'), $allowed, 'editor', 'zzp3a_menu', 'ro');
			same(array('Flat white', 'Cafea cu lapte', 'ro', 'b.jpg'), array($ro['name'], $ro['name__ro'], $ro['saved_lang'], $ro['photo']));
			same(array(array('zzp3a_menu', 'en'), array('zzp3a_menu', 'ro')), array_map(function ($e) { return array($e['collection'], $e['lang']); }, $GLOBALS['p3a_events']));
			// a shared field in a translation: refused, nothing stored
			try {
				cms_store::save_item($type, $id, array('name' => 'X', 'photo' => 'c.jpg'), $allowed, 'editor', null, 'ro');
				check(false, 'a shared field in ro');
			} catch (cms_refused $e) {
				same(array('field_shared'), $e->problems);
			}
			try {
				cms_store::save_item($type, $id, array('name' => 'X'), $allowed, 'editor', null, 'xx');
				check(false, 'an unknown language');
			} catch (InvalidArgumentException $e) {
				check(strpos($e->getMessage(), 'Unknown language') !== false, $e->getMessage());
			}
			// a translation column is no field of its own
			try {
				cms_store::save_item($type, $id, array('name__ro' => 'X'), $allowed);
				check(false, 'name__ro');
			} catch (InvalidArgumentException $e) {
				check(true);
			}
			$row = cms_store::get_item($type, $id);
			same(array('Flat white', 'Cafea cu lapte', 'b.jpg'), array($row['name'], $row['name__ro'], $row['photo']));
			// shown in Romanian, the default's value when the translation is empty
			same('Cafea cu lapte', cms_store::localized($row, $type, 'ro')['name']);
			cms_store::save_item($type, $id, array('name' => ''), $allowed, 'editor', null, 'ro');
			same('Flat white', cms_store::localized(cms_store::get_item($type, $id), $type, 'ro')['name']);
		});
	} finally {
		R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $type");
		cms_store::forget();
	}
});

test('P3.A: a record in a language: save_item, and find() filters and sorts by the translation; off its SQL is today\'s', function () {
	p3a_model(function () {
		cms_store::connect();
		$sql = function ($fn) {
			$logs = p3a_sql($fn);
			preg_match_all('/SELECT [^"]*?zzp3adishdata[^"]*/', $logs, $m);
			return $m[0];
		};
		$find = function () { cms_records::find('zzp3adish', array('name' => 'Ciorbă'), 'name'); cms_records::find('zzp3adish', 'name=Ciorbă&order=-name'); };
		cms_records::ensure(cms_records::info('zzp3adish'));
		$today = $sql($find);
		check(count($today) === 2 && strpos($today[0], ' AND `name` = :f_name  ORDER BY `name` ASC, id ASC') !== false && strpos($today[1], ' AND `name` = :c0_name  ORDER BY `name` DESC, id ASC') !== false, json_encode($today));
		same($today, with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () use ($sql, $find) { return $sql(function () use ($find) { i18n::with('ro', $find); }); }), 'languages, switch off');
		with_settings(p2a_on(), function () use ($sql, $find, $today) {
			cms_records::ensure(cms_records::info('zzp3adish'));
			same($today, $sql($find), 'on, the default language');
			$ro = $sql(function () use ($find) { i18n::with('ro', $find); });
			$coalesce = "COALESCE(NULLIF(`name__ro`, ''), `name`)";
			check(strpos($ro[0], " AND $coalesce = :f_name  ORDER BY $coalesce ASC, id ASC") !== false && strpos($ro[1], " AND $coalesce = :c0_name  ORDER BY $coalesce DESC, id ASC") !== false, json_encode($ro));
			$writable = cms_records::writable(cms_records::info('zzp3adish'));
			$soup = cms_store::save_item('zzp3adishdata', 0, array('name' => 'Soup', 'email' => 'a@example.test'), $writable);
			$bread = cms_store::save_item('zzp3adishdata', 0, array('name' => 'Bread'), $writable);
			cms_store::save_item('zzp3adishdata', 0, array('name' => 'Apple pie'), $writable);
			cms_store::save_item('zzp3adishdata', $soup['id'], array('name' => 'Ciorbă'), $writable, 'editor', null, 'ro');
			cms_store::save_item('zzp3adishdata', $bread['id'], array('name' => 'Pâine'), $writable, 'editor', null, 'ro');
			try {
				cms_store::save_item('zzp3adishdata', $soup['id'], array('email' => 'b@example.test'), $writable, 'editor', null, 'ro');
				check(false, 'email is shared');
			} catch (cms_refused $e) {
				same(array('field_shared'), $e->problems);
			}
			$names = function ($rows) { return array_map(function ($r) { return $r['name']; }, $rows); };
			// Romanian: Apple pie (no Romanian), Ciorbă, Pâine
			same(array($soup['id']), array_map(function ($r) { return $r['id']; }, i18n::with('ro', function () { return cms_records::find('zzp3adish', array('name' => 'Ciorbă')); })), 'a filter matches Romanian text');
			same(array(), i18n::with('ro', function () { return cms_records::find('zzp3adish', 'name=Soup'); }), 'not its English');
			same(array('Apple pie'), $names(i18n::with('ro', function () { return cms_records::find('zzp3adish', 'name=Apple pie'); })), 'an untranslated one by its English');
			same(array('Apple pie', 'Soup', 'Bread'), $names(i18n::with('ro', function () { return cms_records::find('zzp3adish', array(), 'name'); })), 'sorted by the Romanian (rows as stored)');
			same(array('Apple pie', 'Bread', 'Soup'), $names(cms_records::find('zzp3adish', array(), 'name')), 'and by the English');
		});
	});
});

test('P3.A: on /ro/ a list sorts by the Romanian name, a filter matches Romanian text, an untranslated item shows English; the editor saves Romanian', function () {
	$type = 'zzp3amenudata';
	try {
		p2b_site(function ($dir) use ($type) {
			$view = "$dir/application/views/default/zzp3amenu.html";
			file_put_contents($view, '<!doctype html><html><head><title>Menu</title></head><body><ul>'
				."<!-- render.cms.zzp3a_menu('name=?name&order=name') --><li><b><!-- print.name -->Zz seed<!-- /print.name --></b>"
				.'<!-- print.@src.photo --><img src="seed.jpg" alt=""><!-- /print.@src.photo --></li>'
				."<!-- /render.cms.zzp3a_menu('name=?name&order=name') --></ul></body></html>");
			$feed = "$dir/application/views/default/zzp3afeed.html";
			file_put_contents($feed, "<!doctype html><html><head><title>F</title></head><body><!-- render.feed.items('zzp3a_menu') --><i><!-- print.name -->x<!-- /print.name --></i><!-- /render.feed.items('zzp3a_menu') --></body></html>");
			p2b_server($dir, function ($base) use ($type) {
				$names = function ($path) use ($base) {
					list($status, $body) = http('GET', $base.$path);
					same(200, $status, $path);
					preg_match_all('#<b>(.*?)</b>#', (string)$body, $m);
					return $m[1];
				};
				// the first request makes the table, and, multilingual, the
				// name's Romanian column; the photo is shared
				same(array('Zz seed'), $names('/zzp3amenu'));
				cms_store::forget();
				$columns = cms_store::columns($type);
				check(isset($columns['name__ro']) && !isset($columns['photo__ro']), implode(', ', array_keys($columns)));
				R::exec("DELETE FROM $type");
				$ids = with_settings(p2a_on(), function () use ($type) {
					$ids = array();
					foreach (array('Apple' => 'Măr', 'Banana' => 'Banană', 'Cherry' => null) as $en => $ro) {
						$item = cms_store::save_item($type, 0, array('name' => $en, 'photo' => strtolower($en).'.jpg'), array('name', 'photo'));
						if ($ro !== null) cms_store::save_item($type, $item['id'], array('name' => $ro), array('name', 'photo'), 'editor', null, 'ro');
						$ids[$en] = $item['id'];
					}
					return $ids;
				});
				same(array('Apple', 'Banana', 'Cherry'), $names('/zzp3amenu'), 'English, by the English name');
				same(array('Banană', 'Cherry', 'Măr'), $names('/ro/zzp3amenu'), 'Romanian, by the Romanian name; Cherry in English');
				same(array('Măr'), $names('/ro/zzp3amenu?name='.rawurlencode('Măr')), 'a filter matches Romanian text');
				same(array(), $names('/ro/zzp3amenu?name=Apple'), 'and not the English of a translated item');
				same(array('Cherry'), $names('/ro/zzp3amenu?name=Cherry'), 'an untranslated item by its English');
				same(array('Apple'), $names('/zzp3amenu?name=Apple'), 'English as always');
				// feeds in the page's language too
				$feed = http('GET', "$base/ro/zzp3afeed")[1];
				check(strpos($feed, '<i>Măr</i>') !== false && strpos($feed, '<i>Cherry</i>') !== false, $feed);
				// the editor on /ro/ writes the Romanian name, and the photo for every language
				$editor = p2b_login($base);
				list($config) = p2b_config("$base/ro/zzp3amenu", $editor);
				$post = function ($fields, $lang = 'ro') use ($base, $editor, $config) {
					list($status, $body) = form_post("$base/ro/api/cms/editor_save_item", $fields + array('csrf' => $config['csrf']), array("Cookie: $editor"));
					return array($status, json_decode($body, true), $body);
				};
				$cherry = array('collection' => 'zzp3a_menu', 'id' => $ids['Cherry']);
				list($status, $json, $body) = $post($cherry + array('fields' => array('name' => 'Cireșe', 'photo' => 'cirese.jpg'), 'lang' => 'ro'));
				same(200, $status, $body);
				same(array('Cireșe', 'cirese.jpg'), array($json['name'], $json['photo']), 'the item as /ro/ shows it');
				same(array('Banană', 'Cireșe', 'Măr'), $names('/ro/zzp3amenu'));
				same(array('Apple', 'Banana', 'Cherry'), $names('/zzp3amenu'), 'the English stays');
				check(strpos(http('GET', "$base/zzp3amenu")[1], 'cirese.jpg') !== false, 'the photo is everyone\'s');
				list($status) = $post($cherry + array('fields' => array('name' => 'Wrong'), 'lang' => 'en'));
				same(400, $status, 'a page open in another language');
				list($status) = $post($cherry + array('fields' => array('name' => 'Wrong')));
				same(400, $status, 'no lang');
				list($status) = $post($cherry + array('fields' => array('name__ro' => 'Wrong'), 'lang' => 'ro'));
				same(400, $status, 'a language\'s column is no field');
				check(strpos(http('GET', "$base/ro/zzp3amenu")[1], 'Wrong') === false, 'nothing refused was stored');
			});
		});
	} finally {
		cms_store::connect();
		R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $type");
		cms_store::forget();
	}
});

test('P3.A: schema knows item and record translations: missing ones are drift, --apply adds them', function () use ($root) {
	$view = "$root/application/views/default/zzp3aschema.html";
	test_fixture($view);
	file_put_contents($view, '<html><head><title>S</title></head><body><!-- render.cms.zzp3a_list --><p><!-- print.title -->Hello<!-- /print.title --><!-- print.@href.link --><a href="/x">x</a><!-- /print.@href.link --></p><!-- /render.cms.zzp3a_list --></body></html>');
	$changes = array();
	try {
		p3a_model(function () use (&$changes) {
			$schema = new raster_schema();
			$table = function ($status, $name) {
				foreach ($status['tables'] as $t) if ($t['table'] === $name) return $t;
				return null;
			};
			$changes = $schema->apply();
			$off = $table($schema->status(), 'zzp3alistdata');
			check(!array_key_exists('missing_translations', $off) && !array_key_exists('translated', $off), 'off: as 2.2.45');
			with_settings(p2a_on(), function () use ($schema, $table, &$changes) {
				$status = $schema->status();
				$list = $table($status, 'zzp3alistdata');
				same(array('title'), $list['translated']);
				same(array('title__ro', 'saved_lang', 'slug__ro'), $list['missing_translations'], 'slug__ro is P3.B\'s');
				$dish = $table($status, 'zzp3adishdata');
				same(array('name', 'description'), $dish['translated']);
				same(array('name__ro', 'description__ro', 'saved_lang', 'slug__ro'), $dish['missing_translations']);
				same(true, $status['drift']);
				$applied = $schema->apply();
				$changes = array_merge($changes, $applied);
				foreach (array('zzp3alistdata.title__ro', 'zzp3alistdata.saved_lang', 'zzp3adishdata.name__ro', 'zzp3adishdata.description__ro', 'zzp3adishdata.saved_lang', 'zzp3adishdata.slug__ro', 'zzp3alistdata.slug__ro') as $column) check(in_array("added $column", $applied, true), "$column: ".json_encode($applied));
				$status = $schema->status();
				same(array(), $table($status, 'zzp3alistdata')['missing_translations']);
				same(array(), $table($status, 'zzp3adishdata')['missing_translations']);
				same(array(), $table($status, 'zzp3adishdata')['orphans']);
			});
		});
	} finally {
		@unlink($view);
		p2a_undo($changes, array('zzp3alistdata'));
	}
});

// ## P3.B: translated slugs and their redirects (#138)

// a menu table for the P3.B tests, multilingual (en, ro): name and its
// Romanian, slug and slug__ro; dropped again, with slughistory, by p3b_drop
function p3b_table($table = 'zzp3bmenudata') {
	cms_store::connect();
	p3b_drop($table);
	with_settings(p2a_on(), function () use ($table) {
		cms_types::ensure($table, array('name' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'), array('name', 'slug'));
	});
}
function p3b_drop($table = 'zzp3bmenudata') {
	cms_store::connect();
	R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS `$table`");
	R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS slughistory');
	cms_store::forget();
}
// does $table have an index named $name?
function p3b_indexed($table, $name) {
	$adapter = R::getDatabaseAdapter();
	if ($adapter->getDatabase()->getDatabaseType() !== 'mysql') return (bool)$adapter->getCell("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", array($name));
	return (bool)$adapter->getCell('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', array($table, $name));
}
function p3b_save($id, $values, $table = 'zzp3bmenudata') {
	return with_settings(p2a_on(), function () use ($id, $values, $table) {
		return cms_store::save_item($table, $id, $values, array_keys($values));
	});
}

test('P3.B: off, and in the default language, slugs send the SQL they always did and keep no history', function () {
	p3b_drop();
	cms_types::ensure('zzp3bmenudata', array('name' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
	try {
		$settings = array(
			'off' => array('multilingual' => false, 'languages' => array()),
			'languages, switch off' => array('multilingual' => false, 'languages' => array('en', 'ro')),
			'one language' => array('multilingual' => true, 'languages' => array('en')),
		);
		$item = null;
		foreach ($settings as $what => $setting) {
			with_settings($setting, function () use ($what, &$item) {
				cms_store::forget();
				$plain = p2a_sql(function () { same('flat-white', cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0)); });
				foreach (array('en', 'ro') as $lang) {
					same($plain, p2a_sql(function () use ($lang) { same('flat-white', cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0, $lang)); }), "$what, unique_slug in $lang");
					same('Flat white', cms_store::slug_source(array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'), array(), $lang), "$what, slug_source in $lang");
				}
				same(array(), p2a_sql(function () {
					same(false, cms_store::record_slug('zzp3bmenudata', 1, 'flat-white', 'ro'));
					same(null, cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'ro'));
					same(null, cms_store::slug_item('zzp3bmenudata', 'flat-white', 'ro'));
					same('zzp3bmenu/zzp3bmenu_item/flat-white', cms::path_in('zzp3bmenu/zzp3bmenu_item/flat-white', 'ro'));
				}), "$what: no query");
				// a save and a rename: no slug__ro written, no slughistory, no query naming either
				$log = p2a_sql(function () use (&$item) {
					$item = cms_store::save_item('zzp3bmenudata', 0, array('name' => 'Flat white'), array('name'));
					$item = cms_store::save_item('zzp3bmenudata', $item['id'], array('slug' => 'flat-white-coffee'), array());
				});
				same('flat-white-coffee', $item['slug'], $what);
				check(!preg_grep('/slughistory|slug__|name__/', $log), "$what: ".implode(' | ', preg_grep('/slughistory|slug__|name__/', $log)));
				same(array('id', 'name', 'slug', 'enabled', 'published_at', 'updated_at'), p2a_columns('zzp3bmenudata'), "$what: no column added");
				same(false, cms_store::table_exists('slughistory'), "$what: no slughistory");
				$schema = new raster_schema();
				check(!isset($schema->system_tables()['slughistory']), "$what: schema lists no slughistory");
				R::exec('DELETE FROM zzp3bmenudata');
			});
		}
		// on, the default language is today's SQL too
		with_settings(p2a_on(), function () {
			cms_store::forget();
			$plain = p2a_sql(function () { cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0); });
			same($plain, p2a_sql(function () { cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0, 'en'); }), 'on, unique_slug in en');
			same('Flat white', cms_store::slug_source(array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'), array(), 'en'));
			same('Cafea cu lapte', cms_store::slug_source(array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'), array(), 'ro'));
			// a hidden field is never a slug, in any language
			same('Latte', cms_store::slug_source(array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte', 'dish' => 'Latte'), array('name'), 'ro'));
		});
	} finally {
		p3b_drop();
	}
});

test('P3.B: slug__ro is made from the translated title, indexed, and unique within its language', function () {
	p3b_table();
	try {
		check(array_key_exists('slug__ro', cms_store::columns('zzp3bmenudata')), 'slug__ro made by ensure');
		check(p3b_indexed('zzp3bmenudata', 'zzp3bmenudata_slug__ro'), 'slug__ro has its own index');
		check(p3b_indexed('zzp3bmenudata', 'zzp3bmenudata_slug'), 'slug keeps its own');
		$flat = p3b_save(0, array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'));
		same(array('flat-white', 'cafea-cu-lapte'), array($flat['slug'], $flat['slug__ro']));
		// no Romanian title: no Romanian slug, the default one is its /ro/ address
		$espresso = p3b_save(0, array('name' => 'Espresso'));
		same(array('espresso', ''), array($espresso['slug'], isset($espresso['slug__ro']) ? $espresso['slug__ro'] : ''));
		// the same words in both: nothing to make
		$latte = p3b_save(0, array('name' => 'Latte', 'name__ro' => 'Latte'));
		same(array('latte', ''), array($latte['slug'], isset($latte['slug__ro']) ? $latte['slug__ro'] : ''));
		with_settings(p2a_on(), function () use ($espresso) {
			// taken in Romanian: cafea-cu-lapte by flat white's own, espresso
			// by the item that is at its default slug in Romanian
			same('cafea-cu-lapte-2', cms_store::unique_slug('zzp3bmenudata', 'Cafea cu lapte', 0, 'ro'));
			same('espresso-2', cms_store::unique_slug('zzp3bmenudata', 'Espresso', 0, 'ro'));
			same('espresso', cms_store::unique_slug('zzp3bmenudata', 'Espresso', $espresso['id'], 'ro'), 'its own is free to it');
			// the default language looks at slug alone, as it always did
			same('cafea-cu-lapte', cms_store::unique_slug('zzp3bmenudata', 'Cafea cu lapte', 0));
			same('flat-white-2', cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0));
			same('flat-white', cms_store::unique_slug('zzp3bmenudata', 'Flat white', 0, 'ro'), "free in Romanian: flat white's is cafea-cu-lapte there");
		});
		// a second Cafea cu lapte gets its own Romanian slug
		$second = p3b_save(0, array('name' => 'White coffee', 'name__ro' => 'Cafea cu lapte'));
		same('cafea-cu-lapte-2', $second['slug__ro']);
		// a slug once made stays when the title changes
		$flat = p3b_save($flat['id'], array('name__ro' => 'Cafea cu lapte mare'));
		same('cafea-cu-lapte', $flat['slug__ro']);
	} finally {
		p3b_drop();
	}
});

test('P3.B: slughistory keeps every slug an item had, per language; resolve_slug says where it is now', function () {
	p3b_table();
	try {
		$flat = p3b_save(0, array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'));
		$espresso = p3b_save(0, array('name' => 'Espresso'));
		with_settings(p2a_on(), function () use ($flat, $espresso) {
			same(array(array('lang' => 'en', 'slug' => 'flat-white'), array('lang' => 'ro', 'slug' => 'cafea-cu-lapte')),
				R::getAll("SELECT lang, slug FROM slughistory WHERE `type` = 'zzp3bmenudata' AND item = ? ORDER BY id", array($flat['id'])));
			same(false, cms_store::record_slug('zzp3bmenudata', $flat['id'], 'flat-white', 'en'), 'once');
			same(false, cms_store::record_slug('zzp3bmenudata', $flat['id'], 'flat-white'), 'null is the default language');
			// its slug in the URL's language: shown as it is
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'cafea-cu-lapte', 'ro'));
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'en'));
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'espresso', 'ro'), 'no Romanian slug: the default is its Romanian one');
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'nothing', 'ro'), 'no item: the list 404s');
			same(null, cms_store::resolve_slug('zzp3bmenudata', (string)$espresso['id'], 'ro'), 'an id is an id (#90)');
			// another language's
			same(array('id' => $flat['id'], 'slug' => 'cafea-cu-lapte'), cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'ro'));
			same(array('id' => $flat['id'], 'slug' => 'flat-white'), cms_store::resolve_slug('zzp3bmenudata', 'cafea-cu-lapte', 'en'));
		});
		// renamed in English: the old slug is history
		p3b_save($flat['id'], array('slug' => 'flat-white-coffee'));
		// and in Romanian: slug, with lang (P3.A's mapping writes slug__ro)
		$ro = with_settings(p2a_on(), function () use ($flat) { return cms_store::save_item('zzp3bmenudata', $flat['id'], array('slug' => 'cafea-cu-lapte-mare'), array(), 'editor', null, 'ro'); });
		same(array('flat-white-coffee', 'cafea-cu-lapte-mare'), array($ro['slug'], $ro['slug__ro']));
		with_settings(p2a_on(), function () use ($flat) {
			same(array('id' => $flat['id'], 'slug' => 'flat-white-coffee'), cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'en'));
			same(array('id' => $flat['id'], 'slug' => 'cafea-cu-lapte-mare'), cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'ro'));
			same(array('id' => $flat['id'], 'slug' => 'cafea-cu-lapte-mare'), cms_store::resolve_slug('zzp3bmenudata', 'cafea-cu-lapte', 'ro'));
			same(array('id' => $flat['id'], 'slug' => 'flat-white-coffee'), cms_store::resolve_slug('zzp3bmenudata', 'cafea-cu-lapte', 'en'));
			same(4, (int)R::getCell("SELECT COUNT(*) FROM slughistory WHERE item = ?", array($flat['id'])));
		});
		// a slug an item has now wins over one another item had
		$new = p3b_save(0, array('name' => 'Flat white'));
		same('flat-white', $new['slug']);
		with_settings(p2a_on(), function () use ($new) {
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'en'));
			same(null, cms_store::resolve_slug('zzp3bmenudata', 'flat-white', 'ro'), 'the new item is at it in Romanian too');
		});
		// a slug from before multilingual was on is history once it changes
		with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () use (&$old) {
			$old = cms_store::save_item('zzp3bmenudata', 0, array('name' => 'Mocha'), array('name'));
		});
		same(0, (int)R::getCell("SELECT COUNT(*) FROM slughistory WHERE item = ?", array($old['id'])), 'off: nothing kept');
		p3b_save($old['id'], array('slug' => 'mocha-cup'));
		with_settings(p2a_on(), function () use ($old) {
			same(array('id' => $old['id'], 'slug' => 'mocha-cup'), cms_store::resolve_slug('zzp3bmenudata', 'mocha', 'en'));
		});
	} finally {
		p3b_drop();
	}
});

test('P3.B: path_in and item_alternates spell an item URL with each language\'s slug', function () {
	p3b_table();
	try {
		$flat = p3b_save(0, array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'));
		$espresso = p3b_save(0, array('name' => 'Espresso'));
		with_settings(p1c_on(array('uri_string' => '/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte')), function () use ($flat, $espresso) {
			same('zzp3bmenu/zzp3bmenu_item/flat-white', cms::path_in('zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte', 'en'));
			same('zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte', cms::path_in('zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte', 'ro'));
			same('/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte', cms::path_in('/zzp3bmenu/zzp3bmenu_item/flat-white', 'ro', 'en'));
			same('zzp3bmenu/zzp3bmenu_item/espresso', cms::path_in('zzp3bmenu/zzp3bmenu_item/espresso', 'en'), 'no Romanian slug: the default');
			same('zzp3bmenu/zzp3bmenu_item/nothing', cms::path_in('zzp3bmenu/zzp3bmenu_item/nothing', 'en'), 'no item: as it is');
			// any other path costs no query
			same(array(), p2a_sql(function () {
				same('about', cms::path_in('about', 'en'));
				same('zzp3bmenu/zzp3bmenu_page/2', cms::path_in('zzp3bmenu/zzp3bmenu_page/2', 'en'));
				same('zzp3bmenu/zzp3bmenu_items/name/x', cms::path_in('zzp3bmenu/zzp3bmenu_items/name/x', 'en'));
			}));
			// the page's alternates follow
			same(array(array('lang' => 'en', 'url' => 'http://cafe.test/zzp3bmenu/zzp3bmenu_item/flat-white'), array('lang' => 'ro', 'url' => 'http://cafe.test/ro/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte')), i18n::alternates());
			// an item's own, from its row, with no query
			$rows = R::getAll('SELECT * FROM zzp3bmenudata ORDER BY id');
			same(array(), p2a_sql(function () use ($rows, &$alternates) {
				foreach ($rows as $row) $alternates[] = cms::item_alternates('zzp3bmenu', $row);
			}));
			same(array(array('lang' => 'en', 'url' => 'http://cafe.test/zzp3bmenu/zzp3bmenu_item/flat-white'), array('lang' => 'ro', 'url' => 'http://cafe.test/ro/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte')), $alternates[0]);
			same(array(array('lang' => 'en', 'url' => 'http://cafe.test/zzp3bmenu/zzp3bmenu_item/espresso'), array('lang' => 'ro', 'url' => 'http://cafe.test/ro/zzp3bmenu/zzp3bmenu_item/espresso')), $alternates[1]);
			same('http://cafe.test/ro/zzp3bmenu/zzp3bmenu_item/7', cms::item_alternates('zzp3bmenu', array('id' => 7, 'slug' => ''))[1]['url'], 'no slug: the id');
		});
		// off: the stub's answer, the item's own slug in every language
		with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () use ($flat) {
			same(i18n::alternates('zzp3bmenu/zzp3bmenu_item/flat-white'), cms::item_alternates('zzp3bmenu', $flat));
		});
	} finally {
		p3b_drop();
	}
});

test('P3.B: /ro/ item URLs: its Romanian slug renders, another language\'s or an old one answers 301, off an old slug is a 404', function () {
	global $root;
	$view = "$root/application/views/default/zzp3bmenu.html";
	test_fixture($view);
	file_put_contents($view, '<!doctype html><html><head><title>Menu</title></head><body>'
		.'<!-- render.cms.zzp3bmenu --><article><!-- print.@href.raster_detail_link --><a href="#"><!-- print.name -->Flat white<!-- /print.name --></a><!-- /print.@href.raster_detail_link --></article><!-- /render.cms.zzp3bmenu -->'
		.'</body></html>');
	p3b_table();
	try {
		$flat = p3b_save(0, array('name' => 'Flat white', 'name__ro' => 'Cafea cu lapte'));
		p3b_save(0, array('name' => 'Espresso'));
		$draft = p3b_save(0, array('name' => 'Cortado'));
		p3b_save($draft['id'], array('slug' => 'cortado-mic', 'enabled' => 0));
		// renamed: the old slug redirects
		p3b_save($flat['id'], array('slug' => 'flat-white-coffee'));
		$on = "config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);";
		p1b_site($on, function () {
			p1b_server(array(), function ($port) {
				$base = "http://127.0.0.1:$port";
				$item = "$base/ro/zzp3bmenu/zzp3bmenu_item";
				list($status, $body) = http('GET', "$item/cafea-cu-lapte");
				same(200, $status, 'its Romanian slug');
				check(strpos($body, 'Cafea cu lapte') !== false && strpos($body, 'Espresso') === false, 'the one item, in Romanian');
				check(strpos($body, '<link rel="alternate" hreflang="en" href="'.$base.'/zzp3bmenu/zzp3bmenu_item/flat-white-coffee">') !== false, 'hreflang follows the slug: '.$body);
				check(strpos($body, '<link rel="canonical" href="'.$item.'/cafea-cu-lapte">') !== false, 'canonical');
				foreach (array(
					"$item/flat-white-coffee" => "$item/cafea-cu-lapte",
					"$item/flat-white" => "$item/cafea-cu-lapte",
					"$base/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte" => "$base/zzp3bmenu/zzp3bmenu_item/flat-white-coffee",
					"$base/zzp3bmenu/zzp3bmenu_item/flat-white" => "$base/zzp3bmenu/zzp3bmenu_item/flat-white-coffee",
					"$base/zzp3bmenu/zzp3bmenu_item/flat-white?x=1" => "$base/zzp3bmenu/zzp3bmenu_item/flat-white-coffee?x=1",
				) as $url => $to) {
					list($status, $body, $headers) = http('GET', $url);
					same(301, $status, $url);
					same($to, p1b_header($headers, 'Location'), $url);
					same('', (string)$body, "$url has no body");
					same(null, p1b_header($headers, 'X-Raster-Cache'), "$url is not cached");
				}
				same(200, http('GET', "$base/zzp3bmenu/zzp3bmenu_item/flat-white-coffee")[0]);
				same(200, http('GET', "$item/espresso")[0], 'no Romanian slug: at the default one');
				same(404, http('GET', "$item/nothing")[0]);
				// a draft's old slug says nothing to visitors
				same(404, http('GET', "$item/cortado")[0], 'draft');
				// a post is never redirected
				check(http('POST', "$base/zzp3bmenu/zzp3bmenu_item/flat-white", 'x=1')[0] !== 301, 'a post is not redirected');
			});
		});
		// off: an old slug is a 404, as it always was
		p1b_site("config::set('languages')->to(array('en', 'ro'));", function () {
			p1b_server(array(), function ($port) {
				$base = "http://127.0.0.1:$port";
				list($status, , $headers) = http('GET', "$base/zzp3bmenu/zzp3bmenu_item/flat-white");
				same(404, $status, 'off: the old slug');
				same(null, p1b_header($headers, 'Location'));
				same(200, http('GET', "$base/zzp3bmenu/zzp3bmenu_item/flat-white-coffee")[0]);
				same(404, http('GET', "$base/ro/zzp3bmenu/zzp3bmenu_item/cafea-cu-lapte")[0], 'off: no /ro/');
			});
		});
	} finally {
		@unlink($view);
		p3b_drop();
	}
});

test('P3.B: schema lists slug__ro and slughistory only when on, and --apply makes and indexes them', function () {
	global $root;
	$view = "$root/application/views/default/zzp3bschema.html";
	test_fixture($view);
	file_put_contents($view, '<!doctype html><html><head><title>S</title></head><body><!-- render.cms.zzp3bschema --><p><!-- print.name -->Tea<!-- /print.name --></p><!-- /render.cms.zzp3bschema --></body></html>');
	cms_store::connect();
	p3b_drop('zzp3bschemadata');
	cms_types::ensure('zzp3bschemadata', array('name' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
	$changes = array();
	try {
		$find = function ($status) {
			foreach ($status['tables'] as $table) if ($table['table'] === 'zzp3bschemadata') return $table;
			return null;
		};
		$off = (new raster_schema())->status();
		check(!array_key_exists('missing_translations', $find($off)), 'off: nothing missing');
		check(!in_array('slughistory', array_column($off['system_tables'], 'table'), true), 'off: no slughistory');
		with_settings(p2a_on(), function () use ($find, &$changes) {
			$schema = new raster_schema();
			$status = $schema->status();
			// the name's Romanian and saved_lang are P3.A's, slug__ro P3.B's
			same(array('name__ro', 'saved_lang', 'slug__ro'), $find($status)['missing_translations']);
			check($status['drift'], 'drift');
			$history = current(array_filter($status['system_tables'], function ($t) { return $t['table'] === 'slughistory'; }));
			same(false, $history['exists']);
			$changes = $schema->apply();
			check(in_array('added zzp3bschemadata.slug__ro', $changes, true), implode(' | ', $changes));
			check(in_array('created table slughistory', $changes, true), implode(' | ', $changes));
			check(p3b_indexed('zzp3bschemadata', 'zzp3bschemadata_slug__ro'), 'indexed');
			check(p3b_indexed('slughistory', 'slughistory_slug'), 'slughistory indexed by slug');
			cms_store::forget();
			same(array(), $find($schema->status())['missing_translations']);
		});
		// off again: slug__ro is a kept translation, never an orphan
		cms_store::forget();
		$off = (new raster_schema())->status();
		same(array(), $find($off)['orphans']);
		$kept = $off['translations']['zzp3bschemadata'];
		sort($kept);
		same(array('name__ro', 'slug__ro'), $kept);
	} finally {
		@unlink($view);
		p2a_undo($changes, array('zzp3bschemadata', 'slughistory'));
	}
});

// ## P3.D: the coding MCP translates items

// The starter with a fixture collection (render.cms.zzp3d: a name and a
// description, translated, and a price, shared), its own database and
// the_app.php as it was, put back afterwards. /zzp3d/zzp3d_item/<slug> is
// an item's page, rendered by the same view.
function p3d_site($fn) {
	global $root;
	$view = "$root/application/views/default/zzp3d.html";
	test_fixture($view);
	file_put_contents($view, '<!doctype html><!-- print.@lang.i18n.language --><html lang="en"><!-- /print.@lang.i18n.language --><head><title>Dishes</title></head><body>'
		."\n<!-- render.cms.zzp3d --><article><h2><!-- print.name -->Flat white<!-- /print.name --></h2>"
		.'<p class="d"><!-- print.description -->Milk and espresso<!-- /print.description --></p><p class="price"><!-- print.price -->14<!-- /print.price --></p></article><!-- /render.cms.zzp3d -->'
		."\n</body></html>");
	$config = APPBASE.'config/the_app.php';
	$kept = file_get_contents($config);
	$db = sys_get_temp_dir().'/raster-p3d-'.getmypid().'.sqlite';
	try {
		return $fn($db);
	} finally {
		file_put_contents($config, $kept);
		@unlink($view);
		array_map('unlink', glob("$db*") ?: array());
	}
}
// writes columns of an item straight into the fixture's database, as a
// translation saved some other way would
function p3d_write($db, $table, $id, $values) {
	$pdo = new PDO('sqlite:'.$db);
	foreach ($values as $column => $value) {
		$statement = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
		$statement->execute(array($value, $id));
	}
}
function p3d_columns($db, $table) {
	$pdo = new PDO('sqlite:'.$db);
	return $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
}
// the rows translation_status lists of one kind
function p3d_status($mcp, $kind) {
	$status = $mcp->call('translation_status', array('lang' => 'ro'));
	return array_values(array_filter($status['missing'], function ($row) use ($kind) { return $row['kind'] === $kind; }));
}

test('P3.D: off, the item tools answer as before and make no translation column', function () {
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) use ($db) {
			$made = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Flat white', 'price' => 14)));
			check(!isset($made['error']), json_encode($made));
			$id = $made['id'];
			$listed = $mcp->call('list_items', array('collection' => 'zzp3d'));
			same(array('collection', 'fields', 'total', 'items'), array_keys($listed));
			same('Flat white', $listed['items'][0]['name']);
			$item = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $id));
			same(array('Flat white', 14, 'flat-white'), array($item['name'], $item['price'], $item['slug']));
			same('Cortado', $mcp->call('update_item', array('collection' => 'zzp3d', 'id' => $id, 'fields' => array('name' => 'Cortado')))['name']);
			foreach (array('list_items' => array(), 'get_item' => array('id' => $id), 'create_item' => array('fields' => array('name' => 'x')), 'update_item' => array('id' => $id, 'fields' => array('name' => 'x'))) as $tool => $arguments) {
				$refused = $mcp->call($tool, array('collection' => 'zzp3d', 'lang' => 'ro') + $arguments);
				check(isset($refused['error']) && strpos($refused['error'], 'one language') !== false, "$tool: ".json_encode($refused));
			}
			check(isset($mcp->call('update_item', array('collection' => 'zzp3d', 'id' => $id, 'fields' => array('name__ro' => 'x')))['error']), 'a translation column is never a field');
			same('Cortado', $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $id))['name'], 'nothing written by a refusal');
		});
		check(!preg_grep('/__/', p3d_columns($db, 'zzp3ddata')), json_encode(p3d_columns($db, 'zzp3ddata')));
	});
});

test('P3.D: without lang, or with the default one, list_items and get_item send the SQL they always did', function () {
	p3d_site(function ($db) {
		cms_store::connect();
		try {
			cms_types::ensure('zzp3ddata', array('slug' => 'text', 'name' => 'text', 'description' => 'text', 'price' => 'int', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'));
			$item = cms_store::save_item('zzp3ddata', 0, array('name' => 'Flat white', 'price' => 14), array('name', 'description', 'price'));
			$settings = array(
				'off' => array('multilingual' => false, 'languages' => array()),
				'languages, switch off' => array('multilingual' => false, 'languages' => array('en', 'ro')),
				'on' => array('multilingual' => true, 'languages' => array('en', 'ro')),
			);
			foreach ($settings as $what => $setting) {
				with_settings($setting, function () use ($what, $item) {
					$mcp = new mcp();
					foreach (array('list_items' => array(), 'get_item' => array('id' => $item['id'])) as $tool => $arguments) {
						$arguments += array('collection' => 'zzp3d');
						$plain = $default = null;
						$sql = p2a_sql(function () use ($mcp, $tool, $arguments, &$plain) { $plain = $mcp->call_tool($tool, $arguments); });
						check(empty($plain['isError']), "$what, $tool: ".json_encode($plain));
						if ($what === 'off') continue;
						same($sql, p2a_sql(function () use ($mcp, $tool, $arguments, &$default) { $default = $mcp->call_tool($tool, $arguments + array('lang' => 'en')); }), "$what, $tool: the SQL");
						same($plain['content'], $default['content'], "$what, $tool: the answer");
					}
				});
			}
		} finally {
			R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS zzp3ddata');
			cms_store::forget();
		}
	});
});

test('P3.D: translation_status lists items, done once their column holds a translation', function () {
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) use ($db) {
			$flat = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Flat white', 'description' => 'Milk and espresso', 'price' => 14)));
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$tea = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Tea', 'price' => 9)));
			// set_languages made the first item's columns, create_item the second's
			$columns = p3d_columns($db, 'zzp3ddata');
			foreach (array('name__ro', 'description__ro', 'slug__ro') as $column) check(in_array($column, $columns, true), "$column: ".json_encode($columns));
			check(!in_array('price__ro', $columns, true), 'a price is shared');
			$rows = p3d_status($mcp, 'item');
			same(array(
				array('kind' => 'item', 'where' => "zzp3d/{$flat['id']}", 'field' => 'name', 'default_value' => 'Flat white', 'length' => 10, 'shared' => false),
				array('kind' => 'item', 'where' => "zzp3d/{$flat['id']}", 'field' => 'description', 'default_value' => 'Milk and espresso', 'length' => 17, 'shared' => false),
				array('kind' => 'item', 'where' => "zzp3d/{$tea['id']}", 'field' => 'name', 'default_value' => 'Tea', 'length' => 3, 'shared' => false),
			), $rows, 'each translated field with words, never the price or the slug; an empty description is nothing to translate');
			$before = $mcp->call('translation_status', array('lang' => 'ro'));
			same($before['total'], $mcp->call('describe', array('sections' => array('languages')))['languages']['missing']['ro'], 'describe counts them too');
			p3d_write($db, 'zzp3ddata', $flat['id'], array('name__ro' => 'Cafea cu lapte'));
			$after = $mcp->call('translation_status', array('lang' => 'ro'));
			same($before['total'] - 1, $after['total']);
			same($before['done'] + 1, $after['done']);
			same(array('description', 'name'), array_column(p3d_status($mcp, 'item'), 'field'));
		});
	});
});

test('P3.D: get_item and list_items with lang show the item in that language', function () {
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) use ($db) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$flat = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Flat white', 'description' => 'Milk and espresso', 'price' => 14)));
			$item = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'lang' => 'ro'));
			same(array('value' => '', 'default_value' => 'Flat white', 'translated' => false, 'shared' => false), $item['name']);
			same(array('value' => 14, 'default_value' => 14, 'translated' => false, 'shared' => true), $item['price']);
			same(array($flat['id'], 'ro', 'flat-white', true), array($item['id'], $item['lang'], $item['slug'], $item['enabled']), 'id, slug, enabled stay plain');
			check(!preg_grep('/__/', array_keys($item)), 'no translation columns: '.json_encode(array_keys($item)));
			p3d_write($db, 'zzp3ddata', $flat['id'], array('name__ro' => 'Cafea cu lapte', 'slug__ro' => 'cafea-cu-lapte'));
			$item = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'lang' => 'ro'));
			same(array('value' => 'Cafea cu lapte', 'default_value' => 'Flat white', 'translated' => true, 'shared' => false), $item['name']);
			same('cafea-cu-lapte', $item['slug'], 'the slug in that language');
			same('flat-white', $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'lang' => 'en'))['slug'], 'the default language is as without lang');
			$listed = $mcp->call('list_items', array('collection' => 'zzp3d', 'lang' => 'ro'));
			same(array('collection', 'lang', 'fields', 'total', 'items'), array_keys($listed));
			$row = $listed['items'][0];
			same(array('Cafea cu lapte', 'Milk and espresso', 14, 'cafea-cu-lapte', array('description')), array($row['name'], $row['description'], $row['price'], $row['slug'], $row['untranslated']), 'translated, else the default; with what it lacks');
			check(!preg_grep('/__/', array_keys($row)), json_encode(array_keys($row)));
			same('Flat white', $mcp->call('list_items', array('collection' => 'zzp3d'))['items'][0]['name'], 'without lang, as before');
			check(isset($mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'lang' => 'de'))['error']), 'a language the site lacks');
		});
	});
});

test('P3.D: create_item takes no translation; update_item with lang refuses shared fields', function () {
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$refused = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Cafea'), 'lang' => 'ro'));
			same('Create the item without lang, in the default language (en), then translate it with update_item and lang', isset($refused['error']) ? $refused['error'] : json_encode($refused));
			same(0, $mcp->call('list_items', array('collection' => 'zzp3d'))['total'], 'nothing made');
			$flat = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Flat white', 'price' => 14), 'lang' => 'en'));
			check(!isset($flat['error']), 'the default language is as none: '.json_encode($flat));
			foreach (array('price' => 12, 'enabled' => false) as $field => $value) {
				$refused = $mcp->call('update_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'fields' => array('name' => 'Cafea', $field => $value), 'lang' => 'ro'));
				same("'$field' is shared by every language; update it without lang", isset($refused['error']) ? $refused['error'] : json_encode($refused));
			}
			check(isset($mcp->call('update_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'fields' => array('name__ro' => 'Cafea'), 'lang' => 'ro'))['error']), 'a column is never a field');
			$item = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id']));
			same(array('Flat white', 14), array($item['name'], $item['price']), 'nothing written');
			same('', $item['name__ro'], 'no translation either');
		});
	});
});

test('P3.D: tools/list grows by less than 100 characters with lang on the item tools (#161)', function () {
	// tools/list over stdio once P2 and its review fixes are in (ml/p2
	// 0f00c7b, what P3 builds on): 11805 characters; at the P3 kickoff
	// (43aa961), before those fixes, it was 11739
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$tools = $mcp->message('tools/list')['result']['tools'];
			foreach ($tools as $tool) {
				if (in_array($tool['name'], array('list_items', 'get_item', 'create_item', 'update_item'), true)) check(isset($tool['inputSchema']['properties']['lang']), $tool['name']);
			}
			// less P4.C's "record, email" in translation_status (15), P4's
			$size = strlen(json_encode(array('tools' => $tools), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) - 15;
			check($size - 11805 < 100, "tools/list is $size characters, ".($size - 11805).' more than P2 left it');
		});
	});
});

test('P3.D: an agent translates a collection over MCP and /ro/ shows its items (needs P3.A, P3.B)', function () {
	p3d_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$flat = $mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Flat white', 'description' => 'Milk and espresso', 'price' => 14)));
			$mcp->call('create_item', array('collection' => 'zzp3d', 'fields' => array('name' => 'Tea', 'description' => 'Black', 'price' => 9)));
			// what translation_status lists, translated one item at a time
			$words = array('Flat white' => 'Cafea cu lapte', 'Milk and espresso' => 'Lapte și espresso', 'Tea' => 'Ceai', 'Black' => 'Negru');
			$items = array();
			foreach (p3d_status($mcp, 'item') as $row) {
				list(, $id) = explode('/', $row['where']);
				$items[$id][$row['field']] = $words[$row['default_value']];
			}
			same(2, count($items), json_encode($items));
			foreach ($items as $id => $fields) {
				$saved = $mcp->call('update_item', array('collection' => 'zzp3d', 'id' => (int)$id, 'fields' => $fields, 'lang' => 'ro'));
				check(!isset($saved['error']), json_encode($saved));
			}
			// nothing left for items, and the English is as it was
			same(array(), p3d_status($mcp, 'item'), 'translation_status: 0 missing for items');
			$item = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'lang' => 'ro'));
			same(array('value' => 'Cafea cu lapte', 'default_value' => 'Flat white', 'translated' => true, 'shared' => false), $item['name']);
			same('cafea-cu-lapte', $item['slug'], 'a Romanian slug, made from the Romanian name');
			$english = $mcp->call('get_item', array('collection' => 'zzp3d', 'id' => $flat['id']));
			same(array('Flat white', 'flat-white'), array($english['name'], $english['slug']));
			same(array(), $mcp->call('list_items', array('collection' => 'zzp3d', 'lang' => 'ro'))['items'][0]['untranslated']);
			// the slug can be set too, in that language only
			same('cafea-cu-lapte', $mcp->call('update_item', array('collection' => 'zzp3d', 'id' => $flat['id'], 'fields' => array('slug' => 'cafea-cu-lapte'), 'lang' => 'ro'))['slug']);
			// /ro/ lists and shows the items in Romanian, at their Romanian address
			$list = $mcp->call('render_url', array('url' => '/ro/zzp3d'));
			same(200, $list['status']);
			check(strpos($list['html'], '<h2>Cafea cu lapte</h2>') !== false && strpos($list['html'], '<h2>Ceai</h2>') !== false, 'the Romanian names');
			check(strpos($list['html'], '>14<') !== false, 'the shared price');
			$page = $mcp->call('render_url', array('url' => '/ro/zzp3d/zzp3d_item/cafea-cu-lapte'));
			same(200, $page['status'], json_encode($page));
			check(strpos($page['html'], '<h2>Cafea cu lapte</h2>') !== false && strpos($page['html'], 'Lapte și espresso') !== false, 'the item in Romanian');
			check(strpos($page['html'], '<h2>Ceai</h2>') === false, 'that item only');
			$en = $mcp->call('render_url', array('url' => '/zzp3d/zzp3d_item/flat-white'));
			check(strpos($en['html'], '<h2>Flat white</h2>') !== false, 'English stays at its own address');
		});
	});
});

test('P3.D: translation_status lists records whose type declares translate (needs P3.A)', function () {
	temp_model(APPBASE.'models/zzp3drec', array('zzp3drec.php' => "<?php\nclass zzp3drec\n{\n\tstatic function types() {\n\t\treturn array('zzp3drec' => array('fields' => array('title' => '', 'email' => ''), 'translate' => array('title')));\n\t}\n}\n"));
	try {
		p3d_site(function ($db) {
			p2c_session(array('RASTER_DB' => $db), function ($mcp) {
				$mcp->call('set_languages', array('languages' => array('en', 'ro')));
				$made = $mcp->call('create_item', array('collection' => 'zzp3drec', 'fields' => array('title' => 'Opening hours', 'email' => 'ana@example.com')));
				check(!isset($made['error']), json_encode($made));
				same(array(array('kind' => 'record', 'where' => "zzp3drec/{$made['id']}", 'field' => 'title', 'default_value' => 'Opening hours', 'length' => 13, 'shared' => false)), p3d_status($mcp, 'record'), 'the title, never the email');
				same(array('value' => 'ana@example.com', 'default_value' => 'ana@example.com', 'translated' => false, 'shared' => true), $mcp->call('get_item', array('collection' => 'zzp3drec', 'id' => $made['id'], 'lang' => 'ro'))['email']);
				$saved = $mcp->call('update_item', array('collection' => 'zzp3drec', 'id' => $made['id'], 'fields' => array('title' => 'Program'), 'lang' => 'ro'));
				check(!isset($saved['error']), json_encode($saved));
				same(array(), p3d_status($mcp, 'record'));
			});
		});
	} finally {
		remove_dir(APPBASE.'models/zzp3drec');
	}
});

// ## P3.C: the sitemap (#126) and items in every language

// the P3.C fixture views in the starter's theme for the length of $fn, then
// taken away with the tables they made
function p3c_views($views, $fn) {
	global $root;
	$theme = "$root/application/views/default";
	foreach ($views as $name => $html) {
		test_fixture("$theme/$name");
		file_put_contents("$theme/$name", $html);
	}
	try {
		return $fn($theme);
	} finally {
		foreach (array_keys($views) as $name) @unlink("$theme/$name");
		cms_store::connect();
		foreach (array('zzp3cfaqdata', 'zzp3cmenudata', 'zzp3cpagepage') as $table) R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS `$table`");
		cms_store::forget();
	}
}
// a FAQ that only zzp3cfaq.html shows (no item view), a menu with an item
// view, and a page with a field
function p3c_fixtures() {
	return array(
		'zzp3cfaq.html' => '<!doctype html><html><head><title>FAQ</title></head><body><!-- render.cms.zzp3cfaq --><h2><!-- print.title -->Is there wifi?<!-- /print.title --></h2><!-- /render.cms.zzp3cfaq --></body></html>',
		'zzp3cmenu_item.html' => '<!doctype html><html><head><title>Menu</title></head><body><!-- render.cms.zzp3cmenu --><h1><!-- print.title -->Flat white<!-- /print.title --></h1><!-- /render.cms.zzp3cmenu --></body></html>',
		'zzp3cpage.html' => '<!doctype html><html><head><title>Page</title></head><body><h1><!-- print.cms.zzp3c_headline -->Hello<!-- /print.cms.zzp3c_headline --></h1></body></html>',
	);
}
// the FAQ's table with $rows (slug => columns), all in one transaction
function p3c_faq($rows, $columns = array()) {
	cms_store::connect();
	cms_types::ensure('zzp3cfaqdata', array('title' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime') + $columns);
	R::begin();
	try {
		foreach ($rows as $slug => $values) {
			$values += array('title' => $slug, 'slug' => $slug, 'enabled' => 1, 'published_at' => null, 'updated_at' => '2026-01-02 10:00:00');
			R::exec('INSERT INTO zzp3cfaqdata (`'.implode('`, `', array_keys($values)).'`) VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));
		}
		R::commit();
	} catch (Exception $e) {
		R::rollback();
		throw $e;
	}
	cms_store::forget();
}
// the sitemap's rows, and the SQL it sent
function p3c_pages() {
	$rows = null;
	$sql = p2a_sql(function () use (&$rows) { $rows = (new feed())->pages(); });
	return array($rows, $sql);
}
function p3c_row($rows, $url) {
	foreach ($rows as $row) if ($row['url'] === $url) return $row;
	return null;
}
// a multilingual site at http://cafe.test/, in its default language (P1.C's)
function p3c_on($settings = array()) {
	return p1c_on($settings + array('url_language' => '', 'link_uri' => 'http://cafe.test/', 'uri_string' => '/sitemap.xml'));
}

test('P3.C: the sitemap lists every published item of a collection with no item view, all of them, in one query (#126)', function () {
	p3c_views(p3c_fixtures(), function () {
		$rows = array();
		for ($i = 1; $i <= 1201; $i++) $rows["question-$i"] = array();
		$rows['a-draft'] = array('enabled' => 0);
		$rows['coming-later'] = array('published_at' => date('Y-m-d H:i:s', time() + 86400));
		$rows['published-after-its-save'] = array('published_at' => '2026-03-04 09:00:00');
		$rows['saved-after-publishing'] = array('published_at' => '2025-12-01 09:00:00', 'updated_at' => '2026-02-03 11:00:00');
		p3c_faq($rows);
		list($pages, $sql) = p3c_pages();
		$urls = array_column($pages, 'url');
		$link = rtrim(config::get('link_uri'), '/');
		same(1203, count(preg_grep('#/zzp3cfaq/zzp3cfaq_item/#', $urls)), 'every published item, past 1,000');
		check(in_array("$link/zzp3cfaq/zzp3cfaq_item/question-1", $urls) && in_array("$link/zzp3cfaq/zzp3cfaq_item/question-1201", $urls), 'the oldest and the newest');
		same(array(), array_values(preg_grep('#a-draft|coming-later#', $urls)), 'no draft, nothing scheduled');
		check(in_array("$link/zzp3cfaq", $urls), 'the FAQ page itself');
		same(array(), array_values(preg_grep('#zzp3cmenu_item$#', $urls)), 'an item view is no page');
		// the date: the later of its last save and its publishing
		same('2026-01-02', p3c_row($pages, "$link/zzp3cfaq/zzp3cfaq_item/question-7")['updated']);
		same('2026-03-04', p3c_row($pages, "$link/zzp3cfaq/zzp3cfaq_item/published-after-its-save")['updated']);
		same('2026-02-03', p3c_row($pages, "$link/zzp3cfaq/zzp3cfaq_item/saved-after-publishing")['updated']);
		// one query reads the items: id, slug and the dates, no item loaded whole
		$reads = array_values(preg_grep('/^SELECT\b.*`zzp3cfaqdata`/i', $sql));
		same(1, count($reads), json_encode($reads));
		check(strpos($reads[0], 'SELECT `id`, `slug`, `published_at`, `updated_at` FROM `zzp3cfaqdata` WHERE') === 0, $reads[0]);
	});
});

test('P3.C: a page\'s lastmod is its last save in the CMS, else its view\'s date (#126)', function () {
	p3c_views(p3c_fixtures(), function ($theme) {
		$link = rtrim(config::get('link_uri'), '/');
		touch("$theme/zzp3cpage.html", strtotime('2025-01-02 12:00:00'));
		clearstatcache();
		same('2025-01-02', p3c_row((new feed())->pages(), "$link/zzp3cpage")['updated'], 'never saved: the file\'s date');
		cms_store::connect();
		cms_store::update_page(cms::page_type('/zzp3cpage'), '/zzp3cpage', array('zzp3c_headline' => 'Moved'), array('zzp3c_headline'));
		same(date('Y-m-d'), p3c_row((new feed())->pages(), "$link/zzp3cpage")['updated'], 'saved: its revision\'s date');
		// a deploy that touches the file doesn't move it
		touch("$theme/zzp3cpage.html", strtotime('2030-01-02 12:00:00'));
		clearstatcache();
		same(date('Y-m-d'), p3c_row((new feed())->pages(), "$link/zzp3cpage")['updated'], 'the file\'s date no longer counts');
	});
});

test('P3.C: off, the rows are url and updated, no slug column is read and the sitemap prints what 2.2.45\'s did', function () use ($base) {
	$old = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
		."  <!-- render.feed.pages -->\n  <url><loc><!-- print.url /--></loc><lastmod><!-- print.updated /--></lastmod></url>\n  <!-- /render.feed.pages -->\n</urlset>\n";
	p3c_views(p3c_fixtures() + array('zzp3cold.xml' => $old), function () use ($base) {
		p3c_faq(array('flat-white' => array('slug__ro' => 'cafea-cu-lapte')), array('slug__ro' => 'text'));
		foreach (array(array('multilingual' => false, 'languages' => array()), array('multilingual' => false, 'languages' => array('en', 'ro')), array('multilingual' => true, 'languages' => array('en'))) as $settings) {
			with_settings($settings, function () use ($settings) {
				list($rows, $sql) = p3c_pages();
				$keys = array_unique(array_map(function ($row) { ksort($row); return implode(',', array_keys($row)); }, $rows));
				same(array('updated,url'), array_values($keys), json_encode($settings));
				same(array(), array_values(preg_grep('/slug__ro/', $sql)), 'no slug column read: '.json_encode($settings));
				check(in_array(rtrim(config::get('link_uri'), '/').'/zzp3cfaq/zzp3cfaq_item/flat-white', array_column($rows, 'url')), 'at its own slug');
			});
		}
		// the bundled view prints, byte for byte, what the 2.2.45 view prints
		list($status, $now) = http('GET', "$base/sitemap.xml");
		same(200, $status);
		list(, $then) = http('GET', "$base/zzp3cold.xml");
		same($then, $now);
		check(strpos($now, 'zzp3cfaq/zzp3cfaq_item/flat-white') !== false, $now);
		check(strpos($now, 'xhtml') === false, 'no alternates off');
	});
});

test('P3.C: multilingual, every language\'s address is a row with alternates and x-default, and the sitemap prints them as hreflang', function () use ($base) {
	p3c_views(p3c_fixtures(), function () use ($base) {
		p3c_faq(array('is-there-wifi' => array()));
		with_settings(p3c_on(), function () {
			list($rows, $sql) = p3c_pages();
			$alternates = array(
				array('lang' => 'en', 'url' => 'http://cafe.test/zzp3cpage'),
				array('lang' => 'ro', 'url' => 'http://cafe.test/ro/zzp3cpage'),
				array('lang' => 'x-default', 'url' => 'http://cafe.test/zzp3cpage'),
			);
			foreach (array('http://cafe.test/zzp3cpage', 'http://cafe.test/ro/zzp3cpage') as $url) {
				$row = p3c_row($rows, $url);
				check($row !== null, "$url is listed");
				same($alternates, $row['alternates'], $url);
			}
			// the home page is /ro/ in Romanian
			check(p3c_row($rows, 'http://cafe.test/ro/') !== null, 'the Romanian home page');
			// an item with no Romanian slug is there at its own
			$row = p3c_row($rows, 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/is-there-wifi');
			check($row !== null, json_encode(array_column($rows, 'url')));
			same(array('en', 'ro', 'x-default'), array_column($row['alternates'], 'lang'));
			same(null, p3c_row($rows, 'x-default'), 'x-default is no row of its own');
			same(count(array_unique(array_column($rows, 'url'))), count($rows), 'each address once');
		});
		p1b_site("config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);", function () use ($base) {
			list($status, $body) = http('GET', "$base/sitemap.xml");
			same(200, $status);
			$doc = new DOMDocument();
			check(@$doc->loadXML($body), 'well-formed: '.$body);
			check(strpos($body, "<loc>$base/ro/zzp3cpage</loc>") !== false, $body);
			check(strpos($body, '<xhtml:link xmlns:xhtml="http://www.w3.org/1999/xhtml" rel="alternate" hreflang="ro" href="'.$base.'/ro/zzp3cfaq/zzp3cfaq_item/is-there-wifi"/>') !== false, $body);
			check(strpos($body, 'hreflang="x-default" href="'.$base.'/zzp3cpage"') !== false, $body);
			// read as a sitemap reader would: one url per language, three links each
			$xpath = new DOMXPath($doc);
			$xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
			$xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
			same(3, $xpath->query("//s:url[s:loc='$base/ro/zzp3cpage']/x:link")->length);
		});
	});
});

test('P3.C: multilingual, an item is listed at its slug in every language (needs P3.B\'s item_alternates)', function () {
	p3c_views(p3c_fixtures(), function () {
		p3c_faq(array('flat-white' => array('slug__ro' => 'cafea-cu-lapte'), 'is-there-wifi' => array()), array('slug__ro' => 'text'));
		with_settings(p3c_on(), function () {
			list($rows, $sql) = p3c_pages();
			check((bool)preg_grep('/^SELECT `id`, `slug`, `published_at`, `updated_at`, `slug__ro` FROM `zzp3cfaqdata`/', $sql), 'the Romanian slug is read in the same query: '.json_encode($sql));
			$row = p3c_row($rows, 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/cafea-cu-lapte');
			check($row !== null, json_encode(array_column($rows, 'url')));
			same(array(
				array('lang' => 'en', 'url' => 'http://cafe.test/zzp3cfaq/zzp3cfaq_item/flat-white'),
				array('lang' => 'ro', 'url' => 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/cafea-cu-lapte'),
				array('lang' => 'x-default', 'url' => 'http://cafe.test/zzp3cfaq/zzp3cfaq_item/flat-white'),
			), $row['alternates']);
			same(null, p3c_row($rows, 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/flat-white'), 'not at the English slug in Romanian');
			check(p3c_row($rows, 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/is-there-wifi') !== null, 'an item with no Romanian slug at its own');
		});
	});
});

test('P3.C: on an item page the switcher and hreflang give the item\'s slug in each language (needs P3.B\'s path_in)', function () {
	p3c_views(p3c_fixtures(), function () {
		p3c_faq(array('flat-white' => array('slug__ro' => 'cafea-cu-lapte')), array('slug__ro' => 'text'));
		$english = p3c_on(array('uri_string' => '/zzp3cfaq/zzp3cfaq_item/flat-white'));
		$romanian = p1c_on(array('uri_string' => '/zzp3cfaq/zzp3cfaq_item/cafea-cu-lapte'));
		$want = array('en' => 'http://cafe.test/zzp3cfaq/zzp3cfaq_item/flat-white', 'ro' => 'http://cafe.test/ro/zzp3cfaq/zzp3cfaq_item/cafea-cu-lapte');
		foreach (array('en' => $english, 'ro' => $romanian) as $page => $settings) {
			with_settings($settings, function () use ($page, $want) {
				same($want, array_column(i18n::alternates(), 'url', 'lang'), "alternates on the $page page");
				same($want, array_column((new i18n())->languages(), 'url', 'code'), "the switcher on the $page page");
				$links = template::language_links('', 200);
				foreach ($want as $lang => $url) check(strpos($links, "hreflang=\"$lang\" href=\"$url\"") !== false, "hreflang $lang on the $page page: $links");
				check(strpos($links, "hreflang=\"x-default\" href=\"{$want['en']}\"") !== false, $links);
				check(strpos($links, "<link rel=\"canonical\" href=\"{$want[$page]}\">") !== false, $links);
			});
		}
	});
});


// ## P3 integration: copies, undo and slugs keep every language apart

test('P3 integration: on /ro/ Duplicate copies the stored item in every language, undo brings back its translations, and a missing slug is made from the default language', function () {
	$type = 'zzp3imenudata';
	try {
		p2b_site(function ($dir) use ($type) {
			file_put_contents("$dir/application/views/default/zzp3imenu.html", '<!doctype html><html><head><title>Menu</title></head><body><ul>'
				.'<!-- render.cms.zzp3i_menu --><li><b><!-- print.name -->Zz seed<!-- /print.name --></b></li><!-- /render.cms.zzp3i_menu --></ul></body></html>');
			p2b_server($dir, function ($base) use ($type) {
				same(200, http('GET', "$base/zzp3imenu")[0]);
				cms_store::connect();
				cms_store::forget();
				check(isset(cms_store::columns($type)['name__ro']), 'the Romanian column');
				R::exec("DELETE FROM $type");
				$id = with_settings(p2a_on(), function () use ($type) {
					$item = cms_store::save_item($type, 0, array('name' => 'Apple'), array('name'));
					cms_store::save_item($type, $item['id'], array('name' => 'Măr'), array('name'), 'editor', null, 'ro');
					return $item['id'];
				});
				$editor = p2b_login($base);
				list($config) = p2b_config("$base/ro/zzp3imenu", $editor);
				$post = function ($endpoint, $fields) use ($base, $editor, $config) {
					list($status, $body) = form_post("$base/ro/api/cms/$endpoint", $fields + array('csrf' => $config['csrf']), array("Cookie: $editor"));
					return array($status, json_decode($body, true), $body);
				};
				$stored = function ($id) use ($type) { cms_store::forget(); return R::getRow("SELECT name, name__ro, slug FROM $type WHERE id = ?", array($id)); };
				// Duplicate: the copy's English is the English, its Romanian the Romanian
				list($status, $copy, $body) = $post('editor_save_item', array('collection' => 'zzp3i_menu', 'id' => 0, 'copy' => $id, 'lang' => 'ro'));
				same(200, $status, $body);
				same('Măr', $copy['name'], 'answered as /ro/ shows it');
				same(array('name' => 'Apple', 'name__ro' => 'Măr'), array_slice($stored($copy['id']), 0, 2));
				same('apple-2', $stored($copy['id'])['slug'], 'its slug from the English');
				list($status) = $post('editor_save_item', array('collection' => 'zzp3i_menu', 'id' => 0, 'copy' => 999999, 'lang' => 'ro'));
				same(404, $status, 'a copy of no item');
				// Delete, then undo: the item as delete_item gave it, every language
				list($status, $deleted, $body) = $post('editor_delete_item', array('collection' => 'zzp3i_menu', 'id' => $copy['id']));
				same(200, $status, $body);
				$fields = $deleted['item'];
				foreach (array('id', 'updated_at', 'created_at', 'owner') as $k) unset($fields[$k]);
				check(array_key_exists('name__ro', $fields) && array_key_exists('saved_lang', $fields), json_encode($fields));
				list($status, $back, $body) = $post('editor_save_item', array('collection' => 'zzp3i_menu', 'id' => 0, 'fields' => $fields, 'lang' => 'ro'));
				same(200, $status, $body);
				same(array('name' => 'Apple', 'name__ro' => 'Măr'), array_slice($stored($back['id']), 0, 2), 'undo keeps both languages');
				// an existing item still takes no language's column as a field
				list($status) = $post('editor_save_item', array('collection' => 'zzp3i_menu', 'id' => $back['id'], 'fields' => array('name__ro' => 'Wrong'), 'lang' => 'ro'));
				same(400, $status);
				// an item without a slug, shown on /ro/, gets the English one
				R::exec("UPDATE $type SET slug = NULL WHERE id = ?", array($id));
				check(strpos(http('GET', "$base/ro/zzp3imenu")[1], '<b>Măr</b>') !== false, 'shown in Romanian');
				same('apple', $stored($id)['slug']);
			});
		});
	} finally {
		cms_store::connect();
		R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $type");
		cms_store::forget();
	}
});

// ## P3 review: one address one item, the slug per language, visibility

test('P3 review: an English slug that is another item\'s Romanian address gets a Romanian slug of its own; switcher, cache, history', function () {
	p3b_table();
	try {
		$a = p3b_save(0, array('name' => 'Milk coffee', 'name__ro' => 'Latte'));
		same(array('milk-coffee', 'latte'), array($a['slug'], $a['slug__ro']));
		$b = p3b_save(0, array('name' => 'Latte'));
		same(array('latte', 'latte-2'), array($b['slug'], $b['slug__ro']), 'one /ro/ address, one item');
		$c = p3b_save(0, array('name' => 'Espresso'));
		same(null, isset($c['slug__ro']) ? $c['slug__ro'] : null, 'a slug no one has in Romanian stays the Romanian address');
		check(p3b_indexed('slughistory', 'slughistory_type_slug'), 'slughistory is indexed by type and slug');
		with_settings(p2a_on(), function () use ($a, $b) {
			same((string)$a['id'], (string)cms_store::slug_item('zzp3bmenudata', 'latte', 'ro')['id']);
			same((string)$b['id'], (string)cms_store::slug_item('zzp3bmenudata', 'latte-2', 'ro')['id']);
			// asked again, from what the request already knows
			same(array(), p2a_sql(function () { cms_store::slug_item('zzp3bmenudata', 'latte', 'ro'); }));
			same('zzp3bmenu/zzp3bmenu_item/latte-2', cms::path_in('zzp3bmenu/zzp3bmenu_item/latte', 'ro', 'en'));
		});
		// a draft's Romanian address is not in a visitor's switcher
		p3b_save($b['id'], array('enabled' => 0));
		with_settings(p2a_on(), function () {
			same('zzp3bmenu/zzp3bmenu_item/latte', cms::path_in('zzp3bmenu/zzp3bmenu_item/latte', 'ro', 'en'));
		});
		// an old slug two items had: the one still there
		$m = p3b_save(0, array('name' => 'Mocha'));
		p3b_save($m['id'], array('slug' => 'mocha-classic'));
		$n = p3b_save(0, array('name' => 'Mocha'));
		same('mocha', $n['slug']);
		p3b_save($n['id'], array('slug' => 'mocha-new'));
		with_settings(p2a_on(), function () use ($n) { cms_store::delete_item('zzp3bmenudata', $n['id']); });
		with_settings(p2a_on(), function () use ($m) {
			same(array('id' => (int)$m['id'], 'slug' => 'mocha-classic'), cms_store::resolve_slug('zzp3bmenudata', 'mocha', 'en'));
		});
	} finally {
		p3b_drop();
	}
});

test('P3 review: a new record on a frozen database leaves a declared field without its column to the model', function () {
	p3a_model(function () {
		cms_store::connect();
		cms_types::ensure('zzp3abookdata', array('name' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime', 'created_at' => 'datetime'));
		$was = database::$frozen;
		database::$frozen = true;
		R::freeze(true);
		try {
			$saved = cms_store::save_item('zzp3abookdata', 0, array('name' => 'Ana'), array(), 'model');
			same('Ana', $saved['name']);
			check(!array_key_exists('note', cms_store::columns('zzp3abookdata')), 'no column made');
		} finally {
			database::$frozen = $was;
			R::freeze($was);
		}
		// fluid, the column comes with the default, as always
		$saved = cms_store::save_item('zzp3abookdata', 0, array('name' => 'Bo'), array(), 'model');
		same('', $saved['note']);
	});
});

test('P3 review: the editor on /ro/ writes the Romanian address, never the English one, and a protected item never redirects for a visitor', function () {
	$type = 'zzp3rmenudata';
	try {
		p2b_site(function ($dir) use ($type) {
			file_put_contents("$dir/application/views/default/zzp3rmenu.html", '<!doctype html><html><head><title>Menu</title></head><body><ul>'
				.'<!-- render.cms.zzp3rmenu --><li><b><!-- print.name -->Zz seed<!-- /print.name --></b></li><!-- /render.cms.zzp3rmenu --></ul></body></html>');
			p2b_server($dir, function ($base) use ($type) {
				same(200, http('GET', "$base/zzp3rmenu")[0]);
				cms_store::connect();
				cms_store::forget();
				// development gives a multilingual list its slug per language when it is first shown (P5.A)
				check(isset(cms_store::columns($type)['slug__ro']), 'the list has its Romanian slugs');
				R::exec("DELETE FROM $type");
				$id = with_settings(p2a_on(), function () use ($type) { return cms_store::save_item($type, 0, array('name' => 'Apple'), array('name'))['id']; });
				$editor = p2b_login($base);
				list($config) = p2b_config("$base/ro/zzp3rmenu", $editor);
				list($status, $json, $body) = (function () use ($base, $editor, $config, $id) {
					list($status, $body) = form_post("$base/ro/api/cms/editor_save_item", array('collection' => 'zzp3rmenu', 'id' => $id, 'fields' => array('slug' => 'mar'), 'lang' => 'ro', 'csrf' => $config['csrf']), array("Cookie: $editor"));
					return array($status, json_decode($body, true), $body);
				})();
				same(200, $status, $body);
				cms_store::forget();
				same(array('apple', 'mar'), array_values(R::getRow("SELECT slug, slug__ro FROM $type WHERE id = ?", array($id))));
				check(cms_types::index_slug($type, 'slug__ro') === false, 'made with its index');
				// the old English address on /ro/ answers 301 to the Romanian one
				list($status, , $headers) = http('GET', "$base/ro/zzp3rmenu/zzp3rmenu_item/apple");
				same(301, $status);
				check(in_array("Location: $base/ro/zzp3rmenu/zzp3rmenu_item/mar", $headers, true), json_encode($headers));
			});
			// the same list behind a role: a visitor is sent to log in, not to the Romanian slug
			$events = "$dir/application/config/the_events.php";
			$kept = file_get_contents($events);
			file_put_contents($events, $kept."\nconfig::set('protected')->to(array('zzp3rmenu' => 'editor'));\n");
			try {
				p2b_server($dir, function ($base) {
					list($status, , $headers) = http('GET', "$base/ro/zzp3rmenu/zzp3rmenu_item/apple");
					check($status !== 301 && !preg_grep('#zzp3rmenu_item/mar#', $headers), "$status ".json_encode($headers));
					$editor = p2b_login($base);
					same(301, http('GET', "$base/ro/zzp3rmenu/zzp3rmenu_item/apple", null, array("Cookie: $editor"))[0], 'the editor is redirected');
				});
			} finally {
				file_put_contents($events, $kept);
			}
		});
	} finally {
		cms_store::connect();
		R::getDatabaseAdapter()->exec("DROP TABLE IF EXISTS $type");
		R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS slughistory');
		cms_store::forget();
	}
});

// ## P4 kickoff: the contracts the P4 packages code against

// the settings a P4 off test runs under: every way multilingual is off
function p4_off_settings() {
	return array(
		'off' => array('multilingual' => false, 'languages' => array()),
		'languages, switch off' => array('multilingual' => false, 'languages' => array('en', 'ro')),
		'one language' => array('multilingual' => true, 'languages' => array('en')),
	);
}
// a sent mail with what changes every time (date, id, boundary) taken out
function p4_mail_shape($raw) {
	$raw = preg_replace('/^(Date|Message-ID): .*$/m', '$1: x', $raw);
	$raw = preg_replace('/raster-[0-9a-f]{16}/', 'raster-x', $raw);
	return $raw;
}

test('P4 kickoff: off, send_view and render_view with a language send and render what they always did', function () use ($root) {
	$dir = "$root/application/views/default/_email";
	test_fixture("$dir/zzp4kick.html");
	file_put_contents("$dir/zzp4kick.html", '<!doctype html><html><head><title>Your table</title></head><body><p>Hi <!-- print.self.name -->there<!-- /print.self.name --></p>'
		.'<a href="/zzp4kick_menu">Menu</a> <a href="https://example.com/x">Out</a></body></html>');
	try {
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what) {
				$html = controller::render_view('_email/zzp4kick', array('name' => 'Ana'));
				foreach (array('en', 'ro', '') as $lang) {
					same($html, controller::render_view('_email/zzp4kick', array('name' => 'Ana'), 'html', $lang), "$what: render_view in '$lang'");
				}
				same(true, mail::send_view('_email/zzp4kick', 'ana@example.test', array('name' => 'Ana')));
				$plain = p4_mail_shape(last_mail()['raw']);
				foreach (array('en', 'ro') as $lang) {
					same(true, mail::send_view('_email/zzp4kick', 'ana@example.test', array('name' => 'Ana'), array(), $lang));
					same($plain, p4_mail_shape(last_mail()['raw']), "$what: send_view in $lang");
				}
				// in_language just runs the function: the request's language and links stay
				$current = i18n::current();
				$link = config::get('link_uri');
				same(array($current, $link), mail::in_language('ro', function () { return array(i18n::current(), config::get('link_uri')); }), "$what: in_language");
				same('x', mail::in_language(null, function () { return 'x'; }));
			});
		}
		// on, a mail with no language is the request's, as before
		with_settings(array('multilingual' => true, 'languages' => array('en', 'ro')), function () {
			same(array('ro', 'en'), array(mail::in_language('ro', function () { return i18n::current(); }), i18n::current()), 'on: in_language runs in the language, then restores it');
			same(i18n::current(), mail::in_language(null, function () { return i18n::current(); }));
		});
	} finally {
		@unlink("$dir/zzp4kick.html");
	}
});

test('P4 kickoff: i18n::of names the language a row is for, the default for rows without one', function () {
	cms_store::connect();
	with_settings(array('multilingual' => true, 'languages' => array('en', 'ro', 'pt-br')), function () {
		same('ro', i18n::of(array('lang' => 'ro')));
		same('ro', i18n::of(array('lang' => 'RO')));
		same('pt-br', i18n::of(array('lang' => 'pt-br')));
		same('en', i18n::of(array('lang' => '')), 'a row from before the column');
		same('en', i18n::of(array('name' => 'Ana')), 'no column');
		same('en', i18n::of(array('lang' => 'fr')), 'a language the site dropped');
		same('en', i18n::of(null));
		$bean = R::dispense('zzp4kick');
		$bean->lang = 'ro';
		same('ro', i18n::of($bean), 'a bean');
		same('en', i18n::of(R::dispense('zzp4kick')), 'a bean without one');
	});
	with_settings(array('multilingual' => false, 'languages' => array()), function () {
		same('', i18n::of(array('lang' => 'ro')), 'no languages: none');
	});
});

test('P4 kickoff: the lang columns are declared on a multilingual site only, so off schema asks nothing new (D13)', function () use ($root) {
	if (!class_exists('order')) require_once "$root/shop/models/order/order.php";
	$records = array('booking' => array('booking', 'booking'), 'rsvp' => array('rsvp', 'rsvp'), 'contact' => array('contact', 'contactmessage'), 'order' => array('order', 'order'));
	$declared = function () use ($records) {
		$found = array(
			'user' => array_key_exists('lang', authentication::schema()['user']),
			'subscriber' => array_key_exists('lang', newsletter::schema()['subscriber']),
		);
		foreach ($records as list($class, $type)) {
			$types = call_user_func(array($class, 'types'));
			$found[$type] = array_key_exists('lang', $types[$type]['fields']);
			// the model writes it; staff and agents see it, and it is in the events' payloads
			if ($found[$type]) check(in_array('lang', $types[$type]['readonly'], true), "$type: lang is readonly");
		}
		return $found;
	};
	$none = array('user' => false, 'subscriber' => false, 'booking' => false, 'rsvp' => false, 'contactmessage' => false, 'order' => false);
	foreach (p4_off_settings() as $what => $setting) {
		same($none, with_settings($setting, $declared), $what);
	}
	same(array_map(function () { return true; }, $none), with_settings(array('multilingual' => true, 'languages' => array('en', 'ro')), $declared), 'on');
	// off, schema status of this database names no lang column as missing
	$status = with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () {
		cms_records::forget();
		try {
			return (new raster_schema())->status();
		} finally {
			cms_records::forget();
		}
	});
	foreach (array_merge($status['tables'], $status['system_tables']) as $table) {
		check(!in_array('lang', $table['missing'], true), $table['table'].' asks for lang off');
	}
});

test('P4 kickoff: without languages, dates are read as they always were, through parse_date (#135)', function () {
	with_settings(array('multilingual' => false, 'languages' => array()), function () {
		foreach (array('5/10/2026', '2026-10-05', '5.10.2026', '10 May 2026', 'today', '5/10/2026 19:30') as $typed) {
			same(strtotime($typed), cms_types::parse_date($typed), $typed);
			same(strtotime($typed), cms_types::parse_date($typed, 'ro'), "$typed in ro");
		}
		same(null, cms_types::parse_date('31/02/2026'));
		same('2026-05-10', cms_types::clean('date', '5/10/2026', 'when'), 'the American reading, as before');
		same('2026-05-10 19:30:00', cms_types::clean('datetime', '5/10/2026 19:30', 'when'));
	});
});

test('P4 kickoff: off, newsletter recipients in a language are everyone, as before', function () {
	foreach (p4_off_settings() as $what => $setting) {
		with_settings($setting, function () use ($what) {
			same(newsletter::recipients(null), newsletter::recipients(null, 'ro'), $what);
			same(newsletter::recipients(null), newsletter::recipients(null, 'en'), $what);
		});
	}
});

// ## P4.A: mail in the recipient's language

$p4a_on = array('multilingual' => true, 'languages' => array('en', 'ro'));
// A Romanian dictionary for the fixture emails, and $settings (PHP) in
// config/the_events.php for the servers
function p4a_site($settings, $fn) {
	global $root;
	$i18n = "$root/application/i18n";
	$made = !is_dir($i18n);
	$made_ro = !is_dir("$i18n/ro");
	@mkdir("$i18n/ro", 0775, true);
	test_fixture("$i18n/ro/zzp4a.php");
	file_put_contents("$i18n/ro/zzp4a.php", "<?php\nreturn array('subject' => 'Masa ta', 'hello' => 'Bună', 'confirmed' => 'Rezervare confirmată', 'new' => 'Rezervare nouă', 'promoted' => 'Ai intrat');\n");
	$events = APPBASE.'config/the_events.php';
	$kept = file_get_contents($events);
	if ($settings !== '') file_put_contents($events, (strpos($kept, '<?php') === 0 ? $kept : "<?php\n")."\n".$settings."\n");
	try {
		return $fn();
	} finally {
		file_put_contents($events, $kept);
		@unlink("$i18n/ro/zzp4a.php");
		if ($made_ro) @rmdir("$i18n/ro");
		if ($made) @rmdir($i18n);
	}
}
// an email view of the fixture theme: a translated <title> and greeting,
// $body, and the links an email has
function p4a_email($name, $title, $body = '') {
	$file = APPBASE.config::get('views_path', 'views').'/'.config::get('theme', 'default')."/_email/$name.html";
	test_fixture($file);
	file_put_contents($file, "<!doctype html><html><head><title><!-- print.i18n.zzp4a('$title') -->".ucfirst($title)."<!-- /print.i18n.zzp4a('$title') --></title></head>"
		."<body><p><!-- print.i18n.zzp4a('hello') -->Hello<!-- /print.i18n.zzp4a('hello') --> <!-- print.self.name -->Ana<!-- /print.self.name --></p>$body"
		.'<a href="/zzp4a_menu">Menu</a> <a href="zzp4a_menu.html">Page</a> <a href="/media/zzp4a.jpg">Photo</a> <a href="logo.png">Logo</a></body></html>');
	return $file;
}
// a mail's subject, decoded
function p4a_subject($raw) {
	return preg_match('/^Subject: (.*)$/m', $raw, $m) ? mb_decode_mimeheader(trim($m[1])) : null;
}
// a sent mail with its links' tokens and ids taken out too
function p4a_shape($raw) {
	$raw = p4_mail_shape($raw);
	// the parts are base64: decode them so a token in the text can go
	$raw = preg_replace_callback('/(Content-Transfer-Encoding: base64\r\n\r\n)(.*?)(\r\n--)/s', function ($m) {
		return $m[1].preg_replace('/token=[a-f0-9]+/', 'token=x', base64_decode($m[2])).$m[3];
	}, $raw);
	return $raw;
}

test('P4.A: send_view in a language: its words, the <title> as the subject, /ro/ links and root links made absolute (D13)', function () use ($p4a_on) {
	p4a_site('', function () use ($p4a_on) {
		$file = p4a_email('zzp4a_note', 'subject', '<p><a href="<!-- print.self.url /-->">Go</a></p>');
		try {
			with_settings($p4a_on, function () {
				$base = config::get('link_base');
				$site = preg_replace('#^([a-z]+://[^/]+).*$#', '$1', config::get('base_uri'));
				$theme = config::get('base_uri').trim(boot::$appname.'/'.config::get('views_path'), '/').'/'.config::get('theme').'/';
				same('en', i18n::current(), 'an English request');
				// the vars are built in the language too (in_language), as senders do
				$sent = mail::in_language('ro', function () { return mail::send_view('_email/zzp4a_note', 'ana@example.test', array('name' => 'Ana', 'url' => util::url('zzp4a_menu')), array(), 'ro'); });
				same(true, $sent);
				$mail = last_mail();
				same('Masa ta', p4a_subject($mail['raw']), 'the subject is the Romanian <title>');
				check(strpos($mail['text'], 'Bună Ana') !== false, $mail['text']);
				check(strpos($mail['text'], "Go ({$base}ro/zzp4a_menu)") !== false, 'a link the sender made: '.$mail['text']);
				check(strpos($mail['text'], "Menu ({$base}ro/zzp4a_menu)") !== false, 'a root page link: '.$mail['text']);
				check(strpos($mail['text'], "Page ({$base}ro/zzp4a_menu)") !== false, 'a page.html link: '.$mail['text']);
				check(strpos($mail['text'], "Photo ($site/media/zzp4a.jpg)") !== false, 'a root file link is the site\'s: '.$mail['text']);
				check(strpos($mail['text'], "Logo ({$theme}logo.png)") !== false, 'a relative file is the theme\'s: '.$mail['text']);
				same('en', i18n::current(), 'the request\'s language after');
				same($base, config::get('link_uri'));
				// in the default language: English, and a root link is still the page's
				mail::send_view('_email/zzp4a_note', 'ana@example.test', array('name' => 'Ana', 'url' => util::url('zzp4a_menu')), array(), 'en');
				$mail = last_mail();
				same('Subject', p4a_subject($mail['raw']));
				check(strpos($mail['text'], 'Hello Ana') !== false, $mail['text']);
				check(strpos($mail['text'], "Menu ({$base}zzp4a_menu)") !== false && strpos($mail['text'], '/ro/') === false, $mail['text']);
				// render_view alone, in a language
				$html = controller::render_view('_email/zzp4a_note', array('name' => 'Ana'), 'html', 'ro');
				check(strpos($html, '<title>Masa ta</title>') !== false && strpos($html, 'href="'.$base.'ro/zzp4a_menu">Menu') !== false, $html);
				// no language: the request's, as before
				mail::send_view('_email/zzp4a_note', 'ana@example.test', array('name' => 'Ana'));
				same('Subject', p4a_subject(last_mail()['raw']));
				$ro = i18n::with('ro', function () {
					mail::send_view('_email/zzp4a_note', 'ana@example.test', array('name' => 'Ana'));
					return last_mail();
				});
				same('Masa ta', p4a_subject($ro['raw']), 'a /ro/ request without a language');
			});
			// off, a root link is the theme's, as it always was
			foreach (p4_off_settings() as $what => $setting) {
				with_settings($setting, function () use ($what) {
					$theme = config::get('base_uri').trim(boot::$appname.'/'.config::get('views_path'), '/').'/'.config::get('theme').'/';
					$html = controller::render_view('_email/zzp4a_note', array('name' => 'Ana'), 'html', 'ro');
					check(strpos($html, 'href="'.$theme.'zzp4a_menu">Menu') !== false && strpos($html, 'href="'.$theme.'media/zzp4a.jpg"') !== false, "$what: $html");
					check(strpos($html, '<title>Subject</title>') !== false, "$what: English");
				});
			}
		} finally {
			@unlink($file);
		}
	});
});

test('P4.A: a view without a <title> is sent with the site\'s name in the recipient\'s language', function () use ($root, $p4a_on) {
	$file = "$root/application/views/default/_email/zzp4a_untitled.html";
	test_fixture($file);
	file_put_contents($file, '<!doctype html><html><head></head><body><p>Hi</p></body></html>');
	cms_store::connect();
	$made = !cms_store::table_exists('sitepage');
	if ($made) cms_types::ensure('sitepage', array('slug' => 'text', 'site_name' => 'text', 'updated_at' => 'datetime'));
	$had = array_key_exists('site_name__ro', cms_store::columns('sitepage'));
	if (!$had) cms_types::ensure('sitepage', array('site_name__ro' => 'text'));
	cms_store::forget();
	$row = R::dispense('sitepage');
	$row->slug = '/';
	$row->site_name = 'The Cafe';
	$row->site_name__ro = 'Cafeneaua';
	$row->updated_at = R::isoDateTime();
	R::store($row);
	try {
		with_settings($p4a_on, function () {
			mail::send_view('_email/zzp4a_untitled', 'ana@example.test', array(), array(), 'ro');
			same('Cafeneaua', p4a_subject(last_mail()['raw']));
			mail::send_view('_email/zzp4a_untitled', 'ana@example.test', array(), array(), 'en');
			same('The Cafe', p4a_subject(last_mail()['raw']));
		});
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what) {
				mail::send_view('_email/zzp4a_untitled', 'ana@example.test', array(), array(), 'ro');
				same('The Cafe', p4a_subject(last_mail()['raw']), $what);
			});
		}
	} finally {
		R::trash($row);
		if ($made) R::exec('DROP TABLE IF EXISTS sitepage');
		elseif (!$had) R::exec('ALTER TABLE sitepage DROP COLUMN site_name__ro');
		cms_store::forget();
		@unlink($file);
	}
});

test('P4.A: a staff member on English /staff confirms a booking made on /ro/: the guest\'s email is Romanian, with /ro/ links (D13)', function () use ($booking_base, $p1b_on, $p4a_on) {
	p4a_site($p1b_on, function () use ($booking_base, $p4a_on) {
		booking_site(function () use ($booking_base, $p4a_on) {
			p4a_email('booking_requested', 'subject', '<p>Manage: <!-- print.self.manage_url -->none<!-- /print.self.manage_url --></p>');
			p4a_email('booking_confirmed', 'confirmed', '<p>Manage: <!-- print.self.manage_url -->none<!-- /print.self.manage_url --></p>');
			p4a_email('booking_new', 'new', '<p>Wrote in <!-- print.self.lang -->x<!-- /print.self.lang -->, <!-- print.self.book_url -->x<!-- /print.self.book_url --></p>');
			with_settings($p4a_on, function () use ($booking_base) {
				cms_records::forget();
				try {
					$tue = booking_cafe();
					cms_records::ensure(cms_records::info('booking'), true);
					// the guest books on /ro/zzbook
					$mails = booking_mails(function () use ($booking_base, $tue) {
						same(303, form_post("$booking_base/ro/zzbook", array('raster_form' => 'booking.form', 'name' => 'Ioana', 'email' => 'ioana@example.test', 'date' => $tue, 'time' => '19:00', 'people' => '2'))[0]);
					});
					same(array('Masa ta ioana@example.test', 'New staff@zz.test'), booking_sent($mails), 'the guest\'s in Romanian, staff\'s in English');
					check(strpos($mails[0]['text'], "$booking_base/ro/booking?token=") !== false && strpos($mails[0]['text'], "Menu ($booking_base/ro/zzp4a_menu)") !== false, $mails[0]['text']);
					check(strpos($mails[1]['text'], 'Hello Ioana') !== false && strpos($mails[1]['text'], 'Wrote in ro') !== false, $mails[1]['text']);
					check(strpos($mails[1]['text'], "$booking_base/book") !== false && strpos($mails[1]['text'], '/ro/') === false, 'staff\'s links are English: '.$mails[1]['text']);
					$b = cms_records::find('booking', array('email' => 'ioana@example.test'))[0];
					same('ro', $b['lang']);
					// staff confirm it from an English page (MCP run_action and the page's button call act)
					same('en', i18n::current());
					$base = config::get('link_base');
					$mails = booking_mails(function () use ($b) {
						booking_as_editor(function () use ($b) { cms_records::act('booking', $b['id'], 'confirm', array(), true); });
					});
					same(array('Rezervare confirmată ioana@example.test'), booking_sent($mails));
					check(strpos($mails[0]['text'], 'Bună Ioana') !== false, $mails[0]['text']);
					check(strpos($mails[0]['text'], "{$base}ro/booking?token=") !== false, 'the manage link is /ro/: '.$mails[0]['text']);
					check(strpos($mails[0]['text'], "Menu ({$base}ro/zzp4a_menu)") !== false, $mails[0]['text']);
					same('en', i18n::current(), 'the request stays English');
					// staff can't change it, and a later write keeps it
					same(array(), booking_problems(function () use ($b) {
						try {
							booking_staff(array('lang' => 'en'), $b['id']);
							check(false, 'staff set lang');
						} catch (InvalidArgumentException $e) {
							check(strpos($e->getMessage(), "'lang'") !== false, $e->getMessage());
						}
					}));
					booking_staff(array('notes' => 'window seat'), $b['id']);
					same('ro', cms_records::get('booking', $b['id'])['lang']);
					// a booking staff take by phone on the English page is English
					$phone = booking_staff(array('email' => 'phone@example.test', 'date' => $tue, 'time' => '19:00', 'people' => 2));
					same('en', cms_records::get('booking', $phone['id'])['lang']);
					// a booking from before the column reads as the default language
					R::exec('UPDATE bookingdata SET lang = ? WHERE id = ?', array('', $phone['id']));
					$mails = booking_mails(function () use ($phone) {
						booking_as_editor(function () use ($phone) { cms_records::act('booking', $phone['id'], 'confirm', array(), true); });
					});
					same(array('Confirmed phone@example.test'), booking_sent($mails));
					check(strpos($mails[0]['text'], '/ro/') === false, $mails[0]['text']);
				} finally {
					cms_records::forget();
				}
			});
		});
	});
});

test('P4.A: rsvp: a waiting guest who replied on /ro/ is promoted by staff in Romanian; staff\'s copy is English', function () use ($p4a_on) {
	p4a_site('', function () use ($p4a_on) {
		rsvp_site(function () use ($p4a_on) {
			p4a_email('rsvp_promoted', 'promoted', '<p>Manage: <!-- print.self.manage_url -->none<!-- /print.self.manage_url --></p>');
			p4a_email('rsvp_new', 'new', '<p>Wrote in <!-- print.self.lang -->x<!-- /print.self.lang --></p>');
			with_settings($p4a_on + array('rsvp_staff_email' => 'staff@zz.test'), function () {
				cms_records::forget();
				try {
					// past its deadline, so only staff move the waitlist in
					$event = rsvp_event(array('date' => date('Y-m-d', strtotime('+1 day')), 'places' => 1, 'rsvp_until' => date('Y-m-d H:i', time() - 3600)));
					$first = rsvp_reply(array('event' => $event['id'], 'email' => 'first@example.test'));
					$waiting = i18n::with('ro', function () use ($event) { return rsvp_reply(array('event' => $event['id'], 'email' => 'ioana@example.test', 'name' => 'Ioana'), 'editor'); });
					same(array('waiting', 'ro'), array(rsvp_status($waiting['id']), cms_records::get('rsvp', $waiting['id'])['lang']));
					cms_records::update('rsvp', $first['id'], array('answer' => 'no'));
					$base = config::get('link_base');
					$mails = booking_mails(function () use ($waiting) {
						booking_as_editor(function () use ($waiting) { cms_records::act('rsvp', $waiting['id'], 'promote', array(), true); });
					});
					same(array('Ai intrat ioana@example.test'), booking_sent($mails));
					check(strpos($mails[0]['text'], 'Bună Ioana') !== false && strpos($mails[0]['text'], "{$base}ro/rsvp?token=") !== false, $mails[0]['text']);
					// staff hear of a guest's own reply in English, with the guest's language
					rsvp::forget();
					$mails = booking_mails(function () use ($event) {
						i18n::with('ro', function () use ($event) {
							$reply = rsvp_reply(array('event' => $event['id'], 'email' => 'maria@example.test', 'name' => 'Maria', 'answer' => 'no'));
							(new rsvp())->replied_mail(rsvp::payload($reply, 'guest'));
						});
					});
					$staff = array_values(array_filter($mails, function ($m) { return $m['to'] === 'staff@zz.test'; }));
					same(1, count($staff), json_encode(booking_sent($mails)));
					same('New', $staff[0]['view']);
					check(strpos($staff[0]['text'], 'Wrote in ro') !== false && strpos($staff[0]['text'], 'Hello Maria') !== false, $staff[0]['text']);
				} finally {
					cms_records::forget();
				}
			});
		});
	});
});

test('P4.A: the password reset, newsletter confirmation and contact emails follow the stored language', function () use ($base, $p1b_on, $p4a_on) {
	p4a_site($p1b_on, function () use ($base, $p4a_on) {
		with_settings($p4a_on, function () use ($base) {
			cms_store::connect();
			// an account made on /ro/ asks for a reset on an English page
			$id = i18n::with('ro', function () { return authentication::create_user('zzp4a-reset@example.test', 'a long password', 'member', 'Ioana'); });
			same('ro', R::load('user', $id)->lang);
			same('ro', authentication::payload($id)['lang'], 'the events say it');
			same(303, form_post("$base/forgot", array('raster_form' => 'authentication.forgot', 'email' => 'zzp4a-reset@example.test'))[0]);
			$mail = last_mail();
			check($mail && strpos($mail['text'], "$base/ro/reset?token=") !== false, 'the reset link: '.($mail ? $mail['text'] : 'no mail'));
			// one made in English gets English
			$en = authentication::create_user('zzp4a-reset-en@example.test', 'a long password', 'member', 'Ana');
			same('en', R::load('user', $en)->lang);
			// signing up on /ro/, confirming from anywhere: the link is /ro/
			$link = config::get('link_base');
			same('pending', i18n::with('ro', function () { return newsletter::subscribe('zzp4a-news@example.test', 'Ioana'); }));
			same('ro', R::findOne('subscriber', ' email = ? ', array('zzp4a-news@example.test'))->lang);
			// signing up again in English keeps it
			newsletter::subscribe('zzp4a-news@example.test', 'Ioana');
			same('ro', R::findOne('subscriber', ' email = ? ', array('zzp4a-news@example.test'))->lang);
			R::exec('UPDATE subscriber SET confirm_sent_at = NULL WHERE email = ?', array('zzp4a-news@example.test'));
			(new newsletter())->confirmation_mail(array('email' => 'zzp4a-news@example.test', 'status' => 'pending'));
			$mail = last_mail();
			check(strpos($mail['text'], "{$link}ro/newsletter-confirm?token=") !== false, $mail['text']);
		});
		// contact: staff's email in the default language, with the page as the sender read it
		contact_site(function () use ($p4a_on) {
			p4a_email('contact_received', 'new', '<p>Wrote in <!-- print.self.lang -->x<!-- /print.self.lang --> on <!-- print.self.page_url -->x<!-- /print.self.page_url --></p>');
			with_settings($p4a_on + array('contact_staff_email' => 'desk@zz.test'), function () {
				cms_records::forget();
				try {
					cms_records::ensure(cms_records::info('contactmessage'), true);
					$link = config::get('link_base');
					$message = i18n::with('ro', function () { return cms_records::create('contactmessage', array('email' => 'ioana@example.test', 'name' => 'Ioana', 'message' => 'Bună', 'page' => '/contact')); });
					same('ro', $message['lang']);
					(new contact())->staff_mail(contact::payload($message, 'visitor'));
					$mail = last_mail();
					same('New', p4a_subject($mail['raw']));
					check(strpos($mail['text'], 'Hello Ioana') !== false && strpos($mail['text'], "Wrote in ro on {$link}ro/contact") !== false, $mail['text']);
				} finally {
					cms_records::forget();
				}
			});
		});
	});
});

// the mails $fn sends, shaped (p4a_shape), in order
function p4a_sent($fn) {
	return array_map(function ($m) { return p4a_shape($m['raw']); }, booking_mails($fn));
}

test('P4.A: off, every bundled sender\'s mail is what it was, byte for byte (D13)', function () {
	$off = array('none' => array('multilingual' => false, 'languages' => array())) + p4_off_settings();
	$token = str_repeat('ab', 32);
	// booking: the guest's request with staff's copy, and a confirmation
	booking_site(function () use ($off, $token) {
		$tue = booking_cafe();
		cms_records::ensure(cms_records::info('booking'), true);
		$b = booking_guest(array('name' => 'Ana', 'email' => 'ana@example.test', 'date' => $tue, 'time' => '19:00', 'people' => 2));
		check(!array_key_exists('lang', cms_records::get('booking', $b['id'])), 'off, no lang is stored');
		$payload = booking::payload(cms_records::get('booking', $b['id']), 'guest');
		check(!array_key_exists('lang', $payload), 'off, the payload has no lang');
		// what 2.2.50 sent: the booking's fields and the links, as send_view gets them
		$vars = $payload + array('manage_url' => util::url('booking', array('token' => $token)), 'resource_name' => 'Dining room', 'service_name' => '', 'site_url' => util::url(''), 'book_url' => util::url('book'));
		$then = p4a_sent(function () use ($vars) {
			mail::send_view('_email/booking_requested', 'ana@example.test', $vars);
			mail::send_view('_email/booking_new', 'staff@zz.test', array('manage_url' => '') + $vars);
			mail::send_view('_email/booking_confirmed', 'ana@example.test', $vars);
		});
		foreach ($off as $what => $setting) {
			with_settings($setting, function () use ($what, $payload, $b, $token, $then) {
				cms_records::forget();
				same($then, p4a_sent(function () use ($payload, $b, $token) {
					booking::$links[(int)$b['id']] = $token;
					(new booking())->requested_mail($payload);
					booking::$links[(int)$b['id']] = $token;
					(new booking())->confirmed_mail($payload);
				}), "booking, $what");
			});
		}
		cms_records::forget();
	});
	// rsvp: a promotion, and a guest's reply with staff's copy
	rsvp_site(function () use ($off, $token) {
		with_settings(array('rsvp_staff_email' => 'staff@zz.test'), function () use ($off, $token) {
			$event = rsvp_event(array('places' => 4));
			$reply = rsvp_reply(array('event' => $event['id'], 'email' => 'ana@example.test', 'name' => 'Ana'));
			$payload = rsvp::payload($reply, 'guest');
			check(!array_key_exists('lang', $payload), 'off, the payload has no lang');
			$item = rsvp::item($event['id']);
			$vars = $payload + array('manage_url' => util::url('rsvp', array('token' => $token)), 'position' => '', 'event_url' => $item['url'], 'deadline' => date('Y-m-d H:i', $item['deadline']), 'site_url' => util::url(''));
			$then = p4a_sent(function () use ($vars) {
				mail::send_view('_email/rsvp_going', 'ana@example.test', $vars);
				mail::send_view('_email/rsvp_new', 'staff@zz.test', array('manage_url' => '') + $vars);
				mail::send_view('_email/rsvp_promoted', 'ana@example.test', $vars);
			});
			foreach ($off as $what => $setting) {
				with_settings($setting, function () use ($what, $payload, $reply, $token, $then) {
					cms_records::forget();
					same($then, p4a_sent(function () use ($payload, $reply, $token) {
						rsvp::$links[(int)$reply['id']] = $token;
						(new rsvp())->replied_mail($payload);
						rsvp::$links[(int)$reply['id']] = $token;
						(new rsvp())->promoted_mail($payload);
					}), "rsvp, $what");
				});
			}
			cms_records::forget();
		});
	});
	// contact: staff's email
	contact_site(function () use ($off) {
		with_settings(array('contact_staff_email' => 'desk@zz.test'), function () use ($off) {
			cms_records::ensure(cms_records::info('contactmessage'), true);
			$message = contact::payload(cms_records::create('contactmessage', array('email' => 'ana@example.test', 'name' => 'Ana', 'subject' => 'Table', 'message' => 'Hi', 'page' => '/contact')), 'visitor');
			$then = p4a_sent(function () use ($message) {
				mail::send_view('_email/contact_received', 'desk@zz.test', $message + array('page_url' => util::url('/contact'), 'staff_url' => util::url('staff'), 'site_url' => util::url('')), array('Reply-To' => '"Ana" <ana@example.test>'));
			});
			foreach ($off as $what => $setting) {
				with_settings($setting, function () use ($what, $message, $then) {
					cms_records::forget();
					same($then, p4a_sent(function () use ($message) { (new contact())->staff_mail($message); }), "contact, $what");
				});
			}
			cms_records::forget();
		});
	});
	// newsletter: the confirmation
	cms_store::connect();
	newsletter::subscribe('zzp4a-off@example.test', 'Ana');
	$subscriber = R::findOne('subscriber', ' email = ? ', array('zzp4a-off@example.test'));
	check(!isset($subscriber->lang), 'off, no lang is stored');
	$then = p4a_sent(function () use ($subscriber) {
		mail::send_view('_email/newsletter_confirm', 'zzp4a-off@example.test', array('confirm_url' => util::url('newsletter-confirm', array('token' => $subscriber->token)), 'name' => 'Ana'));
	});
	foreach ($off as $what => $setting) {
		with_settings($setting, function () use ($what, $then) {
			R::exec('UPDATE subscriber SET confirm_sent_at = NULL WHERE email = ?', array('zzp4a-off@example.test'));
			same($then, p4a_sent(function () { (new newsletter())->confirmation_mail(array('email' => 'zzp4a-off@example.test', 'status' => 'pending')); }), "newsletter, $what");
		});
	}
	R::exec('DELETE FROM subscriber WHERE email = ?', array('zzp4a-off@example.test'));
	// accounts: none gets a language off
	$id = authentication::create_user('zzp4a-off@example.test', 'a long password');
	check(!isset(R::load('user', $id)->lang), 'off, no lang is stored');
	same(array('id', 'email', 'name', 'role'), array_keys(authentication::payload($id)));
	R::exec('DELETE FROM user WHERE id = ?', array($id));
});

test('P4.A: a record\'s lang column is kept, not an orphan, when multilingual is turned off again (D13)', function () use ($p4a_on) {
	booking_site(function () use ($p4a_on) {
		with_settings($p4a_on, function () {
			cms_records::forget();
			cms_records::ensure(cms_records::info('booking'), true);
			cms_records::forget();
		});
		check(array_key_exists('lang', cms_store::columns('bookingdata')), 'on made the column');
		$status = with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () {
			cms_records::forget();
			try {
				return (new raster_schema())->status();
			} finally {
				cms_records::forget();
			}
		});
		$table = null;
		foreach ($status['tables'] as $row) if ($row['table'] === 'bookingdata') $table = $row;
		check($table !== null, 'bookingdata is listed');
		check(!in_array('lang', $table['orphans'], true), 'lang is an orphan: '.json_encode($table['orphans']));
		// and so is its slug in Romanian, which development made with the table (P5.A)
		same(array('lang', 'slug__ro'), isset($status['translations']['bookingdata']) ? $status['translations']['bookingdata'] : null, 'it is kept');
	});
});

test('P4.A: a frozen database without the lang columns still takes accounts and subscribers (D13)', function () use ($root) {
	$db = test_db(sys_get_temp_dir().'/raster-p4a-frozen-'.getmypid().'.sqlite');
	$env = 'RASTER_ENV=production RASTER_DB='.escapeshellarg($db).' RASTER_URL=http://example.test/';
	$raster = php_cmd().' '.escapeshellarg("$root/bin/raster");
	try {
		exec("$env $raster schema --apply 2>&1", $out, $code);
		same(0, $code, implode("\n", $out));
		p4a_site("config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);", function () use ($env, $raster, $root) {
			// (raster user unfreezes, as it adds the columns accounts need)
			$result = shell_exec("$env ".php_cmd().' -r '.escapeshellarg('require "'.$root.'/system/boot.php"; boot::$appname = "application"; boot::cli(); authentication::connect(); newsletter::connect();'
				.' $id = authentication::create_user("zzp4a@example.test", "secret-pass");'
				.' echo json_encode(array(database::$frozen, i18n::enabled(), (bool)authentication::check_login("zzp4a@example.test", "secret-pass"), newsletter::subscribe("zzp4a@example.test"),'
				.' array_key_exists("lang", cms_store::columns("user")), array_key_exists("lang", cms_store::columns("subscriber"))));').' 2>&1');
			same('[true,true,true,"pending",false,false]', trim((string)$result));
		});
	} finally {
		array_map('unlink', glob("$db*") ?: array());
	}
});

// ## P4.B: newsletter per language, record names, dates (#135)

// the subscriber table set aside for $fn, so it starts with none; the rows
// $fn makes are thrown away and the table put back after
function p4b_subscribers($fn) {
	cms_store::connect();
	$kept = cms_store::table_exists('subscriber');
	if ($kept) R::exec('ALTER TABLE subscriber RENAME TO zzp4b_subscriber');
	try {
		return $fn(function ($email, $fields = array()) {
			$subscriber = R::dispense('subscriber');
			foreach ($fields + array('email' => $email, 'name' => '', 'status' => 'confirmed', 'token' => sha1($email), 'source' => '', 'created_at' => R::isoDateTime()) as $name => $value) $subscriber->$name = $value;
			return (int)R::store($subscriber);
		});
	} finally {
		if (cms_store::table_exists('subscriber')) R::exec('DROP TABLE subscriber');
		if ($kept) R::exec('ALTER TABLE zzp4b_subscriber RENAME TO subscriber');
	}
}

test('P4.B: newsletter recipients in a language are those who read it; no lang, or none known, reads the default', function () {
	$on = array('multilingual' => true, 'languages' => array('en', 'ro'));
	p4b_subscribers(function ($add) use ($on) {
		$ana = $add('ana@example.test');
		$bob = $add('bob@example.test');
		$add('gone@example.test', array('status' => 'unsubscribed'));
		$emails = function ($rows) { return array_map(function ($row) { return $row['email']; }, $rows); };
		with_settings($on, function () use ($emails) {
			// a table from before the column: everyone reads the default
			check(!array_key_exists('lang', cms_store::columns('subscriber')), 'the fixture has no lang column yet');
			same(array('ana@example.test', 'bob@example.test'), $emails(newsletter::recipients(null, 'en')), 'no column: the default');
			same(array(), newsletter::recipients(null, 'ro'), 'no column: nobody reads ro');
		});
		$cy = $add('cy@example.test', array('lang' => 'ro'));
		$add('dan@example.test', array('lang' => 'fr'));
		$add('eve@example.test', array('lang' => 'RO'));
		$add('fay@example.test', array('lang' => 'en'));
		$add('gil@example.test', array('lang' => 'ro', 'status' => 'pending'));
		with_settings($on, function () use ($emails, $cy) {
			same(array('cy@example.test', 'eve@example.test'), $emails(newsletter::recipients(null, 'ro')), 'ro');
			same(array('ana@example.test', 'bob@example.test', 'dan@example.test', 'fay@example.test'), $emails(newsletter::recipients(null, 'en')), "en: '' and a dropped language too");
			$row = newsletter::recipients(null, 'ro')[0];
			same(array('id', 'email', 'name', 'token'), array_keys($row), 'the rows keep their keys');
			// an issue: only those it has not reached
			$issue = R::dispense('newsletterissue');
			$issue->url = '/ro/zzp4b-issue';
			R::store($issue);
			$delivery = R::dispense('newsletterdelivery');
			$delivery->issue = (int)$issue->id;
			$delivery->email = 'cy@example.test';
			R::store($delivery);
			try {
				same(array('eve@example.test'), $emails(newsletter::recipients($issue, 'ro')), 'ro, not reached yet');
				same(4, count(newsletter::recipients($issue, 'en')));
				// no language: everyone, as before
				same(5, count(newsletter::recipients($issue)));
			} finally {
				R::trash($delivery);
				R::trash($issue);
			}
		});
		// off, a language changes nothing: the same rows, keys and order
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what) {
				same(newsletter::recipients(null), newsletter::recipients(null, 'ro'), $what);
				same(array('id', 'email', 'name', 'token'), array_keys(newsletter::recipients(null, 'ro')[0]), $what);
			});
		}
	});
});

test('P4.B: raster send on a bilingual site sends each subscriber the page in their language (#200)', function () use ($root, $db, $maildir, $base, $p1b_on) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp4b_issue.html");
	file_put_contents("$theme/zzp4b_issue.html", '<!doctype html><html><head><title><!-- print.i18n.zzp1b(\'hello\') -->Hello<!-- /print.i18n.zzp1b(\'hello\') --></title></head><body>'
		.'<p>Issue</p><a href="<!-- print.newsletter.unsubscribe_url /-->">Unsubscribe</a></body></html>');
	$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_MAIL='.escapeshellarg("log://$maildir").' RASTER_URL='.escapeshellarg("$base/");
	$send = function ($args) use ($env, $root) {
		return shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' send '.$args.' 2>&1; echo "exit=$?"');
	};
	$sent = function ($fn) use ($maildir) {
		$before = glob("$maildir/*.eml") ?: array();
		$out = $fn();
		$mails = array();
		foreach (array_diff(glob("$maildir/*.eml") ?: array(), $before) as $file) {
			$raw = file_get_contents($file);
			preg_match('/^To: (.*)$/m', $raw, $to);
			preg_match('/^Subject: (.*)$/m', $raw, $subject);
			$mails[trim($to[1])] = array('subject' => iconv_mime_decode(trim($subject[1]), 0, 'UTF-8'), 'raw' => $raw);
		}
		ksort($mails);
		return array($out, $mails);
	};
	try {
		p4b_subscribers(function ($add) use ($p1b_on, $send, $sent) {
			$add('old@example.test');
			$add('ro@example.test', array('lang' => 'ro'));
			$add('en@example.test', array('lang' => 'en'));
			p1b_site($p1b_on, function () use ($send, $sent) {
				$out = $send('/zzp4b_issue --dry-run');
				check(strpos($out, 'Would send "Hello" (en) to 2 subscriber(s)') !== false && strpos($out, 'Would send "Bună ziua" (ro) to 1 subscriber(s)') !== false && strpos($out, 'exit=0') !== false, "dry run: $out");
				list($out, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue'); });
				check(strpos($out, 'Sent "Hello" (en) to 2 subscriber(s)') !== false && strpos($out, 'Sent "Bună ziua" (ro) to 1 subscriber(s)') !== false && strpos($out, 'exit=0') !== false, "send: $out");
				same(array('en@example.test', 'old@example.test', 'ro@example.test'), array_keys($mails));
				same(array('Hello', 'Hello', 'Bună ziua'), array_values(array_map(function ($m) { return $m['subject']; }, $mails)), 'each in their language; a subscriber from before the column in the default');
				check(preg_match('#List-Unsubscribe: <[^>]*/ro/newsletter-unsubscribe\?token=#', $mails['ro@example.test']['raw']) === 1, 'the Romanian reader unsubscribes on the Romanian page');
				check(preg_match('#List-Unsubscribe: <[^>]*/newsletter-unsubscribe\?token=#', $mails['old@example.test']['raw']) === 1 && strpos($mails['old@example.test']['raw'], '/ro/newsletter-unsubscribe') === false, 'the default reader on the default one');
				// each language is an issue of its own, keyed by its address
				same(array('/zzp4b_issue', '/ro/zzp4b_issue'), R::getCol("SELECT url FROM newsletterissue WHERE url LIKE '%zzp4b_issue' ORDER BY id"));
				list($out, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue'); });
				check(strpos($out, '/zzp4b_issue was already sent') !== false && strpos($out, '/ro/zzp4b_issue was already sent') !== false && strpos($out, 'exit=1') !== false && !$mails, "sent once: $out");
				// a new reader of ro gets it on the next run with --lang
				R::exec("UPDATE newsletterissue SET incomplete = 1 WHERE url = '/ro/zzp4b_issue'");
				return null;
			});
			$add('ro2@example.test', array('lang' => 'ro'));
			p1b_site($p1b_on, function () use ($send, $sent) {
				list($out, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue --lang=ro'); });
				check(strpos($out, 'Sent "Bună ziua" (ro) to 1 more subscriber(s), 2 in all') !== false && strpos($out, '(en)') === false, "--lang=ro: $out");
				same(array('ro2@example.test'), array_keys($mails));
				// --to: the default's rendering, or --lang's
				list($out, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue --to=me@example.test'); });
				same(array('me@example.test'), array_keys($mails));
				same('Hello', $mails['me@example.test']['subject'], "--to: $out");
				list(, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue --to=me@example.test --lang=ro --subject=Salut'); });
				same('Salut', $mails['me@example.test']['subject'], '--subject applies to every language');
				check(strpos($send('/zzp4b_issue --lang=fr --dry-run'), 'exit=2') !== false, 'a language the site lacks');
				// a language that doesn't render is an error for it; the others still go
				$out = $send('/zzp4b_nothing --dry-run');
				check(strpos($out, '/ro/zzp4b_nothing did not render') !== false && strpos($out, 'error: /zzp4b_nothing did not render') !== false && strpos($out, 'exit=1') !== false, "missing page: $out");
				return null;
			});
			// off: no language, everyone gets the one page, and --lang is refused
			list($out, $mails) = $sent(function () use ($send) { return $send('/zzp4b_issue --again'); });
			check(strpos($out, 'Sent "Hello" to 4 subscriber(s) via') !== false && strpos($out, 'exit=0') !== false, "off: $out");
			same(4, count($mails));
			check(strpos($send('/zzp4b_issue --lang=ro --dry-run'), 'exit=2') !== false, 'off, --lang is refused');
		});
	} finally {
		@unlink("$theme/zzp4b_issue.html");
		cms_store::connect();
		if (cms_store::table_exists('newsletterissue') && cms_store::table_exists('newsletterdelivery')) R::exec("DELETE FROM newsletterdelivery WHERE issue IN (SELECT id FROM newsletterissue WHERE url LIKE '%zzp4b_issue')");
		if (cms_store::table_exists('newsletterissue')) R::exec("DELETE FROM newsletterissue WHERE url LIKE '%zzp4b_issue'");
	}
});

test('P4.B: dates are typed day first in every language but en-us; ISO and words everywhere; no languages, as before (D14)', function () {
	$day = function ($typed, $lang = null) {
		$time = cms_types::parse_date($typed, $lang);
		return $time === null ? null : date('Y-m-d H:i', $time);
	};
	with_settings(array('multilingual' => true, 'languages' => array('en', 'ro')), function () use ($day) {
		same('2026-10-05 00:00', $day('5/10/2026'), 'en+ro: 5 October');
		same('2026-10-05 00:00', $day('5/10/2026', 'ro'));
		same('2026-10-05 00:00', $day('05/10/2026', 'en'), 'en is day first too');
		same('2026-10-05 19:30', $day('5/10/2026 19:30'), 'with a time');
		same('2026-10-05 00:00', $day('5.10.2026'), 'dots, as PHP reads them');
		same('2026-10-05 00:00', $day('5-10-2026'), 'dashes, as PHP reads them');
		same('2026-10-05 00:00', $day('2026-10-05'), 'ISO');
		same('2026-05-10 00:00', $day('10 May 2026'), 'words');
		same(date('Y-m-d 00:00'), $day('today'));
		same('2026-12-25 00:00', $day('25/12/2026'), 'a day past 12, which American order refuses');
		same(null, $day('13/13/2026'));
		same(null, $day('31/02/2026'));
		same(null, $day('5/10/2026 nonsense'));
		same('2026-05-10 00:00', $day('5/10/2026', 'en-us'), 'en-us is month first');
		// what is stored, through clean()
		same('2026-10-05', cms_types::clean('date', '5/10/2026', 'when'));
		same('2026-10-05 19:30:00', cms_types::clean('datetime', '5/10/2026 19:30', 'when'));
		same('2026-10-05', i18n::with('ro', function () { return cms_types::clean('date', '5/10/2026', 'when'); }), 'in the current language');
	});
	with_settings(array('multilingual' => true, 'languages' => array('en-us', 'es')), function () use ($day) {
		same('2026-05-10 00:00', $day('5/10/2026'), 'an en-us site reads it the American way');
		same('2026-10-05 00:00', $day('5/10/2026', 'es'));
	});
	// languages set, the switch off: the order still follows them (D14)
	with_settings(array('multilingual' => false, 'languages' => array('ro')), function () use ($day) {
		same('2026-10-05 00:00', $day('5/10/2026'), 'a site in Romanian only');
	});
	with_settings(array('multilingual' => false, 'languages' => array()), function () use ($day) {
		same('2026-05-10 00:00', $day('5/10/2026'), 'no languages: unchanged');
		same('2026-05-10', cms_types::clean('date', '5/10/2026', 'when'));
		same(strtotime('25/12/2026') === false ? null : date('Y-m-d H:i', strtotime('25/12/2026')), $day('25/12/2026'), 'no languages: as strtotime');
	});
});

test('P4.B: booking resource and service names in a language: the translation, else the default; off, as before (#200)', function () use ($p1b_on) {
	booking_site(function () use ($p1b_on) {
		same(array('name'), cms_records::info('bookingservice')['translate'], 'bookingservice translates its name');
		same(array('name'), cms_records::info('bookingresource')['translate']);
		same(array(), cms_records::info('bookingservice')['unknown']);
		same(array(), cms_records::info('booking')['translate'], "a booking's fields are what the guest typed");
		$on = array('multilingual' => true, 'languages' => array('en', 'ro'));
		$date = with_settings($on, function () {
			$date = booking_salon();
			$cut = (int)R::getCell("SELECT id FROM bookingservicedata WHERE slug = 'cut'");
			$ana = (int)R::getCell("SELECT id FROM bookingresourcedata WHERE slug = 'ana'");
			cms_store::save_item('bookingservicedata', $cut, array('name' => 'Tuns'), array('name'), 'editor', null, 'ro');
			cms_store::save_item('bookingresourcedata', $ana, array('name' => 'Ana (RO)'), array('name'), 'editor', null, 'ro');
			return $date;
		});
		$names = function ($rows) { return array_map(function ($row) { return $row['name']; }, $rows); };
		$name_of = new ReflectionMethod('booking', 'name_of');
		$name_of->setAccessible(true);
		with_settings($on, function () use ($names, $name_of) {
			booking::forget();
			same(array('Cut', 'Colour'), $names((new booking())->services()), 'en');
			same(array('Ana', 'Mihai'), $names((new booking())->resources()));
			$ro = i18n::with('ro', function () use ($names, $name_of) {
				return array($names((new booking())->services()), $names((new booking())->resources()), $name_of->invoke(null, booking::setup(), 'services', 'cut'), $name_of->invoke(null, booking::setup(), 'resources', 'mihai'));
			});
			same(array(array('Tuns', 'Colour'), array('Ana (RO)', 'Mihai'), 'Tuns', 'Mihai'), $ro, 'ro: translated, and an untranslated name in English');
			same('Cut', $name_of->invoke(null, booking::setup(), 'services', 'cut'), 'the setup is read once for every language');
			same('', $name_of->invoke(null, booking::setup(), 'services', 'nothing'));
		});
		// off: the stored name, whatever the columns
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($names, $name_of, $what) {
				booking::forget();
				same(array('Cut', 'Colour'), $names((new booking())->services()), $what);
				same('Cut', i18n::with('ro', function () use ($name_of) { return $name_of->invoke(null, booking::setup(), 'services', 'cut'); }), $what);
			});
		}
		// the pages: /ro/ shows the Romanian names
		p1b_site($p1b_on, function () {
			$ro = booking_render('/ro/zzbook');
			check(strpos($ro, '<i class="service">Tuns</i>') !== false && strpos($ro, '<i class="service">Colour</i>') !== false && strpos($ro, '<b class="resource">Ana (RO)</b>') !== false, "/ro/zzbook: $ro");
			$en = booking_render('/zzbook');
			check(strpos($en, '<i class="service">Cut</i>') !== false && strpos($en, 'Tuns') === false, "/zzbook: $en");
		});
		booking::forget();
	});
});

test('P4.B: an rsvp event reads in the current language, each language cached on its own (#200)', function () {
	rsvp_site(function () {
		$on = array('multilingual' => true, 'languages' => array('en', 'ro'));
		with_settings($on, function () {
			$id = (int)rsvp_event(array('title' => 'Jazz night'))['id'];
			cms_types::ensure('eventsdata', array(), array('title'));
			cms_store::save_item('eventsdata', $id, array('title' => 'Seară de jazz', 'slug' => 'seara-de-jazz'), array('title', 'slug'), 'editor', null, 'ro');
			rsvp::forget();
			$en = rsvp::item($id);
			$ro = i18n::with('ro', function () use ($id) { return rsvp::item($id); });
			same('Jazz night', $en['title']);
			same('Seară de jazz', $ro['title'], 'ro: the title translated');
			same('jazz-night', $ro['slug'], 'the slug the event is known by stays');
			check(substr($ro['url'], -strlen('/seara-de-jazz')) === '/seara-de-jazz' && strpos($ro['url'], '/ro/') !== false, 'ro url: '.$ro['url']);
			check(substr($en['url'], -strlen('/jazz-night')) === '/jazz-night' && strpos($en['url'], '/ro/') === false, 'en url: '.$en['url']);
			same('Jazz night', rsvp::item($id)['title'], 'the English one is still English after');
			// an event without a translation reads in English
			$plain = (int)rsvp_event(array('title' => 'Quiz'))['id'];
			same('Quiz', i18n::with('ro', function () use ($plain) { return rsvp::item($plain)['title']; }));
		});
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what) {
				rsvp::forget();
				$id = (int)R::getCell("SELECT id FROM eventsdata WHERE slug = 'jazz-night'");
				same('Jazz night', rsvp::item($id)['title'], $what);
				same('Jazz night', i18n::with('ro', function () use ($id) { return rsvp::item($id)['title']; }), "$what, in ro");
			});
		}
		rsvp::forget();
	});
});

// ## P4.C: the coding MCP translates emails and records

// The starter with a booking page (render.booking.form) and the booking
// emails, whose words are keys of the zzp4c_mail dictionary: 'hello' is
// printed by every email, 'book' by an email and the page too. Its own
// database, the_app.php and the dictionaries as they were, put back after.
function p4c_site($fn) {
	global $root;
	$theme = "$root/application/views/default";
	$lang = '<!doctype html><!-- print.@lang.i18n.language --><html lang="en"><!-- /print.@lang.i18n.language -->';
	$key = function ($key, $text) { return "<!-- print.i18n.zzp4c_mail('$key') -->$text<!-- /print.i18n.zzp4c_mail('$key') -->"; };
	$body = '<body><p>'.$key('hello', 'Hello').' <!-- print.self.name -->there<!-- /print.self.name -->,</p>'
		.'<p><!-- print.self.date -->2026-10-20<!-- /print.self.date --> <!-- print.self.time -->19:00<!-- /print.self.time -->:'
		.' <!-- print.self.service_name -->Dinner<!-- /print.self.service_name --> <!-- print.self.resource_name -->Dining room<!-- /print.self.resource_name --></p>';
	$views = array(
		'zzp4c_book' => $lang.'<head><title>Book</title></head><body><h1>'.$key('book', 'Book a table').'</h1>'
			.'<!-- render.booking.form --><form method="post"><input name="name" required><input type="email" name="email"><input type="date" name="date" required>'
			.'<input name="time" required><input type="number" name="people"><input name="resource"><input name="service"><button>Book</button></form><!-- /render.booking.form --></body></html>',
		'_email/booking_requested' => $lang.'<head><title>'.$key('requested_subject', 'We have your booking').'</title></head>'.$body
			.'<p><a href="<!-- print.self.manage_url /-->">'.$key('book', 'Book a table').'</a></p></body></html>',
		'_email/booking_confirmed' => $lang.'<head><title>'.$key('confirmed_subject', 'Your booking is confirmed').'</title></head>'.$body.'</body></html>',
		'_email/booking_new' => $lang.'<head><title>'.$key('new_subject', 'A new booking').'</title></head>'.$body.'</body></html>',
	);
	// the rest of what render.booking.form sends, with a fixed subject each
	foreach (array('booking_declined', 'booking_cancelled', 'booking_changed') as $email) $views["_email/$email"] = $lang."<head><title>$email</title></head>".$body.'</body></html>';
	foreach ($views as $name => $html) {
		test_fixture("$theme/$name.html");
		file_put_contents("$theme/$name.html", $html);
	}
	$config = APPBASE.'config/the_app.php';
	$kept = file_get_contents($config);
	$i18n = APPBASE.'i18n';
	$made = !is_dir($i18n);
	$dictionaries = array();
	foreach (glob("$i18n/*/*.php") ?: array() as $file) $dictionaries[$file] = file_get_contents($file);
	$db = sys_get_temp_dir().'/raster-p4c-'.getmypid().'.sqlite';
	controller::forget_models();
	cms_records::forget();
	try {
		return $fn($db);
	} finally {
		file_put_contents($config, $kept);
		foreach (array_keys($views) as $name) @unlink("$theme/$name.html");
		foreach (glob("$i18n/*/*.php") ?: array() as $file) {
			if (!isset($dictionaries[$file])) unlink($file);
		}
		foreach ($dictionaries as $file => $source) file_put_contents($file, $source);
		foreach (glob("$i18n/*", GLOB_ONLYDIR) ?: array() as $dir) @rmdir($dir);
		if ($made) @rmdir($i18n);
		array_map('unlink', glob("$db*") ?: array());
		controller::forget_models();
		cms_records::forget();
	}
}
// the rows translation_status lists, every page of them
function p4c_status($mcp) {
	$rows = array();
	$offset = 0;
	do {
		$batch = $mcp->call('translation_status', array('lang' => 'ro', 'limit' => 50, 'offset' => $offset));
		$rows = array_merge($rows, $batch['missing']);
		$offset += 50;
	} while ($offset < $batch['total']);
	return $rows;
}
// the dining room, open every evening, and dinner there
function p4c_setup($mcp) {
	foreach (array(
		array('bookingresource', array('name' => 'Dining room', 'people' => 20)),
		array('bookinghours', array('resource' => 'dining-room', 'days' => 'daily', 'starts' => '19:00', 'ends' => '21:00', 'every' => 120)),
		array('bookingservice', array('name' => 'Dinner', 'minutes' => 120, 'resources' => 'dining-room')),
	) as list($collection, $fields)) {
		$made = $mcp->call('create_item', array('collection' => $collection, 'fields' => $fields));
		check(!isset($made['error']), "$collection: ".json_encode($made));
	}
}

test('P4.C: off, emails lint as before and describe counts nothing', function () use ($root) {
	require_once BASE.'tools/describe.php';
	$dir = "$root/application/views/default/_email";
	test_fixture("$dir/zzp4c.html");
	file_put_contents("$dir/zzp4c.html", '<html lang="en"><head><title>Fixed words</title></head><body><p>Fixed words</p></body></html>');
	try {
		foreach (p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what, $dir) {
				$problems = (new raster_inspector())->lint_path("$dir/zzp4c.html");
				same(array(), array_values(array_filter($problems, function ($p) { return strpos($p['message'], 'the same in every language') !== false || strpos($p['message'], 'names one language') !== false; })), $what);
				same(array(), (array)raster_describe::languages()['missing'], $what);
			});
		}
		// on, an email's fixed words and its <html lang> are warned about, as a page's are
		$on = with_settings(p2a_on(), function () use ($dir) { return json_encode((new raster_inspector())->lint_path("$dir/zzp4c.html")); });
		check(strpos($on, "'Fixed words' is the same in every language") !== false, $on);
		check(strpos($on, "names one language on every language's pages") !== false, $on);
	} finally {
		@unlink("$dir/zzp4c.html");
	}
});

test('P4.C: translation_status lists the keys only emails print as kind email, with the first email', function () {
	p4c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$mail = array_values(array_filter(p4c_status($mcp), function ($row) { return $row['where'] === 'zzp4c_mail'; }));
			usort($mail, function ($a, $b) { return strcmp($a['field'], $b['field']); });
			$row = function ($kind, $field, $text, $view = null) {
				return array('kind' => $kind, 'where' => 'zzp4c_mail', 'field' => $field) + ($view ? array('view' => $view) : array()) + array('default_value' => $text, 'length' => mb_strlen($text), 'shared' => false);
			};
			same(array(
				// the page prints it too: one strings row
				$row('strings', 'book', 'Book a table'),
				$row('email', 'confirmed_subject', 'Your booking is confirmed', '_email/booking_confirmed'),
				// several emails print it: the first, sorted
				$row('email', 'hello', 'Hello', '_email/booking_cancelled'),
				$row('email', 'new_subject', 'A new booking', '_email/booking_new'),
				$row('email', 'requested_subject', 'We have your booking', '_email/booking_requested'),
			), $mail);
			// counted once, in total and in describe's missing
			$total = $mcp->call('translation_status', array('lang' => 'ro'))['total'];
			same(array('ro' => $total), $mcp->call('describe', array('sections' => array('languages')))['languages']['missing']);
			// write_strings translates them like any key
			same(5, $mcp->call('write_strings', array('file' => 'zzp4c_mail', 'lang' => 'ro', 'strings' => array('book' => 'Rezervă o masă', 'confirmed_subject' => 'Rezervarea ta e confirmată',
				'hello' => 'Bună', 'new_subject' => 'O rezervare nouă', 'requested_subject' => 'Am primit rezervarea ta')))['written']);
			same(array(), array_values(array_filter(p4c_status($mcp), function ($row) { return $row['where'] === 'zzp4c_mail'; })));
			same($total - 5, $mcp->call('translation_status', array('lang' => 'ro'))['total']);
		});
	});
});

test('P4.C: what people typed into a booking is never a record row', function () {
	p4c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			p4c_setup($mcp);
			$made = $mcp->call('create_item', array('collection' => 'booking', 'fields' => array('name' => 'Ana Pop', 'email' => 'ana@example.test', 'date' => date('Y-m-d', strtotime('next tuesday')), 'time' => '19:00', 'people' => 2, 'resource' => 'dining-room', 'service' => 'dinner', 'notes' => 'By the window')));
			check(!isset($made['error']), json_encode($made));
			$records = array_values(array_filter(p4c_status($mcp), function ($row) { return $row['kind'] === 'record'; }));
			$collections = array_unique(array_map(function ($row) { return explode('/', $row['where'])[0]; }, $records));
			same(array(), array_values(array_diff($collections, array('bookingresource', 'bookingservice'))), json_encode($records));
			check(strpos(json_encode($records), 'By the window') === false && strpos(json_encode($records), 'Ana Pop') === false, json_encode($records));
		});
	});
});

test('P4.C: an agent translates the booking emails and service names over MCP, and translation_status reports 0 (needs P4.A, P4.B)', function () {
	global $maildir;
	p4c_site(function ($db) use ($maildir) {
		p2c_session(array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir-booking", 'RASTER_MAIL_FROM' => 'staff@zz.test'), function ($mcp) use ($db) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			p4c_setup($mcp);
			// 1. the emails' keys and the names, beside the rest
			$rows = p4c_status($mcp);
			$listed = array();
			foreach ($rows as $row) if (in_array($row['kind'], array('email', 'record'), true)) $listed[] = $row['kind'].' '.$row['where'].' '.$row['field'];
			sort($listed);
			// the starter's own emails (P5.A: their words are dictionary keys), then the booking's
			$starter = array();
			foreach (array('choose_a_new_password', 'hello', 'hi', 'if_it_wasn_t_you', 'if_you_didn_t_ask', 'please_confirm_that_you_want', 'please_confirm_your_subscription', 'reset_your_password', 'someone_asked_to_reset_the', 'yes_subscribe_me') as $key) $starter[] = "email emails $key";
			same(array_merge($starter, array('email zzp4c_mail confirmed_subject', 'email zzp4c_mail hello', 'email zzp4c_mail new_subject', 'email zzp4c_mail requested_subject', 'record bookingresource/1 name', 'record bookingservice/1 name')), $listed);
			// 2. everything translated the way its kind says
			$words = array('Dining room' => 'Sala de mese', 'Dinner' => 'Cină', 'Hello' => 'Bună', 'We have your booking' => 'Am primit rezervarea ta', 'Your booking is confirmed' => 'Rezervarea ta e confirmată');
			$pages = $items = $strings = array();
			foreach ($rows as $row) {
				$text = isset($words[$row['default_value']]) ? $words[$row['default_value']] : 'RO '.$row['default_value'];
				if ($row['kind'] === 'page' || $row['kind'] === 'site') $pages[$row['where']][$row['field']] = $text;
				elseif ($row['kind'] === 'item' || $row['kind'] === 'record') $items[$row['where']][$row['field']] = $text;
				else $strings[$row['where']][$row['field']] = $text;
			}
			foreach ($pages as $where => $fields) check(!isset($mcp->call('update_page', array('page' => $where, 'fields' => $fields, 'lang' => 'ro'))['error']), $where);
			foreach ($items as $where => $fields) {
				list($collection, $id) = explode('/', $where);
				$saved = $mcp->call('update_item', array('collection' => $collection, 'id' => (int)$id, 'fields' => $fields, 'lang' => 'ro'));
				check(!isset($saved['error']), "$where: ".json_encode($saved));
			}
			foreach ($strings as $file => $keys) same(count($keys), $mcp->call('write_strings', array('file' => $file, 'lang' => 'ro', 'strings' => $keys))['written'], $file);
			// 3. nothing left
			$status = $mcp->call('translation_status', array('lang' => 'ro'));
			same(0, $status['total'], json_encode($status['missing']));
			same(array('ro' => 0), $mcp->call('describe', array('sections' => array('languages')))['languages']['missing']);
			// 4. a booking made on /ro/ mails Romanian, its staff copy English,
			// and so does staff's confirmation over MCP, in English
			list($base, $log) = booking_server(array('RASTER_DB' => $db));
			$tue = date('Y-m-d', strtotime('next tuesday'));
			$mails = booking_mails(function () use ($base, $tue, $log) {
				list($status, $body) = form_post("$base/ro/zzp4c_book", array('raster_form' => 'booking.form', 'name' => 'Ana', 'email' => 'ana@example.test', 'date' => $tue, 'time' => '19:00', 'people' => '2', 'resource' => 'dining-room', 'service' => 'dinner'));
				same(303, $status, (string)@file_get_contents($log).substr(strip_tags((string)$body), 0, 1500));
			});
			same(array('Am primit rezervarea ta ana@example.test', 'A new booking staff@zz.test'), booking_sent($mails));
			check(strpos($mails[0]['text'], 'Bună Ana') !== false && strpos($mails[0]['text'], 'Cină Sala de mese') !== false, $mails[0]['text']);
			check(strpos($mails[1]['text'], 'Hello Ana') !== false && strpos($mails[1]['text'], 'Dinner Dining room') !== false, $mails[1]['text']);
			$booking = $mcp->call('list_items', array('collection' => 'booking'))['items'][0];
			$mails = booking_mails(function () use ($mcp, $booking) {
				$done = $mcp->call('run_action', array('collection' => 'booking', 'id' => $booking['id'], 'action' => 'confirm'));
				check(!isset($done['error']), json_encode($done));
			});
			same(array('Rezervarea ta e confirmată ana@example.test'), booking_sent($mails));
			check(strpos($mails[0]['text'], 'Bună Ana') !== false && strpos($mails[0]['text'], 'Cină Sala de mese') !== false, $mails[0]['text']);
		});
	});
});

test('P4.C: tools/list grows by the translation_status words only; the recipe stays under 120 words (#161)', function () {
	// translation_status's description says "record, email" (15 characters)
	// more than P3 left it; P2.C's and P3.D's budgets leave them out
	p4c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$tools = $mcp->message('tools/list')['result']['tools'];
			$status = array_values(array_filter($tools, function ($tool) { return $tool['name'] === 'translation_status'; }))[0];
			same('What a language lacks: page, site, item, record, email and dictionary words, with the default text.', $status['description']);
			$instructions = $mcp->message('initialize')['result']['instructions'];
			check(strpos($instructions, '_email/') !== false, 'the recipe says emails are translated too');
			preg_match('/Making a site multilingual.*$/s', $instructions, $m);
			check(str_word_count($m[0]) <= 120, 'the recipe is at most 120 words: '.str_word_count($m[0]));
		});
	});
});

// ## P4 review: frozen lang, more off comparisons, send by language

// $fn on a frozen connection, as production runs, put back after
function p4r_frozen($fn) {
	$was = database::$frozen;
	database::$frozen = true;
	R::freeze(true);
	cms_store::forget();
	try {
		return $fn();
	} finally {
		database::$frozen = $was;
		R::freeze($was);
		cms_store::forget();
	}
}

test('P4 review: a frozen database without the lang column still takes bookings and contact messages (D13)', function () {
	$on = array('multilingual' => true, 'languages' => array('en', 'ro'));
	$off = array('multilingual' => false, 'languages' => array());
	booking_site(function () use ($on, $off) {
		// the tables as a release before the column made them
		$date = with_settings($off, function () {
			cms_records::forget();
			$date = booking_cafe();
			cms_records::ensure(cms_records::info('booking'), true);
			return $date;
		});
		check(!array_key_exists('lang', cms_store::columns('bookingdata')), 'the fixture has no lang column');
		$booking = with_settings($on, function () use ($date) {
			cms_records::forget();
			return p4r_frozen(function () use ($date) {
				return i18n::with('ro', function () use ($date) { return booking_guest(array('date' => $date, 'time' => '19:00', 'people' => 2)); });
			});
		});
		check(!empty($booking['id']), 'the booking went in');
		check(!array_key_exists('lang', cms_store::columns('bookingdata')), 'and made no column');
		cms_records::forget();
	});
	contact_site(function () use ($on, $off) {
		with_settings($off, function () {
			cms_records::forget();
			cms_records::ensure(cms_records::info('contactmessage'), true);
		});
		$message = with_settings($on, function () {
			cms_records::forget();
			return p4r_frozen(function () {
				return i18n::with('ro', function () {
					return cms_store::save_item('contactmessagedata', 0, array('email' => 'frozen@example.test', 'message' => 'Bună'), cms_records::writable(cms_records::info('contactmessage')), 'editor');
				});
			});
		});
		check(!empty($message['id']), 'the message went in');
		check(!array_key_exists('lang', cms_store::columns('contactmessagedata')));
		cms_records::forget();
	});
});

// the password reset form, sent in this process for $email: the mails it sent
function p4r_forgot($email) {
	$template = template::instance();
	$kept = array($_SERVER, $_POST, $template->current_call, $template->forms);
	try {
		return booking_mails(function () use ($email, $template) {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
			$_POST = array('raster_form' => 'authentication.forgot', 'raster_ts' => stamp_for_tests(60), 'email' => $email);
			$template->current_call = 'authentication.forgot';
			$template->forms['authentication.forgot'] = array('email' => array('type' => 'email', 'required' => true));
			unset(controller::instance()->objects['validation']);
			spam_clear();
			(new authentication())->forgot();
		});
	} finally {
		list($_SERVER, $_POST, $template->current_call, $template->forms) = $kept;
		unset(controller::instance()->objects['validation']);
	}
}

test('P4 review: off, the password reset email is what it was, byte for byte (D13)', function () {
	cms_store::connect();
	$email = 'zzp4r-reset@example.test';
	$id = authentication::create_user($email, 'a long password', 'member', 'Ana');
	try {
		foreach (array('none' => array('multilingual' => false, 'languages' => array())) + p4_off_settings() as $what => $setting) {
			with_settings($setting, function () use ($what, $email, $id) {
				R::exec('UPDATE user SET reset_hash = NULL, reset_expires = NULL WHERE id = ?', array($id));
				$mails = p4r_forgot($email);
				same(1, count($mails), "$what: one mail");
				check(preg_match('/token=([a-f0-9]{48})/', $mails[0]['text'], $t) === 1, "$what: the link");
				// what 2.2.50 sent: the link and the name, as send_view gets them
				$then = booking_mails(function () use ($email, $t) {
					mail::send_view('_email/password_reset', $email, array('reset_url' => util::url(config::get('reset_page', 'reset'), array('token' => $t[1])), 'name' => 'Ana'));
				});
				same(p4a_shape($then[0]['raw']), p4a_shape($mails[0]['raw']), $what);
			});
		}
	} finally {
		R::exec('DELETE FROM user WHERE id = ?', array($id));
	}
});

test('P4 review: dates with a two-digit year are day first too, as PHP reads the century (D14)', function () {
	with_settings(array('multilingual' => true, 'languages' => array('en', 'ro')), function () {
		same('2026-10-05', cms_types::clean('date', '5/10/26', 'when'));
		same('1975-10-05', cms_types::clean('date', '5/10/75', 'when'));
		same('2026-10-05 19:30:00', cms_types::clean('datetime', '5/10/26 19:30', 'when'));
		same(null, cms_types::parse_date('31/02/26'));
	});
	with_settings(array('multilingual' => true, 'languages' => array('en-us', 'ro')), function () {
		same('2026-05-10', date('Y-m-d', cms_types::parse_date('5/10/26', 'en-us')), 'en-us');
	});
	with_settings(array('multilingual' => false, 'languages' => array()), function () {
		same('2026-05-10', cms_types::clean('date', '5/10/26', 'when'), 'no languages: as before');
	});
});

test('P4 review: raster send skips a language already sent, and refuses a language domain as the address (#200)', function () use ($root, $db, $maildir, $base, $p1b_on) {
	$theme = "$root/application/views/default";
	test_fixture("$theme/zzp4r_issue.html");
	file_put_contents("$theme/zzp4r_issue.html", '<!doctype html><html><head><title><!-- print.i18n.zzp1b(\'hello\') -->Hello<!-- /print.i18n.zzp1b(\'hello\') --></title></head><body>'
		.'<p>Issue</p><a href="<!-- print.newsletter.unsubscribe_url /-->">Unsubscribe</a></body></html>');
	$send = function ($args, $url = null) use ($db, $maildir, $root, $base) {
		$env = 'RASTER_DB='.escapeshellarg($db).' RASTER_MAIL='.escapeshellarg("log://$maildir").' RASTER_URL='.escapeshellarg($url ?: "$base/");
		return shell_exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' send '.$args.' 2>&1; echo "exit=$?"');
	};
	try {
		p4b_subscribers(function ($add) use ($p1b_on, $send) {
			$add('en@example.test', array('lang' => 'en'));
			$add('ro@example.test', array('lang' => 'ro'));
			p1b_site($p1b_on, function () use ($send) {
				$out = $send('/zzp4r_issue --lang=en');
				check(strpos($out, 'Sent "Hello" (en) to 1 subscriber(s)') !== false && strpos($out, 'exit=0') !== false, "en first: $out");
				// the run for every language: English is skipped, Romanian goes, and that is a success
				$out = $send('/zzp4r_issue');
				check(strpos($out, 'Skipped (en): /zzp4r_issue was already sent') !== false && strpos($out, 'Sent "Bună ziua" (ro) to 1 subscriber(s)') !== false && strpos($out, 'exit=0') !== false, "the rest: $out");
				// everything sent: nothing to do, as off
				$out = $send('/zzp4r_issue');
				check(strpos($out, 'already sent in every language') !== false && strpos($out, 'exit=1') !== false, "all sent: $out");
			});
			// RASTER_URL a language's domain: two languages, one address
			p1b_site($p1b_on."\nconfig::set('domain_language')->to(array('zzp4r.example.ro' => 'ro'));\nconfig::set('site_url')->to('http://zzp4r.example.com/');", function () use ($send) {
				$out = $send('/zzp4r_issue --again --dry-run', 'http://zzp4r.example.ro/');
				check(strpos($out, 'more than one language on this host') !== false && strpos($out, 'exit=2') !== false, "a language domain: $out");
			});
		});
	} finally {
		@unlink("$theme/zzp4r_issue.html");
		cms_store::connect();
		if (cms_store::table_exists('newsletterissue') && cms_store::table_exists('newsletterdelivery')) R::exec("DELETE FROM newsletterdelivery WHERE issue IN (SELECT id FROM newsletterissue WHERE url LIKE '%zzp4r_issue')");
		if (cms_store::table_exists('newsletterissue')) R::exec("DELETE FROM newsletterissue WHERE url LIKE '%zzp4r_issue'");
	}
});

// ## P5.B: doctor names missing translation columns

test('P5.B: doctor names the translation columns a multilingual site lacks, and says nothing of them off', function () use ($root) {
	$view = "$root/application/views/default/zzp5bdoctor.html";
	test_fixture($view);
	file_put_contents($view, '<html><head><title>D</title></head><body>'
		.'<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>'
		.'<p><!-- print.cms.seats -->14<!-- /print.cms.seats --></p></body></html>');
	$type = cms::page_type('/zzp5bdoctor');
	$schema = new raster_schema();
	$checks = function ($environment) use ($root) {
		$found = array();
		foreach (raster_project::doctor($root, 'application', $environment) as $c) {
			if (strpos($c['title'], 'language column') !== false) $found[] = $c;
		}
		return $found;
	};
	$changes = array();
	try {
		// the table as a one-language site makes it
		$changes = $schema->apply();
		same(array(), $checks('development'), 'off: nothing about translations');
		with_settings(array('languages' => array('en', 'ro')), function () use ($checks) {
			same(array(), $checks('development'), 'languages without multilingual: nothing either');
		});
		with_settings(p2a_on(), function () use ($checks, $schema, $type, &$changes) {
			$found = $checks('development');
			same(1, count($found), json_encode($found));
			same('warn', $found[0]['status']);
			check(preg_match('/^\d+ language column\(s\) missing$/', $found[0]['title']) === 1, $found[0]['title']);
			check(strpos($found[0]['detail'], "$type: headline__ro, saved_lang") !== false, $found[0]['detail']);
			check(strpos($found[0]['detail'], 'seats__ro') === false, 'a shared field has no translation');
			check(strpos($found[0]['detail'], 'php bin/raster schema --apply') !== false, 'says how to add them');
			same('fail', $checks('production')[0]['status'], 'production: a fail');
			// once --apply made them, this page is no longer named
			$changes = array_merge($changes, $schema->apply());
			foreach ($checks('development') as $c) check(strpos($c['detail'], $type) === false, $c['detail']);
		});
	} finally {
		@unlink($view);
		p2a_undo($changes, array($type));
	}
});

// ## Final review: the starter as 2.2.45 made it, its words, lang on frozen records

// a new site (raster new) renders its pages byte for byte as 2.2.45's did:
// tests/expected/starter-2.2.45/ holds what v2.2.45 rendered, with the form
// stamp and the asset versions blanked; the html tag's dir="ltr" is allowed
test('Final review: a new starter site renders its pages as v2.2.45 did, byte for byte', function () use ($root) {
	$tmp = sys_get_temp_dir().'/raster-final-'.getmypid();
	@mkdir($tmp, 0775, true);
	$site = "$tmp/site";
	exec(php_cmd().' '.escapeshellarg("$root/bin/raster").' new '.escapeshellarg($site).' 2>&1', $out, $code);
	same(0, $code, implode("\n", $out));
	try {
		$pages = array('/' => 'index', '/about' => 'about', '/news' => 'news', '/news/news_item/hello-world' => 'news_news_item_hello-world', '/login' => 'login', '/register' => 'register', '/forgot' => 'forgot', '/reset' => 'reset', '/newsletter-confirm' => 'newsletter-confirm', '/newsletter-unsubscribe' => 'newsletter-unsubscribe');
		foreach ($pages as $page => $file) {
			$db = "$tmp/".md5($page).'.sqlite';
			@unlink($db);
			$env = 'RASTER_APP=application RASTER_ENV= RASTER_DB='.escapeshellarg($db).' RASTER_URL=http://localhost:8000/';
			$now = shell_exec('cd '.escapeshellarg($site).' && '.$env.' '.php_cmd().' bin/raster render '.escapeshellarg($page).' 2>&1');
			@unlink($db);
			$now = preg_replace(array('/name="raster_ts" value="[^"]*"/', '/\?v=[0-9a-f]{8}/'), array('name="raster_ts" value=""', '?v='), $now);
			check(substr_count($now, '<html lang="en" dir="ltr">') === 1, "$page: ".substr($now, 0, 300));
			same(file_get_contents("$root/tests/expected/starter-2.2.45/$file.html"), str_replace('<html lang="en" dir="ltr">', '<html lang="en">', $now), "$page byte for byte");
		}
	} finally {
		remove_dir($tmp);
	}
});

// spec #200 R9: every visible word of the starter is a CMS field or a
// dictionary key, so turning languages on leaves nothing untranslatable
test('Final review: the starter with multilingual on has no untranslatable words, and translation_status lists its fixed words as strings', function () {
	$untranslatable = function () {
		$found = array();
		foreach ((new raster_inspector())->lint() as $p) {
			if (strpos($p['file'], 'zzp2c') !== false) continue;
			if (strpos($p['message'], 'is the same in every language') !== false || strpos($p['message'], 'more line(s) of this view') !== false) $found[] = $p['file'].':'.$p['line'].' '.$p['message'];
		}
		return $found;
	};
	same(array(), with_settings(p2a_on(), $untranslatable));
	p2c_site(function ($db) {
		p2c_session(array('RASTER_DB' => $db), function ($mcp) {
			$mcp->call('set_languages', array('languages' => array('en', 'ro')));
			$status = $mcp->call('translation_status', array('lang' => 'ro', 'limit' => 1000));
			$strings = array();
			foreach ($status['missing'] as $row) if ($row['kind'] === 'strings') $strings[] = $row['where'].' '.$row['field'];
			foreach (array('common home', 'common subscribe', 'common get_new_posts_by_email', 'index latest_news', 'login log_in', 'register create_an_account', 'account your_account', 'reset save_password') as $want) check(in_array($want, $strings, true), "$want: ".json_encode($strings));
		});
	});
});

// a bundled record's lang column is left to schema --apply on a frozen
// database and kept when multilingual is off again; a site's own record
// field called lang is like any other field: no column, an orphan
test('Final review: a site\'s own record table with a lang column it no longer declares is an orphan, multilingual off', function () {
	p3a_model(function () {
		cms_store::connect();
		cms_types::ensure('zzp3abookdata', array('name' => 'text', 'note' => 'text', 'lang' => 'text', 'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime', 'created_at' => 'datetime'));
		cms_store::forget();
		$status = with_settings(array('multilingual' => false, 'languages' => array('en', 'ro')), function () { return (new raster_schema())->status(); });
		$table = null;
		foreach ($status['tables'] as $row) if ($row['table'] === 'zzp3abookdata') $table = $row;
		check($table !== null, 'zzp3abookdata is listed');
		check(in_array('lang', $table['orphans'], true), 'lang is an orphan: '.json_encode($table));
		check(!isset($status['translations']['zzp3abookdata']) || !in_array('lang', $status['translations']['zzp3abookdata'], true), 'and not a kept translation');
	});
});

// ## 2.2.52: items found by their language's slug

// the events table with its Romanian columns, as a multilingual site's
// development makes them, and events with a Romanian title (so a slug__ro)
// and without one
function v2252_events() {
	return with_settings(p2a_on(), function () {
		cms_types::ensure('eventsdata', array('title' => 'text', 'date' => 'date', 'starts' => 'time', 'places' => 'int', 'rsvp_until' => 'datetime',
			'slug' => 'text', 'enabled' => 'bool', 'published_at' => 'datetime', 'updated_at' => 'datetime'), array('title', 'slug'));
		cms_store::forget();
		$poetry = rsvp_event(array('title' => 'Poetry evening', 'places' => 4));
		cms_store::save_item('eventsdata', $poetry['id'], array('title' => 'Seara de poezie'), array('title'), 'editor', 'events', 'ro');
		$quiz = rsvp_event(array('title' => 'Quiz night'));
		cms_store::forget();
		$poetry = R::getRow('SELECT * FROM eventsdata WHERE id = ?', array($poetry['id']));
		same(array('poetry-evening', 'seara-de-poezie'), array($poetry['slug'], $poetry['slug__ro']));
		return array($poetry, R::getRow('SELECT * FROM eventsdata WHERE id = ?', array($quiz['id'])));
	});
}

test('2.2.52: an event\'s page on /ro/, at its Romanian slug, takes a reply; the English page and a /ro/ page at the default slug too', function () use ($booking_base, $p1b_on) {
	p1b_site($p1b_on, function () use ($booking_base) {
		rsvp_site(function () use ($booking_base) {
			spam_clear();
			$history = cms_store::table_exists('slughistory');
			try {
				list($poetry, $quiz) = v2252_events();
				$pages = array(
					'ro' => array("$booking_base/ro/events/events_item/seara-de-poezie", $poetry, 'ana.ro'),
					'en' => array("$booking_base/events/events_item/poetry-evening", $poetry, 'ana.en'),
					'ro at the default slug' => array("$booking_base/ro/events/events_item/quiz-night", $quiz, 'ana.quiz'),
				);
				foreach ($pages as $what => $page) {
					list($url, $event, $who) = $page;
					list($status, $body) = http('GET', $url);
					same(200, $status, "$what: $body");
					check(strpos($body, 'ALERT not_open') === false && strpos($body, "event={$event['id']} ") !== false, "$what: the page's event: $body");
					list($status, , $location) = rsvp_post($url, array('name' => 'Ana', 'email' => "$who@example.test"));
					same(array(303, "$url?done=going"), array($status, $location), $what);
					$reply = cms_records::find('rsvp', array('email' => "$who@example.test"));
					same(array(1, (int)$event['id'], 'going'), array(count($reply), $reply ? (int)$reply[0]['event'] : null, $reply ? $reply[0]['status'] : null), $what);
				}
				same('ro', cms_records::find('rsvp', array('email' => 'ana.ro@example.test'))[0]['lang'], 'the reply made on /ro/ is Romanian');
			} finally {
				if (!$history) R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS slughistory');
				cms_store::forget();
			}
		});
	});
});

test('2.2.52: cms_store::item_at and item_id find an item by its language\'s slug, as render.cms does; rsvp::page_event uses them', function () {
	rsvp_site(function () {
		$history = cms_store::table_exists('slughistory');
		try {
			list($poetry, $quiz) = v2252_events();
			$at = function ($key, $lang) { return with_settings(p2a_on() + array('url_language' => $lang === 'en' ? '' : $lang), function () use ($key) { cms_store::$slug_items = array(); return cms_store::item_at('eventsdata', $key); }); };
			same(array('id' => (int)$poetry['id']), $at('seara-de-poezie', 'ro'), 'its Romanian slug');
			same(array('id' => (int)$quiz['id']), $at('quiz-night', 'ro'), 'no Romanian slug: its default one');
			same(array('slug' => 'poetry-evening'), $at('poetry-evening', 'ro'), 'the English slug of one with a Romanian slug: render.cms finds it by its default slug (a GET is answered 301 first)');
			same(array('slug' => 'seara-de-poezie'), $at('seara-de-poezie', 'en'), 'in English a Romanian slug is a slug no item has');
			same(array('slug' => 'poetry-evening'), $at('poetry-evening', 'en'));
			same(array('id' => (int)$quiz['id']), $at((string)$quiz['id'], 'en'), 'a number no item has as its slug is an id');
			foreach (array('ro' => array('seara-de-poezie', $poetry), 'en' => array('poetry-evening', $poetry)) as $lang => $case) {
				$id = with_settings(p2a_on() + array('url_language' => $lang === 'en' ? '' : $lang, 'uri_string' => '/events/events_item/'.$case[0]), function () {
					cms_store::$slug_items = array();
					rsvp::forget();
					$event = rsvp::page_event();
					return $event ? $event['id'] : null;
				});
				same((int)$case[1]['id'], $id, "page_event in $lang");
			}
			same((int)$quiz['id'], cms_store::item_id('eventsdata', 'quiz-night'));
			same(null, cms_store::item_id('eventsdata', 'nothing-here'));
		} finally {
			if (!$history) R::getDatabaseAdapter()->exec('DROP TABLE IF EXISTS slughistory');
			cms_store::forget();
			rsvp::forget();
		}
	});
});

test('2.2.52: multilingual off, rsvp::page_event and an item URL\'s lookup send the SQL 2.2.51 sent', function () {
	rsvp_site(function () {
		$event = rsvp_event(array('title' => 'Poetry evening'));
		foreach (array('off' => array('multilingual' => false, 'languages' => array()), 'languages, switch off' => array('multilingual' => false, 'languages' => array('en', 'ro'))) as $what => $off) {
			with_settings($off + array('uri_string' => '/events/events_item/poetry-evening'), function () use ($what, $event) {
				rsvp::forget();
				$now = p2a_sql(function () use ($event) { same((int)$event['id'], rsvp::page_event()['id']); });
				// 2.2.51's page_event: the default slug, then the item
				rsvp::forget();
				$was = p2a_sql(function () use ($event) {
					$bean = cms_store::table_exists('eventsdata') ? R::findOne('eventsdata', ' slug = ? ', array('poetry-evening')) : null;
					same((int)$event['id'], (int)$bean->id);
					rsvp::item((int)$bean->id);
				});
				same($was, $now, "$what: page_event");
				check(!preg_grep('/slug__|slughistory/', $now), "$what: ".implode(' | ', $now));
				// a number: the item at that slug first, then the number as an id
				foreach (array('99999', (string)$event['id']) as $number) {
					$now = with_settings(array('uri_string' => "/events/events_item/$number"), function () use ($number) {
						rsvp::forget();
						return p2a_sql(function () { rsvp::page_event(); });
					});
					rsvp::forget();
					$was = p2a_sql(function () use ($number) {
						$bean = cms_store::table_exists('eventsdata') ? R::findOne('eventsdata', ' slug = ? ', array($number)) : null;
						rsvp::item($bean ? (int)$bean->id : (int)$number);
					});
					same($was, $now, "$what: page_event at $number");
				}
				// render.cms: a slug is a filter, no query of its own; a number
				// is asked for as a slug first, with 2.2.51's query
				same(array(), p2a_sql(function () { same(array('slug' => 'poetry-evening'), cms_store::item_at('eventsdata', 'poetry-evening')); }), "$what: item_at, a slug");
				$columns = cms_store::columns('eventsdata');
				same(array('SELECT COUNT(*) FROM `eventsdata` WHERE slug = ?'), array_values(preg_grep('/^SELECT/', p2a_sql(function () use ($columns) { same(array('id' => 7), cms_store::item_at('eventsdata', '7', null, $columns)); }))), "$what: item_at, a number");
			});
		}
		rsvp::forget();
	});
});

// one way to find an item from its address: a bundled model asks
// cms_store::item_at() / item_id(), and no model but the CMS writes a slug
// lookup of its own (nor shares one with another model)
test('2.2.52: no bundled model but cms finds an item by a slug query of its own', function () use ($root) {
	$found = array();
	foreach (glob("$root/system/models/*", GLOB_ONLYDIR) as $dir) {
		if (basename($dir) === 'cms') continue;
		$files = array_merge(glob("$dir/*.php"), glob("$dir/sql/*.sql") ?: array());
		foreach ($files as $file) {
			// the whole file, so a call split over lines is seen too
			$code = file_get_contents($file);
			// SQL comparing a slug column (slug, slug__ro; not $slug = or
			// ->slug =), a find with a slug filter, or the CMS's own slug calls
			$lookup = '/(?<![$>\w])slug(__[a-z_]+)?`?\s*(=(?![=>])|LIKE\b|GLOB\b|IN\b)|find(One|All)?\s*\([^;]*?[\'"]slug(__[a-z_]+)?[\'"]\s*=>|slug_item\s*\(|resolve_slug\s*\(/s';
			if (preg_match_all($lookup, $code, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[0] as $hit) $found[] = substr($file, strlen($root) + 1).':'.(substr_count(substr($code, 0, $hit[1]), "\n") + 1).': '.trim(preg_replace('/\s+/', ' ', $hit[0]));
			}
		}
	}
	same(array(), $found);
	// the CMS's own list and rsvp ask the one method
	check(strpos(file_get_contents("$root/system/models/cms/cms.php"), 'cms_store::item_at(') !== false, 'cms::collection asks item_at');
	check(strpos(file_get_contents("$root/system/models/rsvp/rsvp.php"), 'cms_store::item_id(') !== false, 'rsvp::page_event asks item_id');
	same(1, substr_count(file_get_contents("$root/system/models/cms/cms.php").file_get_contents("$root/system/models/cms/store.php"), 'WHERE slug = ?\', array($key)'), 'one number-is-a-slug query');
});

test('2.2.52: multilingual, /ro/login shows the layout\'s site fields in Romanian; off, /login keeps the template\'s words as it always did', function () use ($p1b_on) {
	cms_store::connect();
	$made = !cms_store::table_exists('sitepage');
	if ($made) cms_types::ensure('sitepage', array('slug' => 'text', 'site_name' => 'text', 'updated_at' => 'datetime'));
	$had = array_key_exists('site_name__ro', cms_store::columns('sitepage'));
	if (!$had) cms_types::ensure('sitepage', array('site_name__ro' => 'text'));
	cms_store::forget();
	$row = R::dispense('sitepage');
	$row->slug = 'site';
	$row->site_name = 'Zz2252 Cafe';
	$row->site_name__ro = 'Zz2252 Cafeneaua';
	$row->updated_at = R::isoDateTime();
	R::store($row);
	try {
		p1b_site($p1b_on, function () {
			p1b_server(array(), function ($port) {
				list($status, $body) = http('GET', "http://127.0.0.1:$port/ro/login");
				same(200, $status);
				check(strpos($body, 'Zz2252 Cafeneaua') !== false, '/ro/login: '.$body);
				check(strpos($body, 'raster-editor') === false, 'no editor on the login page');
				list(, $body) = http('GET', "http://127.0.0.1:$port/login");
				check(strpos($body, 'Zz2252 Cafe') !== false && strpos($body, 'Cafeneaua') === false, '/login: '.$body);
			});
		});
		p1b_site("config::set('languages')->to(array('en', 'ro'));", function () {
			p1b_server(array(), function ($port) {
				list($status, $body) = http('GET', "http://127.0.0.1:$port/login");
				same(200, $status);
				check(strpos($body, 'Zz2252') === false, 'off: the template\'s words: '.$body);
			});
		});
	} finally {
		R::trash($row);
		if ($made) R::exec('DROP TABLE IF EXISTS sitepage');
		elseif (!$had) R::exec('ALTER TABLE sitepage DROP COLUMN site_name__ro');
		cms_store::forget();
	}
});

// an alert's words are shown as written: lint reads them (multilingual
// on), and a dictionary key inside the alert says them in each language
test('2.2.52: lint, multilingual on, reports the words of an alert and of a field message, and suggests a dictionary key inside the alert; off, nothing', function () {
	$view = '<!doctype html><!-- print.@lang.i18n.language --><html lang="en"><!-- /print.@lang.i18n.language --><body>'
		."\n<!-- print.validation.alert('sent') --><p class=\"notice\">Thanks, we read every one.</p><!-- /print.validation.alert('sent') -->"
		."\n<!-- print.validation.alert('done') --><p><!-- print.i18n.zz2252('done') -->Done.<!-- /print.i18n.zz2252('done') --></p><!-- /print.validation.alert('done') -->"
		."\n<!-- render.zz2252.form --><form method=\"post\"><input name=\"x\" required>\n<!-- render.validation.field('x') --><p>Fill it in.</p><!-- /render.validation.field('x') --></form><!-- /render.zz2252.form -->"
		."\n<!-- print.if.live --><p class=\"notice\">Live words.</p><!-- /print.if.live --></body></html>";
	$said = function ($problems) {
		$words = array();
		foreach ($problems as $p) if (strpos($p['message'], 'is the same in every language') !== false) $words[$p['line']] = $p['message'];
		return $words;
	};
	$on = with_settings(p2a_on(), function () use ($view, $said) { return $said((new raster_inspector())->lint_source($view, 'view', 'default')); });
	// the alert, the field's message, and what print.if shows (as before)
	same(array(2, 5, 6), array_keys($on), json_encode($on));
	check(strpos($on[2], "'Thanks, we read every one.'") === 0 && strpos($on[2], "an alert shows its words as written") !== false && strpos($on[2], "<!-- print.validation.alert('…') --><p><!-- print.i18n.<file>('key') -->") !== false, $on[2]);
	check(strpos($on[5], "'Fill it in.'") === 0 && strpos($on[5], 'no annotation prints it') !== false, $on[5]);
	foreach (p4_off_settings() as $what => $setting) {
		same(array(), with_settings($setting, function () use ($view, $said) { return $said((new raster_inspector())->lint_source($view, 'view', 'default')); }), $what);
	}
});

test('2.2.52: lint warns about an alert name a view has twice with different words, always; the same words twice are fine', function () {
	$twice = "<!-- print.validation.alert('name_invalid') --><p>Tell us your name.</p><!-- /print.validation.alert('name_invalid') -->\n"
		."<!-- print.validation.alert('name_invalid') --><p>That name has characters we can't use.</p><!-- /print.validation.alert('name_invalid') -->\n";
	$found = function ($html) {
		return array_values(array_filter((new raster_inspector())->lint_source($html, 'view', 'default'), function ($p) { return strpos($p['message'], 'twice with different words') !== false; }));
	};
	foreach (array('off' => array('multilingual' => false, 'languages' => array()), 'on' => p2a_on()) as $what => $setting) {
		with_settings($setting, function () use ($twice, $found, $what) {
			$problems = $found($twice);
			same(1, count($problems), $what);
			same(array('warning', 2), array($problems[0]['severity'], $problems[0]['line']), $what);
			same(array(), $found(str_replace("That name has characters we can't use.", 'Tell us your name.', $twice)), "$what: the same words");
		});
	}
});

// the starter's alerts are dictionary keys: with multilingual on a fresh
// site has no words lint calls untranslatable (the Final review test), and
// its pages render as v2.2.45's did (the byte-for-byte test)
test('2.2.52: every alert of the starter and of the demo\'s visitor pages is worded through a dictionary key', function () use ($root) {
	$plain = array();
	foreach (array('application/views/default', 'demo/views/cafe') as $dir) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen("$root/"));
			if (substr($path, -5) !== '.html') continue;
			// the demo's staff pages and the lab stay English (demo/README.md)
			if (strpos($path, 'demo/') === 0 && preg_match('#/(staff/.*|staff|lab|[a-z]+_item)\.html$#', $path) && !preg_match('#/(menu|events|journal|faq)_item\.html$#', $path)) continue;
			preg_match_all("/<!-- print\.validation\.alert\('([a-z0-9_]+)'\) -->(.*?)<!-- \/print\.validation\.alert\('\\1'\) -->/s", file_get_contents($file->getPathname()), $alerts, PREG_SET_ORDER);
			foreach ($alerts as $alert) {
				$words = trim(strip_tags(preg_replace('/<!-- print\.i18n\.([a-z0-9_]+)\(\'([a-z0-9_.-]+)\'\) -->.*?<!-- \/print\.i18n\.\1\(\'\2\'\) -->|<code>.*?<\/code>/s', '', $alert[2])));
				if (preg_match('/\p{L}{2,}/u', $words)) $plain[] = "$path {$alert[1]}: $words";
			}
		}
	}
	same(array(), $plain);
});

// the starter's alerts, now dictionary keys, print 2.2.51's bytes when
// they show, multilingual off
test('2.2.52: a new starter site prints its alerts with 2.2.51\'s bytes', function () use ($root) {
	$tmp = sys_get_temp_dir().'/raster-2252-'.getmypid();
	@mkdir($tmp, 0775, true);
	$site = "$tmp/site";
	exec(php_cmd().' '.escapeshellarg("$root/bin/raster").' new '.escapeshellarg($site).' 2>&1', $out, $code);
	same(0, $code, implode("\n", $out));
	try {
		$alerts = array(
			'/login?done=login_failed' => '<!-- print.validation.alert(\'login_failed\') --><p class="error">Wrong email or password.</p><!-- /print.validation.alert(\'login_failed\') -->',
			'/reset?done=reset_invalid' => '<p class="error">This link has expired or was already used. <a href="http://localhost:8000/forgot">Get a new one</a>.</p>',
			'/register?done=email_taken' => '<p class="error">There is already an account with that email. <a href="http://localhost:8000/login">Log in</a> instead?</p>',
			'/newsletter-confirm?done=confirmed' => '<h1>You are subscribed</h1><p>Thanks for confirming. The next issue will reach you by email.</p>',
			'/?done=check_email' => '<p class="notice">Almost there: check your inbox to confirm.</p>',
		);
		foreach ($alerts as $page => $bytes) {
			$db = "$tmp/".md5($page).'.sqlite';
			$env = 'RASTER_APP=application RASTER_ENV= RASTER_DB='.escapeshellarg($db).' RASTER_URL=http://localhost:8000/';
			$now = shell_exec('cd '.escapeshellarg($site).' && '.$env.' '.php_cmd().' bin/raster render '.escapeshellarg($page).' 2>&1');
			@unlink($db);
			// 2.2.51 printed the alert's block as it is in the view, its annotations gone
			$bytes = preg_replace('/<!-- \/?print\.validation\.alert\(\'[a-z_]+\'\) -->/', '', $bytes);
			check(strpos($now, "    $bytes\n") !== false || strpos($now, "  $bytes\n") !== false || strpos($now, "      $bytes\n") !== false, "$page: $bytes in ".$now);
			check(strpos($now, 'print.i18n') === false, "$page: no annotation left");
		}
	} finally {
		remove_dir($tmp);
	}
});

echo "\n\n$passed passed, ".count($failed)." failed".($skipped ? ', '.count($skipped).' skipped' : '')."\n";
foreach ($skipped as $skip) echo "  - $skip\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
