<?php
// #Payments
//
// Buyers can always pay on delivery: no provider, the order is shipped
// unpaid and the studio marks it paid when the courier brings the money.
// Paying by card needs a provider, chosen with config shop_payments:
//
// - stripe: Stripe Checkout. start() creates a Checkout Session and sends the
//   buyer to Stripe; Stripe calls /api/order/webhook with a signed
//   checkout.session.completed event once they paid.
// - test: a pretend provider for trying the shop without an account
//   anywhere. Its page (/pay/test) signs the same event Stripe would and hands
//   it to the same code the webhook runs. It only works in development.
//
// Both sign events the way Stripe does (Stripe-Signature: t=…,v1=HMAC), so the
// webhook has one way to check them.
class order_payments
{
	static function provider() {
		$provider = config::get('shop_payments', '');
		return in_array($provider, array('stripe', 'test'), true) ? $provider : 'none';
	}

	// the pretend provider only works while developing: anywhere a stranger
	// can reach, it would mark orders paid for free
	static function testing() {
		return self::provider() === 'test' && config::get('environment') === 'development';
	}

	// can buyers pay by card right now?
	static function available() {
		if (self::provider() === 'stripe') return (string)config::get('stripe_secret_key') !== '' && self::secret() !== '';
		return self::testing();
	}

	// Where the buyer goes to pay. The provider's session id is kept with the
	// order: the webhook only accepts the session this shop opened.
	static function start($order) {
		if (self::provider() === 'test') {
			if (!self::testing()) throw new RuntimeException('The test payments only work in development');
			$url = config::get('link_uri').'pay/test?order='.rawurlencode($order['number']);
			cms_records::update('order', $order['id'], array('provider_ref' => 'cs_test_'.$order['number'], 'provider_url' => $url));
			return $url;
		}
		$session = self::stripe('checkout/sessions', self::checkout_session($order), 'checkout-'.$order['number']);
		if (!$session || empty($session['url']) || empty($session['id'])) throw new RuntimeException('Stripe did not start a checkout session');
		cms_records::update('order', $order['id'], array('provider_ref' => (string)$session['id'], 'provider_url' => (string)$session['url']));
		return $session['url'];
	}

	// the link to pay an order that isn't paid yet
	static function pay_url($order) {
		if ($order['status'] !== 'unpaid' || $order['payment'] !== 'card') return '';
		return self::provider() === 'test' && !self::testing() ? '' : (string)$order['provider_url'];
	}

	// Stops the provider taking payment for an order (before it is
	// cancelled). False when the buyer already paid.
	static function close($order) {
		if ($order['payment'] !== 'card' || self::provider() !== 'stripe' || strpos((string)$order['provider_ref'], 'cs_') !== 0) return true;
		$expired = self::stripe('checkout/sessions/'.rawurlencode($order['provider_ref']).'/expire', array());
		if (is_array($expired) && ($expired['status'] ?? '') === 'expired') return true;
		// already expired, or never opened: ask how it ended
		$session = self::stripe_get('checkout/sessions/'.rawurlencode($order['provider_ref']));
		return is_array($session) && ($session['status'] ?? '') === 'expired';
	}

	// what Stripe needs to show the buyer, in Stripe's form encoding
	static function checkout_session($order) {
		$link = config::get('link_uri');
		$fields = array(
			'mode' => 'payment',
			'client_reference_id' => $order['number'],
			'customer_email' => $order['email'],
			'success_url' => $link.'thanks',
			'cancel_url' => $link.'thanks',
			// cards settle at once; methods that settle days later would need
			// checkout.session.async_payment_succeeded before shipping
			'payment_method_types[0]' => 'card',
			// unpaid orders hold their pieces for an hour (order::HOLD_MINUTES)
			'expires_at' => time() + order::HOLD_MINUTES * 60,
		);
		foreach (array_values($order['lines']) as $i => $line) {
			$fields["line_items[$i][quantity]"] = (int)$line['qty'];
			$fields["line_items[$i][price_data][currency]"] = strtolower($order['currency']);
			$fields["line_items[$i][price_data][unit_amount]"] = (int)$line['cents'];
			$fields["line_items[$i][price_data][product_data][name]"] = $line['piece'];
		}
		return $fields;
	}

	// The money back. Stripe remembers a request by its idempotency key, so
	// asking again after a timeout returns the first refund, not a second one.
	static function refund($order) {
		// cash from a courier goes back by hand
		if (($order['payment'] ?? 'card') !== 'card') return true;
		if (self::provider() !== 'stripe') return self::testing();
		$refund = self::stripe('refunds', array('payment_intent' => $order['provider_ref']), 'refund-'.$order['number'], $error);
		if (is_array($refund) && in_array($refund['status'] ?? '', array('succeeded', 'pending'), true)) return true;
		return $error === 'charge_already_refunded';
	}

	// ##Signatures

	static function secret() {
		$secret = (string)config::get('shop_webhook_secret');
		// the example's own secret is public, so it only works while developing
		if ($secret === 'whsec_test_only_for_the_example' && config::get('environment') !== 'development') return '';
		return $secret;
	}

	static function sign($payload, $time = null) {
		$time = $time === null ? time() : (int)$time;
		return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, self::secret());
	}

	// Stripe's scheme: t is when it was signed (five minutes of leeway),
	// v1 the HMAC of "t.payload" with the webhook secret
	static function verify($payload, $header) {
		$secret = self::secret();
		if ($secret === '' || $header === '') return false;
		$time = null;
		$signatures = array();
		foreach (explode(',', $header) as $part) {
			$pair = explode('=', trim($part), 2);
			if (count($pair) !== 2) continue;
			if ($pair[0] === 't') $time = (int)$pair[1];
			if ($pair[0] === 'v1') $signatures[] = $pair[1];
		}
		if (!$time || abs(time() - $time) > 300) return false;
		$expected = hash_hmac('sha256', $time.'.'.$payload, $secret);
		foreach ($signatures as $signature) {
			if (hash_equals($expected, $signature)) return true;
		}
		return false;
	}

	// ##The pretend provider

	// what Stripe would send once the buyer paid, signed and handed to the
	// webhook's code
	static function pay_as_test($order) {
		if (!self::testing()) throw new RuntimeException('The test payments only work in development');
		$payload = json_encode(self::test_event($order));
		if (!self::verify($payload, self::sign($payload))) throw new RuntimeException('The test event did not verify');
		return order::paid(json_decode($payload, true));
	}

	static function test_event($order) {
		return array(
			'id' => 'evt_test_'.bin2hex(random_bytes(6)),
			'type' => 'checkout.session.completed',
			'data' => array('object' => array(
				'id' => 'cs_test_'.$order['number'],
				'client_reference_id' => $order['number'],
				'payment_intent' => 'pi_test_'.bin2hex(random_bytes(6)),
				'payment_status' => 'paid',
				'amount_total' => cart::cents($order['total']),
				'currency' => strtolower($order['currency']),
			)),
		);
	}

	// ##Stripe's API

	// a POST to Stripe's API; $error gets Stripe's error code when it says no
	static function stripe($path, $fields, $idempotency_key = null, &$error = null) {
		$headers = $idempotency_key ? array('Idempotency-Key: '.$idempotency_key) : array();
		return self::stripe_call('POST', $path, $fields, $headers, $error);
	}

	static function stripe_get($path) {
		return self::stripe_call('GET', $path, array(), array(), $error);
	}

	static function stripe_call($method, $path, $fields, $headers, &$error) {
		$error = null;
		$key = (string)config::get('stripe_secret_key');
		if ($key === '') throw new RuntimeException('Set STRIPE_SECRET_KEY to take payments with Stripe');
		$curl = curl_init('https://api.stripe.com/v1/'.$path);
		$options = array(CURLOPT_USERPWD => $key.':', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers);
		if ($method === 'POST') $options += array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields));
		curl_setopt_array($curl, $options);
		$body = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$data = is_string($body) ? json_decode($body, true) : null;
		if ($body === false || $status >= 400) {
			$error = is_array($data) && isset($data['error']['code']) ? (string)$data['error']['code'] : 'unreachable';
			log::warning("stripe $method $path: HTTP $status $error");
			return null;
		}
		return $data;
	}
}
