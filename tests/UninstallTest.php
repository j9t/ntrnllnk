<?php
/**
 * Tests for uninstall.php
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Plugin;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	public function test_uninstall_removes_all_stored_data_and_scheduled_events(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file
		$constants = ( new \ReflectionClass( Plugin::class ) )->getConstants();
		// Checked with their cleanup functions, as identifiers may share values (like the rebuild option and hook)
		$functions = [
			'HOOK'      => 'wp_clear_scheduled_hook',
			'META'      => 'delete_post_meta_by_key',
			'OPTION'    => 'delete_option',
			'TRANSIENT' => 'delete_transient',
		];

		$count = 0;
		foreach ( $functions as $prefix => $function ) {
			foreach ( $constants as $constant => $name ) {
				if ( str_starts_with( $constant, $prefix . '_' ) && is_string( $name ) ) {
					$this->assertStringContainsString( $function . "( '" . $name . "' );", $uninstall, $constant . ' is not removed on uninstall' );
					++$count;
				}
			}
		}
		$this->assertSame( 7, $count );
	}
}