<?php

// the util class is aimed at making the work with Raster easier
// providing a view wrappers for often uses situations
class util {

	// the site's key for signing things (the form stamp): RASTER_SECRET, else
	// data/secret, made on the first use. '' when there is none to be had.
	static $secret = null;
	static function secret() {
		if (self::$secret !== null) return self::$secret;
		$env = getenv('RASTER_SECRET');
		if ($env !== false && $env !== '') return self::$secret = $env;
		$file = APPBASE.'data/secret';
		if (!is_file($file)) {
			if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
			// 'x' fails when the file exists, so two first requests can't
			// write different keys: the loser reads the winner's
			$handle = @fopen($file, 'x');
			if ($handle) {
				fwrite($handle, bin2hex(random_bytes(32)));
				fclose($handle);
				@chmod($file, 0600);
			}
		}
		return self::$secret = trim((string)@file_get_contents($file));
	}

	// get the value from a key/value set passed in the url
	static function param($name, $v = false)
	{
		$uri_segments=(array)config::get('uri_segments');
		$value=false;
		if(in_array($name, $uri_segments))
			if(array_key_exists(array_search($name, $uri_segments) + 1, $uri_segments))
				$value = $uri_segments[array_search($name, $uri_segments) + 1];
		(!$value) ? $ret = $v : $ret = $value;
		return $ret;
	}
	
	// redirect to a location within the app
	static function redirect($location = '')
	{
		$base = config::get('link_uri');
		header("Location: ".$base.ltrim($location, '/'));
		exit;
	}

	// removes duplicate matches from a preg_match_all result, keeping the
	// groups aligned (used by the template loops)
	static function unique_matches($matches)
	{
		if (empty($matches) || empty($matches[0])) return $matches;
		$keep = array_keys(array_unique($matches[0]));
		foreach ($matches as $group => $values) {
			$matches[$group] = array_values(array_intersect_key($values, array_flip($keep)));
		}
		return $matches;
	}

	// escapes a value for HTML output
	static function e($value)
	{
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}

	// Starts the session only for people who already have one (editors,
	// members) or when $force is true (logging in). Visitors get no cookie.
	static function session($force = false)
	{
		if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) return;
		if ($force || isset($_COOKIE[session_name()])) {
			session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true,
				'cookie_secure' => config::get('protocol') === 'https'));
		}
	}

	// Ends a successful form post: redirects to the same page with
	// ?done=<name>, so reloading doesn't send the form again. Blocks written
	// as <!-- print.validation.alert('name') --> show after the redirect.
	static function done($name = 'done', $location = null)
	{
		$name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
		if ($location === null) {
			$location = config::get('link_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/');
		}
		$location .= (strpos($location, '?') === false ? '?' : '&').'done='.$name;
		if (PHP_SAPI === 'cli') return $location;
		header('Location: '.$location, true, 303);
		exit;
	}

	// An absolute link to a page of the site, for emails and redirects:
	// util::url('letters/confirm', array('token' => $t))
	static function url($page = '', $query = array())
	{
		$url = rtrim((string)config::get('link_uri'), '/').'/'.ltrim((string)$page, '/');
		return $query ? $url.'?'.http_build_query($query) : $url;
	}

	// Absolute links are safe to email when the site's address is configured
	// (RASTER_URL or site_url), or in development. Otherwise the address
	// would come from the visitor's Host header and could be forged.
	static function trusted_links()
	{
		return (bool)config::get('trusted_url') || config::get('environment') === 'development' || PHP_SAPI === 'cli';
	}

	// call after changing content so cached pages are rebuilt. Inside
	// cms_records::transaction() it waits for the commit, and comes once
	// however many writes there were; after a rollback it doesn't come
	static function content_changed()
	{
		if (class_exists('cms_records', false) && cms_records::$depth > 0) {
			cms_records::$changed = true;
			return;
		}
		if (raster_cache::bump() === false) log::warning("page cache: can't write ".raster_cache::dir().'version, so visitors still get the pages cached before this change');
		event::dispatch('content_changed');
	}

	// a per session token that forms changing data must send back
	static function csrf_token()
	{
		if (session_status() !== PHP_SESSION_ACTIVE) return '';
		if (empty($_SESSION['raster_csrf'])) {
			$_SESSION['raster_csrf'] = bin2hex(random_bytes(16));
		}
		return $_SESSION['raster_csrf'];
	}

	static function csrf_valid($token)
	{
		return is_string($token) && $token !== '' && hash_equals(util::csrf_token(), $token);
	}

	/* these are used for forms management and to be able to hook xss filters */

	// get a value of the $_POST array
	static function post($index_name)
	{
		config::set('post_pointer')->to($index_name);
		if(!array_key_exists($index_name, $_POST))
			return false;
		event::dispatch("read_post_data");
		return $_POST[$index_name];
	}

	// get a value of the $_COOKIE array
	static function cookie($index_name)
	{
		config::set('cookie_pointer')->to($index_name);
		if(!array_key_exists($index_name, $_COOKIE))
			return false;
		event::dispatch("read_cookie_data");
		return $_COOKIE[$index_name];
	}

	// get a value of the $_GET array
	static function get($index_name)
	{
		config::set('get_pointer')->to($index_name);
		if(!array_key_exists($index_name, $_GET))
			return false;
		event::dispatch("read_get_data");
		return $_GET[$index_name];
	}
	// retrieve a portion of the $_POST array
	static function post_filter()
	{
		$args = func_get_args();
		return array_intersect_key($_POST, array_flip($args));
	}
	// boolean check if there is any data in $_GET
	static function no_get_data()
	{
		if(count($_GET) > 0)
			return false;
		else
			return true;
	}
	// boolean check if there is any data in $_POST
	static function no_post_data()
	{
		if(count($_POST) > 0)
			return false;
		else
			return true;
	}
}

// a path relative to the project folder, for messages
function raster_path($path) {
	$root = dirname(BASE).'/';
	return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
}

// #Page cache
// Whole pages are cached for visitors (no session, no query string but
// tracking parameters) and thrown away when content changes. On by default
// in production; set config page_cache to true or false to decide yourself.
class raster_cache {

	static $cacheable = null;
	// the version when the request started: the page is stored under it, so
	// one whose render overlapped a content change is never served as fresh
	static $version = null;
	// query parameters that don't change the page: a link shared with
	// ?utm_source=… or an ad's click id
	static $tracking = '/^(utm_[a-z0-9_]*|fbclid|gclid|msclkid)$/i';

	static function dir() {
		return APPBASE.'data/cache/';
	}

	static function enabled() {
		if (getenv('RASTER_EXPORT')) return false;
		return (bool)config::get('page_cache', config::get('environment') === 'production');
	}

	static function key() {
		// multilingual sites cache one copy per language
		$language = config::get('languages') ? i18n::detect() : '';
		return sha1(config::get('protocol').'://'.config::get('host').'|'.config::get('uri_string').'|'.$language);
	}

	static function cacheable() {
		if (self::$cacheable !== null) return self::$cacheable;
		$uri = (string)config::get('uri_string');
		// `raster render` (and MCP render_url) always makes the page afresh,
		// and keeps no copy of it
		$ok = self::enabled() && !defined('RASTER_RENDER')
			&& isset($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'))
			&& !isset($_COOKIE[session_name()])
			&& !preg_match('#^/(api|mcp|login)(/|$)#', $uri);
		// a link with only tracking parameters is the same page
		foreach (explode('&', isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '') as $pair) {
			if ($pair !== '' && !preg_match(self::$tracking, urldecode(strtok($pair, '=')))) $ok = false;
		}
		foreach ((array)config::get('page_cache_skip', array()) as $pattern) {
			if (preg_match('%^/?'.$pattern.'%', $uri)) $ok = false;
		}
		return self::$cacheable = $ok;
	}

	// a page the request decided not to keep, though it answers 200: a filter
	// page with no items, a page past the last one
	static function skip() {
		self::$cacheable = false;
	}

	static function version() {
		$file = self::dir().'version';
		return is_file($file) ? (string)file_get_contents($file) : '';
	}

	// writes a file whole, under a temporary name it then takes: whoever
	// reads it gets the old file or the new one, never part of one. Returns
	// whether it wrote.
	static function write($file, $content) {
		if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
		$temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
		if (@file_put_contents($temporary, $content) !== false && @rename($temporary, $file)) return true;
		@unlink($temporary);
		return false;
	}

	// content changed: a new version, and the pages cached under the old one
	// are deleted. Files other requests are still writing are left alone;
	// one an hour old was left by a request that died before it was done.
	// The version is random, not counted, so it is never read to make the
	// next one and an old one never comes back. Returns how many pages were
	// deleted, or false when the version can't be written (the cache belongs
	// to another user): the cached pages are then still served.
	static function bump() {
		$written = self::write(self::dir().'version', bin2hex(random_bytes(8)));
		$removed = 0;
		foreach (glob(self::dir().'*') ?: array() as $file) {
			if (preg_match('/^[0-9a-f]{40}$/', basename($file)) && @unlink($file)) $removed++;
			elseif (substr($file, -4) === '.tmp' && @filemtime($file) < time() - 3600) @unlink($file);
		}
		return $written ? $removed : false;
	}

	// throws every cached page away: for changes Raster can't see, such as
	// views, models or the database edited directly. Returns the new version,
	// how many cached pages were deleted and an error when it could not.
	static function clear() {
		// and what controller::models() keeps of the models and views
		@unlink(self::dir().'models.json');
		$removed = self::bump();
		if ($removed === false) {
			return array('version' => self::version(), 'removed' => 0, 'error' => "can't write ".self::dir().'version, so the page cache was not cleared.'
				.' Run this as the user the web server runs as, or let this user write to that folder');
		}
		return array('version' => self::version(), 'removed' => $removed);
	}

	// an item scheduled for later: the cache is thrown away at that time.
	// data/cache/next keeps every time still to come, one per line.
	static function schedule($timestamp) {
		if ($timestamp <= time()) return;
		self::reschedule(function ($times) use ($timestamp) {
			$times[] = (int)$timestamp;
			return $times;
		});
	}

	// a scheduled item's time has come: that's a content change too. The
	// cache is thrown away once however many came, and the later times stay.
	static function publish_due() {
		$file = self::dir().'next';
		$times = is_file($file) ? array_map('intval', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : array();
		if (!$times || min($times) > time()) return;
		$due = false;
		self::reschedule(function ($times) use (&$due) {
			$later = array_filter($times, function ($time) { return $time > time(); });
			$due = count($later) < count($times);
			return $later;
		});
		if ($due && self::bump() === false) log::warning("page cache: can't write ".self::dir().'version, so a scheduled item waits for page_cache_ttl');
	}

	// changes the scheduled times under a lock, so a time scheduled while
	// another request publishes the ones due is never lost
	static function reschedule($change) {
		if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
		$handle = @fopen(self::dir().'next', 'c+');
		if (!$handle) {
			log::warning("page cache: can't write ".self::dir().'next, so scheduled items wait for page_cache_ttl');
			return;
		}
		flock($handle, LOCK_EX);
		$times = array_map('intval', array_filter(explode("\n", (string)stream_get_contents($handle))));
		$times = array_unique($change($times));
		sort($times);
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, $times ? implode("\n", $times)."\n" : '');
		flock($handle, LOCK_UN);
		fclose($handle);
	}

	static function serve() {
		if (!self::cacheable()) return;
		// the page is made as the plain URL would be, so tracking values
		// (anyone's to choose) never end up in the copy every visitor gets
		foreach (array_keys($_GET) as $name) {
			if (preg_match(self::$tracking, $name)) unset($_GET[$name]);
		}
		self::publish_due();
		self::$version = self::version();
		$file = self::dir().self::key();
		$handle = @fopen($file, 'r');
		if (!$handle) return;
		$meta = json_decode((string)fgets($handle), true);
		$ttl = (int)config::get('page_cache_ttl', 3600);
		if (!$meta || $meta['v'] !== self::$version || time() - $meta['t'] > $ttl) { fclose($handle); return; }
		header('Content-Type: '.$meta['type']);
		// the headers the page was made with, a name given twice sent twice
		$sent = array();
		foreach (isset($meta['headers']) ? (array)$meta['headers'] : array() as $header) {
			$name = strtolower(strtok($header, ':'));
			header($header, !isset($sent[$name]));
			$sent[$name] = true;
		}
		header('Last-Modified: '.self::http_date($meta['t']));
		header('X-Raster-Cache: hit');
		// the browser's copy is as new as this one
		$since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
		if ($since !== false && $since >= $meta['t']) http_response_code(304);
		elseif (!empty($meta['ts'])) {
			// the forms on this page carry a marker where the stamp goes:
			// one fresh stamp for all of them, so "too fast" works on a cached page
			echo str_replace('{{raster:ts}}', validation_spam::stamp(), (string)stream_get_contents($handle));
		}
		else fpassthru($handle);
		fclose($handle);
		exit;
	}

	// a time as HTTP writes it, in GMT whatever the site's time zone
	static function http_date($time) {
		return gmdate('D, d M Y H:i:s', (int)$time).' GMT';
	}

	// Is the view file spelled as the request found it? Each name under the
	// views folder is looked up in its folder's listing, so it is asked only
	// when a page is about to be stored.
	static function spelled_as_on_disk($file) {
		foreach (array(APPBASE.config::get('views_path').DIRECTORY_SEPARATOR, BASE.'views/') as $root) {
			if (strpos((string)$file, $root) !== 0) continue;
			$folder = $root;
			foreach (explode('/', str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)))) as $name) {
				if (!in_array($name, @scandir($folder) ?: array(), true)) return false;
				$folder .= $name.'/';
			}
			return true;
		}
		return true;
	}

	static function store($output) {
		if (!self::cacheable() || http_response_code() !== 200 || session_status() === PHP_SESSION_ACTIVE) return;
		// content changed while the page was made: it may show the old content
		if (self::$version === null || self::$version !== self::version()) return;
		$type = 'text/html; charset=utf-8';
		// what models and listeners set (X-Robots-Tag, a CORS header) goes
		// out again with every copy; PHP's own X-Powered-By it sends itself
		$headers = array();
		foreach (headers_list() as $header) {
			$name = strtolower(trim(strtok($header, ':')));
			if ($name === 'set-cookie') return;
			if ($name === 'content-type') $type = trim(substr($header, 13));
			elseif (!in_array($name, array('x-powered-by', 'last-modified', 'x-raster-cache'), true)) $headers[] = $header;
		}
		// /ABOUT finds about.html on a disk that ignores case: it answers,
		// but every spelling would be a copy of its own
		if (!self::spelled_as_on_disk(controller::instance()->current_route)) return;
		$time = time();
		$meta = array('v' => self::$version, 't' => $time, 'type' => $type, 'headers' => $headers);
		// the form stamps are not kept: each is a marker serve() fills in
		$output = preg_replace('/(name="raster_ts" value=")[^"]*"/', '$1{{raster:ts}}"', $output, -1, $stamps);
		if ($stamps) $meta['ts'] = true;
		$meta = json_encode($meta);
		if (!self::write(self::dir().self::key(), $meta."\n".$output) || headers_sent()) return;
		header('Last-Modified: '.self::http_date($time));
		header('X-Raster-Cache: miss');
	}
}
