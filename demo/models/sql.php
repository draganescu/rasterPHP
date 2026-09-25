<?php
// Named queries for any model: database::instance('cafe')->dish_names('coffee').
// sprintf-style placeholders are quoted by the database driver.
$queries['dish_names'] = "SELECT name FROM menudata WHERE category = '%s' ORDER BY name";
