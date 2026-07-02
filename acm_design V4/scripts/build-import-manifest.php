<?php
/**
 * Erzeugt leadwerk_importer/manifest/import-manifest.json aus mapping.json.
 *
 * Kanonische Import-Steuerung fuer Leadwerk_Importer ist ausschliesslich
 * leadwerk_importer/manifest/mapping.json. Dieses Skript haelt import-manifest.json
 * als abgeleitetes Hilfs-Artefakt synchron (z. B. externe Tools), ohne zweite
 * source_key-/Seitenliste zu pflegen.
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

$root         = dirname( __DIR__ );
$mapping_path = $root . '/leadwerk_importer/manifest/mapping.json';

if ( ! is_file( $mapping_path ) ) {
	fwrite( STDERR, "Fehlt: {$mapping_path}\n" );
	exit( 1 );
}

$raw = file_get_contents( $mapping_path );
$data = json_decode( (string) $raw, true );
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Ungueltiges JSON: {$mapping_path}\n" );
	exit( 1 );
}

$items = array();

foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
	$slug        = (string) ( $page['slug'] ?? '' );
	$source_file = (string) ( $page['source_file'] ?? '' );
	$source_key  = (string) ( $page['source_key'] ?? '' );
	$field_name  = (string) ( $page['field_name'] ?? '' );
	$title       = (string) ( $page['title'] ?? '' );
	$status      = (string) ( $page['post_status'] ?? 'publish' );

	if ( '' === $slug || '' === $source_file || '' === $source_key ) {
		continue;
	}

	$items[] = array(
		'type'         => 'page',
		'import_key'   => 'de:page:' . $slug,
		'source'       => $source_file,
		'slug'         => $slug,
		'source_key'   => $source_key,
		'field_name'   => $field_name,
		'title'        => $title,
		'status'       => $status,
		'update_mode'  => 'replace',
		'is_front_page'=> ! empty( $page['is_front_page'] ),
	);
}

foreach ( (array) ( $data['news_articles'] ?? array() ) as $article ) {
	$source_file = (string) ( $article['source_file'] ?? '' );
	if ( '' === $source_file || ! preg_match( '/\.html$/i', $source_file ) ) {
		continue;
	}
	$base = basename( $source_file, '.html' );
	$items[] = array(
		'import_key'   => 'de:post:news:' . $base,
		'source'       => str_replace( '\\', '/', $source_file ),
		'type'         => 'post',
		'post_type'    => (string) ( $article['target_type'] ?? 'acm_news' ),
		'slug'         => $base,
		'title'        => '',
		'status'       => 'publish',
		'update_mode'  => 'replace',
	);
}

$out = array(
	'version'             => 2,
	'derived_from'        => 'leadwerk_importer/manifest/mapping.json',
	'documentation'       => 'Nicht zur Laufzeit von Leadwerk_Importer gelesen. Kanonische Steuerdatei: leadwerk_importer/manifest/mapping.json.',
	'mapping_version'     => isset( $data['version'] ) ? $data['version'] : '',
	'site_title'          => (string) ( $data['site_title'] ?? '' ),
	'site_tagline'        => (string) ( $data['site_tagline'] ?? '' ),
	'site_locale_default' => 'de',
	'items'               => $items,
);

$dest = $root . '/leadwerk_importer/manifest/import-manifest.json';
if ( ! is_dir( dirname( $dest ) ) ) {
	mkdir( dirname( $dest ), 0775, true );
}
file_put_contents( $dest, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "Wrote {$dest} (" . count( $items ) . " items)\n";
