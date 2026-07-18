<?php
/**
 * Analysis engine (spec §10).
 *
 * Deterministic, rules-based detection over the untrusted answer text:
 * brand mentions, competitor mentions, citations, recommendation strength
 * and appearance position. All output is treated as indicative, not absolute
 * (spec §10.3), and reflected in the stored confidence score.
 *
 * @package AVM
 */

namespace AVM\Services;

use AVM\Data\BrandsRepository;
use AVM\Data\CompetitorsRepository;
use AVM\Data\ResultsRepository;
use AVM\Data\CitationsRepository;
use AVM\Data\MentionsRepository;
use AVM\Providers\ProviderResponse;

defined( 'ABSPATH' ) || exit;

class AnalysisService {

	/**
	 * Analyse a provider response and persist result, citations and mentions.
	 *
	 * @param int              $run_id   Run ID.
	 * @param array            $prompt   Prompt row.
	 * @param ProviderResponse $response Normalised response.
	 * @return int Result ID.
	 */
	public function analyse_and_store( $run_id, array $prompt, ProviderResponse $response ) {
		$brand       = ( new BrandsRepository() )->primary();
		$competitors = ( new CompetitorsRepository() )->active();

		$answer      = $response->answer;
		$store_raw   = (int) \AVM\Support\Settings::get( 'store_raw_answers', 1 );
		$brand_hit   = $brand ? $this->brand_mentioned( $answer, $brand ) : false;

		// Merge provider-supplied citations with URLs found in the answer text.
		$urls = array_unique( array_merge( $response->citations, $this->extract_urls( $answer ) ) );

		$brand_domain     = $brand ? $this->host( $brand['domain'] ) : '';
		$citation_hit     = false;
		$position         = $brand_hit ? $this->position( $answer, $brand ) : 0;
		$recommendation   = $this->classify_recommendation( $answer, $brand, $brand_hit );

		$results = new ResultsRepository();
		$result_id = $results->insert(
			array(
				'run_id'            => (int) $run_id,
				'prompt_id'         => (int) $prompt['id'],
				'answer'            => $store_raw ? $this->sanitise_answer( $answer ) : '',
				'brand_detected'    => $brand_hit ? 1 : 0,
				'citation_detected' => 0, // updated below once citations are known.
				'recommendation'    => $recommendation,
				'brand_position'    => $position,
				'confidence'        => $this->confidence( $answer, $urls ),
			)
		);

		// Persist citations, classifying ownership.
		$citations_repo = new CitationsRepository();
		foreach ( $urls as $url ) {
			$host      = $this->host( $url );
			if ( '' === $host ) {
				continue;
			}
			$ownership = 'third_party';
			$page_id   = 0;
			if ( '' !== $brand_domain && $this->same_host( $host, $brand_domain ) ) {
				$ownership    = 'customer';
				$citation_hit = true;
				$page_id      = $this->resolve_page_id( $url );
			} elseif ( $this->matches_competitor( $host, $competitors ) ) {
				$ownership = 'competitor';
			}
			$citations_repo->insert(
				array(
					'result_id' => $result_id,
					'url'       => esc_url_raw( $url ),
					'domain'    => $host,
					'page_id'   => $page_id,
					'page_type' => $page_id ? get_post_type( $page_id ) : '',
					'ownership' => $ownership,
				)
			);
		}

		if ( $citation_hit ) {
			$results->update( $result_id, array( 'citation_detected' => 1 ) );
		}

		// Persist mentions (brand + competitors).
		$mentions = new MentionsRepository();
		if ( $brand_hit && $brand ) {
			$mentions->insert(
				array(
					'result_id'      => $result_id,
					'organisation'   => $brand['primary_name'],
					'ownership'      => 'customer',
					'position'       => $position,
					'classification' => $recommendation,
				)
			);
		}
		foreach ( $competitors as $competitor ) {
			if ( $this->competitor_mentioned( $answer, $competitor ) ) {
				$mentions->insert(
					array(
						'result_id'      => $result_id,
						'organisation'   => $competitor['name'],
						'ownership'      => 'competitor',
						'position'       => $this->position_of( $answer, $competitor['name'] ),
						'classification' => 'neutral',
					)
				);
			}
		}

		return $result_id;
	}

	/**
	 * Whether the brand (primary or alt names or domain) appears in the answer.
	 *
	 * @param string $answer Answer text.
	 * @param array  $brand  Brand row.
	 * @return bool
	 */
	private function brand_mentioned( $answer, array $brand ) {
		$needles = array_merge(
			array( $brand['primary_name'], $this->host( $brand['domain'] ) ),
			BrandsRepository::alt_names( $brand )
		);
		return $this->any_contains( $answer, $needles );
	}

	/**
	 * Whether a competitor (name, alt names or domain) appears.
	 *
	 * @param string $answer     Answer text.
	 * @param array  $competitor Competitor row.
	 * @return bool
	 */
	private function competitor_mentioned( $answer, array $competitor ) {
		$alts    = (array) json_decode( $competitor['alt_names'], true );
		$needles = array_merge( array( $competitor['name'], $this->host( $competitor['domain'] ) ), $alts );
		return $this->any_contains( $answer, $needles );
	}

	/**
	 * Case-insensitive whole-ish word containment for any needle.
	 *
	 * @param string $haystack Text.
	 * @param array  $needles  Candidate strings.
	 * @return bool
	 */
	private function any_contains( $haystack, array $needles ) {
		$haystack = strtolower( $haystack );
		foreach ( $needles as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );
			if ( '' === $needle ) {
				continue;
			}
			// Word-boundary match for short names to avoid false positives.
			if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/u', $haystack ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classify recommendation strength (spec §10.3) — indicative only.
	 *
	 * @param string     $answer    Answer text.
	 * @param array|null $brand     Brand row.
	 * @param bool       $brand_hit Whether the brand was detected.
	 * @return string
	 */
	private function classify_recommendation( $answer, $brand, $brand_hit ) {
		if ( ! $brand || ! $brand_hit ) {
			return 'not_mentioned';
		}

		$lower = strtolower( $answer );
		$name  = strtolower( $brand['primary_name'] );
		$pos   = strpos( $lower, $name );
		$window = false !== $pos ? substr( $lower, max( 0, $pos - 80 ), 200 ) : $lower;

		$negative = array( 'avoid', 'not recommend', 'poor', 'disappointing', 'complaints' );
		foreach ( $negative as $term ) {
			if ( false !== strpos( $window, $term ) ) {
				return 'negative';
			}
		}

		$strong = array( 'best', 'top choice', 'highly recommend', 'leading', 'excellent', 'ideal' );
		foreach ( $strong as $term ) {
			if ( false !== strpos( $window, $term ) ) {
				return 'strong';
			}
		}

		// Listed among numbered/bulleted recommendations?
		if ( preg_match( '/(^|\n)\s*(\d+\.|[-*•])\s.*' . preg_quote( $name, '/' ) . '/im', $answer ) ) {
			return 'listed';
		}

		return 'neutral';
	}

	/**
	 * Ordinal position of the brand within the answer (1 = first named org).
	 *
	 * @param string $answer Answer text.
	 * @param array  $brand  Brand row.
	 * @return int
	 */
	private function position( $answer, array $brand ) {
		return $this->position_of( $answer, $brand['primary_name'] );
	}

	/**
	 * Position of an organisation name in reading order.
	 *
	 * @param string $answer Answer text.
	 * @param string $name   Organisation name.
	 * @return int
	 */
	private function position_of( $answer, $name ) {
		$pos = stripos( $answer, $name );
		if ( false === $pos ) {
			return 0;
		}
		// Approximate ordinal by counting capitalised proper-noun runs before it.
		$before = substr( $answer, 0, $pos );
		preg_match_all( '/\b[A-Z][A-Za-z0-9&.\-]+/', $before, $m );
		return count( $m[0] ) + 1;
	}

	/**
	 * Extract http(s) URLs from free text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function extract_urls( $text ) {
		preg_match_all( '#https?://[^\s\)\]\}"\'<>]+#i', $text, $m );
		return array_map(
			static function ( $u ) {
				return rtrim( $u, '.,;:' );
			},
			$m[0]
		);
	}

	/**
	 * Host portion of a URL or bare domain, lowercased, without www.
	 *
	 * @param string $value URL or domain.
	 * @return string
	 */
	private function host( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . $value;
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		$host = $host ? strtolower( $host ) : '';
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Whether two hosts are the same registrable domain.
	 *
	 * @param string $a Host A.
	 * @param string $b Host B.
	 * @return bool
	 */
	private function same_host( $a, $b ) {
		return $a === $b || ( '' !== $b && substr( $a, -strlen( '.' . $b ) ) === '.' . $b );
	}

	/**
	 * Whether a host belongs to a monitored competitor.
	 *
	 * @param string $host        Host.
	 * @param array  $competitors Competitor rows.
	 * @return bool
	 */
	private function matches_competitor( $host, array $competitors ) {
		foreach ( $competitors as $competitor ) {
			$c = $this->host( $competitor['domain'] );
			if ( '' !== $c && $this->same_host( $host, $c ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a customer URL to a local post ID where possible.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private function resolve_page_id( $url ) {
		$id = url_to_postid( $url );
		return $id ? (int) $id : 0;
	}

	/**
	 * Confidence score for this analysis (spec §11.3) based on answer richness.
	 *
	 * @param string $answer Answer.
	 * @param array  $urls   Extracted URLs.
	 * @return float 0..1
	 */
	private function confidence( $answer, array $urls ) {
		$score = 0.4;
		if ( strlen( $answer ) > 200 ) {
			$score += 0.2;
		}
		if ( ! empty( $urls ) ) {
			$score += 0.3;
		}
		if ( preg_match( '/(\d+\.|[-*•])\s/', $answer ) ) {
			$score += 0.1;
		}
		return min( 1.0, round( $score, 3 ) );
	}

	/**
	 * Sanitise an AI answer before storage/display (spec §17.3).
	 *
	 * @param string $answer Raw answer.
	 * @return string
	 */
	private function sanitise_answer( $answer ) {
		$answer = wp_kses( $answer, array() ); // strip all HTML.
		return mb_substr( $answer, 0, 20000 ); // cap stored size.
	}
}
