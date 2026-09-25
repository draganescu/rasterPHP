<?php

class log {
	
	public $enabled = false;

	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}

    private $entries = array();

    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }

    public static function enable() {
    	$log = log::instance();
    	$log->enabled = true;
    }

    public static function disable() {
    	$log = log::instance();
    	$log->enabled = false;
    }

    public function output() {
        if ($this->enabled && PHP_SAPI !== 'cli') {
            echo $this->javascript_console();
        }
    }

    function javascript_console() {
        $code = "<script>\n";
        foreach ($this->entries as $entry) {
            $code .= "\tconsole.log(".json_encode($entry->type.': '.$entry->message, JSON_HEX_TAG).");\n";
        }
        $code .= "</script>\n";
        return $code;
    }


    public static function info($message) {

    	$log = log::instance();
    	if ($log->enabled) {
            $entry = $log->create_entry('info', $message);
        }
    }

    

    public static function warning($message) {
    	$log = log::instance();
    	if ($log->enabled) {
            $entry = $log->create_entry('warning', $message);
        }
    }

    public static function error($message) {
    	$log = log::instance();
    	if ($log->enabled) {
            $entry = $log->create_entry('error', $message);
        }
    }

    private function create_entry($type, $message) {
        $entry = new StdClass;
        $entry->type = $type;
        $entry->message = $message;
        $this->entries[] = $entry;
        return $entry;
    }
    
}