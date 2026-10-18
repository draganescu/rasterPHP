<?php
// The controller singleton handles the main sequence for a standard
// request that Raster receives. Its a singleton because one of the main
// tennets of this architecture is to have one and only one controller
class controller {
	
	// standard singleton boilerplate repeated because
	// there is no singleton inherited and i am commenting this
	// in every file to remember not coding when i am sleepy
	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}
    
    // The routes property is matching the 
    protected $routes = array();
    // Ohh bad programing, but it works this is set when
    // we want to make the controller think the request's URL
    // is different
    public $forced_route = array();
    // Whatever the controller matched
    public $current_route = '';
    // the view the route resolved to, without the extension (staff/rota), '' for a 404
    public $current_view = '';
    // Manual routes holder uset by controller::route('a')->to('b');
    public $current_config_route = '';
    // Memory property to keep track in case themes are changed in a 
    // single request
    private $changed_themes = array();
    
    // this is a holder for all the loaded models *as requested by the view*
    protected $models = array();
    // model instances, keyed by model name
    public $objects = array();
    // the model currently being loaded (useful inside loading_model_* events)
    public $loading_model = null;

    // singleton boilerplate stuff
    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls, false) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }
	
	// Files and folders starting with _ (_layout.html, _email/) are partials,
	// never pages, whichever URL asks for them
	static function is_partial($view)
	{
		return (bool)preg_match('#(^|/)_#', (string)$view);
	}

	// So, the views can be taken out of the application's directory for
	// -paranoid- sorry security reasons. This is why you can 
	// config::set('views_path')->to('hidden_dir_relative_to_'.APPBASE) 
	// and APPBASE is always relative to system.
	static function build_view_path($view, $ext = null)
	{
		$view_file = config::get('theme') . DIRECTORY_SEPARATOR .
						$view . ($ext === null ? config::get('views_ext') : $ext);
		if (!file_exists(APPBASE . config::get('views_path') . DIRECTORY_SEPARATOR . $view_file)) {
			return BASE . 'views/' . $view_file;
		} else {
			return APPBASE . config::get('views_path') . DIRECTORY_SEPARATOR . $view_file;
		}
	}
	
	// Just as the views the models can live outsite the app
	// being just classes the code is reusable so maybe you can have a separate
	// library outside the application and integrate it in other
	// projects that do not use Raster
	// 
	// the method takes $model (string) as a param and based on 
	// configuration returns $paths (array) containing all possible
	// filesystem locations for a model
	static function build_model_paths( $model ) {
		// app_path is for a model built for the application
		$paths['app_path'] = APPBASE . config::get('models_path') . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . $model . '.php';
		// system_path is for default Raster models
		$paths['system_path'] = BASE . config::get('models_path') . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . $model . '.php';
		// extended_path is for replaced or extended system models
		$paths['extended_path'] = APPBASE . config::get('models_path') . DIRECTORY_SEPARATOR . 'the_' . $model . DIRECTORY_SEPARATOR . 'the_' . $model . '.php';
		return $paths;
	}

	// ##Models
	// One lookup finds every model, for the request and the tools alike:
	// models/<name>/ is the site's own and is used whole; else
	// models/the_<name>/ overrides system/models/<name>/; else the bundled
	// model. Gives array(name, class, file, parent, bundled, shadows, dirs)
	// or null. parent is the bundled file an override extends, shadows says
	// the site's model hides a bundled one, dirs are where its helpers and
	// sql/ live, the override's first.
	static $found = array();
	static $declared = null;
	static $used = null;

	static function model($name) {
		$name = (string)$name;
		if (array_key_exists($name, self::$found)) return self::$found[$name];
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) return null;
		$app = APPBASE.config::get('models_path', 'models');
		$bundled = BASE.'models/'.$name;
		$has_bundled = is_file("$bundled/$name.php");
		$found = null;
		if (is_file("$app/$name/$name.php")) {
			$found = array('class' => $name, 'file' => "$app/$name/$name.php", 'parent' => null, 'bundled' => false, 'shadows' => $has_bundled, 'dirs' => array("$app/$name"));
		} elseif (is_file("$app/the_$name/the_$name.php")) {
			$found = array('class' => "the_$name", 'file' => "$app/the_$name/the_$name.php", 'parent' => $has_bundled ? "$bundled/$name.php" : null, 'bundled' => $has_bundled, 'shadows' => false, 'dirs' => $has_bundled ? array("$app/the_$name", $bundled) : array("$app/the_$name"));
		} elseif ($has_bundled) {
			$found = array('class' => $name, 'file' => "$bundled/$name.php", 'parent' => null, 'bundled' => true, 'shadows' => false, 'dirs' => array($bundled));
		}
		return self::$found[$name] = $found ? array('name' => $name) + $found : null;
	}

	// loads a model's file (and the bundled one an override extends) and
	// gives the class to call: 'the_mail', 'mail', or null. Bundled code
	// sends mail through it, so a site's the_mail is the one that sends:
	//   $mail = controller::model_class('mail'); $mail::send_view(...);
	static function model_class($name) {
		$found = controller::model($name);
		if (!$found) return null;
		if ($found['parent']) require_once $found['parent'];
		require_once $found['file'];
		return class_exists($found['class'], false) ? $found['class'] : null;
	}

	// every model by name, the site's first, with what it declares
	// (declares: types, schema, api, requires) and the events it listens to
	// (listens: event => method or methods). Kept in data/cache/models.json
	// while Raster's version and the model files stay the same; only the
	// files that have listens() are loaded to make it.
	static function models() {
		if (self::$declared !== null) return self::$declared;
		$names = array();
		$stamp = array(trim((string)@file_get_contents(BASE.'VERSION')));
		foreach (array(APPBASE.config::get('models_path', 'models'), BASE.'models') as $root) {
			foreach (glob($root.'/*', GLOB_ONLYDIR) ?: array() as $dir) {
				$folder = basename($dir);
				$file = "$dir/$folder.php";
				if (!is_file($file)) continue;
				$stamp[] = $file.' '.filemtime($file).' '.filesize($file);
				$names[strpos($folder, 'the_') === 0 ? substr($folder, 4) : $folder] = true;
			}
		}
		$key = sha1(implode("\n", $stamp));
		self::$declared = controller::cached('models', $key);
		if (self::$declared !== null) return self::$declared;
		$models = array();
		foreach (array_keys($names) as $name) {
			$found = controller::model($name);
			if (!$found) continue;
			$source = file_get_contents($found['file']).($found['parent'] ? file_get_contents($found['parent']) : '');
			$declares = array();
			foreach (array('types', 'schema', 'api', 'requires') as $hook) $declares[$hook] = strpos($source, "function $hook(") !== false;
			$listens = array();
			if (strpos($source, 'function listens(') !== false) {
				$class = controller::model_class($name);
				if ($class && method_exists($class, 'listens')) $listens = (array)call_user_func(array($class, 'listens'));
			}
			$models[$name] = $found + array('declares' => $declares, 'listens' => $listens);
		}
		return self::$declared = controller::cache('models', $key, $models);
	}

	// does a view of any theme name the model (render.<name>.,
	// print.<name>., print.@attr.<name>., or the /*- -*/ form)? A bundled
	// model's types count only then. Kept in models.json while no view changes.
	static function uses($name) {
		if (self::$used === null) {
			$root = APPBASE.config::get('views_path', 'views');
			$views = array();
			if (is_dir($root)) {
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
					if (preg_match('/(\.html|\.rss|\.atom|\.xml|\.json|\.txt|'.preg_quote(config::get('views_ext', '.html'), '/').')$/', $file->getFilename())) $views[$file->getPathname()] = $file->getMTime().' '.$file->getSize();
				}
			}
			$key = sha1(json_encode($views));
			self::$used = controller::cached('uses', $key);
			if (self::$used === null) {
				$used = array();
				foreach (array_keys($views) as $file) {
					preg_match_all('/(?:<!--|\/\*-)\s*(?:render|print)\.(?:[@+][A-Za-z0-9_\-:]+\.)?([a-z0-9_]+)\./', (string)file_get_contents($file), $m);
					foreach ($m[1] as $model) $used[$model] = true;
				}
				self::$used = controller::cache('uses', $key, array_keys($used));
			}
		}
		return in_array((string)$name, self::$used, true);
	}

	// what the models in use need, merged: requires()[$key] of every model
	// that has the hook, a bundled one only where a view uses it (as for
	// types()). 'pages' and 'settings' are lists of names; 'emails' is
	// region => the _email views it sends. requires() may read config, so
	// it is asked each time, never kept in models.json:
	//   controller::required('pages')  // array('login', 'register', …)
	static function required($key) {
		$list = array();
		$map = array();
		foreach (controller::models() as $name => $found) {
			if (empty($found['declares']['requires'])) continue;
			$own = !$found['bundled'] || ($found['parent'] && strpos((string)file_get_contents($found['file']), 'function requires(') !== false);
			if (!$own && !controller::uses($name)) continue;
			try {
				$class = controller::model_class($name);
				if (!$class || !method_exists($class, 'requires') || !(new ReflectionMethod($class, 'requires'))->isStatic()) continue;
				$needs = call_user_func(array($class, 'requires'));
			} catch (Throwable $e) {
				log::warning("$name::requires(): ".$e->getMessage());
				continue;
			}
			if (!is_array($needs) || !isset($needs[$key])) continue;
			foreach ((array)$needs[$key] as $k => $value) {
				if (!is_int($k)) $map[$k] = array_values(array_unique(array_merge(isset($map[$k]) ? $map[$k] : array(), (array)$value)));
				elseif (!in_array($value, $list, true)) $list[] = $value;
			}
		}
		return $map ?: $list;
	}

	// what the lookups found is kept for the request; the tests change models
	// in the middle of one
	static function forget_models() {
		self::$found = array();
		self::$declared = null;
		self::$used = null;
	}

	// a part of data/cache/models.json, when it was made under the same key
	protected static function cached($part, $key) {
		$saved = json_decode((string)@file_get_contents(raster_cache::dir().'models.json'), true);
		return isset($saved[$part]['key'], $saved[$part]['value']) && $saved[$part]['key'] === $key && is_array($saved[$part]['value']) ? $saved[$part]['value'] : null;
	}

	// keeps it; on a disk Raster can't write to, the next request looks again
	protected static function cache($part, $key, $value) {
		$file = raster_cache::dir().'models.json';
		$saved = json_decode((string)@file_get_contents($file), true);
		if (!is_array($saved)) $saved = array();
		$saved[$part] = array('key' => $key, 'value' => $value);
		raster_cache::write($file, json_encode($saved));
		return $value;
	}

	// The respond method is attached to the launch event and its main role
	// is to look up the current url and find a matching view
	function respond() {

		// /news.rss renders news.rss, /api.json renders api.json: the extension
		// picks the format and the view file
		$segments = config::get('uri_segments');
		$last = end($segments);
		$format = 'html';
		$format_ext = null;
		if (preg_match('/^(.+)\.(rss|atom|xml|json|txt)$/', (string)$last, $m)) {
			$format_ext = '.'.$m[2];
			$format = in_array($m[2], array('rss', 'atom', 'xml')) ? 'xml' : $m[2];
			$segments[count($segments) - 1] = $m[1];
		}
		config::set('format')->to($format);

		// a cached copy of the page, when there is a fresh one
		raster_cache::serve();

		// posted forms must come from this site (/api included; /mcp has its own token)
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && $segments[0] !== 'mcp') controller::guard_post();

		// this event allows work to be done before the route is found
		event::dispatch('finding_route');

		$route = '';
		$template = '';
		
		// loading the routes config which is in system/config by default
		// but you can override it easily with a file in application/config
		config::load('routes');
		
		$controller = controller::instance();

		// the default view to load matches the url exactly
		// so a request to index.php/products will load views/products.html
		// while a request to index.php/products/car will load views/products/car.html
		// this is by deafault but can be overridden with routes
		$default_view = implode('/', $segments);
		$default_file = controller::build_view_path($default_view, $format_ext);
		
		// set the route to the default; views can't be requested with
		// relative paths, and partials (files or folders starting with _,
		// like _layout.html or _email/) are never pages
		// /./staff, /../x or /.env: no page has a segment starting with a dot,
		// so these are 404s, whatever the routes say
		$dotted = (bool)preg_match('#(^|/)\.#', implode('/', $segments));
		$is_safe = $default_view !== '' && !$dotted
			&& !controller::is_partial($default_view);
		$view = '';
		if($is_safe && file_exists($default_file)) {
			$route = $default_file;
			$view = $default_view;
		}
		
		// then we look up routes to see if the author specifically requested
		// a different view trough controller::route( 'url/param' )->to( 'view' );
		foreach ($dotted ? array() : $this->routes as $url=>$file)
		{
			// the $forced_route is when we want to emulate a different url
			// than the one found by the controller in $_SERVER
			if($this->forced_route == $url)
			{
				$template = $file;
				break;
			}
			
			// routes are regular expressions anchored at the start of the path
			// so 'blog' matches /blog and /blog/post/1 but not /my-blog
			if(preg_match("%^/?".$url."%", config::get('uri_string'))) {

				if(isset($this->changed_themes[$url])) {
					config::set('theme')->to($this->changed_themes[$url]);
				}

				$template = $file;
				break;
			}
		}
		
		// the route is the filesystem address to the view
		if($template != '') {
			$route = controller::build_view_path($template);
			$view = $template;
		}

		// index route
		if (implode('/', config::get('uri_segments')) == '') {
			$route = controller::build_view_path(config::get('default_view'));
			$view = config::get('default_view');
		}


		// if no file exists for neither default or manual route
		// the default view is loaded
		if($template == '' && $route == '') {
			event::dispatch('route_not_found');
			$route = controller::error('404');
			$view = '';
		}

		// obvious right?
		$this->current_route = $route;
		$this->current_view = $view;
		// print.if.live shows what needs PHP behind it (forms), print.if.static
		// what a static export shows instead
		$static = (bool)getenv('RASTER_EXPORT');
		template::set('live')->to(!$static);
		template::set('static')->to($static);
		event::dispatch('route_set');

		// a 404 without error_document_404, printed once the session has
		// started (route_set), so PHP has nothing to warn about
		if (!$route) {
			exit('<h1>404 Not Found</h1>');
		}
		
		// just a hook
		event::dispatch('route_found');

		return $this;
	}

	// Did another site start this request? The browser says so with the
	// Origin header (`null` when it hides where the request came from:
	// a sandboxed frame, a page with Referrer-Policy: no-referrer, unless
	// Sec-Fetch-Site says it is this site), Sec-Fetch-Site or Referer. A
	// server calling (a webhook) sends none of them, so it is not foreign.
	static function is_foreign() {
		$host = strtolower((string)config::get('host'));
		$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : null;
		$site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : null;
		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
		$host_of = function ($url) {
			$parts = parse_url($url);
			return isset($parts['host']) ? strtolower($parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')) : '';
		};
		if ($origin === 'null') return !in_array($site, array('same-origin', 'none'), true);
		if ($origin !== null) return $host_of($origin) !== $host;
		if ($site !== null) return !in_array($site, array('same-origin', 'none'), true);
		if ($referer !== null) return $host_of($referer) !== $host;
		return false;
	}

	// Rejects posts that come from another site (controller::is_foreign()),
	// posts from bots that filled the honeypot, and posts from logged in
	// users without the token.
	static function guard_post() {
		if (controller::is_foreign()) {
			http_response_code(403);
			exit('Forbidden: this form was sent from another site.');
		}
		if (isset($_POST['raster_hp']) && $_POST['raster_hp'] !== '') {
			// pretend it worked so the bot moves on
			header('Location: '.config::get('link_uri').ltrim((string)config::get('uri_string'), '/'), true, 303);
			exit;
		}
		util::session();
		if (!empty($_SESSION['uid']) && !util::csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
			http_response_code(403);
			exit('This form has expired. Go back, reload the page and send it again.');
		}
	}

	// Renders a view into a string without touching the page being built,
	// used for emails. $vars are available as print.self.name.
	static function render_view($view, $vars = array(), $format = 'html') {
		$path = file_exists($view) ? $view : controller::build_view_path($view);
		if (!file_exists($path)) throw new RuntimeException("View not found: $view");
		$previous = template::swap(null);
		try {
			$template = template::instance();
			$template->view_file = raster_path($path);
			$template->views_path = boot::$appname.DIRECTORY_SEPARATOR.config::get('views_path');
			$template->theme = config::get('theme');
			$template->view_ext = config::get('views_ext');
			$template->base_uri = config::get('base_uri');
			$template->link_uri = config::get('link_uri');
			$template->format = $format;
			$template->is_email = true;
			foreach ($vars as $name => $value) $template->vars[$name] = $value;
			controller::instance()->render($template, file_get_contents($path));
			$output = template::instance()->output;
			// relative images and links become absolute, relative to the theme
			$base = config::get('base_uri').trim(boot::$appname.'/'.config::get('views_path'), '/').'/'.config::get('theme').'/';
			return preg_replace_callback('/\b(src|href)=(["\'])(?!https?:|mailto:|tel:|#|\/\/|data:)([^"\']*)\2/i', function ($m) use ($base) {
				return $m[1].'='.$m[2].$base.ltrim($m[3], './').$m[2];
			}, $output);
		} finally {
			template::swap($previous);
		}
	}

	// Sets the status and gives the view for it (config error_document_404,
	// error_document_503), or false when the site has none. It prints
	// nothing: the session may not have started yet.
	public static function error($error, $document = false) {
		if (!headers_sent()) {
			http_response_code((int)$error);
		}
		if (!$document) {
			$document = config::get('error_document_'.$error, false);
		}
		return $document ? controller::build_view_path($document) : false;
	}

	// The database can't be reached (cms::setup asks). Outside development
	// the page answers 503, as the view error_document_503 when the site has
	// one, and nothing is cached, so the real page shows as soon as the
	// database is back. Development says what is wrong.
	static function unavailable($reason) {
		log::error('the database can\'t be reached: '.$reason);
		raster_cache::$cacheable = false;
		if (config::get('environment') === 'development') {
			if (!headers_sent()) http_response_code(500);
			exit("<!doctype html><meta charset='utf-8'><title>No database</title><p>The database can't be reached: ".htmlspecialchars($reason)."</p>");
		}
		$document = controller::error('503');
		// the view is a page: feeds and data views (news.rss) get the plain line
		if ($document && file_exists($document) && config::get('format', 'html') === 'html') {
			$controller = controller::instance();
			$controller->current_route = $document;
			$controller->handle_response();
			exit;
		}
		echo "<!doctype html><meta charset='utf-8'><title>Unavailable</title><p>This site is unavailable for a moment. Try again shortly.</p>";
		exit;
	}
	
	// internal method of the controller used in handle_response
	private function call_method($object, $method) {

		
		if (!is_object($object)) return false;
		// the model's own name, also when a the_<model> override answers
		$model = preg_replace('/^the_/', '', get_class($object));

		// methods can take literal arguments: render.news.latest(3, 'sports')
		// they are parsed, never eval()-ed
		$call = template::parse_call($method);
		if ($call === false) {
			throw new RuntimeException("Malformed tag at ".$model.'.'.$method);
		}
		list($name, $arguments) = $call;
		// executing_news_latest, whatever the arguments (and validation.field
		// inside form 2, which runs as field__f2, is still executing_validation_field)
		$event_name = $model.'_'.preg_replace('/__f\d+$/', '', $name);
		event::dispatch('executing_'.$event_name, array('arguments' => $arguments));

		if(!is_callable(array($object, $name))) return false;

		// named queries without a model name are the model's own while it runs,
		// and the caller's again after (a model called from inside another)
		$db = null;
		if (class_exists('database', false) && database::configured()) {
			$db = database::instance(  );
			$previous = $db->current_model;
			$db->current_model = $model;
		}
		try {
			$data = call_user_func_array(array($object, $name), $arguments);
		} finally {
			if ($db) $db->current_model = $previous;
		}
		
		event::dispatch('executed_'.$event_name, array('arguments' => $arguments, 'result' => $data));
		
		return $data;
	}
	
	public function handle_response() {
		
		$data = file_get_contents($this->current_route);

		// In development a broken template stops with a list of what is
		// wrong instead of rendering half a page (config strict_templates)
		$strict = config::get('strict_templates', config::get('environment') === 'development');
		if ($strict) {
			require_once BASE.'tools/inspector.php';
			$inspector = new raster_inspector();
			$problems = $inspector->lint_path($this->current_route);
			// and the partials it includes with dry
			preg_match_all('/<!-- dry\.([a-z0-9_\-\/]+)\.[a-z0-9_\-]+ \/?-->/', $data, $dried);
			foreach (array_unique($dried[1]) as $partial) {
				$path = controller::build_view_path($partial);
				if (strpos($partial, '..') === false && file_exists($path)) $problems = array_merge($problems, $inspector->lint_path($path));
			}
			$errors = array_filter($problems, function ($p) { return $p['severity'] === 'error'; });
			if ($errors) controller::template_error($errors);
		}
		
		$template = template::instance();
		$template->view_file = raster_path($this->current_route);
		$template->format = config::get('format', 'html');
		$types = array('html' => 'text/html', 'xml' => preg_match('/\.rss$/', $this->current_route) ? 'application/rss+xml' : 'application/xml', 'json' => 'application/json', 'txt' => 'text/plain');
		if (!headers_sent()) header('Content-Type: '.$types[$template->format].'; charset=utf-8');
		
		$template::set('views_path')->to(boot::$appname.DIRECTORY_SEPARATOR.config::get('views_path'));
		$template::set('theme')->to(config::get('theme'));
		$template::set('view_ext')->to(config::get('views_ext'));
		$template::set('base_uri')->to(config::get('base_uri'));
		$template::set('link_uri')->to(config::get('link_uri'));
		
		try {
			$this->render($template, $data);
		} catch (Throwable $e) {
			if (!$strict) {
				// production: log it and show a plain error, never the details,
				// whatever was thrown (a missing named query is a LogicException)
				error_log('Raster error: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
				if (!headers_sent()) {
					http_response_code(500);
					header('Content-Type: text/html; charset=utf-8');
				}
				echo "<!doctype html><meta charset='utf-8'><title>Error</title><p>This page could not be shown.</p>";
				exit;
			}
			// development: PHP's own error, with the trace, for anything but a template error
			if (!($e instanceof RuntimeException)) throw $e;
			controller::template_error(array(array('file' => $template->view_file, 'line' => 0, 'column' => 0, 'severity' => 'error', 'message' => $e->getMessage())));
		}

		event::dispatch('done');
		
		return $this;
	}

	// shows template errors in place of the page (development only)
	static function template_error($problems) {
		if (!headers_sent()) {
			http_response_code(500);
			header('Content-Type: text/html; charset=utf-8');
			header('X-Raster-Template-Errors: '.count($problems));
		}
		echo "<!doctype html><meta charset='utf-8'><title>Template errors</title>";
		echo "<body style='font:15px/1.5 ui-monospace,monospace;padding:24px;max-width:960px;margin:auto'>";
		echo "<h1 style='font:600 20px system-ui'>This view has template errors</h1><pre style='white-space:pre-wrap'>";
		foreach ($problems as $p) {
			echo htmlspecialchars($p['file'].($p['line'] ? ':'.$p['line'].':'.$p['column'] : '').': '.$p['message'])."\n";
		}
		echo "</pre><p>Run <code>php bin/raster lint</code> to check every view.</p></body>";
		exit;
	}

	public function render($template, $data) {
		$template = template::parse($data);

		foreach($template->models as $model) {
			controller::load_model($model);
		}
		
		foreach($template->models_methods_render as $action) {
			$model = $action[0];
			$method = $action[1];

			$template->set_current_block($model, $method, 'render');

			$object = controller::get_object($model);
			$data = $this->call_method($object, $method);
			
			event::dispatch("before_render");
			$template->_render($data, $model, $method);
			event::dispatch("after_render");
		}
		
		foreach($template->models_methods_print as $action) {
			$model = $action[0];
			$method = $action[1];
			$attr = isset($action[2]) ? $action[2] : null;
			
			$template->set_current_block($model, $method, 'print', $attr);
			if ($template->current_params['pos1'] === false) continue;

			$object = controller::get_object($model);
			$data = $this->call_method($object, $method);

			event::dispatch("before_print");
			// a print inside a repeated render block has one copy per row:
			// this fills them all with the same value
			$template->_print($data, $model, $method);
			event::dispatch("after_print");
		}
		
		$this->fix_links();
	}
	
	protected function fix_links() {
		$template = template::instance();
		// links to the default view go to the site root
		$template->output = preg_replace("/(href|action)=(\"|')".preg_quote(config::get('default_view'), '/')."\.html(\"|')/", '$1=$2'.template::get('link_uri').'$3', $template->output);
		$template->output = preg_replace("/(href|action|src)=(\"|')([a-zA-Z0-9\-\._\?\,\'\/\\\+&amp;%\$#\=~]*)\?".template::get('tpl_uri')."=(.*?)(\"|')/", '$1="'.template::get('link_uri').'$4"', $template->output);
		$template->output = preg_replace("/(href|action|src)=(\"|')([a-zA-Z0-9\-\._\?\,\'\/\\\+&amp;%\$#\=~]*)\.html/", '$1=$2'.template::get('link_uri').'$3', $template->output);
		// links to feed and data views: href="news.rss" -> /news.rss
		$template->output = preg_replace_callback('/(href)=(["\'])([a-zA-Z0-9\-_\/]+\.(rss|atom|xml|json|txt))\2/', function ($m) {
			return file_exists(controller::build_view_path(substr($m[3], 0, -strlen($m[4]) - 1), '.'.$m[4])) ? $m[1].'='.$m[2].template::get('link_uri').$m[3].$m[2] : $m[0];
		}, $template->output);
		$template->output = str_replace(template::get('link_uri')."__", template::get('link_uri').template::get('pad_uri'), $template->output);
		return $template;
	} 
	
	static function get_object($model) {
		$controller = controller::instance();
		return isset($controller->objects[$model]) ? $controller->objects[$model] : null;
	}
	
	static function load_model($model) {
		
		$controller = controller::instance();
		$controller->loading_model = $model;
		$continue_loading = event::dispatch('loading_model_'.$model);
		
		if(array_key_exists($model, (array)$controller->objects)) return true;
		
		if(!$continue_loading) return false;
		if($model == 'session') return true;
		if($model == 'if') return true;
		if($model == 'self') return true;
		
		$class = controller::model_class($model);
		if (!$class) return false;
		$controller->objects[$model] = new $class();
		
		return true;
		
	}
	

	public static function route($uri)
	{
		$controller = controller::instance();
		$controller->current_config_route = $uri;
		return $controller;		
	}
	
	public function to($template)
	{
		$this->routes[$this->current_config_route] = $template;
		return $this;
	}

	public function from($theme)
	{
		$this->changed_themes[$this->current_config_route] = $theme;
	}
	
	public function output() {
		$template = template::instance();
		// last chance to change the page, e.g. the CMS adds its toolbar here
		event::dispatch('before_output');
		// markup a listener just put in has not been through fix_links: an alert
		// block is only a placeholder while render runs, so a relative link in one
		// would still point at the view instead of the route
		$this->fix_links();
		raster_cache::store($template->output);
		echo $template->output;
		event::dispatch('land');
	}
	
}