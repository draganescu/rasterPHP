# Changelog

Every release lists what sites need to do. `php bin/raster update` does the
file changes for you; `php bin/raster doctor` shows what is left.

## 2.2.55

What sites need to do: a site that still uses `render.cms.login` or the validation regions `not_empty`, `email_format` or `are_the_same` changes them by hand (`raster lint` points at each one). A site that names a `404.html` (or `403`, `500`, `503`) view as its error page in config is unchanged; one that doesn't gets a lint warning.

**Two deprecated features are gone (#24).** Both were deprecated in 2.0.0, and `raster doctor` has warned about them since.
- `render.cms.login` was due in 2.2.0. Use `render.authentication.login`. The 2.0.0 upgrade step that renamed it is gone too, so an app coming from Raster 1.x renames it by hand. `raster lint` reports the old block as an error ("render.cms.login was removed in 2.2.55"), since without it the block would be read as a list named `login` and its form would never show.
- The validation regions `not_empty`, `email_format` and `are_the_same` were due in 3.0.0. Put `required` and `type="email"` on the input and use `render.validation.field('email', 'required')` and `render.validation.field('email', 'email')` for one message per broken rule, and `render.validation.matches('password', 'password_again')` instead of `are_the_same`. `raster lint` reports any left as an unknown validation rule.
- Their entries in `system/tools/deprecations.php` are gone. The file, `doctor`'s check and `allow_deprecated` stay, for the deprecations still listed. The demo café's contact and reset forms use the current rules, and its `allow_deprecated` setting is gone.

**The sitemap leaves out the site's error pages (#209).** A view named in `error_document_403`, `_404`, `_500` or `_503` is no longer listed in `sitemap.xml`. A `404.html` (or `403`, `500`, `503`) without its setting is a page of its own: `/404` answers 200 and is listed, so `raster lint` now warns and says which setting to add.

**`bin/raster` refuses a `RASTER_APP` that isn't an app folder (#41).** With a missing folder, the app base was `/` and the tools walked the disk until PHP gave up. Every command now stops at once with `error: <app>/ is not a Raster app folder. Check RASTER_APP.` and exit 1. `index.php` keeps falling back to `application`, as before.

**`composer.phar` is gone from the repository (#48).** Nothing used it, and sites never got it (`raster new` and `raster update` copy only the framework and the starter). A `.phar` in a site folder is still refused by the server.

## 2.2.53

What sites need to do: nothing. A site's own `i18n/<lang>/raster_editor.php` still wins over Raster's words.

**The editor's language follows one rule.** Everything Raster shows the person editing (the in-page editor, its messages, the fallback login page) is in one language: in development, the one the sibip builder sends in the `Raster-Editor-Language` header; otherwise the site's base language, the first in its list; otherwise English. It is the same on multilingual and one-language sites, and editing `/fr/` no longer changes the buttons. A missing word falls back to English on its own. The editor's dates follow the same language, so the config has `editor_lang` on every site now. A code with no dictionary (`ko`) gets English words and its own dates. Published sites and public previews run in production, where the header is ignored.

**What went:** the dock's language dropdown, the `editor_set_lang` endpoint (now a 404), the `user.editor_lang` column (no longer declared; a site that has it keeps it, unused), and every guess from the browser: nothing reads `Accept-Language`, `?lang=` or the language cookie to pick the editor's words.

**The editor's refusals are codes it words.** "Unknown page", "No such revision", "This page is in ro: reload it" and the other sentences `editor.php` sent keep their English `error` for API callers and MCP, and now add `problems`: `one_value`, `unknown_page`, `unknown_field`, `wrong_language` (with `lang`), `unknown_collection`, `no_such_item`, `no_such_revision`, `broken` and `not_saved` (a store's own exception, its sentence in `detail`, shown after the words). The editor words them from its dictionary.

**The dock's language label is a badge.** On a translation's page the dock shows the language's code (`RO`), with "editing: RO (falls back to EN)" as its title and label; the default language's pages show none. On a phone the dock shows no badge, so every button fits.

**No "All saved" in the dock.** Every change saves when you leave its field, and each save shows its own tick and a toast with Undo, so the dock's status only ever said "All saved". It is gone, and the dock has room for its buttons; leaving the page while a save is still on its way still asks first.

**No zooming into the editor's text boxes on a phone.** iOS Safari zoomed in when the link box (and the editor's other boxes) took focus, because their text was 14px; on touch screens they are 16px.

**The formatting bar stays when you move between rich fields.** Tapping or clicking from one rich field to another left no bar until edit mode was turned off and on: the first field's leaving, which runs a moment later, took down the bar the second had just shown. Now it only hides its own.

**Raster's fallback login page** (used when a theme has no `login.html`) is no longer hard-coded English. Its words come from the editor's dictionaries through `print.cms.login_word('login_…')`, in the content's language: the address's on a multilingual site (`/fr/login` is French), else the base language. Its `<html lang>` says which.

**The editor in 14 more languages:** French, German, Spanish, Italian, Portuguese, Dutch, Hungarian, Polish, Bulgarian, Greek, Turkish, Japanese, Hindi and Chinese (Simplified, `zh`), next to Romanian. An AI agent drafted them; corrections are welcome. A test checks that every dictionary has every key and keeps every `{placeholder}`.

## 2.2.52

What sites need to do: a multilingual site may see new `untranslatable_text` warnings for plain words inside alerts; move them into a `print.i18n` key inside the alert.

**On a multilingual site an event's page in another language now takes replies.** The Romanian page of an evening (`/ro/events/events_item/seara-de-poezie`, its Romanian slug) said "this evening doesn't take replies here" while the English page took them: the rsvp model looked the event up by its default slug only. It now finds it as the list does, through the new `cms_store::item_at()` and `item_id()`: the language's slug, else the default slug, else a number as an id. That is now the one place an item's address is read (`render.cms` uses it too), and a test checks no other bundled model writes a slug lookup of its own. AGENTS.md has a short "Writing a model for a multilingual site" with the four core calls a model uses.

**`/ro/login` shows the layout's site fields in Romanian.** The login page never read CMS fields; on a multilingual site its `site_*` fields (the tagline, the footer) now follow the page's language as on every other page. Off, the login page is as it was.

**Lint reads an alert's words** (multilingual on). A `print.validation.alert` block prints its words as written, so on a multilingual site every language showed them in the default language and lint said nothing. `untranslatable_text` now reports them, and says to put a `print.i18n.<file>('key')` inside the alert. Off, lint is as it was. The starter's alerts are dictionary keys now, with the English as the default, so its pages are byte for byte what they were.

**Lint warns about one alert name worded twice** (`alert_twice`, always). Two `print.validation.alert('x')` blocks with different words in one view show the first one's words in both places.

**The demo café words every alert in Romanian.** The booking, reply, contact, account and newsletter alerts of the visitor pages are dictionary keys now, with their Romanian in `demo/i18n/ro/`. The contact form's `name_invalid` and `email_invalid` share the booking form's words: one alert name shows one text on a page.

## 2.2.51

**Multilingual sites** (#200). A site can now serve its template text and everything editors write in several languages, behind one setting that is off by default. A site that doesn't turn it on renders as before.

What sites need to do after `php bin/raster update`:

- **Nothing, unless you turn multilingual on.** With `multilingual` off your pages, headers, links and SQL are as they were.
- **Dates typed day first.** On a site that sets `languages` (multilingual or not), a date typed as `5/10/2026` or `5/10/26` is read as 5 October, except for `en-us`. ISO dates (`2026-10-05`) are read as always. A site without `languages` reads dates as before.
- **A site with `languages` and no `multilingual`** (template-only translation) keeps working until 3.0.0; `doctor` suggests the switch. Keep it on purpose with `allow_deprecated` (`'template-only-languages' => true`).
- **A dictionary that sets `$lang = array(…)`** instead of returning its array is deprecated (#44); the upgrade step adds `return $lang;`.
- **The demo café** gained an editable opening-hours list: run `php bin/raster schema --apply` and `php demo/seed.php` on a demo install.

To turn it on: `config::set('multilingual')->to(true);` and `config::set('languages')->to(array('en', 'ro'));` (the first is the default) in `config/the_app.php`, then `php bin/raster schema --apply` in production. Or ask an agent: MCP `set_languages(["en", "ro"])`.

- **Addresses.** Every language but the default has its own addresses under its code (`/ro/menu`, its home `/ro/`), or its own domain (`domain_language`). The URL alone picks the language: one URL is one language, so the page cache, CDNs and 304s stay correct, every page answers `Content-Language`, `?lang=ro` answers 301 to the page in that language, and no language cookie is set. The prefix is taken off before anything reads the path, so `protected` pages, `/ro/api`, `/ro/mcp` and the login keep working, and every link Raster builds (pages and emails) keeps the language. A site under a path (`/preview/abc/ro/menu`) works too.
- **Pages, site fields and items in every language.** A text field printed as content (or as `alt`, `title`, `placeholder`, `aria-label`, a meta `content`) gets one column per language (`headline__ro`); photos, links, numbers and dates stay one value for every language. An empty translation shows the default language. Lists sort and filter by the translated text. `php bin/raster schema --apply` adds the columns; turning multilingual off keeps them ("kept translations"), never offered for `--drop`.
- **Item addresses per language** (#138). Items get a Romanian slug from their Romanian title; another language's slug, or an old one, answers 301 to the right address.
- **The in-page editor** edits the page's language on `/ro/…`, says which fields still show the default, and speaks the editor's own language (chosen in the dock), not the page's.
- **Emails go out in the recipient's language** (D13): bookings, replies, contact messages, accounts, subscribers and shop orders remember the language they were made in, and every email to them (also those staff trigger) is rendered in it, with `/ro/` links. Staff copies go in the default language. `raster send` sends each subscriber the issue in their language.
- **SEO.** `<html lang dir>` follows the page (#138); multilingual pages carry `canonical`, `hreflang` and `x-default` links; the sitemap lists every language with alternates.
- **The sitemap lists every published item** (#126): collections without an item view, no 1,000 cap, `lastmod` from the last save.
- **Static export** crawls each language at its own addresses.
- **Lint** (multilingual on) warns about words no annotation prints, dictionary keys missing in a language, and an `<html>` whose `lang` is fixed; always about `__` in names and `href="#…"` links (#117).
- **`doctor`** checks the languages: the switch with fewer than two, codes it can't read, `domain_language` without a site address, and missing translation columns.
- **The coding MCP builds multilingual sites:** `set_languages`, `translation_status` (pages, site fields, items, records, dictionary words, emails), `read_strings`/`write_strings` (Raster writes the PHP), `lang` on the page and item tools, a `languages` block in `describe`, and the theme's CSS, JS and SVG through `read_asset`/`write_asset` (#159). Over HTTP the writing tools need `mcp_write_views`.
- **The language API** for every model: `i18n::enabled()`, `current()`, `default_language()`, `suffix()`, `prefix()`, `address()`, `with()`, `dir()`, `date_order()`, `alternates()`, `of()`.
- **Groundwork that shipped on the way:** see 2.2.46–2.2.50 (lint/engine agreement, one URL parser, no English from the framework, the switch and `/ro/` URLs, `<html lang dir>`).
- **Other fixes:** the framework's error pages and the editor's refusals are words a site can change (#38 #128, 2.2.48); the starter, demo and shop models return no words (#127); newsletter queries live in `sql/` files.

## 2.2.50

Nothing for sites to do. Every view's `<html>` tag in the starter, the demo and the shop now prints its language and direction; a site's own views keep theirs until it changes them.

- **`<html lang dir>` follows the page's language** (#138). `print.i18n.dir` gives `rtl` for languages written right to left (Arabic, Hebrew, Persian, Urdu, …), else `ltr`. The bundled views wrap their tag as `<!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr">…`, so the mock-up still reads `lang="en"` and a site without languages prints the same page, now with `dir="ltr"`. Emails keep `lang="en"` as written for now.
- **Multilingual pages say where their other languages are**: in `<head>`, after the `<base>`, a `<link rel="canonical">`, one `<link rel="alternate" hreflang="xx">` per language and `hreflang="x-default"`, from `i18n::alternates()`. None on a 404 or on a URL with a query; a page that writes its own keeps them. The switcher's rows (`render.i18n.languages`) gain `url` (the language's own address in multilingual mode, `?lang=` otherwise) and `current`, and the starter's `_layout.html` shows it when the site has more than one language (`if.languages`).
- **The `<base>` goes after any `<head …>` tag** (#117), with attributes (`<head prefix="og: …">`) or in capitals, never into a `<head>` inside a comment or a script; `//host/…` links are left as written. `lint` warns about `href="#…"` and `href="?…"`, which open the theme folder: write `about.html#…`. The `#anchor` part of #117 stays open.
- **Static export crawls each language from its own address** (#200): `/ro/` is written to `ro/index.html`, links already carry the prefix, every language gets its own `404.html`, and a language with its own domain is fetched there. Template-only and one-language exports are as before. It warns when a multilingual export has no absolute address.
- **`raster export` reads `site_url`** when neither `--url` nor `RASTER_URL` is set (#137).

## 2.2.49

Nothing for most sites to do. Two things `doctor` may now say:

- **A site with `languages` and no `multilingual`** (template-only translation, with `?lang=` and the cookie) gets a deprecation hint: it keeps working until 3.0.0, and `multilingual` is the way forward (below). Keep it on purpose with `allow_deprecated` (`'template-only-languages' => true`).
- **A dictionary that sets `$lang = array(…)` instead of returning its array** is deprecated (#44). `php bin/raster update` (its upgrade step) adds `return $lang;` to such files.

- **One switch for multilingual sites: `multilingual`, off by default** (#200). With `config::set('multilingual')->to(true)` and two or more `languages` (the first is the default), the URL alone says the language: the default language has no prefix (`/menu`), the others are under their code (`/ro/menu`, a language's home is `/ro/`), and a host listed in `domain_language` is its language with no prefix. `/ro` answers 301 to `/ro/`, `/en/menu` (the default's code) 301 to `/menu`, and `?lang=ro` 301 to the same page in that language. A language with its own domain lives only there. The prefix is taken off in `config::initialize()` before anything reads the path, so routes, `protected` pages (`/ro/staff` goes to `/ro/login` and back), `/ro/api` and `/ro/mcp` work as without it, and every link Raster builds keeps the language: `link_uri` carries the prefix, and on a `/ro/` page hand-written root links to pages (`href="/menu"`, `action`, `formaction`) get it too, while links to files don't. Pages and feeds answer `Content-Language`, kept with the cached copy; the page cache keeps one copy per URL, and no `lang` cookie is set. A site under a path (`RASTER_URL=https://host/preview/abc/`) serves `/preview/abc/ro/menu`. With `multilingual` off nothing of this happens, and output, headers and cache keys are as before.
- **The language API for every model** (`system/models/i18n/i18n.php`): `i18n::enabled()`, `default_language()`, `current()`, `is_default()`, `suffix()`, `prefix()`, `address()`, `with($lang, $fn)`, `dir()`, `date_order()` and `alternates()`. Raster's own code reads language state only through it. `i18n` can't be overridden by a `the_i18n` for these calls (AGENTS.md, Extending).
- **`doctor` checks the languages**: `multilingual` with fewer than two languages (the site behaves as off), a language code Raster can't read, and `domain_language` with multilingual but no site address.

## 2.2.48

`php bin/raster update` does what sites need: its upgrade step adds the `no_database` and `no_users` alerts after `<!-- print.cms.login_message /-->` in a site's login view, since that tag now prints nothing. To word the refusals, a site can set `error_document_403` and `error_document_500` views (below). A listener or script that read `{"error":"cross-site"}` from `/api` now gets `cross_site`.

- **The framework prints no English to visitors** (#38). Every refusal goes through `controller::refuse($code, $alert)`: it renders the site's `error_document_<code>` view, with an alert the view words (`cross_site`, `form_expired`, `not_allowed`), or answers the bare status (`<h1>403</h1>`) with no sentences. That covers a post from another site, a stale form, a protected page someone without the role asks for, the 404, the 500 outside development and the 503. The refused post is never handled, error pages are never cached, and they keep the page's flags (`if.logged_in`, `if.live`). On `/api` the answer is JSON with `problems`. The login page's notices are alerts, and an email without a `<title>` gets the site's name (or the host) as its subject.
- **The in-page editor's refusals are names, worded in the editor's language** (#128). Type errors answer `problems: ['type_<type>']`, uploads `upload_missing`, `upload_too_big` (also over PHP's `post_max_size`), `upload_not_a_picture` and `upload_not_stored`. English and Romanian ship; a site rewords any of them in `i18n/<lang>/raster_editor.php`.

## 2.2.47

Nothing for most sites to do. A listener of `cms.item_saved` or `cms.item_deleted` that compared `collection` with a name rebuilt from the table (`teammembers` for a `team_members` collection) now gets the collection's own name.

- **One place reads and writes collection and item URLs** (#40). `cms::collection_uri()` parses `/<view>/<name>_item|_items|_page/…`, `cms::collection_request()` says what a list is asked for, and `cms::item_url()`, `cms::page_link()` and `cms::filter_link()` build the links. The router, page fields, pagination, feed, the sitemap, records, RSVP, the inspector, `describe` and MCP all call them, so the URLs a site's links write render as before. On a page with two paginated lists, each pager now keeps the other's page, and `/landing_page/landing_page/2` shows page 2.
- **One place matches `protected` paths**, `authentication::protection($path)`, ignoring case. The page check, the sitemap, the Admin menu, lint and `describe` agree: `Faq` protects `/faq` and the Admin menu now lists it.
- **Item URLs are written `/<name>/<name>_item/{slug}` everywhere** (#37): `describe`, MCP's `site_overview` and the inspector said `{id}`.
- **`cms.item_saved` and `cms.item_deleted` carry the collection's own name** (#33), and lint reports two collection names that share one table (`team_members` and `teammembers`) as an error.

## 2.2.46

Two things for some sites to check, after `php bin/raster update`:

- **A view named like `<name>_page.html` or `<name>_items.html`** (say `landing_page.html`) now keeps its own fields. Before, it read and saved them under another page (`/landing`), so text an editor wrote on it seems to disappear: copy it again on that page.
- **A site with a route into another theme** (`->from('print')`) whose views print `print.cms` fields: `schema --check` and `doctor` now see those fields, so run `php bin/raster schema --apply` once.

- **Lint, schema and the page agree on every name** (#28). The characters a model, key, field, attribute, view and `res`/`dry` name may use are one list in the engine (`template::$names`, printed by `raster annotations`), and lint reads the same list. `print.@data-x.key`, `print.@aria-label.key` and `print.line2` inside a render block now print, and `dry._layout.nav2` inserts `res.nav2`. A row key the engine can't read is a lint error.
- **A `dry` inside a fragment is filled in**, up to five levels, as lint already read it (#36). The page, lint, `schema` and MCP list the same fields. A page with no nested `dry` renders as before.
- **`/landing_page` is its own page** (#132). `<name>_page` and `<name>_items` are list segments only where the router serves the list (`/news/news_page/2`, `/news/news_items`), and the inspector uses the same rule as the page.
- **Views a route serves from another theme are linted and get their tables** (#133). `lint`, MCP `lint_templates`, `describe` and `schema` include every theme a route names; `lint --theme=<name>` checks that theme alone and `--all-themes` works as before.

## 2.2.45

Nothing for existing sites to do. This changes only what a new site starts with (`php bin/raster new`).

- **A new site says welcome instead of talking about Raster.** The starter's home page is "Hello, and welcome" with a short introduction, its first post is "Hello, world", the about page says what it is for, the site name is "My website" and the footer says "Made with Raster". The list of Raster's features is gone from the home page. Field names are the same.
- **The starter looks like a plain classic web page.** One readable column, a serif font, the browser's own colours and blue links, a line under the header and above the footer. It still sets no colours and no dark mode, so the look is still the site's to write.
- **A demo test no longer fails on Fridays.** It took the sixth Friday from today to come before the sixth Saturday, which is not true when today is a Friday.

## 2.2.44

Nothing for most sites to do. Security fixes from a review of the framework, the demo and the example shop. Four things to check:

- **A site upgraded from Raster 1.x** whose `admin` account still has the password `admin` can no longer log in with it. Set a new one with `php bin/raster user admin --password=-` (it reads the password from standard input).
- **The example shop's seed** now prints a random password for the studio account instead of making `studio password`. A shop already seeded keeps its password: change it if it is still that one.
- **A site's own login view** shows `slow_down` when one address tries more than 20 logins in an hour (`login_per_ip`). Word that alert near the form or in the layout, as the default and demo themes do.
- **Scripts that call `php bin/raster user … --password=<value>`** still work, and now print a note on stderr. `--password=-` with the password on standard input, or `RASTER_PASSWORD`, keeps it out of the process list.

- **`raster update` can't be handed a release by another user of the server.** It unpacked into `/tmp/raster-update-<pid>` and used that folder even when someone else had made it first, so a local user could put their own `system/boot.php` there and have the update copy it over the site and run it. The folder now has a random name, is made with mode 0700, and the update stops if it can't be made. The test suites' opcache folder had the same gap and is fixed the same way.
- **Logins: guesses sent at once each count against the lock, and one address can't try many accounts.** The five-try lock read the count, checked the password, then wrote the count plus one, so guesses sent together all read the same count and let through about five per PHP worker. Each try is now counted first, inside the database's write lock, and a login only writes the columns it changes, so it can't put back a password or email another request just changed. The lock counts per account; the new `login_per_ip` setting (20 an hour) counts tries per address, so one sender can't guess across many accounts or try which ones exist. Over it, a login answers `slow_down` and checks nothing: word that alert where a login form is (the default and demo layouts already do). The account form's current password goes through the account's lock too.
- **Raster 1.x's admin/admin no longer logs in.** An old unsalted md5 password that is the account's own name is refused (see above).
- **The in-page editor's API reaches no accounts table and no page kept for admins.** `editor_delete_item` and `editor_save_item` could reach the `usersdata` table a 1.x site still has, by spelling the collection `users_`, and the delete answered with the row's password hash. `editor_history`, `editor_save_field` and `editor_restore` took any page table, so an editor could read and change a page `protected` keeps for admins. Both are closed, and a deleted record comes back without its hidden fields.
- **The in-page editor never puts back a script link the server took out.** A guest's booking with the phone `javascript:…` printed as a link on a staff page had its link removed by the server, but the editor wrote the stored value back after a save or an action.
- **The newsletter confirmation page escapes the address and name** it shows. An address with a quoted local part could carry markup onto the page for whoever opened that link.
- **Mail goes to the address a form was given.** An address such as `"1<ana@example.com>"@example.org` was counted by the forms' limits as a new address each time and delivered over SMTP to `ana@example.com`, so anyone could flood an inbox from the site's sender. Only a `<…>` that ends the value, as in `Name <addr>`, is the address now.
- **A refused SMTP login no longer writes the password into the error log.** The error quoted the line it answered, which during `AUTH LOGIN` is the base64 password.
- **Names under `localhost` count as development only for requests from this machine**, as `localhost` itself did. The demo maps `ro.localhost`, so any client could switch a demo served without `RASTER_ENV` into development.
- **A guest's manage links stop working when staff change the email** of the booking or the reply, so a link that went to a mistyped address dies when the address is corrected.
- **The RSVP form says whether an address has replied only to a post it would take.** A post with an invalid answer could test any address against a private event without a trace.
- **The sitemap leaves out the items of collections that need an account.** It listed every item of a members-only collection (the slug is the title), and matched `protected` with case where the access check doesn't.
- **The demo's `/lab/color/<value>` escapes the value**, which it printed raw in the page and in a script.
- **The example shop sells a scheduled piece only once it is out**, and its seed makes the studio's password at random (see above).

## 2.2.43

Nothing for sites to do. **Sign-ups for one address at the same moment no longer fail on MariaDB, and send one confirmation** (#213).

- **Two sign-ups at once with one email gave one of them an error page on MariaDB 11.8.** A sign-up takes the database's lock, but the confirmation email's listener runs after the commit, when the lock is gone, and wrote when it sent the link (`confirm_sent_at`) without it. A second sign-up for the address, already holding the lock and having read the row, then found it changed under it. MariaDB 11.8 checks for that by default (`innodb_snapshot_isolation`) and fails the write ("Record has changed since last read", error 1020); MySQL let it through and wrote back the old stamp.
- **Sign-ups at once send one confirmation email, not one each.** Each listener read the stamp before any had written it, so all of them sent. Now the listener reads the subscriber and notes the email as sent under the lock, then sends it. When the lock stays busy (5 seconds) the email still goes, unnoted, and the log says so. A confirmation that fails to send is still noted, so asking again within 5 minutes sends nothing, as it already was for mail sent after a web request's answer.
- **Confirming or unsubscribing at the same moment as a sign-up for that address is no longer undone.** Both wrote the subscriber without the lock, so a sign-up in progress could write `pending` back over a confirmation on MySQL, or fail on MariaDB. They now write under the lock, reading the subscriber again first, and `newsletter.confirmed` and `newsletter.unsubscribed` are sent after the commit. A confirmation link that is malformed, unknown or already used doesn't wait for the lock.
- **The tests pass on MariaDB, and pull requests run them there.** Three checked a column's type as `int`, where MariaDB says `int(11)`. The framework, demo and shop suites now also run on MariaDB 11.8 with PHP 8.4, next to MySQL.

## 2.2.42

Nothing for sites to do: a site keeps its own `style.css`. **A new site starts plain** (#226). `raster new` copies the starter theme, and its `style.css` was a whole look: one 760px column, a blue accent, a bordered card grid, a large hero and automatic dark mode. Agents building a site recoloured it instead of designing one, so most sites looked alike (draganescu/raster-builder#161). Now it holds only what a page needs to work: box-sizing, pictures that fit their box, forms in the page's font, the form layouts, the nav and pager states and `visually-hidden`. Colours, fonts, widths and spacing are the theme's to write. The starter's pages are unchanged, and AGENTS.md now says the starter is plain on purpose and that the demo café's look is its own.

## 2.2.41

Nothing for most sites to do. A site served from a copy of the Raster repository (a clone or a GitHub download, not one made with `raster new`) should delete its `sm/` folder if it has one (#227).

- **`sm/`, the old sourceMakeup code viewer, is gone.** It was a 2013 viewer for the framework's annotated source, never used by Raster and never copied into sites by `raster new` or `raster update`. It read any file named in `?file=` and saved it under `sm/cache/` as a plain `.txt` file the web server would hand out. The private-path rules block URLs ending in `.php`, but a request for the folder `/sm/` doesn't end in `.php`, and Caddy's `php_fastcgi` ran `sm/index.php` anyway. On PHP 8 the viewer failed before it read the file, but it still ran and printed the server's paths; on PHP 7 it would have read any file the PHP user can, the database settings included.

## 2.2.40

Nothing for sites to do. A tarball packed on a Mac updates a site, and the test suites run clean on a Mac (#116).

- **`raster update <file.tar.gz>` takes a tarball made with macOS `tar`.** macOS `tar` adds a hidden `._<folder>` entry next to the release's folder, so the update saw two things at the top and said the file did not hold a Raster release. Now the `._<name>` files macOS adds, and a `__MACOSX/` folder from a Mac's zip, are left out before the release's folder is looked for, and are never copied into the site.
- **The SMTP tests no longer need 127.0.0.2.** They stand in for "a mail server that isn't this machine", which `mail` must not send a password to in plain text. macOS has no 127.0.0.2, so the demo suite hung there. They now use `0.0.0.0`, which Linux and macOS both connect to this machine, and the fake mail server listens on 127.0.0.1. A fake server that can't start fails its test at once instead of being waited for.
- **A stopped or killed test run leaves no servers running.** Every suite now stops its servers when it is stopped with Ctrl-C, `kill` or a timeout (only the demo suite did), and a small watcher started with each suite stops them after a `kill -9` too.
- **The demo suite deletes only the files it put in `media/`.** It deleted every file there newer than an hour. A run killed outright leaves its files to the next run, which moves them aside, as it does with the other files the tests write.

## 2.2.39

Nothing for most sites to do. A site with a literal route and URLs with parameters behind it can drop the tables those URLs left (below). **A route's URLs with parameters show the route's page fields** (#110). With `controller::route('blog/post')->to('post')`, an editor's headline on `/blog/post` didn't show on `/blog/post/id/7`, which still showed the template's default: page fields were kept per whole URL, so each URL with parameters was a page of its own. In development every such URL a visitor opened made its own table (`blogpostid7…page`), `schema` listed those as unused with a `--drop` hint, and MCP's `get_page` and `update_page` answered "Unknown page" for them. Now a URL a literal route matched (only lowercase letters, digits, `_`, `-` and `/`) is that route's page, as page numbers and filters already were: `/blog/post/id/7` shows and edits the fields of `/blog/post`, no new table is made, and MCP finds the route's page from any of its URLs. A pattern with regular expression parts (`blog/[0-9]+`) is not one address, so each URL it matches keeps its own fields, as before.

**The tables already made.** `php bin/raster schema` lists the tables URLs with parameters made in development as unused. What an editor saved on one of those URLs no longer shows anywhere; copy what you want to keep onto the route's page (`/blog/post`), then drop each table with the command `schema` prints (`php bin/raster schema --drop=<table>`).

## 2.2.38

Nothing for sites to do. **A nested render block runs before its parent, whatever came before it** (#97). Render blocks run from the last in the page to the first, so a block nested inside another runs first. But each one found its block by looking from the top of the page, so when the same tag was used twice, say a list near the top and the same list again inside another block, the nested copy ran after the block around it. By then that block had filled in its rows and taken out their `print` tags, so the nested list repeated its mock-up text (`n`, `n` instead of `Ada`, `Bob`). Two sibling blocks that each held the same list broke the second one the same way. Now each block looks from the bottom of the page, so it takes the last copy of its tag still there, and both lists print their rows.

## 2.2.37

Nothing for sites to do. Five fixes to form and field values (#158's group 5).

- **A collection emptied by deleting its items stays empty** (#91). When the last item was deleted, production showed visitors the template's mock-up again, with a link to nowhere, and development stored it again as a new item. Now the mock-up is stored only when the collection's table is made, and a list with no items shows nothing.
- **A number page field keeps its mock-up's decimals** (#92). With `14.50` in the mock-up, the first view printed `14.50` and every later one `14.5`. A page field is now read as its type before it prints, as an item's field is, so `4.5` prints `4.50`. Its text is read the same way too, so a picture in a page field shows under the folder the site runs in, as an item's does.
- **`make model` gives each field its input's type** (#104). A number input with a decimal `step` (or `any`) is a number, so `12.50` is no longer refused; a checkbox is a bool, so a ticked box is stored as yes and not `on`; `date`, `time` and `datetime-local` inputs go in `'types'`. What the command prints next names the `<field>_invalid` alert of each typed field.
- **A line break counts once for `minlength` and `maxlength`** (#111), as the browser counts it. A message the browser accepted at the limit was refused on the server, which counted each line break twice.
- **A required file input passes when the file arrived** (#112). It was looked up in the post, where files never are, so a form with `<input type="file" required>` could never be sent. AGENTS.md now says how a form takes an upload.

## 2.2.36

Nothing for sites to do. **A filter's select keeps the option a model lists.** A `<form method="get">` was filled from the URL before the page's render blocks ran, so an option a model listed (`render.booking.resources` on a salon's page, `render.rsvp.events` on the replies' staff page) was never chosen, and when the URL asked for the mock-up option's value, every row copied from it was marked chosen. Now the URL's values go in two steps. Options, checkboxes and radios are chosen once the page is rendered, whoever listed them, and exactly one option of a single select is chosen. Text inputs, hidden inputs and textareas are filled before, as until now, so a value a model prints into one (`print.@value`) wins over the URL's: a form per row, with its own hidden id, keeps that id whatever the URL says. For options, checkboxes and radios the URL's choice wins, in every row; a select without `multiple` gets one chosen option, the first that matches, and the editor's mock-up of a new item is never chosen. An option without a `value` is chosen by its text, without the editor's marks.

## 2.2.35

Nothing for sites to do. The demo café's jazz night and poetry evening take replies (`php demo/seed.php` adds them), with the guest's page on `/rsvp` and the staff page on `/staff/events`. AGENTS.md has the steps, and a new wiki page, Event replies (RSVP), covers the model. #168 Phase 2's RSVP is done.

## 2.2.34

Nothing for sites to do. To give staff a page for the replies, add one protected for editors (`lint` warns while it isn't). **The staff page.** `render.rsvp.events('from=today')` lists the events to come, drafts included, with their places, people going, places left, and the replies waiting, maybe and no, and each event's replies, editable in place with the `promote` button, each with its place in the queue. `render.rsvp.attention` lists the replies an edit of their event left out of place: `event_gone` (deleted), `event_hidden` (a draft or scheduled again), `over_places` (places lowered under the people going) and `moved` (the date or start changed since the guest replied). Nobody is moved out or emailed for these; an editor's save of the reply clears `moved`. `print.rsvp.count('status=waiting')` counts replies, or `count('attention')`. `render.cms.rsvp('event=?event&order=status,waiting_since')` lists one event's replies, and its card for a new reply starts with that event, so staff add a phone reply in the page.

**`raster render --as=member` without an account sees no private records.** Rendered as a role alone, the made-up member had the id 0 and saw every record of an owned private type that staff or model code had made (their owner is 0). A real account was never affected.

## 2.2.33

To take replies, add `render.rsvp.form` to the event's page (or any page, with `?event=<id>`), word its alerts (`going`, `waiting`, `noted`, `already_replied`, `closed`, `full`, `too_many`, `not_open`, …) and `try_again` / `slow_down` (lint names a page without them), and add `_email/rsvp_link`. **The reply form** stores a yes, no or maybe for the page's event, never one the post names, and redirects with `?done=going`, `?done=waiting` or `?done=noted`. **One reply per address:** replying again with an address that replied shows `already_replied` and emails that address its link, at most once every five minutes. The form has the spam checks of the booking form, asked before that lookup, so a post without a stamp mails nothing and the 4th post in an hour with one address is held. `lint` errors on a page with the form when the events collection has no `date` field, and warns when it has no `places`. A reply made by the form inside a site's own `cms_records::transaction()` sends its event and its email once.

## 2.2.32

To email guests, add `_email/rsvp_going`, `rsvp_waiting`, `rsvp_promoted` and `rsvp_noted` (lint names any missing) and a page `rsvp` with `render.rsvp.manage`. **The guest's link** opens their reply; a GET changes nothing, the page's form changes it, and it works until the event starts, after a no too. **Staff emails** (`_email/rsvp_new`) only when `rsvp_staff_email` is set. Settings: `rsvp_manage_page`, `rsvp_staff_email`.

## 2.2.31

**On MySQL or MariaDB, run `php bin/raster schema --apply` once.** SQLite sites have nothing to do. **A text field holds more than 64 KB on MySQL** (#211). Text columns are now `LONGTEXT`; until now they were `TEXT`, which stops at 64 KB, so a long page or post could fail to save in production, or be cut short. SQLite never had a limit. `schema --check` lists the text columns that are still `TEXT` or `VARCHAR`, in page and collection tables as well as model tables, and `--apply` widens them. Values already stored are kept as they are. Before, `--apply` widened the small columns of model tables only; a page table made in development on MySQL kept its `VARCHAR(191)` columns in production. Slugs keep their columns: they are indexed, and never longer than 80 characters.

## 2.2.30

Nothing for sites to do. **Replies to events.** A new bundled model, `rsvp`, keeps replies (yes, no, maybe, a party size) to the items of the site's `events` collection (`rsvp_collection`) as private `rsvp` records. An item's `places` and `rsvp_until` fields, written in its view, set the limit and the deadline. A yes that doesn't fit waits; when places free up the model moves in, oldest first, every waiting party that fits. Staff add and edit replies in the page; `render.rsvp.event` shows the places left. Events: `rsvp.replied`, `rsvp.changed`, `rsvp.promoted`. Settings: `rsvp_collection`, `rsvp_max_people`, `rsvp_closes`, `rsvp_waitlist`. Guests' emails and the form come in the next releases.

## 2.2.29

Nothing you must do; a site's own form model keeps holding over `spam_per_ip` until it passes the reasons through `validation_spam::holds()` (below). **Real visitors are no longer turned away when many of them share one address** (#208). Before, on shared hosting or behind a proxy every post could come from one address, and after five posts of a form in an hour everyone was held with `slow_down`, or kept as spam on the contact form, without the owner knowing.

**A private address is a proxy by default.** With `trusted_proxies` not set, a post from a private, loopback or link-local address (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `169.254/16`, `::1`, `fc00::/7` with Fly's `fdaa::`, `fe80::/10`) is counted by the visitor's address in `client_ip_header` (`X-Forwarded-For`). Nobody on the open internet can post from such an address, so something on the same network forwarded the post. This holds only when the proxy writes the header itself, setting or overwriting `client_ip_header`; something that only forwards the connection (Docker's port proxy, a NAT port-forward, nginx in front of Apache without `proxy_set_header X-Forwarded-For`) passes on the visitor's own header, which a bot can change on every post. Behind one of those, set `trusted_proxies` to `array()`. raster-builder sites, Fly, and most hosts with nginx or LiteSpeed in front count real visitors with no settings. Setting `trusted_proxies` trusts only what it lists, as before; `array()` trusts nobody.

**Too many posts from one IP flag a post, they don't hold it.** `too_many_from_ip` is still a reason, but the newsletter, sign-up, booking and record forms take the post, and forgot still mails. The contact form still keeps such a message as spam. `spam_ip_blocks` true (a new setting, false by default) holds them back as before. The per-email limit still holds. A site's own form model should pass the reasons through `validation_spam::holds()`: `if ($reasons = validation_spam::holds($v->suspect())) return $v->hold($reasons);`. One that calls `hold()` on `suspect()`'s answer directly still holds back over `spam_per_ip`.

**`doctor` says when many people seem to share one address.** When one IP sends one form more than 5 different emails in an hour, `doctor` warns in production: "Many people seem to share one address. If the site is behind Cloudflare or another proxy, set `trusted_proxies` and `client_ip_header`." It can also be one sender trying many addresses, a bot rather than a crowd. It replaces the warning about posts from a private address, which the new default makes moot. Cloudflare's addresses are public, so a site behind it still sets `trusted_proxies` to Cloudflare's ranges and `client_ip_header` to `CF-Connecting-IP`.

## 2.2.28

Nothing for sites to do. This release carries 2.2.25, 2.2.26 and 2.2.27: their release runs stopped on the in-page editor's browser test, which posted a booking without the form's time stamp (2.2.23), so 2.2.25 turned it away. The test now sends a stamp, as a browser does. Nothing in the framework changed.

## 2.2.27

Nothing for sites to do. **The café and the docs move onto the contact model.** The demo café's contact form is `render.contact.form` now (its own `cafe::contact()` is gone), with `_email/contact_received`, `contact_staff_email` and a Messages section on `/staff`: new, spam and done. AGENTS.md and the wiki teach a `feedback` form (a rating and a comment, stored, nothing mailed) in place of the old `contact` model, and a new wiki page, Contact forms, covers the bundled one. The Forms, Static export, Tutorial, Security and Settings pages say how the stamp, the limits and the other spam checks work. #168 Phase 1.5 is done.

**A held post shows its message once.** `lint` now counts the `try_again` and `slow_down` alerts in the fragments a view includes, so they can live once in a layout's `res.header` instead of on each page. The demo café and the starter theme do that; before, a page that also had its own pair showed the message twice. If your layout's footer has the pair and a page has it too, keep one: the top of the page is the best place.

**A long non-ASCII name in a mail header is folded.** The encoded words of a name in `From`, `To` or `Reply-To` are joined with a folding break, so 200 characters of emoji no longer make a header line over 998 characters. A name that fits one word is sent as before.

## 2.2.26

Nothing for sites to do. **A bundled contact model.** `render.contact.form` stores every message as a private `contactmessage` record and emails staff about it after the answer (`_email/contact_received`, to `contact_staff_email`, else the sender), with `Reply-To` the visitor. Nothing is mailed to the visitor. A message that looks like spam is kept with status `spam` and its reasons, nobody is emailed, and the visitor is thanked all the same. A newline in the name, email, phone or subject is refused, so nothing a visitor types can add a mail header. A double click stores one message. Spam older than `contact_spam_days` (30) is deleted as new messages come. Staff list the messages with `render.contact.messages` (done, spam and not spam are its buttons) and count them with `print.contact.count`, on a page protected for editors; `lint` warns when it isn't. Events: `contact.received` and `contact.flagged`. Settings: `contact_staff_email`, `contact_staff_page`, `contact_max_length`, `contact_spam_days`. AGENTS.md has the steps and a worked staff page.

**Behind a proxy, set `trusted_proxies` before using it** (see 2.2.25). With `trusted_proxies` empty the whole site shares one count, so after five messages in an hour every visitor's message is kept as spam (`too_many_from_ip`) and staff get no email about it. They see it under spam on the staff page.

**An address that is more than an address is refused.** PHP's email filter takes `"x>,evil@evil.test,<y"@x.com`; the contact model refuses, as `email_invalid`, an email with whitespace, a quoted local part, any of `" ( ) , : ; < > [ ] \` or a second `@`, so nothing a visitor types can put another address in `Reply-To`.

**A site with its own `contact` model keeps it.** Its `render.contact.send` calls its own class and nothing of the bundled model loads; `lint` says the bundled one is hidden. To move: rename your model (or delete it once its messages are elsewhere), put `render.contact.form` around your form with the fields `name email phone subject message`, write `_email/contact_received.html` from your old email, add the alerts (`sent`, `email_invalid`, `message_invalid`, …), and make the staff page from AGENTS.md.

**A record's slug comes from what `fill()` works out too.** A new record whose model fills its title gets its slug from that title, not from the fields the form sent. A name that isn't plain ASCII in `From`, `To` or a `Reply-To` passed to `mail::send_view()` is now sent encoded whole (`=?UTF-8?B?…?= <a@b.test>`), so no part of it can be read as another address; plain ASCII names are sent as before.

## 2.2.25

The newsletter sign-up, the sign-up and forgot-password forms, the booking form and every `cms_records::submit` form (a record type with `create` `visitor` or `member`) now turn away posts that look like spam, using the checks from 2.2.24. A suspect post gets the form again with what was typed and the alert `try_again` (no fresh stamp, or one forged or too young) or `slow_down` (too many posts from one IP or address in the hour). Nothing is stored or mailed. Forgot answers `reset_sent` as for an unknown address when the limits are passed, and mails nothing. Editors are never held. A record type can say `'spam' => false` to run its own checks.

**Behind a proxy (a load balancer, Cloudflare, a host such as Fly), set `trusted_proxies` and `client_ip_header` before updating.** With `trusted_proxies` empty every post seems to come from the proxy, so the whole site shares one count: after five posts in an hour, forgot-password answers `reset_sent` and sends no email, and the sign-up, newsletter, booking and record forms answer `slow_down` to everyone, for the rest of the hour. `doctor` says when posts come from a private address. On a host that runs the site on several machines, also set `RASTER_SECRET` to one value on all of them, or a form shown by one machine fails its stamp on another (`try_again`).

**To do:** add `print.validation.alert('try_again')` and `print.validation.alert('slow_down')` to pages with these forms, in your words; `php bin/raster lint` names the pages. A script that posts to your forms without fetching them first now gets `try_again`.

## 2.2.24

On a frozen (production) database, run `php bin/raster schema --apply` once after updating: it makes the two new tables, `formhit` and `formsalt`. Until then `doctor` reports that the database and the templates differ, and the limits pass. A form model can call `validation::get()->suspect()` after `valid()` to ask whether a post looks like spam: it checks the form's stamp (sent too fast, forged, missing or replayed), counts posts per IP and per address for each form, and counts links and blocked words. `$v->hold($reasons)` shows the form again with what was typed and the alert `try_again`, or `slow_down` for a limit. No bundled form asks yet; that comes next. Editors are never flagged, and a check that can't run (no key, no table) passes and logs a warning. Behind a proxy, set `trusted_proxies` (doctor says when).

**No raw IP is stored.** Counts are hashed with a salt made each day, and the salt goes with its counts at midnight. They live in the two new tables, which a fluid database makes on first use.

**Settings:** `spam_min_seconds` (3), `spam_max_age` (86400), `spam_per_ip` (5), `spam_per_email` (3), `spam_max_links` (2), `spam_blocklist`, `trusted_proxies` and `client_ip_header` (`X-Forwarded-For`). `describe` lists the ones a site sets.

`suspect(array())` runs no text checks at all, the blocklist on the email's domain included, so a form that answers `try_again` can't refuse a blocked address over and over, a message that could never help; pass the fields to check (`suspect(array('message'))`) to have them. Behind a trusted proxy, an entry of the header with a port or in brackets (`1.2.3.4:5678`, `[2001:db8::1]:443`) counts as its address.

**doctor** warns when the user running it can't read `data/secret`, or it isn't a key: if the web server can't read it either, the stamp is off. Delete it and let the site make it again, or set `RASTER_SECRET`. doctor runs as you, so run it as the web server's user to be sure. It also warns in production when posts come from a private address while `trusted_proxies` is empty.

## 2.2.23

Nothing for most sites to do. Every post form that has a render block around it now gets a hidden `raster_ts` field: the time the form was shown, signed with the site's key. Nothing checks it yet; the spam checks that read it come next. The key is `RASTER_SECRET` when set, else the first web request that needs one makes `data/secret` (make sure the web server can write to `data/`, as for the SQLite file and the cache). A command (`raster render`, a script) never makes the file, since PHP-FPM might not be able to read one made by another user; with no file it can read, it signs with a key of its own for that process only. Emails, static exports and forms with no render block carry no stamp. The page cache keeps a marker in the stamp's place and writes a fresh stamp into the page on every hit, so cached forms stay fresh too.

**A site model that reads `$_POST` whole:** one that left out Raster's own fields by the old list `raster_form`, `raster_hp`, `csrf` now also gets `raster_ts`. Use `template::$internal_fields` instead of a list of your own, so a field Raster adds later is left out too.

## 2.2.22

Nothing for sites to do. `raster update` brings the new `.htaccess`; behind nginx or Caddy, add the theme file lines from `php bin/raster deploy --config=nginx|caddy`.

**A changed stylesheet shows right after a publish.** Relative links to theme files (`href="style.css"`, `src`, `srcset`, `poster`) get `?v=` and a version made from the file's time and size, so the URL changes when the file does. The `.htaccess` and the nginx and Caddy configs keep a theme file with a version for a year, and have browsers check one without it each time. Pages are sent with `Cache-Control: no-cache`, so a browser asks again each time and gets a 304 when nothing changed. `php -S` sends the same headers. `asset_versions => false` leaves the URLs as written.

## 2.2.21

A list that pairs `limit=N` with a pager now shows the same N rows on every page: set `<name>_page_size` instead. A site's own `reservation` or `booking` model keeps working.

**The demo and the docs use the bundled booking model.** The demo café takes table bookings on `/visit` and a salon's appointments on `/salon`. Guests manage their booking on `/booking`, and the staff pages are made from list options and the booking regions. `php demo/seed.php` sets up what can be booked. AGENTS.md has the steps to take bookings and a worked staff page. Its Records and Events examples, and the wiki's, are now a catering request type rather than a booking model of your own. The wiki has a new Bookings page.

**The pager counts what the list shows (#88).** `pagination.links('cms.<name>', '…')` now counts drafts for editors, `owner=me`, and a private type's records for whoever may read them. Visitors get no pager over private records. A list with `limit=3` shows the same three on every page, so it no longer moves with a paged list beside it, and it has no pager.

**lint warns about raw `find()` rows on any page (#125).** Rows a model hands back from `cms_records::find()` without `cms_records::listed()` print a `false` bool as the mock-up and visitor text unescaped, on any page. AGENTS.md now says so. Rows a model makes text itself (`cms_records::for_template()`, `util::e()`) aren't warned about outside admin pages.

**The booking events are listed by name.** `booking.confirmed`, `booking.declined` and `booking.cancelled` now show in the vocabulary, and lint checks bindings to them.

## 2.2.20

- MCP: a malformed message gets a JSON-RPC error (`-32600`) instead of ending the stdio server, and a batch of non-objects over HTTP no longer causes a PHP error. A batch over stdio gets a list back. An unknown tool is a `-32602` error. `serverInfo.version` is Raster's version. (#134)
- MCP over HTTP works on Apache with PHP-FPM: the `.htaccess` passes the `Authorization` header on, `/mcp` also reads `REDIRECT_HTTP_AUTHORIZATION`, and `doctor` reports a `.htaccess` without the line. `raster update` brings the new `.htaccess`. If you edited yours, add the two lines from `php bin/raster deploy --config=apache`. (#106)
- `render_url` works over HTTP under PHP-FPM, passes the whole environment to the page, and no longer hangs on a page that logs a lot. (#107)
- The stdio MCP server sees fields, collections, methods and record types written during the session. After a PHP file it has loaded changes (a model's `api()`, `types()` or `listens()`), a fresh process answers each later call. (#105)

## 2.2.19

Nothing for sites to do.

**`raster render` shows what visitors get.** It never reads or writes the page cache, also in production, so a view edited by hand shows at once. A protected page answers with its 303 to the login page: `Location: …` and `HTTP 303` go to stderr, no page is printed, and the exit code is 1. MCP `render_url` gives status 303. `--as` still renders the page as that person.

**Every scheduled item clears the page cache.** Before, only the earliest did, and later ones were missing from cached pages until `page_cache_ttl` ran out. `data/cache/next` now keeps every time still to come.

**`cache clear` says when it couldn't.** When the cache belongs to another user (the web server's) and nothing could be written, `raster cache clear` says so and exits 1, and MCP `clear_cache` answers an error. Run it as the web server's user. `raster update` and `raster upgrade` now clear the page cache when they finish.

**Cached pages keep their headers and answer 304.** Headers a model or listener sets (`X-Robots-Tag`, CORS, `Link`) are sent on cache hits too. Cached pages carry `Last-Modified`, and a request with a current `If-Modified-Since` gets a 304 with no body.

## 2.2.18

Nothing for sites to do. On nginx or Caddy, print the server config again with `raster deploy` so pages whose slug ends in `-journal`, `-wal` or `-shm` can be opened.

**Items titled with a number open.** An item titled `1984` opens at `/news/news_item/1984`. A number in an item URL is a slug first and an id only when no item has it as its slug.

**A domain per language works with the site's address set.** With `RASTER_URL` set, a request on a domain listed in `domain_language` keeps its host. Its pages come in that language, its links stay on it and its forms are accepted. Every other host still gets the site's address.

**Pages ending in -journal are pages.** Only SQLite's own side files (`.sqlite-journal`, `.sqlite-wal`, `.sqlite-shm`) are refused now, on every server. `lint` warns about a view such as `docs/config.html`, whose address the server refuses because `config/`, `models/`, `data/` and `i18n/` are private under any folder.

**Extensions on routed and missing URLs.** `/specials.json` is a 404 page unless `menu.json` exists, and page 2 of a feed (`/journal/journal_page/2.rss`) is the feed. A missing page is HTML whatever its extension.

**One address per page.** `/menu/`, and `/index.php/menu` while `rewrite` is on, redirect (301) to `/menu`. Filter links no longer end in a slash.

## 2.2.17

Nothing for sites to do.

**The in-page editor is fast on long pages.** Placing the edit marks took time that grew with the square of the page (over half a second for a 500-row list). It now takes one pass, so editors get long lists about as fast as visitors.

**Enter in a paragraph field makes a new line.** A rich field that is a `<p>`, a heading or a list item no longer stores a paragraph inside a paragraph (which split the text and locked the field after a reload). Enter adds a line break, and pasted paragraphs become lines.

**The editor works from the keyboard.** In edit mode, Tab reaches items, photos and the new-item card. Enter on an item opens its handle (Esc returns), Enter on a photo replaces it, and Enter on the card starts a new item.

## 2.2.16

- Listeners of bundled models declared in `listens()` now run with the framework's own bindings: before `config/the_events.php` and before the site's models' `listens()`. A site listener sees what the bundled listener already did, and `the_events.php` can unbind it with `event::unbind(…)->from(…)` (#27).
- Models now autoload before the config files load.

## 2.2.15

Nothing for sites to do.

**A new day exports again.** `raster export` counts the date (in the site's `timezone`) as part of what the site is made of. A nightly export now drops events that `date>=today` no longer lists, where before it said "Nothing changed since the last export". Pages that come out the same are still not written again.

**`raster update` stops when it can't replace a file.** A framework file it can't write or remove now stops the update with exit 1 and the file's name, and the files already replaced are put back. Before, it said "Updated" while some files were still old. `update <folder>` also refuses a folder that doesn't hold `system/boot.php`, even with `--force`.

**The comment at the top of `bin/raster`** lists the same commands and options as `raster help`.

## 2.2.14

Behind nginx: regenerate the config with `php bin/raster deploy --config=nginx`, or add `client_max_body_size 13m;` to it. Make sure the php.ini PHP-FPM reads has `upload_max_filesize = 12M` and `post_max_size = 13M`.

**The nginx config makes room for the editor's pictures.** nginx takes 1 MB request bodies unless told otherwise, so the editor's larger pictures were refused. `deploy --config=nginx` now allows 13 MB. It also gzips CSS, scripts, feeds and other text, and gives `/media/` a year's cache lifetime, since an upload never reuses a file name.

**doctor checks PHP's upload limits.** In production it warns when PHP takes files under 12 MB or requests under 13 MB, the size of the editor's largest picture. Stock PHP takes 2 MB, so large PNGs never arrived and the editor could only say "The upload did not arrive".

**Ready for PHP 8.5.** `update` and `export` no longer read `$http_response_header`, and the example shop no longer calls `curl_close()`. PHP 8.5 deprecates both. CI now tests PHP 8.1, 8.4 and 8.5, MySQL on 8.1 and 8.4, the in-page editor in Chromium, and updating a site from GitHub.

## 2.2.13

To take bookings, add the six `_email/booking_*.html` views (`booking_requested`, `booking_new`, `booking_confirmed`, `booking_declined`, `booking_cancelled`, `booking_changed`) and a manage page (`booking.html`, or set `booking_manage_page`) with `render.booking.manage`. lint names any view that is missing.

**Booking links.** Every email to a guest carries a fresh link to manage the booking. Opening it changes nothing. The page's own buttons cancel or move the booking, up to `booking_cutoff` minutes (120) before the start. The link stops working at the start, or once the booking is declined or cancelled. A logged-in guest manages their own bookings without a link. Links are never stored, logged or page-cached; the booking keeps only a hash.

**Booking events.** `booking.requested`, `booking.confirmed`, `booking.declined`, `booking.cancelled` and `booking.rescheduled` arrive after the commit, with the booking's fields and `who` (`guest` or `staff`). The bundled model sends its emails from listeners you can unbind in `config/the_events.php`. Staff emails go to `booking_staff_email`, or else the sender.

**Models can declare the records a region needs.** `requires()` takes a `records` key. lint warns on a booking form while there is no resource yet.

## 2.2.12

Nothing for sites to do. **Bookings, part two.** A site whose views use `render.booking.*` now takes bookings. `render.booking.form` stores a request that holds its place, and capacity is counted at the busiest minute in one query inside the write's lock, so two guests can't both take the last table. Staff confirm, decline or cancel bookings with the actions, and may book a walk-in or a booking at ten minutes' notice, but never over capacity. A booking stranded by a change of the setup needs attention, with the reason, in `render.booking.agenda` and `render.booking.attention` and in `print.booking.count('attention')`. These are for editors only and editable in place. New setting: `booking_max_people` (12). **Record types can fill their own fields.** A model's static `fill($type, $after, $before)` returns the fields it works out (a booking's end), merged into every write before `check()`. `cms_records::writer()` tells `check()` who is writing. **Forms shown again** select value-less options by their text and keep unticked boxes unticked (#80).

## 2.2.11

Nothing for sites to do.

**Bookings, part one.** A site whose views use `render.booking.*` gets the bundled booking model's setup records: `bookingresource` (what is booked, with a people limit and a bookings limit), `bookinghours` (when slots start), `bookingclosed` (days off) and `bookingservice` (what a booking is for and how long it lasts). Staff edit them in the page. `render.booking.slots`, `days`, `resources` and `services` list one day's start times, the next days with a free slot, and the active resources and services. They read the date and the guest's choices from the URL, need no JavaScript, and are never page-cached. Times follow the site's `timezone` across DST changes, and a booking ends by midnight. New settings: `booking_notice` (60 minutes) and `booking_horizon` (60 days). A site with its own `booking` model or type keeps it, and lint says so.

## 2.2.10

Nothing for sites to do, unless `models/sql.php` still names its array `$querries`: rename it `$queries`.

**A model's queries survive calls into other models.** A name passed to `database::instance('x')` now applies to that call only. Before, it stuck to the shared instance, so after `cms_records::find()`, `newsletter::subscribe()` or an email printing a CMS field, a model's own `database::instance()->query_name()` was looked up in the CMS folder and threw. Code that passed a name once and then relied on it for later unnamed calls must pass it on each call.

**Bundled models and overrides have SQL files.** Named queries are looked up in a `the_<name>` override's `sql/` folder, then the bundled model's `system/models/<name>/sql/`. `vocabulary` and `lint` see them. The newsletter's confirmed count is `newsletter.confirmed_count`.

**`%` in SQL files runs as written.** `strftime('%d', date)`, `DATE_FORMAT(d, '%d.%m')` and `LIKE '%soup%'` no longer crash the page. `query()` runs SQL as written; the `'%s'` entries of `models/sql.php` are quoted as before.

**`$querries` in models/sql.php is no longer read** (deprecated since 2.0).

**`cms_records::find()` takes values.** A `?x` value (the URL's, in a template) now throws instead of silently dropping the condition, and `owner=me` gives the logged in person's records (none when logged out).

## 2.2.9

Nothing for sites to do.

**Staff `/api` methods refuse requests other sites start.** A method a model's `api()` lists for `member`, `editor` or `admin` now answers 403 `{"error":"cross-site"}` on any verb, GET included, when the browser says another site started the request (`Origin`, `Sec-Fetch-Site` or `Referer`). Methods listed for `visitor`, webhooks among them, answer as before. Forms and `/api` posts with `Origin: null` are refused too, unless the browser's `Sec-Fetch-Site` says they come from the site itself.

**`sitemap_skip` adds to the pages left out, rather than replacing them.** The login, sign-up, password and newsletter pages are always left out of the sitemap and of `raster export`, wherever `login_page`, `reset_page` and the newsletter settings put them. A site's `sitemap_skip` no longer needs to repeat them. Those pages are left out only on sites whose views use accounts or the newsletter: elsewhere, a page that happens to be called `account` is an ordinary page. `describe` lists the account and newsletter settings on those sites only.

**Models can say what they need.** A model's `static function requires()` names the emails its regions send, the pages that need PHP behind them, and its settings. `lint`, the sitemap, `raster export` and `describe` read it (see "What a model declares" in AGENTS.md).

## 2.2.8

Set `timezone` in production (`config::set('timezone')->to('Europe/Bucharest')`); `doctor` warns when neither it nor php.ini's `date.timezone` is set. `today`, scheduled items, stamps and the editor's Schedule box and badge follow it. `mail::send()` returns true once a message is kept: in a web request mail now goes out after the page answers, with SMTP waiting at most 5 seconds. Delivery failures are logged and send `mail.failed` (listeners on `mail.sent`/`mail.failed` run after the answer). Names in From and To and long subjects are sent as encoded words, and `mail://` passes `-f` with the sender's address. Signing up again within 5 minutes sends no second email, nor does asking for a password reset again. The link already sent still works. Run `schema --apply` (new `subscriber.confirm_sent_at`). `authentication::url()` and `newsletter::page_url()` are deprecated for `util::url($page, $query)`.

## 2.2.7

Nothing for sites to do.

**One model lookup.** Pages, events, `/api`, `lint` and MCP now find a model the same way: `models/<name>/`, else `models/the_<name>/` over the bundled model, else the bundled model. A `the_mail` override now changes the emails the newsletter and accounts send, because bundled models send through the override. `lint` warns when a site model has a bundled model's name, since it hides the bundled one completely.

**Faster requests on sites with many models.** Raster keeps what models declare, and the events they listen to, in `data/cache/models.json`. A request then binds listeners without reading every model file. The file is made again when a model file changes or Raster is updated, and `raster cache clear` deletes it. An override's `listens()` replaces the bundled model's; call `parent::listens()` to keep both.

**Bundled record types only where used.** A bundled model's `types()` count only on a site whose views use that model, so a site that doesn't use it gets no extra tables. Types a site's own `the_<name>` override declares always count, as before.

## 2.2.6

Nothing for sites to do. A model that relied on `cms_records::find()`
stopping at 1,000 records can pass a limit.

- **`cms_records::find()` reads every match.** It stopped at 1,000 records
  without saying so. It now has no limit unless one is given, and its
  filters may be list options as `render.cms` takes them (`date>=today&status!=cancelled&order=date`) (#85).
- **`owner=me` lists only your own records on a public type.** For members
  and visitors the template's own `owner=me` was dropped, so a member saw
  everyone's records (#89).

## 2.2.5

Nothing for sites to do.

- **Collection URLs never serve a view inside a `_` folder.** Files and
  folders starting with `_` are never pages, but the CMS collection router
  only checked the view's own name, so `/_email/digest/news_page/1` showed
  an email view as a page. `controller::is_partial()` now decides for the
  controller and the collection router alike (#46).

## 2.2.4

Nothing for sites to do. A script that passes a command an option it
doesn't take now stops with exit 2 instead of running; fix the option name.

- **Export stops when a page answers an error.** A page that answered 5xx,
  or didn't answer, during `php bin/raster export` used to be left out with
  a warning, the copy an earlier export wrote was removed, and the command
  exited 0. Now it lists each such page, writes and removes nothing (the
  manifest stays as it was) and exits 1 (#99).
- **Commands refuse options they don't take.** Any `--word` argument is an
  option, and each command checks its options against the ones its help
  names. A typo like `send … --dryrun` used to be ignored, so the mail went
  out; now it gives an error naming the options the command takes, prints
  its help lines and exits 2. The help now lists every option the commands
  read (#101).
- **`schema --dry-run`.** `--rename` and `--drop` run all their checks and
  say what they would do without changing anything; `--apply --dry-run`
  applies nothing and says so.

## 2.2.3

Nothing for sites to do. On a frozen database, `schema --apply` adds the new
`newsletterdelivery` table and `newsletterissue.incomplete` column, and
`send` adds them itself the first time it runs.

- **An interrupted newsletter send goes on where it stopped (#86).** `php
  bin/raster send` recorded the issue only after the last subscriber, so a
  run that died part way mailed everyone again on the rerun, and failed
  addresses were never retried. Each delivery is now recorded as the mail is
  handed over, and the issue stays incomplete until everyone has it: a plain
  rerun mails only the subscribers who don't have it yet, failed ones
  included. `--again` still mails everyone. The sending lives in
  `newsletter::send_issue()`, so a model can send an issue too.
- **One SMTP connection per send.** `mail::keep_open()` and `mail::close()`
  keep the connection open between mails instead of logging in again for
  each one.
- **The mail password no longer shows in `send` output.** The summary,
  `--dry-run` and `--to` print the transport through `mail::destination()`,
  without its user, password or options.

## 2.2.2

Nothing for sites to do. Records that already have a slug keep it.

- **A record's slug is never made from its hidden fields.** A record with
  no `title`, `headline` or `name` took its slug from its first filled text
  field, hidden ones included, so a public type whose first field was a
  hidden `secret` or `email` showed that value in its URL, and editors and
  agents got it back as the record's slug. Hidden fields, `owner` and
  `created_at` are now skipped when a slug is made (#93).

## 2.2.1

Nothing for sites to do.

- **Lint and schema read annotations the way pages do (#108).** An
  annotation written in script form (`/*- … -*/`) inside a script or a
  style was skipped by `lint`, `schema`, `describe` and MCP, so a typo there
  passed lint and a CMS field written that way was called an orphan, and
  `--drop` could delete its column. Annotations inside `<!-- remove -->`
  blocks counted as real, so `schema --apply` made tables and columns for
  things no page shows. Both now match what the engine does, and a long
  comment no longer hides the annotations after it.
- **`schema --drop` only drops what the templates own (#109).** A column
  drop now goes through the same check as a table drop: CMS page and
  collection tables only. Tables a model declares with `schema()`, record
  type tables and the columns a type declares are refused, even with
  `--force`, so `--drop=user.password` no longer drops the password column.
  A `schema()` table is no longer listed as unused.

## 2.2.0

Nothing for sites to do.

- **The editor's dock moves out of the way.** On a phone it sat at the
  bottom of the screen and covered whatever was under it, so links and
  buttons there couldn't be tapped. Flick it up and it moves to the top;
  flick it down and it comes back. A finger, a mouse dragged and let go,
  and two fingers on a trackpad all do it. A quick flick goes where it was
  thrown, a slow drag to the nearer edge, and a small wobble is still a
  tap. It stays where it was left, page after page.

## 2.1.13

Nothing for sites to do.

- **Logging out clears the session cookie on HTTPS sites.** The expired
  cookie is now sent with the session cookie's own settings (Secure,
  HttpOnly, SameSite, path, domain). Before, a browser ignored it when the
  session cookie was Secure, so the old cookie stayed after logging out:
  the session was over on the server, but that visitor got a new empty
  session on every page and missed the page cache. Sites that name their
  session cookie `__Host-…` were always affected.

## 2.1.12

Nothing for sites to do. On a frozen database, `php bin/raster schema
--apply` adds the two new account columns; `login-link` adds them itself
when it runs.

- **Login links from the command line.** `php bin/raster login-link
  <email|username> [--next=/path]` prints a link that logs that account in
  without a password. It works once, within a minute, and only its hash is
  kept, as with password reset links. Opening it logs in and then asks for
  the page again without the token (or for `--next`); an old or used link
  only does the redirect. It is for a site builder that runs the site and
  wants a Log in button. The page cache never answers or keeps those
  requests. It finds an account; it never makes one (`raster user` does).

## 2.1.11

Nothing for sites to do.

- **MCP answers are smaller.** Tool results come back as compact JSON
  instead of indented JSON, which was more than half their size. `describe`
  leaves out the vocabulary unless `sections` asks for it: the vocabulary
  is most of a full answer and has its own tool. A fresh site's `describe`
  went from about 32,000 characters to about 2,800. `php bin/raster
  describe` still prints everything. (#161)

## 2.1.10

MySQL catches up with SQLite. On a MySQL site, run
`RASTER_ENV=production php bin/raster schema --apply` once after updating:
it widens the columns earlier versions made too small and adds the slug
indexes. On SQLite, run it once too, for the indexes.

- **Transactions lock on MySQL too.** `cms_records::transaction()` takes a
  named lock per database before it starts, and lets go when it ends, as
  SQLite's lock does. So on MySQL as on SQLite, two checkouts at once
  can't both take the last item, two sign-ups with one email make one
  account, sign-ups at once leave one subscriber, items saved at once get
  their own slugs, and two editors saving one page both keep their
  change. A write that waits more than 5 seconds for the lock fails with
  "database is locked". (#78, #77, #83)
- **Tables your models declare get real column types.** `schema --apply`
  declares each column of a `schema()` table, and of the accounts and
  newsletter tables, as the type of its default (`''` TEXT, `0` INT),
  without storing and deleting a row. On MySQL those columns used to be
  sized by that row (`varchar(191)`, `tinyint`), so in production a long
  message or 300 guests gave a 500. Columns made that way by an earlier
  version are widened by `schema --apply`, and `schema --check` reports
  them until then. (#79)
- **Password reset works on MySQL in development.** Finishing a reset
  failed once development had made the expiry column a date. (#154)
- **Slugs are indexed.** Every table with slugs gets an index on them, so
  opening an item stays quick in a big collection, and a new item finds
  its free slug in one query, however many items share its title. Tables
  made before get the index from `schema --apply`. (#129)
- **The tests run on MySQL.** `RASTER_DB=mysql://user:password@host/name`
  points a site, or a test suite, at a MySQL database (write the password
  as `rawurlencode()` does). Each suite then makes its own empty databases
  beside that one and drops them at the end; tests that only mean
  something on SQLite are counted as skipped. CI runs the suites on
  MySQL 8.4, and a release waits for them. (#113)

## 2.1.9

Nothing to do on a site: `php bin/raster update` brings the change.

- **Pictures keep working when a site moves to another folder.** An
  uploaded picture's address is now stored from the site's root
  (`/media/<file>`), without the folder the site happened to run under,
  and shown under the folder it runs in now. Until now the folder was
  saved with it: a picture uploaded while the site ran under `/preview/abc/`
  kept that address after the site and its database moved to a domain's
  root, and did not load there. Addresses already saved that way are
  shown right without being saved again. Only a value that is a whole
  address of a file in `media/` changes; links, text and other sites'
  addresses are left as they are.

## 2.1.8

Run `php bin/raster update`; `php bin/raster doctor` shows what is left.
Things to check on a site:

- If a model's methods should answer at `/api`, list them in its
  `static function api()`: the `api_open` setting is gone.
- Delete `application/.htaccess` if your site has one (`raster update`
  removes `system/.htaccess`).
- In production, turn `display_errors` off in php.ini; `doctor` warns
  when it is on.

The double-submit fixes below hold on SQLite. MySQL and unique indexes
come later (#78, #129).

- **/api offers only what a model lists, bundled models included.**
  `cms` now lists its own endpoints in `api()`: the in-page editor's
  endpoints, `style` and `logout`. These still check the caller
  themselves. `/api/cms/setup`, `route`, `inject_toolbar`, `login` and
  `login_message` now answer 404. A public method added in a `the_cms`
  override also answers 404 unless the override lists it in its own
  `api()`, which adds to what `cms` lists. `raster vocabulary` shows
  what `cms` offers. (#32)
- **The `api_open` setting is gone.** It kept every public method of
  models without `api()` reachable by anyone at `/api`. Setting it now
  does nothing, `raster upgrade` no longer writes it, and `doctor` no
  longer reports it. If a model's methods should answer at `/api`, list
  them in `static function api()`. (#24)
- **An /api method that fails answers JSON, not a stack trace.** A
  method that throws answers `{"error":"server error"}` with status 500,
  and the error goes to the error log with the URL, so a payment
  provider sees a failure and sends the webhook again. Anything the
  method printed before it threw is dropped. A call missing an argument
  the method needs gets 400. In development the answer also has
  `exception` and `trace`. The in-page editor's endpoints also log
  unexpected errors and answer 500. (#74)
- **PHP errors no longer reach visitors outside development.** Raster
  turns `display_errors` off and `log_errors` on for every request
  outside development, whatever php.ini says. The command line keeps
  showing errors. The plain 404 page is now printed after the session
  starts, so a visitor with a session cookie no longer sees
  `session_start()` warnings. `raster doctor` warns in production when
  php.ini has `display_errors` on, because an error before Raster starts
  would still show. Set it off there too. (#74)
- **A database outage answers 503 instead of the mock-up.** When the
  database can't be reached (MySQL down, an SQLite file the web server
  can't read), pages outside development answer 503 instead of showing
  the template's mock-up content with a 200. `/api` answers
  `{"error":"database unavailable"}` with 503 before the method runs,
  whatever role the method needs, so a webhook is never told "no such
  order" and a logged-in caller is never told to log in. The error is
  logged and nothing is cached, so the real pages are back as soon as
  the database is. To show your own page, add a view that uses no model
  needing the database and set
  `config::set('error_document_503')->to('503')`. Development shows the
  error instead, on pages and on `/api`. A table that doesn't exist yet
  still shows the template default, as before. (#65)
- **The MCP view tools stay inside the views folder.** `list_views`,
  `read_view`, `check_view` and `write_view` accept a `theme` only when
  it is the name of a folder directly under `views/` (letters, digits,
  `_` and `-`). A path such as `../..`, a folder inside a theme, or a
  theme folder that links out of `views/` is refused, and the error
  lists the themes there. An agent holding the MCP token can no longer
  read files elsewhere on the server, or write a page into `media/`.
  Leaving `theme` out still means the site's theme. (#72)
- **Text with `$` or `\` in it stays as written.** Prices like `$5` and
  paths like `C:\new` used to lose characters in two places: what the
  in-page editor showed (and Duplicate then copied), and form fields
  refilled after a failed rule. A value with `\"` in it could stop the
  editor from loading on that page. These values are now kept exactly as
  entered, including in an attribute Raster adds to a tag. (#66)
- **A link a visitor typed can't hide `javascript:`.** A tab, newline,
  control byte or HTML entity inside the scheme no longer gets a
  `javascript:`, `data:` or `vbscript:` link past the filter on
  `print.@href` of a record field. That includes entities for control
  characters such as `&#1;` and `&#13;`. The in-page editor's clean-up
  of links that editors type uses the same rule and now also blocks
  `vbscript:`. (#67)
- **Lists and bad bytes no longer skip form rules.** A field sent as
  `name[]=x` when the form doesn't name it `name[]`, or with bytes that
  aren't UTF-8, now fails `required`, so the form shows its message for
  an empty field and nothing is stored. A field the form does name
  `tags[]` still takes a list, and a `pattern` that can't run counts as
  not matched. (#70)
- **The in-page editor's endpoints refuse a list where they take one
  value.** A post with `id[]=…`, `value[]=…`, `type[]=…` and the like
  now answers 400 and changes nothing. Before, `id[]=99` on
  `editor_delete_item` deleted item 1, and `value[]=x` stored the text
  `Array`. (#139)
- **A slug given as a list is refused** like any other field instead of
  being stored as `array`, from the editor and from MCP alike. (#139)
- **Resetting a password from the command line no longer makes the
  account an admin.** For an account that already exists, `raster user
  <email> --password=…` keeps the account's role, and `--role=…` alone
  keeps its password instead of setting a new random one. The command
  says what it kept. A new account is still an admin with a random
  password unless `--role` or `--password` say otherwise. (#71)
- **After a lock runs out, wrong passwords are counted from zero
  again.** Once 15 minutes have passed since the last wrong password, it
  takes five new wrong ones to lock the account again. Before, one wrong
  guess after the first lock locked it for another 15 minutes, and the
  right password was refused too. (#73)
- **Cached pages are never served cut off or stale.** A cached page and
  the cache version are now written to a temporary file and renamed into
  place, so a visitor arriving mid-write gets the old page or the new
  one. The version is a random value read once when the request starts.
  A page whose render overlapped a content change is not kept, so it
  can't be served as a hit afterwards. (#68)
- **A content change deletes the old cached pages.** Before, the files
  stayed in `data/cache/` forever after every change; now only pages for
  the current content are on disk, and temporary files left by a request
  that died are swept after an hour. (#56)
- **Made-up list URLs no longer fill the page cache.** A filter page
  with no items (`/news/news_items/tag/nothing`), a page past the last
  one (`/news/news_page/999`), a filter on a field the list doesn't
  have, a list or item URL spelled other than the way its links spell it
  (`/news/news_page/02`, `/news/news_item/007`) and a typed filter
  spelled other than the way the page prints it
  (`/menu/menu_items/price/14.500`, `/events/events_items/date/10 Oct
  2026`) still answer 200, but are not cached. A model that knows its
  page shouldn't be kept can call `raster_cache::skip()`. (#56)
- **Links with tracking parameters are cache hits.**
  `/about?utm_source=newsletter` (any `utm_*`, and `fbclid`, `gclid`,
  `msclkid`) is served the cached `/about`, and is built without those
  values, so they never end up in the page other visitors get. Any other
  query parameter still skips the cache. (#56)
- **Cached list pages stay cached when another list on the page doesn't
  print the filtered field.** The site's own number filter links
  (`/menu/menu_items/price/3.50`) take their spelling from the
  collection's mock-up, so a sidebar list of names no longer keeps them
  out of the cache. In production, a collection's filter and page URLs
  aren't cached until `schema --apply` has made its table. (#56)
- **The MCP view tools stay inside the theme, links included.**
  `read_view`, `check_view` and `write_view` used to follow a view file
  that is a link to somewhere else on the server, reading it or
  overwriting it. A view file whose link leads out of the theme, or
  leads nowhere, is now refused, and `list_views` leaves it out. A link
  that stays inside the theme still works. (#72)
- **`raster upgrade` refuses an app that isn't there.** When
  `RASTER_APP` is misspelled, names a folder with no `config/`, or names
  a framework folder such as `system/`, upgrade says so in one line and
  exits 1. It no longer prints a PHP warning or a success line it didn't
  earn, and it no longer writes into the framework folder. (#75)
- **`raster upgrade` says when it couldn't record the version.** If
  `config/raster-version` can't be written, upgrade reports it, names
  the steps that already ran (it checks them again next time) and exits
  1. (#75)
- **The page cache keeps one copy per page, whatever the letter case.**
  On a disk that ignores case (macOS, Windows), `/ABOUT`, `/About` or
  `/News/news_page/2` find `about.html` or `news.html` and answer, but
  only the view file's own spelling is cached, so made-up spellings
  can't fill the disk. The check runs only when a page is about to be
  stored. (#56)
- **Two sign-ups at once with one email make one account, on SQLite.** A
  double click or two tabs used to make two accounts; now one is logged
  in and the other is answered with `email_taken`, instead of the second
  overwriting the first. The same goes for two members changing to one
  email at once, and for a member changing to an email someone is
  signing up with. Looking for the email and storing the account happen
  in one transaction, and the password is hashed before it. (#77)
- **New `authentication::create_user($login, $password, $role, $name)`**
  makes an account, or returns null when the login is already taken; it
  never changes an existing account. `save_user()` still creates or
  updates (for `raster user`) under the same lock. (#77)
- **Newsletter sign-ups sent at once with one address leave one
  subscriber, on SQLite.** `newsletter::subscribe()` looks the address
  up and stores it in one transaction, and joins the caller's
  transaction when there is one. (#77)
- **Signing up again before confirming sends the same confirmation
  link,** so every confirmation email already sent still works. Before,
  each sign-up made a new link and the earlier one stopped working.
  (#77)
- **`newsletter.subscribed` is sent after the commit, and the
  confirmation email comes from the framework's listener on it,**
  `newsletter.confirmation_mail`. A rolled-back sign-up stores nothing
  and emails nobody. A site that sends its own email can unbind it with
  `event::unbind('newsletter.subscribed')->from('newsletter',
  'confirmation_mail')`. (#77)
- **Content saved at the same moment no longer collides, on SQLite.**
  Saving or deleting an item and saving a page now run in a transaction,
  as records already did. Items saved at once with one title get
  different slugs, and two editors saving different fields of one page
  at once both keep their change. Unique indexes and MySQL are not
  covered yet. (#77, #78, #129)
- **The page cache and `content_changed` wait for the commit.** Inside
  `cms_records::transaction()`, `util::content_changed()` throws the
  cache away and sends `content_changed` once after the commit, when its
  listeners can read the new rows, and not at all when the transaction
  is rolled back. (#81)
- **On MySQL, `cms_records::transaction()` no longer fails with "There
  is no active transaction"** when a write in it adds a table or column
  (in development, the first account on a new site). MySQL commits by
  itself on such a change, and Raster then has nothing left to commit.
  (#77)
- **The `log://` mail transport no longer warns** when two emails sent
  at once both create its folder.
- **Fields named like SQL words work in lists.** A field called `when`,
  `from`, `group` or `to` now sorts and filters like any other, in
  `order=`, in comparisons such as `group>2`, on filter pages such as
  `/trips/trips_items/from/Paris`, in `cms_records::find`, and in
  `schema --rename` and `--drop`. Before, the list came out empty in
  development and showed the mock-up in production. (#69) A field named
  `order` or `limit` works too, except that `order=` and `limit=` in
  list options still mean the sort and the limit.
- **Lists work on MySQL again.** The filter that hides drafts and
  scheduled items, and `order=newest`, compare `published_at` with an
  empty string only while the column is still text, as in a table made
  before 2.1.7. MySQL refused that comparison for the typed column 2.1.7
  creates, so visitors saw the mock-up and pages with a pager, feeds and
  the sitemap answered 500. (#64)
- **A time prints `19:00` on every database.** A `time` value is read
  without seconds, so MySQL's `19:00:00` prints the same as on SQLite.
  (#64)
- **Apps with no version file are taken as current.** `raster upgrade`
  (and so `raster update`) no longer treats an app without
  `config/raster-version` as a Raster 1.x site and runs every old step
  on it, which could turn on `api_open` in a second app made by hand. It
  writes today's version into the app, runs no old steps and says so in
  one line. For an app that really is from 1.x, write `1.0.0` in that
  file first, then run `raster upgrade`. `raster version` says which
  apps have no version file (#75).
- **Theme SVGs, WebP images, fonts, icons and PDFs load on Apache.** New
  sites no longer get `application/.htaccess` and `system/.htaccess`,
  old Apache 2.2 rules that refused anything but png, jpg, gif, js and
  css under the app folder, and gave every theme file a 500 on an Apache
  without `mod_access_compat`. The root `.htaccess` still refuses
  config, data, models and system on its own. `raster update` removes
  `system/.htaccess`; if your site has an `application/.htaccess`,
  delete it yourself (#76).
- **Long lists fill every row.** A `print.model.method` or
  `print.if.flag` inside a render block used to stop after 1,000 rows:
  later rows kept the mock-up and the raw annotation, and a `print.if`
  block meant for staff showed to visitors. Every row is now filled, in
  one pass over the page, so 2,000 rows take about 4 ms instead of 25.
  Sites need do nothing. (#54)

## 2.1.7

Existing databases are not converted for you. `php bin/raster schema`
shows each field's type and the columns that differ, and
`schema --apply` converts them, Raster's own (`enabled`,
`published_at`) included, where every value fits.

- **Fields have types.** A field is text, `int`, `number`, `bool`,
  `date`, `datetime` or `time`. A template field's mock-up says which
  (`14` an int, `4.50` a number, `2026-10-10` a date, `19:00` a time,
  anything else text); a record field's default does (`0` an int, `false`
  a bool), and `'types' => array('date' => 'date')` in `types()` names
  the rest. The column is declared as the type.
- **Values are stored as their type,** whoever writes them: `5 Oct 2026`
  becomes `2026-10-05`, `8pm` becomes `20:00`, `yes` becomes 1. A value
  that can't be (`many` guests, `2026-02-30`, `31 Feb`, a stray letter
  in a date, `01234` as a whole number) is refused with the field and an example. A form value the
  HTML let through raises `<field>_invalid`; a ticked checkbox is yes
  whatever its `value`.
- **Lists compare and sort by type:** `order=-price` puts 100 above 18
  above 9.50, and `price<10` compares numbers.
- **Models, MCP and `/api` read ints, floats and bools.** Templates and
  the in-page editor still get text; a number prints with its mock-up's
  decimals, so `14.50` stays `14.50`, from any model, records included.
  After a save the editor shows what was stored (`9.50`, `2026-10-05`),
  not what was typed.
- Columns are declared the same way in MySQL (`DOUBLE`, `TINYINT(1)`),
  and `schema --apply` never leaves a column half converted.
- `schema`, `describe` and MCP `site_overview` list the types, and
  `schema --apply` converts a column whose type changed when every value
  fits.
- `enabled` is a bool now: compare `!$item['enabled']`, not `=== '0'`.

## 2.1.6

Nothing for sites to do. Staff pages that show records with a model's own
rows (`render.booking.rows` returning `cms_records::find(…)`) now get a
`lint` warning: list them with `render.cms.<type>` and the options below,
or return them through `cms_records::listed()`, so staff can edit them in
the page again.

- **Lists filter from the URL, by date, and sort by several fields.**
  `render.cms.booking('stylist=?stylist&date>=today&order=date,time')`:
  `field=?name` takes `?name=` from the URL and is left out when the URL
  has none, so a plain `<form method="get">` filters a staff page with no
  model code. `>`, `>=`, `<`, `<=` and `!=` compare, and `today` is the
  date the page is shown, so upcoming and past bookings are two lists, not
  a stored field that goes stale. `order` takes several fields.
  Pagination takes the same options and its links keep the query string.
- **Get forms keep what was asked.** `/bookings?stylist=Ana` shows Ana
  chosen in a `<form method="get">`.
- **Views a model builds stay editable.** A render method that returns
  records through `cms_records::listed($type, $rows)` gets the in-page
  editor's marks, also in lists inside its rows: an agenda grouped by day
  is edited in place, with each record's actions. Lists inside rows now
  render through the same code as a render block, so their attributes
  (`print.@href`) and escaping work too.
- **Staff can add records a form makes.** A type with
  `'staff_add' => true` gets the in-page editor's card for a new item (a
  booking taken over the phone). A new item starts with the values the
  list's filters ask for, as the URL gives them. No card on an item's own
  page.
- **Render a page as staff.** `php bin/raster render /bookings --as=editor`
  (an account's email or username, or a role) renders the page as that
  person and prints what the in-page editor can edit there. MCP
  `render_url` takes `as` and answers with the same as `editor`. Both
  refuse in production, where they would show any account's pages
  without a password. `raster render` also passes a URL's query string to
  the page.
- **`lint` warns about records staff can't reach:** a type a form makes
  that no view lists with `render.cms.<type>`, and an admin page showing
  records a model reads itself without `cms_records::listed()`.
- **MCP over stdio survives a PHP error.** An `Error` in site code (a typo
  in a listener, say) answers the call with its file and line instead of
  ending the server, and output a tool's code prints no longer reaches the
  protocol stream.
- **The in-page editor keeps several lists of one collection apart.** An
  item added from a list's card was counted in every list of that
  collection on the page, so the next card of another list showed up
  under the first one.
- AGENTS.md: staff pages keep the in-page editor working: list options,
  then a model view with `listed()`, then links to each record's page.

## 2.1.5

Nothing for sites to do. Back up an SQLite database by copying the file
together with its `-wal` and `-shm` files, or with `sqlite3 <file> .backup`.

- **Fewer database queries per page in production.** With the schema
  frozen, the CMS reads the list of tables and their columns once per
  request instead of several times per field, turns list rows into arrays
  without checking every table's columns, reads a page's row once for all
  its fields, and no longer counts a list's rows before showing it. Demo
  pages went from 22–74 queries to 3–9.
- **SQLite runs in WAL mode.** Readers no longer wait for a write to
  commit, and writes cost less. Each connection also sets
  `synchronous=NORMAL` and waits at most 5 seconds for a locked database,
  not PDO's 60. Existing databases switch on their next connection. The
  `-wal` and `-shm` files are never served, and `raster export` ignores
  the `-shm` file when deciding whether anything changed.
- **Item ids in lists and feeds are numbers.** `render.cms.<name>` and
  `feed.items` now give `id` as an integer, as MCP and the editor already
  did. Pages print the same.

## 2.1.4

Run `php bin/raster cache clear` on production sites after updating, so no
page cached before the update is served at an address that is now refused.

- **Security: attributes set inside render rows could be broken out of.**
  A value printed with `print.@attr.key` or `print.+attr.key` inside a
  render block could close the attribute and add its own (an `onmouseover`,
  say) with a backslash sequence. Links built from the request path, such
  as the language switcher and pagination on filter pages, made this
  reachable from a crafted URL, and so did a visitor-written field of a
  public record type. Row attributes are now set the same way as
  top-level `print.@attr`, where the value is only ever text.
  (GHSA-v6gr-g4cg-vjcr)
- **Security: protected pages could be reached without logging in.**
  Behind nginx, Apache or Caddy, `/./staff`, `/%2E/staff`, or `/Staff` on a
  disk that ignores case, rendered a page `protected` keeps for staff.
  Now `a//b` is `a/b`, any path with a segment starting with a dot is a
  404, and each `protected` pattern matches the start of the URL and of
  the view it renders, ignoring case. (GHSA-52xw-67m4-c6xw)
- **`print.@src` in a render row leaves `srcset` alone.** The attribute is
  matched by its whole name, on the tag the annotation wraps only, so
  `srcset`, `data-src` and attributes of nested tags are no longer
  overwritten.
- **Every error gets the plain error page in production.** Only template
  errors did; an `InvalidArgumentException` or a named query that doesn't
  exist ended as a raw PHP fatal. Now anything thrown while rendering shows
  the plain page and is written to the error log with where it happened.
- **Warnings and errors always reach the PHP error log.** `log::warning`
  and `log::error` did nothing unless `log::enable()` was called, so a
  password reset email that failed to send left no trace. `log::enable()`
  still controls the browser console.
- **Page requests do less.** The lint, schema and describe tools load only
  when MCP answers, and the framework no longer asks the autoloader for
  `the_config` and the other core overrides on every `config::get`.

## 2.1.3

Nothing for sites to do.

- **Clearing the page cache by hand.** In production, changes Raster doesn't
  make itself (a view, theme file, model or config edited with ordinary file
  tools, or the database changed directly) were invisible to visitors until
  `page_cache_ttl` ran out, while `raster render` and MCP `render_url`,
  which never read the cache, already showed them. `php bin/raster cache
  clear` and the MCP tool `clear_cache` throw the cache away. The MCP
  server's instructions tell agents when to call it, and `describe` says
  whether the cache is on (`site.page_cache`).

## 2.1.2

Nothing for sites to do.

- **A site can live under a path.** When `RASTER_URL` (or `site_url`) has a
  path, e.g. `https://example.com/shop/`, that path is where the site is:
  requests under it are routed, and links, the `<base>` and emails carry it.
  `/shop` without the slash redirects to `/shop/`. This works behind a proxy
  that forwards `/shop/...` unchanged, and with `raster serve`, static files
  included. Running `php -S` yourself, set `RASTER_URL`: its router runs
  before the site's config is read, so it can't see `site_url`. Private
  paths are refused the same way under the folder.
- **Agents are told: every form that saves data gets a staff page.**
  `AGENTS.md` (Records) now asks agents that add a form storing records to
  also add a page, protected for editors, that lists them, so what visitors
  send is never stored out of sight.
- **The in-page editor keeps the site's look on every page.** Once the
  browser had cached the editor's script, it could run before the page's
  stylesheets applied and take the browser's defaults (serif type, black on
  white, a default blue) for the site's colours and fonts. It now reads the
  look again when the stylesheets and the page have loaded.
- **The in-page editor lists admin pages.** Every view `protected` keeps
  for `editor` or `admin` shows in a new **Admin** menu in the editor's bar,
  by its `<title>`, for whoever may open it. Staff pages no longer need a
  hidden menu or a `print.if.is_editor` link. `describe` (and MCP
  `describe`) has a new `admin_pages` section.

## 2.1.1

**Security: `/api` answers only what a model lists.** Until now every public
method of every application model answered at `/api/<model>/<method>`, to
anyone. That included methods written for templates on protected pages: a
staff page's `unpaid_orders()` was protected at `/orders` and readable by
any visitor at `/api/…/unpaid_orders`, and `cms_records::find()` (which is
for the model's own code) returns private records to whoever calls it. Now a
model lists what it offers, with the least role that may call each method:

```php
static function api() {
    return array('hours' => 'visitor', 'webhook' => 'visitor', 'day' => 'editor');
}
```

Anything not listed answers 404. A role the caller lacks answers 401 (not
logged in) or 403. `lint` checks `api()`, and `vocabulary` shows what each
model offers. `/api/the_<model>/…` is 404: an override of a bundled model
is only ever reached by the name it overrides, and only when that name is
in `api_system_models`.

**What sites need to do** after `php bin/raster update`:

- If any of your models has no `api()`, the upgrade adds
  `config::set('api_open')->to(true)` to `config/the_app.php`, so `/api`
  keeps answering as before and nothing a script depends on breaks. That
  also keeps the hole open. `php bin/raster doctor` warns about it until you
  close it:
  1. Find what calls your `/api` (page scripts, webhooks, other servers).
  2. List those methods in each model's `static function api()`, with the
     least role that may call them.
  3. Remove the `api_open` line.
- `api_open` stops working in 2.2.0.

## 2.1.0

**What sites need to do** after `php bin/raster update`:

- Run `php bin/raster doctor`. If it reports an `.htaccess` from an older
  Raster, replace it: `php bin/raster deploy --config=apache > .htaccess`
  (or `--config=nginx|caddy` for those servers).
- Public files in a folder named `config/`, `models/`, `data/` or `i18n/`
  anywhere in the site are now refused (403). Move them to `media/` or the
  theme folder.
- Code that called a named query that doesn't exist got `false`; it now
  throws. `php bin/raster lint` finds those calls.
- Anything that used the old editor endpoints (`edit_variable`, `edit_data`,
  …) or posted `raster_action` needs the new editor or MCP instead.
- Records are new and optional: nothing changes until a model declares a
  type. In production, `php bin/raster schema --apply` then creates its table.

- **Records: types a model declares, stored and shown by the CMS.** A model's
  `static function types()` declares bookings, orders, applications: their
  fields, who may create them, which fields are `readonly` or `hidden`, whether
  they are `public` or have an `owner`, and their `actions`. The CMS stores
  them as a collection named after the type, lists them wherever a view
  renders `render.cms.<type>`, and lets editors and agents change them with
  the in-page editor and the MCP item tools. The model's static
  `check($type, $after, $before)` runs before every write — form, editor, MCP
  or the model's own code — and names problems the template words as alerts.
  Records are private unless the type says otherwise: visitors see none, and
  feeds, the sitemap and exports leave them out. What visitors typed prints as
  text. `cms_records::submit()` is a whole form handler;
  `cms_records::transaction()` makes several writes happen together or not at
  all, with events after the commit; list fields (line items) are stored as
  JSON and render as nested rows. Actions are buttons in the editor for the
  roles allowed, `run_action` over MCP. `owner=me` lists the logged in
  person's own records (an account page). `php bin/raster make model <name>
  --from=<view>` writes a model for a form's records. Nothing changes for
  existing sites. See the Records section of AGENTS.md.
- The editor's item handle shows a record type's actions instead of Duplicate
  and Schedule, and never makes a readonly field editable.
- **An example shop** in `shop/` (Blue Hour Ceramics): public products,
  private orders with an owner, a cart in the session, a checkout that takes
  stock in a transaction so the last piece can't sell twice, and Ship, Mark
  paid, Cancel and Refund actions. Buyers pay the courier on delivery by
  default; cards go through Stripe Checkout (or a pretend provider in
  development), with a signed webhook at `/api/order/webhook`. It is an example to copy, not
  part of the framework. `php tests/shop.php` runs it end to end.
- The demo café's bookings are records: `/staff` groups them into to
  confirm, confirmed and cancelled, `/reservation` lists them all with links
  by evening and status, a day holds 20 guests, and members see their own
  bookings on `/account`.
- **Tooling an agent can afford.** Everything an agent needs to work on a
  Raster site is now an MCP tool, so it is asked and answered in one
  long-lived process instead of starting PHP again per question: `describe`
  (~70 ms) returns how URLs reach views, the content model the markup
  declares, the vocabulary, the behaviour-changing settings and the current
  lint state; `vocabulary` lists every model with its methods **and their
  signatures**, the named SQL queries, the events and who listens, and the
  names the CMS keeps; `annotations` is the grammar; `list_views`, `read_view`,
  `check_view`, `write_view` and `render_url` cover reading, checking, writing
  and seeing a template. `write_view` refuses markup that does not lint and
  leaves the file untouched, so a hallucinated model or method cannot land.
  `render_url` runs in its own process, so a page that dies cannot take the
  server down. On the command line: `php bin/raster describe` and
  `php bin/raster vocabulary`, both with `--json`.
- **`lint` checks the arguments.** Annotations pass literals, so the count is
  known without running anything: too few or too many is now an error, naming
  the signature it read (`cafe.category_count(category) needs 1 argument(s), 0
  given`). A method name that does not exist also gets the nearest one that
  does.
- `write_view` edits code, not content — a template can call any model — so
  over HTTP it is not offered until a site sets `mcp_write_views`. Over stdio,
  where the agent is already on the machine with the files, it is available.
  `describe` never includes the MCP token or the mail transport.
- **One list of what is never served.** The rules were kept twice, in
  `.htaccess` and in the router in `index.php`, and they had already drifted:
  neither refused `.phar`, so a `composer.phar` in a site folder was
  downloadable. They now live once, in `system/private_paths.php`, which the
  router reads and which `php bin/raster deploy --config=apache|nginx|caddy`
  turns into the configuration for the server in front (the shipped
  `.htaccess` is that output). `.phar`, `.lock`, `.ini` and `.bak` join the
  private extensions, matching ignores case on every server (a
  case-insensitive disk could otherwise hand out `/DEMO/DATA/SITE.SQLITE`),
  and `/.well-known/` is the one dot folder that is served. `doctor` checks
  that the `.htaccess` on disk still carries every rule; a site's own
  `.htaccess` from an older Raster is reported, and
  `php bin/raster deploy --config=apache > .htaccess` replaces it.
  No rule names an app folder any more, so `config/`, `models/`, `data/` and
  `i18n/` are private under every top level folder, not only app folders: a
  static file at `/assets/data/x.json` is now a 403. Put public files
  elsewhere (`media/`, or the theme folder).
- **`lint --fix`** repairs what has one right answer: spacing the engine
  can't read (`<!--print.cms.x-->`) and short closing tags. The engine still
  needs the whole name in a closing tag; lint now says which block a
  `<!-- /render -->` meant and writes `<!-- /render.cms.menu('order=name') -->`
  in. A likely misspelled keyword stays a warning, since it may be an
  ordinary comment, and everything else that needs a decision is reported.
- **The annotation grammar is data:** `system/tools/annotations.php`, printed
  by `php bin/raster annotations [--json]`. `lint` checks against that same
  file, so what an agent is told and what is enforced cannot drift apart.
- Config `allow_deprecated` marks uses of deprecated features a site keeps on
  purpose (`array('<id>' => true or path pattern(s))`); `doctor` counts them
  apart instead of warning. The demo café uses it for the older validation
  regions its suite still covers.
- The ORM no longer needs `pdo_mysql` to be loaded on an SQLite-only site:
  `rb.php` read `PDO::MYSQL_ATTR_INIT_COMMAND` as it loaded, which is a fatal
  error when PHP has no MySQL driver.
- `raster_project::apps()` and the router agreed that any top level folder
  with a `config/` inside is an app folder, which made `system/` one.
- `raster export <folder>` writes the site as static files: every page,
  item, list page and feed, the 404 page, theme files and uploads, and each
  language in its own folder. Exporting again writes only what changed,
  removes what's gone, and does nothing when nothing changed.
- `print.if.live` and `print.if.static`: forms go in the first, what a static
  export shows instead in the second. An export stops when a page still
  shows a form.
- Uploaded pictures are stored with root-relative addresses (`/media/…`),
  so they survive a new domain or a static export.

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
- A link inside a `print.validation.alert()` block goes to the route. The
  block is only a placeholder while the page renders, so the markup a
  listener puts back at the end used to miss the pass that turns
  `cart.html` into `/cart`. Anything added on `before_output`, the CMS
  toolbar included, is fixed up now.

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
