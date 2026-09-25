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
*   <!-- render.feed.pages -->             every page and collection item, for sitemap.xml:
*                                          url and updated (YYYY-MM-DD)
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
		$type = cms::collection_type($collection);
		database::instance('cms');
		if (!database::configured() || !cms_store::table_exists($type)) return array();
		$columns = cms_store::columns($type);
		list($sql, $bindings) = cms_store::published_sql($columns);
		$limit = (int)($limit ?: config::get('feed_limit', 20));
		$sql .= ' ORDER BY '.cms_store::order_sql('newest', $columns).' LIMIT '.max(1, $limit);
		$rows = array();
		foreach (R::exportAll(R::find($type, $sql, $bindings)) as $item) {
			$date = !empty($item['published_at']) ? $item['published_at'] : (!empty($item['updated_at']) ? $item['updated_at'] : 'now');
			$time = strtotime($date) ?: time();
			$item['url'] = rtrim(config::get('link_uri'), '/').'/'.$collection.'/'.$collection.'_item/'.(!empty($item['slug']) ? rawurlencode($item['slug']) : $item['id']);
			$item['date_rfc822'] = date(DATE_RSS, $time);
			$item['date_iso'] = date(DATE_ATOM, $time);
			$rows[] = $item;
		}
		return $rows;
	}

	// every page of the site and every published collection item
	function pages() {
		require_once BASE.'tools/inspector.php';
		$inspector = new raster_inspector();
		$link = rtrim(config::get('link_uri'), '/');
		$skip = (array)config::get('sitemap_skip', array('login', 'account', 'reset', 'forgot', 'register', 'newsletter-confirm', 'newsletter-unsubscribe'));
		$rows = array();
		$item_views = array();
		foreach ($inspector->views() as $view) {
			if (preg_match('#(^|/)_#', $view) || substr($view, -strlen($inspector->ext)) !== $inspector->ext) continue;
			$name = substr($view, 0, -strlen($inspector->ext));
			if (preg_match('/^([a-z0-9_]+)_item$/', basename($name), $m)) { $item_views[] = $m[1]; continue; }
			if (in_array($name, $skip)) continue;
			$updated = date('Y-m-d', filemtime($inspector->theme_dir().'/'.$view));
			$rows[] = array('url' => $name === config::get('default_view', 'index') ? $link.'/' : $link.'/'.$name, 'updated' => $updated);
		}
		database::instance('cms');
		if (database::configured()) {
			foreach (array_unique($item_views) as $collection) {
				foreach ($this->items($collection, 1000) as $item) {
					$rows[] = array('url' => $item['url'], 'updated' => substr($item['date_iso'], 0, 10));
				}
			}
		}
		return $rows;
	}
}
