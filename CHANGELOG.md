# Changelog

Every release lists what sites need to do. `php bin/raster update` does the
file changes for you; `php bin/raster doctor` shows what is left.

## 2.1.6

Nothing for sites to do. Staff pages that list records with a model's own
rows (`render.booking.rows` returning `cms_records::find(…)`) now get a
`lint` warning: move them to `render.cms.<type>` with the options below so
staff can edit them in the page again.

- **Lists filter from the URL, by date, and sort by several fields.**
  `render.cms.booking('stylist=?stylist&date>=today&order=date,time')`:
  `field=?name` takes `?name=` from the URL and is left out when the URL
  has none, so a plain `<form method="get">` filters a staff page with no
  model code. `>`, `>=`, `<`, `<=` and `!=` compare, and `today`,
  `today+7` and `now` are the date and time the page is shown, so upcoming
  and past bookings are two lists, not a stored field that goes stale.
  `order` takes several fields. Pagination takes the same options and its
  links keep the query string.
- **Get forms keep what was asked.** `/bookings?stylist=Ana` shows Ana
  chosen in a `<form method="get">`.
- **Staff can add records a form makes.** A type with
  `'staff_add' => true` gets the in-page editor's card for a new item (a
  booking taken over the phone). The card only shows on lists a new
  record would show in, for every type: none on a list of confirmed
  bookings when status starts as new. A new item starts with the values
  the list's filters ask for, as the URL gives them.
- **Render a page as staff.** `php bin/raster render /bookings --as=editor`
  (an account's email or username, or a role) renders the page as that
  person and prints what the in-page editor can do there: editable fields,
  items, and each list with whether it gets a card for a new item. MCP
  `render_url` takes `as` and answers with the same as `editor`.
  `raster render` also passes a URL's query string to the page.
- **`lint` warns about records staff can't reach:** a type a form makes
  that no view lists with `render.cms.<type>`, and an admin page listing
  records a model reads itself, which the in-page editor can't edit.
- **MCP over stdio answers a call that ends the server.** When a listener
  or the site's code calls `exit()` during a tool call, the call gets an
  error naming the tool, the listener that was running and what it
  printed, instead of the process ending without a word. A PHP error
  (a typo in a listener, say) answers the call with its file and line,
  and the server goes on. Output a tool's code prints no longer reaches
  the protocol stream.
- **The in-page editor keeps several lists of one collection apart.** An
  item added from a list's card was counted in every list of that
  collection on the page, so the next card of another list showed up
  under the first one.
- AGENTS.md: staff pages stay on `render.cms.<type>`, with the recipe for
  filters and upcoming and past bookings.

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
