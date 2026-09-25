<?php
// An application model. Models are plain classes whose methods return data:
// strings for print, arrays of rows for render, false to keep the markup
// that is already in the view.
class site {

	function year() {
		return date('Y');
	}

	// used as <!-- render.site.nav --> in _layout.html
	function nav() {
		$current = '/'.trim((string)config::get('uri_string'), '/');
		$links = array(
			array('label' => 'Home', 'url' => '/'),
			array('label' => 'News', 'url' => '/news'),
			array('label' => 'About', 'url' => '/about'),
		);
		foreach ($links as $i => $link) {
			$active = $link['url'] === '/' ? $current === '/' : strpos($current, $link['url']) === 0;
			$links[$i]['url'] = rtrim(config::get('link_uri'), '/').$link['url'];
			$links[$i]['state'] = $active ? 'active' : '';
		}
		return $links;
	}
}
