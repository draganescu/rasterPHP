# Newsletter

The bundled `newsletter` model runs an email list: sign-ups with confirmation by email (**double opt-in**), an unsubscribe page, one-click unsubscribe from mail programs, and sending any page of your site as an issue.

Emails go out through the `mail` model, so set up [Sending email](Sending-Email) first.

## The sign-up form

```html
<!-- print.validation.alert('check_email') --><p>Almost there: check your inbox to confirm.</p><!-- /print.validation.alert('check_email') -->
<!-- print.validation.alert('subscribed') --><p>You're subscribed. Thanks!</p><!-- /print.validation.alert('subscribed') -->
<!-- render.newsletter.signup -->
<form method="post">
  <input type="email" name="email" required placeholder="you@example.com">
  <input type="text" name="name" placeholder="Your name (optional)">
  <!-- render.validation.field('email') --><p class="error">Please enter a valid email address.</p><!-- /render.validation.field('email') -->
  <button>Subscribe</button>
</form>
<!-- /render.newsletter.signup -->
```

- Fields: `email`, and optionally `name`.
- With double opt-in (the default), the visitor gets the email `_email/newsletter_confirm.html`, which must contain the confirmation link `<!-- print.self.confirm_url /-->`. They are subscribed only after clicking it. The alert is `check_email`.
- Someone who's already subscribed sees the same `check_email` answer, so the form can't reveal who is on the list.
- Someone who signs up again before confirming gets the same link again, so whichever email they open, the link works.
- The same address sent twice at once (a double click) is stored once.
- Also possible: `email_invalid`.

The starter site puts this form in the footer of `_layout.html`.

## The confirmation page

The link in the confirmation email goes to `/newsletter-confirm?token=…`. That page needs a `render.newsletter.confirm` block (it can be empty) and alerts:

```html
<!-- print.validation.alert('confirmed') --><h1>You are subscribed</h1><!-- /print.validation.alert('confirmed') -->
<!-- print.validation.alert('confirm_invalid') --><h1>This link isn't valid any more</h1><!-- /print.validation.alert('confirm_invalid') -->
<!-- render.newsletter.confirm --><p>Subscribed as <!-- print.email -->you@example.com<!-- /print.email --></p><!-- /render.newsletter.confirm -->
```

## The unsubscribe page

Unsubscribe links go to `/newsletter-unsubscribe?token=…`. The page asks for confirmation with a button:

```html
<!-- print.validation.alert('unsubscribed') --><h1>You are unsubscribed</h1><!-- /print.validation.alert('unsubscribed') -->
<!-- print.validation.alert('unsubscribe_invalid') --><h1>Use the link from one of our emails</h1><!-- /print.validation.alert('unsubscribe_invalid') -->
<!-- render.newsletter.unsubscribe -->
<form method="post">
  <p>Stop sending the newsletter to <strong class="spa_email">you@example.com</strong>?</p>
  <button>Unsubscribe</button>
</form>
<!-- /render.newsletter.unsubscribe -->
```

`class="spa_email"` shows the subscriber's address (see [form_state](Forms-and-Validation#filling-the-form-again-form_state)).

Mail programs such as Gmail and Apple Mail also show their own **Unsubscribe** button for your issues. It works with one click, through the same page.

To use other addresses for these two pages:

```php
config::set('newsletter_confirm_page')->to('letters/confirm');
config::set('newsletter_unsubscribe_page')->to('letters/stop');
```

## Subscriber count

```html
Join <!-- print.newsletter.count /--> readers.
```

Counts confirmed subscribers.

## Sending an issue

Any page of your site can be sent as an issue: write a news post in the CMS, check it on the site, then send it.

```sh
RASTER_URL=https://example.com/ php bin/raster send /news/news_item/our-new-office --dry-run
RASTER_URL=https://example.com/ php bin/raster send /news/news_item/our-new-office --to=me@example.com
RASTER_URL=https://example.com/ php bin/raster send /news/news_item/our-new-office
```

| Option | Does |
|---|---|
| `--dry-run` | says how many subscribers would get it, sends nothing |
| `--to=address` | sends only to that address, as a test |
| `--subject="…"` | a subject other than the page's `<title>` |
| `--again` | sends a page that was already sent (each page is sent once otherwise) |

What happens:

1. The page is rendered as a visitor would see it.
2. Scripts, forms and `<nav>` elements are removed; they don't belong in an email.
3. Links and images become absolute addresses on your site.
4. The page's `<title>` becomes the subject (or `--subject`). A page without a title isn't sent.
5. Each confirmed subscriber gets their own copy with their own unsubscribe link.

The site's address, `RASTER_URL` (or the `site_url` setting), is required, so links point at your live site. Run the command on the server, or anywhere with the production database, with `RASTER_ENV=production`.

**Put an unsubscribe link in the page** so each reader gets their own:

```html
<a href="<!-- print.newsletter.unsubscribe_url /-->">Unsubscribe</a>
```

On the website this links to the unsubscribe page; in a sent issue, each reader's copy links with their own token. `raster send` warns you if the page has none (readers can still use their mail program's button).

## From your own code

Subscribe someone from any model, for example when they tick "send me news" in another form:

```php
$status = newsletter::subscribe($email, $name, '/visit');   // the last argument records where they signed up
```

It does what the form does, confirmation email included, and returns `'pending'`, `'confirmed'`, `'already'`, or `false` for an invalid address. Better still, have the form's model send an event and let another model subscribe the guest; see [Events](Events).

Events sent: `newsletter.subscribed` (with `email`, `name`, `status`, `source`), `newsletter.confirmed` and `newsletter.unsubscribed`.

`newsletter::subscribe()` looks the address up and stores it in one transaction (see [Records](Records)). Called inside a transaction of your own, it joins it: `newsletter.subscribed` and the confirmation email wait for your commit, and a rollback leaves no subscriber and sends nothing.

The confirmation email itself is sent by the framework's listener on `newsletter.subscribed`, `newsletter.confirmation_mail`, for a `pending` address. To send your own instead (through a mailing service, say), unbind it in `application/config/the_events.php` and listen yourself:

```php
event::unbind('newsletter.subscribed')->from('newsletter', 'confirmation_mail');
```

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `newsletter_double_opt_in` | `true` | `false` subscribes immediately, without a confirmation email; the alert is then `subscribed` |
| `newsletter_confirm_page` | `newsletter-confirm` | the confirmation page |
| `newsletter_unsubscribe_page` | `newsletter-unsubscribe` | the unsubscribe page |

Double opt-in is strongly recommended: it proves the address belongs to the person, and many countries' laws expect it.

Subscribers are stored in the `subscriber` table, with their status (`pending`, `confirmed`, `unsubscribed`) and dates.
