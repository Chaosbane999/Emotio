<?php
/**
 * Uninstall routine (spec §17.4).
 *
 * Only removes data when the administrator has explicitly opted in by setting
 * the `avm_delete_data_on_uninstall` option. Otherwise all monitoring data is
 * retained so a reinstall keeps history.
 *
 * @package AVM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

// Respect the opt-in flag; default is to keep data.
if ( ! get_option( 'avm_delete_data_on_uninstall' ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-avm-autoloader.php';
AVM\Autoloader::register();

// Drop custom tables.
if ( class_exists( 'AVM\\Install' ) ) {
	AVM\Install::drop_tables();
}

// Remove capabilities.
if ( class_exists( 'AVM\\Support\\Capabilities' ) ) {
	AVM\Support\Capabilities::remove();
}

// Delete options (including encrypted keys).
$options = array(
	'avm_settings',
	'avm_api_key_enc',
	'avm_db_version',
	'avm_onboarded',
	'avm_last_score',
	'avm_technical_readiness',
	'avm_site_checks',
	'avm_site_sample',
	'avm_delete_data_on_uninstall',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'avm_process_queue' );
wp_clear_scheduled_hook( 'avm_enqueue_due' );
