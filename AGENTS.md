# Raster: a guide for agents

Raster is a PHP framework for content sites. Views are plain HTML. The dynamic
parts are marked with HTML comments, and the CMS builds its content model from
those comments. This file is the whole specification. When the code and this
file disagree, the code is wrong.

## Your loop

```sh
php bin/raster serve              # http://localhost:8000, no setup, SQLite
php bin/raster lint               # after every template edit; exit 1 on errors
php bin/raster schema             # what the CMS will store, compared with the database
php bin/raster render /about      # print a page's HTML without a server
php tests/run.php                 # framework test suite
```

In development, a view with template errors, or with errors in the partials it
includes with `dry`, returns HTTP 500 with the list of problems (header
`X-Raster-Template-Errors`) instead of a half-rendered page.

## Layout

```
index.php                     entry point, also the router for `php -S`
bin/raster                    command line
application/
  config/the_app.php          settings (theme, mcp_token, page sizes)
  config/the_routes.php       extra routes (usually not needed)
  config/servers.php          host → environment
  config/db/<environment>.php database connection per environment
  models/<name>/<name>.php    your models: class <name>
  views/<theme>/*.html        views, css, images
  data/                       SQLite database (not served, not committed)
system/                       the framework; don't edit for a site
media/                        uploads from the CMS
```

## URLs to views

- `/` renders `index.html`.
- `/about` renders `about.html`. `/docs/setup` renders `docs/setup.html`.
- A view whose name starts with `_` (like `_layout.html`) is a partial and is
  never served as a page.
- Unknown URLs return 404. `/index` is the same page as `/`.
- View files are templates, not public files: `/application/views/…/*.html`
  returns 403. CSS, images and other assets in the theme folder are served.
- In views, link to other pages by file name: `href="about.html"`. Raster
  rewrites it to `/about`, and the file still works when opened as a static
  mock-up. `index.html` becomes `/`. Assets (`style.css`, `img/x.png`) are
  relative to the theme folder.
- To make a URL render a differently named view, use `application/config/the_routes.php`:
  `controller::route('blog/post')->to('post');`. Patterns are regular
  expressions matched from the start of the path.

## Annotations

An annotation is an HTML comment in one of these exact forms: one space after
`<!--` and one space before `-->` (or ` /-->` for self-closing). Anything else
is ignored at runtime, and `lint` reports it.

| Form | Meaning |
|---|---|
| `<!-- print.model.method -->default<!-- /print.model.method -->` | Replace the block with the string the method returns. If it returns `false` or `null`, the default stays. |
| `<!-- print.model.method /-->` | Same, with no default. |
| `<!-- render.model.method --> … <!-- /render.model.method -->` | Repeat the block once for each row the method returns. Inside, `print.key` refers to a key of the row. |
| `<!-- remove --> … <!-- /remove -->` | Mock-up content. Always removed. Can't be nested. |
| `<!-- res.name --> … <!-- /res.name -->` | Marks a reusable fragment in a view. |
| `<!-- dry.view.name /-->` | Inserts the fragment `res.name` from `view.html`. |

Blocks must nest properly: close the inner block before the outer one.

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
- `print.+attr.key` wraps a tag and appends the value to `attr`.
- `raster_detail_link` is provided for CMS collections: the item's URL.
- `print.@href.raster_filter@author` links to the other items of the
  collection with the same `author` (`/news/news_items/author/<value>/`).
  `raster_filter@author@year` matches on several fields.
- A row value that is itself a list of rows renders its `print.key` block once
  per nested row. Models can use this for one-to-many data, for example a post
  with its comments.
- If the method returns an empty array, the block renders nothing. If it
  returns `false`, the mock-up content stays.

### Method arguments

Literals only: `render.news.latest(3, 'sports', true)`. Numbers, quoted
strings, `true`, `false` and `null` are allowed. Expressions are rejected, and
arguments are never passed to `eval`.

### Built-in models

- `print.session.key`: the value of `$_SESSION['key']`.
- `print.cms.*` and `render.cms.*`: the CMS (below).

## Models

`application/models/products/products.php`:

```php
<?php
class products {
    function featured() {            // render.products.featured
        return array(
            array('name' => 'Chair', 'url' => '/chair'),
        );
    }
    function count() {               // print.products.count
        return '12';
    }
}
```

Methods return strings (for `print`) or lists of associative arrays (for
`render`). Output is not escaped, so escape user input with `util::e($value)`.

Every public method of an application model is also reachable as JSON at
`/api/<model>/<method>/<arg1>/<arg2>`. Of the system models, only `cms` is
reachable (config `api_system_models`). Put anything that changes data behind
a permission check.

Database access uses RedBeanPHP (`R::find`, `R::dispense`, `R::store`, …) or
`database::instance()->query('SELECT … WHERE a = ?', array($a))`. Parameters
are always bound.

## The CMS: the markup is the schema

`print.cms.<field>` and `render.cms.<collection>` are CMS content. You don't
declare a schema; the annotations are the schema.

```html
<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>
```

This declares a page field `headline` for this page. Its first value is
`Hello`, the text inside the block.

```html
<!-- render.cms.news -->
<h2><!-- print.headline -->First post<!-- /print.headline --></h2>
<div><!-- print.body --><p>Text</p><!-- /print.body --></div>
<!-- /render.cms.news -->
```

This declares a collection `news` with fields `headline` and `body`. The
first item is the mock-up content. Collections are site-wide: every view that
renders `cms.news` shows the same items.

**Rules**

- Field and collection names: lowercase letters, digits and `_`, starting with
  a letter.
- Reserved names: fields can't be named after a CMS method (`style`, `login`,
  `session`, `route`, …) or `slug`, `id`, `updated_at`, `enabled`.
  Collections can't be called `users` or `raster`. `lint` reports these.
- Page fields belong to the page's URL (the slug): `/about` and `/` each have
  their own `title`. A field in a `dry` fragment is stored separately for each
  page that includes it. For content shared across the whole site, use a
  collection.
- Collection URLs are routed automatically, but only to views that contain
  `render.cms.<name>`:
  - `/news/news_item/3` renders `news_item.html` (or `news.html`), filtered to
    item 3. Returns 404 if the item doesn't exist.
  - `/news/news_page/2` renders page 2 of `news.html`. The page size is set by
    `news_page_size` or `raster_page_size` (10).
  - `/news/news_items/tag/php` renders `news.html` filtered by `tag = php`.
  - `render.cms.news('featured=1')` filters in the template and adds the
    `featured` field. When the field is new, the newest item gets that value.
- Relationships are by value: items that share a field value (an `author`,
  a `tag`) are linked with `raster_filter@field` and listed at
  `/<name>/<name>_items/<field>/<value>`. For real references between
  collections, write a model (RedBeanPHP has own and shared lists).
- Page edits are versioned: each save stores a new revision.
- Values can contain HTML and are printed as is. An empty value shows the
  template's default content, so to hide something, remove it from the view.

**Changing fields**

- Adding an annotation adds a field. In development it's created on the next
  request. In production run `php bin/raster schema --apply`.
- Renaming an annotation leaves the old column orphaned. `raster schema`
  lists the orphan and, when it can tell, suggests `--rename=table.old:new`.
  Run that to keep the content. It works even if a request already created
  the new column: the content is moved into it.
- `--drop=table.column` or `--drop=table` deletes what no template uses. It
  refuses anything a template still uses, unless you pass `--force`.
- `php bin/raster schema --check` exits with 1 when the templates and the
  database differ. Use it in CI and deploys.

## Environments

`application/config/servers.php` maps host names (regular expressions matched
against the whole name) to environments. Hosts that are not listed are
**production**. `localhost` and `127.0.0.1` only count for requests from the
same machine, because the Host header is chosen by the client. **On a server,
set `RASTER_ENV=production`**. It overrides everything.
Each environment has `application/config/db/<environment>.php`:

- development: SQLite at `application/data/raster.sqlite`, fluid (tables and
  columns are created on demand).
- production: frozen. The schema only changes through `raster schema --apply`.
  If a column is missing, the template's default is shown.

`RASTER_DB=/path/file.sqlite` points either environment at another SQLite
file. `RASTER_URL=https://example.com/` tells the command line the site's URL.
The environment follows from it too, so an unlisted host means production.
`raster render` exits with 1 when the page returns 4xx or 5xx.

## Editors

- `php bin/raster user <name>` creates a CMS user and prints a password.
- Editors log in at `/login` and get a toolbar on every page.
- Visitors browsing the site get no cookies. A session starts at login.

## MCP (agents editing content)

- Local, over stdio: `php bin/raster mcp`. It's already set up in `.mcp.json`.
- Remote, over HTTP: POST to `/mcp` with `Authorization: Bearer <token>`. The
  endpoint is off until `RASTER_MCP_TOKEN` (or `config::set('mcp_token')`) is
  set.

Tools: `site_overview`, `get_page`, `update_page`, `page_history`,
`list_items`, `get_item`, `create_item`, `update_item`, `delete_item`,
`lint_templates`, `schema_status`. Pages can be named by URL (`/about`), view
(`about`) or table. Writes are limited to fields that exist in the templates.
To add a field, edit a view.

## Checklist for a change

1. Edit or add views in `application/views/<theme>/`. Start from static HTML
   with real content, then annotate.
2. `php bin/raster lint` must print no errors.
3. `php bin/raster schema` shows the content model you meant to create.
4. `php bin/raster render /the-url`, or open it with `serve`, and check the HTML.
5. `php tests/run.php` if you touched `system/`.
