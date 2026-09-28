<?php
// Mutation check: php tests/mutate.php [--all] [--jobs=N] [--only=text] [--suite=demo|run|both] [--base=ref]
//
// Each entry in tests/mutations.json breaks the framework on purpose (one
// exact search and replace). The suite runs against a copy of the project
// with that change; if the suite still passes, the mutation "survived" and a
// feature is not really tested. Stale entries (the search text is gone, or
// is not unique) fail too, so the list keeps up with the code.
//
// Every mutation runs a whole suite, and a suite run costs what it costs: on a
// three core machine, measured, about a minute per mutation once a few run at
// once. All 76 is an hour of waiting.
//
// **By default it only runs the mutations whose file this branch touched**
// (against --base, `master` by default, plus anything not committed yet). An
// ordinary change is a handful: editing system/tools/inspector.php selects 9,
// which is minutes rather than an hour. `--all` is the full sweep, for a
// release or after editing the framework widely. It is not a gate: agents run
// it only when asked (see AGENTS.md). Don't set --jobs above the
// number of cores; the suites start their own servers and thrash.

if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$options = array('jobs' => 2, 'only' => '', 'suite' => 'demo', 'base' => 'master', 'all' => false);
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--all') $options['all'] = true;
	elseif (preg_match('/^--(jobs|only|suite|base)=(.*)$/', $arg, $m)) $options[$m[1]] = $m[2];
	else exit("Usage: php tests/mutate.php [--all] [--jobs=N] [--only=text] [--suite=demo|run|both] [--base=ref]\n");
}

// ##What this branch touched
//
// The files changed against the base, plus anything not committed yet. Returns
// null when git can't answer (no repository, no such ref), and the run then
// covers everything rather than pretending a change touched nothing.
function changed_files($root, $base) {
	$git = 'git -C '.escapeshellarg($root).' ';
	exec($git.'rev-parse --git-dir 2>/dev/null', $ignored, $code);
	if ($code !== 0) return null;
	$ref = null;
	foreach (array('origin/'.$base, $base) as $candidate) {
		exec($git.'rev-parse --verify --quiet '.escapeshellarg($candidate).' 2>/dev/null', $ignored, $found);
		if ($found === 0) { $ref = $candidate; break; }
	}
	if ($ref === null) return null;
	$files = array();
	foreach (array('diff --name-only '.escapeshellarg($ref).'...HEAD', 'diff --name-only', 'diff --name-only --cached') as $command) {
		$lines = array();
		exec($git.$command.' 2>/dev/null', $lines);
		foreach ($lines as $line) if (trim($line) !== '') $files[trim($line)] = true;
	}
	return array_keys($files);
}
$suites = $options['suite'] === 'both' ? array('demo', 'run') : array($options['suite']);
$mutations = json_decode(file_get_contents(__DIR__.'/mutations.json'), true);
if (!is_array($mutations)) exit("tests/mutations.json is not valid JSON\n");
$total = count($mutations);
$scope = 'all '.$total;
if ($options['only'] !== '') {
	$mutations = array_values(array_filter($mutations, function ($m) use ($options) { return strpos($m['name'], $options['only']) !== false; }));
	$scope = "matching '{$options['only']}'";
} elseif (!$options['all']) {
	$changed = changed_files($root, $options['base']);
	if ($changed === null) {
		$scope = "all $total (no git to compare with)";
	} else {
		$mutations = array_values(array_filter($mutations, function ($m) use ($changed) { return in_array($m['file'], $changed); }));
		$scope = 'for the '.count($changed).' file(s) this branch touched, of '.$total.' (--all for every one)';
		if (!$mutations) {
			echo "Nothing to check: no mutation targets a file this branch changed against {$options['base']}.\n";
			echo "The files it changed: ".($changed ? implode(', ', $changed) : '(none)')."\n";
			echo "--all runs every mutation; --base=<ref> compares with something else.\n";
			exit(0);
		}
	}
}

$work = sys_get_temp_dir().'/raster-mutate-'.getmypid();
@mkdir($work, 0775, true);
register_shutdown_function(function () use ($work) { exec('rm -rf '.escapeshellarg($work)); });

// a clean copy of the project, without git history or generated data
function copy_project($root, $to) {
	exec('mkdir -p '.escapeshellarg($to).' && cd '.escapeshellarg($root).' && tar --exclude=.git --exclude=./demo/data/cache --exclude=./demo/data/mail --exclude=\'*.sqlite\' -cf - . | tar -xf - -C '.escapeshellarg($to), $out, $code);
	return $code === 0;
}

$results = array();
$queue = $mutations;
$running = array();
$started = microtime(true);
echo count($mutations)." mutation(s) $scope, ".(int)$options['jobs']." at a time\n";
while ($queue || $running) {
	while ($queue && count($running) < max(1, (int)$options['jobs'])) {
		$mutation = array_shift($queue);
		$dir = $work.'/'.preg_replace('/[^a-zA-Z0-9_\-]/', '_', $mutation['name']);
		$file = "$dir/{$mutation['file']}";
		copy_project($root, $dir);
		$source = is_file($file) ? file_get_contents($file) : '';
		$count = $source === '' ? 0 : substr_count($source, $mutation['search']);
		if ($count !== 1) {
			$results[$mutation['name']] = $count === 0 ? 'stale (search text not found)' : "stale (search text found $count times)";
			echo "?";
			continue;
		}
		file_put_contents($file, str_replace($mutation['search'], $mutation['replace'], $source));
		$commands = array();
		foreach ($suites as $suite) $commands[] = escapeshellarg(PHP_BINARY).' '.escapeshellarg("$dir/tests/$suite.php");
		$process = proc_open(implode(' && ', $commands).' > '.escapeshellarg("$dir/.mutate.log").' 2>&1', array(), $pipes, $dir);
		$running[] = array('mutation' => $mutation, 'process' => $process, 'dir' => $dir);
	}
	foreach ($running as $i => $job) {
		$status = proc_get_status($job['process']);
		if ($status['running']) continue;
		$killed = $status['exitcode'] !== 0;
		$results[$job['mutation']['name']] = $killed ? 'killed' : 'survived';
		exec('rm -rf '.escapeshellarg($job['dir']));
		echo $killed ? '.' : 'S';
		unset($running[$i]);
	}
	usleep(200000);
}

$bad = array_filter($results, function ($r) { return $r !== 'killed'; });
printf("\n\n%d killed, %d survived or stale, in %ds\n", count($results) - count($bad), count($bad), microtime(true) - $started);
foreach ($bad as $name => $result) echo "  ✗ $name: $result\n";
exit($bad ? 1 : 0);
