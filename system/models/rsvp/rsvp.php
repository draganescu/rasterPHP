<?php
/**
* RSVP
*
* Replies to the site's events: yes (with a party size), no or maybe. The
* events stay content, the items of a markup collection (rsvp_collection,
* events) that editors write in the page. The model reads four fields of
* an item, which the site writes in its item view like any other field:
*
*   date        required: an item without one takes no replies
*   starts      (or time) when it starts; without one, 00:00 of its date
*   places      the most people with a yes; empty or 0 is no limit
*   rsvp_until  when replies close; empty is rsvp_closes minutes before
*               the start
*
* Replies are the rsvp type: name, email, phone, event (the item's id),
* answer, people, notes, and what the model works out (status: going,
* waiting, maybe or no; waiting_since, their place in the queue;
* changed_at; event_at, the start the guest was told). A yes is going when
* the party fits the places left, else waiting. When places free up (a no,
* a smaller party, a reply deleted or moved, places raised) the model moves
* in, oldest first, every waiting party that fits; nobody going is ever
* moved out. From the deadline a guest may only give places back, and
* nothing is moved in; from the start a guest changes nothing. Guests reply
* on the form; staff add and edit replies in the page
* (render.cms.rsvp('event=?event')).
*
* Regions:
*   <!-- render.rsvp.event -->  one row for the page's event (its item page,
*     /events/events_item/<slug>, else ?event=<id>): event, places and left
*     ('' for no limit), going (people), waiting (replies), state (open,
*     full, closed, over) and deadline. Never page-cached.
*   <!-- render.rsvp.form -->  the reply form for the page's event, found
*     the same way (never one the post names): name, email, phone, answer,
*     people, notes. Redirects with ?done=going, waiting or noted. Shown
*     first it says not_open or closed. An address that replied gets
*     already_replied and its link again (rsvp_link). The spam checks of
*     the booking form: try_again, slow_down.
*   <!-- render.rsvp.manage -->  the guest's page, by the link in their
*     emails (?token=) or, logged in, ?reply=<id>: the reply, with
*     event_title, event_date, event_starts, event_url, deadline and
*     position, and a form posting do=change with answer and people. A GET
*     changes nothing. The link works until the event starts, after a no
*     too, and dies when the event is hidden or deleted.
*
* For staff (editors; anyone else gets nothing, and none is page-cached):
*   <!-- render.rsvp.events('from=today') -->  every event from that day,
*     drafts too, with event_title, event_date, event_starts, places, going,
*     left, waiting, maybe, no and its replies, editable in place, each with
*     attention and position.
*   <!-- render.rsvp.attention -->  the active replies an edit of their
*     event left out of place: event_gone, event_hidden, over_places, moved.
*   <!-- print.rsvp.count('status=waiting') -->  how many replies match, or
*     count('attention').
*   One event's replies, with the card for a phone reply that starts with
*   the event: render.cms.rsvp('event=?event&order=status,waiting_since').
*
* The action: promote (editors), a waiting reply in now, out of turn, if
* its party fits, also after the deadline.
*
* Alerts: not_open, name_invalid, email_invalid, answer_invalid,
* people_invalid, too_many, closed, full, already_replied, full_for_more,
* not_enough_places; for the action not_waiting. On the form: try_again
* and slow_down, and going, waiting or noted after a reply. On the manage
* page: link_invalid, and changed after the change.
*
* Emails, views the site writes: _email/rsvp_going (the reply became
* going, or its party changed), rsvp_waiting (with position), rsvp_promoted
* and rsvp_noted (no or maybe) to the guest, each with print.self.manage_url,
* their new link; rsvp_link, the link again to an address that replied
* before, at most once every five minutes; rsvp_new to rsvp_staff_email
* when a guest replied or changed their reply, only when that is set.
*
* Events, after the commit: rsvp.replied, rsvp.changed (with was and
* was_people, the status and party before), rsvp.promoted. Each has the
* reply's fields, who (guest, staff or waitlist), event_title, event_date
* and event_starts.
*
* Settings:
*   config::set('rsvp_collection')->to('events');  the collection whose items take replies
*   config::set('rsvp_max_people')->to(6);         people in a guest's reply
*   config::set('rsvp_closes')->to(0);             minutes before the start replies close, without rsvp_until
*   config::set('rsvp_waitlist')->to(true);        false: a yes that doesn't fit is refused (full)
*   config::set('rsvp_manage_page')->to('rsvp');   the guest's page their links open
*   config::set('rsvp_staff_email')->to('staff@example.com');  staff emails; empty sends none
*/
class rsvp
{
	// whom the model's own writes are for: staff, a guest on the form or a
	// link, whose writes get the guest rules (is_staff), or the waitlist
	static $acting = 'staff';
	// while the model makes its own writes: saved() leaves those alone
	static $writing = false;
	// the event a visitor's reply is for: the page's, never the post's
	static $posting = null;
	// events where a write freed places, event => true: their waitlist moves
	// before the model's own write commits, or after staff's (saved())
	static $freed = array();
	// what staff's writes changed, id => was, was_people and whether the
	// answer, people or event did, for saved()
	static $changed = array();
	// the events read in this request, id => event (item())
	static $events = array();
	// the guest's new links, id => raw token: this request only, for the
	// model's own email listener, never stored, logged or sent with an event
	static $links = array();
	// the id of the page's event (page_event()), false until it is read
	static $page = false;
	// the reply the manage page is about, by its ?token= or ?reply=
	static $managed = array();
	// the replies the model made itself, id => true, whose events it sends:
	// inside a site's own transaction their cms.item_saved comes after the
	// commit, when the model's write is over, and saved() must leave it
	static $made = array();

	static function types() {
		return array(
			'rsvp' => array(
				// name first: the slug is made from it, never from the email
				'fields' => array(
					'name' => '', 'email' => '', 'phone' => '', 'event' => 0, 'answer' => 'yes', 'people' => 1, 'notes' => '',
					'status' => '', 'waiting_since' => '', 'changed_at' => '', 'event_at' => '', 'token_hashes' => array(), 'link_sent_at' => '',
				),
				'types' => array('waiting_since' => 'datetime', 'changed_at' => 'datetime', 'event_at' => 'datetime', 'link_sent_at' => 'datetime'),
				// guests reply on the form, staff add phone replies in the
				// page, and a guest who was logged in sees their own
				'create' => 'visitor',
				'staff_add' => true,
				'owner' => true,
				'public' => false,
				// event stays writable, so a new reply in the page starts
				// with the event its list filters by
				'readonly' => array('status', 'waiting_since', 'changed_at', 'event_at'),
				'hidden' => array('token_hashes', 'link_sent_at'),
				'actions' => array('promote' => 'editor'),
			),
		);
	}

	// ##Replies

	// The status a reply's answer and the places give it, on every write:
	// no and maybe take no places; a yes is going when it fits, else
	// waiting. A going or waiting yes keeps its status and its place in the
	// queue; a write of the model's own that names a status (a promotion)
	// keeps it. Places freed go to the waitlist (self::$freed).
	static function fill($type, $after, $before) {
		if ($type !== 'rsvp') return array();
		// first in the write's lock: the event and the places are read
		// there, as they are now, for fill() and check()
		self::forget();
		$values = array();
		$writer = cms_records::writer();
		// a visitor replies to the page's event, whatever the post names
		if ($writer === 'visitor') $values['event'] = $after['event'] = self::$posting === null ? 0 : (int)self::$posting;
		$id = (int)$after['id'];
		$event = self::item($after['event']);
		$moved = $before && (int)$after['event'] !== (int)$before['event'];
		$answered = !$before || self::changed($after, $before, array('answer', 'people', 'event'));
		$was = $before ? (string)$before['status'] : '';
		$status = (string)$after['status'];
		$people = (int)$after['people'];
		// the model's own write of another status, with the same answer,
		// people and event, is a promotion: that status stays
		$named = $before && !$answered && $writer === 'model' && $status !== $was;
		if (!$named) {
			$answer = (string)$after['answer'];
			$since = (string)$after['waiting_since'];
			if ($answer === 'no' || $answer === 'maybe') {
				$status = $answer;
				$since = '';
			} elseif ($answer === 'yes' && !($before && !$moved && in_array($was, array('going', 'waiting'), true))) {
				// a new yes, one from no or maybe, or one moved to another event
				$fits = !$event || $event['places'] < 1 || self::going($event['id'], $id) + $people <= $event['places'];
				$status = $fits ? 'going' : 'waiting';
				$since = $fits ? '' : date('Y-m-d H:i:s');
			}
			$values['status'] = $status;
			$values['waiting_since'] = $since;
		}
		if (!$before || $status !== $was || $people !== (int)$before['people']) $values['changed_at'] = date('Y-m-d H:i:s');
		// the start the guest was told, so a moved event shows; an editor's
		// save (a note: phoned them) says they know the new one
		if ($event && (!$before || $answered || $status !== $was || $writer === 'editor')) $values['event_at'] = date('Y-m-d H:i:s', $event['starts_at']);
		if ($before) {
			$smaller = !$moved && $people < (int)$before['people'];
			// a smaller waiting party may fit now
			if (($was === 'going' && ($status !== 'going' || $moved || $smaller)) || ($was === 'waiting' && $status === 'waiting' && $smaller)) self::$freed[(int)$before['event']] = true;
			unset(self::$changed[$id]);
			if (self::is_staff() && !self::$writing) self::$changed[$id] = array('was' => $was, 'was_people' => (int)$before['people'], 'answered' => $answered);
		}
		return $values;
	}

	// The rules run when a reply is made, or its answer, people, event or
	// email change: staff edit a phone number or a note of a reply whose
	// event no longer fits it, and it goes through. Whatever changed, a
	// reply made going, or a going party made bigger, must fit.
	static function check($type, $after, $before) {
		if ($type !== 'rsvp' || $after === null) return array();
		$problems = self::problems($after, $before);
		// a refused write frees nothing: fill() noted it before the rules ran
		if ($problems && $before) unset(self::$freed[(int)$before['event']]);
		return $problems;
	}

	protected static function problems($after, $before) {
		if (!$before || self::changed($after, $before, array('answer', 'people', 'event', 'email'))) {
			$problems = self::rules($after, $before);
			if ($problems) return $problems;
		}
		$problems = array();
		$was_going = $before && $before['status'] === 'going' && (int)$after['event'] === (int)$before['event'];
		if ($after['status'] !== 'going' || ($was_going && $after['people'] <= $before['people'])) return $problems;
		// the other replies going, from the database, inside the write's lock
		$event = self::item($after['event']);
		if (!$event || $event['places'] < 1 || self::going($event['id'], (int)$after['id']) + (int)$after['people'] <= $event['places']) return $problems;
		// a going guest keeps the places they have; a promotion that lost
		// a race moves nobody
		if ($was_going) $problems[] = 'full_for_more';
		else $problems[] = 'not_enough_places';
		return $problems;
	}

	protected static function rules($after, $before) {
		$problems = array();
		$staff = self::is_staff();
		$event = self::item($after['event']);
		// staff may reply for a draft (they see it); nobody for an item
		// with no date or none at all
		if (!$event || (!$event['visible'] && !$staff)) {
			$problems[] = 'not_open';
			return $problems;
		}
		$name = (string)$after['name'];
		if (trim($name) === '' || self::unsafe($name, 200)) $problems[] = 'name_invalid';
		$email = trim((string)$after['email']);
		if (($email === '' && !$staff) || ($email !== '' && (self::unsafe($after['email'], 254) || !filter_var($email, FILTER_VALIDATE_EMAIL)))) $problems[] = 'email_invalid';
		$answer = (string)$after['answer'];
		if (!in_array($answer, array('yes', 'no', 'maybe'), true)) $problems[] = 'answer_invalid';
		if ($problems) return $problems;
		$yes = $answer === 'yes';
		$people = (int)$after['people'];
		if ($yes && $people < 1) $problems[] = 'people_invalid';
		if ($problems) return $problems;
		$moved = $before && (int)$after['event'] !== (int)$before['event'];
		// a yes that takes places: new, moved, from no or maybe, or more people
		$takes = $yes && (!$before || $moved || $before['answer'] !== 'yes' || $people > (int)$before['people']);
		// a party bigger than the event could never fit; one already in
		// (places lowered under it) may still shrink or change its email
		if ($takes && (($event['places'] > 0 && $people > $event['places']) || (!$staff && $people > (int)config::get('rsvp_max_people', 6)))) $problems[] = 'too_many';
		if ($problems) return $problems;
		if (!$staff) {
			// from the deadline a guest only gives places back; from the start
			// a guest changes nothing. Real seconds, on the site's clock.
			if (time() >= $event['starts_at'] || ($takes && time() >= $event['deadline'])) $problems[] = 'closed';
			if ($problems) return $problems;
		}
		if ($after['status'] === 'waiting' && (!$before || $before['status'] !== 'waiting' || $moved) && !config::get('rsvp_waitlist', true)) $problems[] = 'full';
		if ($problems) return $problems;
		// one reply per address and event; two posts at once, or staff adding
		// a second one
		if ($email !== '' && self::replied($after['event'], $email, $after['id'])) $problems[] = 'already_replied';
		return $problems;
	}

	// whether the write is staff's: the in-page editor or MCP, or the
	// model's own code unless it acts for a guest. Model code that writes
	// replies itself is trusted like staff.
	static function is_staff() {
		$writer = cms_records::writer();
		return $writer === 'editor' || ($writer === 'model' && self::$acting !== 'guest');
	}

	protected static function changed($after, $before, $fields) {
		foreach ($fields as $field) {
			if ((string)$after[$field] !== (string)$before[$field]) return true;
		}
		return false;
	}

	// a control character (CR, LF, NUL, …), or longer than $max characters
	protected static function unsafe($value, $max) {
		$value = (string)$value;
		return preg_match('/[\x00-\x1f\x7f]/', $value) || mb_strlen($value, 'UTF-8') > $max;
	}

	// people going to an event, the reply $id left out
	static function going($event, $id) {
		if (!cms_store::table_exists('rsvpdata')) return 0;
		$rows = database::instance('rsvp')->going((int)$event, (int)$id);
		return $rows ? (int)$rows[0]['going'] : 0;
	}

	// the waiting replies of an event, oldest first: id, people, waiting_since
	static function queue($event) {
		if (!cms_store::table_exists('rsvpdata')) return array();
		return database::instance('rsvp')->queue((int)$event);
	}

	// the id of another reply to this event with this address, or 0
	protected static function replied($event, $email, $id) {
		if (!cms_store::table_exists('rsvpdata')) return 0;
		$rows = database::instance('rsvp')->replied((int)$event, strtolower(trim((string)$email)), (int)$id);
		return $rows ? (int)$rows[0]['id'] : 0;
	}

	// ##The waitlist

	// Moves in, oldest first, every waiting reply whose party fits: a party
	// of 4 that doesn't fit doesn't keep two places from a couple behind it.
	// Inside the write's lock and reading everything again, so a second run
	// moves nobody. Nothing from the deadline, or for an event visitors
	// can't see: freed places are staff's to give then (the promote action).
	static function promote_waiting($id) {
		return self::writing('waitlist', 0, function () use ($id) {
			self::forget();
			$event = self::item($id);
			if (!$event || !$event['visible'] || time() >= $event['deadline'] || !cms_store::table_exists('rsvpdata')) return array();
			$left = $event['places'] > 0 ? $event['places'] - self::going($id, 0) : null;
			$moved = array();
			foreach (self::queue($id) as $row) {
				if ($left !== null && $left < 1) break;
				if ($left !== null && (int)$row['people'] > $left) continue;
				// the promoted guest's email carries a new link
				$reply = cms_records::get('rsvp', $row['id']);
				$saved = cms_records::update('rsvp', $reply['id'], array('status' => 'going', 'waiting_since' => '') + self::issue($reply));
				cms_records::dispatch('rsvp.promoted', self::payload($saved, 'waitlist'));
				if ($left !== null) $left -= (int)$saved['people'];
				$moved[] = $saved;
			}
			return $moved;
		});
	}

	// The action: this waiting reply goes in now, out of turn, when its
	// party fits (not_enough_places otherwise: staff raise places first,
	// which moves the queue in order). Read again inside the transaction.
	static function promote($reply, $input) {
		return self::writing('staff', $reply['id'], function () use ($reply) {
			$reply = cms_records::get('rsvp', $reply['id']);
			if (!$reply) throw new InvalidArgumentException('No rsvp');
			if ($reply['status'] !== 'waiting') cms_records::refuse('not_waiting');
			$saved = cms_records::update('rsvp', $reply['id'], array('status' => 'going', 'waiting_since' => '') + self::issue($reply));
			cms_records::dispatch('rsvp.promoted', self::payload($saved, 'staff'));
			return $saved;
		});
	}

	// Runs the model's own write $work for $who ('guest', 'staff' or
	// 'waitlist') in one transaction, so its events wait for the commit;
	// places it freed go to the waitlist before then. The links stay as
	// they were when the write doesn't happen, so none for it is mailed.
	protected static function writing($who, $id, $work) {
		$acting = self::$acting;
		$writing = self::$writing;
		$links = self::$links;
		$made = self::$made;
		self::$acting = in_array($who, array('guest', 'waitlist'), true) ? $who : 'staff';
		self::$writing = true;
		try {
			return cms_records::transaction(function () use ($work) {
				$result = $work();
				while (self::$freed) {
					$event = key(self::$freed);
					unset(self::$freed[$event]);
					self::promote_waiting($event);
				}
				return $result;
			});
		} catch (Throwable $e) {
			self::$links = $links;
			self::$made = $made;
			self::$freed = array();
			throw $e;
		} finally {
			self::$acting = $acting;
			self::$writing = $writing;
		}
	}

	// ##The guest's link
	// Each email to the guest carries a new link to their reply: 32 random
	// bytes, sent only in that email. The reply keeps the sha256 of the
	// newest 20 (token_hashes), so the first email's link still works after
	// a promotion. A link works while the event is there, visitors see it
	// and it hasn't started, read from the event each time, so it follows a
	// moved event; a no reply's link too, so a guest can come after all.

	// The values to write with a change so the guest gets a new link
	// (token_hashes), or array() when the reply has no email. The raw token
	// waits in self::$links for the email listener.
	static function issue($reply) {
		if (trim((string)$reply['email']) === '') return array();
		$raw = bin2hex(random_bytes(32));
		$hashes = isset($reply['token_hashes']) ? array_values((array)$reply['token_hashes']) : array();
		$hashes[] = hash('sha256', $raw);
		self::$links[(int)$reply['id']] = $raw;
		return array('token_hashes' => array_slice($hashes, -20));
	}

	// the reply a link is for, or null: a malformed or unknown token, or a
	// reply whose event is gone, hidden or started
	static function by_token($raw) {
		if (!is_string($raw) || !preg_match('/^[a-f0-9]{64}$/', $raw) || !self::ready() || !cms_store::table_exists('rsvpdata')) return null;
		$hash = hash('sha256', $raw);
		foreach (database::instance('rsvp')->by_token('%'.$hash.'%') as $row) {
			$hashes = json_decode((string)$row['token_hashes'], true);
			foreach (is_array($hashes) ? $hashes : array() as $kept) {
				if (!is_string($kept) || !hash_equals($kept, $hash)) continue;
				$reply = cms_records::get('rsvp', $row['id']);
				return $reply && self::is_live($reply) ? $reply : null;
			}
		}
		return null;
	}

	// a reply its guest can still reach, whatever its answer: its event is
	// there, visitors see it, and it hasn't started
	protected static function is_live($reply) {
		$event = self::item($reply['event']);
		return $event && $event['visible'] && time() < $event['starts_at'];
	}

	// The reply the manage page shows: the one ?token= is for, or the
	// logged in guest's own by ?reply=<id>. Null when neither; read once
	// per request (forget() reads it again).
	static function managed() {
		$token = util::get('token');
		$id = util::get('reply');
		$key = is_string($token) && $token !== '' ? "token:$token" : (is_string($id) && $id !== '' ? "reply:$id" : '');
		if ($key === '' || !self::ready()) return null;
		if (array_key_exists($key, self::$managed)) return self::$managed[$key];
		$reply = null;
		if (strpos($key, 'token:') === 0) {
			$reply = self::by_token($token);
		} elseif (ctype_digit($id) && cms_store::table_exists('rsvpdata')) {
			$me = cms_records::owner_me(cms_records::info('rsvp'));
			$found = $me === null ? null : cms_records::get('rsvp', (int)$id);
			if ($found && (string)$found['owner'] === $me && self::is_live($found)) $reply = $found;
		}
		return self::$managed[$key] = $reply;
	}

	// A reply's answer and people from $values, in one write, with
	// rsvp.changed; places it frees go to the waitlist in the same
	// transaction. A guest's ($who 'guest', from the manage page) needs a
	// live link (link_invalid) and gets the guest rules. The same answer
	// and people again write and send nothing.
	static function change($id, $values, $who) {
		$changes = array_intersect_key((array)$values, array_flip(array('answer', 'people')));
		return self::writing($who, $id, function () use ($id, $changes, $who) {
			$reply = cms_records::get('rsvp', $id);
			if (!$reply) throw new InvalidArgumentException("No rsvp $id");
			if ($who === 'guest' && !self::is_live($reply)) cms_records::refuse('link_invalid');
			if (!self::changed($changes, array_intersect_key($reply, $changes), array_keys($changes))) return $reply;
			$saved = cms_records::update('rsvp', $id, $changes);
			$saved = self::with_link($saved, $reply['status'], $reply['people']);
			cms_records::dispatch('rsvp.changed', self::payload($saved, $who) + array('was' => $reply['status'], 'was_people' => (int)$reply['people']));
			return $saved;
		});
	}

	// the reply with a new link written, when the change emails its guest
	protected static function with_link($reply, $was, $was_people) {
		if (self::guest_view($reply, $was, $was_people) === null) return $reply;
		$values = self::issue($reply);
		return $values ? cms_records::update('rsvp', $reply['id'], $values) : $reply;
	}

	// ##The event

	// The events collection's item $id as the model reads it, or null when
	// there is none or it has no date: id, title, slug, date, starts (H:i),
	// places (0 for no limit), rsvp_until, visible (as visitors see it: not
	// a draft, not scheduled), starts_at and deadline (Unix times), url.
	// Read once per request (forget() reads it again).
	static function item($id) {
		$id = (int)$id;
		if ($id < 1) return null;
		if (array_key_exists($id, self::$events)) return self::$events[$id];
		$table = self::table();
		return self::$events[$id] = self::from_item($table === null ? null : cms_store::get_item($table, $id));
	}

	// an item of the events collection as the model reads it (item()), or
	// null
	protected static function from_item($item) {
		$event = null;
		if ($item && isset($item['date']) && (string)$item['date'] !== '') {
			$starts = isset($item['starts']) && (string)$item['starts'] !== '' ? $item['starts'] : (isset($item['time']) && (string)$item['time'] !== '' ? $item['time'] : '00:00');
			$starts = substr((string)$starts, 0, 5);
			$published = isset($item['published_at']) ? (string)$item['published_at'] : '';
			$event = array(
				'id' => (int)$item['id'],
				'title' => (string)cms_store::slug_source($item),
				'slug' => (string)$item['slug'],
				'date' => (string)$item['date'],
				'starts' => $starts,
				'places' => isset($item['places']) ? max(0, (int)$item['places']) : 0,
				'rsvp_until' => isset($item['rsvp_until']) ? (string)$item['rsvp_until'] : '',
				'visible' => (!isset($item['enabled']) || ($item['enabled'] !== false && (string)$item['enabled'] !== '0')) && ($published === '' || $published <= date('Y-m-d H:i:s')),
				'starts_at' => strtotime($item['date'].' '.$starts),
				'url' => util::url(self::collection().'/'.self::collection().'_item/'.$item['slug']),
			);
			if ($event['starts_at'] === false) $event = null;
			else $event['deadline'] = self::deadline($event);
		}
		return $event;
	}

	// when replies close, as a Unix time: rsvp_until, else rsvp_closes
	// minutes before the start, and never after the start
	static function deadline($event) {
		$until = (string)$event['rsvp_until'] !== '' ? strtotime((string)$event['rsvp_until']) : false;
		return min($until !== false ? $until : $event['starts_at'] - (int)config::get('rsvp_closes', 0) * 60, $event['starts_at']);
	}

	// The event the page is about: on its item page the item as
	// render.cms finds it (a number is a slug first), else ?event=<id>.
	// Null when there is none, or a visitor can't see it; editors get a
	// draft, so they can check its page.
	static function page_event() {
		if (self::$page === false) {
			self::$page = null;
			$collection = self::collection();
			$table = self::table();
			if ($collection !== null && util::param($collection) === $collection.'_item') {
				$wanted = (string)util::param($collection.'_item');
				$bean = cms_store::table_exists($table) ? R::findOne($table, ' slug = ? ', array($wanted)) : null;
				if ($bean) self::$page = (int)$bean->id;
				elseif (ctype_digit($wanted)) self::$page = (int)$wanted;
			} elseif (is_string(util::get('event')) && ctype_digit(util::get('event'))) {
				self::$page = (int)util::get('event');
			}
		}
		$event = self::$page ? self::item(self::$page) : null;
		return $event && ($event['visible'] || authentication::can('editor')) ? $event : null;
	}

	// the collection whose items take replies, or null when its name can't
	// be a collection's
	protected static function collection() {
		$collection = (string)config::get('rsvp_collection', 'events');
		return preg_match('/^[a-z][a-z0-9_]*$/', $collection) ? $collection : null;
	}

	protected static function table() {
		$collection = self::collection();
		return $collection === null ? null : cms::collection_type($collection);
	}

	static function forget() {
		self::$events = array();
		self::$page = false;
		self::$managed = array();
	}

	// ##Events
	// After the commit. The model's own writes send theirs; saved() sends
	// them for staff's and model code's.

	// what an event gets of a reply: every field but the hidden ones, who
	// (guest, staff or waitlist) and the event's title, date and start
	static function payload($reply, $who) {
		$event = self::item($reply['event']);
		return cms_records::shown(cms_records::info('rsvp'), $reply) + array(
			'who' => in_array($who, array('guest', 'waitlist'), true) ? $who : 'staff',
			'event_title' => $event ? $event['title'] : '',
			'event_date' => $event ? $event['date'] : '',
			'event_starts' => $event ? $event['starts'] : '',
		);
	}

	// cms.item_saved: an event saved in the page (places raised or emptied,
	// published again) moves its waitlist in; a reply staff wrote in the
	// page, over MCP or in model code gets its event (who: staff) and, when
	// that emails the guest, their new link, and the places it freed go to
	// the waitlist. In a transaction of its own.
	function saved($saved) {
		if (!is_array($saved) || !isset($saved['collection'], $saved['item']['id']) || !self::ready()) return null;
		if ($saved['collection'].'data' === self::table()) {
			self::promote_waiting((int)$saved['item']['id']);
			return null;
		}
		if ($saved['collection'] !== 'rsvp') return null;
		$id = (int)$saved['item']['id'];
		// a reply the form made sent rsvp.replied itself, also when a site's
		// transaction around it held this back until its own commit
		if ($saved['created'] && isset(self::$made[$id])) {
			unset(self::$made[$id]);
			return null;
		}
		if (self::$writing) return null;
		$changed = isset(self::$changed[$id]) && self::$changed[$id]['answered'] ? self::$changed[$id] : null;
		unset(self::$changed[$id]);
		// a note, or the model's own write after its commit: nothing to do
		if (!$saved['created'] && !$changed && !self::$freed) return null;
		self::writing('staff', $id, function () use ($id, $saved, $changed) {
			$reply = cms_records::get('rsvp', $id);
			if (!$reply) return;
			if ($saved['created']) {
				$reply = self::with_link($reply, '', $reply['people']);
				cms_records::dispatch('rsvp.replied', self::payload($reply, 'staff'));
			} elseif ($changed) {
				$reply = self::with_link($reply, $changed['was'], $changed['was_people']);
				cms_records::dispatch('rsvp.changed', self::payload($reply, 'staff') + array('was' => $changed['was'], 'was_people' => $changed['was_people']));
			}
		});
		return null;
	}

	// cms.item_deleted: a going reply deleted frees its places. Deleting
	// an event moves nobody: its replies need attention.
	function deleted($deleted) {
		if (self::$writing || !is_array($deleted) || !isset($deleted['collection'], $deleted['item']['status']) || $deleted['collection'] !== 'rsvp' || !self::ready()) return null;
		if ($deleted['item']['status'] === 'going') self::promote_waiting((int)$deleted['item']['event']);
		return null;
	}

	// ##Emails
	// The model's listeners (system/config/events.php) send them; a site
	// unbinds one in config/the_events.php to send its own:
	//   event::unbind('rsvp.promoted')->from('rsvp', 'promoted_mail');

	function replied_mail($reply) {
		if (!is_array($reply) || !isset($reply['status'], $reply['people'])) return null;
		return self::mail($reply, self::guest_view($reply, '', $reply['people']), true);
	}

	function changed_mail($reply) {
		if (!is_array($reply) || !isset($reply['status'], $reply['people'], $reply['was'], $reply['was_people'])) return null;
		return self::mail($reply, self::guest_view($reply, $reply['was'], $reply['was_people']), true);
	}

	function promoted_mail($reply) {
		return self::mail($reply, 'rsvp_promoted', false);
	}

	// The guest's email for a reply made or changed, or null when nothing
	// changed for them: going when it became going or its party changed,
	// waiting the same (with its place in the queue), noted when it became
	// no or maybe. Promotions have their own (promoted_mail).
	protected static function guest_view($reply, $was, $was_people) {
		$status = (string)$reply['status'];
		$resized = (int)$reply['people'] !== (int)$was_people;
		if ($status === 'going' && ($was !== 'going' || $resized)) return 'rsvp_going';
		if ($status === 'waiting' && ($was !== 'waiting' || $resized)) return 'rsvp_waiting';
		if (($status === 'no' || $status === 'maybe') && $was !== $status) return 'rsvp_noted';
		return null;
	}

	// The guest's email $guest (null: none), with their new link when the
	// change made one, and with $staff rsvp_new to rsvp_staff_email when
	// the guest made the change and the site set it. The views get the
	// reply's fields, who, manage_url, position, event_title, event_date,
	// event_starts, event_url, deadline and site_url.
	protected static function mail($reply, $guest, $staff) {
		if (!is_array($reply) || !isset($reply['id'], $reply['event'], $reply['email']) || !self::ready()) return null;
		$id = (int)$reply['id'];
		$event = self::item($reply['event']);
		$vars = $reply;
		$vars['manage_url'] = isset(self::$links[$id]) ? util::url(config::get('rsvp_manage_page', 'rsvp'), array('token' => self::$links[$id])) : '';
		unset(self::$links[$id]);
		$vars['position'] = (string)self::position($reply);
		$vars['event_url'] = $event ? $event['url'] : '';
		$vars['deadline'] = $event ? date('Y-m-d H:i', $event['deadline']) : '';
		$vars['site_url'] = util::url('');
		if ($guest !== null && trim((string)$reply['email']) !== '') self::send('_email/'.$guest, $reply['email'], $vars);
		// staff asked for these, or a jazz night would send forty
		$to = trim((string)config::get('rsvp_staff_email', ''));
		if (!$staff || $to === '' || !isset($reply['who']) || $reply['who'] !== 'guest') return null;
		$vars['manage_url'] = '';
		self::send('_email/rsvp_new', $to, $vars);
		return null;
	}

	// a waiting reply's place in its event's queue, from 1; '' for the rest
	static function position($reply) {
		if ((string)$reply['status'] !== 'waiting') return '';
		$place = 1;
		foreach (self::queue($reply['event']) as $row) {
			if ((int)$row['id'] === (int)$reply['id']) return $place;
			$place++;
		}
		return '';
	}

	// a reply stays made when its email can't go: the failure is logged,
	// without the address or the link, so not the error's text either (an
	// SMTP server's reply can name the recipient)
	protected static function send($view, $to, $vars) {
		$mail = controller::model_class('mail');
		try {
			if ($mail::send_view($view, $to, $vars)) return true;
		} catch (Exception $e) {
			log::error("rsvp: $view: not sent (".get_class($e).")");
			return false;
		}
		log::error("rsvp: $view was not sent");
		return false;
	}

	// ##The setup

	// the bundled type is declared: a site whose own model or type is called
	// rsvp keeps the name (cms_records::types), and then every region here
	// gives nothing
	static function ready() {
		$info = cms_records::info('rsvp');
		return $info !== null && $info['model'] === 'rsvp';
	}

	// what a site taking replies needs: the emails' views (rsvp_new only
	// when staff asked for it), the manage page (left out of the sitemap
	// and an export) and the settings
	static function requires() {
		$emails = array('_email/rsvp_going', '_email/rsvp_waiting', '_email/rsvp_promoted', '_email/rsvp_noted');
		if (trim((string)config::get('rsvp_staff_email', '')) !== '') $emails[] = '_email/rsvp_new';
		return array(
			// the form also mails a guest who replied before their link again
			'emails' => array('render.rsvp.form' => array_merge($emails, array('_email/rsvp_link')), 'render.rsvp.manage' => $emails),
			'pages' => array(config::get('rsvp_manage_page', 'rsvp')),
			'settings' => array('rsvp_collection', 'rsvp_manage_page', 'rsvp_staff_email', 'rsvp_max_people', 'rsvp_closes', 'rsvp_waitlist'),
			// a view with the staff list is the replies' staff page
			'lists' => array('rsvp' => array('render.rsvp.events')),
		);
	}

	// a region's options, 'event=?event&from=today': a ?x is the URL's x,
	// today is today's date, and empty values are left out
	static function options($argument) {
		$options = array();
		foreach (explode('&', (string)$argument) as $chunk) {
			if (strpos($chunk, '=') === false) continue;
			list($key, $value) = explode('=', $chunk, 2);
			$key = trim($key);
			$value = cms_store::list_value(trim($value));
			if ($key === '' || $value === null || $value === '') continue;
			$options[$key] = $value;
		}
		return $options;
	}

	// ##Regions

	// the page's event: its places, the people going, the places left, the
	// replies waiting, and what a yes gets now (state)
	function event() {
		// the places left change with every reply
		raster_cache::skip();
		if (!self::ready()) return array();
		$event = self::page_event();
		if (!$event) return array();
		$going = self::going($event['id'], 0);
		$left = $event['places'] > 0 ? max(0, $event['places'] - $going) : '';
		if (time() >= $event['starts_at']) $state = 'over';
		elseif (time() >= $event['deadline']) $state = 'closed';
		elseif ($left !== '' && $left < 1) $state = 'full';
		else $state = 'open';
		return array(array(
			'event' => (string)$event['id'],
			'places' => $event['places'] > 0 ? (string)$event['places'] : '',
			'going' => (string)$going,
			'left' => (string)$left,
			'waiting' => (string)count(self::queue($event['id'])),
			'state' => $state,
			'deadline' => date('Y-m-d H:i', $event['deadline']),
		));
	}

	// The guest's page, by the link in their emails (?token=) or, logged in,
	// by ?reply=<id>: the reply without its hidden fields, with
	// event_title, event_date, event_starts, event_url, deadline and
	// position. A GET changes nothing; its form posts do=change with answer
	// and people. Alerts: link_invalid, and the rules' (closed,
	// full_for_more, …); changed after the change, on the page without the
	// token.
	function manage() {
		// the link is a key: no cache keeps it, and no Referer takes it along
		raster_cache::skip();
		if (PHP_SAPI !== 'cli' && !headers_sent()) header('Referrer-Policy: same-origin');
		if (!self::ready()) return array();
		$v = validation::get();
		$reply = self::managed();
		if (!$reply) {
			// after a change the page has ?done= and no link any more
			if (!is_string(util::get('done')) || util::get('done') === '') $v->raise('link_invalid');
			return array();
		}
		// back to this page without the token (the owner's keeps ?reply=)
		$back = config::get('link_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/');
		if (!is_string(util::get('token')) || util::get('token') === '') $back .= '?reply='.(int)$reply['id'];
		if ($v->submitted() && util::post('do') === 'change') {
			$values = array();
			foreach (array('answer', 'people') as $field) {
				$value = util::post($field);
				if (is_string($value) && trim($value) !== '') $values[$field] = trim($value);
			}
			try {
				$saved = self::change($reply['id'], $values, 'guest');
				if (self::changed($saved, $reply, array('answer', 'people'))) return self::manage_done('changed', $back);
			} catch (cms_refused $e) {
				foreach ($e->problems as $problem) $v->raise($problem);
			}
			$reply = cms_records::get('rsvp', $reply['id']);
		}
		$event = self::item($reply['event']);
		$row = cms_records::shown(cms_records::info('rsvp'), $reply) + array(
			'event_title' => $event ? $event['title'] : '',
			'event_date' => $event ? $event['date'] : '',
			'event_starts' => $event ? $event['starts'] : '',
			'event_url' => $event ? $event['url'] : '',
			'deadline' => $event ? date('Y-m-d H:i', $event['deadline']) : '',
			'position' => (string)self::position($reply),
		);
		return array(cms_records::for_template(cms_records::info('rsvp'), $row));
	}

	// The reply form, for the page's event (never one the post names):
	// name, email, phone, answer, people and notes. Shown first, it says
	// not_open when the page has no event visitors can see, and closed
	// from the deadline. A stored reply redirects with ?done=going, waiting
	// or noted, keeping ?event= when the event came from it. An address
	// that replied gets already_replied, and its link again by email.
	function form() {
		// the places left change with every reply
		raster_cache::skip();
		if (!self::ready()) return false;
		$v = validation::get();
		$event = self::page_event();
		if (!$v->submitted()) {
			if (!$event) $v->raise('not_open');
			elseif (time() >= $event['deadline']) $v->raise('closed');
			return false;
		}
		if (!$v->valid()) return template::instance()->form_state();
		// before the lookup below, so a script can't have links mailed
		// without a stamp, and the per-email limit stops a loop of them
		if ($reasons = validation_spam::holds($v->suspect(array()))) return $v->hold($reasons);
		$email = trim((string)util::post('email'));
		$replied = $event && $email !== '' ? self::replied($event['id'], $email, 0) : 0;
		if ($replied) {
			$v->raise('already_replied');
			self::link_again(cms_records::get('rsvp', $replied));
			return template::instance()->form_state();
		}
		$posting = self::$posting;
		self::$posting = $event ? $event['id'] : null;
		// inside a site's own transaction saved() runs after its commit, when
		// $writing no longer says the write was ours (alone, it does)
		$outer = cms_records::$depth > 0;
		try {
			// the reply, its link and rsvp.replied go together
			$reply = self::writing('guest', 0, function () use ($outer) {
				$item = cms_records::submit('rsvp', false);
				if (!is_array($item)) return $item;
				// left behind if the site's transaction rolls back: a later
				// reply that gets the same id in this request then sends nothing
				if ($outer) self::$made[(int)$item['id']] = true;
				$reply = self::with_link(cms_records::get('rsvp', $item['id']), '', $item['people']);
				cms_records::dispatch('rsvp.replied', self::payload($reply, 'guest'));
				return $reply;
			});
		} finally {
			self::$posting = $posting;
		}
		if (!is_array($reply)) return $reply;
		// back to the page it was sent from, with the event when the URL named it
		$back = config::get('link_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/');
		if ((string)util::get('event') === (string)$event['id']) $back .= '?event='.$event['id'];
		if ($reply['status'] === 'going') util::done('going', $back);
		elseif ($reply['status'] === 'waiting') util::done('waiting', $back);
		else util::done('noted', $back);
		return false;
	}

	// The reply's link again, by email to the reply's own address, so
	// nobody gets someone else's reply by typing their address; at most
	// once every mail::RESEND_AFTER, read inside the lock so two posts at
	// once send one. Nothing for a reply its guest can't reach any more.
	protected static function link_again($reply) {
		if (!$reply || !self::is_live($reply)) return;
		$mail = controller::model_class('mail');
		$saved = self::writing('guest', $reply['id'], function () use ($reply, $mail) {
			$reply = cms_records::get('rsvp', $reply['id']);
			if (!$reply || $mail::sent_lately($reply['link_sent_at'])) return null;
			$values = self::issue($reply);
			if (!$values) return null;
			return cms_records::update('rsvp', $reply['id'], $values + array('link_sent_at' => date('Y-m-d H:i:s')));
		});
		if ($saved) self::mail(self::payload($saved, 'guest'), 'rsvp_link', false);
	}

	// util::done exits in a request; on the command line the page shows
	// nothing more
	protected static function manage_done($done, $back) {
		self::forget();
		util::done($done, $back);
		return array();
	}

	// ##For staff
	// Private wherever a page puts them: anyone but an editor gets nothing.
	// The counts change with every reply, so none is page-cached.

	// The events from from= (today) on, by date and start, drafts and
	// scheduled ones too (staff see what they hid): event, event_title,
	// event_date, event_starts, places and left ('' for no limit), going
	// (people), waiting, maybe and no (replies), and replies: the event's
	// replies, editable in place, each with attention and position.
	function events($options = 'from=today') {
		raster_cache::skip();
		if (!self::ready() || !authentication::can('editor')) return array();
		$options = self::options($options);
		$from = isset($options['from']) && preg_match('/^\d{4}-\d\d-\d\d$/', $options['from']) ? $options['from'] : date('Y-m-d');
		$events = self::dated($from);
		$counts = self::counts(array_keys($events));
		$replies = self::replies_to(array_keys($events));
		$rows = array();
		foreach ($events as $id => $event) {
			$going = self::tally($counts, $id, 'going', 'people');
			$listed = isset($replies[$id]) ? $replies[$id] : array();
			$rows[] = array(
				'event' => (string)$id,
				'event_title' => $event['title'],
				'event_date' => $event['date'],
				'event_starts' => $event['starts'],
				'places' => $event['places'] > 0 ? (string)$event['places'] : '',
				'going' => (string)$going,
				'left' => $event['places'] > 0 ? (string)max(0, $event['places'] - $going) : '',
				'waiting' => (string)self::tally($counts, $id, 'waiting', 'replies'),
				'maybe' => (string)self::tally($counts, $id, 'maybe', 'replies'),
				'no' => (string)self::tally($counts, $id, 'no', 'replies'),
				'replies' => self::for_staff($listed, $counts, self::positions($listed)),
			);
		}
		return $rows;
	}

	// The active replies (going, waiting) that need attention, with the
	// reason (reason()), by their event's start: those to events to come,
	// and those whose start the guest was told is still to come, so a
	// deleted event's and one moved into the past are there too.
	function attention() {
		raster_cache::skip();
		if (!self::ready() || !authentication::can('editor')) return array();
		$today = date('Y-m-d');
		$replies = array();
		$positions = array();
		foreach (self::replies_to(array_keys(self::dated($today))) as $listed) {
			foreach ($listed as $reply) $replies[(int)$reply['id']] = $reply;
			$positions += self::positions($listed);
		}
		foreach (self::told($today) as $reply) {
			$id = (int)$reply['id'];
			if (isset($replies[$id])) continue;
			$replies[$id] = $reply;
			if ($reply['status'] === 'waiting') $positions[$id] = (string)self::position($reply);
		}
		$events = array();
		foreach ($replies as $reply) $events[] = (int)$reply['event'];
		// by the start: the event's now, or the one the guest was told
		$starts = function ($reply) {
			$event = self::item($reply['event']);
			return $event ? $event['starts_at'] : (int)strtotime((string)$reply['event_at']);
		};
		uasort($replies, function ($a, $b) use ($starts) { return ($starts($a) - $starts($b)) ?: ((int)$a['id'] - (int)$b['id']); });
		$rows = array();
		foreach (self::for_staff(array_values($replies), self::counts($events), $positions) as $row) {
			if ($row['attention'] !== '') $rows[] = $row;
		}
		return $rows;
	}

	// how many replies match the options ('status=waiting&event=7', values
	// as cms_records::find takes them), or 'attention' for how many need
	// it: for a staff page's heading ('' for anyone but an editor)
	function count($options = 'status=waiting') {
		raster_cache::skip();
		if (!self::ready() || !authentication::can('editor')) return '';
		if ($options === 'attention') return (string)count($this->attention());
		if (!cms_store::table_exists('rsvpdata')) return '0';
		return (string)count(cms_records::find('rsvp', (string)$options));
	}

	// Why an active reply (going or waiting) needs attention after staff
	// edited its event, or '': event_gone (deleted, or its date emptied),
	// event_hidden (a draft or scheduled again), over_places (places
	// lowered under the people going, $going: every going reply of it),
	// moved (its date or start isn't the one the guest was told, event_at).
	// Worked out as replies are listed, never stored: nobody is moved out
	// or emailed for it, and staff clear moved by saving the reply.
	static function reason($reply, $event, $going) {
		$status = (string)$reply['status'];
		if ($status !== 'going' && $status !== 'waiting') return '';
		if (!$event) return 'event_gone';
		if (!$event['visible']) return 'event_hidden';
		if ($status === 'going' && $event['places'] > 0 && (int)$going > $event['places']) return 'over_places';
		$told = (string)$reply['event_at'] !== '' ? strtotime((string)$reply['event_at']) : false;
		if ($told !== false && $told !== $event['starts_at']) return 'moved';
		return '';
	}

	// The events dated $from or later, id => event as item() reads it, by
	// start. One query on the collection's table: its name is the setting's,
	// checked (collection()), and the date is bound.
	protected static function dated($from) {
		$table = self::table();
		if ($table === null || !cms_store::table_exists($table) || !array_key_exists('date', cms_store::columns($table))) return array();
		$events = array();
		foreach (database::instance('rsvp')->query("SELECT * FROM `$table` WHERE `date` >= ? ORDER BY `date`, id", array($from)) as $row) {
			$row['id'] = (int)$row['id'];
			$event = self::from_item(cms_types::read_row($table, $row));
			if ($event) $events[$event['id']] = self::$events[$event['id']] = $event;
		}
		uasort($events, function ($a, $b) { return ($a['starts_at'] - $b['starts_at']) ?: ($a['id'] - $b['id']); });
		return $events;
	}

	// replies and people of each status, event => status => replies, people
	protected static function counts($events) {
		if (!$events || !cms_store::table_exists('rsvpdata')) return array();
		$counts = array();
		foreach (database::instance('rsvp')->counts(min($events), max($events)) as $row) {
			$counts[(int)$row['event']][(string)$row['status']] = array('replies' => (int)$row['replies'], 'people' => (int)$row['people']);
		}
		return $counts;
	}

	protected static function tally($counts, $event, $status, $what) {
		return isset($counts[$event][$status]) ? $counts[$event][$status][$what] : 0;
	}

	// the replies to these events, event => replies, in one read: the
	// queue first, oldest first, then the rest as they came
	protected static function replies_to($events) {
		if (!$events || !cms_store::table_exists('rsvpdata')) return array();
		$min = min($events);
		$max = max($events);
		$replies = array();
		foreach (cms_records::find('rsvp', "event>=$min&event<=$max&order=waiting_since,created_at") as $reply) $replies[(int)$reply['event']][] = $reply;
		return $replies;
	}

	// the active replies whose start, as the guest was told it, is $from or later
	protected static function told($from) {
		if (!cms_store::table_exists('rsvpdata')) return array();
		return cms_records::find('rsvp', "event_at>=$from&status!=no&status!=maybe");
	}

	// each waiting reply's place in its event's queue, id => '1', '2', …,
	// from all of the event's replies: oldest waiting_since first, as
	// sql/queue.sql orders them
	protected static function positions($replies) {
		$queue = array_values(array_filter($replies, function ($reply) { return $reply['status'] === 'waiting'; }));
		usort($queue, function ($a, $b) { return strcmp((string)$a['waiting_since'], (string)$b['waiting_since']) ?: ((int)$a['id'] - (int)$b['id']); });
		$positions = array();
		foreach ($queue as $n => $reply) $positions[(int)$reply['id']] = (string)($n + 1);
		return $positions;
	}

	// replies for a staff page: without the hidden fields, with why each
	// needs attention, its place in the queue ('' unless waiting) and its
	// event's title, date and start, editable in place
	protected static function for_staff($replies, $counts, $positions) {
		$info = cms_records::info('rsvp');
		$rows = array();
		foreach ($replies as $reply) {
			$event = self::item($reply['event']);
			$row = cms_records::shown($info, $reply);
			$row['attention'] = self::reason($reply, $event, self::tally($counts, (int)$reply['event'], 'going', 'people'));
			$row['position'] = isset($positions[(int)$reply['id']]) ? $positions[(int)$reply['id']] : '';
			$row['event_title'] = $event ? $event['title'] : '';
			$row['event_date'] = $event ? $event['date'] : '';
			$row['event_starts'] = $event ? $event['starts'] : '';
			$rows[] = $row;
		}
		return cms_records::listed('rsvp', $rows);
	}
}
