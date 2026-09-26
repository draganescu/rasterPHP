# Raster Café: the demo that uses everything

A small café site built with Raster, where every framework feature does a
real job. `tests/demo.php` drives it over HTTP, the command line, MCP and a
fake SMTP server, and fails if any feature below has no passing test.

```sh
RASTER_APP=demo php bin/raster serve      # http://localhost:8000
RASTER_APP=demo php bin/raster user you@example.com --role=editor
php tests/demo.php                        # the whole matrix
```

| Where | What it shows |
|---|---|
| `/` | page fields, featured dishes (a filtered, limited collection), upcoming events |
| `/menu`, `/menu/menu_item/<slug>` | a collection with pagination, category filter links, counts from an SQL file |
| `/events`, `/journal` | ordering, drafts, scheduled items, author filter links |
| `/visit` | a booking form using every validation rule, a contact form, the newsletter form: three forms on one page |
| `/members`, `/staff`, `/account`, `/login`, `/register`, `/forgot`, `/password/new` | accounts, roles and protected pages |
| `/lab` | the engine's edge cases, one section each |
| `/journal.rss`, `/journal.atom`, `/feed.json`, `/sitemap.xml`, `/hours.txt` | formats |
| `?lang=ro` | the Romanian translation |

## Feature matrix

| ID | Feature |
|---|---|
| A1 | `/` renders index.html |
| A2 | `/about` renders about.html |
| A3 | nested views: `/docs/setup` |
| A4 | `/index` is the home page |
| A5 | routes file, anchored patterns |
| A6 | key/value URL parameters |
| A7 | unknown URLs are 404 |
| A8 | partials and `_email/` are never pages |
| A9 | `.html` links rewritten, `index.html` becomes `/` |
| A10 | theme assets served |
| A11 | code, config, data, raw views and tooling are 403 |
| A12 | query strings don't change the route |
| A13 | a custom 404 page (`error_document_404`) |
| A14 | a route to a view in another theme (`->from('print')`) |
| A15 | `rewrite` off: every link goes through index.php |
| B1 | RSS: content type, well-formed, escaped |
| B2 | Atom |
| B3 | JSON views: rows become a list |
| B4 | sitemap.xml |
| B5 | text views |
| B6 | links to feed views rewritten |
| B7 | `.txt` links rewritten |
| B8 | `feed_limit` |
| C1 | print with a default and with a model value |
| C2 | self-closing print |
| C3 | render repeats rows |
| C4 | an empty array renders nothing |
| C5 | false keeps the mock-up (render and print) |
| C6 | remove runs before models |
| C7 | res and dry partials |
| C8 | print.if |
| C9 | print.self |
| C10 | print.session |
| C11 | attributes: @ sets, + appends |
| C12 | rows with lists of rows |
| C13 | the same self-closing tag several times |
| C14 | literal arguments, including negative numbers and null |
| C15 | HTML printed as is; util::e escapes |
| C16 | template::replace, scoped to a path |
| C17 | rendering into memory (`__`) and reading it back |
| C18 | model calls inside a repeated block fill every copy |
| C19 | inner blocks are evaluated first |
| C20 | SQL files in `models/<name>/sql/` |
| C21 | bound query parameters |
| C22 | application bindings in `config/the_events.php` |
| C23 | models load each other on first use |
| C24 | the JSON api; system and static methods private |
| C25 | development shows template errors, partials included |
| C26 | `route_not_found` event |
| C27 | values in scripts: `/*- print.x /-*/` |
| C28 | a dry block with a placeholder |
| C29 | attributes are escaped; false removes the attribute; a false value keeps the mock-up |
| C30 | a render method returning a string |
| C31 | named queries in `models/sql.php`, placeholders quoted |
| C32 | an application binding to a core event (`done`) |
| C33 | `loading_model_<name>` returning false stops the model |
| C34 | events carry a payload; a listener returning false; event::unbind |
| C35 | `the_<model>` overrides a bundled model |
| C36 | `log::enable()` prints the log to the browser console |
| C37 | `strict_templates` off renders broken templates anyway |
| C38 | a model sends an event and another listens (`listens()`): a booking subscribes the guest |
| C39 | MCP site_overview lists who listens to what |
| C40 | `executed_<model>_<method>` uses the model's own name (also under a `the_` override) and carries the result |
| C41 | bundled models send events from every path: cms over MCP, authentication.registered |
| C42 | lint checks event bindings: missing models and methods, events nothing sends |
| C43 | named queries: `:name` placeholders, the calling model's `sql/` by default, a missing name is an error that lint finds |
| C44 | `print.@attr.model.method` outside render blocks sets an attribute from a model |
| D1 | raster_form and honeypot on every post form |
| D2 | the session token on forms for logged in users |
| D3 | posts from other sites refused (Origin, Sec-Fetch-Site, Referer, /api too) |
| D4 | honeypot: fake success, nothing done |
| D5 | required |
| D6 | type="email" |
| D7 | minlength and maxlength |
| D8 | number with min and max |
| D9 | date with min and max |
| D10 | pattern |
| D11 | cant_be |
| D12 | accepted |
| D13 | an application rule (`models/validation/rules/`) |
| D14 | not_empty and email_format from older Raster |
| D15 | matches |
| D16 | form_state fills inputs, selects, radios, checkboxes, textareas, never passwords |
| D17 | success redirects with ?done= and shows the alert |
| D18 | alerts are hidden until raised |
| D19 | several forms on one page are validated separately |
| D20 | form_state with data, spa_ classes |
| D21 | /api never runs form models |
| D22 | logged in posts without the token are refused |
| D23 | type="url" |
| D24 | `field('guests', 'max')` shows only when that rule fails |
| D25 | validation::errors() lists what failed |
| E1 | page fields with defaults from the markup |
| E2 | site_ fields shared by every page |
| E3 | collections seeded from the mock-up |
| E4 | slugs (with accents), items by slug and by id |
| E5 | missing items are 404, and only under their own collection |
| E6 | raster_detail_link |
| E7 | raster_filter links and `_items` filter URLs |
| E8 | `_page` pagination with pagination.links and pages |
| E9 | order |
| E10 | limit |
| E11 | filters in the template |
| E12 | drafts hidden from visitors, shown to editors |
| E13 | scheduled items appear on time |
| E14 | page revisions |
| E15 | an empty value shows the default |
| E16 | a new annotation becomes a column (development) |
| E17 | the in-page editor only for editors; visitors get the plain page |
| E18 | the editor saves page fields, site-wide ones included, as revisions |
| E19 | the editor adds, changes, hides and deletes items |
| E20 | editor endpoints need an editor and the session token |
| E21 | picture upload: only real images, new file names |
| E22 | reserved names are lint errors |
| E23 | `raster_page_size` for collections without their own |
| E24 | order by `-field` and `oldest`; pagination follows the filter argument |
| E25 | item pages fall back to the collection view |
| E26 | the built-in editor login page, toolbar assets, logout by POST with the token |
| E27 | editor marks: fields, attribute fields, items, lists with their mock-up; fields out of reach (in <head>) listed as hidden |
| E28 | page history and restoring a revision from the editor |
| E29 | photos: page and item image fields; an empty value keeps the template's picture |
| F1 | schema status as JSON |
| F2 | schema --check |
| F3 | schema --apply in production, including model tables |
| F4 | rename suggestion and --rename |
| F5 | --drop refuses used columns; drops unused tables |
| F6 | frozen database: templates ahead of it show defaults |
| F7 | `--drop --force` |
| G1 | sign up |
| G2 | email already taken |
| G3 | password rules |
| G4 | log in and failed log in |
| G5 | ?next= and open redirects |
| G6 | protected pages send visitors to log in |
| G7 | editor-only pages refuse members |
| G8 | if.logged_in, if.is_editor and friends |
| G9 | authentication.me, escaped |
| G10 | account changes need the current password |
| G11 | log out |
| G12 | forgot and reset by email; tokens work once |
| G13 | a new password ends other sessions |
| G14 | five wrong passwords lock the account |
| G15 | registration can be turned off |
| G16 | raster user and raster users, roles |
| G17 | md5 accounts and the old usersdata table |
| G18 | visitors get no cookies |
| G19 | `login_page` |
| G20 | log in with a username |
| G21 | `raster user` defaults: admin, random password |
| H1 | newsletter sign up sends a confirmation |
| H2 | the same answer for people already subscribed |
| H3 | confirm |
| H4 | invalid confirmation links |
| H5 | newsletter.count |
| H6 | raster send: RASTER_URL, --dry-run, --to, --subject, once, --again |
| H7 | List-Unsubscribe and one-click unsubscribe |
| H8 | the unsubscribe page |
| H9 | issues without forms, scripts or nav, absolute links, one link per reader |
| H10 | single opt-in |
| H11 | `newsletter_confirm_page`, and the name is stored |
| H12 | `raster send` refuses a page without a title |
| I1 | emails are views; subject from the title; text version |
| I2 | values escaped in emails; images absolute |
| I3 | SMTP: AUTH, sender, recipient |
| I4 | SMTP refuses plain text to other hosts unless ?insecure=1 |
| I5 | production without RASTER_URL sends no links |
| I6 | `log://` defaults to `data/mail/` |
| I7 | `mail://` uses PHP's mail() |
| I8 | STARTTLS before the password; smtps |
| I9 | the sender from config `mail_from` |
| J1 | the template's language is the default |
| J2 | ?lang= translates and sets a cookie |
| J3 | the cookie remembers |
| J4 | Accept-Language |
| J5 | the language switcher |
| J6 | one cached copy per language |
| J7 | `domain_language` |
| J8 | `language_cookie` |
| K1 | a model that paginates itself (?page=) |
| L1 | servers.php: whole names, loopback only from this machine |
| L2 | RASTER_ENV |
| L3 | page cache: hit, miss, query strings, logged in, cleared by edits |
| L4 | links use RASTER_URL, not the Host header |
| L5 | the protocol is part of the cache key |
| L6 | `site_url` in config |
| L7 | `page_cache_skip`, `page_cache_ttl`, `page_cache` off |
| M1 | MCP needs its token; GET is refused |
| M2 | initialize, ping, tools/list, batches, errors |
| M3 | notifications get 202 |
| M4 | every tool |
| M5 | unknown fields and pages are errors |
| M6 | MCP over stdio |
| M7 | invalid requests; unknown protocol versions get the newest |
| M8 | slug and enabled are writable on items |
| M9 | `mcp_token` in config |
| N1 | raster help and unknown commands |
| N2 | raster lint and --json, --all-themes |
| N3 | raster render and its exit codes |
| N4 | raster serve |
| N5 | `raster export`: pages, items, lists, feeds, the 404 page, assets and every language as static files; what needs PHP is reported |
| O1 | the sitemap skips private pages and lists items |
