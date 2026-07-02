<?php
/**
 * Database install and legacy migration helpers.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_Migrator {

	const OPTION_DB_VERSION = 'leadwerk_translation_db_version';
	const DB_VERSION        = '2.1.0';
	const OPTION_REPAIR_VERSION = 'leadwerk_translation_registry_repair_version';
	const REPAIR_VERSION        = '2026-03-27-1';
	const OPTION_MEMORY_VERSION = 'leadwerk_translation_memory_version';
	const MEMORY_VERSION        = '2026-03-28-1';

	/**
	 * Create or upgrade plugin storage.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$elements_table  = Leadwerk_Translation_API::get_elements_table_name();
		$strings_table   = Leadwerk_Translation_API::get_strings_table_name();
		$memory_table    = Leadwerk_Translation_API::get_memory_table_name();

		$sql = "
CREATE TABLE {$elements_table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	element_type varchar(64) NOT NULL,
	element_id bigint(20) unsigned NOT NULL,
	trid varchar(64) NOT NULL,
	language_code varchar(10) NOT NULL,
	source_language_code varchar(10) NOT NULL DEFAULT '',
	source_element_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(32) NOT NULL DEFAULT 'complete',
	source_key varchar(191) NOT NULL DEFAULT '',
	public_slug varchar(191) NOT NULL DEFAULT '',
	internal_slug varchar(191) NOT NULL DEFAULT '',
	is_home tinyint(1) NOT NULL DEFAULT 0,
	updated_at datetime NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY element_lookup (element_type,element_id),
	KEY trid_lookup (trid,language_code),
	KEY source_key_lookup (source_key,language_code),
	KEY public_slug_lookup (language_code,public_slug),
	KEY source_element_lookup (source_element_id,language_code)
) {$charset_collate};

CREATE TABLE {$strings_table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	package varchar(191) NOT NULL,
	object_type varchar(64) NOT NULL DEFAULT '',
	object_id bigint(20) unsigned NOT NULL DEFAULT 0,
	string_name varchar(191) NOT NULL,
	language_code varchar(10) NOT NULL,
	value longtext NULL,
	source_hash char(32) NOT NULL DEFAULT '',
	status varchar(32) NOT NULL DEFAULT 'complete',
	updated_at datetime NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY string_lookup (package,object_type,object_id,string_name,language_code),
	KEY package_lookup (package,language_code),
	KEY object_lookup (object_type,object_id,language_code)
) {$charset_collate};

CREATE TABLE {$memory_table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	source_language_code varchar(10) NOT NULL,
	target_language_code varchar(10) NOT NULL,
	content_type varchar(32) NOT NULL,
	source_hash char(32) NOT NULL DEFAULT '',
	source_normalized longtext NULL,
	source_value longtext NULL,
	translation_value longtext NULL,
	origin_type varchar(64) NOT NULL,
	origin_id bigint(20) unsigned NOT NULL DEFAULT 0,
	origin_key varchar(191) NOT NULL DEFAULT '',
	updated_at datetime NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY origin_lookup (origin_type,origin_id,origin_key),
	KEY source_lookup (source_language_code,target_language_code,content_type,source_hash)
) {$charset_collate};
";

		dbDelta( $sql );

		if ( ! get_option( Leadwerk_Translation_API::OPTION_SETTINGS ) ) {
			add_option( Leadwerk_Translation_API::OPTION_SETTINGS, Leadwerk_Translation_API::get_default_settings() );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		self::migrate_legacy_content();
		self::migrate_theme_strings();
		self::maybe_repair_translation_registry();
		self::maybe_backfill_translation_memory( true );
	}

	/**
	 * Run install when DB version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
			self::install();
			return;
		}

		self::migrate_theme_strings();
		self::maybe_repair_translation_registry();
		self::maybe_backfill_translation_memory();
	}

	/**
	 * Migrate existing meta-based translations into the registry table.
	 *
	 * @return void
	 */
	protected static function migrate_legacy_content() {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => Leadwerk_Translation_API::META_LANG,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Leadwerk_Translation_API::META_TRID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$lang       = sanitize_key( (string) get_post_meta( $post->ID, Leadwerk_Translation_API::META_LANG, true ) ) ?: Leadwerk_Translation_API::get_default_language();
			$source_key = sanitize_key( (string) get_post_meta( $post->ID, 'leadwerk_source_key', true ) );
			$is_home    = ! empty( get_post_meta( $post->ID, Leadwerk_Translation_API::META_LANG_ROOT, true ) ) || in_array( $source_key, array( 'acm-home-v1', 'acm-index-v1' ), true ) || ( Leadwerk_Translation_API::get_default_language() === $lang && (int) get_option( 'page_on_front' ) === (int) $post->ID );
			$public_slug = trim( (string) get_post_meta( $post->ID, Leadwerk_Translation_API::META_PUBLIC_SLUG, true ), '/' );

			if ( '' === $public_slug && ! $is_home ) {
				$public_slug = trim( (string) $post->post_name, '/' );
			}

			if ( Leadwerk_Translation_API::get_default_language() !== $lang && ( (int) $post->post_parent > 0 || $post->post_name === $public_slug ) ) {
				$new_slug = wp_unique_post_slug(
					sanitize_title( ( '' !== $public_slug ? $public_slug : $post->post_type ) . '-' . $lang ),
					$post->ID,
					$post->post_status ?: 'publish',
					$post->post_type,
					0
				);

				wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_name'   => $new_slug,
						'post_parent' => 0,
					)
				);

				$post = get_post( $post->ID );
			}

			Leadwerk_Translation_API::ensure_post_record(
				$post->ID,
				array(
					'language_code'        => $lang,
					'trid'                 => get_post_meta( $post->ID, Leadwerk_Translation_API::META_TRID, true ),
					'source_language_code' => Leadwerk_Translation_API::get_default_language() === $lang ? '' : Leadwerk_Translation_API::get_default_language(),
					'source_element_id'    => (int) get_post_meta( $post->ID, Leadwerk_Translation_API::META_SOURCE_ID, true ),
					'source_key'           => $source_key,
					'public_slug'          => $is_home ? '' : $public_slug,
					'internal_slug'        => $post instanceof WP_Post ? $post->post_name : '',
					'is_home'              => $is_home,
					'status'               => sanitize_key( (string) get_post_meta( $post->ID, Leadwerk_Translation_API::META_STATUS, true ) ) ?: 'complete',
				)
			);
		}
	}

	/**
	 * Migrate theme_strings_{lang} JSON options into string packages.
	 *
	 * @return void
	 */
	protected static function migrate_theme_strings() {
		foreach ( array( 'de', 'en' ) as $lang ) {
			$existing = Leadwerk_Translation_API::get_package_strings( 'theme_strings', $lang, array() );
			if ( ! empty( $existing ) ) {
				continue;
			}

			$raw = Leadwerk_Translation_API::get_localized_option( 'theme_strings', $lang, '' );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				Leadwerk_Translation_API::set_package_strings( 'theme_strings', $lang, $decoded );
			}
		}
	}

	/**
	 * Run the idempotent registry repair pass once per repair version.
	 *
	 * @return void
	 */
	protected static function maybe_repair_translation_registry() {
		if ( self::REPAIR_VERSION === (string) get_option( self::OPTION_REPAIR_VERSION, '' ) ) {
			return;
		}

		foreach ( Leadwerk_Translation_API::get_active_languages() as $lang => $config ) {
			if ( Leadwerk_Translation_API::get_default_language() === $lang ) {
				continue;
			}

			Leadwerk_Translation_API::repair_all_translation_links( $lang );
		}

		update_option( self::OPTION_REPAIR_VERSION, self::REPAIR_VERSION );
	}

	/**
	 * Build translation memory from existing complete translations once per version.
	 *
	 * @param bool $force Whether rebuild should run regardless of stored version.
	 * @return void
	 */
	protected static function maybe_backfill_translation_memory( $force = false ) {
		if ( ! $force && self::MEMORY_VERSION === (string) get_option( self::OPTION_MEMORY_VERSION, '' ) ) {
			return;
		}

		self::backfill_string_translation_memory();
		self::backfill_bundle_translation_memory();
		update_option( self::OPTION_MEMORY_VERSION, self::MEMORY_VERSION );
	}

	/**
	 * Backfill translation memory from existing string translations.
	 *
	 * @return void
	 */
	protected static function backfill_string_translation_memory() {
		global $wpdb;

		$source_lang = Leadwerk_Translation_API::get_default_language();
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target.id, target.package, target.object_type, target.object_id, target.string_name, target.language_code AS target_lang, target.value AS target_value, target.status AS target_status, source.value AS source_value
				FROM " . Leadwerk_Translation_API::get_strings_table_name() . " AS target
				INNER JOIN " . Leadwerk_Translation_API::get_strings_table_name() . " AS source
					ON source.package = target.package
					AND source.object_type = target.object_type
					AND source.object_id = target.object_id
					AND source.string_name = target.string_name
					AND source.language_code = %s
				WHERE target.language_code <> %s",
				$source_lang,
				$source_lang
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			Leadwerk_Translation_API::sync_translation_memory_origin(
				$source_lang,
				sanitize_key( (string) ( $row['target_lang'] ?? '' ) ),
				'text',
				(string) ( $row['source_value'] ?? '' ),
				(string) ( $row['target_value'] ?? '' ),
				array(
					'status'      => sanitize_key( (string) ( $row['target_status'] ?? '' ) ),
					'origin_type' => 'string',
					'origin_id'   => (int) ( $row['id'] ?? 0 ),
					'origin_key'  => implode(
						'|',
						array(
							sanitize_key( (string) ( $row['package'] ?? '' ) ),
							sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
							(string) (int) ( $row['object_id'] ?? 0 ),
							sanitize_key( (string) ( $row['string_name'] ?? '' ) ),
						)
					),
				)
			);
		}
	}

	/**
	 * Backfill translation memory from existing translated post bundles.
	 *
	 * @return void
	 */
	protected static function backfill_bundle_translation_memory() {
		global $wpdb;

		$source_lang = Leadwerk_Translation_API::get_default_language();
		$ids         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT element_id FROM " . Leadwerk_Translation_API::get_elements_table_name() . " WHERE element_type LIKE 'post_%%' AND language_code <> %s AND source_element_id > 0",
				$source_lang
			)
		);

		foreach ( array_map( 'intval', (array) $ids ) as $translated_post_id ) {
			$source_post_id = (int) Leadwerk_Translation_API::get_source_post_id( $translated_post_id );
			if ( $source_post_id < 1 ) {
				continue;
			}

			Leadwerk_Translation_Sync::sync_bundle_translation_memory(
				$source_post_id,
				$translated_post_id,
				Leadwerk_Translation_API::get_translation_bundle( $translated_post_id )
			);
		}
	}
}

