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

		Report::register();
	}

	/**
	 * Adds the settings page to the Settings menu
	 */
	public static function add_page(): void {
		$hook = add_options_page( self::title(), 'ntrnllnk', self::CAPABILITY, self::SLUG, [ self::class, 'render_page' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load' ] );
		}
	}

	/**
	 * Prepares loading the settings page
	 */
	public static function load(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'add_styles' ] );
	}

	/**
	 * Adds the settings page’s styles to WordPress’s admin styles
	 */
	public static function add_styles(): void {
		// Like the labels of the fields, for the summary to read as a control
		wp_add_inline_style( 'common', '.ntrnllnk-advanced > summary { cursor: pointer; font-weight: 600; }' );
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
		$input = is_array( $input ) ? $input : [];
		// Resetting discards the form’s values
		if ( ! empty( $input['reset'] ) ) {
			$input = Settings::DEFAULTS;
		}

		return Settings::sanitize( $input, array_keys( self::post_types() ) );
	}

	/**
	 * Outputs the settings page
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		printf( '<div class="wrap"><h1>%s</h1><form action="options.php" method="post">', esc_html( self::title() ) );
		settings_fields( self::SLUG );
		self::render_sections();
		echo '<p class="submit">';
		// Empty, for WordPress’s “Save Changes”
		submit_button( '', 'primary', 'submit', false );
		echo ' ';
		submit_button(
			__( 'Reset to Defaults', 'ntrnllnk' ),
			'secondary',
			Plugin::OPTION_SETTINGS . '[reset]',
			false,
			[
				'id'             => 'ntrnllnk-reset',
				// Also with invalid values in the form
				'formnovalidate' => 'formnovalidate',
				'onclick'        => 'return confirm(' . wp_json_encode( __( 'Reset all settings to their defaults?', 'ntrnllnk' ) ) . ');',
			]
		);
		echo '</p>';
		printf(
			'</form><h2>%s</h2><p>%s</p><p class="submit"><a href="%s" class="button">%s</a></p><h2>%s</h2><p>%s</p><p>%s</p><form action="%s" method="post"><input type="hidden" name="action" value="%s">',
			esc_html__( 'Report', 'ntrnllnk' ),
			esc_html__( 'The report shows, for each post, its related posts and the links ntrnllnk adds to its text.', 'ntrnllnk' ),
			esc_url( admin_url( 'tools.php?page=' . Report::SLUG ) ),
			esc_html__( 'Open Report', 'ntrnllnk' ),
			esc_html__( 'Rebuild', 'ntrnllnk' ),
			esc_html__( 'ntrnllnk works out related posts and the subjects for in-content links in the background, in a rebuild. Changing posts, or saving settings that affect related posts, starts one by itself; this button starts one right away.', 'ntrnllnk' ),
			esc_html( self::status() ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_REBUILD )
		);
		wp_nonce_field( self::ACTION_REBUILD );
		submit_button( __( 'Rebuild Now', 'ntrnllnk' ), 'secondary', 'submit', true );
		echo '</form><hr><p>' . self::attribution() . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
	}

	/**
	 * Outputs the sections with their fields, each section’s advanced fields collapsed
	 */
	private static function render_sections(): void {
		$sections_fields = array_column( self::fields(), 'section' );
		$table           = function ( string $section ): void {
			echo '<table class="form-table" role="presentation">';
			do_settings_fields( self::SLUG, 'ntrnllnk_' . $section );
			echo '</table>';
		};
		foreach ( self::sections() as $section => $title ) {
			printf( '<h2>%s</h2>', esc_html( $title ) );
			$table( $section );
			if ( in_array( $section . '_advanced', $sections_fields, true ) ) {
				printf( '<details class="ntrnllnk-advanced"><summary>%s</summary>', esc_html__( 'Advanced', 'ntrnllnk' ) );
				$table( $section . '_advanced' );
				echo '</details>';
			}
		}
	}

	/**
	 * Returns the sections, with their titles; fields of a section’s `_advanced` part show collapsed below it
	 *
	 * @return array<string, string>
	 */
	private static function sections(): array {
		return [
			'list'    => __( 'Related Posts', 'ntrnllnk' ),
			'inline'  => __( 'In-Content Links', 'ntrnllnk' ),
			'general' => __( 'General', 'ntrnllnk' ),
		];
	}

	/**
	 * Returns the title of the settings page
	 */
	public static function title(): string {
		return __( 'ntrnllnk—Automatic Internal Linking', 'ntrnllnk' );
	}

	/**
	 * Returns who makes ntrnllnk, and where to find the project
	 */
	public static function attribution(): string {
		$link = fn( string $url, string $text ): string => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html( $text ) );

		return sprintf(
			/* translators: 1: Author, linked, 2: “Contribute and support on GitHub,” linked */
			esc_html__( 'A project by %1$s (%2$s).', 'ntrnllnk' ),
			$link( 'https://meiert.com/', 'Jens Oliver Meiert' ),
			$link( 'https://github.com/j9t/ntrnllnk', __( 'contribute and support on GitHub', 'ntrnllnk' ) )
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
		$default  = self::default( $key, $field );
		/* translators: %s: Default value */
		$description = trim( ( $field['description'] ?? '' ) . ( '' === $default ? '' : ' ' . sprintf( __( 'Default: %s.', 'ntrnllnk' ), $default ) ) );
		$describedby = '' === $description ? '' : sprintf( ' aria-describedby="%s-description"', esc_attr( $id ) );
		// Common attributes of the control
		$control = sprintf( 'id="%s" name="%s%s"%s', esc_attr( $id ), esc_attr( $name ), 'weights' === $field['type'] ? '[words]' : '', $describedby );

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
				$html = sprintf( '<fieldset%s><legend class="screen-reader-text">%s</legend>%s</fieldset>', $describedby, esc_html( $field['label'] ), $html );
				break;
			default:
				// Weights: words, as links get the rest
				$html = sprintf( '<input type="number" %s value="%s" min="0" max="1" step="0.05" class="small-text">', $control, esc_attr( (string) ( $value['words'] ?? '' ) ) );
		}//end switch

		if ( '' !== $description ) {
			$html .= sprintf( '<p class="description" id="%s-description">%s</p>', esc_attr( $id ), esc_html( $description ) );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built
	}

	/**
	 * Returns a setting’s default as shown on the settings page, or an empty string for empty defaults and defaults shown as placeholders
	 *
	 * @param string                                                   $key   Setting.
	 * @param array{type: string, choices?: array<int|string, string>} $field Field.
	 */
	private static function default( string $key, array $field ): string {
		// With as many decimals as the number has, formatted for the site’s language
		$number = fn( int|float $number ): string => number_format_i18n( $number, max( 0, strlen( (string) strrchr( (string) $number, '.' ) ) - 1 ) );
		if ( 'weights' === $field['type'] ) {
			return $number( Settings::DEFAULTS['weights']['words'] );
		}
		if ( 'post_types' === $field['type'] ) {
			return implode( ', ', array_intersect_key( self::post_types(), array_flip( Settings::DEFAULTS['post_types'] ) ) );
		}

		$default = Settings::DEFAULTS[ $key ];

		return match ( true ) {
			is_bool( $default )                                                       => $default ? __( 'On', 'ntrnllnk' ) : __( 'Off', 'ntrnllnk' ),
			'select' === $field['type'] && ( is_int( $default ) || is_string( $default ) ) => $field['choices'][ $default ] ?? '',
			is_int( $default ) || is_float( $default )                                 => $number( $default ),
			default                                                                    => '',
		};
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
			'post_types'                   => [
				'section' => 'general',
				'label'   => __( 'Post types', 'ntrnllnk' ),
				'type'    => 'post_types',
			],
			'urls'                         => [
				'section'     => 'general',
				'label'       => __( 'URLs', 'ntrnllnk' ),
				'type'        => 'select',
				'choices'     => [
					'absolute' => __( 'Absolute (https://…)', 'ntrnllnk' ),
					'relative' => __( 'Root-relative (/…)', 'ntrnllnk' ),
				],
				'description' => __( 'Absolute URLs work wherever the content goes, like in feeds and email.', 'ntrnllnk' ),
			],
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
			'placement'                    => [
				'section'     => 'list_advanced',
				'label'       => __( 'Placement', 'ntrnllnk' ),
				'type'        => 'select',
				'choices'     => [
					'auto'   => __( 'After the content', 'ntrnllnk' ),
					'manual' => __( 'Manual', 'ntrnllnk' ),
				],
				'description' => __( 'For manual placement, use the [ntrnllnk] shortcode in a post, or ntrnllnk_render() in a theme template.', 'ntrnllnk' ),
			],
			'priority'                     => [
				'section'     => 'list_advanced',
				'label'       => __( 'Priority', 'ntrnllnk' ),
				'type'        => 'number',
				'description' => __( 'With placement after the content: the lower, the further up the list shows among what other plugins add to the content.', 'ntrnllnk' ),
			],
			'language'                     => [
				'section' => 'list_advanced',
				'label'   => __( 'Language', 'ntrnllnk' ),
				'type'    => 'select',
				'choices' => [
					'auto' => __( 'Detect per post', 'ntrnllnk' ),
					'de'   => __( 'German', 'ntrnllnk' ),
					'en'   => __( 'English', 'ntrnllnk' ),
				],
			],
			'weights'                      => [
				'section'     => 'list_advanced',
				'label'       => __( 'Weight of words', 'ntrnllnk' ),
				'type'        => 'weights',
				'description' => __( 'How much shared words count, compared with shared links, which get the rest (0–1).', 'ntrnllnk' ),
			],
			'debug'                        => [
				'section' => 'list_advanced',
				'label'   => __( 'Debugging', 'ntrnllnk' ),
				'type'    => 'checkbox',
				'text'    => __( 'Show scores of related posts, and posts below the minimum score, to logged-in users (contributors and above)', 'ntrnllnk' ),
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
			'links_inline_single_words'    => [
				'section'     => 'inline',
				'label'       => __( 'Single words', 'ntrnllnk' ),
				'type'        => 'checkbox',
				'text'        => __( 'Also link single words from titles, like product or person names', 'ntrnllnk' ),
				'description' => __( 'More links, though some may miss: check the report, and exclude phrases as needed.', 'ntrnllnk' ),
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
				'section' => 'inline_advanced',
				'label'   => __( 'Class', 'ntrnllnk' ),
				'type'    => 'checkbox',
				'text'    => __( 'Give in-content links the class “ntrnllnk-inline,” to style or track them', 'ntrnllnk' ),
			],
		];
	}
}