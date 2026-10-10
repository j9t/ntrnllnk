<?php
/**
 * Tests for Admin, with WordPress functions simulated by Brain Monkey
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Ntrnllnk\Admin;
use Ntrnllnk\Plugin;
use Ntrnllnk\Settings;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase {

	// Counts Mockery expectations (of WordPress calls) as assertions
	use MockeryPHPUnitIntegration;

	/**
	 * Options by name, as `get_option()` returns them
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
		Functions\when( 'number_format_i18n' )->alias( fn( float $number, int $decimals = 0 ): string => number_format( $number, $decimals ) );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'get_post_types' )->justReturn(
			[
				'post'       => (object) [ 'labels' => (object) [ 'name' => 'Posts' ] ],
				'page'       => (object) [ 'labels' => (object) [ 'name' => 'Pages' ] ],
				'attachment' => (object) [ 'labels' => (object) [ 'name' => 'Media' ] ],
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Returns what a callback outputs
	 *
	 * @param callable $callback Callback.
	 */
	private function captured( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	public function test_register_adds_page_settings_rebuild_and_plugin_link(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'ntrnllnk/ntrnllnk.php' );
		Admin::register( '/plugins/ntrnllnk/ntrnllnk.php' );

		$this->assertNotFalse( has_action( 'admin_menu', [ Admin::class, 'add_page' ] ) );
		$this->assertNotFalse( has_action( 'admin_init', [ Admin::class, 'register_settings' ] ) );
		$this->assertNotFalse( has_action( 'admin_post_ntrnllnk_rebuild_now', [ Admin::class, 'handle_rebuild' ] ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_ntrnllnk/ntrnllnk.php', [ Admin::class, 'add_action_links' ] ) );
	}

	public function test_register_settings_registers_option_with_sanitizing_and_all_fields(): void {
		Functions\expect( 'register_setting' )->once()->with( Admin::SLUG, Plugin::OPTION_SETTINGS, Mockery::subset( [ 'sanitize_callback' => [ Admin::class, 'sanitize' ] ] ) );
		$fields = [];
		Functions\when( 'add_settings_field' )->alias(
			function ( string $id ) use ( &$fields ): void {
				$fields[] = $id;
			}
		);
		Admin::register_settings();

		$this->assertEqualsCanonicalizing( array_keys( Settings::DEFAULTS ), $fields );
	}

	public function test_register_settings_groups_shared_settings_and_each_feature_s_advanced_ones(): void {
		Functions\when( 'register_setting' )->justReturn( true );
		$sections = [];
		Functions\when( 'add_settings_field' )->alias(
			function ( string $id, string $title, callable $callback, string $page, string $section ) use ( &$sections ): void {
				$sections[ $section ][] = $id;
			}
		);
		Admin::register_settings();

		$this->assertSame( [ 'post_types', 'urls' ], $sections['ntrnllnk_general'] );
		$this->assertSame( [ 'count', 'heading', 'heading_level', 'score_min' ], $sections['ntrnllnk_list'] );
		$this->assertSame( [ 'placement', 'priority', 'language', 'weights', 'debug' ], $sections['ntrnllnk_list_advanced'] );
		$this->assertSame( [ 'links_inline', 'links_inline_max', 'links_inline_single_words', 'links_inline_exclude_phrases', 'links_inline_exclude_posts' ], $sections['ntrnllnk_inline'] );
		$this->assertSame( [ 'links_class' ], $sections['ntrnllnk_inline_advanced'] );
	}

	public function test_sanitize_offers_public_post_types_except_attachments(): void {
		$this->assertSame( [ 'page' ], Admin::sanitize( [ 'post_types' => [ 'page', 'attachment' ] ] )['post_types'] );
		$this->assertSame( Settings::DEFAULTS['count'], Admin::sanitize( 'invalid' )['count'] );
	}

	public function test_sanitize_resets_to_defaults_on_request(): void {
		$this->assertSame(
			Settings::DEFAULTS,
			Admin::sanitize(
				[
					'count'      => 10,
					'post_types' => [ 'page' ],
					'reset'      => '1',
				]
			)
		);
	}

	public function test_render_field_outputs_stored_values(): void {
		$this->options[ Plugin::OPTION_SETTINGS ] = [
			'count'                        => 3,
			'debug'                        => true,
			'links_inline_exclude_phrases' => [ 'Happy End', 'Miss "Marple"' ],
		];

		$this->assertMatchesRegularExpression( '/name="ntrnllnk_settings\[count\]"[^>]* value="3"/', $this->captured( fn() => Admin::render_field( [ 'key' => 'count' ] ) ) );
		$this->assertMatchesRegularExpression( '/name="ntrnllnk_settings\[debug\]"[^>]* value="1" checked/', $this->captured( fn() => Admin::render_field( [ 'key' => 'debug' ] ) ) );
		$this->assertStringContainsString( ">Happy End\nMiss &quot;Marple&quot;</textarea>", $this->captured( fn() => Admin::render_field( [ 'key' => 'links_inline_exclude_phrases' ] ) ) );
	}

	public function test_render_field_connects_description(): void {
		$field = $this->captured( fn() => Admin::render_field( [ 'key' => 'count' ] ) );

		$this->assertStringContainsString( 'aria-describedby="ntrnllnk-count-description"', $field );
		$this->assertStringContainsString( '<p class="description" id="ntrnllnk-count-description">', $field );
		$this->assertStringNotContainsString( 'aria-describedby', $this->captured( fn() => Admin::render_field( [ 'key' => 'heading' ] ) ) );
	}

	/**
	 * Returns the settings page, with each section’s fields marked by the section’s ID
	 */
	private function page(): string {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		foreach ( [ 'settings_fields', 'wp_nonce_field' ] as $function ) {
			Functions\when( $function )->justReturn( null );
		}
		Functions\when( 'do_settings_fields' )->alias(
			function ( string $page, string $section ): void {
				echo '<tr><td>' . $section . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Simulated output
			}
		);
		Functions\when( 'submit_button' )->alias(
			function ( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
				$button = sprintf( '<input type="submit" name="%s" value="%s">', $name, '' === $text ? 'Save Changes' : $text );
				echo $wrap ? '<p class="submit">' . $button . '</p>' : $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Simulated output
			}
		);

		return $this->captured( [ Admin::class, 'render_page' ] );
	}

	public function test_render_page_shows_title_settings_report_and_rebuild_in_order(): void {
		$page = $this->page();

		$this->assertStringContainsString( '<h1>ntrnllnk—Automatic Internal Linking</h1>', $page );
		$this->assertStringContainsString( '<p class="submit"><a href="https://example.com/wp-admin/tools.php?page=ntrnllnk-report" class="button">Open Report</a></p>', $page );
		// Wrapped like “Save Changes,” for the same spacing
		$this->assertStringContainsString( '<p class="submit"><input type="submit" name="submit" value="Rebuild Now"></p>', $page );
		$this->assertLessThan( strpos( $page, '<h2>Report</h2>' ), strpos( $page, 'ntrnllnk_inline_advanced' ) );
		$this->assertLessThan( strpos( $page, '<h2>Rebuild</h2>' ), strpos( $page, '<h2>Report</h2>' ) );
	}

	public function test_render_page_shows_sections_with_collapsed_advanced_settings(): void {
		$page = $this->page();

		$this->assertStringContainsString( '<h2>General</h2><table class="form-table" role="presentation"><tr><td>ntrnllnk_general</td></tr></table>', $page );
		$this->assertStringContainsString( '<h2>Related Posts</h2><table class="form-table" role="presentation"><tr><td>ntrnllnk_list</td></tr></table><details class="ntrnllnk-advanced"><summary>Advanced</summary><table class="form-table" role="presentation"><tr><td>ntrnllnk_list_advanced</td></tr></table></details>', $page );
		$this->assertStringContainsString( '<h2>In-Content Links</h2><table class="form-table" role="presentation"><tr><td>ntrnllnk_inline</td></tr></table><details class="ntrnllnk-advanced"><summary>Advanced</summary><table class="form-table" role="presentation"><tr><td>ntrnllnk_inline_advanced</td></tr></table></details>', $page );
		$this->assertLessThan( strpos( $page, '<h2>In-Content Links</h2>' ), strpos( $page, '<h2>Related Posts</h2>' ) );
		$this->assertLessThan( strpos( $page, '<h2>General</h2>' ), strpos( $page, '<h2>In-Content Links</h2>' ) );
	}

	public function test_render_page_offers_reset_next_to_saving(): void {
		$this->assertStringContainsString( '<p class="submit"><input type="submit" name="submit" value="Save Changes"> <input type="submit" name="ntrnllnk_settings[reset]" value="Reset to Defaults"></p>', $this->page() );
	}

	public function test_load_adds_styles_for_settings_page_only(): void {
		Admin::load();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ Admin::class, 'add_styles' ] ) );
	}

	public function test_add_styles_marks_advanced_settings_as_expandable(): void {
		Functions\expect( 'wp_add_inline_style' )->once()->with( 'common', Mockery::pattern( '/\.ntrnllnk-advanced > summary \{[^}]*cursor: pointer; font-size: 14px; font-weight: 600; \} \.ntrnllnk-advanced:not\(\[open\]\) \{ padding-bottom: 20px; \}/' ) );

		Admin::add_styles();
	}

	public function test_attribution_links_author_and_project(): void {
		$attribution = Admin::attribution();

		$this->assertSame( 'A project by <a href="https://meiert.com/" target="_blank">Jens Oliver Meiert</a> (<a href="https://github.com/j9t/ntrnllnk" target="_blank">contribute and support on GitHub</a>).', $attribution );
	}

	public function test_render_field_adds_default_to_description(): void {
		$this->options[ Plugin::OPTION_SETTINGS ] = [ 'count' => 3 ];

		$this->assertStringContainsString( '0 turns the list off. Default: 5.</p>', $this->captured( fn() => Admin::render_field( [ 'key' => 'count' ] ) ) );
	}

	public function test_render_field_shows_defaults_per_type(): void {
		$defaults = [
			'links_inline'  => 'On',
			'debug'         => 'Off',
			'score_min'     => '0.04',
			'weights'       => '0.6',
			'heading_level' => 'h2',
			'urls'          => 'Absolute (https://…)',
			'post_types'    => 'Posts',
		];
		foreach ( $defaults as $key => $default ) {
			$this->assertStringContainsString( 'Default: ' . $default . '.</p>', $this->captured( fn() => Admin::render_field( [ 'key' => $key ] ) ), $key );
		}
	}

	public function test_render_field_connects_default_of_post_types_to_its_group(): void {
		$this->assertStringContainsString( '<fieldset aria-describedby="ntrnllnk-post_types-description">', $this->captured( fn() => Admin::render_field( [ 'key' => 'post_types' ] ) ) );
	}

	public function test_render_field_omits_empty_defaults(): void {
		foreach ( [ 'heading', 'links_inline_exclude_phrases', 'links_inline_exclude_posts' ] as $key ) {
			$this->assertStringNotContainsString( 'Default:', $this->captured( fn() => Admin::render_field( [ 'key' => $key ] ) ), $key );
		}
	}

	public function test_render_field_frontloads_debugging_text(): void {
		$this->assertStringContainsString( '> Show scores of related posts, and posts below the minimum score, to logged-in users (contributors and above)</label>', $this->captured( fn() => Admin::render_field( [ 'key' => 'debug' ] ) ) );
	}

	public function test_render_field_shows_default_heading_as_placeholder(): void {
		$this->assertStringContainsString( 'placeholder="Further reading" value=""', $this->captured( fn() => Admin::render_field( [ 'key' => 'heading' ] ) ) );
	}

	public function test_render_field_lists_post_types_and_words_weight(): void {
		$post_types = $this->captured( fn() => Admin::render_field( [ 'key' => 'post_types' ] ) );

		$this->assertStringContainsString( 'name="ntrnllnk_settings[post_types][]" value="post" checked', $post_types );
		$this->assertStringContainsString( 'value="page"', $post_types );
		$this->assertStringNotContainsString( 'attachment', $post_types );
		$this->assertMatchesRegularExpression( '/name="ntrnllnk_settings\[weights\]\[words\]"[^>]* value="0.6"/', $this->captured( fn() => Admin::render_field( [ 'key' => 'weights' ] ) ) );
	}

	public function test_render_field_selects_stored_choice(): void {
		$this->options[ Plugin::OPTION_SETTINGS ] = [ 'heading_level' => 'auto' ];

		$this->assertStringContainsString( '<option value="auto" selected>', $this->captured( fn() => Admin::render_field( [ 'key' => 'heading_level' ] ) ) );
	}

	public function test_status_reports_running_rebuild(): void {
		Functions\when( 'get_transient' )->justReturn( true );

		$this->assertStringStartsWith( 'A rebuild is running.', Admin::status() );
	}

	public function test_status_reports_pending_rebuild(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() );

		$this->assertStringStartsWith( 'A rebuild is about to start.', Admin::status() );
	}

	public function test_status_reports_last_rebuild(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_date' )->justReturn( 'October 9, 2026 14:32' );
		$this->options[ Plugin::OPTION_REBUILD ] = [
			'time'     => 1,
			'duration' => 2.5,
			'posts'    => 150,
		];

		$this->assertSame( 'Last rebuild: October 9, 2026 14:32, for 150 posts, in 2.5 seconds.', Admin::status() );
	}

	public function test_status_reports_missing_rebuild(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$this->assertSame( 'No rebuild yet.', Admin::status() );
	}

	public function test_request_rebuild_requires_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException() );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->expectException( \RuntimeException::class );

		Admin::request_rebuild();
	}

	public function test_request_rebuild_checks_nonce_and_rebuilds_soon(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( Admin::ACTION_REBUILD );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Plugin::HOOK_REBUILD );
		Functions\expect( 'wp_schedule_single_event' )->once();

		Admin::request_rebuild();
	}

	public function test_add_action_links_puts_settings_first(): void {
		$this->assertSame(
			[ '<a href="https://example.com/wp-admin/options-general.php?page=ntrnllnk">Settings</a>', '<a href="#">Deactivate</a>' ],
			Admin::add_action_links( [ '<a href="#">Deactivate</a>' ] )
		);
	}
}