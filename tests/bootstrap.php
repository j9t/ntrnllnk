<?php
/**
 * Test bootstrap: loads the classes and stand-ins for WordPress
 *
 * @package Ntrnllnk
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'ntrnllnk/ntrnllnk.php' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/stubs.php';