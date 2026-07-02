<?php
/**
 * Main importer for ACM AIR CHARTER pages, media, news and translations.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Importer {

	/**
	 * Dry-run mode.
	 *
	 * @var bool
	 */
	protected $dry_run = true;

	/**
	 * Manifest data.
	 *
	 * @var array<string,mixed>
	 */
	protected $manifest = array();

	/**
	 * Manifest directory path.
	 *
	 * @var string
	 */
	protected $manifest_dir = '';

	/**
	 * Source root path.
	 *
	 * @var string
	 */
	protected $source_root = '';

	/**
	 * Media importer.
	 *
	 * @var Leadwerk_Media_Importer|null
	 */
	protected $media_importer = null;

	/**
	 * Source file -> page lookup by language.
	 *
	 * @var array<string,array<string,int>>
	 */
	protected $page_lookup = array(
		'de' => array(),
		'en' => array(),
	);

	/**
	 * Filler instance.
	 *
	 * @var Leadwerk_ACF_Filler|null
	 */
	protected $filler = null;

	/**
	 * Constructor.
	 *
	 * @param bool $apply Whether changes should be applied.
	 */
	public function __construct( $apply = false ) {
		$this->dry_run      = ! $apply;
		$this->manifest_dir = LEADWERK_IMPORTER_PATH . 'manifest/';
		$this->load_manifest();

		$bundled = LEADWERK_IMPORTER_PATH . 'source_assets';
		if ( is_dir( $bundled ) ) {
			$this->source_root = $bundled;
		}

		$this->source_root = (string) apply_filters( 'leadwerk_import_source_root', $this->source_root );
		if ( '' !== $this->source_root && is_dir( $this->source_root ) ) {
			$this->media_importer = new Leadwerk_Media_Importer( $this->source_root, $this->dry_run );
		}

	}

	/**
	 * Load manifest.
	 *
	 * @return void
	 */
	protected function load_manifest() {
		$path = $this->manifest_dir . 'mapping.json';
		if ( ! is_file( $path ) ) {
			Leadwerk_Logger::log( 'Manifest nicht gefunden: ' . $path, 'error' );
			$this->manifest = array( 'pages' => array() );
			return;
		}

		$json = file_get_contents( $path );
		$data = json_decode( (string) $json, true );
		$this->manifest = is_array( $data ) ? $data : array( 'pages' => array() );

		Leadwerk_Logger::log( 'Manifest geladen: ' . count( $this->manifest['pages'] ?? array() ) . ' Seiten' );
	}

	/**
	 * Run the importer synchronously.
	 *
	 * @return void
	 */
	public function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$job = $this->build_initial_job_state();
		$max_iterations = 2000;
		$iterations     = 0;

		while ( $iterations < $max_iterations && ! in_array( (string) ( $job['status'] ?? '' ), array( 'completed', 'failed' ), true ) ) {
			$job = $this->run_next_batch(
				$job,
				array(
					'media_import'        => 20,
					'page_upsert'         => 2,
					'page_fill_de'        => 1,
					'page_meta'           => 2,
					'news_import'         => 2,
				)
			);
			++$iterations;
		}

		if ( $iterations >= $max_iterations && ! in_array( (string) ( $job['status'] ?? '' ), array( 'completed', 'failed' ), true ) ) {
			Leadwerk_Logger::record_result( 'error', 'Importer abgebrochen: zu viele Verarbeitungsschritte ohne Abschluss.', 'import-loop' );
			Leadwerk_Logger::finish_job(
				'failed',
				array(
					'current_item' => 'Importer aborted after reaching the safety iteration limit.',
				)
			);
		}

		Leadwerk_Logger::save();
	}

	/**
	 * Create or resume one import job state.
	 *
	 * @return array<string,mixed>
	 */
	public function build_initial_job_state() {
		$steps = $this->get_step_definitions();
		$job   = Leadwerk_Logger::start_job(
			array(
				'dry_run' => $this->dry_run,
				'status'  => 'running',
			)
		);

		if ( ! empty( $job['steps'] ) && ! empty( $job['job_id'] ) && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'booting' ), true ) ) {
			return $job;
		}

		$job = Leadwerk_Logger::set_state(
			array(
				'job_id'          => sanitize_text_field( wp_generate_uuid4() ),
				'dry_run'         => $this->dry_run,
				'status'          => 'running',
				'current_step'    => 'preflight',
				'current_item'    => '',
				'started_at'      => current_time( 'mysql', true ),
				'finished_at'     => '',
				'processed'       => 0,
				'success_count'   => 0,
				'warning_count'   => 0,
				'error_count'     => 0,
				'steps'           => $steps,
				'queues'          => array(
					'pages'   => array_values( (array) ( $this->manifest['pages'] ?? array() ) ),
					'media'   => array(),
					'news'    => array_values( (array) ( $this->manifest['news_articles'] ?? array() ) ),
				),
				'cursor'          => array(
					'media_import'        => 0,
					'page_upsert'         => 0,
					'page_fill_de'        => 0,
					'page_meta'           => 0,
					'news_import'         => 0,
				),
				'page_lookup'     => $this->page_lookup,
				'results'         => array(
					'pages'    => array(),
					'media'    => array(),
					'summary'  => array(),
					'blocking' => array(),
				),
				'log_tail'        => array(),
				'overall_percent' => 0,
				'step_percent'    => 0,
			)
		);

		Leadwerk_Logger::log( $this->dry_run ? '--- Dry-Run ---' : '--- Import (Apply) ---' );
		return $job;
	}

	/**
	 * Repair one mapped page without rerunning the full importer.
	 *
	 * @param string $source_key Source key from the manifest.
	 * @return array<string,mixed>|WP_Error
	 */
	public function repair_page_by_source_key( $source_key ) {
		$source_key = sanitize_key( (string) $source_key );
		$page_config = $this->find_page_config_by_source_key( $source_key );

		if ( empty( $page_config ) ) {
			return new WP_Error( 'leadwerk_import_missing_page', 'Mapping fuer die angeforderte Seite wurde nicht gefunden.' );
		}

		if ( Leadwerk_Logger::has_active_job() ) {
			return new WP_Error( 'leadwerk_import_active_job', 'Es laeuft bereits ein Import-Job.' );
		}

		$original_manifest = $this->manifest;
		$this->manifest['pages'] = array( $page_config );

		try {
			$job_state = $this->build_targeted_page_job_state( $page_config );
			$job_state = $this->perform_preflight_step( $job_state );

			if ( 'failed' === (string) ( $job_state['status'] ?? '' ) ) {
				Leadwerk_Logger::save();
				return $job_state;
			}

			$job_state = $this->perform_page_upsert_step( $job_state, 1 );
			$job_state = $this->perform_page_fill_de_step( $job_state, 1 );
			$job_state = $this->perform_page_meta_step( $job_state, 1 );
			$job_state = $this->perform_finalize_step( $job_state );
			$job_state = $this->complete_job( $job_state, true );

			Leadwerk_Logger::save();
			return $job_state;
		} finally {
			$this->manifest = $original_manifest;
		}
	}

	/**
	 * Force one mapped page to use the canonical local shell payload.
	 *
	 * @param string $source_key Source key from the manifest.
	 * @return array<string,mixed>|WP_Error
	 */
	public function force_canonical_sync_by_source_key( $source_key ) {
		$source_key  = sanitize_key( (string) $source_key );
		$page_config = $this->find_page_config_by_source_key( $source_key );

		if ( empty( $page_config ) ) {
			return new WP_Error( 'leadwerk_import_missing_page', 'Mapping fuer die angeforderte Seite wurde nicht gefunden.' );
		}

		if ( Leadwerk_Logger::has_active_job() ) {
			return new WP_Error( 'leadwerk_import_active_job', 'Es laeuft bereits ein Import-Job.' );
		}

		$original_manifest = $this->manifest;
		$this->manifest['pages'] = array( $page_config );

		try {
			$job_state = $this->build_force_canonical_sync_job_state( $page_config );
			$job_state = $this->perform_preflight_step( $job_state );

			if ( 'failed' === (string) ( $job_state['status'] ?? '' ) ) {
				Leadwerk_Logger::save();
				return $job_state;
			}

			$job_state = $this->perform_page_upsert_step( $job_state, 1 );
			$job_state = $this->perform_force_canonical_sync_step( $job_state, 1 );
			$job_state = $this->perform_page_meta_step( $job_state, 1 );
			$job_state = $this->perform_finalize_step( $job_state );
			$job_state = $this->complete_job( $job_state, true );

			Leadwerk_Logger::save();
			return $job_state;
		} finally {
			$this->manifest = $original_manifest;
		}
	}

	/**
	 * Repair broken structured DE content using last-good snapshots first and shell fallback second.
	 *
	 * @param string[] $source_keys Optional source-key filter.
	 * @return array<string,mixed>|WP_Error
	 */
	public function repair_current_structured_content( $source_keys = array() ) {
		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			return new WP_Error( 'leadwerk_repair_missing_schema', 'Leadwerk_Content_Schema ist nicht geladen.' );
		}

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			return new WP_Error( 'leadwerk_repair_missing_acf', 'ACF Field Funktionen sind fuer den Repair-Run nicht verfuegbar.' );
		}

		if ( ! function_exists( 'leadwerk_theme_render_exact_page_group' ) ) {
			return new WP_Error( 'leadwerk_repair_missing_renderer', 'Exact Renderer ist fuer den Repair-Run nicht verfuegbar.' );
		}

		if ( Leadwerk_Logger::has_active_job() ) {
			return new WP_Error( 'leadwerk_import_active_job', 'Es laeuft bereits ein Import-Job.' );
		}

		$page_configs = $this->get_structured_repair_page_configs( $source_keys );
		if ( empty( $page_configs ) ) {
			return new WP_Error( 'leadwerk_repair_missing_pages', 'Keine passenden DE-Field-Gruppen fuer den Structured-Repair gefunden.' );
		}

		Leadwerk_Logger::start_job(
			array(
				'dry_run' => $this->dry_run,
				'status'  => 'running',
			)
		);
		Leadwerk_Logger::set_state(
			array(
				'job_id'          => sanitize_text_field( wp_generate_uuid4() ),
				'dry_run'         => $this->dry_run,
				'status'          => 'running',
				'current_step'    => 'repair_structured',
				'current_item'    => '',
				'started_at'      => current_time( 'mysql', true ),
				'finished_at'     => '',
				'processed'       => 0,
				'success_count'   => 0,
				'warning_count'   => 0,
				'error_count'     => 0,
				'steps'           => array(
					'repair_structured' => array(
						'label'     => 'Repair Structured Content',
						'total'     => count( $page_configs ),
						'processed' => 0,
						'status'    => 'running',
					),
				),
				'queues'          => array(
					'pages' => array_values( $page_configs ),
					'media' => array(),
				),
				'cursor'          => array(
					'repair_structured' => 0,
				),
				'results'         => array(
					'pages'             => array(),
					'media'             => array(),
					'summary'           => array(),
					'blocking'          => array(),
					'structured_repair' => array(),
				),
				'log_tail'        => array(),
				'overall_percent' => 0,
				'step_percent'    => 0,
			)
		);

		$filler = $this->get_filler();
		$report = array(
			'dry_run'             => $this->dry_run,
			'scanned'             => 0,
			'unchanged'           => 0,
			'source_drift'        => 0,
			'repaired'            => 0,
			'failed'              => 0,
			'snapshot_used'       => 0,
			'shell_fallback_used' => 0,
			'pages'               => array(),
		);

		foreach ( array_values( $page_configs ) as $index => $page_config ) {
			$page_config = is_array( $page_config ) ? $page_config : array();
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$group       = Leadwerk_Content_Schema::get_group( $field_name );
			$de_id       = $this->find_page_by_source_key_and_lang( $source_key, 'de', (string) ( $page_config['target_type'] ?? 'page' ) );
			$page_entry  = array(
				'source_key'     => $source_key,
				'slug'           => (string) ( $page_config['slug'] ?? '' ),
				'field_name'     => $field_name,
				'post_id'        => (int) $de_id,
				'repair_source'  => '',
				'status'         => 'skipped',
				'message'        => '',
				'current_render' => array(),
			);

			Leadwerk_Logger::set_current( 'repair_structured', (string) ( $page_config['source_file'] ?? $source_key ) );

			if ( ! $de_id || empty( $group['layouts'] ) ) {
				$page_entry['status']  = 'error';
				$page_entry['message'] = 'DE-Seite oder strukturierte Layout-Gruppe fehlt.';
				$report['failed']++;
				$report['pages'][ $source_key ] = $page_entry;
				Leadwerk_Logger::record_result( 'error', 'Structured repair fehlgeschlagen fuer ' . $source_key . ': DE-Seite oder Layout-Gruppe fehlt.', $source_key . '-repair' );
				Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
				continue;
			}

			$group['field_name'] = $field_name;
			$current_value       = get_field( $field_name, $de_id );
			$current_validation  = $filler->validate_group_value( $field_name, $current_value );
			$current_render      = $this->validate_exact_group_render( $group, $current_value, $page_config, $de_id );
			$source_drift        = $this->assess_canonical_source_drift( $page_config, $group, $current_value, $current_validation, $current_render, $de_id, $filler );
			$needs_repair        = empty( $current_validation['has_visible_content'] ) || empty( $current_render['is_valid'] );

			$page_entry['current_validation'] = $this->compact_validation( (array) $current_validation );
			$page_entry['current_render']     = $this->compact_render_validation( $current_render );
			$page_entry['source_drift']       = $this->compact_source_drift( $source_drift );
			$report['scanned']++;

			if ( ! $needs_repair && ! empty( $source_drift['has_drift'] ) ) {
				$page_entry['status']      = 'source_drift';
				$page_entry['sync_status'] = 'source_drift';
				$page_entry['message']     = 'Current structured content is render-valid but differs from the canonical shell. Run Force Canonical Sync to align source data.';
				$report['source_drift']++;
				$report['pages'][ $source_key ] = $page_entry;
				Leadwerk_Logger::record_result( 'warning', 'Structured repair erkannte Canonical-Drift fuer ' . $source_key . '.', $source_key . '-repair' );
				Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
				continue;
			}

			if ( ! $needs_repair ) {
				$page_entry['status']  = 'ok';
				$page_entry['message'] = 'Current structured render is already valid.';
				$report['unchanged']++;
				$report['pages'][ $source_key ] = $page_entry;
				Leadwerk_Logger::record_result( 'success', 'Structured repair nicht noetig fuer ' . $source_key . '.', $source_key . '-repair' );
				Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
				continue;
			}

			$candidate = $this->resolve_structured_repair_candidate( $page_config, $group, $current_value, $de_id, $filler );
			$page_entry['candidate'] = array(
				'repair_source' => (string) ( $candidate['repair_source'] ?? '' ),
				'snapshot_meta' => (string) ( $candidate['snapshot_meta_source'] ?? '' ),
				'validation'    => $this->compact_validation( (array) ( $candidate['validation'] ?? array() ) ),
				'render'        => $this->compact_render_validation( (array) ( $candidate['render'] ?? array() ) ),
			);

			if ( empty( $candidate['is_valid'] ) ) {
				$page_entry['status']  = 'error';
				$page_entry['message'] = 'Weder Snapshot noch Shell-Fallback konnte valide Exact-HTML liefern.';
				$report['failed']++;
				$report['pages'][ $source_key ] = $page_entry;
				Leadwerk_Logger::record_result( 'error', 'Structured repair fehlgeschlagen fuer ' . $source_key . ': kein valider Snapshot/Shell-Kandidat.', $source_key . '-repair' );
				Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
				continue;
			}

			if ( $this->dry_run ) {
				$page_entry['status']        = 'dry-run';
				$page_entry['message']       = 'Dry-run: Kandidat wurde validiert, aber nicht geschrieben.';
				$page_entry['repair_source'] = (string) ( $candidate['repair_source'] ?? '' );
				if ( 'snapshot' === $page_entry['repair_source'] ) {
					$report['snapshot_used']++;
				} elseif ( 'shell_fallback' === $page_entry['repair_source'] ) {
					$report['shell_fallback_used']++;
				}
				$report['pages'][ $source_key ] = $page_entry;
				Leadwerk_Logger::record_result( 'warning', 'Structured repair Dry-Run fuer ' . $source_key . ': ' . $page_entry['repair_source'], $source_key . '-repair' );
				Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
				continue;
			}

			update_field( $field_name, $candidate['value'], $de_id );
			$readback            = get_field( $field_name, $de_id );
			$readback_validation = $filler->validate_group_value( $field_name, $readback );
			$readback_render     = $this->validate_exact_group_render( $group, $readback, $page_config, $de_id );

			$page_entry['readback_validation'] = $this->compact_validation( (array) $readback_validation );
			$page_entry['readback_render']     = $this->compact_render_validation( $readback_render );

			if ( ! empty( $readback_validation['has_visible_content'] ) && ! empty( $readback_render['is_valid'] ) ) {
				$this->save_last_good_snapshot( $de_id, $field_name, $readback, $readback_validation, 'readback' );
				$this->save_imported_field_state( $de_id, $field_name, $readback, 'structured_repair' );
				$this->refresh_translation_needs_from_source( $de_id );
				$page_entry['status']        = 'repaired';
				$page_entry['sync_status']   = 'structured_repair';
				$page_entry['message']       = 'Structured content repaired successfully.';
				$page_entry['repair_source'] = (string) ( $candidate['repair_source'] ?? '' );
				$report['repaired']++;
				if ( 'snapshot' === $page_entry['repair_source'] ) {
					$report['snapshot_used']++;
				} elseif ( 'shell_fallback' === $page_entry['repair_source'] ) {
					$report['shell_fallback_used']++;
				}
				Leadwerk_Logger::record_result( 'success', 'Structured repair erfolgreich fuer ' . $source_key . ' via ' . $page_entry['repair_source'] . '.', $source_key . '-repair' );
			} else {
				update_field( $field_name, $current_value, $de_id );
				$page_entry['status']  = 'error';
				$page_entry['message'] = 'Repair-Schreibvorgang war nicht stabil; urspruenglicher Inhalt wurde wiederhergestellt.';
				$report['failed']++;
				Leadwerk_Logger::record_result( 'error', 'Structured repair Schreibtest fehlgeschlagen fuer ' . $source_key . '; Originalinhalt wurde wiederhergestellt.', $source_key . '-repair' );
			}

			$report['pages'][ $source_key ] = $page_entry;
			Leadwerk_Logger::update_step( 'repair_structured', array( 'processed' => $index + 1 ) );
		}

		Leadwerk_Logger::update_step(
			'repair_structured',
			array(
				'processed' => count( $page_configs ),
				'status'    => 'completed',
			)
		);

		$state = Leadwerk_Logger::finish_job(
			'completed',
			array(
				'current_step'                 => 'repair_structured',
				'current_item'                 => '',
				'suppress_post_import_notice'  => true,
				'results'                      => array(
					'structured_repair' => $report,
				),
			)
		);
		Leadwerk_Logger::save();

		$report['job_state'] = $state;
		return $report;
	}

	/**
	 * Run the next importer batch.
	 *
	 * @param array<string,mixed> $job_state   Current state.
	 * @param array<string,int>   $batch_sizes Step-specific batch sizes.
	 * @return array<string,mixed>
	 */
	public function run_next_batch( $job_state, $batch_sizes = array() ) {
		$job_state = is_array( $job_state ) ? $job_state : $this->build_initial_job_state();
		$batch_sizes = apply_filters( 'leadwerk_import_batch_sizes', is_array( $batch_sizes ) ? $batch_sizes : array(), $job_state );
		if ( in_array( (string) ( $job_state['status'] ?? '' ), array( 'completed', 'failed' ), true ) ) {
			return $job_state;
		}

		$this->restore_runtime_from_state( $job_state );

		$step_key = $this->get_next_step_key( $job_state );
		if ( '' === $step_key ) {
			return $this->complete_job( $job_state );
		}

		switch ( $step_key ) {
			case 'preflight':
				$job_state = $this->perform_preflight_step( $job_state );
				break;
			case 'media_scan':
				$job_state = $this->perform_media_scan_step( $job_state );
				break;
			case 'media_import':
				$job_state = $this->perform_media_import_step( $job_state, max( 1, (int) ( $batch_sizes['media_import'] ?? 6 ) ) );
				break;
			case 'page_upsert':
				$job_state = $this->perform_page_upsert_step( $job_state, max( 1, (int) ( $batch_sizes['page_upsert'] ?? 1 ) ) );
				break;
			case 'page_fill_de':
				$job_state = $this->perform_page_fill_de_step( $job_state, max( 1, (int) ( $batch_sizes['page_fill_de'] ?? 1 ) ) );
				break;
			case 'page_meta':
				$job_state = $this->perform_page_meta_step( $job_state, max( 1, (int) ( $batch_sizes['page_meta'] ?? 1 ) ) );
				break;
			case 'options':
				$job_state = $this->perform_options_step( $job_state );
				break;
			case 'news_import':
				$job_state = $this->perform_news_import_step( $job_state, max( 1, (int) ( $batch_sizes['news_import'] ?? 2 ) ) );
				break;
			case 'finalize':
				$job_state = $this->perform_finalize_step( $job_state );
				break;
		}

		$this->persist_runtime_into_state( $job_state );
		$job_state = $this->merge_runtime_job_state( $job_state );
		$job_state = $this->refresh_processed_count( $job_state );
		Leadwerk_Logger::set_state( $job_state );

		if ( 'failed' === ( $job_state['status'] ?? '' ) ) {
			return Leadwerk_Logger::get_state();
		}

		if ( '' === $this->get_next_step_key( $job_state ) ) {
			return $this->complete_job( $job_state );
		}

		return Leadwerk_Logger::get_state();
	}

	/**
	 * Find one page config by manifest source key.
	 *
	 * @param string $source_key Source key.
	 * @return array<string,mixed>
	 */
	protected function find_page_config_by_source_key( $source_key ) {
		$source_key = sanitize_key( (string) $source_key );
		foreach ( (array) ( $this->manifest['pages'] ?? array() ) as $page_config ) {
			$page_config = is_array( $page_config ) ? $page_config : array();
			if ( $source_key === sanitize_key( (string) ( $page_config['source_key'] ?? '' ) ) ) {
				return $page_config;
			}
		}

		return array();
	}

	/**
	 * Build one minimal job state for a single mapped page repair.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return array<string,mixed>
	 */
	protected function build_targeted_page_job_state( $page_config ) {
		$job_state = $this->build_initial_job_state();
		$job_state['queues']['pages'] = array( $page_config );
		$job_state['queues']['media'] = array();
		$job_state['cursor']          = array(
			'media_import'        => 0,
			'page_upsert'         => 0,
			'page_fill_de'        => 0,
			'page_meta'           => 0,
		);

		foreach ( (array) ( $job_state['steps'] ?? array() ) as $step_key => $step ) {
			$job_state['steps'][ $step_key ]['processed'] = 0;
			if ( in_array( $step_key, array( 'preflight', 'page_upsert', 'page_fill_de', 'page_meta', 'finalize' ), true ) ) {
				$job_state['steps'][ $step_key ]['total']  = 1;
				$job_state['steps'][ $step_key ]['status'] = 'pending';
				continue;
			}

			$job_state['steps'][ $step_key ]['total']     = 0;
			$job_state['steps'][ $step_key ]['processed'] = 0;
			$job_state['steps'][ $step_key ]['status']    = 'completed';
		}

		$job_state['results']['pages']    = array();
		$job_state['results']['media']    = array();
		$job_state['results']['summary']  = array();
		$job_state['results']['blocking'] = array();
		$job_state['current_step']        = 'preflight';
		$job_state['current_item']        = (string) ( $page_config['source_file'] ?? $page_config['source_key'] ?? '' );
		$job_state['processed']           = 0;
		$job_state['overall_percent']     = 0;
		$job_state['step_percent']        = 0;

		return Leadwerk_Logger::set_state( $job_state );
	}

	/**
	 * Build one minimal job state for a single force canonical sync.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return array<string,mixed>
	 */
	protected function build_force_canonical_sync_job_state( $page_config ) {
		$job_state = $this->build_targeted_page_job_state( $page_config );
		$existing_steps = is_array( $job_state['steps'] ?? null ) ? $job_state['steps'] : array();

		$job_state['cursor'] = array(
			'page_upsert'           => 0,
			'force_canonical_sync'  => 0,
			'page_meta'             => 0,
		);
		$job_state['steps'] = array(
			'preflight' => array_merge(
				(array) ( $existing_steps['preflight'] ?? array() ),
				array(
					'total'     => 1,
					'processed' => 0,
					'status'    => 'pending',
				)
			),
			'media_scan' => array_merge(
				(array) ( $existing_steps['media_scan'] ?? array() ),
				array(
					'total'     => 0,
					'processed' => 0,
					'status'    => 'completed',
				)
			),
			'media_import' => array_merge(
				(array) ( $existing_steps['media_import'] ?? array() ),
				array(
					'total'     => 0,
					'processed' => 0,
					'status'    => 'completed',
				)
			),
			'page_upsert' => array_merge(
				(array) ( $existing_steps['page_upsert'] ?? array() ),
				array(
					'total'     => 1,
					'processed' => 0,
					'status'    => 'pending',
				)
			),
			'force_canonical_sync' => array(
				'label'     => 'Force Canonical Sync',
				'total'     => 1,
				'processed' => 0,
				'status'    => 'pending',
			),
			'page_fill_de' => array_merge(
				(array) ( $existing_steps['page_fill_de'] ?? array() ),
				array(
					'total'     => 0,
					'processed' => 0,
					'status'    => 'completed',
				)
			),
			'page_meta' => array_merge(
				(array) ( $existing_steps['page_meta'] ?? array() ),
				array(
					'total'     => 1,
					'processed' => 0,
					'status'    => 'pending',
				)
			),
			'options' => array_merge(
				(array) ( $existing_steps['options'] ?? array() ),
				array(
					'total'     => 0,
					'processed' => 0,
					'status'    => 'completed',
				)
			),
			'finalize' => array_merge(
				(array) ( $existing_steps['finalize'] ?? array() ),
				array(
					'total'     => 1,
					'processed' => 0,
					'status'    => 'pending',
				)
			),
		);

		return Leadwerk_Logger::set_state( $job_state );
	}

	/**
	 * Return structured page options for importer admin tools.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_structured_page_options() {
		$options = array();
		foreach ( $this->get_structured_repair_page_configs() as $page_config ) {
			$page_config = is_array( $page_config ) ? $page_config : array();
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			if ( '' === $source_key ) {
				continue;
			}

			$options[ $source_key ] = array(
				'title'      => (string) ( $page_config['title'] ?? $source_key ),
				'source_key' => $source_key,
			);
		}

		return $options;
	}

	/**
	 * Resolve the page configs that participate in the structured repair scan.
	 *
	 * @param string[] $source_keys Optional requested source keys.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_structured_repair_page_configs( $source_keys = array() ) {
		$mandatory = array();
		$requested = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $item ) {
							return sanitize_key( (string) $item );
						},
						(array) $source_keys
					)
				)
			)
		);

		$manifest_pages = array_values( (array) ( $this->manifest['pages'] ?? array() ) );
		$all_configs    = array();
		foreach ( $manifest_pages as $page_config ) {
			$page_config = is_array( $page_config ) ? $page_config : array();
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$group       = Leadwerk_Content_Schema::get_group( $field_name );
			if ( empty( $group['layouts'] ) ) {
				continue;
			}

			$all_configs[ sanitize_key( (string) ( $page_config['source_key'] ?? '' ) ) ] = $page_config;
		}

		$ordered_keys = array();
		foreach ( $mandatory as $source_key ) {
			if ( isset( $all_configs[ $source_key ] ) ) {
				$ordered_keys[] = $source_key;
			}
		}

		if ( empty( $requested ) ) {
			foreach ( array_keys( $all_configs ) as $source_key ) {
				if ( ! in_array( $source_key, $ordered_keys, true ) ) {
					$ordered_keys[] = $source_key;
				}
			}
		} else {
			foreach ( $requested as $source_key ) {
				if ( isset( $all_configs[ $source_key ] ) && ! in_array( $source_key, $ordered_keys, true ) ) {
					$ordered_keys[] = $source_key;
				}
			}
		}

		$configs = array();
		foreach ( $ordered_keys as $source_key ) {
			if ( isset( $all_configs[ $source_key ] ) ) {
				$configs[] = $all_configs[ $source_key ];
			}
		}

		return $configs;
	}

	/**
	 * Resolve the preferred repair candidate for one broken structured page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param array<string,mixed> $group       Group schema.
	 * @param mixed               $current     Current stored value.
	 * @param int                 $post_id     Post ID.
	 * @param Leadwerk_ACF_Filler $filler      Filler instance.
	 * @return array<string,mixed>
	 */
	protected function resolve_structured_repair_candidate( $page_config, $group, $current, $post_id, $filler ) {
		$field_name = (string) ( $page_config['field_name'] ?? '' );
		$snapshot   = $this->get_last_good_snapshot( $post_id, $field_name );

		if ( is_array( $snapshot ) && array_key_exists( 'value', $snapshot ) ) {
			$snapshot_validation = $filler->validate_group_value( $field_name, $snapshot['value'] );
			$snapshot_render     = $this->validate_exact_group_render( $group, $snapshot['value'], $page_config, $post_id );
			if ( ! empty( $snapshot_validation['has_visible_content'] ) && ! empty( $snapshot_render['is_valid'] ) ) {
				return array(
					'is_valid'             => true,
					'repair_source'        => 'snapshot',
					'snapshot_meta_source' => (string) ( $snapshot['source'] ?? '' ),
					'value'                => $snapshot['value'],
					'validation'           => $snapshot_validation,
					'render'               => $snapshot_render,
				);
			}
		}

		$shell_payload = $this->build_canonical_shell_payload( $page_config, 'de' );
		$shell_value   = $shell_payload['value'] ?? array();
		$shell_validation = $filler->validate_group_value( $field_name, $shell_value );
		$shell_render     = $this->validate_exact_group_render( $group, $shell_value, $page_config, $post_id );

		if ( ! empty( $shell_validation['has_visible_content'] ) && ! empty( $shell_render['is_valid'] ) ) {
			return array(
				'is_valid'             => true,
				'repair_source'        => 'shell_fallback',
				'snapshot_meta_source' => '',
				'value'                => $shell_value,
				'validation'           => $shell_validation,
				'render'               => $shell_render,
				'shell_source'         => (string) ( $shell_payload['source'] ?? '' ),
			);
		}

		return array(
			'is_valid'             => false,
			'repair_source'        => '',
			'snapshot_meta_source' => (string) ( $snapshot['source'] ?? '' ),
			'value'                => $current,
			'validation'           => $shell_validation,
			'render'               => $shell_render,
		);
	}

	/**
	 * Build the canonical shell payload used as the repair fallback.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $lang        Language code.
	 * @return array<string,mixed>
	 */
	protected function build_canonical_shell_payload( $page_config, $lang = 'de' ) {
		$filler = $this->get_filler();
		$html   = $this->get_canonical_shell_html( $page_config );

		if ( '' !== trim( $html ) ) {
			$payload           = $filler->build_page_payload_from_html( $page_config, $html, $lang );
			$payload['source'] = 'theme_shell';
			return $payload;
		}

		$payload           = $filler->build_page_payload( $page_config, $lang );
		$payload['source'] = 'source_assets';
		return $payload;
	}

	/**
	 * Read the local canonical shell HTML for one page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function get_canonical_shell_html( $page_config ) {
		$file_name = basename( (string) ( $page_config['source_file'] ?? '' ) );
		if ( '' === $file_name ) {
			return '';
		}

		$candidates = array();
		if ( defined( 'LEADWERK_THEME_DIR' ) ) {
			$candidates[] = trailingslashit( LEADWERK_THEME_DIR ) . 'source_shells/' . $file_name;
		}
		$candidates[] = dirname( LEADWERK_IMPORTER_PATH ) . '/leadwerk_theme/source_shells/' . $file_name;

		foreach ( array_unique( $candidates ) as $file_path ) {
			if ( is_file( $file_path ) ) {
				$html = file_get_contents( $file_path );
				if ( false !== $html ) {
					return (string) $html;
				}
			}
		}

		if ( function_exists( 'leadwerk_theme_get_source_template_html' ) ) {
			return (string) leadwerk_theme_get_source_template_html( (string) ( $page_config['source_key'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Compare current structured content with the canonical shell source.
	 *
	 * @param array<string,mixed> $page_config        Page config.
	 * @param array<string,mixed> $group             Group schema.
	 * @param mixed               $current_value      Current stored value.
	 * @param array<string,mixed> $current_validation Current validation.
	 * @param array<string,mixed> $current_render     Current render validation.
	 * @param int                 $post_id            Post ID.
	 * @param Leadwerk_ACF_Filler $filler             Filler instance.
	 * @return array<string,mixed>
	 */
	protected function assess_canonical_source_drift( $page_config, $group, $current_value, $current_validation, $current_render, $post_id, $filler ) {
		$field_name = (string) ( $page_config['field_name'] ?? '' );
		if ( '' === $field_name || empty( $group ) || ! is_array( $group ) ) {
			return array(
				'has_drift' => false,
			);
		}

		$group['field_name'] = $field_name;
		$canonical_payload   = $this->build_canonical_shell_payload( $page_config, 'de' );
		$canonical_value     = $canonical_payload['value'] ?? array();
		$canonical_validation = $filler->validate_group_value( $field_name, $canonical_value );
		$canonical_render     = $this->validate_exact_group_render( $group, $canonical_value, $page_config, $post_id );
		$current_sig          = ! empty( $current_validation['has_visible_content'] ) ? $this->build_field_signature( $current_value ) : '';
		$canonical_sig        = ! empty( $canonical_validation['has_visible_content'] ) ? $this->build_field_signature( $canonical_value ) : '';
		$has_drift            = '' !== $current_sig
			&& '' !== $canonical_sig
			&& $current_sig !== $canonical_sig
			&& ! empty( $current_render['is_valid'] )
			&& ! empty( $canonical_render['is_valid'] );

		return array(
			'has_drift'            => $has_drift,
			'canonical_source'     => (string) ( $canonical_payload['source'] ?? '' ),
			'current_signature'    => $current_sig,
			'canonical_signature'  => $canonical_sig,
			'canonical_validation' => $canonical_validation,
			'canonical_render'     => $canonical_render,
		);
	}

	/**
	 * Validate rendered exact HTML for one structured group.
	 *
	 * @param array<string,mixed> $group      Group schema.
	 * @param mixed               $value      Candidate value.
	 * @param array<string,mixed> $page_config Page config.
	 * @param int                 $post_id    Post ID.
	 * @return array<string,mixed>
	 */
	protected function validate_exact_group_render( $group, $value, $page_config, $post_id ) {
		$render_group               = is_array( $group ) ? $group : array();
		$render_group['field_name'] = '';
		$html                       = leadwerk_theme_render_exact_page_group( $render_group, $value, (int) $post_id );
		$has_visible_content        = '' !== trim( wp_strip_all_tags( (string) $html ) );

		$result = array(
			'is_valid'                    => false,
			'render_has_visible_content'  => $has_visible_content,
			'heading_block_descendants'   => 0,
			'nested_paragraphs'           => 0,
			'empty_headings'              => 0,
			'misplaced_headline_patterns' => 0,
			'missing_expected_headings'   => array(),
			'expected_heading_texts'      => array(),
			'rendered_heading_texts'      => array(),
		);

		if ( ! $has_visible_content || ! class_exists( 'DOMDocument' ) ) {
			return $result;
		}

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="leadwerk-structured-repair-root">' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		$result['heading_block_descendants'] = $this->count_dom_query(
			$xpath,
			'//*[@id="leadwerk-structured-repair-root"]//*[self::h1 or self::h2 or self::h3 or self::h4]//*[self::article or self::aside or self::blockquote or self::div or self::header or self::footer or self::li or self::main or self::ol or self::p or self::section or self::table or self::ul]'
		);
		$result['nested_paragraphs'] = $this->count_dom_query(
			$xpath,
			'//*[@id="leadwerk-structured-repair-root"]//p//p'
		);
		$result['empty_headings'] = $this->count_dom_query(
			$xpath,
			'//*[@id="leadwerk-structured-repair-root"]//*[self::h1 or self::h2 or self::h3 or self::h4][normalize-space(string(.))=""]'
		);

		$empty_heading_nodes = $xpath->query( '//*[@id="leadwerk-structured-repair-root"]//*[self::h1 or self::h2 or self::h3 or self::h4][normalize-space(string(.))=""]' );
		if ( $empty_heading_nodes instanceof DOMNodeList ) {
			foreach ( $empty_heading_nodes as $heading_node ) {
				$next_paragraph = $xpath->query( 'following::*[self::p][normalize-space(string(.))!=""][1]', $heading_node );
				if ( $next_paragraph instanceof DOMNodeList && $next_paragraph->length > 0 ) {
					$result['misplaced_headline_patterns']++;
				}
			}
		}

		$heading_nodes = $xpath->query( '//*[@id="leadwerk-structured-repair-root"]//*[self::h1 or self::h2 or self::h3 or self::h4]' );
		if ( $heading_nodes instanceof DOMNodeList ) {
			foreach ( $heading_nodes as $heading_node ) {
				$text = $this->normalize_heading_text( $heading_node->textContent ?? '' );
				if ( '' !== $text ) {
					$result['rendered_heading_texts'][] = $text;
				}
			}
		}

		$result['expected_heading_texts']    = $this->extract_expected_heading_texts_from_group( $group, $value );
		$result['rendered_heading_texts']    = array_values( array_unique( $result['rendered_heading_texts'] ) );
		$result['missing_expected_headings'] = array_values(
			array_filter(
				$result['expected_heading_texts'],
				function ( $expected ) use ( $result ) {
					return ! in_array( $expected, $result['rendered_heading_texts'], true );
				}
			)
		);
		$result['is_valid'] = $has_visible_content
			&& 0 === (int) $result['heading_block_descendants']
			&& 0 === (int) $result['nested_paragraphs']
			&& 0 === (int) $result['empty_headings']
			&& 0 === (int) $result['misplaced_headline_patterns']
			&& empty( $result['missing_expected_headings'] );

		return $result;
	}

	/**
	 * Count nodes for one DOMXPath query.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param string   $query XPath query.
	 * @return int
	 */
	protected function count_dom_query( $xpath, $query ) {
		$nodes = $xpath->query( $query );
		return $nodes instanceof DOMNodeList ? (int) $nodes->length : 0;
	}

	/**
	 * Extract the expected visible heading texts for a structured value.
	 *
	 * @param array<string,mixed> $group Group schema.
	 * @param mixed               $value Structured value.
	 * @return string[]
	 */
	protected function extract_expected_heading_texts_from_group( $group, $value ) {
		if ( empty( $group['layouts'] ) || ! is_array( $value ) ) {
			return array();
		}

		$map      = $this->get_expected_heading_field_paths();
		$sections = array_values( $value );
		$texts    = array();
		$index    = 0;

		foreach ( (array) ( $group['layouts'] ?? array() ) as $layout_key => $layout_schema ) {
			$template    = (string) ( $layout_schema['template'] ?? $layout_key );
			$field_paths = (array) ( $map[ $template ] ?? array() );
			$section     = isset( $sections[ $index ] ) && is_array( $sections[ $index ] ) ? $sections[ $index ] : array();
			foreach ( $field_paths as $field_path ) {
				$texts = array_merge( $texts, $this->extract_heading_texts_from_field_path( $section, (string) $field_path ) );
			}
			++$index;
		}

		return array_values( array_unique( array_filter( $texts ) ) );
	}

	/**
	 * Return the heading field-path map used by the exact render validator.
	 *
	 * @return array<string,string[]>
	 */
	protected function get_expected_heading_field_paths() {
		return array(
			'hero'                => array( 'title' ),
			'hero_slider'         => array( 'slides.*.title' ),
			'pillars'             => array( 'title' ),
			'why_acm'             => array( 'title' ),
			'how_it_works'        => array( 'title' ),
			'testimonials'        => array( 'title' ),
			'faq'                 => array( 'title' ),
			'banner_cta'          => array( 'title' ),
			'about_bedeutet'      => array( 'title' ),
			'media_text'          => array( 'title' ),
			'workflow_blurbs'     => array( 'title' ),
			'center_cta'          => array( 'title' ),
			'tabs_section'        => array( 'title', 'tabs.*.intro' ),
			'retirement_audience' => array( 'title' ),
			'concepts_section'    => array( 'title' ),
			'blurb_image_section' => array( 'title' ),
			'approach_tiles'      => array( 'title' ),
			'timeline'            => array( 'title' ),
			'target_groups'       => array( 'title' ),
			'results_section'     => array( 'title' ),
			'real_estate_intro'   => array( 'goals_title', 'challenge_title' ),
			'calculator'          => array( 'title' ),
			'case_highlight'      => array( 'title' ),
			'dark_cta'            => array( 'title' ),
			'responsibility'      => array( 'title' ),
			'new_phase'           => array( 'title' ),
			'outcomes'            => array( 'title' ),
			'target_groups_image' => array( 'title' ),
			'audience_cards'      => array( 'title' ),
			'media_blurbs'        => array( 'title' ),
			'invest_detail'       => array( 'title', 'explainer_title' ),
			'tax_detail'          => array( 'title', 'steps_title' ),
			'contact_main'        => array( 'title' ),
		);
	}

	/**
	 * Extract normalized heading texts from one dot-path.
	 *
	 * @param mixed    $value      Section value.
	 * @param string   $field_path Dot path with optional * wildcard.
	 * @return string[]
	 */
	protected function extract_heading_texts_from_field_path( $value, $field_path ) {
		$segments = array_values( array_filter( explode( '.', (string) $field_path ), 'strlen' ) );
		return $this->extract_heading_texts_from_segments( $value, $segments );
	}

	/**
	 * Extract normalized heading texts from path segments.
	 *
	 * @param mixed    $value    Current value.
	 * @param string[] $segments Remaining path segments.
	 * @return string[]
	 */
	protected function extract_heading_texts_from_segments( $value, $segments ) {
		if ( empty( $segments ) ) {
			$text = $this->normalize_heading_text( is_scalar( $value ) ? (string) $value : '' );
			return '' !== $text ? array( $text ) : array();
		}

		$segment = array_shift( $segments );
		if ( '*' === $segment ) {
			$texts = array();
			foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
				$texts = array_merge( $texts, $this->extract_heading_texts_from_segments( $row, $segments ) );
			}
			return $texts;
		}

		if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
			return array();
		}

		return $this->extract_heading_texts_from_segments( $value[ $segment ], $segments );
	}

	/**
	 * Normalize heading text for validator comparisons.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	protected function normalize_heading_text( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		return sanitize_text_field( $text );
	}

	/**
	 * Return step definitions for the progress bar.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_step_definitions() {
		$page_total = count( (array) ( $this->manifest['pages'] ?? array() ) );

		return array(
			'preflight' => array(
				'label'     => 'Preflight',
				'total'     => 1,
				'processed' => 0,
				'status'    => 'pending',
			),
			'media_scan' => array(
				'label'     => 'Media Scan',
				'total'     => 1,
				'processed' => 0,
				'status'    => 'pending',
			),
			'media_import' => array(
				'label'     => 'Media Import',
				'total'     => 0,
				'processed' => 0,
				'status'    => 'pending',
			),
			'page_upsert' => array(
				'label'     => 'Page Upsert',
				'total'     => $page_total,
				'processed' => 0,
				'status'    => 'pending',
			),
			'page_fill_de' => array(
				'label'     => 'Fill DE Pages',
				'total'     => $page_total,
				'processed' => 0,
				'status'    => 'pending',
			),
			'page_meta' => array(
				'label'     => 'Apply Meta',
				'total'     => $page_total,
				'processed' => 0,
				'status'    => 'pending',
			),
			'options' => array(
				'label'     => 'Options',
				'total'     => 1,
				'processed' => 0,
				'status'    => 'pending',
			),
			'news_import' => array(
				'label'     => 'News Import',
				'total'     => count( (array) ( $this->manifest['news_articles'] ?? array() ) ),
				'processed' => 0,
				'status'    => 'pending',
			),
			'finalize' => array(
				'label'     => 'Finalize',
				'total'     => 1,
				'processed' => 0,
				'status'    => 'pending',
			),
		);
	}

	/**
	 * Preflight step.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function perform_preflight_step( $job_state ) {
		$step_key  = 'preflight';
		$job_state = $this->mark_step_running( $job_state, $step_key, 'Checking importer prerequisites' );
		$blocking  = array();

		if ( empty( $this->manifest['pages'] ) || ! is_array( $this->manifest['pages'] ) ) {
			$blocking[] = 'Das Manifest enthaelt keine importierbaren Seiten.';
		}

		if ( '' === $this->source_root || ! is_dir( $this->source_root ) ) {
			$blocking[] = 'source_assets wurde nicht gefunden.';
		}

		if ( ! class_exists( 'Leadwerk_Content_Schema' ) ) {
			$blocking[] = 'Leadwerk_Content_Schema ist nicht geladen.';
		}

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			$blocking[] = 'Leadwerk Fields API ist nicht aktiv.';
		}

		if ( ! class_exists( 'Leadwerk_Translation_API' ) ) {
			$blocking[] = 'Leadwerk WPML Clone API ist nicht geladen.';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			$blocking[] = 'PHP-Extension dom (DOMDocument) fehlt — strukturierter Import und Validierung sind nicht verfuegbar.';
		}

		if ( defined( 'ACF_VERSION' ) || defined( 'ACF_PRO' ) ) {
			$blocking[] = 'Advanced Custom Fields ist aktiv. Bitte deaktivieren; Leadwerk Fields speichert in einem anderen Meta-Format.';
		}

		foreach ( (array) ( $this->manifest['pages'] ?? array() ) as $page_config ) {
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$source_file = (string) ( $page_config['source_file'] ?? '' );

			if ( '' === $field_name || ! Leadwerk_Content_Schema::get_group( $field_name ) ) {
				$blocking[] = 'Schema fehlt fuer ' . ( $page_config['source_key'] ?? 'unknown' ) . '.';
			}

			if ( '' === $source_file || ! is_file( $this->resolve_source_path( $source_file ) ) ) {
				$blocking[] = 'HTML-Datei fehlt: ' . $source_file;
			}
		}

		foreach ( (array) ( $this->manifest['news_articles'] ?? array() ) as $article ) {
			if ( ! is_array( $article ) ) {
				continue;
			}
			$source_file = (string) ( $article['source_file'] ?? '' );
			if ( '' === $source_file || ! preg_match( '/\.html$/i', $source_file ) ) {
				continue;
			}
			if ( ! is_file( $this->resolve_source_path( $source_file ) ) ) {
				$blocking[] = 'News-HTML fehlt: ' . $source_file;
			}
		}

		if ( ! empty( $blocking ) ) {
			foreach ( $blocking as $message ) {
				Leadwerk_Logger::record_result( 'error', $message, 'preflight' );
			}

			$job_state['results']['blocking'] = array_values( $blocking );
			$job_state['status']              = 'failed';
			$job_state = $this->mark_step_finished( $job_state, $step_key, 'failed', 'Preflight failed' );
			$job_state = $this->merge_runtime_job_state( $job_state );
			Leadwerk_Logger::finish_job(
				'failed',
				array(
					'steps'        => $job_state['steps'],
					'results'      => $job_state['results'],
					'current_step' => $step_key,
					'current_item' => 'Preflight failed',
				)
			);
			return Leadwerk_Logger::get_state();
		}

		$this->record_preflight_source_asset_warnings();
		$this->record_preflight_translation_seed_notice();

		Leadwerk_Logger::record_result( 'success', 'Preflight erfolgreich abgeschlossen.', 'preflight' );
		return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Preflight complete' );
	}

	/**
	 * Log non-blocking warnings when expected static bundles are missing under source_root.
	 *
	 * @return void
	 */
	protected function record_preflight_source_asset_warnings() {
		$root = $this->source_root;
		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}

		$fotos = $root . DIRECTORY_SEPARATOR . 'Fotos';
		if ( ! is_dir( $fotos ) ) {
			Leadwerk_Logger::record_result(
				'warning',
				'Ordner Fotos/ fehlt unter source_assets — HTML verweist typischerweise auf viele Medien; Medienimport bleibt duenn.',
				'preflight'
			);
		}

		$logo = $root . DIRECTORY_SEPARATOR . 'logo.png';
		if ( ! is_file( $logo ) ) {
			Leadwerk_Logger::record_result(
				'warning',
				'logo.png fehlt im Quellroot — Header/Footer-Logos im statischen HTML koennen nicht importiert werden.',
				'preflight'
			);
		}

		$theme_option_assets = array(
			'assets/images/Logo-final-weiss-rz_svg.svg',
			'assets/images/Logo-final-weiss-rz.png',
			'assets/images/Schriftzug.svg',
		);
		foreach ( $theme_option_assets as $rel ) {
			$full = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
			if ( ! is_file( $full ) ) {
				Leadwerk_Logger::record_result(
					'warning',
					'Theme-Option Medien fehlt: ' . $rel . ' (fill_options).',
					'preflight'
				);
			}
		}
	}

	/**
	 * Warn when EN seed file has no page entries (non-blocking).
	 *
	 * @return void
	 */
	protected function record_preflight_translation_seed_notice() {
		$path = $this->manifest_dir . 'translation-seeds.json';
		if ( ! is_file( $path ) ) {
			Leadwerk_Logger::record_result(
				'warning',
				'translation-seeds.json fehlt im manifest-Ordner — EN-Seeds nur optional; EN-Inhalte ggf. ueber Leadwerk WPML Clone pflegen.',
				'preflight'
			);
			return;
		}

		$json = file_get_contents( $path );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$pages = $data['pages'] ?? array();
		if ( empty( $pages ) || ! is_array( $pages ) ) {
			Leadwerk_Logger::record_result(
				'warning',
				'translation-seeds.json: pages leer — EN-Feldinhalte werden nicht aus Seeds vorbefuellt; Leadwerk WPML Clone oder spaetere Seeds nutzen.',
				'preflight'
			);
		}
	}

	/**
	 * Media scan step.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function perform_media_scan_step( $job_state ) {
		$step_key  = 'media_scan';
		$job_state = $this->mark_step_running( $job_state, $step_key, 'Scanning bundled assets' );

		$files = $this->collect_media_files( $this->source_root, '' );
		$job_state['queues']['media'] = array_values( $files );
		$job_state['steps']['media_import']['total'] = count( $files );
		$job_state['steps']['media_import']['status'] = empty( $files ) ? 'skipped' : 'pending';

		Leadwerk_Logger::log( 'Medien in source_assets: ' . count( $files ) . ' Datei(en)' );
		Leadwerk_Logger::record_result( 'success', 'Medienliste erstellt: ' . count( $files ) . ' Datei(en).', 'media-scan' );

		return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Media scan complete' );
	}

	/**
	 * Media import step.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_media_import_step( $job_state, $batch_size ) {
		$step_key = 'media_import';
		$queue    = array_values( (array) ( $job_state['queues']['media'] ?? array() ) );
		$total    = count( $queue );

		if ( 0 === $total ) {
			return $this->mark_step_finished( $job_state, $step_key, 'skipped', 'No media files to import' );
		}

		$cursor    = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$processed = 0;
		$job_state = $this->mark_step_running( $job_state, $step_key, 'Importing media assets' );

		while ( $cursor < $total && $processed < $batch_size ) {
			$relative_path = (string) $queue[ $cursor ];
			$job_state['current_item'] = $relative_path;

			$result_id = $this->media_importer ? (int) $this->media_importer->import_file( $relative_path ) : 0;
			if ( $this->dry_run || $result_id > 0 ) {
				Leadwerk_Logger::record_result( 'success', 'Medium verarbeitet: ' . $relative_path, $relative_path );
			} else {
				Leadwerk_Logger::record_result( 'warning', 'Medium konnte nicht importiert werden: ' . $relative_path, $relative_path );
			}

			$job_state['results']['media'][ $relative_path ] = array(
				'source_path'   => $relative_path,
				'attachment_id' => $result_id,
				'status'        => ( $this->dry_run || $result_id > 0 ) ? 'success' : 'warning',
			);

			++$cursor;
			++$processed;
			$job_state['cursor'][ $step_key ] = $cursor;
			$job_state['steps'][ $step_key ]['processed'] = $cursor;
		}

		if ( $cursor >= $total ) {
			return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Media import complete' );
		}

		return $job_state;
	}

	/**
	 * Page upsert step.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_page_upsert_step( $job_state, $batch_size ) {
		$step_key  = 'page_upsert';
		$pages     = array_values( (array) ( $job_state['queues']['pages'] ?? array() ) );
		$total     = count( $pages );
		$cursor    = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$processed = 0;

		$job_state = $this->mark_step_running( $job_state, $step_key, 'Creating or updating pages' );

		while ( $cursor < $total && $processed < $batch_size ) {
			$page_config = (array) $pages[ $cursor ];
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$job_state['current_item'] = (string) ( $page_config['source_file'] ?? $source_key );

			$de_result = $this->upsert_page( $page_config, 'de' );
			$en_result = $this->upsert_page( $page_config, 'en' );

			if ( ! empty( $de_result['post_id'] ) ) {
				$this->page_lookup['de'][ (string) $page_config['source_file'] ] = (int) $de_result['post_id'];
			}

			if ( ! empty( $en_result['post_id'] ) ) {
				$this->page_lookup['en'][ (string) $page_config['source_file'] ] = (int) $en_result['post_id'];
			}

			$job_state['results']['pages'][ $source_key ] = $this->merge_page_result(
				$job_state['results']['pages'][ $source_key ] ?? array(),
				array(
					'source_key' => $source_key,
					'title'      => (string) ( $page_config['title'] ?? '' ),
					'de'         => $de_result,
					'en'         => $en_result,
				)
			);

			if ( ! $this->dry_run && ! empty( $de_result['post_id'] ) && ! empty( $en_result['post_id'] ) && class_exists( 'Leadwerk_Translation_API' ) ) {
				Leadwerk_Translation_API::link_posts(
					(int) $de_result['post_id'],
					(int) $en_result['post_id'],
					'en',
					! empty( $page_config['is_front_page'] ),
					array(
						'source_key'         => $page_config['source_key'],
						'source_public_slug' => $this->get_public_slug( $page_config ),
						'source_is_home'     => ! empty( $page_config['is_front_page'] ),
						'public_slug'        => $this->get_translation_public_slug_for_link( $page_config, (int) $en_result['post_id'], 'en' ),
						'status'             => $this->get_translation_record_status( (int) $en_result['post_id'], 'en' ),
						'is_home'            => ! empty( $page_config['is_front_page'] ),
					)
				);
			}

			if ( ! empty( $de_result['post_id'] ) && ! empty( $en_result['post_id'] ) ) {
				Leadwerk_Logger::record_result( 'success', 'Seite vorbereitet: ' . ( $page_config['title'] ?? $source_key ), $source_key );
			} else {
				Leadwerk_Logger::record_result( 'error', 'Seite konnte nicht vorbereitet werden: ' . ( $page_config['title'] ?? $source_key ), $source_key );
			}

			++$cursor;
			++$processed;
			$job_state['cursor'][ $step_key ] = $cursor;
			$job_state['steps'][ $step_key ]['processed'] = $cursor;
		}

		if ( $cursor >= $total ) {
			$this->repair_leadwerk_attachment_slugs_and_registry();
			return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Page upsert complete' );
		}

		return $job_state;
	}

	/**
	 * After all pages exist: uniquify Leadwerk attachment slugs vs pages/CPTs, refresh translation registry.
	 *
	 * @return void
	 */
	protected function repair_leadwerk_attachment_slugs_and_registry() {
		if ( $this->dry_run || ! $this->media_importer instanceof Leadwerk_Media_Importer ) {
			return;
		}

		$changed = $this->media_importer->repair_all_imported_attachment_slugs();
		if ( empty( $changed ) ) {
			return;
		}

		if ( class_exists( 'Leadwerk_Translation_API' ) ) {
			foreach ( $changed as $attachment_id ) {
				Leadwerk_Translation_API::ensure_post_record( (int) $attachment_id );
			}
		}

		Leadwerk_Logger::log( sprintf( 'Attachment-Slugs nach Seiten-Upsert angepasst: %d Datei(en).', count( $changed ) ) );
	}

	/**
	 * Fill DE structured fields and validate readback.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_page_fill_de_step( $job_state, $batch_size ) {
		$step_key  = 'page_fill_de';
		$pages     = array_values( (array) ( $job_state['queues']['pages'] ?? array() ) );
		$total     = count( $pages );
		$cursor    = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$processed = 0;
		$filler    = $this->get_filler();

		$job_state = $this->mark_step_running( $job_state, $step_key, 'Filling German structured content' );

		while ( $cursor < $total && $processed < $batch_size ) {
			$page_config = (array) $pages[ $cursor ];
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$group       = Leadwerk_Content_Schema::get_group( $field_name );
			$de_id       = (int) ( $job_state['results']['pages'][ $source_key ]['de']['post_id'] ?? $this->find_page_by_source_key_and_lang( $source_key, 'de' ) );
			$page_result = $job_state['results']['pages'][ $source_key ] ?? array();

			$job_state['current_item'] = (string) ( $page_config['source_file'] ?? $source_key );

			if ( $de_id && is_array( $group ) && ! empty( $group['layouts'] ) && function_exists( 'get_field' ) ) {
				$expected_layouts = count( (array) $group['layouts'] );
				$acf_rows         = get_field( $field_name, $de_id );
				$row_count        = is_array( $acf_rows ) ? count( array_values( $acf_rows ) ) : 0;
				if ( $row_count > 0 && $row_count !== $expected_layouts ) {
					Leadwerk_Logger::log(
						sprintf(
							'ACF/Shell-Hinweis %s: %d Flexible-Layouts in der DB, Schema erwartet %d (Exact-Renderer pairt nach Index!).',
							$source_key,
							$row_count,
							$expected_layouts
						),
						'warning'
					);
				}
				$meta_sk = (string) get_post_meta( $de_id, 'leadwerk_source_key', true );
				if ( '' !== $source_key && $meta_sk !== $source_key ) {
					Leadwerk_Logger::log(
						sprintf(
							'Meta-Hinweis %s: leadwerk_source_key ist "%s", Manifest erwartet "%s".',
							$source_key,
							$meta_sk,
							$source_key
						),
						'warning'
					);
				}
			}

			$previous            = function_exists( 'get_field' ) && $de_id ? get_field( $field_name, $de_id ) : null;
			$previous_validation = $filler->validate_group_value( $field_name, $previous );

			if ( ! $de_id ) {
				$page_result['de']['field_status']  = 'error';
				$page_result['de']['field_message'] = 'Source page not found.';
				$page_result['de']['failure_reason'] = 'write_failed';
				$job_state['results']['pages'][ $source_key ] = $page_result;
				Leadwerk_Logger::record_result( 'error', 'DE-Seite fehlt fuer ' . $source_key, $source_key );
				++$cursor;
				++$processed;
				$job_state['cursor'][ $step_key ] = $cursor;
				$job_state['steps'][ $step_key ]['processed'] = $cursor;
				continue;
			}

			$payload          = $filler->build_page_payload( $page_config, 'de' );
			$validation       = (array) ( $payload['validation'] ?? array() );
			$layout_diagnostics = $this->compact_layout_diagnostics( (array) ( $payload['layout_diagnostics'] ?? array() ) );
			$payload_has_data = ! empty( $validation['has_visible_content'] );
			$current_status   = 'success';
			$current_message  = '';
			$current_reason   = '';
			$current_sync_status = '';
			$current_details  = array();
			$readback         = $previous;
			$readback_validation = $previous_validation;
			$stored_meta      = null;
			$write_guard      = $payload_has_data
				? $this->resolve_import_write_guard( $de_id, $field_name, $previous, $previous_validation, $payload['value'], $validation )
				: array();

			if ( $payload_has_data && ! empty( $write_guard['skip_write'] ) ) {
				$current_reason  = (string) ( $write_guard['reason'] ?? '' );
				$current_message = (string) ( $write_guard['message'] ?? 'Bestehender Structured Inhalt wurde beibehalten.' );
				$current_details = array(
					'write_guard' => array(
						'reason'              => $current_reason,
						'state_source'        => (string) ( $write_guard['state_source'] ?? '' ),
						'has_import_state'    => ! empty( $write_guard['has_import_state'] ),
						'bootstrap_persisted' => ! empty( $write_guard['persist_import_state'] ) && ! $this->dry_run,
					),
				);
				$group = is_array( $group ) ? $group : array();
				if ( ! empty( $group ) ) {
					$group['field_name'] = $field_name;
				}
				$current_render = ! empty( $previous_validation['has_visible_content'] ) && ! empty( $group )
					? $this->validate_exact_group_render( $group, $previous, $page_config, $de_id )
					: array();
				$source_drift = ! empty( $group )
					? $this->assess_canonical_source_drift( $page_config, $group, $previous, $previous_validation, $current_render, $de_id, $filler )
					: array();
				if ( ! empty( $source_drift['has_drift'] ) ) {
					$current_status      = 'warning';
					$current_reason      = 'source_drift';
					$current_sync_status = 'source_drift';
					$current_message     = 'Structured Inhalt wurde beibehalten, weicht aber vom Canonical Shell Source ab. Force Canonical Sync empfohlen.';
					$current_details['source_drift'] = $this->compact_source_drift( $source_drift );
					$current_details['write_guard']['bootstrap_persisted'] = false;
					$write_guard['persist_import_state'] = false;
				}

				if ( ! $this->dry_run && ! empty( $write_guard['persist_import_state'] ) ) {
					$this->save_last_good_snapshot( $de_id, $field_name, $previous, $previous_validation, 'readback' );
					$this->save_imported_field_state( $de_id, $field_name, $previous, (string) ( $write_guard['state_source'] ?? 'existing_synced' ) );
					$this->sync_post_content_if_needed( $de_id, $page_config, $previous );
				}
			} elseif ( $payload_has_data ) {
				if ( ! $this->dry_run ) {
					update_field( $field_name, $payload['value'], $de_id );
					$stored_meta          = get_post_meta( $de_id, $field_name, true );
					$readback            = get_field( $field_name, $de_id );
					$readback_validation = $filler->validate_group_value( $field_name, $readback );
				} else {
					$readback            = $payload['value'];
					$readback_validation = $validation;
				}

				if ( ! empty( $readback_validation['has_visible_content'] ) ) {
					$current_message = 'DE-Felder gefuellt.';
					if ( ! $this->dry_run ) {
						$this->save_last_good_snapshot( $de_id, $field_name, $readback, $readback_validation, 'readback' );
						$this->save_imported_field_state( $de_id, $field_name, $readback, 'import_readback' );
						$this->sync_post_content_if_needed( $de_id, $page_config, $readback );
						$this->refresh_translation_needs_from_source( $de_id );
					}
					$this->maybe_update_post_status( $de_id, $this->get_desired_source_post_status( $page_config ), ! empty( $page_result['de']['created_new'] ) );
				} else {
					$current_reason  = $this->determine_readback_failure_reason( $stored_meta, $readback );
					$current_status  = ! empty( $previous_validation['has_visible_content'] ) ? 'warning' : 'error';
					$current_message = $this->get_de_failure_message( $current_reason, ! empty( $previous_validation['has_visible_content'] ) );
					$current_details = $this->build_failure_details( $validation, $readback_validation, $layout_diagnostics, $stored_meta, $readback );

					if ( ! $this->dry_run && ! empty( $previous_validation['has_visible_content'] ) ) {
						update_field( $field_name, $previous, $de_id );
						$readback            = get_field( $field_name, $de_id );
						$readback_validation = $filler->validate_group_value( $field_name, $readback );
						$current_reason      = 'restored_previous';
						$current_message     = 'Schreiben fehlgeschlagen, vorheriger Inhalt wurde wiederhergestellt.';
						$current_details['restored_previous'] = true;
						if ( ! empty( $readback_validation['has_visible_content'] ) ) {
							$this->save_last_good_snapshot( $de_id, $field_name, $readback, $readback_validation, 'restored_previous' );
							$this->sync_post_content_if_needed( $de_id, $page_config, $readback );
						}
					} elseif ( ! $this->dry_run ) {
						$this->save_last_good_snapshot( $de_id, $field_name, $payload['value'], $validation, 'payload_fallback' );
						$this->force_draft( $de_id );
					}
				}
			} else {
				$current_reason  = $this->has_selector_miss_in_diagnostics( $layout_diagnostics ) ? 'selector_miss' : 'parser_empty';
				$current_status  = ! empty( $previous_validation['has_visible_content'] ) ? 'warning' : 'error';
				$current_message = $this->get_de_failure_message( $current_reason, ! empty( $previous_validation['has_visible_content'] ) );
				$current_details = $this->build_failure_details( $validation, $readback_validation, $layout_diagnostics, null, $readback );

				if ( ! $this->dry_run && empty( $previous_validation['has_visible_content'] ) ) {
					$this->force_draft( $de_id );
				}
			}

			if ( 'success' === $current_status ) {
				$current_reason = '';
			}

			$page_result['de'] = array_merge(
				(array) ( $page_result['de'] ?? array() ),
				array(
					'post_id'             => $de_id,
					'field_status'        => $current_status,
					'field_message'       => $current_message,
					'failure_reason'      => $current_reason,
					'sync_status'         => $current_sync_status,
					'failure_details'     => $current_details,
					'payload_validation'  => $this->compact_validation( $validation ),
					'readback_validation' => $this->compact_validation( $readback_validation ),
					'layout_diagnostics'  => $layout_diagnostics,
					'source_ready'        => ! empty( $readback_validation['has_visible_content'] ),
					'used_last_good_fallback' => false,
				)
			);
			$job_state['results']['pages'][ $source_key ] = $page_result;

			Leadwerk_Logger::record_result( $current_status, $page_config['title'] . ': ' . $current_message, $source_key );

			++$cursor;
			++$processed;
			$job_state['cursor'][ $step_key ] = $cursor;
			$job_state['steps'][ $step_key ]['processed'] = $cursor;
		}

		if ( $cursor >= $total ) {
			return $this->mark_step_finished( $job_state, $step_key, 'completed', 'DE fill complete' );
		}

		return $job_state;
	}

	/**
	 * Force one DE structured field group to use the canonical shell payload.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_force_canonical_sync_step( $job_state, $batch_size ) {
		$step_key  = 'force_canonical_sync';
		$pages     = array_values( (array) ( $job_state['queues']['pages'] ?? array() ) );
		$total     = count( $pages );
		$cursor    = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$processed = 0;
		$filler    = $this->get_filler();

		$job_state = $this->mark_step_running( $job_state, $step_key, 'Forcing canonical shell content into German structured fields' );

		while ( $cursor < $total && $processed < $batch_size ) {
			$page_config = (array) $pages[ $cursor ];
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$group       = Leadwerk_Content_Schema::get_group( $field_name );
			$de_id       = (int) ( $job_state['results']['pages'][ $source_key ]['de']['post_id'] ?? $this->find_page_by_source_key_and_lang( $source_key, 'de' ) );
			$page_result = $job_state['results']['pages'][ $source_key ] ?? array();

			$job_state['current_item'] = (string) ( $page_config['source_file'] ?? $source_key );

			if ( ! $de_id || ! is_array( $group ) || empty( $group['layouts'] ) ) {
				$page_result['de'] = array_merge(
					(array) ( $page_result['de'] ?? array() ),
					array(
						'post_id'        => $de_id,
						'field_status'   => 'error',
						'field_message'  => 'Canonical sync failed: DE page or structured layout group missing.',
						'failure_reason' => 'write_failed',
						'sync_status'    => 'force_canonical_sync',
					)
				);
				$job_state['results']['pages'][ $source_key ] = $page_result;
				Leadwerk_Logger::record_result( 'error', 'Canonical sync fehlgeschlagen fuer ' . $source_key . ': DE-Seite oder Layout-Gruppe fehlt.', $source_key . '-canonical-sync' );
				++$cursor;
				++$processed;
				$job_state['cursor'][ $step_key ] = $cursor;
				$job_state['steps'][ $step_key ]['processed'] = $cursor;
				continue;
			}

			$group['field_name'] = $field_name;
			$previous            = function_exists( 'get_field' ) ? get_field( $field_name, $de_id ) : null;
			$previous_validation = $filler->validate_group_value( $field_name, $previous );
			$payload             = $this->build_canonical_shell_payload( $page_config, 'de' );
			$payload_value       = $payload['value'] ?? array();
			$validation          = (array) ( $payload['validation'] ?? array() );
			$layout_diagnostics  = $this->compact_layout_diagnostics( (array) ( $payload['layout_diagnostics'] ?? array() ) );
			$payload_render      = $this->validate_exact_group_render( $group, $payload_value, $page_config, $de_id );
			$current_status      = 'success';
			$current_message     = '';
			$current_reason      = '';
			$current_details     = array(
				'canonical_source'   => (string) ( $payload['source'] ?? '' ),
				'payload_validation' => $this->compact_validation( $validation ),
				'payload_render'     => $this->compact_render_validation( $payload_render ),
			);
			$readback            = $previous;
			$readback_validation = $previous_validation;
			$readback_render     = array();

			if ( empty( $validation['has_visible_content'] ) || empty( $payload_render['is_valid'] ) ) {
				$current_status  = 'error';
				$current_reason  = empty( $validation['has_visible_content'] ) ? 'parser_empty' : 'render_empty';
				$current_message = 'Canonical shell payload ist nicht valide genug fuer einen sicheren Force Sync.';
			} elseif ( $this->dry_run ) {
				$readback            = $payload_value;
				$readback_validation = $validation;
				$readback_render     = $payload_render;
				$current_message     = 'Dry-run: Canonical shell payload wurde validiert, aber nicht geschrieben.';
			} else {
				update_field( $field_name, $payload_value, $de_id );
				$readback            = get_field( $field_name, $de_id );
				$readback_validation = $filler->validate_group_value( $field_name, $readback );
				$readback_render     = $this->validate_exact_group_render( $group, $readback, $page_config, $de_id );

				if ( ! empty( $readback_validation['has_visible_content'] ) && ! empty( $readback_render['is_valid'] ) ) {
					$this->save_last_good_snapshot( $de_id, $field_name, $readback, $readback_validation, 'force_canonical_sync' );
					$this->save_imported_field_state( $de_id, $field_name, $readback, 'force_canonical_sync' );
					$this->sync_post_content_if_needed( $de_id, $page_config, $readback );
					$this->refresh_translation_needs_from_source( $de_id );
					$this->maybe_update_post_status( $de_id, $this->get_desired_source_post_status( $page_config ), ! empty( $page_result['de']['created_new'] ) );
					$current_message = 'Canonical shell content synced into DE structured fields.';
				} else {
					$current_status  = ! empty( $previous_validation['has_visible_content'] ) ? 'warning' : 'error';
					$current_reason  = 'write_failed';
					$current_message = 'Canonical sync write/readback was not stable.';
					if ( ! empty( $previous_validation['has_visible_content'] ) ) {
						update_field( $field_name, $previous, $de_id );
						$readback            = get_field( $field_name, $de_id );
						$readback_validation = $filler->validate_group_value( $field_name, $readback );
						$readback_render     = $this->validate_exact_group_render( $group, $readback, $page_config, $de_id );
						$current_details['restored_previous'] = true;
						$current_message = 'Canonical sync write/readback failed; previous structured content was restored.';
					}
				}
			}

			$page_result['de'] = array_merge(
				(array) ( $page_result['de'] ?? array() ),
				array(
					'post_id'             => $de_id,
					'field_status'        => $current_status,
					'field_message'       => $current_message,
					'failure_reason'      => $current_reason,
					'sync_status'         => 'force_canonical_sync',
					'failure_details'     => $current_details,
					'payload_validation'  => $this->compact_validation( $validation ),
					'readback_validation' => $this->compact_validation( $readback_validation ),
					'layout_diagnostics'  => $layout_diagnostics,
					'canonical_render'    => $this->compact_render_validation( $payload_render ),
					'readback_render'     => $this->compact_render_validation( $readback_render ),
					'source_ready'        => ! empty( $readback_validation['has_visible_content'] ),
					'used_last_good_fallback' => false,
				)
			);
			$job_state['results']['pages'][ $source_key ] = $page_result;

			Leadwerk_Logger::record_result( $current_status, $page_config['title'] . ': ' . $current_message, $source_key . '-canonical-sync' );

			++$cursor;
			++$processed;
			$job_state['cursor'][ $step_key ] = $cursor;
			$job_state['steps'][ $step_key ]['processed'] = $cursor;
		}

		if ( $cursor >= $total ) {
			return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Canonical sync complete' );
		}

		return $job_state;
	}

	/**
	 * Apply page meta after field fill.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_page_meta_step( $job_state, $batch_size ) {
		$step_key  = 'page_meta';
		$pages     = array_values( (array) ( $job_state['queues']['pages'] ?? array() ) );
		$total     = count( $pages );
		$cursor    = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$processed = 0;
		$filler    = $this->get_filler();

		$job_state = $this->mark_step_running( $job_state, $step_key, 'Applying SEO and page meta' );

		while ( $cursor < $total && $processed < $batch_size ) {
			$page_config = (array) $pages[ $cursor ];
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$de_id       = (int) ( $job_state['results']['pages'][ $source_key ]['de']['post_id'] ?? 0 );
			$en_id       = (int) ( $job_state['results']['pages'][ $source_key ]['en']['post_id'] ?? 0 );

			$job_state['current_item'] = (string) ( $page_config['source_file'] ?? $source_key );

			if ( $de_id ) {
				$de_payload = $filler->build_page_payload( $page_config, 'de' );
				if ( ! $this->dry_run ) {
					$de_meta_result = $this->apply_page_meta( $de_id, $de_payload, $page_config, 'de' );
				} else {
					$de_meta_result = array(
						'body_class'       => $this->resolve_expected_body_class( $page_config, $de_payload ),
						'body_class_source'=> 'payload',
					);
				}
			} else {
				$de_meta_result = array();
			}

			if ( $en_id ) {
				$en_payload = $this->build_en_meta_payload( $page_config, $en_id );
				if ( ! $this->dry_run ) {
					$en_meta_result = $this->apply_page_meta( $en_id, $en_payload, $page_config, 'en' );
				} else {
					$en_meta_result = array(
						'body_class'       => $this->resolve_expected_body_class( $page_config, $en_payload ),
						'body_class_source'=> 'resolved_shell',
					);
				}
			} else {
				$en_meta_result = array();
			}

			$job_state['results']['pages'][ $source_key ] = $this->merge_page_result(
				$job_state['results']['pages'][ $source_key ] ?? array(),
				array(
					'meta_status'  => 'success',
					'meta_message' => 'Page meta applied.',
					'de'           => array(
						'body_class'        => (string) ( $de_meta_result['body_class'] ?? '' ),
						'body_class_source' => (string) ( $de_meta_result['body_class_source'] ?? '' ),
					),
					'en'           => array(
						'body_class'        => (string) ( $en_meta_result['body_class'] ?? '' ),
						'body_class_source' => (string) ( $en_meta_result['body_class_source'] ?? '' ),
					),
				)
			);

			Leadwerk_Logger::record_result( 'success', 'Meta verarbeitet: ' . ( $page_config['title'] ?? $source_key ), $source_key . '-meta' );

			++$cursor;
			++$processed;
			$job_state['cursor'][ $step_key ] = $cursor;
			$job_state['steps'][ $step_key ]['processed'] = $cursor;
		}

		if ( $cursor >= $total ) {
			return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Meta step complete' );
		}

		return $job_state;
	}

	/**
	 * Deprecated no-op kept for backward compatibility with older callers.
	 *
	 * Automatic EN syncing is intentionally disabled so manual translations
	 * entered through the translation editor are never overwritten by import runs.
	 *
	 * @param array<string,mixed> $job_state  State.
	 * @param int                 $batch_size Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_translation_sync_step( $job_state, $batch_size ) {
		$step_key = 'translation_sync_en';
		if ( isset( $job_state['steps'][ $step_key ] ) ) {
			$job_state['steps'][ $step_key ]['processed'] = (int) ( $job_state['steps'][ $step_key ]['total'] ?? 0 );
		}

		Leadwerk_Logger::log( 'Automatische EN-Synchronisierung ist deaktiviert. Manuelle Uebersetzungen bleiben unangetastet.' );
		return $this->mark_step_finished( $job_state, $step_key, 'skipped', 'Automatic EN sync disabled' );
	}

	/**
	 * Apply shared options and site identity.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function perform_options_step( $job_state ) {
		$step_key  = 'options';
		$job_state = $this->mark_step_running( $job_state, $step_key, 'Applying shared options' );

		if ( ! $this->dry_run ) {
			$this->apply_site_identity();
			$this->fill_options();
			$this->set_site_icon();
		}

		Leadwerk_Logger::record_result( 'success', 'Optionen verarbeitet.', 'options' );
		return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Options complete' );
	}

	/**
	 * Run only the options step (site identity, Leadwerk options, site icon) without preflight or other import steps.
	 *
	 * @return array<string,string>
	 */
	public function run_options_only() {
		if ( $this->dry_run ) {
			return array( 'status' => 'skipped' );
		}

		$this->apply_site_identity();
		$this->fill_options();
		$this->set_site_icon();

		Leadwerk_Logger::record_result( 'success', 'Nur Optionen importiert (Site-Identity, Felder, Site-Icon).', 'options-only' );

		return array( 'status' => 'completed' );
	}

	/**
	 * Final render-safety pass.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function perform_finalize_step( $job_state ) {
		$step_key  = 'finalize';
		$job_state = $this->mark_step_running( $job_state, $step_key, 'Verifying render readiness' );
		$filler    = $this->get_filler();

		foreach ( (array) ( $job_state['queues']['pages'] ?? array() ) as $page_config ) {
			$page_config = (array) $page_config;
			$source_key  = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
			$field_name  = (string) ( $page_config['field_name'] ?? '' );
			$de_id       = (int) ( $job_state['results']['pages'][ $source_key ]['de']['post_id'] ?? 0 );
			$en_id       = (int) ( $job_state['results']['pages'][ $source_key ]['en']['resolved_post_id'] ?? $job_state['results']['pages'][ $source_key ]['en']['post_id'] ?? 0 );
			if ( ! $de_id || ! function_exists( 'get_field' ) ) {
				continue;
			}

			$source_state        = $this->get_effective_field_state( $de_id, $field_name, $filler, array( 'readback', 'restored_previous', 'payload_fallback', 'force_canonical_sync' ) );
			$current_validation  = (array) ( $source_state['validation'] ?? array() );
			$uses_last_good      = ! empty( $source_state['used_last_good_fallback'] );

			$job_state['results']['pages'][ $source_key ]['de']['render_ready'] = ! empty( $current_validation['has_visible_content'] );
			$job_state['results']['pages'][ $source_key ]['de']['used_last_good_fallback'] = $uses_last_good;

			if ( empty( $current_validation['has_visible_content'] ) && ! $this->dry_run ) {
				$this->force_draft( $de_id );
				Leadwerk_Logger::record_result( 'warning', 'Leere Seite wurde aus Sicherheitsgruenden als Entwurf belassen: ' . ( $page_config['title'] ?? $source_key ), $source_key . '-finalize' );
			}

			foreach ( array( 'de' => $de_id, 'en' => $en_id ) as $lang_code => $page_id ) {
				if ( ! $page_id ) {
					continue;
				}

				$style_result = $this->ensure_page_style_contract( $page_id, $page_config );
				$job_state['results']['pages'][ $source_key ][ $lang_code ]['body_class']        = (string) ( $style_result['body_class'] ?? '' );
				$job_state['results']['pages'][ $source_key ][ $lang_code ]['body_class_source'] = (string) ( $style_result['source'] ?? '' );
				$job_state['results']['pages'][ $source_key ][ $lang_code ]['body_class_status'] = (string) ( $style_result['status'] ?? '' );

				if ( 'repaired' === ( $style_result['status'] ?? '' ) ) {
					Leadwerk_Logger::record_result( 'success', sprintf( 'Body class repaired for %s [%s]: %s', ( $page_config['title'] ?? $source_key ), strtoupper( $lang_code ), $style_result['body_class'] ), $source_key . '-' . $lang_code . '-style' );
				} elseif ( 'missing' === ( $style_result['status'] ?? '' ) ) {
					Leadwerk_Logger::record_result( 'warning', sprintf( 'Body class missing for %s [%s].', ( $page_config['title'] ?? $source_key ), strtoupper( $lang_code ) ), $source_key . '-' . $lang_code . '-style' );
				}
			}
		}

		$this->repair_leadwerk_attachment_slugs_and_registry();

		return $this->mark_step_finished( $job_state, $step_key, 'completed', 'Finalize complete' );
	}

	/**
	 * Complete the whole job.
	 *
	 * @param array<string,mixed> $job_state                    State.
	 * @param bool                $suppress_post_import_notice  Skip Permalink-Hinweis (z. B. Einzel-Reparatur).
	 * @return array<string,mixed>
	 */
	protected function complete_job( $job_state, $suppress_post_import_notice = false ) {
		$job_state = $this->merge_runtime_job_state( $job_state );
		$job_state = $this->refresh_processed_count( $job_state );
		$job_state['current_item'] = 'Import complete';
		$job_state['status']       = 'completed';
		$extra                     = array(
			'steps'         => $job_state['steps'],
			'results'       => $job_state['results'],
			'page_lookup'   => $this->page_lookup,
			'current_step'  => 'finalize',
			'current_item'  => 'Import complete',
			'processed'     => $job_state['processed'],
			'success_count' => $job_state['success_count'],
			'warning_count' => $job_state['warning_count'],
			'error_count'   => $job_state['error_count'],
		);
		if ( $suppress_post_import_notice ) {
			$extra['suppress_post_import_notice'] = true;
		}
		return Leadwerk_Logger::finish_job( 'completed', $extra );
	}

	/**
	 * Return the next unfinished step key.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return string
	 */
	protected function get_next_step_key( $job_state ) {
		foreach ( (array) ( $job_state['steps'] ?? array() ) as $step_key => $step ) {
			$status    = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			$total     = max( 1, (int) ( $step['total'] ?? 1 ) );
			$processed = (int) ( $step['processed'] ?? 0 );

			if ( in_array( $status, array( 'completed', 'skipped' ), true ) ) {
				continue;
			}

			if ( $processed >= $total && 'failed' !== $status ) {
				continue;
			}

			return (string) $step_key;
		}

		return '';
	}

	/**
	 * Restore runtime caches from persisted state.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return void
	 */
	protected function restore_runtime_from_state( $job_state ) {
		$page_lookup       = isset( $job_state['page_lookup'] ) && is_array( $job_state['page_lookup'] ) ? $job_state['page_lookup'] : array();
		$this->page_lookup = array(
			'de' => isset( $page_lookup['de'] ) && is_array( $page_lookup['de'] ) ? $page_lookup['de'] : array(),
			'en' => isset( $page_lookup['en'] ) && is_array( $page_lookup['en'] ) ? $page_lookup['en'] : array(),
		);
	}

	/**
	 * Persist runtime caches into the state array.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return void
	 */
	protected function persist_runtime_into_state( &$job_state ) {
		$job_state['page_lookup'] = $this->page_lookup;
	}

	/**
	 * Merge live logger counters and log tail back into the local job array.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function merge_runtime_job_state( $job_state ) {
		$live_state = Leadwerk_Logger::get_state();

		foreach ( array( 'success_count', 'warning_count', 'error_count', 'log_tail', 'started_at', 'finished_at' ) as $key ) {
			if ( array_key_exists( $key, $live_state ) ) {
				$job_state[ $key ] = $live_state[ $key ];
			}
		}

		if ( ! empty( $live_state['results']['summary'] ) && is_array( $live_state['results']['summary'] ) ) {
			$job_state['results']['summary'] = $live_state['results']['summary'];
		}

		return $job_state;
	}

	/**
	 * Mark one step as running.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @param string              $step_key  Step key.
	 * @param string              $item      Current item.
	 * @return array<string,mixed>
	 */
	protected function mark_step_running( $job_state, $step_key, $item = '' ) {
		$job_state['status']                       = 'running';
		$job_state['current_step']                 = $step_key;
		$job_state['current_item']                 = $item;
		$job_state['steps'][ $step_key ]['status'] = 'running';
		return $job_state;
	}

	/**
	 * Mark one step as finished.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @param string              $step_key  Step key.
	 * @param string              $status    Status.
	 * @param string              $item      Current item.
	 * @return array<string,mixed>
	 */
	protected function mark_step_finished( $job_state, $step_key, $status, $item = '' ) {
		$total = max( 1, (int) ( $job_state['steps'][ $step_key ]['total'] ?? 1 ) );
		$job_state['steps'][ $step_key ]['status']    = $status;
		$job_state['steps'][ $step_key ]['processed'] = $total;
		$job_state['current_step']                    = $step_key;
		$job_state['current_item']                    = $item;
		return $job_state;
	}

	/**
	 * Refresh total processed counter.
	 *
	 * @param array<string,mixed> $job_state State.
	 * @return array<string,mixed>
	 */
	protected function refresh_processed_count( $job_state ) {
		$processed = 0;
		foreach ( (array) ( $job_state['steps'] ?? array() ) as $step ) {
			$processed += (int) ( $step['processed'] ?? 0 );
		}

		$job_state['processed'] = $processed;
		return $job_state;
	}

	/**
	 * Return one filler instance.
	 *
	 * @return Leadwerk_ACF_Filler
	 */
	protected function get_filler() {
		if ( ! $this->filler instanceof Leadwerk_ACF_Filler ) {
			$this->filler = new Leadwerk_ACF_Filler( $this->source_root, $this->media_importer, $this->page_lookup );
		}

		return $this->filler;
	}

	/**
	 * Merge a page result patch.
	 *
	 * @param array<string,mixed> $existing Existing result.
	 * @param array<string,mixed> $patch    Patch.
	 * @return array<string,mixed>
	 */
	protected function merge_page_result( $existing, $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) ) {
				$existing[ $key ] = $this->merge_page_result( $existing[ $key ], $value );
				continue;
			}

			$existing[ $key ] = $value;
		}

		return $existing;
	}

	/**
	 * Compact verbose validation data for job storage.
	 *
	 * @param array<string,mixed> $validation Validation data.
	 * @return array<string,mixed>
	 */
	protected function compact_validation( $validation ) {
		return array(
			'has_visible_content'    => ! empty( $validation['has_visible_content'] ),
			'visible_content_score'  => (int) ( $validation['visible_content_score'] ?? 0 ),
			'expected_layout_count'  => (int) ( $validation['expected_layout_count'] ?? 0 ),
			'parsed_layout_count'    => (int) ( $validation['parsed_layout_count'] ?? 0 ),
			'parsed_section_count'   => (int) ( $validation['parsed_section_count'] ?? 0 ),
			'non_empty_layout_count' => (int) ( $validation['non_empty_layout_count'] ?? 0 ),
			'missing_sections'       => (int) ( $validation['missing_sections'] ?? 0 ),
			'empty_layouts'          => array_slice( array_values( array_unique( (array) ( $validation['empty_layouts'] ?? array() ) ) ), 0, 10 ),
			'empty_fields'           => array_slice( array_values( array_unique( (array) ( $validation['empty_fields'] ?? array() ) ) ), 0, 12 ),
		);
	}

	/**
	 * Compact exact-render validator details for storage and notices.
	 *
	 * @param array<string,mixed> $validation Render validation data.
	 * @return array<string,mixed>
	 */
	protected function compact_render_validation( $validation ) {
		return array(
			'is_valid'                    => ! empty( $validation['is_valid'] ),
			'render_has_visible_content'  => ! empty( $validation['render_has_visible_content'] ),
			'heading_block_descendants'   => (int) ( $validation['heading_block_descendants'] ?? 0 ),
			'nested_paragraphs'           => (int) ( $validation['nested_paragraphs'] ?? 0 ),
			'empty_headings'              => (int) ( $validation['empty_headings'] ?? 0 ),
			'misplaced_headline_patterns' => (int) ( $validation['misplaced_headline_patterns'] ?? 0 ),
			'missing_expected_headings'   => array_slice( array_values( array_unique( (array) ( $validation['missing_expected_headings'] ?? array() ) ) ), 0, 8 ),
		);
	}

	/**
	 * Compact canonical source drift diagnostics.
	 *
	 * @param array<string,mixed> $drift Drift diagnostics.
	 * @return array<string,mixed>
	 */
	protected function compact_source_drift( $drift ) {
		return array(
			'has_drift'            => ! empty( $drift['has_drift'] ),
			'canonical_source'     => (string) ( $drift['canonical_source'] ?? '' ),
			'current_signature'    => (string) ( $drift['current_signature'] ?? '' ),
			'canonical_signature'  => (string) ( $drift['canonical_signature'] ?? '' ),
			'canonical_validation' => $this->compact_validation( (array) ( $drift['canonical_validation'] ?? array() ) ),
			'canonical_render'     => $this->compact_render_validation( (array) ( $drift['canonical_render'] ?? array() ) ),
		);
	}

	/**
	 * Compact layout diagnostics for job storage.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Raw diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	protected function compact_layout_diagnostics( $diagnostics ) {
		$out = array();
		foreach ( array_values( (array) $diagnostics ) as $diagnostic ) {
			$out[] = array(
				'layout_key'                 => (string) ( $diagnostic['layout_key'] ?? '' ),
				'label'                      => (string) ( $diagnostic['label'] ?? '' ),
				'matched_by'                 => (string) ( $diagnostic['matched_by'] ?? 'index' ),
				'selector_used'              => (string) ( $diagnostic['selector_used'] ?? '' ),
				'source_index'               => (int) ( $diagnostic['source_index'] ?? 0 ),
				'found'                      => ! empty( $diagnostic['found'] ),
				'layout_has_visible_content' => ! empty( $diagnostic['layout_has_visible_content'] ),
				'visible_content_score'      => (int) ( $diagnostic['visible_content_score'] ?? 0 ),
				'empty_fields'               => array_slice( array_values( array_unique( (array) ( $diagnostic['empty_fields'] ?? array() ) ) ), 0, 8 ),
			);
		}

		return $out;
	}

	/**
	 * Whether diagnostics contain a selector miss.
	 *
	 * @param array<int,array<string,mixed>> $layout_diagnostics Layout diagnostics.
	 * @return bool
	 */
	protected function has_selector_miss_in_diagnostics( $layout_diagnostics ) {
		foreach ( (array) $layout_diagnostics as $diagnostic ) {
			if ( in_array( (string) ( $diagnostic['matched_by'] ?? '' ), array( 'selector_miss', 'missing' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide why the readback failed.
	 *
	 * @param mixed $stored_meta Stored raw meta value.
	 * @param mixed $readback    Readback through get_field().
	 * @return string
	 */
	protected function determine_readback_failure_reason( $stored_meta, $readback ) {
		if ( ! $this->meta_value_has_data( $stored_meta ) ) {
			return 'write_failed';
		}

		if ( ! $this->meta_value_has_data( $readback ) ) {
			return 'readback_empty';
		}

		return 'render_empty';
	}

	/**
	 * Build a human-readable DE failure message.
	 *
	 * @param string $reason            Failure reason.
	 * @param bool   $has_previous_data Whether existing content was preserved.
	 * @return string
	 */
	protected function get_de_failure_message( $reason, $has_previous_data = false ) {
		switch ( sanitize_key( (string) $reason ) ) {
			case 'selector_miss':
				return $has_previous_data
					? 'Parser konnte nicht alle erwarteten Sektionen zuordnen, bestehender Inhalt wurde beibehalten.'
					: 'Parser konnte nicht alle erwarteten Sektionen zuordnen, Seite bleibt im Entwurfsstatus.';
			case 'write_failed':
				return $has_previous_data
					? 'Structured Inhalt konnte nicht gespeichert werden, bestehender Inhalt wurde beibehalten.'
					: 'Structured Inhalt konnte nicht gespeichert werden, Seite bleibt im Entwurfsstatus.';
			case 'readback_empty':
				return $has_previous_data
					? 'Structured Inhalt wurde geschrieben, aber leer zurueckgelesen. Bestehender Inhalt wurde beibehalten.'
					: 'Structured Inhalt wurde geschrieben, aber leer zurueckgelesen. Seite bleibt im Entwurfsstatus.';
			case 'render_empty':
				return $has_previous_data
					? 'Structured Inhalt ist vorhanden, liefert aber keine sichtbare Ausgabe. Bestehender Inhalt wurde beibehalten.'
					: 'Structured Inhalt liefert keine sichtbare Ausgabe, Seite bleibt im Entwurfsstatus.';
			case 'parser_empty':
			default:
				return $has_previous_data
					? 'Extraktion leer, bestehender Inhalt wurde beibehalten.'
					: 'Extraktion leer, Seite bleibt im Entwurfsstatus.';
		}
	}

	/**
	 * Build a compact failure detail payload.
	 *
	 * @param array<string,mixed>         $payload_validation  Payload validation.
	 * @param array<string,mixed>         $readback_validation Readback validation.
	 * @param array<int,array<string,mixed>> $layout_diagnostics Layout diagnostics.
	 * @param mixed                       $stored_meta         Stored raw meta.
	 * @param mixed                       $readback            Readback.
	 * @return array<string,mixed>
	 */
	protected function build_failure_details( $payload_validation, $readback_validation, $layout_diagnostics, $stored_meta = null, $readback = null ) {
		$matched_sections = 0;
		foreach ( (array) $layout_diagnostics as $diagnostic ) {
			if ( ! empty( $diagnostic['found'] ) ) {
				++$matched_sections;
			}
		}

		return array(
			'expected_sections' => (int) ( $payload_validation['expected_layout_count'] ?? 0 ),
			'matched_sections'  => $matched_sections,
			'non_empty_layouts' => max(
				(int) ( $payload_validation['non_empty_layout_count'] ?? 0 ),
				(int) ( $readback_validation['non_empty_layout_count'] ?? 0 )
			),
			'empty_layouts'     => array_slice(
				array_values(
					array_unique(
						array_merge(
							(array) ( $payload_validation['empty_layouts'] ?? array() ),
							(array) ( $readback_validation['empty_layouts'] ?? array() )
						)
					)
				),
				0,
				8
			),
			'storage_has_data'  => $this->meta_value_has_data( $stored_meta ),
			'readback_has_data' => $this->meta_value_has_data( $readback ),
		);
	}

	/**
	 * Whether one meta/readback value contains any data.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function meta_value_has_data( $value ) {
		if ( is_numeric( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->meta_value_has_data( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return the snapshot meta key for one structured field.
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	protected function get_last_good_meta_key( $field_name ) {
		return '_leadwerk_last_good_' . sanitize_key( (string) $field_name );
	}

	/**
	 * Persist the last known good structured payload.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param string              $field_name  Field name.
	 * @param mixed               $value       Snapshot value.
	 * @param array<string,mixed> $validation  Validation state.
	 * @param string              $source      Snapshot source.
	 * @return void
	 */
	protected function save_last_good_snapshot( $post_id, $field_name, $value, $validation, $source = 'readback' ) {
		if ( ! $post_id ) {
			return;
		}

		update_post_meta(
			(int) $post_id,
			$this->get_last_good_meta_key( $field_name ),
			array(
				'source'     => sanitize_key( (string) $source ),
				'saved_at'   => current_time( 'mysql', true ),
				'value'      => $value,
				'validation' => $this->compact_validation( (array) $validation ),
			)
		);
	}

	/**
	 * Read one last-good snapshot.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $field_name Field name.
	 * @return array<string,mixed>
	 */
	protected function get_last_good_snapshot( $post_id, $field_name ) {
		$snapshot = get_post_meta( (int) $post_id, $this->get_last_good_meta_key( $field_name ), true );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Return the importer state meta key for one structured field.
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	protected function get_import_state_meta_key( $field_name ) {
		return '_leadwerk_import_state_' . sanitize_key( (string) $field_name );
	}

	/**
	 * Persist the importer-owned signature for one structured field.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $field_name Field name.
	 * @param mixed  $value      Stored value.
	 * @param string $source     State source label.
	 * @return void
	 */
	protected function save_imported_field_state( $post_id, $field_name, $value, $source = 'import_readback' ) {
		if ( ! $post_id ) {
			return;
		}

		update_post_meta(
			(int) $post_id,
			$this->get_import_state_meta_key( $field_name ),
			array(
				'source'    => sanitize_key( (string) $source ),
				'saved_at'  => current_time( 'mysql', true ),
				'signature' => $this->build_field_signature( $value ),
			)
		);
	}

	/**
	 * Read the importer-owned signature state for one structured field.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $field_name Field name.
	 * @return array<string,mixed>
	 */
	protected function get_imported_field_state( $post_id, $field_name ) {
		$state = get_post_meta( (int) $post_id, $this->get_import_state_meta_key( $field_name ), true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Decide whether the importer should skip one field write to preserve manual edits.
	 *
	 * @param int                 $post_id            Post ID.
	 * @param string              $field_name         Field name.
	 * @param mixed               $current_value      Existing field value.
	 * @param array<string,mixed> $current_validation Existing validation.
	 * @param mixed               $payload_value      New payload value.
	 * @param array<string,mixed> $payload_validation Payload validation.
	 * @return array<string,mixed>
	 */
	protected function resolve_import_write_guard( $post_id, $field_name, $current_value, $current_validation, $payload_value, $payload_validation ) {
		$current_has_data = ! empty( $current_validation['has_visible_content'] );
		$payload_has_data = ! empty( $payload_validation['has_visible_content'] );
		$current_sig      = $current_has_data ? $this->build_field_signature( $current_value ) : '';
		$payload_sig      = $payload_has_data ? $this->build_field_signature( $payload_value ) : '';
		$import_state     = $this->get_imported_field_state( $post_id, $field_name );
		$import_sig       = (string) ( $import_state['signature'] ?? '' );

		if ( ! $current_has_data || ! $payload_has_data ) {
			return array(
				'skip_write'           => false,
				'reason'               => '',
				'message'              => '',
				'state_source'         => '',
				'has_import_state'     => ! empty( $import_sig ),
				'persist_import_state' => false,
			);
		}

		if ( '' !== $current_sig && '' !== $payload_sig && $current_sig === $payload_sig ) {
			return array(
				'skip_write'           => true,
				'reason'               => 'already_synced',
				'message'              => 'Structured Inhalt ist bereits aktuell; kein Re-Import noetig.',
				'state_source'         => 'existing_synced',
				'has_import_state'     => ! empty( $import_sig ),
				'persist_import_state' => ( '' === $import_sig || $import_sig !== $current_sig ),
			);
		}

		if ( '' !== $import_sig && '' !== $current_sig && $current_sig !== $import_sig ) {
			return array(
				'skip_write'           => true,
				'reason'               => 'manual_edit_preserved',
				'message'              => 'Manuell bearbeiteter Structured Inhalt wurde beibehalten; Import fuer dieses Feld uebersprungen.',
				'state_source'         => (string) ( $import_state['source'] ?? '' ),
				'has_import_state'     => true,
				'persist_import_state' => false,
			);
		}

		if ( '' === $import_sig && '' !== $current_sig && '' !== $payload_sig && $current_sig !== $payload_sig ) {
			$current_ne = (int) ( $current_validation['non_empty_layout_count'] ?? 0 );
			$payload_ne = (int) ( $payload_validation['non_empty_layout_count'] ?? 0 );
			if ( $payload_ne <= $current_ne ) {
				return array(
					'skip_write'           => true,
					'reason'               => 'manual_edit_preserved_legacy',
					'message'              => 'Bestehender Structured Inhalt weicht vom bisherigen Import-Stand ab und wurde beibehalten.',
					'state_source'         => '',
					'has_import_state'     => false,
					'persist_import_state' => false,
				);
			}
		}

		return array(
			'skip_write'           => false,
			'reason'               => '',
			'message'              => '',
			'state_source'         => (string) ( $import_state['source'] ?? '' ),
			'has_import_state'     => ! empty( $import_sig ),
			'persist_import_state' => false,
		);
	}

	/**
	 * Build a stable signature for one structured field value.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	protected function build_field_signature( $value ) {
		$encoded = wp_json_encode(
			$this->normalize_value_for_signature( $value ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return is_string( $encoded ) ? sha1( $encoded ) : '';
	}

	/**
	 * Normalize one structured value into a stable signature payload.
	 *
	 * @param mixed $value Field value.
	 * @return mixed
	 */
	protected function normalize_value_for_signature( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = array();

			if ( $this->is_list_array( $value ) ) {
				foreach ( $value as $item ) {
					$normalized[] = $this->normalize_value_for_signature( $item );
				}
				return $normalized;
			}

			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );

			foreach ( $keys as $key ) {
				$normalized[ (string) $key ] = $this->normalize_value_for_signature( $value[ $key ] );
			}

			return $normalized;
		}

		if ( is_string( $value ) ) {
			return trim( $value );
		}

		return $value;
	}

	/**
	 * Determine whether an array is a list.
	 *
	 * @param array<mixed> $value Array value.
	 * @return bool
	 */
	protected function is_list_array( $value ) {
		$expected = 0;
		foreach ( array_keys( (array) $value ) as $key ) {
			if ( $expected !== $key ) {
				return false;
			}
			++$expected;
		}

		return true;
	}

	/**
	 * Resolve the effective source field state, optionally using a snapshot.
	 *
	 * @param int                  $post_id          Post ID.
	 * @param string               $field_name       Field name.
	 * @param Leadwerk_ACF_Filler  $filler           Filler instance.
	 * @param string[]             $allowed_sources  Snapshot sources that may be used.
	 * @return array<string,mixed>
	 */
	protected function get_effective_field_state( $post_id, $field_name, $filler, $allowed_sources = array( 'readback', 'restored_previous', 'payload_fallback' ) ) {
		$value      = function_exists( 'get_field' ) ? get_field( $field_name, $post_id ) : null;
		$validation = $filler->validate_group_value( $field_name, $value );

		if ( ! empty( $validation['has_visible_content'] ) ) {
			return array(
				'value'                   => $value,
				'validation'              => $validation,
				'used_last_good_fallback' => false,
				'snapshot_source'         => '',
			);
		}

		$snapshot = $this->get_last_good_snapshot( $post_id, $field_name );
		$source   = (string) ( $snapshot['source'] ?? '' );
		if ( empty( $snapshot['value'] ) || ! in_array( $source, (array) $allowed_sources, true ) ) {
			return array(
				'value'                   => $value,
				'validation'              => $validation,
				'used_last_good_fallback' => false,
				'snapshot_source'         => $source,
			);
		}

		$snapshot_validation = $filler->validate_group_value( $field_name, $snapshot['value'] );
		if ( empty( $snapshot_validation['has_visible_content'] ) ) {
			return array(
				'value'                   => $value,
				'validation'              => $validation,
				'used_last_good_fallback' => false,
				'snapshot_source'         => $source,
			);
		}

		return array(
			'value'                   => $snapshot['value'],
			'validation'              => $snapshot_validation,
			'used_last_good_fallback' => true,
			'snapshot_source'         => $source,
		);
	}

	/**
	 * Keep legal post content synchronized after importer writes.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param array<string,mixed> $page_config Page config.
	 * @param mixed               $value       Structured group value.
	 * @return void
	 */
	protected function sync_post_content_if_needed( $post_id, $page_config, $value ) {
		if ( ! $post_id || ! $this->is_legal_page( $page_config ) || ! is_array( $value ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => $this->build_legal_post_content( $value ),
			)
		);
	}

	/**
	 * Refresh translation bundle/status for every linked target after DE source changes.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return void
	 */
	protected function refresh_translation_needs_from_source( $source_post_id ) {
		$source_post_id = (int) $source_post_id;
		if ( $this->dry_run || $source_post_id < 1 || ! class_exists( 'Leadwerk_Translation_API' ) ) {
			return;
		}

		if ( class_exists( 'Leadwerk_Translation_Sync' ) && method_exists( 'Leadwerk_Translation_Sync', 'refresh_all_bundles_from_source' ) ) {
			Leadwerk_Translation_Sync::refresh_all_bundles_from_source( $source_post_id );
			return;
		}

		$default_lang = Leadwerk_Translation_API::get_default_language();
		foreach ( Leadwerk_Translation_API::get_translations( $source_post_id ) as $lang => $translation_id ) {
			if ( $default_lang === $lang || (int) $translation_id === $source_post_id ) {
				continue;
			}

			Leadwerk_Translation_API::update_post_translation_status( (int) $translation_id, 'needs_update' );
		}
	}

	/**
	 * Build the legal page HTML synced into post_content.
	 *
	 * @param array<string,mixed> $value Structured value.
	 * @return string
	 */
	protected function build_legal_post_content( $value ) {
		$headline = trim( (string) ( $value['headline'] ?? '' ) );
		$content  = (string) ( $value['content'] ?? '' );

		return sprintf(
			'<section class="content-section content-section--white legal-content"><div class="container--narrow"><h1 class="legal-title anim">%1$s</h1><div class="legal-body anim" style="animation-delay:100ms">%2$s</div></div></section>',
			$headline,
			$content
		);
	}

	/**
	 * Create or update a page for one language.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $lang        Language code.
	 * @return array<string,mixed>
	 */
	protected function upsert_page( $page_config, $lang ) {
		$title      = 'en' === $lang ? $this->get_translated_title( $page_config ) : ( $page_config['title'] ?? 'Untitled' );
		$title      = trim( html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $title ) {
			$title = 'Untitled';
		}
		$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
		$slug       = $this->build_internal_slug( $page_config, $lang, $title );
		$existing   = $this->find_page_by_source_key_and_lang( $source_key, $lang, (string) ( $page_config['target_type'] ?? 'page' ) );
		$status     = $this->get_initial_post_status( $page_config, $lang, $existing );
		$result     = array(
			'post_id'     => 0,
			'lang'        => $lang,
			'created_new' => false,
			'existing'    => (bool) $existing,
			'post_status' => $status,
			'title'       => $title,
		);

		$post_data = array(
			'post_type'    => $page_config['target_type'] ?? 'page',
			'post_status'  => $status,
			'post_parent'  => 0,
			'post_content' => $this->get_default_block_content(),
			'menu_order'   => isset( $page_config['menu_order'] ) ? (int) $page_config['menu_order'] : 0,
		);

		if ( ! $existing || 'en' !== $lang ) {
			$post_data['post_name'] = $slug;
		} else {
			$result['preserved_slug'] = (string) get_post_field( 'post_name', $existing );
		}

		if ( 'de' === $lang || ! $existing ) {
			$post_data['post_title'] = $title;
		}

		if ( $existing ) {
			$post_data['ID']  = $existing;
			$result['post_id'] = (int) $existing;

			if ( ! $this->dry_run ) {
				wp_update_post( $post_data );
				update_post_meta( $existing, 'leadwerk_source_key', $source_key );
				update_post_meta( $existing, 'leadwerk_lang', $lang );
				if ( 'de' === $lang && ! empty( $page_config['is_front_page'] ) ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', (int) $existing );
				}
				Leadwerk_Logger::log( sprintf( 'Page aktualisiert: %s [%s] (ID %d)', $title, $lang, $existing ) );
			} else {
				Leadwerk_Logger::log( sprintf( 'Page wuerde aktualisiert: %s [%s] (ID %d)', $title, $lang, $existing ) );
			}

			return $result;
		}

		$result['created_new'] = true;

		if ( $this->dry_run ) {
			Leadwerk_Logger::log( sprintf( 'Page wuerde angelegt: %s [%s]', $title, $lang ) );
			return $result;
		}

		$id = wp_insert_post( $post_data );
		if ( ! $id || is_wp_error( $id ) ) {
			Leadwerk_Logger::log( sprintf( 'Fehler beim Anlegen: %s [%s]', $title, $lang ), 'error' );
			return $result;
		}

		update_post_meta( $id, 'leadwerk_source_key', $source_key );
		update_post_meta( $id, 'leadwerk_lang', $lang );

		if ( 'de' === $lang && ! empty( $page_config['is_front_page'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $id );
		}

		Leadwerk_Logger::log( sprintf( 'Page angelegt: %s [%s] (ID %d)', $title, $lang, $id ) );
		$result['post_id'] = (int) $id;
		return $result;
	}

	/**
	 * Truncate SEO title for Yoast width hints (matches theme helper when available).
	 *
	 * @param string $title      Document title.
	 * @param int    $max_chars  Max characters before ellipsis.
	 * @return string
	 */
	protected function truncate_seo_title_for_yoast( $title, $max_chars = 58 ) {
		if ( function_exists( 'leadwerk_theme_truncate_seo_title_for_yoast' ) ) {
			return leadwerk_theme_truncate_seo_title_for_yoast( $title, $max_chars );
		}

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
	 * Apply page meta and SEO.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param array<string,mixed> $payload     Built payload.
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $lang        Language code.
	 * @return void
	 */
	protected function apply_page_meta( $post_id, $payload, $page_config, $lang ) {
		$body_class        = $this->resolve_expected_body_class( $page_config, $payload );
		$body_class_source = '';
		if ( '' !== $body_class ) {
			$body_class_source = '' !== trim( (string) ( $payload['body_class'] ?? '' ) ) ? 'payload' : 'resolved_shell';
			update_post_meta( $post_id, 'leadwerk_body_class', sanitize_text_field( $body_class ) );
		}

		if ( array_key_exists( 'meta_description', $payload ) && '' !== trim( (string) $payload['meta_description'] ) ) {
			update_post_meta( $post_id, 'leadwerk_meta_description', sanitize_text_field( $payload['meta_description'] ) );
		}

		if ( array_key_exists( 'document_title', $payload ) && '' !== trim( (string) $payload['document_title'] ) ) {
			update_post_meta( $post_id, 'leadwerk_document_title', sanitize_text_field( $payload['document_title'] ) );
		}

		update_post_meta( $post_id, 'leadwerk_source_file', (string) $page_config['source_file'] );

		if ( $this->is_legal_page( $page_config ) ) {
			update_post_meta( $post_id, 'leadwerk_meta_robots', 'noindex, follow' );
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		}

		if ( ! empty( $payload['meta_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $payload['meta_description'] ) );
		}

		if ( ! empty( $payload['document_title'] ) ) {
			$seo_title = $this->truncate_seo_title_for_yoast( (string) $payload['document_title'] );
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $seo_title ) );
		}

		$focus = '';
		if ( 'en' === $lang ) {
			$focus = trim( (string) ( $page_config['focus_keyphrase_en'] ?? '' ) );
		}
		if ( '' === $focus ) {
			$focus = trim( (string) ( $page_config['focus_keyphrase'] ?? '' ) );
		}
		if ( '' !== $focus ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $focus ) );
		}

		if ( ! $this->dry_run ) {
			$this->maybe_rebuild_yoast_indexable( $post_id );
		}

		return array(
			'body_class'        => $body_class,
			'body_class_source' => $body_class_source,
		);
	}

	/**
	 * Refresh Yoast indexables after meta import.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	protected function maybe_rebuild_yoast_indexable( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		if ( function_exists( 'leadwerk_theme_rebuild_yoast_post_indexable' ) ) {
			leadwerk_theme_rebuild_yoast_post_indexable( $post_id );
			return;
		}

		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( '\Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher', false ) ) {
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
	 * Recursively collect importable media files.
	 *
	 * @param string $dir  Directory.
	 * @param string $base Base path.
	 * @return string[]
	 */
	protected function collect_media_files( $dir, $base ) {
		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'mp4', 'webm', 'mov', 'mp3', 'wav', 'ico', 'woff', 'woff2' );
		$out     = array();

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return $out;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full = $dir . DIRECTORY_SEPARATOR . $item;
			$rel  = '' === $base ? $item : $base . '/' . $item;

			if ( is_dir( $full ) ) {
				$out = array_merge( $out, $this->collect_media_files( $full, $rel ) );
				continue;
			}

			$ext = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $allowed, true ) ) {
				$out[] = $rel;
			}
		}

		return $out;
	}

	/**
	 * Fill theme options.
	 *
	 * @return void
	 */
	protected function fill_options() {
		if ( ! class_exists( 'Leadwerk_Fields_API' ) && ! function_exists( 'update_field' ) ) {
			return;
		}

		$option_map = array(
			'header_logo'     => 'assets/images/Logo-final-weiss-rz_svg.svg',
			'footer_logo'     => 'assets/images/Logo-final-weiss-rz.png',
			'footer_wordmark' => 'assets/images/Schriftzug.svg',
		);

		foreach ( $option_map as $field_name => $source_path ) {
			$attachment_id = $this->resolve_attachment( $source_path );
			if ( $attachment_id && ! $this->leadwerk_get_option( $field_name ) ) {
				$this->leadwerk_update_option( $field_name, $attachment_id );
			}
		}

		if ( ! $this->leadwerk_get_option( 'company_address' ) ) {
			$this->leadwerk_update_option( 'company_address', "ACM AIR CHARTER Luftfahrtgesellschaft mbH\nMontreal Ave. D415\n77836 Rheinmünster" );
		}

		if ( ! $this->leadwerk_get_option( 'company_phone' ) ) {
			$this->leadwerk_update_option( 'company_phone', '+49 7229 3022-0' );
		}

		if ( ! $this->leadwerk_get_option( 'company_email' ) ) {
			$this->leadwerk_update_option( 'company_email', 'info@acm.aero' );
		}

		if ( ! $this->leadwerk_get_option( 'google_maps_url' ) ) {
			$this->leadwerk_update_option( 'google_maps_url', 'https://www.google.com/maps/dir/?api=1&destination=Montreal%20Ave.%20D415%2C%2077836%20Rheinm%C3%BCnster%2C%20Deutschland' );
		}

		if ( ! $this->leadwerk_get_option( 'wpforms_form_id_de' ) ) {
			$this->leadwerk_update_option( 'wpforms_form_id_de', '' );
		}

		if ( ! $this->leadwerk_get_option( 'wpforms_form_id_en' ) ) {
			$this->leadwerk_update_option( 'wpforms_form_id_en', '' );
		}

		if ( ! $this->leadwerk_get_option( 'theme_strings_de' ) ) {
			$this->leadwerk_update_option( 'theme_strings_de', wp_json_encode( $this->get_default_theme_strings( 'de' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		}

		if ( ! $this->leadwerk_get_option( 'theme_strings_en' ) ) {
			$this->leadwerk_update_option( 'theme_strings_en', wp_json_encode( $this->get_default_theme_strings( 'en' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		}

		$this->fill_starlink_modal_options_if_empty();
	}

	/**
	 * Seed Leadwerk Optionen „Starlink Modal“ when empty (dedicated fields + optional image attachment).
	 * Texte: zuerst aus theme_strings_de (gleiche Keys wie im Theme), sonst Shell-Defaults.
	 *
	 * @return void
	 */
	protected function fill_starlink_modal_options_if_empty() {
		$defaults = array(
			'starlink_modal_title'     => 'ACM – Authorized Reseller für Starlink.',
			'starlink_modal_image_alt' => 'Starlink Antenne auf Business-Jet – ACM Authorized Reseller',
			'starlink_modal_badge'     => 'Starlink Authorized Reseller',
			'starlink_modal_headline'  => 'Ihr Starlink-Partner für die Business Aviation.',
			'starlink_modal_teaser'    => 'Starlink ist das Satelliten-Internet von SpaceX speziell für Business Jets: hohe Geschwindigkeit, 4K-Streaming und stabile Verbindung weltweit – auch über den Polarrouten. ACM ist autorisierter Reseller und Installationspartner.',
			'starlink_modal_cta_label' => 'Installation anfragen',
		);

		$from_strings = array(
			'starlink_modal_title'     => 'starlink_modal_title',
			'starlink_modal_image_alt' => 'starlink_image_alt',
			'starlink_modal_badge'     => 'starlink_badge',
			'starlink_modal_headline'  => 'starlink_headline',
			'starlink_modal_teaser'    => 'starlink_teaser',
			'starlink_modal_cta_label' => 'starlink_cta_label',
		);

		foreach ( $from_strings as $option_name => $theme_string_key ) {
			if ( '' !== trim( (string) $this->leadwerk_get_option( $option_name ) ) ) {
				continue;
			}
			$candidate = $this->get_theme_string_value_from_stored_json( 'de', $theme_string_key );
			if ( '' !== $candidate ) {
				$this->leadwerk_update_option( $option_name, $candidate );
				continue;
			}
			if ( isset( $defaults[ $option_name ] ) ) {
				$this->leadwerk_update_option( $option_name, $defaults[ $option_name ] );
			}
		}

		$img = $this->leadwerk_get_option( 'starlink_modal_image' );
		if ( null === $img || '' === $img || ( is_numeric( $img ) && (int) $img <= 0 ) ) {
			$attachment_id = $this->resolve_attachment( 'Fotos/starlink-popup.png' );
			if ( $attachment_id ) {
				$this->leadwerk_update_option( 'starlink_modal_image', $attachment_id );
			}
		}
	}

	/**
	 * Read a single key from stored theme_strings_de / theme_strings_en JSON option.
	 *
	 * @param string $lang         'de' or 'en'.
	 * @param string $string_key   Key inside the JSON object.
	 * @return string
	 */
	protected function get_theme_string_value_from_stored_json( $lang, $string_key ) {
		$opt_name = ( 'en' === $lang ) ? 'theme_strings_en' : 'theme_strings_de';
		$raw      = $this->leadwerk_get_option( $opt_name );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded[ $string_key ] ) ) {
			return '';
		}
		$val = $decoded[ $string_key ];
		return is_string( $val ) ? $val : ( is_scalar( $val ) ? (string) $val : '' );
	}

	/**
	 * Leadwerk-Option lesen (leadwerk_opt_*), unabhaengig von ACF — vermeidet ACF-Sanitizer auf Roh-HTML.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 */
	protected function leadwerk_get_option( $name ) {
		if ( class_exists( 'Leadwerk_Fields_API' ) ) {
			return Leadwerk_Fields_API::get_field( $name, 'option' );
		}
		if ( function_exists( 'get_field' ) ) {
			return get_field( $name, 'option' );
		}
		return null;
	}

	/**
	 * Leadwerk-Option schreiben (leadwerk_opt_*), unabhaengig von ACF.
	 *
	 * @param string $name  Field name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	protected function leadwerk_update_option( $name, $value ) {
		if ( class_exists( 'Leadwerk_Fields_API' ) ) {
			Leadwerk_Fields_API::update_field( $name, $value, 'option' );
			return;
		}
		if ( function_exists( 'update_field' ) ) {
			update_field( $name, $value, 'option' );
		}
	}

	/**
	 * Return default theme strings for one language.
	 *
	 * @param string $lang Language code.
	 * @return array<string,string>
	 */
	protected function get_default_theme_strings( $lang ) {
		if ( 'en' === $lang ) {
			return array(
				'services_menu_label' => 'Services',
				'header_language_group_label' => 'Choose language',
				'header_language_button_label' => 'Change language',
				'header_open_menu_label' => 'Open menu',
				'header_language_option_de' => 'Deutsch',
				'header_language_option_en' => 'English',
				'footer_menu_heading' => 'Menu',
				'footer_topics_heading' => 'Topics',
				'footer_contact_heading' => 'Contact',
				'footer_menu_home' => 'Home',
				'footer_phone_prefix' => 'Phone:',
				'footer_desc_home' => 'Integrated business aviation: charter, aircraft management, maintenance and CAMO from a single source at Karlsruhe/Baden-Baden Airport.',
				'footer_desc_general' => 'ACM AIR CHARTER offers charter, aircraft management, maintenance and CAMO — premium business aviation from one team.',
				'footer_desc_legal' => 'ACM AIR CHARTER GmbH — business aviation at Baden Airpark (Karlsruhe/Baden-Baden). For legal notices see imprint and privacy policy.',
				'contact_privacy_link_label' => 'Privacy policy',
				'structured_open_link_label' => 'Open link',
				'ui_learn_more_label' => 'Learn more',
				'news_read_more_label' => 'Read more',
				'ui_step_label' => 'Step',
				'ui_steps_nav_label' => 'Steps',
				'ui_prev_step_label' => 'Previous step',
				'ui_next_step_label' => 'Next step',
				'ui_swipe_or_click_hint' => 'Swipe or click',
				'ui_close_label' => 'Close',
				'store_badge_apple_aria_label' => 'Download on the App Store',
				'store_badge_apple_alt' => 'Download on the App Store',
				'store_badge_google_aria_label' => 'Get it on Google Play',
				'store_badge_google_alt' => 'Get it on Google Play',
				'legacy_home_hero_title_gradient' => 'Premium business aviation.',
				'legacy_home_hero_typewriter_words' => 'Charter.|Management.|Maintenance.|CAMO.',
				'legacy_home_hero_cta_text' => 'Contact us',
				'legacy_home_why_label' => 'Why ACM',
				'legacy_home_why_title' => 'One team for charter, management and maintenance.',
				'legacy_home_app_steps_title' => 'How we work with you',
				'legacy_home_packages_label' => 'Services',
				'legacy_home_packages_title' => 'Tailored aviation solutions',
				'legacy_home_solutions_label' => 'For operators and owners',
				'legacy_home_solutions_title' => 'End-to-end support',
				'legacy_home_solutions_register_text' => 'Get in touch',
				'legacy_home_faq_label' => 'FAQ',
				'legacy_home_faq_title' => 'Questions and answers',
				'legacy_home_cta_title' => "Charter and operations\naround the clock.",
				'legacy_home_cta_primary_text' => 'Request charter',
				'legacy_home_cta_secondary_text' => 'Contact',
				'legacy_home_ticker_items' => 'CHARTER|GLOBAL|FALCON|MAINTENANCE|CAMO|HANDLING|OPS',
				'legacy_user_ticker_items' => 'CHARTER|MANAGEMENT|MAINTENANCE|CAMO|BADEN AIRPARK|24/7',
				'legacy_merchant_benefits_ticker_items' => 'IS-BAO|CAMO|EASA|PART-NCC|QUALITY|SAFETY',
				'legacy_merchant_dashboard_ticker_items' => 'FLEET|OPS|LINE MAINTENANCE|BASE MAINTENANCE|STORES|PLANNING',
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
			);
		}

		return array(
			'services_menu_label' => 'Leistungen',
			'header_language_group_label' => 'Sprache wählen',
			'header_language_button_label' => 'Sprache wechseln',
			'header_open_menu_label' => 'Menü öffnen',
			'header_language_option_de' => 'Deutsch',
			'header_language_option_en' => 'English',
			'footer_menu_heading' => 'Menü',
			'footer_topics_heading' => 'Themen',
			'footer_contact_heading' => 'Kontakt',
			'footer_menu_home' => 'Startseite',
			'footer_phone_prefix' => 'Tel.:',
			'footer_desc_home' => 'Integrierte Business Aviation – Charter, Aircraft Management, Maintenance und CAMO aus einer Hand am Flughafen Karlsruhe/Baden-Baden.',
			'footer_desc_general' => 'ACM AIR CHARTER: Charter, Aircraft Management, Maintenance und CAMO – Premium Business Aviation aus einem Team.',
			'footer_desc_legal' => 'ACM AIR CHARTER GmbH – Business Aviation am Baden Airpark (Karlsruhe/Baden-Baden). Rechtliches siehe Impressum und Datenschutz.',
			'contact_privacy_link_label' => 'Datenschutz',
			'structured_open_link_label' => 'Link öffnen',
			'ui_learn_more_label' => 'Mehr erfahren',
			'news_read_more_label' => 'Weiterlesen',
			'ui_step_label' => 'Schritt',
			'ui_steps_nav_label' => 'Schritte',
			'ui_prev_step_label' => 'Vorheriger Schritt',
			'ui_next_step_label' => 'Nächster Schritt',
			'ui_swipe_or_click_hint' => 'Wischen oder klicken',
			'ui_close_label' => 'Schließen',
			'store_badge_apple_aria_label' => 'Im App Store herunterladen',
			'store_badge_apple_alt' => 'Im App Store herunterladen',
			'store_badge_google_aria_label' => 'Bei Google Play herunterladen',
			'store_badge_google_alt' => 'Bei Google Play herunterladen',
			'legacy_home_hero_title_gradient' => 'Premium Business Aviation.',
			'legacy_home_hero_typewriter_words' => 'Charter.|Management.|Wartung.|CAMO.',
			'legacy_home_hero_cta_text' => 'Kontakt aufnehmen',
			'legacy_home_why_label' => 'Warum ACM',
			'legacy_home_why_title' => 'Ein Team für Charter, Management und Wartung.',
			'legacy_home_app_steps_title' => 'So arbeiten wir mit Ihnen',
			'legacy_home_packages_label' => 'Leistungen',
			'legacy_home_packages_title' => 'Maßgeschneiderte Luftfahrtlösungen',
			'legacy_home_solutions_label' => 'Für Betreiber und Eigentümer',
			'legacy_home_solutions_title' => 'Rundum-Betreuung',
			'legacy_home_solutions_register_text' => 'Kontakt aufnehmen',
			'legacy_home_faq_label' => 'FAQ',
			'legacy_home_faq_title' => 'Fragen und Antworten',
			'legacy_home_cta_title' => "Charter und Operations\nrund um die Uhr.",
			'legacy_home_cta_primary_text' => 'Charter anfragen',
			'legacy_home_cta_secondary_text' => 'Kontakt',
			'legacy_home_ticker_items' => 'CHARTER|GLOBAL|FALCON|WARTUNG|CAMO|HANDLING|OPS',
			'legacy_user_ticker_items' => 'CHARTER|MANAGEMENT|WARTUNG|CAMO|BADEN AIRPARK|24/7',
			'legacy_merchant_benefits_ticker_items' => 'IS-BAO|CAMO|EASA|PART-NCC|QUALITÄT|SAFETY',
			'legacy_merchant_dashboard_ticker_items' => 'FLOTTE|OPS|LINIENWARTUNG|BASISWARTUNG|STORES|PLANUNG',
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
			'cmplz_category_preferences_title' => 'Präferenzen',
			'cmplz_category_preferences_desc' => 'Die technische Speicherung oder der Zugriff ist für den rechtmäßigen Zweck der Speicherung von Präferenzen erforderlich, die nicht vom Abonnenten oder Nutzer angefordert wurden.',
			'cmplz_category_statistics_title' => 'Statistiken',
			'cmplz_category_statistics_desc' => 'Die technische Speicherung oder der Zugriff, der ausschließlich zu statistischen Zwecken verwendet wird.',
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
	}

	/**
	 * Set the WordPress site icon.
	 *
	 * @return void
	 */
	protected function set_site_icon() {
		if ( get_option( 'site_icon' ) ) {
			return;
		}

		$attachment_id = $this->resolve_attachment( 'favicon-192x192.png' );
		if ( $attachment_id ) {
			update_option( 'site_icon', $attachment_id );
		}
	}

	/**
	 * Resolve an attachment by source path.
	 *
	 * @param string $source_path Source path.
	 * @return int
	 */
	protected function resolve_attachment( $source_path ) {
		if ( $this->media_importer ) {
			$attachment_id = (int) $this->media_importer->get_attachment_id_by_source( $source_path );
			if ( $attachment_id ) {
				return $attachment_id;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => 'leadwerk_source_path',
						'value' => trim( $source_path, '/' ),
					),
				),
			)
		);

		$ids = $query->get_posts();
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Apply site identity from manifest.
	 *
	 * @return void
	 */
	protected function apply_site_identity() {
		if ( ! empty( $this->manifest['site_title'] ) ) {
			update_option( 'blogname', sanitize_text_field( $this->manifest['site_title'] ) );
		}

		if ( ! empty( $this->manifest['site_tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $this->manifest['site_tagline'] ) );
		}
	}

	/**
	 * Find a page by source key and language.
	 *
	 * @param string $source_key Source key.
	 * @param string $lang       Language code.
	 * @return int
	 */
	protected function find_page_by_source_key_and_lang( $source_key, $lang, $post_type = 'page' ) {
		if ( class_exists( 'Leadwerk_Translation_API' ) ) {
			$registry_post_id = Leadwerk_Translation_API::get_post_by_source_key( $source_key, $lang, $post_type );
			if ( $registry_post_id ) {
				return (int) $registry_post_id;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
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
	 * Default block content for imported pages.
	 *
	 * @return string
	 */
	protected function get_default_block_content() {
		return '<!-- wp:acf/leadwerk-acm-page /-->';
	}

	/**
	 * Whether a page config points to a legal page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return bool
	 */
	protected function is_legal_page( $page_config ) {
		return in_array( $page_config['field_name'] ?? '', array( 'impressum_page', 'datenschutz_page' ), true );
	}

	/**
	 * Build the public slug stored in the translation registry.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function get_public_slug( $page_config ) {
		if ( ! empty( $page_config['is_front_page'] ) ) {
			return '';
		}

		return trim( (string) ( $page_config['slug'] ?? '' ), '/' );
	}

	/**
	 * Resolve the translated public slug without overwriting an existing EN slug.
	 *
	 * Existing translated pages may have manually curated slugs (for example
	 * /en/contact/ instead of /en/kontakt/). Import, single-page repair and
	 * force-canonical sync should keep those values stable.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param int                 $post_id     Translated post ID.
	 * @param string              $lang        Language code.
	 * @return string
	 */
	protected function get_translation_public_slug_for_link( $page_config, $post_id, $lang ) {
		if ( ! empty( $page_config['is_front_page'] ) ) {
			return '';
		}

		$fallback = sanitize_title( $this->get_public_slug( $page_config ) );
		$post_id  = (int) $post_id;
		$lang     = sanitize_key( (string) $lang );
		if ( $post_id < 1 ) {
			return $fallback;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $fallback;
		}

		$meta_key           = class_exists( 'Leadwerk_Translation_API' ) ? Leadwerk_Translation_API::META_PUBLIC_SLUG : 'leadwerk_public_slug';
		$stored_public_slug = sanitize_title( trim( (string) get_post_meta( $post_id, $meta_key, true ), '/' ) );
		$current_post_slug  = sanitize_title( trim( (string) $post->post_name, '/' ) );
		$post_public_slug   = $current_post_slug;
		$suffix             = '' !== $lang ? '-' . $lang : '';
		if ( '' !== $suffix && strlen( $post_public_slug ) > strlen( $suffix ) && $suffix === substr( $post_public_slug, -strlen( $suffix ) ) ) {
			$post_public_slug = substr( $post_public_slug, 0, -strlen( $suffix ) );
		}

		$default_internal_slug = sanitize_title( $this->build_internal_slug( $page_config, $lang, (string) $post->post_title ) );
		if ( 'en' === $lang && '' !== $post_public_slug && $current_post_slug !== $default_internal_slug && $post_public_slug !== $fallback ) {
			return $post_public_slug;
		}

		if ( '' !== $stored_public_slug ) {
			return $stored_public_slug;
		}

		if ( class_exists( 'Leadwerk_Translation_API' ) && method_exists( 'Leadwerk_Translation_API', 'get_post_public_slug' ) ) {
			$api_public_slug = sanitize_title( trim( (string) Leadwerk_Translation_API::get_post_public_slug( $post_id ), '/' ) );
			if ( '' !== $api_public_slug ) {
				return $api_public_slug;
			}
		}

		return '' !== $post_public_slug ? $post_public_slug : $fallback;
	}

	/**
	 * Build an internal unique WP slug for one page language.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $lang        Language code.
	 * @param string              $title       Page title.
	 * @return string
	 */
	protected function build_internal_slug( $page_config, $lang, $title ) {
		$base_slug = trim( (string) ( $page_config['slug'] ?? sanitize_title( $title ) ), '/' );

		if ( 'en' !== $lang ) {
			return sanitize_title( $base_slug );
		}

		if ( ! empty( $page_config['is_front_page'] ) ) {
			return 'home-en';
		}

		return sanitize_title( $base_slug . '-en' );
	}

	/**
	 * Build the EN meta payload without any seed file dependency.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param int                 $post_id     EN post ID.
	 * @return array<string,mixed>
	 */
	protected function build_en_meta_payload( $page_config, $post_id = 0 ) {
		$payload = array(
			'body_class' => $this->resolve_expected_body_class( $page_config ),
		);

		$post_id = (int) $post_id;
		if ( $post_id > 0 ) {
			$document_title = trim( (string) get_post_meta( $post_id, 'leadwerk_document_title', true ) );
			if ( '' !== $document_title ) {
				$payload['document_title'] = $document_title;
			}

			$meta_description = trim( (string) get_post_meta( $post_id, 'leadwerk_meta_description', true ) );
			if ( '' !== $meta_description ) {
				$payload['meta_description'] = $meta_description;
			}
		}

		return $payload;
	}

	/**
	 * Resolve the current translation record status without any seed file dependency.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return string
	 */
	protected function get_translation_record_status( $post_id, $lang ) {
		$post_id = (int) $post_id;
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id > 0 && class_exists( 'Leadwerk_Translation_API' ) ) {
			$status = sanitize_key( (string) get_post_meta( $post_id, Leadwerk_Translation_API::META_STATUS, true ) );
			if ( '' !== $status ) {
				return $status;
			}
		}

		return 'en' === $lang ? 'not_translated' : 'complete';
	}

	/**
	 * Resolve the canonical page body class for one FINORA page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param array<string,mixed> $payload     Optional payload.
	 * @return string
	 */
	protected function resolve_expected_body_class( $page_config, $payload = array() ) {
		$body_class = trim( (string) ( $payload['body_class'] ?? '' ) );
		if ( '' !== $body_class ) {
			return $this->normalize_body_class_string( $body_class );
		}

		$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
		if ( '' !== $source_key && function_exists( 'leadwerk_theme_get_source_template_body_class' ) ) {
			$body_class = trim( (string) leadwerk_theme_get_source_template_body_class( $source_key ) );
			if ( '' !== $body_class ) {
				return $this->normalize_body_class_string( $body_class );
			}
		}

		$source_file = (string) ( $page_config['source_file'] ?? '' );
		if ( '' !== $source_file ) {
			$path = $this->resolve_source_path( $source_file );
			if ( is_file( $path ) ) {
				$html = file_get_contents( $path );
				if ( is_string( $html ) && '' !== $html ) {
					$body_class = $this->extract_body_class_from_html( $html );
					if ( '' !== $body_class ) {
						return $body_class;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Ensure one page stores the expected body class contract.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param array<string,mixed> $page_config Page config.
	 * @return array<string,string>
	 */
	protected function ensure_page_style_contract( $post_id, $page_config ) {
		$expected = $this->resolve_expected_body_class( $page_config );
		$current  = $this->normalize_body_class_string( (string) get_post_meta( $post_id, 'leadwerk_body_class', true ) );

		if ( '' === $expected ) {
			return array(
				'status'     => 'missing',
				'body_class' => '',
				'source'     => '',
			);
		}

		if ( $current === $expected ) {
			return array(
				'status'     => 'ok',
				'body_class' => $expected,
				'source'     => 'stored',
			);
		}

		if ( ! $this->dry_run ) {
			update_post_meta( $post_id, 'leadwerk_body_class', sanitize_text_field( $expected ) );
		}

		return array(
			'status'     => 'repaired',
			'body_class' => $expected,
			'source'     => 'resolved_shell',
		);
	}

	/**
	 * Extract one normalized body class string from an HTML document.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	protected function extract_body_class_from_html( $html ) {
		if ( preg_match( '/<body[^>]*class="([^"]+)"/i', (string) $html, $matches ) ) {
			return $this->normalize_body_class_string( $matches[1] ?? '' );
		}

		return '';
	}

	/**
	 * Normalize one body class string.
	 *
	 * @param string $body_class Body class string.
	 * @return string
	 */
	protected function normalize_body_class_string( $body_class ) {
		$classes = preg_split( '/\s+/', trim( (string) $body_class ) );
		$classes = is_array( $classes ) ? $classes : array();
		$classes = array_map( 'sanitize_html_class', $classes );
		$classes = array_values( array_unique( array_filter( $classes ) ) );

		return implode( ' ', $classes );
	}

	/**
	 * Resolve the translated post title for one EN page.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function get_translated_title( $page_config ) {
		$source_key = sanitize_key( (string) ( $page_config['source_key'] ?? '' ) );
		$post_type  = (string) ( $page_config['target_type'] ?? 'page' );
		$existing   = $this->find_page_by_source_key_and_lang( $source_key, 'en', $post_type );

		if ( $existing ) {
			$post = get_post( (int) $existing );
			if ( $post instanceof WP_Post && '' !== trim( (string) $post->post_title ) ) {
				return (string) $post->post_title;
			}
		}

		if ( ! empty( $page_config['translated_title'] ) ) {
			return (string) $page_config['translated_title'];
		}

		return (string) ( $page_config['title'] ?? 'Untitled' );
	}

	/**
	 * Initial WP post status while the importer is still filling fields.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @param string              $lang        Language code.
	 * @param int                 $existing    Existing post ID.
	 * @return string
	 */
	protected function get_initial_post_status( $page_config, $lang, $existing ) {
		if ( $existing ) {
			$post = get_post( (int) $existing );
			if ( $post instanceof WP_Post && '' !== trim( (string) $post->post_status ) ) {
				return (string) $post->post_status;
			}
		}

		return 'draft';
	}

	/**
	 * Desired final source-language status after successful field fill.
	 *
	 * @param array<string,mixed> $page_config Page config.
	 * @return string
	 */
	protected function get_desired_source_post_status( $page_config ) {
		return sanitize_key( (string) ( $page_config['post_status'] ?? 'publish' ) ) ?: 'publish';
	}

	/**
	 * Set the post status after successful source fill.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $status      Status.
	 * @param bool   $created_new Whether the post was created during this run.
	 * @return void
	 */
	protected function maybe_update_post_status( $post_id, $status, $created_new = false ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$status = sanitize_key( (string) $status ) ?: 'publish';
		if ( ! $created_new && $status === $post->post_status ) {
			return;
		}

		if ( $status !== $post->post_status ) {
			wp_update_post(
				array(
					'ID'          => (int) $post_id,
					'post_status' => $status,
				)
			);
		}
	}

	/**
	 * Force a post into draft state.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	protected function force_draft( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || 'draft' === $post->post_status ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Inner HTML of a DOM node (children only).
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	protected function leadwerk_dom_node_inner_html( $dom, $node ) {
		if ( ! $dom instanceof DOMDocument || ! $node instanceof DOMNode ) {
			return '';
		}
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Extract main article inner HTML via DOM when regex extraction fails or is empty.
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	protected function extract_news_article_inner_html_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return '';
		}
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return '';
		}
		$xpath = new DOMXPath( $dom );

		$nodes = $xpath->query( "//article[contains(concat(' ', normalize-space(@class), ' '), ' article-body ')]" );
		if ( $nodes instanceof DOMNodeList && $nodes->length > 0 ) {
			return $this->leadwerk_dom_node_inner_html( $dom, $nodes->item( 0 ) );
		}

		$nodes = $xpath->query( '//article' );
		if ( ! $nodes instanceof DOMNodeList || $nodes->length === 0 ) {
			return '';
		}

		$best     = '';
		$best_len = 0;
		for ( $i = 0; $i < $nodes->length; $i++ ) {
			$node = $nodes->item( $i );
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$cls = $node->getAttribute( 'class' );
			if ( preg_match( '/\bnews-card-related\b|\bnews-card\b/', $cls ) ) {
				continue;
			}
			$inner = $this->leadwerk_dom_node_inner_html( $dom, $node );
			$len   = strlen( trim( wp_strip_all_tags( $inner ) ) );
			if ( $len > $best_len ) {
				$best_len = $len;
				$best     = $inner;
			}
		}

		return $best;
	}

	/**
	 * Extract news body from static HTML (regex first, then DOM fallback).
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	protected function extract_news_post_content_from_html( $html ) {
		$content = '';
		if ( preg_match( '/<article[^>]*>(.*?)<\/article>/si', $html, $m ) ) {
			$content = $m[1];
		} elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/si', $html, $m ) ) {
			$content = $m[1];
		} elseif ( preg_match( '/<body[^>]*>(.*?)<\/body>/si', $html, $m ) ) {
			$content = $m[1];
		}

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			$content = $this->extract_news_article_inner_html_dom( $html );
		}

		return $content;
	}

	/**
	 * Wrap fragment for theme CSS when no .article-body block is present.
	 *
	 * @param string $content HTML fragment.
	 * @return string
	 */
	protected function maybe_wrap_news_article_body( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return '';
		}
		if ( preg_match( '/class\s*=\s*["\'][^"\']*\barticle-body\b/', $content ) ) {
			return $content;
		}
		return '<div class="article-body">' . $content . '</div>';
	}

	/**
	 * Meta key aligned with theme LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META (Leadwerk Fields / get_field).
	 *
	 * @return string
	 */
	protected function leadwerk_importer_acm_news_publication_meta_key() {
		return 'acm_news_publication_date';
	}

	/**
	 * Strip related-news teaser cards so their &lt;time&gt; does not override the article date.
	 *
	 * @param string $html Full HTML.
	 * @return string
	 */
	protected function scope_html_for_news_article_datetime( $html ) {
		$html = (string) $html;
		return (string) preg_replace( '/<article[^>]*\bnews-card-related\b[^>]*>.*?<\/article>/is', '', $html );
	}

	/**
	 * Normalize extracted date string to Y-m-d for post meta and sorting.
	 *
	 * @param string $raw Raw from &lt;time datetime&gt; or visible label.
	 * @return string Empty if not parseable.
	 */
	protected function normalize_acm_news_publication_ymd( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw ) ) {
			$head = substr( $raw, 0, 10 );
			$ts   = strtotime( $head . ' 12:00:00' );
			if ( $ts ) {
				return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
			}
		}
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m ) ) {
			$ts = strtotime( sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] ) . ' 12:00:00' );
			if ( $ts ) {
				return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
			}
		}
		$parsed = $this->parse_german_long_month_news_date( $raw );
		if ( '' !== $parsed ) {
			return $parsed;
		}
		$ts = strtotime( $raw );
		if ( $ts ) {
			return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
		}
		return '';
	}

	/**
	 * Parse labels like "1. Juni 2025" (German month names).
	 *
	 * @param string $label Plain text.
	 * @return string Y-m-d or empty.
	 */
	protected function parse_german_long_month_news_date( $label ) {
		$label = trim( html_entity_decode( (string) $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $label ) {
			return '';
		}
		$months = array(
			'januar'    => 1,
			'februar'   => 2,
			'märz'      => 3,
			'maerz'     => 3,
			'marz'      => 3,
			'april'     => 4,
			'mai'       => 5,
			'juni'      => 6,
			'juli'      => 7,
			'august'    => 8,
			'september' => 9,
			'oktober'   => 10,
			'november'  => 11,
			'dezember'  => 12,
			'january'   => 1,
			'february'  => 2,
			'march'     => 3,
			'may'       => 5,
			'june'      => 6,
			'july'      => 7,
			'october'   => 10,
			'december'  => 12,
		);
		if ( ! preg_match( '/^(\d{1,2})\.\s*([^\d\s.]+)\s+(\d{4})$/u', $label, $m ) ) {
			return '';
		}
		$day        = (int) $m[1];
		$month_word = strtolower( str_replace( array( "\xc3\xa4", "\xc3\xb6", "\xc3\xbc" ), array( 'a', 'o', 'u' ), $m[2] ) );
		$month_word = str_replace( array( 'ä', 'ö', 'ü', 'ß' ), array( 'a', 'o', 'u', 'ss' ), $month_word );
		$year       = (int) $m[3];
		if ( ! isset( $months[ $month_word ] ) ) {
			return '';
		}
		$month = (int) $months[ $month_word ];
		$ts    = strtotime( sprintf( '%04d-%02d-%02d', $year, $month, $day ) . ' 12:00:00' );
		if ( ! $ts ) {
			return '';
		}
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Write canonical publication date meta (_leadwerk_news_datetime + Leadwerk CF key).
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $raw_extracted Raw from HTML (datetime attribute or fallback text).
	 * @return void
	 */
	protected function persist_acm_news_publication_metas( $post_id, $raw_extracted ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || $this->dry_run ) {
			return;
		}
		$ymd = $this->normalize_acm_news_publication_ymd( $raw_extracted );
		if ( '' === $ymd ) {
			return;
		}
		update_post_meta( $post_id, '_leadwerk_news_datetime', $ymd );
		update_post_meta( $post_id, $this->leadwerk_importer_acm_news_publication_meta_key(), $ymd );
	}

	/**
	 * Article date: first &lt;time datetime&gt; outside related-news cards; optional .news-date span.
	 *
	 * @param string $html Full HTML.
	 * @return string Raw token for normalize_acm_news_publication_ymd().
	 */
	protected function extract_news_datetime_from_html( $html ) {
		$scope = $this->scope_html_for_news_article_datetime( $html );
		if ( preg_match( '/<time[^>]*\bdatetime\s*=\s*["\']([^"\']+)["\']/', $scope, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		if ( preg_match( '/<span[^>]*\bnews-date\b[^>]*>([^<]+)<\/span>/i', $scope, $m2 ) ) {
			return sanitize_text_field( trim( html_entity_decode( $m2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
		return '';
	}

	/**
	 * Erstes Hero-Bild unter figure.article-figure als Pfad relativ zu source_root (z. B. Fotos/news/foo.jpg).
	 *
	 * @param string $html Vollstaendiges Artikel-HTML.
	 * @return string Leerstring wenn nicht ermittelbar.
	 */
	protected function extract_news_hero_source_path_from_html( $html ) {
		if ( ! preg_match( '/<figure[^>]*\barticle-figure\b[^>]*>.*?<img[^>]+src\s*=\s*["\']([^"\']+)["\']/is', (string) $html, $m ) ) {
			return '';
		}
		$src = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		if ( preg_match( '#^(https?:)?//#i', $src ) ) {
			return '';
		}
		$src = str_replace( '\\', '/', $src );
		$src = preg_replace( '#^(\.\./)+#', '', $src );
		$src = ltrim( $src, '/' );
		if ( preg_match( '#^Fotos/.+#i', $src ) ) {
			return $src;
		}
		return '';
	}

	/**
	 * Entfernt scroll-reveal aus importiertem Markup (kein JS auf WP-Single, sonst opacity:0).
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	protected function strip_news_scroll_reveal_classes( $html ) {
		return (string) preg_replace( '/\bscroll-reveal\s*/', '', (string) $html );
	}

	/**
	 * Featured Image aus Quell-HTML setzen, wenn Medien unter source_root vorliegen.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $html    Vollstaendiges Artikel-HTML.
	 * @return void
	 */
	protected function maybe_assign_news_featured_from_source_html( $post_id, $html ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || $this->dry_run || ! $this->media_importer instanceof Leadwerk_Media_Importer ) {
			return;
		}
		$rel = $this->extract_news_hero_source_path_from_html( $html );
		if ( '' === $rel ) {
			return;
		}
		$att_id = (int) $this->media_importer->get_attachment_id_by_source( $rel );
		if ( ! $att_id ) {
			$att_id = (int) $this->media_importer->import_file( $rel );
		}
		if ( $att_id > 0 ) {
			set_post_thumbnail( $post_id, $att_id );
		}
	}

	/**
	 * Import news articles from the manifest news_articles queue.
	 *
	 * @param array<string,mixed> $job_state Job state.
	 * @param int                 $batch     Batch size.
	 * @return array<string,mixed>
	 */
	protected function perform_news_import_step( $job_state, $batch = 2 ) {
		$step_key = 'news_import';
		$queue    = (array) ( $job_state['queues']['news'] ?? array() );
		$cursor   = (int) ( $job_state['cursor'][ $step_key ] ?? 0 );
		$total    = count( $queue );

		if ( $cursor >= $total ) {
			$job_state['steps'][ $step_key ]['status']    = 'completed';
			$job_state['steps'][ $step_key ]['processed'] = $total;
			return $job_state;
		}

		$job_state = $this->mark_step_running( $job_state, $step_key, 'Importing news articles' );
		$processed = 0;

		while ( $cursor < $total && $processed < $batch ) {
			$article = $queue[ $cursor ];

			if ( ! is_array( $article ) || empty( $article['source_file'] ) ) {
				Leadwerk_Logger::log( 'News: Ungueltige Artikelkonfiguration bei Index ' . $cursor, 'warning' );
				++$cursor;
				++$processed;
				continue;
			}

			$source_file = (string) $article['source_file'];
			$source_path = $this->resolve_source_path( $source_file );

			$job_state['current_item'] = 'News: ' . basename( $source_file );

			if ( ! is_file( $source_path ) ) {
				Leadwerk_Logger::log( 'News-Datei nicht gefunden: ' . $source_path, 'warning' );
				Leadwerk_Logger::record_result( 'warning', 'Datei nicht gefunden: ' . $source_file, 'news-' . $cursor );
				++$cursor;
				++$processed;
				continue;
			}

			$html = file_get_contents( $source_path );
			if ( false === $html || '' === trim( $html ) ) {
				Leadwerk_Logger::log( 'News-Datei leer: ' . $source_path, 'warning' );
				++$cursor;
				++$processed;
				continue;
			}

			// Parse title from <title> tag or <h1>.
			$title = '';
			if ( preg_match( '/<title>([^<]+)<\/title>/i', $html, $m ) ) {
				$title = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				// Remove site name suffix if present (e.g. " - ACM AIR CHARTER")
				$title = preg_replace( '/\s*[-|]\s*ACM\s*AIR\s*CHARTER.*/i', '', $title );
			}
			if ( '' === $title && preg_match( '/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m ) ) {
				$title = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			if ( '' === $title ) {
				$title = ucfirst( str_replace( array( '-', '.html' ), array( ' ', '' ), basename( $source_file ) ) );
			}

			$content       = $this->extract_news_post_content_from_html( $html );
			$content       = $this->strip_news_scroll_reveal_classes( $content );
			$news_datetime = $this->extract_news_datetime_from_html( $html );

			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				Leadwerk_Logger::log( 'News: Kein Artikelinhalt extrahierbar: ' . $source_path, 'warning' );
				Leadwerk_Logger::record_result( 'warning', 'Leerer Artikelinhalt: ' . $source_file, 'news-' . $cursor );
			} else {
				$content = $this->maybe_wrap_news_article_body( $content );
			}

			// Generate slug from source file name.
			$slug = str_replace( array( '.html', '.htm' ), '', basename( $source_file ) );

			if ( $this->dry_run ) {
				Leadwerk_Logger::log( '[DRY-RUN] Wuerde News erstellen: ' . $title . ' (slug: ' . $slug . ')' );
				Leadwerk_Logger::record_result( 'success', 'DRY-RUN: News wuerde erstellt: ' . $title, 'news-' . $cursor );
			} else {
				// Check if the news post already exists.
				$existing = new WP_Query( array(
					'post_type'      => 'acm_news',
					'name'           => $slug,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				) );

				if ( $existing->have_posts() ) {
					$post_id = $existing->posts[0];
					wp_update_post( array(
						'ID'           => $post_id,
						'post_title'   => $title,
						'post_content' => $content,
						'post_status'  => 'publish',
					) );
					update_post_meta( $post_id, '_leadwerk_news_source_file', sanitize_text_field( $source_file ) );
					if ( '' !== $news_datetime ) {
						$this->persist_acm_news_publication_metas( $post_id, $news_datetime );
					}
					$this->maybe_assign_news_featured_from_source_html( $post_id, $html );
					Leadwerk_Logger::log( 'News aktualisiert: ' . $title . ' (ID: ' . $post_id . ')' );
					Leadwerk_Logger::record_result( 'success', 'News aktualisiert: ' . $title, 'news-' . $cursor );
				} else {
					$post_id = wp_insert_post( array(
						'post_type'    => 'acm_news',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $content,
						'post_status'  => 'publish',
					), true );

					if ( is_wp_error( $post_id ) ) {
						Leadwerk_Logger::log( 'News-Erstellung fehlgeschlagen: ' . $post_id->get_error_message(), 'error' );
						Leadwerk_Logger::record_result( 'error', 'News-Erstellung fehlgeschlagen: ' . $title, 'news-' . $cursor );
					} else {
						// Store source file reference.
						update_post_meta( $post_id, '_leadwerk_news_source_file', sanitize_text_field( $source_file ) );
						if ( '' !== $news_datetime ) {
							$this->persist_acm_news_publication_metas( $post_id, $news_datetime );
						}
						$this->maybe_assign_news_featured_from_source_html( $post_id, $html );
						Leadwerk_Logger::log( 'News erstellt: ' . $title . ' (ID: ' . $post_id . ')' );
						Leadwerk_Logger::record_result( 'success', 'News erstellt: ' . $title, 'news-' . $cursor );
					}
				}
			}

			++$cursor;
			++$processed;
		}

		$job_state['cursor'][ $step_key ]             = $cursor;
		$job_state['steps'][ $step_key ]['processed'] = $cursor;

		if ( $cursor >= $total ) {
			$job_state['steps'][ $step_key ]['status'] = 'completed';
			Leadwerk_Logger::log( 'News Import abgeschlossen: ' . $total . ' Artikel verarbeitet.' );
		}

		return $job_state;
	}

	/**
	 * Resolve one source path in the bundled assets directory.
	 *
	 * @param string $relative_file Relative path.
	 * @return string
	 */
	protected function resolve_source_path( $relative_file ) {
		$relative_file = ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, (string) $relative_file ), DIRECTORY_SEPARATOR );
		return rtrim( $this->source_root, '/\\' ) . DIRECTORY_SEPARATOR . $relative_file;
	}
}
