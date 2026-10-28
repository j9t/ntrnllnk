<?php
/**
 * Tests for uninstall.php
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Ntrnllnk\Plugin;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	// Counts Mockery expectations (of WordPress calls) as assertions
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_uninstall_removes_data_of_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'delete_post_meta_by_key' )->once()->with( Plugin::META_KEY );

		require dirname( __DIR__ ) . '/uninstall.php';
	}

	public function test_uninstall_removes_data_of_each_site_of_network(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->with( Mockery::subset( [ 'number' => 0 ] ) )->andReturn( [ 1, 2 ] );
		Functions\expect( 'switch_to_blog' )->once()->with( 1 )->ordered();
		Functions\expect( 'switch_to_blog' )->once()->with( 2 )->ordered();
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'delete_post_meta_by_key' )->twice()->with( Plugin::META_KEY );

		require dirname( __DIR__ ) . '/uninstall.php';
	}

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