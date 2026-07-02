<?php
/**
 * After JPEG/PNG upload: convert to WebP on disk; same attachment ID when converting post-insert (REST etc.).
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primary: wp_handle_upload (file is WebP before attachment row). Fallback: wp_generate_attachment_metadata.
 */
class Leadwerk_Auto_Webp_On_Upload {

	/**
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'filter_handle_upload' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'filter_generate_attachment_metadata' ), 999, 2 );
	}

	/**
	 * @param array<string,mixed> $upload  file, url, type, error.
	 * @param string              $context upload|sideload.
	 * @return array<string,mixed>
	 */
	public static function filter_handle_upload( $upload, $context ) {
		if ( self::$busy ) {
			return $upload;
		}
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}
		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return $upload;
		}
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return $upload;
		}
		if ( ! self::context_allows_auto_webp( 'handle_upload', 0, $upload ) ) {
			return $upload;
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $upload;
		}

		$file = (string) $upload['file'];
		if ( ! is_file( $file ) ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$dir       = dirname( $file );
		$stem      = pathinfo( wp_basename( $file ), PATHINFO_FILENAME );
		$webp_name = wp_unique_filename( $dir, $stem . '.webp' );
		$webp_abs  = trailingslashit( $dir ) . $webp_name;

		if ( is_file( $webp_abs ) ) {
			wp_delete_file( $webp_abs );
		}

		self::add_editor_quality_filter();
		$saved = $editor->save( $webp_abs, 'image/webp' );
		self::remove_editor_quality_filter();

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) || filesize( $saved['path'] ) < 1 ) {
			return $upload;
		}

		$webp_abs = $saved['path'];
		wp_delete_file( $file );

		$upload['file'] = $webp_abs;
		$upload['type'] = 'image/webp';
		$base_old       = wp_basename( $file );
		$base_new       = wp_basename( $webp_abs );
		if ( isset( $upload['url'] ) && is_string( $upload['url'] ) && '' !== $upload['url'] ) {
			$upload['url'] = self::replace_url_basename( (string) $upload['url'], $base_old, $base_new );
		}

		return $upload;
	}

	/**
	 * REST and other flows: after metadata is generated for JPEG/PNG, swap file to WebP and regenerate metadata once.
	 *
	 * @param array<string,mixed> $metadata        Metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function filter_generate_attachment_metadata( $metadata, $attachment_id ) {
		if ( self::$busy ) {
			return is_array( $metadata ) ? $metadata : array();
		}
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return is_array( $metadata ) ? $metadata : array();
		}
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return is_array( $metadata ) ? $metadata : array();
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return is_array( $metadata ) ? $metadata : array();
		}
		if ( ! self::context_allows_auto_webp( 'generate_metadata', $attachment_id, null ) ) {
			return is_array( $metadata ) ? $metadata : array();
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return is_array( $metadata ) ? $metadata : array();
		}

		self::$busy = true;
		try {
			if ( ! self::replace_attachment_file_with_webp_only( $attachment_id ) ) {
				return is_array( $metadata ) ? $metadata : array();
			}
			$new_path = get_attached_file( $attachment_id );
			if ( ! $new_path || ! is_file( $new_path ) ) {
				return is_array( $metadata ) ? $metadata : array();
			}
		} finally {
			self::$busy = false;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		return wp_generate_attachment_metadata( $attachment_id, $new_path );
	}

	/**
	 * JPEG/PNG → WebP on disk; update DB paths/mime; delete original; no wp_generate_attachment_metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected static function replace_attachment_file_with_webp_only( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$path          = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$dir       = dirname( $path );
		$stem      = pathinfo( wp_basename( $path ), PATHINFO_FILENAME );
		$webp_name = wp_unique_filename( $dir, $stem . '.webp' );
		$webp_abs  = trailingslashit( $dir ) . $webp_name;

		if ( is_file( $webp_abs ) ) {
			wp_delete_file( $webp_abs );
		}

		self::add_editor_quality_filter();
		$saved = $editor->save( $webp_abs, 'image/webp' );
		self::remove_editor_quality_filter();

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) || filesize( $saved['path'] ) < 1 ) {
			return false;
		}

		$webp_abs = $saved['path'];
		wp_delete_file( $path );

		$rel = _wp_get_relative_upload_path( $webp_abs );
		if ( ! is_string( $rel ) || '' === $rel ) {
			$uploads = wp_get_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return false;
			}
			$rel = ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ), '', wp_normalize_path( $webp_abs ) ), '/' );
		}

		update_attached_file( $attachment_id, $rel );

		$uploads = wp_get_upload_dir();
		$new_url = trailingslashit( $uploads['baseurl'] ) . str_replace( '\\', '/', $rel );

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
				'guid'           => $new_url,
			)
		);

		update_post_meta( $attachment_id, '_leadwerk_auto_webp_upload', '1' );

		return true;
	}

	/**
	 * Full cycle (file + metadata) for external callers.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function convert_attachment_to_webp_inplace( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		if ( ! self::replace_attachment_file_with_webp_only( $attachment_id ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return true;
	}

	/**
	 * @param string               $where     handle_upload|generate_metadata.
	 * @param int                  $attach_id 0 if unknown.
	 * @param array<string,mixed>|null $upload Upload row for handle_upload.
	 * @return bool
	 */
	protected static function context_allows_auto_webp( $where, $attach_id, $upload ) {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		/**
		 * Enable automatic WebP conversion right after JPEG/PNG upload.
		 *
		 * @param bool                 $enable    Default true (except WP_IMPORTING, checked earlier).
		 * @param int                  $attach_id Attachment ID or 0 during wp_handle_upload.
		 * @param string               $where     handle_upload|generate_metadata.
		 * @param array<string,mixed>|null $upload  Upload array when $where is handle_upload.
		 */
		return (bool) apply_filters( 'leadwerk_auto_webp_on_upload_enable', true, $attach_id, $where, $upload );
	}

	/**
	 * @param string $url      Full URL to old file.
	 * @param string $old_base Old basename.
	 * @param string $new_base New basename.
	 * @return string
	 */
	protected static function replace_url_basename( $url, $old_base, $new_base ) {
		$pos = strrpos( $url, $old_base );
		if ( false !== $pos && $pos === strlen( $url ) - strlen( $old_base ) ) {
			return substr_replace( $url, $new_base, $pos, strlen( $old_base ) );
		}
		$dir = dirname( $url );
		return ( '/' === $dir || '\\' === $dir ? '' : $dir ) . '/' . $new_base;
	}

	/**
	 * @return void
	 */
	protected static function add_editor_quality_filter() {
		if ( class_exists( 'Leadwerk_Webp_Body_Tool' ) ) {
			add_filter( 'wp_editor_set_quality', array( 'Leadwerk_Webp_Body_Tool', 'filter_webp_editor_quality' ), 10, 2 );
		}
	}

	/**
	 * @return void
	 */
	protected static function remove_editor_quality_filter() {
		if ( class_exists( 'Leadwerk_Webp_Body_Tool' ) ) {
			remove_filter( 'wp_editor_set_quality', array( 'Leadwerk_Webp_Body_Tool', 'filter_webp_editor_quality' ), 10 );
		}
	}
}
