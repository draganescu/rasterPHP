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
			session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true));
		}
	}

	// Ends a successful form post: redirects to the same page with
	// ?done=<name>, so reloading doesn't send the form again. Blocks written
	// as <!-- print.validation.alert('name') --> show after the redirect.
	static function done($name = 'done', $location = null)
	{
		$name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
		if ($location === null) {
			$location = config::get('base_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/');
		}
		$location .= (strpos($location, '?') === false ? '?' : '&').'done='.$name;
		if (PHP_SAPI === 'cli') return $location;
		header('Location: '.$location, true, 303);
		exit;
	}

	// call after changing content so cached pages are rebuilt
	static function content_changed()
	{
		raster_cache::bump();
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
// Whole pages are cached for visitors (no session, no query string) and
// thrown away when content changes. On by default in production; set
// config page_cache to true or false to decide yourself.
class raster_cache {

	static $cacheable = null;

	static function dir() {
		return APPBASE.'data/cache/';
	}

	static function enabled() {
		return (bool)config::get('page_cache', config::get('environment') === 'production');
	}

	static function key() {
		// multilingual sites cache one copy per language
		$language = config::get('languages') ? i18n::detect() : '';
		return sha1(config::get('host').'|'.config::get('uri_string').'|'.$language);
	}

	static function cacheable() {
		if (self::$cacheable !== null) return self::$cacheable;
		$uri = (string)config::get('uri_string');
		$ok = self::enabled()
			&& isset($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'))
			&& empty($_SERVER['QUERY_STRING'])
			&& !isset($_COOKIE[session_name()])
			&& !preg_match('#^/(api|mcp|login)(/|$)#', $uri);
		foreach ((array)config::get('page_cache_skip', array()) as $pattern) {
			if (preg_match('%^/?'.$pattern.'%', $uri)) $ok = false;
		}
		return self::$cacheable = $ok;
	}

	static function version() {
		$file = self::dir().'version';
		return is_file($file) ? (int)file_get_contents($file) : 0;
	}

	static function bump() {
		if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
		@file_put_contents(self::dir().'version', (string)(self::version() + 1), LOCK_EX);
	}

	static function serve() {
		if (!self::cacheable()) return;
		$file = self::dir().self::key();
		if (!is_file($file)) return;
		$handle = fopen($file, 'r');
		$meta = json_decode((string)fgets($handle), true);
		$ttl = (int)config::get('page_cache_ttl', 3600);
		if (!$meta || $meta['v'] !== self::version() || time() - $meta['t'] > $ttl) { fclose($handle); return; }
		header('Content-Type: '.$meta['type']);
		header('X-Raster-Cache: hit');
		fpassthru($handle);
		fclose($handle);
		exit;
	}

	static function store($output) {
		if (!self::cacheable() || http_response_code() !== 200 || session_status() === PHP_SESSION_ACTIVE) return;
		$type = 'text/html; charset=utf-8';
		foreach (headers_list() as $header) {
			if (stripos($header, 'Set-Cookie:') === 0) return;
			if (stripos($header, 'Content-Type:') === 0) $type = trim(substr($header, 13));
		}
		if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
		$meta = json_encode(array('v' => self::version(), 't' => time(), 'type' => $type));
		@file_put_contents(self::dir().self::key(), $meta."\n".$output, LOCK_EX);
		if (!headers_sent()) header('X-Raster-Cache: miss');
	}
}
