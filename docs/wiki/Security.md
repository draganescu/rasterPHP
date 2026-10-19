# Security

This page lists the protections Raster applies for you, and the few things that remain your responsibility.

## Handled for you

**Private files stay private.** Code, config, the database and raw templates are never served, on `php -S`, Apache, nginx or Caddy alike, from one list of rules. See [Deploying to production](Deploying-to-Production#how-a-raster-site-is-laid-out-on-a-server). Matching ignores upper and lower case, so `/APPLICATION/DATA/RASTER.SQLITE` is refused too.

**Forms can't be posted from other websites.** Every POST is checked against the browser's `Origin`, `Sec-Fetch-Site` or `Referer` header, and `/api` methods listed for staff or members are checked on every verb, GET too (see [Models](Models)). This blocks cross-site request forgery (CSRF), where a malicious page makes a visitor's browser submit your forms. Logged-in users' forms also carry a secret per-session token that must match. See [Forms and validation](Forms-and-Validation#protection-added-for-you).

**Spam bots are fooled.** Every post form has a hidden honeypot field. Posts that fill it get a fake success and nothing is done.

**Spam is checked, without keeping anyone's address.** Every post form also carries a hidden time stamp, signed with the site's key. A form model can ask `validation::get()->suspect()` whether a post looks like spam; the newsletter, sign-up, forgot-password and booking forms, and every record form visitors can fill, do. It looks at the stamp (missing, forged, sent too fast, or too old), how many posts one IP and one email address made in the last hour, links, and a blocklist you set. A held post gets its form back with what was typed, and an alert in your words. Too many posts from one IP only flag a post, since many people can share one address; `spam_ip_blocks` true holds those back too. The contact form keeps a suspected message aside for staff and thanks the sender all the same. Editors are never held. See [Forms and validation](Forms-and-Validation#spam-checks).

- **No raw IP is stored**, in a table, a log line or an event. Counts hold a hash of the IP (an IPv6 address by its first 64 bits) and of the email address, made with a salt that changes each day. At midnight the salt is deleted with every count made with it, so limits start again and nothing can be traced back.
- **The key** is `RASTER_SECRET`, or else a file Raster makes at `application/data/secret`. A check that can't run (no key, no table yet) lets the post through and logs a warning: a broken check never locks visitors out.
- **Behind a proxy** (Fly, a load balancer, nginx in front) every post comes from the proxy's address. When that address is private (`10.…`, `192.168.…`, `127.0.0.1`, Fly's `fdaa:…`), nobody on the open internet could have sent it, so Raster reads the visitor's address from `client_ip_header` (`X-Forwarded-For`) with no settings. This holds only when the proxy writes the header itself, setting or overwriting `client_ip_header`; something that only forwards the connection (Docker's port proxy, a NAT port-forward, nginx in front of Apache without `proxy_set_header X-Forwarded-For`) passes on the visitor's own header, which a bot can change on every post. Behind one of those, set `trusted_proxies` to `array()`. Cloudflare's addresses are public: set `trusted_proxies` to its ranges and `client_ip_header` to `CF-Connecting-IP`. Setting `trusted_proxies` trusts only what it lists; `array()` trusts nobody.
- **Many people on one address.** When one address sends one form more than 5 different email addresses in an hour, `raster doctor` warns in production: "Many people seem to share one address. If the site is behind Cloudflare or another proxy, set `trusted_proxies` and `client_ip_header`." It can also be one sender trying many addresses, a bot rather than a crowd.
- **Tune it** with `spam_min_seconds`, `spam_max_age`, `spam_per_ip`, `spam_per_email`, `spam_ip_blocks`, `spam_max_links` and `spam_blocklist` (see [Settings and environments](Settings-and-Environments)).

**Validation runs on the server.** The rules in your HTML (`required`, `type`, `maxlength`, `pattern`…) are enforced on the server as well as in the browser. A field sent as a list the form didn't ask for (`name[]=x`), or with bytes that aren't UTF-8, fails `required` instead of slipping past the rules.

**Links visitors type can't run script.** A record field a visitor wrote and a view prints as a link (`print.@href.website`) is dropped when it is a `javascript:`, `data:` or `vbscript:` URL, also when an HTML entity, a tab, a newline or a control byte hides the scheme. The in-page editor applies the same rule to links editors type.

**Passwords** are hashed with PHP's `password_hash`. Five wrong passwords lock an account for 15 minutes (once 15 minutes pass after the last one, counting starts again), and failed attempts are slowed down. Changing a password logs out other sessions. The password-reset form answers the same whether or not an account exists, and reset links expire after an hour and work once.

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

**Offer over `/api` only what you mean to.** A model's methods are reachable at `/api/<model>/<method>` only when it lists them in `static function api()`, each with the least role that may call it (`visitor`, `member`, `editor`, `admin`). Everything else answers 404. A method listed for visitors can be called by anyone, so if it writes, deletes or sends something it checks what it's given. See [Models](Models#a-models-json-api).

The same holds for the bundled models: `cms` offers only its editor endpoints, `style` and `logout`, and a public method you add in a `the_cms` override answers 404 unless your override's `api()` lists it (that list adds to what `cms` lists). The `api_open` setting of earlier versions, which kept every public method reachable, is gone: setting it does nothing.

**Errors stay in the log.** Outside development Raster turns PHP's `display_errors` off for every request, so an error shows visitors no paths or arguments: a page answers a plain 500, an `/api` method `{"error":"server error"}`, and the details go to the error log with the URL. `raster doctor` warns when php.ini still has `display_errors` on in production, because an error before Raster starts would still show; set it off there too.

**Protect private pages on the server side.** `print.if.is_editor` only hides markup. To keep a whole page private, list it in `config::set('protected')`. See [Accounts and roles](Accounts-and-Roles#protected-pages).

**Use bound parameters** in your own SQL. Never concatenate visitor input into a query.

**Keep secrets out of git.** Use environment variables for SMTP passwords and the MCP token.

**Serve over HTTPS**, and set `RASTER_URL` to the `https://` address. `doctor` warns when it isn't.

**Keep the web server config current.** After updating Raster, `doctor` tells you if `.htaccess` is missing rules; regenerate nginx or Caddy config with `raster deploy` too.

**Don't put public files in folders named `config`, `models`, `data` or `i18n`**, anywhere: those names are private under every top-level folder. Put downloads in `media/` or the theme folder.
