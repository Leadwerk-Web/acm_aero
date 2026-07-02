<?php
/**
 * Central shared translation packages for ACM runtime fragments.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Shared_Translation_Packages {

	/**
	 * Cached parsed package definitions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	protected static $package_cache = array();

	/**
	 * Sync all shared packages so they appear in translation queues.
	 *
	 * @param string $target_lang Target language.
	 * @return void
	 */
	public static function sync_dashboard_packages( $target_lang = 'en' ) {
		foreach ( self::get_registry() as $entry ) {
			$source_items = self::get_package_source_items( $entry );
			if ( empty( $source_items ) ) {
				continue;
			}

			Leadwerk_Translation_API::sync_string_package(
				(string) $entry['package'],
				$source_items,
				$target_lang
			);
		}
	}

	/**
	 * Build contextual shared sections for one source page editor.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $target_lang    Target language.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_editor_sections( $source_post_id, $target_lang = 'en' ) {
		$source_key = sanitize_key( (string) get_post_meta( (int) $source_post_id, 'leadwerk_source_key', true ) );
		if ( '' === $source_key ) {
			return array();
		}

		$sections = array();
		foreach ( self::get_registry() as $entry ) {
			if ( ! self::is_visible_on_source_key( $entry, $source_key ) ) {
				continue;
			}

			$source_items = self::get_package_source_items( $entry );
			if ( empty( $source_items ) ) {
				continue;
			}

			$package       = (string) $entry['package'];
			$target_records = Leadwerk_Translation_API::sync_string_package( $package, $source_items, $target_lang );
			$definitions   = self::get_package_definitions( $entry );
			$segments      = array();

			foreach ( $definitions as $definition ) {
				$string_name = (string) ( $definition['string_name'] ?? '' );
				if ( '' === $string_name || ! array_key_exists( $string_name, $source_items ) ) {
					continue;
				}

				$record      = is_array( $target_records[ $string_name ] ?? null ) ? $target_records[ $string_name ] : array();
				$translation = (string) ( $record['value'] ?? '' );
				$status      = (string) ( $record['status'] ?? ( '' === trim( $translation ) ? 'not_translated' : 'complete' ) );

				$segments[] = array(
					'id'          => 'shared_' . sanitize_key( (string) ( $definition['segment_id'] ?? $string_name ) ),
					'label'       => (string) ( $definition['label'] ?? $string_name ),
					'source'      => (string) ( $definition['source'] ?? '' ),
					'translation' => $translation,
					'status'      => $status,
					'type'        => (string) ( $definition['type'] ?? 'text' ),
					'input_name'  => 'leadwerk_shared_segments[' . $package . '][' . $string_name . ']',
				);
			}

			if ( empty( $segments ) ) {
				continue;
			}

			$sections[] = array(
				'label'    => (string) ( $entry['label'] ?? 'Shared strings' ),
				'path_key' => (string) ( $entry['section_key'] ?? $package ),
				'segments' => $segments,
				'is_shared' => true,
			);
		}

		return $sections;
	}

	/**
	 * Save contextual shared section submissions.
	 *
	 * @param array<string,mixed> $submitted       Submitted package payloads.
	 * @param int                 $source_post_id  Source post ID.
	 * @param string              $target_lang     Target language.
	 * @return void
	 */
	public static function save_editor_submissions( $submitted, $source_post_id, $target_lang = 'en' ) {
		$submitted  = is_array( $submitted ) ? $submitted : array();
		$source_key = sanitize_key( (string) get_post_meta( (int) $source_post_id, 'leadwerk_source_key', true ) );
		if ( '' === $source_key ) {
			return;
		}

		$packages = array();
		foreach ( self::get_registry() as $entry ) {
			if ( ! self::is_visible_on_source_key( $entry, $source_key ) ) {
				continue;
			}

			$package = (string) $entry['package'];
			if ( ! isset( $packages[ $package ] ) ) {
				$packages[ $package ] = array(
					'entry'        => $entry,
					'source_items' => array(),
				);
			}

			$packages[ $package ]['source_items'] = array_merge(
				$packages[ $package ]['source_items'],
				self::get_package_source_items( $entry )
			);
		}

		foreach ( $packages as $package => $payload ) {
			$source_items = is_array( $payload['source_items'] ?? null ) ? $payload['source_items'] : array();
			if ( empty( $source_items ) ) {
				continue;
			}

			Leadwerk_Translation_API::sync_string_package( $package, $source_items, $target_lang );
			$package_input = is_array( $submitted[ $package ] ?? null ) ? $submitted[ $package ] : array();

			foreach ( $source_items as $string_name => $source_value ) {
				if ( ! array_key_exists( $string_name, $package_input ) ) {
					continue;
				}

				$translation = trim( (string) wp_unslash( $package_input[ $string_name ] ) );
				Leadwerk_Translation_API::upsert_string(
					$package,
					$string_name,
					$target_lang,
					$translation,
					array(
						'source_hash' => md5( (string) $source_value ),
						'status'      => '' === $translation ? 'not_translated' : 'complete',
					)
				);
			}

			Leadwerk_Translation_API::sync_string_package( $package, $source_items, $target_lang );
		}
	}

	/**
	 * Return one combined section status.
	 *
	 * @param array<int,array<string,mixed>> $sections Shared sections.
	 * @return string
	 */
	public static function get_sections_status( $sections ) {
		$has_segments     = false;
		$has_not_done     = false;
		$has_needs_update = false;
		$has_in_progress  = false;

		foreach ( (array) $sections as $section ) {
			$segments = is_array( $section['segments'] ?? null ) ? $section['segments'] : array();
			foreach ( $segments as $segment ) {
				$has_segments = true;
				$status       = sanitize_key( (string) ( $segment['status'] ?? 'not_translated' ) );
				if ( in_array( $status, array( 'not_translated', 'needs_translation' ), true ) ) {
					$has_not_done = true;
					continue;
				}
				if ( in_array( $status, array( 'needs_update', 'outdated' ), true ) ) {
					$has_needs_update = true;
					continue;
				}
				if ( in_array( $status, array( 'in_progress', 'blocked' ), true ) ) {
					$has_in_progress = true;
				}
			}
		}

		if ( ! $has_segments ) {
			return 'complete';
		}
		if ( $has_not_done ) {
			return 'not_translated';
		}
		if ( $has_needs_update ) {
			return 'needs_update';
		}
		if ( $has_in_progress ) {
			return 'in_progress';
		}

		return 'complete';
	}

	/**
	 * Apply shared package translations to the ACM modals partial.
	 *
	 * @param string      $html HTML fragment.
	 * @param string|null $lang Language code.
	 * @return string
	 */
	public static function localize_modals_html( $html, $lang = null ) {
		$html = (string) $html;
		if ( '' === trim( $html ) || ! class_exists( 'Leadwerk_HTML_Segments' ) ) {
			return $html;
		}

		$default_lang = Leadwerk_Translation_API::get_default_language();
		$lang         = sanitize_key( (string) ( $lang ? $lang : Leadwerk_Translation_API::get_current_request_language() ) );
		if ( '' === $lang || $lang === $default_lang ) {
			return $html;
		}

		list( $dom, $root ) = self::load_fragment_dom( $html );
		if ( ! $dom || ! $root ) {
			return $html;
		}

		$xpath = new DOMXPath( $dom );
		foreach ( self::get_registry() as $entry ) {
			if ( 'html_fragment' !== (string) ( $entry['type'] ?? '' ) ) {
				continue;
			}

			$root_id = sanitize_key( (string) ( $entry['root_id'] ?? '' ) );
			if ( '' === $root_id ) {
				continue;
			}

			$node = $xpath->query( './/*[@id="' . $root_id . '"]', $root )->item( 0 );
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$translations = self::get_html_segment_translations( $entry, $lang );
			if ( empty( $translations ) ) {
				continue;
			}

			$translated_html = Leadwerk_HTML_Segments::apply( $dom->saveHTML( $node ), $translations );
			self::replace_node_with_html( $node, $translated_html );
		}

		return self::serialize_root_children( $root );
	}

	/**
	 * Registry describing all contextual shared packages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected static function get_registry() {
		$all_pages = self::get_all_acm_page_source_keys();

		return array(
			array(
				'section_key' => 'shared_flight_modal',
				'label'       => 'Shared: Flight Request Modal',
				'type'        => 'html_fragment',
				'package'     => 'acm_shared_flight_modal',
				'root_id'     => 'flight-modal',
				'visible_on'  => array( 'acm-index-v1', 'acm-aircraft-v1', 'acm-maintenance-v1', 'acm-careers-v1', 'acm-contact-v1' ),
			),
			array(
				'section_key' => 'shared_maintenance_modal',
				'label'       => 'Shared: Maintenance Request Modal',
				'type'        => 'html_fragment',
				'package'     => 'acm_shared_maintenance_modal',
				'root_id'     => 'maintenance-modal',
				'visible_on'  => array( 'acm-index-v1', 'acm-thats-acm-v1', 'acm-charter-v1', 'acm-global-7500-v1', 'acm-global-6000-v1', 'acm-global-xrs-v1', 'acm-aircraft-v1', 'acm-maintenance-v1', 'acm-careers-v1', 'acm-contact-v1' ),
			),
			array(
				'section_key' => 'shared_management_modal',
				'label'       => 'Shared: Management Conversation Modal',
				'type'        => 'html_fragment',
				'package'     => 'acm_shared_management_modal',
				'root_id'     => 'management-modal',
				'visible_on'  => array( 'acm-index-v1', 'acm-thats-acm-v1', 'acm-charter-v1', 'acm-global-7500-v1', 'acm-global-6000-v1', 'acm-global-xrs-v1', 'acm-aircraft-v1', 'acm-maintenance-v1', 'acm-careers-v1', 'acm-contact-v1' ),
			),
			array(
				'section_key' => 'shared_career_modal',
				'label'       => 'Shared: Career Application Modal',
				'type'        => 'html_fragment',
				'package'     => 'acm_shared_career_modal',
				'root_id'     => 'career-modal',
				'visible_on'  => array( 'acm-index-v1', 'acm-global-7500-v1', 'acm-aircraft-v1', 'acm-maintenance-v1', 'acm-careers-v1', 'acm-contact-v1' ),
			),
			array(
				'section_key' => 'shared_starlink_modal',
				'label'       => 'Shared: Starlink Modal',
				'type'        => 'html_fragment',
				'package'     => 'acm_shared_starlink_modal',
				'root_id'     => 'starlink-modal',
				'visible_on'  => array( 'acm-index-v1' ),
			),
			array(
				'section_key' => 'shared_footer_strings',
				'label'       => 'Shared: Footer Strings',
				'type'        => 'string_keys',
				'package'     => 'theme_strings',
				'keys'        => array(
					'footer_tagline' => 'Footer tagline',
				),
				'visible_on'  => $all_pages,
			),
			array(
				'section_key' => 'shared_news_strings',
				'label'       => 'Shared: News Card Strings',
				'type'        => 'string_keys',
				'package'     => 'theme_strings',
				'keys'        => array(
					'news_read_more_label' => 'News card CTA',
				),
				'visible_on'  => array( 'acm-index-v1', 'acm-news-v1' ),
			),
		);
	}

	/**
	 * Package definitions for one registry entry.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function get_package_definitions( $entry ) {
		$cache_key = self::get_cache_key_for_entry( $entry );
		if ( '' === $cache_key ) {
			return array();
		}

		if ( isset( self::$package_cache[ $cache_key ]['definitions'] ) ) {
			return self::$package_cache[ $cache_key ]['definitions'];
		}

		$definitions = array();
		$type        = (string) ( $entry['type'] ?? '' );

		if ( 'html_fragment' === $type ) {
			$fragment_html = self::get_source_fragment_html( $entry );
			if ( '' !== trim( $fragment_html ) && class_exists( 'Leadwerk_HTML_Segments' ) ) {
				$segments = Leadwerk_HTML_Segments::extract( $fragment_html );
				foreach ( $segments as $segment_key => $segment ) {
					if ( ! is_array( $segment ) ) {
						continue;
					}

					$definitions[] = array(
						'segment_key' => (string) $segment_key,
						'segment_id'  => substr( md5( $cache_key . '|' . $segment_key ), 0, 16 ),
						'string_name' => 'seg_' . substr( md5( $cache_key . '|' . $segment_key ), 0, 16 ),
						'label'       => self::build_html_segment_label( $segment ),
						'source'      => (string) ( $segment['source'] ?? '' ),
						'type'        => 'text',
					);
				}
			}
		} elseif ( 'string_keys' === $type ) {
			$source_strings = self::get_theme_source_strings();
			foreach ( (array) ( $entry['keys'] ?? array() ) as $key => $label ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! array_key_exists( $key, $source_strings ) ) {
					continue;
				}

				$definitions[] = array(
					'segment_key' => $key,
					'segment_id'  => $key,
					'string_name' => $key,
					'label'       => (string) $label,
					'source'      => (string) $source_strings[ $key ],
					'type'        => 'text',
				);
			}
		}

		self::$package_cache[ $cache_key ]['definitions'] = $definitions;
		return $definitions;
	}

	/**
	 * Build a unique cache key for one registry entry.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 * @return string
	 */
	protected static function get_cache_key_for_entry( $entry ) {
		$package     = sanitize_key( (string) ( $entry['package'] ?? '' ) );
		$section_key = sanitize_key( (string) ( $entry['section_key'] ?? '' ) );

		if ( '' === $package ) {
			return '';
		}

		return '' !== $section_key ? $package . '__' . $section_key : $package;
	}

	/**
	 * Source items keyed by string name for one entry.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 * @return array<string,string>
	 */
	protected static function get_package_source_items( $entry ) {
		$items = array();
		foreach ( self::get_package_definitions( $entry ) as $definition ) {
			$string_name = (string) ( $definition['string_name'] ?? '' );
			if ( '' === $string_name ) {
				continue;
			}
			$items[ $string_name ] = (string) ( $definition['source'] ?? '' );
		}

		return $items;
	}

	/**
	 * Find translated html segment values for runtime application.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 * @param string              $lang  Language code.
	 * @return array<string,string>
	 */
	protected static function get_html_segment_translations( $entry, $lang ) {
		$translations = array();
		$records      = Leadwerk_Translation_API::get_package_string_records( (string) $entry['package'], $lang );

		foreach ( self::get_package_definitions( $entry ) as $definition ) {
			$string_name = (string) ( $definition['string_name'] ?? '' );
			$segment_key = (string) ( $definition['segment_key'] ?? '' );
			if ( '' === $string_name || '' === $segment_key ) {
				continue;
			}

			$value = trim( (string) ( $records[ $string_name ]['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}

			$translations[ $segment_key ] = $value;
		}

		return $translations;
	}

	/**
	 * Whether one registry entry is visible for a page source key.
	 *
	 * @param array<string,mixed> $entry      Registry entry.
	 * @param string              $source_key Source key.
	 * @return bool
	 */
	protected static function is_visible_on_source_key( $entry, $source_key ) {
		$visible_on = array_map( 'sanitize_key', (array) ( $entry['visible_on'] ?? array() ) );
		return in_array( sanitize_key( (string) $source_key ), $visible_on, true );
	}

	/**
	 * Build one readable label for an extracted HTML segment.
	 *
	 * @param array<string,mixed> $segment Segment metadata.
	 * @return string
	 */
	protected static function build_html_segment_label( $segment ) {
		$attribute = sanitize_key( (string) ( $segment['attribute'] ?? '' ) );
		$source    = self::normalize_source_label( (string) ( $segment['source'] ?? '' ) );
		if ( '' === $source ) {
			$source = (string) ( $segment['key'] ?? 'Segment' );
		}

		if ( '' !== $attribute ) {
			return ucfirst( $attribute ) . ': ' . self::truncate_label( $source );
		}

		return self::truncate_label( $source );
	}

	/**
	 * Read source fragment html from the shared partial file.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 * @return string
	 */
	protected static function get_source_fragment_html( $entry ) {
		$root_id = sanitize_key( (string) ( $entry['root_id'] ?? '' ) );
		if ( '' === $root_id ) {
			return '';
		}

		$partials_dir = function_exists( 'get_stylesheet_directory' )
			? trailingslashit( get_stylesheet_directory() ) . 'partials/'
			: '';
		$path         = $partials_dir . 'acm-modals.html';
		if ( '' === $partials_dir || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$html = file_get_contents( $path );
		if ( ! is_string( $html ) || '' === $html ) {
			return '';
		}

		list( $dom, $root ) = self::load_fragment_dom( $html );
		if ( ! $dom || ! $root ) {
			return '';
		}

		$xpath = new DOMXPath( $dom );
		$node  = $xpath->query( './/*[@id="' . $root_id . '"]', $root )->item( 0 );
		return $node instanceof DOMElement ? $dom->saveHTML( $node ) : '';
	}

	/**
	 * Load an HTML fragment into a wrapper root.
	 *
	 * @param string $html HTML fragment.
	 * @return array{0:?DOMDocument,1:?DOMElement}
	 */
	protected static function load_fragment_dom( $html ) {
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-shared-root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array( null, null );
		}

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="leadwerk-shared-root"]' )->item( 0 );
		return array( $dom, $root instanceof DOMElement ? $root : null );
	}

	/**
	 * Replace one DOM node with the provided HTML fragment.
	 *
	 * @param DOMElement $node DOM node.
	 * @param string     $html Replacement html.
	 * @return void
	 */
	protected static function replace_node_with_html( DOMElement $node, $html ) {
		$parent = $node->parentNode;
		if ( ! $parent instanceof DOMNode ) {
			return;
		}

		list( $fragment_dom, $fragment_root ) = self::load_fragment_dom( $html );
		if ( ! $fragment_dom || ! $fragment_root ) {
			return;
		}

		$imports = array();
		foreach ( iterator_to_array( $fragment_root->childNodes ) as $child ) {
			if ( ! $child instanceof DOMNode ) {
				continue;
			}
			$imports[] = $node->ownerDocument->importNode( $child, true );
		}

		foreach ( $imports as $import ) {
			$parent->insertBefore( $import, $node );
		}

		$parent->removeChild( $node );
	}

	/**
	 * Serialize wrapper child nodes back to plain fragment html.
	 *
	 * @param DOMElement $root Wrapper element.
	 * @return string
	 */
	protected static function serialize_root_children( DOMElement $root ) {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			if ( ! $child instanceof DOMNode ) {
				continue;
			}
			$html .= $root->ownerDocument->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Source theme strings for shared contextual keys.
	 *
	 * @return array<string,string>
	 */
	protected static function get_theme_source_strings() {
		if ( function_exists( 'leadwerk_theme_get_theme_strings' ) ) {
			$strings = leadwerk_theme_get_theme_strings( 'de' );
			if ( is_array( $strings ) ) {
				return $strings;
			}
		}

		$strings = Leadwerk_Translation_API::get_package_strings( 'theme_strings', 'de', array() );
		if ( ! empty( $strings ) ) {
			return $strings;
		}

		$raw = Leadwerk_Translation_API::get_localized_option( 'theme_strings', 'de', '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Known ACM page source keys.
	 *
	 * @return array<int,string>
	 */
	protected static function get_all_acm_page_source_keys() {
		return array(
			'acm-index-v1',
			'acm-thats-acm-v1',
			'acm-charter-v1',
			'acm-global-7500-v1',
			'acm-global-6000-v1',
			'acm-global-xrs-v1',
			'acm-aircraft-v1',
			'acm-maintenance-v1',
			'acm-careers-v1',
			'acm-contact-v1',
			'acm-news-v1',
			'acm-impressum-v1',
			'acm-datenschutz-v1',
		);
	}

	/**
	 * Normalize label text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function normalize_source_label( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Keep labels compact in the editor.
	 *
	 * @param string $value Raw label.
	 * @return string
	 */
	protected static function truncate_label( $value ) {
		$value = (string) $value;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > 72 ? mb_substr( $value, 0, 69 ) . '...' : $value;
		}

		return strlen( $value ) > 72 ? substr( $value, 0, 69 ) . '...' : $value;
	}
}
