<?php
/**
 * Central settings accessor (spec §4.1, §17.1).
 *
 * Non-secret settings live in the `avm_settings` option. The API key is stored
 * separately, encrypted, in `avm_api_key_enc` — or read from a wp-config
 * constant which always takes precedence and is never persisted to the DB.
 *
 * @package AVM
 */

namespace AVM\Support;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION      = 'avm_settings';
	const KEY_OPTION  = 'avm_api_key_enc';
	const FP_OPTION   = 'avm_api_key_fp';

	/**
	 * Map of provider slug => wp-config constant name.
	 *
	 * @var array
	 */
	private static $constants = array(
		'openai'     => 'AVM_OPENAI_API_KEY',
		'gemini'     => 'AVM_GEMINI_API_KEY',
		'perplexity' => 'AVM_PERPLEXITY_API_KEY',
	);

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = get_option( self::OPTION, array() );
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function all() {
		return get_option( self::OPTION, array() );
	}

	/**
	 * Persist a partial settings update after sanitising.
	 *
	 * @param array $changes Key/value changes.
	 */
	public static function update( array $changes ) {
		$all = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( $all, $changes ) );
	}

	/**
	 * Store the API key for a provider (encrypted). Empty string clears it.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      Plain API key.
	 */
	public static function set_api_key( $provider, $key ) {
		$store = get_option( self::KEY_OPTION, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}
		$fingerprints = get_option( self::FP_OPTION, array() );
		if ( ! is_array( $fingerprints ) ) {
			$fingerprints = array();
		}

		if ( '' === trim( (string) $key ) ) {
			unset( $store[ $provider ], $fingerprints[ $provider ] );
		} else {
			$store[ $provider ]        = Crypto::encrypt( trim( $key ) );
			$fingerprints[ $provider ] = Crypto::key_fingerprint();
		}
		update_option( self::KEY_OPTION, $store, false );
		update_option( self::FP_OPTION, $fingerprints, false );
	}

	/**
	 * Health of a provider's stored key.
	 *
	 * Distinguishes a genuinely absent key from one that exists but can no
	 * longer be decrypted because the WordPress salts changed (spec §17.1).
	 *
	 * @param string $provider Provider slug.
	 * @return string 'constant' | 'ok' | 'missing' | 'unreadable'.
	 */
	public static function key_status( $provider ) {
		if ( self::key_is_constant( $provider ) ) {
			return 'constant';
		}

		$store = get_option( self::KEY_OPTION, array() );
		if ( ! is_array( $store ) || empty( $store[ $provider ] ) ) {
			return 'missing';
		}

		// A stored envelope that decrypts to empty means the key is unreadable.
		if ( '' === Crypto::decrypt( $store[ $provider ] ) ) {
			return 'unreadable';
		}

		// Fingerprint mismatch also signals the salts changed under us.
		$fingerprints = get_option( self::FP_OPTION, array() );
		if ( is_array( $fingerprints ) && ! empty( $fingerprints[ $provider ] )
			&& $fingerprints[ $provider ] !== Crypto::key_fingerprint() ) {
			return 'unreadable';
		}

		return 'ok';
	}

	/**
	 * Retrieve the decrypted API key for a provider.
	 *
	 * wp-config constant wins over the encrypted DB value.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_api_key( $provider ) {
		if ( isset( self::$constants[ $provider ] ) ) {
			$const = self::$constants[ $provider ];
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				return (string) constant( $const );
			}
		}

		$store = get_option( self::KEY_OPTION, array() );
		if ( is_array( $store ) && ! empty( $store[ $provider ] ) ) {
			return Crypto::decrypt( $store[ $provider ] );
		}

		return '';
	}

	/**
	 * Whether an API key is configured for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function has_api_key( $provider ) {
		return '' !== self::get_api_key( $provider );
	}

	/**
	 * Whether the key for a provider comes from wp-config (read-only in UI).
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function key_is_constant( $provider ) {
		return isset( self::$constants[ $provider ] )
			&& defined( self::$constants[ $provider ] )
			&& '' !== (string) constant( self::$constants[ $provider ] );
	}
}
