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
		return APPBASE . config::get('views_path') . DIRECTORY_SEPARATOR .
						config::get('theme') . DIRECTORY_SEPARATOR .
						$view . config::get('views_ext');
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
		$default_file = controller::build_view_path(implode('/', config::get('uri_segments')));
		
		// set the route to the default
		if(file_exists($default_file)) {
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
			
			if(preg_match("%".$url."%", config::get('uri_string'))) {

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
		
		// if no file exists for neither default or manual route
		// the default view is loaded
		if($template == '' && $route == '') {
			event::dispatch('route not found');
			$route = controller::build_view_path(config::get('default_view'));
		}
		
		// obvious right?
		$this->current_route = $route;
		
		// just a hook
		event::dispatch('route_found');

		return $this;
	}
	
	// internal method of the controller used in handle_response
	private function call_method($object, $method) {

		
		$model = get_class( $object );
		$test = explode("(", $method);
		event::dispatch('executing_'.$model."_".$method);
		
		if(!is_callable(array($object, $test[0]))) return false;
		
		$db = database::instance(  );
		$db->current_model = $model;

		if(strpos($method, "(") === false)
			$data = $object->$method();
		else
			if(@eval('$data = $object->'.$method.';') === false)
				exit("Malformed tag at ".htmlentities($model.'.'.$method)." !");
		
		event::dispatch('executed_'.$model."_".$method);
		
		return $data;
	}
	
	public function handle_response() {
		
		$data = file_get_contents($this->current_route);
		
		$template = template::instance();
		
		$template::set('views_path')->to(boot::$appname.DIRECTORY_SEPARATOR.config::get('views_path'));
		$template::set('theme')->to(config::get('theme'));
		$template::set('view_ext')->to(config::get('views_ext'));
		$template::set('base_uri')->to(config::get('base_uri'));
		$template::set('link_uri')->to(config::get('link_uri'));
		
		$template = template::parse($data);

		foreach($template->models as $model) {
			controller::load_model($model);
		}
		
		foreach($template->models_methods_render as $action) {
			$model = $action[0];
			$method = $action[1];

			$object = controller::get_object($model);
			$data = $this->call_method($object, $method);
			
			event::dispatch("before_render");
			$template->_render($data, $model, $method);
			event::dispatch("after_render");
		}
		
		foreach($template->models_methods_print as $action) {
			$model = $action[0];
			$method = $action[1];
			
			$object = controller::get_object($model);
			$data = $this->call_method($object, $method);
			
			event::dispatch("before_print");
			$template->_print($data, $model, $method);
			event::dispatch("after_print");
		}
		
		$this->fix_links();
				
		event::dispatch('done');
		
		return $this;
		
	}
	
	protected function fix_links() {
		$template = template::instance();
		$template->output = preg_replace("/(href|action|src)=(\"|')([a-zA-Z0-9\-\._\?\,\'\/\\\+&amp;%\$#\=~]*)\?".template::get('tpl_uri')."=(.*?)(\"|')/", '$1="'.template::get('link_uri').'$4"', $template->output);
		$template->output = str_replace(template::get('link_uri')."__", template::get('link_uri').template::get('pad_uri'), $template->output);
		return $template;
	} 
	
	static function get_object($model) {
		$controller = controller::instance();
		return $controller->objects[$model];
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
			require_once $model_path;
			$model_path = $possible_paths['extended_path'];
			$model = 'the_'.$model;
		}
		if( file_exists($possible_paths['app_path']) ) {
			$model_path = $possible_paths['app_path'];
		}

		if(is_null($model_path)) return false;
		
		require_once $model_path;
		$object = new $model();
		$controller->objects[$base_model] = $object;
		
		return true;
		
	}
	

	public function route($uri)
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
		echo $template->output;
		event::dispatch('land');
	}
	
}