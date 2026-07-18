<?php
/**
 * Base repository: thin, safe wrapper over $wpdb for a single table.
 *
 * All queries are parameterised via $wpdb->prepare or the built-in
 * insert/update/delete helpers (spec §17.2 prepared queries).
 *
 * @package AVM
 */

namespace AVM\Data;

defined( 'ABSPATH' ) || exit;

abstract class Repository {

	/**
	 * Base table name (without prefix). Set by subclasses.
	 *
	 * @var string
	 */
	protected $table = '';

	/**
	 * Column => printf format map for writes.
	 *
	 * @var array
	 */
	protected $formats = array();

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . $this->table;
	}

	/**
	 * Insert a row and return the new ID (0 on failure).
	 *
	 * @param array $data Column values.
	 * @return int
	 */
	public function insert( array $data ) {
		global $wpdb;
		$data = $this->with_timestamps( $data, true );
		$ok   = $wpdb->insert( $this->table(), $data, $this->formats_for( $data ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row by ID.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column values.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$data = $this->with_timestamps( $data, false );
		return false !== $wpdb->update(
			$this->table(),
			$data,
			array( 'id' => (int) $id ),
			$this->formats_for( $data ),
			array( '%d' )
		);
	}

	/**
	 * Fetch a single row by ID as an associative array.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Delete a row by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Count rows, optionally with a simple equality WHERE map.
	 *
	 * @param array $where Column => value equality filters.
	 * @return int
	 */
	public function count( array $where = array() ) {
		global $wpdb;
		list( $clause, $params ) = $this->build_where( $where );
		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . $clause;
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Fetch all rows matching a simple equality WHERE map, ordered.
	 *
	 * @param array  $where   Column => value equality filters.
	 * @param string $orderby Column to order by (validated against formats).
	 * @param string $dir     ASC|DESC.
	 * @param int    $limit   Max rows (0 = no limit).
	 * @param int    $offset  Offset.
	 * @return array
	 */
	public function all( array $where = array(), $orderby = 'id', $dir = 'DESC', $limit = 0, $offset = 0 ) {
		global $wpdb;
		list( $clause, $params ) = $this->build_where( $where );

		$orderby = $this->safe_column( $orderby );
		$dir     = 'ASC' === strtoupper( $dir ) ? 'ASC' : 'DESC';

		$sql = 'SELECT * FROM ' . $this->table() . $clause . " ORDER BY {$orderby} {$dir}";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $limit;
			$params[] = (int) $offset;
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Build a parameterised WHERE clause from an equality map.
	 *
	 * @param array $where Column => value.
	 * @return array [ string $clause, array $params ]
	 */
	protected function build_where( array $where ) {
		if ( empty( $where ) ) {
			return array( '', array() );
		}
		$parts  = array();
		$params = array();
		foreach ( $where as $col => $val ) {
			$col     = $this->safe_column( $col );
			$parts[] = "{$col} = %s";
			$params[] = $val;
		}
		return array( ' WHERE ' . implode( ' AND ', $parts ), $params );
	}

	/**
	 * Only allow known columns in ORDER BY / WHERE to prevent injection.
	 *
	 * @param string $column Candidate column.
	 * @return string
	 */
	protected function safe_column( $column ) {
		$allowed = array_merge( array( 'id' ), array_keys( $this->formats ) );
		return in_array( $column, $allowed, true ) ? $column : 'id';
	}

	/**
	 * Return the format array matching a subset of columns.
	 *
	 * @param array $data Column values.
	 * @return array
	 */
	protected function formats_for( array $data ) {
		$out = array();
		foreach ( array_keys( $data ) as $col ) {
			$out[] = isset( $this->formats[ $col ] ) ? $this->formats[ $col ] : '%s';
		}
		return $out;
	}

	/**
	 * Add created_at / updated_at automatically where those columns exist.
	 *
	 * @param array $data     Column values.
	 * @param bool  $creating Whether this is an insert.
	 * @return array
	 */
	protected function with_timestamps( array $data, $creating ) {
		$now = current_time( 'mysql' );
		if ( $creating && isset( $this->formats['created_at'] ) && ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( isset( $this->formats['updated_at'] ) && ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}
		return $data;
	}
}
