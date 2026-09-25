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
