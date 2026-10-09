<?php
/**
 * Adds the settings page
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the settings page, with the rebuild status and a button to rebuild right away
 */
final class Admin {

	public const SLUG = 'ntrnllnk';

	public const ACTION_REBUILD = 'ntrnllnk_rebuild_now';

	private const CAPABILITY = 'manage_options';

	/**
	 * Registers the admin hooks
	 *
	 * @param string $file Main plugin file.
	 */
	public static function register( string $file ): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_' . self::ACTION_REBUILD, [ self::class, 'handle_rebuild' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), [ self::class, 'add_action_links' ] );
	}

	/**
	 * Adds the settings page to the Settings menu
	 */
	public static function add_page(): void {
		add_options_page( 'ntrnllnk', 'ntrnllnk', self::CAPABILITY, self::SLUG, [ self::class, 'render_page' ] );
	}

	/**
	 * Registers the setting, its sections, and its fields
	 */
	public static function register_settings(): void {
		register_setting(
			self::SLUG,
			Plugin::OPTION_SETTINGS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
		$sections = [
			'list'     => __( 'Related Posts', 'ntrnllnk' ),
			'inline'   => __( 'In-Content Links', 'ntrnllnk' ),
			'advanced' => __( 'Advanced', 'ntrnllnk' ),
		];
		foreach ( $sections as $section => $title ) {
			add_settings_section( 'ntrnllnk_' . $section, $title, '__return_null', self::SLUG );
		}
		foreach ( self::fields() as $key => $field ) {
			$args = [ 'key' => $key ];
			if ( 'post_types' !== $key ) {
				$args['label_for'] = 'ntrnllnk-' . $key;
			}
			add_settings_field( $key, $field['label'], [ self::class, 'render_field' ], self::SLUG, 'ntrnllnk_' . $field['section'], $args );
		}
	}

	/**
	 * Returns valid settings from the form
	 *
	 * @param mixed $input Form input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		return Settings::sanitize( is_array( $input ) ? $input : [], array_keys( self::post_types() ) );
	}

	/**
	 * Outputs the settings page
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap"><h1>ntrnllnk</h1><form action="options.php" method="post">';
		settings_fields( self::SLUG );
		do_settings_sections( self::SLUG );
		submit_button();
		printf(
			'</form><h2>%s</h2><p>%s</p><p>%s</p><form action="%s" method="post"><input type="hidden" name="action" value="%s">',
			esc_html__( 'Rebuild', 'ntrnllnk' ),
			esc_html__( 'ntrnllnk works out related posts and the subjects for in-content links in the background, in a rebuild. Changing posts, or saving settings that affect related posts, starts one by itself; this button starts one right away.', 'ntrnllnk' ),
			esc_html( self::status() ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_REBUILD )
		);
		wp_nonce_field( self::ACTION_REBUILD );
		submit_button( __( 'Rebuild Now', 'ntrnllnk' ), 'secondary', 'submit', false );
		echo '</form><hr><p>' . self::attribution() . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
	}

	/**
	 * Returns who makes ntrnllnk, and where to find the project
	 */
	public static function attribution(): string {
		return sprintf(
			/* translators: 1: Author, linked, 2: “GitHub,” linked */
			esc_html__( 'ntrnllnk is made by %1$s. Find the project, report issues, and contribute on %2$s.', 'ntrnllnk' ),
			'<a href="' . esc_url( 'https://meiert.com/' ) . '">Jens Oliver Meiert</a>',
			'<a href="' . esc_url( 'https://github.com/j9t/ntrnllnk' ) . '">GitHub</a>'
		);
	}

	/**
	 * Outputs a settings field
	 *
	 * @param array{key: string} $args Field arguments.
	 */
	public static function render_field( array $args ): void {
		$key      = $args['key'];
		$field    = self::fields()[ $key ];
		$settings = Settings::merge( get_option( Plugin::OPTION_SETTINGS, [] ) );
		$value    = $settings[ $key ];
		$id       = 'ntrnllnk-' . $key;
		$name     = Plugin::OPTION_SETTINGS . '[' . $key . ']';
		// Common attributes of the control, with its description, if any
		$control = sprintf( 'id="%s" name="%s%s"', esc_attr( $id ), esc_attr( $name ), 'weights' === $field['type'] ? '[words]' : '' );
		if ( isset( $field['description'] ) ) {
			$control .= sprintf( ' aria-describedby="%s-description"', esc_attr( $id ) );
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				$html = sprintf( '<label><input type="checkbox" %s value="1"%s> %s</label>', $control, $value ? ' checked' : '', esc_html( $field['text'] ?? '' ) );
				break;
			case 'number':
				foreach ( [ 'min', 'max', 'step' ] as $attribute ) {
					$control .= isset( $field[ $attribute ] ) ? sprintf( ' %s="%s"', $attribute, esc_attr( (string) $field[ $attribute ] ) ) : '';
				}
				$html = sprintf( '<input type="number" %s value="%s" class="small-text">', $control, esc_attr( (string) $value ) );
				break;
			case 'text':
				$value    = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				$control .= isset( $field['placeholder'] ) ? sprintf( ' placeholder="%s"', esc_attr( $field['placeholder'] ) ) : '';
				$html     = sprintf( '<input type="text" %s value="%s" class="regular-text">', $control, esc_attr( $value ) );
				break;
			case 'textarea':
				$html = sprintf( '<textarea %s rows="4" class="large-text">%s</textarea>', $control, esc_textarea( implode( "\n", (array) $value ) ) );
				break;
			case 'select':
				$options = '';
				foreach ( $field['choices'] ?? [] as $choice => $label ) {
					$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $choice ), (string) $choice === (string) $value ? ' selected' : '', esc_html( $label ) );
				}
				$html = sprintf( '<select %s>%s</select>', $control, $options );
				break;
			case 'post_types':
				$html = '';
				foreach ( self::post_types() as $post_type => $label ) {
					$html .= sprintf( '<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label><br>', esc_attr( $name ), esc_attr( $post_type ), in_array( $post_type, (array) $value, true ) ? ' checked' : '', esc_html( $label ) );
				}
				$html = sprintf( '<fieldset><legend class="screen-reader-text">%s</legend>%s</fieldset>', esc_html( $field['label'] ), $html );
				break;
			default:
				// Weights: words, as links get the rest
				$html = sprintf( '<input type="number" %s value="%s" min="0" max="1" step="0.05" class="small-text">', $control, esc_attr( (string) ( $value['words'] ?? '' ) ) );
		}//end switch

		if ( isset( $field['description'] ) ) {
			$html .= sprintf( '<p class="description" id="%s-description">%s</p>', esc_attr( $id ), esc_html( $field['description'] ) );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
	}

	/**
	 * Returns the status of rebuilds: running, pending, or the last one
	 */
	public static function status(): string {
		$reload = __( 'Reload the page to see when it’s done.', 'ntrnllnk' );
		if ( get_transient( Plugin::TRANSIENT_LOCK ) ) {
			return __( 'A rebuild is running.', 'ntrnllnk' ) . ' ' . $reload;
		}
		$time_next = wp_next_scheduled( Plugin::HOOK_REBUILD );
		if ( $time_next ) {
			/* translators: %s: Time */
			$pending = $time_next <= time() ? __( 'A rebuild is about to start.', 'ntrnllnk' ) : sprintf( __( 'A rebuild is scheduled for %s.', 'ntrnllnk' ), wp_date( (string) get_option( 'time_format' ), $time_next ) );

			return $pending . ' ' . $reload;
		}

		$rebuild = get_option( Plugin::OPTION_REBUILD );
		if ( ! is_array( $rebuild ) ) {
			return __( 'No rebuild yet.', 'ntrnllnk' );
		}

		return sprintf(
			/* translators: 1: Date and time, 2: Number of posts, 3: Duration in seconds */
			_n( 'Last rebuild: %1$s, for %2$s post, in %3$s seconds.', 'Last rebuild: %1$s, for %2$s posts, in %3$s seconds.', (int) $rebuild['posts'], 'ntrnllnk' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $rebuild['time'] ),
			number_format_i18n( (int) $rebuild['posts'] ),
			number_format_i18n( (float) $rebuild['duration'], 1 )
		);
	}

	/**
	 * Starts a rebuild on request of the button, and returns to the settings page
	 */
	public static function handle_rebuild(): void {
		self::request_rebuild();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Starts a rebuild, if the user may and the request is genuine
	 */
	public static function request_rebuild(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ntrnllnk' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION_REBUILD );
		Plugin::rebuild_soon();
	}

	/**
	 * Adds a link to the settings page to the plugin’s entry in the list of plugins
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function add_action_links( array $links ): array {
		return [ sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'ntrnllnk' ) ), ...$links ];
	}

	/**
	 * Returns the URL of the settings page
	 */
	private static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Returns the public post types other than attachments, with their names
	 *
	 * @return array<string, string>
	 */
	private static function post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'] );

		return array_map( fn( object $post_type ): string => $post_type->labels->name, $post_types );
	}

	/**
	 * Returns the fields per setting, in order
	 *
	 * @return array<string, array{section: string, label: string, type: string, text?: string, description?: string, placeholder?: string, min?: int|float, max?: int|float, step?: int|float, choices?: array<int|string, string>}>
	 */
	private static function fields(): array {
		return [
			'count'                        => [
				'section'     => 'list',
				'label'       => __( 'Number of related posts', 'ntrnllnk' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => Settings::COUNT_MAX,
				'description' => __( 'Lists show fewer if fewer posts are related closely enough; 0 turns the list off.', 'ntrnllnk' ),
			],
			'heading'                      => [
				'section'     => 'list',
				'label'       => __( 'Heading', 'ntrnllnk' ),
				'type'        => 'text',
				'placeholder' => __( 'Further reading', 'ntrnllnk' ),
			],
			'heading_level'                => [
				'section'     => 'list',
				'label'       => __( 'Heading level', 'ntrnllnk' ),
				'type'        => 'select',
				'choices'     => [
					2      => 'h2',
					3      => 'h3',
					4      => 'h4',
					5      => 'h5',
					6      => 'h6',
					'auto' => __( 'Automatic', 'ntrnllnk' ),
				],
				'description' => __( 'Automatic uses the highest level in the post’s content, so that the list sits at the level of its top sections.', 'ntrnllnk' ),
			],
			'score_min'                    => [
				'section'     => 'list',
				'label'       => __( 'Minimum score', 'ntrnllnk' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => 1,
				'step'        => 0.005,
				'description' => __( 'How closely posts need to be related to show (0–1); raise it if lists show unrelated posts, lower it if fitting posts are missing.', 'ntrnllnk' ),
			],
			'debug'                        => [
				'section' => 'list',
				'label'   => __( 'Debugging', 'ntrnllnk' ),
				'type'    => 'checkbox',
				'text'    => __( 'Show logged-in users who can edit posts the scores behind the list, and posts that just missed the minimum score (struck through)', 'ntrnllnk' ),
			],
			'links_inline'                 => [
				'section' => 'inline',
				'label'   => __( 'In-content links', 'ntrnllnk' ),
				'type'    => 'checkbox',
				'text'    => __( 'Link mentions of other posts’ subjects in the text', 'ntrnllnk' ),
			],
			'links_inline_max'             => [
				'section' => 'inline',
				'label'   => __( 'Maximum per post', 'ntrnllnk' ),
				'type'    => 'number',
				'min'     => 0,
				'max'     => Settings::COUNT_MAX,
			],
			'links_inline_exclude_phrases' => [
				'section'     => 'inline',
				'label'       => __( 'Excluded phrases', 'ntrnllnk' ),
				'type'        => 'textarea',
				'description' => __( 'Phrases never to link, one per line.', 'ntrnllnk' ),
			],
			'links_inline_exclude_posts'   => [
				'section'     => 'inline',
				'label'       => __( 'Excluded posts', 'ntrnllnk' ),
				'type'        => 'text',
				'description' => __( 'IDs of posts whose content gets no in-content links, separated by commas.', 'ntrnllnk' ),
			],
			'links_class'                  => [
				'section' => 'inline',
				'label'   => __( 'Class', 'ntrnllnk' ),
				'type'    => 'checkbox',
				'text'    => __( 'Give in-content links the class “ntrnllnk-inline,” to style or track them', 'ntrnllnk' ),
			],
			'post_types'                   => [
				'section' => 'advanced',
				'label'   => __( 'Post types', 'ntrnllnk' ),
				'type'    => 'post_types',
			],
			'placement'                    => [
				'section'     => 'advanced',
				'label'       => __( 'Placement', 'ntrnllnk' ),
				'type'        => 'select',
				'choices'     => [
					'auto'   => __( 'After the content', 'ntrnllnk' ),
					'manual' => __( 'Manual', 'ntrnllnk' ),
				],
				'description' => __( 'For manual placement, use the [ntrnllnk] shortcode in a post, or ntrnllnk_render() in a theme template.', 'ntrnllnk' ),
			],
			'priority'                     => [
				'section'     => 'advanced',
				'label'       => __( 'Priority', 'ntrnllnk' ),
				'type'        => 'number',
				'description' => __( 'With placement after the content: the lower, the further up the list shows among what other plugins add to the content.', 'ntrnllnk' ),
			],
			'urls'                         => [
				'section' => 'advanced',
				'label'   => __( 'URLs', 'ntrnllnk' ),
				'type'    => 'select',
				'choices' => [
					'absolute' => __( 'Absolute (https://…), which work wherever the content goes', 'ntrnllnk' ),
					'relative' => __( 'Root-relative (/…)', 'ntrnllnk' ),
				],
			],
			'language'                     => [
				'section' => 'advanced',
				'label'   => __( 'Language', 'ntrnllnk' ),
				'type'    => 'select',
				'choices' => [
					'auto' => __( 'Detect per post', 'ntrnllnk' ),
					'de'   => __( 'German', 'ntrnllnk' ),
					'en'   => __( 'English', 'ntrnllnk' ),
				],
			],
			'weights'                      => [
				'section'     => 'advanced',
				'label'       => __( 'Weight of words', 'ntrnllnk' ),
				'type'        => 'weights',
				'description' => __( 'How much shared words count, compared with shared links, which get the rest (0–1).', 'ntrnllnk' ),
			],
		];
	}
}