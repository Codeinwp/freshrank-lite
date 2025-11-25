<?php
/**
 * Settings GSC - Google Search Console OAuth Configuration
 * Handles GSC connection, authentication, and diagnostics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings_GSC {

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
	 * Render GSC settings page
	 */
	public function render_gsc_settings() {
		$gsc_authenticated      = get_option( 'freshrank_gsc_authenticated', false );
		$prioritization_enabled = get_option( 'freshrank_prioritization_enabled', 0 );
		$client_id              = get_option( 'freshrank_gsc_client_id', '' );

		// Decrypt client secret for display (show placeholder if encrypted)
		$client_secret_encrypted = get_option( 'freshrank_gsc_client_secret', '' );
		$client_secret           = '';
		if ( ! empty( $client_secret_encrypted ) ) {
			try {
				$client_secret = FreshRank_Encryption::decrypt( $client_secret_encrypted );
				// Show placeholder instead of actual secret for security
				$client_secret = str_repeat( '•', 40 ); // Placeholder dots
			} catch ( Exception $e ) {
				// If decryption fails, it might not be encrypted yet
				$client_secret = $client_secret_encrypted;
			}
		}

		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php _e( 'Article Prioritization', 'freshrank-ai' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="prioritization_enabled" value="1" <?php checked( $prioritization_enabled ); ?>>
								<?php _e( 'Enable GSC-based article prioritization', 'freshrank-ai' ); ?>
							</label>
							<p class="description">
								<?php _e( 'When enabled, articles will be prioritized based on Google Search Console performance data. Requires GSC authentication below.', 'freshrank-ai' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Date for Age Calculation', 'freshrank-ai' ); ?></th>
					<td>
						<fieldset>
							<?php
							$date_type = get_option( 'freshrank_gsc_date_type', 'post_date' );
							?>
							<label>
								<input type="radio" name="gsc_date_type" value="post_date" <?php checked( $date_type, 'post_date' ); ?>>
								<?php _e( 'Published Date', 'freshrank-ai' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="gsc_date_type" value="post_modified" <?php checked( $date_type, 'post_modified' ); ?>>
								<?php _e( 'Modified Date', 'freshrank-ai' ); ?>
							</label>
							<p class="description">
								<?php _e( 'Choose which date to use for calculating content age in the priority score. Published Date prioritizes older posts, while Modified Date prioritizes posts that haven\'t been updated recently.', 'freshrank-ai' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Connection Status', 'freshrank-ai' ); ?></th>
					<td>
						<?php if ( $gsc_authenticated ) : ?>
							<span class="freshrank-status-connected">
								<?php _e( '✓ Connected to Google Search Console', 'freshrank-ai' ); ?>
							</span>
							<p>
								<button type="button" id="freshrank-test-gsc-connection" class="button">
									<?php _e( 'Test Connection', 'freshrank-ai' ); ?>
								</button>
								<button type="button" id="freshrank-diagnose-gsc" class="button">
									<span class="dashicons dashicons-admin-tools" style="margin-top: 4px;"></span>
									<?php _e( 'Run Diagnostics', 'freshrank-ai' ); ?>
								</button>
								<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=freshrank-settings&tab=gsc&action=disconnect' ), 'freshrank_gsc_disconnect' ); ?>" class="button button-secondary">
									<?php _e( 'Disconnect', 'freshrank-ai' ); ?>
								</a>
							</p>
						<?php else : ?>
							<span class="freshrank-status-disconnected">
								<?php _e( '✗ Not connected to Google Search Console', 'freshrank-ai' ); ?>
							</span>
							<p>
								<button type="button" id="freshrank-diagnose-gsc" class="button">
									<span class="dashicons dashicons-admin-tools" style="margin-top: 4px;"></span>
									<?php _e( 'Run Diagnostics', 'freshrank-ai' ); ?>
								</button>
							</p>
						<?php endif; ?>

						<!-- Diagnostic Results Container -->
						<div id="freshrank-gsc-diagnostics" style="display: none; margin-top: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
							<h4 style="margin-top: 0;"><?php _e( 'GSC Connection Diagnostics', 'freshrank-ai' ); ?></h4>
							<div id="freshrank-gsc-diagnostics-content"></div>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'OAuth Client ID', 'freshrank-ai' ); ?></th>
					<td>
						<input type="text" name="gsc_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" />
						<p class="description">
							<?php _e( 'Your Google OAuth 2.0 Client ID. Get this from the Google Cloud Console.', 'freshrank-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'OAuth Client Secret', 'freshrank-ai' ); ?></th>
					<td>
						<input type="password" name="gsc_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" />
						<p class="description">
							<?php _e( 'Your Google OAuth 2.0 Client Secret. Keep this secure.', 'freshrank-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Redirect URI', 'freshrank-ai' ); ?></th>
					<td>
						<code><?php echo admin_url( 'admin.php?page=freshrank-settings&tab=gsc&action=callback' ); ?></code>
						<p class="description">
							<?php _e( 'Add this exact URL to your Google OAuth 2.0 configuration as an authorized redirect URI.', 'freshrank-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Authentication', 'freshrank-ai' ); ?></th>
					<td>
						<?php if ( ! empty( $client_id ) && ! empty( $client_secret ) ) : ?>
							<?php if ( ! $gsc_authenticated ) : ?>
								<a href="<?php echo $this->get_gsc_auth_url(); ?>" class="button button-primary">
									<?php _e( 'Connect to Google Search Console', 'freshrank-ai' ); ?>
								</a>
							<?php else : ?>
								<p><?php _e( 'Already authenticated. Use the disconnect button above to re-authenticate.', 'freshrank-ai' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description">
								<?php _e( 'Please save your OAuth credentials first, then you can authenticate.', 'freshrank-ai' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Settings', 'freshrank-ai' ) ); ?>

		<?php $this->render_prioritization_criteria( $gsc_authenticated, $prioritization_enabled ); ?>
		<?php $this->render_setup_instructions(); ?>
		<?php $this->render_debug_section( $gsc_authenticated ); ?>
		<?php $this->render_gsc_styles_and_scripts(); ?>
		<?php
	}

	/**
	 * Render prioritization criteria section
	 */
	private function render_prioritization_criteria( $gsc_authenticated, $prioritization_enabled ) {
		?>
		<h3><?php _e( 'Prioritization Criteria', 'freshrank-ai' ); ?></h3>
		<div class="freshrank-prioritization-info" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 4px; margin: 20px 0;">
			<?php if ( $gsc_authenticated && $prioritization_enabled ) : ?>
				<p style="color: #00a32a; font-weight: 600; margin-top: 0;">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php _e( 'Prioritization is active. Articles are ranked using these GSC metrics:', 'freshrank-ai' ); ?>
				</p>
			<?php else : ?>
				<p style="color: #646970; font-weight: 600; margin-top: 0;">
					<span class="dashicons dashicons-info"></span>
					<?php _e( 'When GSC prioritization is enabled, articles will be ranked using these metrics:', 'freshrank-ai' ); ?>
				</p>
			<?php endif; ?>

			<table class="widefat" style="margin-top: 15px;">
				<thead>
					<tr>
						<th style="width: 25%;"><?php _e( 'Metric', 'freshrank-ai' ); ?></th>
						<th style="width: 15%;"><?php _e( 'Weight', 'freshrank-ai' ); ?></th>
						<th><?php _e( 'Description', 'freshrank-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php _e( 'Impressions', 'freshrank-ai' ); ?></strong></td>
						<td>40%</td>
						<td><?php _e( 'How often the article appears in Google search results. Higher impressions = more visibility potential.', 'freshrank-ai' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php _e( 'Click-Through Rate (CTR)', 'freshrank-ai' ); ?></strong></td>
						<td>30%</td>
						<td><?php _e( 'Percentage of impressions that result in clicks. Low CTR indicates poor title/meta description.', 'freshrank-ai' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php _e( 'Average Position', 'freshrank-ai' ); ?></strong></td>
						<td>20%</td>
						<td><?php _e( 'Average ranking position in search results. Articles ranking 4-10 have the most optimization potential.', 'freshrank-ai' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php _e( 'Clicks', 'freshrank-ai' ); ?></strong></td>
						<td>10%</td>
						<td><?php _e( 'Total clicks received. Shows actual traffic value and user interest.', 'freshrank-ai' ); ?></td>
					</tr>
				</tbody>
			</table>

			<div style="margin-top: 15px; padding: 15px; background: #fff; border-left: 4px solid #2271b1;">
				<h4 style="margin: 0 0 10px 0;"><?php _e( 'How Priority Score Works', 'freshrank-ai' ); ?></h4>
				<p style="margin: 0;">
					<?php _e( 'Articles with <strong>high impressions</strong> but <strong>low CTR</strong> or <strong>position 4-10</strong> receive the highest priority scores. These are "quick win" opportunities where small optimizations can yield significant traffic gains.', 'freshrank-ai' ); ?>
				</p>
			</div>

			<?php if ( ! $gsc_authenticated || ! $prioritization_enabled ) : ?>
				<p style="margin-bottom: 0; margin-top: 15px; color: #646970;">
					<em><?php _e( 'Enable GSC-based prioritization above and connect your Google Search Console account to activate this feature.', 'freshrank-ai' ); ?></em>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render setup instructions (collapsible)
	 */
	private function render_setup_instructions() {
		?>
		<div class="freshrank-setup-instructions-toggle">
			<button type="button" class="button button-secondary freshrank-toggle-setup" id="freshrank-setup-toggle">
				<span class="dashicons dashicons-arrow-down-alt2"></span>
				<?php _e( 'Show Setup Instructions', 'freshrank-ai' ); ?>
			</button>
		</div>

		<div class="freshrank-setup-instructions" id="freshrank-setup-instructions" style="display: none;">
			<?php
			$setup_steps = array(
				array(
					'title'        => __( 'Create or Select a Google Cloud Project', 'freshrank-ai' ),
					'link'         => 'https://console.cloud.google.com/projectcreate',
					'link_text'    => __( 'Google Cloud Console - Create Project', 'freshrank-ai' ),
					'instructions' => array(
						__( 'Click the <strong>"NEW PROJECT"</strong> button in the top right', 'freshrank-ai' ),
						__( 'Enter a project name (e.g., "FreshRank AI GSC Integration")', 'freshrank-ai' ),
						__( 'Click <strong>"CREATE"</strong>', 'freshrank-ai' ),
					),
					'note'         => __( 'Or select an existing project from the project dropdown at the top of the page.', 'freshrank-ai' ),
				),
				array(
					'title'        => __( 'Enable the Google Search Console API', 'freshrank-ai' ),
					'link'         => 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
					'link_text'    => __( 'Search Console API Library Page', 'freshrank-ai' ),
					'instructions' => array(
						__( 'Make sure your project is selected in the top dropdown', 'freshrank-ai' ),
						__( 'Click the blue <strong>"ENABLE"</strong> button', 'freshrank-ai' ),
						__( 'Wait for the API to be enabled (takes a few seconds)', 'freshrank-ai' ),
					),
				),
				array(
					'title'        => __( 'Configure OAuth Consent Screen', 'freshrank-ai' ),
					'link'         => 'https://console.cloud.google.com/apis/credentials/consent',
					'link_text'    => __( 'OAuth Consent Screen', 'freshrank-ai' ),
					'instructions' => array(
						__( 'Select <strong>"External"</strong> user type', 'freshrank-ai' ),
						__( 'Click <strong>"CREATE"</strong>', 'freshrank-ai' ),
						__( 'Fill in the required fields:', 'freshrank-ai' ),
						array(
							__( '<strong>App name:</strong> FreshRank AI', 'freshrank-ai' ),
							__( '<strong>User support email:</strong> Your email', 'freshrank-ai' ),
							__( '<strong>Developer contact:</strong> Your email', 'freshrank-ai' ),
						),
						__( 'Click <strong>"SAVE AND CONTINUE"</strong> through all screens', 'freshrank-ai' ),
					),
				),
				array(
					'title'        => __( 'Create OAuth 2.0 Credentials', 'freshrank-ai' ),
					'link'         => 'https://console.cloud.google.com/apis/credentials',
					'link_text'    => __( 'Credentials Page', 'freshrank-ai' ),
					'instructions' => array(
						__( 'Click <strong>"+ CREATE CREDENTIALS"</strong> at the top', 'freshrank-ai' ),
						__( 'Select <strong>"OAuth client ID"</strong>', 'freshrank-ai' ),
						__( 'Choose <strong>"Web application"</strong> as the application type', 'freshrank-ai' ),
						__( 'Enter a name (e.g., "FreshRank AI Client")', 'freshrank-ai' ),
					),
				),
				array(
					'title'      => __( 'Add Authorized Redirect URI', 'freshrank-ai' ),
					'content'    => array(
						__( 'In the OAuth client configuration:', 'freshrank-ai' ),
						__( 'Scroll down to <strong>"Authorized redirect URIs"</strong>', 'freshrank-ai' ),
						__( 'Click <strong>"+ ADD URI"</strong>', 'freshrank-ai' ),
						__( 'Copy and paste this exact URI:', 'freshrank-ai' ),
					),
					'copy_field' => true,
					'note'       => __( 'The URI must match exactly, including https:// and any trailing paths.', 'freshrank-ai' ),
					'final_step' => __( 'Click <strong>"CREATE"</strong>', 'freshrank-ai' ),
				),
				array(
					'title'   => __( 'Copy Your Credentials', 'freshrank-ai' ),
					'content' => array(
						__( 'A popup will appear with your credentials:', 'freshrank-ai' ),
						__( 'Copy the <strong>"Client ID"</strong> and paste it in the field above', 'freshrank-ai' ),
						__( 'Copy the <strong>"Client Secret"</strong> and paste it in the field above', 'freshrank-ai' ),
						__( 'Click <strong>"OK"</strong> to close the popup', 'freshrank-ai' ),
					),
					'note'    => __( 'You can always find these credentials later in the Google Cloud Console under Credentials.', 'freshrank-ai' ),
				),
				array(
					'title'        => __( 'Save and Connect', 'freshrank-ai' ),
					'instructions' => array(
						__( 'Scroll up and paste your Client ID and Client Secret into the fields', 'freshrank-ai' ),
						__( 'Click <strong>"Save Changes"</strong> at the bottom of this page', 'freshrank-ai' ),
						__( 'After saving, click the <strong>"Connect to Google Search Console"</strong> button', 'freshrank-ai' ),
						__( 'Authorize FreshRank AI to access your Google Search Console data', 'freshrank-ai' ),
					),
					'success'      => __( 'Once connected, FreshRank AI will automatically prioritize articles based on their Search Console performance!', 'freshrank-ai' ),
				),
			);

			foreach ( $setup_steps as $index => $step ) {
				$this->render_setup_step( $index + 1, $step );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render individual setup step
	 */
	private function render_setup_step( $number, $step ) {
		?>
		<div class="freshrank-setup-step">
			<div class="freshrank-step-number"><?php echo $number; ?></div>
			<div class="freshrank-step-content">
				<h4><?php echo $step['title']; ?></h4>

				<?php if ( isset( $step['link'] ) ) : ?>
					<p><?php _e( 'Go to', 'freshrank-ai' ); ?> <a href="<?php echo esc_url( $step['link'] ); ?>" target="_blank" rel="noopener"><?php echo $step['link_text']; ?></a></p>
				<?php endif; ?>

				<?php if ( isset( $step['content'] ) ) : ?>
					<p><?php echo $step['content'][0]; ?></p>
				<?php endif; ?>

				<?php if ( isset( $step['instructions'] ) ) : ?>
					<ul>
						<?php foreach ( $step['instructions'] as $instruction ) : ?>
							<?php if ( is_array( $instruction ) ) : ?>
								<ul>
									<?php foreach ( $instruction as $sub ) : ?>
										<li><?php echo $sub; ?></li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<li><?php echo $instruction; ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( isset( $step['copy_field'] ) && $step['copy_field'] ) : ?>
					<div class="freshrank-copy-field">
						<input type="text" readonly value="<?php echo esc_attr( admin_url( 'admin.php?page=freshrank-settings&tab=gsc&action=callback' ) ); ?>" id="freshrank-redirect-uri" />
						<button type="button" class="button button-secondary freshrank-copy-uri">
							<span class="dashicons dashicons-admin-page"></span> <?php _e( 'Copy', 'freshrank-ai' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<?php if ( isset( $step['note'] ) ) : ?>
					<p class="freshrank-help-text"><?php echo $step['note']; ?></p>
				<?php endif; ?>

				<?php if ( isset( $step['final_step'] ) ) : ?>
					<ul><li><?php echo $step['final_step']; ?></li></ul>
				<?php endif; ?>

				<?php if ( isset( $step['success'] ) ) : ?>
					<p class="freshrank-success-text"><?php echo $step['success']; ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render debug section (only if authenticated)
	 */
	private function render_debug_section( $gsc_authenticated ) {
		if ( ! $gsc_authenticated ) {
			return;
		}

		?>
		<h3><?php _e( 'Debug Information', 'freshrank-ai' ); ?></h3>
		<div class="freshrank-debug-section" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-top: 20px;">
			<?php
			try {
				$gsc_api = freshrank_get_gsc_api();

				// 1. Site Properties
				echo '<h4>Available GSC Properties:</h4>';
				$properties = $gsc_api->get_site_properties();
				if ( ! empty( $properties ) ) {
					echo '<ul>';
					foreach ( $properties as $property ) {
						echo '<li><code>' . esc_html( $property['url'] ) . '</code> (' . $property['permission'] . ')</li>';
					}
					echo '</ul>';
				} else {
					echo '<p style="color: red;">No properties found</p>';
				}

				echo '<p><strong>WordPress Site:</strong> <code>' . esc_html( get_site_url() ) . '</code></p>';

				// 2. Permalink Structure
				$permalink_structure = get_option( 'permalink_structure' );
				echo '<p><strong>Permalink Structure:</strong> <code>' . esc_html( $permalink_structure ? $permalink_structure : 'Plain (not SEO-friendly)' ) . '</code></p>';

				if ( empty( $permalink_structure ) ) {
					echo '<p style="color: red; font-weight: bold;">⚠️ WARNING: You are using plain permalinks. GSC typically doesn\'t track URLs like <code>?p=123</code>. Please change to a SEO-friendly permalink structure in Settings > Permalinks.</p>';
				}

				// 3. Recent Post Test
				$this->render_sample_post_test( $gsc_api );

				// 4. Database Check
				global $wpdb;
				$articles_table = $wpdb->prefix . 'freshrank_articles';
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) 
							FROM {$articles_table} 
							WHERE impressions_current > %d",
						0
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				echo '<h4>Database Status:</h4>';
				echo '<p>Articles with GSC data in database: <strong>' . ( $count ? $count : '0' ) . '</strong></p>';

			} catch ( Exception $e ) {
				echo '<p style="color: red;">Debug error: ' . esc_html( $e->getMessage() ) . '</p>';
			}
			?>

			<h4>Troubleshooting Steps:</h4>
			<ol>
				<li><strong>Check Permalink Structure:</strong> Must be SEO-friendly (not Plain)</li>
				<li><strong>Verify GSC Property:</strong> WordPress site URL must match a GSC property</li>
				<li><strong>Check Post Age:</strong> Posts need to be published and indexed by Google</li>
				<li><strong>Wait for Data:</strong> GSC data has a 2-3 day delay</li>
				<li><strong>URL Format:</strong> Compare sample GSC URLs with WordPress URLs above</li>
			</ol>

			<p><button type="button" class="button freshrank-refresh-debug"><?php _e( 'Refresh Debug Info', 'freshrank-ai' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Render sample post test in debug section
	 */
	private function render_sample_post_test( $gsc_api ) {
		$recent_posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $recent_posts ) ) {
			return;
		}

		$test_post = $recent_posts[0];
		$test_url  = get_permalink( $test_post->ID );

		echo '<h4>Sample Post Test:</h4>';
		echo '<p><strong>Post:</strong> ' . esc_html( $test_post->post_title ) . '</p>';
		echo '<p><strong>URL:</strong> <code>' . esc_html( $test_url ) . '</code></p>';

		// Test for GSC data
		$end_date   = date( 'Y-m-d' );
		$start_date = date( 'Y-m-d', strtotime( '-30 days' ) );

		$test_data = $gsc_api->get_url_analytics( $test_url, $start_date, $end_date );

		if ( $test_data['impressions'] > 0 || $test_data['clicks'] > 0 ) {
			echo '<p style="color: green; font-weight: bold;">✓ GSC data found for this post!</p>';
			echo '<ul>';
			echo '<li>Impressions: ' . number_format( $test_data['impressions'] ) . '</li>';
			echo '<li>Clicks: ' . number_format( $test_data['clicks'] ) . '</li>';
			echo '<li>CTR: ' . number_format( $test_data['ctr'] * 100, 2 ) . '%</li>';
			echo '<li>Position: ' . number_format( $test_data['position'], 1 ) . '</li>';
			echo '</ul>';
		} else {
			echo '<p style="color: orange; font-weight: bold;">⚠️ No GSC data found for this post</p>';
			$this->test_raw_gsc_api( $gsc_api, $start_date, $end_date );
		}
	}

	/**
	 * Test raw GSC API call
	 */
	private function test_raw_gsc_api( $gsc_api, $start_date, $end_date ) {
		echo '<h5>Testing Raw GSC API:</h5>';
		try {
			$reflection = new ReflectionClass( $gsc_api );
			$method     = $reflection->getMethod( 'get_current_site_property' );
			$method->setAccessible( true );
			$site_property = $method->invoke( $gsc_api );

			$test_params = array(
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => array( 'page' ),
				'startRow'   => 0,
				'rowLimit'   => 5,
			);

			$method = $reflection->getMethod( 'make_api_request' );
			$method->setAccessible( true );
			$endpoint     = 'sites/' . urlencode( $site_property ) . '/searchAnalytics/query';
			$raw_response = $method->invoke( $gsc_api, $endpoint, $test_params );

			if ( ! empty( $raw_response['rows'] ) ) {
				echo '<p style="color: green;">✓ GSC API is working and returning data</p>';
				echo '<p><strong>Sample URLs from your GSC account:</strong></p>';
				echo '<ul>';
				foreach ( array_slice( $raw_response['rows'], 0, 5 ) as $row ) {
					echo '<li><code>' . esc_html( $row['keys'][0] ) . '</code> (' . $row['impressions'] . ' impressions)</li>';
				}
				echo '</ul>';
				echo '<p style="color: blue;"><strong>Compare these URLs with your WordPress post URLs above. If they don\'t match, that\'s the problem!</strong></p>';
			} else {
				echo '<p style="color: red;">GSC API returned no data for your site</p>';
			}
		} catch ( Exception $e ) {
			echo '<p style="color: red;">GSC API test failed: ' . esc_html( $e->getMessage() ) . '</p>';
		}
	}

	/**
	 * Render GSC-specific styles and scripts
	 */
	private function render_gsc_styles_and_scripts() {
		?>
		<style>
		.freshrank-setup-instructions {
			background: #f9f9f9;
			padding: 20px;
			border: 1px solid #ddd;
			border-radius: 4px;
			margin-top: 20px;
		}
		.freshrank-setup-step {
			display: flex;
			gap: 20px;
			margin-bottom: 30px;
			background: #fff;
			padding: 20px;
			border-radius: 4px;
			border-left: 4px solid #2271b1;
		}
		.freshrank-setup-step:last-child {
			margin-bottom: 0;
		}
		.freshrank-step-number {
			flex-shrink: 0;
			width: 40px;
			height: 40px;
			background: #2271b1;
			color: #fff;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 18px;
			font-weight: 600;
		}
		.freshrank-step-content {
			flex: 1;
		}
		.freshrank-step-content h4 {
			margin: 0 0 10px 0;
			font-size: 16px;
			color: #1d2327;
		}
		.freshrank-step-content ul {
			margin: 10px 0;
			padding-left: 20px;
		}
		.freshrank-step-content ul ul {
			margin: 5px 0;
		}
		.freshrank-step-content li {
			margin-bottom: 5px;
			line-height: 1.6;
		}
		.freshrank-copy-field {
			display: flex;
			gap: 10px;
			margin: 10px 0;
			align-items: center;
		}
		.freshrank-copy-field input {
			flex: 1;
			padding: 8px 12px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			background: #f6f7f7;
			font-family: monospace;
			font-size: 13px;
		}
		.freshrank-copy-field button {
			display: flex;
			align-items: center;
			gap: 5px;
			white-space: nowrap;
		}
		.freshrank-copy-field button .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
		}
		.freshrank-help-text {
			color: #646970;
			font-size: 13px;
			font-style: italic;
			margin: 10px 0 5px 0;
		}
		.freshrank-success-text {
			color: #00a32a;
			font-weight: 600;
			font-size: 14px;
			margin: 10px 0 5px 0;
		}
		.freshrank-setup-instructions-toggle {
			margin: 20px 0;
		}
		.freshrank-setup-instructions-toggle button {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.freshrank-setup-instructions-toggle .dashicons {
			transition: transform 0.3s ease;
		}
		.freshrank-setup-instructions-toggle .dashicons.rotated {
			transform: rotate(180deg);
		}
		@media (max-width: 782px) {
			.freshrank-setup-step {
				flex-direction: column;
				gap: 10px;
			}
			.freshrank-copy-field {
				flex-direction: column;
			}
			.freshrank-copy-field input,
			.freshrank-copy-field button {
				width: 100%;
			}
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			// Toggle setup instructions handler using event delegation
			$(document).on('click', '.freshrank-toggle-setup', function(e) {
				e.preventDefault();
				var instructions = $('#freshrank-setup-instructions');
				var button = $(this);

				if (instructions.is(':hidden')) {
					instructions.show();
					button.html('<span class="dashicons dashicons-arrow-up-alt2"></span> <?php esc_html_e( 'Hide Setup Instructions', 'freshrank-ai' ); ?>');
				} else {
					instructions.hide();
					button.html('<span class="dashicons dashicons-arrow-down-alt2"></span> <?php esc_html_e( 'Show Setup Instructions', 'freshrank-ai' ); ?>');
				}
			});

			// Copy redirect URI handler using event delegation
			$(document).on('click', '.freshrank-copy-uri', function(e) {
				e.preventDefault();
				var input = $('#freshrank-redirect-uri')[0];
				var button = $(this);

				input.select();
				input.setSelectionRange(0, 99999); // For mobile devices

				try {
					document.execCommand('copy');
					var originalText = button.html();
					button.html('<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Copied!', 'freshrank-ai' ); ?>');
					button.css({
						'background': '#00a32a',
						'color': '#fff',
						'border-color': '#00a32a'
					});

					setTimeout(function() {
						button.html(originalText);
						button.css({
							'background': '',
							'color': '',
							'border-color': ''
						});
					}, 2000);
				} catch (err) {
					alert('<?php esc_html_e( 'Failed to copy. Please copy manually.', 'freshrank-ai' ); ?>');
				}
			});

			// Refresh debug info handler using event delegation
			$(document).on('click', '.freshrank-refresh-debug', function(e) {
				e.preventDefault();
				location.reload();
			});
		});
		</script>
		<?php
	}

	/**
	 * Save GSC settings
	 */
	public function save_gsc_settings() {
		// Save prioritization setting
		$prioritization_enabled = isset( $_POST['prioritization_enabled'] ) ? 1 : 0;
		update_option( 'freshrank_prioritization_enabled', $prioritization_enabled );

		// Save date type for age calculation
		if ( isset( $_POST['gsc_date_type'] ) ) {
			$date_type = sanitize_text_field( $_POST['gsc_date_type'] );
			// Validate it's one of the allowed values
			if ( in_array( $date_type, array( 'post_date', 'post_modified' ), true ) ) {
				update_option( 'freshrank_gsc_date_type', $date_type );
			}
		}

		// Save OAuth credentials
		if ( isset( $_POST['gsc_client_id'] ) ) {
			$client_id = trim( FreshRank_Validation_Helper::sanitize_text( $_POST['gsc_client_id'], 0, 500 ) );
			update_option( 'freshrank_gsc_client_id', $client_id );
		}

		// Save OAuth client secret (encrypted)
		if ( isset( $_POST['gsc_client_secret'] ) ) {
			$client_secret = trim( FreshRank_Validation_Helper::sanitize_text( $_POST['gsc_client_secret'], 0, 500 ) );

			// Check if this is the placeholder (unchanged)
			$is_placeholder = preg_match( '/^•+$/', $client_secret );

			if ( $is_placeholder ) {
				// Don't save if it's just the placeholder - keep existing value
			} elseif ( ! empty( $client_secret ) ) {
				// New secret provided - encrypt and save
				try {
					$encrypted_secret = FreshRank_Encryption::encrypt( $client_secret );
					update_option( 'freshrank_gsc_client_secret', $encrypted_secret );
				} catch ( Exception $e ) {
					throw new Exception( 'Failed to save client secret: ' . $e->getMessage() );
				}
			} else {
				// Empty value - clear the secret
				update_option( 'freshrank_gsc_client_secret', '' );
			}
		}
	}

	/**
	 * Get GSC authentication URL
	 */
	private function get_gsc_auth_url() {
		try {
			$gsc_api = freshrank_get_gsc_api();
			return $gsc_api->get_auth_url();
		} catch ( Exception $e ) {
			return '#';
		}
	}
}
