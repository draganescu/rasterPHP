# Best practices

Recommendations for building and running sites with Raster, collected from how the framework is designed and how the demo café is written.

## Templates

- **Start from finished static HTML with real content**, then annotate. The page should look right opened straight from disk before any model exists.
- **Link by file name** (`href="about.html"`) and write asset paths relative to the theme folder (`src="images/logo.svg"`). The file then works both as a mock-up and live.
- **Give every CMS field a meaningful default.** It's what editors see first and what shows if the value is ever emptied.
- **Put shared parts in `_layout.html`** as `res` fragments and pull them in with `dry`. Keep fragments one level deep (a `dry` inside a fragment isn't expanded).
- **Put `site_*` fields in the layout**: the site name, footer text, contact details.
- **Use `remove` for extra sample items**, so a list looks full in the mock-up but visitors only see real items.
- **Write every word in the template.** Error and success messages, emails and button labels belong in HTML, not PHP strings.
- **Type short closing tags if you like, then run `lint --fix`** to write the full names in.
- **Run `php bin/raster lint` after every template change.** Also read the warnings: forms nobody handles, alerts nobody raises and validation blocks that can't show are usually real mistakes.

## Models

- **Return `false` when you have nothing to say**, so the template's text shows instead of a gap.
- **Escape what visitors typed** with `util::e()` before returning it to an HTML view.
- **Keep models about data, not wording.** If you catch yourself returning a sentence, return a flag or a value and let the template hold the words.
- **Offer over `/api` only what a script needs.** List those methods in `static function api()` with the least role that may call them; everything else stays off `/api`.
- **Use events for side effects.** When a booking should also subscribe someone to the newsletter, dispatch `reservation.booked` and let another model listen, rather than calling across models.
- **Declare your tables** with a static `schema()` method so `schema --apply` creates them in production.
- **Keep SQL in `.sql` files** in the model's `sql/` folder and call each by name (`database::instance()->in_category($c)`), rather than writing SQL strings in PHP. Use RedBean for one row as an object (`R::dispense`, `R::store`), and always bind parameters.
- **Call `util::content_changed()`** after writing data that pages show, so the cache is refreshed.

## Forms

- **Follow the four steps**: not submitted → `false`; invalid → `form_state()`; do the work; `util::done('name')`.
- **Put the rules in HTML attributes** and one `validation.field` message per field, inside the form.
- **Prefer `util::done()` for success**: the redirect stops a double post on reload.
- **Wrap forms in `print.if.live`** if the site might ever be statically exported, and say in `print.if.static` what shows instead.

## Content

- **Name fields for what they mean** (`headline`, `summary`, `photo`), not how they look (`big_text`, `left_image`). Renames are possible but cost a `schema --rename`.
- **Use a `title`, `headline` or `name` field in collections** so slugs are readable.
- **Use drafts and scheduling** rather than deleting and re-adding items.

## Environments and deploys

- **Set `RASTER_ENV=production` and `RASTER_URL`** on every server, and keep secrets in environment variables.
- **Never copy your development database over production.** Content lives on the server; code travels through git.
- **After each deploy** run `schema` (with `--rename` if you renamed fields), then `schema --apply`, then `doctor`.
- **Add `php bin/raster lint` and `php bin/raster schema --check` to CI**, so a broken template or an unapplied field fails the build.
- **Back up the database and `media/`.**

## The framework

- **Don't edit `system/`, `bin/raster`, `index.php`, `.htaccess` or `AGENTS.md`.** Use `the_<model>` classes, `config/the_*.php` files and events instead. `raster update` then stays a one-command job.
- **Read `CHANGELOG.md`** before updating, and run `doctor` after.
- **When unsure how something is written, look at the demo café** in `demo/`: every feature is used there.
