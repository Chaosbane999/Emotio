<?php
/**
 * Competitor management view (spec §6.2, §13.3).
 *
 * @package AVM
 * @var array $competitors Competitor rows.
 * @var int   $max         Competitor cap.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Competitors', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>

	<p class="description">
		<?php
		printf(
			/* translators: %d competitor cap */
			esc_html__( 'Track up to %d competitors on the current plan.', 'ai-visibility-monitor' ),
			(int) $max
		);
		?>
	</p>

	<h2><?php esc_html_e( 'Add a competitor', 'ai-visibility-monitor' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-form">
		<input type="hidden" name="action" value="avm_save_competitor" />
		<?php wp_nonce_field( 'avm_save_competitor' ); ?>
		<p class="avm-inline">
			<label><?php esc_html_e( 'Name', 'ai-visibility-monitor' ); ?><input type="text" name="name" required /></label>
			<label><?php esc_html_e( 'Domain', 'ai-visibility-monitor' ); ?><input type="text" name="domain" placeholder="example.com" /></label>
			<label><?php esc_html_e( 'Category', 'ai-visibility-monitor' ); ?><input type="text" name="category" /></label>
			<label>
				<?php esc_html_e( 'Priority', 'ai-visibility-monitor' ); ?>
				<select name="priority">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<option value="<?php echo (int) $i; ?>"<?php selected( 3, $i ); ?>><?php echo (int) $i; ?></option>
					<?php endfor; ?>
				</select>
			</label>
		</p>
		<p><label><?php esc_html_e( 'Alternative names (comma separated)', 'ai-visibility-monitor' ); ?><input type="text" name="alt_names" class="regular-text" /></label></p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add competitor', 'ai-visibility-monitor' ); ?></button></p>
	</form>

	<h2><?php esc_html_e( 'Monitored competitors', 'ai-visibility-monitor' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Domain', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Category', 'ai-visibility-monitor' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'ai-visibility-monitor' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $competitors ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No competitors yet.', 'ai-visibility-monitor' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $competitors as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['name'] ); ?></td>
					<td><?php echo esc_html( $c['domain'] ); ?></td>
					<td><?php echo esc_html( $c['category'] ); ?></td>
					<td><?php echo (int) $c['priority']; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_attr__( 'Remove this competitor?', 'ai-visibility-monitor' ); ?>')">
							<input type="hidden" name="action" value="avm_save_competitor" />
							<input type="hidden" name="competitor_id" value="<?php echo (int) $c['id']; ?>" />
							<input type="hidden" name="delete" value="1" />
							<?php wp_nonce_field( 'avm_save_competitor' ); ?>
							<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete', 'ai-visibility-monitor' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
