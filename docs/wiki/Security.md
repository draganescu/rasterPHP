# Security

This page lists the protections Raster applies for you, and the few things that remain your responsibility.

## Handled for you

**Private files stay private.** Code, config, the database and raw templates are never served, on `php -S`, Apache, nginx or Caddy alike, from one list of rules. See [Deploying to production](Deploying-to-Production#how-a-raster-site-is-laid-out-on-a-server). Matching ignores upper and lower case, so `/APPLICATION/DATA/RASTER.SQLITE` is refused too.

**Forms can't be posted from other websites.** Every POST is checked against the browser's `Origin`, `Sec-Fetch-Site` or `Referer` header. This blocks cross-site request forgery (CSRF), where a malicious page makes a visitor's browser submit your forms. Logged-in users' forms also carry a secret per-session token that must match. See [Forms and validation](Forms-and-Validation#protection-added-for-you).

**Spam bots are fooled.** Every post form has a hidden honeypot field. Posts that fill it get a fake success and nothing is done.

**Validation runs on the server.** The rules in your HTML (`required`, `type`, `maxlength`, `pattern`…) are enforced on the server as well as in the browser.

**Passwords** are hashed with PHP's `password_hash`. Five wrong passwords lock an account for 15 minutes, and failed attempts are slowed down. Changing a password logs out other sessions. The password-reset form answers the same whether or not an account exists, and reset links expire after an hour and work once.

**Sessions** start only at log in; visitors get no session cookie (only a language cookie, if they pick a language). The session cookie is `HttpOnly` (scripts can't read it), `SameSite=Lax`, and `Secure` over HTTPS. Log-out is a POST.

**Redirects after log in** (`?next=`) only go to paths on your own site, so the log-in page can't be used to send people to a phishing site.

**Links in emails** use the configured site address (`RASTER_URL`), never the `Host` header a visitor's browser sent. In production, emails with links aren't sent until the address is set.

**Development mode can't be forced from outside.** `localhost` in `servers.php` only counts for requests from the server itself.

**Templates never run code.** Method arguments in annotations are literals only; nothing is passed to `eval`.

**SQL** helpers bind parameters. Named queries and `database::instance()->query()` send values separately from the SQL.

**The editor's endpoints** require an editor account and the session token. Uploads must be real JPEG, PNG, GIF or WebP images up to 12 MB, and get new random names.

**MCP over HTTP** is off until you set a token, compares it in constant time, and doesn't offer template writing unless you allow it.

**Error details** are hidden in production: a broken page shows a plain message and the details go to the server's error log.

## Your responsibility

**Escape visitor input in HTML views.** Values printed into HTML views are printed as they are, because CMS content is meant to be HTML. If a model returns something a visitor typed, a URL parameter, or data from an outside source, escape it:

```php
return util::e($name);
```

Values in feeds, JSON views, attributes set with `@`/`+`, and `print.self` are escaped for you. `print.session` isn't.

**Protect methods that change data.** Every public method of every model in `application/models/` is callable at `/api/<model>/<method>`. A method that writes, deletes or sends something must check who's asking:

```php
function export_orders() {
    if (!authentication::can('admin')) return false;
    …
}
```

or be `protected`, `static`, or start with `_`. See [Models](Models#every-model-is-also-a-json-api).

**Protect private pages on the server side.** `print.if.is_editor` only hides markup. To keep a whole page private, list it in `config::set('protected')`. See [Accounts and roles](Accounts-and-Roles#protected-pages).

**Use bound parameters** in your own SQL. Never concatenate visitor input into a query.

**Keep secrets out of git.** Use environment variables for SMTP passwords and the MCP token.

**Serve over HTTPS**, and set `RASTER_URL` to the `https://` address. `doctor` warns when it isn't.

**Keep the web server config current.** After updating Raster, `doctor` tells you if `.htaccess` is missing rules; regenerate nginx or Caddy config with `raster deploy` too.

**Don't put public files in folders named `config`, `models`, `data` or `i18n`**, anywhere: those names are private under every top-level folder. Put downloads in `media/` or the theme folder.
