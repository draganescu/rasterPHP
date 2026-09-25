<?php
  /**
  * The api class executes according to uri a specific model and method
  *
  *   /api/products/latest/5  ->  products::latest('5'), sent as JSON
  *
  * Every public method of every model is reachable this way, so keep
  * methods that change data behind a check (see cms::require_admin).
  */
  class api
  {

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

      // application models are public; system models only when listed
      $paths = controller::build_model_paths($model);
      $is_app_model = file_exists($paths['app_path']) || file_exists($paths['extended_path']);
      if (!$is_app_model && !in_array($model, (array)config::get('api_system_models', array('cms')))) $this->fail(404, 'unknown model');

      if (!controller::load_model($model)) $this->fail(404, 'unknown model');
      $obj = controller::get_object($model);
      // only real public methods, never magic ones
      if (!is_object($obj) || !method_exists($obj, $method)) $this->fail(404, 'unknown method');
      $reflection = new ReflectionMethod($obj, $method);
      if (!$reflection->isPublic() || $reflection->isStatic()) $this->fail(404, 'unknown method');

      $result = call_user_func_array(array($obj, $method), array_slice($segments, 3));
      if ($result !== false) {
        header('Content-Type: application/json');
        echo json_encode($result);
      }
      exit;
    }

    protected function fail($status, $message) {
      http_response_code($status);
      header('Content-Type: application/json');
      echo json_encode(array('error' => $message));
      exit;
    }
  }
