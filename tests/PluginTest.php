<?php
/**
 * Tests for Plugin, with WordPress functions simulated by Brain Monkey
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Ntrnllnk\Plugin;
use Ntrnllnk\Settings;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	// Counts Mockery expectations (of WordPress calls) as assertions
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// Unlike Brain Monkey’s stub, WordPress keeps relative URLs relative
		Functions\when( 'esc_url' )->alias( fn( string $url ): string => htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'get_permalink' )->alias( fn( \WP_Post $post ): string => 'https://example.com/' . $post->ID . '/' );
		Functions\when( 'get_the_title' )->alias( fn( \WP_Post $post ): string => $post->post_title );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Options by name, as `get_option()` returns them
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Stores settings, as saved on the settings page
	 *
	 * @param array<string, mixed> $settings Settings to override.
	 */
	private function settings( array $settings ): void {
		$this->options[ Plugin::OPTION_SETTINGS ] = $settings;
	}

	/**
	 * Sets up a single post view
	 *
	 * @param int $id Post ID.
	 */
	private function view( int $id ): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_main_query' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( $id );
	}

	/**
	 * Returns a post, as WordPress builds it from database fields
	 *
	 * @param array<string, int|string> $fields Fields.
	 */
	private function post( array $fields = [] ): \WP_Post {
		return new \WP_Post( (object) $fields );
	}

	/**
	 * Lets a rebuild run: unlocked, with caches primed in batches
	 */
	private function expect_rebuild_runs(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'delete_transient' )->once()->with( Plugin::TRANSIENT_LOCK );
		Functions\when( 'wp_raise_memory_limit' )->justReturn( false );
		Functions\when( 'update_object_term_cache' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( [] );
	}

	private function expect_rebuild_soon( bool $scheduled ): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->times( $scheduled ? 1 : 0 )->with( Plugin::HOOK_REBUILD );
		Functions\expect( 'wp_schedule_single_event' )->times( $scheduled ? 1 : 0 )->with( Mockery::type( 'int' ), Plugin::HOOK_REBUILD );
	}

	private function expect_rebuild( bool $scheduled ): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->times( $scheduled ? 1 : 0 )->with( Mockery::type( 'int' ), Plugin::HOOK_REBUILD );
	}

	public function test_transition_schedules_rebuild_for_newly_published_posts(): void {
		$this->expect_rebuild( true );
		Plugin::on_transition_post_status( 'publish', 'draft', $this->post() );
	}

	public function test_transition_leaves_updates_of_published_posts_to_post_updated(): void {
		$this->expect_rebuild( false );
		Plugin::on_transition_post_status( 'publish', 'publish', $this->post() );
	}

	public function test_transition_schedules_rebuild_for_unpublished_posts(): void {
		$this->expect_rebuild( true );
		Plugin::on_transition_post_status( 'draft', 'publish', $this->post() );
	}

	public function test_transition_ignores_drafts(): void {
		$this->expect_rebuild( false );
		Plugin::on_transition_post_status( 'draft', 'draft', $this->post() );
	}

	public function test_transition_ignores_other_post_types(): void {
		$this->expect_rebuild( false );
		Plugin::on_transition_post_status( 'publish', 'draft', $this->post( [ 'post_type' => 'page' ] ) );
	}

	public function test_post_updated_schedules_rebuild_when_content_changes(): void {
		$this->expect_rebuild( true );
		Plugin::on_post_updated( 7, $this->post( [ 'post_content' => 'New' ] ), $this->post( [ 'post_content' => 'Old' ] ) );
	}

	public function test_post_updated_schedules_rebuild_when_type_changes(): void {
		$this->expect_rebuild( true );
		Plugin::on_post_updated( 7, $this->post( [ 'post_type' => 'page' ] ), $this->post() );
	}

	public function test_post_updated_ignores_unchanged_posts(): void {
		$this->expect_rebuild( false );
		Plugin::on_post_updated( 7, $this->post( [ 'post_name' => 'new-slug' ] ), $this->post( [ 'post_name' => 'old-slug' ] ) );
	}

	public function test_post_updated_ignores_status_changes(): void {
		$this->expect_rebuild( false );
		Plugin::on_post_updated(
			7,
			$this->post(
				[
					'post_status'  => 'draft',
					'post_content' => 'New',
				]
			),
			$this->post( [ 'post_content' => 'Old' ] )
		);
	}

	public function test_post_updated_ignores_other_post_types(): void {
		$this->expect_rebuild( false );
		Plugin::on_post_updated(
			7,
			$this->post(
				[
					'post_type'    => 'page',
					'post_content' => 'New',
				]
			),
			$this->post( [ 'post_type' => 'page' ] )
		);
	}

	public function test_schedule_rebuild_skips_pending_rebuild(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1700000000 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Plugin::schedule_rebuild();
	}

	public function test_set_object_terms_schedules_rebuild_on_change(): void {
		Functions\when( 'get_post' )->justReturn( $this->post( [ 'ID' => 7 ] ) );
		$this->expect_rebuild( true );
		Plugin::on_set_object_terms( 7, [], [ 3, 4 ], 'category', false, [ 3 ] );
	}

	public function test_set_object_terms_ignores_unchanged_terms(): void {
		Functions\when( 'get_post' )->justReturn( $this->post( [ 'ID' => 7 ] ) );
		$this->expect_rebuild( false );
		Plugin::on_set_object_terms( 7, [], [ 4, 3 ], 'category', false, [ 3, 4 ] );
	}

	public function test_set_object_terms_ignores_drafts(): void {
		Functions\when( 'get_post' )->justReturn( $this->post( [ 'post_status' => 'draft' ] ) );
		$this->expect_rebuild( false );
		Plugin::on_set_object_terms( 7, [], [ 3, 4 ], 'category', false, [ 3 ] );
	}

	public function test_delete_term_schedules_rebuild_if_posts_had_it(): void {
		$this->expect_rebuild( true );
		Plugin::on_delete_term( 5, 9, 'category', null, [ 7 ] );
	}

	public function test_delete_term_ignores_unused_terms(): void {
		$this->expect_rebuild( false );
		Plugin::on_delete_term( 5, 9, 'category', null, [] );
	}

	public function test_register_output_appends_list_and_links_content_by_default(): void {
		Functions\expect( 'add_shortcode' )->once()->with( 'ntrnllnk', [ Plugin::class, 'shortcode' ] );
		Plugin::register_output();

		$this->assertTrue( has_filter( 'the_content', [ Plugin::class, 'render' ], 20 ) );
		$this->assertTrue( has_filter( 'the_content', [ Plugin::class, 'link_content' ], 12 ) );
	}

	public function test_register_output_follows_placement_and_in_content_links(): void {
		$this->settings(
			[
				'placement'    => 'manual',
				'links_inline' => false,
			]
		);
		Functions\when( 'add_shortcode' )->justReturn( true );
		Plugin::register_output();

		$this->assertFalse( has_filter( 'the_content', [ Plugin::class, 'render' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ Plugin::class, 'link_content' ] ) );
	}

	public function test_register_output_uses_priority(): void {
		$this->settings( [ 'priority' => 8 ] );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Plugin::register_output();

		$this->assertTrue( has_filter( 'the_content', [ Plugin::class, 'render' ], 8 ) );
	}

	public function test_settings_merge_stored_settings_over_defaults(): void {
		$this->settings(
			[
				'count'   => 3,
				'unknown' => true,
			]
		);
		$settings = Plugin::settings();

		$this->assertSame( 3, $settings['count'] );
		$this->assertSame( Settings::DEFAULTS['score_min'], $settings['score_min'] );
		$this->assertArrayNotHasKey( 'unknown', $settings );
	}

	public function test_settings_use_default_heading_unless_set(): void {
		$this->assertSame( 'Further reading', Plugin::settings()['heading'] );

		$this->settings( [ 'heading' => 'Related posts' ] );
		$this->assertSame( 'Related posts', Plugin::settings()['heading'] );
	}

	public function test_settings_saved_rebuilds_soon_when_ranking_settings_change(): void {
		$this->expect_rebuild_soon( true );
		Plugin::on_settings_saved( [], [ 'score_min' => 0.06 ] + Settings::DEFAULTS );
	}

	public function test_settings_saved_skips_rebuild_when_only_display_settings_change(): void {
		$this->expect_rebuild_soon( false );
		Plugin::on_settings_saved( Settings::DEFAULTS, [ 'heading' => 'Related posts' ] + Settings::DEFAULTS );
	}

	public function test_settings_added_rebuilds_soon_unless_ranking_settings_are_defaults(): void {
		$this->expect_rebuild_soon( true );
		Plugin::on_settings_added( Plugin::OPTION_SETTINGS, [ 'count' => 3 ] + Settings::DEFAULTS );
	}

	public function test_rebuild_soon_replaces_pending_rebuild(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Plugin::HOOK_REBUILD );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::on( fn( int $time ): bool => abs( $time - time() ) < 5 ), Plugin::HOOK_REBUILD );
		Plugin::rebuild_soon();
	}

	public function test_html_returns_empty_string_without_related_posts(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( '', Plugin::html( 7 ) );
	}

	public function test_html_returns_empty_string_with_count_zero(): void {
		$this->settings( [ 'count' => 0 ] );
		Functions\expect( 'get_post_meta' )->never();

		$this->assertSame( '', Plugin::html( 7 ) );
	}

	public function test_html_lists_published_related_posts(): void {
		Functions\when( 'get_post_meta' )->justReturn(
			[
				11 => [ 'words' => 0.08 ],
				12 => [ 'links' => 0.05 ],
			]
		);
		Functions\expect( 'get_posts' )->once()->with(
			Mockery::subset(
				[
					'post__in'    => [ 11, 12 ],
					'post_status' => 'publish',
				]
			)
		)->andReturn(
			[
				$this->post(
					[
						'ID'         => 11,
						'post_title' => 'A',
					]
				),
				$this->post(
					[
						'ID'         => 12,
						'post_title' => 'B & C',
					]
				),
			]
		);

		$this->assertSame(
			'<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/">A</a></li><li><a href="https://example.com/12/">B &amp; C</a></li></ul></section>',
			Plugin::html( 7 )
		);
	}

	public function test_html_lists_related_posts_with_scores_without_debugging_data_by_default(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( '<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/"></a></li></ul></section>', Plugin::html( 7 ) );
	}

	public function test_html_leaves_out_posts_below_minimum_score(): void {
		Functions\when( 'get_post_meta' )->justReturn(
			[
				11 => [ 'words' => 0.05 ],
				12 => [ 'words' => 0.03 ],
			]
		);
		Functions\expect( 'get_posts' )->once()->with( Mockery::subset( [ 'post__in' => [ 11 ] ] ) )->andReturn( [ $this->post( [ 'ID' => 11 ] ) ] );

		$this->assertStringNotContainsString( '/12/', Plugin::html( 7 ) );
	}

	public function test_html_returns_empty_string_with_posts_below_minimum_score_only(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 12 => [ 'words' => 0.03 ] ] );
		Functions\expect( 'get_posts' )->never();

		$this->assertSame( '', Plugin::html( 7 ) );
	}

	public function test_html_shows_scores_and_near_misses_to_editors_when_debugging(): void {
		$this->settings( [ 'debug' => true ] );
		Functions\when( 'get_post_meta' )->justReturn(
			[
				11 => [
					'words' => 0.031,
					'links' => 0.021,
				],
				12 => [ 'links' => 0.03 ],
				13 => [ 'words' => 0.1 ],
			]
		);
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ), $this->post( [ 'ID' => 12 ] ), $this->post( [ 'ID' => 13 ] ) ] );
		Functions\expect( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		$this->assertSame(
			'<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/"></a> <span class="ntrnllnk-debug">[score: 0.052 – words 0.031, links 0.021]</span></li><li><del title="Below score_min (0.04), so not shown to visitors"><a href="https://example.com/12/"></a></del> <span class="ntrnllnk-debug">[score: 0.03 – words 0, links 0.03]</span></li><li><a href="https://example.com/13/"></a> <span class="ntrnllnk-debug">[score: 0.1 – words 0.1, links 0]</span></li></ul></section>',
			Plugin::html( 7 )
		);
	}

	public function test_html_hides_debugging_data_and_near_misses_from_other_users(): void {
		$this->settings( [ 'debug' => true ] );
		Functions\when( 'get_post_meta' )->justReturn(
			[
				11 => [ 'words' => 0.05 ],
				12 => [ 'words' => 0.03 ],
			]
		);
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/"></a></li></ul></section>', Plugin::html( 7 ) );
	}

	public function test_html_follows_heading_level_and_urls(): void {
		$this->settings(
			[
				'heading_level' => 'auto',
				'urls'          => 'relative',
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Functions\when( 'get_post_field' )->justReturn( '<p>Intro</p><h3>Section</h3><h4>Detail</h4>' );
		Functions\when( 'wp_make_link_relative' )->alias( fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', '', $url ) );

		$this->assertSame( '<section class="ntrnllnk"><h3>Further reading</h3><ul><li><a href="/11/"></a></li></ul></section>', Plugin::html( 7 ) );
	}

	public function test_html_applies_html_filter(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Filters\expectApplied( 'ntrnllnk_html' )->once()->with( Mockery::type( 'string' ), Mockery::type( 'array' ), 7 )->andReturn( '<p>Custom</p>' );

		$this->assertSame( '<p>Custom</p>', Plugin::html( 7 ) );
	}

	public function test_render_appends_list_to_single_posts(): void {
		$this->view( 21 );
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );

		$this->assertStringStartsWith( "<p>Text</p>\n<section class=\"ntrnllnk\">", Plugin::render( '<p>Text</p>' ) );
	}

	public function test_render_leaves_other_views_alone(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_main_query' )->justReturn( true );

		$this->assertSame( '<p>Text</p>', Plugin::render( '<p>Text</p>' ) );
	}

	public function test_render_skips_posts_with_shortcode(): void {
		$this->view( 22 );
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Plugin::shortcode();

		$this->assertSame( '<p>Text</p>', Plugin::render( '<p>Text</p>' ) );
	}

	public function test_html_leaves_links_without_class(): void {
		$this->settings( [ 'links_class' => true ] );
		Functions\when( 'get_post_meta' )->justReturn( [ 11 => [ 'words' => 0.05 ] ] );
		Functions\when( 'get_posts' )->justReturn(
			[
				$this->post(
					[
						'ID'         => 11,
						'post_title' => 'A',
					]
				),
			]
		);

		$this->assertSame(
			'<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/">A</a></li></ul></section>',
			Plugin::html( 7 )
		);
	}

	public function test_link_content_adds_class_to_links(): void {
		$this->settings(
			[
				'links_inline' => true,
				'links_class'  => true,
			]
		);
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [ 'Agatha Christie' => 11 ];
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );

		$this->assertSame(
			'<p><a href="https://example.com/11/" class="ntrnllnk-inline">Agatha Christie</a></p>',
			Plugin::link_content( '<p>Agatha Christie</p>' )
		);
	}

	public function test_link_content_links_published_targets_only_once(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [
			'Agatha Christie' => 11,
			'Harry Potter'    => 12,
			'Stephen King'    => 7,
		];
		Functions\expect( 'get_posts' )->once()->with( Mockery::subset( [ 'post__in' => [ 11, 12 ] ] ) )->andReturn(
			[
				$this->post( [ 'ID' => 11 ] ),
				$this->post( [ 'ID' => 12 ] ),
			]
		);
		// The content already links to post 12, by its short link
		Functions\when( 'url_to_postid' )->alias( fn( string $url ): int => 'https://example.com/?p=12' === $url ? 12 : 0 );

		$this->assertSame(
			'<p><a href="/?p=12">Book</a> on Harry Potter</p><p><a href="https://example.com/11/">Agatha Christie</a></p><p>Stephen King</p>',
			Plugin::link_content( '<p><a href="/?p=12">Book</a> on Harry Potter</p><p>Agatha Christie</p><p>Stephen King</p>' )
		);
	}

	public function test_link_content_skips_excluded_phrases(): void {
		$this->settings(
			[
				'links_inline'                 => true,
				'links_inline_exclude_phrases' => [ 'Agatha Christie' ],
			]
		);
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [
			'Agatha Christie' => 11,
			'Harry Potter'    => 12,
		];
		Functions\expect( 'get_posts' )->once()->with( Mockery::subset( [ 'post__in' => [ 12 ] ] ) )->andReturn( [ $this->post( [ 'ID' => 12 ] ) ] );

		$this->assertSame(
			'<p>Agatha Christie</p><p><a href="https://example.com/12/">Harry Potter</a></p>',
			Plugin::link_content( '<p>Agatha Christie</p><p>Harry Potter</p>' )
		);
	}

	public function test_link_content_skips_lookups_without_mentioned_phrases(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [ 'Agatha Christie' => 11 ];
		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'url_to_postid' )->never();

		$this->assertSame( '<p><a href="/?p=12">Harry Potter</a></p>', Plugin::link_content( '<p><a href="/?p=12">Harry Potter</a></p>' ) );
	}

	public function test_link_content_skips_excluded_posts(): void {
		$this->settings(
			[
				'links_inline'               => true,
				'links_inline_exclude_posts' => [ 7 ],
			]
		);
		$this->view( 7 );
		Filters\expectApplied( 'ntrnllnk_phrases' )->never();

		$this->assertSame( '<p>Agatha Christie</p>', Plugin::link_content( '<p>Agatha Christie</p>' ) );
	}

	public function test_link_content_applies_phrases_filter(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [ 'Agatha Christie' => 11 ];
		Filters\expectApplied( 'ntrnllnk_phrases' )->once()->with( [ 'Agatha Christie' => 11 ], 7 )->andReturn( [ 'Kluftinger' => 13 ] );
		Functions\expect( 'get_posts' )->once()->with( Mockery::subset( [ 'post__in' => [ 13 ] ] ) )->andReturn( [ $this->post( [ 'ID' => 13 ] ) ] );

		$this->assertSame(
			'<p>Agatha Christie</p><p><a href="https://example.com/13/">Kluftinger</a></p>',
			Plugin::link_content( '<p>Agatha Christie</p><p>Kluftinger</p>' )
		);
	}

	public function test_link_content_ignores_links_in_list(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		$this->options[ Plugin::OPTION_PHRASES ] = [ 'Agatha Christie' => 11 ];
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Functions\when( 'url_to_postid' )->justReturn( 11 );

		$this->assertSame(
			'<p><a href="https://example.com/11/">Agatha Christie</a></p><section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/">Agatha Christie</a></li></ul></section>',
			Plugin::link_content( '<p>Agatha Christie</p><section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="https://example.com/11/">Agatha Christie</a></li></ul></section>' )
		);
	}

	public function test_rebuild_stores_related_posts_and_phrases(): void {
		$posts = [
			$this->post(
				[
					'ID'           => 1,
					'post_title'   => 'Agatha Christie in Order',
					'post_content' => '<p>Agatha Christie wrote mysteries. Agatha Christie’s detectives. Agatha Christie died in 1976.</p>',
				]
			),
			$this->post(
				[
					'ID'           => 2,
					'post_title'   => 'Mysteries Like Poirot',
					'post_content' => '<p>Detectives and mysteries in the style of Agatha Christie.</p>',
				]
			),
			$this->post(
				[
					'ID'           => 3,
					'post_title'   => 'Gardening',
					'post_content' => '<p>Roses and tulips.</p>',
				]
			),
		];
		Functions\expect( 'get_posts' )->times( 3 )->andReturn( [ 1, 2, 3 ], $posts, [ 3 ] );
		$this->expect_rebuild_runs();
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_post_timestamp' )->alias( fn( \WP_Post $post ): int => $post->ID );
		$stored = [];
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, array $related ) use ( &$stored ): void {
				$stored[ $id ] = $related;
			}
		);
		Functions\expect( 'delete_post_meta' )->once()->with( 3, Plugin::META_KEY );
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_REBUILD, Mockery::on( fn( array $status ): bool => 3 === $status['posts'] && abs( $status['time'] - time() ) < 5 && $status['duration'] >= 0 ), false );
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_PHRASES, [ 'Agatha Christie' => 1 ], true );
		Functions\expect( 'wp_set_option_autoload' )->once()->with( Plugin::OPTION_PHRASES, true );

		Plugin::rebuild();

		$this->assertSame( [ 1, 2 ], array_keys( $stored ) );
		$this->assertSame( [ 2 ], array_keys( $stored[1] ) );
		$this->assertSame( [ 1 ], array_keys( $stored[2] ) );
		$this->assertSame( [ 'words' ], array_keys( $stored[1][2] ) );
		$this->assertSame( round( $stored[1][2]['words'], 6 ), $stored[1][2]['words'] );
	}

	public function test_rebuild_keeps_near_misses_when_debugging(): void {
		$posts = [
			$this->post(
				[
					'ID'           => 1,
					'post_content' => '<p>Dragons and elves.</p>',
				]
			),
			$this->post(
				[
					'ID'           => 2,
					'post_content' => '<p>Dragons and elves.</p>',
				]
			),
			$this->post(
				[
					'ID'           => 3,
					'post_content' => '<p>Roses and tulips.</p>',
				]
			),
		];
		// Identical posts without links score 0.6 (the weight of words), which is above half the minimum, but below the minimum itself
		$this->settings(
			[
				'score_min' => 1.0,
				'debug'     => true,
			]
		);
		Functions\expect( 'get_posts' )->times( 3 )->andReturn( [ 1, 2, 3 ], $posts, [] );
		$this->expect_rebuild_runs();
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_post_timestamp' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_set_option_autoload' )->justReturn( true );
		Functions\expect( 'update_post_meta' )->once()->with( 1, Plugin::META_KEY, Mockery::on( fn( array $related ): bool => [ 2 ] === array_keys( $related ) ) );
		Functions\expect( 'update_post_meta' )->once()->with( 2, Plugin::META_KEY, Mockery::on( fn( array $related ): bool => [ 1 ] === array_keys( $related ) ) );

		Plugin::rebuild();
	}

	public function test_rebuild_loads_posts_in_batches_without_caching_them(): void {
		$batches = [];
		Functions\when( 'get_posts' )->alias(
			function ( array $args ) use ( &$batches ): array {
				if ( isset( $args['meta_key'] ) ) {
					return [];
				}
				if ( 'ids' === ( $args['fields'] ?? '' ) ) {
					return range( 1, 250 );
				}
				$batches[] = $args;

				return array_map( fn( int $id ): \WP_Post => $this->post( [ 'ID' => $id ] ), $args['post__in'] );
			}
		);
		$this->expect_rebuild_runs();
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_post_timestamp' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_set_option_autoload' )->justReturn( true );

		Plugin::rebuild();

		$this->assertSame( [ 200, 50 ], array_map( fn( array $args ): int => count( $args['post__in'] ), $batches ) );
		$this->assertFalse( $batches[0]['cache_results'] );
	}

	public function test_rebuild_reschedules_while_another_runs(): void {
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\expect( 'get_posts' )->never();
		$this->expect_rebuild( true );

		Plugin::rebuild();
	}

	public function test_rebuild_skips_ranking_with_count_zero(): void {
		$this->settings(
			[
				'count'        => 0,
				'links_inline' => true,
			]
		);
		$post = $this->post(
			[
				'ID'           => 1,
				'post_title'   => 'Agatha Christie in Order',
				'post_content' => '<p>Agatha Christie wrote mysteries. Agatha Christie’s detectives. Agatha Christie died in 1976.</p>',
			]
		);
		Functions\expect( 'get_posts' )->times( 3 )->andReturn( [ 1 ], [ $post ], [ 1 ] );
		$this->expect_rebuild_runs();
		Functions\expect( 'update_object_term_cache' )->never();
		// Needed for phrases, too, to break ties
		Functions\when( 'get_post_timestamp' )->justReturn( 1 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->once()->with( 1, Plugin::META_KEY );
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_REBUILD, Mockery::type( 'array' ), false );
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_PHRASES, [ 'Agatha Christie' => 1 ], true );
		Functions\when( 'wp_set_option_autoload' )->justReturn( true );

		Plugin::rebuild();
	}
}