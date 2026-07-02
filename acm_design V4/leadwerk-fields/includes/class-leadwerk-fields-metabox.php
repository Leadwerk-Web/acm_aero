<?php
/**
 * Leadwerk Fields metabox UI.
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Fields_Metabox {

	/**
	 * Options page sections (title + fields).
	 *
	 * @var array<int,array{title:string,description?:string,fields:array<string,array<string,mixed>>}>
	 */
	private static $options_sections = array(
		array(
			'title' => 'Header',
			'description' => 'Logo fuer die Kopfzeile (Exact-Shells und Fallback). Texte wie CTA-Button, Logo-Alt und ARIA stehen in den Theme Strings (JSON) unten.',
			'fields'  => array(
				'header_logo' => array( 'label' => 'Header-Logo', 'type' => 'image' ),
			),
		),
		array(
			'title' => 'Footer',
			'description' => 'Logos, Social-Links, optional IS-BAO-Badge und CAMO-Link. Ueberschriften, Tagline, Copyright und AGB-Bezeichnung ueber Theme Strings. AGB im Footer: Entweder leadwerk_source_key (z. B. acm-agb-v1 auf DE/EN-Seiten) oder eine WordPress-Seiten-ID aus dem Uebersetzungspaar setzen — dann URL und Titel pro Sprache; sonst nur statische AGB-URL.',
			'fields'  => array(
				'footer_logo'                => array( 'label' => 'Footer-Logo', 'type' => 'image' ),
				'footer_wordmark'            => array( 'label' => 'Footer-Schriftzug (Fallback-Theme)', 'type' => 'image' ),
				'footer_agb_source_key'      => array( 'label' => 'AGB: leadwerk_source_key (optional)', 'type' => 'text', 'help' => 'Z. B. acm-agb-v1 — gleicher Meta-Wert auf DE- und EN-Seite. Schlaegt die statische AGB-URL.' ),
				'footer_agb_page_id'         => array( 'label' => 'AGB: Seiten-ID (optional)', 'type' => 'text', 'help' => 'ID der DE- oder EN-AGB-Seite (Leadwerk-Uebersetzungspaar). Schlaegt source_key und statische URL.' ),
				'footer_agb_url'             => array( 'label' => 'AGB-Link (optional, Fallback extern)', 'type' => 'url' ),
				'footer_social_linkedin_url' => array( 'label' => 'Social: LinkedIn URL', 'type' => 'url' ),
				'footer_social_instagram_url' => array( 'label' => 'Social: Instagram URL', 'type' => 'url' ),
				'footer_is_bao_badge'        => array( 'label' => 'IS-BAO-Badge Bild (optional)', 'type' => 'image' ),
				'footer_camo_url'            => array( 'label' => 'Services-Spalte: CAMO-Link (optional)', 'type' => 'url' ),
			),
		),
		array(
			'title' => 'Globale Kontaktdaten',
			'fields'  => array(
				'company_address' => array( 'label' => 'Adresse', 'type' => 'textarea' ),
				'company_phone'   => array( 'label' => 'Telefon', 'type' => 'text' ),
				'company_email'   => array( 'label' => 'E-Mail', 'type' => 'text' ),
				'google_maps_url' => array( 'label' => 'Google Maps URL', 'type' => 'url' ),
			),
		),
		array(
			'title' => 'Formulare',
			'fields'  => array(
				'wpforms_form_id_de' => array( 'label' => 'WPForms Form ID / Shortcode (DE)', 'type' => 'text' ),
				'wpforms_form_id_en' => array( 'label' => 'WPForms Form ID / Shortcode (EN)', 'type' => 'text' ),
			),
		),
		array(
			'title'       => 'Starlink Modal',
			'description' => 'Inhalte fuer das Starlink-Reseller-Popup (erscheint auf der Startseite ab 20% Scroll). Bild und Texte ueberschreiben den Theme-Default.',
			'fields'      => array(
				'starlink_modal_image'     => array( 'label' => 'Bild', 'type' => 'image' ),
				'starlink_modal_image_alt' => array( 'label' => 'Bild Alt-Text', 'type' => 'text' ),
				'starlink_modal_title'     => array( 'label' => 'Titel (H2)', 'type' => 'text' ),
				'starlink_modal_badge'     => array( 'label' => 'Badge-Text', 'type' => 'text' ),
				'starlink_modal_headline'  => array( 'label' => 'Headline', 'type' => 'text' ),
				'starlink_modal_teaser'    => array( 'label' => 'Teaser-Text', 'type' => 'textarea' ),
				'starlink_modal_cta_label' => array( 'label' => 'CTA-Button Text', 'type' => 'text' ),
				'starlink_modal_cta_url'   => array(
					'label' => 'CTA-Button Link (optional Override)',
					'type'  => 'url',
					'help'  => 'Leer = Standard: Kontaktseite #maintenance.',
				),
			),
		),
		array(
			'title'       => 'Uebersetzungen (Theme Strings)',
			'description' => 'JSON mit Schluessel/Wert. Header/Footer u.a.: header_contact_cta_label, header_logo_alt, header_logo_link_aria_label, footer_tagline, footer_copyright, footer_legal_heading, footer_social_heading, footer_agb_link_label, footer_camo_link_label, footer_services_heading, footer_company_heading, footer_contact_heading, footer_phone_prefix, footer_desc_home, footer_desc_general, footer_desc_legal, header_language_* , services_menu_label.',
			'fields'      => array(
				'theme_strings_de' => array( 'label' => 'Theme Strings JSON (DE)', 'type' => 'textarea' ),
				'theme_strings_en' => array( 'label' => 'Theme Strings JSON (EN)', 'type' => 'textarea' ),
			),
		),
	);

	/**
	 * Flat map of all option field definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_options_fields_flat() {
		$out = array();
		foreach ( self::$options_sections as $section ) {
			foreach ( $section['fields'] as $key => $def ) {
				$out[ $key ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Load field value via global get_field() when present (ACF or Leadwerk shim), else JSON meta API.
	 *
	 * @param string          $name    Field name.
	 * @param int|string|null $post_id Post ID or "option".
	 * @return mixed
	 */
	private static function storage_get_field( $name, $post_id = null ) {
		// Leadwerk-Optionen immer ueber die API (leadwerk_opt_*), nie ACF — sonst zerstoert ACF/keses
		// Tailwind-Arbitrary-Klassen wie z-[100] und Roh-HTML (Modals) beim Speichern.
		if ( 'option' === $post_id || 'options' === $post_id ) {
			return Leadwerk_Fields_API::get_field( $name, $post_id );
		}
		if ( function_exists( 'get_field' ) ) {
			return get_field( $name, $post_id );
		}
		return Leadwerk_Fields_API::get_field( $name, $post_id );
	}

	/**
	 * Persist field via global update_field() when present, else Leadwerk_Fields_API.
	 *
	 * @param string          $name    Field name.
	 * @param mixed           $value   Value.
	 * @param int|string|null $post_id Post ID or "option".
	 */
	private static function storage_update_field( $name, $value, $post_id = null ) {
		if ( 'option' === $post_id || 'options' === $post_id ) {
			Leadwerk_Fields_API::update_field( $name, $value, $post_id );
			return;
		}
		if ( function_exists( 'update_field' ) ) {
			update_field( $name, $value, $post_id );
			return;
		}
		Leadwerk_Fields_API::update_field( $name, $value, $post_id );
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metaboxes' ), 10, 2 );
		add_action( 'save_post_page', array( __CLASS__, 'save_sections' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'register_options_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'maybe_disable_block_editor' ), 10, 2 );
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'filter_tiny_mce_for_leadwerk_editors' ), 10, 2 );
	}

	/**
	 * Preserve layout HTML (div/p/section with class/style) in Leadwerk metabox TinyMCE instances.
	 *
	 * Editor IDs come from sanitize_title( $field_name ) or explicit leadwerk_opt_* — all start with "leadwerk".
	 *
	 * @param array<string,mixed> $init      TinyMCE init array.
	 * @param string              $editor_id Editor instance ID.
	 * @return array<string,mixed>
	 */
	public static function filter_tiny_mce_for_leadwerk_editors( $init, $editor_id ) {
		if ( 0 !== strpos( (string) $editor_id, 'leadwerk' ) ) {
			return $init;
		}

		$append = 'div[id|class|style|align|role|dir|lang],section[id|class|style|align|role|dir|lang],article[id|class|style|align|role|dir|lang],header[id|class|style|align|role|dir|lang],footer[id|class|style|align|role|dir|lang],main[id|class|style|align|role|dir|lang],aside[id|class|style|align|role|dir|lang],p[id|class|style|align|dir|lang],span[id|class|style|align|dir|lang],h1[id|class|style|align|dir|lang],h2[id|class|style|align|dir|lang],h3[id|class|style|align|dir|lang],h4[id|class|style|align|dir|lang],h5[id|class|style|align|dir|lang],h6[id|class|style|align|dir|lang]';

		if ( ! empty( $init['extended_valid_elements'] ) ) {
			$init['extended_valid_elements'] .= ',' . $append;
		} else {
			$init['extended_valid_elements'] = $append;
		}

		return $init;
	}

	public static function maybe_disable_block_editor( $use_block_editor, $post ) {
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return $use_block_editor;
		}

		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		if ( $group ) {
			return false;
		}

		return $use_block_editor;
	}

	public static function enqueue_admin_assets( $hook ) {
		$screens = array( 'post.php', 'post-new.php', 'toplevel_page_leadwerk-options' );
		$found   = false;
		$is_options_page = false;

		foreach ( $screens as $screen ) {
			if ( false !== strpos( $hook, $screen ) || $hook === $screen ) {
				$found = true;
				$is_options_page = 'toplevel_page_leadwerk-options' === $screen;
				break;
			}
		}

		if ( ! $found ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'jquery' );
		$load_media = ! $is_options_page || ! empty( $_GET['leadwerk_media'] );
		if ( $load_media ) {
			wp_enqueue_media();
			wp_add_inline_script( 'media-editor', self::get_inline_js() );
		} else {
			wp_add_inline_script( 'jquery', self::get_inline_js() );
		}
		wp_add_inline_style( 'wp-admin', self::get_inline_css() );
	}

	public static function register_metaboxes( $post_type, $post ) {
		if ( 'page' !== $post_type || ! $post ) {
			return;
		}

		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		if ( ! $group ) {
			return;
		}

		remove_post_type_support( 'page', 'editor' );

		add_meta_box(
			'leadwerk_page_sections',
			esc_html( $group['label'] ),
			array( __CLASS__, 'render_sections_metabox' ),
			'page',
			'normal',
			'high'
		);
	}

	public static function render_sections_metabox( $post ) {
		$group = Leadwerk_Content_Schema::get_group_for_post( $post );
		if ( ! $group ) {
			return;
		}

		$field_name = $group['field_name'];

		wp_nonce_field( 'leadwerk_save_sections', 'leadwerk_sections_nonce' );

		echo '<input type="hidden" name="leadwerk_sections_field_name" value="' . esc_attr( $field_name ) . '">';
		echo '<div class="leadwerk-metabox">';
		echo '<p class="description">' . esc_html( $group['description'] ) . '</p>';
		echo '<p class="description"><strong>Hinweis:</strong> Diese Seite wird ueber Leadwerk Fields gepflegt. Der normale Seiteninhalt ist kein Bearbeitungsbereich.</p>';

		if ( empty( $group['layouts'] ) ) {
			$values = self::storage_get_field( $field_name, $post->ID );
			$values = is_array( $values ) ? $values : array();

			echo '<div class="leadwerk-section-box">';
			echo '<div class="leadwerk-section-fields" style="display:block;">';

			foreach ( $group['fields'] as $field_key => $definition ) {
				$value = $values[ $field_key ] ?? Leadwerk_Content_Schema::get_default_value( $definition );
				self::render_field( "leadwerk_group[{$field_key}]", $definition, $value );
			}

			echo '</div>';
			echo '</div>';
			echo '</div>';
			return;
		}

		$sections = self::storage_get_field( $field_name, $post->ID );
		$sections = is_array( $sections ) ? array_values( $sections ) : array();

		if ( empty( $sections ) ) {
			echo '<p><em>Keine Sektionen vorhanden. Bitte zuerst den Leadwerk-Import ausfuehren.</em></p>';
			echo '</div>';
			return;
		}

		foreach ( $sections as $index => $section ) {
			$layout = isset( $section['acf_fc_layout'] ) ? sanitize_key( $section['acf_fc_layout'] ) : '';
			$schema = Leadwerk_Content_Schema::get_layout( $field_name, $layout );
			$label  = $schema['label'] ?? ucfirst( $layout );

			echo '<div class="leadwerk-section-box">';
			echo '<h3 class="leadwerk-section-title">';
			echo '<span class="leadwerk-section-number">' . (int) ( $index + 1 ) . '</span> ';
			echo esc_html( $label ) . ' <code>[' . esc_html( $layout ) . ']</code>';
			echo '</h3>';
			echo '<div class="leadwerk-section-fields">';
			echo '<input type="hidden" name="leadwerk_sections[' . esc_attr( (string) $index ) . '][acf_fc_layout]" value="' . esc_attr( $layout ) . '">';

			if ( $schema ) {
				foreach ( $schema['fields'] as $field_key => $definition ) {
					$value = $section[ $field_key ] ?? Leadwerk_Content_Schema::get_default_value( $definition );
					self::render_field( "leadwerk_sections[{$index}][{$field_key}]", $definition, $value );
				}
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	public static function save_sections( $post_id, $post ) {
		if ( ! isset( $_POST['leadwerk_sections_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['leadwerk_sections_nonce'] ), 'leadwerk_save_sections' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$field_name = isset( $_POST['leadwerk_sections_field_name'] ) ? sanitize_key( wp_unslash( $_POST['leadwerk_sections_field_name'] ) ) : '';
		$group      = Leadwerk_Content_Schema::get_group( $field_name );
		if ( ! $group ) {
			return;
		}

		if ( empty( $group['layouts'] ) ) {
			$raw = $_POST['leadwerk_group'] ?? null;
			if ( ! is_array( $raw ) ) {
				return;
			}

			$values = array();
			foreach ( $group['fields'] as $field_key => $definition ) {
				$values[ $field_key ] = self::sanitize_field_value( $raw[ $field_key ] ?? null, $definition );
			}

			self::storage_update_field( $field_name, $values, $post_id );
			self::sync_post_content_if_needed( $post_id, $group, $values );
			return;
		}

		$raw = $_POST['leadwerk_sections'] ?? null;

		if ( ! is_array( $raw ) ) {
			return;
		}

		$sections = array();

		foreach ( $raw as $section_raw ) {
			if ( ! is_array( $section_raw ) ) {
				continue;
			}

			$layout = isset( $section_raw['acf_fc_layout'] ) ? sanitize_key( wp_unslash( $section_raw['acf_fc_layout'] ) ) : '';
			$schema = Leadwerk_Content_Schema::get_layout( $field_name, $layout );

			if ( ! $schema ) {
				continue;
			}

			$section                  = array();
			$section['acf_fc_layout'] = $layout;

			foreach ( $schema['fields'] as $field_key => $definition ) {
				$section[ $field_key ] = self::sanitize_field_value( $section_raw[ $field_key ] ?? null, $definition );
			}

			$sections[] = $section;
		}

		self::storage_update_field( $field_name, $sections, $post_id );
		self::sync_post_content_if_needed( $post_id, $group, $sections );
	}

	public static function register_options_page() {
		add_menu_page(
			__( 'Leadwerk Optionen', 'leadwerk-fields' ),
			__( 'Leadwerk Optionen', 'leadwerk-fields' ),
			'manage_options',
			'leadwerk-options',
			array( __CLASS__, 'render_options_page' ),
			'dashicons-store',
			80
		);
	}

	public static function render_options_page() {
		if ( isset( $_POST['leadwerk_options_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['leadwerk_options_nonce'] ), 'leadwerk_save_options' ) ) {
			self::save_options();
			echo '<div class="notice notice-success"><p>Optionen gespeichert.</p></div>';
		}
		$show_theme_strings = ! empty( $_GET['leadwerk_show_strings'] );
		$media_loaded       = ! empty( $_GET['leadwerk_media'] );
		$page_url           = menu_page_url( 'leadwerk-options', false );
		$media_url          = add_query_arg(
			array_filter(
				array(
					'leadwerk_media'        => '1',
					'leadwerk_show_strings' => $show_theme_strings ? '1' : '',
				)
			),
			$page_url
		);
		$theme_strings_url  = add_query_arg(
			array_filter(
				array(
					'leadwerk_show_strings' => '1',
					'leadwerk_media'        => $media_loaded ? '1' : '',
				)
			),
			$page_url
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Leadwerk Optionen', 'leadwerk-fields' ); ?></h1>
			<?php if ( ! $media_loaded ) : ?>
			<div class="notice notice-info inline leadwerk-options-lazy-notice">
				<p>
					<?php esc_html_e( 'Schnellmodus aktiv: Die WordPress-Mediathek wird erst geladen, wenn sie gebraucht wird.', 'leadwerk-fields' ); ?>
					<a class="button button-small" href="<?php echo esc_url( $media_url ); ?>"><?php esc_html_e( 'Medienauswahl laden', 'leadwerk-fields' ); ?></a>
				</p>
			</div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'leadwerk_save_options', 'leadwerk_options_nonce' ); ?>
				<?php foreach ( self::$options_sections as $section ) : ?>
				<h2 class="leadwerk-options-section-title"><?php echo esc_html( $section['title'] ); ?></h2>
				<?php if ( ! empty( $section['description'] ) ) : ?>
				<p class="description"><?php echo esc_html( $section['description'] ); ?></p>
				<?php endif; ?>
				<?php if ( self::is_lazy_options_section( $section ) && ! $show_theme_strings ) : ?>
				<div class="leadwerk-options-lazy-section">
					<p><?php esc_html_e( 'Dieser Bereich enthaelt grosse JSON-Felder und wird fuer einen schnelleren Seitenaufruf nicht automatisch geladen.', 'leadwerk-fields' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( $theme_strings_url ); ?>"><?php esc_html_e( 'Theme Strings bearbeiten', 'leadwerk-fields' ); ?></a></p>
				</div>
				<?php continue; ?>
				<?php endif; ?>
				<table class="form-table leadwerk-options-table">
					<?php foreach ( $section['fields'] as $key => $definition ) : ?>
					<tr>
						<th scope="row"><label for="leadwerk_opt_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $definition['label'] ); ?></label></th>
						<td><?php
						$value = self::storage_get_field( $key, 'option' );
						self::render_field( 'leadwerk_opt_' . $key, $definition, $value, 'leadwerk_opt_' . $key );
						if ( ! empty( $definition['help'] ) ) {
							echo '<p class="description">' . esc_html( (string) $definition['help'] ) . '</p>';
						}
						?></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<?php endforeach; ?>
				<?php submit_button( __( 'Optionen speichern', 'leadwerk-fields' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function is_lazy_options_section( $section ) {
		foreach ( (array) ( $section['fields'] ?? array() ) as $key => $definition ) {
			if ( 0 === strpos( (string) $key, 'theme_strings_' ) || ! empty( $definition['lazy'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function save_options() {
		foreach ( self::get_options_fields_flat() as $key => $definition ) {
			$form_key = 'leadwerk_opt_' . $key;
			if ( array_key_exists( $form_key, $_POST ) ) {
				self::storage_update_field( $key, self::sanitize_field_value( $_POST[ $form_key ], $definition ), 'option' );
			}
		}
	}

	private static function render_field( $name, $definition, $value, $id = '' ) {
		$type  = $definition['type'] ?? 'text';
		$label = $definition['label'] ?? $name;
		$id    = $id ?: sanitize_title( $name );

		echo '<div class="leadwerk-field leadwerk-field-' . esc_attr( $type ) . '">';

		if ( 'checkbox' !== $type ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}

		switch ( $type ) {
			case 'text':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text">';
				break;

			case 'url':
				// type="text": Browser-Validierung verbietet #anker, tel:, relative Pfade — WP sanitized dennoch mit esc_url_raw / Anker-Regel.
				echo '<input type="text" inputmode="url" autocomplete="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" placeholder="https://… oder #bereich">';
				break;

			case 'textarea':
			case 'wysiwyg':
			case 'svg_code':
				$rows = 'svg_code' === $type ? 8 : 4;
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) $rows ) . '" class="large-text' . ( 'svg_code' === $type ? ' code' : '' ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
				break;

			case 'html':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="18" class="large-text code">' . esc_textarea( (string) $value ) . '</textarea>';
				echo '<p class="description">Raw HTML der statischen Sektion.</p>';
				break;

			case 'classic_editor':
				wp_editor(
					(string) $value,
					$id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => 18,
						'media_buttons' => false,
						'teeny'         => false,
						'quicktags'     => true,
						'wpautop'       => false,
						// Avoid wrapping root <div> shells in <p> and reduce block splits that drop class/style on <p>.
						'tinymce'       => array(
							'wpautop'           => false,
							'forced_root_block' => false,
						),
					)
				);
				break;

			case 'heading_html':
				wp_editor(
					(string) $value,
					$id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => 10,
						'media_buttons' => false,
						'teeny'         => false,
						'quicktags'     => true,
						'tinymce'       => true,
					)
				);
				echo '<p class="description">Nur Inline-Markup verwenden. Aussenliegende Absatz-Wrapper werden beim Speichern entfernt.</p>';
				break;

			case 'select_options':
				$text_value = '';
				if ( is_array( $value ) ) {
					$text_value = implode( "\n", array_map( 'strval', $value ) );
				}
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text code">' . esc_textarea( $text_value ) . '</textarea>';
				echo '<p class="description">Eine Option pro Zeile.</p>';
				break;

			case 'checkbox':
				echo '<label class="leadwerk-checkbox">';
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . '>';
				echo '<span>' . esc_html( $label ) . '</span>';
				echo '</label>';
				break;

			case 'image':
				$img_id  = is_numeric( $value ) ? (int) $value : 0;
				$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
				echo '<div class="leadwerk-image-field" data-target="' . esc_attr( $id ) . '">';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $img_id ) . '">';
				echo '<div class="leadwerk-image-preview">';
				if ( $img_url ) {
					echo '<img src="' . esc_url( $img_url ) . '" alt="" style="max-width:150px;height:auto;">';
				}
				echo '</div>';
				echo '<button type="button" class="button leadwerk-image-select">Bild waehlen</button> ';
				echo '<button type="button" class="button leadwerk-image-remove">Entfernen</button>';
				echo '</div>';
				break;

			case 'video':
				$vid_id   = is_numeric( $value ) ? (int) $value : 0;
				$legacy   = ( ! $vid_id && is_string( $value ) ) ? trim( $value ) : '';
				$scalar   = is_scalar( $value ) ? (string) $value : '';
				$vid_url  = $vid_id ? wp_get_attachment_url( $vid_id ) : '';
				echo '<div class="leadwerk-video-field" data-target="' . esc_attr( $id ) . '">';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $scalar ) . '" class="leadwerk-video-input">';
				echo '<div class="leadwerk-video-preview">';
				if ( $vid_url ) {
					echo '<p class="description"><a href="' . esc_url( $vid_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_basename( $vid_url ) ) . '</a> <span class="description">(Anhang-ID ' . (int) $vid_id . ')</span></p>';
				} elseif ( '' !== $legacy ) {
					echo '<p class="description">' . esc_html__( 'Hinweis: Alter Pfad aus Import — bitte Video aus der Mediathek waehlen, um eine Anhang-ID zu setzen.', 'leadwerk-fields' ) . '</p>';
					echo '<p><code>' . esc_html( $legacy ) . '</code></p>';
				}
				echo '</div>';
				echo '<button type="button" class="button leadwerk-video-select">' . esc_html__( 'Video waehlen', 'leadwerk-fields' ) . '</button> ';
				echo '<button type="button" class="button leadwerk-video-remove">' . esc_html__( 'Entfernen', 'leadwerk-fields' ) . '</button>';
				echo '</div>';
				break;

			case 'file':
				$mime     = isset( $definition['mime'] ) ? (string) $definition['mime'] : 'application/pdf';
				$file_id  = is_numeric( $value ) ? (int) $value : 0;
				$legacy   = ( ! $file_id && is_string( $value ) ) ? trim( $value ) : '';
				$scalar   = is_scalar( $value ) ? (string) $value : '';
				$file_url = $file_id ? wp_get_attachment_url( $file_id ) : '';
				echo '<div class="leadwerk-file-field" data-target="' . esc_attr( $id ) . '" data-mime="' . esc_attr( $mime ) . '">';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $scalar ) . '" class="leadwerk-file-input">';
				echo '<div class="leadwerk-file-preview">';
				if ( $file_url ) {
					echo '<p class="description"><a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_basename( $file_url ) ) . '</a> <span class="description">(Anhang-ID ' . (int) $file_id . ')</span></p>';
				} elseif ( '' !== $legacy ) {
					echo '<p class="description">' . esc_html__( 'Hinweis: Alter Wert aus Import — bitte Datei aus der Mediathek waehlen, um eine Anhang-ID zu setzen.', 'leadwerk-fields' ) . '</p>';
					echo '<p><code>' . esc_html( $legacy ) . '</code></p>';
				}
				echo '</div>';
				echo '<button type="button" class="button leadwerk-file-select">' . esc_html__( 'PDF waehlen', 'leadwerk-fields' ) . '</button> ';
				echo '<button type="button" class="button leadwerk-file-remove">' . esc_html__( 'Entfernen', 'leadwerk-fields' ) . '</button>';
				echo '</div>';
				break;

			case 'repeater':
				self::render_repeater_field( $name, $definition, $value, $id );
				break;
		}

		echo '</div>';
	}

	private static function render_repeater_field( $name, $definition, $value, $id ) {
		$items     = is_array( $value ) ? array_values( $value ) : array();
		$add_label = $definition['add_button_label'] ?? 'Eintrag hinzufuegen';

		echo '<div class="leadwerk-repeater" id="' . esc_attr( $id ) . '" data-next-index="' . esc_attr( (string) count( $items ) ) . '">';
		if ( ! empty( $definition['top_add_bar'] ) ) {
			echo '<div class="leadwerk-repeater-add-bar">';
			echo '<button type="button" class="button button-primary button-large leadwerk-repeater-add leadwerk-repeater-add-plus" title="' . esc_attr( $add_label ) . '" aria-label="' . esc_attr( $add_label ) . '">';
			echo '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ';
			echo '<span class="leadwerk-repeater-add-plus-label">' . esc_html( $add_label ) . '</span>';
			echo '</button>';
			echo '</div>';
		}
		echo '<div class="leadwerk-repeater-items">';

		foreach ( $items as $index => $item ) {
			echo self::get_repeater_item_markup( $name, $definition, (int) $index, is_array( $item ) ? $item : array() );
		}

		echo '</div>';
		echo '<button type="button" class="button leadwerk-repeater-add leadwerk-repeater-add-bottom">' . esc_html( $add_label ) . '</button>';
		echo '<template class="leadwerk-repeater-template">' . self::get_repeater_item_markup( $name, $definition, '__INDEX__', array() ) . '</template>';
		echo '</div>';
	}

	private static function get_repeater_item_markup( $name, $definition, $index, $item ) {
		ob_start();
		?>
		<div class="leadwerk-repeater-item">
			<div class="leadwerk-repeater-item-header">
				<strong class="leadwerk-repeater-item-title">Eintrag</strong>
				<div class="leadwerk-repeater-item-actions">
					<button type="button" class="button button-small leadwerk-repeater-move-up">Nach oben</button>
					<button type="button" class="button button-small leadwerk-repeater-move-down">Nach unten</button>
					<button type="button" class="button button-small leadwerk-repeater-remove">Entfernen</button>
				</div>
			</div>
			<div class="leadwerk-repeater-item-fields">
				<?php
				foreach ( $definition['fields'] as $sub_key => $sub_definition ) {
					$sub_value = $item[ $sub_key ] ?? Leadwerk_Content_Schema::get_default_value( $sub_definition );
					self::render_field( $name . '[' . $index . '][' . $sub_key . ']', $sub_definition, $sub_value );
				}
				?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	private static function sanitize_field_value( $value, $definition ) {
		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
				return sanitize_text_field( is_null( $value ) ? '' : wp_unslash( $value ) );

			case 'url':
				$raw = trim( (string) wp_unslash( is_null( $value ) ? '' : $value ) );
				if ( '' === $raw ) {
					return '';
				}
				// Gleiche Seite / Anker (kein gueltiger URL-Typ fuer esc_url_raw).
				if ( preg_match( '/^#[^\s#]*$/u', $raw ) ) {
					return sanitize_text_field( $raw );
				}
				return esc_url_raw( $raw );

			case 'textarea':
				return sanitize_textarea_field( is_null( $value ) ? '' : wp_unslash( $value ) );

			case 'wysiwyg':
			case 'classic_editor':
				return wp_kses_post( is_null( $value ) ? '' : wp_unslash( $value ) );

			case 'heading_html':
				return Leadwerk_Content_Schema::sanitize_heading_html( is_null( $value ) ? '' : wp_unslash( $value ) );

			case 'html':
				return is_null( $value ) ? '' : (string) wp_unslash( $value );

			case 'svg_code':
				return trim( (string) wp_unslash( is_null( $value ) ? '' : $value ) );

			case 'image':
				return absint( $value );

			case 'video':
				$raw = wp_unslash( is_null( $value ) ? '' : $value );
				if ( is_numeric( $raw ) ) {
					return absint( $raw );
				}
				return sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );

			case 'file':
				$raw = wp_unslash( is_null( $value ) ? '' : $value );
				if ( is_numeric( $raw ) ) {
					return absint( $raw );
				}
				return sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );

			case 'checkbox':
				return ! empty( $value );

			case 'select_options':
				$raw = is_null( $value ) ? '' : wp_unslash( $value );
				$raw = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
				$raw = is_array( $raw ) ? $raw : array();
				$out = array();

				foreach ( $raw as $line ) {
					$line = sanitize_text_field( (string) $line );
					if ( '' !== $line ) {
						$out[] = $line;
					}
				}

				return $out;

			case 'repeater':
				$rows = is_array( $value ) ? $value : array();
				$out  = array();

				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$item = array();
					foreach ( $definition['fields'] as $sub_key => $sub_definition ) {
						$item[ $sub_key ] = self::sanitize_field_value( $row[ $sub_key ] ?? null, $sub_definition );
					}
					$out[] = $item;
				}

				return $out;
		}

		return sanitize_text_field( is_null( $value ) ? '' : wp_unslash( $value ) );
	}

	private static function sync_post_content_if_needed( $post_id, $group, $value ) {
		if ( empty( $group['sync_post_content'] ) || ! is_array( $value ) ) {
			return;
		}

		$post_content = self::build_legal_page_content( $value );
		remove_action( 'save_post_page', array( __CLASS__, 'save_sections' ), 10 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $post_content,
			)
		);
		add_action( 'save_post_page', array( __CLASS__, 'save_sections' ), 10, 2 );
	}

	private static function build_legal_page_content( $value ) {
		$headline = trim( (string) ( $value['headline'] ?? '' ) );
		$content  = (string) ( $value['content'] ?? '' );

		return sprintf(
			'<section class="content-section content-section--white legal-content"><div class="container"><h1>%1$s</h1><div class="legal-copy">%2$s</div></div></section>',
			esc_html( $headline ),
			$content
		);
	}

	private static function get_inline_css() {
		return '
.leadwerk-metabox { max-width: 100%; }
.leadwerk-section-box { background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; margin: 12px 0; overflow: hidden; }
.leadwerk-section-title { margin: 0; padding: 12px 16px; background: #e9e9e9; border-bottom: 1px solid #ddd; font-size: 14px; font-weight: 600; cursor: pointer; }
.leadwerk-section-title:hover { background: #ddd; }
.leadwerk-section-number { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: #fff; border-radius: 50%; font-size: 12px; margin-right: 6px; }
.leadwerk-section-fields { padding: 12px 16px; }
.leadwerk-field { margin-bottom: 14px; }
.leadwerk-field > label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
.leadwerk-checkbox { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
.leadwerk-image-preview { margin: 6px 0; }
.leadwerk-image-preview img { border: 1px solid #ddd; border-radius: 4px; }
.leadwerk-video-preview { margin: 6px 0; }
.leadwerk-file-preview { margin: 6px 0; }
.leadwerk-repeater { border: 1px solid #d0d7de; background: #fff; border-radius: 6px; padding: 10px; }
.leadwerk-repeater-add-bar { margin-bottom: 12px; }
.leadwerk-repeater-add-plus .dashicons { vertical-align: middle; margin-top: -3px; width: 20px; height: 20px; font-size: 20px; line-height: 1; }
.leadwerk-repeater-add-plus .leadwerk-repeater-add-plus-label { vertical-align: middle; }
.leadwerk-repeater-add-bottom { margin-top: 4px; }
.leadwerk-repeater-items { display: grid; gap: 12px; margin-bottom: 10px; }
.leadwerk-repeater-item { border: 1px solid #d0d7de; border-radius: 6px; background: #fafafa; }
.leadwerk-repeater-item-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; background: #f3f4f6; }
.leadwerk-repeater-item-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.leadwerk-repeater-item-fields { padding: 12px; }
.leadwerk-field-classic_editor .wp-editor-wrap,
.leadwerk-field-heading_html .wp-editor-wrap { max-width: 100%; }
.leadwerk-field-classic_editor .wp-editor-area { min-height: 320px; }
.leadwerk-field-heading_html .wp-editor-area { min-height: 180px; }
.leadwerk-options-table .leadwerk-field { margin: 0; }
.leadwerk-options-table .leadwerk-field > label { display: none; }
h2.leadwerk-options-section-title { margin: 1.75em 0 0.35em; padding: 0; font-size: 1.3em; }
.wrap h2.leadwerk-options-section-title:first-of-type { margin-top: 0.5em; }
.leadwerk-options-lazy-section { max-width: 760px; padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
.leadwerk-options-lazy-section p { margin: 0 0 10px; }
.leadwerk-options-lazy-section p:last-child { margin-bottom: 0; }
.leadwerk-options-lazy-notice p { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
	';
	}

	private static function get_inline_js() {
		return "
jQuery(function($){
	function reloadWithLeadwerkParam(name, value){
		var href = window.location.href;
		try {
			var url = new URL(href);
			url.searchParams.set(name, value);
			window.location.href = url.toString();
			return;
		} catch (err) {}
		window.location.href = href + (href.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(name) + '=' + encodeURIComponent(value);
	}

	function ensureWpMedia(){
		if (window.wp && wp.media) {
			return true;
		}
		if (!window.confirm('Die Medienauswahl wird nachgeladen. Nicht gespeicherte Aenderungen auf dieser Seite gehen verloren. Fortfahren?')) {
			return false;
		}
		reloadWithLeadwerkParam('leadwerk_media', '1');
		return false;
	}

	function updateRepeaterTitles(container){
		container.find('.leadwerk-repeater-item').each(function(index){
			$(this).find('.leadwerk-repeater-item-title').text('Eintrag ' + (index + 1));
		});
	}

	$(document).on('click','.leadwerk-image-select',function(e){
		e.preventDefault();
		if (!ensureWpMedia()) return;
		var wrap = $(this).closest('.leadwerk-image-field');
		var frame = wp.media({title:'Bild waehlen',button:{text:'Auswaehlen'},multiple:false});
		frame.on('select',function(){
			var att = frame.state().get('selection').first().toJSON();
			wrap.find('input[type=hidden]').val(att.id);
			var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			wrap.find('.leadwerk-image-preview').html('<img src=\"' + thumb + '\" alt=\"\" style=\"max-width:150px;height:auto;\">');
		});
		frame.open();
	});

	$(document).on('click','.leadwerk-image-remove',function(e){
		e.preventDefault();
		var wrap = $(this).closest('.leadwerk-image-field');
		wrap.find('input[type=hidden]').val('0');
		wrap.find('.leadwerk-image-preview').html('');
	});

	$(document).on('click','.leadwerk-video-select',function(e){
		e.preventDefault();
		if (!ensureWpMedia()) return;
		var wrap = $(this).closest('.leadwerk-video-field');
		var frame = wp.media({title:'Video waehlen',button:{text:'Auswaehlen'},library:{type:'video'},multiple:false});
		frame.on('select',function(){
			var att = frame.state().get('selection').first().toJSON();
			wrap.find('input.leadwerk-video-input').val(String(att.id));
			var name = att.filename || (att.url ? att.url.split('/').pop() : '');
			var link = att.url ? '<a href=\"'+att.url+'\" target=\"_blank\" rel=\"noopener noreferrer\">'+name+'</a>' : name;
			wrap.find('.leadwerk-video-preview').html('<p class=\"description\">'+link+' <span class=\"description\">(Anhang-ID '+att.id+')</span></p>');
		});
		frame.open();
	});

	$(document).on('click','.leadwerk-video-remove',function(e){
		e.preventDefault();
		var wrap = $(this).closest('.leadwerk-video-field');
		wrap.find('input.leadwerk-video-input').val('');
		wrap.find('.leadwerk-video-preview').html('');
	});

	$(document).on('click','.leadwerk-file-select',function(e){
		e.preventDefault();
		if (!ensureWpMedia()) return;
		var wrap = $(this).closest('.leadwerk-file-field');
		var mime = wrap.attr('data-mime') || 'application/pdf';
		var frame = wp.media({title:'PDF waehlen',button:{text:'Auswaehlen'},library:{type:mime},multiple:false});
		frame.on('select',function(){
			var att = frame.state().get('selection').first().toJSON();
			if (att.mime && att.mime !== 'application/pdf' && mime === 'application/pdf') {
				window.alert('Bitte eine PDF-Datei waehlen.');
				return;
			}
			wrap.find('input.leadwerk-file-input').val(String(att.id));
			var name = att.filename || (att.url ? att.url.split('/').pop() : '');
			var link = att.url ? '<a href=\"'+att.url+'\" target=\"_blank\" rel=\"noopener noreferrer\">'+name+'</a>' : name;
			wrap.find('.leadwerk-file-preview').html('<p class=\"description\">'+link+' <span class=\"description\">(Anhang-ID '+att.id+')</span></p>');
		});
		frame.open();
	});

	$(document).on('click','.leadwerk-file-remove',function(e){
		e.preventDefault();
		var wrap = $(this).closest('.leadwerk-file-field');
		wrap.find('input.leadwerk-file-input').val('');
		wrap.find('.leadwerk-file-preview').html('');
	});

	$(document).on('click','.leadwerk-section-title',function(){
		$(this).next('.leadwerk-section-fields').slideToggle(200);
	});

	$(document).on('click','.leadwerk-repeater-add',function(e){
		e.preventDefault();
		var repeater = $(this).closest('.leadwerk-repeater');
		var nextIndex = parseInt(repeater.attr('data-next-index'), 10) || 0;
		var idxStr = String(nextIndex);
		var tplEl = repeater.find('template.leadwerk-repeater-template').get(0);
		if (tplEl && tplEl.content && document.importNode) {
			var frag = document.importNode(tplEl.content, true);
			frag.querySelectorAll('*').forEach(function(el){
				for (var a = el.attributes.length - 1; a >= 0; a--) {
					var attr = el.attributes[a];
					if (attr.value.indexOf('__INDEX__') !== -1) {
						el.setAttribute(attr.name, attr.value.replace(/__INDEX__/g, idxStr));
					}
				}
			});
			var itemsEl = repeater.find('.leadwerk-repeater-items').get(0);
			if (itemsEl) {
				itemsEl.appendChild(frag);
			}
		} else {
			var template = repeater.find('.leadwerk-repeater-template').html() || '';
			template = template.replace(/__INDEX__/g, idxStr);
			repeater.find('.leadwerk-repeater-items').append(template);
		}
		repeater.attr('data-next-index', nextIndex + 1);
		updateRepeaterTitles(repeater);
		var lastItem = repeater.find('.leadwerk-repeater-item').last().get(0);
		if (lastItem && lastItem.scrollIntoView) {
			lastItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	});

	$(document).on('click','.leadwerk-repeater-remove',function(e){
		e.preventDefault();
		var repeater = $(this).closest('.leadwerk-repeater');
		$(this).closest('.leadwerk-repeater-item').remove();
		updateRepeaterTitles(repeater);
	});

	$(document).on('click','.leadwerk-repeater-move-up',function(e){
		e.preventDefault();
		var item = $(this).closest('.leadwerk-repeater-item');
		var prev = item.prev('.leadwerk-repeater-item');
		if (prev.length) {
			item.insertBefore(prev);
			updateRepeaterTitles(item.closest('.leadwerk-repeater'));
		}
	});

	$(document).on('click','.leadwerk-repeater-move-down',function(e){
		e.preventDefault();
		var item = $(this).closest('.leadwerk-repeater-item');
		var next = item.next('.leadwerk-repeater-item');
		if (next.length) {
			item.insertAfter(next);
			updateRepeaterTitles(item.closest('.leadwerk-repeater'));
		}
	});

	$('.leadwerk-repeater').each(function(){
		updateRepeaterTitles($(this));
	});
});
";
	}
}
