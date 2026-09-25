<?php
$active   = true;
$dsn      = 'sqlite:'.(getenv('RASTER_DB') ?: APPBASE.'data/cafe.sqlite');
$user     = null;
$password = null;
$frozen   = false;
