<?php
/**
 * Removes the plugin’s data
 *
 * @package Ntrnllnk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_ntrnllnk_related' );
wp_clear_scheduled_hook( 'ntrnllnk_rebuild' );
wp_clear_scheduled_hook( 'ntrnllnk_rebuild_daily' );