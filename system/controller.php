<?php
class controller {
	
	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}
    
    protected $routes = array();
    public $forced_route = array();
    public $current_route = '';
    public $current_config_route = '';
    
    protected $models = array();

    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }
	
	static function build_view_path($view)
	{
		return APPBASE . config::get('views_path') . DIRECTORY_SEPARATOR .
						config::get('theme') . DIRECTORY_SEPARATOR .
						$view . config::get('views_ext');
	}
	
	static function build_model_paths( $model ) {
		$paths['app_path'] = APPBASE . config::get('models_path') . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . $model . '.php';
		$paths['system_path'] = BASE . config::get('models_path') . DIRECTORY_SEPARATOR . $model . DIRECTORY_SEPARATOR . $model . '.php';
		$paths['extended_path'] = APPBASE . config::get('models_path') . DIRECTORY_SEPARATOR . 'the_' . $model . DIRECTORY_SEPARATOR . 'the_' . $model . '.php';
		return $paths;
	}

	function respond() {
		
		$route = '';
		$template = '';
		config::load('routes');
		$controller = controller::instance();
		$default_file = controller::build_view_path(implode('/', config::get('uri_segments')));
		
		if(file_exists($default_file)) {
			$route = $default_file;
		}
		
		foreach ($this->routes as $url=>$file)
		{
			if($this->forced_route == $url)
			{
				$template = $file;
				break;
			}
			
			if(preg_match("%".$url."$%", config::get('uri_string'))) {
				$template = $file;
				break;
			}
		}
		
		if($template != '') 
			$route = controller::build_view_path($template);
		
		
		if($template == '' && $route == '') {
			event::dispatch('route not found');
			$route = controller::build_view_path(config::get('default_view'));
		}
		
		$this->current_route = $route;
		
		event::dispatch('route_found');

		return $this;
	}
	
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
		
		$template::set('views_path')->to(config::get('views_path'));
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
		$template->output = preg_replace("/(href|action)=(\"|')([a-zA-Z0-9\-\._\?\,\'\/\\\+&amp;%\$#\=~]*)\?".template::get('tpl_uri')."=(.*?)(\"|')/", '$1="'.template::get('link_uri').'$4"', $template->output);
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
	
	public function output() {
		$template = template::instance();
		echo $template->output;
		event::dispatch('land');
	}
	
}