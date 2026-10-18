# Phase 1 groundwork Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let bundled models in `system/models/` declare everything they need (types, tables, SQL, emails, pages, settings, `/api`) and work safely beside site models, and give sites a time zone and mail that never holds up a form — so the bundled `booking` model (and later RSVP, payments, store) can be built on it.

**Architecture:** One model lookup (`controller::model()`) replaces the five hand-written ones and caches what each model declares; the database stops letting one model's name stick for the next caller; a static `requires()` hook replaces the hardcoded newsletter/authentication lists; mail is kept during the request and sent after the answer.

**Tech Stack:** PHP 8.1+ (no namespaces, types, `[]`, `match`, arrow functions; tabs), RedBean (`system/libraries/rb.php`, the only library), SQLite and MySQL, plain-PHP tests (`test()`, `check()`, `same()`).

**Spec:** `docs/designs/booking.md` ("Depends on") and issue #168 (Phase 1). Issues: #35 #41 #42 #94 #95 #127 #24 (`$querries` only) #122 #126 #84 #87 #124 #40 #47, and two items of #176.

## Global Constraints

- STYLEGUIDE.md in full; no new dependency; SQL in `sql/` files; records through `cms_records`.
- AGENTS.md changes in the same commit as the behaviour; a new demo-visible feature gets an ID in `demo/README.md`.
- Every suite green on SQLite locally: `php tests/run.php`, `php tests/update.php`, `php tests/demo.php`, `php tests/shop.php`, `php bin/raster lint`, `RASTER_APP=demo php bin/raster lint --all-themes`; CI adds PHP 8.1, 8.4 and MySQL. Never run `tests/mutate.php`.
- `tests/run.php:222` must keep passing: a page request loads nothing from `system/tools/`.
- One PR per section below (G1–G4), each released as the next 2.2.x by the merger (writers don't bump versions).
- Sites see no change in behaviour unless a section says so in its CHANGELOG line.

## Decisions (maintainer's calls, made 2026-10-18 while planning)

1. The lookup is `controller::model($name)` in `system/controller.php`.
2. #35: bundled code reaches overridable models through `controller::model_class()` for **mail** (newsletter, authentication, later booking); `mail` uses `static::` inside. Other cross-model calls stay direct; AGENTS.md says which calls `the_` overrides reach.
3. Discovery cache: `data/cache/models.json` (JSON, never PHP in a writable folder), keyed on VERSION + model file mtimes; the views part keyed on view file mtimes (fall back to folder mtimes + `cache clear` if the walk costs > 0.3 ms on the demo).
4. Gating by use applies to bundled `types()` and `requires()` only; `schema()` always counts (newsletter/user tables stay as today).
5. A name passed to `database::instance('x')` applies to that call only (a clone). Documented behaviour changes; CHANGELOG says so.
6. #24: only `$querries` is removed now (G2 rewrites that code). `render.cms.login` and the old validation regions stay with #24 for 2.3.0.
7. The hook is `requires()`; `sitemap_skip` adds to the model-declared pages.
8. `api_system_models` stays as the gate for bundled `/api` (default unchanged); `Origin: null` counts as cross-site in `is_foreign()`.
9. `log://` mail is deferred too (one behaviour); no mod_php `Content-Length` trick.
10. Resend limit is a 5-minute constant (`mail::RESEND_AFTER = 300`); the visitor sees the same answer, no second email. AGENTS.md's "signing up again sends the same link again" changes.
11. The editor's scheduled badge (#84) is fixed in G4.
12. `cms_records::find()` with a `?param` value throws.

## Review Focus

1. **A site's own model or `the_` override with a bundled name** (`mail`, `newsletter`, `booking` later): the site's code must win exactly as before, its `listens()` bound once, never twice. Test in G1.
2. **Upgrading a site with a warm `data/cache`** from 2.2.6: a stale or missing `models.json`, a read-only `data/` — pages still render (fresh scan), nothing written outside `data/cache`. Test in G1.
3. **A model calling another model mid-template** (a site model calls `cms_records::find`, then its own `sql/` query; a view rendering both): the caller's query still resolves. Test in G2.
4. **A request whose mail server hangs or refuses** while the visitor is logged in: the answer arrives at once, the next request is not held by the session lock, the failure is logged and `mail.failed` sent. Test in G4.
5. **Times near midnight and DST in a non-UTC zone** (`Europe/Bucharest` on a UTC server): `today`, scheduled items and `published_at` follow the site's zone. Test in G4.

---

## G1: One model lookup (#35 #42 #41) — first

**Files:**
- Modify: `system/controller.php` (new functions beside `build_model_paths` 78-86; `load_model` 478-516; `call_method` 310-341)
- Modify: `system/boot.php:59-80` (autoloader), `system/event.php:120-136` (`discover`), `system/models/cms/records.php:45-74` (`types()`), `system/tools/schema.php:85-97` (`system_tables`), `system/tools/inspector.php` (329-393 `model_info`, 425-435 `vocabulary`, 480-484 `model_api`, 997-1027 `listeners`, new `lint_models`), `system/models/api/api.php:45-48`, `system/tools/make.php:27`, `system/models/cms/cms.php:143` (`include __DIR__.'/routes.php'`), `system/models/mail/mail.php` (`self::` → `static::` at 78, 96-127), `system/models/newsletter/newsletter.php:121`, `system/models/authentication/authentication.php:441`, `system/util.php:252` (`raster_cache::clear()` deletes `models.json`)
- Test: `tests/run.php`
- Docs: AGENTS.md (Models ~184, Extending 1056-1060, Events ~262, Bundled models intro, cache)

**Interfaces:**
- Produces:
  - `controller::model($name)` → `null` or `array('name', 'class', 'file', 'parent', 'bundled', 'dirs')`. Order: `models/<name>/` wins wholly (`dirs` = only it); else `models/the_<name>/` over `system/models/<name>/` (`class` = `the_<name>`, `parent` = the bundled file, `dirs` = override dir then bundled dir); else `system/models/<name>/`. Static per-request cache.
  - `controller::model_class($name)` → loads the file(s), returns `'the_x'`, `'x'` or `null`.
  - `controller::models()` → `name => model() + array('declares' => array('types' => bool, 'schema' => bool, 'api' => bool, 'requires' => bool), 'listens' => array(event => method|methods))`, from `models.json` when fresh.
  - `controller::uses($name)` → true when any theme's view has `render.<name>.`, `print.<name>.`, `print.@attr.<name>.` or the `/*- -*/` form. Own few lines; must not load `system/tools/`.
  - `call_method` saves and restores the database's `current_model` in a `finally` (G2 relies on it).

- [ ] **Step 1: Write the failing tests** in `tests/run.php`, each with temporary folders under `application/models` (and, for 4, `system/models/zzbook`) removed in `finally`:
  - `test('one lookup: app beats bundled, the_ overrides bundled', …)`: `same('system', …model('feed')['bundled'] ? 'system' : 'app')`; with `models/the_zz/the_zz.php` over a temporary bundled `zz`: `same('the_zz', controller::model_class('zz'))`, `check(class_exists('zz', false))`; with app `models/feed/feed.php`: `same(false, controller::model('feed')['bundled'])` and the bundled `feed.php` is not in `get_included_files()`.
  - `test('the_mail reaches mail sent by newsletter', …)` in a subprocess (as `race_php`): a `the_mail` whose `send()` prefixes the subject with `[T] `; `newsletter::subscribe('a@b.test')` leaves a `.eml` whose subject starts `[T] `.
  - `test('warm requests load no model that only listens', …)` with the prepend counting `get_included_files` (as run.php:222): second `render /about` includes no `models/*/*.php` whose only hook is `listens()`; adding a model with `listens()` binds it on the next request in development.
  - `test('bundled types count only where views use them', …)`: temporary bundled `zzbook` with `types()` → `zzbook` absent from `cms_records::types()`; after writing a view containing `<!-- render.zzbook.form -->`, present.
  - `test('a site model named like a bundled one wins and lint says so', …)`: lint output contains `models/newsletter shadows the bundled newsletter`.
- [ ] **Step 2: Run** `php tests/run.php` — the new tests FAIL (unknown `controller::model`).
- [ ] **Step 3: Implement** the four functions, then switch each caller listed under Files to them. `models.json` written with `raster_cache::write` (fails quietly; fresh scan on read-only disks). On a cache miss load only files whose source contains `function <hook>(` (as event.php:126). `listens()` must return a constant array (AGENTS.md).
- [ ] **Step 4: Run** all suites and both lints — PASS, including run.php:222.
- [ ] **Step 5: Commit** "Raster finds every model in one place, and the_ overrides reach mail sent by other models".

## G2: SQL for bundled models (#94 #127 #95, `$querries`) — after G1; parallel with G3

**Files:**
- Modify: `system/database.php` (17-29 `instance`, 71-92 `find_query`/`named_queries`, 95-112 `query`, `__call`)
- Modify: every `database::instance('cms')` / `('newsletter')` / `('authentication')` / `('feed')` call (store.php:201, feed.php:27,69, cms.php:174,517,567, authentication.php:40, newsletter.php:32) → `database::instance()`
- Create: `system/models/newsletter/sql/confirmed_count.sql`; `newsletter::count()` (174) uses it
- Modify: `system/tools/inspector.php` (496-502 `model_queries`, 1090-1123 `lint_queries`)
- Modify (#176): `system/models/cms/store.php` (`list_options($argument, $from_url = true)`, 130-165), `system/models/cms/records.php:267-282` (`find()`), `system/models/cms/cms.php:421-427` → `cms_records::owner_me($info)`
- Remove (`$querries`): database.php:88-90, `system/tools/deprecations.php:19-25`, `system/upgrades/2.0.0.php:53-60`, `docs/wiki/Updating-Raster.md:65`, `tests/update.php:167-185`
- Test: `tests/run.php`, `tests/update.php`
- Docs: AGENTS.md "SQL in files" (200-237), Records `find()` (~652)

**Interfaces:**
- Consumes: `controller::model($m)['dirs']`; `call_method`'s restore (G1).
- Produces:
  - `database::instance($model = false)`: no name → the singleton (its `current_model` set and restored by `call_method`); a name → a clone bound to that model, the singleton untouched.
  - `database::query_file($model, $name)` → path of the first `<dir>/sql/<name>.sql` over `dirs` (app, `the_`, bundled), or `null`.
  - `database::find_query($model, $name)` → SQL of the file, else `models/sql.php` `$queries[$name]`, else `null`.
  - `query($sql, $params)` never runs `vsprintf`; `protected printf_query($sql, $params)` does, only for `models/sql.php` entries.
  - `cms_records::owner_me($info)` → the user id as a string, or `null`.
  - `cms_records::find()` with a `?x` value throws `InvalidArgumentException("find() takes values, not ?x: pass the value")`; `owner=me` (string or array form) gives the viewer's own records, `array()` when logged out.

- [ ] **Step 1: Write the failing tests** (`tests/run.php`):
  - `test('a model's queries survive calls into bundled models (#94)', …)`: app model `zzq` with `sql/one.sql` (`SELECT 1 AS one`); after `cms_records::find('zzq_type')`, after `newsletter::subscribe()`, after `mail::send_view()` of a view printing a cms field: `same('1', (string)database::instance('zzq')->one()[0]['one'])` and the singleton's next call from a `zzq` template method still finds `one`.
  - `test('bundled and the_ queries are found (#127 #41)', …)`: `check(database::query_file('newsletter', 'confirmed_count') !== null)`; a `models/the_zzq/sql/two.sql` is found for model `zzq`; `vocabulary` lists `newsletter.confirmed_count`.
  - `test('percent signs in SQL files run as written (#95)', …)`: `sql/pct.sql` with `SELECT strftime('%d', '2026-10-05') AS d` → `same('05', …)`; `LIKE '%soup%'` with no params runs; the demo's `dish_names` (`'%s'` in `models/sql.php`) still works.
  - `test('find() refuses ?param and handles owner=me (#176)', …)`: `find('x', 'a=?b')` throws; as a member, `find('zzown', 'owner=me')` returns only their rows; logged out returns `array()`.
  - `tests/update.php`: a site with `$querries` gets no deprecation line (the entry is gone).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the interfaces above; move bundled callers off named instances.
- [ ] **Step 4: Run** all suites and both lints — PASS.
- [ ] **Step 5: Commit** "Bundled models have SQL files, and calling one no longer breaks the caller's queries". CHANGELOG: "`$querries` in models/sql.php is no longer read (deprecated since 2.0); a name passed to `database::instance()` applies to that call only."

## G3: Models declare their needs (#126 #122) — after G1; parallel with G2

**Files:**
- Modify: `system/controller.php` (new `required()`, `is_foreign()`; `guard_post` 216-227 uses `is_foreign`)
- Modify: `system/models/api/api.php` (38-48 → `reachable()`; after 60 the cross-site refusal), `system/tools/inspector.php` (456-458 → `reachable`, 931-939 → `required('emails')`), `system/models/feed/feed.php:55`, `system/tools/export.php:63-68`, `system/tools/describe.php:23-33`, `system/models/cms/records.php:34` (`'requires'` in `$hooks`)
- Modify: `system/models/newsletter/newsletter.php`, `system/models/authentication/authentication.php` (each gets `requires()`)
- Test: `tests/run.php`, `tests/demo.php` (C48, O1)
- Docs: AGENTS.md (Models "What a model declares", Events /api ~296-330, Settings 1097/1109, Static export ~949)

**Interfaces:**
- Consumes: `controller::models()`, `controller::uses()` (G1).
- Produces:
  - `static function requires()` on a model → `array('emails' => array('<region>' => array('<_email/view>', …)), 'pages' => array('<page>', …), 'settings' => array('<setting>', …))`; called when needed (may read config), not cached.
  - `controller::required($key)` → merged `requires()[$key]` of every model in use (bundled ones only when `uses()`).
  - `controller::is_foreign()` → true when Origin (including `null`), Sec-Fetch-Site or Referer says another site.
  - `api::reachable($name)` → the one `/api` rule (not `the_`, not in `api_blocked`, a model exists, bundled ones only in `api_system_models`).
  - `/api` methods listed for any role but `visitor` answer 403 `cross-site` when `is_foreign()`, on every verb.
- Newsletter `requires()`: emails `render.newsletter.signup` → `_email/newsletter_confirm`; pages: `newsletter_confirm_page`, `newsletter_unsubscribe_page` (config, with defaults); settings: `newsletter_double_opt_in`, `newsletter_confirm_page`, `newsletter_unsubscribe_page`.
- Authentication `requires()`: emails `render.authentication.forgot` → `_email/password_reset`; pages: `login_page`, `register`, `forgot`, `reset_page`, `account`; settings: `registration`, `login_page`, `after_login`, `reset_page`, `password_min_length`, `protected`.

- [ ] **Step 1: Write the failing tests**:
  - run.php `test('staff /api methods refuse cross-site requests on every verb (#122)', …)`: an `editor` method over GET with `Origin: https://evil.test` → 403 `cross-site`; with `Origin: null` → 403; same-site GET as editor → 200; a `visitor` method cross-site → 200.
  - run.php `test('required emails come from the model', …)`: lint on a view with `render.authentication.forgot` and no `_email/password_reset` reports the error; a temporary model's `requires()` emails entry is enforced the same way.
  - demo.php C48: the cross-site case; O1: sitemap and export skip account and newsletter pages and follow a moved `newsletter_confirm_page` (`letters/confirm` in the demo).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**; remove the hardcoded lists (inspector 931-939, feed 55, export 63-68, describe 23-33); `sitemap_skip` adds to `required('pages')`.
- [ ] **Step 4: Run** all suites and both lints — PASS; run.php:309 and :1442 unchanged.
- [ ] **Step 5: Commit** "Models declare the emails, pages and settings they need, and staff /api methods refuse other sites on every verb". CHANGELOG notes `sitemap_skip` now adds to the defaults.

## G4: Site time zone, mail after the answer (#84 #87 #124 #40 #47) — parallel with G1, rebased after it

**Files:**
- Modify: `system/config.php` (~159, apply `timezone`), `system/tools/project.php` (doctor 451-463), `system/models/cms/editor.php:80-89` + `system/models/cms/editor/editor.js` (60-73, 210-212, 1139-1145)
- Modify: `system/models/mail/mail.php` (`TIMEOUT` at 201/203, `$kept`, `send`, `send_kept`, `encoded`, `via_mail` 162-164, `sent_lately`)
- Modify: `system/util.php` (new `util::url`), `system/models/newsletter/newsletter.php` (51-54 `page_url`, 85-127 resend, `schema()` 39 `confirm_sent_at`), `system/models/authentication/authentication.php` (262-264, 288, 429-447)
- Modify: `tests/fake_smtp.php` (a `silent` mode)
- Test: `tests/run.php`, `tests/demo.php` (next free I IDs after I9; H13), `tests/update.php`
- Docs: AGENTS.md (Settings `timezone`, Mail ~800, Newsletter ~770, Authentication `forgot`, Records/CMS `today`)

**Interfaces:**
- Produces:
  - Setting `timezone` (e.g. `'Europe/Bucharest'`), applied with `date_default_timezone_set` after `set_environment`; unknown → logged warning, PHP's zone kept. Doctor: production with neither `timezone` nor ini `date.timezone` → warning; unknown zone → fail.
  - `mail::TIMEOUT = 5`; `mail::send(...)` (same signature): CLI or `keep_open()` sends now; in a web request it validates, keeps, returns `true`; `mail::send_kept()` (registered once with `register_shutdown_function`): `session_write_close()`, finish the answer (`fastcgi_finish_request` / `frankenphp_finish_request` / `litespeed_finish_request`, else flush buffers + `flush()`), `ignore_user_abort(true)`, send all on one connection; a failure logs and dispatches `mail.failed`; once a connect fails the rest fail at once.
  - `protected static function encoded($text)` → `mb_encode_mimeheader($text, 'UTF-8', 'B')` for Subject and display names in From/To.
  - `util::url($page = '', $query = array())` → `rtrim(link_uri, '/').'/'.ltrim($page, '/')` plus `?`+`http_build_query($query)` when non-empty; replaces `newsletter::page_url`, `authentication::url`, the reset link (440) and `login_link` (288).
  - `mail::RESEND_AFTER = 300`; `mail::sent_lately($stamp)` → bool. Reset: skip when `reset_expires - 3600` is under 5 minutes ago (same `reset_sent` answer, token kept). Newsletter: `subscriber.confirm_sent_at`, set only when the column exists.
  - Booking-to-come rule (AGENTS.md): SQL binds PHP's now, never SQLite's `date('now')`.

- [ ] **Step 1: Write the failing tests**:
  - run.php `test('the site time zone sets today and published_at (#84)', …)`: with `timezone` `Pacific/Kiritimati` (UTC+14), a `render.cms` list with `date>=today` drops an item dated today in UTC; `published_at` stamps in that zone.
  - update.php: doctor in production warns `timezone` unset; no warning when set.
  - demo.php I10 `a form that sends mail answers at once (#87)`: with `tests/fake_smtp.php` in `silent` mode, a raw `fsockopen` POST of the contact form reads `HTTP/1.1 303` within 1 s; the whole request ends within 10 s; logged in, the next request isn't held.
  - demo.php I11 `mail headers are encoded (#124)`: `From: =?UTF-8?B?` for `Raster Café`; a long accented subject is split into ≤ 75-char words; `-f<address>` reaches a fake `sendmail_path`.
  - demo.php H13 and run.php:2197 (changed): a second sign-up within 5 minutes sends nothing and answers the same; with `confirm_sent_at` back-dated 6 minutes, the same link is sent again. A reset asked twice sends one mail and the first link still works.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the interfaces; editor badge compares against `Date.now() + skew` from the server's `now`.
- [ ] **Step 4: Run** all suites and both lints — PASS.
- [ ] **Step 5: Commit** "Sites have a time zone, and mail goes out after the page answers". CHANGELOG: "Set `timezone` in production (doctor warns). `mail::send()` returns true once a message is kept; delivery failures are logged and send `mail.failed`. Signing up again within 5 minutes sends no second email. Run `schema --apply` (new `subscriber.confirm_sent_at`)."

## After G1–G4

The booking model's own plan (`docs/plans/<date>-booking.md`) is written from `docs/designs/booking.md` against the merged interfaces.
