<?php
/**
 * ACM Leadwerk theme integration.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_THEME_VERSION', '1.0.12' );
define( 'LEADWERK_THEME_DIR', get_template_directory() );
define( 'LEADWERK_THEME_URI', get_template_directory_uri() );
define( 'LEADWERK_THEME_ACM_NEWS_FILTER_SLUG_META', 'acm_news_filter_slug' );
/** Leadwerk-Feld / post_meta: Veröffentlichungsdatum (Y-m-d) für Sortierung und Anzeige. */
define( 'LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META', 'acm_news_publication_date' );
/** Post meta (Seiten): Wert `1` = gleicher schlichter Header wie News-Artikel (heller Balken, dunkles Logo). */
define( 'LEADWERK_THEME_SIMPLE_HEADER_META', 'leadwerk_simple_header' );
define( 'LEADWERK_THEME_SIMPLE_PAGE_TEMPLATE', 'template-acm-simple-page.php' );

$leadwerk_structured_render_file = LEADWERK_THEME_DIR . '/inc/structured-acm-render.php';
$leadwerk_structured_render_alt  = LEADWERK_THEME_DIR . '/inc/structured-finora-render.php';
if ( is_file( $leadwerk_structured_render_file ) ) {
	require_once $leadwerk_structured_render_file;
} elseif ( is_file( $leadwerk_structured_render_alt ) ) {
	require_once $leadwerk_structured_render_alt;
}

$leadwerk_secure_pdf = LEADWERK_THEME_DIR . '/inc/leadwerk-secure-pdf-download.php';
if ( is_file( $leadwerk_secure_pdf ) ) {
	require_once $leadwerk_secure_pdf;
}

$leadwerk_exact_render_file = LEADWERK_THEME_DIR . '/inc/exact-acm-render.php';
$leadwerk_exact_render_alt  = LEADWERK_THEME_DIR . '/inc/exact-finora-render.php';
if ( is_file( $leadwerk_exact_render_file ) ) {
	require_once $leadwerk_exact_render_file;
} elseif ( is_file( $leadwerk_exact_render_alt ) ) {
	require_once $leadwerk_exact_render_alt;
}

/**
 * Theme setup.
 *
 * @return void
 */
function leadwerk_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'post-thumbnails', array( 'post', 'page', 'acm_news' ) );
	remove_theme_support( 'block-templates' );
	remove_theme_support( 'block-template-parts' );
}
add_action( 'after_setup_theme', 'leadwerk_theme_setup' );
add_filter( 'leadwerk_render_floating_switcher', '__return_false' );

/**
 * Register the ACM News custom post type.
 *
 * @return void
 */
function leadwerk_theme_register_acm_news_cpt() {
	if ( post_type_exists( 'acm_news' ) ) {
		return;
	}

	$labels = array(
		'name'               => 'ACM News',
		'singular_name'      => 'News-Beitrag',
		'menu_name'          => 'ACM News',
		'add_new'            => 'Neuen Beitrag',
		'add_new_item'       => 'Neuen News-Beitrag erstellen',
		'edit_item'          => 'News-Beitrag bearbeiten',
		'new_item'           => 'Neuer News-Beitrag',
		'view_item'          => 'News-Beitrag ansehen',
		'search_items'       => 'News durchsuchen',
		'not_found'          => 'Keine News gefunden',
		'not_found_in_trash' => 'Keine News im Papierkorb',
		'all_items'          => 'Alle News',
	);

	// has_archive false: vermeidet Rewrite-Konflikt mit der WordPress-Seite slug "news" (statische Uebersicht).
	register_post_type( 'acm_news', array(
		'labels'        => $labels,
		'public'        => true,
		'has_archive'   => false,
		'menu_icon'     => 'dashicons-megaphone',
		'menu_position' => 5,
		'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
		'rewrite'       => array( 'slug' => 'news', 'with_front' => false ),
		'show_in_rest'  => true,
	) );
}
add_action( 'init', 'leadwerk_theme_register_acm_news_cpt' );

/**
 * Classic editor for ACM News: sichtbares Beitragsbild, Auszug, vertrautes UI.
 *
 * @param bool   $use       Whether to use block editor.
 * @param string $post_type Post type.
 * @return bool
 */
function leadwerk_theme_use_block_editor_for_acm_news( $use, $post_type ) {
	return ( 'acm_news' === $post_type ) ? false : $use;
}
add_filter( 'use_block_editor_for_post_type', 'leadwerk_theme_use_block_editor_for_acm_news', 10, 2 );

/**
 * Labels for news filter slugs (news page filter bar + card pills).
 *
 * @return array<string,string>
 */
function leadwerk_theme_get_acm_news_filter_slug_choices() {
	return array(
		'unternehmen' => 'Unternehmen',
		'operations'  => 'Operations',
		'flotte'      => 'Flotte',
		'maintenance' => 'Maintenance',
		'partner'     => 'Partner',
		'handling'    => 'Handling',
		'sicherheit'  => 'Sicherheit',
	);
}

/**
 * Meta box: Kategorie fuer News-Filter (data-category).
 *
 * @param WP_Post $post Post.
 * @return void
 */
function leadwerk_theme_render_acm_news_filter_metabox( $post ) {
	if ( ! $post instanceof WP_Post || 'acm_news' !== $post->post_type ) {
		return;
	}
	wp_nonce_field( 'leadwerk_theme_acm_news_filter_save', 'leadwerk_theme_acm_news_filter_nonce' );
	$key     = LEADWERK_THEME_ACM_NEWS_FILTER_SLUG_META;
	$current = sanitize_title( (string) get_post_meta( $post->ID, $key, true ) );
	$choices = leadwerk_theme_get_acm_news_filter_slug_choices();
	if ( '' === $current || ! isset( $choices[ $current ] ) ) {
		$current = 'unternehmen';
	}
	echo '<p class="description">' . esc_html__( 'Steuert die Zuordnung zu den Filter-Buttons auf der News-Uebersicht.', 'leadwerk-theme' ) . '</p>';
	echo '<label for="leadwerk_acm_news_filter_slug" class="screen-reader-text">' . esc_html__( 'News-Kategorie', 'leadwerk-theme' ) . '</label>';
	echo '<select name="leadwerk_acm_news_filter_slug" id="leadwerk_acm_news_filter_slug" style="max-width:100%;">';
	foreach ( $choices as $slug => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $slug ),
			selected( $current, $slug, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
}

/**
 * Register ACM News meta boxes.
 *
 * @return void
 */
function leadwerk_theme_register_acm_news_metaboxes() {
	add_meta_box(
		'leadwerk_acm_news_filter',
		__( 'News-Kategorie (Filter)', 'leadwerk-theme' ),
		'leadwerk_theme_render_acm_news_filter_metabox',
		'acm_news',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'leadwerk_theme_register_acm_news_metaboxes' );

/**
 * Save news filter slug meta.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function leadwerk_theme_save_acm_news_filter_meta( $post_id ) {
	if ( ! isset( $_POST['leadwerk_theme_acm_news_filter_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leadwerk_theme_acm_news_filter_nonce'] ) ), 'leadwerk_theme_acm_news_filter_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$pt = get_post_type( $post_id );
	if ( 'acm_news' !== $pt ) {
		return;
	}
	$key     = LEADWERK_THEME_ACM_NEWS_FILTER_SLUG_META;
	$choices = leadwerk_theme_get_acm_news_filter_slug_choices();
	$raw     = isset( $_POST['leadwerk_acm_news_filter_slug'] )
		? sanitize_title( wp_unslash( $_POST['leadwerk_acm_news_filter_slug'] ) )
		: '';
	if ( isset( $choices[ $raw ] ) ) {
		update_post_meta( $post_id, $key, $raw );
	} else {
		update_post_meta( $post_id, $key, 'unternehmen' );
	}
}
add_action( 'save_post_acm_news', 'leadwerk_theme_save_acm_news_filter_meta' );

/**
 * Nach Speichern: fehlendes Veröffentlichungsdatum aus dem WordPress-Beitragsdatum (post_date) setzen.
 *
 * Läuft über wp_after_insert_post, damit Importer/andere Schritte Meta danach noch setzen dürfen.
 *
 * @param int     $post_id Post-ID.
 * @param WP_Post $post    Beitrag.
 * @param bool    $update  True bei Aktualisierung.
 * @return void
 */
function leadwerk_theme_after_insert_acm_news_publication_meta( $post_id, $post, $update ) {
	if ( ! $post instanceof WP_Post || 'acm_news' !== $post->post_type ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	$legacy = get_post_meta( $post_id, '_leadwerk_news_datetime', true );
	$cf     = get_post_meta( $post_id, LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META, true );
	if ( ( is_string( $legacy ) && '' !== trim( $legacy ) ) || ( is_string( $cf ) && '' !== trim( $cf ) ) ) {
		return;
	}
	$ts = strtotime( $post->post_date );
	if ( ! $ts ) {
		return;
	}
	$ymd = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
	update_post_meta( $post_id, '_leadwerk_news_datetime', $ymd );
	update_post_meta( $post_id, LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META, $ymd );
}
add_action( 'wp_after_insert_post', 'leadwerk_theme_after_insert_acm_news_publication_meta', 20, 3 );

/**
 * Permalink fuer acm_news immer /news/{slug}/ (Admin „URL“ + konsistente Links).
 *
 * @param string  $post_link Permalink.
 * @param WP_Post $post     Beitrag.
 * @return string
 */
function leadwerk_theme_acm_news_post_type_link( $post_link, $post ) {
	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		return $post_link;
	}
	if ( ! $post instanceof WP_Post || 'acm_news' !== $post->post_type ) {
		return $post_link;
	}
	if ( '' === (string) $post->post_name ) {
		return $post_link;
	}
	return home_url( user_trailingslashit( 'news/' . $post->post_name ) );
}
add_filter( 'post_type_link', 'leadwerk_theme_acm_news_post_type_link', 10, 2 );

/**
 * Einmalig Rewrite-Regeln flushen (nach CPT-Aenderung / fehlendem /news/-Prefix).
 *
 * @return void
 */
function leadwerk_theme_maybe_flush_acm_news_rewrites() {
	if ( ! post_type_exists( 'acm_news' ) ) {
		return;
	}
	$flag = 'leadwerk_flush_acm_news_rw_v3';
	if ( get_option( $flag ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( $flag, '1' );
}
add_action( 'init', 'leadwerk_theme_maybe_flush_acm_news_rewrites', 999 );

/**
 * Enqueue static ACM assets.
 *
 * @return void
 */
function leadwerk_theme_enqueue_assets() {
	$current_lang = leadwerk_theme_get_current_lang();
	$default_lang = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::get_default_language() : 'de';
	$is_default   = $current_lang === $default_lang;

	// Core stylesheet.
	wp_enqueue_style( 'leadwerk-theme-core', get_stylesheet_uri(), array(), LEADWERK_THEME_VERSION );

	// Google Fonts: Cormorant Garamond (headings) + Inter (body) — ACM design system.
	wp_enqueue_style( 'leadwerk-theme-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Inter:wght@300;400;500;600&display=swap', array(), null );

	// Tailwind CSS CDN — used by all ACM source shells.
	wp_enqueue_script( 'tailwindcss', 'https://cdn.tailwindcss.com', array(), null, false );
	wp_enqueue_script( 'leadwerk-tailwind-config', LEADWERK_THEME_URI . '/js/tailwind-config.js', array( 'tailwindcss' ), LEADWERK_THEME_VERSION, false );

	// Base CSS — shared styles across all pages.
	wp_enqueue_style( 'leadwerk-theme-base', LEADWERK_THEME_URI . '/css/base.css', array( 'leadwerk-theme-core' ), LEADWERK_THEME_VERSION );

	if ( function_exists( 'leadwerk_theme_get_structured_inline_styles' ) ) {
		wp_add_inline_style( 'leadwerk-theme-base', leadwerk_theme_get_structured_inline_styles() );
	}

	// Page-specific CSS/JS based on source_key.
	$source_key = leadwerk_theme_get_current_source_key();
	$page_assets = array(
		'acm-index-v1'          => 'index',
		'acm-thats-acm-v1'      => 'thats-acm',
		'acm-charter-v1'        => 'charter',
		'acm-global-7500-v1'    => 'fleet',
		'acm-global-6000-v1'    => 'fleet',
		'acm-global-xrs-v1'     => 'fleet',
		'acm-aircraft-v1'       => 'aircraft',
		'acm-maintenance-v1'    => 'maintenance',
		'acm-careers-v1'        => 'karriere',
		'acm-contact-v1'        => 'kontakt',
		'acm-news-v1'           => 'news',
	);

	if ( isset( $page_assets[ $source_key ] ) ) {
		$slug     = $page_assets[ $source_key ];
		$css_file = '/css/page-' . $slug . '.css';
		$js_file  = '/js/page-' . $slug . '.js';

		if ( file_exists( get_stylesheet_directory() . $css_file ) ) {
			wp_enqueue_style( 'leadwerk-page-' . $slug, LEADWERK_THEME_URI . $css_file, array( 'leadwerk-theme-base' ), LEADWERK_THEME_VERSION );
		}
		if ( file_exists( get_stylesheet_directory() . $js_file ) ) {
			wp_enqueue_script( 'leadwerk-page-' . $slug, LEADWERK_THEME_URI . $js_file, array(), LEADWERK_THEME_VERSION, true );
		}
	}

	// News single, Impressum, Datenschutz & Seiten mit leadwerk_simple_header=1 — gleiche Header-/Shell-Styles.
	if ( function_exists( 'leadwerk_theme_is_simple_header_context' ) && leadwerk_theme_is_simple_header_context() ) {
		wp_enqueue_style( 'leadwerk-page-news-article', LEADWERK_THEME_URI . '/css/page-news-article.css', array( 'leadwerk-theme-base' ), LEADWERK_THEME_VERSION );
	}

	// Global mobile QA overrides â€” load after page styles so mobile fixes can win.
	$mobile_qa_file = LEADWERK_THEME_DIR . '/css/mobile-qa.css';
	if ( file_exists( $mobile_qa_file ) ) {
		$mobile_qa_deps = array( 'leadwerk-theme-base' );
		if ( isset( $slug ) && wp_style_is( 'leadwerk-page-' . $slug, 'enqueued' ) ) {
			$mobile_qa_deps[] = 'leadwerk-page-' . $slug;
		}
		if ( wp_style_is( 'leadwerk-page-news-article', 'enqueued' ) ) {
			$mobile_qa_deps[] = 'leadwerk-page-news-article';
		}

		wp_enqueue_style(
			'leadwerk-theme-mobile-qa',
			LEADWERK_THEME_URI . '/css/mobile-qa.css',
			$mobile_qa_deps,
			(string) filemtime( $mobile_qa_file )
		);
	}

	// Main JS.
	wp_enqueue_script( 'leadwerk-theme-main', LEADWERK_THEME_URI . '/js/main.js', array(), LEADWERK_THEME_VERSION, true );
	wp_localize_script(
		'leadwerk-theme-main',
		'leadwerkThemeData',
		array(
			'locale'              => $current_lang,
			'defaultLang'         => $default_lang,
			'wpformsTranslations' => $is_default ? array() : leadwerk_theme_get_wpforms_translations( $current_lang ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'leadwerk_theme_enqueue_assets' );

/**
 * Truncate a human-readable SEO title for Yoast pixel/width hints (character-based heuristic).
 *
 * @param string $title      Raw title.
 * @param int    $max_chars  Maximum characters before ellipsis.
 * @return string
 */
function leadwerk_theme_truncate_seo_title_for_yoast( $title, $max_chars = 58 ) {
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return '';
	}
	if ( $max_chars < 8 ) {
		$max_chars = 8;
	}
	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $title ) > $max_chars ) {
		return rtrim( mb_substr( $title, 0, $max_chars - 1 ) ) . '…';
	}
	if ( strlen( $title ) > $max_chars ) {
		return rtrim( substr( $title, 0, $max_chars - 1 ) ) . '…';
	}

	return $title;
}

/**
 * Build rendered page HTML for Yoast analysis on field-driven pages.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_get_yoast_analysis_content( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! class_exists( 'Leadwerk_Content_Schema' ) || ! function_exists( 'get_field' ) ) {
		return '';
	}

	$group = Leadwerk_Content_Schema::get_group_for_post( $post_id );
	if ( ! $group || empty( $group['field_name'] ) ) {
		return '';
	}

	$field_name = $group['field_name'];
	$value      = get_field( $field_name, $post_id );

	$content = '';
	if ( function_exists( 'leadwerk_theme_render_exact_page_group' ) ) {
		$content = leadwerk_theme_render_exact_page_group( $group, $value, $post_id );
	} else {
		$content = leadwerk_theme_render_current_page_content( $post_id );
	}

	if ( '' === trim( wp_strip_all_tags( $content ) ) && false === strpos( $content, '<img' ) ) {
		return '';
	}

	$content = (string) preg_replace( '#<script[^>]*>.*?</script>#is', '', $content );
	$content = (string) preg_replace( '#<style[^>]*>.*?</style>#is', '', $content );

	$clean_content = wp_kses_post( $content );
	$clean_content = (string) str_replace( array( "\r", "\n", "\t" ), ' ', $clean_content );
	$clean_content = (string) preg_replace( '/\s+/', ' ', $clean_content );

	return trim( $clean_content );
}

/**
 * Rebuild Yoast SEO Indexable for one post (admin list dots, admin bar) after meta-only changes.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function leadwerk_theme_rebuild_yoast_post_indexable( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'YoastSEO' ) ) {
		return;
	}
	if ( ! class_exists( '\Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher', false ) ) {
		return;
	}

	try {
		$yoast = YoastSEO();
		if ( ! is_object( $yoast ) || ! isset( $yoast->classes ) || ! is_object( $yoast->classes ) || ! method_exists( $yoast->classes, 'get' ) ) {
			return;
		}

		$watcher = $yoast->classes->get( \Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::class );
		if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
			$watcher->build_indexable( $post_id );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return;
	}
}

/**
 * After saving a Leadwerk-managed page, refresh Yoast indexables (ACF-only saves may skip wp_insert_post).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post.
 * @return void
 */
function leadwerk_theme_leadwerk_page_yoast_indexable_touch( $post_id, $post, $update ) {
	unset( $update );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( '' === (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) ) {
		return;
	}

	leadwerk_theme_rebuild_yoast_post_indexable( $post_id );
}

add_action( 'save_post', 'leadwerk_theme_leadwerk_page_yoast_indexable_touch', 99, 3 );

/**
 * Feed rendered Leadwerk page content into Yoast's content analysis.
 *
 * Yoast analyses the editor content by default. Our ACM pages render from
 * Leadwerk/ACF fields and exact source shells, so the editor can appear empty
 * even when the public page contains headings, links, images and copy.
 *
 * @param string $hook_suffix Current admin hook.
 * @return void
 */
function leadwerk_theme_enqueue_admin_yoast_analysis( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! class_exists( 'WPSEO_Options' ) || ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base ) {
		return;
	}

	$post_id = 0;
	if ( isset( $_GET['post'] ) ) {
		$post_id = (int) $_GET['post'];
	} elseif ( isset( $_POST['post_ID'] ) ) {
		$post_id = (int) $_POST['post_ID'];
	}

	if ( $post_id <= 0 ) {
		return;
	}

	$analysis_content = leadwerk_theme_get_yoast_analysis_content( $post_id );
	if ( '' === $analysis_content ) {
		return;
	}

	$max_bytes = (int) apply_filters( 'leadwerk_yoast_analysis_inline_max_bytes', 350000 );
	if ( $max_bytes > 0 && strlen( $analysis_content ) > $max_bytes ) {
		$analysis_content = substr( $analysis_content, 0, $max_bytes );
	}

	$payload = array(
		'postId'          => $post_id,
		'renderedContent' => $analysis_content,
	);
	$json    = wp_json_encode(
		$payload,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
	);
	if ( false === $json ) {
		$payload['renderedContent'] = substr( wp_strip_all_tags( $analysis_content ), 0, 60000 );
		$json                       = wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
	}
	if ( false === $json ) {
		return;
	}

	wp_enqueue_script(
		'leadwerk-admin-yoast-analysis',
		LEADWERK_THEME_URI . '/js/admin-yoast-analysis.js',
		array(),
		LEADWERK_THEME_VERSION,
		true
	);

	wp_add_inline_script(
		'leadwerk-admin-yoast-analysis',
		'window.leadwerkYoastAnalysis = ' . $json . ';',
		'before'
	);
}
add_action( 'admin_enqueue_scripts', 'leadwerk_theme_enqueue_admin_yoast_analysis', 100 );

/**
 * Start output buffering early so we can replace Complianz banner strings.
 *
 * Complianz stores its banner text as wp_options (not gettext .mo strings),
 * so switch_to_locale / load_plugin_textdomain has no effect on the banner.
 * We capture the full page HTML and do a targeted string replacement for
 * the cmplz-cookiebanner-container block.
 *
 * @return void
 */
function leadwerk_theme_cmplz_ob_start() {
if ( is_admin() ) {
return;
}

$lang         = leadwerk_theme_get_current_lang();
$default_lang = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::get_default_language() : 'de';

if ( $lang === $default_lang ) {
return;
}

ob_start( 'leadwerk_theme_cmplz_replace_banner_strings' );
}

/**
 * Output buffer callback: replace German Complianz banner strings with
 * the current language translations.
 *
 * @param string $html Full page HTML.
 * @return string
 */
function leadwerk_theme_cmplz_replace_banner_strings( $html ) {
/* Only process if the banner container is present. */
if ( false === strpos( $html, 'cmplz-cookiebanner-container' ) ) {
return $html;
}

$lang       = leadwerk_theme_get_current_lang();
$de_strings = leadwerk_theme_get_theme_strings( 'de' );
$tr_strings = leadwerk_theme_get_theme_strings( $lang );

$search  = array();
$replace = array();
foreach ( $de_strings as $key => $de_value ) {
if ( 0 !== strpos( $key, 'cmplz_' ) ) {
continue;
}

$tr_value = isset( $tr_strings[ $key ] ) ? (string) $tr_strings[ $key ] : '';
if ( '' === $tr_value || $tr_value === $de_value ) {
continue;
}

$search[]  = $de_value;
$replace[] = $tr_value;
}

if ( empty( $search ) ) {
return $html;
}

return str_replace( $search, $replace, $html );
}
add_action( 'template_redirect', 'leadwerk_theme_cmplz_ob_start', 1 );

/**
 * Also hook cmplz_cookie_banner_text if the filter exists in the installed
 * Complianz version.
 *
 * @param string $html Banner HTML.
 * @return string
 */
function leadwerk_theme_cmplz_filter_banner_text( $html ) {
$lang         = leadwerk_theme_get_current_lang();
$default_lang = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::get_default_language() : 'de';

if ( $lang === $default_lang ) {
return $html;
}

return leadwerk_theme_cmplz_replace_banner_strings( $html );
}
add_filter( 'cmplz_cookie_banner_text', 'leadwerk_theme_cmplz_filter_banner_text', 10, 1 );

/**
 * Inject a small JS patch in the footer that overrides the German strings
 * inside the global complianz config object (placeholdertext, aria_label)
 * for non-default-language pages.
 *
 * @return void
 */
function leadwerk_theme_cmplz_js_locale_override() {
if ( is_admin() ) {
return;
}

$lang         = leadwerk_theme_get_current_lang();
$default_lang = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::get_default_language() : 'de';

if ( $lang === $default_lang ) {
return;
}

$js_overrides = array(
'placeholdertext' => 'en' === $lang
? leadwerk_theme_get_string( 'cmplz_placeholder_accept', 'Click here to accept {category} cookies and enable this content', $lang )
: '',
'aria_label'      => 'en' === $lang
? leadwerk_theme_get_string( 'cmplz_placeholder_accept', 'Click here to accept {category} cookies and enable this content', $lang )
: '',
);

$js_overrides = array_filter( $js_overrides );
if ( empty( $js_overrides ) ) {
return;
}
?>
<script id="leadwerk-cmplz-locale-override">
(function(){
if(typeof complianz==='undefined')return;
var t=<?php echo wp_json_encode( $js_overrides ); ?>;
for(var k in t){if(t.hasOwnProperty(k))complianz[k]=t[k];}
})();
</script>
<?php
}
add_action( 'wp_footer', 'leadwerk_theme_cmplz_js_locale_override', 101 );

/**
 * Register dynamic theme blocks.
 *
 * @return void
 */
function leadwerk_theme_register_blocks() {
$blocks = array(
'leadwerk-acm-page'   => 'leadwerk_theme_render_page_block',
'leadwerk-acm-header' => 'leadwerk_theme_render_header_block',
'leadwerk-acm-footer' => 'leadwerk_theme_render_footer_block',
);

foreach ( $blocks as $name => $callback ) {
register_block_type(
'acf/' . $name,
array(
'render_callback' => $callback,
)
);
}
}
add_action( 'init', 'leadwerk_theme_register_blocks' );

/**
 * Add static body classes and language marker.
 *
 * @param string[] $classes Existing classes.
 * @return string[]
 */
function leadwerk_theme_body_classes( $classes ) {
	if ( function_exists( 'leadwerk_theme_is_simple_header_context' ) && leadwerk_theme_is_simple_header_context() ) {
		$classes[] = 'page-simple-header';
	}
	if ( is_singular( 'acm_news' ) ) {
		$classes[] = 'page-news-article';
	}
if ( is_404() ) {
$classes[] = 'page-404';
$classes[] = 'header-scrolled';
$classes[] = 'lang-' . leadwerk_theme_get_current_lang();
}
if ( is_singular( 'page' ) ) {
$post_id    = get_queried_object_id();
$body_class = trim( (string) get_post_meta( $post_id, 'leadwerk_body_class', true ) );
$source_key = trim( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
$lang       = leadwerk_theme_get_current_lang();

if ( '' === $body_class && '' !== $source_key && function_exists( 'leadwerk_theme_get_source_template_body_class' ) ) {
$body_class = (string) leadwerk_theme_get_source_template_body_class( $source_key );
}

if ( '' !== $body_class ) {
$classes = array_merge( $classes, preg_split( '/\s+/', $body_class ) );
}

if ( 'acm-index-v1' === $source_key || 'acm-home-v1' === $source_key ) {
$classes[] = 'home';
}
if ( 'acm-404-v1' === $source_key ) {
$classes[] = 'page-404';
$classes[] = 'header-scrolled';
}

$classes[] = 'lang-' . $lang;
}

$classes = array_values(
array_filter(
array_unique( array_filter( $classes ) ),
static function ( $class ) {
return false === strpos( (string) $class, 'leadwerk' );
}
)
);

return $classes;
}
add_filter( 'body_class', 'leadwerk_theme_body_classes' );
/**
 * Output fallback favicon if site icon is not configured.
 *
 * @return void
 */
function leadwerk_theme_favicon() {
	if ( get_option( 'site_icon' ) ) {
		return;
	}

	echo '<link rel="icon" type="image/webp" href="' . esc_url( LEADWERK_THEME_URI . '/favicon-32x32.webp' ) . '" sizes="32x32">' . "\n";
	echo '<link rel="icon" type="image/webp" href="' . esc_url( LEADWERK_THEME_URI . '/favicon-192x192.webp' ) . '" sizes="192x192">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . esc_url( LEADWERK_THEME_URI . '/apple-touch-icon.webp' ) . '">' . "\n";
}
add_action( 'wp_head', 'leadwerk_theme_favicon', 1 );

/**
 * Output canonical, hreflang and meta description tags.
 *
 * @return void
 */
function leadwerk_theme_head_meta() {
	if ( ! is_singular( 'page' ) ) {
		return;
	}

	$post_id          = get_queried_object_id();
	$meta_description = trim( (string) get_post_meta( $post_id, 'leadwerk_meta_description', true ) );
	$robots           = trim( (string) get_post_meta( $post_id, 'leadwerk_meta_robots', true ) );

	if ( '' !== $meta_description ) {
		echo '<meta name="description" content="' . esc_attr( $meta_description ) . '">' . "\n";
	}

	if ( '' !== $robots ) {
		echo '<meta name="robots" content="' . esc_attr( $robots ) . '">' . "\n";
	}

	echo '<link rel="canonical" href="' . esc_url( get_permalink( $post_id ) ) . '">' . "\n";

	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		$translations = Leadwerk_Translation_API::get_translations( $post_id );
		$x_default    = ! empty( $translations['de'] ) ? get_permalink( $translations['de'] ) : get_permalink( $post_id );
		if ( ! empty( $translations['de'] ) ) {
			echo '<link rel="alternate" hreflang="de" href="' . esc_url( get_permalink( $translations['de'] ) ) . '">' . "\n";
		}
		if ( ! empty( $translations['en'] ) ) {
			echo '<link rel="alternate" hreflang="en" href="' . esc_url( get_permalink( $translations['en'] ) ) . '">' . "\n";
		}
		if ( $x_default ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '">' . "\n";
		}
	}
}
add_action( 'wp_head', 'leadwerk_theme_head_meta', 5 );

/**
 * Render the dynamic page block.
 *
 * @return string
 */
function leadwerk_theme_render_page_block() {
	$post_id = get_the_ID();
	if ( ! $post_id || ! class_exists( 'Leadwerk_Content_Schema' ) || ! function_exists( 'get_field' ) ) {
		return '';
	}

	$group = Leadwerk_Content_Schema::get_group_for_post( $post_id );
	if ( ! $group || empty( $group['field_name'] ) ) {
		return '';
	}

	$field_name = $group['field_name'];
	$value      = get_field( $field_name, $post_id );
	if ( function_exists( 'leadwerk_theme_render_exact_page_group' ) ) {
		$exact_html = leadwerk_theme_render_exact_page_group( $group, $value, $post_id );
		if ( false !== strpos( $exact_html, 'leadwerk-structured-' ) ) {
			return function_exists( 'leadwerk_theme_render_exact_runtime_notice' )
				? leadwerk_theme_render_exact_runtime_notice(
					'Structured fallback markers were detected for post #' . $post_id . '. Exact shell rendering must be fixed before this page is used publicly.',
					$post_id
				)
				: '';
		}
		if ( '' !== trim( wp_strip_all_tags( $exact_html ) ) || false !== strpos( $exact_html, '<section' ) || false !== strpos( $exact_html, 'runtime-notice' ) ) {
			return $exact_html;
		}
	}

	return function_exists( 'leadwerk_theme_render_exact_runtime_notice' )
		? leadwerk_theme_render_exact_runtime_notice(
			'Exact shell rendering is required for post #' . $post_id . ', but no mapped shell output was produced.',
			$post_id
		)
		: '';
}

/**
 * Render current page content in classic theme templates.
 *
 * @param int $post_id Optional post ID.
 * @return string
 */
function leadwerk_theme_render_current_page_content( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	if ( ! $post_id ) {
		return '';
	}

	$group = class_exists( 'Leadwerk_Content_Schema' )
		? Leadwerk_Content_Schema::get_group_for_post( $post_id )
		: null;

	if ( $group && ! empty( $group['field_name'] ) && function_exists( 'get_field' ) ) {
		$field_name = $group['field_name'];
		$value      = get_field( $field_name, $post_id );

		if ( function_exists( 'leadwerk_theme_render_exact_page_group' ) ) {
			$exact_html = leadwerk_theme_render_exact_page_group( $group, $value, $post_id );
			if ( false !== strpos( $exact_html, 'leadwerk-structured-' ) ) {
				return function_exists( 'leadwerk_theme_render_exact_runtime_notice' )
					? leadwerk_theme_render_exact_runtime_notice(
						'Structured fallback markers were detected for post #' . $post_id . '. Exact shell rendering is not clean yet.',
						$post_id
					)
					: '';
			}
			if ( false !== strpos( $exact_html, '<section' ) || false !== strpos( $exact_html, '<div class="runtime-notice"' ) ) {
				return $exact_html;
			}

			return function_exists( 'leadwerk_theme_render_exact_runtime_notice' )
				? leadwerk_theme_render_exact_runtime_notice(
					'Exact shell missing or source key is unmapped for post #' . $post_id . '.',
					$post_id
				)
				: '';
		}

		return function_exists( 'leadwerk_theme_render_exact_runtime_notice' )
			? leadwerk_theme_render_exact_runtime_notice(
				'Exact renderer is unavailable for post #' . $post_id . '.',
				$post_id
			)
			: '';
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	if ( function_exists( 'leadwerk_theme_is_simple_content_page' ) && leadwerk_theme_is_simple_content_page( $post_id ) ) {
		return leadwerk_theme_render_simple_content_page( $post_id );
	}

	return apply_filters( 'the_content', $post->post_content );
}

/**
 * Whether the selected page template is the editable simple content template.
 *
 * @param int $post_id Optional post ID.
 * @return bool
 */
function leadwerk_theme_uses_simple_page_template( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return false;
	}

	return LEADWERK_THEME_SIMPLE_PAGE_TEMPLATE === (string) get_page_template_slug( $post_id );
}

/**
 * Plain WordPress pages render in the same quiet shell as Impressum/Datenschutz.
 *
 * @param int $post_id Optional post ID.
 * @return bool
 */
function leadwerk_theme_is_simple_content_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return false;
	}

	if ( leadwerk_theme_uses_simple_page_template( $post_id ) ) {
		return true;
	}

	return '' === trim( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
}

/**
 * Force the rendered ACM header into its scrolled visual state.
 *
 * @param string $html Header markup.
 * @return string
 */
function leadwerk_theme_force_scrolled_header_markup( $html ) {
	return preg_replace_callback(
		'/<header\b([^>]*)>/i',
		static function ( $matches ) {
			$attrs = (string) ( $matches[1] ?? '' );
			if ( preg_match( '/\sclass=(["\'])(.*?)\1/i', $attrs, $class_match ) ) {
				$classes = preg_split( '/\s+/', trim( (string) $class_match[2] ) );
				$classes = is_array( $classes ) ? $classes : array();
				if ( ! in_array( 'header-scrolled', $classes, true ) ) {
					$classes[] = 'header-scrolled';
				}
				$attrs = preg_replace( '/\sclass=(["\'])(.*?)\1/i', ' class="' . esc_attr( implode( ' ', array_filter( $classes ) ) ) . '"', $attrs, 1 );
			} else {
				$attrs .= ' class="header-scrolled"';
			}
			if ( false === stripos( $attrs, 'data-force-scrolled-header' ) ) {
				$attrs .= ' data-force-scrolled-header="true"';
			}
			return '<header' . $attrs . '>';
		},
		(string) $html,
		1
	);
}

/**
 * Render editor-managed page content inside the legal/simple ACM layout.
 *
 * @param int $post_id Optional post ID.
 * @return string
 */
function leadwerk_theme_render_simple_content_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	$title   = trim( get_the_title( $post ) );
	$content = apply_filters( 'the_content', $post->post_content );

	ob_start();
	?>
	<main class="leadwerk-simple-page">
		<section class="content-section content-section--white legal-content pt-32 pb-24">
			<div class="max-w-3xl mx-auto px-6">
				<?php if ( '' !== $title ) : ?>
					<h1 class="legal-title font-serif text-4xl text-stone-900 mb-8"><?php echo esc_html( $title ); ?></h1>
				<?php endif; ?>
				<div class="legal-body text-stone-600 leading-relaxed prose prose-stone max-w-none">
					<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already filtered through the_content. ?>
				</div>
			</div>
		</section>
	</main>
	<?php

	return ob_get_clean();
}

/**
 * Render the dynamic header block.
 *
 * @return string
 */
function leadwerk_theme_render_header_block() {
	if ( function_exists( 'leadwerk_theme_render_exact_site_header' ) ) {
		$exact_header = leadwerk_theme_render_exact_site_header();
		if ( '' !== trim( $exact_header ) ) {
			if ( is_404() || 'acm-404-v1' === leadwerk_theme_get_current_source_key() ) {
				return leadwerk_theme_force_scrolled_header_markup( $exact_header );
			}
			return $exact_header;
		}
	}

	$lang            = leadwerk_theme_get_current_lang();
	$strings         = leadwerk_theme_get_theme_strings( $lang );
	$home_url        = leadwerk_theme_get_page_url( 'acm-index-v1', $lang, home_url( '/' ) );
	$language_url    = leadwerk_theme_get_alternate_language_url();
	$lang_pair       = leadwerk_theme_get_header_footer_lang_pair_urls();
	$de_url          = $lang_pair['de'];
	$en_url          = $lang_pair['en'];
	$is_service_page = leadwerk_theme_is_service_page();
	$service_label   = $strings['services_menu_label'] ?? 'Leistungen';
	$lang_group_label = $strings['header_language_group_label'] ?? ( 'en' === $lang ? 'Choose language' : 'Sprache wählen' );
	$lang_button_label = $strings['header_language_button_label'] ?? ( 'en' === $lang ? 'Change language' : 'Sprache wechseln' );
	$open_menu_label = $strings['header_open_menu_label'] ?? ( 'en' === $lang ? 'Open menu' : 'Menü öffnen' );
	$lang_option_de  = $strings['header_language_option_de'] ?? 'Deutsch';
	$lang_option_en  = $strings['header_language_option_en'] ?? 'English';
	$language_label  = 'en' === $lang ? 'EN' : 'DE';
	$other_short     = 'en' === $lang ? 'DE' : 'EN';
	$header_logo_alt = leadwerk_theme_get_string( 'header_logo_alt', 'ACM AIR CHARTER', $lang );
	$header_logo_aria = leadwerk_theme_get_string( 'header_logo_link_aria_label', '', $lang );

	ob_start();
	?>
	<header class="site-header">
		<div class="header-row">
			<div class="header-logo">
				<a href="<?php echo esc_url( $home_url ); ?>"<?php echo '' !== $header_logo_aria ? ' aria-label="' . esc_attr( $header_logo_aria ) . '"' : ''; ?>>
					<img src="<?php echo esc_url( leadwerk_theme_get_option_image_url( 'header_logo', 'assets/images/Logo-final-weiss-rz_svg.svg' ) ); ?>" alt="<?php echo esc_attr( $header_logo_alt ); ?>" width="920" height="210">
				</a>
			</div>
			<nav class="header-nav">
				<ul class="nav-menu">
					<li><a class="<?php echo leadwerk_theme_is_source_key( 'acm-thats-acm-v1' ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-thats-acm-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-thats-acm-v1', $lang, "That's ACM" ) ); ?></a></li>
					<li class="has-submenu">
						<a class="<?php echo in_array( leadwerk_theme_get_current_source_key(), array( 'acm-charter-v1', 'acm-global-7500-v1', 'acm-global-6000-v1', 'acm-global-xrs-v1' ), true ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-charter-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-charter-v1', $lang, 'Charter' ) ); ?></a>
						<ul class="sub-menu">
							<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-charter-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-charter-v1', $lang, 'Charter' ) ); ?></a></li>
							<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-7500-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-7500-v1', $lang, 'Bombardier Global 7500' ) ); ?></a></li>
							<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-6000-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-6000-v1', $lang, 'Bombardier Global 6000' ) ); ?></a></li>
							<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-xrs-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-xrs-v1', $lang, 'Bombardier Global XRS' ) ); ?></a></li>
						</ul>
					</li>
					<li><a class="<?php echo leadwerk_theme_is_source_key( 'acm-aircraft-v1' ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-aircraft-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-aircraft-v1', $lang, 'Aircraft Management' ) ); ?></a></li>
					<li><a class="<?php echo leadwerk_theme_is_source_key( 'acm-maintenance-v1' ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-maintenance-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-maintenance-v1', $lang, 'Maintenance' ) ); ?></a></li>
					<li><a class="<?php echo leadwerk_theme_is_source_key( 'acm-careers-v1' ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-careers-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-careers-v1', $lang, 'Karriere' ) ); ?></a></li>
					<li><a class="<?php echo leadwerk_theme_is_source_key( 'acm-contact-v1' ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-contact-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-contact-v1', $lang, 'Kontakt' ) ); ?></a></li>
				</ul>
			</nav>
			<div class="header-cta">
				<div class="header-lang" role="group" aria-label="<?php echo esc_attr( $lang_group_label ); ?>">
					<button class="header-lang-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr( $lang_button_label ); ?>" title="<?php echo esc_attr( $lang_button_label ); ?>">
						<span class="header-lang-icon" aria-hidden="true">
							<svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10"></circle><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
						</span>
						<span class="header-lang-label"><?php echo esc_html( $language_label ); ?></span>
					</button>
					<div class="header-lang-dropdown" hidden>
						<a class="header-lang-option<?php echo 'de' === $lang ? ' is-active' : ''; ?>" href="<?php echo esc_url( $de_url ); ?>" hreflang="de" lang="de"><?php echo esc_html( $lang_option_de ); ?></a>
						<a class="header-lang-option<?php echo 'en' === $lang ? ' is-active' : ''; ?>" href="<?php echo esc_url( $en_url ); ?>" hreflang="en" lang="en"><?php echo esc_html( $lang_option_en ); ?></a>
					</div>
				</div>
			</div>
			<button class="mobile-menu-toggle" type="button" aria-label="<?php echo esc_attr( $open_menu_label ); ?>">
				<span></span><span></span><span></span>
			</button>
		</div>
		<div class="mobile-menu">
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-thats-acm-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-thats-acm-v1', $lang, "That's ACM" ) ); ?></a>
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-charter-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-charter-v1', $lang, 'Charter' ) ); ?></a>
			<div class="sub-menu">
				<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-7500-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-7500-v1', $lang, 'Bombardier Global 7500' ) ); ?></a>
				<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-6000-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-6000-v1', $lang, 'Bombardier Global 6000' ) ); ?></a>
				<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-global-xrs-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-global-xrs-v1', $lang, 'Bombardier Global XRS' ) ); ?></a>
			</div>
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-aircraft-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-aircraft-v1', $lang, 'Aircraft Management' ) ); ?></a>
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-maintenance-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-maintenance-v1', $lang, 'Maintenance' ) ); ?></a>
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-careers-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-careers-v1', $lang, 'Karriere' ) ); ?></a>
			<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-contact-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-contact-v1', $lang, 'Kontakt' ) ); ?></a>
			<a class="mobile-menu-locale" href="<?php echo esc_url( $language_url ); ?>"><?php echo esc_html( $other_short ); ?></a>
		</div>
	</header>
	<?php

	return ob_get_clean();
}

/**
 * Footer AGB link: URL and anchor text for the active language.
 *
 * Priority: optional WP page ID (translation pair) → optional leadwerk_source_key → static footer_agb_url.
 *
 * @param string $lang Language code (de|en).
 * @return array{href:string,label:string}
 */
function leadwerk_theme_get_footer_agb_link( $lang ) {
	$lang       = sanitize_key( (string) $lang );
	$static     = trim( (string) leadwerk_theme_get_option_value( 'footer_agb_url', '' ) );
	$page_id    = absint( leadwerk_theme_get_option_value( 'footer_agb_page_id', '0' ) );
	$source_key = sanitize_key( (string) leadwerk_theme_get_option_value( 'footer_agb_source_key', '' ) );
	$fallback   = leadwerk_theme_get_string( 'footer_agb_link_label', 'AGB', $lang );

	if ( $page_id > 0 && class_exists( 'Leadwerk_Translation_API' ) ) {
		$target = (int) Leadwerk_Translation_API::get_translation( $page_id, $lang );
		if ( $target > 0 ) {
			$url = Leadwerk_Translation_API::get_public_post_url( $target, $static );
			if ( '' === $url ) {
				$plink = get_permalink( $target );
				$url   = $plink ? (string) $plink : $static;
			}
			$post = get_post( $target );
			$label = ( $post instanceof WP_Post && '' !== trim( (string) $post->post_title ) )
				? (string) $post->post_title
				: $fallback;
			return array( 'href' => $url, 'label' => $label );
		}
	}

	if ( '' !== $source_key && class_exists( 'Leadwerk_Translation_API' ) ) {
		$url = leadwerk_theme_get_page_url( $source_key, $lang, $static );
		if ( '' !== $url && '#' !== $url ) {
			return array(
				'href'  => $url,
				'label' => leadwerk_theme_get_page_title( $source_key, $lang, $fallback ),
			);
		}
	}

	if ( '' !== $static ) {
		return array( 'href' => $static, 'label' => $fallback );
	}

	return array( 'href' => '', 'label' => $fallback );
}

/**
 * Render the dynamic footer block.
 *
 * @return string
 */
function leadwerk_theme_render_footer_block() {
	if ( function_exists( 'leadwerk_theme_render_exact_site_footer' ) ) {
		$exact_footer = leadwerk_theme_render_exact_site_footer();
		if ( '' !== trim( $exact_footer ) ) {
			return $exact_footer;
		}
	}

	$lang             = leadwerk_theme_get_current_lang();
	$strings          = leadwerk_theme_get_theme_strings( $lang );
	$source_key       = leadwerk_theme_get_current_source_key();
	$footer_desc_key  = leadwerk_theme_is_legal_source_key( $source_key ) ? 'footer_desc_legal' : ( in_array( $source_key, array( 'acm-index-v1', 'acm-home-v1' ), true ) ? 'footer_desc_home' : 'footer_desc_general' );
	$footer_tagline_s = leadwerk_theme_get_string( 'footer_tagline', '', $lang );
	$footer_desc_text = '' !== trim( $footer_tagline_s ) ? $footer_tagline_s : (string) ( $strings[ $footer_desc_key ] ?? '' );
	$address          = nl2br( esc_html( leadwerk_theme_get_option_value( 'company_address', "Am Flughafen 12\n77836 Rheinmünster" ) ) );
	$phone            = leadwerk_theme_get_option_value( 'company_phone', '+49 7229 30405-0' );
	$email            = leadwerk_theme_get_option_value( 'company_email', 'info@acm.aero' );
	$maps_url         = leadwerk_theme_get_option_value( 'google_maps_url', '#' );
	$phone_prefix     = $strings['footer_phone_prefix'] ?? ( 'en' === $lang ? 'Phone:' : 'Tel.:' );
	$footer_camo_url  = trim( leadwerk_theme_get_option_value( 'footer_camo_url', '' ) );
	$footer_agb       = leadwerk_theme_get_footer_agb_link( $lang );
	$footer_logo_alt  = leadwerk_theme_get_string( 'footer_logo_alt', 'ACM Logo', $lang );

	ob_start();
	?>
	<footer class="site-footer">
		<div class="footer-main">
			<div class="footer-col">
				<img src="<?php echo esc_url( leadwerk_theme_get_option_image_url( 'footer_logo', 'assets/images/Logo-final-weiss-rz.webp' ) ); ?>" alt="<?php echo esc_attr( $footer_logo_alt ); ?>" class="footer-logo" width="922" height="212" loading="lazy">
				<p class="footer-desc"><?php echo esc_html( $footer_desc_text ); ?></p>
			</div>
			<div class="footer-col">
				<h4><?php echo esc_html( $strings['footer_services_heading'] ?? 'Leistungen' ); ?></h4>
				<ul>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-charter-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-charter-v1', $lang, 'Charter' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-aircraft-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-aircraft-v1', $lang, 'Aircraft Management' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-maintenance-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-maintenance-v1', $lang, 'Maintenance' ) ); ?></a></li>
					<?php if ( '' !== $footer_camo_url ) : ?>
					<li><a href="<?php echo esc_url( $footer_camo_url ); ?>"><?php echo esc_html( leadwerk_theme_get_string( 'footer_camo_link_label', 'CAMO', $lang ) ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>
			<div class="footer-col">
				<h4><?php echo esc_html( $strings['footer_company_heading'] ?? 'Unternehmen' ); ?></h4>
				<ul>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-thats-acm-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-thats-acm-v1', $lang, "That's ACM" ) ); ?></a></li>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-careers-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-careers-v1', $lang, 'Karriere' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-news-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-news-v1', $lang, 'News' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-contact-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-contact-v1', $lang, 'Kontakt' ) ); ?></a></li>
				</ul>
			</div>
			<div class="footer-col footer-contact">
				<h4><?php echo esc_html( $strings['footer_contact_heading'] ?? 'Kontakt' ); ?></h4>
				<div class="contact-item">
					<span class="contact-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
					<p><a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo $address; ?></a></p>
				</div>
				<div class="contact-item">
					<span class="contact-icon"><i class="fa-solid fa-phone" aria-hidden="true"></i></span>
					<p><?php echo esc_html( $phone_prefix ); ?> <a href="<?php echo esc_url( 'tel:' . preg_replace( '/\s+/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></p>
				</div>
				<div class="contact-item">
					<span class="contact-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
					<p><a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
				</div>
			</div>
		</div>
		<div class="footer-logo-full">
			<img src="<?php echo esc_url( leadwerk_theme_get_option_image_url( 'footer_wordmark', 'assets/images/Schriftzug.svg' ) ); ?>" alt="<?php echo esc_attr( leadwerk_theme_get_string( 'footer_wordmark_alt', 'ACM AIR CHARTER', $lang ) ); ?>" class="footer-logo-full__img" width="972" height="176" loading="lazy">
			<div class="footer-logo-full__gradient" aria-hidden="true"></div>
		</div>
		<div class="footer-bottom">
			<p>
				<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-impressum-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-impressum-v1', $lang, 'Impressum' ) ); ?></a>&nbsp; &nbsp; &nbsp;|&nbsp; &nbsp; &nbsp;<a href="<?php echo esc_url( leadwerk_theme_get_page_url( 'acm-datenschutz-v1', $lang ) ); ?>"><?php echo esc_html( leadwerk_theme_get_page_title( 'acm-datenschutz-v1', $lang, 'Datenschutz' ) ); ?></a>
				<?php if ( '' !== $footer_agb['href'] ) : ?>
					&nbsp; &nbsp; &nbsp;|&nbsp; &nbsp; &nbsp;<a href="<?php echo esc_url( $footer_agb['href'] ); ?>"><?php echo esc_html( $footer_agb['label'] ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	</footer>
	<?php

	return ob_get_clean();
}

/**
 * Prepare a stored HTML section before output.
 *
 * @param string $html HTML.
 * @return string
 */
function leadwerk_theme_prepare_section_html( $html ) {
	if ( false !== strpos( $html, 'class="contact-form"' ) ) {
		$form_markup = leadwerk_theme_get_contact_form_markup();
		$html        = preg_replace( '#<form class="contact-form".*?</form>#si', $form_markup, $html, 1 );
	}

	return $html;
}

/**
 * Return contact form markup or fallback.
 *
 * @return string
 */
function leadwerk_theme_get_contact_form_markup() {
	$lang        = leadwerk_theme_get_current_lang();
	$form_config = trim(
		(string) (
			class_exists( 'Leadwerk_Translation_API' )
				? Leadwerk_Translation_API::get_localized_option( 'wpforms_form_id', $lang, '' )
				: leadwerk_theme_get_option_value( 'en' === $lang ? 'wpforms_form_id_en' : 'wpforms_form_id_de', '' )
		)
	);
	$strings     = leadwerk_theme_get_theme_strings( $lang );
	$fallback    = '<div class="contact-form-placeholder">' . esc_html( $strings['wpforms_missing'] ?? 'WPForms configuration missing.' ) . '</div>';

	if ( '' === $form_config ) {
		return $fallback;
	}

	if ( ! shortcode_exists( 'wpforms' ) ) {
		return $fallback;
	}

	$shortcode = leadwerk_theme_normalize_wpforms_shortcode( $form_config );
	if ( '' === $shortcode ) {
		return $fallback;
	}

	$markup = (string) do_shortcode( $shortcode );
	if ( '' === trim( wp_strip_all_tags( $markup ) ) && false === strpos( $markup, '<form' ) && false === strpos( $markup, 'wpforms' ) ) {
		return $fallback;
	}

	return $markup;
}

/**
 * Normalize a stored WPForms value into a valid shortcode.
 *
 * @param string $value Stored option value.
 * @return string
 */
function leadwerk_theme_normalize_wpforms_shortcode( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	if ( 0 === stripos( $value, '[wpforms' ) ) {
		return $value;
	}

	if ( preg_match( '/^\d+$/', $value ) ) {
		return '[wpforms id="' . absint( $value ) . '" title="false" description="false"]';
	}

	return '';
}

/**
 * Get current language.
 *
 * @return string
 */
function leadwerk_theme_get_current_lang() {
	$post_id = get_queried_object_id();
	if ( $post_id && class_exists( 'Leadwerk_Translation_API' ) ) {
		return Leadwerk_Translation_API::get_post_language( $post_id );
	}

	if ( class_exists( 'Leadwerk_Translation_API' ) && method_exists( 'Leadwerk_Translation_API', 'get_current_request_language' ) ) {
		return Leadwerk_Translation_API::get_current_request_language();
	}

	return 'de';
}

/**
 * Get current source key.
 *
 * @return string
 */
function leadwerk_theme_get_current_source_key() {
	$post_id = get_queried_object_id();
	return $post_id ? (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) : '';
}

/**
 * Whether current page matches a source key.
 *
 * @param string $source_key Source key.
 * @return bool
 */
function leadwerk_theme_is_source_key( $source_key ) {
	return leadwerk_theme_get_current_source_key() === $source_key;
}

/**
 * Whether the current page is one of the service pages.
 *
 * @return bool
 */
function leadwerk_theme_is_service_page() {
	return in_array(
		leadwerk_theme_get_current_source_key(),
		array( 'acm-retirement-v1', 'acm-investment-v1', 'acm-real-estate-v1', 'acm-inheritance-v1' ),
		true
	);
}

/**
 * Whether a source key belongs to a legal page.
 *
 * @param string $source_key Source key.
 * @return bool
 */
function leadwerk_theme_is_legal_source_key( $source_key ) {
	return in_array( $source_key, array( 'acm-impressum-v1', 'acm-datenschutz-v1', 'acm-agb-v1' ), true );
}

/**
 * Kontexte mit „einfachem“ hellen Header (wie News-Artikel): acm_news Single, Impressum, Datenschutz,
 * sowie beliebige Seiten mit Post-Meta leadwerk_simple_header = 1.
 *
 * @return bool
 */
function leadwerk_theme_is_simple_header_context() {
	if ( is_singular( 'acm_news' ) ) {
		return true;
	}
	if ( ! is_singular( 'page' ) ) {
		return false;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return false;
	}
	if ( function_exists( 'leadwerk_theme_is_simple_content_page' ) && leadwerk_theme_is_simple_content_page( $post_id ) ) {
		return true;
	}
	$source_key = trim( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
	if ( leadwerk_theme_is_legal_source_key( $source_key ) ) {
		return true;
	}
	return '1' === (string) get_post_meta( $post_id, LEADWERK_THEME_SIMPLE_HEADER_META, true );
}

/**
 * Get alternate language URL for current page.
 *
 * @return string
 */
function leadwerk_theme_get_alternate_language_url() {
	$post_id = get_queried_object_id();
	if ( ! $post_id || ! class_exists( 'Leadwerk_Translation_API' ) ) {
		return home_url( '/en/' );
	}

	$target_lang = 'en' === leadwerk_theme_get_current_lang() ? 'de' : 'en';
	$fallback    = 'en' === $target_lang ? home_url( '/en/' ) : home_url( '/' );

	return Leadwerk_Translation_API::get_translation_url( $post_id, $target_lang, $fallback );
}

/**
 * Get a page ID by source key and language.
 *
 * @param string $source_key Source key.
 * @param string $lang       Language code.
 * @return int
 */
function leadwerk_theme_get_page_id( $source_key, $lang ) {
	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		return Leadwerk_Translation_API::get_post_by_source_key( $source_key, $lang );
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => 'leadwerk_source_key',
					'value' => $source_key,
				),
				array(
					'key'   => 'leadwerk_lang',
					'value' => $lang,
				),
			),
		)
	);

	$ids = $query->get_posts();
	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Get a page URL by source key.
 *
 * @param string $source_key Source key.
 * @param string $lang       Language code.
 * @param string $fallback   Fallback URL.
 * @return string
 */
function leadwerk_theme_get_page_url( $source_key, $lang, $fallback = '#' ) {
	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		return Leadwerk_Translation_API::get_post_url_by_source_key( $source_key, $lang, $fallback );
	}

	$page_id = leadwerk_theme_get_page_id( $source_key, $lang );
	return $page_id ? get_permalink( $page_id ) : $fallback;
}

/**
 * DE/EN URLs for shell header/footer language links (aligned with Leadwerk translation URLs).
 * On single acm_news, uses the translated post pair; otherwise the current page source_key.
 *
 * @return array{de: string, en: string}
 */
function leadwerk_theme_get_header_footer_lang_pair_urls() {
	$de_fb = leadwerk_theme_get_page_url( 'acm-news-v1', 'de', home_url( '/' ) );
	$en_fb = leadwerk_theme_get_page_url( 'acm-news-v1', 'en', home_url( '/en/' ) );

	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		$post_id = get_queried_object_id();
		$post    = get_queried_object();
		if ( $post_id && $post instanceof WP_Post && is_singular( $post->post_type )
			&& Leadwerk_Translation_API::is_translatable_post_type( $post->post_type ) ) {
			return array(
				'de' => Leadwerk_Translation_API::get_translation_url( $post_id, 'de', $de_fb ),
				'en' => Leadwerk_Translation_API::get_translation_url( $post_id, 'en', $en_fb ),
			);
		}
	}

	$key = leadwerk_theme_get_current_source_key();

	return array(
		'de' => leadwerk_theme_get_page_url( $key, 'de', home_url( '/' ) ),
		'en' => leadwerk_theme_get_page_url( $key, 'en', home_url( '/en/' ) ),
	);
}

/**
 * Get a page title by source key.
 *
 * @param string $source_key Source key.
 * @param string $lang       Language code.
 * @param string $fallback   Fallback label.
 * @return string
 */
function leadwerk_theme_get_page_title( $source_key, $lang, $fallback = '' ) {
	$page_id = leadwerk_theme_get_page_id( $source_key, $lang );
	if ( ! $page_id ) {
		return $fallback;
	}

	$title = trim( (string) get_the_title( $page_id ) );
	if ( '' === $title ) {
		return $fallback;
	}
	$decoded = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

	return '' !== $decoded ? $decoded : $fallback;
}

/**
 * Get an option value through Leadwerk Fields.
 *
 * @param string $field_name Field name.
 * @param string $default    Default.
 * @return string
 */
function leadwerk_theme_get_option_value( $field_name, $default = '' ) {
	$value = null;
	if ( class_exists( 'Leadwerk_Fields_API' ) ) {
		$value = Leadwerk_Fields_API::get_field( $field_name, 'option' );
	} elseif ( function_exists( 'get_field' ) ) {
		$value = get_field( $field_name, 'option' );
	}
	if ( null !== $value && '' !== trim( (string) $value ) ) {
		return (string) $value;
	}

	return $default;
}

/**
 * Get an option image URL.
 *
 * @param string $field_name   Field name.
 * @param string $default_path Theme-relative fallback path.
 * @return string
 */
function leadwerk_theme_get_option_image_url( $field_name, $default_path ) {
	$value = null;
	if ( class_exists( 'Leadwerk_Fields_API' ) ) {
		$value = Leadwerk_Fields_API::get_field( $field_name, 'option' );
	} elseif ( function_exists( 'get_field' ) ) {
		$value = get_field( $field_name, 'option' );
	}
	if ( is_numeric( $value ) ) {
		$url = wp_get_attachment_url( (int) $value );
		if ( $url ) {
			return $url;
		}
	}

	if ( function_exists( 'leadwerk_theme_get_uploaded_media_url_for_template_ref' ) ) {
		$url = leadwerk_theme_get_uploaded_media_url_for_template_ref( $default_path );
		if ( '' !== $url ) {
			return $url;
		}
	}

	return LEADWERK_THEME_URI . '/' . ltrim( $default_path, '/' );
}

/**
 * Attachment ID for an image option (Leadwerk Fields / ACF option).
 *
 * @param string $field_name Field name.
 * @return int
 */
function leadwerk_theme_get_option_image_id( $field_name ) {
	$value = null;
	if ( class_exists( 'Leadwerk_Fields_API' ) ) {
		$value = Leadwerk_Fields_API::get_field( $field_name, 'option' );
	} elseif ( function_exists( 'get_field' ) ) {
		$value = get_field( $field_name, 'option' );
	}
	return is_numeric( $value ) ? (int) $value : 0;
}

/**
 * Format multiline address for safe HTML (line breaks only).
 *
 * @param string $raw Raw textarea.
 * @return string
 */
function leadwerk_theme_format_address_lines_html( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$lines = preg_split( '/\r\n|\r|\n/', $raw );
	$parts = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$parts[] = esc_html( $line );
		}
	}

	return implode( '<br />', $parts );
}

/**
 * Merge only non-empty translated string values into defaults.
 *
 * @param array<string,string> $defaults Base strings.
 * @param array<string,mixed>  $translations Candidate translations.
 * @return array<string,string>
 */
function leadwerk_theme_merge_non_empty_strings( $defaults, $translations ) {
	$merged = is_array( $defaults ) ? $defaults : array();

	foreach ( (array) $translations as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}

		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			continue;
		}

		$merged[ $key ] = $value;
	}

	return $merged;
}

/**
 * Get language-aware theme strings.
 *
 * @param string|null $lang Optional language code.
 * @return array<string,string>
 */
function leadwerk_theme_get_theme_strings( $lang = null ) {
	$lang     = $lang ?: leadwerk_theme_get_current_lang();
	$defaults = 'en' === $lang
		? array(
			'services_menu_label' => 'Services',
			'header_language_group_label' => 'Choose language',
			'header_language_button_label' => 'Change language',
			'header_open_menu_label' => 'Open menu',
			'header_language_option_de' => 'Deutsch',
			'header_language_option_en' => 'English',
			'header_contact_cta_label' => 'Contact us',
			'header_logo_alt' => 'ACM AIR CHARTER Logo',
			'header_logo_link_aria_label' => 'ACM AIR CHARTER Home',
			'footer_tagline' => 'Premium Business Aviation from a single source — Charter, Management, Maintenance.',
			'footer_copyright' => '© 2026 ACM AIR CHARTER GmbH. All rights reserved.',
			'footer_legal_heading' => 'Legal',
			'footer_social_heading' => 'Follow us',
			'footer_agb_link_label' => 'Terms and conditions',
			'footer_camo_link_label' => 'CAMO',
			'footer_social_linkedin_aria' => 'LinkedIn',
			'footer_social_instagram_aria' => 'Instagram',
			'footer_logo_alt' => 'ACM Logo',
			'footer_wordmark_alt' => 'ACM AIR CHARTER',
			'footer_services_heading' => 'Services',
			'footer_company_heading' => 'Company',
			'footer_contact_heading' => 'Contact',
			'footer_menu_home' => 'Home',
			'footer_phone_prefix' => 'Phone:',
			'footer_desc_home' => 'ACM AIR CHARTER — Premium Business Aviation. Charter flights with Bombardier Global jets, professional Aircraft Management, certified Maintenance (Part-145) and CAMO from a single source.',
			'footer_desc_general' => 'ACM AIR CHARTER offers premium business aviation services from Rheinmünster, Germany. Charter, Aircraft Management, Maintenance and CAMO — everything from one trusted partner.',
			'footer_desc_legal' => 'ACM AIR CHARTER GmbH — Premium Business Aviation. Charter, Aircraft Management, Maintenance (Part-145) and CAMO services from a single source.',
			'contact_privacy_link_label' => 'Privacy policy',
			'structured_open_link_label' => 'Open link',
			'ui_learn_more_label' => 'Learn more',
			'ui_step_label' => 'Step',
			'ui_steps_nav_label' => 'Steps',
			'ui_prev_step_label' => 'Previous step',
			'ui_next_step_label' => 'Next step',
			'ui_swipe_or_click_hint' => 'Swipe or click',
			'ui_close_label' => 'Close',
			'news_read_more_label' => 'Read more',
			'store_badge_apple_aria_label' => 'Download on the App Store',
			'store_badge_apple_alt' => 'Download on the App Store',
			'store_badge_google_aria_label' => 'Get it on Google Play',
			'store_badge_google_alt' => 'Get it on Google Play',
			'legacy_home_hero_title_gradient' => 'Save your city center.',
			'legacy_home_hero_typewriter_words' => 'Shop local.|Find deals.|Discover fashion.|Strengthen your city.',
			'legacy_home_hero_cta_text' => 'Download app',
			'legacy_home_why_label' => 'Why U-like-it?',
			'legacy_home_why_title' => 'Because local is simply better.',
			'legacy_home_app_steps_title' => 'How the app works',
			'legacy_home_packages_label' => 'These are',
			'legacy_home_packages_title' => 'U like it packages',
			'legacy_home_solutions_label' => 'For retailers and gastronomy',
			'legacy_home_solutions_title' => 'Our solutions for businesses',
			'legacy_home_solutions_register_text' => 'Register business',
			'legacy_home_faq_label' => 'FAQ',
			'legacy_home_faq_title' => 'Everything you need to know',
			'legacy_home_cta_title' => "Join now\nand experience local deals.",
			'legacy_home_cta_primary_text' => 'Register business',
			'legacy_home_cta_secondary_text' => 'Download app',
			'legacy_home_ticker_items' => 'TROUSERS|SWEATERS|JACKETS|HATS|ACCESSORIES|DRESSES|SHOES|BAGS|JEANS|COATS|SHIRTS|FABRICS',
			'legacy_user_ticker_items' => 'FASHION|FOOD|CAFES|BOUTIQUES|RESTAURANTS|BARS|CONCEPT STORES|LOCAL DEALS',
			'legacy_merchant_benefits_ticker_items' => 'BOUTIQUES|FASHION|CONCEPT STORES|ACCESSORIES|LOCAL RETAILERS|SHOES|JEWELRY|INTERIOR',
			'legacy_merchant_dashboard_ticker_items' => 'MORE FOOTFALL|LOCAL DEALS|MEASURABLE|NO WASTAGE|QR REDEMPTION|PLANNABLE|SIMPLE|LOCAL',
			'wpforms_missing' => 'Please connect an English WPForms form ID or shortcode in Leadwerk options.',
			'wpforms_name_label' => 'Name',
			'wpforms_first_name_placeholder' => 'First name',
			'wpforms_last_name_placeholder' => 'Last name',
			'wpforms_email_label' => 'Email address',
			'wpforms_email_placeholder' => 'your@email.com',
			'wpforms_message_label' => 'Your message',
			'wpforms_message_placeholder' => 'What is it about? What is on your mind right now?',
			'wpforms_submit_label' => 'Send message',
			'wpforms_consent_prefix' => 'I have read the ',
			'wpforms_consent_link_label' => 'privacy policy',
			'wpforms_consent_suffix' => ' and agree.',
			'cmplz_title' => 'Manage consent',
			'cmplz_message' => 'To provide the best experience, we use technologies like cookies to store and/or access device information. If you consent to these technologies, we may process data such as browsing behavior or unique IDs on this site. If you do not consent or withdraw your consent, certain features and functions may be affected.',
			'cmplz_category_functional_title' => 'Functional',
			'cmplz_category_functional_desc' => 'The technical storage or access is strictly necessary for the legitimate purpose of enabling the use of a specific service explicitly requested by the subscriber or user, or for the sole purpose of carrying out the transmission of a communication over an electronic communications network.',
			'cmplz_category_preferences_title' => 'Preferences',
			'cmplz_category_preferences_desc' => 'The technical storage or access is necessary for the legitimate purpose of storing preferences that are not requested by the subscriber or user.',
			'cmplz_category_statistics_title' => 'Statistics',
			'cmplz_category_statistics_desc' => 'The technical storage or access that is used exclusively for statistical purposes.',
			'cmplz_category_statistics_anonymous_desc' => 'Without a subpoena, the voluntary consent of your Internet service provider, or additional records from third parties, the information stored or accessed for this purpose alone usually cannot be used to identify you.',
			'cmplz_category_marketing_title' => 'Marketing',
			'cmplz_category_marketing_desc' => 'The technical storage or access is required to create user profiles, send advertising, or track the user across one website or across several websites for similar marketing purposes.',
			'cmplz_always_active' => 'Always active',
			'cmplz_manage_options' => 'Manage options',
			'cmplz_manage_services' => 'Manage services',
			'cmplz_manage_vendors' => 'Manage {vendor_count} vendors',
			'cmplz_read_more_purposes' => 'Read more about these purposes',
			'cmplz_accept' => 'Accept',
			'cmplz_deny' => 'Deny',
			'cmplz_view_preferences' => 'View preferences',
			'cmplz_save_preferences' => 'Save preferences',
			'cmplz_placeholder_accept' => 'Click here to accept {category} cookies and enable this content',
		)
		: array(
			'services_menu_label' => 'Leistungen',
			'header_language_group_label' => 'Sprache wählen',
			'header_language_button_label' => 'Sprache wechseln',
			'header_open_menu_label' => 'Menü öffnen',
			'header_language_option_de' => 'Deutsch',
			'header_language_option_en' => 'English',
			'header_contact_cta_label' => 'Kontakt aufnehmen',
			'header_logo_alt' => 'ACM AIR CHARTER Logo',
			'header_logo_link_aria_label' => 'ACM AIR CHARTER Startseite',
			'footer_tagline' => 'Premium Business Aviation aus einer Hand – Charter, Management, Maintenance.',
			'footer_copyright' => '© 2026 ACM AIR CHARTER GmbH. Alle Rechte vorbehalten.',
			'footer_legal_heading' => 'Rechtliches',
			'footer_social_heading' => 'Folgen Sie uns',
			'footer_agb_link_label' => 'AGB',
			'footer_camo_link_label' => 'CAMO',
			'footer_social_linkedin_aria' => 'LinkedIn',
			'footer_social_instagram_aria' => 'Instagram',
			'footer_logo_alt' => 'ACM Logo',
			'footer_wordmark_alt' => 'ACM AIR CHARTER',
			'footer_services_heading' => 'Leistungen',
			'footer_company_heading' => 'Unternehmen',
			'footer_contact_heading' => 'Kontakt',
			'footer_menu_home' => 'Startseite',
			'footer_phone_prefix' => 'Tel.:',
			'footer_desc_home' => 'ACM AIR CHARTER — Premium Business Aviation. Charterflüge mit Bombardier Global Jets, professionelles Aircraft Management, zertifizierte Maintenance (Part-145) und CAMO aus einer Hand.',
			'footer_desc_general' => 'ACM AIR CHARTER bietet Premium Business Aviation Services aus Rheinmünster. Charter, Aircraft Management, Maintenance und CAMO — alles aus einer Hand.',
			'footer_desc_legal' => 'ACM AIR CHARTER GmbH — Premium Business Aviation. Charter, Aircraft Management, Maintenance (Part-145) und CAMO aus einer Hand.',
			'contact_privacy_link_label' => 'Datenschutz',
			'structured_open_link_label' => 'Link öffnen',
			'ui_learn_more_label' => 'Mehr erfahren',
			'ui_step_label' => 'Schritt',
			'ui_steps_nav_label' => 'Schritte',
			'ui_prev_step_label' => 'Vorheriger Schritt',
			'ui_next_step_label' => 'Nächster Schritt',
			'ui_swipe_or_click_hint' => 'Wischen oder klicken',
			'ui_close_label' => 'Schließen',
			'news_read_more_label' => 'Weiterlesen',
			'store_badge_apple_aria_label' => 'Im App Store herunterladen',
			'store_badge_apple_alt' => 'Im App Store herunterladen',
			'store_badge_google_aria_label' => 'Bei Google Play herunterladen',
			'store_badge_google_alt' => 'Bei Google Play herunterladen',
			'legacy_home_hero_title_gradient' => 'Rette&nbsp;deine Innenstadt.',
			'legacy_home_hero_typewriter_words' => 'Shoppe lokal.|Finde Deals.|Entdecke Mode.|Stärke deine Stadt.',
			'legacy_home_hero_cta_text' => 'App herunterladen',
			'legacy_home_why_label' => 'Warum U-like-it?',
			'legacy_home_why_title' => 'Weil lokal einfach besser ist.',
			'legacy_home_app_steps_title' => 'So funktioniert die App',
			'legacy_home_packages_label' => 'Das sind',
			'legacy_home_packages_title' => 'U like it Pakete',
			'legacy_home_solutions_label' => 'Für Händler & Gastronomie',
			'legacy_home_solutions_title' => 'Unsere Lösungen für Unternehmen',
			'legacy_home_solutions_register_text' => 'Unternehmen registrieren',
			'legacy_home_faq_label' => 'FAQ',
			'legacy_home_faq_title' => 'Alles was du wissen musst',
			'legacy_home_cta_title' => "Jetzt mitmachen\nund lokale Deals erleben.",
			'legacy_home_cta_primary_text' => 'Unternehmen registrieren',
			'legacy_home_cta_secondary_text' => 'App herunterladen',
			'legacy_home_ticker_items' => 'HOSEN|PULLOVER|JACKEN|HÜTE|ACCESSOIRES|KLEIDER|SCHUHE|TASCHEN|JEANS|MÄNTEL|SHIRTS|STOFFE',
			'legacy_user_ticker_items' => 'FASHION|GASTRO|CAFES|BOUTIQUEN|RESTAURANTS|BARS|CONCEPT STORES|LOKALE DEALS',
			'legacy_merchant_benefits_ticker_items' => 'BOUTIQUEN|FASHION|CONCEPT STORES|ACCESSOIRES|LOKALE HÄNDLER|SCHUHE|SCHMUCK|INTERIOR',
			'legacy_merchant_dashboard_ticker_items' => 'MEHR FREQUENZ|LOKALE DEALS|MESSBAR|KEIN STREUVERLUST|QR-EINLÖSUNG|PLANBAR|EINFACH|LOKAL',
			'wpforms_missing' => 'Bitte hinterlege eine WPForms Formular-ID oder einen Shortcode fuer Deutsch in den Leadwerk Optionen.',
			'wpforms_name_label' => 'Name',
			'wpforms_first_name_placeholder' => 'Vorname',
			'wpforms_last_name_placeholder' => 'Nachname',
			'wpforms_email_label' => 'E-Mail-Adresse',
			'wpforms_email_placeholder' => 'deine@email.de',
			'wpforms_message_label' => 'Deine Nachricht',
			'wpforms_message_placeholder' => 'Worum geht es? Was beschaeftigt dich gerade?',
			'wpforms_submit_label' => 'Nachricht senden',
			'wpforms_consent_prefix' => 'Ich habe die ',
			'wpforms_consent_link_label' => 'Datenschutzbestimmungen',
			'wpforms_consent_suffix' => ' gelesen und bin einverstanden.',
			'cmplz_title' => 'Zustimmung verwalten',
			'cmplz_message' => 'Um dir ein optimales Erlebnis zu bieten, verwenden wir Technologien wie Cookies, um Geräteinformationen zu speichern und/oder darauf zuzugreifen. Wenn du diesen Technologien zustimmst, können wir Daten wie das Surfverhalten oder eindeutige IDs auf dieser Website verarbeiten. Wenn du deine Zustimmung nicht erteilst oder zurückziehst, können bestimmte Merkmale und Funktionen beeinträchtigt werden.',
			'cmplz_category_functional_title' => 'Funktional',
			'cmplz_category_functional_desc' => 'Die technische Speicherung oder der Zugang ist unbedingt erforderlich für den rechtmäßigen Zweck, die Nutzung eines bestimmten Dienstes zu ermöglichen, der vom Teilnehmer oder Nutzer ausdrücklich gewünscht wird, oder für den alleinigen Zweck, die Übertragung einer Nachricht über ein elektronisches Kommunikationsnetz durchzuführen.',
			'cmplz_category_preferences_title' => 'Preferences',
			'cmplz_category_preferences_desc' => 'The technical storage or access is necessary for the legitimate purpose of storing preferences that are not requested by the subscriber or user.',
			'cmplz_category_statistics_title' => 'Statistiken',
			'cmplz_category_statistics_desc' => 'The technical storage or access that is used exclusively for statistical purposes.',
			'cmplz_category_statistics_anonymous_desc' => 'Die technische Speicherung oder der Zugriff, der ausschließlich zu anonymen statistischen Zwecken verwendet wird. Ohne eine Vorladung, die freiwillige Zustimmung deines Internetdienstanbieters oder zusätzliche Aufzeichnungen von Dritten können die zu diesem Zweck gespeicherten oder abgerufenen Informationen allein in der Regel nicht dazu verwendet werden, dich zu identifizieren.',
			'cmplz_category_marketing_title' => 'Marketing',
			'cmplz_category_marketing_desc' => 'Die technische Speicherung oder der Zugriff ist erforderlich, um Nutzerprofile zu erstellen, um Werbung zu versenden oder um den Nutzer auf einer Website oder über mehrere Websites hinweg zu ähnlichen Marketingzwecken zu verfolgen.',
			'cmplz_always_active' => 'Immer aktiv',
			'cmplz_manage_options' => 'Optionen verwalten',
			'cmplz_manage_services' => 'Dienste verwalten',
			'cmplz_manage_vendors' => 'Verwalten von {vendor_count}-Lieferanten',
			'cmplz_read_more_purposes' => 'Lese mehr über diese Zwecke',
			'cmplz_accept' => 'Akzeptieren',
			'cmplz_deny' => 'Ablehnen',
			'cmplz_view_preferences' => 'Einstellungen ansehen',
			'cmplz_save_preferences' => 'Einstellungen speichern',
			'cmplz_placeholder_accept' => 'Klicke hier, um {category}-Cookies zu akzeptieren und diesen Inhalt zu aktivieren',
		);

	$package_strings = class_exists( 'Leadwerk_Translation_API' )
		? Leadwerk_Translation_API::get_package_strings( 'theme_strings', $lang, array() )
		: array();

	if ( ! empty( $package_strings ) ) {
		return leadwerk_theme_merge_non_empty_strings( $defaults, $package_strings );
	}

	$raw = class_exists( 'Leadwerk_Translation_API' )
		? Leadwerk_Translation_API::get_localized_option( 'theme_strings', $lang, '' )
		: leadwerk_theme_get_option_value( 'en' === $lang ? 'theme_strings_en' : 'theme_strings_de', '' );

	if ( is_array( $raw ) ) {
		return leadwerk_theme_merge_non_empty_strings( $defaults, $raw );
	}

	if ( '' === trim( $raw ) ) {
		return $defaults;
	}

	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? leadwerk_theme_merge_non_empty_strings( $defaults, $decoded ) : $defaults;
}

/**
 * Get one translated theme string with a fallback.
 *
 * @param string      $key      String key.
 * @param string      $fallback Fallback label.
 * @param string|null $lang     Optional language code.
 * @return string
 */
function leadwerk_theme_get_string( $key, $fallback = '', $lang = null ) {
	$strings = leadwerk_theme_get_theme_strings( $lang );
	$value   = isset( $strings[ $key ] ) ? trim( (string) $strings[ $key ] ) : '';

	return '' !== $value ? $value : (string) $fallback;
}

/**
 * Get one translated string list split by pipes or line breaks.
 *
 * @param string        $key      String key.
 * @param array<string> $fallback Fallback items.
 * @param string|null   $lang     Optional language code.
 * @return array<string>
 */
function leadwerk_theme_get_string_list( $key, $fallback = array(), $lang = null ) {
	$raw = leadwerk_theme_get_string( $key, '', $lang );
	if ( '' === trim( $raw ) ) {
		return array_values( array_filter( array_map( 'trim', (array) $fallback ) ) );
	}

	$items = preg_split( '/\r\n|\r|\n|\|/', $raw );
	return array_values( array_filter( array_map( 'trim', (array) $items ) ) );
}

/**
 * Get WPForms contact-form translations for the active language.
 *
 * @param string|null $lang Optional language code.
 * @return array<string,string>
 */
function leadwerk_theme_get_wpforms_translations( $lang = null ) {
	$strings = leadwerk_theme_get_theme_strings( $lang );

	return array(
		'nameLabel'            => (string) ( $strings['wpforms_name_label'] ?? '' ),
		'firstNamePlaceholder' => (string) ( $strings['wpforms_first_name_placeholder'] ?? '' ),
		'lastNamePlaceholder'  => (string) ( $strings['wpforms_last_name_placeholder'] ?? '' ),
		'emailLabel'           => (string) ( $strings['wpforms_email_label'] ?? '' ),
		'emailPlaceholder'     => (string) ( $strings['wpforms_email_placeholder'] ?? '' ),
		'messageLabel'         => (string) ( $strings['wpforms_message_label'] ?? '' ),
		'messagePlaceholder'   => (string) ( $strings['wpforms_message_placeholder'] ?? '' ),
		'submitLabel'          => (string) ( $strings['wpforms_submit_label'] ?? '' ),
		'consentPrefix'        => (string) ( $strings['wpforms_consent_prefix'] ?? '' ),
		'consentLinkLabel'     => (string) ( $strings['wpforms_consent_link_label'] ?? '' ),
		'consentSuffix'        => (string) ( $strings['wpforms_consent_suffix'] ?? '' ),
	);
}

/**
 * Get visible Complianz banner translations for the active language.
 *
 * @param string|null $lang Optional language code.
 * @return array<string,string>
 */
function leadwerk_theme_get_complianz_banner_translations( $lang = null ) {
	$lang         = $lang ?: leadwerk_theme_get_current_lang();
	$default_lang = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::get_default_language() : 'de';
	if ( $lang === $default_lang ) {
		return array();
	}

	$strings = leadwerk_theme_get_theme_strings( $lang );

	return array(
		'title'                          => (string) ( $strings['cmplz_title'] ?? '' ),
		'message'                        => (string) ( $strings['cmplz_message'] ?? '' ),
		'functionalTitle'                => (string) ( $strings['cmplz_category_functional_title'] ?? '' ),
		'functionalDescription'          => (string) ( $strings['cmplz_category_functional_desc'] ?? '' ),
		'preferencesTitle'               => (string) ( $strings['cmplz_category_preferences_title'] ?? '' ),
		'preferencesDescription'         => (string) ( $strings['cmplz_category_preferences_desc'] ?? '' ),
		'statisticsTitle'                => (string) ( $strings['cmplz_category_statistics_title'] ?? '' ),
		'statisticsDescription'          => (string) ( $strings['cmplz_category_statistics_desc'] ?? '' ),
		'statisticsAnonymousDescription' => (string) ( $strings['cmplz_category_statistics_anonymous_desc'] ?? '' ),
		'marketingTitle'                 => (string) ( $strings['cmplz_category_marketing_title'] ?? '' ),
		'marketingDescription'           => (string) ( $strings['cmplz_category_marketing_desc'] ?? '' ),
		'alwaysActive'                   => (string) ( $strings['cmplz_always_active'] ?? '' ),
		'manageOptions'                  => (string) ( $strings['cmplz_manage_options'] ?? '' ),
		'manageServices'                 => (string) ( $strings['cmplz_manage_services'] ?? '' ),
		'manageVendors'                  => (string) ( $strings['cmplz_manage_vendors'] ?? '' ),
		'readMorePurposes'               => (string) ( $strings['cmplz_read_more_purposes'] ?? '' ),
		'accept'                         => (string) ( $strings['cmplz_accept'] ?? '' ),
		'deny'                           => (string) ( $strings['cmplz_deny'] ?? '' ),
		'viewPreferences'                => (string) ( $strings['cmplz_view_preferences'] ?? '' ),
		'savePreferences'                => (string) ( $strings['cmplz_save_preferences'] ?? '' ),
		'placeholderAccept'             => (string) ( $strings['cmplz_placeholder_accept'] ?? '' ),
	);
}
