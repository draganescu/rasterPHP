# Settings and environments

This page covers where settings go, how Raster decides whether it's running in development or production, the page cache, and the full list of settings and environment variables.

## Where settings go

Your settings live in `application/config/the_app.php`, written like this:

```php
<?php
config::set('theme')->to('default');
config::set('protected')->to(array('account' => 'member'));
config::set('after_login')->to('account');
```

This file is loaded after the framework's defaults (`system/config/app.php`), so what you set wins. Read a setting anywhere with `config::get('name')`, or `config::get('name', $default)`. You can add settings of your own the same way; the demo café keeps its staff email address in `config::set('cafe_staff_email')`.

Other files in `application/config/`:

| File | Holds |
|---|---|
| `the_routes.php` | custom URL rules ([Pages and URLs](Pages-and-URLs#routes-when-the-url-and-the-file-differ)) |
| `the_events.php` | event bindings ([Events](Events)) |
| `servers.php` | which host names are which environment (below) |
| `db/<environment>.php` | the database for each environment ([The database](The-Database)) |
| `raster-version` | the Raster version this app was last upgraded to; managed by `raster upgrade` |

**Secrets** (SMTP passwords, tokens) are better kept out of these files and out of git: use environment variables, listed at the end of this page.

## Environments

An **environment** is a name for where the site is running. Raster uses two:

| | development | production |
|---|---|---|
| Database | fluid: tables and columns are created as templates change | frozen: changes only through `raster schema --apply` |
| Template errors | the page shows a list of problems (HTTP 500) | a plain "This page could not be shown", details go to the PHP error log |
| PHP's own errors | shown as php.ini says | never shown to visitors (`display_errors` off, `log_errors` on, whatever php.ini says); `/api` answers `{"error":"server error"}` |
| Database can't be reached | the page says why (HTTP 500) | every page answers 503 (the view `error_document_503`, if set), logged, nothing cached |
| Page cache | off | on |
| Email | written to files in `data/mail/` | sent with PHP's `mail()` unless you set `RASTER_MAIL` |
| Email links | allowed without a configured address | need `RASTER_URL` |

You can define more environments (such as `staging`): add a matching `config/db/staging.php`.

### How the environment is chosen

1. If the environment variable **`RASTER_ENV`** is set, it wins. **Set `RASTER_ENV=production` on every server.**
2. Otherwise the host name of the request is looked up in `application/config/servers.php`:

   ```php
   <?php
   $servers['localhost'] = 'development';
   $servers['127\.0\.0\.1'] = 'development';
   $servers['staging\.example\.com'] = 'staging';
   ```

   Keys are regular expressions matched against the **whole** host name (without the port). `localhost` and `127.0.0.1` only count when the request really comes from the same machine, so nobody can switch your live site into development by sending a fake `Host` header.
3. A host that isn't listed is **production**.
4. Without a `servers.php` file, everything is development.

On the command line there is no request, so Raster uses the host from `RASTER_URL`, or `localhost` when it isn't set. That makes commands run in development by default. Setting `RASTER_URL=https://example.com/` alone therefore also switches a command to production (the host isn't listed). To be explicit, set `RASTER_ENV`:

```sh
RASTER_ENV=production php bin/raster schema --apply
```

## The site's address

Set it for production:

```sh
RASTER_URL=https://example.com/
```

or `config::set('site_url')->to('https://example.com/')`. With it, every link Raster builds (in pages, emails, feeds, sitemaps) uses that address rather than the `Host` header the visitor's browser sent. In production, emails with links are not sent without it, and `raster send` requires it. On the command line it also tells commands like `render` and `user` which address to print.

## The page cache

In production, whole pages are saved and sent again to the next visitor without running any PHP models. This makes the site fast on small servers.

- Only pages for visitors **without a session** (not logged in) and **without a query string** are cached. `/api`, `/mcp` and `/login` never are.
- **Any content change clears the whole cache**: a save in the editor, over MCP or from the command line. When a scheduled item's publish time arrives, the cache is cleared too.
- If your own model changes data that pages show, call `util::content_changed()` afterwards.
- **Changes made outside Raster don't clear it**: a view, theme file, model or config edited in a text editor (or by an agent with plain file tools), or the database changed directly. Visitors who aren't logged in keep seeing the old page until the cache is cleared or `page_cache_ttl` runs out. Clear it with `php bin/raster cache clear`, or the MCP tool `clear_cache`. `raster render` and MCP `render_url` never read the cache, so they show the change before visitors do.
- Responses carry an `X-Raster-Cache: hit` or `miss` header, so you can check what happened.
- Cached files are in `application/data/cache/`. `describe` says whether the cache is on (`site.page_cache`).

Settings:

```php
config::set('page_cache')->to(true);            // force on (or false: force off), whatever the environment
config::set('page_cache_ttl')->to(600);         // seconds a copy stays fresh (default 3600)
config::set('page_cache_skip')->to(array('lab', 'shop/cart'));   // path patterns never cached
```

Use `page_cache_skip` for pages that must be fresh on every visit (a random quote, a live stock count).

## All settings

Set with `config::set('name')->to(value)` in `config/the_app.php`.

| Setting | Default | Meaning |
|---|---|---|
| `theme` | `default` | the folder in `views/` |
| `default_view` | `index` | the view for `/` |
| `views_ext` | `.html` | the extension of HTML views |
| `rewrite` | `true` | `false` puts `index.php/` in every link, for servers that can't rewrite URLs |
| `strict_templates` | `true` in development | template errors stop the page with a list (HTTP 500) |
| `error_document_404` | none | a view for 404 pages |
| `error_document_503` | none | a view for when the database can't be reached, outside development; use no model that needs the database in it |
| `site_url` | none | the site's address (same as `RASTER_URL`) |
| `cms_enabled` | `true` | the CMS and the editor |
| `raster_page_size` | `10` | items per page in every collection |
| `<name>_page_size` | none | items per page in collection `<name>` |
| `raster_media_folder` | `media` | where editor uploads go |
| `feed_limit` | `20` | items in `feed.items` |
| `sitemap_skip` | login, account, reset, forgot, register, newsletter pages | views left out of `feed.pages` |
| `protected` | none | `array('path' => 'role')`: pages that need an account |
| `registration` | `true` | `false` turns sign-up off |
| `login_page` | `login` | where protected pages send visitors |
| `after_login` | none | where to go after logging in |
| `reset_page` | `reset` | the page password-reset emails link to |
| `password_min_length` | `8` | |
| `newsletter_double_opt_in` | `true` | `false` subscribes without a confirmation email |
| `newsletter_confirm_page` | `newsletter-confirm` | |
| `newsletter_unsubscribe_page` | `newsletter-unsubscribe` | |
| `mail` | see `RASTER_MAIL` | how email is sent |
| `mail_from` | see `RASTER_MAIL_FROM` | the sender |
| `languages` | none | e.g. `array('en', 'ro')`, default first |
| `domain_language` | none | e.g. `array('example.ro' => 'ro')` |
| `language_cookie` | `lang` | the cookie that remembers a language choice |
| `page_cache` | on in production | |
| `page_cache_ttl` | `3600` | seconds |
| `page_cache_skip` | none | path patterns |
| `export_skip` | none | paths or `#regex#` patterns left out of `raster export` |
| `mcp_token` | none | turns on MCP over HTTP (same as `RASTER_MCP_TOKEN`) |
| `mcp_write_views` | `false` | lets MCP over HTTP rewrite templates |
| `api_system_models` | `cms` | bundled models reachable at `/api`, for what their `api()` lists |
| `api_blocked` | `mcp`, `api` | models never reachable at `/api`. Setting it replaces the list, so keep `mcp` and `api` in it: `array('mcp', 'api', 'billing')` |
| `allow_deprecated` | none | deprecated features this site keeps on purpose, so `doctor` doesn't warn |

## Environment variables

Environment variables win over the settings above. Set them in your server's configuration (PHP-FPM pool, Apache `SetEnv`, a `.env` loaded by your process manager, a container definition) or before a command.

| Variable | Meaning |
|---|---|
| `RASTER_ENV` | the environment: `production`, `development`, … |
| `RASTER_URL` | the site's address, e.g. `https://example.com/` |
| `RASTER_DB` | path of the SQLite file to use |
| `RASTER_APP` | the app folder, `application` by default |
| `RASTER_MAIL` | the mail transport: `log://`, `mail://`, `smtp://…`, `smtps://…` |
| `RASTER_MAIL_FROM` | the sender, e.g. `My Site <hello@example.com>` |
| `RASTER_MCP_TOKEN` | turns on MCP over HTTP; clients must send this token |
| `NO_COLOR` | plain command-line output, without colours |
| `RASTER_REPO` | the GitHub repository `raster update` downloads from (default `draganescu/rasterPHP`), for forks |
