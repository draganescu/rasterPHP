<?php
/**
* Contact
*
* Messages from the site's contact form, kept for staff. Every message is
* stored, as the contactmessage type: name, email, phone, subject,
* message, and what the model works out (title, the date and time it
* came, which is also its slug; page, the path it was sent from; status;
* spam, why it looks like spam). A message that looks like spam
* (validation::get()->suspect()) is kept aside with status spam, and the
* visitor is thanked all the same, so a bot learns nothing. Staff get an
* email about every other one. The form:
*
*   <!-- render.contact.form --><form method="post">…</form><!-- /render.contact.form -->
*
* Regions:
*   <!-- render.contact.form -->  fields name email phone subject message (a
*     the_contact override adds more); ?done=sent when stored
* For staff (editors) only, nothing for anyone else:
*   <!-- render.contact.messages('status=new&order=-created_at') -->
*     the messages, editable in place with their actions; options as
*     cms_records::find takes them
*   <!-- print.contact.count('status=new') -->  how many; '' for anyone else
*
* Statuses: new, done (staff answered it), spam. The actions: done, spam
* (reason staff), not_spam (back to new, its reasons cleared).
*
* Alerts: sent, name_invalid, email_invalid, phone_invalid, subject_invalid
* (a control character, or over 200 characters; an email that isn't one, is
* over 254, or isn't a plain address: quotes, brackets, spaces, a second @),
* message_invalid (empty, or over contact_max_length characters);
* for the actions not_done_spam and not_spam_invalid.
*
* Emails, a view the site writes: _email/contact_received to staff, after
* the answer, for a message that isn't spam, with Reply-To the visitor. It
* gets the message's fields, page_url, staff_url and site_url. Nothing is
* ever mailed to the visitor. Events, after the commit: contact.received
* (who: visitor, or staff after not_spam) and contact.flagged (kept as
* spam), with the message's fields.
*
* Settings:
*   config::set('contact_staff_email')->to('staff@example.com');  else the sender
*   config::set('contact_staff_page')->to('staff');  the staff page, for staff_url
*   config::set('contact_max_length')->to(5000);     characters in a message
*   config::set('contact_spam_days')->to(30);        days spam is kept; false keeps it
*/
class contact
{
	// why the post being stored looks like spam, from form() for fill(); empty
	// for every other write
	static $reasons = array();
	// while form() stores a visitor's message
	static $writing = false;

	static function types() {
		$types = array(
			'contactmessage' => array(
				// title first: the slug is made from it, never from the email
				'fields' => array(
					'title' => '', 'name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => '',
					'page' => '', 'status' => 'new', 'spam' => '',
				),
				// visitors send them, nobody reads their own back, and staff
				// don't write them
				'create' => 'visitor',
				'public' => false,
				'readonly' => array('title', 'page', 'status', 'spam'),
				// form() runs the checks itself and keeps a suspect as spam
				'spam' => false,
				'actions' => array('done' => 'editor', 'spam' => 'editor', 'not_spam' => 'editor'),
			),
		);
		// the language the sender wrote in, for staff's email: only a
		// multilingual site's; staff and agents see it, and only the
		// model writes it (#200 D13)
		if (i18n::enabled()) {
			$types['contactmessage']['fields']['lang'] = '';
			$types['contactmessage']['readonly'][] = 'lang';
		}
		return $types;
	}

	// A new message's title is when it came. One a visitor sent on the form
	// also gets its page and, when it looks like spam, its status and
	// reasons; one staff write (the editor, MCP) stays new.
	static function fill($type, $after, $before) {
		if ($type !== 'contactmessage' || $before) return array();
		$values = array('title' => date('Y-m-d H:i'));
		if (!self::$writing || cms_records::writer() !== 'visitor') return $values;
		$values['page'] = '/'.ltrim((string)strtok((string)config::get('uri_string'), '?'), '/');
		if (self::$reasons) {
			$values['status'] = 'spam';
			$values['spam'] = implode(',', self::$reasons);
		}
		return $values;
	}

	// Every write, whoever makes it. Name, email, phone and subject may end
	// up in a mail header, so a newline in them is refused, not cleaned.
	static function check($type, $after, $before) {
		if ($type !== 'contactmessage' || $after === null) return array();
		$problems = array();
		// a field an update leaves as it was keeps it: a message stored before
		// contact_max_length was lowered can still be marked done
		if (self::changed('name', $after, $before) && self::unsafe($after['name'], 200)) $problems[] = 'name_invalid';
		$email = (string)$after['email'];
		if (self::changed('email', $after, $before) && ($email === '' || self::unsafe($email, 254) || !filter_var($email, FILTER_VALIDATE_EMAIL) || self::not_plain($email))) $problems[] = 'email_invalid';
		if (self::changed('phone', $after, $before) && self::unsafe($after['phone'], 200)) $problems[] = 'phone_invalid';
		if (self::changed('subject', $after, $before) && self::unsafe($after['subject'], 200)) $problems[] = 'subject_invalid';
		$message = (string)$after['message'];
		if (self::changed('message', $after, $before) && (trim($message) === '' || mb_strlen($message, 'UTF-8') > (int)config::get('contact_max_length', 5000))) $problems[] = 'message_invalid';
		// spam is marked not spam first, so a message is never done unread
		if ($before && $before['status'] === 'spam' && $after['status'] === 'done') $problems[] = 'not_done_spam';
		return $problems;
	}

	// a new message's field, or one the write changes
	protected static function changed($field, $after, $before) {
		return !$before || (string)$after[$field] !== (string)$before[$field];
	}

	// a control character (CR, LF, NUL, …), or longer than $max characters
	protected static function unsafe($value, $max) {
		$value = (string)$value;
		return preg_match('/[\x00-\x1f\x7f]/', $value) || mb_strlen($value, 'UTF-8') > $max;
	}

	// An address the filter takes that is more than a plain one: a quoted
	// local part ("x>,evil@evil.test,<y"@x.com) or a domain in brackets
	// can carry address syntax into Reply-To, where reply_to() only cleans
	// the name
	protected static function not_plain($email) {
		return preg_match('/[\s"(),:;<>\[\]\\\\]/', $email) || substr_count($email, '@') !== 1;
	}

	// ##Actions
	// Each reads the message again inside the transaction with its write.

	static function done($message, $input) {
		return self::set_status($message['id'], 'done');
	}

	static function spam($message, $input) {
		return self::set_status($message['id'], 'spam');
	}

	// back to new, its reasons cleared; contact.received (who: staff) tells
	// a listener it is a real message, and staff, who are looking at it, get
	// no email
	static function not_spam($message, $input) {
		return self::set_status($message['id'], 'new');
	}

	protected static function set_status($id, $status) {
		return cms_records::transaction(function () use ($id, $status) {
			$message = cms_records::get('contactmessage', $id);
			if (!$message) throw new InvalidArgumentException("No contactmessage $id");
			if ($status === 'new' && $message['status'] !== 'spam') cms_records::refuse('not_spam_invalid');
			if ($status === $message['status']) return $message;
			$values = array('status' => $status);
			if ($status === 'spam') $values['spam'] = 'staff';
			if ($status === 'new') $values['spam'] = '';
			$saved = cms_records::update('contactmessage', $id, $values);
			if ($status === 'new') cms_records::dispatch('contact.received', self::payload($saved, 'staff'));
			return $saved;
		});
	}

	// ##Emails and events

	// what an event or the email gets of a message: every field but the
	// title, and who: visitor, or staff when staff marked it not spam
	static function payload($message, $who) {
		$payload = cms_records::shown(cms_records::info('contactmessage'), $message);
		unset($payload['title']);
		return $payload + array('who' => $who === 'staff' ? 'staff' : 'visitor');
	}

	// The visitor's name and address for Reply-To; the name without the
	// characters that mean something in an address header (quotes, brackets,
	// a comma, a group's : and ;, @, a backslash), so it can only ever be a
	// name. The address is never a recipient.
	static function reply_to($name, $email) {
		$name = trim(str_replace(array('"', '<', '>', ',', '(', ')', '[', ']', ':', ';', '@', '\\'), '', (string)$name));
		return $name === '' ? (string)$email : '"'.$name.'" <'.$email.'>';
	}

	// contact.received: staff's email (system/config/events.php). A site
	// that sends its own unbinds it in config/the_events.php:
	//   event::unbind('contact.received')->from('contact', 'staff_mail');
	function staff_mail($message) {
		if (!is_array($message) || !isset($message['who'], $message['email']) || $message['who'] === 'staff' || !self::ready()) return null;
		$to = trim((string)config::get('contact_staff_email', ''));
		if ($to === '') $to = trim((string)(getenv('RASTER_MAIL_FROM') ?: config::get('mail_from', '')));
		if ($to === '') {
			log::warning('contact: no address for staff emails: set contact_staff_email or mail_from');
			return null;
		}
		$vars = $message;
		$vars['page_url'] = util::url(isset($message['page']) ? $message['page'] : '');
		$vars['staff_url'] = util::url(config::get('contact_staff_page', 'staff'));
		$vars['site_url'] = util::url('');
		$headers = array('Reply-To' => self::reply_to(isset($message['name']) ? $message['name'] : '', $message['email']));
		// the message stays stored when its email can't go; the failure is
		// logged without the address
		$mail = controller::model_class('mail');
		try {
			if (!$mail::send_view('_email/contact_received', $to, $vars, $headers)) log::error('contact: _email/contact_received was not sent');
		} catch (Exception $e) {
			log::error('contact: _email/contact_received: '.$e->getMessage());
		}
		return null;
	}

	// ##The setup

	// the bundled type is declared: a site whose own model or type is called
	// contact keeps the name (cms_records::types), and then every region
	// here gives nothing
	static function ready() {
		$info = cms_records::info('contactmessage');
		return $info !== null && $info['model'] === 'contact';
	}

	// what a site taking messages needs: the staff email's view, the
	// settings; render.contact.messages lists the type, as
	// render.cms.contactmessage does, for lint's staff page check
	static function requires() {
		return array(
			'emails' => array('render.contact.form' => array('_email/contact_received')),
			'settings' => array('contact_staff_email', 'contact_staff_page', 'contact_max_length', 'contact_spam_days'),
			'lists' => array('contactmessage' => array('render.contact.messages')),
		);
	}

	// ##Regions

	// The contact form: the message stored, its event, and ?done=sent, also
	// for a message kept as spam and for a second click on Send.
	function form() {
		if (!self::ready()) return false;
		$v = validation::get();
		if (!$v->submitted()) return false;
		// a post the HTML rules refuse is neither stored nor counted
		if (!$v->valid()) return template::instance()->form_state();
		if (self::twin()) {
			util::done('sent');
			return false;
		}
		// fields named, so the text checks run, and the email's domain
		// against the blocklist with them
		$reasons = $v->suspect(array('name', 'subject', 'message'));
		self::$reasons = $reasons;
		self::$writing = true;
		try {
			$result = cms_records::transaction(function () {
				$item = cms_records::submit('contactmessage', false);
				if (!is_array($item)) return $item;
				$message = cms_records::get('contactmessage', $item['id']);
				// each name written out, so lint and the vocabulary know who sends it
				if ($message['status'] === 'spam') cms_records::dispatch('contact.flagged', self::payload($message, 'visitor'));
				else cms_records::dispatch('contact.received', self::payload($message, 'visitor'));
				return true;
			});
		} finally {
			// never left for a write from the editor or MCP
			self::$reasons = array();
			self::$writing = false;
		}
		if ($result !== true) return $result;
		self::expire();
		util::done('sent');
		return false;
	}

	// the same email and message stored in the last 10 minutes
	protected static function twin() {
		$email = trim((string)util::post('email'));
		$message = trim((string)util::post('message'));
		if ($email === '' || $message === '' || !cms_store::table_exists('contactmessagedata')) return false;
		return (bool)database::instance('contact')->recent($email, $message, date('Y-m-d H:i:s', time() - 600));
	}

	// spam older than contact_spam_days goes as a new message comes
	protected static function expire() {
		$days = config::get('contact_spam_days', 30);
		if ($days === false) return;
		database::instance('contact')->expire_spam(date('Y-m-d H:i:s', time() - (int)$days * 86400));
	}

	// ##For staff
	// Private wherever a page puts them: anyone but an editor gets nothing.

	function messages($options = 'status=new&order=-created_at') {
		raster_cache::skip();
		if (!self::ready() || !authentication::can('editor')) return array();
		return cms_records::listed('contactmessage', cms_records::find('contactmessage', (string)$options));
	}

	function count($options = 'status=new') {
		raster_cache::skip();
		if (!self::ready() || !authentication::can('editor')) return '';
		return (string)count(cms_records::find('contactmessage', (string)$options));
	}
}
