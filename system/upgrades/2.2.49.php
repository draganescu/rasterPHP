<?php
// Upgrading an app to Raster 2.2.49 (see 2.0.0.php for how steps work).
//
// A dictionary in i18n/<lang>/ returns its array. One from older Raster
// that sets $lang instead (deprecation i18n-lang-variable, the pattern
// that finds it) gets `return $lang;` at its end, which keeps every word.
$dictionaries = function ($dir) {
	$found = array();
	if (!is_dir($dir)) return $found;
	$rules = include dirname(__DIR__).'/tools/deprecations.php';
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		if ($file->isFile() && preg_match('/\.php$/', $file->getFilename()) && preg_match($rules['i18n-lang-variable']['pattern'], file_get_contents($file->getPathname()))) $found[] = $file->getPathname();
	}
	return $found;
};

return array(
	'i18n-return-array' => array(
		'description' => 'i18n dictionaries that set $lang return it',
		'needed' => function ($c) use ($dictionaries) { return (bool)$dictionaries($c['app'].'/i18n'); },
		'apply' => function ($c) use ($dictionaries) {
			$changed = array();
			foreach ($dictionaries($c['app'].'/i18n') as $file) {
				$source = rtrim(preg_replace('/\?>\s*$/', '', file_get_contents($file)));
				file_put_contents($file, $source."\nreturn \$lang;\n");
				$changed[] = substr($file, strlen($c['root']) + 1);
			}
			return 'changed '.implode(', ', $changed);
		},
	),
);
