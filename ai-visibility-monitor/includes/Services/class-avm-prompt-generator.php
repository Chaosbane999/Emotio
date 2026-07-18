<?php
/**
 * Suggested prompt generator (spec §7.1).
 *
 * Produces draft monitoring questions from the brand profile. Prompts are
 * always created as drafts and require explicit approval before monitoring.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Data\BrandsRepository;
use AVM\Data\PromptsRepository;

defined( 'ABSPATH' ) || exit;

class PromptGenerator {

	/**
	 * Build suggested prompt texts (not yet persisted).
	 *
	 * @param array $profile Brand profile fields.
	 * @return array List of [ text, category, priority ].
	 */
	public function suggest( array $profile ) {
		$services = array_filter( array_map( 'trim', (array) ( $profile['services'] ?? array() ) ) );
		$sectors  = array_filter( array_map( 'trim', (array) ( $profile['sectors'] ?? array() ) ) );
		$geo      = trim( (string) ( $profile['geo'] ?? '' ) );
		$brand    = trim( (string) ( $profile['brand'] ?? '' ) );

		$geo_suffix = '' !== $geo ? ' in ' . $geo : '';
		$prompts    = array();

		foreach ( $services as $service ) {
			$prompts[] = array(
				'text'     => sprintf( 'Which are the best companies for %s%s?', $service, $geo_suffix ),
				'category' => 'service',
				'priority' => 'priority',
			);
			$prompts[] = array(
				'text'     => sprintf( 'Who should I hire for %s%s?', $service, $geo_suffix ),
				'category' => 'buyer_problem',
				'priority' => 'supporting',
			);
		}

		foreach ( $sectors as $sector ) {
			$prompts[] = array(
				'text'     => sprintf( 'What companies specialise in %s%s?', $sector, $geo_suffix ),
				'category' => 'sector',
				'priority' => 'supporting',
			);
		}

		if ( '' !== $brand ) {
			$prompts[] = array(
				'text'     => sprintf( 'What is %s known for and who are their main competitors?', $brand ),
				'category' => 'brand',
				'priority' => 'discovery',
			);
		}

		return $prompts;
	}

	/**
	 * Persist a set of suggested prompts as drafts.
	 *
	 * @param array $suggestions Output of suggest().
	 * @return int Number of prompts created.
	 */
	public function store_drafts( array $suggestions ) {
		$repo    = new PromptsRepository();
		$created = 0;
		foreach ( $suggestions as $s ) {
			$text = sanitize_textarea_field( $s['text'] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$repo->insert(
				array(
					'prompt_text' => $text,
					'category'    => sanitize_key( $s['category'] ?? 'informational' ),
					'priority'    => sanitize_key( $s['priority'] ?? 'supporting' ),
					'status'      => 'draft',
					'frequency'   => 'monthly',
				)
			);
			++$created;
		}
		return $created;
	}
}
