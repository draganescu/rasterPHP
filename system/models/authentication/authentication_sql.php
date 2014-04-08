<?php 
// should do this if we can access the user from their db!
$querries["get_user"] = "select id from users where `email` = '%s' AND `password` = '%s'" ;
$querries["add_user"] = "insert into users (`email`,`password`) values ('%s','%s')";
$querries["get_current"] = "select `username`, `email`, `password` from users where `id` = %d" ;
$querries["email_exists"] = "select `email` from users where `email` = '%s'" ;
$querries["get_users_from_to"] = "SELECT * FROM `customers` C
	LEFT JOIN users U On C.users_id = U.id
ORDER BY C.id DESC
LIMIT %d, %d;" ;