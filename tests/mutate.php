<?php
// Mutation check for the demo suite: php tests/mutate.php [--jobs=N] [--only=text] [--suite=demo|run|both]
//
// Each entry in tests/mutations.json breaks the framework on purpose (one
// exact search and replace). The suite runs against a copy of the project
// with that change; if the suite still passes, the mutation "survived" and a
// feature is not really tested. Stale entries (the search text is gone, or
// is not unique) fail too, so the list keeps up with the code.
//
// A maintainer tool, slow by design: every mutation runs the whole suite.

if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$options = array('jobs' => 2, 'only' => '', 'suite' => 'demo');
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--(jobs|only|suite)=(.*)$/', $arg, $m)) $options[$m[1]] = $m[2];
	else exit("Usage: php tests/mutate.php [--jobs=N] [--only=text] [--suite=demo|run|both]\n");
}
$suites = $options['suite'] === 'both' ? array('demo', 'run') : array($options['suite']);
$mutations = json_decode(file_get_contents(__DIR__.'/mutations.json'), true);
if (!is_array($mutations)) exit("tests/mutations.json is not valid JSON\n");
if ($options['only'] !== '') {
	$mutations = array_values(array_filter($mutations, function ($m) use ($options) { return strpos($m['name'], $options['only']) !== false; }));
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
echo count($mutations)." mutation(s), ".(int)$options['jobs']." at a time\n";
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
