<?php
/**
 * Runs on plugin deactivation.
 *
 * Unschedules background jobs but preserves all customer data (spec §17.4).
 *
 * @package AVM
 */

namespace AVM;

use AVM\Queue\Queue;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Clear scheduled events. Data is intentionally retained.
	 */
	public static function deactivate() {
		Queue::unschedule();
	}
}
