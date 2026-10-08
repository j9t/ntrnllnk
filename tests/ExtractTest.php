<?php
/**
 * Tests for Extract
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Extract;
use PHPUnit\Framework\TestCase;

final class ExtractTest extends TestCase {

	public function test_text_drops_tags_and_block_comments(): void {
		$html = "<!-- wp:paragraph -->\n<p>A <strong>good</strong> book.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( 'A good book.', Extract::text( $html ) );
	}

	public function test_text_drops_scripts_and_styles(): void {
		$html = '<p>Before</p><script>var a = 1;</script><style>p { color: red; }</style><p>After</p>';
		$this->assertSame( 'Before After', Extract::text( $html ) );
	}

	public function test_text_decodes_entities(): void {
		$this->assertSame( 'Tom & Jerry—“classic”', Extract::text( '<p>Tom &amp; Jerry&mdash;&ldquo;classic&rdquo;</p>' ) );
	}

	public function test_text_separates_adjacent_blocks(): void {
		$this->assertSame( 'One Two', Extract::text( '<h2>One</h2><p>Two</p>' ) );
	}

	public function test_headings_returns_heading_texts(): void {
		$html = '<h2 class="wp-block-heading">The <em>Classic</em></h2><p>Text</p><h3>In a Hurry</h3>';
		$this->assertSame( [ 'The Classic', 'In a Hurry' ], Extract::headings( $html ) );
	}

	public function test_heading_level_top_returns_highest_level(): void {
		$this->assertSame( 2, Extract::heading_level_top( '<h3>Book</h3><h2>Section</h2><h4>Detail</h4>' ) );
	}

	public function test_heading_level_top_returns_level_of_content_starting_lower(): void {
		$this->assertSame( 3, Extract::heading_level_top( '<p>Intro</p><h3 class="wp-block-heading">Book</h3><h5>Detail</h5>' ) );
	}

	public function test_heading_level_top_returns_null_without_headings(): void {
		$this->assertNull( Extract::heading_level_top( '<p>Text with <header>no headings</header></p>' ) );
	}

	public function test_hrefs_returns_unique_absolute_targets_with_queries(): void {
		$html = '<a href="/?p=3851">A</a><a href="https://www.example.com/books/?p=1&amp;b=2#c">B</a><a href="/?p=3851">A</a><a href="#top">Top</a>';

		$this->assertSame(
			[ 'https://www.example.com/?p=3851', 'https://www.example.com/books/?p=1&b=2#c' ],
			Extract::hrefs( $html, 'https://www.example.com' )
		);
	}

	public function test_links_returns_unique_normalized_targets(): void {
		$html = '<a href="https://www.example.com/books/3499012464/?ref=x"><img src="cover.jpg"></a>'
			. '<a href="https://www.example.com/books/3499012464/">Title</a>'
			. '<a href=\'https://en.wikipedia.org/wiki/Brain#Structure\'>Brain</a>';
		$this->assertSame( [ 'example.com/books/3499012464', 'en.wikipedia.org/wiki/Brain' ], Extract::links( $html ) );
	}

	public function test_links_reads_unquoted_targets(): void {
		$this->assertSame( [ 'example.org/a' ], Extract::links( '<a href=https://example.org/a/>A</a>' ) );
	}

	public function test_links_skips_anchors_and_other_schemes(): void {
		$html = '<a href="#top">Top</a><a href="mailto:a@example.com">Mail</a><a href="tel:123">Tel</a><a href="javascript:void(0)">JS</a>';
		$this->assertSame( [], Extract::links( $html ) );
	}

	public function test_links_resolves_relative_targets_against_base(): void {
		$html = '<a href="/series/agatha-christie/">Christie</a><a href="//example.org/a">Protocol-relative</a>';
		$this->assertSame(
			[ 'example.com/series/agatha-christie', 'example.org/a' ],
			Extract::links( $html, 'https://www.example.com' )
		);
	}

	public function test_links_skips_relative_targets_without_base(): void {
		$this->assertSame( [], Extract::links( '<a href="/series/">Series</a>' ) );
	}

	public function test_normalize_url_keeps_path_case(): void {
		$this->assertSame( 'example.com/Path/X', Extract::normalize_url( 'HTTP://Example.COM/Path/X/' ) );
	}

	public function test_normalize_url_returns_host_for_root(): void {
		$this->assertSame( 'example.com', Extract::normalize_url( 'https://www.example.com/?a=b' ) );
	}

	public function test_normalize_url_rejects_invalid_urls(): void {
		$this->assertNull( Extract::normalize_url( 'https://' ) );
	}
}