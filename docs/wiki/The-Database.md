# The database

Raster stores CMS content, accounts and newsletter subscribers in a database, and your models can use it too. This page explains the setup, the two ways to query it, and how the tables get created.

## SQLite by default

Out of the box, Raster uses **SQLite**: a complete SQL database stored in one file, `application/data/raster.sqlite`. It needs no server, no user and no password; PHP talks to the file directly. It's a good fit for most small and medium sites. Back it up by copying the file (ideally while the site isn't writing).

The `data/` folder is never served over the web and is excluded from git by its own `.gitignore`.

## Connections per environment

Each [environment](Settings-and-Environments#environments) has its own connection file in `application/config/db/`, named after the environment:

```php
<?php // application/config/db/development.php
$active   = true;
$dsn      = 'sqlite:'.(getenv('RASTER_DB') ?: APPBASE.'data/raster.sqlite');
$user     = null;
$password = null;
$frozen   = false;
```

| Variable | Meaning |
|---|---|
| `$active` | `false` switches this connection off |
| `$dsn` | where the database is, in PHP's PDO format (see below) |
| `$user`, `$password` | credentials, for database servers |
| `$frozen` | `true` means the table structure never changes by itself (see [Fluid and frozen](#fluid-and-frozen)) |

To use **MySQL** or MariaDB instead, change the DSN and credentials:

```php
$dsn      = 'mysql:host=localhost;dbname=mysite;charset=utf8mb4';
$user     = 'mysite';
$password = getenv('DB_PASSWORD');
```

`RASTER_DB=/path/to/other.sqlite` points the SQLite connections at another file without editing anything, which is handy for tests and servers.

`APPBASE` is a constant holding the path of your app folder, with a trailing slash.

## Fluid and frozen

The CMS creates tables and columns from your templates: add `<!-- print.cms.subtitle -->` to a view and a `subtitle` column is needed. What happens next depends on the connection:

- **Fluid** (`$frozen = false`, the development default): the column is created on the next request that renders the annotation. You never think about it.
- **Frozen** (`$frozen = true`, the production default): nothing changes by itself. Until you apply the change, the new field shows its template default and can't be saved. Apply changes with:

```sh
RASTER_ENV=production php bin/raster schema --apply
```

Freezing production means a typo in a template can't quietly add columns to your live database, and the database isn't altered in the middle of a visitor's request.

`php bin/raster schema` compares the content model in your templates with the database, and `--rename`, `--drop` and `--check` help you change it safely. See [The CMS](The-CMS#changing-fields-safely).

## Querying with SQL

Get the database object and run a query with **bound parameters**: values are passed separately from the SQL, so they can never change the query (this is what protects you from SQL injection).

```php
$db = database::instance();
$rows = $db->query('SELECT name, price FROM product WHERE category = ? AND price <= ?', array('chairs', 200));
$rows = $db->query('SELECT * FROM product WHERE id = :id', array(':id' => 7));
```

The result is always a list of rows, each an array keyed by column name, which is exactly what a `render` method returns. So a model method can be this short:

```php
function cheap_chairs() {
    return database::instance()->query("SELECT name, price FROM product WHERE category = 'chairs' AND price < 100");
}
```

Never paste visitor input into the SQL string yourself; always use `?` or `:name`.

## SQL in files (named queries)

Longer queries can live in `.sql` files next to the model and be called as if they were methods:

```sql
-- application/models/products/sql/in_category.sql
SELECT name, price FROM product WHERE category = ? AND price <= ? ORDER BY name
```

```php
// inside the products model
$rows = database::instance()->in_category('chairs', 200);

// from any other model: name the folder to look in
$rows = database::instance('products')->in_category('chairs', 200);
```

- `?` placeholders are filled from the arguments, in order. For `:name` placeholders pass one array: `->between(array(':low' => 1, ':high' => 5))`.
- Without a model name, Raster looks in the folder of the model a template is currently calling. A model name you pass is remembered for later calls, so pass it whenever you're not sure.
- Queries any model may use can go in `application/models/sql.php`:

  ```php
  <?php
  $queries['dish_names'] = "SELECT name FROM menudata WHERE category = '%s' ORDER BY name";
  ```

  Values for `'%s'` are quoted safely by the database driver. A `.sql` file with the same name wins over an entry here.
- Calling a name that has no query throws a `BadMethodCallException`. `lint` finds such calls in your models before you run them.

## Working with records: RedBeanPHP

Raster includes **RedBeanPHP**, a small library that maps database rows to PHP objects (an ORM). RedBean calls a row a **bean** and a table a **type**. In a fluid database it also creates tables and columns as you store beans, so you don't write `CREATE TABLE`.

Always call `database::instance()` first in a request: it loads RedBean and connects. Then use the `R` class:

```php
database::instance();

// create
$booking = R::dispense('reservation');      // a new, unsaved row of type "reservation"
$booking->name = 'Ada';
$booking->guests = 4;
$booking->created_at = R::isoDateTime();   // "2026-09-28 12:00:00"
$id = R::store($booking);                  // saves it and returns its id

// read
$one  = R::load('reservation', $id);                              // by id
$one  = R::findOne('reservation', ' email = ? ', array($email));  // first match, or null
$many = R::find('reservation', ' guests > ? ORDER BY id DESC LIMIT 20 ', array(2));
$all  = R::findAll('reservation', ' ORDER BY id ');
$n    = R::count('reservation');

// update and delete
$one->guests = 5;
R::store($one);
R::trash($one);

// beans to plain arrays, e.g. for a render method
$rows = R::exportAll($many);
```

Type names must be **lowercase letters and digits only**: no underscores, no capitals. `reservation` and `orderitem` work; `order_item` doesn't.

The full RedBean manual is at https://redbeanphp.com.

## Tables your model writes

A model that stores its own records should say which tables and columns it uses, with a static `schema()` method. `raster schema --apply` then creates them in production:

```php
class reservation {
    static function schema() {
        return array(
            'reservation' => array('name' => '', 'email' => '', 'guests' => 0, 'created_at' => ''),
        );
    }
}
```

The values are examples of the kind of data: `''` for text, `0` for numbers. The bundled models declare their tables the same way.

## Tables Raster uses

You'll see these names in `raster schema` and in the database. You rarely need to touch them directly.

| Table | Holds |
|---|---|
| `homepage`, `aboutpage`, … | page fields, one table per page, one row per saved revision |
| `sitepage` | site-wide `site_*` fields |
| `newsdata`, `teamdata`, … | the items of each CMS collection (`<name>data`) |
| `user` | accounts |
| `subscriber` | newsletter subscribers |
| `newsletterissue` | which pages were sent as newsletter issues |

Page tables are named after the URL: `/about` uses `aboutpage`. Longer paths get a short hash in the name so different pages never share a table.
