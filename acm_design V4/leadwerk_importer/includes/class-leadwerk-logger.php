<?php
/**
 * Structured logging and job-state storage for the importer.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Logger {

	const OPTION_LOG = 'leadwerk_import_log';
	const OPTION_JOB = 'leadwerk_import_job_state';
	const LOG_TAIL_LIMIT = 80;

	/**
	 * Plaintext log buffer.
	 *
	 * @var string
	 */
	protected static $log = '';

	/**
	 * Cached job state.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $state = null;

	/**
	 * Append one log line.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		$state     = self::load_state();
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$prefix    = '[' . $timestamp . ']';
		$level     = sanitize_key( (string) $level );
		$line      = $prefix . ' ' . trim( (string) $message );

		if ( '' !== $level && 'info' !== $level ) {
			$line = $prefix . ' [' . strtoupper( $level ) . '] ' . trim( (string) $message );
		}

		self::$log .= $line . "\n";

		$tail   = isset( $state['log_tail'] ) && is_array( $state['log_tail'] ) ? array_values( $state['log_tail'] ) : array();
		$tail[] = array(
			'time'    => $timestamp,
			'level'   => $level ?: 'info',
			'message' => trim( (string) $message ),
			'line'    => $line,
		);

		if ( count( $tail ) > self::LOG_TAIL_LIMIT ) {
			$tail = array_slice( $tail, -1 * self::LOG_TAIL_LIMIT );
		}

		$state['log_tail'] = $tail;
		self::persist_state( $state );
		update_option( self::OPTION_LOG, self::$log, false );
	}

	/**
	 * Start or resume one import job.
	 *
	 * @param array<string,mixed> $args Job arguments.
	 * @return array<string,mixed>
	 */
	public static function start_job( $args = array() ) {
		$existing = self::load_state();
		if ( ! empty( $existing['job_id'] ) && in_array( $existing['status'] ?? '', array( 'running', 'booting' ), true ) ) {
			return $existing;
		}

		$job_id = sanitize_text_field( (string) ( $args['job_id'] ?? wp_generate_uuid4() ) );
		$state  = array(
			'job_id'         => $job_id,
			'status'         => sanitize_key( (string) ( $args['status'] ?? 'booting' ) ),
			'dry_run'        => ! empty( $args['dry_run'] ),
			'current_step'   => '',
			'step_percent'   => 0,
			'overall_percent'=> 0,
			'current_item'   => '',
			'processed'      => 0,
			'success_count'  => 0,
			'warning_count'  => 0,
			'error_count'    => 0,
			'started_at'     => current_time( 'mysql', true ),
			'finished_at'    => '',
			'steps'          => array(),
			'queues'         => array(),
			'cursor'         => array(),
			'results'        => array(
				'pages'    => array(),
				'media'    => array(),
				'summary'  => array(),
				'blocking' => array(),
			),
			'log_tail'       => array(),
		);

		self::$log = '';
		self::persist_state( array_merge( $state, (array) $args ) );
		update_option( self::OPTION_LOG, '', false );

		return self::load_state();
	}

	/**
	 * Return the current job state.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state() {
		return self::load_state();
	}

	/**
	 * Update the stored job state.
	 *
	 * @param array<string,mixed> $patch Partial state.
	 * @return array<string,mixed>
	 */
	public static function update_state( $patch ) {
		$state = self::load_state();
		$state = self::merge_state( $state, (array) $patch );
		self::persist_state( $state );
		return $state;
	}

	/**
	 * Replace the full state.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function set_state( $state ) {
		self::persist_state( (array) $state );
		return self::load_state();
	}

	/**
	 * Store the importer step definitions.
	 *
	 * @param array<string,mixed> $steps Steps.
	 * @return void
	 */
	public static function set_steps( $steps ) {
		self::update_state(
			array(
				'steps' => (array) $steps,
			)
		);
	}

	/**
	 * Record the currently active item.
	 *
	 * @param string $step Step key.
	 * @param string $item Item label.
	 * @return void
	 */
	public static function set_current( $step, $item = '' ) {
		self::update_state(
			array(
				'current_step' => sanitize_key( (string) $step ),
				'current_item' => sanitize_text_field( (string) $item ),
			)
		);
	}

	/**
	 * Update one step record in-place.
	 *
	 * @param string               $step_key Step key.
	 * @param array<string,mixed>  $patch    Partial step data.
	 * @return array<string,mixed>
	 */
	public static function update_step( $step_key, $patch ) {
		$step_key = sanitize_key( (string) $step_key );
		$state    = self::load_state();
		$steps    = isset( $state['steps'] ) && is_array( $state['steps'] ) ? $state['steps'] : array();
		$current  = isset( $steps[ $step_key ] ) && is_array( $steps[ $step_key ] ) ? $steps[ $step_key ] : array();
		$steps[ $step_key ] = array_merge( $current, (array) $patch );
		$state['steps']     = $steps;
		self::recalculate_percentages( $state );
		self::persist_state( $state );
		return $steps[ $step_key ];
	}

	/**
	 * Append one summary row.
	 *
	 * @param string               $bucket Summary bucket.
	 * @param array<string,mixed>  $entry  Entry data.
	 * @return void
	 */
	public static function add_summary_item( $bucket, $entry ) {
		$state = self::load_state();
		if ( empty( $state['results'] ) || ! is_array( $state['results'] ) ) {
			$state['results'] = array();
		}

		if ( empty( $state['results'][ $bucket ] ) || ! is_array( $state['results'][ $bucket ] ) ) {
			$state['results'][ $bucket ] = array();
		}

		$state['results'][ $bucket ][] = $entry;
		self::persist_state( $state );
	}

	/**
	 * Increment one counter.
	 *
	 * @param string $key   Counter key.
	 * @param int    $delta Amount.
	 * @return void
	 */
	public static function increment( $key, $delta = 1 ) {
		$state         = self::load_state();
		$current       = isset( $state[ $key ] ) ? (int) $state[ $key ] : 0;
		$state[ $key ] = $current + (int) $delta;
		self::persist_state( $state );
	}

	/**
	 * Record one work-item outcome and log it.
	 *
	 * @param string $status   success|warning|error.
	 * @param string $message  Message.
	 * @param string $item_key Optional item key.
	 * @return void
	 */
	public static function record_result( $status, $message, $item_key = '' ) {
		$status = sanitize_key( (string) $status );

		switch ( $status ) {
			case 'warning':
				self::increment( 'warning_count' );
				self::log( $message, 'warning' );
				break;
			case 'error':
				self::increment( 'error_count' );
				self::log( $message, 'error' );
				break;
			default:
				self::increment( 'success_count' );
				self::log( $message, 'info' );
				break;
		}

		if ( '' !== trim( (string) $item_key ) ) {
			self::add_summary_item(
				'summary',
				array(
					'item_key' => sanitize_text_field( (string) $item_key ),
					'status'   => $status,
					'message'  => sanitize_text_field( (string) $message ),
				)
			);
		}
	}

	/**
	 * Finish the current job.
	 *
	 * @param string               $status Status.
	 * @param array<string,mixed>  $extra  Extra state.
	 * @return array<string,mixed>
	 */
	public static function finish_job( $status = 'completed', $extra = array() ) {
		$extra               = (array) $extra;
		$suppress_post_steps = ! empty( $extra['suppress_post_import_notice'] );
		unset( $extra['suppress_post_import_notice'] );

		$state               = self::load_state();
		$state['status']     = sanitize_key( (string) $status );
		$state['finished_at']= current_time( 'mysql', true );
		$state               = self::merge_state( $state, $extra );
		self::recalculate_percentages( $state, true );
		self::persist_state( $state );
		update_option( self::OPTION_LOG, self::$log, false );

		if ( 'completed' === $state['status'] && empty( $state['dry_run'] ) && ! $suppress_post_steps && defined( 'LEADWERK_IMPORTER_OPTION_POST_STEPS_NOTICE' ) ) {
			update_option( LEADWERK_IMPORTER_OPTION_POST_STEPS_NOTICE, '1', false );
		}

		return $state;
	}

	/**
	 * Reset the stored job state.
	 *
	 * @return void
	 */
	public static function reset_job() {
		self::$state = array();
		self::$log   = '';
		delete_option( self::OPTION_JOB );
		delete_option( self::OPTION_LOG );
	}

	/**
	 * Return whether a job is currently active.
	 *
	 * @return bool
	 */
	public static function has_active_job() {
		$state = self::load_state();
		return ! empty( $state['job_id'] ) && in_array( $state['status'] ?? '', array( 'running', 'booting' ), true );
	}

	/**
	 * Get the full plaintext log.
	 *
	 * @return string
	 */
	public static function get_log() {
		if ( '' === self::$log ) {
			self::$log = (string) get_option( self::OPTION_LOG, '' );
		}
		return self::$log;
	}

	/**
	 * Persist the current log buffer.
	 *
	 * @return void
	 */
	public static function save() {
		update_option( self::OPTION_LOG, self::$log, false );
		if ( null !== self::$state ) {
			self::persist_state( self::$state );
		}
	}

	/**
	 * Load persisted state from the database.
	 *
	 * @return array<string,mixed>
	 */
	protected static function load_state() {
		if ( null !== self::$state ) {
			return self::$state;
		}

		$state = get_option( self::OPTION_JOB, array() );
		$state = is_array( $state ) ? $state : array();
		self::$state = $state;

		if ( '' === self::$log ) {
			self::$log = (string) get_option( self::OPTION_LOG, '' );
		}

		return self::$state;
	}

	/**
	 * Persist state and keep percentages fresh.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	protected static function persist_state( $state ) {
		$state = is_array( $state ) ? $state : array();
		self::recalculate_percentages( $state );
		self::$state = $state;
		update_option( self::OPTION_JOB, self::$state, false );
	}

	/**
	 * Merge nested state arrays.
	 *
	 * @param array<string,mixed> $state Existing state.
	 * @param array<string,mixed> $patch Patch.
	 * @return array<string,mixed>
	 */
	protected static function merge_state( $state, $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) && is_array( $value ) ) {
				$state[ $key ] = self::merge_state( $state[ $key ], $value );
				continue;
			}

			$state[ $key ] = $value;
		}

		return $state;
	}

	/**
	 * Refresh stored overall percentages from step data.
	 *
	 * @param array<string,mixed> $state       State.
	 * @param bool                $force_final Whether to clamp to 100.
	 * @return void
	 */
	protected static function recalculate_percentages( &$state, $force_final = false ) {
		$steps = isset( $state['steps'] ) && is_array( $state['steps'] ) ? $state['steps'] : array();
		if ( empty( $steps ) ) {
			$state['step_percent']    = 0;
			$state['overall_percent'] = $force_final ? 100 : 0;
			return;
		}

		$total_weighted   = 0.0;
		$current_step_key = sanitize_key( (string) ( $state['current_step'] ?? '' ) );

		foreach ( $steps as $step_key => $step ) {
			$total     = max( 1, (int) ( $step['total'] ?? 1 ) );
			$processed = min( $total, max( 0, (int) ( $step['processed'] ?? 0 ) ) );
			$status    = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );

			if ( 'completed' === $status ) {
				$processed = $total;
			} elseif ( in_array( $status, array( 'pending', 'skipped' ), true ) ) {
				$processed = 0;
			}

			$step_percent = (int) round( ( $processed / $total ) * 100 );
			$steps[ $step_key ]['processed'] = $processed;
			$steps[ $step_key ]['step_percent'] = $step_percent;
			$total_weighted += $step_percent;
		}

		$state['steps'] = $steps;
		if ( $force_final ) {
			$state['overall_percent'] = 100;
		} else {
			$state['overall_percent'] = (int) round( $total_weighted / max( 1, count( $steps ) ) );
		}

		if ( '' !== $current_step_key && ! empty( $steps[ $current_step_key ] ) ) {
			$state['step_percent'] = (int) ( $steps[ $current_step_key ]['step_percent'] ?? 0 );
			return;
		}

		$state['step_percent'] = $state['overall_percent'];
	}
}
