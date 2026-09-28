<?php
// Blue Hour Ceramics: an example shop built on records. See shop/README.md.
config::set('theme')->to('kiln');

// accounts: buyers may make one to see their orders; staff see /orders
config::set('protected')->to(array('orders' => 'editor', 'account' => 'member'));
config::set('after_login')->to('account');

// the shop's money: prices are in this currency, with two decimals
config::set('shop_currency')->to('EUR');
config::set('shop_name')->to('Blue Hour Ceramics');
config::set('mail_from')->to('Blue Hour Ceramics <hello@bluehour.test>');
config::set('shop_staff_email')->to('studio@bluehour.test');

// Payments. Buyers can always pay the courier on delivery. To also take
// cards, set a provider: 'test' is a pretend one built into this example,
// for development only (its page, views/kiln/pay/test.html, sends the same
// signed event Stripe would).
// 'stripe' uses Stripe Checkout: set SHOP_PAYMENTS=stripe, STRIPE_SECRET_KEY
// and STRIPE_WEBHOOK_SECRET, and point a Stripe webhook for
// checkout.session.completed at https://<your site>/api/order/webhook
$env = function ($name, $default) { $value = getenv($name); return $value === false || $value === '' ? $default : $value; };
config::set('shop_payments')->to($env('SHOP_PAYMENTS', ''));
config::set('stripe_secret_key')->to($env('STRIPE_SECRET_KEY', ''));
// signs the events the webhook accepts; the test provider signs with it too.
// The default is public, so it only works in development.
config::set('shop_webhook_secret')->to($env('STRIPE_WEBHOOK_SECRET', 'whsec_test_only_for_the_example'));

// the pretend provider's page and the order pages are never cached or in the sitemap
config::set('sitemap_skip')->to(array('login', 'account', 'register', 'cart', 'checkout', 'thanks', 'orders', 'pay/test'));
config::set('page_cache_skip')->to(array('cart', 'checkout', 'thanks', 'pay'));
