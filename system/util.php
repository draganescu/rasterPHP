<?php

class util {
	static function param($name, $v = false)
	{
		$uri_segments=config::get('uri_segments');
		$value=false;
		if(in_array($name, $uri_segments))
			if(array_key_exists(array_search($name, $uri_segments) + 1, $uri_segments))
				$value = $uri_segments[array_search($name, $uri_segments) + 1];
		(!$value) ? $ret = $v : $ret = $value;
		return $ret;
	}
	
	// redirect to a location within the app
	static function redirect($location)
	{
		$base = config::get('base_url');
		header("Location: ".$base.$location);
		exit;
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