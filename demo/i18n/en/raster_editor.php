<?php
// The in-page editor in the café's words: the booking buttons on /staff, and
// what a refusal says (problem_<name>; without one the editor shows the name).
return array(
	'action_confirm' => 'Confirm booking', 'action_decline' => 'Decline', 'action_cancel' => 'Cancel booking',
	'problem_closed' => 'We are closed that day: move the booking or cancel it',
	'problem_not_a_slot' => 'That is no longer one of the times: move the booking',
	'problem_over_capacity' => 'Too many people at that time: move one of the bookings',
	'problem_resource_inactive' => 'That is switched off on the setup page',
	'problem_service_unavailable' => 'That service is not offered there any more',
	'problem_fully_booked' => 'Full at that time',
	'problem_already_confirmed' => 'Already confirmed', 'problem_already_declined' => 'Already declined', 'problem_already_cancelled' => 'Already cancelled',
	'problem_resource_in_use' => 'Bookings to come use it: keep its slug, or move them first',
	'problem_service_in_use' => 'Bookings to come use it: keep its slug, or move them first',
);
