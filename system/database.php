<?php
class database {
	
    public $current_model = null;

	private static $instances = array();
    protected function __construct() {
    	require BASE.'libraries/rb.php';
    	database::setup(  );
    }
    protected function __clone() {}

    public static function instance(  )
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }

    public function __call($name, $arguments) {
        $sqlfile = APPBASE.'models/'.$this->current_model.'/sql/'.$name.'.sql';

        if(file_exists($sqlfile))
        {
            return $this->query(file_get_contents($sqlfile), $arguments);
        } else {
            return false;
        }

        return $this;
    }

    public function query(  ) {
        $args = func_get_args();

        if (count($args) < 2)
        {
            $args = $args[0];
        }
        else
        {
            $query = array_shift($args);
            if($this->escape === true)
                $args = array_map('mysql_escape_string', $args[0]);
            else
                $args = $args[0];
            array_unshift($args, $query);
        }
        
        $query = call_user_func_array('sprintf', $args);
        return R::getAll( $query );
    }

    public static function setup(  ) {
    	$active_connections = array(  );
    	$db_config_files = database::get_db_config(  );

    	if( count( $db_config_files ) == 0 ) return false;

    	foreach ($db_config_files as $file) {
    		require_once APPBASE.'config/db/'.$file;
    		$key = basename($file, ".php");
            if( $active )
        		R::addDatabase($key,$dsn,$user,$password,$frozen);
            if( $active )
                $active_connections[  ] = $key;
    	}

    	$env = config::get( 'environment' );

        if( !in_array($env, $active_connections) ) return false;
        
		R::selectDatabase($env);
    	return true;
    }

    static function get_db_config() {
    	$db_config_files = array(  );
		$files = scandir(APPBASE.'config/db/'); 
		foreach($files as $file) {
		    if(!is_dir(BASE.$file.'/') && strpos($file,'.php') !== false) {
            	$db_config_files[] = $file;
			}
		}		
		return $db_config_files;
	}


}