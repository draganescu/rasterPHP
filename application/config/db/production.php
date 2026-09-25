<?php
// Database connection for the "production" environment.
//
// Frozen: the schema never changes at request time. After deploying new
// templates run `RASTER_ENV=production php bin/raster schema --apply`
// to add the new tables and columns.
$active   = true;
$dsn      = 'sqlite:'.(getenv('RASTER_DB') ?: APPBASE.'data/raster.sqlite');
$user     = null;
$password = null;
$frozen   = true;
