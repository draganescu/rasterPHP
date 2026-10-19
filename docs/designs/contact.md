# Spam checks for public forms, and the bundled contact model

Status: approved 2026-10-19, with every recommendation taken (see
**Decisions**). Tracking: #168 (Phase 1.5). Written against master at
Raster 2.2.13; brought up to 2.2.21 (booking's B4 has landed).

## Why

Every site has a contact form, and every one was written by hand from the
example in AGENTS.md (**Forms**) and the wiki (Forms and Validation, the
tutorial's step 7): a `contact` model whose `send()` mails the message and
keeps nothing. The café's `cafe::contact()` (`demo/models/cafe/cafe.php`,
where booking's B4 moved it from `reservation`) is the same thing. So each site
has the same gaps: a message the mail server dropped is gone, staff have no
list of what came in, and the only spam defence is the framework's
honeypot.

The framework already refuses a post from another site and fakes success
to a bot that fills `raster_hp` (`controller::guard_post()`, the field
added in `template::index_forms()`). That stops the dumbest bots. It does
nothing against a script that fetches the form and posts it back at once,
a flood from one address, or a person pasting links. And it can only
throw away: there is no "this looks like spam, keep it aside".

Raster is batteries included. This phase adds two things, written and
tested once:

- **spam checks** any public form can ask for, through the validation
  model forms already use;
- **`contact`**, a bundled model in `system/models/contact/`, like
  `newsletter` and `booking`: the site writes the form and the words; the
  model owns the record, the checks, the email to staff and the event.

## Goals

- One call tells a form model whether a post looks like spam, and why:
  `validation::get()->suspect()`. The reasons are names, as problems are.
- Four checks, all on by default, each with a setting:
  - **too fast**: a signed time stamp, added to every post form as
    `raster_hp` is, says when the form was shown; a post sooner than
    `spam_min_seconds` after it is suspect;
  - **too many**: posts per hour per email address and per IP, for that
    form. IPs (and the addresses, for counting) are hashed with a salt that
    changes every day, and the counts expire. No raw IP is stored or logged;
  - **too many links** in the text fields;
  - **blocked words or domains**, from an optional list.
- `render.contact.form` validates, runs the checks, stores a private
  `contactmessage` record, emails staff after the answer and dispatches
  `contact.received`. A suspected one is stored with its reasons and status
  `spam`, and is not mailed: a false positive is never lost, staff find it
  on their page.
- A staff page from the Records recipe, with the in-page editor: new
  messages, done, spam; one click marks a message as not spam.
- Header-injection safe: nothing a visitor typed reaches a mail header
  except a validated address and an encoded name in `Reply-To`.
- The other bundled public forms (newsletter sign-up, sign-up, forgot
  password, booking) use the same checks.
- A site turns contact on by writing `render.contact.form` in a page.
  Nothing in `the_app.php` is required.
- Works on SQLite and MySQL, with no JavaScript, behind the page cache, and
  is covered by tests and the demo café (whose contact form moves onto it).

## Non-goals (for now)

- **CAPTCHAs** or any third-party check (Akismet, reCAPTCHA, Turnstile).
  They send visitors' data elsewhere and need JavaScript or keys. A site
  that wants one listens to `contact.received` or overrides the model.
- **Answering from the staff page.** Staff reply from their own mail app
  (`Reply-To` is the visitor). Threads and canned replies are a helpdesk.
- **Attachments.** Uploads are their own problem (sizes, types, storage).
- **A copy to the sender.** See the anti-goals.
- Learning filters (Bayesian scores), IP reputation lists, blocking whole
  countries.

## Anti-goals (a failure even if it works)

- The form mails an address the visitor typed (a "send me a copy" box, an
  auto-reply). That makes the site a relay anyone can use to spam anyone.
- A visitor's text reaches a mail header raw: a newline in a name or a
  subject must never add a `Bcc:`.
- A raw IP address is stored, logged, sent in an event or kept past the
  day.
- A real message is thrown away because a check misfired, or a form stops
  working because a check could not run (no database table, no secret, a
  frozen database).
- Sending the form needs JavaScript.
- Every visitor shares one count because the site sits behind a proxy, so
  the tenth visitor of the hour is flagged.
- The staff page needs a custom model view that loses the in-page editor.

## What the site writes, what the framework and the model own

| The site writes | The framework / the model owns |
|---|---|
| The contact form, in its own design and words | The stamp field, the checks, the counts and their salt |
| `_email/contact_received` (the words of the staff email) | When it is sent, to whom, its headers |
| Alert words: `sent`, `try_again`, `slow_down`, `email_invalid`, … | Which alert is raised |
| The staff page, from the Records recipe | The `contactmessage` type, its actions, its listing |
| Optional settings in `the_app.php` | Defaults that work without them |

## Names, and sites that already have a contact model

The model is `contact`; its record type is `contactmessage` (#168's name;
`message` alone is too common a collection name to take, as `service` was
for booking).

AGENTS.md and the wiki taught sites to write `models/contact/` with a
`send()` method, so most sites have one. The rule from the groundwork
(#178, AGENTS.md **Models**) already covers them: a site model with a
bundled model's name hides the bundled one completely. Their
`render.contact.send` keeps calling their own class, nothing of the
bundled model loads, no table or type appears. `lint` gives its existing
warning (`models/contact shadows the bundled contact…`,
`raster_inspector::lint_models()`), which doesn't fail `lint`; the upgrade
step's notes say how to move onto the bundled one. `make model contact`
is refused from then on, as for any bundled name (`system/tools/make.php`).

The bundled types and listeners count only on a site whose views use
`render.contact.*` or `print.contact.*` (the cached discovery booking
uses: `controller::uses()`), so other sites get no table, no Admin entry
and nothing new in `describe` or MCP.

## Part 1: spam checks for public forms

### The stamp

`template::index_forms()` adds a third hidden field to every post form, next
to `raster_form` and `raster_hp`:

```html
<input type="hidden" name="raster_ts" value="1760790000.3f9a…">
```

The value is the Unix time the form was shown and an HMAC-SHA256 of it with
the site's key (see **Settings and the key**), compared with
`hash_equals`. It is skipped wherever `raster_form`, `raster_hp` and `csrf`
are today: field constraints, `form_state()`, filling (the three
`in_array(... array('raster_form', 'raster_hp', 'csrf'))` in
`system/template.php`).

**The page cache.** A cached page (`raster_cache`, an hour by default)
would hand every visitor the same, ever older stamp, so "too fast" could
never fire on a cached page and one stamp could be replayed all hour.
*(Decision 2.)* `raster_cache::store()` writes the stamp's value as a fixed
marker and notes it in the copy's first line; `serve()` puts a fresh stamp
in its place when that note is there (it then reads the copy into memory
instead of `fpassthru`). Pages without forms are served as today.

A static export (`raster export`) leaves the field out: no PHP answers
those forms (the café shows its form only under `print.if.live`).

### `validation::get()->suspect($fields = null)`

A form model calls it after `valid()`, once per post. It returns a list of
reason names, empty when the post looks human, and counts the post for the
rate limits. `$fields` names the text fields to look for links and blocked
words in; by default every posted field the form declares. The email for
the per-address limit is the posted `email` field, when there is one.

| Reason | When |
|---|---|
| `no_stamp` | the post has no `raster_ts` (a script that never fetched the form) |
| `stamp_invalid` | its signature doesn't match (forged, or the key changed) |
| `too_fast` | sooner than `spam_min_seconds` (3) after the stamp |
| `stamp_old` | later than `spam_max_age` seconds (86400) after it: a replayed stamp |
| `too_many_from_ip` | more than `spam_per_ip` (5) posts of this form from this IP in the last hour |
| `too_many_from_email` | more than `spam_per_email` (3) posts of this form with this address in the last hour |
| `too_many_links` | more than `spam_max_links` (2) links in the checked fields |
| `blocked` | a word or domain of `spam_blocklist` in the checked fields or the email's domain |

The checks are lazy: nothing runs for a form whose model doesn't ask, so
GET pages, `/api` (never a form submission: `validation::submitted()` is
false there), MCP and the command line pay nothing.

Editors and admins (a logged in session with that role) get no reasons:
staff testing their own form are never flagged. Members get every check;
their posts already carry the session token (`guard_post()`).

The honeypot stays as it is: a filled `raster_hp` is certain, so the bot
still gets its fake success and nothing is stored or counted.

### Rules

- **A check that can't run passes.** No table (a frozen production
  database before `schema --apply`), no key, a database error: that check
  is skipped and a warning logged (`log::warning('spam: …')`), without the
  IP or the address. A form never stops working because of its spam checks.
- **Counting.** Each post that reaches `suspect()` is counted, suspected or
  not, so a bot hammering the form stays over the limit. Posts that failed
  `valid()` aren't counted (a person fixing a typo). The count is per form
  (`raster_form`), so a newsletter sign-up and a contact message from one
  visitor don't add up.
- **Hashing.** An IP is hashed as `sha256(salt + ip)`; an IPv6 address by
  its /64 prefix (one household or phone holds a whole /64). An email is
  lower-cased and hashed the same way. The salt is 32 random bytes made
  for the day (the site's `timezone`, #84); when the day changes a new one
  is made and the old salt and every count made with it are deleted, so
  nothing stored can be traced back to an IP later. Limits therefore start
  again at midnight; that is the price of forgetting.
- **Expiry.** Counts older than an hour are deleted as new ones are
  written; all of them go with the day's salt.
- **The client's IP.** `REMOTE_ADDR`, unless it is one of
  `trusted_proxies` (addresses or CIDR ranges), in which case the address
  comes from `client_ip_header` (`X-Forwarded-For`: the last entry that
  isn't a trusted proxy; or a single-value header such as `Fly-Client-IP`).
  Raster reads no forwarding header today (`REMOTE_ADDR` appears only in
  `system/config.php`, for the environment). *(Decision 3.)* When posts
  arrive from a private address and nothing is configured, `doctor` says
  so, since every visitor would share one count.
- **Links** are counted as `http://`, `https://`, `www.` and `[url=` in the
  checked fields, case-insensitively. `spam_max_links` false turns the
  check off; 0 allows none.
- **The blocklist** is `spam_blocklist`, a list of words and domains,
  empty by default. A word matches whole and case-insensitively; an entry
  with a dot (`casino.example`) matches that domain and its subdomains in
  links and in the email's domain. *(Decision 7.)*

### What each bundled public form does with a suspect post

*(Decision 4.)*

| Form | Checks | A suspect post |
|---|---|---|
| `render.contact.form` | all | stored with status `spam` and its reasons; not mailed; `contact.flagged`; the visitor sees `sent` like anyone |
| `render.newsletter.signup` | stamp, both limits | nothing stored or mailed; the form again with what was typed and `try_again` (stamp reasons) or `slow_down` (limits) |
| `render.authentication.register` | stamp, both limits | the same |
| `render.authentication.forgot` | both limits | answers `reset_sent` as it does for an unknown address, mails nothing |
| `render.booking.form` | stamp, both limits | the form again with `try_again` / `slow_down` |
| `cms_records::submit` (a site's own record form, `create` visitor or member) | stamp, both limits | the form again with `try_again` / `slow_down` *(Decision 5)* |

Login (its own lockout, and password managers fill and send it in under a
second), reset, account, logout, the booking manage page and newsletter
unsubscribe (a link from an email, or a mail app's one-click post) are
not checked.

A person whose post was refused sees the form again with everything they
typed and a fresh stamp, so sending it again works: nothing is lost there
either. `lint` warns on a page with one of these regions that has no
`print.validation.alert('try_again')`.

A site's own form model (a hand-written `send()`) gets nothing until it
calls `suspect()`; AGENTS.md shows how.

## Part 2: the contact model

### Data

#### `contactmessage` — the messages (a record type)

| Field | Type | Notes |
|---|---|---|
| `name` | text | optional |
| `email` | text | required, valid |
| `phone` | text | optional |
| `subject` | text | optional |
| `message` | text | required |
| `page` | text | the path the form was sent from (`/visit`), filled by the model |
| `status` | text | `new`, `done`, `spam`; readonly |
| `spam` | text | the reasons, comma separated (`too_fast,too_many_links`), or `staff`; readonly |

`created_at` is the record's own. Type settings: `create` visitor, private,
no `owner` (a message is not an account's to read back), no `staff_add`.
Actions for editors: `done`, `spam`, `not_spam`. The slug is never made
from the email (`cms_store::slug_source` falls back to the first field;
`name_invalid` can't be relied on since the name is optional), so the
model's `fill()` gives it the date and time (`2026-10-18-1432`).

A site that needs more fields (an order number, "how did you hear of us")
uses a `the_contact` override whose `types()` adds fields, as booking's
design does (`the_booking`); `render.contact.form`
stores every field the type declares that the form sends
(`cms_records::submit`'s rule).

### Rules (in `check()` and `form()`)

- **Header-safe fields.** `name`, `email`, `phone` and `subject` with a
  CR, LF, NUL or other control character are refused (`name_invalid`,
  `email_invalid`, `phone_invalid`, `subject_invalid`), not quietly
  cleaned: whoever sends a newline in a name is not a person. `email` must
  pass `FILTER_VALIDATE_EMAIL` and be at most 254 characters.
- **Lengths**, whatever the HTML says: `name`, `phone` up to 200,
  `subject` up to 200, `message` up to `contact_max_length` (5000)
  characters (`message_invalid` when empty or longer; `name_invalid` and
  so on for the others).
- **A double click** (the same email and message within 10 minutes) stores
  nothing new and answers `sent`.
- **Spam** is `suspect()`'s reasons. A suspected message is stored as
  `spam` with its reasons, and the visitor gets the same `sent` as anyone,
  so a bot learns nothing.
- **Status.** `new` → `done` (staff answered it), and `spam` ⇄ `new`:
  `spam` on a message staff judge to be spam (reason `staff`), `not_spam`
  on a flagged one (its reasons are cleared). `not_spam` doesn't mail
  staff, who are looking at it, but sends `contact.received`, so a
  listener sees every real message once *(Decision 6)*.
- **Retention.** Messages with status `spam` older than `contact_spam_days`
  (30) are deleted when a new message is stored (no scheduler needed).
  Real messages stay until staff delete them. *(Decision 8.)*

### Emails

Sent after the answer (G4, `mail::send_kept()`), only for messages that
aren't suspected. `lint` reports a theme with `render.contact.form` and no
view (`requires()['emails']`).

| View | To | When |
|---|---|---|
| `_email/contact_received` | staff | a message arrived and isn't suspected |

- To `contact_staff_email`, else the sender (`RASTER_MAIL_FROM`,
  `mail_from`), as booking's staff emails do; with neither, a warning is
  logged and the message is still stored.
- `From` stays the site's sender (SPF and DMARC pass). `Reply-To` is the
  visitor: the validated address, with the name (control characters are
  already refused; `"`, `<`, `>` and `,` are dropped from it) encoded by
  mail's own `named()`. It goes through `mail::send_view()`'s `$headers`,
  and `mail::send()` already strips CR and LF from every header
  (`clean_header()`) and encodes the subject; the model's refusal above is
  the first line, those are the second.
- The subject is the view's `<title>`. The site may print the visitor's
  `subject` there; it is escaped and its newlines can't survive.
- The view gets the record's fields, `id`, `created_at`, `page_url` (the
  absolute link to `page`), `staff_url` (`contact_staff_page`) and
  `site_url`. `print.self` escapes them in HTML views
  (`template.php:558`).
- A mail that fails leaves the message stored and `status` `new`: the
  staff page is the inbox that never loses one. The failure is logged
  without the address (`mail.failed` still says it).
- Nothing is ever mailed to the visitor (see the anti-goals).

### Events (after the commit)

| Event | Payload |
|---|---|
| `contact.received` | the message's fields and `who` (`visitor`, or `staff` after `not_spam`) |
| `contact.flagged` | the same, with `spam` (the reasons): for a site that wants to log or count them |

The staff email is the framework's listener on `contact.received`
(`system/config/events.php`, bound only for the bundled model and only
where a view uses it, as booking's are). A site that sends its own
unbinds it: `event::unbind('contact.received')->from('contact', 'staff_mail')`.
The payload never holds an IP or its hash.

### Annotations

| Annotation | What it does |
|---|---|
| `render.contact.form` | the form handler: fields `name email phone subject message` (and any a `the_contact` override adds); on success redirects with `?done=sent`, or shows the form again with the alerts above |
| `render.contact.messages('status=new')` | staff: messages through `cms_records::listed`, editable in place with their actions; options as `cms_records::find` takes them. Nothing for anyone else |
| `print.contact.count('status=new')` | how many, for the staff page's heading (staff only; `''` otherwise) |

The staff page follows the Records recipe, as booking's does:
`render.cms.contactmessage('status=new&order=-created_at')` works as well;
`render.contact.messages` is the same list with the actions shown, so the
recipe in AGENTS.md uses it. AGENTS.md gets a worked staff page (new,
done, spam), and `contact_staff_page` (`staff`) is what `staff_url` points
to. `lint` warns when `contact_staff_page` is not protected for editors.

### Settings (all optional)

| Setting | Default | |
|---|---|---|
| `contact_staff_email` | `mail_from` | where staff emails go |
| `contact_staff_page` | `staff` | the page with the messages, for `staff_url` |
| `contact_max_length` | 5000 | characters in a message |
| `contact_spam_days` | 30 | days a message flagged as spam is kept |
| `spam_min_seconds` | 3 | fastest a person fills a form |
| `spam_max_age` | 86400 | seconds a stamp stays good |
| `spam_per_ip` | 5 | posts of one form per hour from one IP |
| `spam_per_email` | 3 | posts of one form per hour with one address |
| `spam_max_links` | 2 | links in the checked fields; false turns it off |
| `spam_blocklist` | `array()` | words and domains |
| `trusted_proxies` | `array()` | addresses or CIDR ranges in front of the site |
| `client_ip_header` | `X-Forwarded-For` | read only from a trusted proxy |

`describe` lists them (`requires()['settings']`). Any `spam_*` set to false
turns that check off.

### Settings and the key

The stamp needs a key nobody outside the site knows, and Raster has none
(booking's links are random for that reason, `booking.php:439`).
*(Decision 1.)* `RASTER_SECRET` when set (hosts with several machines,
read-only disks); otherwise 32 random bytes written on first use to
`<app>/data/secret` (mode 0600; `data/` is never served,
`private_paths::$private_app_folders`). `describe` and MCP never show it
(`describe.php` already hides anything that could hold a secret). Losing or
changing it only makes the forms open at that moment `stamp_invalid`:
contact keeps those messages as spam, the other forms ask once more.

### Tables

The counts are not records. The validation model declares two tables in
`schema()`, so `schema --apply` makes them for a frozen database, and
makes them on first use otherwise (as newsletter does):

- `formhit`: `form`, `kind` (`ip` or `email`), `hash`, `at`; one index on
  `form, kind, hash, at`.
- `formsalt`: `day`, `salt`; one row.

Kept in the database, not in `data/`, so sites served by several machines
share one count. The queries are files in `system/models/validation/sql/`.

## Our own examples move onto it

- The café: `cafe::contact()` is removed; `visit.html`'s form
  becomes `render.contact.form` (its legacy `not_empty`/`email_format`
  regions stay in it, so D14 keeps its test; the `website` URL field stays
  as a validated field the type doesn't store); `_email/contact.html`
  becomes `_email/contact_received.html`; `cafe_staff_email` gives way to
  `contact_staff_email`; `/staff` gets a Messages section built from the
  recipe. (Booking's B4 has landed and moved the form to `cafe`; this is
  the move.)
- AGENTS.md **Forms**, the wiki's Forms and Validation and Static Export
  pages: the worked example is no longer a `contact` model. It becomes a
  `feedback` form (a rating and a comment, stored, nothing mailed) that
  shows `valid()`, `suspect()` and `util::done()`. *(Decision 9.)*
- The tutorial's step 7 uses `render.contact.form` and its staff email,
  and says in two lines how to write your own form model.
- Sites outside this repository with their own `contact` model keep it,
  untouched (above). The 2.2.x upgrade step adds nothing to them; its
  notes say how to move.

## Tests

A feature ID group in `demo/README.md` (the next free letter after
booking's P: **Q**), tests in `tests/demo.php` for the café's form and staff
page, and in `tests/run.php` for the checks:

- the stamp: added to every post form, skipped by `form_state`, missing,
  forged, too fast, too old; a cached page served twice gets two fresh
  stamps; an export has none;
- limits per IP and per address, per form; IPv6 by /64; a trusted proxy's
  header read, an untrusted one ignored; the salt and the counts gone the
  next day; no raw IP in the database, the log or an event;
- links and the blocklist, words whole, domains with subdomains;
- a check that can't run (no table, frozen database, no key) passes and
  logs;
- contact: stored and mailed once after the answer; suspected stored as
  spam and not mailed; a newline in name, email, phone or subject refused;
  `Reply-To` encoded, never a second header; lengths; a double click; the
  actions; `not_spam` sends `contact.received` and no mail; spam older than
  `contact_spam_days` deleted; a site's own `contact` model still answers
  `render.contact.send` and the bundled one loads nothing;
- newsletter, register, forgot, booking and `cms_records::submit` with a
  suspect post: nothing stored or mailed, the form again with what was
  typed; editors never flagged.

Existing tests that post forms without fetching them (`tests/demo.php`
around the `/visit` posts, and others) take the stamp from the page as a
browser would; a helper does it. All on SQLite and MySQL.

## Depends on

Everything Phase 1 merged: one model lookup and the shadowing rule
(#178), bundled `sql/` files (#181), bundled models declaring types,
tables, required emails and settings (#180), the site `timezone` and mail
after the answer (#179), and `fill()` / `writer()` from booking's B1–B2.
Nothing new is needed from Phase 1 before this starts.

## Left to the builder

- Internal method names, the SQL, and how `suspect()` caches its answer
  within a request.
- The stamp's exact format and the cache marker, within the rules above.
- How `doctor` detects a site behind an unconfigured proxy.
- The exact AGENTS.md wording, the worked staff page and the `feedback`
  example.
- The demo's words and design.

## Decisions

*(Decided 2026-10-19: every recommendation below is taken. The
alternatives are kept for the record.)*


1. **A site key.** The stamp must be signed, and Raster has no secret.
   Recommend `RASTER_SECRET`, else 32 random bytes made on first use in
   `<app>/data/secret`. The alternative, keeping every issued stamp in the
   database, writes a row for every page view with a form, cached or not.
2. **Stamps on cached pages.** Recommend the cache writes a marker and
   `serve()` puts a fresh stamp in (pages with forms are then read into
   memory, not streamed). Alternatives: never cache a page with a public
   form (most sites have a newsletter form in the footer, so nearly nothing
   would be cached), or accept that "too fast" doesn't work on cached
   pages.
3. **The client's IP behind a proxy.** Recommend `trusted_proxies` and
   `client_ip_header`, empty by default, plus a `doctor` notice. Hosts
   such as raster-builder's router must then send the visitor's address,
   and their sites set the two settings. The alternative is to trust
   `X-Forwarded-For` always, which lets any bot pick a new IP per post.
4. **What the other bundled forms do with a suspect post.** Recommend the
   table above: only contact stores and flags; the newsletter, sign-up and
   booking forms show the form again with `try_again` / `slow_down`
   (nothing lost, nothing mailed); forgot answers as for an unknown
   address. The alternative, storing a flagged subscriber, account or
   booking, needs a flag on each, and a flagged booking would hold a place.
5. **Sites' own record forms.** Recommend `cms_records::submit` runs the
   stamp and limits for visitor- and member-made types, so every site's
   record form is covered without code. A site whose scripts post to its
   own forms without fetching them then gets `try_again`; a type setting
   (`'spam' => false`) turns it off. The alternative is opt-in only
   (`suspect()` in model code).
6. **`not_spam`.** Recommend it sends `contact.received` (`who` staff) and
   no email. The alternatives: send the staff email too, or send nothing.
7. **The blocklist.** Recommend a setting (`spam_blocklist`) now. The
   alternative is a record type staff edit in the page (`spamword`), which
   is more to build and puts the list in Admin.
8. **Keeping messages.** Recommend spam deleted after 30 days and real
   messages kept until staff delete them. The alternative is a limit on
   real messages too (`contact_keep_days`), for sites that want one.
9. **The AGENTS.md / wiki forms example.** Recommend a `feedback` model
   (stored, not mailed), so the docs stop teaching a hand-written contact
   form while still teaching how a form model works, and the tutorial
   moving onto `render.contact.form`.
