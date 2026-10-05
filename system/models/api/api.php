<?php
  /**
  * The api class executes according to uri a specific model and method
  *
  *   /api/products/latest/5  ->  products::latest('5'), sent as JSON
  *
  * A model offers methods over /api only by listing them, with the least
  * role that may call each one:
  *
  *   static function api() {
  *       return array('latest' => 'visitor', 'webhook' => 'visitor', 'report' => 'editor');
  *   }
  *
  * Anything not listed answers 404, whatever the method would return. The
  * roles are visitor (anyone), member, editor and admin. Bundled models
  * follow the same rule: cms lists its editor endpoints.
  *
  * A method that throws answers {"error":"server error"} with a 500, and the
  * error goes to the log with the URL; a database that can't be reached
  * answers 503 before the method runs, and too few arguments 400.
  * Development adds the trace.
  */
  class api
  {
    static $roles = array('visitor', 'member', 'editor', 'admin');

    function load()
    {
      $model = util::param('api', false);
      if (!$model)
        return false;

      $segments = config::get('uri_segments');
      if ($segments[0] !== 'api') return false;

      $method = util::param($model, false);
      if(!$method || strpos($method, '_') === 0) $this->fail(404, 'unspecified method');
      // an override (the_feed) is only ever reached by the name it overrides
      if (!preg_match('/^[a-z0-9_]+$/', $model) || strpos($model, 'the_') === 0) $this->fail(404, 'unknown model');
      // models that must not be reachable over /api (mcp has its own endpoint)
      if (in_array($model, (array)config::get('api_blocked', array('mcp', 'api')))) $this->fail(404, 'unknown model');

      // bundled models (and overrides of them) only when listed in
      // api_system_models
      $paths = controller::build_model_paths($model);
      $bundled = file_exists($paths['system_path']);
      if ($bundled && !in_array($model, (array)config::get('api_system_models', array('cms')))) $this->fail(404, 'unknown model');
      if (!$bundled && !file_exists($paths['app_path']) && !file_exists($paths['extended_path'])) $this->fail(404, 'unknown model');

      if (!controller::load_model($model)) $this->fail(404, 'unknown model');
      $obj = controller::get_object($model);
      // only real public methods, never magic ones
      if (!is_object($obj) || !method_exists($obj, $method)) $this->fail(404, 'unknown method');
      $reflection = new ReflectionMethod($obj, $method);
      if (!$reflection->isPublic() || $reflection->isStatic()) $this->fail(404, 'unknown method');

      // only what the model lists, for the roles it names; an override
      // (the_cms) adds to what the bundled model lists, it never drops it
      $offered = array_merge(self::offered($model), self::offered(get_class($obj)));
      if (!isset($offered[$method])) $this->fail(404, 'unknown method');
      // a database that can't be reached is an outage, not an empty table:
      // a webhook told "no such order" would never be sent again. Checked
      // before the role, since who is asking can't be known without it.
      if (database::configured() && ($down = cms_store::unreachable()) !== null) {
        log::error('the database can\'t be reached: '.$down.', at '.$this->request());
        $answer = array('error' => 'database unavailable');
        if (config::get('environment') === 'development') $answer['exception'] = $down;
        $this->fail(503, $answer);
      }
      if (!self::may($offered[$method])) {
        $this->fail(authentication::user() ? 403 : 401, 'not allowed');
      }

      $arguments = array_slice($segments, 3);
      if (count($arguments) < $reflection->getNumberOfRequiredParameters()) $this->fail(400, 'missing arguments');
      // what the method printed before it threw is thrown away with it
      ob_start();
      try {
        $result = call_user_func_array(array($obj, $method), $arguments);
      } catch (Throwable $e) {
        ob_end_clean();
        $this->broken($e);
      }
      ob_end_flush();
      if ($result !== false) {
        header('Content-Type: application/json');
        echo json_encode($result);
      }
      exit;
    }

    // What a model class offers over /api: array(method => role), empty
    // when it lists nothing. Every entry is method => role, nothing shorter:
    // an entry without a role, or a role that isn't one of self::$roles,
    // offers nothing (lint reports it), so a slip never opens a method by
    // accident.
    static function offered($class) {
      if (!method_exists($class, 'api') || !(new ReflectionMethod($class, 'api'))->isStatic()) return array();
      $offered = array();
      $listed = call_user_func(array($class, 'api'));
      foreach (is_array($listed) ? $listed : array() as $method => $role) {
        if (is_string($method) && in_array($role, self::$roles, true)) $offered[$method] = $role;
      }
      return $offered;
    }

    static function may($role) {
      return $role === 'visitor' || authentication::can($role);
    }

    // A method that threw: logged with the URL and answered as JSON, so a
    // payment provider sees a failure and tries again. A database that
    // can't be reached is 503; development adds what was thrown.
    protected function broken($e) {
      $thrown = get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
      log::error("$thrown, at ".$this->request());
      // a database error is an outage only when the database is gone; a
      // bad query is a bug like any other
      $database = ($e instanceof PDOException || $e instanceof RedBeanPHP\RedException\SQL) && cms_store::unreachable() !== null;
      $answer = array('error' => $database ? 'database unavailable' : 'server error');
      if (config::get('environment') === 'development') {
        $answer['exception'] = $thrown;
        $answer['trace'] = explode("\n", $e->getTraceAsString());
      }
      $this->fail($database ? 503 : 500, $answer);
    }

    protected function request() {
      return (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET').' '.config::get('uri_string');
    }

    protected function fail($status, $message) {
      if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
      }
      echo json_encode(is_array($message) ? $message : array('error' => $message));
      exit;
    }
  }
