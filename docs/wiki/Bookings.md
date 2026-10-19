# Bookings

The bundled `booking` model takes bookings from your own pages: a café's tables at its sittings, a salon's appointments with or without "with Ana". You write the pages, the emails and the words; the model owns the data, the rules, the status changes, the guest's link and the events. You write no model code, and nothing in `the_app.php` is required.

The model counts only on a site whose views use `render.booking.*` or `print.booking.*`. Other sites get no tables, no Admin entries and nothing new in `describe` or MCP. A site with its own model or record type called `booking` keeps it: the bundled one stays out of the way, and `raster lint` says so.

The demo café in the Raster repository (`demo/`) is a complete example: table bookings on `/visit`, a small salon on `/salon`, the guest's page on `/booking` and the staff pages under `/staff`.

Emails go out through the `mail` model, so set up [Sending email](Sending-Email) first.

On a production site the database is frozen: run `php bin/raster schema --apply` once after adding the booking pages, before the form can store anything. That makes the tables of the bookings, their setup and the spam checks. In development they are made on the first use.

## What can be booked

Staff set this up as records, edited in the page like anything else:

| Type | What it is | Fields |
|---|---|---|
| `bookingresource` | what is booked: a dining room, a stylist, a studio | `name`, `people` (most people at once in a slot), `bookings` (most bookings at once), `active`. 0 is no limit. |
| `bookinghours` | when slots start | `resource` (empty: every resource), `days` (`daily` or `mon,tue,…`), `starts`, `ends` (the last start), `every` (minutes between starts), `from`/`until` (a season) |
| `bookingclosed` | days off | `date`, `until`, `resource` (empty: every one), `note` |
| `bookingservice` | what a booking is for (optional) | `name`, `minutes`, `resources` (slugs that offer it; empty: all), `active` |

A café has one resource, the Dining room with `people` 20, and hours every 120 minutes from 19:00 to 21:00: two sittings. A salon has a resource per stylist with `bookings` 1, hours every 30 minutes, and services: a 30 minute cut, a 90 minute colour. Without services a booking lasts until the next start of its hours (a sitting lasts until the next sitting).

Put the four lists on a staff page (`render.cms.bookingresource`, `render.cms.bookinghours`, `render.cms.bookingclosed`, `render.cms.bookingservice`), or make the first ones in a seed script with `cms_records::create()`, as the demo's `demo/seed.php` does. Until there is a resource, the form says `not_open` and `raster lint` warns.

## The booking page

The guest picks a day with a GET form, then a time from the day's slots, shown as radio buttons inside the booking form. No JavaScript is needed.

```html
<form method="get">
  <input type="date" name="date"> <button>Show times</button>
</form>

<!-- print.validation.alert('booked') --><p>Thank you, we have your request. We emailed you a link to change or cancel it.</p><!-- /print.validation.alert('booked') -->
<!-- print.validation.alert('fully_booked') --><p class="error">That time is full now. Pick another.</p><!-- /print.validation.alert('fully_booked') -->
<!-- print.validation.alert('not_a_slot') --><p class="error">Pick one of the times shown.</p><!-- /print.validation.alert('not_a_slot') -->

<!-- render.booking.form -->
<form method="post">
  <!-- render.booking.slots('date=?date&people=?people') -->
  <!-- print.+class.state --><label class="slot"><!-- print.@value.time --><input type="radio" name="time" value="19:00"><!-- /print.@value.time --> <!-- print.time -->19:00<!-- /print.time -->, <!-- print.left_people -->20<!-- /print.left_people --> places left</label><!-- /print.+class.state -->
  <!-- /render.booking.slots('date=?date&people=?people') -->
  <label>Name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>People <input type="number" name="people" min="1" max="8" value="2"></label>
  <button>Book</button>
</form>
<!-- /render.booking.form -->
```

- The form posts `name`, `email`, `phone`, `date`, `time`, `people`, `resource`, `service` and `notes`. What the URL chose and the form doesn't have (the date, the stylist) comes back as hidden inputs.
- Each slot row has `time`, `ends`, `left_people` and `left_bookings` (empty where that limit is 0), `full` (`1` or `0`) and `state` (`free` or `full`, for a class).
- `render.booking.days('limit=7')` lists the next days with a free slot, `date` and `slots_free`: buttons in a GET form make a "pick a day" list.
- With several resources, name the one this page books (a hidden `resource` input, and `resource=dining-room` in the regions), or let the guest choose in the GET form (`resource=?resource`, with `render.booking.resources` for the options). When the resource offers services, the guest chooses one too (`render.booking.services('resource=?resource')`).
- The alerts the form can raise: `booked`, `not_open`, `resource_invalid`, `resource_inactive`, `name_invalid`, `email_invalid`, `people_invalid`, `too_many` (over `booking_max_people`), `not_a_slot`, `service_unavailable`, `too_soon` (within `booking_notice`), `too_far` (past `booking_horizon`), `already_booked` (a double click) and `fully_booked`.

The page's HTML rules (`required`, `max="8"`) work as on any [form](Forms-and-Validation).

## Requests, confirmations and the guest's emails

Every booking is a **request** that holds its place until staff confirm or decline it. Capacity is counted at the busiest minute of the booking, so two guests can never both get the last table, even when they press Book at the same moment.

The guest gets an email at every step, and each email has a fresh link to their booking. Write these views in `_email/`; `raster lint` names any that is missing:

| View | To | When |
|---|---|---|
| `booking_requested` | guest | they booked (or staff booked for them) |
| `booking_new` | staff | a guest booked or moved a booking |
| `booking_confirmed` | guest | staff confirmed it |
| `booking_declined` | guest | staff declined it |
| `booking_cancelled` | guest, and staff when the guest cancelled | it was cancelled |
| `booking_changed` | guest | it moved |

Each view gets the booking's fields, `manage_url` (the guest's link, empty in emails that have none), `resource_name`, `service_name`, `site_url` and `book_url`:

```html
<title>We have your booking request</title>
<p>Hello <!-- print.self.name -->there<!-- /print.self.name -->, we have your request for <!-- print.self.date -->2026-10-20<!-- /print.self.date --> at <!-- print.self.time -->19:00<!-- /print.self.time -->.</p>
<p><a href="<!-- print.self.manage_url /-->">Change or cancel it</a></p>
```

Staff emails go to `booking_staff_email`, else the site's sender.

## The guest's page

The link opens `/booking?token=…` (the `booking_manage_page` setting moves it). Opening it changes nothing, so a mail scanner that follows links can't cancel a booking. Only a form posted from the page acts.

```html
<!-- print.validation.alert('cancelled') --><p>Your booking is cancelled.</p><!-- /print.validation.alert('cancelled') -->
<!-- print.validation.alert('rescheduled') --><p>Your booking has moved. We'll confirm the new time.</p><!-- /print.validation.alert('rescheduled') -->
<!-- print.validation.alert('link_invalid') --><p>This link no longer works. Call us.</p><!-- /print.validation.alert('link_invalid') -->
<!-- print.validation.alert('too_late') --><p>It's too close to your booking to change it here. Call us.</p><!-- /print.validation.alert('too_late') -->

<!-- render.booking.manage -->
<p><!-- print.name -->Ana<!-- /print.name -->, <!-- print.date -->2026-10-20<!-- /print.date --> at <!-- print.time -->19:00<!-- /print.time --></p>
<form method="post"><input type="hidden" name="do" value="cancel"><button>Cancel</button></form>
<form method="get"><input type="hidden" name="token"><input type="hidden" name="booking"><input type="date" name="date"> <button>Show times</button></form>
<form method="post">
  <input type="hidden" name="do" value="change">
  <!-- render.booking.slots('date=?date&for=manage') --><label><!-- print.@value.time --><input type="radio" name="time" value="19:00"><!-- /print.@value.time --> <!-- print.time -->19:00<!-- /print.time --></label><!-- /render.booking.slots('date=?date&for=manage') -->
  <button>Move</button>
</form>
<!-- /render.booking.manage -->
```

- A guest can cancel or move until `booking_cutoff` minutes before the start (120); after that the page says `too_late`.
- A moved booking is a request again, and staff get `booking_new`.
- The link works until the booking starts, and is dead once it is cancelled or declined.
- A guest who booked while logged in manages their bookings from their account without a link: `/booking?booking=<id>`. On the account page, `render.cms.booking('owner=me&order=-date')` lists them.
- The page is never cached and is left out of the sitemap and a [static export](Static-Export).

## The staff page

The staff page is made of [record](Records) lists, so staff confirm, decline and cancel with each booking's buttons in the [in-page editor](The-In-Page-Editor), move a booking by changing its date or time, and add one taken over the phone from the card at the end of a list.

```html
<p><!-- print.booking.count('status=requested&date>=today') -->3<!-- /print.booking.count('status=requested&date>=today') --> requests,
   <!-- print.booking.count('attention') -->0<!-- /print.booking.count('attention') --> need attention</p>

<h2>Needs attention</h2>
<!-- render.booking.attention --><p><!-- print.name -->Ana<!-- /print.name --> <!-- print.date -->2026-10-20<!-- /print.date -->: <!-- print.attention -->closed<!-- /print.attention --></p><!-- /render.booking.attention -->

<h2>Requests</h2>
<!-- render.cms.booking('status=requested&date>=today&order=date,time') --><p><!-- print.name -->Ana<!-- /print.name --> <!-- print.date -->2026-10-20<!-- /print.date --> <!-- print.time -->19:00<!-- /print.time --></p><!-- /render.cms.booking('status=requested&date>=today&order=date,time') -->

<h2>This week</h2>
<!-- render.booking.agenda('from=today&days=7') -->
<h3><!-- print.date -->2026-10-20<!-- /print.date --></h3>
<ul><!-- print.slots --><li><!-- print.time -->19:00<!-- /print.time -->: <!-- print.left_people -->12<!-- /print.left_people --> left</li><!-- /print.slots --></ul>
<ul><!-- print.bookings --><li><!-- print.name -->Ana<!-- /print.name --> <!-- print.status -->requested<!-- /print.status --></li><!-- /print.bookings --></ul>
<!-- /render.booking.agenda('from=today&days=7') -->
```

Protect the staff pages by their own names, never by `booking` (that prefix would also lock the guest's `/booking` page, and `lint` warns):

```php
config::set('protected')->to(array('staff' => 'editor', 'booking_item' => 'editor'));
```

A `booking_item.html` view that renders `render.cms.booking` is each booking's own page (`/booking/booking_item/<slug>`).

**Needs attention.** Staff may close a day, change the hours or lower a limit even where bookings already sit. Nothing is refused or cancelled; the bookings that no longer fit show in `render.booking.attention` and the agenda, with the reason: `closed`, `resource_inactive`, `service_unavailable`, `not_a_slot` or `over_capacity`. Confirming one is refused with that reason; cancelling, declining or moving it is not.

Staff (the in-page editor, MCP, your own code) skip the notice, the horizon and `booking_max_people`, and may add a booking in the past, but never over capacity or off the slots.

Name the buttons and refusals in your site's words in `application/i18n/<lang>/raster_editor.php`: `'action_confirm' => 'Confirm booking'`, `'problem_closed' => 'We are closed that day'`.

## More fields

To add a field to a booking (a dietary note, a newsletter box), override the model's types:

```php
<?php // application/models/the_booking/the_booking.php
class the_booking extends booking {
    static function types() {
        $types = parent::types();
        $types['booking']['fields']['newsletter'] = false;
        return $types;
    }
}
```

The form, the editor, the emails and the events carry it.

## Events

After each change commits, the model sends `booking.requested`, `booking.confirmed`, `booking.declined`, `booking.cancelled` or `booking.rescheduled`, with the booking's fields (never the link) and `who`, `guest` or `staff`. Listen to them like any [event](Events):

```php
static function listens() {
    return array('booking.requested' => 'subscribe_guest');
}
function subscribe_guest($booking) {
    if ($booking['who'] === 'guest' && $booking['newsletter']) newsletter::subscribe($booking['email'], $booking['name'], '/visit');
}
```

The emails are the model's own listeners. To send your own instead, unbind one in `config/the_events.php`: `event::unbind('booking.confirmed')->from('booking', 'confirmed_mail');`.

## Settings

| Setting | Default | |
|---|---|---|
| `booking_page` | `book` | the page with the form (`book_url` in emails) |
| `booking_manage_page` | `booking` | the guest's page |
| `booking_staff_email` | the sender | where staff emails go |
| `booking_notice` | 60 | minutes ahead a guest may book |
| `booking_horizon` | 60 | days ahead a guest may book |
| `booking_cutoff` | 120 | minutes before the start a guest may still cancel or change |
| `booking_max_people` | 12 | people in a guest's booking |

Times are the site's clock (setting `timezone`). A time the clock skips in spring is no slot, the hour repeated in autumn is one, and a booking ends by midnight.

Everything the model does is listed in `AGENTS.md`, under Bundled models.
