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
* nothing is moved in; from the start a guest changes nothing. Staff add
* and edit replies in the page (render.cms.rsvp('event=?event')).
*
* Regions:
*   <!-- render.rsvp.event -->  one row for the page's event (its item page,
*     /events/events_item/<slug>, else ?event=<id>): event, places and left
*     ('' for no limit), going (people), waiting (replies), state (open,
*     full, closed, over) and deadline. Never page-cached.
*
* The action: promote (editors), a waiting reply in now, out of turn, if
* its party fits, also after the deadline.
*
* Alerts: not_open, name_invalid, email_invalid, answer_invalid,
* people_invalid, too_many, closed, full, already_replied, full_for_more,
* not_enough_places; for the action not_waiting.
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
	// the guest's new links, id => raw token: this request only
	static $links = array();
	// the id of the page's event (page_event()), false until it is read
	static $page = false;

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

	// another reply to this event with this address
	protected static function replied($event, $email, $id) {
		if (!cms_store::table_exists('rsvpdata')) return false;
		return (bool)database::instance('rsvp')->replied((int)$event, strtolower($email), (int)$id);
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
				$saved = cms_records::update('rsvp', $row['id'], array('status' => 'going', 'waiting_since' => ''));
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
			$saved = cms_records::update('rsvp', $reply['id'], array('status' => 'going', 'waiting_since' => ''));
			cms_records::dispatch('rsvp.promoted', self::payload($saved, 'staff'));
			return $saved;
		});
	}

	// Runs the model's own write $work for $who ('guest', 'staff' or
	// 'waitlist') in one transaction, so its events wait for the commit;
	// places it freed go to the waitlist before then. A link issued for a
	// write that didn't happen is dropped.
	protected static function writing($who, $id, $work) {
		$acting = self::$acting;
		$writing = self::$writing;
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
			unset(self::$links[(int)$id]);
			self::$freed = array();
			throw $e;
		} finally {
			self::$acting = $acting;
			self::$writing = $writing;
		}
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
		$item = $table === null ? null : cms_store::get_item($table, $id);
		$event = null;
		if ($item && (string)$item['date'] !== '') {
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
		return self::$events[$id] = $event;
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
	// page, over MCP or in model code gets its event (who: staff), and the
	// places it freed go to the waitlist. In a transaction of its own.
	function saved($saved) {
		if (!is_array($saved) || !isset($saved['collection'], $saved['item']['id']) || !self::ready()) return null;
		if ($saved['collection'].'data' === self::table()) {
			self::promote_waiting((int)$saved['item']['id']);
			return null;
		}
		if ($saved['collection'] !== 'rsvp' || self::$writing) return null;
		$id = (int)$saved['item']['id'];
		$changed = isset(self::$changed[$id]) ? self::$changed[$id] : null;
		unset(self::$changed[$id]);
		if ($saved['created']) cms_records::dispatch('rsvp.replied', self::payload($saved['item'], 'staff'));
		elseif ($changed && $changed['answered']) cms_records::dispatch('rsvp.changed', self::payload($saved['item'], 'staff') + array('was' => $changed['was'], 'was_people' => $changed['was_people']));
		while (self::$freed) {
			$event = key(self::$freed);
			unset(self::$freed[$event]);
			self::promote_waiting($event);
		}
		return null;
	}

	// cms.item_deleted: a going reply deleted frees its places. Deleting
	// an event moves nobody: its replies need attention.
	function deleted($deleted) {
		if (self::$writing || !is_array($deleted) || !isset($deleted['collection'], $deleted['item']['status']) || $deleted['collection'] !== 'rsvp' || !self::ready()) return null;
		if ($deleted['item']['status'] === 'going') self::promote_waiting((int)$deleted['item']['event']);
		return null;
	}

	// ##The setup

	// the bundled type is declared: a site whose own model or type is called
	// rsvp keeps the name (cms_records::types), and then every region here
	// gives nothing
	static function ready() {
		$info = cms_records::info('rsvp');
		return $info !== null && $info['model'] === 'rsvp';
	}

	// what a site taking replies has: the settings
	static function requires() {
		return array(
			'settings' => array('rsvp_collection', 'rsvp_max_people', 'rsvp_closes', 'rsvp_waitlist'),
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
}
