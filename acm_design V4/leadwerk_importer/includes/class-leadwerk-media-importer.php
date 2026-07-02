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
		$mimes['webp']  = 'image/webp';
		$mimes['svg']   = 'image/svg+xml';
		$mimes['svgz']  = 'image/svg+xml';
		$mimes['ico']   = 'image/x-icon';
		$mimes['woff']  = 'font/woff';
		$mimes['woff2'] = 'font/woff2';
		$mimes['ttf']   = 'font/ttf';
		$mimes['eot']   = 'application/vnd.ms-fontobject';
		return $mimes;
	}

	/**
	 * True if the attachment points to a readable local file or an offloaded HTTP URL.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function attachment_resolves_for_import( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}
		$file = trim( $file );
		if ( preg_match( '#^https?://#i', $file ) ) {
			return true;
		}
		if ( file_exists( $file ) ) {
			return true;
		}
		$norm = wp_normalize_path( $file );
		if ( $norm !== $file && file_exists( $norm ) ) {
			return true;
		}
		$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( is_string( $rel ) && '' !== $rel ) {
			$uploads = wp_get_upload_dir();
			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$abs = path_join( $uploads['basedir'], $rel );
				if ( file_exists( $abs ) ) {
					return true;
				}
				$abs_norm = wp_normalize_path( $abs );
				if ( $abs_norm !== $abs && file_exists( $abs_norm ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function fix_mime_detection( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$map = array(
			'webp'  => 'image/webp',
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
		if ( ! $existing ) {
			$existing = $this->find_attachment_by_upload_file_path( $norm );
			if ( $existing && ! $this->dry_run ) {
				update_post_meta( $existing, 'leadwerk_source_path', $norm );
			}
		}
		if ( $existing ) {
			// Verify the physical file still exists on disk (or URL offload).
			if ( self::attachment_resolves_for_import( (int) $existing ) ) {
				$this->attachment_map[ $norm ] = $existing;
				Leadwerk_Logger::log( "Media bereits vorhanden: $relative_path => $existing" );
				return (int) $existing;
			}
			// Physical file missing — delete stale attachment record and re-import.
			if ( ! $this->dry_run ) {
				Leadwerk_Logger::log( "Media Datei fehlt auf Disk fuer Attachment $existing — loesche und importiere neu: $relative_path" );
				wp_delete_attachment( (int) $existing, true );
			}
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
		if ( ! $id ) {
			foreach ( $this->get_source_path_lookup_candidates( $norm ) as $candidate ) {
				if ( $candidate === $norm ) {
					continue;
				}
				if ( isset( $this->attachment_map[ $candidate ] ) ) {
					$id = (int) $this->attachment_map[ $candidate ];
				} else {
					$id = $this->find_attachment_by_source_path( $candidate );
					if ( $id ) {
						$this->attachment_map[ $candidate ] = $id;
					}
				}
				if ( $id ) {
					break;
				}
			}
		}
		if ( ! $id ) {
			foreach ( $this->get_source_path_lookup_candidates( $norm ) as $candidate ) {
				$id = $this->find_attachment_by_upload_file_path( $candidate );
				if ( $id ) {
					$this->attachment_map[ $candidate ] = $id;
					if ( ! $this->dry_run ) {
						update_post_meta( $id, 'leadwerk_source_path', $candidate );
					}
					break;
				}
			}
		}
		if ( $id ) {
			// Verify the physical file still exists — stale IDs from a wiped uploads folder must be ignored.
			if ( ! self::attachment_resolves_for_import( (int) $id ) ) {
				$attached_file = get_attached_file( (int) $id );
				Leadwerk_Logger::log( "Attachment $id hat keine Datei auf Disk — wird ignoriert fuer: $relative_path" );
				// #region agent log
				if ( function_exists( 'leadwerk_debug_ndjson' ) ) {
					leadwerk_debug_ndjson(
						array(
							'hypothesisId' => 'H2',
							'location'     => 'Leadwerk_Media_Importer::get_attachment_id_by_source',
							'message'      => 'db attachment rejected file missing',
							'data'         => array(
								'relative_path'   => $relative_path,
								'norm'            => $norm,
								'attachment_id'   => (int) $id,
								'attached_file'   => is_string( $attached_file ) ? substr( $attached_file, 0, 200 ) : '',
								'resolves_helper' => false,
							),
							'runId'        => 'post-fix',
						)
					);
				}
				// #endregion
				return 0;
			}
			$this->attachment_map[ $norm ] = $id;
		}
		return $id;
	}

	protected function normalize_path( $path ) {
		$path = str_replace( array( '\\', '//' ), array( '/', '/' ), $path );
		$path = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94" ), '-', $path );
		return trim( $path, '/' );
	}

	protected function get_source_path_lookup_candidates( $norm ) {
		$norm = $this->normalize_path( $norm );
		if ( '' === $norm ) {
			return array();
		}

		$candidates = array( $norm );
		$ext        = strtolower( pathinfo( $norm, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'webp', 'jpg', 'jpeg', 'png' ), true ) ) {
			$dir  = trim( str_replace( '\\', '/', pathinfo( $norm, PATHINFO_DIRNAME ) ), './' );
			$stem = pathinfo( $norm, PATHINFO_FILENAME );
			if ( '' !== $stem ) {
				foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $candidate_ext ) {
					$candidates[] = ( '' !== $dir ? $dir . '/' : '' ) . $stem . '.' . $candidate_ext;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( array( $this, 'normalize_path' ), $candidates ) ) ) );
	}

	protected function find_attachment_by_source_path( $norm ) {
		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'leadwerk_source_path' AND meta_value = %s LIMIT 1",
				$norm
			)
		);
		if ( $id > 0 ) {
			Leadwerk_Logger::log( "find_attachment_by_source_path: FOUND id=$id for '$norm'" );
		}
		return $id;
	}

	/**
	 * Find an attachment by its WordPress upload path when Leadwerk source meta is missing.
	 *
	 * @param string $norm Normalized source path.
	 * @return int Attachment ID.
	 */
	protected function find_attachment_by_upload_file_path( $norm ) {
		global $wpdb;
		$candidates = $this->get_upload_file_lookup_candidates( $norm );
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate || false === strpos( $candidate, '/' ) ) {
				continue;
			}
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$candidate
				)
			);
			if ( $id > 0 ) {
				return $id;
			}
		}

		$basename = wp_basename( $this->normalize_path( $norm ) );
		if ( '' === $basename ) {
			return 0;
		}

		$like = '%' . $wpdb->esc_like( $basename );
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 10",
				$like
			)
		);
		foreach ( $ids as $pid ) {
			$file = (string) get_post_meta( (int) $pid, '_wp_attached_file', true );
			if ( $basename === wp_basename( str_replace( '\\', '/', $file ) ) ) {
				return (int) $pid;
			}
		}

		return 0;
	}


	/**
	 * Build possible _wp_attached_file values for a source path.
	 *
	 * @param string $norm Normalized source path.
	 * @return string[]
	 */
	protected function get_upload_file_lookup_candidates( $norm ) {
		$norm       = $this->normalize_path( $norm );
		$candidates = array( $norm );

		if ( 0 === stripos( $norm, 'Fotos/uploads/' ) ) {
			$candidates[] = substr( $norm, strlen( 'Fotos/uploads/' ) );
		}
		if ( 0 === stripos( $norm, 'uploads/' ) ) {
			$candidates[] = substr( $norm, strlen( 'uploads/' ) );
		}
		if ( preg_match( '#(?:^|/)uploads/(\d{4}/\d{2}/.+)$#i', $norm, $m ) ) {
			$candidates[] = (string) $m[1];
		}

		return array_values( array_unique( array_filter( array_map( array( $this, 'normalize_path' ), $candidates ) ) ) );
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
