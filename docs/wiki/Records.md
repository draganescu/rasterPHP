# Records

Most of what a site stores is **content**: pages, menu items, news. You declare it by writing a template, editors write it, and visitors read it. Some things aren't like that. A table booking, an order or a job application is sent by a visitor, belongs to them, has rules (no double bookings, no selling what's out of stock), and only staff should see it. Raster calls these **records**.

A model declares a type of record: what it holds and what makes it valid. The CMS does the rest. It stores records, lists them wherever a template renders them, and lets editors and agents work with them in the same [in-page editor](The-In-Page-Editor) and [MCP tools](Working-with-AI-Agents) as any other item.

## Declaring a type

```php
<?php // application/models/reservation/reservation.php
class reservation {
    static function types() {
        return array('reservation' => array(
            'fields'   => array('name' => '', 'email' => '', 'date' => '', 'guests' => 0, 'status' => 'new'),
            'create'   => 'visitor',          // anyone may book
            'readonly' => array('status'),    // staff see it, only the model changes it
            'actions'  => array('confirm' => 'editor', 'cancel' => 'editor'),
        ));
    }

    // every write passes here: the form, the editor, an agent, your own code
    static function check($type, $after, $before) {
        $problems = array();
        if ($after && $after['guests'] > 8) $problems[] = 'too_many';
        return $problems;
    }

    static function confirm($booking, $input) {
        return cms_records::update('reservation', $booking['id'], array('status' => 'confirmed'));
    }

    static function cancel($booking, $input) {
        return cms_records::update('reservation', $booking['id'], array('status' => 'cancelled'));
    }

    // the form: <!-- render.reservation.book -->
    function book() {
        return cms_records::submit('reservation', 'booked');
    }
}
```

What each part of the type means:

| Key | What it does |
|---|---|
| `fields` | What a record holds, with defaults. A default of `array()` makes a list (the lines of an order). |
| `create` | Who may send the form: `visitor`, `member` or `editor` (the default). |
| `staff_add` | `true` lets staff add records in the page too, though visitors make them with a form: a booking taken over the phone. |
| `owner` | `true` remembers the account that made each record, and lets that person read their own. |
| `public` | `true` shows records to everyone. By default only staff (and owners) see them. |
| `readonly` | Fields staff can see but not change. Only the model writes them. |
| `hidden` | Fields staff and agents never see, like a payment id. |
| `html` | Fields printed as HTML. Everything else prints as plain text, because visitors typed it. |
| `actions` | Buttons for staff, each with the least role that may press it. |

The hooks (`types`, `check`, the actions) are `static`. That keeps them away from `/api/<model>/<method>`, which never calls a static method, even one a model lists in `api()`.

## The form

```html
<!-- print.validation.alert('booked') --><p class="notice">Your table is booked.</p><!-- /print.validation.alert('booked') -->
<!-- print.validation.alert('too_many') --><p class="error">For more than 8 guests, please call us.</p><!-- /print.validation.alert('too_many') -->

<!-- render.reservation.book -->
<form method="post">
  <label>Name <input name="name" required></label>
  <label>Date <input type="date" name="date" required></label>
  <label>Guests <input type="number" name="guests" min="1" required></label>
  <button>Book</button>
</form>
<!-- /render.reservation.book -->
```

`cms_records::submit()` is the whole handler. It checks the HTML rules as any [form](Forms-and-Validation) does. It then keeps only the fields visitors may write, so a visitor can't set `status`. It runs `check()`, and each problem it names shows the alert with that name. When nothing is wrong, it stores the record and shows the `booked` alert.

## Showing records

Records show wherever a template renders them, like any collection:

```html
<!-- views/default/staff.html, protected for editors -->
<table><tbody>
<!-- render.cms.reservation('order=newest') -->
<tr><td><!-- print.name -->Ana<!-- /print.name --></td><td><!-- print.date -->2026-10-01<!-- /print.date --></td><td><!-- print.status -->new<!-- /print.status --></td></tr>
<!-- /render.cms.reservation('order=newest') -->
</tbody></table>
```

Visitors see nothing here, not even the example row. Staff see every booking, and in edit mode each one has **Confirm** and **Cancel** buttons. Name and date can be edited in place. Status can't, but it updates when a button changes it. With `'owner' => true`, the same block on `/account` shows a logged in visitor their own bookings.

Every form that saves data needs a page like this one. Without it, what visitors send is stored and nobody sees it (`raster lint` warns). Protect it for editors in the settings, `config::set('protected')->to(array('staff' => 'editor'))`. Staff find it in the in-page editor's **Admin** menu, so the site needs no link to it.

### Staff pages: filters, upcoming and past

Keep staff pages on `render.cms.<type>`. Only the rows the CMS lists can be edited in the page: rows a model builds itself (with `cms_records::find`) look the same, but staff can't edit them, press their buttons or add one, and `raster lint` warns when an admin page does that. What staff pages usually need is in the list options:

```html
<form method="get">
  <select name="stylist"><option value="">Anyone</option><option>Ana</option><option>Ioana</option></select>
  <input type="date" name="day">
  <button>Show</button> <a href="bookings.html">Show all</a>
</form>
<h2>Upcoming</h2>
<!-- render.cms.booking('date>=today&stylist=?stylist&date=?day&order=date,time') --> … <!-- /render.cms.booking('date>=today&stylist=?stylist&date=?day&order=date,time') -->
<h2>Past</h2>
<!-- render.cms.booking('date<today&stylist=?stylist&date=?day&order=-date,-time&limit=50') --> … <!-- /render.cms.booking('date<today&stylist=?stylist&date=?day&order=-date,-time&limit=50') -->
```

- `stylist=?stylist` filters by the form's choice, and shows everyone when nothing is chosen. The form keeps the choice.
- `date>=today` and `date<today` split upcoming from past when the page is shown. Don't store a field like `period` for this: it is wrong the next day.
- For tabs, make a page for each (`bookings.html` and `bookings/past.html`, both protected by the prefix `bookings`).
- `php bin/raster render /bookings --as=editor` shows the page as staff see it and says what the in-page editor can edit there.

With `'staff_add' => true`, each list a new booking would show in ends with a card for adding one; a list of `status=confirmed` bookings has none, when `status` is readonly and starts as `new`.

### Values a row shows but doesn't store

A booking's end time, or the stylist's photo from another type, come from a static hook on the model:

```php
static function computed($type, $booking) {
    return array('ends_at' => date('H:i', strtotime($booking['time']) + 7200));
}
```

`<!-- print.ends_at -->` then works in any list of bookings. The editor shows it and doesn't edit it, and a computed value never replaces a stored field.

### Views a model builds

Totals, an agenda grouped by day, a week grid, or records of several types on one list are more than list options say. Build them in a model, and hand the records back through `cms_records::listed()`, so staff still edit them in the page:

```php
function agenda() {                           // render.booking.agenda
    $days = array();
    foreach (cms_records::find('booking', array(), 'date,time') as $b) $days[$b['date']][] = $b;
    $rows = array();
    foreach ($days as $day => $bookings) {
        $rows[] = array('day' => $day, 'bookings' => cms_records::listed('booking', $bookings, array('date' => $day)));
    }
    return $rows;
}
```

```html
<!-- render.booking.agenda -->
<h3><!-- print.day -->2026-10-01<!-- /print.day --></h3>
<ul><!-- print.bookings --><li><!-- print.time -->10:00<!-- /print.time --> <!-- print.name -->Ana<!-- /print.name --></li><!-- /print.bookings --></ul>
<!-- /render.booking.agenda -->
```

`listed()` keeps only what the person may read, adds computed values, prints what visitors typed as text, and tells the in-page editor which record each row is. Staff edit the bookings in place, press their buttons, and each day ends with a card for a new booking on that day (with `staff_add`). Call it last, on what the template gets: the list carries a key of its own. Without it, `raster lint` warns on admin pages.

When even that doesn't fit, link each record to its own page with `<!-- print.@href.raster_detail_link -->` (`/booking/booking_item/<slug or id>`, shown by a view that renders `render.cms.booking`). Editors edit it there; visitors get a 404 for a private type.

So: list options first, then a model view with `listed()` or links to each record's page.

Records never appear in feeds, the sitemap or a [static export](Static-Export), unless the type is `public`.

## The rules hold everywhere

`check()` runs before every write, whoever makes it:

- a visitor's form, where problems show as alerts
- the in-page editor, where the change is refused with the problem named
- an agent over MCP, whose call returns an error naming the problem
- your own code: `cms_records::create()`, `update()` and `delete()`

This is why the rule belongs in the model and not in the form. A member of staff moving a booking to a full evening hits the same rule as a visitor booking it.

## Your own code

```php
cms_records::create('reservation', array('name' => 'Ana', 'date' => '2026-10-05', 'guests' => 2));
cms_records::update('reservation', 12, array('status' => 'confirmed'));
cms_records::get('reservation', 12);
cms_records::find('reservation', array('date' => '2026-10-05'));
cms_records::refuse('fully_booked');   // stop, with a named problem
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

If any write is refused (say `check()` finds stock would go below zero), none of them happen. Emails and other event listeners run only after the transaction commits.

## Starting from a form you already have

```sh
php bin/raster make model inquiry --from=contact.html
```

This writes `models/inquiry/inquiry.php`: the type with a field for each input of the form (passwords left out), an empty `check()` and the handler. The file is yours to change from then on.

## In production

`php bin/raster schema --apply` creates the records' table with every column the model declares. See [The database](The-Database).

A complete example built this way is the [example shop](https://github.com/draganescu/rasterPHP/tree/master/shop) in the Raster repository: public products, private orders, a checkout that takes stock in a transaction, and a payment provider's webhook.
