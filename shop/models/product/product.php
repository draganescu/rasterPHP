<?php
// The pieces for sale. Products are records the studio writes: the type
// makes them public (everyone sees the catalogue) and lets the description
// hold HTML; check() keeps a price and never lets stock go below zero, which
// is what stops two checkouts from selling the last mug twice (see
// order::checkout, which lowers stock inside a transaction).
class product
{
	static function types() {
		return array('product' => array(
			'fields' => array(
				'name' => '', 'price' => '0.00', 'description' => '', 'photo' => '',
				'stock' => 0, 'category' => '',
			),
			'public' => true,
			'html' => array('description'),
		));
	}

	static function check($type, $after, $before) {
		// deleting is fine: orders keep their own copy of what was sold
		if ($after === null) return array();
		$problems = array();
		if (trim((string)$after['name']) === '') $problems[] = 'name_missing';
		if (!is_numeric($after['price']) || (float)$after['price'] <= 0) $problems[] = 'price_invalid';
		if (!is_numeric($after['stock']) || (int)$after['stock'] < 0) $problems[] = 'sold_out';
		return $problems;
	}

	// a product visitors may buy, by its slug
	static function for_sale($slug) {
		if (!preg_match('/^[a-z0-9\-]+$/', (string)$slug)) return null;
		$found = cms_records::find('product', array('slug' => $slug));
		if (!$found || (string)$found[0]['enabled'] === '0') return null;
		return $found[0];
	}
}
