<?php
/**
* Newsletter
*
* Sign ups with double opt-in, confirmation, unsubscribe, and sending any
* page of the site as an issue. All text lives in your templates.
*
*   <!-- render.newsletter.signup -->       a form with an email input (name optional)
*   <!-- render.newsletter.confirm -->      the page the confirmation email links to
*   <!-- render.newsletter.unsubscribe -->  the page unsubscribe links go to (a form with a button)
*   <!-- print.newsletter.count /-->        confirmed subscribers
*   <!-- print.@href.unsubscribe_url --> in an issue: the reader's own unsubscribe link,
*     written as <a href="<!-- print.newsletter.unsubscribe_url /-->">Unsubscribe</a>
*
* Alerts: check_email, subscribed, confirmed, confirm_invalid, unsubscribed,
* unsubscribe_invalid.
*
* Emails: views _email/newsletter_confirm.html (print.self.confirm_url).
* Sending: php bin/raster send /news/news_item/my-post  (see raster help).
* The page is sent as it looks on the site, minus scripts, forms and <nav>.
*
* Settings:
*   config::set('newsletter_double_opt_in')->to(true);
*   config::set('newsletter_confirm_page')->to('newsletter-confirm');
*   config::set('newsletter_unsubscribe_page')->to('newsletter-unsubscribe');
*/
class newsletter
{
	const MARKER = '{{raster:unsubscribe_url}}';

	static function connect() {
		database::instance('cms');
		if (!database::configured()) throw new RuntimeException('No database for the '.config::get('environment').' environment');
	}

	// the tables this model uses, for `raster schema --apply`
	static function schema() {
		return array(
			'subscriber' => array('email' => '', 'name' => '', 'status' => '', 'token' => '', 'source' => '', 'created_at' => '', 'confirmed_at' => '', 'unsubscribed_at' => ''),
			'newsletterissue' => array('url' => '', 'subject' => '', 'recipients' => 0, 'sent_at' => ''),
		);
	}

	static function table_ready() {
		try { return in_array('subscriber', R::inspect()); } catch (Exception $e) { return false; }
	}

	static function page_url($setting, $default, $token = null) {
		$url = rtrim(config::get('link_uri'), '/').'/'.ltrim((string)config::get($setting, $default), '/');
		return $token === null ? $url : $url.'?token='.$token;
	}

	static function find_by_token($token) {
		if (!is_string($token) || !preg_match('/^[a-f0-9]{40}$/', $token) || !self::table_ready()) return null;
		return R::findOne('subscriber', ' token = ? ', array($token));
	}

	// fields: email, name (optional)
	function signup() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return template::instance()->form_state();
		$status = self::subscribe(util::post('email'), util::post('name'), '/'.ltrim(strtok((string)config::get('uri_string'), '?'), '/'));
		if ($status === false) {
			$v->raise('email_invalid');
			return template::instance()->form_state();
		}
		// people already subscribed get the same answer, so nobody learns who is
		if ($status === 'pending' || ($status === 'already' && config::get('newsletter_double_opt_in', true))) util::done('check_email');
		util::done('subscribed');
		return false;
	}

	// Subscribes an address from anywhere (a form, another model, a
	// listener). With double opt-in it sends the confirmation email.
	// Returns 'pending', 'confirmed', 'already' or false for a bad address.
	static function subscribe($email, $name = '', $source = '') {
		$email = strtolower(trim((string)$email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
		self::connect();
		$subscriber = self::table_ready() ? R::findOne('subscriber', ' email = ? ', array($email)) : null;
		if ($subscriber && $subscriber->status === 'confirmed') return 'already';
		if (!$subscriber) {
			$subscriber = R::dispense('subscriber');
			$subscriber->email = $email;
			$subscriber->created_at = R::isoDateTime();
		}
		$subscriber->name = trim((string)$name);
		$subscriber->token = bin2hex(random_bytes(20));
		$subscriber->source = (string)$source;
		if (config::get('newsletter_double_opt_in', true)) {
			$subscriber->status = 'pending';
			R::store($subscriber);
			$sent = mail::send_view('_email/newsletter_confirm', $email, array(
				'confirm_url' => self::page_url('newsletter_confirm_page', 'newsletter-confirm', $subscriber->token),
				'name' => $subscriber->name,
			));
			if (!$sent) log::error('Newsletter confirmation email failed: '.mail::$last_error);
		} else {
			$subscriber->status = 'confirmed';
			$subscriber->confirmed_at = R::isoDateTime();
			R::store($subscriber);
		}
		event::dispatch('newsletter.subscribed', array('email' => $email, 'name' => $subscriber->name, 'status' => $subscriber->status, 'source' => $subscriber->source));
		return $subscriber->status;
	}

	// the page behind the confirmation link (?token=...)
	function confirm() {
		$v = validation::get();
		self::connect();
		$subscriber = self::find_by_token(util::get('token'));
		if (!$subscriber || $subscriber->status === 'unsubscribed') {
			$v->raise('confirm_invalid');
			return array();
		}
		if ($subscriber->status !== 'confirmed') {
			$subscriber->status = 'confirmed';
			$subscriber->confirmed_at = R::isoDateTime();
			R::store($subscriber);
			event::dispatch('newsletter.confirmed', array('email' => $subscriber->email, 'name' => (string)$subscriber->name));
		}
		$v->raise('confirmed');
		return array(array('email' => $subscriber->email, 'name' => (string)$subscriber->name));
	}

	// the page behind unsubscribe links: shows the form, the button unsubscribes.
	// Mail apps' one-click unsubscribe posts here directly.
	function unsubscribe() {
		$v = validation::get();
		self::connect();
		$token = util::get('token') ?: util::post('token');
		$subscriber = self::find_by_token($token);
		if (!$subscriber) {
			$v->raise('unsubscribe_invalid');
			return array();
		}
		if ($subscriber->status === 'unsubscribed') {
			$v->raise('unsubscribed');
			return array();
		}
		if (!$v->submitted()) return template::instance()->form_state(array('token' => $token, 'email' => $subscriber->email));
		$subscriber->status = 'unsubscribed';
		$subscriber->unsubscribed_at = R::isoDateTime();
		R::store($subscriber);
		event::dispatch('newsletter.unsubscribed', array('email' => $subscriber->email, 'name' => (string)$subscriber->name));
		$v->raise('unsubscribed');
		return array();
	}

	function count() {
		self::connect();
		return self::table_ready() ? (string)R::count('subscriber', " status = 'confirmed' ") : '0';
	}

	// in an issue: each reader's link. On the web: the unsubscribe page.
	function unsubscribe_url() {
		return getenv('RASTER_SENDING') ? self::MARKER : self::page_url('newsletter_unsubscribe_page', 'newsletter-unsubscribe');
	}

	// ##Sending, used by `php bin/raster send`

	static function subscribers() {
		self::connect();
		return self::table_ready() ? R::find('subscriber', " status = 'confirmed' ORDER BY id ") : array();
	}

	// turns a rendered page into an email: no scripts or <base>, absolute links
	static function prepare_issue($html) {
		$base = null;
		if (preg_match('/<base\s+href=["\']([^"\']+)["\']/i', $html, $m)) $base = $m[1];
		$html = preg_replace('#<base\b[^>]*>\s*#i', '', $html);
		$html = preg_replace('#<script\b.*?</script>\s*#is', '', $html);
		// forms and navigation don't belong in an email
		$html = preg_replace('#<form\b.*?</form>\s*#is', '', $html);
		$html = preg_replace('#<nav\b.*?</nav>\s*#is', '', $html);
		if ($base) {
			$html = preg_replace_callback('/\b(src|href)=(["\'])(?!https?:|mailto:|tel:|#|\/\/|data:|\{\{)([^"\']*)\2/i', function ($m) use ($base) {
				return $m[1].'='.$m[2].$base.ltrim($m[3], './').$m[2];
			}, $html);
		}
		return $html;
	}

	static function subject_of($html) {
		return preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES) : '';
	}

	// sends a prepared issue to one address; returns true when handed over
	static function deliver($html, $subject, $email, $token) {
		$unsubscribe = self::page_url('newsletter_unsubscribe_page', 'newsletter-unsubscribe', $token);
		$body = str_replace(self::MARKER, htmlspecialchars($unsubscribe, ENT_QUOTES), $html);
		return mail::send($email, $subject, $body, null, array(
			'List-Unsubscribe' => '<'.$unsubscribe.'>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		));
	}
}
