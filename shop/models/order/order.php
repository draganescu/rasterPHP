<?php
// Orders are records. This model declares what an order holds and which
// changes make sense (an order is paid before it ships, and a shipped order
// has a tracking number); the CMS stores orders, lists them on /orders for the
// studio with Ship, Cancel and Refund buttons, and shows buyers their own on
// /account.
//
// The money moves through a payment provider (payments.php): checkout makes
// the order and sends the buyer to pay, and the provider calls
// /api/order/webhook when they have. Two rules run through this file:
// whatever a change depends on is read again inside the transaction that
// makes it, and calls to the provider happen outside transactions, because
// they can't be rolled back.
class order
{
	// which status may follow which
	static $next = array(
		'unpaid' => array('paid', 'cancelled'),
		'paid' => array('shipped', 'refunded'),
		'shipped' => array('refunded'),
		// paid after it was cancelled: the money goes straight back
		'cancelled' => array('refunded'),
		'refunded' => array(),
	);

	// unpaid orders hold their pieces this long, then go back on the shelf
	const HOLD_MINUTES = 60;

	static function types() {
		return array('order' => array(
			'fields' => array(
				'number' => '', 'email' => '', 'name' => '', 'address' => '', 'city' => '', 'postcode' => '', 'country' => '',
				'lines' => array(), 'total' => '0.00', 'currency' => '', 'status' => 'unpaid',
				'tracking' => '', 'payment_id' => '', 'payment_url' => '', 'paid_at' => '', 'shipped_at' => '',
			),
			// buyers make orders, through the checkout (never a new card on
			// /orders); one who was logged in sees theirs on /account
			'create' => 'visitor',
			'owner' => true,
			// what was bought and what happened to it only change through the
			// checkout, the provider and the actions; the studio can fix an
			// address or type the tracking number
			'readonly' => array('number', 'lines', 'total', 'currency', 'status', 'paid_at', 'shipped_at'),
			// the provider's ids are for this model, not for people
			'hidden' => array('payment_id', 'payment_url'),
			'actions' => array('ship' => 'editor', 'cancel' => 'editor', 'refund' => 'admin'),
		));
	}

	// Every change to an order passes here, from the checkout, the studio's
	// page editor, an agent over MCP or the webhook.
	static function check($type, $after, $before) {
		if ($after === null) {
			// orders are the shop's books: they are cancelled or refunded, never deleted
			return array('orders_are_kept');
		}
		$problems = array();
		if (!$before && !$after['lines']) $problems[] = 'empty_order';
		if ($before && $before['status'] !== $after['status'] && !in_array($after['status'], self::$next[$before['status']], true)) {
			$problems[] = 'not_now';
		}
		if ($after['status'] === 'shipped' && trim((string)$after['tracking']) === '') $problems[] = 'tracking_missing';
		return $problems;
	}

	// Changes an order's status, but only from the status the caller expects:
	// the order is read again inside the transaction, so two clicks, two
	// webhook deliveries or a click and a webhook can't both do it
	// (events about the move are sent by the caller once this returns, that
	// is after the commit)
	static function move($id, $from, $changes) {
		return cms_records::transaction(function () use ($id, $from, $changes) {
			$order = cms_records::get('order', $id);
			if (!in_array($order['status'], (array)$from, true)) cms_records::refuse('not_now');
			$moved = cms_records::update('order', $id, $changes);
			if (isset($changes['status']) && $changes['status'] === 'cancelled') self::restock($order);
			if (isset($changes['status']) && $changes['status'] === 'refunded' && $order['status'] === 'paid') self::restock($order);
			return $moved;
		});
	}

	// ##Actions: the buttons on /orders

	// Ship: needs the tracking number, typed into the order or given as input
	static function ship($order, $input) {
		$changes = array('status' => 'shipped', 'shipped_at' => date('Y-m-d H:i:s'));
		if (!empty($input['tracking'])) $changes['tracking'] = (string)$input['tracking'];
		$shipped = self::move($order['id'], 'paid', $changes);
		cms_records::dispatch('order.shipped', $shipped);
		return $shipped;
	}

	// Cancel an order nobody paid for: the provider stops taking payment for
	// it first, then its pieces go back on the shelf
	static function cancel($order, $input) {
		if ($order['status'] !== 'unpaid') cms_records::refuse('not_now');
		if (!order_payments::close($order)) cms_records::refuse('already_paid');
		return self::move($order['id'], 'unpaid', array('status' => 'cancelled'));
	}

	// Refund a paid order (admins only). The money goes back first, outside
	// any transaction; the provider remembers the request, so pressing Refund
	// again after a failure does not refund twice. Pieces never shipped go
	// back on the shelf.
	static function refund($order, $input) {
		if (!in_array($order['status'], array('paid', 'shipped'), true)) cms_records::refuse('not_now');
		if (!order_payments::refund($order)) cms_records::refuse('refund_failed');
		return self::move($order['id'], array('paid', 'shipped'), array('status' => 'refunded'));
	}

	// by the product's id, so a piece renamed or taken off the shop still
	// gets its stock back
	static function restock($order) {
		foreach ($order['lines'] as $line) {
			$product = isset($line['product_id']) ? cms_records::get('product', $line['product_id']) : null;
			if ($product) cms_records::update('product', $product['id'], array('stock' => (int)$product['stock'] + (int)$line['qty']));
		}
	}

	// unpaid orders older than HOLD_MINUTES give their pieces back
	static function release_stale() {
		$cutoff = date('Y-m-d H:i:s', time() - self::HOLD_MINUTES * 60);
		foreach (cms_records::find('order', array('status' => 'unpaid')) as $order) {
			if ($order['created_at'] > $cutoff || !order_payments::close($order)) continue;
			try {
				self::move($order['id'], 'unpaid', array('status' => 'cancelled'));
			} catch (cms_refused $e) {
				// paid or cancelled meanwhile
			}
		}
	}

	// ##Checkout: <!-- render.order.checkout --> on checkout.html
	//
	// The cart becomes an order and the pieces leave the shelf, all at once:
	// if one piece sold out meanwhile, product::check refuses its stock going
	// below zero and nothing is written. Then the buyer goes to pay. If the
	// provider can't take them, the order is cancelled and the cart kept.
	function checkout() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return template::instance()->form_state();
		if (!order_payments::available()) {
			$v->raise('payment_unavailable');
			return template::instance()->form_state();
		}
		if (cart::prune()) {
			// something in the cart left the shop while the buyer was here
			$v->raise('cart_changed');
			return template::instance()->form_state();
		}
		$cart = cart::raw();
		if (!$cart) {
			$v->raise('cart_empty');
			return template::instance()->form_state();
		}
		self::release_stale();
		$buyer = array();
		foreach (array('email', 'name', 'address', 'city', 'postcode', 'country') as $field) $buyer[$field] = trim((string)util::post($field));
		try {
			$order = cms_records::transaction(function () use ($cart, $buyer) {
				$lines = array();
				$total = 0;
				foreach ($cart as $slug => $qty) {
					$product = product::for_sale($slug);
					if (!$product) cms_records::refuse('sold_out');
					cms_records::update('product', $product['id'], array('stock' => (int)$product['stock'] - (int)$qty));
					$cents = cart::cents($product['price']);
					$lines[] = array(
						'product' => $slug, 'product_id' => (int)$product['id'], 'piece' => $product['name'], 'qty' => (int)$qty,
						'price' => cart::money($cents), 'subtotal' => cart::money($cents * (int)$qty), 'cents' => $cents,
					);
					$total += $cents * (int)$qty;
				}
				$order = cms_records::create('order', $buyer + array(
					'lines' => $lines, 'total' => number_format($total / 100, 2, '.', ''),
					'currency' => config::get('shop_currency', 'EUR'), 'status' => 'unpaid',
				));
				return cms_records::update('order', $order['id'], array('number' => 'BH-'.(1000 + (int)$order['id'])));
			});
		} catch (cms_refused $e) {
			foreach ($e->problems as $problem) $v->raise($problem);
			return template::instance()->form_state();
		}
		try {
			$url = order_payments::start($order);
		} catch (Exception $e) {
			log::warning('shop: '.$e->getMessage());
			self::move($order['id'], 'unpaid', array('status' => 'cancelled'));
			$v->raise('payment_unavailable');
			return template::instance()->form_state();
		}
		cart::store(array());
		// the thank-you page shows this order to whoever placed it
		$_SESSION['last_order'] = $order['number'];
		header('Location: '.$url, true, 303);
		exit;
	}

	// ##The provider tells us a buyer paid: POST /api/order/webhook
	//
	// A server calling, not a browser: no session, no form. The body is the
	// provider's event, signed with the webhook secret; anything unsigned is
	// refused. Events that aren't about one of this shop's orders are
	// answered 200 (and logged), or the provider would keep resending them.
	function webhook() {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			http_response_code(405);
			return array('error' => 'POST only');
		}
		$payload = (string)file_get_contents('php://input');
		$signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string)$_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
		if (!order_payments::verify($payload, $signature)) {
			http_response_code(400);
			return array('error' => 'The signature does not match');
		}
		return self::paid(json_decode($payload, true));
	}

	// Marks the order an event is about as paid, once: providers send an
	// event again when they are unsure it arrived.
	static function paid($event) {
		$types = array('checkout.session.completed', 'checkout.session.async_payment_succeeded');
		if (!is_array($event) || !in_array($event['type'] ?? '', $types, true)) return array('received' => true, 'ignored' => 'not a payment');
		$session = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : array();
		$found = cms_records::find('order', array('number' => (string)($session['client_reference_id'] ?? '')));
		$order = $found ? $found[0] : null;
		// the session this shop opened for this order, for its total, in its currency
		$why = '';
		if (!$order) $why = 'no such order';
		elseif (($session['id'] ?? '') !== $order['payment_id'] && ($session['payment_intent'] ?? '') !== $order['payment_id']) $why = 'not this order\'s checkout';
		elseif ((int)($session['amount_total'] ?? -1) !== cart::cents($order['total']) || strtolower((string)($session['currency'] ?? '')) !== strtolower($order['currency'])) $why = 'not the order\'s total';
		// a bank transfer or a debit may complete the checkout before the money arrives
		elseif (($session['payment_status'] ?? '') !== 'paid') $why = 'not paid yet';
		if ($why !== '') {
			log::warning("shop webhook: ignored an event ($why)");
			return array('received' => true, 'ignored' => $why);
		}
		$intent = (string)($session['payment_intent'] ?? $session['id']);
		try {
			$paid = self::move($order['id'], 'unpaid', array('status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'), 'payment_id' => $intent));
			cms_records::dispatch('order.paid', $paid);
			return array('received' => true, 'paid' => $order['number']);
		} catch (cms_refused $e) {
			$now = cms_records::get('order', $order['id']);
			if ($now['status'] !== 'cancelled') return array('received' => true, 'already' => $now['status']);
		}
		// paid after the studio cancelled it (the provider's page was still
		// open): the money goes straight back, and the studio hears about it
		$refunded = order_payments::refund(array('payment_id' => $intent) + $order);
		if ($refunded) {
			$late = self::move($order['id'], 'cancelled', array('status' => 'refunded', 'payment_id' => $intent));
			cms_records::dispatch('order.refunded_late', $late);
		} else {
			log::warning("shop webhook: {$order['number']} was paid after it was cancelled and the refund failed");
		}
		return array('received' => true, 'refunded' => $refunded, 'number' => $order['number']);
	}

	// ##Emails

	static function listens() {
		return array('order.paid' => 'paid_mail', 'order.shipped' => 'shipped_mail', 'order.refunded_late' => 'late_mail');
	}

	function paid_mail($order) {
		if (!is_array($order) || empty($order['email'])) return null;
		$vars = self::mail_vars($order);
		mail::send_view('_email/order_confirmation', $order['email'], $vars);
		mail::send_view('_email/new_order', config::get('shop_staff_email'), $vars);
		return null;
	}

	function shipped_mail($order) {
		if (!is_array($order) || empty($order['email'])) return null;
		mail::send_view('_email/order_shipped', $order['email'], self::mail_vars($order) + array('tracking' => (string)$order['tracking']));
		return null;
	}

	function late_mail($order) {
		if (!is_array($order) || empty($order['number'])) return null;
		mail::send_view('_email/late_payment', config::get('shop_staff_email'), self::mail_vars($order));
		return null;
	}

	static function mail_vars($order) {
		$items = array();
		foreach ($order['lines'] as $line) $items[] = $line['qty'].' × '.$line['piece'].' ('.$line['subtotal'].')';
		return array(
			'number' => $order['number'], 'name' => $order['name'], 'items' => implode(', ', $items),
			'total' => cart::money(cart::cents($order['total'])),
			'address' => trim($order['address'].', '.$order['postcode'].' '.$order['city'].', '.$order['country'], ', '),
		);
	}

	// ##Pages the model fills itself

	// thanks.html: the order this visitor just placed, whether or not they
	// have an account. Model code prints raw, so everything is escaped here.
	function receipt() {
		util::session();
		$number = isset($_SESSION['last_order']) ? (string)$_SESSION['last_order'] : '';
		$found = $number === '' ? array() : cms_records::find('order', array('number' => $number));
		if (!$found) return array();
		$order = $found[0];
		$lines = array();
		foreach ($order['lines'] as $line) {
			$lines[] = array('name' => util::e($line['piece']), 'qty' => (string)$line['qty'], 'subtotal' => util::e($line['subtotal']));
		}
		template::set('order_paid')->to($order['status'] === 'paid');
		template::set('order_unpaid')->to($order['status'] === 'unpaid');
		template::set('can_pay')->to(order_payments::pay_url($order) !== '');
		return array(array(
			'number' => util::e($order['number']), 'email' => util::e($order['email']),
			'total' => cart::money(cart::cents($order['total'])), 'lines' => $lines,
			'pay_url' => util::e(order_payments::pay_url($order)),
		));
	}

	// pay/test.html: the pretend provider's page (payments set to 'test')
	function test_payment() {
		if (!order_payments::testing()) return array();
		$found = cms_records::find('order', array('number' => (string)(isset($_GET['order']) ? $_GET['order'] : '')));
		if (!$found || $found[0]['status'] !== 'unpaid') return array();
		$order = $found[0];
		$v = validation::get();
		if ($v->submitted()) {
			order_payments::pay_as_test($order);
			util::redirect('thanks');
		}
		return array(array('number' => util::e($order['number']), 'total' => cart::money(cart::cents($order['total']))));
	}
}
