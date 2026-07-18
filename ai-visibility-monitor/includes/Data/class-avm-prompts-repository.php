<?php
/**
 * Prompts repository (spec §7, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class PromptsRepository extends Repository {

	protected $table = 'avm_prompts';

	protected $formats = array(
		'prompt_text'    => '%s',
		'category'       => '%s',
		'priority'       => '%s',
		'status'         => '%s',
		'frequency'      => '%s',
		'target_page_id' => '%d',
		'geo_context'    => '%s',
		'last_run_at'    => '%s',
		'next_run_at'    => '%s',
		'created_at'     => '%s',
	);

	/**
	 * Active prompts (approved and monitoring).
	 *
	 * @return array
	 */
	public function active() {
		return $this->all( array( 'status' => 'active' ), 'priority', 'ASC' );
	}

	/**
	 * Count active prompts (enforces the 25-prompt MVP cap).
	 *
	 * @return int
	 */
	public function active_count() {
		return $this->count( array( 'status' => 'active' ) );
	}

	/**
	 * Prompts that are due to be monitored now.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function due( $limit = 50 ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			'SELECT * FROM ' . $this->table() .
			" WHERE status = 'active' AND ( next_run_at IS NULL OR next_run_at <= %s )" .
			' ORDER BY FIELD(priority, %s, %s, %s), next_run_at ASC LIMIT %d',
			$now,
			'priority',
			'supporting',
			'discovery',
			(int) $limit
		);
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
