<?php
function validate_are_the_same($field_A, $field_B)
{
	
	$app = the::app();
	if(($app->post($field_A) === $app->post($field_B)))
		return true;
	else
		return array("message" => false);
}