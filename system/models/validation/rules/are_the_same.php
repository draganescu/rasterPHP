<?php
function validate_are_the_same($field_A, $field_B)
{
	
	$app = controller::instance();
	if((util::post($field_A) === util::post($field_B)))
		return true;
	else
		return array("message" => false);
}