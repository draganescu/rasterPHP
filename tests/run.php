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
// every new site starts from this theme, so its CSS carries no look to drift back to (raster-builder#161)
test('the starter style is structure only', function () use ($root) {
	$css = file_get_contents("$root/application/views/default/style.css");
	check(!preg_match('/#[0-9a-f]{3,8}\b|\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix)\(/i', $css, $m), 'a colour in the starter: '.($m[0] ?? ''));
	check(!preg_match('/(^|[;{\s])(color|background(-color)?|border(-[a-z]+)?-color)\s*:/i', $css, $m), 'the starter sets a colour: '.($m[0] ?? ''));
	check(stripos($css, 'prefers-color-scheme') === false, 'the starter picks a dark mode');
	check(!preg_match('/(^|[}\s,])(main|\.site-header|\.site-footer|\.hero|\.features?)\s*[{,]/', $css, $m), 'the starter lays out the page: '.trim($m[0] ?? ''));
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
	check(strpos($body, '<h1>Write HTML. Get a CMS.</h1>') !== false);
	check(strpos($body, 'Placeholder') === false, 'remove block leaked');
	check(strpos($body, 'href="'.$base.'/news/news_item/raster-runs-on-php-8"') !== false, 'detail link');
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
			same(array('error' => 'cross-site'), json_decode($body, true), "GET with $header");
		}
		list($status, $body) = http('DELETE', "$base/api/zzstaff/close_day", null, array("Cookie: $cookie", 'Origin: https://evil.test'));
		same(403, $status, 'DELETE');
		same(array('error' => 'cross-site'), json_decode($body, true), 'DELETE');
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
	same('int', strtolower($pdo->query("SHOW COLUMNS FROM user LIKE 'failed_count'")->fetch(PDO::FETCH_ASSOC)['Type']));
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
		same('int', strtolower($pdo->query("SHOW COLUMNS FROM newsletterissue LIKE 'recipients'")->fetch(PDO::FETCH_ASSOC)['Type']));
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
	check(strpos($body, 'Ünïcode') < strpos($body, 'Raster runs on PHP 8'), 'order=newest');
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
	same(404, http('GET', "$base/zzz/news_item/raster-runs-on-php-8")[0]);
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
			check(strpos($get($path)[1], 'Raster runs on PHP 8') !== false, "$path lists the item");
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
	check(strpos($body, '404 Not Found') !== false, $body);
	check(strpos($body, 'Warning') === false && strpos($body, 'session') === false, $body);
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
		check(strpos($body, 'Write HTML. Get a CMS.') !== false, 'template default');
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
	$raster = function ($args) use ($env, $root) {
		exec("$env ".php_cmd().' '.escapeshellarg("$root/bin/raster").' '.$args.' 2>&1', $out, $code);
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
	echo '['.array_sum(array_map('count', $links)).' confirmations for 25 addresses] ';
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

echo "\n\n$passed passed, ".count($failed)." failed".($skipped ? ', '.count($skipped).' skipped' : '')."\n";
foreach ($skipped as $skip) echo "  - $skip\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
