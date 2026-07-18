<?php
/**
 * Provider registry — the extension point for adding new AI providers (spec §8.1).
 *
 * @package AVM
 */

namespace AVM\Providers;

defined( 'ABSPATH' ) || exit;

class ProviderRegistry {

	/**
	 * slug => ProviderInterface.
	 *
	 * @var ProviderInterface[]
	 */
	private static $providers = array();

	/**
	 * Register built-in providers and fire an extensibility hook.
	 */
	public static function boot() {
		self::register( new OpenAiProvider() );
		self::register( new PerplexityProvider() );

		/**
		 * Allow add-ons to register further providers.
		 *
		 * @param ProviderRegistry $registry_class Static registry (self).
		 */
		do_action( 'avm_register_providers' );
	}

	/**
	 * Register a provider instance.
	 *
	 * @param ProviderInterface $provider Provider.
	 */
	public static function register( ProviderInterface $provider ) {
		self::$providers[ $provider->slug() ] = $provider;
	}

	/**
	 * Get a provider by slug.
	 *
	 * @param string $slug Slug.
	 * @return ProviderInterface|null
	 */
	public static function get( $slug ) {
		return isset( self::$providers[ $slug ] ) ? self::$providers[ $slug ] : null;
	}

	/**
	 * All registered providers.
	 *
	 * @return ProviderInterface[]
	 */
	public static function all() {
		return self::$providers;
	}
}
