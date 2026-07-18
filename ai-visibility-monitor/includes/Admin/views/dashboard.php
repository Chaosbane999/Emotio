<?php
/**
 * Dashboard overview view (spec §13.1).
 *
 * @package AVM
 * @var array $metrics Scoring metrics bundle.
 */

defined( 'ABSPATH' ) || exit;

$change = (int) $metrics['change'];
$arrow  = $change > 0 ? '↑' : ( $change < 0 ? '↓' : '→' );
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'AI Visibility Dashboard', 'ai-visibility-monitor' ); ?></h1>

	<p class="avm-confidence">
		<?php esc_html_e( 'Confidence:', 'ai-visibility-monitor' ); ?>
		<strong><?php echo esc_html( $metrics['confidence'] ); ?></strong>
		<span class="description">
			<?php
			printf(
				/* translators: %d: number of completed tests */
				esc_html__( 'based on %d completed tests', 'ai-visibility-monitor' ),
				(int) $metrics['total_tests']
			);
			?>
		</span>
	</p>

	<div class="avm-cards">
		<div class="avm-card avm-card-score">
			<span class="avm-card-label"><?php esc_html_e( 'AI Visibility Score', 'ai-visibility-monitor' ); ?></span>
			<span class="avm-card-value"><?php echo (int) $metrics['score']; ?><small>/100</small></span>
			<span class="avm-card-delta"><?php echo esc_html( $arrow . ' ' . abs( $change ) ); ?></span>
			<span class="description"><?php esc_html_e( 'Indicative measure — not an official AI platform score.', 'ai-visibility-monitor' ); ?></span>
		</div>
		<?php
		$cards = array(
			array( __( 'Brand mention rate', 'ai-visibility-monitor' ), $metrics['mention_rate'] . '%' ),
			array( __( 'Website citation rate', 'ai-visibility-monitor' ), $metrics['citation_rate'] . '%' ),
			array( __( 'Recommendation rate', 'ai-visibility-monitor' ), $metrics['recommendation_rate'] . '%' ),
			array( __( 'Share of voice', 'ai-visibility-monitor' ), $metrics['share_of_voice'] . '%' ),
			array( __( 'Prompt coverage', 'ai-visibility-monitor' ), $metrics['prompt_coverage'] . '%' ),
		);
		foreach ( $cards as $card ) :
			?>
			<div class="avm-card">
				<span class="avm-card-label"><?php echo esc_html( $card[0] ); ?></span>
				<span class="avm-card-value"><?php echo esc_html( $card[1] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<h2><?php esc_html_e( 'Score components', 'ai-visibility-monitor' ); ?></h2>
	<table class="widefat striped avm-components">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Component', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Weight', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Value', 'ai-visibility-monitor' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$labels = array(
				'citation_rate'       => __( 'Website citation rate', 'ai-visibility-monitor' ),
				'recommendation_rate' => __( 'Brand recommendation rate', 'ai-visibility-monitor' ),
				'share_of_voice'      => __( 'Competitor share of voice', 'ai-visibility-monitor' ),
				'prompt_coverage'     => __( 'Prompt coverage', 'ai-visibility-monitor' ),
				'technical_readiness' => __( 'Technical readiness', 'ai-visibility-monitor' ),
				'consistency'         => __( 'Visibility consistency', 'ai-visibility-monitor' ),
			);
			foreach ( $labels as $key => $label ) :
				?>
				<tr>
					<td><?php echo esc_html( $label ); ?></td>
					<td><?php echo esc_html( round( $metrics['weights'][ $key ] * 100 ) . '%' ); ?></td>
					<td><?php echo esc_html( $metrics['components'][ $key ] . '%' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="avm-actions">
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=avm_export_csv' ), 'avm_export_csv' ) ); ?>">
			<?php esc_html_e( 'Export results (CSV)', 'ai-visibility-monitor' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=avm-prompts' ) ); ?>">
			<?php esc_html_e( 'Manage prompts', 'ai-visibility-monitor' ); ?>
		</a>
	</p>
</div>
