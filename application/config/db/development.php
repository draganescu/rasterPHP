<?php
// Database connection for the "development" environment.
// The file name is the environment name (see config/servers.php).
//
// SQLite needs no setup: the file is created on the first request.
// For MySQL use: $dsn = 'mysql:host=localhost;dbname=raster'; plus $user/$password.
$active   = true;
$dsn      = 'sqlite:'.(getenv('RASTER_DB') ?: APPBASE.'data/raster.sqlite');
$user     = null;
$password = null;
// not frozen: the CMS adds tables and columns as you add annotations to views
$frozen   = false;

// log::enable(); // prints framework events to the browser console
