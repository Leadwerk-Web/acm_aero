<?php
/**
 * Signierte PDF-Download-URLs (Karriere / Stellenanzeigen).
 *
 * v2-Tokens sind cache-sicher (kein Ablaufdatum): Page-Cache (Hummingbird/Varnish)
 * kann HTML laenger halten als die fruehere 7-Tage-TTL von v1.
 * v1-Tokens werden weiter akzeptiert (inkl. abgelaufener), solange die Signatur stimmt.
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
 * Legacy TTL (nur noch fuer Filter-Kompatibilitaet; neue Tokens nutzen v2 ohne Ablauf).
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
	// v2: 2|id|sig — kein Expiry, kompatibel mit langfristigem Full-Page-Cache.
	$data  = '2|' . $attachment_id;
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
	if ( false === $decoded || '' === $decoded ) {
		return 0;
	}
	$pipe_count = substr_count( $decoded, '|' );

	// v2: 2|id|sig
	if ( 2 === $pipe_count ) {
		$parts = explode( '|', $decoded, 3 );
		if ( count( $parts ) !== 3 ) {
			return 0;
		}
		list( $ver, $id, $sig ) = $parts;
		if ( '2' !== (string) $ver ) {
			return 0;
		}
		$id = absint( $id );
		if ( ! $id ) {
			return 0;
		}
		$data     = $ver . '|' . $id;
		$expected = hash_hmac( 'sha256', $data, leadwerk_theme_secure_pdf_hmac_key() );
		if ( ! is_string( $sig ) || ! hash_equals( $expected, $sig ) ) {
			return 0;
		}

		return $id;
	}

	// v1: 1|id|exp|sig — Expiry wird ignoriert (gecachte HTML-Seiten).
	if ( 3 !== $pipe_count ) {
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
	$id = absint( $id );
	if ( ! $id ) {
		return 0;
	}
	$data     = $ver . '|' . $id . '|' . (int) $exp;
	$expected = hash_hmac( 'sha256', $data, leadwerk_theme_secure_pdf_hmac_key() );
	if ( ! is_string( $sig ) || ! hash_equals( $expected, $sig ) ) {
		return 0;
	}

	// #region agent log
	@file_put_contents( '/Users/atlas/Documents/Github/acm_aero/acm_design V4/.cursor/debug-884c52.log', wp_json_encode( array( 'sessionId' => '884c52', 'runId' => 'post-fix', 'hypothesisId' => 'A', 'location' => 'leadwerk-secure-pdf-download.php:v1-accept', 'message' => 'Accepted v1 token (expiry ignored for cache compat)', 'data' => array( 'id' => $id, 'exp' => (int) $exp, 'expired' => ( (int) $exp < time() ), 'now' => time() ), 'timestamp' => (int) round( microtime( true ) * 1000 ) ) ) . "\n", FILE_APPEND );
	// #endregion

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
 * href fuer Karriere-PDF: signierte URL bei Mediathek-ID, sonst Legacy-URL.
 *
 * @param mixed $value Anhang-ID oder URL-String.
 * @return string
 */
function leadwerk_theme_resolve_career_pdf_href( $value ) {
	if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && is_numeric( trim( $value ) ) ) ) {
		$id = (int) $value;
		if ( $id > 0 ) {
			$secure = leadwerk_theme_get_secure_pdf_download_url( $id );
			if ( '' !== $secure ) {
				return $secure;
			}
			$url = wp_get_attachment_url( $id );

			return $url ? $url : '';
		}
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
	if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && is_numeric( trim( $value ) ) ) ) {
		$id = (int) $value;
		if ( $id > 0 ) {
			$path = get_attached_file( $id );
			if ( $path ) {
				$fname = sanitize_file_name( basename( $path ) );
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
		// #region agent log
		@file_put_contents( '/Users/atlas/Documents/Github/acm_aero/acm_design V4/.cursor/debug-884c52.log', wp_json_encode( array( 'sessionId' => '884c52', 'runId' => 'local', 'hypothesisId' => 'C', 'location' => 'leadwerk-secure-pdf-download.php:empty-token', 'message' => 'Empty leadwerk_pdf token', 'data' => array( 'rawType' => gettype( $raw ) ), 'timestamp' => (int) round( microtime( true ) * 1000 ) ) ) . "\n", FILE_APPEND );
		// #endregion
		status_header( 400 );
		exit;
	}
	$id = leadwerk_theme_parse_secure_pdf_token( $raw );
	if ( ! $id || ! leadwerk_theme_attachment_is_allowed_pdf( $id ) ) {
		// #region agent log
		$pad  = strlen( (string) $raw ) % 4;
		$tok  = (string) $raw . ( $pad ? str_repeat( '=', 4 - $pad ) : '' );
		$dec  = base64_decode( strtr( $tok, '-_', '+/' ), true );
		$bits = ( is_string( $dec ) && '' !== $dec ) ? explode( '|', $dec, 4 ) : array();
		@file_put_contents( '/Users/atlas/Documents/Github/acm_aero/acm_design V4/.cursor/debug-884c52.log', wp_json_encode( array( 'sessionId' => '884c52', 'runId' => 'local', 'hypothesisId' => 'A', 'location' => 'leadwerk-secure-pdf-download.php:reject', 'message' => 'Secure PDF rejected', 'data' => array( 'parsedId' => (int) $id, 'allowed' => $id ? leadwerk_theme_attachment_is_allowed_pdf( $id ) : false, 'tokenParts' => $bits, 'now' => time(), 'exp' => isset( $bits[2] ) ? (int) $bits[2] : null, 'expired' => isset( $bits[2] ) ? ( (int) $bits[2] < time() ) : null ), 'timestamp' => (int) round( microtime( true ) * 1000 ) ) ) . "\n", FILE_APPEND );
		// #endregion
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
