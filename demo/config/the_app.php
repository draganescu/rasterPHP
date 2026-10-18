<?php
// Raster Café: the demo that uses every feature. See demo/README.md.
config::set('theme')->to('cafe');

// two languages; the templates are written in English. ro.localhost is Romanian.
config::set('languages')->to(array('en', 'ro'));
config::set('domain_language')->to(array('ro.localhost' => 'ro'));
config::set('language_cookie')->to('cafe_lang');

// accounts: members see /members, staff (editors) see /staff and the pages
// under it (/staff/setup, /staff/bookings) and a booking's own page. Never
// 'booking': that prefix would also lock the guest's /booking page (lint
// warns about it).
config::set('protected')->to(array('members' => 'member', 'staff' => 'editor', 'account' => 'member', 'booking_item' => 'editor'));
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
config::set('sitemap_skip')->to(array('lab', '404', '503'));

// a friendlier 404 page (views/cafe/404.html)
config::set('error_document_404')->to('404');

// what visitors see when the database can't be reached (views/cafe/503.html);
// it uses no model that needs the database
config::set('error_document_503')->to('503');

// the lab changes on every visit, so it is never cached
config::set('page_cache_skip')->to(array('lab'));

// mail: who it comes from, and where staff messages go
config::set('mail_from')->to('Raster Café <hello@cafe.test>');
config::set('cafe_staff_email')->to('staff@cafe.test');

// bookings (the bundled booking model; what can be booked comes from
// php demo/seed.php): the form is on /visit, staff get the requests, and a
// table is for at most 8
config::set('booking_page')->to('visit');
config::set('booking_staff_email')->to('staff@cafe.test');
config::set('booking_max_people')->to(8);

// the contact and reset forms keep the older validation regions on purpose, so
// the suite still covers them (D14). doctor counts them apart instead of warning.
config::set('allow_deprecated')->to(array(
	'legacy-validation-regions' => '#^views/cafe/(visit|password/new)\.html$#',
));

// text replaced in pages under /lab only (template::replace)
template::instance()->replace('{{cafe}}', 'Raster Café', 'lab');

// Knobs the demo tests turn with environment variables
$knob = function ($name) { $value = getenv($name); return $value === false ? null : $value; };
if ($knob('CAFE_REGISTRATION') === 'off') config::set('registration')->to(false);
if ($knob('CAFE_DOUBLE_OPT_IN') === 'off') config::set('newsletter_double_opt_in')->to(false);
if ($knob('CAFE_LOGIN_PAGE')) config::set('login_page')->to($knob('CAFE_LOGIN_PAGE'));
if ($knob('CAFE_MCP_TOKEN')) config::set('mcp_token')->to($knob('CAFE_MCP_TOKEN'));
if ($knob('CAFE_SITE_URL')) config::set('site_url')->to($knob('CAFE_SITE_URL'));
if ($knob('CAFE_PAGE_CACHE')) config::set('page_cache')->to($knob('CAFE_PAGE_CACHE') === 'on');
if ($knob('CAFE_CACHE_TTL')) config::set('page_cache_ttl')->to((int)$knob('CAFE_CACHE_TTL'));
if ($knob('CAFE_STRICT') === 'off') config::set('strict_templates')->to(false);
if ($knob('CAFE_REWRITE') === 'off') config::set('rewrite')->to(false);
if ($knob('CAFE_API_FEED') === 'on') config::set('api_system_models')->to(array('cms', 'feed'));
if ($knob('CAFE_LOG') === 'on') log::enable();
if ($knob('CAFE_ADMIN_PAGE')) config::set('protected')->to(config::get('protected') + array($knob('CAFE_ADMIN_PAGE') => 'admin'));
