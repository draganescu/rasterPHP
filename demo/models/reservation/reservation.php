<?php
// Table reservations and the contact form (views/cafe/visit.html).
// Rules are in the form's HTML; messages are in the template.
class reservation
{
	// the tables this model writes, so `raster schema --apply` creates them
	static function schema() {
		return array('reservation' => array(
			'name' => '', 'email' => '', 'phone' => '', 'date' => '', 'guests' => 0,
			'seating' => '', 'newsletter' => '', 'notes' => '', 'created_at' => '',
		));
	}

	// fields: name, email, phone, date, guests, seating, newsletter, notes, terms
	function book() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) {
			// validation::errors() lists what failed, field by field
			template::set('error_count')->to((string)count($v->errors()));
			return template::instance()->form_state();
		}
		database::instance('cms');
		$booking = R::dispense('reservation');
		foreach (array('name', 'email', 'phone', 'date', 'seating', 'notes') as $field) {
			$booking->$field = trim((string)util::post($field));
		}
		$booking->guests = (int)util::post('guests');
		$booking->newsletter = util::post('newsletter') ? 'yes' : 'no';
		$booking->created_at = R::isoDateTime();
		R::store($booking);
		mail::send_view('_email/reservation', config::get('cafe_staff_email', 'staff@cafe.test'), array(
			'name' => $booking->name,
			'date' => $booking->date,
			'guests' => (string)$booking->guests,
			'notes' => $booking->notes,
		));
		// other models react to a booking without this one knowing them
		// (cafe::subscribe_guest adds the guest to the newsletter)
		event::dispatch('reservation.booked', array(
			'name' => $booking->name, 'email' => $booking->email, 'date' => $booking->date,
			'guests' => (int)$booking->guests, 'newsletter' => $booking->newsletter === 'yes',
		));
		util::done('booked');
		return false;
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

	// for staff: the latest reservations
	function latest() {
		database::instance('cms');
		if (!in_array('reservation', R::inspect())) return array();
		$rows = array();
		foreach (R::find('reservation', ' ORDER BY id DESC LIMIT 20 ') as $booking) {
			$rows[] = array(
				'name' => util::e($booking->name),
				'date' => util::e($booking->date),
				'guests' => (string)(int)$booking->guests,
				'notes' => util::e($booking->notes),
			);
		}
		return $rows;
	}
}
