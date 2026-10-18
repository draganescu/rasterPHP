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
			// incomplete: 1 until every reader has the issue (rows from before it existed are complete)
			'newsletterissue' => array('url' => '', 'subject' => '', 'recipients' => 0, 'sent_at' => '', 'incomplete' => 0),
			// one row per address an issue was handed over to
			'newsletterdelivery' => array('issue' => 0, 'email' => '', 'sent_at' => ''),
		);
	}

	static function table_ready() {
		return cms_store::table_exists('subscriber');
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
	// listener). Returns 'pending', 'confirmed', 'already' or false for a
	// bad address. The address is looked up and stored in one transaction,
	// so on SQLite two sign-ups at once leave one subscriber. Signing up
	// again while pending keeps the token, so every link sent still works.
	// newsletter.subscribed waits for the commit (also inside someone
	// else's transaction), and the confirmation email is sent by its
	// listener, confirmation_mail.
	static function subscribe($email, $name = '', $source = '') {
		$email = strtolower(trim((string)$email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
		self::connect();
		$double = config::get('newsletter_double_opt_in', true);
		return cms_records::transaction(function () use ($email, $name, $source, $double) {
			$subscriber = self::table_ready() ? R::findOne('subscriber', ' email = ? ', array($email)) : null;
			if ($subscriber && $subscriber->status === 'confirmed') return 'already';
			if (!$subscriber) {
				$subscriber = R::dispense('subscriber');
				$subscriber->email = $email;
				$subscriber->created_at = R::isoDateTime();
			}
			$subscriber->name = trim((string)$name);
			if ($subscriber->status !== 'pending' || !$subscriber->token) $subscriber->token = bin2hex(random_bytes(20));
			$subscriber->source = (string)$source;
			if ($double) {
				$subscriber->status = 'pending';
			} else {
				$subscriber->status = 'confirmed';
				$subscriber->confirmed_at = R::isoDateTime();
			}
			R::store($subscriber);
			cms_records::dispatch('newsletter.subscribed', array('email' => $email, 'name' => $subscriber->name, 'status' => $subscriber->status, 'source' => $subscriber->source));
			return $subscriber->status;
		});
	}

	// listens to newsletter.subscribed (system/config/events.php): a pending
	// address gets the link to confirm. A site that sends its own unbinds it:
	//   event::unbind('newsletter.subscribed')->from('newsletter', 'confirmation_mail');
	function confirmation_mail($subscribed) {
		if (!is_array($subscribed) || !isset($subscribed['email'], $subscribed['status']) || $subscribed['status'] !== 'pending') return null;
		self::connect();
		$subscriber = self::table_ready() ? R::findOne('subscriber', ' email = ? ', array(strtolower(trim((string)$subscribed['email'])))) : null;
		if (!$subscriber || $subscriber->status !== 'pending' || !$subscriber->token) return null;
		// through the site's the_mail, when it has one
		$mail = controller::model_class('mail');
		$sent = $mail::send_view('_email/newsletter_confirm', $subscriber->email, array(
			'confirm_url' => self::page_url('newsletter_confirm_page', 'newsletter-confirm', $subscriber->token),
			'name' => (string)$subscriber->name,
		));
		if (!$sent) log::error('Newsletter confirmation email failed: '.$mail::$last_error);
		return null;
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

	// the last send_issue(): how many it mailed, and the addresses that failed (email => error)
	public static $sent = 0;
	public static $failures = array();

	static function subscribers() {
		self::connect();
		return self::table_ready() ? R::find('subscriber', " status = 'confirmed' ORDER BY id ") : array();
	}

	// the latest issue sent from this URL, or null
	static function issue($url) {
		self::connect();
		if (!cms_store::table_exists('newsletterissue')) return null;
		return R::findOne('newsletterissue', ' url = ? ORDER BY id DESC ', array((string)$url));
	}

	// the confirmed subscribers an issue has not reached yet (all of them for null)
	static function recipients($issue) {
		if (!$issue || !$issue->id || !cms_store::table_exists('newsletterdelivery')) return self::subscribers();
		if (!self::table_ready()) return array();
		return R::find('subscriber', " status = 'confirmed' AND email NOT IN (SELECT email FROM newsletterdelivery WHERE issue = ?) ORDER BY id ", array((int)$issue->id));
	}

	// Sends a prepared issue (see prepare_issue) to the confirmed subscribers
	// who don't have it yet, noting each address as it is handed over, so a
	// run that stopped goes on where it stopped and failed addresses are
	// tried again. $again starts over and mails everyone. Returns false when
	// the issue already reached everyone, else its newsletterissue row, whose
	// recipients counts every address it reached.
	// A mail handed over just as the process is killed is not noted yet, so
	// that one reader may get it twice.
	static function send_issue($url, $html, $subject, $again = false) {
		self::connect();
		self::$sent = 0;
		self::$failures = array();
		// the tables as `raster schema --apply` makes them, also on a frozen
		// database: missing columns added, and on MySQL the small ones that
		// 2.2.0 and earlier made (tinyint recipients, varchar(191) subject)
		// widened, since frozen RedBean would never grow them
		foreach (array('newsletterissue', 'newsletterdelivery') as $table) {
			$types = array_map(array('cms_types', 'of_default'), self::schema()[$table]);
			cms_types::ensure($table, $types);
			cms_types::widen($table, $types);
		}
		$issue = self::issue($url);
		if ($issue && !$issue->incomplete && !$again) return false;
		if (!$issue || $again) {
			$issue = R::dispense('newsletterissue');
			$issue->url = (string)$url;
			$issue->recipients = 0;
			$issue->sent_at = R::isoDateTime();
		}
		$issue->subject = (string)$subject;
		$issue->incomplete = 1;
		R::store($issue);
		$mail = controller::model_class('mail');
		$mail::keep_open();
		try {
			foreach (self::recipients($issue) as $subscriber) {
				if (!self::deliver($html, $subject, $subscriber->email, $subscriber->token)) {
					self::$failures[$subscriber->email] = $mail::$last_error;
					continue;
				}
				$delivery = R::dispense('newsletterdelivery');
				$delivery->issue = (int)$issue->id;
				$delivery->email = $subscriber->email;
				$delivery->sent_at = R::isoDateTime();
				R::store($delivery);
				self::$sent++;
			}
		} finally {
			$mail::close();
		}
		$issue->recipients = R::count('newsletterdelivery', ' issue = ? ', array((int)$issue->id));
		$issue->incomplete = self::$failures ? 1 : 0;
		R::store($issue);
		return $issue;
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
		$mail = controller::model_class('mail');
		return $mail::send($email, $subject, $body, null, array(
			'List-Unsubscribe' => '<'.$unsubscribe.'>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		));
	}
}
