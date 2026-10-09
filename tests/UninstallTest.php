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
		$names     = array_filter( ( new \ReflectionClass( Plugin::class ) )->getConstants(), fn( string $constant ): bool => (bool) preg_match( '/^(HOOK|META|OPTION|TRANSIENT)_/', $constant ), ARRAY_FILTER_USE_KEY );

		$this->assertNotEmpty( $names );
		foreach ( $names as $constant => $name ) {
			$this->assertStringContainsString( "'" . $name . "'", $uninstall, $constant . ' is not removed on uninstall' );
		}
	}
}
