---
layout: post
title:  "RTO: Request, Template, Object"
date:   2014-06-29 10:18:00
categories: rto specs
excerpt: A design pattern for web artefacts made by people and agents. A request picks a template, and the template pulls its data from objects.
# Published as _posts/2014-06-29-rto.markdown in draganescu/draganescu.github.com
# (without the heading below): https://draganescu.github.io/rto/specs/2014/06/29/rto.html
---

# RTO: Request, Template, Object

*Version 2 (2026). The first version, from 2014, described the three steps.
This version describes the whole pattern: how data is written, formats,
the URL, failure, caching and the standard objects. Raster is the reference
implementation.*

## 1. What RTO is for

RTO is a design pattern for **web artefacts**: sites, landing pages, blogs,
newsletters and small apps. It is not for applications in general.

The difference matters. A website is a collection of information presented in
various ways; an application is a tool that does something. A website has a
sitemap, and an application doesn't. RTO is built around that fact: **the page
is the unit**. You can list the pages of an artefact before building it, and
most people who visit only read.

RTO grew out of MVC, but reverses who decides. In MVC a controller decides what
data a view receives. In RTO the **template declares what it needs, and pulls
it**.

## 2. The three steps

1. A **request** names a resource and a format.
2. The request is mapped to a **template**.
3. The template is mapped to **objects**, which return data.

A single envelope (one front controller) does the mapping, runs the objects the
template points to, and returns the response. There are no per-page
controllers.

### 2.1 The request

A request is a key-value pair: the **URL** describes the data, and the
**format** is how to present it. `/news` is the news in HTML. `/news.rss` is the
same news as RSS. `/news/news_page/2` is the second page of it.

The URL is a small query language written as a path. An RTO implementation
defines its grammar. Raster's is:

| URL | Meaning |
|---|---|
| `/about` | the page `about` |
| `/news/news_item/<slug or id>` | one item of the collection `news` |
| `/news/news_page/<n>` | page *n* of the collection |
| `/news/news_items/<field>/<value>` | the items where *field* is *value* |
| `/news.rss` | the page `news` in the format `rss` |

What the grammar can't express (joins, references between records) belongs
in objects, not in templates.

### 2.2 The template

A template is the page as it will look: real markup with real content in it.
It must render in a browser as a static file, so a designer can open it as a
mock-up and an agent can check it by looking.

The dynamic parts are marked with a **small, closed vocabulary** of
annotations that point at objects. In Raster these are HTML comments:

- `print.object.method`: replace this block with a value.
- `render.object.method`: repeat this block for each row.
- `remove`: mock-up content, always removed.
- `res` and `dry`: define a fragment and reuse it.

Templates contain **no general-purpose logic**. The vocabulary is fixed.
Anything more (conditions, formatting, access rules) is an object's job. A
closed vocabulary is what makes templates checkable: a tool can list every
object a template needs, find every mistake, and derive what data it will
store.

### 2.3 Objects

Objects wrap the logic that fetches and changes data: databases, files,
sessions, mail, other services. They return plain data: strings for single
values, lists of rows for repeated blocks, and `false` for "keep what the
template has".

## 3. Rules the pattern depends on

**3.1 The template owns the text.** Every word a person reads is in a
template: headings, error messages, confirmations, translations, emails.
Objects decide whether a block shows and which data fills it, but never write
prose. An error message is a block in the template that an object reveals. An
email is a template rendered for one recipient.

**3.2 The markup is the schema.** A template that points at stored content
declares that content: its name, its place and its default (the mock-up text).
An implementation can derive the storage from the templates. It must be able
to report the difference between what the templates declare and what is
stored.

**3.3 Inner blocks are evaluated first.** When blocks nest, the inner ones run
before the block around them. A form's validation messages therefore run
before the object that handles the form, and that object already knows the
result.

**3.4 Writes go through the template.** A form posts to the page it is on. The
object that renders the form also handles the post. When the post is invalid,
the object shows the form again with the submitted values. When it succeeds,
the object redirects back to the same page with a marker naming the outcome,
and the template shows the matching message. There is no separate write
endpoint or controller.

**3.5 Constraints live in the markup.** A form's inputs already describe what
they accept (`required`, `type`, lengths, ranges, patterns). The server
enforces the same constraints, so they are written once.

**3.6 Formats are templates.** A feed, a sitemap or a JSON document is a
template in that format, pointing at the same objects. Values are escaped for
the format. An email is a template too.

**3.7 Fail softly for visitors, loudly for authors.** At runtime, a missing
object or empty data leaves the template's own content in place: a visitor
sees a stale sentence rather than an error. While building, the same mistakes
are errors: unknown objects, unclosed blocks, forms nothing handles, messages
nothing reveals. An implementation should provide both behaviours.

**3.8 Extension through events.** The envelope announces the steps of a
request (route found, before render, before output). Objects can subscribe to
them for cross-cutting work such as access control, caching and
instrumentation, without changing templates.

## 4. Caching

Because templates declare their data and content changes go through known
objects, whole pages can be cached for anonymous readers. The cache is thrown
away whenever content changes. Requests with a session or a query are not
cached.

## 5. Standard objects

The pattern doesn't require them. An implementation should still offer these,
following the rules above: every one of them is written in templates, with
text owned by the template.

| Object | Provides |
|---|---|
| **cms** | Page fields and collections derived from the markup (3.2). It also provides site-wide fields, readable slugs, drafts, scheduled items, revision history, and filters or links by shared field values. |
| **validation** | Form constraints from the markup (3.5), message blocks, and outcome alerts. |
| **authentication** | Log in, sign up, reset by email, account, log out, roles, and protected pages. |
| **mail** | Emails as templates, over configurable transports. |
| **newsletter** | Double opt-in sign-up, confirm, unsubscribe, and sending any page as an issue. |
| **feed** | Items and pages for feeds and sitemaps (3.6). |
| **pagination** | Page links for collections and objects. |
| **i18n** | Translations, where the template's text is the default language. |

## 6. Agents

RTO suits artefacts made by software agents because the template is both the
design and the contract:

- An agent writes real HTML with real content, which it is good at, and
  annotates it.
- A checker reports every mistake with its position (3.7).
- The content model is derived, not designed (3.2), so a site can describe
  itself. Its pages, fields and collections can be exposed as tools, and an
  agent can edit content without editing code.

## 7. Where RTO stops

RTO is the wrong pattern when:

- the data model would be drawn before the pages;
- content has references between records and is reused away from its pages;
- the artefact is mostly interaction: multi-step flows, dashboards, state that
  lives across requests.

In those cases, write objects that do the work, or use a framework built for
applications.
