<?php
/**
 * Extracts text, headings, and links from post HTML
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts text, headings, and links from post HTML
 *
 * Like the other core classes, it does not call WordPress, so that it can be tested without it.
 */
final class Extract {

	/**
	 * Elements whose boundaries separate words
	 */
	private const ELEMENTS_BLOCK = 'address|article|aside|blockquote|br|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|nav|ol|p|pre|section|table|td|th|tr|ul';

	/**
	 * Returns the plain text of HTML, with whitespace collapsed
	 *
	 * @param string $html HTML.
	 */
	public static function text( string $html ): string {
		$html = preg_replace( '#<!--.*?-->#s', '', $html ) ?? '';
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ) ?? '';
		$html = preg_replace( '#<(/?)(' . self::ELEMENTS_BLOCK . ')\b#i', ' <$1$2', $html ) ?? '';
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Kept WordPress-independent

		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * Returns the plain text of each heading
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function headings( string $html ): array {
		preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $matches );

		return array_map( [ self::class, 'text' ], $matches[2] );
	}

	/**
	 * Returns the highest heading level (the lowest number), or null if there are no headings
	 *
	 * @param string $html HTML.
	 */
	public static function heading_level_top( string $html ): ?int {
		preg_match_all( '#<h([1-6])\b#i', $html, $matches );

		return $matches[1] ? (int) min( $matches[1] ) : null;
	}

	/**
	 * Returns the unique, normalized targets of all links
	 *
	 * Root-relative targets are resolved against `$url_base`, if given; other relative targets are skipped.
	 *
	 * @param string $html     HTML.
	 * @param string $url_base Site URL, e.g., `https://example.com`.
	 * @return string[]
	 */
	public static function links( string $html, string $url_base = '' ): array {
		return array_values( array_unique( array_filter( array_map( [ self::class, 'normalize_url' ], self::hrefs( $html, $url_base ) ) ) ) );
	}

	/**
	 * Returns the unique, absolute HTTP(S) targets of all links, as they are
	 *
	 * Root-relative targets are resolved against `$url_base`, if given; other relative targets are skipped.
	 *
	 * @param string $html     HTML.
	 * @param string $url_base Site URL, e.g., `https://example.com`.
	 * @return string[]
	 */
	public static function hrefs( string $html, string $url_base = '' ): array {
		preg_match_all( '#<a\b[^>]*?\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );

		$origin_base = self::origin( $url_base );
		$hrefs       = [];
		foreach ( $matches as $match ) {
			$href = trim( html_entity_decode( $match[1] ?? $match[2] ?? $match[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( str_starts_with( $href, '//' ) ) {
				$href = 'https:' . $href;
			} elseif ( str_starts_with( $href, '/' ) ) {
				if ( null === $origin_base ) {
					continue;
				}
				$href = $origin_base . $href;
			}
			if ( null !== self::normalize_url( $href ) ) {
				$hrefs[ $href ] = true;
			}
		}

		return array_keys( $hrefs );
	}

	/**
	 * Returns an absolute HTTP(S) URL as host and path, without “www.,” query, fragment, and trailing slash
	 *
	 * @param string $url URL.
	 */
	public static function normalize_url( string $url ): ?string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Kept WordPress-independent
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
			return null;
		}
		$host = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );

		return $host . rtrim( $parts['path'] ?? '', '/' );
	}

	/**
	 * Returns scheme and host of a URL, or null if there are none
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): ?string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Kept WordPress-independent
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}