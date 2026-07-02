<?php
/**
 * Language switcher widget, shortcode, and template function.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Language_Switcher extends WP_Widget {

	/**
	 * Register the widget.
	 */
	public function __construct() {
		parent::__construct(
			'leadwerk_language_switcher',
			__( 'Leadwerk Language Switcher', 'leadwerk-wpml-clone' ),
			array( 'description' => __( 'Displays language switcher links.', 'leadwerk-wpml-clone' ) )
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
		add_shortcode( 'leadwerk_language_switcher', array( __CLASS__, 'shortcode_handler' ) );
	}

	/**
	 * Register the widget with WP.
	 *
	 * @return void
	 */
	public static function register_widget() {
		register_widget( __CLASS__ );
	}

	/**
	 * Front-end widget output.
	 *
	 * @param array<string,string> $args     Widget arguments.
	 * @param array<string,mixed>  $instance Widget instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		echo wp_kses_post( $args['before_widget'] ?? '' );
		echo self::render( $instance );
		echo wp_kses_post( $args['after_widget'] ?? '' );
	}

	/**
	 * Widget admin form.
	 *
	 * @param array<string,mixed> $instance Current settings.
	 * @return void
	 */
	public function form( $instance ) {
		$style = $instance['style'] ?? 'horizontal';
		$show_flags = ! empty( $instance['show_flags'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">
				<?php esc_html_e( 'Style:', 'leadwerk-wpml-clone' ); ?>
			</label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="horizontal" <?php selected( $style, 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'leadwerk-wpml-clone' ); ?></option>
				<option value="vertical" <?php selected( $style, 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'leadwerk-wpml-clone' ); ?></option>
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'leadwerk-wpml-clone' ); ?></option>
			</select>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_flags' ) ); ?>" value="1" <?php checked( $show_flags ); ?> />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>">
				<?php esc_html_e( 'Show language codes', 'leadwerk-wpml-clone' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Save widget settings.
	 *
	 * @param array<string,mixed> $new_instance New settings.
	 * @param array<string,mixed> $old_instance Old settings.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'style'      => sanitize_key( $new_instance['style'] ?? 'horizontal' ),
			'show_flags' => ! empty( $new_instance['show_flags'] ),
		);
	}

	/**
	 * Shortcode handler: [leadwerk_language_switcher style="horizontal" show_flags="1"]
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_handler( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'      => 'horizontal',
				'show_flags' => '0',
			),
			$atts,
			'leadwerk_language_switcher'
		);

		return self::render( array(
			'style'      => $atts['style'],
			'show_flags' => '1' === $atts['show_flags'],
		) );
	}

	/**
	 * Render the language switcher HTML.
	 *
	 * @param array<string,mixed> $options Rendering options.
	 * @return string
	 */
	public static function render( $options = array() ) {
		$style      = sanitize_key( $options['style'] ?? 'horizontal' );
		$show_flags = ! empty( $options['show_flags'] );
		$current    = Leadwerk_Translation_API::get_current_request_language();
		$languages  = Leadwerk_Translation_API::get_active_languages();

		/* Get alternate URLs for the current page */
		$alternates = array();
		if ( class_exists( 'Leadwerk_Translation_Router' ) && method_exists( 'Leadwerk_Translation_Router', 'get_current_page_alternate_urls' ) ) {
			$alternates = Leadwerk_Translation_Router::get_current_page_alternate_urls();
		}

		$html = '<nav class="lang-switcher-widget lang-switcher-widget--' . esc_attr( $style ) . '" role="navigation" aria-label="' . esc_attr__( 'Language', 'leadwerk-wpml-clone' ) . '">';

		if ( 'dropdown' === $style ) {
			$html .= '<div class="lang-switcher-widget__toggle">' . esc_html( strtoupper( $current ) ) . ' ▾</div>';
			$html .= '<div class="lang-switcher-widget__list">';
		}

		foreach ( $languages as $code => $config ) {
			$url = isset( $alternates[ $code ] ) ? $alternates[ $code ] : '';
			if ( ! $url ) {
				$url = $code === Leadwerk_Translation_API::get_default_language()
					? home_url( '/' )
					: home_url( '/' . $code . '/' );
			}

			$active_class = $code === $current ? ' lang-switcher-widget__item--active' : '';
			$label        = $show_flags ? strtoupper( $code ) . ' ' . $config['label'] : $config['label'];

			$html .= '<a class="lang-switcher-widget__item' . esc_attr( $active_class ) . '" href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $code ) . '">';
			$html .= esc_html( $label );
			$html .= '</a>';
		}

		if ( 'dropdown' === $style ) {
			$html .= '</div>';
		}

		$html .= '</nav>';

		return $html;
	}
}

/**
 * Template function for themes to render the language switcher.
 *
 * @param array<string,mixed> $options Options for rendering.
 * @return void
 */
function leadwerk_language_switcher( $options = array() ) {
	echo Leadwerk_Language_Switcher::render( $options );
}
