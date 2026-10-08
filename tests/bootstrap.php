<?php
/**
 * Test bootstrap: loads the classes without WordPress
 *
 * @package Ntrnllnk
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';