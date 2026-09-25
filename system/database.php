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
    public static function instance( $model = false )
    {  
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        $database = self::$instances[$cls];
        if ($model) {
            $database->current_model = $model;
        }
        return $database;
    }

    // true when a connection was selected for the current environment
    protected static $connected = false;
    // the name of the selected connection (the environment)
    public static $connection = null;
    // whether the selected connection is frozen (no automatic schema changes)
    public static $frozen = false;

    // by default we load the Red Bean library and connect to the db when 
    // a new instance of the database singleton is called
    protected function __construct() {
        require_once BASE.'libraries/rb.php';
        database::setup(  );
    }

    // true when there is a database connection for this environment
    public static function configured() {
        if (!isset(self::$instances[__CLASS__]) && !isset(self::$instances['the_'.__CLASS__])) {
            database::instance();
        }
        return self::$connected;
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
                $queries = array();
                $querries = array(); // the old spelling, still read
                include APPBASE.'models/sql.php';
                $queries = array_merge($querries, $queries);
                if(array_key_exists($name, $queries)) {
                    return $this->query($queries[$name], $arguments);
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $this;
    }

    // Runs a query and returns all rows. Parameters are bound, never pasted:
    //   $db->query('SELECT * FROM book WHERE author = ?', array('Tolkien'));
    //   $db->query('SELECT * FROM book WHERE author = :a', array(':a' => 'Tolkien'));
    // Older SQL files that use sprintf placeholders (%s, %d) still work; their
    // values are quoted by the database driver first.
    public function query( $query, $params = array() ) {
        $params = (array)$params;
        if (count($params) === 1 && isset($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }
        if (preg_match('/%[sd]/', $query) && !preg_match('/\?|:[a-z_]/i', $query)) {
            $pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
            $quoted = array();
            foreach ($params as $value) {
                $quoted[] = is_int($value) || is_float($value) ? $value : $pdo->quote((string)$value);
            }
            $query = vsprintf(str_replace("'%s'", '%s', $query), $quoted);
            $params = array();
        }
        log::info('Query: '. $query);
        return R::getAll( $query, $params );
    }

    // each connection config in APPBASE.'config/db/' is parsed and if 
    // its active the R library is made aware of the new connection
    public static function setup(  ) {
    	$active_connections = array(  );
    	$db_config_files = database::get_db_config(  );

    	if( count( $db_config_files ) == 0 ) return false;

    	$frozen_connections = array(  );
    	foreach ($db_config_files as $file) {
    		// each file is read in isolation so settings don't leak between files
    		$settings = (function ($__file) {
    			$active = false; $dsn = null; $user = null; $password = null; $frozen = false;
    			require $__file;
    			return compact('active', 'dsn', 'user', 'password', 'frozen');
    		})(APPBASE.'config/db/'.$file);
    		$key = basename($file, ".php");
    		if( !$settings['active'] || !$settings['dsn'] ) continue;
    		// make sure the folder of an sqlite database exists
    		if (strpos($settings['dsn'], 'sqlite:') === 0) {
    			$path = substr($settings['dsn'], 7);
    			if ($path !== ':memory:' && !is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    		}
    		if (!R::hasDatabase($key)) {
    			R::addDatabase($key, $settings['dsn'], $settings['user'], $settings['password'], (bool)$settings['frozen']);
    		}
    		$active_connections[  ] = $key;
    		$frozen_connections[ $key ] = (bool)$settings['frozen'];
    	}

        // Raster supports seamless deployement on multiple
        // servers which can have different tags attached such as 
        // development, local, staging, pre-production, live etc.
    	$env = config::get( 'environment' );
        
        if( !in_array($env, $active_connections) ) return false;
        
        // depending on what the current environment is we use R to make
        // a new connection to the DB
		R::selectDatabase($env);
		R::freeze($frozen_connections[$env]);
		self::$connected = true;
		self::$connection = $env;
		self::$frozen = $frozen_connections[$env];
    	return true;
    }

    // the database config loader looks up all the files in APPBASE.'config/db/'
    static function get_db_config() {
    	$db_config_files = array(  );
    	if (!is_dir(APPBASE.'config/db/')) return $db_config_files;
		$files = scandir(APPBASE.'config/db/'); 
		foreach($files as $file) {
		    if(is_file(APPBASE.'config/db/'.$file) && substr($file, -4) === '.php') {
            	$db_config_files[] = $file;
			}
		}		
		return $db_config_files;
	}


}