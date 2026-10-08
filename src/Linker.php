<?php
/**
 * Links phrases in HTML
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Links the first mention of phrases in HTML, outside headings, links, code, tables, and figures (like images), and at most once per paragraph
 */
final class Linker {

	/**
	 * Elements whose text is never linked
	 */
	private const ELEMENTS_SKIP = [ 'a', 'button', 'code', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'script', 'style', 'table', 'textarea' ];

	/**
	 * Elements that start a new paragraph, in which a new link may follow
	 */
	private const ELEMENTS_BLOCK = [ 'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ];

	/**
	 * Genitive endings a link includes (“Agatha Christies,” “Agatha Christie’s”)
	 */
	private const SUFFIXES = '(?:s|’s|&#8217;s|\'s)?';

	/**
	 * Returns HTML with the first mention of each target’s phrases linked
	 *
	 * Matches are case-sensitive and respect word boundaries; at the same position, longer phrases win. Each target is linked once, and each paragraph (or other block) gets one link at most, so that links spread out.
	 *
	 * @param string                $html  HTML.
	 * @param array<string, string> $urls  Phrases and their target URLs.
	 * @param int                   $count Maximum number of links.
	 * @param string                $class_link Class of the links, if any.
	 */
	public static function link( string $html, array $urls, int $count, string $class_link = '' ): string {
		if ( ! $urls || $count < 1 ) {
			return $html;
		}
		uksort( $urls, fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		$tokens = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $tokens ) {
			return $html;
		}

		$depth_skip   = 0;
		$block_linked = false;
		foreach ( $tokens as $index => $token ) {
			if ( str_starts_with( $token, '<' ) ) {
				if ( ! preg_match( '/^<(\/?)([a-z][a-z0-9]*)/i', $token, $match ) ) {
					continue;
				}
				$element = strtolower( $match[2] );
				if ( in_array( $element, self::ELEMENTS_SKIP, true ) ) {
					$depth_skip = '/' === $match[1] ? max( 0, $depth_skip - 1 ) : $depth_skip + ( str_ends_with( $token, '/>' ) ? 0 : 1 );
				}
				if ( in_array( $element, self::ELEMENTS_BLOCK, true ) ) {
					$block_linked = false;
				}
			} elseif ( 0 === $depth_skip && ! $block_linked && '' !== trim( $token ) ) {
				$tokens[ $index ] = self::link_text( $token, $urls, $class_link );
				if ( $tokens[ $index ] !== $token ) {
					$block_linked = true;
					--$count;
				}
				if ( ! $urls || $count < 1 ) {
					break;
				}
			}//end if
		}//end foreach

		return implode( '', $tokens );
	}

	/**
	 * Returns the phrases that HTML contains, as a quick check before linking
	 *
	 * Text in HTML is escaped, so phrases are, too; this may keep phrases that `link()` will not link (like those in attributes), but never drops one it would link.
	 *
	 * @template T
	 * @param string           $html    HTML.
	 * @param array<string, T> $phrases Phrases, with any values.
	 * @return array<string, T>
	 */
	public static function mentioned( string $html, array $phrases ): array {
		return array_filter( $phrases, fn( $phrase ): bool => str_contains( $html, htmlspecialchars( (string) $phrase, ENT_NOQUOTES, 'UTF-8' ) ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Links the first phrase mention in a text node, if any, and removes its target
	 *
	 * @param string                $text Text node (HTML-escaped).
	 * @param array<string, string> $urls  Phrases and their target URLs, longest first.
	 * @param string                $class_link Class of the link, if any.
	 */
	private static function link_text( string $text, array &$urls, string $class_link ): string {
		$phrases = [];
		foreach ( array_keys( $urls ) as $phrase ) {
			$phrases[ htmlspecialchars( $phrase, ENT_NOQUOTES, 'UTF-8' ) ] = $phrase;
		}
		$pattern = '/(?<![\p{L}\p{N}])(' . implode( '|', array_map( fn( string $phrase ): string => preg_quote( $phrase, '/' ), array_keys( $phrases ) ) ) . ')' . self::SUFFIXES . '(?![\p{L}\p{N}])/u';
		if ( ! preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
			return $text;
		}

		[ $mention, $offset ] = $match[0];
		$url                  = $urls[ $phrases[ $match[1][0] ] ];
		$urls                 = array_filter( $urls, fn( string $url_other ): bool => $url_other !== $url );

		$attribute_class = '' === $class_link ? '' : ' class="' . htmlspecialchars( $class_link, ENT_QUOTES, 'UTF-8' ) . '"';

		return substr( $text, 0, $offset ) . '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '"' . $attribute_class . '>' . $mention . '</a>' . substr( $text, $offset + strlen( $mention ) );
	}
}