<?php
  /**
  * The api class executes according to uri a specific model and method
  */
  class api
  {
    
    function load()
    {
      $model = util::param('api', false);
      if (!$model)
        return false;

      $method = util::param($model, false);
      if(!$method) die('unspecified method');

      controller::load_model($model);
      $obj = controller::get_object($model);

      echo json_encode(call_user_func_array(array($obj, $method), array_slice(config::get('uri_segments'), 3)));
      exit;
    }
  }