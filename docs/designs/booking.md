# Bundled model: booking

Status: draft for approval, 2026-10-18. Tracking: #168 (Phase 1).

## Why

Sites that take bookings (a café's tables, a salon's appointments, a
studio's classes with a few seats) each had a booking model written for
them, and each one repeated the same mistakes: capacity summed over the
wrong rows, the guest never told anything, a staff page that lost the
in-page editor, times off by the server's time zone, a cancel link a mail
scanner could follow. Raster is batteries included: booking is written and
tested once, in `system/models/booking/`, like `newsletter`. A site writes
its pages and its words; the model owns the data, the rules, the status
changes, the emails and the events.

## Goals

- One model covers both kinds of booking:
  - **sittings**: fixed times (a café's 19:00 and 21:00);
  - **appointments**: a grid of start times, each booking lasting as long as
    the service chosen (a salon's 30-minute cut or 90-minute colour), with
    or without a chosen person or resource ("with Ana").
- Two limits on every slot, either or both: **people** (a café seats 20)
  and **bookings** (a stylist takes one at a time).
- Every booking is a **request** until staff confirm or decline it. A
  request holds its place, so two guests can't both get the last table.
- The guest gets an email at every step and a **link** to cancel or
  reschedule, up to a cutoff.
- Staff run it from a page with the in-page editor: confirm, decline,
  cancel, move a booking, add phone bookings, edit hours, resources and
  services. No code needed for any of it.
- A site turns it on by writing the annotations in its pages. Nothing in
  `the_app.php` is required.
- Works on SQLite and MySQL, in pages with no JavaScript, and is covered by
  tests and the demo café (which moves onto it).

## Non-goals (for now)

- **Reminders** before the visit. They need something that runs on a
  schedule; that will be its own bundled model (a small scheduler run by
  cron or by visits), later. Booking dispatches the events a scheduler would
  need, so nothing here has to change then.
- **Deposits or payment** at booking. Phase 3 (payments) adds them through
  events; booking stays unaware of money.
- Recurring bookings, calendar sync (ICS feeds, Google), one booking that
  takes several resources at once, waitlists (RSVP has one, Phase 2),
  guests choosing "any stylist" with the model picking one.

## Anti-goals (a failure even if it works)

- A site needs model code of its own for an ordinary café or salon.
- The staff page needs a custom model view that loses the in-page editor.
- Booking a slot needs JavaScript.
- A GET request (a mail scanner opening the link) changes a booking.
- A token, email address or phone number shows up where it shouldn't: in
  a slug, a public list, a log or the page cache.

## What the site writes, what the model owns

| The site writes | The model owns |
|---|---|
| The booking form, the "pick a time" page, the manage page, the staff page, in its own design and words | Record types and their fields, `check()`, the actions, the slot arithmetic, tokens, the emails' data, the events |
| `_email/booking_*.html` views (words of each email) | When each email is sent and to whom |
| Alert words for each problem name (`fully_booked`, `too_late`, …) | Which problem is raised |
| Optional settings in `the_app.php` | Defaults that work without them |

## Names, and sites that already have a booking type

*(Builder check; my call.)* The supporting types carry the model's name
(`bookingresource`, `bookinghours`, `bookingclosed`, `bookingservice`):
`service` is too common a collection name to take. A site whose own model
or record type is called `booking` (AGENTS.md and the wiki taught sites to
write one; the builder's salon site has one) keeps it whole: the site's
model wins, as app models already do, and the bundled types aren't
declared; `lint` gives a notice, nothing breaks. The bundled types exist
only on a site whose views use `render.booking.*` or `print.booking.*`
(found by the cached discovery from the groundwork), so other sites get no
tables, no Admin entries and nothing new in `describe` or MCP.

## Data

All are records (`cms_records`), declared by the bundled model's `types()`
(so Phase 1's groundwork must let bundled models declare types). Staff edit
every one in the page.

### `bookingresource` — what can be booked (staff make these)

| Field | Type | Meaning |
|---|---|---|
| `name` | text | "Dining room", "Ana", "Studio A" |
| `people` | int | most people at once in one slot; 0 = no limit |
| `bookings` | int | most bookings at once in one slot; 0 = no limit |
| `active` | bool | false hides it from guests; its bookings stay |

A café has one resource (Dining room, people 20, bookings 0). A salon has
one per stylist (people 0, bookings 1). A site with **no** resource records
can't take bookings; the form raises `not_open` (and `lint` warns on a page
with `render.booking.form`).

### `bookinghours` — when slots start (staff make these)

| Field | Type | Meaning |
|---|---|---|
| `resource` | text | the resource's slug, or empty for every resource |
| `days` | text | weekdays it applies to: `mon,tue,…` or `daily` |
| `starts` | time | first start, e.g. 19:00 |
| `ends` | time | last start, e.g. 21:00 (a booking may run past it) |
| `every` | int | minutes between starts, e.g. 120 for sittings, 15 for a salon grid |
| `from`, `until` | date | optional season; empty = always |

Slots on a day are the union of the starts of every row that applies.
Sittings and appointments are the same mechanism: sittings are a large
`every`, appointments a small one.

### `bookingclosed` — days off (staff make these)

`date`, `until` (optional, for a range), `resource` (optional; empty = all),
`note`. A closed day has no slots.

### `bookingservice` — what a booking is for (optional, staff make these)

`name`, `minutes`, `resources` (comma-separated slugs that offer it; empty =
all), `active`. When a site has **no** services, a booking lasts `every`
minutes of the hours row it starts in (so a sitting lasts until the next
sitting), and the form has no service field.

### `booking` — the bookings

| Field | Type | Notes |
|---|---|---|
| `name`, `email`, `phone` | text | `email` required unless staff add it |
| `date` | date | site time zone |
| `time` | time | start |
| `ends` | time | worked out by the model from the service or `every`; readonly |
| `people` | int | default 1; 1 for a site whose resources all have `people` 0 |
| `resource` | text | slug; when the site has one resource, filled in by the model |
| `service` | text | slug or empty |
| `notes` | text | |
| `status` | text | `requested`, `confirmed`, `declined`, `cancelled`; readonly |
| `token_hashes`, `token_expires` | hidden | the guest's manage links (a list of sha256 hashes) |
| `changed_at` | datetime | readonly, last status or time change |

Type settings: `create` visitor, `staff_add` true, `owner` true (a guest who
was logged in sees their own on their account page), private. Actions:
`confirm`, `decline`, `cancel` for editors.

A site that needs more fields on a booking (a dietary note, a party name)
uses a `the_booking` override that adds fields (Phase 1's model lookup makes
`the_` overrides reach bundled types).

## Rules

### Capacity (in `check()`, inside the write's transaction)

For the booking's resource, every **active** booking (`requested` or
`confirmed`) whose time overlaps `[time, ends)` on that date counts. Over
any minute of the new booking's span:

- if the resource's `people` > 0: their people + this booking's ≤ `people`;
- if its `bookings` > 0: their count + 1 ≤ `bookings`.

Otherwise `fully_booked`. The booking being changed doesn't count against
itself. The sum is one SQL query in `system/models/booking/sql/`, not
`find()` in PHP. Two guests submitting for the last place at once: one gets
`fully_booked` (records' write transaction already locks).

### A start time is valid when

- it is one of the day's slots for that resource (hours rows, minus closed
  days) — `not_a_slot`;
- the service is offered by that resource — `service_unavailable`;
- it is at least `booking_notice` minutes from now — `too_soon`;
- it is at most `booking_horizon` days ahead — `too_far`;
- `people` is between 1 and `booking_max_people` — `too_many` / `people_invalid`.

Staff writes (in-page edits, phone bookings, MCP) skip only `too_soon` and
`too_far`: staff can squeeze in a booking for in ten minutes, but never
overbook.

### When staff change the setup under existing bookings

*(Decided 2026-10-18: allow and flag.)* Staff may close a day, change or
remove hours, lower a resource's limits or switch a resource or service off
even when bookings already sit there. Nothing is refused, cancelled or
emailed. Instead:

- `check()` runs the slot and capacity rules on a booking only when it is
  created, when its `date`, `time`, `resource`, `service` or `people`
  change, and on `confirm`. Any other edit (a phone number, a note) always
  goes through, so a stranded booking never gets stuck.
- An active booking that no longer fits **needs attention**, with the
  reason: `closed`, `not_a_slot`, `over_capacity`, `resource_inactive`,
  `service_unavailable`. The model works this out when it lists bookings;
  it isn't stored.
- `render.booking.agenda` rows and a `render.booking.attention` list show
  it (each booking through `cms_records::listed`, so staff edit or move it
  in place), and `print.booking.count('attention')` gives the number for
  the staff page's heading.
- `confirm` on such a booking is refused with the reason as its problem;
  `cancel`, `decline` and moving it are not.
- The guest's link keeps working; a guest reschedule is checked as usual,
  and a guest can still cancel.

### Status

```
requested ──confirm──▶ confirmed
    │  └──decline──▶ declined
    └──────┬────────────┘
           cancel (guest by link, or staff)
           ▼
       cancelled
```

- `declined` and `cancelled` are final and free the place.
- **Reschedule** (guest by link, or staff changing `date`/`time`/`resource`/
  `service`): the booking keeps its id and goes back to `requested` when the
  guest does it (staff must confirm the new time); staff changes keep the
  status. The old place is freed and the new one checked in the same
  transaction.
- Each move rereads the booking inside the transaction (as the café's
  `confirm` does today), so a booking cancelled a second ago can't be
  confirmed.

### The guest's link

- Every email to the guest carries a fresh link: 32 random bytes, sent only
  in that email, its sha256 added to the booking's `token_hashes` (a list,
  so the link in the first email still works after confirmation). One
  purpose (manage this booking); not the newsletter's token. The raw token
  never goes into an event or a log: the model hands it to its own email
  listener within the request (a static keyed by booking id) and drops it.
  *(Builder check; my call — Raster has no site secret, so signed links
  would need one.)*
- Valid until the booking's start (`token_expires`), and dead once the
  booking is `declined` or `cancelled`.
- `?token=` on the manage page (no route parameters, issue #110).
- **GET** shows the booking with Cancel and Change buttons. Only a **POST**
  from the site's page acts (the cross-site check applies). A scanner
  following the link changes nothing.
- Cancel or reschedule is refused within `booking_cutoff` minutes of the
  start: `too_late` (the page tells the guest to phone).
- A logged-in guest manages their own bookings from their account the same
  way, without a token.
- Manage pages and anything with `?token=` are never page-cached, and the
  manage page is left out of the sitemap and static export (declared by the
  model, Phase 1 groundwork).

## Emails

Sent after the answer (Phase 1's mail change), each with a `manage_url`
when the guest has one. The site writes the views; `lint` errors when a
page uses `render.booking.form` and a required one is missing.

| View | To | When |
|---|---|---|
| `_email/booking_requested` | guest | booking made (it is a request) |
| `_email/booking_new` | staff | booking made, or rescheduled by the guest |
| `_email/booking_confirmed` | guest | confirmed |
| `_email/booking_declined` | guest | declined |
| `_email/booking_cancelled` | guest, and staff when the guest cancelled | cancelled |
| `_email/booking_changed` | guest | staff moved it, or the guest rescheduled |

Staff address: `booking_staff_email`, default the site's `mail_from`.
A booking staff add with no email sends the guest nothing. All the
booking's fields (not the hidden ones) are passed to each view, plus
`manage_url`, the resource's and service's names, and `site_url`.
Each email is sent at most once per status change (no resend endpoint).

## Events (after the commit)

`booking.requested`, `booking.confirmed`, `booking.declined`,
`booking.cancelled`, `booking.rescheduled`, each with the booking's fields
(no hidden ones) and who did it (`guest` or `staff`). The model's own email
listeners are framework listeners a site can unbind, as with
`newsletter.subscribed`. A future reminders model listens to these.

## Annotations

| Annotation | What it does |
|---|---|
| `render.booking.form` | the form handler (`cms_records::submit`), fields `name email phone date time people resource service notes`; on success redirects with `?done=booked` |
| `render.booking.slots('date=?date&resource=?resource&service=?service&people=?people')` | rows for one day: `time`, `ends`, `left_people`, `left_bookings`, `full` (bool); a GET form picks the day, the page lists times as radio buttons inside the booking form. No JavaScript |
| `render.booking.days('resource=?resource&service=?service')` | the next days with any free slot: `date`, `slots_free`, for a "pick a day" list |
| `render.booking.resources`, `render.booking.services` | active ones, for the form's choices |
| `render.booking.manage` | the guest's manage page: the booking by `?token=` (or the logged-in owner's `?booking=<id>`), its Cancel form and Change form (Change reuses `slots` for the new day); raises `link_invalid`, `too_late`, `cancelled`, `rescheduled` |
| `render.booking.agenda('from=today&days=7&resource=?resource')` | staff: days, each with its bookings through `cms_records::listed` and the places left per slot, editable in place |
| `print.booking.count('status=requested')` | how many bookings wait for a decision, for the staff page's heading |

The staff page itself follows the Records recipe: `render.cms.booking(...)`
lists with filters for requests, upcoming and past, plus `agenda` for the
day view; `render.cms.bookingresource`, `render.cms.bookinghours`,
`render.cms.bookingclosed`, `render.cms.bookingservice` where staff edit the
setup. AGENTS.md gets a worked staff page.

## Settings (all optional)

| Setting | Default | |
|---|---|---|
| `booking_page` | `book` | page with the form (links in emails) |
| `booking_manage_page` | `booking` | guest manage page |
| `booking_staff_email` | `mail_from` | |
| `booking_notice` | 60 | minutes ahead a guest may book |
| `booking_horizon` | 60 | days ahead a guest may book |
| `booking_cutoff` | 120 | minutes before the start a guest may still cancel or change |
| `booking_max_people` | 12 | per booking, for guests |

Time zone is the site's `timezone` (Phase 1 groundwork, #84).

## The demo café moves onto it

`demo/models/reservation` is removed. The café gets one resource (Dining
room, 20 people), hours for its two sittings Tue–Sun, a Monday closed rule
in its hours (the `not_monday` validation rule goes), the guest emails, a
manage page, and `/staff` built from the recipe. `cafe::subscribe_guest`
listens to `booking.requested` instead of `reservation.booked`. The demo
also gets a small salon-style section (two stylists, two services) so both
kinds are exercised. Existing reservation rows in a site's database are not
migrated (the demo's data is seed data); sites with their own `reservation`
model keep it, untouched.

## Tests

A feature ID group in `demo/README.md` (next free letter), tests in
`tests/demo.php` for the café and the salon section and in `tests/run.php`
for the slot arithmetic: sittings, a grid with mixed service lengths and
overlaps, both limits, closed days and seasons, the time zone across DST,
notice/horizon/cutoff, concurrent last-place bookings, a GET to the manage
link changing nothing, an expired or used token, reschedule freeing the old
place, staff overriding notice but not capacity, every email sent once per
change. All on SQLite and MySQL.

## Depends on (Phase 1 groundwork, in #168)

The groundwork lands first, each item its own PR and 2.2.x release, and
defines its own interface there; booking's plan starts from what merged.
One model lookup with `the_` overrides; bundled `sql/` files and query
lookup that doesn't break the caller (#94, #127); bundled models declaring
types, tables, required emails, sitemap/export skips and `/api`; site
`timezone` (#84); mail after the answer (#87). Booking also fixes #80 (a
re-shown form re-ticking boxes), #125 (staff lists through `listed()`),
#88 (pager counts on private lists) and #110 in the parts it touches.

## Left to the builder

- Internal method names, SQL, and how slots are computed and cached within
  a request.
- The exact AGENTS.md wording and the worked staff page.
- How `days` limits its work (it must stay fast for 60 days × a salon grid).
- The demo's words and design.
