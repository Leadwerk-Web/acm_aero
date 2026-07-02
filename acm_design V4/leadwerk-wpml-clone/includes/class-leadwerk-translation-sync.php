<?php
/**
 * Synchronize translated structured field values and classic translation bundles.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_Sync {

	/**
	 * Field keys that should not become translation segments.
	 *
	 * @var string[]
	 */
	protected static $non_translatable_text_keys = array(
		'anchor_id',
		'button_page_key',
		'cta_page_key',
		'field_id',
		'href',
		'icon',
		'icon_text',
		'image_position',
		'initials',
		'modifier',
		'number',
		'page_key',
		'privacy_page',
		'type',
		'variant',
	);

	/**
	 * Seed or refresh a translated post from its source record.
	 *
	 * @param int   $source_post_id     Source post ID.
	 * @param int   $translated_post_id Translated post ID.
	 * @param mixed $seed_values        Optional translated seed values.
	 * @return array<string,mixed>
	 */
	public static function sync_from_source( $source_post_id, $translated_post_id, $seed_values = null ) {
		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return array();
		}

		$source_values = self::get_group_values( $group, $source_post_id );
		$seed_lookup   = self::resolve_seed_lookup_for_pair( $group, $source_post_id, $translated_post_id, $seed_values );
		$bundle        = Leadwerk_Translation_API::get_translation_bundle( $translated_post_id );
		$bundle        = self::reconcile_bundle_with_source(
			$group,
			$source_values,
			$seed_lookup,
			$bundle,
			Leadwerk_Translation_API::get_post_language( $source_post_id ),
			Leadwerk_Translation_API::get_post_language( $translated_post_id )
		);
		$translated    = self::build_translated_value( $group, $source_values, $bundle );

		self::update_group_values( $group, $translated_post_id, $translated );
		Leadwerk_Translation_API::update_translation_bundle( $translated_post_id, $bundle );
		Leadwerk_Translation_API::update_post_translation_status( $translated_post_id, self::calculate_bundle_status( $bundle ) );
		self::sync_bundle_translation_memory( $source_post_id, $translated_post_id, $bundle );

		self::sync_acm_news_shared_from_source( $source_post_id, $translated_post_id, false );

		return $bundle;
	}

	/**
	 * Refresh every translation connected to one source post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function sync_all_from_source( $source_post_id ) {
		$source_id      = Leadwerk_Translation_API::get_source_post_id( $source_post_id );
		$source_id      = $source_id ? $source_id : (int) $source_post_id;
		$translations   = Leadwerk_Translation_API::get_translations( $source_id );
		$default_lang   = Leadwerk_Translation_API::get_default_language();
		$synced_bundles = array();

		foreach ( $translations as $lang => $translation_id ) {
			if ( $default_lang === $lang || (int) $translation_id === (int) $source_id ) {
				continue;
			}

			$synced_bundles[ $lang ] = self::sync_from_source( $source_id, (int) $translation_id, null );
		}

		return $synced_bundles;
	}

	/**
	 * Refresh one translated bundle/status from the current source without overwriting translated field values.
	 *
	 * @param int   $source_post_id     Source post ID.
	 * @param int   $translated_post_id Translated post ID.
	 * @param mixed $seed_values        Optional translated seed values.
	 * @return array<string,mixed>
	 */
	public static function refresh_bundle_from_source( $source_post_id, $translated_post_id, $seed_values = null ) {
		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return array();
		}

		$source_values = self::get_group_values( $group, $source_post_id );
		$seed_lookup   = self::resolve_seed_lookup_for_pair( $group, $source_post_id, $translated_post_id, $seed_values );
		$bundle        = Leadwerk_Translation_API::get_translation_bundle( $translated_post_id );
		$bundle        = self::reconcile_bundle_with_source(
			$group,
			$source_values,
			$seed_lookup,
			$bundle,
			Leadwerk_Translation_API::get_post_language( $source_post_id ),
			Leadwerk_Translation_API::get_post_language( $translated_post_id )
		);

		Leadwerk_Translation_API::update_translation_bundle( $translated_post_id, $bundle );
		Leadwerk_Translation_API::update_post_translation_status( $translated_post_id, self::calculate_bundle_status( $bundle ) );

		return $bundle;
	}

	/**
	 * Refresh every translation bundle/status for one source without overwriting translated field values.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function refresh_all_bundles_from_source( $source_post_id ) {
		$source_id       = Leadwerk_Translation_API::get_source_post_id( $source_post_id );
		$source_id       = $source_id ? $source_id : (int) $source_post_id;
		$translations    = Leadwerk_Translation_API::get_translations( $source_id );
		$default_lang    = Leadwerk_Translation_API::get_default_language();
		$refreshed_bundles = array();

		foreach ( $translations as $lang => $translation_id ) {
			if ( $default_lang === $lang || (int) $translation_id === (int) $source_id ) {
				continue;
			}

			$refreshed_bundles[ $lang ] = self::refresh_bundle_from_source( $source_id, (int) $translation_id, null );
		}

		return $refreshed_bundles;
	}

	/**
	 * Save translations from the classic editor.
	 *
	 * @param int                 $source_post_id     Source post ID.
	 * @param int                 $translated_post_id Translated post ID.
	 * @param array<string,mixed> $submitted          Submitted translations keyed by segment id.
	 * @return array<string,mixed>
	 */
	public static function save_translations( $source_post_id, $translated_post_id, $submitted ) {
		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return array();
		}

		$source_values = self::get_group_values( $group, $source_post_id );
		$bundle        = Leadwerk_Translation_API::get_translation_bundle( $translated_post_id );
		$bundle        = self::reconcile_bundle_with_source(
			$group,
			$source_values,
			array(),
			$bundle,
			Leadwerk_Translation_API::get_post_language( $source_post_id ),
			Leadwerk_Translation_API::get_post_language( $translated_post_id )
		);
		$submitted     = is_array( $submitted ) ? $submitted : array();

		foreach ( (array) ( $bundle['paths'] ?? array() ) as $path_key => $path_bundle ) {
			$segment_id = (string) ( $path_bundle['id'] ?? '' );
			if ( '' === $segment_id || ! array_key_exists( $segment_id, $submitted ) ) {
				continue;
			}

			$translation = (string) wp_unslash( $submitted[ $segment_id ] );
			$translation = 'text' === ( $path_bundle['type'] ?? 'text' )
				? trim( $translation )
				: trim( $translation );

			$bundle['paths'][ $path_key ]['translation']  = $translation;
			$bundle['paths'][ $path_key ]['needs_review'] = false;
			$bundle['paths'][ $path_key ]['status']       = '' === trim( $translation ) ? 'needs_translation' : 'complete';
		}

		$translated = self::build_translated_value( $group, $source_values, $bundle );

		self::update_group_values( $group, $translated_post_id, $translated );
		Leadwerk_Translation_API::update_translation_bundle( $translated_post_id, $bundle );
		Leadwerk_Translation_API::update_post_translation_status( $translated_post_id, self::calculate_bundle_status( $bundle ) );
		self::sync_bundle_translation_memory( $source_post_id, $translated_post_id, $bundle );

		return $bundle;
	}

	/**
	 * Build classic editor sections for the admin UI.
	 *
	 * @param int $source_post_id     Source post ID.
	 * @param int $translated_post_id Translated post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_editor_sections( $source_post_id, $translated_post_id ) {
		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return array();
		}

		$source_values = self::get_group_values( $group, $source_post_id );
		$bundle        = Leadwerk_Translation_API::get_translation_bundle( $translated_post_id );
		$seed_lookup   = self::resolve_seed_lookup_for_pair( $group, $source_post_id, $translated_post_id, null );
		$bundle        = self::reconcile_bundle_with_source(
			$group,
			$source_values,
			$seed_lookup,
			$bundle,
			Leadwerk_Translation_API::get_post_language( $source_post_id ),
			Leadwerk_Translation_API::get_post_language( $translated_post_id )
		);
		Leadwerk_Translation_API::update_translation_bundle( $translated_post_id, $bundle );
		Leadwerk_Translation_API::update_post_translation_status( $translated_post_id, self::calculate_bundle_status( $bundle ) );

		$sections = array();
		foreach ( (array) ( $bundle['paths'] ?? array() ) as $path_key => $path_bundle ) {
			$section_key = (string) ( $path_bundle['section_key'] ?? 'general' );
			if ( ! isset( $sections[ $section_key ] ) ) {
				$sections[ $section_key ] = array(
					'label'    => (string) ( $path_bundle['section_label'] ?? ucfirst( $section_key ) ),
					'path_key' => $section_key,
					'segments' => array(),
				);
			}

			$sections[ $section_key ]['segments'][] = array(
				'id'          => (string) ( $path_bundle['id'] ?? '' ),
				'label'       => (string) ( $path_bundle['label'] ?? $path_key ),
				'source'      => (string) ( $path_bundle['source'] ?? '' ),
				'translation' => (string) ( $path_bundle['translation'] ?? '' ),
				'status'      => self::determine_status( $path_bundle ),
				'segment_key' => $path_key,
				'type'        => (string) ( $path_bundle['type'] ?? 'text' ),
			);
		}

		return array_values( $sections );
	}

	/**
	 * Copy ACM News teaser image and shared meta from source to translation.
	 *
	 * @param int  $source_post_id     Source post (typically default language).
	 * @param int  $translated_post_id Translation target post.
	 * @param bool $aggressive         True: always set thumbnail and meta from source when source has values.
	 *                                 False: only fill thumbnail/meta on target when still empty.
	 * @return void
	 */
	public static function sync_acm_news_shared_from_source( $source_post_id, $translated_post_id, $aggressive ) {
		$source_post_id     = (int) $source_post_id;
		$translated_post_id = (int) $translated_post_id;
		if ( $source_post_id < 1 || $translated_post_id < 1 || $source_post_id === $translated_post_id ) {
			return;
		}

		$source = get_post( $source_post_id );
		$target = get_post( $translated_post_id );
		if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post ) {
			return;
		}
		if ( 'acm_news' !== $source->post_type || 'acm_news' !== $target->post_type ) {
			return;
		}

		$thumb = (int) get_post_thumbnail_id( $source_post_id );
		if ( $thumb > 0 ) {
			if ( $aggressive || ! get_post_thumbnail_id( $translated_post_id ) ) {
				set_post_thumbnail( $translated_post_id, $thumb );
			}
		}

		$meta_keys = array(
			'acm_news_filter_slug',
			'_leadwerk_news_datetime',
			'acm_news_publication_date',
			'_leadwerk_news_source_file',
		);

		foreach ( $meta_keys as $key ) {
			$val = get_post_meta( $source_post_id, $key, true );
			if ( null === $val || ( is_string( $val ) && '' === $val ) ) {
				continue;
			}
			if ( $aggressive ) {
				update_post_meta( $translated_post_id, $key, $val );
				continue;
			}
			$existing = get_post_meta( $translated_post_id, $key, true );
			if ( false === $existing || null === $existing || ( is_string( $existing ) && '' === $existing ) ) {
				update_post_meta( $translated_post_id, $key, $val );
			}
		}
	}

	/**
	 * Index or clear translation memory entries for one translated post bundle.
	 *
	 * @param int                 $source_post_id     Source post ID.
	 * @param int                 $translated_post_id Target post ID.
	 * @param array<string,mixed> $bundle             Optional bundle.
	 * @return int
	 */
	public static function sync_bundle_translation_memory( $source_post_id, $translated_post_id, $bundle = array() ) {
		$source_post_id     = (int) $source_post_id;
		$translated_post_id = (int) $translated_post_id;
		$source_lang        = sanitize_key( (string) Leadwerk_Translation_API::get_post_language( $source_post_id ) );
		$target_lang        = sanitize_key( (string) Leadwerk_Translation_API::get_post_language( $translated_post_id ) );
		if ( $source_post_id < 1 || $translated_post_id < 1 || '' === $source_lang || '' === $target_lang || $source_lang === $target_lang ) {
			return 0;
		}

		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return 0;
		}

		$source_values = self::get_group_values( $group, $source_post_id );
		$descriptors   = self::collect_leaf_descriptors( $group, $source_values );
		$bundle        = is_array( $bundle ) && ! empty( $bundle ) ? $bundle : Leadwerk_Translation_API::get_translation_bundle( $translated_post_id );
		$paths         = is_array( $bundle['paths'] ?? null ) ? $bundle['paths'] : array();
		$indexed       = 0;

		foreach ( $descriptors as $descriptor ) {
			$path_key    = (string) ( $descriptor['path_key'] ?? '' );
			$path_bundle = isset( $paths[ $path_key ] ) && is_array( $paths[ $path_key ] ) ? $paths[ $path_key ] : array();
			$row_id      = Leadwerk_Translation_API::sync_translation_memory_origin(
				$source_lang,
				$target_lang,
				(string) ( $descriptor['type'] ?? 'text' ),
				(string) ( $descriptor['source'] ?? '' ),
				(string) ( $path_bundle['translation'] ?? '' ),
				array(
					'status'      => self::determine_status( $path_bundle ),
					'origin_type' => 'post_segment',
					'origin_id'   => $translated_post_id,
					'origin_key'  => $path_key,
				)
			);

			if ( $row_id > 0 ) {
				++$indexed;
			}
		}

		return $indexed;
	}

	/**
	 * Return the normalized status for one translation bundle.
	 *
	 * @param array<string,mixed> $bundle Bundle data.
	 * @return string
	 */
	public static function get_bundle_status( $bundle ) {
		return self::calculate_bundle_status( $bundle );
	}

	/**
	 * Whether a translation bundle can be published.
	 *
	 * @param array<string,mixed> $bundle Bundle data.
	 * @return bool
	 */
	public static function can_publish_bundle( $bundle ) {
		return 'complete' === self::calculate_bundle_status( $bundle );
	}

	/**
	 * Build seed paths from structured field values.
	 *
	 * @param array<string,mixed> $group  Group definition.
	 * @param mixed               $values Source or seed values.
	 * @return array<string,mixed>
	 */
	public static function build_seed_paths_from_values( $group, $values ) {
		$paths = array();

		foreach ( self::collect_leaf_descriptors( $group, $values ) as $descriptor ) {
			$paths[ $descriptor['path_key'] ] = array(
				'type'        => $descriptor['type'],
				'translation' => (string) $descriptor['source'],
				'label'       => (string) $descriptor['label'],
				'section_key' => (string) $descriptor['section_key'],
			);
		}

		return $paths;
	}

	/**
	 * Build seed diagnostics for one source post.
	 *
	 * @param int                 $source_post_id Source post ID.
	 * @param mixed               $seed_values    Structured or legacy-normalized seed values.
	 * @param array<string,mixed> $bundle         Optional reconciled bundle.
	 * @return array<string,int|bool>
	 */
	public static function get_seed_diagnostics_for_post( $source_post_id, $seed_values = null, $bundle = array() ) {
		$group = self::resolve_translation_group( $source_post_id );
		if ( ! $group ) {
			return self::get_empty_seed_diagnostics();
		}

		return self::build_seed_diagnostics(
			$group,
			self::get_group_values( $group, $source_post_id ),
			$seed_values,
			$bundle
		);
	}

	/**
	 * Resolve a structured Leadwerk group or fall back to native post fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	protected static function resolve_translation_group( $post_id ) {
		if ( class_exists( 'Leadwerk_Content_Schema' ) && function_exists( 'get_field' ) && function_exists( 'update_field' ) ) {
			$group = Leadwerk_Content_Schema::get_group_for_post( $post_id );
			if ( $group && ! empty( $group['field_name'] ) ) {
				return $group;
			}
		}

		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return array(
			'field_name'         => '__leadwerk_native_post_fields',
			'native_post_fields' => true,
			'fields'             => array(
				'post_title'   => array( 'label' => 'Title', 'type' => 'text' ),
				'post_excerpt' => array( 'label' => 'Excerpt', 'type' => 'classic_editor' ),
				'post_content' => array( 'label' => 'Content', 'type' => 'classic_editor' ),
			),
		);
	}

	/**
	 * Read source or translated values for one translation group.
	 *
	 * @param array<string,mixed> $group   Group definition.
	 * @param int                 $post_id Post ID.
	 * @return mixed
	 */
	protected static function get_group_values( $group, $post_id ) {
		if ( ! empty( $group['native_post_fields'] ) ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				return array();
			}

			return array(
				'post_title'   => (string) $post->post_title,
				'post_excerpt' => (string) $post->post_excerpt,
				'post_content' => (string) $post->post_content,
			);
		}

		$values = get_field( $group['field_name'], $post_id );
		return self::maybe_repair_group_source_values( $group, $values, $post_id );
	}

	/**
	 * Persist translated values back to the target object.
	 *
	 * @param array<string,mixed> $group      Group definition.
	 * @param int                 $post_id    Post ID.
	 * @param mixed               $translated Translated value payload.
	 * @return void
	 */
	protected static function update_group_values( $group, $post_id, $translated ) {
		if ( ! empty( $group['native_post_fields'] ) ) {
			wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_title'   => (string) ( $translated['post_title'] ?? '' ),
					'post_excerpt' => (string) ( $translated['post_excerpt'] ?? '' ),
					'post_content' => (string) ( $translated['post_content'] ?? '' ),
				)
			);
			return;
		}

		update_field( $group['field_name'], $translated, $post_id );
	}

	/**
	 * Normalize incoming seed values into a path lookup.
	 *
	 * @param array<string,mixed> $group       Group definition.
	 * @param mixed               $seed_values Structured seed, bundle seed or translated values.
	 * @return array<string,mixed>
	 */
	protected static function normalize_seed_lookup( $group, $seed_values ) {
		if ( ! is_array( $seed_values ) || empty( $seed_values ) ) {
			return array();
		}

		if ( isset( $seed_values['paths'] ) && is_array( $seed_values['paths'] ) ) {
			return $seed_values['paths'];
		}

		return self::build_seed_paths_from_values( $group, $seed_values );
	}

	/**
	 * Resolve structured seed lookup for one source/target pair.
	 *
	 * @param array<string,mixed> $group              Group definition.
	 * @param int                 $source_post_id     Source post ID.
	 * @param int                 $translated_post_id Target post ID.
	 * @param mixed               $seed_values        Optional explicit seed values.
	 * @return array<string,mixed>
	 */
	protected static function resolve_seed_lookup_for_pair( $group, $source_post_id, $translated_post_id, $seed_values = null ) {
		if ( null !== $seed_values ) {
			return self::normalize_seed_lookup( $group, $seed_values );
		}

		$lang = sanitize_key( (string) Leadwerk_Translation_API::get_post_language( $translated_post_id ) );
		if ( '' === $lang || Leadwerk_Translation_API::get_default_language() === $lang ) {
			return array();
		}

		$source_key = sanitize_key( (string) get_post_meta( (int) $source_post_id, 'leadwerk_source_key', true ) );
		if ( '' === $source_key ) {
			return array();
		}

		$seed_manager = self::get_translation_seed_manager();
		if ( ! $seed_manager ) {
			return array();
		}

		return self::normalize_seed_lookup( $group, $seed_manager->get_page_seed( $source_key, $lang ) );
	}

	/**
	 * Lazily instantiate the FINORA translation seed manager.
	 *
	 * @return Leadwerk_Translation_Seed_Manager|null
	 */
	protected static function get_translation_seed_manager() {
		static $manager = null;
		static $loaded  = false;

		if ( $loaded ) {
			return $manager;
		}

		$loaded = true;
		if ( ! class_exists( 'Leadwerk_Translation_Seed_Manager' ) || ! defined( 'LEADWERK_IMPORTER_PATH' ) ) {
			return null;
		}

		$manifest_dir = trailingslashit( LEADWERK_IMPORTER_PATH ) . 'manifest/';
		$source_root  = trailingslashit( LEADWERK_IMPORTER_PATH ) . 'source_assets';
		$manager      = new Leadwerk_Translation_Seed_Manager( $manifest_dir, $source_root );

		return $manager;
	}

	/**
	 * Repair known source-value gaps before building classic translation bundles.
	 *
	 * @param array<string,mixed> $group   Group definition.
	 * @param mixed               $values  Stored field value.
	 * @param int                 $post_id Source post ID.
	 * @return mixed
	 */
	protected static function maybe_repair_group_source_values( $group, $values, $post_id ) {
		if ( empty( $group['field_name'] ) || ! is_array( $values ) ) {
			return $values;
		}

		$field_name = (string) $group['field_name'];
		$source_key = sanitize_key( (string) get_post_meta( (int) $post_id, 'leadwerk_source_key', true ) );

		if ( 'finora_about_sections' !== $field_name || 'acm-about-v1' !== $source_key ) {
			return $values;
		}

		$repaired = false;
		foreach ( $values as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			if ( 'bedeutet' !== (string) ( $section['acf_fc_layout'] ?? '' ) ) {
				continue;
			}

			if ( '' !== trim( (string) ( $section['title'] ?? '' ) ) ) {
				break;
			}

			$title = self::get_finora_about_bedeutet_heading_html();
			if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
				break;
			}

			$values[ $index ]['title'] = $title;
			$repaired                  = true;
			break;
		}

		if ( $repaired && function_exists( 'update_field' ) ) {
			update_field( $field_name, $values, $post_id );
		}

		return $values;
	}

	/**
	 * Read the canonical About page heading for the "bedeutet" section.
	 *
	 * @return string
	 */
	protected static function get_finora_about_bedeutet_heading_html() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$paths = array();
		if ( defined( 'LEADWERK_THEME_DIR' ) ) {
			$paths[] = trailingslashit( LEADWERK_THEME_DIR ) . 'source_shells/ueber-finora.html';
		}
		if ( defined( 'LEADWERK_IMPORTER_PATH' ) ) {
			$paths[] = trailingslashit( LEADWERK_IMPORTER_PATH ) . 'source_assets/ueber-finora.html';
		}

		$cached = '';
		foreach ( $paths as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$html = file_get_contents( $path );
			if ( ! is_string( $html ) || '' === $html ) {
				continue;
			}

			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded   = $dom->loadHTML( $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded ) {
				continue;
			}

			$xpath = new DOMXPath( $dom );
			$node  = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " section-bedeutet ")]//*[contains(concat(" ", normalize-space(@class), " "), " section-heading ")]//h2[1]' )->item( 0 );
			if ( $node instanceof DOMNode ) {
				$cached = self::get_dom_node_inner_html( $node );
				if ( '' !== trim( wp_strip_all_tags( $cached ) ) ) {
					return $cached;
				}
			}
		}

		return $cached;
	}

	/**
	 * Serialize child nodes as inner HTML.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string
	 */
	protected static function get_dom_node_inner_html( $node ) {
		if ( ! $node instanceof DOMNode || ! $node->hasChildNodes() ) {
			return '';
		}

		$html = '';
		foreach ( $node->childNodes as $child_node ) {
			$html .= $node->ownerDocument->saveHTML( $child_node );
		}

		return trim( (string) $html );
	}

	/**
	 * Rebuild one bundle from current source values plus old bundle/seed state.
	 *
	 * @param array<string,mixed> $group         Group definition.
	 * @param mixed               $source_values Source values.
	 * @param array<string,mixed> $seed_lookup   Structured seed lookup.
	 * @param array<string,mixed> $bundle        Existing bundle.
	 * @return array<string,mixed>
	 */
	protected static function reconcile_bundle_with_source( $group, $source_values, $seed_lookup, $bundle, $source_lang = '', $target_lang = '' ) {
		$existing_paths = is_array( $bundle['paths'] ?? null ) ? $bundle['paths'] : array();
		$next_paths     = array();
		$descriptors    = self::collect_leaf_descriptors( $group, $source_values );
		$source_lang    = sanitize_key( (string) $source_lang );
		$target_lang    = sanitize_key( (string) $target_lang );

		foreach ( $descriptors as $descriptor ) {
			$path_key         = $descriptor['path_key'];
			$existing         = isset( $existing_paths[ $path_key ] ) && is_array( $existing_paths[ $path_key ] ) ? $existing_paths[ $path_key ] : array();
			$seed_translation = '';
			$memory_translation = '';

			if ( isset( $seed_lookup[ $path_key ] ) && is_array( $seed_lookup[ $path_key ] ) ) {
				$seed_translation = (string) ( $seed_lookup[ $path_key ]['translation'] ?? '' );
			}

			$existing_translation = array_key_exists( 'translation', $existing ) ? (string) $existing['translation'] : '';
			$translation          = $existing_translation;

			// Seed only missing translations; manual editor changes stay authoritative on re-import.
			if ( '' === trim( $existing_translation ) && '' !== trim( $seed_translation ) ) {
				$translation = $seed_translation;
			}
			if ( '' === trim( $translation ) && '' !== $source_lang && '' !== $target_lang && $source_lang !== $target_lang ) {
				$memory_match = Leadwerk_Translation_API::find_translation_memory_match(
					$source_lang,
					$target_lang,
					(string) ( $descriptor['type'] ?? 'text' ),
					(string) ( $descriptor['source'] ?? '' )
				);
				$memory_translation = (string) ( $memory_match['translation_value'] ?? '' );
				if ( '' !== trim( $memory_translation ) ) {
					$translation = $memory_translation;
				}
			}
			
			$source_hash = (string) $descriptor['source_hash'];

			$changed = isset( $existing['source_hash'] ) && (string) $existing['source_hash'] !== $source_hash;

			$next_paths[ $path_key ] = array(
				'id'            => (string) ( $existing['id'] ?? self::build_segment_id( $path_key ) ),
				'path_key'      => $path_key,
				'type'          => (string) $descriptor['type'],
				'label'         => (string) $descriptor['label'],
				'section_key'   => (string) $descriptor['section_key'],
				'section_label' => (string) $descriptor['section_label'],
				'source'        => (string) $descriptor['source'],
				'source_hash'   => $source_hash,
				'translation'   => $translation,
				'needs_review'  => $changed ? '' !== trim( $translation ) : ! empty( $existing['needs_review'] ),
			);

			$next_paths[ $path_key ]['status'] = self::determine_status( $next_paths[ $path_key ] );
		}

		$bundle['paths']      = $next_paths;
		$bundle['field_name'] = (string) ( $group['field_name'] ?? '' );
		$bundle['updated_at'] = current_time( 'mysql' );

		return $bundle;
	}

	/**
	 * Build translated values from one bundle.
	 *
	 * @param array<string,mixed> $group         Group definition.
	 * @param mixed               $source_values Source values.
	 * @param array<string,mixed> $bundle        Translation bundle.
	 * @return mixed
	 */
	protected static function build_translated_value( $group, $source_values, $bundle ) {
		$path_lookup = is_array( $bundle['paths'] ?? null ) ? $bundle['paths'] : array();

		if ( ! empty( $group['layouts'] ) && is_array( $group['layouts'] ) ) {
			$translated = array();
			$sections   = is_array( $source_values ) ? array_values( $source_values ) : array();
			$index      = 0;

			foreach ( $group['layouts'] as $layout_key => $layout_schema ) {
				$source_section              = self::get_layout_source_value( $sections, $index, $layout_key );
				$translated_section          = array();
				$translated_section['acf_fc_layout'] = $layout_key;

				foreach ( (array) ( $layout_schema['fields'] ?? array() ) as $field_key => $definition ) {
					$source_value = array_key_exists( $field_key, $source_section )
						? $source_section[ $field_key ]
						: self::get_default_value( $definition );
					$translated_section[ $field_key ] = self::build_translated_field_value(
						$field_key,
						$definition,
						$source_value,
						array( $layout_key, $field_key ),
						$path_lookup
					);
				}

				$translated[] = $translated_section;
				++$index;
			}

			return $translated;
		}

		$source_group = is_array( $source_values ) ? $source_values : array();
		$translated   = array();
		foreach ( (array) ( $group['fields'] ?? array() ) as $field_key => $definition ) {
			$source_value = array_key_exists( $field_key, $source_group )
				? $source_group[ $field_key ]
				: self::get_default_value( $definition );
			$translated[ $field_key ] = self::build_translated_field_value(
				$field_key,
				$definition,
				$source_value,
				array( 'fields', $field_key ),
				$path_lookup
			);
		}

		return $translated;
	}

	/**
	 * Build one translated field value.
	 *
	 * @param string              $field_key    Field key.
	 * @param array<string,mixed> $definition   Field definition.
	 * @param mixed               $source_value Source value.
	 * @param array<int,string>   $path_parts   Current path parts.
	 * @param array<string,mixed> $path_lookup  Bundle paths.
	 * @return mixed
	 */
	protected static function build_translated_field_value( $field_key, $definition, $source_value, $path_parts, $path_lookup ) {
		$type = $definition['type'] ?? 'text';

		if ( 'repeater' === $type ) {
			$rows = is_array( $source_value ) ? array_values( $source_value ) : array();
			$out  = array();

			foreach ( $rows as $index => $row ) {
				$item = array();
				foreach ( (array) ( $definition['fields'] ?? array() ) as $sub_key => $sub_definition ) {
					$sub_source_value = is_array( $row ) && array_key_exists( $sub_key, $row )
						? $row[ $sub_key ]
						: self::get_default_value( $sub_definition );
					$item[ $sub_key ] = self::build_translated_field_value(
						$sub_key,
						$sub_definition,
						$sub_source_value,
						array_merge( $path_parts, array( (string) $index, $sub_key ) ),
						$path_lookup
					);
				}
				$out[] = $item;
			}

			return $out;
		}

		if ( self::is_translatable_definition( $field_key, $definition ) ) {
			$path_key = self::build_path_key( $path_parts );
			return isset( $path_lookup[ $path_key ]['translation'] ) ? (string) $path_lookup[ $path_key ]['translation'] : '';
		}

		return $source_value;
	}

	/**
	 * Collect translatable leaf descriptors from source values.
	 *
	 * @param array<string,mixed> $group  Group definition.
	 * @param mixed               $values Current values.
	 * @return array<int,array<string,string>>
	 */
	protected static function collect_leaf_descriptors( $group, $values, $skip_empty = true ) {
		$descriptors = array();

		if ( ! empty( $group['layouts'] ) && is_array( $group['layouts'] ) ) {
			$sections = is_array( $values ) ? array_values( $values ) : array();
			$index    = 0;

			foreach ( $group['layouts'] as $layout_key => $layout_schema ) {
				$section       = self::get_layout_source_value( $sections, $index, $layout_key );
				$section_label = (string) ( $layout_schema['label'] ?? ucfirst( $layout_key ) );
				self::collect_field_descriptors(
					(array) ( $layout_schema['fields'] ?? array() ),
					$section,
					array( $layout_key ),
					array(),
					$layout_key,
					$section_label,
					$descriptors,
					(bool) $skip_empty
				);
				++$index;
			}

			return $descriptors;
		}

		self::collect_field_descriptors(
			(array) ( $group['fields'] ?? array() ),
			is_array( $values ) ? $values : array(),
			array( 'fields' ),
			array(),
			'general',
			(string) ( $group['label'] ?? 'Content' ),
			$descriptors,
			(bool) $skip_empty
		);

		return $descriptors;
	}

	/**
	 * Collect field descriptors recursively.
	 *
	 * @param array<string,mixed>              $fields        Field definitions.
	 * @param array<string,mixed>              $values        Current values.
	 * @param array<int,string>                $path_parts    Path parts.
	 * @param array<int,string>                $label_prefix  Nested labels.
	 * @param string                           $section_key   Section key.
	 * @param string                           $section_label Section label.
	 * @param array<int,array<string,string>> &$descriptors  Output.
	 * @return void
	 */
	protected static function collect_field_descriptors( $fields, $values, $path_parts, $label_prefix, $section_key, $section_label, &$descriptors, $skip_empty = true ) {
		foreach ( $fields as $field_key => $definition ) {
			$type        = $definition['type'] ?? 'text';
			$field_label = (string) ( $definition['label'] ?? ucfirst( $field_key ) );
			$current     = is_array( $values ) && array_key_exists( $field_key, $values )
				? $values[ $field_key ]
				: self::get_default_value( $definition );

			if ( 'repeater' === $type ) {
				$rows = is_array( $current ) ? array_values( $current ) : array();
				foreach ( $rows as $index => $row ) {
					$row_label = $field_label . ' ' . ( $index + 1 );
					self::collect_field_descriptors(
						(array) ( $definition['fields'] ?? array() ),
						is_array( $row ) ? $row : array(),
						array_merge( $path_parts, array( $field_key, (string) $index ) ),
						array_merge( $label_prefix, array( $row_label ) ),
						$section_key,
						$section_label,
						$descriptors,
						(bool) $skip_empty
					);
				}
				continue;
			}

			if ( ! self::is_translatable_definition( $field_key, $definition ) ) {
				continue;
			}

			$source = (string) $current;
			if ( $skip_empty && self::is_effectively_empty_source( $definition, $source ) ) {
				continue;
			}

			$descriptors[] = array(
				'path_key'      => self::build_path_key( array_merge( $path_parts, array( $field_key ) ) ),
				'section_key'   => (string) $section_key,
				'section_label' => (string) $section_label,
				'label'         => implode( ' -> ', array_merge( $label_prefix, array( $field_label ) ) ),
				'type'          => self::get_segment_type( $definition ),
				'source'        => $source,
				'source_hash'   => md5( self::normalize_translatable_source( $definition, $source ) ),
			);
		}
	}

	/**
	 * Calculate the top-level bundle status.
	 *
	 * @param array<string,mixed> $bundle Bundle.
	 * @return string
	 */
	protected static function calculate_bundle_status( $bundle ) {
		$paths = (array) ( $bundle['paths'] ?? array() );
		if ( empty( $paths ) ) {
			return 'complete';
		}

		$has_translation   = false;
		$has_missing       = false;
		$has_needs_review  = false;

		foreach ( $paths as $path_bundle ) {
			$status = self::determine_status( is_array( $path_bundle ) ? $path_bundle : array() );

			if ( 'complete' === $status ) {
				$has_translation = true;
				continue;
			}

			if ( 'needs_update' === $status ) {
				$has_translation  = true;
				$has_needs_review = true;
				continue;
			}

			$has_missing = true;
		}

		if ( $has_needs_review ) {
			return 'needs_update';
		}

		if ( $has_missing && $has_translation ) {
			return 'in_progress';
		}

		if ( $has_missing ) {
			return 'not_translated';
		}

		return 'complete';
	}

	/**
	 * Determine status for one path bundle.
	 *
	 * @param array<string,mixed> $path_bundle Path bundle.
	 * @return string
	 */
	protected static function determine_status( $path_bundle ) {
		if ( ! empty( $path_bundle['needs_review'] ) ) {
			return 'needs_update';
		}

		$translation = trim( (string) ( $path_bundle['translation'] ?? '' ) );
		return '' === $translation ? 'needs_translation' : 'complete';
	}

	/**
	 * Determine whether one field definition is translatable.
	 *
	 * @param string              $field_key   Field key.
	 * @param array<string,mixed> $definition  Definition.
	 * @return bool
	 */
	protected static function is_translatable_definition( $field_key, $definition ) {
		$type = $definition['type'] ?? 'text';

		if ( ! in_array( $type, array( 'text', 'textarea', 'classic_editor', 'wysiwyg', 'heading_html' ), true ) ) {
			return false;
		}

		$non_translatable = apply_filters( 'leadwerk_sync_non_translatable_keys', self::$non_translatable_text_keys );
		return ! in_array( $field_key, $non_translatable, true );
	}

	/**
	 * Return the classic editor segment type.
	 *
	 * @param array<string,mixed> $definition Definition.
	 * @return string
	 */
	protected static function get_segment_type( $definition ) {
		return in_array( $definition['type'] ?? 'text', array( 'classic_editor', 'wysiwyg', 'heading_html' ), true ) ? 'richtext' : 'text';
	}

	/**
	 * Whether one source value is effectively empty for translation purposes.
	 *
	 * @param array<string,mixed> $definition Field definition.
	 * @param string              $source     Source content.
	 * @return bool
	 */
	protected static function is_effectively_empty_source( $definition, $source ) {
		return '' === self::normalize_translatable_source( $definition, $source );
	}

	/**
	 * Normalize source content before emptiness/hash comparisons.
	 *
	 * @param array<string,mixed> $definition Field definition.
	 * @param string              $source     Raw source value.
	 * @return string
	 */
	protected static function normalize_translatable_source( $definition, $source ) {
		return Leadwerk_Translation_API::normalize_translation_memory_source( (string) $source, self::get_segment_type( $definition ) );
	}

	/**
	 * Build diagnostics for how one seed maps onto required bundle paths.
	 *
	 * @param array<string,mixed> $group         Group definition.
	 * @param mixed               $source_values Source values.
	 * @param mixed               $seed_values   Structured seed values.
	 * @param array<string,mixed> $bundle        Bundle data.
	 * @return array<string,int|bool>
	 */
	protected static function build_seed_diagnostics( $group, $source_values, $seed_values, $bundle ) {
		$seed_lookup = self::normalize_seed_lookup( $group, $seed_values );
		if ( empty( $seed_lookup ) ) {
			return self::get_empty_seed_diagnostics();
		}

		$required_keys = array_fill_keys(
			array_map(
				static function ( $descriptor ) {
					return (string) $descriptor['path_key'];
				},
				self::collect_leaf_descriptors( $group, $source_values, true )
			),
			true
		);
		$all_source_keys = array_fill_keys(
			array_map(
				static function ( $descriptor ) {
					return (string) $descriptor['path_key'];
				},
				self::collect_leaf_descriptors( $group, $source_values, false )
			),
			true
		);
		$bundle_paths    = is_array( $bundle['paths'] ?? null ) ? $bundle['paths'] : array();
		$diagnostics     = self::get_empty_seed_diagnostics();
		$diagnostics['structured_seed_found'] = true;

		foreach ( $seed_lookup as $path_key => $path_seed ) {
			if ( ! is_array( $path_seed ) ) {
				continue;
			}

			++$diagnostics['total_seed_paths'];

			$seed_translation = trim( (string) ( $path_seed['translation'] ?? '' ) );
			if ( '' !== $seed_translation ) {
				++$diagnostics['translated_seed_paths'];
			}

			if ( isset( $required_keys[ $path_key ] ) ) {
				if ( '' !== $seed_translation && $seed_translation === trim( (string) ( $bundle_paths[ $path_key ]['translation'] ?? '' ) ) ) {
					++$diagnostics['applied_seed_paths'];
				}
				continue;
			}

			if ( isset( $all_source_keys[ $path_key ] ) ) {
				++$diagnostics['skipped_empty_source_paths'];
				continue;
			}

			++$diagnostics['unmatched_seed_paths'];
		}

		return $diagnostics;
	}

	/**
	 * Return a default seed diagnostics payload.
	 *
	 * @return array<string,int|bool>
	 */
	protected static function get_empty_seed_diagnostics() {
		return array(
			'structured_seed_found'     => false,
			'total_seed_paths'          => 0,
			'translated_seed_paths'     => 0,
			'applied_seed_paths'        => 0,
			'skipped_empty_source_paths'=> 0,
			'unmatched_seed_paths'      => 0,
		);
	}

	/**
	 * Build a stable path key.
	 *
	 * @param array<int,string> $parts Path parts.
	 * @return string
	 */
	protected static function build_path_key( $parts ) {
		$parts = array_map(
			static function ( $part ) {
				return trim( (string) $part );
			},
			(array) $parts
		);
		$parts = array_values(
			array_filter(
				$parts,
				static function ( $part ) {
					return '' !== $part;
				}
			)
		);

		return implode( '.', $parts );
	}

	/**
	 * Build a compact segment ID from one path key.
	 *
	 * @param string $path_key Path key.
	 * @return string
	 */
	protected static function build_segment_id( $path_key ) {
		return 'seg_' . substr( md5( (string) $path_key ), 0, 12 );
	}

	/**
	 * Return source value for one layout slot.
	 *
	 * @param array<int,mixed> $sections   Source sections.
	 * @param int              $index      Layout index.
	 * @param string           $layout_key Layout key.
	 * @return array<string,mixed>
	 */
	protected static function get_layout_source_value( $sections, $index, $layout_key ) {
		$section = isset( $sections[ $index ] ) && is_array( $sections[ $index ] ) ? $sections[ $index ] : array();
		if ( empty( $section['acf_fc_layout'] ) ) {
			$section['acf_fc_layout'] = $layout_key;
		}

		return $section;
	}

	/**
	 * Resolve a default field value.
	 *
	 * @param array<string,mixed> $definition Field definition.
	 * @return mixed
	 */
	protected static function get_default_value( $definition ) {
		if ( class_exists( 'Leadwerk_Content_Schema' ) && method_exists( 'Leadwerk_Content_Schema', 'get_default_value' ) ) {
			return Leadwerk_Content_Schema::get_default_value( $definition );
		}

		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'checkbox':
				return false;
			case 'image':
				return 0;
			case 'repeater':
			case 'select_options':
				return array();
			default:
				return '';
		}
	}
}

