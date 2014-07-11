<?php
class i18n
{
	var $loaded = array();
	var $lang = array();

	// language detection
	// check by domain and so on
	function i18n()
	{
		$app = controller::instance();
		$app->detected_language = $this->detect_language();	
	}

	// gets the required string depending on detected language
	function __call($method, $args)
	{
		$app = controller::instance();
		$language = $this->get_lang();
		$lang = array();
		
		if(!in_array($method, $this->loaded))
		{
			$lang_file = BASE.'../models/i18n/L10n/'.$language.'/'.$method.'.php';
			$default_lang_file = BASE.'../models/i18n/L10n/'.$app->default_language.'/'.$method.'.php';

			if ( file_exists($lang_file) ) require_once $lang_file;
			elseif ( file_exists($default_lang_file) ) require_once $default_lang_file;
			else return false;

			$this->loaded[] = $method;
			$this->lang = array_merge($this->lang, $lang);
		}
		
		$lang = $this->lang;

		if (array_key_exists($args[0], $lang) ) return $lang[$args[0]];
		else return $app->current_block;
		//else return false;
	}

	// method of detecting by domain 
	// and from the header of the user
	// or if our magic cookie exists
	function detect_language()
	{
		$app = controller::instance();

		foreach($app->domain_language as $server=>$lang)
			if(strpos($app->uri_string, $server) !== false) return $lang;
		
		$cookie_lang = util::cookie($app->language_cookie);
		if ( $cookie_lang )
			return $cookie_lang;

		if ( array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER) )
			$browser_lang = $this->parse_header($_SERVER['HTTP_ACCEPT_LANGUAGE']);
		if( !empty($browser_lang) and isset($browser_lang) )
			return $browser_lang;
		
		if ( empty($app->domain_language) )
			return '';

		return '';		

	}

	function parse_header($header)
	{
		// break up string into pieces (languages and q factors)
		preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $header, $lang_parse);

	    if ( count($lang_parse[1]) ) {
	        // create a list like "en" => 0.8
	        $langs = array_combine($lang_parse[1], $lang_parse[4]);
	    	
	        // set default to 1 for any without q factor
	        foreach ($langs as $lang => $val) {
	            if ($val === '') $langs[$lang] = 1;
	        }

	        // sort list based on value	
	        arsort($langs, SORT_NUMERIC);
	    }
	    if(is_array($lang))
		    if(array_key_exists(0, $lang))
			    return $langs[0];
			else
				return null;
		else
			return null;
	}

	function get_lang()
	{
		$app = controller::instance();
		$app->detected_language != '' ? $language = $app->detected_language : $language = $app->default_language;
		return $language;
	}

	function set_language_cookie()
	{
		$app = my::app();
		$cookie_name = $app->language_cookie;
		$language = $this->get_lang();

		setcookie($cookie_name, $language, time()+3600, '/', $app->current_server);
	}

}