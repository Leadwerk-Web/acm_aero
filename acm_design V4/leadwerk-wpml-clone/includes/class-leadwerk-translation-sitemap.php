<?php
/**
 * WordPress native sitemap hreflang support.
 *
 * Adds <xhtml:link rel="alternate"> hreflang entries to the WordPress 5.5+
 * built-in sitemap, as well as hooks for Yoast SEO and RankMath.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_Sitemap {

	/**
	 * Register sitemap hooks.
	 *
	 * @return void
	 */
	public static function init() {
		/* WordPress 5.5+ native sitemap */
		add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'add_hreflang_to_sitemap_entry' ), 10, 3 );

		/* Yoast SEO */
		add_filter( 'wpseo_sitemap_url', array( __CLASS__, 'add_hreflang_to_yoast_url' ), 10, 2 );

		/* RankMath */
		add_filter( 'rank_math/sitemap/url', array( __CLASS__, 'add_hreflang_to_rankmath_url' ), 10, 2 );

		/* Expand the WP sitemap to include translated pages */
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'include_translated_posts_in_sitemap' ), 10, 2 );
	}

	/**
	 * Add hreflang alternates to a WP native sitemap entry.
	 *
	 * @param array<string,mixed> $entry    Sitemap entry.
	 * @param WP_Post             $post     Post object.
	 * @param string              $post_type Post type.
	 * @return array<string,mixed>
	 */
	public static function add_hreflang_to_sitemap_entry( $entry, $post, $post_type ) {
		if ( ! $post instanceof WP_Post || ! Leadwerk_Translation_API::is_translatable_post_type( $post->post_type ) ) {
			return $entry;
		}

		$source_id    = Leadwerk_Translation_API::get_source_post_id( $post->ID );
		$source_id    = $source_id ?: $post->ID;
		$translations = Leadwerk_Translation_API::get_translations( $source_id );

		if ( count( $translations ) < 2 ) {
			return $entry;
		}

		/* The WP sitemap spec doesn't natively support xhtml:link.
		 * We store the alternates under a custom key that can be
		 * consumed by custom sitemap XSL stylesheets or plugins.       */
		$alternates = array();
		foreach ( $translations as $lang => $translation_id ) {
			$translation_post = get_post( $translation_id );
			if ( ! $translation_post instanceof WP_Post || 'publish' !== $translation_post->post_status ) {
				continue;
			}

			$url = Leadwerk_Translation_API::get_public_post_url( $translation_id );
			if ( ! $url ) {
				continue;
			}

			$locale = Leadwerk_Translation_Router::get_locale_for_language( $lang );
			$bcp47  = $locale ? str_replace( '_', '-', $locale ) : $lang;

			$alternates[] = array(
				'hreflang' => $bcp47,
				'href'     => $url,
			);
		}

		if ( ! empty( $alternates ) ) {
			$entry['alternates'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Add hreflang to Yoast SEO sitemap URLs.
	 *
	 * @param string               $url  Sitemap URL entry (XML string).
	 * @param array<string,string> $data URL data array.
	 * @return string
	 */
	public static function add_hreflang_to_yoast_url( $url, $data ) {
		if ( empty( $data['loc'] ) ) {
			return $url;
		}

		$post_id = url_to_postid( $data['loc'] );
		if ( ! $post_id ) {
			return $url;
		}

		$alternates = self::build_alternates_xml( $post_id );
		if ( '' === $alternates ) {
			return $url;
		}

		/* Insert the xhtml:link elements before the closing </url> */
		return str_replace( '</url>', $alternates . '</url>', $url );
	}

	/**
	 * Add hreflang to RankMath sitemap URLs.
	 *
	 * @param string               $url  Sitemap URL entry (XML string).
	 * @param array<string,string> $data URL data array.
	 * @return string
	 */
	public static function add_hreflang_to_rankmath_url( $url, $data ) {
		return self::add_hreflang_to_yoast_url( $url, $data );
	}

	/**
	 * Include translated posts in WP native sitemap.
	 *
	 * @param array<string,mixed> $args      Query arguments.
	 * @param string              $post_type Post type.
	 * @return array<string,mixed>
	 */
	public static function include_translated_posts_in_sitemap( $args, $post_type ) {
		/* Remove any language filtering that might exclude translations */
		if ( isset( $args['post__in'] ) ) {
			unset( $args['post__in'] );
		}

		return $args;
	}

	/**
	 * Build xhtml:link XML for one post's translations.
	 *
	 * @param int $post_id Post ID.
	 * @return string XML snippet.
	 */
	protected static function build_alternates_xml( $post_id ) {
		$source_id    = Leadwerk_Translation_API::get_source_post_id( $post_id );
		$source_id    = $source_id ?: $post_id;
		$translations = Leadwerk_Translation_API::get_translations( $source_id );

		if ( count( $translations ) < 2 ) {
			return '';
		}

		$xml = '';
		foreach ( $translations as $lang => $translation_id ) {
			$translation_post = get_post( $translation_id );
			if ( ! $translation_post instanceof WP_Post || 'publish' !== $translation_post->post_status ) {
				continue;
			}

			$url    = Leadwerk_Translation_API::get_public_post_url( $translation_id );
			$locale = Leadwerk_Translation_Router::get_locale_for_language( $lang );
			$bcp47  = $locale ? str_replace( '_', '-', $locale ) : $lang;

			if ( $url ) {
				$xml .= "\t" . '<xhtml:link rel="alternate" hreflang="' . esc_attr( $bcp47 ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
		}

		return $xml;
	}
}
