<?php
// Raster Café: the demo that uses every feature. See demo/README.md.
config::set('theme')->to('cafe');

// a multilingual site in two languages: English, the templates' and the
// default, at /menu, and Romanian at /ro/menu (demo/seed.php adds the
// Romanian content; i18n/ro/ holds the fixed words)
config::set('multilingual')->to(true);
config::set('languages')->to(array('en', 'ro'));
// what the off switch keeps (template-only translation, with ?lang= and a
// cookie, deprecated until 3.0.0) is tested with CAFE_MULTILINGUAL=off, see below
config::set('language_cookie')->to('cafe_lang');
// No domain_language here: seen from the site's own address (RASTER_URL,
// site_url) a language with its own domain lives only there
// (i18n::address()), so a ro.localhost entry would send the live café's
// /ro/ pages to a host nobody can reach. Tests turn it on with
// CAFE_DOMAIN_LANGUAGE=ro.localhost, below.

// accounts: members see /members, staff (editors) see /staff and the pages
// under it (/staff/setup, /staff/bookings, /staff/events) and a booking's,
// a message's and a reply's own page. Never 'booking' or 'rsvp': those
// prefixes would also lock the guest's /booking and /rsvp pages (lint warns
// about it).
config::set('protected')->to(array('members' => 'member', 'staff' => 'editor', 'account' => 'member', 'booking_item' => 'editor', 'contactmessage_item' => 'editor', 'rsvp_item' => 'editor'));
config::set('after_login')->to('members');
config::set('password_min_length')->to(10);
config::set('reset_page')->to('password/new');

// the newsletter's pages live under /letters
config::set('newsletter_confirm_page')->to('letters/confirm');
config::set('newsletter_unsubscribe_page')->to('letters/stop');

// lists: four dishes and three events per page, five of everything else
config::set('menu_page_size')->to(4);
config::set('events_page_size')->to(3);
config::set('raster_page_size')->to(5);
config::set('booking_page_size')->to(50);
config::set('feed_limit')->to(5);

// the sitemap leaves out private pages, the account, newsletter and booking
// pages (wherever the settings put them), and these utility pages
config::set('sitemap_skip')->to(array('lab', '403', '404', '500', '503'));

// a friendlier 404 page (views/cafe/404.html)
config::set('error_document_404')->to('404');

// the café's own words for a refusal (views/cafe/403.html: a form from
// another site, one open too long, a page the account can't open) and for
// a page that broke (views/cafe/500.html)
config::set('error_document_403')->to('403');
config::set('error_document_500')->to('500');

// what visitors see when the database can't be reached (views/cafe/503.html);
// it uses no model that needs the database
config::set('error_document_503')->to('503');

// the lab changes on every visit, so it is never cached
config::set('page_cache_skip')->to(array('lab'));

// mail: who it comes from, and where staff messages go
config::set('mail_from')->to('Raster Café <hello@cafe.test>');
config::set('contact_staff_email')->to('staff@cafe.test');

// bookings (the bundled booking model; what can be booked comes from
// php demo/seed.php): the form is on /visit, staff get the requests, and a
// table is for at most 8
config::set('booking_page')->to('visit');
config::set('booking_staff_email')->to('staff@cafe.test');
config::set('booking_max_people')->to(8);

// replies to the events (the bundled rsvp model; the two evenings come from
// php demo/seed.php): guests reply on each event's page and change it on
// /rsvp, and staff get an email for each guest's reply
config::set('rsvp_staff_email')->to('staff@cafe.test');

// the contact form on /visit and the reset form keep the older validation regions on purpose, so
// the suite still covers them (D14). doctor counts them apart instead of warning.
config::set('allow_deprecated')->to(array(
	'legacy-validation-regions' => '#^views/cafe/(visit|password/new)\.html$#',
));

// text replaced in pages under /lab only (template::replace)
template::instance()->replace('{{cafe}}', 'Raster Café', 'lab');

// Knobs the demo tests turn with environment variables
$knob = function ($name) { $value = getenv($name); return $value === false ? null : $value; };
// the suite posts many forms from one address; the spam tests leave the limits on
if ($knob('CAFE_SPAM_LIMITS') === 'off') config::set('spam_per_ip')->to(false);
if ($knob('CAFE_SPAM_LIMITS') === 'off') config::set('spam_per_email')->to(false);
if ($knob('CAFE_SPAM_IP_BLOCKS') === 'on') config::set('spam_ip_blocks')->to(true);
if ($knob('CAFE_REGISTRATION') === 'off') config::set('registration')->to(false);
if ($knob('CAFE_DOUBLE_OPT_IN') === 'off') config::set('newsletter_double_opt_in')->to(false);
if ($knob('CAFE_LOGIN_PAGE')) config::set('login_page')->to($knob('CAFE_LOGIN_PAGE'));
if ($knob('CAFE_MCP_TOKEN')) config::set('mcp_token')->to($knob('CAFE_MCP_TOKEN'));
if ($knob('CAFE_SITE_URL')) config::set('site_url')->to($knob('CAFE_SITE_URL'));
if ($knob('CAFE_TIMEZONE')) config::set('timezone')->to($knob('CAFE_TIMEZONE'));
if ($knob('CAFE_PAGE_CACHE')) config::set('page_cache')->to($knob('CAFE_PAGE_CACHE') === 'on');
if ($knob('CAFE_TIMEZONE')) config::set('timezone')->to($knob('CAFE_TIMEZONE'));
if ($knob('CAFE_CACHE_TTL')) config::set('page_cache_ttl')->to((int)$knob('CAFE_CACHE_TTL'));
if ($knob('CAFE_STRICT') === 'off') config::set('strict_templates')->to(false);
if ($knob('CAFE_REWRITE') === 'off') config::set('rewrite')->to(false);
if ($knob('CAFE_API_FEED') === 'on') config::set('api_system_models')->to(array('cms', 'feed'));
if ($knob('CAFE_LOG') === 'on') log::enable();
if ($knob('CAFE_MULTILINGUAL') === 'off') config::set('multilingual')->to(false);
if ($knob('CAFE_DOMAIN_LANGUAGE')) config::set('domain_language')->to(array($knob('CAFE_DOMAIN_LANGUAGE') => 'ro'));
if ($knob('CAFE_ADMIN_PAGE')) config::set('protected')->to(config::get('protected') + array($knob('CAFE_ADMIN_PAGE') => 'admin'));
