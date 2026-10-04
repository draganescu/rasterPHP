<?php
// Table reservations and the contact form (views/cafe/visit.html).
// Rules for each field are in the form's HTML; messages are in the template.
//
// Reservations are records: this model declares the type and what makes a
// booking valid, and the CMS stores them, shows them on /staff (where the
// template renders render.cms.reservation) and lets staff edit them in the
// page or over MCP. Every write, whoever makes it, passes check().
class reservation
{
	// how many guests the café seats in a day
	const SEATS = 20;

	static function types() {
		return array('reservation' => array(
			'fields' => array(
				'name' => '', 'email' => '', 'phone' => '', 'date' => '', 'guests' => 0,
				'seating' => '', 'newsletter' => '', 'notes' => '', 'status' => 'new',
			),
			// anyone can book; staff see every booking, and a guest who was
			// logged in sees their own on /account
			'create' => 'visitor',
			// staff add the bookings they take over the phone on /staff
			'staff_add' => true,
			'owner' => true,
			// the status changes through the actions, not by typing
			'readonly' => array('status'),
			'actions' => array('confirm' => 'editor', 'cancel' => 'editor'),
		));
	}

	// a day holds SEATS guests; cancelled bookings free their seats
	static function check($type, $after, $before) {
		if ($after === null || $after['status'] === 'cancelled') return array();
		$problems = array();
		$taken = 0;
		foreach (cms_records::find('reservation', array('date' => $after['date'])) as $booking) {
			if ($booking['status'] === 'cancelled' || ($before && (int)$booking['id'] === (int)$before['id'])) continue;
			$taken += (int)$booking['guests'];
		}
		if ($taken + (int)$after['guests'] > self::SEATS) $problems[] = 'fully_booked';
		return $problems;
	}

	// staff.html, By evening: the coming evenings, each with its bookings
	// and the seats left. The bookings go through cms_records::listed, so
	// staff edit them here as in any render.cms list.
	function agenda() {
		$evenings = array();
		foreach (cms_records::find('reservation', array(), 'date,name') as $booking) {
			if ($booking['status'] === 'cancelled' || $booking['date'] < date('Y-m-d')) continue;
			$evenings[$booking['date']][] = $booking;
		}
		$rows = array();
		foreach (array_slice($evenings, 0, 7, true) as $date => $bookings) {
			$guests = array_sum(array_map(function ($b) { return (int)$b['guests']; }, $bookings));
			$rows[] = array(
				'date' => $date,
				'seats_left' => (string)max(0, self::SEATS - $guests),
				'bookings' => cms_records::listed('reservation', $bookings),
			);
		}
		return $rows;
	}

	// read again with the write, so a booking cancelled a moment ago is not
	// confirmed from a page that still showed it as new
	static function confirm($booking, $input) {
		return cms_records::transaction(function () use ($booking) {
			$booking = cms_records::get('reservation', $booking['id']);
			if ($booking['status'] === 'cancelled') cms_records::refuse('already_cancelled');
			return cms_records::update('reservation', $booking['id'], array('status' => 'confirmed'));
		});
	}

	static function cancel($booking, $input) {
		return cms_records::update('reservation', $booking['id'], array('status' => 'cancelled'));
	}

	// /api/reservation/day/<date>: one evening's bookings as JSON, for staff
	// only. Nothing else in this model is reachable over /api: not the form
	// handlers, not the listener below.
	static function api() {
		return array('day' => 'editor');
	}

	function day($date = '') {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) return array();
		return cms_records::find('reservation', array('date' => $date));
	}

	// saving a new booking tells the staff and the rest of the site
	static function listens() {
		return array('cms.item_saved' => 'booked');
	}

	function booked($saved) {
		if (!is_array($saved) || !isset($saved['collection']) || $saved['collection'] !== 'reservation' || empty($saved['created'])) return null;
		$booking = $saved['item'];
		mail::send_view('_email/reservation', config::get('cafe_staff_email', 'staff@cafe.test'), array(
			'name' => $booking['name'],
			'date' => $booking['date'],
			'guests' => (string)$booking['guests'],
			'notes' => $booking['notes'],
		));
		// other models react to a booking without this one knowing them
		// (cafe::subscribe_guest adds the guest to the newsletter)
		event::dispatch('reservation.booked', array(
			'name' => $booking['name'], 'email' => $booking['email'], 'date' => $booking['date'],
			'guests' => (int)$booking['guests'], 'newsletter' => $booking['newsletter'] === 'yes',
		));
		return null;
	}

	// fields: name, email, phone, date, guests, seating, newsletter, notes, terms
	function book() {
		$v = validation::get();
		if ($v->submitted() && !$v->valid()) {
			// validation::errors() lists what failed, field by field
			template::set('error_count')->to((string)count($v->errors()));
		}
		return cms_records::submit('reservation', 'booked');
	}

	// the contact form uses the rule names older Raster sites had
	function contact() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return template::instance()->form_state();
		mail::send_view('_email/contact', config::get('cafe_staff_email', 'staff@cafe.test'), array(
			'email' => (string)util::post('email'),
			'message' => (string)util::post('message'),
		));
		util::done('contacted');
		return false;
	}
}
