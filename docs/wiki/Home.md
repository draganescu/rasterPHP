# Raster PHP documentation

Raster is a small PHP framework for websites: company sites, landing pages, blogs, newsletters and small web apps. These pages explain how to build and run a site with it. They assume you know HTML and some PHP, and nothing about Raster itself.

## Raster in one paragraph

You write your pages as ordinary HTML files. Where a part of the page should come from somewhere else (a database, a list of blog posts, a form result), you wrap it in an HTML comment such as `<!-- print.cms.headline -->Hello<!-- /print.cms.headline -->`. Raster reads those comments, asks a PHP class for the data, and puts the answer in place of the placeholder. If nothing comes back, the placeholder text stays. Because the comments are invisible in a browser, the same file still works as a static mock-up.

The built-in content management system (CMS) reads the same comments to work out what content your site has. You never write a database schema or configure an admin panel: the HTML is the schema.

## What's included

| Feature | What it gives you | Page |
|---|---|---|
| Templates | HTML files with comment markers, one file per URL | [Annotations](Annotations) |
| CMS | editable page text, lists of items (news, products, team members), drafts, scheduled posts, revision history | [The CMS](The-CMS) |
| In-page editor | editors change content directly on the page after logging in | [The in-page editor](The-In-Page-Editor) |
| Forms | validation rules taken from your HTML, messages written in your template, spam and cross-site protection | [Forms and validation](Forms-and-Validation) |
| Accounts | log in, sign up, password reset, roles, protected pages | [Accounts and roles](Accounts-and-Roles) |
| Email | emails written as templates, sent through SMTP or PHP's `mail()` | [Sending email](Sending-Email) |
| Newsletter | double opt-in sign-up, unsubscribe, send any page as an issue | [Newsletter](Newsletter) |
| Feeds | RSS, Atom, JSON, sitemap and text files | [Feeds, sitemaps and data views](Feeds-Sitemaps-and-Data-Views) |
| Translations | several languages from one set of templates | [Translations](Translations) |
| Static export | the whole site as plain files for any static host | [Static export](Static-Export) |
| Agent tools | an MCP server so AI assistants can read and edit the site safely | [Working with AI agents](Working-with-AI-Agents) |

## Where to start

1. [Getting started](Getting-Started): install, run the site on your computer, log in.
2. [How Raster works](How-Raster-Works): the three ideas behind everything else.
3. [Tutorial: your first site](Tutorial-Your-First-Site): build a page with editable text, a list, your own PHP data and a contact form.

After that, use the reference pages in the sidebar as you need them.

## Reference

- Building pages: [Pages and URLs](Pages-and-URLs) · [Annotations](Annotations) · [Models](Models) · [The database](The-Database) · [Events](Events)
- Content: [The CMS](The-CMS) · [The in-page editor](The-In-Page-Editor) · [Pagination](Pagination) · [Feeds, sitemaps and data views](Feeds-Sitemaps-and-Data-Views) · [Translations](Translations)
- Visitors: [Forms and validation](Forms-and-Validation) · [Accounts and roles](Accounts-and-Roles) · [Sending email](Sending-Email) · [Newsletter](Newsletter)
- Running a site: [Settings and environments](Settings-and-Environments) · [Command line](Command-Line) · [Deploying to production](Deploying-to-Production) · [Static export](Static-Export) · [Updating Raster](Updating-Raster) · [Security](Security)
- Going further: [Working with AI agents](Working-with-AI-Agents) · [Extending Raster](Extending-Raster) · [Best practices](Best-practices) · [Troubleshooting](Troubleshooting) · [Glossary](Glossary)

## Other sources

- `AGENTS.md` in the repository is the complete technical specification. It is dense and written for AI agents; when this wiki and `AGENTS.md` disagree, `AGENTS.md` and the code are right.
- The demo café in `demo/` is a complete site that uses every feature. When you are unsure how something is written, look there. Run it with `RASTER_APP=demo php bin/raster serve`.
- `CHANGELOG.md` lists what changed in each release.

These pages describe Raster 2.0 and the unreleased changes on `master` as of September 2026.
