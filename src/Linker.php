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
	 * Elements whose content is text, not markup, even if it looks like tags
	 */
	private const ELEMENTS_RAW = [ 'script', 'style', 'textarea' ];

	/**
	 * Inside of a tag, where quoted attribute values may contain “>” and, unclosed, run to the end, as in browsers; possessive, so that matching stays linear
	 */
	private const TAG_INSIDE = '(?:[^>=]|=\s*+(?>"[^"]*+(?:"|\z)|\'[^\']*+(?:\'|\z))?+)*+';

	/**
	 * Genitive endings a link includes (“Agatha Christies,” “Agatha Christie’s”)
	 */
	private const SUFFIXES = '(?:s|’s|&#8217;s|\'s)?';

	/**
	 * Returns HTML with the first mention of each target’s phrases linked
	 *
	 * Matches are case-sensitive and respect word boundaries; at the same position, longer phrases win. Each target is linked once, and each paragraph (or other block) gets one link at most, so that links spread out; custom phrases come first there, and do not count toward the maximum.
	 *
	 * @param string                $html HTML.
	 * @param array<string, string> $urls Phrases and their target URLs.
	 * @param int                   $count Maximum number of links.
	 * @param string                $class_link Class of the links, if any.
	 * @param array<string, string> $linked Phrases that got linked, with their target URLs, in order.
	 * @param array<string, string> $urls_custom Custom phrases and their target URLs.
	 */
	public static function link( string $html, array $urls, int $count, string $class_link = '', array &$linked = [], array $urls_custom = [] ): string {
		$linked = [];
		if ( ( ! $urls || $count < 1 ) && ! $urls_custom ) {
			return $html;
		}
		$longest_first = fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a );
		uksort( $urls, $longest_first );
		uksort( $urls_custom, $longest_first );

		// Raw text elements as one token each; unclosed tags and elements run to the end, as in browsers
		$elements_raw = implode( '|', array_map( fn( string $element ): string => '<' . $element . '\b' . self::TAG_INSIDE . '>.*?(?:<\/' . $element . '\s*>|\z)', self::ELEMENTS_RAW ) );
		$tokens       = preg_split( '/(<!--.*?-->|' . $elements_raw . '|<' . self::TAG_INSIDE . '(?:>|\z))/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
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
					$closed     = str_ends_with( $token, '/>' ) || preg_match( '/<\/' . $element . '\s*>$/i', $token );
					$depth_skip = '/' === $match[1] ? max( 0, $depth_skip - 1 ) : $depth_skip + ( $closed ? 0 : 1 );
				}
				if ( in_array( $element, self::ELEMENTS_BLOCK, true ) ) {
					$block_linked = false;
				}
			} elseif ( 0 === $depth_skip && ! $block_linked && '' !== trim( $token ) ) {
				$phrase   = self::match( $token, $urls_custom );
				$custom   = null !== $phrase;
				$phrase ??= $count > 0 ? self::match( $token, $urls ) : null;
				if ( null === $phrase ) {
					continue;
				}
				$url               = $custom ? $urls_custom[ $phrase ] : $urls[ $phrase ];
				$tokens[ $index ]  = self::link_text( $token, $phrase, $url, $class_link );
				$linked[ $phrase ] = $url;
				$block_linked      = true;
				$count            -= $custom ? 0 : 1;
				$urls              = array_filter( $urls, fn( string $url_other ): bool => $url_other !== $url );
				$urls_custom       = array_filter( $urls_custom, fn( string $url_other ): bool => $url_other !== $url );
				if ( ( ! $urls || $count < 1 ) && ! $urls_custom ) {
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
	 * @param string           $html HTML.
	 * @param array<string, T> $phrases Phrases, with any values.
	 * @return array<string, T>
	 */
	public static function mentioned( string $html, array $phrases ): array {
		return array_filter( $phrases, fn( $phrase ): bool => str_contains( $html, htmlspecialchars( (string) $phrase, ENT_NOQUOTES, 'UTF-8' ) ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Returns the phrase mentioned first in a text node, or null if there is none
	 *
	 * @param string                $text Text node (HTML-escaped).
	 * @param array<string, string> $urls Phrases and their target URLs, longest first.
	 */
	private static function match( string $text, array $urls ): ?string {
		if ( ! $urls ) {
			return null;
		}
		$phrases = [];
		foreach ( array_keys( $urls ) as $phrase ) {
			$phrases[ htmlspecialchars( $phrase, ENT_NOQUOTES, 'UTF-8' ) ] = $phrase;
		}

		return preg_match( self::pattern( array_keys( $phrases ) ), $text, $match ) ? $phrases[ $match[1] ] : null;
	}

	/**
	 * Links the first mention of a phrase in a text node
	 *
	 * @param string $text Text node (HTML-escaped).
	 * @param string $phrase Phrase.
	 * @param string $url Target URL.
	 * @param string $class_link Class of the link, if any.
	 */
	private static function link_text( string $text, string $phrase, string $url, string $class_link ): string {
		preg_match( self::pattern( [ htmlspecialchars( $phrase, ENT_NOQUOTES, 'UTF-8' ) ] ), $text, $match, PREG_OFFSET_CAPTURE );
		[ $mention, $offset ] = $match[0];
		$attribute_class      = '' === $class_link ? '' : ' class="' . htmlspecialchars( $class_link, ENT_QUOTES, 'UTF-8' ) . '"';

		return substr( $text, 0, $offset ) . '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '"' . $attribute_class . '>' . $mention . '</a>' . substr( $text, $offset + strlen( $mention ) );
	}

	/**
	 * Returns the pattern matching any of the phrases, with genitive endings, at word boundaries
	 *
	 * @param string[] $phrases Phrases (HTML-escaped), longest first.
	 */
	private static function pattern( array $phrases ): string {
		return '/(?<![\p{L}\p{N}])(' . implode( '|', array_map( fn( string $phrase ): string => preg_quote( $phrase, '/' ), $phrases ) ) . ')' . self::SUFFIXES . '(?![\p{L}\p{N}])/u';
	}
}