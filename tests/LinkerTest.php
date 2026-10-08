<?php
/**
 * Tests for Linker
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Linker;
use PHPUnit\Framework\TestCase;

final class LinkerTest extends TestCase {

	private const URLS = [
		'Agatha Christie' => '/christie/',
		'Harry Potter'    => '/potter/',
	];

	public function test_link_links_first_mention_only(): void {
		$this->assertSame(
			'<p>Like <a href="/christie/">Agatha Christie</a>, and Agatha Christie again.</p>',
			Linker::link( '<p>Like Agatha Christie, and Agatha Christie again.</p>', self::URLS, 3 )
		);
	}

	public function test_link_skips_headings_links_and_code(): void {
		$html = '<h2>Agatha Christie</h2><p><a href="/x/">Agatha Christie</a> <code>Agatha Christie</code> <pre>Agatha Christie</pre></p><p>Agatha Christie</p>';

		$this->assertSame(
			'<h2>Agatha Christie</h2><p><a href="/x/">Agatha Christie</a> <code>Agatha Christie</code> <pre>Agatha Christie</pre></p><p><a href="/christie/">Agatha Christie</a></p>',
			Linker::link( $html, self::URLS, 3 )
		);
	}

	public function test_link_skips_tables_and_figures(): void {
		$html = '<table><tr><td>Agatha Christie</td></tr></table><figure class="wp-block-image"><img src="a.jpg" alt=""><figcaption>Agatha Christie</figcaption></figure><p>Agatha Christie</p>';

		$this->assertSame(
			'<table><tr><td>Agatha Christie</td></tr></table><figure class="wp-block-image"><img src="a.jpg" alt=""><figcaption>Agatha Christie</figcaption></figure><p><a href="/christie/">Agatha Christie</a></p>',
			Linker::link( $html, self::URLS, 3 )
		);
	}

	public function test_link_skips_comments(): void {
		$html = '<!-- Agatha Christie --><p>Agatha Christie</p>';

		$this->assertSame( '<!-- Agatha Christie --><p><a href="/christie/">Agatha Christie</a></p>', Linker::link( $html, self::URLS, 3 ) );
	}

	public function test_link_links_once_per_paragraph(): void {
		$this->assertSame(
			'<p><a href="/potter/">Harry Potter</a> and <em>Agatha Christie</em></p><ul><li><a href="/christie/">Agatha Christie</a></li></ul>',
			Linker::link( '<p>Harry Potter and <em>Agatha Christie</em></p><ul><li>Agatha Christie</li></ul>', self::URLS, 3 )
		);
	}

	public function test_link_respects_maximum(): void {
		$this->assertSame(
			'<p><a href="/potter/">Harry Potter</a></p><p>Agatha Christie</p>',
			Linker::link( '<p>Harry Potter</p><p>Agatha Christie</p>', self::URLS, 1 )
		);
	}

	public function test_link_links_each_target_once(): void {
		$urls = [
			'Agatha Christie'   => '/christie/',
			'Agatha Mary Clara' => '/christie/',
		];

		$this->assertSame(
			'<p><a href="/christie/">Agatha Christie</a>, born Agatha Mary Clara Miller</p>',
			Linker::link( '<p>Agatha Christie, born Agatha Mary Clara Miller</p>', $urls, 3 )
		);
	}

	public function test_link_prefers_longest_phrase(): void {
		$urls = [
			'Herr der Ringe'          => '/ringe/',
			'Herr der Ringe Trilogie' => '/trilogie/',
		];

		$this->assertSame(
			'<p>Die <a href="/trilogie/">Herr der Ringe Trilogie</a></p>',
			Linker::link( '<p>Die Herr der Ringe Trilogie</p>', $urls, 3 )
		);
	}

	public function test_link_includes_genitive(): void {
		$this->assertSame( '<p><a href="/christie/">Agatha Christies</a> Detektive</p>', Linker::link( '<p>Agatha Christies Detektive</p>', self::URLS, 3 ) );
		$this->assertSame( '<p><a href="/christie/">Agatha Christie&#8217;s</a> detectives</p>', Linker::link( '<p>Agatha Christie&#8217;s detectives</p>', self::URLS, 3 ) );
		$this->assertSame( '<p><a href="/christie/">Agatha Christie’s</a> detectives</p>', Linker::link( '<p>Agatha Christie’s detectives</p>', self::URLS, 3 ) );
	}

	public function test_link_respects_word_boundaries_and_case(): void {
		$html = '<p>Harry Potterfan, harry potter, Agatha Christieland</p>';

		$this->assertSame( $html, Linker::link( $html, self::URLS, 3 ) );
	}

	public function test_link_matches_phrases_with_entities(): void {
		$this->assertSame(
			'<p>See <a href="/tj/">Tom &amp; Jerry</a></p>',
			Linker::link( '<p>See Tom &amp; Jerry</p>', [ 'Tom & Jerry' => '/tj/' ], 3 )
		);
	}

	public function test_link_escapes_urls(): void {
		$this->assertSame(
			'<p><a href="/a?b=1&amp;c=&quot;2&quot;">Harry Potter</a></p>',
			Linker::link( '<p>Harry Potter</p>', [ 'Harry Potter' => '/a?b=1&c="2"' ], 3 )
		);
	}

	public function test_link_returns_html_unchanged_without_phrases(): void {
		$this->assertSame( '<p>Harry Potter</p>', Linker::link( '<p>Harry Potter</p>', [], 3 ) );
	}

	public function test_mentioned_keeps_phrases_the_html_contains(): void {
		$this->assertSame(
			[ 'Tom & Jerry' => 12 ],
			Linker::mentioned(
				'<p>Tom &amp; Jerry, not Agatha</p>',
				[
					'Agatha Christie' => 11,
					'Tom & Jerry'     => 12,
				]
			)
		);
	}
}