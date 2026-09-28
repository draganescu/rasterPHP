# Translations

The bundled `i18n` model (short for "internationalisation") lets one set of templates serve several languages. The text written in your templates is the **default language**; other languages come from small PHP dictionary files.

## Setting it up

List the languages in `application/config/the_app.php`, default first:

```php
config::set('languages')->to(array('en', 'ro'));
```

Use two- or three-letter language codes, optionally with a region (`pt-br`).

## Marking text for translation

Wrap translatable text in `print.i18n.<file>('<key>')`:

```html
<h1><!-- print.i18n.home('welcome') -->Welcome<!-- /print.i18n.home('welcome') --></h1>
<a href="menu.html"><!-- print.i18n.common('menu') -->Our menu<!-- /print.i18n.common('menu') --></a>
```

- `home` is the dictionary file, `welcome` the key.
- In the default language, and whenever a translation is missing, the template's text shows.

## Dictionaries

Create one file per language and dictionary name, in `application/i18n/<language>/<file>.php`, returning an array of key => text:

```php
<?php // application/i18n/ro/home.php
return array(
    'welcome' => 'Bine ați venit',
);
```

```php
<?php // application/i18n/ro/common.php
return array(
    'menu' => 'Meniul nostru',
);
```

Values may contain HTML. The `i18n/` folder is never served over the web.

## How the language is chosen

For each request, the first of these that gives one of your languages wins:

1. `?lang=ro` in the address. The choice is remembered in a cookie.
2. The domain, if you map domains to languages:
   ```php
   config::set('domain_language')->to(array('example.ro' => 'ro'));
   ```
   Subdomains count too: `www.example.ro` is Romanian.
3. The cookie from an earlier `?lang=` choice. Its name is `lang`, or set your own with `config::set('language_cookie')->to('site_lang')`.
4. The browser's preferred languages (`Accept-Language` header).
5. The default language, the first in your list.

The cookie is the only cookie a visitor gets without logging in, and only after they pick a language.

## A language switcher and the `lang` attribute

```html
<html lang="<!-- print.i18n.language /-->">
```

```html
<ul class="languages">
  <!-- render.i18n.languages -->
  <li><!-- print.+class.state --><!-- print.@href.url --><a href="#"><!-- print.code -->en<!-- /print.code --></a><!-- /print.@href.url --><!-- /print.+class.state --></li>
  <!-- /render.i18n.languages -->
</ul>
```

`render.i18n.languages` returns one row per language with `code`, `url` (the current page with `?lang=…`) and `state` (`active` for the current one).

## CMS content and translations

The dictionaries translate the text in your **templates**. CMS page fields and collections store **one** value per field. For content editors write in several languages, the usual approach is separate fields or collections per language, chosen in the template, or a separate app per language (see [Pages and URLs](Pages-and-URLs#several-sites-in-one-install)).

## Other places languages matter

- The page cache keeps one copy per language.
- A [static export](Static-Export) writes each additional language into its own folder (`/ro/…`), and the switcher links there.
- The [in-page editor](The-In-Page-Editor#the-editors-language) uses the page's language for its own buttons.
- From a model, `i18n::detect()` returns the current language code.
