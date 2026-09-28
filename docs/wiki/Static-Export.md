# Static export

`raster export` writes your whole site as plain HTML files that any static host can serve: GitHub Pages, Netlify, Cloudflare Pages, an S3 bucket, or any web server without PHP. You keep editing with Raster on your computer (or a private server), then export and upload.

This suits sites without forms or accounts: portfolios, documentation, blogs, landing pages.

## Running it

```sh
php bin/raster export site/ --url=https://example.com/
```

Raster starts its built-in server, visits every page **as a visitor would** (not logged in, so no drafts and no editor), follows the links it finds, and saves each answer:

| URL | File |
|---|---|
| `/` | `index.html` |
| `/about` | `about/index.html` |
| `/news/news_item/our-new-office` | `news/news_item/our-new-office/index.html` |
| `/news/news_page/2` | `news/news_page/2/index.html` |
| `/news.rss` | `news.rss` |
| a missing page | `404.html` |

Theme files (CSS, images, fonts) and `media/` are copied too. Most static hosts serve `about/index.html` for `/about` and use `404.html` for missing pages.

**Links** point at the `--url` address. Without `--url`, `RASTER_URL` is used, and without either, links are root-relative (`/about`), which works when the site is at the root of its domain.

## Forms need PHP

A static host can't receive a form. If a page still shows a form, the export **stops and writes nothing**, listing the pages. Decide what each form becomes on the static site:

```html
<!-- print.if.live -->
<!-- render.contact.send -->
<form method="post"> … </form>
<!-- /render.contact.send -->
<!-- /print.if.live -->

<!-- print.if.static -->
<p>Write to us at <a href="mailto:hello@example.com">hello@example.com</a>.</p>
<!-- /print.if.static -->
```

`if.live` is true on the running site, `if.static` during an export. So the live site keeps its form, and the exported site shows the email address. Other options: link to a hosted form service, or show a phone number.

## What is left out

- Pages that need an account (anything that redirects to log in), and the log-in, sign-up, password, account and newsletter confirm/unsubscribe pages
- `/api` and `/mcp`
- Paths you exclude: `--skip=/drafts` on the command line (repeatable), or in config:
  ```php
  config::set('export_skip')->to(array('/private', '#^/tmp-#'));
  ```
  An entry is a path prefix, or a regular expression between `#` characters.

At the end, the command lists links to left-out pages and links with query strings (a static host can't answer `?page=2`), so you can fix or accept them.

## Several languages

With [translations](Translations), the first language goes at the root and each other language in its own folder: `/ro/`, `/ro/about/`, and so on. The language switcher links to those folders.

## Exporting again

Export to the same folder again and Raster only does what's needed:

- If nothing the site is made of has changed (content, templates, models, theme files, media), it does nothing: *Nothing changed since the last export*.
- Otherwise it renders every page again but **writes only files whose content changed**, and **removes files the site no longer has**.

So an upload tool that syncs changed files (rsync, most static hosting CLIs) only sends what changed. The folder's `.raster-export.json` keeps track. Files the export didn't write (say, a `CNAME` file for GitHub Pages) are left alone.

`--clean` deletes the whole folder, your own extra files included, and starts over. The export refuses to write into a non-empty folder that it didn't create.

## A simple workflow

```sh
php bin/raster serve                                          # edit content in the browser
php bin/raster export ../site-public --url=https://example.com/
cd ../site-public && git add -A && git commit -m "Update" && git push   # e.g. GitHub Pages
```
