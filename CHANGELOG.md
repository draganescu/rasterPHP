# Changelog

Every release lists what sites need to do. `php bin/raster update` does the
file changes for you; `php bin/raster doctor` shows what is left.

## 2.1.8

Run `php bin/raster update`; `php bin/raster doctor` shows what is left.
Things to check on a site:

- If a model's methods should answer at `/api`, list them in its
  `static function api()`: the `api_open` setting is gone.
- Delete `application/.htaccess` if your site has one (`raster update`
  removes `system/.htaccess`).
- In production, turn `display_errors` off in php.ini; `doctor` warns
  when it is on.

The double-submit fixes below hold on SQLite. MySQL and unique indexes
come later (#78, #129).

- **/api offers only what a model lists, bundled models included.**
  `cms` now lists its own endpoints in `api()`: the in-page editor's
  endpoints, `style` and `logout`. These still check the caller
  themselves. `/api/cms/setup`, `route`, `inject_toolbar`, `login` and
  `login_message` now answer 404. A public method added in a `the_cms`
  override also answers 404 unless the override lists it in its own
  `api()`, which adds to what `cms` lists. `raster vocabulary` shows
  what `cms` offers. (#32)
- **The `api_open` setting is gone.** It kept every public method of
  models without `api()` reachable by anyone at `/api`. Setting it now
  does nothing, `raster upgrade` no longer writes it, and `doctor` no
  longer reports it. If a model's methods should answer at `/api`, list
  them in `static function api()`. (#24)
- **An /api method that fails answers JSON, not a stack trace.** A
  method that throws answers `{"error":"server error"}` with status 500,
  and the error goes to the error log with the URL, so a payment
  provider sees a failure and sends the webhook again. Anything the
  method printed before it threw is dropped. A call missing an argument
  the method needs gets 400. In development the answer also has
  `exception` and `trace`. The in-page editor's endpoints also log
  unexpected errors and answer 500. (#74)
- **PHP errors no longer reach visitors outside development.** Raster
  turns `display_errors` off and `log_errors` on for every request
  outside development, whatever php.ini says. The command line keeps
  showing errors. The plain 404 page is now printed after the session
  starts, so a visitor with a session cookie no longer sees
  `session_start()` warnings. `raster doctor` warns in production when
  php.ini has `display_errors` on, because an error before Raster starts
  would still show. Set it off there too. (#74)
- **A database outage answers 503 instead of the mock-up.** When the
  database can't be reached (MySQL down, an SQLite file the web server
  can't read), pages outside development answer 503 instead of showing
  the template's mock-up content with a 200. `/api` answers
  `{"error":"database unavailable"}` with 503 before the method runs,
  whatever role the method needs, so a webhook is never told "no such
  order" and a logged-in caller is never told to log in. The error is
  logged and nothing is cached, so the real pages are back as soon as
  the database is. To show your own page, add a view that uses no model
  needing the database and set
  `config::set('error_document_503')->to('503')`. Development shows the
  error instead, on pages and on `/api`. A table that doesn't exist yet
  still shows the template default, as before. (#65)
- **The MCP view tools stay inside the views folder.** `list_views`,
  `read_view`, `check_view` and `write_view` accept a `theme` only when
  it is the name of a folder directly under `views/` (letters, digits,
  `_` and `-`). A path such as `../..`, a folder inside a theme, or a
  theme folder that links out of `views/` is refused, and the error
  lists the themes there. An agent holding the MCP token can no longer
  read files elsewhere on the server, or write a page into `media/`.
  Leaving `theme` out still means the site's theme. (#72)
- **Text with `$` or `\` in it stays as written.** Prices like `$5` and
  paths like `C:\new` used to lose characters in two places: what the
  in-page editor showed (and Duplicate then copied), and form fields
  refilled after a failed rule. A value with `\"` in it could stop the
  editor from loading on that page. These values are now kept exactly as
  entered, including in an attribute Raster adds to a tag. (#66)
- **A link a visitor typed can't hide `javascript:`.** A tab, newline,
  control byte or HTML entity inside the scheme no longer gets a
  `javascript:`, `data:` or `vbscript:` link past the filter on
  `print.@href` of a record field. That includes entities for control
  characters such as `&#1;` and `&#13;`. The in-page editor's clean-up
  of links that editors type uses the same rule and now also blocks
  `vbscript:`. (#67)
- **Lists and bad bytes no longer skip form rules.** A field sent as
  `name[]=x` when the form doesn't name it `name[]`, or with bytes that
  aren't UTF-8, now fails `required`, so the form shows its message for
  an empty field and nothing is stored. A field the form does name
  `tags[]` still takes a list, and a `pattern` that can't run counts as
  not matched. (#70)
- **The in-page editor's endpoints refuse a list where they take one
  value.** A post with `id[]=…`, `value[]=…`, `type[]=…` and the like
  now answers 400 and changes nothing. Before, `id[]=99` on
  `editor_delete_item` deleted item 1, and `value[]=x` stored the text
  `Array`. (#139)
- **A slug given as a list is refused** like any other field instead of
  being stored as `array`, from the editor and from MCP alike. (#139)
- **Resetting a password from the command line no longer makes the
  account an admin.** For an account that already exists, `raster user
  <email> --password=…` keeps the account's role, and `--role=…` alone
  keeps its password instead of setting a new random one. The command
  says what it kept. A new account is still an admin with a random
  password unless `--role` or `--password` say otherwise. (#71)
- **After a lock runs out, wrong passwords are counted from zero
  again.** Once 15 minutes have passed since the last wrong password, it
  takes five new wrong ones to lock the account again. Before, one wrong
  guess after the first lock locked it for another 15 minutes, and the
  right password was refused too. (#73)
- **Cached pages are never served cut off or stale.** A cached page and
  the cache version are now written to a temporary file and renamed into
  place, so a visitor arriving mid-write gets the old page or the new
  one. The version is a random value read once when the request starts.
  A page whose render overlapped a content change is not kept, so it
  can't be served as a hit afterwards. (#68)
- **A content change deletes the old cached pages.** Before, the files
  stayed in `data/cache/` forever after every change; now only pages for
  the current content are on disk, and temporary files left by a request
  that died are swept after an hour. (#56)
- **Made-up list URLs no longer fill the page cache.** A filter page
  with no items (`/news/news_items/tag/nothing`), a page past the last
  one (`/news/news_page/999`), a filter on a field the list doesn't
  have, a list or item URL spelled other than the way its links spell it
  (`/news/news_page/02`, `/news/news_item/007`) and a typed filter
  spelled other than the way the page prints it
  (`/menu/menu_items/price/14.500`, `/events/events_items/date/10 Oct
  2026`) still answer 200, but are not cached. A model that knows its
  page shouldn't be kept can call `raster_cache::skip()`. (#56)
- **Links with tracking parameters are cache hits.**
  `/about?utm_source=newsletter` (any `utm_*`, and `fbclid`, `gclid`,
  `msclkid`) is served the cached `/about`, and is built without those
  values, so they never end up in the page other visitors get. Any other
  query parameter still skips the cache. (#56)
- **Cached list pages stay cached when another list on the page doesn't
  print the filtered field.** The site's own number filter links
  (`/menu/menu_items/price/3.50`) take their spelling from the
  collection's mock-up, so a sidebar list of names no longer keeps them
  out of the cache. In production, a collection's filter and page URLs
  aren't cached until `schema --apply` has made its table. (#56)
- **The MCP view tools stay inside the theme, links included.**
  `read_view`, `check_view` and `write_view` used to follow a view file
  that is a link to somewhere else on the server, reading it or
  overwriting it. A view file whose link leads out of the theme, or
  leads nowhere, is now refused, and `list_views` leaves it out. A link
  that stays inside the theme still works. (#72)
- **`raster upgrade` refuses an app that isn't there.** When
  `RASTER_APP` is misspelled, names a folder with no `config/`, or names
  a framework folder such as `system/`, upgrade says so in one line and
  exits 1. It no longer prints a PHP warning or a success line it didn't
  earn, and it no longer writes into the framework folder. (#75)
- **`raster upgrade` says when it couldn't record the version.** If
  `config/raster-version` can't be written, upgrade reports it, names
  the steps that already ran (it checks them again next time) and exits
  1. (#75)
- **The page cache keeps one copy per page, whatever the letter case.**
  On a disk that ignores case (macOS, Windows), `/ABOUT`, `/About` or
  `/News/news_page/2` find `about.html` or `news.html` and answer, but
  only the view file's own spelling is cached, so made-up spellings
  can't fill the disk. The check runs only when a page is about to be
  stored. (#56)
- **Two sign-ups at once with one email make one account, on SQLite.** A
  double click or two tabs used to make two accounts; now one is logged
  in and the other is answered with `email_taken`, instead of the second
  overwriting the first. The same goes for two members changing to one
  email at once, and for a member changing to an email someone is
  signing up with. Looking for the email and storing the account happen
  in one transaction, and the password is hashed before it. (#77)
- **New `authentication::create_user($login, $password, $role, $name)`**
  makes an account, or returns null when the login is already taken; it
  never changes an existing account. `save_user()` still creates or
  updates (for `raster user`) under the same lock. (#77)
- **Newsletter sign-ups sent at once with one address leave one
  subscriber, on SQLite.** `newsletter::subscribe()` looks the address
  up and stores it in one transaction, and joins the caller's
  transaction when there is one. (#77)
- **Signing up again before confirming sends the same confirmation
  link,** so every confirmation email already sent still works. Before,
  each sign-up made a new link and the earlier one stopped working.
  (#77)
- **`newsletter.subscribed` is sent after the commit, and the
  confirmation email comes from the framework's listener on it,**
  `newsletter.confirmation_mail`. A rolled-back sign-up stores nothing
  and emails nobody. A site that sends its own email can unbind it with
  `event::unbind('newsletter.subscribed')->from('newsletter',
  'confirmation_mail')`. (#77)
- **Content saved at the same moment no longer collides, on SQLite.**
  Saving or deleting an item and saving a page now run in a transaction,
  as records already did. Items saved at once with one title get
  different slugs, and two editors saving different fields of one page
  at once both keep their change. Unique indexes and MySQL are not
  covered yet. (#77, #78, #129)
- **The page cache and `content_changed` wait for the commit.** Inside
  `cms_records::transaction()`, `util::content_changed()` throws the
  cache away and sends `content_changed` once after the commit, when its
  listeners can read the new rows, and not at all when the transaction
  is rolled back. (#81)
- **On MySQL, `cms_records::transaction()` no longer fails with "There
  is no active transaction"** when a write in it adds a table or column
  (in development, the first account on a new site). MySQL commits by
  itself on such a change, and Raster then has nothing left to commit.
  (#77)
- **The `log://` mail transport no longer warns** when two emails sent
  at once both create its folder.
- **Fields named like SQL words work in lists.** A field called `when`,
  `from`, `group` or `to` now sorts and filters like any other, in
  `order=`, in comparisons such as `group>2`, on filter pages such as
  `/trips/trips_items/from/Paris`, in `cms_records::find`, and in
  `schema --rename` and `--drop`. Before, the list came out empty in
  development and showed the mock-up in production. (#69) A field named
  `order` or `limit` works too, except that `order=` and `limit=` in
  list options still mean the sort and the limit.
- **Lists work on MySQL again.** The filter that hides drafts and
  scheduled items, and `order=newest`, compare `published_at` with an
  empty string only while the column is still text, as in a table made
  before 2.1.7. MySQL refused that comparison for the typed column 2.1.7
  creates, so visitors saw the mock-up and pages with a pager, feeds and
  the sitemap answered 500. (#64)
- **A time prints `19:00` on every database.** A `time` value is read
  without seconds, so MySQL's `19:00:00` prints the same as on SQLite.
  (#64)
- **Apps with no version file are taken as current.** `raster upgrade`
  (and so `raster update`) no longer treats an app without
  `config/raster-version` as a Raster 1.x site and runs every old step
  on it, which could turn on `api_open` in a second app made by hand. It
  writes today's version into the app, runs no old steps and says so in
  one line. For an app that really is from 1.x, write `1.0.0` in that
  file first, then run `raster upgrade`. `raster version` says which
  apps have no version file (#75).
- **Theme SVGs, WebP images, fonts, icons and PDFs load on Apache.** New
  sites no longer get `application/.htaccess` and `system/.htaccess`,
  old Apache 2.2 rules that refused anything but png, jpg, gif, js and
  css under the app folder, and gave every theme file a 500 on an Apache
  without `mod_access_compat`. The root `.htaccess` still refuses
  config, data, models and system on its own. `raster update` removes
  `system/.htaccess`; if your site has an `application/.htaccess`,
  delete it yourself (#76).
- **Long lists fill every row.** A `print.model.method` or
  `print.if.flag` inside a render block used to stop after 1,000 rows:
  later rows kept the mock-up and the raw annotation, and a `print.if`
  block meant for staff showed to visitors. Every row is now filled, in
  one pass over the page, so 2,000 rows take about 4 ms instead of 25.
  Sites need do nothing. (#54)

## 2.1.7

Existing databases are not converted for you. `php bin/raster schema`
shows each field's type and the columns that differ, and
`schema --apply` converts them, Raster's own (`enabled`,
`published_at`) included, where every value fits.

- **Fields have types.** A field is text, `int`, `number`, `bool`,
  `date`, `datetime` or `time`. A template field's mock-up says which
  (`14` an int, `4.50` a number, `2026-10-10` a date, `19:00` a time,
  anything else text); a record field's default does (`0` an int, `false`
  a bool), and `'types' => array('date' => 'date')` in `types()` names
  the rest. The column is declared as the type.
- **Values are stored as their type,** whoever writes them: `5 Oct 2026`
  becomes `2026-10-05`, `8pm` becomes `20:00`, `yes` becomes 1. A value
  that can't be (`many` guests, `2026-02-30`, `31 Feb`, a stray letter
  in a date, `01234` as a whole number) is refused with the field and an example. A form value the
  HTML let through raises `<field>_invalid`; a ticked checkbox is yes
  whatever its `value`.
- **Lists compare and sort by type:** `order=-price` puts 100 above 18
  above 9.50, and `price<10` compares numbers.
- **Models, MCP and `/api` read ints, floats and bools.** Templates and
  the in-page editor still get text; a number prints with its mock-up's
  decimals, so `14.50` stays `14.50`, from any model, records included.
  After a save the editor shows what was stored (`9.50`, `2026-10-05`),
  not what was typed.
- Columns are declared the same way in MySQL (`DOUBLE`, `TINYINT(1)`),
  and `schema --apply` never leaves a column half converted.
- `schema`, `describe` and MCP `site_overview` list the types, and
  `schema --apply` converts a column whose type changed when every value
  fits.
- `enabled` is a bool now: compare `!$item['enabled']`, not `=== '0'`.

## 2.1.6

Nothing for sites to do. Staff pages that show records with a model's own
rows (`render.booking.rows` returning `cms_records::find(…)`) now get a
`lint` warning: list them with `render.cms.<type>` and the options below,
or return them through `cms_records::listed()`, so staff can edit them in
the page again.

- **Lists filter from the URL, by date, and sort by several fields.**
  `render.cms.booking('stylist=?stylist&date>=today&order=date,time')`:
  `field=?name` takes `?name=` from the URL and is left out when the URL
  has none, so a plain `<form method="get">` filters a staff page with no
  model code. `>`, `>=`, `<`, `<=` and `!=` compare, and `today` is the
  date the page is shown, so upcoming and past bookings are two lists, not
  a stored field that goes stale. `order` takes several fields.
  Pagination takes the same options and its links keep the query string.
- **Get forms keep what was asked.** `/bookings?stylist=Ana` shows Ana
  chosen in a `<form method="get">`.
- **Views a model builds stay editable.** A render method that returns
  records through `cms_records::listed($type, $rows)` gets the in-page
  editor's marks, also in lists inside its rows: an agenda grouped by day
  is edited in place, with each record's actions. Lists inside rows now
  render through the same code as a render block, so their attributes
  (`print.@href`) and escaping work too.
- **Staff can add records a form makes.** A type with
  `'staff_add' => true` gets the in-page editor's card for a new item (a
  booking taken over the phone). A new item starts with the values the
  list's filters ask for, as the URL gives them. No card on an item's own
  page.
- **Render a page as staff.** `php bin/raster render /bookings --as=editor`
  (an account's email or username, or a role) renders the page as that
  person and prints what the in-page editor can edit there. MCP
  `render_url` takes `as` and answers with the same as `editor`. Both
  refuse in production, where they would show any account's pages
  without a password. `raster render` also passes a URL's query string to
  the page.
- **`lint` warns about records staff can't reach:** a type a form makes
  that no view lists with `render.cms.<type>`, and an admin page showing
  records a model reads itself without `cms_records::listed()`.
- **MCP over stdio survives a PHP error.** An `Error` in site code (a typo
  in a listener, say) answers the call with its file and line instead of
  ending the server, and output a tool's code prints no longer reaches the
  protocol stream.
- **The in-page editor keeps several lists of one collection apart.** An
  item added from a list's card was counted in every list of that
  collection on the page, so the next card of another list showed up
  under the first one.
- AGENTS.md: staff pages keep the in-page editor working: list options,
  then a model view with `listed()`, then links to each record's page.

## 2.1.5

Nothing for sites to do. Back up an SQLite database by copying the file
together with its `-wal` and `-shm` files, or with `sqlite3 <file> .backup`.

- **Fewer database queries per page in production.** With the schema
  frozen, the CMS reads the list of tables and their columns once per
  request instead of several times per field, turns list rows into arrays
  without checking every table's columns, reads a page's row once for all
  its fields, and no longer counts a list's rows before showing it. Demo
  pages went from 22–74 queries to 3–9.
- **SQLite runs in WAL mode.** Readers no longer wait for a write to
  commit, and writes cost less. Each connection also sets
  `synchronous=NORMAL` and waits at most 5 seconds for a locked database,
  not PDO's 60. Existing databases switch on their next connection. The
  `-wal` and `-shm` files are never served, and `raster export` ignores
  the `-shm` file when deciding whether anything changed.
- **Item ids in lists and feeds are numbers.** `render.cms.<name>` and
  `feed.items` now give `id` as an integer, as MCP and the editor already
  did. Pages print the same.

## 2.1.4

Run `php bin/raster cache clear` on production sites after updating, so no
page cached before the update is served at an address that is now refused.

- **Security: attributes set inside render rows could be broken out of.**
  A value printed with `print.@attr.key` or `print.+attr.key` inside a
  render block could close the attribute and add its own (an `onmouseover`,
  say) with a backslash sequence. Links built from the request path, such
  as the language switcher and pagination on filter pages, made this
  reachable from a crafted URL, and so did a visitor-written field of a
  public record type. Row attributes are now set the same way as
  top-level `print.@attr`, where the value is only ever text.
  (GHSA-v6gr-g4cg-vjcr)
- **Security: protected pages could be reached without logging in.**
  Behind nginx, Apache or Caddy, `/./staff`, `/%2E/staff`, or `/Staff` on a
  disk that ignores case, rendered a page `protected` keeps for staff.
  Now `a//b` is `a/b`, any path with a segment starting with a dot is a
  404, and each `protected` pattern matches the start of the URL and of
  the view it renders, ignoring case. (GHSA-52xw-67m4-c6xw)
- **`print.@src` in a render row leaves `srcset` alone.** The attribute is
  matched by its whole name, on the tag the annotation wraps only, so
  `srcset`, `data-src` and attributes of nested tags are no longer
  overwritten.
- **Every error gets the plain error page in production.** Only template
  errors did; an `InvalidArgumentException` or a named query that doesn't
  exist ended as a raw PHP fatal. Now anything thrown while rendering shows
  the plain page and is written to the error log with where it happened.
- **Warnings and errors always reach the PHP error log.** `log::warning`
  and `log::error` did nothing unless `log::enable()` was called, so a
  password reset email that failed to send left no trace. `log::enable()`
  still controls the browser console.
- **Page requests do less.** The lint, schema and describe tools load only
  when MCP answers, and the framework no longer asks the autoloader for
  `the_config` and the other core overrides on every `config::get`.

## 2.1.3

Nothing for sites to do.

- **Clearing the page cache by hand.** In production, changes Raster doesn't
  make itself (a view, theme file, model or config edited with ordinary file
  tools, or the database changed directly) were invisible to visitors until
  `page_cache_ttl` ran out, while `raster render` and MCP `render_url`,
  which never read the cache, already showed them. `php bin/raster cache
  clear` and the MCP tool `clear_cache` throw the cache away. The MCP
  server's instructions tell agents when to call it, and `describe` says
  whether the cache is on (`site.page_cache`).

## 2.1.2

Nothing for sites to do.

- **A site can live under a path.** When `RASTER_URL` (or `site_url`) has a
  path, e.g. `https://example.com/shop/`, that path is where the site is:
  requests under it are routed, and links, the `<base>` and emails carry it.
  `/shop` without the slash redirects to `/shop/`. This works behind a proxy
  that forwards `/shop/...` unchanged, and with `raster serve`, static files
  included. Running `php -S` yourself, set `RASTER_URL`: its router runs
  before the site's config is read, so it can't see `site_url`. Private
  paths are refused the same way under the folder.
- **Agents are told: every form that saves data gets a staff page.**
  `AGENTS.md` (Records) now asks agents that add a form storing records to
  also add a page, protected for editors, that lists them, so what visitors
  send is never stored out of sight.
- **The in-page editor keeps the site's look on every page.** Once the
  browser had cached the editor's script, it could run before the page's
  stylesheets applied and take the browser's defaults (serif type, black on
  white, a default blue) for the site's colours and fonts. It now reads the
  look again when the stylesheets and the page have loaded.
- **The in-page editor lists admin pages.** Every view `protected` keeps
  for `editor` or `admin` shows in a new **Admin** menu in the editor's bar,
  by its `<title>`, for whoever may open it. Staff pages no longer need a
  hidden menu or a `print.if.is_editor` link. `describe` (and MCP
  `describe`) has a new `admin_pages` section.

## 2.1.1

**Security: `/api` answers only what a model lists.** Until now every public
method of every application model answered at `/api/<model>/<method>`, to
anyone. That included methods written for templates on protected pages: a
staff page's `unpaid_orders()` was protected at `/orders` and readable by
any visitor at `/api/…/unpaid_orders`, and `cms_records::find()` (which is
for the model's own code) returns private records to whoever calls it. Now a
model lists what it offers, with the least role that may call each method:

```php
static function api() {
    return array('hours' => 'visitor', 'webhook' => 'visitor', 'day' => 'editor');
}
```

Anything not listed answers 404. A role the caller lacks answers 401 (not
logged in) or 403. `lint` checks `api()`, and `vocabulary` shows what each
model offers. `/api/the_<model>/…` is 404: an override of a bundled model
is only ever reached by the name it overrides, and only when that name is
in `api_system_models`.

**What sites need to do** after `php bin/raster update`:

- If any of your models has no `api()`, the upgrade adds
  `config::set('api_open')->to(true)` to `config/the_app.php`, so `/api`
  keeps answering as before and nothing a script depends on breaks. That
  also keeps the hole open. `php bin/raster doctor` warns about it until you
  close it:
  1. Find what calls your `/api` (page scripts, webhooks, other servers).
  2. List those methods in each model's `static function api()`, with the
     least role that may call them.
  3. Remove the `api_open` line.
- `api_open` stops working in 2.2.0.

## 2.1.0

**What sites need to do** after `php bin/raster update`:

- Run `php bin/raster doctor`. If it reports an `.htaccess` from an older
  Raster, replace it: `php bin/raster deploy --config=apache > .htaccess`
  (or `--config=nginx|caddy` for those servers).
- Public files in a folder named `config/`, `models/`, `data/` or `i18n/`
  anywhere in the site are now refused (403). Move them to `media/` or the
  theme folder.
- Code that called a named query that doesn't exist got `false`; it now
  throws. `php bin/raster lint` finds those calls.
- Anything that used the old editor endpoints (`edit_variable`, `edit_data`,
  …) or posted `raster_action` needs the new editor or MCP instead.
- Records are new and optional: nothing changes until a model declares a
  type. In production, `php bin/raster schema --apply` then creates its table.

- **Records: types a model declares, stored and shown by the CMS.** A model's
  `static function types()` declares bookings, orders, applications: their
  fields, who may create them, which fields are `readonly` or `hidden`, whether
  they are `public` or have an `owner`, and their `actions`. The CMS stores
  them as a collection named after the type, lists them wherever a view
  renders `render.cms.<type>`, and lets editors and agents change them with
  the in-page editor and the MCP item tools. The model's static
  `check($type, $after, $before)` runs before every write — form, editor, MCP
  or the model's own code — and names problems the template words as alerts.
  Records are private unless the type says otherwise: visitors see none, and
  feeds, the sitemap and exports leave them out. What visitors typed prints as
  text. `cms_records::submit()` is a whole form handler;
  `cms_records::transaction()` makes several writes happen together or not at
  all, with events after the commit; list fields (line items) are stored as
  JSON and render as nested rows. Actions are buttons in the editor for the
  roles allowed, `run_action` over MCP. `owner=me` lists the logged in
  person's own records (an account page). `php bin/raster make model <name>
  --from=<view>` writes a model for a form's records. Nothing changes for
  existing sites. See the Records section of AGENTS.md.
- The editor's item handle shows a record type's actions instead of Duplicate
  and Schedule, and never makes a readonly field editable.
- **An example shop** in `shop/` (Blue Hour Ceramics): public products,
  private orders with an owner, a cart in the session, a checkout that takes
  stock in a transaction so the last piece can't sell twice, and Ship, Mark
  paid, Cancel and Refund actions. Buyers pay the courier on delivery by
  default; cards go through Stripe Checkout (or a pretend provider in
  development), with a signed webhook at `/api/order/webhook`. It is an example to copy, not
  part of the framework. `php tests/shop.php` runs it end to end.
- The demo café's bookings are records: `/staff` groups them into to
  confirm, confirmed and cancelled, `/reservation` lists them all with links
  by evening and status, a day holds 20 guests, and members see their own
  bookings on `/account`.
- **Tooling an agent can afford.** Everything an agent needs to work on a
  Raster site is now an MCP tool, so it is asked and answered in one
  long-lived process instead of starting PHP again per question: `describe`
  (~70 ms) returns how URLs reach views, the content model the markup
  declares, the vocabulary, the behaviour-changing settings and the current
  lint state; `vocabulary` lists every model with its methods **and their
  signatures**, the named SQL queries, the events and who listens, and the
  names the CMS keeps; `annotations` is the grammar; `list_views`, `read_view`,
  `check_view`, `write_view` and `render_url` cover reading, checking, writing
  and seeing a template. `write_view` refuses markup that does not lint and
  leaves the file untouched, so a hallucinated model or method cannot land.
  `render_url` runs in its own process, so a page that dies cannot take the
  server down. On the command line: `php bin/raster describe` and
  `php bin/raster vocabulary`, both with `--json`.
- **`lint` checks the arguments.** Annotations pass literals, so the count is
  known without running anything: too few or too many is now an error, naming
  the signature it read (`cafe.category_count(category) needs 1 argument(s), 0
  given`). A method name that does not exist also gets the nearest one that
  does.
- `write_view` edits code, not content — a template can call any model — so
  over HTTP it is not offered until a site sets `mcp_write_views`. Over stdio,
  where the agent is already on the machine with the files, it is available.
  `describe` never includes the MCP token or the mail transport.
- **One list of what is never served.** The rules were kept twice, in
  `.htaccess` and in the router in `index.php`, and they had already drifted:
  neither refused `.phar`, so a `composer.phar` in a site folder was
  downloadable. They now live once, in `system/private_paths.php`, which the
  router reads and which `php bin/raster deploy --config=apache|nginx|caddy`
  turns into the configuration for the server in front (the shipped
  `.htaccess` is that output). `.phar`, `.lock`, `.ini` and `.bak` join the
  private extensions, matching ignores case on every server (a
  case-insensitive disk could otherwise hand out `/DEMO/DATA/SITE.SQLITE`),
  and `/.well-known/` is the one dot folder that is served. `doctor` checks
  that the `.htaccess` on disk still carries every rule; a site's own
  `.htaccess` from an older Raster is reported, and
  `php bin/raster deploy --config=apache > .htaccess` replaces it.
  No rule names an app folder any more, so `config/`, `models/`, `data/` and
  `i18n/` are private under every top level folder, not only app folders: a
  static file at `/assets/data/x.json` is now a 403. Put public files
  elsewhere (`media/`, or the theme folder).
- **`lint --fix`** repairs what has one right answer: spacing the engine
  can't read (`<!--print.cms.x-->`) and short closing tags. The engine still
  needs the whole name in a closing tag; lint now says which block a
  `<!-- /render -->` meant and writes `<!-- /render.cms.menu('order=name') -->`
  in. A likely misspelled keyword stays a warning, since it may be an
  ordinary comment, and everything else that needs a decision is reported.
- **The annotation grammar is data:** `system/tools/annotations.php`, printed
  by `php bin/raster annotations [--json]`. `lint` checks against that same
  file, so what an agent is told and what is enforced cannot drift apart.
- Config `allow_deprecated` marks uses of deprecated features a site keeps on
  purpose (`array('<id>' => true or path pattern(s))`); `doctor` counts them
  apart instead of warning. The demo café uses it for the older validation
  regions its suite still covers.
- The ORM no longer needs `pdo_mysql` to be loaded on an SQLite-only site:
  `rb.php` read `PDO::MYSQL_ATTR_INIT_COMMAND` as it loaded, which is a fatal
  error when PHP has no MySQL driver.
- `raster_project::apps()` and the router agreed that any top level folder
  with a `config/` inside is an app folder, which made `system/` one.
- `raster export <folder>` writes the site as static files: every page,
  item, list page and feed, the 404 page, theme files and uploads, and each
  language in its own folder. Exporting again writes only what changed,
  removes what's gone, and does nothing when nothing changed.
- `print.if.live` and `print.if.static`: forms go in the first, what a static
  export shows instead in the second. An export stops when a page still
  shows a form.
- Uploaded pictures are stored with root-relative addresses (`/media/…`),
  so they survive a new domain or a static export.

- A new in-page editor replaces the old toolbar and its modal forms: the
  page is the editor, it takes the site's colours and fonts, saves as you
  go with undo, handles items (details, duplicate, hide, schedule, delete,
  add from the template's mock-up), photos (choose or drop, framed in the
  browser), fields the page can't show, and the page's history. English and
  Romanian. No jQuery or other scripts from elsewhere.
- `print.@attr.model.method` sets an attribute outside render blocks, so a
  page can have photo fields: `<!-- print.@src.cms.photo --><img src="a.jpg"><!-- /print.@src.cms.photo -->`.
- In render blocks, `print.@attr.key` with an empty value keeps the
  mock-up's attribute instead of emptying it.
- Removed: the old editor endpoints (`edit_variable`, `edit_data`,
  `edit_item`, `add_item`, `remove_item`, `upload_media`, `crop_media`,
  `css`, `script`) and posting `raster_action` to a page.

- Named queries: calling one that doesn't exist throws
  `BadMethodCallException` (it returned `false`), and `lint` reports such
  calls. `:name` placeholders are documented.
- A link inside a `print.validation.alert()` block goes to the route. The
  block is only a placeholder while the page renders, so the markup a
  listener puts back at the end used to miss the pass that turns
  `cart.html` into `/cart`. Anything added on `before_output`, the CMS
  toolbar included, is fixed up now.

## 2.0.0

RTO v2. Raster becomes a framework for sites, small apps, blogs and
newsletters made by people and agents. [AGENTS.md](AGENTS.md) is the
specification; the pattern is at
https://draganescu.github.io/rto/specs/2014/06/29/rto.html.

**New**
- PHP 8.1+, SQLite by default, `php bin/raster serve` with no setup.
- The CMS schema comes from the markup: page fields, site-wide `site_*`
  fields, collections with slugs, drafts, scheduled items, ordering, limits,
  filters and pagination; page revisions; `raster schema` to compare,
  apply, rename and drop.
- Forms: rules from HTML attributes, messages from the template, several
  forms per page, alerts, `form_state`, protection against other sites and
  bots.
- Bundled models: authentication (accounts, roles, protected pages, reset
  by email, lockout), newsletter (double opt-in, one-click unsubscribe,
  `raster send`), mail (log, mail(), SMTP with STARTTLS), feed, pagination,
  i18n, validation.
- Formats: `.rss`, `.atom`, `.xml`, `.json` and `.txt` views, escaped for
  their format.
- Events between models: `event::dispatch('model.happened', $payload)`,
  listeners declared with `static function listens()` or bound in
  `config/the_events.php`, checked by `lint` and listed by MCP. The bundled
  models send `authentication.*`, `newsletter.*`, `cms.*`, `mail.*` and
  `content_changed`.
- Page cache for visitors, cleared by any content change.
- MCP over stdio and HTTP; `raster lint`, `render`, `user`, `users`.
- Several apps in one project (`RASTER_APP`), and the demo café.
- `raster new`, `update`, `upgrade`, `doctor` and `version`.

**Upgrading from 1.x** (`raster upgrade` does the first four)
- `CLAUDE.md` and `.mcp.json` are added for agents.
- `data/` and `media/` get a `.gitignore`.
- `render.cms.login` becomes `render.authentication.login`.
- `models/sql.php` names its array `$queries` (`$querries` still works
  until 2.2.0).
- Accounts from the old `usersdata` table and md5 passwords keep working;
  passwords are rehashed at the next login.
- Regions named `not_empty`, `email_format` and `are_the_same` still work
  until 3.0.0; use HTML attributes with `validation.field('name')`, and
  `matches`.
- The toolbar logs out with a POST to `/api/cms/logout`;
  `/login/logout/fromraster` is gone.

**Deprecated**
- `render.cms.login`, `$querries` (removed in 2.2.0).
- `not_empty`, `email_format`, `are_the_same` regions (removed in 3.0.0).
