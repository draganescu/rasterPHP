# Forms and validation

In Raster, a form's **rules** are its HTML attributes, its **messages** are written in the template, and a small model method decides what happens when it's sent. Protection against other websites and spam bots is added for you.

## The pattern

A form posts to its own page. The `render` block around the `<form>` names the method that handles it:

```html
<!-- print.validation.alert('sent') --><p class="notice">Thanks, we got your message.</p><!-- /print.validation.alert('sent') -->

<!-- render.contact.send -->
<form method="post">
  <label>Email <input type="email" name="email" required></label>
  <!-- render.validation.field('email') --><p class="error">Enter your email address.</p><!-- /render.validation.field('email') -->

  <label>Message <textarea name="message" required maxlength="2000"></textarea></label>
  <!-- render.validation.field('message') --><p class="error">Write a message (up to 2000 characters).</p><!-- /render.validation.field('message') -->

  <button>Send</button>
</form>
<!-- /render.contact.send -->
```

```php
<?php // application/models/contact/contact.php
class contact {
    function send() {
        $v = validation::get();
        if (!$v->submitted()) return false;                            // 1. not sent: show the form as written
        if (!$v->valid()) return template::instance()->form_state();   // 2. invalid: show it again with the values typed
        mail::send_view('_email/contact', 'me@example.com', array(     // 3. valid: do the work
            'message' => util::post('message'),
        ));
        util::done('sent');                                            // 4. redirect back, show the 'sent' alert
    }
}
```

Almost every form handler has these four steps.

- **`submitted()`** is true only when *this* form was posted. Several forms on one page don't interfere with each other.
- **`valid()`** checks every rule of the form. It's false when any field breaks a rule.
- **`form_state()`** returns the form with the visitor's values filled back in, so they don't have to type everything again. Returning it from the method puts that version on the page.
- **`util::done('sent')`** redirects to the same page with `?done=sent`. This prevents a double post when the visitor reloads, and shows the `alert('sent')` block. It stops the request, so nothing after it runs.

Leave out the form's `action` attribute, or point it at the same page. A `<form method="post">` that isn't inside a render block has no handler, and `lint` warns about it.

## Rules come from the HTML

These attributes are checked in the browser (as HTML always does) **and again on the server**, so a visitor can't get around them:

| Attribute | Rule name | Fails when |
|---|---|---|
| `required` | `required` | the field is empty (for checkbox groups: none ticked) |
| `type="email"` | `email` | not an email address |
| `type="url"` | `url` | not a full URL |
| `type="number"`, `type="range"` | `number` | not a number |
| `type="date"` | `date` | not a date written as `YYYY-MM-DD` |
| `min`, `max` on numbers and dates | `min`, `max` | too small or too large |
| `minlength`, `maxlength` | `minlength`, `maxlength` | too short or too long (in characters) |
| `pattern` | `pattern` | doesn't match the regular expression (the whole value must match) |

Empty optional fields are never checked against the other rules: an empty, non-required email field is fine.

Two things browsers never send fail `required`, on any field, so the words you wrote for an empty field show: a list (`name[]=x`) for a field the form doesn't name `name[]`, and a value with bytes that aren't UTF-8. A field you do name `tags[]` (a group of checkboxes) takes a list as before. A `pattern` that can't run counts as not matched.

## Showing messages

### Per field

```html
<!-- render.validation.field('email') --><p class="error">Enter a valid email address.</p><!-- /render.validation.field('email') -->
```

The block shows when the field breaks **any** of its rules. To give a different message per rule, name the rule:

```html
<input type="number" name="guests" min="1" max="12" required>
<!-- render.validation.field('guests', 'required') --><p class="error">How many people?</p><!-- /render.validation.field('guests', 'required') -->
<!-- render.validation.field('guests', 'max') --><p class="error">For more than 12, please call us.</p><!-- /render.validation.field('guests', 'max') -->
```

Put these blocks **inside** the `<form>`. `lint` warns about a `validation.field` whose input has no rule, because it could never show.

### Rules HTML can't express

These are blocks too, and they also count toward `valid()`:

| Block | Shows when |
|---|---|
| `validation.matches('password', 'password_again')` | the two fields differ |
| `validation.cant_be('username', 'admin')` | the field has that value |
| `validation.accepted('terms')` | the checkbox isn't ticked |

```html
<label><input type="checkbox" name="terms"> I accept the terms</label>
<!-- render.validation.accepted('terms') --><p class="error">Please accept the terms.</p><!-- /render.validation.accepted('terms') -->
```

### Your own rules

Create `application/models/validation/rules/<rule>.php` with a function `validate_<rule>` that returns `true` when the value is fine:

```php
<?php // application/models/validation/rules/not_monday.php
function validate_not_monday($value) {
    if ($value === null || trim((string)$value) === '') return true;  // let 'required' handle empty values
    $time = strtotime((string)$value);
    return $time === false || date('N', $time) !== '1';
}
```

Use it like the built-in ones. The first argument is the field name; any further arguments are passed to your function after the value:

```html
<input type="date" name="date" required>
<!-- render.validation.not_monday('date') --><p class="error">We are closed on Mondays.</p><!-- /render.validation.not_monday('date') -->
```

### Alerts: messages for the whole form

```html
<!-- print.validation.alert('booked') --><p class="notice">Your table is booked. See you soon!</p><!-- /print.validation.alert('booked') -->
```

An alert is hidden until either:

- a model calls `validation::get()->raise('booked')` during this request, or
- the page is loaded with `?done=booked`, which is what `util::done('booked')` does.

Alerts can sit anywhere on the page, before or after the form. Use `raise()` for messages about a failed attempt ("Wrong password") and `done()` for success. `lint` warns about an alert that no model ever raises.

## Filling the form again: form_state

`template::instance()->form_state()` fills the current form with the posted values. You can also pass your own values, for example to pre-fill an edit form:

```php
function edit_profile() {
    $v = validation::get();
    $user = authentication::user();
    if (!$v->submitted()) return template::instance()->form_state(array('name' => $user['name'], 'email' => $user['email']));
    …
}
```

It sets:

- `value` on inputs
- `checked` on checkboxes and radio buttons whose value matches (arrays work for `name="tags[]"`)
- `selected` on matching `<option>`s
- the text of `<textarea>`s
- the text of any element with the class `spa_<key>`, e.g. `<strong class="spa_email">` for showing a value that isn't an input

Values go back exactly as they were typed: `$100`, `\1` and `$0` stay as they are.

**Passwords are never filled in**, nor are file inputs. When you pass your own array, keys with no matching field become hidden inputs, which is handy for a token or an id the form must send back.

## In the model: more from the validator

```php
$v = validation::get();
$v->submitted();          // this form was posted
$v->valid();              // every rule passes
$v->errors();             // array('email' => array('required'), …) for this form
$v->raise('name_taken');  // show alert('name_taken')
util::post('email');      // a posted value, or false
```

`errors()` is useful for, say, showing a count: `template::set('error_count')->to((string)count($v->errors()))` and `<!-- print.self.error_count /-->`.

## Protection added for you

Every `<form method="post">` gets three hidden fields when the page is rendered:

| Field | Purpose |
|---|---|
| `raster_form` | which render block owns the form, so `submitted()` knows which form was sent |
| `raster_hp` | a **honeypot**: a field hidden from people. Bots that fill every field fill it too. |
| `csrf` | for logged-in users: a secret per session, proving the post came from a page of this site |

And every post is checked before any model runs:

- **Posts from another website are refused** (403). Browsers say where a post comes from (`Origin`, `Sec-Fetch-Site` or `Referer` headers), and Raster compares that with your site. This blocks cross-site request forgery: another site making a visitor's browser submit your forms.
- **Posts that filled the honeypot get a fake success**: they're redirected as if it worked, and nothing is done. The bot moves on.
- **Posts from logged-in users without the right `csrf` value are refused** with a message asking them to reload the page. This happens when a page was open so long the session changed.

You don't need to add anything to your forms for this.

## Good to know

- **Several forms on one page** are handled separately: each has its own render block, and validation blocks belong to the form they sit in. The demo café's `/visit` page has three.
- **Posts from scripts or tests** without the `raster_form` field count as sent for a form when they include at least one of its fields.
- **The JSON API** at `/api/…` never runs form handlers: there, `submitted()` is always false.
- **File uploads** aren't handled for you. Add `enctype="multipart/form-data"` to the form and read `$_FILES` in your model.
- **Static export**: a static host can't receive forms. Wrap forms in `<!-- print.if.live -->` and write what the static site shows instead (an email address, a phone number) in `<!-- print.if.static -->`. See [Static export](Static-Export).
- **Older Raster sites** used the regions `not_empty`, `email_format` and `are_the_same`. They still work until Raster 3.0; use `required`, `type="email"` with `validation.field(…)`, and `matches` instead. `raster doctor` lists where they're used.
