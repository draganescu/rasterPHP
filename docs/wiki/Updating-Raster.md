# Updating Raster

Raster separates the **framework** from **your site**, so updating one never touches the other.

| Framework (replaced by updates) | Yours (never touched) |
|---|---|
| `system/`, `bin/raster`, `index.php`, `.htaccess`, `AGENTS.md` | `application/` (and any other app folder), `media/`, `CLAUDE.md`, `.mcp.json` |

## Updating

```sh
php bin/raster update --dry-run    # what would change
php bin/raster update              # the latest published release
```

You can also update to a specific version, a branch, a local folder or an archive:

```sh
php bin/raster update 2.1.0
php bin/raster update master
php bin/raster update ../rasterPHP
php bin/raster update raster-2.1.0.tar.gz
```

What happens:

1. The release is downloaded from GitHub (or read from the folder or archive).
2. **Raster checks whether you edited any framework file** since it was installed. It keeps a fingerprint of every framework file in `system/checksums.json`. If something was changed, added or removed, the update stops and lists the files, so your changes aren't silently lost. Move the changes into your app (see [Extending Raster](Extending-Raster)) and run it again, or pass `--force` to go ahead anyway.
3. Going back to an older version also needs `--force`.
4. The framework files are replaced. The old ones are kept in `application/data/backups/`.
5. `raster upgrade` runs for every app in the project (below).

Then:

```sh
php bin/raster doctor
```

and run your own checks. `CHANGELOG.md` lists what changed in each release, and what sites need to do.

## Upgrading an app

Some releases change something your templates or files must follow: a renamed annotation, a moved file. Those changes come as **upgrade steps** in `system/upgrades/<version>.php`, and `raster upgrade` applies the ones your app still needs:

```sh
php bin/raster upgrade --dry-run   # list the steps
php bin/raster upgrade             # apply them
```

`update` already does this for you. Each step checks first whether it's needed, so running it twice is harmless. The version an app has been upgraded to is in `config/raster-version`.

An app without that file, such as a second app you made by copying the first, is taken as current: `raster upgrade` writes today's version into it, runs no old steps and says so in one line. From then on it updates like any other app.

`raster upgrade` works on the app `RASTER_APP` names (`application` by default). A name that isn't an app folder (misspelled, without a `config/` folder inside, or a framework folder such as `system/`) is refused with an error and exit code 1, and nothing is written. When `config/raster-version` can't be written, upgrade says so, names the steps that ran (it checks them again next time), and exits 1.

Upgrade steps only change files. Database changes are always yours to apply, with `php bin/raster schema --apply` in production.

## Deprecated features

When a feature is replaced, the old way keeps working for at least one more release, and `raster doctor` lists where you still use it, with what to do instead. Current deprecations:

| Old | New | Removed in |
|---|---|---|
| `render.cms.login` | `render.authentication.login` (`raster upgrade` changes it) | 2.2.0 |
| `$querries` in `models/sql.php` | `$queries` (`raster upgrade` changes it) | 2.2.0 |
| validation regions `not_empty`, `email_format`, `are_the_same` | `required` / `type="email"` with `validation.field(…)`, and `matches` | 3.0.0 |

If you keep a deprecated feature on purpose for a while, tell `doctor` so it stops warning:

```php
config::set('allow_deprecated')->to(array(
    'legacy-validation-regions' => '#^views/default/contact\.html$#',   // only in this file
    'querries' => true,                                                 // everywhere
));
```

## Checking your version

```sh
php bin/raster version
```

prints the framework's version and the version each app has been upgraded to.

## Upgrading from Raster 1.x

An app from Raster 1.x has no `config/raster-version`, and `raster upgrade` would take it as current. Write `1.0.0` into that file first, then run `php bin/raster upgrade`.

`raster upgrade` handles the file changes: it adds `CLAUDE.md` and `.mcp.json`, adds `.gitignore` files to `data/` and `media/`, renames `render.cms.login` and `$querries`. Old accounts (the `usersdata` table and MD5 passwords) are moved to the new `user` table automatically on a fluid (development) connection, so open the site once that way before freezing it for production. MD5 passwords are re-hashed at the next log in. See the 2.0.0 section of `CHANGELOG.md` for everything else.

## If you work from a git clone

A project cloned from the repository has no `system/checksums.json`, so `update` can't tell whether framework files were edited. It says so, keeps a backup, and records the fingerprints for next time. If you track Raster with git, you can also just pull; then run `php bin/raster upgrade`.
