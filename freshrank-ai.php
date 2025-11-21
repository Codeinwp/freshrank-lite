<?php
/**
 * Plugin Name: FreshRank AI Lite
 * Plugin URI: https://freshrank.ai
 * Description: Stop guessing what’s wrong with your content. FreshRank analyzes your WordPress posts to deliver actionable insights that improve user experience, engagement, and rankings.
 * Version: 1.0.0
 * Author: FreshRank AI
 * Author URI: https://freshrank.ai
 * License: GPL v2 or later
 * Text Domain: freshrank-ai
 * Tags: seo, ai, content optimization, gpt-5, generative engine optimization, geo, chatgpt, claude, perplexity, search console, freshrank
 */

// FreshRank AI Lite - Free Version
if (!defined('FRESHRANK_FREE_VERSION')) {
    define('FRESHRANK_FREE_VERSION', true);
}

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent multiple editions (Pro/Lite) from running simultaneously
$current_plugin_basename = plugin_basename( __FILE__ );
if ( defined( 'FRESHRANK_AI_ACTIVE_PLUGIN' ) && FRESHRANK_AI_ACTIVE_PLUGIN !== $current_plugin_basename ) {
	add_action(
		'admin_init',
		function () use ( $current_plugin_basename ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( $current_plugin_basename );
		}
	);

	add_action(
		'admin_notices',
		function () use ( $current_plugin_basename ) {
			$active_basename = FRESHRANK_AI_ACTIVE_PLUGIN;
			$current_label   = ( strpos( $current_plugin_basename, 'freshrank-ai-lite' ) !== false )
			? __( 'FreshRank AI Lite', 'freshrank-ai' )
			: __( 'FreshRank AI Pro', 'freshrank-ai' );
			$active_label    = ( strpos( $active_basename, 'freshrank-ai-lite' ) !== false )
			? __( 'FreshRank AI Lite', 'freshrank-ai' )
			: __( 'FreshRank AI Pro', 'freshrank-ai' );

			$message = sprintf(
				// translators: %1$s is the current plugin name, %2$s is the active plugin name
				esc_html__( '%1$s was deactivated because %2$s is already active. Only one FreshRank AI edition can run at a time.', 'freshrank-ai' ),
				esc_html( $current_label ),
				esc_html( $active_label )
			);

			echo '<div class="notice notice-warning"><p>' . $message . '</p></div>';
		}
	);

	return;
}

if ( ! defined( 'FRESHRANK_AI_ACTIVE_PLUGIN' ) ) {
	define( 'FRESHRANK_AI_ACTIVE_PLUGIN', $current_plugin_basename );
}

// Define plugin constants
define( 'FRESHRANK_VERSION', '2.0.2-dev.f7a52c2' );
define( 'FRESHRANK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRESHRANK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FRESHRANK_PLUGIN_FILE', __FILE__ );

// Free version detection - defined in build process
// Free version (freshrank-ai-lite): Analysis only, limited features
// Pro version (freshrank-ai): Full features including content generation
if ( ! defined( 'FRESHRANK_FREE_VERSION' ) ) {
	define( 'FRESHRANK_FREE_VERSION', false );
}

// Upgrade URL for free version users
if ( ! defined( 'FRESHRANK_UPGRADE_URL' ) ) {
	define( 'FRESHRANK_UPGRADE_URL', 'https://themeisle.com/plugins/fresh-rank/' );
}

// Helper function to check if this is the free version
function freshrank_is_free_version() {
	return defined( 'FRESHRANK_FREE_VERSION' ) && FRESHRANK_FREE_VERSION === true;
}

// Helper function to check if feature is available
function freshrank_feature_available( $feature ) {
	if ( ! freshrank_is_free_version() ) {
		return true; // Pro version has all features
	}

	// Free version: Only analysis features available
	$free_features = array(
		'analysis',
		'priority_scoring',
		'gsc_integration',
		'dashboard',
		'settings',
	);

	return in_array( $feature, $free_features, true );
}

/**
 * Debug logging helper function
 * Logs messages if plugin debug mode is enabled in Settings > Advanced
 * Note: Only checks plugin setting, not WP_DEBUG, to prevent accidental data leaks
 *
 * @param string $message The message to log
 */

// FreshRank AI Lite - Free Version
if (!defined('FRESHRANK_FREE_VERSION')) {
    define('FRESHRANK_FREE_VERSION', true);
}
function freshrank_debug_log( $message ) {
	if ( get_option( 'freshrank_debug_mode', false ) ) {
		error_log( 'FreshRank AI: ' . $message );
	}
}

/**
 * White-Label Helper Functions (Pro Only)
 */

// FreshRank AI Lite - Free Version
if (!defined('FRESHRANK_FREE_VERSION')) {
    define('FRESHRANK_FREE_VERSION', true);
}

// Check if white-label mode is enabled
function freshrank_whitelabel_enabled() {
	if ( freshrank_is_free_version() ) {
		return false;
	}
	return get_option( 'freshrank_whitelabel_enabled', false );
}

// Get white-labeled plugin name
function freshrank_get_plugin_name() {
	if ( freshrank_is_free_version() ) {
		return 'FreshRank AI Lite';
	}

	if ( freshrank_whitelabel_enabled() ) {
		$custom_name = get_option( 'freshrank_whitelabel_plugin_name', '' );
		if ( ! empty( $custom_name ) ) {
			return $custom_name;
		}
	}
	return 'FreshRank AI';
}

// Get white-labeled logo URL
function freshrank_get_logo_url() {
	if ( freshrank_whitelabel_enabled() ) {
		return get_option( 'freshrank_whitelabel_logo_url', '' );
	}
	return '';
}

// Get white-labeled primary color
function freshrank_get_primary_color() {
	if ( freshrank_whitelabel_enabled() ) {
		return get_option( 'freshrank_whitelabel_primary_color', '#0073aa' );
	}
	return '#0073aa';
}

// Get white-labeled support email
function freshrank_get_support_email() {
	if ( freshrank_whitelabel_enabled() ) {
		$custom_email = get_option( 'freshrank_whitelabel_support_email', '' );
		if ( ! empty( $custom_email ) ) {
			return $custom_email;
		}
	}
	return 'support@freshrank.ai';
}

// Get white-labeled documentation URL
function freshrank_get_docs_url() {
	if ( freshrank_whitelabel_enabled() ) {
		$custom_url = get_option( 'freshrank_whitelabel_docs_url', '' );
		if ( ! empty( $custom_url ) ) {
			return $custom_url;
		}
	}
	return 'https://freshrank.ai/docs';
}

// Check if branding should be hidden
function freshrank_hide_branding() {
	if ( freshrank_whitelabel_enabled() ) {
		return get_option( 'freshrank_whitelabel_hide_branding', false );
	}
	return false;
}

/**
 * Custom Instructions Helper Functions
 */

// FreshRank AI Lite - Free Version
if (!defined('FRESHRANK_FREE_VERSION')) {
    define('FRESHRANK_FREE_VERSION', true);
}

// Check if custom instructions are enabled
function freshrank_custom_instructions_enabled() {
	return (bool) get_option( 'freshrank_custom_instructions_enabled', 0 );
}

// Get custom analysis prompt instructions
function freshrank_get_custom_analysis_prompt() {
	if ( freshrank_custom_instructions_enabled() ) {
		return get_option( 'freshrank_custom_analysis_prompt', '' );
	}
	return '';
}

// Get custom rewrite prompt instructions
function freshrank_get_custom_rewrite_prompt() {
	if ( freshrank_custom_instructions_enabled() ) {
		return get_option( 'freshrank_custom_rewrite_prompt', '' );
	}
	return '';
}

/**
 * Get GSC API instance with filter support for mocking
 *
 * @return FreshRank_GSC_API The GSC API instance (real or mock)
 */

// FreshRank AI Lite - Free Version
if (!defined('FRESHRANK_FREE_VERSION')) {
    define('FRESHRANK_FREE_VERSION', true);
}
function freshrank_get_gsc_api() {
	require_once FRESHRANK_PLUGIN_DIR . 'includes/class-gsc-api.php';
	$instance = new FreshRank_GSC_API();

	/**
	 * Filter the GSC API instance
	 * This allows the mock plugin to replace the real API with a mock version
	 *
	 * @param FreshRank_GSC_API $instance The GSC API instance
	 */
	return apply_filters( 'freshrank_gsc_api_instance', $instance );
}

// Main plugin class
class FreshRank {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_composer_dependencies();

		add_action( 'init', array( $this, 'init' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		register_uninstall_hook( __FILE__, array( 'FreshRank', 'uninstall' ) );

		// Add settings link on plugins page
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

		// SSL verification override for local development
		add_filter(
			'freshrank_ai_http_request_sslverify',
			function ( $verify ) {
				if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, array( 'local', 'development' ), true ) ) {
					freshrank_debug_log( 'Disabling SSL verification for local/development environment.' );
					return false;
				}
				return $verify;
			}
		);
	}

	public function init() {
		// Load required files
		$this->load_dependencies();

		// Initialize components
		$this->init_components();

		// Load text domain for internationalization
		load_plugin_textdomain( 'freshrank-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	private function load_dependencies() {
		// Core classes (always needed)
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-encryption.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-database.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-gsc-api.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-ai-analyzer.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-analytics-scheduler.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-gsc-batch-processor.php';

		// Utilities (always needed - used by AJAX and other modules)
		require_once FRESHRANK_PLUGIN_DIR . 'includes/utilities/class-html-builder.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/utilities/class-notification-manager.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/utilities/class-ajax-response.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/utilities/class-validation-helper.php';

		// Load admin modules immediately (needed for menu registration)
		// But we'll still lazy load the heavy content updater modules
		if ( is_admin() ) {
			$this->load_admin_modules();
		}

		// Content updater modules (load on-demand for AJAX)
		// These will be loaded by the load_content_updater_modules() method when needed
	}

	/**
	 * Load composer dependencies.
	 *
	 * @return void
	 */
	private function load_composer_dependencies() {
		if ( file_exists( FRESHRANK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once FRESHRANK_PLUGIN_DIR . 'vendor/autoload.php';
		}
	}

	/**
	 * Load admin modules
	 * Dashboard and settings modules always loaded for menu registration
	 * Content updater modules lazy loaded on-demand
	 */
	public function load_admin_modules() {
		// Always load dashboard and settings modules for menu registration
		$this->load_dashboard_modules();
		$this->load_settings_modules();

		// Lazy load content updater modules only when needed
		$current_page      = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
		$is_freshrank_page = in_array( $current_page, array( 'freshrank-ai', 'freshrank-analytics', 'freshrank-settings' ), true );

		if ( wp_doing_ajax() || $is_freshrank_page ) {
			$this->load_content_updater_modules();
		}
	}

	/**
	 * Load dashboard modules
	 */
	private function load_dashboard_modules() {
		if ( ! class_exists( 'FreshRank_Dashboard_Data_Provider' ) ) {
			require_once FRESHRANK_PLUGIN_DIR . 'includes/dashboard/class-dashboard-data-provider.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/dashboard/class-dashboard-statistics.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/dashboard/class-dashboard-filters.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/dashboard/class-dashboard-actions.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/class-dashboard.php';
		}
	}

	/**
	 * Load settings modules
	 */
	private function load_settings_modules() {
		if ( ! class_exists( 'FreshRank_Settings_Renderer' ) ) {
			require_once FRESHRANK_PLUGIN_DIR . 'includes/settings/class-settings-renderer.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/settings/class-settings-api.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/settings/class-settings-gsc.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/settings/class-settings-filters.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/settings/class-settings-advanced.php';
			require_once FRESHRANK_PLUGIN_DIR . 'includes/class-settings.php';
		}
	}

	/**
	 * Load content updater modules
	 */
	private function load_content_updater_modules() {
		if ( class_exists( 'FreshRank_API_Client' ) ) {
			return;
		}

		$module_files = array(
			'includes/content-updater/class-api-client.php',
			'includes/content-updater/class-content-validator.php',
			'includes/content-updater/class-prompt-builder.php',
			'includes/content-updater/class-draft-generator.php',
			'includes/class-content-updater.php',
		);

		foreach ( $module_files as $relative_path ) {
			if ( ! file_exists( FRESHRANK_PLUGIN_DIR . $relative_path ) ) {
				return;
			}
		}

		foreach ( $module_files as $relative_path ) {
			require_once FRESHRANK_PLUGIN_DIR . $relative_path;
		}
	}

	/**
	 * Initialize admin controllers after modules are loaded
	 */
	public function init_admin_controllers() {
		// Initialize dashboard if class is loaded
		if ( class_exists( 'FreshRank_Dashboard' ) ) {
			FreshRank_Dashboard::get_instance();
		}

		// Initialize settings if class is loaded
		if ( class_exists( 'FreshRank_Settings' ) ) {
			FreshRank_Settings::get_instance();
		}
	}

	private function init_components() {
		// Initialize database
		FreshRank_Database::get_instance();

		// Initialize analytics scheduler (runs in background)
		FreshRank_Analytics_Scheduler::get_instance();

		// Initialize batch processor (registers ActionScheduler callback)
		FreshRank_GSC_Batch_Processor::get_instance();

		// Initialize admin components (modules already loaded in load_dependencies)
		if ( is_admin() ) {
			$this->init_admin_controllers();
		}

		// Initialize AJAX handlers (core)
		add_action( 'wp_ajax_freshrank_refresh_gsc_single', array( $this, 'ajax_refresh_gsc_single' ) );
		add_action( 'wp_ajax_freshrank_prioritize_articles', array( $this, 'ajax_prioritize_articles' ) );
		add_action( 'wp_ajax_freshrank_get_prioritization_progress', array( $this, 'ajax_get_prioritization_progress' ) );
		add_action( 'wp_ajax_freshrank_cancel_prioritization', array( $this, 'ajax_cancel_prioritization' ) );
		add_action( 'wp_ajax_freshrank_analyze_article', array( $this, 'ajax_analyze_article' ) );
		add_action( 'wp_ajax_freshrank_check_analysis_status', array( $this, 'ajax_check_analysis_status' ) );
		add_action( 'wp_ajax_freshrank_analyze_bulk', array( $this, 'ajax_analyze_bulk' ) );
		add_action( 'wp_ajax_freshrank_reorder_articles', array( $this, 'ajax_reorder_articles' ) );

		// Analysis item management (dismiss/restore)
		add_action( 'wp_ajax_freshrank_dismiss_item', array( $this, 'ajax_dismiss_item' ) );
		add_action( 'wp_ajax_freshrank_restore_item', array( $this, 'ajax_restore_item' ) );
		add_action( 'wp_ajax_freshrank_set_view_preference', array( $this, 'ajax_set_view_preference' ) );

		// Draft workflow (available in both Free & Pro)
		add_action( 'wp_ajax_freshrank_update_article', array( $this, 'ajax_update_article' ) );
		add_action( 'wp_ajax_freshrank_check_draft_status', array( $this, 'ajax_check_draft_status' ) );
		add_action( 'wp_ajax_freshrank_update_bulk', array( $this, 'ajax_update_bulk' ) );
		add_action( 'wp_ajax_freshrank_approve_draft', array( $this, 'ajax_approve_draft' ) );
		add_action( 'wp_ajax_freshrank_approve_revision', array( $this, 'ajax_approve_revision' ) );
		add_action( 'wp_ajax_freshrank_reject_draft', array( $this, 'ajax_reject_draft' ) );
		add_action( 'wp_ajax_freshrank_reject_revision', array( $this, 'ajax_reject_revision' ) );
		add_action( 'wp_ajax_freshrank_get_draft_diff', array( $this, 'ajax_get_draft_diff' ) );

		// FIXED: Add the missing AJAX handlers for connection testing
		add_action( 'wp_ajax_freshrank_test_gsc_connection', array( $this, 'ajax_test_gsc_connection' ) );
		add_action( 'wp_ajax_freshrank_test_openai_connection', array( $this, 'ajax_test_openai_connection' ) );
		add_action( 'wp_ajax_freshrank_diagnose_gsc', array( $this, 'ajax_diagnose_gsc' ) );

		// Dismiss API notice
		add_action( 'wp_ajax_freshrank_dismiss_api_notice', array( $this, 'ajax_dismiss_api_notice' ) );

		// Delete functionality
		add_action( 'wp_ajax_freshrank_delete_article', array( $this, 'ajax_delete_article' ) );
		add_action( 'wp_ajax_freshrank_delete_bulk', array( $this, 'ajax_delete_bulk' ) );

		// Bootstrap Pro-only features if available
		$pro_loader = FRESHRANK_PLUGIN_DIR . 'includes/pro/class-pro-features.php';
		if ( ! freshrank_is_free_version() && file_exists( $pro_loader ) ) {
			require_once $pro_loader;
			if ( class_exists( 'FreshRank_Pro_Features' ) ) {
				FreshRank_Pro_Features::bootstrap( $this );
			}
		}
	}

	/**
	 * Add settings link on plugins page
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=freshrank-settings' ) . '">' . __( 'Settings', 'freshrank-ai' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Verify AJAX request security (reusable helper)
	 * Checks nonce and user capabilities
	 *
	 * @param string $required_capability Capability required (default: manage_freshrank)
	 * @return bool True if verified, dies otherwise
	 */
	private function verify_ajax_security( $required_capability = 'manage_freshrank' ) {
		check_ajax_referer( 'freshrank_nonce', 'nonce' );

		if ( ! current_user_can( $required_capability ) ) {
			FreshRank_AJAX_Response::not_authorized();
			exit;
		}

		return true;
	}

	/**
	 * Allowed analysis categories for dismiss/restore actions.
	 *
	 * @return array
	 */
	private function get_allowed_analysis_categories() {
		$allowed = array(
			'factual_updates',
			'user_experience',
			'search_optimization',
			'ai_visibility',
			'opportunities',
			'content_quality',
			'seo_issues',
			'content_freshness',
			'ux_issues',
			'geo_analysis',
			'optimization_opportunities',
			'content_gaps',
			'technical_seo',
			'meta_data',
			'readability',
			'internal_links',
			'external_links',
			'content_structure',
			'trust_signals',
			'performance',
			'featured_snippets',
			'engagement',
			'ai_targets',
			'misc',
		);

		/**
		 * Filter the list of allowed analysis categories used for dismiss/restore actions.
		 *
		 * @param array $allowed Array of allowed category slugs.
		 */
		return apply_filters( 'freshrank_allowed_analysis_categories', $allowed );
	}

	/**
	 * Clear draft-related metadata (but keep cost tracking)
	 *
	 * @param int $post_id Post ID
	 * @param bool $keep_token_usage Whether to preserve token usage data (default: true)
	 * @param bool $include_backup Whether to include backup metadata in deletion (default: false)
	 */
	private function clear_draft_metadata( $post_id, $keep_token_usage = true, $include_backup = false ) {
		$meta_keys = array(
			'_freshrank_last_ai_update',
			'_freshrank_ai_revision_id',
			'_freshrank_draft_post_id',
			'_freshrank_has_revision_draft',
			'_freshrank_update_severity',
			'_freshrank_severity_summary',
			'_freshrank_seo_improvements',
			'_freshrank_content_updates',
			'_freshrank_update_summary',
		);

		// Add backup keys if requested
		if ( $include_backup ) {
			$meta_keys[] = '_freshrank_original_content_backup';
			$meta_keys[] = '_freshrank_original_title_backup';
			$meta_keys[] = '_freshrank_original_excerpt_backup';
		}

		// Add token_usage to deletion if requested
		if ( ! $keep_token_usage ) {
			$meta_keys[] = '_freshrank_token_usage';
		}

		foreach ( $meta_keys as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	public function activate() {
		// Load required classes for activation
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-encryption.php';
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-database.php';

		// Create database tables
		FreshRank_Database::create_tables();

		// Set default options
		$default_options = array(
			'prioritization_enabled' => false,
			'gsc_authenticated'      => false,
			'openai_api_key'         => '',
			'openai_model'           => 'gpt-4.1-mini',
			'email_notifications'    => true,
			'debug_mode'             => false,
			'rate_limit_delay'       => 1000, // milliseconds
		);

		foreach ( $default_options as $key => $value ) {
			if ( ! get_option( 'freshrank_' . $key ) ) {
				add_option( 'freshrank_' . $key, $value );
			}
		}

		// Create capability
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_freshrank' );
		}
	}

	public function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'freshrank_cleanup_old_data' );
		FreshRank_Analytics_Scheduler::clear_scheduled_events();
	}

	public static function uninstall() {
		// Load database class for uninstall
		require_once FRESHRANK_PLUGIN_DIR . 'includes/class-database.php';

		// Remove database tables
		FreshRank_Database::drop_tables();

		// Remove ALL plugin options (including any that might have been added)
		global $wpdb;
		$like = $wpdb->esc_like( 'freshrank_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		// Remove ALL plugin-related post meta from all posts
		$like_meta = $wpdb->esc_like( '_freshrank_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like_meta ) );

		// Remove any scheduled cron jobs
		wp_clear_scheduled_hook( 'freshrank_cleanup_old_data' );
		wp_clear_scheduled_hook( 'freshrank_daily_maintenance' );
		wp_clear_scheduled_hook( 'freshrank_weekly_cleanup' );

		// Remove capabilities from ALL roles (not just administrator)
		global $wp_roles;
		if ( $wp_roles ) {
			foreach ( $wp_roles->roles as $role_name => $role_info ) {
				$role = get_role( $role_name );
				if ( $role && $role->has_cap( 'manage_freshrank' ) ) {
					$role->remove_cap( 'manage_freshrank' );
				}
			}
		}

		// Delete any draft posts that were created by the plugin
		$draft_posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'meta_query'  => array(
					array(
						'key'     => '_freshrank_created_date',
						'compare' => 'EXISTS',
					),
				),
				'numberposts' => -1,
			)
		);

		foreach ( $draft_posts as $draft ) {
			wp_delete_post( $draft->ID, true ); // Force delete, skip trash
		}

		// Clean up any orphaned data in wp_posts table (posts with freshrank meta that no longer exist)
		$like_meta_orphan = $wpdb->esc_like( '_freshrank_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"
            DELETE p FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key LIKE %s
            AND p.post_type = 'post'
            AND p.post_status = 'auto-draft'
        ",
				$like_meta_orphan
			)
		);

		// Remove any transients that might have been set
		$like_transient         = $wpdb->esc_like( '_transient_freshrank_' ) . '%';
		$like_transient_timeout = $wpdb->esc_like( '_transient_timeout_freshrank_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like_transient,
				$like_transient_timeout
			)
		);

		// Clear any object cache entries
		wp_cache_flush();

		// Remove any user meta related to the plugin
		$like_usermeta = $wpdb->esc_like( 'freshrank_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like_usermeta ) );

		// Clean up any comments meta (in case we ever stored data there)
		$like_commentmeta = $wpdb->esc_like( 'freshrank_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s", $like_commentmeta ) );

		// Remove any term meta (in case we ever used taxonomies)
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->termmeta}'" ) ) {
			$like_termmeta = $wpdb->esc_like( 'freshrank_' ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $like_termmeta ) );
		}

		freshrank_debug_log( 'Plugin completely uninstalled and all data removed' );
	}

	// AJAX Handlers
	/**
	 * Start batched prioritization
	 */
	public function ajax_prioritize_articles() {
		$this->verify_ajax_security();

		try {
			$batch_processor = FreshRank_GSC_Batch_Processor::get_instance();
			$job_info        = $batch_processor->start_prioritization();

			FreshRank_AJAX_Response::success(
				$job_info,
				__( 'Prioritization started successfully', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_refresh_gsc_single() {
		$this->verify_ajax_security();

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			$gsc_api = freshrank_get_gsc_api();
			$url     = get_permalink( $post_id );

			// Calculate date ranges (current and previous 28-day periods)
			$end_date   = date( 'Y-m-d', strtotime( '-3 days' ) );
			$start_date = date( 'Y-m-d', strtotime( '-31 days' ) );

			// Force refresh (bypass cache)
			$current_analytics = $gsc_api->get_url_analytics( $url, $start_date, $end_date, true );

			// Store in database
			$database = FreshRank_Database::get_instance();
			$database->save_gsc_data( $post_id, $current_analytics, array() );

			FreshRank_AJAX_Response::success(
				array( 'post_id' => $post_id ),
				__( 'GSC data refreshed successfully', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Get prioritization progress
	 */
	public function ajax_get_prioritization_progress() {
		$this->verify_ajax_security();

		try {
			$batch_processor = FreshRank_GSC_Batch_Processor::get_instance();
			$progress        = $batch_processor->get_progress();

			if ( $progress ) {
				FreshRank_AJAX_Response::success( $progress );
			} else {
				FreshRank_AJAX_Response::success(
					array(
						'status'  => 'idle',
						'message' => __( 'No prioritization in progress', 'freshrank-ai' ),
					)
				);
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Cancel in-progress prioritization
	 */
	public function ajax_cancel_prioritization() {
		$this->verify_ajax_security();

		try {
			$batch_processor = FreshRank_GSC_Batch_Processor::get_instance();
			$batch_processor->cancel_prioritization();

			FreshRank_AJAX_Response::success(
				array(),
				__( 'Prioritization cancelled successfully', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_analyze_article() {
		$this->verify_ajax_security();

		// Simplified single-phase approach
		// The analyzer will handle status updates internally
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // 5 minutes max
		}

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			freshrank_debug_log( 'Post ID: ' . $post_id );

			$analyzer = new FreshRank_AI_Analyzer();
			$analysis = $analyzer->analyze_article( $post_id );

			freshrank_debug_log( 'Analysis completed successfully' );

			FreshRank_AJAX_Response::success(
				array( 'analysis' => $analysis ),
				__( 'Article analyzed successfully', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			freshrank_debug_log( 'Analysis failed - ' . $e->getMessage() );
			freshrank_debug_log( 'Stack trace: ' . $e->getTraceAsString() );

			FreshRank_AJAX_Response::error( $e->getMessage() );
		} catch ( Throwable $e ) {
			freshrank_debug_log( 'FATAL ERROR - ' . $e->getMessage() );
			freshrank_debug_log( 'Stack trace: ' . $e->getTraceAsString() );

			FreshRank_AJAX_Response::error( 'A critical error occurred: ' . $e->getMessage() );
		}
	}

	/**
	 * Check analysis status (for polling during long-running analyses)
	 */
	public function ajax_check_analysis_status() {
		$this->verify_ajax_security();

		try {
			$post_id  = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			$db       = FreshRank_Database::get_instance();
			$analysis = $db->get_analysis( $post_id );

			// Check transient to see if actively processing
			$is_processing = get_transient( 'freshrank_analyzing_' . $post_id );

			if ( ! $analysis ) {
				FreshRank_AJAX_Response::success( array( 'status' => 'pending' ), 'No analysis data found' );
				return;
			}

			if ( $analysis->status === 'completed' ) {
				FreshRank_AJAX_Response::success(
					array(
						'status'       => 'completed',
						'issues_count' => $analysis->issues_count,
					),
					'Analysis complete'
				);
			} elseif ( $analysis->status === 'error' ) {
				FreshRank_AJAX_Response::success(
					array(
						'status' => 'error',
						'error'  => $analysis->error_message ?? 'Unknown error',
					),
					'Analysis failed'
				);
			} elseif ( $analysis->status === 'analyzing' ) {
				// Check if actually processing or stale
				if ( $is_processing !== false ) {
					FreshRank_AJAX_Response::success( array( 'status' => 'analyzing' ), 'Analysis in progress' );
				} else {
					// Stale analysis - reset to pending
					global $wpdb;
					$analysis_table = $wpdb->prefix . 'freshrank_analysis';
					$wpdb->update(
						$analysis_table,
						array( 'status' => 'pending' ),
						array( 'post_id' => $post_id ),
						array( '%s' ),
						array( '%d' )
					);

					FreshRank_AJAX_Response::success( array( 'status' => 'stale' ), 'Analysis was interrupted' );
				}
			} else {
				FreshRank_AJAX_Response::success( array( 'status' => $analysis->status ?? 'pending' ), 'Status check complete' );
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Check draft creation status (for polling during long-running draft creation)
	 */
	public function ajax_check_draft_status() {
		$this->verify_ajax_security();

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			$db      = FreshRank_Database::get_instance();

			// Check if draft exists for this post
			global $wpdb;

			$draft = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d ORDER BY created_at DESC LIMIT %d",
					$post_id,
					1
				)
			);

			// Check transient to see if actively processing
			$is_processing = get_transient( 'freshrank_creating_draft_' . $post_id );

			if ( $draft && get_post( $draft->draft_post_id ) ) {
				FreshRank_AJAX_Response::success(
					array(
						'has_draft' => true,
						'status'    => $draft->status,
						'draft_id'  => $draft->draft_post_id,
					),
					'Draft exists'
				);
			} elseif ( $is_processing !== false ) {
				FreshRank_AJAX_Response::success(
					array(
						'has_draft' => false,
						'status'    => 'creating',
					),
					'Draft creation in progress'
				);
			} else {
				FreshRank_AJAX_Response::success(
					array(
						'has_draft' => false,
						'status'    => 'none',
					),
					'No draft found'
				);
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_analyze_bulk() {
		$this->verify_ajax_security();

		$post_ids = array_map( 'intval', $_POST['post_ids'] );

		try {
			$analyzer = new FreshRank_AI_Analyzer();
			$results  = $analyzer->analyze_bulk( $post_ids );

			FreshRank_AJAX_Response::success( array( 'results' => $results ), __( 'Bulk analysis completed', 'freshrank-ai' ) );
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler: Dismiss an analysis item
	 */
	public function ajax_dismiss_item() {
		$this->verify_ajax_security();

		try {
			$post_id  = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			$category = FreshRank_Validation_Helper::sanitize_text( $_POST['category'] );
			$index    = FreshRank_Validation_Helper::validate_int_range( $_POST['index'], 0, 999999 );

			if ( ! in_array( $category, $this->get_allowed_analysis_categories(), true ) ) {
				FreshRank_AJAX_Response::error( __( 'Invalid analysis category', 'freshrank-ai' ) );
				return;
			}
			$database = FreshRank_Database::get_instance();
			$result   = $database->dismiss_analysis_item( $post_id, $category, $index );

			if ( $result ) {
				FreshRank_AJAX_Response::success( array(), __( 'Item dismissed successfully', 'freshrank-ai' ) );
			} else {
				FreshRank_AJAX_Response::error( __( 'Failed to dismiss item', 'freshrank-ai' ) );
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler: Restore a dismissed analysis item
	 */
	public function ajax_restore_item() {
		$this->verify_ajax_security();

		try {
			$post_id  = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			$category = FreshRank_Validation_Helper::sanitize_text( $_POST['category'] );
			$index    = FreshRank_Validation_Helper::validate_int_range( $_POST['index'], 0, 999999 );

			if ( ! in_array( $category, $this->get_allowed_analysis_categories(), true ) ) {
				FreshRank_AJAX_Response::error( __( 'Invalid analysis category', 'freshrank-ai' ) );
				return;
			}
			$database = FreshRank_Database::get_instance();
			$result   = $database->restore_analysis_item( $post_id, $category, $index );

			if ( $result ) {
				FreshRank_AJAX_Response::success( array(), __( 'Item restored successfully', 'freshrank-ai' ) );
			} else {
				FreshRank_AJAX_Response::error( __( 'Failed to restore item', 'freshrank-ai' ) );
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler: Set analysis view preference (actionable/all/dismissed)
	 */
	public function ajax_set_view_preference() {
		$this->verify_ajax_security();

		try {
			$view    = FreshRank_Validation_Helper::validate_enum(
				$_POST['view'],
				array( 'actionable', 'all', 'dismissed' ),
				'actionable',
				true
			);
			$user_id = get_current_user_id();
			update_user_meta( $user_id, 'freshrank_analysis_view_preference', $view );

			FreshRank_AJAX_Response::success(
				array( 'view' => $view ),
				__( 'View preference saved', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_update_article() {
		$this->verify_ajax_security();

		// Allow process to continue even if user disconnects
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // 5 minutes max
		}

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );

			freshrank_debug_log( '=== Draft Creation Start ===' );
			freshrank_debug_log( 'Post ID: ' . $post_id );

			$database = FreshRank_Database::get_instance();

			$lock_key = 'freshrank_creating_draft_' . $post_id;
			// Check if draft already exists
			global $wpdb;
			$existing_draft = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d ORDER BY created_at DESC LIMIT %d",
					$post_id,
					1
				)
			);

			if ( $existing_draft && get_post( $existing_draft->draft_post_id ) ) {
				freshrank_debug_log( 'Draft already exists for post ID: ' . $post_id . ', Draft ID: ' . $existing_draft->draft_post_id );

				// Clear any stale lock since we're not proceeding
				delete_transient( $lock_key );

				FreshRank_AJAX_Response::error( __( 'A draft already exists for this article. Please approve or reject it first.', 'freshrank-ai' ) );
				return;
			}

			// ATOMIC RACE CONDITION PROTECTION using WordPress lock
			$lock_acquired = false;
			$max_attempts  = 3;

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				// Try to acquire lock - returns false if already locked
				$existing_lock = get_transient( $lock_key );

				if ( $existing_lock === false ) {
					// Lock is free, try to acquire it atomically
					$lock_acquired = set_transient( $lock_key, time(), 1200 ); // 20 minute expiry (to cover 15min API timeout + buffer)

					if ( $lock_acquired ) {
						freshrank_debug_log( "Lock acquired on attempt $attempt" );
						break;
					}
				} else {
					// Check if lock is stale (older than 20 minutes)
					$lock_age = time() - $existing_lock;
					if ( $lock_age > 1200 ) {
						// Stale lock, clear it and retry
						delete_transient( $lock_key );
						freshrank_debug_log( "Cleared stale lock (age: {$lock_age}s)" );
						continue;
					}
				}

				if ( $attempt < $max_attempts ) {
					usleep( 500000 ); // Wait 0.5 seconds before retry
				}
			}

			if ( ! $lock_acquired ) {
				throw new Exception( __( 'Draft creation already in progress for this article. Please wait a moment and try again.', 'freshrank-ai' ) );
			}

			// Set draft status to 'creating' after acquiring lock
			$database->update_draft_status( $post_id, 'creating' );

			// Execute draft creation
			$updater  = new FreshRank_Content_Updater();
			$draft_id = $updater->create_updated_draft( $post_id );

			if ( $draft_id ) {
				// Clear lock on success
				delete_transient( $lock_key );

				freshrank_debug_log( 'Draft created successfully. Draft ID: ' . $draft_id );
				freshrank_debug_log( '=== Draft Creation Complete ===' );

				FreshRank_AJAX_Response::success(
					array(
						'draft_id' => $draft_id,
					),
					__( 'Draft created successfully!', 'freshrank-ai' )
				);
			} else {
				// Clear lock and set error status
				delete_transient( $lock_key );
				$database->update_draft_status( $post_id, 'error' );

				freshrank_debug_log( 'Draft creation failed - no draft ID returned' );

				FreshRank_AJAX_Response::error( __( 'Failed to create draft - no draft ID returned', 'freshrank-ai' ) );
			}
		} catch ( Exception $e ) {
			// Always clear lock on error
			if ( isset( $lock_key ) ) {
				delete_transient( $lock_key );
			}
			$database->update_draft_status( $post_id, 'error' );

			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_update_bulk() {
		$this->verify_ajax_security();

		$post_ids = array_map( 'intval', $_POST['post_ids'] );

		try {
			$updater = new FreshRank_Content_Updater();
			$results = $updater->create_bulk_drafts( $post_ids );

			FreshRank_AJAX_Response::success( array( 'results' => $results ), __( 'Bulk updates completed', 'freshrank-ai' ) );
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for approving drafts - Updated to ensure complete reset
	 */
	public function ajax_approve_draft() {
		$this->verify_ajax_security();

		try {
			$draft_id    = FreshRank_Validation_Helper::sanitize_post_id( $_POST['draft_id'] );
			$original_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['original_id'] );
			$updater     = new FreshRank_Content_Updater();
			$updater->approve_draft( $draft_id, $original_id );

			// Clear any WordPress object cache for this post to ensure fresh data
			clean_post_cache( $original_id );
			wp_cache_delete( $original_id, 'posts' );
			wp_cache_delete( $original_id, 'post_meta' );

			// Also clear any plugin-specific cache
			wp_cache_delete( 'freshrank_article_' . $original_id );
			wp_cache_delete( 'freshrank_analysis_' . $original_id );

			FreshRank_AJAX_Response::success(
				array(
					'reset_complete' => true,
					'original_id'    => $original_id,
				),
				__( 'Draft approved and published. Article has been reset for fresh analysis.', 'freshrank-ai' )
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	public function ajax_reject_draft() {
		// Accept either post_id or draft_id, convert to post_id, then call ajax_reject_revision
		// Note: Using OR capability check - edit_posts is fallback for editors
		$this->verify_ajax_security( 'edit_posts' );

		// Accept either post_id or draft_id (for backwards compatibility)
		if ( isset( $_POST['post_id'] ) ) {
			$post_id = intval( $_POST['post_id'] );
		} elseif ( isset( $_POST['draft_id'] ) ) {
			// Get the original post ID from the draft
			$draft_id = intval( $_POST['draft_id'] );
			$post_id  = intval( get_post_meta( $draft_id, '_freshrank_original_post_id', true ) );

			if ( ! $post_id ) {
				// If meta doesn't exist, check the drafts table
				global $wpdb;
				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT original_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE draft_post_id = %d",
						$draft_id
					)
				);
			}

			if ( ! $post_id ) {
				FreshRank_AJAX_Response::error( 'Could not find original post for this draft.' );
				return;
			}
		} else {
			FreshRank_AJAX_Response::error( 'Missing post_id or draft_id parameter.' );
			return;
		}

		// Set post_id in $_POST so ajax_reject_revision can use it
		$_POST['post_id'] = $post_id;

		// Forward to the unified reject revision handler
		$this->ajax_reject_revision();
	}

	/**
	 * Approve revision (restore the AI revision to make it live)
	 * UPDATED: Gets content from draft post (user may have edited it)
	 */
	public function ajax_approve_revision() {
		// Note: Using OR capability check - edit_posts is fallback for editors
		$this->verify_ajax_security( 'edit_posts' );

		$post_id = intval( $_POST['post_id'] );

		global $wpdb;

		try {

			// Get the draft post ID that was created
			$draft_post_id = get_post_meta( $post_id, '_freshrank_draft_post_id', true );

			if ( ! $draft_post_id || ! get_post( $draft_post_id ) ) {
				throw new Exception( 'No draft post found to approve.' );
			}

			// Get the draft post content (user may have edited it)
			$draft_post = get_post( $draft_post_id );

			if ( ! $draft_post ) {
				throw new Exception( 'Draft post no longer exists.' );
			}

			// Update the original post with the draft content
			$update_data = array(
				'ID'                => $post_id,
				'post_title'        => str_replace( '[FreshRank Draft] ', '', $draft_post->post_title ),
				'post_content'      => $draft_post->post_content,
				'post_excerpt'      => $draft_post->post_excerpt,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			);

			$result = wp_update_post( $update_data, true );

			if ( is_wp_error( $result ) ) {
				throw new Exception( 'Failed to update original post: ' . $result->get_error_message() );
			}

			freshrank_debug_log( 'Original post updated with draft content' );

			// Delete the temporary revisions we created
			$all_revisions      = wp_get_post_revisions( $post_id, array( 'posts_per_page' => -1 ) );
			$draft_created_time = get_post_meta( $post_id, '_freshrank_last_ai_update', true );

			if ( $draft_created_time ) {
				$draft_timestamp = strtotime( $draft_created_time );

				foreach ( $all_revisions as $revision ) {
					$revision_time = strtotime( $revision->post_modified );
					// Delete revisions created within 5 seconds of draft creation (the temporary ones)
					if ( abs( $revision_time - $draft_timestamp ) <= 5 ) {
						wp_delete_post_revision( $revision->ID );
					}
				}
			}

			// Delete the draft post
			wp_delete_post( $draft_post_id, true );

			// Remove draft relationship from database
			$wpdb->delete(
				$wpdb->prefix . 'freshrank_drafts',
				array( 'original_post_id' => $post_id ),
				array( '%d' )
			);

			// Store approval timestamp for analytics tracking
			update_post_meta( $post_id, '_freshrank_update_approved_date', current_time( 'mysql' ) );
			update_post_meta( $post_id, '_freshrank_content_updated', true );

			// Clear draft-related metadata (but keep token_usage for cost tracking)
			$this->clear_draft_metadata( $post_id, true, false );

			// Clear analysis data so post can be re-analyzed with new content
			$analysis_table = $wpdb->prefix . 'freshrank_analysis';
			$wpdb->delete( $analysis_table, array( 'post_id' => $post_id ), array( '%d' ) );

			// Set status to 'updated' instead of 'pending'
			$database = FreshRank_Database::get_instance();
			$result   = $database->update_draft_status( $post_id, 'updated' );
			freshrank_debug_log( 'update_draft_status returned: ' . var_export( $result, true ) );

			// Verify it was saved
			$verify_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT draft_status FROM {$wpdb->prefix}freshrank_articles WHERE post_id = %d",
					$post_id
				)
			);

			FreshRank_AJAX_Response::success( array(), __( 'AI update approved. Content is now live!', 'freshrank-ai' ) );

		} catch ( Exception $e ) {
			freshrank_debug_log( 'Exception in ajax_approve_revision: ' . $e->getMessage() );
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Reject revision-based update (delete the AI draft revisions)
	 */
	public function ajax_reject_revision() {
		// Note: Using OR capability check - edit_posts is fallback for editors
		$this->verify_ajax_security( 'edit_posts' );

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );

			global $wpdb;

			// Get all revisions (newest first)
			$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => -1 ) );

			// If no revisions exist, this is an old draft - just clear metadata
			if ( count( $revisions ) < 1 ) {
				freshrank_debug_log( 'No revisions found. Clearing old draft metadata.' );

				// Check if there's an old draft post and delete it
				$old_draft = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d",
						$post_id
					)
				);

				if ( $old_draft && $old_draft->draft_post_id ) {
					// Delete the old draft post
					wp_delete_post( $old_draft->draft_post_id, true );

					// Remove the draft relationship
					$wpdb->delete(
						$wpdb->prefix . 'freshrank_drafts',
						array( 'original_post_id' => $post_id ),
						array( '%d' )
					);
				}

				// Clear ALL AI update and draft metadata (including backups)
				$this->clear_draft_metadata( $post_id, true, true );

				// Reset analysis status to 'completed' not 'pending'
				$wpdb->update(
					$wpdb->prefix . 'freshrank_analysis',
					array( 'status' => 'completed' ),
					array( 'post_id' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);

				FreshRank_AJAX_Response::success( array(), __( 'Old draft cleared. Create a new draft to use the revision-based system.', 'freshrank-ai' ) );
				return;
			}

			$revisions_array = array_values( $revisions );

			// Find the revision before the AI update
			// The AI update meta tells us when it was made
			$ai_update_time = get_post_meta( $post_id, '_freshrank_last_ai_update', true );

			if ( ! $ai_update_time ) {
				FreshRank_AJAX_Response::error( __( 'No AI update found to reject.', 'freshrank-ai' ) );
				return;
			}

			$ai_update_timestamp = strtotime( $ai_update_time );
			$previous_revision   = null;

			// Find the revision just before the AI update
			foreach ( $revisions_array as $revision ) {
				$revision_time = strtotime( $revision->post_modified );
				if ( $revision_time < $ai_update_timestamp ) {
					$previous_revision = $revision;
					break;
				}
			}

			// If no revision found before AI update, it's an old draft - just clear metadata
			if ( ! $previous_revision ) {
				freshrank_debug_log( 'No revision found before AI update timestamp. Clearing metadata only (old draft).' );

				// Check if there's an old draft post and delete it
				$old_draft = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d",
						$post_id
					)
				);

				if ( $old_draft && $old_draft->draft_post_id ) {
					// Delete the old draft post
					wp_delete_post( $old_draft->draft_post_id, true );

					// Remove the draft relationship
					$wpdb->delete(
						$wpdb->prefix . 'freshrank_drafts',
						array( 'original_post_id' => $post_id ),
						array( '%d' )
					);
				}

				// Clear ALL AI update and draft metadata (including backups)
				$this->clear_draft_metadata( $post_id, true, true );

				// Reset analysis status to 'completed' not 'pending'
				$wpdb->update(
					$wpdb->prefix . 'freshrank_analysis',
					array( 'status' => 'completed' ),
					array( 'post_id' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);

				FreshRank_AJAX_Response::success( array(), __( 'Old draft cleared. Please create a new draft to see revision-based changes.', 'freshrank-ai' ) );
				return;
			}

			// Restore the previous revision
			$restored = wp_restore_post_revision( $previous_revision->ID );

			if ( ! $restored ) {
				FreshRank_AJAX_Response::error( __( 'Failed to restore previous version.', 'freshrank-ai' ) );
				return;
			}

			// Check if there's an old draft post and delete it
			$old_draft = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d",
					$post_id
				)
			);

			if ( $old_draft && $old_draft->draft_post_id ) {
				// Delete the old draft post
				wp_delete_post( $old_draft->draft_post_id, true );

				// Remove the draft relationship
				$wpdb->delete(
					$wpdb->prefix . 'freshrank_drafts',
					array( 'original_post_id' => $post_id ),
					array( '%d' )
				);
			}

			// Clear ALL AI update and draft metadata (including backups)
			$this->clear_draft_metadata( $post_id, true, true );

			// Reset draft status (keep analysis) and set to 'completed' not 'pending'
			$database = FreshRank_Database::get_instance();
			$wpdb->update(
				$wpdb->prefix . 'freshrank_analysis',
				array( 'status' => 'completed' ),
				array( 'post_id' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);

			FreshRank_AJAX_Response::success( array(), __( 'AI update rejected. Previous version restored.', 'freshrank-ai' ) );
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Get draft diff for comparison view
	 */
	public function ajax_get_draft_diff() {
		$this->verify_ajax_security();

		try {
			$draft_id    = FreshRank_Validation_Helper::sanitize_post_id( $_POST['draft_id'] );
			$original_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['original_id'] );
			$original    = get_post( $original_id );
			$draft       = get_post( $draft_id );

			if ( ! $original || ! $draft ) {
				FreshRank_AJAX_Response::error( __( 'Original or draft post not found', 'freshrank-ai' ) );
				return;
			}

			// Get content with basic formatting
			$original_content = $this->format_content_for_diff( $original->post_content );
			$draft_content    = $this->format_content_for_diff( $draft->post_content );

			FreshRank_AJAX_Response::success(
				array(
					'title'         => $original->post_title,
					'original_html' => $original_content,
					'draft_html'    => $draft_content,
				)
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * Format content for diff view with paragraphs and line breaks
	 */
	private function format_content_for_diff( $content ) {
		// Apply WordPress content filters
		$content = apply_filters( 'the_content', $content );

		// Wrap in a container for better styling
		return '<div class="freshrank-diff-formatted">' . $content . '</div>';
	}

	public function ajax_reorder_articles() {
		$this->verify_ajax_security();

		$ordered_ids = array_map( 'intval', $_POST['ordered_ids'] );

		try {
			$database = FreshRank_Database::get_instance();
			$database->save_article_order( $ordered_ids );

			FreshRank_AJAX_Response::success( array(), __( 'Article order saved', 'freshrank-ai' ) );
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * FIXED: AJAX handler for testing GSC connection
	 */
	public function ajax_test_gsc_connection() {
		$this->verify_ajax_security();

		try {
			$gsc_api     = freshrank_get_gsc_api();
			$test_result = $gsc_api->test_connection();

			if ( $test_result['success'] ) {
				$message = $test_result['message'];

				// Add test results if available
				if ( ! empty( $test_result['test_results'] ) ) {
					$message .= "\n\nTest Results (last 30 days):";
					foreach ( $test_result['test_results'] as $result ) {
						$message .= "\n• " . $result['title'] . ': ' . $result['impressions'] . ' impressions, ' . $result['clicks'] . ' clicks';
					}
				}

				if ( ! empty( $test_result['matching_property'] ) ) {
					$message .= "\n\nUsing GSC property: " . $test_result['matching_property'];
				}

				FreshRank_AJAX_Response::success(
					array(
						'test_results' => $test_result['test_results'] ?? array(),
					),
					$message
				);
			} else {
				FreshRank_AJAX_Response::error(
					$test_result['message']
				);
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( 'Connection test failed: ' . $e->getMessage() );
		}
	}

	/**
	 * FIXED: AJAX handler for testing OpenAI connection
	 */
	public function ajax_test_openai_connection() {
		$this->verify_ajax_security();

		try {
			$analyzer    = new FreshRank_AI_Analyzer();
			$test_result = $analyzer->test_api_connection();

			if ( $test_result['success'] ) {
				FreshRank_AJAX_Response::success(
					array(),
					$test_result['message'] . ' (Model: ' . ( $test_result['model'] ?? 'Unknown' ) . ')'
				);
			} else {
				FreshRank_AJAX_Response::error( $test_result['message'] );
			}
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( 'Connection test failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for GSC diagnostics
	 */
	public function ajax_diagnose_gsc() {
		$this->verify_ajax_security();

		try {
			$gsc_api     = freshrank_get_gsc_api();
			$diagnostics = $gsc_api->diagnose_connection();

			FreshRank_AJAX_Response::success( array( 'diagnostics' => $diagnostics ) );
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( 'Diagnostics failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler to get OpenRouter models list
	 */
	public function ajax_get_openrouter_models() {
		$this->verify_ajax_security();

		$force_refresh = false;
		if ( isset( $_POST['force_refresh'] ) ) {
			$force_refresh_value = sanitize_text_field( wp_unslash( $_POST['force_refresh'] ) );
			$force_refresh       = in_array( strtolower( $force_refresh_value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		freshrank_debug_log( 'ajax_get_openrouter_models called' . ( $force_refresh ? ' (force refresh)' : '' ) );

		if ( $force_refresh ) {
			delete_transient( 'freshrank_openrouter_models' );
		} else {
			$cached_models = get_transient( 'freshrank_openrouter_models' );
			if ( $cached_models !== false ) {
				if ( $this->openrouter_models_have_popularity_metadata( $cached_models ) ) {
					freshrank_debug_log( 'Returning ' . count( $cached_models ) . ' cached models' );
					FreshRank_AJAX_Response::success(
						array(
							'models' => $cached_models,
							'cached' => true,
						)
					);
					return;
				}

				freshrank_debug_log( 'Cached models missing popularity metadata. Refreshing from API.' );
			}
		}

		freshrank_debug_log( 'No cached models, fetching from API' );

		try {
			$api_key = get_option( 'freshrank_openrouter_api_key', '' );
			if ( empty( $api_key ) ) {
				freshrank_debug_log( 'No API key configured' );
				FreshRank_AJAX_Response::error( 'OpenRouter API key not configured' );
				return;
			}

			$decrypted_key = FreshRank_Encryption::decrypt( $api_key );
			freshrank_debug_log( 'API key decrypted successfully' );

			$response = wp_remote_get(
				'https://openrouter.ai/api/v1/models',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $decrypted_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				FreshRank_AJAX_Response::error( 'Failed to fetch models: ' . $response->get_error_message() );
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			freshrank_debug_log( 'Models API response code: ' . $response_code );

			if ( $response_code !== 200 ) {
				freshrank_debug_log( 'Models API error: HTTP ' . $response_code );
				FreshRank_AJAX_Response::error( 'OpenRouter API error (HTTP ' . $response_code . ')' );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
				freshrank_debug_log( 'No models in response data' );
				FreshRank_AJAX_Response::error( 'No models returned from OpenRouter' );
				return;
			}

			freshrank_debug_log( 'Received ' . count( $data['data'] ) . ' models from API' );

			$popularity_map = $this->get_openrouter_popularity_map( $decrypted_key );
			freshrank_debug_log( 'Popularity map loaded for ' . count( $popularity_map ) . ' models' );

			$models = array();
			foreach ( $data['data'] as $model ) {
				if ( ! is_array( $model ) ) {
					continue;
				}

				$model_id = isset( $model['id'] ) ? sanitize_text_field( $model['id'] ) : '';
				if ( empty( $model_id ) ) {
					continue;
				}

				$model_name      = isset( $model['name'] ) ? sanitize_text_field( $model['name'] ) : $model_id;
				$popularity_meta = isset( $popularity_map[ $model_id ] ) ? $popularity_map[ $model_id ] : array();
				$pricing_details = $this->parse_openrouter_pricing( isset( $model['pricing'] ) ? $model['pricing'] : array() );

				$models[] = array(
					'id'                        => $model_id,
					'name'                      => $model_name,
					'context_length'            => isset( $model['context_length'] ) ? intval( $model['context_length'] ) : 0,
					'pricing_prompt_per_1k'     => $pricing_details['prompt_per_1k'],
					'pricing_completion_per_1k' => $pricing_details['completion_per_1k'],
					'pricing_currency'          => $pricing_details['currency'],
					'pricing_currency_symbol'   => $pricing_details['currency_symbol'],
					'pricing_has_data'          => $pricing_details['has_pricing'],
					'usage'                     => isset( $popularity_meta['usage'] ) ? $popularity_meta['usage'] : null,
					'usage_label'               => isset( $popularity_meta['usage_label'] ) ? $popularity_meta['usage_label'] : '',
					'rank'                      => isset( $popularity_meta['rank'] ) ? $popularity_meta['rank'] : null,
					'rank_label'                => isset( $popularity_meta['rank_label'] ) ? $popularity_meta['rank_label'] : '',
					'score'                     => isset( $popularity_meta['score'] ) ? $popularity_meta['score'] : null,
					'popularity'                => isset( $popularity_meta['popularity'] ) ? $popularity_meta['popularity'] : null,
				);
			}

			freshrank_debug_log( 'Formatted ' . count( $models ) . ' models for dropdown' );

			if ( ! empty( $models ) ) {
				usort( $models, array( $this, 'sort_openrouter_models' ) );
			}

			// Cache for 24 hours
			set_transient( 'freshrank_openrouter_models', $models, 24 * HOUR_IN_SECONDS );
			freshrank_debug_log( 'Models cached successfully' );

			FreshRank_AJAX_Response::success(
				array(
					'models' => $models,
					'cached' => false,
				)
			);
		} catch ( Exception $e ) {
			FreshRank_AJAX_Response::error( 'Error: ' . $e->getMessage() );
		}
	}

	private function openrouter_models_have_popularity_metadata( $models ) {
		if ( ! is_array( $models ) || empty( $models ) ) {
			return false;
		}

		$first = reset( $models );

		return is_array( $first ) && array_key_exists( 'usage_label', $first ) && array_key_exists( 'popularity', $first );
	}

	private function get_openrouter_popularity_map( $api_key ) {
		$map = array();

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/rankings',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			freshrank_debug_log( 'Rankings API error: ' . $response->get_error_message() );
			return $map;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			freshrank_debug_log( 'Rankings API error: HTTP ' . $response_code );
			return $map;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			freshrank_debug_log( 'Rankings API returned empty data' );
			return $map;
		}

		$map = $this->parse_openrouter_popularity_data( $data );

		return $map;
	}

	private function parse_openrouter_pricing( $pricing ) {
		$result = array(
			'prompt_per_1k'     => null,
			'completion_per_1k' => null,
			'currency'          => '',
			'currency_symbol'   => '',
			'has_pricing'       => false,
		);

		if ( ! is_array( $pricing ) || empty( $pricing ) ) {
			return $result;
		}

		$prompt_data     = $this->extract_openrouter_price( $pricing, 'prompt' );
		$completion_data = $this->extract_openrouter_price( $pricing, 'completion' );

		$currency = '';
		if ( ! empty( $prompt_data['currency'] ) ) {
			$currency = $prompt_data['currency'];
		} elseif ( ! empty( $completion_data['currency'] ) ) {
			$currency = $completion_data['currency'];
		}

		if ( ! empty( $currency ) ) {
			$result['currency']        = strtoupper( $currency );
			$result['currency_symbol'] = $this->get_currency_symbol( $result['currency'] );
		}

		if ( $prompt_data['value'] !== null ) {
			$result['prompt_per_1k'] = $this->calculate_cost_per_1k( $prompt_data['value'] );
			$result['has_pricing']   = true;
		}

		if ( $completion_data['value'] !== null ) {
			$result['completion_per_1k'] = $this->calculate_cost_per_1k( $completion_data['value'] );
			$result['has_pricing']       = true;
		}

		return $result;
	}

	private function extract_openrouter_price( $pricing, $key ) {
		$value    = null;
		$currency = '';

		if ( ! isset( $pricing[ $key ] ) ) {
			return array(
				'value'    => $value,
				'currency' => $currency,
			);
		}

		$price_entry = $pricing[ $key ];

		if ( is_array( $price_entry ) ) {
			// Try to find USD first
			$preferred_keys = array( 'usd', 'USD', 'Usd', 'usd$', 'USD$', 'usd_per_token', 'USD_per_token' );
			foreach ( $preferred_keys as $preferred_key ) {
				if ( isset( $price_entry[ $preferred_key ] ) && is_numeric( $price_entry[ $preferred_key ] ) ) {
					$value    = (float) $price_entry[ $preferred_key ];
					$currency = 'USD';
					break;
				}
			}

			if ( $value === null ) {
				foreach ( $price_entry as $entry_key => $entry_value ) {
					if ( is_numeric( $entry_value ) ) {
						$value    = (float) $entry_value;
						$currency = strtoupper( is_string( $entry_key ) ? $entry_key : '' );
						break;
					}
				}
			}

			if ( $value === null && isset( $price_entry['value'] ) && is_numeric( $price_entry['value'] ) ) {
				$value = (float) $price_entry['value'];
				if ( isset( $price_entry['currency'] ) ) {
					$currency = strtoupper( sanitize_text_field( $price_entry['currency'] ) );
				}
			}
		} elseif ( is_numeric( $price_entry ) ) {
			$value = (float) $price_entry;
		}

		return array(
			'value'    => $value,
			'currency' => $currency,
		);
	}

	private function calculate_cost_per_1k( $value ) {
		if ( $value === null ) {
			return null;
		}

		return (float) $value * 1000;
	}

	private function get_currency_symbol( $currency ) {
		switch ( strtoupper( $currency ) ) {
			case 'USD':
				return '$';
			case 'EUR':
				return '€';
			case 'GBP':
				return '£';
			case 'JPY':
				return '¥';
			case 'AUD':
				return 'A$';
			case 'CAD':
				return 'C$';
			default:
				return '';
		}
	}

	private function parse_openrouter_popularity_data( $data ) {
		$map = array();

		if ( ! is_array( $data ) ) {
			return $map;
		}

		$collections = array();

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$collections[] = $data['data'];
		} elseif ( isset( $data['rankings'] ) && is_array( $data['rankings'] ) ) {
			$collections[] = $data['rankings'];
		} elseif ( $this->is_list_array( $data ) ) {
			$collections[] = $data;
		} else {
			foreach ( $data as $value ) {
				if ( is_array( $value ) && $this->is_list_array( $value ) ) {
					$collections[] = $value;
				}
			}
		}

		foreach ( $collections as $entries ) {
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$model_id = $this->extract_openrouter_model_id( $entry );
				if ( empty( $model_id ) ) {
					continue;
				}

				$usage = $this->extract_numeric_field(
					$entry,
					array(
						'usage',
						'stats.usage',
						'metrics.usage',
						'metrics.calls_30d',
						'metrics.calls_last_30_days',
						'meta.usage',
						'meta.usage_30d',
						'summary.usage',
					)
				);

				$rank = $this->extract_numeric_field(
					$entry,
					array(
						'rank',
						'stats.rank',
						'metrics.rank',
						'position',
					)
				);

				$score = $this->extract_numeric_field(
					$entry,
					array(
						'score',
						'stats.score',
						'metrics.score',
					),
					true
				);

				$popularity_value = 0.0;
				if ( $usage !== null ) {
					$popularity_value = (float) $usage;
				} elseif ( $score !== null ) {
					$popularity_value = (float) $score;
				} elseif ( $rank !== null && (int) $rank > 0 ) {
					$popularity_value = 1 / (float) max( 1, (int) $rank );
				}

				$entry_usage = $usage !== null ? (int) $usage : null;
				$entry_rank  = $rank !== null ? (int) $rank : null;
				$entry_score = $score !== null ? (float) $score : null;

				$usage_label = '';
				if ( $entry_usage !== null ) {
					$usage_label = sprintf(
						// translators: %s is the number of calls
						__( 'Usage: %s calls', 'freshrank-ai' ),
						number_format_i18n( $entry_usage )
					);
				}

				$rank_label = '';
				if ( $entry_rank !== null ) {
					$rank_label = sprintf(
						// translators: %1$d is the rank number
						__( 'Rank #%1$d on OpenRouter', 'freshrank-ai' ),
						$entry_rank
					);
				}

				$map[ $model_id ] = array(
					'usage'       => $entry_usage,
					'rank'        => $entry_rank,
					'score'       => $entry_score,
					'popularity'  => $popularity_value,
					'usage_label' => $usage_label,
					'rank_label'  => $rank_label,
				);
			}
		}

		return $map;
	}

	private function is_list_array( $source_array ) {
		if ( ! is_array( $source_array ) || empty( $source_array ) ) {
			return false;
		}

		return array_keys( $source_array ) === range( 0, count( $source_array ) - 1 );
	}

	private function extract_openrouter_model_id( $entry ) {
		$candidates = array(
			'id',
			'model',
			'model_id',
			'attributes.id',
			'attributes.model',
			'model.id',
			'meta.model',
		);

		foreach ( $candidates as $path ) {
			$value = $this->get_value_by_path( $entry, $path );
			if ( is_string( $value ) && $value !== '' ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	private function get_value_by_path( $source_array, $path ) {
		if ( ! is_array( $source_array ) ) {
			return null;
		}

		$segments = explode( '.', $path );
		$value    = $source_array;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	private function extract_numeric_field( $entry, $paths, $allow_float = false ) {
		foreach ( $paths as $path ) {
			$value = $this->get_value_by_path( $entry, $path );
			if ( $value === null ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				return $allow_float ? (float) $value : (int) $value;
			}
		}

		return null;
	}

	private function sort_openrouter_models( $a, $b ) {
		$pop_a = isset( $a['popularity'] ) && $a['popularity'] !== null ? (float) $a['popularity'] : 0.0;
		$pop_b = isset( $b['popularity'] ) && $b['popularity'] !== null ? (float) $b['popularity'] : 0.0;

		if ( abs( $pop_a - $pop_b ) > 0.000001 ) {
			return ( $pop_a > $pop_b ) ? -1 : 1;
		}

		$rank_a = isset( $a['rank'] ) && $a['rank'] !== null ? (int) $a['rank'] : PHP_INT_MAX;
		$rank_b = isset( $b['rank'] ) && $b['rank'] !== null ? (int) $b['rank'] : PHP_INT_MAX;

		if ( $rank_a !== $rank_b ) {
			return ( $rank_a < $rank_b ) ? -1 : 1;
		}

		$name_a = isset( $a['name'] ) ? $a['name'] : '';
		$name_b = isset( $b['name'] ) ? $b['name'] : '';

		$name_compare = strcasecmp( $name_a, $name_b );
		if ( $name_compare !== 0 ) {
			return $name_compare;
		}

		return strcasecmp( $a['id'], $b['id'] );
	}

	/**
	 * AJAX handler to test OpenRouter connection
	 * Simplified: Just fetches models list to verify API key works
	 */
	public function ajax_test_openrouter_connection() {
		$this->verify_ajax_security();

		freshrank_debug_log( 'OpenRouter test connection started' );

		try {
			// Check for API key in request (for testing before saving) or from saved option
			$api_key_input = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

			// If key provided in request and not placeholder, use it
			if ( ! empty( $api_key_input ) && $api_key_input !== str_repeat( '•', 20 ) ) {
				$decrypted_key = $api_key_input;
				freshrank_debug_log( 'Using API key from request' );
			} else {
				// Otherwise use saved key
				$api_key = get_option( 'freshrank_openrouter_api_key', '' );
				if ( empty( $api_key ) ) {
					freshrank_debug_log( 'No OpenRouter API key found' );
					FreshRank_AJAX_Response::error( 'OpenRouter API key not configured. Please enter your API key and try again.' );
					return;
				}
				$decrypted_key = FreshRank_Encryption::decrypt( $api_key );
				freshrank_debug_log( 'Using saved API key' );
			}

			// Test by fetching models list (free, no rate limit issues)
			$response = wp_remote_get(
				'https://openrouter.ai/api/v1/models',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $decrypted_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				freshrank_debug_log( 'OpenRouter test failed (WP Error): ' . $response->get_error_message() );
				FreshRank_AJAX_Response::error( 'Connection failed: ' . $response->get_error_message() );
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			freshrank_debug_log( 'OpenRouter test response code: ' . $response_code );

			if ( $response_code !== 200 ) {
				$error_body    = wp_remote_retrieve_body( $response );
				$error_data    = json_decode( $error_body, true );
				$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown error';

				freshrank_debug_log( 'OpenRouter test failed (HTTP ' . $response_code . '): ' . $error_message );
				FreshRank_AJAX_Response::error( 'OpenRouter API error (HTTP ' . $response_code . '): ' . $error_message );
				return;
			}

			freshrank_debug_log( 'OpenRouter test successful!' );
			FreshRank_AJAX_Response::success( array(), 'OpenRouter API connection successful!' );
		} catch ( Exception $e ) {
			freshrank_debug_log( 'OpenRouter test exception: ' . $e->getMessage() );
			FreshRank_AJAX_Response::error( 'Test failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for dismissing API notice
	 */
	public function ajax_dismiss_api_notice() {
		$this->verify_ajax_security();

		update_user_meta( get_current_user_id(), 'freshrank_dismiss_api_notice', true );
		FreshRank_AJAX_Response::success( array() );
	}

	/**
	 * AJAX handler for deleting an article
	 */
	public function ajax_delete_article() {
		$this->verify_ajax_security();

		try {
			$post_id = FreshRank_Validation_Helper::sanitize_post_id( $_POST['post_id'] );
			// Get the post before deletion for logging
			$post = get_post( $post_id );
			if ( ! $post ) {
				FreshRank_AJAX_Response::error( __( 'Post not found', 'freshrank-ai' ) );
				return;
			}

			$post_title = $post->post_title;

			// Clean up FreshRank data
			global $wpdb;

			// Delete from articles table
			$wpdb->delete(
				$wpdb->prefix . 'freshrank_articles',
				array( 'post_id' => $post_id ),
				array( '%d' )
			);

			// Delete from analysis table
			$wpdb->delete(
				$wpdb->prefix . 'freshrank_analysis',
				array( 'post_id' => $post_id ),
				array( '%d' )
			);

			// Get and delete any associated drafts
			$drafts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d",
					$post_id
				)
			);

			foreach ( $drafts as $draft ) {
				if ( $draft->draft_post_id ) {
					wp_delete_post( $draft->draft_post_id, true );
				}
			}

			// Delete from drafts table
			$wpdb->delete(
				$wpdb->prefix . 'freshrank_drafts',
				array( 'original_post_id' => $post_id ),
				array( '%d' )
			);

			// Delete all post meta associated with FreshRank
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
					$post_id,
					'_freshrank_%'
				)
			);

			// Finally, delete the WordPress post itself (force delete, skip trash)
			$deleted = wp_delete_post( $post_id, true );

			if ( $deleted ) {
				freshrank_debug_log( "Deleted article '{$post_title}' (ID: {$post_id}) and all associated data" );

				FreshRank_AJAX_Response::success(
					array(),
					// translators: %s is the article title
					sprintf( __( 'Article "%s" deleted successfully', 'freshrank-ai' ), $post_title )
				);
			} else {
				FreshRank_AJAX_Response::error( __( 'Failed to delete article', 'freshrank-ai' ) );
			}
		} catch ( Exception $e ) {
			freshrank_debug_log( 'Delete article error - ' . $e->getMessage() );

			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for bulk deleting articles
	 */
	public function ajax_delete_bulk() {
		$this->verify_ajax_security();

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'intval', $_POST['post_ids'] ) : array();

		if ( empty( $post_ids ) ) {
			FreshRank_AJAX_Response::error( __( 'No articles selected', 'freshrank-ai' ) );
		}

		try {
			$deleted_count = 0;
			$errors        = array();

			foreach ( $post_ids as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					// translators: %d is the post ID
					$errors[] = sprintf( __( 'Post ID %d not found', 'freshrank-ai' ), $post_id );
					continue;
				}

				// Clean up FreshRank data
				global $wpdb;

				// Delete from articles table
				$wpdb->delete(
					$wpdb->prefix . 'freshrank_articles',
					array( 'post_id' => $post_id ),
					array( '%d' )
				);

				// Delete from analysis table
				$wpdb->delete(
					$wpdb->prefix . 'freshrank_analysis',
					array( 'post_id' => $post_id ),
					array( '%d' )
				);

				// Get and delete any associated drafts
				$drafts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT draft_post_id FROM {$wpdb->prefix}freshrank_drafts WHERE original_post_id = %d",
						$post_id
					)
				);

				foreach ( $drafts as $draft ) {
					if ( $draft->draft_post_id ) {
						wp_delete_post( $draft->draft_post_id, true );
					}
				}

				// Delete from drafts table
				$wpdb->delete(
					$wpdb->prefix . 'freshrank_drafts',
					array( 'original_post_id' => $post_id ),
					array( '%d' )
				);

				// Delete all post meta associated with FreshRank
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
						$post_id,
						'_freshrank_%'
					)
				);

				// Delete the WordPress post itself (force delete, skip trash)
				$deleted = wp_delete_post( $post_id, true );

				if ( $deleted ) {
					++$deleted_count;
				} else {
					// translators: %d is the post ID
					$errors[] = sprintf( __( 'Failed to delete post ID %d', 'freshrank-ai' ), $post_id );
				}
			}

			freshrank_debug_log( "Bulk deleted {$deleted_count} articles" );

			if ( $deleted_count > 0 ) {
				// translators: %d is the number of articles deleted
				$message = sprintf( _n( '%d article deleted successfully', '%d articles deleted successfully', $deleted_count, 'freshrank-ai' ), $deleted_count );

				if ( ! empty( $errors ) ) {
					// translators: %d is the number of errors
					$message .= ' ' . sprintf( __( '(%d errors)', 'freshrank-ai' ), count( $errors ) );
				}

				FreshRank_AJAX_Response::success(
					array(
						'deleted_count' => $deleted_count,
						'errors'        => $errors,
					),
					$message
				);
			} else {
				FreshRank_AJAX_Response::error(
					__( 'No articles were deleted', 'freshrank-ai' ),
					'error',
					array(
						'errors' => $errors,
					)
				);
			}
		} catch ( Exception $e ) {
			freshrank_debug_log( 'Bulk delete error - ' . $e->getMessage() );

			FreshRank_AJAX_Response::error( $e->getMessage() );
		}
	}
}

// Initialize the plugin
FreshRank::get_instance();
