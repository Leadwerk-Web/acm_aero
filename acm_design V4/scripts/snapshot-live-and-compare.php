<?php
/**
 * Lädt alle in mapping.json gelisteten DE-Seiten von einer Live-Basis-URL,
 * speichert Roh-HTML unter _snapshots/myrdbx/html/ und vergleicht
 * //body/main/section mit den Root-HTML-Dateien (Importer-kompatibel).
 *
 * Usage (Repo-Root = acm_design V4):
 *   php scripts/snapshot-live-and-compare.php
 *   php scripts/snapshot-live-and-compare.php --base-url="https://b4451i.myrdbx.io"
 *   php scripts/snapshot-live-and-compare.php --fetch-only
 *   php scripts/snapshot-live-and-compare.php --compare-only
 *
 * Hinweis: PHP ohne openssl/curl (typisch manche Windows-Builds) kann HTTPS nicht per
 * file_get_contents laden. Dann zuerst:
 *   powershell -File scripts/snapshot-live-fetch.ps1
 * und anschliessend: php scripts/snapshot-live-and-compare.php --compare-only
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

$root = dirname( __DIR__ );
$mapping_path = $root . '/leadwerk_importer/manifest/mapping.json';
$snapshot_dir = $root . '/_snapshots/myrdbx/html';
$report_path  = $root . '/_snapshots/myrdbx/compare-report.txt';

$opts = getopt( '', array( 'base-url::', 'fetch-only', 'compare-only' ) );
$base_url = isset( $opts['base-url'] ) ? rtrim( (string) $opts['base-url'], "/ \t\n\r\0\x0B" ) : 'https://b4451i.myrdbx.io';
$fetch_only   = isset( $opts['fetch-only'] );
$compare_only = isset( $opts['compare-only'] );

if ( ! is_file( $mapping_path ) ) {
	fwrite( STDERR, "Fehlt: {$mapping_path}\n" );
	exit( 1 );
}

$raw = file_get_contents( $mapping_path );
$data = json_decode( (string) $raw, true );
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Ungültiges JSON in mapping.json\n" );
	exit( 1 );
}

if ( ! $compare_only ) {
	if ( ! is_dir( $snapshot_dir ) && ! mkdir( $snapshot_dir, 0775, true ) && ! is_dir( $snapshot_dir ) ) {
		fwrite( STDERR, "Verzeichnis nicht anlegbar: {$snapshot_dir}\n" );
		exit( 1 );
	}
}

/**
 * @return array{0:?DOMXPath,1:string} xpath or null, error
 */
function leadwerk_snapshot_dom( $html ) {
	if ( '' === trim( $html ) ) {
		return array( null, 'empty_html' );
	}
	$dom = new DOMDocument( '1.0', 'UTF-8' );
	libxml_use_internal_errors( true );
	$ok = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	if ( ! $ok ) {
		return array( null, 'loadHTML_failed' );
	}
	return array( new DOMXPath( $dom ), '' );
}

/**
 * @return array<int,string> normalized text per section
 */
function leadwerk_extract_main_section_texts( DOMXPath $xpath ) {
	$sections = array();
	// Gleiche Prioritaet wie Leadwerk_ACF_Filler::extract_body_sections().
	$nodes = $xpath->query( '//body/main/section' );
	if ( ! $nodes instanceof DOMNodeList || $nodes->length === 0 ) {
		// WordPress-Frontend rendert oft ohne <main>: direkte body-Kinder <section>
		// (nicht //body/section, um verschachtelte Sektionen auszuschliessen).
		$nodes = $xpath->query( '/html/body/section' );
	}
	if ( ! $nodes instanceof DOMNodeList || $nodes->length === 0 ) {
		$nodes = $xpath->query( '//body/section' );
	}
	if ( ! $nodes instanceof DOMNodeList ) {
		return array();
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$text = $node->textContent;
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$sections[] = trim( (string) $text );
	}
	return $sections;
}

/**
 * @return string
 */
function leadwerk_http_get( $url ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		if ( false === $ch ) {
			return '';
		}
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_USERAGENT      => 'ACM-Leadwerk-Snapshot/1.0',
				CURLOPT_HTTPHEADER     => array( 'Accept: text/html' ),
			)
		);
		$html = curl_exec( $ch );
		curl_close( $ch );
		return false === $html ? '' : (string) $html;
	}
	$ctx = stream_context_create(
		array(
			'http'  => array(
				'timeout'         => 60,
				'follow_location' => 1,
				'max_redirects'   => 5,
				'header'          => "User-Agent: ACM-Leadwerk-Snapshot/1.0\r\nAccept: text/html\r\n",
			),
			'ssl'   => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
			),
		)
	);
	$html = @file_get_contents( $url, false, $ctx );
	return false === $html ? '' : (string) $html;
}

/**
 * @param array<string,mixed> $page
 */
function leadwerk_page_live_url( $base_url, $page ) {
	if ( ! empty( $page['is_front_page'] ) ) {
		return $base_url . '/';
	}
	$slug = (string) ( $page['slug'] ?? '' );
	if ( '' === $slug ) {
		return '';
	}
	return $base_url . '/' . rawurlencode( $slug ) . '/';
}

/**
 * @param string $source_file e.g. news/foo.html
 */
function leadwerk_news_live_url( $base_url, $source_file ) {
	$base = basename( $source_file, '.html' );
	if ( '' === $base ) {
		return '';
	}
	return $base_url . '/news/' . rawurlencode( $base ) . '/';
}

$targets = array();

foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
	$rel = (string) ( $page['source_file'] ?? '' );
	if ( '' === $rel ) {
		continue;
	}
	$targets[] = array(
		'rel' => str_replace( '\\', '/', $rel ),
		'url' => leadwerk_page_live_url( $base_url, $page ),
	);
}

foreach ( (array) ( $data['news_articles'] ?? array() ) as $article ) {
	$rel = (string) ( $article['source_file'] ?? '' );
	if ( '' === $rel || ! preg_match( '/\.html$/i', $rel ) ) {
		continue;
	}
	$targets[] = array(
		'rel' => str_replace( '\\', '/', $rel ),
		'url' => leadwerk_news_live_url( $base_url, $rel ),
	);
}

$fetch_log = array();
$compare_lines = array();

if ( ! $compare_only ) {
	foreach ( $targets as $t ) {
		$rel = $t['rel'];
		$url = $t['url'];
		if ( '' === $url ) {
			$fetch_log[] = "SKIP no URL: {$rel}";
			continue;
		}
		$html = leadwerk_http_get( $url );
		$dest = $snapshot_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) && ! mkdir( $dest_dir, 0775, true ) && ! is_dir( $dest_dir ) ) {
			fwrite( STDERR, "Verzeichnis nicht anlegbar: {$dest_dir}\n" );
			exit( 1 );
		}
		if ( '' === $html ) {
			$fetch_log[] = "FAIL fetch empty: {$rel} <= {$url}";
			file_put_contents( $dest, "<!-- fetch failed: {$url} -->\n" );
			continue;
		}
		file_put_contents( $dest, $html );
		$fetch_log[] = "OK {$rel} <= {$url} (" . strlen( $html ) . " bytes)";
	}
	file_put_contents( $root . '/_snapshots/myrdbx/fetch-log.txt', implode( "\n", $fetch_log ) . "\n" );
	echo "Fetch: " . count( $fetch_log ) . " Ziele, Log: _snapshots/myrdbx/fetch-log.txt\n";
}

if ( $fetch_only ) {
	exit( 0 );
}

$compare_lines[] = 'Vergleich main/section-Text (normalisiert) — Live-Snapshot vs. Projektroot';
$compare_lines[] = 'Basis-URL: ' . $base_url;
$compare_lines[] = str_repeat( '-', 72 );

$mismatch = 0;
$missing_local = 0;
$missing_live_parse = 0;

foreach ( $targets as $t ) {
	$rel = $t['rel'];
	$live_path = $snapshot_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
	$local_path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );

	if ( ! is_file( $local_path ) ) {
		$compare_lines[] = "[NO_LOCAL] {$rel}";
		++$missing_local;
		continue;
	}

	$live_html = is_file( $live_path ) ? (string) file_get_contents( $live_path ) : '';
	$local_html = (string) file_get_contents( $local_path );

	list( $lx, $err ) = leadwerk_snapshot_dom( $live_html );
	list( $cx, $cerr ) = leadwerk_snapshot_dom( $local_html );

	if ( null === $lx ) {
		$compare_lines[] = "[LIVE_PARSE] {$rel} ({$err})";
		++$missing_live_parse;
		continue;
	}
	if ( null === $cx ) {
		$compare_lines[] = "[LOCAL_PARSE] {$rel} ({$cerr})";
		++$mismatch;
		continue;
	}

	$ls = leadwerk_extract_main_section_texts( $lx );
	$cs = leadwerk_extract_main_section_texts( $cx );

	$lc = count( $ls );
	$cc = count( $cs );

	if ( $lc !== $cc ) {
		$compare_lines[] = "[SECTION_COUNT] {$rel} live={$lc} local={$cc}";
		++$mismatch;
	}

	$max = max( $lc, $cc );
	for ( $i = 0; $i < $max; $i++ ) {
		$lt = $ls[ $i ] ?? '';
		$ct = $cs[ $i ] ?? '';
		if ( $lt !== $ct ) {
			$compare_lines[] = "[SECTION_DIFF] {$rel} index={$i} live_len=" . strlen( $lt ) . ' local_len=' . strlen( $ct );
			++$mismatch;
		}
	}

	if ( $lc === $cc ) {
		$same = true;
		for ( $i = 0; $i < $lc; $i++ ) {
			if ( ( $ls[ $i ] ?? '' ) !== ( $cs[ $i ] ?? '' ) ) {
				$same = false;
				break;
			}
		}
		if ( $same ) {
			$compare_lines[] = "[OK] {$rel} sections={$lc}";
		}
	}
}

$compare_lines[] = str_repeat( '-', 72 );
$compare_lines[] = 'Zusammenfassung: mismatch_events=' . $mismatch . ' missing_local=' . $missing_local . ' live_parse_fail=' . $missing_live_parse;

file_put_contents( $report_path, implode( "\n", $compare_lines ) . "\n" );
echo "Report: {$report_path}\n";
exit( 0 );
