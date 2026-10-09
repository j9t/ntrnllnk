<?php
/**
 * Tests for Report, with WordPress functions simulated by Brain Monkey
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Ntrnllnk\Report;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Row from Report
 * @phpstan-import-type Related from Report
 * @phpstan-import-type Links from Report
 */
final class ReportTest extends TestCase {

	// Counts Mockery expectations (of WordPress calls) as assertions
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://example.com/wp-admin/' . $path );
		// Unlike Brain Monkey’s stub, WordPress keeps relative URLs relative
		Functions\when( 'esc_url' )->alias( fn( string $url ): string => htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'number_format_i18n' )->alias( fn( float $number ): string => number_format( $number ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Returns a row’s data
	 *
	 * @param Related|null $related Related posts.
	 * @param Links|null   $links   In-content links.
	 * @param int          $listed  Number of lists with the post.
	 * @return Row
	 */
	private function row( ?array $related = [], ?array $links = [], int $listed = 0 ): array {
		return [
			'id'      => 7,
			'title'   => 'Lee Child & Co.',
			'url'     => 'https://example.com/7/',
			'related' => $related,
			'links'   => $links,
			'listed'  => $listed,
		];
	}

	public function test_pagination_html_follows_wordpress_tables(): void {
		Functions\when( 'add_query_arg' )->alias( fn( string $key, int $value, string $url ): string => $url . '&' . $key . '=' . $value );
		$html = Report::pagination_html( 2, 3, 45, 'https://example.com/r?page=x' );

		$this->assertStringStartsWith( '<div class="tablenav-pages"><span class="displaying-num">45 posts</span><span class="pagination-links">', $html );
		$this->assertStringContainsString( '<a class="first-page button" href="https://example.com/r?page=x&amp;paged=1"><span class="screen-reader-text">First page</span><span aria-hidden="true">«</span></a>', $html );
		$this->assertStringContainsString( '<a class="prev-page button" href="https://example.com/r?page=x&amp;paged=1">', $html );
		$this->assertStringContainsString( '<span class="paging-input">2 of <span class="total-pages">3</span></span>', $html );
		$this->assertStringContainsString( '<a class="next-page button" href="https://example.com/r?page=x&amp;paged=3">', $html );
		$this->assertStringContainsString( '<a class="last-page button" href="https://example.com/r?page=x&amp;paged=3">', $html );
	}

	public function test_pagination_html_disables_links_to_current_page(): void {
		Functions\when( 'add_query_arg' )->alias( fn( string $key, int $value, string $url ): string => $url . '&' . $key . '=' . $value );
		$html = Report::pagination_html( 1, 3, 45, 'https://example.com/r?page=x' );

		$this->assertSame( 2, substr_count( $html, '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' ) );
		$this->assertStringNotContainsString( 'first-page', $html );
	}

	public function test_pagination_html_shows_only_count_for_one_page(): void {
		$this->assertSame( '<div class="tablenav-pages one-page"><span class="displaying-num">1 post</span></div>', Report::pagination_html( 1, 1, 1, 'https://example.com/r' ) );
	}

	public function test_add_page_adds_report_to_tools_for_administrators(): void {
		Functions\expect( 'add_management_page' )->once()->with( 'ntrnllnk', 'ntrnllnk', 'manage_options', Report::SLUG, [ Report::class, 'render_page' ] )->andReturn( 'tools_page_ntrnllnk-report' );
		Report::add_page();

		$this->assertNotFalse( has_action( 'load-tools_page_ntrnllnk-report', [ Report::class, 'load' ] ) );
	}

	public function test_load_adds_styles_for_report_only(): void {
		Report::load();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ Report::class, 'add_styles' ] ) );
	}

	public function test_add_styles_aligns_lists_with_post_titles(): void {
		Functions\expect( 'wp_add_inline_style' )->once()->with( 'common', Mockery::pattern( '/\.ntrnllnk-report td > ul \{ ?margin-top: ?0;? ?\}/' ) );
		Report::add_styles();
	}

	public function test_header_html_puts_settings_link_next_to_title(): void {
		$this->assertSame(
			'<h1 class="wp-heading-inline">ntrnllnk Report</h1> <a href="https://example.com/wp-admin/options-general.php?page=ntrnllnk" class="page-title-action">Settings</a><hr class="wp-header-end">',
			Report::header_html()
		);
	}

	public function test_index_counts_lists_with_each_post_and_finds_posts_with_list(): void {
		$index = Report::index(
			[
				1 => [
					2 => [ 'words' => 0.05 ],
					3 => [ 'words' => 0.02 ],
				],
				2 => [ 1 => [ 'links' => 0.06 ] ],
				3 => [ 1 => [ 'words' => 0.03 ] ],
				4 => 'invalid',
			],
			0.04
		);

		$this->assertSame(
			[
				2 => 1,
				1 => 1,
			],
			$index['listed']
		);
		$this->assertSame( [ 1, 2 ], array_keys( $index['with_list'] ) );
	}

	public function test_row_html_shows_post_with_edit_link_and_placeholders(): void {
		$html = Report::row_html( $this->row() );

		$this->assertStringContainsString( '<a href="https://example.com/7/">Lee Child &amp; Co.</a>', $html );
		$this->assertStringContainsString( 'https://example.com/wp-admin/post.php?post=7&amp;action=edit', $html );
		$this->assertSame( 2, substr_count( $html, '<td>—</td>' ) );
		$this->assertSame( 4, substr_count( $html, '<td' ) );
		$this->assertStringContainsString( '<td>0</td>', $html );
	}

	public function test_row_html_lists_related_posts_and_links(): void {
		$related = [
			[
				'title'   => 'A',
				'url'     => '/a/',
				'score'   => 0.052,
				'visible' => true,
			],
			[
				'title'   => 'B',
				'url'     => '/b/',
				'score'   => 0.03,
				'visible' => false,
			],
		];
		$links   = [
			[
				'phrase' => 'Agatha Christie',
				'title'  => 'Agatha Christie in Order',
				'url'    => '/c/',
			],
		];
		$html    = Report::row_html( $this->row( $related, $links, 4 ) );

		$this->assertStringContainsString( '<td><ul><li><a href="/a/">A</a> (0.052)</li></ul><details><summary>Below the minimum score (1)</summary><ul><li><a href="/b/">B</a> (0.03)</li></ul></details></td>', $html );
		$this->assertStringContainsString( '<li>Agatha Christie → <a href="/c/">Agatha Christie in Order</a></li>', $html );
		$this->assertStringContainsString( '<td>4</td>', $html );
	}

	public function test_row_html_shows_near_misses_only_in_details_without_visible_posts(): void {
		$related = [
			[
				'title'   => 'B',
				'url'     => '/b/',
				'score'   => 0.03,
				'visible' => false,
			],
		];

		$this->assertStringContainsString( '<td>—<details><summary>', Report::row_html( $this->row( $related ) ) );
	}

	public function test_row_html_marks_turned_off_features(): void {
		$this->assertSame( 2, substr_count( Report::row_html( $this->row( related: null, links: null ) ), '<td>Off</td>' ) );
	}

	public function test_views_html_links_filters_with_counts_and_marks_current(): void {
		$html = Report::views_html(
			[
				''             => 150,
				'no-list'      => 11,
				'never-listed' => 7,
			],
			'no-list'
		);

		$this->assertStringContainsString( '<a href="https://example.com/wp-admin/tools.php?page=ntrnllnk-report">All <span class="count">(150)</span></a>', $html );
		$this->assertStringContainsString( '<a href="https://example.com/wp-admin/tools.php?page=ntrnllnk-report&amp;filter=no-list" class="current" aria-current="page">No list <span class="count">(11)</span></a>', $html );
		$this->assertStringContainsString( 'Never listed <span class="count">(7)</span>', $html );
	}
}