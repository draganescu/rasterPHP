<?php
// Four pieces on the shelf and an account for the studio, so the example has
// something to sell: php shop/seed.php
//
// Products are records; this goes through cms_records like the page editor
// does, so product::check() looks at every one.
if (PHP_SAPI !== 'cli') exit;
putenv('RASTER_APP=shop');
require_once dirname(__DIR__).'/system/boot.php';
boot::$appname = 'shop';
boot::cli();

$pieces = array(
	array('name' => 'Morning mug', 'price' => '24.00', 'stock' => 6, 'category' => 'mugs', 'photo' => 'img/mug.svg',
		'description' => '<p>Stoneware, 300 ml, glazed in a deep evening blue. Holds heat, fits two hands.</p>'),
	array('name' => 'Soup bowl', 'price' => '32.00', 'stock' => 4, 'category' => 'bowls', 'photo' => 'img/bowl.svg',
		'description' => '<p>A wide bowl in sage green, deep enough for ramen, calm enough for porridge.</p>'),
	array('name' => 'Tall vase', 'price' => '68.00', 'stock' => 1, 'category' => 'vases', 'photo' => 'img/vase.svg',
		'description' => '<p>Thrown in one piece, 28 cm, iron red glaze that pools darker at the base.</p>'),
	array('name' => 'Espresso cup', 'price' => '16.00', 'stock' => 10, 'category' => 'mugs', 'photo' => 'img/mug.svg',
		'description' => '<p>90 ml, a small version of the morning mug.</p>'),
);
foreach ($pieces as $piece) {
	if (cms_records::find('product', array('name' => $piece['name']))) continue;
	cms_records::create('product', $piece);
	echo "added {$piece['name']}\n";
}
if (!authentication::has_users()) {
	authentication::save_user('studio@bluehour.test', 'studio password', 'admin');
	echo "studio account: studio@bluehour.test / studio password (change it)\n";
}
