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
		$app = controller::instance();
		//$app->log("cleaning ".util::post_pointer);
		$this->data = $_POST[config::get('post_pointer')];
		$this->data = clean($this->data);
		$_POST[config::get('post_pointer')] = $this->data;
		//$app->log("cleaned ".util::post_pointer." result: ".$_POST[util::post_pointer]);
		return true;
	}

	function clean_cookie()
	{ 
		$app = controller::instance();
		//$app->log("cleaning ".util::cookie_pointer);
		$this->data = $_COOKIE[config::get('cookie_pointer')];
		$this->data = clean($this->data);
		$_COOKIE[config::get('cookie_pointer')] = $this->data;
		//$app->log("cleaned ".util::cookie_pointer." result: ".$_COOKIE[util::cookie_pointer]);
		return true;
	}

	function clean_get()
	{
		$app = controller::instance();
		//$app->log("cleaning ".util::get_pointer);
		$this->data = $_GET[config::get('get_pointer')];
		$this->data = clean($this->data);
		$_GET[config::get('get_pointer')] = $this->data;
		//$app->log("cleaned ".util::get_pointer." result: ".$_GET[util::get_pointer]);
		return true;
	}
}
