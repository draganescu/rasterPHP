<?php

class event {
	
	private static $instances = array();
    protected function __construct() {}
    protected function __clone() {}
    protected $events = array();
    protected $current_event = '';
    protected $event_data = array();
    public $current_model = null;
    public $current_method = null;

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
		$binds = isset($this->events[$this->current_event]) ? $this->events[$this->current_event] : array();
		foreach (( array )$binds as $key => $bind) {
			if( $model == $bind[ 0 ] && $method == $bind[ 1 ] ) {
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
		$subscribers = isset($this->events[$this->current_event]) ? $this->events[$this->current_event] : array();
		unset($this->events[$this->current_event]);
		$this->current_event = 'core_'.$this->current_event;
		$core_subscribers = isset($this->events[$this->current_event]) ? $this->events[$this->current_event] : array();
		if(empty($core_subscribers)) {
			$core_subscribers = array();
		}
		$this->events[$this->current_event] = array_merge($core_subscribers, $subscribers);
		return true;
	}
	
	// Runs everything bound to an event: the application's bindings first,
	// then the framework's core ones (core() moved those to core_<event>).
	// Returns false when any subscriber returned false, so for example a
	// loading_model_<name> handler can stop a model from loading.
	public static function dispatch($the_event) {
		
		log::info('Event: '.$the_event);
		$event = event::instance();
		$result = true;

		foreach (array($the_event, 'core_'.$the_event) as $name) {
			if (strpos($the_event, 'core_') === 0 && $name !== $the_event) continue;
			if (empty($event->events[$name])) continue;
			$is_core = strpos($name, 'core_') === 0;
			foreach ($event->events[$name] as $bind) {
				list($model, $method) = $bind;
				$event->current_event = $name;
				$event->current_model = $model;
				$event->current_method = $method;

				if ($model == NULL) {
					$value = function_exists($method) ? $method($event) : null;
				} else {
					if ($is_core) {
						$object = $model::instance();
					} else {
						// a model can't be asked about loading itself
						if ($the_event !== 'loading_model_'.$model) controller::load_model($model);
						$object = controller::get_object($model);
					}
					$value = (is_object($object) && is_callable(array($object, $method))) ? $object->$method() : false;
				}
				$event->data($value);
				if ($value === false && strpos($the_event, 'loading_model_') === 0) $result = false;
			}
		}
		
		return $result;
	}
	
	function data($value) {
		$this->event_data[$this->current_event][$this->current_model.$this->current_method] = $value;
		return true;
	}
	
	static function result($name, $model, $method) {
		$event = event::instance();
		foreach (array($name, 'core_'.$name) as $key) {
			if (isset($event->event_data[$key][$model.$method])) return $event->event_data[$key][$model.$method];
		}
		return null;
	}
	
}