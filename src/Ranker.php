<?php
/**
 * Ranks documents by similarity
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks documents by similarity
 *
 * Each signal (words, links) is a TF-IDF vector compared by cosine similarity; the score is the weighted sum, between 0 and 1. Features that all documents share carry no weight, so site-wide boilerplate, ubiquitous links, and catch-all categories are ignored.
 *
 * Terms count as words rather than as a signal of their own: a post with a single category would otherwise match every post in it fully.
 */
final class Ranker {

	/**
	 * Default weights per signal, adding up to 1
	 */
	public const WEIGHTS = [
		'words' => 0.6,
		'links' => 0.4,
	];

	/**
	 * Weight of a term, as a count of words (like a title word)
	 */
	private const WEIGHT_TERM = 3;

	/**
	 * Weights per signal
	 *
	 * @var array<string, float>
	 */
	private array $weights;

	/**
	 * Creates a ranker
	 *
	 * @param array<string, float> $weights   Weights per signal (`words`, `links`); missing ones use the defaults.
	 * @param float                $score_min Minimum score for a document to count as related.
	 */
	public function __construct( array $weights = [], private float $score_min = 0.02 ) {
		$this->weights = array_merge( self::WEIGHTS, array_intersect_key( $weights, self::WEIGHTS ) );
	}

	/**
	 * Returns the most related documents for each document
	 *
	 * @param Document[] $documents Documents.
	 * @param int        $count     Maximum number of related documents per document.
	 * @return array<int, array<int, float>> Document ID => related document IDs and scores, best first.
	 */
	public function related( array $documents, int $count ): array {
		$scores = [];
		$times  = [];
		foreach ( $documents as $document ) {
			$scores[ $document->id ] = [];
			$times[ $document->id ]  = $document->time;
		}

		$bags_words = [];
		$bags_links = [];
		foreach ( $documents as $document ) {
			// Words never contain a colon, so prefixed terms cannot collide with them
			$bags_words[ $document->id ] = $document->words + array_fill_keys( array_map( fn( int $term ): string => 'term:' . $term, $document->terms ), self::WEIGHT_TERM );
			$bags_links[ $document->id ] = array_fill_keys( $document->links, 1 );
		}
		$this->add_similarities( $scores, self::vectors( $bags_words ), $this->weights['words'] );
		$this->add_similarities( $scores, self::vectors( $bags_links ), $this->weights['links'] );

		$related = [];
		foreach ( $scores as $id => $candidates ) {
			$candidates = array_filter( $candidates, fn( float $score ): bool => $score >= $this->score_min );
			uksort(
				$candidates,
				// Rounding keeps float noise from overriding the tie-breakers (newer first, then lower ID)
				fn( int $a, int $b ): int => [ round( $candidates[ $b ], 6 ), $times[ $b ], $a ] <=> [ round( $candidates[ $a ], 6 ), $times[ $a ], $b ]
			);
			$related[ $id ] = array_slice( $candidates, 0, $count, true );
		}

		return $related;
	}

	/**
	 * Adds the weighted cosine similarities of all vector pairs to the scores
	 *
	 * @param array<int, array<int, float>>        $scores  Scores per document pair.
	 * @param array<int, array<int|string, float>> $vectors Unit vectors per document.
	 * @param float                                $weight  Weight of the signal.
	 */
	private function add_similarities( array &$scores, array $vectors, float $weight ): void {
		$postings = [];
		foreach ( $vectors as $id => $vector ) {
			foreach ( $vector as $feature => $value ) {
				$postings[ $feature ][ $id ] = $value;
			}
		}

		foreach ( $vectors as $id => $vector ) {
			foreach ( $vector as $feature => $value ) {
				foreach ( $postings[ $feature ] as $id_other => $value_other ) {
					if ( $id_other !== $id ) {
						$scores[ $id ][ $id_other ] = ( $scores[ $id ][ $id_other ] ?? 0.0 ) + $weight * $value * $value_other;
					}
				}
			}
		}
	}

	/**
	 * Returns TF-IDF unit vectors, keeping only features that occur in more than one document
	 *
	 * @param array<int, array<int|string, int>> $bags Feature counts per document.
	 * @return array<int, array<int|string, float>>
	 */
	private static function vectors( array $bags ): array {
		$count_documents = count( $bags );
		$frequencies     = [];
		foreach ( $bags as $bag ) {
			foreach ( $bag as $feature => $_ ) {
				$frequencies[ $feature ] = ( $frequencies[ $feature ] ?? 0 ) + 1;
			}
		}

		$vectors = [];
		foreach ( $bags as $id => $bag ) {
			$vector = [];
			foreach ( $bag as $feature => $count ) {
				$value = ( 1 + log( $count ) ) * log( $count_documents / $frequencies[ $feature ] );
				if ( $value > 0 ) {
					$vector[ $feature ] = $value;
				}
			}
			$norm = sqrt( array_sum( array_map( fn( float $value ): float => $value * $value, $vector ) ) );

			$vectors[ $id ] = [];
			foreach ( $vector as $feature => $value ) {
				if ( $frequencies[ $feature ] > 1 ) {
					$vectors[ $id ][ $feature ] = $value / $norm;
				}
			}
		}

		return $vectors;
	}
}