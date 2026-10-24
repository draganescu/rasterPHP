<?php
/**
* Feed
*
* Data for feeds and sitemaps. You write the XML or JSON as a view with the
* extension of its format (news.rss, sitemap.xml, feed.json); printed values
* are escaped for that format automatically.
*
*   <!-- render.feed.items('news') -->     newest published items of a collection, with
*                                          every field plus url, date_rfc822 (RSS) and date_iso
*   <!-- render.feed.pages -->             every page and published item, for sitemap.xml:
*                                          url and updated (YYYY-MM-DD); multilingual, a row
*                                          per language with alternates (lang, url)
*   <!-- print.feed.site_url /-->          the site's address
*/
class feed
{
	function site_url() {
		return config::get('link_uri');
	}

	// the newest published items of a collection, limit from config feed_limit (20)
	function items($collection, $limit = null) {
		if (!preg_match('/^[a-z][a-z0-9_]*$/', (string)$collection)) return array();
		// records of a private type (orders, bookings) are never in a feed or the sitemap
		if (cms_records::is_private($collection)) return array();
		$type = cms::collection_type($collection);
		database::instance();
		if (!database::configured() || !cms_store::table_exists($type)) return array();
		$columns = cms_store::columns($type);
		list($sql, $bindings) = cms_store::published_sql($columns);
		$limit = (int)($limit ?: config::get('feed_limit', 20));
		$sql .= ' ORDER BY '.cms_store::order_sql('newest', $columns).' LIMIT '.max(1, $limit);
		$rows = array();
		$record = cms_records::info($collection);
		foreach (R::find($type, $sql, $bindings) as $bean) {
			$item = cms_store::export_item($bean);
			// feeds print text; a public record type also has its lists
			// decoded, hidden fields out, and what visitors typed escaped
			$item = $record ? cms_records::for_template($record, cms_records::shown($record, cms_records::decode($record, $item))) : cms_types::show_row($item);
			$date = !empty($item['published_at']) ? $item['published_at'] : (!empty($item['updated_at']) ? $item['updated_at'] : 'now');
			$time = strtotime($date) ?: time();
			$item['url'] = cms::item_url($collection, $item);
			$item['date_rfc822'] = date(DATE_RSS, $time);
			$item['date_iso'] = date(DATE_ATOM, $time);
			$rows[] = $item;
		}
		return $rows;
	}

	// every page of the site and every published item of every public
	// collection whose items have pages (#126): rows of url and updated
	// (YYYY-MM-DD). Multilingual, each language's address is a row of its
	// own, with alternates: every language's address and x-default.
	function pages() {
		require_once BASE.'tools/inspector.php';
		$inspector = new raster_inspector();
		$link = rtrim(config::get('link_uri'), '/');
		// the pages the models in use need PHP for (login, the newsletter's
		// answers), and what sitemap_skip adds
		$skip = array_merge(controller::required('pages'), (array)config::get('sitemap_skip', array()));
		// a page that needs an account isn't listed, nor are the items of a
		// collection whose item page does: matched as authentication::setup()
		// matches, by URL and by view, in any case
		$open = function ($path) { return !authentication::protection($path); };
		database::instance();
		$database = database::configured();
		$views = $rows = array();
		foreach ($inspector->views() as $view) {
			$path = cms::page_path($view, $inspector->ext);
			if ($path === null) continue;
			$name = substr($view, 0, -strlen($inspector->ext));
			$views[] = $name;
			// an item page is listed by its items, below
			if (cms::item_view($name) !== null) continue;
			if (in_array($name, $skip)) continue;
			if (!$open('/'.$name)) continue;
			// the page's last save in the CMS, else the view file's date
			$latest = $database ? cms_store::latest(cms::page_type($inspector->slug_for_view($view))) : null;
			$time = $latest && $latest->updated_at ? strtotime($latest->updated_at) : false;
			$updated = date('Y-m-d', $time ?: filemtime($inspector->theme_dir().'/'.$view));
			$this->listed($rows, $link.$path, $updated, function () use ($path) { return i18n::alternates(ltrim($path, '/')); });
		}
		if (!$database) return $rows;
		// the collections with item pages: /menu/menu_item/<slug> is shown
		// by menu_item.html, else menu.html, when it renders the list, as
		// cms::route() sends it there
		foreach ($views as $name) {
			if (!preg_match('/^([a-z][a-z0-9_]*?)(_item)?$/', $name, $m)) continue;
			$collection = $m[1];
			if (isset($m[2]) && in_array($collection, $views)) continue;
			$shown = null;
			foreach (array($collection.'_item', $collection) as $view) {
				if (in_array($view, $views) && cms::renders_collection($view, $collection)) { $shown = $view; break; }
			}
			if ($shown === null || in_array($shown, $skip)) continue;
			if (!$open('/'.$shown) || !$open(cms::item_path($collection))) continue;
			// records of a private type (orders, bookings) are never listed
			if (cms_records::is_private($collection)) continue;
			foreach ($this->published($collection) as $item) {
				$this->listed($rows, cms::item_url($collection, $item), $item['updated'], function () use ($collection, $item) { return cms::item_alternates($collection, $item); });
			}
		}
		return $rows;
	}

	// a sitemap row: off, the address; multilingual, the address in every
	// language, each a row with them all and x-default (the default's)
	protected function listed(&$rows, $url, $updated, $alternates) {
		if (!i18n::enabled()) {
			$rows[] = array('url' => $url, 'updated' => $updated);
			return;
		}
		$alternates = $alternates();
		foreach ($alternates as $row) {
			if (strtolower($row['lang']) === strtolower(i18n::default_language())) $alternates[] = array('lang' => 'x-default', 'url' => $row['url']);
		}
		foreach ($alternates as $row) {
			if ($row['lang'] !== 'x-default') $rows[] = array('url' => $row['url'], 'updated' => $updated, 'alternates' => $alternates);
		}
	}

	// every published item of a collection, in one query: id, slug (and,
	// multilingual, its slug in each language) and updated, the later of
	// its last save and its publishing date
	protected function published($collection) {
		$type = cms::collection_type($collection);
		if (!cms_store::table_exists($type)) return array();
		$columns = cms_store::columns($type);
		$select = array('id');
		foreach (array('slug', 'published_at', 'updated_at') as $field) {
			if (array_key_exists($field, $columns)) $select[] = $field;
		}
		if (i18n::enabled() && array_key_exists('slug', $columns)) {
			foreach (i18n::available() as $code) {
				$column = cms_types::column('slug', $code);
				if ($column !== 'slug' && array_key_exists($column, $columns)) $select[] = $column;
			}
		}
		list($sql, $bindings) = cms_store::published_sql($columns);
		$items = array();
		foreach (R::getAll('SELECT `'.implode('`, `', $select).'` FROM `'.$type.'` WHERE'.$sql.'ORDER BY '.cms_store::order_sql('newest', $columns), $bindings) as $item) {
			$item['id'] = (int)$item['id'];
			$time = 0;
			foreach (array('published_at', 'updated_at') as $field) {
				if (!empty($item[$field])) $time = max($time, (int)strtotime($item[$field]));
			}
			$item['updated'] = date('Y-m-d', $time ?: time());
			$items[] = $item;
		}
		return $items;
	}
}
