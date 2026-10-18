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
* Bookings are the booking type: name, email, phone, date, time, people,
* resource, service, notes, and what the model works out (ends, status,
* changed_at). Each is a request that holds its place until staff confirm,
* decline or cancel it (the actions). Guests book with the form:
*
*   <!-- render.booking.form --><form method="post">…</form><!-- /render.booking.form -->
*
* Regions, all of them reading ?x from the URL where an option says so:
*   <!-- render.booking.slots('date=?date&resource=?resource&service=?service&people=?people') -->
*     one day's start times: time, ends, left_people, left_bookings ('' for
*     no limit), full ('1'/'0'), state (free/full)
*   <!-- render.booking.days('resource=?resource&service=?service&limit=14') -->
*     the next days with a free slot: date, slots_free
*   <!-- render.booking.resources('service=?service') -->  the active ones
*   <!-- render.booking.services('resource=?resource') -->  the active ones
*   <!-- render.booking.form -->  the booking form; ?done=booked when stored
* For staff (editors) only, nothing for anyone else:
*   <!-- render.booking.agenda('from=today&days=7&resource=?resource') -->
*     days: date, closed, slots (as above), bookings (editable in place,
*     with attention, resource_name, service_name)
*   <!-- render.booking.attention -->  bookings to come that no longer fit
*   <!-- print.booking.count('status=requested') -->  how many; or 'attention'
*
* With one resource it is the one booked when none is chosen. Slots are
* times on the site's clock (the timezone setting): a time the clock skips
* in spring isn't a slot, the hour repeated in autumn is one slot, and a
* booking ends by midnight.
*
* Alerts for setup records: every_invalid, hours_invalid, days_invalid,
* season_invalid, limit_invalid, minutes_invalid, closed_invalid,
* resource_in_use, service_in_use. For bookings: not_open, resource_invalid,
* resource_inactive, name_invalid, email_invalid, people_invalid, too_many,
* not_a_slot, service_unavailable, too_soon, too_far, already_booked,
* fully_booked, already_confirmed, already_declined, already_cancelled; a
* confirm is also refused with why the booking needs attention (closed,
* resource_inactive, service_unavailable, not_a_slot, over_capacity).
*
* Settings:
*   config::set('booking_notice')->to(60);      minutes ahead a guest may book
*   config::set('booking_horizon')->to(60);     days ahead a guest may book
*   config::set('booking_max_people')->to(12);  people in a guest's booking
*/
class booking
{
	static $week = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
	// the setup records, read once per request (setup())
	static $setup = null;
	// the statuses that hold a place
	static $active = array('requested', 'confirmed');
	// whom the model's own writes are for: staff, or a guest on the form or
	// a link, whose writes get the guest rules (is_staff)
	static $acting = 'staff';
	// bookings staff moved in this request, id => true
	static $moved = array();

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
			'booking' => array(
				'fields' => array(
					'name' => '', 'email' => '', 'phone' => '', 'date' => '', 'time' => '', 'ends' => '',
					'people' => 1, 'resource' => '', 'service' => '', 'notes' => '', 'status' => 'requested',
					'token_hashes' => array(), 'token_expires' => '', 'changed_at' => '',
				),
				'types' => array('date' => 'date', 'time' => 'time', 'ends' => 'time', 'token_expires' => 'datetime', 'changed_at' => 'datetime'),
				// guests book with the form, staff take phone bookings in the
				// page, and a guest who was logged in sees their own
				'create' => 'visitor',
				'staff_add' => true,
				'owner' => true,
				'public' => false,
				'readonly' => array('ends', 'status', 'changed_at'),
				'hidden' => array('token_hashes', 'token_expires'),
				'actions' => array('confirm' => 'editor', 'decline' => 'editor', 'cancel' => 'editor'),
			),
		);
	}

	// every write of the setup changes the slots, so what was read of it is
	// read again
	static function check($type, $after, $before) {
		if ($type === 'booking') return self::check_booking($after, $before);
		self::forget();
		$problems = array();
		// a booking names its resource and service by slug: while one to come
		// does, the slug stays (the name may change)
		if ($before && ($after === null || $after['slug'] !== $before['slug'])) {
			if ($type === 'bookingresource' && self::in_use('resource', $before['slug'])) $problems[] = 'resource_in_use';
			if ($type === 'bookingservice' && self::in_use('service', $before['slug'])) $problems[] = 'service_in_use';
		}
		if ($after === null) return $problems;
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

	// active bookings dated today or later with this resource or service
	protected static function in_use($field, $slug) {
		if (!cms_store::table_exists('bookingdata')) return false;
		$db = database::instance('booking');
		$rows = $field === 'resource' ? $db->resource_in_use((string)$slug, date('Y-m-d')) : $db->service_in_use((string)$slug, date('Y-m-d'));
		return $rows && (int)$rows[0]['total'] > 0;
	}

	// ##Bookings

	// The fields a booking works out from the others, on every write: its
	// end (from the service, else its hours row), the resource when there
	// is only one, 1 person where no resource counts people, and when its
	// status or time last changed.
	static function fill($type, $after, $before) {
		if ($type !== 'booking') return array();
		$values = array();
		$resources = self::setup()['resources'];
		if ((string)$after['resource'] === '' && count($resources) === 1) $values['resource'] = $after['resource'] = (string)key($resources);
		if ($resources && !array_filter(array_column($resources, 'people'))) $values['people'] = $after['people'] = 1;
		$placed = !$before || self::changed($after, $before, array('date', 'time', 'resource', 'service'));
		if ($placed && (string)$after['time'] !== '') {
			$every = self::every((string)$after['date'], (string)$after['resource'], (string)$after['time']);
			$values['ends'] = self::clock(self::minutes($after['time']) + self::length($after['resource'], $after['service'], (int)$every));
		}
		$moved = $before && self::changed($after, $before, array('date', 'time'));
		if (!$before || $moved || $after['status'] !== $before['status']) $values['changed_at'] = date('Y-m-d H:i:s');
		// a guest's link works until the booking starts
		if ((!$before || $moved) && (string)$after['date'] !== '' && (string)$after['time'] !== '') $values['token_expires'] = $after['date'].' '.$after['time'].':00';
		if ($before && $placed && self::is_staff()) self::$moved[(int)$before['id']] = true;
		return $values;
	}

	// whether the write is staff's: the in-page editor or MCP, or the
	// model's own code unless it acts for a guest. Model code that writes
	// bookings itself is trusted like staff.
	static function is_staff() {
		$writer = cms_records::writer();
		return $writer === 'editor' || ($writer === 'model' && self::$acting !== 'guest');
	}

	protected static function changed($after, $before, $fields) {
		foreach ($fields as $field) {
			if ((string)$after[$field] !== (string)$before[$field]) return true;
		}
		return false;
	}

	// the every of a start time on that day, or null when it isn't one
	protected static function every($date, $resource, $time) {
		foreach (self::starts($date, $resource) as $start) {
			if ($start['time'] === $time) return $start['every'];
		}
		return null;
	}

	// Status changes, then the slot's rules, which run only when the
	// booking is made, moved (date, time, resource, service or people) or
	// confirmed: staff edit a phone number or a note of a booking that no
	// longer fits, and it needs attention instead (reason()).
	protected static function check_booking($after, $before) {
		if ($after === null) return array();
		$problems = array();
		$status = (string)$after['status'];
		$was = $before ? (string)$before['status'] : null;
		if ($before && $status !== $was) {
			// declined and cancelled are final; only a request is declined
			if ($was === 'declined') $problems[] = 'already_declined';
			elseif ($was === 'cancelled') $problems[] = 'already_cancelled';
			elseif ($status === 'declined' && $was !== 'requested') $problems[] = 'already_confirmed';
			if ($problems) return $problems;
		}
		if (!in_array($status, self::$active, true)) return array();
		if ($before && !self::changed($after, $before, array('date', 'time', 'resource', 'service', 'people')) && ($status !== 'confirmed' || $was === 'confirmed')) return array();
		$setup = self::setup();
		$staff = self::is_staff();
		if (!$setup['resources']) {
			$problems[] = 'not_open';
			return $problems;
		}
		$resource = (string)$after['resource'];
		if (!isset($setup['resources'][$resource])) $problems[] = 'resource_invalid';
		elseif (!$setup['resources'][$resource]['active']) $problems[] = 'resource_inactive';
		// the slug is made from the name, never from the email
		if (trim((string)$after['name']) === '') $problems[] = 'name_invalid';
		$email = trim((string)$after['email']);
		if (($email === '' && !$staff) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) $problems[] = 'email_invalid';
		if ($after['people'] < 1) $problems[] = 'people_invalid';
		if ($problems) return $problems;
		if (!$staff && $after['people'] > (int)config::get('booking_max_people', 12)) $problems[] = 'too_many';
		if ($problems) return $problems;
		$date = (string)$after['date'];
		$time = (string)$after['time'];
		$every = self::every($date, $resource, $time);
		$from = self::minutes($time);
		$to = $from + self::length($resource, $after['service'], (int)$every);
		if ($every === null || $to > 1440) $problems[] = 'not_a_slot';
		if ($problems) return $problems;
		if (self::service_problem($resource, (string)$after['service'])) $problems[] = 'service_unavailable';
		if ($problems) return $problems;
		if (!$staff) {
			// real seconds, as the slots count them
			$horizon = date('Y-m-d', strtotime(date('Y-m-d').' +'.(int)config::get('booking_horizon', 60).' days'));
			if (self::starts_at($date, $time) < time() + (int)config::get('booking_notice', 60) * 60) $problems[] = 'too_soon';
			elseif ($date > $horizon) $problems[] = 'too_far';
			if ($problems) return $problems;
			// a second click on Book
			if (!$before && self::booked($email, $date, $time, $resource)) $problems[] = 'already_booked';
			if ($problems) return $problems;
		}
		$limit_people = (int)$setup['resources'][$resource]['people'];
		$limit_bookings = (int)$setup['resources'][$resource]['bookings'];
		if ($limit_people > 0 || $limit_bookings > 0) {
			// the other bookings, from the database, inside the write's lock
			$rows = database::instance('booking')->peak($from, $date, $resource, (int)$after['id'], $from, $to, $date, $resource, (int)$after['id']);
			if (($limit_people > 0 && (int)$rows[0]['people'] + $after['people'] > $limit_people) || ($limit_bookings > 0 && (int)$rows[0]['bookings'] + 1 > $limit_bookings)) $problems[] = 'fully_booked';
		}
		return $problems;
	}

	// a service the booking can't have: none when the resource offers some,
	// or one it doesn't offer (or that is off)
	protected static function service_problem($resource, $service) {
		return $service === '' ? self::has_services($resource) : !self::offers($service, $resource);
	}

	// an active booking with this email for the same start
	protected static function booked($email, $date, $time, $resource) {
		foreach (cms_records::find('booking', array('email' => $email, 'date' => $date, 'time' => $time, 'resource' => $resource)) as $row) {
			if (in_array($row['status'], self::$active, true)) return true;
		}
		return false;
	}

	// Why an active booking no longer fits the setup, or '': closed,
	// resource_inactive, service_unavailable, not_a_slot or over_capacity
	// (some minute of it holds more than the limit; every booking there is
	// flagged). $taken are the day's active bookings of its resource, read
	// when not given. Worked out when bookings are listed, never stored.
	static function reason($booking, $taken = null) {
		if (!in_array($booking['status'], self::$active, true)) return '';
		$resources = self::setup()['resources'];
		$date = (string)$booking['date'];
		$resource = (string)$booking['resource'];
		$problems = array();
		if (self::is_closed($date, $resource)) $problems[] = 'closed';
		elseif (!isset($resources[$resource]) || !$resources[$resource]['active']) $problems[] = 'resource_inactive';
		elseif (self::service_problem($resource, (string)$booking['service'])) $problems[] = 'service_unavailable';
		if ($problems) return $problems[0];
		$every = self::every($date, $resource, (string)$booking['time']);
		$from = self::minutes($booking['time']);
		$to = self::minutes($booking['ends'], true);
		if ($every === null || $from + self::length($resource, $booking['service'], $every) > 1440) $problems[] = 'not_a_slot';
		if ($problems) return $problems[0];
		$busy = self::peak($taken === null ? self::taken($date, $resource) : $taken, $from, $to);
		$people = (int)$resources[$resource]['people'];
		$bookings = (int)$resources[$resource]['bookings'];
		if (($people > 0 && $busy['people'] > $people) || ($bookings > 0 && $busy['bookings'] > $bookings)) $problems[] = 'over_capacity';
		return $problems ? $problems[0] : '';
	}

	// the active bookings of a day and resource: id, time, ends, people
	protected static function taken($date, $resource) {
		if (!self::is_date($date) || !cms_store::table_exists('bookingdata')) return array();
		return database::instance('booking')->day_bookings($date, $resource);
	}

	// ##Actions and moves
	// Each reads the booking again inside the transaction, so one cancelled
	// a moment ago isn't confirmed from a page that still showed it.

	static function confirm($booking, $input) {
		return self::set_status($booking['id'], 'confirmed');
	}

	static function decline($booking, $input) {
		return self::set_status($booking['id'], 'declined');
	}

	static function cancel($booking, $input) {
		return self::set_status($booking['id'], 'cancelled');
	}

	protected static function set_status($id, $status) {
		$acting = self::$acting;
		self::$acting = 'staff';
		try {
			return cms_records::transaction(function () use ($id, $status) {
				$booking = cms_records::get('booking', $id);
				if (!$booking) throw new InvalidArgumentException("No booking $id");
				if ($booking['status'] === 'cancelled') cms_records::refuse('already_cancelled');
				if ($booking['status'] === 'declined') cms_records::refuse('already_declined');
				if ($booking['status'] === 'confirmed' && $status !== 'cancelled') cms_records::refuse('already_confirmed');
				// a booking that no longer fits is confirmed once it does again
				$reason = $status === 'confirmed' ? self::reason($booking) : '';
				if ($reason !== '') cms_records::refuse($reason);
				return cms_records::update('booking', $id, array('status' => $status));
			});
		} finally {
			self::$acting = $acting;
		}
	}

	// Moves a booking: date, time, resource, service and people from
	// $values. One write, so its old place is freed as the new one is
	// checked. A guest's move ($who 'guest') gets the guest rules and goes
	// back to requested; staff's keeps the status. The same place again
	// writes nothing.
	static function move($id, $values, $who) {
		$changes = array_intersect_key((array)$values, array_flip(array('date', 'time', 'resource', 'service', 'people')));
		$acting = self::$acting;
		self::$acting = $who === 'guest' ? 'guest' : 'staff';
		try {
			return cms_records::transaction(function () use ($id, $changes, $who) {
				$booking = cms_records::get('booking', $id);
				if (!$booking) throw new InvalidArgumentException("No booking $id");
				if (!self::changed($changes, array_intersect_key($booking, $changes), array_keys($changes))) return $booking;
				if ($who === 'guest') $changes['status'] = 'requested';
				return cms_records::update('booking', $id, $changes);
			});
		} finally {
			self::$acting = $acting;
		}
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
	// $now false is the day as staff see it, with neither notice nor horizon.
	static function day($date, $resource, $service = '', $people = 1, $taken = array(), $now = null) {
		if (!self::is_date($date)) return array();
		$resources = self::setup()['resources'];
		if (!isset($resources[$resource]) || !$resources[$resource]['active']) return array();
		if ((string)$service !== '' && !self::offers($service, $resource)) return array();
		$staff = $now === false;
		$now = $now === null || $staff ? time() : (int)$now;
		$today = date('Y-m-d', $now);
		if (!$staff && ($date < $today || $date > date('Y-m-d', strtotime("$today +".(int)config::get('booking_horizon', 60).' days')))) return array();
		$notice = (int)config::get('booking_notice', 60) * 60;
		$limit_people = (int)$resources[$resource]['people'];
		$limit_bookings = (int)$resources[$resource]['bookings'];
		$rows = array();
		foreach (self::starts($date, $resource) as $start) {
			$from = self::minutes($start['time']);
			$to = $from + self::length($resource, $service, $start['every']);
			if ($to > 1440 || (!$staff && self::starts_at($date, $start['time']) < $now + $notice)) continue;
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
		return self::day($options['date'], $chosen[0], $chosen[1], $chosen[2], self::taken($options['date'], $chosen[0]));
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
		// the bookings of every day it may look at, in one query
		$taken = array();
		if (cms_store::table_exists('bookingdata')) {
			foreach (database::instance('booking')->window_bookings($today, date('Y-m-d', strtotime("$today +$horizon days"))) as $row) {
				if ($row['resource'] === $chosen[0]) $taken[$row['date']][] = $row;
			}
		}
		$rows = array();
		for ($n = 0; $n <= $horizon && count($rows) < $limit; $n++) {
			$date = date('Y-m-d', strtotime("$today +$n days"));
			$free = 0;
			foreach (self::day($date, $chosen[0], $chosen[1], $chosen[2], isset($taken[$date]) ? $taken[$date] : array()) as $slot) {
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
	// The booking form. Shown first, it is filled from the URL (date,
	// time, resource, service, people: what a "pick a time" page links
	// with), and says not_open while there is nothing to book.
	function form() {
		if (!self::ready()) return false;
		$v = validation::get();
		if (!$v->submitted()) {
			$open = false;
			foreach (self::setup()['resources'] as $row) {
				if ($row['active']) $open = true;
			}
			if (!$open) $v->raise('not_open');
			$chosen = self::options('date=?date&time=?time&resource=?resource&service=?service&people=?people');
			return $chosen ? template::instance()->form_state($chosen) : false;
		}
		$acting = self::$acting;
		self::$acting = 'guest';
		try {
			return cms_records::submit('booking', 'booked');
		} finally {
			self::$acting = $acting;
		}
	}

	// ##For staff
	// Private wherever a page puts them: anyone but an editor gets nothing.

	// Days from from= (today) for days= (7): date, closed ('1'/'0'), the
	// slots with the places left (of resource=, or of the one resource),
	// and the active bookings, editable in place, each with attention,
	// resource_name and service_name.
	function agenda($options = 'from=today&days=7') {
		if (!self::ready() || !authentication::can('editor')) return array();
		$options = self::options($options);
		$from = isset($options['from']) && self::is_date($options['from']) ? $options['from'] : date('Y-m-d');
		$count = isset($options['days']) ? min(366, max(1, (int)$options['days'])) : 7;
		$until = date('Y-m-d', strtotime("$from +".($count - 1).' days'));
		$resources = self::setup()['resources'];
		$resource = isset($options['resource']) ? $options['resource'] : '';
		$active = array_keys(array_filter($resources, function ($row) { return $row['active']; }));
		$shown = $resource !== '' ? $resource : (count($active) === 1 ? $active[0] : '');
		$bookings = self::upcoming("date>=$from&date<=$until");
		$taken = self::by_place($bookings);
		$days = array();
		foreach ($bookings as $b) {
			if ($resource === '' || $b['resource'] === $resource) $days[$b['date']][] = $b;
		}
		$rows = array();
		for ($n = 0; $n < $count; $n++) {
			$date = date('Y-m-d', strtotime("$from +$n days"));
			$closed = $shown !== '' ? self::is_closed($date, $shown) : $active && !array_filter($active, function ($slug) use ($date) { return !self::is_closed($date, $slug); });
			$rows[] = array(
				'date' => $date,
				'closed' => $closed ? '1' : '0',
				'slots' => $shown !== '' ? self::day($date, $shown, '', 1, isset($taken["$date|$shown"]) ? $taken["$date|$shown"] : array(), false) : array(),
				'bookings' => self::for_staff(isset($days[$date]) ? $days[$date] : array(), $taken),
			);
		}
		return $rows;
	}

	// the active bookings to come that need attention, with the reason
	function attention() {
		if (!self::ready() || !authentication::can('editor')) return array();
		$bookings = self::upcoming('date>=today');
		$rows = array();
		foreach (self::for_staff($bookings, self::by_place($bookings)) as $row) {
			if ($row['attention'] !== '') $rows[] = $row;
		}
		return $rows;
	}

	// how many bookings match the options ('status=requested&date>=today',
	// values as cms_records::find takes them), or 'attention' for how many
	// need it: for a staff page's heading
	function count($options = 'status=requested') {
		if (!self::ready() || !authentication::can('editor')) return '0';
		if ($options === 'attention') return (string)count($this->attention());
		return (string)count(cms_records::find('booking', (string)$options));
	}

	// the active bookings matching list options, by date and time
	protected static function upcoming($options) {
		return cms_records::find('booking', $options.'&status!=cancelled&status!=declined&order=date,time');
	}

	// bookings by "date|resource", as reason() takes them
	protected static function by_place($bookings) {
		$taken = array();
		foreach ($bookings as $b) $taken[$b['date'].'|'.$b['resource']][] = $b;
		return $taken;
	}

	// bookings for a staff page: without the hidden fields, with why each
	// needs attention and its resource's and service's names, editable in
	// place (cms_records::listed)
	protected static function for_staff($bookings, $taken) {
		$setup = self::setup();
		$info = cms_records::info('booking');
		$rows = array();
		foreach ($bookings as $b) {
			$key = $b['date'].'|'.$b['resource'];
			$row = cms_records::shown($info, $b);
			$row['attention'] = self::reason($b, isset($taken[$key]) ? $taken[$key] : array());
			$row['resource_name'] = isset($setup['resources'][$b['resource']]) ? $setup['resources'][$b['resource']]['name'] : '';
			$row['service_name'] = isset($setup['services'][$b['service']]) ? $setup['services'][$b['service']]['name'] : '';
			$rows[] = $row;
		}
		return cms_records::listed('booking', $rows);
	}
}
