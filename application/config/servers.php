<?php
// Maps hosts (regular expressions matched against the base URL) to
// environments. Each environment has a database file in config/db/.
// Hosts that match nothing run as "production". RASTER_ENV overrides all.
$servers[ 'localhost' ] = 'development';
$servers[ '127\.0\.0\.1' ] = 'development';
