<?php
/**
 * Diagnostics view (spec §21).
 *
 * @package AVM
 * @var array $queue Queue status snapshot.
 */

defined( 'ABSPATH' ) || exit;

use AVM\Support\Settings;
use AVM\Data\RunsRepository;

$runs      = new RunsRepository();
$used      = $runs->count_since( gmdate( 'Y-m-01 00:00:00' ) );
$cost      = $runs->cost_since( gmdate( 'Y-m-01 00:00:00' ) );
$forecast  = $runs->forecast_month();
$allowance = (int) Settings::get( 'monthly_allowance', 200 );
$provider  = Settings::get( 'provider', 'openai' );
$has_key   = Settings::has_api_key( $provider );

$rows = array(
	__( 'Plugin version', 'ai-visibility-monitor' )        => AVM_VERSION,
	__( 'WordPress version', 'ai-visibility-monitor' )     => get_bloginfo( 'version' ),
	__( 'PHP version', 'ai-visibility-monitor' )           => PHP_VERSION,
	__( 'Provider', 'ai-visibility-monitor' )              => $provider,
	__( 'Provider key configured', 'ai-visibility-monitor' ) => $has_key ? __( 'Yes', 'ai-visibility-monitor' ) : __( 'No', 'ai-visibility-monitor' ),
	__( 'Action Scheduler', 'ai-visibility-monitor' )      => function_exists( 'as_enqueue_async_action' ) ? __( 'Available', 'ai-visibility-monitor' ) : __( 'Using WP-Cron fallback', 'ai-visibility-monitor' ),
	__( 'Queue: queued', 'ai-visibility-monitor' )         => (int) $queue['queued'],
	__( 'Queue: processing', 'ai-visibility-monitor' )     => (int) $queue['processing'],
	__( 'Queue: failed', 'ai-visibility-monitor' )         => (int) $queue['failed'],
	__( 'Next queue run', 'ai-visibility-monitor' )        => $queue['next_cron'] ? gmdate( 'Y-m-d H:i', $queue['next_cron'] ) . ' UTC' : __( 'Not scheduled', 'ai-visibility-monitor' ),
	__( 'Queries this month', 'ai-visibility-monitor' )    => $allowance > 0 ? $used . ' / ' . $allowance : (string) $used,
	__( 'Estimated cost this month', 'ai-visibility-monitor' ) => '$' . number_format( (float) $cost, 4 ),
	__( 'Forecast queries (month end)', 'ai-visibility-monitor' ) => $allowance > 0 ? $forecast['queries'] . ' / ' . $allowance : (string) $forecast['queries'],
	__( 'Forecast cost (month end)', 'ai-visibility-monitor' ) => '$' . number_format( (float) $forecast['cost'], 4 ),
);
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>

	<?php if ( ! wp_next_scheduled( \AVM\Queue\Queue::CRON_HOOK ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Background processing is not scheduled. On low-traffic sites, configure a real server cron (WP-Cron depends on site visits).', 'ai-visibility-monitor' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped">
		<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th style="width:280px;"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( (string) $value ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Website readiness', 'ai-visibility-monitor' ); ?></h2>
	<p>
		<?php
		$readiness = (int) get_option( 'avm_technical_readiness', 0 );
		printf(
			/* translators: %d readiness score */
			esc_html__( 'Current technical readiness score: %d/100', 'ai-visibility-monitor' ),
			$readiness
		);
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="avm_run_site_analysis" />
		<?php wp_nonce_field( 'avm_run_site_analysis' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Run website readiness analysis', 'ai-visibility-monitor' ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Recent log entries', 'ai-visibility-monitor' ); ?></h2>
	<?php
	global $wpdb;
	$logs = $wpdb->get_results( "SELECT created_at, severity, event_type, message FROM {$wpdb->prefix}avm_logs ORDER BY id DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB
	?>
	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Time', 'ai-visibility-monitor' ); ?></th>
			<th><?php esc_html_e( 'Severity', 'ai-visibility-monitor' ); ?></th>
			<th><?php esc_html_e( 'Event', 'ai-visibility-monitor' ); ?></th>
			<th><?php esc_html_e( 'Message', 'ai-visibility-monitor' ); ?></th>
		</tr></thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No log entries.', 'ai-visibility-monitor' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( (array) $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( $log['created_at'] ); ?></td>
					<td><?php echo esc_html( $log['severity'] ); ?></td>
					<td><?php echo esc_html( $log['event_type'] ); ?></td>
					<td><?php echo esc_html( $log['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
