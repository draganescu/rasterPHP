<?php
// Upgrading an app to Raster 2.1.1.
//
// /api used to answer for every public method of every application model,
// to anyone. From 2.1.1 a model offers methods there only by listing them in
// static function api(). Sites made before keep the old behaviour through
// config api_open until their models list what they offer; `raster doctor`
// warns about it, and 2.2.0 removes it.
return array(
	'api-open' => array(
		'description' => 'keep /api open for models that don\'t list their methods yet (config api_open; list them in static function api() and remove it)',
		'needed' => function ($c) {
			$config = $c['app'].'/config/the_app.php';
			if (is_file($config) && strpos(file_get_contents($config), 'api_open') !== false) return false;
			// only an app with a model that doesn't list its methods
			foreach (glob($c['app'].'/models/*/*.php') ?: array() as $file) {
				if (basename($file, '.php') !== basename(dirname($file))) continue;
				if (strpos(basename($file), 'the_') === 0) continue;
				if (strpos(file_get_contents($file), 'function api(') === false) return true;
			}
			return false;
		},
		'apply' => function ($c) {
			$config = $c['app'].'/config/the_app.php';
			$before = is_file($config) ? rtrim(preg_replace('/\?>\s*$/', '', file_get_contents($config)))."\n" : "<?php\n";
			file_put_contents($config, $before."\n"
				."// Added by `raster upgrade` for Raster 2.1.1. Until 2.1.1 every public method\n"
				."// of every model answered at /api/<model>/<method>, to anyone. Now a model\n"
				."// offers methods there only by listing them: static function api() {\n"
				."// return array('method' => 'visitor'); } (or member, editor, admin). This line\n"
				."// keeps the old behaviour for models that don't list theirs yet. List what\n"
				."// each model offers, then remove it: it stops working in 2.2.0.\n"
				."config::set('api_open')->to(true);\n");
			return 'config/the_app.php keeps /api open; `raster doctor` lists it until you close it';
		},
	),
);
