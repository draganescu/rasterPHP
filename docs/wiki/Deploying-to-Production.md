# Deploying to production

This page walks through putting a Raster site on a server: what the server needs, which files to upload, how to configure the web server so private files stay private, and what to check afterwards.

If your site has no forms or accounts, a [static export](Static-Export) to a static host may be simpler.

## What the server needs

- **PHP 8.1 or newer**, running as **PHP-FPM** (the usual way PHP runs behind a web server; FPM stands for FastCGI Process Manager), with the extensions `pdo`, `pdo_sqlite` (or `pdo_mysql`), `mbstring` and `json`. `openssl` for email over TLS.
- A web server in front: **Apache**, **nginx** or **Caddy**.
- Shell access, to run `php bin/raster` commands.

Any ordinary PHP hosting works. `php bin/raster serve` is for development only; don't use it on a server.

## How a Raster site is laid out on a server

A Raster site is **one folder**, and the web server's document root is that folder. That's convenient, but it means the folder also holds things that must never be downloadable: the framework's code, your models, config files with settings, and the SQLite database with your content and users' password hashes.

All the rules for what is private are kept in one list, `system/private_paths.php`:

| Never served | Why |
|---|---|
| dotfiles and dot folders (`.git`, `.env`, `.htaccess`) except `/.well-known/` | version history, secrets, server config |
| `system/`, `bin/`, `tests/` | the framework and tooling |
| `config/`, `models/`, `data/`, `i18n/` inside any top-level folder | your settings, code, database, translations |
| view files (`.html`, `.rss`, …) under `views/` | they're templates, not pages |
| `.php`, `.phar`, `.sqlite`, `.sql`, `.md`, `.lock`, `.ini`, `.bak` anywhere (except `index.php`) | code, databases, dumps, notes |
| SQLite's `-journal`, `-wal`, `-shm` files | parts of the database |

Everything else (theme CSS and images, `media/`) is served as a normal file, and every other request goes to `index.php`.

`php bin/raster deploy` turns that list into configuration for your web server, so you never write these rules by hand.

## Step by step

### 1. Upload the code

Put the project folder on the server, for example `/var/www/site`, with git or rsync. Upload the framework and your `application/` folder, but **not** your development `application/data/` folder (your local database, cache and saved emails) and not `media/` from your computer, unless you mean to replace what's on the server. Both are excluded from git by their `.gitignore` files.

### 2. Configure the web server

**Apache.** The `.htaccess` file that ships with Raster already has every rule. Apache needs `mod_rewrite` enabled and `AllowOverride All` (or at least `FileInfo`) for the folder. If your `.htaccess` is from an older Raster, regenerate it:

```sh
php bin/raster deploy --config=apache > .htaccess
```

**nginx.**

```sh
php bin/raster deploy --config=nginx --host=example.com --root=/var/www/site --socket=unix//run/php/php8.3-fpm.sock
```

prints a complete `server { … }` block: an exact location for `index.php`, one `return 403` location per private rule, and `try_files` for everything else. Save it in your nginx sites folder, add HTTPS (for example with Certbot), and reload nginx.

**Caddy.**

```sh
php bin/raster deploy --config=caddy --host=example.com --root=/var/www/site --socket=unix//run/php/php8.3-fpm.sock
```

prints a Caddyfile block. Caddy gets HTTPS certificates by itself.

The `--socket` value is where PHP-FPM listens; check your PHP-FPM pool configuration (`listen = …`).

### 3. Set the environment variables

At least:

| Variable | Example |
|---|---|
| `RASTER_ENV` | `production` |
| `RASTER_URL` | `https://example.com/` |
| `RASTER_MAIL` | `smtp://user:password@smtp.example.com:587` |
| `RASTER_MAIL_FROM` | `My Site <hello@example.com>` |

Where to set them depends on your setup. Some common ways:

```ini
; PHP-FPM pool file, e.g. /etc/php/8.3/fpm/pool.d/www.conf
env[RASTER_ENV] = production
env[RASTER_URL] = https://example.com/
```

```apache
# Apache virtual host
SetEnv RASTER_ENV production
SetEnv RASTER_URL https://example.com/
```

```nginx
# nginx, inside the location = /index.php block
fastcgi_param RASTER_ENV production;
fastcgi_param RASTER_URL https://example.com/;
```

```
# Caddy
php_fastcgi unix//run/php/php8.3-fpm.sock {
    env RASTER_ENV production
    env RASTER_URL https://example.com/
}
```

Commands you run in a shell on the server don't see the web server's variables. Export them in the shell too, or prefix commands: `RASTER_ENV=production RASTER_URL=https://example.com/ php bin/raster doctor`.

`RASTER_ENV` matters even if your domain isn't listed in `servers.php` (which already makes it production): being explicit avoids surprises, and `doctor` warns when it's missing.

### 4. Make the writable folders writable

The web server's user (often `www-data`) must be able to write to:

- `application/data/` (the database, page cache, logs of sent mail)
- `media/` (editor uploads)

For example: `chown -R www-data:www-data application/data media`.

SQLite also needs to create temporary files next to the database, which is why the whole `data/` folder must be writable, not only the `.sqlite` file.

### 5. Create the database structure

Production databases are **frozen**: the structure only changes when you say so. On the first deploy, and after every deploy that adds fields to templates:

```sh
RASTER_ENV=production php bin/raster schema --apply
```

This creates the tables and columns your templates declare, plus the ones the bundled models (accounts, subscribers) and your own models' `schema()` methods declare.

If you use MySQL, create the empty database and its user first, and set the connection in `application/config/db/production.php` ([The database](The-Database)).

### 6. Create an editor

```sh
RASTER_ENV=production php bin/raster user you@example.com
```

### 7. Check

```sh
RASTER_ENV=production RASTER_URL=https://example.com/ php bin/raster doctor
```

It checks PHP, versions, framework files, `.htaccess`, folder permissions, templates, the database, and production settings (site address, HTTPS, mail transport, MCP token length, `RASTER_ENV`). Fix everything marked `✗`.

Then test in a browser. A quick way to confirm private files are private:

```sh
curl -I https://example.com/application/data/raster.sqlite    # expect 403
curl -I https://example.com/application/config/the_app.php     # expect 403
```

## After the first deploy

**Content lives on the server; code lives in git.** Editors change content in the production database. Your templates and models travel through git. Don't copy your development database over the production one, or you'll erase what editors wrote.

**A typical update:**

```sh
git pull                                                   # new templates and models
RASTER_ENV=production php bin/raster schema --apply        # new fields, if any
RASTER_ENV=production php bin/raster doctor
```

When you've renamed a field, run the `--rename` that `raster schema` suggests before `--apply`, so content moves to the new name. See [The CMS](The-CMS#changing-fields-safely).

**Backups.** Back up `application/data/raster.sqlite` (or your MySQL database) and `media/`. Everything else is in git.

**Caching.** The page cache is on in production and cleared automatically whenever content changes. Nothing to set up. Links with tracking parameters (`?utm_source=…`, `fbclid`, `gclid`, `msclkid`) are served from the cache like the bare address, so a newsletter or an ad campaign doesn't make every visit render the page again. See [the page cache](Settings-and-Environments#the-page-cache).

**Errors.** In production, a broken template shows a plain error page and the details go to PHP's error log. Run `php bin/raster lint` before deploying so this doesn't happen.

## Checklist

- [ ] PHP 8.1+ with PHP-FPM and the required extensions
- [ ] web server configured from `raster deploy`, with HTTPS
- [ ] `RASTER_ENV=production`, `RASTER_URL`, `RASTER_MAIL`, `RASTER_MAIL_FROM` set
- [ ] `application/data/` and `media/` writable by the web server
- [ ] `schema --apply` run
- [ ] an editor account created
- [ ] `doctor` shows no failures
- [ ] private files return 403
- [ ] backups of the database and `media/` scheduled
