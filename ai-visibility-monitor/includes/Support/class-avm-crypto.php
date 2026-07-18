<?php
/**
 * Reversible encryption for API credentials at rest (spec §17.1).
 *
 * Keys are encrypted with AES-256-GCM using a key derived from WordPress
 * salts (AUTH_KEY/AUTH_SALT). Credentials may alternatively be defined in
 * wp-config.php via AVM_OPENAI_API_KEY and are then never written to the DB.
 *
 * @package AVM
 */

namespace AVM\Support;

defined( 'ABSPATH' ) || exit;

class Crypto {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Derive a 32-byte key from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );

		if ( '' === $material ) {
			// Extremely unlikely, but never encrypt with an empty key.
			$material = wp_salt( 'auth' );
		}

		return hash( 'sha256', 'avm|' . $material, true );
	}

	/**
	 * A short, non-secret fingerprint of the current encryption key.
	 *
	 * Stored alongside an encrypted secret so we can detect when the WordPress
	 * salts have changed (which makes previously stored secrets undecryptable)
	 * without ever exposing the key material itself.
	 *
	 * @return string 12-hex-char fingerprint.
	 */
	public static function key_fingerprint() {
		return substr( hash( 'sha256', 'avm-fp|' . self::key() ), 0, 12 );
	}

	/**
	 * Encrypt a plaintext secret. Returns a base64 envelope or '' on failure.
	 *
	 * @param string $plaintext Secret value.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === (string) $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = random_bytes( $iv_len );
		$tag    = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Decrypt an envelope produced by encrypt(). Returns '' on failure.
	 *
	 * @param string $envelope Base64 envelope.
	 * @return string
	 */
	public static function decrypt( $envelope ) {
		if ( '' === (string) $envelope || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $envelope, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$tag_len = 16;
		if ( strlen( $raw ) < $iv_len + $tag_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$tag        = substr( $raw, $iv_len, $tag_len );
		$ciphertext = substr( $raw, $iv_len + $tag_len );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Mask a secret for display, e.g. "sk-…â€¦â€¦â€¦Xy8Q".
	 *
	 * @param string $secret Plain secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		$len    = strlen( $secret );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $secret, 0, 3 ) . str_repeat( '•', 6 ) . substr( $secret, -4 );
	}
}
