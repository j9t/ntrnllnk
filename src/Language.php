<?php
/**
 * Detects the language of a text
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the language of a text by counting stopwords of each supported language
 */
final class Language {

	/**
	 * Supported languages (ISO 639-1)
	 */
	public const SUPPORTED = [ 'de', 'en' ];

	/**
	 * Minimum number of stopwords for a detection, so that a few foreign words (as in titles) do not decide
	 */
	private const COUNT_STOPWORDS_MIN = 3;

	/**
	 * Returns the language of a text, or the fallback if the text gives no clear answer
	 *
	 * @param string $text     Plain text.
	 * @param string $fallback Language to return without a clear answer, e.g., the site language.
	 */
	public static function detect( string $text, string $fallback ): string {
		$words  = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$counts = [];
		foreach ( self::SUPPORTED as $language ) {
			$stopwords           = Stopwords::for_language( $language );
			$counts[ $language ] = count( array_filter( false === $words ? [] : $words, fn( string $word ): bool => isset( $stopwords[ $word ] ) ) );
		}
		arsort( $counts );

		[ $language_first, $language_second ] = array_keys( $counts );
		if ( $counts[ $language_first ] < self::COUNT_STOPWORDS_MIN || $counts[ $language_first ] === $counts[ $language_second ] ) {
			return $fallback;
		}

		return $language_first;
	}
}