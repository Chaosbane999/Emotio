<?php
/**
 * OpenAI provider (spec §8.1 — the MVP's single launch provider).
 *
 * Uses the Chat Completions API. All requests go over HTTPS via the WordPress
 * HTTP API; credentials are passed per-request and never logged.
 *
 * @package AVM
 */

namespace AVM\Providers;

use AVM\Support\Logger;

defined( 'ABSPATH' ) || exit;

class OpenAiProvider implements ProviderInterface {

	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * Rough per-1K-token pricing in USD (prompt, completion).
	 * Cost is always presented as an estimate (spec §16.1).
	 *
	 * @var array
	 */
	private $pricing = array(
		'gpt-4o-mini' => array( 0.00015, 0.00060 ),
		'gpt-4o'      => array( 0.00250, 0.01000 ),
	);

	public function slug() {
		return 'openai';
	}

	public function label() {
		return 'OpenAI';
	}

	public function supports_citations() {
		// Chat Completions does not return structured citations; URLs are
		// parsed heuristically from the answer text by the analysis service.
		return false;
	}

	/**
	 * Lightweight credential check against the models endpoint.
	 *
	 * @param string $api_key API key.
	 * @return true|\WP_Error
	 */
	public function test_connection( $api_key ) {
		$response = wp_remote_get(
			self::API_BASE . '/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'avm_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === (int) $code ) {
			return true;
		}
		if ( 401 === (int) $code ) {
			return new \WP_Error( 'avm_invalid_key', __( 'The API key was rejected (invalid or expired).', 'ai-visibility-monitor' ) );
		}
		if ( 429 === (int) $code ) {
			return new \WP_Error( 'avm_rate_limited', __( 'Rate limited or insufficient credit.', 'ai-visibility-monitor' ) );
		}

		return new \WP_Error( 'avm_provider_error', sprintf( /* translators: %d: HTTP status */ __( 'Provider returned status %d.', 'ai-visibility-monitor' ), (int) $code ) );
	}

	/**
	 * Submit a monitoring query.
	 *
	 * @param string $prompt  Framed question.
	 * @param array  $options model, api_key, geo, market, max_tokens.
	 * @return ProviderResponse|\WP_Error
	 */
	public function query( $prompt, array $options ) {
		$api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$model   = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
		$max     = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 700;

		if ( '' === $api_key ) {
			return new \WP_Error( 'avm_no_key', __( 'No API key configured.', 'ai-visibility-monitor' ) );
		}

		$system = 'You are a concise research assistant answering as a general web '
			. 'search assistant would. Recommend specific companies, brands and '
			. 'websites by name where relevant. When you reference a company, include '
			. 'its website URL. Keep the answer under 250 words.';

		$body = array(
			'model'       => $model,
			'temperature' => 0.2,
			'max_tokens'  => $max,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
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
			Logger::error( 'provider_query', 'OpenAI query failed', array( 'status' => $code, 'code' => $err ) );
			return new \WP_Error( $err, sprintf( /* translators: %d: HTTP status */ __( 'Provider returned status %d.', 'ai-visibility-monitor' ), $code ) );
		}

		if ( empty( $json['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'avm_malformed_response', __( 'The provider returned an unexpected response.', 'ai-visibility-monitor' ) );
		}

		$result                    = new ProviderResponse( $json['choices'][0]['message']['content'] );
		$result->model             = isset( $json['model'] ) ? $json['model'] : $model;
		$result->prompt_tokens     = isset( $json['usage']['prompt_tokens'] ) ? (int) $json['usage']['prompt_tokens'] : 0;
		$result->completion_tokens = isset( $json['usage']['completion_tokens'] ) ? (int) $json['usage']['completion_tokens'] : 0;
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
		$rates = isset( $this->pricing[ $model ] ) ? $this->pricing[ $model ] : $this->pricing['gpt-4o-mini'];
		$cost  = ( $prompt_tokens / 1000 ) * $rates[0] + ( $completion_tokens / 1000 ) * $rates[1];
		return round( $cost, 5 );
	}
}
