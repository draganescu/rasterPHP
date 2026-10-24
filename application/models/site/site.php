<?php
// An application model. Models are plain classes whose methods return data:
// strings for print, arrays of rows for render, false to keep the markup
// that is already in the view.
class site {

	// Methods offered as JSON at /api/site/<method>, with the least role that
	// may call each one (visitor, member, editor, admin). Nothing that isn't
	// listed is reachable there.
	static function api() {
		return array('year' => 'visitor');
	}

	function year() {
		return date('Y');
	}

	// used as <!-- render.site.nav --> in _layout.html: one row, a list per
	// link (print.home, print.news, print.about) of one row with its url and
	// state (active on its page). The words are the view's.
	function nav() {
		$current = '/'.trim((string)config::get('uri_string'), '/');
		$links = array('home' => '/', 'news' => '/news', 'about' => '/about');
		$row = array();
		foreach ($links as $name => $url) {
			$active = $url === '/' ? $current === '/' : strpos($current, $url) === 0;
			$row[$name] = array(array('url' => rtrim(config::get('link_uri'), '/').$url, 'state' => $active ? 'active' : ''));
		}
		return array($row);
	}
}
