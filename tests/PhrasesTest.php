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

	public function test_mentions_skips_phrases_whose_first_word_is_capitalized_only_for_starting_the_title(): void {
		$this->assertSame( [], Phrases::mentions( 'Is Web3 dead?', 'Is Web3 dead? Many say it is. Is Web3 dead? Is Web3 dead?' ) );
	}

	public function test_mentions_skips_phrases_whose_first_word_is_capitalized_only_for_following_a_colon(): void {
		$this->assertSame(
			[ 'AI Agents' => 1 ],
			Phrases::mentions( 'A guide: Building AI Agents', 'Building AI Agents is fun, and we enjoy building them.' )
		);
	}

	public function test_mentions_keeps_names_starting_the_title(): void {
		$this->assertSame( [ 'Stephen King' => 1 ], Phrases::mentions( 'Stephen King: the best books', 'Stephen King is a king of horror.' ) );
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

	public function test_extract_gives_shared_phrases_to_the_post_mentioning_them_most(): void {
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'Stephen King: Die besten Bücher', str_repeat( 'Stephen King schrieb. ', 4 ) ),
				2 => Phrases::mentions( 'Stephen King in der richtigen Reihenfolge', str_repeat( 'Stephen King schrieb. ', 12 ) ),
			]
		);

		$this->assertSame( [ 'Stephen King' => 2 ], $phrases );
	}

	public function test_extract_gives_shared_phrases_to_the_leader_even_if_close(): void {
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'Stephen King: Die besten Bücher', str_repeat( 'Stephen King schrieb. ', 13 ) ),
				2 => Phrases::mentions( 'Stephen King in der richtigen Reihenfolge', str_repeat( 'Stephen King schrieb. ', 10 ) ),
			]
		);

		$this->assertSame( [ 'Stephen King' => 1 ], $phrases );
	}

	public function test_extract_gives_equally_mentioned_phrases_to_the_newer_post(): void {
		$text    = 'Harry Potter, Harry Potter, Harry Potter';
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'Harry Potter in der richtigen Reihenfolge', $text ),
				2 => Phrases::mentions( 'Bücher wie Harry Potter', $text ),
			],
			[
				1 => 200,
				2 => 100,
			]
		);

		$this->assertSame( [ 'Harry Potter' => 1 ], $phrases );
	}

	public function test_extract_gives_equally_mentioned_and_dated_phrases_to_the_higher_id(): void {
		$text    = 'Harry Potter, Harry Potter, Harry Potter';
		$phrases = Phrases::extract(
			[
				2 => Phrases::mentions( 'Harry Potter in der richtigen Reihenfolge', $text ),
				1 => Phrases::mentions( 'Bücher wie Harry Potter', $text ),
			]
		);

		$this->assertSame( [ 'Harry Potter' => 2 ], $phrases );
	}

	public function test_mentions_counts_single_words_on_request(): void {
		$this->assertSame(
			[ 'Mistral' => 3 ],
			Phrases::mentions( 'What makes Mistral so interesting?', 'Mistral is French. Mistral’s models … We like Mistral.', true )
		);
		$this->assertSame( [], Phrases::mentions( 'What makes Mistral so interesting?', 'Mistral, Mistral, Mistral' ) );
	}

	public function test_mentions_skips_single_words_that_start_the_title_or_have_fewer_than_three_letters(): void {
		$this->assertSame( [], Phrases::mentions( 'Claude: Moving beyond US solutions', 'Claude, Claude, Claude. Moving on. US, US, US.', true ) );
	}

	public function test_mentions_skips_single_words_the_text_uses_in_lowercase(): void {
		$this->assertSame( [ 'Right Order' => 0 ], Phrases::mentions( 'The Right Order', 'Right here, right now, in the right order.', true ) );
	}

	public function test_extract_drops_single_words_from_several_titles(): void {
		$text    = 'Web3, Web3, Web3';
		$phrases = Phrases::extract(
			[
				1 => Phrases::mentions( 'The state of Web3', $text, true ),
				2 => Phrases::mentions( 'Our thoughts on Web3', $text, true ),
				3 => Phrases::mentions( 'Why switch to Claude', 'Claude, Claude, Claude', true ),
			]
		);

		$this->assertSame( [ 'Claude' => 3 ], $phrases );
	}
}
