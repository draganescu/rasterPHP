<?php
// Raster Café: the demo that uses every feature. See demo/README.md.
config::set('theme')->to('cafe');

// two languages; the templates are written in English
config::set('languages')->to(array('en', 'ro'));

// accounts: members see /members, staff (editors) see /staff
config::set('protected')->to(array('members' => 'member', 'staff' => 'editor', 'account' => 'member'));
config::set('after_login')->to('members');

// four dishes per menu page; three events per page
config::set('menu_page_size')->to(4);
config::set('events_page_size')->to(3);

// text replaced in pages under /lab only (template::replace)
template::instance()->replace('{{cafe}}', 'Raster Café', 'lab');
