<?php
/**
 * Tests for ntrnllnk.php
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NtrnllnkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'register_activation_hook' )->justReturn();
		Functions\when( 'register_deactivation_hook' )->justReturn();
		Functions\when( 'plugin_basename' )->justReturn( 'ntrnllnk/ntrnllnk.php' );
		require_once dirname( __DIR__ ) . '/ntrnllnk.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_autoloader_ignores_unknown_classes(): void {
		$this->assertFalse( class_exists( 'Ntrnllnk\Missing' ) );
		$this->assertFalse( class_exists( 'Ntrnllnk\Sub\Plugin' ) );
	}
}