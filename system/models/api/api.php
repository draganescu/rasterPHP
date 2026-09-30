<?php
  /**
  * The api class executes according to uri a specific model and method
  *
  *   /api/products/latest/5  ->  products::latest('5'), sent as JSON
  *
  * An application model offers methods over /api only by listing them,
  * with the least role that may call each one:
  *
  *   static function api() {
  *       return array('latest' => 'visitor', 'webhook' => 'visitor', 'report' => 'editor');
  *   }
  *
  * Anything not listed answers 404, whatever the method would return. The
  * roles are visitor (anyone), member, editor and admin. A site that still
  * sets config api_open (written by the 2.1.1 upgrade for sites made
  * before) keeps every public method of its models that don't list theirs
  * reachable, as before; `raster doctor` warns about it.
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
      if (!preg_match('/^[a-z0-9_]+$/', $model)) $this->fail(404, 'unknown model');
      // models that must not be reachable over /api (mcp has its own endpoint)
      if (in_array($model, (array)config::get('api_blocked', array('mcp', 'api')))) $this->fail(404, 'unknown model');

      // bundled models (and overrides of them) only when listed in
      // api_system_models; they guard their own methods
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

      // application models: only what the model lists, for the roles it names
      if (!$bundled) {
        $offered = self::offered(get_class($obj));
        if ($offered !== null) {
          if (!isset($offered[$method])) $this->fail(404, 'unknown method');
          if (!self::may($offered[$method])) {
            $this->fail(authentication::user() ? 403 : 401, 'not allowed');
          }
        }
      }

      $result = call_user_func_array(array($obj, $method), array_slice($segments, 3));
      if ($result !== false) {
        header('Content-Type: application/json');
        echo json_encode($result);
      }
      exit;
    }

    // What a model class offers over /api: array(method => role). An empty
    // array when it lists nothing, and null when the site keeps the open
    // /api of older versions (config api_open) and the model lists nothing.
    // A method named without a role is for visitors; a role that isn't one
    // of self::$roles offers nothing (lint reports it).
    static function offered($class) {
      if (!method_exists($class, 'api') || !(new ReflectionMethod($class, 'api'))->isStatic()) {
        return config::get('api_open') ? null : array();
      }
      $offered = array();
      foreach ((array)call_user_func(array($class, 'api')) as $method => $role) {
        if (is_int($method)) { $method = $role; $role = 'visitor'; }
        if (is_string($method) && in_array($role, self::$roles, true)) $offered[$method] = $role;
      }
      return $offered;
    }

    static function may($role) {
      return $role === 'visitor' || authentication::can($role);
    }

    protected function fail($status, $message) {
      http_response_code($status);
      header('Content-Type: application/json');
      echo json_encode(array('error' => $message));
      exit;
    }
  }
