<?php
/**
 * Change detection (spec §10.6, §13.5).
 *
 * Compares each prompt's two most recent results and reports what changed:
 * gained/lost mentions, gained/lost citations, and recommendation shifts.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Data\PromptsRepository;
use AVM\Data\ResultsRepository;

defined( 'ABSPATH' ) || exit;

class ChangeService {

	/**
	 * Build a list of change items across active prompts.
	 *
	 * @param int $limit Max prompts to inspect.
	 * @return array List of change rows: prompt_id, prompt, type, label, direction.
	 */
	public function recent( $limit = 100 ) {
		$prompts = ( new PromptsRepository() )->all( array( 'status' => 'active' ), 'id', 'ASC', $limit );
		$results = new ResultsRepository();
		$changes = array();

		foreach ( $prompts as $prompt ) {
			$pair = $results->last_two_for_prompt( $prompt['id'] );
			if ( count( $pair ) < 2 ) {
				continue;
			}
			list( $current, $previous ) = $pair;

			foreach ( $this->diff( $current, $previous ) as $change ) {
				$changes[] = array(
					'prompt_id' => (int) $prompt['id'],
					'prompt'    => $prompt['prompt_text'],
					'type'      => $change['type'],
					'label'     => $change['label'],
					'direction' => $change['direction'],
					'when'      => $current['created_at'],
				);
			}
		}

		// Most impactful first: losses, then gains, then neutral shifts.
		usort(
			$changes,
			static function ( $a, $b ) {
				$order = array( 'loss' => 0, 'gain' => 1, 'shift' => 2 );
				return ( $order[ $a['direction'] ] ?? 3 ) <=> ( $order[ $b['direction'] ] ?? 3 );
			}
		);

		return $changes;
	}

	/**
	 * Compute the change items between two consecutive results.
	 *
	 * @param array $current  Newer result row.
	 * @param array $previous Older result row.
	 * @return array
	 */
	private function diff( array $current, array $previous ) {
		$out = array();

		if ( (int) $current['brand_detected'] && ! (int) $previous['brand_detected'] ) {
			$out[] = array( 'type' => 'mention', 'direction' => 'gain', 'label' => __( 'Newly gained a brand mention', 'ai-visibility-monitor' ) );
		} elseif ( ! (int) $current['brand_detected'] && (int) $previous['brand_detected'] ) {
			$out[] = array( 'type' => 'mention', 'direction' => 'loss', 'label' => __( 'Lost a brand mention', 'ai-visibility-monitor' ) );
		}

		if ( (int) $current['citation_detected'] && ! (int) $previous['citation_detected'] ) {
			$out[] = array( 'type' => 'citation', 'direction' => 'gain', 'label' => __( 'Website newly cited', 'ai-visibility-monitor' ) );
		} elseif ( ! (int) $current['citation_detected'] && (int) $previous['citation_detected'] ) {
			$out[] = array( 'type' => 'citation', 'direction' => 'loss', 'label' => __( 'Website citation lost', 'ai-visibility-monitor' ) );
		}

		if ( $current['recommendation'] !== $previous['recommendation'] ) {
			$out[] = array(
				'type'      => 'recommendation',
				'direction' => 'shift',
				'label'     => sprintf(
					/* translators: 1: previous classification, 2: new classification */
					__( 'Recommendation changed from %1$s to %2$s', 'ai-visibility-monitor' ),
					ucfirst( str_replace( '_', ' ', $previous['recommendation'] ) ),
					ucfirst( str_replace( '_', ' ', $current['recommendation'] ) )
				),
			);
		}

		return $out;
	}
}
