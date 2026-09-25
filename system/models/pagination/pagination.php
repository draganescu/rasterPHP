<?php
/**
* Pagination
*
* Page links for a CMS collection or any model, written in the template:
*
*   <!-- render.pagination.links('cms.news') -->
*   <nav class="pager">
*     <!-- print.+class.prev_state --><!-- print.@href.prev_url --><a class="prev" href="#">Newer</a><!-- /print.@href.prev_url --><!-- /print.+class.prev_state -->
*     <!-- render.pagination.pages('cms.news') -->
*     <!-- print.+class.state --><!-- print.@href.url --><a href="#"><!-- print.number -->1<!-- /print.number --></a><!-- /print.@href.url --><!-- /print.+class.state -->
*     <!-- /render.pagination.pages('cms.news') -->
*     <!-- print.+class.next_state --><!-- print.@href.next_url --><a class="next" href="#">Older</a><!-- /print.@href.next_url --><!-- /print.+class.next_state -->
*   </nav>
*   <!-- /render.pagination.links('cms.news') -->
*
* Both render nothing when everything fits on one page.
*
* - 'cms.news' pages the news collection: /news, /news/news_page/2, ...
* - 'products.list' asks your model: products::list(true) must return
*   array('total' => 120, 'perpage' => 20); pages are ?page=2, ?page=3
*   and the model reads util::get('page') itself.
*/
class pagination
{
	protected function state($source, $filter = '') {
		if (!preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/', (string)$source, $m)) {
			throw new InvalidArgumentException("pagination needs 'model.method' or 'cms.collection', got '$source'");
		}
		list(, $model, $name) = $m;
		if ($model === 'cms') {
			$type = cms::collection_type($name);
			$filters = array();
			// /news/news_items/tag/php narrows the list, and so the pages
			$segments = (array)config::get('uri_segments');
			$start = array_search($name.'_items', $segments);
			if ($start !== false) {
				for ($i = $start + 1; $i + 1 < count($segments); $i += 2) {
					if (preg_match('/^'.preg_quote($name, '/').'_page$/', $segments[$i])) break;
					$filters[$segments[$i]] = $segments[$i + 1];
				}
			}
			// the same filters as the list: pagination.links('cms.news', 'featured=1')
			foreach (explode('&', (string)$filter) as $pair) {
				$parts = explode('=', $pair, 2);
				if ($parts[0] !== '' && !in_array($parts[0], array('order', 'limit'))) $filters[$parts[0]] = isset($parts[1]) ? $parts[1] : '';
			}
			$total = cms_store::table_exists($type) ? cms_store::count_published($type, $filters) : 0;
			$perpage = (int)config::get($name.'_page_size') ?: ((int)config::get('raster_page_size') ?: 10);
			$current = max(1, (int)util::param($name.'_page', 1));
			$uri = (string)config::get('uri_string');
			$base = preg_replace('#/'.preg_quote($name, '#').'_page/\d+/?$#', '', rtrim($uri, '/'));
			if ($base === '') $base = '/';
			$link = rtrim(config::get('link_uri'), '/');
			$url = function ($n) use ($link, $base, $name) {
				return $n <= 1 ? $link.($base === '/' ? '/' : $base) : $link.rtrim($base, '/').'/'.$name.'_page/'.$n;
			};
		} else {
			controller::load_model($model);
			$object = controller::get_object($model);
			if (!$object || !method_exists($object, $name)) throw new InvalidArgumentException("pagination: $model::$name() not found");
			$info = $object->$name(true);
			$total = (int)(isset($info['total']) ? $info['total'] : 0);
			$perpage = (int)(isset($info['perpage']) ? $info['perpage'] : 10);
			$current = max(1, (int)util::get('page') ?: 1);
			$path = rtrim(config::get('base_uri'), '/').strtok((string)config::get('uri_string'), '?');
			$url = function ($n) use ($path) { return $n <= 1 ? $path : $path.'?page='.$n; };
		}
		$pages = max(1, (int)ceil($total / max(1, $perpage)));
		return array('pages' => $pages, 'current' => min($current, $pages), 'url' => $url);
	}

	// one row per page: number, url, state ("current" or "")
	function pages($source, $filter = '') {
		$s = $this->state($source, $filter);
		if ($s['pages'] < 2) return array();
		$rows = array();
		for ($n = 1; $n <= $s['pages']; $n++) {
			$rows[] = array('number' => (string)$n, 'url' => $s['url']($n), 'state' => $n === $s['current'] ? 'current' : '');
		}
		return $rows;
	}

	// one row: prev_url, next_url, current, total, prev_state and next_state ("disabled" at the ends)
	function links($source, $filter = '') {
		$s = $this->state($source, $filter);
		if ($s['pages'] < 2) return array();
		return array(array(
			'current' => (string)$s['current'],
			'total' => (string)$s['pages'],
			'prev_url' => $s['url'](max(1, $s['current'] - 1)),
			'next_url' => $s['url'](min($s['pages'], $s['current'] + 1)),
			'prev_state' => $s['current'] <= 1 ? 'disabled' : '',
			'next_state' => $s['current'] >= $s['pages'] ? 'disabled' : '',
		));
	}

	// the name older Raster sites used
	function paginate($source, $filter = '') {
		return $this->links($source, $filter);
	}
}
