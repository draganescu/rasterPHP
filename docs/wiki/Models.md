# Models

A **model** is a plain PHP class that answers the questions a template asks. This page covers how to write one, what to return, the helpers you'll use most, and how a model offers methods as a JSON API.

## Where models live

```
application/models/<name>/<name>.php   containing   class <name>
```

For example `application/models/shop/shop.php`:

```php
<?php
class shop {
    function price() {                // <!-- print.shop.price -->
        return '€10';
    }

    function products() {             // <!-- render.shop.products -->
        return array(
            array('name' => 'Oak table', 'price' => '€420'),
            array('name' => 'Reading lamp', 'price' => '€65'),
        );
    }
}
```

Rules:

- The folder, the file and the class share one name, in lowercase letters, digits and `_`.
- There's no base class to extend and nothing to register. A model loads the first time something uses it.
- Raster makes **one instance per request** of each model a template uses, and calls the methods on it. So you can keep values in properties between calls in the same request.
- Methods a template calls must be **public**. Write them as ordinary (non-static) methods.

## What to return

| Used as | Return | Effect |
|---|---|---|
| `print` | a string (or number) | shown in place of the default |
| `print` | `false` or `null` | the template's default stays |
| `render` | an array of rows | the block repeats per row |
| `render` | `array()` | nothing shows |
| `render` | `false` | the template's sample content stays |
| `render` | a string | the whole block is replaced by it |

Returning `false` to keep the template's text is a key habit. It means "I have nothing to say here", and the designer's text shows instead of an empty gap.

**Escaping.** In HTML views, values are printed as they are. Anything that comes from a visitor must be escaped by you:

```php
function greeting() {
    return 'Hello, '.util::e(util::get('name')).'!';
}
```

`util::e()` is `htmlspecialchars` with safe defaults. Feeds and JSON views escape for you.

## Arguments from the template

```html
<!-- render.shop.latest(3, 'lamps') -->
```

```php
function latest($count, $category = 'all') { … }
```

Templates pass literal values only (numbers, strings, `true`, `false`, `null`). `lint` checks that the count matches your method's parameters, so you learn about a mismatch before a visitor does. See [Annotations](Annotations#arguments).

## Helpers you'll use

### Request data

| Call | Returns |
|---|---|
| `util::param('id')` | the URL segment after `id` in `/products/id/7`, or `false` |
| `util::param('id', 1)` | the same, with a default |
| `util::get('page')` | `$_GET['page']`, or `false` |
| `util::post('email')` | `$_POST['email']`, or `false` |
| `util::post_filter('name', 'email')` | only those keys of `$_POST`, as an array |
| `util::cookie('lang')` | `$_COOKIE['lang']`, or `false` |
| `util::no_post_data()`, `util::no_get_data()` | `true` when there's nothing posted or in the query string |
| `config::get('uri_string')` | the current path, e.g. `/team` |

### Responses

| Call | Does |
|---|---|
| `util::done('saved')` | redirects back to the same page with `?done=saved`, so reloading doesn't post twice, and shows the `alert('saved')` block. Stops the request. |
| `util::done('saved', '/thanks')` | the same, to another address |
| `util::redirect('account')` | redirects to a path on the site and stops |
| `util::content_changed()` | tells Raster that content changed, so the page cache is thrown away. Call it after your model writes data visitors see. Inside `cms_records::transaction()` it waits for the commit, and is dropped if the transaction is rolled back. |

### Settings and the template

| Call | Does |
|---|---|
| `config::get('name', $default)` | reads a setting; your own settings work too |
| `config::set('name')->to($value)` | changes a setting for this request |
| `config::get('link_uri')` | the site's base address, for building links (`https://example.com/`) |
| `config::get('environment')` | `development`, `production`, … |
| `template::set('flag')->to(true)` | sets a value for `print.if.flag` or `print.self.flag` |
| `template::instance()->form_state($data)` | fills the form in the current block with values (see [Forms and validation](Forms-and-Validation)) |

## Models calling models

Any model can use any other, by name. Raster loads the class when it's first mentioned:

```php
mail::send_view('_email/welcome', $email, array('name' => $name));
newsletter::subscribe($email, $name, '/signup');
$v = validation::get();
```

The bundled models offer static methods for this. For your own models, either write static helper methods or create an instance: `(new pricing())->for_product($id)`.

When one model should **react** to something another did (a booking should also subscribe the guest to the newsletter), don't call it directly: send an event. See [Events](Events).

## Helper classes

Big models can be split. A class named `<model>_<name>` is loaded from `models/<model>/<name>.php`:

```
models/shop/shop.php      class shop
models/shop/cart.php      class shop_cart
```

`shop_cart` loads the first time you use it, like a model.

## Data from the database

Models can use the database directly. See [The database](The-Database) for queries, SQL files and declaring the tables a model writes.

## Keeping sample content visible while you build

Because `false` keeps the template's text, you can annotate a page before the model exists in full:

```php
function testimonials() {
    return false; // TODO: load from the CRM; until then the mock-up shows
}
```

## Rendering into memory

Occasionally you want a block rendered but shown somewhere else, or post-processed. Return the rows with an extra `'__' => true` key: the block is rendered but not printed. Read the HTML later from the template:

```php
function remember() {
    return array('__' => true, array('note' => 'remembered'));
}

function recall() {
    $html = template::instance()->render_results['cafe']['remember'][0] ?? false;
    return $html === false ? false : trim(strip_tags($html));
}
```

Since render blocks run before print blocks, a `print` method can always read what a `render` block produced.

To render a **whole other view** into a string, use `controller::render_view('partials/card', array('title' => 'Hi'))`. Values are available in that view as `print.self.<name>`. The emails Raster sends are made this way.

## A model's JSON API

A model can offer some of its methods over HTTP, as JSON:

```
GET /api/<model>/<method>/<arg1>/<arg2>
```

It lists them in a static `api()` method, each with the least role that may call it:

```php
class shop {
    static function api() {
        return array(
            'latest'  => 'visitor',  // anyone
            'orders'  => 'editor',   // staff only
        );
    }
    function latest($limit = 3, $category = '') { … }
    function orders() { … }
}
```

`/api/shop/latest/3/lamps` then calls `shop::latest('3', 'lamps')` and returns the result as JSON. A method that returns `false` sends an empty response. The roles are `visitor`, `member`, `editor` and `admin`. Someone who isn't logged in gets `401` for a method that needs a role, and someone logged in without the role gets `403`.

**Anything a model doesn't list answers `404`**, even when it's public: the methods templates call, form handlers, event listeners. A model with no `api()` offers nothing. So a staff-only list that a template renders on a protected page stays on that page; if you also want it as JSON, list it for `editor`.

What is never reachable:

- methods that are static, not public, or whose name starts with `_`
- the bundled models, except `cms`, and of `cms` only what its own `api()` lists: the in-page editor's endpoints (which check for an editor and a security token), `style` and `logout`. A public method added in a `the_cms` override answers 404 unless the override lists it. The override's `api()` adds to what `cms` lists, so the in-page editor keeps working. `config::set('api_system_models')->to(array('cms', 'feed'))` lets another bundled model through, for what its `api()` lists.

Posts to `/api` pass the same cross-site check as forms. When the visitor is logged in, the post must also include the session token as a `csrf` field, as forms do; your page's scripts can read it from a hidden `csrf` input of any post form on the page. Posts to `/api` don't count as form submissions, though: `validation::get()->submitted()` is `false`, so form handlers written as shown in [Forms and validation](Forms-and-Validation) do nothing over `/api`.

A method you list for visitors can be called by anyone, so one that changes data still checks what it's given (the shop's payment webhook verifies the provider's signature, for example). `php bin/raster lint` reports an `api()` that names a method the model doesn't have, one `/api` can't call, or a role that doesn't exist. `php bin/raster vocabulary` shows what each model offers.

**When a method fails.** A method that throws answers `{"error":"server error"}` with status 500, and the error goes to PHP's error log with the URL, so a payment provider sees the failure and sends the webhook again. Anything the method printed before it threw is dropped. When the database can't be reached the call answers 503 before the method runs, so a webhook is never told "no such order" during an outage and the provider sends it again; a bad query on a database that is there is a 500 like any other error. A call without an argument the method needs gets 400. In development the answer also carries `exception` and `trace`, to find the mistake.

## Overriding a bundled model

To change how a bundled model (such as `feed` or `authentication`) behaves, extend it instead of editing `system/`. See [Extending Raster](Extending-Raster).
