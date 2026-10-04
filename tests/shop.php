<?php
// The example shop's test suite: php tests/shop.php
//
// Drives shop/ (Blue Hour Ceramics) over HTTP the way a buyer, the studio and
// a payment provider would: the catalogue, the cart, checkout with the last
// piece contested, the pretend provider, the signed webhook, the studio's
// actions, and what visitors must never see.

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/raster-shop-'.getmypid();
@mkdir($tmp, 0775, true);
$db = "$tmp/shop.sqlite";
$maildir = "$tmp/mail";
putenv('RASTER_APP=shop');
putenv("RASTER_DB=$db");
putenv("RASTER_MAIL=log://$maildir");
putenv('RASTER_ENV');

require_once $root.'/system/boot.php';
boot::$appname = 'shop';
boot::cli();
require_once BASE.'tools/inspector.php';

$passed = 0; $failed = array();
function test($name, $fn) {
	global $passed, $failed;
	try { $fn(); $passed++; echo '.'; }
	catch (Throwable $e) { $failed[] = "$name: ".$e->getMessage().' (line '.$e->getLine().')'; echo 'F'; }
}
function check($condition, $message = 'assertion failed') { if (!$condition) throw new Exception($message); }
function same($expected, $actual, $message = '') {
	if ($expected !== $actual) throw new Exception(trim($message.' expected '.var_export($expected, true).', got '.var_export($actual, true)));
}
function has($haystack, $needle, $message = '') { if (strpos((string)$haystack, $needle) === false) throw new Exception(trim($message.' missing: '.$needle)); }
function lacks($haystack, $needle, $message = '') { if (strpos((string)$haystack, $needle) !== false) throw new Exception(trim($message.' unexpected: '.$needle)); }

$servers = array();
function free_port() {
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	return (int)substr($name, strrpos($name, ':') + 1);
}
function server($env) {
	global $root, $servers, $tmp;
	$port = free_port();
	$env = array_merge(array('PATH' => getenv('PATH'), 'RASTER_APP' => 'shop'), $env);
	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', "error_log=$tmp/php-errors.log", '-S', "127.0.0.1:$port", "$root/index.php"), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $root, $env);
	for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	$servers[] = $process;
	return "http://127.0.0.1:$port";
}
register_shutdown_function(function () use ($tmp, $root) {
	global $servers;
	foreach ($servers as $process) proc_terminate($process);
	exec('rm -rf '.escapeshellarg($tmp).' '.escapeshellarg("$root/shop/data/cache"));
});
function http($method, $url, $body = null, $headers = array()) {
	if (is_array($body)) {
		$body = http_build_query($body);
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
	}
	$context = stream_context_create(array('http' => array(
		'method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
		'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 20,
	)));
	$content = @file_get_contents($url, false, $context);
	preg_match('/\d{3}/', $http_response_header[0], $m);
	return array((int)$m[0], (string)$content, $http_response_header);
}
function header_value($headers, $name) {
	foreach ($headers as $h) if (stripos($h, $name.':') === 0) return trim(substr($h, strlen($name) + 1));
	return null;
}
// a browser: keeps its cookies between requests
class visitor {
	public $cookies = array();
	public $base;
	function __construct($base) { $this->base = $base; }
	function go($method, $path, $body = null, $headers = array()) {
		if ($this->cookies) $headers[] = 'Cookie: '.implode('; ', $this->cookies);
		$response = http($method, $this->base.$path, $body, $headers);
		foreach ($response[2] as $h) {
			if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = $m[1].'='.$m[2];
		}
		return $response;
	}
	function token($path = '/') {
		$html = $this->go('GET', $path)[1];
		return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/"csrf":"([a-f0-9]+)"/', $html, $m) ? $m[1] : '');
	}
	function login($email, $password) {
		list($status) = $this->go('POST', '/login', array('raster_form' => 'authentication.login', 'login' => $email, 'password' => $password));
		same(303, $status, "login $email");
	}
	function buy($slug, $qty, $buyer = array()) {
		$csrf = $this->token("/product/product_item/$slug");
		same(303, $this->go('POST', "/product/product_item/$slug", array('raster_form' => 'cart.add', 'qty' => (string)$qty, 'csrf' => $csrf))[0], "add $slug");
		return $this->checkout($buyer);
	}
	function checkout($buyer = array()) {
		$csrf = $this->token('/checkout');
		return $this->go('POST', '/checkout', array_merge(array('raster_form' => 'order.checkout', 'email' => 'ana@example.com', 'name' => 'Ana Pop', 'address' => 'Strada Exemplu 1', 'city' => 'București', 'postcode' => '010101', 'country' => 'Romania', 'payment' => 'card', 'csrf' => $csrf), $buyer));
	}
}
function mails() {
	global $maildir;
	$files = glob("$maildir/*.eml") ?: array();
	sort($files);
	$mails = array();
	foreach ($files as $file) {
		$raw = file_get_contents($file);
		preg_match('/^To: (.*)$/m', $raw, $to);
		preg_match('/^Subject: (.*)$/m', $raw, $subject);
		$text = preg_match('/Content-Type: text\/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $raw, $m) ? base64_decode($m[1]) : '';
		$mails[] = array('to' => trim($to[1]), 'subject' => iconv_mime_decode(trim($subject[1]), 0, 'UTF-8'), 'text' => $text);
	}
	return $mails;
}
function stock($slug) {
	return (int)cms_records::find('product', array('slug' => $slug))[0]['stock'];
}
function order_by_number($number) {
	$found = cms_records::find('order', array('number' => $number));
	return $found ? $found[0] : null;
}
function number_in($location) {
	return preg_match('/order=(BH-\d+)/', (string)$location, $m) ? $m[1] : null;
}
function signed_event($base, $event, $signature = null) {
	$payload = json_encode($event);
	return http('POST', "$base/api/order/webhook", $payload, array('Content-Type: application/json', 'Stripe-Signature: '.($signature ?: order_payments::sign($payload))));
}

// ## The shop, seeded as the README says

$out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/shop/seed.php").' 2>&1');
// cards through the pretend provider; the shop without a provider is further down
$base = server(array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'RASTER_MCP_TOKEN' => 'shop-token', 'SHOP_PAYMENTS' => 'test'));
database::instance('cms');

test('the seed puts four pieces on the shelf and makes the studio\'s account', function () use ($out) {
	has($out, 'added Morning mug');
	has($out, 'studio@bluehour.test');
	same(4, count(cms_records::find('product')));
});

test('everyone sees the catalogue: products are a public record type', function () use ($base) {
	list($status, $body) = http('GET', "$base/");
	same(200, $status);
	foreach (array('Morning mug', 'Soup bowl', 'Tall vase', 'Espresso cup') as $name) has($body, "<h2>$name</h2>");
	has($body, 'href="'.$base.'/product/product_item/tall-vase"');
	has($body, '€68.00 · 1 left');
	list($status, $body) = http('GET', "$base/product/product_item/soup-bowl");
	same(200, $status);
	has($body, '<p>A wide bowl in sage green', 'descriptions are HTML (the type lists them in html)');
	same(404, http('GET', "$base/product/product_item/no-such-thing")[0]);
});

test('the cart: add, too many, totals, remove', function () use ($base) {
	$v = new visitor($base);
	same(303, $v->go('POST', '/product/product_item/morning-mug', array('raster_form' => 'cart.add', 'qty' => '2'))[0]);
	has($v->go('GET', '/product/product_item/morning-mug?done=added')[1], 'Added.');
	list(, $body) = $v->go('POST', '/product/product_item/tall-vase', array('raster_form' => 'cart.add', 'qty' => '2', 'csrf' => ''));
	has($body, 'There aren\'t that many left.');
	same(303, $v->go('POST', '/product/product_item/soup-bowl', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	list(, $cart) = $v->go('GET', '/cart');
	has($cart, 'Cart (3)');
	has($cart, '2 × €24.00', 'quantities');
	has($cart, '€48.00');
	has($cart, 'Total <strong>€80.00</strong>');
	same(303, $v->go('POST', '/cart', array('raster_form' => 'cart.update', 'remove' => 'soup-bowl'))[0]);
	has($v->go('GET', '/cart')[1], 'Total <strong>€48.00</strong>');
	has(http('GET', "$base/cart")[1], 'Nothing here yet.', 'another visitor has their own cart');
});

test('checkout: the HTML rules, an empty cart, then an order and the stock leaves the shelf', function () use ($base) {
	$v = new visitor($base);
	list(, $body) = $v->go('POST', '/checkout', array('raster_form' => 'order.checkout', 'email' => 'x@example.com', 'name' => 'X', 'address' => 'A', 'city' => 'C', 'postcode' => 'P', 'country' => 'R'));
	has($body, 'Your cart is empty.');
	$before = stock('espresso-cup');
	same(303, $v->go('POST', '/product/product_item/espresso-cup', array('raster_form' => 'cart.add', 'qty' => '3'))[0]);
	list(, $body) = $v->checkout(array('email' => 'nope'));
	has($body, 'We need an email for the receipt.');
	list($status, , $headers) = $v->checkout(array('name' => 'Ana <script>x</script>'));
	same(303, $status);
	$number = number_in(header_value($headers, 'Location'));
	check($number !== null, 'sent to pay: '.header_value($headers, 'Location'));
	has(header_value($headers, 'Location'), "$base/pay/test?order=$number");
	same($before - 3, stock('espresso-cup'));
	$order = order_by_number($number);
	same('unpaid', $order['status']);
	same('48.00', $order['total']);
	same(array('product' => 'espresso-cup', 'piece' => 'Espresso cup', 'qty' => 3), array_intersect_key($order['lines'][0], array_flip(array('product', 'piece', 'qty'))), 'the order keeps its own copy of what was sold');
	same(0, (int)$order['owner'], 'a guest\'s order belongs to nobody');
	has($v->go('GET', '/cart')[1], 'Cart (0)', 'the cart is empty after checkout');
	has($v->go('GET', '/thanks')[1], "Order $number", 'the buyer sees their order');
	has($v->go('GET', '/thanks')[1], 'Pay now');
	lacks(http('GET', "$base/thanks")[1], $number, 'nobody else does');
});

test('the last piece, wanted twice at the same moment: one checkout wins, the other writes nothing', function () use ($base, $db, $maildir) {
	// a second server on the same database, so the two checkouts really race
	$other = server(array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'SHOP_PAYMENTS' => 'test'));
	$a = new visitor($base);
	$b = new visitor($other);
	same(1, stock('tall-vase'));
	foreach (array($a, $b) as $v) same(303, $v->go('POST', '/product/product_item/tall-vase', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	$orders = count(cms_records::find('order'));
	$multi = curl_multi_init();
	$handles = array();
	foreach (array($a, $b) as $i => $v) {
		$handle = curl_init($v->base.'/checkout');
		curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('Cookie: '.implode('; ', $v->cookies)),
			CURLOPT_POSTFIELDS => http_build_query(array('raster_form' => 'order.checkout', 'email' => "racer$i@example.com", 'name' => "Racer $i", 'address' => 'A 1', 'city' => 'C', 'postcode' => '1', 'country' => 'RO', 'payment' => 'delivery'))));
		curl_multi_add_handle($multi, $handle);
		$handles[] = $handle;
	}
	do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.05); } while ($running);
	$codes = array_map(function ($h) { return curl_getinfo($h, CURLINFO_HTTP_CODE); }, $handles);
	$bodies = array_map('curl_multi_getcontent', $handles);
	sort($codes);
	same(array(200, 302), $codes, 'one order is placed, the other checkout stays');
	check(strpos(implode('', $bodies), 'bought the last of a piece') !== false || strpos(implode('', $bodies), 'changed while you were here') !== false, 'and is told why');
	same(0, stock('tall-vase'), 'never below zero');
	same($orders + 1, count(cms_records::find('order')), 'and no second order');
});

test('the pretend provider: paying marks the order paid, and emails the buyer and the studio', function () use ($base) {
	$v = new visitor($base);
	list(, , $headers) = $v->buy('soup-bowl', 1, array('email' => 'bea@example.com', 'name' => 'Bea'));
	$number = number_in(header_value($headers, 'Location'));
	$before = count(mails());
	list(, $page) = $v->go('GET', "/pay/test?order=$number");
	has($page, 'Pay €32.00');
	same(302, $v->go('POST', "/pay/test?order=$number", array('raster_form' => 'order.test_payment'))[0]);
	$order = order_by_number($number);
	same('paid', $order['status']);
	check($order['paid_at'] !== '' && strpos($order['provider_ref'], 'pi_test_') === 0, 'paid_at and the provider\'s id');
	$sent = array_slice(mails(), $before);
	same(array('bea@example.com', 'studio@bluehour.test'), array_map(function ($m) { return $m['to']; }, $sent));
	has($sent[0]['text'], "payment for order $number");
	has($sent[0]['text'], '1 × Soup bowl (€32.00)');
	has($v->go('GET', '/thanks')[1], 'Paid, thank you.');
	lacks(http('GET', "$base/pay/test?order=$number")[1], 'Pay €', 'a paid order has nothing to pay');
	$v->go('POST', "/pay/test?order=$number", array('raster_form' => 'order.test_payment'));
	same($before + 2, count(mails()), 'paying twice sends nothing more');
	same('paid', order_by_number($number)['status']);
});

test('the webhook: only this shop\'s session, for the total, when the money is in; replays and strangers change nothing', function () use ($base) {
	$v = new visitor($base);
	list(, , $headers) = $v->buy('espresso-cup', 1, array('email' => 'cai@example.com'));
	$number = number_in(header_value($headers, 'Location'));
	$paid = array('id' => 'cs_test_'.$number, 'client_reference_id' => $number, 'payment_intent' => 'pi_real_1', 'payment_status' => 'paid', 'amount_total' => 1600, 'currency' => 'eur');
	$event = function ($changes = array(), $type = 'checkout.session.completed') use ($paid) {
		return array('id' => 'evt_1', 'type' => $type, 'data' => array('object' => array_merge($paid, $changes)));
	};
	same(405, http('GET', "$base/api/order/webhook")[0]);
	list($status, $body) = signed_event($base, $event(), 't='.time().',v1='.str_repeat('0', 64));
	same(400, $status);
	has($body, 'signature');
	same(400, signed_event($base, $event(), order_payments::sign(json_encode($event()), time() - 3600))[0], 'an old signature');
	$ignored = function ($changes, $why) use ($base, $event) {
		list($status, $body) = signed_event($base, $event($changes));
		same(200, $status, "$why is answered, so the provider stops sending it");
		same($why, json_decode($body, true)['ignored']);
	};
	$ignored(array('amount_total' => 100), 'not the order\'s total');
	$ignored(array('currency' => 'usd'), 'not the order\'s total');
	$ignored(array('id' => 'cs_someone_else'), 'not this order\'s checkout');
	$ignored(array('payment_status' => 'unpaid'), 'not paid yet');
	$ignored(array('client_reference_id' => 'BH-9999'), 'no such order');
	same('unpaid', order_by_number($number)['status']);
	list($status, $body) = signed_event($base, $event());
	same(200, $status);
	same(array('received' => true, 'paid' => $number), json_decode($body, true));
	same('pi_real_1', order_by_number($number)['provider_ref']);
	$mails = count(mails());
	same(array('received' => true, 'already' => 'paid'), json_decode(signed_event($base, $event())[1], true), 'a replay changes nothing');
	same($mails, count(mails()), 'and sends nothing');
	same('not a payment', json_decode(signed_event($base, array('type' => 'charge.refunded'))[1], true)['ignored']);
	same(404, http('GET', "$base/api/order/paid")[0], 'the handler itself is static: /api can\'t reach it');
});

test('paid after the studio cancelled it: the money goes back and the studio hears', function () use ($base) {
	$v = new visitor($base);
	$before = stock('morning-mug');
	list(, , $headers) = $v->buy('morning-mug', 1, array('email' => 'dan@example.com', 'name' => 'Dan'));
	$number = number_in(header_value($headers, 'Location'));
	cms_records::act('order', order_by_number($number)['id'], 'cancel', array(), true);
	same('cancelled', order_by_number($number)['status']);
	same($before, stock('morning-mug'), 'the mug is back');
	$mails = count(mails());
	$event = array('type' => 'checkout.session.completed', 'data' => array('object' => array('id' => 'cs_test_'.$number, 'client_reference_id' => $number, 'payment_intent' => 'pi_late', 'payment_status' => 'paid', 'amount_total' => 2400, 'currency' => 'eur')));
	list($status, $body) = signed_event($base, $event);
	same(200, $status);
	same(true, json_decode($body, true)['refunded']);
	same('refunded', order_by_number($number)['status']);
	same($before, stock('morning-mug'), 'and not put back twice');
	$sent = array_slice(mails(), $mails);
	same(1, count($sent));
	same('studio@bluehour.test', $sent[0]['to']);
	has($sent[0]['text'], "$number was paid after it was cancelled");
});

test('the studio: orders to ship, Ship needs a tracking number and happens once, readonly status, refunds for admins', function () use ($base) {
	$studio = new visitor($base);
	$studio->login('studio@bluehour.test', 'studio password');
	list($status, $page) = $studio->go('GET', '/orders');
	same(200, $status);
	has($page, 'Ana &lt;script&gt;x&lt;/script&gt;', 'what buyers typed prints as text');
	$paid = cms_records::find('order', array('status' => 'paid'));
	check(count($paid) >= 2, 'the paid orders');
	$order = $paid[0];
	has($page, $order['number']);
	preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $page, $m);
	$config = json_decode($m[1], true);
	$mark = null;
	foreach ($config['marks'] as $candidate) if ($candidate['kind'] === 'item' && $candidate['collection'] === 'order' && $candidate['id'] === (int)$order['id']) $mark = $candidate;
	same(array('ship', 'mark_paid', 'cancel', 'refund'), $mark['actions'], 'an admin gets every button');
	check(!array_key_exists('provider_ref', $mark['values']) && !array_key_exists('provider_url', $mark['values']), 'the provider\'s ids stay hidden');
	$token = $studio->token('/orders');
	$act = function ($action, $id) use ($studio, $token) {
		list($status, $body) = $studio->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $id, 'action' => $action, 'csrf' => $token));
		return array($status, json_decode($body, true));
	};
	list($status, $body) = $act('ship', $order['id']);
	same(422, $status);
	same(array('tracking_missing'), $body['problems']);
	list($status, $body) = $studio->go('POST', '/api/cms/editor_save_item', array('collection' => 'order', 'id' => $order['id'], 'fields' => array('status' => 'shipped'), 'csrf' => $token));
	same(400, $status, 'the status only changes through the actions');
	list($status) = $studio->go('POST', '/api/cms/editor_save_item', array('collection' => 'order', 'id' => $order['id'], 'fields' => array('tracking' => 'RO123456785RO'), 'csrf' => $token));
	same(200, $status, 'the tracking number is typed in place');
	$before = count(mails());
	list($status, $body) = $act('ship', $order['id']);
	same(200, $status);
	same('shipped', $body['status']);
	same(array('not_now'), $act('ship', $order['id'])[1]['problems'], 'shipping twice');
	$shipped = array_slice(mails(), $before);
	same(1, count($shipped), 'one email');
	same($order['email'], $shipped[0]['to']);
	has($shipped[0]['text'], 'Tracking number: RO123456785RO');
	same(array('not_now'), $act('cancel', $order['id'])[1]['problems'], 'a shipped order can\'t be cancelled');
	same('refunded', $act('refund', $order['id'])[1]['status']);
	same(array('not_now'), $act('refund', $order['id'])[1]['problems'], 'nor refunded twice');
	list($status, $body) = $studio->go('POST', '/api/cms/editor_delete_item', array('collection' => 'order', 'id' => $order['id'], 'csrf' => $token));
	same(422, $status);
	same(array('orders_are_kept'), json_decode($body, true)['problems']);
	// a paid order never shipped: refunding it puts the pieces back
	$unshipped = cms_records::find('order', array('status' => 'paid'))[0];
	$line = $unshipped['lines'][0];
	$stock = stock($line['product']);
	same('refunded', $act('refund', $unshipped['id'])[1]['status']);
	same($stock + $line['qty'], stock($line['product']));
});

test('cancelling an unpaid order puts its pieces back once, even when the piece left the shop; editors can\'t refund', function () use ($base) {
	shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/raster').' user helper@bluehour.test --role=editor --password='.escapeshellarg('helper password'));
	$v = new visitor($base);
	$bowl = cms_records::find('product', array('slug' => 'soup-bowl'))[0];
	$before = (int)$bowl['stock'];
	list(, , $headers) = $v->buy('soup-bowl', 2);
	$number = number_in(header_value($headers, 'Location'));
	same($before - 2, stock('soup-bowl'));
	// the studio takes the bowl off the shop while the order waits
	cms_records::update('product', $bowl['id'], array('enabled' => '0'));
	$helper = new visitor($base);
	$helper->login('helper@bluehour.test', 'helper password');
	$token = $helper->token('/orders');
	$order = order_by_number($number);
	list($status, $body) = $helper->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $order['id'], 'action' => 'refund', 'csrf' => $token));
	same(422, $status);
	same(array('not_allowed'), json_decode($body, true)['problems'], 'refunds are for admins');
	list($status, $body) = $helper->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $order['id'], 'action' => 'cancel', 'csrf' => $token));
	same(200, $status);
	same('cancelled', json_decode($body, true)['status']);
	same(422, $helper->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $order['id'], 'action' => 'cancel', 'csrf' => $token))[0], 'cancelling twice');
	same($before, (int)cms_records::get('product', $bowl['id'])['stock'], 'back on the shelf, once');
	cms_records::update('product', $bowl['id'], array('enabled' => '1'));
});

test('a piece that leaves the shop leaves carts too', function () use ($base) {
	$v = new visitor($base);
	same(303, $v->go('POST', '/product/product_item/espresso-cup', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	$w = new visitor($base);
	same(303, $w->go('POST', '/product/product_item/espresso-cup', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	$cup = cms_records::find('product', array('slug' => 'espresso-cup'))[0];
	cms_records::update('product', $cup['id'], array('enabled' => '0'));
	try {
		// checking out straight away
		list(, $body) = $v->go('POST', '/checkout', array('raster_form' => 'order.checkout', 'email' => 'e@example.com', 'name' => 'E', 'address' => 'A', 'city' => 'C', 'postcode' => 'P', 'country' => 'R'));
		has($body, 'Something in your cart changed');
		// looking at the cart first
		list(, $cart) = $w->go('GET', '/cart');
		has($cart, 'your cart changed');
		has($cart, 'Cart (0)', 'the header counts what can be bought');
		has($cart, 'Nothing here yet.');
	} finally {
		cms_records::update('product', $cup['id'], array('enabled' => '1'));
	}
});

test('unpaid orders give their pieces back after an hour', function () use ($base) {
	$v = new visitor($base);
	$before = stock('morning-mug');
	list(, , $headers) = $v->buy('morning-mug', 1);
	$number = number_in(header_value($headers, 'Location'));
	R::exec('UPDATE orderdata SET created_at = ? WHERE number = ?', array(date('Y-m-d H:i:s', time() - 2 * 3600), $number));
	same(303, (new visitor($base))->buy('espresso-cup', 1)[0], 'the next checkout tidies up');
	same('cancelled', order_by_number($number)['status']);
	same($before, stock('morning-mug'));
});

test('buyers with an account see their own orders, and only theirs', function () use ($base) {
	shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/raster').' user dana@example.com --role=member --password='.escapeshellarg('dana password'));
	$dana = new visitor($base);
	$dana->login('dana@example.com', 'dana password');
	list(, , $headers) = $dana->buy('espresso-cup', 1, array('email' => 'dana@example.com', 'name' => 'Dana'));
	$number = number_in(header_value($headers, 'Location'));
	$user = R::findOne('user', ' email = ? ', array('dana@example.com'));
	same((int)$user->id, order_by_number($number)['owner']);
	$account = $dana->go('GET', '/account')[1];
	has($account, $number);
	has($account, '1 × Espresso cup');
	same(1, substr_count($account, 'class="order"'), 'only Dana\'s order');
	list($status, $body) = http('GET', "$base/account");
	check($status !== 200 || strpos($body, $number) === false, 'visitors don\'t see it');
	lacks(http('GET', "$base/orders")[1], $number, 'the studio page is closed to visitors');
	lacks($dana->go('GET', '/orders')[1], 'Ana Pop', 'and to buyers');
});

test('agents: orders over MCP with the same rules, and the run_action tool', function () use ($base) {
	$call = function ($name, $arguments) use ($base) {
		list(, $body) = http('POST', "$base/mcp", json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $arguments))), array('Authorization: Bearer shop-token', 'Content-Type: application/json'));
		return json_decode($body, true)['result'];
	};
	$overview = $call('site_overview', array())['structuredContent'];
	$types = array();
	foreach ($overview['collections'] as $c) $types[$c['name']] = $c;
	same('order', $types['order']['declared_by']);
	same(true, $types['product']['public']);
	check(!in_array('provider_ref', $types['order']['fields']), 'hidden from agents');
	$pending = cms_records::find('order', array('status' => 'unpaid'))[0];
	$refused = $call('update_item', array('collection' => 'order', 'id' => (int)$pending['id'], 'fields' => array('total' => '0.01')));
	check(!empty($refused['isError']), 'an agent can\'t change a total');
	$refused = $call('run_action', array('collection' => 'order', 'id' => (int)$pending['id'], 'action' => 'ship', 'input' => array('tracking' => 'X1')));
	has($refused['content'][0]['text'], 'not_now', 'nor ship an unpaid order');
	$made = $call('create_item', array('collection' => 'product', 'fields' => array('name' => 'Free mug', 'price' => '0', 'stock' => '1')));
	has($made['content'][0]['text'], 'price_invalid', 'product::check applies to agents too');
});

test('pay on delivery, the default: no provider, no pretending the order is paid', function () use ($base, $db, $maildir) {
	$plain = server(array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir"));
	$v = new visitor($plain);
	$csrf = $v->token('/product/product_item/soup-bowl');
	same(303, $v->go('POST', '/product/product_item/soup-bowl', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	list(, $form) = $v->go('GET', '/checkout');
	has($form, 'value="delivery" checked');
	lacks($form, 'value="card"', 'no card choice without a provider');
	list(, $body) = $v->go('POST', '/checkout', array('raster_form' => 'order.checkout', 'email' => 'mihai@example.com', 'name' => 'Mihai', 'address' => 'Strada 2', 'city' => 'Cluj', 'postcode' => '400001', 'country' => 'Romania', 'payment' => 'card'));
	has($body, 'We can\'t take payments right now', 'asking for a card anyway');
	$before = count(mails());
	list($status, , $headers) = $v->go('POST', '/checkout', array('raster_form' => 'order.checkout', 'email' => 'mihai@example.com', 'name' => 'Mihai', 'address' => 'Strada 2', 'city' => 'Cluj', 'postcode' => '400001', 'country' => 'Romania'));
	same(302, $status);
	same("$plain/thanks", header_value($headers, 'Location'), 'straight to the receipt, no payment page');
	$order = cms_records::find('order', array('email' => 'mihai@example.com'), 'newest', 1)[0];
	same('delivery', $order['payment']);
	same('unpaid', $order['status'], 'not paid, and not pretending');
	same('', $order['paid_at']);
	$thanks = $v->go('GET', '/thanks')[1];
	has($thanks, 'You\'ll pay €32.00 to the courier when it arrives');
	lacks($thanks, 'Paid, thank you');
	lacks($thanks, 'Pay now');
	$sent = array_slice(mails(), $before);
	same(array('mihai@example.com', 'studio@bluehour.test'), array_map(function ($m) { return $m['to']; }, $sent));
	has($sent[0]['text'], 'You\'ll pay €32.00 to the courier');
	has($sent[1]['text'], 'to be paid on delivery');
	// the studio ships it unpaid, and marks it paid when the courier brings the money
	$studio = new visitor($plain);
	$studio->login('studio@bluehour.test', 'studio password');
	$page = $studio->go('GET', '/orders')[1];
	has($page, $order['number'], 'in To ship');
	$token = $studio->token('/orders');
	$act = function ($action) use ($studio, $token, $order) {
		list($status, $body) = $studio->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $order['id'], 'action' => $action, 'csrf' => $token));
		return array($status, json_decode($body, true));
	};
	same(array('not_now'), $act('mark_paid')[1]['problems'], 'not before it ships');
	same(array('not_now'), $act('refund')[1]['problems'], 'nothing to refund yet');
	$studio->go('POST', '/api/cms/editor_save_item', array('collection' => 'order', 'id' => $order['id'], 'fields' => array('tracking' => 'RO999'), 'csrf' => $token));
	list($status, $shipped) = $act('ship');
	same(200, $status);
	same('shipped', $shipped['status']);
	same('', $shipped['paid_at']);
	list($status, $paid) = $act('mark_paid');
	same(200, $status);
	check($paid['paid_at'] !== '', 'the money is in');
	same('shipped', $paid['status']);
	same(array('not_now'), $act('mark_paid')[1]['problems'], 'once');
	// a card order can't ship before it is paid
	$card = cms_records::find('order', array('status' => 'unpaid', 'payment' => 'card'))[0];
	list($status, $body) = $studio->go('POST', '/api/cms/editor_action', array('collection' => 'order', 'id' => $card['id'], 'action' => 'ship', 'input' => array('tracking' => 'X'), 'csrf' => $token));
	same(array('not_now'), json_decode($body, true)['problems']);
	// and an order paid on delivery waits for the studio, however long
	R::exec('UPDATE orderdata SET created_at = ? WHERE payment = ? AND status = ?', array(date('Y-m-d H:i:s', time() - 5 * 3600), 'delivery', 'unpaid'));
	$waiting = count(cms_records::find('order', array('status' => 'unpaid', 'payment' => 'delivery')));
	(new visitor($plain))->buy('espresso-cup', 1, array('payment' => 'delivery'));
	same($waiting + 1, count(cms_records::find('order', array('status' => 'unpaid', 'payment' => 'delivery'))), 'none cancelled');
});

test('the studio\'s own account lists the studio\'s own orders, not everyone\'s', function () use ($base) {
	$studio = new visitor($base);
	$studio->login('studio@bluehour.test', 'studio password');
	// (the editor's own copy of the template's example is left out)
	$account = preg_replace('#<template data-raster-mockup.*?</template>#s', '', $studio->go('GET', '/account')[1]);
	same(0, substr_count($account, 'class="order"'), 'a guest\'s order is not the studio\'s');
	check(substr_count($studio->go('GET', '/orders')[1], 'class="order') > 3, 'while /orders has them all');
});

test('outside development the pretend provider and the example\'s secret are off, and checkout sells nothing', function () use ($tmp, $root, $maildir) {
	$env = array('RASTER_ENV' => 'production', 'RASTER_DB' => "$tmp/prod.sqlite", 'RASTER_URL' => 'https://bluehour.example/', 'RASTER_MAIL' => "log://$maildir");
	$cmd = function ($args) use ($env, $root) {
		$process = proc_open(array_merge(array(PHP_BINARY), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, array_merge(getenv(), $env, array('RASTER_APP' => 'shop')));
		$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
		return array(proc_close($process), $out);
	};
	list($code, $out) = $cmd(array("$root/bin/raster", 'schema', '--apply'));
	same(0, $code, $out);
	has($out, 'created table productdata');
	has($out, 'created table orderdata');
	same(0, $cmd(array("$root/bin/raster", 'schema', '--check'))[0]);
	same(0, $cmd(array("$root/shop/seed.php"))[0]);
	// an unpaid order, as if one were left from before
	list($code, $out) = $cmd(array('-r', 'require "'.$root.'/system/boot.php"; boot::$appname = "shop"; boot::cli(); $o = cms_records::create("order", array("number" => "BH-7", "lines" => array(array("piece" => "x", "qty" => 1)), "total" => "1.00", "currency" => "EUR")); echo $o["status"];'));
	same('unpaid', $out);
	$prod = server($env);
	$v = new visitor($prod);
	same(303, $v->go('POST', '/product/product_item/tall-vase', array('raster_form' => 'cart.add', 'qty' => '1'))[0]);
	list($status, $body) = $v->checkout();
	same(200, $status);
	has($body, 'We can\'t take payments right now');
	has($v->go('GET', '/')[1], '€68.00 · 1 left', 'nothing left the shelf');
	lacks(http('GET', "$prod/pay/test?order=BH-7")[1], 'Pay €', 'no pretend payments');
	$event = array('type' => 'checkout.session.completed', 'data' => array('object' => array('id' => 'cs_test_BH-7', 'client_reference_id' => 'BH-7', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur')));
	$payload = json_encode($event);
	$signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec_test_only_for_the_example');
	same(400, http('POST', "$prod/api/order/webhook", $payload, array('Content-Type: application/json', "Stripe-Signature: $signature"))[0], 'the example\'s public secret is refused');
});

test('when Stripe can\'t be reached, nothing is ordered and the cart is kept', function () use ($db, $maildir) {
	$stripe = server(array('RASTER_DB' => $db, 'RASTER_MAIL' => "log://$maildir", 'SHOP_PAYMENTS' => 'stripe', 'STRIPE_SECRET_KEY' => 'sk_test_not_a_real_key', 'STRIPE_WEBHOOK_SECRET' => 'whsec_example_stripe'));
	$v = new visitor($stripe);
	$before = stock('soup-bowl');
	$orders = count(cms_records::find('order'));
	list($status, $body) = $v->buy('soup-bowl', 1);
	same(200, $status);
	has($body, 'We can\'t take payments right now');
	same($before, stock('soup-bowl'), 'the bowl is back on the shelf');
	$last = cms_records::find('order', array(), 'newest', 1)[0];
	same($orders + 1, count(cms_records::find('order')));
	same('cancelled', $last['status'], 'the order it started is cancelled');
	has($v->go('GET', '/cart')[1], 'Cart (1)', 'and the cart is kept');
});

test('Stripe Checkout: the session the shop would ask Stripe for', function () {
	$order = array('number' => 'BH-1042', 'email' => 'ana@example.com', 'currency' => 'EUR', 'lines' => array(
		array('piece' => 'Morning mug', 'qty' => 2, 'cents' => 2400),
		array('piece' => 'Soup bowl', 'qty' => 1, 'cents' => 3200),
	));
	$fields = order_payments::checkout_session($order);
	same('payment', $fields['mode']);
	same('BH-1042', $fields['client_reference_id'], 'the webhook finds the order by it');
	same(2, $fields['line_items[0][quantity]']);
	same(2400, $fields['line_items[0][price_data][unit_amount]']);
	same('eur', $fields['line_items[1][price_data][currency]']);
	same('Soup bowl', $fields['line_items[1][price_data][product_data][name]']);
});

test('the shop\'s code, config, data and seed script are never served', function () use ($base) {
	foreach (array('/shop/seed.php', '/shop/config/the_app.php', '/shop/models/order/payments.php', '/shop/data/shop.sqlite', '/shop/views/kiln/orders.html') as $path) {
		same(403, http('GET', $base.$path)[0], $path);
	}
	same(200, http('GET', "$base/shop/views/kiln/style.css")[0], 'theme files are');
});

// ## 2.1.8 batch A: page cache
// (batch A adds its tests here)

// ## 2.1.8 batch B: errors and /api
// (batch B adds its tests here)

// ## 2.1.8 batch C: list SQL
// (batch C adds its tests here)

// ## 2.1.8 batch D: accounts
// (batch D adds its tests here)

// ## 2.1.8 batch E: template output
// (batch E adds its tests here)

// ## 2.1.8 batch F: upgrade tooling
// (batch F adds its tests here)

// ## 2.1.8 batch G: MCP themes
// (batch G adds its tests here)

// ## 2.1.8 batch H: row loop
// (batch H adds its tests here)

test('lint is clean and there were no PHP warnings', function () use ($root, $tmp) {
	$out = shell_exec('RASTER_APP=shop '.escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/bin/raster").' lint 2>&1');
	has($out, 'No errors, 0 warning(s)');
	$log = is_file("$tmp/php-errors.log") ? file_get_contents("$tmp/php-errors.log") : '';
	check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $log, $m), $m ? $m[0] : '');
});

echo "\n\n$passed passed, ".count($failed)." failed\n";
foreach ($failed as $failure) echo "  ✗ $failure\n";
exit($failed ? 1 : 0);
