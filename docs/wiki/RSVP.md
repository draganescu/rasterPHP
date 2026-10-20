# Event replies (RSVP)

The bundled `rsvp` model takes replies to your events: yes (with how many are coming), maybe or no. An event has a number of places; a yes that doesn't fit goes on a waitlist, and the model moves people in as places free up. You write the pages, the emails and the words; the model owns the replies, the place arithmetic, the waitlist, the guest's link and the events. You write no model code, and nothing in `the_app.php` is required when your events collection is called `events`.

The events themselves stay content: the items of a collection your views declare (`render.cms.events`), written by editors in the page like any other. The model only reads them.

The model counts only on a site whose views use `render.rsvp.*` or `print.rsvp.*`. Other sites get no table, no Admin entries and nothing new in `describe` or MCP. A site with its own model or record type called `rsvp` keeps it: the bundled one stays out of the way, and `raster lint` says so.

The demo café in the Raster repository (`demo/`) is a complete example: replies on each event's page (`/events/events_item/<slug>`), the guest's page on `/rsvp` and the staff page on `/staff/events`. `php demo/seed.php` adds a jazz night with 40 places and a poetry evening with 4, so the waitlist is easy to see.

Emails go out through the `mail` model, so set up [Sending email](Sending-Email) first.

On a production site the database is frozen: run `php bin/raster schema --apply` once after adding the pages, before the form can store anything. That makes the replies' table (`rsvpdata`), the new columns of your events and the spam checks' tables. In development they are made on the first use.

## What your site writes

- the event's fields, in its views;
- the form, on the event's own page;
- the guest's page, `rsvp.html`;
- the emails, in `_email/`;
- a staff page, protected for editors.

## The event's fields

The model reads four fields of an event by name. Write them in the collection's views like any other field, with a mock-up value that gives the type:

```html
<!-- events_item.html -->
<!-- render.cms.events -->
<h1><!-- print.title -->Jazz night<!-- /print.title --></h1>
<p><!-- print.date -->2026-11-20<!-- /print.date --> at <!-- print.starts -->20:00<!-- /print.starts --></p>
<p><!-- print.places -->40<!-- /print.places --> places, replies until <!-- print.rsvp_until -->2026-11-20 18:00<!-- /print.rsvp_until --></p>
<!-- /render.cms.events -->
```

| Field | Type | Meaning |
|---|---|---|
| `date` | date | required: an event without one takes no replies |
| `starts` (or `time`) | time | when it starts; without one, midnight of its date |
| `places` | number | the most people with a yes; empty or 0 is no limit, and no waitlist |
| `rsvp_until` | date and time | when replies close; empty is when it starts (or `rsvp_closes` minutes before) |

A field's type comes from the collection's own view (`events.html`, else `events_item.html`), so put `places` with its mock-up (`40`) in the list's view too. A draft or a scheduled event takes no replies from guests.

## The form

The form goes on the event's own page, or on any page with `?event=<id>`. Above it, `render.rsvp.event` says how many places are left:

```html
<!-- print.if.live -->
<!-- render.rsvp.event -->
<p class="places"><!-- print.left -->12<!-- /print.left --> of <!-- print.places -->40<!-- /print.places --> places left. Replies close <!-- print.deadline -->2026-11-20 18:00<!-- /print.deadline -->.</p>
<!-- /render.rsvp.event -->

<!-- print.validation.alert('going') --><p class="notice">You're in. We've emailed you a link to change your reply.</p><!-- /print.validation.alert('going') -->
<!-- print.validation.alert('waiting') --><p class="notice">It's full: you're on the waitlist, and we'll email you if a place frees up.</p><!-- /print.validation.alert('waiting') -->
<!-- print.validation.alert('noted') --><p class="notice">Thanks for letting us know.</p><!-- /print.validation.alert('noted') -->
<!-- print.validation.alert('already_replied') --><p class="notice">You've replied already: we've emailed you a link to change your reply.</p><!-- /print.validation.alert('already_replied') -->
<!-- print.validation.alert('closed') --><p class="error">Replies are closed.</p><!-- /print.validation.alert('closed') -->
<!-- print.validation.alert('too_many') --><p class="error">For more than 6, call us.</p><!-- /print.validation.alert('too_many') -->

<!-- render.rsvp.form -->
<form method="post">
  <label><input type="radio" name="answer" value="yes" checked> Yes</label>
  <label><input type="radio" name="answer" value="maybe"> Maybe</label>
  <label><input type="radio" name="answer" value="no"> No</label>
  <label>People <input type="number" name="people" min="1" max="6" value="1"></label>
  <label>Name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Phone <input type="tel" name="phone"></label>
  <label>Notes <textarea name="notes"></textarea></label>
  <button>Reply</button>
</form>
<!-- /render.rsvp.form -->
<!-- /print.if.live -->
<!-- print.if.static --><p>Call us to reply.</p><!-- /print.if.static -->
```

- The form posts `name`, `email`, `phone`, `answer`, `people` and `notes`. The event is always the page's: a guest can't reply to another event by changing the post.
- After a reply the page comes back with `?done=going`, `?done=waiting` or `?done=noted`.
- The alerts the form can raise: `not_open` (no event visitors can see), `name_invalid`, `email_invalid`, `answer_invalid`, `people_invalid`, `too_many` (over `places`, or over `rsvp_max_people` for guests), `closed` (past the deadline), `full` (it doesn't fit and `rsvp_waitlist` is off) and `already_replied`. Shown first, the form already says `not_open` or `closed` when that applies.
- It has the spam checks of every public form, so word `try_again` and `slow_down` too, once, in your layout (see [Forms and validation](Forms-and-Validation)).
- `render.rsvp.event`'s row has `places` and `left` (empty for no limit), `going` (people), `waiting` (replies), `deadline` and `state`: `open`, `full` (a yes goes on the waitlist), `closed` (past the deadline) or `over` (it has started). For a sentence per state without JavaScript, write each one in the block, put `<!-- print.+class.state -->` around them, and let your CSS show the one whose class matches, as the demo does.
- The form and the places left are never cached, and a [static export](Static-Export) can't take replies: keep them inside `print.if.live`.

**One reply per address.** A post with an address that already replied to this event stores nothing: it shows `already_replied` and emails the link to that reply's own address, at most once every five minutes. Nobody can change someone else's reply by typing their address, or flood their inbox.

## The waitlist

A yes is `going` while the people going plus its party fit `places`; otherwise it is `waiting`. When places free up (someone says no or maybe, comes with fewer, or is deleted; staff raise `places`), the model moves in, oldest first, every waiting party that fits: a party of four that doesn't fit doesn't keep two places from a couple behind it. Each one moved in gets an email.

- Two people replying at the same moment for the last place can't both get it: one is in, the other waits.
- Nobody going is ever moved out. Staff may lower `places` under the people going, move the date or hide the event; every reply keeps its place, and the staff page shows what needs a phone call.
- From the deadline nobody is moved in: a place given back is staff's to give, with the Promote button. From the start, guests change nothing.

## The guest's emails

Every email to the guest has a fresh link to their reply. Write these views in `_email/`; `raster lint` names any that is missing:

| View | To | When |
|---|---|---|
| `rsvp_going` | guest | the reply is in, or a party that is in changed size |
| `rsvp_waiting` | guest | the reply went on the waitlist, or a waiting party changed size |
| `rsvp_promoted` | guest | moved in from the waitlist |
| `rsvp_noted` | guest | they said maybe or no |
| `rsvp_link` | guest | they replied again with the same address: their link again |
| `rsvp_new` | staff | a guest replied or changed their reply; only when `rsvp_staff_email` is set |

Each view gets the reply's fields, `manage_url` (the guest's link), `position` (their place on the waitlist), `event_title`, `event_date`, `event_starts`, `event_url`, `deadline` and `site_url`. The `<title>` is the subject, and may print the event:

```html
<title>You're in: <!-- print.self.event_title -->Jazz night<!-- /print.self.event_title --></title>
<p>Hello <!-- print.self.name -->there<!-- /print.self.name -->, we have you down for <!-- print.self.people -->2<!-- /print.self.people -->.</p>
<p><a href="<!-- print.self.manage_url /-->">Change your reply</a></p>
```

Staff get no email unless you set `rsvp_staff_email`: a jazz night's forty replies are on the staff page.

## The guest's page

The link opens `/rsvp?token=…` (the `rsvp_manage_page` setting moves it). Opening it changes nothing, so a mail scanner that follows links can't change a reply. Only the page's form does.

```html
<!-- print.validation.alert('changed') --><p>Done: we've emailed you your reply.</p><!-- /print.validation.alert('changed') -->
<!-- print.validation.alert('link_invalid') --><p>This link no longer works. Call us.</p><!-- /print.validation.alert('link_invalid') -->
<!-- print.validation.alert('closed') --><p>Replies are closed: you can still tell us you can't come, or come with fewer.</p><!-- /print.validation.alert('closed') -->
<!-- print.validation.alert('full_for_more') --><p>There aren't places for more of you; your places are kept.</p><!-- /print.validation.alert('full_for_more') -->

<!-- render.rsvp.manage -->
<p><!-- print.name -->Ana<!-- /print.name -->, for <!-- print.event_title -->Jazz night<!-- /print.event_title -->: <!-- print.status -->going<!-- /print.status --></p>
<form method="post">
  <input type="hidden" name="do" value="change">
  <label><input type="radio" name="answer" value="yes"> Yes</label>
  <label><input type="radio" name="answer" value="maybe"> Maybe</label>
  <label><input type="radio" name="answer" value="no"> No</label>
  <label>People <input type="number" name="people" min="1"></label>
  <button>Change my reply</button>
</form>
<!-- /render.rsvp.manage -->
```

- The link works until the event starts, also after a no, so the guest can say yes again before the deadline. It dies when the event is hidden or deleted.
- Places it frees go to the waitlist at once.
- A guest who replied while logged in opens their reply from their account without a link: `/rsvp?reply=<id>`. On the account page, `render.cms.rsvp('owner=me&order=-created_at')` lists their replies.
- The page is never cached and is left out of the sitemap and a static export. Don't protect `rsvp` for staff: guests would get the login page, and `lint` warns.

## The staff page

The staff page is made of [record](Records) lists, so staff edit replies in the [in-page editor](The-In-Page-Editor), move a waiting reply in with its Promote button, and add one taken over the phone from the card at the end of a list.

```html
<p><!-- print.rsvp.count('status=waiting') -->3<!-- /print.rsvp.count('status=waiting') --> replies waiting (past events too),
   <!-- print.rsvp.count('attention') -->0<!-- /print.rsvp.count('attention') --> that need attention</p>

<h2>Needs attention</h2>
<!-- render.rsvp.attention --><p><!-- print.name -->Ana<!-- /print.name -->, <!-- print.event_title -->Jazz night<!-- /print.event_title -->: <!-- print.attention -->moved<!-- /print.attention --></p><!-- /render.rsvp.attention -->

<h2>Evenings to come</h2>
<!-- render.rsvp.events('from=today') -->
<h3><!-- print.event_title -->Jazz night<!-- /print.event_title -->, <!-- print.event_date -->2026-11-20<!-- /print.event_date --></h3>
<p><!-- print.going -->28<!-- /print.going --> going, <!-- print.left -->12<!-- /print.left --> of <!-- print.places -->40<!-- /print.places --> left, <!-- print.waiting -->0<!-- /print.waiting --> waiting</p>
<ul><!-- print.replies --><li><!-- print.name -->Ana<!-- /print.name --> <!-- print.people -->2<!-- /print.people --> <!-- print.status -->going<!-- /print.status --></li><!-- /print.replies --></ul>
<!-- /render.rsvp.events('from=today') -->

<h2>One evening</h2>
<form method="get">
  <select name="event"><option value="">All events</option>
    <!-- render.rsvp.events('from=today') --><!-- print.@value.event --><option value="0"><!-- print.event_title -->Jazz night<!-- /print.event_title --></option><!-- /print.@value.event --><!-- /render.rsvp.events('from=today') -->
  </select>
  <button>Show</button>
</form>
<!-- render.cms.rsvp('event=?event&order=status,waiting_since') --><p><!-- print.name -->Ana<!-- /print.name --> <!-- print.status -->going<!-- /print.status --></p><!-- /render.cms.rsvp('event=?event&order=status,waiting_since') -->
```

- `render.rsvp.events('from=today')` lists the events from that day, drafts included, with their counts (`going`, `left`, `waiting`, `maybe`, `no`) and `replies`, each with `attention` and `position`.
- The GET form picks one evening. Give the mock-up option a value no event has (`0`), so it is never the one the URL asks for. The card at the end of its list starts with that evening, so a phone reply needs no id typed in. A phone reply needs no email, and it waits like anyone's when the evening is full.
- `count()` takes plain values (`count('status=going&event=7')`), not `?event`, and counts every reply that matches, past events' too. One evening's numbers are in its row.
- Link to one evening with the GET form, not a link built with `print.+href`: that adds a space before the id.

Protect the staff page by its own name, never by `rsvp`:

```php
config::set('protected')->to(array('staff' => 'editor', 'rsvp_item' => 'editor'));
```

An `rsvp_item.html` view that renders `render.cms.rsvp` is each reply's own page (`/rsvp/rsvp_item/<slug>`).

**Needs attention.** Staff edit events all the time, so nothing they do to an event is refused, nobody going is moved out, and nobody is emailed. Instead each reply that no longer fits shows why: `event_gone` (deleted), `event_hidden` (a draft or scheduled), `over_places` (places lowered under the people going) or `moved` (the date or time changed since the guest replied). Phone the guest and save the reply (a note, "phoned them") to clear `moved`.

Staff (the in-page editor, MCP, your own code) skip the deadline and `rsvp_max_people`, and may reply for a draft, but never go over `places`: a yes that doesn't fit waits. `promote` moves a waiting reply in now, out of turn and after the deadline too, when its party fits.

Name the button and refusals in your site's words in `application/i18n/<lang>/raster_editor.php`: `'action_promote' => 'Let them in'`, `'problem_not_enough_places' => 'Not enough places: raise the places first'`.

## More fields

To add a field to a reply (a dietary note), override the model's types:

```php
<?php // application/models/the_rsvp/the_rsvp.php
class the_rsvp extends rsvp {
    static function types() {
        $types = parent::types();
        $types['rsvp']['fields']['dietary'] = '';
        return $types;
    }
}
```

Add `<input name="dietary">` to the form; the editor, the emails and the events carry it.

## Events

After each change commits, the model sends `rsvp.replied` (a reply was made), `rsvp.changed` (its answer, people or event changed, with `was` and `was_people`) or `rsvp.promoted`, with the reply's fields (never the link), `who` (`guest`, `staff` or `waitlist`) and `event_title`, `event_date` and `event_starts`. Listen to them like any [event](Events):

```php
static function listens() {
    return array('rsvp.promoted' => 'tell_the_door');
}
```

The emails are the model's own listeners. To send your own instead, unbind one in `config/the_events.php`: `event::unbind('rsvp.promoted')->from('rsvp', 'promoted_mail');` (also `replied_mail` and `changed_mail`).

## Settings

| Setting | Default | |
|---|---|---|
| `rsvp_collection` | `events` | the collection whose items take replies |
| `rsvp_manage_page` | `rsvp` | the guest's page |
| `rsvp_staff_email` | none | where staff emails go; empty sends none |
| `rsvp_max_people` | 6 | people in a guest's reply |
| `rsvp_closes` | 0 | minutes before the start replies close, for an event without `rsvp_until` |
| `rsvp_waitlist` | true | false refuses a yes that doesn't fit (`full`) |

Times are the site's clock (setting `timezone`).

Everything the model does is listed in `AGENTS.md`, under Bundled models.
