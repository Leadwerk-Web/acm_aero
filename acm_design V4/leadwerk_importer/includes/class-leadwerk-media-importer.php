<?php
/**
 * Medienimport: Dateien als Attachments anlegen, Deduplizierung über Pfad/Meta.
 *
 * @package Leadwerk_Importer
 */
class Leadwerk_Media_Importer {

	protected $source_root = '';
	protected $attachment_map = array();
	protected $dry_run = false;

	public function __construct( $source_root, $dry_run = false ) {
		$this->source_root = rtrim( $source_root, '/\\' );
		$this->dry_run     = $dry_run;
		add_filter( 'upload_mimes', array( $this, 'allow_extra_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_mime_detection' ), 10, 5 );
	}

	public function allow_extra_mimes( $mimes ) {
		$mimes['svg']   = 'image/svg+xml';
		$mimes['svgz']  = 'image/svg+xml';
		$mimes['ico']   = 'image/x-icon';
		$mimes['woff']  = 'font/woff';
		$mimes['woff2'] = 'font/woff2';
		$mimes['ttf']   = 'font/ttf';
		$mimes['eot']   = 'application/vnd.ms-fontobject';
		return $mimes;
	}

	public function fix_mime_detection( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$map = array(
			'svg'   => 'image/svg+xml',
			'svgz'  => 'image/svg+xml',
			'ico'   => 'image/x-icon',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'eot'   => 'application/vnd.ms-fontobject',
		);
		if ( isset( $map[ $ext ] ) ) {
			$data['ext']             = $ext;
			$data['type']            = $map[ $ext ];
			$data['proper_filename'] = false;
		}
		return $data;
	}

	/**
	 * Importiert eine Datei und gibt Attachment-ID zurück.
	 *
	 * @param string $relative_path Pfad relativ zu source_root.
	 * @return int 0 bei Fehler oder Dry-Run.
	 */
	public function import_file( $relative_path ) {
		$full_path = $this->source_root . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative_path );
		if ( ! is_file( $full_path ) ) {
			Leadwerk_Logger::log( "Media skip (missing): $relative_path" );
			return 0;
		}
		$norm = $this->normalize_path( $relative_path );
		if ( isset( $this->attachment_map[ $norm ] ) ) {
			return (int) $this->attachment_map[ $norm ];
		}
		$existing = $this->find_attachment_by_source_path( $norm );
		if ( $existing ) {
			$this->attachment_map[ $norm ] = $existing;
			Leadwerk_Logger::log( "Media bereits vorhanden: $relative_path => $existing" );
			return (int) $existing;
		}
		if ( $this->dry_run ) {
			Leadwerk_Logger::log( "Media would import: $relative_path" );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( wp_basename( $full_path ) );
		copy( $full_path, $tmp );
		$file_array = array(
			'name'     => wp_basename( $full_path ),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file_array, 0, null );
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			Leadwerk_Logger::log( "Media error $relative_path: " . $id->get_error_message() );
			return 0;
		}
		$this->ensure_unique_attachment_slug( (int) $id );
		update_post_meta( $id, 'leadwerk_source_path', $norm );
		$this->attachment_map[ $norm ] = $id;
		Leadwerk_Logger::log( "Media imported: $relative_path => $id" );
		return (int) $id;
	}

	public function get_attachment_id_by_source( $relative_path ) {
		$norm = $this->normalize_path( $relative_path );
		if ( isset( $this->attachment_map[ $norm ] ) ) {
			return (int) $this->attachment_map[ $norm ];
		}
		$id = $this->find_attachment_by_source_path( $norm );
		if ( $id ) {
			$this->attachment_map[ $norm ] = $id;
		}
		return $id;
	}

	protected function normalize_path( $path ) {
		$path = str_replace( array( '\\', '//' ), array( '/', '/' ), $path );
		$path = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94" ), '-', $path );
		return trim( $path, '/' );
	}

	protected function find_attachment_by_source_path( $norm ) {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'meta_key'       => 'leadwerk_source_path',
			'meta_value'     => $norm,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		) );
		$ids = $q->get_posts();
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Re-run slug uniquify for all Leadwerk-imported attachments (after pages exist).
	 *
	 * @return int[] Attachment IDs whose post_name was changed.
	 */
	public function repair_all_imported_attachment_slugs() {
		if ( $this->dry_run ) {
			return array();
		}
		$q = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => 'leadwerk_source_path',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$changed = array();
		foreach ( $q->posts as $aid ) {
			$aid = (int) $aid;
			if ( $aid < 1 ) {
				continue;
			}
			if ( $this->ensure_unique_attachment_slug( $aid ) ) {
				$changed[] = $aid;
			}
		}
		return $changed;
	}

	/**
	 * Set attachment post_name distinct from pages/CPTs (same wp_posts slug space).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True if post_name was updated.
	 */
	protected function ensure_unique_attachment_slug( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return false;
		}
		$base = (string) $post->post_name;
		if ( '' === $base ) {
			return false;
		}
		$status = $post->post_status ?: 'inherit';
		$unique = wp_unique_post_slug( $base, $attachment_id, $status, 'attachment', (int) $post->post_parent );
		if ( $unique === $post->post_name ) {
			return false;
		}
		wp_update_post(
			array(
				'ID'        => $attachment_id,
				'post_name' => $unique,
			)
		);
		clean_post_cache( $attachment_id );
		return true;
	}
}
