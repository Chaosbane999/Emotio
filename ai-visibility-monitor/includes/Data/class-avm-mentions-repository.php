<?php
/**
 * Mentions repository (spec §10.2, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class MentionsRepository extends Repository {

	protected $table = 'avm_mentions';

	protected $formats = array(
		'result_id'      => '%d',
		'organisation'   => '%s',
		'ownership'      => '%s',
		'position'       => '%d',
		'classification' => '%s',
		'created_at'     => '%s',
	);

	/**
	 * Mentions for a result.
	 *
	 * @param int $result_id Result ID.
	 * @return array
	 */
	public function for_result( $result_id ) {
		return $this->all( array( 'result_id' => (int) $result_id ), 'position', 'ASC' );
	}

	/**
	 * Share-of-voice counts by organisation across all results.
	 *
	 * @return array Rows of organisation, ownership, appearances.
	 */
	public function share_of_voice() {
		global $wpdb;
		$sql = 'SELECT organisation, ownership, COUNT(*) AS appearances
			FROM ' . $this->table() . '
			GROUP BY organisation, ownership
			ORDER BY appearances DESC';
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
