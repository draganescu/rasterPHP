<?php
function validate_cant_be($field_name, $field_value)
{
	$app = controller::instance();	
	if((util::post($field_name) === false) || util::post($field_name) != $field_value)
		return true;
	else
		return array("message" => false);
}