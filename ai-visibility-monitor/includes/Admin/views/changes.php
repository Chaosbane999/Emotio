<?php
/**
 * Change report view (spec §13.5).
 *
 * @package AVM
 * @var array $changes Change rows from ChangeService.
 */

defined( 'ABSPATH' ) || exit;

$icons = array( 'gain' => '⬆', 'loss' => '⬇', 'shift' => '↔' );
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Changes', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>
	<p class="description"><?php esc_html_e( 'What changed between the two most recent tests for each monitored prompt.', 'ai-visibility-monitor' ); ?></p>

	<?php if ( empty( $changes ) ) : ?>
		<p><?php esc_html_e( 'No changes detected yet. At least two completed tests per prompt are needed.', 'ai-visibility-monitor' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Change', 'ai-visibility-monitor' ); ?></th>
					<th><?php esc_html_e( 'Prompt', 'ai-visibility-monitor' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ai-visibility-monitor' ); ?></th>
					<th><?php esc_html_e( 'When', 'ai-visibility-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $changes as $c ) : ?>
					<tr class="avm-change-<?php echo esc_attr( $c['direction'] ); ?>">
						<td><span class="avm-change-icon"><?php echo esc_html( $icons[ $c['direction'] ] ?? '•' ); ?></span> <?php echo esc_html( $c['label'] ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $c['prompt'], 16 ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $c['type'] ) ); ?></td>
						<td><?php echo esc_html( mysql2date( 'j M Y', $c['when'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
