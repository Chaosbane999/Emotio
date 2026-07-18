<?php
/**
 * Metrics and AI Visibility Score (spec §11).
 *
 * The score is composed from weighted components and is explicitly NOT presented
 * as an official score belonging to any AI platform (spec §11.2). Component
 * scores are always exposed alongside the total.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Data\ResultsRepository;
use AVM\Data\MentionsRepository;
use AVM\Data\PromptsRepository;

defined( 'ABSPATH' ) || exit;

class ScoringService {

	/**
	 * Component weights (spec §11.2). Must sum to 1.0.
	 *
	 * @var array
	 */
	private $weights = array(
		'citation_rate'       => 0.35,
		'recommendation_rate' => 0.20,
		'share_of_voice'      => 0.20,
		'prompt_coverage'     => 0.10,
		'technical_readiness' => 0.10,
		'consistency'         => 0.05,
	);

	/**
	 * Compute the full metrics bundle for the dashboard.
	 *
	 * @return array
	 */
	public function metrics() {
		$results_repo = new ResultsRepository();
		$results      = $results_repo->all( array(), 'created_at', 'DESC', 2000 );
		$total        = count( $results );

		$brand_hits    = 0;
		$citation_hits = 0;
		$recommended   = 0;
		foreach ( $results as $r ) {
			$brand_hits    += (int) $r['brand_detected'];
			$citation_hits += (int) $r['citation_detected'];
			if ( in_array( $r['recommendation'], array( 'strong', 'listed' ), true ) ) {
				++$recommended;
			}
		}

		$mention_rate        = $this->rate( $brand_hits, $total );
		$citation_rate       = $this->rate( $citation_hits, $total );
		$recommendation_rate = $this->rate( $recommended, $total );
		$share_of_voice      = $this->share_of_voice();
		$prompt_coverage     = $this->prompt_coverage();
		$technical_readiness = (float) get_option( 'avm_technical_readiness', 0 );
		$consistency         = $this->consistency();

		$components = array(
			'citation_rate'       => $citation_rate,
			'recommendation_rate' => $recommendation_rate,
			'share_of_voice'      => $share_of_voice,
			'prompt_coverage'     => $prompt_coverage,
			'technical_readiness' => $technical_readiness,
			'consistency'         => $consistency,
		);

		$score = 0.0;
		foreach ( $this->weights as $key => $weight ) {
			$score += ( $components[ $key ] / 100 ) * $weight * 100;
		}

		return array(
			'score'               => (int) round( $score ),
			'components'          => $components,
			'weights'             => $this->weights,
			'mention_rate'        => $mention_rate,
			'citation_rate'       => $citation_rate,
			'recommendation_rate' => $recommendation_rate,
			'share_of_voice'      => $share_of_voice,
			'prompt_coverage'     => $prompt_coverage,
			'total_tests'         => $total,
			'confidence'          => $this->confidence_label( $total ),
			'change'              => $this->period_change( $score ),
		);
	}

	/**
	 * Customer share of voice vs competitors (spec §11.1).
	 *
	 * @return float 0..100
	 */
	private function share_of_voice() {
		$rows     = ( new MentionsRepository() )->share_of_voice();
		$customer = 0;
		$total    = 0;
		foreach ( $rows as $row ) {
			$total += (int) $row['appearances'];
			if ( 'customer' === $row['ownership'] ) {
				$customer += (int) $row['appearances'];
			}
		}
		return $this->rate( $customer, $total );
	}

	/**
	 * Percentage of active prompts where the brand appeared at least once.
	 *
	 * @return float 0..100
	 */
	private function prompt_coverage() {
		$prompts = ( new PromptsRepository() )->active();
		if ( empty( $prompts ) ) {
			return 0.0;
		}
		$results = new ResultsRepository();
		$covered = 0;
		foreach ( $prompts as $prompt ) {
			$latest = $results->latest_for_prompt( $prompt['id'] );
			if ( $latest && (int) $latest['brand_detected'] ) {
				++$covered;
			}
		}
		return $this->rate( $covered, count( $prompts ) );
	}

	/**
	 * Citation consistency across the two most recent tests per prompt.
	 *
	 * @return float 0..100
	 */
	private function consistency() {
		$prompts = ( new PromptsRepository() )->active();
		$results = new ResultsRepository();
		$stable  = 0;
		$counted = 0;
		foreach ( $prompts as $prompt ) {
			$last_two = $results->last_two_for_prompt( $prompt['id'] );
			if ( count( $last_two ) < 2 ) {
				continue;
			}
			++$counted;
			if ( (int) $last_two[0]['brand_detected'] === (int) $last_two[1]['brand_detected'] ) {
				++$stable;
			}
		}
		return $counted ? $this->rate( $stable, $counted ) : 0.0;
	}

	/**
	 * Confidence label (spec §11.3).
	 *
	 * @param int $total_tests Completed tests.
	 * @return string
	 */
	private function confidence_label( $total_tests ) {
		if ( $total_tests < 10 ) {
			return __( 'Limited data', 'ai-visibility-monitor' );
		}
		if ( $total_tests < 40 ) {
			return __( 'Developing confidence', 'ai-visibility-monitor' );
		}
		if ( $total_tests < 120 ) {
			return __( 'Moderate confidence', 'ai-visibility-monitor' );
		}
		return __( 'Strong confidence', 'ai-visibility-monitor' );
	}

	/**
	 * Store the current score and return the delta versus the last snapshot.
	 *
	 * @param float $score Current score.
	 * @return int
	 */
	private function period_change( $score ) {
		$previous = (float) get_option( 'avm_last_score', $score );
		update_option( 'avm_last_score', round( $score, 2 ) );
		return (int) round( $score - $previous );
	}

	/**
	 * Safe percentage.
	 *
	 * @param int $part  Numerator.
	 * @param int $whole Denominator.
	 * @return float
	 */
	private function rate( $part, $whole ) {
		return $whole > 0 ? round( ( $part / $whole ) * 100, 1 ) : 0.0;
	}
}
