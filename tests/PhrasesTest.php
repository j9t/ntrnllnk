<?php
/**
 * Tests for Phrases
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Phrases;
use PHPUnit\Framework\TestCase;

final class PhrasesTest extends TestCase {

	public function test_candidates_returns_name_like_phrases(): void {
		$candidates = Phrases::candidates( 'Agatha Christie in der richtigen Reihenfolge: Alle Bände' );

		$this->assertContains( 'Agatha Christie', $candidates );
		$this->assertNotContains( 'Alle Bände', $candidates );
	}

	public function test_candidates_allows_joiners_inside_but_not_at_edges(): void {
		$candidates = Phrases::candidates( 'Die besten Bücher wie »Der Herr der Ringe«' );

		$this->assertContains( 'Herr der Ringe', $candidates );
		$this->assertNotContains( 'Der Herr der Ringe', $candidates );
		$this->assertNotContains( 'Herr der', $candidates );
	}

	public function test_candidates_breaks_at_punctuation(): void {
		$candidates = Phrases::candidates( 'Rita Falk: Eberhofer, Franz – Krimis' );

		$this->assertContains( 'Rita Falk', $candidates );
		$this->assertNotContains( 'Falk Eberhofer', $candidates );
		$this->assertNotContains( 'Franz Krimis', $candidates );
	}

	public function test_candidates_keeps_initials_inside(): void {
		$candidates = Phrases::candidates( 'Jennifer L. Armentrout in der richtigen Reihenfolge' );

		$this->assertContains( 'Jennifer L. Armentrout', $candidates );
		$this->assertNotContains( 'Jennifer L', $candidates );
		$this->assertNotContains( 'Jennifer L.', $candidates );
	}

	public function test_candidates_returns_sub_phrases_of_title_case_titles(): void {
		$this->assertContains( 'Harry Potter', Phrases::candidates( 'The Best Books Like Harry Potter' ) );
	}

	public function test_candidates_skips_single_words(): void {
		$this->assertSame( [], Phrases::candidates( 'Kluftinger in der richtigen Reihenfolge' ) );
	}

	public function test_mentions_counts_mentions_of_title_candidates(): void {
		$this->assertSame(
			[ 'Agatha Christie' => 2 ],
			Phrases::mentions( 'Agatha Christie in der richtigen Reihenfolge', 'Agatha Christie schrieb viel. Agatha Christies Detektive …' )
		);
	}

	public function test_extract_maps_topical_phrases_to_their_posts(): void {
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'Agatha Christie in der richtigen Reihenfolge', 'Agatha Christie schrieb viel. Agatha Christies Detektive … Agatha Christie starb 1976.' ),
				2 => Phrases::mentions( 'Die besten Krimis', 'Auch Agatha Christie gehört dazu.' ),
			]
		);

		$this->assertSame( [ 'Agatha Christie' => 1 ], $phrases );
	}

	public function test_extract_drops_phrases_mentioned_too_rarely(): void {
		$this->assertSame( [], Phrases::extract( [ 1 => Phrases::mentions( 'Zehn Tipps für Krimis', 'Hier sind zehn Tipps.' ) ] ) );
	}

	public function test_extract_drops_phrases_in_several_titles(): void {
		$text    = 'Harry Potter, Harry Potter, Harry Potter';
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'Bücher wie Harry Potter', $text ),
				2 => Phrases::mentions( 'Harry Potter in der richtigen Reihenfolge', $text ),
			]
		);

		$this->assertSame( [], $phrases );
	}
}