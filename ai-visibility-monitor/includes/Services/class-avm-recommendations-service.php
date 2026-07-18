<?php
/**
 * Recommendations engine (spec §15).
 *
 * Translates monitoring findings into practical, evidenced actions. Runs after
 * each analysis and is idempotent per (prompt, type) while a rec is still open.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Data\ResultsRepository;
use AVM\Data\CitationsRepository;
use AVM\Data\MentionsRepository;
use AVM\Data\RecommendationsRepository;

defined( 'ABSPATH' ) || exit;

class RecommendationsService {

	/**
	 * Generate recommendations for a freshly analysed prompt result.
	 *
	 * @param array $prompt    Prompt row.
	 * @param int   $result_id Result ID.
	 */
	public function generate_for_prompt( array $prompt, $result_id ) {
		if ( ! $result_id ) {
			return;
		}

		$results = new ResultsRepository();
		$result  = $results->find( $result_id );
		if ( ! $result ) {
			return;
		}

		$repo      = new RecommendationsRepository();
		$mentions  = ( new MentionsRepository() )->for_result( $result_id );
		$citations = ( new CitationsRepository() )->for_result( $result_id );

		$competitor_hits = array_filter(
			$mentions,
			static function ( $m ) {
				return 'competitor' === $m['ownership'];
			}
		);
		$competitor_pages = array_filter(
			$citations,
			static function ( $c ) {
				return 'competitor' === $c['ownership'];
			}
		);

		// Case 1: brand absent but competitors present -> highest-value gap.
		if ( ! (int) $result['brand_detected'] && ! empty( $competitor_hits ) ) {
			if ( ! $repo->exists_open( $prompt['id'], 'improve_page' ) ) {
				$names = wp_list_pluck( $competitor_hits, 'organisation' );
				$repo->insert(
					array(
						'prompt_id' => (int) $prompt['id'],
						'page_id'   => (int) $prompt['target_page_id'],
						'type'      => 'improve_page',
						'priority'  => 'high',
						'evidence'  => sprintf(
							/* translators: %1$d competitor count, %2$s competitor names */
							__( '%1$d monitored competitor(s) appeared (%2$s) and %3$d competitor page(s) were cited, but the brand did not appear.', 'ai-visibility-monitor' ),
							count( $competitor_hits ),
							implode( ', ', array_slice( $names, 0, 5 ) ),
							count( $competitor_pages )
						),
						'action'    => __( 'Expand the target page to clearly cover this topic, add proof points and case studies, and answer the underlying buyer question directly.', 'ai-visibility-monitor' ),
					)
				);
			}
		}

		// Case 2: brand mentioned but never cited -> crawlability/citation gap.
		if ( (int) $result['brand_detected'] && ! (int) $result['citation_detected'] ) {
			if ( ! $repo->exists_open( $prompt['id'], 'improve_crawlability' ) ) {
				$repo->insert(
					array(
						'prompt_id' => (int) $prompt['id'],
						'page_id'   => (int) $prompt['target_page_id'],
						'type'      => 'improve_crawlability',
						'priority'  => 'medium',
						'evidence'  => __( 'The brand was mentioned but the website was not cited as a source in the answer.', 'ai-visibility-monitor' ),
						'action'    => __( 'Ensure the relevant page is indexable, well-structured, and provides a clear, quotable answer with supporting evidence and structured data.', 'ai-visibility-monitor' ),
					)
				);
			}
		}
	}
}
