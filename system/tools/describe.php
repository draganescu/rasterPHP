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
// `vocabulary`. The MCP `describe` leaves out the vocabulary unless asked: it
// is most of a full answer and has its own tool.
class raster_describe
{
	// The settings worth telling an agent about: they change how the site
	// behaves. The models in use add theirs (requires(), 'settings').
	// Anything that could hold a secret (mcp_token, the mail DSN and its
	// password) is deliberately not here.
	static $settings = array(
		'theme', 'default_view', 'views_ext', 'rewrite', 'strict_templates',
		'error_document_403', 'error_document_404', 'error_document_500', 'error_document_503', 'site_url', 'cms_enabled', 'raster_page_size',
		'raster_media_folder', 'feed_limit', 'sitemap_skip', 'protected', 'mail_from',
		'multilingual', 'languages', 'domain_language', 'language_cookie', 'page_cache',
		'page_cache_ttl', 'page_cache_skip', 'api_system_models',
		'allow_deprecated',
		'spam_min_seconds', 'spam_max_age', 'spam_per_ip', 'spam_per_email', 'spam_ip_blocks',
		'spam_max_links', 'spam_blocklist', 'trusted_proxies', 'client_ip_header',
	);

	static function sections() {
		return array('site', 'languages', 'routing', 'pages', 'admin_pages', 'collections', 'vocabulary', 'settings', 'lint', 'schema', 'views');
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

		// the site's languages and what each lacks (#200)
		if ($want('languages')) $out['languages'] = self::languages($inspector);

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
					'a collection adds /<name>/<name>_item/{slug} (the id when an item has no slug), /<name>/<name>_page/<n> and /<name>/<name>_items/<field>/<value>',
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
				$pages[] = array('url' => $page['url'], 'view' => $page['view']) + (isset($page['theme']) ? array('theme' => $page['theme']) : array()) + array('table' => $page['type'], 'fields' => $fields) + self::types($page['fields']);
			}
			$out['pages'] = $pages;
		}

		// the views 'protected' keeps for staff: the in-page editor lists them
		if ($want('admin_pages')) {
			$out['admin_pages'] = array();
			foreach (authentication::all_admin_pages() as $page) {
				$out['admin_pages'][] = array('url' => cms::page_path($page['view'].config::get('views_ext', '.html')), 'view' => $page['view'], 'title' => $page['title'], 'role' => $page['role']);
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
					'item_url' => cms::item_path($collection['name'], '{slug}'),
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
			foreach (array_unique(array_merge(self::$settings, controller::required('settings'))) as $name) {
				$value = config::get($name);
				if ($value !== null) $settings[$name] = $value;
			}
			$out['settings'] = $settings;
		}

		if ($want('lint')) {
			// the site's theme and the ones its routes serve views from
			$problems = $inspector->lint();
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

	// The site's languages, for describe, site_overview and set_languages
	// (#200): whether multilingual is on (enabled: on and two or more
	// languages; multilingual_setting: the setting as written), the default,
	// the list, where each language's pages start when enabled (/ and /ro/,
	// or a domain_language host) and how many translations each language
	// lacks (translation_status's total).
	static function languages($inspector = null) {
		$urls = array();
		$missing = array();
		if (i18n::enabled()) {
			$domains = array();
			foreach ((array)config::get('domain_language', array()) as $domain => $language) $domains[strtolower($language)] = strtolower($domain);
			foreach (i18n::available() as $code) {
				$urls[$code] = isset($domains[strtolower($code)]) ? $domains[strtolower($code)] : '/'.i18n::prefix($code);
			}
			try {
				$inspector = $inspector ?: new raster_inspector();
				foreach (array_slice(i18n::available(), 1) as $code) {
					$missing[$code] = count(array_filter(self::translations($code, $inspector), function ($row) { return !$row['done']; }));
				}
			} catch (Exception $e) {
				$missing = array();
			}
		}
		return array(
			'enabled' => i18n::enabled(),
			'multilingual_setting' => (bool)config::get('multilingual', false),
			'default' => i18n::default_language(),
			'list' => i18n::available(),
			'urls' => (object)$urls,
			'missing' => (object)$missing,
		);
	}

	// Everything that has words in the default language and could have
	// them in $lang: each translated page and site_* field whose shown value
	// isn't empty, and each dictionary key the views print with a mock-up
	// text, and each translated field of every item and record (where is
	// <collection>/<id>), with done telling whether $lang has it. For
	// translation_status and the missing counts above; P4 adds emails.
	static function translations($lang, $inspector = null) {
		$inspector = $inspector ?: new raster_inspector();
		$rows = array();
		cms_store::connect();
		foreach ($inspector->content_model()['pages'] as $page) {
			$stored = cms_store::page_values($page['type']);
			$values = $stored ? $stored['fields'] : array();
			$translated = cms_types::translated($page['type']);
			foreach ($page['fields'] as $name => $field) {
				if (empty($field['translate']) && !in_array($name, $translated, true)) continue;
				$value = isset($values[$name]) && trim((string)$values[$name]) !== '' ? (string)$values[$name] : (string)$field['default'];
				if (trim($value) === '') continue;
				$column = cms_types::column($name, $lang);
				$done = $column !== $name && isset($values[$column]) && trim((string)$values[$column]) !== '';
				$rows[] = array('kind' => $page['view'] === '*' ? 'site' : 'page', 'where' => $page['url'], 'field' => $name, 'default_value' => $value, 'done' => $done);
			}
		}
		// items and records: one query a collection, only the columns counted
		foreach ($inspector->content_model()['collections'] as $collection) {
			if (!cms_store::table_exists($collection['type'])) continue;
			$columns = cms_store::columns($collection['type']);
			$fields = array_values(array_intersect(self::item_translated($collection), array_keys($columns)));
			if (!$fields) continue;
			$select = array('id');
			foreach ($fields as $field) {
				$select[] = $field;
				if (array_key_exists(cms_types::column($field, $lang), $columns)) $select[] = cms_types::column($field, $lang);
			}
			foreach (R::getAll('SELECT `'.implode('`, `', array_unique($select)).'` FROM `'.$collection['type'].'` ORDER BY id') as $item) {
				foreach ($fields as $field) {
					$value = (string)$item[$field];
					if (trim($value) === '') continue;
					$column = cms_types::column($field, $lang);
					$done = $column !== $field && isset($item[$column]) && trim((string)$item[$column]) !== '';
					$rows[] = array('kind' => isset($collection['model']) ? 'record' : 'item', 'where' => $collection['name'].'/'.$item['id'], 'field' => $field, 'default_value' => $value, 'done' => $done);
				}
			}
		}
		foreach (self::strings_used($inspector) as $file => $keys) {
			$strings = self::dictionary($file, $lang);
			foreach ($keys as $key => $default) {
				if (trim($default) === '') continue;
				$rows[] = array('kind' => 'strings', 'where' => $file, 'field' => $key, 'default_value' => $default, 'done' => isset($strings[$key]) && trim((string)$strings[$key]) !== '');
			}
		}
		return $rows;
	}

	// the fields of a collection (as the content model has it) that have a
	// translation per language: a record type's as its model declares them
	// (translate, shared), a markup collection's as the templates print them
	// or the database has them. Never slug: it is made from the title.
	static function item_translated($collection) {
		if (isset($collection['model'])) {
			$info = cms_records::info($collection['name']);
			return $info ? array_values(cms_records::translated_fields($info)) : array();
		}
		$fields = array();
		foreach ($collection['fields'] as $name => $field) {
			if (!empty($field['translate'])) $fields[] = $name;
		}
		return array_values(array_unique(array_merge($fields, array_intersect(cms_types::translated($collection['type']), array_keys($collection['fields'])))));
	}

	// file => key => the mock-up text, for every print.i18n.<file>('key')
	// in the site's theme and the themes its routes serve views from
	static function strings_used($inspector) {
		$used = array();
		$themes = array_unique(array_merge(array($inspector->theme), array_column($inspector->routes(), 'theme')));
		foreach ($themes as $theme) {
			if (!is_dir($inspector->theme_dir($theme))) continue;
			foreach ($inspector->views($theme) as $view) {
				list($blocks) = raster_inspector::blocks(file_get_contents($inspector->theme_dir($theme).'/'.$view));
				self::collect_strings($blocks, $used);
			}
		}
		ksort($used);
		return $used;
	}

	static protected function collect_strings($blocks, &$used) {
		foreach ($blocks as $block) {
			if ($block['keyword'] === 'remove') continue;
			if ($block['keyword'] === 'print') {
				$attribute = raster_inspector::attribute_call($block['ref']);
				$ref = raster_inspector::model_reference($attribute ? $attribute['ref'] : $block['ref']);
				$call = $ref && $ref['model'] === 'i18n' ? template::parse_call($ref['method']) : false;
				// a dictionary is any method i18n doesn't have, with the key as its argument
				if ($call !== false && isset($call[1][0]) && is_string($call[1][0]) && !method_exists('i18n', $call[0])) {
					$default = $attribute ? (string)template::get_attribute($block['inner'], $attribute['attribute']) : trim($block['inner']);
					if (!isset($used[$call[0]][$call[1][0]]) || $used[$call[0]][$call[1][0]] === '') $used[$call[0]][$call[1][0]] = $default;
				}
			}
			if (!empty($block['children'])) self::collect_strings($block['children'], $used);
		}
	}

	// the words of <app>/i18n/<lang>/<file>.php, as the i18n model reads them
	static function dictionary($file, $lang) {
		if (!preg_match('/^[a-z0-9_]+$/', $file) || !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $lang)) return array();
		return self::dictionary_file(APPBASE.'i18n/'.$lang.'/'.$file.'.php');
	}

	// a dictionary file's array, or the $lang it sets in the older form
	static function dictionary_file($path) {
		if (!is_file($path)) return array();
		$read = function ($path) {
			$lang = array();
			$returned = include $path;
			return is_array($returned) ? $returned : $lang;
		};
		return (array)$read($path);
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
