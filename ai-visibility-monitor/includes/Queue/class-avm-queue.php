<?php
/**
 * Background monitoring queue (spec §9, §16).
 *
 * Uses Action Scheduler when available, otherwise falls back to a WP-Cron
 * recurring event. Jobs live in the avm_queue table: deduped, retried with a
 * cap, and processed in small batches so public/admin requests are never used
 * to run AI queries.
 *
 * @package AVM
 */

namespace AVM\Queue;

use AVM\Support\Settings;
use AVM\Support\Logger;
use AVM\Services\LocalQueryExecutor;
use AVM\Data\PromptsRepository;
use AVM\Data\RunsRepository;

defined( 'ABSPATH' ) || exit;

class Queue {

	const CRON_HOOK   = 'avm_process_queue';
	const ENQUEUE_HOOK = 'avm_enqueue_due';

	/**
	 * Register hooks (called on every load).
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::ENQUEUE_HOOK, array( __CLASS__, 'enqueue_due_prompts' ) );

		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				if ( ! isset( $schedules['avm_five_minutes'] ) ) {
					$schedules['avm_five_minutes'] = array(
						'interval' => 5 * MINUTE_IN_SECONDS,
						'display'  => __( 'Every 5 minutes (AVM)', 'ai-visibility-monitor' ),
					);
				}
				return $schedules;
			}
		);
	}

	/**
	 * Schedule recurring events on activation.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'avm_five_minutes', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::ENQUEUE_HOOK ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::ENQUEUE_HOOK );
		}
	}

	/**
	 * Clear scheduled events on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::ENQUEUE_HOOK );
	}

	/**
	 * Add a prompt to the queue (deduped).
	 *
	 * @param int    $prompt_id Prompt ID.
	 * @param string $reason    Job origin (scheduled|manual).
	 * @return bool True if queued, false if a duplicate/over allowance.
	 */
	public static function enqueue_prompt( $prompt_id, $reason = 'scheduled' ) {
		global $wpdb;

		if ( ! self::allowance_remaining() ) {
			Logger::log( 'queue', 'Skipped enqueue: monthly allowance reached', Logger::WARNING );
			return false;
		}

		$dedupe = 'prompt:' . (int) $prompt_id;
		$table  = $wpdb->prefix . 'avm_queue';

		// INSERT IGNORE-style dedupe via the unique dedupe_key column.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE dedupe_key = %s AND status IN ('queued','processing')", $dedupe )
		);
		if ( $existing ) {
			return false;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'job_type'     => 'prompt_scan',
				'prompt_id'    => (int) $prompt_id,
				'dedupe_key'   => $dedupe,
				'status'       => 'queued',
				'available_at' => current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $ok && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::CRON_HOOK, array(), 'avm' );
		}

		return (bool) $ok;
	}

	/**
	 * Enqueue every prompt whose schedule is due (hourly).
	 */
	public static function enqueue_due_prompts() {
		$due = ( new PromptsRepository() )->due( 50 );
		foreach ( $due as $prompt ) {
			self::enqueue_prompt( (int) $prompt['id'], 'scheduled' );
		}
	}

	/**
	 * Process a small batch of queued jobs.
	 */
	public static function process_batch() {
		global $wpdb;

		$batch_size = max( 1, (int) Settings::get( 'batch_size', 3 ) );
		$table      = $wpdb->prefix . 'avm_queue';
		$now        = current_time( 'mysql' );

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'queued' AND available_at <= %s ORDER BY id ASC LIMIT %d",
				$now,
				$batch_size
			),
			ARRAY_A
		);

		if ( ! $jobs ) {
			return;
		}

		$executor    = new LocalQueryExecutor();
		$retry_limit = max( 0, (int) Settings::get( 'retry_limit', 3 ) );
		$prompts     = new PromptsRepository();

		foreach ( $jobs as $job ) {
			if ( ! self::allowance_remaining() ) {
				Logger::log( 'queue', 'Batch halted: monthly allowance reached', Logger::WARNING );
				break;
			}

			// Claim the job.
			$wpdb->update( $table, array( 'status' => 'processing' ), array( 'id' => (int) $job['id'] ), array( '%s' ), array( '%d' ) );

			$prompt = $prompts->find( (int) $job['prompt_id'] );
			if ( ! $prompt ) {
				$wpdb->delete( $table, array( 'id' => (int) $job['id'] ), array( '%d' ) );
				continue;
			}

			$result = $executor->execute( $prompt );

			if ( is_wp_error( $result ) ) {
				$attempts = (int) $job['attempts'] + 1;
				if ( $attempts >= $retry_limit || self::is_terminal( $result->get_error_code() ) ) {
					$wpdb->update(
						$table,
						array(
							'status'     => 'failed',
							'attempts'   => $attempts,
							'last_error' => substr( $result->get_error_code(), 0, 255 ),
						),
						array( 'id' => (int) $job['id'] ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
				} else {
					// Exponential backoff.
					$delay = (int) ( MINUTE_IN_SECONDS * pow( 2, $attempts ) );
					$wpdb->update(
						$table,
						array(
							'status'       => 'queued',
							'attempts'     => $attempts,
							'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
							'last_error'   => substr( $result->get_error_code(), 0, 255 ),
						),
						array( 'id' => (int) $job['id'] ),
						array( '%s', '%d', '%s', '%s' ),
						array( '%d' )
					);
				}
				continue;
			}

			// Success: remove the job.
			$wpdb->delete( $table, array( 'id' => (int) $job['id'] ), array( '%d' ) );
		}
	}

	/**
	 * Whether a provider error code should not be retried (spec §16.2).
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	private static function is_terminal( $code ) {
		return in_array(
			$code,
			array( 'avm_invalid_key', 'avm_no_key', 'avm_no_provider', 'avm_insufficient_credit' ),
			true
		);
	}

	/**
	 * Whether the monthly query allowance still has room (spec §16).
	 *
	 * @return bool
	 */
	public static function allowance_remaining() {
		$allowance = (int) Settings::get( 'monthly_allowance', 200 );
		if ( $allowance <= 0 ) {
			return true; // 0 = unlimited.
		}
		$used = ( new RunsRepository() )->count_since( gmdate( 'Y-m-01 00:00:00' ) );
		return $used < $allowance;
	}

	/**
	 * Queue status snapshot for diagnostics.
	 *
	 * @return array
	 */
	public static function status() {
		global $wpdb;
		$table = $wpdb->prefix . 'avm_queue';
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array( 'queued' => 0, 'processing' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		$out['next_cron']    = wp_next_scheduled( self::CRON_HOOK );
		$out['next_enqueue'] = wp_next_scheduled( self::ENQUEUE_HOOK );
		return $out;
	}
}
