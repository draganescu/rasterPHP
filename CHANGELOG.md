# Changelog

Every release lists what sites need to do. `php bin/raster update` does the
file changes for you; `php bin/raster doctor` shows what is left.

## Unreleased

- A new in-page editor replaces the old toolbar and its modal forms: the
  page is the editor, it takes the site's colours and fonts, saves as you
  go with undo, handles items (details, duplicate, hide, schedule, delete,
  add from the template's mock-up), photos (choose or drop, framed in the
  browser), fields the page can't show, and the page's history. English and
  Romanian. No jQuery or other scripts from elsewhere.
- `print.@attr.model.method` sets an attribute outside render blocks, so a
  page can have photo fields: `<!-- print.@src.cms.photo --><img src="a.jpg"><!-- /print.@src.cms.photo -->`.
- In render blocks, `print.@attr.key` with an empty value keeps the
  mock-up's attribute instead of emptying it.
- Removed: the old editor endpoints (`edit_variable`, `edit_data`,
  `edit_item`, `add_item`, `remove_item`, `upload_media`, `crop_media`,
  `css`, `script`) and posting `raster_action` to a page.

- Named queries: calling one that doesn't exist throws
  `BadMethodCallException` (it returned `false`), and `lint` reports such
  calls. `:name` placeholders are documented.

## 2.0.0

RTO v2. Raster becomes a framework for sites, small apps, blogs and
newsletters made by people and agents. [AGENTS.md](AGENTS.md) is the
specification; the pattern is at
https://draganescu.github.io/rto/specs/2014/06/29/rto.html.

**New**
- PHP 8.1+, SQLite by default, `php bin/raster serve` with no setup.
- The CMS schema comes from the markup: page fields, site-wide `site_*`
  fields, collections with slugs, drafts, scheduled items, ordering, limits,
  filters and pagination; page revisions; `raster schema` to compare,
  apply, rename and drop.
- Forms: rules from HTML attributes, messages from the template, several
  forms per page, alerts, `form_state`, protection against other sites and
  bots.
- Bundled models: authentication (accounts, roles, protected pages, reset
  by email, lockout), newsletter (double opt-in, one-click unsubscribe,
  `raster send`), mail (log, mail(), SMTP with STARTTLS), feed, pagination,
  i18n, validation.
- Formats: `.rss`, `.atom`, `.xml`, `.json` and `.txt` views, escaped for
  their format.
- Events between models: `event::dispatch('model.happened', $payload)`,
  listeners declared with `static function listens()` or bound in
  `config/the_events.php`, checked by `lint` and listed by MCP. The bundled
  models send `authentication.*`, `newsletter.*`, `cms.*`, `mail.*` and
  `content_changed`.
- Page cache for visitors, cleared by any content change.
- MCP over stdio and HTTP; `raster lint`, `render`, `user`, `users`.
- Several apps in one project (`RASTER_APP`), and the demo café.
- `raster new`, `update`, `upgrade`, `doctor` and `version`.

**Upgrading from 1.x** (`raster upgrade` does the first four)
- `CLAUDE.md` and `.mcp.json` are added for agents.
- `data/` and `media/` get a `.gitignore`.
- `render.cms.login` becomes `render.authentication.login`.
- `models/sql.php` names its array `$queries` (`$querries` still works
  until 2.2.0).
- Accounts from the old `usersdata` table and md5 passwords keep working;
  passwords are rehashed at the next login.
- Regions named `not_empty`, `email_format` and `are_the_same` still work
  until 3.0.0; use HTML attributes with `validation.field('name')`, and
  `matches`.
- The toolbar logs out with a POST to `/api/cms/logout`;
  `/login/logout/fromraster` is gone.

**Deprecated**
- `render.cms.login`, `$querries` (removed in 2.2.0).
- `not_empty`, `email_format`, `are_the_same` regions (removed in 3.0.0).
