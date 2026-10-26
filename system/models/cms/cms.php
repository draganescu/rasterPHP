<?php

require_once __DIR__.'/store.php';

/**
* Cms
*
* The CMS derives its content model from the views:
*
*   <!-- print.cms.headline -->Hello<!-- /print.cms.headline -->
*     a page field called "headline", stored per page, starting as "Hello"
*
*   <!-- render.cms.features --> ... <!-- print.title -->A<!-- /print.title --> ... <!-- /render.cms.features -->
*     a collection called "features" whose items have a "title" field
*
* A field's type comes from its mock-up: 14 is an int, 2026-10-10 a date,
* Hello text (types.php).
*
* In development (fluid database) tables and columns are created on the first
* request that renders a new annotation. In production (frozen database) run
* `php bin/raster schema --apply` after deploying new templates.
*/
class cms
{

	private $page = NULL;
	private $page_name = NULL;
	private $page_variables = array();
	private $page_data = array();
	private $slug = NULL;
	// the login page: only the site_* fields, multilingual (#200)
	private $site_only = false;

	private $data_name = NULL;

	// page rows by type, read once per request and shared by their fields
	private $pages = array();
	// fields whose translation columns were looked for (development)
	private $translations = array();
	// field => where the page prints it (null for words, '@href' …), read
	// from the page once (development, multilingual)
	private $print_places = null;

	// ##Naming
	// Bean types must be lowercase letters and digits only.

	// the table holding the fields of a page, from its URL slug
	// Single segment slugs keep a readable name (/about -> aboutpage).
	// Anything else gets a short hash so /about-us, /aboutus and
	// /about/us never share a table.
	static function page_type($slug) {
		$slug = (string)$slug;
		if ($slug === 'home' || $slug === '/' || $slug === '') return 'homepage';
		if (preg_match('#^/([a-z0-9]+)$#', $slug, $m) && $m[1] !== 'home') return $m[1].'page';
		$readable = substr(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $slug)), 0, 40);
		return $readable.substr(md5($slug), 0, 6).'page';
	}

	// names that can't be used for page fields or collections. A collection
	// is reserved by its table: users_ and u_sers are usersdata too, the
	// accounts of the first versions, which no editor may read. A name
	// with __ is a translation's column (headline__ro), never a field
	static function reserved($name, $kind) {
		if (strpos((string)$name, '__') !== false) return true;
		if ($kind === 'collection') return in_array(self::collection_type($name), array('usersdata', 'rasterdata'));
		return method_exists('cms', $name) || in_array($name, array('slug', 'id', 'updated_at', 'enabled', 'published_at', 'saved_lang'));
	}

	// the table holding the items of a collection
	static function collection_type($name) {
		return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$name)).'data';
	}

	// The page a literal route is (controller::route('blog/post') is
	// /blog/post), or null for a pattern with regular expression parts,
	// which is not one address.
	static function route_slug($pattern) {
		$pattern = trim((string)$pattern, '/');
		return preg_match('#^[a-z0-9_\-/]+$#', $pattern) ? '/'.$pattern : null;
	}

	// The slug identifies which page's content a URL shows. Pagination and
	// filter segments are not part of it, so /news/news_page, /news/news_page/2
	// and /news/news_items/tag/x show the same page fields as /news: the
	// URLs route() sends to news.html, because it renders render.cms.news.
	// /landing_page is the view landing_page.html, a page of its own (#132).
	// Item URLs (/news/news_item/3) share one slug per collection.
	// A URL a literal route matched is that route's page, so parameters
	// don't make pages: /blog/post/id/7 shows the fields of /blog/post.
	static function slug_for_uri($uri, $route = null) {
		$slug = '/'.trim(preg_replace('#/+#', '/', (string)$uri), '/');
		if ($slug === '/' || $slug === '/'.config::get('default_view', 'index')) return 'home';
		$routed = cms::route_slug($route);
		if ($routed !== null) return $routed;
		$url = cms::collection_uri($slug);
		if ($url && $url['kind'] !== 'item' && $url['path'] !== '/' && cms::renders_collection(ltrim($url['path'], '/'), $url['name'])) {
			$slug = $url['path'];
		} elseif ($url && $url['kind'] !== 'item' && $url['path'] === '/' && $url['rest']) {
			// a list on the home page has no page of its own (/news_page/2)
			$slug = '/'.$url['name'];
		} elseif ($url && $url['kind'] === 'item') {
			// an item shown by the collection's own view (no news_item.html)
			// shares that view's page fields
			$slug = file_exists(controller::build_view_path($url['name'].'_item')) ? cms::item_path($url['name']) : '/'.$url['name'];
		}
		return $slug;
	}

	// ##Collection URLs
	// A collection's URLs, read and written only here (#40):
	//   /news/news_item/<slug>         one item (its id when it has no slug)
	//   /news/news_page/2              page 2 of the list
	//   /news/news_items/tag/php       the list where tag is php; its page
	//                                  comes last: .../tag/php/news_page/2

	// What a URL says about a collection, from its first segment that is
	// <name>_item, <name>_items or <name>_page: /news/news_page/2 is
	// array('path' => '/news', 'name' => 'news', 'kind' => 'page', 'rest' => array('2')).
	// A list is on a page, so the first segment counts last:
	// /landing_page/landing_page/2 is page 2 on /landing_page. Null for a
	// URL that is no collection's.
	static function collection_uri($uri) {
		$segments = explode('/', trim(preg_replace('#/+#', '/', (string)$uri), '/'));
		$order = array_keys($segments);
		$order[] = array_shift($order);
		foreach ($order as $i) {
			if (!preg_match('/^([a-z0-9_]+)_(item|items|page)$/', $segments[$i], $m)) continue;
			return array('path' => '/'.implode('/', array_slice($segments, 0, $i)), 'name' => $m[1], 'kind' => $m[2], 'rest' => array_slice($segments, $i + 1));
		}
		return null;
	}

	// What the URL asks of the list $name: the item ('' for
	// /news/news_item with nothing after it, null for none), the filters and
	// the page (0 when it names none). An item and filters are under the
	// collection's own name (/news/news_item/3). A page is the list's
	// wherever it shows and whatever other lists the URL pages too:
	// /events/news_page/2, /news/news_page/2/events_page/3.
	static function collection_request($name, $uri = null) {
		$uri = $uri === null ? config::get('uri_string') : $uri;
		$asked = array('item' => null, 'filters' => array(), 'page' => 0);
		$segments = explode('/', trim(preg_replace('#/+#', '/', (string)$uri), '/'));
		$at = array_keys($segments, $name.'_page', true);
		if ($at && isset($segments[end($at) + 1])) $asked['page'] = (int)$segments[end($at) + 1];
		$url = cms::collection_uri($uri);
		if (!$url || $url['name'] !== $name || substr($url['path'], -strlen($name) - 1) !== '/'.$name) return $asked;
		$rest = $url['rest'];
		if ($url['kind'] === 'item') $asked['item'] = isset($rest[0]) ? $rest[0] : '';
		if ($url['kind'] === 'items') {
			for ($i = 0; $i + 1 < count($rest); $i += 2) {
				if ($rest[$i] !== $name.'_page') $asked['filters'][$rest[$i]] = $rest[$i + 1];
			}
		}
		return $asked;
	}

	// The collection a view is the item page of (news_item, or
	// staff/booking_item, is news's or booking's), or null. $view is the
	// view's name, without its extension.
	static function item_view($view) {
		return preg_match('#^(?:.*/)?([a-z0-9_]+)_item$#', (string)$view, $m) ? $m[1] : null;
	}

	// page $n of the list $name on the page at $uri (the current one), as
	// the pager links it: /news/news_page/2, /news/news_items/tag/php/news_page/2,
	// and the page without a number for page 1
	static function page_link($name, $n, $uri = null) {
		$uri = $uri === null ? (string)config::get('uri_string') : (string)$uri;
		$base = preg_replace('#/'.preg_quote($name, '#').'_page/\d+/?$#', '', rtrim($uri, '/'));
		if ($base === '') $base = '/';
		$link = rtrim(config::get('link_uri'), '/');
		return $n <= 1 ? $link.($base === '/' ? '/' : $base) : $link.rtrim($base, '/').'/'.$name.'_page/'.$n;
	}

	// the list $name filtered by the $fields of $item:
	// /news/news_items/tag/php, without the last slash, as item links are (#131)
	static function filter_link($name, $fields, $item) {
		$link = config::get('link_uri').$name.'/'.$name.'_items/';
		foreach ($fields as $field) {
			$link .= $field.'/'.rawurlencode(isset($item[$field]) ? $item[$field] : '').'/';
		}
		return rtrim($link, '/');
	}

	// /news/news_item, the page every item of news shows on, and with $key
	// one item's address: /news/news_item/raster-runs. Where the address
	// stands for any item (describe, MCP, the inspector) the key is {slug} (#37).
	static function item_path($name, $key = null) {
		return '/'.$name.'/'.$name.'_item'.($key === null ? '' : '/'.$key);
	}

	// an item's link, by its slug, or its id when it has none
	static function item_url($name, $item) {
		return util::url(cms::item_path($name, !empty($item['slug']) ? rawurlencode($item['slug']) : $item['id']));
	}

	// $path (as it reads, no language prefix, not encoded) spelled the way
	// the language $lang spells it: an item URL's slug becomes the item's
	// slug in $lang (/menu/menu_item/flat-white is menu/menu_item/cafea-cu-lapte
	// in ro), or its default slug while it has none there; any other path
	// is as it is, and costs no query. $from is the language $path is in
	// (null: the current one). i18n::alternates() asks it for every
	// language when multilingual is on.
	static function path_in($path, $lang, $from = null) {
		if (!i18n::enabled()) return $path;
		$url = cms::collection_uri($path);
		if (!$url || $url['kind'] !== 'item' || count($url['rest']) !== 1 || $url['rest'][0] === '' || ltrim($url['path'], '/') !== $url['name']) return $path;
		$from = $from === null ? i18n::current() : (string)$from;
		if (strtolower((string)$lang) === strtolower($from)) return $path;
		$key = $url['rest'][0];
		try {
			$row = cms_store::slug_item(cms::collection_type($url['name']), $key, $from);
		} catch (Exception $e) {
			return $path;
		}
		// a draft, or a private record, has no other address for whoever
		// may not see it
		if ($row && !cms::item_visible($url['name'], (int)$row['id'])) $row = null;
		$slug = $row ? cms_store::slug_in($row, $lang) : '';
		return $slug === '' ? $path : substr($path, 0, -strlen($key)).$slug;
	}

	// whether the person asking may see item $id of collection $name, as
	// its list would show it (drafts for editors, a private type's records
	// for whoever may read them); asked once a request per item, until an
	// item is saved (kept with slug_item's answers)
	static function item_visible($name, $id) {
		$key = "visible\n".$name."\n".$id."\n".(cms::loggedin() ? 1 : 0);
		if (array_key_exists($key, cms_store::$slug_items)) return cms_store::$slug_items[$key];
		$where = cms_store::list_where($name, array(), array('id' => (int)$id), array());
		return cms_store::$slug_items[$key] = $where !== null && (bool)R::count(cms::collection_type($name), $where[0], $where[1]);
	}

	// an item's address in every language, as i18n::alternates() gives a
	// page's: rows of lang and url. $item is a row as read, with its slug
	// columns (slug, slug__ro), before cms_store::localized(), so a list of
	// items needs no query per item: each language's slug, else the
	// default slug, else the id.
	static function item_alternates($name, $item) {
		if (!i18n::enabled()) return i18n::alternates(ltrim(cms::item_path($name, !empty($item['slug']) ? $item['slug'] : $item['id']), '/'));
		$rows = array();
		foreach (i18n::available() as $code) {
			$address = i18n::address($code);
			if ($address === null) continue;
			$slug = cms_store::slug_in($item, $code);
			$path = ltrim(cms::item_path($name, $slug !== '' ? $slug : $item['id']), '/');
			$rows[] = array('lang' => $code, 'url' => $address.implode('/', array_map('rawurlencode', explode('/', $path))));
		}
		return $rows;
	}

	// The address of a view that is a page (about.html is /about, the
	// default view /), or null for one that is never a page: partials and
	// folders starting with _ (_layout, _email/), and feeds (news.rss).
	static function page_path($view, $ext = null) {
		$ext = $ext ?: config::get('views_ext', '.html');
		if (controller::is_partial($view) || substr($view, -strlen($ext)) !== $ext) return null;
		$name = substr($view, 0, -strlen($ext));
		return $name === config::get('default_view', 'index') ? '/' : '/'.$name;
	}

	// Visitors don't get a session cookie; editors get one when they log in.
	static function session($force = false) {
		util::session($force);
	}

	// Is the URL spelled the way the list's own links spell it?
	// /news/news_page/2, /news/news_items/tag/php, /news/news_item/7. Other
	// spellings of the same page (/news/news_page/02, /news/news_page/1, a
	// segment left over, an id with zeros in front) still answer, but are
	// not kept, since there is no end to them.
	static function canonical_uri($name) {
		$segments = (array)config::get('uri_segments');
		// /news/news_page/2.rss is page 2 of the feed
		$segments[count($segments) - 1] = preg_replace('/\.(rss|atom|xml|json|txt)$/', '', (string)end($segments));
		$url = cms::collection_uri(implode('/', $segments));
		if (!$url || $url['name'] !== $name) return true;
		$rest = $url['rest'];
		$page = function ($value) { return (bool)preg_match('/^[1-9][0-9]*$/', $value) && $value !== '1'; };
		if ($url['kind'] === 'page') return count($rest) === 1 && $page($rest[0]);
		if ($url['kind'] === 'item') return count($rest) === 1 && (!ctype_digit($rest[0]) || $rest[0] === (string)(int)$rest[0]);
		if (count($rest) % 2) return false;
		$seen = array();
		for ($j = 0; $j < count($rest); $j += 2) {
			if (isset($seen[$rest[$j]])) return false;
			$seen[$rest[$j]] = true;
			// the page comes last: /news/news_items/tag/php/news_page/2
			if ($rest[$j] === $name.'_page') return $j + 2 === count($rest) && $page($rest[$j + 1]);
		}
		return true;
	}

	// Is a URL filter's value spelled the way the template prints it? Text
	// is as it is; 14.50 for a number whose mock-up is 14.50, 2026-10-10 for
	// a date, 1 for a bool.
	static function canonical_value($column, $value, $example) {
		$type = cms_types::of_column($column);
		if ($type === 'text') return true;
		try {
			$clean = cms_types::clean($type, $value, 'filter');
		} catch (InvalidArgumentException $e) {
			// it matches nothing, and an empty list is not kept anyway
			return true;
		}
		return cms_types::show(cms_types::read($type, $clean), $example) === (string)$value;
	}

	// A field's mock-up in the collection's own view, for a list that doesn't
	// print it (a sidebar of names): the site's links spell 14.50 from there.
	static function collection_example($name, $field) {
		static $defaults = array();
		if (!isset($defaults[$name])) {
			require_once BASE.'tools/inspector.php';
			$inspector = new raster_inspector();
			$defaults[$name] = (array)$inspector->collection_defaults($name);
		}
		return isset($defaults[$name][$field]) ? $defaults[$name][$field] : null;
	}

	// Does a view render the collection (render.cms.<name>)? Then
	// /<view>/<name>_page and /<view>/<name>_items are its list URLs: route()
	// sends them to the view, and slug_for_uri() gives them its page fields.
	static function renders_collection($view, $name) {
		if (strpos($view, '..') !== false || controller::is_partial($view)) return false;
		$file = controller::build_view_path($view);
		return file_exists($file) && strpos(file_get_contents($file), '<!-- render.cms.'.$name) !== false;
	}

	// custom cms routes for admin panels and collection URLs
	public function route() {
		include __DIR__.'/routes.php';

		// /news/news_item/3  -> news_item.html (or news.html)
		// /news/news_page/2  -> news.html
		// /news/news_items/tag/php -> news.html
		$url = cms::collection_uri(config::get('uri_string'));
		if ($url && $url['path'] !== '/') {
			$in = ltrim($url['path'], '/');
			// item URLs live under the collection's own name: /news/news_item/x
			if ($url['kind'] === 'item' && $in !== $url['name']) return;
			$candidates = $url['kind'] === 'item' ? array($url['name'].'_item', $in) : array($in);
			foreach ($candidates as $view) {
				// only views that actually render this collection
				if (cms::renders_collection($view, $url['name'])) {
					controller::route(preg_quote($in.'/'.$url['name'].'_'.$url['kind'], '%').'(/|$)')->to($view);
					if ($url['kind'] === 'item') cms::moved_item($url, $view);
					break;
				}
			}
		}
	}

	// An item URL with another language's slug (/ro/menu/menu_item/flat-white),
	// or with a slug the item had before, answers 301 to the item's slug in
	// the URL's language (#138, #200), before anything is printed, and is
	// never cached. Only an item the visitor may see, only for GET and HEAD,
	// and only multilingual: off, an old slug is a 404 as it always was.
	// $url is collection_uri()'s.
	static function moved_item($url, $view = null) {
		if (!i18n::enabled() || count($url['rest']) !== 1 || $url['rest'][0] === '') return;
		// a page that needs a role the visitor lacks says nothing of its
		// items, not even their slug in another language: authentication
		// answers it (login or 403) as for any other address
		if (class_exists('authentication')) {
			$uri = '/'.ltrim((string)config::get('uri_string'), '/');
			foreach (authentication::protection($uri) + ($view === null ? array() : authentication::protection('/'.$view)) as $role) {
				if (!authentication::can($role)) return;
			}
		}
		if (!in_array(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET', array('GET', 'HEAD'), true)) return;
		// on the command line only `raster render` follows it
		if (PHP_SAPI === 'cli' && !defined('RASTER_RENDER')) return;
		if (config::get('cms_enabled') == false) return;
		database::instance();
		if (!database::configured()) return;
		$name = $url['name'];
		$lang = i18n::current();
		try {
			$found = cms_store::resolve_slug(cms::collection_type($name), $url['rest'][0], $lang);
			if (!$found) return;
			// drafts for editors, a private type's records for whoever may read them
			cms::session();
			$where = cms_store::list_where($name, array(), array('id' => $found['id']), array());
			if ($where === null || !R::count(cms::collection_type($name), $where[0], $where[1])) return;
		} catch (Exception $e) {
			log::warning('CMS: '.$e->getMessage());
			return;
		}
		$location = i18n::with($lang, function () use ($name, $found) { return cms::item_url($name, $found); });
		if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') $location .= '?'.$_SERVER['QUERY_STRING'];
		raster_cache::skip();
		header('Location: '.$location, true, 301);
		if (PHP_SAPI === 'cli') fwrite(STDERR, "Location: $location\n");
		exit;
	}

	// Setup routes for the admin section
	public function setup()
	{
		if (config::get('cms_enabled') == false) {
			return;
		}

		cms::session();

		$db = database::instance();
		if (!database::configured()) {
			return;
		}
		// a database that can't be reached is an outage, not a missing
		// table: never the mock-up as if it were the page
		$down = cms_store::unreachable();
		if ($down !== null) controller::unavailable($down);

		$uri_string = config::get('uri_string');
		$index_file = config::get('index_file');
		$slug = cms::slug_for_uri(str_replace('/'.$index_file, '', $uri_string), controller::instance()->matched_route);

		if (strpos($slug, '/login') === 0) {
			// the login page has no fields of its own and no editor. On a
			// multilingual site its layout's site_* fields are the page's
			// language's, as on every other page (off, the template's, as
			// they always were)
			if (i18n::enabled()) {
				$this->page_name = cms::page_type($slug);
				$this->slug = $slug;
				$this->site_only = true;
			}
			return;
		}

		if (controller::instance()->current_route == false) {
			return;
		}

		// feeds and data views (news.rss) have no page fields of their own
		if (config::get('format', 'html') !== 'html') {
			$this->page_name = 'feedpage';
			$this->slug = $slug;
			return;
		}

		$page_name = cms::page_type($slug);
		$this->page_name = $page_name;
		$this->slug = $slug;

		// editors get the page marked for the in-page editor
		cms_editor::start();

		try {
			// the page's row is created by its first print.cms field
			$this->pages[$page_name] = cms_store::latest($page_name);
		} catch (Exception $e) {
			// a frozen schema without this page: templates show their defaults
			log::warning('CMS: '.$e->getMessage());
			$this->pages[$page_name] = null;
		}
	}

	public function __call($name, $arguments)
	{
		$action = template::get('current_action');
		if (!$this->page_name) return false;
		if ($this->site_only && ($action !== 'print' || cms::field_type($name, $this->page_name) !== 'sitepage')) return false;

		try {
			switch ($action) {
				case 'print':
					return $this->page_field($name);
				case 'render':
					return $this->collection($name, $arguments);
				default:
					return false;
			}
		} catch (Exception $e) {
			log::warning('CMS: '.$e->getMessage());
			return false;
		}
	}

	// a page field; false keeps the markup that is in the template
	// fields named site_* are shared by every page (stored in sitepage)
	static function field_type($name, $page_type) {
		return strpos($name, 'site_') === 0 ? 'sitepage' : $page_type;
	}

	protected function page_field($name) {
		if (cms::reserved($name, 'field')) return false;
		$this->page_variables[] = $name;
		$type = cms::field_type($name, $this->page_name);
		if (!array_key_exists($type, $this->pages)) $this->pages[$type] = cms_store::latest($type);
		$page = $this->pages[$type];
		if (!$page && !database::$frozen) {
			cms_types::ensure($type, array('slug' => 'text', 'updated_at' => 'datetime'));
			$page = R::dispense($type);
			$page->slug = $type === 'sitepage' ? 'site' : $this->slug;
			$page->updated_at = R::isoDateTime();
			R::store($page);
			$this->pages[$type] = $page;
		}
		if (!$page) return false;
		// a stored row carries every column of its table; a new one is of the
		// mock-up's type and starts as the mock-up
		$example = trim(template::get('current_block'));
		$multilingual = i18n::enabled();
		// D7: does the field have a translation per language? Only when every
		// place the page prints it (its view and the layouts dried into it)
		// is a place for words, as the content model says: a URL printed as a
		// link's text and in its href stays one value
		if ($multilingual && !database::$frozen && $this->print_places === null) {
			// read before the first cms print runs, while every one is still there
			$this->print_places = array();
			preg_match_all('/<!-- print\.(?:([@+])([a-zA-Z0-9_:-]+)\.)?cms\.([a-z0-9_]+)\b[^>]*?-->/', (string)template::instance()->output, $prints, PREG_SET_ORDER);
			foreach ($prints as $print) $this->print_places[$print[3]][] = $print[1] === '' ? null : $print[1].$print[2];
		}
		$attr = $multilingual ? template::get('current_attr') : null;
		$places = $this->print_places;
		$translates = function ($field_type) use ($name, $attr, $places) {
			$where = isset($places[$name]) ? $places[$name] : array();
			$where[] = $attr;
			foreach (array_unique($where) as $at) {
				// an attribute a print adds to (+class) is never words
				if ($at !== null && $at[0] === '+') return array();
				if (!cms_types::translates($field_type, $at === null ? null : substr($at, 1))) return array();
			}
			return array($name);
		};
		if (!array_key_exists($name, $page->getProperties())) {
			if (database::$frozen) return false;
			$field_type = cms_types::of_example($example);
			cms_types::ensure($type, array($name => $field_type), $multilingual ? $translates($field_type) : array());
			$page->$name = cms_types::clean($field_type, $example, $name);
			R::store($page);
		} elseif ($multilingual && !database::$frozen && !isset($this->translations[$type.'.'.$name])) {
			// a field made before the site had languages gets its
			// translation columns the first time it prints (development)
			$this->translations[$type.'.'.$name] = true;
			foreach (i18n::available() as $lang) {
				if (i18n::is_default($lang) || array_key_exists(cms_types::column($name, $lang), $page->getProperties())) continue;
				$types = cms_types::of_table($type);
				$wanted = $translates(isset($types[$name]) ? $types[$name] : 'text');
				if ($wanted) cms_types::ensure($type, array(), $wanted);
				break;
			}
		}
		// read as its type, as a collection's are, so 14.5 prints as its mock-up 14.50
		$types = cms_types::of_table($type);
		$stored = $page->$name;
		$mark = null;
		if ($multilingual) {
			// the field in the page's language, or the default language's
			// value while it has no translation (D8); the editor's mark
			// says which language it edits, and whether the field has a
			// translation (shared fields are one for every language)
			$lang = i18n::current();
			$row = $page->getProperties();
			$shown = cms_store::localized($row, $type, $lang);
			$stored = $shown[$name];
			$mark = array('lang' => $lang);
			if (!i18n::is_default($lang)) {
				$translated = in_array($name, cms_types::translated($type), true);
				$column = cms_types::column($name, $lang);
				$mark['shared'] = !$translated;
				if ($translated) {
					$mark['untranslated'] = !isset($row[$column]) || (string)$row[$column] === '';
					$mark['fallback'] = (string)cms_types::show($row[$name]);
					// what is stored in this language: an undo puts back
					// nothing, never the default language's words shown
					if ($mark['untranslated']) $mark['value'] = '';
				}
			}
		}
		$value = cms_types::show(isset($types[$name]) ? cms_types::read($types[$name], $stored) : $stored, $example);
		$value = $value === '' ? false : $value;
		if (cms_editor::editing()) {
			return cms_editor::field($type, $type === 'sitepage' ? 'site' : $this->slug, $name, $value, template::get('current_block'), $mark);
		}
		return $value;
	}

	protected function collection($name, $arguments) {
		if (cms::reserved($name, 'collection')) return false;
		$filter_link_params = array();
		$this->page_data[] = $name;
		$this->data_name = cms::collection_type($name);
		$filters = array();
		$expected_properties = array();

		extract(template::get('current_params'));
		foreach ($datastarts[1] as $key=>$value) {
			if(strpos($value, 'if.') !== false) continue;
			if(strpos($value, 'raster_filter') !== false) {
				$params = explode("raster_filter@", $value);
				$filter_link_params[$params[1]] = explode('@', $params[1]);
				continue;
			}
			list($property, $content) = $this->detect_data($key, $value);
			if ($property == "raster_detail_link") {
				continue;
			}
			$expected_properties[$property] = $content;
		}

		$asked = cms::collection_request($name);
		$page = $asked['page'];
		// /news/news_page/02 shows page 2, but is not a page worth keeping
		if (!cms::canonical_uri($name)) raster_cache::skip();

		// uri filters: /news/news_items/tag/php
		$uri_filters = $asked['filters'];
		$filters = $uri_filters + $filters;

		// param filters: render.cms.news('featured=1&order=newest&limit=3'),
		// render.cms.booking('stylist=?stylist&date>=today&order=date,time')
		// (see cms_store::list_options)
		$list = cms_store::list_options(!empty($arguments) && is_string($arguments[0]) ? $arguments[0] : '');
		$options = $list['options'];
		// fields a list mentions become fields of the collection, starting
		// with the value a list asks for (featured=1)
		foreach ($list['fields'] as $field => $value) {
			if ($value !== '' || !isset($expected_properties[$field])) $expected_properties[$field] = $value;
		}
		// pages of _page_size (raster_page_size, 10); a limit list shows its
		// first ones on every page of the site (#88)
		list($page_size, $limited) = cms_store::page_size($name, $options);
		$roffset = !$limited && $page > 1 ? ($page-1)*$page_size : 0;

		// a type a model declares: the model's fields, no mock-up row, and
		// private records only for editors and their owners
		$record = cms_records::info($name);
		if ($record) {
			cms_records::ensure($record);
			if (!cms_store::table_exists($this->data_name)) return array();
		}

		$exists = cms_store::table_exists($this->data_name);
		if ($record) {
			// nothing to seed or add: the model declares the fields
		} elseif (database::$frozen) {
			// production seeds nothing, and a list with no table keeps the
			// mock-up; until schema --apply, its filter and page URLs are not kept
			if (!$exists) {
				if ($uri_filters || $page > 1) raster_cache::skip();
				return false;
			}
		} elseif (!$exists) {
			// the first item is the placeholder content from the template; a
			// table emptied by deleting its items stays empty (#91)
			require_once BASE.'tools/inspector.php';
			$inspector = new raster_inspector();
			$seed = array_merge($expected_properties, $inspector->collection_defaults($name));
			$types = array_map(array('cms_types', 'of_example'), $seed);
			cms_types::ensure($this->data_name, $types + array_intersect_key(cms_types::$system, array_flip(array('slug', 'enabled', 'published_at', 'updated_at'))));
			$item = R::dispense($this->data_name);
			foreach ($seed as $property=>$content) {
				$item->$property = cms_types::clean($types[$property], $content, $property);
			}
			$item->updated_at = R::isoDateTime();
			$item->enabled = 1;
			$item->slug = cms_store::unique_slug($this->data_name, cms_store::slug_source($seed), 0);
			R::store($item);
		} else {
			// new fields in the template become new columns, of the type of the
			// collection's own mock-up (as the first item); the newest item gets it
			$fields = cms_store::columns($this->data_name);
			$new = array_diff_key($expected_properties, $fields);
			if ($new) {
				require_once BASE.'tools/inspector.php';
				$inspector = new raster_inspector();
				$new = array_merge($new, array_intersect_key($inspector->collection_defaults($name), $new));
				$types = array_map(array('cms_types', 'of_example'), $new);
				cms_types::ensure($this->data_name, $types);
				$latest = cms_store::latest($this->data_name);
				if ($latest) {
					foreach ($new as $key => $value) $latest->$key = cms_types::clean($types[$key], $value, $key);
					R::store($latest);
				}
			}
		}

		// multilingual, in development: the collection's translated fields
		// get their columns per language where its fields are first seen
		// (a new table, a new field, one made before the site had languages)
		if (!$record && !database::$frozen && i18n::enabled()) $this->item_translations($name, $datastarts[1]);

		// only real columns can be filtered on
		$fields = cms_store::columns($this->data_name);
		// /menu/menu_items/price/14.500 and /events/events_items/date/10 Oct 2026
		// find what the site's own links (14.50, 2026-10-10) find; they
		// answer, but there is no end to the spellings
		foreach ($uri_filters as $key => $value) {
			if (array_key_exists($key, $fields) && preg_match('/^[a-z0-9_]+$/', $key) && !cms::canonical_value($fields[$key], $value, isset($expected_properties[$key]) ? $expected_properties[$key] : cms::collection_example($name, $key))) raster_cache::skip();
		}
		// one item: /news/news_item/3 or /news/news_item/raster-runs-on-php-8,
		// found as cms_store::item_at() finds it: on /ro/ by its Romanian
		// slug, else its default one (an item titled 1984 has the slug 1984:
		// a number that is a slug opens that item, and only a number no item
		// has as its slug is an id, #90). Another language's slug or an old
		// one never gets here: route() answers 301 (moved_item)
		if ($asked['item'] !== null) $filters = $uri_filters + cms_store::item_at($this->data_name, $asked['item'], null, $fields);
		// what the list shows, as the pager counts it
		// filtered and sorted in the page's language: a translation, else the
		// default language's value (D8)
		$lang = i18n::current();
		$where = cms_store::list_where($name, $list['conditions'], $filters, $uri_filters, $lang);
		if ($where === null) return array();
		list($sql, $bindings) = $where;
		$sql .= ' ORDER BY '.cms_store::order_sql(isset($options['order']) ? $options['order'] : '', $fields, $lang).' LIMIT '.(int)$page_size.' OFFSET '.(int)$roffset;
		$data = array_values(array_map(array('cms_store', 'export_item'), R::find($this->data_name, $sql, $bindings)));
		// and shown in it: each translated field's translation, or the
		// default language's text while it has none
		// (the stored rows kept for the slugs made below, from each
		// language's own words)
		$stored = array();
		foreach ($data as $key => $row) {
			$shown = cms_store::localized($row, $this->data_name, $lang);
			if ($shown !== $row) $stored[$key] = $row;
			$data[$key] = $shown;
		}
		// a filter nothing matches and a page past the last one answer, empty;
		// they are not kept, so made-up URLs don't fill the page cache
		if (!$data && ($page > 1 || $uri_filters)) raster_cache::skip();
		// the template prints text: 4.5 as its mock-up 4.50 does
		foreach ($data as $key => $row) $data[$key] = cms_types::show_row($row, $expected_properties);
		if ($record) {
			foreach ($data as $key => $row) $data[$key] = cms_records::for_template($record, cms_records::decode($record, $row));
		}

		// items made before slugs existed get one (development only), and
		// multilingual, the slug of this page's language they lack while they
		// have a translated title (made before slug__ro was)
		if (!database::$frozen && array_key_exists('slug', $fields)) {
			$skip = $record ? array_merge($record['hidden'], array('owner', 'created_at')) : array();
			$column = cms_store::slug_column();
			foreach ($data as $key => $item) {
				// the slugs come from the stored words, never the row as shown
				$source = isset($stored[$key]) ? $stored[$key] : $item;
				$lacks = $column !== 'slug' && empty($item[$column]) && cms_store::slug_source($source, $skip, i18n::current()) !== cms_store::slug_source($source, $skip);
				if (!empty($source['slug']) && !$lacks) continue;
				$bean = R::load($this->data_name, $item['id']);
				$made = empty($source['slug']);
				if ($made) $bean->slug = cms_store::unique_slug($this->data_name, cms_store::slug_source($source, $skip), $item['id']);
				// a new default slug that is another item's address in a
				// language gets one of its own there
				if ($lacks || $made) cms_store::translate_slugs($this->data_name, $bean, $skip);
				R::store($bean);
				cms_store::$slug_items = array();
				cms_store::record_slugs($this->data_name, $bean, $source);
				// shown as the row shows it: this language's slug, else the default's
				$data[$key]['slug'] = $column !== 'slug' && !empty($bean->$column) ? $bean->$column : $bean->slug;
				if ($column !== 'slug' && $bean->$column !== null) $data[$key][$column] = $bean->$column;
			}
		}
		if (empty($data) && (isset($filters['id']) || isset($filters['slug'])) && !headers_sent()) {
			// a detail page for an item that does not exist
			http_response_code(404);
		}

		// building auto detail links
		foreach ($data as $key => $item) {
			$base = config::get("link_uri");
			$data[$key]['raster_detail_link'] = cms::item_url($name, $item);
			foreach ($filter_link_params as $at_key => $filter_fields) {
				$data[$key]['raster_filter@'.$at_key] = cms::filter_link($name, $filter_fields, $item);
			}
		}

		return $data;
	}

	// types posted by the editor must be tables the CMS owns
	static function safe_type($type) {
		$type = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$type));
		return preg_match('/(page|data)$/', $type) ? $type : 'invalidpage';
	}

	// admin endpoints stop here unless someone is logged in
	static function require_admin($check_csrf = false) {
		cms::session();
		if (!cms::loggedin() || ($check_csrf && !util::csrf_valid(util::post('csrf')))) {
			controller::refuse(403, 'not_allowed');
		}
		database::instance();
	}

	// What /api offers, like any model: the editor's endpoints, its style
	// and logout. They check the caller themselves (require_admin answers
	// 403), so they are listed for anyone; nothing else of cms, or of an
	// override of it, answers there.
	static function api() {
		return array_fill_keys(array('editor_save_field', 'editor_save_item', 'editor_delete_item', 'editor_history', 'editor_restore',
			'editor_upload', 'editor_action', 'editor_script', 'style', 'logout'), 'visitor');
	}

	// ##The in-page editor (see editor.php): POST /api/cms/editor_… with csrf
	public function editor_save_field() { return cms_editor::save_field(); }
	public function editor_save_item() { return cms_editor::save_item(); }
	public function editor_delete_item() { return cms_editor::delete_item(); }
	public function editor_history() { return cms_editor::history(); }
	public function editor_restore() { return cms_editor::restore(); }
	public function editor_upload() { return cms_editor::upload(); }
	public function editor_action() { return cms_editor::action(); }
	public function editor_script() { return cms_editor::script(); }

	function style() {
		if (!util::param('output', false)) {
				return config::get('link_uri').'api/cms/style/output/true';
		}
		header("Content-Type: text/css");
		header("X-Content-Type-Options: nosniff");
		echo file_get_contents(BASE.'views/cms_admin/style.css');
		return false;
	}

	// the editor's Log out: POST /api/cms/logout with the session token
	public function logout() {
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return false;
		cms::require_admin(true);
		authentication::log_out();
		return true;
	}

	// the editor login page (system/views/cms_admin/login.html) uses
	// render.authentication.login; this stays for older templates
	public function login() {
		if (cms::loggedin()) util::redirect();
		$auth = new authentication();
		return $auth->login();
	}

	// what the login page should say before anyone can log in: the alerts
	// no_database (none is configured for this environment) and no_users
	// (make one with php bin/raster user). It prints nothing itself.
	function login_message() {
		database::instance();
		if (!database::configured()) return validation::get()->raise('no_database');
		authentication::connect();
		if (!authentication::has_users()) return validation::get()->raise('no_users');
		return '';
	}

	// the fallback login page's words, print.cms.login_word('login_title'),
	// in its language (cms_editor::login_lang), escaped for an attribute too
	function login_word($key) {
		return htmlspecialchars(cms_editor::login_word((string)$key), ENT_QUOTES, 'UTF-8');
	}

	// the fallback login page's <html lang>
	function login_lang() {
		return cms_editor::login_lang();
	}

	// editors and admins get the toolbar and see drafts
	static function loggedin() {
		return authentication::can('editor');
	}

	// bound to before_output: the editor, for editors
	public function inject_toolbar() {
		if (!cms::loggedin() || !$this->page_name || $this->site_only) return false;
		cms_editor::inject($this->page_name, $this->slug);
		return true;
	}

	// Multilingual, in development: the columns per language of the
	// collection's translated fields (the content model's translate, D7),
	// looked for once a request. $prints are the list's print references
	// (name, @src.photo): only a field one of them prints as words that
	// lacks its column in a language sends for the content model, which
	// reads every view, so a list whose columns are there costs nothing.
	private function item_translations($name, $prints) {
		$type = $this->data_name;
		if (isset($this->translations['collection.'.$type])) return;
		$this->translations['collection.'.$type] = true;
		$columns = cms_store::columns($type);
		// what schema asks of every multilingual list: a slug per language
		// and the language of the last save
		$slugs = false;
		foreach (i18n::available() as $lang) {
			if (array_key_exists('slug', $columns) && !array_key_exists(cms_types::column('slug', $lang), $columns)) $slugs = true;
		}
		if ($slugs) cms_types::ensure($type, array(), array('slug'));
		if (!array_key_exists('saved_lang', $columns)) cms_types::ensure($type, array('saved_lang' => 'text'));
		if ($slugs || !array_key_exists('saved_lang', $columns)) $columns = cms_store::columns($type);
		$types = cms_types::of_table($type);
		$candidates = array();
		foreach ($prints as $value) {
			if (strpos($value, 'if.') !== false || strpos($value, 'raster_filter') !== false) continue;
			$parts = explode('.', $value);
			$field = $parts[count($parts) - 1];
			$attribute = in_array(substr($parts[0], 0, 1), array('@', '+'), true) ? substr($parts[0], 1) : null;
			if (!isset($types[$field]) || in_array($field, cms_store::$system_fields, true) || !cms_types::translates($types[$field], $attribute)) continue;
			foreach (i18n::available() as $lang) {
				$column = cms_types::column($field, $lang);
				if ($column !== $field && !array_key_exists($column, $columns)) $candidates[$field] = true;
			}
		}
		if (!$candidates) return;
		require_once BASE.'tools/inspector.php';
		$inspector = new raster_inspector();
		$model = $inspector->content_model();
		$known = isset($model['collections'][$name]) ? $model['collections'][$name]['fields'] : array();
		$wanted = array();
		foreach (array_keys($candidates) as $field) {
			if (!empty($known[$field]['translate'])) $wanted[] = $field;
		}
		if ($wanted) cms_types::ensure($type, array(), $wanted);
	}

	private function detect_data($key, $value) {
		$parts = explode('.', $value);
		extract(template::get('current_params'));

	  $rendered_tpl = $render_template;
		$start = "<!-- print.".$value." -->";
		if($datastarts[2][$key] == '/')
			$end = $start;
		else
			$end = str_replace("<!-- ", "<!-- /", $start);

		$rpos1 = strpos($rendered_tpl, $start);
		if($rpos1 === false)
		{
			$start = "<!-- print.".$value." /-->";
			$end = $start;
			$rpos1 = strpos($rendered_tpl, $start);
		}

		if ($rpos1 === false || $start === $end) {
			$content = '';
		} else {
			$endpos = strpos($rendered_tpl, $end, $rpos1);
			$content = $endpos === false ? '' : substr($rendered_tpl, $rpos1 + strlen($start), $endpos - $rpos1 - strlen($start));
		}
		// print.@src.photo: the mock-up's value is the attribute of the tag it wraps
		if (strpos($value, '@') !== false || strpos($value, '+') !== false) {
			$content = template::get_attribute($content, str_replace(array('@', '+'), '', $parts[0]));
		}
		$property = $parts[count($parts) -1];
		return array($property, $content);
	}
}
