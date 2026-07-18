<?php
/**
 * WordPress website readiness analysis (spec §14).
 *
 * Runs lightweight technical/content checks against a sample of public content
 * and produces a technical-readiness score (0..100) feeding the visibility
 * score's 10% technical component. Positioned as readiness indicators, not
 * guarantees of AI inclusion (spec §14.3).
 *
 * @package AVM
 */

namespace AVM\Services;

defined( 'ABSPATH' ) || exit;

class SiteAnalyzer {

	/**
	 * Analyse a sample of published pages/posts and store a readiness score.
	 *
	 * @param int $sample_size Max posts to inspect.
	 * @return array Report: score and per-check tallies.
	 */
	public function analyse( $sample_size = 30 ) {
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => (int) $sample_size,
				'no_found_rows'  => true,
			)
		);

		$checks = array(
			'has_title'       => 0,
			'has_excerpt'     => 0,
			'has_headings'    => 0,
			'is_indexable'    => 0,
			'has_schema'      => 0,
			'sufficient_body' => 0,
		);
		$count = count( $posts );

		foreach ( $posts as $post ) {
			$content = (string) $post->post_content;

			if ( '' !== trim( $post->post_title ) ) {
				++$checks['has_title'];
			}
			if ( '' !== trim( $post->post_excerpt ) || str_word_count( wp_strip_all_tags( $content ) ) > 40 ) {
				++$checks['has_excerpt'];
			}
			if ( preg_match( '/<h[1-3][ >]/i', $content ) ) {
				++$checks['has_headings'];
			}
			// Indexable unless a noindex robots meta setting is present.
			$noindex = 'noindex' === get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
			if ( ! $noindex ) {
				++$checks['is_indexable'];
			}
			if ( false !== stripos( $content, 'application/ld+json' ) || $this->post_has_schema_plugin() ) {
				++$checks['has_schema'];
			}
			if ( str_word_count( wp_strip_all_tags( $content ) ) >= 150 ) {
				++$checks['sufficient_body'];
			}
		}

		$score = 0;
		if ( $count > 0 ) {
			$weights = array(
				'has_title'       => 15,
				'has_excerpt'     => 15,
				'has_headings'    => 15,
				'is_indexable'    => 25,
				'has_schema'      => 15,
				'sufficient_body' => 15,
			);
			foreach ( $weights as $key => $weight ) {
				$score += ( $checks[ $key ] / $count ) * $weight;
			}
		}
		$score = (int) round( $score );

		update_option( 'avm_technical_readiness', $score );
		update_option( 'avm_site_checks', $checks );
		update_option( 'avm_site_sample', $count );

		return array(
			'score'  => $score,
			'checks' => $checks,
			'sample' => $count,
		);
	}

	/**
	 * Whether a known schema plugin (e.g. AI Schema Pro) is active (spec §14.4).
	 *
	 * @return bool
	 */
	private function post_has_schema_plugin() {
		return defined( 'AI_SCHEMA_PRO_VERSION' ) || class_exists( 'AI_Schema_Pro' );
	}
}
