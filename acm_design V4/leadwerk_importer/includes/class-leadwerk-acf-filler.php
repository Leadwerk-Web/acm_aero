<?php
/**
 * Structured FINORA field filling from static HTML sources.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_ACF_Filler {

	/** @var string */
	protected $source_root = '';

	/** @var Leadwerk_Media_Importer|null */
	protected $media_importer = null;

	/** @var array<string,array<string,int>> */
	protected $page_lookup = array();

	/** @var array<string,int> */
	protected $attachment_cache = array();

	/**
	 * Constructor.
	 *
	 * @param string                        $source_root    Source root.
	 * @param Leadwerk_Media_Importer|null  $media_importer Media importer.
	 * @param array<string,array<string,int>> $page_lookup  Page lookup.
	 */
	public function __construct( $source_root = '', $media_importer = null, $page_lookup = array() ) {
		$this->source_root    = rtrim( (string) $source_root, '/\\' );
		$this->media_importer = $media_importer instanceof Leadwerk_Media_Importer ? $media_importer : null;
		$this->page_lookup    = is_array( $page_lookup ) ? $page_lookup : array();
	}

	/**
	 * Build one structured payload from disk.
	 *
	 * @param array<string,mixed> $page_config            Page config.
	 * @param string              $lang                   Language code.
	 * @param string              $override_relative_file Optional alternative file path.
	 * @return array<string,mixed>
	 */
	public function build_page_payload( $page_config, $lang = 'de', $override_relative_file = '' ) {
		$relative_file = '' !== trim( (string) $override_relative_file )
			? (string) $override_relative_file
			: (string) ( $page_config['source_file'] ?? '' );
		$file_path     = $this->resolve_source_path( $relative_file );

		if ( '' === $relative_file || ! is_file( $file_path ) ) {
			Leadwerk_Logger::log( 'HTML-Datei nicht gefunden: ' . $relative_file );
			return array();
		}

		$html = file_get_contents( $file_path );
		if ( false === $html ) {
			Leadwerk_Logger::log( 'HTML-Datei konnte nicht gelesen werden: ' . $relative_file );
			return array();
		}

		return $this->build_page_payload_from_html( $page_config, (string) $html, $lang );
	}

	/**
	 * Build one payload directly from HTML.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $html        HTML string.
	 * @param string              $lang        Language code.
	 * @return array<string,mixed>
	 */
	public function build_page_payload_from_html( $page_config, $html, $lang = 'de' ) {
		list( $dom, $xpath ) = $this->create_dom_xpath( $html );

		$payload = array(
			'body_class'       => $this->attr( $xpath, '//body', 'class' ),
			'document_title'   => $this->text( $xpath, '//title' ),
			'meta_description' => $this->attr( $xpath, '//meta[@name="description"]', 'content' ),
			'value'            => array(),
			'validation'       => array(),
			'layout_diagnostics' => array(),
		);

		$group = Leadwerk_Content_Schema::get_group( (string) ( $page_config['field_name'] ?? '' ) );
		if ( ! $group ) {
			return $payload;
		}

		if ( empty( $group['layouts'] ) ) {
			$legal_match      = $this->resolve_legal_match( $xpath );
			$payload['value'] = $this->normalize_group_value( (string) $page_config['field_name'], $this->build_legal_value( $xpath, $legal_match['node'] ?? null ) );
			$payload['validation'] = $this->build_payload_validation( (string) $page_config['field_name'], $group, $payload['value'], 1 );
			$payload['layout_diagnostics'] = $this->build_layout_diagnostics(
				(string) $page_config['field_name'],
				$group,
				$payload['value'],
				array(
					'legal_content' => array(
						'matched_by'    => (string) ( $legal_match['matched_by'] ?? 'missing' ),
						'selector_used' => (string) ( $legal_match['selector_used'] ?? '.legal-content' ),
						'found'         => ! empty( $legal_match['found'] ),
						'source_index'  => 0,
					),
				)
			);
			return $payload;
		}

		$section_match     = $this->resolve_group_section_nodes( (string) $page_config['field_name'], $group, $xpath );
		$sections          = (array) ( $section_match['sections'] ?? array() );
		$normalized_values = array();
		$index             = 0;

		foreach ( $group['layouts'] as $layout_key => $layout_schema ) {
			$layout_match         = (array) ( $section_match['matches'][ $layout_key ] ?? array() );
			$section_node         = $layout_match['node'] ?? ( $sections[ $index ] ?? null );
			$normalized_values[]  = $this->normalize_layout_value(
				(string) $page_config['field_name'],
				$layout_key,
				$this->parse_layout_value( $layout_key, $layout_schema, $section_node, $lang )
			);
			++$index;
		}

		if ( count( $sections ) !== count( $group['layouts'] ) ) {
			Leadwerk_Logger::log(
				sprintf(
					'Sektionen/Layouts Anzahl abweichend fuer %s (%s): %d HTML vs %d Schema',
					(string) ( $page_config['source_file'] ?? '' ),
					$lang,
					count( $sections ),
					count( $group['layouts'] )
				)
			);
		}

		$payload['value'] = $normalized_values;
		$payload['validation'] = $this->build_payload_validation( (string) $page_config['field_name'], $group, $payload['value'], count( $sections ) );
		$payload['layout_diagnostics'] = $this->build_layout_diagnostics(
			(string) $page_config['field_name'],
			$group,
			$payload['value'],
			(array) ( $section_match['matches'] ?? array() )
		);
		return $payload;
	}

	/**
	 * Build structured payload from a set of section HTML fragments.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param array<int,string>   $sections    Section HTML fragments.
	 * @param array<string,mixed> $metadata    Metadata fallback.
	 * @param string              $lang        Language code.
	 * @return array<string,mixed>
	 */
	public function build_page_payload_from_sections( $page_config, $sections, $metadata = array(), $lang = 'de' ) {
		$payload = array(
			'body_class'       => (string) ( $metadata['body_class'] ?? '' ),
			'document_title'   => (string) ( $metadata['document_title'] ?? '' ),
			'meta_description' => (string) ( $metadata['meta_description'] ?? '' ),
			'value'            => array(),
			'validation'       => array(),
			'layout_diagnostics' => array(),
		);

		$group = Leadwerk_Content_Schema::get_group( (string) ( $page_config['field_name'] ?? '' ) );
		if ( ! $group ) {
			return $payload;
		}

		if ( empty( $group['layouts'] ) ) {
			return $payload;
		}

		$sections = is_array( $sections ) ? array_values( $sections ) : array();
		$values   = array();
		$index    = 0;

		foreach ( $group['layouts'] as $layout_key => $layout_schema ) {
			$section_html = isset( $sections[ $index ] ) ? (string) $sections[ $index ] : '';
			$values[]     = $this->normalize_layout_value(
				(string) $page_config['field_name'],
				$layout_key,
				$this->parse_layout_html( $layout_key, $layout_schema, $section_html, $lang )
			);
			++$index;
		}

		$payload['value'] = $values;
		$payload['validation'] = $this->build_payload_validation( (string) $page_config['field_name'], $group, $payload['value'], count( $sections ) );
		$payload['layout_diagnostics'] = $this->build_layout_diagnostics( (string) $page_config['field_name'], $group, $payload['value'] );
		return $payload;
	}

	/**
	 * Validate stored group values against the structured schema.
	 *
	 * @param string $field_name Field group name.
	 * @param mixed  $value      Stored value.
	 * @return array<string,mixed>
	 */
	public function validate_group_value( $field_name, $value ) {
		$group = Leadwerk_Content_Schema::get_group( (string) $field_name );
		if ( ! $group ) {
			return array(
				'field_name'            => (string) $field_name,
				'has_visible_content'   => false,
				'visible_content_score' => 0,
				'empty_fields'          => array(),
				'empty_layouts'         => array(),
			);
		}

		$parsed_count = is_array( $value ) ? count( $value ) : ( empty( $group['layouts'] ) ? 1 : 0 );
		return $this->build_payload_validation( (string) $field_name, $group, $value, $parsed_count );
	}

	/**
	 * Build validation metrics for one payload.
	 *
	 * @param string              $field_name            Field group name.
	 * @param array<string,mixed> $group                 Group schema.
	 * @param mixed               $value                 Payload value.
	 * @param int                 $parsed_section_count  Parsed section count.
	 * @return array<string,mixed>
	 */
	protected function build_payload_validation( $field_name, $group, $value, $parsed_section_count = 0 ) {
		$validation = array(
			'field_name'             => $field_name,
			'is_legal'               => empty( $group['layouts'] ),
			'expected_layout_count'  => ! empty( $group['layouts'] ) ? count( $group['layouts'] ) : 1,
			'parsed_layout_count'    => is_array( $value ) && ! empty( $group['layouts'] ) ? count( $value ) : ( empty( $group['layouts'] ) ? 1 : 0 ),
			'parsed_section_count'   => max( 0, (int) $parsed_section_count ),
			'non_empty_layout_count' => 0,
			'missing_sections'       => 0,
			'empty_fields'           => array(),
			'empty_layouts'          => array(),
			'visible_content_score'  => 0,
			'has_visible_content'    => false,
		);

		if ( empty( $group['layouts'] ) ) {
			$fields = (array) ( $group['fields'] ?? array() );
			foreach ( $fields as $field_key => $definition ) {
				$field_report = $this->summarize_definition_visibility(
					is_array( $value ) && array_key_exists( $field_key, $value ) ? $value[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition ),
					$definition,
					$field_key
				);
				$validation['visible_content_score'] += (int) $field_report['visible_count'];
				if ( ! empty( $field_report['empty_fields'] ) ) {
					$validation['empty_fields'] = array_merge( $validation['empty_fields'], $field_report['empty_fields'] );
				}
			}

			$validation['non_empty_layout_count'] = $validation['visible_content_score'] > 0 ? 1 : 0;
			$validation['has_visible_content']    = $validation['visible_content_score'] > 0;
			if ( ! $validation['has_visible_content'] ) {
				$validation['empty_layouts'][] = 'legal_content';
			}

			return $validation;
		}

		$sections         = is_array( $value ) ? array_values( $value ) : array();
		$expected_layouts = (array) ( $group['layouts'] ?? array() );
		$validation['missing_sections'] = max( 0, count( $expected_layouts ) - max( count( $sections ), (int) $parsed_section_count ) );

		$section_index = 0;
		foreach ( $expected_layouts as $layout_key => $layout_schema ) {
			$section      = isset( $sections[ $section_index ] ) && is_array( $sections[ $section_index ] )
				? $sections[ $section_index ]
				: $this->empty_layout_value( $layout_key, $layout_schema );
			$section_path = 'layout:' . $layout_key;
			$section_has_visible_content = false;

			foreach ( (array) ( $layout_schema['fields'] ?? array() ) as $field_key => $definition ) {
				$field_report = $this->summarize_definition_visibility(
					array_key_exists( $field_key, $section ) ? $section[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition ),
					$definition,
					$section_path . '.' . $field_key
				);
				$validation['visible_content_score'] += (int) $field_report['visible_count'];
				if ( ! empty( $field_report['empty_fields'] ) ) {
					$validation['empty_fields'] = array_merge( $validation['empty_fields'], $field_report['empty_fields'] );
				}
				if ( (int) $field_report['visible_count'] > 0 ) {
					$section_has_visible_content = true;
				}
			}

			if ( ! $section_has_visible_content && $this->section_has_override_visible_content( $field_name, $layout_key, $section ) ) {
				$section_has_visible_content = true;
				++$validation['visible_content_score'];
			}

			if ( $section_has_visible_content ) {
				++$validation['non_empty_layout_count'];
			} else {
				$validation['empty_layouts'][] = $layout_key;
			}

			++$section_index;
		}

		$validation['has_visible_content'] = $validation['visible_content_score'] > 0 && $validation['non_empty_layout_count'] > 0;
		return $validation;
	}

	/**
	 * Build per-layout diagnostics for one payload.
	 *
	 * @param string                   $field_name    Field group name.
	 * @param array<string,mixed>      $group         Group schema.
	 * @param mixed                    $value         Payload value.
	 * @param array<string,array<string,mixed>> $matches Base match diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	protected function build_layout_diagnostics( $field_name, $group, $value, $matches = array() ) {
		$diagnostics = array();

		if ( empty( $group['layouts'] ) ) {
			$base          = (array) ( $matches['legal_content'] ?? array() );
			$visible_count = 0;
			$empty_fields  = array();

			foreach ( (array) ( $group['fields'] ?? array() ) as $field_key => $definition ) {
				$field_report   = $this->summarize_definition_visibility(
					is_array( $value ) && array_key_exists( $field_key, $value ) ? $value[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition ),
					$definition,
					'layout:legal_content.' . $field_key
				);
				$visible_count += (int) $field_report['visible_count'];
				$empty_fields   = array_merge( $empty_fields, (array) ( $field_report['empty_fields'] ?? array() ) );
			}

			$diagnostics[] = array(
				'layout_key'                  => 'legal_content',
				'label'                       => 'Legal Content',
				'matched_by'                  => (string) ( $base['matched_by'] ?? 'missing' ),
				'selector_used'               => (string) ( $base['selector_used'] ?? '.legal-content' ),
				'source_index'                => (int) ( $base['source_index'] ?? 0 ),
				'found'                       => ! empty( $base['found'] ),
				'layout_has_visible_content'  => $visible_count > 0,
				'visible_content_score'       => $visible_count,
				'empty_fields'                => array_values( array_unique( $empty_fields ) ),
			);

			return $diagnostics;
		}

		$sections         = is_array( $value ) ? array_values( $value ) : array();
		$section_index    = 0;
		foreach ( (array) ( $group['layouts'] ?? array() ) as $layout_key => $layout_schema ) {
			$section       = isset( $sections[ $section_index ] ) && is_array( $sections[ $section_index ] )
				? $sections[ $section_index ]
				: $this->empty_layout_value( $layout_key, $layout_schema );
			$base          = (array) ( $matches[ $layout_key ] ?? array() );
			$visible_count = 0;
			$empty_fields  = array();

			foreach ( (array) ( $layout_schema['fields'] ?? array() ) as $field_key => $definition ) {
				$field_report   = $this->summarize_definition_visibility(
					array_key_exists( $field_key, $section ) ? $section[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition ),
					$definition,
					'layout:' . $layout_key . '.' . $field_key
				);
				$visible_count += (int) $field_report['visible_count'];
				$empty_fields   = array_merge( $empty_fields, (array) ( $field_report['empty_fields'] ?? array() ) );
			}

			$layout_has_visible_content = $visible_count > 0;
			if ( ! $layout_has_visible_content && $this->section_has_override_visible_content( $field_name, $layout_key, $section ) ) {
				$layout_has_visible_content = true;
				++$visible_count;
			}

			$diagnostics[] = array(
				'layout_key'                 => (string) $layout_key,
				'label'                      => (string) ( $layout_schema['label'] ?? $layout_key ),
				'matched_by'                 => (string) ( $base['matched_by'] ?? 'index' ),
				'selector_used'              => (string) ( $base['selector_used'] ?? '' ),
				'source_index'               => (int) ( $base['source_index'] ?? $section_index ),
				'found'                      => ! array_key_exists( 'found', $base ) || ! empty( $base['found'] ),
				'layout_has_visible_content' => $layout_has_visible_content,
				'visible_content_score'      => $visible_count,
				'empty_fields'               => array_values( array_unique( $empty_fields ) ),
			);
			++$section_index;
		}

		return $diagnostics;
	}

	/**
	 * Decide whether a layout should count as visible based on important fields.
	 *
	 * @param string              $field_name Field group name.
	 * @param string              $layout_key Layout key.
	 * @param array<string,mixed> $section    Section data.
	 * @return bool
	 */
	protected function section_has_override_visible_content( $field_name, $layout_key, $section ) {
		$important_paths = array();

		if ( 'acm_index_sections' === $field_name && 'hero' === $layout_key ) {
			$important_paths = array( 'video', 'poster', 'title' );
		} elseif ( 'acm_index_sections' === $field_name && in_array( $layout_key, array( 'hero_statement', 'services_promo', 'trust_kpis', 'final_cta' ), true ) ) {
			$important_paths = array( 'quote', 'title', 'image', 'items', 'body', 'actions' );
		} elseif ( 'acm_index_sections' === $field_name && 'charter_hero' === $layout_key ) {
			$important_paths = array( 'title', 'image', 'image_alt', 'cta_label' );
		} elseif ( 'acm_index_sections' === $field_name && in_array( $layout_key, array( 'maintenance', 'management', 'handling', 'careers' ), true ) ) {
			$important_paths = array( 'label', 'title', 'body', 'image', 'cta_label' );
		} elseif ( 'acm_thats_acm_sections' === $field_name && in_array( $layout_key, array( 'company_intro', 'banner_experience', 'horizontal_timeline', 'certifications', 'banner_contact', 'capabilities', 'homebase' ), true ) ) {
			$important_paths = array( 'label', 'title', 'body', 'image', 'items', 'rows', 'intro_title', 'intro_label' );
		} elseif ( 'acm_thats_acm_sections' === $field_name && 'contact_cta' === $layout_key ) {
			$important_paths = array( 'title', 'body', 'cta_label', 'secondary_label' );
		} elseif ( 'acm_contact_sections' === $field_name ) {
			if ( 'hero' === $layout_key ) {
				$important_paths = array( 'title', 'subtitle', 'eyebrow', 'image' );
			} elseif ( 'quick_links' === $layout_key ) {
				$important_paths = array( 'label', 'title', 'items' );
			} elseif ( 'general_inquiry' === $layout_key ) {
				$important_paths = array( 'title', 'body', 'phone', 'email', 'cta_label' );
			} elseif ( 0 === strpos( (string) $layout_key, 'dept_' ) ) {
				$important_paths = array( 'title', 'intro', 'left_column', 'map_embed_url', 'profiles', 'footer_note' );
			}
		} elseif ( 'acm_charter_sections' === $field_name && in_array( $layout_key, array( 'hero', 'value_prop', 'operative_split', 'use_cases', 'fleet_overview', 'fleet_banner', 'safety_certs', 'differentiation', 'contact_cta' ), true ) ) {
			$important_paths = array( 'title', 'subtitle', 'label', 'body', 'intro', 'image', 'items', 'aircraft', 'cta_label', 'compare_label', 'compare_title', 'compare_tabs', 'compare_panels' );
		} elseif ( in_array( $field_name, array( 'acm_global7500_sections', 'acm_global6000_sections', 'acm_globalxrs_sections' ), true ) ) {
			$global_paths = array(
				'hero'            => array( 'title', 'subtitle', 'eyebrow', 'image' ),
				'highlights'      => array( 'title', 'items' ),
				'aircraft_intro'  => array( 'label', 'title', 'body', 'image' ),
				'cabin_story'     => array( 'label', 'title', 'body', 'image' ),
				'cabin_zones'     => array( 'title', 'items' ),
				'promo_banner'    => array( 'title', 'image' ),
				'gallery'         => array( 'title', 'slides' ),
				'floorplan'       => array( 'title', 'image', 'footnote' ),
				'technical_specs' => array( 'title', 'spec_groups', 'specs', 'footnote' ),
				'fleet_teaser'    => array( 'title', 'items' ),
				'contact_cta'     => array( 'title', 'body', 'cta_label', 'secondary_label' ),
			);
			if ( isset( $global_paths[ $layout_key ] ) ) {
				$important_paths = $global_paths[ $layout_key ];
			}
		} elseif ( 'acm_aircraft_sections' === $field_name ) {
			$aircraft_paths = array(
				'hero'             => array( 'title', 'subtitle', 'eyebrow', 'image', 'proof_badges' ),
				'owner_intro'      => array( 'title', 'body', 'image' ),
				'betriebsmodelle'  => array( 'label', 'title', 'items' ),
				'promo_banner'     => array( 'title', 'image' ),
				'process'          => array( 'title', 'intro', 'steps', 'cta_label' ),
				'integrated_split' => array( 'title', 'intro', 'image', 'items' ),
				'transparency'     => array( 'title', 'intro', 'image', 'items' ),
				'contact_cta'      => array( 'title', 'body', 'cta_label', 'secondary_label' ),
			);
			if ( isset( $aircraft_paths[ $layout_key ] ) ) {
				$important_paths = $aircraft_paths[ $layout_key ];
			}
		} elseif ( 'acm_maintenance_sections' === $field_name ) {
			$mnt_paths = array(
				'hero'         => array( 'title', 'subtitle', 'eyebrow', 'image', 'proof_badges' ),
				'owner_intro'  => array( 'title', 'body', 'image' ),
				'services'     => array( 'label', 'title', 'items' ),
				'promo_banner' => array( 'title', 'image' ),
				'process'      => array( 'title', 'intro', 'steps', 'cta_label' ),
				'aog'          => array( 'label', 'title', 'body', 'highlights', 'phone', 'image', 'cta_label' ),
				'facility'     => array( 'title', 'intro', 'image', 'items' ),
				'contact_cta'  => array( 'title', 'body', 'cta_label', 'secondary_label' ),
			);
			if ( isset( $mnt_paths[ $layout_key ] ) ) {
				$important_paths = $mnt_paths[ $layout_key ];
			}
		} elseif ( 'acm_careers_sections' === $field_name ) {
			$careers_paths = array(
				'hero'           => array( 'title', 'subtitle', 'eyebrow', 'image', 'proof_badges' ),
				'employer_intro' => array( 'title', 'body', 'image' ),
				'benefits'       => array( 'label', 'title', 'items' ),
				'work_areas'     => array( 'label', 'title', 'items' ),
				'promo_top'      => array( 'title', 'image', 'cta_label' ),
				'stellen'        => array( 'label', 'title', 'items', 'footer_text' ),
				'promo_bottom'   => array( 'title', 'image', 'cta_label' ),
				'culture_split'  => array( 'label', 'title', 'intro', 'image', 'highlights' ),
				'contact_cta'    => array( 'title', 'body', 'cta_label', 'secondary_label' ),
			);
			if ( isset( $careers_paths[ $layout_key ] ) ) {
				$important_paths = $careers_paths[ $layout_key ];
			}
		} elseif ( 'acm_news_sections' === $field_name ) {
			$news_paths = array(
				'hero'          => array( 'title', 'subtitle', 'eyebrow', 'image' ),
				'news_filters'  => array( 'filters' ),
				'news_grid'     => array( 'title' ),
				'news_archive'  => array( 'label', 'title', 'intro', 'load_more_text' ),
				'contact_cta'   => array( 'title', 'body', 'cta_label', 'secondary_label' ),
			);
			if ( isset( $news_paths[ $layout_key ] ) ) {
				$important_paths = $news_paths[ $layout_key ];
			}
		}

		foreach ( $important_paths as $path ) {
			if ( array_key_exists( $path, (array) $section ) && $this->value_has_visible_content( $section[ $path ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a scalar/array value contains visible content.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function value_has_visible_content( $value ) {
		if ( is_numeric( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( wp_strip_all_tags( $value ) );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->value_has_visible_content( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Summarize visibility for one schema field definition.
	 *
	 * @param mixed               $value      Value.
	 * @param array<string,mixed> $definition Definition.
	 * @param string              $path       Logical path.
	 * @return array<string,mixed>
	 */
	protected function summarize_definition_visibility( $value, $definition, $path ) {
		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'image':
				$visible = absint( $value ) > 0;
				return array(
					'visible_count' => $visible ? 1 : 0,
					'empty_fields'  => $visible ? array() : array( $path ),
				);

			case 'checkbox':
				return array(
					'visible_count' => ! empty( $value ) ? 1 : 0,
					'empty_fields'  => array(),
				);

			case 'repeater':
				$rows          = is_array( $value ) ? array_values( $value ) : array();
				$visible_count = 0;
				$empty_fields  = array();

				foreach ( $rows as $row_index => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$row_visible = 0;
					foreach ( (array) ( $definition['fields'] ?? array() ) as $sub_key => $sub_definition ) {
						$field_report = $this->summarize_definition_visibility(
							array_key_exists( $sub_key, $row ) ? $row[ $sub_key ] : Leadwerk_Content_Schema::get_default_value( $sub_definition ),
							$sub_definition,
							$path . '[' . $row_index . '].' . $sub_key
						);
						$row_visible    += (int) $field_report['visible_count'];
						$visible_count  += (int) $field_report['visible_count'];
						$empty_fields    = array_merge( $empty_fields, (array) $field_report['empty_fields'] );
					}

					if ( 0 === $row_visible ) {
						$empty_fields[] = $path . '[' . $row_index . ']';
					}
				}

				if ( empty( $rows ) ) {
					$empty_fields[] = $path;
				}

				return array(
					'visible_count' => $visible_count,
					'empty_fields'  => array_values( array_unique( $empty_fields ) ),
				);

			case 'classic_editor':
			case 'wysiwyg':
			case 'heading_html':
				$visible = '' !== trim( wp_strip_all_tags( (string) $value ) );
				return array(
					'visible_count' => $visible ? 1 : 0,
					'empty_fields'  => $visible ? array() : array( $path ),
				);

			case 'url':
			case 'text':
			case 'textarea':
			default:
				$visible = '' !== trim( (string) $value );
				return array(
					'visible_count' => $visible ? 1 : 0,
					'empty_fields'  => $visible ? array() : array( $path ),
				);
		}
	}

	/**
	 * Parse one layout from a section HTML fragment.
	 *
	 * @param string              $layout_key    Layout key.
	 * @param array<string,mixed> $layout_schema Layout schema.
	 * @param string              $html          Section HTML.
	 * @param string              $lang          Language code.
	 * @return array<string,mixed>
	 */
	protected function parse_layout_html( $layout_key, $layout_schema, $html, $lang ) {
		if ( '' === trim( $html ) ) {
			return $this->empty_layout_value( $layout_key, $layout_schema );
		}

		$wrapped = '<section class="leadwerk-temp-section">' . $html . '</section>';
		list( $dom, $xpath ) = $this->create_dom_xpath( $wrapped );
		$section_node        = $this->first_node( $xpath, '//section[contains(@class,"leadwerk-temp-section")]' );

		return $this->parse_layout_value( $layout_key, $layout_schema, $section_node, $lang );
	}

	/**
	 * Normalize one parsed layout value against the schema.
	 *
	 * @param string              $field_name Field group name.
	 * @param string              $layout_key Layout key.
	 * @param array<string,mixed> $value      Raw value.
	 * @return array<string,mixed>
	 */
	protected function normalize_layout_value( $field_name, $layout_key, $value ) {
		$schema = Leadwerk_Content_Schema::get_layout( $field_name, $layout_key );
		if ( ! $schema ) {
			return array( 'acf_fc_layout' => $layout_key );
		}

		$out                  = array();
		$out['acf_fc_layout'] = $layout_key;

		foreach ( (array) ( $schema['fields'] ?? array() ) as $field_key => $definition ) {
			$raw              = is_array( $value ) && array_key_exists( $field_key, $value ) ? $value[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition );
			$out[ $field_key ] = $this->normalize_value_by_definition( $raw, $definition );
		}

		return $out;
	}

	/**
	 * Normalize a scalar group value.
	 *
	 * @param string              $field_name Field group name.
	 * @param array<string,mixed> $value      Raw value.
	 * @return array<string,mixed>
	 */
	protected function normalize_group_value( $field_name, $value ) {
		$group = Leadwerk_Content_Schema::get_group( $field_name );
		if ( ! $group || empty( $group['fields'] ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $group['fields'] as $field_key => $definition ) {
			$raw            = is_array( $value ) && array_key_exists( $field_key, $value ) ? $value[ $field_key ] : Leadwerk_Content_Schema::get_default_value( $definition );
			$out[ $field_key ] = $this->normalize_value_by_definition( $raw, $definition );
		}

		return $out;
	}

	/**
	 * Normalize one field value by definition.
	 *
	 * @param mixed               $value      Raw value.
	 * @param array<string,mixed> $definition Field definition.
	 * @return mixed
	 */
	protected function normalize_value_by_definition( $value, $definition ) {
		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
				return trim( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'textarea':
				return trim( (string) $value );
			case 'classic_editor':
			case 'wysiwyg':
				return trim( (string) $value );
			case 'heading_html':
				return Leadwerk_Content_Schema::sanitize_heading_html( (string) $value );
			case 'image':
				return absint( $value );
			case 'checkbox':
				return ! empty( $value );
			case 'repeater':
				$rows = is_array( $value ) ? array_values( $value ) : array();
				$out  = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$item = array();
					foreach ( (array) ( $definition['fields'] ?? array() ) as $sub_key => $sub_definition ) {
						$item[ $sub_key ] = $this->normalize_value_by_definition(
							array_key_exists( $sub_key, $row ) ? $row[ $sub_key ] : Leadwerk_Content_Schema::get_default_value( $sub_definition ),
							$sub_definition
						);
					}
					$out[] = $item;
				}
				return $out;
			default:
				return trim( (string) $value );
		}
	}

	/**
	 * Resolve section nodes for one field group with selector fallbacks.
	 *
	 * @param string                   $field_name Field group name.
	 * @param array<string,mixed>      $group      Group schema.
	 * @param DOMXPath                 $xpath      XPath instance.
	 * @return array<string,mixed>
	 */
	protected function resolve_group_section_nodes( $field_name, $group, $xpath ) {
		$sections      = $this->extract_body_sections( $xpath );
		$fallbacks     = $this->get_selector_fallbacks( $field_name );
		$used_nodes    = array();
		$matches       = array();
		$section_index = 0;

		foreach ( (array) ( $group['layouts'] ?? array() ) as $layout_key => $layout_schema ) {
			$section_node   = $sections[ $section_index ] ?? null;
			$selector       = (string) ( $fallbacks[ $layout_key ] ?? '' );
			$matched_by     = $section_node instanceof DOMNode ? 'index' : 'missing';
			$selector_used  = '';

			if ( '' !== $selector ) {
				if ( ! $section_node instanceof DOMNode || ! $this->node_matches_selector( $section_node, $selector ) ) {
					$fallback_node = $this->find_section_by_selector( $xpath, $selector, $used_nodes );
					if ( $fallback_node instanceof DOMNode ) {
						$section_node  = $fallback_node;
						$matched_by    = 'selector_fallback';
						$selector_used = $selector;
					} else {
						$matched_by    = $section_node instanceof DOMNode ? 'selector_miss' : 'missing';
						$selector_used = $selector;
					}
				} else {
					$selector_used = $selector;
				}
			}

			if ( $section_node instanceof DOMNode ) {
				$used_nodes[ spl_object_hash( $section_node ) ] = true;
			}

			$matches[ $layout_key ] = array(
				'node'          => $section_node instanceof DOMNode ? $section_node : null,
				'matched_by'    => $matched_by,
				'selector_used' => $selector_used,
				'source_index'  => $section_index,
				'found'         => $section_node instanceof DOMNode,
				'label'         => (string) ( $layout_schema['label'] ?? $layout_key ),
			);
			++$section_index;
		}

		return array(
			'sections' => $sections,
			'matches'  => $matches,
		);
	}

	/**
	 * Resolve the legal page wrapper.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string,mixed>
	 */
	protected function resolve_legal_match( $xpath ) {
		$section = $this->find_section_by_selector( $xpath, '.legal-content' );
		if ( ! $section instanceof DOMNode ) {
			$section = $this->first_node( $xpath, '//section[contains(@class,"legal-content")][1]' );
		}

		return array(
			'node'          => $section instanceof DOMNode ? $section : null,
			'matched_by'    => $section instanceof DOMNode ? 'selector_fallback' : 'missing',
			'selector_used' => '.legal-content',
			'found'         => $section instanceof DOMNode,
		);
	}

	/**
	 * Return the semantic selector fallbacks for problematic groups.
	 *
	 * @param string $field_name Field group name.
	 * @return array<string,string>
	 */
	protected function get_selector_fallbacks( $field_name ) {
		$maps = array(
			'acm_index_sections' => array(
				'hero'              => '#hero',
				'hero_statement'    => '#hero-statement',
				'services'          => '#services',
				'services_promo'    => '#services-promo',
				'trust_kpis'        => '#trust-kpis',
				'about'             => '#about',
				'charter_hero'      => '#charter-hero',
				'maintenance'       => '#maintenance',
				'management'        => '#management',
				'handling'          => '#handling',
				'careers'           => '#careers',
				'news'              => '#news',
				'final_cta'         => '#home-final-cta',
			),
			'acm_thats_acm_sections' => array(
				'hero'                 => '#hero',
				'company_intro'        => '#thats-intro',
				'banner_experience'    => '#thats-banner-experience',
				'horizontal_timeline'  => '#htl-section',
				'certifications'       => '#thats-certifications',
				'banner_contact'       => '#thats-banner-contact',
				'capabilities'         => '#thats-capabilities',
				'homebase'             => '#thats-homebase',
				'contact_cta'          => '#contact',
			),
			'acm_charter_sections' => array(
				'hero'              => '#hero',
				'value_prop'        => '#charter-value',
				'operative_split'   => '#charter-operative',
				'use_cases'         => '#charter-use-cases',
				'fleet_overview'    => '#flotte',
				'fleet_banner'      => '#charter-fleet-banner',
				'safety_certs'      => '#charter-safety',
				'differentiation'   => '#charter-differentiation',
				'contact_cta'       => '#contact',
			),
			'acm_global7500_sections' => array(
				'hero'             => '#hero',
				'highlights'       => '#g7500-highlights',
				'aircraft_intro'   => '#g7500-intro',
				'cabin_story'      => '#g7500-cabin',
				'cabin_zones'      => '#g7500-zones',
				'promo_banner'     => '#g7500-promo',
				'gallery'          => '#g7500-gallery',
				'floorplan'        => '#g7500-floorplan',
				'technical_specs'  => '#g7500-specs',
				'fleet_teaser'     => '#g7500-fleet',
				'contact_cta'      => '#contact',
			),
			'acm_global6000_sections' => array(
				'hero'             => '#hero',
				'highlights'       => '#g6000-highlights',
				'aircraft_intro'   => '#g6000-intro',
				'cabin_story'      => '#g6000-cabin',
				'cabin_zones'      => '#g6000-zones',
				'promo_banner'     => '#g6000-promo',
				'gallery'          => '#g6000-gallery',
				'floorplan'        => '#g6000-floorplan',
				'technical_specs'  => '#g6000-specs',
				'fleet_teaser'     => '#g6000-fleet',
				'contact_cta'      => '#contact',
			),
			'acm_globalxrs_sections' => array(
				'hero'             => '#hero',
				'highlights'       => '#gxrs-highlights',
				'aircraft_intro'   => '#gxrs-intro',
				'cabin_story'      => '#gxrs-cabin',
				'cabin_zones'      => '#gxrs-zones',
				'promo_banner'     => '#gxrs-promo',
				'gallery'          => '#gxrs-gallery',
				'floorplan'        => '#gxrs-floorplan',
				'technical_specs'  => '#gxrs-specs',
				'fleet_teaser'     => '#gxrs-fleet',
				'contact_cta'      => '#contact',
			),
			'acm_aircraft_sections' => array(
				'hero'             => '#hero',
				'owner_intro'      => '#am-owner',
				'betriebsmodelle'  => '#betriebsmodelle',
				'promo_banner'     => '#am-promo',
				'process'          => '#am-process',
				'integrated_split' => '#am-integrated',
				'transparency'     => '#am-transparency',
				'contact_cta'      => '#contact',
			),
			'acm_maintenance_sections' => array(
				'hero'          => '#hero',
				'owner_intro'   => '#mnt-intro',
				'services'      => '#services',
				'promo_banner'  => '#mnt-promo',
				'process'       => '#mnt-process',
				'aog'           => '#aog',
				'facility'      => '#mnt-facility',
				'contact_cta'   => '#contact',
			),
			'acm_careers_sections' => array(
				'hero'            => '#hero',
				'employer_intro'  => '#car-employer',
				'benefits'        => '#car-benefits',
				'work_areas'      => '#car-areas',
				'promo_top'       => '#car-promo-jobs',
				'stellen'         => '#stellen',
				'promo_bottom'    => '#car-promo-culture',
				'culture_split'   => '#car-culture',
				'contact_cta'     => '#contact',
			),
			'acm_contact_sections' => array(
				'hero'                    => '#hero',
				'quick_links'             => '#kon-bereiche',
				'dept_zentrale'           => '#zentrale',
				'dept_geschaeftsfuehrung' => '#geschaeftsfuehrung',
				'dept_flugbetrieb'        => '#flugbetrieb',
				'dept_sales_operations'   => '#sales-operations',
				'dept_camo'               => '#camo',
				'dept_ground_operations'  => '#ground-operations',
				'dept_maintenance'        => '#maintenance',
				'dept_safety_compliance'  => '#safety-compliance',
				'dept_stores'             => '#stores',
				'general_inquiry'         => '#kon-inquiry',
			),
			'acm_news_sections' => array(
				'hero'          => '#hero',
				'news_filters'  => '#news-filters-bar',
				'news_grid'     => '#news-grid',
				'news_archive'  => '#news-archive-section',
				'contact_cta'   => '#contact',
			),
		);

		return (array) ( $maps[ $field_name ] ?? array() );
	}

	/**
	 * Find the first body section matching one selector.
	 *
	 * @param DOMXPath            $xpath      XPath instance.
	 * @param string              $selector   Simple selector.
	 * @param array<string,bool>  $used_nodes Already claimed nodes.
	 * @return DOMNode|null
	 */
	protected function find_section_by_selector( $xpath, $selector, $used_nodes = array() ) {
		$predicate = $this->selector_to_xpath_predicate( $selector );
		if ( '' === $predicate ) {
			return null;
		}

		// ACM HTML: sections are inside <main>, legacy: direct children of <body>.
		$queries = array(
			'//body/main/section[' . $predicate . ']',
			'//body/section[' . $predicate . ']',
		);

		foreach ( $queries as $query ) {
			foreach ( $this->query_nodes( $xpath, $query ) as $node ) {
				$hash = spl_object_hash( $node );
				if ( isset( $used_nodes[ $hash ] ) ) {
					continue;
				}
				return $node;
			}
		}

		return null;
	}

	/**
	 * Whether a node matches one simple selector.
	 *
	 * @param DOMNode $node     Section node.
	 * @param string  $selector Selector.
	 * @return bool
	 */
	protected function node_matches_selector( $node, $selector ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}

		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return false;
		}

		if ( '.' === substr( $selector, 0, 1 ) ) {
			return $this->class_name_exists( $node, substr( $selector, 1 ) );
		}

		if ( '#' === substr( $selector, 0, 1 ) ) {
			return $node->hasAttribute( 'id' ) && (string) $node->getAttribute( 'id' ) === substr( $selector, 1 );
		}

		return strtolower( $node->tagName ) === strtolower( $selector );
	}

	/**
	 * Convert a simple selector to an XPath predicate.
	 *
	 * @param string $selector Selector.
	 * @return string
	 */
	protected function selector_to_xpath_predicate( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return '';
		}

		if ( '.' === substr( $selector, 0, 1 ) ) {
			$class_name = substr( $selector, 1 );
			return 'contains(concat(" ", normalize-space(@class), " "), " ' . esc_attr( $class_name ) . ' ")';
		}

		if ( '#' === substr( $selector, 0, 1 ) ) {
			return '@id="' . esc_attr( substr( $selector, 1 ) ) . '"';
		}

		return 'self::' . sanitize_key( $selector );
	}

	/**
	 * Whether an element has one CSS class.
	 *
	 * @param DOMElement $node       Element.
	 * @param string     $class_name Class name.
	 * @return bool
	 */
	protected function class_name_exists( $node, $class_name ) {
		$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
		return in_array( $class_name, array_filter( (array) $classes ), true );
	}

	/**
	 * Build one legal page value.
	 *
	 * @param DOMXPath      $xpath        XPath.
	 * @param DOMNode|null  $section_node Optional legal section node.
	 * @return array<string,mixed>
	 */
	protected function build_legal_value( $xpath, $section_node = null ) {
		$context = $section_node instanceof DOMNode ? $section_node : $xpath;
		$query   = $section_node instanceof DOMNode
			? './/*[contains(@class,"legal-body")]/* | .//*[contains(@class,"legal-copy")]/*'
			: '//section[contains(@class,"legal-content")]//div[contains(@class,"legal-body")]/* | //section[contains(@class,"legal-content")]//div[contains(@class,"legal-copy")]/*';
		$content = '';
		foreach ( $this->query_nodes( $context, $query ) as $node ) {
			$content .= $node->ownerDocument->saveHTML( $node );
		}

		if ( '' === trim( $content ) ) {
			$fallback_query = $section_node instanceof DOMNode
				? './/*[contains(@class,"legal-body")][1] | .//*[contains(@class,"legal-copy")][1]'
				: '//section[contains(@class,"legal-content")]//*[contains(@class,"legal-body")][1] | //section[contains(@class,"legal-content")]//*[contains(@class,"legal-copy")][1]';
			$fallback_node = $this->first_node( $context, $fallback_query );
			if ( $fallback_node instanceof DOMNode ) {
				$content = $this->save_inner_html( $fallback_node );
			}
		}

		$headline_query = $section_node instanceof DOMNode
			? './/*[contains(@class,"legal-title")][1] | .//h1[1]'
			: '//section[contains(@class,"legal-content")]//*[contains(@class,"legal-title")][1] | //section[contains(@class,"legal-content")]//h1[1]';

		return array(
			'headline' => $this->text( $context, $headline_query ),
			'content'  => $content,
		);
	}

	/**
	 * Parse one layout value from a section node.
	 *
	 * @param string              $layout_key    Layout key.
	 * @param array<string,mixed> $layout_schema Layout schema.
	 * @param DOMNode|null        $section_node  Section node.
	 * @param string              $lang          Language code.
	 * @return array<string,mixed>
	 */
	protected function parse_layout_value( $layout_key, $layout_schema, $section_node, $lang ) {
		if ( ! $section_node instanceof DOMNode ) {
			return $this->empty_layout_value( $layout_key, $layout_schema );
		}

		switch ( (string) ( $layout_schema['template'] ?? $layout_key ) ) {
			case 'hero_slider':
				return $this->parse_hero_slider( $section_node );
			case 'pillars':
				return $this->parse_pillars( $section_node );
			case 'audience_switcher':
				return $this->parse_audience_switcher( $section_node );
			case 'why_acm':
				return $this->parse_why_acm( $section_node );
			case 'how_it_works':
				return $this->parse_how_it_works( $section_node );
			case 'testimonials':
				return $this->parse_testimonials( $section_node );
			case 'faq':
				return $this->parse_faq( $section_node );
			case 'hero':
				return $this->parse_hero( $section_node );
			case 'banner_cta':
				return $this->parse_banner_cta( $section_node );
			case 'about_bedeutet':
				return $this->parse_about_bedeutet( $section_node );
			case 'media_text':
				return $this->parse_media_text( $section_node );
			case 'workflow_blurbs':
				return $this->parse_workflow_blurbs( $section_node );
			case 'center_cta':
				return $this->parse_center_cta( $section_node );
			case 'tabs_section':
				return $this->parse_tabs_section( $section_node );
			case 'retirement_audience':
				return $this->parse_retirement_audience( $section_node );
			case 'concepts_section':
				return $this->parse_concepts_section( $section_node );
			case 'blurb_image_section':
				return $this->parse_blurb_image_section( $section_node );
			case 'approach_tiles':
				return $this->parse_approach_tiles( $section_node );
			case 'timeline':
				return $this->parse_timeline( $section_node );
			case 'target_groups':
				return $this->parse_target_groups( $section_node );
			case 'results_section':
				return $this->parse_results_section( $section_node );
			case 'real_estate_intro':
				return $this->parse_real_estate_intro( $section_node );
			case 'calculator':
				return $this->parse_calculator( $section_node );
			case 'case_highlight':
				return $this->parse_case_highlight( $section_node );
			case 'dark_cta':
				return $this->parse_dark_cta( $section_node );
			case 'responsibility':
				return $this->parse_responsibility( $section_node );
			case 'new_phase':
				return $this->parse_new_phase( $section_node );
			case 'outcomes':
				return $this->parse_outcomes( $section_node );
			case 'target_groups_image':
				return $this->parse_target_groups_image( $section_node );
			case 'audience_cards':
				return $this->parse_audience_cards( $section_node );
			case 'media_blurbs':
				return $this->parse_media_blurbs( $section_node );
			case 'invest_detail':
				return $this->parse_invest_detail( $section_node );
			case 'tax_detail':
				return $this->parse_tax_detail( $section_node );
			case 'contact_main':
				return $this->parse_contact_main( $section_node );

			/* ── ACM AIR CHARTER templates ─────────────────── */
			case 'acm_hero_video':
				return $this->parse_acm_hero_video( $section_node );
			case 'acm_hero_statement':
				return $this->parse_acm_hero_statement( $section_node );
			case 'acm_fullwidth_promo':
				return $this->parse_acm_fullwidth_promo( $section_node );
			case 'acm_intro_centered':
				return $this->parse_acm_intro_centered( $section_node );
			case 'acm_certifications_grid':
				return $this->parse_acm_certifications_grid( $section_node );
			case 'acm_split_icon_features':
				return $this->parse_acm_split_icon_features( $section_node );
			case 'acm_split_rows':
				return $this->parse_acm_split_rows( $section_node );
			case 'acm_process_split':
				return $this->parse_acm_process_split( $section_node );
			case 'acm_kpi_strip':
				return $this->parse_acm_kpi_strip( $section_node );
			case 'acm_home_final_cta':
				return $this->parse_acm_home_final_cta( $section_node );
			case 'acm_hero':
				return $this->parse_acm_hero( $section_node );
			case 'acm_services_grid':
				return $this->parse_acm_services_grid( $section_node );
			case 'acm_about_teaser':
				return $this->parse_acm_about_teaser( $section_node );
			case 'acm_charter_hero':
				return $this->parse_acm_charter_hero( $section_node );
			case 'acm_content_block':
				return $this->parse_acm_content_block( $section_node );
			case 'acm_news_teaser':
				return $this->parse_acm_news_teaser( $section_node );
			case 'acm_horizontal_timeline':
				return $this->parse_acm_horizontal_timeline( $section_node );
			case 'acm_contact_cta':
				return $this->parse_acm_contact_cta( $section_node );
			case 'acm_fleet_overview':
				return $this->parse_acm_fleet_overview( $section_node );
			case 'acm_aircraft_specs':
				return $this->parse_acm_aircraft_specs( $section_node );
			case 'acm_zone_cards':
				return $this->parse_acm_zone_cards( $section_node );
			case 'acm_image_carousel':
				return $this->parse_acm_image_carousel( $section_node );
			case 'acm_featured_figure':
				return $this->parse_acm_featured_figure( $section_node );
			case 'acm_fleet_teaser':
				return $this->parse_acm_fleet_teaser( $section_node );
			case 'acm_betriebsmodelle':
				return $this->parse_acm_betriebsmodelle( $section_node );
			case 'acm_aog_callout':
				return $this->parse_acm_aog_callout( $section_node );
			case 'acm_area_cards':
				return $this->parse_acm_area_cards( $section_node );
			case 'acm_split_highlights':
				return $this->parse_acm_split_highlights( $section_node );
			case 'acm_stellen':
				return $this->parse_acm_stellen( $section_node );
			case 'acm_contact_command_grid':
				return $this->parse_acm_contact_command_grid( $section_node );
			case 'acm_contact_dept_section':
				return $this->parse_acm_contact_dept_section( $section_node );
			case 'acm_contact_inquiry_band':
				return $this->parse_acm_contact_inquiry_band( $section_node );
			case 'acm_departments':
				return $this->parse_acm_departments( $section_node );
			case 'acm_news_filter_bar':
				return $this->parse_acm_news_filter_bar( $section_node );
			case 'acm_news_grid':
				return $this->parse_acm_news_grid( $section_node );
			case 'acm_news_archive':
				return $this->parse_acm_news_archive( $section_node );

			default:
				return $this->empty_layout_value( $layout_key, $layout_schema );
		}
	}

	/**
	 * Return one empty layout value by schema.
	 *
	 * @param string              $layout_key    Layout key.
	 * @param array<string,mixed> $layout_schema Layout schema.
	 * @return array<string,mixed>
	 */
	protected function empty_layout_value( $layout_key, $layout_schema ) {
		$out                  = array();
		$out['acf_fc_layout'] = $layout_key;

		foreach ( (array) ( $layout_schema['fields'] ?? array() ) as $field_key => $definition ) {
			$out[ $field_key ] = Leadwerk_Content_Schema::get_default_value( $definition );
		}

		return $out;
	}

	/**
	 * Parse a hero section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_hero( $section_node ) {
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"hero-img-main")]//img[1]' );
		$button = $this->parse_button( $section_node );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h1[1]' ),
				'subtitle'  => $this->text( $section_node, './/*[contains(@class,"hero-subtitle")][1]' ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
			),
			$button
		);
	}

	/**
	 * Parse the home slider.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_hero_slider( $section_node ) {
		$slides = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"hero-slide")]' ) as $slide ) {
			$button      = $this->parse_button( $slide );
			$background  = $this->resolve_image_from_style( $this->attr( $slide, '.', 'style' ) );
			$slides[] = array_merge(
				array(
					'title'          => $this->html( $slide, './/h1[1]' ),
					'subtitle'       => $this->text( $slide, './/*[contains(@class,"hero-slide-subtitle")][1]' ),
					'background'     => $background['id'],
					'background_alt' => $background['alt'],
				),
				$button
			);
		}

		$services = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"hero-services")]//*[contains(@class,"service-item")]' ) as $item ) {
			$image = $this->parse_image_fields( $item, './/img[1]' );
			$link  = $this->parse_link_target( $this->attr( $item, '.', 'href' ) );
			$label = $this->html( $item, './/div[last()]' );
			$services[] = array(
				'title'       => $label,
				'description' => '',
				'icon'        => $image['id'],
				'icon_alt'    => $image['alt'],
				'page_key'    => $link['page_key'],
				'url'         => $link['url'],
			);
		}

		return array(
			'slides'     => $slides,
			'services'   => $services,
			'prev_label' => $this->attr( $section_node, './/*[contains(@class,"hero-slider-prev")][1]', 'aria-label' ),
			'next_label' => $this->attr( $section_node, './/*[contains(@class,"hero-slider-next")][1]', 'aria-label' ),
		);
	}

	/**
	 * Parse a shared pillars section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_pillars( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"pillar-card")]' ) as $card ) {
			$image  = $this->parse_image_fields( $card, './/img[1]' );
			$button = $this->parse_button( $card );
			$items[] = array_merge(
				array(
					'icon'        => $image['id'],
					'icon_alt'    => $image['alt'],
					'title'       => $this->text( $card, './/h3[1]' ),
					'description' => $this->html( $card, './/*[contains(@class,"card-desc")][1]' ),
				),
				$this->prefix_button_fields( $button, 'button' )
			);
		}

		return array(
			'title' => $this->html( $section_node, './/h2[1]' ),
			'items' => $items,
		);
	}

	/**
	 * Parse the home audience switcher.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_audience_switcher( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"fs-list")]//*[contains(@class,"fs-item")]' ) as $item ) {
			$src   = $this->attr( $item, '.', 'data-img' );
			$image = $this->resolve_image_field( $src );
			$items[] = array(
				'label'      => trim( preg_replace( '/\s+/', ' ', $item->textContent ) ),
				'card_title' => $this->attr( $item, '.', 'data-title' ),
				'body'       => '<p>' . esc_html( $this->attr( $item, '.', 'data-body' ) ) . '</p>',
				'image'      => $image['id'],
				'image_alt'  => $this->attr( $item, '.', 'data-title' ),
			);
		}

		return array(
			'title'      => $this->html( $section_node, './/*[contains(@class,"fs-intro")][1]' ),
			'prev_label' => $this->attr( $section_node, './/*[contains(@class,"fs-prev")][1]', 'aria-label' ),
			'next_label' => $this->attr( $section_node, './/*[contains(@class,"fs-next")][1]', 'aria-label' ),
			'items'      => $items,
		);
	}

	/**
	 * Parse why-acm section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_why_acm( $section_node ) {
		$blurbs = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$blurbs[] = array(
				'title'   => $this->text( $blurb, './/h4[1]' ),
				'content' => $this->first_available_html( $blurb, array( './/p[1]', './/*[contains(@class,"blurb-content")][1]' ) ),
			);
		}

		$image  = $this->parse_image_fields( $section_node, './/*[contains(@class,"why-acm-right")]//img[1]' );
		$button = $this->parse_button( $section_node, './/a[contains(@class,"btn-section")][1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'subtitle'  => $this->text( $section_node, './/*[contains(@class,"subtitle")][1]' ),
				'body'      => $this->html( $section_node, './/*[contains(@class,"about-text")][1]' ),
				'blurbs'    => $blurbs,
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
			),
			$button
		);
	}

	/**
	 * Parse how-it-works section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_how_it_works( $section_node ) {
		$steps = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"how-step")]' ) as $step ) {
			$steps[] = array(
				'icon_text' => $this->text( $step, './/*[contains(@class,"step-icon")][1]' ),
				'title'     => $this->text( $step, './/h4[1]' ),
				'content'   => $this->html( $step, './/p[1]' ),
			);
		}

		return array_merge(
			array(
				'title' => $this->html( $section_node, './/h2[1]' ),
				'steps' => $steps,
			),
			$this->parse_button( $section_node, './/a[contains(@class,"btn")][last()]' )
		);
	}

	/**
	 * Parse testimonials section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_testimonials( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"testimonial-card")]' ) as $card ) {
			$force_expanded = '';
			if ( $card instanceof DOMElement && $card->hasAttribute( 'data-force-expanded' ) ) {
				$force_expanded = strtolower( trim( (string) $card->getAttribute( 'data-force-expanded' ) ) );
			}

			$items[] = array(
				'quote'          => $this->html( $card, './/*[contains(@class,"testimonial-text")][1]' ),
				'toggle_enabled' => 'true' !== $force_expanded,
				'initials'       => $this->text( $card, './/*[contains(@class,"testimonial-initials")][1]' ),
				'name'           => $this->text( $card, './/*[contains(@class,"testimonial-name")][1]' ),
				'role'           => $this->text( $card, './/*[contains(@class,"testimonial-role")][1]' ),
			);
		}

		return array(
			'title'    => $this->html( $section_node, './/h2[1]' ),
			'subtitle' => $this->text( $section_node, './/*[contains(@class,"testimonials-subtitle")][1]' ),
			'items'    => $items,
		);
	}

	/**
	 * Parse FAQ section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_faq( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"accordion-item")]' ) as $item ) {
			$items[] = array(
				'question' => $this->text( $item, './/*[contains(@class,"accordion-title")][1]' ),
				'answer'   => $this->html( $item, './/*[contains(@class,"accordion-content")][1]' ),
			);
		}

		$background = $this->resolve_image_from_style( $this->attr( $section_node, './/*[contains(@class,"faq-left")][1]', 'style' ) );

		return array(
			'title'            => $this->html( $section_node, './/h2[1]' ),
			'intro'            => $this->text( $section_node, './/*[contains(@class,"faq-intro")][1]' ),
			'background_image' => $background['id'],
			'items'            => $items,
		);
	}

	/**
	 * Parse a banner CTA section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_banner_cta( $section_node ) {
		$background = $this->resolve_image_from_style( $this->attr( $section_node, '.', 'style' ) );

		return array_merge(
			array(
				'title'            => $this->html( $section_node, './/h2[1]' ),
				'body'             => $this->first_available_html( $section_node, array( './/p[1]', './/div[contains(@class,"anim")][2]' ) ),
				'background_image' => $background['id'],
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse about-bedeutet section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_about_bedeutet( $section_node ) {
		$columns    = $this->query_nodes( $section_node, './/*[contains(@class,"two-col")][1]/*[contains(@class,"col-text")]' );
		$left       = isset( $columns[0] ) ? $columns[0] : null;
		$right      = isset( $columns[1] ) ? $columns[1] : null;
		$right_items = array();

		if ( $right ) {
			foreach ( $this->query_nodes( $right, './/*[contains(@class,"blurb")]' ) as $blurb ) {
				$right_items[] = array(
					'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
				);
			}
		}

		return array_merge(
			array(
				'title'       => $this->first_available_html(
					$section_node,
					array(
						'.//*[contains(@class,"section-heading")][1]//h2[1]',
						'.//*[contains(@class,"two-col")][1]/*[contains(@class,"col-text")][1]//h2[1]',
					)
				),
				'left_body'   => $left ? $this->join_html( $this->query_nodes( $left, './p' ) ) : '',
				'right_title' => $right ? $this->text( $right, './/h4[1]' ) : '',
				'right_items' => $right_items,
			),
			$left ? $this->parse_button( $left ) : array()
		);
	}

	/**
	 * Parse a simple media/text section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_media_text( $section_node ) {
		$text  = $this->first_node( $section_node, './/*[contains(@class,"col-text")][1]' );
		$image = $this->first_node( $section_node, './/*[contains(@class,"col-img")][1]' );

		return array_merge(
			array(
				'title'          => $text ? $this->html( $text, './/h2[1]' ) : '',
				'body'           => $text ? $this->join_html( $this->query_nodes( $text, './p | .//*[contains(@class,"section-body")]/* | .//*[contains(@class,"section-subtitle")]' ) ) : '',
				'image'          => $image ? $this->parse_image_fields( $image, './/img[1]' )['id'] : 0,
				'image_alt'      => $image ? $this->parse_image_fields( $image, './/img[1]' )['alt'] : '',
				'image_position' => $this->is_image_first( $section_node ) ? 'left' : 'right',
			),
			$text ? $this->parse_button( $text ) : array()
		);
	}

	/**
	 * Parse workflow blurb section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_workflow_blurbs( $section_node ) {
		$columns = $this->query_nodes( $section_node, './/*[contains(@class,"two-col")][1]/*[contains(@class,"col-text")]' );
		$left    = isset( $columns[0] ) ? $columns[0] : null;
		$right   = isset( $columns[1] ) ? $columns[1] : null;
		$items   = array();

		if ( $right ) {
			foreach ( $this->query_nodes( $right, './/*[contains(@class,"blurb")]' ) as $blurb ) {
				$items[] = array(
					'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
				);
			}
		}

		return array_merge(
			array(
				'title'     => $left ? $this->html( $left, './/h2[1]' ) : '',
				'intro'     => $right ? $this->html( $right, './/p[1]' ) : '',
				'items'     => $items,
				'highlight' => $right ? $this->html( $right, './/*[contains(@class,"workflow-highlight")][1]' ) : '',
			),
			$left ? $this->parse_button( $left ) : array()
		);
	}

	/**
	 * Parse centered CTA.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_center_cta( $section_node ) {
		return array_merge(
			array(
				'title' => $this->html( $section_node, './/h2[1]' ),
				'body'  => $this->html( $section_node, './/p[1]' ),
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse tabs section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_tabs_section( $section_node ) {
		$tabs       = array();
		$nav_labels = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"tab-nav")]//button' ) as $button ) {
			$nav_labels[] = trim( $button->textContent );
		}

		$panels = $this->query_nodes( $section_node, './/*[contains(@class,"tab-panel")]' );
		foreach ( $panels as $index => $panel ) {
			$bullets = array();
			foreach ( $this->query_nodes( $panel, './/li' ) as $li ) {
				$bullets[] = array( 'text' => trim( $li->textContent ) );
			}

			$tabs[] = array(
				'title'   => isset( $nav_labels[ $index ] ) ? $nav_labels[ $index ] : $this->text( $panel, './/h4[1]' ),
				'intro'   => $this->html( $panel, './/h4[1]' ),
				'bullets' => $bullets,
				'outro'   => $this->html( $panel, './/p[last()]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"tabs-img")]//img[1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'intro'     => $this->html( $section_node, './/*[contains(@class,"favorites-title")]//p[1]' ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'tabs'      => $tabs,
			),
			$this->parse_button( $section_node, './/*[contains(@class,"favorites-cta")]//a[1]' )
		);
	}

	/**
	 * Parse retirement audience section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_retirement_audience( $section_node ) {
		$jump_links = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"audience-links")]//a' ) as $link ) {
			$href         = $this->attr( $link, '.', 'href' );
			$jump_links[] = array(
				'label'     => trim( $link->textContent ),
				'anchor_id' => ltrim( (string) strstr( $href, '#' ), '#' ),
			);
		}

		$cards = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"audience-card")]' ) as $card ) {
			$variant = 'default';
			$class   = $this->attr( $card, '.', 'class' );
			if ( false !== strpos( $class, 'audience-card--highlight' ) ) {
				$variant = 'highlight';
			} elseif ( false !== strpos( $class, 'audience-card--large' ) ) {
				$variant = 'large';
			} elseif ( false !== strpos( $class, 'audience-card--small' ) ) {
				$variant = 'small';
			}

			$blurbs = array();
			foreach ( $this->query_nodes( $card, './/*[contains(@class,"blurb")]' ) as $blurb ) {
				$blurbs[] = array(
					'title'   => $this->text( $blurb, './/h4[1]' ),
					'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
				);
			}

			$button   = $this->parse_button( $card );
			$cards[] = array_merge(
				array(
					'variant'   => $variant,
					'anchor_id' => $this->attr( $card, '.', 'id' ),
					'title'     => $this->text( $card, './/h3[1]' ),
					'intro'     => $this->html( $card, './p[1]' ),
					'blurbs'    => $blurbs,
				),
				$this->prefix_button_fields( $button, 'button' )
			);
		}

		return array(
			'title'      => $this->html( $section_node, './/h2[1]' ),
			'jump_links' => $jump_links,
			'cards'      => $cards,
		);
	}

	/**
	 * Parse concepts section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_concepts_section( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"concepts-image-wrap")]//img[1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'intro'     => $this->html( $section_node, './/*[contains(@class,"concepts-text-col")]//p[1]' ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'items'     => $items,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse a blurb + image section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_blurb_image_section( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/img[1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'intro'     => $this->first_available_html( $section_node, array( './/*[contains(@class,"section-heading")]//p[1]', './/*[contains(@class,"col-text")]//p[1]' ) ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'items'     => $items,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse approach tiles section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_approach_tiles( $section_node ) {
		$tiles = array();
		$tile_nodes = $this->query_nodes(
			$section_node,
			'.//*[contains(concat(" ", normalize-space(@class), " "), " approach-tiles-grid ")]/*[contains(concat(" ", normalize-space(@class), " "), " approach-tile ")]'
		);
		foreach ( $tile_nodes as $tile ) {
			$tiles[] = array(
				'title'   => $this->text( $tile, './/h4[1]' ),
				'content' => $this->html( $tile, './/*[contains(@class,"approach-tile__back")]//p[1]' ),
			);
		}
		$tiles = $this->normalize_approach_tiles( $tiles, count( $tile_nodes ) );

		return array_merge(
			array(
				'title' => $this->html( $section_node, './/h2[1]' ),
				'body'  => $this->html( $section_node, './/*[contains(@class,"col-text")]//p[1]' ),
				'tiles' => $tiles,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Normalize approach tiles so older broad class matches do not survive in stored data.
	 *
	 * @param array<int,array<string,mixed>> $tiles Source tiles.
	 * @param int                            $limit Expected maximum count.
	 * @return array<int,array<string,mixed>>
	 */
	protected function normalize_approach_tiles( $tiles, $limit = 0 ) {
		$normalized = array();
		$index_map  = array();
		$limit      = max( 0, (int) $limit );

		foreach ( (array) $tiles as $tile ) {
			if ( ! is_array( $tile ) ) {
				continue;
			}

			$title         = trim( wp_strip_all_tags( (string) ( $tile['title'] ?? '' ) ) );
			$content       = (string) ( $tile['content'] ?? '' );
			$content_text  = trim( wp_strip_all_tags( $content ) );
			$dedupe_source = '' !== $title ? $title : $content_text;
			if ( '' === $dedupe_source ) {
				continue;
			}

			$key = sanitize_title( $dedupe_source );
			if ( isset( $index_map[ $key ] ) ) {
				$existing_index   = $index_map[ $key ];
				$existing_tile    = $normalized[ $existing_index ];
				$existing_title   = trim( wp_strip_all_tags( (string) ( $existing_tile['title'] ?? '' ) ) );
				$existing_content = trim( wp_strip_all_tags( (string) ( $existing_tile['content'] ?? '' ) ) );
				if ( strlen( $content_text ) > strlen( $existing_content ) || ( '' === $existing_title && '' !== $title ) ) {
					$normalized[ $existing_index ] = array(
						'title'   => $title,
						'content' => $content,
					);
				}
				continue;
			}

			$index_map[ $key ] = count( $normalized );
			$normalized[]      = array(
				'title'   => $title,
				'content' => $content,
			);
		}

		if ( $limit > 0 ) {
			$normalized = array_slice( $normalized, 0, $limit );
		}

		return array_values( $normalized );
	}

	/**
	 * Parse timeline section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_timeline( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"timeline-item")]' ) as $item ) {
			$bullets = array();
			foreach ( $this->query_nodes( $item, './/li' ) as $li ) {
				$bullets[] = array( 'text' => trim( $li->textContent ) );
			}
			$items[] = array(
				'number'  => $this->text( $item, './/*[contains(@class,"timeline-item__number")][1]' ),
				'icon'    => $this->attr( $item, './/*[contains(@class,"timeline-item__icon")]//i[1]', 'class' ),
				'title'   => $this->text( $item, './/h4[1]' ),
				'body'    => $this->join_html( $this->query_nodes( $item, './/*[contains(@class,"timeline-item__card")][1]/p' ) ),
				'bullets' => $bullets,
			);
		}

		return array(
			'title' => $this->html( $section_node, './/h2[1]' ),
			'intro' => $this->first_available_html( $section_node, array( './/*[contains(@class,"section-heading")]//p[1]', './/p[1]' ) ),
			'items' => $items,
		);
	}

	/**
	 * Parse target groups section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_target_groups( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"target-group-grid")]//*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		return array_merge(
			array(
				'title'    => $this->html( $section_node, './/h2[1]' ),
				'subtitle' => $this->text( $section_node, './/*[contains(@class,"target-groups__subtitle")][1]' ),
				'items'    => $items,
				'summary'  => $this->html( $section_node, './/*[contains(@class,"target-groups__summary")][1]' ),
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse results section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_results_section( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/img[1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'intro'     => $this->html( $section_node, './/*[contains(@class,"col-text")]//p[1]' ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'items'     => $items,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse real estate intro section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_real_estate_intro( $section_node ) {
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"immobilien-intro-image")][1]' );
		$stats = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"intro-stat")]' ) as $stat ) {
			$stats[] = array(
				'value' => $this->text( $stat, './/*[contains(@class,"intro-stat__value")][1]' ),
				'label' => $this->text( $stat, './/*[contains(@class,"intro-stat__label")][1]' ),
			);
		}

		$headings = $this->query_nodes( $section_node, './/h2' );
		$paras    = $this->query_nodes( $section_node, './/p' );
		$blurbs   = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$blurbs[] = array(
				'title'   => $this->text( $blurb, './/h4[1]' ),
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		return array(
			'image'           => $image['id'],
			'image_alt'       => $image['alt'],
			'stats'           => $stats,
			'goals_title'     => isset( $headings[0] ) ? $this->html( $headings[0], '.' ) : '',
			'goals_body'      => isset( $paras[0] ) ? $this->html( $paras[0], '.' ) : '',
			'challenge_title' => isset( $headings[1] ) ? $this->html( $headings[1], '.' ) : '',
			'challenge_body'  => isset( $paras[1] ) ? $this->html( $paras[1], '.' ) : '',
			'blurbs'          => $blurbs,
		);
	}

	/**
	 * Parse calculator section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_calculator( $section_node ) {
		$cards = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"calc-v2__card")]' ) as $card ) {
			$rows = array();
			foreach ( $this->query_nodes( $card, './/*[contains(@class,"calc-v2__row")]' ) as $row ) {
				$modifier = '';
				$class    = $this->attr( $row, '.', 'class' );
				foreach ( array( 'plus', 'minus', 'subtotal', 'highlight', 'hero', 'accent', 'big' ) as $candidate ) {
					if ( false !== strpos( $class, 'calc-v2__row--' . $candidate ) || false !== strpos( $class, 'calc-v2__value--' . $candidate ) ) {
						$modifier = $candidate;
						break;
					}
				}
				$rows[] = array(
					'label'    => $this->text( $row, './/*[contains(@class,"calc-v2__label")][1]' ),
					'value'    => $this->text( $row, './/*[contains(@class,"calc-v2__value")][1]' ),
					'modifier' => $modifier,
				);
			}

			$cards[] = array(
				'title'    => $this->text( $card, './/*[contains(@class,"calc-v2__card-title")][1]' ),
				'icon'     => $this->attr( $card, './/*[contains(@class,"calc-v2__card-icon")]//i[1]', 'class' ),
				'featured' => false !== strpos( $this->attr( $card, '.', 'class' ), 'calc-v2__card--featured' ),
				'rows'     => $rows,
			);
		}

		$kpis = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"calc-v2__kpi")]' ) as $kpi ) {
			$kpis[] = array(
				'value'  => $this->text( $kpi, './/*[contains(@class,"calc-v2__kpi-value")][1]' ),
				'label'  => $this->text( $kpi, './/*[contains(@class,"calc-v2__kpi-label")][1]' ),
				'accent' => false !== strpos( $this->attr( $kpi, './/*[contains(@class,"calc-v2__kpi-value")][1]', 'class' ), '--accent' ),
			);
		}

		return array(
			'title'    => $this->html( $section_node, './/h2[1]' ),
			'subtitle' => $this->html( $section_node, './/*[contains(@class,"calc-v2__subtitle")][1]' ),
			'cards'    => $cards,
			'kpis'     => $kpis,
		);
	}

	/**
	 * Parse case highlight section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_case_highlight( $section_node ) {
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"adrian-img")][1]' );

		return array(
			'image'     => $image['id'],
			'image_alt' => $image['alt'],
			'title'     => $this->html( $section_node, './/*[contains(@class,"adrian-heading")][1]' ),
			'body'      => $this->html( $section_node, './/*[contains(@class,"adrian-text")][1]' ),
			'quote'     => $this->html( $section_node, './/*[contains(@class,"adrian-quote")][1]' ),
		);
	}

	/**
	 * Parse dark CTA section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_dark_cta( $section_node ) {
		return array_merge(
			array(
				'title' => $this->html( $section_node, './/h2[1]' ),
				'body'  => $this->html( $section_node, './/p[1]' ),
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse responsibility section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_responsibility( $section_node ) {
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"responsibility-bottom__image")]//img[1]' );
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/*[contains(@class,"responsibility-top__main")]//h2[1]' ),
				'intro'     => $this->html( $section_node, './/*[contains(@class,"responsibility-top__main")]//p[1]' ),
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'lead'      => $this->html( $section_node, './/*[contains(@class,"responsibility-lead")][1]' ),
				'items'     => $items,
				'note'      => $this->html( $section_node, './/*[contains(@class,"responsibility-note")][1]' ),
			),
			$this->parse_button( $section_node, './/*[contains(@class,"responsibility-top__side")]//a[1]' )
		);
	}

	/**
	 * Parse new-phase section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_new_phase( $section_node ) {
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"new-phase-img")][1]' );
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		return array_merge(
			array(
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'title'     => $this->html( $section_node, './/*[contains(@class,"new-phase-heading")][1]' ),
				'body'      => $this->html( $section_node, './/*[contains(@class,"new-phase-text")][1]' ),
				'items'     => $items,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse outcomes section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_outcomes( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"flip-box")]' ) as $box ) {
			$items[] = array(
				'icon'    => $this->attr( $box, './/*[contains(@class,"flip-box-icon")]//i[1]', 'class' ),
				'title'   => $this->text( $box, './/h4[1]' ),
				'content' => $this->html( $box, './/*[contains(@class,"flip-box-back")]//p[1]' ),
			);
		}

		return array(
			'title' => $this->html( $section_node, './/h2[1]' ),
			'intro' => $this->first_available_html( $section_node, array( './/*[contains(@class,"section-heading")]//p[1]', './/*[contains(@class,"outcomes-text")][1]' ) ),
			'body'  => $this->html( $section_node, './/*[contains(@class,"outcomes-text")][1]' ),
			'items' => $items,
		);
	}

	/**
	 * Parse image target groups section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_target_groups_image( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$items[] = array(
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/img[1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'intro'     => $this->html( $section_node, './/*[contains(@class,"target-intro")][1]' ),
				'items'     => $items,
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse audience cards section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_audience_cards( $section_node ) {
		$items = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"audience-card")]' ) as $card ) {
			$class = $this->attr( $card, '.', 'class' );
			$items[] = array_merge(
				array(
					'title'    => $this->text( $card, './/h3[1]' ),
					'content'  => $this->html( $card, './/p[1]' ),
					'is_empty' => false !== strpos( $class, 'audience-card--empty' ),
				),
				$this->prefix_button_fields( $this->parse_button( $card ), 'button' )
			);
		}

		return array(
			'title' => $this->html( $section_node, './/h2[1]' ),
			'items' => $items,
		);
	}

	/**
	 * Parse media blurbs section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_media_blurbs( $section_node ) {
		$blurbs = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"blurb")]' ) as $blurb ) {
			$blurbs[] = array(
				'title'   => $this->text( $blurb, './/h4[1]' ),
				'content' => $this->html( $blurb, './/*[contains(@class,"blurb-content")][1]' ),
			);
		}
		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"feature-image")][1]' );

		return array_merge(
			array(
				'title'     => $this->html( $section_node, './/h2[1]' ),
				'subtitle'  => $this->text( $section_node, './/*[contains(@class,"section-subtitle")][1]' ),
				'body'      => $this->html( $section_node, './/*[contains(@class,"section-body")][1]' ),
				'blurbs'    => $blurbs,
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse invest detail section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_invest_detail( $section_node ) {
		$trends = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"megatrend-grid")]//*[contains(@class,"flip-box")]' ) as $box ) {
			$trends[] = array(
				'icon'    => $this->attr( $box, './/*[contains(@class,"flip-box-icon")]//i[1]', 'class' ),
				'title'   => $this->text( $box, './/h4[1]' ),
				'content' => $this->html( $box, './/*[contains(@class,"flip-box-back")]//p[1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"feature-image")][1]' );

		return array_merge(
			array(
				'title'           => $this->html( $section_node, './/*[contains(@class,"two-col--invest-intro")]//h2[1]' ),
				'subtitle'        => $this->text( $section_node, './/*[contains(@class,"two-col--invest-intro")]//*[contains(@class,"section-subtitle")][1]' ),
				'body'            => $this->html( $section_node, './/*[contains(@class,"two-col--invest-intro")]//*[contains(@class,"section-body")][1]' ),
				'image'           => $image['id'],
				'image_alt'       => $image['alt'],
				'explainer_title' => $this->html( $section_node, './/*[contains(@class,"invest-explainer")]//h2[1]' ),
				'explainer_body'  => $this->html( $section_node, './/*[contains(@class,"invest-explainer")]//p[1]' ),
				'explainer_sub'   => $this->text( $section_node, './/*[contains(@class,"invest-explainer")]//h3[1]' ),
				'trends'          => $trends,
				'outro'           => $this->html( $section_node, './/*[contains(@class,"invest-outro")][1]' ),
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse tax detail section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_tax_detail( $section_node ) {
		$steps = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"step-card")]' ) as $step ) {
			$icon = $this->text( $step, './/*[contains(@class,"step-icon")][1]' );
			if ( '' === trim( $icon ) ) {
				$icon = $this->attr( $step, './/*[contains(@class,"step-icon")]//i[1]', 'class' );
			}
			$steps[] = array(
				'icon'    => $icon,
				'title'   => $this->text( $step, './/h4[1]' ),
				'content' => $this->html( $step, './/p[1]' ),
			);
		}

		$image = $this->parse_image_fields( $section_node, './/*[contains(@class,"feature-image")][1]' );

		return array_merge(
			array(
				'title'       => $this->html( $section_node, './/*[contains(@class,"two-col--tax-intro")]//h2[1]' ),
				'subtitle'    => $this->text( $section_node, './/*[contains(@class,"two-col--tax-intro")]//*[contains(@class,"section-subtitle")][1]' ),
				'body'        => $this->html( $section_node, './/*[contains(@class,"section-body")][1]' ),
				'how_title'   => $this->text( $section_node, './/*[contains(@class,"two-col--tax-intro")]//h3[1]' ),
				'how_body'    => $this->html( $section_node, './/*[contains(@class,"section-subtitle--dense")][1]' ),
				'image'       => $image['id'],
				'image_alt'   => $image['alt'],
				'steps_title' => $this->html( $section_node, './/*[contains(@class,"section-heading--center")]//h2[1]' ),
				'steps'       => $steps,
			),
			$this->parse_button( $section_node )
		);
	}

	/**
	 * Parse contact section.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_contact_main( $section_node ) {
		$info_cards = array();
		foreach ( $this->query_nodes( $section_node, './/*[contains(@class,"kontakt-info-card")]' ) as $card ) {
			$href = $this->attr( $card, './/*[contains(@class,"kontakt-info-card__link")][1]', 'href' );
			$type = 'address';
			if ( 0 === strpos( $href, 'tel:' ) ) {
				$type = 'phone';
			} elseif ( 0 === strpos( $href, 'mailto:' ) ) {
				$type = 'email';
			} elseif ( '' !== trim( $href ) ) {
				$type = 'link';
			}
			$info_cards[] = array(
				'title' => $this->text( $card, './/h4[1]' ),
				'type'  => $type,
				'value' => $this->html( $card, './/p[1]' ),
				'href'  => $href,
			);
		}

		$privacy_href = $this->attr( $section_node, './/label[@for="contact-privacy"]//a[1]', 'href' );

		return array(
			'title'         => $this->html( $section_node, './/h2[1]' ),
			'intro'         => $this->html( $section_node, './/*[contains(@class,"kontakt-form-intro")][1]' ),
			'submit_label'  => $this->text( $section_node, './/*[contains(@class,"btn-submit")][1]' ),
			'privacy_label' => $this->html( $section_node, './/label[@for="contact-privacy"][1]' ),
			'privacy_page'  => $this->map_href_to_source_key( $privacy_href ),
			'info_cards'    => $info_cards,
		);
	}

	/**
	 * Resolve a source file path.
	 *
	 * @param string $relative_file Relative file.
	 * @return string
	 */
	protected function resolve_source_path( $relative_file ) {
		return '' === $this->source_root ? '' : $this->source_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $relative_file, '/\\' ) );
	}

	/**
	 * Create DOM and XPath.
	 *
	 * @param string $html HTML.
	 * @return array{0:DOMDocument,1:DOMXPath}
	 */
	protected function create_dom_xpath( $html ) {
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		return array( $dom, new DOMXPath( $dom ) );
	}

	/**
	 * Extract direct body sections.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<int,DOMNode>
	 */
	protected function extract_body_sections( $xpath ) {
		$nodes = array();

		// ACM HTML uses <body><main><section> hierarchy.
		// Legacy FINORA HTML used <body><section> directly.
		// Try <main> wrapper first, fall back to direct body children.
		$main_sections = $this->query_nodes( $xpath, '//body/main/section' );
		if ( ! empty( $main_sections ) ) {
			return $main_sections;
		}

		foreach ( $this->query_nodes( $xpath, '//body/section' ) as $node ) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Query nodes from DOMXPath or DOMNode.
	 *
	 * @param DOMXPath|DOMNode $context Context.
	 * @param string           $query   XPath query.
	 * @return array<int,DOMNode>
	 */
	protected function query_nodes( $context, $query ) {
		if ( $context instanceof DOMXPath ) {
			$list = $context->query( $query );
		} else {
			$list = ( new DOMXPath( $context->ownerDocument ) )->query( $query, $context );
		}

		$out = array();
		if ( $list instanceof DOMNodeList ) {
			foreach ( $list as $node ) {
				if ( $node instanceof DOMNode ) {
					$out[] = $node;
				}
			}
		}
		return $out;
	}

	/**
	 * Return the first matching node.
	 *
	 * @param DOMXPath|DOMNode $context Context.
	 * @param string           $query   XPath query.
	 * @return DOMNode|null
	 */
	protected function first_node( $context, $query ) {
		$nodes = $this->query_nodes( $context, $query );
		return ! empty( $nodes[0] ) ? $nodes[0] : null;
	}

	/**
	 * Return text content.
	 *
	 * @param DOMXPath|DOMNode $context Context.
	 * @param string           $query   XPath query.
	 * @return string
	 */
	protected function text( $context, $query ) {
		$node = $this->first_node( $context, $query );
		return $node ? trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) : '';
	}

	/**
	 * Return one attribute value.
	 *
	 * @param DOMXPath|DOMNode $context Context.
	 * @param string           $query   XPath query.
	 * @param string           $attr    Attribute name.
	 * @return string
	 */
	protected function attr( $context, $query, $attr ) {
		$node = $this->first_node( $context, $query );
		return ( $node instanceof DOMElement && $node->hasAttribute( $attr ) ) ? trim( (string) $node->getAttribute( $attr ) ) : '';
	}

	/**
	 * Return one node as inner HTML.
	 *
	 * @param DOMXPath|DOMNode $context Context.
	 * @param string           $query   XPath query.
	 * @return string
	 */
	protected function html( $context, $query ) {
		$node = $this->first_node( $context, $query );
		return $node ? $this->save_inner_html( $node ) : '';
	}

	/**
	 * Return the first non-empty HTML match from a list.
	 *
	 * @param DOMNode  $context Context node.
	 * @param string[] $queries Queries.
	 * @return string
	 */
	protected function first_available_html( $context, $queries ) {
		foreach ( $queries as $query ) {
			$html = $this->html( $context, $query );
			if ( '' !== trim( $html ) ) {
				return $html;
			}
		}
		return '';
	}

	/**
	 * Save inner HTML for one node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	protected function save_inner_html( $node ) {
		if ( $node instanceof DOMDocument ) {
			return '';
		}

		if ( $node instanceof DOMElement ) {
			$html = '';
			foreach ( $node->childNodes as $child ) {
				$html .= $node->ownerDocument->saveHTML( $child );
			}
			return $html;
		}

		return $node->ownerDocument->saveHTML( $node );
	}

	/**
	 * Join multiple nodes as HTML.
	 *
	 * @param array<int,DOMNode> $nodes Nodes.
	 * @return string
	 */
	protected function join_html( $nodes ) {
		$html = '';
		foreach ( $nodes as $node ) {
			$html .= $node->ownerDocument->saveHTML( $node );
		}
		return $html;
	}

	/**
	 * Parse image fields.
	 *
	 * @param DOMNode $context Context node.
	 * @param string  $query   XPath query.
	 * @return array{id:int,alt:string}
	 */
	protected function parse_image_fields( $context, $query ) {
		$src = $this->attr( $context, $query, 'src' );
		return array(
			'id'  => $this->resolve_image_field( $src )['id'],
			'alt' => $this->attr( $context, $query, 'alt' ),
		);
	}

	/**
	 * Resolve an image source path to an attachment field payload.
	 *
	 * @param string $src Raw src.
	 * @return array{id:int,alt:string}
	 */
	protected function resolve_image_field( $src ) {
		$path = $this->resolve_asset_source_path( $src );
		return array(
			'id'  => $this->get_attachment_id_by_source( $path ),
			'alt' => '',
		);
	}

	/**
	 * Resolve a background image from inline style.
	 *
	 * @param string $style Style string.
	 * @return array{id:int,alt:string}
	 */
	protected function resolve_image_from_style( $style ) {
		$src = '';
		if ( preg_match( '/url\\([\'"]?([^\'")]+)[\'"]?\\)/i', (string) $style, $matches ) ) {
			$src = (string) $matches[1];
		}

		return $this->resolve_image_field( $src );
	}

	/**
	 * Parse a button target and label.
	 *
	 * @param DOMNode $context Context node.
	 * @param string  $query   Button query.
	 * @return array<string,string>
	 */
	protected function parse_button( $context, $query = './/a[contains(@class,"btn")][1]' ) {
		$node = $this->first_node( $context, $query );
		if ( ! $node instanceof DOMElement ) {
			return array(
				'cta_label'    => '',
				'cta_page_key' => '',
				'cta_url'      => '',
			);
		}

		$link = $this->parse_link_target( (string) $node->getAttribute( 'href' ) );
		return array(
			'cta_label'    => trim( preg_replace( '/\s+/', ' ', $node->textContent ) ),
			'cta_page_key' => $link['page_key'],
			'cta_url'      => $link['url'],
		);
	}

	/**
	 * Rename CTA fields for nested repeaters.
	 *
	 * @param array<string,string> $button Button payload.
	 * @param string               $prefix Prefix.
	 * @return array<string,string>
	 */
	protected function prefix_button_fields( $button, $prefix ) {
		return array(
			$prefix . '_label'    => (string) ( $button['cta_label'] ?? '' ),
			$prefix . '_page_key' => (string) ( $button['cta_page_key'] ?? '' ),
			$prefix . '_url'      => (string) ( $button['cta_url'] ?? '' ),
		);
	}

	/**
	 * Parse an href into internal page key or URL.
	 *
	 * @param string $href Raw href.
	 * @return array{page_key:string,url:string}
	 */
	protected function parse_link_target( $href ) {
		return array(
			'page_key' => $this->map_href_to_source_key( $href ),
			'url'      => $this->is_internal_html_link( $href ) ? '' : trim( (string) $href ),
		);
	}

	/**
	 * Whether an href points to an internal imported HTML file.
	 *
	 * @param string $href Href.
	 * @return bool
	 */
	protected function is_internal_html_link( $href ) {
		return '' !== $this->map_href_to_source_key( $href );
	}

	/**
	 * Map one href to a FINORA source key.
	 *
	 * @param string $href Href.
	 * @return string
	 */
	protected function map_href_to_source_key( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $href ) {
			return '';
		}

		if ( false !== strpos( $href, '#' ) ) {
			$href = substr( $href, 0, (int) strpos( $href, '#' ) );
		}

		$href = trim( str_replace( '\\', '/', $href ) );
		$href = preg_replace( '#^(?:https?:)?//[^/]+/#i', '', $href );
		$href = ltrim( (string) $href, '/' );

		$map = array(
			'index.html'                => 'acm-index-v1',
			'thats-acm.html'            => 'acm-thats-acm-v1',
			'charter.html'              => 'acm-charter-v1',
			'global-7500.html'          => 'acm-global-7500-v1',
			'global-6000.html'          => 'acm-global-6000-v1',
			'global-xrs.html'           => 'acm-global-xrs-v1',
			'aircraft-management.html'  => 'acm-aircraft-v1',
			'maintenance.html'          => 'acm-maintenance-v1',
			'karriere.html'             => 'acm-careers-v1',
			'kontakt.html'              => 'acm-contact-v1',
			'news.html'                 => 'acm-news-v1',
			'impressum.html'            => 'acm-impressum-v1',
			'datenschutz.html'          => 'acm-datenschutz-v1',
			'en/index.html'             => 'acm-index-v1',
			'en/thats-acm.html'         => 'acm-thats-acm-v1',
			'en/charter.html'           => 'acm-charter-v1',
			'en/global-7500.html'       => 'acm-global-7500-v1',
			'en/global-6000.html'       => 'acm-global-6000-v1',
			'en/global-xrs.html'        => 'acm-global-xrs-v1',
			'en/aircraft-management.html' => 'acm-aircraft-v1',
			'en/maintenance.html'       => 'acm-maintenance-v1',
			'en/karriere.html'          => 'acm-careers-v1',
			'en/kontakt.html'           => 'acm-contact-v1',
			'en/news.html'              => 'acm-news-v1',
			'en/impressum.html'         => 'acm-impressum-v1',
			'en/datenschutz.html'       => 'acm-datenschutz-v1',
		);

		return isset( $map[ $href ] ) ? $map[ $href ] : '';
	}

	/**
	 * Resolve a relative asset path.
	 *
	 * @param string $raw Raw path.
	 * @return string
	 */
	protected function resolve_asset_source_path( $raw ) {
		$raw = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $raw || preg_match( '#^(?:https?:)?//#i', $raw ) || preg_match( '#^(?:mailto:|tel:|data:|javascript:|#)#i', $raw ) ) {
			return '';
		}

		$raw = str_replace( '\\', '/', $raw );
		$raw = preg_replace( '#^\./#', '', $raw );
		return ltrim( (string) $raw, '/' );
	}

	/**
	 * Get attachment ID by source path.
	 *
	 * @param string $path Relative source path.
	 * @return int
	 */
	protected function get_attachment_id_by_source( $path ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return 0;
		}

		if ( isset( $this->attachment_cache[ $path ] ) ) {
			return $this->attachment_cache[ $path ];
		}

		$id = 0;
		if ( $this->media_importer ) {
			$id = (int) $this->media_importer->get_attachment_id_by_source( $path );
		}

		if ( ! $id ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_query'     => array(
						array(
							'key'   => 'leadwerk_source_path',
							'value' => $path,
						),
					),
				)
			);
			$ids = $query->get_posts();
			$id  = ! empty( $ids ) ? (int) $ids[0] : 0;
		}

		if ( ! $id && $this->media_importer ) {
			$id = (int) $this->media_importer->import_file( $path );
		}

		$this->attachment_cache[ $path ] = $id;
		return $id;
	}

	/**
	 * Whether the first column is an image column.
	 *
	 * @param DOMNode $section_node Section node.
	 * @return bool
	 */
	protected function is_image_first( $section_node ) {
		$children = $this->query_nodes( $section_node, './/*[contains(@class,"two-col")][1]/*' );
		if ( empty( $children[0] ) || ! $children[0] instanceof DOMElement ) {
			return false;
		}
		return false !== strpos( (string) $children[0]->getAttribute( 'class' ), 'col-img' );
	}

	/* ═══════════════════════════════════════════════════════════
	 * ACM AIR CHARTER – Section Parsers
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * ACM hero video section (index page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_hero_video( $node ) {
		$title    = $this->html( $node, './/h1[1]' );
		$subtitle = $this->text( $node, './/*[contains(@class,"hero-subtitle")][1]' );
		if ( '' === $subtitle ) {
			$subtitle = $this->text( $node, './/p[1]' );
		}

		$video  = $this->attr( $node, './/video[1]//source[1]', 'src' );
		if ( '' === $video ) {
			$video = $this->attr( $node, './/video[1]', 'src' );
		}
		$poster     = $this->parse_image_fields( $node, './/video[1]' );
		$poster_src = $this->attr( $node, './/video[1]', 'poster' );

		// #region agent log
		$log_path = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'debug-eeb156.log';
		$payload  = array(
			'sessionId'    => 'eeb156',
			'hypothesisId' => 'H_import_hero_video',
			'location'     => 'class-leadwerk-acf-filler.php:parse_acm_hero_video',
			'message'      => 'Parsed shell hero video fields for index import',
			'data'         => array(
				'video_src' => is_string( $video ) ? $video : '',
			),
			'timestamp'    => (int) round( microtime( true ) * 1000 ),
		);
		@file_put_contents( $log_path, ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ) . "\n", FILE_APPEND );
		// #endregion

		return array(
			'title'      => $title,
			'subtitle'   => $subtitle,
			'video'      => urldecode( $video ),
			'poster'     => $poster_src !== '' ? $this->resolve_image_field( $poster_src )['id'] : 0,
			'poster_alt' => 'ACM AIR CHARTER Hero',
		);
	}

	/**
	 * ACM hero statement quote block.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_hero_statement( $node ) {
		return array(
			'quote' => $this->html( $node, './/p[1]' ),
		);
	}

	/**
	 * ACM full-bleed promo (image + overlay headline + CTA).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_fullwidth_promo( $node ) {
		$img    = $this->parse_image_fields( $node, './/img[not(contains(@class,"section-emblem"))][1]' );
		$button = $this->parse_button( $node, './/a[contains(@class,"inline-flex")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"btn-overlay-white")][1]' );
		}

		return array_merge(
			array(
				'title'     => $this->html( $node, './/h2[1]' ),
				'image'     => $img['id'],
				'image_alt' => $img['alt'],
			),
			$button
		);
	}

	/**
	 * ACM centered intro (That's ACM).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_intro_centered( $node ) {
		$body       = '';
		$is_first   = true;
		$p_class_mb = 'text-stone-500 leading-relaxed max-w-3xl mx-auto mb-6';
		$p_class    = 'text-stone-500 leading-relaxed max-w-3xl mx-auto';
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"text-center") and contains(@class,"scroll-reveal")]//p' ) as $p ) {
			if ( ! $p instanceof DOMNode ) {
				continue;
			}
			$inner = trim( $this->save_inner_html( $p ) );
			if ( '' === $inner ) {
				continue;
			}
			$cls  = $is_first ? $p_class_mb : $p_class;
			$body .= '<p class="' . esc_attr( $cls ) . '">' . $inner . '</p>';
			$is_first = false;
		}

		return array(
			'label' => $this->text( $node, './/*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/h2[1]' ),
			'body'  => $body,
		);
	}

	/**
	 * ACM certifications grid.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_certifications_grid( $node ) {
		$items = array();
		$card_xpath = './/div[contains(@class,"grid") and (contains(@class,"lg:grid-cols-3") or contains(@class,"lg:grid-cols-4") or (contains(@class,"md:grid-cols-2") and (contains(@class,"gap-10") or contains(@class,"gap-12") or contains(@class,"gap-8"))))]//div[contains(@class,"scroll-reveal") and not(contains(@class,"fleet-card"))]';
		foreach ( $this->query_nodes( $node, $card_xpath ) as $card ) {
			$svg = $this->first_node( $card, './/*[(contains(@class,"w-12") or contains(@class,"w-16"))]//svg[1] | .//svg[1]' );
			$icon = '';
			if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
				$icon = $svg->ownerDocument->saveHTML( $svg );
			}
			$title = $this->text( $card, './/*[contains(@class,"cert-title")][1] | .//h3[1] | .//h4[1]' );
			$content = $this->html( $card, './/*[contains(@class,"cert-title")][1]/following-sibling::p[1] | .//h3[1]/following-sibling::p[1] | .//h4[1]/following-sibling::p[1]' );
			$items[] = array(
				'title'   => $title,
				'content' => $content,
				'icon'    => trim( $icon ),
			);
		}

		$intro = '';
		$intro_node = $this->first_node( $node, './/div[contains(@class,"text-center")]//h2[1]/following-sibling::p[1]' );
		if ( $intro_node instanceof DOMElement && $intro_node->ownerDocument ) {
			$intro = trim( $intro_node->ownerDocument->saveHTML( $intro_node ) );
		}

		return array(
			'label' => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1]' ),
			'intro' => $intro,
			'items' => $items,
		);
	}

	/**
	 * ACM split section with image column and icon feature list (Charter operative).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_split_icon_features( $node ) {
		$img_wrap = $this->first_node( $node, './/div[contains(@class,"image-zoom-bleed")][1]' );
		$content  = $this->first_node( $node, './/div[contains(@class,"content-box-outer")]//div[contains(@class,"max-w-xl")][1]' );
		$grid     = $this->first_node( $node, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]' );

		$image_left  = true;
		$image_right = false;
		$img_col     = null;

		if ( $grid instanceof DOMElement ) {
			foreach ( $this->query_nodes( $grid, './div' ) as $col ) {
				if ( ! $col instanceof DOMElement ) {
					continue;
				}
				if ( $this->first_node( $col, './/img[1] | .//div[contains(@class,"image-zoom-bleed")]' ) ) {
					$img_col = $col;
					$cls     = ' ' . $col->getAttribute( 'class' ) . ' ';
					if ( false !== strpos( $cls, 'lg:order-2' ) ) {
						$image_right = true;
						$image_left  = false;
					} else {
						$image_left = true;
					}
					break;
				}
			}
		}

		// Transparenz: kein content-box-outer, Bild+Text in max-w-6xl-Grid.
		if ( ! $content instanceof DOMNode ) {
			$wrap = $this->first_node( $node, './/div[contains(@class,"max-w-6xl")][1]' );
			$g2   = $wrap instanceof DOMNode
				? $this->first_node( $wrap, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]' )
				: null;
			if ( $g2 instanceof DOMElement ) {
				$txt_col = null;
				$img_col = null;
				foreach ( $this->query_nodes( $g2, './div' ) as $col ) {
					if ( ! $col instanceof DOMElement ) {
						continue;
					}
					if ( $this->first_node( $col, './/h2[1]' ) && $this->first_node( $col, './/*[contains(@class,"section-label")][1]' ) ) {
						$txt_col = $col;
					}
					if ( $this->first_node( $col, './/img[not(contains(@class,"section-emblem"))][1]' ) ) {
						$img_col = $col;
					}
				}
				if ( $txt_col instanceof DOMElement ) {
					$content = $txt_col;
				}
				if ( $img_col instanceof DOMElement ) {
					$cls = ' ' . $img_col->getAttribute( 'class' ) . ' ';
					if ( false !== strpos( $cls, 'lg:order-2' ) ) {
						$image_right = true;
						$image_left  = false;
					} else {
						$image_left  = true;
						$image_right = false;
					}
				}
			}
		}

		$img_source = ( $img_wrap instanceof DOMNode ) ? $img_wrap : ( $img_col instanceof DOMElement ? $img_col : $node );
		$img        = $this->parse_image_fields( $img_source, './/img[not(contains(@class,"section-emblem"))][1]' );

		$label = '';
		$title = '';
		$intro = '';
		$items = array();

		if ( $content instanceof DOMNode ) {
			$label = $this->text( $content, './/*[contains(@class,"section-label")][1]' );
			$title = $this->html( $content, './/h2[1]' );
			$h2    = $this->first_node( $content, './/h2[1]' );
			$sy6   = $this->first_node( $content, './/div[contains(@class,"space-y-6")][1]' );
			$sy8   = $this->first_node( $content, './/div[contains(@class,"space-y-8")][1]' );
			$brk   = $this->first_node( $content, './/div[contains(@class,"grid") and contains(@class,"sm:grid-cols-2")][1]' );
			if ( $h2 instanceof DOMElement && $h2->ownerDocument ) {
				$chunks = array();
				$el     = $h2->nextSibling;
				while ( $el ) {
					if ( $el === $sy6 || $el === $sy8 || $el === $brk ) {
						break;
					}
					if ( $el instanceof DOMElement && 'p' === strtolower( $el->tagName ) ) {
						$chunks[] = trim( $el->ownerDocument->saveHTML( $el ) );
					}
					$el = $el->nextSibling;
				}
				$intro = implode( '', $chunks );
			}

			foreach ( $this->query_nodes( $content, './/div[contains(@class,"space-y-6")]//div[contains(@class,"flex") and contains(@class,"items-start")]' ) as $row ) {
				$svg  = $this->first_node( $row, './/svg[1]' );
				$icon = '';
				if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
					$icon = trim( $svg->ownerDocument->saveHTML( $svg ) );
				}
				$items[] = array(
					'icon'    => $icon,
					'title'   => $this->text( $row, './/h4[1]' ),
					'content' => $this->html( $row, './/h4[1]/following-sibling::p[1]' ),
				);
			}

			if ( empty( $items ) ) {
				foreach ( $this->query_nodes( $content, './/div[contains(@class,"space-y-8")]//div[./h4[1]]' ) as $row ) {
					$items[] = array(
						'icon'    => '',
						'title'   => $this->text( $row, './/h4[1]' ),
						'content' => $this->html( $row, './/h4[1]/following-sibling::p[1]' ),
					);
				}
			}

			if ( empty( $items ) && $brk instanceof DOMElement ) {
				foreach ( $this->query_nodes( $content, './/div[contains(@class,"sm:grid-cols-2")]//div[contains(@class,"flex") and contains(@class,"items-start")]' ) as $row ) {
					$svg  = $this->first_node( $row, './/svg[1]' );
					$icon = '';
					if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
						$icon = trim( $svg->ownerDocument->saveHTML( $svg ) );
					}
					$title_el = $this->first_node( $row, './/h3[contains(@class,"cert-title")][1] | .//h4[1]' );
					$t        = '';
					if ( $title_el instanceof DOMElement ) {
						$t = trim( $title_el->textContent );
					}
					$items[] = array(
						'icon'    => $icon,
						'title'   => $t,
						'content' => $this->html( $row, './/h3[contains(@class,"cert-title")][1]/following-sibling::p[1] | .//h4[1]/following-sibling::p[1]' ),
					);
				}
			}
		}

		return array(
			'image_left'  => $image_left ? '1' : '0',
			'image_right' => $image_right ? 1 : 0,
			'image'       => $img['id'],
			'image_alt'   => $img['alt'],
			'label'       => $label,
			'title'       => $title,
			'intro'       => $intro,
			'items'       => $items,
		);
	}

	/**
	 * ACM capabilities-style multi split rows.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_split_rows( $node ) {
		$intro_wrap = $this->first_node( $node, './/div[contains(@class,"max-w-6xl")]//div[contains(@class,"text-center")][1]' );
		$intro_label = '';
		$intro_title = '';
		if ( $intro_wrap instanceof DOMNode ) {
			$intro_label = $this->text( $intro_wrap, './/*[contains(@class,"section-label")][1]' );
			$intro_title = $this->html( $intro_wrap, './/h2[1]' );
		}

		$rows = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"max-w-6xl")]//div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2") and contains(@class,"gap-12")]' ) as $grid ) {
			$img_wrap = $this->first_node( $grid, './/div[contains(@class,"image-zoom-wrap")][1]' );
			if ( ! $img_wrap instanceof DOMElement ) {
				continue;
			}
			$img        = $this->parse_image_fields( $grid, './/img[contains(@class,"img-no-filter")][1] | .//div[contains(@class,"image-zoom-wrap")]//img[1]' );
			$class_attr = (string) $img_wrap->getAttribute( 'class' );
			$image_right = ( false !== strpos( $class_attr, 'lg:order-2' ) );

			$text_col = null;
			foreach ( $this->query_nodes( $grid, './div' ) as $col ) {
				if ( ! $col instanceof DOMElement ) {
					continue;
				}
				if ( $col->getAttribute( 'class' ) && false !== strpos( $col->getAttribute( 'class' ), 'image-zoom-wrap' ) ) {
					continue;
				}
				if ( $this->first_node( $col, './/*[contains(@class,"section-label")][1]' ) instanceof DOMNode ) {
					$text_col = $col;
					break;
				}
			}
			if ( ! $text_col instanceof DOMNode ) {
				continue;
			}

			$body_chunks = array();
			foreach ( $this->query_nodes( $text_col, './/p' ) as $p ) {
				$inner = trim( $this->save_inner_html( $p ) );
				if ( '' !== $inner ) {
					$body_chunks[] = $inner;
				}
			}
			$body_html = '';
			$last_i    = count( $body_chunks ) - 1;
			foreach ( $body_chunks as $pi => $inner ) {
				$mb = ( $pi < $last_i ) ? ' mb-4' : '';
				$body_html .= '<p class="text-stone-500 leading-relaxed' . $mb . '">' . $inner . '</p>';
			}

			$rows[] = array(
				'label'       => $this->text( $text_col, './/*[contains(@class,"section-label")][1]' ),
				'title'       => $this->html( $text_col, './/h3[1]' ),
				'body'        => $body_html,
				'image'       => $img['id'],
				'image_alt'   => $img['alt'],
				'image_right' => $image_right,
			);
		}

		return array(
			'intro_label' => $intro_label,
			'intro_title' => $intro_title,
			'rows'        => $rows,
		);
	}

	/**
	 * ACM KPI / trust figure strip.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_kpi_strip( $node ) {
		$items = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"scroll-reveal") and contains(@class,"flex-1")]' ) as $col ) {
			$figure  = $this->text( $col, './/span[contains(@class,"text-4xl")][1]' );
			if ( '' === $figure ) {
				$figure = $this->text( $col, './/span[1]' );
			}
			$caption = $this->text( $col, './/span[contains(@class,"text-base")][1]' );
			if ( '' === $caption ) {
				$caption = $this->text( $col, './/span[2]' );
			}
			if ( '' !== $figure || '' !== $caption ) {
				$items[] = array(
					'figure'  => $figure,
					'caption' => $caption,
				);
			}
		}

		return array( 'items' => $items );
	}

	/**
	 * ACM home closing CTA band with multiple links.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_home_final_cta( $node ) {
		$actions = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"homepage-cta-grid")]//a' ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			$tgt     = $this->parse_link_target( (string) $link->getAttribute( 'href' ) );
			$actions[] = array(
				'label'    => trim( preg_replace( '/\s+/', ' ', $link->textContent ) ),
				'page_key' => $tgt['page_key'],
				'url'      => $tgt['url'],
			);
		}

		return array(
			'title'   => $this->html( $node, './/h2[1]' ),
			'body'    => $this->html( $node, './/p[contains(@class,"text-stone-600")][1] | .//div[contains(@class,"max-w-2xl")]//p[1]' ),
			'actions' => $actions,
		);
	}

	/**
	 * ACM standard hero (image background).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_hero( $node ) {
		$h1       = $this->first_node( $node, './/h1[1]' );
		$eyebrow  = '';
		$subtitle = '';
		if ( $h1 instanceof DOMElement ) {
			$eyebrow  = $this->text( $h1, './preceding-sibling::p[1]' );
			$subtitle = $this->text( $h1, './following-sibling::p[1]' );
		}
		$title = $this->html( $node, './/h1[1]' );
		if ( '' === $subtitle ) {
			$subtitle = $this->text( $node, './/*[contains(@class,"hero-subtitle")][1]' );
		}
		if ( '' === $subtitle ) {
			$subtitle = $this->text( $node, './/p[contains(@class,"text-white")][1]' );
		}
		if ( '' === $subtitle && ! $h1 ) {
			$subtitle = $this->text( $node, './/p[1]' );
		}

		// Background image: direct <img> or inline style.
		$image  = $this->parse_image_fields( $node, './/img[1]' );
		$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"btn-overlay-white")][1]' );
		}
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[1]' );
		}

		$proof_badges = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"hero-proof-bar")]//div[contains(@class,"proof-badge")]' ) as $badge ) {
			$svg  = $this->first_node( $badge, './/div[contains(@class,"proof-badge-icon")]//svg[1] | .//svg[1]' );
			$icon = '';
			if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
				$icon = trim( $svg->ownerDocument->saveHTML( $svg ) );
			}
			$proof_badges[] = array(
				'icon' => $icon,
				'text' => $this->text( $badge, './/*[contains(@class,"proof-badge-text")][1]' ),
			);
		}

		return array_merge(
			array(
				'eyebrow'      => $eyebrow,
				'title'        => $title,
				'subtitle'     => $subtitle,
				'image'        => $image['id'],
				'image_alt'    => $image['alt'],
				'proof_badges' => $proof_badges,
			),
			$button
		);
	}

	/**
	 * ACM services grid (index page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_services_grid( $node ) {
		$title = $this->html( $node, './/h2[1]' );
		$items = array();

		foreach ( $this->query_nodes( $node, './/article' ) as $card ) {
			$img    = $this->parse_image_fields( $card, './/img[1]' );
			$button = $this->parse_button( $card, './/a[1]' );

			$items[] = array(
				'title'       => $this->text( $card, './/h3[1]' ),
				'description' => $this->html( $card, './/p[1]' ),
				'image'       => $img['id'],
				'image_alt'   => $img['alt'],
				'cta_label'   => (string) ( $button['cta_label'] ?? '' ),
				'page_key'    => (string) ( $button['cta_page_key'] ?? '' ),
				'url'         => (string) ( $button['cta_url'] ?? '' ),
			);
		}

		return array(
			'title' => $title,
			'items' => $items,
		);
	}

	/**
	 * ACM about teaser (index page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_about_teaser( $node ) {
		$label  = $this->text( $node, './/*[contains(@class,"section-label")][1]' );
		$image  = $this->parse_image_fields( $node, './/img[not(contains(@class,"section-emblem"))][1]' );
		$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[1]' );
		}

		$image_right = false;
		$grid        = $this->first_node( $node, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]' );
		if ( $grid instanceof DOMElement ) {
			$cols    = $this->query_nodes( $grid, './div' );
			$img_idx = -1;
			$txt_idx = -1;
			foreach ( $cols as $i => $col ) {
				if ( ! $col instanceof DOMElement ) {
					continue;
				}
				if ( $this->first_node( $col, './/img[not(contains(@class,"section-emblem"))][1]' ) ) {
					$img_idx = (int) $i;
					$cl      = ' ' . $col->getAttribute( 'class' ) . ' ';
					if ( false !== strpos( $cl, 'lg:order-2' ) ) {
						$image_right = true;
					}
				}
				if ( $this->first_node( $col, './/h2[1]' ) ) {
					$txt_idx = (int) $i;
				}
			}
			if ( ! $image_right && $img_idx >= 0 && $txt_idx >= 0 && $img_idx > $txt_idx ) {
				$image_right = true;
			}
		}

		$body_html = '';
		$h2        = $this->first_node( $node, './/h2[1]' );
		if ( $h2 instanceof DOMElement && $h2->ownerDocument ) {
			$chunks = array();
			$el     = $h2->nextSibling;
			while ( $el ) {
				if ( $el instanceof DOMElement ) {
					$tag = strtolower( $el->tagName );
					if ( 'p' === $tag ) {
						$chunks[] = trim( $el->ownerDocument->saveHTML( $el ) );
					} elseif ( 'div' === $tag ) {
						$cl = ' ' . $el->getAttribute( 'class' ) . ' ';
						if ( false !== strpos( $cl, ' flex ' ) ) {
							break;
						}
					}
				}
				$el = $el->nextSibling;
			}
			$body_html = implode( '', $chunks );
		}
		if ( '' === $body_html ) {
			$body_html = $this->html( $node, './/h2[1]/following-sibling::p[1]' );
		}

		return array_merge(
			array(
				'label'       => $label,
				'title'       => $this->html( $node, './/h2[1]' ),
				'body'        => $body_html,
				'image'       => $image['id'],
				'image_alt'   => $image['alt'],
				'image_right' => $image_right ? 1 : 0,
			),
			$button
		);
	}

	/**
	 * ACM home fleet promo banner.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_charter_hero( $node ) {
		$img    = $this->parse_image_fields( $node, './/img[not(contains(@class,"section-emblem"))][1]' );
		$button = $this->parse_button( $node, './/a[contains(@class,"btn-overlay-white")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"inline-flex")][1]' );
		}

		return array_merge(
			array(
				'title'     => $this->html( $node, './/h2[1]' ),
				'image'     => $img['id'],
				'image_alt' => $img['alt'],
			),
			$button
		);
	}

	/**
	 * ACM generic content block.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_content_block( $node ) {
		$label = $this->text( $node, './/*[contains(concat(" ", normalize-space(@class), " "), " section-label ")][1]' );
		if ( '' === trim( $label ) ) {
			$label = $this->text( $node, './/div[contains(concat(" ", normalize-space(@class), " "), " max-w-xl ")]//span[contains(concat(" ", normalize-space(@class), " "), " uppercase ")][1]' );
		}
		if ( '' === trim( $label ) ) {
			$label = $this->text( $node, './/div[.//h2[1] and not(.//img[not(contains(@class,"section-emblem"))][1])]//span[contains(@class,"tracking")][1]' );
		}
		if ( '' === trim( $label ) ) {
			$label = $this->text( $node, './/div[contains(concat(" ", normalize-space(@class), " "), " content-box-outer ")]//span[contains(@class,"tracking")][1] | .//span[contains(@class,"tracking")][1]' );
		}
		$title  = $this->html( $node, './/h2[1]' );
		$body   = $this->html( $node, './/p[1]' );
		$image  = $this->parse_image_fields( $node, './/img[not(contains(@class,"section-emblem"))][1]' );
		$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[not(contains(@class,"acm-logo"))][1]' );
		}

		$items = array();
		foreach ( $this->query_nodes( $node, './/*[contains(@class,"feature-item")] | .//li' ) as $item ) {
			$items[] = array(
				'icon'    => $this->html( $item, './/svg[1]' ),
				'title'   => $this->text( $item, './/h3[1] | .//strong[1]' ),
				'content' => $this->html( $item, './/p[1]' ),
			);
		}

		return array_merge(
			array(
				'label'     => $label,
				'title'     => $title,
				'body'      => $body,
				'image'     => $image['id'],
				'image_alt' => $image['alt'],
				'items'     => $items,
			),
			$button
		);
	}

	/**
	 * ACM news teaser (index page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_news_teaser( $node ) {
		$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[last()]' );
		}

		return array_merge(
			array(
				'title'     => $this->html( $node, './/h2[1]' ),
				'max_posts' => '6',
			),
			$button
		);
	}

	/**
	 * ACM horizontal timeline (That's ACM page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_horizontal_timeline( $node ) {
		$head  = $this->first_node( $node, './/div[contains(@class,"text-center")][1]' );
		$label = $head instanceof DOMNode ? $this->text( $head, './/*[contains(@class,"section-label")][1]' ) : $this->text( $node, './/*[contains(@class,"section-label")][1]' );
		$title = $head instanceof DOMNode ? $this->html( $head, './/h2[1]' ) : $this->html( $node, './/h2[1]' );
		$intro = $head instanceof DOMNode ? $this->html( $head, './/p[1]' ) : '';
		if ( '' === trim( wp_strip_all_tags( $intro ) ) ) {
			$intro = $this->html( $node, './/div[contains(@class,"text-center")][1]/following-sibling::p[1]' );
		}
		$items = array();

		foreach ( $this->query_nodes( $node, './/article[contains(concat(\' \', normalize-space(@class), \' \'), \' htl-card \')] | .//*[contains(concat(\' \', normalize-space(@class), \' \'), \' htl-item \')] | .//*[contains(concat(\' \', normalize-space(@class), \' \'), \' timeline-item \')]' ) as $item ) {
			$img = $this->parse_image_fields( $item, './/img[1]' );
			$items[] = array(
				'year'    => $this->text( $item, './/*[contains(@class,"htl-card-year")] | .//*[contains(@class,"htl-year")] | .//*[contains(@class,"year")]' ),
				'title'   => $this->text( $item, './/*[contains(@class,"htl-card-title")] | .//h3[1] | .//*[contains(@class,"htl-title")]' ),
				'content' => $this->html( $item, './/*[contains(@class,"htl-card-text")] | .//p[1] | .//*[contains(@class,"htl-text")]' ),
				'image'   => $img['id'],
			);
		}

		return array(
			'label' => $label,
			'title' => $title,
			'intro' => $intro,
			'items' => $items,
		);
	}

	/**
	 * ACM contact CTA (shared across pages).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_contact_cta( $node ) {
		$button = $this->parse_button( $node, './/a[contains(@class,"btn-primary")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		}
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"btn-outline")][1]' );
		}
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[starts-with(@href,"tel:")][1]' );
		}
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[1]' );
		}
		$secondary = $this->parse_button( $node, './/a[contains(@class,"btn-outline")][1]' );
		if ( empty( $secondary['cta_label'] ) ) {
			$secondary = $this->parse_button( $node, './/a[starts-with(@href,"tel:")][1]' );
		}
		if (
			(string) ( $secondary['cta_label'] ?? '' ) === (string) ( $button['cta_label'] ?? '' )
			&& (string) ( $secondary['cta_page_key'] ?? '' ) === (string) ( $button['cta_page_key'] ?? '' )
			&& (string) ( $secondary['cta_url'] ?? '' ) === (string) ( $button['cta_url'] ?? '' )
		) {
			$secondary = array(
				'cta_label'    => '',
				'cta_page_key' => '',
				'cta_url'      => '',
			);
		}

		return array_merge(
			array(
				'title' => $this->html( $node, './/h2[1]' ),
				'body'  => $this->html( $node, './/p[1]' ),
			),
			$button,
			$this->prefix_button_fields( $secondary, 'secondary' )
		);
	}

	/**
	 * ACM fleet overview (charter page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_fleet_overview( $node ) {
		$head     = $this->first_node( $node, './/div[contains(@class,"text-center")][1]' );
		$label    = $head instanceof DOMNode ? $this->text( $head, './/*[contains(@class,"section-label")][1]' ) : $this->text( $node, './/*[contains(@class,"section-label")][1]' );
		$title    = $head instanceof DOMNode ? $this->html( $head, './/h2[1]' ) : $this->html( $node, './/h2[1]' );
		$subtitle = $head instanceof DOMNode ? $this->text( $head, './/p[1]' ) : '';
		$aircraft = array();

		foreach ( $this->query_nodes( $node, './/*[contains(@class,"fleet-card")] | .//*[contains(@class,"aircraft-card")] | .//article' ) as $card ) {
			if ( ! $this->first_node( $card, './/h3[1] | .//h4[1]' ) ) {
				continue;
			}
			$img    = $this->parse_image_fields( $card, './/img[1]' );
			$button = $this->parse_button( $card, './/a[contains(@class,"link-arrow")][1] | .//a[1]' );

			$aircraft[] = array(
				'title'       => $this->text( $card, './/h3[1]' ),
				'subtitle'    => $this->text( $card, './/*[contains(@class,"section-label")][1] | .//span[1]' ),
				'description' => $this->html( $card, './/p[contains(@class,"text-stone-500")][1] | .//div[contains(@class,"p-8")]//p[1] | .//p[1]' ),
				'specs'     => $this->html( $card, './/ul[1]' ),
				'image'     => $img['id'],
				'image_alt' => $img['alt'],
				'cta_label' => (string) ( $button['cta_label'] ?? '' ),
				'page_key'  => (string) ( $button['cta_page_key'] ?? '' ),
				'url'       => (string) ( $button['cta_url'] ?? '' ),
			);
		}

		$compare_label = '';
		$compare_title = '';
		$compare_tabs  = array();
		$compare_panels = array();

		$tabs_wrap = $this->first_node( $node, './/*[contains(@class,"fleet-compare-tabs")][1]' );
		if ( $tabs_wrap instanceof DOMNode ) {
			$compare_head = $this->first_node( $tabs_wrap, './preceding-sibling::*[1]' );
			if ( $compare_head instanceof DOMNode ) {
				$compare_label = $this->text( $compare_head, './/*[contains(@class,"section-label")][1]' );
				$compare_title = $this->html( $compare_head, './/h3[1] | .//h2[1]' );
			}
			foreach ( $this->query_nodes( $tabs_wrap, './/button[contains(concat(" ", normalize-space(@class), " "), " fleet-compare-tab ")]' ) as $tab ) {
				if ( ! $tab instanceof DOMElement ) {
					continue;
				}
				$compare_tabs[] = array(
					'variant' => trim( (string) $tab->getAttribute( 'data-tab' ) ),
					'label'   => trim( preg_replace( '/\s+/', ' ', $tab->textContent ) ),
				);
			}
		}

		foreach ( $this->query_nodes( $node, './/*[contains(@class,"fleet-compare-panel")]' ) as $panel ) {
			if ( ! $panel instanceof DOMElement ) {
				continue;
			}
			$rows         = array();
			$scale_labels = array();

			foreach ( $this->query_nodes( $panel, './/*[contains(@class,"fleet-bar-row")]' ) as $row ) {
				$rows[] = array(
					'aircraft' => $this->text( $row, './/*[contains(@class,"fleet-bar-name")][1]' ),
					'value'    => $this->text( $row, './/*[contains(@class,"fleet-bar-value")][1]' ),
				);
			}

			$scale = $this->first_node( $panel, './/*[contains(@class,"fleet-bar-scale")][1]' );
			if ( $scale instanceof DOMNode ) {
				foreach ( $this->query_nodes( $scale, './span' ) as $scale_item ) {
					$scale_labels[] = array(
						'label' => trim( preg_replace( '/\s+/', ' ', $scale_item->textContent ) ),
					);
				}
			}

			$compare_panels[] = array(
				'variant'      => trim( (string) $panel->getAttribute( 'data-panel' ) ),
				'rows'         => $rows,
				'scale_labels' => $scale_labels,
			);
		}

		return array(
			'label'         => $label,
			'title'    => $title,
			'subtitle' => $subtitle,
			'aircraft'      => $aircraft,
			'compare_label' => $compare_label,
			'compare_title' => $compare_title,
			'compare_tabs'  => $compare_tabs,
			'compare_panels' => $compare_panels,
		);
	}

	/**
	 * ACM aircraft specs page (global-7500, -6000, -xrs).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_aircraft_specs( $node ) {
		$title       = $this->html( $node, './/div[contains(@class,"text-center")]//h2[1] | .//h2[1]' );
		$subtitle    = $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' );
		$description = '';

		$spec_groups = array();
		foreach ( $this->query_nodes( $node, './/h3[contains(@class,"spec-group-title")]' ) as $gh ) {
			if ( ! $gh instanceof DOMElement ) {
				continue;
			}
			$group_title = trim( $gh->textContent );
			$spec_grid   = $this->first_node( $gh, './following-sibling::div[contains(@class,"spec-grid")][1]' );
			$rows        = array();
			if ( $spec_grid instanceof DOMElement ) {
				foreach ( $this->query_nodes( $spec_grid, './/div[contains(@class,"spec-item")]' ) as $item ) {
					$val = $this->text( $item, './/*[contains(@class,"spec-value")][1]' );
					$lbl = $this->text( $item, './/*[contains(@class,"spec-label")][1]' );
					if ( '' !== $lbl || '' !== $val ) {
						$rows[] = array(
							'label' => $lbl,
							'value' => $val,
						);
					}
				}
			}
			if ( '' !== $group_title || $rows ) {
				$spec_groups[] = array(
					'group_title' => $group_title,
					'rows'        => $rows,
				);
			}
		}

		$specs = array();
		foreach ( $spec_groups as $group ) {
			foreach ( (array) ( $group['rows'] ?? array() ) as $row ) {
				$specs[] = array(
					'label' => $row['label'] ?? '',
					'value' => $row['value'] ?? '',
				);
			}
		}

		if ( empty( $spec_groups ) ) {
			foreach ( $this->query_nodes( $node, './/*[contains(@class,"spec-row")] | .//tr | .//li' ) as $row ) {
				$label_node = $this->first_node( $row, './/td[1] | .//span[1] | .//strong[1]' );
				$value_node = $this->first_node( $row, './/td[2] | .//span[2]' );
				if ( $label_node ) {
					$specs[] = array(
						'label' => trim( $label_node->textContent ),
						'value' => $value_node ? trim( $value_node->textContent ) : '',
					);
				}
			}
		}

		$footnote = '';
		$fn_node  = $this->first_node( $node, './/p[contains(@class,"text-center") and contains(@class,"mt-12")][1]' );
		if ( $fn_node instanceof DOMElement && $fn_node->ownerDocument ) {
			$footnote = trim( $fn_node->ownerDocument->saveHTML( $fn_node ) );
		}

		return array(
			'title'        => $title,
			'subtitle'     => $subtitle,
			'description'  => $description,
			'spec_groups'  => $spec_groups,
			'specs'        => $specs,
			'footnote'     => $footnote,
			'gallery'      => array(),
			'fleet_links'  => array(),
		);
	}

	/**
	 * ACM cabin zone cards (Global aircraft pages).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_zone_cards( $node ) {
		$items = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"zone-card")]' ) as $card ) {
			$items[] = array(
				'number'  => $this->text( $card, './/*[contains(@class,"zone-card-number")][1]' ),
				'title'   => $this->text( $card, './/*[contains(@class,"zone-card-title")][1]' ),
				'content' => $this->html( $card, './/p[1]' ),
			);
		}

		return array(
			'label' => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1] | .//h2[1]' ),
			'items' => $items,
		);
	}

	/**
	 * ACM full-width image carousel section.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_image_carousel( $node ) {
		$slides = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"carousel-slide")]' ) as $slide ) {
			$img      = $this->parse_image_fields( $slide, './/img[1]' );
			$slides[] = array(
				'image'     => $img['id'],
				'image_alt' => $img['alt'],
			);
		}

		return array(
			'label'  => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' ),
			'title'  => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1] | .//h2[1]' ),
			'slides' => $slides,
		);
	}

	/**
	 * ACM featured figure (floorplan / single diagram).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_featured_figure( $node ) {
		$img = $this->parse_image_fields( $node, './/div[contains(@class,"scroll-reveal")]//img[not(contains(@class,"section-emblem"))][1] | .//img[not(contains(@class,"section-emblem"))][1]' );

		$footnote = '';
		$fn_node  = $this->first_node( $node, './/p[contains(@class,"text-center") and contains(@class,"mt-6")][1]' );
		if ( $fn_node instanceof DOMElement && $fn_node->ownerDocument ) {
			$footnote = trim( $fn_node->ownerDocument->saveHTML( $fn_node ) );
		}

		return array(
			'label'     => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' ),
			'title'     => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1] | .//h2[1]' ),
			'image'     => $img['id'],
			'image_alt' => $img['alt'],
			'footnote'  => $footnote,
		);
	}

	/**
	 * ACM fleet teaser (two link cards).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_fleet_teaser( $node ) {
		$items = array();
		foreach ( $this->query_nodes( $node, './/a[contains(@class,"fleet-link-card")]' ) as $card ) {
			if ( ! $card instanceof DOMElement ) {
				continue;
			}
			$img    = $this->parse_image_fields( $card, './/img[1]' );
			$href   = $card->getAttribute( 'href' );
			$target = $this->parse_link_target( $href );

			$items[] = array(
				'subtitle'    => $this->text( $card, './/*[contains(@class,"section-label")][1]' ),
				'title'       => $this->text( $card, './/h3[1]' ),
				'description' => $this->html( $card, './/p[contains(@class,"text-stone-500")][1] | .//div[contains(@class,"p-6")]//p[1]' ),
				'image'       => $img['id'],
				'image_alt'   => $img['alt'],
				'cta_label'   => $this->text( $card, './/*[contains(@class,"link-arrow")][1]' ),
				'page_key'    => $target['page_key'],
				'url'         => $target['url'],
			);
		}

		return array(
			'label' => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1] | .//h2[1]' ),
			'items' => $items,
		);
	}

	/**
	 * ACM Betriebsmodelle (Aircraft Management page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_betriebsmodelle( $node ) {
		$head     = $this->first_node( $node, './/div[contains(@class,"text-center") and contains(@class,"scroll-reveal")][1]' );
		$label    = $head instanceof DOMNode ? $this->text( $head, './/*[contains(@class,"section-label")][1]' ) : $this->text( $node, './/*[contains(@class,"section-label")][1]' );
		$title    = $head instanceof DOMNode ? $this->html( $head, './/h2[1]' ) : $this->html( $node, './/h2[1]' );
		$intro    = '';
		$items    = array();

		foreach ( $this->query_nodes( $node, './/div[contains(@class,"grid") and contains(@class,"md:grid-cols-2")]/div[contains(@class,"scroll-reveal")]' ) as $card ) {
			if ( ! $this->first_node( $card, './/h3[1]' ) ) {
				continue;
			}
			$features = array();
			foreach ( $this->query_nodes( $card, './/li' ) as $li ) {
				$features[] = array(
					'text' => trim( $li->textContent ),
				);
			}

			$btn = $this->parse_button( $card, './/a[contains(@class,"btn-primary")][1]' );
			if ( empty( $btn['cta_label'] ) ) {
				$btn = $this->parse_button( $card, './/a[contains(@class,"btn")][1]' );
			}

			$items[] = array_merge(
				array(
					'kicker'      => $this->text( $card, './/*[contains(@class,"section-number")][1]' ),
					'title'       => $this->text( $card, './/h3[1]' ),
					'description' => $this->html( $card, './/p[contains(@class,"text-stone-500")][1] | .//p[1]' ),
					'features'    => $features,
				),
				$btn
			);
		}

		return array(
			'label' => $label,
			'title' => $title,
			'intro' => $intro,
			'items' => $items,
		);
	}

	/**
	 * ACM process split (intro + steps column).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_process_split( $node ) {
		$grid = $this->first_node( $node, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]' );
		$left = null;
		if ( $grid instanceof DOMElement ) {
			foreach ( $this->query_nodes( $grid, './div[contains(@class,"scroll-reveal")]' ) as $col ) {
				if ( $this->first_node( $col, './/div[contains(@class,"process-step")][1]' ) ) {
					break;
				}
				if ( $this->first_node( $col, './/h2[1]' ) ) {
					$left = $col;
				}
			}
		}
		$left = ( $left instanceof DOMNode ) ? $left : $node;

		$body_parts = array();
		$h2         = $this->first_node( $left, './/h2[1]' );
		if ( $h2 instanceof DOMElement && $h2->ownerDocument ) {
			$el = $h2->nextSibling;
			while ( $el ) {
				$nx = $el->nextSibling;
				if ( $el instanceof DOMElement ) {
					$tag = strtolower( $el->tagName );
					if ( 'p' === $tag ) {
						$body_parts[] = trim( $el->ownerDocument->saveHTML( $el ) );
					} elseif ( 'a' === $tag && false !== strpos( ' ' . $el->getAttribute( 'class' ) . ' ', ' btn-primary ' ) ) {
						break;
					}
				}
				$el = $nx;
			}
		}

		$button = $this->parse_button( $left, './/a[contains(@class,"btn-primary")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $left, './/a[contains(@class,"btn")][1]' );
		}

		$steps = array();
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"process-step")]' ) as $step ) {
			if ( ! $step instanceof DOMElement ) {
				continue;
			}
			$steps[] = array(
				'step'    => (string) $step->getAttribute( 'data-step' ),
				'title'   => $this->text( $step, './/h3[1]' ),
				'content' => $this->html( $step, './/p[1]' ),
			);
		}

		return array_merge(
			array(
				'label' => $this->text( $left, './/*[contains(@class,"section-label")][1]' ),
				'title' => $this->html( $left, './/h2[1]' ),
				'intro' => implode( '', $body_parts ),
				'steps' => $steps,
			),
			$button
		);
	}

	/**
	 * ACM AOG callout section.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_aog_callout( $node ) {
		$img = $this->parse_image_fields(
			$node,
			'.//div[contains(@class,"image-zoom-bleed")]//img[not(contains(@class,"section-emblem"))][1] | .//div[contains(@class,"grid")]//img[not(contains(@class,"section-emblem"))][not(ancestor::div[contains(@class,"max-w-xl")])][1]'
		);

		$box = $this->first_node( $node, './/div[contains(@class,"content-box-outer")]//div[contains(@class,"max-w-xl")][1] | .//div[contains(@class,"max-w-xl")][1]' );
		$ctx = $box instanceof DOMNode ? $box : $node;

		$label = $this->text( $ctx, './/*[contains(@class,"section-label")][1]' );
		$title = $this->html( $ctx, './/h2[1]' );

		$body_parts = array();
		$h2         = $this->first_node( $ctx, './/h2[1]' );
		$space8     = $this->first_node( $ctx, './/div[contains(@class,"space-y-8")][1]' );
		if ( $h2 instanceof DOMElement && $h2->ownerDocument ) {
			$el = $h2->nextSibling;
			while ( $el ) {
				if ( $el === $space8 ) {
					break;
				}
				$nx = $el->nextSibling;
				if ( $el instanceof DOMElement && 'p' === strtolower( $el->tagName ) ) {
					$body_parts[] = trim( $el->ownerDocument->saveHTML( $el ) );
				}
				$el = $nx;
			}
		}
		$body = implode( '', $body_parts );
		if ( '' === $body ) {
			$body = $this->html( $ctx, './/h2[1]/following-sibling::p[1]' );
		}

		$highlights = array();
		if ( $space8 instanceof DOMElement ) {
			foreach ( $this->query_nodes( $space8, './div' ) as $row ) {
				if ( ! $this->first_node( $row, './/h4[1]' ) ) {
					continue;
				}
				$highlights[] = array(
					'title'   => $this->text( $row, './/h4[1]' ),
					'content' => $this->html( $row, './/p[1]' ),
				);
			}
		}

		$phone = $this->text( $node, './/a[contains(@href,"tel:")][1]' );
		if ( '' === $phone ) {
			$phone_href = $this->attr( $node, './/a[contains(@href,"tel:")][1]', 'href' );
			$phone      = str_replace( 'tel:', '', (string) $phone_href );
		}

		$button = array(
			'cta_label'    => '',
			'cta_page_key' => '',
			'cta_url'      => '',
		);
		foreach ( $this->query_nodes( $node, './/a[contains(@class,"btn")]' ) as $a ) {
			if ( ! $a instanceof DOMElement ) {
				continue;
			}
			$href = (string) $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$link   = $this->parse_link_target( $href );
			$button = array(
				'cta_label'    => trim( preg_replace( '/\s+/', ' ', $a->textContent ) ),
				'cta_page_key' => $link['page_key'],
				'cta_url'      => $link['url'],
			);
			break;
		}

		return array_merge(
			array(
				'image'      => (int) ( $img['id'] ?? 0 ),
				'image_alt'  => (string) ( $img['alt'] ?? '' ),
				'label'      => $label,
				'title'      => $title,
				'body'       => $body,
				'highlights' => $highlights,
				'phone'      => $phone,
			),
			$button
		);
	}

	/**
	 * ACM work area cards (Karriere).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_area_cards( $node ) {
		$items = array();
		foreach ( $this->query_nodes( $node, './/*[contains(@class,"area-card")]' ) as $card ) {
			$icon = '';
			$icon_wrap = $this->first_node( $card, './/div[contains(@class,"w-12") and contains(@class,"h-12")][1]' );
			if ( $icon_wrap instanceof DOMElement ) {
				$svg = $this->first_node( $icon_wrap, './/svg[1]' );
				if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
					$icon = trim( $svg->ownerDocument->saveHTML( $svg ) );
				}
			}
			$link_btn = $this->parse_button( $card, './/a[contains(@class,"link-arrow")][1]' );
			$items[] = array_merge(
				array(
					'icon'        => $icon,
					'title'       => $this->text( $card, './/h3[1]' ),
					'description' => $this->html( $card, './/p[1]' ),
				),
				$this->prefix_button_fields( $link_btn, 'link' )
			);
		}

		return array(
			'label' => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1]' ),
			'items' => $items,
		);
	}

	/**
	 * ACM split: image + highlights column (Karriere Haltung).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_split_highlights( $node ) {
		$image = $this->parse_image_fields( $node, './/img[not(contains(@class,"section-emblem"))][1]' );

		$text_col = null;
		$grid     = $this->first_node( $node, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]' );
		if ( $grid instanceof DOMElement ) {
			foreach ( $this->query_nodes( $grid, './div' ) as $col ) {
				if ( $this->first_node( $col, './/h2[1]' ) ) {
					$text_col = $col;
					break;
				}
			}
		}

		$label   = $text_col instanceof DOMNode ? $this->text( $text_col, './/*[contains(@class,"section-label")][1]' ) : '';
		$title   = $text_col instanceof DOMNode ? $this->html( $text_col, './/h2[1]' ) : '';
		$intro   = '';
		$h2      = $text_col instanceof DOMNode ? $this->first_node( $text_col, './/h2[1]' ) : null;
		if ( $h2 instanceof DOMElement && $h2->ownerDocument ) {
			$intro_el = $this->first_node( $h2, './following-sibling::p[1]' );
			if ( $intro_el instanceof DOMElement ) {
				$intro = trim( $intro_el->ownerDocument->saveHTML( $intro_el ) );
			}
		}

		$highlights = array();
		$space8     = $text_col instanceof DOMNode ? $this->first_node( $text_col, './/div[contains(@class,"space-y-8")][1]' ) : null;
		if ( $space8 instanceof DOMElement ) {
			foreach ( $this->query_nodes( $space8, './div' ) as $row ) {
				if ( ! $this->first_node( $row, './/h4[1]' ) ) {
					continue;
				}
				$highlights[] = array(
					'title'   => $this->text( $row, './/h4[1]' ),
					'content' => $this->html( $row, './/p[1]' ),
				);
			}
		}

		return array(
			'image'      => $image['id'],
			'image_alt'  => $image['alt'],
			'label'      => $label,
			'title'      => $title,
			'intro'      => $intro,
			'highlights' => $highlights,
		);
	}

	/**
	 * ACM Stellenangebote (Karriere page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_stellen( $node ) {
		$label = $this->text( $node, './/div[contains(@class,"text-center")][1]//*[contains(@class,"section-label")][1] | .//*[contains(@class,"section-label")][1]' );
		$title = $this->html( $node, './/h2[1]' );
		$intro = '';
		$intro_el = $this->first_node( $node, './/div[contains(@class,"text-center")][contains(@class,"mb-16")][1]//p[1]' );
		if ( $intro_el instanceof DOMElement && $intro_el->ownerDocument ) {
			$intro = trim( $intro_el->ownerDocument->saveHTML( $intro_el ) );
		}

		$items                = array();
		$tasks_heading        = 'Aufgaben';
		$requirements_heading = 'Anforderungen';

		foreach ( $this->query_nodes( $node, './/details[contains(@class,"job-card")]' ) as $card ) {
			$meta = $this->query_nodes( $card, './/summary//span[contains(@class,"job-meta-tag")]' );
			$dept = isset( $meta[0] ) ? trim( $meta[0]->textContent ) : '';
			$loc  = isset( $meta[1] ) ? trim( $meta[1]->textContent ) : '';
			$type = isset( $meta[2] ) ? trim( $meta[2]->textContent ) : '';

			$detail = $this->first_node( $card, './/*[contains(@class,"job-detail-content")][1]' );
			$tasks  = '';
			$req    = '';
			if ( $detail instanceof DOMElement ) {
				$grid = $this->first_node( $detail, './/div[contains(@class,"grid")][1]' );
				if ( $grid instanceof DOMElement ) {
					foreach ( $this->query_nodes( $grid, './div' ) as $col ) {
						$h4t = $this->text( $col, './/h4[1]' );
						$ul  = $this->first_node( $col, './/ul[1]' );
						if ( ! $ul instanceof DOMElement || ! $ul->ownerDocument ) {
							continue;
						}
						$html = trim( $ul->ownerDocument->saveHTML( $ul ) );
						if ( false !== stripos( $h4t, 'Aufgaben' ) ) {
							$tasks_heading = trim( $h4t ) ?: $tasks_heading;
							$tasks = $html;
						} elseif ( false !== stripos( $h4t, 'Anforderungen' ) ) {
							$requirements_heading = trim( $h4t ) ?: $requirements_heading;
							$req = $html;
						}
					}
				}
			}

			$pdf_label = '';
			$pdf_url   = '';
			$pdf_a     = $detail instanceof DOMNode ? $this->first_node( $detail, './/a[contains(@class,"link-arrow")][1]' ) : null;
			if ( $pdf_a instanceof DOMElement ) {
				$href_raw = (string) $pdf_a->getAttribute( 'href' );
				$pt       = $this->parse_link_target( $href_raw );
				$pdf_url  = '' !== (string) $pt['url'] ? (string) $pt['url'] : $href_raw;
				$pdf_label = trim( preg_replace( '/\s+/', ' ', $pdf_a->textContent ) );
			}

			$apply = array(
				'cta_label'    => '',
				'cta_page_key' => '',
				'cta_url'      => '',
			);
			if ( $detail instanceof DOMNode ) {
				$apply = $this->parse_button( $detail, './/a[contains(@class,"btn-primary")][1]' );
			}

			$items[] = array_merge(
				array(
					'title'        => $this->text( $card, './/summary//h3[1] | .//h3[1]' ),
					'department'   => $dept,
					'location'     => $loc,
					'type'         => $type,
					'tasks'        => $tasks,
					'requirements' => $req,
					'description'  => '',
					'pdf_label'    => $pdf_label,
					'pdf_url'      => $pdf_url,
				),
				$this->prefix_button_fields( $apply, 'apply' )
			);
		}

		$footer_text = '';
		$footer_btn  = array(
			'cta_label'    => '',
			'cta_page_key' => '',
			'cta_url'      => '',
		);
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"text-center")][contains(@class,"mt-16")]' ) as $fwrap ) {
			$fa = $this->first_node( $fwrap, './/a[contains(@class,"btn-primary")][1]' );
			if ( ! $fa instanceof DOMElement ) {
				continue;
			}
			$footer_btn = $this->parse_button( $fwrap, './/a[contains(@class,"btn-primary")][1]' );
			$p          = $this->first_node( $fwrap, './/p[1]' );
			if ( $p instanceof DOMElement && $p->ownerDocument ) {
				$footer_text = trim( $p->ownerDocument->saveHTML( $p ) );
			}
			break;
		}

		return array_merge(
			array(
				'label'                => $label,
				'title'                => $title,
				'intro'                => $intro,
				'tasks_heading'        => $tasks_heading,
				'requirements_heading' => $requirements_heading,
				'items'                => $items,
				'footer_text'          => $footer_text,
			),
			$this->prefix_button_fields( $footer_btn, 'footer' )
		);
	}

	/**
	 * Kontakt: Command-Panel (Schnellzugriff).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_contact_command_grid( $node ) {
		$items = array();
		foreach ( $this->query_nodes( $node, './/a[contains(@class,"command-card")]' ) as $a ) {
			if ( ! $a instanceof DOMElement ) {
				continue;
			}
			$icon = '';
			$slot = $this->first_node( $a, './/div[contains(@class,"w-12") and contains(@class,"h-12")][1]' );
			if ( $slot instanceof DOMElement ) {
				$svg = $this->first_node( $slot, './/svg[1]' );
				if ( $svg instanceof DOMNode && $svg->ownerDocument ) {
					$icon = trim( $svg->ownerDocument->saveHTML( $svg ) );
				}
			}
			$title    = $this->text( $a, './/div[contains(@class,"pr-8")]//span[contains(@class,"font-serif")][1] | .//div[contains(@class,"pr-8")]//span[1]' );
			$subtitle = $this->text( $a, './/div[contains(@class,"pr-8")]//span[contains(@class,"mt-1")][1] | .//div[contains(@class,"pr-8")]//span[2]' );
			$href     = (string) $a->getAttribute( 'href' );
			$pt       = $this->parse_link_target( $href );
			$btn      = array(
				'cta_label'    => $title,
				'cta_page_key' => $pt['page_key'],
				'cta_url'      => $pt['url'],
			);
			$items[] = array_merge(
				array(
					'icon'     => $icon,
					'title'    => $title,
					'subtitle' => $subtitle,
				),
				$this->prefix_button_fields( $btn, 'link' )
			);
		}

		return array(
			'label' => $this->text( $node, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]' ),
			'title' => $this->html( $node, './/div[contains(@class,"text-center")]//h2[1]' ),
			'intro' => $this->text( $node, './/div[contains(@class,"text-center")]//p[1]' ),
			'items' => $items,
		);
	}

	/**
	 * Kontakt: Abteilungs-Sektion (Zentrale mit Karte oder Profilraster).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_contact_dept_section( $node ) {
		$section_id = '';
		if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) ) {
			$section_id = (string) $node->getAttribute( 'id' );
		}

		$iframe = $this->first_node( $node, './/iframe[1]' );
		$map_url = '';
		if ( $iframe instanceof DOMElement && $iframe->hasAttribute( 'src' ) ) {
			$map_url = trim( (string) $iframe->getAttribute( 'src' ) );
		}

		if ( '' !== $map_url ) {
			$left = $this->first_node(
				$node,
				'.//div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")]//div[contains(@class,"scroll-reveal")][.//h2[1]][not(.//iframe)][1]'
			);
			$title_html = $left instanceof DOMNode
				? $this->html( $left, './/h2[1]' )
				: $this->html( $node, './/h2[1]' );
			$left_body = '';
			$intro_html = '';
			$footer_html = '';
			if ( $left instanceof DOMElement && $left->ownerDocument ) {
				$h2 = $this->first_node( $left, './/h2[1]' );
				if ( $h2 instanceof DOMElement ) {
					$parts = array();
					$nx    = $h2->nextSibling;
					while ( $nx ) {
						$next = $nx->nextSibling;
						if ( $nx instanceof DOMElement && $nx->ownerDocument ) {
							$html    = trim( $nx->ownerDocument->saveHTML( $nx ) );
							$tag     = strtolower( $nx->tagName );
							$classes = ' ' . trim( (string) $nx->getAttribute( 'class' ) ) . ' ';
							if ( 'p' === $tag && '' === $intro_html && false !== strpos( $classes, ' text-stone-500 ' ) ) {
								$intro_html = $html;
							} elseif ( 'p' === $tag && '' === $footer_html ) {
								$footer_html = $html;
							} else {
								$parts[] = $html;
							}
						}
						$nx = $next;
					}
					$left_body = implode( '', $parts );
				}
			}

			return array(
				'section_id'    => $section_id,
				'section_label' => '',
				'title'         => $title_html,
				'intro'         => $intro_html,
				'left_column'   => $left_body,
				'map_embed_url' => $map_url,
				'profiles'      => array(),
				'footer_note'   => $footer_html,
			);
		}

		$header = $this->first_node( $node, './/div[contains(@class,"scroll-reveal")][contains(@class,"mb-4")][1]' );
		$section_label = $header instanceof DOMNode
			? $this->text( $header, './/*[contains(@class,"section-label")][1]' )
			: $this->text( $node, './/*[contains(@class,"section-label")][1]' );
		$title_html = $header instanceof DOMNode
			? $this->html( $header, './/h2[1]' )
			: $this->html( $node, './/h2[1]' );
		$intro = $this->html( $node, './/p[contains(@class,"text-stone-500")][contains(@class,"max-w-3xl")][1]' );

		$profiles = array();
		// Nur aeussere Profilkarten; sonst matcht contains(@class,"profile-card") auch Wrap/Image (3x pro Person).
		foreach ( $this->query_nodes( $node, './/div[contains(concat(\' \', normalize-space(@class), \' \'), \' profile-card \')]' ) as $card ) {
			$img    = $this->parse_image_fields( $card, './/img[1]' );
			$tel_a  = $this->first_node( $card, './/a[starts-with(@href,"tel:")][1]' );
			$mail_a = $this->first_node( $card, './/a[starts-with(@href,"mailto:")][1]' );
			$phone  = $tel_a ? trim( $tel_a->textContent ) : '';
			$phone_url = $tel_a instanceof DOMElement ? (string) $tel_a->getAttribute( 'href' ) : '';
			$email     = $mail_a ? trim( $mail_a->textContent ) : '';
			$email_url = $mail_a instanceof DOMElement ? (string) $mail_a->getAttribute( 'href' ) : '';
			$profiles[] = array(
				'name'      => $this->text( $card, './/h3[1]' ),
				'role'      => $this->text( $card, './/div[contains(@class,"p-5")]/span[contains(@class,"block")][1]' ),
				'phone'     => $phone,
				'phone_url' => $phone_url,
				'email'     => $email,
				'email_url' => $email_url,
				'image'     => $img['id'],
			);
		}

		$footer_note = '';
		foreach ( $this->query_nodes( $node, './/div[contains(@class,"mt-10")][1]//p' ) as $fn ) {
			if ( ! $fn instanceof DOMElement || ! $fn->ownerDocument ) {
				continue;
			}
			if ( '' === trim( wp_strip_all_tags( $fn->textContent ) ) ) {
				continue;
			}
			$footer_note = trim( $fn->ownerDocument->saveHTML( $fn ) );
			break;
		}

		return array(
			'section_id'    => $section_id,
			'section_label' => $section_label,
			'title'         => $title_html,
			'intro'         => $intro,
			'left_column'   => '',
			'map_embed_url' => '',
			'profiles'      => $profiles,
			'footer_note'   => $footer_note,
		);
	}

	/**
	 * Kontakt: olivfarbenes Abschlussband.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_contact_inquiry_band( $node ) {
		$button = $this->parse_button( $node, './/a[contains(@class,"btn-outline-white")][1]' );
		if ( empty( $button['cta_label'] ) ) {
			$button = $this->parse_button( $node, './/a[contains(@class,"btn")][1]' );
		}

		$tel  = $this->first_node( $node, './/div[contains(@class,"space-y-3")]//a[starts-with(@href,"tel:")][1]' );
		$mail = $this->first_node( $node, './/div[contains(@class,"space-y-3")]//a[starts-with(@href,"mailto:")][1]' );

		return array_merge(
			array(
				'section_label' => $this->text( $node, './/*[contains(@class,"section-label")][1]' ),
				'title'         => $this->html( $node, './/h2[1]' ),
				'body'          => $this->html( $node, './/p[contains(@class,"max-w-2xl")][1]' ),
				'phone'         => $tel ? trim( $tel->textContent ) : '',
				'phone_url'     => $tel instanceof DOMElement ? (string) $tel->getAttribute( 'href' ) : '',
				'email'         => $mail ? trim( $mail->textContent ) : '',
				'email_url'     => $mail instanceof DOMElement ? (string) $mail->getAttribute( 'href' ) : '',
				'address'       => $this->text( $node, './/address[1]' ),
			),
			$button
		);
	}

	/**
	 * ACM departments grid (Kontakt page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_departments( $node ) {
		$departments = array();

		foreach ( $this->query_nodes( $node, './/article | .//*[contains(@class,"dept-card")] | .//*[contains(@class,"command-card")]' ) as $dept ) {
			$anchor_id = '';
			$link = $this->first_node( $dept, './/a[1]' );
			if ( $link instanceof DOMElement ) {
				$href = $link->getAttribute( 'href' );
				if ( 0 === strpos( $href, '#' ) ) {
					$anchor_id = substr( $href, 1 );
				}
			}

			$contacts = array();
			foreach ( $this->query_nodes( $dept, './/*[contains(@class,"contact-person")] | .//*[contains(@class,"person")]' ) as $person ) {
				$contacts[] = array(
					'name'  => $this->text( $person, './/h4[1] | .//strong[1]' ),
					'role'  => $this->text( $person, './/span[1] | .//p[1]' ),
					'phone' => $this->text( $person, './/a[contains(@href,"tel:")][1]' ),
					'email' => $this->attr( $person, './/a[contains(@href,"mailto:")][1]', 'href' ),
					'image' => 0,
				);
			}

			$departments[] = array(
				'anchor_id' => $anchor_id,
				'title'     => $this->text( $dept, './/h3[1] | .//span[1]' ),
				'contacts'  => $contacts,
			);
		}

		return array(
			'departments' => $departments,
		);
	}

	/**
	 * ACM News: Filterleiste (eigene Sektion).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_news_filter_bar( $node ) {
		$filters = array();
		foreach ( $this->query_nodes( $node, './/button[contains(@class,"filter-btn")]' ) as $btn ) {
			$label = trim( $btn->textContent );
			if ( '' === $label ) {
				continue;
			}
			$slug = '';
			if ( $btn instanceof DOMElement && $btn->hasAttribute( 'data-filter' ) ) {
				$slug = $btn->getAttribute( 'data-filter' );
			}
			if ( '' === $slug ) {
				$slug = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $label ) );
			}
			$cls        = $btn instanceof DOMElement ? ' ' . str_replace( "\n", ' ', $btn->getAttribute( 'class' ) ) . ' ' : '';
			$is_default = false !== strpos( $cls, ' active ' );
			$filters[]  = array(
				'label'      => $label,
				'slug'       => $slug,
				'is_default' => $is_default ? 1 : 0,
			);
		}

		return array(
			'filters' => $filters,
		);
	}

	/**
	 * ACM news grid (news archive page).
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_news_grid( $node ) {
		return array(
			'title' => $this->html( $node, './/h2[1]' ),
		);
	}

	/**
	 * ACM news archive section.
	 *
	 * @param DOMNode $node Section node.
	 * @return array<string,mixed>
	 */
	protected function parse_acm_news_archive( $node ) {
		return array(
			'label'          => $this->text( $node, './/p[contains(@class,"section-label")][1]' ),
			'title'          => $this->html( $node, './/h2[1]' ),
			'intro'          => $this->html( $node, './/h2[1]/following-sibling::p[contains(@class,"text-stone-500")][1]' ),
			'posts_per_page' => '10',
			'load_more_text' => $this->text( $node, './/button[@id="news-archive-load-more-btn"] | .//button[contains(@class,"btn-outline")][1]' ),
		);
	}
}
