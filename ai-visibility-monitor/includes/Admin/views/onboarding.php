<?php
/**
 * Onboarding wizard view (spec §6.2).
 *
 * @package AVM
 * @var array|null $brand Existing primary brand row.
 */

defined( 'ABSPATH' ) || exit;

$details = $brand && ! empty( $brand['details'] ) ? (array) json_decode( $brand['details'], true ) : array();
$alts    = $brand ? implode( ', ', (array) json_decode( $brand['alt_names'], true ) ) : '';
?>
<div class="wrap avm-wrap avm-onboarding">
	<h1><?php esc_html_e( 'AI Visibility Monitor — Setup', 'ai-visibility-monitor' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Tell us about your brand so we can suggest relevant monitoring questions. You can change all of this later.', 'ai-visibility-monitor' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avm-form">
		<input type="hidden" name="action" value="avm_save_onboarding" />
		<?php wp_nonce_field( 'avm_save_onboarding' ); ?>

		<h2><?php esc_html_e( 'Organisation', 'ai-visibility-monitor' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="avm-brand"><?php esc_html_e( 'Primary brand name', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="text" id="avm-brand" name="primary_name" class="regular-text" required value="<?php echo esc_attr( $brand['primary_name'] ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-alts"><?php esc_html_e( 'Alternative names', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="text" id="avm-alts" name="alt_names" class="regular-text" value="<?php echo esc_attr( $alts ); ?>" placeholder="<?php esc_attr_e( 'comma separated', 'ai-visibility-monitor' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-domain"><?php esc_html_e( 'Website domain', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="text" id="avm-domain" name="domain" class="regular-text" value="<?php echo esc_attr( $brand['domain'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-desc"><?php esc_html_e( 'Business description', 'ai-visibility-monitor' ); ?></label></th>
				<td><textarea id="avm-desc" name="description" rows="2" class="large-text"><?php echo esc_textarea( $details['description'] ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-services"><?php esc_html_e( 'Products / services', 'ai-visibility-monitor' ); ?></label></th>
				<td>
					<textarea id="avm-services" name="services" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'One per line', 'ai-visibility-monitor' ); ?>"><?php echo esc_textarea( $details['services'] ?? '' ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-sectors"><?php esc_html_e( 'Sectors', 'ai-visibility-monitor' ); ?></label></th>
				<td><textarea id="avm-sectors" name="sectors" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'One per line', 'ai-visibility-monitor' ); ?>"><?php echo esc_textarea( $details['sectors'] ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="avm-geo"><?php esc_html_e( 'Geographic market', 'ai-visibility-monitor' ); ?></label></th>
				<td><input type="text" id="avm-geo" name="geo" class="regular-text" value="<?php echo esc_attr( $details['geo'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. London, UK', 'ai-visibility-monitor' ); ?>" /></td>
			</tr>
		</table>

		<p>
			<label>
				<input type="checkbox" name="generate_prompts" value="1" checked />
				<?php esc_html_e( 'Generate suggested monitoring questions from this profile (saved as drafts for approval).', 'ai-visibility-monitor' ); ?>
			</label>
		</p>

		<p>
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save and continue', 'ai-visibility-monitor' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=avm-settings' ) ); ?>"><?php esc_html_e( 'Skip to settings', 'ai-visibility-monitor' ); ?></a>
		</p>
	</form>
</div>
