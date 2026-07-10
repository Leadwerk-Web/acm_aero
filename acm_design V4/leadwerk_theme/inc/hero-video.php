<?php
/**
 * Hero video performance: preload hints and early play boot.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default poster path relative to static source root.
 *
 * @return string
 */
function leadwerk_theme_hero_video_default_poster_path() {
	return 'Fotos/Neu/hero_video_poster.webp';
}

/**
 * Default hero MP4 path relative to static source root.
 *
 * @return string
 */
function leadwerk_theme_hero_video_default_video_path() {
	return 'Fotos/ACM hero_short.mp4';
}

/**
 * Whether the current request renders the home hero video.
 *
 * @return bool
 */
function leadwerk_theme_is_home_hero_context() {
	if ( is_front_page() ) {
		return true;
	}

	return function_exists( 'leadwerk_theme_is_source_key' )
		&& ( leadwerk_theme_is_source_key( 'acm-index-v1' ) || leadwerk_theme_is_source_key( 'acm-home-v1' ) );
}

/**
 * Return a hero video URL unchanged (playback starts at 0; clip is pre-trimmed).
 *
 * @param string $url Video URL.
 * @return string
 */
function leadwerk_theme_hero_video_src_with_start( $url ) {
	return trim( (string) $url );
}

/**
 * Resolve hero poster URL for preload/output.
 *
 * @param int $poster_id Optional attachment ID from CMS field.
 * @return string
 */
function leadwerk_theme_get_hero_video_poster_url( $poster_id = 0 ) {
	$poster_id = (int) $poster_id;
	if ( $poster_id > 0 ) {
		$url = wp_get_attachment_image_url( $poster_id, 'large' );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}

	if ( function_exists( 'leadwerk_theme_static_share_image_url' ) ) {
		return leadwerk_theme_static_share_image_url( leadwerk_theme_hero_video_default_poster_path() );
	}

	return function_exists( 'leadwerk_theme_static_asset_url' )
		? leadwerk_theme_static_asset_url( leadwerk_theme_hero_video_default_poster_path() )
		: '';
}

/**
 * Resolve hero MP4 URL for preload/output.
 *
 * @param string $video_src Optional resolved video URL from CMS field.
 * @return string
 */
function leadwerk_theme_get_hero_video_file_url( $video_src = '' ) {
	$video_src = trim( (string) $video_src );
	if ( '' !== $video_src ) {
		return $video_src;
	}

	return function_exists( 'leadwerk_theme_static_asset_url' )
		? leadwerk_theme_static_asset_url( leadwerk_theme_hero_video_default_video_path() )
		: '';
}

/**
 * Output high-priority preload hints for poster + hero video on the home page.
 *
 * @return void
 */
function leadwerk_theme_hero_video_preload_hints() {
	if ( is_admin() || ! leadwerk_theme_is_home_hero_context() ) {
		return;
	}

	$poster = leadwerk_theme_get_hero_video_poster_url();
	$video  = leadwerk_theme_get_hero_video_file_url();

	if ( '' !== $poster ) {
		echo '<link rel="preload" as="image" href="' . esc_url( $poster ) . '" fetchpriority="high">' . "\n";
	}

	if ( '' !== $video ) {
		echo '<link rel="preload" as="video" href="' . esc_url( $video ) . '" type="video/mp4" fetchpriority="high">' . "\n";
	}
}
add_action( 'wp_head', 'leadwerk_theme_hero_video_preload_hints', 3 );

/**
 * Enqueue a tiny inline boot script so hero playback starts before main.js loads.
 *
 * @return void
 */
function leadwerk_theme_enqueue_hero_video_boot() {
	if ( is_admin() || ! leadwerk_theme_is_home_hero_context() ) {
		return;
	}

	$code = '(function(){var v=document.getElementById("hero-video");if(!v)return;function go(){v.classList.add("loaded","is-playing");var p=v.play();if(p&&p.catch)p.catch(function(){});}v.addEventListener("loadeddata",go,{once:true});v.addEventListener("playing",go,{once:true});v.addEventListener("error",function(){v.style.display="none";});if(v.readyState>=2)go();})();';

	wp_register_script( 'leadwerk-hero-video-boot', false, array(), LEADWERK_THEME_VERSION, false );
	wp_enqueue_script( 'leadwerk-hero-video-boot' );
	wp_add_inline_script( 'leadwerk-hero-video-boot', $code );
}
add_action( 'wp_enqueue_scripts', 'leadwerk_theme_enqueue_hero_video_boot', 5 );
