<?php
// Overrides the bundled feed model: everything it does, plus a generator name.
class the_feed extends feed {
	function generator() {
		return 'Raster Café feeds';
	}
}
