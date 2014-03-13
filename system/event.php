<?php

class event {
	
	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}
    protected $events = array();
    protected $current_event = '';
    protected $event_data = array();

    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }
    
    static function bind($event)
	{
		$events = event::instance();
		$events->current_event = $event;
		return $events;
	}
	
	function to($model, $method) {
		$this->events[$this->current_event][] = array($model, $method);
		return $this;
	}

	static function unbind($event)
	{
		$events = event::instance();
		$events->current_event = $event;
		return $events;
	}
	
	function from($model, $method) {
		$unbind = null;
		$binds = $this->events[$this->current_event];
		foreach (( array )$binds as $key => $bind) {
			if( $model == $bind[ 0 ] && $model == $bind[ 1 ] ) {
				$unbind = $key;
			}
		}
		if( !is_null($unbind) ) {
			unset( $this->events[$this->current_event][ $unbind ] );
		}
		return $this;
	}
	
	function core() {
		if(strpos($this->current_event, 'core_') !== false) return false;
		$subscribers = $this->events[$this->current_event];
		unset($this->events[$this->current_event]);
		$this->current_event = 'core_'.$this->current_event;
		$core_subscribers = $this->events[$this->current_event];
		if(empty($core_subscribers)) {
			$core_subscribers = array();
		}
		$this->events[$this->current_event] = array_merge($core_subscribers, $subscribers);
		return true;
	}
	
	public static function dispatch($the_event) {
		
		log::info('Event: '.$the_event);
		$event = event::instance();
		
		if(!is_array($event->events))
			return true;

		if(!array_key_exists($the_event, $event->events))
			if(!array_key_exists('core_'.$the_event, $event->events))
				return true;

		if(array_key_exists('core_'.$the_event, $event->events))
			$the_event = 'core_'.$the_event;
		
		$event->current_event = $the_event;
		$current_event_binds = $event->events[$the_event];
		foreach ($current_event_binds as $index => $bind) {
			$model = $bind[0];
			$method = $bind[1];
			$event->current_model = $model;
			$event->current_method = $method;
			
			if($model == NULL) {
				function_exists($method) ? $event->data($method($event)) : $event->data(NULL);
			}
			
			if(strpos($the_event, 'core_') !== false) {
				$object = $model::instance();
			} else {
				controller::load_model($model);
				$object = controller::get_object($model);
			}
			
			if(!is_callable(array($object, $method))) $event->data(false);
			
			$event->data($object->$method());
		}
		
		return true;
	}
	
	function data($value) {
		$this->event_data[$this->current_event][$this->current_model.$this->current_method] = $value;
		return true;
	}
	
	static function result($event, $model, $method) {
		$event = event::instance();
		return $event->event_data[$event][$model.$method];
	}
	
}