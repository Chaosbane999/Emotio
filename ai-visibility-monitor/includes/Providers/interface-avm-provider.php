<?php
/**
 * Standard internal interface every AI provider must implement (spec §8.2).
 *
 * Keeps the reporting system decoupled from any single provider.
 *
 * @package AVM
 */

namespace AVM\Providers;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/**
	 * Machine slug, e.g. "openai".
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the provider can return source citations/URLs.
	 *
	 * @return bool
	 */
	public function supports_citations();

	/**
	 * Verify credentials with a lightweight call.
	 *
	 * @param string $api_key API key.
	 * @return true|\WP_Error
	 */
	public function test_connection( $api_key );

	/**
	 * Submit a monitoring query and return a normalised response.
	 *
	 * @param string $prompt  Approved question, already framed with context.
	 * @param array  $options model, geo, market, api_key, max_tokens.
	 * @return ProviderResponse|\WP_Error
	 */
	public function query( $prompt, array $options );

	/**
	 * Estimate the cost in USD for a token usage pair.
	 *
	 * @param string $model             Model identifier.
	 * @param int    $prompt_tokens     Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @return float
	 */
	public function estimate_cost( $model, $prompt_tokens, $completion_tokens );
}
