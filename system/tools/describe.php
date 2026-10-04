<?php
// #Describing a site to an agent
//
// One answer to "what is this Raster site?", meant to be the first thing an
// agent asks and the only thing it needs before editing: how URLs reach views,
// the content model the markup declares, every name a template may call, the
// settings that change behaviour, and whether the templates lint clean right
// now.
//
// It reads files and never executes a model, so it is safe anywhere, and the
// whole thing costs tens of milliseconds — see `raster describe --time`. The
// point is that an agent gets it in one call instead of a dozen shell
// invocations, each paying PHP's startup again.
//
// Used by `php bin/raster describe` and by the MCP tools `describe` and
// `vocabulary`.
class raster_describe
{
	// The settings worth telling an agent about: they change how the site
	// behaves. Anything that could hold a secret (mcp_token, the mail DSN and
	// its password) is deliberately not here.
	static $settings = array(
		'theme', 'default_view', 'views_ext', 'rewrite', 'strict_templates',
		'error_document_404', 'error_document_503', 'site_url', 'cms_enabled', 'raster_page_size',
		'raster_media_folder', 'feed_limit', 'sitemap_skip', 'protected',
		'registration', 'login_page', 'after_login', 'reset_page',
		'password_min_length', 'newsletter_double_opt_in',
		'newsletter_confirm_page', 'newsletter_unsubscribe_page', 'mail_from',
		'languages', 'domain_language', 'language_cookie', 'page_cache',
		'page_cache_ttl', 'page_cache_skip', 'api_system_models',
		'allow_deprecated',
	);

	static function sections() {
		return array('site', 'routing', 'pages', 'admin_pages', 'collections', 'vocabulary', 'settings', 'lint', 'schema', 'views');
	}

	// $sections limits the work; the default is everything.
	static function site($sections = null, $limits = array()) {
		$limits += array('default' => 120, 'problems' => 50);
		$want = function ($name) use ($sections) {
			return $sections === null || in_array($name, (array)$sections);
		};
		$inspector = new raster_inspector();
		$out = array();

		if ($want('site')) {
			$out['site'] = array(
				'app' => boot::$appname,
				'raster' => trim(@file_get_contents(BASE.'VERSION')) ?: 'unknown',
				'environment' => config::get('environment'),
				'page_cache' => raster_cache::enabled(),
				'theme' => $inspector->theme,
				'base_url' => config::get('base_uri'),
				'views' => config::get('views_path', 'views').'/'.$inspector->theme.'/',
				'models' => config::get('models_path', 'models').'/',
			);
		}

		if ($want('routing')) {
			$routes = array();
			foreach ($inspector->routes() as $route) {
				$routes[] = array('pattern' => $route['pattern'], 'view' => $route['view'], 'theme' => $route['theme']);
			}
			$out['routing'] = array(
				'rules' => array(
					'/ renders '.config::get('default_view', 'index').config::get('views_ext', '.html'),
					'/about renders about'.config::get('views_ext', '.html').', /docs/setup renders docs/setup'.config::get('views_ext', '.html'),
					'/news.rss renders the view news.rss; also .atom, .xml, .json, .txt',
					'views and folders starting with _ are never pages (_layout, _email/)',
					'a collection adds /<name>/<name>_item/<slug>, /<name>/<name>_page/<n> and /<name>/<name>_items/<field>/<value>',
					'anything else is 404',
				),
				'extra_routes' => $routes,
				'extra_routes_file' => boot::$appname.'/config/the_routes.php',
			);
		}

		$model = ($want('pages') || $want('collections') || $want('schema')) ? $inspector->content_model() : null;

		if ($want('pages')) {
			$pages = array();
			foreach ($model['pages'] as $page) {
				$fields = array();
				foreach ($page['fields'] as $name => $field) {
					$fields[$name] = self::clip($field['default'], $limits['default']);
				}
				$pages[] = array('url' => $page['url'], 'view' => $page['view'], 'table' => $page['type'], 'fields' => $fields) + self::types($page['fields']);
			}
			$out['pages'] = $pages;
		}

		// the views 'protected' keeps for staff: the in-page editor lists them
		if ($want('admin_pages')) {
			$out['admin_pages'] = array();
			foreach (authentication::all_admin_pages() as $page) {
				$out['admin_pages'][] = array('url' => '/'.($page['view'] === config::get('default_view', 'index') ? '' : $page['view']), 'view' => $page['view'], 'title' => $page['title'], 'role' => $page['role']);
			}
		}

		if ($want('collections')) {
			$collections = array();
			foreach ($model['collections'] as $collection) {
				$fields = array();
				foreach ($collection['fields'] as $name => $field) {
					$fields[$name] = self::clip($field['default'], $limits['default']);
				}
				$collections[] = array(
					'name' => $collection['name'],
					'fields' => $fields,
				) + self::types($collection['fields']) + array(
					'used_in' => $collection['views'],
					'items' => self::count_items($collection['type']),
					'item_url' => '/'.$collection['name'].'/'.$collection['name'].'_item/{slug}',
					'page_size' => (int)config::get($collection['name'].'_page_size', config::get('raster_page_size', 10)),
				);
				// a type a model declares: who may see, create and act on its records
				if (isset($collection['model'])) {
					$collections[count($collections) - 1] += array('declared_by' => $collection['model'], 'public' => $collection['public'], 'owner' => $collection['owner'], 'create' => $collection['create'], 'staff_add' => $collection['staff_add'], 'readonly' => $collection['readonly'], 'hidden' => $collection['hidden'], 'actions' => $collection['actions']);
				}
			}
			$out['collections'] = $collections;
		}

		if ($want('vocabulary')) $out['vocabulary'] = $inspector->vocabulary();

		if ($want('settings')) {
			$settings = array();
			foreach (self::$settings as $name) {
				$value = config::get($name);
				if ($value !== null) $settings[$name] = $value;
			}
			$out['settings'] = $settings;
		}

		if ($want('lint')) {
			$problems = $inspector->lint(array($inspector->theme));
			$errors = array_values(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
			$out['lint'] = array(
				'errors' => count($errors),
				'warnings' => count($problems) - count($errors),
				'problems' => array_slice($problems, 0, $limits['problems']),
			);
		}

		if ($want('schema')) {
			try {
				$status = (new raster_schema())->status();
				$out['schema'] = array('drift' => (bool)$status['drift'], 'frozen' => (bool)database::$frozen, 'fix' => $status['drift'] ? 'php bin/raster schema --apply' : null);
			} catch (Exception $e) {
				$out['schema'] = array('error' => $e->getMessage());
			}
		}

		if ($want('views')) $out['views'] = $inspector->views();

		return $out;
	}

	static protected function count_items($table) {
		try {
			cms_store::connect();
			return cms_store::table_exists($table) ? (int)R::count($table) : 0;
		} catch (Exception $e) {
			return null;
		}
	}

	// the fields that aren't text, with their types; the rest are text
	static function types($fields) {
		$types = array();
		foreach ($fields as $name => $field) {
			if (isset($field['type']) && $field['type'] !== 'text') $types[$name] = $field['type'];
		}
		return $types ? array('types' => $types) : array();
	}

	static protected function clip($text, $length) {
		$text = trim(preg_replace('/\s+/', ' ', (string)$text));
		return strlen($text) > $length ? substr($text, 0, $length - 1).'…' : $text;
	}
}
