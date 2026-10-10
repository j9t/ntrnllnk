<?php
/**
 * Tests for Settings
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Ranker;
use Ntrnllnk\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	/**
	 * Returns sanitized settings for form input, with `post` and `page` as available post types
	 *
	 * @param array<string, mixed> $input Form input.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		return Settings::sanitize( $input, [ 'post', 'page' ] );
	}

	public function test_sanitize_returns_all_settings(): void {
		$this->assertSame( array_keys( Settings::DEFAULTS ), array_keys( $this->sanitize( [] ) ) );
	}

	public function test_sanitize_drops_unknown_settings(): void {
		$this->assertArrayNotHasKey( 'unknown', $this->sanitize( [ 'unknown' => 1 ] ) );
	}

	public function test_sanitize_turns_missing_checkboxes_off(): void {
		$settings = $this->sanitize( [ 'debug' => '1' ] );

		$this->assertTrue( $settings['debug'] );
		$this->assertFalse( $settings['links_inline'] );
		$this->assertFalse( $settings['links_class'] );
		$this->assertFalse( $settings['links_inline_single_words'] );
	}

	public function test_sanitize_keeps_available_post_types_only(): void {
		$this->assertSame( [ 'page' ], $this->sanitize( [ 'post_types' => [ 'page', 'product' ] ] )['post_types'] );
		$this->assertSame( [], $this->sanitize( [] )['post_types'] );
	}

	public function test_sanitize_limits_numbers(): void {
		$settings = $this->sanitize(
			[
				'count'            => '-3',
				'links_inline_max' => '99',
				'score_min'        => '2',
				'priority'         => '8',
			]
		);

		$this->assertSame( 0, $settings['count'] );
		$this->assertSame( Settings::COUNT_MAX, $settings['links_inline_max'] );
		$this->assertSame( 1.0, $settings['score_min'] );
		$this->assertSame( 8, $settings['priority'] );
	}

	public function test_sanitize_falls_back_to_defaults_for_invalid_choices(): void {
		$settings = $this->sanitize(
			[
				'heading_level' => '7',
				'urls'          => 'protocol-relative',
				'placement'     => 'sidebar',
				'language'      => 'fr',
				'count'         => 'many',
			]
		);

		$this->assertSame( Settings::DEFAULTS['heading_level'], $settings['heading_level'] );
		$this->assertSame( Settings::DEFAULTS['urls'], $settings['urls'] );
		$this->assertSame( Settings::DEFAULTS['placement'], $settings['placement'] );
		$this->assertSame( Settings::DEFAULTS['language'], $settings['language'] );
		$this->assertSame( Settings::DEFAULTS['count'], $settings['count'] );
	}

	public function test_sanitize_accepts_heading_levels_and_auto(): void {
		$this->assertSame( 3, $this->sanitize( [ 'heading_level' => '3' ] )['heading_level'] );
		$this->assertSame( 'auto', $this->sanitize( [ 'heading_level' => 'auto' ] )['heading_level'] );
	}

	public function test_sanitize_collapses_whitespace_in_heading(): void {
		$this->assertSame( 'Related posts', $this->sanitize( [ 'heading' => "  Related\n posts " ] )['heading'] );
	}

	public function test_sanitize_splits_excluded_phrases_into_lines(): void {
		$this->assertSame( [ 'Happy End', 'Miss Marple' ], $this->sanitize( [ 'links_inline_exclude_phrases' => "Happy End\r\n\r\n Miss Marple \nHappy End" ] )['links_inline_exclude_phrases'] );
	}

	public function test_sanitize_extracts_excluded_post_ids(): void {
		$this->assertSame( [ 123, 456 ], $this->sanitize( [ 'links_inline_exclude_posts' => '123, 456 123 0 abc' ] )['links_inline_exclude_posts'] );
	}

	public function test_sanitize_gives_links_the_rest_of_the_weight(): void {
		$this->assertSame(
			[
				'words' => 0.7,
				'links' => 0.3,
			],
			$this->sanitize( [ 'weights' => [ 'words' => '0.7' ] ] )['weights']
		);
		$this->assertSame( 1.0, $this->sanitize( [ 'weights' => [ 'words' => '3' ] ] )['weights']['words'] );
	}

	public function test_sanitize_returns_sanitized_settings_unchanged(): void {
		$settings = $this->sanitize(
			[
				'post_types'                   => [ 'post' ],
				'count'                        => '3',
				'heading'                      => 'Related',
				'links_inline'                 => '1',
				'links_inline_exclude_phrases' => "Happy End\nMiss Marple",
				'links_inline_exclude_posts'   => '123',
				'weights'                      => [ 'words' => '0.5' ],
			]
		);

		$this->assertSame( $settings, $this->sanitize( $settings ) );
	}

	public function test_merge_fills_in_defaults_and_drops_unknown_settings(): void {
		$settings = Settings::merge(
			[
				'count'   => 3,
				'unknown' => true,
			]
		);

		$this->assertSame( array_keys( Settings::DEFAULTS ), array_keys( $settings ) );
		$this->assertSame( 3, $settings['count'] );
		$this->assertSame( [ 'post' ], Settings::merge( false )['post_types'] );
	}

	public function test_rebuilding_returns_settings_that_decide_related_posts_and_phrases(): void {
		$this->assertSame( Settings::REBUILD, array_keys( Settings::rebuilding( Settings::DEFAULTS ) ) );
		$this->assertContains( 'links_inline_single_words', Settings::REBUILD );
	}

	public function test_defaults_use_ranker_weights(): void {
		$this->assertSame( Ranker::WEIGHTS, Settings::DEFAULTS['weights'] );
	}
}