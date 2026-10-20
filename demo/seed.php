<?php
// What the café takes bookings for, and two evenings that take replies,
// so a fresh demo has them: php demo/seed.php (after schema --apply in
// production)
//
// The Dining room seats 20 at two sittings, Tuesday to Sunday (no row for
// Monday, so Mondays have none), and is closed on 25 December. The salon
// corner has two stylists, Ana and Mihai, one booking at a time each, and
// two services. These are booking setup records: staff change them on
// /staff/setup. This goes through cms_records like the page editor does,
// so booking::check() looks at every one; running it again adds nothing.
//
// The evenings are items of the events collection (the rsvp model's): a
// jazz night with 40 places in two weeks, and a poetry evening with 4 in
// ten days, whose replies close the day before, so the waitlist is easy to
// see. An evening counts as there while one with its title is still to
// come; the mock-up's own Jazz night is long past.
if (PHP_SAPI !== 'cli') exit;
putenv('RASTER_APP=demo');
require_once dirname(__DIR__).'/system/boot.php';
boot::$appname = 'demo';
boot::cli();

// 25 December this year, or next year's once it has passed
$christmas = date('Y').'-12-25';
if ($christmas < date('Y-m-d')) $christmas = (date('Y') + 1).'-12-25';

$setup = array(
	'bookingresource' => array(
		array('name' => 'Dining room', 'people' => 20, 'bookings' => 0),
		array('name' => 'Ana', 'people' => 0, 'bookings' => 1),
		array('name' => 'Mihai', 'people' => 0, 'bookings' => 1),
	),
	'bookinghours' => array(
		array('resource' => 'dining-room', 'days' => 'tue,wed,thu,fri,sat,sun', 'starts' => '19:00', 'ends' => '21:00', 'every' => 120),
		array('resource' => 'ana', 'days' => 'tue,wed,thu,fri,sat', 'starts' => '10:00', 'ends' => '17:30', 'every' => 30),
		array('resource' => 'mihai', 'days' => 'wed,thu,fri,sat', 'starts' => '10:00', 'ends' => '17:30', 'every' => 30),
	),
	'bookingclosed' => array(
		array('date' => $christmas, 'note' => 'Christmas'),
	),
	'bookingservice' => array(
		array('name' => 'Cut', 'minutes' => 30, 'resources' => 'ana,mihai'),
		array('name' => 'Colour', 'minutes' => 90, 'resources' => 'ana'),
	),
);
// what tells two records of a type apart
$keys = array('bookingresource' => array('name'), 'bookinghours' => array('resource'), 'bookingclosed' => array('date'), 'bookingservice' => array('name'));
foreach ($setup as $type => $records) {
	foreach ($records as $record) {
		if (cms_records::find($type, array_intersect_key($record, array_flip($keys[$type])))) continue;
		cms_records::create($type, $record);
		echo "added $type ".reset($record)."\n";
	}
}

// the events table's columns, typed as schema --apply makes them (places an
// int), also when nobody has opened /events yet
require_once BASE.'tools/inspector.php';
$model = (new raster_inspector())->content_model();
$types = array_intersect_key(cms_types::$system, array_flip(array('slug', 'enabled', 'published_at', 'updated_at')));
foreach ($model['collections']['events']['fields'] as $name => $field) $types[$name] = $field['type'];
cms_store::connect();
cms_types::ensure('eventsdata', $types);

$evenings = array(
	array('title' => 'Jazz night', 'date' => date('Y-m-d', strtotime('+14 days')), 'starts' => '20:00', 'places' => 40,
		'summary' => '<p>The house trio, from eight.</p>', 'body' => '<p>Two sets of standards and a few of our own. Tell us you are coming, and how many.</p>'),
	array('title' => 'Poetry evening', 'date' => date('Y-m-d', strtotime('+10 days')), 'starts' => '19:00', 'places' => 4, 'rsvp_until' => date('Y-m-d', strtotime('+9 days')).' 18:00',
		'summary' => '<p>Four chairs by the window.</p>', 'body' => '<p>Bring a poem, or just listen. There are four chairs; when they are taken, join the waitlist.</p>'),
);
foreach ($evenings as $evening) {
	if (R::findOne('eventsdata', ' title = ? AND date >= ? ', array($evening['title'], date('Y-m-d')))) continue;
	cms_store::save_item('eventsdata', 0, $evening, array_keys($evening));
	echo "added event {$evening['title']}\n";
}
