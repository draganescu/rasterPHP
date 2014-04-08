<?php
class validation
{
	
	var $invalid = false;
	var $messages = array();
	
	function __call($method, $args)
	{	
		$app = the::app();
		if($app->no_post_data() && $app->no_get_data())
			return "<!-- unset -->";
		
		if(!function_exists("validate_".$method))
		{
			if(is_file(BASE."../models/validation/rules/".$method.".php"))
				require_once BASE."../models/validation/rules/".$method.".php";
			else
				return "<!-- rule doesnt exist -->";
		}
		
		$result = call_user_func_array("validate_".$method, $args);
		
		if($result !== true)
			$this->invalid = true;
		
		if($result === true)
			return "<!-- valid -->";
		else
			return array($result);
	}
	
	function is_valid()
	{
		$app = the::app();
		if($app->no_post_data() && $app->no_get_data())
			return "<!-- unset -->";
		else
		{
			if($app->template_validation === true)
				return array(
					"__" => true,
					array(
						"message" => validation::message("is_valid")
					)
				);
			else
				return array(
					"__" => true,
					array(
						"message" => false
					)
				);
		}
	}
	
	static function message()
	{
		$args = func_get_args();
		$file = array_shift($args);
		
		$app = the::app();
		
		if(!file_exists(BASE.'/../views/'.$app->theme.'/validation/'.$file.".html"))
			return "<!-- message not found \n
						you need to create a validation directory \n
			 			in your theme folder and add \n
			 			an html file named $file in it that has the \n
			 			messge -->";
		else
			$message = file_get_contents(BASE.'/../views/'.$app->theme.'/validation/'.$file.".html");
		
		array_unshift($args, $message);
		
		$message = call_user_func_array('sprintf', $args);
		return $message;
	}
	
	function alert($name)
	{
		$app = the::app();
		if(array_key_exists($name, $this->messages) && $this->messages[$name] == false)
			return false;
		else if(array_key_exists($name, $this->messages) && $this->messages[$name] != false)
			return $this->messages[$name];
		else if(!array_key_exists($name, $this->messages))
			$this->messages[$name] = $app->current_block;
		return "<!-- alert set -->";
	}
	
	function raise($name)
	{
		if(array_key_exists($name, $this->messages))
			return $this->messages[$name];
		else if(!array_key_exists($name, $this->messages))
			$this->messages[$name] = false;
		return "";
	}
	
}