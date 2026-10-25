# One language for the controls (Raster side) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything Raster shows the person editing is in one language, chosen by one rule:
1. the language the builder sends, in development mode only;
2. otherwise the site's base language;
3. otherwise English.

Missing words fall back to English one by one. The editor's language dropdown, the per-person choice and the browser guess all go.

**Architecture:** `cms_editor::editor_lang()` becomes the one rule, on every site, multilingual or not. `strings()` always uses it. The server's editor refusals keep their English `error` and gain `problems` codes that the editor puts into words from its dictionary. The fallback login view's words come from the same dictionaries, through `print.cms.word('<key>')`, in the content language. The editor gets 14 new dictionaries.

**Tech Stack:** PHP 8.1+ in Raster style (STYLEGUIDE.md), plain-PHP tests (`test()`, `check()`, `same()`), `tests/editor-browser.js` (Playwright, run in CI).

**Spec:** `docs/designs/controls-language.md`, a copy of raster-builder's `specs/13-ui-language/00-design.md` (approved 2026-10-25). The builder half is `specs/13-ui-language/01-plan.md` there. Its Task 7 sends the header this plan reads.

## Global Constraints

- The header is `Raster-Editor-Language` (`$_SERVER['HTTP_RASTER_EDITOR_LANGUAGE']`). It is read only when `config::get('environment') === 'development'`. The value is lowercased and must match `/^[a-z]{2}(-[a-z]{2})?$/`; anything else is ignored.
- The editor's language is the language the person reads. It doesn't have to be one the editor has words for: `hi` with no `hi.php` gives English words and `hi` dates. Only codes that pass the regex are ever used.
- Dictionaries: `system/models/cms/editor/lang/<code>.php` for `fr de es it pt nl hu pl bg el tr ja hi zh` (`ro` exists). Each returns `array('key' => 'words')`, with the header line `// The in-page editor in <Language>. Keys missing here stay in English.`. Placeholders are `{name}` and must be kept. A site's own `<app>/i18n/<code>/raster_editor.php` still wins.
- Nothing reads `Accept-Language`, `?lang=` or the `lang` cookie to choose the editor's language. Visitor text doesn't change at all.
- One release at the end: one PR from `ui-lang/one-language`, with the CHANGELOG under `## 2.2.53` (or the next free number when it merges) and `system/VERSION` plus the three `config/raster-version` files bumped once.
- Tests run through `/home/vagabond/bin/serial`, one suite at a time, in the foreground. While working, use `RASTER_TEST_ONLY=<substring>`. Before each commit, run only the affected suites (`run.php` for system code, `demo.php` if demo views or demo tests change). CI runs everything. Afterwards, check `pgrep -af 'php.*-S 127.0.0.1'` and kill only your own leftovers. Never run tests/mutate.php.
- AGENTS.md and `docs/wiki/Translations.md` change in the same commit as the behaviour they describe.

## Calls this plan makes

1. **`error` stays English and `problems` is added.** API callers and MCP keep a readable sentence. The editor shows the words for the problem codes. The existing test that checks `"Unknown field 'zzp2b_headline__ro'"` keeps passing.
2. **Problem variables.** The editor's `problem(name, json)` fills `{field}` from `json.field` and `{lang}` from `json.lang`, both as the dock writes them. A raw detail (`json.detail`, an exception's message) is added after the words as `": " + detail`.
3. **The `user.editor_lang` column** is no longer declared. Sites that already have it keep it unused: no data migration, and `schema --apply` drops nothing.
4. **The fallback login view's language** is `i18n::current()` on a multilingual site, else `i18n::default_language()`, else `en`. `<html lang="…">` is set from it. This is `cms_editor::login_lang()`.

## Review Focus

- **The header in production** (published sites, public previews, downloads with `servers.php`) changes nothing. → Task 1 test with `RASTER_ENV=production`.
- **A junk header** (`../../x`, `fr;q=1`, 300 characters) is ignored, and the base language applies. → Task 1.
- **A base language with no dictionary** (`sv`) gives English words, `editor_lang` `sv`, and no warning in the log. → Task 1.
- **A translation that lost a placeholder** (`{name}`) → Task 3's dictionary test refuses it.
- **A browser whose `Accept-Language` is Romanian, on an English-first site** gets English, even on `/ro/` pages. → Task 1.

---

### Task 1: One rule for the editor's language; the dropdown and the per-person choice go

**Files:**
- Modify: `system/models/cms/editor.php` (`editor_lang()`, `pick_editor_lang()` → the rule; `strings()` always uses `editor_lang()`; `inject()` adds `editor_lang` and `languages` only when multilingual, as now, and drops `editor_languages`; delete `set_lang()`), `system/models/cms/cms.php:792,804` (remove `editor_set_lang` from the list and the method), `system/models/authentication/authentication.php:131-135` (stop declaring `editor_lang`; keep `lang`), `system/models/cms/editor/editor.js` (delete `ui.langs` and its `.langs` CSS, and the `editor_language` and `problem_not_multilingual` keys), `system/models/cms/editor/lang/ro.php` (delete the same two keys), AGENTS.md (the editor section: "The editor's own language"), `docs/wiki/Translations.md`
- Test: `tests/run.php`. Replace the P2.B test "the editor's own words…" (around line 10651) with `P2.B: the editor's words: the builder's header in development, the base language otherwise, never the browser`. Update "P2.B: off, …" (around 10691): its `?lang=ro` case now gets the base language's words (`en`, `strings` `{}`). `tests/editor-browser.js:92-94`: the picker step goes. It now checks that the dock has no `.langs` and that its `.lang` reads `editing: RO (falls back to EN)`.

**Interfaces:**
- Produces: `cms_editor::editor_lang()`, which returns a code, or `'en'`, and is cached per request as now. `cms_editor::editor_languages()` stays, because MCP's `read_strings`/`write_strings` use it.

- [ ] **Step 1: Write the failing test** (helpers `p2b_site`, `p2b_server($dir, $fn, $env)`, `p2b_login`, `p2b_config($url, $cookie, $headers)`):
  ```php
  // en, ro multilingual; an editor
  list($c) = p2b_config("$base/ro/zzp2babout", $editor, array('Accept-Language: ro-RO,ro;q=0.9'));
  same(array('ro', 'en', array()), array($c['lang'], $c['editor_lang'], (array)$c['strings']), 'the browser is not asked');
  list($c) = p2b_config("$base/zzp2babout", $editor, array('Raster-Editor-Language: ro'));
  same(array('ro', 'Editează'), array($c['editor_lang'], $c['strings']['edit']));
  list($c) = p2b_config("$base/zzp2babout", $editor, array('Raster-Editor-Language: ../../x'));
  same('en', $c['editor_lang'], 'junk is ignored');
  list($c) = p2b_config("$base/zzp2babout", $editor, array('Raster-Editor-Language: hi'));
  same(array('hi', array()), array($c['editor_lang'], (array)$c['strings']), 'no words yet: English');
  check(!array_key_exists('editor_languages', $c), 'no picker');
  // the same site, RASTER_ENV=production: the header is ignored
  // base language ro: languages ro, en → editor_lang ro, Romanian words
  // base language sv (languages sv, en): editor_lang sv, strings {}
  // POST /api/cms/editor_set_lang is a 404
  ```
- [ ] **Step 2:** `RASTER_TEST_ONLY='P2.B' /home/vagabond/bin/serial php tests/run.php`. It fails.
- [ ] **Step 3:** Implement. `pick_editor_lang()` becomes:
  ```php
  // The builder says whose controls these are; only its preview runs in
  // development, so a visitor of a live site can't change them. Else the
  // site's base language, else English. Never the browser (#<issue>).
  if (config::get('environment') === 'development' && !empty($_SERVER['HTTP_RASTER_EDITOR_LANGUAGE'])) {
  	$sent = strtolower(trim((string)$_SERVER['HTTP_RASTER_EDITOR_LANGUAGE']));
  	if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $sent)) return $sent;
  }
  $base = strtolower(i18n::default_language());
  return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $base) ? $base : 'en';
  ```
- [ ] **Step 4:** The focused run passes. Then `RASTER_TEST_ONLY='P2' …run.php` and `RASTER_TEST_ONLY=multilingual node tests/editor-browser.js` (through serial) pass.
- [ ] **Step 5:** Commit: `The editor's words follow one rule: the builder's header in development, the base language, English (#<issue>)`.

### Task 2: The server's refusals become codes the editor puts into words

**Files:**
- Modify: `system/models/cms/editor.php`. `fail($message, $status = 400, $problem = null, $extra = array())` adds `problems => array($problem)` and `$extra` when a problem is given. Each English `fail()` names its problem:

  | today | problem | extra |
  |---|---|---|
  | `"'$name' takes one value"` | `one_value` | `field` => $name |
  | `'Unknown page'` | `unknown_page` | |
  | `"Unknown field '$field'"` | `unknown_field` | `field` |
  | `'This page is in <lang>: reload it'` | `wrong_language` | `lang` => i18n::current() |
  | `'Unknown collection'` | `unknown_collection` | |
  | `'No such item'` | `no_such_item` | |
  | `'No such revision'` | `no_such_revision` | |
  | `'Something went wrong; it is in the error log'` (broken) | `broken` | |
  | `$e->getMessage()` (a store exception) | `not_saved` | `detail` => the message |

  `system/models/cms/editor/editor.js`: `problem(name, json)` per Call 2, `call()` passes `json`, and the new `problem_*` keys go in `T` (English).
- Test: `tests/run.php`: add `P0.C: the editor's own refusals are codes too: unknown_field, wrong_language, no_such_item, not_saved with its detail`. Each one checks `status`, `error` (unchanged English) and `problems`.

- [ ] **Step 1:** Write the test. It posts to `editor_save_field` with an unknown field, to `/ro/api/cms/editor_save_field` with `lang=en`, and to `editor_item` with id 999999. `not_saved` is caught through a field write that the store refuses, as in the existing test at about tests/run.php:10622.
- [ ] **Step 2:** `RASTER_TEST_ONLY='P0.C' …` fails.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `RASTER_TEST_ONLY='P0.C'` and `RASTER_TEST_ONLY='P2'` pass. `node tests/editor-browser.js` (serial) passes, because the toasts it reads are words, not codes.
- [ ] **Step 5:** Commit.

### Task 3: The fallback login page's words

**Files:**
- Modify: `system/views/cms_admin/login.html`. Every sentence becomes `<!-- print.cms.word('login_…') /-->`, and that includes the alerts' insides and the `placeholder` and `value` attributes. `<html>` → `<html lang="<!-- print.cms.login_lang /-->">`. Also modify `system/models/cms/cms.php` (`word($key)`, `login_lang()`) and `system/models/cms/editor.php` (`const LOGIN_WORDS = array(...)`: the English, with keys `login_title` "Raster CMS Login", `login_heading` "Raster CMS", `login_lede` "Log in to edit this site.", `login_no_database`, `login_no_users`, `login_failed`, `login_slow_down`, `login_username`, `login_username_hint` "Email or username", `login_password`, `login_password_hint` "Your password", `login_submit` "Log in"; and `login_lang()` per Call 4). `system/models/cms/editor/lang/ro.php` gets the Romanian.
- Test: `tests/run.php` "P0.C: the login page words why nobody can log in…" (around 8152) still passes unchanged, because the English is the same. Add `P0.C: the fallback login page is in the content language: /ro/login Romanian on a multilingual site, the base language elsewhere, never the browser`.

**Interfaces:**
- `cms::word($key)` → the words for `$key` in `cms_editor::login_lang()`. It looks in the site's `raster_editor.php`, then Raster's `lang/<code>.php`, then `LOGIN_WORDS`. Escaped with `htmlspecialchars` (ENT_QUOTES), because it is printed in attributes.

- [ ] **Step 1:** Write the test. The site has no `login.html`. Use the starter theme copy in `p2b_site` with its `login.html` removed for the test.
- [ ] **Step 2:** It fails.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `RASTER_TEST_ONLY='login'` passes, and so do `php bin/raster lint` and `RASTER_APP=demo php bin/raster lint --all-themes` (no `untranslatable_text` from the system view).
- [ ] **Step 5:** Commit.

### Task 4: Fourteen dictionaries

**Files:**
- Create: `system/models/cms/editor/lang/{fr,de,es,it,pt,nl,hu,pl,bg,el,tr,ja,hi,zh}.php`
- Test: `tests/run.php`. Add `the editor's dictionaries: every key is one editor.js or LOGIN_WORDS has, every placeholder is kept, and each file is complete`. It reads `T`'s keys from editor.js with a regex over its `var T = {…}` block and compares each file's keys and `{…}` sets.

- [ ] **Step 1:** Write the test, and also run it against `ro.php`, which must pass after Tasks 1 and 3.
- [ ] **Step 2:** Draft each file with one agent per language, in parallel. Each agent keeps every key and `{placeholder}`, uses the informal "you" where the language has one (as `ro.php` does), and keeps `E`, `Esc` and `⌘Z` as they are in `keyboard`.
- [ ] **Step 3:** `RASTER_TEST_ONLY='dictionaries'` passes. `php -l` is clean on each file.
- [ ] **Step 4:** Commit: `The editor in 14 more languages, agent-drafted (#<issue>)`.

### Task 5: Release

**Files:** `CHANGELOG.md` (`## 2.2.53`. Bold lead-ins: the one rule; what went (the dropdown, `editor_set_lang`, the browser guess); the refusal codes; the login page; the 14 languages; "What sites need to do: nothing. A site's own `raster_editor.php` still wins."), `system/VERSION`, `application/config/raster-version`, `demo/config/raster-version`, `shop/config/raster-version`, `docs/wiki/Translations.md`.

- [ ] **Step 1:** `/home/vagabond/bin/serial php tests/run.php`, then `…demo.php`. Both pass.
- [ ] **Step 2:** Push `ui-lang/one-language`, open the PR (draft → ready once CI is green), and wait for CI to be green on every job.
- [ ] **Step 3:** Merge to master. The release workflow (`.github/workflows/release.yml`) runs the tests, tags `v2.2.53` and publishes the release. Tell the builder plan's Task 9 the tag.
