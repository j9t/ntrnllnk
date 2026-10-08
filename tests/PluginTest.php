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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Overrides settings via the `ntrnllnk_settings` filter
	 *
	 * @param array<string, mixed> $settings Settings to override.
	 */
	private function settings( array $settings ): void {
		Filters\expectApplied( 'ntrnllnk_settings' )->andReturnUsing( fn( array $defaults ): array => array_merge( $defaults, $settings ) );
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
		Functions\when( 'get_post_meta' )->justReturn( [ 11, 12 ] );
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

	public function test_html_follows_heading_level_and_urls(): void {
		$this->settings(
			[
				'heading_level' => 'auto',
				'urls'          => 'relative',
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( [ 11 ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Functions\when( 'get_post_field' )->justReturn( '<p>Intro</p><h3>Section</h3><h4>Detail</h4>' );
		Functions\when( 'wp_make_link_relative' )->alias( fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', '', $url ) );

		$this->assertSame( '<section class="ntrnllnk"><h3>Further reading</h3><ul><li><a href="/11/"></a></li></ul></section>', Plugin::html( 7 ) );
	}

	public function test_html_applies_html_filter(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 11 ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Filters\expectApplied( 'ntrnllnk_html' )->once()->with( Mockery::type( 'string' ), Mockery::type( 'array' ), 7 )->andReturn( '<p>Custom</p>' );

		$this->assertSame( '<p>Custom</p>', Plugin::html( 7 ) );
	}

	public function test_render_appends_list_to_single_posts(): void {
		$this->view( 21 );
		Functions\when( 'get_post_meta' )->justReturn( [ 11 ] );
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
		Functions\when( 'get_post_meta' )->justReturn( [ 11 ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );
		Plugin::shortcode();

		$this->assertSame( '<p>Text</p>', Plugin::render( '<p>Text</p>' ) );
	}

	public function test_html_leaves_links_without_class(): void {
		$this->settings( [ 'links_class' => true ] );
		Functions\when( 'get_post_meta' )->justReturn( [ 11 ] );
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
		Functions\when( 'get_option' )->justReturn( [ 'Agatha Christie' => 11 ] );
		Functions\when( 'get_posts' )->justReturn( [ $this->post( [ 'ID' => 11 ] ) ] );

		$this->assertSame(
			'<p><a href="https://example.com/11/" class="ntrnllnk-inline">Agatha Christie</a></p>',
			Plugin::link_content( '<p>Agatha Christie</p>' )
		);
	}

	public function test_link_content_links_published_targets_only_once(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		Functions\when( 'get_option' )->justReturn(
			[
				'Agatha Christie' => 11,
				'Harry Potter'    => 12,
				'Stephen King'    => 7,
			]
		);
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
		Functions\when( 'get_option' )->justReturn(
			[
				'Agatha Christie' => 11,
				'Harry Potter'    => 12,
			]
		);
		Functions\expect( 'get_posts' )->once()->with( Mockery::subset( [ 'post__in' => [ 12 ] ] ) )->andReturn( [ $this->post( [ 'ID' => 12 ] ) ] );

		$this->assertSame(
			'<p>Agatha Christie</p><p><a href="https://example.com/12/">Harry Potter</a></p>',
			Plugin::link_content( '<p>Agatha Christie</p><p>Harry Potter</p>' )
		);
	}

	public function test_link_content_skips_lookups_without_mentioned_phrases(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		Functions\when( 'get_option' )->justReturn( [ 'Agatha Christie' => 11 ] );
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
		Functions\expect( 'get_option' )->never();

		$this->assertSame( '<p>Agatha Christie</p>', Plugin::link_content( '<p>Agatha Christie</p>' ) );
	}

	public function test_link_content_applies_phrases_filter(): void {
		$this->settings( [ 'links_inline' => true ] );
		$this->view( 7 );
		Functions\when( 'get_option' )->justReturn( [ 'Agatha Christie' => 11 ] );
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
		Functions\when( 'get_option' )->justReturn( [ 'Agatha Christie' => 11 ] );
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
		Functions\expect( 'update_post_meta' )->once()->with( 1, Plugin::META_KEY, [ 2 ] );
		Functions\expect( 'update_post_meta' )->once()->with( 2, Plugin::META_KEY, [ 1 ] );
		Functions\expect( 'delete_post_meta' )->once()->with( 3, Plugin::META_KEY );
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_PHRASES, [ 'Agatha Christie' => 1 ], true );
		Functions\expect( 'wp_set_option_autoload' )->once()->with( Plugin::OPTION_PHRASES, true );

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
		Functions\expect( 'update_option' )->once()->with( Plugin::OPTION_PHRASES, [ 'Agatha Christie' => 1 ], true );
		Functions\when( 'wp_set_option_autoload' )->justReturn( true );

		Plugin::rebuild();
	}
}