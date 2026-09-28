# Troubleshooting

Start with the two commands that find most problems:

```sh
php bin/raster lint      # template mistakes, with file and line
php bin/raster doctor    # setup problems
```

## My annotation does nothing; the sample text still shows

In order of likelihood:

1. **Spacing.** It must be exactly `<!-- print.x.y -->`: one space after `<!--`, one before `-->`. `lint` reports this and `lint --fix` repairs it.
2. **The method returned `false` or `null`.** That's the signal to keep the template's text. Check your model.
3. **It's inside a `remove` block**, which is deleted before anything runs.
4. **A `print.key` inside a render block, but the row has no such key.** The sample text stays for keys a row doesn't have.
5. **In production, a new CMS field** shows its default until you run `php bin/raster schema --apply`.
6. **A name with characters `dry` doesn't accept.** In `dry.<view>.<fragment>`, the view name may only contain lowercase letters, `_` and `/`, and the fragment name lowercase letters, `_` and `-`. No digits, and no `-` in view names. `<!-- dry._part-2.box /-->` is left in the page as a plain comment, and `lint` doesn't catch it.

## The page shows "This view has template errors"

That's development mode stopping a broken page instead of showing half of it. The list gives each problem with file, line and column; the same list comes from `lint`. A common cause is a short closing tag: `<!-- /render -->` or a closing tag without the arguments doesn't close the block. The closing tag repeats the full opening name, `<!-- /render.cms.news('limit=3') -->`, and `lint --fix` writes it in for you. The response has status 500 and an `X-Raster-Template-Errors` header. Fix the errors, or temporarily render anyway with `config::set('strict_templates')->to(false)`.

## "Model 'x' not found" or "has no public method"

- The model must be at `application/models/<name>/<name>.php` and contain `class <name>`, all with the same lowercase name.
- The method must be public.
- `lint` suggests the nearest real method name. `php bin/raster vocabulary` lists every name a template can call.

## "needs 1 argument(s), 0 given"

The template calls a method with fewer (or more) arguments than its PHP signature has. Add them in the annotation, or give the PHP parameters default values.

## My CSS or images don't load on some pages

Asset paths are relative to the **theme folder**: write `href="style.css"`, not `href="../style.css"` or `href="/style.css"`. Raster adds a `<base>` tag that makes this work on every URL. The tag is only added when your `<head>` tag is written exactly as `<head>`, without attributes. See [Pages and URLs](Pages-and-URLs#assets-css-images-scripts).

## In-page anchor links (`href="#section"`) go to the wrong place

Because of that same `<base>` tag, `#section` points at the theme folder. Write `href="about.html#section"` or `href="/about#section"`.

## My form doesn't do anything when sent

- The `<form>` must have `method="post"` and be **inside** the `render.<model>.<method>` block that handles it. `lint` warns about a post form outside any render block.
- The handler must check `validation::get()->submitted()`. It's `false` for other forms on the page, for GET requests, and for calls through `/api`.
- **403 "this form was sent from another site"**: the browser reported a different origin. This happens when you open the site under one host name (`127.0.0.1:8000`) and the form posts to another, or behind a proxy that changes the host. Set `RASTER_URL` to the address people use.
- **403 "This form has expired"**: a logged-in user's session changed (they logged out and in elsewhere). Reload the page and send again.

## A validation message never shows

- Put `validation.field('name')` blocks **inside** the `<form>`.
- The input needs a rule (`required`, `type`, `minlength`…) for `field` to have anything to check. `lint` warns when it doesn't.
- For `alert('name')` blocks: a model must call `validation::get()->raise('name')` or `util::done('name')`. `lint` warns about alerts nobody raises.

## Emails aren't arriving

- **In development** they're not sent: look in `application/data/mail/`.
- **In production**, check `RASTER_URL` is set (emails with links aren't sent without it), `RASTER_MAIL` points at a real SMTP server, and `RASTER_MAIL_FROM` is an address your provider may send from.
- `mail::$last_error` holds the last failure. Listen to the `mail.failed` event to log them.
- An SMTP server that doesn't offer encryption is refused; use `smtps://` or, only if you must, `?insecure=1`.

## Changes don't show for visitors, but do when I'm logged in

That's the page cache (production only). Content saved through Raster clears it automatically. If your own model writes data, call `util::content_changed()` afterwards. For pages that must always be fresh, add them to `page_cache_skip`. Check the `X-Raster-Cache` response header.

## A new item or post doesn't appear

- It might be a **draft** (`enabled` = 0) or **scheduled** (`published_at` in the future). Editors see these; visitors don't. Log out, or use a private window, to see what visitors see.
- The list might be filtered (`featured=1`) or limited (`limit=3`).

## "The database is frozen and does not have this table or column yet"

You're in production, where the structure only changes when you apply it:

```sh
RASTER_ENV=production php bin/raster schema --apply
```

## I renamed a field and the content disappeared

The content is still there, in the old column. `php bin/raster schema` shows it as an orphan and suggests a `--rename` command that moves it to the new name. Run it (in production, before `--apply`).

## A page is 404 but the file exists

- Files and folders starting with `_` are never pages.
- An item URL (`/news/news_item/x`) is 404 when there's no such item (or it's a draft and you're not logged in), and only works under its own collection name.
- A route in `the_routes.php` might be catching the URL first. Routes win over files.

## `raster update` refuses to run

It found edited framework files, listed in its output. Move your changes into your app (see [Extending Raster](Extending-Raster)), then run it again. `--force` updates anyway and keeps the old files in `application/data/backups/`.

## Seeing what happens inside a request

Add `log::enable();` to `config/the_app.php`. Every event and SQL query is then printed to the browser's JavaScript console. Remove it when you're done, or switch it on only in development as shown in [Events](Events#seeing-events-as-they-happen).

## Strange text at the top of every page

A config file in `application/config/` is missing its opening `<?php`, so PHP prints it instead of running it. The starter's `the_events.php` is empty; add `<?php` as its first line before writing bindings.
