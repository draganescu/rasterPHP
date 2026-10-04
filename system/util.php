<?php

// the util class is aimed at making the work with Raster easier
// providing a view wrappers for often uses situations
class util {

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

	// Absolute links are safe to email when the site's address is configured
	// (RASTER_URL or site_url), or in development. Otherwise the address
	// would come from the visitor's Host header and could be forged.
	static function trusted_links()
	{
		return (bool)config::get('trusted_url') || config::get('environment') === 'development' || PHP_SAPI === 'cli';
	}

	// call after changing content so cached pages are rebuilt
	static function content_changed()
	{
		raster_cache::bump();
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
		$ok = self::enabled()
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
	// reads it gets the old file or the new one, never part of one
	static function write($file, $content) {
		if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
		$temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
		if (@file_put_contents($temporary, $content) === false || !@rename($temporary, $file)) @unlink($temporary);
	}

	// content changed: a new version, and the pages cached under the old one
	// are deleted. Files other requests are still writing are left alone;
	// one an hour old was left by a request that died before it was done.
	// The version is random, not counted, so it is never read to make the
	// next one and an old one never comes back. Returns how many pages were
	// deleted.
	static function bump() {
		self::write(self::dir().'version', bin2hex(random_bytes(8)));
		$removed = 0;
		foreach (glob(self::dir().'*') ?: array() as $file) {
			if (preg_match('/^[0-9a-f]{40}$/', basename($file)) && @unlink($file)) $removed++;
			elseif (substr($file, -4) === '.tmp' && @filemtime($file) < time() - 3600) @unlink($file);
		}
		return $removed;
	}

	// throws every cached page away: for changes Raster can't see, such as
	// views, models or the database edited directly. Returns the new version
	// and how many cached pages were deleted.
	static function clear() {
		$removed = self::bump();
		return array('version' => self::version(), 'removed' => $removed);
	}

	// an item scheduled for later: the cache is thrown away at that time
	static function schedule($timestamp) {
		if ($timestamp <= time()) return;
		$file = self::dir().'next';
		$next = is_file($file) ? (int)file_get_contents($file) : 0;
		if ($next === 0 || $next <= time() || $timestamp < $next) self::write($file, (string)$timestamp);
	}

	// a scheduled item's time has come: that's a content change too
	static function publish_due() {
		$next = self::dir().'next';
		if (is_file($next) && (int)file_get_contents($next) <= time()) {
			@unlink($next);
			self::bump();
		}
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
		header('X-Raster-Cache: hit');
		fpassthru($handle);
		fclose($handle);
		exit;
	}

	static function store($output) {
		if (!self::cacheable() || http_response_code() !== 200 || session_status() === PHP_SESSION_ACTIVE) return;
		// content changed while the page was made: it may show the old content
		if (self::$version === null || self::$version !== self::version()) return;
		$type = 'text/html; charset=utf-8';
		foreach (headers_list() as $header) {
			if (stripos($header, 'Set-Cookie:') === 0) return;
			if (stripos($header, 'Content-Type:') === 0) $type = trim(substr($header, 13));
		}
		$meta = json_encode(array('v' => self::$version, 't' => time(), 'type' => $type));
		self::write(self::dir().self::key(), $meta."\n".$output);
		if (!headers_sent()) header('X-Raster-Cache: miss');
	}
}
