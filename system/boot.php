<?php
// #Boot class

// This class handles the bootstrapping by loading needed configuration files,
// system classes and dispatches the first event ```launch``` which triggers 
// application execution
class boot {
	
	// The name of the application is also the name of the folder
	// where the application files are located. For instance, if ```$appname```,
	// which is defined in the index file as ```boot::appname = 'xxxx'``` is say basecamp then
	// the application folder will be ```/basecamp/```
	static $appname;
	
	// ##Initialization
	// The application does the following bootstrap sequence:
	static function up()
	{
		// where it is on the file system to reference its files properly
		boot::file_system_setup();
		// loads and instanced the main system classes (/system/*.php)
		boot::load_core_files();
		// loads default configuration (can be easily overriden)
		boot::load_core_config();
		// configuration initialization sets up the current used hostname,
		// determines the index file and the rewrite procedure
		// and figures out if its development or production or whatever
		config::initialize();
		boot::autoload_models();
		// listeners models declare in static function listens()
		event::discover();
		// the first event (hook) that our system launches
		event::dispatch('launch');
	}
	
	// ##CLI initialization
	// Same as up() but stops before dispatching ```launch```. The raster
	// command line tool (bin/raster) and the tests use this to get a fully
	// configured framework (config, database, models) without a request.
	static function cli($uri = '/')
	{
		// RASTER_URL tells the command line where the site lives, so links
		// it prints (and the MCP base_url) are right
		$url = parse_url(getenv('RASTER_URL') ?: 'http://localhost:8000/');
		$_SERVER['HTTP_HOST'] = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
		if (isset($url['scheme']) && $url['scheme'] === 'https') $_SERVER['HTTPS'] = 'on';
		$_SERVER['SCRIPT_NAME'] = rtrim(isset($url['path']) ? $url['path'] : '', '/').'/index.php';
		$_SERVER['REQUEST_URI'] = $uri;
		boot::file_system_setup();
		boot::load_core_files();
		boot::load_core_config();
		config::initialize();
		boot::autoload_models();
		event::discover();
	}

	// Model classes load on first use, so models can call each other
	// directly: mail::send_view(...), validation::get()
	static function autoload_models() {
		spl_autoload_register(function ($class) {
			if (!preg_match('/^[a-z][a-z0-9_]*$/', $class)) return;
			$paths = controller::build_model_paths($class);
			foreach (array('app_path', 'system_path') as $key) {
				if (file_exists($paths[$key])) {
					require_once $paths[$key];
					return;
				}
			}
			// helper classes live next to their model: cms_store in models/cms/store.php
			if (preg_match('/^([a-z0-9]+)_([a-z0-9_]+)$/', $class, $m)) {
				foreach (array(APPBASE, BASE) as $root) {
					$file = $root.config::get('models_path').'/'.$m[1].'/'.$m[2].'.php';
					if (file_exists($file)) {
						require_once $file;
						return;
					}
				}
			}
		});
	}

	static function file_system_setup() {
		if (defined('BASE')) return;
		$current_directory = __DIR__;
		define('APPBASE', realpath($current_directory.'/../'.self::$appname).'/');
		define('BASE', $current_directory.'/');
	}
	
	static function load_core_files() {
		$core_files = self::get_core_files();
		foreach ($core_files as $file) {
			$status = self::check_core_file_status($file);
			if($status == 0) {
				require_once BASE.$file;
			} else if ($status == 1) {
				require_once BASE.$file;
				require_once APPBASE.'the_'.$file;
			} else if ($status == 2) {
				require_once APPBASE.$file;
			}
		}
	}
	
	static function load_core_config() {
		$core_files = self::get_core_config();
		foreach ($core_files as $file) {
			$status = self::check_core_file_status($file, 'config/');
			if($status == 1) {
				require_once BASE.'config/'.$file;
				require_once APPBASE.'config/the_'.$file;
			} else {
				require_once BASE.'config/'.$file;
			}
		}
	}
	
	static function check_core_file_status($file, $folder = '') {
		$status = 0;
		if(file_exists(APPBASE.$folder.'the_'.$file)) $status = 1;
		return $status;
	}
	
	static function get_core_files() {
		$files = scandir(BASE);
		$core_files = array();
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
				$core_files[] = $file;
			}
		}		
		return $core_files;
	}
	
	static function get_core_config() {
		$files = scandir(BASE.'config/');
		$core_config_files = array();
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
				$core_config_files[] = $file;
			}
		}		
		return $core_config_files;
	}
	
}

// Next source to read: ```/system/config.php```