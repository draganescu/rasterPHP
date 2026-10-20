<?php
// What the café takes bookings for, so a fresh demo can take them:
// php demo/seed.php (after schema --apply in production)
//
// The Dining room seats 20 at two sittings, Tuesday to Sunday (no row for
// Monday, so Mondays have none), and is closed on 25 December. The salon
// corner has two stylists, Ana and Mihai, one booking at a time each, and
// two services. These are booking setup records: staff change them on
// /staff/setup. This goes through cms_records like the page editor does,
// so booking::check() looks at every one; running it again adds nothing.
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
