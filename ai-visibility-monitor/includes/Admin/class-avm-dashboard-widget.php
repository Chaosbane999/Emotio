<?php
/**
 * WordPress dashboard widget (spec §12).
 *
 * Reads only previously stored data — never makes a live API call on render.
 *
 * @package AVM
 */

namespace AVM\Admin;

use AVM\Support\Capabilities;
use AVM\Support\Settings;
use AVM\Services\ScoringService;
use AVM\Data\RunsRepository;
use AVM\Data\RecommendationsRepository;

defined( 'ABSPATH' ) || exit;

class DashboardWidget {

	/**
	 * Register the widget.
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Add the widget if the user may view reports.
	 */
	public function add() {
		if ( ! Capabilities::can_view() ) {
			return;
		}
		wp_add_dashboard_widget(
			'avm_widget',
			__( 'AI Visibility Monitor', 'ai-visibility-monitor' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render cached metrics.
	 */
	public function render() {
		$metrics   = ( new ScoringService() )->metrics();
		$allowance = (int) Settings::get( 'monthly_allowance', 200 );
		$used      = ( new RunsRepository() )->count_since( gmdate( 'Y-m-01 00:00:00' ) );
		$remaining = $allowance > 0 ? max( 0, $allowance - $used ) : __( 'Unlimited', 'ai-visibility-monitor' );
		$actions   = ( new RecommendationsRepository() )->count( array( 'status' => 'open' ) );
		$change    = (int) $metrics['change'];
		$arrow     = $change > 0 ? '↑' : ( $change < 0 ? '↓' : '→' );

		echo '<div class="avm-widget">';
		printf(
			'<p class="avm-score"><strong>%s</strong> %d/100 <span class="avm-delta">%s %d</span></p>',
			esc_html__( 'AI Visibility:', 'ai-visibility-monitor' ),
			(int) $metrics['score'],
			esc_html( $arrow ),
			absint( $change )
		);
		echo '<ul class="avm-widget-list">';
		$this->row( __( 'Brand mentions', 'ai-visibility-monitor' ), $metrics['mention_rate'] . '%' );
		$this->row( __( 'Website citations', 'ai-visibility-monitor' ), $metrics['citation_rate'] . '%' );
		$this->row( __( 'Share of voice', 'ai-visibility-monitor' ), $metrics['share_of_voice'] . '%' );
		$this->row( __( 'Tests completed', 'ai-visibility-monitor' ), (int) $metrics['total_tests'] );
		$this->row( __( 'Priority actions', 'ai-visibility-monitor' ), (int) $actions );
		$this->row( __( 'Queries remaining', 'ai-visibility-monitor' ), is_int( $remaining ) ? $remaining : $remaining );
		echo '</ul>';
		printf(
			'<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=avm-dashboard' ) ),
			esc_html__( 'View full report', 'ai-visibility-monitor' ),
			esc_url( admin_url( 'admin.php?page=avm-prompts' ) ),
			esc_html__( 'Run scan', 'ai-visibility-monitor' )
		);
		echo '</div>';
	}

	/**
	 * Render one label/value row.
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 */
	private function row( $label, $value ) {
		printf(
			'<li><span>%s</span><strong>%s</strong></li>',
			esc_html( $label ),
			esc_html( (string) $value )
		);
	}
}
