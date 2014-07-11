<?php 
controller::route( 'login' )->to( 'login' )->from( 'cms_admin' );
controller::route( 'login/logout' )->to( 'login' )->from( 'cms_admin' );