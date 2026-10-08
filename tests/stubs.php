<?php
/**
 * Minimal stand-ins for WordPress classes and constants, for testing without WordPress
 *
 * @package Ntrnllnk
 */

const MINUTE_IN_SECONDS = 60;
const DAY_IN_SECONDS    = 86400;

/**
 * Stand-in for WP_Post
 */
final class WP_Post {

	public int $ID = 0;

	public string $post_type = 'post';

	public string $post_status = 'publish';

	public string $post_title = '';

	public string $post_content = '';

	/**
	 * Creates a post from database fields, like WP_Post
	 *
	 * @param object $post Fields.
	 */
	public function __construct( object $post ) {
		foreach ( get_object_vars( $post ) as $name => $value ) {
			$this->$name = $value;
		}
	}
}

/**
 * Stand-in for WP_Taxonomy
 */
final class WP_Taxonomy {

	public bool $public = true;
}