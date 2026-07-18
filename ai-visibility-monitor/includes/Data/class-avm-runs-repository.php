<?php
/**
 * Query-run repository (spec §8.4, §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class RunsRepository extends Repository {

	protected $table = 'avm_runs';

	protected $formats = array(
		'prompt_id'         => '%d',
		'provider'          => '%s',
		'model'             => '%s',
		'status'            => '%s',
		'prompt_tokens'     => '%d',
		'completion_tokens' => '%d',
		'estimated_cost'    => '%f',
		'error_code'        => '%s',
		'created_at'        => '%s',
	);

	/**
	 * Count runs (billable queries) created since a MySQL datetime.
	 *
	 * @param string $since MySQL datetime.
	 * @return int
	 */
	public function count_since( $since ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE created_at >= %s',
				$since
			)
		);
	}

	/**
	 * Sum of estimated cost since a MySQL datetime.
	 *
	 * @param string $since MySQL datetime.
	 * @return float
	 */
	public function cost_since( $since ) {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(estimated_cost),0) FROM ' . $this->table() . ' WHERE created_at >= %s',
				$since
			)
		);
	}
}
