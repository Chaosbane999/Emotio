<?php
/**
 * Perplexity provider (spec §8 — second provider).
 *
 * Perplexity's chat/completions endpoint is search-grounded and returns a
 * `citations` array of source URLs, so unlike the OpenAI launch provider it
 * supplies real citations — the single most valuable signal for the visibility
 * score (website citation rate is 35% of it). Adding this provider also proves
 * the provider abstraction with a genuinely different implementation.
 *
 * @package AVM
 */

namespace AVM\Providers;

use AVM\Support\Logger;

defined( 'ABSPATH' ) || exit;

class PerplexityProvider implements ProviderInterface {

	const API_BASE = 'https://api.perplexity.ai';

	/**
	 * Per-1K-token pricing in USD (prompt, completion). Estimates only.
	 *
	 * @var array
	 */
	private $pricing = array(
		'sonar'     => array( 0.00100, 0.00100 ),
		'sonar-pro' => array( 0.00300, 0.01500 ),
	);

	public function slug() {
		return 'perplexity';
	}

	public function label() {
		return 'Perplexity';
	}

	public function supports_citations() {
		return true;
	}

	/**
	 * Perplexity has no lightweight auth endpoint, so probe with a tiny query.
	 *
	 * @param string $api_key API key.
	 * @return true|\WP_Error
	 */
	public function test_connection( $api_key ) {
		$response = $this->request( $api_key, 'sonar', 'Reply with the single word: ok', 5 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Submit a monitoring query.
	 *
	 * @param string $prompt  Framed question.
	 * @param array  $options api_key, model, max_tokens.
	 * @return ProviderResponse|\WP_Error
	 */
	public function query( $prompt, array $options ) {
		$api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$model   = isset( $options['model'] ) ? $options['model'] : 'sonar';
		$max     = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 700;

		if ( '' === $api_key ) {
			return new \WP_Error( 'avm_no_key', __( 'No API key configured.', 'ai-visibility-monitor' ) );
		}

		$response = $this->request( $api_key, $model, $prompt, $max );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'avm_malformed_response', __( 'The provider returned an unexpected response.', 'ai-visibility-monitor' ) );
		}

		$result            = new ProviderResponse( $response['choices'][0]['message']['content'] );
		$result->model     = isset( $response['model'] ) ? $response['model'] : $model;
		$result->citations = $this->extract_citations( $response );

		$result->prompt_tokens     = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : 0;
		$result->completion_tokens = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
		$result->estimated_cost    = $this->estimate_cost( $model, $result->prompt_tokens, $result->completion_tokens );

		return $result;
	}

	/**
	 * Estimate USD cost from token usage.
	 *
	 * @param string $model             Model.
	 * @param int    $prompt_tokens     Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @return float
	 */
	public function estimate_cost( $model, $prompt_tokens, $completion_tokens ) {
		$pricing = apply_filters( 'avm_provider_pricing', $this->pricing, $this->slug() );
		$rates   = isset( $pricing[ $model ] ) ? $pricing[ $model ] : reset( $pricing );
		$cost    = ( $prompt_tokens / 1000 ) * $rates[0] + ( $completion_tokens / 1000 ) * $rates[1];
		return round( $cost, 5 );
	}

	/**
	 * Perform a chat/completions request and return the decoded body.
	 *
	 * @param string $api_key    API key.
	 * @param string $model      Model.
	 * @param string $prompt     User content.
	 * @param int    $max_tokens Max completion tokens.
	 * @return array|\WP_Error
	 */
	private function request( $api_key, $model, $prompt, $max_tokens ) {
		$body = array(
			'model'       => $model,
			'temperature' => 0.2,
			'max_tokens'  => (int) $max_tokens,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are a concise research assistant. Recommend specific companies and websites by name where relevant. Keep answers under 250 words.',
				),
				array( 'role' => 'user', 'content' => $prompt ),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'avm_service_unavailable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$map = array(
				401 => 'avm_invalid_key',
				402 => 'avm_insufficient_credit',
				429 => 'avm_rate_limited',
				500 => 'avm_service_unavailable',
				503 => 'avm_service_unavailable',
			);
			$err = isset( $map[ $code ] ) ? $map[ $code ] : 'avm_provider_error';
			Logger::error( 'provider_query', 'Perplexity query failed', array( 'status' => $code, 'code' => $err ) );
			return new \WP_Error( $err, sprintf( /* translators: %d: HTTP status */ __( 'Provider returned status %d.', 'ai-visibility-monitor' ), $code ) );
		}

		return is_array( $json ) ? $json : new \WP_Error( 'avm_malformed_response', __( 'The provider returned an unexpected response.', 'ai-visibility-monitor' ) );
	}

	/**
	 * Extract citation URLs, tolerating both the legacy flat `citations`
	 * array and the newer `search_results` objects.
	 *
	 * @param array $response Decoded response.
	 * @return string[]
	 */
	private function extract_citations( array $response ) {
		$urls = array();

		if ( ! empty( $response['citations'] ) && is_array( $response['citations'] ) ) {
			foreach ( $response['citations'] as $entry ) {
				$url = is_array( $entry ) ? ( $entry['url'] ?? '' ) : $entry;
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		if ( ! empty( $response['search_results'] ) && is_array( $response['search_results'] ) ) {
			foreach ( $response['search_results'] as $entry ) {
				if ( ! empty( $entry['url'] ) ) {
					$urls[] = $entry['url'];
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
