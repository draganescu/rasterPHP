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
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }
	
	// So, the views can be taken out of the application's directory for
	// -paranoid- sorry security reasons. This is why you can 
	// config::set('views_path')->to('hidden_dir_relative_to_'.APPBASE) 
	// and APPBASE is always relative to system.
	static function build_view_path($view)
	{
		$view_file = config::get('theme') . DIRECTORY_SEPARATOR .
						$view . config::get('views_ext');
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

	// The respond method is attached to the launch event and its main role
	// is to look up the current url and find a matching view
	function respond() {
		
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
		$default_view = implode('/', config::get('uri_segments'));
		$default_file = controller::build_view_path($default_view);
		
		// set the route to the default; views can't be requested with
		// relative paths and partials (files starting with _) are never pages
		$is_safe = $default_view !== '' && strpos($default_view, '..') === false
			&& strpos(basename($default_view), '_') !== 0;
		if($is_safe && file_exists($default_file)) {
			$route = $default_file;
		}
		
		// then we look up routes to see if the author specifically requested
		// a different view trough controller::route( 'url/param' )->to( 'view' );
		foreach ($this->routes as $url=>$file)
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
		if($template != '') 
			$route = controller::build_view_path($template);

		// index route
		if (implode('/', config::get('uri_segments')) == '') {
			$route = controller::build_view_path(config::get('default_view'));
		}


		// if no file exists for neither default or manual route
		// the default view is loaded
		if($template == '' && $route == '') {
			event::dispatch('route_not_found');
			$route = controller::error('404');
		}

		// obvious right?
		$this->current_route = $route;
		event::dispatch('route_set');

		if (!$route) {
			exit;
		}
		
		// just a hook
		event::dispatch('route_found');

		return $this;
	}

	public static function error($error, $document = false) {
		if ($error == '404' && !headers_sent()) {
			http_response_code(404);
		}
		if (!$document) {
			$document = config::get('error_document_'.$error, false);
		}
		if ($document) {
			$document = controller::build_view_path($document);
		} else {
			echo "<h1>404 Not Found</h1>";
		}
		return $document;
	}
	
	// internal method of the controller used in handle_response
	private function call_method($object, $method) {

		
		if (!is_object($object)) return false;
		$model = get_class( $object );
		event::dispatch('executing_'.$model."_".$method);

		// methods can take literal arguments: render.news.latest(3, 'sports')
		// they are parsed, never eval()-ed
		$call = template::parse_call($method);
		if ($call === false) {
			throw new RuntimeException("Malformed tag at ".$model.'.'.$method);
		}
		list($name, $arguments) = $call;

		if(!is_callable(array($object, $name))) return false;

		if (class_exists('database', false) && database::configured()) {
			$db = database::instance(  );
			$db->current_model = $model;
		}

		$data = call_user_func_array(array($object, $name), $arguments);
		
		event::dispatch('executed_'.$model."_".$method);
		
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
				$path = template::instance()->view_path($partial);
				if (strpos($partial, '..') === false && file_exists($path)) $problems = array_merge($problems, $inspector->lint_path($path));
			}
			$errors = array_filter($problems, function ($p) { return $p['severity'] === 'error'; });
			if ($errors) controller::template_error($errors);
		}
		
		$template = template::instance();
		$template->view_file = raster_path($this->current_route);
		
		$template::set('views_path')->to(boot::$appname.DIRECTORY_SEPARATOR.config::get('views_path'));
		$template::set('theme')->to(config::get('theme'));
		$template::set('view_ext')->to(config::get('views_ext'));
		$template::set('base_uri')->to(config::get('base_uri'));
		$template::set('link_uri')->to(config::get('link_uri'));
		
		try {
			$this->render($template, $data);
		} catch (RuntimeException $e) {
			if (!$strict) throw $e;
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

	protected function render($template, $data) {
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
			
			$template->set_current_block($model, $method, 'print');

			$object = controller::get_object($model);
			$data = $this->call_method($object, $method);
			
			event::dispatch("before_print");
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
		
		$possible_paths = controller::build_model_paths($model);

		$model_path = null;
		$base_model = $model;
		if( file_exists($possible_paths['system_path']) ) {
			$model_path = $possible_paths['system_path'];
		}
		if( file_exists($possible_paths['extended_path']) ) {
			if (!is_null($model_path)) require_once $model_path;
			$model_path = $possible_paths['extended_path'];
			$model = 'the_'.$model;
		}
		if( file_exists($possible_paths['app_path']) ) {
			$model_path = $possible_paths['app_path'];
		}

		if(is_null($model_path)) return false;
		
		require_once $model_path;
		if (!class_exists($model, false)) return false;
		$object = new $model();
		$controller->objects[$base_model] = $object;
		
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
		echo $template->output;
		event::dispatch('land');
	}
	
}