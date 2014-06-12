<?php

// in Raster the database class is just a small utility that provides easier access
// to Red Bean PHP which is the default ORM for Raster
// The nicest feature is the sql externalisation to standalone files that can be called
// by name as methods of the database class (see __call below)
class database {
	
    // the current_model is a holder that we use so we know where to take
    // the sql from when overriden methods are called
    // as in $db->get_my_stuff();
    public $current_model = null;

    // singleton boilerplate
	private static $instances = array();
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

    // by default we load the Red Bean library and connect to the db when 
    // a new instance of the database singleton is called
    protected function __construct() {
        require BASE.'libraries/rb.php';
        database::setup(  );
    }

    // Raster offers a simple way to get rid of SQL text syntax in you PHP files
    // by allowing you to create sql files in the model folder and then call those
    // querries by using the name of the file as a method of the db object
    // and parameters to be placed inside the query
    // ##Example
    // - say you have a model called products
    // - inside it you make a folder named sql
    // - in that folder you make a file called get_all.sql
    // - in the sql file you'd have something like
    // ```SELECT * FROM products```
    // in your products.php model you can now do:
    // ``` $db = database::instance(); $products = $db->get_all(); ```
    public function __call($name, $arguments) {
        $sqlfile = APPBASE.'models/'.$this->current_model.'/sql/'.$name.'.sql';

        if(file_exists($sqlfile))
        {
            return $this->query(file_get_contents($sqlfile), $arguments);
        } else {
            if(file_exists(APPBASE.'models/sql.php')) {
                include APPBASE.'models/sql.php';
                if(array_key_exists($name, $querries)) {
                    return $this->query($querries[$name], $arguments);
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $this;
    }

    // This is just a helper function that runs a query trough
    // R::getAll( $query ) and has parameter the classic escaping built in
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
        log::info('Query: '. $query);
        return R::getAll( $query );
    }

    // each connection config in APPBASE.'config/db/' is parsed and if 
    // its active the R library is made aware of the new connection
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

        // Raster supports seamless deployement on multiple
        // servers which can have different tags attached such as 
        // development, local, staging, pre-production, live etc.
    	$env = config::get( 'environment' );

        if( !in_array($env, $active_connections) ) return false;
        
        // depending on what the current environment is we use R to make
        // a new connection to the DB
		R::selectDatabase($env);
    	return true;
    }

    // the database config loader looks up all the files in APPBASE.'config/db/'
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