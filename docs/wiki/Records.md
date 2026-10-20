# Records

Most of what a site stores is **content**: pages, menu items, news. You declare it by writing a template, editors write it, and visitors read it. Some things aren't like that. A catering request, an order or a job application is sent by a visitor, belongs to them, has rules (no catering for two, no selling what's out of stock), and only staff should see it. Raster calls these **records**.

> **Taking bookings?** Table bookings and appointments are records too, and Raster ships them ready: the bundled booking model has the type, the slots, the capacity rules, the guest's emails and link, and a staff page made of the lists below. See [Bookings](Bookings). Replies to your events (yes, maybe, no, with places and a waitlist) are ready too: see [Event replies (RSVP)](RSVP). The example on this page is a catering request, a type of your own.

A model declares a type of record: what it holds and what makes it valid. The CMS does the rest. It stores records, lists them wherever a template renders them, and lets editors and agents work with them in the same [in-page editor](The-In-Page-Editor) and [MCP tools](Working-with-AI-Agents) as any other item.

## Declaring a type

```php
<?php // application/models/inquiry/inquiry.php
class inquiry {
    static function types() {
        return array('inquiry' => array(
            'fields'   => array('name' => '', 'email' => '', 'date' => '', 'people' => 0, 'message' => '', 'status' => 'new'),
            'types'    => array('date' => 'date'),
            'create'   => 'visitor',          // anyone may ask
            'readonly' => array('status'),    // staff see it, only the model changes it
            'actions'  => array('answer' => 'editor', 'archive' => 'editor'),
        ));
    }

    // every write passes here: the form, the editor, an agent, your own code
    static function check($type, $after, $before) {
        $problems = array();
        if ($after && $after['people'] < 10) $problems[] = 'too_few';
        return $problems;
    }

    static function answer($inquiry, $input) {
        return cms_records::update('inquiry', $inquiry['id'], array('status' => 'answered'));
    }

    static function archive($inquiry, $input) {
        return cms_records::update('inquiry', $inquiry['id'], array('status' => 'archived'));
    }

    // the form: <!-- render.inquiry.form -->
    function form() {
        return cms_records::submit('inquiry', 'sent');
    }
}
```

What each part of the type means:

| Key | What it does |
|---|---|
| `fields` | What a record holds, with defaults. A default of `array()` makes a list (the lines of an order). The default's PHP type is the field's: `0` an int, `0.0` a number, `false` a bool, `''` text. |
| `types` | Types the defaults can't say: `array('date' => 'date', 'starts' => 'time')`. Also `datetime`, or any type to override a default's. |
| `create` | Who may send the form: `visitor`, `member` or `editor` (the default). |
| `staff_add` | `true` lets staff add records in the page too, though visitors make them with a form: a request taken over the phone. |
| `owner` | `true` remembers the account that made each record, and lets that person read their own. |
| `public` | `true` shows records to everyone. By default only staff (and owners) see them. |
| `readonly` | Fields staff can see but not change. Only the model writes them. |
| `hidden` | Fields staff and agents never see, like a payment id. |
| `html` | Fields printed as HTML. Everything else prints as plain text, because visitors typed it. |
| `actions` | Buttons for staff, each with the least role that may press it. |

The hooks (`types`, `check`, the actions) are `static`. That keeps them away from `/api/<model>/<method>`, which never calls a static method, even one a model lists in `api()`.

## The form

```html
<!-- print.validation.alert('sent') --><p class="notice">Thank you, we'll write back with a menu.</p><!-- /print.validation.alert('sent') -->
<!-- print.validation.alert('too_few') --><p class="error">We cater for ten people or more: for fewer, book a table.</p><!-- /print.validation.alert('too_few') -->

<!-- render.inquiry.form -->
<form method="post">
  <label>Name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Date <input type="date" name="date" required></label>
  <label>People <input type="number" name="people" min="1" required></label>
  <label>What's the occasion? <textarea name="message"></textarea></label>
  <button>Ask</button>
</form>
<!-- /render.inquiry.form -->
```

`cms_records::submit()` is the whole handler. It checks the HTML rules as any [form](Forms-and-Validation) does. It then keeps only the fields visitors may write, so a visitor can't set `status`. It runs `check()`, and each problem it names shows the alert with that name. When nothing is wrong, it stores the record and shows the `sent` alert.

## Showing records

Records show wherever a template renders them, like any collection:

```html
<!-- views/default/staff.html, protected for editors -->
<table><tbody>
<!-- render.cms.inquiry('order=newest') -->
<tr><td><!-- print.name -->Ana<!-- /print.name --></td><td><!-- print.date -->2026-10-01<!-- /print.date --></td><td><!-- print.status -->new<!-- /print.status --></td></tr>
<!-- /render.cms.inquiry('order=newest') -->
</tbody></table>
```

Visitors see nothing here, not even the example row. Staff see every request, and in edit mode each one has **Answer** and **Archive** buttons. Name and date can be edited in place. Status can't, but it updates when a button changes it. With `'owner' => true`, the same block on `/account` shows a logged in visitor their own requests. A list with `owner=me` shows each person only their own records, editors included, even on a public type.

Every form that saves data needs a page like this one. Without it, what visitors send is stored and nobody sees it (`raster lint` warns). Protect it for editors in the settings, `config::set('protected')->to(array('staff' => 'editor'))`. Staff find it in the in-page editor's **Admin** menu, so the site needs no link to it.

### Staff pages: filters, upcoming and past

Keep staff pages on `render.cms.<type>`. Only the rows the CMS lists can be edited in the page: rows a model builds itself (with `cms_records::find`) look the same, but staff can't edit them, press their buttons or add one, and `raster lint` warns about it. What staff pages usually need is in the list options:

```html
<form method="get">
  <select name="status"><option value="">Any</option><option>new</option><option>answered</option></select>
  <input type="date" name="day">
  <button>Show</button> <a href="inquiries.html">Show all</a>
</form>
<h2>Upcoming</h2>
<!-- render.cms.inquiry('date>=today&status=?status&date=?day&order=date') --> … <!-- /render.cms.inquiry('date>=today&status=?status&date=?day&order=date') -->
<h2>Past</h2>
<!-- render.cms.inquiry('date<today&status=?status&date=?day&order=-date&limit=50') --> … <!-- /render.cms.inquiry('date<today&status=?status&date=?day&order=-date&limit=50') -->
```

- `status=?status` filters by the form's choice, and shows everything when nothing is chosen. The form keeps the choice.
- `date>=today` and `date<today` split upcoming from past when the page is shown. Don't store a field like `period` for this: it is wrong the next day.
- For tabs, make a page for each (`inquiries.html` and `inquiries/past.html`, both protected by the prefix `inquiries`).
- `php bin/raster render /inquiries --as=editor` shows the page as staff see it and says what the in-page editor can edit there.

With `'staff_add' => true`, staff also get the card for a new request at the end of each list.

### Views a model builds

Totals, an agenda grouped by day, values worked out per row, or records of several types on one list are more than list options say. Build them in a model, and hand the records to the template through `cms_records::listed()`, so staff still edit them in the page:

```php
function by_day() {                           // render.inquiry.by_day
    $days = array();
    foreach (cms_records::find('inquiry', 'date>=today&status!=archived&order=date') as $i) $days[$i['date']][] = $i;
    $rows = array();
    foreach ($days as $day => $inquiries) $rows[] = array('day' => $day, 'inquiries' => cms_records::listed('inquiry', $inquiries));
    return $rows;
}
```

```html
<!-- render.inquiry.by_day -->
<h3><!-- print.day -->2026-10-01<!-- /print.day --></h3>
<ul><!-- print.inquiries --><li><!-- print.name -->Ana<!-- /print.name --></li><!-- /print.inquiries --></ul>
<!-- /render.inquiry.by_day -->
```

`listed()` prints what visitors typed as text and marks each row as its record: staff edit the requests in place and press their buttons. The model decides which records show, and values it adds to a row print without being editable. New requests are added from a `render.cms` list or a request's own page. The booking model's agenda works this way. Without `listed()`, a `false` bool prints the mock-up and what visitors typed prints as HTML, and `raster lint` warns on any page that shows such rows (on admin pages, also that the editor can't edit them).

When even that doesn't fit, link each record to its own page with `<!-- print.@href.raster_detail_link -->` (`/inquiry/inquiry_item/<slug>`, shown by a view that renders `render.cms.inquiry`). Editors edit it there; visitors get a 404 for a private type.

So: list options first, then a model view with `listed()` or links to each record's page, and ask only when none of these work.

Records never appear in feeds, the sitemap or a [static export](Static-Export), unless the type is `public`.

## The rules hold everywhere

`check()` runs before every write, whoever makes it:

- a visitor's form, where problems show as alerts
- the in-page editor, where the change is refused with the problem named
- an agent over MCP, whose call returns an error naming the problem
- your own code: `cms_records::create()`, `update()` and `delete()`

This is why the rule belongs in the model and not in the form. A member of staff changing a request to six people hits the same rule as a visitor sending it.

## Your own code

```php
cms_records::create('inquiry', array('name' => 'Ana', 'date' => '2026-10-05', 'people' => 12));
cms_records::update('inquiry', 12, array('status' => 'answered'));
cms_records::get('inquiry', 12);
cms_records::find('inquiry', array('date' => '2026-10-05'));
cms_records::find('inquiry', 'date>=today&status!=archived&order=date');  // list options
cms_records::find('inquiry', array(), 'newest', 10);  // a limit; without one, every match
cms_records::find('inquiry', 'owner=me');  // the logged in person's, none when logged out
// values only: a ?status is the URL's, for templates, and find() throws on one
cms_records::refuse('too_few');   // stop, with a named problem
```

To make several writes happen together or not at all, put them in a transaction:

```php
cms_records::transaction(function () use ($cart) {
    foreach ($cart as $line) {
        $product = cms_records::get('product', $line['id']);
        cms_records::update('product', $line['id'], array('stock' => $product['stock'] - $line['qty']));
    }
    cms_records::create('order', array('lines' => $cart, 'status' => 'new'));
});
```

If any write is refused (say `check()` finds stock would go below zero), none of them happen. The database is locked for writing from the start, on SQLite and MySQL alike, so two checkouts at once can't both take the last item; a write that waits more than 5 seconds for the lock fails with "database is locked". Emails and other event listeners run only after the transaction commits. The page cache is thrown away, and `content_changed` sent, once after the commit too, so a page rendered while the transaction runs is never kept as fresh with the old rows, and nothing hears about a write that is rolled back. On MySQL, a write that adds a table or column (in development) commits what came before it, as MySQL does on any schema change.

The CMS saves its own content the same way: every save or delete of an item and every page save is a transaction of its own (or joins yours). This means two items saved at once with one title get different slugs, and two editors saving different fields of one page at once both keep their change. A newsletter sign-up is one too: one address sent twice at once is stored once, and its confirmation email waits for the commit.

## Starting from a form you already have

```sh
php bin/raster make model inquiry --from=contact.html
```

This writes `models/inquiry/inquiry.php`: the type with a field for each input of the form (passwords left out), an empty `check()` and the handler. The file is yours to change from then on.

## In production

`php bin/raster schema --apply` creates the records' table with every column the model declares. See [The database](The-Database).

A complete example built this way is the [example shop](https://github.com/draganescu/rasterPHP/tree/master/shop) in the Raster repository: public products, private orders, a checkout that takes stock in a transaction, and a payment provider's webhook.
