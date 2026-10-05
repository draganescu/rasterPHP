# The CMS

Raster's content management system stores the text, pictures and lists that editors change. It has no schema file and no admin configuration: it reads your templates and works out what content exists from the annotations. This page explains the two kinds of content (page fields and collections), their URLs, drafts and scheduling, and how to change fields safely later.

Editors change content in the page itself; see [The in-page editor](The-In-Page-Editor). AI agents can change it too; see [Working with AI agents](Working-with-AI-Agents).

## Page fields

A `print.cms.<name>` annotation declares a **page field**:

```html
<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>
<div class="intro"><!-- print.cms.intro --><p>Some <strong>rich</strong> text.</p><!-- /print.cms.intro --></div>
```

- The content between the tags is the field's **default**, and its starting value.
- Values are stored **per page URL**: `headline` on `/about` and `headline` on `/team` are separate fields.
- Values can contain HTML.
- An empty value shows the template's default again.
- Every save stores a new **revision** of the page, so nothing is lost. Two people saving different fields at the same moment both keep their change. The editor shows the history and can bring back an older version.

A field with no default (`<!-- print.cms.subtitle /-->`) works, but `lint` warns about it: an empty field is invisible in the editor, so give it some starting text.

### Fields in attributes and in `<head>`

Page fields can fill attributes, which is how you get editable images, links and meta tags:

```html
<!-- print.@src.cms.hero_photo --><img src="images/hero.jpg" alt=""><!-- /print.@src.cms.hero_photo -->
<!-- print.@href.cms.cta_link --><a class="button" href="contact.html">Get in touch</a><!-- /print.@href.cms.cta_link -->
<!-- print.@content.cms.description --><meta name="description" content="A short description of the page"><!-- /print.@content.cms.description -->
<title><!-- print.cms.title -->About us<!-- /print.cms.title --></title>
```

Until an editor uploads a picture, the template's picture shows. Fields the page can't show as editable (in `<head>`, inside attributes) are listed in the editor's **Page** panel.

### Site-wide fields

A field whose name starts with `site_` is shared by **every** page:

```html
<a class="brand" href="index.html"><!-- print.cms.site_name -->My Company<!-- /print.cms.site_name --></a>
<footer><!-- print.cms.site_footer -->© My Company<!-- /print.cms.site_footer --></footer>
```

Put these in `_layout.html` so they appear everywhere. Changing one changes it on every page.

## Collections

A `render.cms.<name>` block declares a **collection**: a list of items with the same fields, such as news posts, products, team members or FAQs. The fields are the `print` keys inside the block:

```html
<!-- render.cms.news -->
<article>
  <h2><!-- print.headline -->Our new office<!-- /print.headline --></h2>
  <p class="date"><!-- print.date -->2026-09-01<!-- /print.date --></p>
  <!-- print.@src.photo --><img src="images/news.jpg" alt=""><!-- /print.@src.photo -->
  <div><!-- print.summary --><p>We moved.</p><!-- /print.summary --></div>
</article>
<!-- /render.cms.news -->
```

- The first time the block renders, the collection gets **one item made from the template's sample content**, so the page never starts empty.
- A collection can appear on several pages (the home page shows three, the news page shows all). All the fields used anywhere belong to it.
- Adding a new `print.key` to the block adds a field. The most recent item gets the template's text as its starting value; older items start empty until someone fills them in.
- Items are **not** versioned the way pages are; each save replaces the item's values.

### Options: sorting, limiting, filtering

Pass options as one string, in the style of a URL query:

```html
<!-- render.cms.news('order=newest&limit=3') -->
<!-- render.cms.products('featured=1&order=name') -->
<!-- render.cms.events('date>=today&order=date,time') -->
<!-- render.cms.products('category=?category&order=-price') -->
```

| Option | Meaning |
|---|---|
| `order=newest` | newest first (by publish date, falling back to last update) |
| `order=oldest` | in the order they were added (the default), not by any date field |
| `order=<field>` | by that field, A to Z |
| `order=-<field>` | by that field, Z to A |
| `order=date,time` | by several fields, each may have its `-` |
| `limit=<n>` | at most n items (otherwise the page size, see below) |
| `<field>=<value>` | only items whose field equals the value |
| `<field>=?<name>` | equals the URL's `?<name>=`; left out when the URL has none or it is empty |
| `<field>>=<value>` | also `>`, `<`, `<=`, and `!=` (which keeps items where the field is empty) |

`today` is the date the page is shown: `date>=today` is what's coming, `date<today` what's past. Values compare as the field's type: a field whose mock-up is `2026-10-10` is a date, `14` an int, `4.50` a number, `19:00` a time, and anything else text. Whoever writes a value, it is stored as its type (`5 Oct 2026` becomes `2026-10-05`), or refused when it can't be one.

A filter on a field that doesn't exist yet **adds that field**. So `render.cms.products('featured=1')` gives products a `featured` field, which editors set to `1` on the items to show.

#### Filtering from a form

`field=?name` makes a plain search or filter form work with no model code:

```html
<form method="get">
  <select name="category"><option value="">All</option><option>Mugs</option><option>Bowls</option></select>
  <button>Show</button>
</form>
<!-- render.cms.products('category=?category&order=name') --> … <!-- /render.cms.products('category=?category&order=name') -->
```

`/shop?category=Mugs` shows the mugs, and the form shows Mugs chosen: forms with `method="get"` keep what the URL asked. A new item an editor adds to the filtered list starts as a mug. Pages with a query string are never cached.

Remember: the closing tag repeats the options exactly, `<!-- /render.cms.news('order=newest&limit=3') -->`.

## Collection URLs

Each collection gets URLs without any routing work:

| URL | Shows |
|---|---|
| `/news/news_item/our-new-office` | one item, by its slug |
| `/news/news_item/3` | one item, by its id |
| `/news/news_page/2` | page 2 of the list |
| `/news/news_items/tag/php` | only the items whose `tag` field is `php` |

**Item URLs** always start with the collection's own name: `/<collection>/<collection>_item/…`. `/news/news_item/…` uses `news_item.html` if it exists, otherwise `news.html`, and one of them must exist and contain `render.cms.news`. Either way, that block shows just the one item. An item that doesn't exist gives a **404**. So if your team list is on `people.html`, create `team_item.html` for the detail pages (or name the list page `team.html`).

**List URLs** (`_page` and `_items`) start with the page that shows the list: `/news/news_page/2` for `news.html`, `/archive/news_page/2` for `archive.html`. A collection listed on the home page is an exception: `/news_page/2` has no page to attach to and gives a 404, so put long, paginated lists on a page of their own.

**Page fields on these URLs.** List pages (`/news/news_page/2`, `/news/news_items/tag/php`) share their page fields with `/news`. All item pages of a collection share one set of page fields (for example a common sidebar text): their own when `news_item.html` exists, otherwise those of `/news`.

### Links to items: `raster_detail_link`

Each item has a ready-made link to its own page:

```html
<!-- print.@href.raster_detail_link --><a href="news/news_item/1"><!-- print.headline -->Title<!-- /print.headline --></a><!-- /print.@href.raster_detail_link -->
```

### Links to related items: `raster_filter`

`raster_filter@<field>` links to the list of items that share this item's value for that field:

```html
By <!-- print.@href.raster_filter@author --><a href="#"><!-- print.author -->Ada<!-- /print.author --></a><!-- /print.@href.raster_filter@author -->
```

For an item whose author is *Ada*, this links to `/news/news_items/author/Ada/`. Several fields work too: `raster_filter@author@year`.

### Page size

Lists show 10 items per page unless you say otherwise:

```php
config::set('raster_page_size')->to(12);   // every collection
config::set('news_page_size')->to(5);      // just news
```

A `limit` option in the template wins over both. To show page links, see [Pagination](Pagination).

### Relationships

Items relate **by value**: two news items with the same `author` text are "by the same author", and `raster_filter@author` lists them. There are no foreign keys. If you need real references between records (an order with many order lines), write a model with its own tables; see [The database](The-Database).

## Slugs

Every item gets a **slug**, a readable id for its URL, made from its `title`, `headline` or `name` field (or else its first text field). Accents are turned into plain letters: *Café crème* becomes `cafe-creme`. If the slug is taken, `-2`, `-3` and so on are added, even for items saved at the same moment. Editors can change it in the item's details.

## Drafts and scheduled items

Every item has two extra fields:

- `enabled`: `0` makes it a **draft** (the editor calls this *Hidden*).
- `published_at`: a date and time in the future **schedules** it.

Visitors don't see drafts or scheduled items, on lists, detail pages, feeds or sitemaps. Logged-in editors see everything, so they can check a post before it goes out. When a scheduled item's time comes, the page cache is thrown away so it shows up.

## Names you can use

- Field and collection names: lowercase letters, digits and `_`, starting with a letter.
- Reserved field names: `slug`, `id`, `updated_at`, `enabled`, `published_at`, and the names of the `cms` model's own methods (`style`, `login`, `logout`, `route`, `setup`, and others).
- Reserved collection names: `users`, `raster`.
- Words SQL keeps for itself (`when`, `from`, `group`, `order`) are fine as field names: lists sort and filter by them like any other field.

`lint` reports a reserved or invalid name with its file and line.

## Changing fields safely

The markup is the schema, so renaming an annotation means renaming a column. `php bin/raster schema` shows how your templates and the database compare:

```
page /about  (about.html)  table aboutpage, 3 row(s)
  ✓ title  "About"
  + subtitle  "Who we are"
  - heading (in database, not in any template)
    renamed? php bin/raster schema --rename=aboutpage.heading:subtitle
```

- `✓` the field exists in both
- `+` the template has it, the database doesn't yet
- `-` the database has it, no template uses it any more (an **orphan**)

When you rename an annotation, Raster notices the likely rename and suggests the command that **moves the content across**, so nothing editors wrote is lost:

| Command | Does |
|---|---|
| `php bin/raster schema` | shows the comparison |
| `php bin/raster schema --apply` | creates missing tables and columns (needed in production, where the database is frozen) |
| `php bin/raster schema --rename=aboutpage.heading:subtitle` | renames a column, keeping its content |
| `php bin/raster schema --drop=aboutpage.heading` | deletes a column no template uses |
| `php bin/raster schema --drop=olddata` | deletes a whole table no template uses |
| `--force` | together with `--drop`, deletes even if a template still uses it |
| `php bin/raster schema --check` | exits with code 1 when templates and database differ, for automated checks |
| `--json` | the same information as JSON |

`--drop` refuses to delete something a template still uses unless you add `--force`.

In production (frozen database), a field you added to a template shows its default until you run `schema --apply` there.

## Turning the CMS off

```php
config::set('cms_enabled')->to(false);
```

`print.cms` and `render.cms` then keep their template content, and the editor isn't offered.
