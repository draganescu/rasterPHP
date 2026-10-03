<?php
// #Events
//
// How models talk to each other without the template knowing. A model
// announces that something happened, with the details in an array:
//
//   event::dispatch('reservation.booked', array('email' => $email, 'name' => $name));
//
// and any model can listen, either by declaring it next to its code:
//
//   class cafe {
//       static function listens() {
//           return array('reservation.booked' => 'subscribe_guest');
//       }
//       function subscribe_guest($booking) { ... $booking['email'] ... }
//   }
//
// or with a binding in config/the_events.php:
//
//   event::bind('reservation.booked')->to('cafe', 'subscribe_guest');
//
// Listeners run in the order they were bound: config/the_events.php first,
// then models' listens(), then the framework's own ("core") bindings. Each
// gets the payload array. A listener that returns false makes dispatch()
// return false; the code that dispatched decides what that means (for
// loading_model_<name> it stops the model from loading).
//
// Events the bundled models send are listed in AGENTS.md (Events); the
// request lifecycle sends launch, finding_route, route_set, route_found,
// route_not_found, loading_model_<name>, executing_<model>_<method>,
// executed_<model>_<method>, before_drying, dried_<view>, after_drying,
// before_render, after_render, before_print, after_print, loop,
// before_output, done and land.
class event {

	private static $instances = array();
	protected function __construct() {}
	protected function __clone() {}

	// event name => list of array(model, method, core)
	protected $events = array();
	protected $current_event = '';
	protected $event_data = array();
	public $current_model = null;
	public $current_method = null;

	public static function instance()
	{
		$cls = __CLASS__;
		if (class_exists('the_'.$cls, false)) $cls = 'the_'.$cls;
		if (!isset(self::$instances[$cls])) {
			self::$instances[$cls] = new $cls;
		}
		return self::$instances[$cls];
	}

	// ##Binding

	static function bind($event) {
		$events = event::instance();
		$events->current_event = $event;
		return $events;
	}

	function to($model, $method) {
		foreach ($this->listeners_of($this->current_event) as $bind) {
			if ($bind[0] === $model && $bind[1] === $method) return $this;
		}
		$this->events[$this->current_event][] = array($model, $method, false);
		return $this;
	}

	// the binding just made belongs to the framework: it runs after every
	// other listener, on the class's singleton (controller, log)
	function core() {
		if (empty($this->events[$this->current_event])) return $this;
		$last = count($this->events[$this->current_event]) - 1;
		$this->events[$this->current_event][$last][2] = true;
		return $this;
	}

	static function unbind($event) {
		return event::bind($event);
	}

	function from($model, $method) {
		foreach ($this->listeners_of($this->current_event) as $key => $bind) {
			if ($bind[0] === $model && $bind[1] === $method) unset($this->events[$this->current_event][$key]);
		}
		return $this;
	}

	protected function listeners_of($name) {
		return isset($this->events[$name]) ? $this->events[$name] : array();
	}

	// every binding: event => list of "model.method"
	static function bindings() {
		$all = array();
		foreach (event::instance()->events as $name => $binds) {
			foreach (self::ordered($binds) as $bind) $all[$name][] = $bind[0].'.'.$bind[1];
		}
		ksort($all);
		return $all;
	}

	protected static function ordered($binds) {
		$app = array(); $core = array();
		foreach ($binds as $bind) {
			if ($bind[2]) $core[] = $bind; else $app[] = $bind;
		}
		return array_merge($app, $core);
	}

	// Binds what models declare in `static function listens()`. Called at
	// boot, after config/the_events.php. Only model files that mention
	// listens() are loaded.
	static function discover() {
		$roots = array(APPBASE.config::get('models_path', 'models'), BASE.'models');
		foreach ($roots as $root) {
			foreach (glob($root.'/*', GLOB_ONLYDIR) ?: array() as $dir) {
				$folder = basename($dir);
				$file = "$dir/$folder.php";
				if (!is_file($file) || strpos(file_get_contents($file), 'function listens(') === false) continue;
				// models/the_feed/the_feed.php declares listeners for feed
				$model = strpos($folder, 'the_') === 0 ? substr($folder, 4) : $folder;
				if (!class_exists($folder)) require_once $file;
				if (!class_exists($folder) || !method_exists($folder, 'listens')) continue;
				foreach ((array)call_user_func(array($folder, 'listens')) as $event => $methods) {
					foreach ((array)$methods as $method) event::bind($event)->to($model, $method);
				}
			}
		}
	}

	// ##Dispatching

	// Runs every listener of an event with the payload. Returns false when
	// any listener returned false.
	public static function dispatch($the_event, $payload = array()) {
		log::info('Event: '.$the_event);
		$event = event::instance();
		$result = true;
		foreach (self::ordered($event->listeners_of($the_event)) as $bind) {
			list($model, $method, $is_core) = $bind;
			$event->current_event = $the_event;
			$event->current_model = $model;
			$event->current_method = $method;
			if ($model === null) {
				$value = function_exists($method) ? $method($payload) : null;
			} else {
				if ($is_core) {
					$object = $model::instance();
				} else {
					// a model can't be asked about loading itself
					if ($the_event !== 'loading_model_'.$model) controller::load_model($model);
					$object = controller::get_object($model);
				}
				$value = (is_object($object) && is_callable(array($object, $method))) ? $object->$method($payload) : null;
			}
			$event->event_data[$the_event][$model.'.'.$method] = $value;
			if ($value === false) $result = false;
		}
		return $result;
	}

	// what a listener returned the last time the event ran, or null
	static function result($name, $model, $method) {
		$event = event::instance();
		return isset($event->event_data[$name][$model.'.'.$method]) ? $event->event_data[$name][$model.'.'.$method] : null;
	}
}
