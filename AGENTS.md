# Raster: a guide for agents

Raster is the PHP implementation of RTO (Request, Template, Object). A request
picks a template, and the template pulls its data from objects (models). Views
are plain HTML, and the dynamic parts are HTML comments. The template owns all
the text; models only decide what shows. The CMS, forms, accounts and the
newsletter all follow this rule.

This file is the whole specification. When the code and this file disagree,
the code is wrong. The pattern itself is described at
https://draganescu.github.io/rto/specs/2014/06/29/rto.html

## Your loop

```sh
php bin/raster serve              # http://localhost:8000, no setup, SQLite
php bin/raster lint               # after every template edit; exit 1 on errors
php bin/raster schema             # what the CMS will store, compared with the database
php bin/raster render /about      # print a page without a server (exit 1 on 4xx/5xx)
php tests/run.php                 # framework test suite
php tests/demo.php                # the demo café: every feature, end to end
```

`demo/` is a complete example site that uses every feature (see
`demo/README.md`). Run it with `RASTER_APP=demo php bin/raster serve`. When
you're unsure how something is written, look there first.

In development, a view with template errors, or with errors in the partials it
includes, returns HTTP 500 listing the problems (header
`X-Raster-Template-Errors`) instead of a half-rendered page.

## Layout

```
index.php                     entry point, also the router for `php -S`
bin/raster                    command line
application/
  config/the_app.php          settings
  config/the_routes.php       extra routes (rarely needed)
  config/servers.php          host → environment
  config/db/<environment>.php database per environment
  models/<name>/<name>.php    your models: class <name>
  views/<theme>/              views (.html, .rss, .xml, .json), css, images
  i18n/<lang>/<file>.php      translations
  data/                       SQLite, page cache, logged mail (never served)
demo/                         the demo café, laid out the same way
system/                       the framework and its bundled models
media/                        uploads from the CMS
```

## Requests to views

- `/` renders `index.html`. `/about` renders `about.html`, and
  `/docs/setup` renders `docs/setup.html`. `/index` is the same page as `/`.
- **Formats:** `/news.rss` renders `news.rss`, and `/sitemap.xml` renders
  `sitemap.xml` (also `.json`, `.txt`, `.atom`). Printed values are escaped
  for the format: XML-escaped in feeds, JSON-escaped in JSON views. HTML views
  print values as they are.
- Files and folders starting with `_` are never pages: `_layout.html` for
  partials, `_email/` for emails.
- Unknown URLs return 404. View files themselves (`.html`, `.rss`, `.xml`,
  `.json`, `.txt` under `application/views/`) are never served raw (403); other
  theme assets are. Links like `href="news.rss"` are rewritten to `/news.rss`.
- In `.json` views, the rows of a render block are separated by commas, so a
  block inside `[ … ]` makes a JSON list.
- Link to pages by file name, `href="about.html"`. Raster rewrites that to
  `/about`, and `index.html` becomes `/`, so the file still works as a static
  mock-up. Asset paths are relative to the theme folder.
- Routes, for URLs that should render a differently named view
  (`application/config/the_routes.php`):
  `controller::route('blog/post')->to('post');`. Patterns are regular
  expressions matched from the start of the path. A literal route is a page
  of its own, with its own page fields. `/blog/post/id/7` makes
  `util::param('id')` return `7`.
- **Several sites in one install:** each app folder has its own config,
  models and views. `RASTER_APP=<folder>` picks one; the default is
  `application`.

## Annotations

An annotation is an HTML comment in one of these exact forms: one space after
`<!--` and one space before `-->`, or before `/-->` when self-closing.
Anything else is ignored at runtime, and `lint` reports it.

| Form | Meaning |
|---|---|
| `<!-- print.model.method -->default<!-- /print.model.method -->` | Replaced by the string the method returns. `false` or `null` keeps the default. |
| `<!-- print.model.method /-->` | Same, with no default. |
| `<!-- render.model.method --> … <!-- /render.model.method -->` | Repeated once per row the method returns. Inside, `print.key` is a key of the row. |
| `<!-- remove --> … <!-- /remove -->` | Mock-up content. Removed before anything runs. Can't be nested. |
| `<!-- res.name --> … <!-- /res.name -->` | A reusable fragment. |
| `<!-- dry.view.name /-->` | Inserts fragment `res.name` from `view.html` (e.g. `dry._layout.header`). |
| `<!-- print.if.flag --> … <!-- /print.if.flag -->` | Shown only when the template flag is true (`template::set('flag')->to(true)`). |
| `<!-- print.self.name /-->` | A value set on the template, for example in emails. |
| `<!-- print.session.key /-->` | `$_SESSION['key']`. |

Blocks must nest properly. **Evaluation order:** render blocks run from the
last in the file to the first, so blocks nested inside a render block run
before it. That's how a form's model already knows the validation results of
the regions inside the form. Print blocks run after all render blocks.

### Inside render blocks

```html
<!-- render.cms.team -->
<!-- print.@href.raster_detail_link --><a href="team/team_item/1">
  <!-- print.name -->Ada<!-- /print.name --></a><!-- /print.@href.raster_detail_link -->
<!-- print.+class.role --><span class="badge">…</span><!-- /print.+class.role -->
<!-- /render.cms.team -->
```

- `print.key` is replaced by the row's value.
- `print.@attr.key` wraps a tag and sets its `attr` to the value (escaped).
  `print.+attr.key` appends the value to the attribute instead. The attribute
  must already exist on the tag.
- A value that is itself a list of rows repeats its `print.key` block once for
  each nested row, for example a post with its comments.
- An empty array renders nothing. `false` keeps the mock-up content.

### Method arguments

Literals only: `render.news.latest(3, 'sports', true, -1)`. Numbers, quoted
strings, `true`, `false` and `null` are allowed. Nothing is ever passed to
`eval`.

## Models

```php
<?php // application/models/products/products.php
class products {
    function featured() {            // render.products.featured
        return array(array('name' => 'Chair', 'url' => '/chair'));
    }
    function count() {               // print.products.count
        return '12';
    }
}
```

Models return strings for `print` and lists of rows for `render`. HTML views
print values unescaped, so use `util::e($value)` on user input. Models load on
first use, so they can call each other directly (`mail::send_view(...)`,
`validation::get()`). A helper class named `<model>_<name>` lives in
`models/<model>/<name>.php` and loads the same way.

A model that writes its own tables declares them, so production gets them
from `raster schema --apply`:

```php
static function schema() {
    return array('reservation' => array('name' => '', 'guests' => 0, 'created_at' => ''));
}
```

SQL can live in files: `models/<model>/sql/<name>.sql` runs as
`database::instance('<model>')-><name>($arg, …)`, with `?` placeholders bound
to the arguments.

Application events go in `config/the_events.php`:
`event::bind('before_output')->to('cafe', 'stamp');`. Text can be replaced in
the pages under a path: `template::instance()->replace('{{x}}', 'y', 'lab');`.

Every public method of an application model is also JSON at
`/api/<model>/<method>/<arg>/…`. Of the system models, only `cms` is
reachable. Posts there pass the same site check as forms, but they are not
form submissions: `validation::get()->submitted()` is false, so form models
do nothing over `/api`. Keep anything else that changes data behind a check.

The database is RedBeanPHP (`R::find`, `R::dispense`, `R::store`), or
`database::instance()->query('… WHERE a = ?', array($a))` with bound
parameters.

## Forms

A form posts to its own page. The render block around it is the model that
handles it:

```html
<!-- print.validation.alert('sent') --><p>Thanks, we got your message.</p><!-- /print.validation.alert('sent') -->
<!-- render.contact.send -->
<form method="post">
  <input type="email" name="email" required>
  <!-- render.validation.field('email') --><p class="error">Enter your email.</p><!-- /render.validation.field('email') -->
  <textarea name="message" required maxlength="2000"></textarea>
  <!-- render.validation.field('message') --><p class="error">Write a message (up to 2000 characters).</p><!-- /render.validation.field('message') -->
  <button>Send</button>
</form>
<!-- /render.contact.send -->
```

```php
class contact {
    function send() {
        $v = validation::get();
        if (!$v->submitted()) return false;                       // show the form as designed
        if (!$v->valid()) return template::instance()->form_state(); // again, with the values
        mail::send_view('_email/contact', 'me@example.com', array('message' => util::post('message')));
        util::done('sent');                                       // redirect; the alert shows
    }
}
```

- **Rules live in the HTML.** `required`, `type` (email, url, number, date),
  `minlength`, `maxlength`, `min`, `max` and `pattern` are enforced on the
  server too.
- **`validation.field('name')`** shows its block when that field breaks a rule.
  Other regions: `matches('password', 'password_again')`, `cant_be('name',
  'admin')`, `accepted('terms')`. For your own rules, add
  `application/models/validation/rules/<rule>.php` with a function
  `validate_<rule>($value, ...)` that returns true or false.
- **`print.validation.alert('name')`** is hidden until a model calls
  `validation::get()->raise('name')`, or until the page loads with
  `?done=name` (which `util::done('name')` does). Alerts work anywhere on the
  page.
- **`template::instance()->form_state($data)`** fills the form: input values,
  checked boxes, selected options and textarea text. Passwords are never
  filled.
- **Added automatically** to every post form: `raster_form` (its owner), a
  honeypot field, and the session token for logged in users.
- **Refused automatically:** posts from other sites, and bots that fill the
  honeypot. The bots get a fake success.

## The CMS: the markup is the schema

`print.cms.<field>` declares a page field. Its default is the content inside
the block, and it is stored separately for each page URL. `render.cms.<name>`
declares a collection, whose fields are the `print` keys inside it. The first
item of a collection is the mock-up content.

```html
<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>
<!-- render.cms.news('order=newest&limit=3') -->
<h2><!-- print.headline -->First post<!-- /print.headline --></h2>
<!-- /render.cms.news('order=newest&limit=3') -->
```

- **Names:** lowercase letters, digits and `_`, starting with a letter.
  Reserved: CMS method names (`style`, `login`, …), `slug`, `id`,
  `updated_at`, `enabled` and `published_at` for fields; `users` and `raster`
  for collections. `lint` reports these.
- **Site-wide fields:** a field whose name starts with `site_`
  (`print.cms.site_name`) is shared by every page. Put these in `_layout.html`.
- **Collection URLs** are routed to views that render that collection:
  - `/news/news_item/<slug or id>` renders `news_item.html` (or `news.html`)
    with that item. If there's no such item, the response is a 404.
  - `/news/news_page/2` is page 2. The page size comes from `news_page_size` or
    `raster_page_size` (10).
  - `/news/news_items/tag/php` lists the items where `tag` is `php`.
- **Options:** `render.cms.news('featured=1&order=newest&limit=3')`. `order`
  is `newest`, `oldest`, `<field>` or `-<field>` (descending). Any other
  `key=value` is a filter, and adds that field if it's new.
- **Items** get a `slug` made from their title, headline or name. They also
  have `enabled` (`0` makes a draft) and `published_at` (a future date
  schedules the item). Visitors don't see drafts or scheduled items; editors
  do.
- **Relationships are by value.** `print.@href.raster_filter@author` links to
  the items with the same `author`. For real references, write a model.
- **Page edits are versioned:** every save is a new revision. Values can be
  HTML. An empty value shows the template default.
- **Changing fields.** A new annotation adds a column: on the next request in
  development, or with `php bin/raster schema --apply` in production.
  `schema` shows orphaned columns and suggests
  `--rename=table.old:new`, which moves the content across.
  `--drop=table.column` or `--drop=table` deletes something no template uses;
  `--force` overrides that check. `--check` exits 1 when the templates and the
  database differ (for CI).

## Bundled models

**authentication**: accounts, with every screen written in your templates.
- Regions:
  - `render.authentication.login`: fields `login` (email or username) and
    `password`; respects `?next=/path`.
  - `register`: `name`, `email`, `password`.
  - `forgot`: `email`. Sends `_email/password_reset.html`, with
    `print.self.reset_url` and `print.self.name`.
  - `reset`: `password`, opened from that email's link.
  - `account`: `name`, `email`, `password`, `current_password`.
  - `logout`: a form with a button.
  - `me`: rows with `name`, `email`, `role`.
- Alerts: `login_failed`, `email_taken`, `email_invalid`, `password_short`,
  `reset_sent`, `reset_invalid`, `registered`, `password_changed`,
  `account_saved`, `current_password_wrong`.
- Flags: `if.logged_in`, `if.logged_out`, `if.is_member`, `if.is_editor`,
  `if.is_admin`.
- Roles: `admin`, `editor` (edits content), `member`.
- Settings: `config::set('protected')->to(array('account' => 'member'))`,
  `registration` (false turns sign-up off), `login_page`, `after_login`,
  `password_min_length` (8).
- Five wrong passwords lock an account for 15 minutes.
- Command line: `php bin/raster user <email|name> [--role=…] [--password=…]`
  and `php bin/raster users`.

**newsletter**: sign-ups with double opt-in.
- Regions:
  - `render.newsletter.signup`: a form with `email`, and optionally `name`.
    Sends `_email/newsletter_confirm.html` with `print.self.confirm_url`.
  - `render.newsletter.confirm`: on the page `newsletter-confirm`.
  - `render.newsletter.unsubscribe`: on the page `newsletter-unsubscribe`, a
    form with a button. Mail apps' one-click unsubscribe works too.
  - `print.newsletter.count`.
- Alerts: `check_email`, `subscribed`, `confirmed`, `confirm_invalid`,
  `unsubscribed`, `unsubscribe_invalid`.
- **Sending an issue:** `php bin/raster send /news/news_item/my-post
  [--to=you@example.com] [--dry-run] [--again]` emails any page to confirmed
  subscribers. Scripts, forms and `<nav>` are removed, and links become
  absolute. Put `<a href="<!-- print.newsletter.unsubscribe_url /-->">` in the
  page so each reader gets their own unsubscribe link. A page is sent once
  unless you pass `--again`.

**mail**: emails are views. `mail::send_view('_email/welcome', $to,
array('name' => 'Ada'))` uses the view's `<title>` as the subject.
- Transport, set with `RASTER_MAIL`:
  - `log://` writes to `application/data/mail/` (the development default);
    `log:///some/folder` writes to that folder instead.
  - `mail://` uses PHP's mail() (the production default).
  - `smtp://user:pass@host:587` requires STARTTLS (add `?insecure=1` to allow
    plain SMTP; `localhost` is allowed as is), and `smtps://…:465` uses TLS.
- Sender: `RASTER_MAIL_FROM`.

**feed**:
- `render.feed.items('news')`: the newest published items, with every field
  plus `url`, `date_rfc822` and `date_iso`.
- `render.feed.pages`: every page and item, with `url` and `updated`, for
  `sitemap.xml`.
- `print.feed.site_url`.

**pagination**: `render.pagination.links('cms.news')` (add the list's filters
as a second argument: `links('cms.news', 'featured=1')`) gives one row with
`prev_url`, `next_url`, `prev_state` and `next_state` (`disabled` at the
ends), `current` and `total`. `render.pagination.pages('cms.news')` gives one
row per page with `number`, `url` and `state` (`current`). For your own model,
use `'model.method'`: `method(true)` returns
`array('total' => …, 'perpage' => …)`, and the pages are `?page=N`.

**i18n**: the template's text is the default language.
- Translations: `print.i18n.home('welcome')` looks up `application/i18n/<lang>/home.php`,
  which returns an array of key => text.
- Settings: `config::set('languages')->to(array('en', 'ro'))` and
  `domain_language`.
- The language comes from `?lang=` (remembered in a cookie), the domain, the
  cookie, then the browser.
- `print.i18n.language` gives the current language; `render.i18n.languages`
  gives a switcher with `code`, `url` and `state`.

## Environments and cache

- **Environments.** `servers.php` maps host names (full-name regular
  expressions) to environments. Hosts that aren't listed are **production**.
  `localhost` counts only for requests from the same machine. **On servers,
  set `RASTER_ENV=production`.**
- **Development** uses SQLite at `application/data/raster.sqlite`, and the
  schema is fluid.
- **Production** is frozen: the schema only changes through
  `schema --apply`, which also creates the tables the bundled models use
  (accounts, subscribers). Missing columns show the template default.
- **The site's address:** set `RASTER_URL=https://example.com/` (or
  `config::set('site_url')`). Links in pages and emails then never depend on
  the visitor's `Host` header. In production, emails with links (password
  reset, newsletter confirmation) are not sent without it, and
  `raster send` needs it.
- **Database file:** `RASTER_DB=/path.sqlite` points at another database
  file.
- **Page cache**, on by default in production (config `page_cache`):
  - Whole pages are cached for visitors without a session or a query string.
  - Any content change throws the cache away (`util::content_changed()` in
    your own models), and so does the moment a scheduled item is published.
  - Settings: `page_cache_ttl` (3600 seconds) and `page_cache_skip` (path
    patterns). Responses carry `X-Raster-Cache: hit|miss`.

## Editors and agents

- Editors (roles editor and admin) log in at `/login` and get a toolbar on
  every page. They also see drafts. Visitors get no cookies until they log in
  (except `lang`, when they pick a language).
- **MCP** is available in two ways:
  - over stdio: `php bin/raster mcp`, already set up in `.mcp.json`;
  - over HTTP: POST to `/mcp` with `Authorization: Bearer $RASTER_MCP_TOKEN`.
    It's off until that token is set.
- **Tools:** `site_overview`, `get_page`, `update_page`, `page_history`,
  `list_items`, `get_item`, `create_item`, `update_item`, `delete_item`,
  `lint_templates`, `schema_status`.
- **Addressing pages:** by URL (`/about`), by view (`about`), or `site` for the
  `site_*` fields.
- **Writes** are limited to fields in the templates, plus `slug`, `enabled`
  and `published_at` on items.

## Checklist for a change

1. Edit or add views in `application/views/<theme>/`. Start from static HTML
   with real content, then annotate.
2. `php bin/raster lint` must show no errors. Also read the warnings: they
   point to forms nobody handles, alerts nobody raises, and missing email
   views.
3. `php bin/raster schema` shows the content model you meant to create.
4. `php bin/raster render /the-url`, or `serve`, and check the HTML.
5. `php tests/run.php` if you touched `system/`.
