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
php bin/raster describe           # the whole site in one answer; start here
php bin/raster vocabulary         # every name a template may call, with signatures
php bin/raster annotations        # the annotation grammar, as data
php bin/raster serve              # http://localhost:8000, no setup, SQLite
php bin/raster lint               # after every template edit; exit 1 on errors
php bin/raster lint --fix         # repair what is mechanical, report the rest
php bin/raster schema             # what the CMS will store, compared with the database
php bin/raster render /about      # print a page without a server (exit 1 on 3xx/4xx/5xx)
php bin/raster render /staff --as=editor   # as staff see it, says what the editor can edit (not in production)
php bin/raster cache clear        # after editing files or the database by hand, in production
php bin/raster doctor             # is this site healthy, up to date, ready for production?
```

`describe`, `vocabulary`, `annotations`, `lint`, `schema` and `doctor` all take
`--json`. A command refuses an option it doesn't take (a mistyped
`--dry-run`, say) with exit 2 and the options it does take, before it runs:
`php bin/raster help` lists them all. If you can speak MCP, use that instead: the same answers come from one
long-lived process (`php bin/raster mcp`, already in `.mcp.json`) rather than
starting PHP again for every question — `describe` costs about 70 ms, everything
else under 20. See **Editors and agents**.

The demo café in the Raster repository
(https://github.com/draganescu/rasterPHP/tree/master/demo) is a complete site
that uses every feature. When you're unsure how something is written, look
there first. Its look is the café's own: don't copy it into another site.

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
system/                       the framework and its bundled models (don't edit)
media/                        uploads from the CMS
```

## Requests to views

- `/` renders `index.html`. `/about` renders `about.html`, and
  `/docs/setup` renders `docs/setup.html`. `/index` is the same page as `/`.
  A page has one address: a GET for `/menu/` (a trailing slash), or for
  `/index.php/menu` while `rewrite` is on, answers 301 to `/menu`, query
  kept. Raster's own links, filter links included, never end in a slash.
- **Formats:** `/news.rss` renders `news.rss`, and `/sitemap.xml` renders
  `sitemap.xml` (also `.json`, `.txt`, `.atom`). Printed values are escaped
  for the format: XML-escaped in feeds, JSON-escaped in JSON views. HTML views
  print values as they are. A route or a collection URL with an extension renders
  the routed view's file in that format (`/news/news_page/2.rss` is page 2
  of `news.rss`), and is a 404 when there is none. A 404 page is always
  HTML, whatever the missing URL's extension (`/about.json`).
- Files and folders starting with `_` are never pages: `_layout.html` for
  partials, `_email/` for emails. Collection URLs follow the same rule:
  `/_email/digest/news_page/1` is a 404 even when `_email/digest.html`
  renders `news` (one check, `controller::is_partial()`).
- Unknown URLs return 404, and so does any path with a segment starting
  with a dot (`/./about`, `/.env`); `a//b` is `a/b`. View files themselves (`.html`, `.rss`, `.xml`,
  `.json`, `.txt` under `application/views/`) are never served raw (403); other
  theme assets are. A second path segment named `config`, `models`, `data`
  or `i18n` is refused (403) under any folder, since those are an app's
  private folders: `docs/config.html` is a page nobody can open, and `lint`
  warns about it (the rules are in `system/private_paths.php`). Links like `href="news.rss"` are rewritten to `/news.rss`.
- In `.json` views, the rows of a render block are separated by commas, so a
  block inside `[ … ]` makes a JSON list.
- Link to pages by file name, `href="about.html"`. Raster rewrites that to
  `/about`, and `index.html` becomes `/`, so the file still works as a static
  mock-up. Asset paths are relative to the theme folder.
- **The `<base>` tag.** Every HTML page but an email gets
  `<base href=".../views/<theme>/">` right after its `<head>` tag, with
  attributes or without (`<head prefix="og: …">`), unless it has a
  `<base>` of its own; that is what makes asset paths work on every URL. So
  `href="#prices"` and `href="?sort=price"` open the theme folder, not the
  page: write `href="about.html#prices"` (it becomes `/about#prices`) or
  `href="about.html?sort=price"`. `lint` warns about the short forms,
  except on a link a `print.@href` sets and on a page with its own `<base>`. A link to another
  site without its scheme (`href="//cdn.example.com/guide.html"`) is left
  as written.
  Next to it a script sets `BASE`, the site's address for scripts; on a
  multilingual site the address of the page's language (`/ro/`), where
  `/api` answers too.
- **Asset versions.** A relative URL to a real file in the theme
  (`href="style.css"`, `src`, `srcset`, `poster`) gets `?v=<version>`, made
  from the file's time and size, so it changes when the file does
  (`controller::version_assets`, config `asset_versions`, on by default). A
  URL that already has a query or a fragment is left alone. The servers
  keep a theme file with `?v=` for a year (`immutable`) and have browsers
  check one without it each time; pages are `Cache-Control: no-cache`. So a
  browser keeps the CSS and still sees a new one right after a publish.
  CSS's own `url(…)` (fonts, backgrounds) gets no version and is checked each
  time.
- Routes, for URLs that should render a differently named view
  (`application/config/the_routes.php`):
  `controller::route('blog/post')->to('post');`. Patterns are regular
  expressions matched from the start of the path. `/blog/post/id/7` makes
  `util::param('id')` return `7`. A literal route (only lowercase letters,
  digits, `_`, `-` and `/`) is a page of its own, with its own page fields,
  and its URLs with parameters are that same page: `/blog/post/id/7` shows
  and edits the fields of `/blog/post`. A pattern with regular expression
  parts (`blog/[0-9]+`) is not one address, so each URL it matches keeps
  its own page fields.
- **Several sites in one install:** each app folder has its own config,
  models and views. `RASTER_APP=<folder>` picks one; the default is
  `application`.

## Annotations

An annotation is an HTML comment in one of these exact forms: one space after
`<!--` and one space before `-->`, or before `/-->` when self-closing.
Anything else is ignored at runtime, and `lint` reports it. `lint --fix`
repairs spacing and short closing tags, where there is only one right answer;
a likely misspelled keyword (`<!-- prnit.cms.x -->`) is only a warning,
because it may be an ordinary comment.

The grammar is also data: `system/tools/annotations.php`, printed by
`php bin/raster annotations [--json]`. It is what `lint` checks against, so
an agent can read it and be sure. Its `names` are the characters each kind
of name is made of, and the engine builds its patterns from the same list
(`template::$names`), so a name lint accepts is one the page reads: model
names are lowercase letters, digits and `_`, as PHP class names are (a
name with `-` is read as one, and lint reports no such model); row keys letters, digits,
`_` and `-` (`print.line2`, `print.line-2`); attributes may have dashes and
colons too (`print.@data-id.key`, `print.@aria-label.key`,
`print.@xlink:href.url`); views and `res` names lowercase letters, digits,
`_` and `-` (`dry._layout.nav2`, `res.nav2`).

| Form | Meaning |
|---|---|
| `<!-- print.model.method -->default<!-- /print.model.method -->` | Replaced by the string the method returns. `false` or `null` keeps the default. |
| `<!-- print.model.method /-->` | Same, with no default. |
| `<!-- print.@src.model.method --><img src="a.jpg"><!-- /print.@src.model.method -->` | Sets the attribute of the tag it wraps (`+` instead of `@` appends). `false`, `null` or an empty string keep the mock-up's value. With `cms`, a page field such as a photo. |
| `<!-- render.model.method --> … <!-- /render.model.method -->` | Repeated once per row the method returns. Inside, `print.key` is a key of the row. |
| `<!-- remove --> … <!-- /remove -->` | Mock-up content. Removed before anything runs, so fields and collections inside it are not part of the content model. Can't be nested. |
| `<!-- res.name --> … <!-- /res.name -->` | A reusable fragment. |
| `<!-- dry.view.name /-->` | Inserts fragment `res.name` from `view.html` (e.g. `dry._layout.header`). A `dry` inside the fragment is filled in too, up to five levels deep, as `lint`, `schema` and MCP read it; a deeper one stays a comment. |
| `<!-- print.if.flag --> … <!-- /print.if.flag -->` | Shown only when the template flag is true (`template::set('flag')->to(true)`). `if.live` is true on the running site and `if.static` in a static export. |
| `<!-- print.self.name /-->` | A value set on the template, for example in emails. |
| `<!-- print.session.key /-->` | `$_SESSION['key']`. |

Blocks must nest properly. **Evaluation order:** render blocks run from the
last in the file to the first, so blocks nested inside a render block run
before it, also when the same tag appears earlier in the page (a list at
the top and again inside another block). That's how a form's model already knows the validation results of
the regions inside the form. Print blocks run after all render blocks.

**Closing tags carry the whole name**, arguments included:
`<!-- render.cms.menu('order=name&limit=3') -->` closes with
`<!-- /render.cms.menu('order=name&limit=3') -->`. A short closing tag
(`<!-- /render -->`, or `<!-- /render.cms.menu -->`) is ignored by the engine.
`lint` reports it with the full tag it should be, and `lint --fix` writes that
in, so write the short form if it is quicker and let the fix finish it.

### Inside render blocks

```html
<!-- render.cms.team -->
<!-- print.@href.raster_detail_link --><a href="team/team_item/1">
  <!-- print.name -->Ada<!-- /print.name --></a><!-- /print.@href.raster_detail_link -->
<!-- print.+class.role --><span class="badge">…</span><!-- /print.+class.role -->
<!-- /render.cms.team -->
```

- `print.key` is replaced by the row's value. Keys are letters, digits, `_`
  and `-`; `lint` reports one with any other character, which never prints.
- `print.model.method` and `print.if.flag` work inside a render block too:
  the method is called once and every row gets the same value (or hides
  the block), however long the list.
- `print.@attr.key` wraps a tag and sets its `attr` to the value (escaped).
  `print.+attr.key` appends the value to the attribute instead, after a
  space, as for `class` (so it can't finish a URL: `href="?event="` becomes
  `?event= 7`). The attribute must already exist on the tag.
- A value that is itself a list of rows repeats its `print.key` block once for
  each nested row, for example a post with its comments.
- An empty array renders nothing. `false` keeps the mock-up content. For
  `print.@attr.key`, `null` or an empty string keep the mock-up's attribute
  (a new image field shows the template's picture), and `false` removes it.

### Method arguments

Literals only: `render.news.latest(3, 'sports', true, -1)`. Numbers, quoted
strings, `true`, `false` and `null` are allowed. Nothing is ever passed to
`eval`.

Because they are literals, `lint` knows how many you passed and checks it
against the method: too few or too many is an error, naming the signature it
read. `raster vocabulary` lists every method that way
(`category_count(category)`, `links(source, filter = …)`), so there is no need
to guess.

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

One lookup finds a model, for pages, events, `/api`, `lint` and MCP alike
(`controller::model($name)`): `models/<name>/` is the site's own and is used
whole; else `models/the_<name>/` overrides the bundled
`system/models/<name>/` (see **Extending**); else the bundled model. A site
model with a bundled model's name hides the bundled one completely, and
`lint` warns about it.

A model that writes its own tables declares them, so production gets them
from `raster schema --apply`:

```php
static function schema() {
    return array('visit' => array('page' => '', 'seconds' => 0, 'created_at' => ''));
}
```

Each column is declared as the type of its example: `''` is text (TEXT
on SQLite, LONGTEXT on MySQL, so neither has a size limit), `0` an
integer, `0.0` a number, `false` a bool, on SQLite and MySQL alike.

### What a model declares

A model tells Raster what it is with static hooks, which `/api` can never
reach: `schema()` (its tables), `types()` (its records, see **Records**),
`api()` (what `/api` offers), `listens()` (see **Events**), and
`requires()`, what a site that uses the model needs:

```php
static function requires() {
    return array(
        'emails' => array('render.inquiry.form' => array('_email/inquiry_received')),
        'pages' => array(config::get('inquiry_answer_page', 'inquiry-answer')),
        'settings' => array('inquiry_staff_email', 'inquiry_answer_page'),
    );
}
```

- `emails`: a region and the `_email/` views it sends. `lint` reports a
  view with the region in a theme that lacks one of them.
- `pages`: views that only work with PHP behind them. The sitemap
  (`feed.pages`) and `raster export` leave them out; `sitemap_skip` and
  `export_skip` add to them.
- `settings`: `describe` lists their values.
- `records`: a region and the record types it needs at least one of
  (`'render.booking.form' => array('bookingresource')`). `lint` warns on a
  view with the region while there is none, and says nothing when it can't
  reach the database.
- `lists`: a record type and the model's regions that list it for staff
  (`'contactmessage' => array('render.contact.messages')`). `lint` counts a
  view with one of them as the type's staff page, as it counts
  `render.cms.<type>`.
- `requires()` is asked when it is needed, never kept in `models.json`, so
  it may read config: a page moved by its setting is left out where it is.
  `controller::required('pages')` merges what every model in use declares;
  a bundled model's counts only on a site whose views use it, as for
  `types()`. Emails are the exception: `lint` (and MCP's checks of a view
  before it is written) asks for a region's emails wherever the region
  appears, so a site's first newsletter form is checked too.
  `authentication` and `newsletter` declare theirs this way.

### SQL in files

**A model's SQL goes in files, not in PHP strings.** Each query is a file in
the model's `sql/` folder, and runs as a method named after it:

```sql
-- application/models/products/sql/in_category.sql
SELECT name, price FROM product WHERE category = ? AND price <= ? ORDER BY name
```

```php
$rows = database::instance()->in_category('chairs', 200);           // inside products
$rows = database::instance('products')->in_category('chairs', 200); // from anywhere
```

- The result is a list of rows (arrays keyed by column).
- `?` placeholders are bound to the arguments in order. For `:name`
  placeholders pass one array: `->between(array(':low' => 1, ':high' => 5))`.
- Without a model name, the query is looked up in the folder of the model
  a template is currently calling (and put back when that call ends). A
  name you pass applies to that call only, so code that calls another
  model, bundled or not, never moves your queries; pass the name whenever
  you're not sure.
- The model's folders are those `controller::model()` finds: the site's
  own `models/<name>/`, or a `models/the_<name>/` override's `sql/` first
  and then the bundled `system/models/<name>/sql/`. Bundled models keep
  their fixed queries there too (`newsletter.confirmed_count`).
- A file runs as written: `strftime('%d', date)` and `LIKE '%soup%'` are
  SQL. Queries any model can use go in `models/sql.php`:
  `$queries['name'] = "SELECT … WHERE a = '%s'"`. Its `'%s'` values are
  quoted by the database driver. (So are an old file's, when it is called
  with values and has no `?` or `:name` for them.) A file in the model's
  `sql/` folder wins over an entry there with the same name.
- A name with no query throws `BadMethodCallException`, and `lint` reports
  `database::instance(…)->name(…)` calls that have no query.
- **Times are PHP's, in the site's time zone** (setting `timezone`). A query
  that needs now or today takes it as an argument, `date('Y-m-d H:i:s')`
  from PHP, never SQLite's `date('now')` or MySQL's `NOW()`, which follow
  the database server's clock (UTC on SQLite).

Write a query this way rather than with `R::getAll(…)`, `R::exec(…)` or
`database::instance()->query('SELECT …')`: the model's code then reads as
what it does (`->in_category($c, $max)`), the SQL can be read and run on its
own, `vocabulary` lists every query by name, and `lint` checks each call has
one. Keep RedBean for what it does best, one row as an object
(`R::dispense`, `R::store`, `R::load`, `R::trash`), and `cms_records` for
records. An inline `query()` is for SQL the code has to put together.

Text can be replaced in the pages under a path:
`template::instance()->replace('{{x}}', 'y', 'lab');`.

## Events: models talking to each other

A template shows what the page displays and nothing else. When one model's
work should set off another's (a booking subscribes the guest to the
newsletter, a new account gets a welcome email), the first model sends an
event and the second listens. No model in the view does the wiring.

```php
// the model that knows something happened (the bundled booking model sends
// booking.requested with the booking's fields when a guest books)
event::dispatch('inquiry.received', array('email' => $email, 'newsletter' => true));

// a model that cares declares it next to its code (demo/models/cafe)
class cafe {
    static function listens() {
        return array('booking.requested' => 'subscribe_guest');   // or a list of methods
    }
    function subscribe_guest($booking) {
        if ($booking['who'] === 'guest' && $booking['newsletter']) newsletter::subscribe($booking['email']);
    }
}
```

- Or bind in `config/the_events.php`:
  `event::bind('booking.requested')->to('cafe', 'subscribe_guest');`, and
  `event::unbind(…)->from(…)`.
- Listeners get the payload array and run in order: the framework's own
  bindings in `system/config/events.php` (routing, the CMS and account
  checks, the newsletter's confirmation email, the booking emails) and the
  bundled models' `listens()` (an override's, for a `the_` model), then
  `the_events.php`, then the site's own models' `listens()`, then the
  framework's core handlers (controller and log). So a site listener sees
  the state a bundled model's listener left (an order already marked
  paid), and `the_events.php` can unbind any of the framework's listeners,
  `listens()` ones included. A listener returning `false` makes
  `event::dispatch()` return `false`; the sender decides what that means.
- Name your events `<model>.<what happened>`, in the past tense.
- `listens()` returns a constant array: Raster keeps what it returns in
  `data/cache/models.json` (with what each model declares), so a request
  binds listeners without loading the models. The list is made again when a
  model file changes or Raster is updated, so a new or edited model counts
  on the next request. An override's `listens()` is the one that counts for
  the model it overrides (call `parent::listens()` to keep the bundled
  one's).
- `lint` reports bindings to models or methods that don't exist, and events
  nothing sends. MCP `site_overview` lists who listens to what.

Events the bundled models send, from forms, `/api`, MCP and the command line
alike:

| Event | Payload |
|---|---|
| `authentication.registered`, `logged_in`, `logged_out`, `password_changed`, `account_saved` | `id`, `email`, `name`, `role` |
| `authentication.login_failed` | `login` |
| `newsletter.subscribed` | `email`, `name`, `status` (`pending` or `confirmed`), `source` (after the commit; the framework's listener `newsletter.confirmation_mail` emails a pending address) |
| `newsletter.confirmed`, `newsletter.unsubscribed` | `email`, `name` (after the commit) |
| `booking.requested` | a booking was made (by a guest on the form, or by staff in the page, over MCP or in model code): its fields but `token_hashes` and `token_expires`, and `who` (`guest` or `staff`). After the commit, as are the four below |
| `booking.confirmed`, `booking.declined`, `booking.cancelled` | the same, after the status change (`cancelled` by the guest's link has `who` `guest`) |
| `booking.rescheduled` | the same, after a move of an active booking (its `date`, `time`, `resource` or `service`; a guest's goes back to `requested`) |
| `contact.received` | a contact message that isn't spam was stored: its fields but `title`, and `who` (`visitor`; `staff` when staff marked one not spam). After the commit, as is the one below |
| `contact.flagged` | a message kept as spam: the same, with `spam` (the reasons) |
| `rsvp.replied` | a reply to an event was made (by a guest on the form, or by staff in the page, over MCP or in model code): its fields but `token_hashes` and `link_sent_at`, `who` (`guest`, `staff` or `waitlist`), `event_title`, `event_date`, `event_starts`. After the commit, as are the two below |
| `rsvp.changed` | the same, after its `answer`, `people` or `event` changed, with `was` and `was_people` (the status and party before) |
| `rsvp.promoted` | the same, after a waiting reply was moved in (`who` `waitlist`; `staff` by the `promote` action) |
| `cms.item_saved` | `collection` (its own name, `team_members`), `created` (true for new items), `item` |
| `cms.item_deleted` | `collection`, `item` |
| `cms.page_saved` | `type`, `slug`, `changed` (field names), `fields`; multilingual, also `lang` (the language the revision changed) |
| `content_changed` | none (sent by `util::content_changed()`; inside a transaction, once after the commit) |
| `mail.sent` / `mail.failed` | `to`, `subject` / and `error` (in a web request, after the answer) |

The request sends `launch`, `finding_route`, `route_set`, `route_found`,
`route_not_found`, `before_drying`, `dried_<view>`, `after_drying`,
`before_render`, `after_render`, `before_print`, `after_print`, `loop`,
`before_output`, `done` and `land`. `loading_model_<name>` can stop a model
from loading by returning false. Around every model call a template makes,
`executing_<model>_<method>` gets `arguments` and `executed_<model>_<method>`
gets `arguments` and `result` (the model's own name even when a `the_`
override answers, the method's name without arguments). These say "the
template called it", not "it happened": `executed_authentication_register`
runs on every view of the sign-up page, so listen to
`authentication.registered` instead.

A model offers methods as JSON at `/api/<model>/<method>/<arg>/…` by listing
them, each with the least role that may call it (`visitor`, `member`,
`editor`, `admin`):

```php
static function api() {
    return array('hours' => 'visitor', 'day' => 'editor');
}
```

Anything not listed answers 404, public or not: the methods templates call,
form handlers, listeners. A model without `api()` offers nothing. A role the
caller lacks answers 401 (not logged in) or 403. Static methods are never
reachable, even listed. Of the system models, only `cms` is reachable, and
only for what its own `api()` lists: the in-page editor's endpoints, `style`
and `logout`, which check the caller themselves; `api_system_models` lets
others through. `api::reachable($name)` is that one rule, for `/api` and
`vocabulary` alike. An override (`the_feed`)
is reached only by the name it overrides, and a public method it adds
answers 404 unless its `api()` lists it; that list adds to the bundled
model's, so the editor's endpoints stay. Posts
there pass the same site check as forms, but they are not form submissions:
`validation::get()->submitted()` is false, so form models do nothing over
`/api`. A method listed for any role but `visitor` answers 403
`{"error":"cross_site","problems":["cross_site"]}` to a request another site started, on every verb,
GET too: the session cookie (SameSite=Lax) comes along when someone follows
a link from another site. The browser says so with `Origin` (`Origin: null`
counts, unless `Sec-Fetch-Site` says it is this site), `Sec-Fetch-Site` or
`Referer` (`controller::is_foreign()`, the same test forms pass). A
method listed for visitors can be called by anyone, from anywhere: if it
changes data, it checks what it is given. `lint` reports an `api()` naming a method
that doesn't exist or can't be called, or a role that doesn't exist, and
`vocabulary` shows what each model offers.

A method that throws answers `{"error":"server error"}` with status 500,
and the error goes to the log with the URL, so a payment provider sees a
failure and tries again. Output the method printed before it threw is
dropped. A database that can't be reached answers 503 before the method
runs, so a webhook is never told "no such order" during an outage; a bad
query on a database that is there is a 500 like any other error. A call
with too few arguments answers 400 (`/api/cafe/bookings` for
`bookings($date)`). In development the answer also has `exception` and `trace`.

The database is RedBeanPHP (`R::dispense`, `R::store`, `R::load`) for rows
as objects, and named queries in `sql/` files for everything you would write
SQL for (see **SQL in files**). Values are always bound parameters, never
pasted into the SQL.

## Forms

A form posts to its own page. The render block around it is the model that
handles it. A feedback form, a rating and a comment kept for staff and
mailed to nobody:

```html
<!-- print.validation.alert('sent') --><p>Thanks, we read every one.</p><!-- /print.validation.alert('sent') -->
<!-- print.validation.alert('try_again') --><p>That went by a bit quickly. Send it once more.</p><!-- /print.validation.alert('try_again') -->
<!-- print.validation.alert('slow_down') --><p>That's a lot from one place. Try again in an hour.</p><!-- /print.validation.alert('slow_down') -->
<!-- render.feedback.send -->
<form method="post">
  <input type="number" name="rating" min="1" max="5" required>
  <!-- render.validation.field('rating') --><p class="error">Give us 1 to 5.</p><!-- /render.validation.field('rating') -->
  <textarea name="comment" maxlength="1000"></textarea>
  <button>Send</button>
</form>
<!-- /render.feedback.send -->
```

```php
class feedback {                                                  // application/models/feedback/feedback.php
    static function types() {
        // 'spam' => false: send() runs the spam checks itself
        return array('feedback' => array('fields' => array('rating' => 0, 'comment' => ''), 'create' => 'visitor', 'spam' => false));
    }
    function send() {
        $v = validation::get();
        if (!$v->submitted()) return false;                          // show the form as designed
        if (!$v->valid()) return template::instance()->form_state(); // again, with the values
        if ($reasons = validation_spam::holds($v->suspect())) return $v->hold($reasons); // looks like spam: again, with try_again or slow_down
        cms_records::create('feedback', array('rating' => (int)util::post('rating'), 'comment' => (string)util::post('comment')));
        util::done('sent');                                          // redirect; the alert shows
    }
}
```

A form that only stores what it is sent needs less: `function send() { return
cms_records::submit('feedback', 'sent'); }` does these steps, spam checks
included (leave out `'spam' => false`). For a contact form that emails staff,
use the bundled `contact` model (see **Bundled models**) rather than a model
of your own. Staff read the feedback on a page that renders
`render.cms.feedback` (see **Records**).

- **Rules live in the HTML.** `required`, `type` (email, url, number, date),
  `minlength`, `maxlength`, `min`, `max` and `pattern` are enforced on the
  server too. A field sent as a list (`name[]=x`) when the form doesn't name
  it `name[]`, or with bytes that aren't UTF-8, fails `required`, so the
  form's own words for an empty field show; a field named `tags[]` takes a
  list. A `pattern` that can't run counts as not matched. A line break
  counts as one character for `minlength` and `maxlength`, as the browser
  counts it, though it sends two (CR LF).
- **Uploads.** A form with `<input type="file" name="cv">` needs
  `enctype="multipart/form-data"`. Its model reads the file from
  `$_FILES['cv']` (`tmp_name`, `name`, `error`) and moves it out of PHP's
  temporary folder itself. `required` on a file input is met when the file
  arrived whole (`error` is `UPLOAD_ERR_OK`; of `photos[]`, any one); a
  file too big for the server never arrived, so it fails `required`.
- **What was typed comes back as typed** when the form is shown again:
  `$100`, `\1` and `$0` included. So do the choices: an `<option>` without
  a `value` is chosen by its text, as browsers send it (`<option>Ana</option>`
  for `stylist=Ana`), and a box ticked in the template that the visitor
  unticked stays unticked (the post doesn't name it).
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
  honeypot field, the session token for logged in users, and `raster_ts`, a
  signed time stamp (the time the form was shown and an HMAC of it with the
  site's key; see `RASTER_SECRET`). It is a hidden field like the others,
  never a field of the form's own, and it is left out of emails, static
  exports and forms with no render block around them. `suspect()` reads it
  (below).
- **Refused automatically:** posts from other sites (an `Origin: null`
  counts as another site unless `Sec-Fetch-Site` says it is this one), and
  bots that fill the honeypot. The bots get a fake success. A post from
  another site, and a logged in person's post without the session token
  (the form was open for too long), answer 403 with the alert
  `cross_site` or `form_expired`, for the site's `error_document_403` to
  word (see **Error pages**); nothing on that page handles the post.
- **Spam checks.** A form model asks after `valid()`, once per post:

  ```php
  if ($reasons = validation_spam::holds($v->suspect())) return $v->hold($reasons);   // the form again, as typed
  ```

  - `validation::get()->suspect($fields = null)` returns the reasons the
    post looks like spam, empty when it looks human. `$fields` are the
    fields looked at for links and blocked words: `null` is every field of
    the form that was posted, `array()` none. `array()` turns off every
    text check, the blocklist on the email's domain included (only the
    stamp and the limits run): a form that answers `try_again` would
    refuse a blocked address on every try. Asked twice in a request, it
    counts the post once.

    | Reason | When |
    |---|---|
    | `no_stamp` | the post has no `raster_ts` (a script that never fetched the form) |
    | `stamp_invalid` | its signature doesn't match (forged, or the key changed) |
    | `too_fast` | sooner than `spam_min_seconds` after the form was shown |
    | `stamp_old` | later than `spam_max_age` seconds after it: a replayed stamp |
    | `too_many_from_ip` | more than `spam_per_ip` posts of this form from this IP in the last hour; it flags the post and doesn't hold it (see `holds()`) |
    | `too_many_from_email` | more than `spam_per_email` posts of this form with this `email` in the last hour |
    | `too_many_links` | more than `spam_max_links` links (`http://`, `https://`, `www.`, `[url=`) in the checked fields |
    | `blocked` | a word of `spam_blocklist` in the checked fields, or a domain of it (an entry with a dot, and its subdomains) in their links or the email's domain; not checked at all with `$fields` `array()` |

    At most one stamp reason; every other check runs, in this order.
  - `validation_spam::holds($reasons)` keeps the reasons that turn a post
    away: all but `too_many_from_ip`. Many people can share one address (an
    office, a phone carrier, a proxy Raster can't see through), so over
    `spam_per_ip` a post is only flagged: the contact form keeps it as
    spam, the other forms take it. `spam_ip_blocks` true holds it back too.
    Pass `suspect()`'s answer through it before `hold()`.
  - `$v->hold($reasons)` raises `slow_down` when a reason is a limit
    (`too_many_from_…`), else `try_again`, and returns the form again with
    what was typed and a fresh stamp. Word both alerts in the view.
  - **Editors and admins** get no reasons and are not counted, so staff can
    test their own form. A form that wasn't sent gets none.
  - **A check that can't run passes:** no key, no table (a frozen
    database before `schema --apply`), a database error. It logs
    `spam: …` once per request, without the IP or the address.
  - **Counting.** Each post that reaches `suspect()` is counted, suspected
    or not, per form (`raster_form`), and the limit is passed when the
    hour's count, this post included, is above the setting: with
    `spam_per_ip` 5 the 6th post is the first `too_many_from_ip`. A post
    without a valid `email` is counted by IP only. Ask after `valid()`, so a
    person fixing a typo isn't counted.
  - **No raw IP is kept.** The counts (table `formhit`) hold
    `sha256(salt + ip)` and `sha256(salt + email)`, the email lower-cased,
    an IPv6 address by its /64. The salt (table `formsalt`) is made for the
    day in the site's `timezone`; at the next day it is deleted with every
    count made with it, so limits start again at midnight. Counts older
    than an hour are deleted as new ones are written. Both tables are the
    validation model's `schema()`: `schema --apply` makes them.
  - **Behind a proxy** every post comes from the proxy's address. A post
    from a trusted proxy is counted by `client_ip_header`
    (`X-Forwarded-For`, the last entry that isn't a trusted proxy; or one
    address, `Fly-Client-IP`). With `trusted_proxies` not set, every
    private, loopback or link-local address is trusted (`10/8`,
    `172.16/12`, `192.168/16`, `127/8`, `169.254/16`, `::1`, `fc00::/7`,
    `fe80::/10`): nobody on the open internet can post from one, so
    something on the same network forwarded the post. That covers Fly,
    raster-builder sites and most hosts with nginx or LiteSpeed in front,
    with no settings. It holds only when what is in front writes the
    header itself, setting or overwriting `client_ip_header`. Something
    that only forwards the connection (Docker's port proxy, a NAT
    port-forward, nginx in front of Apache without `proxy_set_header
    X-Forwarded-For`) passes on the visitor's own header, which a bot can
    change on every post: behind one, set `trusted_proxies` to `array()`. Set `trusted_proxies` (addresses or CIDR ranges, IPv4
    and IPv6) and only those are trusted; `array()` trusts nobody. An
    entry's port and brackets are left out (`1.2.3.4:5678`,
    `[2001:db8::1]:443`). A header that is missing or not an address keeps
    the proxy's. An IPv4 address written as IPv6 (`::ffff:203.0.113.7`)
    counts as the IPv4 address. Cloudflare's addresses are public: name
    them in `trusted_proxies` with `client_ip_header` `CF-Connecting-IP`.
  - **Many people on one address.** When one IP sends one form more than 5
    different emails in an hour, today's `formsalt` row notes it (a `pair`
    row in `formhit`, both hashes, goes with the counts), and in
    production `doctor` warns: "Many people seem to share one address. If
    the site is behind Cloudflare or another proxy, set `trusted_proxies`
    and `client_ip_header`." One person posting again and again doesn't.
    It can also be one sender trying many addresses, a bot rather than a
    crowd.
  - **What each bundled public form does with a suspect post.** They ask
    `suspect(array())` (stamp and limits, no text checks and no
    blocklist, the email's domain included) right after `valid()`, so a
    person fixing a typo isn't counted:

    | Form | A suspect post |
    |---|---|
    | `render.newsletter.signup` | nothing stored or mailed; the form again with what was typed, and `try_again` (stamp) or `slow_down` (a limit); `too_many_from_ip` alone is taken, unless `spam_ip_blocks` |
    | `render.authentication.register` | the same |
    | `render.booking.form` | the same |
    | `render.rsvp.form` | the same; it is asked before the form looks for an earlier reply with the address, so a post held back mails no link (`already_replied`) |
    | `render.authentication.forgot` | only the limits count: a post over `spam_per_email` (or over `spam_per_ip` with `spam_ip_blocks`) answers `reset_sent` as for an unknown address and mails nothing; the stamp is not checked |
    | `cms_records::submit` (a record form whose type has `create` `visitor` or `member`) | the form again with `try_again` / `slow_down` (as the newsletter: not for `too_many_from_ip` alone); checked before `create` is, so a bot learns nothing about which types need a login |
    | `render.contact.form` | stored as `spam` with its reasons (`too_many_from_ip` included), nobody emailed, and `sent` as for anyone (see **Bundled models**, contact); it also checks `name`, `subject` and `message` for links and blocked words, and the email's domain against the blocklist |

    Login, reset, account, logout, the booking and rsvp manage pages and the
    newsletter's unsubscribe aren't checked (login has a limit of its own,
    `login_per_ip`; see **Bundled models**, authentication). `lint` warns on a view with
    `render.newsletter.signup`, `render.authentication.register`,
    `render.booking.form` or `render.rsvp.form` that lacks `print.validation.alert('try_again')`
    or `('slow_down')`: word them where the person will see them, as for
    any alert. Put the pair once at the top of the layout's `res.header`
    (`lint` counts alerts in the fragments a view includes), so a held post
    shows its message once, and not again beside the form. A site's own form on `cms_records::submit` is covered too,
    but `lint` can't see which views post to it, so add the alerts there
    yourself. A script that posts without ever fetching the form gets
    `try_again`.
- **Forms that ask with the URL** (`<form method="get">`, a filter or a
  search) show what the URL asked: `/bookings?stylist=Ana` keeps Ana chosen,
  and `?stylist=` chooses only the option whose value is empty. Options,
  checkboxes and radios are chosen once the page is rendered, so an option
  a render block lists is chosen too (a `<select>` of
  `render.booking.resources`), and a mock-up option is never marked before
  it is copied into every row: for options, checkboxes and radios the
  URL's choice wins, in every row. A select without `multiple` gets one
  chosen option, the first that matches. Text inputs, hidden inputs and
  textareas are filled before: a value a model prints into one
  (`print.@value`) wins over the URL's, so a form per row keeps its own.
  Raster does nothing else to these forms; see `field=?name` in the CMS
  options.

## The CMS: the markup is the schema

`print.cms.<field>` declares a page field. Its default is the content inside
the block, and it is stored separately for each page. A page is a view's
URL or a literal route's; parameters, page numbers and filters don't make
new pages (`/blog/post/id/7` is `/blog/post`, see Routes). `render.cms.<name>`
declares a collection, whose fields are the `print` keys inside it. The first
item of a collection is the mock-up content. A collection whose items were
all deleted shows nothing, in development and production alike: the
mock-up is stored only when the collection's table is made.

```html
<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>
<!-- render.cms.news('order=newest&limit=3') -->
<h2><!-- print.headline -->First post<!-- /print.headline --></h2>
<!-- /render.cms.news('order=newest&limit=3') -->
```

- **Names:** lowercase letters, digits and `_`, starting with a letter.
  Reserved: CMS method names (`style`, `login`, …), `slug`, `id`,
  `updated_at`, `enabled`, `published_at` and `saved_lang` for fields;
  `users` and `raster` for collections; and any name with `__` in it, which
  joins a field and a language in a translation's column (`lint` error
  `double_underscore`, always, multilingual or not). `lint` reports these. A collection's table drops the
  `_`s (`team_members` is stored in `teammembersdata`), so `team_members`
  and `teammembers` would share one table and show each other's items:
  `lint` reports that as an error. Words SQL keeps for itself
  (`when`, `from`, `group`, `order`) are fine: lists sort and filter by them
  like any other field.
- **Site-wide fields:** a field whose name starts with `site_`
  (`print.cms.site_name`) is shared by every page. Put these in `_layout.html`.
- **Collection URLs** are routed to views that render that collection:
  - `/news/news_item/{slug}` renders `news_item.html` (or `news.html`)
    with that item; an item with no slug is at its id. `{slug}` is how
    `describe` and MCP's `site_overview` write an item's address too. If
    there's no such item, the response is a 404. A
    number is a slug first: an item titled `1984` opens at
    `/news/news_item/1984`, and a number no item has as its slug is an id.
  - `/news/news_page/2` is page 2. The page size comes from `news_page_size` or
    `raster_page_size` (10).
  - `/news/news_items/tag/php` lists the items where `tag` is `php`.
    Filters count only under the collection's own name, as its filter
    links write them; the list and its pager read them the same way.
  - `<name>_page` and `<name>_items` are these only after a page whose
    view renders the list (`/news/news_page` is `news.html`'s, with its
    page fields), or with something after them: `/landing_page` is the
    view `landing_page.html`, a page with fields of its own, apart from
    `/landing`'s.
- **Options:** `render.cms.news('featured=1&order=newest&limit=3')`, pairs
  joined by `&`. They work the same in `pagination.links('cms.news', '…')`:
  the list and its pager are built from the same conditions, so the pager
  counts the rows the list shows, drafts for editors, `owner=me` and a
  private type's records for whoever may read them (visitors get no pager
  over private records).
  - `order` is `newest`, `oldest`, `<field>` or `-<field>` (descending), or
    several separated by commas: `order=date,time`, `order=-date,-time`.
    `oldest` is the order items were added, not a date: to sort by a date
    field, name it.
  - `limit=3` shows at most 3, the same 3 on every page: a limit list is
    one page, and never moves with a paged list beside it.
  - `field=value` is a filter, and adds that field if it's new.
  - `field=?name` takes the value from the URL's `?name=`; when the URL has
    none, or it is empty, that filter is left out. So a plain
    `<form method="get">` filters a list with no model code, and the form
    keeps what was chosen.
  - `field>=value`, also `>`, `<`, `<=` and `!=` (which keeps empty fields).
    `today` is the date the page is shown (`2026-10-03`), in the site's
    time zone (setting `timezone`): `date>=today` is what is coming,
    `date<today` what is past. Values compare as the
    field's type (see **Types**): `price<10` compares numbers. A value
    that can't be of the type (`?max=lots` for a number) matches nothing.
  - A new item added to the list in the page starts with the values its
    `=` filters ask for, as the URL gives them.
- **Types.** Every field is text, `int`, `number`, `bool`, `date`,
  `datetime` or `time`, and its mock-up says which: `14` is an int, `4.50`
  a number, `2026-10-10` a date, `2026-10-10 19:00` a datetime, `19:00` a
  time. Anything else is text, so `14 lei`, `0721 000 000` and `Jazz night`
  are. A collection's fields take the mock-up of its own view (`news.html`,
  else `news_item.html`), the one its first item is stored from.
  - Whoever writes (the editor, MCP, a form, a model), the value is stored
    as its type: `4.5` for `4.50`, `2026-10-05` for `5 Oct 2026`, `20:00`
    for `8pm`, `1` for `yes`. One that can't be (`many` for an int,
    `2026-02-30`, `x` for a date) is refused with the field and an
    example. An empty value is `null` (`false` for a bool, `''` for text).
    A form's ticked checkbox is yes, whatever its `value`.
  - Lists filter and sort by type: `order=-price` puts 100 above 18 above
    9.50.
  - Models, MCP and `/api` read ints, floats and bools. Templates print
    text: a number with as many decimals as its mock-up (with `14.50` in
    the mock-up, 4.5 prints `4.50`, a page field's as an item's), a bool `1` or `0`, an empty value
    nothing, and what visitors typed as text. That holds for records
    printed through `render.cms` and `cms_records::listed()`. Rows a model
    hands back itself follow the plain rules: `false` keeps the mock-up
    (so a `newsletter` false prints the mock-up's `1`), and values print
    as they are, unescaped. A number in such a row still prints with its
    mock-up's decimals (`24.0` under `24.00` prints `24.00`). `lint` warns
    on any page that shows `find()` rows without `listed()`. The in-page
    editor shows what was stored the same way.
  - `schema`, `describe` and `site_overview` list each field's type. When
    a mock-up changes type (`14` becomes `14 lei`), `schema` shows the
    column as retyped and `schema --apply` converts it if every stored
    value fits; otherwise it names the values that don't.
- **Items** get a `slug` made from their title, headline or name (else
  their first text field; never a record's hidden fields, which a public
  URL must not carry). They also
  have `enabled` (`0` makes a draft) and `published_at` (a future date
  schedules the item). Visitors don't see drafts or scheduled items; editors
  do. `published_at`, `created_at` and `updated_at` are the site's wall
  clock in its time zone (setting `timezone`, else PHP's, UTC on most
  servers); the editor's Schedule box and its live/scheduled badge use the
  site's clock too, whatever zone the editor's browser is in.
- **Translations** (multilingual on, see **i18n**). A translated field
  keeps the default language in its own column and each other language in
  `<field>__<code>`: `headline__ro`, `name__pt_br` (`-` becomes `_`),
  `name__id`, of the field's type. `cms_types::column($field, $lang)` is the
  one place that name is made. Which fields are translated is read from
  how the markup prints them (`cms_types::translates($type, $attribute)`):
  a text field printed as content, or into `@alt`, `@title`,
  `@placeholder`, `@aria-label` or `@content`. Every other attribute
  (`@src`, `@href`, `@data-*`, …) and every int, number, bool, date and
  time is one value for every language, and so is a field printed in
  several places unless every one of them is a place for words (a URL
  shown as a link's text and in its `href` stays one value). The content
  model (`raster_inspector::content_model()`) gives each field
  `'translate' => true|false` by this rule; a record type's fields are
  shared for now. `cms_types::translated($table)` names the fields that
  have translation columns, as the database has them (empty when off).
  `cms_types::ensure($table, $types, $translated)` makes the columns, in
  development and with `schema --apply`; with multilingual off it adds none.
  Page tables and `sitepage` get them, and a `saved_lang` column (the
  language a revision was saved in, managed by Raster); collections' items
  come later. Raster makes a translation column before anything is stored
  in it (every write path declares it first), which is what keeps
  `name__id` a plain text column. A column RedBean creates itself takes its
  type from the first value stored, and one ending in `_id` also gets an
  index and an attempt at a foreign key to a table `name_`, and on MySQL
  is made an integer column, which refuses text.
- **Relationships are by value.** `print.@href.raster_filter@author` links to
  the items with the same `author`. For real references, write a model.
- **Page edits are versioned:** every save is a new revision. Values can be
  HTML. An empty value shows the template default.
- **Page fields in a language** (`multilingual` on, see **i18n**): a
  translated field (`text` printed as content, or in `@alt`, `@title`,
  `@placeholder`, `@aria-label`, `@content`) keeps each translation in a
  column of its own (`headline__ro`); the others (photos, links, numbers)
  are shared, one value for every language. On `/ro/…` a page or site
  field shows its Romanian, and the default language's value while that
  is empty (`cms_store::localized($row, $type, $lang)`, for showing only,
  never for a write). In development a field's first print makes its
  translation columns, when every place the page (and the layouts dried
  into it) prints it is a place for words, as `schema` reads it. `cms_store::update_page($type, $slug, $values,
  $allowed, $lang)` writes `$values` (named by the fields themselves,
  `headline`) in `$lang`: a translated field into its column, and a shared
  one is refused with `field_shared`, nothing stored; without `$lang` it
  writes the default language, as always. Each revision keeps the
  language it changed in `saved_lang` (`page_history` lists it), and
  restoring one brings back the whole row, every language's translations
  included. Off, none of this happens: no column, no `saved_lang`, the
  event as it was.
- **Changing fields.** A new annotation adds a column: on the next request in
  development, or with `php bin/raster schema --apply` in production.
  `schema` shows orphaned columns and suggests
  `--rename=table.old:new`, which moves the content across.
  `--drop=table.column` or `--drop=table` deletes something no template uses;
  `--force` overrides that check. Either takes only CMS page and collection
  tables: the accounts and subscribers, a table a model declares with
  `schema()`, and a record type's table and the columns its `types()`
  declares are never dropped, `--force` or not, and are never listed as
  unused. `--dry-run` says what `--rename`, `--drop` and `--apply` would
  change and changes nothing. `--check` exits 1 when the templates and the
  database differ (for CI).
  - With multilingual on, a translated page field lacking its column in a
    language, or a page table lacking `saved_lang`, is drift (marked `+`,
    `missing_translations` in `--json`, with the table's `translated`
    fields): `--apply` adds them, empty, so each language shows the default
    text until it is translated. A translation column of a field no
    template has any more is an orphan with it.
  - Translation columns no language uses now (multilingual off, a language
    taken out of `languages`) are **kept translations**: listed per table
    (`translations` in `--json`, there when multilingual is on or some are
    kept), never orphans, never drift, never suggested for `--drop` or
    `--rename`. Turning multilingual on again brings them back.
  - `--rename=table.old:new` moves the field's translation columns with it
    (`old__ro` to `new__ro`, kept ones too), and `--dry-run` lists them.

## Records: types a model declares

Collections declared by markup are content: editors write them, visitors read
them. Bookings, orders and applications are **records**: a model declares
what they hold and what makes them valid, and the CMS stores them, shows them
wherever a view renders them, and lets editors and agents change them with
the same in-page editor and MCP tools as any item.

```php
class inquiry {                                        // catering requests
    static function types() {
        return array('inquiry' => array(
            'fields'   => array('name' => '', 'email' => '', 'date' => '', 'people' => 0, 'message' => '', 'status' => 'new', 'dishes' => array()),
            'types'    => array('date' => 'date'),   // the rest come from the defaults: 0 an int, '' text
            'create'   => 'visitor',           // who may send the form: visitor, member, editor (default)
            'staff_add' => true,               // staff also add them in the page (one taken over the phone)
            'owner'    => true,                // remember who made it; they may read their own
            'public'   => false,               // the default: only editors (and owners) see records
            'readonly' => array('status'),     // shown to editors, written only by the model
            'hidden'   => array(),             // never shown to editors or agents, nor made a slug (payment ids, tokens)
            'html'     => array(),             // fields printed as HTML; the rest print escaped
            'spam'     => true,                // false: the model runs its own spam checks (see Forms)
            'actions'  => array('answer' => 'editor', 'archive' => 'admin'),
        ));   // a type's name is lowercase letters and digits; no field x next to x_id
    }
    static function check($type, $after, $before) {   // every write passes here
        $problems = array();
        if ($after && $after['people'] < 10) $problems[] = 'too_few';   // catering starts at ten
        return $problems;                              // names of alerts, or nothing
    }
    static function answer($item, $input) {            // an action
        return cms_records::update('inquiry', $item['id'], array('status' => 'answered'));
    }
    function form() {                                  // the form: render.inquiry.form
        return cms_records::submit('inquiry', 'sent');
    }
}
```

Bookings (a café's tables, a salon's appointments) need no model of their
own: the bundled booking model has the type, its rules and its emails (see
**Bundled models**, booking). A site adds a field to it with a
`the_booking` override.

- **The type is a collection** named after it (table `inquirydata`):
  `render.cms.inquiry('order=newest')` lists records, item URLs and
  filters work, and `site_overview`, `describe` and `schema` list it with the
  model that declares it. Its fields come from `types()`, not the markup, and
  no mock-up row is ever stored. Records also get `created_at` and, with
  `owner`, the account id in `owner`.
- **Field types** come from the defaults: `0` is an int, `0.0` a number,
  `false` a bool, `''` text. `'types'` names the others: `'date'`,
  `'datetime'` or `'time'` (or any type, overriding the default's). The
  model reads them as such (`$after['people'] < 10`, `if
  ($booking['newsletter'])`), with no casts. See **Types** above.
- **`check($type, $after, $before)` runs before every write**, whoever makes
  it: a form, the in-page editor, MCP, or the model's own code. `$after` is
  the whole record as it would be stored (`null` when deleting), `$before` as
  it was (`null` when creating). Return the names of what is wrong; each is an
  alert the template words (`<!-- print.validation.alert('too_many') -->`).
  `cms_records::refuse('name')` stops a write from anywhere in the same way.
  Write problems as `$problems[] = 'name'` or `refuse('name')` so `lint`
  knows the alerts are raised. `cms_records::writer()` says who makes the
  write while `check()` runs: `'visitor'` (a form), `'editor'` (the in-page
  editor, MCP) or `'model'` (the model's own code), so a rule can be for
  guests only.
- **`fill($type, $after, $before)` works out fields the model owns** before
  `check()` runs, on every write: it returns `array(field => value)`, which
  is stored as the fields' types (a booking's `ends` from its `time` and
  service, a `changed_at`). It sees the record as `check()` does, and
  `cms_records::writer()` too. Make such fields `readonly`, so only the
  model sets them; keys that aren't fields are left out.
- **Hooks are static** — `types()`, `check()`, `fill()`, actions — so
  `/api/<model>/<method>` can never call them. `lint` reports a `check()` or
  `fill()` that is not static and an action without its method. (Event listeners named in
  `listens()` are ordinary public methods; /api reaches them only if the
  model lists them in `api()`, which it shouldn't.)
- **Privacy.** A type is private unless it says `'public' => true`: visitors
  get no rows from `render.cms.<type>`, item URLs are 404, and `feed.items`,
  the sitemap and a static export leave it out. Editors see every record;
  with `owner`, a logged in person sees their own. A page listing someone's
  own records (an account page) filters with `owner=me`:
  `render.cms.order('owner=me&order=newest')` shows the logged in person
  what they made, editors included, and visitors nothing, on a public type
  too. Records print what
  visitors typed as text: every field is escaped unless the type lists it in
  `html`, and a link a visitor typed (`print.@href.website`) can't be a
  `javascript:`, `data:` or `vbscript:` URL, also when an entity, a tab, a
  newline or a control byte hides the scheme. Visitors can't filter a public type by its hidden fields
  (`/guestbook/guestbook_items/email/…`).
- **Forms.** `cms_records::submit($type, $done)` is the whole handler: it
  shows the form (`false`), shows it again with the values when the HTML
  rules fail, keeps only the fields people may write (declared, not readonly
  or hidden; a field the form doesn't send keeps the type's default), runs
  `check()` and raises each problem as an alert, or stores
  the record and redirects with `?done=$done` (the type's name by default).
  A value the HTML rules let through but the field's type doesn't (`2.5`
  people in a number input) raises `<field>_invalid` (`people_invalid`).
  `$done = false` returns the stored record instead, for a checkout that goes
  on to pay. A visitor who may not create gets `login_required` (member
  types) or `not_allowed`. For a type that visitors or members create,
  `submit()` first asks `suspect(array())` (see **Forms**): a post that
  looks like spam (`holds()`) gets the form again with `try_again` or `slow_down`, so
  word both. A type that runs its own checks says `'spam' => false`.
- **Editors and agents** change records like items, except that readonly
  fields are shown and not editable, hidden fields never reach them, and the
  item's handle has the type's actions instead of Duplicate and Schedule.
  Deleting a record has no undo. The card for a new item at the end of a
  list appears for types staff make themselves (`create` is `editor`, the
  default), and for types that say `'staff_add' => true`: a café takes
  bookings and catering requests from its forms and over the phone. Orders, which come with a
  payment, leave it out. A refused write answers 422 with
  `problems`; MCP returns an error naming them.
- **Actions** are what staff do to a record beyond changing a field. The type
  maps each to the least role that may run it (`editor` or `admin`);
  the model has `static function <action>($item, $input)` that writes through
  `cms_records` and returns the record, or refuses. `$item` is the record as
  the action started; an action that depends on it (only an unpaid order can
  be cancelled) reads it again inside `cms_records::transaction()` with its
  writes, and keeps calls to other services (a refund at the payment
  provider) outside that transaction. The in-page editor shows a
  button for each action the person's role allows (`POST
  /api/cms/editor_action`); MCP has `run_action`, trusted like an admin. Name
  a button in the site's words with `application/i18n/<lang>/raster_editor.php`:
  `'action_confirm' => 'Confirm booking'`, and a refusal with `'problem_<name>'`
  (`'problem_closed' => 'We are closed that day'`; the demo's
  `demo/i18n/en/raster_editor.php`).
- **Every form that saves data gets a staff page.** When a type's records
  come from a form (`create` is `visitor` or `member`), also make a view that
  lists them, `render.cms.<type>('order=newest')`, and protect it for
  editors: `config::set('protected')->to(array('inquiries' => 'editor'))`.
  Without it the records are stored and nobody can see them (`lint` warns).
  Staff confirm, edit and delete them there with the in-page editor and the
  type's actions. The editor's Admin menu lists it, so it needs no link in
  the site.
- **Staff pages keep the in-page editor working**, in this order:
  1. **List options** first. Filters, tabs and sorting need no model code:

     ```html
     <form method="get">
       <select name="resource"><option value="">Anyone</option><option value="ana">Ana</option></select>
       <input type="date" name="day"> <button>Show</button>
     </form>
     <!-- render.cms.booking('date>=today&resource=?resource&date=?day&order=date,time') -->…<!-- /render.cms.booking('date>=today&resource=?resource&date=?day&order=date,time') -->
     ```

     A second list with `date<today` and `order=-date,-time` shows the past;
     for tabs, make a page each. Don't store what the page can work out (a
     `period` field goes stale the next day), and don't filter in the
     browser.
  2. **A model view** for what options can't say: totals, an agenda by day,
     values worked out per row, records of several types. The model reads
     the records and hands them to the template through
     `cms_records::listed($type, $rows)`, which prints them as text and
     marks each as its record, so staff edit them and run their actions in
     place. It works for the rows a render method returns and for lists
     inside them:

     ```php
     function by_day() {                       // render.inquiry.by_day
         $days = array();
         foreach (cms_records::find('inquiry', 'date>=today&status!=archived&order=date') as $i) $days[$i['date']][] = $i;
         $rows = array();
         foreach ($days as $day => $inquiries) $rows[] = array('day' => $day, 'inquiries' => cms_records::listed('inquiry', $inquiries));
         return $rows;
     }
     ```

     ```html
     <!-- render.inquiry.by_day -->
     <h3><!-- print.day -->2026-10-01<!-- /print.day --></h3>
     <ul><!-- print.inquiries --><li><!-- print.name -->Ana<!-- /print.name --></li><!-- /print.inquiries --></ul>
     <!-- /render.inquiry.by_day -->
     ```

     The model decides which records show. Values it adds to a row print
     but aren't editable. New records are added from a `render.cms` list or
     the record's page, not from a model view. The bundled booking model's
     `render.booking.agenda` and `render.booking.attention` work this way.
  3. **Links to each record's own page**, `print.@href.raster_detail_link`
     (`/inquiry/inquiry_item/{slug}`, shown by a view that renders
     `render.cms.inquiry`), where editors edit it. Every listed row has one.
  4. Ask only when none of these can show what is needed.

  Rows a model returns from `cms_records::find(…)` without `listed()` can't
  be edited in the page, print a `false` bool as the mock-up and what
  visitors typed unescaped; `lint` warns about them on every page (on admin
  pages, about the editing too). Check
  the page with `php bin/raster render /inquiries --as=editor` (MCP
  `render_url` with `as`), which says what the editor can edit there.
- **The model's own code** writes with `cms_records::create($type, $values)`,
  `update($type, $id, $values)` and `delete($type, $id)` (these may write
  readonly and hidden fields and `owner`, and still pass `check()`), and reads
  with `get($type, $id)` and `find($type, $filters, $order, $limit)`.
  `$filters` is `array('field' => 'value')` (equal values) or list options
  as `render.cms` takes them, `'date>=today&status!=cancelled'`, with
  `order=` and `limit=` in them too; `$order` is as in list options
  (`'date,-time'`, oldest first by default). The values are values: a
  `?resource` (the URL's, in a template) throws `InvalidArgumentException`,
  so pass `util::param()` or `$_GET` yourself. `owner=me` (or
  `array('owner' => 'me')`) is the logged in person's records, and none
  when nobody is logged in. `find()` returns every record
  that matches unless `$limit` (or `limit=`) says how many, so ask for
  only what the page shows. It hands records to a template through
  `listed($type, $rows)` (see staff pages above).
  A field whose default is `array()` is a list (line items), stored as JSON
  and handed back as a list; inside `render.cms.<type>` its rows repeat like
  any nested rows.
- **Transactions.** `cms_records::transaction(function () { … })` makes every
  write in it happen, or none: an exception or a refusal rolls all of them
  back. The database is locked for writing from the start (SQLite with
  `BEGIN IMMEDIATE`, MySQL with a named lock per database), so two
  checkouts can't both take the last item. A write that waits more than 5
  seconds for the lock fails with "database is locked". Events (`cms.item_saved` and the
  rest) wait for the commit, so nothing is emailed about a write that was
  rolled back. So does `util::content_changed()`: the page cache is thrown
  away and `content_changed` sent once, after the commit, and not at all
  after a rollback. Every single write of a record is already one (its
  `check()` and the write together), and so is every save or delete of a
  markup collection's item and every page save: two items saved
  at once with one title get different slugs, and two page saves at once
  (one field each) keep each other's change. So is a newsletter sign-up
  (`newsletter::subscribe()`): sign-ups at once with one address
  leave one subscriber. A transaction started inside another joins it. On
  MySQL a write that adds a table or column (development) commits what
  came before it, as MySQL does on any schema change.
- **Starting from a form:** `php bin/raster make model inquiry --from=contact.html`
  writes `models/inquiry/inquiry.php` with the type (fields from the form's
  inputs, passwords left out), an empty `check()` and the handler. Each
  field takes its input's type: a number input an int, or a number (`0.0`)
  when its `step` has decimals or is `any`; a checkbox a bool; `date`,
  `time` and `datetime-local` inputs go in `'types'`. What it prints next
  names the `<field>_invalid` alert of each typed field, for the view to
  word.
  `--form=<method>` picks one of several forms, `--dry-run` prints it.
- **In production** `php bin/raster schema --apply` creates the table with
  every declared column, and no row.
- **A worked example:** `shop/` in the Raster repository is a small shop
  built this way: public products, private orders with an owner, a
  checkout that takes stock in a transaction, actions to ship, cancel and
  refund, and a payment provider's webhook. See `shop/README.md`.
- **Calls from other sites.** A payment provider's webhook is a server posting
  to `/api/<model>/<method>`, listed in `api()` for `visitor`: it sends no
  browser headers and no session, so the cross-site check lets it through.
  The method reads the body with `file_get_contents('php://input')`,
  verifies the provider's signature itself, sets `http_response_code()` when
  it refuses, and returns what to answer (as JSON).

## Bundled models

A bundled model's record types (`types()`) and needs (`requires()`) count
only on a site whose views use the model (`render.<name>.…`,
`print.<name>.…`, in any theme); the tables in its `schema()` always count.
A site's own model or type keeps its name: when a site type is called like a
bundled model in use, or like one of its types (a site's `booking`), that
bundled model declares none of its types, and `lint` warns.

**authentication**: accounts, with every screen written in your templates.
- Regions:
  - `render.authentication.login`: fields `login` (email or username) and
    `password`; respects `?next=/path`.
  - `register`: `name`, `email`, `password`.
  - `forgot`: `email`. Sends `_email/password_reset.html`, with
    `print.self.reset_url` and `print.self.name`. Asked again within 5
    minutes (`mail::RESEND_AFTER`), it answers the same and sends nothing:
    the link already sent still works.
  - `reset`: `password`, opened from that email's link.
  - `account`: `name`, `email`, `password`, `current_password`.
  - `logout`: a form with a button.
  - `me`: rows with `name`, `email`, `role`.
- Alerts: `login_failed`, `email_taken`, `email_invalid`, `password_short`,
  `reset_sent`, `reset_invalid`, `registered`, `password_changed`,
  `account_saved`, `current_password_wrong`; `try_again` and `slow_down` on
  `register` (a post that looks like spam; see **Forms**); `slow_down` on
  `login` (over `login_per_ip`).
- Flags: `if.logged_in`, `if.logged_out`, `if.is_member`, `if.is_editor`,
  `if.is_admin`.
- Roles: `admin`, `editor` (edits content), `member`.
- Settings: `config::set('protected')->to(array('account' => 'member'))`
  (each pattern matches the start of the URL and of the view it renders,
  ignoring case: `Faq` protects `/faq`. The Admin menu, the sitemap, the
  editor's saves and `lint` match it the same way. A view protected for
  `editor` or `admin` is an admin page, see **Editors and agents**),
  `registration` (false turns sign-up off), `login_page`, `after_login`,
  `password_min_length` (8).
- A protected page answers a visitor with a 303 to the login page, and
  `raster render` (MCP `render_url`) does the same: it prints `Location:
  …` and `HTTP 303` on stderr, no page, and exits 1. `--as` renders it
  as that person sees it. Someone logged in without the role gets 403,
  the site's `error_document_403` with the alert `not_allowed` (see
  **Error pages**).
- The login page says why nobody can log in yet with two alerts that
  `print.cms.login_message` raises (it prints nothing itself):
  `no_database` (none is configured for this environment) and `no_users`
  (make one with `php bin/raster user`). The starter's `login.html` words
  both, and `raster upgrade` adds them to an older view that has the tag.
- Five wrong passwords lock an account for 15 minutes, at the login and
  in the account form's `current_password` alike. Once 15 minutes have
  passed since the last wrong one, counting starts again from zero. Each
  try is counted before the password is checked, inside the database's
  write lock, so guesses sent at once each count. The lock counts per
  account; `login_per_ip` (20) counts tries per address, any account and
  none: over it in an hour, a login answers `slow_down` and checks
  nothing, so one sender can't guess across many accounts or try which
  ones exist. It counts the address as the spam checks do (hashed, by
  `client_ip_header` behind a trusted proxy; see **Forms**).
- One email, one account: two sign-ups at once with one email (a double
  click) make one account, and the other answers `email_taken`; so do two
  members changing to one email at once, or a member changing to the email
  someone is signing up with. Looking for the email and storing the
  account happen in one transaction. From code,
  `authentication::create_user($login, $password, $role, $name)` makes an
  account, or returns null when the login is taken; `save_user(…)` creates
  or updates.
- Command line: `php bin/raster user <email|name> [--role=…] [--password=-]`
  and `php bin/raster users`. A new account is an admin with a random
  password unless the options say otherwise; an existing one keeps the role
  or password the command isn't given. `--password=-` reads the password
  from standard input (`printf '%s\n' "$pw" | php bin/raster user ana@example.com --password=-`),
  and so does `RASTER_PASSWORD` from the environment: a command's
  arguments are in the process list for every user of the machine.
  `--password=<value>` still works, with a note saying so.
- `php bin/raster login-link <email|name> [--next=/path]` prints a link
  (`<site>/?raster_login=<token>`) that logs that account in without a
  password, once, within a minute; then the page comes again without the
  token, or `--next` if it is a path on the site. It is for a host that runs
  the site, such as a site builder's Log in button. Logging out stays a
  form (`render.authentication.logout`): there is no logout link.

**newsletter**: sign-ups with double opt-in.
- Regions:
  - `render.newsletter.signup`: a form with `email`, and optionally `name`.
    Sends `_email/newsletter_confirm.html` with `print.self.confirm_url`.
  - `render.newsletter.confirm`: on the page `newsletter-confirm`.
  - `render.newsletter.unsubscribe`: on the page `newsletter-unsubscribe`, a
    form with a button. Mail apps' one-click unsubscribe works too.
  - `print.newsletter.count`.
  - From code: `newsletter::subscribe($email, $name, $source)` does what the
    form does (and sends the confirmation) and returns `pending`,
    `confirmed`, `already` or false. It looks the address up and stores it
    in one transaction, so sign-ups at once leave one subscriber;
    inside another transaction it joins it. Confirming and unsubscribing
    read and write the subscriber under the same lock, so a sign-up at
    the same moment never undoes them.
  - The confirmation email is sent after the commit by the framework's
    listener on `newsletter.subscribed` (status `pending`); a site that
    sends its own unbinds it in `config/the_events.php`:
    `event::unbind('newsletter.subscribed')->from('newsletter', 'confirmation_mail');`.
    Signing up again while pending answers the same; within 5 minutes
    (`mail::RESEND_AFTER`) of the last confirmation email it sends nothing,
    later it sends the same link again: every confirmation email sent still
    works. When it was sent is `subscriber.confirm_sent_at` (`schema
    --apply` adds it; until then a frozen database sends every time).
- Alerts: `check_email`, `subscribed`, `confirmed`, `confirm_invalid`,
  `unsubscribed`, `unsubscribe_invalid`, `try_again`, `slow_down` (a post that
  looks like spam; see **Forms**).
- **Sending an issue:** `php bin/raster send /news/news_item/my-post
  [--to=you@example.com] [--dry-run] [--again]` emails any page to confirmed
  subscribers. Scripts, forms and `<nav>` are removed, and links become
  absolute. Put `<a href="<!-- print.newsletter.unsubscribe_url /-->">` in the
  page so each reader gets their own unsubscribe link. A page is sent once
  unless you pass `--again`. Each address is noted (table
  `newsletterdelivery`) as its mail is handed over, so running the command
  again after it stopped, or after some addresses failed, mails only the
  confirmed subscribers who don't have it yet; the page counts as sent once
  a run reached everyone. `--again` mails everyone. A mail handed over just
  as the process was killed isn't noted yet, so that one reader may get it
  twice. One SMTP connection serves the whole run, and the transport is
  printed without its user, password or options.
  - From code: `newsletter::send_issue($url, $html, $subject, $again)`
    sends a page rendered with `RASTER_SENDING=1` and passed through
    `newsletter::prepare_issue()`. It returns false when the issue already
    reached everyone, else its `newsletterissue` row (`recipients`,
    `incomplete`); `newsletter::$sent` and `newsletter::$failures`
    (email => error) say what this run did. It makes its two tables, with
    the types `schema --apply` gives them, also on a frozen database, and
    on MySQL widens the small columns an older Raster made for them
    (`recipients` as TINYINT, `subject` as VARCHAR(191) or TEXT), as `--apply`
    does, so sites need no step for it.

**booking**: bookings for a café's sittings or a salon's appointments
(with or without "with Ana"), taken from the site's own pages. The site
writes its pages, its emails and the words of each alert; the model owns
the types, the rules, the status changes, the guest's link, the emails' data
and the events. Nothing in `the_app.php` is required. It counts only on a
site whose views use `render.booking.*` or `print.booking.*`. The demo café
(`demo/`) takes table bookings on `/visit` and a salon's on `/salon`, with
its setup from `php demo/seed.php`, and is the worked example of all of it.
- Setup, as records staff edit in the page (`render.cms.bookingresource`
  and so on; each is made by editors and private):
  - `bookingresource`: what is booked. `name`, `people` (most people at
    once in a slot), `bookings` (most bookings at once), `active` (false
    hides it). 0 is no limit: a café's Dining room has `people` 20, a
    stylist `bookings` 1.
  - `bookinghours`: when slots start. `resource` (a resource's slug, empty
    for every one), `days` (`daily`, or `mon,tue,…`), `starts`, `ends` (the
    last start; a booking may run past it), `every` (minutes between
    starts), `from`/`until` (a season, empty for always). A day's slots are
    the starts of every row that applies, each once; sittings are a large
    `every` (120), a salon's grid a small one (15).
  - `bookingclosed`: days off. `date`, `until` (a range, both days
    included), `resource` (empty closes every one), `note`.
  - `bookingservice`: what a booking is for. `name`, `minutes`,
    `resources` (slugs that offer it, comma separated; empty for all),
    `active`. Without services a booking lasts the `every` of its hours row
    (a sitting lasts until the next one); a start in several rows keeps the
    smallest `every`.
  - Refused with: `every_invalid` (every < 1), `hours_invalid` (no starts or
    ends, or starts after ends), `days_invalid`, `season_invalid` (from
    after until), `limit_invalid` (people or bookings below 0),
    `minutes_invalid` (below 1), `closed_invalid` (no date, or until before
    it).
- Regions (options read `?x` from the URL; empty ones are left out). With
  one active resource it is the one booked; with several, slots and days
  give nothing until one is chosen, and nothing until a service is chosen
  when the resource offers any:
  - `render.booking.slots('date=?date&resource=?resource&service=?service&people=?people')`:
    one day's start times, as rows with `date`, `time`, `ends`,
    `left_people` and `left_bookings` (`''` where that limit is 0), `full`
    (`'1'`/`'0'`) and `state` (`free`/`full`, for `print.+class.state`). A
    GET form picks the day; the page lists the times as radio buttons. No
    JavaScript.
  - `render.booking.days('resource=?resource&service=?service&limit=14')`:
    the next days with a free slot, `date` and `slots_free`.
  - `render.booking.resources('service=?service')` and
    `render.booking.services('resource=?resource')`: the active ones,
    editable in the page.
  - `render.booking.form`: the booking form, posting fields `name email
    phone date time people resource service notes` (a `the_booking`
    override adds more). It stores a request and redirects with
    `?done=booked`, or shows the form again with the alerts below. Shown
    first, it is filled from the URL's `date`, `time`, `resource`, `service`
    and `people` (a field the form doesn't have comes back as a hidden
    input), and it raises `not_open` while no resource is active. The
    guest gets `booking_requested` with their link, staff `booking_new`.
    `lint` warns on it while there is no `bookingresource` yet.
  - `render.booking.manage`: the guest's page (the `booking` view, or
    `booking_manage_page`), one row: the booking without its hidden fields,
    with `resource_name` and `service_name`. See **The guest's link**.
  - Slots and days are never page-cached, and count the bookings made.
    On the manage page, `render.booking.slots('date=?date&for=manage')`
    lists a day's times for the managed booking: its resource, service and
    people, and its own place not counted against it.
- Bookings are the `booking` type: `name`, `email`, `phone`, `date`, `time`,
  `people` (1 by default), `resource` (a slug), `service` (a slug or empty),
  `notes`, and what the model works out (readonly): `ends`, `status`,
  `changed_at`. `token_hashes` and `token_expires` are hidden. Guests make
  them with the form, staff add them in the page (`staff_add`) or over MCP,
  and with `owner` a guest who was logged in reads their own. Private.
  - On every write the model fills (`fill()`) `ends` from the service, or
    else the `every` of the hours row the time starts in; the `resource`
    when there is only one; `people` 1 when no resource counts people; and
    `changed_at` when the status, date or time changes.
  - Status: a booking is `requested`, holding its place, until staff run
    `confirm` or `decline` (the actions; `cancel` too). `declined` and
    `cancelled` are final and free the place: changing the status from
    them is refused with `already_declined` / `already_cancelled`, and a
    confirmed booking can't be declined (`already_confirmed`). Each action
    reads the booking again in its transaction, so one cancelled a moment
    ago isn't confirmed from a page that still showed it.
  - The slot's rules run when a booking is made, when its `date`, `time`,
    `resource`, `service` or `people` change, and when it is confirmed, in
    this order: `not_open` (no resources), `resource_invalid` (none chosen
    among several, or an unknown one), `resource_inactive`, `name_invalid`
    (empty), `email_invalid` (a guest's empty or anyone's invalid address),
    `people_invalid` (below 1); `too_many` (a guest's over
    `booking_max_people`); `not_a_slot` (not one of the day's starts, a
    closed day, or ending after midnight); `service_unavailable` (none
    chosen where the resource offers some, or one it doesn't offer or that
    is off); `too_soon` / `too_far` (guests: within `booking_notice`, past
    `booking_horizon`); `already_booked` (a guest's second booking of the
    same start: a double click); `fully_booked`. A booking form post that
    looks like spam raises `try_again` or `slow_down` first (see **Forms**),
    word both on the page. Any other edit (a phone
    number, a note) goes through, so a booking stranded by a change of the
    setup never gets stuck.
  - Capacity: at the busiest minute of the booking's span, the other active
    bookings of its resource and day plus this one fit `people` and
    `bookings` (whichever is above 0). The booking being changed doesn't
    count against itself, so a move frees the old place as the new one is
    checked. It is one query (`sql/peak.sql`) inside the write's lock, so
    two guests at once can't both take the last place.
  - Staff (the in-page editor, MCP, or model code) skip `too_soon`,
    `too_far`, `too_many` and `already_booked`, and may book in the past (a
    walk-in), but never over capacity and never off the slots.
  - Needs attention: an active booking the setup no longer fits, with the
    first reason of `closed`, `resource_inactive`, `service_unavailable`,
    `not_a_slot` and `over_capacity` (some minute of it holds more than the
    limit; each booking there is flagged). Staff may close a day, change
    the hours or lower a limit under bookings: nothing is refused or
    cancelled. `confirm` is refused with the reason; `cancel`, `decline`
    and moving it are not.
  - A resource's or service's slug stays while an active booking dated
    today or later uses it: a change of slug or a delete is refused with
    `resource_in_use` / `service_in_use`. Its name may change.
- For staff (editors; anyone else gets nothing, wherever the page puts
  them):
  - `render.booking.agenda('from=today&days=7&resource=?resource')`: a row
    per day, with `date`, `closed` (`'1'`/`'0'`), `slots` (as in
    `render.booking.slots`, with the places left, for the resource asked
    or the only one; no notice or horizon) and `bookings`: that day's
    active bookings through `cms_records::listed`, editable in place, each
    with `attention` (the reason, or empty), `resource_name` and
    `service_name`.
  - `render.booking.attention`: the active bookings to come that need
    attention, with the same added fields.
  - `print.booking.count('status=requested')`: how many bookings match
    (options as `cms_records::find` takes them, values only), or
    `count('attention')`: for the staff page's heading.
- Times are the site's clock (`timezone`). A start the clock skips in spring
  isn't a slot, the hour repeated in autumn gives each time once, and a
  booking ends by midnight (a start whose span would end later isn't a slot;
  an end at midnight is `00:00`). `booking_notice` counts real minutes, so
  on the spring day 04:00 is 30 minutes after 02:30.
- From code: `booking::day($date, $resource, $service, $people, $taken,
  $now)` gives the slot rows (`$now` false: as staff see the day),
  `booking::starts($date, $resource)` the start times,
  `booking::peak($rows, $from, $to)` the most people and bookings at once
  over minutes `[$from, $to)` (spans are half-open: one ending at 10:00 and
  one starting then never meet). `booking::move($id, $values, $who)` moves
  a booking (`date`, `time`, `resource`, `service`, `people`) in one write;
  a guest's move (`$who` `'guest'`) gets the guest rules and the cutoff and
  goes back to `requested`, staff's keeps the status; either sends
  `booking.rescheduled` and the guest a new link. `booking::reason($booking)`
  says why it needs attention, `booking::by_token($raw)` gives the booking a
  link is for (or null). Model code that writes bookings with
  `cms_records::create/update` is trusted like staff, and its new bookings
  get `booking.requested` with `who` `staff`.
- The guest's link. Each email to the guest carries a new one in
  `manage_url`: `<booking_manage_page>?token=<64 hex>`, 32 random bytes sent
  only in that email. The booking keeps their sha256 (the newest 20, in
  the hidden `token_hashes`), so the first email's link still works after
  the confirmation and follows the booking when it moves. A link works
  until the booking starts (`token_expires`) and is dead once it is
  declined or cancelled (their emails have no link); a dead, unknown or
  malformed one raises `link_invalid`.
  - A GET shows the booking and changes nothing, so a mail scanner opening
    the link does no harm. The page's forms post to the page itself (the
    token stays in the URL, never in a hidden field), with `do=cancel`, or
    `do=change` and the new `time` (the day from `?date=`, from a GET form
    that keeps `token`). The cross-site check applies.
  - Within `booking_cutoff` minutes of the start the page raises
    `too_late` (tell the guest to phone) and refuses both.
  - A guest's change is checked as a guest's booking (`fully_booked`,
    `too_soon`, …, raised on the page), goes back to `requested` and emails
    `booking_changed` to the guest and `booking_new` to staff; the same time
    again writes and sends nothing. Afterwards the page redirects to
    `?done=cancelled` / `?done=rescheduled` without the token, and shows
    only that alert.
  - A logged in guest manages their own booking (its `owner`) by
    `?booking=<id>`, without a token; the redirect keeps `?booking=`.
  - The page is never page-cached, sends `Referrer-Policy: same-origin`, and
    is left out of the sitemap and `raster export`. The raw token is in no
    event, log, record or cache: the model hands it to its own email
    listener within the request. Don't protect `booking` for staff
    (`protected`): `lint` warns, since guests would get the login page.
- Emails, after the answer (the site writes the views, and `lint` reports a
  missing one where `render.booking.form` or `render.booking.manage` is):

  | View | To | When |
  |---|---|---|
  | `_email/booking_requested` | guest | a booking is made, by the guest or by staff |
  | `_email/booking_new` | staff | the guest made a booking or moved it |
  | `_email/booking_confirmed` | guest | confirmed |
  | `_email/booking_declined` | guest | declined |
  | `_email/booking_cancelled` | guest, and staff when the guest cancelled | cancelled |
  | `_email/booking_changed` | guest | moved, by staff or the guest |

  Each gets the booking's fields (not the hidden ones), `who`,
  `manage_url` (empty when there is no link), `resource_name`,
  `service_name`, `site_url` and `book_url` (`booking_page`). Staff's go to
  `booking_staff_email`, else the sender (`RASTER_MAIL_FROM`, `mail_from`).
  A booking with no email sends the guest nothing. Each change sends each
  email once; a status that didn't change (`already_confirmed`) sends
  nothing. An edit that isn't a move (a phone number, a note) sends
  nothing. A booking staff add or move in the page or over MCP gets the
  guest's email and link through the model's `cms.item_saved` listener
  (`booking.saved`), with `who` `staff`; staff aren't emailed about their
  own. The listeners are framework bindings (`system/config/events.php`),
  bound only for the bundled model and only where a view uses it (as for
  its types), never a site's own `booking`; a site without booking loads
  none of its files. A `booking::move()` inside your own
  `cms_records::transaction()` still sends its email once. Unbind one to
  send your own:
  `event::unbind('booking.confirmed')->from('booking', 'confirmed_mail')`
  (also `requested_mail`, `declined_mail`, `cancelled_mail`,
  `rescheduled_mail`).
- Settings: `booking_notice` (60 minutes ahead), `booking_horizon` (60 days
  ahead), `booking_max_people` (12 in a guest's booking), `booking_cutoff`
  (120 minutes before the start a guest may still cancel or change),
  `booking_page` (`book`, the form, for `book_url`), `booking_manage_page`
  (`booking`), `booking_staff_email` (the sender).
- More fields on a booking (a dietary note, the café's newsletter box): a
  `the_booking` override whose `types()` adds them to `parent::types()`.
  The form, the editor and the events carry them; `fill()`, `check()` and
  the actions are the bundled ones.

  ```php
  class the_booking extends booking {           // models/the_booking/the_booking.php
      static function types() {
          $types = parent::types();
          $types['booking']['fields']['newsletter'] = false;
          return $types;
      }
  }
  ```

- Taking bookings, step by step:
  1. A page with the form (`booking_page`): a GET form for the day,
     `render.booking.days` and the day's `render.booking.slots` as radio
     buttons inside `render.booking.form`, with an alert for each problem
     the form can raise. With several resources, name the one this form is
     for (a hidden `resource` input and `resource=…` in the regions), or let
     the guest choose it in the GET form (`resource=?resource`).
  2. The manage page (`booking`): `render.booking.manage` with a Cancel
     form, and a Change form under a GET form for the day that keeps
     `token` and `booking` as hidden inputs.
  3. The six `_email/booking_*.html` views (`lint` names a missing one).
  4. The staff pages, from the Records recipe, protected for editors by
     their own names:

     ```php
     config::set('protected')->to(array('staff' => 'editor', 'booking_item' => 'editor'));
     ```

     ```html
     <!-- staff.html -->
     <p><!-- print.booking.count('status=requested&date>=today') -->3<!-- /print.booking.count('status=requested&date>=today') --> requests,
        <!-- print.booking.count('attention') -->0<!-- /print.booking.count('attention') --> need attention</p>
     <form method="get"><select name="resource"><option value="">All</option><option value="dining-room">Dining room</option></select>
       <input type="date" name="day"> <button>Show</button></form>
     <h3>Needs attention</h3>
     <!-- render.booking.attention --><p><!-- print.name -->Ana<!-- /print.name -->: <!-- print.attention -->closed<!-- /print.attention --></p><!-- /render.booking.attention -->
     <h3>Requests</h3>
     <!-- render.cms.booking('status=requested&date>=today&resource=?resource&date=?day&order=date,time') -->…<!-- /render.cms.booking('status=requested&date>=today&resource=?resource&date=?day&order=date,time') -->
     <h3>By day</h3>
     <!-- render.booking.agenda('from=today&days=7&resource=?resource') -->
     <h4><!-- print.date -->2026-10-20<!-- /print.date --></h4>
     <ul><!-- print.slots --><li><!-- print.time -->19:00<!-- /print.time -->: <!-- print.left_people -->12<!-- /print.left_people --> left</li><!-- /print.slots --></ul>
     <ul><!-- print.bookings --><li><!-- print.name -->Ana<!-- /print.name --> <!-- print.status -->requested<!-- /print.status --></li><!-- /print.bookings --></ul>
     <!-- /render.booking.agenda('from=today&days=7&resource=?resource') -->
     <h3>Past</h3>
     <!-- render.cms.booking('date<today&status!=cancelled&resource=?resource&order=-date,-time&limit=20') -->…<!-- /render.cms.booking('date<today&status!=cancelled&resource=?resource&order=-date,-time&limit=20') -->
     ```

     Staff confirm, decline and cancel with each booking's buttons, move
     one by editing its date or time, and add a phone booking with the card
     at the end of a `render.cms.booking` list. A page under `staff/`
     renders `render.cms.bookingresource`, `bookinghours`, `bookingclosed`
     and `bookingservice` for the setup, and `booking_item.html` renders
     `render.cms.booking` for a booking's own page
     (`/booking/booking_item/{slug}`, where `raster_detail_link` goes).
     Never protect `booking`: as a prefix it takes the guest's `/booking`
     too, and `lint` warns.
  5. What can be booked: staff add the resources and hours on the setup
     page, or a site ships a seed script (`demo/seed.php`) that makes them
     with `cms_records::create`. Until there is one, the form raises
     `not_open` and `lint` warns.

**contact**: the site's contact form, with every message kept for staff.
The site writes the form, the staff email and the words of each alert; the
model owns the type, the rules, the spam checks, the email's headers and
the events. Nothing in `the_app.php` is required. It counts only on a site
whose views use `render.contact.*` or `print.contact.*`; a site's own
`models/contact/` (with the `send()` the docs used to teach) keeps working
and hides the bundled one completely, and `lint` says so.
- `render.contact.form`: the form, posting fields `name email phone subject
  message` (a `the_contact` override adds more, as for booking). It stores
  the message and redirects with `?done=sent`, or shows the form again with
  the alerts below. Every message is stored: one that looks like spam
  (`validation::get()->suspect()`, its `name`, `subject` and `message` checked
  for links and blocked words) is kept with status `spam`, nobody is emailed,
  and the visitor gets the same `sent`, so a bot learns nothing. The same
  email and message again within 10 minutes (a double click) stores nothing
  and answers `sent`.
- Messages are the `contactmessage` type: `name`, `email`, `phone`,
  `subject`, `message`, and what the model works out (readonly): `title`
  (when it came, `2026-10-18 14:32`, which is also its slug, never the
  email), `page` (the path the form was sent from, `/visit`), `status` and
  `spam` (the reasons, comma separated: `no_stamp,too_many_links`, or
  `staff`). Visitors make them, nobody reads their own back (no `owner`),
  staff don't add them, and they are private.
- Rules, for every writer: `name_invalid`, `phone_invalid`,
  `subject_invalid` (a CR, LF, NUL or other control character, or over 200
  characters: they may reach a mail header, so they are refused, not
  cleaned), `email_invalid` (empty, not an address, over 254 characters,
  a control character, or more than a plain address: whitespace, a quoted
  local part, any of `" ( ) , : ; < > [ ] \`, or a second `@`, which PHP's
  email filter lets through and could carry a second address into
  Reply-To), `message_invalid` (empty, or over `contact_max_length`
  characters).
- Status: `new` until staff run `done` (they answered it); `spam` (reason
  `staff`) on one staff judge to be spam, `not_spam` on a flagged one (back
  to `new`, its reasons cleared). A message is never `done` straight from
  `spam` (`not_done_spam`: mark it not spam first), and `not_spam` on one
  that isn't spam is refused `not_spam_invalid`. Spam older than
  `contact_spam_days` (30) is deleted when a new message is stored; real
  messages stay until staff delete them.
- For staff (editors; anyone else gets nothing, wherever the page puts
  them; neither is page-cached):
  - `render.contact.messages('status=new&order=-created_at')`: the messages
    through `cms_records::listed`, editable in place with their actions;
    options as `cms_records::find` takes them (values only).
  - `print.contact.count('status=new')`: how many, for the page's heading
    (`''` for anyone else).
- Email, after the answer: `_email/contact_received` to staff
  (`contact_staff_email`, else the sender, `RASTER_MAIL_FROM` or
  `mail_from`; with neither, a warning is logged and the message is still
  stored), for each message that isn't spam. The subject is the view's
  `<title>`. `From` stays the site's sender; `Reply-To` is the visitor
  (`"Ana" <ana@example.com>`, the name without `"`, `<`, `>`, `,`, `(`,
  `)`, `[`, `]`, `:`, `;`, `@` and `\`, so it can only be a name). It
  gets the message's fields, `id`, `created_at`, `page_url` (the absolute
  link to `page`), `staff_url` (`contact_staff_page`) and `site_url`. A
  mail that fails leaves the message stored and `new`. Nothing is ever
  mailed to the visitor. `lint` reports a theme with `render.contact.form`
  and no `_email/contact_received`. The listener is a framework binding
  (`system/config/events.php`), bound only for the bundled model and only
  where a view uses it; unbind it to send your own:
  `event::unbind('contact.received')->from('contact', 'staff_mail')`.
- Events, after the commit: `contact.received` (a message that isn't spam,
  `who` `visitor`; or one staff marked not spam, `who` `staff`, which mails
  nobody) and `contact.flagged` (kept as spam), with the message's fields
  but `title`. No IP, or its hash, is in either.
- Settings: `contact_staff_email` (the sender), `contact_staff_page`
  (`staff`), `contact_max_length` (5000), `contact_spam_days` (30; false
  keeps spam), and the spam checks' (see **Forms**).
- From code: `contact::payload($message, $who)` is what the events and the
  email get, `contact::reply_to($name, $email)` the `Reply-To` value.
- Taking messages, step by step:
  1. The form, on any page, with an alert for each problem and `sent`:

     ```html
     <!-- print.validation.alert('sent') --><p>Thanks, we'll write back soon.</p><!-- /print.validation.alert('sent') -->
     <!-- print.validation.alert('email_invalid') --><p>Check your email address.</p><!-- /print.validation.alert('email_invalid') -->
     <!-- print.validation.alert('message_invalid') --><p>Write a message, up to 5000 characters.</p><!-- /print.validation.alert('message_invalid') -->
     <!-- render.contact.form -->
     <form method="post">
       <input name="name"> <input type="email" name="email" required> <input name="subject">
       <textarea name="message" required></textarea> <button>Send</button>
     </form>
     <!-- /render.contact.form -->
     ```

  2. `_email/contact_received.html`, with `<title>` as the subject
     (`print.self.subject` may go there; its newlines can't survive).
  3. The staff page, from the Records recipe, protected for editors by its
     own name (`lint` warns on a view with `render.contact.messages` that
     isn't):

     ```php
     config::set('protected')->to(array('staff' => 'editor', 'contactmessage_item' => 'editor'));
     ```

     ```html
     <!-- staff.html -->
     <h3>Messages: <!-- print.contact.count -->2<!-- /print.contact.count --> new</h3>
     <!-- render.contact.messages -->
     <article><!-- print.@href.raster_detail_link --><a href="#"><!-- print.title -->2026-10-18 14:32<!-- /print.title --></a><!-- /print.@href.raster_detail_link -->
       <!-- print.name -->Ana<!-- /print.name --> <!-- print.email -->ana@example.com<!-- /print.email --> on <!-- print.page -->/visit<!-- /print.page -->
       <p><!-- print.message -->Is the terrace open?<!-- /print.message --></p></article>
     <!-- /render.contact.messages -->
     <h3>Answered</h3>
     <!-- render.contact.messages('status=done&order=-created_at&limit=20') -->…<!-- /render.contact.messages('status=done&order=-created_at&limit=20') -->
     <h3>Spam</h3>
     <!-- render.contact.messages('status=spam&order=-created_at') -->… <!-- print.spam -->no_stamp<!-- /print.spam -->…<!-- /render.contact.messages('status=spam&order=-created_at') -->
     ```

     Staff mark each one done, spam or not spam with its buttons.
     `contactmessage_item.html` rendering `render.cms.contactmessage` is a
     message's own page (where `raster_detail_link` goes).

**rsvp**: replies to the site's events: yes (with a party size), no or
maybe, with places and a waitlist that moves. The events stay content,
the items of a markup collection that editors write in the page; the
model owns the replies, the place arithmetic, the waitlist and the events.
Nothing in `the_app.php` is required when the collection is called
`events`. It counts only on a site whose views use `render.rsvp.*` or
`print.rsvp.*`; a site's own `rsvp` model or type keeps the name, and
`lint` warns. Guests reply on the site's form (`render.rsvp.form`), staff
add replies in the page (`render.cms.rsvp`), over MCP or by model code,
and the guest gets an email with their link at each step. Staff see
every event's counts and replies, and what needs attention, on their own
page. The demo café's events (`demo/`) take replies, its two evenings
from `php demo/seed.php`, and are the worked example of all of it.
- **The event is an item** of `rsvp_collection` (`events`), read with
  `cms_store::get_item()`, never written. Four of its fields, written in
  its item view like any other (`<!-- print.places -->40<!-- /print.places -->`),
  are read by name:

  | Field | Type (from its mock-up) | Meaning |
  |---|---|---|
  | `date` | date | required: an item without one takes no replies |
  | `starts` (or `time`) | time | when it starts; without one, 00:00 of its date |
  | `places` | int | the most people with a yes; empty or 0 is no limit (and no waitlist) |
  | `rsvp_until` | datetime | when replies close; empty is `rsvp_closes` minutes before the start, and never later than the start |

  An event visitors can't see (a draft, or scheduled) takes no replies
  from guests. Times are the site's clock (`timezone`), in real seconds.
- Replies are the `rsvp` type: `name`, `email`, `phone`, `event` (the
  item's id), `answer` (`yes`, `no`, `maybe`), `people` (1 by default),
  `notes`, and what the model works out (readonly): `status`,
  `waiting_since` (the reply's place in the queue), `changed_at` (its
  status or people last changed) and `event_at` (the start the guest was
  told, so a moved event shows). `token_hashes` and `link_sent_at` are
  hidden. Guests make them (`create` visitor), staff add them in the page
  (`staff_add`), with `owner` a guest who was logged in reads their own,
  and they are private. The slug comes from the name, never the email. A
  visitor never chooses the event: a visitor's write gets the page's
  (`rsvp::$posting`), whatever the post names.
- `status`, on every write (`fill()`): `no` and `maybe` take no places; a
  new `yes` (or one from no or maybe, or moved to another event) is
  `going` when the people already going plus its party fit `places`, else
  `waiting` with `waiting_since` now. A going or waiting yes keeps its
  status, and a waiting one its place in the queue, whatever its party
  becomes. The people going are summed in one query (`sql/going.sql`)
  inside the write's lock, the reply being written left out, so two yeses
  for the last place at once leave one `going` and one `waiting`.
- The rules run when a reply is made and when its `answer`, `people`,
  `event` or `email` change, in this order: `not_open` (no event, no
  `date`, or hidden, for guests), `name_invalid` (empty, a control
  character, over 200 characters), `email_invalid` (a guest's empty;
  anyone's not an address, a control character, over 254),
  `answer_invalid`, `people_invalid` (a yes below 1), `too_many` (a yes
  that takes places, new, from no or maybe, moved or bigger, over
  `places` for anyone, over `rsvp_max_people` for guests; a party already
  in may still shrink or change its email), `closed`
  (guests: from the deadline a new yes, a no or maybe becoming yes, or
  more people; from the start, any change), `full` (it would wait while
  `rsvp_waitlist` is false), `already_replied` (another reply to this
  event with this email, in any case). Any other edit (a phone number, a
  note) goes through, so a reply whose event changed never gets stuck.
  Whatever changed, a reply made going, or a going party made bigger, must
  fit: `full_for_more` (a going guest asking for more than is left keeps
  the places they have), `not_enough_places` otherwise. Staff (the in-page
  editor, MCP, or model code) skip `closed`, the guests' `too_many` and the
  missing email, and may reply for a draft, but never go over `places`: a
  staff yes that doesn't fit waits like anyone's.
- **The waitlist.** When places may have freed up (a going reply says no
  or maybe, gets smaller, moves to another event or is deleted; a waiting
  one gets smaller; the event is saved, `places` raised or emptied) the
  model moves in, oldest `waiting_since` first, every waiting reply whose
  party fits: a party of 4 that doesn't fit doesn't keep two places from a
  couple behind it. Each one becomes `going` and sends `rsvp.promoted`
  (`who` `waitlist`). It reads the queue and the places again inside the
  write's lock, so running it twice moves nobody twice. A write the model
  makes itself promotes in the same transaction; one staff make in the
  page or over MCP, or a save of the event, promotes after the commit,
  from the model's `cms.item_saved` and `cms.item_deleted` listeners, in a
  transaction of its own. Nothing is moved in from the deadline, nor for
  an event visitors can't see: freed places are staff's to give then.
  Nobody `going` is ever moved out: staff may lower `places` under the
  people going, move the date, hide or delete the event, and every reply
  keeps its status. Saying no or maybe leaves the queue; a yes again joins
  at the end.
- `promote`, an action for editors: this waiting reply goes in now, out of
  turn and after the deadline too, when its party fits
  (`not_enough_places` otherwise: raise `places` first, which moves the
  queue in order; `not_waiting` on one that isn't waiting). It sends
  `rsvp.promoted` with `who` `staff`.
- `render.rsvp.event`: one row for the page's event, on its item page
  (`/events/events_item/{slug}`, found as `render.cms.events` finds
  it) or on any page by `?event=<id>`: `event` (its id), `places` and
  `left` (`''` for no limit), `going` (people), `waiting` (replies),
  `state` (`open`; `full`, a yes goes on the waitlist; `closed`, past the
  deadline; `over`, it has started) and `deadline` (`2026-11-20 18:00`).
  Nothing for an event visitors can't see (editors get it). Never
  page-cached.

  ```html
  <!-- events_item.html -->
  <!-- render.cms.events -->
  <h1><!-- print.title -->Jazz night<!-- /print.title --></h1>
  <p><!-- print.date -->2026-11-20<!-- /print.date --> at <!-- print.starts -->20:00<!-- /print.starts -->,
     <!-- print.places -->40<!-- /print.places --> places, replies until <!-- print.rsvp_until -->2026-11-20 18:00<!-- /print.rsvp_until --></p>
  <!-- /render.cms.events -->
  <!-- render.rsvp.event --><!-- print.+class.state --><p class="places"><!-- print.left -->12<!-- /print.left --> of <!-- print.places -->40<!-- /print.places --> places left</p><!-- /print.+class.state --><!-- /render.rsvp.event -->
  ```

- `render.rsvp.form`: the reply form, posting fields `name email phone
  answer people notes` (a `the_rsvp` override adds more). The event is
  the page's, found as `render.rsvp.event` finds it: on its item page, or
  on any page by `?event=<id>`; a visitor's reply never takes the event
  from the post, whatever it names. It stores the reply and redirects
  with `?done=going`, `?done=waiting` or `?done=noted` (its status) to
  the page it was sent from, `?event=` kept, or shows the form again,
  with what was typed, and the rules' alert (`closed`, `full`,
  `too_many`, `people_invalid`, …). Shown first, it raises `not_open`
  when the page has no event visitors can see (none, a draft, scheduled,
  no `date`) and `closed` from the deadline. The guest gets
  `rsvp_going`, `rsvp_waiting` (with `position`) or `rsvp_noted` with
  their link. Never page-cached; wrap it in `print.if.live` for a static
  export. A post that looks like spam gets `try_again` or `slow_down`
  (see **Forms**), so word both.
  - **One reply per address.** A post with an address that already
    replied to this event (in any letter case) stores nothing, shows the
    form again with `already_replied` ("we've emailed you a link to
    change your reply"), and emails `_email/rsvp_link` with a new link to
    **the reply's own address**, at most once every five minutes
    (`mail::RESEND_AFTER`; the hidden `link_sent_at`). So nobody changes
    someone else's reply by typing their address, and nobody floods an
    inbox: the spam checks come first, so a post without a stamp mails
    nothing, and the 4th post in an hour with one address is held
    (`slow_down`, `spam_per_email`). Nothing is mailed for an event that
    has started or is hidden. The reply's own rules run before the
    address is looked up, so a post they refuse anyway (an `answer` that
    isn't one) gets their alert and never says whether that address has
    replied.
  - A reply made by the form inside a site's own
    `cms_records::transaction()` still sends its event and its email
    once, after that commit.
  - `lint` errors on a view with `render.rsvp.form` when no view of
    `rsvp_collection` has a `date` field, and warns when none has
    `places` (no limit, so no waitlist).

  ```html
  <!-- events_item.html, under render.rsvp.event -->
  <!-- print.validation.alert('going') --><p>You're in. We've emailed you a link to change your reply.</p><!-- /print.validation.alert('going') -->
  <!-- print.validation.alert('waiting') --><p>It's full: you're on the waitlist, and we'll email you if a place frees up.</p><!-- /print.validation.alert('waiting') -->
  <!-- print.validation.alert('noted') --><p>Thanks for letting us know.</p><!-- /print.validation.alert('noted') -->
  <!-- print.validation.alert('already_replied') --><p>You've replied already: we've emailed you a link to change your reply.</p><!-- /print.validation.alert('already_replied') -->
  <!-- print.validation.alert('closed') --><p>Replies are closed.</p><!-- /print.validation.alert('closed') -->
  <!-- print.validation.alert('too_many') --><p>That's more people than we can take in one reply.</p><!-- /print.validation.alert('too_many') -->
  <!-- render.rsvp.form -->
  <form method="post">
    <input name="name" required> <input type="email" name="email" required> <input name="phone">
    <label><input type="radio" name="answer" value="yes" checked> Yes</label> <label><input type="radio" name="answer" value="maybe"> Maybe</label>
    <label><input type="radio" name="answer" value="no"> No</label> <input type="number" name="people" min="1" max="6" value="1">
    <textarea name="notes"></textarea> <button>Reply</button>
  </form>
  <!-- /render.rsvp.form -->
  ```

- `render.rsvp.manage`: the guest's page (the `rsvp` view, or
  `rsvp_manage_page`), one row: the reply without its hidden fields, with
  `event_title`, `event_date`, `event_starts`, `event_url`, `deadline`
  (`2026-11-20 18:00`) and `position` (in the queue, `''` unless
  waiting). See **The guest's link**.

  ```html
  <!-- rsvp.html -->
  <!-- print.validation.alert('link_invalid') --><p>This link no longer works. Write to us or phone.</p><!-- /print.validation.alert('link_invalid') -->
  <!-- print.validation.alert('changed') --><p>Done: we've emailed you your reply.</p><!-- /print.validation.alert('changed') -->
  <!-- print.validation.alert('closed') --><p>Replies are closed: you can still tell us you can't come, or come with fewer.</p><!-- /print.validation.alert('closed') -->
  <!-- print.validation.alert('full_for_more') --><p>There aren't places for more of you; your places are kept.</p><!-- /print.validation.alert('full_for_more') -->
  <!-- render.rsvp.manage -->
  <p><!-- print.name -->Ana<!-- /print.name -->, for <!-- print.event_title -->Jazz night<!-- /print.event_title -->: <!-- print.status -->going<!-- /print.status --></p>
  <form method="post"><input type="hidden" name="do" value="change">
    <label><input type="radio" name="answer" value="yes"> Yes</label> <label><input type="radio" name="answer" value="maybe"> Maybe</label>
    <label><input type="radio" name="answer" value="no"> No</label> <input type="number" name="people" min="1"> <button>Change my reply</button></form>
  <!-- /render.rsvp.manage -->
  ```

- The guest's link. Each email to the guest carries a new one in
  `manage_url`: `<rsvp_manage_page>?token=<64 hex>`, 32 random bytes sent
  only in that email. The reply keeps their sha256 (the newest 20, in the
  hidden `token_hashes`), so the first email's link still works after a
  promotion. A link works until the event starts, read from the event
  each time, so it follows a moved event, and after a no too, so the
  guest can say yes again before the deadline. It is dead once the event
  is hidden (a draft, scheduled) or deleted; a dead, unknown or malformed
  one raises `link_invalid`.
  - A GET shows the reply and changes nothing, so a mail scanner opening
    the link does no harm. The page's form posts to the page itself (the
    token stays in the URL, never in a hidden field) with `do=change`,
    `answer` and `people` (either may be left out). The cross-site check
    applies.
  - The change is checked as a guest's (`closed`, `full_for_more`,
    `too_many`, …, raised on the page, the reply as it was); places it
    frees go to the waitlist in the same transaction. The same answer and
    people again writes and sends nothing. Afterwards the page redirects
    to `?done=changed` without the token, and shows only that alert.
  - A logged in guest manages their own reply (its `owner`) by
    `?reply=<id>`, without a token; the redirect keeps `?reply=`.
  - The page is never page-cached, sends `Referrer-Policy: same-origin`, and
    is left out of the sitemap and `raster export`. The raw token is in no
    event, log, record or cache: the model hands it to its own email
    listener within the request. Don't protect `rsvp` for staff
    (`protected`): `lint` warns, since guests would get the login page.
- Emails, after the answer (the site writes the views, and `lint` reports a
  missing one where `render.rsvp.form` or `render.rsvp.manage` is):

  | View | To | When |
  |---|---|---|
  | `_email/rsvp_going` | guest | the reply becomes `going`, or a going party changes size, by the guest or staff |
  | `_email/rsvp_waiting` | guest | the reply becomes `waiting`, or a waiting party changes size |
  | `_email/rsvp_promoted` | guest | moved in from the waitlist (or by the `promote` action) |
  | `_email/rsvp_noted` | guest | the reply becomes `no` or `maybe` |
  | `_email/rsvp_link` | guest | they replied again on the form with the same address: their link again, to the reply's address (`render.rsvp.form` only) |
  | `_email/rsvp_new` | staff | a guest replied or changed their reply; only when `rsvp_staff_email` is set |

  Each gets the reply's fields (not the hidden ones), `who`, `manage_url`
  (their new link; empty for staff), `position` (`1` for the head of the
  queue, `''` unless waiting), `event_title`, `event_date`,
  `event_starts`, `event_url` (the item's page), `deadline` and
  `site_url`. A reply with no email sends the guest nothing, and gets no
  link. Each change sends each email once; one that changes neither
  status nor people (a note, a phone number), or an edit of the event,
  sends nothing. A reply staff add or change in the page or over MCP
  emails the guest through the model's `cms.item_saved` listener; staff
  aren't emailed about their own. A site with no `rsvp_staff_email` sends
  staff nothing: a jazz night's forty replies are on the staff page.
  Unbind one to send your own:
  `event::unbind('rsvp.promoted')->from('rsvp', 'promoted_mail')` (also
  `replied_mail` and `changed_mail`, which send `rsvp_going`,
  `rsvp_waiting`, `rsvp_noted` and `rsvp_new`).
- Events, after the commit: `rsvp.replied` (a reply was made), `rsvp.changed`
  (its `answer`, `people` or `event` changed, with `was` and `was_people`,
  its status and party before) and `rsvp.promoted`. Each has the reply's
  fields but the hidden ones, `who` (`guest`, `staff` or `waitlist`), and
  `event_title` (the event's title, headline or name), `event_date` and
  `event_starts`. A reply staff add or change in the page, over MCP or in
  model code gets its event through the model's `cms.item_saved` listener
  (`rsvp.saved`), with `who` `staff`; editing a note or the event sends
  none. The listeners are framework bindings (`system/config/events.php`),
  bound only for the bundled model and only where a view uses it; a site
  without rsvp loads none of its files.
- Settings: `rsvp_collection` (`events`), `rsvp_max_people` (6 in a
  guest's reply), `rsvp_closes` (0 minutes before the start, for an event
  without `rsvp_until`), `rsvp_waitlist` (true; false refuses a yes that
  doesn't fit with `full`), `rsvp_manage_page` (`rsvp`, the guest's page
  their links open), `rsvp_staff_email` (empty: staff get no emails).
- For staff (editors; anyone else gets nothing, wherever the page puts
  them; none is page-cached):
  - `render.rsvp.events('from=today')`: a row per event dated from that
    day (`from=2026-10-01`, `from=?from`), by date and start, drafts and
    scheduled ones included: `event` (its id), `event_title`,
    `event_date`, `event_starts`, `places` and `left` (`''` for no
    limit), `going` (people), `waiting`, `maybe` and `no` (replies), and
    `replies`: the event's replies through `cms_records::listed`, the
    queue first, editable in place with the `promote` button, each with
    `attention` (the reason, or empty), `position` (`''` unless waiting),
    `event_title`, `event_date` and `event_starts`. It reads the events
    in one query and the replies in another, however many events there
    are.
  - `render.rsvp.attention`: the active replies (`going`, `waiting`) that
    need attention, with the same added fields, by their event's start:
    those to events to come, and those whose start the guest was told is
    still to come (a deleted event's, one moved into the past).
  - `print.rsvp.count('status=waiting')`: how many replies match (options
    as `cms_records::find` takes them, values only:
    `count('status=going&event=7')`, never `?event`), or
    `count('attention')`: for the staff page's heading (`''` for anyone
    else). It counts every reply that matches, past events' too: one
    event's numbers are in its `render.rsvp.events` row.
  - **Needs attention.** Staff edit events in the page all the time (a
    typo, a date), so nothing they do to an event is refused, nobody
    going is moved out, and nobody is emailed. Instead an active reply
    shows why it no longer fits, the first of: `event_gone` (the item was
    deleted, or its `date` emptied), `event_hidden` (a draft, or scheduled
    again), `over_places` (`places` lowered under the people going: every
    going reply of the event), `moved` (its date or start isn't the one
    the guest was told, `event_at`). It is worked out as replies are
    listed, never stored. Staff phone the guest and save the reply (a
    note, "phoned them"): an editor's save of a reply sets `event_at` to
    the start now, which clears `moved`. `rsvp::reason($reply, $event,
    $going)` says the same from code.
  - One event's replies: `render.cms.rsvp('event=?event&order=status,waiting_since')`
    on the staff page, opened with `?event=<id>`: a GET form whose
    `<select name="event">` lists the events with `render.rsvp.events`
    (`<!-- print.@value.event --><option value="1">…</option><!-- /print.@value.event -->`),
    after an `<option value="">All events</option>`, and keeps the chosen
    evening chosen. Not a link: `print.+href` adds a space before what it
    appends, so it can't finish `?event=`. Without `?event` (All events)
    the list has every reply. The card for a new reply at the end of that list starts with that
    `event`, so a phone reply is added in the page with no id to type.
    A phone reply needs no email; it is checked like any staff reply
    (never over `places`: it waits when it doesn't fit).
  - `lint` warns on a view with `render.rsvp.events` or
    `render.rsvp.attention` that isn't protected for editors, and counts a
    view with `render.rsvp.events` as the `rsvp` type's staff page.
    Protect it by its own name (`staff`), never `rsvp`, the guests' page.
- From code: `rsvp::item($id)` is the event as the model reads it (`id`,
  `title`, `slug`, `date`, `starts`, `places`, `rsvp_until`, `visible`,
  `starts_at`, `deadline`, `url`), or null; `rsvp::page_event()` the
  page's; `rsvp::deadline($event)` when its replies close;
  `rsvp::going($event, $id)` the people going, reply `$id` left out;
  `rsvp::promote_waiting($event)` moves its queue in now and gives the
  replies moved. `rsvp::change($id, $values, $who)` changes a reply's
  `answer` and `people` in one write, sends `rsvp.changed` (and the
  guest's email with a new link) and moves the waitlist in the same
  transaction; a guest's (`$who` `'guest'`) needs a live link
  (`link_invalid`) and gets the guest rules, staff's (`'staff'`) doesn't.
  `rsvp::by_token($raw)` gives the reply a link is for (or null),
  `rsvp::position($reply)` its place in the queue. Model code that writes
  replies with `cms_records::create/update` is trusted like staff.
- Taking replies, step by step:
  1. The event's fields, written in the collection's views like any other
     (`events.html`, the list, and `events_item.html`, the item): `date`,
     `starts` and `places`, and `rsvp_until` where replies close before
     the start. The list's own view types the fields, so give `places` its
     mock-up (`40`) there too. A field only staff need to see goes in
     `print.if.is_editor` (the café's `rsvp_until`).
  2. The item view: `render.rsvp.event` for the places left, then
     `render.rsvp.form` with an alert for each problem the form can raise
     and for `going`, `waiting` and `noted`, all inside `print.if.live`,
     and what a static export says instead in `print.if.static` (a phone
     number). One sentence per `state`, with no JavaScript: put each in
     the region's block and let `print.+class.state` and the theme's CSS
     show the one that applies:

     ```html
     <!-- render.rsvp.event -->
     <!-- print.+class.state --><div class="rsvp-state">
       <p class="if-open"><!-- print.left -->12<!-- /print.left --> of <!-- print.places -->40<!-- /print.places --> places left</p>
       <p class="if-full">Full: join the waitlist</p> <p class="if-closed">Replies closed</p> <p class="if-over">It has started</p>
     </div><!-- /print.+class.state -->
     <!-- /render.rsvp.event -->
     ```

     ```css
     .rsvp-state > p { display: none; }
     .rsvp-state.open .if-open, .rsvp-state.full .if-full, .rsvp-state.closed .if-closed, .rsvp-state.over .if-over { display: block; }
     ```

  3. The manage page (`rsvp`): `render.rsvp.manage` with its change form,
     and alerts for `link_invalid`, `changed`, `closed`, `full_for_more`,
     `too_many` and `people_invalid`.
  4. The `_email/rsvp_*.html` views: `rsvp_going`, `rsvp_waiting`,
     `rsvp_promoted`, `rsvp_noted` and `rsvp_link`, and `rsvp_new` with
     `rsvp_staff_email` (`lint` names a missing one). A `<title>` may
     print the event: `<title>You're in: <!-- print.self.event_title -->Jazz night<!-- /print.self.event_title --></title>`.
  5. The staff page, from the Records recipe, protected for editors by
     its own names (never `rsvp`, the guests' page):

     ```php
     config::set('protected')->to(array('staff' => 'editor', 'rsvp_item' => 'editor'));
     ```

     ```html
     <!-- staff/events.html -->
     <p><!-- print.rsvp.count('status=waiting') -->3<!-- /print.rsvp.count('status=waiting') --> replies waiting (past events too),
        <!-- print.rsvp.count('attention') -->0<!-- /print.rsvp.count('attention') --> that need attention</p>
     <h3>Needs attention</h3>
     <!-- render.rsvp.attention --><p><!-- print.name -->Ana<!-- /print.name -->, <!-- print.event_title -->Jazz night<!-- /print.event_title -->: <!-- print.attention -->moved<!-- /print.attention --></p><!-- /render.rsvp.attention -->
     <!-- render.rsvp.events('from=today') -->
     <h3><!-- print.event_title -->Jazz night<!-- /print.event_title -->, <!-- print.event_date -->2026-11-20<!-- /print.event_date --></h3>
     <p><!-- print.going -->28<!-- /print.going --> going, <!-- print.left -->12<!-- /print.left --> of <!-- print.places -->40<!-- /print.places --> left, <!-- print.waiting -->0<!-- /print.waiting --> waiting</p>
     <ul><!-- print.replies --><li><!-- print.name -->Ana<!-- /print.name --> <!-- print.people -->2<!-- /print.people --> <!-- print.status -->going<!-- /print.status --> <!-- print.position --><!-- /print.position --></li><!-- /print.replies --></ul>
     <!-- /render.rsvp.events('from=today') -->
     <h3>One evening</h3>
     <form method="get"><select name="event"><option value="">All events</option>
       <!-- render.rsvp.events('from=today') --><!-- print.@value.event --><option value="1"><!-- print.event_title -->Jazz night<!-- /print.event_title --></option><!-- /print.@value.event --><!-- /render.rsvp.events('from=today') -->
     </select> <button>Show</button></form>
     <!-- render.cms.rsvp('event=?event&order=status,waiting_since') --><p><!-- print.name -->Ana<!-- /print.name --> <!-- print.status -->going<!-- /print.status --></p><!-- /render.cms.rsvp('event=?event&order=status,waiting_since') -->
     ```

     Staff edit a reply in place, move a waiting one in with its Promote
     button, and add a phone reply with the card at the end of the one
     evening's list (pick the evening first: the card starts with it). The
     places, the date and the deadline are the event's own fields, edited
     on its page; raising `places` there moves the waitlist in.
     `rsvp_item.html` rendering `render.cms.rsvp` is a reply's own page
     (`/rsvp/rsvp_item/{slug}`, where `raster_detail_link` goes). The
     counts take values only, so a count for the chosen event is the
     event's row, not `count('…&event=?event')`.
  6. On `/account`, `render.cms.rsvp('owner=me&order=-created_at')` lists a
     logged in guest's own replies; a GET form to `rsvp.html` with
     `<button name="reply" value="…">` opens one, without a token.
  7. The events themselves: staff write them in the page. A seed script
     can add some with `cms_store::save_item('eventsdata', 0, $values,
     array_keys($values))`, after `cms_types::ensure()` makes the table's
     columns with the types of the inspector's content model
     (`demo/seed.php`), so `places` is an int before anyone opens the list.

**mail**: emails are views. `mail::send_view('_email/welcome', $to,
array('name' => 'Ada'))` uses the view's `<title>` as the subject. A view
without one is sent with the site's name as the subject (the `site_name`
field every page shares, `print.cms.site_name`), else its host.
- Transport, set with `RASTER_MAIL`:
  - `log://` writes to `application/data/mail/` (the development default);
    `log:///some/folder` writes to that folder instead.
  - `mail://` uses PHP's mail() (the production default).
  - `smtp://user:pass@host:587` requires STARTTLS (add `?insecure=1` to allow
    plain SMTP; `localhost` is allowed as is), and `smtps://…:465` uses TLS.
- Sender: `RASTER_MAIL_FROM`. A name in it, in the recipient, or in a
  `Reply-To` passed in `send_view()`'s headers, that isn't
  plain ASCII is sent encoded whole (`=?UTF-8?B?UmFzdGVyIENhZsOp?=
  <hello@cafe.test>`), and so is the subject, in words of at most 75
  characters. `mail://` gives sendmail the sender's address with `-f`, so
  bounces go to it.
- **Mail goes out after the answer.** In a web request `mail::send()` and
  `send_view()` check the recipient and the transport, keep the message and
  return true; once the visitor has the page (the session written,
  `fastcgi_finish_request()` where PHP has it), `mail::send_kept()` sends
  every kept mail over one connection. A slow or silent mail server can't
  hold a form: SMTP waits `mail::TIMEOUT` (5) seconds at most, and once the
  server can't be reached the rest of the request's mails fail at once. A
  failure is logged and sends `mail.failed` (`to`, `subject`, `error`), so
  that event, not `send()`'s result, says a kept mail didn't go. The
  command line (`raster send`, scripts, MCP over stdio) and code between
  `keep_open()` and `close()` send at once, and `send()` returns whether
  the mail was handed over.
- Links in emails: `util::url('letters/confirm', array('token' => $t))` is
  the absolute address of a page of the site, with its query.
- Many mails at once: `mail::keep_open()` keeps the SMTP connection for the
  mails that follow (`RSET` between them, a new connection if the server
  dropped it) until `mail::close()`. `mail::destination()` is the transport
  without user, password or options, for printing.

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
  which returns an array of key => text. A dictionary that sets `$lang`
  instead of returning it still works until 3.0.0 (`doctor` warns, and
  `raster upgrade` adds `return $lang;`).
- Settings: `config::set('languages')->to(array('en', 'ro'))` (codes such
  as `en`, `ro`, `pt-br`; the first is the default; `doctor` fails on one
  it can't read, which is skipped), `domain_language`, and the switch
  `config::set('multilingual')->to(true)`, off by default. Multilingual
  needs two or more languages: with fewer, `doctor` fails and the site
  behaves as off.
- Multilingual, each language has its own addresses (`/ro/menu`) and the
  address alone picks it: an exact `domain_language` host, else the
  `/<lang>/` prefix, else the default.
- Without `multilingual` (template-only translation, deprecated since
  2.2.49, removed in 3.0.0; `doctor` warns on two or more languages and
  suggests the switch; one language, `array('ro')`, is fine) only the
  template's words are translated, and the language comes from `?lang=`
  (remembered in a cookie), the domain, the cookie, then the browser.
- `print.i18n.language` gives the current language (`false` when the site
  has no `languages`, so the mock-up's value stays), and `print.i18n.dir`
  `rtl` for a language written right to left (Arabic, Hebrew, Persian,
  Urdu, …), else `ltr`. Every page in the starter, the demo and the shop
  starts with them, so the mock-up reads `lang="en"` (emails say
  `lang="en"` as written for now):
  `<!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr"><!-- /print.@dir.i18n.dir --><!-- /print.@lang.i18n.language -->`.
- `render.i18n.languages` gives a switcher with `code`, `url` (this page in
  that language), `state` (`active` for the current one) and `current`
  (true for it). Multilingual, `url` is the language's own address
  (`/ro/menu`, from `i18n::alternates()`); otherwise it is the page with
  `?lang=xx`. The flag `if.languages` is true when the site has more than
  one language: the starter's `_layout.html` shows its switcher inside
  `print.if.languages`.
- Multilingual, Raster also puts into `<head>`, after the `<base>`:
  `<link rel="canonical">` (the page in its own language), one
  `<link rel="alternate" hreflang="xx">` per language, and
  `hreflang="x-default"` (the default language's address). A page that
  writes its own canonical or hreflang links keeps them instead. None are
  added on a page that isn't a 200 (the 404 page) or on a URL with a query
  (`?page=2`); tracking parameters (`utm_*`, `fbclid`, …) don't count. Off,
  nothing is added.
- **Lint, multilingual on.** Three more warnings: `untranslatable_text`,
  words in a view that no annotation prints, so every language shows them
  as written (make them `print.cms.<name>` for words editors change, or
  `print.i18n.<file>('key')` for fixed words; what a print replaces,
  comments, `remove`, scripts, styles, numbers, punctuation and lone
  symbols are left out, and so are emails; one per line, at most ten per
  view and a count of the rest); `missing_translation_key`, a
  `print.i18n.<file>('key')` some language's dictionary has no entry for;
  `html_lang_fixed`, an `<html>` whose `lang` no
  `print.@lang.i18n.language` prints. Off, none of them.
- **The language API.** Every model, a site's own too, reads language
  state through these static calls, never `config::get('languages')`.
  With multilingual off they give the one-language answer, so callers
  need no special case:

  | Call | Gives |
  |---|---|
  | `i18n::enabled()` | `multilingual` is on and there are two or more languages |
  | `i18n::available()` | the languages, the ones Raster can read |
  | `i18n::default_language()` | the first, or `''` when `languages` is unset |
  | `i18n::current()` | this request's language: multilingual, the address's; otherwise what `?lang=`, the cookie and the browser pick (`i18n::detect()`, kept) |
  | `i18n::is_default($lang = null)` | whether it is the default language |
  | `i18n::suffix($lang = null)` | a translation column's suffix: `''` when off and for the default, else `__ro`, `__pt_br` |
  | `i18n::prefix($lang = null)` | the address prefix on this host: `''` when off, for the default and on the language's own domain, else `ro/` |
  | `i18n::with($lang, $fn)` | runs `$fn` with `current()` set to `$lang` (one the site doesn't list runs in the default) and, multilingual, `link_uri` set to that language's address; puts both back, even when `$fn` throws, nests, and returns what `$fn` returned |
  | `i18n::dir($lang = null)` | `rtl` for Arabic, Hebrew, Persian, Urdu and the other right-to-left languages, else `ltr` |
  | `i18n::date_order($lang = null)` | how dates are typed: `''` without languages, `mdy` for `en-us`, `dmy` for the rest (`Y-m-d` is always read) |
  | `i18n::address($lang)` | where that language's pages start (`link_base` and its prefix; its own `domain_language` host, seen from the site's address or a language's domain; or the site's address seen from another language's domain), the base `alternates()`, `with()` and the `/ro/` and `?lang=` redirects use; `null` when it has none |
  | `i18n::alternates($path = null)` | the page (or `$path`, as it reads) in every language: rows of `lang` and `url`, each path segment encoded; a language with its own `domain_language` host is at that host, and from such a host the others are on the site's address (`RASTER_URL`, `site_url`), or left out without one (`doctor` fails on that) |

  `$lang` left out is `current()`. Raster calls these on the bundled
  class, from the page cache before any model loads too, so a site's
  `the_i18n` can't change them; it changes only what templates print
  through `print.i18n` and `render.i18n`.

**Multilingual URLs** (`multilingual` on, with two or more `languages`): the
URL alone says the content's language, so one URL is always one language.
- **Addresses.** The default language has no prefix (`/menu`); every other
  language is under its code (`/ro/menu`). A language's home page is `/ro/`,
  and `/ro` answers 301 to it. `/ro/menu/` answers 301 to `/ro/menu` (the
  one-address rule), `/en/menu` (the default's code) 301 to `/menu`, and
  `?lang=ro` 301 to the same page in that language (`/ro/menu`, the rest of
  the query kept; `ro-RO` names `ro`). A host listed in `domain_language`
  (exactly, port aside) is that language and takes no prefix. One language
  has one address: on the site's address (`site_url`, else `RASTER_URL`)
  `/ro/menu` answers 301 to `example.ro/menu` when `ro` has its own
  domain, and `?lang=` goes to a language's domain, or from one to the
  site's address. On any other host (a preview or staging copy whose
  `RASTER_URL` isn't the `site_url`, `localhost`) every language is served
  under its prefix there, and links stay on that host.
  The cookie and `Accept-Language` don't choose the content, and no `lang`
  cookie is set.
- **One place.** `config::initialize()` takes the prefix off, after the
  site's own folder (`RASTER_URL=https://host/preview/abc/` serves
  `/preview/abc/ro/menu`) and before anything reads the path. Routes,
  `protected` pages (`/ro/staff` sends a visitor to `/ro/login`, and back),
  the cross-site check, `/ro/api/…` and `/ro/mcp` see `/menu`, as without a
  prefix. `config::get('url_language')` is the language the URL names (`''`
  for the default, and when multilingual is off), `link_base` the site's
  link address without a prefix, and `link_uri` adds the prefix (`…/ro/`),
  so every link Raster builds, in pages and in emails rendered there, keeps
  the language.
- **Links.** On a `/ro/` page, links by file name (`href="menu.html"`) and
  hand-written root links to pages (`href="/menu"`, `action="/contact"`,
  `formaction`) become `…/ro/menu`, and `/index.php/menu` the same. Links to files are left alone: anything with an
  extension (`/menu.pdf`, `/news.rss`), under the app's folder (the theme),
  `media/` or `system/`, and any path that is a file. A root link outside
  the site's folder, or that already names a language (`/ro/menu`), keeps
  that.
- **Headers and cache.** Every HTML and feed page answers
  `Content-Language: ro` (its language), and a cached copy goes out with it
  too. The page cache keeps one copy per URL, so `/menu` and `/ro/menu` are
  two copies and need no `Vary`.
- With multilingual off none of this happens: `/ro/menu` is a 404, `?lang=`
  picks the template's language and sets the cookie, links are as before and
  there is no `Content-Language`.

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
- **Errors.** Outside development PHP's own messages go to the error log,
  never to visitors: Raster turns `display_errors` off and `log_errors` on
  for every request, whatever php.ini says (the command line keeps them).
  `doctor` warns in production when php.ini has `display_errors` on, since
  an error before Raster starts would still show.
- **A database that can't be reached** (MySQL down, an SQLite file the web
  server can't read) is not a missing table: outside development every page
  answers 503 (`/api` too, as JSON), as the view `error_document_503` when the site sets one, or the
  bare status (see **Error pages**). The error is logged and nothing is cached, so the real pages
  show as soon as the database is back. Development shows the error
  instead.
- **The site's address:** set `RASTER_URL=https://example.com/` (or
  `config::set('site_url')`). Links in pages and emails then never depend on
  the visitor's `Host` header. In production, emails with links (password
  reset, newsletter confirmation) are not sent without it, and
  `raster send` needs it. A domain listed in `domain_language` (exactly,
  port aside) is the site's own too: a request on it keeps its host, so its
  language, its links and its forms; every other host gets the address.
  With a path (`https://example.com/shop/`) the site
  lives under it: URLs are matched and links built under `/shop/`, also with
  `raster serve` and behind a proxy that forwards the path unchanged. With
  `php -S` directly, use `RASTER_URL`, not `site_url`. Addresses outside the
  folder still answer (`/about` as well as `/shop/about`); a server or proxy
  in front that only passes `/shop/` keeps the site to one address.
- **Database file:** `RASTER_DB=/path.sqlite` points at another database
  file. SQLite runs in WAL mode, so `-wal` and `-shm` files sit beside it;
  copy all three together, or back up with `sqlite3 <file> .backup`.
  `RASTER_DB=mysql://user:password@host:3306/name` uses that MySQL database
  instead, whatever `config/db/` says, for every connection there
  (encode the password as PHP's `rawurlencode()` does).
- **What the server must never serve.** A Raster site is one folder, and most
  of it is private: the framework, the app's code and config, the SQLite file,
  the view files themselves. The rules are in one list,
  `system/private_paths.php`, and everything comes from it — the router in
  `index.php` for `php -S`, the `.htaccess` that ships with Raster (the only
  one: there is none inside the app or `system/` folders), and:

  ```sh
  php bin/raster deploy --config=apache          # the .htaccess itself
  php bin/raster deploy --config=nginx  --host=site.example --root=/srv/site
  php bin/raster deploy --config=caddy  --host=site.example --socket=unix//run/php/php-fpm.sock
  ```

  The nginx config also takes request bodies up to 13 MB
  (`client_max_body_size`; nginx's 1 MB would refuse the editor's larger
  pictures with a 413), gzips text other than pages, and gives `/media/` a
  year's `expires`, since an upload never reuses a file name.

  `raster serve` is PHP's built in server, for development. In production run
  PHP-FPM behind one of those. `doctor` checks that the `.htaccess` on disk
  still carries every rule, and the line that passes the `Authorization`
  header on to PHP-FPM for MCP over HTTP. Matching ignores case on every server, and
  `/.well-known/` is the one dot folder that is served (certificates,
  `security.txt`).
- **Page cache**, on by default in production (config `page_cache`):
  - Whole pages are cached for visitors without a session or a query string.
    Tracking parameters don't count: `/about?utm_source=x` (any `utm_*`,
    `fbclid`, `gclid`, `msclkid`) is served the cached `/about`, and is
    made as `/about` is, without them (models don't see them in `$_GET`).
  - Any content change throws the cache away and deletes the cached files
    (`util::content_changed()` in your own models; inside a transaction
    when it commits), and so does the moment a scheduled item is
    published, each one of several (`data/cache/next` keeps every time
    still to come, one per line). A page whose render overlapped the
    change is not kept.
  - A cached page goes out with the headers it was made with (what models
    and listeners set: `X-Robots-Tag`, a CORS header, `Link`), never
    `Set-Cookie`: a page that sets a cookie is not kept. It carries
    `Last-Modified` (the time it was stored, in GMT), and a request whose
    `If-Modified-Since` is not older gets a 304 without the page.
  - A filter page with no items (`/news/news_items/tag/nothing`), a page
    past the last one and a filter on a field the list doesn't have answer
    200 but are never cached, so made-up URLs don't fill the disk. Neither
    is a list or item URL spelled other than the way its links spell it
    (`/news/news_page/1`, `/news/news_page/02`, `/news/news_item/007`, a
    segment left over at the end), or a typed filter spelled other than the
    way the page prints it (`/menu/menu_items/price/14.500` for `14.50`,
    `/events/events_items/date/10 Oct 2026`), or a view name in other
    letter case (`/ABOUT` finds `about.html` on a disk that ignores case,
    as on macOS and Windows). A model that decides the same calls
    `raster_cache::skip()`.
  - **Form stamps stay fresh.** A cached page keeps a marker where each
    form's `raster_ts` goes, and a hit writes one new stamp into every form
    on the page, so a cached form is no easier to replay than a live one.
  - Settings: `page_cache_ttl` (3600 seconds) and `page_cache_skip` (path
    patterns). Responses carry `X-Raster-Cache: hit|miss` (`miss` only when
    the page was stored). `describe` says
    whether the cache is on (`site.page_cache`).
  - **Changes Raster doesn't make itself don't clear it.** Raster's own
    writes do (`write_view`, `update_page`, the item tools, the editor,
    forms). A view, theme file, model or config edited with ordinary file
    tools, or the database changed directly, is not seen by visitors who
    aren't logged in until the cache is cleared or `page_cache_ttl` runs
    out: run `php bin/raster cache clear`, or MCP `clear_cache`. When it
    can't write the cache (its files belong to the web server's user and
    the command runs as another) it says so and exits 1 (MCP: an error),
    and visitors keep the old pages: run it as that user. A content change
    that can't write it logs a warning. `raster upgrade`, and so `update`,
    clears the cache at the end. That also deletes `data/cache/models.json`, where Raster keeps what the
    models declare; it is made again when a model file changes anyway, and
    a site whose `data/` can't be written to simply looks every time.
    `render` and `render_url` never read or write the cache, so a fresh
    render does not mean visitors see the change.

## Static export

`php bin/raster export <folder> [--url=https://example.com/]` writes the site
as plain files for any static host (GitHub Pages, Netlify, a bucket). It
visits every page as a visitor would and follows the links, so items, pages
of lists and filter pages come along; drafts and the editor don't.

- `/about` becomes `about/index.html`, feeds keep their names, a missing
  page becomes `404.html`, and theme files and `media/` are copied.
- Links point at `--url`, else the site's address (`RASTER_URL`, then
  `site_url`), else they are root-relative.
- Every language after the first goes in `/<lang>/`: `/ro/` becomes
  `ro/index.html` and `/ro/menu` `ro/menu/index.html`. A multilingual site
  (`multilingual` on) is crawled at each language's own addresses: every
  view is started in every language (`/menu` and `/ro/menu`), and the links
  its pages carry are followed from there. Each language gets its own
  `404.html` (`ro/404.html`). It warns when the links are root-relative (no
  `--url` or site address: search engines want absolute `hreflang` and
  canonical links). A language with its own `domain_language` host is
  exported as the site serves it: when the site has no `site_url`, the
  export's server is the site's own address and sends that language to its
  domain, so its pages are asked for on that host, written in its folder
  (`ro/`), and linked at the domain (publish that folder there; the export
  warns). With `site_url` set it is under `/ro/` like any other language. With languages but `multilingual` off, each page is
  fetched again in every other language, and the export rewrites its links
  and the language switcher's `?lang=` links to `/<lang>/`.
- **Forms need PHP**, so a page that still shows one stops the export and
  nothing is written. Wrap forms in `<!-- print.if.live -->` and write what
  the static site shows instead in `<!-- print.if.static -->` (a phone
  number, an email address, the RSS feed).
- **A page that answers an error** (5xx, a model that throws) or doesn't
  answer, the 404 page included, also stops the export: it names each such page, writes and removes
  nothing, so the last export stays whole, and exits 1.
- Left out: pages that need an account, the pages the models in use name
  in `requires()` (login, sign-up, password and newsletter pages, wherever
  their settings put them), `/api`, and paths in
  `config::set('export_skip')` or `--skip=/path`, under every language's
  prefix too (`/ro/login`). The command lists links to left-out pages and query
  strings, since a static host can't answer them.
- **Exporting again** to the same folder writes only files that changed and
  removes the ones the site no longer has, so a deploy uploads just those.
  When nothing the site is made of changed (content, templates, models,
  theme, media) and it is the same day, it does nothing. A new day in the
  site's time zone always exports again, so `date>=today` lists and what
  prints from the clock stay current. `.raster-export.json` in the folder keeps
  track; files the export didn't write are left alone. `--clean` starts the
  folder over.

## Editors and agents

- Editors (roles editor and admin) log in at `/login`. They also see drafts.
  Visitors get no cookies until they log in (except `lang`, when they pick a
  language on a site that isn't multilingual).
- **The in-page editor.** For editors, the page itself is the editor: every
  `print.cms` field, collection, item and item field is editable where it
  shows, in the site's own type (`E` or the Edit button starts, changes save
  when you leave a field, every change can be undone). A rich field whose
  place can't hold paragraphs (it is a `<p>`, a heading, an `<li>`, or sits
  inside one) takes Enter as a line break, and pasted paragraphs, lists,
  headings and quotes become lines, so what it stores fits where it prints (in a field that is a list,
  Enter still adds a bullet). A field that already holds paragraphs, say
  in an `<li>` or an `<a>` card, keeps them. Items get a handle to
  edit details, duplicate, hide, schedule or delete them; each list ends with
  a card for a new item, made from the template's mock-up. Photos (`print.@src`)
  are replaced by choosing or dropping a picture, framed in the browser.
  Everything works from the keyboard too: in edit mode Tab reaches each
  item, photo and new-item card, focus shows the item's handle or the photo
  button, Enter moves into the handle (Esc comes back), picks a photo or
  starts the new item. The
  Page panel lists fields the page can't show (in `<head>`, in attributes),
  the lists, and the page's history. The dock sits at the bottom of the
  screen; flick it up (a finger, a mouse dragged and let go, or two fingers
  on a trackpad) and it moves to the top, so nothing under it is out of
  reach. It stays where it was left. Nothing is added for visitors.
- **Admin pages.** Every view that `protected` keeps for `editor` or `admin`
  (`config::set('protected')->to(array('staff' => 'editor'))`) is an
  admin page: the editor's dock has an **Admin** menu listing the ones the
  person may open, by the view's `<title>`. That's the whole registration, so
  staff pages need no hidden menus or `print.if.is_editor` links. A prefix
  covers the views under it (`'staff'` lists `staff.html` and
  `staff/rota.html`), and the strictest matching pattern decides who sees a
  page. `_item` views are left out (they need an item). `describe` lists them
  under `admin_pages`.
  - How it works: while an editor looks at a page the CMS marks what it
    prints (`<!--raster:s 3-->…<!--raster:e 3-->`, details in
    `#raster-editor-config`), and `system/models/cms/editor/editor.js` saves
    through `POST /api/cms/editor_save_field`, `editor_save_item`,
    `editor_delete_item`, `editor_history`, `editor_restore`,
    `editor_upload` and `editor_set_lang`, with the session token. They use the same store as MCP,
    so revisions, field checks and events are the same. A field name with
    `__` (a language's column) is refused.
  - **On a multilingual site** the editor edits the page's language, the
    one its address names: on `/ro/about` it saves the Romanian (through
    `/ro/api/cms/…`, posting `lang`, which must be the page's), and
    `/about` keeps its English. The dock says so ("editing: RO (falls back
    to EN)"); a field with no Romanian yet shows the English and is
    marked, and the Page panel says which fields are not translated yet
    and which are the same in every language (a photo saved on `/ro/` is
    the photo everywhere). An untranslated field holds nothing in that
    language, so an undo there empties it again. The history says which
    language each revision changed, and its Restore brings back every
    language of that version, as it says. In production before
    `schema --apply` has made a language's columns, a text field saved in
    that language is refused (`translation_columns_missing`, worded "run
    php bin/raster schema --apply") instead of landing in the default
    language's column. The config's `lang` is the page's language, and it adds
    `editor_lang`, `languages` and `editor_languages`; off it is as it
    always was.
  - Its colours and fonts come from the page (body text, background, the
    first button's colour as the accent). To choose them, set
    `--raster-accent`, `--raster-on-accent`, `--raster-font`,
    `--raster-heading-font`, `--raster-bg`, `--raster-fg`,
    `--raster-surface` or `--raster-radius` on `:root`.
  - Its words are in English; `system/models/cms/editor/lang/<lang>.php`
    translates them (Romanian is included), and
    `application/i18n/<lang>/raster_editor.php` overrides any of them.
    Which language: without `multilingual`, the page's (`?lang=`, as
    detected). Multilingual, the editor's own, apart from the page's: the
    one the person chose with the dock's picker (kept on the account,
    `user.editor_lang`, which `schema --apply` adds on a multilingual
    site), else the first of
    the browser's languages it has words for, else the site's default
    language, else English. So an English owner fixing `/ro/` gets English
    words.
  - What the server refuses comes back as names, `problems` in the
    answer, and the editor words each as `problem_<name>` (a record's
    `check()` included): a value that isn't of its field's type is
    `type_<type>` (`type_int`, `type_number`, `type_bool`, `type_date`,
    `type_datetime`, `type_time`, `type_text`), with `field`, which the
    words print as `{field}` (`'problem_type_number' => '{field} needs a
    number, like 4.50'`); a picture is `upload_too_big` (also over PHP's
    `upload_max_filesize` or `post_max_size`), `upload_missing`
    (it didn't arrive whole), `upload_not_a_picture` (also for a file the
    browser can't show) or `upload_not_stored`; `form_expired` (the session
    token is stale: reload) and `not_allowed` (not logged in as staff) come
    from the 403 every refused post gets on `/api`. Reword any of them in
    `raster_editor.php`: `'problem_upload_too_big' => 'Pictures up to 12 MB, please'`.
- **MCP** is available in two ways:
  - over stdio: `php bin/raster mcp`, already set up in `.mcp.json`. A PHP
    error in the site's code answers the call with its file and line
    instead of ending the server;
  - over HTTP: POST to `/mcp` with `Authorization: Bearer $RASTER_MCP_TOKEN`.
    It's off until that token is set. Apache hands that header to PHP-FPM
    only when told to: the shipped `.htaccess` copies it
    (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`), and
    `doctor` reports a `.htaccess` without that line (`authorization`).
  - Both answer a batch (a list of messages) with a list. A message that
    isn't an object with a string `method` gets `-32600 Invalid request`,
    a tool that isn't offered `-32602`, and the server goes on.
    `serverInfo.version` is Raster's version (`system/VERSION`).
  - The stdio server lives for the session, and each call reads the
    templates, models and record types again, so what an agent writes
    counts on its next call. PHP can't load a class twice: once a PHP file
    the server has loaded changes (a model's `api()`, `types()` or
    `listens()`, config), a fresh `bin/raster mcp` answers every later
    call, under 100 ms each. Restart the server to make it fast again.
    If that fresh process dies before it answers (a model halfway through
    an edit no longer parses), `ping` is still answered and every other
    request gets `-32603` with PHP's message, until the file is fixed.
- **Tools for the site itself:**
  - `describe` — how URLs reach views, the pages and collections the markup
    declares, the admin pages, the settings that change behaviour, and whether
    the templates lint clean. Ask this first. `sections` narrows it. The
    vocabulary is left out unless `sections` names it; it is most of a full
    answer and has its own tool. (`php bin/raster describe` still prints
    everything.)
  - `vocabulary` — every model with its methods and their signatures, the
    named SQL queries, the events and who listens, the names the CMS keeps.
    Read from the code, so it cannot be out of date. Check it before writing
    an annotation instead of guessing a method name.
  - `annotations` — the grammar, the same data `lint` checks against.
  - `list_views`, `read_view` — the templates.
  - `check_view` — lint markup that isn't written yet; nothing is saved.
  - `write_view` — write a view, but only if it lints: on an error the file is
    left alone and the problems come back. The answer says what the change
    does to the content model.
  - The four view tools take an optional `theme`: the name of a folder
    directly under `views/` (letters, digits, `_` and `-`), the site's theme
    when left out. A path, or a folder that links out of `views/`, is
    refused, and so is a view file that links out of the theme
    (`list_views` leaves it out); a link inside the theme works.
  - `render_url` — the page's status and HTML, no web server. With `as`
    (`editor`, `admin`, `member`, or an account's email) it renders the page
    as that person, outside production only, and for staff also says what the in-page editor marks:
    editable fields, items, and each list with whether it gets a card for a
    new item. It runs in its own process, so a page that dies can't take the
    server down: the command line PHP (next to PHP-FPM's, over HTTP), with
    the server's whole environment, and what the page logs read after the
    page, so a page that logs a lot still answers. It never reads the page
    cache.
  - `clear_cache` — throws the page cache away. Call it after changing
    views, theme files, models or config any way but `write_view`, or the
    database directly; Raster's own tools already clear it.
- **Tools for content:** `site_overview`, `get_page`, `update_page`,
  `page_history`, `list_items`, `get_item`, `create_item`, `update_item`,
  `delete_item`, `run_action` (records), `lint_templates`, `schema_status`.
- **Addressing pages:** by URL (`/about`), by view (`about`), or `site` for the
  `site_*` fields.
- **Writes** are limited to fields in the templates, plus `slug`, `enabled`
  and `published_at` on items. Values are given as their types (`4`,
  `4.5`, `true`, `"2026-10-05"`); text is fine too and is converted the
  same way.
- **`write_view` edits code, not content**, since a template can call any
  model. Over stdio the agent is already on the machine with the files, so it
  is available. Over HTTP it is not offered until the site sets
  `config::set('mcp_write_views')->to(true)`.
- Results come back as compact JSON, in `structuredContent` and as text.
- `describe` leaves out anything that could be a secret: the MCP token and the
  mail transport are never in its settings.

## Extending

- **Override a bundled model:** `models/the_<name>/the_<name>.php` with
  `class the_<name> extends <name>`. Templates keep calling `<name>`; your
  class is used, and its `listens()` counts for `<name>`. The override is
  reached by template calls, events, `/api`, and the mail the bundled
  models send: newsletter and authentication send through
  `controller::model_class('mail')`, so a `the_mail` changes every email
  Raster sends. Other static calls between models (`newsletter::subscribe`,
  `authentication::` helpers) go to the bundled class, and so does the
  language API (`i18n::current()` and the rest), which can't be overridden.
  A helper file in the override's folder (`the_cms/store.php`) is found
  before the bundled one, and so is a query in its `sql/` folder
  (`the_newsletter/sql/confirmed_count.sql`).
- **Core files:** `application/the_<name>.php` is loaded after
  `system/<name>.php`. A `the_config`, `the_controller`, `the_event`,
  `the_log`, `the_database` or `the_template` class there replaces that
  singleton. `util` is all static, so a `the_util` class is never called in
  its place: `the_util.php` is for functions of your own. Config files work
  the same way (`config/the_app.php`, `the_routes.php`, `the_events.php` are
  loaded after the framework's own).
- **Values in scripts and styles:** `"/*- print.model.method /-*/"` is the
  same as `<!-- print.model.method /-->`, written so the file stays valid
  JavaScript or CSS. `lint`, `schema`, `describe` and MCP read it as the
  engine does (the marks are `script_form` in `raster annotations`), so a
  `print.cms` field written this way is a field like any other. A plain
  comment that only starts like it, `/*- header */`, is no annotation and
  hides none of the ones after it.
- **Render into memory:** a render method whose result has the key
  `'__' => true` next to its rows is rendered but not printed; read the HTML later from
  `template::instance()->render_results['model']['method']`.
- **A route to another theme:** `controller::route('print/menu')->to('menu')->from('print')`.
  `lint` (and MCP `lint_templates`, `describe`) checks the site's theme and
  every theme a route serves views from; `--theme=name` checks that one
  alone and `--all-themes` every folder under `views/`. A literal route's
  view there is a page of the site like any other: `schema` (and
  `--apply`), `describe` and MCP `get_page`/`update_page` know its fields
  (`/print/menu`), and `describe` names its `theme`.
- **Error pages.** The framework prints no words of its own to visitors:
  every refusal goes through `controller::refuse($code, $alert)`, which
  sets the status and renders the site's view for it,
  `error_document_<code>` (`controller::error($code)` gives that view, or
  false), and without one answers the bare status: a page whose body is
  only the code (`<h1>403</h1>`). A site words them:
  - `config::set('error_document_404')->to('404')` renders `404.html`
    with status 404, for a URL no view answers.
  - `error_document_403`: a post from another site (alert `cross_site`),
    a logged in person's post with a stale session token (`form_expired`),
    and a protected page someone logged in without the role asked for
    (`not_allowed`). Word each alert in the view
    (`<!-- print.validation.alert('form_expired') -->…`). The refused post
    is never handed to the forms on the 403 page.
  - `error_document_500`: a page that broke outside development (the
    error goes to the log, the visitor never sees it). A 500 page that
    breaks too answers the bare status.
  - `error_document_503`: below.

  A 403, 500 or 503 page is rendered as written: its `cms` fields show
  their defaults, there is no in-page editor, and it is never cached. On `/api` the answer
  is JSON instead, `{"error":"not_allowed","problems":["not_allowed"]}`
  (`{"error":"refused"}` for a refusal without an alert). A file the
  server never serves (`system/private_paths.php`) gets the same bare 403
  from `index.php` under `php -S`.
- **A page for a database outage:** `config::set('error_document_503')->to('503')`
  renders `503.html` with status 503 when the database can't be reached
  (see **Environments and cache**). Keep it to models that don't need the
  database: the `cms` fields show their defaults there.

## Settings

Set in `config/the_app.php` with `config::set('name')->to(value)`.

| Setting | Default | Meaning |
|---|---|---|
| `theme` | `default` | the folder in `views/` |
| `default_view` | `index` | the view for `/` |
| `views_ext` | `.html` | the extension of HTML views |
| `rewrite` | true | false puts `index.php/` in every link, for servers without rewrites |
| `strict_templates` | true in development | template errors stop the page with a list (500) |
| `error_document_404` | none | a view for 404s |
| `error_document_403` | none | a view for refusals: a post from another site, a stale form, a protected page without the role (see **Error pages**) |
| `error_document_500` | none | a view for a page that broke (outside development) |
| `error_document_503` | none | a view for when the database can't be reached (outside development) |
| `site_url` | none | the site's address (same as `RASTER_URL`) |
| `cms_enabled` | true | the CMS and the editor toolbar |
| `raster_page_size`, `<name>_page_size` | 10 | items per page |
| `raster_media_folder` | `media` | where uploads go |
| `feed_limit` | 20 | items in `feed.items` |
| `sitemap_skip` | none | views left out of `feed.pages`, besides private pages and the pages the models in use name in `requires()` (login, account, the newsletter's answers, …) |
| `protected` | none | `array('path' => 'role')` |
| `registration` | true | false turns sign-up off |
| `login_page`, `after_login`, `reset_page` | `login`, none, `reset` | account pages |
| `password_min_length` | 8 | |
| `timezone` | PHP's (`date.timezone`, UTC on most servers) | the site's time zone, `'Europe/Bucharest'`: `today`, scheduled items and every stamp follow it; an unknown name is logged and PHP's kept. `doctor` warns in production when neither this nor php.ini's `date.timezone` is set |
| `booking_notice` | 60 | minutes ahead a guest may book |
| `booking_horizon` | 60 | days ahead a guest may book |
| `booking_max_people` | 12 | people in one booking, for guests; staff may book more |
| `booking_cutoff` | 120 | minutes before the start a guest may still cancel or change by their link |
| `booking_page`, `booking_manage_page` | `book`, `booking` | the booking form's page (`book_url` in emails) and the guest's manage page (their links) |
| `booking_staff_email` | the sender (`RASTER_MAIL_FROM`, `mail_from`) | where staff's booking emails go |
| `contact_staff_email` | the sender (`RASTER_MAIL_FROM`, `mail_from`) | where staff's contact emails go |
| `contact_staff_page` | `staff` | the page with the messages, for `staff_url` in that email |
| `contact_max_length` | 5000 | characters in a contact message |
| `contact_spam_days` | 30 | days a message kept as spam stays; false keeps it |
| `rsvp_collection` | `events` | the markup collection whose items take replies |
| `rsvp_max_people` | 6 | people in one reply, for guests; staff may add more |
| `rsvp_closes` | 0 | minutes before the start replies close, for an event without `rsvp_until` |
| `rsvp_waitlist` | true | false refuses a yes that doesn't fit (`full`) instead of putting it on the waitlist |
| `rsvp_manage_page` | `rsvp` | the guest's page their links open |
| `rsvp_staff_email` | none | where staff's rsvp emails go (`rsvp_new`); empty sends none |
| `newsletter_double_opt_in` | true | false subscribes without a confirmation |
| `newsletter_confirm_page`, `newsletter_unsubscribe_page` | `newsletter-confirm`, `newsletter-unsubscribe` | |
| `mail`, `mail_from` | see `RASTER_MAIL` | transport and sender |
| `multilingual` | false | one address per language (`/ro/menu`) for the `languages`, which need to be two or more (see **i18n**) |
| `languages`, `domain_language`, `language_cookie` | none, none, `lang` | the languages, the first the default; a host that is one language's; the cookie template-only translation remembers the choice in |
| `page_cache`, `page_cache_ttl`, `page_cache_skip` | on in production, 3600, none | |
| `asset_versions` | true | false leaves theme asset URLs as they are written |
| `mcp_token` | none | same as `RASTER_MCP_TOKEN` |
| `mcp_write_views` | false | lets MCP over HTTP write templates (`write_view`); over stdio it always can |
| `api_system_models` | `cms` | system models reachable at `/api`, for what their `api()` lists |
| `spam_min_seconds` | 3 | a post sooner than this after its form was shown is `too_fast`; false turns the stamp checks off |
| `spam_max_age` | 86400 | a stamp older than this many seconds is `stamp_old`; false allows any age |
| `spam_per_ip`, `spam_per_email` | 5, 3 | posts of one form in an hour from one IP, with one address; false turns that limit off |
| `login_per_ip` | 20 | login tries from one address in an hour, whatever the account; over it, `slow_down`. false turns it off |
| `spam_ip_blocks` | false | true holds back a post over `spam_per_ip` too; by default it is only flagged (the contact form keeps it as spam) |
| `spam_max_links` | 2 | links allowed in the checked fields; 0 allows none, false any number |
| `spam_blocklist` | none | words and domains (`casino`, `pills.example`) that make a post `blocked` |
| `trusted_proxies`, `client_ip_header` | private addresses, `X-Forwarded-For` | proxies in front of the site (`array('10.0.0.0/8')`; not set, any private, loopback or link-local address; `array()` none), and the header they send the visitor's address in |
| `allow_deprecated` | none | `array('<id>' => true or path pattern(s))`: uses of deprecated features this site keeps on purpose, so `doctor` counts them apart instead of warning |

Environment variables: `RASTER_ENV`, `RASTER_URL`, `RASTER_DB`,
`RASTER_APP` (the application folder, `application` by default),
`RASTER_MAIL`, `RASTER_MAIL_FROM`, `RASTER_MCP_TOKEN`, `RASTER_SECRET` (the
site's key, below), `NO_COLOR` (plain command-line output). They win over
the settings above.

`RASTER_SECRET` is the site's key for signing form stamps (not a setting, so
`describe` never shows it). Without it the first web request that needs a
key makes `data/secret` (64 hex characters, mode 0600), and the site keeps
using that file. The command line (`raster render`, a script) never makes
it: a file made by a command would belong to that command's user, and
PHP-FPM, running as another, couldn't read it. A command with no file it
can read signs with a key of its own, for that process only. Set the
variable instead when several servers share a site, so they all sign and
check with one key. A `data/secret` the web server can't read, or that
isn't a key, turns the stamp off and logs a warning on every request.
`doctor` can only say what its own user sees: it warns when that user can't
read the file or it isn't a key, so run it as the web server's user to be
sure. Delete the file and let the site make it again, or set
`RASTER_SECRET`. Changing the key makes the forms already shown fail their
stamp check, so people with a page open send it again.

## Checklist for a change

1. Edit or add views in `application/views/<theme>/`. Start from static HTML
   with real content, then annotate. A new site's `style.css` is a plain
   page in the browser's own colours, on purpose: the site's look (colours,
   fonts, layout) is yours to write.
2. `php bin/raster lint` must show no errors. Also read the warnings: they
   point to forms nobody handles, alerts nobody raises, and missing email
   views.
3. `php bin/raster schema` shows the content model you meant to create.
4. `php bin/raster render /the-url`, or `serve`, and check the HTML. For a
   page staff edit, also `render /the-url --as=editor`: it says what the
   in-page editor can still edit there.
5. Don't edit `system/`, `bin/raster`, `index.php`, `.htaccess` or this
   file in a site: `raster update` replaces them. Use the ways in
   Extending instead.

## Keeping Raster up to date

- `php bin/raster update` replaces the framework files with the latest
  release (or `update 2.1.0`, `update <branch>`, `update <folder>`,
  `update <file.tar.gz>`; `--dry-run` shows what would change). It refuses
  when framework files were edited since they were installed
  (`system/checksums.json` records them); `--force` goes ahead. Replaced
  files are kept in `application/data/backups/`. A folder or archive must
  hold `system/boot.php` to count as a release; anything else is refused,
  `--force` or not. An archive packed on a Mac is fine: the `._<name>`
  files macOS `tar` adds and a `__MACOSX/` folder are left out, never
  copied in. A framework file it can't write or remove stops the
  update with exit 1 and the file's name, and the old files are put back,
  so nothing is half updated or recorded as installed.
- It then runs `php bin/raster upgrade` for every app: the steps in
  `system/upgrades/<version>.php` the app still needs (renamed annotations,
  moved files). The version an app is at is in `config/raster-version`.
  An app without that file (one made by hand) is taken as current: `upgrade`
  writes today's version into it and runs no old steps. An app from Raster
  1.x gets them by having `1.0.0` written there first. A `RASTER_APP`
  that names no app folder (missing, without `config/`, or a framework
  folder such as `system/`) is refused with an error and exit 1, and
  nothing is written. When the version file can't be written, upgrade
  says so, names the steps that ran, and exits 1. Upgrade ends by
  clearing the page cache, since new framework files and rewritten views
  make different pages (a cache it can't write is a warning).
  Database changes stay with `raster schema --apply`.
- `php bin/raster doctor` checks PHP, versions, edited framework files,
  templates, the database, whether `.htaccess` still carries every rule in
  `system/private_paths.php`, uses of deprecated features
  (`system/tools/deprecations.php`, minus the ones config `allow_deprecated`
  says are on purpose), a `data/secret` the user running it can't read or
  that isn't a key, a language code it can't read, `multilingual` with
  fewer than two languages and, multilingual, a `domain_language` without
  a site address (`RASTER_URL`, `site_url`) on a host of no language,
  and, in production, one address that sent one form many different
  emails today (many people share it), the site address, mail, tokens,
  whether PHP shows errors (`display_errors`) and whether PHP takes the
  editor's pictures: it warns when `upload_max_filesize` is below 12M or
  `post_max_size` below 13M (`cms_editor::UPLOAD_MB` and the rest of the
  form). It reads the command line's php.ini, which can differ from
  PHP-FPM's.
  Exit 1 when something must be fixed.
- `php bin/raster new <folder>` starts a new site from this copy of Raster.
- `CHANGELOG.md` in the repository lists what changed in each release.

## Working on Raster itself

In the Raster repository, not in sites made with it:

```sh
php tests/run.php                 # framework test suite
php tests/demo.php                # the demo café: every feature, end to end
php tests/update.php              # new, update, upgrade, doctor
php tests/shop.php                # the example shop in shop/: records, checkout, payments
node tests/editor-browser.js      # the in-page editor in Chromium (needs Playwright)
```

The suites start their PHP children (servers, `bin/raster`, MCP sessions) with opcache's file cache (`php_flags()` in `tests/db.php`), so each one reads compiled code instead of compiling the framework again; `RASTER_TEST_OPCACHE=off` turns it off. A suite also removes, at its start, the untracked fixtures a killed run left behind (`test_sweep()`). A run stopped with Ctrl-C, `kill` or a timeout still stops its servers, and `tests/watch.php`, started with every suite, stops them after one killed outright (`kill -9`). The demo suite deletes only the files it put in `media/`.

Run, demo and shop also run on MySQL: `RASTER_DB=mysql://root@127.0.0.1:3306/raster
php tests/demo.php`. Each run makes new, empty databases beside the one named
and drops them at the end. Tests that only mean something on SQLite say why
and count as skipped on MySQL. `update.php` is SQLite only: it tests files.

`php tests/mutate.php` is not one of the tests. It checks the tests: each
entry in `tests/mutations.json` breaks the framework on purpose and the demo
suite must notice. It costs about a minute per mutation, so do not run it
while working on a change, before a commit or as a gate on a branch. Run it
only when asked, or with `--all` before a release or after a wide rewrite
of the framework. Commits that change a mutation's `search` text leave that
entry stale; fixing the entry can wait for the next sweep.

Read `STYLEGUIDE.md` before writing framework code. The short of it: new
code looks like the code around it. Lowercase snake_case names, `array()`,
tabs, no namespaces, type declarations, interfaces or docblock tags, no new
dependencies. Don't reformat lines you aren't changing.

A new feature gets an ID in `demo/README.md`, a use in the demo and a test.
A change that sites must follow gets an upgrade step, and anything it
replaces an entry in `system/tools/deprecations.php` (kept working until the
version it names). To release, bump `system/VERSION` and add a
`## <version>` section to `CHANGELOG.md`, then merge to `master`: the
workflow in `.github/workflows/release.yml` runs the tests, tags
`v<version>` and publishes the release with those notes. Pull requests run
the same tests: the four suites and both lints on PHP 8.1, 8.4 and 8.5,
an update of a new site from GitHub, the framework, demo and shop suites on
MySQL with PHP 8.1 and 8.4 and on MariaDB 11.8 with PHP 8.4, and
`tests/editor-browser.js` in Chromium. The
jobs run side by side, so the slowest one sets the time.
