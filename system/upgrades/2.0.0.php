<?php
// Upgrading an app from Raster 1.x to 2.0.
//
// Each step: 'needed' says whether this app still needs it, 'apply' does
// it and may return a line to show. Both get array('root' => project
// folder, 'app' => app folder, 'name' => app folder name). Steps only
// touch files; database changes go through `raster schema --apply`.
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
);
