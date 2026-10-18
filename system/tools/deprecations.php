<?php
// Things that still work but will be removed. `raster doctor` looks for
// them in each app's views, models and config (`in` limits the files, as a
// pattern over the path inside the app folder). `raster upgrade` fixes the
// ones it can; the message says what to do for the rest.
//
// When you deprecate something: add it here with the version it was
// deprecated in and the version that removes it (at least one minor
// release later), keep it working until then, and add an upgrade step in
// system/upgrades/<version>.php when the change can be made automatically.
return array(
	'cms-login-region' => array(
		'since' => '2.0.0',
		'removed_in' => '2.2.0',
		'in' => '#^views/#',
		'pattern' => '/<!-- \/?render\.cms\.login -->/',
		'message' => 'render.cms.login is now render.authentication.login (`raster upgrade` changes it)',
	),
	'legacy-validation-regions' => array(
		'since' => '2.0.0',
		'removed_in' => '3.0.0',
		'in' => '#^views/#',
		'pattern' => '/<!-- render\.validation\.(not_empty|email_format|are_the_same)\(/',
		'message' => 'regions from older Raster: not_empty and email_format become required and type="email" on the input with render.validation.field(\'name\'); are_the_same becomes matches',
	),
	'url-helpers' => array(
		'since' => '2.2.7',
		'removed_in' => '2.3.0',
		'in' => '#\.php$#',
		'pattern' => '/\b(authentication::url|newsletter::page_url)\(/',
		'message' => 'authentication::url($page) and newsletter::page_url($setting, $default, $token) are util::url($page, $query): util::url(config::get(\'newsletter_confirm_page\', \'newsletter-confirm\'), array(\'token\' => $token))',
	),
);
