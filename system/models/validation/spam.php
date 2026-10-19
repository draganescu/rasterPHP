<?php
/**
* Spam checks for public forms: a helper of the validation model.
*
* The stamp is a hidden field, `raster_ts`, added to every post form that has
* an owner. Its value is the time the form was shown and an HMAC of it with
* the site's key (`util::secret()`), so a post can say how long ago its form
* was made and a script can't invent one.
*
* reasons() is what validation::get()->suspect() asks. It checks the stamp,
* counts the post by its IP and its address (hashed with the day's salt, in
* the formhit table), and looks for links and blocked words. A check that
* can't run (no key, no table, a database error) passes and logs a warning,
* so a form never stops working because of its spam checks. No raw IP is
* stored or logged: only hashes, and the salt goes with its counts at
* midnight.
*/
class validation_spam
{
	// regions that can turn a post away as spam: lint wants try_again and
	// slow_down alerts in any view that has one
	static $checked_regions = array('render.newsletter.signup', 'render.authentication.register', 'render.booking.form', 'render.contact.form');
	// warnings already logged in this request, so each is logged once
	static $warned = array();
	// the tables were made (or found) in this request
	static $ready = false;

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

	// The reasons a post to $owner looks like spam, in the order of the
	// design's table; empty when it looks human. $fields are the fields
	// looked at for links and blocked words. The post is counted here.
	static function reasons($owner, $fields) {
		$reasons = array();

		// the stamp: spam_min_seconds false turns all of it off
		$min = config::get('spam_min_seconds', 3);
		if ($min !== false) {
			if (util::secret() === '') {
				self::warn("there is no key (RASTER_SECRET or data/secret), so stamps aren't checked");
			} else {
				$age = self::stamp_age(isset($_POST['raster_ts']) ? $_POST['raster_ts'] : null);
				$max = config::get('spam_max_age', 86400);
				if ($age === null) $reasons[] = 'no_stamp';
				elseif ($age === false) $reasons[] = 'stamp_invalid';
				elseif ($age < (int)$min) $reasons[] = 'too_fast';
				elseif ($max !== false && $age > (int)$max) $reasons[] = 'stamp_old';
			}
		}

		// the limits: the post is counted, then compared, so the 6th post
		// of the hour is the first one over a limit of 5
		$per_ip = config::get('spam_per_ip', 5);
		$per_email = config::get('spam_per_email', 3);
		$email = self::email();
		if ($email === null) $per_email = false;
		if ($per_ip !== false || $per_email !== false) {
			$salt = self::salt();
			if ($salt !== null) {
				self::note_proxy();
				try {
					if ($per_ip !== false && self::count_hit($owner, 'ip', self::hash(self::network(self::client_ip()), $salt)) > (int)$per_ip) $reasons[] = 'too_many_from_ip';
					if ($per_email !== false && self::count_hit($owner, 'email', self::hash($email, $salt)) > (int)$per_email) $reasons[] = 'too_many_from_email';
				} catch (Exception $e) {
					self::warn($e->getMessage());
				}
			}
		}

		// the text: the fields named, joined; the email's domain always
		$text = array();
		foreach ((array)$fields as $field) {
			foreach ((array)validation::value($field) as $value) {
				if (is_string($value)) $text[] = $value;
			}
		}
		$text = implode("\n", $text);
		$links = config::get('spam_max_links', 2);
		if ($links !== false && self::links($text) > (int)$links) $reasons[] = 'too_many_links';
		if (self::blocked($text, $email)) $reasons[] = 'blocked';
		return $reasons;
	}

	// the posted email, lower-cased, or null when there is none that is valid
	protected static function email() {
		$email = validation::value('email');
		if (!is_string($email)) return null;
		$email = strtolower(trim($email));
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
	}

	// The visitor's address: REMOTE_ADDR, unless that is a trusted proxy,
	// then the client_ip_header it sends (for a list, the last entry that
	// isn't itself trusted). A missing or bad header keeps REMOTE_ADDR.
	static function client_ip() {
		$remote = self::unmapped(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
		$proxies = (array)config::get('trusted_proxies', array());
		if (!$proxies || !self::trusted($remote, $proxies)) return $remote;
		$header = 'HTTP_'.strtoupper(str_replace('-', '_', (string)config::get('client_ip_header', 'X-Forwarded-For')));
		if (empty($_SERVER[$header]) || !is_string($_SERVER[$header])) return $remote;
		$entries = array_reverse(array_map(array('validation_spam', 'unmapped'), array_map('trim', explode(',', $_SERVER[$header]))));
		foreach ($entries as $entry) {
			if (!filter_var($entry, FILTER_VALIDATE_IP)) return $remote;
			if (!self::trusted($entry, $proxies)) return $entry;
		}
		// every hop is trusted: the first is the visitor
		return end($entries);
	}

	// An IPv4 address written as IPv6 (::ffff:203.0.113.7, from a server
	// listening on both) as the IPv4 it is: otherwise every visitor would
	// share one /64, and no IPv4 range would match it
	static function unmapped($ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return $ip;
		$packed = inet_pton($ip);
		if (substr($packed, 0, 12) !== str_repeat("\0", 10)."\xff\xff") return $ip;
		return inet_ntop(substr($packed, 12));
	}

	protected static function trusted($ip, $proxies) {
		foreach ($proxies as $range) {
			if (self::in_range($ip, $range)) return true;
		}
		return false;
	}

	// whether $ip is $range: an address, or a CIDR range (10.0.0.0/8,
	// fd00::/8). An IPv4 address is never in an IPv6 range.
	static function in_range($ip, $range) {
		$range = trim((string)$range);
		$bits = null;
		if (strpos($range, '/') !== false) list($range, $bits) = explode('/', $range, 2);
		if (!filter_var((string)$ip, FILTER_VALIDATE_IP) || !filter_var($range, FILTER_VALIDATE_IP)) return false;
		$ip = inet_pton((string)$ip);
		$range = inet_pton($range);
		if (strlen($ip) !== strlen($range)) return false;
		if ($bits === null) return $ip === $range;
		if (!ctype_digit($bits) || (int)$bits > strlen($ip) * 8) return false;
		$bytes = intdiv((int)$bits, 8);
		if (substr($ip, 0, $bytes) !== substr($range, 0, $bytes)) return false;
		$rest = (int)$bits % 8;
		if ($rest === 0) return true;
		$mask = (0xff << (8 - $rest)) & 0xff;
		return (ord($ip[$bytes]) & $mask) === (ord($range[$bytes]) & $mask);
	}

	// what an IP is counted as: IPv4 whole, IPv6 by its /64 (one household
	// or phone holds a whole /64), anything else 'unknown'
	protected static function network($ip) {
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return bin2hex(substr(inet_pton($ip), 0, 8));
		return 'unknown';
	}

	static function hash($value, $salt) {
		return hash('sha256', $salt.$value);
	}

	// The day's salt, made when the day (the site's timezone) changes; the
	// old one goes, and every count made with it, so nothing stored can be
	// traced back to an IP later. null when it can't be had.
	static function salt() {
		try {
			self::ready();
			$db = database::instance('validation');
			$today = date('Y-m-d');
			$rows = $db->salt();
			// only ever forward: a request that started before midnight
			// can't put yesterday's salt back
			if ($rows && $rows[0]['day'] >= $today) return $rows[0]['salt'];
			// two requests at midnight wait for each other here, and the
			// second finds the first one's salt
			return cms_records::transaction(function () use ($db, $today) {
				$rows = $db->salt();
				if ($rows && $rows[0]['day'] >= $today) return $rows[0]['salt'];
				$db->clear_hits();
				$db->clear_salt();
				$salt = bin2hex(random_bytes(32));
				$db->add_salt($today, $salt);
				return $salt;
			});
		} catch (Exception $e) {
			self::warn($e->getMessage());
			return null;
		}
	}

	// Posts from a private address with no trusted_proxies: a proxy is
	// probably in front and every visitor shares one count. doctor runs
	// on the command line and learns it from today's formsalt row.
	protected static function note_proxy() {
		$remote = self::unmapped(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
		if (!filter_var($remote, FILTER_VALIDATE_IP) || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return;
		if (config::get('trusted_proxies')) return;
		try {
			database::instance('validation')->mark_proxied(date('Y-m-d'));
		} catch (Exception $e) {
			self::warn($e->getMessage());
		}
	}

	// Counts one post of $form by $kind (ip or email) and $hash; returns
	// how many there were in the last hour, this one included. Older rows of
	// the form go first.
	static function count_hit($form, $kind, $hash) {
		$db = database::instance('validation');
		$now = time();
		$db->expire($form, $now - 3600);
		$db->add_hit($form, $kind, $hash, $now);
		$rows = $db->count($form, $kind, $hash, $now - 3600);
		return $rows ? (int)$rows[0]['hits'] : 0;
	}

	// A fluid database makes the tables on first use; a frozen one has them
	// once `schema --apply` ran, and until then the queries fail and the
	// limits pass.
	protected static function ready() {
		if (self::$ready) return;
		cms_store::connect();
		if (!R::getRedBean()->isFrozen()) {
			foreach (validation::schema() as $table => $fields) cms_types::ensure($table, array_map(array('cms_types', 'of_default'), $fields));
		}
		self::$ready = true;
	}

	static function links($text) {
		return (int)preg_match_all('~https?://|www\.|\[url=~i', (string)$text);
	}

	// A word of spam_blocklist in $text, whole; or a domain (an entry with a
	// dot) that is the email's domain or a host in the text, or their parent
	static function blocked($text, $email) {
		$list = (array)config::get('spam_blocklist', array());
		if (!$list) return false;
		$hosts = array();
		if (preg_match_all('~(?:://|www\.)([a-z0-9.-]+)~i', (string)$text, $m)) $hosts = array_map('strtolower', $m[1]);
		if ($email !== null) $hosts[] = substr($email, strrpos($email, '@') + 1);
		foreach ($list as $entry) {
			$entry = strtolower(trim((string)$entry));
			if ($entry === '') continue;
			if (strpos($entry, '.') === false) {
				if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($entry, '/').'(?![\p{L}\p{N}])/iu', (string)$text)) return true;
				continue;
			}
			foreach ($hosts as $host) {
				$host = rtrim($host, '.');
				if ($host === $entry || substr($host, -strlen($entry) - 1) === '.'.$entry) return true;
			}
		}
		return false;
	}

	// a check that can't run says so once per request, without the IP or
	// the address (the queries bind hashes, never those)
	protected static function warn($message) {
		if (isset(self::$warned[$message])) return;
		self::$warned[$message] = true;
		log::warning('spam: '.$message);
	}
}
