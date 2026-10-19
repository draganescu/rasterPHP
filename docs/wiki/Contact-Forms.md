# Contact forms

The bundled `contact` model takes messages from your own contact form. You write the form, the email staff get and the words of each alert; the model stores every message, keeps the ones that look like spam aside, emails staff about the rest and owns the data, the rules and the events. You write no model code, and nothing in `the_app.php` is required.

The model counts only on a site whose views use `render.contact.*` or `print.contact.*`. Other sites get no table, no Admin entries and nothing new in `describe` or MCP. A site with its own model called `contact` keeps it: the bundled one stays out of the way, and `raster lint` says so (see [Moving from your own contact model](#moving-from-your-own-contact-model)).

The demo café in the Raster repository (`demo/`) is a complete example: the form on `/visit` and the staff page on `/staff`.

Emails go out through the `mail` model, so set up [Sending email](Sending-Email) first.

## The form

```html
<!-- print.validation.alert('sent') --><p class="notice">Thanks, we'll write back soon.</p><!-- /print.validation.alert('sent') -->
<!-- print.validation.alert('email_invalid') --><p class="error">Check your email address.</p><!-- /print.validation.alert('email_invalid') -->
<!-- print.validation.alert('message_invalid') --><p class="error">Write a message, up to 5000 characters.</p><!-- /print.validation.alert('message_invalid') -->

<!-- render.contact.form -->
<form method="post">
  <label>Name <input name="name"></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Phone <input type="tel" name="phone"></label>
  <label>Subject <input name="subject"></label>
  <label>Message <textarea name="message" required></textarea></label>
  <button>Send</button>
</form>
<!-- /render.contact.form -->
```

- The form posts `name`, `email`, `phone`, `subject` and `message`. Only `email` and `message` have to be filled. The page's HTML rules (`required`, `maxlength`) work as on any [form](Forms-and-Validation).
- A stored message redirects to `?done=sent`, which shows the `sent` alert. A broken rule shows the form again with what was typed and one of these alerts: `name_invalid`, `email_invalid`, `phone_invalid`, `subject_invalid` or `message_invalid`.
- A name, email, phone or subject with a line break or another control character is refused, not cleaned: they can end up in a mail header, and nothing a visitor types should be able to add one. A subject is also at most 200 characters, an email address at most 254, and a message at most `contact_max_length` (5000).
- The same email and message again within 10 minutes (a double click) stores nothing and answers `sent`.

To add a field (a topic, an order number), override the model's types, as for [Bookings](Bookings#more-fields): `class the_contact extends contact` with `types()` adding it to `parent::types()`. The form, the editor, the email and the events carry it.

## Spam is kept aside

Every message is stored. One that looks like spam (`validation::get()->suspect()`, see [Forms and validation](Forms-and-Validation#spam-checks)) is stored with status `spam` and the reasons it was flagged, for example `no_stamp` or `too_many_links`. Nobody is emailed about it, and the sender is thanked all the same, so a bot learns nothing. Besides the stamp and the limits, the model looks through `name`, `subject` and `message` for links and blocked words.

That is why a contact form needs only the `sent` alert and not `try_again` or `slow_down`. If a real person was caught, staff mark the message not spam and it moves up to the new ones. Spam older than `contact_spam_days` (30) is deleted as new messages arrive; real messages stay until staff delete them.

## The email staff get

Write `_email/contact_received.html`. The `<title>` is the subject, and `print.self.<name>` prints the message's values; `raster lint` names the view if it is missing.

```html
<title>A message from the website</title>
<p>From <!-- print.self.name -->Ana<!-- /print.self.name -->, <!-- print.self.email -->ana@example.com<!-- /print.self.email -->.</p>
<p><!-- print.self.message -->Is the terrace open?<!-- /print.self.message --></p>
<p>Sent from <a href="<!-- print.self.page_url /-->"><!-- print.self.page_url /--></a>. <a href="<!-- print.self.staff_url /-->">Open the staff page</a></p>
```

- The view gets the message's fields and `id`, `created_at`, `page_url` (the page the form was sent from), `staff_url` (the page set by `contact_staff_page`) and `site_url`.
- It goes to `contact_staff_email`, else the site's sender, **after** the visitor has their answer, so a slow mail server can't hold the form.
- `Reply-To` is the visitor, so staff answer by replying. Nothing is ever mailed to an address a visitor typed.
- A mail that fails leaves the message stored and `new`.

## The staff page

Staff read the messages with two regions that show nothing to anyone but editors:

```html
<h2>Messages: <!-- print.contact.count('status=new') -->2<!-- /print.contact.count('status=new') --> new</h2>

<!-- render.contact.messages('status=new&order=-created_at') -->
<article>
  <!-- print.@href.raster_detail_link --><a href="#"><!-- print.title -->2026-10-18 14:32<!-- /print.title --></a><!-- /print.@href.raster_detail_link -->
  <!-- print.name -->Ana<!-- /print.name -->, <!-- print.email -->ana@example.com<!-- /print.email --> on <!-- print.page -->/visit<!-- /print.page -->
  <p><!-- print.message -->Is the terrace open?<!-- /print.message --></p>
</article>
<!-- /render.contact.messages('status=new&order=-created_at') -->

<h3>Spam</h3>
<!-- render.contact.messages('status=spam&order=-created_at') -->
<p><!-- print.message -->Cheap watches<!-- /print.message --> (<!-- print.spam -->no_stamp<!-- /print.spam -->)</p>
<!-- /render.contact.messages('status=spam&order=-created_at') -->

<h3>Done</h3>
<!-- render.contact.messages('status=done&order=-created_at&limit=20') -->…<!-- /render.contact.messages('status=done&order=-created_at&limit=20') -->
```

The messages are [records](Records) of the `contactmessage` type, so in the [in-page editor](The-In-Page-Editor) each has a button for each action: **done** (staff answered it), **spam** and **not spam** (back to new, its reasons cleared). A message is never marked done straight from spam: mark it not spam first. The `title` is when it came (`2026-10-18 14:32`) and is the message's address, never the email. The `spam` field holds the reasons.

Protect the page by its own name, and the message's own page too:

```php
config::set('protected')->to(array('staff' => 'editor', 'contactmessage_item' => 'editor'));
```

`raster lint` warns about a view with `render.contact.messages` that isn't protected for editors, and about a contact form with no view listing the messages. A `contactmessage_item.html` view that renders `render.cms.contactmessage` is each message's own page, where `raster_detail_link` goes.

## Events

After a message is stored, the model sends `contact.received` (a message that isn't spam, `who` `visitor`) or `contact.flagged` (kept as spam), with the message's fields and, for flagged ones, the reasons. When staff mark a message not spam it sends `contact.received` with `who` `staff`, and mails nobody. Neither carries an IP, or its hash. Listen to them like any [event](Events), for example to post to a chat:

```php
static function listens() {
    return array('contact.received' => 'tell_chat');
}
function tell_chat($message) {
    if ($message['who'] === 'visitor') chat::post('New message from '.$message['name']);
}
```

The email is the model's own listener. To send your own instead, unbind it in `config/the_events.php`: `event::unbind('contact.received')->from('contact', 'staff_mail');`.

## Settings

| Setting | Default | |
|---|---|---|
| `contact_staff_email` | the sender | where staff emails go |
| `contact_staff_page` | `staff` | the page with the messages, for `staff_url` |
| `contact_max_length` | 5000 | characters in a message |
| `contact_spam_days` | 30 | days spam is kept; `false` keeps it |

The spam checks have settings of their own (`spam_per_ip`, `spam_max_links`, `spam_blocklist`, `trusted_proxies` and more): see [Settings and environments](Settings-and-Environments) and [Security](Security).

## Moving from your own contact model

Older guides taught a `contact` model with a `send()` method. It keeps working: a site's own `models/contact/` hides the bundled one completely, and `render.contact.send` calls your class. To move to the bundled model:

1. Rename your model, or delete it once nothing needs it.
2. Put `render.contact.form` around your form, with the fields `name`, `email`, `phone`, `subject` and `message`.
3. Write `_email/contact_received.html` from your old email.
4. Add the alerts (`sent`, `email_invalid`, `message_invalid`, …).
5. Make the staff page above.

Everything the model does is listed in `AGENTS.md`, under Bundled models.
