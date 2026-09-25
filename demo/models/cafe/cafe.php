<?php
// The café's own model. Most methods feed the menu and the pages; the rest
// exist so the lab page (views/cafe/lab.html) can show every engine feature.
class cafe
{
	// ##Pages

	// opening hours: day, time, state (open or closed)
	function hours() {
		return array(
			array('day' => 'Monday', 'time' => 'closed', 'state' => 'closed'),
			array('day' => 'Tuesday to Friday', 'time' => '8:00 to 18:00', 'state' => 'open'),
			array('day' => 'Weekend', 'time' => '9:00 to 16:00', 'state' => 'open'),
		);
	}

	// dishes in a category, counted with an SQL file (models/cafe/sql/count_category.sql)
	function category_count($category) {
		try {
			$rows = database::instance('cafe')->count_category($category);
			return (string)(isset($rows[0]['total']) ? $rows[0]['total'] : 0);
		} catch (Exception $e) {
			return '0';
		}
	}

	// an application event (config/the_events.php): every page gets a header
	function stamp() {
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-Cafe: served');
		return true;
	}

	// a core event (done): runs before the page is sent
	function finish() {
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-Cafe-Done: yes');
		return true;
	}

	// route_not_found
	function missing() {
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-Cafe-Missing: yes');
		return true;
	}

	// loading_model_secret: false stops the model from loading
	function deny() {
		return false;
	}

	// ##Lab: one method per engine feature

	// literal arguments: specials(3, 'soup', true, -1, null)
	function specials($count, $label, $flag, $offset, $nothing) {
		return json_encode(array($count, $label, $flag, $offset, $nothing));
	}

	// rows whose values are lists of rows
	function sections() {
		return array(
			array('section' => 'Coffee', 'dishes' => array(array('dish' => 'Espresso'), array('dish' => 'Flat white'))),
			array('section' => 'Cakes', 'dishes' => array(array('dish' => 'Carrot cake'))),
		);
	}

	// an empty list renders nothing
	function nothing() {
		return array();
	}

	// false keeps the mock-up
	function keep() {
		return false;
	}

	// HTML views print values as they are; escape user input yourself
	function trusted_html() {
		return '<em>house blend</em>';
	}

	function escaped() {
		return util::e('<script>alert("no")</script>');
	}

	// JavaScript can read values too: "/*- print.cafe.color /-*/"
	// values for print.self and print.if, set before the print pass
	function prepare() {
		template::set('greeting')->to('Hello from the café');
		template::set('has_specials')->to(true);
		template::set('sold_out')->to(false);
		return '';
	}

	// the same self-closing tag several times in a row
	function links() {
		return array(array('url' => 'https://example.com/a'), array('url' => 'https://example.com/b'));
	}

	// attribute values are escaped; false removes the attribute; a false
	// value keeps the mock-up
	function tricky() {
		return array(
			array('link' => 'https://example.com/?q="quotes"&x=<y>', 'label' => 'escaped link'),
			array('link' => false, 'label' => false),
		);
	}

	// a render method may return a string instead of rows
	function banner() {
		return '<p class="banner">Open today</p>';
	}

	// runs only if a remove block doesn't stop it
	function side_effect() {
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-Side-Effect: ran');
		return 'side effect';
	}

	// a named query from models/sql.php with a quoted placeholder
	function dish_names($category) {
		$rows = database::instance('cafe')->dish_names($category);
		return $rows ? implode(', ', array_map(function ($r) { return $r['name']; }, $rows)) : 'none';
	}

	// a key from the URL: /lab/color/red
	function color() {
		return util::param('color', 'none');
	}

	// a query with a bound parameter
	function bound() {
		$rows = database::instance('cafe')->query('SELECT ? AS value', array("it's bound"));
		return $rows[0]['value'];
	}

	// rendered into memory ("__"), then printed elsewhere
	function remember() {
		return array('__' => true, array('note' => 'remembered'));
	}

	function recall() {
		$results = template::instance()->render_results;
		return isset($results['cafe']['remember'][0]) ? trim(strip_tags($results['cafe']['remember'][0])) : false;
	}

	// a model that paginates itself: guestbook(true) answers the pager
	function guestbook($count = false) {
		$entries = array();
		foreach (array('Ana', 'Bogdan', 'Carmen', 'Dan', 'Elena', 'Florin', 'Gabi') as $name) {
			$entries[] = array('guest' => $name);
		}
		$perpage = 3;
		if ($count === true) return array('total' => count($entries), 'perpage' => $perpage);
		$page = max(1, (int)util::get('page'));
		return array_slice($entries, ($page - 1) * $perpage, $perpage);
	}
}
