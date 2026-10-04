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

	private $data_name = NULL;

	// page rows by type, read once per request and shared by their fields
	private $pages = array();

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

	// names that can't be used for page fields or collections
	static function reserved($name, $kind) {
		if ($kind === 'collection') return in_array($name, array('users', 'raster'));
		return method_exists('cms', $name) || in_array($name, array('slug', 'id', 'updated_at', 'enabled', 'published_at'));
	}

	// the table holding the items of a collection
	static function collection_type($name) {
		return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$name)).'data';
	}

	// The slug identifies which page's content a URL shows. Pagination and
	// filter segments are not part of it, so /news/news_page/2 and
	// /news/news_items/tag/x show the same page fields as /news.
	// Item URLs (/news/news_item/3) share one slug per collection.
	static function slug_for_uri($uri) {
		$slug = '/'.trim(preg_replace('#/+#', '/', (string)$uri), '/');
		if ($slug === '/' || $slug === '/'.config::get('default_view', 'index')) return 'home';
		if (preg_match('#^(/.*?)?/([a-z0-9_]+)_(page|items)(/.*)?$#', $slug, $m)) {
			$slug = $m[1] !== '' ? $m[1] : '/'.$m[2];
		} elseif (preg_match('#^(?:/.*?)?/([a-z0-9_]+)_item(/.*)?$#', $slug, $m)) {
			// an item shown by the collection's own view (no news_item.html)
			// shares that view's page fields
			$slug = file_exists(controller::build_view_path($m[1].'_item')) ? '/'.$m[1].'/'.$m[1].'_item' : '/'.$m[1];
		}
		return $slug;
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
		$page = function ($value) { return (bool)preg_match('/^[1-9][0-9]*$/', $value) && $value !== '1'; };
		foreach ($segments as $i => $segment) {
			$rest = array_slice($segments, $i + 1);
			if ($segment === $name.'_page') return count($rest) === 1 && $page($rest[0]);
			if ($segment === $name.'_item') return count($rest) === 1 && (!ctype_digit($rest[0]) || $rest[0] === (string)(int)$rest[0]);
			if ($segment === $name.'_items') {
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

	// custom cms routes for admin panels and collection URLs
	public function route() {
		include 'routes.php';

		// /news/news_item/3  -> news_item.html (or news.html)
		// /news/news_page/2  -> news.html
		// /news/news_items/tag/php -> news.html
		$uri = config::get('uri_string');
		if (preg_match('#^/(.+?)/([a-z0-9_]+)_(item|items|page)(/|$)#', (string)$uri, $m)) {
			// item URLs live under the collection's own name: /news/news_item/x
			if ($m[3] === 'item' && $m[1] !== $m[2]) return;
			$candidates = $m[3] === 'item' ? array($m[2].'_item', $m[1]) : array($m[1]);
			foreach ($candidates as $view) {
				if (strpos($view, '..') !== false || strpos(basename($view), '_') === 0) continue;
				$file = controller::build_view_path($view);
				// only views that actually render this collection
				if (file_exists($file) && strpos(file_get_contents($file), '<!-- render.cms.'.$m[2]) !== false) {
					controller::route(preg_quote($m[1].'/'.$m[2].'_'.$m[3], '%').'(/|$)')->to($view);
					break;
				}
			}
		}
	}

	// Setup routes for the admin section
	public function setup()
	{
		if (config::get('cms_enabled') == false) {
			return;
		}

		cms::session();

		$db = database::instance('cms');
		if (!database::configured()) {
			return;
		}

		$uri_string = config::get('uri_string');
		$index_file = config::get('index_file');
		$slug = cms::slug_for_uri(str_replace('/'.$index_file, '', $uri_string));

		if (strpos($slug, '/login') === 0) {
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
		if (!array_key_exists($name, $page->getProperties())) {
			if (database::$frozen) return false;
			$field_type = cms_types::of_example($example);
			cms_types::ensure($type, array($name => $field_type));
			$page->$name = cms_types::clean($field_type, $example, $name);
			R::store($page);
		}
		$value = cms_types::show($page->$name, $example);
		$value = $value === '' ? false : $value;
		if (cms_editor::editing()) {
			return cms_editor::field($type, $type === 'sitepage' ? 'site' : $this->slug, $name, $value, template::get('current_block'));
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

		// pagination
		$page_size = (int)config::get($name."_page_size");
		if ($page_size < 1) {
			$page_size = (int)config::get("raster_page_size");
			if ($page_size < 1) {
				$page_size = 10;
			}
		}

		$page = (int)util::param($name.'_page', 0);
		$roffset = $page > 1 ? ($page-1)*$page_size : 0;
		// /news/news_page/02 shows page 2, but is not a page worth keeping
		if (!cms::canonical_uri($name)) raster_cache::skip();

		// one item: /news/news_item/3 or /news/news_item/raster-runs-on-php-8
		if (util::param($name) == $name.'_item') {
			$wanted = (string)util::param($name.'_item');
			if (ctype_digit($wanted)) $filters['id'] = (int)$wanted;
			else $filters['slug'] = $wanted;
		}

		// uri filters: /news/news_items/tag/php
		$uri_filters = array();
		if (util::param($name) == $name.'_items') {
			$uri_segments = config::get('uri_segments');
			$start_key = array_search($name.'_items', $uri_segments);
			foreach ($uri_segments as $key => $value) {
				if ($key > $start_key && ($key - $start_key)%2 == 0) {
					$uri_filters[$uri_segments[$key-1]] = $value;
				}
			}
			unset($uri_filters[$name.'_page']);
			$filters = $uri_filters + $filters;
		}

		// param filters: render.cms.news('featured=1&order=newest&limit=3'),
		// render.cms.booking('stylist=?stylist&date>=today&order=date,time')
		// (see cms_store::list_options)
		$options = array();
		$compare = array();
		if (!empty($arguments) && is_string($arguments[0])) {
			$list = cms_store::list_options($arguments[0]);
			$options = $list['options'];
			// fields a list mentions become fields of the collection, starting
			// with the value a list asks for (featured=1)
			foreach ($list['fields'] as $field => $value) {
				if ($value !== '' || !isset($expected_properties[$field])) $expected_properties[$field] = $value;
			}
			foreach ($list['conditions'] as $condition) {
				if ($condition[1] === '=') $filters[$condition[0]] = $condition[2];
				else $compare[] = $condition;
			}
		}
		if (isset($options['limit']) && (int)$options['limit'] > 0) {
			$page_size = (int)$options['limit'];
			$roffset = $page > 1 ? ($page-1)*$page_size : 0;
		}

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
			// mock-up (an empty table too, checked once the list is read);
			// until schema --apply, its filter and page URLs are not kept
			if (!$exists) {
				if ($uri_filters || $page > 1) raster_cache::skip();
				return false;
			}
		} elseif (!$exists || R::count($this->data_name) == 0) {
			// the first item is the placeholder content from the template
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
				foreach ($new as $key => $value) $latest->$key = cms_types::clean($types[$key], $value, $key);
				R::store($latest);
			}
		}

		// only real columns can be filtered on
		$fields = cms_store::columns($this->data_name);
		// drafts (enabled = 0) and future posts are hidden, except for editors
		list($sql, $bindings) = cms_store::published_sql($fields, cms::loggedin());
		if ($record) {
			list($visible, $more) = cms_records::visibility_sql($record);
			$sql .= $visible;
			$bindings += $more;
		}
		// owner=me: the logged in person's own records, editors included
		// (their /account shows what they made, not everyone's)
		if ($record && isset($filters['owner']) && $filters['owner'] === 'me') {
			$user = authentication::user();
			if (!$user || !$record['owner'] || !$user['id']) return array();
			$filters['owner'] = (string)$user['id'];
		}
		// a record's hidden fields are no filter for visitors (no asking
		// "did this email book?")
		if ($record && !cms::loggedin()) {
			$secret = array_merge($record['hidden'], array('owner'));
			foreach ($secret as $hidden) {
				// a filter the URL asks for and the list ignores
				if (isset($uri_filters[$hidden])) raster_cache::skip();
				unset($filters[$hidden]);
			}
			$compare = array_filter($compare, function ($c) use ($secret) { return !in_array($c[0], $secret, true); });
		}
		foreach ($filters as $key => $value) {
			if (!array_key_exists($key, $fields) || !preg_match('/^[a-z0-9_]+$/', $key)) {
				if ($key === 'id' || $key === 'slug') return array();
				// /news/news_items/nonsense/x lists everything; it answers,
				// but every made-up field would be a page of its own
				if (array_key_exists($key, $uri_filters)) raster_cache::skip();
				continue;
			}
			// /menu/menu_items/price/14.500 and /events/events_items/date/10 Oct 2026
			// find what the site's own links (14.50, 2026-10-10) find; they
			// answer, but there is no end to the spellings
			if (array_key_exists($key, $uri_filters) && !cms::canonical_value($fields[$key], $value, isset($expected_properties[$key]) ? $expected_properties[$key] : cms::collection_example($name, $key))) raster_cache::skip();
			$compare[] = array($key, '=', $value);
		}
		// featured=1, date>=today, guests>4, each as its field's type
		list($more, $more_bindings) = cms_store::conditions_sql($compare, $fields);
		$sql .= $more;
		$bindings += $more_bindings;
		$sql .= ' ORDER BY '.cms_store::order_sql(isset($options['order']) ? $options['order'] : '', $fields).' LIMIT '.(int)$page_size.' OFFSET '.(int)$roffset;
		$data = array_values(array_map(array('cms_store', 'export_item'), R::find($this->data_name, $sql, $bindings)));
		// a filter nothing matches and a page past the last one answer, empty
		// (or with the mock-up, while the list has no items); they are not
		// kept, so made-up URLs don't fill the page cache
		if (!$data && ($page > 1 || $uri_filters)) raster_cache::skip();
		if (!$data && !$record && database::$frozen && R::count($this->data_name) == 0) return false;
		// the template prints text: 4.5 as its mock-up 4.50 does
		foreach ($data as $key => $row) $data[$key] = cms_types::show_row($row, $expected_properties);
		if ($record) {
			foreach ($data as $key => $row) $data[$key] = cms_records::for_template($record, cms_records::decode($record, $row));
		}

		// items made before slugs existed get one (development only)
		if (!database::$frozen && array_key_exists('slug', $fields)) {
			foreach ($data as $key => $item) {
				if (!empty($item['slug'])) continue;
				$bean = R::load($this->data_name, $item['id']);
				$bean->slug = cms_store::unique_slug($this->data_name, cms_store::slug_source($item), $item['id']);
				R::store($bean);
				$data[$key]['slug'] = $bean->slug;
			}
		}
		if (empty($data) && (isset($filters['id']) || isset($filters['slug'])) && !headers_sent()) {
			// a detail page for an item that does not exist
			http_response_code(404);
		}

		// building auto detail links
		foreach ($data as $key => $item) {
			$base = config::get("link_uri");
			$data[$key]['raster_detail_link'] = $base.$name.'/'.$name.'_item/'.(!empty($item['slug']) ? rawurlencode($item['slug']) : $item['id']);
			foreach ($filter_link_params as $at_key => $filter_fields) {
				$filter_link = $base.$name.'/'.$name.'_items/';
				foreach ($filter_fields as $field) {
					$filter_link .= $field.'/'.rawurlencode(isset($item[$field]) ? $item[$field] : '').'/';
				}
				$data[$key]['raster_filter@'.$at_key] = $filter_link;
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
			http_response_code(403);
			exit('Forbidden');
		}
		database::instance('cms');
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

	// message shown above the login form
	function login_message() {
		database::instance('cms');
		if (!database::configured()) {
			return '<p class="notice">No database is configured for this environment (application/config/db/).</p>';
		}
		authentication::connect();
		if (!authentication::has_users()) {
			return '<p class="notice">There are no users yet. Create one from the project folder: <code>php bin/raster user admin</code></p>';
		}
		return '';
	}

	// editors and admins get the toolbar and see drafts
	static function loggedin() {
		return authentication::can('editor');
	}

	// bound to before_output: the editor, for editors
	public function inject_toolbar() {
		if (!cms::loggedin() || !$this->page_name) return false;
		cms_editor::inject($this->page_name, $this->slug);
		return true;
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
