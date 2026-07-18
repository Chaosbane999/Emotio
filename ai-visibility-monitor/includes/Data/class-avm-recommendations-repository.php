<?php
/**
 * Recommendations repository (spec §15, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class RecommendationsRepository extends Repository {

	protected $table = 'avm_recommendations';

	protected $formats = array(
		'prompt_id'  => '%d',
		'page_id'    => '%d',
		'type'       => '%s',
		'priority'   => '%s',
		'status'     => '%s',
		'evidence'   => '%s',
		'action'     => '%s',
		'notes'      => '%s',
		'created_at' => '%s',
		'updated_at' => '%s',
	);

	/**
	 * Open recommendations, highest priority first.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function open( $limit = 0 ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . $this->table() . "
			WHERE status = 'open'
			ORDER BY FIELD(priority, 'high','medium','low'), created_at DESC";
		if ( $limit > 0 ) {
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Whether an equivalent open recommendation already exists (dedupe).
	 *
	 * @param int    $prompt_id Prompt ID.
	 * @param string $type      Recommendation type.
	 * @return bool
	 */
	public function exists_open( $prompt_id, $type ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->table() . " WHERE prompt_id = %d AND type = %s AND status = 'open' LIMIT 1",
				(int) $prompt_id,
				$type
			)
		);
	}
}
