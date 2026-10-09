<?php
/**
 * Adds the report page
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the report page: for each post, its related posts, in-content links, and how many lists include it
 *
 * Lists come from the stored related posts. In-content links are not stored, so they are worked out for the posts on the current page only, from their content as shown.
 *
 * @phpstan-type Related list<array{title: string, url: string, score: float, visible: bool}>
 * @phpstan-type Links list<array{phrase: string, title: string, url: string}>
 * @phpstan-type Row array{id: int, title: string, url: string, related: Related|null, links: Links|null, listed: int}
 */
final class Report {

	public const SLUG = 'ntrnllnk-report';

	/**
	 * Number of posts per page, as each needs its content rendered
	 */
	private const COUNT_PAGE = 20;

	/**
	 * Filters besides showing all posts
	 */
	private const FILTERS = [ 'no-list', 'never-listed' ];

	/**
	 * Registers the admin hooks
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
	}

	/**
	 * Adds the report page to the Tools menu
	 */
	public static function add_page(): void {
		$hook = add_management_page( 'ntrnllnk', 'ntrnllnk', 'manage_options', self::SLUG, [ self::class, 'render_page' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load' ] );
		}
	}

	/**
	 * Prepares loading the report page
	 */
	public static function load(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'add_styles' ] );
	}

	/**
	 * Adds the report’s styles to WordPress’s admin styles
	 */
	public static function add_styles(): void {
		// Lists start level with the post title, rather than a line lower
		wp_add_inline_style( 'common', '.ntrnllnk-report td > ul { margin-top: 0; }' );
	}

	/**
	 * Outputs the report page
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Plugin::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only filters and pages what is shown
		$filter = sanitize_key( wp_unslash( $_GET['filter'] ?? '' ) );
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : '';
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable

		printf(
			'<div class="wrap">%s<p>%s</p><p>%s</p>',
			self::header_html(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
			esc_html__( 'For each post: its related posts, the links ntrnllnk adds to its text, and how many lists include it. In-content links are worked out for the posts on this page as you open it, so it may take a moment.', 'ntrnllnk' ),
			esc_html( Admin::status() )
		);
		if ( ! $settings['post_types'] ) {
			printf( '<p>%s</p></div>', esc_html__( 'No post types are selected in the settings.', 'ntrnllnk' ) );
			return;
		}

		$related  = self::related_all();
		$index    = self::index( $related, $settings['score_min'] );
		$excluded = [
			''             => [],
			'no-list'      => array_keys( $index['with_list'] ),
			'never-listed' => array_keys( $index['listed'] ),
		];
		$counts   = array_map( fn( array $ids ): int => self::query( $settings['post_types'], $ids, 1, 1 )->found_posts, $excluded );
		$query    = self::query( $settings['post_types'], $excluded[ $filter ], self::COUNT_PAGE, $page );
		/**
		 * Posts on this page
		 *
		 * @var \WP_Post[] $posts
		 */
		$posts = $query->posts;

		$rows = '';
		foreach ( self::rows( $posts, $related, $index, $settings ) as $row ) {
			$rows .= self::row_html( $row );
		}
		if ( '' === $rows ) {
			$rows = sprintf( '<tr><td colspan="4">%s</td></tr>', esc_html__( 'No posts found.', 'ntrnllnk' ) );
		}
		$url        = admin_url( 'tools.php?page=' . self::SLUG . ( '' === $filter ? '' : '&filter=' . $filter ) );
		$pagination = self::pagination_html( $page, (int) $query->max_num_pages, (int) $query->found_posts, $url );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
		echo self::views_html( $counts, $filter );
		printf(
			'<div class="tablenav top">%s<br class="clear"></div><table class="widefat striped ntrnllnk-report"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>%s</tbody></table><div class="tablenav bottom">%s<br class="clear"></div></div>',
			$pagination,
			esc_html__( 'Post', 'ntrnllnk' ),
			esc_html__( 'Related posts', 'ntrnllnk' ),
			esc_html__( 'In-content links', 'ntrnllnk' ),
			esc_html__( 'Listed by', 'ntrnllnk' ),
			$rows,
			$pagination
		);
		// phpcs:enable
	}

	/**
	 * Returns the page’s title, with a link to the settings next to it, as WordPress places actions
	 */
	public static function header_html(): string {
		return sprintf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html__( 'ntrnllnk Report', 'ntrnllnk' ),
			esc_url( admin_url( 'options-general.php?page=' . Admin::SLUG ) ),
			esc_html__( 'Settings', 'ntrnllnk' )
		);
	}

	/**
	 * Returns how many lists include each post, and which posts have a list, counting only what visitors see
	 *
	 * @param array<int, mixed> $related   Stored related posts per post ID.
	 * @param float             $score_min Minimum score.
	 * @return array{listed: array<int, int>, with_list: array<int, true>}
	 */
	public static function index( array $related, float $score_min ): array {
		$listed    = [];
		$with_list = [];
		foreach ( $related as $id => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			$visible = Plugin::visible( array_filter( $entries, 'is_array' ), $score_min );
			if ( $visible ) {
				$with_list[ $id ] = true;
			}
			foreach ( array_keys( $visible ) as $id_related ) {
				$listed[ $id_related ] = ( $listed[ $id_related ] ?? 0 ) + 1;
			}
		}

		return [
			'listed'    => $listed,
			'with_list' => $with_list,
		];
	}

	/**
	 * Returns a table row
	 *
	 * @param array $row Row data; related posts and links are null when turned off.
	 * @phpstan-param Row $row
	 */
	public static function row_html( array $row ): string {
		$cell = fn( ?array $items ): string => null === $items ? esc_html__( 'Off', 'ntrnllnk' ) : ( $items ? '<ul>' . implode( '', $items ) . '</ul>' : '—' );
		$link = fn( string $url, string $title ): string => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $title ) );

		$item = fn( array $entry ): string => sprintf( '<li>%s (%s)</li>', $link( $entry['url'], $entry['title'] ), Plugin::format_score( $entry['score'] ) );

		$related = $cell( null === $row['related'] ? null : array_map( $item, array_filter( $row['related'], fn( array $entry ): bool => $entry['visible'] ) ) );
		$misses  = array_map( $item, array_filter( $row['related'] ?? [], fn( array $entry ): bool => ! $entry['visible'] ) );
		if ( $misses ) {
			/* translators: %s: Number of posts */
			$related .= sprintf( '<details><summary>%s</summary><ul>%s</ul></details>', esc_html( sprintf( __( 'Below the minimum score (%s)', 'ntrnllnk' ), number_format_i18n( count( $misses ) ) ) ), implode( '', $misses ) );
		}
		$links = null === $row['links'] ? null : array_map( fn( array $entry ): string => sprintf( '<li>%s → %s</li>', esc_html( $entry['phrase'] ), $link( $entry['url'], $entry['title'] ) ), $row['links'] );

		return sprintf(
			'<tr><td><strong>%s</strong><div class="row-actions visible"><a href="%s">%s</a></div></td><td>%s</td><td>%s</td><td>%s</td></tr>',
			$link( $row['url'], $row['title'] ),
			esc_url( admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) ),
			esc_html__( 'Edit', 'ntrnllnk' ),
			$related,
			$cell( $links ),
			esc_html( number_format_i18n( $row['listed'] ) )
		);
	}

	/**
	 * Returns the pagination, as in WordPress’s tables: the number of posts, and links to the first, previous, next, and last page
	 *
	 * @param int    $page  Current page.
	 * @param int    $pages Number of pages.
	 * @param int    $count Number of posts.
	 * @param string $url   URL of the report, with its filter.
	 */
	public static function pagination_html( int $page, int $pages, int $count, string $url ): string {
		/* translators: %s: Number of posts */
		$html = sprintf( '<span class="displaying-num">%s</span>', esc_html( sprintf( _n( '%s post', '%s posts', $count, 'ntrnllnk' ), number_format_i18n( $count ) ) ) );
		if ( $pages <= 1 ) {
			return '<div class="tablenav-pages one-page">' . $html . '</div>';
		}

		$button = function ( string $class_link, int $target, string $label, string $symbol ) use ( $page, $pages, $url ): string {
			if ( $target === $page || $target < 1 || $target > $pages ) {
				return sprintf( '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span>', $symbol );
			}

			return sprintf( '<a class="%s button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>', $class_link, esc_url( add_query_arg( 'paged', $target, $url ) ), esc_html( $label ), $symbol );
		};

		return sprintf(
			'<div class="tablenav-pages">%s<span class="pagination-links">%s %s <span class="screen-reader-text">%s</span><span class="paging-input">%s</span> %s %s</span></div>',
			$html,
			$button( 'first-page', 1, __( 'First page', 'ntrnllnk' ), '«' ),
			$button( 'prev-page', $page - 1, __( 'Previous page', 'ntrnllnk' ), '‹' ),
			esc_html__( 'Current page', 'ntrnllnk' ),
			sprintf(
				/* translators: 1: Current page, 2: Number of pages */
				esc_html__( '%1$s of %2$s', 'ntrnllnk' ),
				esc_html( number_format_i18n( $page ) ),
				'<span class="total-pages">' . esc_html( number_format_i18n( $pages ) ) . '</span>'
			),
			$button( 'next-page', $page + 1, __( 'Next page', 'ntrnllnk' ), '›' ),
			$button( 'last-page', $pages, __( 'Last page', 'ntrnllnk' ), '»' )
		);
	}

	/**
	 * Returns the links to filter the report, with the number of posts each shows
	 *
	 * @param array<string, int> $counts  Number of posts per filter (`''` for all).
	 * @param string             $current Current filter.
	 */
	public static function views_html( array $counts, string $current ): string {
		$labels = [
			''             => __( 'All', 'ntrnllnk' ),
			'no-list'      => __( 'No list', 'ntrnllnk' ),
			'never-listed' => __( 'Never listed', 'ntrnllnk' ),
		];
		$views  = [];
		foreach ( $labels as $filter => $label ) {
			$url     = admin_url( 'tools.php?page=' . self::SLUG . ( '' === $filter ? '' : '&filter=' . $filter ) );
			$views[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>', esc_url( $url ), $current === $filter ? ' class="current" aria-current="page"' : '', esc_html( $label ), esc_html( number_format_i18n( $counts[ $filter ] ?? 0 ) ) );
		}

		return '<ul class="subsubsub">' . implode( ' | ', $views ) . '</ul>';
	}

	/**
	 * Returns the data of the rows for posts: their related posts, in-content links, and how many lists include them
	 *
	 * @param \WP_Post[]                                                                    $posts    Posts.
	 * @param array<int, mixed>                                                             $related  Stored related posts per post ID.
	 * @param array{listed: array<int, int>, with_list: array<int, true>}                   $index    Index from `index()`.
	 * @param array{count: int, links_inline: bool, score_min: float, post_types: string[]} $settings Settings.
	 * @return list<Row>
	 */
	private static function rows( array $posts, array $related, array $index, array $settings ): array {
		$entries = [];
		$linked  = [];
		$ids     = [];
		foreach ( $posts as $post ) {
			$entries[ $post->ID ] = array_filter( is_array( $related[ $post->ID ] ?? null ) ? $related[ $post->ID ] : [], 'is_array' );
			$linked[ $post->ID ]  = [];
			if ( $settings['links_inline'] ) {
				Plugin::link_post( $post->ID, self::content( $post ), $linked[ $post->ID ] );
			}
			$ids = [ ...$ids, ...array_keys( $entries[ $post->ID ] ), ...array_values( $linked[ $post->ID ] ) ];
		}

		// Titles and URLs of all targets at once; unpublished ones are left out, as when shown
		$targets = [];
		if ( $ids ) {
			$posts_target = get_posts(
				[
					'post__in'       => array_values( array_unique( $ids ) ),
					'post_type'      => $settings['post_types'],
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
				]
			);
			foreach ( $posts_target as $post_target ) {
				$targets[ $post_target->ID ] = [
					'title' => get_the_title( $post_target ),
					'url'   => (string) get_permalink( $post_target ),
				];
			}
		}

		$rows = [];
		foreach ( $posts as $post ) {
			$list = [];
			foreach ( $entries[ $post->ID ] as $id_related => $parts ) {
				if ( isset( $targets[ $id_related ] ) ) {
					$score  = array_sum( $parts );
					$list[] = $targets[ $id_related ] + [
						'score'   => $score,
						'visible' => (bool) Plugin::visible( [ $parts ], $settings['score_min'] ),
					];
				}
			}
			$links = [];
			foreach ( $linked[ $post->ID ] as $phrase => $id_target ) {
				if ( isset( $targets[ $id_target ] ) ) {
					$links[] = [ 'phrase' => (string) $phrase ] + $targets[ $id_target ];
				}
			}
			$rows[] = [
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'related' => $settings['count'] > 0 ? $list : null,
				'links'   => $settings['links_inline'] ? $links : null,
				'listed'  => $index['listed'][ $post->ID ] ?? 0,
			];
		}//end foreach

		return $rows;
	}

	/**
	 * Returns the stored related posts of all posts, in one query
	 *
	 * @return array<int, mixed>
	 */
	private static function related_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One query for all lists, instead of one per post, on an admin page only
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Plugin::META_KEY ) );

		$related = [];
		foreach ( (array) $rows as $row ) {
			$related[ (int) $row->post_id ] = maybe_unserialize( $row->meta_value );
		}

		return $related;
	}

	/**
	 * Returns a query of published posts, newest first
	 *
	 * @param string[] $post_types   Post types.
	 * @param int[]    $ids_excluded IDs of posts to leave out.
	 * @param int      $count        Number of posts per page.
	 * @param int      $page         Page.
	 */
	private static function query( array $post_types, array $ids_excluded, int $count, int $page ): \WP_Query {
		return new \WP_Query(
			[
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'post__not_in'        => $ids_excluded, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Admin page only
				'orderby'             => 'date',
				'order'               => 'DESC',
				'posts_per_page'      => $count,
				'paged'               => $page,
				'ignore_sticky_posts' => true,
			]
		);
	}

	/**
	 * Returns a post’s content as shown, for finding the links ntrnllnk adds to it
	 *
	 * @param \WP_Post $post_report Post.
	 */
	private static function content( \WP_Post $post_report ): string {
		global $post;
		$post_previous = $post;
		$post          = $post_report; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Content filters expect the post, as in the loop
		setup_postdata( $post );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter
		$content = (string) apply_filters( 'the_content', $post->post_content );
		$post    = $post_previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the post
		if ( $post instanceof \WP_Post ) {
			setup_postdata( $post );
		}

		return $content;
	}
}