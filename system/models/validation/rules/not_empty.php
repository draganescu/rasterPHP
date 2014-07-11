<?php
function validate_not_empty($field_name)
{
	$app = controller::instance();
	if((util::post($field_name) === false) || util::post($field_name) != "")
		return true;
	
	return array("message" => false);
}