<?php
/**
 * Citations repository (spec §10.5, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class CitationsRepository extends Repository {

	protected $table = 'avm_citations';

	protected $formats = array(
		'result_id'  => '%d',
		'url'        => '%s',
		'domain'     => '%s',
		'page_id'    => '%d',
		'page_type'  => '%s',
		'ownership'  => '%s',
		'created_at' => '%s',
	);

	/**
	 * Citations for a result.
	 *
	 * @param int $result_id Result ID.
	 * @return array
	 */
	public function for_result( $result_id ) {
		return $this->all( array( 'result_id' => (int) $result_id ), 'id', 'ASC' );
	}

	/**
	 * Aggregate most-cited customer pages.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function top_owned_pages( $limit = 20 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT url, domain, page_id, COUNT(*) AS hits, MIN(created_at) AS first_cited, MAX(created_at) AS last_cited
			 FROM ' . $this->table() . "
			 WHERE ownership = 'customer'
			 GROUP BY url, domain, page_id
			 ORDER BY hits DESC
			 LIMIT %d",
			(int) $limit
		);
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
