<?php
/** TEST-ONLY: this sandbox serves the mock VezmoPay API from 127.0.0.1 over http. */
define( 'WP_ENVIRONMENT_TYPE', 'local' );
add_filter( 'vezmopay_allowed_api_hosts', function ( $hosts ) {
	$hosts[] = '127.0.0.1';
	$hosts[] = 'localhost';
	return $hosts;
} );
