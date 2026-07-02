<?php
/**
 * Load and export JSON-based translation seeds for ACM / Leadwerk imports.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_Seed_Manager {

	/**
	 * Seed file name.
	 */
	const FILE_NAME = 'translation-seeds.json';

	/**
	 * Manifest directory.
	 *
	 * @var string
	 */
	protected $manifest_dir = '';

	/**
	 * Source root directory.
	 *
	 * @var string
	 */
	protected $source_root = '';

	/**
	 * Loaded seed data.
	 *
	 * @var array<string,mixed>
	 */
	protected $seed_data = array();

	/**
	 * Per-page seed diagnostics captured during lookup.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	protected $seed_context = array();

	/**
	 * Constructor.
	 *
	 * @param string $manifest_dir Manifest directory.
	 * @param string $source_root  Source root.
	 */
	public function __construct( $manifest_dir = '', $source_root = '' ) {
		$this->manifest_dir = rtrim( (string) $manifest_dir, '/\\' ) . DIRECTORY_SEPARATOR;
		$this->source_root  = rtrim( (string) $source_root, '/\\' );
		$this->load();
	}

	/**
	 * Return loaded seed data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_seed_data() {
		return $this->seed_data;
	}

	/**
	 * Return one page seed.
	 *
	 * @param array<string,mixed>|string $page_config_or_source_key Page config or source key.
	 * @param string                     $lang                      Language code.
	 * @return array<string,mixed>
	 */
	public function get_page_seed( $page_config_or_source_key, $lang = 'en' ) {
		$source_key = $this->resolve_source_key( $page_config_or_source_key );
		if ( '' === $source_key ) {
			return array();
		}

		$page = $this->seed_data['pages'][ $source_key ] ?? array();
		$page = is_array( $page ) ? $page : array();
		$seed = $page[ $lang ] ?? array();
		$seed = is_array( $seed ) ? $seed : array();
		$this->set_seed_context( $source_key, $lang, $seed, false, false );

		if ( empty( $seed ) ) {
			return $seed;
		}

		if ( $this->is_structured_seed( $seed ) ) {
			$this->set_seed_context( $source_key, $lang, $seed, false, false );
			return $seed;
		}

		if ( ! is_array( $page_config_or_source_key ) ) {
			return $seed;
		}

		$converted = $this->convert_legacy_seed_to_structured( $page_config_or_source_key, $seed, $lang );
		if ( empty( $converted ) ) {
			return $seed;
		}

		$this->seed_data['pages'][ $source_key ][ $lang ] = $converted;
		$this->seed_data['version']                    = '3';
		$persisted = $this->persist_seed_data();
		$this->set_seed_context( $source_key, $lang, $converted, true, $persisted );
		return $converted;
	}

	/**
	 * Return lookup diagnostics for one page seed.
	 *
	 * @param array<string,mixed>|string $page_config_or_source_key Page config or source key.
	 * @param string                     $lang                      Language code.
	 * @return array<string,mixed>
	 */
	public function get_page_seed_context( $page_config_or_source_key, $lang = 'en' ) {
		$source_key = $this->resolve_source_key( $page_config_or_source_key );
		if ( '' === $source_key ) {
			return $this->build_seed_context( array(), false, false );
		}

		if ( ! isset( $this->seed_context[ $source_key ][ $lang ] ) ) {
			$this->get_page_seed( $page_config_or_source_key, $lang );
		}

		return is_array( $this->seed_context[ $source_key ][ $lang ] ?? null )
			? $this->seed_context[ $source_key ][ $lang ]
			: $this->build_seed_context( array(), false, false );
	}

	/**
	 * Whether a page seed exists.
	 *
	 * @param array<string,mixed>|string $page_config_or_source_key Page config or source key.
	 * @param string                     $lang                      Language code.
	 * @return bool
	 */
	public function has_page_seed( $page_config_or_source_key, $lang = 'en' ) {
		return ! empty( $this->get_page_seed( $page_config_or_source_key, $lang ) );
	}

	/**
	 * Return one seed-derived translation status.
	 *
	 * @param array<string,mixed>|string $page_config_or_source_key Page config or source key.
	 * @param string                     $lang                      Language code.
	 * @return string
	 */
	public function get_page_seed_status( $page_config_or_source_key, $lang = 'en' ) {
		$seed = $this->get_page_seed( $page_config_or_source_key, $lang );
		if ( empty( $seed ) || empty( $seed['paths'] ) || ! is_array( $seed['paths'] ) ) {
			return 'not_translated';
		}

		$total      = 0;
		$translated = 0;

		foreach ( $seed['paths'] as $path_seed ) {
			if ( ! is_array( $path_seed ) ) {
				continue;
			}

			if ( isset( $path_seed['segments'] ) && is_array( $path_seed['segments'] ) ) {
				foreach ( $path_seed['segments'] as $segment_translation ) {
					++$total;
					if ( '' !== trim( (string) $segment_translation ) ) {
						++$translated;
					}
				}
				continue;
			}

			++$total;
			if ( '' !== trim( (string) ( $path_seed['translation'] ?? '' ) ) ) {
				++$translated;
			}
		}

		if ( 0 === $total || 0 === $translated ) {
			return 'not_translated';
		}

		if ( $translated < $total ) {
			return 'in_progress';
		}

		return 'complete';
	}

	/**
	 * Export a fresh structured seed file from legacy EN HTML sources.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return array<string,mixed>
	 */
	public function export_from_legacy_html( $manifest ) {
		$pages  = array();
		$filler = new Leadwerk_ACF_Filler( $this->source_root, null, array() );

		foreach ( (array) ( $manifest['pages'] ?? array() ) as $page_config ) {
			$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			if ( '' === $source_key ) {
				continue;
			}

			$translation_path = $this->get_legacy_translation_relative_path( $page_config );
			if ( ! is_file( $this->resolve_source_path( $translation_path ) ) ) {
				continue;
			}

			$payload = $filler->build_page_payload( $page_config, 'en', $translation_path );
			if ( empty( $payload ) ) {
				continue;
			}

			$group = class_exists( 'Leadwerk_Content_Schema' )
				? Leadwerk_Content_Schema::get_group( (string) ( $page_config['field_name'] ?? '' ) )
				: null;
			if ( ! $group || ! class_exists( 'Leadwerk_Translation_Sync' ) ) {
				continue;
			}

			$pages[ $source_key ] = array(
				'en' => array(
					'translated_title' => $this->resolve_translated_title( $page_config, $payload ),
					'document_title'   => (string) ( $payload['document_title'] ?? '' ),
					'meta_description' => (string) ( $payload['meta_description'] ?? '' ),
					'paths'            => Leadwerk_Translation_Sync::build_seed_paths_from_values( $group, $payload['value'] ?? array() ),
				),
			);
		}

		return array(
			'version'   => '3',
			'languages' => array(
				'source' => 'de',
				'target' => 'en',
			),
			'pages'     => $pages,
		);
	}

	/**
	 * Write a fresh seed file to disk from legacy EN HTML sources.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return bool
	 */
	public function export_to_file( $manifest ) {
		$data = $this->export_from_legacy_html( $manifest );
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) || '' === $json ) {
			return false;
		}

		$written = file_put_contents( $this->get_seed_file_path(), $json );
		if ( false === $written ) {
			return false;
		}

		$this->seed_data = $data;
		return true;
	}

	/**
	 * Rewrite a legacy HTML-segment seed file into structured path seeds.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return bool
	 */
	public function migrate_legacy_file( $manifest ) {
		$data = $this->seed_data;
		if ( empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return false;
		}

		$changed = false;
		foreach ( (array) ( $manifest['pages'] ?? array() ) as $page_config ) {
			$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			if ( '' === $source_key ) {
				continue;
			}

			$page_seed = $data['pages'][ $source_key ]['en'] ?? array();
			if ( ! is_array( $page_seed ) || empty( $page_seed ) || $this->is_structured_seed( $page_seed ) ) {
				continue;
			}

			$converted = $this->convert_legacy_seed_to_structured( $page_config, $page_seed, 'en' );
			if ( empty( $converted ) ) {
				continue;
			}

			$data['pages'][ $source_key ]['en'] = $converted;
			$changed                            = true;
		}

		if ( ! $changed ) {
			return false;
		}

		$data['version'] = '3';
		$this->seed_data = $data;

		return $this->persist_seed_data();
	}

	/**
	 * Load seed file from disk.
	 *
	 * @return void
	 */
	protected function load() {
		$path = $this->get_seed_file_path();
		if ( ! is_file( $path ) ) {
			$this->seed_data = $this->get_empty_seed_data();
			return;
		}

		$json = file_get_contents( $path );
		$data = json_decode( (string) $json, true );
		$this->seed_data = is_array( $data ) ? $data : $this->get_empty_seed_data();
	}

	/**
	 * Return empty seed data.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_empty_seed_data() {
		return array(
			'version'   => '3',
			'languages' => array(
				'source' => 'de',
				'target' => 'en',
			),
			'pages'     => array(),
		);
	}

	/**
	 * Return the seed file path.
	 *
	 * @return string
	 */
	protected function get_seed_file_path() {
		return $this->manifest_dir . self::FILE_NAME;
	}

	/**
	 * Persist the in-memory seed data to disk.
	 *
	 * @return bool
	 */
	protected function persist_seed_data() {
		$this->seed_data['version'] = '3';

		$json = wp_json_encode( $this->seed_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) || '' === $json ) {
			return false;
		}

		return false !== file_put_contents( $this->get_seed_file_path(), $json );
	}

	/**
	 * Store seed diagnostics for later importer reporting.
	 *
	 * @param string              $source_key      Source key.
	 * @param string              $lang            Language code.
	 * @param array<string,mixed> $seed            Seed data.
	 * @param bool                $migrated_legacy Whether a legacy seed was migrated.
	 * @param bool                $persisted       Whether the migrated seed was written to disk.
	 * @return void
	 */
	protected function set_seed_context( $source_key, $lang, $seed, $migrated_legacy, $persisted ) {
		if ( ! isset( $this->seed_context[ $source_key ] ) || ! is_array( $this->seed_context[ $source_key ] ) ) {
			$this->seed_context[ $source_key ] = array();
		}

		$this->seed_context[ $source_key ][ $lang ] = $this->build_seed_context( $seed, $migrated_legacy, $persisted );
	}

	/**
	 * Build a normalized seed context payload.
	 *
	 * @param array<string,mixed> $seed            Seed data.
	 * @param bool                $migrated_legacy Whether a legacy seed was migrated.
	 * @param bool                $persisted       Whether the migrated seed was written to disk.
	 * @return array<string,mixed>
	 */
	protected function build_seed_context( $seed, $migrated_legacy, $persisted ) {
		$paths            = is_array( $seed['paths'] ?? null ) ? $seed['paths'] : array();
		$total_paths      = 0;
		$translated_paths = 0;

		foreach ( $paths as $path_seed ) {
			if ( ! is_array( $path_seed ) ) {
				continue;
			}

			++$total_paths;
			if ( '' !== trim( (string) ( $path_seed['translation'] ?? '' ) ) ) {
				++$translated_paths;
			}
		}

		return array(
			'structured_seed_found' => ! empty( $paths ) && $this->is_structured_seed( $seed ),
			'legacy_seed_migrated'  => (bool) $migrated_legacy,
			'persisted'             => (bool) $persisted,
			'total_seed_paths'      => $total_paths,
			'translated_seed_paths' => $translated_paths,
		);
	}

	/**
	 * Resolve source key from page config or key string.
	 *
	 * @param array<string,mixed>|string $page_config_or_source_key Page config or source key.
	 * @return string
	 */
	protected function resolve_source_key( $page_config_or_source_key ) {
		if ( is_array( $page_config_or_source_key ) ) {
			return sanitize_key( (string) ( $page_config_or_source_key['source_key'] ?? '' ) );
		}

		return sanitize_key( (string) $page_config_or_source_key );
	}

	/**
	 * Whether one seed already uses structured field-path translations.
	 *
	 * @param array<string,mixed> $seed Page seed.
	 * @return bool
	 */
	protected function is_structured_seed( $seed ) {
		$paths = $seed['paths'] ?? array();
		if ( empty( $paths ) || ! is_array( $paths ) ) {
			return false;
		}

		foreach ( $paths as $path_seed ) {
			if ( ! is_array( $path_seed ) ) {
				continue;
			}

			if ( isset( $path_seed['segments'] ) && is_array( $path_seed['segments'] ) ) {
				return false;
			}

			if ( array_key_exists( 'translation', $path_seed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert one legacy HTML-segment seed to structured field-path translations.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param array<string,mixed> $seed        Legacy seed.
	 * @param string              $lang        Language.
	 * @return array<string,mixed>
	 */
	protected function convert_legacy_seed_to_structured( $page_config, $seed, $lang ) {
		$group = class_exists( 'Leadwerk_Content_Schema' )
			? Leadwerk_Content_Schema::get_group( (string) ( $page_config['field_name'] ?? '' ) )
			: null;
		if ( ! $group || ! class_exists( 'Leadwerk_Translation_Sync' ) ) {
			return array();
		}

		$filler = new Leadwerk_ACF_Filler( $this->source_root, null, array() );

		if ( empty( $group['layouts'] ) ) {
			$payload = $filler->build_page_payload( $page_config, 'de' );
			$value   = is_array( $payload['value'] ?? null ) ? $payload['value'] : array();
			$paths   = is_array( $seed['paths'] ?? null ) ? $seed['paths'] : array();

			$headline_key = 'scalar__headline';
			if ( isset( $paths[ $headline_key ]['translation'] ) ) {
				$value['headline'] = (string) $paths[ $headline_key ]['translation'];
			} elseif ( '' !== trim( (string) ( $seed['translated_title'] ?? '' ) ) ) {
				$value['headline'] = (string) $seed['translated_title'];
			}

			$content_key = 'scalar__content';
			if ( isset( $paths[ $content_key ] ) ) {
				$value['content'] = $this->apply_legacy_seed_to_html(
					(string) ( $payload['value']['content'] ?? '' ),
					$paths[ $content_key ]
				);
			}

			return array(
				'translated_title' => $this->resolve_translated_title( $page_config, array( 'value' => $value ) ),
				'document_title'   => (string) ( $seed['document_title'] ?? '' ),
				'meta_description' => (string) ( $seed['meta_description'] ?? '' ),
				'paths'            => Leadwerk_Translation_Sync::build_seed_paths_from_values( $group, $value ),
			);
		}

		$html = $this->load_source_html( (string) ( $page_config['source_file'] ?? '' ) );
		if ( '' === $html ) {
			return array();
		}

		$source_sections = $this->extract_body_sections_html( $html );
		$translated      = array();
		$legacy_paths    = is_array( $seed['paths'] ?? null ) ? $seed['paths'] : array();

		foreach ( $source_sections as $index => $section_html ) {
			$legacy_key    = $index . '__html';
			$translated[]  = isset( $legacy_paths[ $legacy_key ] )
				? $this->apply_legacy_seed_to_html( $section_html, $legacy_paths[ $legacy_key ] )
				: '';
		}

		$payload = $filler->build_page_payload_from_sections(
			$page_config,
			$translated,
			array(
				'document_title'   => (string) ( $seed['document_title'] ?? '' ),
				'meta_description' => (string) ( $seed['meta_description'] ?? '' ),
				'body_class'       => $this->resolve_source_body_class( $page_config ),
			),
			$lang
		);

		return array(
			'translated_title' => $this->resolve_translated_title( $page_config, $payload ),
			'document_title'   => (string) ( $seed['document_title'] ?? '' ),
			'meta_description' => (string) ( $seed['meta_description'] ?? '' ),
			'paths'            => Leadwerk_Translation_Sync::build_seed_paths_from_values( $group, $payload['value'] ?? array() ),
		);
	}

	/**
	 * Apply a legacy seed entry to one source HTML fragment.
	 *
	 * @param string               $source_html Source HTML.
	 * @param array<string,mixed>  $legacy_path Legacy path bundle.
	 * @return string
	 */
	protected function apply_legacy_seed_to_html( $source_html, $legacy_path ) {
		if ( '' === trim( $source_html ) || ! is_array( $legacy_path ) ) {
			return '';
		}

		if ( isset( $legacy_path['translation'] ) ) {
			return (string) $legacy_path['translation'];
		}

		$segments = is_array( $legacy_path['segments'] ?? null ) ? $legacy_path['segments'] : array();
		if ( empty( $segments ) || ! class_exists( 'Leadwerk_HTML_Segments' ) ) {
			return '';
		}

		return Leadwerk_HTML_Segments::apply( $source_html, $segments );
	}

	/**
	 * Load one source HTML document from source_root.
	 *
	 * @param string $relative_file Relative source file.
	 * @return string
	 */
	protected function load_source_html( $relative_file ) {
		$path = $this->resolve_source_path( $relative_file );
		if ( '' === $relative_file || ! is_file( $path ) ) {
			return '';
		}

		$html = file_get_contents( $path );
		return false === $html ? '' : (string) $html;
	}

	/**
	 * Resolve the source page body class from exact shells or source HTML.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function resolve_source_body_class( $page_config ) {
		$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
		if ( '' !== $source_key && function_exists( 'leadwerk_theme_get_source_template_body_class' ) ) {
			$body_class = trim( (string) leadwerk_theme_get_source_template_body_class( $source_key ) );
			if ( '' !== $body_class ) {
				return $body_class;
			}
		}

		$html = $this->load_source_html( (string) ( $page_config['source_file'] ?? '' ) );
		if ( '' !== $html && preg_match( '/<body[^>]*class="([^"]+)"/i', $html, $matches ) ) {
			return $this->normalize_body_class_string( $matches[1] ?? '' );
		}

		return '';
	}

	/**
	 * Normalize one body class string.
	 *
	 * @param string $body_class Body class string.
	 * @return string
	 */
	protected function normalize_body_class_string( $body_class ) {
		$classes = preg_split( '/\s+/', trim( (string) $body_class ) );
		$classes = is_array( $classes ) ? $classes : array();
		$classes = array_map( 'sanitize_html_class', $classes );
		$classes = array_values( array_unique( array_filter( $classes ) ) );

		return implode( ' ', $classes );
	}

	/**
	 * Resolve one relative source path.
	 *
	 * @param string $relative_file Relative file.
	 * @return string
	 */
	protected function resolve_source_path( $relative_file ) {
		return $this->source_root . DIRECTORY_SEPARATOR . ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative_file ), DIRECTORY_SEPARATOR );
	}

	/**
	 * Extract top-level body sections as HTML strings.
	 *
	 * @param string $html Source HTML.
	 * @return array<int,string>
	 */
	protected function extract_body_sections_html( $html ) {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$xpath    = new DOMXPath( $dom );
		$sections = array();

		// ACM HTML: sections are inside <main>, legacy: direct body children.
		$main_nodes = $xpath->query( '//body/main/section' );
		$body_nodes = $xpath->query( '//body/section' );
		$source     = ( $main_nodes instanceof DOMNodeList && $main_nodes->length > 0 ) ? $main_nodes : $body_nodes;

		if ( $source instanceof DOMNodeList ) {
			foreach ( $source as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}

				$sections[] = $dom->saveHTML( $node );
			}
		}

		return $sections;
	}

	/**
	 * Resolve the legacy EN HTML path for one page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function get_legacy_translation_relative_path( $page_config ) {
		$source_file = (string) ( $page_config['source_file'] ?? '' );
		return 'en/' . ltrim( $source_file, '/' );
	}

	/**
	 * Resolve a human title for one translated post seed.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param array<string,mixed> $payload     EN payload.
	 * @return string
	 */
	protected function resolve_translated_title( $page_config, $payload ) {
		if ( ! empty( $page_config['translated_title'] ) ) {
			$title = (string) $page_config['translated_title'];
		} else {
			$headline = (string) ( $payload['value']['headline'] ?? '' );
			if ( '' !== trim( $headline ) ) {
				$title = $headline;
			} else {
				$document_title = trim( (string) ( $payload['document_title'] ?? '' ) );
				if ( '' !== $document_title ) {
					$parts = preg_split( '/\s+[|\-]\s+/', $document_title );
					if ( is_array( $parts ) && ! empty( $parts[0] ) ) {
						$title = trim( (string) $parts[0] );
					} else {
						$title = $document_title;
					}
				} else {
					$title = (string) ( $page_config['title'] ?? '' );
				}
			}
		}

		return trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
