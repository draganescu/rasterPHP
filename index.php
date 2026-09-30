<?php

// #The index file
// Raster is a normal web framework so all requests have a single
// entry point, which is the index.php file. It can be renamed and it serves
// as the entry point for one application.

// ### Development server
// `php -S localhost:8000 index.php` runs the site with no web server.
// Static files (theme css, images, media) are served as they are, while code,
// configuration and data are never exposed. The rules are the ones in
// system/private_paths.php, which .htaccess and `raster deploy` also come from.
if (PHP_SAPI === 'cli-server') {
	$path = preg_replace('#/+#', '/', rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
	// a site whose RASTER_URL has a path (http://localhost:8000/shop/) is
	// asked for under it; its files are still in this folder
	$folder = rtrim((string)parse_url((string)getenv('RASTER_URL'), PHP_URL_PATH), '/');
	$under_folder = $folder !== '' && strpos($path, $folder.'/') === 0;
	if ($under_folder) {
		$path = substr($path, strlen($folder));
	}
	// the same rules as .htaccess and the configs `raster deploy` prints, from
	// the one list in system/private_paths.php
	require_once __DIR__.'/system/private_paths.php';
	if (private_paths::blocked($path)) {
		http_response_code(403);
		exit('Forbidden');
	}
	if ($path !== '/' && is_file(__DIR__.$path) && strpos(realpath(__DIR__.$path), __DIR__.'/') === 0) {
		if (!$under_folder) return false;
		// PHP's server would look for the file under the folder, so it is sent from here
		$types = array('css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'txt' => 'text/plain; charset=UTF-8', 'html' => 'text/html; charset=UTF-8', 'xml' => 'application/xml', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg');
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		header('Content-Type: '.(isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
		header('Content-Length: '.filesize(__DIR__.$path));
		readfile(__DIR__.$path);
		exit;
	}
}

// ### Bootstrap
// The first thing we load is the boot class
// which handles auto magic and also wires up the framework
require_once 'system/boot.php';

// ### App name
// We give the application a name.
// By updating the name you can have more than one application
// using the same codebase, for example, add a blog.php file, update it like:
//
// ```
// boot::$appname = 'blog';
// ```
//
// and then in .htaccess direct all requests to blog.php. 
// RASTER_APP picks another app folder, e.g. RASTER_APP=demo for the demo café
$app = getenv('RASTER_APP');
boot::$appname = ($app && preg_match('/^[a-z0-9_]+$/', $app) && is_dir(__DIR__.'/'.$app.'/config')) ? $app : 'application';

// ### We're away!
// The static boot::up() method is all it takes to have
// Raster load and execute all the proper files for the
// current request
boot::up();

// Next source to read: ```/system/boot.php```