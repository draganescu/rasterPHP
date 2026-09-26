<?php
// #What is never served
//
// A Raster site is one folder, and most of what is in it must never come back
// over HTTP: the framework, the app's code and config, the SQLite file, the
// views themselves. Three different things enforce that — `php -S` (the
// router in index.php), `.htaccess` on Apache, and whatever sits in front on
// a server — so the rules live here once, in one list, and everything else
// reads or is generated from them.
//
//   private_paths::blocked($path)            the check index.php makes
//   private_paths::pattern()                 the same rules as one regex
//   private_paths::config('caddy', ...)      a server config built from them
//
// `php bin/raster deploy --config=<server>` prints that config, and
// `php bin/raster doctor` checks that the .htaccess in the site still carries
// every rule here, so the copy on disk can't quietly fall behind.
//
// This file is loaded before the framework boots, so it depends on nothing.
class private_paths {

	// The extensions nothing may fetch, whatever folder they are in.
	// index.php is the exception: it is the site.
	static $private_extensions = array('php', 'phar', 'sqlite', 'sql', 'md', 'lock', 'ini', 'bak');

	// View files are pages, never downloads.
	static $view_extensions = array('html', 'rss', 'atom', 'xml', 'json', 'txt');

	// The folders inside an app folder that hold code, settings and data.
	static $private_app_folders = array('config', 'models', 'data', 'i18n');

	// The framework's own folders.
	static $private_root_folders = array('system', 'bin', 'tests');

	// ##The rules
	//
	// One entry per reason to refuse. Each carries the regular expression the
	// PHP router uses and the equivalent line for each server, written side by
	// side so a change is made in one place and is visible for all of them.
	//
	// No rule names the app folders. `config/`, `models/`, `data/`, `i18n/` and
	// raw view files are refused under any top level folder, so every server
	// enforces the same thing whatever a site's folders are called, and adding
	// an app needs no new configuration.
	static function rules() {
		$private = implode('|', self::$private_extensions);
		$views = implode('|', self::$view_extensions);
		$app_folders = implode('|', self::$private_app_folders);
		$root_folders = implode('|', self::$private_root_folders);
		return array(
			array(
				'id' => 'dotfiles',
				'why' => 'dotfiles and dot folders: .git, .env, .htaccess, .github',
				'php' => '(^|/)\.',
				'apache' => array('RewriteRule (^|/)\. - [F,L]'),
				'nginx' => '(^|/)\.',
				'caddy' => '(^|/)\.',
			),
			array(
				'id' => 'framework',
				'why' => 'the framework, the command line and the test suites',
				'php' => '^/('.$root_folders.')(/|$)',
				'apache' => array('RewriteRule ^('.$root_folders.')(/|$) - [F,L]'),
				'nginx' => '^/('.$root_folders.')(/|$)',
				'caddy' => '^/('.$root_folders.')(/|$)',
			),
			array(
				'id' => 'app-code',
				'why' => "each app's settings, models, database and translations",
				'php' => '^/[^/]+/('.$app_folders.')(/|$)',
				'apache' => array('RewriteRule ^[^/]+/('.$app_folders.')(/|$) - [F,L]'),
				'nginx' => '^/[^/]+/('.$app_folders.')(/|$)',
				'caddy' => '^/[^/]+/('.$app_folders.')(/|$)',
			),
			array(
				'id' => 'raw-views',
				'why' => 'view files are pages, not downloads',
				'php' => '^/[^/]+/views/.*\.('.$views.')$',
				'apache' => array('RewriteRule ^[^/]+/views/.*\.('.$views.')$ - [F,L]'),
				'nginx' => '^/[^/]+/views/.*\.('.$views.')$',
				'caddy' => '^/[^/]+/views/.*\.('.$views.')$',
			),
			array(
				'id' => 'private-extensions',
				'why' => 'code, databases, dumps and notes, wherever they are (index.php excepted)',
				'php' => '\.('.$private.')$',
				'apache' => array('RewriteRule ^(?!index\.php$).*\.('.$private.')$ - [F,L]'),
				'nginx' => '\.('.$private.')$',
				'caddy' => '\.('.$private.')$',
			),
			array(
				'id' => 'sqlite-sidecars',
				'why' => "SQLite's journal, write-ahead log and shared memory files",
				'php' => '-(journal|wal|shm)$',
				'apache' => array('RewriteRule -(journal|wal|shm)$ - [F,L]'),
				'nginx' => '-(journal|wal|shm)$',
				'caddy' => '-(journal|wal|shm)$',
			),
		);
	}

	// The one path that is always allowed: the site's entry point.
	const ENTRY = '/index.php';

	// ##Using the rules
	//
	// The app folders of a site: every top level folder with a config/ inside,
	// except the framework's own (system/ has a config/ folder too) and media/.
	static function apps($root) {
		$apps = array();
		$not_apps = array_merge(self::$private_root_folders, array('media'));
		foreach (glob(rtrim($root, '/').'/*/config', GLOB_ONLYDIR) ?: array() as $dir) {
			$name = basename(dirname($dir));
			if (in_array($name, $not_apps)) continue;
			$apps[] = $name;
		}
		sort($apps);
		return $apps;
	}

	// Every rule as one regular expression, for a quick check.
	static function pattern() {
		$fragments = array();
		foreach (self::rules() as $rule) $fragments[] = $rule['php'];
		return '#'.implode('|', $fragments).'#i';
	}

	// True when a request path must be refused. $path is the decoded path of
	// the URL, with no query string.
	static function blocked($path) {
		if ($path === self::ENTRY) return false;
		return (bool)preg_match(self::pattern(), $path);
	}

	// One existing file per rule, as a URL path: what `doctor --edge` asks the
	// server in front for. They are files that are really there, so a 200
	// means the server handed them over, not that the path was a typo. Rules
	// with nothing to point at are left out.
	static function samples($root, $apps) {
		$root = rtrim($root, '/');
		$samples = array();
		$add = function ($id, $path) use ($root, &$samples) {
			if (isset($samples[$id]) || $path === null) return;
			if (is_file($root.$path)) $samples[$id] = $path;
		};
		$add('dotfiles', '/.htaccess');
		$add('framework', '/system/boot.php');
		foreach ($apps as $app) {
			$add('app-code', "/$app/config/the_app.php");
			$views = glob("$root/$app/views/*/*.html") ?: array();
			$add('raw-views', isset($views[0]) ? substr($views[0], strlen($root)) : null);
		}
		foreach (glob($root.'/*.{'.implode(',', self::$private_extensions).'}', GLOB_BRACE) ?: array() as $file) {
			if (substr($file, strlen($root)) === self::ENTRY) continue;
			$add('private-extensions', substr($file, strlen($root)));
		}
		return $samples;
	}

	// ##Server configuration
	//
	// The same rules as a config file for the server in front. `apache` is the
	// .htaccess that ships with Raster, the same for every site; the others
	// need the host, the folder and the PHP-FPM socket.
	static function servers() {
		return array('apache', 'nginx', 'caddy');
	}

	static function config($server, $options = array()) {
		$options += array('host' => 'example.com', 'root' => '/var/www/site', 'socket' => 'unix//run/php/php-fpm.sock');
		switch ($server) {
		case 'apache': return self::apache($options);
		case 'nginx': return self::nginx($options);
		case 'caddy': return self::caddy($options);
		}
		throw new InvalidArgumentException("No configuration for '$server'. Try: ".implode(', ', self::servers()));
	}

	static protected function apache($options) {
		$lines = array(
			'# Apache for a Raster site. `php bin/raster deploy --config=apache` prints',
			'# this file; the rules come from system/private_paths.php.',
			'',
			'RewriteEngine on',
			'',
			'# Never serve code, configuration, data or tooling directly',
		);
		foreach (self::rules() as $rule) {
			$lines[] = '# '.$rule['why'];
			foreach ($rule['apache'] as $line) $lines[] = $line;
		}
		$lines[] = '';
		$lines[] = '# everything else is the site';
		$lines[] = 'RewriteCond %{REQUEST_FILENAME} !-d';
		$lines[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
		$lines[] = 'RewriteRule . index.php [L]';
		return implode("\n", $lines)."\n";
	}

	static protected function nginx($options) {
		$lines = array(
			'# nginx for a Raster site. `php bin/raster deploy --config=nginx` prints it;',
			'# the rules come from system/private_paths.php. No app folder is named, so',
			'# adding an app to the site needs no change here.',
			'server {',
			'    listen 80;',
			'    server_name '.$options['host'].';',
			'    root '.rtrim($options['root'], '/').';',
			'    index index.php;',
			'',
			'    # the entry point, matched exactly so the rules below never hide it',
			'    location = /index.php {',
			'        include fastcgi_params;',
			'        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
			'        fastcgi_pass '.self::nginx_pass($options['socket']).';',
			'    }',
			'',
		);
		foreach (self::rules() as $rule) {
			$lines[] = '    # '.$rule['why'];
			$lines[] = '    location ~* "'.$rule['nginx'].'" { return 403; }';
		}
		$lines[] = '';
		$lines[] = '    # anything else: a real file, or the site';
		$lines[] = '    location / {';
		$lines[] = '        try_files $uri /index.php$is_args$args;';
		$lines[] = '    }';
		$lines[] = '}';
		return implode("\n", $lines)."\n";
	}

	static protected function nginx_pass($socket) {
		if (strpos($socket, 'unix/') === 0) return 'unix:'.substr($socket, strlen('unix/'));
		return $socket;
	}

	static protected function caddy($options) {
		$fragments = array();
		foreach (self::rules() as $rule) $fragments[] = $rule['caddy'];
		$lines = array(
			'# Caddy for a Raster site. `php bin/raster deploy --config=caddy` prints it;',
			'# the rules come from system/private_paths.php. No app folder is named, so',
			'# adding an app to the site needs no change here.',
			$options['host'].' {',
			'    root * '.rtrim($options['root'], '/'),
			'    encode gzip zstd',
			'',
		);
		foreach (self::rules() as $rule) $lines[] = '    # '.$rule['why'];
		$lines = array_merge($lines, array(
			'    @private {',
			'        not path '.self::ENTRY,
			'        path_regexp private (?i)'.implode('|', $fragments),
			'    }',
			'    respond @private 403',
			'',
			'    php_fastcgi '.$options['socket'],
			'    file_server',
			'}',
		));
		return implode("\n", $lines)."\n";
	}
}
