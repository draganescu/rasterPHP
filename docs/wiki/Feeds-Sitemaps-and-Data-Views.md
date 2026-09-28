# Feeds, sitemaps and data views

A view doesn't have to be HTML. Name it with another extension and Raster serves it in that format: an RSS feed for your news, a sitemap for search engines, a JSON file for a script, a plain text file. You write these the same way as pages, with the same annotations.

## Formats

| Extension | Served as | Values are escaped as |
|---|---|---|
| `.rss` | `application/rss+xml` | XML |
| `.atom`, `.xml` | `application/xml` | XML |
| `.json` | `application/json` | JSON string content |
| `.txt` | `text/plain` | not escaped |

The file `news.rss` is served at `/news.rss`, `sitemap.xml` at `/sitemap.xml`. Links to them written as `href="news.rss"` are rewritten like page links.

Escaping means a headline such as *Fish & Chips "special"* can't break your XML or JSON. It happens automatically for printed values in these formats (unlike HTML views, where values are printed as they are).

## An RSS feed

The bundled `feed` model supplies the data:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>News</title>
    <link><!-- print.feed.site_url /--></link>
    <description>News from our site</description>
    <!-- render.feed.items('news') -->
    <item>
      <title><!-- print.headline -->Headline<!-- /print.headline --></title>
      <link><!-- print.url /--></link>
      <guid><!-- print.url /--></guid>
      <pubDate><!-- print.date_rfc822 /--></pubDate>
      <description><!-- print.summary -->Summary<!-- /print.summary --></description>
    </item>
    <!-- /render.feed.items('news') -->
  </channel>
</rss>
```

`render.feed.items('news')` returns the newest **published** items of the `news` collection (no drafts, nothing scheduled for later), with every field of the item plus:

| Key | Value |
|---|---|
| `url` | the item's full address |
| `date_rfc822` | its date in the format RSS wants (`Mon, 28 Sep 2026 10:00:00 +0000`) |
| `date_iso` | its date in ISO 8601, for Atom and JSON Feed |

The date is the item's publish date, or its last update. The number of items is 20, or `config::set('feed_limit')->to(10)`, or a second argument: `feed.items('news', 5)`.

`print.feed.site_url` is the site's home address.

Tell browsers and feed readers about the feed in your `<head>`:

```html
<link rel="alternate" type="application/rss+xml" title="News" href="news.rss">
```

## A sitemap

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <!-- render.feed.pages -->
  <url><loc><!-- print.url /--></loc><lastmod><!-- print.updated /--></lastmod></url>
  <!-- /render.feed.pages -->
</urlset>
```

`render.feed.pages` lists every page of the theme and every published item of every collection that has an item view (`<name>_item.html`), each with `url` and `updated` (a date).

Left out automatically: partials and emails (`_…`), protected pages, and the account and newsletter pages. Change that list with:

```php
config::set('sitemap_skip')->to(array('login', 'account', 'register', 'forgot', 'reset', 'thanks', '404'));
```

## JSON

In a `.json` view, the rows of a render block are separated by commas, so a block placed inside `[ … ]` makes a valid JSON list:

```json
{
  "title": "Our journal",
  "items": [
    <!-- render.feed.items('journal') -->
    {"url": "<!-- print.url /-->", "title": "<!-- print.title -->Title<!-- /print.title -->", "date": "<!-- print.date_iso /-->"}
    <!-- /render.feed.items('journal') -->
  ]
}
```

Printed values are escaped as the inside of a JSON string, so always put them between quotes. This is how the demo café builds a [JSON Feed](https://jsonfeed.org).

If you just need a model's data as JSON, you may not need a view at all: every model method is already available at `/api/<model>/<method>`; see [Models](Models#every-model-is-also-a-json-api).

## Plain text

In a `.txt` view each rendered row becomes one line:

```
Opening hours
<!-- render.cafe.hours -->
<!-- print.day -->Day<!-- /print.day -->: <!-- print.time -->time<!-- /print.time -->
<!-- /render.cafe.hours -->
```

Useful for `robots.txt`-style files, `humans.txt`, or simple machine-readable lists.

## Your own data

Any model works in these views, not just `feed`. A method that returns rows can fill a feed of products, events or anything else. Remember that in feeds and JSON your values are escaped for you, so return plain text rather than HTML-escaped text.
