<?php
// The editor login page, unless the theme has its own login.html
if (!file_exists(APPBASE.config::get('views_path').'/'.config::get('theme').'/login'.config::get('views_ext'))) {
	controller::route('login')->to('login')->from('cms_admin');
}
