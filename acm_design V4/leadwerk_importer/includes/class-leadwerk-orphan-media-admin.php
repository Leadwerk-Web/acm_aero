<?php
/**
 * Admin: orphan media — (1) Leadwerk import meta + heuristics, (2) strict: any attachment not in schema field trees.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools screen for cleaning duplicate-import orphan media.
 */
class Leadwerk_Orphan_Media_Admin {

	const PER_PAGE     = 50;
	const NONCE_ACTION = 'leadwerk_orphan_media';
	const NONCE_NAME   = 'leadwerk_orphan_media_nonce';

	/**
	 * Parsed leadwerk_opt_* values (request-level cache).
	 *
	 * @var mixed[]|null
	 */
	protected static $leadwerk_option_values_cache = null;

	/**
	 * Attachment IDs found inside Leadwerk schema field meta + options (request cache).
	 *
	 * @var array<int,bool>|null
	 */
	protected static $field_linked_attachment_ids_cache = null;

	/** @var string */
	const MODE_IMPORT = 'import';

	/** @var string */
	const MODE_STRICT_FIELDS = 'strict_fields';

	/**
	 * Register orphan screens: prefer submenus under Leadwerk Translation (reliable), else top-level.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_action( 'admin_menu', array( __CLASS__, 'register_orphan_admin_menus' ), 100 );
	}

	/**
	 * @return bool
	 */
	protected static function leadwerk_translation_top_level_exists() {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return false;
		}
		foreach ( $menu as $item ) {
			if ( is_array( $item ) && ! empty( $item[2] ) && 'leadwerk-translation' === $item[2] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return void
	 */
	public static function register_orphan_admin_menus() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::leadwerk_translation_top_level_exists() ) {
			add_submenu_page(
				'leadwerk-translation',
				__( 'Orphan (import)', 'leadwerk-importer' ),
				__( 'Orphan (import)', 'leadwerk-importer' ),
				'manage_options',
				'leadwerk-orphan-import',
				array( __CLASS__, 'render_menu_page_import' )
			);

			add_submenu_page(
				'leadwerk-translation',
				__( 'Orphan (Fields)', 'leadwerk-importer' ),
				__( 'Orphan (Fields)', 'leadwerk-importer' ),
				'manage_options',
				'leadwerk-orphan-fields',
				array( __CLASS__, 'render_menu_page_strict' )
			);
			return;
		}

		add_menu_page(
			__( 'Leadwerk orphan (import)', 'leadwerk-importer' ),
			__( 'Orphan (import)', 'leadwerk-importer' ),
			'manage_options',
			'leadwerk-orphan-import',
			array( __CLASS__, 'render_menu_page_import' ),
			'dashicons-format-gallery',
			82
		);

		add_menu_page(
			__( 'Leadwerk orphan (Fields)', 'leadwerk-importer' ),
			__( 'Orphan (Fields)', 'leadwerk-importer' ),
			'manage_options',
			'leadwerk-orphan-fields',
			array( __CLASS__, 'render_menu_page_strict' ),
			'dashicons-dismiss',
			83
		);
	}

	/**
	 * Top-level menu: import-heuristic orphan list.
	 *
	 * @return void
	 */
	public static function render_menu_page_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'leadwerk-importer' ) );
		}
		echo '<div class="wrap">';
		self::render_inner(
			array( 'page' => 'leadwerk-orphan-import' ),
			'h1',
			self::MODE_IMPORT,
			'admin.php'
		);
		echo '</div>';
	}

	/**
	 * Top-level menu: strict field-orphan list.
	 *
	 * @return void
	 */
	public static function render_menu_page_strict() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'leadwerk-importer' ) );
		}
		echo '<div class="wrap">';
		self::render_inner(
			array( 'page' => 'leadwerk-orphan-fields' ),
			'h1',
			self::MODE_STRICT_FIELDS,
			'admin.php'
		);
		echo '</div>';
	}

	/**
	 * Post types whose fields/content count as a reference (filterable).
	 *
	 * @return string[]
	 */
	protected static function get_scoped_post_types() {
		$types = array( 'page', 'acm_news' );
		if ( class_exists( 'Leadwerk_Translation_API' ) ) {
			$settings = Leadwerk_Translation_API::get_settings();
			if ( ! empty( $settings['translatable_post_types'] ) && is_array( $settings['translatable_post_types'] ) ) {
				$types = array_merge( $types, $settings['translatable_post_types'] );
			}
		}
		$types = array_map( 'sanitize_key', $types );
		$types = array_values( array_filter( array_unique( $types ) ) );
		if ( empty( $types ) ) {
			$types = array( 'page', 'acm_news' );
		}

		/**
		 * Post types scanned for meta/content references when detecting orphan Leadwerk media.
		 *
		 * @param string[] $types Post type names.
		 */
		return apply_filters( 'leadwerk_orphan_media_scoped_post_types', $types );
	}

	/**
	 * Public accessor for scoped post types (WebP body tool, other admin utilities).
	 *
	 * @return string[]
	 */
	public static function get_scoped_post_types_public() {
		return self::get_scoped_post_types();
	}

	/**
	 * Distinct attachment IDs that have Leadwerk source path meta.
	 *
	 * @return int[]
	 */
	public static function get_leadwerk_import_attachment_ids() {
		global $wpdb;

		$types = self::get_scoped_post_types();
		// Still list all leadwerk attachments, not only those tied to scoped types (attachment is its own post).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS att ON att.ID = pm.post_id AND att.post_type = 'attachment'
			WHERE pm.meta_key = 'leadwerk_source_path' AND pm.meta_value <> ''"
		);

		return array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
	}

	/**
	 * Decode a post meta / option value the same way Leadwerk_Fields_API does (JSON or digit string).
	 *
	 * @param mixed $raw Raw meta value.
	 * @return mixed
	 */
	protected static function decode_leadwerk_stored_value( $raw ) {
		if ( ! is_string( $raw ) ) {
			return $raw;
		}
		if ( '' === $raw ) {
			return null;
		}
		$first = $raw[0] ?? '';
		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		return $raw;
	}

	/**
	 * Collect attachment post IDs referenced inside nested field values (integers that are real attachments).
	 *
	 * @param mixed           $data   Decoded field tree.
	 * @param array<int,bool> $bucket ID => true.
	 * @return void
	 */
	protected static function absorb_attachment_ints_from_value( $data, array &$bucket ) {
		if ( is_int( $data ) || ( is_string( $data ) && '' !== $data && ctype_digit( $data ) ) ) {
			$id = (int) $data;
			if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
				$bucket[ $id ] = true;
			}
			return;
		}
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $v ) {
			self::absorb_attachment_ints_from_value( $v, $bucket );
		}
	}

	/**
	 * All attachment IDs that appear in Leadwerk_Content_Schema group post meta or leadwerk_opt_* options.
	 *
	 * @return array<int,bool>
	 */
	public static function collect_field_linked_attachment_ids() {
		if ( null !== self::$field_linked_attachment_ids_cache ) {
			return self::$field_linked_attachment_ids_cache;
		}

		$bucket = array();

		if ( class_exists( 'Leadwerk_Content_Schema' ) ) {
			$keys = array_keys( Leadwerk_Content_Schema::get_groups() );
			if ( ! empty( $keys ) ) {
				global $wpdb;
				$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$sql            = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match key count.
				$prepared_sql = $wpdb->prepare( $sql, $keys );
				$rows         = $wpdb->get_col( $prepared_sql );
				foreach ( (array) $rows as $raw ) {
					self::absorb_attachment_ints_from_value( self::decode_leadwerk_stored_value( $raw ), $bucket );
				}
			}
		}

		global $wpdb;
		$opt_rows = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'leadwerk_opt_%'"
		);
		foreach ( (array) $opt_rows as $raw ) {
			self::absorb_attachment_ints_from_value( self::decode_leadwerk_stored_value( maybe_unserialize( $raw ) ), $bucket );
		}

		/**
		 * Extra attachment IDs always treated as field-linked (e.g. custom option namespaces).
		 *
		 * @param array<int,bool> $bucket ID => true.
		 */
		$filtered = apply_filters( 'leadwerk_field_linked_attachment_ids', $bucket );
		self::$field_linked_attachment_ids_cache = is_array( $filtered ) ? $filtered : $bucket;

		return self::$field_linked_attachment_ids_cache;
	}

	/**
	 * Featured images, site icon, custom logo — always kept when using strict field mode.
	 *
	 * @return array<int,bool>
	 */
	public static function collect_core_protected_attachment_ids() {
		global $wpdb;

		$bucket = array();
		$rows   = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value <> '' AND meta_value <> '0'"
		);
		foreach ( (array) $rows as $v ) {
			$id = (int) $v;
			if ( $id > 0 ) {
				$bucket[ $id ] = true;
			}
		}
		$sid = (int) get_option( 'site_icon' );
		if ( $sid > 0 ) {
			$bucket[ $sid ] = true;
		}
		$logo = (int) get_theme_mod( 'custom_logo', 0 );
		if ( $logo > 0 ) {
			$bucket[ $logo ] = true;
		}

		return $bucket;
	}

	/**
	 * Attachments not referenced in Leadwerk field trees; excludes featured/site icon/logo only.
	 *
	 * @return int[]
	 */
	public static function get_strict_field_orphan_attachment_ids() {
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			return array();
		}

		$linked    = self::collect_field_linked_attachment_ids();
		$protected = self::collect_core_protected_attachment_ids();

		global $wpdb;
		$all = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private','publish')"
		);

		$orphans = array();
		foreach ( (array) $all as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			if ( isset( $linked[ $id ] ) || isset( $protected[ $id ] ) ) {
				continue;
			}
			$orphans[] = $id;
		}

		/**
		 * Attachment IDs to exclude from strict-field orphan deletion (safety).
		 *
		 * @param int[] $orphans Candidate IDs.
		 */
		$orphans = apply_filters( 'leadwerk_strict_field_orphan_attachment_ids', $orphans );

		return array_values(
			array_filter(
				array_map( 'intval', (array) $orphans ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
	}

	/**
	 * Delete attachments for strict-field mode (no leadwerk_source_path required).
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array{deleted:int,skipped:int}
	 */
	public static function delete_strict_field_orphan_attachments( array $ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		$deleted = 0;
		$skipped = 0;

		self::$field_linked_attachment_ids_cache = null;
		$allowed                                = array_flip( self::get_strict_field_orphan_attachment_ids() );

		foreach ( $ids as $id ) {
			if ( ! isset( $allowed[ $id ] ) ) {
				++$skipped;
				continue;
			}
			if ( ! get_post( $id ) instanceof WP_Post || 'attachment' !== get_post_type( $id ) ) {
				++$skipped;
				continue;
			}
			$result = wp_delete_attachment( $id, true );
			if ( ! $result ) {
				++$skipped;
				continue;
			}
			++$deleted;
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);
	}

	/**
	 * Whether an attachment is treated as in-use (do not offer as orphan).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function attachment_is_referenced( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return true;
		}

		if ( (int) get_option( 'site_icon' ) === $attachment_id ) {
			return true;
		}

		$custom_logo = (int) get_theme_mod( 'custom_logo', 0 );
		if ( $custom_logo === $attachment_id ) {
			return true;
		}

		if ( self::referenced_in_leadwerk_options( $attachment_id ) ) {
			return true;
		}

		global $wpdb;
		$types         = self::get_scoped_post_types();
		$placeholders  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params_thumb = array_merge( $types, array( (string) $attachment_id ) );
		$sql_thumb    = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ($placeholders)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s";
		$count_thumb   = (int) $wpdb->get_var( $wpdb->prepare( $sql_thumb, ...$params_thumb ) );
		if ( $count_thumb > 0 ) {
			return true;
		}

		$like_serialized = '%' . $wpdb->esc_like( 'i:' . $attachment_id . ';' ) . '%';
		$params_meta     = array_merge(
			$types,
			array( $attachment_id, (string) $attachment_id, $like_serialized )
		);
		$sql_meta        = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ($placeholders)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.post_id <> %d
			AND ( pm.meta_value = %s OR pm.meta_value LIKE %s )";
		$count_meta      = (int) $wpdb->get_var( $wpdb->prepare( $sql_meta, ...$params_meta ) );
		if ( $count_meta > 0 ) {
			return true;
		}

		$like_img = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';
		$params_ct = array_merge( $types, array( $like_img, $like_img ) );
		$sql_ct    = "SELECT COUNT(*) FROM {$wpdb->posts} AS p
			WHERE p.post_type IN ($placeholders)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND ( p.post_content LIKE %s OR p.post_excerpt LIKE %s )";
		$count_ct  = (int) $wpdb->get_var( $wpdb->prepare( $sql_ct, ...$params_ct ) );
		if ( $count_ct > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the attachment ID appears inside leadwerk_opt_* option trees (public for WebP tool).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function attachment_referenced_in_leadwerk_options_only( $attachment_id ) {
		return self::referenced_in_leadwerk_options( (int) $attachment_id );
	}

	/**
	 * Recursive check for attachment ID inside leadwerk_opt_* option values.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected static function referenced_in_leadwerk_options( $attachment_id ) {
		if ( null === self::$leadwerk_option_values_cache ) {
			global $wpdb;
			self::$leadwerk_option_values_cache = array();
			$rows                               = $wpdb->get_col(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'leadwerk_opt_%'"
			);
			foreach ( (array) $rows as $raw ) {
				self::$leadwerk_option_values_cache[] = maybe_unserialize( $raw );
			}
		}

		foreach ( self::$leadwerk_option_values_cache as $val ) {
			if ( self::value_tree_contains_attachment_id( $val, (int) $attachment_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walk arrays/scalars for numeric attachment reference (ACF-style ID / nested arrays).
	 *
	 * @param mixed $data          Option or meta value.
	 * @param int   $attachment_id Target ID.
	 * @return bool
	 */
	protected static function value_tree_contains_attachment_id( $data, $attachment_id ) {
		if ( is_int( $data ) || is_float( $data ) ) {
			return (int) $data === $attachment_id;
		}

		if ( is_string( $data ) ) {
			if ( (string) (int) $data === $data && (int) $data === $attachment_id ) {
				return true;
			}
			$maybe = maybe_unserialize( $data );
			if ( $maybe !== $data && ( is_array( $maybe ) || is_object( $maybe ) ) ) {
				return self::value_tree_contains_attachment_id( $maybe, $attachment_id );
			}
			return false;
		}

		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}

		if ( ! is_array( $data ) ) {
			return false;
		}

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && 'id' === strtolower( $key ) ) {
				if ( (int) $value === $attachment_id ) {
					return true;
				}
			}
			if ( self::value_tree_contains_attachment_id( $value, $attachment_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IDs of Leadwerk imports with no detected reference.
	 *
	 * @return int[]
	 */
	public static function get_orphan_attachment_ids() {
		$out = array();
		foreach ( self::get_leadwerk_import_attachment_ids() as $id ) {
			if ( ! self::attachment_is_referenced( (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Delete attachments by ID if still Leadwerk-imported and still unreferenced.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array{deleted:int,skipped:int}
	 */
	public static function delete_orphan_attachments( array $ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		$deleted = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			$path = get_post_meta( $id, 'leadwerk_source_path', true );
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				++$skipped;
				continue;
			}
			if ( self::attachment_is_referenced( $id ) ) {
				++$skipped;
				continue;
			}
			if ( ! get_post( $id ) instanceof WP_Post || 'attachment' !== get_post_type( $id ) ) {
				++$skipped;
				continue;
			}

			$result = wp_delete_attachment( $id, true );
			if ( ! $result ) {
				++$skipped;
				continue;
			}
			++$deleted;
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);
	}

	/**
	 * Query args when embedded under Leadwerk Import.
	 *
	 * @return array<string,string>
	 */
	public static function get_embedded_query_args() {
		return array(
			'page'         => 'leadwerk-import',
			'leadwerk_tab' => 'orphan-media',
		);
	}

	/**
	 * Embedded tab: strict field orphans.
	 *
	 * @return array<string,string>
	 */
	public static function get_embedded_strict_query_args() {
		return array(
			'page'         => 'leadwerk-import',
			'leadwerk_tab' => 'orphan-media-strict',
		);
	}

	/**
	 * Build admin URL (tools.php or admin.php) with query args.
	 *
	 * @param string                   $script Basename: tools.php or admin.php.
	 * @param array<string,string|int> $args   Query args.
	 * @return string
	 */
	protected static function build_screen_url( $script, array $args ) {
		$script = in_array( (string) $script, array( 'tools.php', 'admin.php' ), true ) ? (string) $script : 'tools.php';
		return add_query_arg( $args, admin_url( $script ) );
	}

	/**
	 * Render only inner content (for embedding on Leadwerk Import or top-level menus).
	 *
	 * @param array<string,string|int> $url_base_args Query args for pagination and form action.
	 * @param string                   $heading_tag   h1 or h2.
	 * @param string                   $mode          import|strict_fields.
	 * @param string                   $admin_script  tools.php (Leadwerk Import tabs) or admin.php (top-level menus).
	 * @return void
	 */
	public static function render_inner( array $url_base_args, $heading_tag = 'h1', $mode = self::MODE_IMPORT, $admin_script = 'tools.php' ) {
		$heading_tag  = in_array( (string) $heading_tag, array( 'h1', 'h2' ), true ) ? (string) $heading_tag : 'h1';
		$admin_script = in_array( (string) $admin_script, array( 'tools.php', 'admin.php' ), true ) ? (string) $admin_script : 'tools.php';
		$strict       = ( self::MODE_STRICT_FIELDS === $mode );

		$title = $strict
			? esc_html__( 'Orphan media (not in Leadwerk Fields)', 'leadwerk-importer' )
			: esc_html__( 'Leadwerk orphan media (import)', 'leadwerk-importer' );
		if ( 'h2' === $heading_tag ) {
			echo '<h2>' . $title . '</h2>';
		} else {
			echo '<h1>' . $title . '</h1>';
		}

		if ( isset( $_POST[ self::NONCE_NAME ] )
			&& wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION )
			&& isset( $_POST['leadwerk_orphan_media_action'] )
			&& 'delete_selected' === sanitize_key( wp_unslash( $_POST['leadwerk_orphan_media_action'] ) ) ) {
			$post_mode = isset( $_POST['leadwerk_orphan_media_mode'] )
				? sanitize_key( wp_unslash( $_POST['leadwerk_orphan_media_mode'] ) )
				: self::MODE_IMPORT;
			$sel       = isset( $_POST['leadwerk_orphan_attachment_ids'] ) ? (array) wp_unslash( $_POST['leadwerk_orphan_attachment_ids'] ) : array();

			if ( self::MODE_STRICT_FIELDS === $post_mode ) {
				$res = self::delete_strict_field_orphan_attachments( $sel );
				$msg = sprintf(
					/* translators: 1: deleted count, 2: skipped count */
					__( 'Deleted %1$d attachment(s); skipped %2$d (no longer orphan, wrong type, or error).', 'leadwerk-importer' ),
					(int) $res['deleted'],
					(int) $res['skipped']
				);
			} else {
				$res = self::delete_orphan_attachments( $sel );
				$msg = sprintf(
					/* translators: 1: deleted count, 2: skipped count */
					__( 'Deleted %1$d attachment(s); skipped %2$d (in use, not Leadwerk import, or error).', 'leadwerk-importer' ),
					(int) $res['deleted'],
					(int) $res['skipped']
				);
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( $strict && ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Leadwerk Fields schema is not loaded. Activate the leadwerk-fields plugin.', 'leadwerk-importer' ) . '</p></div>';
			return;
		}

		$orphans  = $strict ? self::get_strict_field_orphan_attachment_ids() : self::get_orphan_attachment_ids();
		$total    = count( $orphans );
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$chunks   = array_chunk( $orphans, self::PER_PAGE );
		$page_idx = $paged - 1;
		$slice    = isset( $chunks[ $page_idx ] ) ? $chunks[ $page_idx ] : array();
		$max_page = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$form_url = self::build_screen_url( $admin_script, $url_base_args );

		if ( $strict ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Strict mode.', 'leadwerk-importer' ) . '</strong> ';
			echo esc_html__( 'Only attachment IDs found inside Leadwerk_Content_Schema field meta keys and leadwerk_opt_* options count as “in use”. Featured images, site icon, and custom logo are always kept. Content embedded only in post_content (without a field reference) may be listed here and deleted — verify before removing.', 'leadwerk-importer' );
			echo '</p></div>';
		} else {
			echo '<p>' . esc_html__( 'Lists Leadwerk-imported files (meta: leadwerk_source_path) that still look unused (thumbnails, theme options, wp-image in scoped content, etc.).', 'leadwerk-importer' ) . '</p>';
		}

		if ( $total < 1 ) {
			echo '<p><strong>' . esc_html__( 'No matching attachments found.', 'leadwerk-importer' ) . '</strong></p>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: count */
				__( 'Listed: %d', 'leadwerk-importer' ),
				$total
			)
		) . '</p>';

		if ( $max_page > 1 ) {
			echo '<div class="tablenav top"><div class="tablenav-pages">';
			echo esc_html( sprintf( '%1$s / %2$s', (string) $paged, (string) $max_page ) );
			echo ' ';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( self::build_screen_url( $admin_script, array_merge( $url_base_args, array( 'paged' => $paged - 1 ) ) ) ) . '">' . esc_html__( 'Previous', 'leadwerk-importer' ) . '</a> ';
			}
			if ( $paged < $max_page ) {
				echo '<a class="button" href="' . esc_url( self::build_screen_url( $admin_script, array_merge( $url_base_args, array( 'paged' => $paged + 1 ) ) ) ) . '">' . esc_html__( 'Next', 'leadwerk-importer' ) . '</a>';
			}
			echo '</div></div>';
		}

		$confirm_js = $strict
			? esc_js( __( 'STRICT DELETE: Permanently remove selected files? This cannot be undone.', 'leadwerk-importer' ) )
			: esc_js( __( 'Permanently delete selected files from the server? This cannot be undone.', 'leadwerk-importer' ) );

		echo '<form method="post" action="' . esc_url( $form_url ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<input type="hidden" name="leadwerk_orphan_media_action" value="delete_selected" />';
		echo '<input type="hidden" name="leadwerk_orphan_media_mode" value="' . esc_attr( $strict ? self::MODE_STRICT_FIELDS : self::MODE_IMPORT ) . '" />';

		echo '<p><button type="submit" class="button button-secondary" onclick="return confirm(\'' . $confirm_js . '\');">' . esc_html__( 'Delete selected', 'leadwerk-importer' ) . '</button> ';
		echo '<button type="button" class="button" id="leadwerk-orphan-media-select-all">' . esc_html__( 'Select all', 'leadwerk-importer' ) . '</button> ';
		echo '<button type="button" class="button" id="leadwerk-orphan-media-select-none">' . esc_html__( 'Select none', 'leadwerk-importer' ) . '</button></p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:40px;"><span class="screen-reader-text">' . esc_html__( 'Select', 'leadwerk-importer' ) . '</span></th>';
		echo '<th>' . esc_html__( 'ID', 'leadwerk-importer' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'leadwerk-importer' ) . '</th>';
		echo '<th>' . esc_html__( 'leadwerk_source_path', 'leadwerk-importer' ) . '</th>';
		echo '<th>' . esc_html__( 'Uploaded file', 'leadwerk-importer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $slice as $aid ) {
			$post = get_post( $aid );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$src  = (string) get_post_meta( $aid, 'leadwerk_source_path', true );
			$file = get_attached_file( $aid );
			echo '<tr>';
			echo '<td><input class="leadwerk-orphan-media-cb" type="checkbox" name="leadwerk_orphan_attachment_ids[]" value="' . esc_attr( (string) $aid ) . '" /></td>';
			echo '<td>' . esc_html( (string) $aid ) . '</td>';
			echo '<td>' . esc_html( get_the_title( $post ) ) . '</td>';
			echo '<td><code>' . esc_html( '' !== $src ? $src : '—' ) . '</code></td>';
			echo '<td><code>' . esc_html( is_string( $file ) ? $file : '' ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p><button type="submit" class="button button-primary" onclick="return confirm(\'' . $confirm_js . '\');">' . esc_html__( 'Delete selected', 'leadwerk-importer' ) . '</button></p>';
		echo '</form>';

		echo '<script>';
		echo '(function(){';
		echo 'var cbs=document.querySelectorAll(".leadwerk-orphan-media-cb");';
		echo 'var allBtn=document.getElementById("leadwerk-orphan-media-select-all");';
		echo 'var noneBtn=document.getElementById("leadwerk-orphan-media-select-none");';
		echo 'if(allBtn){allBtn.addEventListener("click",function(){for(var i=0;i<cbs.length;i++){cbs[i].checked=true;}});}';
		echo 'if(noneBtn){noneBtn.addEventListener("click",function(){for(var i=0;i<cbs.length;i++){cbs[i].checked=false;}});}';
		echo '})();';
		echo '</script>';
	}
}
