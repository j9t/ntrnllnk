<?php
/**
 * Measures how long a rebuild takes and how much memory it needs, outside WordPress
 *
 * Runs on generated posts (`--posts=2000`) or on a site’s posts exported with WP-CLI (`--input=benchmark.json`):
 *
 *     wp post list --post_status=publish --fields=ID,post_title,post_content,post_date --format=json > benchmark.json
 *
 * `--count=0` skips ranking, as for a site without the list (see `Plugin::build()`). `--frequency-max=500` sets how many posts a feature may be in before ranking skips it (see `Ranker::FREQUENCY_MAX`). With `--compare`, it also ranks without skipping features and reports how many related posts match, to check the effect. Unlike a real rebuild, it neither strips shortcodes nor uses terms.
 *
 * @package Ntrnllnk
 */

declare( strict_types = 1 );

namespace Ntrnllnk;

define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Lets the classes load outside WordPress

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Default number of related posts per post, as in `Plugin::settings()`
 */
const NTRNLLNK_COUNT_RELATED = 5;

/**
 * Returns generated posts: words with a natural (Zipf) distribution, some links, and titles with names the text mentions
 *
 * @param int $count Number of posts.
 * @return array<int, array{title: string, content: string, time: int}>
 */
function ntrnllnk_posts_generated( int $count ): array {
	mt_srand( 1 );
	$syllables = [ 'ba', 'do', 'fi', 'gu', 'ka', 'lo', 'mi', 'nu', 'pa', 'ro', 'si', 'tu', 'wa', 'zo' ];
	$word      = function ( int $index ) use ( $syllables ): string {
		$word = '';
		do {
			$word .= $syllables[ $index % count( $syllables ) ];
			$index = intdiv( $index, count( $syllables ) );
		} while ( $index > 0 );

		return str_pad( $word, 4, 'ka' );
	};

	// Cumulative Zipf weights, for picking word ranks by binary search
	$count_vocabulary = 20000;
	$weights          = [];
	$sum              = 0.0;
	for ( $rank = 1; $rank <= $count_vocabulary; $rank++ ) {
		$sum      += 1 / $rank;
		$weights[] = $sum;
	}
	$pick = function () use ( $weights, $sum, $count_vocabulary ): int {
		$target = mt_rand() / mt_getrandmax() * $sum;
		$low    = 0;
		$high   = $count_vocabulary - 1;
		while ( $low < $high ) {
			$middle = ( $low + $high ) >> 1;
			if ( $weights[ $middle ] < $target ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		return $low;
	};

	$posts = [];
	for ( $id = 1; $id <= $count; $id++ ) {
		$name       = ucfirst( $word( mt_rand( 0, 5000 ) ) ) . ' ' . ucfirst( $word( mt_rand( 0, 5000 ) ) );
		$paragraphs = [];
		for ( $paragraph = 0; $paragraph < 12; $paragraph++ ) {
			$words = [];
			for ( $index = 0; $index < 50; $index++ ) {
				$words[] = $word( $pick() );
			}
			if ( $paragraph < 3 ) {
				$words[] = $name;
			}
			$paragraphs[] = '<p>' . implode( ' ', $words ) . ' <a href="https://example.org/' . intdiv( mt_rand( 1, 1000 ) ** 2, 1000 ) . '">link</a></p>';
		}
		$posts[ $id ] = [
			'title'   => $name . ' in Order',
			'content' => implode( "\n", $paragraphs ),
			'time'    => $id,
		];
	}

	return $posts;
}

/**
 * Returns posts from a WP-CLI JSON export
 *
 * @param string $file JSON file.
 * @throws \RuntimeException If the file cannot be read.
 * @return array<int, array{title: string, content: string, time: int}>
 */
function ntrnllnk_posts_exported( string $file ): array {
	$rows = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $rows ) ) {
		throw new \RuntimeException( 'Could not read ' . $file );
	}
	$posts = [];
	foreach ( $rows as $row ) {
		$posts[ (int) $row['ID'] ] = [
			'title'   => (string) $row['post_title'],
			'content' => (string) $row['post_content'],
			'time'    => (int) strtotime( (string) ( $row['post_date'] ?? '' ) ),
		];
	}

	return $posts;
}

/**
 * Runs the steps of a rebuild and returns a report
 *
 * Mirrors `Plugin::build()`, without WordPress.
 *
 * @param array<int, array{title: string, content: string, time: int}> $posts         Posts by ID.
 * @param int                                                          $count         Number of related posts per post; 0 skips ranking.
 * @param int                                                          $frequency_max Maximum number of posts a feature may be in.
 * @param bool                                                         $compare       Whether to compare with ranking that keeps all features.
 */
function ntrnllnk_benchmark( array $posts, int $count, int $frequency_max, bool $compare ): string {
	$time       = microtime( true );
	$tokenizers = [];
	$documents  = [];
	$mentions   = [];
	$times      = [];
	foreach ( $posts as $id => $post ) {
		$text            = Extract::text( $post['content'] );
		$mentions[ $id ] = Phrases::mentions( Extract::text( $post['title'] ), $text );
		$times[ $id ]    = $post['time'];
		if ( $count < 1 ) {
			continue;
		}
		$language                  = Language::detect( $text, 'en' );
		$tokenizers[ $language ] ??= new Tokenizer( $language );
		$documents[]               = Document::from_post( $id, $post['title'], $post['content'], [], $post['time'], $tokenizers[ $language ], 'https://example.com' );
	}
	unset( $posts );
	$duration_documents = microtime( true ) - $time;
	$memory_documents   = memory_get_usage();

	$time             = microtime( true );
	$related          = $count > 0 ? ( new Ranker( frequency_max: $frequency_max ) )->related( $documents, $count ) : [];
	$duration_ranking = microtime( true ) - $time;

	$time             = microtime( true );
	$phrases          = Phrases::extract( $mentions, $times );
	$duration_phrases = microtime( true ) - $time;

	$lines = [
		sprintf( 'Posts:     %s', number_format( count( $mentions ) ) ),
		sprintf( 'Documents: %6.1f s (%s MB after)', $duration_documents, number_format( $memory_documents / 1e6 ) ),
		sprintf( 'Ranking:   %6.1f s', $duration_ranking ),
		sprintf( 'Phrases:   %6.1f s (%s found)', $duration_phrases, number_format( count( $phrases ) ) ),
		sprintf( 'Peak:      %s MB', number_format( memory_get_peak_usage() / 1e6 ) ),
	];

	if ( $compare && $count > 0 ) {
		$time    = microtime( true );
		$exact   = ( new Ranker( frequency_max: PHP_INT_MAX ) )->related( $documents, $count );
		$matches = 0;
		$total   = 0;
		foreach ( $exact as $id => $scores ) {
			$total   += count( $scores );
			$matches += count( array_intersect_key( $scores, $related[ $id ] ) );
		}
		$lines[] = sprintf( 'Exact:     %6.1f s, %.1f%% of related posts match', microtime( true ) - $time, $total ? 100 * $matches / $total : 100 );
	}

	return implode( "\n", $lines );
}

/**
 * Returns a command-line option’s value, or null if it is not given
 *
 * @param string $name Option name.
 */
function ntrnllnk_option( string $name ): ?string {
	static $options = null;
	$options      ??= getopt( '', [ 'posts:', 'input:', 'count:', 'frequency-max:', 'compare' ] );
	$value          = is_array( $options ) ? $options[ $name ] ?? null : null;

	return is_string( $value ) || false === $value ? (string) $value : null;
}

try {
	$ntrnllnk_input = ntrnllnk_option( 'input' );
	$ntrnllnk_posts = null === $ntrnllnk_input ? ntrnllnk_posts_generated( (int) ( ntrnllnk_option( 'posts' ) ?? 2000 ) ) : ntrnllnk_posts_exported( $ntrnllnk_input );
	fwrite( STDOUT, ntrnllnk_benchmark( $ntrnllnk_posts, (int) ( ntrnllnk_option( 'count' ) ?? NTRNLLNK_COUNT_RELATED ), (int) ( ntrnllnk_option( 'frequency-max' ) ?? Ranker::FREQUENCY_MAX ), null !== ntrnllnk_option( 'compare' ) ) . "\n" );
} catch ( \RuntimeException $err ) {
	fwrite( STDERR, $err->getMessage() . "\n" );
	exit( 1 );
}