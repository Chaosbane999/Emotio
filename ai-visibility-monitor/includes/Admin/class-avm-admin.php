<?php
/**
 * Admin controller: menus, asset loading and secure form handlers.
 *
 * Every state-changing action verifies a nonce and an AVM capability, and
 * sanitises input before writing / escapes on output (spec §17.2).
 *
 * @package AVM
 */

namespace AVM\Admin;

use AVM\Support\Capabilities;
use AVM\Support\Settings;
use AVM\Support\Logger;
use AVM\Providers\ProviderRegistry;
use AVM\Queue\Queue;
use AVM\Data\PromptsRepository;
use AVM\Data\CompetitorsRepository;
use AVM\Data\BrandsRepository;
use AVM\Data\RecommendationsRepository;
use AVM\Services\ScoringService;
use AVM\Services\SiteAnalyzer;
use AVM\Services\PromptGenerator;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'avm-dashboard';

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_onboarding' ) );

		// admin-post handlers (state-changing).
		add_action( 'admin_post_avm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_avm_save_prompt', array( $this, 'handle_save_prompt' ) );
		add_action( 'admin_post_avm_prompt_action', array( $this, 'handle_prompt_action' ) );
		add_action( 'admin_post_avm_save_competitor', array( $this, 'handle_save_competitor' ) );
		add_action( 'admin_post_avm_save_recommendation', array( $this, 'handle_save_recommendation' ) );
		add_action( 'admin_post_avm_save_onboarding', array( $this, 'handle_save_onboarding' ) );
		add_action( 'admin_post_avm_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_avm_run_site_analysis', array( $this, 'handle_run_site_analysis' ) );
	}

	/**
	 * Register the admin menu tree.
	 */
	public function menu() {
		add_menu_page(
			__( 'AI Visibility', 'ai-visibility-monitor' ),
			__( 'AI Visibility', 'ai-visibility-monitor' ),
			Capabilities::VIEW,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-visibility',
			58
		);

		$pages = array(
			'avm-dashboard'      => array( __( 'Dashboard', 'ai-visibility-monitor' ), Capabilities::VIEW, 'render_dashboard' ),
			'avm-prompts'        => array( __( 'Prompts', 'ai-visibility-monitor' ), Capabilities::VIEW, 'render_prompts' ),
			'avm-competitors'    => array( __( 'Competitors', 'ai-visibility-monitor' ), Capabilities::MANAGE, 'render_competitors' ),
			'avm-recommendations'=> array( __( 'Recommendations', 'ai-visibility-monitor' ), Capabilities::VIEW, 'render_recommendations' ),
			'avm-settings'       => array( __( 'Settings', 'ai-visibility-monitor' ), Capabilities::MANAGE, 'render_settings' ),
			'avm-diagnostics'    => array( __( 'Diagnostics', 'ai-visibility-monitor' ), Capabilities::MANAGE, 'render_diagnostics' ),
		);

		foreach ( $pages as $slug => $def ) {
			add_submenu_page(
				self::SLUG,
				$def[0],
				$def[0],
				$def[1],
				$slug,
				array( $this, $def[2] )
			);
		}

		// Onboarding / setup wizard.
		add_submenu_page(
			self::SLUG,
			__( 'Setup Wizard', 'ai-visibility-monitor' ),
			__( 'Setup Wizard', 'ai-visibility-monitor' ),
			Capabilities::MANAGE,
			'avm-onboarding',
			array( $this, 'render_onboarding' )
		);
	}

	/**
	 * Enqueue admin CSS/JS on plugin pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'avm-' ) && 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'avm-admin', AVM_PLUGIN_URL . 'assets/css/admin.css', array(), AVM_VERSION );
		wp_enqueue_script( 'avm-admin', AVM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), AVM_VERSION, true );
		wp_localize_script(
			'avm-admin',
			'AVM',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'avm_ajax' ),
			)
		);
	}

	/**
	 * Redirect to onboarding once after activation.
	 */
	public function maybe_redirect_onboarding() {
		if ( ! get_transient( 'avm_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'avm_activation_redirect' );
		if ( ! get_option( 'avm_onboarded' ) && Capabilities::can_manage() && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=avm-onboarding' ) );
			exit;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Page renderers                                                         */
	/* --------------------------------------------------------------------- */

	public function render_dashboard() {
		$this->guard( Capabilities::VIEW );
		$metrics = ( new ScoringService() )->metrics();
		$this->view( 'dashboard', compact( 'metrics' ) );
	}

	public function render_prompts() {
		$this->guard( Capabilities::VIEW );
		$prompts = ( new PromptsRepository() )->all( array(), 'created_at', 'DESC', 200 );
		$active  = ( new PromptsRepository() )->active_count();
		$max     = (int) Settings::get( 'max_active_prompts', 25 );
		$this->view( 'prompts', compact( 'prompts', 'active', 'max' ) );
	}

	public function render_competitors() {
		$this->guard( Capabilities::MANAGE );
		$competitors = ( new CompetitorsRepository() )->all( array(), 'priority', 'ASC', 100 );
		$max         = (int) Settings::get( 'max_competitors', 5 );
		$this->view( 'competitors', compact( 'competitors', 'max' ) );
	}

	public function render_recommendations() {
		$this->guard( Capabilities::VIEW );
		$recommendations = ( new RecommendationsRepository() )->all( array(), 'created_at', 'DESC', 200 );
		$this->view( 'recommendations', compact( 'recommendations' ) );
	}

	public function render_settings() {
		$this->guard( Capabilities::MANAGE );
		$settings  = Settings::all();
		$providers = ProviderRegistry::all();
		$this->view( 'settings', compact( 'settings', 'providers' ) );
	}

	public function render_diagnostics() {
		$this->guard( Capabilities::MANAGE );
		$queue = Queue::status();
		$this->view( 'diagnostics', compact( 'queue' ) );
	}

	public function render_onboarding() {
		$this->guard( Capabilities::MANAGE );
		$brand = ( new BrandsRepository() )->primary();
		$this->view( 'onboarding', compact( 'brand' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* --------------------------------------------------------------------- */

	public function handle_save_settings() {
		$this->verify( 'avm_save_settings', Capabilities::MANAGE );

		$provider  = sanitize_key( wp_unslash( $_POST['provider'] ?? 'openai' ) );
		$changes   = array(
			'provider'          => $provider,
			'model'             => sanitize_text_field( wp_unslash( $_POST['model'] ?? 'gpt-4o-mini' ) ),
			'monthly_allowance' => max( 0, absint( $_POST['monthly_allowance'] ?? 200 ) ),
			'batch_size'        => max( 1, absint( $_POST['batch_size'] ?? 3 ) ),
			'retry_limit'       => max( 0, absint( $_POST['retry_limit'] ?? 3 ) ),
			'store_raw_answers' => isset( $_POST['store_raw_answers'] ) ? 1 : 0,
		);
		Settings::update( $changes );

		// API key: only overwrite when a new value is supplied and not constant-defined.
		$key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' !== $key && ! Settings::key_is_constant( $provider ) ) {
			Settings::set_api_key( $provider, $key );
			Logger::log( 'settings', 'API key updated for ' . $provider );
		}

		$this->redirect_back( 'avm-settings', 'saved' );
	}

	public function handle_save_prompt() {
		$this->verify( 'avm_save_prompt', Capabilities::MANAGE );

		$repo = new PromptsRepository();
		$id   = absint( $_POST['prompt_id'] ?? 0 );
		$data = array(
			'prompt_text'    => sanitize_textarea_field( wp_unslash( $_POST['prompt_text'] ?? '' ) ),
			'category'       => sanitize_key( wp_unslash( $_POST['category'] ?? 'informational' ) ),
			'priority'       => sanitize_key( wp_unslash( $_POST['priority'] ?? 'supporting' ) ),
			'frequency'      => sanitize_key( wp_unslash( $_POST['frequency'] ?? 'monthly' ) ),
			'target_page_id' => absint( $_POST['target_page_id'] ?? 0 ),
			'geo_context'    => sanitize_text_field( wp_unslash( $_POST['geo_context'] ?? '' ) ),
		);

		if ( '' === $data['prompt_text'] ) {
			$this->redirect_back( 'avm-prompts', 'error' );
		}

		if ( $id ) {
			$repo->update( $id, $data );
		} else {
			$data['status'] = 'draft';
			$repo->insert( $data );
		}
		$this->redirect_back( 'avm-prompts', 'saved' );
	}

	public function handle_prompt_action() {
		$this->verify( 'avm_prompt_action', Capabilities::MANAGE );

		$repo   = new PromptsRepository();
		$id     = absint( $_POST['prompt_id'] ?? 0 );
		$action = sanitize_key( wp_unslash( $_POST['prompt_do'] ?? '' ) );
		$prompt = $id ? $repo->find( $id ) : null;
		if ( ! $prompt ) {
			$this->redirect_back( 'avm-prompts', 'error' );
		}

		switch ( $action ) {
			case 'approve':
				// Enforce the active-prompt cap (spec §7.4).
				if ( 'active' !== $prompt['status'] && $repo->active_count() >= (int) Settings::get( 'max_active_prompts', 25 ) ) {
					$this->redirect_back( 'avm-prompts', 'limit' );
				}
				$repo->update( $id, array( 'status' => 'active' ) );
				break;
			case 'pause':
				$repo->update( $id, array( 'status' => 'paused' ) );
				break;
			case 'delete':
				$repo->delete( $id );
				break;
			case 'duplicate':
				$copy = $prompt;
				unset( $copy['id'] );
				$copy['status'] = 'draft';
				$repo->insert( $copy );
				break;
			case 'run':
				if ( ! Queue::allowance_remaining() ) {
					$this->redirect_back( 'avm-prompts', 'allowance' );
				}
				Queue::enqueue_prompt( $id, 'manual' );
				$this->redirect_back( 'avm-prompts', 'queued' );
		}
		$this->redirect_back( 'avm-prompts', 'saved' );
	}

	public function handle_save_competitor() {
		$this->verify( 'avm_save_competitor', Capabilities::MANAGE );

		$repo = new CompetitorsRepository();
		$id   = absint( $_POST['competitor_id'] ?? 0 );

		if ( isset( $_POST['delete'] ) && $id ) {
			$repo->delete( $id );
			$this->redirect_back( 'avm-competitors', 'saved' );
		}

		// Enforce competitor cap on new rows.
		if ( ! $id && $repo->count( array( 'status' => 'active' ) ) >= (int) Settings::get( 'max_competitors', 5 ) ) {
			$this->redirect_back( 'avm-competitors', 'limit' );
		}

		$alts = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['alt_names'] ?? '' ) ) ) );
		$data = array(
			'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'alt_names' => wp_json_encode( array_map( 'sanitize_text_field', $alts ) ),
			'domain'    => $this->clean_domain( wp_unslash( $_POST['domain'] ?? '' ) ),
			'category'  => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ),
			'priority'  => min( 5, max( 1, absint( $_POST['priority'] ?? 3 ) ) ),
			'status'    => 'active',
		);

		if ( '' === $data['name'] ) {
			$this->redirect_back( 'avm-competitors', 'error' );
		}

		if ( $id ) {
			$repo->update( $id, $data );
		} else {
			$repo->insert( $data );
		}
		$this->redirect_back( 'avm-competitors', 'saved' );
	}

	public function handle_save_recommendation() {
		$this->verify( 'avm_save_recommendation', Capabilities::VIEW );

		$repo   = new RecommendationsRepository();
		$id     = absint( $_POST['recommendation_id'] ?? 0 );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? 'open' ) );
		$allowed = array( 'open', 'planned', 'in_progress', 'completed', 'dismissed' );
		if ( $id && in_array( $status, $allowed, true ) ) {
			$repo->update(
				$id,
				array(
					'status' => $status,
					'notes'  => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
				)
			);
		}
		$this->redirect_back( 'avm-recommendations', 'saved' );
	}

	public function handle_save_onboarding() {
		$this->verify( 'avm_save_onboarding', Capabilities::MANAGE );

		$brands = new BrandsRepository();
		$alts   = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['alt_names'] ?? '' ) ) ) );
		$data   = array(
			'primary_name' => sanitize_text_field( wp_unslash( $_POST['primary_name'] ?? '' ) ),
			'alt_names'    => wp_json_encode( array_map( 'sanitize_text_field', $alts ) ),
			'domain'       => $this->clean_domain( wp_unslash( $_POST['domain'] ?? '' ) ),
			'details'      => wp_json_encode(
				array(
					'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
					'services'    => sanitize_textarea_field( wp_unslash( $_POST['services'] ?? '' ) ),
					'sectors'     => sanitize_textarea_field( wp_unslash( $_POST['sectors'] ?? '' ) ),
					'geo'         => sanitize_text_field( wp_unslash( $_POST['geo'] ?? '' ) ),
				)
			),
		);

		$existing = $brands->primary();
		if ( $existing ) {
			$brands->update( (int) $existing['id'], $data );
		} else {
			$brands->insert( $data );
		}

		// Generate draft prompts from the profile.
		if ( isset( $_POST['generate_prompts'] ) ) {
			$gen = new PromptGenerator();
			$gen->store_drafts(
				$gen->suggest(
					array(
						'brand'    => $data['primary_name'],
						'services' => explode( "\n", (string) wp_unslash( $_POST['services'] ?? '' ) ),
						'sectors'  => explode( "\n", (string) wp_unslash( $_POST['sectors'] ?? '' ) ),
						'geo'      => sanitize_text_field( wp_unslash( $_POST['geo'] ?? '' ) ),
					)
				)
			);
		}

		update_option( 'avm_onboarded', 1 );
		$this->redirect_back( 'avm-prompts', 'onboarded' );
	}

	public function handle_run_site_analysis() {
		$this->verify( 'avm_run_site_analysis', Capabilities::MANAGE );
		( new SiteAnalyzer() )->analyse();
		$this->redirect_back( 'avm-diagnostics', 'analysed' );
	}

	/**
	 * Stream monitoring results as CSV (spec §20).
	 */
	public function handle_export_csv() {
		$this->verify( 'avm_export_csv', Capabilities::VIEW );

		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results(
			"SELECT r.created_at, pr.prompt_text, r.brand_detected, r.citation_detected, r.recommendation, r.brand_position, r.confidence
			 FROM {$p}avm_results r
			 LEFT JOIN {$p}avm_prompts pr ON pr.id = r.prompt_id
			 ORDER BY r.created_at DESC LIMIT 5000",
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ai-visibility-results.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'Prompt', 'Brand detected', 'Citation detected', 'Recommendation', 'Position', 'Confidence' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['created_at'],
					$row['prompt_text'],
					$row['brand_detected'] ? 'yes' : 'no',
					$row['citation_detected'] ? 'yes' : 'no',
					$row['recommendation'],
					$row['brand_position'],
					$row['confidence'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Capability gate for page rendering.
	 *
	 * @param string $cap Capability.
	 */
	private function guard( $cap ) {
		if ( ! current_user_can( $cap ) && ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-visibility-monitor' ) );
		}
	}

	/**
	 * Verify nonce + capability for a handler.
	 *
	 * @param string $action Nonce action.
	 * @param string $cap    Capability.
	 */
	private function verify( $action, $cap ) {
		if ( ! current_user_can( $cap ) && ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-visibility-monitor' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Render a view partial with extracted data.
	 *
	 * @param string $name View slug.
	 * @param array  $data Variables.
	 */
	private function view( $name, array $data = array() ) {
		$file = AVM_PLUGIN_DIR . 'includes/Admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'View not found.', 'ai-visibility-monitor' ) . '</p></div>';
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract
		extract( $data, EXTR_SKIP );
		include $file;
	}

	/**
	 * Redirect back to a plugin page with a status flag.
	 *
	 * @param string $page   Page slug.
	 * @param string $status Status message key.
	 */
	private function redirect_back( $page, $status ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&avm_status=' . $status ) );
		exit;
	}

	/**
	 * Normalise a user-entered domain to a bare host.
	 *
	 * @param string $domain Raw input.
	 * @return string
	 */
	private function clean_domain( $domain ) {
		$domain = trim( (string) $domain );
		if ( '' === $domain ) {
			return '';
		}
		if ( false === strpos( $domain, '://' ) ) {
			$domain = 'https://' . $domain;
		}
		$host = wp_parse_url( esc_url_raw( $domain ), PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
	}
}
