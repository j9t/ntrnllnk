<?php
/**
 * Test bootstrap: loads the classes and stand-ins for WordPress
 *
 * @package Ntrnllnk
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/stubs.php';