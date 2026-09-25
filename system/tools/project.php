<?php
// ##Projects: creating, updating and upgrading Raster sites
//
// A Raster project is the framework plus one or more app folders:
//
//   system/  bin/raster  index.php  .htaccess  AGENTS.md    the framework
//   application/  (or any folder with a config/ inside)     your app
//   media/  CLAUDE.md  .mcp.json                            yours too
//
// `raster update` replaces the framework files and nothing else. Anything
// you need to change in the framework goes in your app instead: a
// `the_<model>` class, `config/the_*.php`, or `the_<core file>.php`
// (see AGENTS.md, Extending). Files the framework installed are recorded
// in system/checksums.json, so an update can tell when one was edited.
//
// `raster upgrade` then brings each app up to the framework's version with
// the steps in system/upgrades/<version>.php. Every step checks whether it
// is needed first, so running it twice is harmless.
class raster_project
{
	const REPO = 'draganescu/rasterPHP';

	// the framework's files and folders, relative to the project root
	static $owned = array('system', 'bin/raster', 'index.php', '.htaccess', 'AGENTS.md');

	// ##Versions

	static function version($root) {
		$file = $root.'/system/VERSION';
		return is_file($file) ? trim(file_get_contents($file)) : '1.0.0';
	}

	// the version an app was last upgraded to; apps from before 2.0 have none
	static function app_version($app_dir) {
		$file = $app_dir.'/config/raster-version';
		return is_file($file) ? trim(file_get_contents($file)) : '1.0.0';
	}

	static function set_app_version($app_dir, $version) {
		file_put_contents($app_dir.'/config/raster-version', $version."\n");
	}

	// app folders: top-level folders with a config/ inside
	static function apps($root) {
		$apps = array();
		foreach (glob($root.'/*/config', GLOB_ONLYDIR) ?: array() as $dir) {
			$name = basename(dirname($dir));
			if (in_array($name, array('system', 'bin', 'tests', 'media'))) continue;
			$apps[] = $name;
		}
		sort($apps);
		return $apps;
	}

	// ##Framework files

	// every framework file, relative to the root
	static function files($root) {
		$files = array();
		foreach (self::$owned as $path) {
			$full = $root.'/'.$path;
			if (is_file($full)) {
				$files[] = $path;
			} elseif (is_dir($full)) {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
				foreach ($iterator as $file) {
					if (!$file->isFile()) continue;
					$relative = substr($file->getPathname(), strlen($root) + 1);
					if ($relative === 'system/checksums.json') continue;
					$files[] = $relative;
				}
			}
		}
		sort($files);
		return $files;
	}

	static function checksums($root) {
		$sums = array();
		foreach (self::files($root) as $file) $sums[$file] = sha1_file($root.'/'.$file);
		return $sums;
	}

	// remembers the framework files as installed
	static function record($root) {
		$data = array('version' => self::version($root), 'files' => self::checksums($root));
		file_put_contents($root.'/system/checksums.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
	}

	// framework files changed since they were installed, or null when
	// nothing was recorded (a git checkout, or a project from before 2.0)
	static function edits($root) {
		$file = $root.'/system/checksums.json';
		$recorded = is_file($file) ? json_decode(file_get_contents($file), true) : null;
		if (!is_array($recorded) || !isset($recorded['files'])) return null;
		$now = self::checksums($root);
		$edits = array('changed' => array(), 'missing' => array(), 'added' => array());
		foreach ($recorded['files'] as $path => $sum) {
			if (!isset($now[$path])) $edits['missing'][] = $path;
			elseif ($now[$path] !== $sum) $edits['changed'][] = $path;
		}
		foreach ($now as $path => $sum) {
			if (!isset($recorded['files'][$path])) $edits['added'][] = $path;
		}
		return $edits;
	}

	static function has_edits($edits) {
		return $edits && ($edits['changed'] || $edits['missing'] || $edits['added']);
	}

	// ##Getting a release

	// a folder holding a Raster release: a local path (a checkout or an
	// unpacked archive), a .tar.gz file, or a version or branch on GitHub
	static function fetch($source, $work) {
		if (is_dir($source)) return realpath($source);
		if (preg_match('/\.(tar\.gz|tgz)$/', $source) && is_file($source)) return self::unpack($source, $work);
		$repo = getenv('RASTER_REPO') ?: self::REPO;
		if ($source === 'latest') {
			$release = self::download("https://api.github.com/repos/$repo/releases/latest");
			$info = json_decode($release, true);
			if (!isset($info['tag_name'])) throw new RuntimeException("No published release found for $repo");
			$source = $info['tag_name'];
		}
		$ref = preg_match('/^v?\d+\.\d+/', $source) ? 'refs/tags/v'.ltrim($source, 'v') : 'refs/heads/'.$source;
		$archive = $work.'/release.tar.gz';
		file_put_contents($archive, self::download("https://codeload.github.com/$repo/tar.gz/$ref"));
		return self::unpack($archive, $work);
	}

	static function download($url) {
		$context = stream_context_create(array('http' => array('header' => "User-Agent: raster-update\r\n", 'timeout' => 60, 'ignore_errors' => true)));
		$body = @file_get_contents($url, false, $context);
		$status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
		if ($body === false || $status !== 200) throw new RuntimeException("Could not download $url".($status ? " (HTTP $status)" : ''));
		return $body;
	}

	static function unpack($archive, $work) {
		$target = $work.'/release';
		if (!is_dir($target)) mkdir($target, 0775, true);
		$phar = new PharData($archive);
		$phar->extractTo($target, null, true);
		// GitHub archives have one folder at the top
		$entries = array_values(array_diff(scandir($target), array('.', '..')));
		$root = count($entries) === 1 && is_dir("$target/{$entries[0]}") ? "$target/{$entries[0]}" : $target;
		if (!is_file("$root/system/boot.php")) throw new RuntimeException("$archive does not hold a Raster release");
		return $root;
	}

	// ##Updating

	// what an update would change: files added, changed and removed
	static function plan($root, $release) {
		$old = self::checksums($root);
		$new = self::checksums($release);
		$plan = array('add' => array(), 'change' => array(), 'remove' => array());
		foreach ($new as $path => $sum) {
			if (!isset($old[$path])) $plan['add'][] = $path;
			elseif ($old[$path] !== $sum) $plan['change'][] = $path;
		}
		foreach ($old as $path => $sum) {
			if (!isset($new[$path])) $plan['remove'][] = $path;
		}
		return $plan;
	}

	// copies the framework files over, after keeping the old ones in
	// <app>/data/backups/; returns the backup folder
	static function update($root, $release, $backup_app) {
		$plan = self::plan($root, $release);
		$backup = $root.'/'.$backup_app.'/data/backups/raster-'.self::version($root).'-'.date('Ymd-His');
		foreach (array_merge($plan['change'], $plan['remove']) as $path) {
			self::copy_file($root.'/'.$path, $backup.'/'.$path);
		}
		if (is_file($root.'/system/checksums.json')) self::copy_file($root.'/system/checksums.json', $backup.'/system/checksums.json');
		foreach ($plan['remove'] as $path) {
			unlink($root.'/'.$path);
		}
		foreach (array_merge($plan['add'], $plan['change']) as $path) {
			self::copy_file($release.'/'.$path, $root.'/'.$path);
		}
		// folders the release no longer has
		foreach (array_reverse(self::folders($root.'/system')) as $dir) {
			if (count(scandir($dir)) === 2) rmdir($dir);
		}
		if (is_file($root.'/bin/raster')) chmod($root.'/bin/raster', 0755);
		self::record($root);
		return is_dir($backup) ? $backup : null;
	}

	static function copy_file($from, $to) {
		if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
		copy($from, $to);
	}

	static function folders($dir) {
		$folders = array();
		if (!is_dir($dir)) return $folders;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($iterator as $file) if ($file->isDir()) $folders[] = $file->getPathname();
		sort($folders);
		return $folders;
	}

	// ##New projects

	// a new project in $target from the framework at $root, with the
	// starter app (application/ without its data)
	static function create($root, $target) {
		if (file_exists($target) && count(scandir($target)) > 2) throw new RuntimeException("$target is not empty");
		foreach (self::files($root) as $path) self::copy_file($root.'/'.$path, $target.'/'.$path);
		chmod($target.'/bin/raster', 0755);
		$app = $root.'/application';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (!$file->isFile()) continue;
			$relative = substr($file->getPathname(), strlen($root) + 1);
			// the starter app, not the old documentation theme or local data
			if (preg_match('#^application/(views/test/|data/(?!\.gitignore$)|config/raster-version$)#', $relative)) continue;
			self::copy_file($file->getPathname(), $target.'/'.$relative);
		}
		self::copy_file($root.'/media/.gitignore', $target.'/media/.gitignore');
		file_put_contents($target.'/CLAUDE.md', "@AGENTS.md\n");
		copy($root.'/.mcp.json', $target.'/.mcp.json');
		self::set_app_version($target.'/application', self::version($root));
		self::record($target);
	}

	// ##Upgrades

	// upgrade files newer than $from, up to $to, oldest first
	static function upgrade_files($root, $from, $to) {
		$files = array();
		foreach (glob($root.'/system/upgrades/*.php') ?: array() as $file) {
			$version = basename($file, '.php');
			if (version_compare($version, $from, '>') && version_compare($version, $to, '<=')) $files[$version] = $file;
		}
		uksort($files, 'version_compare');
		return $files;
	}

	// the steps an app still needs; each is array(version, id, description, apply)
	static function pending($root, $app) {
		$app_dir = $root.'/'.$app;
		$context = array('root' => $root, 'app' => $app_dir, 'name' => $app);
		$pending = array();
		foreach (self::upgrade_files($root, self::app_version($app_dir), self::version($root)) as $version => $file) {
			$steps = include $file;
			foreach ($steps as $id => $step) {
				if (call_user_func($step['needed'], $context)) {
					$pending[] = array('version' => $version, 'id' => $id, 'description' => $step['description'], 'apply' => $step['apply'], 'context' => $context);
				}
			}
		}
		return $pending;
	}

	static function upgrade($root, $app) {
		$done = array();
		foreach (self::pending($root, $app) as $step) {
			$result = call_user_func($step['apply'], $step['context']);
			$done[] = array('version' => $step['version'], 'id' => $step['id'], 'description' => $step['description'], 'result' => is_string($result) ? $result : '');
		}
		self::set_app_version($root.'/'.$app, self::version($root));
		return $done;
	}

	// ##Deprecations

	// uses of things that still work but will go away, found in the app's
	// views, models and config: array(file, line, id, message)
	static function deprecations($root, $app) {
		$found = array();
		$rules = include $root.'/system/deprecations.php';
		$app_dir = $root.'/'.$app;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app_dir, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (!$file->isFile() || !preg_match('/\.(php|html|rss|atom|xml|json|txt|sql)$/', $file->getFilename())) continue;
			$relative = substr($file->getPathname(), strlen($app_dir) + 1);
			if (strpos($relative, 'data/') === 0) continue;
			$source = file_get_contents($file->getPathname());
			foreach ($rules as $id => $rule) {
				if (isset($rule['in']) && !preg_match($rule['in'], $relative)) continue;
				if (!preg_match_all($rule['pattern'], $source, $matches, PREG_OFFSET_CAPTURE)) continue;
				foreach ($matches[0] as $match) {
					$found[] = array(
						'file' => $app.'/'.$relative,
						'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
						'id' => $id,
						'message' => $rule['message'].' (deprecated in '.$rule['since'].', removed in '.$rule['removed_in'].')',
					);
				}
			}
		}
		usort($found, function ($a, $b) { return strcmp($a['file'], $b['file']) ?: $a['line'] - $b['line']; });
		return $found;
	}

	// ##Doctor

	// checks a project and one app: array of array(status, title, detail)
	// with status ok, warn or fail
	static function doctor($root, $app, $environment) {
		$checks = array();
		$add = function ($status, $title, $detail = '') use (&$checks) {
			$checks[] = array('status' => $status, 'title' => $title, 'detail' => $detail);
		};
		$app_dir = $root.'/'.$app;

		// PHP
		$add(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', 'PHP '.PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : 'Raster needs PHP 8.1 or newer');
		foreach (array('pdo' => 'fail', 'mbstring' => 'fail', 'json' => 'fail', 'openssl' => 'warn', 'gd' => 'warn', 'phar' => 'warn', 'zlib' => 'warn') as $extension => $severity) {
			if (!extension_loaded($extension)) {
				$why = array('openssl' => 'needed for SMTP over TLS and for `raster update` downloads', 'gd' => 'needed to crop images in the editor', 'phar' => 'needed by `raster update` to unpack releases', 'zlib' => 'needed by `raster update` to unpack releases');
				$add($severity, "PHP extension $extension is missing", isset($why[$extension]) ? $why[$extension] : 'Raster needs it');
			}
		}
		$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : array();
		if (!in_array('sqlite', $drivers) && !in_array('mysql', $drivers)) $add('fail', 'No PDO driver for SQLite or MySQL');

		// versions
		$framework = self::version($root);
		$app_version = self::app_version($app_dir);
		$pending = self::pending($root, $app);
		if ($pending) {
			$add('fail', "$app is at $app_version, the framework at $framework", count($pending).' upgrade step(s) to run: php bin/raster upgrade');
		} else {
			$add('ok', "Raster $framework");
		}

		// framework files
		$edits = self::edits($root);
		if ($edits === null) {
			$add('warn', 'No record of the framework files as installed', 'An update can\'t tell whether they were edited. `php bin/raster update` records them.');
		} elseif (self::has_edits($edits)) {
			$list = array();
			foreach (array('changed', 'missing', 'added') as $kind) foreach ($edits[$kind] as $path) $list[] = "$kind: $path";
			$add('warn', 'Framework files were edited', implode("\n", $list)."\nAn update would replace them. Move the changes to your app (AGENTS.md, Extending).");
		} else {
			$add('ok', 'Framework files as installed');
		}

		// folders the site writes to
		foreach (array($app.'/data', 'media') as $folder) {
			$path = $root.'/'.$folder;
			if (!is_dir($path)) $add('fail', "$folder/ is missing");
			elseif (!is_writable($path)) $add('fail', "$folder/ is not writable", 'The web server needs to write here');
		}

		// templates and content
		$inspector = new raster_inspector();
		$problems = $inspector->lint();
		$errors = count(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
		$add($errors ? 'fail' : (count($problems) ? 'warn' : 'ok'), $errors ? "$errors template error(s)" : (count($problems) ? count($problems).' template warning(s)' : 'Templates'), $problems ? 'php bin/raster lint' : '');
		try {
			$schema = (new raster_schema())->status();
			if ($schema['drift']) {
				$add($environment === 'production' ? 'fail' : 'warn', 'The database and the templates differ', $environment === 'production' ? 'php bin/raster schema --apply' : 'Development creates what is missing on the next request; php bin/raster schema shows it');
			} else {
				$add('ok', 'Database matches the templates');
			}
		} catch (Exception $e) {
			$add('fail', 'No database', $e->getMessage());
		}

		// deprecations
		$deprecated = self::deprecations($root, $app);
		if ($deprecated) {
			$groups = array();
			foreach ($deprecated as $d) {
				$groups[$d['message']][] = "{$d['file']}:{$d['line']}";
			}
			$lines = array();
			foreach ($groups as $message => $places) $lines[] = $message."\n  ".implode("\n  ", $places);
			$add('warn', count($deprecated).' use(s) of deprecated features', implode("\n", $lines));
		}

		// production settings
		if ($environment === 'production') {
			$add(config::get('trusted_url') ? 'ok' : 'fail', 'Site address', config::get('trusted_url') ? config::get('link_uri') : 'Set RASTER_URL (or config site_url): emails with links are not sent without it');
			if (config::get('trusted_url') && strpos((string)config::get('link_uri'), 'https://') !== 0) $add('warn', 'The site address is not https');
			$transport = mail::transport();
			if (strpos($transport, 'log://') === 0) $add('warn', 'Mail is written to files, not sent', 'Set RASTER_MAIL to smtp://… or mail://');
			$token = getenv('RASTER_MCP_TOKEN') ?: config::get('mcp_token');
			if ($token && strlen($token) < 32) $add('fail', 'The MCP token is short', 'Use at least 32 random characters');
			if (!getenv('RASTER_ENV')) $add('warn', 'RASTER_ENV is not set', 'Production is picked from the host name; set RASTER_ENV=production on the server');
		}
		return $checks;
	}
}
