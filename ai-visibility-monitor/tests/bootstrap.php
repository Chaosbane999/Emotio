<?php
/**
 * PHPUnit bootstrap.
 *
 * These are fast unit tests for pure logic (no live WordPress). We define the
 * few constants the classes reference and load the plugin autoloader. WordPress
 * function calls inside tested methods should be stubbed per-test with
 * Brain\Monkey where needed.
 *
 * @package AVM
 */

define( 'ABSPATH', __DIR__ . '/' );

// WordPress security salts used by AVM\Support\Crypto.
define( 'AUTH_KEY', 'unit-test-auth-key-000000000000000000' );
define( 'AUTH_SALT', 'unit-test-auth-salt-111111111111111111' );
define( 'SECURE_AUTH_KEY', 'unit-test-secure-key-2222222222222222' );

define( 'AVM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require dirname( __DIR__ ) . '/includes/class-avm-autoloader.php';
AVM\Autoloader::register();

$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $composer_autoload ) ) {
	require $composer_autoload;
}
