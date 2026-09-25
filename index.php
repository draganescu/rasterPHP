<?php

// #The index file
// Raster is a normal web framework so all requests have a single
// entry point, which is the index.php file. It can be renamed and it serves
// as the entry point for one application.

// ### Development server
// `php -S localhost:8000 index.php` runs the site with no web server.
// Static files (theme css, images, media) are served as they are, while code,
// configuration and data are never exposed (the same rules as .htaccess).
if (PHP_SAPI === 'cli-server') {
	$path = preg_replace('#/+#', '/', rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
	// every app folder (application/, demo/, ...) keeps its code and data private
	$apps = array();
	foreach (glob(__DIR__.'/*/config', GLOB_ONLYDIR) as $dir) $apps[] = preg_quote(basename(dirname($dir)), '#');
	$apps = implode('|', $apps ?: array('application'));
	$blocked = '#(^|/)\.|^/(system|bin|tests|docs)(/|$)|^/('.$apps.')/(config|models|data|i18n)(/|$)|^/('.$apps.')/views/.*\.(html|rss|atom|xml|json|txt)$|\.(php|sqlite|sql|md)$|-(journal|wal|shm)$#i';
	if (preg_match($blocked, $path) && $path !== '/index.php') {
		http_response_code(403);
		exit('Forbidden');
	}
	if ($path !== '/' && is_file(__DIR__.$path) && strpos(realpath(__DIR__.$path), __DIR__.'/') === 0) {
		return false;
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