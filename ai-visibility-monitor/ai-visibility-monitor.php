<?php
/**
 * Plugin Name:       AI Visibility Monitor
 * Plugin URI:        https://emotio.co.uk/ai-visibility-monitor
 * Description:        Monitors whether your brand, website and services appear in AI-generated search answers, tracks competitors, and recommends improvements.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Emotio
 * Author URI:        https://emotio.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-visibility-monitor
 * Domain Path:       /languages
 *
 * @package AVM
 */

defined( 'ABSPATH' ) || exit;

define( 'AVM_VERSION', '0.1.0' );
define( 'AVM_DB_VERSION', '1' );
define( 'AVM_PLUGIN_FILE', __FILE__ );
define( 'AVM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AVM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AVM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once AVM_PLUGIN_DIR . 'includes/class-avm-autoloader.php';
AVM\Autoloader::register();

/**
 * Activation: create tables, capabilities, default options, schedule queue.
 */
register_activation_hook(
	__FILE__,
	static function () {
		AVM\Activator::activate();
	}
);

/**
 * Deactivation: unschedule background jobs, keep data.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		AVM\Deactivator::deactivate();
	}
);

/**
 * Boot the plugin.
 */
add_action(
	'plugins_loaded',
	static function () {
		AVM\Plugin::instance()->boot();
	}
);
