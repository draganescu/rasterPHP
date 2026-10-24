# Translations

The bundled `i18n` model (short for "internationalisation") lets one set of templates serve several languages. The text written in your templates is the **default language**. There are two kinds of words on a page, and each gets translated its own way:

- **Words editors change** (headings, page text, the site's name) are CMS fields. On a multilingual site each field that holds words has a value per language, typed in the in-page editor or by an agent.
- **Fixed words** (a button, a form's labels, the footer) live in small PHP dictionary files, one per language.

## Setting it up

List the languages in `application/config/the_app.php`, default first, and turn multilingual on:

```php
config::set('languages')->to(array('en', 'ro'));
config::set('multilingual')->to(true);
```

Use two- or three-letter language codes, optionally with a region (`pt-br`). Multilingual needs two or more languages; with fewer, `php bin/raster doctor` fails and the site behaves as if it were off.

A site with `languages` but without `multilingual` still works the older way, where only the templates' words are translated (see [Template-only translation](#template-only-translation) below). That way is deprecated and goes away in 3.0.0; `doctor` suggests the switch.

## Each language has its own address

The default language has no prefix (`/menu`); every other language lives under its code (`/ro/menu`), and a language's home page is `/ro/`. The address alone says the language, so one address is always one language: the page cache, search engines and shared links all agree. A domain listed in `domain_language` is that language with no prefix:

```php
config::set('domain_language')->to(array('example.ro' => 'ro'));
```

Links keep the language by themselves: on a `/ro/` page, `href="menu.html"` and `href="/menu"` both become `/ro/menu`. Links to files (`/menu.pdf`, pictures, styles) are left alone. Raster also adds the `<link rel="canonical">` and `hreflang` tags to each page's `<head>`, and answers with a `Content-Language` header. [AGENTS.md](https://github.com/draganescu/rasterPHP/blob/master/AGENTS.md) ("Multilingual URLs") has every rule.

## Fixed words: dictionaries

Wrap the text in `print.i18n.<file>('<key>')`:

```html
<h1><!-- print.i18n.home('welcome') -->Welcome<!-- /print.i18n.home('welcome') --></h1>
<a href="menu.html"><!-- print.i18n.common('menu') -->Our menu<!-- /print.i18n.common('menu') --></a>
```

- `home` is the dictionary file, `welcome` the key.
- In the default language, and whenever a translation is missing, the template's text shows.

Create one file per language and dictionary name, in `application/i18n/<language>/<file>.php`, returning an array of key => text:

```php
<?php // application/i18n/ro/home.php
return array(
    'welcome' => 'Bine ați venit',
);
```

Values may contain HTML. The `i18n/` folder is never served over the web.

## Words editors change: CMS fields in every language

Nothing to set up per field: Raster reads from the markup which fields hold words.

- A field printed as text, or into `alt`, `title`, `placeholder`, `aria-label` or `content`, is **translated**: one value per language.
- Everything else is **shared**, one value for every language: photos (`print.@src`), links (`print.@href`), classes, numbers and dates. A picture or a price is set once.

On `/ro/about`, the in-page editor edits the Romanian. A field nobody has translated yet shows the default language's words, so a new language starts complete and fills in as it is translated. A shared field is changed on the default language's page. The editor's own buttons follow the person, not the page: pick their language in the editor's dock.

This covers page fields and the `site_` fields every page shares. Collection items (news posts, menu dishes) keep one value for every language for now.

Behind the scenes a translated field `headline` keeps its Romanian in a column `headline__ro` (`pt-br` gives `headline__pt_br`). That's why field and collection names may not contain two underscores in a row. In development the columns appear on their own; on a production server, run `php bin/raster schema --apply` after adding a language. Turning multilingual off keeps every translation, ready for when it's turned on again.

## Keep words out of models

A model's PHP can't be translated, so a model returns data and the template has the words. The starter's menu is an example: `site::nav()` returns each link's address and whether it is the current page, and `_layout.html` says "Home", "News" and "About". When a model needs to say one thing or another, it sets a flag and the template words each case with `print.if`, as the shop's order emails do for "paid by card" and "to be paid on delivery".

With multilingual on, `php bin/raster lint` warns about words in the markup that neither a CMS field nor a dictionary key holds, about dictionary keys missing in a language, and about an `<html>` tag whose `lang` is fixed.

## A language switcher and the `lang` attribute

```html
<!-- print.@lang.i18n.language --><!-- print.@dir.i18n.dir --><html lang="en" dir="ltr"><!-- /print.@dir.i18n.dir --><!-- /print.@lang.i18n.language -->
```

The mock-up still says `lang="en"`, and the page says the language it is shown in. `print.i18n.dir` is `rtl` for languages written right to left (Arabic, Hebrew, Persian, Urdu, …) and `ltr` for the rest. Every page of the starter, the demo and the shop starts this way. Emails keep `lang="en"` as written for now.

```html
<!-- print.if.languages -->
<ul class="languages">
  <!-- render.i18n.languages -->
  <li><!-- print.+class.state --><!-- print.@href.url --><a href="index.html"><!-- print.code -->en<!-- /print.code --></a><!-- /print.@href.url --><!-- /print.+class.state --></li>
  <!-- /render.i18n.languages -->
</ul>
<!-- /print.if.languages -->
```

`render.i18n.languages` returns one row per language with `code`, `url` (the current page in that language: `/ro/menu` on a multilingual site, else the page with `?lang=…`), `state` (`active` for the current one) and `current` (true for the current one). The flag `if.languages` is true when the site has more than one language, so the list shows only then; the starter's `_layout.html` has the switcher this way.

## With an AI agent

Over [MCP](Working-with-AI-Agents), an agent can do the whole job: `set_languages` turns languages on (and adds the columns), `translation_status` lists what a language still lacks (page fields, items, dictionary words), `update_page` with a `lang` writes a page's translation and `update_item` with a `lang` an item's, `read_strings` and `write_strings` handle the dictionaries, and `render_url` with `/ro/…` shows the result. The tools that change settings or files refuse on a production server.

## From a model

Read the language through the `i18n` calls, never `config::get('languages')`: `i18n::current()` is this request's language, `i18n::default_language()` the first, `i18n::enabled()` whether the site is multilingual. AGENTS.md ("i18n") lists them all.

## Template-only translation

Without `multilingual`, only the templates' words are translated (dictionaries), CMS fields keep one value, and every language shares the same addresses. For each request, the first of these that gives one of your languages wins:

1. `?lang=ro` in the address. The choice is remembered in a cookie.
2. The domain, if you map domains to languages with `domain_language`. Subdomains count too: `www.example.ro` is Romanian. With the site's address set (`RASTER_URL`), the domains listed here are the site's own too: a page on `example.ro` keeps its language, its links stay on `example.ro`, and its forms are accepted. Only the domain exactly as listed counts for that, so list `www.example.ro` as well if visitors use it.
3. The cookie from an earlier `?lang=` choice. Its name is `lang`, or set your own with `config::set('language_cookie')->to('site_lang')`.
4. The browser's preferred languages (`Accept-Language` header).
5. The default language, the first in your list.

The cookie is the only cookie a visitor gets without logging in, and only after they pick a language. A multilingual site sets no such cookie: the address decides.

A site with a single language (`array('ro')`) is fine this way: its pages say `lang="ro"`, dates are read day first, and the editor speaks Romanian.

## Other places languages matter

- The page cache keeps one copy per address, so one per language.
- A [static export](Static-Export) writes each additional language into its own folder (`/ro/…`), and the switcher links there.
- The [in-page editor](The-In-Page-Editor#the-editors-language) has its own words in several languages.
