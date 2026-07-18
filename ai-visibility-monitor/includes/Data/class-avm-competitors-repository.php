<?php
/**
 * Competitors repository (spec §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class CompetitorsRepository extends Repository {

	protected $table = 'avm_competitors';

	protected $formats = array(
		'name'       => '%s',
		'alt_names'  => '%s',
		'domain'     => '%s',
		'category'   => '%s',
		'priority'   => '%d',
		'status'     => '%s',
		'created_at' => '%s',
	);

	/**
	 * All active competitors.
	 *
	 * @return array
	 */
	public function active() {
		return $this->all( array( 'status' => 'active' ), 'priority', 'ASC' );
	}
}
