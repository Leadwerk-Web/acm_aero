<?php
/**
 * Structured data (Schema.org JSON-LD) for ACM AIR CHARTER.
 *
 * Extends Yoast SEO schema graph with Organization, LocalBusiness and Service
 * markup. Falls back to standalone JSON-LD when Yoast is inactive.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical site URL for schema @id values.
 *
 * @return string
 */
function leadwerk_theme_schema_site_url() {
	return trailingslashit( home_url( '/' ) );
}

/**
 * Organization @id used across graph pieces.
 *
 * @return string
 */
function leadwerk_theme_schema_organization_id() {
	return leadwerk_theme_schema_site_url() . '#organization';
}

/**
 * LocalBusiness @id used across graph pieces.
 *
 * @return string
 */
function leadwerk_theme_schema_local_business_id() {
	return leadwerk_theme_schema_site_url() . '#localbusiness';
}

/**
 * Parse a multiline address string into PostalAddress parts.
 *
 * @param string $raw Raw address text.
 * @return array{streetAddress:string,postalCode:string,addressLocality:string,addressCountry:string}
 */
function leadwerk_theme_schema_parse_address( $raw ) {
	$raw    = trim( (string) $raw );
	$lines  = preg_split( '/\r\n|\r|\n/', $raw );
	$lines  = is_array( $lines ) ? array_values( array_filter( array_map( 'trim', $lines ) ) ) : array();
	$street = $lines[0] ?? 'Montreal Ave. D415';
	$city   = $lines[1] ?? '77836 Rheinmünster';
	$zip    = '';
	$local  = $city;

	if ( preg_match( '/^(\d{4,5})\s+(.+)$/', $city, $matches ) ) {
		$zip   = $matches[1];
		$local = $matches[2];
	}

	return array(
		'streetAddress'   => $street,
		'postalCode'      => $zip,
		'addressLocality' => $local,
		'addressCountry'  => 'DE',
	);
}

/**
 * Collect social profile URLs for sameAs.
 *
 * @return string[]
 */
function leadwerk_theme_schema_same_as_urls() {
	$urls = array();
	if ( ! function_exists( 'leadwerk_theme_get_option_value' ) ) {
		return $urls;
	}

	foreach ( array( 'footer_social_linkedin_url', 'footer_social_instagram_url' ) as $field ) {
		$url = trim( (string) leadwerk_theme_get_option_value( $field, '' ) );
		if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Build shared company facts for schema nodes.
 *
 * @return array<string,mixed>
 */
function leadwerk_theme_get_company_schema_facts() {
	$site_url = leadwerk_theme_schema_site_url();
	$address  = leadwerk_theme_schema_parse_address(
		function_exists( 'leadwerk_theme_get_option_value' )
			? leadwerk_theme_get_option_value( 'company_address', "Montreal Ave. D415\n77836 Rheinmünster" )
			: "Montreal Ave. D415\n77836 Rheinmünster"
	);
	$phone = function_exists( 'leadwerk_theme_get_option_value' )
		? trim( (string) leadwerk_theme_get_option_value( 'company_phone', '+49 7229 30405-0' ) )
		: '+49 7229 30405-0';
	$email = function_exists( 'leadwerk_theme_get_option_value' )
		? trim( (string) leadwerk_theme_get_option_value( 'company_email', 'info@acm.aero' ) )
		: 'info@acm.aero';
	$logo = function_exists( 'leadwerk_theme_get_option_image_url' )
		? leadwerk_theme_get_option_image_url( 'header_logo', 'assets/images/Logo-final-weiss-rz_svg.svg' )
		: '';
	$image = function_exists( 'leadwerk_theme_get_og_image_url' )
		? leadwerk_theme_get_og_image_url()
		: ( function_exists( 'leadwerk_theme_static_share_image_url' )
			? leadwerk_theme_static_share_image_url( leadwerk_theme_get_default_og_share_image_path() )
			: '' );

	return array(
		'site_url'      => $site_url,
		'legal_name'    => 'ACM AIR CHARTER Luftfahrtgesellschaft mbH',
		'brand_name'    => 'ACM AIR CHARTER',
		'description'   => 'Premium Business Aviation: Charter, Aircraft Management, Maintenance & CAMO aus einer Hand.',
		'phone'         => $phone,
		'email'         => $email,
		'address'       => $address,
		'logo_url'      => $logo,
		'image_url'     => $image,
		'same_as'       => leadwerk_theme_schema_same_as_urls(),
		'maps_url'      => function_exists( 'leadwerk_theme_get_option_value' )
			? trim( (string) leadwerk_theme_get_option_value( 'google_maps_url', '' ) )
			: '',
	);
}

/**
 * Organization schema node.
 *
 * @return array<string,mixed>
 */
function leadwerk_theme_build_organization_schema() {
	$facts = leadwerk_theme_get_company_schema_facts();
	$node  = array(
		'@type'        => 'Organization',
		'@id'          => leadwerk_theme_schema_organization_id(),
		'name'         => $facts['legal_name'],
		'alternateName'=> $facts['brand_name'],
		'url'          => $facts['site_url'],
		'description'  => $facts['description'],
	);

	if ( '' !== $facts['logo_url'] ) {
		$node['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $facts['logo_url'],
		);
	}

	if ( ! empty( $facts['same_as'] ) ) {
		$node['sameAs'] = $facts['same_as'];
	}

	$node['contactPoint'] = array(
		'@type'             => 'ContactPoint',
		'telephone'         => $facts['phone'],
		'email'             => $facts['email'],
		'contactType'       => 'customer service',
		'areaServed'        => array( 'DE', 'EU', 'Worldwide' ),
		'availableLanguage' => array( 'German', 'English' ),
	);

	return $node;
}

/**
 * LocalBusiness schema node.
 *
 * @return array<string,mixed>
 */
function leadwerk_theme_build_local_business_schema() {
	$facts = leadwerk_theme_get_company_schema_facts();
	$node  = array(
		'@type'              => array( 'LocalBusiness', 'ProfessionalService' ),
		'@id'                => leadwerk_theme_schema_local_business_id(),
		'name'               => $facts['brand_name'],
		'url'                => $facts['site_url'],
		'telephone'          => $facts['phone'],
		'email'              => $facts['email'],
		'description'        => $facts['description'],
		'parentOrganization' => array( '@id' => leadwerk_theme_schema_organization_id() ),
		'address'            => array_merge(
			array( '@type' => 'PostalAddress' ),
			$facts['address']
		),
	);

	if ( '' !== $facts['image_url'] ) {
		$node['image'] = $facts['image_url'];
	}

	if ( ! empty( $facts['same_as'] ) ) {
		$node['sameAs'] = $facts['same_as'];
	}

	return $node;
}

/**
 * Service schema definitions keyed by Leadwerk source key.
 *
 * @return array<string,array<string,string>>
 */
function leadwerk_theme_get_service_schema_map() {
	return array(
		'acm-charter-v1'     => array(
			'name'        => 'Private Jet Charter',
			'serviceType' => 'Business Aviation Charter',
		),
		'acm-global-7500-v1' => array(
			'name'        => 'Bombardier Global 7500 Charter',
			'serviceType' => 'Long Range Business Jet Charter',
		),
		'acm-global-6000-v1' => array(
			'name'        => 'Bombardier Global 6000 Charter',
			'serviceType' => 'Long Range Business Jet Charter',
		),
		'acm-global-xrs-v1'  => array(
			'name'        => 'Bombardier Global Express XRS Charter',
			'serviceType' => 'Long Range Business Jet Charter',
		),
		'acm-aircraft-v1'    => array(
			'name'        => 'Aircraft Management',
			'serviceType' => 'Business Aviation Aircraft Management',
		),
		'acm-maintenance-v1' => array(
			'name'        => 'Aircraft Maintenance & CAMO',
			'serviceType' => 'Part-145 Maintenance and CAMO Services',
		),
	);
}

/**
 * Build a Service schema node for the current page when applicable.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>|null
 */
function leadwerk_theme_build_service_schema_for_post( $post_id ) {
	$post_id    = (int) $post_id;
	$source_key = $post_id > 0 ? (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) : '';
	$map        = leadwerk_theme_get_service_schema_map();

	if ( ! isset( $map[ $source_key ] ) ) {
		return null;
	}

	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return null;
	}

	$service = $map[ $source_key ];
	$facts   = leadwerk_theme_get_company_schema_facts();

	return array(
		'@type'       => 'Service',
		'@id'         => trailingslashit( $permalink ) . '#service',
		'name'        => $service['name'],
		'serviceType' => $service['serviceType'],
		'provider'    => array( '@id' => leadwerk_theme_schema_organization_id() ),
		'areaServed'  => 'Worldwide',
		'url'         => $permalink,
		'description' => $facts['description'],
	);
}

/**
 * Whether a graph already contains a schema type.
 *
 * @param array<int,mixed> $graph Schema graph.
 * @param string           $type  Schema type.
 * @return bool
 */
function leadwerk_theme_schema_graph_has_type( $graph, $type ) {
	foreach ( (array) $graph as $piece ) {
		if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
			continue;
		}
		$types = (array) $piece['@type'];
		if ( in_array( $type, $types, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Link Yoast WebSite / WebPage nodes to the Organization publisher.
 *
 * @param array<int,mixed> $graph Schema graph.
 * @return array<int,mixed>
 */
function leadwerk_theme_schema_link_publisher( $graph ) {
	$org_id = leadwerk_theme_schema_organization_id();

	foreach ( $graph as $index => $piece ) {
		if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
			continue;
		}

		$types = (array) $piece['@type'];
		if ( in_array( 'WebSite', $types, true ) || in_array( 'WebPage', $types, true ) ) {
			$graph[ $index ]['publisher'] = array( '@id' => $org_id );
		}
	}

	return $graph;
}

/**
 * Extend Yoast schema graph with ACM organization and service data.
 *
 * @param array<int,mixed>  $graph   Existing graph pieces.
 * @param Meta_Tags_Context $context Yoast context object.
 * @return array<int,mixed>
 */
function leadwerk_theme_extend_yoast_schema_graph( $graph, $context ) {
	unset( $context );

	if ( ! leadwerk_theme_schema_graph_has_type( $graph, 'Organization' ) ) {
		$graph[] = leadwerk_theme_build_organization_schema();
	}

	if ( ! leadwerk_theme_schema_graph_has_type( $graph, 'LocalBusiness' ) ) {
		$graph[] = leadwerk_theme_build_local_business_schema();
	}

	$post_id = (int) get_queried_object_id();
	$service = leadwerk_theme_build_service_schema_for_post( $post_id );
	if ( is_array( $service ) && ! leadwerk_theme_schema_graph_has_type( $graph, 'Service' ) ) {
		$graph[] = $service;
	}

	return leadwerk_theme_schema_link_publisher( $graph );
}
add_filter( 'wpseo_schema_graph', 'leadwerk_theme_extend_yoast_schema_graph', 20, 2 );

/**
 * Treat ACM news posts as NewsArticle in Yoast schema output.
 *
 * @param string|string[] $type Article schema type(s).
 * @return string|string[]
 */
function leadwerk_theme_yoast_acm_news_article_type( $type ) {
	if ( is_singular( 'acm_news' ) ) {
		return 'NewsArticle';
	}

	return $type;
}
add_filter( 'wpseo_schema_article_type', 'leadwerk_theme_yoast_acm_news_article_type' );

/**
 * Output standalone JSON-LD when Yoast SEO is not active.
 *
 * @return void
 */
function leadwerk_theme_head_structured_data_fallback() {
	if ( defined( 'WPSEO_VERSION' ) || is_admin() ) {
		return;
	}

	$graph = array(
		leadwerk_theme_build_organization_schema(),
		leadwerk_theme_build_local_business_schema(),
	);

	$post_id = (int) get_queried_object_id();
	$service = leadwerk_theme_build_service_schema_for_post( $post_id );
	if ( is_array( $service ) ) {
		$graph[] = $service;
	}

	if ( is_singular() ) {
		$graph[] = array(
			'@type'       => is_singular( 'acm_news' ) ? 'NewsArticle' : 'WebPage',
			'@id'         => get_permalink( $post_id ) . '#webpage',
			'url'         => get_permalink( $post_id ),
			'name'        => wp_strip_all_tags( get_the_title( $post_id ) ),
			'isPartOf'    => array( '@id' => leadwerk_theme_schema_site_url() . '#website' ),
			'publisher'   => array( '@id' => leadwerk_theme_schema_organization_id() ),
			'inLanguage'  => 0 === strpos( (string) determine_locale(), 'en' ) ? 'en' : 'de',
		);
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'leadwerk_theme_head_structured_data_fallback', 7 );
