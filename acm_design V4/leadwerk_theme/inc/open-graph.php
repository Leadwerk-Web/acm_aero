<?php
/**
 * Open Graph / social share previews for ACM pages and news.
 *
 * Supplies page-specific hero images so pasted acm.aero links show a relevant
 * visual in chat apps (WhatsApp, iMessage, Slack, LinkedIn, …).
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default share image (site-wide fallback).
 *
 * @return string Relative path under static source root.
 */
function leadwerk_theme_get_default_og_share_image_path() {
	return 'Fotos/Hangar/ACM - Hangar Baden-Baden.webp';
}

/**
 * Map Leadwerk source keys to hero/share images.
 *
 * @return array<string,string>
 */
function leadwerk_theme_get_og_share_image_map() {
	return array(
		'acm-index-v1'       => 'Fotos/Neu/hero_video_poster.webp',
		'acm-home-v1'        => 'Fotos/Neu/hero_video_poster.webp',
		'acm-thats-acm-v1'   => 'Fotos/Neu/Thats ACM.webp',
		'acm-about-v1'       => 'Fotos/Neu/Thats ACM.webp',
		'acm-charter-v1'     => 'Fotos/Neu/Charter_hero.webp',
		'acm-global-7500-v1' => 'Fotos/Neu/7500_hero.webp',
		'acm-global-6000-v1' => 'Fotos/Neu/Global 6000/Sitzgelegenheit.webp',
		'acm-global-xrs-v1'  => 'Fotos/Neu/XRS_hero.webp',
		'acm-aircraft-v1'    => 'Fotos/Neu/AircraftManagement_Hero.webp',
		'acm-maintenance-v1' => 'Fotos/Neu/Maintenance_CAMO_hero.webp',
		'acm-careers-v1'     => 'Fotos/Neu/karriere_hero.webp',
		'acm-contact-v1'     => 'Fotos/Neu/kontakt_hero.webp',
		'acm-news-v1'        => 'Fotos/Neu/Thats ACM.webp',
		'acm-impressum-v1'   => leadwerk_theme_get_default_og_share_image_path(),
		'acm-datenschutz-v1' => leadwerk_theme_get_default_og_share_image_path(),
	);
}

/**
 * Encode each path segment for safe public URLs (spaces, umlauts, …).
 *
 * @param string $relative_path Path relative to static source root.
 * @return string
 */
function leadwerk_theme_encode_static_asset_path( $relative_path ) {
	$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
	$segments      = explode( '/', $relative_path );
	$segments      = array_map( 'rawurlencode', $segments );

	return implode( '/', $segments );
}

/**
 * Resolve a static asset path, trying alternate extensions when missing on disk.
 *
 * @param string $relative_path Path relative to static source root.
 * @return string Existing relative path or original when not found.
 */
function leadwerk_theme_resolve_existing_static_asset_path( $relative_path ) {
	$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
	if ( '' === $relative_path ) {
		return '';
	}

	$base = leadwerk_theme_get_static_source_base();
	$dir  = trailingslashit( $base['dir'] );

	$candidates = array( $relative_path );
	if ( preg_match( '/\.(webp|jpe?g|png)$/i', $relative_path ) ) {
		$stem = preg_replace( '/\.(webp|jpe?g|png)$/i', '', $relative_path );
		foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $ext ) {
			$candidates[] = $stem . '.' . $ext;
		}
	} else {
		foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $ext ) {
			$candidates[] = $relative_path . '.' . $ext;
		}
	}

	$candidates = array_values( array_unique( $candidates ) );
	foreach ( $candidates as $candidate ) {
		$full = $dir . str_replace( '/', DIRECTORY_SEPARATOR, $candidate );
		if ( is_file( $full ) ) {
			return $candidate;
		}
	}

	return $relative_path;
}

/**
 * Public absolute URL for a static share image path.
 *
 * @param string $relative_path Path relative to static source root.
 * @return string
 */
function leadwerk_theme_static_share_image_url( $relative_path ) {
	$relative_path = leadwerk_theme_resolve_existing_static_asset_path( $relative_path );
	if ( '' === $relative_path ) {
		return '';
	}

	$base = leadwerk_theme_get_static_source_base();

	return trailingslashit( $base['url'] ) . leadwerk_theme_encode_static_asset_path( $relative_path );
}

/**
 * Resolve the Open Graph image URL for the current or given post.
 *
 * @param int $post_id Optional post ID (defaults to queried object).
 * @return string Absolute image URL or empty string.
 */
function leadwerk_theme_get_og_image_url( $post_id = 0 ) {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	if ( $post_id <= 0 ) {
		return leadwerk_theme_static_share_image_url( leadwerk_theme_get_default_og_share_image_path() );
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return leadwerk_theme_static_share_image_url( leadwerk_theme_get_default_og_share_image_path() );
	}

	if ( 'acm_news' === $post->post_type ) {
		if ( function_exists( 'leadwerk_theme_get_acm_news_card_image_url' ) ) {
			$url = leadwerk_theme_get_acm_news_card_image_url( $post );
			if ( is_string( $url ) && '' !== $url && ! preg_match( '#^data:#i', $url ) ) {
				return $url;
			}
		}

		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'large' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
	}

	$source_key = (string) get_post_meta( $post_id, 'leadwerk_source_key', true );
	$map        = leadwerk_theme_get_og_share_image_map();
	$path       = isset( $map[ $source_key ] ) ? (string) $map[ $source_key ] : leadwerk_theme_get_default_og_share_image_path();

	/**
	 * Filter the relative static path before URL resolution.
	 *
	 * @param string $path       Relative asset path.
	 * @param string $source_key Leadwerk source key.
	 * @param int    $post_id    Post ID.
	 */
	$path = (string) apply_filters( 'leadwerk_theme_og_share_image_path', $path, $source_key, $post_id );
	$url  = leadwerk_theme_static_share_image_url( $path );

	/**
	 * Filter the resolved Open Graph image URL.
	 *
	 * @param string $url        Absolute image URL.
	 * @param int    $post_id    Post ID.
	 * @param string $source_key Leadwerk source key.
	 */
	return (string) apply_filters( 'leadwerk_theme_og_image_url', $url, $post_id, $source_key );
}

/**
 * Yoast SEO: supply og:image when no custom social image is configured.
 *
 * @param string $image Existing image URL from Yoast.
 * @return string
 */
function leadwerk_theme_yoast_opengraph_image( $image ) {
	if ( is_string( $image ) && '' !== trim( $image ) ) {
		return $image;
	}

	if ( ! is_singular() ) {
		return $image;
	}

	$resolved = leadwerk_theme_get_og_image_url();

	return '' !== $resolved ? $resolved : $image;
}
add_filter( 'wpseo_opengraph_image', 'leadwerk_theme_yoast_opengraph_image', 20 );

/**
 * Yoast SEO: mirror image for Twitter cards.
 *
 * @param string $image Existing Twitter image URL from Yoast.
 * @return string
 */
function leadwerk_theme_yoast_twitter_image( $image ) {
	if ( is_string( $image ) && '' !== trim( $image ) ) {
		return $image;
	}

	if ( ! is_singular() ) {
		return $image;
	}

	$resolved = leadwerk_theme_get_og_image_url();

	return '' !== $resolved ? $resolved : $image;
}
add_filter( 'wpseo_twitter_image', 'leadwerk_theme_yoast_twitter_image', 20 );

/**
 * Build share title for Open Graph fallback output.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_get_og_title( $post_id = 0 ) {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	if ( $post_id <= 0 ) {
		return wp_get_document_title();
	}

	return wp_strip_all_tags( get_the_title( $post_id ) );
}

/**
 * Build share description for Open Graph fallback output.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_get_og_description( $post_id = 0 ) {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	if ( $post_id <= 0 ) {
		return '';
	}

	$meta = trim( (string) get_post_meta( $post_id, 'leadwerk_meta_description', true ) );
	if ( '' !== $meta ) {
		return $meta;
	}

	$yoast = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );
	if ( '' !== $yoast ) {
		return $yoast;
	}

	$post = get_post( $post_id );
	if ( $post instanceof WP_Post && '' !== trim( (string) $post->post_excerpt ) ) {
		return wp_strip_all_tags( $post->post_excerpt );
	}

	return '';
}

/**
 * Output Open Graph tags when Yoast SEO is not active.
 *
 * @return void
 */
function leadwerk_theme_head_open_graph() {
	if ( defined( 'WPSEO_VERSION' ) || ! is_singular() ) {
		return;
	}

	$post_id     = (int) get_queried_object_id();
	$image_url   = leadwerk_theme_get_og_image_url( $post_id );
	$title       = leadwerk_theme_get_og_title( $post_id );
	$description = leadwerk_theme_get_og_description( $post_id );
	$url         = get_permalink( $post_id );
	$og_type     = 'acm_news' === get_post_type( $post_id ) ? 'article' : 'website';

	echo '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', determine_locale() ) ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
	if ( '' !== $title ) {
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	}
	if ( '' !== $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( is_string( $url ) && '' !== $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	}
	echo '<meta property="og:site_name" content="ACM AIR CHARTER">' . "\n";
	if ( '' !== $image_url ) {
		echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	if ( '' !== $image_url ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'leadwerk_theme_head_open_graph', 6 );
