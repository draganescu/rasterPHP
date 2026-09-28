# How Raster works

Raster is built on three ideas. Once they are clear, the rest of the framework follows from them.

## 1. A URL picks an HTML file

There are no controllers to write and no route list to maintain. The address of a page is the path of its file:

| URL | File in `application/views/default/` |
|---|---|
| `/` | `index.html` |
| `/about` | `about.html` |
| `/docs/setup` | `docs/setup.html` |
| `/news.rss` | `news.rss` |

If the file exists, that's the page. If not, the visitor gets a 404. [Pages and URLs](Pages-and-URLs) covers the details and the exceptions.

## 2. The HTML pulls its data from PHP classes

In many frameworks, a controller fetches data and pushes it into a template. Raster turns this around: the template asks for what it needs, and a PHP class answers. The pattern is called **RTO**, for Request, Template, Object ([the original write-up](https://draganescu.github.io/rto/specs/2014/06/29/rto.html)).

The template asks with HTML comments, called **annotations**:

```html
<p>We have <!-- print.products.count -->12<!-- /print.products.count --> products.</p>
```

This means: "call the method `count()` of the class `products` and put what it returns here". The class is a plain PHP class in `application/models/products/products.php`. Raster calls these classes **models**:

```php
<?php
class products {
    function count() {
        return '42';
    }
}
```

The visitor sees *We have 42 products.* If `count()` returns `false`, the visitor sees the text that was already there, *12*.

There are two main kinds of annotation:

- **`print`** puts one piece of text in place of the placeholder.
- **`render`** repeats a piece of HTML once for every row a method returns, which is how you show lists.

```html
<ul>
  <!-- render.products.featured -->
  <li><!-- print.name -->Chair<!-- /print.name --></li>
  <!-- /render.products.featured -->
</ul>
```

```php
function featured() {
    return array(
        array('name' => 'Oak table'),
        array('name' => 'Reading lamp'),
    );
}
```

Inside a `render` block, `print.name` means "the `name` of the current row". The full list of annotations is in [Annotations](Annotations).

## 3. The template owns every word

Models decide **what** shows. They don't write the words. Error messages, success messages, email text, button labels: all of it lives in your HTML, so a designer or copywriter can change it without touching PHP.

A form error, for example, is written in the template and only shown when a rule is broken:

```html
<!-- render.validation.field('email') -->
<p class="error">Please enter a valid email address.</p>
<!-- /render.validation.field('email') -->
```

An email is also an HTML template, with its subject in `<title>`. The built-in account, newsletter and CMS features follow this rule too: they ship with no user-facing text of their own. You write every screen.

## Mock-ups stay mock-ups

Because annotations are comments, a browser ignores them. You can open `about.html` straight from disk and see a working static page with sample text. Raster also rewrites links such as `href="about.html"` to `/about` when it serves the page, so links work both ways.

For sample content that should never reach visitors, wrap it in a `remove` block:

```html
<!-- remove -->
<li>Another sample product so the mock-up looks full</li>
<!-- /remove -->
```

A good way to build a Raster page is to write it first as finished static HTML with realistic content, then add annotations to the parts that change.

## The markup is the schema

The built-in model called `cms` stores content in the database. When a template contains:

```html
<h1><!-- print.cms.headline -->Hello<!-- /print.cms.headline --></h1>
```

Raster learns that this page has an editable field called `headline` whose starting value is *Hello*. When it contains:

```html
<!-- render.cms.news -->
<h2><!-- print.title -->First post<!-- /print.title --></h2>
<!-- /render.cms.news -->
```

it learns that there is a list (a **collection**) called `news`, and each item has a `title`. The database tables and columns are created from this. There is no separate schema file, migration or admin configuration. See [The CMS](The-CMS).

## What happens during a request

You don't need this to build a site, but it helps when something surprises you.

1. `index.php` starts the framework and loads your settings.
2. Raster works out which environment it is in (development or production) and connects to that environment's database.
3. The URL is turned into a view file. If a cached copy of the page exists, it is sent right away.
4. Posted forms are checked: posts from other websites and obvious bots are refused.
5. The template is read. `dry` blocks pull in shared fragments from other files (such as the header and footer), and `remove` blocks are deleted.
6. Hidden security fields are added to every `<form method="post">`.
7. Every `render` block runs, **from the last one in the file to the first**. This means a block nested inside another block runs before the outer one.
8. Every `print` block runs.
9. Links are rewritten, alerts are shown or hidden, and the editor is added for logged-in editors.
10. The page is sent, and stored in the cache if it can be.

At each step Raster sends an **event** your own code can react to. See [Events](Events).

Step 7 is why a form works: the validation blocks are nested inside the form's `render` block, so they run first. By the time the form's model runs, it already knows whether the fields are valid.

## Next

[Tutorial: your first site](Tutorial-Your-First-Site) puts all of this together.
