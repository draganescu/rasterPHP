<?php
// The café's bookings are the bundled booking model's (system/models/booking),
// with one field more: the "also send me the newsletter" box on /visit.
// cafe::subscribe_guest reads it from booking.requested, as a bool.
class the_booking extends booking
{
	static function types() {
		$types = parent::types();
		$types['booking']['fields']['newsletter'] = false;
		return $types;
	}
}
