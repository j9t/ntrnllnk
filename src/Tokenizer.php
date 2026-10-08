<?php
/**
 * Splits text into stemmed words
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Splits text into stemmed words, dropping stopwords, short words, and numbers
 *
 * The stemming is deliberately light: it only needs to bring inflected forms of the same word together, not to produce linguistic stems.
 */
final class Tokenizer {

	/**
	 * Suffixes and their replacements, longest first, per language
	 */
	private const SUFFIXES = [
		'de' => [
			'ern' => '',
			'em'  => '',
			'en'  => '',
			'er'  => '',
			'es'  => '',
			'e'   => '',
			's'   => '',
		],
		'en' => [
			'sses' => 'ss',
			'ies'  => 'y',
			'ing'  => '',
			'ed'   => '',
			's'    => '',
		],
	];

	/**
	 * Endings that keep a final “s,” per language
	 */
	private const ENDINGS_S = [
		'de' => [ 'nis', 'ss' ],
		'en' => [ 'is', 'ss', 'us' ],
	];

	/**
	 * Minimum stem length, per language
	 */
	private const LENGTH_STEM_MIN = [
		'de' => 4,
		'en' => 3,
	];

	private const LENGTH_WORD_MIN = 3;

	/**
	 * Stopwords as a lookup table
	 *
	 * @var array<string, true>
	 */
	private array $stopwords;

	/**
	 * Creates a tokenizer for a language
	 *
	 * @param string $language ISO 639-1 code, e.g., `de`; other languages are only lowercased.
	 */
	public function __construct( private string $language ) {
		$this->stopwords = Stopwords::for_language( $language );
	}

	/**
	 * Returns the stemmed words of a text, in order and with repeats
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public function tokens( string $text ): array {
		$words  = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = [];
		foreach ( false === $words ? [] : $words as $word ) {
			if ( mb_strlen( $word ) < self::LENGTH_WORD_MIN || isset( $this->stopwords[ $word ] ) || preg_match( '/^\p{N}+$/u', $word ) ) {
				continue;
			}
			$tokens[] = $this->stem( $word );
		}

		return $tokens;
	}

	/**
	 * Returns the stem of a lowercase word
	 *
	 * @param string $word Lowercase word.
	 */
	public function stem( string $word ): string {
		if ( ! isset( self::SUFFIXES[ $this->language ] ) ) {
			return $word;
		}
		if ( 'de' === $this->language ) {
			$word = strtr(
				$word,
				[
					'ä' => 'a',
					'ö' => 'o',
					'ü' => 'u',
					'ß' => 'ss',
				]
			);
		}

		foreach ( self::SUFFIXES[ $this->language ] as $suffix => $replacement ) {
			if ( ! str_ends_with( $word, $suffix ) || ( 's' === $suffix && $this->keeps_s( $word ) ) ) {
				continue;
			}
			$stem = substr( $word, 0, -strlen( $suffix ) ) . $replacement;
			if ( mb_strlen( $stem ) >= self::LENGTH_STEM_MIN[ $this->language ] ) {
				$word = $stem;
				break;
			}
		}

		// German “-nis” plurals (“Geheimnisse”) double the “s”
		if ( 'de' === $this->language && str_ends_with( $word, 'niss' ) ) {
			$word = substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Returns whether a word’s final “s” is part of the stem
	 *
	 * @param string $word Lowercase word.
	 */
	private function keeps_s( string $word ): bool {
		foreach ( self::ENDINGS_S[ $this->language ] as $ending ) {
			if ( str_ends_with( $word, $ending ) ) {
				return true;
			}
		}

		return false;
	}
}