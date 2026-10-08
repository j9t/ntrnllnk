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
 * Related posts are computed for all published posts at once, because a new post can change every other post’s list. Rebuilds run via WP-Cron, a minute after a change (so that bulk edits cause one rebuild) and daily as a safety net.
 */
final class Plugin {

	public const META_KEY = '_ntrnllnk_related';

	public const HOOK_REBUILD = 'ntrnllnk_rebuild';

	public const HOOK_REBUILD_DAILY = 'ntrnllnk_rebuild_daily';

	private const DELAY_REBUILD = MINUTE_IN_SECONDS;

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
		add_action( 'before_delete_post', [ self::class, 'on_before_delete_post' ], 10, 2 );
	}

	/**
	 * Registers the shortcode and, for automatic placement, the content filter
	 *
	 * Runs on `init`, after themes have loaded, so that settings from a theme apply.
	 */
	public static function register_output(): void {
		$settings = self::settings();
		if ( 'auto' === $settings['placement'] ) {
			add_filter( 'the_content', [ self::class, 'render' ], $settings['priority'] );
		}
		add_shortcode( 'ntrnllnk', [ self::class, 'shortcode' ] );
	}

	/**
	 * Returns the settings, adjustable via the `ntrnllnk_settings` filter
	 *
	 * @return array{post_types: string[], count: int, heading: string, heading_level: int|'auto', urls: 'absolute'|'relative', placement: 'auto'|'manual', priority: int, language: string, weights: array<string, float>, score_min: float}
	 */
	public static function settings(): array {
		$defaults = [
			'post_types'    => [ 'post' ],
			'count'         => 5,
			'heading'       => __( 'Further reading', 'ntrnllnk' ),
			'heading_level' => 2,
			'urls'          => 'absolute',
			'placement'     => 'auto',
			'priority'      => 20,
			'language'      => 'auto',
			'weights'       => Ranker::WEIGHTS,
			'score_min'     => 0.02,
		];

		/**
		 * Filters the settings
		 *
		 * @param array $settings Settings: `post_types`, `count`, `heading`, `heading_level` (2–6 or `auto`), `urls` (`absolute`, `relative`), `placement` (`auto`, `manual`), `priority`, `language` (`auto`, `de`, `en`), `weights` (`words`, `links`), and `score_min`.
		 */
		return array_merge( $defaults, apply_filters( 'ntrnllnk_settings', $defaults ) );
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
	 * Schedules a rebuild when a post enters, leaves, or changes while in the published state
	 *
	 * @param string   $status_new New status.
	 * @param string   $status_old Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition_post_status( string $status_new, string $status_old, \WP_Post $post ): void {
		if ( ( 'publish' === $status_new || 'publish' === $status_old ) && self::is_enabled( $post ) ) {
			self::schedule_rebuild();
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
	 * Schedules a single rebuild, unless one is pending
	 */
	public static function schedule_rebuild(): void {
		if ( ! wp_next_scheduled( self::HOOK_REBUILD ) ) {
			wp_schedule_single_event( time() + self::DELAY_REBUILD, self::HOOK_REBUILD );
		}
	}

	/**
	 * Recomputes and stores the related posts of all published posts
	 */
	public static function rebuild(): void {
		$settings = self::settings();
		$posts    = get_posts(
			[
				'post_type'        => $settings['post_types'],
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			]
		);

		$terms         = self::terms( $posts, $settings['post_types'] );
		$language_site = substr( get_locale(), 0, 2 );
		$tokenizers    = [];
		$url_base      = home_url();
		$documents     = [];
		foreach ( $posts as $post ) {
			$content  = strip_shortcodes( $post->post_content );
			$language = 'auto' === $settings['language'] ? Language::detect( Extract::text( $content ), $language_site ) : $settings['language'];

			$tokenizers[ $language ] ??= new Tokenizer( $language );
			$documents[]               = Document::from_post(
				$post->ID,
				$post->post_title,
				$content,
				$terms[ $post->ID ] ?? [],
				(int) get_post_timestamp( $post ),
				$tokenizers[ $language ],
				$url_base
			);
		}

		$related = ( new Ranker( $settings['weights'], $settings['score_min'] ) )->related( $documents, $settings['count'] );

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
		foreach ( $related as $id => $scores ) {
			if ( $scores ) {
				update_post_meta( $id, self::META_KEY, array_keys( $scores ) );
			}
		}
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
		$ids      = get_post_meta( $id, self::META_KEY, true );
		if ( ! is_array( $ids ) || ! $ids ) {
			return '';
		}

		// Checked again, because related posts may have been unpublished since the last rebuild
		$posts = get_posts(
			[
				'post__in'       => array_map( 'intval', $ids ),
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
			$url    = (string) get_permalink( $post );
			$url    = 'relative' === $settings['urls'] ? wp_make_link_relative( $url ) : $url;
			$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( get_the_title( $post ) ) );
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
	 * Returns whether related posts are enabled for a post’s type
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_enabled( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::settings()['post_types'], true );
	}

	/**
	 * Returns the IDs of the terms of public taxonomies, per post
	 *
	 * Reads from the term cache, which `get_posts()` has filled.
	 *
	 * @param \WP_Post[] $posts      Posts.
	 * @param string[]   $post_types Post types.
	 * @return array<int, int[]>
	 */
	private static function terms( array $posts, array $post_types ): array {
		$taxonomies    = get_object_taxonomies( $post_types, 'objects' );
		$taxonomies    = array_keys( array_filter( $taxonomies, fn( \WP_Taxonomy $taxonomy ): bool => $taxonomy->public ) );
		$terms_by_post = [];
		foreach ( $posts as $post ) {
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post, $taxonomy );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$terms_by_post[ $post->ID ][] = $term->term_id;
					}
				}
			}
		}

		return $terms_by_post;
	}
}