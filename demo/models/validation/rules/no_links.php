<?php
// validation.no_links('message'): a message to the café has no web links,
// which is most of what spam sends
function validate_no_links($value) {
	if ($value === null || trim((string)$value) === '') return true;
	return !preg_match('#https?://|www\.#i', (string)$value);
}
