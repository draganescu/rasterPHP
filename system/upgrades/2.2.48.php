<?php
// Upgrading an app to Raster 2.2.48 (see 2.0.0.php for how steps work).
//
// print.cms.login_message no longer prints the notices "No database is
// configured…" and "There are no users yet…": it raises the alerts
// no_database and no_users, for the view to word. A login view that has
// the tag and not the alerts gets them, with the words it showed before.
$login_views = function ($dir) {
	$found = array();
	if (!is_dir($dir)) return $found;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		if (!$file->isFile() || !preg_match('/\.html$/', $file->getFilename())) continue;
		$html = file_get_contents($file->getPathname());
		if (strpos($html, '<!-- print.cms.login_message /-->') !== false && strpos($html, "print.validation.alert('no_users')") === false) $found[] = $file->getPathname();
	}
	return $found;
};

return array(
	'login-message-alerts' => array(
		'description' => 'print.cms.login_message raises the alerts no_database and no_users; login views word them',
		'needed' => function ($c) use ($login_views) { return (bool)$login_views($c['app'].'/views'); },
		'apply' => function ($c) use ($login_views) {
			$alerts = "<!-- print.validation.alert('no_database') --><p class=\"notice\">No database is configured for this environment (application/config/db/).</p><!-- /print.validation.alert('no_database') -->"
				."<!-- print.validation.alert('no_users') --><p class=\"notice\">There are no users yet. Create one from the project folder: <code>php bin/raster user admin</code></p><!-- /print.validation.alert('no_users') -->";
			$changed = array();
			foreach ($login_views($c['app'].'/views') as $file) {
				file_put_contents($file, str_replace('<!-- print.cms.login_message /-->', '<!-- print.cms.login_message /-->'.$alerts, file_get_contents($file)));
				$changed[] = substr($file, strlen($c['root']) + 1);
			}
			return 'changed '.implode(', ', $changed);
		},
	),
);
