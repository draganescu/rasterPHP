# Annotations

Annotations are the HTML comments that make a template dynamic. This page lists every form, what it does with each kind of value, and the rules the engine follows.

`php bin/raster annotations` prints the same grammar from the framework itself, and `php bin/raster lint` checks your views against it.

## Spelling: the rule that trips everyone up

The engine finds annotations by exact text. Each one is written with **exactly one space after `<!--` and one space before `-->`** (or before `/-->` when it closes itself):

```html
<!-- print.cms.headline -->Hello<!-- /print.cms.headline -->   correct
<!-- print.newsletter.count /-->                              correct, self-closing
<!--print.cms.headline-->                                    ignored
<!--  print.cms.headline  -->                                ignored
```

A misspelled annotation isn't an error at runtime: it's just an HTML comment, so the placeholder text shows and nothing happens. `php bin/raster lint` reports these as errors, and `php bin/raster lint --fix` repairs the spacing for you.

## Names

Most annotations have the shape `keyword.model.method`:

- **keyword**: `print`, `render`, `remove`, `res` or `dry`
- **model**: the name of a PHP class in `models/<name>/<name>.php`, in lowercase letters, digits and `_`
- **method**: a public method of that class

`print.products.count` calls `products::count()` (on an instance of the class, not statically).

## The full list

| Annotation | What it does |
|---|---|
| `<!-- print.model.method -->default<!-- /print.model.method -->` | Replaced by the string the method returns. `false` or `null` keeps `default`. |
| `<!-- print.model.method /-->` | The same, with no default. |
| `<!-- print.@attr.model.method --><tag attr="…"><!-- /print.@attr.model.method -->` | Sets an attribute of the tag it wraps. |
| `<!-- print.+attr.model.method --><tag attr="…"><!-- /print.+attr.model.method -->` | Appends to an attribute instead. |
| `<!-- render.model.method --> … <!-- /render.model.method -->` | Repeats its content once per row the method returns. |
| `<!-- print.key -->` inside a render block | A value from the current row. |
| `<!-- print.if.flag --> … <!-- /print.if.flag -->` | Shown only when the flag is `true`. |
| `<!-- print.self.name /-->` | A value set on the template (mostly used in emails). |
| `<!-- print.session.key /-->` | A value from the visitor's session. |
| `<!-- remove --> … <!-- /remove -->` | Sample content, deleted before anything runs. |
| `<!-- res.name --> … <!-- /res.name -->` | A named, reusable fragment. |
| `<!-- dry.view.name /-->` | Inserts fragment `name` from another view. |

The rest of this page goes through them one by one.

## print

```html
<p>Price: <!-- print.shop.price -->€10<!-- /print.shop.price --></p>
<p>Subscribers: <!-- print.newsletter.count /--></p>
```

What the method returns decides what shows:

| Return value | Result |
|---|---|
| a string or number | replaces the block, default included |
| `false` or `null` | the default text stays |
| an array or object | an error: use `render` for lists |

In HTML views the value is printed **as it is, without escaping**. That's deliberate: CMS content is HTML. If a value comes from a visitor (a form, a URL), escape it in your model with `util::e($value)`. In `.rss`, `.xml`, `.atom` and `.json` views, values are escaped for that format automatically.

## Setting attributes: `@` and `+`

To put a value inside an attribute (an image's `src`, a link's `href`, a `class`), wrap the whole tag:

```html
<!-- print.@src.cms.photo --><img src="images/placeholder.jpg" alt=""><!-- /print.@src.cms.photo -->
<!-- print.@href.shop.checkout_url --><a href="#">Check out</a><!-- /print.@href.shop.checkout_url -->
<!-- print.+class.shop.badge --><span class="badge">New</span><!-- /print.+class.shop.badge -->
```

- `@attr` **replaces** the attribute's value. `+attr` **adds** the value after the existing one, separated by a space: `class="badge"` becomes `class="badge sale"`.
- The attribute must already be on the tag. `lint` reports it if it isn't.
- Outside render blocks, only the **first** tag inside the block is changed. Inside render blocks, every tag in the wrapped HTML that has that attribute is changed, so wrap just the one tag.
- Values are escaped for the attribute.
- `false`, `null` or an empty string keep the attribute as written in the template. This is what lets a new image field show the template's picture until an editor uploads one.

Inside a render block, the same syntax uses row keys: `print.@href.url`, `print.+class.state`. There, one thing differs: a **`false`** value removes the attribute, while `null` or an empty string keep it.

## render

```html
<ul>
  <!-- render.shop.products -->
  <li><!-- print.name -->Chair<!-- /print.name --> — <!-- print.price -->€50<!-- /print.price --></li>
  <!-- /render.shop.products -->
</ul>
```

```php
function products() {
    return array(
        array('name' => 'Oak table', 'price' => '€420'),
        array('name' => 'Reading lamp', 'price' => '€65'),
    );
}
```

| Return value | Result |
|---|---|
| a list of rows (arrays with named keys) | the content is repeated once per row |
| an empty array | nothing is shown |
| `false` | the content stays as written (the mock-up) |
| a string | the whole block is replaced by that string |

Rows can also be objects; they are turned into arrays.

**Inside the block:**

- `print.key` is replaced by the row's `key`. A key the row doesn't have keeps its sample text.
- A value of `false` for a key keeps its sample text.
- The same key can appear several times; every copy is filled.
- `print.model.method` (with a model name) can still be used inside a render block. It's called once, and every copy gets the same value.

**Lists inside rows.** If a row's value is itself a list of rows, the block for that key repeats once per inner row:

```php
return array(
    array('section' => 'Coffee', 'dishes' => array(array('dish' => 'Espresso'), array('dish' => 'Flat white'))),
    array('section' => 'Cakes',  'dishes' => array(array('dish' => 'Carrot cake'))),
);
```

```html
<!-- render.cafe.sections -->
<h3><!-- print.section -->Section<!-- /print.section --></h3>
<ul><!-- print.dishes --><li><!-- print.dish -->Dish<!-- /print.dish --></li><!-- /print.dishes --></ul>
<!-- /render.cafe.sections -->
```

**Keys Raster fills in.** For CMS collections, each row also has `raster_detail_link` (the item's own URL) and `raster_filter@<field>` links. See [The CMS](The-CMS).

## Arguments

Methods can take arguments, written in parentheses:

```html
<!-- render.news.latest(3, 'sports', true, -1, null) -->
```

Only **literal values** are allowed: whole or decimal numbers (negative too), strings in single or double quotes, `true`, `false` and `null`. No variables, no expressions. Nothing is ever evaluated as PHP.

Because arguments are literals, `lint` checks their count against the method's signature: *`cafe.category_count(category) needs 1 argument(s), 0 given`*.

## Closing tags carry the whole name

A closing tag repeats the opening name exactly, **arguments included**:

```html
<!-- render.cms.menu('order=name&limit=3') -->
…
<!-- /render.cms.menu('order=name&limit=3') -->
```

A short closing tag such as `<!-- /render -->` or `<!-- /render.cms.menu -->` doesn't close anything: the engine doesn't recognise it, so the block counts as never closed and the page fails (in development, an error list; in production, a plain error page). `lint` reports it and tells you the full tag; `lint --fix` writes it in. So it's fine to type the short form, as long as you run the fixer before loading the page.

Blocks must nest properly: close the inner block before the outer one.

## print.if: show or hide

```html
<!-- print.if.logged_in --><a href="account.html">Your account</a><!-- /print.if.logged_in -->
<!-- print.if.logged_out --><a href="login.html">Log in</a><!-- /print.if.logged_out -->
```

The content shows only if the flag is exactly `true`. Flags are set on the template, from any model:

```php
template::set('has_specials')->to(true);
```

A model that sets a flag must run before the `print` pass; any `render` method does (see [Evaluation order](#evaluation-order)).

Flags Raster sets for you:

| Flag | True when |
|---|---|
| `live` | the page is served by PHP (always, except in a static export) |
| `static` | the page is being written by `raster export` |
| `logged_in`, `logged_out` | the visitor is or isn't logged in |
| `is_member`, `is_editor`, `is_admin` | the visitor has at least that role |

There is no "else". Use two flags, or a flag and its opposite as above.

## print.self: values handed to a view

```html
<p>Hi <!-- print.self.name -->there<!-- /print.self.name -->,</p>
```

Prints a value set on the template. You'll mostly use this in emails, where `mail::send_view('_email/welcome', $to, array('name' => 'Ada'))` makes `name` available. On normal pages, set values with `template::set('name')->to('Ada')`. Values printed with `self` are HTML-escaped.

## print.session

```html
<!-- print.session.cart_count /-->
```

Prints `$_SESSION['cart_count']`, or nothing if it isn't set. This value is **not** escaped, so only put trusted values in the session. Visitors only have a session after logging in; see [Security](Security).

## remove: sample content

```html
<!-- render.cms.news -->
<article>…</article>
<!-- remove -->
<article>Second sample article, so the mock-up looks realistic</article>
<!-- /remove -->
<!-- /render.cms.news -->
```

Everything inside is deleted before any model runs, so annotations inside a `remove` block are never called. Remove blocks can't be nested inside each other.

## res and dry: shared fragments

Name a fragment in one file with `res`:

```html
<!-- in _layout.html -->
<!-- res.header -->
<header>…</header>
<!-- /res.header -->
```

Insert it in another with `dry.<view>.<fragment>`:

```html
<!-- dry._layout.header /-->
```

The view name can include folders: `dry.partials/nav.main`. Keep names simple: view names may only use lowercase letters, `_` and `/`, fragment names lowercase letters, `_` and `-`. Digits don't work in either. A `dry` block can also wrap placeholder content, which is replaced by the fragment:

```html
<!-- dry._layout.header --><header>Mock-up header</header><!-- /dry._layout.header -->
```

Things to know:

- Fragments are inserted **before** anything else happens, so they can contain any annotation.
- Insertion happens **once**: a `dry` inside an inserted fragment isn't expanded. Keep fragments one level deep.
- The `res` markers themselves are removed from the output, so a page can define fragments and still show them.
- `lint` reports a `dry` that names a missing file or fragment.

## Values inside JavaScript and CSS

An HTML comment inside `<script>` or `<style>` would break the code. There, write `/*-` and `-*/` instead of `<!--` and `-->`:

```html
<script>
  var accent = "/*- print.theme.accent /-*/";
</script>
```

This is the same as `<!-- print.theme.accent /-->`, but the file stays valid JavaScript or CSS for editors and mock-ups.

## Evaluation order

1. `dry` fragments are inserted, then `remove` blocks are deleted.
2. **Render blocks** run, from the **last** one in the file to the **first**. Blocks nested inside another render block therefore run before the block around them.
3. **Print blocks** run, after every render block, also last to first.

This order is what makes forms work: the `validation.field(…)` blocks inside a form run before the form's own model, so the model already knows the results. It also means a `render` method can set flags and values that `print` blocks use later.

## Text replacement

To replace a piece of text in every template under a path, call `replace` from a config file or a model:

```php
// in config/the_app.php: every {{brand}} on pages under /lab becomes "Raster Café"
template::instance()->replace('{{brand}}', 'Raster Café', '^/lab');
```

The third argument is a regular expression searched for **anywhere** in the URL path (`lab` also matches `/collab`; use `^/lab` for "paths starting with /lab"). Leave it out to apply the replacement everywhere.
