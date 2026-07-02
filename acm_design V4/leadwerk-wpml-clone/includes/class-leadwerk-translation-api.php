<?php
/**
 * Translation registry, settings and string helpers.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_API {

	const META_LANG          = 'leadwerk_lang';
	const META_TRID          = 'leadwerk_trid';
	const META_SOURCE_ID     = 'leadwerk_translation_source_id';
	const META_SEGMENTS      = 'leadwerk_translation_segments';
	const META_LANG_ROOT     = 'leadwerk_lang_root';
	const META_SEED_SNAPSHOT = 'leadwerk_translation_seed_snapshot';
	const META_PUBLIC_SLUG   = 'leadwerk_public_slug';
	const META_STATUS        = 'leadwerk_translation_status';

	const OPTION_SETTINGS    = 'leadwerk_translation_settings';
	const USER_META_ADMIN_LANG = 'leadwerk_admin_language';

	const TABLE_ELEMENTS = 'leadwerk_translation_elements';
	const TABLE_STRINGS  = 'leadwerk_translation_strings';
	const TABLE_MEMORY   = 'leadwerk_translation_memory';

	/**
	 * Current front-end request language.
	 *
	 * @var string
	 */
	protected static $current_request_language = 'de';

	/**
	 * Cached settings to avoid repeated get_option calls.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $settings_cache = null;

	/**
	 * Locale map for language codes.
	 *
	 * @var array<string,string>
	 */
	protected static $locale_map = array(
		'de' => 'de_DE',
		'en' => 'en_US',
		'fr' => 'fr_FR',
		'es' => 'es_ES',
		'it' => 'it_IT',
		'tr' => 'tr_TR',
		'nl' => 'nl_NL',
		'pt' => 'pt_PT',
		'pl' => 'pl_PL',
		'ru' => 'ru_RU',
	);

	/**
	 * Default plugin settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_default_settings() {
		return array(
			'default_language'        => 'de',
			'active_languages'        => array( 'de', 'en' ),
			'url_mode'                => 'directory_prefix',
			'editor_mode'             => 'classic_segments',
			'translation_jobs'        => 'status_only',
			'translatable_post_types' => array( 'page', 'acm_news' ),
			'translatable_taxonomies' => array(),
		);
	}

	/**
	 * Return merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$settings = get_option( self::OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$merged   = array_merge( self::get_default_settings(), $settings );

		$merged['active_languages'] = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) $merged['active_languages'] )
				)
			)
		);
		if ( empty( $merged['active_languages'] ) ) {
			$merged['active_languages'] = array( 'de', 'en' );
		}

		$merged['default_language'] = sanitize_key( (string) $merged['default_language'] );
		if ( ! in_array( $merged['default_language'], $merged['active_languages'], true ) ) {
			array_unshift( $merged['active_languages'], $merged['default_language'] );
		}

		$merged['translatable_post_types'] = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) $merged['translatable_post_types'] )
				)
			)
		);
		$merged['translatable_post_types'] = array_values(
			array_filter(
				$merged['translatable_post_types'],
				static function ( $pt ) {
					return 'attachment' !== $pt;
				}
			)
		);
		if ( ! in_array( 'acm_news', $merged['translatable_post_types'], true ) ) {
			$merged['translatable_post_types'][] = 'acm_news';
			$merged['translatable_post_types']   = array_values( array_unique( $merged['translatable_post_types'] ) );
		}
		$merged['translatable_taxonomies'] = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) $merged['translatable_taxonomies'] )
				)
			)
		);

		self::$settings_cache = $merged;
		return $merged;
	}

	/**
	 * Persist plugin settings.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 * @return void
	 */
	public static function update_settings( $settings ) {
		self::$settings_cache = null;
		$incoming = (array) $settings;
		if ( array_key_exists( 'translatable_post_types', $incoming ) ) {
			$incoming['translatable_post_types'] = array_values(
				array_filter(
					array_map( 'sanitize_key', (array) $incoming['translatable_post_types'] ),
					static function ( $pt ) {
						return 'attachment' !== $pt;
					}
				)
			);
		}
		update_option( self::OPTION_SETTINGS, array_merge( self::get_settings(), $incoming ) );
		self::$settings_cache = null;
	}

	/**
	 * Get the default language.
	 *
	 * @return string
	 */
	public static function get_default_language() {
		return sanitize_key( (string) self::get_settings()['default_language'] );
	}

	/**
	 * Return active language config.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_active_languages() {
		$out = array();

		foreach ( self::get_settings()['active_languages'] as $code ) {
			$out[ $code ] = array(
				'code'   => $code,
				'label'  => self::get_language_label( $code ),
				'prefix' => self::get_language_prefix( $code ),
				'locale' => isset( self::$locale_map[ $code ] ) ? self::$locale_map[ $code ] : $code,
			);
		}

		return $out;
	}

	/**
	 * Get public path prefix for one language.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_language_prefix( $lang ) {
		$lang = sanitize_key( (string) $lang );
		return self::get_default_language() === $lang ? '' : $lang;
	}

	/**
	 * Language label.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_language_label( $lang ) {
		$labels = array(
			'de' => 'Deutsch',
			'en' => 'English',
			'fr' => 'Français',
			'es' => 'Español',
			'it' => 'Italiano',
			'tr' => 'Türkçe',
			'nl' => 'Nederlands',
			'pt' => 'Português',
			'pl' => 'Polski',
			'ru' => 'Русский',
		);
		$lang = sanitize_key( (string) $lang );
		return isset( $labels[ $lang ] ) ? $labels[ $lang ] : strtoupper( $lang );
	}

	/**
	 * Whether a post type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_translatable_post_type( $post_type ) {
		return in_array( sanitize_key( (string) $post_type ), self::get_settings()['translatable_post_types'], true );
	}

	/**
	 * Whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function is_translatable_taxonomy( $taxonomy ) {
		return in_array( sanitize_key( (string) $taxonomy ), self::get_settings()['translatable_taxonomies'], true );
	}

	/**
	 * Get current admin language filter.
	 *
	 * @return string
	 */
	public static function get_admin_language() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::get_default_language();
		}

		$lang = sanitize_key( (string) get_user_meta( $user_id, self::USER_META_ADMIN_LANG, true ) );
		return in_array( $lang, self::get_settings()['active_languages'], true ) ? $lang : self::get_default_language();
	}

	/**
	 * Persist current admin language filter.
	 *
	 * @param string $lang Language code.
	 * @return void
	 */
	public static function set_admin_language( $lang ) {
		$lang    = sanitize_key( (string) $lang );
		$user_id = get_current_user_id();
		if ( $user_id && in_array( $lang, self::get_settings()['active_languages'], true ) ) {
			update_user_meta( $user_id, self::USER_META_ADMIN_LANG, $lang );
		}
	}

	/**
	 * Store current front-end request language.
	 *
	 * @param string $lang Language code.
	 * @return void
	 */
	public static function set_current_request_language( $lang ) {
		self::$current_request_language = sanitize_key( (string) $lang ) ?: self::get_default_language();
	}

	/**
	 * Return current front-end request language.
	 *
	 * @return string
	 */
	public static function get_current_request_language() {
		return self::$current_request_language ?: self::get_default_language();
	}

	/**
	 * Get registry table name.
	 *
	 * @return string
	 */
	public static function get_elements_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_ELEMENTS;
	}

	/**
	 * Get string table name.
	 *
	 * @return string
	 */
	public static function get_strings_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_STRINGS;
	}

	/**
	 * Get translation memory table name.
	 *
	 * @return string
	 */
	public static function get_memory_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_MEMORY;
	}

	/**
	 * Normalize content before translation memory hashing/lookup.
	 *
	 * @param string $value        Raw value.
	 * @param string $content_type Content type.
	 * @return string
	 */
	public static function normalize_translation_memory_source( $value, $content_type = 'text' ) {
		$value        = (string) $value;
		$content_type = sanitize_key( (string) $content_type );

		if ( 'richtext' === $content_type ) {
			$value = wp_strip_all_tags( $value );
		}

		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( "\xc2\xa0", ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Build a stable hash for translation memory lookup.
	 *
	 * @param string $value        Raw value.
	 * @param string $content_type Content type.
	 * @return string
	 */
	public static function build_translation_memory_hash( $value, $content_type = 'text' ) {
		return md5( self::normalize_translation_memory_source( $value, $content_type ) );
	}

	/**
	 * Convert a post into a registry element type.
	 *
	 * @param string|int|WP_Post $post Post object, ID or post type slug.
	 * @return string
	 */
	public static function get_post_element_type( $post ) {
		$post_type = $post instanceof WP_Post ? $post->post_type : ( is_numeric( $post ) ? get_post_type( (int) $post ) : (string) $post );
		return 'post_' . sanitize_key( (string) $post_type );
	}

	/**
	 * Convert a taxonomy into a registry element type.
	 *
	 * @param string|WP_Term $taxonomy Taxonomy or term object.
	 * @return string
	 */
	public static function get_term_element_type( $taxonomy ) {
		$taxonomy = $taxonomy instanceof WP_Term ? $taxonomy->taxonomy : $taxonomy;
		return 'term_' . sanitize_key( (string) $taxonomy );
	}

	/**
	 * Create a new translation group id.
	 *
	 * @return string
	 */
	public static function generate_trid() {
		return 'lw_' . wp_generate_uuid4();
	}

	/**
	 * Fetch one registry record.
	 *
	 * @param string $element_type Element type.
	 * @param int    $element_id   Element ID.
	 * @return array<string,mixed>
	 */
	public static function get_registry_record( $element_type, $element_id ) {
		$cache_key = "registry_{$element_type}_{$element_id}";
		$cached    = wp_cache_get( $cache_key, 'leadwerk_translation' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_elements_table_name() . " WHERE element_type = %s AND element_id = %d LIMIT 1",
				(string) $element_type,
				(int) $element_id
			),
			ARRAY_A
		);

		$result = is_array( $row ) ? $row : array();
		wp_cache_set( $cache_key, $result, 'leadwerk_translation' );

		return $result;
	}

	/**
	 * Registry rows for post_* elements whose post no longer exists in wp_posts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_orphan_post_registry_rows() {
		global $wpdb;

		$table = self::get_elements_table_name();
		$posts = $wpdb->posts;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are controlled.
		$rows = $wpdb->get_results(
			"SELECT e.* FROM {$table} AS e
			LEFT JOIN {$posts} AS p ON p.ID = e.element_id
			WHERE e.element_type LIKE 'post_%'
			AND p.ID IS NULL
			ORDER BY e.id ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete registry rows by primary key id, only when still orphaned post_* rows.
	 *
	 * @param int[] $db_ids Registry table primary keys.
	 * @return array{deleted:int,skipped:int}
	 */
	public static function delete_orphan_registry_rows_by_ids( array $db_ids ) {
		global $wpdb;

		$db_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $db_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		$deleted = 0;
		$skipped = 0;
		$table   = self::get_elements_table_name();

		foreach ( $db_ids as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is controlled.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
					$id
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				++$skipped;
				continue;
			}

			$element_type = (string) ( $row['element_type'] ?? '' );
			if ( 0 !== strpos( $element_type, 'post_' ) ) {
				++$skipped;
				continue;
			}

			$element_id = (int) ( $row['element_id'] ?? 0 );
			if ( get_post( $element_id ) instanceof WP_Post ) {
				++$skipped;
				continue;
			}

			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			if ( $wpdb->rows_affected > 0 ) {
				wp_cache_delete( "registry_{$element_type}_{$element_id}", 'leadwerk_translation' );
				++$deleted;
			} else {
				++$skipped;
			}
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);
	}

	/**
	 * Find the home record for a language.
	 *
	 * @param string $language_code Language code.
	 * @return array<string,mixed>
	 */
	public static function get_home_record( $language_code ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_elements_table_name() . " WHERE language_code = %s AND is_home = 1 AND element_type LIKE 'post_%%' LIMIT 1",
				sanitize_key( (string) $language_code )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Find one registry record by public slug.
	 *
	 * @param string $language_code Language code.
	 * @param string $public_slug   Public slug.
	 * @param string $type_like     Element type SQL LIKE pattern.
	 * @return array<string,mixed>
	 */
	public static function get_record_by_public_slug( $language_code, $public_slug, $type_like = 'post_%' ) {
		global $wpdb;

		// Bei gleichem public_slug (z. B. Seite "thats-acm" + falsch registriertes Bild) muss die Seite vor Attachment/Revision gewinnen.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_elements_table_name() . " WHERE language_code = %s AND public_slug = %s AND element_type LIKE %s ORDER BY CASE element_type WHEN %s THEN 0 WHEN %s THEN 1 WHEN %s THEN 100 WHEN %s THEN 100 WHEN %s THEN 100 ELSE 10 END, id ASC LIMIT 1",
				sanitize_key( (string) $language_code ),
				trim( (string) $public_slug, '/' ),
				(string) $type_like,
				'post_page',
				'post_post',
				'post_attachment',
				'post_revision',
				'post_nav_menu_item'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Insert or update one registry record.
	 *
	 * @param string               $element_type Element type.
	 * @param int                  $element_id   Element ID.
	 * @param array<string,mixed>  $args         Record data.
	 * @return array<string,mixed>
	 */
	public static function upsert_element( $element_type, $element_id, $args ) {
		global $wpdb;

		$existing = self::get_registry_record( $element_type, $element_id );
		$now      = current_time( 'mysql', true );
		$data     = array(
			'element_type'         => (string) $element_type,
			'element_id'           => (int) $element_id,
			'trid'                 => sanitize_text_field( (string) ( $args['trid'] ?? ( $existing['trid'] ?? self::generate_trid() ) ) ),
			'language_code'        => sanitize_key( (string) ( $args['language_code'] ?? ( $existing['language_code'] ?? self::get_default_language() ) ) ),
			'source_language_code' => sanitize_key( (string) ( $args['source_language_code'] ?? ( $existing['source_language_code'] ?? '' ) ) ),
			'source_element_id'    => (int) ( $args['source_element_id'] ?? ( $existing['source_element_id'] ?? 0 ) ),
			'status'               => sanitize_key( (string) ( $args['status'] ?? ( $existing['status'] ?? 'complete' ) ) ),
			'source_key'           => sanitize_key( (string) ( $args['source_key'] ?? ( $existing['source_key'] ?? '' ) ) ),
			'public_slug'          => trim( (string) ( $args['public_slug'] ?? ( $existing['public_slug'] ?? '' ) ), '/' ),
			'internal_slug'        => sanitize_title( (string) ( $args['internal_slug'] ?? ( $existing['internal_slug'] ?? '' ) ) ),
			'is_home'              => ! empty( $args['is_home'] ) || ! empty( $existing['is_home'] ) ? 1 : 0,
			'updated_at'           => $now,
		);

		if ( empty( $existing ) ) {
			$data['created_at'] = $now;
			$wpdb->insert( self::get_elements_table_name(), $data );
		} else {
			$wpdb->update( self::get_elements_table_name(), $data, array( 'id' => (int) $existing['id'] ) );
		}

		if ( 0 === strpos( (string) $element_type, 'post_' ) ) {
			self::mirror_post_meta(
				(int) $element_id,
				array_merge(
					$existing,
					$data,
					array(
						'element_type' => $element_type,
						'element_id'   => (int) $element_id,
					)
				)
			);
		}

		wp_cache_delete( "registry_{$element_type}_{$element_id}", 'leadwerk_translation' );

		return self::get_registry_record( $element_type, $element_id );
	}

	/**
	 * Ensure one post has a registry record.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $args    Override values.
	 * @return array<string,mixed>
	 */
	public static function ensure_post_record( $post_id, $args = array() ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$element_type = self::get_post_element_type( $post );
		$existing     = self::get_registry_record( $element_type, $post->ID );
		$source_key   = sanitize_key( (string) ( $args['source_key'] ?? ( $existing['source_key'] ?? get_post_meta( $post->ID, 'leadwerk_source_key', true ) ) ) );
		$lang         = sanitize_key( (string) ( $args['language_code'] ?? ( $existing['language_code'] ?? get_post_meta( $post->ID, self::META_LANG, true ) ) ) );
		$lang         = $lang ?: self::get_default_language();
		$is_home      = array_key_exists( 'is_home', $args )
			? ! empty( $args['is_home'] )
			: (
				! empty( $existing['is_home'] )
				|| ! empty( get_post_meta( $post->ID, self::META_LANG_ROOT, true ) )
				|| 'acm-home-v1' === $source_key
				|| 'acm-index-v1' === $source_key
				|| ( self::get_default_language() === $lang && (int) get_option( 'page_on_front' ) === (int) $post->ID )
			);
		$public_slug  = trim( (string) ( $args['public_slug'] ?? ( $existing['public_slug'] ?? get_post_meta( $post->ID, self::META_PUBLIC_SLUG, true ) ) ), '/' );

		if ( '' === $public_slug && ! $is_home ) {
			$public_slug = self::derive_public_slug_from_post( $post, $lang );
		}

		if ( 'acm_news' === $post->post_type && ! $is_home && '' !== $public_slug ) {
			$public_slug = self::normalize_acm_news_registry_public_slug( $public_slug );
		}

		if ( 'attachment' === $post->post_type && ! $is_home && '' !== $public_slug ) {
			$public_slug = self::generate_unique_public_slug( $public_slug, $lang, $post->ID );
		}

		$internal_slug = sanitize_title(
			(string) (
				$args['internal_slug']
				?? ( $post->post_name ?: self::build_internal_slug( $public_slug, $lang, $is_home, $post ) )
			)
		);

		return self::upsert_element(
			$element_type,
			$post->ID,
			array(
				'trid'                 => $args['trid'] ?? ( $existing['trid'] ?? get_post_meta( $post->ID, self::META_TRID, true ) ),
				'language_code'        => $lang,
				'source_language_code' => $args['source_language_code'] ?? ( $existing['source_language_code'] ?? ( self::get_default_language() === $lang ? '' : self::get_default_language() ) ),
				'source_element_id'    => $args['source_element_id'] ?? ( $existing['source_element_id'] ?? get_post_meta( $post->ID, self::META_SOURCE_ID, true ) ),
				'status'               => $args['status'] ?? ( $existing['status'] ?? get_post_meta( $post->ID, self::META_STATUS, true ) ?: ( self::get_default_language() === $lang ? 'complete' : 'not_translated' ) ),
				'source_key'           => $source_key,
				'public_slug'          => $is_home ? '' : $public_slug,
				'internal_slug'        => $internal_slug,
				'is_home'              => $is_home,
			)
		);
	}

	/**
	 * Link a source and translated post together.
	 *
	 * @param int                 $source_post_id     Source post ID.
	 * @param int                 $translated_post_id Target post ID.
	 * @param string              $target_lang        Target language.
	 * @param bool                $translated_is_root Whether translation represents a language home.
	 * @param array<string,mixed> $args               Extra record fields.
	 * @return string
	 */
	public static function link_posts( $source_post_id, $translated_post_id, $target_lang, $translated_is_root = false, $args = array() ) {
		$source_post     = get_post( (int) $source_post_id );
		$translated_post = get_post( (int) $translated_post_id );

		if ( ! $source_post instanceof WP_Post || ! $translated_post instanceof WP_Post ) {
			return '';
		}

		$source_record = self::ensure_post_record(
			$source_post->ID,
			array(
				'language_code' => self::get_default_language(),
				'source_key'    => $args['source_key'] ?? get_post_meta( $source_post->ID, 'leadwerk_source_key', true ),
				'public_slug'   => array_key_exists( 'source_public_slug', $args ) ? (string) $args['source_public_slug'] : self::derive_public_slug_from_post( $source_post, self::get_default_language() ),
				'is_home'       => ! empty( $args['source_is_home'] ) || in_array( get_post_meta( $source_post->ID, 'leadwerk_source_key', true ), array( 'acm-home-v1', 'acm-index-v1' ), true ) || (int) get_option( 'page_on_front' ) === (int) $source_post->ID,
				'status'        => 'complete',
			)
		);

		self::ensure_post_record(
			$translated_post->ID,
			array(
				'trid'                 => $source_record['trid'] ?? self::generate_trid(),
				'language_code'        => sanitize_key( (string) $target_lang ),
				'source_language_code' => self::get_default_language(),
				'source_element_id'    => $source_post->ID,
				'source_key'           => $args['source_key'] ?? get_post_meta( $translated_post->ID, 'leadwerk_source_key', true ),
				'public_slug'          => $translated_is_root || ! empty( $args['is_home'] ) ? '' : (string) ( $args['public_slug'] ?? self::derive_public_slug_from_post( $translated_post, $target_lang ) ),
				'internal_slug'        => $args['internal_slug'] ?? $translated_post->post_name,
				'status'               => $args['status'] ?? 'complete',
				'is_home'              => $translated_is_root || ! empty( $args['is_home'] ),
			)
		);

		return (string) ( $source_record['trid'] ?? '' );
	}

	/**
	 * Get translation records keyed by language.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,int>
	 */
	public static function get_translations( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$source_id   = self::get_source_post_id( $post->ID );
		$source_id   = $source_id ? $source_id : (int) $post->ID;
		$source_post = get_post( $source_id );
		if ( ! $source_post instanceof WP_Post ) {
			return array();
		}

		$record = self::ensure_post_record( $source_post->ID );
		$out    = self::get_registered_translations_for_post( $source_post, $record );

		if ( self::get_default_language() === sanitize_key( (string) ( $record['language_code'] ?? self::get_default_language() ) ) ) {
			$out[ self::get_default_language() ] = (int) $source_post->ID;
		}

		foreach ( self::get_active_languages() as $lang => $config ) {
			if ( isset( $out[ $lang ] ) ) {
				continue;
			}

			$repair = self::repair_translation_links_for_source( $source_post->ID, $lang );
			if ( ! empty( $repair['target_id'] ) ) {
				$out[ $lang ] = (int) $repair['target_id'];
			}
		}

		return $out;
	}

	/**
	 * Session debug NDJSON (Cursor debug mode). Do not log secrets/PII.
	 *
	 * @param string               $location Location label.
	 * @param string               $message  Short message.
	 * @param array<string,mixed> $data     Payload (include hypothesisId key).
	 * @return void
	 */
	protected static function agent_debug_log_session( $location, $message, array $data ) {
		// #region agent log
		$payload = array_merge(
			array(
				'sessionId'   => '5279b9',
				'location'    => (string) $location,
				'message'     => (string) $message,
				'timestamp'   => (int) round( microtime( true ) * 1000 ),
			),
			$data
		);
		$line = wp_json_encode( $payload ) . "\n";
		if ( defined( 'LEADWERK_WPML_CLONE_PATH' ) && LEADWERK_WPML_CLONE_PATH ) {
			@file_put_contents( LEADWERK_WPML_CLONE_PATH . 'debug-5279b9.log', $line, FILE_APPEND | LOCK_EX );
		}
		if ( function_exists( 'wp_remote_post' ) ) {
			wp_remote_post(
				'http://127.0.0.1:7345/ingest/b1195c55-d6eb-488c-93f3-5ea6ddc2460c',
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'headers'   => array(
						'Content-Type'       => 'application/json',
						'X-Debug-Session-Id' => '5279b9',
					),
					'body'      => wp_json_encode( $payload ),
				)
			);
		}
		// #endregion
	}

	/**
	 * Get one translation by language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	public static function get_translation( $post_id, $lang ) {
		$lang = sanitize_key( (string) $lang );
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$raw_source_id = self::get_source_post_id( $post->ID );
		$source_id     = $raw_source_id ? $raw_source_id : (int) $post->ID;
		$source_post   = get_post( $source_id );
		if ( ! $source_post instanceof WP_Post ) {
			return 0;
		}

		if ( self::get_default_language() === $lang && self::get_default_language() === self::get_post_language( $source_post->ID ) ) {
			self::agent_debug_log_session(
				'Leadwerk_Translation_API::get_translation',
				'early_default_source',
				array(
					'hypothesisId'    => 'H4',
					'request_post_id' => (int) $post_id,
					'return_id'       => (int) $source_post->ID,
					'raw_source_id'   => (int) $raw_source_id,
				)
			);
			return (int) $source_post->ID;
		}

		$record       = self::ensure_post_record( $source_post->ID );
		$translations = self::get_registered_translations_for_post( $source_post, $record );
		if ( isset( $translations[ $lang ] ) ) {
			$tid = (int) $translations[ $lang ];
			self::agent_debug_log_session(
				'Leadwerk_Translation_API::get_translation',
				'from_registry',
				array(
					'hypothesisId'         => 'H2',
					'request_post_id'      => (int) $post_id,
					'effective_source_id'  => (int) $source_id,
					'return_id'            => $tid,
					'source_trid'          => (string) ( $record['trid'] ?? '' ),
					'translations_map_ids' => $translations,
				)
			);
			return $tid;
		}

		$repair = self::repair_translation_links_for_source( $source_post->ID, $lang );
		$out    = ! empty( $repair['target_id'] ) ? (int) $repair['target_id'] : 0;
		self::agent_debug_log_session(
			'Leadwerk_Translation_API::get_translation',
			'after_repair',
			array(
				'hypothesisId'             => 'H1,H3,H5',
				'request_post_id'          => (int) $post_id,
				'lang'                     => $lang,
				'raw_source_id'            => (int) $raw_source_id,
				'effective_source_id'      => (int) $source_id,
				'request_post_lang'        => self::get_post_language( (int) $post_id ),
				'source_post_lang'         => self::get_post_language( $source_post->ID ),
				'source_trid'              => (string) ( $record['trid'] ?? '' ),
				'source_key'               => sanitize_key( (string) ( $record['source_key'] ?? '' ) ),
				'translations_before_repair' => $translations,
				'repair_target_id'         => (int) ( $repair['target_id'] ?? 0 ),
				'repair_mode'              => (string) ( $repair['resolution_mode'] ?? '' ),
				'repair_candidate_source'  => (string) ( $repair['candidate_source'] ?? '' ),
			)
		);
		return $out;
	}

	/**
	 * Get the source post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_source_post_id( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$record = self::ensure_post_record( $post->ID );
		if ( ! empty( $record['source_element_id'] ) ) {
			return (int) $record['source_element_id'];
		}

		return self::get_default_language() === ( $record['language_code'] ?? self::get_default_language() ) ? (int) $post->ID : 0;
	}

	/**
	 * Get post language.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_post_language( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::get_default_language();
		}

		$record = self::ensure_post_record( $post->ID );
		return sanitize_key( (string) ( $record['language_code'] ?? self::get_default_language() ) );
	}

	/**
	 * Get translation group id.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_trid( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$record = self::ensure_post_record( $post->ID );
		return (string) ( $record['trid'] ?? '' );
	}

	/**
	 * Get translated post ID by source key and language.
	 *
	 * @param string $source_key Source key.
	 * @param string $lang       Language code.
	 * @return int
	 */
	public static function get_post_by_source_key( $source_key, $lang = 'de', $preferred_post_type = 'page' ) {
		$candidates = self::find_posts_by_source_key_and_lang( $source_key, $lang, $preferred_post_type );
		return ! empty( $candidates[0]['post_id'] ) ? (int) $candidates[0]['post_id'] : 0;
	}

	/**
	 * Find and rank posts by source key + language.
	 *
	 * @param string $source_key           Source key.
	 * @param string $lang                 Language code.
	 * @param string $preferred_post_type  Preferred post type.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function find_posts_by_source_key_and_lang( $source_key, $lang, $preferred_post_type = '' ) {
		$source_key          = sanitize_key( (string) $source_key );
		$lang                = sanitize_key( (string) $lang );
		$preferred_post_type = sanitize_key( (string) $preferred_post_type );

		if ( '' === $source_key || '' === $lang ) {
			return array();
		}

		global $wpdb;

		$candidates = array();
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id FROM " . self::get_elements_table_name() . " WHERE source_key = %s AND language_code = %s AND element_type LIKE 'post_%%'",
				$source_key,
				$lang
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			self::add_post_by_source_key_candidate( $candidates, (int) ( $row['element_id'] ?? 0 ), $source_key, $lang, $preferred_post_type, 'source_key_registry' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => 'leadwerk_source_key',
						'value' => $source_key,
					),
					array(
						'key'   => self::META_LANG,
						'value' => $lang,
					),
				),
			)
		);

		foreach ( (array) $query->get_posts() as $post_id ) {
			self::add_post_by_source_key_candidate( $candidates, (int) $post_id, $source_key, $lang, $preferred_post_type, 'source_key_meta' );
		}

		$candidates = array_values( $candidates );
		usort( $candidates, array( __CLASS__, 'compare_post_by_source_key_candidates' ) );

		return $candidates;
	}

	/**
	 * Add a ranked source-key lookup candidate.
	 *
	 * @param array<int,array<string,mixed>> $candidates          Candidates.
	 * @param int                            $post_id             Post ID.
	 * @param string                         $source_key          Source key.
	 * @param string                         $lang                Language code.
	 * @param string                         $preferred_post_type Preferred post type.
	 * @param string                         $candidate_source    Candidate source.
	 * @return void
	 */
	protected static function add_post_by_source_key_candidate( &$candidates, $post_id, $source_key, $lang, $preferred_post_type, $candidate_source ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Medien/Revisionen duerfen nie als Navigationsziel fuer source_key gewinnen (sonst /uploads/... statt Seite).
		if ( in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
			return;
		}

		$record          = self::get_registry_record( self::get_post_element_type( $post ), $post->ID );
		$record_source_key = sanitize_key( (string) ( $record['source_key'] ?? '' ) );
		$meta_source_key   = sanitize_key( (string) get_post_meta( $post->ID, 'leadwerk_source_key', true ) );
		$record_lang       = sanitize_key( (string) ( $record['language_code'] ?? '' ) );
		$meta_lang         = sanitize_key( (string) get_post_meta( $post->ID, self::META_LANG, true ) );

		if ( $source_key !== ( $record_source_key ?: $meta_source_key ) ) {
			return;
		}

		if ( $lang !== ( $record_lang ?: $meta_lang ) ) {
			return;
		}

		if ( ! isset( $candidates[ $post->ID ] ) ) {
			$candidates[ $post->ID ] = array(
				'post_id'         => (int) $post->ID,
				'post_type_match' => '' !== $preferred_post_type && $preferred_post_type === $post->post_type,
				'non_trash'       => 'trash' !== $post->post_status,
				'has_bundle'      => self::translation_bundle_has_paths( $post->ID ),
				'modified_ts'     => self::get_post_modified_timestamp( $post ),
				'candidate_source'=> (string) $candidate_source,
			);
			return;
		}

		if ( 'source_key_registry' === $candidate_source ) {
			$candidates[ $post->ID ]['candidate_source'] = 'source_key_registry';
		}
	}

	/**
	 * Sort source-key candidates deterministically.
	 *
	 * @param array<string,mixed> $left  Left candidate.
	 * @param array<string,mixed> $right Right candidate.
	 * @return int
	 */
	protected static function compare_post_by_source_key_candidates( $left, $right ) {
		foreach ( array( 'post_type_match', 'non_trash', 'has_bundle' ) as $key ) {
			$left_value  = ! empty( $left[ $key ] ) ? 1 : 0;
			$right_value = ! empty( $right[ $key ] ) ? 1 : 0;
			if ( $left_value !== $right_value ) {
				return $left_value > $right_value ? -1 : 1;
			}
		}

		$left_modified  = (int) ( $left['modified_ts'] ?? 0 );
		$right_modified = (int) ( $right['modified_ts'] ?? 0 );
		if ( $left_modified !== $right_modified ) {
			return $left_modified > $right_modified ? -1 : 1;
		}

		return (int) ( $right['post_id'] ?? 0 ) <=> (int) ( $left['post_id'] ?? 0 );
	}

	/**
	 * Repair a source -> translation relation if the registry link is missing or stale.
	 *
	 * @param int    $source_post_id      Source post ID.
	 * @param string $lang                Target language.
	 * @param int    $preferred_target_id Preferred translated post ID.
	 * @return array<string,mixed>
	 */
	public static function repair_translation_links_for_source( $source_post_id, $lang = 'en', $preferred_target_id = 0 ) {
		$info = array(
			'target_id'            => 0,
			'repaired'             => false,
			'duplicates_detected'  => false,
			'duplicate_ids'        => array(),
			'resolution_mode'      => 'missing',
			'candidate_source'     => '',
			'preferred_target_id'  => (int) $preferred_target_id,
		);

		$source_post = get_post( (int) $source_post_id );
		if ( ! $source_post instanceof WP_Post ) {
			return $info;
		}

		$lang = sanitize_key( (string) $lang );
		if ( '' === $lang ) {
			$lang = self::get_default_language();
		}

		if ( self::get_default_language() === $lang ) {
			$info['target_id']       = (int) $source_post->ID;
			$info['resolution_mode'] = 'registry';
			return $info;
		}

		$source_record      = self::ensure_post_record( $source_post->ID );
		$registered_targets = self::get_registered_translations_for_post( $source_post, $source_record );
		$registered_target  = isset( $registered_targets[ $lang ] ) ? (int) $registered_targets[ $lang ] : 0;
		$source_key         = sanitize_key( (string) ( $source_record['source_key'] ?? get_post_meta( $source_post->ID, 'leadwerk_source_key', true ) ) );
		$candidates         = self::find_translation_candidates_for_source( $source_post, $lang, $source_key, (int) $preferred_target_id, $registered_target );

		if ( empty( $candidates ) ) {
			if ( $registered_target && self::is_post_translation_candidate( $registered_target, $source_post, $lang, $source_key ) ) {
				$info['target_id']       = $registered_target;
				$info['resolution_mode'] = 'registry';
			}
			// #region agent log
			self::agent_debug_log_session(
				'Leadwerk_Translation_API::repair_translation_links_for_source',
				'empty_candidates_branch',
				array(
					'hypothesisId'      => 'H3',
					'source_post_id'    => (int) $source_post->ID,
					'lang'              => $lang,
					'source_key'        => $source_key,
					'source_trid'       => (string) ( $source_record['trid'] ?? '' ),
					'registered_target' => (int) $registered_target,
					'candidate_ok'      => (bool) ( $registered_target && self::is_post_translation_candidate( $registered_target, $source_post, $lang, $source_key ) ),
					'resolved_target'   => (int) ( $info['target_id'] ?? 0 ),
				)
			);
			// #endregion
			return $info;
		}

		$best                      = $candidates[0];
		$info['target_id']         = (int) $best['post_id'];
		$info['candidate_source']  = (string) ( $best['candidate_source'] ?? '' );
		$info['duplicates_detected'] = count( $candidates ) > 1;
		$info['duplicate_ids']     = array_values(
			array_map(
				'intval',
				array_column( array_slice( $candidates, 1 ), 'post_id' )
			)
		);

		$target_record = self::ensure_post_record( (int) $best['post_id'] );
		$link_matches  = self::translation_link_matches_source( $target_record, $source_post, $lang, (string) ( $source_record['trid'] ?? '' ) );

		if ( $registered_target && (int) $registered_target === (int) $best['post_id'] && $link_matches ) {
			$info['resolution_mode'] = 'registry';
			return $info;
		}

		$target_post = get_post( (int) $best['post_id'] );
		if ( ! $target_post instanceof WP_Post ) {
			$info['target_id']       = 0;
			$info['resolution_mode'] = 'missing';
			return $info;
		}

		self::link_posts(
			$source_post->ID,
			$target_post->ID,
			$lang,
			! empty( $source_record['is_home'] ),
			array(
				'source_key'         => $source_key,
				'source_public_slug' => (string) ( $source_record['public_slug'] ?? '' ),
				'source_is_home'     => ! empty( $source_record['is_home'] ),
				'public_slug'        => ! empty( $source_record['is_home'] ) ? '' : (string) ( $target_record['public_slug'] ?? self::derive_public_slug_from_post( $target_post, $lang ) ),
				'internal_slug'      => $target_post->post_name,
				'status'             => (string) ( $target_record['status'] ?? get_post_meta( $target_post->ID, self::META_STATUS, true ) ?: 'not_translated' ),
				'is_home'            => ! empty( $source_record['is_home'] ),
			)
		);

		$info['repaired']        = true;
		$info['resolution_mode'] = 'repaired';

		return $info;
	}

	/**
	 * Repair all missing/stale translation links for translatable source posts.
	 *
	 * @param string $lang Target language.
	 * @return array<string,int>
	 */
	public static function repair_all_translation_links( $lang = 'en' ) {
		$lang    = sanitize_key( (string) $lang );
		$summary = array(
			'scanned'             => 0,
			'repaired'            => 0,
			'resolved'            => 0,
			'duplicates_detected' => 0,
			'missing'             => 0,
		);

		$query = new WP_Query(
			array(
				'post_type'      => self::get_settings()['translatable_post_types'],
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( (array) $query->posts as $source_id ) {
			if ( self::get_default_language() !== self::get_post_language( (int) $source_id ) ) {
				continue;
			}

			++$summary['scanned'];
			$result = self::repair_translation_links_for_source( (int) $source_id, $lang );
			if ( ! empty( $result['target_id'] ) ) {
				++$summary['resolved'];
			} else {
				++$summary['missing'];
			}

			if ( ! empty( $result['repaired'] ) ) {
				++$summary['repaired'];
			}

			if ( ! empty( $result['duplicates_detected'] ) ) {
				++$summary['duplicates_detected'];
			}
		}

		return $summary;
	}

	/**
	 * Get currently registered translations without attempting repair.
	 *
	 * @param WP_Post              $post   Source post.
	 * @param array<string,mixed>  $record Source record.
	 * @return array<string,int>
	 */
	protected static function get_registered_translations_for_post( $post, $record = array() ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$record = ! empty( $record ) && is_array( $record ) ? $record : self::ensure_post_record( $post->ID );
		if ( empty( $record['trid'] ) ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id, language_code FROM " . self::get_elements_table_name() . " WHERE trid = %s AND element_type = %s",
				(string) $record['trid'],
				self::get_post_element_type( $post )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ sanitize_key( (string) ( $row['language_code'] ?? '' ) ) ] = (int) ( $row['element_id'] ?? 0 );
		}

		return $out;
	}

	/**
	 * Find and rank EN candidates for one source post.
	 *
	 * @param WP_Post $source_post          Source post.
	 * @param string  $lang                 Target language.
	 * @param string  $source_key           Source key.
	 * @param int     $preferred_target_id  Preferred target ID.
	 * @param int     $registered_target_id Registered target ID.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function find_translation_candidates_for_source( $source_post, $lang, $source_key, $preferred_target_id = 0, $registered_target_id = 0 ) {
		if ( ! $source_post instanceof WP_Post || '' === $lang ) {
			return array();
		}

		$acm_news_empty_key = ( 'acm_news' === $source_post->post_type && '' === $source_key );

		$candidates = array();

		if ( $registered_target_id ) {
			self::add_translation_candidate(
				$candidates,
				(int) $registered_target_id,
				$source_post,
				$lang,
				$source_key,
				'registry',
				(int) $preferred_target_id
			);
		}

		if ( '' !== $source_key ) {
			global $wpdb;
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT element_id FROM " . self::get_elements_table_name() . " WHERE source_key = %s AND language_code = %s AND element_type LIKE 'post_%%'",
					$source_key,
					$lang
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				self::add_translation_candidate(
					$candidates,
					(int) ( $row['element_id'] ?? 0 ),
					$source_post,
					$lang,
					$source_key,
					'source_key_registry',
					(int) $preferred_target_id
				);
			}

			$query = new WP_Query(
				array(
					'post_type'      => $source_post->post_type,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => 'leadwerk_source_key',
							'value' => $source_key,
						),
						array(
							'key'   => self::META_LANG,
							'value' => $lang,
						),
					),
				)
			);

			foreach ( (array) $query->get_posts() as $post_id ) {
				self::add_translation_candidate(
					$candidates,
					(int) $post_id,
					$source_post,
					$lang,
					$source_key,
					'source_key_meta',
					(int) $preferred_target_id
				);
			}
		}

		if ( '' === $source_key && self::is_translatable_post_type( $source_post->post_type ) ) {
			$meta_q = new WP_Query(
				array(
					'post_type'      => $source_post->post_type,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => self::META_SOURCE_ID,
							'value' => (string) (int) $source_post->ID,
						),
						array(
							'key'   => self::META_LANG,
							'value' => $lang,
						),
					),
				)
			);

			foreach ( (array) $meta_q->get_posts() as $post_id ) {
				self::add_translation_candidate(
					$candidates,
					(int) $post_id,
					$source_post,
					$lang,
					$source_key,
					'meta_source_link',
					(int) $preferred_target_id
				);
			}
		}

		if ( $acm_news_empty_key ) {
			$trid = trim( (string) get_post_meta( $source_post->ID, self::META_TRID, true ) );
			if ( '' !== $trid ) {
				$trid_q = new WP_Query(
					array(
						'post_type'      => 'acm_news',
						'post_status'    => 'any',
						'post__not_in'   => array( (int) $source_post->ID ),
						'fields'         => 'ids',
						'posts_per_page' => 20,
						'no_found_rows'  => true,
						'meta_query'     => array(
							'relation' => 'AND',
							array(
								'key'   => self::META_TRID,
								'value' => $trid,
							),
							array(
								'key'   => self::META_LANG,
								'value' => $lang,
							),
						),
					)
				);

				foreach ( (array) $trid_q->get_posts() as $post_id ) {
					self::add_translation_candidate(
						$candidates,
						(int) $post_id,
						$source_post,
						$lang,
						$source_key,
						'acm_news_trid_meta',
						(int) $preferred_target_id
					);
				}
			}
		}

		$candidates = array_values( $candidates );
		usort( $candidates, array( __CLASS__, 'compare_translation_candidates' ) );

		return $candidates;
	}

	/**
	 * Add one translation repair candidate.
	 *
	 * @param array<int,array<string,mixed>> $candidates         Candidates.
	 * @param int                            $post_id            Candidate post ID.
	 * @param WP_Post                        $source_post        Source post.
	 * @param string                         $lang               Target language.
	 * @param string                         $source_key         Source key.
	 * @param string                         $candidate_source   Discovery mode.
	 * @param int                            $preferred_target_id Preferred target ID.
	 * @return void
	 */
	protected static function add_translation_candidate( &$candidates, $post_id, $source_post, $lang, $source_key, $candidate_source, $preferred_target_id = 0 ) {
		if ( ! self::is_post_translation_candidate( $post_id, $source_post, $lang, $source_key ) ) {
			return;
		}

		$post         = get_post( (int) $post_id );
		$record       = self::get_registry_record( self::get_post_element_type( $post ), $post->ID );
		$linked_source = (int) ( $record['source_element_id'] ?? get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
		$lang_match   = $lang === sanitize_key( (string) ( $record['language_code'] ?? get_post_meta( $post->ID, self::META_LANG, true ) ) );
		$is_preferred = (int) $preferred_target_id > 0 && (int) $preferred_target_id === (int) $post->ID;

		if ( ! isset( $candidates[ $post->ID ] ) ) {
			$candidates[ $post->ID ] = array(
				'post_id'          => (int) $post->ID,
				'candidate_source' => (string) $candidate_source,
				'preferred'        => $is_preferred,
				'post_type_match'  => $post->post_type === $source_post->post_type,
				'non_trash'        => 'trash' !== $post->post_status,
				'has_bundle'       => self::translation_bundle_has_paths( $post->ID ),
				'linked_source'    => (int) $linked_source === (int) $source_post->ID,
				'lang_match'       => $lang_match,
				'modified_ts'      => self::get_post_modified_timestamp( $post ),
			);
			return;
		}

		$candidates[ $post->ID ]['preferred'] = ! empty( $candidates[ $post->ID ]['preferred'] ) || $is_preferred;
		if ( 'registry' === $candidate_source ) {
			$candidates[ $post->ID ]['candidate_source'] = 'registry';
		} elseif ( 'source_key_registry' === $candidate_source && 'registry' !== $candidates[ $post->ID ]['candidate_source'] ) {
			$candidates[ $post->ID ]['candidate_source'] = 'source_key_registry';
		}
	}

	/**
	 * Compare translation candidates using the fixed selection policy.
	 *
	 * @param array<string,mixed> $left  Left candidate.
	 * @param array<string,mixed> $right Right candidate.
	 * @return int
	 */
	protected static function compare_translation_candidates( $left, $right ) {
		foreach ( array( 'preferred', 'post_type_match', 'non_trash', 'has_bundle', 'linked_source', 'lang_match' ) as $key ) {
			$left_value  = ! empty( $left[ $key ] ) ? 1 : 0;
			$right_value = ! empty( $right[ $key ] ) ? 1 : 0;
			if ( $left_value !== $right_value ) {
				return $left_value > $right_value ? -1 : 1;
			}
		}

		$left_modified  = (int) ( $left['modified_ts'] ?? 0 );
		$right_modified = (int) ( $right['modified_ts'] ?? 0 );
		if ( $left_modified !== $right_modified ) {
			return $left_modified > $right_modified ? -1 : 1;
		}

		return (int) ( $right['post_id'] ?? 0 ) <=> (int) ( $left['post_id'] ?? 0 );
	}

	/**
	 * Whether a post qualifies as a candidate for one translation relation.
	 *
	 * @param int     $post_id     Candidate post ID.
	 * @param WP_Post $source_post Source post.
	 * @param string  $lang        Target language.
	 * @param string  $source_key  Source key.
	 * @return bool
	 */
	protected static function is_post_translation_candidate( $post_id, $source_post, $lang, $source_key ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || ! $source_post instanceof WP_Post ) {
			return false;
		}

		if ( (int) $post->ID === (int) $source_post->ID ) {
			return false;
		}

		$record          = self::get_registry_record( self::get_post_element_type( $post ), $post->ID );
		$record_source_key = sanitize_key( (string) ( $record['source_key'] ?? '' ) );
		$meta_source_key   = sanitize_key( (string) get_post_meta( $post->ID, 'leadwerk_source_key', true ) );
		$record_lang       = sanitize_key( (string) ( $record['language_code'] ?? '' ) );
		$meta_lang         = sanitize_key( (string) get_post_meta( $post->ID, self::META_LANG, true ) );
		$lang_ok           = $lang === ( $record_lang ?: $meta_lang );

		if ( ! $lang_ok ) {
			return false;
		}

		if ( '' === $source_key ) {
			$linked = (int) get_post_meta( $post->ID, self::META_SOURCE_ID, true );
			if ( $linked === (int) $source_post->ID ) {
				return true;
			}
			$reg_src = (int) ( $record['source_element_id'] ?? 0 );
			return $reg_src === (int) $source_post->ID;
		}

		return $source_key === ( $record_source_key ?: $meta_source_key );
	}

	/**
	 * Whether a stored bundle contains active translation paths.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected static function translation_bundle_has_paths( $post_id ) {
		$bundle = self::get_translation_bundle( (int) $post_id );
		return ! empty( $bundle['paths'] ) && is_array( $bundle['paths'] );
	}

	/**
	 * Determine whether a target record is already correctly linked to its source.
	 *
	 * @param array<string,mixed> $target_record Target record.
	 * @param WP_Post             $source_post   Source post.
	 * @param string              $lang          Target language.
	 * @param string              $source_trid   Source trid.
	 * @return bool
	 */
	protected static function translation_link_matches_source( $target_record, $source_post, $lang, $source_trid ) {
		return ! empty( $target_record )
			&& (int) ( $target_record['source_element_id'] ?? 0 ) === (int) $source_post->ID
			&& sanitize_key( (string) ( $target_record['language_code'] ?? '' ) ) === sanitize_key( (string) $lang )
			&& '' !== (string) $source_trid
			&& (string) ( $target_record['trid'] ?? '' ) === (string) $source_trid;
	}

	/**
	 * Convert post modified time into a sortable timestamp.
	 *
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	protected static function get_post_modified_timestamp( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$modified = $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt
			? $post->post_modified_gmt
			: $post->post_modified;

		$timestamp = $modified ? strtotime( (string) $modified ) : 0;
		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Get public URL by source key and language.
	 *
	 * @param string $source_key Source key.
	 * @param string $lang       Language code.
	 * @param string $fallback   Fallback URL.
	 * @return string
	 */
	public static function get_post_url_by_source_key( $source_key, $lang = 'de', $fallback = '' ) {
		$post_id = self::get_post_by_source_key( $source_key, $lang );
		return $post_id ? self::get_public_post_url( $post_id, $fallback ) : $fallback;
	}

	/**
	 * Get the effective public slug for one translated post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_post_public_slug( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$record = self::ensure_post_record( $post->ID );
		if ( ! empty( $record['is_home'] ) ) {
			return '';
		}

		$public_slug = trim( (string) ( $record['public_slug'] ?? '' ), '/' );
		if ( '' !== $public_slug ) {
			return $public_slug;
		}

		return self::derive_public_slug_from_post( $post, (string) ( $record['language_code'] ?? self::get_post_language( $post->ID ) ) );
	}

	/**
	 * Whether a translated post represents a language root.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_post_language_root( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$record = self::ensure_post_record( $post->ID );
		return ! empty( $record['is_home'] );
	}

	/**
	 * Update one translated post title/public slug pair.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $args    Identity payload.
	 * @return array<string,mixed>
	 */
	public static function update_post_identity( $post_id, $args = array() ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array(
				'updated'     => false,
				'post_id'     => 0,
				'post_title'  => '',
				'public_slug' => '',
				'post_name'   => '',
				'is_home'     => false,
				'error'       => 'missing_post',
			);
		}

		$record      = self::ensure_post_record( $post->ID );
		$lang        = sanitize_key( (string) ( $record['language_code'] ?? self::get_post_language( $post->ID ) ) );
		$is_home     = ! empty( $record['is_home'] );
		$post_title  = array_key_exists( 'post_title', $args ) ? sanitize_text_field( (string) $args['post_title'] ) : (string) $post->post_title;
		$public_slug = self::get_post_public_slug( $post->ID );
		$slug_dirty  = false;

		if ( array_key_exists( 'public_slug', $args ) && ! $is_home ) {
			$requested_slug = sanitize_title( trim( (string) $args['public_slug'], '/' ) );
			if ( '' !== $requested_slug ) {
				$public_slug = self::generate_unique_public_slug( $requested_slug, $lang, $post->ID );
				$slug_dirty  = true;
			}
		}

		$post_update = array(
			'ID' => $post->ID,
		);

		if ( $post_title !== (string) $post->post_title ) {
			$post_update['post_title'] = $post_title;
		}

		$internal_slug = (string) $post->post_name;
		if ( $slug_dirty ) {
			$internal_slug = self::build_internal_slug( $public_slug, $lang, $is_home, $post );
			if ( $internal_slug !== (string) $post->post_name ) {
				$post_update['post_name'] = $internal_slug;
			}
		}

		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
			$post = get_post( $post->ID );
			if ( $post instanceof WP_Post ) {
				$internal_slug = (string) $post->post_name;
				$post_title    = (string) $post->post_title;
			}
		}

		$record = self::ensure_post_record(
			$post_id,
			array(
				'public_slug'   => $is_home ? '' : $public_slug,
				'internal_slug' => $internal_slug,
			)
		);

		return array(
			'updated'     => true,
			'post_id'     => (int) $post_id,
			'post_title'  => (string) $post_title,
			'public_slug' => ! empty( $record['is_home'] ) ? '' : trim( (string) ( $record['public_slug'] ?? $public_slug ), '/' ),
			'post_name'   => (string) ( $record['internal_slug'] ?? $internal_slug ),
			'is_home'     => ! empty( $record['is_home'] ),
			'error'       => '',
		);
	}

	/**
	 * Get public URL for a translated post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $fallback  Fallback URL.
	 * @return string
	 */
	public static function get_public_post_url( $post_id, $fallback = '' ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $fallback;
		}

		$record = self::ensure_post_record( $post->ID );
		if ( empty( $record ) ) {
			return $fallback;
		}

		$path = self::build_public_path(
			(string) $record['language_code'],
			(string) $record['public_slug'],
			! empty( $record['is_home'] )
		);

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Get public URL for one translation of a post.
	 *
	 * @param int    $post_id  Source or translated post ID.
	 * @param string $lang     Target language.
	 * @param string $fallback Fallback URL.
	 * @return string
	 */
	public static function get_translation_url( $post_id, $lang, $fallback = '' ) {
		$translation_id = self::get_translation( $post_id, $lang );
		return $translation_id ? self::get_public_post_url( $translation_id, $fallback ) : $fallback;
	}

	/**
	 * Get public URL for one translated term.
	 *
	 * @param int    $term_id   Term ID.
	 * @param string $taxonomy  Taxonomy.
	 * @param string $fallback  Fallback URL.
	 * @return string
	 */
	public static function get_public_term_url( $term_id, $taxonomy, $fallback = '' ) {
		$record = self::get_registry_record( self::get_term_element_type( $taxonomy ), (int) $term_id );
		if ( empty( $record ) ) {
			return $fallback;
		}

		$path = self::build_public_path(
			(string) $record['language_code'],
			(string) $record['public_slug'],
			false
		);

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Resolve one public request path to a registry record.
	 *
	 * @param string $path Request path.
	 * @return array<string,mixed>
	 */
	public static function resolve_public_request( $path ) {
		$path        = trim( urldecode( (string) $path ), '/' );
		$language    = self::get_default_language();
		$public_slug = $path;

		foreach ( self::get_active_languages() as $code => $config ) {
			$prefix = trim( (string) $config['prefix'], '/' );
			if ( '' === $prefix ) {
				continue;
			}

			if ( $path === $prefix ) {
				$language    = $code;
				$public_slug = '';
				break;
			}

			if ( 0 === strpos( $path, $prefix . '/' ) ) {
				$language    = $code;
				$public_slug = trim( substr( $path, strlen( $prefix ) + 1 ), '/' );
				break;
			}
		}

		if ( '' === $public_slug ) {
			$home = self::get_home_record( $language );
			return ! empty( $home ) ? $home : array( 'language_code' => $language );
		}

		$record = self::get_record_by_public_slug( $language, $public_slug, 'post_%' );
		if ( ! empty( $record ) ) {
			return $record;
		}

		$record = self::get_record_by_public_slug( $language, $public_slug, 'term_%' );
		if ( ! empty( $record ) ) {
			return $record;
		}

		return array( 'language_code' => $language );
	}

	/**
	 * Read a localized option such as wpforms_form_id_en.
	 *
	 * @param string $base_field Base field name without language suffix.
	 * @param string $lang       Language code.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public static function get_localized_option( $base_field, $lang = 'de', $default = '' ) {
		$field = (string) $base_field;

		if ( ! preg_match( '/_(de|en)$/', $field ) ) {
			$field .= '_' . sanitize_key( (string) $lang );
		}

		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field, 'option' );
			if ( ! is_null( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$value = get_option( $field, null );
		return ! is_null( $value ) && '' !== $value ? $value : $default;
	}

	/**
	 * Find the newest exact translation memory match for one source value.
	 *
	 * @param string $source_lang  Source language code.
	 * @param string $target_lang  Target language code.
	 * @param string $content_type Content type.
	 * @param string $source_value Raw source value.
	 * @return array<string,mixed>
	 */
	public static function find_translation_memory_match( $source_lang, $target_lang, $content_type, $source_value ) {
		global $wpdb;

		$source_lang       = sanitize_key( (string) $source_lang );
		$target_lang       = sanitize_key( (string) $target_lang );
		$content_type      = sanitize_key( (string) $content_type ) ?: 'text';
		$source_normalized = self::normalize_translation_memory_source( $source_value, $content_type );
		if ( '' === $source_normalized || '' === $source_lang || '' === $target_lang || $source_lang === $target_lang ) {
			return array();
		}

		$source_hash = self::build_translation_memory_hash( $source_normalized, $content_type );
		$cache_key   = 'memory_match_' . md5( implode( '|', array( $source_lang, $target_lang, $content_type, $source_hash ) ) );
		$cached      = wp_cache_get( $cache_key, 'leadwerk_translation' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_memory_table_name() . " WHERE source_language_code = %s AND target_language_code = %s AND content_type = %s AND source_hash = %s ORDER BY updated_at DESC, id DESC",
				$source_lang,
				$target_lang,
				$content_type,
				$source_hash
			),
			ARRAY_A
		);

		$match = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( $source_normalized !== (string) ( $row['source_normalized'] ?? '' ) ) {
				continue;
			}

			$translation_value = (string) ( $row['translation_value'] ?? '' );
			if ( '' === self::normalize_translation_memory_source( $translation_value, $content_type ) ) {
				continue;
			}

			$match = $row;
			break;
		}

		wp_cache_set( $cache_key, $match, 'leadwerk_translation' );
		return $match;
	}

	/**
	 * Insert or update one translation memory candidate.
	 *
	 * @param string              $source_lang      Source language code.
	 * @param string              $target_lang      Target language code.
	 * @param string              $content_type     Content type.
	 * @param string              $source_value     Raw source value.
	 * @param string              $translation_value Raw translation value.
	 * @param array<string,mixed> $args             Origin metadata.
	 * @return int Memory row ID.
	 */
	public static function upsert_translation_memory( $source_lang, $target_lang, $content_type, $source_value, $translation_value, $args = array() ) {
		global $wpdb;

		$source_lang       = sanitize_key( (string) $source_lang );
		$target_lang       = sanitize_key( (string) $target_lang );
		$content_type      = sanitize_key( (string) $content_type ) ?: 'text';
		$origin_type       = sanitize_key( (string) ( $args['origin_type'] ?? '' ) );
		$origin_id         = (int) ( $args['origin_id'] ?? 0 );
		$origin_key        = sanitize_text_field( (string) ( $args['origin_key'] ?? '' ) );
		$source_value      = (string) $source_value;
		$translation_value = (string) $translation_value;
		$source_normalized = self::normalize_translation_memory_source( $source_value, $content_type );
		$translation_check = self::normalize_translation_memory_source( $translation_value, $content_type );

		if ( '' === $source_lang || '' === $target_lang || $source_lang === $target_lang || '' === $origin_type || $origin_id < 1 || '' === $origin_key || '' === $source_normalized || '' === $translation_check ) {
			return 0;
		}

		$source_hash = self::build_translation_memory_hash( $source_normalized, $content_type );
		$existing    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM " . self::get_memory_table_name() . " WHERE origin_type = %s AND origin_id = %d AND origin_key = %s LIMIT 1",
				$origin_type,
				$origin_id,
				$origin_key
			),
			ARRAY_A
		);
		$data        = array(
			'source_language_code' => $source_lang,
			'target_language_code' => $target_lang,
			'content_type'         => $content_type,
			'source_hash'          => $source_hash,
			'source_normalized'    => $source_normalized,
			'source_value'         => $source_value,
			'translation_value'    => $translation_value,
			'origin_type'          => $origin_type,
			'origin_id'            => $origin_id,
			'origin_key'           => $origin_key,
			'updated_at'           => current_time( 'mysql', true ),
		);

		if ( empty( $existing ) ) {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( self::get_memory_table_name(), $data );
			$row_id = (int) $wpdb->insert_id;
		} else {
			$row_id = (int) $existing['id'];
			$wpdb->update( self::get_memory_table_name(), $data, array( 'id' => $row_id ) );
		}

		self::clear_translation_memory_cache( $source_lang, $target_lang, $content_type, $source_hash );
		return $row_id;
	}

	/**
	 * Delete one translation memory candidate by origin identity.
	 *
	 * @param string $origin_type Origin type.
	 * @param int    $origin_id   Origin ID.
	 * @param string $origin_key  Origin key.
	 * @return void
	 */
	public static function delete_translation_memory_origin( $origin_type, $origin_id, $origin_key ) {
		global $wpdb;

		$origin_type = sanitize_key( (string) $origin_type );
		$origin_id   = (int) $origin_id;
		$origin_key  = sanitize_text_field( (string) $origin_key );
		if ( '' === $origin_type || $origin_id < 1 || '' === $origin_key ) {
			return;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_language_code, target_language_code, content_type, source_hash FROM " . self::get_memory_table_name() . " WHERE origin_type = %s AND origin_id = %d AND origin_key = %s LIMIT 1",
				$origin_type,
				$origin_id,
				$origin_key
			),
			ARRAY_A
		);

		$wpdb->delete(
			self::get_memory_table_name(),
			array(
				'origin_type' => $origin_type,
				'origin_id'   => $origin_id,
				'origin_key'  => $origin_key,
			)
		);

		if ( is_array( $existing ) ) {
			self::clear_translation_memory_cache(
				(string) ( $existing['source_language_code'] ?? '' ),
				(string) ( $existing['target_language_code'] ?? '' ),
				(string) ( $existing['content_type'] ?? '' ),
				(string) ( $existing['source_hash'] ?? '' )
			);
		}
	}

	/**
	 * Sync one origin into translation memory, deleting stale non-complete values.
	 *
	 * @param string              $source_lang       Source language code.
	 * @param string              $target_lang       Target language code.
	 * @param string              $content_type      Content type.
	 * @param string              $source_value      Raw source value.
	 * @param string              $translation_value Raw translation value.
	 * @param array<string,mixed> $args              Origin/status metadata.
	 * @return int Memory row ID or 0.
	 */
	public static function sync_translation_memory_origin( $source_lang, $target_lang, $content_type, $source_value, $translation_value, $args = array() ) {
		$status      = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$origin_type = sanitize_key( (string) ( $args['origin_type'] ?? '' ) );
		$origin_id   = (int) ( $args['origin_id'] ?? 0 );
		$origin_key  = sanitize_text_field( (string) ( $args['origin_key'] ?? '' ) );

		if ( 'complete' !== $status ) {
			self::delete_translation_memory_origin( $origin_type, $origin_id, $origin_key );
			return 0;
		}

		if ( '' === self::normalize_translation_memory_source( $translation_value, $content_type ) ) {
			self::delete_translation_memory_origin( $origin_type, $origin_id, $origin_key );
			return 0;
		}

		return self::upsert_translation_memory( $source_lang, $target_lang, $content_type, $source_value, $translation_value, $args );
	}

	/**
	 * Clear one cached translation memory lookup.
	 *
	 * @param string $source_lang  Source language code.
	 * @param string $target_lang  Target language code.
	 * @param string $content_type Content type.
	 * @param string $source_hash  Source hash.
	 * @return void
	 */
	protected static function clear_translation_memory_cache( $source_lang, $target_lang, $content_type, $source_hash ) {
		$source_lang  = sanitize_key( (string) $source_lang );
		$target_lang  = sanitize_key( (string) $target_lang );
		$content_type = sanitize_key( (string) $content_type );
		$source_hash  = sanitize_text_field( (string) $source_hash );
		if ( '' === $source_lang || '' === $target_lang || '' === $content_type || '' === $source_hash ) {
			return;
		}

		$cache_key = 'memory_match_' . md5( implode( '|', array( $source_lang, $target_lang, $content_type, $source_hash ) ) );
		wp_cache_delete( $cache_key, 'leadwerk_translation' );
	}

	/**
	 * Persist one translated string.
	 *
	 * @param string               $package Package name.
	 * @param string               $name    String key.
	 * @param string               $lang    Language code.
	 * @param string               $value   Value.
	 * @param array<string,mixed>  $args    Extra fields.
	 * @return int
	 */
	public static function upsert_string( $package, $name, $lang, $value, $args = array() ) {
		global $wpdb;

		$where = array(
			'package'       => sanitize_key( (string) $package ),
			'object_type'   => sanitize_key( (string) ( $args['object_type'] ?? '' ) ),
			'object_id'     => (int) ( $args['object_id'] ?? 0 ),
			'string_name'   => sanitize_key( (string) $name ),
			'language_code' => sanitize_key( (string) $lang ),
		);
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM " . self::get_strings_table_name() . " WHERE package = %s AND object_type = %s AND object_id = %d AND string_name = %s AND language_code = %s LIMIT 1",
				$where['package'],
				$where['object_type'],
				$where['object_id'],
				$where['string_name'],
				$where['language_code']
			),
			ARRAY_A
		);
		$data     = array_merge(
			$where,
			array(
				'value'       => (string) $value,
				'source_hash' => sanitize_text_field( (string) ( $args['source_hash'] ?? md5( (string) $value ) ) ),
				'status'      => sanitize_key( (string) ( $args['status'] ?? ( '' === trim( (string) $value ) ? 'not_translated' : 'complete' ) ) ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		if ( empty( $existing ) ) {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( self::get_strings_table_name(), $data );
			$row_id = (int) $wpdb->insert_id;
		} else {
			$row_id = (int) $existing['id'];
			$wpdb->update( self::get_strings_table_name(), $data, array( 'id' => $row_id ) );
		}

		$cache_key = "string_{$where['package']}_{$where['string_name']}_{$where['language_code']}_{$where['object_type']}_{$where['object_id']}";
		wp_cache_delete( $cache_key, 'leadwerk_translation' );
		return $row_id;
	}

	/**
	 * Fetch one string translation record.
	 *
	 * @param string $package     Package name.
	 * @param string $name        String key.
	 * @param string $lang        Language code.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string,mixed>
	 */
	public static function get_string_record( $package, $name, $lang, $object_type = '', $object_id = 0 ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_strings_table_name() . " WHERE package = %s AND object_type = %s AND object_id = %d AND string_name = %s AND language_code = %s LIMIT 1",
				sanitize_key( (string) $package ),
				sanitize_key( (string) $object_type ),
				(int) $object_id,
				sanitize_key( (string) $name ),
				sanitize_key( (string) $lang )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Read one localized string.
	 *
	 * @param string $package Package name.
	 * @param string $name    String key.
	 * @param string $lang    Language code.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function get_string( $package, $name, $lang, $default = '' ) {
		$cache_key = "string_{$package}_{$name}_{$lang}__0";
		$cached    = wp_cache_get( $cache_key, 'leadwerk_translation' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM " . self::get_strings_table_name() . " WHERE package = %s AND string_name = %s AND language_code = %s AND object_type = '' AND object_id = 0 LIMIT 1",
				sanitize_key( (string) $package ),
				sanitize_key( (string) $name ),
				sanitize_key( (string) $lang )
			)
		);

		$result = is_null( $value ) ? (string) $default : (string) $value;
		wp_cache_set( $cache_key, $result, 'leadwerk_translation' );

		return $result;
	}

	/**
	 * Read a string package for one language.
	 *
	 * @param string               $package  Package name.
	 * @param string               $lang     Language code.
	 * @param array<string,string> $defaults Defaults.
	 * @return array<string,string>
	 */
	public static function get_package_strings( $package, $lang, $defaults = array() ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT string_name, value FROM " . self::get_strings_table_name() . " WHERE package = %s AND language_code = %s AND object_type = '' AND object_id = 0",
				sanitize_key( (string) $package ),
				sanitize_key( (string) $lang )
			),
			ARRAY_A
		);
		$out  = is_array( $defaults ) ? $defaults : array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['string_name'] ] = (string) $row['value'];
		}

		return $out;
	}

	/**
	 * Fetch string translation rows for one package/language.
	 *
	 * @param string $package     Package name.
	 * @param string $lang        Language code.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_package_string_records( $package, $lang, $object_type = '', $object_id = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_strings_table_name() . " WHERE package = %s AND object_type = %s AND object_id = %d AND language_code = %s ORDER BY string_name ASC",
				sanitize_key( (string) $package ),
				sanitize_key( (string) $object_type ),
				(int) $object_id,
				sanitize_key( (string) $lang )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['string_name'] ) ) {
				continue;
			}

			$out[ $row['string_name'] ] = $row;
		}

		return $out;
	}

	/**
	 * Persist a package for one language.
	 *
	 * @param string               $package Package name.
	 * @param string               $lang    Language code.
	 * @param array<string,string> $strings Strings.
	 * @return void
	 */
	public static function set_package_strings( $package, $lang, $strings ) {
		foreach ( (array) $strings as $name => $value ) {
			self::upsert_string( $package, $name, $lang, (string) $value );
		}
	}

	/**
	 * Reconcile a string package against the current source-language strings.
	 *
	 * @param string               $package      Package name.
	 * @param array<string,string> $source_items Source strings keyed by name.
	 * @param string               $target_lang  Target language.
	 * @param array<string,mixed>  $args         Extra context.
	 * @return array<string,array<string,mixed>>
	 */
	public static function sync_string_package( $package, $source_items, $target_lang = 'en', $args = array() ) {
		$source_lang = sanitize_key( (string) ( $args['source_lang'] ?? self::get_default_language() ) );
		$target_lang = sanitize_key( (string) $target_lang );
		$object_type = sanitize_key( (string) ( $args['object_type'] ?? '' ) );
		$object_id   = (int) ( $args['object_id'] ?? 0 );
		$source_items = is_array( $source_items ) ? $source_items : array();

		$target_records = self::get_package_string_records( $package, $target_lang, $object_type, $object_id );

		foreach ( $source_items as $name => $value ) {
			$name        = sanitize_key( (string) $name );
			$value       = (string) $value;
			$source_hash = md5( $value );

			self::upsert_string(
				$package,
				$name,
				$source_lang,
				$value,
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'source_hash' => $source_hash,
					'status'      => 'complete',
				)
			);

			$target_record = $target_records[ $name ] ?? array();
			$target_value  = (string) ( $target_record['value'] ?? '' );
			$stored_hash   = (string) ( $target_record['source_hash'] ?? '' );
			$memory_match  = array();

			if ( '' === trim( $target_value ) ) {
				$memory_match = self::find_translation_memory_match( $source_lang, $target_lang, 'text', $value );
				if ( ! empty( $memory_match['translation_value'] ) ) {
					$target_value = (string) $memory_match['translation_value'];
				}
			}

			if ( '' === trim( $target_value ) ) {
				$status = 'not_translated';
			} elseif ( empty( $memory_match ) && '' !== $stored_hash && $stored_hash !== $source_hash ) {
				$status = 'needs_update';
			} else {
				$status = 'complete';
			}

			$row_id = self::upsert_string(
				$package,
				$name,
				$target_lang,
				$target_value,
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'source_hash' => $source_hash,
					'status'      => $status,
				)
			);

			self::sync_translation_memory_origin(
				$source_lang,
				$target_lang,
				'text',
				$value,
				$target_value,
				array(
					'status'      => $status,
					'origin_type' => 'string',
					'origin_id'   => $row_id,
					'origin_key'  => implode( '|', array( sanitize_key( (string) $package ), $object_type, (string) $object_id, $name ) ),
				)
			);
		}

		return self::get_package_string_records( $package, $target_lang, $object_type, $object_id );
	}

	/**
	 * Get queued string translations for one language.
	 *
	 * @param string $target_lang Target language.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_string_translation_queue( $target_lang = 'en' ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_strings_table_name() . " WHERE language_code = %s AND status IN ('not_translated','needs_update','in_progress') ORDER BY package ASC, string_name ASC",
				sanitize_key( (string) $target_lang )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Read localized attachment metadata.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $field         Field key.
	 * @param string|null $lang          Optional language code.
	 * @param string      $default       Default.
	 * @return string
	 */
	public static function get_localized_attachment_meta( $attachment_id, $field, $lang = null, $default = '' ) {
		$lang  = $lang ?: self::get_current_request_language();
		$value = self::get_string( 'attachment_' . (int) $attachment_id, $field, $lang, '' );

		if ( '' !== trim( $value ) ) {
			return $value;
		}

		if ( '_wp_attachment_image_alt' === $field ) {
			$meta_value = get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true );
			return '' !== trim( (string) $meta_value ) ? (string) $meta_value : $default;
		}

		$post = get_post( (int) $attachment_id );
		if ( ! $post instanceof WP_Post ) {
			return $default;
		}

		if ( 'post_title' === $field ) {
			return (string) $post->post_title;
		}
		if ( 'post_excerpt' === $field ) {
			return (string) $post->post_excerpt;
		}
		if ( 'post_content' === $field ) {
			return (string) $post->post_content;
		}

		return $default;
	}

	/**
	 * Get stored translation bundle.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_translation_bundle( $post_id ) {
		$bundle = get_post_meta( (int) $post_id, self::META_SEGMENTS, true );
		return is_array( $bundle ) ? $bundle : array();
	}

	/**
	 * Get stored seed snapshot.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_seed_snapshot( $post_id ) {
		$snapshot = get_post_meta( (int) $post_id, self::META_SEED_SNAPSHOT, true );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Persist bundle and derived snapshot.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $bundle  Bundle data.
	 * @return void
	 */
	public static function update_translation_bundle( $post_id, $bundle ) {
		$bundle = is_array( $bundle ) ? $bundle : array();

		update_post_meta( (int) $post_id, self::META_SEGMENTS, $bundle );
		update_post_meta( (int) $post_id, self::META_SEED_SNAPSHOT, self::build_seed_snapshot( $bundle ) );
	}

	/**
	 * Update translation status for one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status key.
	 * @return void
	 */
	public static function update_post_translation_status( $post_id, $status ) {
		self::ensure_post_record(
			(int) $post_id,
			array(
				'status' => sanitize_key( (string) $status ),
			)
		);
	}

	/**
	 * Build compact bundle snapshot.
	 *
	 * @param array<string,mixed> $bundle Translation bundle.
	 * @return array<string,mixed>
	 */
	protected static function build_seed_snapshot( $bundle ) {
		$snapshot = array(
			'updated_at_gmt' => current_time( 'mysql', true ),
			'paths'          => array(),
		);

		foreach ( (array) ( $bundle['paths'] ?? array() ) as $path_key => $path_bundle ) {
			if ( isset( $path_bundle['segments'] ) && is_array( $path_bundle['segments'] ) ) {
				$segments = array();
				foreach ( (array) ( $path_bundle['segments'] ?? array() ) as $segment_key => $segment ) {
					$segments[ $segment_key ] = array(
						'source_hash'  => (string) ( $segment['source_hash'] ?? '' ),
						'status'       => (string) ( $segment['status'] ?? '' ),
						'needs_review' => ! empty( $segment['needs_review'] ),
					);
				}
				$snapshot['paths'][ $path_key ] = array(
					'type'     => (string) ( $path_bundle['type'] ?? 'richtext' ),
					'segments' => $segments,
				);
				continue;
			}

			$snapshot['paths'][ $path_key ] = array(
				'type'         => (string) ( $path_bundle['type'] ?? 'scalar' ),
				'source_hash'  => (string) ( $path_bundle['source_hash'] ?? '' ),
				'status'       => (string) ( $path_bundle['status'] ?? '' ),
				'needs_review' => ! empty( $path_bundle['needs_review'] ),
			);
		}

		return $snapshot;
	}

	/**
	 * Mirror registry values to legacy post meta.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $record  Registry record.
	 * @return void
	 */
	protected static function mirror_post_meta( $post_id, $record ) {
		update_post_meta( $post_id, self::META_LANG, sanitize_key( (string) $record['language_code'] ) );
		update_post_meta( $post_id, self::META_TRID, sanitize_text_field( (string) $record['trid'] ) );
		update_post_meta( $post_id, self::META_SOURCE_ID, (int) ( $record['source_element_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_PUBLIC_SLUG, (string) ( $record['public_slug'] ?? '' ) );
		update_post_meta( $post_id, self::META_STATUS, sanitize_key( (string) ( $record['status'] ?? 'complete' ) ) );

		if ( ! empty( $record['is_home'] ) ) {
			update_post_meta( $post_id, self::META_LANG_ROOT, 1 );
		} else {
			delete_post_meta( $post_id, self::META_LANG_ROOT );
		}
	}

	/**
	 * Build the public path for one translated object.
	 *
	 * @param string $lang        Language code.
	 * @param string $public_slug Public slug.
	 * @param bool   $is_home     Whether record is a language home.
	 * @return string
	 */
	protected static function build_public_path( $lang, $public_slug, $is_home ) {
		$prefix = trim( self::get_language_prefix( $lang ), '/' );

		if ( $is_home ) {
			return '' !== $prefix ? $prefix : '/';
		}

		$path = '' !== $prefix ? $prefix . '/' : '';
		$path .= trim( (string) $public_slug, '/' );

		return trim( $path, '/' );
	}

	/**
	 * Build an internal unique slug for non-default language content.
	 *
	 * @param string  $public_slug Public slug.
	 * @param string  $lang        Language code.
	 * @param bool    $is_home     Whether record is a language home.
	 * @param WP_Post $post        Post object.
	 * @return string
	 */
	protected static function build_internal_slug( $public_slug, $lang, $is_home, $post ) {
		$base = $is_home ? 'home-' . $lang : trim( (string) $public_slug, '/' );
		if ( '' === $base ) {
			$base = $post->post_type . '-' . $lang . '-' . $post->ID;
		}

		if ( self::get_default_language() !== $lang ) {
			$base .= '-' . $lang;
		}

		return wp_unique_post_slug(
			sanitize_title( $base ),
			$post->ID,
			$post->post_status ?: 'publish',
			$post->post_type,
			0
		);
	}

	/**
	 * Generate a unique public slug within one language and post type.
	 *
	 * @param string $public_slug Candidate public slug.
	 * @param string $lang        Language code.
	 * @param int    $post_id     Current post ID. If 0, collision checks are skipped (callers should pass a real ID).
	 * @return string
	 */
	protected static function generate_unique_public_slug( $public_slug, $lang, $post_id = 0 ) {
		$base = sanitize_title( (string) $public_slug );
		if ( '' === $base ) {
			return '';
		}

		$candidate = $base;
		$suffix    = 2;

		while ( self::public_slug_exists_for_other_post( $candidate, $lang, $post_id ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Whether a public slug is already used by another element of the same post type in the same language.
	 *
	 * Scoped to element_type (e.g. post_page only vs post_page) so attachments or other CPTs
	 * do not force -2 suffixes on pages. Without a valid post_id, returns false (no collision).
	 *
	 * @param string $public_slug Public slug.
	 * @param string $lang        Language code.
	 * @param int    $post_id     Current post ID.
	 * @return bool
	 */
	protected static function public_slug_exists_for_other_post( $public_slug, $lang, $post_id = 0 ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$element_type = self::get_post_element_type( $post );

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT element_id FROM " . self::get_elements_table_name() . " WHERE language_code = %s AND public_slug = %s AND element_type = %s AND element_id <> %d LIMIT 1",
				sanitize_key( (string) $lang ),
				trim( (string) $public_slug, '/' ),
				(string) $element_type,
				$post_id
			)
		);

		return $existing_id > 0;
	}

	/**
	 * Derive public slug from a post record.
	 *
	 * @param WP_Post $post Post object.
	 * @param string  $lang Language code.
	 * @return string
	 */
	protected static function derive_public_slug_from_post( $post, $lang ) {
		$stored = trim( (string) get_post_meta( $post->ID, self::META_PUBLIC_SLUG, true ), '/' );
		if ( '' !== $stored ) {
			$slug = $stored;
		} else {
			$slug = trim( (string) $post->post_name, '/' );
			if ( self::get_default_language() !== $lang ) {
				$suffix = '-' . sanitize_key( (string) $lang );
				if ( $suffix === substr( $slug, -strlen( $suffix ) ) ) {
					$slug = substr( $slug, 0, -strlen( $suffix ) );
				}
			}
		}

		if ( 'acm_news' === $post->post_type ) {
			return self::normalize_acm_news_registry_public_slug( $slug );
		}

		return $slug;
	}

	/**
	 * Registry public path for acm_news must match CPT rewrite: /news/{slug}/ (plus language prefix in build_public_path).
	 * Legacy rows stored only post_name — repair duplicate or missing news/ segments.
	 *
	 * @param string $public_slug Raw slug (may be missing news/ or repeat it).
	 * @return string Normalized path segment e.g. "news/my-article".
	 */
	protected static function normalize_acm_news_registry_public_slug( $public_slug ) {
		$s = trim( (string) $public_slug, '/' );
		if ( '' === $s ) {
			return '';
		}
		while ( preg_match( '#^news/#i', $s ) ) {
			$s = trim( (string) preg_replace( '#^news/#i', '', $s ), '/' );
		}
		if ( '' === $s ) {
			return '';
		}

		return 'news/' . $s;
	}

	/**
	 * Link a source term with its translation.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $source_taxonomy Taxonomy slug.
	 * @param int    $translated_term_id Translated term ID.
	 * @param string $target_lang Target language.
	 * @param array<string,mixed> $args Extra fields.
	 * @return string trid
	 */
	public static function link_terms( $source_term_id, $source_taxonomy, $translated_term_id, $target_lang, $args = array() ) {
		$source_term = get_term( (int) $source_term_id, $source_taxonomy );
		$target_term = get_term( (int) $translated_term_id, $source_taxonomy );

		if ( ! $source_term instanceof WP_Term || ! $target_term instanceof WP_Term ) {
			return '';
		}

		$element_type  = self::get_term_element_type( $source_taxonomy );
		$source_record = self::upsert_element(
			$element_type,
			$source_term->term_id,
			array(
				'language_code' => self::get_default_language(),
				'public_slug'   => $source_term->slug,
				'internal_slug' => $source_term->slug,
				'status'        => 'complete',
			)
		);

		self::upsert_element(
			$element_type,
			$target_term->term_id,
			array(
				'trid'                 => $source_record['trid'] ?? self::generate_trid(),
				'language_code'        => sanitize_key( (string) $target_lang ),
				'source_language_code' => self::get_default_language(),
				'source_element_id'    => $source_term->term_id,
				'public_slug'          => $args['public_slug'] ?? $target_term->slug,
				'internal_slug'        => $args['internal_slug'] ?? $target_term->slug,
				'status'               => $args['status'] ?? 'complete',
			)
		);

		return (string) ( $source_record['trid'] ?? '' );
	}

	/**
	 * Get one term translation by language.
	 *
	 * @param int    $term_id  Source term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $lang     Target language.
	 * @return int Translated term ID or 0.
	 */
	public static function get_term_translation( $term_id, $taxonomy, $lang ) {
		$element_type = self::get_term_element_type( $taxonomy );
		$record       = self::get_registry_record( $element_type, (int) $term_id );

		if ( empty( $record['trid'] ) ) {
			return 0;
		}

		global $wpdb;
		$target_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT element_id FROM " . self::get_elements_table_name() . " WHERE trid = %s AND element_type = %s AND language_code = %s LIMIT 1",
				(string) $record['trid'],
				$element_type,
				sanitize_key( (string) $lang )
			)
		);

		return $target_id;
	}

	/**
	 * Calculate translation completeness percentage for a post.
	 *
	 * @param int $translated_post_id Translated post ID.
	 * @return int Percentage 0-100.
	 */
	public static function get_translation_completeness( $translated_post_id ) {
		$bundle = self::get_translation_bundle( (int) $translated_post_id );
		$paths  = (array) ( $bundle['paths'] ?? array() );

		if ( empty( $paths ) ) {
			return 100;
		}

		$total    = count( $paths );
		$complete = 0;

		foreach ( $paths as $path_bundle ) {
			$translation = trim( (string) ( $path_bundle['translation'] ?? '' ) );
			if ( '' !== $translation ) {
				++$complete;
			}
		}

		return (int) round( ( $complete / $total ) * 100 );
	}
}

