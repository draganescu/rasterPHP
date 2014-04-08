<?php if ( ! defined('BASE')) exit('No direct script access allowed');
class sanitization {
	
	private $data;
	private $cleaner;
	
	function sanitization()
	{
		require_once BASE.'../models/sanitization/cleaner.php';
	}
	
	function clean_post()
	{ 
		$app = the::app();
		$app->log("cleaning ".$app->post_pointer);
		$this->data = $_POST[$app->post_pointer];
		$this->data = clean($this->data);
		$_POST[$app->post_pointer] = $this->data;
		$app->log("cleaned ".$app->post_pointer." result: ".$_POST[$app->post_pointer]);
		return true;
	}

	function clean_cookie()
	{ 
		$app = the::app();
		$app->log("cleaning ".$app->cookie_pointer);
		$this->data = $_COOKIE[$app->cookie_pointer];
		$this->data = clean($this->data);
		$_COOKIE[$app->cookie_pointer] = $this->data;
		$app->log("cleaned ".$app->cookie_pointer." result: ".$_COOKIE[$app->cookie_pointer]);
		return true;
	}

	function clean_get()
	{
		$app = the::app();
		$app->log("cleaning ".$app->get_pointer);
		$this->data = $_GET[$app->get_pointer];
		$this->data = clean($this->data);
		$_GET[$app->get_pointer] = $this->data;
		$app->log("cleaned ".$app->get_pointer." result: ".$_GET[$app->get_pointer]);
		return true;
	}
}
