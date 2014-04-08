<?php
function validate_not_empty($field_name)
{
	$app = the::app();
	if(($app->post($field_name) === false) || $app->post($field_name) != "")
		return true;
	
	return array("message" => false);
}