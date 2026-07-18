<?php
/**
 * Query executor contract (spec §25).
 *
 * This is the single seam that decouples the WordPress interface from where
 * queries actually run. The MVP ships LocalQueryExecutor (calls the provider
 * from WordPress). A later release can drop in RemoteQueryExecutor that calls
 * the Emotio managed API — with no change to the reporting or UI layers.
 *
 * @package AVM
 */

namespace AVM\Services;

defined( 'ABSPATH' ) || exit;

interface QueryExecutorInterface {

	/**
	 * Execute a monitoring query for a single prompt row and persist a run,
	 * a result, its citations and mentions.
	 *
	 * @param array $prompt Prompt row (associative).
	 * @return int|\WP_Error Result ID on success, WP_Error on failure.
	 */
	public function execute( array $prompt );
}
