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
| `/members`, `/staff`, `/account`, `/login`, `/register`, `/forgot`, `/password/new` | accounts, roles and protected pages; bookings are records the staff page lists and members see on /account |
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
| A16 | the private extensions are 403, `composer.phar` too (system/private_paths.php) |
| A17 | theme files of every kind (svg, webp, woff2, ico, pdf) are served; the root `.htaccess` is the only one |
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
| C45 | a short closing tag (`<!-- /render -->`) is a lint error that names the full closing tag |
| C46 | lint checks how many arguments a method takes, and names the nearest real method |
| C47 | a relative link inside a `print.validation.alert()` block goes to the route, not the view file |
| C48 | /api answers only what a model lists in `api()`, for the roles it names; a model that lists nothing offers nothing; the vocabulary shows each list, cms's included; overrides are never addressed as `the_<model>`; lint checks `api()` |
| C49 | in production (strict templates off) any exception (a missing named query too) shows the plain error page and goes to the error log; `log::warning` and `log::error` always reach the error log |
| C50 | an /api method that throws answers JSON: 500 `{"error":"server error"}` logged with the URL (a bad query too), 503 when the database is down, 400 for missing arguments; development adds the trace |
| C51 | `cms` offers over /api only what its `api()` lists (the editor endpoints, `style`, `logout`); a public method of a `the_cms` override answers 404 unless listed |
| C52 | a link a visitor typed can't run script, even with a tab, newline, control byte or HTML entity hiding `javascript:`, `data:` or `vbscript:` |
| C53 | a `print.model.method` or `print.if.flag` inside a render block fills every row of a list of any length (no limit at 1,000 rows), so a staff-only block stays hidden in all of them |
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
| D26 | a form with `method="get"` shows what the URL asked (the staff page's filters) |
| D27 | a form shown again keeps what was typed exactly, `$100`, `\1` and `$0` included |
| D28 | a field sent as a list (`name[]`) when the form doesn't name it so, or with bytes that aren't UTF-8, fails `required`; a field named `tags[]` takes a list |
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
| E30 | admin pages: views `protected` keeps for editors or admins are listed in the editor's Admin menu, by title, for whoever may open them; `describe` lists them |
| E31 | fields named like SQL words (`when`, `from`, `group`) sort, filter (`group>2`) and link to their filter pages (`/trips/trips_items/from/Paris`) like any other field |
| E32 | the in-page editor gets stored text as it is: `$5`, `\1` and backslashes, so Duplicate copies it unchanged |
| E33 | on SQLite, items saved at once with one title each get their own slug, and page saves at once (one field each) keep each other's changes: item and page saves run in a transaction |
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
| G21 | `raster user` defaults for a new account: admin, random password |
| G22 | `raster user` on an existing account keeps the role and password it isn't given |
| G23 | once a lock runs out, wrong passwords are counted from zero |
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
| L8 | `raster cache clear` and MCP `clear_cache` for changes made outside Raster; `describe` says whether the cache is on |
| L9 | page cache: empty filter pages, pages past the last one, unknown filter fields and other spellings of a list's URLs (`journal_page/1`, a segment left over) answer 200 but aren't kept; a content change deletes the old cached pages |
| L10 | page cache: links with only tracking parameters (`utm_*`, `fbclid`, `gclid`, `msclkid`) are cache hits, and their values never reach the cached page |
| L11 | a database that can't be reached answers 503 outside development (`error_document_503`, views/cafe/503.html; `/api` as JSON, before the method runs), logged, never cached |
| L12 | the page cache is thrown away and `content_changed` sent once a transaction commits, and not at all when it is rolled back |
| M1 | MCP needs its token; GET is refused |
| M2 | initialize, ping, tools/list, batches, errors |
| M3 | notifications get 202 |
| M4 | every tool |
| M5 | unknown fields and pages are errors |
| M6 | MCP over stdio |
| M7 | invalid requests; unknown protocol versions get the newest |
| M8 | slug and enabled are writable on items |
| M9 | `mcp_token` in config |
| M10 | `describe`: the whole site in one call, by section, with no secrets in the settings |
| M11 | `vocabulary`: models, methods with their signatures, SQL queries, events, reserved names |
| M12 | `annotations`: the grammar over MCP, the same data lint checks against |
| M13 | `list_views` and `read_view`, and the paths they refuse |
| M14 | `check_view` lints a draft and writes nothing |
| M15 | `write_view` refuses markup that does not lint, writes markup that does, and is off over HTTP |
| M16 | `render_url` renders a page without a web server, in its own process |
| M17 | `render_url` with `as`: the page as an editor, with what the in-page editor marks |
| M18 | a PHP error in site code answers the MCP call over stdio with its file and line, and the server goes on |
| M19 | the view tools take `theme` only as a folder directly under `views/`; a path or a link out of `views/` is refused |
| N1 | raster help and unknown commands |
| N2 | raster lint and --json, --all-themes |
| N3 | raster render and its exit codes |
| N4 | raster serve |
| N5 | raster annotations, and --json: the grammar as data |
| N6 | raster lint --fix repairs spacing and short closing tags, and leaves typos and the rest |
| N7 | raster deploy --config=apache\|nginx\|caddy |
| N8 | raster doctor: .htaccess rules, `allow_deprecated` |
| N9 | `raster export`: pages, items, lists, feeds, the 404 page, assets and every language as static files; what needs PHP is reported |
| N10 | `print.if.live` / `print.if.static`: forms on the live site, what shows instead in the export; an unwrapped form stops the export |
| N11 | exporting again writes only files that changed, removes what the site no longer has, and does nothing when nothing changed |
| N12 | raster vocabulary, and --json |
| N13 | raster describe, and --sections |
| N14 | `raster render <url> --as=<account or role>`, and a query string in the URL |
| N15 | `raster upgrade` takes an app with no `config/raster-version` as current: writes the version, runs no old steps |
| O1 | the sitemap skips private pages and lists items |
| R1 | a model declares a type (`types()`): a collection with the model's fields and no mock-up row, in site_overview, describe and schema |
| R2 | `cms_records::submit`: a form stores a record with only the fields people may write; what visitors typed prints as text |
| R3 | `check()` runs on every write: the form (an alert), the editor (422 with the problems), MCP and the model's own code |
| R4 | records are private unless the type says `public`: no pages, feeds, sitemap or item URLs for visitors; editors see them all |
| R5 | `owner`: a record remembers who made it, and they read their own; `owner=me` lists only the logged in person's (/account), editors included |
| R6 | `readonly` fields: shown to editors, not editable, refused from the editor and MCP, written by the model |
| R7 | `hidden` fields: never shown to the editor or MCP |
| R8 | actions: buttons for the roles allowed, `editor_action`, MCP `run_action`, refusals; hooks are static so /api can't reach them |
| R9 | `cms_records::transaction`: every write or none, and events wait for the commit |
| R10 | list fields: stored as JSON, back as lists, rendered as nested rows |
| R11 | lint checks types: static check(), a method for each action, keys that mean nothing |
| R12 | `schema --apply` creates record tables in production, with every column and no row |
| R13 | `raster make model <name> --from=<view>`: a model for the records a form sends |
| R14 | a staff page lists records by status (`status=new&order=date`), and filter addresses (`/reservation/reservation_items/date/…`) show one evening, for staff only |
| R15 | list options: filters from the URL (`seating=?seating`), dates (`date>=today`, `date<today`), `!=`, and `order=date,name`; pagination keeps the query |
| R16 | `staff_add`: staff add bookings from the card at the end of a list; a new item starts with the list's filters; no card on an item's page |
| R17 | lint warns about records a form makes that no view lists, and about admin pages listing records a model reads itself |
| R18 | `cms_records::listed()`: a model's agenda of bookings by evening, nested in its rows, is edited in place with the record's actions; values the model adds are not editable |
| R19 | a record's own page (`/reservation/reservation_item/<slug>`) is where editors edit a record a model view links to; visitors get a 404 |
| T1 | a template field is of its mock-up's type (`14.50` a number, `2026-10-10` a date, `19:00` a time), a record field of its default's (`0` an int, `false` a bool) or the one `types` names; `schema`, `describe` and `site_overview` say so |
| T2 | values are stored as their type whoever writes them (`12 Dec 2026` is `2026-12-12`, `8pm` is `20:00`), or refused with the field and an example |
| T3 | lists filter and sort by type: `order=-price` puts 100 above 18 above 9.50, `price>=10` compares numbers, `order=date,starts` dates and times |
| T4 | models, MCP and /api read ints, floats and bools, so the reservation model has no casts |
| T5 | templates print text: a number with as many decimals as its mock-up, nothing for an empty value; a form value of the wrong type raises `<field>_invalid` |
| T6 | `schema --apply` converts a column whose type changed when every value fits, and names the values that don't |
| T7 | a time prints `19:00` whatever the database gives back (MySQL's `19:00:00`); lists hide drafts and scheduled items and sort by `newest` whether `published_at` is a typed column or still text from before 2.1.7 (only a text one is compared with `''`, which MySQL refuses for a date) |
