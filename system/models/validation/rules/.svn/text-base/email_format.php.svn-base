<?php
function validate_email_format($field_name)
{
	$app = the::app();	
	$emailregexp = '/^([a-zA-Z0-9])+([\.a-zA-Z0-9_-])*@([a-zA-Z0-9_-])+(\.[a-zA-Z0-9_-]+)*\.([a-zA-Z]{2,6})$/';
	if(preg_match($emailregexp, $app->post($field_name)))
		return true;
	else
		return array("message" => false);
}