<?php
/**
 * Results repository (spec §10, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class ResultsRepository extends Repository {

	protected $table = 'avm_results';

	protected $formats = array(
		'run_id'            => '%d',
		'prompt_id'         => '%d',
		'answer'            => '%s',
		'brand_detected'    => '%d',
		'citation_detected' => '%d',
		'recommendation'    => '%s',
		'brand_position'    => '%d',
		'confidence'        => '%f',
		'created_at'        => '%s',
	);

	/**
	 * The latest result for a prompt.
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return array|null
	 */
	public function latest_for_prompt( $prompt_id ) {
		$rows = $this->all( array( 'prompt_id' => (int) $prompt_id ), 'created_at', 'DESC', 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * The two most recent results for a prompt (current + previous) for change detection.
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return array
	 */
	public function last_two_for_prompt( $prompt_id ) {
		return $this->all( array( 'prompt_id' => (int) $prompt_id ), 'created_at', 'DESC', 2 );
	}

	/**
	 * Results created within a date window (for period metrics).
	 *
	 * @param string $since MySQL datetime.
	 * @param string $until MySQL datetime.
	 * @return array
	 */
	public function between( $since, $until ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT * FROM ' . $this->table() . ' WHERE created_at >= %s AND created_at <= %s',
			$since,
			$until
		);
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
