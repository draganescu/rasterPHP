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
//
// The opening hours on /about are items of the hours collection: closed on
// Monday, 8 to 18 Tuesday to Friday, 9 to 16 at the weekend. Editors change
// them on the page; once they have, seeding again leaves the list alone.
//
// Then the Romanian (the café is multilingual): the pages, the site fields
// and the lists' items get the Romanian of the English they still have,
// from the phrase book at the end; run it again on a café that has been
// open a while and it adds the Romanian of what is there, never over what
// an editor wrote.
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

// the hours table as the about page makes it, also when nobody has opened
// /about yet. Only a list nobody has edited is filled: none, or the one row
// the page stores from its mock-up (Monday, whose state it reads as the
// mock-up's class, "day", and the seed sets to closed)
$types = array_intersect_key(cms_types::$system, array_flip(array('slug', 'enabled', 'published_at', 'updated_at')));
foreach ($model['collections']['hours']['fields'] as $name => $field) $types[$name] = $field['type'];
cms_types::ensure('hoursdata', $types);
$hours = array(
	array('day' => 'Monday', 'time' => 'closed', 'state' => 'closed'),
	array('day' => 'Tuesday to Friday', 'time' => '8:00 to 18:00', 'state' => 'open'),
	array('day' => 'Weekend', 'time' => '9:00 to 16:00', 'state' => 'open'),
);
$mockup = R::count('hoursdata') === 1 ? R::findOne('hoursdata', ' day = ? AND time = ? AND state = ? ', array('Monday', 'closed', 'day')) : null;
if (R::count('hoursdata') === 0 || $mockup) {
	foreach ($hours as $row) {
		if ($mockup && $row['day'] === 'Monday') {
			// its slug came from the mock-up's class (day)
			cms_store::save_item('hoursdata', (int)$mockup->id, $row + array('slug' => 'monday'), array_keys($row));
			continue;
		}
		cms_store::save_item('hoursdata', 0, $row, array_keys($row));
		echo "added hours {$row['day']}\n";
	}
}

// The Romanian (the café is multilingual: English at /menu, Romanian at
// /ro/menu). Each translated field of the pages, the site and the lists'
// items gets its Romanian from the phrase book below, when its English is
// one the book knows and its Romanian is still empty: a field an editor
// translated, or whose English an editor changed, is left alone, and so
// is an item the book doesn't know. The items' Romanian addresses
// (slug__ro) come from their Romanian titles. Seeding again adds nothing.
$romanian = array(
	// the site and the pages
	'A small café in București: good coffee, cake and quiet corners.' => 'O cafenea mică în București: cafea bună, prăjituri și colțuri liniștite.',
	'Raster Café' => 'Raster Café',
	'Coffee, cake and quiet corners since 2014.' => 'Cafea, prăjituri și colțuri liniștite din 2014.',
	'Strada Exemplu 1, București' => 'Strada Exemplu 1, București',
	'Good coffee, slow mornings.' => 'Cafea bună, dimineți fără grabă.',
	'<p>A small café where the menu, the events and this very sentence are edited in the page.</p>' => '<p>O cafenea mică unde meniul, evenimentele și chiar fraza aceasta se schimbă direct în pagină.</p>',
	'About us' => 'Despre noi',
	'<p>Two friends, one espresso machine and a lot of patience.</p>' => '<p>Doi prieteni, un espressor și multă răbdare.</p>',
	'How this site was set up' => 'Cum a fost făcut acest site',
	'Events' => 'Evenimente',
	'Questions' => 'Întrebări',
	'Journal' => 'Jurnal',
	'Menu' => 'Meniu',
	'<p>Ana and Mihai cut hair upstairs, one guest at a time. A cut takes 30 minutes, a colour 90 (Ana only). Pick who, what and a day, then a time.</p>' => '<p>Ana și Mihai tund la etaj, câte un client pe rând. O tunsoare durează 30 de minute, o vopsire 90 (doar Ana). Alege cine, ce și o zi, apoi o oră.</p>',
	'<p>Two sittings, at 19:00 and 21:00, Tuesday to Sunday. Pick a day, then a time. A booking is a request until we confirm it, and every email we send has a link to change or cancel it.</p>' => '<p>Două ture, la 19:00 și la 21:00, de marți până duminică. Alege o zi, apoi o oră. O rezervare e o cerere până o confirmăm, iar fiecare email pe care-l trimitem are un link ca s-o schimbi sau s-o anulezi.</p>',
	// the menu
	'Flat white' => 'Cafea cu lapte',
	'Double ristretto, steamed milk.' => 'Ristretto dublu, lapte spumat.',
	'Espresso' => 'Espresso',
	'One shot, thick crema, gone in three sips.' => 'O doză, cremă groasă, gata din trei sorbituri.',
	'Cortado' => 'Cortado',
	'Equal parts espresso and warm milk, in glass.' => 'Espresso și lapte cald în părți egale, în pahar.',
	'Filter of the week' => 'Cafeaua la filtru a săptămânii',
	'Whatever the roaster sent us on Monday.' => 'Ce ne-a trimis prăjitorul luni.',
	'Cold brew' => 'Cafea la rece',
	'Eighteen hours in the fridge, served over ice.' => 'Optsprezece ore în frigider, servită cu gheață.',
	'Cheesecake' => 'Cheesecake',
	'Baked, not set. Lemon zest in the crust.' => 'Copt, nu închegat. Coajă de lămâie în blat.',
	'Carrot cake' => 'Prăjitură cu morcovi',
	'Walnuts, cream cheese, an honest amount of cinnamon.' => 'Nuci, cremă de brânză și scorțișoară cât trebuie.',
	'Poppy seed roll' => 'Cozonac cu mac',
	'Sunday\'s recipe, made on Saturday night.' => 'Rețeta de duminică, făcută sâmbătă seara.',
	'Almond croissant' => 'Croissant cu migdale',
	'Yesterday\'s croissant, which is the point.' => 'Croissantul de ieri, și tocmai asta e ideea.',
	'Sencha' => 'Sencha',
	'Green, grassy, brewed at seventy degrees.' => 'Verde, ierbos, infuzat la șaptezeci de grade.',
	'Mint infusion' => 'Ceai de mentă',
	'Fresh leaves, hot water, nothing else.' => 'Frunze proaspete, apă fierbinte, nimic altceva.',
	'coffee' => 'cafea',
	'cakes' => 'prăjituri',
	'tea' => 'ceai',
	// the events, the mock-up's and the two above
	'Jazz night' => 'Seară de jazz',
	'<p>Live trio from eight.</p>' => '<p>Trio live de la opt.</p>',
	'<p>Details about the evening.</p>' => '<p>Despre seara aceasta.</p>',
	'<p>The house trio, from eight.</p>' => '<p>Trioul casei, de la opt.</p>',
	'<p>Two sets of standards and a few of our own. Tell us you are coming, and how many.</p>' => '<p>Două seturi de standarde și câteva piese de-ale noastre. Spune-ne că vii și câți sunteți.</p>',
	'Poetry evening' => 'Seară de poezie',
	'<p>Four chairs by the window.</p>' => '<p>Patru scaune lângă fereastră.</p>',
	'<p>Bring a poem, or just listen. There are four chairs; when they are taken, join the waitlist.</p>' => '<p>Adu o poezie sau doar ascultă. Sunt patru scaune; când se ocupă, intră pe lista de așteptare.</p>',
	'Cupping: Ethiopia' => 'Degustare: Etiopia',
	'<p>Five lots side by side. Bring a notebook.</p>' => '<p>Cinci loturi una lângă alta. Adu un carnețel.</p>',
	'<p>We line up five washed Ethiopian lots and taste them blind. Free, twelve seats.</p>' => '<p>Punem în rând cinci loturi etiopiene spălate și le gustăm pe nevăzute. Gratuit, douăsprezece locuri.</p>',
	'Latte art, beginners' => 'Latte art pentru începători',
	'<p>Two hours, one jug each, a lot of wasted milk.</p>' => '<p>Două ore, câte o carafă de fiecare, mult lapte risipit.</p>',
	'<p>No experience needed. We supply the milk and the patience.</p>' => '<p>Nu ai nevoie de experiență. Noi aducem laptele și răbdarea.</p>',
	'Book swap' => 'Schimb de cărți',
	'<p>Bring one, take one, argue about it.</p>' => '<p>Adu una, ia una, certați-vă pe ea.</p>',
	'<p>The shelf by the window is emptied and refilled every month.</p>' => '<p>Raftul de lângă fereastră se golește și se umple în fiecare lună.</p>',
	'Vinyl morning' => 'Dimineață cu viniluri',
	'<p>Records from opening until noon.</p>' => '<p>Discuri de la deschidere până la prânz.</p>',
	'<p>Bring a sleeve. First come, first played.</p>' => '<p>Adu un disc. Primul venit, primul ascultat.</p>',
	'Roasting open day' => 'Zi deschisă la prăjit',
	'<p>The drum runs all afternoon.</p>' => '<p>Toba merge toată după-amiaza.</p>',
	'<p>Watch a batch from green to first crack to done, and take a bag home.</p>' => '<p>Urmărește o tură de la boabe verzi la prima crăpătură și până la gata, și ia o pungă acasă.</p>',
	'Winter menu tasting' => 'Degustarea meniului de iarnă',
	'<p>The new drinks, before they go on the board.</p>' => '<p>Băuturile noi, înainte să ajungă pe tablă.</p>',
	'<p>Six small cups, honest feedback expected.</p>' => '<p>Șase cești mici, așteptăm păreri sincere.</p>',
	// the journal
	'Opening day' => 'Ziua deschiderii',
	'Ana' => 'Ana',
	'Mihai' => 'Mihai',
	'Ioana' => 'Ioana',
	'<p>We opened the doors on a rainy Tuesday.</p>' => '<p>Am deschis ușile într-o marți ploioasă.</p>',
	'<p>The whole story of the first day.</p>' => '<p>Toată povestea primei zile.</p>',
	'Why the menu lives in the page' => 'De ce meniul stă în pagină',
	'<p>Nobody on staff wants to learn a content model.</p>' => '<p>Nimeni din echipă nu vrea să învețe un model de conținut.</p>',
	'<p>The menu is a list in the template. We edit a dish by clicking it on the page. There is no admin screen, and there is nothing to keep in sync.</p>' => '<p>Meniul e o listă în șablon. Schimbăm un fel dând clic pe el în pagină. Nu există ecran de administrare și nimic de ținut sincronizat.</p>',
	'The grinder we regret buying' => 'Râșnița pe care regretăm că am cumpărat-o',
	'<p>It was cheap twice: once at the till, once every morning.</p>' => '<p>A fost ieftină de două ori: o dată la casă, apoi în fiecare dimineață.</p>',
	'<p>Retention, static, and a dial that drifts. We kept it for eight months out of stubbornness.</p>' => '<p>Rămâne cafea în ea, face electricitate statică și se dereglează. Am ținut-o opt luni din încăpățânare.</p>',
	'A week of cortados' => 'O săptămână de cortado',
	'<p>We sold four hundred and eleven of them.</p>' => '<p>Am vândut patru sute unsprezece.</p>',
	'<p>Same beans, same jug, four baristas. The tally says the jug matters more than the beans.</p>' => '<p>Aceleași boabe, aceeași carafă, patru bariste. Socoteala spune că, mai mult decât boabele, contează carafa.</p>',
	'On closing at six' => 'Despre închisul la șase',
	'<p>The last hour was never worth the lights.</p>' => '<p>Ultima oră n-a meritat niciodată lumina aprinsă.</p>',
	'<p>We looked at a month of receipts after five o\'clock and made the easy decision.</p>' => '<p>Ne-am uitat la o lună de bonuri de după ora cinci și am luat decizia ușoară.</p>',
	'Where the cakes come from' => 'De unde vin prăjiturile',
	'<p>Two streets away, in a kitchen smaller than ours.</p>' => '<p>La două străzi distanță, într-o bucătărie mai mică decât a noastră.</p>',
	'<p>Ruxandra bakes for three cafés and answers the phone herself. We take what she has.</p>' => '<p>Ruxandra coace pentru trei cafenele și răspunde singură la telefon. Luăm ce are.</p>',
	'Water, and why we gave up on it' => 'Apa, și de ce am renunțat la ea',
	'<p>Three filters, two years, one conclusion: change the cartridge on a calendar, not on taste.</p>' => '<p>Trei filtre, doi ani, o concluzie: schimbă cartușul după calendar, nu după gust.</p>',
	'Draft: the espresso we are not serving yet' => 'Ciornă: espresso-ul pe care nu-l servim încă',
	'<p>Still too sharp at the end.</p>' => '<p>Încă prea acru la final.</p>',
	'<p>This post is a draft. Visitors never see it; signed-in editors do.</p>' => '<p>Articolul acesta e o ciornă. Vizitatorii nu-l văd niciodată; editorii conectați da.</p>',
	// the questions
	'Is there wifi?' => 'Aveți wifi?',
	'<p>Yes, ask at the counter.</p>' => '<p>Da, întreabă la tejghea.</p>',
	'Can I work here all afternoon?' => 'Pot lucra aici toată după-amiaza?',
	'<p>Yes, except Saturdays after eleven, when we need the tables.</p>' => '<p>Da, mai puțin sâmbăta după unsprezece, când avem nevoie de mese.</p>',
	'Do you take cards?' => 'Primiți carduri?',
	'<p>Cards, phones and cash. No cheques, no crypto.</p>' => '<p>Carduri, telefoane și numerar. Fără cecuri, fără cripto.</p>',
	'Is the milk optional?' => 'Laptele e opțional?',
	'<p>Oat, soy and none. No extra charge.</p>' => '<p>De ovăz, de soia sau deloc. Fără cost în plus.</p>',
	'Can I bring a dog?' => 'Pot veni cu un câine?',
	'<p>On the terrace always, inside if it is calm.</p>' => '<p>Pe terasă oricând, înăuntru dacă e liniștit.</p>',
	'Do you do takeaway cups?' => 'Aveți pahare la pachet?',
	'<p>We lend a cup and take it back. Bring your own and pay two lei less.</p>' => '<p>Împrumutăm o cană și o luăm înapoi. Adu-o pe a ta și plătești doi lei mai puțin.</p>',
	// what the café takes bookings for
	'Dining room' => 'Sala de mese',
	'Cut' => 'Tuns',
	'Colour' => 'Vopsit',
	// the opening hours
	'Monday' => 'Luni',
	'closed' => 'închis',
	'Tuesday to Friday' => 'Marți–vineri',
	'8:00 to 18:00' => '8:00–18:00',
	'Weekend' => 'Sâmbătă și duminică',
	'9:00 to 16:00' => '9:00–16:00',
);
$lang = 'ro';
if (i18n::enabled() && in_array($lang, i18n::available(), true)) {
	$model = (new raster_inspector())->content_model();
	// pages and the site: a new revision with the Romanian it lacks
	foreach ($model['pages'] as $page) {
		$fields = array_keys(array_filter($page['fields'], function ($field) { return !empty($field['translate']); }));
		if (!$fields) continue;
		$type = $page['type'];
		$types = array();
		foreach ($fields as $name) $types[$name] = $page['fields'][$name]['type'];
		cms_types::ensure($type, $types + array_intersect_key(cms_types::$system, array_flip(array('slug', 'updated_at'))), $fields);
		$latest = cms_store::latest($type);
		$row = $latest ? $latest->export() : array();
		$values = array();
		foreach ($fields as $name) {
			$english = isset($row[$name]) && $row[$name] !== '' && $row[$name] !== null ? $row[$name] : $page['fields'][$name]['default'];
			$column = cms_types::column($name, $lang);
			if (!isset($romanian[$english]) || (isset($row[$column]) && $row[$column] !== '')) continue;
			$values[$name] = $romanian[$english];
		}
		if (!$values) continue;
		cms_store::update_page($type, $page['slug'], $values, array_keys($values), $lang);
		echo "added Romanian for {$page['url']}: ".implode(', ', array_keys($values))."\n";
	}
	// the lists' items, those there are, and the booking setup's names
	// (the types that say what they translate: the Dining room, the services)
	$lists = $model['collections'];
	foreach (array('bookingresource', 'bookingservice') as $name) if (!isset($lists[$name])) $lists[$name] = array('fields' => array());
	foreach ($lists as $name => $collection) {
		$info = cms_records::info($name);
		$fields = $info ? cms_records::translated_fields($info) : array_keys(array_filter($collection['fields'], function ($field) { return !empty($field['translate']); }));
		$table = cms::collection_type($name);
		if (!$fields || !cms_store::table_exists($table)) continue;
		cms_types::ensure($table, array(), array_merge($fields, array('slug')));
		foreach (R::getAll("SELECT * FROM `$table` ORDER BY id") as $row) {
			$values = array();
			foreach ($fields as $field) {
				$column = cms_types::column($field, $lang);
				if (!isset($row[$field]) || !isset($romanian[$row[$field]]) || (isset($row[$column]) && $row[$column] !== '')) continue;
				$values[$field] = $romanian[$row[$field]];
			}
			if (!$values) continue;
			cms_store::save_item($table, (int)$row['id'], $values, array_keys($values), $info ? 'model' : 'editor', $name, $lang);
			echo "added Romanian for $name {$row['slug']}\n";
		}
	}
}
