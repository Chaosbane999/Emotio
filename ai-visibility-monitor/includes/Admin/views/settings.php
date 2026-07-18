<?php
/**
 * Settings view (spec §6.2 AI provider connection, §16 cost controls, §17 security).
 *
 * @package AVM
 * @var array $settings  Current settings.
 * @var array $providers Registered providers.
 */

defined( 'ABSPATH' ) || exit;

use AVM\Support\Settings;

$current_provider = $settings['provider'] ?? 'openai';
$has_key          = Settings::has_api_key( $current_provider );
$is_constant      = Settings::key_is_constant( $current_provider );
$key_status       = Settings::key_status( $current_provider );
$masked           = $has_key ? \AVM\Support\Crypto::mask( Settings::get_api_key( $current_provider ) ) : '';
?>
<div class="wrap avm-wrap">
	<h1><?php esc_html_e( 'Settings', 'ai-visibility-monitor' ); ?></h1>
	<?php include __DIR__ . '/_notices.php'; ?>

	<?php if ( 'unreadable' === $key_status ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Your saved API key can no longer be read.', 'ai-visibility-monitor' ); ?></strong>
				<?php esc_html_e( 'This usually means the WordPress security keys (salts) in wp-config.php changed after the key was saved. Please re-enter your API key below to restore monitoring.', 'ai-visibility-monitor' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-form">
		<input type="hidden" name="action" value="avm_save_settings" />
		<?php wp_nonce_field( 'avm_save_settings' ); ?>

		<h2><?php esc_html_e( 'AI provider', 'ai-visibility-monitor' ); ?></h2>
		<div class="notice notice-info inline" style="margin:0 0 12px;">
			<p>
				<strong><?php esc_html_e( 'This is your own provider account.', 'ai-visibility-monitor' ); ?></strong>
				<?php esc_html_e( 'All AI queries are made directly from this website using the key below, and the provider bills you directly for usage. Emotio does not process the calls and does not charge for them.', 'ai-visibility-monitor' ); ?>
			</p>
		</div>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="avm-provider"><?php esc_html_e( 'Provider', 'ai-visibility-monitor' ); ?></label></th>
				<td>
					<select id="avm-provider" name="provider">
						<?php foreach ( $providers as $slug => $provider ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $current_provider, $slug ); ?>><?php echo esc_html( $provider->label() ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-model"><?php esc_html_e( 'Model', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="text" id="avm-model" name="model" value="<?php echo esc_attr( $settings['model'] ?? 'gpt-4o-mini' ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-api-key"><?php esc_html_e( 'API key', 'ai-visibility-monitor' ); ?></label></th>
				<td>
					<?php if ( $is_constant ) : ?>
						<p><code><?php esc_html_e( 'Defined in wp-config.php', 'ai-visibility-monitor' ); ?></code> — <?php echo esc_html( $masked ); ?></p>
						<p class="description"><?php esc_html_e( 'The key is set as a constant and cannot be edited here.', 'ai-visibility-monitor' ); ?></p>
					<?php else : ?>
						<input type="password" id="avm-api-key" name="api_key" value="" autocomplete="off"
							placeholder="<?php echo $has_key ? esc_attr( $masked ) : esc_attr__( 'Enter API key', 'ai-visibility-monitor' ); ?>" class="regular-text" />
						<button type="button" class="button" id="avm-test-connection"><?php esc_html_e( 'Test connection', 'ai-visibility-monitor' ); ?></button>
						<span id="avm-test-result" class="avm-test-result"></span>
						<p class="description">
							<?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing key. For maximum security define AVM_OPENAI_API_KEY in wp-config.php instead.', 'ai-visibility-monitor' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Cost controls', 'ai-visibility-monitor' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="avm-allowance"><?php esc_html_e( 'Monthly query allowance', 'ai-visibility-monitor' ); ?></label></th>
				<td>
					<input type="number" id="avm-allowance" name="monthly_allowance" min="0" value="<?php echo (int) ( $settings['monthly_allowance'] ?? 200 ); ?>" />
					<p class="description"><?php esc_html_e( '0 means unlimited. Scans stop automatically once the allowance is reached.', 'ai-visibility-monitor' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-batch"><?php esc_html_e( 'Batch size', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="number" id="avm-batch" name="batch_size" min="1" max="25" value="<?php echo (int) ( $settings['batch_size'] ?? 3 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-retry"><?php esc_html_e( 'Retry limit', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="number" id="avm-retry" name="retry_limit" min="0" max="10" value="<?php echo (int) ( $settings['retry_limit'] ?? 3 ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Store raw answers', 'ai-visibility-monitor' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="store_raw_answers" value="1"<?php checked( ! empty( $settings['store_raw_answers'] ) ); ?> />
						<?php esc_html_e( 'Keep the full AI answer text for each result (uses more storage).', 'ai-visibility-monitor' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Data & privacy', 'ai-visibility-monitor' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'ai-visibility-monitor' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="delete_data_on_uninstall" value="1"<?php checked( (int) get_option( 'avm_delete_data_on_uninstall', 0 ) ); ?> />
						<?php esc_html_e( 'Permanently delete all plugin tables, settings and stored API keys when the plugin is deleted.', 'ai-visibility-monitor' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Deactivating the plugin never deletes data; only deleting it (with this ticked) does.', 'ai-visibility-monitor' ); ?></p>
				</td>
			</tr>
		</table>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ai-visibility-monitor' ); ?></button></p>
	</form>
</div>
