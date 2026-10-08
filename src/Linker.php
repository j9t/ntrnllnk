<?php
/**
 * Links phrases in HTML
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Links the first mention of phrases in HTML, outside headings, links, code, tables, and figures (like images)
 */
final class Linker {

	/**
	 * Elements whose text is never linked
	 */
	private const ELEMENTS_SKIP = [ 'a', 'button', 'code', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'script', 'style', 'table', 'textarea' ];

	/**
	 * Genitive endings a link includes (“Agatha Christies,” “Agatha Christie’s”)
	 */
	private const SUFFIXES = '(?:s|’s|&#8217;s|\'s)?';

	/**
	 * Returns HTML with the first mention of each target’s phrases linked
	 *
	 * Matches are case-sensitive and respect word boundaries; at the same position, longer phrases win. Each target is linked once.
	 *
	 * @param string                $html  HTML.
	 * @param array<string, string> $urls  Phrases and their target URLs.
	 * @param int                   $count Maximum number of links.
	 */
	public static function link( string $html, array $urls, int $count ): string {
		if ( ! $urls || $count < 1 ) {
			return $html;
		}
		uksort( $urls, fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		$tokens = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $tokens ) {
			return $html;
		}

		$depth_skip = 0;
		foreach ( $tokens as $index => $token ) {
			if ( str_starts_with( $token, '<' ) ) {
				if ( preg_match( '/^<(\/?)([a-z][a-z0-9]*)/i', $token, $match ) && in_array( strtolower( $match[2] ), self::ELEMENTS_SKIP, true ) ) {
					$depth_skip = '/' === $match[1] ? max( 0, $depth_skip - 1 ) : $depth_skip + ( str_ends_with( $token, '/>' ) ? 0 : 1 );
				}
			} elseif ( 0 === $depth_skip && '' !== trim( $token ) ) {
				$tokens[ $index ] = self::link_text( $token, $urls, $count );
				if ( ! $urls || $count < 1 ) {
					break;
				}
			}
		}

		return implode( '', $tokens );
	}

	/**
	 * Links phrases in a text node, removing linked targets and counting down
	 *
	 * @param string                $text  Text node (HTML-escaped).
	 * @param array<string, string> $urls  Phrases and their target URLs, longest first.
	 * @param int                   $count Remaining number of links.
	 */
	private static function link_text( string $text, array &$urls, int &$count ): string {
		$result   = '';
		$position = 0;
		while ( $urls && $count > 0 ) {
			$phrases = [];
			foreach ( array_keys( $urls ) as $phrase ) {
				$phrases[ htmlspecialchars( $phrase, ENT_NOQUOTES, 'UTF-8' ) ] = $phrase;
			}
			$pattern = '/(?<![\p{L}\p{N}])(' . implode( '|', array_map( fn( string $phrase ): string => preg_quote( $phrase, '/' ), array_keys( $phrases ) ) ) . ')' . self::SUFFIXES . '(?![\p{L}\p{N}])/u';
			if ( ! preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE, $position ) ) {
				break;
			}

			[ $mention, $offset ] = $match[0];
			$url                  = $urls[ $phrases[ $match[1][0] ] ];

			$result  .= substr( $text, $position, $offset - $position ) . '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $mention . '</a>';
			$position = $offset + strlen( $mention );
			$urls     = array_filter( $urls, fn( string $url_other ): bool => $url_other !== $url );
			--$count;
		}

		return $result . substr( $text, $position );
	}
}