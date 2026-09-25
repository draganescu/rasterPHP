<?php
/**
* i18n
*
* The text in the template is the default language. Other languages come
* from dictionaries in application/i18n/<language>/<file>.php:
*
*   <?php return array('welcome' => 'Bine ați venit');
*
* and are used like this:
*
*   <h1><!-- print.i18n.home('welcome') -->Welcome<!-- /print.i18n.home('welcome') --></h1>
*
* A missing translation keeps the template's text.
*
* Settings (application/config/the_app.php):
*   config::set('languages')->to(array('en', 'ro'));   // first one is the default
*   config::set('domain_language')->to(array('example.ro' => 'ro'));
*
* The language is picked from ?lang=xx (remembered in a cookie), then the
* domain, the cookie, the browser's Accept-Language, then the default.
*
*   <html lang="<!-- print.i18n.language /-->">
*   <!-- render.i18n.languages --><!-- print.+class.state --><!-- print.@href.url --><a href="#"><!-- print.code -->en<!-- /print.code --></a><!-- /print.@href.url --><!-- /print.+class.state --><!-- /render.i18n.languages -->
*/
class i18n
{
	protected $loaded = array();
	protected static $detected = null;

	static function available() {
		$languages = (array)config::get('languages', array());
		return array_values(array_filter($languages, function ($l) { return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $l); }));
	}

	static function cookie_name() {
		return (string)config::get('language_cookie', 'lang');
	}

	// the language of this request
	static function detect() {
		if (self::$detected !== null) return self::$detected;
		$languages = self::available();
		if (!$languages) return self::$detected = '';
		$pick = function ($candidate) use ($languages) {
			$candidate = strtolower((string)$candidate);
			foreach ($languages as $language) {
				if (strtolower($language) === $candidate) return $language;
			}
			foreach ($languages as $language) {
				if (strtolower($language) === substr($candidate, 0, 2)) return $language;
			}
			return null;
		};
		if (isset($_GET['lang']) && ($language = $pick($_GET['lang']))) {
			if (PHP_SAPI !== 'cli' && !headers_sent()) {
				setcookie(self::cookie_name(), $language, array('expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax'));
			}
			return self::$detected = $language;
		}
		$host = preg_replace('/:\d+$/', '', (string)config::get('host'));
		foreach ((array)config::get('domain_language', array()) as $domain => $language) {
			if ($host === $domain || substr($host, -strlen('.'.$domain)) === '.'.$domain) return self::$detected = $language;
		}
		if (isset($_COOKIE[self::cookie_name()]) && ($language = $pick($_COOKIE[self::cookie_name()]))) {
			return self::$detected = $language;
		}
		if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			foreach (self::parse_header($_SERVER['HTTP_ACCEPT_LANGUAGE']) as $candidate) {
				if ($language = $pick($candidate)) return self::$detected = $language;
			}
		}
		return self::$detected = $languages[0];
	}

	// languages from an Accept-Language header, best first
	static function parse_header($header) {
		preg_match_all('/([a-z]{1,8}(?:-[a-z0-9]{1,8})?)\s*(?:;\s*q\s*=\s*(1(?:\.0+)?|0(?:\.\d+)?))?/i', $header, $m);
		$weights = array();
		foreach ($m[1] as $i => $language) {
			$weights[$language] = $m[2][$i] === '' ? 1.0 : (float)$m[2][$i];
		}
		arsort($weights, SORT_NUMERIC);
		return array_keys($weights);
	}

	function language() {
		$language = self::detect();
		return $language === '' ? false : $language;
	}

	// a switcher: rows with code, url and state (active or empty)
	function languages() {
		$current = self::detect();
		$path = rtrim(config::get('link_uri'), '/').strtok((string)config::get('uri_string'), '?');
		$rows = array();
		foreach (self::available() as $code) {
			$rows[] = array('code' => $code, 'url' => $path.'?lang='.$code, 'state' => $code === $current ? 'active' : '');
		}
		return $rows;
	}

	// print.i18n.<file>('key'): the translation, or false to keep the template text
	function __call($file, $args) {
		if (!preg_match('/^[a-z0-9_]+$/', $file) || !isset($args[0])) return false;
		$language = self::detect();
		$languages = self::available();
		if ($language === '' || ($languages && $language === $languages[0])) return false;
		$key = $language.'/'.$file;
		if (!isset($this->loaded[$key])) {
			$path = APPBASE.'i18n/'.$language.'/'.$file.'.php';
			$this->loaded[$key] = array();
			if (is_file($path)) {
				$lang = array();
				$returned = include $path;
				$this->loaded[$key] = is_array($returned) ? $returned : $lang;
			}
		}
		return isset($this->loaded[$key][$args[0]]) ? $this->loaded[$key][$args[0]] : false;
	}
}
