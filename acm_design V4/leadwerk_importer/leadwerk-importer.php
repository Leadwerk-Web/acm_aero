<?php
/**
 * Plugin Name: Leadwerk Importer
 * Description: Import ACM AIR CHARTER static content into WordPress pages, media, fields and DE/EN translation structure.
 * Version: 1.0.0
 * Author: Leadwerk
 * Text Domain: leadwerk-importer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: leadwerk-fields, leadwerk-wpml-clone
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_IMPORTER_VERSION', '1.0.0' );
define( 'LEADWERK_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEADWERK_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'LEADWERK_IMPORTER_OPTION_POST_STEPS_NOTICE', 'leadwerk_import_needs_post_steps' );

require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-news-cpt.php';

/**
 * Resolve Leadwerk Fields bootstrap file (canonical: leadwerk-fields/, legacy: leadwerk_fields/).
 *
 * @return string Absolute path or empty.
 */
function leadwerk_importer_resolve_fields_main_file() {
	$parent = dirname( LEADWERK_IMPORTER_PATH );
	foreach ( array( $parent . '/leadwerk-fields/leadwerk-fields.php', $parent . '/leadwerk_fields/leadwerk-fields.php' ) as $path ) {
		if ( is_file( $path ) ) {
			return $path;
		}
	}
	return '';
}

/**
 * Resolve shared content schema path for early load when the Fields plugin is not active yet.
 *
 * @return string Absolute path or empty.
 */
function leadwerk_importer_resolve_schema_file() {
	$parent = dirname( LEADWERK_IMPORTER_PATH );
	foreach (
		array(
			$parent . '/leadwerk-fields/includes/class-leadwerk-content-schema.php',
			$parent . '/leadwerk_fields/includes/class-leadwerk-content-schema.php',
		) as $path
	) {
		if ( is_file( $path ) ) {
			return $path;
		}
	}
	return '';
}

/**
 * Resolve WPML-clone plugin directory (canonical: leadwerk-wpml-clone/, legacy: leadwerk_wpml_clone/).
 *
 * @return string Absolute path or empty.
 */
function leadwerk_importer_resolve_wpml_clone_path() {
	$parent = dirname( LEADWERK_IMPORTER_PATH );
	foreach ( array( $parent . '/leadwerk-wpml-clone', $parent . '/leadwerk_wpml_clone' ) as $path ) {
		if ( is_dir( $path ) ) {
			return $path;
		}
	}
	return '';
}

$leadwerk_schema_file = leadwerk_importer_resolve_schema_file();
if ( ! class_exists( 'Leadwerk_Content_Schema' ) && '' !== $leadwerk_schema_file ) {
	require_once $leadwerk_schema_file;
}

$translation_plugin_path = leadwerk_importer_resolve_wpml_clone_path();
if ( ! class_exists( 'Leadwerk_Translation_API' ) && '' !== $translation_plugin_path ) {
	require_once $translation_plugin_path . '/includes/class-leadwerk-translation-api.php';
	require_once $translation_plugin_path . '/includes/class-leadwerk-html-segments.php';
	require_once $translation_plugin_path . '/includes/class-leadwerk-translation-sync.php';
}

require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-importer.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-media-importer.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-logger.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-acf-filler.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-orphan-media-admin.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-webp-body-tool.php';
require_once LEADWERK_IMPORTER_PATH . 'includes/class-leadwerk-auto-webp-upload.php';

Leadwerk_Orphan_Media_Admin::register_menu();
Leadwerk_Webp_Body_Tool::register();
Leadwerk_Auto_Webp_On_Upload::register();

/**
 * Admin menu entry.
 *
 * @return void
 */
function leadwerk_importer_menu() {
	add_management_page(
		__( 'Leadwerk Import', 'leadwerk-importer' ),
		__( 'Leadwerk Import', 'leadwerk-importer' ),
		'manage_options',
		'leadwerk-import',
		'leadwerk_importer_admin_page'
	);
}
add_action( 'admin_menu', 'leadwerk_importer_menu' );

/**
 * Warn if sibling plugins are missing or ACF conflicts with Leadwerk Fields.
 *
 * @return void
 */
function leadwerk_importer_admin_dependency_notices() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$plugins_parent = dirname( LEADWERK_IMPORTER_PATH );
	$fields_main    = leadwerk_importer_resolve_fields_main_file();
	$wpml_main      = '';
	$wpml_base      = leadwerk_importer_resolve_wpml_clone_path();
	if ( '' !== $wpml_base ) {
		$wpml_main = $wpml_base . '/leadwerk-wpml-clone.php';
	}

	if ( '' === $fields_main ) {
		echo '<div class="notice notice-error"><p><strong>Leadwerk Importer:</strong> Erwartet Geschwister-Plugin <code>leadwerk-fields/leadwerk-fields.php</code> (empfohlen) oder Legacy <code>leadwerk_fields/</code> unter <code>' . esc_html( basename( $plugins_parent ) ) . '</code>.</p></div>';
	}

	if ( '' === $wpml_main || ! is_file( $wpml_main ) ) {
		echo '<div class="notice notice-error"><p><strong>Leadwerk Importer:</strong> Erwartet Geschwister-Plugin <code>leadwerk-wpml-clone/leadwerk-wpml-clone.php</code> (empfohlen) oder Legacy <code>leadwerk_wpml_clone/</code>.</p></div>';
	}

	if ( defined( 'ACF_VERSION' ) || defined( 'ACF_PRO' ) ) {
		echo '<div class="notice notice-error"><p><strong>Leadwerk Importer:</strong> Advanced Custom Fields ist aktiv. Fuer diesen Importstack bitte <strong>ACF deaktivieren</strong> &mdash; Speicherformat und <code>get_field</code> kollidieren mit Leadwerk Fields.</p></div>';
	}
}
add_action( 'admin_notices', 'leadwerk_importer_admin_dependency_notices' );

/**
 * Dismiss the post-import checklist notice.
 *
 * @return void
 */
function leadwerk_importer_dismiss_post_steps_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sie haben keine Berechtigung.', 'leadwerk-importer' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'leadwerk_dismiss_post_steps' );
	delete_option( LEADWERK_IMPORTER_OPTION_POST_STEPS_NOTICE );
	$redirect = wp_get_referer();
	wp_safe_redirect( $redirect ? wp_validate_redirect( $redirect, admin_url() ) : admin_url() );
	exit;
}
add_action( 'admin_post_leadwerk_dismiss_post_steps', 'leadwerk_importer_dismiss_post_steps_notice' );

/**
 * Remind admins to flush permalinks and verify reading settings after a successful import.
 *
 * @return void
 */
function leadwerk_importer_post_import_admin_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( '1' !== (string) get_option( LEADWERK_IMPORTER_OPTION_POST_STEPS_NOTICE, '' ) ) {
		return;
	}

	$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=leadwerk_dismiss_post_steps' ), 'leadwerk_dismiss_post_steps' );

	echo '<div class="notice notice-warning is-dismissible"><p><strong>Leadwerk Importer:</strong> ';
	echo esc_html__( 'Ein Live-Import wurde abgeschlossen. Bitte Permalinks speichern, die Startseite unter Lesen prüfen, die News-URL testen (/news/ = Uebersicht, /news/slug/ = Artikel) und Englisch unter /en/ stichprobenartig oeffnen. Im Import-Live-Log Warnungen zu Medien oder Flexible Layouts prüfen; EN-Felder ggf. in Leadwerk WPML Clone pflegen.', 'leadwerk-importer' );
	echo '</p><p>';
	echo '<a class="button button-primary" href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Permalinks', 'leadwerk-importer' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Lesen / Startseite', 'leadwerk-importer' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Hinweis ausblenden', 'leadwerk-importer' ) . '</a>';
	echo '</p></div>';
}
add_action( 'admin_notices', 'leadwerk_importer_post_import_admin_notice' );

/**
 * Enqueue importer admin assets.
 *
 * @param string $hook Current admin hook.
 * @return void
 */
function leadwerk_importer_admin_assets( $hook ) {
	if ( 'tools_page_leadwerk-import' !== $hook ) {
		return;
	}

	$script_path = LEADWERK_IMPORTER_PATH . 'assets/admin-import.js';
	$style_path  = LEADWERK_IMPORTER_PATH . 'assets/admin-import.css';

	if ( is_file( $style_path ) ) {
		wp_enqueue_style(
			'leadwerk-importer-admin',
			LEADWERK_IMPORTER_URL . 'assets/admin-import.css',
			array(),
			(string) filemtime( $style_path )
		);
	}

	if ( is_file( $script_path ) ) {
		wp_enqueue_script(
			'leadwerk-importer-admin',
			LEADWERK_IMPORTER_URL . 'assets/admin-import.js',
			array(),
			(string) filemtime( $script_path ),
			true
		);
	}

	wp_localize_script(
		'leadwerk-importer-admin',
		'leadwerkImporter',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'leadwerk_import_ajax' ),
			'state'   => Leadwerk_Logger::get_state(),
			'strings' => array(
				'startDryRun' => 'Dry-Run starten',
				'startImport' => 'Import starten',
				'running'     => 'Import laeuft...',
				'idle'        => 'Noch kein Import gestartet.',
				'reset'       => 'Ansicht zuruecksetzen',
				'resume'      => 'Aktiven Import fortsetzen',
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'leadwerk_importer_admin_assets' );

/**
 * Importer admin page.
 *
 * @return void
 */
function leadwerk_importer_admin_page() {
	$run      = isset( $_GET['run'] ) && '1' === $_GET['run'] && current_user_can( 'manage_options' );
	$dry_run  = isset( $_GET['dry_run'] ) && '1' === $_GET['dry_run'];
	$options_only = isset( $_GET['options_only'] ) && '1' === (string) $_GET['options_only'] && current_user_can( 'manage_options' );
	$repair_structured_de = isset( $_GET['repair_structured_de'] ) && '1' === (string) $_GET['repair_structured_de'] && current_user_can( 'manage_options' );
	$repair_source_key = isset( $_GET['repair_source_key'] ) && current_user_can( 'manage_options' )
		? sanitize_key( wp_unslash( $_GET['repair_source_key'] ) )
		: '';
	$force_canonical_source_key = isset( $_GET['force_canonical_source_key'] ) && current_user_can( 'manage_options' )
		? sanitize_key( wp_unslash( $_GET['force_canonical_source_key'] ) )
		: '';
	$state = Leadwerk_Logger::get_state();

	if ( $run && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'leadwerk_import_run' ) ) {
		$importer = new Leadwerk_Importer( ! $dry_run );
		$importer->run();
		$state = Leadwerk_Logger::get_state();
		echo '<div class="notice notice-success"><p>Synchroner Import ausgefuehrt. Fuer kuenftige Laeufe bitte die Live-Progress-Oberflaeche unten verwenden.</p></div>';
	}

	if ( $options_only && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'leadwerk_options_only' ) ) {
		$importer = new Leadwerk_Importer( true );
		$result   = $importer->run_options_only();
		$state    = Leadwerk_Logger::get_state();
		if ( 'skipped' === (string) ( $result['status'] ?? '' ) ) {
			echo '<div class="notice notice-warning"><p>Options-Import uebersprungen (Dry-Run nicht unterstuetzt fuer diesen Button).</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>Nur Optionen importiert (Site-Titel/Tagline, Leadwerk-Felder, Site-Icon wo anwendbar).</p></div>';
		}
	}

	if ( '' !== $repair_source_key && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'leadwerk_repair_page' ) ) {
		$importer     = new Leadwerk_Importer( true );
		$repair_state = $importer->repair_page_by_source_key( $repair_source_key );
		$state        = Leadwerk_Logger::get_state();

		if ( is_wp_error( $repair_state ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $repair_state->get_error_message() ) . '</p></div>';
		} elseif ( 'failed' === (string) ( $repair_state['status'] ?? '' ) ) {
			echo '<div class="notice notice-error"><p>Targeted repair fehlgeschlagen. Bitte Live Log und Summary unten pruefen.</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>Targeted repair fuer Seite <code>' . esc_html( $repair_source_key ) . '</code> wurde ausgefuehrt.</p></div>';
		}
	}

	if ( $repair_structured_de && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'leadwerk_repair_structured_de' ) ) {
		$importer      = new Leadwerk_Importer( true );
		$repair_report = $importer->repair_current_structured_content();
		$state         = Leadwerk_Logger::get_state();

		if ( is_wp_error( $repair_report ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $repair_report->get_error_message() ) . '</p></div>';
		} else {
			$summary = sprintf(
				'DE Structured Repair abgeschlossen. Gescannt: %1$d, Canonical Drift: %2$d, repariert: %3$d, unveraendert: %4$d, Snapshot: %5$d, Shell-Fallback: %6$d, Fehler: %7$d.',
				(int) ( $repair_report['scanned'] ?? 0 ),
				(int) ( $repair_report['source_drift'] ?? 0 ),
				(int) ( $repair_report['repaired'] ?? 0 ),
				(int) ( $repair_report['unchanged'] ?? 0 ),
				(int) ( $repair_report['snapshot_used'] ?? 0 ),
				(int) ( $repair_report['shell_fallback_used'] ?? 0 ),
				(int) ( $repair_report['failed'] ?? 0 )
			);
			$notice_class = ! empty( $repair_report['failed'] ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $summary ) . '</p></div>';
		}
	}

	if ( '' !== $force_canonical_source_key && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'leadwerk_force_canonical' ) ) {
		$importer   = new Leadwerk_Importer( true );
		$sync_state = $importer->force_canonical_sync_by_source_key( $force_canonical_source_key );
		$state      = Leadwerk_Logger::get_state();

		if ( is_wp_error( $sync_state ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $sync_state->get_error_message() ) . '</p></div>';
		} elseif ( 'failed' === (string) ( $sync_state['status'] ?? '' ) ) {
			echo '<div class="notice notice-error"><p>Force Canonical Sync fehlgeschlagen. Bitte Live Log und Summary unten pruefen.</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>Force Canonical Sync abgeschlossen fuer ' . esc_html( $force_canonical_source_key ) . '.</p></div>';
		}
	}

	$importer_for_tools       = new Leadwerk_Importer( false );
	$structured_page_options  = $importer_for_tools->get_structured_page_options();
	?>
	<div class="wrap leadwerk-importer-admin">
		<h1><?php esc_html_e( 'Leadwerk Import', 'leadwerk-importer' ); ?></h1>
		<p>ACM AIR CHARTER Inhalte, Medien, Field-Gruppen und DE/EN Uebersetzungsstruktur importieren. Der Live-Import laeuft schrittweise, zeigt Fortschritt an und verhindert leere Seiten so gut wie moeglich.</p>
		<details class="leadwerk-importer-checklist" style="margin:12px 0;padding:12px 14px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;max-width:920px;">
			<summary><strong>Ablauf-Checkliste (Hosting)</strong></summary>
			<ol style="margin:10px 0 0 1.2em;line-height:1.55;">
				<li>Plugins <code>leadwerk-fields</code>, <code>leadwerk-wpml-clone</code>, <code>leadwerk_importer</code> als Geschwister unter <code>wp-content/plugins/</code> (Ordner mit Bindestrich sind fuer WordPress-6.5-Plugin-Abhaengigkeiten erforderlich; Legacy-Namen <code>leadwerk_fields</code> / <code>leadwerk_wpml_clone</code> werden beim Laden weiter erkannt). Theme <code>leadwerk_theme</code> aktiv (CPT <code>acm_news</code> registriert der Importer bei Bedarf auch ohne Theme). Statische Shell-Medien: ein Kanon <code>leadwerk_importer/source_assets</code>; Theme-URLs dafuer siehe <code>leadwerk_importer/DEPLOY-ASSETS.md</code> und Filter <code>leadwerk_theme_static_source_base</code>.</li>
				<li><strong>Aktivierung empfohlen:</strong> zuerst <code>leadwerk-wpml-clone</code> (legt DB-Tabellen an), dann <code>leadwerk-fields</code>, dann <code>leadwerk_importer</code>. <strong>Kein echtes WPML</strong> parallel betreiben (URL- und Locale-Konflikte mit Leadwerk WPML Clone).</li>
				<li><strong>Advanced Custom Fields</strong> (Free/Pro) muss deaktiviert sein; sonst schlägt der Preflight fehl und kollidiert das Meta-Format mit Leadwerk Fields. Keine anderen Plugins, die eine eigene <code>get_field()</code> bereitstellen, solange Leadwerk Fields die API liefern soll.</li>
				<li>Struktur im Repo prüfen: <code>php scripts/verify-leadwerk-deployment.php</code> (Projektroot) — prüft u. a. HTML-Dateien, Drift, und ob jede <code>field_name</code> aus <code>mapping.json</code> in <code>Leadwerk_Content_Schema</code> existiert. Optional strikt: <code>--strict-drift</code>. HTML spiegeln (Kanon = <code>leadwerk_importer/source_assets</code>): <code>php scripts/sync-html-sources.php</code>. Abgeleitetes <code>import-manifest.json</code>: <code>php scripts/build-import-manifest.php</code> (Importer liest nur <code>mapping.json</code>).</li>
				<li>Datenbankbenutzer mit <code>CREATE TABLE</code>-Recht (WPML-Clone-Tabellen beim Aktivieren). Bei roter Admin-Notice zum Plugin-Boot Log und DB-Rechte prüfen.</li>
				<li>Optional: <code>WP_DEBUG_LOG</code> in <code>wp-config.php</code> bei Problemen. PHP-Extensions <code>json</code>, <code>dom</code> und <code>libxml</code> (Manifest bzw. Parser/Validierung). Bei Admin-AJAX-Timeouts oder Shared Hosting: Filter <code>leadwerk_import_batch_sizes</code>, <code>leadwerk_import_raise_php_limits</code>, <code>leadwerk_import_memory_limit</code>, <code>leadwerk_import_time_limit</code> in einem kleinen MU-Plugin oder der Theme-<code>functions.php</code> setzen.</li>
				<li>Zuerst <strong>Dry-Run</strong>, dann Live-Import; im Live-Log auf Preflight-, Medien-, Flexible-Layout- und Finalize-Meldungen achten. Bei leeren Bereichen oder Schema-Drift: „DE Structured Content reparieren“ oder Einzelseiten-Reparatur nutzen.</li>
				<li>Nach dem Import: <strong>Einstellungen &rarr; Permalinks &rarr; Speichern</strong>; <strong>Einstellungen &rarr; Lesen</strong> Startseite prüfen. URLs smoke-testen: Startseite, eine Unterseite, <code>/news/</code> = statische Übersicht, Einzelartikel <code>/news/beitrags-slug/</code> (CPT ohne separates Archiv). Englisch: <code>/en/</code> und eine Unterseite; EN-Inhalte in Leadwerk WPML Clone pflegen oder <code>leadwerk_importer/manifest/translation-seeds.json</code> befüllen.</li>
			</ol>
		</details>
		<?php if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) : ?>
			<div class="notice notice-info inline"><p><strong>Diagnose:</strong> Bei „kritischen Fehlern“ oder stillen Import-Abbruechen in <code>wp-config.php</code> <code>define( 'WP_DEBUG', true );</code> und <code>define( 'WP_DEBUG_LOG', true );</code> setzen; Details erscheinen in <code>wp-content/debug.log</code>.</p></div>
		<?php endif; ?>

		<div class="leadwerk-importer-toolbar">
			<button type="button" class="button" data-leadwerk-start-import="dry-run">Dry-Run starten</button>
			<button type="button" class="button button-primary" data-leadwerk-start-import="apply">Import mit Live-Progress starten</button>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'options_only' => '1' ), admin_url( 'tools.php?page=leadwerk-import' ) ), 'leadwerk_options_only' ) ); ?>" class="button">Nur Optionen importieren</a>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'repair_structured_de' => '1' ), admin_url( 'admin.php?page=leadwerk-import' ) ), 'leadwerk_repair_structured_de' ) ); ?>" class="button">DE Structured Content reparieren</a>
			<button type="button" class="button" data-leadwerk-reset-progress>Ansicht zuruecksetzen</button>
		</div>

		<form method="get" class="leadwerk-importer-repair-one" style="margin:16px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="leadwerk-import" />
			<label for="leadwerk-repair-source-key"><strong>Einzelne Structured Page reparieren</strong></label>
			<select id="leadwerk-repair-source-key" name="repair_source_key" required>
				<option value="">Seite waehlen</option>
				<?php foreach ( $structured_page_options as $option_source_key => $option ) : ?>
					<option value="<?php echo esc_attr( $option_source_key ); ?>" <?php selected( $repair_source_key, $option_source_key ); ?>><?php echo esc_html( (string) ( $option['title'] ?? $option_source_key ) . ' (' . $option_source_key . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php wp_nonce_field( 'leadwerk_repair_page' ); ?>
			<button type="submit" class="button">Reparatur starten</button>
		</form>

		<form method="get" class="leadwerk-importer-force-sync" style="margin:16px 0 24px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="leadwerk-import" />
			<label for="leadwerk-force-canonical-source"><strong>Force Canonical Sync</strong></label>
			<select id="leadwerk-force-canonical-source" name="force_canonical_source_key" required>
				<option value="">Seite waehlen</option>
				<?php foreach ( $structured_page_options as $option_source_key => $option ) : ?>
					<option value="<?php echo esc_attr( $option_source_key ); ?>" <?php selected( $force_canonical_source_key, $option_source_key ); ?>><?php echo esc_html( (string) ( $option['title'] ?? $option_source_key ) . ' (' . $option_source_key . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php wp_nonce_field( 'leadwerk_force_canonical' ); ?>
			<button type="submit" class="button">Canonical Shell auf DE erzwingen</button>
			<p style="margin:0;max-width:700px;">Ueberschreibt die gewaehlte DE Structured Page bewusst mit dem lokalen Canonical Shell Inhalt und setzt verbundene Uebersetzungen auf <code>needs_update</code>.</p>
		</form>

		<div class="leadwerk-importer-progress" data-leadwerk-importer-app>
			<div class="leadwerk-importer-progress__summary">
				<div>
					<strong>Status</strong>
					<div data-import-status><?php echo esc_html( ! empty( $state['status'] ) ? ucfirst( (string) $state['status'] ) : 'Idle' ); ?></div>
				</div>
				<div>
					<strong>Aktiver Schritt</strong>
					<div data-import-step><?php echo esc_html( (string) ( $state['current_step'] ?? 'preflight' ) ); ?></div>
				</div>
				<div>
					<strong>Aktives Element</strong>
					<div data-import-item><?php echo esc_html( (string) ( $state['current_item'] ?? '' ) ); ?></div>
				</div>
			</div>

			<div class="leadwerk-importer-progress__bar">
				<div class="leadwerk-importer-progress__bar-fill" data-import-overall-fill style="width:<?php echo esc_attr( (string) (int) ( $state['overall_percent'] ?? 0 ) ); ?>%"></div>
			</div>
			<div class="leadwerk-importer-progress__percent"><span data-import-overall-percent><?php echo esc_html( (string) (int) ( $state['overall_percent'] ?? 0 ) ); ?></span>%</div>

			<div class="leadwerk-importer-steps" data-import-steps></div>

			<div class="leadwerk-importer-counters">
				<div class="leadwerk-importer-counter"><span>Success</span><strong data-import-success><?php echo esc_html( (string) (int) ( $state['success_count'] ?? 0 ) ); ?></strong></div>
				<div class="leadwerk-importer-counter"><span>Warnings</span><strong data-import-warnings><?php echo esc_html( (string) (int) ( $state['warning_count'] ?? 0 ) ); ?></strong></div>
				<div class="leadwerk-importer-counter"><span>Errors</span><strong data-import-errors><?php echo esc_html( (string) (int) ( $state['error_count'] ?? 0 ) ); ?></strong></div>
			</div>

			<div class="leadwerk-importer-log">
				<h2>Live Log</h2>
				<div class="leadwerk-importer-log__list" data-import-log></div>
			</div>

			<div class="leadwerk-importer-summary" data-import-summary></div>
		</div>

		<details class="leadwerk-importer-fallback">
			<summary>Fallback: synchronen Legacy-Import ausfuehren</summary>
			<p>Nur verwenden, wenn die Live-Progress-Oberflaeche in deinem Setup blockiert wird.</p>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'run' => '1', 'dry_run' => '1' ), admin_url( 'admin.php?page=leadwerk-import' ) ), 'leadwerk_import_run' ) ); ?>" class="button">Legacy Dry-Run</a>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'run' => '1' ), admin_url( 'admin.php?page=leadwerk-import' ) ), 'leadwerk_import_run' ) ); ?>" class="button">Legacy Import</a>
			</p>
		</details>

		<?php if ( isset( $_GET['log'] ) ) : ?>
			<pre style="background:#f5f5f5;padding:1em;max-height:420px;overflow:auto;"><?php echo esc_html( get_option( 'leadwerk_import_log', '' ) ); ?></pre>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Raise PHP limits for a single import AJAX step (filterable).
 *
 * @return void
 */
function leadwerk_importer_maybe_raise_php_limits() {
	if ( ! apply_filters( 'leadwerk_import_raise_php_limits', true ) ) {
		return;
	}
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
	$mem = apply_filters( 'leadwerk_import_memory_limit', '512M' );
	if ( is_string( $mem ) && '' !== $mem ) {
		@ini_set( 'memory_limit', $mem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$time_limit = (int) apply_filters( 'leadwerk_import_time_limit', 300 );
	if ( $time_limit > 0 && function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $time_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

/**
 * Check AJAX permissions.
 *
 * @return void
 */
function leadwerk_importer_verify_ajax_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}

	check_ajax_referer( 'leadwerk_import_ajax', 'nonce' );
}

/**
 * Start one live import job.
 *
 * @return void
 */
function leadwerk_importer_ajax_start() {
	leadwerk_importer_verify_ajax_request();
	leadwerk_importer_maybe_raise_php_limits();

	$dry_run = ! empty( $_POST['dry_run'] );
	$state   = Leadwerk_Logger::get_state();

	if ( Leadwerk_Logger::has_active_job() ) {
		wp_send_json_success( array( 'state' => $state ) );
	}

	$importer = new Leadwerk_Importer( ! $dry_run );
	$state    = $importer->build_initial_job_state();
	wp_send_json_success( array( 'state' => $state ) );
}
add_action( 'wp_ajax_leadwerk_import_start', 'leadwerk_importer_ajax_start' );

/**
 * Run the next batch for the active import job.
 *
 * @return void
 */
function leadwerk_importer_ajax_step() {
	leadwerk_importer_verify_ajax_request();
	leadwerk_importer_maybe_raise_php_limits();

	$state = Leadwerk_Logger::get_state();
	if ( empty( $state['job_id'] ) ) {
		wp_send_json_error( array( 'message' => 'No import job found.' ), 404 );
	}

	try {
		$importer = new Leadwerk_Importer( empty( $state['dry_run'] ) );
		$state    = $importer->run_next_batch( $state );
		wp_send_json_success( array( 'state' => $state ) );
	} catch ( \Throwable $e ) {
		$technical = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
		Leadwerk_Logger::log( 'Import-Schritt abgebrochen: ' . $technical, 'error' );
		Leadwerk_Logger::record_result( 'error', 'Import-Schritt: ' . $e->getMessage(), 'import-step' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Leadwerk Importer: ' . $technical . "\n" . $e->getTraceAsString() );
		}

		$prev = Leadwerk_Logger::get_state();
		$block = isset( $prev['results']['blocking'] ) && is_array( $prev['results']['blocking'] ) ? $prev['results']['blocking'] : array();
		$block[] = $technical;
		Leadwerk_Logger::update_state(
			array(
				'status'       => 'failed',
				'finished_at'  => current_time( 'mysql', true ),
				'current_item' => 'PHP: ' . $e->getMessage(),
				'results'      => array(
					'blocking' => $block,
				),
			)
		);
		Leadwerk_Logger::save();

		wp_send_json_error(
			array(
				'message'   => $e->getMessage(),
				'technical' => $technical,
				'state'     => Leadwerk_Logger::get_state(),
			),
			500
		);
	}
}
add_action( 'wp_ajax_leadwerk_import_step', 'leadwerk_importer_ajax_step' );

/**
 * Read current import state.
 *
 * @return void
 */
function leadwerk_importer_ajax_state() {
	leadwerk_importer_verify_ajax_request();
	wp_send_json_success( array( 'state' => Leadwerk_Logger::get_state() ) );
}
add_action( 'wp_ajax_leadwerk_import_state', 'leadwerk_importer_ajax_state' );

/**
 * Reset the stored job state after completion.
 *
 * @return void
 */
function leadwerk_importer_ajax_reset() {
	leadwerk_importer_verify_ajax_request();

	$state = Leadwerk_Logger::get_state();
	if ( ! empty( $state['job_id'] ) && in_array( (string) ( $state['status'] ?? '' ), array( 'running', 'booting' ), true ) ) {
		wp_send_json_error( array( 'message' => 'The import is still running.' ), 409 );
	}

	Leadwerk_Logger::reset_job();
	wp_send_json_success( array( 'state' => array() ) );
}
add_action( 'wp_ajax_leadwerk_import_reset', 'leadwerk_importer_ajax_reset' );

