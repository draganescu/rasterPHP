# Accounts and roles

The bundled `authentication` model gives your site accounts: log in, sign up, password reset by email, an account page, roles and protected pages. As with everything in Raster, it has no screens of its own. You write each page in your templates, and the model handles the logic. The starter site already has all of them (`login.html`, `register.html`, `forgot.html`, `reset.html`, `account.html`), which you can restyle.

## Roles

| Role | Can |
|---|---|
| `member` | log in and see pages protected for members |
| `editor` | everything a member can, plus edit content and see drafts |
| `admin` | everything |

Roles are ordered: an admin counts as an editor and a member too.

## Creating accounts

**From the command line** (the way to create the first editor):

```sh
php bin/raster user ada@example.com                       # a new account: role admin, random password printed
php bin/raster user ada@example.com --role=editor --password=… --name="Ada Lovelace"
php bin/raster user ada                                   # a username instead of an email works too
php bin/raster users                                      # list accounts
```

Running `user` for an existing account changes only what you give it: `--password=…` resets the password and keeps the role, `--role=…` changes the role and keeps the password. The command says what it kept.

**From the site**: visitors sign up on your registration page and get the `member` role. Turn sign-up off with:

```php
config::set('registration')->to(false);
```

## The pages

Each page is a form inside a `render.authentication.<name>` block, with messages written as alerts. Field names matter; the rest of the HTML is up to you.

### Log in: `render.authentication.login`

```html
<!-- print.validation.alert('login_failed') --><p class="error">Wrong email or password.</p><!-- /print.validation.alert('login_failed') -->
<!-- render.authentication.login -->
<form method="post">
  <label>Email or username <input type="text" name="login" autocomplete="username" required></label>
  <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
  <button>Log in</button>
</form>
<!-- /render.authentication.login -->
```

- Fields: `login` (an email or a username; `email` or `username` also work) and `password`.
- After logging in, the visitor goes to `?next=/path` if the URL has one (only paths on your own site are accepted), else to the `after_login` page, else to the home page.
- **Five wrong passwords lock the account for 15 minutes.** Once 15 minutes have passed since the last wrong password, counting starts again from zero. Each failed attempt also waits a moment before answering, which slows down guessing.

### Sign up: `render.authentication.register`

- Fields: `name` (optional), `email`, `password`. Add a `password_again` field with a `validation.matches('password', 'password_again')` block if you want the password typed twice.
- Alerts: `email_taken`, `email_invalid`, `password_short`, and `registered` after success.
- New accounts are members and are logged in right away.
- Two sign-ups at once with one email (a double click, or two tabs) make one account: the first is logged in, the other is answered with `email_taken`. Looking for the email and storing the account happen in one transaction.

### Forgot password: `render.authentication.forgot`

- Field: `email`. Sends the email `_email/password_reset.html`, which can print `<!-- print.self.reset_url /-->` and `<!-- print.self.name /-->`.
- Alert: `reset_sent`. It shows whether or not the account exists, so the form can't be used to find out who has an account.
- The link works for one hour, and only once.

### Choose a new password: `render.authentication.reset`

- The page the reset email links to (`/reset` by default; change with `config::set('reset_page')->to('password/new')`).
- Field: `password` (and `password_again` with `matches` if you like).
- Alerts: `reset_invalid` (expired or used link), `password_short`, and `password_changed` after success. The user is then logged in.

### Account: `render.authentication.account`

- Fields: `name`, `email`, `password` (a new one, optional) and `current_password`.
- Changing the email or password requires the current password.
- Alerts: `account_saved`, `current_password_wrong`, `email_taken`, `email_invalid`, `password_short`.
- The form is pre-filled with the user's name and email.
- Changing the password logs out every other session of that account.
- When two members change to the same email at once, or a member changes to the email someone is signing up with, one gets it and the other sees `email_taken`.

### Log out: `render.authentication.logout`

A form with just a button:

```html
<!-- render.authentication.logout --><form method="post"><button>Log out</button></form><!-- /render.authentication.logout -->
```

Logging out is a POST, not a link, so another site can't log your visitors out with a hidden image.

### Who is logged in: `render.authentication.me`

One row with `name`, `email` and `role`, already escaped. Empty for visitors.

```html
<!-- render.authentication.me --><p>Logged in as <!-- print.name -->Ada<!-- /print.name --></p><!-- /render.authentication.me -->
```

## Showing things by role

These flags are set on every page:

```html
<!-- print.if.logged_in --><a href="account.html">Account</a><!-- /print.if.logged_in -->
<!-- print.if.logged_out --><a href="login.html">Log in</a><!-- /print.if.logged_out -->
<!-- print.if.is_member -->…<!-- /print.if.is_member -->
<!-- print.if.is_editor -->…<!-- /print.if.is_editor -->
<!-- print.if.is_admin -->…<!-- /print.if.is_admin -->
```

Hiding something with a flag only hides it from the page. To keep a whole page private, protect it.

## Protected pages

```php
config::set('protected')->to(array(
    'account' => 'member',
    'members' => 'member',   // /members and everything under it
    'staff'   => 'editor',
));
```

Each key is a path pattern (a regular expression matched from the start of the path); each value is the lowest role allowed. Matching is by prefix, so `'members'` also protects `/membership`. Write `'members(/|$)'` to protect only `/members` and the pages under it.

- Visitors who aren't logged in are sent to the log-in page, with `?next=` so they come back afterwards.
- Logged-in users without the role get **403**.
- Protected pages are left out of the sitemap and the static export.
- Pages protected for `editor` or `admin` are admin pages: the [in-page editor](The-In-Page-Editor#admin-pages) lists them in its **Admin** menu.

## In your own models

```php
authentication::user();          // array('id', 'name', 'email', 'username', 'role'), or null
authentication::can('member');   // true when logged in with at least that role
authentication::can('editor');
authentication::save_user('ada@example.com', $password, 'member', 'Ada');  // create or update
authentication::create_user('ada@example.com', $password, 'member', 'Ada'); // create only: null when the email has an account
```

To react when someone signs up or logs in (a welcome email, adding them to a CRM), listen to the [events](Events) `authentication.registered`, `logged_in`, `logged_out`, `login_failed`, `password_changed` and `account_saved`.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `registration` | `true` | `false` turns sign-up off |
| `protected` | none | path pattern => role |
| `login_page` | `login` | where protected pages send visitors |
| `after_login` | none | where to go after logging in or signing up (else `?next=` or the home page) |
| `reset_page` | `reset` | the page the reset email links to |
| `password_min_length` | `8` | minimum password length for sign-up, reset and account changes |

Password reset emails need the site's address to be known in production; see [Sending email](Sending-Email).

## Sessions and cookies

Visitors get **no session cookie** until they log in (the only cookie a visitor can get is the language choice, see [Translations](Translations)). The session cookie is created at log in; it's `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS sites. Passwords are stored with PHP's `password_hash`.

Accounts from old Raster 1.x sites (MD5 passwords, the `usersdata` table) are moved to the new `user` table automatically, but only on a fluid (development) database connection. For a frozen production database, open the site once against a non-frozen connection to migrate them. Old MD5 passwords are re-hashed securely at the next log in.
