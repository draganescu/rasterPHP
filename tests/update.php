<?php
// Tests for new projects, updates, upgrades and the doctor: php tests/update.php
//
// Builds releases in a temporary folder (a "2.0.0" with a file that later
// goes away, and a "2.1.0" with a changed file, a new one and an upgrade
// step), makes a site from the first and updates it to the second.

if (PHP_SAPI !== 'cli') exit;

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/raster-update-test-'.getmypid();
mkdir($tmp, 0775, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf '.escapeshellarg($tmp)); });

$passed = 0; $failed = array();
function test($name, $fn) {
	global $passed, $failed;
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
	$process = proc_open(array_merge(array(PHP_BINARY, "$dir/bin/raster"), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir, $env);
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
	has($out, 'name the array $queries');
	list($code, $out) = raster($site, array('upgrade', '--dry-run'));
	has($out, '2.0.0 agent-files');
	has($out, '2.0.0 cms-login-region');
	has($out, '2.0.0 queries-name');
	lacks($out, 'private-folders', 'only the steps it needs');
	lacks($out, '2.1.0 marker', 'already there');
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	has($out, 'changed application/views/default/editor.html');
	same("@AGENTS.md\n", file_get_contents("$site/CLAUDE.md"));
	has(file_get_contents("$site/application/views/default/editor.html"), '<!-- render.authentication.login -->');
	has(file_get_contents("$site/application/views/default/editor.html"), '<!-- /render.authentication.login -->');
	has(file_get_contents("$site/application/models/sql.php"), '$queries[');
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

test('2.1.1 closes /api, and keeps it open for older sites until they list', function () use ($repo, $tmp) {
	$site = "$tmp/site211";
	same(0, raster($repo, array('new', $site))[0]);
	same(array(), array_values(array_filter(explode("\n", raster($site, array('upgrade', '--dry-run'))[1]), function ($l) { return strpos($l, 'api-open') !== false; })), 'a new site starts closed');
	lacks(file_get_contents("$site/application/config/the_app.php"), 'api_open');
	// a site made before 2.1.1, with a model that lists nothing
	file_put_contents("$site/application/config/raster-version", "2.1.0\n");
	mkdir("$site/application/models/orders");
	file_put_contents("$site/application/models/orders/orders.php", "<?php\nclass orders { function all() { return array(); } }\n");
	list($code, $out) = raster($site, array('upgrade'));
	same(0, $code, $out);
	has($out, 'api-open');
	has(file_get_contents("$site/application/config/the_app.php"), "config::set('api_open')->to(true);");
	same(file_get_contents("$repo/system/VERSION"), file_get_contents("$site/application/config/raster-version"));
	has(raster($site, array('doctor'))[1], 'api_open keeps every public method');
	// once the model lists what it offers and the line is gone, doctor is quiet
	file_put_contents("$site/application/models/orders/orders.php", "<?php\nclass orders {\n\tstatic function api() { return array('all' => 'editor'); }\n\tfunction all() { return array(); }\n}\n");
	$config = "$site/application/config/the_app.php";
	file_put_contents($config, preg_replace("/\n\/\/ Added by `raster upgrade` for Raster 2\.1\.1.*$/s", "\n", file_get_contents($config)));
	lacks(raster($site, array('doctor'))[1], 'api_open');
	// a config that ends with a closing tag still gets a working line
	file_put_contents("$site/application/config/raster-version", "2.1.0\n");
	file_put_contents("$site/application/models/orders/orders.php", "<?php\nclass orders { function all() { return array(); } }\n");
	file_put_contents($config, rtrim(file_get_contents($config))."\n?>\n");
	same(0, raster($site, array('upgrade'))[0]);
	lacks(file_get_contents($config), '?>');
	same(0, raster($site, array('render', '/'))[0], 'the page still renders');
	lacks(raster($site, array('render', '/'))[1], 'Added by `raster upgrade`', 'and prints nothing from the config');
	file_put_contents($config, preg_replace("/\n\/\/ Added by `raster upgrade` for Raster 2\.1\.1.*$/s", "\n", file_get_contents($config)));
	file_put_contents("$site/application/models/orders/orders.php", "<?php\nclass orders {\n\tstatic function api() { return array('all' => 'editor'); }\n\tfunction all() { return array(); }\n}\n");
	// an older site whose models already list theirs needs nothing
	file_put_contents("$site/application/config/raster-version", "2.1.0\n");
	lacks(raster($site, array('upgrade'))[1], 'api-open');
});

// ## 2.1.8 batch A: page cache
// (batch A adds its tests here)

// ## 2.1.8 batch B: errors and /api
// (batch B adds its tests here)

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

// ## 2.1.8 batch G: MCP themes
// (batch G adds its tests here)

// ## 2.1.8 batch H: row loop
// (batch H adds its tests here)

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
