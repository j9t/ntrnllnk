<?php
/**
 * Defines and sanitizes the settings
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Defines and sanitizes the settings
 *
 * Sanitizing turns form input into complete settings and leaves sanitized settings unchanged, as WordPress may sanitize twice when saving.
 *
 * @phpstan-type Values array{post_types: string[], count: int, heading: string, heading_level: int|'auto', urls: string, placement: string, priority: int, links_inline: bool, links_inline_max: int, links_inline_single_words: bool, links_inline_exclude_phrases: string[], links_inline_exclude_posts: int[], links_class: bool, language: string, weights: array{words: float, links: float}, score_min: float, debug: bool}
 */
final class Settings {

	/**
	 * Default settings; an empty heading stands for the translated default heading
	 */
	public const DEFAULTS = [
		'post_types'                   => [ 'post' ],
		'count'                        => 5,
		'heading'                      => '',
		'heading_level'                => 2,
		'urls'                         => 'absolute',
		'placement'                    => 'auto',
		'priority'                     => 20,
		'links_inline'                 => true,
		'links_inline_max'             => 3,
		'links_inline_single_words'    => false,
		'links_inline_exclude_phrases' => [],
		'links_inline_exclude_posts'   => [],
		'links_class'                  => false,
		'language'                     => 'auto',
		'weights'                      => Ranker::WEIGHTS,
		'score_min'                    => 0.04,
		'debug'                        => false,
	];

	/**
	 * Settings that decide related posts or the phrases for in-content links, so that changing them requires a rebuild
	 */
	public const REBUILD = [ 'post_types', 'count', 'links_inline_single_words', 'language', 'weights', 'score_min', 'debug' ];

	/**
	 * Maximum number of related posts and of in-content links per post
	 */
	public const COUNT_MAX = 20;

	/**
	 * Choices per setting, the first being the default
	 */
	private const CHOICES = [
		'urls'      => [ 'absolute', 'relative' ],
		'placement' => [ 'auto', 'manual' ],
		'language'  => [ 'auto', ...Language::SUPPORTED ],
	];

	/**
	 * Returns complete, valid settings from form input; invalid values fall back to their defaults, missing checkboxes count as off
	 *
	 * @param array<string, mixed> $input      Form input.
	 * @param string[]|null        $post_types Available post types, or null to keep all.
	 * @return Values
	 */
	public static function sanitize( array $input, ?array $post_types = null ): array {
		$weight_words = is_numeric( $input['weights']['words'] ?? null ) ? round( (float) self::clamp( (float) $input['weights']['words'], 0, 1 ), 2 ) : self::DEFAULTS['weights']['words'];
		$level        = $input['heading_level'] ?? null;

		return [
			'post_types'                   => array_values( array_filter( array_map( 'strval', (array) ( $input['post_types'] ?? [] ) ), fn( string $post_type ): bool => null === $post_types || in_array( $post_type, $post_types, true ) ) ),
			'count'                        => self::integer( $input['count'] ?? null, self::DEFAULTS['count'], 0, self::COUNT_MAX ),
			'heading'                      => trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $input['heading'] ?? '' ) ) ),
			'heading_level'                => 'auto' === $level ? 'auto' : self::integer( $level, self::DEFAULTS['heading_level'], 2, 6, true ),
			'urls'                         => self::choice( $input, 'urls' ),
			'placement'                    => self::choice( $input, 'placement' ),
			'priority'                     => self::integer( $input['priority'] ?? null, self::DEFAULTS['priority'], PHP_INT_MIN, PHP_INT_MAX ),
			'links_inline'                 => ! empty( $input['links_inline'] ),
			'links_inline_max'             => self::integer( $input['links_inline_max'] ?? null, self::DEFAULTS['links_inline_max'], 0, self::COUNT_MAX ),
			'links_inline_single_words'    => ! empty( $input['links_inline_single_words'] ),
			'links_inline_exclude_phrases' => self::lines( $input['links_inline_exclude_phrases'] ?? [] ),
			'links_inline_exclude_posts'   => self::ids( $input['links_inline_exclude_posts'] ?? [] ),
			'links_class'                  => ! empty( $input['links_class'] ),
			'language'                     => self::choice( $input, 'language' ),
			'weights'                      => [
				'words' => $weight_words,
				'links' => round( 1 - $weight_words, 2 ),
			],
			'score_min'                    => is_numeric( $input['score_min'] ?? null ) ? (float) self::clamp( (float) $input['score_min'], 0, 1 ) : self::DEFAULTS['score_min'],
			'debug'                        => ! empty( $input['debug'] ),
		];
	}

	/**
	 * Returns stored settings, with defaults for missing ones, and without unknown ones
	 *
	 * @param mixed $settings Stored settings.
	 * @return array<string, mixed>
	 */
	public static function merge( mixed $settings ): array {
		return array_merge( self::DEFAULTS, array_intersect_key( (array) $settings, self::DEFAULTS ) );
	}

	/**
	 * Returns the settings that require a rebuild
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	public static function rebuilding( array $settings ): array {
		return array_intersect_key( $settings, array_flip( self::REBUILD ) );
	}

	/**
	 * Returns a whole number, limited to a range, or the default if the value is not numeric (or, with `$strict`, out of range)
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Default.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param bool  $strict   Whether values out of range fall back to the default instead of being limited.
	 */
	private static function integer( mixed $value, int $fallback, int $min, int $max, bool $strict = false ): int {
		if ( ! is_numeric( $value ) || ( $strict && ( $value < $min || $value > $max ) ) ) {
			return $fallback;
		}

		return (int) self::clamp( (int) $value, $min, $max );
	}

	/**
	 * Returns a value limited to a range
	 *
	 * @param int|float $value Value.
	 * @param int|float $min   Minimum.
	 * @param int|float $max   Maximum.
	 */
	private static function clamp( int|float $value, int|float $min, int|float $max ): int|float {
		return min( $max, max( $min, $value ) );
	}

	/**
	 * Returns a setting’s value if it is one of its choices, else its default
	 *
	 * @param array<string, mixed> $input   Form input.
	 * @param string               $setting Setting.
	 */
	private static function choice( array $input, string $setting ): string {
		$value = $input[ $setting ] ?? null;

		return in_array( $value, self::CHOICES[ $setting ], true ) ? $value : self::CHOICES[ $setting ][0];
	}

	/**
	 * Returns the distinct, non-empty lines of a text, or of a list
	 *
	 * @param mixed $value Text with one entry per line, or list.
	 * @return string[]
	 */
	private static function lines( mixed $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\R/u', (string) $value );

		return array_values( array_unique( array_filter( array_map( fn( $line ): string => trim( (string) $line ), (array) $lines ), fn( string $line ): bool => '' !== $line ) ) );
	}

	/**
	 * Returns the distinct positive IDs in a text, or in a list
	 *
	 * @param mixed $value Text with IDs separated by anything but digits, or list.
	 * @return int[]
	 */
	private static function ids( mixed $value ): array {
		$ids = is_array( $value ) ? $value : preg_split( '/\D+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), fn( int $id ): bool => $id > 0 ) ) );
	}
}