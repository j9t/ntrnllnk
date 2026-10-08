<?php
/**
 * A post as the ranker sees it
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * A post as the ranker sees it: weighted words, link targets, and terms
 */
final class Document {

	/**
	 * Weight of words in the title
	 */
	private const WEIGHT_TITLE = 3;

	/**
	 * Extra weight of words in headings, which also count as body text
	 */
	private const WEIGHT_HEADING = 1;

	/**
	 * Creates a document
	 *
	 * @param int                $id    Post ID.
	 * @param array<string, int> $words Stemmed words and their weighted counts.
	 * @param string[]           $links Normalized link targets.
	 * @param int[]              $terms Term IDs.
	 * @param int                $time  Publication time (Unix timestamp).
	 */
	public function __construct(
		public readonly int $id,
		public readonly array $words,
		public readonly array $links,
		public readonly array $terms,
		public readonly int $time
	) {}

	/**
	 * Creates a document from post data
	 *
	 * @param int       $id        Post ID.
	 * @param string    $title     Post title.
	 * @param string    $html      Post content.
	 * @param int[]     $terms     Term IDs.
	 * @param int       $time      Publication time (Unix timestamp).
	 * @param Tokenizer $tokenizer Tokenizer for the site language.
	 * @param string    $url_base  Site URL, to resolve root-relative links.
	 */
	public static function from_post( int $id, string $title, string $html, array $terms, int $time, Tokenizer $tokenizer, string $url_base = '' ): self {
		$words = [];
		self::count( $words, $tokenizer->tokens( Extract::text( $title ) ), self::WEIGHT_TITLE );
		self::count( $words, $tokenizer->tokens( implode( ' ', Extract::headings( $html ) ) ), self::WEIGHT_HEADING );
		self::count( $words, $tokenizer->tokens( Extract::text( $html ) ), 1 );

		return new self( $id, $words, Extract::links( $html, $url_base ), array_values( array_unique( $terms ) ), $time );
	}

	/**
	 * Adds weighted tokens to word counts
	 *
	 * @param array<string, int> $words  Word counts.
	 * @param string[]           $tokens Tokens.
	 * @param int                $weight Weight per occurrence.
	 */
	private static function count( array &$words, array $tokens, int $weight ): void {
		foreach ( $tokens as $token ) {
			$words[ $token ] = ( $words[ $token ] ?? 0 ) + $weight;
		}
	}
}