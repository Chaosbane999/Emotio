<?php
/**
 * Main plugin orchestrator (singleton).
 *
 * @package AVM
 */

namespace AVM;

use AVM\Providers\ProviderRegistry;
use AVM\Queue\Queue;
use AVM\Admin\Admin;
use AVM\Admin\DashboardWidget;
use AVM\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Wire everything up.
	 */
	public function boot() {
		load_plugin_textdomain( 'ai-visibility-monitor', false, dirname( AVM_PLUGIN_BASENAME ) . '/languages' );

		ProviderRegistry::boot();
		Queue::register();

		// Keep the schema current after updates.
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );

		if ( is_admin() ) {
			( new Admin() )->register();
			( new DashboardWidget() )->register();
			( new Ajax() )->register();
		}
	}

	/**
	 * Run dbDelta when the stored DB version is behind.
	 */
	public function maybe_upgrade_db() {
		if ( (string) get_option( 'avm_db_version' ) !== (string) AVM_DB_VERSION ) {
			Install::create_tables();
		}
	}
}
