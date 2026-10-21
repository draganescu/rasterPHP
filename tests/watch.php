<?php
// Stops what a test run started once the run is gone, however it ended:
//   php tests/watch.php <run's pid>
// tests/php.php starts it with every suite. A run killed outright (SIGKILL,
// a crash) runs no shutdown function, so its php -S servers, their workers
// and its MCP sessions would run on. Twice a second this notes the run's
// processes and their command lines; when the run is gone it stops each one
// still running the same command, so a process id used again by something
// else is never touched. One started in the last half second can be missed.
if ($argc < 2 || !function_exists('posix_getppid')) exit;
$run = (int)$argv[1];
$me = getmypid();
$seen = array();
while (posix_getppid() === $run) {
	$lines = array();
	exec('ps -A -o pid= -o ppid= -o command=', $lines);
	$children = array();
	$commands = array();
	foreach ($lines as $line) {
		if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) continue;
		$children[(int)$m[2]][] = (int)$m[1];
		$commands[(int)$m[1]] = trim($m[3]);
	}
	$seen = array();
	for ($queue = array($run); $queue; ) {
		$pid = array_shift($queue);
		foreach (isset($children[$pid]) ? $children[$pid] : array() as $child) {
			if ($child === $me) continue;
			$seen[$child] = $commands[$child];
			$queue[] = $child;
		}
	}
	usleep(500000);
}
foreach ($seen as $pid => $command) {
	if (trim((string)shell_exec('ps -o command= -p '.$pid)) === $command) posix_kill($pid, 15);
}
