<?php
/**
 * Finds phrases that identify posts
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Finds phrases that identify posts, as link texts for in-content links
 *
 * A phrase is a name-like run of capitalized words from a post’s title (“Agatha Christie,” “Herr der Ringe”), of two words or more, or optionally a single word (“Mistral”) that no other title contains. It identifies its post if the post’s own text mentions it often enough to be about it, and if other titles contain it, too, more often than any of those posts’ texts (as when an author has both an overview and a reading-order post); on a tie, the newer post gets it.
 */
final class Phrases {

	/**
	 * Lowercase words that may connect capitalized words within a phrase
	 */
	private const JOINERS = [ 'and', 'da', 'de', 'den', 'der', 'des', 'di', 'du', 'la', 'le', 'of', 'the', 'und', 'van', 'vom', 'von', 'zu', 'zum', 'zur' ];

	private const COUNT_MENTIONS_MIN = 3;

	private const COUNT_WORDS_MAX = 5;

	/**
	 * Minimum number of letters of single words, which leaves out abbreviations like “US”
	 */
	private const COUNT_LETTERS_MIN = 3;

	/**
	 * Returns the phrases that identify posts, each with its post’s ID
	 *
	 * @param array<int, array<string, int>> $mentions Posts by ID, with their title’s candidates and how often their text mentions them (see `mentions()`).
	 * @param array<int, int>                $times    Publication times by post ID, to break ties (newer first, then higher ID).
	 * @return array<string, int>
	 */
	public static function extract( array $mentions, array $times = [] ): array {
		$owners = [];
		foreach ( $mentions as $id => $counts ) {
			foreach ( $counts as $phrase => $count ) {
				$owners[ $phrase ][ $id ] = $count;
			}
		}

		$phrases = [];
		foreach ( $owners as $phrase => $counts ) {
			// Single words in several titles are the site’s topics rather than a post’s subject (like “AI”)
			if ( count( $counts ) > 1 && ! str_contains( (string) $phrase, ' ' ) ) {
				continue;
			}
			uksort( $counts, fn( int $a, int $b ): int => [ $counts[ $b ], $times[ $b ] ?? 0, $b ] <=> [ $counts[ $a ], $times[ $a ] ?? 0, $a ] );
			$id = (int) array_key_first( $counts );
			if ( $counts[ $id ] >= self::COUNT_MENTIONS_MIN ) {
				$phrases[ (string) $phrase ] = $id;
			}
		}

		return $phrases;
	}

	/**
	 * Returns the candidates of a title and how often a text mentions each
	 *
	 * Lets callers keep counts instead of texts, so that all posts’ texts need not be in memory at once.
	 *
	 * @param string $title        Plain-text title.
	 * @param string $text         Plain text.
	 * @param bool   $words_single Whether single words are candidates, too.
	 * @return array<string, int>
	 */
	public static function mentions( string $title, string $text, bool $words_single = false ): array {
		preg_match_all( '/(?:^|[:?!–—]\s*)[^\p{L}\p{N}]*([\p{L}\p{N}]+)/u', $title, $matches );
		$words_initial = array_flip( $matches[1] );
		$counts        = [];
		foreach ( self::candidates( $title ) as $phrase ) {
			$word = explode( ' ', $phrase )[0];
			// Capitalized only for starting the title (or part of it), as the text uses it in lowercase, too (like “Is,” unlike “Agatha”)
			if ( isset( $words_initial[ $word ] ) && self::count_mentions( mb_strtolower( $word ), $text ) ) {
				continue;
			}
			$counts[ $phrase ] = self::count_mentions( $phrase, $text );
		}
		if ( ! $words_single ) {
			return $counts;
		}

		foreach ( self::words( $title ) as $word ) {
			// Capitalized as a name rather than for its position (like “Right” in “The Right Order”)
			if ( ! isset( $words_initial[ $word ] ) && ! self::count_mentions( mb_strtolower( $word ), $text ) ) {
				$counts[ $word ] = self::count_mentions( $word, $text );
			}
		}

		return $counts;
	}

	/**
	 * Returns the name-like phrases of a title: runs of two to five capitalized words, possibly joined by joiners, not starting or ending with a stopword, and not crossing punctuation
	 *
	 * @param string $title Plain-text title.
	 * @return string[]
	 */
	public static function candidates( string $title ): array {
		$tokens = preg_split( '/\s+/u', trim( $title ), -1, PREG_SPLIT_NO_EMPTY );
		$runs   = [];
		$run    = [];
		foreach ( false === $tokens ? [] : $tokens as $token ) {
			// Initials (“L.”) continue a name rather than ending it
			if ( $run && self::is_initial( $token ) ) {
				$run[] = $token;
				continue;
			}
			preg_match( '/^([^\p{L}\p{N}]*)(.*?)([^\p{L}\p{N}]*)$/u', $token, $match );
			[ , $punctuation_leading, $word, $punctuation_trailing ] = $match + [ '', '', '', '' ];

			if ( '' !== $punctuation_leading || '' === $word ) {
				$runs[] = $run;
				$run    = [];
			}
			if ( preg_match( '/^\p{Lu}/u', $word ) ) {
				$run[] = $word;
			} elseif ( $run && in_array( $word, self::JOINERS, true ) ) {
				$run[] = $word;
			} else {
				$runs[] = $run;
				$run    = [];
			}
			if ( '' !== $punctuation_trailing ) {
				$runs[] = $run;
				$run    = [];
			}
		}//end foreach
		$runs[] = $run;

		$candidates = [];
		foreach ( $runs as $words ) {
			$count = count( $words );
			for ( $start = 0; $start < $count; $start++ ) {
				$end_max = min( $count, $start + self::COUNT_WORDS_MAX );
				for ( $end = $start + 1; $end < $end_max; $end++ ) {
					if ( self::is_edge( $words[ $start ] ) && self::is_edge( $words[ $end ] ) ) {
						$candidates[] = implode( ' ', array_slice( $words, $start, $end - $start + 1 ) );
					}
				}
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Returns the capitalized words of a title that may stand alone: no initials, stopwords, or words shorter than `COUNT_LETTERS_MIN`
	 *
	 * @param string $title Plain-text title.
	 * @return string[]
	 */
	private static function words( string $title ): array {
		$tokens = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$words  = [];
		foreach ( false === $tokens ? [] : $tokens as $token ) {
			$word = (string) preg_replace( [ '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '/(?:’|\')s$/u' ], '', $token );
			if ( mb_strlen( $word ) >= self::COUNT_LETTERS_MIN && self::is_edge( $word ) ) {
				$words[ $word ] = true;
			}
		}

		return array_keys( $words );
	}

	/**
	 * Returns how often a text mentions a phrase, case-sensitively and including the genitive
	 *
	 * @param string $phrase Phrase.
	 * @param string $text   Plain text.
	 */
	public static function count_mentions( string $phrase, string $text ): int {
		return (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?:s|’s|\'s)?(?![\p{L}\p{N}])/u', $text );
	}

	/**
	 * Returns whether a word may start or end a phrase: capitalized, no initial, and no stopword
	 *
	 * @param string $word Word.
	 */
	private static function is_edge( string $word ): bool {
		static $stopwords = null;
		$stopwords      ??= Stopwords::for_language( 'de' ) + Stopwords::for_language( 'en' );

		return (bool) preg_match( '/^\p{Lu}/u', $word ) && ! self::is_initial( $word ) && ! isset( $stopwords[ mb_strtolower( $word ) ] );
	}

	/**
	 * Returns whether a token is an initial, like “L.”
	 *
	 * @param string $token Token.
	 */
	private static function is_initial( string $token ): bool {
		return (bool) preg_match( '/^\p{Lu}\.$/u', $token );
	}
}