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
*   config::set('multilingual')->to(true);              // one address per language: /ro/menu
*   config::set('languages')->to(array('en', 'ro'));   // first one is the default
*   config::set('domain_language')->to(array('example.ro' => 'ro'));
*
* Multilingual, the address alone picks the language: a domain_language
* host, then the /ro/ prefix, then the default. Without multilingual
* (template-only translation, deprecated, removed in 3.0.0) it is picked
* from ?lang=xx (remembered in a cookie), then the domain, the cookie, the
* browser's Accept-Language, then the default.
*
*   <!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr"><!-- /print.@dir.i18n.dir --><!-- /print.@lang.i18n.language -->
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

	// ##The language API (#200)
	// Every model reads language state through these, never through
	// config::get('languages'). With multilingual off they give the
	// one-language answer: enabled() false, the suffix and prefix empty.
	// config::initialize() sets `url_language` (the language the URL names,
	// '' when off) and `link_base` (link_uri without the language prefix).
	// Raster calls them on this class, so a site's the_i18n can't change
	// them (it can change what templates print through print.i18n).

	protected static $current = null;

	// multilingual is on and there are two or more languages (D1)
	static function enabled() {
		return (bool)config::get('multilingual', false) && count(self::available()) >= 2;
	}

	// the first language, or '' when languages is unset
	static function default_language() {
		$languages = self::available();
		return $languages ? $languages[0] : '';
	}

	// this request's content language: the one i18n::with() runs in, then
	// the URL's when multilingual (D4), otherwise what detect() picks
	static function current() {
		if (self::$current !== null) return self::$current;
		if (!self::enabled()) return self::detect();
		$language = self::known(config::get('url_language'));
		return $language !== null ? $language : self::default_language();
	}

	static function is_default($lang = null) {
		$lang = $lang === null ? self::current() : (string)$lang;
		return strtolower($lang) === strtolower(self::default_language());
	}

	// the column suffix of a translation (D3): '' when off and for the
	// default language, else '__ro', '__pt_br', '__id'
	static function suffix($lang = null) {
		if (!self::enabled()) return '';
		$lang = $lang === null ? self::current() : (string)$lang;
		if ($lang === '' || self::is_default($lang)) return '';
		return '__'.strtolower(str_replace('-', '_', $lang));
	}

	// the URL prefix of a language on this host: '' when off, for the
	// default language and on the language's own domain (domain_language),
	// else 'ro/'
	static function prefix($lang = null) {
		if (!self::enabled()) return '';
		$lang = $lang === null ? self::current() : (string)$lang;
		if ($lang === '' || self::is_default($lang)) return '';
		$domains = array_change_key_case((array)config::get('domain_language', array()));
		$host = self::host();
		if (isset($domains[$host]) && strtolower($domains[$host]) === strtolower($lang)) return '';
		return strtolower($lang).'/';
	}

	// runs $fn with current() set to $lang (a language the site doesn't
	// list runs in the default one) and link_uri set to that language's
	// address (kept as it is when it has none, see address()), puts both
	// back, even when $fn throws, and returns what $fn returned. Calls
	// inside it nest.
	static function with($lang, $fn) {
		$current = self::$current;
		$link = config::get('link_uri');
		$known = self::known($lang);
		self::$current = $known !== null ? $known : self::default_language();
		$address = self::enabled() && config::get('link_base') ? self::address(self::$current) : null;
		$moved = $address !== null;
		if ($moved) config::set('link_uri')->to($address);
		try {
			return $fn();
		} finally {
			self::$current = $current;
			if ($moved) config::set('link_uri')->to($link);
		}
	}

	// 'rtl' for languages written right to left, else 'ltr'
	static function dir($lang = null) {
		$lang = $lang === null ? self::current() : (string)$lang;
		$base = strtolower(strtok($lang, '-'));
		return in_array($base, array('ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'), true) ? 'rtl' : 'ltr';
	}

	// the order dates are typed in (D14): '' without languages, 'mdy' for
	// en-us, 'dmy' for every other language; Y-m-d is always read
	static function date_order($lang = null) {
		if (!self::available()) return '';
		$lang = $lang === null ? self::current() : (string)$lang;
		return strtolower($lang) === 'en-us' ? 'mdy' : 'dmy';
	}

	// the current page (or $path, as it reads, not encoded) in every
	// language that has an address: rows of lang and url, each segment
	// encoded as the one-address redirect writes it, for the switcher,
	// hreflang and the sitemap
	static function alternates($path = null) {
		$path = $path === null ? ltrim(strtok((string)config::get('uri_string'), '?'), '/') : ltrim((string)$path, '/');
		$path = implode('/', array_map('rawurlencode', explode('/', $path)));
		$rows = array();
		foreach (self::available() as $code) {
			$address = self::address($code);
			if ($address !== null) $rows[] = array('lang' => $code, 'url' => $address.$path);
		}
		return $rows;
	}

	// where a language's pages start: link_base and its prefix on this
	// host. A language with its own domain_language host is there, and on
	// such a host the others are on the site's own address (site_url), the
	// host that takes prefixes; both keep the folder and the index file
	// (config::language_of_url redirects there too). On a language's
	// domain without a site address to send the others to, they have
	// none: null (`doctor` fails on that).
	static function address($lang) {
		$base = (string)(config::get('link_base') ?: config::get('link_uri'));
		if (!self::enabled()) return $base;
		$domains = array_change_key_case((array)config::get('domain_language', array()));
		$host = self::host();
		$protocol = (string)config::get('protocol');
		$after_host = substr($base, strlen($protocol.'://'.config::get('host')));
		foreach ($domains as $domain => $language) {
			if (strtolower($language) !== strtolower($lang) || self::known($language) === null) continue;
			return $domain === $host ? $base : $protocol.'://'.$domain.$after_host;
		}
		if (isset($domains[$host]) && self::known($domains[$host]) !== null) {
			$site = parse_url((string)(getenv('RASTER_URL') ?: config::get('site_url')));
			if (empty($site['host']) || isset($domains[strtolower($site['host'])])) return null;
			return $protocol.'://'.strtolower($site['host']).(isset($site['port']) ? ':'.$site['port'] : '').$after_host.(self::is_default($lang) ? '' : strtolower($lang).'/');
		}
		return $base.self::prefix($lang);
	}

	// this request's host, without the port
	protected static function host() {
		return strtolower(preg_replace('/:\d+$/', '', (string)config::get('host')));
	}

	// the listed language $candidate names (ro, RO, ro-RO all give ro), or null
	protected static function known($candidate) {
		$candidate = strtolower((string)$candidate);
		if ($candidate === '') return null;
		$languages = self::available();
		foreach ($languages as $language) {
			if (strtolower($language) === $candidate) return $language;
		}
		foreach ($languages as $language) {
			if (strtolower($language) === substr($candidate, 0, 2)) return $language;
		}
		return null;
	}

	// the language of this request: inside i18n::with() its language;
	// multilingual, current(); otherwise ?lang=xx (remembered in a cookie),
	// the domain, the cookie, Accept-Language, then the default
	static function detect() {
		if (self::$current !== null) return self::$current;
		if (self::enabled()) return self::current();
		if (self::$detected !== null) return self::$detected;
		if (!self::available()) return self::$detected = '';
		if (isset($_GET['lang']) && ($language = self::known($_GET['lang']))) {
			if (PHP_SAPI !== 'cli' && !headers_sent()) {
				setcookie(self::cookie_name(), $language, array('expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax'));
			}
			return self::$detected = $language;
		}
		$host = preg_replace('/:\d+$/', '', (string)config::get('host'));
		foreach ((array)config::get('domain_language', array()) as $domain => $language) {
			if ($host === $domain || substr($host, -strlen('.'.$domain)) === '.'.$domain) return self::$detected = $language;
		}
		if (isset($_COOKIE[self::cookie_name()]) && ($language = self::known($_COOKIE[self::cookie_name()]))) {
			return self::$detected = $language;
		}
		if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			foreach (self::parse_header($_SERVER['HTTP_ACCEPT_LANGUAGE']) as $candidate) {
				if ($language = self::known($candidate)) return self::$detected = $language;
			}
		}
		return self::$detected = self::default_language();
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

	// a switcher: rows with code, url (this page in that language), state
	// (active or empty) and current (true for this page's language). In
	// multilingual mode the url is the language's own address (/ro/menu),
	// otherwise a ?lang= link
	function languages() {
		$current = self::detect();
		$urls = array();
		if (self::enabled()) {
			foreach (self::alternates() as $row) $urls[$row['lang']] = $row['url'];
		}
		$path = rtrim(config::get('link_uri'), '/').strtok((string)config::get('uri_string'), '?');
		$rows = array();
		foreach (self::available() as $code) {
			$url = isset($urls[$code]) ? $urls[$code] : $path.'?lang='.$code;
			$rows[] = array('code' => $code, 'url' => $url, 'state' => $code === $current ? 'active' : '', 'current' => $code === $current);
		}
		return $rows;
	}

	// print.i18n.<file>('key'): the translation, or false to keep the template text
	function __call($file, $args) {
		if (!preg_match('/^[a-z0-9_]+$/', $file) || !isset($args[0])) return false;
		$language = self::current();
		if ($language === '' || self::is_default($language)) return false;
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
