<?php
/**
 * Authenticated admin-ajax endpoints (spec §6.2 test connection).
 *
 * @package AVM
 */

namespace AVM\Admin;

use AVM\Support\Capabilities;
use AVM\Support\Settings;
use AVM\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

class Ajax {

	/**
	 * Register endpoints.
	 */
	public function register() {
		add_action( 'wp_ajax_avm_test_connection', array( $this, 'test_connection' ) );
	}

	/**
	 * Test a provider connection with the supplied or stored key.
	 */
	public function test_connection() {
		check_ajax_referer( 'avm_ajax', 'nonce' );
		if ( ! Capabilities::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-visibility-monitor' ) ), 403 );
		}

		$provider_slug = sanitize_key( wp_unslash( $_POST['provider'] ?? 'openai' ) );
		$provider      = ProviderRegistry::get( $provider_slug );
		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'ai-visibility-monitor' ) ) );
		}

		// Prefer a freshly typed key; fall back to the stored/constant key.
		$key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' === $key ) {
			$key = Settings::get_api_key( $provider_slug );
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'No API key provided.', 'ai-visibility-monitor' ) ) );
		}

		$result = $provider->test_connection( $key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful.', 'ai-visibility-monitor' ) ) );
	}
}
