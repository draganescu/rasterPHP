<?php
// Upgrading an app from Raster 1.x to 2.0.
//
// Each step: 'needed' says whether this app still needs it, 'apply' does
// it and may return a line to show. Both get array('root' => project
// folder, 'app' => app folder, 'name' => app folder name). Steps only
// touch files; database changes go through `raster schema --apply`.
$in_files = function ($dir, $pattern, $regex) {
	$found = array();
	if (!is_dir($dir)) return $found;
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->isFile() && preg_match($pattern, $file->getFilename()) && preg_match($regex, file_get_contents($file->getPathname()))) {
			$found[] = $file->getPathname();
		}
	}
	return $found;
};

return array(
	'agent-files' => array(
		'description' => 'CLAUDE.md and .mcp.json, so agents read AGENTS.md and can use the MCP server',
		'needed' => function ($c) { return !is_file($c['root'].'/CLAUDE.md') || !is_file($c['root'].'/.mcp.json'); },
		'apply' => function ($c) {
			if (!is_file($c['root'].'/CLAUDE.md')) file_put_contents($c['root'].'/CLAUDE.md', "@AGENTS.md\n");
			if (!is_file($c['root'].'/.mcp.json')) {
				file_put_contents($c['root'].'/.mcp.json', json_encode(array('mcpServers' => array('raster' => array('command' => 'php', 'args' => array('bin/raster', 'mcp')))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			}
		},
	),
	'private-folders' => array(
		'description' => 'data/ and media/ keep their contents out of git',
		'needed' => function ($c) { return !is_file($c['app'].'/data/.gitignore') || !is_file($c['root'].'/media/.gitignore'); },
		'apply' => function ($c) {
			foreach (array($c['app'].'/data', $c['root'].'/media') as $dir) {
				if (!is_dir($dir)) mkdir($dir, 0775, true);
				if (!is_file("$dir/.gitignore")) file_put_contents("$dir/.gitignore", "*\n!.gitignore\n");
			}
		},
	),
	'cms-login-region' => array(
		'description' => 'render.cms.login becomes render.authentication.login',
		'needed' => function ($c) use ($in_files) { return (bool)$in_files($c['app'].'/views', '/\.html$/', '/<!-- \/?render\.cms\.login -->/'); },
		'apply' => function ($c) use ($in_files) {
			$changed = array();
			foreach ($in_files($c['app'].'/views', '/\.html$/', '/<!-- \/?render\.cms\.login -->/') as $file) {
				file_put_contents($file, preg_replace('/<!-- (\/?)render\.cms\.login -->/', '<!-- $1render.authentication.login -->', file_get_contents($file)));
				$changed[] = substr($file, strlen($c['root']) + 1);
			}
			return 'changed '.implode(', ', $changed);
		},
	),
	'queries-name' => array(
		'description' => 'models/sql.php names its array $queries',
		'needed' => function ($c) { return is_file($c['app'].'/models/sql.php') && preg_match('/\$querries\b/', file_get_contents($c['app'].'/models/sql.php')); },
		'apply' => function ($c) {
			$file = $c['app'].'/models/sql.php';
			file_put_contents($file, preg_replace('/\$querries\b/', '$queries', file_get_contents($file)));
		},
	),
);
