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
 *
 * Every pair of documents that share a feature gets compared, so features in many documents cost the most while saying the least. Features in more than a fixed number of documents are skipped, which keeps ranking time from growing quadratically on large sites.
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
	 * Maximum number of documents a feature may be in
	 */
	public const FREQUENCY_MAX = 500;

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
	 * @param array<string, float> $weights       Weights per signal (`words`, `links`); missing ones use the defaults.
	 * @param float                $score_min     Minimum score for a document to count as related.
	 * @param int                  $frequency_max Maximum number of documents a feature may be in; features in more are skipped.
	 */
	public function __construct( array $weights = [], private float $score_min = 0.04, private int $frequency_max = self::FREQUENCY_MAX ) {
		$this->weights = array_merge( self::WEIGHTS, array_intersect_key( $weights, self::WEIGHTS ) );
	}

	/**
	 * Returns the most related documents for each document
	 *
	 * @param Document[] $documents Documents.
	 * @param int        $count     Maximum number of related documents per document.
	 * @return array<int, array<int, array<string, float>>> Document ID => related document IDs, best first => weighted similarity per contributing signal, adding up to the score.
	 */
	public function related( array $documents, int $count ): array {
		$times   = [];
		$signals = [];
		foreach ( $documents as $document ) {
			$times[ $document->id ] = $document->time;
		}
		foreach ( $this->weights as $signal => $weight ) {
			$signals[ $signal ] = [ ...$this->index( $documents, $signal ), $weight ];
		}

		// Scored one document at a time, so that memory grows with the number of documents, not of pairs
		$related = [];
		foreach ( $documents as $document ) {
			$scores = [];
			foreach ( $signals as [ $features, $postings, $weight ] ) {
				foreach ( $features[ $document->id ] as $feature ) {
					$value = $weight * $postings[ $feature ][ $document->id ];
					foreach ( $postings[ $feature ] as $id_other => $value_other ) {
						$scores[ $id_other ] = ( $scores[ $id_other ] ?? 0.0 ) + $value * $value_other;
					}
				}
			}
			unset( $scores[ $document->id ] );

			$scores = array_filter( $scores, fn( float $score ): bool => $score >= $this->score_min );
			if ( $count > 0 && count( $scores ) > $count ) {
				// Narrowed with a fast sort to the scores that can make the cut, so that the slow sort below gets few
				arsort( $scores );
				$score_cut = round( (float) array_values( $scores )[ $count - 1 ], 6 );
				$scores    = array_filter( $scores, fn( float $score ): bool => round( $score, 6 ) >= $score_cut );
			}
			uksort(
				$scores,
				// Rounding keeps float noise from overriding the tie-breakers (newer first, then lower ID)
				fn( int $a, int $b ): int => [ round( $scores[ $b ], 6 ), $times[ $b ], $a ] <=> [ round( $scores[ $a ], 6 ), $times[ $a ], $b ]
			);

			// Split by signal only for the few related documents, so that scoring above stays lean
			$related[ $document->id ] = [];
			foreach ( array_keys( array_slice( $scores, 0, $count, true ) ) as $id_other ) {
				$related[ $document->id ][ $id_other ] = self::parts( $signals, $document->id, $id_other );
			}
		}//end foreach

		return $related;
	}

	/**
	 * Returns the weighted similarity of two documents per signal, leaving out signals without any
	 *
	 * @param array<string, array{0: array<int, array<int, int|string>>, 1: array<int|string, array<int, float>>, 2: float}> $signals  Indexes and weights per signal.
	 * @param int                                                                                                            $id       Document ID.
	 * @param int                                                                                                            $id_other Other document’s ID.
	 * @return array<string, float>
	 */
	private static function parts( array $signals, int $id, int $id_other ): array {
		$parts = [];
		foreach ( $signals as $signal => [ $features, $postings, $weight ] ) {
			$similarity = 0.0;
			foreach ( $features[ $id ] as $feature ) {
				$similarity += $postings[ $feature ][ $id ] * ( $postings[ $feature ][ $id_other ] ?? 0.0 );
			}
			if ( $similarity > 0 ) {
				$parts[ $signal ] = $weight * $similarity;
			}
		}

		return $parts;
	}

	/**
	 * Returns a signal’s TF-IDF unit vectors, as the features of each document and the documents of each feature, with the feature’s value in them
	 *
	 * Keeps only features that occur in more than one document and in no more than the maximum; skipped features still count toward the norm, so that scores keep their scale.
	 *
	 * @param Document[] $documents Documents.
	 * @param string     $signal    Signal (`words`, `links`).
	 * @return array{0: array<int, array<int, int|string>>, 1: array<int|string, array<int, float>>}
	 */
	private function index( array $documents, string $signal ): array {
		$frequencies = [];
		foreach ( $documents as $document ) {
			foreach ( self::bag( $document, $signal ) as $feature => $_ ) {
				$frequencies[ $feature ] = ( $frequencies[ $feature ] ?? 0 ) + 1;
			}
		}

		$count_documents = count( $documents );
		$features        = [];
		$postings        = [];
		foreach ( $documents as $document ) {
			$vector = [];
			foreach ( self::bag( $document, $signal ) as $feature => $count ) {
				$value = ( 1 + log( $count ) ) * log( $count_documents / $frequencies[ $feature ] );
				if ( $value > 0 ) {
					$vector[ $feature ] = $value;
				}
			}
			$norm = sqrt( array_sum( array_map( fn( float $value ): float => $value * $value, $vector ) ) );

			$features[ $document->id ] = [];
			foreach ( $vector as $feature => $value ) {
				if ( $frequencies[ $feature ] > 1 && $frequencies[ $feature ] <= $this->frequency_max ) {
					$features[ $document->id ][]           = $feature;
					$postings[ $feature ][ $document->id ] = $value / $norm;
				}
			}
		}

		return [ $features, $postings ];
	}

	/**
	 * Returns a document’s feature counts for a signal
	 *
	 * @param Document $document Document.
	 * @param string   $signal   Signal (`words`, `links`).
	 * @return array<int|string, int>
	 */
	private static function bag( Document $document, string $signal ): array {
		if ( 'links' === $signal ) {
			return array_fill_keys( $document->links, 1 );
		}

		// Words never contain a colon, so prefixed terms cannot collide with them
		return $document->words + array_fill_keys( array_map( fn( int $term ): string => 'term:' . $term, $document->terms ), self::WEIGHT_TERM );
	}
}