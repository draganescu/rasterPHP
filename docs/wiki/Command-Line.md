# Command line

Raster's command-line tool is `bin/raster`. Run it with PHP from your project folder:

```sh
php bin/raster <command> [options]
```

`php bin/raster help` lists the commands. Most commands work on one app: `application/` by default, or another with `RASTER_APP=demo php bin/raster …`. They run in development unless you set `RASTER_ENV` (see [Settings and environments](Settings-and-Environments#how-the-environment-is-chosen)).

Many commands take `--json` for output a script can read. Commands exit with code `0` on success and `1` (or `2` for wrong usage) otherwise, so you can use them in scripts and CI pipelines.

## Everyday

### serve

```sh
php bin/raster serve [--port=8000] [--host=localhost]
```

Runs the site with PHP's built-in web server. For development only; see [Deploying to production](Deploying-to-Production) for servers.

### lint

```sh
php bin/raster lint [--fix] [--json] [--theme=name] [--all-themes]
```

Checks every view of the theme: annotation spelling, blocks that aren't closed or don't nest, models and methods that don't exist (with the nearest real name), the number of arguments, reserved CMS names, attributes an `@` annotation sets that the tag doesn't have, `dry` fragments that don't exist, forms nobody handles, alerts nobody raises, validation blocks that can never show, routes to missing views, event bindings to missing methods, and named SQL queries that don't exist.

Problems are **errors** (the page won't work) or **warnings** (probably a mistake). Exit code 1 means at least one error.

`--fix` repairs what has only one right answer (annotation spacing, short closing tags) and then reports the rest.

```
application/views/default/team.html:11:20: error: Model 'office' has no public method 'todya' (application/models/office/office.php); did you mean 'today'?
```

### render

```sh
php bin/raster render /about
php bin/raster render '/bookings?stylist=Ana' --as=editor
```

Prints the HTML of a URL without a web server. Exits with 1 when the page answers with an error (404, 500), which makes it handy in tests. A query string fills `?…` as a browser's would.

`--as` renders the page as someone: an account's email or username, or a role (`editor`, `admin`, `member`) for a stand-in with that role. As staff, it also prints on stderr what the [in-page editor](The-In-Page-Editor) can do on the page: how many fields it can edit, the items, and each list with whether it gets a card for a new item. Use it after changing a page staff edit, to see that editing still works there. It refuses to run in production, where it would show any account's pages without a password: check on a development copy.

### schema

```sh
php bin/raster schema [--apply] [--check] [--rename=table.old:new] [--drop=table.column] [--drop=table] [--force] [--json]
```

Compares the content model your templates declare with the database, and changes the database. See [The CMS](The-CMS#changing-fields-safely).

### doctor

```sh
php bin/raster doctor [--json]
```

A health check: PHP version and extensions, whether the app needs upgrade steps, whether framework files were edited, whether `.htaccess` has every protection rule, whether `data/` and `media/` are writable, template errors, database drift, deprecated features in use, and in production the site address, mail transport, MCP token and `RASTER_ENV`. Exit code 1 when something must be fixed. Run it on the server after every deploy.

## Accounts

```sh
php bin/raster user <email or username> [--role=admin|editor|member] [--password=…] [--name="…"]
php bin/raster users
```

`user` creates an account, or updates the role and password of an existing one. The default role is `admin`; without `--password` a random one is printed. `users` lists accounts with their role and last log-in. See [Accounts and roles](Accounts-and-Roles).

## Newsletter

```sh
php bin/raster send <url> [--to=email] [--subject="…"] [--dry-run] [--again]
```

Sends a page of the site as a newsletter issue. Needs `RASTER_URL` (or the `site_url` setting). See [Newsletter](Newsletter#sending-an-issue).

## Publishing

### export

```sh
php bin/raster export <folder> [--url=https://example.com/] [--skip=/path] [--clean]
```

Writes the whole site as static files. See [Static export](Static-Export).

### deploy

```sh
php bin/raster deploy --config=apache
php bin/raster deploy --config=nginx --host=example.com --root=/var/www/site --socket=unix//run/php/php8.3-fpm.sock
php bin/raster deploy --config=caddy --host=example.com --root=/var/www/site
```

Prints the web-server configuration that keeps private files private. See [Deploying to production](Deploying-to-Production).

## For agents and tooling

| Command | Prints |
|---|---|
| `php bin/raster describe [--json] [--sections=pages,vocabulary]` | the whole site in one answer: how URLs reach views, pages and collections, every callable model method, settings, lint state. Sections: `site`, `routing`, `pages`, `collections`, `vocabulary`, `settings`, `lint`, `schema`, `views`. |
| `php bin/raster vocabulary [--json]` | every model with its methods and their parameters, named SQL queries, events and listeners, reserved names |
| `php bin/raster annotations [--json]` | the annotation grammar, as data |
| `php bin/raster mcp` | runs the MCP server over standard input/output |
| `php bin/raster cache clear` | throws the [page cache](Settings-and-Environments#the-page-cache) away, after editing views, models or the database by hand |

These are also useful to humans: `vocabulary` is the quickest way to see what a template can call. See [Working with AI agents](Working-with-AI-Agents).

## Projects and versions

| Command | Does |
|---|---|
| `php bin/raster new <folder>` | creates a new site in an empty folder from this copy of Raster |
| `php bin/raster version` | the framework's version and each app's |
| `php bin/raster update [latest\|2.1.0\|<branch>\|<folder>\|<file.tar.gz>] [--dry-run] [--force]` | replaces the framework files with another release, then upgrades every app |
| `php bin/raster upgrade [--dry-run]` | runs the upgrade steps this app still needs |

See [Updating Raster](Updating-Raster).
