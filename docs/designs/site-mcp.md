# The site's MCP server

Status: draft for approval, 2026-10-18.

## Why

A site's owner already talks to Claude or ChatGPT all day. They should be
able to say "change the text above the phone number in the hero" or "what
are today's bookings with Ana?" and have it happen on their site, from the
desktop app or their phone, without opening the editor and without an agent
running on the server. A member should be able to ask their own assistant
to move their booking.

Every Raster site gets this by being a Raster site. The tools it offers are
read from the site itself (its pages, its record types, its models), so they
grow as the site grows and never go stale. That is the same promise as
"the markup is the schema": nobody writes or maintains a tool list.

Raster already has an MCP server (`system/models/mcp/mcp.php`). It is for
the agent *coding* the site: views, lint, describe, write_view, plus content
and record tools that trust the caller like an admin. It stays as it is.
This design adds a second, separate server for *running* the site: its
content and its data, as one signed-in person. The two share protocol code
but no endpoint, no credential and no tool list.

## Goals

- **Connects like any connector.** The owner adds `https://their.site/mcp`
  in Claude (desktop, web, mobile) or ChatGPT, is sent to the site's own
  login, approves, and it works. Remote MCP over Streamable HTTP with OAuth,
  as those clients expect. No token to copy.
- **Acts as the person, never more.** Every call runs as the user who
  approved it, with their role (member, editor, admin) and ownership, through
  the same `authentication::can()` and `cms_records` visibility rules as the
  site's pages. A member's agent sees and changes only their own records.
  Demoting or deleting a user takes effect on their next call.
- **Content by where it sits.** An agent can find a field from the page's
  shape ("in the hero, above the phone number") without reading templates:
  `get_page` returns an outline of the page's editable fields in order,
  grouped by section.
- **Records and model features appear on their own.** Every record type a
  site has (its own or bundled, like `booking`) gets the generic record
  tools. A model can add its own tools with a small `static function mcp()`,
  in the style of `api()` and `types()`.
- **Small catalog.** `tools/list` holds only what this site has and this
  person may use: about a dozen tools, however many models a site has.
  Details of a type come from `describe_type` when the agent needs them.
- **Live right away, with undo.** Agent edits are live at once. Page
  changes can be reverted from page history. Record deletes are soft and
  can be restored, for every writer, not only agents.
- **Uploads are recorded.** Every upload (the editor's and the agent's) gets
  a row in one `upload` table, so a media library can be built on it later.
  Agents can add a picture from an address.
- **Correct, checked by the spec's own tests.** The official MCP conformance
  suite runs in CI.

## Non-goals (for now)

- Building the site: new pages, views, models, design. That is the coding
  MCP's job, over stdio, for the agent that writes code.
- Visitors without an account. The public site is already there for them.
- Managing users and roles over MCP (inviting staff, changing roles). It
  can come later as admin tools; it needs its own care.
- A media library screen. Only the table it will read.
- Emptying the trash on a schedule. Raster has no scheduler yet; until
  then an admin empties it.
- Prompts and resources (MCP's other two features). Tools only.
- Tools generated from `api()` methods. They were written for browser
  widgets (positional string arguments, often no docblock, no read/write
  marker). A model that wants a method on MCP says so in `mcp()`.

## Anti-goals (a failure even if it works)

- An agent can do something over MCP that the same person couldn't do on
  the site.
- A site has to write or keep a tool list, labels for fields, or any MCP
  configuration for ordinary use.
- A site with ten models sends ten times the tool definitions.
- A page request (a visitor's view) loads any OAuth or MCP code
  (`tests/run.php:222` stays true).
- A field that a type marks `hidden` (a token, a password hash) shows up in
  an MCP answer; or an email, phone or token shows up in a log or the page
  cache.
- Text that a visitor or member typed (a booking note) is handed to the
  owner's agent as if the site said it.
- An agent's delete loses data with no way back.

## What already exists and is reused

| Piece | Where | Used for |
|---|---|---|
| JSON-RPC over HTTP and stdio | `system/models/mcp/mcp.php` | protocol handling for both tool sets |
| Users, roles, `can()` | `system/models/authentication/authentication.php:33`, `:265` | the caller's rights |
| Login page | site's `login.html`, or Raster's plain `/login` | sign-in during OAuth |
| Hashed one-time tokens | `login_hash` pattern, `authentication.php:291-335` | the model for storing secrets |
| Content model | `system/tools/inspector.php:1275` (`content_model()`) | the list of pages and fields |
| Editor marks | `system/models/cms/editor.php:36`, `:73-98` | finding fields in a rendered page |
| Saving | `cms_store` (`system/models/cms/store.php`) | every write: checks, revisions, events, cache |
| Records | `cms_records` (`system/models/cms/records.php`) | types, owner, hidden, actions, visibility |
| Uploads | `cms_editor::upload()` (`system/models/cms/editor.php:322`) | its checks and naming, moved into one function every upload path calls |

## The two servers

| | Coding MCP | Site MCP (new) |
|---|---|---|
| For | the agent writing the site | the owner's, staff's or member's assistant |
| Transport | stdio (`php bin/raster mcp`); HTTP at `/mcp/dev` | HTTP at `/mcp` |
| Credential | none (stdio) / the static `mcp_token` (HTTP) | OAuth access token for one user |
| Tools | today's tools, unchanged (their deletes become soft, like every delete) | the ones below |
| On by default | stdio yes, HTTP only with a token | yes, when the site has users |

Decision (my call): `/mcp` becomes the site MCP, because that is the address
an owner types. The coding tools over HTTP move to `/mcp/dev` with the same
`mcp_token`. This breaks anyone using the HTTP coding endpoint today; the
CHANGELOG says so. Raster Builder uses stdio only. A site turns the site MCP
off with `config mcp => false`.

The protocol code (`handle()`, results, errors) is shared. Each server is
just a different tool list and a different way of deciding who is calling.
Writes from either are recorded with where they came from (`mcp-dev`, or
`mcp` and the client).

## Signing in: OAuth

### Library

**thephpleague/oauth2-server**, vendored with its dependencies into
`system/libraries/oauth2/`, committed and pinned, with a small autoloader.
There is still no Composer step for a site, as with `rb.php`. The library is
loaded only by `/oauth/*`, `/.well-known/oauth-*` and `/mcp`. The commit that
adds it lists every package and version and why (the project rule for
dependencies). `raster update` carries it like the rest of `system/`.

It needs PHP 8.2 or newer, `ext-openssl` and `ext-sodium`. Raster still
runs on 8.1; on 8.1, or without those extensions, `/mcp` answers 503 with a
plain reason and `raster doctor` says what is missing. The builder checks
which `lcobucci/jwt` line still supports 8.2 when pinning; if none does, the
floor for the site MCP becomes that version's, and this section is updated.

Raster writes only what the library doesn't do, all small:

- `/.well-known/oauth-protected-resource` (RFC 9728) naming the site as its
  own authorization server.
- `/.well-known/oauth-authorization-server` (RFC 8414).
- Client ID Metadata Documents: the client's `client_id` is an HTTPS URL;
  Raster fetches it, checks it, and keeps it for a day. This is the main
  path in the MCP authorization spec (2026-07-28).
- Dynamic client registration (RFC 7591) at `/oauth/register`, kept because
  that spec still allows it for older clients. The builder checks what
  Claude and ChatGPT use today and keeps both only if both are needed.
- The consent page and the list of connected agents (below).
- A league repository set over RedBean.

### The flow

1. The client calls `/mcp` without a token and gets `401` with
   `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"`.
2. It finds the authorization server, identifies itself, and sends the
   person to `/oauth/authorize` with PKCE (S256 only) and
   `resource=https://their.site/mcp` (RFC 8707).
3. Not logged in: the site's normal login page, then back. Logged in: the
   consent page: which app ("Claude"), which site, as whom and with what role,
   and what it will be able to do. Approve or deny.
4. Authorization code, then access token (short, about an hour) and refresh
   token (rotated on every use, revoked as a family on reuse). Tokens are
   bound to this site's `/mcp` and refused anywhere else.

Stored: one `oauthclient` row per client, one `oauthgrant` row per person and
client (created, last used, revoked), and access and refresh token ids. A
secret that could be replayed is stored only as a hash, as login links are.
The site's signing and encryption keys are generated on first use into
`data/oauth/` (never served, never in a backup that leaves the site, never
in logs), and `raster doctor` checks their permissions.

### Pages the site owns

Raster has no screens of its own; the template owns every word. So:

- **Consent**: a site may write `oauth.html`; if it has none, Raster serves a
  plain one, as it does for `/login`.
- **Connected agents**: `print.authentication.agents` lists the person's
  grants with a revoke button, for the account page. Admins also see
  everyone's (`render.authentication.agents('all=1')`). The starter site's
  `account.html` gets it.

### Who is calling

Every `/mcp` request resolves its token to a user and makes that user the
current user for the request only (no session, no cookie), so
`authentication::user()`, `can()` and `cms_records::visibility_sql()` work
unchanged. The role is read from the `user` row on every request, never
from the token. The writer `cms_store` records is `editor` when the user can
edit and `visitor` otherwise, so a member's booking goes through the same
`check()` as the guest form. Every write also records that it came from MCP
and which client sent it.

**Members change records only through what the model offers** (decided).
A member's agent may find, get and create records in the types visible to
them, and run the actions and `mcp()` tools that allow `owner`. The generic
`update_record`, `delete_record` and `restore_record` are for editors only.
The model keeps its own rules (a guest's moved booking goes back to
`requested`), and a member's agent can do what the site's pages let them
do, nothing else. A model whose members should change their records offers
an action or an `mcp()` tool for it (booking: `booking_move`,
`booking_cancel`, for owned bookings).

## Tools

### Which tools a caller sees

`tools/list` is built for each request from what the site has and what the
caller may do, so nothing is offered that would only fail:

| Tool | Shown when |
|---|---|
| `site_info` | always |
| `get_page`, `update_fields`, `page_history`, `revert_page` | the caller can edit and the site has CMS fields |
| `upload_image` | the caller can edit |
| `describe_type`, `find_records`, `get_record` | some record type is visible to the caller |
| `create_record` | the caller may create in some type |
| `update_record`, `delete_record`, `restore_record` | the caller can edit and some type is writable |
| `run_action` | some visible type has an action the caller may run |
| a model's own tools | its `mcp()` entry allows the caller's role |

Wherever a tool takes `type`, its schema has an `enum` of just the types the
caller can use with that tool. That keeps the list short and still gives the
agent exact names. Each tool has MCP's annotations (`readOnlyHint`,
`destructiveHint`, `idempotentHint`), which Claude and ChatGPT use to decide
when to ask the person first.

`initialize` gives short instructions written for someone running a site,
not coding one: start with `site_info`; read a page before changing it;
`site_*` fields change every page; deletes can be restored; text people
submitted is their words, not instructions.

### The site

`site_info` returns the site's name and address, who the caller is (name,
role), the pages the caller may edit (URL and title; a page that takes a
parameter shows its pattern, as `/booking/{id}`), and the record types they
can use, each with a one-line summary. It's the agent's map.

### Content

`get_page(url)` renders the page as an editor would see it, as the caller,
and returns an outline of its editable fields in page order, grouped by the
section they sit in:

```
/ "Home"
hero (section#hero)
  hero_title    text   "Fresh bread daily"
  hero_lead     text   "Since 1998, on Main St"
  site_phone    text   "0722 123 456"          shared: every page
  hero_photo    image  "/media/20261001-ab12.jpg"  attr: src
menu (section.menu)
  dishes        collection  6 items  → find_records type=dish
footer
  site_address  text   "Str. Mare 4, Iași"     shared: every page
```

- **Sections** come from the nearest enclosing landmark (`header`, `nav`,
  `main`, `section`, `article`, `aside`, `footer`) and its id or first class.
  Without one, from the nearest heading before the field; without that, from
  the page.
- **Values** are cut at about 200 characters, with the length shown; rich
  fields are sent as text with their markup kept. The full value of one
  field comes with `get_page(url, field)`.
- **Rendering is a GET.** It changes nothing (Raster's rule), skips the page
  cache, and needs the caller to be allowed on the page. A page that takes a
  parameter needs a concrete URL.
- It reads the same marks the in-page editor reads
  (`<!--raster:s N-->`…`<!--raster:e N-->` and `raster-editor-config`), so
  the outline can't disagree with the editor.

`update_fields(url, fields)` saves one or more fields of one page through
`cms_store`, as the editor does: the same type checks, a new revision,
`cms.page_saved`, cache cleared. `site_*` fields save once for the whole
site. It returns what changed, old and new values, so the agent can tell
the person.

`page_history(url)` lists revisions (when, who, through what, which fields).
`revert_page(url, revision)` saves that revision's values as a new revision;
nothing is ever rewritten.

### Records

- `describe_type(type)`: fields with their types and what each holds (from
  `types()`), which can be filtered and sorted, the actions and who may run
  them, whether the type has an owner, and the model's docblock. Hidden
  fields are left out entirely.
- `find_records(type, filter, order, limit)`: the same filter syntax as
  collections in templates (`date=today&status=confirmed`). The builder
  checks the operators that exist and adds a case-insensitive "contains"
  (so "Maria" finds "Maria Popescu") if there isn't one. `today`,
  `tomorrow` and relative dates use the site's time zone. Results are
  limited (default 50) and say when there are more.
- `get_record(type, id)`, `create_record(type, fields)`,
  `update_record(type, id, fields)`: through `cms_records`, so `check()`,
  `fill()`, owner, readonly and hidden all apply; problems come back as
  their names (`fully_booked`), which the agent can explain.
- `delete_record(type, id)` is soft (below); `restore_record(type, id)`
  brings it back, running `check()` again (a restored booking whose slot was
  taken meanwhile is refused with that problem).
- `run_action(type, id, action, input)`: a `types()` action, checked
  against the action's role. Today the coding MCP's `run_action` is "trusted
  like an admin"; the site MCP's never is.

**Text people typed.** Every record a visitor or member wrote comes back
with `"submitted_by": "visitor" | "member"`, and its free-text fields inside
a `submitted` object, so the agent and the client can tell the site's words
from a guest's. The server's role checks are what actually protect the site;
this keeps the agent from being talked into something the person can do but
didn't ask for.

### A model's own tools: `mcp()`

Most models need nothing: their record types already give them tools. A
model that has something more useful to offer declares it, in the style of
`api()`:

```php
// "Today's bookings with Ana" in one call
static function mcp() {
	return array(
		'day' => array(
			'role' => 'editor',
			'says' => 'Bookings for one day, in time order, optionally for one resource.',
			'reads' => true,
			'takes' => array('date' => 'date', 'resource' => 'text?'),
		),
	);
}
```

- The key is the method's name. The tool is `<model>_<method>`
  (`booking_day`).
- `role` is the least role allowed, as in `api()`. `owner` is also accepted,
  which means a member may call it and the method must keep to their own
  records.
- `says` is the description. Required: no description, no tool (`lint`
  says so).
- `reads => true` marks a tool that changes nothing. Without it the tool is
  treated as a write and annotated so.
- `takes` maps argument names to Raster's field types (`text`, `int`,
  `number`, `bool`, `date`, `datetime`, `time`); a trailing `?` makes one
  optional. The method gets one array with those keys, already cleaned by
  type, as actions get `$input`.
- What it returns is sent as JSON. A problem name it returns or throws
  through `cms_records::refuse()` comes back as an error the agent can read.

`lint` checks `mcp()` entries the way it checks `api()`: the method exists
and is public, the role is known, `says` is there, `takes` uses known types.
`site_info` and `tools/list` pick models up the same way `types()` are
found, so a bundled model like `booking` offers its tools on exactly the
sites that use it, and nowhere else.

The booking plan (`docs/plans/2026-10-18-booking.md`) is the first user.
Its `agenda($options)` takes an options string for templates, so booking
adds a small `day($input)` that calls it, declared in `mcp()` as
`booking_day` (my call: the `mcp()` convention stays one cleaned array, and
template methods aren't bent to fit it). Release 3 builds on booking B2
being merged; if it isn't yet, release 3 ships `mcp()` proven on the demo's
own model and booking's tools follow in booking's own PR.

**Staff see guests' contact details** (decided). An editor's agent sees
what the editor shows that editor, emails and phones included, so "call
Maria about her booking" works. `hidden` fields (booking's tokens) never
appear. Contact details stay out of the MCP call log and are never cached.
Because these answers pass through the assistant's provider (Anthropic,
OpenAI), a site's privacy page should say staff may use AI assistants to
view bookings and messages; the starter site's privacy text and AGENTS.md
say so.

## Soft delete

Deleting an item, from any writer (editor, either MCP, model code, CLI),
sets `deleted_at` and `deleted_by` instead of removing the row. That holds
for record types and for plain template collections alike, because both
delete through `cms_store`.

It is enforced in Raster's own layer, which is where Raster code reads
items: `cms_store`, `cms_records` (`find`, `get`, `listed`, `visibility_sql`
and the collection queries behind `render.cms.*`), counts, and the SQL files
of bundled models (booking's capacity query among them). Site code that
reads a table itself with `R::find` or its own SQL is the site's to adjust;
AGENTS.md says so, and `lint` warns where it can see a model reading a
record table directly. A deleted row keeps its slug, so `unique_slug` still
counts it and a restore never collides. `restore_record` and an
editor "Restore" bring a row back through `check()`. An admin empties the
trash (`raster trash --empty`, and in Admin); emptying on a schedule waits
for the scheduler. `cms.item_deleted` still fires on delete, and a new
`cms.item_restored` on restore.

Pages already keep every revision. Revisions today hold only the values
and `updated_at` (`system/models/cms/store.php:291-322`), so they gain `by`
(user id), `via` (`editor`, `mcp`, `mcp-dev`, `cli`, `model`), `client` (the
MCP client's name, when there is one) and `changed` (the fields that
changed), for `page_history` and for the owner to see what an agent did.

## Uploads

One table, `upload`: url, path, mime type, bytes, width and height, sha256,
who uploaded it, through what (editor, MCP, form), when, and an optional
alt text. The checks and naming in `cms_editor::upload()` move into one function
that takes a file path and who sent it; the editor's `$_FILES` upload,
`POST /uploads` and `upload_image` all call it and it writes the row, so all
uploads are recorded whichever way they came. A media library later lists
this table; nothing else is built for it now.

- `upload_image(url | data, alt)` on the site MCP: from an `https` address
  that Raster fetches itself, or from base64 for a small picture (the
  builder picks the limit, about 2 MB). Same checks as the editor: JPEG,
  PNG, GIF or WebP, up to 12 MB, sized by `getimagesize`. It returns the
  URL to put in an image field with `update_fields`.
- Fetching an address must not be a way into the site's own network: only
  `https`, the name resolved once and refused if it is a private, loopback
  or link-local address (IPv4 and IPv6), no redirects to such addresses,
  a short timeout, the size checked while reading.
- `POST /uploads` is the plain endpoint behind both: multipart, with either
  a session and CSRF token (the editor) or a bearer token (an agent or app).

## Protocol details

- Streamable HTTP, `POST /mcp` with JSON answers (no SSE stream is needed
  for tools that answer at once); `GET /mcp` answers 405. Both the
  2026-07-28 and 2025-11-25 revisions are added to the versions Raster
  answers (today it knows only 2025-06-18 and older, `mcp.php:21`), for both
  servers. The client's version is used when known; otherwise the newest.
  The older ones stay as long as the conformance suite and the Claude and
  ChatGPT checks pass with them. No server-side session is kept.
- The `Origin` header is checked when present (the spec's DNS-rebinding
  rule), and the token's `resource` must be this site's `/mcp`.
- Answers are never cached and send `Cache-Control: no-store`.
- Tool errors come back as `isError` results with a readable message, as
  `call_tool` does today; protocol errors as JSON-RPC errors.
- MCP calls are logged (when, user, client, tool, target, outcome) without
  arguments' values, so an owner can see what an agent did.

## Testing

- Plain PHP tests in `tests/`, in the existing style, for: token to user to
  rights; a member's agent can't see another member's record; demotion takes
  effect on the next call; the outline for the starter site's pages; edits
  produce revisions and revert works; soft delete hides rows everywhere,
  including booking capacity; `mcp()` discovery and `lint`; hidden fields
  never in answers; the upload address checks.
- The official MCP conformance suite
  (`github.com/modelcontextprotocol/conformance`) runs in CI against a test
  site. It's a test tool only, never shipped.
- Manual check before release: add a test site as a connector in Claude and
  in ChatGPT, run the hero and the bookings examples end to end, revoke the
  grant and see the next call refused.
- The demo café gets a feature ID in `demo/README.md` for "managed over MCP".

## Releases

Each is its own PR and release, in order:

1. **Sign in.** Vendored league, the OAuth endpoints and metadata, consent
   and connected-agents pages, token to user, `/mcp` with `site_info` only,
   coding tools moved to `/mcp/dev`.
2. **Content.** `get_page` with the outline, `update_fields`,
   `page_history`, `revert_page`.
3. **Records.** Soft delete everywhere, revision authors, the record
   tools, filters, `submitted`, `mcp()` and its `lint`, booking's `day`.
4. **Uploads.** The shared upload function, the `upload` table,
   `POST /uploads`, `upload_image`.

## For Raster Builder (not designed here)

What the builder will need, to be designed in its own repo:

- Published sites answer `/mcp`, `/oauth/*` and `/.well-known/oauth-*`
  through the edge app, on their own domain and default address.
- Preview sites on the home machine stay behind the router's session, so
  the site MCP is for published sites only.
- `upload_image` fetching from the internet means a published site makes
  outbound requests; the builder decides if that's allowed from its
  machines.
- A place in the builder showing the owner their site's MCP address, how to
  add it in Claude and ChatGPT, and their connected agents.
- Sites' privacy text mentions AI assistants used by staff (the decision on
  guest contact details); the builder's own /privacy at www.sibip.io is
  unaffected, since this data flows from the site to the owner's assistant,
  not through the builder.

## Left to the builder of this design

- The exact league version set, and the autoloader.
- Whether dynamic client registration stays (depends on what Claude and
  ChatGPT use when it's built).
- Token lifetimes within the limits above.
- The filter operators' exact syntax for "contains".
- The base64 size limit for `upload_image`.
- How the outline names a section that has neither id nor class.
- The wording of the instructions in `initialize` and of each tool's
  description (short, and written for running a site).
