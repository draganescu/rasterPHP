<?php if ( ! defined('BASE')) exit('No direct script access allowed');
require_once BASE.'../library/CI/Input.php';
function clean($data)
{
	$cleaner = new CI_Input;
	$data = $cleaner->xss_clean($data);
	return $data;
}