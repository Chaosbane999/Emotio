<?php
/**
 * Normalised provider response DTO (spec §8.4).
 *
 * @package AVM
 */

namespace AVM\Providers;

defined( 'ABSPATH' ) || exit;

class ProviderResponse {

	/** @var string */
	public $answer = '';

	/** @var string[] Cited URLs. */
	public $citations = array();

	/** @var string */
	public $model = '';

	/** @var int */
	public $prompt_tokens = 0;

	/** @var int */
	public $completion_tokens = 0;

	/** @var float */
	public $estimated_cost = 0.0;

	/**
	 * @param string $answer Generated answer text.
	 */
	public function __construct( $answer = '' ) {
		$this->answer = (string) $answer;
	}
}
