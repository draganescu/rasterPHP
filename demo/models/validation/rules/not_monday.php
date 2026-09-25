<?php
// validation.not_monday('date'): the café is closed on Mondays
function validate_not_monday($value) {
	if ($value === null || trim((string)$value) === '') return true;
	$time = strtotime((string)$value);
	return $time === false || date('N', $time) !== '1';
}
