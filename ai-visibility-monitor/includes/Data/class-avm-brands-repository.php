<?php
/**
 * Brands repository (spec §19).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

class BrandsRepository extends Repository {

	protected $table = 'avm_brands';

	protected $formats = array(
		'primary_name' => '%s',
		'alt_names'    => '%s',
		'domain'       => '%s',
		'details'      => '%s',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Return the single primary brand row (the MVP tracks one brand).
	 *
	 * @return array|null
	 */
	public function primary() {
		$rows = $this->all( array(), 'id', 'ASC', 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Alternative names as an array.
	 *
	 * @param array $brand Brand row.
	 * @return string[]
	 */
	public static function alt_names( array $brand ) {
		$raw = isset( $brand['alt_names'] ) ? (array) json_decode( $brand['alt_names'], true ) : array();
		return array_filter( array_map( 'strval', $raw ) );
	}
}
