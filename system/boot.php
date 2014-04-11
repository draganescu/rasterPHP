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
	static function up()
	{
		boot::file_system_setup();
		boot::load_core_files();
		boot::load_core_config();
		config::initialize();
		event::dispatch('launch');
	}
	
	static function file_system_setup() {
		$current_directory = explode(DIRECTORY_SEPARATOR, __FILE__);
		unset($current_directory[count($current_directory)-1]);
		$current_directory = implode('/', $current_directory);
		
		define('APPBASE', $current_directory.'/../'.self::$appname.'/');
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
		$array = array(); 
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
				$core_files[] = $file;
			}
		}		
		return $core_files;
	}
	
	static function get_core_config() {
		$files = scandir(BASE.'config/'); 
		$array = array(); 
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
				$core_config_files[] = $file;
			}
		}		
		return $core_config_files;
	}
	
}

// Next source to read: ```/system/config.php```