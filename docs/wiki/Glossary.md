# Glossary

**Admin, editor, member.** The three account roles. Members can log in, editors can also edit content, admins can do everything. See [Accounts and roles](Accounts-and-Roles).

**Alert.** A message block, `print.validation.alert('name')`, that stays hidden until a model raises it or the page loads with `?done=name`. See [Forms and validation](Forms-and-Validation#alerts-messages-for-the-whole-form).

**Annotation.** An HTML comment Raster understands, such as `<!-- print.cms.title -->`. See [Annotations](Annotations).

**App.** A folder with a site's config, models, views and data. `application/` by default; pick another with `RASTER_APP`.

**Bean.** RedBeanPHP's name for a database row as a PHP object. See [The database](The-Database#working-with-records-redbeanphp).

**Collection.** A CMS list of items with the same fields (news, products), declared by `render.cms.<name>`. See [The CMS](The-CMS#collections).

**CSRF (cross-site request forgery).** An attack where another website makes a visitor's browser submit a form on your site. Raster blocks it. See [Security](Security).

**Default (content).** The text between an annotation's opening and closing tags. It shows when the model returns `false` or `null`, and is the starting value of a CMS field.

**Development / production.** The two standard [environments](Settings-and-Environments#environments). Development is forgiving and shows errors; production is fast, cached and locked down.

**Draft.** A collection item with `enabled` set to 0; only editors see it.

**dry / res.** `res` names a fragment of a view; `dry` inserts it into another view. Used for shared headers and footers.

**Event.** A named announcement (`reservation.booked`) that other models can listen to. See [Events](Events).

**Flag.** A true/false value on the template, shown with `print.if.<flag>`.

**Fluid / frozen.** Whether the database structure may change by itself (fluid, development) or only through `raster schema --apply` (frozen, production).

**Honeypot.** A form field hidden from people; bots that fill it are ignored.

**Lint.** Checking templates for mistakes without running them: `php bin/raster lint`.

**MCP (Model Context Protocol).** An open standard for connecting AI assistants to tools. Raster has an MCP server. See [Working with AI agents](Working-with-AI-Agents).

**Mock-up content / sample content.** The realistic placeholder text in a template, which shows when there's no data and when the file is opened directly in a browser.

**Model.** A PHP class in `models/<name>/<name>.php` whose methods answer a template's annotations. See [Models](Models).

**Page field.** A CMS value stored per page, declared by `print.cms.<name>`.

**PHP-FPM.** The usual way PHP runs behind a web server such as nginx or Caddy.

**Print / render.** The two main annotations: `print` shows one value, `render` repeats HTML for each row of a list.

**Revision.** A saved version of a page's fields. Every save makes a new one.

**Route.** A rule in `config/the_routes.php` that sends a URL to a differently named view.

**RTO (Request, Template, Object).** The pattern Raster implements: a request picks a template, and the template pulls data from objects (models).

**Schema.** The structure of the database: tables and columns. In Raster, the templates define it.

**Site-wide field.** A page field whose name starts with `site_`; one value shared by every page.

**Slug.** A readable identifier for a URL, made from a title: `our-new-office`.

**SQLite.** A database stored in a single file, used by default.

**Theme.** A folder of views and assets in `views/`. `default` unless you set `theme`.

**View.** A template file: `.html`, `.rss`, `.xml`, `.atom`, `.json` or `.txt`.
