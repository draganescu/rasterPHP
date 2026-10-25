# One language for the controls — design

Status: draft for Andrei's review, 2026-10-25. Covers both repos:
raster-builder (this one) and rasterPHP (upstream, listed in
`docs/raster-upstream.md` when built). Once approved, it becomes a milestone
spec here (`01-*.json`) plus the matching rasterPHP work.

## Why

On the sandbox, Andrei asked the agent to add French to the Walter Merecineanu
House. On the French page, Raster's editing toolbar showed `editing: FR` and,
right next to it, a dropdown showing `EN`. The dropdown picks the language of
the editor's own buttons. It guessed English from the browser, and it only
offers English and Romanian. Two language codes side by side, set in two
different ways, is confusing. The builder itself has no language choice at
all: it is English everywhere.

There are two different things that have a language:

- **The content**: what visitors read. It follows the page (`/fr/…` is
  French). This already works and doesn't change.
- **The controls**: every button, menu, message and email the person building
  or editing the site sees, in the builder and in Raster's editor. This design
  is about the controls.

## The rule

**People get the language they have seen, never one guessed behind their
back.** Nothing reads the browser's language to pick the language of the
controls. Editing a French page does not switch the controls to French. Writing
to the agent in French does not switch them either. The only thing that changes
the language of the controls is a setting the person can see.

## Goals

1. **One setting per builder account**, chosen in a dropdown in the builder.
   The builder screens, its emails, and Raster's editor inside the builder
   preview all follow it.
2. **The starting value comes from the page the person signed up from.** A
   French marketing page links to `/auth/signup?lang=fr`, the sign-up page
   shows in French, and the account starts in French. A link with no language,
   or with one we don't have, means English. Existing accounts start in
   English, because that is what they have been seeing.
3. **On a published, downloaded or self-hosted site** (no builder around it),
   Raster's controls use the site's base language: the first in its list of
   languages. A site with no languages set uses English. There is no dropdown
   there.
4. **Words that are missing fall back to English**, one by one. Mixed languages
   are acceptable: for example, builder screens in Hindi next to a Raster
   button still in English.
5. **First round: 16 languages.** English, French, German, Spanish, Italian,
   Portuguese, Dutch, Romanian, Hungarian, Polish, Bulgarian, Greek, Turkish,
   Japanese, Hindi, Chinese (Simplified). Every one of them reads left to
   right.

## Non-goals

- **The marketing site at www.sibip.io.** It is a sibip-built site, and
  Andrei will translate it himself with the builder, as a dogfood test of
  Raster's translations. The only thing the builder provides is that
  `/auth/signup?lang=xx` (and `/auth/login?lang=xx`) work.
- **The chat.** The agent already answers in the language the person writes
  in (agent.mjs:184). That stays as it is, and it never changes the setting.
- **Right-to-left languages** (Arabic, Hebrew, Urdu). Later.
- **Content translation.** That is M12 (#204), already done.
- **The admin page** (`router/src/admin.js`). It is for Andrei only, and stays
  in English.
- **Developer and agent text**: Raster's command-line tool, its MCP tool
  descriptions, the agent's prompts, and the `HOSTING.md` file in downloads.
  These stay in English.

## Anti-goals (it fails if…)

- Anything picks a language from `Accept-Language`, `navigator.language` or a
  cookie the person never set.
- Two language controls appear in the builder, or Raster's own dropdown shows
  up in the builder preview.
- Opening `/fr/` changes the language of the buttons.
- A missing translation shows a key name (`problem_form_expired`) instead of
  the English words.

## Builder side

**The setting.** A new column `users.ui_lang` (migration `022`). It is one of
the 16 codes; empty means English. `request.user` already reloads the row on
each request, so a change shows straight away. `/auth/me` returns it.

**The dropdown.** It lives in the builder's account menu. It lists each
language by its own name (Français, Deutsch, 日本語, हिन्दी, 简体中文), so
someone who landed in the wrong language can still find theirs. Choosing one
saves it and reloads the page.

**Pages made by the router.** Every page the router makes follows one rule:
signed in, the account's setting; not signed in, `?lang=` in the link, else
English. Pages for signed-in people: `waitlistPage`, `signOutPage`, "Setting
up" (provision.js), "One moment" (upstream.js), and the billing pages
(`/billing/account` in billing.ts, `/billing/subscribe`, `/billing/credits`,
"needs a plan" and `/billing/pay` in checkout.js). Pages for signed-out
people: `page()` (sign-in and sign-up) and `signedOutPage` in auth.js.
`?lang=` is carried on the signed-out pages the same way `?next=` is today:
on the form action, the cross-links and the error redirect. On sign-up it is
stored before `onSignup()`, so the welcome email and the waiting-list page
after it are in that language too. Signing in does not change a stored
setting.

**What gets translated.**
- ui/index.html and every string in ui/src (about 315 strings in 19 files,
  plus the chat's tool labels in ui/src/tools.js).
- Every page the router makes (listed above), billing included.
- The error sentences the router sends that the UI shows as they are (about
  120 `error: '…'` in router/src). The router words them in the account's
  language, because it knows the account.
- Errors saved in the database and shown later: domain purchases, publishing
  (`Publishing failed: …`) and downloads. New rows store a code, plus any raw
  detail from the tool that failed, and the words are added when they are
  shown. Old rows that hold an English sentence are shown as they are, with no
  data migration.
- The 10 email templates in mail-templates.ts. `Mailer.send` gets the
  account's language.
- The default site name "New site", where the UI shows it. It stays stored
  in English, because the machine compares against it. (The direction cards
  are the agent's input and are never shown, so they stay as they are.)
- Number and date formats (today hard-coded `en-US` and `en-GB`) follow the
  setting. So do the language names in the preview's language switcher
  (`languageName`) and the Paddle checkout's `locale`, which today comes from
  the browser.

**How it's built.** One small `t(key, vars)` helper shared by the UI and the
router, with English as the default and one file of words per language. With
English as the default, the roughly 80 test assertions that look for English
phrases keep passing.

**Raster's editor in the preview.** On requests to `/preview/<id>/…`, the
router sends Raster the account's language in a request header, and removes
any copy of that header the browser sent. Raster uses it for the controls,
but only in development mode, which is what the builder preview runs
(`RASTER_ENV=development`). Public previews and published sites run as
production, so there they use the site's base language. Because it is sent on
every request, changing the dropdown changes the preview's editor on the next
reload, with no need to sign in again.

**Fonts.** The builder screens use the system fonts, which cover all 16
languages. The sign-in pages' two fonts (Young Serif, Literata) are cut down
to Latin letters. For Greek, Bulgarian, Japanese, Chinese and Hindi the
browser falls back to the system fonts. No new font files are added.

## Raster side (rasterPHP)

**One rule for the language of the controls**, on every site, multilingual or
not:

1. the language the builder sends in the request header, only in development
   mode;
2. else the site's base language (`i18n::default_language()`);
3. else English.

Each word falls back to English when the dictionary doesn't have it. A site's
own `i18n/<lang>/raster_editor.php` still wins over Raster's words, as today.

**What goes away:**
- reading `Accept-Language` in `pick_editor_lang()`, and on single-language
  sites, picking the editor's words with `i18n::detect()` (the visitor's
  language);
- the per-person choice: the `user.editor_lang` column, the
  `editor_set_lang` endpoint and the dock's dropdown (`ui.langs`).

The `editing: FR (falls back to RO)` label stays. It shows the content's
language, and that is the label the toolbar needs.

**What gets translated.** Everything Raster shows the person editing:
- The editor's dictionary, about 100 phrases. It exists in English and
  Romanian; 14 more languages get added.
- About 15 English sentences that the server sends and the editor shows as
  they are ("Unknown page", "No such revision", "This page is in ro: reload
  it", "Something went wrong; it is in the error log" and others, editor.php).
  These become problem codes that the editor words from its dictionary, the
  way `form_expired` already works. Raw exception text stays as it is, after
  the worded message.
- Raster's fallback login page (`system/views/cms_admin/login.html`, used only
  when a theme has no login view). Today its words are all hard-coded English.
  It is a page visitors can reach, so it uses the content's language, worded
  from Raster's own dictionary and falling back to English. On a multilingual
  site that is the language in the URL (`/fr/login` is French). On any other
  site it is the base language, never `i18n::detect()`, which reads the
  browser.

Visitor text doesn't change. Since 2.2.46 the framework prints no English to
visitors, and the site's dictionaries word everything in the page's language.

**Release.** One rasterPHP release at the end, and the builder's
`RASTER_REF` is raised to it. Tests to update: P2.B ("the editor's own words…
the dock's choice wins") and the editor-browser check for the dock's `.langs`.

## Translations

An agent drafts every dictionary, for both products, from the English. Andrei
or native speakers correct them over time; a wrong word on a button is cheap to
fix. Chinese uses the code `zh` and means Simplified.

## Verify

- Sign up from `/auth/signup?lang=fr`: the sign-up page, the welcome email, the
  builder and the preview's editor are all in French.
- Sign up with no `?lang=` and a Romanian browser: everything is in English.
- Switch the dropdown to Japanese: the builder and the preview's editor are in
  Japanese after a reload, and any word that is missing is in English.
- Open the French page of a Romanian-first site: the buttons stay in the
  account's language, and the label says `editing: FR`.
- On a published site, log in as the owner: the editor is in the site's base
  language and has no language dropdown.
- With `?lang=` set to a code we don't have (`/auth/signup?lang=xx`): English.

## Calls made by me, not decided by Andrei

- The setting lives in the builder's account menu, with each language listed
  by its own name.
- Signing in with `?lang=` doesn't change an existing account's setting. Only
  sign-up and the dropdown do.
- The builder passes the language to Raster in a request header that Raster
  reads only in development mode. That way the dropdown changes the preview's
  editor without signing in again.
- Raster's fallback login page follows the content's language: the URL's on
  multilingual sites, the base language elsewhere. It is not tied to the
  account, because nobody is signed in yet.
- Signed-in router pages, billing included, follow the account. `?lang=` is
  only for signed-out pages.
- Saved errors (domains, publishing, downloads) become codes from now on. Old
  rows are shown as they are.
- English stays for: the admin page, `HOSTING.md`, the command-line tool, MCP
  text, and the raw error text that comes from the SDK.
- No new fonts. Non-Latin text on the sign-in pages uses the system fonts.
- `zh` means Simplified Chinese, and Portuguese has a single version.
