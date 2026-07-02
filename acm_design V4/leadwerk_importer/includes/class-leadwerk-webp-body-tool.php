<?php
/**
 * Admin tool: WebP conversion for images in post_content, featured images, and Leadwerk schema fields.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan, dry-run, convert JPG/PNG to WebP, update post_content and _thumbnail_id; optional Leadwerk-Feld-Meta-Remap; optional safe delete.
 */
class Leadwerk_Webp_Body_Tool {

	const OPTION_MANIFEST = 'leadwerk_webp_body_manifest';
	const OPTION_LOG      = 'leadwerk_webp_body_log';
	const OPTION_JOB      = 'leadwerk_webp_body_job_state';
	const OPTION_FIELD_JOB = 'leadwerk_webp_field_remap_job';

	const NONCE_AJAX = 'leadwerk_webp_body_ajax';

	const WEBP_QUALITY = 85;

	const BATCH_ATTACHMENTS = 2;

	/**
	 * @param int    $quality Default quality.
	 * @param string $mime    Mime type.
	 * @return int
	 */
	public static function filter_webp_editor_quality( $quality, $mime ) {
		if ( 'image/webp' === $mime ) {
			return (int) self::WEBP_QUALITY;
		}
		return (int) $quality;
	}

	/**
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_state', array( __CLASS__, 'ajax_state' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'admin_post_leadwerk_webp_body_delete_sync', array( __CLASS__, 'handle_delete_sync' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_field_remap_init', array( __CLASS__, 'ajax_field_remap_init' ) );
		add_action( 'wp_ajax_leadwerk_webp_body_field_remap_step', array( __CLASS__, 'ajax_field_remap_step' ) );
	}

	/**
	 * @return string[]
	 */
	public static function get_post_types() {
		$types = array( 'page', 'acm_news' );
		if ( class_exists( 'Leadwerk_Orphan_Media_Admin' ) && method_exists( 'Leadwerk_Orphan_Media_Admin', 'get_scoped_post_types_public' ) ) {
			$types = Leadwerk_Orphan_Media_Admin::get_scoped_post_types_public();
		}

		/**
		 * Post types whose post_content is scanned and updated.
		 *
		 * @param string[] $types Post type names.
		 */
		return apply_filters( 'leadwerk_webp_body_post_types', $types );
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Leadwerk WebP (Body)', 'leadwerk-importer' ),
			__( 'Leadwerk WebP (Body)', 'leadwerk-importer' ),
			'manage_options',
			'leadwerk-webp-body',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'tools_page_leadwerk-webp-body' !== $hook ) {
			return;
		}

		$script_path = LEADWERK_IMPORTER_PATH . 'assets/admin-webp-body.js';
		if ( is_file( $script_path ) ) {
			wp_enqueue_script(
				'leadwerk-webp-body-admin',
				LEADWERK_IMPORTER_URL . 'assets/admin-webp-body.js',
				array(),
				(string) filemtime( $script_path ),
				true
			);
			wp_localize_script(
				'leadwerk-webp-body-admin',
				'leadwerkWebpBody',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
					'strings' => array(
						'done'     => __( 'Fertig.', 'leadwerk-importer' ),
						'error'    => __( 'Fehler:', 'leadwerk-importer' ),
						'progress' => __( 'Fortschritt…', 'leadwerk-importer' ),
					),
				)
			);
		}
	}

	/**
	 * @param string $message Message.
	 * @param string $level   info|warning|error.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$line        = '[' . $timestamp . '] [' . strtoupper( $level ) . '] ' . trim( (string) $message ) . "\n";
		$prev        = (string) get_option( self::OPTION_LOG, '' );
		update_option( self::OPTION_LOG, $prev . $line, false );
	}

	/**
	 * @return void
	 */
	public static function clear_log() {
		update_option( self::OPTION_LOG, '', false );
	}

	/**
	 * @return void
	 */
	protected static function maybe_raise_limits() {
		if ( ! apply_filters( 'leadwerk_webp_body_raise_php_limits', true ) ) {
			return;
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$mem = apply_filters( 'leadwerk_webp_body_memory_limit', apply_filters( 'leadwerk_import_memory_limit', '512M' ) );
		if ( is_string( $mem ) && '' !== $mem ) {
			@ini_set( 'memory_limit', $mem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$time_limit = (int) apply_filters( 'leadwerk_webp_body_time_limit', apply_filters( 'leadwerk_import_time_limit', 300 ) );
		if ( $time_limit > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $time_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @return void
	 */
	protected static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
	}

	/**
	 * @return void
	 */
	public static function ajax_state() {
		self::verify_ajax();
		wp_send_json_success( array( 'job' => get_option( self::OPTION_JOB, array() ) ) );
	}

	/**
	 * @return void
	 */
	public static function ajax_start() {
		self::verify_ajax();
		self::maybe_raise_limits();

		$dry_run = ! empty( $_POST['dry_run'] );

		self::clear_log();
		delete_option( self::OPTION_JOB );

		self::log( $dry_run ? 'Dry-Run gestartet.' : 'Live-Lauf gestartet (Stapel-Verarbeitung).' );

		$body = self::scan_attachments_in_body();
		if ( is_wp_error( $body ) ) {
			self::log( $body->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $body->get_error_message() ) );
		}

		$field = self::scan_attachments_in_leadwerk_fields();
		if ( is_wp_error( $field ) ) {
			self::log( 'Leadwerk-Feld-Scan: ' . $field->get_error_message(), 'warning' );
			$field = array(
				'entries' => array(),
				'stats'   => array(
					'posts_scanned_fields'  => 0,
					'field_attachment_refs' => 0,
					'options_scanned'       => 0,
				),
			);
		}

		$thumb = self::scan_attachments_in_featured_thumbnails();

		$scan = self::merge_webp_scan_results( $body, $field, $thumb );

		$manifest = array(
			'created_at' => gmdate( 'c' ),
			'dry_run'    => $dry_run,
			'entries'    => $scan['entries'],
			'stats'      => $scan['stats'],
		);
		update_option( self::OPTION_MANIFEST, $manifest, false );

		self::log(
			sprintf(
				'Scan: Body %1$d Posts / %2$d Body-Zuordnungen | Leadwerk-Felder %3$d Posts / %4$d Feld-Refs / %5$d Optionen | Beitragsbilder %6$d Posts / %7$d Thumb-Refs | gesamt %8$d JPEG/PNG-Attachments.',
				(int) ( $scan['stats']['posts_scanned_body'] ?? 0 ),
				(int) ( $scan['stats']['body_pairs'] ?? 0 ),
				(int) ( $scan['stats']['posts_scanned_fields'] ?? 0 ),
				(int) ( $scan['stats']['field_attachment_refs'] ?? 0 ),
				(int) ( $scan['stats']['options_scanned'] ?? 0 ),
				(int) ( $scan['stats']['posts_with_jpeg_png_featured'] ?? 0 ),
				(int) ( $scan['stats']['thumbnail_pairs'] ?? 0 ),
				(int) ( $scan['stats']['attachments_found'] ?? 0 )
			)
		);

		if ( $dry_run ) {
			foreach ( array_slice( $scan['entries'], 0, 50 ) as $e ) {
				self::log(
					sprintf(
						'[DRY] Attachment %1$d (%2$s) -> WebP, betrifft Posts: %3$s',
						(int) $e['old_attachment_id'],
						isset( $e['sample_old_url'] ) ? $e['sample_old_url'] : '',
						implode( ',', array_map( 'strval', $e['post_ids'] ) )
					)
				);
			}
			if ( count( $scan['entries'] ) > 50 ) {
				self::log( '… weitere Eintraege ausgelassen (Log-Kuerze).' );
			}
			$job = array(
				'status'   => 'completed',
				'dry_run'  => true,
				'message'  => 'Dry-Run abgeschlossen.',
				'stats'    => $scan['stats'],
				'finished' => gmdate( 'c' ),
			);
			update_option( self::OPTION_JOB, $job, false );
			wp_send_json_success( array( 'job' => $job ) );
			return;
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			self::log( 'Server unterstuetzt kein WebP-Schreiben (GD/Imagick). Abbruch.', 'error' );
			$job = array(
				'status'  => 'failed',
				'message' => __( 'WebP wird von diesem PHP-Image-Stack nicht unterstuetzt.', 'leadwerk-importer' ),
			);
			update_option( self::OPTION_JOB, $job, false );
			wp_send_json_error( array( 'message' => $job['message'], 'job' => $job ) );
		}

		$queue = array_values(
			array_filter(
				array_map(
					static function ( $e ) {
						return (int) ( $e['old_attachment_id'] ?? 0 );
					},
					$scan['entries']
				),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);

		$by_id = self::index_entries_by_old_id( $scan['entries'] );

		if ( empty( $queue ) ) {
			$job = array(
				'status'    => 'completed',
				'dry_run'   => false,
				'queue'     => array(),
				'cursor'    => 0,
				'converted' => array(),
				'failed'    => array(),
				'stats'     => $scan['stats'],
				'started'   => gmdate( 'c' ),
				'finished'  => gmdate( 'c' ),
				'message'   => __( 'Keine JPEG/PNG-Attachments im Body oder in Leadwerk-Feldern gefunden.', 'leadwerk-importer' ),
			);
			update_option( self::OPTION_JOB, $job, false );
			self::persist_manifest_from_job( $job, $by_id );
			wp_send_json_success( array( 'job' => $job ) );
			return;
		}

		$job = array(
			'status'        => 'running',
			'dry_run'       => false,
			'queue'         => $queue,
			'cursor'        => 0,
			'converted'     => array(),
			'failed'        => array(),
			'stats'         => $scan['stats'],
			'started'       => gmdate( 'c' ),
			'entries_by_id' => $by_id,
		);
		update_option( self::OPTION_JOB, $job, false );

		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function index_entries_by_old_id( array $entries ) {
		$out = array();
		foreach ( $entries as $e ) {
			$id = (int) ( $e['old_attachment_id'] ?? 0 );
			if ( $id > 0 ) {
				$out[ $id ] = $e;
			}
		}
		return $out;
	}

	/**
	 * @param array<int|string,mixed> $by_id Raw map from option/job.
	 * @return array<int,mixed>
	 */
	protected static function normalize_entries_by_id_keys( array $by_id ) {
		$out = array();
		foreach ( $by_id as $k => $v ) {
			$id = (int) $k;
			if ( $id > 0 && is_array( $v ) ) {
				$out[ $id ] = $v;
			}
		}
		return $out;
	}

	/**
	 * @return void
	 */
	public static function ajax_step() {
		self::verify_ajax();
		self::maybe_raise_limits();

		$job = get_option( self::OPTION_JOB, array() );
		if ( empty( $job['queue'] ) || ! is_array( $job['queue'] ) ) {
			wp_send_json_error( array( 'message' => 'Kein aktiver Job.' ), 400 );
		}

		if ( 'completed' === (string) ( $job['status'] ?? '' ) || 'failed' === (string) ( $job['status'] ?? '' ) ) {
			wp_send_json_success( array( 'job' => $job ) );
			return;
		}

		$queue  = $job['queue'];
		$cursor = (int) ( $job['cursor'] ?? 0 );
		$batch  = (int) apply_filters( 'leadwerk_webp_body_batch_size', self::BATCH_ATTACHMENTS );
		if ( $batch < 1 ) {
			$batch = 1;
		}

		$by_id = isset( $job['entries_by_id'] ) && is_array( $job['entries_by_id'] ) ? self::normalize_entries_by_id_keys( $job['entries_by_id'] ) : array();

		$processed = 0;
		while ( $processed < $batch && $cursor < count( $queue ) ) {
			$old_id = (int) $queue[ $cursor ];
			++$cursor;
			++$processed;

			$entry = isset( $by_id[ $old_id ] ) ? $by_id[ $old_id ] : null;
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( ! apply_filters( 'leadwerk_webp_body_should_convert_attachment', true, $old_id, $entry ) ) {
				self::log( "Attachment $old_id uebersprungen (Filter)." );
				continue;
			}

			$result = self::convert_one_attachment_and_replace_posts( $old_id, $entry );
			if ( is_wp_error( $result ) ) {
				self::log( $result->get_error_message(), 'error' );
				$job['failed'][] = array(
					'old_attachment_id' => $old_id,
					'error'             => $result->get_error_message(),
				);
			} else {
				$job['converted'][] = $result;
				$by_id[ $old_id ]['new_attachment_id'] = (int) $result['new_attachment_id'];
				self::log(
					sprintf(
						'OK: Attachment %1$d -> %2$d (WebP), Posts aktualisiert: %3$d.',
						$old_id,
						(int) $result['new_attachment_id'],
						(int) $result['posts_updated']
					)
				);
			}
		}

		$job['cursor']        = $cursor;
		$job['entries_by_id'] = $by_id;

		if ( $cursor >= count( $queue ) ) {
			$job['status']    = 'completed';
			$job['finished']  = gmdate( 'c' );
			$job['message']  = __( 'Konvertierung abgeschlossen.', 'leadwerk-importer' );
			self::persist_manifest_from_job( $job, $by_id );
		}

		update_option( self::OPTION_JOB, $job, false );
		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * @param array<string,mixed> $job     Job state.
	 * @param array<int,array>    $by_id   Entries by old id.
	 * @return void
	 */
	protected static function persist_manifest_from_job( array $job, array $by_id ) {
		$manifest = get_option( self::OPTION_MANIFEST, array() );
		if ( ! is_array( $manifest ) ) {
			$manifest = array();
		}
		$manifest['updated_at'] = gmdate( 'c' );
		$manifest['dry_run']    = false;
		$by_id                  = self::normalize_entries_by_id_keys( $by_id );
		ksort( $by_id );
		$manifest['entries'] = array_values( $by_id );
		update_option( self::OPTION_MANIFEST, $manifest, false );
	}

	/**
	 * @param int                  $old_id Old attachment ID.
	 * @param array<string,mixed> $entry  Scan entry.
	 * @return array<string,mixed>|WP_Error
	 */
	protected static function convert_one_attachment_and_replace_posts( $old_id, array $entry ) {
		$old_id = (int) $old_id;
		$post   = get_post( $old_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'invalid', 'Kein gueltiges Attachment: ' . $old_id );
		}

		$mime = (string) $post->post_mime_type;
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'mime', 'Kein JPEG/PNG: ' . $old_id );
		}

		$old_path = get_attached_file( $old_id );
		if ( ! $old_path || ! is_file( $old_path ) ) {
			return new WP_Error( 'file', 'Datei fehlt fuer Attachment ' . $old_id );
		}

		$editor = wp_get_image_editor( $old_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$dir      = dirname( $old_path );
		$basename = wp_basename( $old_path );
		$stem     = pathinfo( $basename, PATHINFO_FILENAME );
		$webp_abs = $dir . DIRECTORY_SEPARATOR . $stem . '.webp';

		if ( is_file( $webp_abs ) ) {
			@unlink( $webp_abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_webp_editor_quality' ), 10, 2 );

		$saved = $editor->save( $webp_abs, 'image/webp' );

		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_webp_editor_quality' ), 10 );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( empty( $saved['path'] ) || ! is_file( $saved['path'] ) || filesize( $saved['path'] ) < 1 ) {
			return new WP_Error( 'save', 'WebP-Speichern fehlgeschlagen.' );
		}

		$webp_abs = $saved['path'];

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$new_id = self::insert_webp_attachment( $webp_abs, $old_id );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$metadata = wp_generate_attachment_metadata( $new_id, $webp_abs );
		wp_update_attachment_metadata( $new_id, $metadata );

		$post_ids = isset( $entry['post_ids'] ) && is_array( $entry['post_ids'] ) ? array_map( 'intval', $entry['post_ids'] ) : array();
		$updated  = 0;
		foreach ( $post_ids as $pid ) {
			if ( $pid < 1 ) {
				continue;
			}
			$p = get_post( $pid );
			if ( ! $p ) {
				continue;
			}
			$new_content = self::replace_urls_in_html( $p->post_content, $old_id, $new_id );
			$new_content = apply_filters( 'leadwerk_webp_body_post_content_replace', $new_content, $pid, $old_id, $new_id );
			$changed     = false;
			if ( $new_content !== $p->post_content ) {
				wp_update_post(
					array(
						'ID'           => $pid,
						'post_content' => $new_content,
					)
				);
				$changed = true;
			}
			if ( (int) get_post_thumbnail_id( $pid ) === $old_id ) {
				set_post_thumbnail( $pid, $new_id );
				$changed = true;
			}
			if ( $changed ) {
				++$updated;
			}
		}

		return array(
			'old_attachment_id' => $old_id,
			'new_attachment_id' => (int) $new_id,
			'posts_updated'     => $updated,
		);
	}

	/**
	 * @param string $webp_abs Absolute path to .webp file.
	 * @param int    $old_id   Source attachment (for title/parent hint).
	 * @return int|WP_Error
	 */
	protected static function insert_webp_attachment( $webp_abs, $old_id ) {
		$filename  = wp_basename( $webp_abs );
		$filetype  = wp_check_filetype( $filename, null );
		$title     = preg_replace( '/\.[^.]+$/', '', $filename );

		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/webp',
			'post_title'     => sanitize_file_name( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => 0,
		);

		$new_id = wp_insert_attachment( $attachment, $webp_abs, 0, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		update_post_meta( (int) $new_id, '_leadwerk_webp_converted_from', (int) $old_id );

		return (int) $new_id;
	}

	/**
	 * @param string $html   post_content.
	 * @param int    $old_id Old attachment.
	 * @param int    $new_id New attachment.
	 * @return string
	 */
	protected static function replace_urls_in_html( $html, $old_id, $new_id ) {
		$old_urls = self::collect_attachment_urls( $old_id );
		$new_urls = self::collect_attachment_urls( $new_id );

		$pairs = self::build_url_pairs_longest_first( $old_urls, $new_urls );
		$out   = (string) $html;
		foreach ( $pairs as $pair ) {
			$out = str_replace( $pair[0], $pair[1], $out );
		}

		$out = str_replace( 'wp-image-' . (int) $old_id, 'wp-image-' . (int) $new_id, $out );

		$old_s = (string) (int) $old_id;
		$new_s = (string) (int) $new_id;
		$out    = (string) preg_replace( '/"id"\s*:\s*' . preg_quote( $old_s, '/' ) . '(\s*[,}\]])/', '"id":' . $new_s . '$1', $out );

		return $out;
	}

	/**
	 * @param string[] $old_urls Old absolute URLs.
	 * @param string[] $new_urls New absolute URLs.
	 * @return array<int,array{0:string,1:string}>
	 */
	protected static function build_url_pairs_longest_first( array $old_urls, array $new_urls ) {
		$new_by_stem = array();
		foreach ( $new_urls as $u ) {
			$stem = self::url_stem_key( $u );
			if ( '' !== $stem ) {
				$new_by_stem[ $stem ] = $u;
			}
		}

		$pairs = array();
		foreach ( $old_urls as $old ) {
			$stem = self::url_stem_key( $old );
			if ( '' === $stem ) {
				continue;
			}
			$new = isset( $new_by_stem[ $stem ] ) ? $new_by_stem[ $stem ] : '';
			if ( '' === $new ) {
				$new_guess = preg_replace( '#\.(jpe?g|png)$#i', '.webp', $old );
				if ( $new_guess && in_array( $new_guess, $new_urls, true ) ) {
					$new = $new_guess;
				}
			}
			if ( '' === $new ) {
				continue;
			}
			foreach ( self::url_replacement_variants( $old, $new ) as $pair ) {
				$pairs[] = $pair;
			}
		}

		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) <=> strlen( $a[0] );
			}
		);

		$seen = array();
		$out  = array();
		foreach ( $pairs as $p ) {
			$key = $p[0] . "\0" . $p[1];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $p;
		}
		return $out;
	}

	/**
	 * Filename without extension as lookup key (handles size suffixes).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function url_stem_key( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = wp_basename( $path );
		return (string) preg_replace( '/\.[^.]+$/', '', $base );
	}

	/**
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return array<int,array{0:string,1:string}>
	 */
	protected static function url_replacement_variants( $old_url, $new_url ) {
		$old_url = (string) $old_url;
		$new_url = (string) $new_url;
		$pairs   = array();
		$pairs[] = array( $old_url, $new_url );

		$home = home_url( '/' );
		$rel_old = str_replace( $home, '', $old_url );
		$rel_new = str_replace( $home, '', $new_url );
		if ( $rel_old !== $old_url ) {
			$pairs[] = array( $rel_old, $rel_new );
			$pairs[] = array( './' . $rel_old, './' . $rel_new );
		}

		$pairs[] = array( str_replace( ' ', '%20', $old_url ), str_replace( ' ', '%20', $new_url ) );

		$old_name = wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) );
		$new_name = wp_basename( (string) wp_parse_url( $new_url, PHP_URL_PATH ) );
		if ( '' !== $old_name && '' !== $new_name ) {
			$pairs[] = array( $old_name, $new_name );
			$pairs[] = array( str_replace( ' ', '%20', $old_name ), str_replace( ' ', '%20', $new_name ) );
		}

		$pairs[] = array( str_replace( '/', '\\', $old_url ), str_replace( '/', '\\', $new_url ) );

		$site = site_url( '/' );
		if ( $site !== $home ) {
			$rel2_old = str_replace( $site, '', $old_url );
			$rel2_new = str_replace( $site, '', $new_url );
			if ( $rel2_old !== $old_url ) {
				$pairs[] = array( $rel2_old, $rel2_new );
			}
		}

		return $pairs;
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public static function collect_attachment_urls( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$urls          = array();

		$main = wp_get_attachment_url( $attachment_id );
		if ( $main ) {
			$urls[] = $main;
			$scheme = wp_parse_url( $main, PHP_URL_SCHEME );
			if ( $scheme ) {
				$noscheme = preg_replace( '#^https?:#', '', $main );
				if ( is_string( $noscheme ) && '' !== $noscheme ) {
					$urls[] = $noscheme;
				}
			}
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $main ) {
			$dir_url = trailingslashit( dirname( $main ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$u = $dir_url . $size['file'];
					$urls[] = $u;
					$noscheme = preg_replace( '#^https?:#', '', $u );
					if ( is_string( $noscheme ) && '' !== $noscheme ) {
						$urls[] = $noscheme;
					}
				}
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		usort(
			$urls,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return $urls;
	}

	/**
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	protected static function attachment_is_jpeg_or_png( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$mime = get_post_mime_type( $attachment_id );
		return in_array( (string) $mime, array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * @param mixed                $value      Field value.
	 * @param array<string,mixed>  $definition Field definition.
	 * @param array<int,bool>      $id_set     Output: attachment ID => true.
	 * @return void
	 */
	protected static function collect_jpeg_png_ids_from_field_value( $value, array $definition, array &$id_set ) {
		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'image':
				$id = is_numeric( $value ) ? (int) $value : 0;
				if ( $id > 0 && self::attachment_is_jpeg_or_png( $id ) ) {
					$id_set[ $id ] = true;
				}
				return;

			case 'video':
			case 'file':
				if ( is_numeric( $value ) ) {
					$id = (int) $value;
					if ( $id > 0 && self::attachment_is_jpeg_or_png( $id ) ) {
						$id_set[ $id ] = true;
					}
				}
				return;

			case 'repeater':
				if ( ! is_array( $value ) ) {
					return;
				}
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( (array) ( $definition['fields'] ?? array() ) as $sk => $sdef ) {
						self::collect_jpeg_png_ids_from_field_value( $row[ $sk ] ?? null, $sdef, $id_set );
					}
				}
				return;

			case 'wysiwyg':
			case 'classic_editor':
			case 'html':
			case 'heading_html':
				if ( ! is_string( $value ) || '' === $value ) {
					return;
				}
				$upload = wp_upload_dir();
				if ( ! empty( $upload['error'] ) ) {
					return;
				}
				$base = trailingslashit( $upload['baseurl'] );
				foreach ( self::extract_jpeg_png_attachment_ids_from_content( $value, $base ) as $aid ) {
					$id_set[ (int) $aid ] = true;
				}
				return;

			default:
				return;
		}
	}

	/**
	 * @param mixed                $value Group or flexible value.
	 * @param array<string,mixed>  $group Schema group.
	 * @param array<int,bool>      $id_set Output.
	 * @return void
	 */
	protected static function collect_jpeg_png_ids_from_group_value( $value, array $group, array &$id_set ) {
		if ( empty( $group['layouts'] ) ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( (array) ( $group['fields'] ?? array() ) as $key => $def ) {
				if ( array_key_exists( $key, $value ) ) {
					self::collect_jpeg_png_ids_from_field_value( $value[ $key ], $def, $id_set );
				}
			}
			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout = isset( $row['acf_fc_layout'] ) ? sanitize_key( (string) $row['acf_fc_layout'] ) : '';
			if ( '' === $layout || empty( $group['layouts'][ $layout ]['fields'] ) ) {
				continue;
			}
			$layout_schema = $group['layouts'][ $layout ];
			foreach ( (array) $layout_schema['fields'] as $fk => $fdef ) {
				if ( array_key_exists( $fk, $row ) ) {
					self::collect_jpeg_png_ids_from_field_value( $row[ $fk ], $fdef, $id_set );
				}
			}
		}
	}

	/**
	 * JPEG/PNG-Anhaenge aus Leadwerk_Content_Schema (post_meta + leadwerk_opt_*).
	 *
	 * @return array{entries:array<int,array<string,mixed>>,stats:array<string,int>}|WP_Error
	 */
	protected static function scan_attachments_in_leadwerk_fields() {
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) || ! class_exists( 'Leadwerk_Fields_API' ) ) {
			return new WP_Error( 'leadwerk', 'Leadwerk_Content_Schema oder Leadwerk_Fields_API fehlt.' );
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload', (string) $upload['error'] );
		}

		$groups = Leadwerk_Content_Schema::get_groups();
		if ( empty( $groups ) || ! is_array( $groups ) ) {
			return array(
				'entries' => array(),
				'stats'   => array(
					'posts_scanned_fields'  => 0,
					'field_attachment_refs' => 0,
					'options_scanned'       => 0,
				),
			);
		}

		$by_attachment = array();
		$stats         = array(
			'posts_scanned_fields'  => 0,
			'field_attachment_refs' => 0,
			'options_scanned'       => 0,
		);

		foreach ( self::collect_scoped_post_ids() as $pid ) {
			$had_meta = false;
			foreach ( $groups as $field_name => $group ) {
				if ( ! is_string( $field_name ) || '' === $field_name || ! is_array( $group ) ) {
					continue;
				}
				if ( ! metadata_exists( 'post', $pid, $field_name ) ) {
					continue;
				}
				$had_meta = true;
				$val      = Leadwerk_Fields_API::get_field( $field_name, $pid );
				if ( null === $val || '' === $val ) {
					continue;
				}
				if ( is_array( $val ) && array() === $val ) {
					continue;
				}
				$id_set = array();
				self::collect_jpeg_png_ids_from_group_value( $val, $group, $id_set );
				foreach ( array_keys( $id_set ) as $aid ) {
					$aid = (int) $aid;
					if ( $aid < 1 ) {
						continue;
					}
					++$stats['field_attachment_refs'];
					if ( ! isset( $by_attachment[ $aid ] ) ) {
						$by_attachment[ $aid ] = array(
							'old_attachment_id' => $aid,
							'post_ids'          => array(),
							'sample_old_url'    => '',
						);
					}
					if ( ! in_array( $pid, $by_attachment[ $aid ]['post_ids'], true ) ) {
						$by_attachment[ $aid ]['post_ids'][] = $pid;
					}
				}
			}
			if ( $had_meta ) {
				++$stats['posts_scanned_fields'];
			}
		}

		foreach ( self::collect_option_field_names() as $opt_field ) {
			++$stats['options_scanned'];
			if ( empty( $groups[ $opt_field ] ) || ! is_array( $groups[ $opt_field ] ) ) {
				continue;
			}
			$group = $groups[ $opt_field ];
			$val   = Leadwerk_Fields_API::get_field( $opt_field, 'option' );
			if ( null === $val || '' === $val || ( is_array( $val ) && array() === $val ) ) {
				continue;
			}
			$id_set = array();
			self::collect_jpeg_png_ids_from_group_value( $val, $group, $id_set );
			foreach ( array_keys( $id_set ) as $aid ) {
				$aid = (int) $aid;
				if ( $aid < 1 ) {
					continue;
				}
				++$stats['field_attachment_refs'];
				if ( ! isset( $by_attachment[ $aid ] ) ) {
					$by_attachment[ $aid ] = array(
						'old_attachment_id' => $aid,
						'post_ids'          => array(),
						'sample_old_url'    => '',
					);
				}
			}
		}

		$entries = array();
		foreach ( $by_attachment as $aid => $data ) {
			$post_obj = get_post( $aid );
			if ( ! $post_obj || 'attachment' !== $post_obj->post_type ) {
				continue;
			}
			$mime = (string) $post_obj->post_mime_type;
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			$urls                   = self::collect_attachment_urls( $aid );
			$data['sample_old_url'] = ! empty( $urls[0] ) ? $urls[0] : '';
			$entries[]              = $data;
		}

		return array(
			'entries' => $entries,
			'stats'   => $stats,
		);
	}

	/**
	 * JPEG/PNG featured images (_thumbnail_id) for scoped post types (e.g. acm_news cards).
	 *
	 * @return array{entries:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	protected static function scan_attachments_in_featured_thumbnails() {
		$by_attachment = array();
		$stats         = array(
			'posts_with_jpeg_png_featured' => 0,
			'thumbnail_pairs'              => 0,
		);

		foreach ( self::collect_scoped_post_ids() as $pid ) {
			$tid = (int) get_post_thumbnail_id( $pid );
			if ( $tid < 1 || ! self::attachment_is_jpeg_or_png( $tid ) ) {
				continue;
			}
			++$stats['posts_with_jpeg_png_featured'];
			++$stats['thumbnail_pairs'];
			if ( ! isset( $by_attachment[ $tid ] ) ) {
				$by_attachment[ $tid ] = array(
					'old_attachment_id' => $tid,
					'post_ids'          => array(),
					'sample_old_url'    => '',
				);
			}
			if ( ! in_array( $pid, $by_attachment[ $tid ]['post_ids'], true ) ) {
				$by_attachment[ $tid ]['post_ids'][] = $pid;
			}
		}

		$entries = array();
		foreach ( $by_attachment as $aid => $data ) {
			$post_obj = get_post( $aid );
			if ( ! $post_obj || 'attachment' !== $post_obj->post_type ) {
				continue;
			}
			$mime = (string) $post_obj->post_mime_type;
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			$urls                   = self::collect_attachment_urls( $aid );
			$data['sample_old_url'] = ! empty( $urls[0] ) ? $urls[0] : '';
			$entries[]              = $data;
		}

		return array(
			'entries' => $entries,
			'stats'   => $stats,
		);
	}

	/**
	 * @param array{entries:array,stats:array} $body  Body scan.
	 * @param array{entries:array,stats:array} $field Field scan.
	 * @param array{entries:array,stats:array} $thumb Featured-image scan.
	 * @return array{entries:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	protected static function merge_webp_scan_results( array $body, array $field, array $thumb = array() ) {
		$merged = array();

		foreach ( (array) ( $body['entries'] ?? array() ) as $e ) {
			$id = (int) ( $e['old_attachment_id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			$merged[ $id ] = array(
				'old_attachment_id' => $id,
				'post_ids'          => isset( $e['post_ids'] ) && is_array( $e['post_ids'] ) ? array_values( array_map( 'intval', $e['post_ids'] ) ) : array(),
				'sample_old_url'    => isset( $e['sample_old_url'] ) ? (string) $e['sample_old_url'] : '',
			);
		}

		foreach ( (array) ( $field['entries'] ?? array() ) as $e ) {
			$id = (int) ( $e['old_attachment_id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = array(
					'old_attachment_id' => $id,
					'post_ids'          => isset( $e['post_ids'] ) && is_array( $e['post_ids'] ) ? array_values( array_map( 'intval', $e['post_ids'] ) ) : array(),
					'sample_old_url'    => isset( $e['sample_old_url'] ) ? (string) $e['sample_old_url'] : '',
				);
			} else {
				$merged[ $id ]['post_ids'] = array_values(
					array_unique(
						array_merge(
							$merged[ $id ]['post_ids'],
							isset( $e['post_ids'] ) && is_array( $e['post_ids'] ) ? array_map( 'intval', $e['post_ids'] ) : array()
						)
					)
				);
				if ( '' === (string) ( $merged[ $id ]['sample_old_url'] ?? '' ) && ! empty( $e['sample_old_url'] ) ) {
					$merged[ $id ]['sample_old_url'] = (string) $e['sample_old_url'];
				}
			}
		}

		foreach ( (array) ( $thumb['entries'] ?? array() ) as $e ) {
			$id = (int) ( $e['old_attachment_id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = array(
					'old_attachment_id' => $id,
					'post_ids'          => isset( $e['post_ids'] ) && is_array( $e['post_ids'] ) ? array_values( array_map( 'intval', $e['post_ids'] ) ) : array(),
					'sample_old_url'    => isset( $e['sample_old_url'] ) ? (string) $e['sample_old_url'] : '',
				);
			} else {
				$merged[ $id ]['post_ids'] = array_values(
					array_unique(
						array_merge(
							$merged[ $id ]['post_ids'],
							isset( $e['post_ids'] ) && is_array( $e['post_ids'] ) ? array_map( 'intval', $e['post_ids'] ) : array()
						)
					)
				);
				if ( '' === (string) ( $merged[ $id ]['sample_old_url'] ?? '' ) && ! empty( $e['sample_old_url'] ) ) {
					$merged[ $id ]['sample_old_url'] = (string) $e['sample_old_url'];
				}
			}
		}

		$b_stats = isset( $body['stats'] ) && is_array( $body['stats'] ) ? $body['stats'] : array();
		$f_stats = isset( $field['stats'] ) && is_array( $field['stats'] ) ? $field['stats'] : array();
		$t_stats = isset( $thumb['stats'] ) && is_array( $thumb['stats'] ) ? $thumb['stats'] : array();

		$stats = array(
			'posts_scanned_body'            => (int) ( $b_stats['posts_scanned'] ?? 0 ),
			'body_pairs'                    => (int) ( $b_stats['post_attachment_pairs'] ?? 0 ),
			'posts_scanned_fields'          => (int) ( $f_stats['posts_scanned_fields'] ?? 0 ),
			'field_attachment_refs'         => (int) ( $f_stats['field_attachment_refs'] ?? 0 ),
			'options_scanned'               => (int) ( $f_stats['options_scanned'] ?? 0 ),
			'posts_with_jpeg_png_featured'  => (int) ( $t_stats['posts_with_jpeg_png_featured'] ?? 0 ),
			'thumbnail_pairs'               => (int) ( $t_stats['thumbnail_pairs'] ?? 0 ),
			'attachments_found'             => count( $merged ),
			'post_attachment_pairs'         => (int) ( $b_stats['post_attachment_pairs'] ?? 0 ) + (int) ( $f_stats['field_attachment_refs'] ?? 0 ) + (int) ( $t_stats['thumbnail_pairs'] ?? 0 ),
			'posts_scanned'                 => (int) ( $b_stats['posts_scanned'] ?? 0 ),
		);

		return array(
			'entries' => array_values( $merged ),
			'stats'   => $stats,
		);
	}

	/**
	 * @return array{entries:array<int,array<string,mixed>>,stats:array<string,int>}|WP_Error
	 */
	protected static function scan_attachments_in_body() {
		$types = self::get_post_types();
		if ( empty( $types ) ) {
			return new WP_Error( 'types', 'Keine Post-Types konfiguriert.' );
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload', (string) $upload['error'] );
		}
		$base = trailingslashit( $upload['baseurl'] );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_type IN ($placeholders)
			AND post_status NOT IN ('trash','auto-draft')
			AND post_content <> ''";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $types );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_attachment = array();

		$stats = array(
			'posts_scanned'          => 0,
			'attachments_found'      => 0,
			'post_attachment_pairs'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$pid = (int) ( $row['ID'] ?? 0 );
			$html = (string) ( $row['post_content'] ?? '' );
			if ( $pid < 1 || '' === $html ) {
				continue;
			}
			++$stats['posts_scanned'];

			$ids = self::extract_jpeg_png_attachment_ids_from_content( $html, $base );
			foreach ( $ids as $aid ) {
				$aid = (int) $aid;
				if ( $aid < 1 ) {
					continue;
				}
				++$stats['post_attachment_pairs'];
				if ( ! isset( $by_attachment[ $aid ] ) ) {
					$by_attachment[ $aid ] = array(
						'old_attachment_id' => $aid,
						'post_ids'          => array(),
						'sample_old_url'    => '',
					);
				}
				if ( ! in_array( $pid, $by_attachment[ $aid ]['post_ids'], true ) ) {
					$by_attachment[ $aid ]['post_ids'][] = $pid;
				}
			}
		}

		$entries = array();
		foreach ( $by_attachment as $aid => $data ) {
			$post_obj = get_post( $aid );
			if ( ! $post_obj || 'attachment' !== $post_obj->post_type ) {
				continue;
			}
			$mime = (string) $post_obj->post_mime_type;
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			$urls                   = self::collect_attachment_urls( $aid );
			$data['sample_old_url'] = ! empty( $urls[0] ) ? $urls[0] : '';
			$entries[]              = $data;
		}

		$stats['attachments_found'] = count( $entries );

		return array(
			'entries' => $entries,
			'stats'   => $stats,
		);
	}

	/**
	 * Absolute URL for attachment_url_to_postid (root-relative, protocol-relative, full).
	 *
	 * @param string $url Raw URL from HTML.
	 * @return string
	 */
	protected static function normalize_upload_url_for_lookup( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = self::strip_url_query( $url );
		if ( preg_match( '#^//#', $url ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return $scheme . $url;
		}
		if ( '/' === ( $url[0] ?? '' ) ) {
			return home_url( $url );
		}
		return $url;
	}

	/**
	 * Collect upload image URLs (jpg/png) from post HTML — multiple URL shapes (CDN, relative, blocks).
	 *
	 * @param string $html              post_content.
	 * @param string $upload_base_url   Trailing-slash uploads base URL.
	 * @return string[] Unique URL strings (may be relative; normalize before lookup).
	 */
	protected static function collect_jpeg_png_upload_urls_from_html( $html, $upload_base_url ) {
		$found = array();

		$bases = array_unique(
			array_filter(
				array(
					$upload_base_url,
					trailingslashit( home_url() ) . 'wp-content/uploads/',
					trailingslashit( site_url() ) . 'wp-content/uploads/',
					function_exists( 'content_url' ) ? trailingslashit( content_url() ) . 'uploads/' : '',
				)
			)
		);

		foreach ( $bases as $base ) {
			if ( '' === $base ) {
				continue;
			}
			$quoted = preg_quote( $base, '#' );
			$pat    = '#' . $quoted . '[^\s,"\'<>]+\.(?:jpe?g|png)(?:\?[^\s,"\'<>]*)?#i';
			if ( preg_match_all( $pat, $html, $m ) ) {
				foreach ( $m[0] as $u ) {
					$found[ $u ] = true;
				}
			}
		}

		// Root-relative: /wp-content/uploads/... (common in DB).
		if ( preg_match_all( '#/wp-content/uploads/[^\s,"\'<>]+\.(?:jpe?g|png)(?:\?[^\s,"\'<>]*)?#i', $html, $m2 ) ) {
			foreach ( $m2[0] as $u ) {
				$found[ $u ] = true;
			}
		}

		// Protocol-relative full host + uploads.
		if ( preg_match_all( '#//[^\s,"\'<>/]+/wp-content/uploads/[^\s,"\'<>]+\.(?:jpe?g|png)(?:\?[^\s,"\'<>]*)?#i', $html, $m3 ) ) {
			foreach ( $m3[0] as $u ) {
				$found[ $u ] = true;
			}
		}

		/**
		 * Extra regex patterns for upload JPEG/PNG URLs (each must have one capture group = full URL).
		 *
		 * @param string[] $patterns Regex strings with one capturing group.
		 * @param string   $html     Content.
		 */
		$extra = apply_filters( 'leadwerk_webp_body_upload_url_regexes', array(), $html );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $regex ) {
				if ( ! is_string( $regex ) || '' === $regex ) {
					continue;
				}
				if ( @preg_match_all( $regex, $html, $mx ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( ! empty( $mx[1] ) && is_array( $mx[1] ) ) {
						foreach ( $mx[1] as $u ) {
							if ( is_string( $u ) && '' !== $u ) {
								$found[ $u ] = true;
							}
						}
					}
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * @param string $html post_content.
	 * @param string $upload_base_url Trailing slash baseurl.
	 * @return int[]
	 */
	protected static function extract_jpeg_png_attachment_ids_from_content( $html, $upload_base_url ) {
		$ids = array();

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $html, $m ) ) {
			foreach ( $m[1] as $id_str ) {
				$id = (int) $id_str;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		// Block-Editor u. a.: "id":123 in JSON (nur Kandidaten; MIME-Filter unten).
		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $html, $mj ) ) {
			foreach ( $mj[1] as $id_str ) {
				$id = (int) $id_str;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		foreach ( self::collect_jpeg_png_upload_urls_from_html( $html, $upload_base_url ) as $url ) {
			$abs = self::normalize_upload_url_for_lookup( $url );
			if ( '' === $abs ) {
				continue;
			}
			$aid = attachment_url_to_postid( $abs );
			if ( $aid > 0 ) {
				$ids[ $aid ] = true;
			}
		}

		$out = array();
		foreach ( array_keys( $ids ) as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			$mime = get_post_mime_type( $id );
			if ( ! in_array( (string) $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	protected static function strip_url_query( $url ) {
		$p = strpos( $url, '?' );
		if ( false !== $p ) {
			return substr( $url, 0, $p );
		}
		return $url;
	}

	/**
	 * Block-/Klassen-Referenzen auf Attachment-ID in post_content (scoped Types).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected static function scoped_post_content_references_attachment_id( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		global $wpdb;
		$types        = self::get_post_types();
		if ( empty( $types ) ) {
			return false;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$patterns     = array(
			'%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%',
			'%' . $wpdb->esc_like( '"id":' . $attachment_id . ',' ) . '%',
			'%' . $wpdb->esc_like( '"id": ' . $attachment_id . ',' ) . '%',
			'%' . $wpdb->esc_like( '"id":' . $attachment_id . '}' ) . '%',
			'%' . $wpdb->esc_like( '"id":' . $attachment_id . ' ' ) . '%',
		);
		foreach ( $patterns as $like ) {
			$params = array_merge( $types, array( $like ) );
			$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s LIMIT 1";
			$found  = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $substrings Needles.
	 * @return bool
	 */
	protected static function post_types_content_contains_any( array $substrings ) {
		if ( empty( $substrings ) ) {
			return false;
		}
		$types = self::get_post_types();
		global $wpdb;
		foreach ( $substrings as $needle ) {
			$needle = (string) $needle;
			if ( '' === $needle ) {
				continue;
			}
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$params       = array_merge( $types, array( $like ) );
			$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s LIMIT 1";
			$found        = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Nach WebP-Migration: nur relevante Referenzen (Leadwerk-Schema-Meta, Thumbnail, Optionen, Site-Assets).
	 * Vermeidet False-Positives von attachment_is_referenced() (beliebiges postmeta mit Zahl oder i:ID;).
	 *
	 * @param int $old_id Old attachment ID.
	 * @return bool True wenn noch referenziert — nicht loeschen.
	 */
	protected static function attachment_referenced_for_webp_manifest_delete_narrow( $old_id ) {
		$old_id = (int) $old_id;
		if ( $old_id < 1 ) {
			return true;
		}

		if ( (int) get_option( 'site_icon' ) === $old_id ) {
			return true;
		}

		$custom_logo = (int) get_theme_mod( 'custom_logo', 0 );
		if ( $custom_logo === $old_id ) {
			return true;
		}

		if ( class_exists( 'Leadwerk_Orphan_Media_Admin' )
			&& method_exists( 'Leadwerk_Orphan_Media_Admin', 'attachment_referenced_in_leadwerk_options_only' )
			&& Leadwerk_Orphan_Media_Admin::attachment_referenced_in_leadwerk_options_only( $old_id ) ) {
			return true;
		}

		$types = self::get_post_types();
		if ( empty( $types ) ) {
			return false;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$params_thumb = array_merge( $types, array( (string) $old_id ) );
		$sql_thumb    = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ($placeholders)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s";
		$count_thumb = (int) $wpdb->get_var( $wpdb->prepare( $sql_thumb, ...$params_thumb ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $count_thumb > 0 ) {
			return true;
		}

		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			return false;
		}

		$meta_keys = array_keys( Leadwerk_Content_Schema::get_groups() );
		$meta_keys = array_values(
			array_filter(
				array_unique( array_map( 'strval', $meta_keys ) ),
				static function ( $k ) {
					return '' !== $k;
				}
			)
		);
		if ( empty( $meta_keys ) ) {
			return false;
		}

		$key_ph      = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$like_ser    = '%' . $wpdb->esc_like( 'i:' . $old_id . ';' ) . '%';
		$like_wp     = '%' . $wpdb->esc_like( 'wp-image-' . $old_id ) . '%';
		$like_json1  = '%' . $wpdb->esc_like( '"id":' . $old_id . ',' ) . '%';
		$like_json2  = '%' . $wpdb->esc_like( '"id": ' . $old_id . ',' ) . '%';
		$like_json3  = '%' . $wpdb->esc_like( '"id":' . $old_id . '}' ) . '%';
		$exact       = (string) $old_id;
		$params_meta = array_merge(
			$types,
			$meta_keys,
			array( $old_id, $exact, $like_ser, $like_wp, $like_json1, $like_json2, $like_json3 )
		);
		$sql_meta    = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ($placeholders)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.post_id <> %d
			AND pm.meta_key IN ($key_ph)
			AND (
				pm.meta_value = %s
				OR pm.meta_value LIKE %s
				OR pm.meta_value LIKE %s
				OR pm.meta_value LIKE %s
				OR pm.meta_value LIKE %s
				OR pm.meta_value LIKE %s
			)";
		$count_meta = (int) $wpdb->get_var( $wpdb->prepare( $sql_meta, ...$params_meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $count_meta > 0;
	}

	/**
	 * Safe delete old attachments from manifest when no references remain.
	 *
	 * @return array{deleted:int,skipped:int,log:string[]}
	 */
	public static function run_safe_delete_from_manifest() {
		$manifest = get_option( self::OPTION_MANIFEST, array() );
		if ( empty( $manifest['entries'] ) || ! is_array( $manifest['entries'] ) ) {
			return array(
				'deleted' => 0,
				'skipped' => 0,
				'log'     => array( 'Kein Manifest.' ),
			);
		}

		$deleted = 0;
		$skipped = 0;
		$log     = array();

		foreach ( $manifest['entries'] as $entry ) {
			$old_id = (int) ( $entry['old_attachment_id'] ?? 0 );
			$new_id = (int) ( $entry['new_attachment_id'] ?? 0 );
			if ( $old_id < 1 || $new_id < 1 ) {
				++$skipped;
				$log[] = "Skip old=$old_id new=$new_id (fehlende IDs).";
				continue;
			}

			$old_urls = self::collect_attachment_urls( $old_id );
			if ( ! empty( $old_urls ) && self::post_types_content_contains_any( $old_urls ) ) {
				++$skipped;
				$log[] = "Skip $old_id: post_content enthaelt noch alte URLs.";
				continue;
			}

			if ( self::scoped_post_content_references_attachment_id( $old_id ) ) {
				++$skipped;
				$log[] = "Skip $old_id: post_content enthaelt noch wp-image-/Block-ID-Referenz.";
				continue;
			}

			if ( self::attachment_referenced_for_webp_manifest_delete_narrow( $old_id ) ) {
				++$skipped;
				$log[] = "Skip $old_id: noch referenziert (Leadwerk-Schema-Meta, Thumbnail oder leadwerk_opt_*).";
				continue;
			}

			if ( ! get_post( $old_id ) instanceof WP_Post ) {
				++$skipped;
				continue;
			}

			$res = wp_delete_attachment( $old_id, true );
			if ( ! $res ) {
				++$skipped;
				$log[] = "Skip $old_id: wp_delete_attachment fehlgeschlagen.";
				continue;
			}
			++$deleted;
			$log[] = "Deleted attachment $old_id.";
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
			'log'     => $log,
		);
	}

	/**
	 * @return void
	 */
	public static function ajax_delete() {
		self::verify_ajax();
		if ( empty( $_POST['confirm'] ) || '1' !== (string) wp_unslash( $_POST['confirm'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Bestaetigung fehlt.', 'leadwerk-importer' ) ), 400 );
		}

		self::maybe_raise_limits();
		self::log( 'Sicheres Loeschen gestartet.' );

		$result = self::run_safe_delete_from_manifest();
		foreach ( $result['log'] as $line ) {
			self::log( $line );
		}
		self::log(
			sprintf(
				'Loeschen fertig: geloescht %1$d, uebersprungen %2$d.',
				(int) $result['deleted'],
				(int) $result['skipped']
			)
		);

		wp_send_json_success( $result );
	}

	/**
	 * Fallback non-JS delete via admin-post.
	 *
	 * @return void
	 */
	public static function handle_delete_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-importer' ) );
		}
		check_admin_referer( 'leadwerk_webp_body_delete_sync', 'nonce' );
		if ( empty( $_POST['confirm_delete'] ) ) {
			wp_safe_redirect( admin_url( 'tools.php?page=leadwerk-webp-body&delete_err=1' ) );
			exit;
		}

		self::maybe_raise_limits();
		self::log( 'Sicheres Loeschen (sync) gestartet.' );
		$result = self::run_safe_delete_from_manifest();
		foreach ( $result['log'] as $line ) {
			self::log( $line );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'leadwerk-webp-body',
					'deleted'       => (int) $result['deleted'],
					'skipped'       => (int) $result['skipped'],
					'delete_notice' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * old_attachment_id => new_attachment_id from last manifest (Live-Lauf abgeschlossen).
	 *
	 * @return array<int,int>
	 */
	public static function get_id_map_from_manifest() {
		$manifest = get_option( self::OPTION_MANIFEST, array() );
		$map      = array();
		if ( empty( $manifest['entries'] ) || ! is_array( $manifest['entries'] ) ) {
			return $map;
		}
		foreach ( $manifest['entries'] as $entry ) {
			$o = (int) ( $entry['old_attachment_id'] ?? 0 );
			$n = (int) ( $entry['new_attachment_id'] ?? 0 );
			if ( $o > 0 && $n > 0 ) {
				$map[ $o ] = $n;
			}
		}
		return $map;
	}

	/**
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	protected static function leadwerk_values_equal( $a, $b ) {
		return wp_json_encode( $a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			=== wp_json_encode( $b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed                $value      Stored field tree.
	 * @param array<string,mixed>  $definition Field definition.
	 * @param array<int,int>       $map        Old attachment ID => new.
	 * @return mixed
	 */
	protected static function remap_field_value( $value, array $definition, array $map ) {
		$type = $definition['type'] ?? 'text';

		switch ( $type ) {
			case 'image':
				$id = is_numeric( $value ) ? (int) $value : 0;
				if ( $id > 0 && isset( $map[ $id ] ) ) {
					return (int) $map[ $id ];
				}
				return $value;

			case 'video':
			case 'file':
				if ( is_numeric( $value ) ) {
					$id = (int) $value;
					if ( $id > 0 && isset( $map[ $id ] ) ) {
						return (int) $map[ $id ];
					}
				}
				return $value;

			case 'repeater':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$out = array();
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						$out[] = $row;
						continue;
					}
					$item = array();
					foreach ( (array) ( $definition['fields'] ?? array() ) as $sk => $sdef ) {
						$item[ $sk ] = array_key_exists( $sk, $row )
							? self::remap_field_value( $row[ $sk ], $sdef, $map )
							: null;
					}
					$out[] = $item;
				}
				return $out;

			case 'wysiwyg':
			case 'classic_editor':
			case 'html':
			case 'heading_html':
				$html = is_string( $value ) ? $value : '';
				$out   = $html;
				foreach ( $map as $old_id => $new_id ) {
					$out = self::replace_urls_in_html( $out, (int) $old_id, (int) $new_id );
				}
				return $out;

			default:
				return $value;
		}
	}

	/**
	 * @param mixed                $value Group or flexible value.
	 * @param array<string,mixed>  $group Schema group.
	 * @param array<int,int>       $map   Old => new attachment IDs.
	 * @return mixed
	 */
	protected static function remap_group_value( $value, array $group, array $map ) {
		if ( empty( $group['layouts'] ) ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			$new = $value;
			foreach ( (array) ( $group['fields'] ?? array() ) as $key => $def ) {
				if ( array_key_exists( $key, $new ) ) {
					$new[ $key ] = self::remap_field_value( $new[ $key ], $def, $map );
				}
			}
			return $new;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				$out[] = $row;
				continue;
			}
			$layout = isset( $row['acf_fc_layout'] ) ? sanitize_key( (string) $row['acf_fc_layout'] ) : '';
			if ( '' === $layout || empty( $group['layouts'][ $layout ]['fields'] ) ) {
				$out[] = $row;
				continue;
			}
			$layout_schema = $group['layouts'][ $layout ];
			$new_row       = $row;
			foreach ( (array) $layout_schema['fields'] as $fk => $fdef ) {
				if ( array_key_exists( $fk, $row ) ) {
					$new_row[ $fk ] = self::remap_field_value( $row[ $fk ], $fdef, $map );
				}
			}
			$out[] = $new_row;
		}
		return $out;
	}

	/**
	 * @param int            $post_id Post ID.
	 * @param array<int,int> $map     ID map.
	 * @param bool           $dry_run Dry run.
	 * @return array{fields_changed:int}
	 */
	protected static function remap_post_meta_fields( $post_id, array $map, $dry_run ) {
		$post_id = (int) $post_id;
		$changed = 0;
		if ( $post_id < 1 || empty( $map ) || ! class_exists( 'Leadwerk_Content_Schema' ) || ! class_exists( 'Leadwerk_Fields_API' ) ) {
			return array( 'fields_changed' => 0 );
		}
		if ( ! apply_filters( 'leadwerk_webp_field_remap_apply_post', true, $post_id, $map, $dry_run ) ) {
			return array( 'fields_changed' => 0 );
		}

		$groups = Leadwerk_Content_Schema::get_groups();
		foreach ( $groups as $field_name => $group ) {
			if ( ! is_string( $field_name ) || '' === $field_name || ! is_array( $group ) ) {
				continue;
			}
			if ( ! metadata_exists( 'post', $post_id, $field_name ) ) {
				continue;
			}
			$val = Leadwerk_Fields_API::get_field( $field_name, $post_id );
			if ( null === $val || '' === $val ) {
				continue;
			}
			if ( is_array( $val ) && array() === $val ) {
				continue;
			}
			$new = self::remap_group_value( $val, $group, $map );
			if ( self::leadwerk_values_equal( $val, $new ) ) {
				continue;
			}
			if ( ! $dry_run ) {
				Leadwerk_Fields_API::update_field( $field_name, $new, $post_id );
			}
			++$changed;
		}
		return array( 'fields_changed' => $changed );
	}

	/**
	 * @param string         $field_name Option field basename (ohne leadwerk_opt_).
	 * @param array<int,int> $map        ID map.
	 * @param bool           $dry_run    Dry run.
	 * @return int Fields changed count.
	 */
	protected static function remap_one_option_field( $field_name, array $map, $dry_run ) {
		if ( '' === $field_name || empty( $map ) || ! class_exists( 'Leadwerk_Content_Schema' ) || ! class_exists( 'Leadwerk_Fields_API' ) ) {
			return 0;
		}
		$groups = Leadwerk_Content_Schema::get_groups();
		if ( empty( $groups[ $field_name ] ) || ! is_array( $groups[ $field_name ] ) ) {
			return 0;
		}
		$group = $groups[ $field_name ];
		$val   = Leadwerk_Fields_API::get_field( $field_name, 'option' );
		if ( null === $val || '' === $val ) {
			return 0;
		}
		$new = self::remap_group_value( $val, $group, $map );
		if ( self::leadwerk_values_equal( $val, $new ) ) {
			return 0;
		}
		if ( ! $dry_run ) {
			Leadwerk_Fields_API::update_field( $field_name, $new, 'option' );
		}
		return 1;
	}

	/**
	 * @return int[]
	 */
	protected static function collect_scoped_post_ids() {
		$types = self::get_post_types();
		if ( empty( $types ) ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC";
		$ids          = $wpdb->get_col( $wpdb->prepare( $sql, $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
	 * Option field basenames (leadwerk_opt_*) die im Schema existieren.
	 *
	 * @return string[]
	 */
	protected static function collect_option_field_names() {
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			return array();
		}
		$groups = Leadwerk_Content_Schema::get_groups();
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'leadwerk_opt_%'"
		);
		$prefix = 'leadwerk_opt_';
		$len    = strlen( $prefix );
		$out    = array();
		foreach ( (array) $rows as $full ) {
			$short = substr( (string) $full, $len );
			$short = sanitize_key( $short );
			if ( '' !== $short && isset( $groups[ $short ] ) ) {
				$out[] = $short;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return void
	 */
	public static function ajax_field_remap_init() {
		self::verify_ajax();
		self::maybe_raise_limits();

		$dry_run = ! empty( $_POST['dry_run'] );
		$map     = self::get_id_map_from_manifest();
		if ( empty( $map ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Kein gueltiges Manifest mit old/new Attachment-IDs. Bitte zuerst Body-Live-Konvertierung abschliessen.', 'leadwerk-importer' ),
				),
				400
			);
		}

		$post_ids     = self::collect_scoped_post_ids();
		$option_names = self::collect_option_field_names();

		$job = array(
			'status'         => 'running',
			'dry_run'        => $dry_run,
			'map'            => $map,
			'post_ids'       => $post_ids,
			'post_cursor'    => 0,
			'option_names'   => $option_names,
			'option_cursor'  => 0,
			'started'        => gmdate( 'c' ),
			'stats'          => array(
				'posts_scanned'    => 0,
				'posts_with_change'=> 0,
				'fields_changed'   => 0,
				'options_changed'  => 0,
			),
		);
		update_option( self::OPTION_FIELD_JOB, $job, false );

		self::log(
			sprintf(
				'Feld-Remap %1$s: %2$d Posts, %3$d Option-Felder, %4$d ID-Paare.',
				$dry_run ? '(Dry-Run)' : '(Live)',
				count( $post_ids ),
				count( $option_names ),
				count( $map )
			)
		);

		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * @return void
	 */
	public static function ajax_field_remap_step() {
		self::verify_ajax();
		self::maybe_raise_limits();

		$job = get_option( self::OPTION_FIELD_JOB, array() );
		if ( empty( $job['status'] ) || 'running' !== $job['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Kein aktiver Feld-Remap-Job. Bitte zuerst initialisieren.', 'leadwerk-importer' ) ), 400 );
		}

		$map      = isset( $job['map'] ) && is_array( $job['map'] ) ? $job['map'] : array();
		$dry_run  = ! empty( $job['dry_run'] );
		$map_ints = array();
		foreach ( $map as $k => $v ) {
			$map_ints[ (int) $k ] = (int) $v;
		}

		$batch_posts = (int) apply_filters( 'leadwerk_webp_field_remap_batch_posts', 12 );
		if ( $batch_posts < 1 ) {
			$batch_posts = 1;
		}
		$batch_opts = (int) apply_filters( 'leadwerk_webp_field_remap_batch_options', 4 );
		if ( $batch_opts < 1 ) {
			$batch_opts = 1;
		}

		$post_ids = isset( $job['post_ids'] ) && is_array( $job['post_ids'] ) ? $job['post_ids'] : array();
		$cursor    = (int) ( $job['post_cursor'] ?? 0 );
		$stats     = isset( $job['stats'] ) && is_array( $job['stats'] ) ? $job['stats'] : array(
			'posts_scanned'     => 0,
			'posts_with_change' => 0,
			'fields_changed'    => 0,
			'options_changed'   => 0,
		);

		if ( $cursor < count( $post_ids ) ) {
			$slice = array_slice( $post_ids, $cursor, $batch_posts );
			foreach ( $slice as $pid ) {
				$r = self::remap_post_meta_fields( $pid, $map_ints, $dry_run );
				++$stats['posts_scanned'];
				if ( ! empty( $r['fields_changed'] ) ) {
					++$stats['posts_with_change'];
					$stats['fields_changed'] += (int) $r['fields_changed'];
				}
			}
			$job['post_cursor'] = $cursor + count( $slice );
		} else {
			$opt_names = isset( $job['option_names'] ) && is_array( $job['option_names'] ) ? $job['option_names'] : array();
			$opt_cur   = (int) ( $job['option_cursor'] ?? 0 );
			if ( $opt_cur < count( $opt_names ) ) {
				$slice = array_slice( $opt_names, $opt_cur, $batch_opts );
				foreach ( $slice as $fname ) {
					$n = self::remap_one_option_field( $fname, $map_ints, $dry_run );
					if ( $n > 0 ) {
						++$stats['options_changed'];
						$stats['fields_changed'] += $n;
					}
				}
				$job['option_cursor'] = $opt_cur + count( $slice );
			}
		}

		$job['stats'] = $stats;

		$posts_done  = (int) ( $job['post_cursor'] ?? 0 ) >= count( $post_ids );
		$opts_done   = (int) ( $job['option_cursor'] ?? 0 ) >= count( isset( $job['option_names'] ) && is_array( $job['option_names'] ) ? $job['option_names'] : array() );
		$all_done    = $posts_done && $opts_done;

		if ( $all_done ) {
			$job['status']   = 'completed';
			$job['finished'] = gmdate( 'c' );
			$job['message']  = $dry_run
				? __( 'Feld-Remap Dry-Run abgeschlossen.', 'leadwerk-importer' )
				: __( 'Feld-Remap Live abgeschlossen.', 'leadwerk-importer' );
			self::log(
				sprintf(
					'Feld-Remap fertig (%1$s): Posts mit Aenderung %2$d, Option-Felder %3$d, Meta-Feld-Updates %4$d.',
					$dry_run ? 'Dry-Run' : 'Live',
					(int) $stats['posts_with_change'],
					(int) $stats['options_changed'],
					(int) $stats['fields_changed']
				)
			);
		}

		update_option( self::OPTION_FIELD_JOB, $job, false );
		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'leadwerk-importer' ) );
		}

		if ( isset( $_GET['leadwerk_webp_clear_log'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'leadwerk_webp_clear_log' ) ) {
			self::clear_log();
			wp_safe_redirect( admin_url( 'tools.php?page=leadwerk-webp-body' ) );
			exit;
		}

		$manifest  = get_option( self::OPTION_MANIFEST, array() );
		$job       = get_option( self::OPTION_JOB, array() );
		$field_job = get_option( self::OPTION_FIELD_JOB, array() );
		$log       = (string) get_option( self::OPTION_LOG, '' );

		$supports_webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Leadwerk WebP (Body)', 'leadwerk-importer' ) . '</h1>';

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Bitte vor Live-Lauf ein Backup (Datenbank + wp-content/uploads) anlegen. Body-Live aendert post_content und ggf. Beitragsbild (_thumbnail_id); der nachfolgende Schritt „Leadwerk-Felder“ aktualisiert Meta/Optionen nach dem Manifest.', 'leadwerk-importer' );
		echo '</p></div>';

		if ( ! $supports_webp ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'PHP-Image-Stack: WebP-Schreiben wird nicht unterstuetzt (GD/Imagick pruefen). Dry-Run ist trotzdem moeglich.', 'leadwerk-importer' );
			echo '</p></div>';
		}

		echo '<p>';
		echo esc_html__( 'Statisches Python-Skript ersetzt Dateien auf der Platte; hier werden Medien in WordPress ueber Attachment-IDs und post_content-URLs behandelt.', 'leadwerk-importer' );
		echo '</p>';

		echo '<h2>' . esc_html__( 'Aktionen', 'leadwerk-importer' ) . '</h2>';
		echo '<p>';
		echo '<button type="button" class="button" id="leadwerk-webp-dry-run">' . esc_html__( 'Dry-Run (Scan + Log)', 'leadwerk-importer' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="leadwerk-webp-live"' . ( $supports_webp ? '' : ' disabled' ) . '>' . esc_html__( 'Live-Konvertierung starten', 'leadwerk-importer' ) . '</button>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Dry-Run/Scan umfasst post_content, Beitragsbild (_thumbnail_id) bei den gescannten Post-Types sowie Leadwerk-Feld-Meta (Schema: Bild/Datei/Video + HTML in WYSIWYG). Live-Lauf arbeitet in Stapeln per AJAX; Seite offen lassen bis „Fertig“.', 'leadwerk-importer' ) . '</p>';

		echo '<h2>' . esc_html__( 'Leadwerk-Felder (Attachment-IDs & Medien-URLs)', 'leadwerk-importer' ) . '</h2>';
		echo '<p class="description">';
		echo esc_html__( 'Nach abgeschlossenem Body-Live: gleiche old→new-IDs aus dem Manifest in allen Leadwerk_Content_Schema-Gruppen anwenden (post_meta + leadwerk_opt_*). Bild-/Datei-/Video-IDs werden ersetzt; in HTML-/WYSIWYG-Feldern werden URLs und Block-IDs wie im Body angepasst. Zuerst Dry-Run, dann Live.', 'leadwerk-importer' );
		echo '</p>';
		echo '<p>';
		echo '<button type="button" class="button" id="leadwerk-webp-field-dry">' . esc_html__( 'Feld-Remap Dry-Run', 'leadwerk-importer' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="leadwerk-webp-field-live">' . esc_html__( 'Feld-Remap Live', 'leadwerk-importer' ) . '</button>';
		echo '</p>';

		echo '<div id="leadwerk-webp-progress" style="margin:12px 0;display:none;"><p><strong>' . esc_html__( 'Status:', 'leadwerk-importer' ) . '</strong> <span id="leadwerk-webp-status"></span></p></div>';

		echo '<h2>' . esc_html__( 'Alte JPEG/PNG-Anhaenge loeschen', 'leadwerk-importer' ) . '</h2>';
		echo '<p class="description">';
		echo esc_html__( 'Nur Eintraege aus dem letzten Manifest, wenn post_content keine alten URLs mehr enthaelt und das alte Attachment nirgends sonst referenziert wird (wie Leadwerk-Orphan-Pruefung).', 'leadwerk-importer' );
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:10px;" onsubmit="return document.getElementById(\'leadwerk-webp-delete-confirm\').checked;">';
		wp_nonce_field( 'leadwerk_webp_body_delete_sync', 'nonce' );
		echo '<input type="hidden" name="action" value="leadwerk_webp_body_delete_sync" />';
		echo '<label><input type="checkbox" name="confirm_delete" id="leadwerk-webp-delete-confirm" value="1" /> ';
		echo esc_html__( 'Ich habe die Hinweise gelesen und moechte alte Anhaenge gemaess Manifest sicher loeschen.', 'leadwerk-importer' );
		echo '</label><br /><br />';
		submit_button( __( 'Alte Originale loeschen (Formular)', 'leadwerk-importer' ), 'secondary' );
		echo '</form>';

		echo '<p><button type="button" class="button" id="leadwerk-webp-delete-ajax">' . esc_html__( 'Alte Originale loeschen (AJAX + Checkbox unten)', 'leadwerk-importer' ) . '</button></p>';
		echo '<label><input type="checkbox" id="leadwerk-webp-delete-confirm-ajax" value="1" /> ';
		echo esc_html__( 'Loeschen bestaetigen', 'leadwerk-importer' );
		echo '</label>';

		if ( ! empty( $_GET['delete_notice'] ) ) {
			$d = isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : 0;
			$s = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( /* translators: 1: deleted count, 2: skipped */ __( 'Loeschen abgeschlossen: %1$d geloescht, %2$d uebersprungen.', 'leadwerk-importer' ), $d, $s ) ) . '</p></div>';
		}

		if ( ! empty( $_GET['delete_err'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Checkbox fuer Loeschen fehlt.', 'leadwerk-importer' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Letztes Manifest (Auszug)', 'leadwerk-importer' ) . '</h2>';
		if ( ! empty( $manifest['entries'] ) && is_array( $manifest['entries'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %d count */ __( 'Einträge: %d', 'leadwerk-importer' ), count( $manifest['entries'] ) ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>old ID</th><th>new ID</th><th>Posts</th><th>Beispiel-URL</th></tr></thead><tbody>';
			foreach ( array_slice( $manifest['entries'], 0, 40 ) as $e ) {
				echo '<tr><td>' . esc_html( (string) ( $e['old_attachment_id'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['new_attachment_id'] ?? '—' ) ) . '</td><td>';
				echo esc_html( implode( ',', array_map( 'strval', (array) ( $e['post_ids'] ?? array() ) ) ) );
				echo '</td><td>' . esc_html( (string) ( $e['sample_old_url'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch kein Manifest (zuerst Dry-Run oder Live-Lauf).', 'leadwerk-importer' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Job-Status (Body)', 'leadwerk-importer' ) . '</h2>';
		echo '<pre style="max-height:200px;overflow:auto;background:#f6f7f7;padding:10px;">' . esc_html( wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';

		echo '<h2>' . esc_html__( 'Job-Status (Feld-Remap)', 'leadwerk-importer' ) . '</h2>';
		echo '<pre style="max-height:200px;overflow:auto;background:#f6f7f7;padding:10px;">' . esc_html( wp_json_encode( $field_job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';

		echo '<h2>' . esc_html__( 'Log', 'leadwerk-importer' ) . '</h2>';
		echo '<pre style="max-height:320px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4;">' . esc_html( $log ) . '</pre>';
		echo '<p><a class="button" href="' . esc_url(
			wp_nonce_url(
				add_query_arg( array( 'leadwerk_webp_clear_log' => '1' ), admin_url( 'tools.php?page=leadwerk-webp-body' ) ),
				'leadwerk_webp_clear_log'
			)
		) . '">' . esc_html__( 'Log leeren', 'leadwerk-importer' ) . '</a></p>';

		echo '</div>';
	}
}
