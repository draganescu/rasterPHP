# Sending email

In Raster an email is a view: an HTML file in your theme, written like any page. The bundled `mail` model renders it and sends it. Password resets and newsletter confirmations use the same mechanism, so you control every word they say.

## Writing an email

Put email views in `_email/` inside your theme. The folder name starts with `_`, so these files are never reachable as web pages.

```html
<!-- application/views/default/_email/welcome.html -->
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Welcome to the club</title></head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5">
  <p>Hi <!-- print.self.name -->there<!-- /print.self.name -->,</p>
  <p>Your account is ready. <a href="account.html">Visit your account</a>.</p>
  <img src="images/logo.png" alt="" width="120">
</body>
</html>
```

- The `<title>` is the **subject**.
- `print.self.<name>` prints a value passed by the sender. These values are HTML-escaped.
- Relative links and images become absolute addresses, pointing at your site and theme folder, so they work in a mail program.
- Other annotations work too: an email can call models and show CMS fields.
- A plain-text version is made from the HTML automatically and sent alongside it.
- Mail programs ignore most CSS in `<style>` blocks, so put styles inline, as above.

## Sending it

```php
$sent = mail::send_view('_email/welcome', 'ada@example.com', array('name' => 'Ada'));
if (!$sent) {
    log::error('Welcome email failed: '.mail::$last_error);
}
```

`send_view($view, $to, $values, $headers)` returns `true` when the message was handed over. The optional `$headers` array adds headers, such as `array('Reply-To' => 'sales@example.com')`.

To send HTML you built yourself: `mail::send($to, $subject, $html)`.

To render a view into a string without sending it (for a PDF, an export, or an HTML fragment for a script), use `controller::render_view('_email/welcome', array('name' => 'Ada'))`. The values are available as `print.self.<name>`, and the page being built isn't affected.

## Where mail goes: the transport

One setting decides how mail leaves the site, the environment variable `RASTER_MAIL` (or `config::set('mail')->to(…)`):

| Value | What happens |
|---|---|
| `log://` | each email is written as an `.eml` file in `application/data/mail/`. **The development default.** Open the files with any mail program. |
| `log:///some/folder` | the same, in another folder |
| `mail://` | PHP's `mail()` function, which uses the server's own mail setup. **The production default.** |
| `smtp://user:pass@smtp.example.com:587` | an SMTP server, with an encrypted connection (STARTTLS) |
| `smtps://user:pass@smtp.example.com:465` | an SMTP server over TLS from the start |

For a real site, use your email provider's SMTP details (Postmark, Mailgun, Amazon SES, your hosting company, …). Special characters in the user name or password must be URL-encoded (`@` becomes `%40`).

Raster refuses to send your SMTP password over an unencrypted connection. If a server offers no encryption, the send fails, unless the host is `localhost` or you add `?insecure=1` to the address on purpose.

## The sender

```sh
RASTER_MAIL_FROM="My Site <hello@example.com>"
```

or `config::set('mail_from')->to('My Site <hello@example.com>')`. Use an address on a domain your mail provider is allowed to send for; otherwise receivers may treat the mail as spam.

## The site's address must be known

Emails contain links, and a link needs your site's address. Raster normally takes the address from the request, but a visitor can fake the `Host` header, which would let an attacker plant their own domain in, say, a password reset link.

So **in production, email is only sent when the site's address is configured**:

```sh
RASTER_URL=https://example.com/
```

(or `config::set('site_url')->to('https://example.com/')`). Without it, `send_view` returns `false` and `mail::$last_error` says why. In development and on the command line this check is skipped. `php bin/raster doctor` warns about it.

## Events

Every send dispatches `mail.sent` (with `to` and `subject`) or `mail.failed` (with `error` too). Listen to them to keep a log or raise an alarm; see [Events](Events).

## Testing email

In development everything goes to `application/data/mail/`, one file per message, named in the order they were sent. The demo café's test suite even runs a fake SMTP server (`tests/fake_smtp.php`) to check the SMTP path.
