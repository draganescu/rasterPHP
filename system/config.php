<?php
// #Config class

// The config class is a singleton, like all the other classes
class config {

	// this is the normal singleton boilerplate
	// we do not have a base class because ... well not sure exactly why.
	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}
    
    // these are mainly defaults and we define them here 
    // so that we can set them later on to a new value
    public $rewrite = false;
    protected $config = array();
    static $extension = '.php';
    
    // given a parameter
    static function set($varname) {
    	$config = config::instance();
    	$config->$varname = '';
    	$config->$current_assignment = $varname;
    	return $config;
    }
    
    public function to($value) {
    	$varname = $this->$current_assignment; 
    	$this->$varname = $value;
    	return $this;
    }
    
    static function get_application_config() {
		$files = scandir(APPBASE.'config/');
		$array = array(); 
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
				$app_config_files[] = $file;
			}
		}		
		return $app_config_files;
	}
	
	static function load($file) {
		if(file_exists(APPBASE.'config/'.$file.config::$extension)) {
			require_once APPBASE.'config/'.$file.config::$extension;
			return true;
		} else { 
			return false;
		}
	}
	
	function reset() {
		$application_config_files = config::get_application_config();
    	foreach ($application_config_files as $file) {
			require_once APPBASE.'config/'.$file;
    	}
    	return true;
	}
    
    static function initialize() {
    
    	$config = config::instance();
    	
    	$request_uri = config::request_uri();
    	if($config->host == '') $config->host = $_SERVER["HTTP_HOST"];
    	
    	preg_match("|([a-z,A-Z,_,\.]*)\.php|", $_SERVER["SCRIPT_NAME"], $matches);
    	$config->index_file = $matches[0];
    	
		$folder_path = str_replace($config->index_file, '', $_SERVER["SCRIPT_NAME"]);
		$config->folder_path = $folder_path;
		
	    if($folder_path != '/') {
	  		$request_uri = str_replace($folder_path, '', $request_uri);
		}
		
    	if ($config->protocol == '') {
			$config->protocol = 'http' . ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 's' : '');
    	}
		
		if ($config->base_uri == '') {
			$config->base_uri = $config->protocol.'://'.$config->host . $folder_path;
		} else {
			$config->base_uri = $config->protocol.'://'.$config->base_uri;
		}
		
		if ($config->rewrite == true) {
			$config->link_uri = $config->base_uri;
		} else {
			$config->link_uri = $config->base_uri.$config->index_file."/";
		}
		
		$path_info = str_replace($config->index_file.'/', '', $request_uri);

		$config->uri_segments = explode("/",$path_info);
		array_shift($config->uri_segments);
		
		$config->uri_string = $request_uri;

		$config->set_environment(  );
		
		return $config;
	}

	private function set_environment(  ) {
		$this->environment = 'development';
		if( !file_exists(APPBASE.'config/servers.php') ) {
			return $this;
		}
		require_once APPBASE.'config/servers.php';
		
		$this->servers = $servers;

		foreach ((array)$servers as $key => $environment) {
			if(preg_match("|".$key."|", $this->base_uri)) {
				$this->environment = $environment;
			}
		}
		return $this;
	}
	
	static function request_uri() {
		if (isset($_SERVER['REQUEST_URI'])) {
			$uri = $_SERVER['REQUEST_URI'];
		}
		else {
			if (isset($_SERVER['argv'])) {
				$uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['argv'][0];
			}
			elseif (isset($_SERVER['QUERY_STRING'])) {
				$uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['QUERY_STRING'];
			}
			else {
				$uri = $_SERVER['SCRIPT_NAME'];
			}
		}
		$uri = '/' . ltrim($uri, '/');
		
		return $uri;
	}
	
	static function get($varname) {
		$config = config::instance();
		return $config->$varname;
	}
	
	public function __set($name, $value) {
		$this->config[$name] = $value;
	}

	public function __get($name) {
		if (array_key_exists($name, $this->config)) {
			return $this->config[$name];
		}
		return null;
	}
	
    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }

}

// Next source to read: ```/system/config/events.php```