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
			$slug = '/'.$m[1].'/'.$m[1].'_item';
		}
		return $slug;
	}

	// Visitors don't get a session cookie; editors get one when they log in.
	static function session($force = false) {
		util::session($force);
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

		try {
			// edits sent by the in-page editor
			if (cms::loggedin() && util::post('raster_action')) {
				if (!util::csrf_valid(util::post('csrf'))) {
					http_response_code(403);
					exit('Invalid or expired form, reload the page and try again.');
				}
				$this->save_page();
				$this->save_data();
				$this->add_data();
			}

			// the page's row is created by its first print.cms field
			$this->page = cms_store::latest($page_name);
		} catch (Exception $e) {
			// a frozen schema without this page: templates show their defaults
			log::warning('CMS: '.$e->getMessage());
			$this->page = null;
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
		$page = cms_store::latest($type);
		if (!$page && !database::$frozen) {
			$page = R::dispense($type);
			$page->slug = $type === 'sitepage' ? 'site' : $this->slug;
			$page->updated_at = R::isoDateTime();
			R::store($page);
		}
		if (!$page) return false;
		$fields = cms_store::columns($type);
		if (!array_key_exists($name, $fields)) {
			if (database::$frozen) return false;
			$page->$name = trim(template::get('current_block'));
			R::store($page);
		}
		$value = $page->$name;
		return ($value === null || (string)$value === '') ? false : $value;
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

		// one item: /news/news_item/3 or /news/news_item/raster-runs-on-php-8
		if (util::param($name) == $name.'_item') {
			$wanted = (string)util::param($name.'_item');
			if (ctype_digit($wanted)) $filters['id'] = (int)$wanted;
			else $filters['slug'] = $wanted;
		}

		// uri filters: /news/news_items/tag/php
		if (util::param($name) == $name.'_items') {
			$uri_segments = config::get('uri_segments');
			$start_key = array_search($name.'_items', $uri_segments);
			foreach ($uri_segments as $key => $value) {
				if ($key > $start_key && ($key - $start_key)%2 == 0) {
					$filters[$uri_segments[$key-1]] = $value;
				}
			}
		}
		unset($filters[$name.'_page']);

		// param filters: render.cms.news('featured=1&order=newest&limit=3')
		$options = array();
		if (!empty($arguments) && is_string($arguments[0])) {
			$filters = $this->make_filters($arguments[0], $expected_properties, $filters, $options);
		}
		if (isset($options['limit']) && (int)$options['limit'] > 0) {
			$page_size = (int)$options['limit'];
			$roffset = $page > 1 ? ($page-1)*$page_size : 0;
		}

		$exists = cms_store::table_exists($this->data_name);
		if (!$exists || R::count($this->data_name) == 0) {
			if (database::$frozen) return false;
			// the first item is the placeholder content from the template
			$item = R::dispense($this->data_name);
			foreach ($expected_properties as $property=>$content) {
				$item->$property = trim($content);
			}
			$item->updated_at = R::isoDateTime();
			$item->enabled = '1';
			$item->published_at = '';
			$item->slug = cms_store::unique_slug($this->data_name, cms_store::slug_source($expected_properties), 0);
			R::store($item);
		} elseif (!database::$frozen) {
			// new fields in the template become new columns
			$fields = cms_store::columns($this->data_name);
			$latest = cms_store::latest($this->data_name);
			$changed = false;
			foreach (array('slug', 'published_at') as $system) {
				if (!array_key_exists($system, $fields)) {
					$latest->$system = '';
					$changed = true;
				}
			}
			foreach ($expected_properties as $key => $value) {
				if (!array_key_exists($key, $fields)) {
					$latest->$key = trim($value);
					$changed = true;
				}
			}
			if ($changed) R::store($latest);
		}

		// only real columns can be filtered on
		$fields = cms_store::columns($this->data_name);
		// drafts (enabled = 0) and future posts are hidden, except for editors
		list($sql, $bindings) = cms_store::published_sql($fields, cms::loggedin());
		foreach ($filters as $key => $value) {
			if (!array_key_exists($key, $fields) || !preg_match('/^[a-z0-9_]+$/', $key)) {
				if ($key === 'id' || $key === 'slug') return array();
				continue;
			}
			$sql .= ' AND '.$key.' = :'.$key.' ';
			$bindings[':'.$key] = $value;
		}
		$sql .= ' ORDER BY '.cms_store::order_sql(isset($options['order']) ? $options['order'] : '', $fields).' LIMIT '.(int)$page_size.' OFFSET '.(int)$roffset;
		$data = R::exportAll(R::find($this->data_name, $sql, $bindings));

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

	// parses the filters and adds new fields if any
	protected function make_filters($filters, &$expected_properties, &$data_filter, &$options = array()) {
		foreach (explode('&', $filters) as $chunk) {
			$pair = explode("=", $chunk, 2);
			if ($pair[0] === '') continue;
			// order and limit shape the list, they are not fields
			if (in_array($pair[0], array('order', 'limit'))) {
				$options[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
				continue;
			}
			$data_filter[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
			$expected_properties[$pair[0]] = $data_filter[$pair[0]];
		}
		return $data_filter;
	}

	protected function get_page_variable($page, $variable) {
		$db = database::instance('cms');
		$data = cms_store::latest(cms::field_type((string)$variable, cms::safe_type($page)));
		return array(
			"type" => $data ? $data->getMeta('type') : '',
			"value" => $data ? $data->$variable : ''
		);
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

	function edit_data() {
		cms::require_admin();
		$data_type = cms::collection_type(util::post('name'));
		$data = cms_store::table_exists($data_type) ? R::findAll($data_type) : array();
		$fields = cms_store::columns($data_type);
		unset($fields['password']);
		$csrf = util::csrf_token();
		$type = $data_type;
		include BASE.'models/cms/editor/data.php';
		return false;
	}

	function edit_item() {
		cms::require_admin();
		$item_type = cms::collection_type(util::post('name'));
		$item_id = (int)util::post('did');
		$data = R::load($item_type, $item_id);
		$fields = cms_store::columns($item_type);
		$csrf = util::csrf_token();
		include BASE.'models/cms/editor/item.php';
		return false;
	}

	function add_item() {
		cms::require_admin();
		$item_type = cms::collection_type(util::post('name'));
		$data = R::dispense($item_type);
		$fields = cms_store::columns($item_type);
		$csrf = util::csrf_token();
		include BASE.'models/cms/editor/add.php';
		return false;
	}

	function remove_item() {
		cms::require_admin(true);
		$item_type = cms::collection_type(util::post('name'));
		return array('removed' => cms_store::delete_item($item_type, (int)util::post('did')));
	}

	function edit_variable() {
		cms::require_admin();
		extract($this->get_page_variable(util::post('page'), util::post('name')));
		$csrf = util::csrf_token();
		include BASE.'models/cms/editor/page.php';
		return false;
	}

	function style() {
		if (!util::param('output', false)) {
				return config::get('link_uri').'api/cms/style/output/true';
		}
		header("Content-Type: text/css");
		header("X-Content-Type-Options: nosniff");
		echo file_get_contents(BASE.'views/cms_admin/style.css');
		return false;
	}

	public function css() {
		$file = util::param('raster_file', 'raster_cms');
		if (!in_array($file, array('raster_cms', 'croppic'))) return false;
		header("Content-Type: text/css");
		header("X-Content-Type-Options: nosniff");
		echo file_get_contents(BASE.'models/cms/css/'.$file.'.css');
		return false;
	}

	public function script() {
		$file = util::param('raster_file', 'raster_cms');
		if (!in_array($file, array('raster_cms', 'croppic.min'))) return false;
		header("content-type: application/javascript");
		echo file_get_contents(BASE.'models/cms/js/'.$file.'.js');
		return false;
	}

	// /login/logout/fromraster, the toolbar's logout link
	public function logout() {
		if (util::param('logout') === 'fromraster') {
			authentication::log_out();
			util::redirect();
		}
		return false;
	}

	// the editor login page (system/views/cms_admin/login.html) uses
	// render.authentication.login; this stays for older templates
	public function login() {
		$this->logout();
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

	protected function save_page() {
		if (util::post('raster_action') !== 'save_page') {
			return false;
		}
		// edits always go to the page being viewed (or the site, for site_*)
		$type = cms::field_type((string)util::post('variable_name'), $this->page_name);
		$variable = (string)util::post('variable_name');
		if (!preg_match('/^[a-z0-9_]+$/', $variable) || cms::reserved($variable, 'field') || !array_key_exists($variable, cms_store::columns($type))) {
			return false;
		}
		cms_store::update_page($type, $this->slug, array($variable => util::post('raster_page_value')), array($variable));
	}

	protected function save_data() {
		if (util::post('raster_action') !== 'save_data') {
			return false;
		}
		$data_type = cms::collection_type(util::post('data_name'));
		$this->store_posted_item($data_type, (int)util::post('data_id'));
	}

	protected function add_data() {
		if (util::post('raster_action') !== 'add_data') {
			return false;
		}
		$data_type = cms::collection_type(util::post('data_name'));
		$this->store_posted_item($data_type, 0);
	}

	protected function store_posted_item($data_type, $id) {
		$fields = cms_store::columns($data_type);
		$values = array();
		foreach ($fields as $key => $value) {
			if (in_array($key, cms_store::$system_fields) || $key === 'password') continue;
			if (array_key_exists($key, $_POST)) $values[$key] = util::post($key);
		}
		cms_store::save_item($data_type, $id, $values, array_keys($values));
	}

	// the editing toolbar, added to every page for logged in editors
	public function buttons() {

		if (!cms::loggedin()) {
			return '';
		}

		$link = config::get('link_uri');
		return '
				<link rel="stylesheet" href="'.$link.'api/cms/css/raster_file/croppic">
				<link rel="stylesheet" href="'.$link.'api/cms/css">
				<script>
					var Raster_Admin = {};
					Raster_Admin.page_data = '.json_encode(array_values(array_unique($this->page_data)), JSON_HEX_TAG).';
					Raster_Admin.page_variables = '.json_encode(array_values(array_unique($this->page_variables)), JSON_HEX_TAG).';
					Raster_Admin.page_name = '.json_encode($this->page_name, JSON_HEX_TAG).';
					Raster_Admin.csrf = '.json_encode(util::csrf_token()).';
				</script>
				<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
				<script src="'.$link.'api/cms/script/raster_file/croppic.min"></script>
				<script src="'.$link.'api/cms/script"></script>
		';
	}

	// bound to before_output: adds the toolbar before </body>
	public function inject_toolbar() {
		if (!cms::loggedin() || !$this->page_name) return false;
		$template = template::instance();
		$buttons = $this->buttons();
		if (stripos($template->output, '</body>') !== false) {
			$template->output = preg_replace('#</body>#i', $buttons."\n</body>", $template->output, 1);
		} else {
			$template->output .= $buttons;
		}
		return true;
	}

	protected static function media_dir() {
		$dir = dirname(BASE).'/'.trim(config::get('raster_media_folder', 'media'), '/').'/';
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		return $dir;
	}

	public function upload_media() {
		cms::require_admin(true);
		$allowed = array('gif' => IMAGETYPE_GIF, 'jpeg' => IMAGETYPE_JPEG, 'jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG);
		if (empty($_FILES['img']) || $_FILES['img']['error'] !== UPLOAD_ERR_OK) {
			return array('status' => 'error', 'message' => 'Upload failed');
		}
		$extension = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
		$info = @getimagesize($_FILES['img']['tmp_name']);
		if (!isset($allowed[$extension]) || !$info || $info[2] !== $allowed[$extension]) {
			return array('status' => 'error', 'message' => 'Only gif, jpeg and png images are allowed');
		}
		$filename = bin2hex(random_bytes(8)).'.'.$extension;
		move_uploaded_file($_FILES['img']['tmp_name'], cms::media_dir().$filename);
		return array(
			"status" => 'success',
			"url" => config::get('base_uri').trim(config::get('raster_media_folder', 'media'), '/').'/'.$filename,
			"width" => $info[0],
			"height" => $info[1]
		);
	}

	public function crop_media() {
		cms::require_admin(true);

		// only images already in the media folder can be cropped
		$media_url = config::get('base_uri').trim(config::get('raster_media_folder', 'media'), '/').'/';
		$imgUrl = (string)util::post('imgUrl');
		if (strpos($imgUrl, $media_url) !== 0) return array('status' => 'error', 'message' => 'Unknown image');
		$source = realpath(cms::media_dir().basename(substr($imgUrl, strlen($media_url))));
		if (!$source || strpos($source, realpath(cms::media_dir())) !== 0) return array('status' => 'error', 'message' => 'Unknown image');

		$n = function ($key) { return max(0, (int)round((float)util::post($key))); };
		$imgInitW = $n('imgInitW'); $imgInitH = $n('imgInitH');
		$imgW = max(1, $n('imgW')); $imgH = max(1, $n('imgH'));
		$imgY1 = $n('imgY1'); $imgX1 = $n('imgX1');
		$cropW = max(1, $n('cropW')); $cropH = max(1, $n('cropH'));

		$what = getimagesize($source);
		switch(strtolower($what['mime']))
		{
			case 'image/png': $source_image = imagecreatefrompng($source); break;
			case 'image/jpeg': $source_image = imagecreatefromjpeg($source); break;
			case 'image/gif': $source_image = imagecreatefromgif($source); break;
			default: return array('status' => 'error', 'message' => 'image type not supported');
		}

		$resizedImage = imagecreatetruecolor($imgW, $imgH);
		imagecopyresampled($resizedImage, $source_image, 0, 0, 0, 0, $imgW, $imgH, $imgInitW ?: $what[0], $imgInitH ?: $what[1]);
		$dest_image = imagecreatetruecolor($cropW, $cropH);
		imagecopyresampled($dest_image, $resizedImage, 0, 0, $imgX1, $imgY1, $cropW, $cropH, $cropW, $cropH);

		$filename = "cropped_".bin2hex(random_bytes(8)).'.jpeg';
		imagejpeg($dest_image, cms::media_dir().$filename, 90);

		return array(
			"status" => 'success',
			"url" => $media_url.$filename
		);
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

		if (strpos($value, '@') !== false || strpos($value, '+') !== false) {
			$dataattr = str_replace(array('@', '+'), '', $parts[0]);
			preg_match("% ".preg_quote($dataattr, '%')."(.*?)=(.*?)('|\")(.*?)('|\")%", $rendered_tpl, $attribute_value);
			$content = isset($attribute_value[4]) ? $attribute_value[4] : '';
		} elseif ($rpos1 === false || $start === $end) {
			$content = '';
		} else {
			$endpos = strpos($rendered_tpl, $end, $rpos1);
			$content = $endpos === false ? '' : substr($rendered_tpl, $rpos1 + strlen($start), $endpos - $rpos1 - strlen($start));
		}
		$property = $parts[count($parts) -1];
		return array($property, $content);
	}
}
