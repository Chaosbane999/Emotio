<?php
/**
 * Runs on plugin activation (spec §6.1).
 *
 * @package AVM
 */

namespace AVM;

use AVM\Support\Capabilities;
use AVM\Queue\Queue;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * Create tables, capabilities, defaults and schedule background processing.
	 */
	public static function activate() {
		Install::create_tables();
		Capabilities::add();
		self::seed_default_options();
		Queue::schedule();

		// Flag onboarding as required on first activation only.
		if ( false === get_option( 'avm_onboarded', false ) ) {
			update_option( 'avm_onboarded', 0 );
		}

		set_transient( 'avm_activation_redirect', 1, 30 );
	}

	/**
	 * Establish sensible default settings.
	 */
	private static function seed_default_options() {
		$defaults = array(
			'provider'          => 'openai',
			'model'             => 'gpt-4o-mini',
			'monthly_allowance' => 200,
			'default_frequency' => 'monthly',
			'max_active_prompts'=> 25,
			'max_competitors'   => 5,
			'batch_size'        => 3,
			'retry_limit'       => 3,
			'store_raw_answers' => 1,
		);

		$existing = get_option( 'avm_settings', array() );
		update_option( 'avm_settings', wp_parse_args( $existing, $defaults ) );
	}
}
