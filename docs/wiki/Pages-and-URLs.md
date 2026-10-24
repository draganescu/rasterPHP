# Pages and URLs

This page explains how Raster turns a web address into a view file, how links and asset paths work, and what to do when a URL and a file name should differ.

## Views and themes

A **view** is a template file. Views live in a **theme** folder:

```
application/views/<theme>/
```

The theme is set in `application/config/the_app.php` and is `default` unless you change it:

```php
config::set('theme')->to('mytheme');
```

A theme folder holds the views, and also the CSS, images, fonts and scripts they use.

## From URL to file

| URL | View |
|---|---|
| `/` | `index.html` |
| `/index` | `index.html` (same page as `/`) |
| `/about` | `about.html` |
| `/docs/setup` | `docs/setup.html` (folders work) |
| `/news.rss` | `news.rss` |
| `/sitemap.xml` | `sitemap.xml` |
| `/feed.json` | `feed.json` |
| `/hours.txt` | `hours.txt` |
| `/nothing-here` | no file: **404 Not Found** |

The query string (`?page=2`) never changes which file is used.

**One address per page.** `/about/`, with a trailing slash, answers with a permanent redirect (301) to `/about`, keeping any query string, so a link someone else writes with the slash doesn't make a second copy of the page for search engines or the page cache. Only GET and HEAD requests are redirected; a form sent to `/about/` is handled where it lands.

**Other formats.** Besides `.html`, a view can be `.rss`, `.atom`, `.xml`, `.json` or `.txt`. The URL keeps the extension, and the right `Content-Type` header is sent. Values printed into these views are escaped for the format (XML escaping for feeds, JSON escaping for JSON) so the file stays valid. See [Feeds, sitemaps and data views](Feeds-Sitemaps-and-Data-Views).

**Files that are never pages.** A file or folder whose name starts with `_` can't be visited, not even through a collection URL: `/_email/digest/news_page/1` is a 404 even when `_email/digest.html` renders `news`. Use this for:

- `_layout.html` and other files holding shared fragments
- `_email/` for email templates

The raw view files themselves are never downloadable either: a request for `/application/views/default/about.html` gets **403 Forbidden**. Other files in the theme (CSS, images) are served normally.

## Links between pages

Link to other pages by **file name**, as you would in a static mock-up:

```html
<a href="about.html">About</a>
<a href="index.html">Home</a>
<a href="news.rss">RSS</a>
```

When Raster serves the page it rewrites these to real addresses: `about.html` becomes `/about`, `index.html` becomes `/`, and `news.rss` becomes `/news.rss` (feed links are only rewritten when the view exists). The same file therefore works both as a static mock-up opened from disk and as a live page.

`href="about.html#team"` becomes `/about#team`. Absolute links (`https://…`) and root-relative links (`/about`) are left as they are.

## Assets: CSS, images, scripts

Asset paths are **relative to the theme folder**. In any view:

```html
<link rel="stylesheet" href="style.css">
<img src="images/logo.svg" alt="">
```

This works on every URL, including `/docs/setup` and `/news/news_item/3`, because Raster adds a `<base>` tag to the top of `<head>` pointing at the theme folder:

```html
<base href='http://localhost:8000/application/views/default/' />
<script>var BASE = "http://localhost:8000/"</script>
```

It also defines a JavaScript variable, `BASE`, holding the site's root address, for scripts that need to build URLs.

Two things follow from the `<base>` tag:

- **In-page anchors need the page name.** `href="#contact"` would point at the theme folder, not the current page. Write `href="about.html#contact"` (which becomes `/about#contact`) or `href="/about#contact"`.
- The tag is only added when your `<head>` is written exactly as `<head>` (no attributes) and when the page doesn't already have a `<base>` tag. If you write your own `<base>`, asset paths are up to you.

Emails and newsletter issues don't get the tag; their relative links and images are turned into absolute addresses instead.

## URL parameters

Raster reads parameters from the path as **key/value pairs**. In a model, `util::param('name')` returns the segment after `name`:

| URL | `util::param('id')` | `util::param('color')` |
|---|---|---|
| `/products/id/7` | `'7'` | `false` |
| `/lab/color/red` | `false` | `'red'` |

`util::param('id', 1)` returns `1` when the parameter is missing.

Query strings work as usual: `util::get('page')` returns `$_GET['page']`, or `false` when it isn't there.

Note that `/products/id/7` still needs a view: by default Raster looks for `products/id/7.html`, which doesn't exist. That's what routes are for.

## Routes: when the URL and the file differ

Add routes to `application/config/the_routes.php`:

```php
<?php
// /blog/post/id/7 renders post.html; the model reads util::param('id')
controller::route('blog/post')->to('post');

// /specials shows menu.html under another address
controller::route('specials')->to('menu');

// /print/menu shows menu.html from another theme (views/print/menu.html)
controller::route('print/menu')->to('menu')->from('print');
```

How routes match:

- The pattern is a **regular expression**, matched from the **start** of the path. `blog` matches `/blog` and `/blog/post/1`, but not `/my-blog`. Escape special characters (`.`, `?`, `+`) if you mean them literally.
- Routes are tried in the order they are written. The first match wins, and a matching route wins over a file with the same name.
- A routed URL is a page of its own: CMS page fields on `/specials` are stored separately from those on `/menu`.
- `->from('theme')` renders the view from another theme folder, for example a print-friendly version of a page. `lint` checks that theme too, and the page's fields are known to `schema`, `describe` and MCP like any other page's.
- A routed URL with an extension renders the view's file in that format: `/news/news_page/2.rss` is page 2 of `news.rss`. When the view has no such file (`/specials.json` with no `menu.json`), the answer is the 404 page.

Most sites need no routes at all. CMS collection URLs such as `/news/news_item/3` and `/news/news_page/2` are routed automatically; see [The CMS](The-CMS).

`php bin/raster lint` reports routes that point to missing views or aren't valid regular expressions as errors.

## Error pages

Raster prints no words of its own to visitors. Without a view of yours, an unknown URL gets the bare status: a page whose body is only `404`. To use your own page, create a view (for example `404.html`) and set:

```php
config::set('error_document_404')->to('404');
```

The page is rendered with status 404, so it can use the layout and CMS fields like any other page. It is always an HTML page, also for a missing `/about.json` or `/feed.rss`. A CMS item URL whose item doesn't exist (`/news/news_item/no-such-post`) also returns 404.

The other refusals work the same way, each with its own setting:

```php
config::set('error_document_403')->to('403');   // a refused post, a page the account can't open
config::set('error_document_500')->to('500');   // a page that broke, outside development
config::set('error_document_503')->to('503');   // the database can't be reached
```

A 403 has three causes, and each raises an alert the view words: `cross_site` (the form was sent from another site), `form_expired` (a logged in person's form was open too long; reload and send again) and `not_allowed` (logged in, but without the role a `protected` page needs):

```html
<!-- print.validation.alert('form_expired') --><p>That form was open a long time. Reload the page and send it again.</p><!-- /print.validation.alert('form_expired') -->
```

These pages show the view as it is written: their CMS fields show the template's text, and the post that was refused never reaches a form on them.

## Sites in a subfolder, and servers without URL rewriting

Raster works out whether it runs at the root of a domain or in a subfolder (`https://example.com/shop/`), and builds links accordingly.

On a server that can't send every request to `index.php` (see [Deploying to production](Deploying-to-Production)), set:

```php
config::set('rewrite')->to(false);
```

Links then go through the entry file: `/index.php/about`. While `rewrite` is on, the other way round, `/index.php/about` answers with a permanent redirect (301) to `/about`.

## Several sites in one install

Each top-level folder with a `config/` folder inside is an **app**: a site with its own settings, models, views and database. `application/` is the default. The repository's `demo/` folder is another app.

Pick an app with the `RASTER_APP` environment variable:

```sh
RASTER_APP=demo php bin/raster serve
RASTER_APP=demo php bin/raster lint
```

On a server, set `RASTER_APP` in the web server's environment for that site. The framework in `system/` is shared.
