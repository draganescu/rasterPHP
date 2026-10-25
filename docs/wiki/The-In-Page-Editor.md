# The in-page editor

Raster has no separate admin area. When an editor is logged in, the page itself becomes the editor: text, pictures and lists are changed where they appear, in the site's own fonts and layout. Visitors never see or download any of it.

This page is for developers setting it up and for editors using it.

## Who can edit

Accounts with the role **editor** or **admin** (see [Accounts and roles](Accounts-and-Roles)). Create one with:

```sh
php bin/raster user editor@example.com --role=editor
```

Editors log in at `/login`. If your theme has no `login.html`, Raster provides a plain one at that address. Once logged in, editors also see draft and scheduled items that visitors don't.

## Using it

After logging in, every HTML page of the site shows an **Edit** button.

| To | Do |
|---|---|
| start or stop editing | press **Edit** or the `E` key; `Esc` stops |
| change text | click anything that glows, type, then click elsewhere. Changes save as soon as you leave the field. |
| format rich text | select text in a rich field for bold, italic, link, list or plain text. In a field that is a paragraph or a heading, Enter starts a new line in it rather than a new paragraph; in a field that is a list, Enter adds a bullet |
| undo | ⌘Z / Ctrl+Z, or **Undo** in the message that confirms each change |
| replace a picture | click it, choose or drop a new one, drag to choose what shows, then **Use photo** |
| work on a list item | use the handle on the item: **Details** (every field, the slug, visibility, publish date), **Duplicate**, **Hide**/**Show**, **Schedule**, **Delete** |
| add a list item | use the card at the end of each list: it's made from the template's sample item |
| do it all from the keyboard | in edit mode, Tab reaches each item, picture and new-item card. On an item, Enter moves into its handle (Tab between its buttons, `Esc` to come back); on a picture, Enter chooses a new one; on the card, Enter starts a new item |
| see fields that aren't visible | open the **Page** panel: fields in `<head>` (like the title and meta description) and in attributes are listed there, with the page's lists |
| open a staff page | **Admin** menu in the editor's bar: every page protected for editors or admins that you may open (see [Admin pages](#admin-pages)) |
| go back to an earlier version | **Page** panel → **History** → **Restore**. Restoring adds a new version; nothing is overwritten. |
| log out | in the editor's menu |

An empty text field shows the template's default text again.

## Admin pages

A page protected for editors or admins is an admin page, and the editor's **Admin** menu lists it:

```php
config::set('protected')->to(array(
    'staff'    => 'editor',   // staff.html, and staff/rota.html under it
    'payments' => 'admin',
));
```

Each page shows under its `<title>`. People see the pages they may open, so editors don't see `payments`. Nothing else needs registering, and the site needs no hidden staff menu. The menu only appears when there is at least one admin page. `php bin/raster describe` lists them under `admin_pages`.

## What it edits

Exactly what the templates declare, nothing more:

- every `print.cms.<field>` on the page, site-wide `site_*` fields included
- every `render.cms.<collection>` list, its items, and the fields inside each item
- picture fields written as `print.@src…`

A field editors can't reach means the template doesn't declare it. Add the annotation and it's editable. See [The CMS](The-CMS).

## On a multilingual site

On a [multilingual site](Translations) the editor edits the language of the page you are on: on `/ro/about` you write the Romanian, and `/about` keeps its English. The dock says so with a small badge, `RO`; point at it for "editing: RO (falls back to EN)". The default language's pages have none, so the dock fits a phone. A field with no Romanian yet shows the English and is marked, and the Page panel lists the fields not translated yet and the ones that are the same in every language: a photo or a price changed on `/ro/` changes everywhere. On `/ro/menu` a dish's name is saved in Romanian and its price for every language; a dish added there is made in the default language. The history says which language each revision changed, and Restore brings back every language of that version.

On a production server, run `php bin/raster schema --apply` after adding a language: until then a text field saved in that language is refused, with a message saying so, instead of landing in the default language.

## Matching your site's look

The editor's own controls take their colours and fonts from the page: the body text and background, and the colour of the first button as the accent. To choose them yourself, set any of these CSS variables on `:root` in your stylesheet:

```css
:root {
  --raster-accent: #0b6e4f;      /* buttons, highlights */
  --raster-on-accent: #ffffff;   /* text on the accent colour */
  --raster-font: "Inter", sans-serif;
  --raster-heading-font: "Fraunces", serif;
  --raster-bg: #ffffff;
  --raster-fg: #1a1a1a;
  --raster-surface: #f4f4f2;     /* panels and cards */
  --raster-radius: 10px;
}
```

## The editor's language

The editor's words are in English, and Raster ships them in 15 more languages: French, German, Spanish, Italian, Portuguese, Dutch, Romanian, Hungarian, Polish, Bulgarian, Greek, Turkish, Japanese, Hindi and Chinese (Simplified, `zh`). Apart from Romanian they were drafted by an AI agent, so corrections are welcome. The editor speaks the site's base language, the first in its list of languages, on every site, multilingual or not, and whatever the page's language is: editing `/ro/` on an English-first site keeps English buttons. A site with no languages set gets English. A word the language has no translation for stays in English. The browser's language, `?lang=` and the language cookie never change it, and there is no language menu in the dock.

The one exception is the sibip builder's preview: in development mode, the builder sends the person's own language in a `Raster-Editor-Language` header, and the editor uses that. Published sites run in production, where the header is ignored. To translate it into another language, or to change individual words, create `application/i18n/<lang>/raster_editor.php`:

```php
<?php
return array(
    'edit' => 'Bearbeiten',
    'done' => 'Fertig',
    'welcome' => 'Alles, was leuchtet, lässt sich ändern.',
);
```

Missing keys stay in English. The full list of keys is at the top of `system/models/cms/editor/editor.js` (and translated in `system/models/cms/editor/lang/ro.php`).

What the site refuses comes back as a name, and the editor shows the words for `problem_<name>`. Reword any of them the same way:

| Name | When |
|---|---|
| `type_int`, `type_number`, `type_bool`, `type_date`, `type_datetime`, `type_time`, `type_text` | a value that isn't of its field's type (`{field}` in the words is the field) |
| `upload_too_big`, `upload_missing`, `upload_not_a_picture`, `upload_not_stored` | a picture that is too big, didn't arrive whole, isn't a JPEG, PNG, GIF or WebP, or couldn't be saved |
| `form_expired`, `not_allowed` | the page is out of date (reload it), or you are no longer logged in as staff |
| `unknown_page`, `unknown_field`, `unknown_collection`, `no_such_item`, `no_such_revision`, `one_value` | what the editor posted no longer matches the site (reload the page); `{field}` is the field |
| `wrong_language` | the page is open in another language than its address says (`{lang}` is the address's) |
| `not_saved`, `broken` | the site refused the change for a reason of its own, shown after the words, or something went wrong that is in the error log |

```php
'problem_type_number' => '{field}: write a number, like 4.50',
'problem_upload_too_big' => 'Pictures up to 12 MB, please',
```

## How it works (for developers)

- For a logged-in editor, the CMS surrounds everything it prints with invisible markers such as `<!--raster:s 3-->…<!--raster:e 3-->` and adds a JSON description of them (`<script id="raster-editor-config">`) plus the editor script before `</body>`. Your page needs a `</body>` tag for this.
- The script saves through `POST /api/cms/editor_save_field`, `editor_save_item`, `editor_delete_item`, `editor_history`, `editor_restore` and `editor_upload`. Each needs a logged-in editor and the session's security token.
- These endpoints use the same code as the MCP server and the command line, so revisions, field checks and [events](Events) (`cms.page_saved`, `cms.item_saved`, `cms.item_deleted`) are the same whoever makes the change.
- Uploaded pictures are cropped and resized in the browser, then stored in `media/` with a new random name. Only JPEG, PNG, GIF and WebP up to 12 MB are accepted. They are stored as root-relative addresses (`/media/…`), so they keep working after a domain change or a [static export](Static-Export). Change the folder with `config::set('raster_media_folder')->to('uploads')`.
- The editor has no dependencies and loads nothing from other sites. Its controls live in a Shadow DOM, so your CSS can't break them and theirs can't break your page.
- Visitors get the plain page: no markers, no script, and no cookie.
