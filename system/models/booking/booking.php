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
*   <!-- render.booking.manage -->  the guest's page, by the link in their
*     emails (?token=) or, logged in, ?booking=<id>: the booking, and forms
*     posting do=cancel, or do=change with a time (the day from ?date=, its
*     times from render.booking.slots('date=?date&for=manage'))
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
* resource_inactive, service_unavailable, not_a_slot, over_capacity). On the
* manage page: link_invalid, too_late, and cancelled or rescheduled after.
*
* Emails, views the site writes: _email/booking_requested, booking_confirmed,
* booking_declined, booking_cancelled and booking_changed to the guest (with
* print.self.manage_url, their link), booking_new and booking_cancelled to
* staff when the guest acted. Events, after the commit: booking.requested,
* booking.confirmed, booking.declined, booking.cancelled, booking.rescheduled
* (the booking's fields and who: guest or staff).
*
* Settings:
*   config::set('booking_notice')->to(60);      minutes ahead a guest may book
*   config::set('booking_horizon')->to(60);     days ahead a guest may book
*   config::set('booking_max_people')->to(12);  people in a guest's booking
*   config::set('booking_cutoff')->to(120);     minutes before the start a guest may still cancel or change
*   config::set('booking_page')->to('book');    the form's page, for book_url in emails
*   config::set('booking_manage_page')->to('booking');  the guest's page their links open
*   config::set('booking_staff_email')->to('staff@example.com');  else the sender
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
	// the guest's new links, id => raw token: this request only, for the
	// model's own email listener, never stored, logged or sent with an event
	static $links = array();
	// while the model makes its own writes (the form, the actions, moves):
	// saved() leaves those alone, and fill() marks none of them moved
	static $writing = false;
	// the booking the manage page is about, by its ?token= or ?booking=
	static $managed = array();

	static function types() {
		$types = array(
			// a resource's and a service's name have a column per language
			// on a multilingual site (#200 D7); nothing else here does
			'bookingresource' => array(
				'fields' => array('name' => '', 'people' => 0, 'bookings' => 0, 'active' => true),
				'translate' => array('name'),
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
				'translate' => array('name'),
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
		// the guest's language, which their emails go out in: only a
		// multilingual site's; staff and agents see it, and only the
		// model writes it (#200 D13)
		if (i18n::enabled()) {
			$types['booking']['fields']['lang'] = '';
			$types['booking']['readonly'][] = 'lang';
		}
		return $types;
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
	// end (from the service, else its hours row), the resource when only
	// one is active, 1 person where no active resource counts people, and
	// when its status or time last changed.
	static function fill($type, $after, $before) {
		if ($type !== 'booking') return array();
		// fill() comes first in the write's lock: the setup is read there, as
		// it is now, for fill() and check() (a long-lived process, like MCP
		// over stdio, or a page that showed slots first, read it before)
		self::forget();
		$values = array();
		$resources = self::setup()['resources'];
		// only active resources count, as in chosen(): a resource switched
		// off takes no new bookings. A site's one resource is still filled
		// when it is off, so check() says resource_inactive.
		$active = array_filter($resources, function ($row) { return $row['active']; });
		$only = count($active) === 1 ? $active : (count($resources) === 1 ? $resources : array());
		if ((string)$after['resource'] === '' && $only) $values['resource'] = $after['resource'] = (string)key($only);
		// a booking kept on a switched-off resource that counts people
		// keeps its people
		$own = isset($resources[(string)$after['resource']]) ? (int)$resources[(string)$after['resource']]['people'] : 0;
		if ($active && !array_filter(array_column($active, 'people')) && !$own) $values['people'] = $after['people'] = 1;
		$placed = !$before || self::changed($after, $before, array('date', 'time', 'resource', 'service'));
		if ($placed && (string)$after['time'] !== '') {
			$every = self::every((string)$after['date'], (string)$after['resource'], (string)$after['time']);
			$values['ends'] = self::clock(self::minutes($after['time']) + self::length($after['resource'], $after['service'], (int)$every));
		}
		$moved = $before && self::changed($after, $before, array('date', 'time'));
		if (!$before || $moved || $after['status'] !== $before['status']) $values['changed_at'] = date('Y-m-d H:i:s');
		// a guest's link works until the booking starts
		if ((!$before || $moved) && (string)$after['date'] !== '' && (string)$after['time'] !== '') $values['token_expires'] = $after['date'].' '.$after['time'].':00';
		// links sent to the old address stop working when staff change it:
		// whoever got one by a typo can't open the booking any more
		if ($before && strtolower(trim((string)$after['email'])) !== strtolower(trim((string)$before['email']))) $values['token_hashes'] = array();
		// a move the model makes itself (move()) has its link and event
		// already: only staff's own edits are left for saved(), which may run
		// after writing() is done, at the commit of a transaction around it
		if ($before) unset(self::$moved[(int)$before['id']]);
		if ($before && $placed && self::is_staff() && !self::$writing) self::$moved[(int)$before['id']] = true;
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
		return self::set_status($booking['id'], 'confirmed', 'staff');
	}

	static function decline($booking, $input) {
		return self::set_status($booking['id'], 'declined', 'staff');
	}

	static function cancel($booking, $input) {
		return self::set_status($booking['id'], 'cancelled', 'staff');
	}

	// A status change and its event (booking.confirmed, …) in one
	// transaction. A confirmed guest gets a new link with it; a declined or
	// cancelled booking's links are dead, so its email has none. A guest
	// ($who 'guest', from the manage page) may only cancel, before the cutoff.
	protected static function set_status($id, $status, $who) {
		return self::writing($who, $id, function () use ($id, $status, $who) {
			$booking = cms_records::get('booking', $id);
			if (!$booking) throw new InvalidArgumentException("No booking $id");
			if ($who === 'guest') self::guest_may($booking);
			if ($booking['status'] === 'cancelled') cms_records::refuse('already_cancelled');
			if ($booking['status'] === 'declined') cms_records::refuse('already_declined');
			if ($booking['status'] === 'confirmed' && $status !== 'cancelled') cms_records::refuse('already_confirmed');
			// a booking that no longer fits is confirmed once it does again
			$reason = $status === 'confirmed' ? self::reason($booking) : '';
			if ($reason !== '') cms_records::refuse($reason);
			$values = array('status' => $status);
			if ($status === 'confirmed') $values += self::issue($booking);
			$saved = cms_records::update('booking', $id, $values);
			// each name written out, so lint and the vocabulary know who sends it
			$payload = self::payload($saved, $who);
			if ($status === 'confirmed') cms_records::dispatch('booking.confirmed', $payload);
			elseif ($status === 'declined') cms_records::dispatch('booking.declined', $payload);
			else cms_records::dispatch('booking.cancelled', $payload);
			return $saved;
		});
	}

	// Runs the model's own write $work for $who ('guest' or 'staff') in one
	// transaction, so its events wait for the commit. A link issued for a
	// write that didn't happen is dropped.
	protected static function writing($who, $id, $work) {
		$acting = self::$acting;
		$writing = self::$writing;
		self::$acting = $who === 'guest' ? 'guest' : 'staff';
		self::$writing = true;
		try {
			return cms_records::transaction($work);
		} catch (Throwable $e) {
			unset(self::$links[(int)$id]);
			throw $e;
		} finally {
			self::$acting = $acting;
			self::$writing = $writing;
		}
	}

	// what a guest's link may still do: nothing once the booking is over or
	// gone (link_invalid), nor within booking_cutoff minutes of its start,
	// when the page tells them to phone (too_late)
	protected static function guest_may($booking) {
		if (!self::is_live($booking)) cms_records::refuse('link_invalid');
		if (self::is_late($booking)) cms_records::refuse('too_late');
	}

	// Moves a booking: date, time, resource, service and people from
	// $values. One write, so its old place is freed as the new one is
	// checked. A guest's move ($who 'guest') gets the guest rules and goes
	// back to requested; staff's keeps the status. The same place again
	// writes nothing.
	static function move($id, $values, $who) {
		$changes = array_intersect_key((array)$values, array_flip(array('date', 'time', 'resource', 'service', 'people')));
		return self::writing($who, $id, function () use ($id, $changes, $who) {
			$booking = cms_records::get('booking', $id);
			if (!$booking) throw new InvalidArgumentException("No booking $id");
			if ($who === 'guest') self::guest_may($booking);
			if (!self::changed($changes, array_intersect_key($booking, $changes), array_keys($changes))) return $booking;
			if ($who === 'guest') $changes['status'] = 'requested';
			// the guest's links follow the booking to its new start
			if (in_array($booking['status'], self::$active, true)) $changes += self::issue(array_merge($booking, $changes));
			$saved = cms_records::update('booking', $id, $changes);
			if (in_array($saved['status'], self::$active, true)) cms_records::dispatch('booking.rescheduled', self::payload($saved, $who));
			return $saved;
		});
	}

	// ##The guest's link
	// Each email to the guest carries a new link to manage the booking:
	// 32 random bytes, sent only in that email. The booking keeps the sha256
	// of the newest 20 (token_hashes), so the first email's link still works
	// after the confirmation. A link lasts until the booking starts
	// (token_expires) and dies once it is declined or cancelled. Raster has
	// no site secret to sign links with, so they are random.

	// The values to write with a change so the guest gets a new link
	// (token_hashes, token_expires), or array() when the booking has no
	// email. The raw token waits in self::$links for the email listener.
	static function issue($booking) {
		if (trim((string)$booking['email']) === '') return array();
		$raw = bin2hex(random_bytes(32));
		$hashes = isset($booking['token_hashes']) ? array_values((array)$booking['token_hashes']) : array();
		$hashes[] = hash('sha256', $raw);
		self::$links[(int)$booking['id']] = $raw;
		return array(
			'token_hashes' => array_slice($hashes, -20),
			'token_expires' => $booking['date'].' '.self::clock(self::minutes($booking['time'])).':00',
		);
	}

	// the booking a link is for, or null: a malformed or unknown token, a
	// link past the booking's start, or a booking declined or cancelled
	static function by_token($raw) {
		if (!is_string($raw) || !preg_match('/^[a-f0-9]{64}$/', $raw) || !self::ready() || !cms_store::table_exists('bookingdata')) return null;
		$hash = hash('sha256', $raw);
		foreach (database::instance('booking')->by_token('%'.$hash.'%') as $row) {
			$hashes = json_decode((string)$row['token_hashes'], true);
			foreach (is_array($hashes) ? $hashes : array() as $kept) {
				if (!is_string($kept) || !hash_equals($kept, $hash)) continue;
				$booking = cms_records::get('booking', $row['id']);
				return $booking && self::is_live($booking) ? $booking : null;
			}
		}
		return null;
	}

	// a booking a guest can still reach: active and not started
	protected static function is_live($booking) {
		if (!in_array($booking['status'], self::$active, true)) return false;
		$expires = (string)$booking['token_expires'] !== '' ? strtotime((string)$booking['token_expires']) : self::starts_at($booking['date'], self::clock(self::minutes($booking['time'])));
		return $expires !== false && $expires > time();
	}

	// within booking_cutoff minutes of the start, in real minutes
	protected static function is_late($booking) {
		return self::starts_at($booking['date'], self::clock(self::minutes($booking['time']))) < time() + (int)config::get('booking_cutoff', 120) * 60;
	}

	// The booking the manage page shows: the one ?token= is for, or the
	// logged in guest's own by ?booking=<id>. Null when neither; read once
	// per request (forget() reads it again).
	static function managed() {
		$token = util::get('token');
		$id = util::get('booking');
		$key = is_string($token) && $token !== '' ? "token:$token" : (is_string($id) && $id !== '' ? "booking:$id" : '');
		if ($key === '' || !self::ready()) return null;
		if (array_key_exists($key, self::$managed)) return self::$managed[$key];
		$booking = null;
		if (strpos($key, 'token:') === 0) {
			$booking = self::by_token($token);
		} elseif (ctype_digit($id) && cms_store::table_exists('bookingdata')) {
			$me = cms_records::owner_me(cms_records::info('booking'));
			$found = $me === null ? null : cms_records::get('booking', (int)$id);
			if ($found && (string)$found['owner'] === $me && self::is_live($found)) $booking = $found;
		}
		return self::$managed[$key] = $booking;
	}

	// what an event or an email gets of a booking: every field but the
	// hidden ones, and who made the change (guest or staff)
	static function payload($booking, $who) {
		return cms_records::shown(cms_records::info('booking'), $booking) + array('who' => $who === 'guest' ? 'guest' : 'staff');
	}

	// ##Emails and events
	// The model's listeners (system/config/events.php) send the emails; a
	// site unbinds one in config/the_events.php to send its own:
	//   event::unbind('booking.confirmed')->from('booking', 'confirmed_mail');

	// cms.item_saved: a booking staff added or moved in the page or over MCP
	// gets its guest a link and its event (who: staff). Staff aren't
	// emailed about their own bookings.
	function saved($saved) {
		if (self::$writing || !is_array($saved) || !isset($saved['collection'], $saved['item']['id']) || $saved['collection'] !== 'booking' || !self::ready()) return null;
		$id = (int)$saved['item']['id'];
		$moved = isset(self::$moved[$id]);
		unset(self::$moved[$id]);
		if (!$saved['created'] && !$moved) return null;
		self::writing('staff', $id, function () use ($id, $saved) {
			$booking = cms_records::get('booking', $id);
			if (!$booking || !in_array($booking['status'], self::$active, true)) return;
			$values = self::issue($booking);
			if ($values) $booking = cms_records::update('booking', $id, $values);
			cms_records::dispatch($saved['created'] ? 'booking.requested' : 'booking.rescheduled', self::payload($booking, 'staff'));
		});
		return null;
	}

	function requested_mail($booking) {
		return self::mail($booking, 'booking_requested', 'booking_new');
	}

	function confirmed_mail($booking) {
		return self::mail($booking, 'booking_confirmed', null);
	}

	function declined_mail($booking) {
		return self::mail($booking, 'booking_declined', null);
	}

	function cancelled_mail($booking) {
		return self::mail($booking, 'booking_cancelled', 'booking_cancelled');
	}

	function rescheduled_mail($booking) {
		return self::mail($booking, 'booking_changed', 'booking_new');
	}

	// The guest's email, with their new link when the change made one, and
	// staff's when the guest made the change. The views get the booking's
	// fields, manage_url, resource_name, service_name, site_url and book_url.
	protected static function mail($booking, $guest, $staff) {
		if (!is_array($booking) || !isset($booking['id']) || !self::ready()) return null;
		$id = (int)$booking['id'];
		$setup = self::setup();
		$vars = $booking;
		$vars['manage_url'] = isset(self::$links[$id]) ? util::url(config::get('booking_manage_page', 'booking'), array('token' => self::$links[$id])) : '';
		unset(self::$links[$id]);
		$vars['resource_name'] = self::name_of($setup, 'resources', $booking['resource']);
		$vars['service_name'] = self::name_of($setup, 'services', $booking['service']);
		$vars['site_url'] = util::url('');
		$vars['book_url'] = util::url(config::get('booking_page', 'book'));
		if (trim((string)$booking['email']) !== '') self::send('_email/'.$guest, $booking['email'], $vars);
		if (!$staff || !isset($booking['who']) || $booking['who'] !== 'guest') return null;
		$vars['manage_url'] = '';
		$to = trim((string)config::get('booking_staff_email', ''));
		if ($to === '') $to = trim((string)(getenv('RASTER_MAIL_FROM') ?: config::get('mail_from', '')));
		if ($to === '') log::warning('booking: no address for staff emails: set booking_staff_email or mail_from');
		else self::send('_email/'.$staff, $to, $vars);
		return null;
	}

	// a booking stays made when its email can't go: the failure is logged,
	// without the address or the link
	protected static function send($view, $to, $vars) {
		$mail = controller::model_class('mail');
		try {
			if ($mail::send_view($view, $to, $vars)) return true;
		} catch (Exception $e) {
			log::error("booking: $view: ".$e->getMessage());
			return false;
		}
		log::error("booking: $view was not sent");
		return false;
	}

	// ##The setup

	// the bundled types are declared: a site whose own model or type is
	// called booking keeps the name (cms_records::types), and then every
	// region here gives nothing
	static function ready() {
		$info = cms_records::info('bookingresource');
		return $info !== null && $info['model'] === 'booking';
	}

	// what a site taking bookings needs: the emails' views, the manage page
	// (left out of the sitemap and an export), the settings, and at least
	// one resource before the form can take a booking
	static function requires() {
		$emails = array('_email/booking_requested', '_email/booking_new', '_email/booking_confirmed', '_email/booking_declined', '_email/booking_cancelled', '_email/booking_changed');
		return array(
			'emails' => array(
				'render.booking.form' => $emails,
				'render.booking.manage' => array('_email/booking_cancelled', '_email/booking_changed', '_email/booking_new'),
			),
			'pages' => array(config::get('booking_manage_page', 'booking')),
			'settings' => array('booking_page', 'booking_manage_page', 'booking_staff_email', 'booking_notice', 'booking_horizon', 'booking_cutoff', 'booking_max_people'),
			'records' => array('render.booking.form' => array('bookingresource')),
		);
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
		self::$managed = array();
	}

	// a setup row ($group 'resources' or 'services') as it reads in the
	// current language: its translated name, or the default language's
	// while it has none. setup() keeps every column, so it is read once
	// for every language.
	protected static function localized_row($group, $row) {
		$info = cms_records::info($group === 'resources' ? 'bookingresource' : 'bookingservice');
		return $info ? cms_store::localized($row, $info['type']) : $row;
	}

	// the name of the resource or service $slug names, in the current
	// language, or '' when it names none
	protected static function name_of($setup, $group, $slug) {
		if (!isset($setup[$group][$slug])) return '';
		$row = self::localized_row($group, $setup[$group][$slug]);
		return $row['name'];
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
		if (isset($options['for']) && $options['for'] === 'manage') {
			// the managed booking's own resource, service and people, and its
			// place not counted against it
			$booking = self::managed();
			if (!$booking || !isset($options['date'])) return array();
			$taken = array();
			foreach (self::taken($options['date'], $booking['resource']) as $row) {
				if ((int)$row['id'] !== (int)$booking['id']) $taken[] = $row;
			}
			return self::day($options['date'], $booking['resource'], $booking['service'], $booking['people'], $taken);
		}
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
			$rows[] = self::localized_row('resources', $row);
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
			$rows[] = self::localized_row('services', $row);
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
		// the booking, its link and booking.requested go together
		$result = self::writing('guest', 0, function () {
			$item = cms_records::submit('booking', false);
			if (!is_array($item)) return $item;
			$booking = cms_records::get('booking', $item['id']);
			$values = self::issue($booking);
			if ($values) $booking = cms_records::update('booking', $booking['id'], $values);
			cms_records::dispatch('booking.requested', self::payload($booking, 'guest'));
			return true;
		});
		if ($result !== true) return $result;
		util::done('booked');
		return false;
	}

	// The guest's manage page, by the link in their emails (?token=) or,
	// logged in, by ?booking=<id>: the booking without its hidden fields,
	// with resource_name and service_name. A GET changes nothing. Its form
	// posts do=cancel, or do=change with the new time (and the day from
	// ?date=, as render.booking.slots('date=?date&for=manage') lists it).
	// Alerts: link_invalid, too_late; cancelled and rescheduled after the
	// change, on the page without the token.
	function manage() {
		// the link is a key: no cache keeps it, and no Referer takes it along
		raster_cache::skip();
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('Referrer-Policy: same-origin');
		if (!self::ready()) return array();
		$v = validation::get();
		$booking = self::managed();
		if (!$booking) {
			// after a change the page has ?done= and no link any more
			if (!is_string(util::get('done')) || util::get('done') === '') $v->raise('link_invalid');
			return array();
		}
		// back to this page without the token (the owner's keeps ?booking=)
		$back = config::get('link_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/');
		if (!is_string(util::get('token')) || util::get('token') === '') $back .= '?booking='.(int)$booking['id'];
		if ($v->submitted()) {
			try {
				if (util::post('do') === 'cancel') {
					self::set_status($booking['id'], 'cancelled', 'guest');
					return self::manage_done('cancelled', $back);
				}
				if (util::post('do') === 'change') {
					$date = util::get('date') ?: (util::post('date') ?: $booking['date']);
					$moved = self::move($booking['id'], array('date' => (string)$date, 'time' => (string)util::post('time')), 'guest');
					if ($moved['date'] !== $booking['date'] || $moved['time'] !== $booking['time']) return self::manage_done('rescheduled', $back);
				}
			} catch (cms_refused $e) {
				foreach ($e->problems as $problem) $v->raise($problem);
			}
			$booking = cms_records::get('booking', $booking['id']);
		} elseif (self::is_late($booking)) {
			$v->raise('too_late');
		}
		$setup = self::setup();
		$row = cms_records::shown(cms_records::info('booking'), $booking);
		$row['resource_name'] = self::name_of($setup, 'resources', $booking['resource']);
		$row['service_name'] = self::name_of($setup, 'services', $booking['service']);
		return array(cms_records::for_template(cms_records::info('booking'), $row));
	}

	// util::done exits in a request; on the command line the page shows
	// nothing more
	protected static function manage_done($done, $back) {
		self::forget();
		util::done($done, $back);
		return array();
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
			$row['resource_name'] = self::name_of($setup, 'resources', $b['resource']);
			$row['service_name'] = self::name_of($setup, 'services', $b['service']);
			$rows[] = $row;
		}
		return cms_records::listed('booking', $rows);
	}
}
