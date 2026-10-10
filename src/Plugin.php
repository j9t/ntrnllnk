<?php
/**
 * Connects the ranker to WordPress
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the ranker to WordPress: rebuilds related posts in the background and outputs them
 *
 * Related posts are computed for all published posts at once, because a new post can change every other post’s list. Rebuilds run via WP-Cron, a minute after a post change (so that bulk edits cause one rebuild), right after a settings change, and daily as a safety net. They load posts in batches, so that large sites fit into memory.
 *
 * @phpstan-import-type Values from Settings
 */
final class Plugin {

	public const META_KEY = '_ntrnllnk_related';

	public const OPTION_PHRASES = 'ntrnllnk_phrases';

	public const OPTION_SETTINGS = 'ntrnllnk_settings';

	public const OPTION_REBUILD = 'ntrnllnk_rebuild';

	public const HOOK_REBUILD = 'ntrnllnk_rebuild';

	public const HOOK_REBUILD_DAILY = 'ntrnllnk_rebuild_daily';

	public const TRANSIENT_LOCK = 'ntrnllnk_rebuild_lock';

	private const DELAY_REBUILD = MINUTE_IN_SECONDS;

	/**
	 * Time after which a lock counts as abandoned, as by a rebuild that ran out of memory or time
	 */
	private const DURATION_LOCK = 15 * MINUTE_IN_SECONDS;

	/**
	 * Number of posts loaded at once
	 */
	private const COUNT_BATCH = 200;

	/**
	 * Post fields that, when changed on a published post, change related posts
	 */
	private const FIELDS_RELEVANT = [ 'post_content', 'post_date', 'post_password', 'post_title', 'post_type' ];

	/**
	 * Share of `score_min` down to which rebuilds keep posts when debugging, for editors to see near misses
	 */
	private const FACTOR_DEBUG = 0.5;

	/**
	 * Main plugin file
	 *
	 * @var string
	 */
	private static string $file = '';

	/**
	 * IDs of posts whose content placed the list via shortcode, so that it is not appended, too
	 *
	 * @var array<int, true>
	 */
	private static array $ids_shortcode = [];

	/**
	 * Registers all hooks
	 *
	 * @param string $file Main plugin file.
	 */
	public static function register( string $file ): void {
		self::$file = $file;

		register_activation_hook( $file, [ self::class, 'activate' ] );
		register_deactivation_hook( $file, [ self::class, 'deactivate' ] );

		add_action( 'init', [ self::class, 'load_textdomain' ] );
		add_action( 'init', [ self::class, 'register_output' ] );
		add_action( self::HOOK_REBUILD, [ self::class, 'rebuild' ] );
		add_action( self::HOOK_REBUILD_DAILY, [ self::class, 'rebuild' ] );
		add_action( 'transition_post_status', [ self::class, 'on_transition_post_status' ], 10, 3 );
		add_action( 'post_updated', [ self::class, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ self::class, 'on_before_delete_post' ], 10, 2 );
		add_action( 'set_object_terms', [ self::class, 'on_set_object_terms' ], 10, 6 );
		add_action( 'delete_term', [ self::class, 'on_delete_term' ], 10, 5 );
		add_action( 'update_option_' . self::OPTION_SETTINGS, [ self::class, 'on_settings_saved' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION_SETTINGS, [ self::class, 'on_settings_added' ], 10, 2 );
		add_action( 'admin_init', [ self::class, 'rebuild_after_update' ] );

		Admin::register( $file );
	}

	/**
	 * Registers the shortcode and, as enabled, the content filters for automatic placement and in-content links
	 */
	public static function register_output(): void {
		$settings = self::settings();
		add_shortcode( 'ntrnllnk', [ self::class, 'shortcode' ] );
		// Checked here, because `is_singular( [] )` matches any post type
		if ( ! $settings['post_types'] ) {
			return;
		}
		if ( 'auto' === $settings['placement'] ) {
			add_filter( 'the_content', [ self::class, 'render' ], $settings['priority'] );
		}
		if ( $settings['links_inline'] ) {
			// After shortcodes (11), so that their output counts, too
			add_filter( 'the_content', [ self::class, 'link_content' ], 12 );
		}
	}

	/**
	 * Returns the settings, as saved on the settings page, with defaults for the rest
	 *
	 * @return Values
	 */
	public static function settings(): array {
		// Sanitized again, so that settings stored otherwise than via the settings page cannot break anything
		$settings = Settings::sanitize( Settings::merge( get_option( self::OPTION_SETTINGS, [] ) ) );
		if ( '' === $settings['heading'] ) {
			$settings['heading'] = __( 'Further reading', 'ntrnllnk' );
		}

		return $settings;
	}

	/**
	 * Schedules the first and the daily rebuild
	 */
	public static function activate(): void {
		self::schedule_rebuild();
		if ( ! wp_next_scheduled( self::HOOK_REBUILD_DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_REBUILD_DAILY );
		}
	}

	/**
	 * Unschedules all rebuilds
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK_REBUILD );
		wp_clear_scheduled_hook( self::HOOK_REBUILD_DAILY );
	}

	/**
	 * Loads translations
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'ntrnllnk', false, dirname( plugin_basename( self::$file ) ) . '/languages' );
	}

	/**
	 * Schedules a rebuild when a post enters or leaves the published state
	 *
	 * Changes while published are left to `on_post_updated()`, which can tell whether they matter.
	 *
	 * @param string   $status_new New status.
	 * @param string   $status_old Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition_post_status( string $status_new, string $status_old, \WP_Post $post ): void {
		if ( ( 'publish' === $status_new ) !== ( 'publish' === $status_old ) && self::is_enabled( $post ) ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * Schedules a rebuild when a published post changes in a way that affects related posts
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post after the update.
	 * @param \WP_Post $post_before Post before the update.
	 */
	public static function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}
		if ( ! self::is_enabled( $post_after ) && ! self::is_enabled( $post_before ) ) {
			return;
		}
		foreach ( self::FIELDS_RELEVANT as $field ) {
			if ( $post_after->$field !== $post_before->$field ) {
				self::schedule_rebuild();
				return;
			}
		}
	}

	/**
	 * Schedules a rebuild when a published post is deleted without going through the trash
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function on_before_delete_post( int $post_id, \WP_Post $post ): void {
		if ( 'publish' === $post->post_status && self::is_enabled( $post ) ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * Schedules a rebuild when a published post’s terms change outside of saving it (as in bulk edits)
	 *
	 * @param int                     $id_object   Object ID.
	 * @param array<int|string|mixed> $terms       Terms as given.
	 * @param int[]                   $ids_tt      New term taxonomy IDs.
	 * @param string                  $taxonomy    Taxonomy.
	 * @param bool                    $append      Whether terms were appended.
	 * @param int[]                   $ids_tt_old  Old term taxonomy IDs.
	 */
	public static function on_set_object_terms( int $id_object, array $terms, array $ids_tt, string $taxonomy, bool $append, array $ids_tt_old ): void {
		sort( $ids_tt );
		sort( $ids_tt_old );
		if ( array_map( 'intval', $ids_tt ) === array_map( 'intval', $ids_tt_old ) ) {
			return;
		}
		$post = get_post( $id_object );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && self::is_enabled( $post ) ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * Schedules a rebuild when a term that posts had is deleted
	 *
	 * @param int    $id_term     Term ID.
	 * @param int    $id_tt       Term taxonomy ID.
	 * @param string $taxonomy   Taxonomy.
	 * @param mixed  $term        Deleted term.
	 * @param int[]  $ids_objects IDs of the objects that had the term.
	 */
	public static function on_delete_term( int $id_term, int $id_tt, string $taxonomy, mixed $term, array $ids_objects ): void {
		if ( $ids_objects ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * Rebuilds right away when saved settings change related posts or phrases
	 *
	 * @param mixed $settings_old Previous settings.
	 * @param mixed $settings_new New settings.
	 */
	public static function on_settings_saved( mixed $settings_old, mixed $settings_new ): void {
		$rebuilding = fn( mixed $settings ): array => Settings::rebuilding( Settings::merge( $settings ) );
		if ( $rebuilding( $settings_old ) !== $rebuilding( $settings_new ) ) {
			self::rebuild_soon();
		}
	}

	/**
	 * Rebuilds right away when settings saved for the first time change related posts or phrases
	 *
	 * @param string $option   Option name.
	 * @param mixed  $settings Settings.
	 */
	public static function on_settings_added( string $option, mixed $settings ): void {
		self::on_settings_saved( [], $settings );
	}

	/**
	 * Rebuilds right away after a plugin update, which activation hooks miss, unless a rebuild is pending or running
	 */
	public static function rebuild_after_update(): void {
		$rebuild = get_option( self::OPTION_REBUILD );
		if ( is_array( $rebuild ) && self::version() === ( $rebuild['version'] ?? null ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_REBUILD ) && ! get_transient( self::TRANSIENT_LOCK ) ) {
			self::rebuild_soon();
		}
	}

	/**
	 * Returns the plugin version, from the plugin header
	 */
	private static function version(): string {
		return (string) get_file_data( self::$file, [ 'version' => 'Version' ] )['version'];
	}

	/**
	 * Schedules a rebuild for right away, replacing a pending one; WP-Cron starts it on the next request
	 */
	public static function rebuild_soon(): void {
		wp_clear_scheduled_hook( self::HOOK_REBUILD );
		wp_schedule_single_event( time(), self::HOOK_REBUILD );
	}

	/**
	 * Schedules a single rebuild, unless one is pending
	 */
	public static function schedule_rebuild(): void {
		if ( ! wp_next_scheduled( self::HOOK_REBUILD ) ) {
			wp_schedule_single_event( time() + self::DELAY_REBUILD, self::HOOK_REBUILD );
		}
	}

	/**
	 * Recomputes and stores the related posts of all published posts, unless another rebuild is running, in which case it reschedules
	 */
	public static function rebuild(): void {
		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			self::schedule_rebuild();
			return;
		}
		set_transient( self::TRANSIENT_LOCK, true, self::DURATION_LOCK );
		try {
			self::build();
		} finally {
			delete_transient( self::TRANSIENT_LOCK );
		}
	}

	/**
	 * Recomputes and stores the related posts of all published posts
	 */
	private static function build(): void {
		wp_raise_memory_limit( 'ntrnllnk' );

		$time     = microtime( true );
		$settings = self::settings();
		// WordPress queries posts for an empty post type
		$ids = ! $settings['post_types'] ? [] : get_posts(
			[
				'post_type'        => $settings['post_types'],
				'post_status'      => 'publish',
				'has_password'     => false,
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			]
		);

		// Without a list, only phrases are needed, which saves most of the work
		$rank = $settings['count'] > 0;

		// @@ Reduce documents’ memory (about 30 KB per post) for sites beyond some 5,000 posts
		$taxonomies    = self::taxonomies( $settings['post_types'] );
		$language_site = substr( get_locale(), 0, 2 );
		$tokenizers    = [];
		$url_base      = home_url();
		$documents     = [];
		$mentions      = [];
		$times         = [];
		foreach ( array_chunk( $ids, self::COUNT_BATCH ) as $ids_batch ) {
			// Uncached, so that posts and their content do not pile up in the object cache
			$posts = get_posts(
				[
					'post__in'         => $ids_batch,
					'post_type'        => $settings['post_types'],
					'posts_per_page'   => -1,
					'cache_results'    => false,
					'suppress_filters' => true,
				]
			);
			if ( $rank ) {
				update_object_term_cache( $ids_batch, $settings['post_types'] );
			}

			foreach ( $posts as $post ) {
				$content               = strip_shortcodes( $post->post_content );
				$text                  = Extract::text( $content );
				$mentions[ $post->ID ] = Phrases::mentions( Extract::text( $post->post_title ), $text, $settings['links_inline_single_words'] );
				$times[ $post->ID ]    = (int) get_post_timestamp( $post );
				if ( ! $rank ) {
					continue;
				}
				$language                  = 'auto' === $settings['language'] ? Language::detect( $text, $language_site ) : $settings['language'];
				$tokenizers[ $language ] ??= new Tokenizer( $language );
				$documents[]               = Document::from_post(
					$post->ID,
					$post->post_title,
					$content,
					self::terms( $post, $taxonomies ),
					$times[ $post->ID ],
					$tokenizers[ $language ],
					$url_base
				);
			}
		}//end foreach

		$score_min = $settings['debug'] ? $settings['score_min'] * self::FACTOR_DEBUG : $settings['score_min'];
		$related   = $rank ? ( new Ranker( $settings['weights'], $score_min ) )->related( $documents, $settings['count'] ) : [];
		unset( $documents );
		update_option( self::OPTION_PHRASES, Phrases::extract( $mentions, $times ), $settings['links_inline'] );
		// `update_option()` changes autoloading only along with the value
		wp_set_option_autoload( self::OPTION_PHRASES, $settings['links_inline'] );

		$ids_stale = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs in the background
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			]
		);
		foreach ( $ids_stale as $id ) {
			if ( empty( $related[ $id ] ) ) {
				delete_post_meta( $id, self::META_KEY );
			}
		}
		// Primed in batches, so that `update_post_meta()` finds unchanged values without a query per post
		foreach ( array_chunk( array_keys( array_filter( $related ) ), self::COUNT_BATCH ) as $ids_batch ) {
			update_meta_cache( 'post', $ids_batch );
			foreach ( $ids_batch as $id ) {
				update_post_meta( $id, self::META_KEY, array_map( fn( array $parts ): array => array_map( fn( float $part ): float => round( $part, 6 ), $parts ), $related[ $id ] ) );
			}
		}

		update_option(
			self::OPTION_REBUILD,
			[
				'time'     => time(),
				'duration' => round( microtime( true ) - $time, 1 ),
				'posts'    => count( $ids ),
				'version'  => self::version(),
			],
			false
		);
	}

	/**
	 * Appends the related posts to the content of a single post, unless the content placed them via shortcode
	 *
	 * @param string $content Post content.
	 */
	public static function render( string $content ): string {
		if ( ! is_singular( self::settings()['post_types'] ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( isset( self::$ids_shortcode[ $id ] ) ) {
			return $content;
		}
		$html = self::html( $id );

		return '' === $html ? $content : $content . "\n" . $html;
	}

	/**
	 * Returns the related posts of the current post, for the `[ntrnllnk]` shortcode
	 */
	public static function shortcode(): string {
		$id = (int) get_the_ID();

		self::$ids_shortcode[ $id ] = true;

		return self::html( $id );
	}

	/**
	 * Returns the HTML of a post’s related posts, or an empty string if there are none
	 *
	 * @param int $id Post ID.
	 */
	public static function html( int $id ): string {
		$settings = self::settings();
		if ( $settings['count'] < 1 || ! $settings['post_types'] ) {
			return '';
		}
		$related = array_filter( (array) get_post_meta( $id, self::META_KEY, true ), 'is_array' );
		$debug   = $settings['debug'] && $related && current_user_can( 'edit_posts' );
		if ( ! $debug ) {
			$related = self::visible( $related, $settings['score_min'] );
		}
		if ( ! $related ) {
			return '';
		}

		// Checked again, because related posts may have been unpublished since the last rebuild
		$posts = get_posts(
			[
				'post__in'       => array_map( 'intval', array_keys( $related ) ),
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'post__in',
				'posts_per_page' => $settings['count'],
			]
		);
		if ( ! $posts ) {
			return '';
		}

		$level = 'auto' === $settings['heading_level'] ? Extract::heading_level_top( (string) get_post_field( 'post_content', $id ) ) ?? 2 : (int) $settings['heading_level'];
		$level = min( 6, max( 2, $level ) );
		$items = '';
		foreach ( $posts as $post ) {
			$url       = (string) get_permalink( $post );
			$url       = 'relative' === $settings['urls'] ? wp_make_link_relative( $url ) : $url;
			$link      = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( get_the_title( $post ) ) );
			$debugging = '';
			if ( $debug ) {
				$parts     = $related[ $post->ID ] ?? [];
				$debugging = ' ' . self::debugging( $parts, array_keys( Ranker::WEIGHTS ) );
				if ( round( array_sum( $parts ), 6 ) < $settings['score_min'] ) {
					/* translators: %s: Minimum score */
					$link = sprintf( '<del title="%s">%s</del>', esc_attr( sprintf( __( 'Below the minimum score (%s), so not shown to visitors', 'ntrnllnk' ), self::format_score( $settings['score_min'] ) ) ), $link );
				}
			}
			$items .= sprintf( '<li>%s%s</li>', $link, $debugging );
		}
		$html = sprintf( '<section class="ntrnllnk"><h%1$d>%2$s</h%1$d><ul>%3$s</ul></section>', $level, esc_html( $settings['heading'] ), $items );

		/**
		 * Filters the HTML of the related posts
		 *
		 * @param string     $html  HTML.
		 * @param \WP_Post[] $posts Related posts.
		 * @param int        $id    ID of the post they relate to.
		 */
		return apply_filters( 'ntrnllnk_html', $html, $posts, $id );
	}

	/**
	 * Returns the related posts that visitors see, leaving out near misses kept for debugging
	 *
	 * @param array<int, array<string, float>> $related   Related post IDs and their weighted similarity per signal.
	 * @param float                            $score_min Minimum score.
	 * @return array<int, array<string, float>>
	 */
	public static function visible( array $related, float $score_min ): array {
		return array_filter( $related, fn( array $parts ): bool => round( array_sum( $parts ), 6 ) >= $score_min );
	}

	/**
	 * Returns the debugging data of a related post: its score and the share of each signal
	 *
	 * @param array<string, float> $parts   Weighted similarity per contributing signal.
	 * @param string[]             $signals All signals.
	 */
	private static function debugging( array $parts, array $signals ): string {
		$labels = [
			'words' => __( 'words', 'ntrnllnk' ),
			'links' => __( 'links', 'ntrnllnk' ),
		];
		$shares = array_map( fn( string $signal ): string => ( $labels[ $signal ] ?? $signal ) . ' ' . self::format_score( $parts[ $signal ] ?? 0.0 ), $signals );

		/* translators: 1: Score, 2: Share of each signal */
		return sprintf( '<span class="ntrnllnk-debug">%s</span>', esc_html( sprintf( __( '[score: %1$s – %2$s]', 'ntrnllnk' ), self::format_score( array_sum( $parts ) ), implode( ', ', $shares ) ) ) );
	}

	/**
	 * Returns a score with up to three decimals, without trailing zeros
	 *
	 * @param float $score Score.
	 */
	public static function format_score( float $score ): string {
		return rtrim( rtrim( sprintf( '%.3F', round( $score, 6 ) ), '0' ), '.' );
	}

	/**
	 * Links the first mentions of other posts’ phrases in the content of a single post
	 *
	 * Links only to published posts, and not to posts the content already links to (outside the list of related posts), so that no post is linked twice.
	 *
	 * @param string $content Post content.
	 */
	public static function link_content( string $content ): string {
		$settings = self::settings();
		if ( ! is_singular( $settings['post_types'] ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::link_post( (int) get_the_ID(), $content );
	}

	/**
	 * Links the first mentions of other posts’ phrases in a post’s content, wherever it is shown
	 *
	 * @param int                $id      Post ID.
	 * @param string             $content Post content, as filtered for display.
	 * @param array<string, int> $linked  Phrases that got linked, with the IDs of the posts they link to.
	 */
	public static function link_post( int $id, string $content, array &$linked = [] ): string {
		$linked   = [];
		$settings = self::settings();
		if ( ! $settings['post_types'] || in_array( $id, array_map( 'intval', $settings['links_inline_exclude_posts'] ), true ) ) {
			return $content;
		}

		/**
		 * Filters the phrases to link in a post’s content
		 *
		 * @param array<string, int> $phrases Phrases and the IDs of the posts they link to.
		 * @param int                $id      ID of the post whose content gets linked.
		 */
		$phrases = apply_filters( 'ntrnllnk_phrases', (array) get_option( self::OPTION_PHRASES, [] ), $id );
		$phrases = array_diff_key( $phrases, array_flip( $settings['links_inline_exclude_phrases'] ) );
		$phrases = array_filter( $phrases, fn( $id_target ): bool => (int) $id_target !== $id );
		// Only phrases the content mentions, so that the work below depends on the post, not on the site
		$phrases = Linker::mentioned( $content, $phrases );
		if ( ! $phrases ) {
			return $content;
		}

		$posts = get_posts(
			[
				'post__in'       => array_values( array_unique( array_map( 'intval', $phrases ) ) ),
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
			]
		);
		// Posts the content links to, by ID, so that any form of their URLs counts (like `?p=123`)
		$host_site  = (string) Extract::normalize_url( home_url( '/' ) );
		$ids_linked = [];
		foreach ( Extract::hrefs( preg_replace( '#<section class="ntrnllnk">.*?</section>#s', '', $content ) ?? '', home_url() ) as $href ) {
			if ( str_starts_with( (string) Extract::normalize_url( $href ) . '/', $host_site . '/' ) ) {
				$ids_linked[ url_to_postid( $href ) ] = true;
			}
		}

		$urls = [];
		foreach ( $posts as $post ) {
			$urls[ $post->ID ] = (string) get_permalink( $post );
		}

		$urls_phrases = [];
		foreach ( $phrases as $phrase => $id_target ) {
			$url = $urls[ $id_target ] ?? null;
			if ( null !== $url && ! isset( $ids_linked[ $id_target ] ) ) {
				$urls_phrases[ (string) $phrase ] = 'relative' === $settings['urls'] ? wp_make_link_relative( $url ) : $url;
			}
		}

		$urls_linked = [];
		$content     = Linker::link( $content, $urls_phrases, $settings['links_inline_max'], $settings['links_class'] ? 'ntrnllnk-inline' : '', $urls_linked );
		foreach ( array_keys( $urls_linked ) as $phrase ) {
			$linked[ $phrase ] = (int) $phrases[ $phrase ];
		}

		return $content;
	}

	/**
	 * Returns whether related posts are enabled for a post’s type
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_enabled( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::settings()['post_types'], true );
	}

	/**
	 * Returns the public taxonomies of post types
	 *
	 * @param string[] $post_types Post types.
	 * @return string[]
	 */
	private static function taxonomies( array $post_types ): array {
		$taxonomies = get_object_taxonomies( $post_types, 'objects' );

		return array_keys( array_filter( $taxonomies, fn( \WP_Taxonomy $taxonomy ): bool => $taxonomy->public ) );
	}

	/**
	 * Returns the IDs of a post’s terms in the given taxonomies
	 *
	 * Reads from the term cache, which `build()` fills per batch.
	 *
	 * @param \WP_Post $post       Post.
	 * @param string[] $taxonomies Taxonomies.
	 * @return int[]
	 */
	private static function terms( \WP_Post $post, array $taxonomies ): array {
		$ids = [];
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$ids[] = $term->term_id;
				}
			}
		}

		return $ids;
	}
}