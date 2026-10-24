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
	// two or more languages without multilingual: the template's words are
	// translated, the content and the addresses are not (#200 D2). The
	// file that lists them and doesn't turn multilingual on is the use.
	'template-only-languages' => array(
		'since' => '2.2.49',
		'removed_in' => '3.0.0',
		'in' => '#^config/#',
		'pattern' => '/\A(?![\s\S]*config::set\(\s*[\'"]multilingual[\'"]\s*\)\s*->\s*to\(\s*true\b)[\s\S]*?\Kconfig::set\(\s*[\'"]languages[\'"]\s*\)\s*->\s*to\(\s*array\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]/',
		'message' => 'languages without multilingual translates only the template\'s words, picked by ?lang= and a cookie; add config::set(\'multilingual\')->to(true) for one address per language (/ro/menu) and translatable content, then php bin/raster schema --apply',
	),
	// a dictionary in i18n/<lang>/ that sets $lang instead of returning
	// its array (#44)
	'i18n-lang-variable' => array(
		'since' => '2.2.49',
		'removed_in' => '3.0.0',
		'in' => '#^i18n/#',
		'pattern' => '/\A(?![\s\S]*^\s*return\b)[\s\S]*?\K\$lang\b\s*(?:\[[^\]]*\]\s*)?=(?!=)/m',
		'message' => 'a dictionary that sets $lang instead of returning it: write return array(\'key\' => \'text\'); (`raster upgrade` adds return $lang; at the end)',
	),
);
