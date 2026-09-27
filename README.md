Raster PHP
======

Raster is a PHP framework for web artefacts made by people and agents: sites, landing pages, blogs, newsletters and small apps. It implements the [RTO pattern](https://draganescu.github.io/rto/specs/2014/06/29/rto.html): a request picks a template, and the template pulls its data from objects.

You write HTML and mark the parts that change with HTML comments. The template owns every word on the page, including error messages and emails; models only decide what shows. The CMS derives its content model from the markup, so there are no schema files and no admin configuration.

Compared with Laravel or Symfony, Raster is small: a single controller, one view file per URL, no build step. Pages are fast and cached for visitors. What it generates is easy to follow, and every page is editable as soon as it exists.

```sh
php bin/raster new mysite     # a new site (or clone this repository and work in application/)
php bin/raster serve          # http://localhost:8000 (PHP 8.1+, SQLite, nothing to install)
php bin/raster user admin     # an editor account; log in at /login
php bin/raster lint           # check every view: annotations, forms, alerts, emails
php bin/raster lint --fix     # and repair what is mechanical
php bin/raster annotations    # the annotation grammar, as data for agents
php bin/raster schema         # the content model the templates define, compared with the database
php bin/raster send /news/news_item/hello   # email a page to newsletter subscribers
php bin/raster mcp            # let an agent edit the content (MCP over stdio; /mcp over HTTP)
php bin/raster doctor         # checks the site, including production settings
php bin/raster deploy --config=caddy        # the server config: what must never be served
php bin/raster update         # the latest release of the framework; your app is left alone
```

Included:
- **CMS:** page fields and collections derived from the markup, with site-wide fields, readable slugs, drafts and scheduled posts.
- **Forms:** validation rules come from the HTML attributes and messages from the template. Every post is protected against other sites and bots.
- **Accounts:** login, sign-up, password reset by email, account page, roles, and protected pages.
- **Newsletter:** double opt-in, one-click unsubscribe, and sending any page as an issue.
- **Feeds and sitemaps:** views such as `news.rss` and `sitemap.xml`.
- **Other:** translations, pagination, mail over SMTP, page caching, and an MCP server for agents.
- **Deploying:** one list of what is never served (`system/private_paths.php`), and the Apache,
  nginx or Caddy configuration generated from it.

**Working on a Raster site with an AI agent?** [AGENTS.md](AGENTS.md) is the complete specification.

**Updating:** `raster update` replaces only the framework files (`system/`, `bin/raster`, `index.php`, `.htaccess`, `AGENTS.md`), refuses if you edited them, and then runs the upgrade steps each app needs. What changed is in [CHANGELOG.md](CHANGELOG.md).

Tests: `php tests/run.php` for the framework, `php tests/update.php` for updates, and `php tests/demo.php` for [the demo café](demo/README.md), a complete site that uses every feature. Each feature has an ID, and the suite fails if any of them lacks a passing test. `php tests/mutate.php` breaks the framework on purpose, one change at a time from `tests/mutations.json`, and fails if the demo suite doesn't notice.

---

The original introduction follows.

Raster PHP is the php implementation of the raster specification that implements the RTO design pattern ([more details](https://draganescu.github.io/rto/specs/2014/06/29/rto.html)).

# Hi i'm Raster!

## Basics

Views in HTML. All HTML and nothing else. All templating is done via HTML comments. Clear?

> Comments! Why? Because its lovely not to mess around with the finely built work of a front end developer by adding non semantic stuff, ruining tags by breaking them in loops and also its great to see things in the browser as they're meant to be without accolades or php code artifacts killing your retina.

Models in PHP. Nothing but PHP. All the models return   just data like arrays, strings and booleans.

One single thin controller. Very thin. All it  does is controlling: routing and mapping models to views. 

## How it works

1. You make a nice html page of how you want the UI to look.

~~~
<html>
	...
	<ul class='vehicles'>
		<li>Volvo</li>
		<li>Mercedes</li>
		<li>WV</li>
		<li>Audi</li>
	</ul>
</html>
~~~

2. You open that html look at its parts (you know HTML is structured right?) and start thinking about one thing: where does the data come from? And always answer the same thing: from a model.

~~~
<html>
	...
	<!-- the vehicles list comes from the vehicles model! -->
	<ul class='vehicles'>
		<li>Volvo</li>
		<li>Mercedes</li>
		<li>WV</li>
		<li>Audi</li>
	</ul>
	<!-- amaizing! -->
</html>
~~~

3. You make that model and you code in the logic to get the data then you return it.

~~~
<?php
class vehicles {

	function thelist {
		return array (
			array('name' => 'Aeroplane'),
			array('name' => 'Rickshaw'),
			array('name' => 'Train'),
			array('name' => 'Bicycle'),
		);
	}
}
~~~

4. You go to the html file and code in the comment to output the data.

~~~
<html>
	...
	<!-- render.vehicles.thelist -->
	<ul class='vehicles'>
		<li><!-- print.name -->Volvo<!-- /print.name --></li>
		<!-- remove -->
		<li>Mercedes</li>
		<li>WV</li>
		<li>Audi</li>
		<!-- /remove -->
	</ul>
	<!-- /render.vehicles.thelist -->
</html>
~~~

5. There we go:

~~~
<html>
	...
	<ul class='vehicles'>
		<li>Aeroplane</li>
		<li>Rickshaw</li>
		<li>Train</li>
		<li>Bicycle</li>
	</ul>
</html>
~~~

### Now don't go wasting my precious time

You've got two primary kind of comments: print and render. Print will only output the result of the model's work. Render will loop trough an array of items returned by the model's work. 

> If the model returns false the the html in the template remains intact.

You've got some secondary kind of comments: remove, res and dry. Remove is used to wipe out lorem ipsum and other nonsense. Res specifies a reusable part of a template. Dry specifies a DRY operation: take something from somewhere else and DRY.

## Environment

First, just in case, in the application's index.php you can set the name of the folder where your application lives:

~~~
<?php

require_once 'system/boot.php';
boot::$appname = 'application'; // <- here! here!
boot::up();

?>
~~~

## The core classes

### Boot up!

The Boot class initialises the global configuration, detects where the files are on the filesystem and also takes care to load your precious configuration and extended or replaced core classes.

### Events are awesome!

The Events class glues together all the wonderful singletons Raster is made of. For example, the default events are in /system/config/events.php:

~~~
<?php
event::bind('launch')->to('controller','respond')->core();
event::bind('route_found')->to('controller','handle_response')->core();
event::bind('done')->to('controller','output')->core();
?>
~~~

### Configuration is a global object in your app

To configure a Raster application you create files in your config directory of your application and issue directives such as:

~~~
<?php
config::set('theme')->to('roses');
?>
~~~

then it will be available as:

~~~
<?php
$valentinesday = config::get('theme');
?>
~~~

### I'm utility

The Utility class has some methods that help you while developing with Raster such as getting url parameters, detecting if you have or not post/get data and so forth. Its very useful in models where you can for example not continue the method if there is no data passed to it:

~~~
<?php
	...
	function save_user() {
		if(util::no_post_data()) return false;
	}
?>
~~~

## Oh yes, Database

The ORM of choice is [Red Bean](http://www.redbeanphp.com). Its a nice ORM that handles table creation and mapping of database data to PHP objects. Its rock solid, fast, configurable and i was too damn lazy to write database adapters and whatnot.

## Oh, oh and automatic form management

Raster handles forms automatically because the templating class has this awesome method called _form_state_.

~~~
<?php
class users {
	...
	function add_or_edit_user() {
		
		$page = template::instance();	
		$db = database::instance();

		$uid = util::param('id', false);
		if(!$uid) return false;		

		$user = $db->get_user_by_id($uid);
		return $page->form_state($user);
	}
}
?>
~~~

That code above will handle autocompletion of _value_ attributes and _selected_ attributes by matching the _name_ of the form fields to the keys of the $user array. In your template you'll have:

~~~
<!-- render.users.add_or_edit_user -->
<form>
	<input type='text' name='username' />
</form>
<!-- /render.users.add_or_edit_user -->
~~~

And for all this convenience all you have to remember is to put type before name in the html :)


