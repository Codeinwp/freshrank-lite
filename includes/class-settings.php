<?php
/**
 * Settings Management for FreshRank AI
 * Main orchestrator - delegates to specialized modules
 * Refactored from 2,704 lines to ~500 lines
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings {

	private static $instance = null;

	// Component instances
	private $renderer;
	private $api_settings;
	private $gsc_settings;
	private $filter_settings;
	private $advanced_settings;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Initialize component modules
		$this->renderer          = FreshRank_Settings_Renderer::get_instance();
		$this->api_settings      = FreshRank_Settings_API::get_instance();
		$this->gsc_settings      = FreshRank_Settings_GSC::get_instance();
		$this->filter_settings   = FreshRank_Settings_Filters::get_instance();
		$this->advanced_settings = FreshRank_Settings_Advanced::get_instance();

		// Hook into WordPress
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_action( 'admin_post_freshrank_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_gsc_callback' ) );
	}

	/**
	 * Initialize settings registration
	 */
	public function init_settings() {
		// Boolean settings
		register_setting(
			'freshrank_settings',
			'freshrank_prioritization_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		// AI Provider settings
		register_setting(
			'freshrank_settings',
			'freshrank_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'openai',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openrouter_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openrouter_model_analysis',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openrouter_model_writing',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openrouter_custom_model_analysis',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openrouter_custom_model_writing',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_openai_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_analysis_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'gpt-5',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_content_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'gpt-5',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_enable_web_search',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_rate_limit_delay',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1000,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_debug_mode',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_gsc_client_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_gsc_client_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_gsc_date_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'post_date',
			)
		);

		// Category filter settings (matrix system)
		register_setting(
			'freshrank_settings',
			'freshrank_fix_factual_updates',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_fix_user_experience',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_fix_search_optimization',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_fix_ai_visibility',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_fix_opportunities',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		// Severity level settings (matrix system)
		register_setting(
			'freshrank_settings',
			'freshrank_severity_high',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_severity_medium',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_severity_low',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		// Custom prompt append settings
		register_setting(
			'freshrank_settings',
			'freshrank_custom_instructions_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_custom_analysis_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_custom_rewrite_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		// White-label settings (Pro only)
		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_plugin_name',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'FreshRank AI',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_logo_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_primary_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_hex_color',
				'default'           => '#0073aa',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_support_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_docs_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'freshrank_settings',
			'freshrank_whitelabel_hide_branding',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Render main settings page with tabs
	 */
	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';

		// Handle messages with whitelist validation
		$allowed_messages = array( 'settings_saved', 'gsc_connected', 'gsc_disconnected' );
		if ( isset( $_GET['message'] ) ) {
			$message = sanitize_key( $_GET['message'] );
			if ( in_array( $message, $allowed_messages, true ) ) {
				$this->display_admin_notice( $message );
			}
		}

		?>
		<div class="wrap">
			<?php $this->render_upgrade_notice(); ?>

			<h1><?php echo esc_html( freshrank_get_plugin_name() . ' ' . __( 'Settings', 'freshrank-ai' ) ); ?></h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
				<?php wp_nonce_field( 'freshrank_save_settings', 'freshrank_settings_nonce' ); ?>
				<input type="hidden" name="action" value="freshrank_save_settings">
				<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<?php $this->render_tab_content( $active_tab ); ?>

				<?php if ( $active_tab !== 'gsc' ) : ?>
					<?php submit_button(); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render upgrade notice for free version
	 */
	private function render_upgrade_notice() {
		if ( ! freshrank_is_free_version() ) {
			return;
		}

		?>
		<div class="notice notice-info" style="border-left: 4px solid #00a0d2; padding: 15px; margin: 20px 0; background: #f0f8ff;">
			<h2 style="margin-top: 0;">🎉 <?php esc_html_e( 'You\'re using FreshRank AI Lite (Free)', 'freshrank-ai' ); ?></h2>
			<p style="font-size: 14px;"><?php esc_html_e( 'Get comprehensive content analysis with AI-powered insights. Want automated content generation?', 'freshrank-ai' ); ?></p>
			<p style="margin: 15px 0;"><strong><?php esc_html_e( 'Upgrade to Pro for:', 'freshrank-ai' ); ?></strong></p>
			<ul style="margin-left: 20px; font-size: 14px;">
				<li>✨ <?php esc_html_e( 'AI-generated content updates', 'freshrank-ai' ); ?></li>
				<li>🤖 <?php esc_html_e( 'Access to 450+ AI models (Gemini, Claude, Llama)', 'freshrank-ai' ); ?></li>
				<li>⚡ <?php esc_html_e( 'Bulk content operations', 'freshrank-ai' ); ?></li>
				<li>🔄 <?php esc_html_e( 'Draft approval workflow', 'freshrank-ai' ); ?></li>
				<li>💎 <?php esc_html_e( 'Priority support', 'freshrank-ai' ); ?></li>
			</ul>
			<p style="margin-top: 15px;"><a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" class="button button-primary" style="background: #0073aa; border-color: #0073aa;"><?php esc_html_e( 'Upgrade to Pro →', 'freshrank-ai' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render Pro-only message for tabs not available in free version
	 */
	private function render_pro_only_tab_message( $feature_name ) {
		?>
		<div class="freshrank-card">
			<div class="freshrank-card-body" style="text-align: center; padding: 40px 20px;">
				<span class="dashicons dashicons-lock" style="font-size: 64px; color: #2271b1; width: 64px; height: 64px; margin-bottom: 20px;"></span>

				<h2 style="margin-bottom: 15px;">
				<?php
				echo esc_html(
					sprintf(
					// translators: %s is the feature name
						__( '%s is a Pro Feature', 'freshrank-ai' ),
						$feature_name
					)
				);
				?>
					</h2>

				<p style="font-size: 16px; color: #666; margin-bottom: 30px; max-width: 600px; margin-left: auto; margin-right: auto;">
					<?php
					echo esc_html(
						sprintf(
						// translators: %s is the feature name
							__( '%s is only available in the Pro version. Upgrade to unlock this feature and more.', 'freshrank-ai' ),
							$feature_name
						)
					);
					?>
				</p>

				<div style="background: #f0f6fc; border: 2px solid #2271b1; border-radius: 8px; padding: 25px; max-width: 500px; margin: 0 auto 30px;">
					<h3 style="margin-top: 0; margin-bottom: 15px;"><?php _e( 'Pro Features Include:', 'freshrank-ai' ); ?></h3>
					<ul style="list-style: none; padding: 0; margin: 0; text-align: left;">
						<li style="padding: 8px 0; border-bottom: 1px solid #d0e4f5;"><span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php _e( 'Google Search Console Integration', 'freshrank-ai' ); ?></li>
						<li style="padding: 8px 0; border-bottom: 1px solid #d0e4f5;"><span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php _e( 'OpenRouter - Access 450+ AI models', 'freshrank-ai' ); ?></li>
						<li style="padding: 8px 0; border-bottom: 1px solid #d0e4f5;"><span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php _e( 'Custom AI instructions', 'freshrank-ai' ); ?></li>
						<li style="padding: 8px 0; border-bottom: 1px solid #d0e4f5;"><span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php _e( 'Full category & severity control', 'freshrank-ai' ); ?></li>
						<li style="padding: 8px 0;"><span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php _e( 'White-label branding options', 'freshrank-ai' ); ?></li>
					</ul>
				</div>

				<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" class="button button-primary button-hero" target="_blank">
					<?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render navigation tabs
	 */
	private function render_tabs( $active_tab ) {
		?>
		<nav class="nav-tab-wrapper">
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=general' ); ?>"
				class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
				<?php _e( 'Settings', 'freshrank-ai' ); ?>
			</a>
			<?php if ( ! freshrank_is_free_version() ) : ?>
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=gsc' ); ?>"
				class="nav-tab <?php echo $active_tab === 'gsc' ? 'nav-tab-active' : ''; ?>">
				<?php _e( 'Google Search Console', 'freshrank-ai' ); ?>
			</a>
			<?php endif; ?>
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=notifications' ); ?>"
				class="nav-tab <?php echo $active_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
				<?php _e( 'Debug', 'freshrank-ai' ); ?>
			</a>
			<?php if ( ! freshrank_is_free_version() ) : ?>
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=whitelabel' ); ?>"
				class="nav-tab <?php echo $active_tab === 'whitelabel' ? 'nav-tab-active' : ''; ?>">
				<?php _e( 'White-Label', 'freshrank-ai' ); ?>
			</a>
			<?php endif; ?>

			<?php
			// Allow addons to add their own tabs
			$addon_tabs = apply_filters( 'freshrank_settings_tabs', array() );
			foreach ( $addon_tabs as $tab_key => $tab_label ) :
				?>
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=' . esc_attr( $tab_key ) ); ?>"
				class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render content for active tab
	 */
	private function render_tab_content( $active_tab ) {
		switch ( $active_tab ) {
			case 'gsc':
				if ( freshrank_is_free_version() ) {
					$this->render_pro_only_tab_message( 'Google Search Console' );
				} else {
					$this->gsc_settings->render_gsc_settings();
				}
				break;
			case 'notifications':
				$this->advanced_settings->render_notification_settings();
				break;
			case 'whitelabel':
				if ( ! freshrank_is_free_version() ) {
					$this->advanced_settings->render_whitelabel_settings();
				} else {
					$this->render_general_settings();
				}
				break;
			default:
				// Check if an addon handles this tab
				$addon_tabs = apply_filters( 'freshrank_settings_tabs', array() );
				if ( array_key_exists( $active_tab, $addon_tabs ) ) {
					// Allow addons to render their content
					do_action( 'freshrank_settings_content', $active_tab );
				} else {
					$this->render_general_settings();
				}
				break;
		}
	}

	/**
	 * Render general settings tab (AI config + filters + system info)
	 */
	private function render_general_settings() {
		?>
		<style>
			.freshrank-settings-grid {
				display: grid;
				grid-template-columns: repeat(3, 1fr);
				gap: 20px;
				margin-top: 20px;
			}
			@media (max-width: 1400px) {
				.freshrank-settings-grid {
					grid-template-columns: repeat(2, 1fr);
				}
			}
			@media (max-width: 900px) {
				.freshrank-settings-grid {
					grid-template-columns: 1fr;
				}
			}
			.freshrank-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.freshrank-card h3 {
				margin: 0;
				padding: 12px;
				border-bottom: 1px solid #f0f0f1;
				display: flex;
				align-items: center;
				gap: 8px;
				font-size: 14px;
			}
			.freshrank-card h3 .dashicons {
				color: #2271b1;
			}
			.freshrank-card-body {
				padding: 15px;
			}
			.freshrank-field {
				margin-bottom: 20px;
			}
			.freshrank-field:last-child {
				margin-bottom: 0;
			}
			.freshrank-field label {
				display: block;
				font-weight: 600;
				margin-bottom: 6px;
			}
			.freshrank-field .description {
				margin-top: 8px;
				color: #646970;
				font-size: 13px;
			}
			.freshrank-status-badge {
				display: inline-block;
				padding: 4px 12px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
				margin-left: 8px;
			}
			.freshrank-status-badge.connected {
				background: #00a32a;
				color: #fff;
			}
			.freshrank-status-badge.disconnected {
				background: #dba617;
				color: #fff;
			}
			.freshrank-severity-option {
				display: flex;
				align-items: flex-start;
				padding: 12px;
				margin-bottom: 8px;
				background: #f6f7f7;
				border-radius: 4px;
				transition: background 0.2s;
			}
			.freshrank-severity-option:hover {
				background: #f0f0f1;
			}
			.freshrank-severity-option input[type="checkbox"] {
				margin-right: 10px;
				margin-top: 2px;
			}
			.freshrank-severity-label {
				flex: 1;
			}
			.freshrank-severity-title {
				font-weight: 600;
				display: block;
				margin-bottom: 4px;
			}
			.freshrank-severity-desc {
				font-size: 12px;
				color: #646970;
			}

			/* FreshRank Agent Hover Popover */
			.freshrank-agent-label {
				position: relative;
			}
			.freshrank-agent-label .dashicons-info-outline:hover::after {
				content: "Managed service with no API keys. $1 per update, 3 free/month. Processing takes ~30 minutes. Coming soon.";
				position: absolute;
				left: 0;
				top: 100%;
				margin-top: 8px;
				background: #1d2327;
				color: #fff;
				padding: 12px 14px;
				border-radius: 4px;
				border: none;
				font-size: 13px;
				line-height: 1.5;
				white-space: normal;
				width: 300px;
				z-index: 1000;
				box-shadow: 0 4px 12px rgba(0,0,0,0.25);
				font-weight: normal;
				opacity: 1;
			}
			.freshrank-agent-label .dashicons-info-outline {
				transition: color 0.2s;
			}
			.freshrank-agent-label .dashicons-info-outline:hover {
				color: #2271b1 !important;
			}
		</style>

		<div class="freshrank-settings-grid">
			<!-- AI Configuration Card -->
			<div class="freshrank-card">
				<h3><span class="dashicons dashicons-admin-generic"></span> <?php _e( 'AI Configuration', 'freshrank-ai' ); ?></h3>
				<div class="freshrank-card-body">
					<?php $this->api_settings->render_ai_settings(); ?>
				</div>
			</div>

			<!-- Matrix Filters Card -->
			<?php $this->filter_settings->render_filters_card(); ?>

			<!-- System Status Card -->
			<?php $this->advanced_settings->render_system_info_card(); ?>
		</div>
		<?php
	}

	/**
	 * Save settings (routes to appropriate handler)
	 */
	public function save_settings() {
		// Prevent duplicate saves using a transient lock
		$lock_key = 'freshrank_save_lock_' . get_current_user_id();
		if ( get_transient( $lock_key ) ) {
			// Just redirect, don't save again
			$active_tab   = FreshRank_Validation_Helper::validate_enum(
				isset( $_POST['active_tab'] ) ? $_POST['active_tab'] : 'general',
				array( 'general', 'api', 'gsc', 'filters', 'notifications', 'whitelabel', 'advanced' ),
				'general'
			);
			$redirect_url = admin_url( 'admin.php?page=freshrank-settings&tab=' . $active_tab . '&message=settings_saved' );
			wp_redirect( $redirect_url );
			exit;
		}

		// Set lock for 2 seconds
		set_transient( $lock_key, true, 2 );

		// Basic security check
		if ( ! current_user_can( 'manage_freshrank' ) ) {
			delete_transient( $lock_key );
			wp_die( 'Permission denied' );
		}

		if ( ! isset( $_POST['freshrank_settings_nonce'] ) || ! wp_verify_nonce( $_POST['freshrank_settings_nonce'], 'freshrank_save_settings' ) ) {
			delete_transient( $lock_key );
			wp_die( 'Security check failed' );
		}

		// Get the active tab
		$active_tab = FreshRank_Validation_Helper::validate_enum(
			isset( $_POST['active_tab'] ) ? $_POST['active_tab'] : 'general',
			array( 'general', 'api', 'gsc', 'filters', 'notifications', 'whitelabel', 'advanced' ),
			'general'
		);

		try {
			switch ( $active_tab ) {
				case 'gsc':
					$this->gsc_settings->save_gsc_settings();
					break;
				case 'notifications':
					$this->advanced_settings->save_notification_settings();
					break;
				case 'whitelabel':
					if ( ! freshrank_is_free_version() ) {
						$this->advanced_settings->save_whitelabel_settings();
					}
					break;
				default:
					// Check if an addon handles this tab
					$addon_tabs = apply_filters( 'freshrank_settings_tabs', array() );
					if ( array_key_exists( $active_tab, $addon_tabs ) ) {
						// Allow addons to save their settings
						do_action( 'freshrank_save_settings', $active_tab );
					} else {
						// General tab includes AI settings and filters
						$this->api_settings->save_ai_settings();
						$this->filter_settings->save_filter_settings();
					}
					break;
			}

			// Clear the lock before redirect
			delete_transient( $lock_key );

			// Redirect back to settings page with success message
			$redirect_url = admin_url( 'admin.php?page=freshrank-settings&tab=' . $active_tab . '&message=settings_saved' );
			wp_redirect( $redirect_url );
			exit;
		} catch ( Exception $e ) {
			// Clear the lock before redirect
			delete_transient( $lock_key );

			// Redirect back to settings page with error message
			$redirect_url = admin_url( 'admin.php?page=freshrank-settings&tab=' . $active_tab . '&error=' . urlencode( $e->getMessage() ) );
			wp_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Handle GSC OAuth callback
	 * CSRF Protection: Added capability check to prevent unauthorized OAuth callbacks
	 */
	public function handle_gsc_callback() {
		if ( isset( $_GET['page'], $_GET['tab'], $_GET['action'] ) &&
			$_GET['page'] === 'freshrank-settings' &&
			$_GET['tab'] === 'gsc' ) {

			if ( $_GET['action'] === 'callback' && isset( $_GET['code'] ) ) {
				// CSRF Protection: Verify user has permission to manage FreshRank AI settings
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( __( 'Permission denied. Administrator access required for OAuth callback.', 'freshrank-ai' ) );
				}

				try {
					$gsc_api = freshrank_get_gsc_api();
					// State parameter validation happens inside exchange_code()
					$gsc_api->exchange_code( $_GET['code'] );

					wp_redirect(
						add_query_arg(
							array(
								'page'    => 'freshrank-settings',
								'tab'     => 'gsc',
								'message' => 'gsc_connected',
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				} catch ( Exception $e ) {
					wp_redirect(
						add_query_arg(
							array(
								'page'  => 'freshrank-settings',
								'tab'   => 'gsc',
								'error' => urlencode( $e->getMessage() ),
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
			} elseif ( $_GET['action'] === 'disconnect' && wp_verify_nonce( $_GET['_wpnonce'], 'freshrank_gsc_disconnect' ) ) {
				// CSRF Protection: Verify user has permission to disconnect GSC
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( __( 'Permission denied. Administrator access required to disconnect GSC.', 'freshrank-ai' ) );
				}

				try {
					$gsc_api = freshrank_get_gsc_api();
					$gsc_api->disconnect();

					wp_redirect(
						add_query_arg(
							array(
								'page'    => 'freshrank-settings',
								'tab'     => 'gsc',
								'message' => 'gsc_disconnected',
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				} catch ( Exception $e ) {
					wp_redirect(
						add_query_arg(
							array(
								'page'  => 'freshrank-settings',
								'tab'   => 'gsc',
								'error' => urlencode( $e->getMessage() ),
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
			}
		}
	}

	/**
	 * Display admin notices
	 */
	private function display_admin_notice( $message ) {
		$messages = array(
			'settings_saved'   => array(
				'type' => 'success',
				'text' => __( 'Settings saved successfully.', 'freshrank-ai' ),
			),
			'gsc_connected'    => array(
				'type' => 'success',
				'text' => __( 'Successfully connected to Google Search Console.', 'freshrank-ai' ),
			),
			'gsc_disconnected' => array(
				'type' => 'success',
				'text' => __( 'Disconnected from Google Search Console.', 'freshrank-ai' ),
			),
		);

		if ( isset( $messages[ $message ] ) ) {
			$notice = $messages[ $message ];
			echo '<div class="notice notice-' . $notice['type'] . ' is-dismissible"><p>' . $notice['text'] . '</p></div>';
		}

		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';
		}
	}
}
