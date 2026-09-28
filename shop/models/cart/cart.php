<?php
// The cart lives in the visitor's session (product slug => quantity), not in
// the database: it is nobody's record until it becomes an order.
class cart
{
	// ##The session

	static function raw() {
		util::session();
		$lines = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : array();
		return array_filter($lines, function ($qty) { return (int)$qty > 0; });
	}

	static function store($lines) {
		util::session(true);
		$_SESSION['cart'] = $lines;
	}

	// Drops what can't be bought any more (a piece taken off the shop,
	// renamed, or with less stock than the cart wants). True when it did.
	static function prune() {
		$lines = self::raw();
		$kept = array();
		foreach ($lines as $slug => $qty) {
			$product = product::for_sale($slug);
			if ($product && (int)$product['stock'] > 0) $kept[$slug] = min((int)$qty, (int)$product['stock']);
		}
		if ($kept === $lines) return false;
		self::store($kept);
		return true;
	}

	// what is in the cart, with each product's name and price as they are now
	static function lines() {
		$lines = array();
		foreach (self::raw() as $slug => $qty) {
			$product = product::for_sale($slug);
			if (!$product) continue;
			$cents = self::cents($product['price']) * (int)$qty;
			$lines[] = array(
				'slug' => $slug, 'name' => $product['name'], 'qty' => (int)$qty,
				'price' => $product['price'], 'cents' => $cents, 'stock' => (int)$product['stock'],
			);
		}
		return $lines;
	}

	// ##Money: prices are strings like "24.00", totals are counted in cents

	static function cents($price) {
		return (int)round((float)$price * 100);
	}

	static function money($cents) {
		$amount = number_format($cents / 100, 2, '.', ',');
		$currency = config::get('shop_currency', 'EUR');
		return $currency === 'EUR' ? '€'.$amount : $amount.' '.$currency;
	}

	// ##For the templates

	// the header: <!-- print.cart.count /-->, what can still be bought
	function count() {
		return (string)array_sum(array_map(function ($l) { return $l['qty']; }, self::lines()));
	}

	// <!-- print.cart.total /-->
	function total() {
		return self::money(array_sum(array_map(function ($l) { return $l['cents']; }, self::lines())));
	}

	// <!-- render.cart.lines -->: one row per line, and the flags
	// if.cart_empty / if.cart_full for the rest of the page
	function lines_shown() {
		// only looks: checkout is where the cart is put right, and says so
		$raw = self::raw();
		$rows = array();
		foreach (self::lines() as $line) {
			$rows[] = array(
				'name' => util::e($line['name']), 'qty' => (string)$line['qty'],
				'price' => self::money(self::cents($line['price'])), 'subtotal' => self::money($line['cents']),
				'slug' => $line['slug'], 'url' => config::get('link_uri').'product/product_item/'.rawurlencode($line['slug']),
			);
		}
		$changed = count($rows) !== count($raw);
		foreach (self::lines() as $line) if ($line['qty'] > $line['stock']) $changed = true;
		template::set('cart_changed')->to($changed);
		template::set('cart_empty')->to(!$rows);
		template::set('cart_full')->to((bool)$rows);
		return $rows;
	}

	// ##Forms

	// product_item.html: add this product (from the URL) to the cart
	function add() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return template::instance()->form_state();
		$slug = (string)util::param('product_item');
		$product = product::for_sale($slug);
		if (!$product) {
			$v->raise('sold_out');
			return template::instance()->form_state();
		}
		$lines = self::raw();
		$qty = (isset($lines[$slug]) ? (int)$lines[$slug] : 0) + max(1, (int)util::post('qty'));
		if ($qty > (int)$product['stock']) {
			$v->raise('not_enough');
			return template::instance()->form_state();
		}
		$lines[$slug] = $qty;
		self::store($lines);
		util::done('added');
		return false;
	}

	// cart.html: the Remove buttons (name="remove" value="<slug>")
	function update() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		$lines = self::raw();
		unset($lines[(string)util::post('remove')]);
		self::store($lines);
		util::done('updated');
		return false;
	}
}
