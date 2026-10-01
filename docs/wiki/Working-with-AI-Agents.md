# Working with AI agents

Raster is designed so that an AI assistant (Claude, ChatGPT, Cursor, Copilot and similar tools, called **agents** here) can build and maintain a site safely alongside people. This page explains what Raster gives agents, and how to connect one.

## What agents get

- **`AGENTS.md`**, the complete specification in one file, at the project root. Many coding agents read it automatically. `CLAUDE.md` points Claude Code at it.
- **Answers read from the code**, so they can't be out of date: `raster describe` (the whole site in one answer), `raster vocabulary` (every model and method a template may call, with parameters), `raster annotations` (the template grammar).
- **Checks before changes**: `raster lint` finds mistakes in templates with file and line; over MCP, `write_view` refuses to save a template that doesn't pass lint, so a made-up model or method name can't land in your site.
- **An MCP server**, so an agent can do all of this through tools rather than by guessing.

## What is MCP

The **Model Context Protocol** (MCP) is an open standard for connecting AI assistants to tools and data. An MCP **server** offers a list of tools (each with a name, a description and typed inputs), and an MCP **client** (the assistant's app) lets the model call them. Raster includes an MCP server for your site.

It can run in two ways:

### Over standard input/output, for agents on your computer

```sh
php bin/raster mcp
```

The client starts this command itself and talks to it through its input and output. The project's `.mcp.json` already declares it, so clients that read that file (such as Claude Code) find it when you open the project:

```json
{
  "mcpServers": {
    "raster": { "command": "php", "args": ["bin/raster", "mcp"] }
  }
}
```

For other clients, add a server with the command `php` and the arguments `bin/raster mcp`, run from the project folder. Set `RASTER_APP` or `RASTER_ENV` in its environment if needed.

### Over HTTP, for agents elsewhere

The endpoint is `POST /mcp` on your site. It's **off** until you set a token:

```sh
RASTER_MCP_TOKEN=a-long-random-string-of-at-least-32-characters
```

(or `config::set('mcp_token')->to(…)`, but keep secrets out of git). Clients send it as a header: `Authorization: Bearer <token>`. Anyone with the token can edit your content, so treat it like a password; `doctor` fails in production if it's shorter than 32 characters.

A quick test:

```sh
curl -s https://example.com/mcp \
  -H "Authorization: Bearer $RASTER_MCP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## The tools

### Working on the site itself

| Tool | Does |
|---|---|
| `describe` | the whole site in one answer: how URLs reach views, the pages and collections the markup declares, the vocabulary, the settings that change behaviour, whether templates lint clean. The first thing to ask. `sections` narrows it. |
| `vocabulary` | every model, its methods and their parameters; named SQL queries; events and listeners; reserved names |
| `annotations` | the template grammar, the same data `lint` checks against |
| `list_views`, `read_view` | list and read templates |
| `check_view` | lint a template that isn't saved yet; nothing is written |
| `write_view` | save a template, **only if it lints without errors**; otherwise the file is untouched and the problems come back. The answer says what the change does to the content model. |
| `render_url` | a page's status and HTML, without a web server. Runs in its own process, so a broken page can't crash the MCP server. Never reads the page cache. |
| `clear_cache` | throws the [page cache](Settings-and-Environments#the-page-cache) away, after views, theme files, models or the database were changed without Raster's own tools. In production, visitors who aren't logged in see cached pages until then. |

### Working on content

| Tool | Does |
|---|---|
| `site_overview` | pages with their editable fields, collections with their fields and item counts, and which models listen to which events |
| `get_page`, `update_page`, `page_history` | read and change a page's fields; every update is a new revision |
| `list_items`, `get_item`, `create_item`, `update_item`, `delete_item` | work with collection items |
| `lint_templates` | check every template |
| `schema_status` | compare templates and database |

Pages are addressed by URL (`/about`), by view name (`about`), or `site` for the site-wide `site_*` fields.

## Safety rules built in

- **Agents can only write fields the templates declare** (plus `slug`, `enabled` and `published_at` on items). An agent can't invent a field: to add one, it has to add an annotation to a template, which a person can review.
- **Content changes go through the same code as the in-page editor**: page revisions are kept, [events](Events) fire, the page cache is cleared.
- **Writing templates is a bigger permission** than writing content, because a template can call any model. Over standard input/output the agent already has your files, so `write_view` is available. Over HTTP it's **not offered** unless you allow it:

  ```php
  config::set('mcp_write_views')->to(true);
  ```

- `describe` never includes secrets: the MCP token and the mail transport (which may hold an SMTP password) are left out.

## A good way to work with an agent

1. Ask it to run `describe` (or `php bin/raster describe`) first.
2. For template changes: write the page as static HTML with real content, then annotate it, checking names against `vocabulary`.
3. After each change: `lint`, then `render_url` (or `php bin/raster render /the-url`) to see the result.
4. Check `schema` shows the content model you meant.
5. Review the diff like any other code change.

The checklist at the end of `AGENTS.md` is the same list, written for the agent.
