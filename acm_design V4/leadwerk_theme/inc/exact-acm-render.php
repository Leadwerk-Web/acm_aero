<?php
/**
 * Exact shell renderer for ACM AIR CHARTER pages.
 *
 * Ported from FINORA exact-finora-render.php with ACM-specific
 * header/footer navigation, layout binders, and template maps.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ══════════════════════════════════════════════════════════════
 * 1. PAGE GROUP / SECTION RENDERING
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_render_exact_page_group( $group, $value, $post_id = 0 ) {
	$resolved = function_exists( 'leadwerk_theme_resolve_structured_group_value' )
		? leadwerk_theme_resolve_structured_group_value( $group, $value, $post_id )
		: array(
			'value'         => $value,
			'override_html' => '',
		);

	$value = $resolved['value'] ?? $value;

	if ( ! empty( $resolved['override_html'] ) ) {
		return (string) $resolved['override_html'];
	}

	if ( empty( $group['layouts'] ) ) {
		return leadwerk_theme_render_exact_legal_group( $group, $value, $post_id );
	}

	$source_key        = (string) get_post_meta( $post_id, 'leadwerk_source_key', true );
	$template_sections = leadwerk_theme_get_source_template_sections( $source_key );
	$sections          = is_array( $value ) ? array_values( $value ) : array();
	$output            = '';
	$index             = 0;

	$expected_shells = count( (array) $group['layouts'] );
	$actual_shells   = count( $template_sections );
	if ( $actual_shells !== $expected_shells && $post_id && current_user_can( 'edit_post', $post_id ) ) {
		$output .= leadwerk_theme_render_exact_runtime_notice(
			sprintf(
				'Exact shell mismatch: %1$d HTML section(s) in theme source_shells vs %2$d schema layout(s). Source key: %3$s. Update theme source_shells or schema so counts match (missing sections produce empty output).',
				$actual_shells,
				$expected_shells,
				$source_key ? $source_key : '(none)'
			),
			$post_id
		);
	}

	foreach ( (array) $group['layouts'] as $layout_key => $layout_schema ) {
		$section_value = isset( $sections[ $index ] ) && is_array( $sections[ $index ] ) ? $sections[ $index ] : array();
		$template_html = isset( $template_sections[ $index ] ) ? $template_sections[ $index ] : '';
		$output       .= leadwerk_theme_render_exact_layout_section( $layout_key, $layout_schema, $section_value, $template_html );
		$is_contact_page = ( 'acm-contact-v1' === $source_key )
			|| ( isset( $group['field_name'] ) && 'acm_contact_sections' === (string) $group['field_name'] );
		if ( $is_contact_page && 1 === $index ) {
			$output .= leadwerk_theme_get_acm_contact_anchor_nav_markup();
		}
		++$index;
	}

	if ( '' === trim( wp_strip_all_tags( $output ) ) ) {
		return leadwerk_theme_render_exact_runtime_notice(
			'Exact shell rendering produced no visible content for "' . (string) ( $group['label'] ?? 'page' ) . '". Check source_shells and Leadwerk field data.',
			$post_id
		);
	}

	return $output;
}

function leadwerk_theme_render_exact_runtime_notice( $message, $post_id = 0 ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return '';
	}

	return '<div class="runtime-notice runtime-notice--exact" style="margin:24px auto;max-width:1180px;padding:16px 18px;border:1px solid #fdba74;border-radius:16px;background:#fff7ed;color:#9a3412;">' . esc_html( $message ) . '</div>';
}

function leadwerk_theme_render_exact_legal_group( $group, $value, $post_id = 0 ) {
	$source_key = (string) get_post_meta( $post_id, 'leadwerk_source_key', true );
	$sections   = leadwerk_theme_get_source_template_sections( $source_key );

	if ( empty( $sections[0] ) || ! is_array( $value ) ) {
		return leadwerk_theme_render_exact_runtime_notice(
			'Exact legal shell is missing for "' . (string) ( $group['label'] ?? 'page' ) . '".',
			$post_id
		);
	}

	list( $dom, $xpath, $section_node ) = leadwerk_theme_create_template_dom( $sections[0] );
	if ( ! $section_node ) {
		return '';
	}

	leadwerk_theme_dom_set_inner_html(
		leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"legal-title")][1]', $section_node ),
		(string) ( $value['headline'] ?? '' )
	);
	leadwerk_theme_dom_set_inner_html(
		leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"legal-body")][1] | .//*[contains(@class,"legal-copy")][1]', $section_node ),
		(string) ( $value['content'] ?? '' )
	);

	return leadwerk_theme_dom_outer_html( $section_node );
}

function leadwerk_theme_render_exact_layout_section( $layout_key, $layout_schema, $section, $template_html ) {
	if ( '' === trim( $template_html ) ) {
		return '';
	}

	list( $dom, $xpath, $section_node ) = leadwerk_theme_create_template_dom( $template_html );
	if ( ! $section_node ) {
		return '';
	}

	leadwerk_theme_normalize_template_urls( $xpath, $section_node );

	$template = (string) ( $layout_schema['template'] ?? $layout_key );

	switch ( $template ) {
		/* ── ACM AIR CHARTER layouts ── */
		case 'acm_hero_video':
			leadwerk_theme_bind_exact_acm_hero_video( $xpath, $section_node, $section );
			break;
		case 'acm_hero_statement':
			leadwerk_theme_bind_exact_acm_hero_statement( $xpath, $section_node, $section );
			break;
		case 'acm_fullwidth_promo':
			leadwerk_theme_bind_exact_acm_fullwidth_promo( $xpath, $section_node, $section );
			break;
		case 'acm_intro_centered':
			leadwerk_theme_bind_exact_acm_intro_centered( $xpath, $section_node, $section );
			break;
		case 'acm_certifications_grid':
			leadwerk_theme_bind_exact_acm_certifications_grid( $xpath, $section_node, $section );
			break;
		case 'acm_split_icon_features':
			leadwerk_theme_bind_exact_acm_split_icon_features( $xpath, $section_node, $section );
			break;
		case 'acm_split_rows':
			leadwerk_theme_bind_exact_acm_split_rows( $xpath, $section_node, $section );
			break;
		case 'acm_process_split':
			leadwerk_theme_bind_exact_acm_process_split( $xpath, $section_node, $section );
			break;
		case 'acm_kpi_strip':
			leadwerk_theme_bind_exact_acm_kpi_strip( $xpath, $section_node, $section );
			break;
		case 'acm_home_final_cta':
			leadwerk_theme_bind_exact_acm_home_final_cta( $xpath, $section_node, $section );
			break;
		case 'acm_hero':
			leadwerk_theme_bind_exact_acm_hero( $xpath, $section_node, $section );
			break;
		case 'acm_services_grid':
			leadwerk_theme_bind_exact_acm_services_grid( $xpath, $section_node, $section );
			break;
		case 'acm_about_teaser':
			leadwerk_theme_bind_exact_acm_about_teaser( $xpath, $section_node, $section );
			break;
		case 'acm_charter_hero':
			leadwerk_theme_bind_exact_acm_charter_hero( $xpath, $section_node, $section );
			break;
		case 'acm_content_block':
			leadwerk_theme_bind_exact_acm_content_block( $xpath, $section_node, $section );
			break;
		case 'acm_news_teaser':
			leadwerk_theme_bind_exact_acm_news_teaser( $xpath, $section_node, $section );
			break;
		case 'acm_horizontal_timeline':
			leadwerk_theme_bind_exact_acm_horizontal_timeline( $xpath, $section_node, $section );
			break;
		case 'acm_contact_cta':
			leadwerk_theme_bind_exact_acm_contact_cta( $xpath, $section_node, $section );
			break;
		case 'acm_fleet_overview':
			leadwerk_theme_bind_exact_acm_fleet_overview( $xpath, $section_node, $section );
			break;
		case 'acm_aircraft_specs':
			leadwerk_theme_bind_exact_acm_aircraft_specs( $xpath, $section_node, $section );
			break;
		case 'acm_zone_cards':
			leadwerk_theme_bind_exact_acm_zone_cards( $xpath, $section_node, $section );
			break;
		case 'acm_image_carousel':
			leadwerk_theme_bind_exact_acm_image_carousel( $xpath, $section_node, $section );
			break;
		case 'acm_featured_figure':
			leadwerk_theme_bind_exact_acm_featured_figure( $xpath, $section_node, $section );
			break;
		case 'acm_fleet_teaser':
			leadwerk_theme_bind_exact_acm_fleet_teaser( $xpath, $section_node, $section );
			break;
		case 'acm_betriebsmodelle':
			leadwerk_theme_bind_exact_acm_betriebsmodelle( $xpath, $section_node, $section );
			break;
		case 'acm_aog_callout':
			leadwerk_theme_bind_exact_acm_aog_callout( $xpath, $section_node, $section );
			break;
		case 'acm_area_cards':
			leadwerk_theme_bind_exact_acm_area_cards( $xpath, $section_node, $section );
			break;
		case 'acm_split_highlights':
			leadwerk_theme_bind_exact_acm_split_highlights( $xpath, $section_node, $section );
			break;
		case 'acm_stellen':
			leadwerk_theme_bind_exact_acm_stellen( $xpath, $section_node, $section );
			break;
		case 'acm_contact_command_grid':
			leadwerk_theme_bind_exact_acm_contact_command_grid( $xpath, $section_node, $section );
			break;
		case 'acm_contact_dept_section':
			leadwerk_theme_bind_exact_acm_contact_dept_section( $xpath, $section_node, $section );
			break;
		case 'acm_contact_inquiry_band':
			leadwerk_theme_bind_exact_acm_contact_inquiry_band( $xpath, $section_node, $section );
			break;
		case 'acm_departments':
			leadwerk_theme_bind_exact_acm_departments( $xpath, $section_node, $section );
			break;
		case 'acm_news_filter_bar':
			leadwerk_theme_bind_exact_acm_news_filter_bar( $xpath, $section_node, $section );
			break;
		case 'acm_news_grid':
			leadwerk_theme_bind_exact_acm_news_grid( $xpath, $section_node, $section );
			break;
		case 'acm_news_archive':
			leadwerk_theme_bind_exact_acm_news_archive( $xpath, $section_node, $section );
			break;

		/* ── Legacy FINORA layouts (kept for backward compat) ── */
		case 'hero':
			leadwerk_theme_bind_exact_hero( $xpath, $section_node, $section, false !== strpos( (string) $template_html, 'class="btn' ) );
			break;
		case 'hero_slider':
			leadwerk_theme_bind_exact_hero_slider( $xpath, $section_node, $section );
			break;
		case 'pillars':
			leadwerk_theme_bind_exact_pillars( $xpath, $section_node, $section );
			break;
		case 'why_acm':
		case 'why_finora':
			leadwerk_theme_bind_exact_why_acm( $xpath, $section_node, $section );
			break;
		case 'how_it_works':
			leadwerk_theme_bind_exact_how_it_works( $xpath, $section_node, $section );
			break;
		case 'testimonials':
			leadwerk_theme_bind_exact_testimonials( $xpath, $section_node, $section );
			break;
		case 'faq':
			leadwerk_theme_bind_exact_faq( $xpath, $section_node, $section );
			break;
		case 'banner_cta':
			leadwerk_theme_bind_exact_banner_cta( $xpath, $section_node, $section );
			break;
		case 'media_text':
			leadwerk_theme_bind_exact_media_text( $xpath, $section_node, $section );
			break;
		case 'center_cta':
			leadwerk_theme_bind_exact_center_cta( $xpath, $section_node, $section );
			break;
		case 'contact_main':
			leadwerk_theme_bind_exact_contact_main( $xpath, $section_node, $section );
			break;
	}

	return leadwerk_theme_dom_outer_html( $section_node );
}

/* ══════════════════════════════════════════════════════════════
 * 2. SOURCE TEMPLATE MAP & BODY CLASS MAP
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_get_source_template_map() {
	return array(
		'acm-index-v1'       => 'index.html',
		'acm-thats-acm-v1'   => 'thats-acm.html',
		'acm-charter-v1'     => 'charter.html',
		'acm-global-7500-v1' => 'global-7500.html',
		'acm-global-6000-v1' => 'global-6000.html',
		'acm-global-xrs-v1'  => 'global-xrs.html',
		'acm-aircraft-v1'    => 'aircraft-management.html',
		'acm-maintenance-v1' => 'maintenance.html',
		'acm-careers-v1'     => 'karriere.html',
		'acm-contact-v1'     => 'kontakt.html',
		'acm-news-v1'        => 'news.html',
		'acm-impressum-v1'   => 'impressum.html',
		'acm-datenschutz-v1' => 'datenschutz.html',
		'acm-404-v1'         => '404.html',
		// Legacy aliases.
		'acm-home-v1'        => 'index.html',
		'acm-about-v1'       => 'thats-acm.html',
	);
}

function leadwerk_theme_get_source_template_body_class_map() {
	return array(
		'acm-index-v1'       => 'page-home',
		'acm-thats-acm-v1'   => 'page-thats-acm',
		'acm-charter-v1'     => 'page-charter',
		'acm-global-7500-v1' => 'page-global-7500',
		'acm-global-6000-v1' => 'page-global-6000',
		'acm-global-xrs-v1'  => 'page-global-xrs',
		'acm-aircraft-v1'    => 'page-aircraft',
		'acm-maintenance-v1' => 'page-maintenance',
		'acm-careers-v1'     => 'page-karriere',
		'acm-contact-v1'     => 'page-kontakt',
		'acm-news-v1'        => 'page-news',
		'acm-impressum-v1'   => 'page-impressum',
		'acm-datenschutz-v1' => 'page-datenschutz',
		'acm-404-v1'         => 'page-404 header-scrolled',
		'acm-home-v1'        => 'page-home',
		'acm-about-v1'       => 'page-thats-acm',
	);
}

function leadwerk_theme_normalize_body_class_string( $body_class ) {
	$classes = preg_split( '/\s+/', trim( (string) $body_class ) );
	$classes = is_array( $classes ) ? $classes : array();
	$classes = array_map( 'sanitize_html_class', $classes );
	$classes = array_values( array_unique( array_filter( $classes ) ) );
	return implode( ' ', $classes );
}

function leadwerk_theme_get_source_template_body_class( $source_key ) {
	static $cache = array();
	$source_key = (string) $source_key;
	if ( isset( $cache[ $source_key ] ) ) {
		return $cache[ $source_key ];
	}
	$body_class = '';
	$html       = leadwerk_theme_get_source_template_html( $source_key );
	if ( '' !== $html && preg_match( '/<body[^>]*class="([^"]+)"/i', $html, $matches ) ) {
		$body_class = leadwerk_theme_normalize_body_class_string( $matches[1] ?? '' );
	}
	if ( '' === $body_class ) {
		$fallback_map = leadwerk_theme_get_source_template_body_class_map();
		$body_class   = leadwerk_theme_normalize_body_class_string( $fallback_map[ $source_key ] ?? '' );
	}
	$cache[ $source_key ] = $body_class;
	return $cache[ $source_key ];
}

/* ══════════════════════════════════════════════════════════════
 * 3. SOURCE TEMPLATE LOADERS
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_get_source_template_sections( $source_key ) {
	static $cache = array();
	$source_key = (string) $source_key;
	if ( isset( $cache[ $source_key ] ) ) {
		return $cache[ $source_key ];
	}
	$file_map  = leadwerk_theme_get_source_template_map();
	$file_name = $file_map[ $source_key ] ?? '';
	if ( '' === $file_name ) {
		$cache[ $source_key ] = array();
		return $cache[ $source_key ];
	}
	$file_path = function_exists( 'leadwerk_theme_resolve_exact_shell_file' )
		? leadwerk_theme_resolve_exact_shell_file( $file_name )
		: '';
	if ( '' === $file_path || ! is_file( $file_path ) ) {
		$cache[ $source_key ] = array();
		return $cache[ $source_key ];
	}
	$html = file_get_contents( $file_path );
	if ( false === $html ) {
		$cache[ $source_key ] = array();
		return $cache[ $source_key ];
	}
	$cache[ $source_key ] = leadwerk_theme_extract_body_sections_from_html( (string) $html );
	return $cache[ $source_key ];
}

function leadwerk_theme_get_source_template_html( $source_key ) {
	static $cache = array();
	$source_key = (string) $source_key;
	if ( isset( $cache[ $source_key ] ) ) {
		return $cache[ $source_key ];
	}
	$file_map  = leadwerk_theme_get_source_template_map();
	$file_name = $file_map[ $source_key ] ?? '';
	if ( '' === $file_name ) {
		$cache[ $source_key ] = '';
		return '';
	}
	$file_path = function_exists( 'leadwerk_theme_resolve_exact_shell_file' )
		? leadwerk_theme_resolve_exact_shell_file( $file_name )
		: '';
	if ( '' === $file_path || ! is_file( $file_path ) ) {
		$cache[ $source_key ] = '';
		return '';
	}
	$html = file_get_contents( $file_path );
	if ( false === $html ) {
		$cache[ $source_key ] = '';
		return '';
	}
	$cache[ $source_key ] = (string) $html;
	return $cache[ $source_key ];
}

function leadwerk_theme_get_exact_shell_source_key( $source_key = '' ) {
	$source_key = (string) $source_key;
	$file_map   = leadwerk_theme_get_source_template_map();
	if ( function_exists( 'leadwerk_theme_uses_simple_page_template' ) && leadwerk_theme_uses_simple_page_template() ) {
		return 'acm-impressum-v1';
	}
	if ( isset( $file_map[ $source_key ] ) ) {
		return $source_key;
	}
	if ( '' === trim( $source_key ) && function_exists( 'leadwerk_theme_is_simple_content_page' ) && leadwerk_theme_is_simple_content_page() ) {
		return 'acm-impressum-v1';
	}
	return 'acm-index-v1';
}

/* ══════════════════════════════════════════════════════════════
 * 4. DOM HELPER FUNCTIONS (ported from FINORA)
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_create_document_dom( $html ) {
	$dom = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return array( $dom, new DOMXPath( $dom ) );
}

function leadwerk_theme_extract_body_sections_from_html( $html ) {
	$sections = array();
	$dom      = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );
	$list  = $xpath->query( '//body/main/section' );
	if ( ! ( $list instanceof DOMNodeList ) || 0 === $list->length ) {
		$list = $xpath->query( '//body/section' );
	}
	if ( $list instanceof DOMNodeList ) {
		foreach ( $list as $node ) {
			if ( $node instanceof DOMNode ) {
				$sections[] = $dom->saveHTML( $node );
			}
		}
	}
	return $sections;
}

/**
 * Sticky Bereichsnavigation for the contact page (not a <section>, therefore not in shell section list).
 *
 * @return string
 */
function leadwerk_theme_get_acm_contact_anchor_nav_markup() {
	return <<<'HTML'
<nav aria-label="Bereichsnavigation" class="anchor-nav" id="anchor-nav">
<div aria-hidden="true" class="scroll-progress" id="scroll-progress"></div>
<div class="w-full px-4 sm:px-6 lg:px-10">
<div class="anchor-nav-inner py-1">
<a class="anchor-pill" data-section="zentrale" href="#zentrale">Zentrale</a>
<a class="anchor-pill" data-section="geschaeftsfuehrung" href="#geschaeftsfuehrung">Gesch&auml;ftsf&uuml;hrung</a>
<a class="anchor-pill" data-section="sales-operations" href="#sales-operations">Sales &amp; Operations</a>
<a class="anchor-pill" data-section="camo" href="#camo">CAMO</a>
<a class="anchor-pill" data-section="ground-operations" href="#ground-operations">Ground Ops</a>
<a class="anchor-pill" data-section="maintenance" href="#maintenance">Maintenance</a>
<a class="anchor-pill" data-section="safety-compliance" href="#safety-compliance">Safety</a>
<a class="anchor-pill" data-section="stores" href="#stores">Stores</a>
</div>
</div>
</nav>

HTML;
}

function leadwerk_theme_create_template_dom( $html ) {
	$dom = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-root">' . $html . '</div>' );
	libxml_clear_errors();
	$xpath   = new DOMXPath( $dom );
	$section = leadwerk_theme_dom_first( $xpath, '//*[@id="leadwerk-root"]/*[1]' );
	return array( $dom, $xpath, $section instanceof DOMElement ? $section : null );
}

function leadwerk_theme_dom_query( $context, $query, $scope = null ) {
	if ( $context instanceof DOMXPath ) {
		$list = $context->query( $query, $scope );
	} else {
		$list = ( new DOMXPath( $context->ownerDocument ) )->query( $query, $context );
	}
	$nodes = array();
	if ( $list instanceof DOMNodeList ) {
		foreach ( $list as $node ) {
			if ( $node instanceof DOMNode ) {
				$nodes[] = $node;
			}
		}
	}
	return $nodes;
}

function leadwerk_theme_dom_first( $context, $query, $scope = null ) {
	$nodes = leadwerk_theme_dom_query( $context, $query, $scope );
	return ! empty( $nodes[0] ) ? $nodes[0] : null;
}

/**
 * Haupt-Absatz fuer ACM Content-/Split-Sektionen. Kein contains(@class,"text") — kollidiert mit Tailwind text-* auf Ueberschriften.
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $section Section root.
 * @return DOMElement|null
 */
function leadwerk_theme_dom_first_acm_main_paragraph( $xpath, $section ) {
	$queries = array(
		'.//div[contains(@class,"max-w-xl")]//p[contains(@class,"leading-relaxed")][1]',
		'.//div[contains(@class,"max-w-xl")]//p[1]',
		'.//div[contains(@class,"content-box-outer")]//p[contains(@class,"leading-relaxed")][1]',
		'.//div[contains(@class,"content-box-outer")]//p[1]',
		'.//p[contains(@class,"leading-relaxed")][1]',
		'.//p[contains(@class,"text-stone")][1]',
		'.//p[1]',
	);
	foreach ( $queries as $q ) {
		$n = leadwerk_theme_dom_first( $xpath, $q, $section );
		if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
			return $n;
		}
	}
	return null;
}

function leadwerk_theme_dom_outer_html( $node ) {
	return $node instanceof DOMNode ? $node->ownerDocument->saveHTML( $node ) : '';
}

function leadwerk_theme_dom_clear( $node ) {
	if ( ! $node instanceof DOMNode ) {
		return;
	}
	while ( $node->firstChild ) {
		$node->removeChild( $node->firstChild );
	}
}

function leadwerk_theme_dom_set_inner_html( $node, $html ) {
	if ( ! $node instanceof DOMNode ) {
		return;
	}
	$html = (string) $html;
	leadwerk_theme_dom_clear( $node );
	if ( '' === trim( $html ) ) {
		return;
	}
	$temp = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$temp->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-fragment">' . wp_kses_post( $html ) . '</div>' );
	libxml_clear_errors();
	$fragment_nodes = ( new DOMXPath( $temp ) )->query( '//*[@id="leadwerk-fragment"]/* | //*[@id="leadwerk-fragment"]/text()' );
	if ( ! $fragment_nodes instanceof DOMNodeList ) {
		return;
	}
	foreach ( $fragment_nodes as $child ) {
		$node->appendChild( $node->ownerDocument->importNode( $child, true ) );
	}
}

function leadwerk_theme_dom_set_trusted_inner_html( $node, $html ) {
	if ( ! $node instanceof DOMNode ) {
		return;
	}
	$html = (string) $html;
	leadwerk_theme_dom_clear( $node );
	if ( '' === trim( $html ) ) {
		return;
	}
	$temp = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$temp->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-fragment">' . $html . '</div>' );
	libxml_clear_errors();
	$fragment_nodes = ( new DOMXPath( $temp ) )->query( '//*[@id="leadwerk-fragment"]/* | //*[@id="leadwerk-fragment"]/text()' );
	if ( ! $fragment_nodes instanceof DOMNodeList ) {
		return;
	}
	foreach ( $fragment_nodes as $child ) {
		$node->appendChild( $node->ownerDocument->importNode( $child, true ) );
	}
}

function leadwerk_theme_dom_set_text( $node, $text ) {
	if ( ! $node instanceof DOMNode ) {
		return;
	}
	leadwerk_theme_dom_clear( $node );
	$node->appendChild( $node->ownerDocument->createTextNode( wp_strip_all_tags( (string) $text ) ) );
}

function leadwerk_theme_dom_set_attr( $node, $attr, $value ) {
	if ( ! $node instanceof DOMElement ) {
		return;
	}
	$value = (string) $value;
	if ( '' === trim( $value ) ) {
		$node->removeAttribute( $attr );
		return;
	}
	$node->setAttribute( $attr, $value );
}

function leadwerk_theme_dom_remove( $node ) {
	if ( $node instanceof DOMNode && $node->parentNode ) {
		$node->parentNode->removeChild( $node );
	}
}

function leadwerk_theme_dom_ensure_count( $nodes, $count ) {
	$nodes = array_values( array_filter( $nodes ) );
	$count = max( 0, (int) $count );
	if ( empty( $nodes ) ) {
		return array();
	}
	$template = end( $nodes );
	$parent   = $template instanceof DOMNode ? $template->parentNode : null;
	while ( count( $nodes ) > $count ) {
		$node = array_pop( $nodes );
		leadwerk_theme_dom_remove( $node );
	}
	if ( ! $parent || ! $template ) {
		return $nodes;
	}
	while ( count( $nodes ) < $count ) {
		$clone   = $template->cloneNode( true );
		$parent->appendChild( $clone );
		$nodes[] = $clone;
	}
	return $nodes;
}

function leadwerk_theme_dom_toggle_class( $node, $class, $enabled ) {
	if ( ! $node instanceof DOMElement ) {
		return;
	}
	$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
	$classes = array_filter( is_array( $classes ) ? $classes : array() );
	if ( $enabled && ! in_array( $class, $classes, true ) ) {
		$classes[] = $class;
	}
	if ( ! $enabled ) {
		$classes = array_values(
			array_filter(
				$classes,
				static function ( $item ) use ( $class ) {
					return $item !== $class;
				}
			)
		);
	}
	$node->setAttribute( 'class', trim( implode( ' ', $classes ) ) );
}

/* ══════════════════════════════════════════════════════════════
 * 5. MARKUP HELPERS
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_normalize_heading_markup( $html ) {
	$html = (string) $html;
	if ( class_exists( 'Leadwerk_Content_Schema' ) && method_exists( 'Leadwerk_Content_Schema', 'sanitize_heading_html' ) ) {
		return Leadwerk_Content_Schema::sanitize_heading_html( $html );
	}
	$html = wp_kses_post( $html );
	if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
		return '';
	}
	$html = preg_replace( '#</?(?:p|div|section|article|h1|h2|h3|h4|h5|h6)\b[^>]*>#i', '', $html );
	$html = preg_replace( '/(?:<br>\s*){3,}/i', '<br><br>', (string) $html );
	$html = is_string( $html ) ? trim( $html ) : '';
	return '' === trim( wp_strip_all_tags( $html ) ) ? '' : $html;
}

function leadwerk_theme_normalize_paragraph_markup( $html ) {
	$normalized = leadwerk_theme_normalize_heading_markup( $html );
	return '' === trim( wp_strip_all_tags( $normalized ) ) ? '' : $normalized;
}

function leadwerk_theme_force_strong_heading_markup( $html ) {
	$normalized = leadwerk_theme_normalize_heading_markup( $html );
	if ( '' === trim( wp_strip_all_tags( $normalized ) ) ) {
		return '';
	}
	if ( preg_match( '/^\s*<strong\b[^>]*>.*<\/strong>\s*$/is', $normalized ) ) {
		return $normalized;
	}
	return '<strong>' . $normalized . '</strong>';
}

function leadwerk_theme_set_placeholder_markup( $target, $html, $mode = 'container' ) {
	if ( ! $target instanceof DOMNode ) {
		return;
	}
	switch ( (string) $mode ) {
		case 'heading':
			$html = leadwerk_theme_normalize_heading_markup( $html );
			break;
		case 'paragraph':
			$html = leadwerk_theme_normalize_paragraph_markup( $html );
			break;
		case 'container':
		default:
			$html = (string) $html;
			break;
	}
	leadwerk_theme_dom_set_inner_html( $target, $html );
}

function leadwerk_theme_get_exact_image_url( $image_id, $fallback = '' ) {
	$image_id = absint( $image_id );
	if ( $image_id ) {
		$url = wp_get_attachment_image_url( $image_id, 'full' );
		if ( $url ) {
			return $url;
		}
	}
	return (string) $fallback;
}

/**
 * Mediathek-Anhang-ID oder Roh-URL (Legacy/Import).
 *
 * @param mixed $value Anhang-ID (int/string) oder URL-String.
 * @return string Aufgelöste URL oder getrimmter String.
 */
function leadwerk_theme_resolve_media_url( $value ) {
	if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && is_numeric( trim( $value ) ) ) ) {
		$id = (int) $value;
		if ( $id > 0 ) {
			$url = wp_get_attachment_url( $id );
			return $url ? $url : '';
		}
	}
	return trim( (string) $value );
}

function leadwerk_theme_bind_exact_image( $xpath, $context, $query, $image_id, $alt = '' ) {
	$image = leadwerk_theme_dom_first( $xpath, $query, $context );
	if ( ! $image instanceof DOMElement ) {
		return;
	}
	$fallback = (string) $image->getAttribute( 'src' );
	$url      = leadwerk_theme_get_exact_image_url( (int) $image_id, $fallback );
	leadwerk_theme_dom_set_attr( $image, 'src', $url );
	if ( '' !== trim( (string) $alt ) ) {
		leadwerk_theme_dom_set_attr( $image, 'alt', $alt );
	}
}

function leadwerk_theme_resolve_exact_href( $page_key, $url = '' ) {
	$page_key = trim( (string) $page_key );
	$url      = trim( (string) $url );
	if ( '' !== $page_key ) {
		return leadwerk_theme_get_page_url( $page_key, leadwerk_theme_get_current_lang(), '' !== $url ? $url : '#' );
	}
	return '' !== $url ? $url : '#';
}

function leadwerk_theme_bind_exact_button( $xpath, $context, $query, $label, $page_key = '', $url = '' ) {
	$button = leadwerk_theme_dom_first( $xpath, $query, $context );
	if ( ! $button instanceof DOMElement ) {
		return;
	}
	if ( '' === trim( (string) $label ) ) {
		leadwerk_theme_dom_remove( $button );
		return;
	}
	$page_key = trim( (string) $page_key );
	$url      = trim( (string) $url );
	if ( '' === $page_key && '' === $url ) {
		$plain = strtolower( html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( false !== strpos( $plain, 'maintenance' ) && ( false !== strpos( $plain, 'mehr' ) || false !== strpos( $plain, 'erfahren' ) ) ) {
			$page_key = 'acm-maintenance-v1';
		} elseif ( false !== strpos( $plain, 'stellenausschreibung' ) || ( false !== strpos( $plain, 'unseren' ) && false !== strpos( $plain, 'stellen' ) ) ) {
			$page_key = 'acm-careers-v1';
		} elseif ( false !== strpos( $plain, 'aircraft' ) && false !== strpos( $plain, 'management' ) ) {
			$page_key = 'acm-aircraft-v1';
		} elseif ( false !== strpos( $plain, 'mehr' ) && false !== strpos( $plain, 'acm' ) ) {
			$page_key = 'acm-thats-acm-v1';
		}
	}
	leadwerk_theme_dom_set_attr( $button, 'href', leadwerk_theme_resolve_exact_href( $page_key, $url ) );
	leadwerk_theme_dom_set_text( $button, $label );
}

/**
 * Set href (and optional label) on an anchor that keeps a trailing <svg> (e.g. link-arrow).
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $context Context node.
 * @param string   $query   Anchor query.
 * @param string   $label   Link text (optional if href is set).
 * @param string   $page_key Internal page key.
 * @param string   $url     URL fallback.
 * @param bool|string|null $download true = Dateiname aus URL-Pfad; non-empty string = Vorschlagsname; null = kein download-Attribut.
 */
function leadwerk_theme_bind_exact_anchor_keep_svg( $xpath, $context, $query, $label, $page_key = '', $url = '', $download = null ) {
	$a = leadwerk_theme_dom_first( $xpath, $query, $context );
	if ( ! $a instanceof DOMElement ) {
		return;
	}
	$label    = trim( wp_strip_all_tags( (string) $label ) );
	$page_key = trim( (string) $page_key );
	$url      = trim( (string) $url );
	$href     = leadwerk_theme_resolve_exact_href( $page_key, $url );
	if ( '' === $label && ( '' === $href || '#' === $href ) ) {
		leadwerk_theme_dom_remove( $a );
		return;
	}
	if ( '' !== $href && '#' !== $href ) {
		leadwerk_theme_dom_set_attr( $a, 'href', $href );
	}
	if ( null !== $download && '' !== $href && '#' !== $href ) {
		$fname = '';
		if ( true === $download ) {
			$path = (string) parse_url( $href, PHP_URL_PATH );
			$fname = $path ? sanitize_file_name( basename( $path ) ) : '';
			if ( '' === $fname ) {
				$fname = 'document.pdf';
			}
		} elseif ( is_string( $download ) && '' !== trim( $download ) ) {
			$fname = sanitize_file_name( $download );
			if ( '' === $fname ) {
				$fname = 'document.pdf';
			}
		}
		if ( '' !== $fname ) {
			$a->setAttribute( 'download', $fname );
		}
		if ( $a->hasAttribute( 'target' ) ) {
			$a->removeAttribute( 'target' );
		}
	}
	if ( '' === $label ) {
		return;
	}
	$svg = leadwerk_theme_dom_first( $xpath, './/svg[1]', $a );
	$rm  = array();
	foreach ( iterator_to_array( $a->childNodes, false ) as $child ) {
		if ( $child === $svg ) {
			break;
		}
		if ( $child instanceof DOMText ) {
			$rm[] = $child;
		} elseif ( $child instanceof DOMElement && 'svg' !== strtolower( $child->tagName ) ) {
			$rm[] = $child;
		}
	}
	foreach ( $rm as $n ) {
		if ( $n->parentNode === $a ) {
			$a->removeChild( $n );
		}
	}
	$doc = $a->ownerDocument;
	if ( $svg instanceof DOMElement && $doc ) {
		$a->insertBefore( $doc->createTextNode( $label . ' ' ), $svg );
	} else {
		leadwerk_theme_dom_set_text( $a, $label );
	}
}

/**
 * Set label text on an inline element while preserving its trailing SVG icon.
 *
 * @param DOMXPath $xpath XPath.
 * @param DOMNode  $context Context node.
 * @param string   $query Element query.
 * @param string   $label Label text.
 */
function leadwerk_theme_bind_exact_inline_label_keep_svg( $xpath, $context, $query, $label ) {
	$node = leadwerk_theme_dom_first( $xpath, $query, $context );
	if ( ! $node instanceof DOMElement ) {
		return;
	}
	$label = trim( wp_strip_all_tags( (string) $label ) );
	if ( '' === $label ) {
		leadwerk_theme_dom_remove( $node );
		return;
	}
	$svg = leadwerk_theme_dom_first( $xpath, './/svg[1]', $node );
	$rm  = array();
	foreach ( iterator_to_array( $node->childNodes, false ) as $child ) {
		if ( $child === $svg ) {
			break;
		}
		if ( $child instanceof DOMText ) {
			$rm[] = $child;
		} elseif ( $child instanceof DOMElement && 'svg' !== strtolower( $child->tagName ) ) {
			$rm[] = $child;
		}
	}
	foreach ( $rm as $child ) {
		if ( $child->parentNode === $node ) {
			$node->removeChild( $child );
		}
	}
	$doc = $node->ownerDocument;
	if ( $svg instanceof DOMElement && $doc ) {
		$node->insertBefore( $doc->createTextNode( $label . ' ' ), $svg );
	} else {
		leadwerk_theme_dom_set_text( $node, $label );
	}
}

/**
 * Set href and label on an anchor while preserving a leading <svg> icon.
 *
 * @param DOMXPath $xpath XPath.
 * @param DOMNode  $context Context node.
 * @param string   $query Anchor query.
 * @param string   $label Link label.
 * @param string   $page_key Internal page key.
 * @param string   $url URL fallback.
 */
function leadwerk_theme_bind_exact_anchor_keep_leading_svg( $xpath, $context, $query, $label, $page_key = '', $url = '' ) {
	$a = leadwerk_theme_dom_first( $xpath, $query, $context );
	if ( ! $a instanceof DOMElement ) {
		return;
	}
	$label    = trim( wp_strip_all_tags( (string) $label ) );
	$page_key = trim( (string) $page_key );
	$url      = trim( (string) $url );
	$href     = leadwerk_theme_resolve_exact_href( $page_key, $url );
	if ( '' === $label && ( '' === $href || '#' === $href ) ) {
		leadwerk_theme_dom_remove( $a );
		return;
	}
	if ( '' !== $href && '#' !== $href ) {
		leadwerk_theme_dom_set_attr( $a, 'href', $href );
	}
	if ( '' === $label ) {
		return;
	}
	$svg = leadwerk_theme_dom_first( $xpath, './/svg[1]', $a );
	$rm  = array();
	foreach ( iterator_to_array( $a->childNodes, false ) as $child ) {
		if ( $child === $svg ) {
			continue;
		}
		if ( $child instanceof DOMText ) {
			$rm[] = $child;
		} elseif ( $child instanceof DOMElement && 'svg' !== strtolower( $child->tagName ) ) {
			$rm[] = $child;
		}
	}
	foreach ( $rm as $child ) {
		if ( $child->parentNode === $a ) {
			$a->removeChild( $child );
		}
	}
	$doc = $a->ownerDocument;
	if ( $svg instanceof DOMElement && $doc ) {
		if ( $svg->nextSibling ) {
			$a->insertBefore( $doc->createTextNode( ' ' . $label ), $svg->nextSibling );
		} else {
			$a->appendChild( $doc->createTextNode( ' ' . $label ) );
		}
	} else {
		leadwerk_theme_dom_set_text( $a, $label );
	}
}

function leadwerk_theme_replace_style_url( $style, $url ) {
	$style = (string) $style;
	$url   = (string) $url;
	if ( '' === trim( $url ) ) {
		return $style;
	}
	if ( preg_match( '/url\\([\'"]?([^\'")]+)[\'"]?\\)/i', $style ) ) {
		return preg_replace( '/url\\([\'"]?([^\'")]+)[\'"]?\\)/i', "url('{$url}')", $style, 1 );
	}
	return rtrim( $style, '; ' ) . '; background-image:url(\'' . esc_url_raw( $url ) . '\');';
}

function leadwerk_theme_get_exact_blurb_html( $item ) {
	$title   = trim( (string) ( $item['title'] ?? '' ) );
	$content = (string) ( $item['content'] ?? '' );
	if ( '' !== $title ) {
		$content = preg_replace( '/^\s*<h4[^>]*>.*?<\/h4>/is', '', $content, 1 );
		return '<h4>' . esc_html( $title ) . '</h4>' . $content;
	}
	return $content;
}

function leadwerk_theme_get_prefixed_button_data( $item, $prefix ) {
	return array(
		'label'    => (string) ( $item[ $prefix . '_label' ] ?? '' ),
		'page_key' => (string) ( $item[ $prefix . '_page_key' ] ?? '' ),
		'url'      => (string) ( $item[ $prefix . '_url' ] ?? '' ),
	);
}

/* ══════════════════════════════════════════════════════════════
 * 6. URL NORMALIZER (with fragment hash fix)
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_map_template_href_to_url( $href ) {
	$href = trim( (string) $href );
	if ( '' === $href || '#' === $href || 0 === strpos( $href, '#' ) ) {
		return $href;
	}
	if ( preg_match( '#^(?:https?:)?//#i', $href ) || preg_match( '#^(?:mailto|tel):#i', $href ) ) {
		return $href;
	}

	$normalized = str_replace( '\\', '/', $href );
	$lang       = leadwerk_theme_get_current_lang();

	$static_base = trailingslashit( leadwerk_theme_get_static_source_base()['url'] );

	if ( 0 === strpos( $normalized, 'assets/' ) ) {
		return $static_base . ltrim( $normalized, '/' );
	}

	// Static site folders (shells reference Fotos/...); Emblem: assets/images oder Fotos unter source_assets.
	if ( preg_match( '#^(?:Fotos|fotos)/#i', $normalized ) ) {
		$rel = str_replace( '\\', '/', $normalized );
		if ( preg_match( '#^(?:Fotos|fotos)/Emblem\.svg$#i', $rel ) ) {
			return leadwerk_theme_get_emblem_asset_url();
		}
		return $static_base . $rel;
	}

	if ( 0 === strpos( $normalized, 'en/' ) ) {
		$lang       = 'en';
		$normalized = substr( $normalized, 3 );
	}

	// Extract fragment hash (#sales-operations etc.) before basename lookup.
	$fragment = '';
	$hash_pos = strpos( $normalized, '#' );
	if ( false !== $hash_pos ) {
		$fragment   = substr( $normalized, $hash_pos );
		$normalized = substr( $normalized, 0, $hash_pos );
	}

	// Shell-Links "news/slug.html" sind relativ zur Uebersichtsseite /news/ und werden sonst zu /news/news/slug... aufgeloest.
	if ( post_type_exists( 'acm_news' ) && preg_match( '#^news/([^/]+)\.html?$#i', $normalized, $nm ) ) {
		$slug = sanitize_title( rawurldecode( (string) $nm[1] ) );
		if ( '' !== $slug ) {
			$ids = get_posts(
				array(
					'post_type'           => 'acm_news',
					'name'                => $slug,
					'post_status'         => 'publish',
					'posts_per_page'      => 1,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'suppress_filters'    => false,
					'ignore_sticky_posts' => true,
				)
			);
			if ( ! empty( $ids ) ) {
				return (string) get_permalink( (int) $ids[0] ) . $fragment;
			}
			return trailingslashit( home_url( '/news/' . $slug . '/' ) ) . $fragment;
		}
	}

	$file_name  = basename( $normalized );
	$source_key = array_search( $file_name, leadwerk_theme_get_source_template_map(), true );
	if ( false !== $source_key ) {
		$fallback = 'de' === $lang ? home_url( '/' ) : home_url( '/' . $lang . '/' );
		return leadwerk_theme_get_page_url( (string) $source_key, $lang, $fallback ) . $fragment;
	}

	return $href;
}

/**
 * Teaser-Bilder: Fotos/news/{slug}.jpg im Theme existieren oft nicht — stattdessen Beitragsbild des acm_news mit gleichem Slug.
 *
 * @param string $mapped_src  Bereits durch leadwerk_theme_map_template_href_to_url() gehende URL.
 * @param string $original_src Rohwert aus dem Shell-HTML.
 * @return string
 */
function leadwerk_theme_upgrade_acm_news_listing_image_src( $mapped_src, $original_src ) {
	if ( ! post_type_exists( 'acm_news' ) ) {
		return $mapped_src;
	}
	foreach ( array( (string) $original_src, (string) $mapped_src ) as $candidate ) {
		$candidate = str_replace( '\\', '/', $candidate );
		if ( ! preg_match( '#(?:^|/)(?:Fotos|fotos)/news/([^/]+\.(?:jpe?g|png|webp))$#i', $candidate, $m ) ) {
			continue;
		}
		$stem = preg_replace( '/\.[^.]+$/i', '', $m[1] );
		$slug = sanitize_title( rawurldecode( $stem ) );
		if ( '' === $slug ) {
			continue;
		}
		$ids = get_posts(
			array(
				'post_type'           => 'acm_news',
				'name'                => $slug,
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
		if ( empty( $ids ) ) {
			continue;
		}
		$tid = (int) get_post_thumbnail_id( (int) $ids[0] );
		if ( $tid ) {
			$url = wp_get_attachment_image_url( $tid, 'large' );
			if ( $url ) {
				return $url;
			}
		}
		break;
	}
	return $mapped_src;
}

/**
 * Ein srcset-Segment (URL + optional Breite) normalisieren.
 *
 * @param string $part Segment.
 * @return string
 */
function leadwerk_theme_normalize_srcset_part_url( $part ) {
	$part = trim( (string) $part );
	if ( '' === $part ) {
		return '';
	}
	$segments   = preg_split( '/\s+/', $part, 2 );
	$orig       = (string) ( $segments[0] ?? '' );
	$url        = leadwerk_theme_map_template_href_to_url( $orig );
	$url        = leadwerk_theme_upgrade_acm_news_listing_image_src( $url, $orig );
	$descriptor = trim( (string) ( $segments[1] ?? '' ) );
	return '' !== $descriptor ? $url . ' ' . $descriptor : $url;
}

/**
 * Teaser ohne Fotos/news/{slug}-Bild (z. B. Fotos/Neu/...) — Beitragsbild ueber ersten Artikel-Link (/news/{slug}/) setzen.
 *
 * @param DOMXPath    $xpath         XPath.
 * @param DOMElement  $section_node  Sektions-Root.
 * @return void
 */
function leadwerk_theme_hydrate_acm_news_card_thumbnails_from_links( $xpath, $section_node ) {
	if ( ! post_type_exists( 'acm_news' ) || ! $xpath instanceof DOMXPath || ! $section_node instanceof DOMElement ) {
		return;
	}
	$articles = leadwerk_theme_dom_query(
		$xpath,
		'.//*[@id="news-articles"]//article | .//*[@id="news-archive-articles"]//article',
		$section_node
	);
	foreach ( $articles as $article ) {
		if ( ! $article instanceof DOMElement ) {
			continue;
		}
		$link = leadwerk_theme_dom_first( $xpath, './/a[@href][1]', $article );
		if ( ! $link instanceof DOMElement ) {
			continue;
		}
		$href = (string) $link->getAttribute( 'href' );
		$path = wp_parse_url( $href, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! preg_match( '#/news/([^/]+)/?$#', $path, $pm ) ) {
			continue;
		}
		$slug = sanitize_title( rawurldecode( (string) $pm[1] ) );
		if ( '' === $slug ) {
			continue;
		}
		$ids = get_posts(
			array(
				'post_type'           => 'acm_news',
				'name'                => $slug,
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
		if ( empty( $ids ) ) {
			continue;
		}
		$tid = (int) get_post_thumbnail_id( (int) $ids[0] );
		if ( ! $tid ) {
			continue;
		}
		$url = wp_get_attachment_image_url( $tid, 'large' );
		if ( ! $url ) {
			continue;
		}
		$img = leadwerk_theme_dom_first( $xpath, './/img[1]', $article );
		if ( $img instanceof DOMElement ) {
			leadwerk_theme_dom_set_attr( $img, 'src', $url );
		}
	}
}

function leadwerk_theme_resolve_footer_template_href( $href ) {
	$href   = ltrim( (string) $href, '/' );
	$mapped = leadwerk_theme_map_template_href_to_url( $href );
	if ( $mapped === $href ) {
		return home_url( '/' . $href );
	}
	return $mapped;
}

function leadwerk_theme_normalize_footer_section_links( $xpath, $section_node ) {
	$link_targets = array(
		'impressum'          => 'impressum.html',
		'datenschutz'        => 'datenschutz.html',
		'owner management'   => 'aircraft-management.html',
		'aircraft management'=> 'aircraft-management.html',
		'maintenance'        => 'maintenance.html',
		'camo'               => 'maintenance.html',
		"that's acm"         => 'thats-acm.html',
		'das ist acm'        => 'thats-acm.html',
		'karriere'           => 'karriere.html',
		'news'               => 'news.html',
		'kontakt'            => 'kontakt.html',
		'contact'            => 'kontakt.html',
	);
	$social_targets = array(
		'linkedin'  => 'https://www.linkedin.com/company/acm-air-charter-luftfahrtgesellschaft-mbh',
		'instagram' => 'https://www.instagram.com/acmaircharter/',
	);

	$query = 'footer' === strtolower( $section_node->nodeName )
		? './/a[@href]'
		: './/footer//a[@href]';

	foreach ( leadwerk_theme_dom_query( $xpath, $query, $section_node ) as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}

		$aria = strtolower( trim( (string) $node->getAttribute( 'aria-label' ) ) );
		if ( isset( $social_targets[ $aria ] ) ) {
			$node->setAttribute( 'href', $social_targets[ $aria ] );
			$node->setAttribute( 'target', '_blank' );
			$node->setAttribute( 'rel', 'noopener noreferrer' );
			continue;
		}

		$label = html_entity_decode( (string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$label = strtolower( trim( preg_replace( '/\s+/u', ' ', $label ) ) );
		if ( isset( $link_targets[ $label ] ) ) {
			$node->setAttribute( 'href', leadwerk_theme_resolve_footer_template_href( $link_targets[ $label ] ) );
		}
	}
}

function leadwerk_theme_normalize_template_urls( $xpath, $section_node ) {
	foreach ( leadwerk_theme_dom_query( $xpath, './/*[@src]', $section_node ) as $node ) {
		if ( $node instanceof DOMElement ) {
			$raw = (string) $node->getAttribute( 'src' );
			$src = leadwerk_theme_map_template_href_to_url( $raw );
			$src = leadwerk_theme_upgrade_acm_news_listing_image_src( $src, $raw );
			leadwerk_theme_dom_set_attr( $node, 'src', $src );
		}
	}
	foreach ( array( 'poster', 'data-img', 'data-src', 'data-bg' ) as $attribute ) {
		foreach ( leadwerk_theme_dom_query( $xpath, './/*[@' . $attribute . ']', $section_node ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$raw   = (string) $node->getAttribute( $attribute );
				$value = leadwerk_theme_map_template_href_to_url( $raw );
				$value = leadwerk_theme_upgrade_acm_news_listing_image_src( $value, $raw );
				leadwerk_theme_dom_set_attr( $node, $attribute, $value );
			}
		}
	}
	foreach ( leadwerk_theme_dom_query( $xpath, './/*[@href]', $section_node ) as $node ) {
		if ( $node instanceof DOMElement ) {
			$href = leadwerk_theme_map_template_href_to_url( (string) $node->getAttribute( 'href' ) );
			leadwerk_theme_dom_set_attr( $node, 'href', $href );
		}
	}
	foreach ( leadwerk_theme_dom_query( $xpath, './/*[@style]', $section_node ) as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$style = (string) $node->getAttribute( 'style' );
		if ( preg_match_all( '/url\((["\']?)([^"\')]+)\1\)/i', $style, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$original = $match[2] ?? '';
				$final    = leadwerk_theme_upgrade_acm_news_listing_image_src( leadwerk_theme_map_template_href_to_url( (string) $original ), (string) $original );
				if ( $final !== $original ) {
					$style = str_replace( $original, $final, $style );
				}
			}
			$node->setAttribute( 'style', $style );
		}
	}
	foreach ( leadwerk_theme_dom_query( $xpath, './/*[@srcset]', $section_node ) as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$parts = array_map( 'trim', explode( ',', (string) $node->getAttribute( 'srcset' ) ) );
		$parts = array_map( 'leadwerk_theme_normalize_srcset_part_url', $parts );
		$node->setAttribute( 'srcset', implode( ', ', array_filter( $parts ) ) );
	}
	leadwerk_theme_normalize_footer_section_links( $xpath, $section_node );
	leadwerk_theme_hydrate_acm_news_card_thumbnails_from_links( $xpath, $section_node );
}

/**
 * Map href/src/srcset in einem HTML-Fragment wie bei Exact-Shell-Sektionen.
 *
 * @param string $html Raw fragment from Leadwerk options (imported static HTML).
 * @return string
 */
function leadwerk_theme_normalize_html_fragment_urls( $html ) {
	$html = trim( (string) $html );
	if ( '' === $html ) {
		return '';
	}

	$wrapped = '<?xml encoding="utf-8" ?><html><body><div id="leadwerk-append-root">' . $html . '</div></body></html>';
	$dom     = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$dom->loadHTML( $wrapped );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$root  = $xpath->query( '//div[@id="leadwerk-append-root"]' )->item( 0 );
	if ( ! $root instanceof DOMElement ) {
		return $html;
	}

	leadwerk_theme_normalize_template_urls( $xpath, $root );

	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $dom->saveHTML( $child );
	}

	return $out;
}

/**
 * Rohinhalt eines Theme-Partials unter leadwerk_theme/partials/ (nur erlaubte Dateinamen).
 *
 * @param string $basename Dateiname, z. B. acm-modals.html.
 * @return string
 */
function leadwerk_theme_get_acm_partial_raw( $basename ) {
	$basename = (string) $basename;
	if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*\.html$/i', $basename ) ) {
		return '';
	}
	$path = trailingslashit( LEADWERK_THEME_DIR ) . 'partials/' . $basename;
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return '';
	}
	$html = file_get_contents( $path );
	return false === $html ? '' : (string) $html;
}

/**
 * Scroll-to-Top-Button direkt nach dem Footer-Block (footer.php), vor Modals/Lightbox.
 * Quelle: partials/acm-scroll-top.html; Override: Filter leadwerk_theme_acm_scroll_html.
 *
 * @return void
 */
function leadwerk_theme_render_scroll_to_top_button() {
	$html = (string) apply_filters( 'leadwerk_theme_acm_scroll_html', '' );
	if ( '' === trim( $html ) ) {
		$html = leadwerk_theme_get_acm_partial_raw( 'acm-scroll-top.html' );
	}
	if ( '' !== trim( $html ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-Partial, URLs normalisiert.
		echo leadwerk_theme_normalize_html_fragment_urls( $html );
	}
}

/**
 * Site-Modals und optionale Fleet-Lightbox vor wp_footer (ohne Scroll-Button).
 * Quelle: partials/acm-modals.html, acm-fleet-lightbox.html; Filter: leadwerk_theme_acm_modals_html, leadwerk_theme_acm_fleet_lightbox_html.
 *
 * @return void
 */
function leadwerk_theme_render_footer_acm_chrome_markup() {
	$modals = (string) apply_filters( 'leadwerk_theme_acm_modals_html', '' );
	if ( '' === trim( $modals ) ) {
		$modals = leadwerk_theme_get_acm_partial_raw( 'acm-modals.html' );
	}
	if ( '' !== trim( $modals ) && class_exists( 'Leadwerk_Shared_Translation_Packages' ) ) {
		$modals = Leadwerk_Shared_Translation_Packages::localize_modals_html( $modals, leadwerk_theme_get_current_lang() );
	}
	if ( '' !== trim( $modals ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-Partial, URLs normalisiert.
		echo leadwerk_theme_normalize_html_fragment_urls( $modals );
	}

	$fleet_keys = array( 'acm-global-7500-v1', 'acm-global-6000-v1', 'acm-global-xrs-v1' );
	$sk         = function_exists( 'leadwerk_theme_get_current_source_key' ) ? leadwerk_theme_get_current_source_key() : '';
	if ( ! in_array( $sk, $fleet_keys, true ) ) {
		return;
	}

	$lb = (string) apply_filters( 'leadwerk_theme_acm_fleet_lightbox_html', '' );
	if ( '' === trim( $lb ) ) {
		$lb = leadwerk_theme_get_acm_partial_raw( 'acm-fleet-lightbox.html' );
	}
	if ( '' !== trim( $lb ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-Partial, URLs normalisiert.
		echo leadwerk_theme_normalize_html_fragment_urls( $lb );
	}
}

/**
 * @deprecated Nutze leadwerk_theme_render_footer_acm_chrome_markup().
 * @return void
 */
function leadwerk_theme_render_body_append_markup() {
	leadwerk_theme_render_footer_acm_chrome_markup();
}

/**
 * Apply Leadwerk options and theme strings to exact-match header markup.
 *
 * @param DOMXPath   $xpath  XPath.
 * @param DOMElement $header Header element.
 * @param string     $lang   Language code.
 * @return void
 */
function leadwerk_theme_hydrate_exact_acm_header_from_options( $xpath, $header, $lang ) {
	$logo_id = leadwerk_theme_get_option_image_id( 'header_logo' );
	if ( $logo_id > 0 ) {
		$alt_str = leadwerk_theme_get_string( 'header_logo_alt', '', $lang );
		foreach ( leadwerk_theme_dom_query( $xpath, './/img[contains(@class,"acm-logo")]', $header ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			$fallback = (string) $img->getAttribute( 'src' );
			leadwerk_theme_dom_set_attr( $img, 'src', leadwerk_theme_get_exact_image_url( $logo_id, $fallback ) );
			if ( '' !== trim( $alt_str ) ) {
				leadwerk_theme_dom_set_attr( $img, 'alt', $alt_str );
			}
		}
		$dm_img = leadwerk_theme_dom_first( $xpath, './/div[@id="desktop-menu"]//img[1]', $header );
		if ( $dm_img instanceof DOMElement ) {
			$fallback = (string) $dm_img->getAttribute( 'src' );
			leadwerk_theme_dom_set_attr( $dm_img, 'src', leadwerk_theme_get_exact_image_url( $logo_id, $fallback ) );
			if ( '' !== trim( $alt_str ) ) {
				leadwerk_theme_dom_set_attr( $dm_img, 'alt', $alt_str );
			}
		}
	}

	$aria = leadwerk_theme_get_string( 'header_logo_link_aria_label', '', $lang );
	if ( '' !== trim( $aria ) ) {
		foreach ( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"acm-logo-link")]', $header ) as $a_node ) {
			leadwerk_theme_dom_set_attr( $a_node, 'aria-label', $aria );
		}
		foreach ( leadwerk_theme_dom_query( $xpath, './/a[.//img[contains(@class,"acm-logo")]]', $header ) as $a_node ) {
			leadwerk_theme_dom_set_attr( $a_node, 'aria-label', $aria );
		}
	}

	$cta_lbl = leadwerk_theme_get_string( 'header_contact_cta_label', '', $lang );
	if ( '' !== trim( $cta_lbl ) ) {
		$cta_btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"header-link-button")]', $header );
		if ( $cta_btn instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $cta_btn, $cta_lbl );
		}
	}
}

/**
 * Apply Leadwerk options and theme strings to exact-match footer (brand, contact, columns meta).
 *
 * @param DOMXPath   $xpath  XPath.
 * @param DOMElement $footer Footer element.
 * @param string     $lang   Language code.
 * @return void
 */
function leadwerk_theme_hydrate_exact_acm_footer_from_options( $xpath, $footer, $lang ) {
	$footer_logo_id = leadwerk_theme_get_option_image_id( 'footer_logo' );
	if ( $footer_logo_id > 0 ) {
		$brand_img = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"lg:max-w-sm")]//img[1]', $footer );
		if ( $brand_img instanceof DOMElement ) {
			$fallback = (string) $brand_img->getAttribute( 'src' );
			leadwerk_theme_dom_set_attr( $brand_img, 'src', leadwerk_theme_get_exact_image_url( $footer_logo_id, $fallback ) );
			$logo_alt = leadwerk_theme_get_string( 'footer_logo_alt', '', $lang );
			if ( '' !== trim( $logo_alt ) ) {
				leadwerk_theme_dom_set_attr( $brand_img, 'alt', $logo_alt );
			}
		}
	}

	$tagline = leadwerk_theme_get_string( 'footer_tagline', '', $lang );
	if ( '' !== trim( $tagline ) ) {
		$tag_el = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"lg:max-w-sm")]//p[contains(@class,"text-stone-500")][1]', $footer );
		if ( ! $tag_el instanceof DOMElement ) {
			$tag_el = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-500")][1]', $footer );
		}
		if ( $tag_el instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $tag_el, $tagline );
		}
	}

	$addr_raw = trim( (string) leadwerk_theme_get_option_value( 'company_address', '' ) );
	if ( '' !== $addr_raw ) {
		$inner = leadwerk_theme_format_address_lines_html( $addr_raw );
		$maps  = trim( (string) leadwerk_theme_get_option_value( 'google_maps_url', '' ) );
		if ( '' !== $inner ) {
			$addr_node = leadwerk_theme_dom_first( $xpath, './/address[1]', $footer );
			if ( $addr_node instanceof DOMElement ) {
				if ( '' !== $maps ) {
					$html = '<a href="' . esc_url( $maps ) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
					leadwerk_theme_dom_set_trusted_inner_html( $addr_node, $html );
				} else {
					leadwerk_theme_dom_set_trusted_inner_html( $addr_node, $inner );
				}
			}
		}
	}

	$phone = trim( (string) leadwerk_theme_get_option_value( 'company_phone', '' ) );
	if ( '' !== $phone ) {
		$tel_a = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")][1]', $footer );
		if ( $tel_a instanceof DOMElement ) {
			$tel_href = 'tel:' . preg_replace( '/\s+/', '', $phone );
			leadwerk_theme_dom_set_attr( $tel_a, 'href', $tel_href );
			leadwerk_theme_dom_set_text( $tel_a, $phone );
		}
	}

	$email = trim( (string) leadwerk_theme_get_option_value( 'company_email', '' ) );
	if ( '' !== $email ) {
		$mail_a = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"mailto:")][1]', $footer );
		if ( $mail_a instanceof DOMElement ) {
			leadwerk_theme_dom_set_attr( $mail_a, 'href', 'mailto:' . $email );
			leadwerk_theme_dom_set_text( $mail_a, $email );
		}
	}

	$heading_map = array(
		'services' => 'footer_services_heading',
		'company'  => 'footer_company_heading',
		'legal'    => 'footer_legal_heading',
		'social'   => 'footer_social_heading',
	);
	foreach ( $heading_map as $col => $str_key ) {
		$col_el = leadwerk_theme_dom_first( $xpath, './/*[@data-leadwerk-footer-col="' . $col . '"][1]', $footer );
		if ( ! $col_el instanceof DOMElement ) {
			continue;
		}
		$h4 = leadwerk_theme_dom_first( $xpath, './/h4[1]', $col_el );
		if ( $h4 instanceof DOMElement ) {
			$t = leadwerk_theme_get_string( $str_key, '', $lang );
			if ( '' !== trim( $t ) ) {
				leadwerk_theme_dom_set_text( $h4, $t );
			}
		}
	}

	$social_col = leadwerk_theme_dom_first( $xpath, './/*[@data-leadwerk-footer-col="social"][1]', $footer );
	if ( $social_col instanceof DOMElement ) {
		$linkedin  = trim( (string) leadwerk_theme_get_option_value( 'footer_social_linkedin_url', '' ) );
		$instagram = trim( (string) leadwerk_theme_get_option_value( 'footer_social_instagram_url', '' ) );
		$social_btns = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"gap-4") and contains(@class,"mb-6")]//a', $social_col );
		if ( isset( $social_btns[0] ) && $social_btns[0] instanceof DOMElement && '' !== $linkedin ) {
			leadwerk_theme_dom_set_attr( $social_btns[0], 'href', esc_url( $linkedin ) );
			leadwerk_theme_dom_set_attr( $social_btns[0], 'aria-label', leadwerk_theme_get_string( 'footer_social_linkedin_aria', 'LinkedIn', $lang ) );
		}
		if ( isset( $social_btns[1] ) && $social_btns[1] instanceof DOMElement && '' !== $instagram ) {
			leadwerk_theme_dom_set_attr( $social_btns[1], 'href', esc_url( $instagram ) );
			leadwerk_theme_dom_set_attr( $social_btns[1], 'aria-label', leadwerk_theme_get_string( 'footer_social_instagram_aria', 'Instagram', $lang ) );
		}

		$badge_id = leadwerk_theme_get_option_image_id( 'footer_is_bao_badge' );
		if ( $badge_id > 0 ) {
			$badge_img = leadwerk_theme_dom_first( $xpath, './/img[contains(@alt,"IS-BAO") or contains(@title,"IS-BAO")][1]', $social_col );
			if ( ! $badge_img instanceof DOMElement ) {
				$badge_img = leadwerk_theme_dom_first( $xpath, './/img[1]', $social_col );
			}
			if ( $badge_img instanceof DOMElement ) {
				$fb = (string) $badge_img->getAttribute( 'src' );
				leadwerk_theme_dom_set_attr( $badge_img, 'src', leadwerk_theme_get_exact_image_url( $badge_id, $fb ) );
			}
		}
	}

	$copyright = leadwerk_theme_get_string( 'footer_copyright', '', $lang );
	if ( '' !== trim( $copyright ) ) {
		$nodes  = leadwerk_theme_dom_query( $xpath, './/p[contains(@class,"text-center")]', $footer );
		$target = null;
		for ( $i = count( $nodes ) - 1; $i >= 0; $i-- ) {
			if ( isset( $nodes[ $i ] ) && $nodes[ $i ] instanceof DOMElement ) {
				$target = $nodes[ $i ];
				break;
			}
		}
		if ( $target instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $target, $copyright );
		}
	}
}

/* ══════════════════════════════════════════════════════════════
 * 7. ACM SITE HEADER RENDERER
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_render_exact_site_header() {
	$source_key = leadwerk_theme_get_exact_shell_source_key( leadwerk_theme_get_current_source_key() );
	$html       = leadwerk_theme_get_source_template_html( $source_key );
	if ( '' === trim( $html ) ) {
		return '';
	}

	list( $dom, $xpath ) = leadwerk_theme_create_document_dom( $html );

	// ACM shells use <header id="header"> not class="site-header".
	$header = leadwerk_theme_dom_first( $xpath, '//body/header[@id="header"][1]' );
	if ( ! $header ) {
		$header = leadwerk_theme_dom_first( $xpath, '//body/header[1]' );
	}
	if ( ! $header instanceof DOMElement ) {
		return '';
	}

	leadwerk_theme_normalize_template_urls( $xpath, $header );

	$lang         = leadwerk_theme_get_current_lang();
	$current_key  = leadwerk_theme_get_current_source_key();
	$home_url     = leadwerk_theme_get_page_url( 'acm-index-v1', $lang, home_url( '/' ) );
	$language_url = leadwerk_theme_get_alternate_language_url();
	$lang_pair    = leadwerk_theme_get_header_footer_lang_pair_urls();
	$de_url       = $lang_pair['de'];
	$en_url       = $lang_pair['en'];

	// ── Fix all logo links ──
	foreach ( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"acm-logo-link")]', $header ) as $logo_link ) {
		leadwerk_theme_dom_set_attr( $logo_link, 'href', $home_url );
	}

	// ── Desktop nav links ──
	$acm_nav_pages = array(
		array( 'key' => 'acm-thats-acm-v1',   'label' => "That's ACM" ),
		array( 'key' => 'acm-charter-v1',      'label' => 'Charter' ),
		array( 'key' => 'acm-aircraft-v1',     'label' => 'Aircraft Management' ),
		array( 'key' => 'acm-maintenance-v1',  'label' => 'Maintenance' ),
		array( 'key' => 'acm-careers-v1',      'label' => 'Karriere' ),
		array( 'key' => 'acm-contact-v1',      'label' => 'Kontakt' ),
	);

	// Desktop top nav: a.header-nav-link
	$nav_links = leadwerk_theme_dom_query( $xpath, './/nav[@id="desktop-nav"]//a[contains(@class,"header-nav-link")]', $header );
	foreach ( $nav_links as $idx => $link ) {
		if ( ! isset( $acm_nav_pages[ $idx ] ) ) break;
		$pg = $acm_nav_pages[ $idx ];
		leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
		leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
	}

	// Charter dropdown links
	$charter_sub = array(
		array( 'key' => 'acm-charter-v1',      'label' => 'Charter' ),
		array( 'key' => 'acm-global-7500-v1',  'label' => 'Global 7500' ),
		array( 'key' => 'acm-global-6000-v1',  'label' => 'Global 6000' ),
		array( 'key' => 'acm-global-xrs-v1',   'label' => 'Global XRS' ),
	);
	$dropdown_items = leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"header-nav-dropdown")]//a', $header );
	foreach ( $dropdown_items as $idx => $link ) {
		if ( ! isset( $charter_sub[ $idx ] ) ) break;
		$pg = $charter_sub[ $idx ];
		leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
		leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
	}

	// ── Desktop burger menu links ──
	$burger_links = leadwerk_theme_dom_query( $xpath, './/nav[contains(@class,"desktop-menu-nav")]//a', $header );
	$burger_pages = array(
		array( 'key' => 'acm-thats-acm-v1',   'label' => "That's ACM" ),
		array( 'key' => 'acm-charter-v1',      'label' => 'Charter' ),
		array( 'key' => 'acm-global-7500-v1',  'label' => 'Bombardier Global 7500' ),
		array( 'key' => 'acm-global-6000-v1',  'label' => 'Bombardier Global 6000' ),
		array( 'key' => 'acm-global-xrs-v1',   'label' => 'Bombardier Global XRS' ),
		array( 'key' => 'acm-aircraft-v1',     'label' => 'Aircraft Management' ),
		array( 'key' => 'acm-maintenance-v1',  'label' => 'Maintenance' ),
		array( 'key' => 'acm-careers-v1',      'label' => 'Karriere' ),
		array( 'key' => 'acm-news-v1',         'label' => 'News' ),
		array( 'key' => 'acm-contact-v1',      'label' => 'Kontakt' ),
	);
	foreach ( $burger_links as $idx => $link ) {
		if ( ! isset( $burger_pages[ $idx ] ) ) break;
		$pg = $burger_pages[ $idx ];
		leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
		leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
	}

	// ── Mobile menu links ──
	$mobile_links = leadwerk_theme_dom_query( $xpath, './/*[@id="mobile-menu"]//a[contains(@class,"mobile-menu-link")]', $header );
	$mobile_pages = array(
		array( 'key' => 'acm-thats-acm-v1',   'label' => "That's ACM" ),
		array( 'key' => 'acm-charter-v1',      'label' => 'Charter' ),
		array( 'key' => 'acm-global-7500-v1',  'label' => 'Global 7500' ),
		array( 'key' => 'acm-global-6000-v1',  'label' => 'Global 6000' ),
		array( 'key' => 'acm-global-xrs-v1',   'label' => 'Global XRS' ),
		array( 'key' => 'acm-aircraft-v1',     'label' => 'Aircraft Management' ),
		array( 'key' => 'acm-maintenance-v1',  'label' => 'Maintenance' ),
		array( 'key' => 'acm-careers-v1',      'label' => 'Karriere' ),
		array( 'key' => 'acm-news-v1',         'label' => 'News' ),
		array( 'key' => 'acm-contact-v1',      'label' => 'Kontakt' ),
	);
	foreach ( $mobile_links as $idx => $link ) {
		if ( ! isset( $mobile_pages[ $idx ] ) ) break;
		$pg = $mobile_pages[ $idx ];
		leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
		leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
	}

	// ── Language switcher (header top-right) ──
	$lang_links = leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"header-lang-switcher")]//a', $header );
	if ( isset( $lang_links[0] ) && $lang_links[0] instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $lang_links[0], 'href', $de_url );
		leadwerk_theme_dom_toggle_class( $lang_links[0], 'header-lang-active', 'de' === $lang );
	}
	if ( isset( $lang_links[1] ) && $lang_links[1] instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $lang_links[1], 'href', $en_url );
		leadwerk_theme_dom_toggle_class( $lang_links[1], 'header-lang-active', 'en' === $lang );
	}

	// ── Contact CTA button ──
	$cta_btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"header-link-button")]', $header );
	if ( $cta_btn instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $cta_btn, 'href', leadwerk_theme_get_page_url( 'acm-contact-v1', $lang ) . '#zentrale' );
	}

	// ── Mobile language link ──
	$mobile_lang = leadwerk_theme_dom_first( $xpath, './/*[@id="mobile-menu"]//a[not(contains(@class,"mobile-menu-link"))]', $header );
	if ( $mobile_lang instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $mobile_lang, 'href', $language_url );
		leadwerk_theme_dom_set_text( $mobile_lang, 'en' === $lang ? 'DE' : 'EN' );
	}

	leadwerk_theme_hydrate_exact_acm_header_from_options( $xpath, $header, $lang );

	return leadwerk_theme_dom_outer_html( $header );
}

/* ══════════════════════════════════════════════════════════════
 * 8. ACM SITE FOOTER RENDERER
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_render_exact_site_footer() {
	$source_key = leadwerk_theme_get_exact_shell_source_key( leadwerk_theme_get_current_source_key() );
	$html       = leadwerk_theme_get_source_template_html( $source_key );
	if ( '' === trim( $html ) ) {
		return '';
	}

	list( $dom, $xpath ) = leadwerk_theme_create_document_dom( $html );
	// ACM shells use <footer class="bg-white ..."> not class="site-footer".
	$footer = leadwerk_theme_dom_first( $xpath, '//body/footer[1]' );
	if ( ! $footer instanceof DOMElement ) {
		return '';
	}

	leadwerk_theme_normalize_template_urls( $xpath, $footer );

	$lang      = leadwerk_theme_get_current_lang();
	$lang_pair = leadwerk_theme_get_header_footer_lang_pair_urls();
	$de_url    = $lang_pair['de'];
	$en_url    = $lang_pair['en'];

	// ── Footer nav columns: mark grid children for stable binding (Leistungen | Unternehmen | Rechtliches | Social).
	$footer_grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid-cols-2") and contains(@class,"border-t")][1]', $footer );
	if ( $footer_grid instanceof DOMElement ) {
		$col_roles = array( 'services', 'company', 'legal', 'social' );
		$col_idx   = 0;
		foreach ( leadwerk_theme_dom_query( $xpath, './*', $footer_grid ) as $col_node ) {
			if ( $col_node instanceof DOMElement && isset( $col_roles[ $col_idx ] ) ) {
				$col_node->setAttribute( 'data-leadwerk-footer-col', $col_roles[ $col_idx ] );
			}
			++$col_idx;
		}
	}

	leadwerk_theme_hydrate_exact_acm_footer_from_options( $xpath, $footer, $lang );

	// Services column links (Charter, Aircraft Mgmt, Maintenance, optional CAMO).
	$services_map = array(
		array( 'key' => 'acm-charter-v1',     'label' => 'Charter' ),
		array( 'key' => 'acm-aircraft-v1',    'label' => 'Aircraft Management' ),
		array( 'key' => 'acm-maintenance-v1', 'label' => 'Maintenance' ),
	);
	$camo_url       = trim( (string) leadwerk_theme_get_option_value( 'footer_camo_url', '' ) );
	$services_col = leadwerk_theme_dom_first( $xpath, './/*[@data-leadwerk-footer-col="services"][1]', $footer );
	if ( $services_col instanceof DOMElement ) {
		$services_links = leadwerk_theme_dom_query( $xpath, './/ul//a', $services_col );
		foreach ( $services_links as $idx => $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			if ( 3 === (int) $idx ) {
				if ( '' === $camo_url ) {
					$li = $link->parentNode;
					if ( $li instanceof DOMElement && 'li' === strtolower( $li->tagName ) && $li->parentNode ) {
						$li->parentNode->removeChild( $li );
					}
				} else {
					leadwerk_theme_dom_set_attr( $link, 'href', esc_url( $camo_url ) );
					leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_string( 'footer_camo_link_label', 'CAMO', $lang ) );
				}
				continue;
			}
			if ( ! isset( $services_map[ $idx ] ) ) {
				break;
			}
			$pg = $services_map[ $idx ];
			leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
			leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
		}
	}

	// Unternehmen column (That's ACM, Karriere, News, Kontakt).
	$company_map = array(
		array( 'key' => 'acm-thats-acm-v1', 'label' => "That's ACM" ),
		array( 'key' => 'acm-careers-v1',    'label' => 'Karriere' ),
		array( 'key' => 'acm-news-v1',       'label' => 'News' ),
		array( 'key' => 'acm-contact-v1',    'label' => 'Kontakt' ),
	);
	$company_col = leadwerk_theme_dom_first( $xpath, './/*[@data-leadwerk-footer-col="company"][1]', $footer );
	if ( $company_col instanceof DOMElement ) {
		$company_links = leadwerk_theme_dom_query( $xpath, './/ul//a', $company_col );
		foreach ( $company_links as $idx => $link ) {
			if ( ! isset( $company_map[ $idx ] ) ) {
				break;
			}
			$pg = $company_map[ $idx ];
			leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
			leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
		}
	}

	// Rechtliches: Impressum, Datenschutz; optional AGB via theme option URL.
	$legal_map = array(
		array( 'key' => 'acm-impressum-v1',   'label' => 'Impressum' ),
		array( 'key' => 'acm-datenschutz-v1', 'label' => 'Datenschutz' ),
	);
	$legal_col = leadwerk_theme_dom_first( $xpath, './/*[@data-leadwerk-footer-col="legal"][1]', $footer );
	if ( $legal_col instanceof DOMElement ) {
		$legal_links = leadwerk_theme_dom_query( $xpath, './/ul//a', $legal_col );
		foreach ( $legal_links as $idx => $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			if ( isset( $legal_map[ $idx ] ) ) {
				$pg = $legal_map[ $idx ];
				leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_get_page_url( $pg['key'], $lang ) );
				leadwerk_theme_dom_set_text( $link, leadwerk_theme_get_page_title( $pg['key'], $lang, $pg['label'] ) );
				continue;
			}
			$agb = function_exists( 'leadwerk_theme_get_footer_agb_link' )
				? leadwerk_theme_get_footer_agb_link( $lang )
				: array( 'href' => trim( (string) leadwerk_theme_get_option_value( 'footer_agb_url', '' ) ), 'label' => leadwerk_theme_get_string( 'footer_agb_link_label', 'AGB', $lang ) );
			if ( ! empty( $agb['href'] ) ) {
				leadwerk_theme_dom_set_attr( $link, 'href', (string) $agb['href'] );
				leadwerk_theme_dom_set_text( $link, (string) ( $agb['label'] ?? '' ) );
			} else {
				$li = $link->parentNode;
				if ( $li instanceof DOMElement && 'li' === strtolower( $li->tagName ) && $li->parentNode ) {
					$li->parentNode->removeChild( $li );
				}
			}
		}
	}

	// ── Language links in footer ──
	$footer_lang_links = leadwerk_theme_dom_query( $xpath, './/a[contains(@class,"language-btn")]', $footer );
	if ( isset( $footer_lang_links[0] ) && $footer_lang_links[0] instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $footer_lang_links[0], 'href', $de_url );
		leadwerk_theme_dom_toggle_class( $footer_lang_links[0], 'active', 'de' === $lang );
	}
	if ( isset( $footer_lang_links[1] ) && $footer_lang_links[1] instanceof DOMElement ) {
		leadwerk_theme_dom_set_attr( $footer_lang_links[1], 'href', $en_url );
		leadwerk_theme_dom_toggle_class( $footer_lang_links[1], 'active', 'en' === $lang );
	}

	return leadwerk_theme_dom_outer_html( $footer );
}

/* ══════════════════════════════════════════════════════════════
 * 9. ACM LAYOUT BINDERS (Option B — Full Data Binding)
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_resolve_acm_hero_video_src( $video_value ) {
	$raw = $video_value;
	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
	}
	if ( '' === $raw || null === $raw ) {
		return '';
	}
	if ( is_numeric( $raw ) && (int) $raw > 0 ) {
		$url = wp_get_attachment_url( (int) $raw );
		return $url ? $url : '';
	}
	if ( is_string( $raw ) ) {
		return leadwerk_theme_map_template_href_to_url( $raw );
	}
	return '';
}

function leadwerk_theme_bind_exact_acm_hero_video( $xpath, $section, $value ) {
	$title = trim( (string) ( $value['title'] ?? '' ) );
	if ( '' !== $title ) {
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h1[1]', $section ), $title, 'heading' );
	}
	$subtitle = trim( (string) ( $value['subtitle'] ?? '' ) );
	if ( '' !== $subtitle ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"subtitle")][1] | .//p[1]', $section ), $subtitle );
	}
	$video = leadwerk_theme_dom_first( $xpath, './/video[1]', $section );
	$src   = leadwerk_theme_resolve_acm_hero_video_src( $value['video'] ?? '' );
	if ( $video instanceof DOMElement && '' !== $src ) {
		$source = leadwerk_theme_dom_first( $xpath, './/source[1]', $video );
		if ( $source instanceof DOMElement ) {
			leadwerk_theme_dom_set_attr( $source, 'src', $src );
		} else {
			leadwerk_theme_dom_set_attr( $video, 'src', $src );
		}
	}
	leadwerk_theme_bind_exact_image( $xpath, $section, './/video/@poster | .//img[1]', (int) ( $value['poster'] ?? 0 ), (string) ( $value['poster_alt'] ?? '' ) );
	if ( $video instanceof DOMElement && ! empty( $value['poster'] ) ) {
		$poster_url = leadwerk_theme_get_exact_image_url( (int) $value['poster'], '' );
		if ( '' !== $poster_url ) {
			$video->setAttribute( 'poster', $poster_url );
		}
	}
}

function leadwerk_theme_bind_exact_acm_hero_statement( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $section ), (string) ( $value['quote'] ?? '' ) );
}

function leadwerk_theme_bind_exact_acm_fullwidth_promo( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	$cta_label = trim( (string) ( $value['cta_label'] ?? '' ) );
	if ( '' === $cta_label ) {
		return;
	}
	$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"inline-flex")][1]', $section );
	if ( ! $btn instanceof DOMElement ) {
		$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-overlay-white")][1]', $section );
	}
	if ( $btn instanceof DOMElement ) {
		leadwerk_theme_bind_exact_button( $xpath, $btn, '.', $cta_label, (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_intro_centered( $xpath, $section, $value ) {
	$wrap = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center") and contains(@class,"scroll-reveal")]', $section );
	if ( ! $wrap instanceof DOMElement ) {
		return;
	}
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $wrap ), (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $wrap ), (string) ( $value['title'] ?? '' ), 'heading' );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $wrap );
	if ( ! $h2 instanceof DOMElement ) {
		return;
	}
	$n = $h2->nextSibling;
	while ( $n ) {
		$nx = $n->nextSibling;
		if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
			leadwerk_theme_dom_remove( $n );
		}
		$n = $nx;
	}
	$body = (string) ( $value['body'] ?? '' );
	if ( '' === trim( $body ) ) {
		return;
	}
	$doc    = $wrap->ownerDocument;
	$holder = $doc->createElement( 'div' );
	$wrap->insertBefore( $holder, $h2->nextSibling );
	leadwerk_theme_dom_set_trusted_inner_html( $holder, $body );
	while ( $holder->firstChild ) {
		$wrap->insertBefore( $holder->firstChild, $holder );
	}
	leadwerk_theme_dom_remove( $holder );
}

function leadwerk_theme_bind_exact_acm_certifications_grid( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
	$h2_header = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//h2[1]', $section );
	leadwerk_theme_set_placeholder_markup( $h2_header, (string) ( $value['title'] ?? '' ), 'heading' );
	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( $h2_header instanceof DOMElement && '' !== $intro_html ) {
		$wrap = $h2_header->parentNode;
		if ( $wrap instanceof DOMElement ) {
			$n = $h2_header->nextSibling;
			while ( $n ) {
				$nx = $n->nextSibling;
				if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
					leadwerk_theme_dom_remove( $n );
				}
				$n = $nx;
			}
			$doc    = $wrap->ownerDocument;
			$holder = $doc->createElement( 'div' );
			$wrap->insertBefore( $holder, $h2_header->nextSibling );
			leadwerk_theme_dom_set_trusted_inner_html( $holder, $intro_html );
			while ( $holder->firstChild ) {
				$wrap->insertBefore( $holder->firstChild, $holder );
			}
			leadwerk_theme_dom_remove( $holder );
		}
	}
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$card_xpath = './/div[contains(@class,"grid") and (contains(@class,"lg:grid-cols-3") or contains(@class,"lg:grid-cols-4") or (contains(@class,"md:grid-cols-2") and (contains(@class,"gap-10") or contains(@class,"gap-12") or contains(@class,"gap-8"))))]//div[contains(@class,"scroll-reveal") and not(contains(@class,"fleet-card"))]';
	$cards    = leadwerk_theme_dom_query( $xpath, $card_xpath, $section );
	$nodes    = leadwerk_theme_dom_ensure_count( $cards, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) {
			continue;
		}
		$title_el = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"cert-title")][1] | .//h3[1] | .//h4[1]', $node );
		leadwerk_theme_dom_set_text( $title_el, (string) ( $item['title'] ?? '' ) );
		$content_el = null;
		if ( $title_el instanceof DOMElement ) {
			$content_el = leadwerk_theme_dom_first( $xpath, './following-sibling::p[1]', $title_el );
		}
		if ( ! $content_el instanceof DOMElement ) {
			$content_el = leadwerk_theme_dom_first( $xpath, './/h3[1]/following-sibling::p[1] | .//h4[1]/following-sibling::p[1]', $node );
		}
		if ( $content_el instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $content_el, (string) ( $item['content'] ?? '' ) );
		}
		$icon_slot = leadwerk_theme_dom_first( $xpath, './/div[(contains(@class,"w-12") and contains(@class,"h-12")) or (contains(@class,"w-16") and contains(@class,"h-16"))][1]', $node );
		if ( $icon_slot instanceof DOMElement ) {
			leadwerk_theme_dom_set_trusted_inner_html( $icon_slot, (string) ( $item['icon'] ?? '' ) );
		}
	}
}

function leadwerk_theme_bind_exact_acm_split_icon_features( $xpath, $section, $value ) {
	$grid     = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $section );
	$img_wrap = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"image-zoom-bleed")][1]', $section );
	$text_col = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"content-box-outer")]//div[contains(@class,"max-w-xl")][1]', $section );
	$img_col  = null;

	if ( ! $text_col instanceof DOMElement ) {
		$wrap = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"max-w-6xl")][1]', $section );
		$g2   = $wrap instanceof DOMElement
			? leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $wrap )
			: null;
		if ( $g2 instanceof DOMElement ) {
			foreach ( leadwerk_theme_dom_query( $xpath, './div', $g2 ) as $col ) {
				if ( ! $col instanceof DOMElement ) {
					continue;
				}
				if ( leadwerk_theme_dom_first( $xpath, './/h2[1]', $col ) && leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $col ) ) {
					$text_col = $col;
				}
				if ( leadwerk_theme_dom_first( $xpath, './/img[not(contains(@class,"section-emblem"))][1]', $col ) ) {
					$img_col = $col;
				}
			}
		}
	}

	$image_right_cms = ! empty( $value['image_right'] );
	$image_left      = ! empty( $value['image_left'] ) && '0' !== (string) ( $value['image_left'] ?? '' );
	if ( $image_right_cms ) {
		$image_left = false;
	}

	if ( ! $image_left && $grid instanceof DOMElement && $img_wrap instanceof DOMElement && $img_wrap->parentNode instanceof DOMElement ) {
		$img_parent = $img_wrap->parentNode;
		$outer      = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"content-box-outer")][1]', $section );
		if ( $outer instanceof DOMElement && $img_parent !== $outer ) {
			$img_cls = trim( (string) $img_parent->getAttribute( 'class' ) . ' lg:order-2' );
			$img_parent->setAttribute( 'class', $img_cls );
			$txt_cls = trim( (string) $outer->getAttribute( 'class' ) . ' lg:order-1' );
			$outer->setAttribute( 'class', $txt_cls );
		}
	}

	if ( $image_right_cms && $img_col instanceof DOMElement && $text_col instanceof DOMElement ) {
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-1', false );
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-2', true );
		leadwerk_theme_dom_toggle_class( $text_col, 'lg:order-2', false );
		leadwerk_theme_dom_toggle_class( $text_col, 'lg:order-1', true );
	}

	if ( $img_wrap instanceof DOMElement ) {
		leadwerk_theme_bind_exact_image( $xpath, $img_wrap, './/img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	} elseif ( $img_col instanceof DOMElement ) {
		leadwerk_theme_bind_exact_image( $xpath, $img_col, './/img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	}

	if ( ! $text_col instanceof DOMElement ) {
		return;
	}

	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $text_col ), (string) ( $value['label'] ?? '' ) );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $text_col );
	leadwerk_theme_set_placeholder_markup( $h2, (string) ( $value['title'] ?? '' ), 'heading' );
	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( $h2 instanceof DOMElement && '' !== $intro_html ) {
		$space_y = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-6")][1]', $text_col );
		$space_8 = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-8")][1]', $text_col );
		$inner_g = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"sm:grid-cols-2")][1]', $text_col );
		$n       = $h2->nextSibling;
		while ( $n ) {
			if ( $n === $space_y || $n === $space_8 || $n === $inner_g ) {
				break;
			}
			$nx = $n->nextSibling;
			if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
				leadwerk_theme_dom_remove( $n );
			}
			$n = $nx;
		}
		$doc    = $text_col->ownerDocument;
		$holder = $doc->createElement( 'div' );
		$text_col->insertBefore( $holder, $h2->nextSibling );
		leadwerk_theme_dom_set_trusted_inner_html( $holder, $intro_html );
		while ( $holder->firstChild ) {
			$text_col->insertBefore( $holder->firstChild, $holder );
		}
		leadwerk_theme_dom_remove( $holder );
	}

	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$rows6 = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"space-y-6")]//div[contains(@class,"flex") and contains(@class,"items-start")]', $text_col );
	$rows8 = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"space-y-8")]//div[./h4[1]]', $text_col );
	$rowsi = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"sm:grid-cols-2")]//div[contains(@class,"flex") and contains(@class,"items-start")]', $text_col );
	$rows  = ! empty( $rows6 ) ? $rows6 : ( ! empty( $rows8 ) ? $rows8 : $rowsi );
	$nodes = leadwerk_theme_dom_ensure_count( $rows, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) {
			continue;
		}
		$icon_slot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"w-10") and contains(@class,"h-10")][1]', $node );
		if ( ! $icon_slot instanceof DOMElement ) {
			$icon_slot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"w-12") and contains(@class,"h-12")][1]', $node );
		}
		if ( $icon_slot instanceof DOMElement ) {
			leadwerk_theme_dom_set_trusted_inner_html( $icon_slot, (string) ( $item['icon'] ?? '' ) );
		}
		$tnode = leadwerk_theme_dom_first( $xpath, './/h3[contains(@class,"cert-title")][1]', $node );
		if ( ! $tnode instanceof DOMElement ) {
			$tnode = leadwerk_theme_dom_first( $xpath, './/h4[1]', $node );
		}
		if ( $tnode instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $tnode, (string) ( $item['title'] ?? '' ) );
			$xp = './following-sibling::p[1]';
			leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, $xp, $tnode ), (string) ( $item['content'] ?? '' ) );
		}
	}
}

function leadwerk_theme_bind_exact_acm_split_rows( $xpath, $section, $value ) {
	$intro = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"max-w-6xl")]//div[contains(@class,"text-center")][1]', $section );
	if ( $intro instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $intro ), (string) ( $value['intro_label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $intro ), (string) ( $value['intro_title'] ?? '' ), 'heading' );
	}
	$rows  = isset( $value['rows'] ) && is_array( $value['rows'] ) ? array_values( $value['rows'] ) : array();
	$grids = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"max-w-6xl")]//div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2") and contains(@class,"gap-12")]', $section );
	$nodes = leadwerk_theme_dom_ensure_count( $grids, count( $rows ) );
	foreach ( $nodes as $idx => $grid ) {
		$row = $rows[ $idx ] ?? array();
		if ( ! is_array( $row ) || ! $grid instanceof DOMElement ) {
			continue;
		}
		$img_wrap = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"image-zoom-wrap")][1]', $grid );
		$text_col = leadwerk_theme_dom_first( $xpath, './/div[.//*[contains(@class,"section-label")][1] and not(contains(@class,"image-zoom-wrap"))]', $grid );
		if ( ! $text_col instanceof DOMElement ) {
			$text_col = leadwerk_theme_dom_first( $xpath, './div[not(contains(@class,"image-zoom-wrap"))][1]', $grid );
		}
		if ( $img_wrap instanceof DOMElement ) {
			leadwerk_theme_bind_exact_image( $xpath, $img_wrap, './/img[1]', (int) ( $row['image'] ?? 0 ), (string) ( $row['image_alt'] ?? '' ) );
		}
		if ( $text_col instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $text_col ), (string) ( $row['label'] ?? '' ) );
			$h3        = leadwerk_theme_dom_first( $xpath, './/h3[1]', $text_col );
			$body_html = (string) ( $row['body'] ?? '' );
			if ( $h3 instanceof DOMElement ) {
				leadwerk_theme_set_placeholder_markup( $h3, (string) ( $row['title'] ?? '' ), 'heading' );
			}
			if ( $h3 instanceof DOMElement && '' !== trim( $body_html ) ) {
				$n = $h3->nextSibling;
				while ( $n ) {
					$nx = $n->nextSibling;
					if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
						leadwerk_theme_dom_remove( $n );
					}
					$n = $nx;
				}
				$doc    = $text_col->ownerDocument;
				$holder = $doc->createElement( 'div' );
				$text_col->insertBefore( $holder, $h3->nextSibling );
				leadwerk_theme_dom_set_trusted_inner_html( $holder, $body_html );
				while ( $holder->firstChild ) {
					$text_col->insertBefore( $holder->firstChild, $holder );
				}
				leadwerk_theme_dom_remove( $holder );
			}
		}
	}
}

function leadwerk_theme_bind_exact_acm_kpi_strip( $xpath, $section, $value ) {
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$cols  = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"scroll-reveal") and contains(@class,"flex-1")]', $section );
	$nodes = leadwerk_theme_dom_ensure_count( $cols, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/span[contains(@class,"text-4xl")][1] | .//span[1]', $node ), (string) ( $item['figure'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/span[contains(@class,"text-base")][1] | .//span[2]', $node ), (string) ( $item['caption'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_home_final_cta( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$body_node = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-600")][1] | .//div[contains(@class,"max-w-2xl")]//p[1]', $section );
	if ( $body_node ) {
		leadwerk_theme_dom_set_inner_html( $body_node, (string) ( $value['body'] ?? '' ) );
	}
	$actions = isset( $value['actions'] ) && is_array( $value['actions'] ) ? array_values( $value['actions'] ) : array();
	$links   = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"homepage-cta-grid")]//a', $section );
	$nodes   = leadwerk_theme_dom_ensure_count( $links, count( $actions ) );
	foreach ( $nodes as $idx => $node ) {
		$act = $actions[ $idx ] ?? array();
		if ( ! is_array( $act ) ) {
			continue;
		}
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		leadwerk_theme_bind_exact_button( $xpath, $node, '.', (string) ( $act['label'] ?? '' ), (string) ( $act['page_key'] ?? '' ), (string) ( $act['url'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_hero( $xpath, $section, $value ) {
	$h1 = leadwerk_theme_dom_first( $xpath, './/h1[1]', $section );
	leadwerk_theme_set_placeholder_markup( $h1 instanceof DOMElement ? $h1 : leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );

	$eyebrow = trim( (string) ( $value['eyebrow'] ?? '' ) );
	if ( $h1 instanceof DOMElement ) {
		$eyebrow_node = leadwerk_theme_dom_first( $xpath, './preceding-sibling::p[1]', $h1 );
		if ( $eyebrow_node instanceof DOMElement && '' !== $eyebrow ) {
			leadwerk_theme_dom_set_text( $eyebrow_node, $eyebrow );
		}
		$sub_node = leadwerk_theme_dom_first( $xpath, './following-sibling::p[1]', $h1 );
		if ( $sub_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $sub_node, (string) ( $value['subtitle'] ?? '' ) );
		}
	} else {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"subtitle")][1] | .//p[1]', $section ), (string) ( $value['subtitle'] ?? '' ) );
	}

	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	$bg_url = leadwerk_theme_get_exact_image_url( (int) ( $value['image'] ?? 0 ), '' );
	if ( '' !== $bg_url && $section instanceof DOMElement ) {
		$style = (string) $section->getAttribute( 'style' );
		if ( false !== strpos( $style, 'background' ) ) {
			$section->setAttribute( 'style', leadwerk_theme_replace_style_url( $style, $bg_url ) );
		}
	}
	$cta_label = trim( (string) ( $value['cta_label'] ?? '' ) );
	if ( '' !== $cta_label ) {
		$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn")][1]', $section );
		if ( ! $btn instanceof DOMElement ) {
			$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-overlay-white")][1]', $section );
		}
		if ( $btn instanceof DOMElement ) {
			leadwerk_theme_bind_exact_button( $xpath, $btn, '.', $cta_label, (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
		}
	}

	$badges = isset( $value['proof_badges'] ) && is_array( $value['proof_badges'] ) ? array_values( $value['proof_badges'] ) : array();
	if ( ! empty( $badges ) ) {
		$bar = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"hero-proof-bar")][1]', $section );
		if ( $bar instanceof DOMElement ) {
			$row = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"flex")][1]', $bar );
			$parent = $row instanceof DOMElement ? $row : $bar;
			$badge_nodes = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"proof-badge")]', $parent );
			$nodes       = leadwerk_theme_dom_ensure_count( $badge_nodes, count( $badges ) );
			foreach ( $nodes as $idx => $bnode ) {
				$b = $badges[ $idx ] ?? array();
				if ( ! is_array( $b ) || ! $bnode instanceof DOMElement ) {
					continue;
				}
				$icon = trim( (string) ( $b['icon'] ?? '' ) );
				if ( '' !== $icon ) {
					$slot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"proof-badge-icon")][1]', $bnode );
					if ( $slot instanceof DOMElement ) {
						leadwerk_theme_dom_set_trusted_inner_html( $slot, $icon );
					}
				}
				leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"proof-badge-text")][1]', $bnode ), (string) ( $b['text'] ?? '' ) );
			}
		}
	}
}

function leadwerk_theme_bind_exact_acm_services_grid( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$cards = leadwerk_theme_dom_query( $xpath, './/article[.//h3[1] or .//h4[1]]', $section );
	if ( count( $cards ) < 1 ) {
	$cards = leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"service-card") or contains(@class,"card")]', $section );
	}
	$nodes = leadwerk_theme_dom_ensure_count( $cards, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1] | .//h4[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $node ), (string) ( $item['description'] ?? '' ) );
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), (string) ( $item['image_alt'] ?? '' ) );
		if ( $node instanceof DOMElement && $node->tagName === 'a' ) {
			leadwerk_theme_dom_set_attr( $node, 'href', leadwerk_theme_resolve_exact_href( (string) ( $item['page_key'] ?? '' ), (string) ( $item['url'] ?? '' ) ) );
		} else {
			leadwerk_theme_bind_exact_anchor_keep_svg(
				$xpath,
				$node,
				'.//a[contains(@class,"link-arrow")][1] | .//a[1]',
				(string) ( $item['cta_label'] ?? '' ),
				(string) ( $item['page_key'] ?? '' ),
				(string) ( $item['url'] ?? '' )
			);
		}
	}
}

function leadwerk_theme_bind_exact_acm_about_teaser( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $section );
	leadwerk_theme_set_placeholder_markup( $h2, (string) ( $value['title'] ?? '' ), 'heading' );
	$body_html = (string) ( $value['body'] ?? '' );
	if ( $h2 instanceof DOMElement && '' !== trim( $body_html ) ) {
		$n = $h2->nextSibling;
		while ( $n ) {
			$nx = $n->nextSibling;
			if ( $n instanceof DOMElement ) {
				$tag = strtolower( $n->tagName );
				if ( 'p' === $tag ) {
					leadwerk_theme_dom_remove( $n );
				} elseif ( 'div' === $tag && false !== strpos( ' ' . $n->getAttribute( 'class' ) . ' ', ' flex ' ) ) {
					break;
				}
			}
			$n = $nx;
		}
		$doc    = $h2->ownerDocument;
		$holder = $doc->createElement( 'div' );
		$h2->parentNode->insertBefore( $holder, $h2->nextSibling );
		leadwerk_theme_dom_set_trusted_inner_html( $holder, $body_html );
		while ( $holder->firstChild ) {
			$h2->parentNode->insertBefore( $holder->firstChild, $holder );
		}
		leadwerk_theme_dom_remove( $holder );
	} elseif ( '' !== trim( $body_html ) ) {
		$body_node = leadwerk_theme_dom_first_acm_main_paragraph( $xpath, $section );
		if ( $body_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $body_node, $body_html );
		}
	}
	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );

	$grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $section );
	if ( ! $grid instanceof DOMElement ) {
		return;
	}
	$cols = leadwerk_theme_dom_query( $xpath, './div', $grid );
	if ( count( $cols ) < 2 ) {
		return;
	}
	$img_col = null;
	$txt_col = null;
	foreach ( $cols as $col ) {
		if ( ! $col instanceof DOMElement ) {
			continue;
		}
		if ( leadwerk_theme_dom_first( $xpath, './/img[not(contains(@class,"section-emblem"))][1]', $col ) instanceof DOMElement ) {
			$img_col = $col;
		}
		if ( leadwerk_theme_dom_first( $xpath, './/h2[1]', $col ) instanceof DOMElement ) {
			$txt_col = $col;
		}
	}
	if ( ! $img_col instanceof DOMElement || ! $txt_col instanceof DOMElement ) {
		return;
	}
	$image_right = ! empty( $value['image_right'] );
	if ( $image_right ) {
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-1', false );
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-2', true );
		leadwerk_theme_dom_toggle_class( $txt_col, 'lg:order-2', false );
		leadwerk_theme_dom_toggle_class( $txt_col, 'lg:order-1', true );
	} else {
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-2', false );
		leadwerk_theme_dom_toggle_class( $img_col, 'lg:order-1', true );
		leadwerk_theme_dom_toggle_class( $txt_col, 'lg:order-1', false );
		leadwerk_theme_dom_toggle_class( $txt_col, 'lg:order-2', true );
	}
}

function leadwerk_theme_bind_exact_acm_charter_hero( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	$cta_label = trim( (string) ( $value['cta_label'] ?? '' ) );
	if ( '' !== $cta_label ) {
		$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-overlay-white")][1]', $section );
		if ( ! $btn instanceof DOMElement ) {
			$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"inline-flex")][1]', $section );
		}
		if ( $btn instanceof DOMElement ) {
			leadwerk_theme_bind_exact_button( $xpath, $btn, '.', $cta_label, (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
		}
	}
}

function leadwerk_theme_bind_exact_acm_content_block( $xpath, $section, $value ) {
	$label_node = leadwerk_theme_dom_first(
		$xpath,
		'.//*[contains(concat(" ", normalize-space(@class), " "), " section-label ") or contains(@class,"label") or contains(@class,"overline")][1]'
		. ' | .//div[contains(concat(" ", normalize-space(@class), " "), " max-w-xl ")]//span[contains(concat(" ", normalize-space(@class), " "), " uppercase ")][1]'
		. ' | .//div[.//h2[1] and not(.//img[not(contains(@class,"section-emblem"))][1])]//span[contains(@class,"tracking")][1]'
		. ' | .//div[contains(concat(" ", normalize-space(@class), " "), " content-box-outer ")]//span[contains(@class,"tracking")][1]',
		$section
	);
	leadwerk_theme_dom_set_text( $label_node, (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$body_node = leadwerk_theme_dom_first_acm_main_paragraph( $xpath, $section );
	if ( $body_node instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $body_node, (string) ( $value['body'] ?? '' ) );
	}
	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	// Kein contains(@class,"item") — trifft Tailwind "items-center" / "items-start" und leert bei leerem Repeater die ganze Sektion.
	if ( ! empty( $items ) ) {
		$nodes = leadwerk_theme_dom_ensure_count(
			leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"feature-item")] | .//ul/li | .//ol/li', $section ),
			count( $items )
		);
		foreach ( $nodes as $idx => $node ) {
			$item = $items[ $idx ] ?? array();
			if ( ! is_array( $item ) ) {
				continue;
			}
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1] | .//h4[1]', $node ), (string) ( $item['title'] ?? '' ) );
			leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1] | .//*[contains(@class,"content")][1]', $node ), (string) ( $item['content'] ?? '' ) );
		}
	}
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_acm_news_teaser( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );

	$container = leadwerk_theme_dom_first( $xpath, './/article[1]/parent::*[1]', $section );
	if ( ! $container instanceof DOMElement ) {
		return;
	}

	leadwerk_theme_acm_news_clear_article_children( $xpath, $container );
	$doc = $container->ownerDocument;
	if ( ! $doc ) {
		return;
	}

	$max_posts = max( 1, (int) ( $value['max_posts'] ?? 3 ) );
	$posts     = array_slice( leadwerk_theme_get_acm_news_listing_posts(), 0, $max_posts );

	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		$container->appendChild( leadwerk_theme_build_acm_home_news_teaser_article_element( $doc, $post ) );
	}
}

function leadwerk_theme_bind_exact_acm_horizontal_timeline( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( '' !== $intro_html ) {
		$intro_node = $head instanceof DOMElement ? leadwerk_theme_dom_first( $xpath, './/p[1]', $head ) : null;
		if ( ! $intro_node instanceof DOMElement ) {
			$intro_node = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]/following-sibling::p[1]', $section );
		}
		if ( $intro_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $intro_node, $intro_html );
		}
	}
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	// Nur echte Timeline-Karten (article.htl-card). contains(@class,"htl-card") matcht fälschlich z. B. htl-card-title / htl-card-year und zerstört das DOM bei dom_ensure_count().
	$htl_card_xpath = './/article[contains(concat(\' \', normalize-space(@class), \' \'), \' htl-card \')] | .//*[contains(concat(\' \', normalize-space(@class), \' \'), \' timeline-item \')] | .//*[contains(concat(\' \', normalize-space(@class), \' \'), \' milestone \')]';
	$nodes          = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, $htl_card_xpath, $section ), count( $items ) );
	// #region agent log
	$log_file = dirname( LEADWERK_THEME_DIR ) . DIRECTORY_SEPARATOR . 'debug-eeb156.log';
	$log_payload = array(
		'sessionId'    => 'eeb156',
		'hypothesisId' => 'H_htl_xpath',
		'location'     => 'exact-acm-render.php:leadwerk_theme_bind_exact_acm_horizontal_timeline',
		'message'      => 'Timeline card nodes vs items',
		'data'         => array(
			'items_count' => count( $items ),
			'nodes_count' => is_array( $nodes ) ? count( $nodes ) : 0,
		),
		'timestamp'    => (int) round( microtime( true ) * 1000 ),
	);
	@file_put_contents(
		$log_file,
		( function_exists( 'wp_json_encode' ) ? wp_json_encode( $log_payload ) : json_encode( $log_payload ) ) . "\n",
		FILE_APPEND
	);
	// #endregion
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		$year  = (string) ( $item['year'] ?? '' );
		$title = (string) ( $item['title'] ?? '' );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"htl-card-year") or contains(@class,"htl-year") or contains(@class,"year")][1]', $node ), $year );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"htl-card-title")][1] | .//h3[1] | .//h4[1] | .//*[contains(@class,"htl-title")][1]', $node ), $title );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"htl-card-text")][1] | .//p[1] | .//*[contains(@class,"htl-text")][1]', $node ), (string) ( $item['content'] ?? '' ) );
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), '' );
		if ( $node instanceof DOMElement && $node->hasAttribute( 'aria-label' ) ) {
			$aria = trim( $year . ( '' !== $title ? ' - ' . $title : '' ) );
			if ( '' !== $aria ) {
				$node->setAttribute( 'aria-label', $aria );
			}
		}
	}
}

function leadwerk_theme_bind_exact_acm_contact_cta( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$body_node = leadwerk_theme_dom_first(
		$xpath,
		'.//p[contains(@class,"text-stone-600")][1] | .//p[contains(@class,"text-stone-500")][contains(@class,"max-w-2xl")][1] | .//p[1]',
		$section
	);
	if ( $body_node instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $body_node, (string) ( $value['body'] ?? '' ) );
	}
	$cta_label = trim( (string) ( $value['cta_label'] ?? '' ) );
	$primary_button = null;
	if ( '' !== $cta_label ) {
		$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-primary")][1]', $section );
		if ( ! $btn instanceof DOMElement ) {
			$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn")][1]', $section );
		}
		if ( ! $btn instanceof DOMElement ) {
			$btn = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-outline")][1]', $section );
		}
		if ( ! $btn instanceof DOMElement ) {
			$btn = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")][1]', $section );
		}
		if ( $btn instanceof DOMElement ) {
			$primary_button = $btn;
			if ( false !== strpos( ' ' . $btn->getAttribute( 'class' ) . ' ', ' btn-outline ' ) && leadwerk_theme_dom_first( $xpath, './/svg[1]', $btn ) instanceof DOMElement ) {
				leadwerk_theme_bind_exact_anchor_keep_leading_svg( $xpath, $btn, '.', $cta_label, (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
			} else {
				leadwerk_theme_bind_exact_button( $xpath, $btn, '.', $cta_label, (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
			}
		}
	}
	$secondary_label = trim( (string) ( $value['secondary_label'] ?? '' ) );
	if ( '' !== $secondary_label ) {
		$secondary_button = leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-outline")][1]', $section );
		if ( ! $secondary_button instanceof DOMElement ) {
			$secondary_button = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")][1]', $section );
		}
		if ( $secondary_button instanceof DOMElement && $secondary_button !== $primary_button ) {
			leadwerk_theme_bind_exact_anchor_keep_leading_svg(
				$xpath,
				$secondary_button,
				'.',
				$secondary_label,
				(string) ( $value['secondary_page_key'] ?? '' ),
				(string) ( $value['secondary_url'] ?? '' )
			);
		}
	}
}

function leadwerk_theme_bind_exact_acm_fleet_overview( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
		$subtitle_node = leadwerk_theme_dom_first( $xpath, './/p[1]', $head );
		if ( $subtitle_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_text( $subtitle_node, (string) ( $value['subtitle'] ?? '' ) );
		}
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	$aircraft = isset( $value['aircraft'] ) && is_array( $value['aircraft'] ) ? array_values( $value['aircraft'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"fleet-card") or contains(@class,"aircraft-card")]', $section ), count( $aircraft ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $aircraft[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1] | .//h4[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1] | .//span[1]', $node ), (string) ( $item['subtitle'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-500")][1] | .//div[contains(@class,"p-8")]//p[1] | .//p[1]', $node ), (string) ( $item['description'] ?? '' ) );
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), (string) ( $item['image_alt'] ?? '' ) );
		$link = ( $node instanceof DOMElement && $node->tagName === 'a' ) ? $node : leadwerk_theme_dom_first( $xpath, './/a[1]', $node );
		if ( $link ) leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_resolve_exact_href( (string) ( $item['page_key'] ?? '' ), (string) ( $item['url'] ?? '' ) ) );
		if ( ! ( $node instanceof DOMElement && 'a' === strtolower( $node->tagName ) ) ) {
			leadwerk_theme_bind_exact_anchor_keep_svg(
				$xpath,
				$node,
				'.//a[contains(@class,"link-arrow")][1] | .//a[1]',
				(string) ( $item['cta_label'] ?? '' ),
				(string) ( $item['page_key'] ?? '' ),
				(string) ( $item['url'] ?? '' )
			);
		}
	}

	$compare_head = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"fleet-compare-tabs")][1]/preceding-sibling::*[1]', $section );
	if ( $compare_head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $compare_head ), (string) ( $value['compare_label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h3[1] | .//h2[1]', $compare_head ), (string) ( $value['compare_title'] ?? '' ), 'heading' );
	}

	$compare_tabs = isset( $value['compare_tabs'] ) && is_array( $value['compare_tabs'] ) ? array_values( $value['compare_tabs'] ) : array();
	if ( ! empty( $compare_tabs ) ) {
		$tab_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/button[contains(concat(" ", normalize-space(@class), " "), " fleet-compare-tab ")]', $section ), count( $compare_tabs ) );
		foreach ( $tab_nodes as $idx => $tab_node ) {
			$item = $compare_tabs[ $idx ] ?? array();
			if ( ! is_array( $item ) || ! $tab_node instanceof DOMElement ) {
				continue;
			}
			$variant = trim( (string) ( $item['variant'] ?? '' ) );
			if ( '' !== $variant ) {
				$tab_node->setAttribute( 'data-tab', $variant );
			}
			leadwerk_theme_dom_set_text( $tab_node, (string) ( $item['label'] ?? '' ) );
		}
	}

	$compare_panels = isset( $value['compare_panels'] ) && is_array( $value['compare_panels'] ) ? array_values( $value['compare_panels'] ) : array();
	if ( ! empty( $compare_panels ) ) {
		$panel_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"fleet-compare-panel")]', $section ), count( $compare_panels ) );
		foreach ( $panel_nodes as $idx => $panel_node ) {
			$panel = $compare_panels[ $idx ] ?? array();
			if ( ! is_array( $panel ) || ! $panel_node instanceof DOMElement ) {
				continue;
			}
			$variant = trim( (string) ( $panel['variant'] ?? '' ) );
			if ( '' !== $variant ) {
				$panel_node->setAttribute( 'data-panel', $variant );
			}

			$rows      = isset( $panel['rows'] ) && is_array( $panel['rows'] ) ? array_values( $panel['rows'] ) : array();
			$row_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"fleet-bar-row")]', $panel_node ), count( $rows ) );
			foreach ( $row_nodes as $row_idx => $row_node ) {
				$row = $rows[ $row_idx ] ?? array();
				if ( ! is_array( $row ) || ! $row_node instanceof DOMElement ) {
					continue;
				}
				leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"fleet-bar-name")][1]', $row_node ), (string) ( $row['aircraft'] ?? '' ) );
				leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"fleet-bar-value")][1]', $row_node ), (string) ( $row['value'] ?? '' ) );
			}

			$scale = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"fleet-bar-scale")][1]', $panel_node );
			if ( $scale instanceof DOMElement ) {
				$labels = isset( $panel['scale_labels'] ) && is_array( $panel['scale_labels'] ) ? array_values( $panel['scale_labels'] ) : array();
				$label_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './span', $scale ), count( $labels ) );
				foreach ( $label_nodes as $label_idx => $label_node ) {
					$item = $labels[ $label_idx ] ?? array();
					if ( ! is_array( $item ) ) {
						continue;
					}
					leadwerk_theme_dom_set_text( $label_node, (string) ( $item['label'] ?? '' ) );
				}
			}
		}
	}
}

function leadwerk_theme_bind_exact_acm_aircraft_specs( $xpath, $section, $value ) {
	$title_node = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//h2[1]', $section );
	if ( ! $title_node instanceof DOMElement ) {
		$title_node = leadwerk_theme_dom_first( $xpath, './/h2[1]', $section );
	}
	leadwerk_theme_set_placeholder_markup( $title_node instanceof DOMElement ? $title_node : leadwerk_theme_dom_first( $xpath, './/h1[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );

	$label_node = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]', $section );
	if ( ! $label_node instanceof DOMElement ) {
		$label_node = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section );
	}
	if ( $label_node instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( $label_node, (string) ( $value['subtitle'] ?? '' ) );
	}

	$intro = trim( (string) ( $value['description'] ?? '' ) );
	if ( '' !== $intro ) {
		$intro_node = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"description") or contains(@class,"intro")][1]', $section );
		if ( $intro_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $intro_node, $intro );
		}
	}

	$spec_groups = isset( $value['spec_groups'] ) && is_array( $value['spec_groups'] ) ? array_values( $value['spec_groups'] ) : array();

	if ( ! empty( $spec_groups ) ) {
		$h3_nodes = leadwerk_theme_dom_query( $xpath, './/h3[contains(@class,"spec-group-title")]', $section );
		$wrappers = array();
		foreach ( $h3_nodes as $h3 ) {
			if ( $h3 instanceof DOMElement && $h3->parentNode instanceof DOMElement ) {
				$wrappers[] = $h3->parentNode;
			}
		}
		$wrappers = leadwerk_theme_dom_ensure_count( $wrappers, count( $spec_groups ) );
		foreach ( $wrappers as $gi => $wrap ) {
			if ( ! $wrap instanceof DOMElement ) {
				continue;
			}
			$group = $spec_groups[ $gi ] ?? array();
			if ( ! is_array( $group ) ) {
				continue;
			}
			$gh = leadwerk_theme_dom_first( $xpath, './/h3[contains(@class,"spec-group-title")][1]', $wrap );
			if ( $gh instanceof DOMElement ) {
				leadwerk_theme_dom_set_text( $gh, (string) ( $group['group_title'] ?? '' ) );
			}
			$grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"spec-grid")][1]', $wrap );
			if ( ! $grid instanceof DOMElement ) {
				continue;
			}
			$rows  = isset( $group['rows'] ) && is_array( $group['rows'] ) ? array_values( $group['rows'] ) : array();
			$items = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"spec-item")]', $grid );
			$items = leadwerk_theme_dom_ensure_count( $items, count( $rows ) );
			foreach ( $items as $ri => $node ) {
				$row = $rows[ $ri ] ?? array();
				if ( ! is_array( $row ) ) {
					continue;
				}
				leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"spec-value")][1]', $node ), (string) ( $row['value'] ?? '' ) );
				leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"spec-label")][1]', $node ), (string) ( $row['label'] ?? '' ) );
			}
		}
	} else {
	$specs = isset( $value['specs'] ) && is_array( $value['specs'] ) ? array_values( $value['specs'] ) : array();
	$spec_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"spec-row") or contains(@class,"spec-item")]', $section ), count( $specs ) );
	foreach ( $spec_nodes as $idx => $node ) {
		$item = $specs[ $idx ] ?? array();
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"label")][1] | .//dt[1]', $node ), (string) ( $item['label'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"value")][1] | .//dd[1]', $node ), (string) ( $item['value'] ?? '' ) );
	}
	}

	$footnote = trim( (string) ( $value['footnote'] ?? '' ) );
	if ( '' !== $footnote ) {
		$fn_node = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-center") and contains(@class,"mt-12")][1]', $section );
		if ( $fn_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_trusted_inner_html( $fn_node, $footnote );
		}
	}

	$gallery = isset( $value['gallery'] ) && is_array( $value['gallery'] ) ? array_values( $value['gallery'] ) : array();
	if ( ! empty( $gallery ) ) {
	$gallery_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"gallery")]//img | .//*[contains(@class,"gallery-item")]', $section ), count( $gallery ) );
	foreach ( $gallery_nodes as $idx => $node ) {
		$item = $gallery[ $idx ] ?? array();
		$target = ( $node instanceof DOMElement && $node->tagName === 'img' ) ? $node : leadwerk_theme_dom_first( $xpath, './/img[1]', $node );
		if ( $target ) {
			leadwerk_theme_dom_set_attr( $target, 'src', leadwerk_theme_get_exact_image_url( (int) ( $item['image'] ?? 0 ), (string) $target->getAttribute( 'src' ) ) );
				if ( ! empty( $item['image_alt'] ) ) {
					leadwerk_theme_dom_set_attr( $target, 'alt', (string) $item['image_alt'] );
		}
	}
		}
	}

	$fleet = isset( $value['fleet_links'] ) && is_array( $value['fleet_links'] ) ? array_values( $value['fleet_links'] ) : array();
	if ( ! empty( $fleet ) ) {
	$fleet_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"fleet-link")]', $section ), count( $fleet ) );
	foreach ( $fleet_nodes as $idx => $node ) {
		$item = $fleet[ $idx ] ?? array();
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1] | .//h4[1] | .//span[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), (string) ( $item['image_alt'] ?? '' ) );
		$link = ( $node instanceof DOMElement && $node->tagName === 'a' ) ? $node : leadwerk_theme_dom_first( $xpath, './/a[1]', $node );
			if ( $link ) {
				leadwerk_theme_dom_set_attr( $link, 'href', leadwerk_theme_resolve_exact_href( (string) ( $item['page_key'] ?? '' ), (string) ( $item['url'] ?? '' ) ) );
			}
		}
	}
}

/**
 * Bind ACM zone cards section (Global aircraft cabin zones).
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $section Section element.
 * @param array    $value   Field values.
 */
function leadwerk_theme_bind_exact_acm_zone_cards( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$cards = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"zone-card")]', $section );
	$nodes = leadwerk_theme_dom_ensure_count( $cards, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"zone-card-number")][1]', $node ), (string) ( $item['number'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"zone-card-title")][1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $node ), (string) ( $item['content'] ?? '' ) );
	}
}

/**
 * Bind ACM image carousel (full-width track).
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $section Section element.
 * @param array    $value   Field values.
 */
function leadwerk_theme_bind_exact_acm_image_carousel( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	$slides = isset( $value['slides'] ) && is_array( $value['slides'] ) ? array_values( $value['slides'] ) : array();
	$slide_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"carousel-slide")]', $section ), count( $slides ) );
	foreach ( $slide_nodes as $idx => $node ) {
		$item = $slides[ $idx ] ?? array();
		if ( ! is_array( $item ) ) {
			continue;
		}
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), (string) ( $item['image_alt'] ?? '' ) );
	}
}

/**
 * Bind ACM featured figure (floorplan block).
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $section Section element.
 * @param array    $value   Field values.
 */
function leadwerk_theme_bind_exact_acm_featured_figure( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	leadwerk_theme_bind_exact_image( $xpath, $section, './/div[contains(@class,"scroll-reveal")]//img[not(contains(@class,"section-emblem"))][1] | .//img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	$fn = trim( (string) ( $value['footnote'] ?? '' ) );
	if ( '' !== $fn ) {
		$fn_node = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-center") and contains(@class,"mt-6")][1]', $section );
		if ( $fn_node instanceof DOMElement ) {
			leadwerk_theme_dom_set_trusted_inner_html( $fn_node, $fn );
		}
	}
}

/**
 * Bind ACM fleet teaser (two fleet-link cards).
 *
 * @param DOMXPath $xpath   XPath.
 * @param DOMNode  $section Section element.
 * @param array    $value   Field values.
 */
function leadwerk_theme_bind_exact_acm_fleet_teaser( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$cards = leadwerk_theme_dom_query( $xpath, './/a[contains(@class,"fleet-link-card")]', $section );
	$nodes = leadwerk_theme_dom_ensure_count( $cards, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $node ), (string) ( $item['subtitle'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-500")][1] | .//div[contains(@class,"p-6")]//p[1]', $node ), (string) ( $item['description'] ?? '' ) );
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['image'] ?? 0 ), (string) ( $item['image_alt'] ?? '' ) );
		leadwerk_theme_dom_set_attr( $node, 'href', leadwerk_theme_resolve_exact_href( (string) ( $item['page_key'] ?? '' ), (string) ( $item['url'] ?? '' ) ) );
		leadwerk_theme_bind_exact_inline_label_keep_svg( $xpath, $node, './/*[contains(@class,"link-arrow")][1]', (string) ( $item['cta_label'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_process_split( $xpath, $section, $value ) {
	$grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $section );
	$left = null;
	$right_col = null;
	if ( $grid instanceof DOMElement ) {
		foreach ( leadwerk_theme_dom_query( $xpath, './div[contains(@class,"scroll-reveal")]', $grid ) as $col ) {
			if ( ! $col instanceof DOMElement ) {
				continue;
			}
			if ( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"process-step")][1]', $col ) ) {
				$right_col = $col;
			} elseif ( leadwerk_theme_dom_first( $xpath, './/h2[1]', $col ) ) {
				$left = $col;
			}
		}
	}
	$left = ( $left instanceof DOMElement ) ? $left : $section;

	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $left ), (string) ( $value['label'] ?? '' ) );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $left );
	leadwerk_theme_set_placeholder_markup( $h2, (string) ( $value['title'] ?? '' ), 'heading' );

	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( $h2 instanceof DOMElement && '' !== $intro_html ) {
		$n = $h2->nextSibling;
		while ( $n ) {
			$nx = $n->nextSibling;
			if ( $n instanceof DOMElement ) {
				$tag = strtolower( $n->tagName );
				if ( 'p' === $tag ) {
					leadwerk_theme_dom_remove( $n );
				} elseif ( 'a' === $tag && false !== strpos( ' ' . $n->getAttribute( 'class' ) . ' ', ' btn-primary ' ) ) {
					break;
				}
			}
			$n = $nx;
		}
		$doc    = $h2->ownerDocument;
		$holder = $doc->createElement( 'div' );
		$left->insertBefore( $holder, $h2->nextSibling );
		leadwerk_theme_dom_set_trusted_inner_html( $holder, $intro_html );
		while ( $holder->firstChild ) {
			$left->insertBefore( $holder->firstChild, $holder );
		}
		leadwerk_theme_dom_remove( $holder );
	}

	leadwerk_theme_bind_exact_button( $xpath, $left, './/a[contains(@class,"btn-primary")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
	if ( ! leadwerk_theme_dom_first( $xpath, './/a[contains(@class,"btn-primary")][1]', $left ) ) {
		leadwerk_theme_bind_exact_button( $xpath, $left, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
	}

	$steps_parent = ( $right_col instanceof DOMElement ) ? $right_col : $section;
	$steps        = isset( $value['steps'] ) && is_array( $value['steps'] ) ? array_values( $value['steps'] ) : array();
	$step_nodes   = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"process-step")]', $steps_parent ), count( $steps ) );
	foreach ( $step_nodes as $idx => $node ) {
		$item = $steps[ $idx ] ?? array();
		if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
			continue;
		}
		$step_val = trim( (string) ( $item['step'] ?? '' ) );
		if ( '' !== $step_val ) {
			$node->setAttribute( 'data-step', $step_val );
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $node ), (string) ( $item['content'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_betriebsmodelle( $xpath, $section, $value ) {
	$head = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]', $section );
	if ( $head instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $head ), (string) ( $value['label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $head ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}

	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( '' !== $intro_html ) {
		$intro_el = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"max-w-6xl")]//div[contains(@class,"text-center")][1]/following-sibling::p[1]', $section );
		if ( $intro_el instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $intro_el, $intro_html );
		}
	}

	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$cards = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"grid") and contains(@class,"md:grid-cols-2")]/div[contains(@class,"scroll-reveal")]', $section );
	$nodes = leadwerk_theme_dom_ensure_count( $cards, count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-number")][1]', $node ), (string) ( $item['kicker'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $node ), (string) ( $item['title'] ?? '' ) );
		$desc = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-500")][1]', $node );
		if ( ! $desc instanceof DOMElement ) {
			$desc = leadwerk_theme_dom_first( $xpath, './/ul[1]/preceding-sibling::p[1]', $node );
		}
		if ( $desc instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $desc, (string) ( $item['description'] ?? '' ) );
		}
		$features = isset( $item['features'] ) && is_array( $item['features'] ) ? array_values( $item['features'] ) : array();
		$list     = leadwerk_theme_dom_first( $xpath, './/ul[1]', $node );
		if ( $list ) {
			$lis = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './li', $list ), count( $features ) );
			foreach ( $lis as $fi => $li ) {
				if ( ! $li instanceof DOMElement ) {
					continue;
				}
				$span = leadwerk_theme_dom_first( $xpath, './span[contains(@class,"text-stone")][1]', $li );
				if ( $span instanceof DOMElement ) {
					leadwerk_theme_dom_set_text( $span, (string) ( $features[ $fi ]['text'] ?? '' ) );
				} else {
				leadwerk_theme_dom_set_text( $li, (string) ( $features[ $fi ]['text'] ?? '' ) );
			}
		}
	}
		$cta_l = trim( (string) ( $item['cta_label'] ?? '' ) );
		if ( '' !== $cta_l ) {
			leadwerk_theme_bind_exact_button( $xpath, $node, './/a[contains(@class,"btn-primary")][1]', (string) ( $item['cta_label'] ?? '' ), (string) ( $item['cta_page_key'] ?? '' ), (string) ( $item['cta_url'] ?? '' ) );
		}
	}
}

function leadwerk_theme_bind_exact_acm_aog_callout( $xpath, $section, $value ) {
	leadwerk_theme_bind_exact_image(
		$xpath,
		$section,
		'.//div[contains(@class,"image-zoom-bleed")]//img[not(contains(@class,"section-emblem"))][1] | .//div[contains(@class,"grid")]//img[not(contains(@class,"section-emblem"))][not(ancestor::div[contains(@class,"max-w-xl")])][1]',
		(int) ( $value['image'] ?? 0 ),
		(string) ( $value['image_alt'] ?? '' )
	);

	$box = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"content-box-outer")]//div[contains(@class,"max-w-xl")][1]', $section );
	if ( ! $box instanceof DOMElement ) {
		$box = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"max-w-xl")][1]', $section );
	}
	$ctx = $box instanceof DOMElement ? $box : $section;

	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $ctx ), (string) ( $value['label'] ?? '' ) );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $ctx );
	leadwerk_theme_set_placeholder_markup( $h2, (string) ( $value['title'] ?? '' ), 'heading' );

	$body_html = trim( (string) ( $value['body'] ?? '' ) );
	$space8    = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-8")][1]', $ctx );
	if ( $h2 instanceof DOMElement && '' !== $body_html ) {
		$n = $h2->nextSibling;
		while ( $n ) {
			if ( $n === $space8 ) {
				break;
			}
			$nx = $n->nextSibling;
			if ( $n instanceof DOMElement && 'p' === strtolower( $n->tagName ) ) {
				leadwerk_theme_dom_remove( $n );
			}
			$n = $nx;
		}
		$doc    = $h2->ownerDocument;
		$holder = $doc->createElement( 'div' );
		$ctx->insertBefore( $holder, $h2->nextSibling );
		leadwerk_theme_dom_set_trusted_inner_html( $holder, $body_html );
		while ( $holder->firstChild ) {
			$ctx->insertBefore( $holder->firstChild, $holder );
		}
		leadwerk_theme_dom_remove( $holder );
	}

	$highlights = isset( $value['highlights'] ) && is_array( $value['highlights'] ) ? array_values( $value['highlights'] ) : array();
	if ( $space8 instanceof DOMElement ) {
		$rows  = leadwerk_theme_dom_query( $xpath, './div[.//h4[1]]', $space8 );
		$nodes = leadwerk_theme_dom_ensure_count( $rows, count( $highlights ) );
		foreach ( $nodes as $idx => $row ) {
			$hi = $highlights[ $idx ] ?? array();
			if ( ! is_array( $hi ) || ! $row instanceof DOMElement ) {
				continue;
			}
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h4[1]', $row ), (string) ( $hi['title'] ?? '' ) );
			leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $row ), (string) ( $hi['content'] ?? '' ) );
		}
	}

	$phone_link = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")]', $section );
	if ( $phone_link instanceof DOMElement && ! empty( $value['phone'] ) ) {
		leadwerk_theme_dom_set_attr( $phone_link, 'href', 'tel:' . preg_replace( '/\s+/', '', (string) $value['phone'] ) );
		leadwerk_theme_dom_set_text( $phone_link, (string) $value['phone'] );
	}
	leadwerk_theme_bind_exact_button( $xpath, $ctx, './/a[contains(@class,"btn")][not(starts-with(@href,"tel:"))][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_acm_area_cards( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"area-card")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
			continue;
		}
		$icon_slot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"w-12") and contains(@class,"h-12")][1]', $node );
		if ( $icon_slot instanceof DOMElement ) {
			leadwerk_theme_dom_set_trusted_inner_html( $icon_slot, (string) ( $item['icon'] ?? '' ) );
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $node ), (string) ( $item['title'] ?? '' ) );
		$desc = leadwerk_theme_dom_first( $xpath, './/p[1]', $node );
		if ( $desc instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $desc, (string) ( $item['description'] ?? '' ) );
		}
		leadwerk_theme_bind_exact_anchor_keep_svg(
			$xpath,
			$node,
			'.//a[contains(@class,"link-arrow")][1]',
			(string) ( $item['link_label'] ?? '' ),
			(string) ( $item['link_page_key'] ?? '' ),
			(string) ( $item['link_url'] ?? '' )
		);
	}
}

function leadwerk_theme_bind_exact_acm_split_highlights( $xpath, $section, $value ) {
	leadwerk_theme_bind_exact_image( $xpath, $section, './/img[not(contains(@class,"section-emblem"))][1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );

	$text_col = null;
	$grid     = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $section );
	if ( $grid instanceof DOMElement ) {
		foreach ( leadwerk_theme_dom_query( $xpath, './div', $grid ) as $col ) {
			if ( leadwerk_theme_dom_first( $xpath, './/h2[1]', $col ) ) {
				$text_col = $col;
				break;
			}
		}
	}
	if ( ! $text_col instanceof DOMElement ) {
		return;
	}

	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $text_col ), (string) ( $value['label'] ?? '' ) );
	$h2 = leadwerk_theme_dom_first( $xpath, './/h2[1]', $text_col );
	leadwerk_theme_set_placeholder_markup( $h2, (string) ( $value['title'] ?? '' ), 'heading' );

	$intro_html = trim( (string) ( $value['intro'] ?? '' ) );
	if ( $h2 instanceof DOMElement && '' !== $intro_html ) {
		$intro_el = leadwerk_theme_dom_first( $xpath, './following-sibling::p[1]', $h2 );
		if ( $intro_el instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $intro_el, $intro_html );
		}
	}

	$highlights = isset( $value['highlights'] ) && is_array( $value['highlights'] ) ? array_values( $value['highlights'] ) : array();
	$wrap       = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-8")][1]', $text_col );
	if ( ! $wrap instanceof DOMElement ) {
		return;
	}
	$rows  = leadwerk_theme_dom_query( $xpath, './div', $wrap );
	$nodes = leadwerk_theme_dom_ensure_count( $rows, count( $highlights ) );
	foreach ( $nodes as $idx => $row ) {
		$hi = $highlights[ $idx ] ?? array();
		if ( ! is_array( $hi ) || ! $row instanceof DOMElement ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h4[1]', $row ), (string) ( $hi['title'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $row ), (string) ( $hi['content'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_stellen( $xpath, $section, $value ) {
	$label_node = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][1]//*[contains(@class,"section-label")][1]', $section );
	if ( ! $label_node instanceof DOMElement ) {
		$label_node = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section );
	}
	leadwerk_theme_dom_set_text( $label_node, (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$intro_el = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][contains(@class,"mb-16")][1]//p[1]', $section );
	if ( $intro_el instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $intro_el, (string) ( $value['intro'] ?? '' ) );
	}

	$current_lang = function_exists( 'leadwerk_theme_get_current_lang' ) ? leadwerk_theme_get_current_lang() : 'de';
	$tasks_heading = trim( (string) ( $value['tasks_heading'] ?? '' ) );
	if ( '' === $tasks_heading ) {
		$tasks_heading = 'en' === $current_lang ? 'Tasks' : 'Aufgaben';
	}
	$requirements_heading = trim( (string) ( $value['requirements_heading'] ?? '' ) );
	if ( '' === $requirements_heading ) {
		$requirements_heading = 'en' === $current_lang ? 'Requirements' : 'Anforderungen';
	}

	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/details[contains(@class,"job-card")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
			continue;
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/summary//h3[1]', $node ), (string) ( $item['title'] ?? '' ) );

		$summary = leadwerk_theme_dom_first( $xpath, './/summary[1]', $node );
		$tags    = $summary instanceof DOMElement
			? leadwerk_theme_dom_query( $xpath, './/span[contains(@class,"job-meta-tag")]', $summary )
			: array();
		$tags    = leadwerk_theme_dom_ensure_count( $tags, 3 );
		$meta    = array(
			(string) ( $item['department'] ?? '' ),
			(string) ( $item['location'] ?? '' ),
			(string) ( $item['type'] ?? '' ),
		);
		foreach ( $tags as $ti => $tel ) {
			if ( $tel instanceof DOMElement ) {
				leadwerk_theme_dom_set_text( $tel, $meta[ $ti ] ?? '' );
			}
		}

		$detail = leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"job-detail-content")][1]', $node );
		if ( $detail instanceof DOMElement ) {
			$grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid")][1]', $detail );
			if ( $grid instanceof DOMElement ) {
				foreach ( leadwerk_theme_dom_query( $xpath, './div', $grid ) as $col_index => $col ) {
					if ( ! $col instanceof DOMElement ) {
						continue;
					}
					$h4t = '';
					$h4  = leadwerk_theme_dom_first( $xpath, './/h4[1]', $col );
					if ( $h4 instanceof DOMElement ) {
						$h4t = strtolower( trim( $h4->textContent ) );
					}
					$ul = leadwerk_theme_dom_first( $xpath, './/ul[1]', $col );
					if ( ! $ul instanceof DOMElement ) {
						continue;
					}
					if ( false !== strpos( $h4t, 'aufgaben' ) || false !== strpos( $h4t, 'tasks' ) || 0 === (int) $col_index ) {
						leadwerk_theme_dom_set_text( $h4, $tasks_heading );
						leadwerk_theme_dom_set_inner_html( $ul, (string) ( $item['tasks'] ?? '' ) );
					} elseif ( false !== strpos( $h4t, 'anforderungen' ) || false !== strpos( $h4t, 'requirements' ) || 1 === (int) $col_index ) {
						leadwerk_theme_dom_set_text( $h4, $requirements_heading );
						leadwerk_theme_dom_set_inner_html( $ul, (string) ( $item['requirements'] ?? '' ) );
					}
				}
			}
			leadwerk_theme_bind_exact_button(
				$xpath,
				$detail,
				'.//a[contains(@class,"btn-primary")][1]',
				(string) ( $item['apply_label'] ?? '' ),
				(string) ( $item['apply_page_key'] ?? '' ),
				(string) ( $item['apply_url'] ?? '' )
			);
			if ( function_exists( 'leadwerk_theme_career_pdf_link_parts' ) ) {
				list( $pdf_href, $pdf_download ) = leadwerk_theme_career_pdf_link_parts( $item['pdf_url'] ?? '' );
			} else {
				$pdf_href     = leadwerk_theme_resolve_media_url( $item['pdf_url'] ?? '' );
				$pdf_download = true;
			}
			leadwerk_theme_bind_exact_anchor_keep_svg(
				$xpath,
				$detail,
				'.//a[contains(@class,"link-arrow")][1]',
				(string) ( $item['pdf_label'] ?? '' ),
				'',
				$pdf_href,
				$pdf_download
			);
		}

		$legacy = trim( (string) ( $item['description'] ?? '' ) );
		if ( '' !== $legacy && $detail instanceof DOMElement && '' === trim( (string) ( $item['tasks'] ?? '' ) ) && '' === trim( (string) ( $item['requirements'] ?? '' ) ) ) {
			$ul = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid")][1]//ul[1]', $detail );
			if ( $ul instanceof DOMElement ) {
				leadwerk_theme_dom_set_inner_html( $ul, $legacy );
			}
		}
	}

	$foot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")][contains(@class,"mt-16")][1]', $section );
	if ( $foot instanceof DOMElement ) {
		$fp = leadwerk_theme_dom_first( $xpath, './/p[1]', $foot );
		if ( $fp instanceof DOMElement ) {
			leadwerk_theme_dom_set_inner_html( $fp, (string) ( $value['footer_text'] ?? '' ) );
		}
		leadwerk_theme_bind_exact_button(
			$xpath,
			$foot,
			'.//a[contains(@class,"btn-primary")][1]',
			(string) ( $value['footer_label'] ?? '' ),
			(string) ( $value['footer_page_key'] ?? '' ),
			(string) ( $value['footer_url'] ?? '' )
		);
	}
}

function leadwerk_theme_bind_exact_acm_contact_command_grid( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//*[contains(@class,"section-label")][1]', $section ), (string) ( $value['label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$intro_el = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"text-center")]//p[1]', $section );
	if ( $intro_el instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( $intro_el, (string) ( $value['intro'] ?? '' ) );
	}

	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	if ( count( $items ) > 0 ) {
		$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/a[contains(@class,"command-card")]', $section ), count( $items ) );
		foreach ( $nodes as $idx => $node ) {
			$item = $items[ $idx ] ?? array();
			if ( ! is_array( $item ) || ! $node instanceof DOMElement ) {
				continue;
			}
			$icon_slot = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"w-12") and contains(@class,"h-12")][1]', $node );
			if ( $icon_slot instanceof DOMElement ) {
				leadwerk_theme_dom_set_trusted_inner_html( $icon_slot, (string) ( $item['icon'] ?? '' ) );
			}
			$spans = leadwerk_theme_dom_query( $xpath, './/div[contains(@class,"pr-8")]//span', $node );
			if ( isset( $spans[0] ) && $spans[0] instanceof DOMElement ) {
				leadwerk_theme_dom_set_text( $spans[0], (string) ( $item['title'] ?? '' ) );
			}
			if ( isset( $spans[1] ) && $spans[1] instanceof DOMElement ) {
				leadwerk_theme_dom_set_text( $spans[1], (string) ( $item['subtitle'] ?? '' ) );
			}
			leadwerk_theme_dom_set_attr(
				$node,
				'href',
				leadwerk_theme_resolve_exact_href( (string) ( $item['link_page_key'] ?? '' ), (string) ( $item['link_url'] ?? '' ) )
			);
		}
	}
}

function leadwerk_theme_bind_exact_acm_contact_dept_section( $xpath, $section, $value ) {
	if ( $section instanceof DOMElement ) {
		$sid = trim( (string) ( $value['section_id'] ?? '' ) );
		if ( '' !== $sid ) {
			$section->setAttribute( 'id', sanitize_title( $sid ) );
		}
	}

	$map_url = trim( (string) ( $value['map_embed_url'] ?? '' ) );
	if ( '' !== $map_url ) {
		$grid = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"grid") and contains(@class,"lg:grid-cols-2")][1]', $section );
		$cols = $grid instanceof DOMElement ? leadwerk_theme_dom_query( $xpath, './div', $grid ) : array();
		$left = $cols[0] ?? null;
		$h2   = $left instanceof DOMElement ? leadwerk_theme_dom_first( $xpath, './/h2[1]', $left ) : null;
		$left_html   = trim( (string) ( $value['left_column'] ?? '' ) );
		$intro_html  = trim( (string) ( $value['intro'] ?? '' ) );
		$footer_html = trim( (string) ( $value['footer_note'] ?? '' ) );
		$title_raw   = trim( (string) ( $value['title'] ?? '' ) );
		if ( $h2 instanceof DOMElement ) {
			if ( '' !== trim( wp_strip_all_tags( $title_raw ) ) ) {
				leadwerk_theme_set_placeholder_markup( $h2, $title_raw, 'heading' );
			}
			if ( '' !== $left_html || '' !== $intro_html || '' !== $footer_html ) {
				$n = $h2->nextSibling;
				while ( $n ) {
					$nx = $n->nextSibling;
					leadwerk_theme_dom_remove( $n );
					$n = $nx;
				}
				if ( $h2->parentNode instanceof DOMElement && $h2->ownerDocument ) {
					$parent = $h2->parentNode;
					$after  = $h2;
					foreach ( array( $left_html, $intro_html, $footer_html ) as $chunk ) {
						$chunk = trim( (string) $chunk );
						if ( '' === $chunk ) {
							continue;
						}
						$holder = $h2->ownerDocument->createElement( 'div' );
						if ( $after->nextSibling ) {
							$parent->insertBefore( $holder, $after->nextSibling );
						} else {
							$parent->appendChild( $holder );
						}
						leadwerk_theme_dom_set_trusted_inner_html( $holder, $chunk );
						while ( $holder->firstChild ) {
							$node = $holder->removeChild( $holder->firstChild );
							if ( $after->nextSibling ) {
								$parent->insertBefore( $node, $after->nextSibling );
							} else {
								$parent->appendChild( $node );
							}
							$after = $node;
						}
						leadwerk_theme_dom_remove( $holder );
					}
				}
			}
		}
		$right = $cols[1] ?? null;
		$iframe = $right instanceof DOMElement ? leadwerk_theme_dom_first( $xpath, './/iframe[1]', $right ) : null;
		if ( $iframe instanceof DOMElement ) {
			leadwerk_theme_dom_set_attr( $iframe, 'src', esc_url_raw( $map_url ) );
		}
		return;
	}

	$header = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"scroll-reveal")][contains(@class,"mb-4")][1]', $section );
	if ( $header instanceof DOMElement ) {
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $header ), (string) ( $value['section_label'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $header ), (string) ( $value['title'] ?? '' ), 'heading' );
	} else {
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	}

	$intro_el = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"text-stone-500")][contains(@class,"max-w-3xl")][1]', $section );
	if ( $intro_el instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $intro_el, (string) ( $value['intro'] ?? '' ) );
	}

	$profiles = isset( $value['profiles'] ) && is_array( $value['profiles'] ) ? array_values( $value['profiles'] ) : array();
	if ( count( $profiles ) > 0 ) {
		// #region agent log
		$broad_cards  = leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"profile-card")]', $section );
		$strict_cards = leadwerk_theme_dom_query( $xpath, './/div[contains(concat(\' \', normalize-space(@class), \' \'), \' profile-card \')]', $section );
		$sample       = array();
		foreach ( array_slice( $broad_cards, 0, 6 ) as $bn ) {
			if ( $bn instanceof DOMElement ) {
				$sample[] = $bn->tagName . ':' . (string) $bn->getAttribute( 'class' );
			}
		}
		$empty_profiles = 0;
		foreach ( $profiles as $pr ) {
			if ( ! is_array( $pr ) ) {
				continue;
			}
			$nm = trim( wp_strip_all_tags( (string) ( $pr['name'] ?? '' ) ) );
			$im = (int) ( $pr['image'] ?? 0 );
			if ( '' === $nm && 0 === $im ) {
				++$empty_profiles;
			}
		}
		$log_file    = dirname( LEADWERK_THEME_DIR ) . DIRECTORY_SEPARATOR . 'debug-42b553.log';
		$log_payload = array(
			'sessionId'     => '42b553',
			'hypothesisId'  => 'H1_xpath_profile_card',
			'location'      => 'exact-acm-render.php:leadwerk_theme_bind_exact_acm_contact_dept_section',
			'message'       => 'Profile card xpath: broad vs strict vs profiles',
			'data'          => array(
				'profiles_count'   => count( $profiles ),
				'broad_node_count' => is_array( $broad_cards ) ? count( $broad_cards ) : 0,
				'strict_count'     => is_array( $strict_cards ) ? count( $strict_cards ) : 0,
				'empty_profiles'   => $empty_profiles,
				'broad_sample'     => $sample,
			),
			'timestamp'     => (int) round( microtime( true ) * 1000 ),
		);
		@file_put_contents(
			$log_file,
			( function_exists( 'wp_json_encode' ) ? wp_json_encode( $log_payload ) : json_encode( $log_payload ) ) . "\n",
			FILE_APPEND
		);
		// #endregion
		// Nur echte Karten (div.profile-card). contains(@class,"profile-card") matcht zusaetzlich profile-card-image-wrap / profile-card-image und zerstoert dom_ensure_count (3:1-Verhaeltnis im Shell-HTML).
		$cards  = $strict_cards;
		$pnodes = leadwerk_theme_dom_ensure_count( $cards, count( $profiles ) );
		// #region agent log
		$verify = array(
			'sessionId'    => '42b553',
			'runId'        => 'post-fix',
			'hypothesisId' => 'H1_verify',
			'location'     => 'exact-acm-render.php:leadwerk_theme_bind_exact_acm_contact_dept_section:after_ensure',
			'message'      => 'After dom_ensure_count with strict xpath',
			'data'         => array(
				'profiles_count' => count( $profiles ),
				'pnodes_count'   => is_array( $pnodes ) ? count( $pnodes ) : 0,
			),
			'timestamp'    => (int) round( microtime( true ) * 1000 ),
		);
		@file_put_contents(
			$log_file,
			( function_exists( 'wp_json_encode' ) ? wp_json_encode( $verify ) : json_encode( $verify ) ) . "\n",
			FILE_APPEND
		);
		// #endregion
		foreach ( $pnodes as $pi => $card ) {
			$p = $profiles[ $pi ] ?? array();
			if ( ! is_array( $p ) || ! $card instanceof DOMElement ) {
				continue;
			}
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $card ), (string) ( $p['name'] ?? '' ) );
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"p-5")]/span[contains(@class,"block")][1]', $card ), (string) ( $p['role'] ?? '' ) );
			$tel_el = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")][1]', $card );
			if ( $tel_el instanceof DOMElement ) {
				$tel_href = trim( (string) ( $p['phone_url'] ?? '' ) );
				if ( '' === $tel_href && '' !== trim( (string) ( $p['phone'] ?? '' ) ) ) {
					$tel_href = 'tel:' . preg_replace( '/\s+/', '', (string) $p['phone'] );
				}
				if ( '' !== $tel_href ) {
					leadwerk_theme_dom_set_attr( $tel_el, 'href', esc_attr( $tel_href ) );
				}
				leadwerk_theme_dom_set_text( $tel_el, (string) ( $p['phone'] ?? '' ) );
			}
			$mail_el = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"mailto:")][1]', $card );
			if ( $mail_el instanceof DOMElement ) {
				$mh = trim( (string) ( $p['email_url'] ?? '' ) );
				if ( '' === $mh && '' !== trim( (string) ( $p['email'] ?? '' ) ) ) {
					$mh = 'mailto:' . sanitize_email( (string) $p['email'] );
				}
				if ( '' !== $mh ) {
					leadwerk_theme_dom_set_attr( $mail_el, 'href', esc_attr( $mh ) );
				}
				leadwerk_theme_dom_set_text( $mail_el, (string) ( $p['email'] ?? '' ) );
			}
			leadwerk_theme_bind_exact_image( $xpath, $card, './/img[1]', (int) ( $p['image'] ?? 0 ), (string) ( $p['name'] ?? '' ) );
		}
	}

	$foot_wrap = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"mt-10")][1]', $section );
	if ( $foot_wrap instanceof DOMElement && '' !== trim( (string) ( $value['footer_note'] ?? '' ) ) ) {
		leadwerk_theme_dom_set_trusted_inner_html( $foot_wrap, (string) ( $value['footer_note'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_acm_contact_inquiry_band( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"section-label")][1]', $section ), (string) ( $value['section_label'] ?? '' ) );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$body_el = leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"max-w-2xl")][1]', $section );
	if ( $body_el instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $body_el, (string) ( $value['body'] ?? '' ) );
	}
	$tel_a = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-3")]//a[starts-with(@href,"tel:")][1]', $section );
	if ( $tel_a instanceof DOMElement ) {
		$tu = trim( (string) ( $value['phone_url'] ?? '' ) );
		if ( '' === $tu && '' !== trim( (string) ( $value['phone'] ?? '' ) ) ) {
			$tu = 'tel:' . preg_replace( '/\s+/', '', (string) $value['phone'] );
		}
		if ( '' !== $tu ) {
			leadwerk_theme_dom_set_attr( $tel_a, 'href', esc_attr( $tu ) );
		}
		leadwerk_theme_dom_set_text( $tel_a, (string) ( $value['phone'] ?? '' ) );
	}
	$mail_a = leadwerk_theme_dom_first( $xpath, './/div[contains(@class,"space-y-3")]//a[starts-with(@href,"mailto:")][1]', $section );
	if ( $mail_a instanceof DOMElement ) {
		$eu = trim( (string) ( $value['email_url'] ?? '' ) );
		if ( '' === $eu && '' !== trim( (string) ( $value['email'] ?? '' ) ) ) {
			$eu = 'mailto:' . sanitize_email( (string) $value['email'] );
		}
		if ( '' !== $eu ) {
			leadwerk_theme_dom_set_attr( $mail_a, 'href', esc_attr( $eu ) );
		}
		leadwerk_theme_dom_set_text( $mail_a, (string) ( $value['email'] ?? '' ) );
	}
	$addr_el = leadwerk_theme_dom_first( $xpath, './/address[1]', $section );
	if ( $addr_el instanceof DOMElement ) {
		$addr_txt = trim( (string) ( $value['address'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( $addr_el, $addr_txt ? wp_kses_post( nl2br( esc_html( $addr_txt ), false ) ) : '' );
	}
	leadwerk_theme_bind_exact_button(
		$xpath,
		$section,
		'.//a[contains(@class,"btn-outline-white")][1]',
		(string) ( $value['cta_label'] ?? '' ),
		(string) ( $value['cta_page_key'] ?? '' ),
		(string) ( $value['cta_url'] ?? '' )
	);
}

function leadwerk_theme_bind_exact_acm_departments( $xpath, $section, $value ) {
	$departments = isset( $value['departments'] ) && is_array( $value['departments'] ) ? array_values( $value['departments'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"department") or contains(@class,"contact-group")]', $section ), count( $departments ) );
	foreach ( $nodes as $idx => $node ) {
		$dept = $departments[ $idx ] ?? array();
		if ( ! is_array( $dept ) ) continue;
		if ( $node instanceof DOMElement && ! empty( $dept['anchor_id'] ) ) {
			$node->setAttribute( 'id', sanitize_title( (string) $dept['anchor_id'] ) );
		}
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h2[1] | .//h3[1]', $node ), (string) ( $dept['title'] ?? '' ) );
		$contacts = isset( $dept['contacts'] ) && is_array( $dept['contacts'] ) ? array_values( $dept['contacts'] ) : array();
		$contact_nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"contact-card") or contains(@class,"person")]', $node ), count( $contacts ) );
		foreach ( $contact_nodes as $ci => $cnode ) {
			$c = $contacts[ $ci ] ?? array();
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"name")][1] | .//h4[1]', $cnode ), (string) ( $c['name'] ?? '' ) );
			leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"role") or contains(@class,"position")][1]', $cnode ), (string) ( $c['role'] ?? '' ) );
			$phone_el = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"tel:")][1]', $cnode );
			if ( $phone_el && ! empty( $c['phone'] ) ) {
				leadwerk_theme_dom_set_attr( $phone_el, 'href', 'tel:' . preg_replace( '/\s+/', '', (string) $c['phone'] ) );
				leadwerk_theme_dom_set_text( $phone_el, (string) $c['phone'] );
			}
			$email_el = leadwerk_theme_dom_first( $xpath, './/a[starts-with(@href,"mailto:")][1]', $cnode );
			if ( $email_el && ! empty( $c['email'] ) ) {
				leadwerk_theme_dom_set_attr( $email_el, 'href', 'mailto:' . sanitize_email( (string) $c['email'] ) );
				leadwerk_theme_dom_set_text( $email_el, (string) $c['email'] );
			}
			leadwerk_theme_bind_exact_image( $xpath, $cnode, './/img[1]', (int) ( $c['image'] ?? 0 ), (string) ( $c['name'] ?? '' ) );
		}
	}
}

/**
 * Published acm_news posts for overview/archive (one query per request, per language).
 *
 * @return WP_Post[]
 */
function leadwerk_theme_get_acm_news_listing_posts() {
	static $cached = array();

	if ( ! post_type_exists( 'acm_news' ) ) {
		return array();
	}

	$args = array(
		'post_type'           => 'acm_news',
		'post_status'         => 'publish',
		'posts_per_page'      => -1,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		'suppress_filters'    => false,
		'ignore_sticky_posts' => true,
	);

	$cache_key = 'all';

	if ( class_exists( 'Leadwerk_Translation_API' ) ) {
		$lang    = sanitize_key( (string) leadwerk_theme_get_current_lang() );
		$default = sanitize_key( (string) Leadwerk_Translation_API::get_default_language() );
		if ( '' === $lang ) {
			$lang = $default;
		}
		$cache_key = $lang;
		$meta_key  = Leadwerk_Translation_API::META_LANG;

		if ( $lang === $default ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'value'   => $default,
					'compare' => '=',
				),
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$args['meta_query'] = array(
				array(
					'key'     => $meta_key,
					'value'   => $lang,
					'compare' => '=',
				),
			);
		}
	}

	if ( isset( $cached[ $cache_key ] ) ) {
		return $cached[ $cache_key ];
	}

	$posts = get_posts( $args );
	usort(
		$posts,
		static function ( $a, $b ) {
			$ta = leadwerk_theme_get_acm_news_list_timestamp( $a->ID );
			$tb = leadwerk_theme_get_acm_news_list_timestamp( $b->ID );
			if ( $ta === $tb ) {
				return ( (int) $b->ID <=> (int) $a->ID );
			}
			return ( $ta > $tb ) ? -1 : 1;
		}
	);
	$cached[ $cache_key ] = $posts;

	return $cached[ $cache_key ];
}

/**
 * Allowed filter slugs for news cards (must match filter button data-filter).
 *
 * @return string[]
 */
function leadwerk_theme_acm_news_allowed_filter_slugs() {
	$slugs = array( 'unternehmen', 'operations', 'flotte', 'maintenance', 'partner', 'handling', 'sicherheit' );
	return apply_filters( 'leadwerk_theme_acm_news_filter_slugs', $slugs );
}

/**
 * Meta key for news filter slug (set in theme functions.php).
 *
 * @return string
 */
function leadwerk_theme_acm_news_filter_meta_key() {
	return defined( 'LEADWERK_THEME_ACM_NEWS_FILTER_SLUG_META' )
		? (string) LEADWERK_THEME_ACM_NEWS_FILTER_SLUG_META
		: 'acm_news_filter_slug';
}

/**
 * Resolve the display language for ACM news UI.
 *
 * @param int         $post_id  Optional post ID.
 * @param string|null $fallback Optional fallback language.
 * @return string
 */
function leadwerk_theme_resolve_acm_news_language( $post_id = 0, $fallback = null ) {
	$lang = '';

	if ( $post_id > 0 && class_exists( 'Leadwerk_Translation_API' ) ) {
		$lang = sanitize_key( (string) Leadwerk_Translation_API::get_post_language( $post_id ) );
	}

	if ( '' === $lang && null !== $fallback ) {
		$lang = sanitize_key( (string) $fallback );
	}

	if ( '' === $lang ) {
		$lang = sanitize_key( (string) leadwerk_theme_get_current_lang() );
	}

	return 'en' === $lang ? 'en' : 'de';
}

/**
 * Fallback display labels for news filters.
 *
 * @param string|null $lang Optional language.
 * @return array<string,string>
 */
function leadwerk_theme_get_acm_news_filter_display_defaults( $lang = null ) {
	$lang = leadwerk_theme_resolve_acm_news_language( 0, $lang );

	if ( 'en' === $lang ) {
		return array(
			'all'         => 'All',
			'unternehmen' => 'Company',
			'operations'  => 'Operations',
			'flotte'      => 'Fleet',
			'maintenance' => 'Maintenance',
			'partner'     => 'Partner',
			'handling'    => 'Handling',
			'sicherheit'  => 'Safety',
		);
	}

	return array(
		'all'         => 'Alle',
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
 * Translated filter labels from the news page editor content.
 *
 * @param string|null $lang Optional language.
 * @return array<string,string>
 */
function leadwerk_theme_get_acm_news_filter_display_labels( $lang = null ) {
	static $cache = array();

	$lang = leadwerk_theme_resolve_acm_news_language( 0, $lang );
	if ( isset( $cache[ $lang ] ) ) {
		return $cache[ $lang ];
	}

	$labels  = leadwerk_theme_get_acm_news_filter_display_defaults( $lang );
	$page_id = leadwerk_theme_get_page_id( 'acm-news-v1', $lang );

	if ( $page_id > 0 && function_exists( 'get_field' ) ) {
		$sections = get_field( 'acm_news_sections', $page_id );
		if ( is_array( $sections ) ) {
			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}

				$layout = sanitize_key( (string) ( $section['acf_fc_layout'] ?? $section['layout'] ?? '' ) );
				if ( 'news_filters' !== $layout ) {
					continue;
				}

				foreach ( (array) ( $section['filters'] ?? array() ) as $filter ) {
					if ( ! is_array( $filter ) ) {
						continue;
					}

					$slug  = sanitize_title( (string) ( $filter['slug'] ?? '' ) );
					$label = trim( (string) ( $filter['label'] ?? '' ) );
					if ( '' === $slug || '' === $label ) {
						continue;
					}

					$labels[ $slug ] = leadwerk_theme_acm_news_normalize_visible_text( $label );
				}

				break;
			}
		}
	}

	$cache[ $lang ] = $labels;
	return $cache[ $lang ];
}

/**
 * Resolve data-category slug for a news post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_acm_news_card_category_slug( $post_id ) {
	$post_id = (int) $post_id;
	$key     = leadwerk_theme_acm_news_filter_meta_key();
	$slug    = sanitize_title( (string) get_post_meta( $post_id, $key, true ) );
	$allowed = leadwerk_theme_acm_news_allowed_filter_slugs();
	if ( in_array( $slug, $allowed, true ) ) {
		return $slug;
	}
	return 'unternehmen';
}

/**
 * Human label for filter slug (choices from theme if available).
 *
 * @param string      $slug Slug.
 * @param string|null $lang Optional language.
 * @return string
 */
function leadwerk_theme_acm_news_filter_slug_display_label( $slug, $lang = null ) {
	$slug = sanitize_title( (string) $slug );
	$lang = leadwerk_theme_resolve_acm_news_language( 0, $lang );

	$choices = leadwerk_theme_get_acm_news_filter_display_labels( $lang );
	if ( isset( $choices[ $slug ] ) ) {
		return (string) $choices[ $slug ];
	}

	return '' !== $slug ? ucfirst( $slug ) : '';
}

/**
 * Normalize plain text for news cards: HTML entities (incl. double-encoded) and common UTF-8 mojibake.
 *
 * @param string $text Raw title, excerpt, or stripped body.
 * @return string
 */
function leadwerk_theme_acm_news_normalize_visible_text( $text ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return '';
	}
	$rounds = 0;
	$prev   = null;
	while ( $rounds < 6 && $prev !== $text ) {
		$prev = $text;
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		++$rounds;
	}
	// UTF-8 bytes misread as ISO-8859-1 (e.g. "grÃ¶ÃŸte", "ArchivbeitrÃ¤ge").
	if ( function_exists( 'mb_convert_encoding' ) && preg_match( '/Ã./u', $text ) ) {
		$try = mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' );
		if ( is_string( $try ) && $try !== $text && mb_check_encoding( $try, 'UTF-8' ) ) {
			$had_umlaut = (bool) preg_match( '/[äöüÄÖÜß]/u', $text );
			$has_umlaut = (bool) preg_match( '/[äöüÄÖÜß]/u', $try );
			if ( ( ! $had_umlaut && $has_umlaut ) || ( $has_umlaut && mb_strlen( $try ) < mb_strlen( $text ) ) ) {
				$text = $try;
			}
		}
	}
	return $text;
}

/**
 * Display date for listing cards (import meta or post date).
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_format_acm_news_list_date_legacy( $post_id ) {
	$post_id = (int) $post_id;
	$raw_cf  = defined( 'LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META' )
		? get_post_meta( $post_id, LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META, true )
		: '';
	$raw     = ( is_string( $raw_cf ) && '' !== trim( $raw_cf ) )
		? $raw_cf
		: get_post_meta( $post_id, '_leadwerk_news_datetime', true );
	$ts      = false;
	if ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$ts = strtotime( $raw );
	}
	if ( ! $ts ) {
		$p = get_post( $post_id );
		$ts = ( $p && isset( $p->post_date ) ) ? strtotime( $p->post_date ) : false;
	}
	if ( ! $ts ) {
		return '';
	}
	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	$locale = is_string( $locale ) ? $locale : '';
	// Avoid broken localized month names on some hosts (e.g. "März" → "Mrz").
	if ( 0 === strpos( $locale, 'de' ) && function_exists( 'wp_date' ) ) {
		$months = array(
			1  => 'Januar',
			2  => 'Februar',
			3  => 'März',
			4  => 'April',
			5  => 'Mai',
			6  => 'Juni',
			7  => 'Juli',
			8  => 'August',
			9  => 'September',
			10 => 'Oktober',
			11 => 'November',
			12 => 'Dezember',
		);
		$m_num = (int) wp_date( 'n', $ts );
		$day   = wp_date( 'j', $ts );
		$year  = wp_date( 'Y', $ts );
		$mon   = isset( $months[ $m_num ] ) ? $months[ $m_num ] : wp_date( 'F', $ts );
		return $day . '. ' . $mon . ' ' . $year;
	}
	return date_i18n( 'j. F Y', $ts );
}

/**
 * Resolve the timestamp for listing cards (import meta or post date).
 *
 * @param int $post_id Post ID.
 * @return int
 */
function leadwerk_theme_get_acm_news_list_timestamp( $post_id ) {
	$post_id = (int) $post_id;
	$raw_cf  = '';
	if ( defined( 'LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META' ) ) {
		$raw_cf = get_post_meta( $post_id, LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META, true );
	}
	$raw_legacy = get_post_meta( $post_id, '_leadwerk_news_datetime', true );
	$raw        = ( is_string( $raw_cf ) && '' !== trim( $raw_cf ) ) ? $raw_cf : $raw_legacy;
	$ts         = false;
	if ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$ts = strtotime( $raw );
	}
	if ( ! $ts ) {
		$post = get_post( $post_id );
		$ts   = ( $post && isset( $post->post_date ) ) ? strtotime( $post->post_date ) : false;
	}

	return $ts ? (int) $ts : 0;
}

/**
 * Datetime attribute value for news cards.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function leadwerk_theme_get_acm_news_list_datetime_attribute( $post_id ) {
	$ts = leadwerk_theme_get_acm_news_list_timestamp( $post_id );
	if ( ! $ts ) {
		return '';
	}

	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
}

/**
 * Display date for listing cards with explicit DE/EN formatting.
 *
 * @param int         $post_id Post ID.
 * @param string|null $lang    Optional language.
 * @return string
 */
function leadwerk_theme_format_acm_news_list_date( $post_id, $lang = null ) {
	$ts = leadwerk_theme_get_acm_news_list_timestamp( $post_id );
	if ( ! $ts ) {
		return '';
	}

	$lang  = leadwerk_theme_resolve_acm_news_language( (int) $post_id, $lang );
	$day   = function_exists( 'wp_date' ) ? wp_date( 'j', $ts ) : gmdate( 'j', $ts );
	$year  = function_exists( 'wp_date' ) ? wp_date( 'Y', $ts ) : gmdate( 'Y', $ts );
	$m_num = (int) ( function_exists( 'wp_date' ) ? wp_date( 'n', $ts ) : gmdate( 'n', $ts ) );

	if ( 'en' === $lang ) {
		$months = array(
			1  => 'January',
			2  => 'February',
			3  => 'March',
			4  => 'April',
			5  => 'May',
			6  => 'June',
			7  => 'July',
			8  => 'August',
			9  => 'September',
			10 => 'October',
			11 => 'November',
			12 => 'December',
		);
		$month = isset( $months[ $m_num ] ) ? $months[ $m_num ] : ( function_exists( 'wp_date' ) ? wp_date( 'F', $ts ) : gmdate( 'F', $ts ) );

		return $month . ' ' . $day . ', ' . $year;
	}

	$months = array(
		1  => 'Januar',
		2  => 'Februar',
		3  => "M\u{00E4}rz",
		4  => 'April',
		5  => 'Mai',
		6  => 'Juni',
		7  => 'Juli',
		8  => 'August',
		9  => 'September',
		10 => 'Oktober',
		11 => 'November',
		12 => 'Dezember',
	);
	$month = isset( $months[ $m_num ] ) ? $months[ $m_num ] : ( function_exists( 'wp_date' ) ? wp_date( 'F', $ts ) : gmdate( 'F', $ts ) );

	return $day . '. ' . $month . ' ' . $year;
}

/**
 * Plain-text teaser for cards.
 *
 * @param int  $post_id Post ID.
 * @param bool $archive Longer excerpt for archive variant.
 * @return string
 */
function leadwerk_theme_acm_news_card_excerpt_text( $post_id, $archive = false ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return '';
	}
	$excerpt = trim( (string) $post->post_excerpt );
	if ( '' !== $excerpt ) {
		return leadwerk_theme_acm_news_normalize_visible_text( wp_strip_all_tags( $excerpt ) );
	}
	$words = $archive ? 45 : 28;
	$plain = leadwerk_theme_acm_news_normalize_visible_text( wp_strip_all_tags( (string) $post->post_content ) );
	return wp_trim_words( $plain, $words, '…' );
}

/**
 * SVG arrow for "Weiterlesen" link (trusted HTML fragment).
 *
 * @param bool $inline Whether to add inline class (archive layout).
 * @return string
 */
function leadwerk_theme_acm_news_read_more_svg_html( $inline = false ) {
	$cls = $inline ? 'w-4 h-4 inline' : 'w-4 h-4';
	return '<svg class="' . esc_attr( $cls ) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>';
}

/**
 * Theme-string based CTA label for news cards.
 *
 * @param string|null $lang Optional language.
 * @return string
 */
function leadwerk_theme_get_acm_news_read_more_label( $lang = null ) {
	$lang = leadwerk_theme_resolve_acm_news_language( 0, $lang );

	return leadwerk_theme_get_string(
		'news_read_more_label',
		'en' === $lang ? 'Read more' : 'Weiterlesen',
		$lang
	);
}

/**
 * Resolve the image URL for a news card or teaser card.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function leadwerk_theme_get_acm_news_card_image_url( WP_Post $post ) {
	$thumb_id = (int) get_post_thumbnail_id( $post );
	$img_url  = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
	if ( ! $img_url ) {
		$img_url = (string) apply_filters( 'leadwerk_theme_acm_news_card_placeholder_image_url', '' );
	}
	if ( ! $img_url ) {
		$img_url = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900"><rect fill="#e7e5e4" width="1200" height="900"/></svg>' );
	}

	return (string) $img_url;
}

/**
 * Build one homepage teaser article node.
 *
 * @param DOMDocument $doc  Owner document.
 * @param WP_Post     $post Post object.
 * @return DOMElement
 */
function leadwerk_theme_build_acm_home_news_teaser_article_element( DOMDocument $doc, WP_Post $post ) {
	$permalink = get_permalink( $post );
	$title     = leadwerk_theme_acm_news_normalize_visible_text( get_the_title( $post ) );
	$img_url   = leadwerk_theme_get_acm_news_card_image_url( $post );
	$img_attr  = ( is_string( $img_url ) && preg_match( '#^data:#i', $img_url ) ) ? esc_attr( $img_url ) : esc_url( $img_url );
	$cat_slug  = leadwerk_theme_acm_news_card_category_slug( $post->ID );
	$lang      = leadwerk_theme_resolve_acm_news_language( $post->ID );
	$cat_label = leadwerk_theme_acm_news_filter_slug_display_label( $cat_slug, $lang );

	$article = $doc->createElement( 'article' );
	$article->setAttribute( 'class', 'group scroll-reveal' );
	$article->setAttribute( 'data-home-news', 'true' );

	$link = $doc->createElement( 'a' );
	$link->setAttribute( 'class', 'block' );
	$link->setAttribute( 'href', esc_url( $permalink ) );

	$media = $doc->createElement( 'div' );
	$media->setAttribute( 'class', 'aspect-[4/3] overflow-hidden mb-5' );
	$img = $doc->createElement( 'img' );
	$img->setAttribute( 'class', 'h-full w-full object-cover object-center transition-transform duration-700 group-hover:scale-105' );
	$img->setAttribute( 'loading', 'lazy' );
	$img->setAttribute( 'src', $img_attr );
	$img->setAttribute( 'alt', esc_attr( wp_strip_all_tags( $title ) ) );
	$media->appendChild( $img );
	$link->appendChild( $media );

	$body = $doc->createElement( 'div' );
	$body->setAttribute( 'class', 'px-1' );
	$meta = $doc->createElement( 'div' );
	$meta->setAttribute( 'class', 'flex items-center gap-3 text-xs text-stone-400 mb-3' );
	$cat = $doc->createElement( 'span' );
	$cat->setAttribute( 'class', 'text-olive uppercase tracking-wider font-medium' );
	$cat->appendChild( $doc->createTextNode( $cat_label ) );
	$sep = $doc->createElement( 'span' );
	$sep->appendChild( $doc->createTextNode( "\u{2022}" ) );
	$time = $doc->createElement( 'time' );
	$datetime_attr = leadwerk_theme_get_acm_news_list_datetime_attribute( $post->ID );
	if ( '' !== $datetime_attr ) {
		$time->setAttribute( 'datetime', $datetime_attr );
	}
	$time->appendChild( $doc->createTextNode( leadwerk_theme_format_acm_news_list_date( $post->ID, $lang ) ) );
	$meta->appendChild( $cat );
	$meta->appendChild( $sep );
	$meta->appendChild( $time );
	$body->appendChild( $meta );

	$headline = $doc->createElement( 'h3' );
	$headline->setAttribute( 'class', 'font-serif text-xl lg:text-2xl text-stone-800 group-hover:text-olive transition-colors leading-snug font-normal' );
	$headline->appendChild( $doc->createTextNode( $title ) );
	$body->appendChild( $headline );

	$link->appendChild( $body );
	$article->appendChild( $link );

	return $article;
}

/**
 * Build one news card article node.
 *
 * @param DOMDocument $doc     Owner document.
 * @param WP_Post     $post    Post.
 * @param string      $variant "grid"|"archive".
 * @return DOMElement
 */
function leadwerk_theme_build_acm_news_article_element( DOMDocument $doc, WP_Post $post, $variant ) {
	$variant   = ( 'archive' === $variant ) ? 'archive' : 'grid';
	$permalink = get_permalink( $post );
	$title     = leadwerk_theme_acm_news_normalize_visible_text( get_the_title( $post ) );
	$img_url   = leadwerk_theme_get_acm_news_card_image_url( $post );
	$lang      = leadwerk_theme_resolve_acm_news_language( $post->ID );

	$article = $doc->createElement( 'article' );
	if ( 'grid' === $variant ) {
		$article->setAttribute( 'class', 'news-card scroll-reveal flex flex-col' );
		$article->setAttribute( 'data-imported-news', 'true' );
	} else {
		$article->setAttribute( 'class', 'news-card scroll-reveal flex flex-col' );
		$article->setAttribute( 'data-archive-item', 'true' );
	}
	$cat_slug = leadwerk_theme_acm_news_card_category_slug( $post->ID );
	$article->setAttribute( 'data-category', $cat_slug );
	$cat_label = leadwerk_theme_acm_news_filter_slug_display_label( $cat_slug, $lang );

	$a_img = $doc->createElement( 'a' );
	$a_img->setAttribute( 'class', 'block' );
	$a_img->setAttribute( 'href', esc_url( $permalink ) );
	$div_aspect = $doc->createElement( 'div' );
	$div_aspect->setAttribute( 'class', 'aspect-[4/3] overflow-hidden' );
	$img = $doc->createElement( 'img' );
	$img->setAttribute( 'class', 'w-full h-full object-cover' );
	$img->setAttribute( 'loading', 'lazy' );
	$img->setAttribute( 'alt', esc_attr( wp_strip_all_tags( $title ) ) );
	$src_attr = ( is_string( $img_url ) && preg_match( '#^data:#i', $img_url ) ) ? esc_attr( $img_url ) : esc_url( $img_url );
	$img->setAttribute( 'src', $src_attr );
	$div_aspect->appendChild( $img );
	$a_img->appendChild( $div_aspect );
	$article->appendChild( $a_img );

	$body = $doc->createElement( 'div' );
	$body->setAttribute( 'class', 'news-card-body' );

	$row = $doc->createElement( 'div' );
	$row->setAttribute( 'class', 'flex items-center gap-3 mb-3' );
	$pill = $doc->createElement( 'span' );
	$pill->setAttribute( 'class', 'news-category-pill' );
	$pill->appendChild( $doc->createTextNode( $cat_label ) );
	$date_span = $doc->createElement( 'span' );
	$date_span->setAttribute( 'class', 'news-date' );
	$date_span->appendChild( $doc->createTextNode( leadwerk_theme_format_acm_news_list_date( $post->ID, $lang ) ) );
	$row->appendChild( $pill );
	$row->appendChild( $date_span );
	$body->appendChild( $row );

	$h3 = $doc->createElement( 'h3' );
	$h3->setAttribute( 'class', 'font-serif text-stone-900 font-light mb-3' );
	$h3->setAttribute( 'style', 'font-size: 1.5rem; line-height: 1.25;' );
	$a_title = $doc->createElement( 'a' );
	$a_title->setAttribute( 'class', 'hover:text-olive transition-colors' );
	$a_title->setAttribute( 'href', esc_url( $permalink ) );
	$a_title->appendChild( $doc->createTextNode( $title ) );
	$h3->appendChild( $a_title );
	$body->appendChild( $h3 );

	$clamp   = ( 'archive' === $variant ) ? 'line-clamp-3' : 'line-clamp-2';
	$excerpt = $doc->createElement( 'p' );
	$excerpt->setAttribute( 'class', 'text-stone-500 text-sm leading-relaxed mb-5 ' . $clamp . ' flex-1' );
	$excerpt->appendChild( $doc->createTextNode( leadwerk_theme_acm_news_card_excerpt_text( $post->ID, 'archive' === $variant ) ) );
	$body->appendChild( $excerpt );

	$a_rm = $doc->createElement( 'a' );
	$a_rm->setAttribute( 'class', 'link-arrow mt-auto' );
	$a_rm->setAttribute( 'href', esc_url( $permalink ) );
	leadwerk_theme_dom_set_trusted_inner_html(
		$a_rm,
		esc_html( leadwerk_theme_get_acm_news_read_more_label( $lang ) ) . ' ' . leadwerk_theme_acm_news_read_more_svg_html( 'archive' === $variant )
	);
	$body->appendChild( $a_rm );

	$article->appendChild( $body );
	return $article;
}

/**
 * Remove all article children from a container.
 *
 * @param DOMXPath   $xpath     XPath.
 * @param DOMElement $container Grid container.
 * @return void
 */
function leadwerk_theme_acm_news_clear_article_children( $xpath, DOMElement $container ) {
	foreach ( leadwerk_theme_dom_query( $xpath, './article', $container ) as $node ) {
		leadwerk_theme_dom_remove( $node );
	}
}

function leadwerk_theme_bind_exact_acm_news_filter_bar( $xpath, $section, $value ) {
	$filters = isset( $value['filters'] ) && is_array( $value['filters'] ) ? array_values( $value['filters'] ) : array();
	$nodes   = leadwerk_theme_dom_ensure_count(
		leadwerk_theme_dom_query(
			$xpath,
			'.//div[@id="news-filters"]//button[contains(@class,"filter-btn")] | .//button[contains(@class,"filter-btn")]',
			$section
		),
		count( $filters )
	);
	foreach ( $nodes as $idx => $node ) {
		$item = $filters[ $idx ] ?? array();
		if ( ! $node instanceof DOMElement || ! is_array( $item ) ) {
			continue;
		}
		$slug = trim( (string) ( $item['slug'] ?? '' ) );
		leadwerk_theme_dom_set_attr( $node, 'data-filter', $slug );
		leadwerk_theme_dom_set_text( $node, (string) ( $item['label'] ?? '' ) );
		$def = ! empty( $item['is_default'] );
		leadwerk_theme_dom_toggle_class( $node, 'active', $def );
	}
}

function leadwerk_theme_bind_exact_acm_news_grid( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );

	$container = leadwerk_theme_dom_first( $xpath, './/*[@id="news-articles"]', $section );
	if ( ! $container instanceof DOMElement ) {
		return;
	}
	leadwerk_theme_acm_news_clear_article_children( $xpath, $container );
	$doc   = $container->ownerDocument;
	$posts = leadwerk_theme_get_acm_news_listing_posts();
	if ( ! $doc ) {
		return;
	}
	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$container->appendChild( leadwerk_theme_build_acm_news_article_element( $doc, $post, 'grid' ) );
	}
}

function leadwerk_theme_bind_exact_acm_news_archive( $xpath, $section, $value ) {
	leadwerk_theme_dom_set_text(
		leadwerk_theme_dom_first( $xpath, './/p[contains(@class,"section-label")][1]', $section ),
		(string) ( $value['label'] ?? '' )
	);
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$intro_node = leadwerk_theme_dom_first( $xpath, './/h2[1]/following-sibling::p[contains(@class,"text-stone-500")][1]', $section );
	if ( $intro_node instanceof DOMElement ) {
		leadwerk_theme_dom_set_inner_html( $intro_node, (string) ( $value['intro'] ?? '' ) );
	}
	$load_btn = leadwerk_theme_dom_first( $xpath, './/button[@id="news-archive-load-more-btn"] | .//button[contains(@class,"btn-outline")][1]', $section );
	if ( $load_btn instanceof DOMElement ) {
		$load_label = leadwerk_theme_acm_news_normalize_visible_text( (string) ( $value['load_more_text'] ?? '' ) );
		if ( '' === trim( $load_label ) ) {
			$load_label = 'Weitere Archivbeiträge laden';
		}
		leadwerk_theme_dom_set_text( $load_btn, $load_label );
	}

	$container = leadwerk_theme_dom_first( $xpath, './/*[@id="news-archive-articles"]', $section );
	if ( ! $container instanceof DOMElement ) {
		return;
	}
	leadwerk_theme_acm_news_clear_article_children( $xpath, $container );
	$doc = $container->ownerDocument;
	if ( ! $doc ) {
		return;
	}
	$posts          = leadwerk_theme_get_acm_news_listing_posts();
	$initial_visible = max( 1, (int) ( $value['posts_per_page'] ?? 6 ) );
	foreach ( $posts as $idx => $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$article = leadwerk_theme_build_acm_news_article_element( $doc, $post, 'archive' );
		if ( $idx >= $initial_visible ) {
			leadwerk_theme_dom_toggle_class( $article, 'hidden', true );
			leadwerk_theme_dom_toggle_class( $article, 'archive-load-more-item', true );
		}
		$container->appendChild( $article );
	}

	$wrap = leadwerk_theme_dom_first( $xpath, './/*[@id="news-archive-load-more-wrap"]', $section );
	if ( $wrap instanceof DOMElement ) {
		$needs_more = count( $posts ) > $initial_visible;
		leadwerk_theme_dom_toggle_class( $wrap, 'hidden', ! $needs_more );
	}
}

/* ══════════════════════════════════════════════════════════════
 * 10. LEGACY FINORA LAYOUT BINDERS (backward compat)
 * ══════════════════════════════════════════════════════════════ */

function leadwerk_theme_bind_exact_hero( $xpath, $section, $value, $has_button = true ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h1[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"hero-subtitle")][1]', $section ), (string) ( $value['subtitle'] ?? '' ) );
	leadwerk_theme_bind_exact_image( $xpath, $section, './/*[contains(@class,"hero-img-main")]//img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	if ( $has_button ) {
		leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_hero_slider( $xpath, $section, $value ) {
	$slides = isset( $value['slides'] ) && is_array( $value['slides'] ) ? array_values( $value['slides'] ) : array();
	$nodes  = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"hero-slide")]', $section ), count( $slides ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $slides[ $idx ] ?? array();
		if ( ! $node instanceof DOMElement || ! is_array( $item ) ) continue;
		leadwerk_theme_dom_toggle_class( $node, 'is-active', 0 === $idx );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h1[1]', $node ), (string) ( $item['title'] ?? '' ), 'heading' );
		leadwerk_theme_bind_exact_button( $xpath, $node, './/a[contains(@class,"btn")][1]', (string) ( $item['cta_label'] ?? '' ), (string) ( $item['cta_page_key'] ?? '' ), (string) ( $item['cta_url'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_pillars( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"pillar-card")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_bind_exact_image( $xpath, $node, './/img[1]', (int) ( $item['icon'] ?? 0 ), (string) ( $item['icon_alt'] ?? '' ) );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h3[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"card-desc")][1]', $node ), (string) ( $item['description'] ?? '' ), 'paragraph' );
	}
}

function leadwerk_theme_bind_exact_why_acm( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"subtitle")][1]', $section ), (string) ( $value['subtitle'] ?? '' ) );
	leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"about-text")][1]', $section ), (string) ( $value['body'] ?? '' ) );
	$items = isset( $value['blurbs'] ) && is_array( $value['blurbs'] ) ? array_values( $value['blurbs'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"blurb")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"blurb-content")][1]', $node ), leadwerk_theme_get_exact_blurb_html( is_array( $item ) ? $item : array() ) );
	}
	leadwerk_theme_bind_exact_image( $xpath, $section, './/*[contains(@class,"why-acm-right") or contains(@class,"why-finora-right")]//img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn-section")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_how_it_works( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$steps = isset( $value['steps'] ) && is_array( $value['steps'] ) ? array_values( $value['steps'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"how-step")]', $section ), count( $steps ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $steps[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/h4[1]', $node ), (string) ( $item['title'] ?? '' ) );
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/p[1]', $node ), (string) ( $item['content'] ?? '' ), 'paragraph' );
	}
}

function leadwerk_theme_bind_exact_testimonials( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"testimonial-card")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"testimonial-text")][1]', $node ), (string) ( $item['quote'] ?? '' ), 'paragraph' );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"testimonial-name")][1]', $node ), (string) ( $item['name'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_faq( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	$items = isset( $value['items'] ) && is_array( $value['items'] ) ? array_values( $value['items'] ) : array();
	$nodes = leadwerk_theme_dom_ensure_count( leadwerk_theme_dom_query( $xpath, './/*[contains(@class,"accordion-item")]', $section ), count( $items ) );
	foreach ( $nodes as $idx => $node ) {
		$item = $items[ $idx ] ?? array();
		if ( ! is_array( $item ) ) continue;
		leadwerk_theme_dom_toggle_class( $node, 'is-open', 0 === $idx );
		leadwerk_theme_dom_set_text( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"accordion-title")][1]', $node ), (string) ( $item['question'] ?? '' ) );
		leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"accordion-content")][1]', $node ), (string) ( $item['answer'] ?? '' ) );
	}
}

function leadwerk_theme_bind_exact_banner_cta( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/p[1]', $section ), (string) ( $value['body'] ?? '' ) );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_media_text( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_dom_set_inner_html( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"col-text")]//p[1] | .//p[1]', $section ), (string) ( $value['body'] ?? '' ) );
	leadwerk_theme_bind_exact_image( $xpath, $section, './/*[contains(@class,"col-img")]//img[1]', (int) ( $value['image'] ?? 0 ), (string) ( $value['image_alt'] ?? '' ) );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_center_cta( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), leadwerk_theme_force_strong_heading_markup( (string) ( $value['title'] ?? '' ) ), 'heading' );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/p[1]', $section ), (string) ( $value['body'] ?? '' ), 'paragraph' );
	leadwerk_theme_bind_exact_button( $xpath, $section, './/a[contains(@class,"btn")][1]', (string) ( $value['cta_label'] ?? '' ), (string) ( $value['cta_page_key'] ?? '' ), (string) ( $value['cta_url'] ?? '' ) );
}

function leadwerk_theme_bind_exact_contact_main( $xpath, $section, $value ) {
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/h2[1]', $section ), (string) ( $value['title'] ?? '' ), 'heading' );
	leadwerk_theme_set_placeholder_markup( leadwerk_theme_dom_first( $xpath, './/*[contains(@class,"kontakt-form-intro")][1]', $section ), (string) ( $value['intro'] ?? '' ), 'paragraph' );
}
