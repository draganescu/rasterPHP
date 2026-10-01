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
		// PHP's server would look for the file under the folder, so it is sent
		// from here, with what browsers need from it: the type, a date to
		// revalidate against, and byte ranges (video seeking)
		$file = __DIR__.$path;
		$types = array('css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'mjs' => 'text/javascript; charset=UTF-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'txt' => 'text/plain; charset=UTF-8', 'html' => 'text/html; charset=UTF-8', 'xml' => 'application/xml', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav');
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$type = isset($types[$ext]) ? $types[$ext] : (function_exists('mime_content_type') ? (mime_content_type($file) ?: '') : '');
		header('Content-Type: '.($type ?: 'application/octet-stream'));
		$modified = filemtime($file);
		header('Last-Modified: '.gmdate('D, d M Y H:i:s', $modified).' GMT');
		header('Accept-Ranges: bytes');
		if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $modified) {
			http_response_code(304);
			exit;
		}
		$size = filesize($file);
		$start = 0;
		$end = $size - 1;
		if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $range) && ($range[1] !== '' || $range[2] !== '')) {
			if ($range[1] === '') {
				// bytes=-500 is the last 500 bytes
				$start = max(0, $size - (int)$range[2]);
			} else {
				$start = (int)$range[1];
				if ($range[2] !== '') $end = min($end, (int)$range[2]);
			}
			if ($start > $end) {
				http_response_code(416);
				header('Content-Range: bytes */'.$size);
				exit;
			}
			http_response_code(206);
			header("Content-Range: bytes $start-$end/$size");
		}
		header('Content-Length: '.($end - $start + 1));
		if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
			$handle = fopen($file, 'rb');
			fseek($handle, $start);
			$left = $end - $start + 1;
			while ($left > 0 && !feof($handle)) {
				$chunk = fread($handle, min(65536, $left));
				echo $chunk;
				$left -= strlen($chunk);
			}
			fclose($handle);
		}
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