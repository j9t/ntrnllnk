<?php
/**
 * Plugin Name:       ntrnllnk
 * Plugin URI:        https://github.com/j9t/ntrnllnk
 * Description:       Links related posts based on their content and links.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Jens Oliver Meiert
 * Author URI:        https://meiert.com/
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

/**
 * Outputs the related posts of a post, for placing them in a theme
 *
 * @param int|null $id Post ID; defaults to the current post.
 */
function ntrnllnk_render( ?int $id = null ): void {
	echo Ntrnllnk\Plugin::html( $id ?? (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
}