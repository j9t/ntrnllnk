<?php
/**
 * Removes the plugin’s data
 *
 * @package Ntrnllnk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ntrnllnk_uninstall = function (): void {
	delete_post_meta_by_key( '_ntrnllnk_related' );
	delete_option( 'ntrnllnk_phrases' );
	delete_option( 'ntrnllnk_settings' );
	delete_option( 'ntrnllnk_rebuild' );
	delete_transient( 'ntrnllnk_rebuild_lock' );
	wp_clear_scheduled_hook( 'ntrnllnk_rebuild' );
	wp_clear_scheduled_hook( 'ntrnllnk_rebuild_daily' );
};

// On multisite, from each site, as uninstalling runs only once, and the plugin is not loaded to share `Plugin::on_sites()`
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $ntrnllnk_id_site ) {
		switch_to_blog( (int) $ntrnllnk_id_site );
		$ntrnllnk_uninstall();
		restore_current_blog();
	}
} else {
	$ntrnllnk_uninstall();
}