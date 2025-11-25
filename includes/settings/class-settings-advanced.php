<?php
/**
 * Settings Advanced - System Info, Token Usage, White-Label, Notifications
 * Handles advanced settings and system information
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings_Advanced {

	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Private constructor for singleton
	}

	/**
	 * Display system information card
	 */
	public function render_system_info_card() {
		?>
		<!-- System Status Card -->
		<div class="freshrank-card">
			<h3><span class="dashicons dashicons-dashboard"></span> <?php _e( 'System Status', 'freshrank-ai' ); ?></h3>
			<div class="freshrank-card-body">
				<?php $this->display_system_info(); ?>

				<!-- Token Usage Section (Inside System Status Card) -->
				<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
					<h4 style="margin-top: 0; margin-bottom: 15px;">
						<span class="dashicons dashicons-chart-bar" style="vertical-align: middle;"></span>
						<?php _e( 'Token Usage & Costs', 'freshrank-ai' ); ?>
					</h4>
					<?php $this->display_token_usage(); ?>
				</div>

				<div style="text-align: center; margin-top: 30px;">
					<a href="https://docs.themeisle.com/collection/2368-freshrank" class="button button-secondary button-hero" target="_blank" style="display:inline-flex; align-items:center; gap: 10px;">
						<span class="dashicons dashicons-editor-help" style="font-size: 21px;"></span>
						<?php _e( 'Documentation', 'freshrank-ai' ); ?>
					</a>
				</div>

				<?php if ( freshrank_is_free_version() ) : ?>
				<!-- Pro Features Upgrade Box (Inside System Status Card) -->
				<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
					<div style="background: #f0f6fc; border: 2px solid #2271b1; border-radius: 6px; padding: 20px;">
						<div style="text-align: center; margin-bottom: 12px;">
							<span class="dashicons dashicons-lock" style="font-size: 36px; color: #2271b1; width: 36px; height: 36px;"></span>
						</div>

						<h4 style="text-align: center; margin: 0 0 12px 0; font-size: 16px;"><?php _e( 'Unlock Pro Features', 'freshrank-ai' ); ?></h4>

						<div style="background: white; padding: 12px; border-radius: 4px; margin-bottom: 12px;">
							<p style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600;"><?php _e( 'Pro includes:', 'freshrank-ai' ); ?></p>
							<ul style="list-style: none; padding: 0; margin: 0; font-size: 11px;">
								<li style="padding: 4px 0; border-bottom: 1px solid #f0f0f0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'OpenRouter - 450+ AI models', 'freshrank-ai' ); ?></li>
								<li style="padding: 4px 0; border-bottom: 1px solid #f0f0f0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'Custom model selection', 'freshrank-ai' ); ?></li>
								<li style="padding: 4px 0; border-bottom: 1px solid #f0f0f0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'GSC Integration', 'freshrank-ai' ); ?></li>
								<li style="padding: 4px 0; border-bottom: 1px solid #f0f0f0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'Custom AI instructions', 'freshrank-ai' ); ?></li>
								<li style="padding: 4px 0; border-bottom: 1px solid #f0f0f0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'Category & severity filters', 'freshrank-ai' ); ?></li>
								<li style="padding: 4px 0;"><span class="dashicons dashicons-yes" style="color: #46b450; font-size: 14px; width: 14px; height: 14px;"></span> <?php _e( 'White-label options', 'freshrank-ai' ); ?></li>
							</ul>
						</div>

						<div style="text-align: center;">
							<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" class="button button-primary" style="width: 100%;" target="_blank">
								<?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?>
							</a>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Display system information table
	 */
	public function display_system_info() {
		$stats = FreshRank_Database::get_instance()->get_statistics();

		?>
		<table class="widefat">
			<tbody>
				<tr>
					<td><?php _e( 'Plugin Version', 'freshrank-ai' ); ?></td>
					<td><?php echo FRESHRANK_VERSION; ?></td>
				</tr>
				<tr>
					<td><?php _e( 'WordPress Version', 'freshrank-ai' ); ?></td>
					<td><?php echo get_bloginfo( 'version' ); ?></td>
				</tr>
				<tr>
					<td><?php _e( 'PHP Version', 'freshrank-ai' ); ?></td>
					<td><?php echo PHP_VERSION; ?></td>
				</tr>
				<tr>
					<td><?php _e( 'Total Articles', 'freshrank-ai' ); ?></td>
					<td><?php echo number_format( $stats['total_articles'] ); ?></td>
				</tr>
				<tr>
					<td><?php _e( 'Analyzed Articles', 'freshrank-ai' ); ?></td>
					<td><?php echo number_format( $stats['analyzed_articles'] ); ?></td>
				</tr>
				<tr>
					<td><?php _e( 'Pending Drafts', 'freshrank-ai' ); ?></td>
					<td><?php echo number_format( $stats['pending_drafts'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Display token usage statistics
	 */
	public function display_token_usage() {
		$token_stats = FreshRank_Database::get_instance()->get_token_usage_stats();

		if ( $token_stats['combined']['total_tokens'] == 0 ) {
			?>
			<p class="description" style="color: #666; font-style: italic;">
				<?php _e( 'No token usage data available yet. Token usage will be tracked after running analysis or creating drafts.', 'freshrank-ai' ); ?>
			</p>
			<?php
			return;
		}

		// Calculate costs for combined usage
		$total_cost = $this->calculate_total_cost( $token_stats );

		?>
		<div style="margin-bottom: 16px; padding: 12px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 4px;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
				<div>
					<strong style="font-size: 1.1em;"><?php echo number_format( $token_stats['combined']['total_tokens'] ); ?></strong>
					<span style="color: #666;"> <?php _e( 'total tokens used', 'freshrank-ai' ); ?></span>
				</div>
				<?php if ( $total_cost > 0 ) : ?>
					<div style="font-size: 1.2em; color: #0073aa;">
						<strong>~$<?php echo number_format( $total_cost, 2 ); ?></strong>
					</div>
				<?php endif; ?>
			</div>
			<div style="font-size: 0.9em; color: #666;">
				<?php echo number_format( $token_stats['combined']['total_requests'] ); ?> <?php _e( 'API requests', 'freshrank-ai' ); ?> •
				<?php echo number_format( $token_stats['combined']['prompt_tokens'] ); ?> <?php _e( 'input', 'freshrank-ai' ); ?> •
				<?php echo number_format( $token_stats['combined']['completion_tokens'] ); ?> <?php _e( 'output', 'freshrank-ai' ); ?>
			</div>
		</div>

		<details style="margin-bottom: 16px;">
			<summary style="cursor: pointer; padding: 8px; background: #f9f9f9; border-radius: 4px; user-select: none;">
				<strong><?php _e( 'Breakdown by Operation', 'freshrank-ai' ); ?></strong>
			</summary>
			<div style="margin-top: 12px;">
				<?php if ( $token_stats['analysis']['total_tokens'] > 0 ) : ?>
					<div style="margin-bottom: 12px; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
							<strong><?php _e( 'Analysis', 'freshrank-ai' ); ?></strong>
							<span><?php echo number_format( $token_stats['analysis']['total_tokens'] ); ?> <?php _e( 'tokens', 'freshrank-ai' ); ?></span>
						</div>
						<div style="font-size: 0.85em; color: #666;">
							<?php echo number_format( $token_stats['analysis']['total_requests'] ); ?> <?php _e( 'requests', 'freshrank-ai' ); ?> •
							<?php echo number_format( $token_stats['analysis']['prompt_tokens'] ); ?> <?php _e( 'in', 'freshrank-ai' ); ?> •
							<?php echo number_format( $token_stats['analysis']['completion_tokens'] ); ?> <?php _e( 'out', 'freshrank-ai' ); ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $token_stats['updates']['total_tokens'] > 0 ) : ?>
					<div style="padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
							<strong><?php _e( 'Content Updates', 'freshrank-ai' ); ?></strong>
							<span><?php echo number_format( $token_stats['updates']['total_tokens'] ); ?> <?php _e( 'tokens', 'freshrank-ai' ); ?></span>
						</div>
						<div style="font-size: 0.85em; color: #666;">
							<?php echo number_format( $token_stats['updates']['total_requests'] ); ?> <?php _e( 'requests', 'freshrank-ai' ); ?> •
							<?php echo number_format( $token_stats['updates']['prompt_tokens'] ); ?> <?php _e( 'in', 'freshrank-ai' ); ?> •
							<?php echo number_format( $token_stats['updates']['completion_tokens'] ); ?> <?php _e( 'out', 'freshrank-ai' ); ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</details>

		<?php
		// Show breakdown by model if there's variety
		$all_models    = array_merge(
			array_keys( $token_stats['analysis']['by_model'] ),
			array_keys( $token_stats['updates']['by_model'] )
		);
		$unique_models = array_unique( $all_models );

		if ( count( $unique_models ) > 1 ) :
			?>
			<details>
				<summary style="cursor: pointer; padding: 8px; background: #f9f9f9; border-radius: 4px; user-select: none;">
					<strong><?php _e( 'Breakdown by Model', 'freshrank-ai' ); ?></strong>
				</summary>
				<div style="margin-top: 12px;">
					<?php
					foreach ( $unique_models as $model ) :
						$analysis_data  = isset( $token_stats['analysis']['by_model'][ $model ] ) ? $token_stats['analysis']['by_model'][ $model ] : array(
							'tokens'   => 0,
							'requests' => 0,
						);
						$update_data    = isset( $token_stats['updates']['by_model'][ $model ] ) ? $token_stats['updates']['by_model'][ $model ] : array(
							'tokens'   => 0,
							'requests' => 0,
						);
						$total_tokens   = $analysis_data['tokens'] + $update_data['tokens'];
						$total_requests = $analysis_data['requests'] + $update_data['requests'];
						?>
						<div style="margin-bottom: 8px; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
							<div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
								<strong><?php echo esc_html( $model ); ?></strong>
								<span><?php echo number_format( $total_tokens ); ?> <?php _e( 'tokens', 'freshrank-ai' ); ?></span>
							</div>
							<div style="font-size: 0.85em; color: #666;">
								<?php echo number_format( $total_requests ); ?> <?php _e( 'requests', 'freshrank-ai' ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endif; ?>

		<p class="description" style="margin-top: 12px; font-size: 0.85em; color: #666;">
			<?php _e( 'Cost estimates are approximate and based on current OpenAI pricing. Actual costs may vary based on your API plan.', 'freshrank-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Calculate total cost from token usage stats
	 */
	public function calculate_total_cost( $token_stats ) {
		// Same pricing as in dashboard
		$pricing = array(
			'gpt-5-pro'   => array(
				'input'  => 15.00,
				'output' => 120.00,
			),
			'gpt-5'       => array(
				'input'  => 1.25,
				'output' => 10.00,
			),
			'gpt-5-mini'  => array(
				'input'  => 0.10,
				'output' => 0.40,
			),
			'gpt-5-nano'  => array(
				'input'  => 0.03,
				'output' => 0.12,
			),
			'o3-pro'      => array(
				'input'  => 20.00,
				'output' => 160.00,
			),
			'o3-mini'     => array(
				'input'  => 1.10,
				'output' => 4.40,
			),
			'gpt-4o'      => array(
				'input'  => 5.00,
				'output' => 15.00,
			),
			'gpt-4o-mini' => array(
				'input'  => 0.15,
				'output' => 0.60,
			),
		);

		$total_cost = 0;

		// Calculate cost for analysis models
		foreach ( $token_stats['analysis']['by_model'] as $model => $data ) {
			if ( isset( $pricing[ $model ] ) ) {
				$input_cost  = ( $data['prompt_tokens'] / 1000000 ) * $pricing[ $model ]['input'];
				$output_cost = ( $data['completion_tokens'] / 1000000 ) * $pricing[ $model ]['output'];
				$total_cost += $input_cost + $output_cost;
			}
		}

		// Calculate cost for update models
		foreach ( $token_stats['updates']['by_model'] as $model => $data ) {
			if ( isset( $pricing[ $model ] ) ) {
				$input_cost  = ( $data['prompt_tokens'] / 1000000 ) * $pricing[ $model ]['input'];
				$output_cost = ( $data['completion_tokens'] / 1000000 ) * $pricing[ $model ]['output'];
				$total_cost += $input_cost + $output_cost;
			}
		}

		return $total_cost;
	}

	/**
	 * Render notification settings page
	 */
	public function render_notification_settings() {
		$debug_mode = get_option( 'freshrank_debug_mode', 0 );

		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php _e( 'Debug Mode', 'freshrank-ai' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="debug_mode" value="1" <?php checked( $debug_mode ); ?>>
								<?php _e( 'Enable detailed logging for troubleshooting', 'freshrank-ai' ); ?>
							</label>
							<p class="description">
								<?php _e( 'When enabled, detailed logs will be written to the WordPress debug log. Only enable when troubleshooting issues.', 'freshrank-ai' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save notification settings
	 */
	public function save_notification_settings() {
		$debug_mode = isset( $_POST['debug_mode'] ) ? 1 : 0;
		update_option( 'freshrank_debug_mode', $debug_mode );
	}

	/**
	 * Render white-label settings (Pro only)
	 */
	public function render_whitelabel_settings() {
		// Get current white-label settings
		$whitelabel_enabled = get_option( 'freshrank_whitelabel_enabled', 1 );
		$plugin_name        = get_option( 'freshrank_whitelabel_plugin_name', 'FreshRank AI' );
		$logo_url           = get_option( 'freshrank_whitelabel_logo_url', '' );
		$primary_color      = get_option( 'freshrank_whitelabel_primary_color', '#0073aa' );
		$support_email      = get_option( 'freshrank_whitelabel_support_email', '' );
		$docs_url           = get_option( 'freshrank_whitelabel_docs_url', 'https://freshrank.ai/docs' );
		$hide_branding      = get_option( 'freshrank_whitelabel_hide_branding', 0 );

		?>
		<style>
			.whitelabel-settings-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				margin: 20px 0;
				box-shadow: 0 1px 1px rgba(0,0,0,0.04);
			}
			.whitelabel-settings-card h2 {
				margin-top: 0;
				font-size: 18px;
				border-bottom: 1px solid #eee;
				padding-bottom: 10px;
			}
			.whitelabel-form-table {
				width: 100%;
				margin-top: 15px;
			}
			.whitelabel-form-table th {
				width: 200px;
				text-align: left;
				padding: 15px 10px 15px 0;
				vertical-align: top;
				font-weight: 600;
			}
			.whitelabel-form-table td {
				padding: 15px 0;
			}
			.whitelabel-form-table input[type="text"],
			.whitelabel-form-table input[type="email"],
			.whitelabel-form-table input[type="url"],
			.whitelabel-form-table input[type="color"] {
				width: 100%;
				max-width: 500px;
			}
			.whitelabel-form-table .description {
				display: block;
				margin-top: 5px;
				color: #666;
				font-size: 13px;
			}
			.whitelabel-preview {
				background: #f9f9f9;
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 15px;
				margin-top: 20px;
			}
			.whitelabel-preview h3 {
				margin-top: 0;
				font-size: 16px;
			}
			.whitelabel-toggle-info {
				background: #e7f3ff;
				border-left: 4px solid #0073aa;
				padding: 15px;
				margin: 20px 0;
			}
		</style>

		<div class="whitelabel-settings-card">
			<h2><?php _e( 'Enable White-Label Mode', 'freshrank-ai' ); ?></h2>

			<table class="whitelabel-form-table">
				<tr>
					<th><?php _e( 'Enable White-Label', 'freshrank-ai' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="whitelabel_enabled" value="1" <?php checked( $whitelabel_enabled, true ); ?> />
							<?php _e( 'Enable white-label branding', 'freshrank-ai' ); ?>
						</label>
						<span class="description"><?php _e( 'Turn this on to customize the plugin branding with your agency details.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="whitelabel-settings-card">
			<h2><?php _e( 'Branding Settings', 'freshrank-ai' ); ?></h2>

			<table class="whitelabel-form-table">
				<tr>
					<th><label for="whitelabel_plugin_name"><?php _e( 'Plugin Name', 'freshrank-ai' ); ?></label></th>
					<td>
						<input type="text" id="whitelabel_plugin_name" name="whitelabel_plugin_name" value="<?php echo esc_attr( $plugin_name ); ?>" placeholder="FreshRank AI" />
						<span class="description"><?php _e( 'The name that will appear in the WordPress admin menu and settings pages.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>

				<tr>
					<th><label for="whitelabel_logo_url"><?php _e( 'Custom Logo URL', 'freshrank-ai' ); ?></label></th>
					<td>
						<input type="url" id="whitelabel_logo_url" name="whitelabel_logo_url" value="<?php echo esc_url( $logo_url ); ?>" placeholder="https://yoursite.com/logo.png" />
						<span class="description"><?php _e( 'URL to your agency logo (recommended: 200x50px PNG with transparent background).', 'freshrank-ai' ); ?></span>
						<?php if ( $logo_url ) : ?>
							<div style="margin-top: 10px;">
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo Preview" style="max-width: 200px; max-height: 50px; border: 1px solid #ddd; padding: 5px; background: #fff;" />
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th><label for="whitelabel_primary_color"><?php _e( 'Primary Color', 'freshrank-ai' ); ?></label></th>
					<td>
						<input type="color" id="whitelabel_primary_color" name="whitelabel_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" />
						<input type="text" id="whitelabel_primary_color_hex" value="<?php echo esc_attr( $primary_color ); ?>" readonly style="width: 100px; margin-left: 10px;" />
						<span class="description"><?php _e( 'Primary brand color used for buttons, links, and accents throughout the plugin.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="whitelabel-settings-card">
			<h2><?php _e( 'Support & Documentation', 'freshrank-ai' ); ?></h2>

			<table class="whitelabel-form-table">
				<tr>
					<th><label for="whitelabel_support_email"><?php _e( 'Support Email', 'freshrank-ai' ); ?></label></th>
					<td>
						<input type="email" id="whitelabel_support_email" name="whitelabel_support_email" value="<?php echo esc_attr( $support_email ); ?>" placeholder="support@youragency.com" />
						<span class="description"><?php _e( 'Support email address shown in help text and error messages.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>

				<tr>
					<th><label for="whitelabel_docs_url"><?php _e( 'Documentation URL', 'freshrank-ai' ); ?></label></th>
					<td>
						<input type="url" id="whitelabel_docs_url" name="whitelabel_docs_url" value="<?php echo esc_url( $docs_url ); ?>" placeholder="https://youragency.com/docs" />
						<span class="description"><?php _e( 'URL to your custom documentation or help center.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="whitelabel-settings-card">
			<h2><?php _e( 'Advanced Options', 'freshrank-ai' ); ?></h2>

			<table class="whitelabel-form-table">
				<tr>
					<th><?php _e( 'Hide FreshRank Branding', 'freshrank-ai' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="whitelabel_hide_branding" value="1" <?php checked( $hide_branding, true ); ?> />
							<?php _e( 'Remove "Powered by FreshRank AI" footer text', 'freshrank-ai' ); ?>
						</label>
						<span class="description"><?php _e( 'Completely remove FreshRank AI branding from the plugin interface.', 'freshrank-ai' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( $whitelabel_enabled ) : ?>
		<div class="whitelabel-preview">
			<h3><?php _e( 'Preview', 'freshrank-ai' ); ?></h3>
			<p><?php _e( 'How your branding will appear:', 'freshrank-ai' ); ?></p>
			<div style="background: #fff; border: 2px solid <?php echo esc_attr( $primary_color ); ?>; padding: 15px; border-radius: 4px; margin-top: 10px;">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $plugin_name ); ?>" style="max-width: 200px; max-height: 50px; margin-bottom: 10px;" />
				<?php else : ?>
					<h4 style="margin: 0 0 10px 0; color: <?php echo esc_attr( $primary_color ); ?>;"><?php echo esc_html( $plugin_name ); ?></h4>
				<?php endif; ?>
				<p style="margin: 0; color: #666; font-size: 13px;">
					<?php _e( 'AI-powered SEO & GEO content optimization', 'freshrank-ai' ); ?>
				</p>
				<?php if ( ! $hide_branding ) : ?>
					<p style="margin: 10px 0 0 0; color: #999; font-size: 11px;">
						<?php _e( 'Powered by FreshRank AI', 'freshrank-ai' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<script>
		jQuery(document).ready(function($) {
			// Sync color picker with hex input
			$('#whitelabel_primary_color').on('change', function() {
				$('#whitelabel_primary_color_hex').val($(this).val());
			});
		});
		</script>
		<?php
	}

	/**
	 * Save white-label settings (Pro only)
	 */
	public function save_whitelabel_settings() {
		// Save white-label enabled status
		$whitelabel_enabled = isset( $_POST['whitelabel_enabled'] ) ? 1 : 0;
		update_option( 'freshrank_whitelabel_enabled', $whitelabel_enabled );

		// Save plugin name
		if ( isset( $_POST['whitelabel_plugin_name'] ) ) {
			$plugin_name = FreshRank_Validation_Helper::sanitize_text( $_POST['whitelabel_plugin_name'], 3, 100 );
			update_option( 'freshrank_whitelabel_plugin_name', $plugin_name );
		}

		// Save logo URL
		if ( isset( $_POST['whitelabel_logo_url'] ) ) {
			$logo_url = FreshRank_Validation_Helper::validate_url( $_POST['whitelabel_logo_url'] );
			update_option( 'freshrank_whitelabel_logo_url', $logo_url );
		}

		// Save primary color
		if ( isset( $_POST['whitelabel_primary_color'] ) ) {
			$primary_color = FreshRank_Validation_Helper::validate_hex_color( $_POST['whitelabel_primary_color'] );
			update_option( 'freshrank_whitelabel_primary_color', $primary_color );
		}

		// Save support email
		if ( isset( $_POST['whitelabel_support_email'] ) ) {
			$support_email = FreshRank_Validation_Helper::validate_email( $_POST['whitelabel_support_email'] );
			update_option( 'freshrank_whitelabel_support_email', $support_email );
		}

		// Save docs URL
		if ( isset( $_POST['whitelabel_docs_url'] ) ) {
			$docs_url = FreshRank_Validation_Helper::validate_url( $_POST['whitelabel_docs_url'] );
			update_option( 'freshrank_whitelabel_docs_url', $docs_url );
		}

		// Save hide branding option
		$hide_branding = isset( $_POST['whitelabel_hide_branding'] ) ? 1 : 0;
		update_option( 'freshrank_whitelabel_hide_branding', $hide_branding );
	}
}
