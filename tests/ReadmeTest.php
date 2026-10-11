<?php
/**
 * Tests for readme.txt, which the WordPress plugin directory reads
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase {

	private static string $readme;

	public static function setUpBeforeClass(): void {
		self::$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file
	}

	/**
	 * Returns a field of a header, like `Requires PHP: 8.1`
	 *
	 * @param string $header Header.
	 * @param string $name Field name.
	 */
	private static function field( string $header, string $name ): ?string {
		return preg_match( '/^(?: \* )?' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $header, $match ) ? trim( $match[1] ) : null;
	}

	public function test_readme_names_plugin(): void {
		$this->assertStringStartsWith( "=== ntrnllnk ===\n", self::$readme );
	}

	public function test_readme_matches_plugin_header(): void {
		$header = (string) file_get_contents( dirname( __DIR__ ) . '/ntrnllnk.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file
		$this->assertSame( self::field( $header, 'Version' ), self::field( self::$readme, 'Stable tag' ) );
		foreach ( [ 'Requires at least', 'Requires PHP', 'License', 'License URI' ] as $name ) {
			$this->assertNotNull( self::field( $header, $name ), $name );
			$this->assertSame( self::field( $header, $name ), self::field( self::$readme, $name ), $name );
		}
	}

	public function test_readme_stays_within_directory_limits(): void {
		$this->assertLessThanOrEqual( 5, count( explode( ',', (string) self::field( self::$readme, 'Tags' ) ) ) );
		if ( ! preg_match( '/^=== .+? ===\n(?:[^\n]+\n)+\n([^\n]+)/', self::$readme, $match ) ) {
			$this->fail( 'Short description is missing' );
		}
		$this->assertLessThanOrEqual( 150, mb_strlen( $match[1] ) );
	}

	public function test_readme_changelog_points_to_changelog(): void {
		$this->assertStringEndsWith( "== Changelog ==\n\nSee the [changelog on GitHub](https://github.com/j9t/ntrnllnk/blob/main/CHANGELOG.md).", self::$readme );
	}
}