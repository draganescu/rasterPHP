# Raster style guide

Raster is small on purpose. It has lasted ten years because there is little
of it. This guide says how its code is written, so that new code looks like
the old code and nobody "modernises" it.

It was written from the code as it is. Where the code disagrees with itself,
the guide names the form most of it uses. Follow that form in new code, and
leave old lines alone unless you are changing them anyway.

AGENTS.md is the specification. This file is only about how the code reads.

## Principles

- **Spartan.** Lowercase class names, short methods, arrays, plain functions.
  No layers that only pass a call along.
- **One obvious way.** If Raster already does a thing (config, events, SQL,
  errors, escaping), use that way. Don't add a second one beside it.
- **Delete before you add.** The best change often removes code. A new
  setting, class or file needs a reason a visitor or an editor would notice.
- **Plain PHP, nothing to install.** No Composer packages, no build step. The
  one library is RedBeanPHP, in `system/libraries/rb.php`. Do not add another.
- **The template owns the text (RTO).** Models decide what shows and return
  data. Words people read live in the views, the alerts and `i18n/`.
- **The code reads like what it does.** `database::instance('cafe')->count_category($c)`,
  `util::done('sent')`, `config::set('protected')->to(...)`.

## Naming

Everything is lowercase with underscores (snake_case). There is no camelCase
method in `system/` and one camelCase variable (`$removesStarts`).

- **Classes** are the model's name: `cms`, `mail`, `cart`, `order`. One word
  when it can be. No suffixes like `Service`, `Manager`, `Handler`, `Helper`.
- **Helpers** of a model are `<model>_<name>` in `models/<model>/<name>.php`:
  `cms_records`, `cms_store`, `order_payments`.
- **Framework tools** used by the command line are `raster_<name>` in
  `system/tools/<name>.php`: `raster_inspector`, `raster_schema`.
- **Overrides** are `the_<name>`, in `models/the_<name>/the_<name>.php`,
  and extend the class they replace. That is the only use of `extends` in a
  site, apart from exceptions.
- **Files and folders** are lowercase: `models/<name>/<name>.php`,
  `models/<name>/sql/<query>.sql`, `views/<theme>/<page>.html`,
  `_layout.html` and `_email/` for what is never a page.
- **Methods** say what they give back or what they do, briefly: `types()`,
  `info()`, `find()`, `is_private()`, `send_view()`, `content_changed()`.
  Predicates start with `is_` or `has_`. No `get` prefix when the noun alone
  is clear.
- **Variables** are short words: `$file`, `$rows`, `$info`, `$e`, `$m` for
  regex matches. Don't spell out a type in the name (`$userArray`).
- **Config keys** are snake_case strings: `page_cache_ttl`, `login_page`,
  `<collection>_page_size`.
- **Events** a model sends are `<model>.<what happened>`, in the past tense:
  `reservation.booked`, `newsletter.confirmed`. The request's own events are
  older single words (`launch`, `before_render`) and stay as they are.
- **Alerts and problems** are snake_case names the template words:
  `fully_booked`, `email_taken`, `orders_are_kept`.
- **Commands** are one lowercase word, with `--long-options`: `lint --fix`,
  `schema --apply`, `cache clear`.

## PHP

- **PHP 8.1 or newer** (`doctor` checks it). Using 8.1 does not mean using
  all of it. Readonly properties, enums, `match`, arrow functions, named
  arguments and attributes appear nowhere; keep it that way.
- **`array()`, never `[]`.** About 1,300 uses of `array()` and no `[]` literals.
  `$list[] = $x` to append is fine.
- **No type declarations** on parameters or return values. There are none in
  the code. Cast where it matters instead: `(string)$value`, `(int)$id`,
  `(array)$options`.
- **No namespaces, `use`, interfaces, traits, abstract classes or `final`.**
  None exist. Classes are found by name (`boot::autoload_models`).
- **Static methods** are the default (about 280 of them against 150 instance
  methods). Instance methods are what templates call (`render.cafe.hours`),
  plus the singletons (`config`, `template`, `database`, `validation`).
  Hooks the framework calls (`types`, `check`, `api`, `listens`, `schema`,
  actions) are static, so `/api` can never reach them.
- **Visibility keywords:** leave them off. Most methods have none
  (`static function`, `function`). Write `protected` only when the point is
  to hide something; never write `public`. Properties are `static $x` or
  `public $x` as the file around them does.
- **Indentation is tabs.** Braces open on the same line as the `function`.
  Classes of bundled and demo models open their brace on the next line;
  framework classes open it on the same line. Follow the file you're in.
- **Spacing:** `if (`, `foreach (`, `function (`; concatenation without
  spaces, `'Query: '.$query`. Single quotes unless you interpolate:
  `"$dir/$folder.php"`.
- **Short ifs on one line**, without braces, are normal:
  `if (!is_file($file)) return array();`. Prefer early returns to nesting.
- **Compare strictly**, `===` and `!==`. Use `elseif`, not `else if`.
- **Missing keys:** `isset($a['k']) ? $a['k'] : 'default'` is the usual form.
  `??` is allowed (PHP 8.1) but rare; don't rewrite one into the other.
- **Closures** are fine where PHP wants a callback, and for reading a file in
  isolation (`database::setup`). Don't build objects from them.
- Constants are class `const`, upper case: `const SEATS = 20;`.

## Where code goes

- `system/` is the framework. Sites never edit it; they override.
- `application/models/<name>/<name>.php` holds `class <name>`. That is the
  only place a site's PHP lives, apart from config and `the_util.php`.
- SQL goes in `models/<name>/sql/<query>.sql` and runs as
  `database::instance('<name>')-><query>(...)`. RedBean is for one row as an
  object (`R::dispense`, `R::store`, `R::load`). An inline `query()` is for
  SQL the code has to put together, such as DDL in `tools/schema.php`.
- Records go through `cms_records`, not straight to RedBean.
- Settings are read with `config::get('name', $default)` and set with
  `config::set('name')->to($value)`. No config objects, no `.env` parser.
- Models talk through `event::dispatch()` and `listens()`, not by holding
  references to each other. They may also just call each other
  (`mail::send_view(...)`): models load on first use.
- **A new file** is for a new model, a new `<model>_<name>` helper once the
  model is long, a new query, or a new command's tool in `system/tools/`.
  One class per file is the rule; a small exception class next to the code
  that throws it (`cms_refused`) is fine.

## Comments

- `//` line comments, a sentence or two, saying **why** or what a value
  means: `// a day holds SEATS guests; cancelled bookings free their seats`.
  Most short methods have one line above them, or none.
- A file starts with a few lines on what it is for, often with a usage
  example indented two spaces. Bundled models use a `/** ... */` block for
  this; everything else uses `//`.
- `// #Title` and `// ##Section` split long files (`// ##Writing, for the
  model's own code`). They come from the annotated-source docs.
- **No `@param`, `@return` or `@var` tags.** There are none. If a signature
  needs explaining, write one plain sentence.
- Plain words, short sentences, the voice of AGENTS.md. Name the file or the
  method a reader should look at. No marketing, no "simply", no "robust".

## Docs and releases

- **AGENTS.md is the spec.** A change to behaviour changes AGENTS.md in the
  same commit. If the two disagree, the code is wrong.
- Command help lives in `bin/raster` (the `HELP` text and the comment at the
  top). Add a new command to both.
- `CHANGELOG.md` gets a `## <version>` section: first what sites must do
  ("Nothing for sites to do."), then one bold-titled paragraph per change,
  written for the people running sites.
- To release, bump `system/VERSION`.

## Errors

- **For templates,** `false` or `null` means "show the mock-up", and an
  empty array renders nothing. Models don't print errors into the page.
- **For forms and records,** a problem is a name: `$problems[] = 'too_many'`,
  `cms_records::refuse('name')`, `validation::get()->raise('name')`. The
  template words it.
- **For code mistakes,** throw a built-in exception with a message that says
  what to do: `InvalidArgumentException`, `RuntimeException`,
  `BadMethodCallException`. No custom exception hierarchy.
- **What must not stop the request** is caught and logged:
  `log::warning('authentication: '.$e->getMessage())`.
- The command line prints `error: ...` to STDERR and exits 1 (2 for usage).

## Security defaults

- Escape what people typed: `util::e($value)` in HTML views. Feeds and JSON
  views are escaped by the engine.
- SQL values are bound (`?` or `:name`), never pasted in.
- Forms get the honeypot, the site check and the session token from the
  framework. Don't hand-roll them in a model.
- `/api` reaches only what `static function api()` lists, with a role.
- Passwords with `password_hash`; comparisons of secrets with `hash_equals`.
- What the server may serve is listed once, in `system/private_paths.php`.

## Tests

```sh
php tests/run.php      # framework
php tests/demo.php     # the demo café, every feature
php tests/update.php   # new, update, upgrade, doctor
php tests/shop.php     # records, checkout, payments
```

- Tests are plain PHP with `test()`, `check()` and `same()`. No PHPUnit.
- A new feature gets an ID in `demo/README.md` (`A1`, `A2`, ...), a use in the
  demo and a test.
- A change sites must follow gets an upgrade step in
  `system/upgrades/<version>.php`, and what it replaces gets an entry in
  `system/tools/deprecations.php` that keeps working until the version named.

## Don't

**Don't add the enterprise layer.**

```php
// no
namespace Raster\Models;
final class ReservationService implements RecordTypeInterface {
    public function __construct(private CmsRecords $records) {}
    /** @param array<string, mixed> $booking */
    public function cancel(array $booking, array $input): array {
        return $this->records->update('reservation', $booking['id'], ['status' => 'cancelled']);
    }
}

// yes (demo/models/reservation/reservation.php)
static function cancel($booking, $input) {
    return cms_records::update('reservation', $booking['id'], array('status' => 'cancelled'));
}
```

**Don't put SQL in strings, or wrap the database.**

```php
// no
$rows = R::getAll('SELECT COUNT(*) AS total FROM dish WHERE category = ?', [$category]);
$rows = $this->dishRepository->countByCategory($category);

// yes: the query is models/cafe/sql/count_category.sql
$rows = database::instance('cafe')->count_category($category);
```

**Don't write the page's words in a model.**

```php
// no
return array('message' => 'Thanks, we got your booking!');

// yes: the alert's text is in the view
util::done('booked');
```

**Don't invent a result object.** Return the record, `false`, or a list of
problem names, as `check()` does.

**Don't add getters, setters or a settings class.**

```php
// no
$this->settings->getStaffEmail();
// yes
config::get('cafe_staff_email', 'staff@cafe.test');
```

**Don't reformat files you aren't changing.** No sweeping changes of `array()`
to `[]`, added type hints, reordered methods or rewritten comments. Each one
makes the history harder to read and buys nothing.
