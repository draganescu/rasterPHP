# Getting started

This page takes you from nothing to a running Raster site on your own computer, with an account that can edit it.

## What you need

- **PHP 8.1 or newer**, run from a terminal. Check with `php -v`.
- These PHP extensions: `pdo` with the `pdo_sqlite` driver, `mbstring` and `json`. Most PHP installs have them. Useful but optional: `openssl` (email over TLS, downloading updates), `gd` (image handling), `phar` and `zlib` (unpacking updates).
- **git**, to download Raster.

You do **not** need a web server (Apache, nginx), a database server (MySQL), Composer or Node. Raster uses PHP's built-in development server and **SQLite**, a database that lives in a single file inside your project. There is nothing to install or configure for it.

`php bin/raster doctor` checks all of this for you once you have the code.

## 1. Get the code

```sh
git clone https://github.com/draganescu/rasterPHP.git raster
cd raster
```

The repository contains the framework, a starter site in `application/`, the demo café in `demo/`, and the test suites. You can work in `application/` directly, but for a real project it is cleaner to create a fresh copy that holds only what a site needs:

```sh
php bin/raster new ../mysite
cd ../mysite
```

`raster new` copies the framework and the starter site into an empty folder, and leaves out the demo, the tests and other repository files.

## 2. Run the site

```sh
php bin/raster serve
```

Open http://localhost:8000. You should see the starter site: a home page, a news page, an about page, log-in and sign-up pages, and a newsletter form in the footer.

`serve` runs PHP's built-in web server. Stop it with Ctrl+C. To use another port: `php bin/raster serve --port=8080`.

The first request creates the SQLite database at `application/data/raster.sqlite`. You never have to create tables yourself (see [The database](The-Database)).

## 3. Create an editor account

In a second terminal, in the same folder:

```sh
php bin/raster user admin@example.com
```

This creates an account with the **admin** role and prints a random password:

```
User 'admin@example.com' saved as admin. Password: 0d27c40747d6672806
Log in at http://localhost:8000/login
```

To choose the password, the role or the display name yourself:

```sh
php bin/raster user admin@example.com --password=a-long-password --role=editor --name="Ada Lovelace"
```

Running the command again for an existing account resets its password. `php bin/raster users` lists all accounts.

## 4. Edit a page

Go to http://localhost:8000/login and log in. Then open the home page. You now see an **Edit** button. Press it (or the `E` key), click the headline, change it, and click somewhere else. The change is saved. Reload the page: it stays.

That headline came from this line in `application/views/default/index.html`:

```html
<h1><!-- print.cms.headline -->Write HTML. Get a CMS.<!-- /print.cms.headline --></h1>
```

You didn't create a database table or an admin form for it. The comment told Raster "this is an editable field called `headline`, and its starting text is *Write HTML. Get a CMS.*". [The CMS](The-CMS) explains the rest.

## 5. Look around the project

```
index.php                     the single entry point for every request
.htaccess                     rules for Apache (generated, see Deploying)
AGENTS.md                     the full specification
CLAUDE.md, .mcp.json          files that help AI coding assistants (see Working with AI agents)
bin/raster                    the command line tool
system/                       the framework itself — don't edit it
media/                        pictures uploaded by editors
application/                  your site
  config/the_app.php          your settings
  config/the_routes.php       custom URL rules (rarely needed)
  config/the_events.php       event bindings (see Events)
  config/servers.php          which host names are development
  config/db/development.php   database settings for development
  config/db/production.php    database settings for production
  config/raster-version       the Raster version this site was last upgraded to
  models/<name>/<name>.php    your PHP classes
  views/default/              your HTML templates, CSS and images ("default" is the theme name)
  data/                       the SQLite file, the page cache, emails saved in development
```

Everything you write goes in `application/` (and `media/` fills itself). Leave `system/`, `bin/raster`, `index.php`, `.htaccess` and `AGENTS.md` alone: [updating Raster](Updating-Raster) replaces them. If you need to change how the framework behaves, see [Extending Raster](Extending-Raster).

## 6. Check your work as you go

Two commands are worth running after every change:

```sh
php bin/raster lint          # checks every template for mistakes
php bin/raster render /about # prints the HTML of a page, no browser needed
```

`lint` catches typos in comment markers, blocks that are never closed, methods that don't exist and similar problems, with the file and line. In development, a page with such a mistake shows an error list instead of half a page. See [Troubleshooting](Troubleshooting).

## Next

- [How Raster works](How-Raster-Works): the ideas behind what you just saw.
- [Tutorial: your first site](Tutorial-Your-First-Site): build a page step by step.
