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
		database::instance();
		if (!database::configured()) throw new RuntimeException('No database for the '.config::get('environment').' environment');
	}

	// the tables this model uses, for `raster schema --apply`
	static function schema() {
		$tables = array(
			// confirm_sent_at: when the confirmation was last mailed, so signing up
			// again at once mails nothing
			'subscriber' => array('email' => '', 'name' => '', 'status' => '', 'token' => '', 'source' => '', 'created_at' => '', 'confirmed_at' => '', 'unsubscribed_at' => '', 'confirm_sent_at' => ''),
			// incomplete: 1 until every reader has the issue (rows from before it existed are complete)
			'newsletterissue' => array('url' => '', 'subject' => '', 'recipients' => 0, 'sent_at' => '', 'incomplete' => 0),
			// one row per address an issue was handed over to
			'newsletterdelivery' => array('issue' => 0, 'email' => '', 'sent_at' => ''),
		);
		// the language a subscriber reads, only a multilingual site's (#200 D13)
		if (i18n::enabled()) $tables['subscriber']['lang'] = '';
		return $tables;
	}

	// what a site using the newsletter needs: the confirmation email's view,
	// the pages that answer its links (the sitemap and a static export leave
	// them out) and the settings that change it
	static function requires() {
		return array(
			'emails' => array('render.newsletter.signup' => array('_email/newsletter_confirm')),
			'pages' => array(config::get('newsletter_confirm_page', 'newsletter-confirm'), config::get('newsletter_unsubscribe_page', 'newsletter-unsubscribe')),
			'settings' => array('newsletter_double_opt_in', 'newsletter_confirm_page', 'newsletter_unsubscribe_page'),
		);
	}

	static function table_ready() {
		return cms_store::table_exists('subscriber');
	}

	// deprecated for util::url() (system/tools/deprecations.php)
	static function page_url($setting, $default, $token = null) {
		return util::url(config::get($setting, $default), $token === null ? array() : array('token' => $token));
	}

	// whether subscriber.confirm_sent_at can be written: a frozen database
	// has it once `schema --apply` made it
	protected static function stamps_confirmations() {
		return !R::getRedBean()->isFrozen() || array_key_exists('confirm_sent_at', cms_store::columns('subscriber'));
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
		if ($reasons = validation_spam::holds($v->suspect(array()))) return $v->hold($reasons);
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
				// the language they signed up in, which they read (#200 D13);
				// signing up again leaves it; a frozen database without the
				// column skips it
				if (i18n::enabled() && (!R::getRedBean()->isFrozen() || array_key_exists('lang', cms_store::columns('subscriber')))) $subscriber->lang = i18n::current();
			}
			$subscriber->name = trim((string)$name);
			if ($subscriber->status !== 'pending' || !$subscriber->token) {
				$subscriber->token = bin2hex(random_bytes(20));
				// a new link is mailed whenever it was asked for
				if (self::stamps_confirmations()) $subscriber->confirm_sent_at = null;
			}
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
		// through the site's the_mail, when it has one
		$mail = controller::model_class('mail');
		$subscriber = self::claim_confirmation(strtolower(trim((string)$subscribed['email'])), $mail);
		if (!$subscriber) return null;
		// in the subscriber's language, the link too (#200 D13)
		$lang = i18n::of($subscriber);
		$sent = $mail::in_language($lang, function () use ($mail, $subscriber, $lang) {
			return $mail::send_view('_email/newsletter_confirm', $subscriber->email, array(
				'confirm_url' => util::url(config::get('newsletter_confirm_page', 'newsletter-confirm'), array('token' => $subscriber->token)),
				'name' => (string)$subscriber->name,
			), array(), $lang);
		});
		if (!$sent) log::error('Newsletter confirmation email failed: '.$mail::$last_error);
		return null;
	}

	// The pending subscriber a confirmation goes to, noted as sent before it
	// is sent; null when there is none, or one went out within a few minutes
	// (the link already sent still works). Read and noted under the lock, so
	// sign-ups at once send one email, and a sign-up holding the lock never
	// finds the row changed under it (on MariaDB that fails its write, #213).
	// When the lock stays busy the email goes anyway, unnoted.
	protected static function claim_confirmation($email, $mail) {
		$claim = function ($locked) use ($email, $mail) {
			$subscriber = self::table_ready() ? R::findOne('subscriber', ' email = ? ', array($email)) : null;
			if (!$subscriber || $subscriber->status !== 'pending' || !$subscriber->token) return null;
			if (!$locked || !self::stamps_confirmations()) return $subscriber;
			if ($mail::sent_lately($subscriber->confirm_sent_at)) return null;
			$subscriber->confirm_sent_at = R::isoDateTime();
			R::store($subscriber);
			return $subscriber;
		};
		try {
			return cms_records::transaction(function () use ($claim) { return $claim(true); });
		} catch (RuntimeException $e) {
			log::error('Newsletter confirmation not noted as sent: '.$e->getMessage());
			return $claim(false);
		}
	}

	// the page behind the confirmation link (?token=...)
	function confirm() {
		$v = validation::get();
		self::connect();
		$token = util::get('token');
		$subscriber = self::find_by_token($token);
		// written under the lock, read afresh, as a sign-up is, so one for
		// this address at the same moment can't put it back to pending. A
		// bad token or an address already confirmed doesn't wait for it.
		$confirm = function () use ($token) {
			$subscriber = self::find_by_token($token);
			if ($subscriber && !in_array($subscriber->status, array('confirmed', 'unsubscribed'), true)) {
				$subscriber->status = 'confirmed';
				$subscriber->confirmed_at = R::isoDateTime();
				R::store($subscriber);
				cms_records::dispatch('newsletter.confirmed', array('email' => $subscriber->email, 'name' => (string)$subscriber->name));
			}
			return $subscriber;
		};
		if ($subscriber && !in_array($subscriber->status, array('confirmed', 'unsubscribed'), true)) $subscriber = cms_records::transaction($confirm);
		if (!$subscriber || $subscriber->status === 'unsubscribed') {
			$v->raise('confirm_invalid');
			return array();
		}
		$v->raise('confirmed');
		// what the visitor typed, escaped: HTML views print rows as they are
		return array(array('email' => util::e($subscriber->email), 'name' => util::e($subscriber->name)));
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
		// under the lock, read afresh, as confirm() does
		$found = cms_records::transaction(function () use ($token) {
			$subscriber = self::find_by_token($token);
			if (!$subscriber) return false;
			if ($subscriber->status === 'unsubscribed') return true;
			$subscriber->status = 'unsubscribed';
			$subscriber->unsubscribed_at = R::isoDateTime();
			R::store($subscriber);
			cms_records::dispatch('newsletter.unsubscribed', array('email' => $subscriber->email, 'name' => (string)$subscriber->name));
			return true;
		});
		$v->raise($found ? 'unsubscribed' : 'unsubscribe_invalid');
		return array();
	}

	function count() {
		self::connect();
		if (!self::table_ready()) return '0';
		$rows = database::instance('newsletter')->confirmed_count();
		return (string)(int)$rows[0]['total'];
	}

	// in an issue: each reader's link. On the web: the unsubscribe page.
	function unsubscribe_url() {
		return getenv('RASTER_SENDING') ? self::MARKER : util::url(config::get('newsletter_unsubscribe_page', 'newsletter-unsubscribe'));
	}

	// ##Sending, used by `php bin/raster send`

	// the last send_issue(): how many it mailed, and the addresses that failed (email => error)
	public static $sent = 0;
	public static $failures = array();

	// the confirmed subscribers, as rows (sql/confirmed_subscribers.sql)
	static function subscribers() {
		self::connect();
		return self::table_ready() ? database::instance('newsletter')->confirmed_subscribers() : array();
	}

	// the latest issue sent from this URL, or null
	static function issue($url) {
		self::connect();
		if (!cms_store::table_exists('newsletterissue')) return null;
		return R::findOne('newsletterissue', ' url = ? ORDER BY id DESC ', array((string)$url));
	}

	// the confirmed subscribers an issue has not reached yet (all of them for
	// null), as rows (sql/unreached_subscribers.sql). $lang: on a
	// multilingual site, only those who read that language (i18n::of: a
	// subscriber with none reads the default); null, or off, all of them
	// (#200).
	static function recipients($issue, $lang = null) {
		if ($lang !== null && i18n::enabled()) return self::reading($issue, (string)$lang);
		if (!$issue || !$issue->id || !cms_store::table_exists('newsletterdelivery')) return self::subscribers();
		if (!self::table_ready()) return array();
		return database::instance('newsletter')->unreached_subscribers((int)$issue->id);
	}

	// recipients() of those who read $lang, with the rows' usual keys. A
	// database without subscriber.lang (frozen before the column, or nobody
	// signed up on a multilingual site yet) reads the default language only.
	protected static function reading($issue, $lang) {
		$self = i18n::of(array('lang' => $lang));
		if (!self::table_ready() || !array_key_exists('lang', cms_store::columns('subscriber'))) {
			return $self === i18n::default_language() ? self::recipients($issue) : array();
		}
		self::connect();
		$query = database::instance('newsletter');
		$rows = $issue && $issue->id && cms_store::table_exists('newsletterdelivery') ? $query->unreached_subscribers_lang((int)$issue->id) : $query->confirmed_subscribers_lang();
		$found = array();
		foreach ($rows as $row) {
			if (i18n::of($row) !== $self) continue;
			unset($row['lang']);
			$found[] = $row;
		}
		return $found;
	}

	// Sends a prepared issue (see prepare_issue) to the confirmed subscribers
	// who don't have it yet, noting each address as it is handed over, so a
	// run that stopped goes on where it stopped and failed addresses are
	// tried again. $again starts over and mails everyone. Returns false when
	// the issue already reached everyone, else its newsletterissue row, whose
	// recipients counts every address it reached.
	// A mail handed over just as the process is killed is not noted yet, so
	// that one reader may get it twice.
	// $lang: the language $html is in, sent to recipients($issue, $lang)
	// only, with the unsubscribe page at that language's address; null, or
	// off, to everyone. On, `raster send` passes each language's own address
	// of the page as $url (ro/news), so each language is sent once.
	static function send_issue($url, $html, $subject, $again = false, $lang = null) {
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
			foreach (self::recipients($issue, $lang) as $subscriber) {
				if (!self::deliver($html, $subject, $subscriber['email'], $subscriber['token'], $lang)) {
					self::$failures[$subscriber['email']] = $mail::$last_error;
					continue;
				}
				$delivery = R::dispense('newsletterdelivery');
				$delivery->issue = (int)$issue->id;
				$delivery->email = $subscriber['email'];
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

	// sends a prepared issue to one address; returns true when handed over.
	// $lang (a multilingual site's): the unsubscribe link is that language's page.
	static function deliver($html, $subject, $email, $token, $lang = null) {
		$link = function () use ($token) {
			return util::url(config::get('newsletter_unsubscribe_page', 'newsletter-unsubscribe'), array('token' => $token));
		};
		$unsubscribe = $lang !== null && i18n::enabled() ? i18n::with($lang, $link) : $link();
		$body = str_replace(self::MARKER, htmlspecialchars($unsubscribe, ENT_QUOTES), $html);
		$mail = controller::model_class('mail');
		return $mail::send($email, $subject, $body, null, array(
			'List-Unsubscribe' => '<'.$unsubscribe.'>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		));
	}
}
