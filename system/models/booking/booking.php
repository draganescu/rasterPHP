<?php
/**
* Booking
*
* Bookings for a café's sittings or a salon's appointments, taken from the
* site's own pages. Staff set up what can be booked and when, as records
* they edit in the page (render.cms.bookingresource and the others):
*
*   bookingresource  what is booked: a dining room (people 20), a stylist
*                    (bookings 1); 0 is no limit, active false hides it
*   bookinghours     when slots start: resource (empty: every one), days
*                    (daily, or mon,tue,…), starts, ends (the last start),
*                    every (minutes between starts), from/until (a season)
*   bookingclosed    days off: date, until, resource (empty: every one), note
*   bookingservice   what a booking is for: name, minutes, resources (empty:
*                    every one), active. Without services a booking lasts the
*                    every of its hours row.
*
* Regions, all of them reading ?x from the URL where an option says so:
*   <!-- render.booking.slots('date=?date&resource=?resource&service=?service&people=?people') -->
*     one day's start times: time, ends, left_people, left_bookings ('' for
*     no limit), full ('1'/'0'), state (free/full)
*   <!-- render.booking.days('resource=?resource&service=?service&limit=14') -->
*     the next days with a free slot: date, slots_free
*   <!-- render.booking.resources('service=?service') -->  the active ones
*   <!-- render.booking.services('resource=?resource') -->  the active ones
*
* With one resource it is the one booked when none is chosen. Slots are
* times on the site's clock (the timezone setting): a time the clock skips
* in spring isn't a slot, the hour repeated in autumn is one slot, and a
* booking ends by midnight.
*
* Alerts for setup records: every_invalid, hours_invalid, days_invalid,
* season_invalid, limit_invalid, minutes_invalid, closed_invalid.
*
* Settings:
*   config::set('booking_notice')->to(60);   minutes ahead a guest may book
*   config::set('booking_horizon')->to(60);  days ahead a guest may book
*/
class booking
{
	static $week = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
	// the setup records, read once per request (setup())
	static $setup = null;

	static function types() {
		return array(
			'bookingresource' => array(
				'fields' => array('name' => '', 'people' => 0, 'bookings' => 0, 'active' => true),
			),
			'bookinghours' => array(
				'fields' => array('resource' => '', 'days' => 'daily', 'starts' => '', 'ends' => '', 'every' => 30, 'from' => '', 'until' => ''),
				'types' => array('starts' => 'time', 'ends' => 'time', 'from' => 'date', 'until' => 'date'),
			),
			'bookingclosed' => array(
				'fields' => array('date' => '', 'until' => '', 'resource' => '', 'note' => ''),
				'types' => array('date' => 'date', 'until' => 'date'),
			),
			'bookingservice' => array(
				'fields' => array('name' => '', 'minutes' => 30, 'resources' => '', 'active' => true),
			),
		);
	}

	// every write of the setup changes the slots, so what was read of it is
	// read again
	static function check($type, $after, $before) {
		self::forget();
		if ($after === null) return array();
		$problems = array();
		if ($type === 'bookinghours') {
			// every < 1 would never reach the last start
			if ((int)$after['every'] < 1) $problems[] = 'every_invalid';
			if ((string)$after['starts'] === '' || (string)$after['ends'] === '' || self::minutes($after['starts']) > self::minutes($after['ends'])) $problems[] = 'hours_invalid';
			if (self::weekdays($after['days']) === null) $problems[] = 'days_invalid';
			if ((string)$after['from'] !== '' && (string)$after['until'] !== '' && $after['from'] > $after['until']) $problems[] = 'season_invalid';
		} elseif ($type === 'bookingresource') {
			if ((int)$after['people'] < 0 || (int)$after['bookings'] < 0) $problems[] = 'limit_invalid';
		} elseif ($type === 'bookingservice') {
			if ((int)$after['minutes'] < 1) $problems[] = 'minutes_invalid';
		} elseif ($type === 'bookingclosed') {
			if ((string)$after['date'] === '' || ((string)$after['until'] !== '' && $after['until'] < $after['date'])) $problems[] = 'closed_invalid';
		}
		return $problems;
	}

	// ##The setup

	// the bundled types are declared: a site whose own model or type is
	// called booking keeps the name (cms_records::types), and then every
	// region here gives nothing
	static function ready() {
		$info = cms_records::info('bookingresource');
		return $info !== null && $info['model'] === 'booking';
	}

	// array('resources' => slug => row, 'hours' => rows, 'closed' => rows,
	// 'services' => slug => row)
	static function setup() {
		if (self::$setup !== null) return self::$setup;
		$setup = array('resources' => array(), 'hours' => array(), 'closed' => array(), 'services' => array());
		if (!self::ready()) return $setup;
		try {
			foreach (cms_records::find('bookingresource') as $row) $setup['resources'][$row['slug']] = $row;
			$setup['hours'] = cms_records::find('bookinghours');
			$setup['closed'] = cms_records::find('bookingclosed');
			foreach (cms_records::find('bookingservice') as $row) $setup['services'][$row['slug']] = $row;
		} catch (Exception $e) {
			// no database: no slots, and the page still answers
			log::warning('booking: '.$e->getMessage());
			return $setup;
		}
		return self::$setup = $setup;
	}

	static function forget() {
		self::$setup = null;
	}

	// ##Time
	// Slots are wall-clock minutes of the day, 0 to 1440. Notice and
	// horizon are real seconds (starts_at), so they hold across a DST change.

	// 19:00 or MySQL's 19:00:00 as minutes; with $end, 00:00 is midnight at
	// the end of the day (1440)
	static function minutes($time, $end = false) {
		if (!preg_match('/^(\d{1,2}):(\d\d)/', (string)$time, $m)) return 0;
		$minutes = (int)$m[1] * 60 + (int)$m[2];
		return $end && $minutes === 0 ? 1440 : $minutes;
	}

	static function clock($minutes) {
		$minutes = (int)$minutes;
		return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
	}

	// the moment a time on that date is, on the site's clock
	static function starts_at($date, $time) {
		return strtotime("$date $time");
	}

	protected static function is_date($date) {
		return is_string($date) && preg_match('/^(\d{4})-(\d\d)-(\d\d)$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	// the weekdays a days field names (daily is all of them), or null when it
	// names something else
	protected static function weekdays($days) {
		$days = strtolower(trim((string)$days));
		if ($days === 'daily') return self::$week;
		$list = array_map('trim', explode(',', $days));
		return $days !== '' && !array_diff($list, self::$week) ? $list : null;
	}

	// ##Slots

	// a closed row covers date..until (both included), for its resource or,
	// with none, for every one
	static function is_closed($date, $resource) {
		foreach (self::setup()['closed'] as $row) {
			if ((string)$row['resource'] !== '' && $row['resource'] !== $resource) continue;
			$until = (string)$row['until'] !== '' ? $row['until'] : $row['date'];
			if ($row['date'] <= $date && $date <= $until) return true;
		}
		return false;
	}

	// The day's start times for a resource, sorted:
	// array(array('time' => '09:00', 'every' => 30), …). Every hours row
	// that applies adds its starts; a time in several rows keeps the
	// smallest every, so it lasts the shortest by default.
	static function starts($date, $resource) {
		if (!self::is_date($date) || self::is_closed($date, $resource)) return array();
		$weekday = strtolower(date('D', strtotime($date)));
		$starts = array();
		foreach (self::setup()['hours'] as $row) {
			if ((string)$row['resource'] !== '' && $row['resource'] !== $resource) continue;
			$days = self::weekdays($row['days']);
			if (!$days || !in_array($weekday, $days, true)) continue;
			if ((string)$row['from'] !== '' && $date < $row['from']) continue;
			if ((string)$row['until'] !== '' && $date > $row['until']) continue;
			$every = (int)$row['every'];
			if ($every < 1 || (string)$row['starts'] === '' || (string)$row['ends'] === '') continue;
			$last = self::minutes($row['ends']);
			for ($t = self::minutes($row['starts']); $t <= $last && $t < 1440; $t += $every) {
				$time = self::clock($t);
				// a time the clock skips that day (02:59 to 04:00 in spring) isn't one
				if (date('H:i', self::starts_at($date, $time)) !== $time) continue;
				if (!isset($starts[$time]) || $every < $starts[$time]) $starts[$time] = $every;
			}
		}
		ksort($starts);
		$rows = array();
		foreach ($starts as $time => $every) $rows[] = array('time' => (string)$time, 'every' => $every);
		return $rows;
	}

	// an active service that this resource offers (no resources listed: all)
	static function offers($service, $resource) {
		$services = self::setup()['services'];
		if (!isset($services[$service]) || !$services[$service]['active']) return false;
		$list = trim((string)$services[$service]['resources']);
		return $list === '' || in_array($resource, array_map('trim', explode(',', $list)), true);
	}

	// whether a booking with this resource must name a service
	protected static function has_services($resource) {
		foreach (array_keys(self::setup()['services']) as $service) {
			if (self::offers($service, $resource)) return true;
		}
		return false;
	}

	// minutes a booking lasts: its service's, else the every of the hours
	// row it starts in (a sitting lasts until the next one)
	static function length($resource, $service, $every) {
		if ((string)$service !== '' && self::offers($service, $resource)) return (int)self::setup()['services'][$service]['minutes'];
		return (int)$every;
	}

	// The most people and the most bookings at once at any minute of
	// [$from, $to), over rows with time, ends and people. Spans are
	// half-open: one ending at 10:00 and one starting at 10:00 never meet.
	static function peak($rows, $from, $to) {
		$spans = array();
		// how many there are only goes up where a span starts, so the busiest
		// minute is $from or one of those starts
		$points = array($from);
		foreach ((array)$rows as $row) {
			$start = self::minutes($row['time']);
			$end = self::minutes($row['ends'], true);
			if ($end <= $start || $start >= $to || $end <= $from) continue;
			$spans[] = array($start, $end, max(0, (int)$row['people']));
			if ($start > $from) $points[] = $start;
		}
		$most = array('people' => 0, 'bookings' => 0);
		foreach ($points as $at) {
			$people = 0;
			$bookings = 0;
			foreach ($spans as $span) {
				if ($span[0] > $at || $at >= $span[1]) continue;
				$people += $span[2];
				$bookings++;
			}
			$most['people'] = max($most['people'], $people);
			$most['bookings'] = max($most['bookings'], $bookings);
		}
		return $most;
	}

	// One day's slots for a resource, as rows for a template (text, see
	// render.booking.slots). $taken are the active bookings of that resource
	// and day (time, ends, people). Left out: starts within booking_notice
	// minutes of $now, days past booking_horizon, and spans past midnight.
	static function day($date, $resource, $service = '', $people = 1, $taken = array(), $now = null) {
		if (!self::is_date($date)) return array();
		$resources = self::setup()['resources'];
		if (!isset($resources[$resource]) || !$resources[$resource]['active']) return array();
		if ((string)$service !== '' && !self::offers($service, $resource)) return array();
		$now = $now === null ? time() : (int)$now;
		$today = date('Y-m-d', $now);
		if ($date < $today || $date > date('Y-m-d', strtotime("$today +".(int)config::get('booking_horizon', 60).' days'))) return array();
		$notice = (int)config::get('booking_notice', 60) * 60;
		$limit_people = (int)$resources[$resource]['people'];
		$limit_bookings = (int)$resources[$resource]['bookings'];
		$rows = array();
		foreach (self::starts($date, $resource) as $start) {
			$from = self::minutes($start['time']);
			$to = $from + self::length($resource, $service, $start['every']);
			if ($to > 1440 || self::starts_at($date, $start['time']) < $now + $notice) continue;
			$busy = self::peak($taken, $from, $to);
			$left_people = $limit_people > 0 ? max(0, $limit_people - $busy['people']) : '';
			$left_bookings = $limit_bookings > 0 ? max(0, $limit_bookings - $busy['bookings']) : '';
			$full = ($left_people !== '' && $left_people < (int)$people) || ($left_bookings !== '' && $left_bookings < 1);
			$rows[] = array(
				'date' => $date,
				'time' => $start['time'],
				'ends' => self::clock($to),
				'left_people' => (string)$left_people,
				'left_bookings' => (string)$left_bookings,
				// text, not a bool: a false in a row keeps the mock-up
				'full' => $full ? '1' : '0',
				'state' => $full ? 'full' : 'free',
			);
		}
		return $rows;
	}

	// a region's options, 'date=?date&resource=?resource': a ?x is the URL's
	// x, today is today's date, and empty values are left out
	static function options($argument) {
		$options = array();
		foreach (explode('&', (string)$argument) as $chunk) {
			if (strpos($chunk, '=') === false) continue;
			list($key, $value) = explode('=', $chunk, 2);
			$key = trim($key);
			$value = cms_store::list_value(trim($value));
			if ($key === '' || $value === null || $value === '') continue;
			$options[$key] = $value;
		}
		return $options;
	}

	// array(resource, service, people) a region's options ask for, or null
	// while the guest still has to choose: a resource when there are
	// several, a service when the resource offers some
	protected static function chosen($options) {
		$active = array();
		foreach (self::setup()['resources'] as $slug => $row) {
			if ($row['active']) $active[] = $slug;
		}
		$resource = isset($options['resource']) ? $options['resource'] : (count($active) === 1 ? $active[0] : '');
		$service = isset($options['service']) ? $options['service'] : '';
		$people = isset($options['people']) ? (int)$options['people'] : 1;
		if ($resource === '' || $people < 1 || ($service === '' && self::has_services($resource))) return null;
		return array($resource, $service, $people);
	}

	// ##Regions

	function slots($options = '') {
		// what is free changes by the minute
		raster_cache::skip();
		if (!self::ready()) return array();
		$options = self::options($options);
		$chosen = self::chosen($options);
		if (!$chosen || !isset($options['date'])) return array();
		return self::day($options['date'], $chosen[0], $chosen[1], $chosen[2]);
	}

	// the next days with a free slot, from today, at most limit (14) of them
	function days($options = '') {
		raster_cache::skip();
		if (!self::ready()) return array();
		$options = self::options($options);
		$chosen = self::chosen($options);
		if (!$chosen) return array();
		$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 14;
		$horizon = (int)config::get('booking_horizon', 60);
		$today = date('Y-m-d');
		$rows = array();
		for ($n = 0; $n <= $horizon && count($rows) < $limit; $n++) {
			$date = date('Y-m-d', strtotime("$today +$n days"));
			$free = 0;
			foreach (self::day($date, $chosen[0], $chosen[1], $chosen[2]) as $slot) {
				if ($slot['full'] === '0') $free++;
			}
			if ($free) $rows[] = array('date' => $date, 'slots_free' => (string)$free);
		}
		return $rows;
	}

	// the active resources, editable in the page; service= keeps the ones
	// offering it
	function resources($options = '') {
		if (!self::ready()) return array();
		$options = self::options($options);
		$rows = array();
		foreach (self::setup()['resources'] as $slug => $row) {
			if (!$row['active'] || (isset($options['service']) && !self::offers($options['service'], $slug))) continue;
			$rows[] = $row;
		}
		return cms_records::listed('bookingresource', $rows);
	}

	// the active services; resource= keeps the ones it offers
	function services($options = '') {
		if (!self::ready()) return array();
		$options = self::options($options);
		$rows = array();
		foreach (self::setup()['services'] as $slug => $row) {
			if (!$row['active'] || (isset($options['resource']) && !self::offers($slug, $options['resource']))) continue;
			$rows[] = $row;
		}
		return cms_records::listed('bookingservice', $rows);
	}
}
