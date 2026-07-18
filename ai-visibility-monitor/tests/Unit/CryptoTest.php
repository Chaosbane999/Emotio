<?php
/**
 * Unit tests for the credential encryption layer (spec §17.1).
 *
 * @package AVM
 */

namespace AVM\Tests\Unit;

use AVM\Support\Crypto;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase {

	public function test_round_trip_returns_original_secret() {
		$secret    = 'sk-proj-ABCDEF1234567890abcdef';
		$encrypted = Crypto::encrypt( $secret );

		$this->assertNotSame( '', $encrypted );
		$this->assertNotSame( $secret, $encrypted, 'Ciphertext must differ from plaintext.' );
		$this->assertSame( $secret, Crypto::decrypt( $encrypted ) );
	}

	public function test_ciphertext_is_non_deterministic() {
		$secret = 'sk-live-repeatable-value';
		$this->assertNotSame(
			Crypto::encrypt( $secret ),
			Crypto::encrypt( $secret ),
			'Random IV must make each ciphertext unique.'
		);
	}

	public function test_empty_input_is_handled() {
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
	}

	public function test_malformed_ciphertext_does_not_error() {
		$this->assertSame( '', Crypto::decrypt( 'not-valid-base64-@@@' ) );
		$this->assertSame( '', Crypto::decrypt( base64_encode( 'too-short' ) ) );
	}

	public function test_mask_hides_the_middle_of_a_secret() {
		$masked = Crypto::mask( 'sk-proj-ABCDEF1234567890abcdef' );
		$this->assertStringStartsWith( 'sk-', $masked );
		$this->assertStringEndsWith( 'cdef', $masked );
		$this->assertStringNotContainsString( 'ABCDEF', $masked );
	}

	public function test_fingerprint_is_stable_and_non_secret() {
		$fp = Crypto::key_fingerprint();
		$this->assertSame( 12, strlen( $fp ) );
		$this->assertSame( $fp, Crypto::key_fingerprint(), 'Fingerprint must be stable for a fixed key.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $fp );
	}
}
