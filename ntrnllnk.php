<?php
/**
 * Plugin Name:       ntrnllnk
 * Plugin URI:        https://github.com/j9t/ntrnllnk
 * Description:       Automatic internal linking, in the content and via lists of related posts.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Jens Oliver Meiert
 * Author URI:        https://meiert.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ntrnllnk
 * Domain Path:       /languages
 *
 * @package Ntrnllnk
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		// Unknown classes are left to other autoloaders, as `class_exists()` expects
		if ( ! preg_match( '/^Ntrnllnk\\\\(\w+)$/', $class_name, $match ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . $match[1] . '.php';
		if ( is_file( $file ) ) {
			require $file;
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