<?php
/**
 * Recommendations view (spec §15.2).
 *
 * @package AVM
 * @var array $recommendations Recommendation rows.
 */

defined( 'ABSPATH' ) || exit;

$statuses = array(
	'open'        => __( 'Open', 'ai-visibility-monitor' ),
	'planned'     => __( 'Planned', 'ai-visibility-monitor' ),
	'in_progress' => __( 'In progress', 'ai-visibility-monitor' ),
	'completed'   => __( 'Completed', 'ai-visibility-monitor' ),
	'dismissed'   => __( 'Dismissed', 'ai-visibility-monitor' ),
);
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Recommendations', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>

	<?php if ( empty( $recommendations ) ) : ?>
		<p><?php esc_html_e( 'No recommendations yet. Run some monitoring scans to generate actions.', 'ai-visibility-monitor' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $recommendations as $rec ) : ?>
		<div class="avm-rec avm-rec-<?php echo esc_attr( $rec['priority'] ); ?>">
			<div class="avm-rec-head">
				<span class="avm-badge avm-badge-<?php echo esc_attr( $rec['priority'] ); ?>"><?php echo esc_html( ucfirst( $rec['priority'] ) ); ?></span>
				<strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $rec['type'] ) ) ); ?></strong>
				<span class="avm-badge avm-badge-<?php echo esc_attr( $rec['status'] ); ?>"><?php echo esc_html( $statuses[ $rec['status'] ] ?? $rec['status'] ); ?></span>
			</div>
			<p class="avm-rec-evidence"><strong><?php esc_html_e( 'Evidence:', 'ai-visibility-monitor' ); ?></strong> <?php echo esc_html( $rec['evidence'] ); ?></p>
			<p class="avm-rec-action"><strong><?php esc_html_e( 'Recommended action:', 'ai-visibility-monitor' ); ?></strong> <?php echo esc_html( $rec['action'] ); ?></p>
			<?php if ( $rec['page_id'] ) : ?>
				<p><a href="<?php echo esc_url( get_edit_post_link( (int) $rec['page_id'] ) ); ?>"><?php esc_html_e( 'Open target page', 'ai-visibility-monitor' ); ?></a></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-rec-form">
				<input type="hidden" name="action" value="avm_save_recommendation" />
				<input type="hidden" name="recommendation_id" value="<?php echo (int) $rec['id']; ?>" />
				<?php wp_nonce_field( 'avm_save_recommendation' ); ?>
				<label>
					<?php esc_html_e( 'Status', 'ai-visibility-monitor' ); ?>
					<select name="status">
						<?php foreach ( $statuses as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $rec['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<input type="text" name="notes" class="regular-text" placeholder="<?php esc_attr_e( 'Internal note…', 'ai-visibility-monitor' ); ?>" value="<?php echo esc_attr( $rec['notes'] ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Update', 'ai-visibility-monitor' ); ?></button>
			</form>
		</div>
	<?php endforeach; ?>
</div>
