<?php
/**
 * Structured, credential-safe logging into the avm_logs table (spec §17.1, §21).
 *
 * @package AVM
 */

namespace AVM\Support;

defined( 'ABSPATH' ) || exit;

class Logger {

	const INFO    = 'info';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Patterns that must never reach the log store.
	 *
	 * @var string[]
	 */
	private static $redactions = array(
		'/sk-[A-Za-z0-9_\-]{8,}/',   // OpenAI-style keys.
		'/Bearer\s+[A-Za-z0-9._\-]+/i',
		'/AIza[A-Za-z0-9_\-]{20,}/', // Google API keys.
	);

	/**
	 * Write a log row.
	 *
	 * @param string $event_type Machine event type.
	 * @param string $message    Human-readable message.
	 * @param string $severity   One of the severity constants.
	 * @param array  $context    Additional diagnostic context (redacted).
	 */
	public static function log( $event_type, $message, $severity = self::INFO, array $context = array() ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'avm_logs',
			array(
				'event_type' => substr( sanitize_key( $event_type ), 0, 50 ),
				'severity'   => in_array( $severity, array( self::INFO, self::WARNING, self::ERROR ), true ) ? $severity : self::INFO,
				'message'    => self::redact( (string) $message ),
				'context'    => self::redact( wp_json_encode( $context ) ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Convenience: error-level log.
	 *
	 * @param string $event_type Event type.
	 * @param string $message    Message.
	 * @param array  $context    Context.
	 */
	public static function error( $event_type, $message, array $context = array() ) {
		self::log( $event_type, $message, self::ERROR, $context );
	}

	/**
	 * Remove secrets from any string before it is persisted or displayed.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		foreach ( self::$redactions as $pattern ) {
			$text = preg_replace( $pattern, '[redacted]', $text );
		}
		return $text;
	}
}
