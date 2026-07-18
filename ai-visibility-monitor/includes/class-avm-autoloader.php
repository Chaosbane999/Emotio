<?php
/**
 * PSR-4-ish autoloader for the AVM namespace.
 *
 * Maps AVM\Foo\Bar to includes/Foo/class-avm-bar.php following the
 * WordPress file-naming convention (lowercased, hyphenated, `class-avm-` prefix).
 *
 * @package AVM
 */

namespace AVM;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve a fully-qualified class name to a file and require it.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'AVM\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'AVM\\' ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		// Interfaces use an interface- prefix; everything else class-.
		$is_interface = ( false !== stripos( $name, 'interface' ) )
			|| interface_exists( $class, false );

		$file_name = self::to_file_slug( $name );
		$prefix    = $is_interface ? 'interface-avm-' : 'class-avm-';

		$path = AVM_PLUGIN_DIR . 'includes/';
		if ( ! empty( $parts ) ) {
			$path .= implode( '/', $parts ) . '/';
		}
		$path .= $prefix . $file_name . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Convert a StudlyCase class name to a hyphenated, lowercase slug.
	 *
	 * @param string $name Class short name.
	 * @return string
	 */
	private static function to_file_slug( $name ) {
		// Strip a trailing "Interface" suffix if present.
		$name = preg_replace( '/Interface$/', '', $name );
		$name = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name );
		$name = str_replace( '_', '-', $name );
		return strtolower( $name );
	}
}
