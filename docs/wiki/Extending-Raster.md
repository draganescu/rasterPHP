# Extending Raster

Sooner or later you'll want a bundled feature to behave a little differently. The rule is simple: **never edit the framework files** (`system/`, `bin/raster`, `index.php`, `.htaccess`, `AGENTS.md`). `raster update` replaces them, and refuses to run when it sees they were changed. Everything below lives in your app folder and survives updates.

## Change a bundled model: `the_<name>`

To change or add to a bundled model (`feed`, `authentication`, `newsletter`, `pagination`, `i18n`, `validation`, `cms`), create a class named `the_<name>` that extends it:

```php
<?php // application/models/the_feed/the_feed.php
class the_feed extends feed {
    // a new method templates can call as print.feed.generator
    function generator() {
        return 'My Site feeds';
    }

    // change an existing one
    function site_url() {
        return 'https://example.com/';
    }
}
```

Templates keep writing `feed.…`; your class answers. Events such as `executed_feed_items` keep the model's own name, and a `listens()` method in your class counts for `feed`.

**What an override reaches.** Your class is used wherever Raster creates the model's object: calls from templates, from `/api`, and event listeners. But the framework also calls many methods **statically** on the original class (`mail::send_view()`, `authentication::can()`, `newsletter::subscribe()`), and those calls don't go through your class. Overriding a static method, or overriding `mail` at all, therefore has no effect on the framework's own behaviour. For those, react to [events](Events) instead (`mail.sent`, `authentication.registered`, …).

## Replace a bundled model entirely

A model in `application/models/<name>/<name>.php` with the **same name** as a bundled model is used **instead of** it, completely. Prefer `the_<name>` unless you really mean to rewrite the whole thing.

## React instead of changing: events

Often you don't need to change a model at all, only to do something when it acts. Listen to its events: `authentication.registered`, `newsletter.subscribed`, `cms.item_saved`, `mail.failed` and many more. See [Events](Events).

For example, to stop a model from loading on certain pages, return `false` from a `loading_model_<name>` listener. To change the finished HTML of every page, listen to `before_output` and edit `template::instance()->output`.

## Change settings of the framework: `config/the_*.php`

The framework's own config files (`system/config/app.php`, `routes.php`, `events.php`) are each followed by yours: `config/the_app.php`, `config/the_routes.php`, `config/the_events.php`. Anything you set there wins.

To remove one of the framework's event bindings:

```php
// config/the_events.php
event::unbind('before_output')->from('cms', 'inject_toolbar');
```

## Change a core class: `the_<file>.php`

The core classes in `system/*.php` (`config`, `controller`, `template`, `database`, `event`, `log`, `util`) work the same way. A file named `application/the_<file>.php` is loaded right after `system/<file>.php`:

```php
<?php // application/the_template.php
class the_template extends template {
    // your changes
}
```

For the classes that exist as a single shared instance (`config`, `controller`, `template`, `database`, `event`, `log`), Raster then uses your `the_` class everywhere. Keep such changes small; they depend on internals that may change between versions.

## Your own validation rules

`application/models/validation/rules/<rule>.php` with a function `validate_<rule>($value, …)`. See [Forms and validation](Forms-and-Validation#your-own-rules).

## Other themes for some URLs

A route can render a view from another theme, for example a print version:

```php
controller::route('print/menu')->to('menu')->from('print');
```

## Translate or restyle the editor

See [The in-page editor](The-In-Page-Editor#matching-your-sites-look).

## Check what you've done

`php bin/raster doctor` reports edited framework files and tells you to move the change into your app.
