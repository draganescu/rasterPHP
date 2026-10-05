# Events

Events let one model react to something another model did, without either knowing about the other, and without the template doing the wiring. They also let you hook into each step of a request.

## Why events

Say your booking form should also add the guest to the newsletter when they tick a box, and a new account should get a welcome email. You could call `newsletter::subscribe()` from the booking model, but then the booking model has to know about newsletters, and every new side effect means editing it.

With events, the booking model only announces what happened. Any model that cares listens.

## Sending an event

```php
event::dispatch('reservation.booked', array(
    'name' => $name,
    'email' => $email,
    'newsletter' => true,
));
```

- The first argument is the event's name. Use `<model>.<what happened>`, in the past tense: `reservation.booked`, `order.paid`.
- The second is the **payload**: an array with the details listeners need.
- `dispatch()` returns `false` if any listener returned `false`, and `true` otherwise. What that means is up to the sender.

## Listening

Declare what a model listens to with a static `listens()` method, next to the code that handles it:

```php
class cafe {
    static function listens() {
        return array(
            'reservation.booked' => 'subscribe_guest',
            'authentication.registered' => 'welcome',
            'cms.item_saved' => array('log_change', 'notify_team'),   // several methods
        );
    }

    function subscribe_guest($booking) {
        if ($booking['newsletter']) newsletter::subscribe($booking['email'], $booking['name'], '/visit');
    }

    function welcome($user) {
        mail::send_view('_email/welcome', $user['email'], array('name' => $user['name']));
    }
}
```

Each listener method gets the payload array.

You can also bind listeners in `application/config/the_events.php`. In the starter site this file is empty; like any PHP file it must start with `<?php`, or its contents are ignored (and printed at the top of every page):

```php
<?php
event::bind('reservation.booked')->to('cafe', 'subscribe_guest');
event::unbind('reservation.booked')->from('cafe', 'subscribe_guest');   // remove a binding
```

**Order.** Listeners run in this order:

1. the framework's own request bindings from `system/config/events.php` (for example the API, MCP and CMS routing on `finding_route`, the CMS and account checks on `route_set`, the editor and alerts on `before_output`)
2. your bindings in `the_events.php`
3. `listens()` declarations
4. the framework's core handlers (the controller and the log)

So a `route_set` listener of yours runs after protected pages have been checked, and a `finding_route` listener never sees `/api` or `/mcp` requests, which are answered before it runs.

**Checking.** `php bin/raster lint` reports bindings to models or methods that don't exist, and warns about events nothing sends (usually a typo). `php bin/raster vocabulary` lists every event and who listens to it.

## Events the bundled models send

These are sent whatever caused the change: a form, the in-page editor, the JSON API, or an AI agent over MCP. (Creating accounts with `php bin/raster user` sends no event.)

| Event | Payload |
|---|---|
| `authentication.registered` | `id`, `email`, `name`, `role` |
| `authentication.logged_in`, `logged_out` | `id`, `email`, `name`, `role` |
| `authentication.password_changed`, `account_saved` | `id`, `email`, `name`, `role` |
| `authentication.login_failed` | `login` (what was typed) |
| `newsletter.subscribed` | `email`, `name`, `status` (`pending` or `confirmed`), `source`. Sent after the commit; the framework's listener `newsletter.confirmation_mail` sends a pending address its confirmation email |
| `newsletter.confirmed`, `newsletter.unsubscribed` | `email`, `name` |
| `cms.item_saved` | `collection`, `created` (`true` for a new item), `item` (all its fields) |
| `cms.item_deleted` | `collection`, `item` |
| `cms.page_saved` | `type` (the page's table), `slug`, `changed` (names of changed fields), `fields` (the saved page: `revision`, `updated_at`, and `fields` with the values, so `$payload['fields']['fields']['headline']`) |
| `content_changed` | none (sent by `util::content_changed()`; inside a transaction, once after the commit) |
| `mail.sent` | `to`, `subject` |
| `mail.failed` | `to`, `subject`, `error` |

## Request events

Each request sends events at every step, in this order:

| Event | When |
|---|---|
| `launch` | the framework has started |
| `finding_route` | before the URL is matched to a view |
| `route_not_found` | no view matches (just before the 404) |
| `route_set` | the view is known |
| `route_found` | the view is about to be rendered |
| `before_drying`, `dried_<view>`, `after_drying` | around inserting `dry` fragments |
| `before_render`, `after_render` | around each render block |
| `before_print`, `after_print` | around each print block |
| `loop` | for each nested list inside a row |
| `done` | the page is complete |
| `before_output` | last chance to change the HTML (`template::instance()->output`) |
| `land` | the page has been sent |

And around models:

| Event | Payload |
|---|---|
| `loading_model_<name>` | none. A listener returning `false` stops the model from loading. |
| `executing_<model>_<method>` | `arguments` |
| `executed_<model>_<method>` | `arguments`, `result` |

For example, a header on every page:

```php
// config/the_events.php
event::bind('before_output')->to('site', 'add_header');
```

```php
// in class site
function add_header() {
    if (!headers_sent()) header('X-Powered-By: Coffee');
    return true;
}
```

A word of warning about `executed_…`: it means "a template called this method", not "the thing happened". `executed_authentication_register` runs on every view of the sign-up page, sent or not. To act on a new account, listen to `authentication.registered`.

## Seeing events as they happen

Call `log::enable()`. Every event and SQL query from then on is printed to the browser's JavaScript console at the end of each page.

The environment isn't known yet while config files load, so to keep this to development, switch it on from a listener to the first event:

```php
// config/the_events.php
event::bind('launch')->to('site', 'debug');
```

```php
// in class site
function debug() {
    if (config::get('environment') === 'development') log::enable();
    return true;
}
```

(The files in `config/db/` are all loaded whatever the environment, so they're not a safe place for this either.)

Every event and query is then printed to the browser's JavaScript console at the end of each page.
