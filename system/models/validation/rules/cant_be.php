<?php
function validate_cant_be($field_name, $field_value)
{
	$app = the::app();	
	if(($app->post($field_name) === false) || $app->post($field_name) != $field_value)
		return true;
	else
		return array("message" => false);
}