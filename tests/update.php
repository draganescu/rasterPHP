<?php
// Tests for new projects, updates, upgrades and the doctor: php tests/update.php
//
// Builds releases in a temporary folder (a "2.0.0" with a file that later
// goes away, and a "2.1.0" with a changed file, a new one and an upgrade
// step), makes a site from the first and updates it to the second.

if (PHP_SAPI !== 'cli') exit;

$repo = dirname(__DIR__);
require __DIR__.'/php.php';
$tmp = sys_get_temp_dir().'/raster-update-test-'.getmypid();
mkdir($tmp, 0775, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf '.escapeshellarg($tmp)); });

$passed = 0; $failed = array();
function test($name, $fn) {
	global $passed, $failed;
	// RASTER_TEST_ONLY=<words> runs only the tests whose name has them
	if (getenv('RASTER_TEST_ONLY') && stripos($name, getenv('RASTER_TEST_ONLY')) === false) return;
	try { $fn(); $passed++; echo '.'; }
	catch (Throwable $e) { $failed[] = "$name: ".$e->getMessage().' (line '.$e->getLine().')'; echo 'F'; }
}
function check($condition, $message = 'assertion failed') { if (!$condition) throw new Exception($message); }
function same($expected, $actual, $message = '') {
	if ($expected !== $actual) throw new Exception(trim($message.' expected '.var_export($expected, true).', got '.var_export($actual, true)));
}
function has($haystack, $needle, $message = '') { if (strpos((string)$haystack, $needle) === false) throw new Exception(trim($message.' missing: '.$needle)); }
function lacks($haystack, $needle, $message = '') { if (strpos((string)$haystack, $needle) !== false) throw new Exception(trim($message.' unexpected: '.$needle)); }
// runs bin/raster in a project folder
function raster($dir, $args, $env = array()) {
	$env = array_merge(getenv(), array('RASTER_APP' => 'application', 'RASTER_DB' => "$dir/application/data/test.sqlite", 'NO_COLOR' => '1'), $env);
	foreach ($env as $key => $value) if ($value === null) unset($env[$key]);
	$process = proc_open(array_merge(php_argv("$dir/bin/raster"), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir, $env);
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	return array(proc_close($process), $out);
}
function append($file, $text) { file_put_contents($file, file_get_contents($file).$text); }

$rel200 = "$tmp/rel200";
$rel210 = "$tmp/rel210";
$site = "$tmp/site";

test('raster new', function () use ($repo, $rel200, $site) {
	list($code, $out) = raster($repo, array('new', $rel200));
	same(0, $code, $out);
	has($out, 'Created a Raster '.trim(file_get_contents("$repo/system/VERSION")).' site');
	// the releases here are made up: "2.0.0" is this copy of Raster, labelled
	// so, and ships a file that "2.1.0" drops
	file_put_contents("$rel200/system/VERSION", "2.0.0\n");
	file_put_contents("$rel200/system/obsolete.txt", "gone in 2.1.0\n");
	list($code, $out) = raster($rel200, array('new', $site));
	same(0, $code, $out);
	foreach (array('system/boot.php', 'system/VERSION', 'bin/raster', 'index.php', '.htaccess', 'AGENTS.md', 'CLAUDE.md', '.mcp.json', 'media/.gitignore', 'application/config/the_app.php', 'application/views/default/index.html', 'application/data/.gitignore', 'system/checksums.json', 'system/obsolete.txt') as $file) {
		check(file_exists("$site/$file"), "missing $file");
	}
	check(!file_exists("$site/application/views/test"), 'the old documentation theme');
	check(!file_exists("$site/demo") && !file_exists("$site/tests"), 'only the framework and the starter app');
	same(array('.gitignore'), array_values(array_diff(scandir("$site/application/data"), array('.', '..'))), 'no data copied');
	same("2.0.0\n", file_get_contents("$site/application/config/raster-version"));
	check(is_executable("$site/bin/raster"));
	same(1, raster($repo, array('new', $site))[0], 'refuses a folder that is not empty');
});

test('a new site works', function () use ($site) {
	same(0, raster($site, array('lint'))[0]);
	list($code, $out) = raster($site, array('render', '/'));
	same(0, $code, $out);
	has($out, '</html>');
	list($code, $out) = raster($site, array('doctor'));
	same(0, $code, $out);
	has($out, '✓ Raster 2.0.0');
	has($out, '✓ Framework files as installed');
	lacks($out, 'deprecated');
	list($code, $out) = raster($site, array('version'));
	has($out, 'Raster 2.0.0');
	has($out, 'application/ is at 2.0.0');
});

test('a 2.1.0 release', function () use ($rel200, $rel210) {
	list($code, $out) = raster($rel200, array('new', $rel210));
	same(0, $code, $out);
	unlink("$rel210/system/obsolete.txt");
	file_put_contents("$rel210/system/VERSION", "2.1.0\n");
	append("$rel210/system/util.php", "\n// changed in 2.1.0\n");
	file_put_contents("$rel210/system/tools/hello.php", "<?php // new in 2.1.0\n");
	file_put_contents("$rel210/system/upgrades/2.1.0.php", '<?php return array("marker" => array(
		"description" => "a file every 2.1.0 app has",
		"needed" => function ($c) { return !is_file($c["app"]."/config/marker.txt"); },
		"apply" => function ($c) { file_put_contents($c["app"]."/config/marker.txt", "upgraded\n"); return "wrote marker.txt"; },
	));');
});

test('update --dry-run changes nothing', function () use ($site, $rel210) {
	// a typo of --dry-run is refused, not taken as an update
	list($code, $out) = raster($site, array('update', $rel210, '--dryrun'));
	same(2, $code, $out);
	has($out, "update doesn't take --dryrun");
	same("2.0.0\n", file_get_contents("$site/system/VERSION"));
	same(2, raster($site, array('upgrade', '--dryrun'))[0]);
	list($code, $out) = raster($site, array('update', $rel210, '--dry-run'));
	same(0, $code, $out);
	has($out, 'Raster 2.0.0 → 2.1.0: 2 changed, 2 new, 1 removed file(s)', $out);
	has($out, '~ system/VERSION');
	has($out, '~ system/util.php');
	has($out, '+ system/tools/hello.php');
	has($out, '+ system/upgrades/2.1.0.php');
	has($out, '- system/obsolete.txt');
	has($out, 'upgrade steps for 2.1.0 will run');
	same("2.0.0\n", file_get_contents("$site/system/VERSION"));
	check(is_file("$site/system/obsolete.txt"));
});

test('edited framework files stop an update', function () use ($site, $rel210) {
	append("$site/system/controller.php", "\n// my local fix\n");
	list($code, $out) = raster($site, array('doctor'));
	has($out, '! Framework files were edited');
	has($out, 'changed: system/controller.php');
	list($code, $out) = raster($site, array('update', $rel210));
	same(1, $code, $out);
	has($out, 'changed  system/controller.php');
	has($out, 'Nothing was changed');
	same("2.0.0\n", file_get_contents("$site/system/VERSION"));
});

test('update --force', function () use ($site, $rel210) {
	append("$site/application/views/default/about.html", "\n<!-- my page -->\n");
	// a page cached before the update
	$cache = "$site/application/data/cache";
	if (!is_dir($cache)) mkdir($cache, 0775, true);
	file_put_contents("$cache/version", 'before');
	$page = "$cache/".sha1('a page');
	file_put_contents($page, "{}\n<p>old</p>");
	list($code, $out) = raster($site, array('update', $rel210, '--force'));
	same(0, $code, $out);
	has($out, 'Updated to Raster 2.1.0');
	has($out, '✓ 2.1.0 marker: a file every 2.1.0 app has (wrote marker.txt)');
	has($out, 'application/ is at Raster 2.1.0');
	same("2.1.0\n", file_get_contents("$site/system/VERSION"));
	has(file_get_contents("$site/system/util.php"), '// changed in 2.1.0');
	lacks(file_get_contents("$site/system/controller.php"), 'my local fix', 'framework files are replaced');
	check(is_file("$site/system/tools/hello.php"));
	check(!is_file("$site/system/obsolete.txt"), 'files the release dropped are removed');
	has(file_get_contents("$site/application/views/default/about.html"), '<!-- my page -->', 'the app is left alone');
	same("upgraded\n", file_get_contents("$site/application/config/marker.txt"));
	same("2.1.0\n", file_get_contents("$site/application/config/raster-version"));
	check(!is_file($page), 'the pages cached before the update are deleted');
	check(file_get_contents("$cache/version") !== 'before', 'and the page cache has a new version');
	// the old files are kept
	preg_match('#The replaced files are in (\S+)/#', $out, $m);
	check(isset($m[1]) && strpos($m[1], 'application/data/backups/raster-2.0.0-') === 0, $out);
	has(file_get_contents("$site/{$m[1]}/system/controller.php"), 'my local fix');
	check(is_file("$site/{$m[1]}/system/obsolete.txt"));
	same('2.1.0', json_decode(file_get_contents("$site/system/checksums.json"), true)['version']);
	list($code, $out) = raster($site, array('doctor'));
	same(0, $code, $out);
	has($out, '✓ Raster 2.1.0');
	has($out, '✓ Framework files as installed');
});

test('updates are repeatable, never backwards', function () use ($tmp, $site, $rel200) {
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code);
	has($out, 'nothing to change');
	exec('tar czf '.escapeshellarg("$tmp/rel210.tar.gz").' -C '.escapeshellarg($tmp).' rel210', $o, $status);
	same(0, $status);
	list($code, $out) = raster($site, array('update', "$tmp/rel210.tar.gz"));
	same(0, $code, $out);
	has($out, 'Already up to date');
	list($code, $out) = raster($site, array('update', $rel200));
	same(1, $code);
	has($out, 'older than');
	same("2.1.0\n", file_get_contents("$site/system/VERSION"));
});

test('an app from Raster 1.x', function () use ($site) {
	unlink("$site/CLAUDE.md");
	file_put_contents("$site/application/models/sql.php", "<?php\n\$querries['all_users'] = \"SELECT * FROM user\";\n");
	file_put_contents("$site/application/views/default/editor.html", "<html><body>\n<!-- render.cms.login --><form method=\"post\"><input name=\"login\"><input type=\"password\" name=\"password\"></form><!-- /render.cms.login -->\n</body></html>\n");
	file_put_contents("$site/application/config/raster-version", "1.0.0\n");
	list($code, $out) = raster($site, array('doctor'));
	same(1, $code, $out);
	has($out, 'application is at 1.0.0, the framework at 2.1.0');
	has($out, 'render.cms.login is now render.authentication.login');
	has($out, 'application/views/default/editor.html:2');
	// $querries is no longer read, so nothing is said about it
	lacks($out, '$querries', 'the $querries deprecation is gone');
	lacks($out, 'name the array $queries', 'the $querries deprecation is gone');
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	has($out, '2.0.0 agent-files');
	has($out, '2.0.0 cms-login-region');
	lacks($out, 'queries-name', 'the $querries step is gone');
	lacks($out, 'private-folders', 'only the steps it needs');
	lacks($out, '2.1.0 marker', 'already there');
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	has($out, 'changed application/views/default/editor.html');
	same("@AGENTS.md\n", file_get_contents("$site/CLAUDE.md"));
	has(file_get_contents("$site/application/views/default/editor.html"), '<!-- render.authentication.login -->');
	has(file_get_contents("$site/application/views/default/editor.html"), '<!-- /render.authentication.login -->');
	list($code, $out) = raster($site, array('doctor'));
	same(0, $code, $out);
	lacks($out, 'deprecated');
});

test('doctor in production', function () use ($site) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_URL' => null, 'RASTER_MCP_TOKEN' => 'short');
	list($code, $out) = raster($site, array('doctor', '--json'), $env);
	same(1, $code);
	$report = json_decode($out, true);
	same('production', $report['environment']);
	$titles = array_map(function ($c) { return $c['status'].' '.$c['title']; }, $report['checks']);
	check(in_array('fail Site address', $titles), implode(', ', $titles));
	check(in_array('fail The MCP token is short', $titles));
	check(in_array('fail The database and the templates differ', $titles), 'a new production database needs schema --apply');
	same(0, raster($site, array('schema', '--apply'), $env)[0]);
	list($code, $out) = raster($site, array('doctor'), array('RASTER_ENV' => 'production', 'RASTER_URL' => 'https://site.example/', 'RASTER_MAIL' => 'smtp://mail.example:587', 'RASTER_MCP_TOKEN' => null));
	same(0, $code, $out);
	has($out, '✓ Site address');
});

test('doctor in production warns when no time zone is set (#84)', function () use ($site) {
	// PHP's settings without date.timezone, as most hosts ship them
	$ini = "$site/../no-timezone";
	@mkdir("$ini/conf.d", 0775, true);
	$strip = function ($file) { return preg_replace('/^\s*date\.timezone\s*=.*$/mi', '', (string)file_get_contents($file)); };
	file_put_contents("$ini/php.ini", php_ini_loaded_file() ? $strip(php_ini_loaded_file()) : '');
	foreach (array_filter(array_map('trim', explode(',', (string)php_ini_scanned_files()))) as $file) file_put_contents("$ini/conf.d/".basename($file), $strip($file));
	$doctor = function ($zone) use ($site, $ini) {
		$env = array_merge(getenv(), array('RASTER_APP' => 'application', 'RASTER_DB' => "$site/application/data/test.sqlite", 'NO_COLOR' => '1', 'RASTER_ENV' => 'production', 'RASTER_URL' => 'https://site.example/', 'RASTER_MAIL' => 'smtp://mail.example:587', 'PHP_INI_SCAN_DIR' => "$ini/conf.d"));
		unset($env['RASTER_MCP_TOKEN']);
		$config = "$site/application/config/the_app.php";
		$was = file_get_contents($config);
		if ($zone !== null) file_put_contents($config, $was."\nconfig::set('timezone')->to('$zone');\n");
		try {
			$process = proc_open(php_argv('-c', "$ini/php.ini", "$site/bin/raster", 'doctor'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $site, $env);
			$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
			return array(proc_close($process), $out);
		} finally {
			file_put_contents($config, $was);
		}
	};
	list($code, $out) = $doctor(null);
	has($out, 'No time zone is set');
	has($out, "config::set('timezone')");
	list($code, $out) = $doctor('Europe/Bucharest');
	same(0, $code, $out);
	lacks($out, 'time zone');
	list($code, $out) = $doctor('Europe/Atlantis');
	same(1, $code, $out);
	has($out, "Unknown timezone 'Europe/Atlantis'");
});

test('a new site can keep code out of the web', function () use ($site) {
	// the .htaccess a site is made with carries every rule, whatever app
	// folders it has, and deploy prints it back
	has(raster($site, array('doctor'))[1], '✓ .htaccess has every rule');
	list($code, $out) = raster($site, array('deploy', '--config=apache'));
	same(0, $code, $out);
	same(trim(file_get_contents("$site/.htaccess")), trim($out));
	// the same rules for any site: no app folder is named anywhere
	$caddy = raster($site, array('deploy', '--config=caddy', '--host=new.example'))[1];
	has($caddy, 'new.example');
	has($caddy, 'phar');
	check(strpos($caddy, 'demo') === false, 'no app folder of the Raster repository');
	check(strpos($caddy, 'application') === false, 'and not this site\'s either');
	// an older .htaccess is reported, not silently trusted
	file_put_contents("$site/.htaccess", "RewriteEngine on\n");
	has(raster($site, array('doctor'))[1], '.htaccess is missing');
	unlink("$site/.htaccess");
	has(raster($site, array('doctor'))[1], '.htaccess is missing');
});

// ## 2.1.8 batch A: page cache
// (batch A adds its tests here)

// ## 2.1.8 batch B: errors and /api

test('an older site upgrades with /api closed: api_open is gone (#24)', function () use ($repo, $tmp) {
	$site = "$tmp/siteb";
	same(0, raster($repo, array('new', $site))[0]);
	// a site from before 2.1.1, with a model that lists nothing
	file_put_contents("$site/application/config/raster-version", "2.1.0\n");
	mkdir("$site/application/models/orders");
	file_put_contents("$site/application/models/orders/orders.php", "<?php\nclass orders { function all() { return array('secret order'); } }\n");
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	lacks($out, 'api-open');
	lacks(file_get_contents("$site/application/config/the_app.php"), 'api_open');
	same(file_get_contents("$repo/system/VERSION"), file_get_contents("$site/application/config/raster-version"));
	// a config that still sets it opens nothing
	append("$site/application/config/the_app.php", "\nconfig::set('api_open')->to(true);\n");
	list($code, $out) = raster($site, array('render', '/api/orders/all'));
	lacks($out, 'secret order');
	has($out, 'unknown method');
	lacks(raster($site, array('vocabulary'))[1], 'every public method');
});

// ## 2.1.8 batch C: list SQL
// (batch C adds its tests here)

// ## 2.1.8 batch D: accounts
// (batch D adds its tests here)

// ## 2.1.8 batch E: template output
// (batch E adds its tests here)

// ## 2.1.8 batch F: upgrade tooling

test('a hand-made second app is taken as current, not as 1.x (#75)', function () use ($repo, $tmp) {
	$site = "$tmp/site-blog";
	same(0, raster($repo, array('new', $site))[0]);
	$version = trim(file_get_contents("$repo/system/VERSION"));
	// a second app made by copying the first, without its version file, and a
	// model that offers nothing at /api
	exec('cp -R '.escapeshellarg("$site/application").' '.escapeshellarg("$site/blog"));
	unlink("$site/blog/config/raster-version");
	mkdir("$site/blog/models/posts");
	file_put_contents("$site/blog/models/posts/posts.php", "<?php\nclass posts { function wipe() { return 'all posts deleted'; } }\n");
	$config = file_get_contents("$site/blog/config/the_app.php");
	$blog = array('RASTER_APP' => 'blog', 'RASTER_DB' => "$site/blog/data/test.sqlite");
	// doctor and the dry run have nothing for it to do
	list($code, $out) = raster($site, array('upgrade', '--dry-run'), $blog);
	same(0, $code, $out);
	has($out, 'blog/ has no config/raster-version');
	lacks($out, '2.0.0 ');
	lacks($out, '2.1.1 ');
	check(!is_file("$site/blog/config/raster-version"), 'a dry run writes nothing');
	list($code, $out) = raster($site, array('doctor'), $blog);
	lacks($out, 'upgrade step(s) to run', $out);
	has($out, "✓ Raster $version");
	// updating the project upgrades every app: blog/ gets today's version and
	// no old steps
	list($code, $out) = raster($site, array('update', $repo));
	same(0, $code, $out);
	has($out, "blog/ had no config/raster-version: taken as Raster $version, no upgrade steps run");
	lacks($out, '✓ 2.', 'no old steps');
	same("$version\n", file_get_contents("$site/blog/config/raster-version"));
	same($config, file_get_contents("$site/blog/config/the_app.php"), 'its config is left alone');
	lacks(file_get_contents("$site/blog/config/the_app.php"), 'api_open');
	list($code, $out) = raster($site, array('render', '/api/posts/wipe'), $blog);
	has($out, 'HTTP 404', $out);
	lacks($out, 'all posts deleted', '/api stays closed');
	// from then on it upgrades like any other app
	list($code, $out) = raster($site, array('upgrade'), $blog);
	same(0, $code, $out);
	has($out, "blog/ is at Raster $version, nothing to change");
	lacks($out, 'no config/raster-version');
	list($code, $out) = raster($site, array('version'));
	has($out, "blog/ is at $version");
	// an app really from Raster 1.x says so in its version file, and gets
	// every step again
	unlink("$site/blog/data/.gitignore");
	lacks(raster($site, array('upgrade', '--dry-run'), $blog)[1], '2.0.0 private-folders', 'not while it is current');
	file_put_contents("$site/blog/config/raster-version", "1.0.0\n");
	has(raster($site, array('upgrade', '--dry-run'), $blog)[1], '2.0.0 private-folders');
});

test('upgrade refuses an app folder that is missing or not an app', function () use ($repo, $tmp) {
	$site = "$tmp/site-noapp";
	same(0, raster($repo, array('new', $site))[0]);
	// a misspelled RASTER_APP, a folder that is not an app (no config/), and
	// the framework's own folder, which has a config/ but is no app
	foreach (array('nope', 'media', 'system', 'application/../system') as $app) {
		foreach (array(array('upgrade'), array('upgrade', '--dry-run')) as $args) {
			list($code, $out) = raster($site, $args, array('RASTER_APP' => $app));
			check($code !== 0, implode(' ', $args)." with $app/ exits non-zero: $out");
			has($out, "$app/ is not a Raster app folder");
			lacks($out, 'taken as', 'no success line');
			lacks($out, 'Warning', 'no PHP warning');
		}
	}
	check(!file_exists("$site/nope"), 'nothing is created');
	check(!file_exists("$site/media/config"), 'nothing is written into media/');
	check(!file_exists("$site/system/config/raster-version"), 'nothing is written into system/');
	list($code, $out) = raster($site, array('doctor'));
	lacks($out, 'raster-version', 'doctor finds no framework file added');
	// an app whose version file can't be written says so, and not that it was
	// taken as current
	unlink("$site/application/config/raster-version");
	chmod("$site/application/config", 0555);
	list($code, $out) = raster($site, array('upgrade'));
	chmod("$site/application/config", 0775);
	check($code !== 0, "exits non-zero: $out");
	has($out, "Could not write application/config/raster-version");
	lacks($out, 'taken as', 'no success line');
	lacks($out, 'Warning', 'no PHP warning');
	// when steps ran before the write failed, the error names them
	file_put_contents("$site/application/config/raster-version", "1.0.0\n");
	@unlink("$site/CLAUDE.md");
	chmod("$site/application/config/raster-version", 0444);
	list($code, $out) = raster($site, array('upgrade'));
	chmod("$site/application/config/raster-version", 0664);
	check($code !== 0, "exits non-zero: $out");
	has($out, "Could not write application/config/raster-version");
	has($out, '2.0.0 agent-files');
	has($out, 'upgrade checks them again next time');
	lacks($out, 'the steps above ran', 'no steps are printed above');
});

test('new sites get no old .htaccess below the root (#76)', function () use ($repo, $tmp) {
	check(!file_exists("$repo/application/.htaccess"), 'application/.htaccess is not in the repository');
	check(!file_exists("$repo/system/.htaccess"), 'system/.htaccess is not in the repository');
	$site = "$tmp/site-htaccess";
	same(0, raster($repo, array('new', $site))[0]);
	check(!file_exists("$site/application/.htaccess"), 'raster new copies no application/.htaccess');
	check(!file_exists("$site/system/.htaccess"), 'raster new copies no system/.htaccess');
	check(is_file("$site/.htaccess"), 'the root .htaccess is still there');
	// the root rules still refuse config, data, models and system
	require_once "$repo/system/private_paths.php";
	foreach (array('/application/config/the_app.php', '/application/config/servers', '/application/data/raster.sqlite', '/application/data/mail/x.eml', '/application/models/x/x.php', '/application/models/x/sql/q', '/system/boot.php', '/system/VERSION') as $path) {
		check(private_paths::blocked($path), "$path is refused");
	}
	foreach (array('logo.svg', 'photo.webp', 'font.woff2', 'favicon.ico', 'menu.pdf', 'style.css') as $file) {
		check(!private_paths::blocked("/application/views/default/$file"), "theme file $file is served");
	}
	has(raster($site, array('doctor'))[1], '✓ .htaccess has every rule');
});

test('a file update can\'t write stops it, and the old files are put back (#102)', function () use ($tmp, $rel200, $rel210) {
	$site = "$tmp/site-readonly";
	same(0, raster($rel200, array('new', $site))[0]);
	chmod("$site/system/util.php", 0444);
	try {
		list($code, $out) = raster($site, array('update', $rel210));
		same(1, $code, $out);
		has($out, 'system/util.php');
		lacks($out, 'Updated to Raster');
		lacks($out, 'Warning:', 'a refusal, not a PHP warning');
		// as it was: what was changed before the failure is back, what was new is gone
		same("2.0.0\n", file_get_contents("$site/system/VERSION"));
		lacks(file_get_contents("$site/system/util.php"), '// changed in 2.1.0');
		check(is_file("$site/system/obsolete.txt"), 'the removed file is back');
		check(!file_exists("$site/system/tools/hello.php"), 'the new file is gone');
		check(!file_exists("$site/system/upgrades/2.1.0.php"), 'the new upgrade step is gone');
		check(!file_exists("$site/application/config/marker.txt"), 'no upgrade ran');
		same("2.0.0\n", file_get_contents("$site/application/config/raster-version"));
		list($code, $out) = raster($site, array('doctor'));
		has($out, '✓ Raster 2.0.0');
		has($out, '✓ Framework files as installed');
	} finally {
		chmod("$site/system/util.php", 0644);
	}
	// once the file can be written, the same update goes through
	list($code, $out) = raster($site, array('update', $rel210));
	same(0, $code, $out);
	has(file_get_contents("$site/system/util.php"), '// changed in 2.1.0');
});

test('update takes only a folder that holds a Raster release, even with --force (#102)', function () use ($tmp, $rel200) {
	$site = "$tmp/site-notarelease";
	same(0, raster($rel200, array('new', $site))[0]);
	$before = file_get_contents("$site/system/checksums.json");
	mkdir("$tmp/empty-folder");
	mkdir("$tmp/other-folder/system", 0775, true);
	file_put_contents("$tmp/other-folder/system/VERSION", "9.9.9\n");
	foreach (array("$tmp/empty-folder", "$tmp/other-folder") as $folder) {
		foreach (array(array(), array('--force')) as $force) {
			list($code, $out) = raster($site, array_merge(array('update', $folder), $force));
			same(1, $code, $out);
			has($out, "$folder does not hold a Raster release");
			lacks($out, 'removed file(s)');
		}
	}
	check(is_file("$site/bin/raster") && is_file("$site/system/boot.php"), 'the framework is still there');
	list($code, $out) = raster($site, array('doctor'));
	has($out, '✓ Framework files as installed');
	same($before, file_get_contents("$site/system/checksums.json"));
});

test('update takes a tarball made with macOS tar (#116)', function () use ($tmp, $rel200, $rel210) {
	$site = "$tmp/site-mac";
	same(0, raster($rel200, array('new', $site))[0]);
	// macOS tar keeps a file's extended attributes in a ._<name> entry next
	// to it, and a folder unzipped from a Mac can carry a __MACOSX/ folder
	$pack = "$tmp/mac-pack";
	mkdir($pack);
	exec('cp -a '.escapeshellarg($rel210).' '.escapeshellarg("$pack/rel210"), $o, $status);
	same(0, $status);
	file_put_contents("$pack/._rel210", "Mac OS X attributes\n");
	file_put_contents("$pack/rel210/system/._util.php", "Mac OS X attributes\n");
	mkdir("$pack/__MACOSX/rel210", 0775, true);
	file_put_contents("$pack/__MACOSX/rel210/._index.php", "Mac OS X attributes\n");
	$tar = new PharData("$tmp/mac.tar");
	$tar->buildFromDirectory($pack);
	$tar->compress(Phar::GZ);
	list($code, $out) = raster($site, array('update', "$tmp/mac.tar.gz"));
	same(0, $code, $out);
	has($out, 'Updated to Raster 2.1.0');
	same("2.1.0\n", file_get_contents("$site/system/VERSION"));
	check(!file_exists("$site/system/._util.php"), 'the attributes are not copied in as a file');
	list($code, $out) = raster($site, array('doctor'));
	has($out, '✓ Framework files as installed');
});

// ## Uploads and downloads (#115, #113)

test('doctor in production warns when PHP takes smaller uploads than the editor sends (#115)', function () use ($site) {
	$doctor = function ($upload, $post) use ($site) {
		$env = array_merge(getenv(), array('RASTER_APP' => 'application', 'RASTER_DB' => "$site/application/data/test.sqlite", 'NO_COLOR' => '1', 'RASTER_ENV' => 'production', 'RASTER_URL' => 'https://site.example/', 'RASTER_MAIL' => 'smtp://mail.example:587'));
		unset($env['RASTER_MCP_TOKEN']);
		$process = proc_open(php_argv('-d', "upload_max_filesize=$upload", '-d', "post_max_size=$post", "$site/bin/raster", 'doctor'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $site, $env);
		$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
		return array(proc_close($process), $out);
	};
	// stock PHP: 2 MB files in an 8 MB request
	list($code, $out) = $doctor('2M', '8M');
	same(0, $code, 'a warning, not a failure: '.$out);
	has($out, 'PHP takes uploads up to 2M');
	has($out, 'upload_max_filesize = 12M');
	list($code, $out) = $doctor('64M', '8M');
	has($out, 'PHP takes requests up to 8M');
	has($out, 'post_max_size = 13M');
	list($code, $out) = $doctor('12M', '13M');
	lacks($out, 'PHP takes');
	// 0 is no limit for post_max_size
	list($code, $out) = $doctor('1G', '0');
	lacks($out, 'PHP takes');
});

test('update downloads a release over HTTP and reads its status (#113)', function () use ($repo, $tmp) {
	$www = "$tmp/www";
	@mkdir($www, 0775, true);
	file_put_contents("$www/release.txt", "a release\n");
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	$base = 'http://'.$name;
	$server = proc_open(php_argv('-S', $name, '-t', $www), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $www);
	try {
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', (int)substr($name, strrpos($name, ':') + 1)); $i++) usleep(100000);
		// every notice shows, so a deprecated way of reading the headers
		// (PHP 8.5 deprecates $http_response_header) fails here
		$download = function ($url) use ($repo) {
			$code = 'require '.var_export("$repo/system/tools/project.php", true).'; try { echo raster_project::download($argv[1]); } catch (Exception $e) { echo "refused: ".$e->getMessage(); }';
			$process = proc_open(php_argv('-d', 'error_reporting=-1', '-d', 'display_errors=1', '-r', $code, $url), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
			$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
			proc_close($process);
			return $out;
		};
		same("a release\n", $download("$base/release.txt"));
		same("refused: Could not download $base/missing.tar.gz (HTTP 404)", $download("$base/missing.tar.gz"));
	} finally {
		proc_terminate($server);
		proc_close($server);
	}
});

// ## 2.1.8 batch G: MCP themes
// (batch G adds its tests here)

// ## 2.1.8 batch H: row loop
// (batch H adds its tests here)

// ## P0.C: the framework prints no English (#38 #128)

test('P0.C: an older login view gets the no_database and no_users alerts login_message now raises', function () use ($repo, $tmp) {
	$site = "$tmp/site-p0c";
	same(0, raster($repo, array('new', $site))[0]);
	// the step's version is its file's name (given at release)
	$file = current(array_filter(glob("$repo/system/upgrades/*.php"), function ($f) { return strpos(file_get_contents($f), "'login-message-alerts'") !== false; }));
	check($file !== false, 'the upgrade step');
	$version = basename($file, '.php');
	if (version_compare(trim(file_get_contents("$site/system/VERSION")), $version, '<')) file_put_contents("$site/system/VERSION", "$version\n");
	file_put_contents("$site/application/config/raster-version", "2.2.45\n");
	$view = "$site/application/views/default/editor-login.html";
	file_put_contents($view, "<html><body>\n  <!-- print.cms.login_message /-->\n  <!-- render.authentication.login --><form method=\"post\"><input name=\"login\"><input type=\"password\" name=\"password\"></form><!-- /render.authentication.login -->\n</body></html>\n");
	$starter = file_get_contents("$site/application/views/default/login.html");
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	has($out, "$version login-message-alerts");
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	has($out, 'changed application/views/default/editor-login.html');
	lacks($out, 'default/login.html', 'the starter\'s already has them');
	same($starter, file_get_contents("$site/application/views/default/login.html"));
	$html = file_get_contents($view);
	has($html, "<!-- print.cms.login_message /--><!-- print.validation.alert('no_database') --><p class=\"notice\">No database is configured");
	has($html, "<!-- print.validation.alert('no_users') --><p class=\"notice\">There are no users yet.");
	// once is enough
	file_put_contents("$site/application/config/raster-version", "2.2.45\n");
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	lacks($out, 'login-message-alerts');
});

// ## P1.A: the language API, the switch, doctor

test('P1.A: doctor on languages: multilingual needs two, codes it can\'t read, template-only is deprecated', function () use ($repo, $tmp) {
	$site = "$tmp/site-p1a";
	same(0, raster($repo, array('new', $site))[0]);
	$config = "$site/application/config/the_app.php";
	$starter = file_get_contents($config);
	$doctor = function ($settings) use ($site, $config, $starter) {
		file_put_contents($config, $starter."\n".$settings."\n");
		return raster($site, array('doctor'));
	};
	list($code, $out) = $doctor('');
	same(0, $code, $out);
	lacks($out, 'multilingual');
	lacks($out, 'deprecated');
	list($code, $out) = $doctor("config::set('multilingual')->to(true);\nconfig::set('languages')->to(array('ro'));");
	same(1, $code, $out);
	has($out, '✗ multilingual is on with fewer than two languages');
	has($out, 'The site behaves as if it were off');
	list($code, $out) = $doctor("config::set('multilingual')->to(true);");
	same(1, $code, $out);
	has($out, '✗ multilingual is on with fewer than two languages');
	list($code, $out) = $doctor("config::set('languages')->to(array('en', 'ro_RO'));\nconfig::set('domain_language')->to(array('example.ro' => 'Romanian'));");
	same(1, $code, $out);
	has($out, "✗ Language code(s) Raster can't read: 'ro_RO', 'Romanian'");
	has($out, 'In languages and domain_language, a code is two or three letters, then an optional region: en, ro, pt-br');
	// two languages without the switch: the template's words only (D2)
	list($code, $out) = $doctor("config::set('languages')->to(array('en', 'ro'));");
	same(0, $code, $out);
	has($out, "languages without multilingual translates only the template's words");
	has($out, "add config::set('multilingual')->to(true)");
	has($out, '(deprecated in 2.2.49, removed in 3.0.0)');
	has($out, 'application/config/the_app.php:'.(substr_count($starter, "\n") + 2));
	// on, it says so, and nothing is deprecated
	list($code, $out) = $doctor("config::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);");
	same(0, $code, $out);
	has($out, '✓ Multilingual: en, ro');
	lacks($out, 'deprecated');
	// one language is a one-language site, not template-only translation
	list($code, $out) = $doctor("config::set('languages')->to(array('ro'));");
	same(0, $code, $out);
	lacks($out, 'deprecated');
	lacks($out, 'multilingual');
	// the running config decides, not one file's text
	$routes = "$site/application/config/the_routes.php";
	$kept = file_get_contents($routes);
	file_put_contents($routes, $kept."\nconfig::set('multilingual')->to(TRUE);\n");
	list($code, $out) = $doctor("config::set('languages')->to(array('en', 'ro'));");
	file_put_contents($routes, $kept);
	lacks($out, 'deprecated', 'multilingual set in another file');
	has($out, '✓ Multilingual: en, ro');
	list($code, $out) = $doctor("// config::set('languages')->to(array('en', 'ro'));");
	lacks($out, 'deprecated', 'a line in a comment');
	list($code, $out) = $doctor("config::set('languages')->to(['en', 'ro']);\nconfig::set('multilingual')->to(TRUE);");
	lacks($out, 'deprecated', 'TRUE');
	list($code, $out) = $doctor("config::set('languages')->to(['en', 'ro']);");
	has($out, '(deprecated in 2.2.49, removed in 3.0.0)', 'a [] list');
	list($code, $out) = $doctor("\$codes = array('en', 'ro');\nconfig::set(\"languages\")->to(\$codes);");
	has($out, '(deprecated in 2.2.49, removed in 3.0.0)', 'set from a variable');
	list($code, $out) = $doctor("\$set = 'config::set';\nconfig::instance()->languages = array('en', 'ro');");
	has($out, "translates only the template's words", 'set without config::set: the app\'s config is named');
	has($out, "\n      application/config\n");
	// a site that keeps it on purpose
	list($code, $out) = $doctor("config::set('languages')->to(array('en', 'ro'));\nconfig::set('allow_deprecated')->to(array('template-only-languages' => true));");
	same(0, $code, $out);
	has($out, '1 use(s) of deprecated features kept on purpose');
});

test('P1.A: a dictionary that sets $lang gets a doctor warning and returns it after upgrade (#44)', function () use ($repo, $tmp) {
	$site = "$tmp/site-p1a-lang";
	same(0, raster($repo, array('new', $site))[0]);
	$version = '2.2.49';
	check(is_file("$repo/system/upgrades/$version.php"), 'the upgrade step');
	if (version_compare(trim(file_get_contents("$site/system/VERSION")), $version, '<')) file_put_contents("$site/system/VERSION", "$version\n");
	file_put_contents("$site/application/config/raster-version", "2.2.48\n");
	mkdir("$site/application/i18n/ro", 0775, true);
	$old = "$site/application/i18n/ro/home.php";
	file_put_contents($old, "<?php\n\$lang = array(\n\t'welcome' => 'Bine ați venit',\n\t'return' => 'Înapoi',\n);\n?>\n");
	$parts = "$site/application/i18n/ro/menu.php";
	file_put_contents($parts, "<?php\n\$lang['soup'] = 'Supă';\n\$lang['tea'] = 'Ceai';\n");
	$current = "$site/application/i18n/ro/footer.php";
	file_put_contents($current, "<?php\n// \$lang = array() was the old way\nreturn array('rights' => 'Toate drepturile rezervate');\n");
	$footer = file_get_contents($current);
	$text = "$site/application/i18n/ro/legal.php";
	file_put_contents($text, "<?php\n\$lang = array('terms' => 'Termeni');\n?>\n<!-- kept as written -->\n");
	list($code, $out) = raster($site, array('doctor'));
	has($out, 'a dictionary that sets $lang instead of returning it');
	has($out, 'application/i18n/ro/home.php:2');
	has($out, 'application/i18n/ro/menu.php:2');
	lacks($out, 'footer.php');
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	has($out, "$version i18n-return-array");
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	has($out, 'application/i18n/ro/home.php');
	has($out, 'application/i18n/ro/menu.php');
	lacks($out, 'footer.php');
	same($footer, file_get_contents($current), 'a dictionary that returns is left alone');
	same(array('welcome' => 'Bine ați venit', 'return' => 'Înapoi'), (function ($file) { return include $file; })($old));
	same(array('soup' => 'Supă', 'tea' => 'Ceai'), (function ($file) { return include $file; })($parts));
	// text after the closing tag is still printed, and the array returned
	same("<?php\n\$lang = array('terms' => 'Termeni');\n?>\n<!-- kept as written -->\n<?php return \$lang;\n", file_get_contents($text));
	ob_start();
	$terms = (function ($file) { return include $file; })($text);
	same("<!-- kept as written -->\n", ob_get_clean());
	same(array('terms' => 'Termeni'), $terms);
	list($code, $out) = raster($site, array('doctor'));
	lacks($out, 'sets $lang');
	file_put_contents("$site/application/config/raster-version", "2.2.48\n");
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	lacks($out, 'i18n-return-array', 'once is enough');
});

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
