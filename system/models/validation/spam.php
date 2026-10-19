<?php
/**
* Spam checks for public forms: a helper of the validation model.
*
* The stamp is a hidden field, `raster_ts`, added to every post form that has
* an owner. Its value is the time the form was shown and an HMAC of it with
* the site's key (`util::secret()`), so a post can say how long ago its form
* was made and a script can't invent one.
*/
class validation_spam
{
	// "<unix time>.<64 hex>", or '' when there is no key to sign with
	static function stamp($time = null) {
		$key = util::secret();
		if ($key === '') return '';
		$time = $time === null ? time() : (int)$time;
		return $time.'.'.hash_hmac('sha256', 'raster_ts|'.$time, $key);
	}

	// seconds since the stamp was made; null when there is none; false when
	// it is malformed, forged, or there is no key to check it with
	static function stamp_age($value) {
		if ($value === null || $value === '') return null;
		$key = util::secret();
		if (!is_string($value) || $key === '' || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $value, $m)) return false;
		if (!hash_equals(hash_hmac('sha256', 'raster_ts|'.$m[1], $key), $m[2])) return false;
		return time() - (int)$m[1];
	}
}
