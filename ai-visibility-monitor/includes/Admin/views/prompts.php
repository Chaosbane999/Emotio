<?php
/**
 * Prompt management view (spec §7, §13.2).
 *
 * @package AVM
 * @var array $prompts All prompt rows.
 * @var int   $active  Count of active prompts.
 * @var int   $max     Active-prompt cap.
 */

defined( 'ABSPATH' ) || exit;

use AVM\Data\ResultsRepository;

$results = new ResultsRepository();
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Prompts', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>

	<p class="description">
		<?php
		printf(
			/* translators: 1: active count, 2: max */
			esc_html__( '%1$d of %2$d active prompts. Prompts must be approved before monitoring begins.', 'ai-visibility-monitor' ),
			(int) $active,
			(int) $max
		);
		?>
	</p>

	<h2><?php esc_html_e( 'Add a prompt', 'ai-visibility-monitor' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-form">
		<input type="hidden" name="action" value="avm_save_prompt" />
		<?php wp_nonce_field( 'avm_save_prompt' ); ?>
		<p>
			<label for="avm-prompt-text"><?php esc_html_e( 'Question', 'ai-visibility-monitor' ); ?></label>
			<textarea id="avm-prompt-text" name="prompt_text" rows="2" class="large-text" required></textarea>
		</p>
		<p class="avm-inline">
			<label>
				<?php esc_html_e( 'Category', 'ai-visibility-monitor' ); ?>
				<select name="category">
					<?php foreach ( array( 'brand', 'product', 'service', 'sector', 'location', 'buyer_problem', 'comparison', 'recommendation', 'informational' ) as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $cat ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Priority', 'ai-visibility-monitor' ); ?>
				<select name="priority">
					<option value="priority"><?php esc_html_e( 'Priority (frequent)', 'ai-visibility-monitor' ); ?></option>
					<option value="supporting" selected><?php esc_html_e( 'Supporting', 'ai-visibility-monitor' ); ?></option>
					<option value="discovery"><?php esc_html_e( 'Discovery', 'ai-visibility-monitor' ); ?></option>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Frequency', 'ai-visibility-monitor' ); ?>
				<select name="frequency">
					<option value="weekly"><?php esc_html_e( 'Weekly', 'ai-visibility-monitor' ); ?></option>
					<option value="monthly" selected><?php esc_html_e( 'Monthly', 'ai-visibility-monitor' ); ?></option>
					<option value="manual"><?php esc_html_e( 'Manual only', 'ai-visibility-monitor' ); ?></option>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Geo context', 'ai-visibility-monitor' ); ?>
				<input type="text" name="geo_context" placeholder="<?php esc_attr_e( 'e.g. London, UK', 'ai-visibility-monitor' ); ?>" />
			</label>
		</p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add prompt', 'ai-visibility-monitor' ); ?></button></p>
	</form>

	<h2><?php esc_html_e( 'Your prompts', 'ai-visibility-monitor' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Prompt', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Category', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Status', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Latest result', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Last checked', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'ai-visibility-monitor' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $prompts ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No prompts yet.', 'ai-visibility-monitor' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $prompts as $prompt ) : ?>
				<?php $latest = $results->latest_for_prompt( $prompt['id'] ); ?>
				<tr>
					<td><?php echo esc_html( wp_trim_words( $prompt['prompt_text'], 18 ) ); ?></td>
					<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $prompt['category'] ) ) ); ?></td>
					<td><?php echo esc_html( ucfirst( $prompt['priority'] ) ); ?></td>
					<td><span class="avm-badge avm-badge-<?php echo esc_attr( $prompt['status'] ); ?>"><?php echo esc_html( ucfirst( $prompt['status'] ) ); ?></span></td>
					<td>
						<?php
						if ( $latest ) {
							echo $latest['brand_detected'] ? '✅ ' : '⛔ ';
							echo esc_html( ucfirst( str_replace( '_', ' ', $latest['recommendation'] ) ) );
						} else {
							echo '—';
						}
						?>
					</td>
					<td><?php echo esc_html( $prompt['last_run_at'] ? mysql2date( 'j M Y', $prompt['last_run_at'] ) : '—' ); ?></td>
					<td class="avm-row-actions">
						<?php
						$do_buttons = array();
						if ( 'active' !== $prompt['status'] ) {
							$do_buttons['approve'] = __( 'Approve', 'ai-visibility-monitor' );
						} else {
							$do_buttons['pause'] = __( 'Pause', 'ai-visibility-monitor' );
						}
						$do_buttons['run']       = __( 'Run', 'ai-visibility-monitor' );
						$do_buttons['duplicate'] = __( 'Duplicate', 'ai-visibility-monitor' );
						$do_buttons['delete']    = __( 'Delete', 'ai-visibility-monitor' );
						foreach ( $do_buttons as $do => $label ) :
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-inline-form"<?php echo 'delete' === $do ? ' onsubmit="return confirm(\'' . esc_attr__( 'Delete this prompt?', 'ai-visibility-monitor' ) . '\')"' : ''; ?>>
								<input type="hidden" name="action" value="avm_prompt_action" />
								<input type="hidden" name="prompt_do" value="<?php echo esc_attr( $do ); ?>" />
								<input type="hidden" name="prompt_id" value="<?php echo (int) $prompt['id']; ?>" />
								<?php wp_nonce_field( 'avm_prompt_action' ); ?>
								<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
							</form>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
