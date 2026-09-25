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
	has($out, 'Created a Raster 2.0.0 site');
	// the "2.0.0" release ships a file that 2.1.0 drops
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

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
