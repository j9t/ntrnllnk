<?php
/**
 * Tests for Ranker
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Document;
use Ntrnllnk\Ranker;
use PHPUnit\Framework\TestCase;

final class RankerTest extends TestCase {

	/**
	 * Builds a document from a word list (one occurrence each)
	 *
	 * @param int      $id    Post ID.
	 * @param string[] $words Words.
	 * @param string[] $links Normalized link targets.
	 * @param int[]    $terms Term IDs.
	 * @param int      $time  Publication time.
	 */
	private function document( int $id, array $words, array $links = [], array $terms = [], int $time = 0 ): Document {
		return new Document( $id, array_fill_keys( $words, 1 ), $links, $terms, $time );
	}

	public function test_related_ranks_more_similar_documents_first(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon', 'magic', 'elves', 'crime' ] ),
				$this->document( 2, [ 'dragon', 'magic', 'elves' ] ),
				$this->document( 3, [ 'dragon', 'crime', 'murder' ] ),
				$this->document( 4, [ 'cooking', 'baking' ] ),
			],
			5
		);

		$this->assertSame( [ 2, 3 ], array_keys( $related[1] ) );
	}

	public function test_related_excludes_self_and_unrelated(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon', 'magic' ] ),
				$this->document( 2, [ 'dragon', 'magic' ] ),
				$this->document( 3, [ 'cooking', 'baking' ] ),
			],
			5
		);

		$this->assertSame( [ 2 ], array_keys( $related[1] ) );
		$this->assertSame( [], $related[3] );
	}

	public function test_related_ignores_features_shared_by_all_documents(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'book', 'dragon' ], [], [ 9 ] ),
				$this->document( 2, [ 'book', 'cooking' ], [], [ 9 ] ),
				$this->document( 3, [ 'book', 'garden' ], [], [ 9 ] ),
			],
			5
		);

		$this->assertSame( [ [], [], [] ], array_values( $related ) );
	}

	public function test_related_uses_shared_links(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon' ], [ 'example.com/books/1' ] ),
				$this->document( 2, [ 'cooking' ], [ 'example.com/books/1' ] ),
				$this->document( 3, [ 'garden' ], [ 'example.com/books/2' ] ),
			],
			5
		);

		$this->assertSame( [ 2 ], array_keys( $related[1] ) );
	}

	public function test_related_uses_shared_terms(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon' ], [], [ 4 ] ),
				$this->document( 2, [ 'cooking' ], [], [ 4 ] ),
				$this->document( 3, [ 'garden' ], [], [ 5 ] ),
			],
			5
		);

		$this->assertSame( [ 2 ], array_keys( $related[1] ) );
	}

	public function test_related_breaks_ties_by_recency(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon' ] ),
				$this->document( 2, [ 'dragon' ], [], [], 100 ),
				$this->document( 3, [ 'dragon' ], [], [], 200 ),
				$this->document( 4, [ 'cooking' ] ),
			],
			5
		);

		$this->assertSame( [ 3, 2 ], array_keys( $related[1] ) );
	}

	public function test_related_limits_count(): void {
		$ranker    = new Ranker();
		$documents = [];
		for ( $id = 1; $id <= 6; $id++ ) {
			$documents[] = $this->document( $id, [ 'dragon' ] );
		}
		$documents[] = $this->document( 7, [ 'cooking' ] );

		$this->assertCount( 3, $ranker->related( $documents, 3 )[1] );
	}

	public function test_related_applies_minimum_score(): void {
		$documents = [
			$this->document( 1, [ 'dragon', 'magic' ] ),
			$this->document( 2, [ 'dragon', 'elves' ] ),
			$this->document( 3, [ 'cooking' ] ),
		];

		$this->assertSame( [ 2 ], array_keys( ( new Ranker() )->related( $documents, 5 )[1] ) );
		$this->assertSame( [], ( new Ranker( score_min: 0.5 ) )->related( $documents, 5 )[1] );
	}

	public function test_related_returns_scores_between_zero_and_one(): void {
		$ranker  = new Ranker();
		$related = $ranker->related(
			[
				$this->document( 1, [ 'dragon', 'magic' ], [ 'x.org' ], [ 4 ] ),
				$this->document( 2, [ 'dragon', 'magic' ], [ 'x.org' ], [ 4 ] ),
				$this->document( 3, [ 'cooking' ], [ 'y.org' ], [ 5 ] ),
			],
			5
		);

		$this->assertEqualsWithDelta( 1.0, $related[1][2], 0.0001 );
	}

	public function test_related_handles_empty_input(): void {
		$this->assertSame( [], ( new Ranker() )->related( [], 5 ) );
	}
}