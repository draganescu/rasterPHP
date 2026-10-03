# Pagination

When a list is longer than one page, the bundled `pagination` model gives you the data for "newer / older" links and numbered page links. As always, you write the HTML.

## For a CMS collection

```html
<!-- render.cms.news('order=newest') -->
<article>…</article>
<!-- /render.cms.news('order=newest') -->

<!-- render.pagination.links('cms.news') -->
<nav class="pager">
  <!-- print.+class.prev_state --><!-- print.@href.prev_url --><a class="prev" href="#">&larr; Newer</a><!-- /print.@href.prev_url --><!-- /print.+class.prev_state -->

  <!-- render.pagination.pages('cms.news') -->
  <!-- print.+class.state --><!-- print.@href.url --><a class="page" href="#"><!-- print.number -->1<!-- /print.number --></a><!-- /print.@href.url --><!-- /print.+class.state -->
  <!-- /render.pagination.pages('cms.news') -->

  <!-- print.+class.next_state --><!-- print.@href.next_url --><a class="next" href="#">Older &rarr;</a><!-- /print.@href.next_url --><!-- /print.+class.next_state -->
</nav>
<!-- /render.pagination.links('cms.news') -->
```

**`pagination.links('cms.news')`** returns one row:

| Key | Value |
|---|---|
| `prev_url`, `next_url` | addresses of the previous and next page |
| `prev_state`, `next_state` | `disabled` on the first or last page, otherwise empty. Append it to `class` and style `.disabled`. |
| `current` | the current page number |
| `total` | the number of pages |

**`pagination.pages('cms.news')`** returns one row per page:

| Key | Value |
|---|---|
| `number` | the page number |
| `url` | its address |
| `state` | `current` for the page being shown |

Both return nothing when everything fits on one page, so the pager disappears.

Pages are addressed as `/news` (page 1), `/news/news_page/2`, `/news/news_page/3`… (the first part is the page showing the list). This doesn't work on the home page, where `/news_page/2` is a 404: give a paginated list its own page. The number of items per page comes from `news_page_size`, else `raster_page_size`, else 10 (see [The CMS](The-CMS#page-size)).

### Filtered lists

If the list uses filters, give pagination the same filters as a second argument so it counts the right items:

```html
<!-- render.cms.news('featured=1&order=newest') --> … <!-- /render.cms.news('featured=1&order=newest') -->
<!-- render.pagination.links('cms.news', 'featured=1') --> … <!-- /render.pagination.links('cms.news', 'featured=1') -->
```

Filters from the URL (`/news/news_items/tag/php`) are picked up automatically, and so is a filter that reads the query string: with `category=?category` in both, the page links keep `?category=…`.

## For your own model

Pagination can ask one of your models instead. Pass `'model.method'`. Raster calls the method with `true` as its only argument, and expects the total and the page size back:

```php
class guestbook {
    function entries($count = false) {
        $perpage = 20;
        $db = database::instance('guestbook');
        $total = (int)$db->entry_count()[0]['n'];
        if ($count === true) return array('total' => $total, 'perpage' => $perpage);

        $page = max(1, (int)util::get('page'));
        return $db->entries_page($perpage, ($page - 1) * $perpage);
    }
}
```

```sql
-- models/guestbook/sql/entry_count.sql
SELECT COUNT(*) AS n FROM entry
```

```sql
-- models/guestbook/sql/entries_page.sql
SELECT name, message FROM entry ORDER BY id DESC LIMIT ? OFFSET ?
```

```html
<!-- render.guestbook.entries --> … <!-- /render.guestbook.entries -->
<!-- render.pagination.pages('guestbook.entries') --> … <!-- /render.pagination.pages('guestbook.entries') -->
```

For your own models the pages are addressed with a query string, `?page=2`, which your method reads with `util::get('page')`.

Note that pages with a query string are not stored in the page cache and can't be part of a [static export](Static-Export).
