<?php
/**
 * Single static export root: prefer sibling leadwerk_importer/source_assets, else theme directory.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Physical directory and public URL for bundled static assets (HTML shell paths: assets/, Fotos/).
 *
 * Filter `leadwerk_theme_static_source_base` may return array( 'dir' => absolute path, 'url' => absolute URL ).
 *
 * @return array{dir:string,url:string}
 */
function leadwerk_theme_get_static_source_base() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	$filtered = apply_filters( 'leadwerk_theme_static_source_base', null );
	if ( is_array( $filtered ) && ! empty( $filtered['dir'] ) && ! empty( $filtered['url'] ) && is_dir( (string) $filtered['dir'] ) ) {
		$cached = array(
			'dir' => wp_normalize_path( untrailingslashit( (string) $filtered['dir'] ) ),
			'url' => trailingslashit( esc_url_raw( (string) $filtered['url'] ) ),
		);
		return $cached;
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$plugins_dir = trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'plugins';
		$roots       = array(
			$plugins_dir . '/leadwerk_importer',
			$plugins_dir . '/leadwerk-importer',
		);
		foreach ( $roots as $plugin_root ) {
			$bootstrap = $plugin_root . '/leadwerk-importer.php';
			$assets    = $plugin_root . '/source_assets';
			if ( is_file( $bootstrap ) && is_dir( $assets ) ) {
				$url    = plugins_url( 'source_assets', $bootstrap );
				$cached = array(
					'dir' => wp_normalize_path( untrailingslashit( $assets ) ),
					'url' => trailingslashit( esc_url_raw( $url ) ),
				);
				return $cached;
			}
		}
	}

	$cached = array(
		'dir' => wp_normalize_path( untrailingslashit( LEADWERK_THEME_DIR ) ),
		'url' => trailingslashit( esc_url_raw( untrailingslashit( LEADWERK_THEME_URI ) ) ),
	);
	return $cached;
}

/**
 * Public URL for a path relative to the static source root (e.g. Fotos/Neu/foo.jpg, assets/css/...).
 *
 * @param string $relative_path Path with forward slashes, no leading slash required.
 * @return string
 */
function leadwerk_theme_static_asset_url( $relative_path ) {
	$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
	$base          = leadwerk_theme_get_static_source_base();
	return trailingslashit( $base['url'] ) . $relative_path;
}

/**
 * Emblem: prefer assets/images copy, else Fotos/ (matches static site layout).
 *
 * @return string
 */
function leadwerk_theme_get_emblem_asset_url() {
	$base = leadwerk_theme_get_static_source_base();
	$dir  = $base['dir'];
	$url  = trailingslashit( $base['url'] );
	foreach ( array( 'assets/images/Emblem.svg', 'Fotos/Emblem.svg' ) as $rel ) {
		$full = $dir . '/' . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
		if ( is_file( $full ) ) {
			return $url . ltrim( str_replace( '\\', '/', $rel ), '/' );
		}
	}
	return $url . 'assets/images/Emblem.svg';
}

/**
 * Exact HTML shell file: theme source_shells first, then leadwerk_importer/source_assets (same filenames).
 *
 * @param string $file_name File under source_shells/, e.g. index.html or news/foo.html.
 * @return string Absolute filesystem path or empty.
 */
function leadwerk_theme_resolve_exact_shell_file( $file_name ) {
	$file_name = str_replace( '\\', '/', (string) $file_name );
	$file_name = ltrim( $file_name, '/' );
	if ( '' === $file_name || false !== strpos( $file_name, '..' ) ) {
		return '';
	}

	$theme_path = trailingslashit( LEADWERK_THEME_DIR ) . 'source_shells/' . $file_name;
	if ( is_file( $theme_path ) ) {
		return wp_normalize_path( $theme_path );
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$plugins_dir = trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'plugins';
		foreach ( array( 'leadwerk_importer', 'leadwerk-importer' ) as $slug ) {
			$bootstrap = $plugins_dir . '/' . $slug . '/leadwerk-importer.php';
			$candidate  = $plugins_dir . '/' . $slug . '/source_assets/' . $file_name;
			if ( is_file( $bootstrap ) && is_file( $candidate ) ) {
				return wp_normalize_path( $candidate );
			}
		}
	}

	/**
	 * Override path to an exact shell HTML file (after theme + importer defaults).
	 *
	 * @param string $resolved    Empty or absolute path.
	 * @param string $file_name   Requested relative path under shells / source_assets.
	 * @param string $theme_path  First candidate (theme source_shells).
	 */
	$filtered = apply_filters( 'leadwerk_theme_exact_shell_file', '', $file_name, $theme_path );
	if ( is_string( $filtered ) && '' !== $filtered && is_file( $filtered ) ) {
		return wp_normalize_path( $filtered );
	}

	return '';
}
