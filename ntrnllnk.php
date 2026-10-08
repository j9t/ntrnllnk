<?php
/**
 * Plugin Name:       ntrnllnk
 * Plugin URI:        https://github.com/j9t/ntrnllnk
 * Description:       Links related posts automatically, based on their content, links, and terms.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Jens Oliver Meiert
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ntrnllnk
 * Domain Path:       /languages
 * Update URI:        https://github.com/j9t/ntrnllnk
 *
 * @package Ntrnllnk
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'Ntrnllnk\\' ) ) {
			require __DIR__ . '/src/' . substr( $class_name, strlen( 'Ntrnllnk\\' ) ) . '.php';
		}
	}
);

Ntrnllnk\Plugin::register( __FILE__ );