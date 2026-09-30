# Changelog

Every release lists what sites need to do. `php bin/raster update` does the
file changes for you; `php bin/raster doctor` shows what is left.

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
