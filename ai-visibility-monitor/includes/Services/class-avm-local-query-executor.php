<?php
/**
 * Local query executor (spec §25, initial version).
 *
 * Runs the full pipeline inside WordPress: frame prompt -> call provider ->
 * store run -> analyse answer -> store result/citations/mentions -> update
 * prompt schedule -> generate recommendations.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Providers\ProviderRegistry;
use AVM\Support\Settings;
use AVM\Support\Logger;
use AVM\Data\PromptsRepository;
use AVM\Data\RunsRepository;
use AVM\Data\ResultsRepository;

defined( 'ABSPATH' ) || exit;

class LocalQueryExecutor implements QueryExecutorInterface {

	/** @var AnalysisService */
	private $analysis;

	/** @var RecommendationsService */
	private $recommendations;

	public function __construct() {
		$this->analysis        = new AnalysisService();
		$this->recommendations = new RecommendationsService();
	}

	/**
	 * Execute one prompt end to end.
	 *
	 * @param array $prompt Prompt row.
	 * @return int|\WP_Error
	 */
	public function execute( array $prompt ) {
		$prompt_id     = (int) $prompt['id'];
		$provider_slug = Settings::get( 'provider', 'openai' );
		$model         = Settings::get( 'model', 'gpt-4o-mini' );
		$provider      = ProviderRegistry::get( $provider_slug );
		$runs          = new RunsRepository();

		if ( ! $provider ) {
			return new \WP_Error( 'avm_no_provider', __( 'No AI provider is configured.', 'ai-visibility-monitor' ) );
		}

		$api_key = Settings::get_api_key( $provider_slug );
		if ( '' === $api_key ) {
			return new \WP_Error( 'avm_no_key', __( 'No API key is configured for the selected provider.', 'ai-visibility-monitor' ) );
		}

		// Record a pending run first so failures are still auditable.
		$run_id = $runs->insert(
			array(
				'prompt_id' => $prompt_id,
				'provider'  => $provider_slug,
				'model'     => $model,
				'status'    => 'running',
			)
		);

		$framed   = $this->frame_prompt( $prompt );
		$response = $provider->query(
			$framed,
			array(
				'api_key'    => $api_key,
				'model'      => $model,
				'geo'        => $prompt['geo_context'],
				'max_tokens' => 700,
			)
		);

		if ( is_wp_error( $response ) ) {
			$runs->update(
				$run_id,
				array(
					'status'     => 'failed',
					'error_code' => $response->get_error_code(),
				)
			);
			Logger::error( 'query_execute', 'Query failed for prompt ' . $prompt_id, array( 'error' => $response->get_error_code() ) );
			return $response;
		}

		$runs->update(
			$run_id,
			array(
				'status'            => 'completed',
				'model'             => $response->model,
				'prompt_tokens'     => $response->prompt_tokens,
				'completion_tokens' => $response->completion_tokens,
				'estimated_cost'    => $response->estimated_cost,
			)
		);

		// Analyse and persist result/citations/mentions.
		$result_id = $this->analysis->analyse_and_store( $run_id, $prompt, $response );

		// Advance the prompt's schedule.
		$this->reschedule( $prompt );

		// Turn findings into recommendations.
		$this->recommendations->generate_for_prompt( $prompt, $result_id );

		return $result_id;
	}

	/**
	 * Build a controlled, cost-aware query string (spec §8.3).
	 *
	 * @param array $prompt Prompt row.
	 * @return string
	 */
	private function frame_prompt( array $prompt ) {
		$text = trim( $prompt['prompt_text'] );
		$geo  = trim( $prompt['geo_context'] );
		if ( '' !== $geo ) {
			$text .= "\n\nContext: focus on " . $geo . '.';
		}
		$text .= "\nAnswer concisely and name specific companies and their websites.";
		return $text;
	}

	/**
	 * Set last_run_at now and next_run_at based on frequency.
	 *
	 * @param array $prompt Prompt row.
	 */
	private function reschedule( array $prompt ) {
		$prompts   = new PromptsRepository();
		$frequency = $prompt['frequency'];
		$interval  = 'weekly' === $frequency ? WEEK_IN_SECONDS : MONTH_IN_SECONDS;
		if ( 'manual' === $frequency ) {
			$next = null;
		} else {
			$next = gmdate( 'Y-m-d H:i:s', time() + $interval );
		}

		$prompts->update(
			(int) $prompt['id'],
			array(
				'last_run_at' => current_time( 'mysql' ),
				'next_run_at' => $next,
			)
		);
	}
}
