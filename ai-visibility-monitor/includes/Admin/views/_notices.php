<?php
/**
 * Shared admin notice renderer, driven by the ?avm_status flag.
 *
 * @package AVM
 */

defined( 'ABSPATH' ) || exit;

$status = isset( $_GET['avm_status'] ) ? sanitize_key( wp_unslash( $_GET['avm_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
if ( '' === $status ) {
	return;
}

$messages = array(
	'saved'      => array( 'success', __( 'Saved.', 'ai-visibility-monitor' ) ),
	'onboarded'  => array( 'success', __( 'Setup complete. Review and approve your draft prompts below.', 'ai-visibility-monitor' ) ),
	'queued'     => array( 'success', __( 'Scan queued. Results will appear once the background queue runs.', 'ai-visibility-monitor' ) ),
	'analysed'   => array( 'success', __( 'Website readiness analysis complete.', 'ai-visibility-monitor' ) ),
	'error'      => array( 'error', __( 'Please check the form and try again.', 'ai-visibility-monitor' ) ),
	'limit'      => array( 'warning', __( 'You have reached the limit for your current configuration.', 'ai-visibility-monitor' ) ),
	'allowance'  => array( 'warning', __( 'The monthly query allowance has been reached.', 'ai-visibility-monitor' ) ),
);

if ( isset( $messages[ $status ] ) ) {
	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $messages[ $status ][0] ),
		esc_html( $messages[ $status ][1] )
	);
}
