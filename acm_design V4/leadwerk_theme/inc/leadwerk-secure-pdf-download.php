<?php
/**
 * Signierte, zeitlich begrenzte PDF-Download-URLs (Karriere / Stellenanzeigen).
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Query-Parameter fuer den Download-Endpunkt. */
if ( ! defined( 'LEADWERK_THEME_SECURE_PDF_QUERY' ) ) {
	define( 'LEADWERK_THEME_SECURE_PDF_QUERY', 'leadwerk_pdf' );
}

/**
 * Schluessel fuer HMAC (site-spezifisch).
 *
 * @return string
 */
function leadwerk_theme_secure_pdf_hmac_key() {
	return (string) wp_hash( 'leadwerk_secure_pdf_v1' );
}

/**
 * Gueltigkeitsdauer eines Tokens in Sekunden.
 *
 * @return int
 */
function leadwerk_theme_secure_pdf_ttl() {
	return (int) apply_filters( 'leadwerk_theme_secure_pdf_ttl', 7 * DAY_IN_SECONDS );
}

/**
 * @param int $attachment_id Anhang-ID.
 * @return string URL-sicheres Base64-Token oder leer.
 */
function leadwerk_theme_build_secure_pdf_token( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( ! $attachment_id ) {
		return '';
	}
	$exp   = time() + leadwerk_theme_secure_pdf_ttl();
	$data  = '1|' . $attachment_id . '|' . $exp;
	$sig   = hash_hmac( 'sha256', $data, leadwerk_theme_secure_pdf_hmac_key() );
	$token = $data . '|' . $sig;

	return rtrim( strtr( base64_encode( $token ), '+/', '-_' ), '=' );
}

/**
 * @param string $token_raw Token aus der URL (ggf. bereits von PHP dekodiert).
 * @return int Anhang-ID oder 0.
 */
function leadwerk_theme_parse_secure_pdf_token( $token_raw ) {
	$token_raw = (string) $token_raw;
	if ( '' === $token_raw ) {
		return 0;
	}
	$pad = strlen( $token_raw ) % 4;
	if ( $pad ) {
		$token_raw .= str_repeat( '=', 4 - $pad );
	}
	$decoded = base64_decode( strtr( $token_raw, '-_', '+/' ), true );
	if ( false === $decoded || substr_count( $decoded, '|' ) !== 3 ) {
		return 0;
	}
	$parts = explode( '|', $decoded, 4 );
	if ( count( $parts ) !== 4 ) {
		return 0;
	}
	list( $ver, $id, $exp, $sig ) = $parts;
	if ( '1' !== (string) $ver ) {
		return 0;
	}
	$id  = absint( $id );
	$exp = (int) $exp;
	if ( ! $id || $exp < time() ) {
		return 0;
	}
	$data     = $ver . '|' . $id . '|' . $exp;
	$expected = hash_hmac( 'sha256', $data, leadwerk_theme_secure_pdf_hmac_key() );
	if ( ! is_string( $sig ) || ! hash_equals( $expected, $sig ) ) {
		return 0;
	}

	return $id;
}

/**
 * Ob der Anhang eine lesbare PDF-Datei unterhalb von wp-uploads ist.
 *
 * @param int $attachment_id Anhang-ID.
 * @return bool
 */
function leadwerk_theme_attachment_is_allowed_pdf( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( ! $attachment_id ) {
		return false;
	}
	$path = get_attached_file( $attachment_id );
	if ( ! $path || ! is_readable( $path ) ) {
		return false;
	}
	$mime = get_post_mime_type( $attachment_id );
	if ( 'application/pdf' !== $mime ) {
		return false;
	}
	$uploads = wp_get_upload_dir();
	if ( empty( $uploads['basedir'] ) ) {
		return false;
	}
	$real_file = realpath( $path );
	$real_base = realpath( $uploads['basedir'] );
	if ( ! $real_file || ! $real_base ) {
		return false;
	}
	$base = $real_base . DIRECTORY_SEPARATOR;
	if ( 0 !== strpos( $real_file, $base ) ) {
		return false;
	}

	return true;
}

/**
 * Oeffentliche Download-URL mit signiertem Token (kein direkter /uploads/-Pfad).
 *
 * @param int $attachment_id Anhang-ID.
 * @return string Leer bei ungueltigem PDF.
 */
function leadwerk_theme_get_secure_pdf_download_url( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( ! $attachment_id || ! leadwerk_theme_attachment_is_allowed_pdf( $attachment_id ) ) {
		return '';
	}
	$token = leadwerk_theme_build_secure_pdf_token( $attachment_id );
	if ( '' === $token ) {
		return '';
	}

	return add_query_arg( LEADWERK_THEME_SECURE_PDF_QUERY, $token, home_url( '/' ) );
}

/**
 * Candidate source paths for a career PDF value.
 *
 * @param mixed $value Attachment ID, source path, filename, or URL.
 * @return string[]
 */
function leadwerk_theme_career_pdf_candidate_paths( $value ) {
	$raw = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $raw || is_numeric( $raw ) ) {
		return array();
	}

	$path = $raw;
	if ( preg_match( '#^(?:https?:)?//#i', $raw ) ) {
		$url_path = parse_url( $raw, PHP_URL_PATH );
		$path     = is_string( $url_path ) ? rawurldecode( $url_path ) : '';
	}

	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '#^\./#', '', $path );
	$path = preg_replace( '#^/?wp-content/uploads/#i', '', (string) $path );
	$path = ltrim( (string) $path, '/' );

	$candidates = array();
	if ( '' !== $path && preg_match( '/\.pdf$/i', $path ) ) {
		$candidates[] = $path;
	}

	$basename = basename( $path );
	if ( '' !== $basename && preg_match( '/\.pdf$/i', $basename ) ) {
		$candidates[] = 'Fotos/' . $basename;
		$candidates[] = 'assets/images/' . $basename;
		$candidates[] = $basename;
	}

	return array_values( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) );
}

/**
 * Find an imported PDF attachment from source path or filename.
 *
 * @param mixed $value Attachment ID, source path, filename, or URL.
 * @return int Attachment ID or 0.
 */
function leadwerk_theme_find_career_pdf_attachment_id( $value ) {
	if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && is_numeric( trim( $value ) ) ) ) {
		$id = absint( $value );
		return $id && wp_get_attachment_url( $id ) ? $id : 0;
	}

	$candidates = leadwerk_theme_career_pdf_candidate_paths( $value );
	foreach ( $candidates as $candidate ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'application/pdf',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => 'leadwerk_source_path',
						'value' => $candidate,
					),
				),
			)
		);
		$ids = $query->get_posts();
		if ( ! empty( $ids[0] ) ) {
			return (int) $ids[0];
		}
	}

	foreach ( $candidates as $candidate ) {
		$basename = sanitize_file_name( basename( $candidate ) );
		if ( '' === $basename ) {
			continue;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'application/pdf',
				'fields'         => 'ids',
				'posts_per_page' => 3,
				'meta_query'     => array(
					array(
						'key'     => '_wp_attached_file',
						'value'   => $basename,
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( $query->get_posts() as $id ) {
			$path = get_attached_file( (int) $id );
			if ( $path && sanitize_file_name( basename( $path ) ) === $basename ) {
				return (int) $id;
			}
		}
	}

	return 0;
}

/**
 * Theme URL for a packaged fallback PDF.
 *
 * @param string $relative Relative theme path.
 * @return string
 */
function leadwerk_theme_career_pdf_theme_url( $relative ) {
	$relative = trim( str_replace( '\\', '/', (string) $relative ), '/' );
	if ( '' === $relative || ! defined( 'LEADWERK_THEME_DIR' ) || ! defined( 'LEADWERK_THEME_URI' ) ) {
		return '';
	}
	$path = LEADWERK_THEME_DIR . '/' . $relative;
	if ( ! is_file( $path ) ) {
		return '';
	}
	$parts = array_map( 'rawurlencode', explode( '/', $relative ) );
	return LEADWERK_THEME_URI . '/' . implode( '/', $parts );
}

/**
 * href fuer Karriere-PDF: signierte URL bei Mediathek-ID, sonst Legacy-URL.
 *
 * @param mixed $value Anhang-ID oder URL-String.
 * @return string
 */
function leadwerk_theme_resolve_career_pdf_href( $value ) {
	$id = leadwerk_theme_find_career_pdf_attachment_id( $value );
	if ( $id > 0 ) {
		$secure = leadwerk_theme_get_secure_pdf_download_url( $id );
		if ( '' !== $secure ) {
			return $secure;
		}
		$url = wp_get_attachment_url( $id );

		return $url ? $url : '';
	}

	return trim( (string) $value );
}

/**
 * href und Wert fuer HTML-Attribut download (Dateiname aus Anhang bei ID).
 *
 * @param mixed $value Anhang-ID oder URL-String.
 * @return array{0:string,1:bool|string|null} href, download-Argument fuer bind_exact_anchor_keep_svg.
 */
function leadwerk_theme_career_pdf_link_parts( $value ) {
	$href = leadwerk_theme_resolve_career_pdf_href( $value );
	if ( '' === $href || '#' === $href ) {
		return array( $href, null );
	}
	$fname = '';
	$id = leadwerk_theme_find_career_pdf_attachment_id( $value );
	if ( $id > 0 ) {
		$path = get_attached_file( $id );
		if ( $path ) {
			$fname = sanitize_file_name( basename( $path ) );
		}
	}
	if ( '' === $fname ) {
		foreach ( leadwerk_theme_career_pdf_candidate_paths( $value ) as $candidate ) {
			$fname = sanitize_file_name( basename( $candidate ) );
			if ( '' !== $fname ) {
				break;
			}
		}
	}
	if ( '' !== $fname ) {
		return array( $href, $fname );
	}

	return array( $href, true );
}

/**
 * PDF ausliefern, wenn Query-Parameter gesetzt ist.
 *
 * @return void
 */
function leadwerk_theme_serve_secure_pdf_download() {
	if ( ! isset( $_GET[ LEADWERK_THEME_SECURE_PDF_QUERY ] ) ) {
		return;
	}
	$raw = wp_unslash( $_GET[ LEADWERK_THEME_SECURE_PDF_QUERY ] );
	if ( ! is_string( $raw ) || '' === $raw ) {
		status_header( 400 );
		exit;
	}
	$id = leadwerk_theme_parse_secure_pdf_token( $raw );
	if ( ! $id || ! leadwerk_theme_attachment_is_allowed_pdf( $id ) ) {
		status_header( 403 );
		exit;
	}
	$path = get_attached_file( $id );
	if ( ! $path || ! is_readable( $path ) ) {
		status_header( 404 );
		exit;
	}
	$filename = sanitize_file_name( basename( $path ) );
	if ( '' === $filename || '.pdf' !== strtolower( substr( $filename, -4 ) ) ) {
		status_header( 403 );
		exit;
	}
	$size = filesize( $path );
	if ( false === $size ) {
		status_header( 500 );
		exit;
	}

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	header( 'Content-Length: ' . (string) $size );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- binaere Auslieferung
	readfile( $path );
	exit;
}
add_action( 'template_redirect', 'leadwerk_theme_serve_secure_pdf_download', 0 );
