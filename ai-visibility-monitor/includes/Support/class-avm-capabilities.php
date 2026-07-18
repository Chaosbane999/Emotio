<?php
/**
 * Custom capabilities (spec §5).
 *
 * `avm_manage` — full control incl. credentials (administrators).
 * `avm_view`   — reporting users: dashboards, reports, exports, permitted scans.
 *
 * @package AVM
 */

namespace AVM\Support;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	const MANAGE = 'avm_manage';
	const VIEW   = 'avm_view';

	/**
	 * Grant capabilities to the administrator role on activation.
	 */
	public static function add() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::VIEW );
		}
	}

	/**
	 * Remove capabilities from all roles (used on uninstall, not deactivation).
	 */
	public static function remove() {
		foreach ( wp_roles()->roles as $slug => $data ) {
			$role = get_role( $slug );
			if ( $role ) {
				$role->remove_cap( self::MANAGE );
				$role->remove_cap( self::VIEW );
			}
		}
	}

	/**
	 * True if the current user may manage the plugin (credentials, settings).
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE ) || current_user_can( 'manage_options' );
	}

	/**
	 * True if the current user may view reports.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( self::VIEW ) || self::can_manage();
	}
}
