# Bundled model: rsvp

Status: approved 2026-10-20 (see **Decisions**; 3 taken the other way:
no shared code). Tracking: #168 (Phase 2). Written against master at
2.2.13; booking's B4 and contact (2.2.21–2.2.29) have landed since, and the
line numbers in booking.php are from then.

## Why

A café's jazz night with 40 places, a studio's open evening, a book
launch: the site lists its events as content (the demo's `events`
collection, `demo/views/cafe/events.html`, `render.cms.events`) and wants
people to say they're coming. Today a site writes that model itself, and
it makes the same mistakes booking's design lists: places summed outside
the write's lock, a "no" that never frees a place, a waitlist nobody
moves, a link a mail scanner can follow, the guest list on a public page.
RSVP is written and tested once, in `system/models/rsvp/`, like `booking`.
The site writes its pages and its words; the model owns the replies, the
places, the waitlist, the emails and the events.

### Why not booking with an event as its resource

Booking (`system/models/booking/booking.php`) is about slots: hours rows,
an `every`, spans that overlap, a `peak` query, and every booking a
`requested` until staff confirm it. An event is one moment that staff
already wrote as content, a reply is answered at once, and a full event
puts people in a queue instead of refusing them. Squeezed into booking, an
event would need a `bookingresource`, a `bookinghours` row that makes
exactly one slot, and a status machine with three more states. So RSVP is
its own small model. It takes booking's ideas for the parts that are not
about slots, as its own code: nothing is shared between the two models (see
**What RSVP takes from booking**).

## Goals

- Any item of the site's events collection takes replies. The event stays
  content that editors write in the page. Its places and its deadline are
  fields of that item, edited in the page with the rest of it.
- A reply is **yes**, **no** or **maybe**, with a party size (`people`) for
  a yes. Only yes takes places.
- A yes that doesn't fit goes on the **waitlist**. When places free up (a
  guest says no, a party gets smaller, staff raise the places, a reply is
  deleted), the model moves people in from the waitlist and emails them.
- The guest gets an email for each reply and each change, with a **link**
  to change their reply. A GET of the link shows the reply; only a POST
  changes it. Tokens are hashed and random, as booking's are.
- A **deadline** per event (or a default before it starts). After it, nobody
  can take a place, but anyone can still give one back.
- Staff see every event to come with its counts and its replies, edit them
  in the page, and add phone replies. They need no code for any of it.
- A site turns it on by writing the annotations. Nothing in `the_app.php`
  is needed when the collection is called `events`.
- Works on SQLite and MySQL and in pages with no JavaScript. It is covered
  by tests, and the demo café's events take replies.

## Non-goals (for now)

- **Tickets and payment.** Paid events come with Phase 3 (payments) through
  events. RSVP never sees money.
- **Reminders** before the event, and **offering** a freed place for a few
  hours before the next person gets it. Both need a scheduler, as booking's
  reminders do (booking.md, Non-goals). Promotion here is immediate.
- **Invitations**: an invite-only event where staff upload a guest list
  and each guest gets a link to reply. *(Decision 8.)*
- A public "who's coming" list. Only counts are public.
- Ticket types, seating, recurring events, names for each person in a
  party (plus-ones), calendar files (ICS).
- Replies for items of more than one collection on one site.
  *(Decision 2.)*
- Places left per event on the public **list** page (`events.html`). A
  region nested in `render.cms.events` runs before it (AGENTS.md,
  "Evaluation order"), so it can't know the row. The item page shows them.

## Anti-goals (a failure even if it works)

- A site needs model code of its own to let people reply to its events.
- Events have to become records, losing "the markup is the schema" and the
  in-page editing they have today.
- A GET request (a mail scanner opening the link) changes a reply.
- A token, email address or phone number shows up where it shouldn't: in a
  slug, a public page, a log, an event payload or the page cache.
- Two people at once both get the last place, or a place freed by a "no"
  stays empty while someone waits.
- A guest who has a place loses it because of something someone else did
  (staff lowering the places, another guest's change).

## What the site writes, what the model owns

| The site writes | The model owns |
|---|---|
| The events collection (it has one already) and the fields `places` and `rsvp_until` in its item view | Reading the event, its places and its deadline from the item |
| The reply form, the manage page and the staff page, in its own design and words | The `rsvp` record type, `fill()`, `check()`, the action, the place arithmetic, the waitlist, tokens, the emails' data, the events |
| `_email/rsvp_*.html` (the words of each email) | When each email is sent and to whom |
| Alert words for each problem name (`closed`, `too_many`, …) | Which problem is raised |
| Optional settings in `the_app.php` | Defaults that work without them |

## Names, and sites that already have an rsvp model

The model is `rsvp`, and so is its one record type, as booking's main type
carries its name. A site whose own model or type is called `rsvp` keeps it
whole: `cms_records::types()` (`system/models/cms/records.php:51`) already
leaves out a bundled model one of whose names the site took, and `lint`
warns. The type exists only on a site whose views use `render.rsvp.*` or
`print.rsvp.*` (`controller::uses()`), so other sites get no table, no
Admin entry and nothing new in `describe` or MCP.

## How a reply attaches to an event

*(Decision 1.)* **The event is an item of a markup collection; the reply
is a record.** Both kinds already exist, and each keeps the job it has:

- **Events stay content.** The item has the fields the site already gives
  it (`title`, `date`, `starts`, `summary`, `body` in the demo), drafts and
  scheduling, its own URL (`/events/events_item/jazz-night`), the in-page
  editor and MCP. RSVP adds two fields, which the site declares by writing
  them in its markup as it does any field:

  ```html
  <p class="places"><!-- print.places -->40<!-- /print.places --> places</p>
  ```

  | Field | Type (from its mock-up) | Meaning |
  |---|---|---|
  | `date` | date | required: an item without one takes no replies (`not_open`) |
  | `starts` (or `time`) | time | optional; without one the event starts at 00:00 of its date |
  | `places` | int | the most people with a yes; empty or 0 is no limit (and no waitlist) |
  | `rsvp_until` | datetime | optional deadline; empty is `rsvp_closes` minutes before the start |

  The names are fixed, so the model never needs telling. `lint` errors on a
  page with `render.rsvp.form` whose collection has no `date`, and gives a
  notice when it has no `places` ("no limit"). *(Builder check; my call:
  fixed names over a mapping setting. The café already has `date` and
  `starts`.)*
- **Replies are records** of the type `rsvp`: private, checked by the model
  in the write's transaction, with readonly and hidden fields. A reply
  names its event by the item's **id**. The slug can't be used: editors
  change slugs, and a markup collection has no `check()` to stop them, as
  booking stops a resource's slug changing (`booking.php:142`). Ids are
  never reused (RedBean's tables are `AUTOINCREMENT`, `rb.php:7652`).
- **Which collection.** The setting `rsvp_collection`, `events` by default.
  The model reads an event with `cms_store::get_item('<collection>data',
  $id)` (`store.php:343`) and treats it as visitors see it: a draft or a
  scheduled item (`enabled` 0, `published_at` in the future) takes no
  replies, as `cms_store::published_sql()` (`store.php:62`) hides it.
- **Which event a page is about.** On the item's page the region finds it
  from the URL, as `render.cms.events` does (`cms.php:316`:
  `util::param('events') === 'events_item'`, then the slug or id in
  `util::param('events_item')`). On any other page, `?event=<id>`. The form
  never posts the event: a guest can't reply to an item they can't see.

What a markup item can't do, and how RSVP lives with it: no model can
refuse an edit of a markup item (`store_item` runs `check()` only for
records, `store.php:367`). So staff may lower `places` under the people
already in, move the date, unpublish or delete the event. As with booking's
setup changes ("allow and flag", booking.md), nothing is refused and nobody
loses a place. The replies affected **need attention** (see Rules).

## Data

### `rsvp` — the replies

| Field | Type | Notes |
|---|---|---|
| `event` | int | the event item's id |
| `name`, `email`, `phone` | text | `email` required unless staff add it; `phone` optional |
| `answer` | text | `yes`, `no` or `maybe`; what the guest said |
| `people` | int | party size, the guest included; default 1; counts only for a yes |
| `notes` | text | |
| `status` | text | readonly: `going`, `waiting`, `maybe`, `no` |
| `waiting_since` | datetime | readonly: their place in the queue; empty unless `waiting` |
| `changed_at` | datetime | readonly: last change of status or people |
| `token_hashes` | hidden list | sha256 of the guest's links, the newest 20, as booking |
| `link_sent_at` | hidden datetime | when a link was last mailed again (`rsvp_link`) |

Type settings: `create` visitor, `staff_add` true, `owner` true (a guest
who was logged in finds their replies on their account page), private.
Action: `promote` for editors. A site that wants more fields (a dietary
note) uses a `the_rsvp` override whose `types()` adds them, as booking's
`the_booking` does.

`answer` is what the guest said and `status` is what the model made of
it: `no` → `no`, `maybe` → `maybe`, `yes` → `going` when the party fits,
else `waiting` (else refused, when `rsvp_waitlist` is false). Keeping both
means staff change `answer` in the page and the model works out the rest
in `fill()`, as booking works out `ends`.

No `token_expires`: an event can move, so a link's life is read from the
event each time it is used (see **The guest's link**).

## Rules

### Places (in `fill()` and `check()`, inside the write's transaction)

For an event with `places` > 0, the people **going** are the sum of
`people` over its replies with status `going`, the reply being written
left out. One SQL file, `system/models/rsvp/sql/going.sql`, read inside
the write's lock: a SUM, with nothing like booking's `peak`. A yes fits
when going + `people` ≤ `places`. Two guests posting for the last place at
once: one is `going` and the other `waiting`, since records' transactions
already lock (`records.php:387`).

### A reply is valid when

- the event exists and visitors can see it — `not_open` (also no `date`);
- `name` isn't empty — `name_invalid`; `email` is a valid address (a
  guest's must be there) — `email_invalid`;
- `answer` is `yes`, `no` or `maybe` — `answer_invalid`;
- for a yes, `people` is from 1 to `rsvp_max_people` — `people_invalid` /
  `too_many`, and no bigger than `places` — `too_many` (it could never
  fit);
- for a yes that would be new or more people, it is before the deadline —
  `closed`;
- for a yes that doesn't fit, `rsvp_waitlist` is on — `full`;
- there is no other reply to this event with this email — `already_replied`
  (see below).

Staff writes (the in-page editor, MCP, model code: `cms_records::writer()`,
as `booking::is_staff()` reads it) skip `closed`, `too_many` against
`rsvp_max_people` and the missing email. They never put anyone over
`places`: a staff yes that doesn't fit goes on the waitlist like anyone's.
*(Decision 7.)* The rules run when a reply is made and when its `answer`,
`people` or `event` changes. Any other edit (a phone number, a note)
always goes through, so a reply that needs attention never gets stuck.

### One reply per email per event

A second form post with an address that already replied is refused
(`already_replied`), and the existing reply's link is emailed to that
address again (`_email/rsvp_link`), at most once per `mail::RESEND_AFTER`
(5 minutes, `mail::sent_lately()`, `mail.php:233`, as newsletter and
password reset do). Only the address's owner gets the link, so nobody can
change someone else's reply by typing their email. The page says "we've
emailed you a link to change your reply".

### The deadline

The deadline is `rsvp_until` when the item has one, else the start minus
`rsvp_closes` minutes (default 0, which is the start itself). *(Decision 5.)*

- Before it, anything goes.
- After it and until the event starts, a guest can only **give places
  back**: say no or maybe, or make the party smaller. A new yes, a no
  becoming a yes, or a bigger party is refused with `closed`. A place given
  back is still a place someone may come for, and staff want to know.
- Once the event has started, the link shows the reply and changes nothing
  (`link_invalid` on a post).

### The waitlist

- A yes that doesn't fit is `waiting`, with `waiting_since` now. The guest
  is told at once (`rsvp_waiting`), with their place in the queue
  (`position`).
- **Promotion** runs whenever places may have freed up: a going reply
  becomes no or maybe, or its party gets smaller; a going reply is deleted;
  `places` is raised or emptied on the event; a reply moves to another
  event. `rsvp::promote($event)` reads the queue and the places inside the
  write's lock and moves in, oldest `waiting_since` first, **every waiting
  reply whose party fits**. A party of 4 that doesn't fit doesn't stop a
  party of 2 behind it. *(Decision 4.)* Each one moved becomes `going`,
  gets `rsvp_promoted` and sends `rsvp.promoted`.
- When the model makes the change (a guest's form or link, an action),
  promotion runs in **the same transaction**. When staff make it in the
  page or over MCP, or edit the event, the model's listeners on
  `cms.item_saved` and `cms.item_deleted` (`store.php:450`, `:469`; both
  sent after the commit) run it in a transaction of their own. It reads
  everything again, so running it twice moves nobody twice.
- Promotion stops at the deadline. After it, freed places stay free for
  staff to give (the `promote` action, walk-ins at the door), and the
  waitlist is shown to staff as it stands. *(Decision 5.)*
- A waiting guest who makes their party smaller keeps their place in the
  queue, and so does one who makes it bigger. One who says no or maybe
  leaves the queue. Saying yes again joins at the end.
- A going guest who asks for more people than fit is refused
  (`full_for_more`) and keeps the places they have. The model never moves
  anyone from `going` back to `waiting`.
- `promote` (an action, editors): this waiting reply goes in now, out of
  turn, if its party fits (`not_enough_places` otherwise; staff raise
  `places` first, which promotes in order).

### Needs attention

An active reply (`going` or `waiting`) needs attention, with the reason,
when the event no longer fits it:

- `event_gone`: the item was deleted;
- `event_hidden`: it is a draft or scheduled again;
- `over_places`: `places` was lowered below the people going (every going
  reply of that event is flagged; nobody is moved out);
- `moved`: the event's date or start changed after the reply was made
  (from `changed_at`; staff may want to tell people).

As with booking, the reason is worked out when replies are listed, never
stored. `render.rsvp.attention` lists such replies and
`print.rsvp.count('attention')` counts them. *(Decision 6.)*

### The guest's link

The same scheme as booking (`booking.php:433`–`470`), written again in
`rsvp` (see below):

- Every email to the guest carries a fresh link: 32 random bytes, sent only
  in that email. Its sha256 is added to `token_hashes`, which keeps the
  newest 20, so the link in the first email still works after a promotion.
  The raw token is in no event, log, record or cache. The model hands it
  to its own email listener within the request.
- `?token=` on the manage page (`rsvp_manage_page`, `rsvp`).
- The link works until the event starts, read from the event each time,
  so it follows a moved event. It is dead once the event is deleted or
  hidden. Unlike a cancelled booking's, a **no** reply's link stays alive,
  so the guest can change their mind back to yes before the deadline.
- **GET** shows the reply, the event and the choices. Only a **POST** from
  the site's own page acts (the cross-site check applies), with `do=change`
  and the new `answer` and `people`. A scanner following the link changes
  nothing.
- A logged-in guest manages their own reply from their account by
  `?reply=<id>`, without a token (its `owner`, as booking's `?booking=`).
- The manage page is never page-cached, sends `Referrer-Policy:
  same-origin`, and is left out of the sitemap and static export
  (`requires()['pages']`).

### The page cache and static export

The item page shows places left and the form, so `render.rsvp.event` and
`render.rsvp.form` call `raster_cache::skip()`, as booking's `slots` does.
Event item pages with RSVP are therefore not page-cached. A static export
has no PHP to post to: the site wraps the form in `print.if.live`, as the
café does its booking form.

## Emails

Sent after the answer, each with a `manage_url` when the guest has one.
The site writes the views; `lint` errors when a page uses
`render.rsvp.form` (or `render.rsvp.manage`) and a required one is missing.

| View | To | When |
|---|---|---|
| `_email/rsvp_going` | guest | the reply becomes `going`, or a going party changes size, by the guest or by staff (not by promotion) |
| `_email/rsvp_waiting` | guest | the reply becomes `waiting` |
| `_email/rsvp_promoted` | guest | moved in from the waitlist |
| `_email/rsvp_noted` | guest | the reply becomes `no` or `maybe` |
| `_email/rsvp_link` | guest | they replied again with the same address: their link again |
| `_email/rsvp_new` | staff | a guest replied or changed their reply; only when `rsvp_staff_email` is set |

*(Builder check; my call.)* Staff emails are off unless
`rsvp_staff_email` is set. A café doesn't want forty emails for a jazz
night, and the staff page has the counts. Booking's default is the sender,
because every booking waits for a staff decision. A reply here waits for
nothing.

Each view gets the reply's fields (not the hidden ones), `who`,
`manage_url`, `position` (in the queue, for `rsvp_waiting`), the event's
`event_title` (its title, headline or name, as slugs choose,
`cms_store::slug_source`), `event_date`, `event_starts`, `event_url` (the
item's page), `deadline` and `site_url`. A reply staff add with no email
sends the guest nothing. Each change sends each email once. An edit that
changes neither status nor people sends nothing. Editing the event emails
nobody. *(Decision 6.)*

## Events (after the commit)

| Event | When |
|---|---|
| `rsvp.replied` | a reply was made, any answer, by a guest or staff |
| `rsvp.changed` | its `answer`, `people` or `event` changed, with `was` (the status before) |
| `rsvp.promoted` | moved in from the waitlist, by promotion or the `promote` action |

Each has the reply's fields (no hidden ones), `who` (`guest` or `staff`;
`waitlist` for an automatic promotion), and `event_title`, `event_date`,
`event_starts`. The model's own email listeners are framework bindings in
`system/config/events.php`, bound only for the bundled model and only
where a view uses it, as booking's are (`events.php`, the `$booking`
block). A site unbinds one to send its own:
`event::unbind('rsvp.promoted')->from('rsvp', 'promoted_mail')`. The two
CMS listeners (`cms.item_saved`, `cms.item_deleted`, for replies and for
the events collection) are bound the same way.

## Annotations

| Annotation | What it does |
|---|---|
| `render.rsvp.event` | one row for the page's event: `event`, `places` (`''` for no limit), `going` (people), `left` (`''` for no limit), `waiting` (replies), `state` (`open`, `full`: a yes goes on the waitlist, `closed`: past the deadline, `over`), `deadline`. Never page-cached |
| `render.rsvp.form` | the reply form (`cms_records::submit`), fields `name email phone answer people notes`; the event from the page (above). Redirects with `?done=going`, `?done=waiting` or `?done=noted`; `already_replied` shows the form again with that alert |
| `render.rsvp.manage` | the guest's page, by `?token=` (or the owner's `?reply=<id>`): one row, the reply without hidden fields, with the event's fields as above and `position`; its form posts `do=change`. Alerts `link_invalid`, `closed`, `full_for_more`, then `changed` after |
| `render.rsvp.events('from=today')` | staff: events from that day, each with `event`, `event_title`, `event_date`, `event_starts`, `places`, `going`, `left`, `waiting`, `maybe`, `no`, and `replies` (that event's replies through `cms_records::listed`, editable in place, each with `attention`, `position`) |
| `render.rsvp.attention` | staff: active replies to events to come that need attention, with the reason |
| `print.rsvp.count('status=waiting')` | staff: how many replies match (values as `cms_records::find` takes them), or `'attention'` |

Staff regions give nothing to anyone but editors, wherever a page puts
them (as `booking::agenda`, `booking.php:1012`). The staff page follows the
Records recipe. `render.cms.rsvp('event=?event&order=status,waiting_since')`
lists one event's replies, and the new-item card at its end starts with
`event` from the URL (AGENTS.md, Options: "A new item added to the list in
the page starts with the values its `=` filters ask for"), so a phone reply
is added in the page with no event id to type.

## Settings (all optional)

| Setting | Default | |
|---|---|---|
| `rsvp_collection` | `events` | the markup collection whose items take replies |
| `rsvp_manage_page` | `rsvp` | the guest's page their links open |
| `rsvp_staff_email` | empty | staff emails go here; empty sends none |
| `rsvp_max_people` | 6 | party size for guests |
| `rsvp_closes` | 0 | minutes before the start that replies close, for an event with no `rsvp_until` |
| `rsvp_waitlist` | true | false: a yes that doesn't fit is refused with `full` |

Times are the site's clock (`timezone`), and the deadline is real seconds,
as booking's notice is (`booking::starts_at`).

## What RSVP takes from booking

*(Decided 2026-10-20, Decision 3: bundled models share no code.)* RSVP
carries its own copy of every piece it takes from booking. Nothing moves
out of booking, booking is not touched, and neither model calls the other.
A fix in one is made in the other by hand. That is the price of two models
that can each be read, changed and removed alone.

| Piece | In booking | In rsvp |
|---|---|---|
| The guest's link: 32 random bytes, sha256 kept in a hidden list of 20, `hash_equals` lookup, the raw token kept for the request only | `booking::issue`, `by_token`, `$links` (`booking.php:444`–`470`) | `rsvp::issue`, `by_token`, `$links`, with its own `sql/by_token.sql` |
| The manage page's headers: no cache, `Referrer-Policy: same-origin`, the redirect without the token after a change | `booking::manage`, `manage_done` (`booking.php:956`–`1002`) | `rsvp::manage`, `manage_done` |
| Sending a model's email so a failure is logged without the address or link | `booking::send` (`booking.php:580`) | `rsvp::send`, through `controller::model_class('mail')` as booking's does |
| Region options with `?x` from the URL | `booking::options` (`booking.php:815`) | `rsvp::options` |

Also written again, as patterns: `writing()` (the model's own write in one
transaction, who is acting, links dropped on rollback), `payload()`,
`requires()`, the listeners bound in `system/config/events.php` only where
the model is used, `ready()`, and the staff regions through
`cms_records::listed`. RSVP uses the framework (`cms_records`, `mail`,
`validation`, `util`) as every model does.

Not taken: slots, hours, `peak`, the `requested` → `confirmed` status. An
RSVP never waits for staff.

## Our own examples move onto it

The demo café's events take replies:

- `demo/views/cafe/events_item.html`: `print.places` in the item, then,
  inside `print.if.live`, `render.rsvp.event` ("12 of 40 places left", "Full:
  join the waitlist", "Replies closed") and `render.rsvp.form` with answer
  radios (yes, maybe, no), `people` (1–6), and the alerts `going`,
  `waiting`, `noted`, `already_replied`, `closed`, `full`, `too_many`,
  `people_invalid`, `not_open`.
- `demo/views/cafe/rsvp.html`: the manage page.
- `demo/views/cafe/staff/events.html`: `render.rsvp.events('from=today')`,
  `render.rsvp.attention`, `print.rsvp.count('status=waiting')` in the
  heading, and a page per event through
  `render.cms.rsvp('event=?event&…')` for phone replies.
- `demo/views/cafe/account.html`: `render.cms.rsvp('owner=me&order=-created_at')`
  linking to `rsvp.html?reply=`.
- `demo/views/cafe/_email/rsvp_{going,waiting,promoted,noted,link,new}.html`.
- `demo/i18n/{en,ro}/raster_editor.php`: `action_promote`, the problems.
- `demo/seed.php` (from booking B4) adds two events: one with 40 places and
  one with 4, so the waitlist is easy to see.

`demo/README.md` lists `/events/events_item/…`, `/rsvp` and
`/staff/events` in its "Where" table.

## Tests

A feature ID group in `demo/README.md`: the next free letter after
booking's P and contact's Q, so **S** (R is taken). Tests in
`tests/demo.php` for the café, and in `tests/run.php` for the rules:

- places summed only over `going`, the reply being written left out; no
  limit for empty or 0 `places`;
- the last place taken by two posts at once: one `going`, one `waiting`;
- yes, no and maybe; a party too big for the event; `rsvp_max_people` for
  guests and not for staff;
- promotion: a no, a smaller party, a delete, `places` raised in the page,
  each moving in the oldest waiting reply that fits and skipping a party
  that doesn't; run twice moves nobody twice; nothing after the deadline;
  `promote` out of turn;
- a going guest never moved out: `places` lowered flags `over_places`, a
  bigger party refused with `full_for_more`;
- the deadline: `rsvp_until`, `rsvp_closes`, an event with no start time;
  giving back allowed after it, taking refused; nothing once started;
- a draft, a scheduled or a deleted event: `not_open` on the form,
  `event_hidden` / `event_gone` for staff, the link dead;
- the event's date moved: the link follows it, `moved` is flagged, nobody
  is emailed;
- `already_replied` mails the link once per five minutes, and never to an
  address that isn't the reply's;
- a GET to the manage link changes nothing; a POST from another site is
  refused; a dead, unknown or malformed token is `link_invalid`; a no
  reply's link still works;
- every email sent once per change, with the right `manage_url`, and none
  for an edit of a note or the event; staff's only with `rsvp_staff_email`;
- the events and their payloads, with no hidden fields;
- booking's existing suite unchanged (booking is not touched);
- a site using `rsvp` and not `booking` loads no file from
  `system/models/booking/`, and the reverse.

All on SQLite and MySQL, as CI runs them.

## Depends on

- Booking B4 (the café on booking, and `demo/seed.php`), so the demo is
  built on what is there.
- Phase 1.5's spam checks (#191), if merged first: `render.rsvp.form` asks
  for the stamp and both limits, as `render.booking.form` does there, and
  shows `try_again` / `slow_down`. If RSVP lands first, it gets them in the
  Phase 1.5 PR.
- Everything else is on master already: bundled types and `requires()`
  (#180), `sql/` files (#181), one model lookup and `the_` overrides
  (#178), `timezone` and mail after the answer (#179), `fill()` and
  `writer()` (booking B1–B2).

## Left to the builder

- Internal method names, the SQL, and how the event is read and cached
  within a request.
- Whether `booking::options` stays where it is or moves to `cms_store`.
- How `render.rsvp.events` reads the replies of many events in one query.
- The exact AGENTS.md wording (a "Bundled models: rsvp" section) and the
  wiki's Records page pointing to it.
- The demo's words and design.

## Decisions

*(Decided 2026-10-20: every recommendation below is taken except 3, where
the alternative is taken without the later sharing: bundled models share no
code.)*


1. **Where an event's places and deadline live.** Recommended: fields on
   the markup item (`places`, `rsvp_until`), written in the site's item
   view and edited in the page with the rest of the event. The model can't
   refuse a bad edit there, so it flags instead. The other way is a bundled
   `rsvpevent` record type keyed by the item's id: checked writes and
   readonly fields, but staff edit an event in two places, and the item and
   its settings can drift apart.
2. **One events collection per site, or several.** Recommended: one,
   `rsvp_collection` (`events`), so a reply needs only the item's id and
   the guest never names a collection. Several would need a `collection`
   field on every reply, a list setting, and staff pages per collection.
3. **Share booking's link and manage-page code now.** Recommended: move it
   into `cms_links` (and `mail::send_logged`) in RSVP's first PR, with
   booking moving onto it unchanged. This touches a released model. The
   other way is to copy about 80 lines into rsvp and share later (contact,
   donation and store will want the same links).
   **Decided: neither.** RSVP copies the code and nothing is shared, now or
   later. Bundled models stay self-contained, so one can be read, changed
   or removed without touching another ("no dependency hell"). Contact,
   donation and store take their own copies too.
4. **Waitlist order.** Recommended: oldest first, but **every** party that
   fits moves in, so a party of 4 doesn't keep two free places from a
   couple behind it. The other way is strict order: nobody passes the head
   of the queue, which is fairer to big parties and leaves places empty.
5. **What the deadline stops.** Recommended: after the deadline guests can
   still give places back (no, maybe, a smaller party) until the start,
   but can't take any, and promotion stops, so staff's list stops changing
   and freed places go to walk-ins or the `promote` action. The other ways
   are to freeze everything at the deadline, or to keep promoting until
   the start (emails an hour before a gig that nobody reads).
6. **When staff edit an event under replies.** Recommended: nobody is
   emailed. Replies are flagged (`moved`, `over_places`, `event_hidden`,
   `event_gone`) on the staff page, since editors fix typos and dates all
   the time. The other way is an `_email/rsvp_event_changed` to everyone
   going or waiting when the date or start changes, and one when the event
   is deleted. That needs a "this is a real change" signal the in-page
   editor doesn't have.
7. **Staff and places.** Recommended: as booking, staff never go over
   `places`. A staff yes that doesn't fit is waitlisted, and staff raise
   `places` to squeeze someone in, which also promotes in order. The other
   way: staff writes may exceed it (a café's "one more chair"), which
   needs a flag so promotion doesn't count those places as free.
8. **Invitations.** Recommended: later, as their own step. They would add
   staff uploading a guest list, an invitation email with a reply link per
   guest, and replies only from invited addresses. "Reply by link" in
   #168 is read here as the guest's link to change their reply. If
   invite-only events are meant for Phase 2, the type gains an `invited`
   status and the form a mode that refuses unknown addresses.
