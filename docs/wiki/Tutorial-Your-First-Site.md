# Tutorial: your first site

In this tutorial you add a **Team** page to the starter site. By the end it has editable text, an editable list of people with photos and their own detail pages, data from your own PHP class, and a working contact form that emails you.

You need a running site from [Getting started](Getting-Started) and an admin account. Every file below lives in `application/`.

## Step 1: a plain HTML page

Create `application/views/default/team.html`:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Our team</title>
</head>
<body>
  <main>
    <h1>Meet the team</h1>
    <ul class="people">
      <li><a href="#">Ada Lovelace</a> <span class="role">Engineer</span></li>
      <li><a href="#">Grace Hopper</a> <span class="role">Admiral</span></li>
    </ul>
  </main>
</body>
</html>
```

Open http://localhost:8000/team. The page shows. You did not register a route: the URL `/team` finds the file `team.html`.

## Step 2: share the header and footer

The starter site keeps its `<head>` contents, header and footer in `_layout.html`, in named fragments:

```html
<!-- res.header -->
<header class="site-header"> … </header>
<!-- /res.header -->
```

`res.header` names a fragment. Another page pulls it in with `dry` (short for "don't repeat yourself"): `<!-- dry._layout.header /-->` means "insert the fragment `header` from the file `_layout.html`". Files starting with `_` are never pages themselves, so nobody can visit `/_layout`.

Change `team.html` to use the layout:

```html
<!doctype html>
<html lang="en">
<head>
  <!-- dry._layout.head /-->
  <title>Our team</title>
</head>
<body>
  <!-- dry._layout.header /-->
  <main>
    <h1>Meet the team</h1>
    <ul class="people">
      <li><a href="#">Ada Lovelace</a> <span class="role">Engineer</span></li>
      <li><a href="#">Grace Hopper</a> <span class="role">Admiral</span></li>
    </ul>
  </main>
  <!-- dry._layout.footer /-->
</body>
</html>
```

Reload: the page now has the site's styles, navigation and footer.

The `/-->` ending means the annotation closes itself: there is no separate closing tag. Spacing matters: exactly one space after `<!--` and one before `-->` (or `/-->`). `<!--dry._layout.header-->` is ignored. `php bin/raster lint` reports that, and `php bin/raster lint --fix` repairs it.

## Step 3: make the text editable

Wrap the title and heading in `print.cms` annotations:

```html
<title><!-- print.cms.title -->Our team<!-- /print.cms.title --></title>
…
<h1><!-- print.cms.heading -->Meet the team<!-- /print.cms.heading --></h1>
```

Log in, open `/team` and press **Edit**. The heading glows: click it and change it. The title isn't visible on the page, so the editor lists it in its **Page** panel instead.

What happened: `cms` is a model that ships with Raster. When it sees `print.cms.heading` on the `/team` page, it creates a field called `heading` for that page and stores the text between the tags as its starting value. Each page has its own fields, so `heading` on `/team` and `heading` on `/about` are different.

## Step 4: an editable list

Now make the people a list editors can add to. Replace the `<ul>`:

```html
<ul class="people">
  <!-- render.cms.team('order=name') -->
  <li>
    <!-- print.@src.photo --><img src="images/person.jpg" alt=""><!-- /print.@src.photo -->
    <!-- print.@href.raster_detail_link --><a href="team/team_item/1"><!-- print.name -->Ada Lovelace<!-- /print.name --></a><!-- /print.@href.raster_detail_link -->
    <span class="role"><!-- print.role -->Engineer<!-- /print.role --></span>
  </li>
  <!-- remove -->
  <li><img src="images/person.jpg" alt=""> <a href="#">Grace Hopper</a> <span class="role">Admiral</span></li>
  <!-- /remove -->
  <!-- /render.cms.team('order=name') -->
</ul>
```

Line by line:

- `render.cms.team('order=name')` declares a **collection** called `team`, sorted by name. The HTML inside is repeated once per person.
- `print.name` and `print.role` are fields of each person. Raster creates them from the markup.
- `print.@src.photo` is an **attribute annotation**: it wraps a tag and sets that tag's `src` attribute to the `photo` field. Editors can replace the picture in the editor. Until they do, the template's `images/person.jpg` shows.
- `print.@href.raster_detail_link` sets the link to the person's own page. `raster_detail_link` is filled in by Raster.
- The `remove` block is sample content for the static mock-up. It's deleted before anything runs.
- The closing tag repeats the whole opening name, arguments included: `<!-- /render.cms.team('order=name') -->`.

Reload `/team`. You see one person, Ada: the first item of a new collection is made from the template's own sample content. As an editor, the list now ends with a card for adding a new person, and each person has a handle for editing details, hiding, scheduling or deleting.

## Step 5: a page for each person

The link on each person goes to `/team/team_item/ada-lovelace`. (The last part is the item's **slug**, a readable id made from its name. The numeric id works too: `/team/team_item/1`.) Raster shows that URL with `team_item.html` if it exists. Create it:

```html
<!doctype html>
<html lang="en">
<head>
  <!-- dry._layout.head /-->
  <title>Team</title>
</head>
<body>
  <!-- dry._layout.header /-->
  <main>
    <!-- render.cms.team -->
    <h1><!-- print.name -->Ada Lovelace<!-- /print.name --></h1>
    <p><!-- print.role -->Engineer<!-- /print.role --></p>
    <!-- /render.cms.team -->
    <p><a href="team.html">All of us</a></p>
  </main>
  <!-- dry._layout.footer /-->
</body>
</html>
```

On this URL, `render.cms.team` shows only the one person the URL names. A slug that doesn't exist gives a 404.

## Step 6: data from your own PHP

Not everything belongs in the CMS. The coming week's opening hours can be worked out by code. Create `application/models/office/office.php`:

```php
<?php
class office {
    // used as <!-- print.office.today -->: when we close today
    function today() {
        // on Saturdays we close early; on other days keep the template's time
        return date('N') == 6 ? '14:00' : false;
    }

    // used as <!-- render.office.hours -->: the next seven days we are open
    function hours() {
        $rows = array();
        for ($i = 0; $i < 7; $i++) {
            $day = strtotime("+$i days");
            if (date('N', $day) == 7) continue;     // closed on Sundays
            $saturday = date('N', $day) == 6;
            $rows[] = array('date' => date('Y-m-d', $day), 'opens' => $saturday ? '10:00' : '9:00', 'closes' => $saturday ? '14:00' : '17:00');
        }
        return $rows;
    }
}
```

The rules for models:

- The folder name, file name and class name are the same: `models/office/office.php` holds `class office`.
- A method used by `print` returns a string. Returning `false` (or `null`) keeps what the template already shows.
- A method used by `render` returns a list of rows, each row an array with named keys.
- A model returns data (times, dates, flags), not words. The words stay in the template, where an editor or a translation can reach them.

Add this under the list in `team.html`:

```html
<p>Today we close at <!-- print.office.today -->17:00<!-- /print.office.today -->.</p>
<h2>Opening hours</h2>
<table>
  <!-- render.office.hours -->
  <tr><td><!-- print.date -->2026-10-26<!-- /print.date --></td><td><!-- print.opens -->9:00<!-- /print.opens --> to <!-- print.closes -->17:00<!-- /print.closes --></td></tr>
  <!-- /render.office.hours -->
</table>
```

## Step 7: a contact form

A form posts back to its own page. The `render` block around it names the model that handles it, and for a contact form Raster has one built in: `contact`. Add to `team.html`:

```html
<h2>Write to us</h2>
<!-- print.validation.alert('sent') --><p class="notice">Thanks, we got your message.</p><!-- /print.validation.alert('sent') -->
<!-- print.validation.alert('email_invalid') --><p class="error">Check your email address.</p><!-- /print.validation.alert('email_invalid') -->
<!-- print.validation.alert('message_invalid') --><p class="error">Write a message, up to 2000 characters.</p><!-- /print.validation.alert('message_invalid') -->
<!-- render.contact.form -->
<form method="post">
  <label>Your name <input name="name"></label>
  <label>Your email <input type="email" name="email" required></label>
  <!-- render.validation.field('email') --><p class="error">Please enter your email address.</p><!-- /render.validation.field('email') -->
  <label>Message <textarea name="message" required maxlength="2000"></textarea></label>
  <!-- render.validation.field('message') --><p class="error">Please write a message (up to 2000 characters).</p><!-- /render.validation.field('message') -->
  <button>Send</button>
</form>
<!-- /render.contact.form -->
```

The **rules** are the ordinary HTML attributes (`required`, `type="email"`, `maxlength`). Raster checks them on the server as well, so a visitor can't get around them. The **messages** are in the template: each `validation.field` block shows only when its field breaks a rule. The `alert('sent')` block stays hidden until the message is stored. You wrote no PHP: the form's model is the bundled `contact`, which stores every message for you and, when it looks like spam, keeps it aside.

Tell it who to email, in `application/config/the_app.php`:

```php
config::set('contact_staff_email')->to('me@example.com');
```

And write the email staff get, `application/views/default/_email/contact_received.html`. The `<title>` becomes the subject, and `print.self.<name>` prints the message's fields:

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>New message from the website</title></head>
<body>
  <p>From: <!-- print.self.name /--> <!-- print.self.email /--></p>
  <p><!-- print.self.message /--></p>
</body></html>
```

Replying to that email answers the visitor: their address is in `Reply-To`, and nothing is ever mailed to it by the site. Send the form empty: the error messages appear, and whatever you typed is kept. Fill it in and send it: the page reloads with `?done=sent` and the thank-you message shows. [Contact forms](Contact-Forms) shows how staff read the messages on a page of their own.

In development, emails are not really sent. Each one is saved as a `.eml` file in `application/data/mail/`, which you can open with any mail program. [Sending email](Sending-Email) explains how to send real email.

**Writing a form of your own.** When no bundled model fits, a form is a model method: `submitted()`, `valid()`, do the work, then `util::done('sent')`, as in the feedback form of [Forms and validation](Forms-and-Validation). Ask `suspect()` before the work if the form is public.

## Step 8: add the page to the navigation

The starter site's menu is `render.site.nav` in `_layout.html`. The model, `nav()` in `application/models/site/site.php`, knows each link's address and which one is the page you're on; the words are in the view. Add the address to the `$links` array in `nav()`:

```php
$links = array('home' => '/', 'news' => '/news', 'about' => '/about', 'team' => '/team');
```

and its link in `_layout.html`, inside `render.site.nav`, after the About one:

```html
<!-- print.team --><!-- print.+class.state --><!-- print.@href.url --><a class="nav-link" href="team.html">Team</a><!-- /print.@href.url --><!-- /print.+class.state --><!-- /print.team -->
```

## Step 9: check everything

```sh
php bin/raster lint     # no errors expected
php bin/raster schema   # shows the new fields: title, heading, the team collection, and contactmessage
```

`contactmessage` is where the contact form's messages are kept. `lint` shows a warning about it until there is a staff page that lists the messages: nobody can read them otherwise. [Contact forms](Contact-Forms#the-staff-page) shows how to make one.

Try breaking something on purpose. Change `print.office.today` to `print.office.todya` in both tags and run `lint`:

```
application/views/default/team.html:11:26: error: Model 'office' has no public method 'todya' (application/models/office/office.php); did you mean 'today'?
```

In development, loading the page shows the same list instead of the page. Change it back.

## What you've used

- One HTML file per URL, with shared fragments (`res` and `dry`)
- CMS page fields (`print.cms.x`) and a collection (`render.cms.x`) with detail pages
- Your own model returning a string and a list
- A form whose rules and messages live in the HTML
- An email written as a template

Next, read [Annotations](Annotations) for everything a template can say, and [The CMS](The-CMS) for sorting, filtering, drafts and scheduling.
