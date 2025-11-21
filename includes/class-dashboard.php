<?php
/**
 * Dashboard management for FreshRank AI
 * COMPLETE FIXED VERSION: With priority score details and GSC data display
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Dashboard {

	private static $instance = null;
	private $database;
	private $data_provider;
	private $statistics;
	private $filters;
	private $actions;
	private $actionable_counts = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->database      = FreshRank_Database::get_instance();
		$this->data_provider = FreshRank_Dashboard_Data_Provider::get_instance();
		$this->statistics    = FreshRank_Dashboard_Statistics::get_instance();
		$this->filters       = FreshRank_Dashboard_Filters::get_instance();
		$this->actions       = FreshRank_Dashboard_Actions::get_instance();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_init', array( $this, 'debug_data_flow' ) );
		add_action( 'admin_init', array( $this, 'cleanup_stale_analyses' ) );
	}

	/**
	 * Clean up all stale analyses on dashboard load
	 * This runs once per page load to ensure clean state
	 */
	public function cleanup_stale_analyses() {
		// Check capability first
		if ( ! current_user_can( 'manage_freshrank' ) ) {
			return;
		}

		// Only run on dashboard page
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'freshrank-ai' ) {
			return;
		}

		global $wpdb;
		$analysis_table = $wpdb->prefix . 'freshrank_analysis';
		$drafts_table   = $wpdb->prefix . 'freshrank_drafts';

		// Clean up stale analyses
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
		$analyzing_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$analysis_table} WHERE status = %s",
				'analyzing'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$cleaned_analyses = 0;
		foreach ( $analyzing_posts as $post_id ) {
			// Check if transient exists
			$active = get_transient( 'freshrank_analyzing_' . $post_id );
			if ( $active === false ) {
				// Stale - reset to pending
				$wpdb->update(
					$analysis_table,
					array( 'status' => 'pending' ),
					array( 'post_id' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);
				++$cleaned_analyses;

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					freshrank_debug_log( "Cleaned up stale analysis for post {$post_id}" );
				}
			}
		}

		// Clean up stale draft creations in both drafts and articles tables
		$articles_table = $wpdb->prefix . 'freshrank_articles';

		// Get all posts with draft_status = 'creating' from articles table
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
		$creating_drafts_articles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$articles_table} WHERE draft_status = %s",
				'creating'
			)
		);

		// Also check drafts table
		$creating_drafts_table = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT original_post_id FROM {$drafts_table} WHERE status = %s",
				'creating'
			)
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable

		// Combine both lists
		$all_creating = array_unique( array_merge( $creating_drafts_articles, $creating_drafts_table ) );

		$cleaned_drafts = 0;
		foreach ( $all_creating as $post_id ) {
			// Check if transient exists
			$active = get_transient( 'freshrank_creating_draft_' . $post_id );
			if ( $active === false ) {
				// Stale - reset articles table draft_status
				$wpdb->update(
					$articles_table,
					array( 'draft_status' => 'pending' ),
					array( 'post_id' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);

				// Also clean up drafts table
				$draft_post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT draft_post_id FROM {$drafts_table} WHERE original_post_id = %d AND status = %s LIMIT %d",
						$post_id,
						'creating',
						1
					)
				);

				if ( $draft_post_id && get_post( $draft_post_id ) ) {
					// Draft post exists, mark as pending
					$wpdb->update(
						$drafts_table,
						array( 'status' => 'pending' ),
						array(
							'original_post_id' => $post_id,
							'status'           => 'creating',
						),
						array( '%s' ),
						array( '%d', '%s' )
					);
				} else {
					// No draft post, delete the stale record
					$wpdb->delete(
						$drafts_table,
						array(
							'original_post_id' => $post_id,
							'status'           => 'creating',
						),
						array( '%d', '%s' )
					);
				}

				++$cleaned_drafts;

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					freshrank_debug_log( "Cleaned up stale draft creation for post {$post_id}" );
				}
			}
		}

		// Stale draft creations (stuck in 'creating_draft' for > 15 mins)
		if ( ! empty( $stale_draft_creations ) ) {
			$cleaned_drafts = 0;
			foreach ( $stale_draft_creations as $post_id ) {
				// Reset to 'pending'
				$this->database->update_draft_status( $post_id, 'pending' );
				++$cleaned_drafts;
				freshrank_debug_log( "Cleaned up stale draft creation for post {$post_id}" );
			}
		}

		if ( $cleaned_analyses > 0 || $cleaned_drafts > 0 ) {
			freshrank_debug_log( "Cleaned up {$cleaned_analyses} stale analyses and {$cleaned_drafts} stale draft creations on dashboard load" );
		}
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		$plugin_name = freshrank_get_plugin_name();
		add_menu_page(
			$plugin_name,
			$plugin_name,
			'manage_freshrank',
			'freshrank-ai',
			array( $this, 'dashboard_page' ),
			'dashicons-chart-line',
			30
		);

		add_submenu_page(
			'freshrank-ai',
			__( 'Dashboard', 'freshrank-ai' ),
			__( 'Dashboard', 'freshrank-ai' ),
			'manage_freshrank',
			'freshrank-ai',
			array( $this, 'dashboard_page' )
		);

		// Analytics (Pro only)
		if ( ! freshrank_is_free_version() ) {
			add_submenu_page(
				'freshrank-ai',
				__( 'Analytics', 'freshrank-ai' ),
				__( 'Analytics', 'freshrank-ai' ),
				'manage_freshrank',
				'freshrank-analytics',
				array( $this, 'analytics_page' )
			);
		}

		add_submenu_page(
			'freshrank-ai',
			__( 'Settings', 'freshrank-ai' ),
			__( 'Settings', 'freshrank-ai' ),
			'manage_freshrank',
			'freshrank-settings',
			array( $this, 'settings_page' )
		);

		// Add debug log viewer (only if debug mode is enabled)
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			add_submenu_page(
				'freshrank-ai',
				__( 'Debug Log', 'freshrank-ai' ),
				__( 'Debug Log', 'freshrank-ai' ),
				'manage_freshrank',
				'freshrank-debug',
				array( $this, 'debug_log_page' )
			);
		}
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueue_admin_scripts( $hook ) {
		freshrank_debug_log( 'enqueue_admin_scripts called with hook: ' . $hook );

		// Check if this is any FreshRank page (supports white-label menu slugs)
		$freshrank_pages   = array( 'freshrank-ai', 'freshrank-analytics', 'freshrank-settings', 'freshrank-debug' );
		$is_freshrank_page = false;

		foreach ( $freshrank_pages as $page ) {
			if ( strpos( $hook, $page ) !== false ) {
				$is_freshrank_page = true;
				break;
			}
		}

		if ( ! $is_freshrank_page ) {
			freshrank_debug_log( 'Not a FreshRank page (hook: ' . $hook . '), skipping script enqueue' );
			return;
		}

		freshrank_debug_log( 'FreshRank page detected, enqueuing scripts...' );

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		$script_url = FRESHRANK_PLUGIN_URL . 'admin/js/admin.js';
		freshrank_debug_log( 'Enqueuing admin.js from: ' . $script_url );

		wp_enqueue_script(
			'freshrank-admin',
			$script_url,
			array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-dialog' ),
			FRESHRANK_VERSION,
			true
		);

		wp_enqueue_style(
			'freshrank-admin',
			FRESHRANK_PLUGIN_URL . 'admin/css/admin.css',
			array( 'wp-jquery-ui-dialog' ),
			FRESHRANK_VERSION
		);

		freshrank_debug_log( 'Scripts enqueued successfully' );

		// Localize script
		$localized_data = array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'freshrank_nonce' ),
			'settings_url' => admin_url( 'admin.php?page=freshrank-settings' ),
			'admin_url'    => admin_url(),
			'debug'        => get_option( 'freshrank_debug_mode', false ),
			'strings'      => array(
				'confirm_approve'         => __( 'Are you sure you want to approve this draft? The original post will be replaced.', 'freshrank-ai' ),
				'confirm_reject'          => __( 'Are you sure you want to reject this draft? It will be permanently deleted.', 'freshrank-ai' ),
				'confirm_analyze_all'     => __( 'This will analyze all selected articles. This may take several minutes. Continue?', 'freshrank-ai' ),
				'confirm_update_all'      => __( 'This will create updated drafts for all analyzed articles. This may take several minutes. Continue?', 'freshrank-ai' ),
				'confirm_prioritize'      => __( 'This will fetch Google Search Console data and prioritize all articles based on SEO performance. This may take several minutes. Continue?', 'freshrank-ai' ),
				'confirm_delete'          => __( 'WARNING: This will permanently delete the article and all its FreshRank data. This action cannot be undone!\n\nAre you sure?', 'freshrank-ai' ),
				'confirm_delete_bulk'     => __( 'WARNING: This will permanently delete all selected articles and their FreshRank data. This action cannot be undone!\n\nAre you sure?', 'freshrank-ai' ),
				'analyzing'               => __( 'Analyzing...', 'freshrank-ai' ),
				'updating'                => __( 'Creating draft...', 'freshrank-ai' ),
				'prioritizing'            => __( 'Prioritizing articles...', 'freshrank-ai' ),
				'deleting'                => __( 'Deleting...', 'freshrank-ai' ),
				'error'                   => __( 'Error', 'freshrank-ai' ),
				'success'                 => __( 'Success', 'freshrank-ai' ),
				'processing'              => __( 'Processing...', 'freshrank-ai' ),
				'loading_models'          => __( 'Loading models...', 'freshrank-ai' ),
				'models_unavailable'      => __( 'No models available. Try refreshing the list.', 'freshrank-ai' ),
				'no_models_found'         => __( 'No models match your search.', 'freshrank-ai' ),
				'models_load_failed'      => __( 'Failed to load OpenRouter models.', 'freshrank-ai' ),
				'refreshing'              => __( 'Refreshing...', 'freshrank-ai' ),
				'models_refreshed'        => __( 'Models list refreshed', 'freshrank-ai' ),
				'models_refresh_failed'   => __( 'Failed to refresh models. Please try again.', 'freshrank-ai' ),
				// translators: %s is the rank number
				'rank_fallback'           => __( 'Rank #%s', 'freshrank-ai' ),
				// translators: %1$s is the cost amount, %2$s is the suffix
				'cost_estimate_fragment'  => __( 'Approx. %1$s / 1K %2$s', 'freshrank-ai' ),
				'cost_estimate_separator' => __( ' • ', 'freshrank-ai' ),
				'cost_prompt_suffix'      => __( 'prompt tokens', 'freshrank-ai' ),
				'cost_completion_suffix'  => __( 'completion tokens', 'freshrank-ai' ),
				'draft_created'           => __( 'Draft created. Refresh the page to view details.', 'freshrank-ai' ),
			),
		);

		freshrank_debug_log(
			'Localizing script with data: ' . json_encode(
				array(
					'debug'    => $localized_data['debug'],
					'ajax_url' => $localized_data['ajax_url'],
				)
			)
		);

		wp_localize_script( 'freshrank-admin', 'freshrank_ajax', $localized_data );

		freshrank_debug_log( 'Script localized successfully' );

		// Add white-label custom CSS (Pro only)
		if ( freshrank_whitelabel_enabled() ) {
			$primary_color = freshrank_get_primary_color();
			$custom_css    = "
                /* White-Label Custom Colors */
                .freshrank-card .status.success,
                .freshrank-priority-badge,
                .button-primary.freshrank-btn {
                    background-color: {$primary_color} !important;
                    border-color: {$primary_color} !important;
                }

                .freshrank-card:hover,
                .freshrank-priority-score,
                a.freshrank-link {
                    border-color: {$primary_color} !important;
                    color: {$primary_color} !important;
                }

                .freshrank-btn:hover,
                .button-primary.freshrank-btn:hover {
                    background-color: " . $this->adjust_brightness( $primary_color, -20 ) . ' !important;
                    border-color: ' . $this->adjust_brightness( $primary_color, -20 ) . " !important;
                }

                .freshrank-loading-spinner {
                    border-top-color: {$primary_color} !important;
                }
            ";
			wp_add_inline_style( 'freshrank-admin', $custom_css );
		}
	}

	/**
	 * Adjust color brightness for hover effects
	 */
	private function adjust_brightness( $hex, $steps ) {
		// Remove # if present
		$hex = str_replace( '#', '', $hex );

		// Convert to RGB
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Adjust
		$r = max( 0, min( 255, $r + $steps ) );
		$g = max( 0, min( 255, $g + $steps ) );
		$b = max( 0, min( 255, $b + $steps ) );

		// Convert back to hex
		return '#' . str_pad( dechex( $r ), 2, '0', STR_PAD_LEFT )
					. str_pad( dechex( $g ), 2, '0', STR_PAD_LEFT )
					. str_pad( dechex( $b ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Handle admin actions
	 */
	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_freshrank' ) ) {
			return;
		}

		if ( isset( $_GET['action'] ) && isset( $_GET['page'] ) && $_GET['page'] === 'freshrank-ai' ) {
			switch ( $_GET['action'] ) {
				case 'clear_analysis':
					if ( isset( $_GET['post_id'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_analysis_' . $_GET['post_id'] ) ) {
						$this->actions->handle_clear_analysis( $_GET['post_id'] );
					}
					break;

				case 'clear_draft':
					if ( isset( $_GET['post_id'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_draft_' . $_GET['post_id'] ) ) {
						$this->actions->handle_clear_draft( $_GET['post_id'] );
					}
					break;

				case 'exclude_article':
					if ( isset( $_GET['post_id'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'exclude_article_' . $_GET['post_id'] ) ) {
						$this->actions->handle_exclude_article( $_GET['post_id'] );
					}
					break;

				case 'include_article':
					if ( isset( $_GET['post_id'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'include_article_' . $_GET['post_id'] ) ) {
						$this->actions->handle_include_article( $_GET['post_id'] );
					}
					break;
			}
		}
	}

	/**
	 * Main dashboard page
	 */
	public function dashboard_page() {
		$current_state = $this->data_provider->get_dashboard_state();
		$statistics    = $this->database->get_statistics();

		?>
		<div class="wrap">
			<div class="freshrank-dashboard-layout">
				<div class="freshrank-main-content">
					<h1><?php _e( 'FreshRank AI Dashboard', 'freshrank-ai' ); ?></h1>

					<?php if ( freshrank_is_free_version() ) : ?>
					<!-- Free Version Upgrade Banner -->
					<div class="notice notice-info" style="border-left-color: #2271b1; padding: 15px; margin: 20px 0;">
						<div style="display: flex; align-items: center; gap: 15px;">
							<span class="dashicons dashicons-star-filled" style="font-size: 30px; color: #2271b1;"></span>
							<div style="flex: 1;">
								<h3 style="margin: 0 0 8px 0;">
									<?php _e( '🎉 You\'re using FreshRank AI Lite (Free)', 'freshrank-ai' ); ?>
								</h3>
								<p style="margin: 0 0 10px 0;">
									<?php _e( 'Get comprehensive content analysis with AI-powered insights. Want automated content generation?', 'freshrank-ai' ); ?>
								</p>
								<p style="margin: 0;">
									<strong><?php _e( 'Upgrade to Pro for:', 'freshrank-ai' ); ?></strong>
									✨ <?php _e( 'AI-generated content updates', 'freshrank-ai' ); ?> •
									🤖 <?php _e( 'Access to 450+ AI models', 'freshrank-ai' ); ?> •
									⚡ <?php _e( 'Bulk operations', 'freshrank-ai' ); ?> •
									🔄 <?php _e( 'Draft approval workflow', 'freshrank-ai' ); ?>
								</p>
							</div>
							<div>
								<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" class="button button-primary" target="_blank" style="height: auto; padding: 10px 20px; font-size: 14px;">
									<?php _e( 'Upgrade to Pro →', 'freshrank-ai' ); ?>
								</a>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<div id="wsau-dashboard-content">
						<?php
						switch ( $current_state ) {
							case 'initial':
								$this->render_initial_state();
								break;
							default:
								$this->render_initial_state();
						}
						?>
					</div>
				</div>

				<div class="freshrank-sidebar">
					<?php $this->statistics->display_statistics( $statistics ); ?>
				</div>
			</div>
			
			<!-- Hidden elements for AJAX -->
			<div id="freshrank-progress-dialog" title="<?php _e( 'Processing', 'freshrank-ai' ); ?>" style="display: none;">
				<div id="freshrank-progress-container">
					<div class="freshrank-progress-bar-container">
						<div id="freshrank-progress-bar"></div>
					</div>
					<div id="freshrank-progress-text"><?php _e( 'Initializing...', 'freshrank-ai' ); ?></div>
					<button type="button" id="freshrank-cancel-prioritization" class="button" style="margin-top: 15px; display: none;">
						<?php _e( 'Cancel Prioritization', 'freshrank-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * FIXED: Render detailed priority score breakdown with actual GSC data
	 */
	private function render_priority_score_details( $article ) {
		?>
		<div class="wsau-detailed-priority-info">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<h4 style="margin: 0;"><?php _e( 'Priority Score Breakdown', 'freshrank-ai' ); ?></h4>
				<!-- <button type="button" class="button button-small freshrank-refresh-gsc-data"
						data-post-id="<?php // echo $article->ID; ?>"
						title="<?php //esc_attr_e( 'Refresh GSC data for this article', 'freshrank-ai' ); ?>">
					<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; margin-top: 2px;"></span>
					<?php // _e( 'Refresh Data', 'freshrank-ai' ); ?>
				</button> -->
			</div>
			
			<div class="wsau-priority-section">
				<div class="wsau-priority-summary-section">
					<h5><?php _e( 'Score Components', 'freshrank-ai' ); ?></h5>
					<div class="wsau-priority-summary-content">
						<div class="wsau-priority-component">
							<div class="wsau-component-label">
								<strong><?php _e( 'Content Age Score', 'freshrank-ai' ); ?></strong>
								<span class="wsau-component-weight">(0-30 points)</span>
							</div>
							<div class="wsau-component-value">
								<span class="wsau-score-value"><?php echo number_format( $article->content_age_score, 1 ); ?></span>
								<div class="wsau-score-explanation">
									<?php echo $this->statistics->get_content_age_explanation( $article->content_age_score ); ?>
								</div>
							</div>
						</div>

						<div class="wsau-priority-component">
							<div class="wsau-component-label">
								<strong><?php _e( 'Traffic Decline Score', 'freshrank-ai' ); ?></strong>
								<span class="wsau-component-weight">(0-30 points)</span>
							</div>
							<div class="wsau-component-value">
								<span class="wsau-score-value"><?php echo number_format( $this->statistics->calculate_traffic_decline_score( $article ), 1 ); ?></span>
								<div class="wsau-score-explanation">
									<?php echo $this->statistics->get_traffic_decline_explanation( $article ); ?>
								</div>
							</div>
						</div>

						<div class="wsau-priority-component">
							<div class="wsau-component-label">
								<strong><?php _e( 'Traffic Potential Score', 'freshrank-ai' ); ?></strong>
								<span class="wsau-component-weight">(0-30 points)</span>
							</div>
							<div class="wsau-component-value">
								<span class="wsau-score-value"><?php echo number_format( $article->traffic_potential, 1 ); ?></span>
								<div class="wsau-score-explanation">
									<?php echo $this->statistics->get_traffic_potential_explanation( $article ); ?>
								</div>
							</div>
						</div>

						<div class="wsau-priority-total">
							<div class="wsau-component-label">
								<strong><?php _e( 'Total Priority Score', 'freshrank-ai' ); ?></strong>
								<span class="wsau-component-weight">(0-90 scale)</span>
							</div>
							<div class="wsau-component-value">
								<span class="wsau-score-value wsau-score-total"><?php echo number_format( $article->priority_score, 1 ); ?></span>
								<div class="wsau-score-explanation">
									<?php echo $this->statistics->get_priority_level_explanation( $article->priority_score ); ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				
				<div class="wsau-priority-data-section">
					<h5><?php _e( 'GSC Data', 'freshrank-ai' ); ?></h5>
					<div class="wsau-gsc-data-content">
						<div class="wsau-gsc-period">
							<h6><?php _e( 'Current Period (Last 90 days)', 'freshrank-ai' ); ?></h6>
							<div class="wsau-gsc-metrics">
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Clicks:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->clicks_current ); ?></span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Impressions:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->impressions_current ); ?></span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'CTR:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->ctr_current * 100, 2 ); ?>%</span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Position:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo $article->position_current > 0 ? number_format( $article->position_current, 1 ) : 'N/A'; ?></span>
								</div>
							</div>
						</div>
						
						<div class="wsau-gsc-period">
							<h6><?php _e( 'Previous Period (91-180 days ago)', 'freshrank-ai' ); ?></h6>
							<div class="wsau-gsc-metrics">
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Clicks:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->clicks_previous ); ?></span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Impressions:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->impressions_previous ); ?></span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'CTR:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo number_format( $article->ctr_previous * 100, 2 ); ?>%</span>
								</div>
								<div class="wsau-gsc-metric">
									<span class="wsau-metric-label"><?php _e( 'Position:', 'freshrank-ai' ); ?></span>
									<span class="wsau-metric-value"><?php echo $article->position_previous > 0 ? number_format( $article->position_previous, 1 ) : 'N/A'; ?></span>
								</div>
							</div>
						</div>
						
						<?php if ( ! empty( $article->last_gsc_update ) ) : ?>
							<p style="font-size: 12px; color: #666; margin-top: 10px;">
								<strong><?php _e( 'Last GSC Update:', 'freshrank-ai' ); ?></strong> 
								<?php echo mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $article->last_gsc_update ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render detailed draft information in expandable row
	 */
	/**
	 * Render revision-based draft info for modal
	 */
	/**
	 * Render unified draft info (for system that creates both draft post + revision)
	 */
	private function render_unified_draft_info( $post_id, $draft_info ) {
		// Get draft post
		$draft_post = get_post( $draft_info['draft_id'] );
		if ( ! $draft_post ) {
			echo '<p>' . __( 'Draft post not found.', 'freshrank-ai' ) . '</p>';
			return;
		}

		// Get URLs
		$preview_url = get_preview_post_link( $draft_info['draft_id'] );
		$edit_url    = get_edit_post_link( $draft_info['draft_id'], 'raw' );

		// Get revision URL
		$ai_revision_id = get_post_meta( $post_id, '_freshrank_ai_revision_id', true );
		$compare_url    = $ai_revision_id ? admin_url( 'revision.php?revision=' . $ai_revision_id ) : '';

		// Get metadata
		$update_severity  = get_post_meta( $draft_info['draft_id'], '_freshrank_update_severity', true );
		$severity_summary = get_post_meta( $draft_info['draft_id'], '_freshrank_severity_summary', true );
		$seo_improvements = get_post_meta( $draft_info['draft_id'], '_freshrank_seo_improvements', true );
		$content_updates  = get_post_meta( $draft_info['draft_id'], '_freshrank_content_updates', true );
		$update_summary   = get_post_meta( $draft_info['draft_id'], '_freshrank_update_summary', true );
		$created_date     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $draft_post->post_modified ) );
		?>
		<div class="wsau-detailed-draft-info">
			<h4><?php _e( 'Draft Details', 'freshrank-ai' ); ?></h4>

			<div class="wsau-draft-section">
				<div class="wsau-draft-summary-section">
					<h5><?php _e( 'Changes Summary', 'freshrank-ai' ); ?></h5>

					<?php if ( ! empty( $severity_summary ) ) : ?>
					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px; margin-bottom: 15px;">
						<strong><?php _e( 'Severity Filters Applied:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $severity_summary ); ?>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $update_summary ) ) : ?>
					<div class="wsau-draft-description" style="margin-bottom: 20px; line-height: 1.6;">
						<?php echo esc_html( $update_summary ); ?>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $update_severity ) && is_array( $update_severity ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
						<?php
						printf(
							// translators: %d is the number of issues addressed
							_n( '%d Issue Addressed:', '%d Issues Addressed:', count( $update_severity ), 'freshrank-ai' ),
							count( $update_severity )
						);
						?>
							</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $update_severity as $issue ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $issue ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $seo_improvements ) && is_array( $seo_improvements ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
							<span class="dashicons dashicons-search" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php _e( 'SEO Improvements:', 'freshrank-ai' ); ?>
						</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $seo_improvements as $improvement ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $improvement ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $content_updates ) && is_array( $content_updates ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
							<span class="dashicons dashicons-edit" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php _e( 'Content Updates:', 'freshrank-ai' ); ?>
						</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $content_updates as $update ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $update ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<div style="color: #666; font-size: 0.9em; margin-top: 15px;">
						<strong><?php _e( 'Created:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $created_date ); ?>
					</div>


					<?php if ( $draft_info['tokens_used'] > 0 ) : ?>
							<div class="wsau-draft-token-usage" style="margin-top: 12px;">
								<details>
									<summary style="cursor: pointer; color: #666; font-size: 0.9em;">
										<?php _e( 'Usage Details', 'freshrank-ai' ); ?>
									</summary>
									<div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px; font-size: 0.85em; color: #666;">
										<div><strong><?php _e( 'Tokens:', 'freshrank-ai' ); ?></strong> <?php echo number_format( $draft_info['tokens_used'] ); ?>
											<span style="color: #999;">(<?php echo number_format( $draft_info['prompt_tokens'] ); ?> in + <?php echo number_format( $draft_info['completion_tokens'] ); ?> out)</span>
										</div>
										<?php if ( ! empty( $draft_info['model_used'] ) ) : ?>
											<div><strong><?php _e( 'Model:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $draft_info['model_used'] ); ?></div>
										<?php endif; ?>
										<?php
										$cost = $this->data_provider->estimate_cost_from_tokens( $draft_info['prompt_tokens'], $draft_info['completion_tokens'], $draft_info['model_used'] );
										if ( $cost > 0 ) :
											?>
											<div><strong><?php _e( 'Est. Cost:', 'freshrank-ai' ); ?></strong> $<?php echo number_format( $cost, 4 ); ?></div>
										<?php endif; ?>
									</div>
								</details>
							</div>
						<?php endif; ?>
				</div>

				<div class="wsau-draft-actions-section" style="border-top: 1px solid #ddd; padding-top: 20px; margin-top: 20px;">
					<h5><?php _e( 'Actions', 'freshrank-ai' ); ?></h5>
					<div class="wsau-draft-action-buttons" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
						<?php if ( $compare_url ) : ?>
						<a href="<?php echo esc_url( $compare_url ); ?>" target="_blank" class="button button-primary" style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
							<?php _e( 'View Changes', 'freshrank-ai' ); ?>
						</a>
						<?php endif; ?>

						<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
							<?php _e( 'Preview Draft', 'freshrank-ai' ); ?>
						</a>

						<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-edit" style="margin-top: 3px;"></span>
							<?php _e( 'Edit Draft', 'freshrank-ai' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_revision_draft_info( $post_id ) {
		// Get the specific AI revision ID that was saved
		$ai_revision_id = get_post_meta( $post_id, '_freshrank_ai_revision_id', true );
		$compare_url    = '';

		if ( $ai_revision_id ) {
			// Link directly to the AI revision for comparison
			$compare_url = admin_url( 'revision.php?revision=' . $ai_revision_id );
		}

		// Get metadata from main post
		$update_severity  = get_post_meta( $post_id, '_freshrank_update_severity', true );
		$severity_summary = get_post_meta( $post_id, '_freshrank_severity_summary', true );
		$seo_improvements = get_post_meta( $post_id, '_freshrank_seo_improvements', true );
		$content_updates  = get_post_meta( $post_id, '_freshrank_content_updates', true );
		$update_summary   = get_post_meta( $post_id, '_freshrank_update_summary', true );
		$last_ai_update   = get_post_meta( $post_id, '_freshrank_last_ai_update', true );
		$token_usage      = get_post_meta( $post_id, '_freshrank_token_usage', true );

		$created_date = $last_ai_update ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_ai_update ) : __( 'Unknown', 'freshrank-ai' );

		// Calculate changes count
		$changes_count = 0;
		if ( ! empty( $update_severity ) && is_array( $update_severity ) ) {
			$changes_count = count( $update_severity );
		}
		?>
		<div class="wsau-detailed-draft-info">
			<h4><?php _e( 'Draft Details', 'freshrank-ai' ); ?></h4>

			<div class="wsau-draft-section">
				<div class="wsau-draft-summary-section">
					<h5><?php _e( 'Changes Summary', 'freshrank-ai' ); ?></h5>

					<?php if ( ! empty( $severity_summary ) ) : ?>
					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px; margin-bottom: 15px;">
						<strong><?php _e( 'Severity Filters Applied:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $severity_summary ); ?>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $update_summary ) ) : ?>
					<div class="wsau-draft-description" style="margin-bottom: 20px; line-height: 1.6;">
						<?php echo esc_html( $update_summary ); ?>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $update_severity ) && is_array( $update_severity ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
						<?php
						printf(
							// translators: %d is the number of issues addressed
							_n( '%d Issue Addressed:', '%d Issues Addressed:', count( $update_severity ), 'freshrank-ai' ),
							count( $update_severity )
						);
						?>
							</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $update_severity as $issue ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $issue ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $seo_improvements ) && is_array( $seo_improvements ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
							<span class="dashicons dashicons-search" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php _e( 'SEO Improvements:', 'freshrank-ai' ); ?>
						</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $seo_improvements as $improvement ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $improvement ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $content_updates ) && is_array( $content_updates ) ) : ?>
					<div style="margin-bottom: 20px;">
						<strong>
							<span class="dashicons dashicons-edit" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php _e( 'Content Updates:', 'freshrank-ai' ); ?>
						</strong>
						<ul style="margin-top: 8px; list-style: disc; margin-left: 20px;">
							<?php foreach ( $content_updates as $update ) : ?>
								<li style="margin-bottom: 6px;"><?php echo esc_html( $update ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<div style="color: #666; font-size: 0.9em; margin-top: 15px;">
						<strong><?php _e( 'Created:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $created_date ); ?>
					</div>

					<?php if ( ! empty( $token_usage ) && is_array( $token_usage ) ) : ?>
					<div class="wsau-draft-token-usage" style="margin-top: 12px;">
						<details>
							<summary style="cursor: pointer; color: #666; font-size: 0.9em;">
								<?php _e( 'Usage Details', 'freshrank-ai' ); ?>
							</summary>
							<div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px; font-size: 0.85em; color: #666;">
								<div><strong><?php _e( 'Tokens:', 'freshrank-ai' ); ?></strong> <?php echo number_format( $token_usage['total_tokens'] ); ?>
									<span style="color: #999;">(<?php echo number_format( $token_usage['prompt_tokens'] ); ?> in + <?php echo number_format( $token_usage['completion_tokens'] ); ?> out)</span>
								</div>
								<?php if ( ! empty( $token_usage['model'] ) ) : ?>
									<div><strong><?php _e( 'Model:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $token_usage['model'] ); ?></div>
								<?php endif; ?>
							</div>
						</details>
					</div>
					<?php endif; ?>
				</div>

				<div class="wsau-draft-actions-section" style="border-top: 1px solid #ddd; padding-top: 20px; margin-top: 20px;">
					<h5><?php _e( 'Actions', 'freshrank-ai' ); ?></h5>
					<div class="wsau-draft-action-buttons" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
						<?php
						// Get the draft post ID
						$draft_post_id = get_post_meta( $post_id, '_freshrank_draft_post_id', true );
						if ( $draft_post_id && get_post( $draft_post_id ) ) :
							$preview_url = get_preview_post_link( $draft_post_id );
							$edit_url    = get_edit_post_link( $draft_post_id, 'raw' );
							?>
						<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
							<?php _e( 'Preview Draft', 'freshrank-ai' ); ?>
						</a>
						<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-edit" style="margin-top: 3px;"></span>
							<?php _e( 'Edit Draft', 'freshrank-ai' ); ?>
						</a>
						<?php endif; ?>

						<?php if ( $compare_url ) : ?>
						<a href="<?php echo esc_url( $compare_url ); ?>" target="_blank" class="button">
							<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
							<?php _e( 'View Revision', 'freshrank-ai' ); ?>
						</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_detailed_draft_info( $draft_info ) {
		?>
		<div class="wsau-detailed-draft-info">
			<h4><?php _e( 'Draft Details', 'freshrank-ai' ); ?></h4>

			<div class="wsau-draft-section">
				<div class="wsau-draft-summary-section">
					<h5><?php _e( 'Summary', 'freshrank-ai' ); ?></h5>
					<div class="wsau-draft-summary-content">
						<div class="wsau-draft-stat">
							<strong><?php echo $draft_info['changes_count']; ?></strong> <?php _e( 'changes made', 'freshrank-ai' ); ?>
						</div>
						<div class="wsau-draft-stat">
							<?php _e( 'Created:', 'freshrank-ai' ); ?> <?php echo mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $draft_info['created_date'] ); ?>
						</div>
						<?php if ( ! empty( $draft_info['update_summary'] ) ) : ?>
							<div class="wsau-draft-description">
								<strong><?php _e( 'Update Summary:', 'freshrank-ai' ); ?></strong>
								<?php echo esc_html( $draft_info['update_summary'] ); ?>
							</div>
						<?php endif; ?>

						<?php if ( $draft_info['tokens_used'] > 0 ) : ?>
							<div class="wsau-draft-token-usage" style="margin-top: 12px;">
								<details>
									<summary style="cursor: pointer; color: #666; font-size: 0.9em;">
										<?php _e( 'Usage Details', 'freshrank-ai' ); ?>
									</summary>
									<div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px; font-size: 0.85em; color: #666;">
										<div><strong><?php _e( 'Tokens:', 'freshrank-ai' ); ?></strong> <?php echo number_format( $draft_info['tokens_used'] ); ?>
											<span style="color: #999;">(<?php echo number_format( $draft_info['prompt_tokens'] ); ?> in + <?php echo number_format( $draft_info['completion_tokens'] ); ?> out)</span>
										</div>
										<?php if ( ! empty( $draft_info['model_used'] ) ) : ?>
											<div><strong><?php _e( 'Model:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $draft_info['model_used'] ); ?></div>
										<?php endif; ?>
										<?php
										$cost = $this->data_provider->estimate_cost_from_tokens( $draft_info['prompt_tokens'], $draft_info['completion_tokens'], $draft_info['model_used'] );
										if ( $cost > 0 ) :
											?>
											<div><strong><?php _e( 'Est. Cost:', 'freshrank-ai' ); ?></strong> $<?php echo number_format( $cost, 4 ); ?></div>
										<?php endif; ?>
									</div>
								</details>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="wsau-draft-actions-section">
					<h5><?php _e( 'Actions', 'freshrank-ai' ); ?></h5>
					<div class="wsau-draft-action-buttons">
						<button type="button" class="button button-primary freshrank-view-diff"
								data-draft-id="<?php echo $draft_info['draft_id']; ?>"
								data-original-id="<?php echo $draft_info['original_id']; ?>"
								style="background: #2271b1; color: #fff; border-color: #2271b1;">
							<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
							<?php _e( 'View Changes', 'freshrank-ai' ); ?>
						</button>
						<button type="button" class="button button-primary freshrank-approve-draft-inline"
								data-draft-id="<?php echo $draft_info['draft_id']; ?>"
								data-original-id="<?php echo $draft_info['original_id']; ?>">
							<?php _e( 'Approve Draft', 'freshrank-ai' ); ?>
						</button>
						<button type="button" class="button button-secondary freshrank-reject-draft-inline"
								data-draft-id="<?php echo $draft_info['draft_id']; ?>">
							<?php _e( 'Reject Draft', 'freshrank-ai' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings page
	 */
	public function settings_page() {
		// This will be handled by the Settings class
		FreshRank_Settings::get_instance()->render_settings_page();
	}

	/**
	 * Get current dashboard state - Always show initial state for consistent UI
	 */
	/**
	 * Render initial state - Updated to show draft buttons when articles are analyzed
	 */
	private function render_initial_state() {
		// Get pagination parameters
		$per_page     = isset( $_GET['per_page'] ) ? intval( $_GET['per_page'] ) : 25;
		$per_page     = in_array( $per_page, array( 10, 25, 50, 100 ), true ) ? $per_page : 25; // Validate
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		// Get filter values
		$filters = array();
		if ( isset( $_GET['author_filter'] ) && $_GET['author_filter'] != '0' ) {
			$filters['author'] = intval( $_GET['author_filter'] );
		}
		if ( isset( $_GET['category_filter'] ) && $_GET['category_filter'] != '0' ) {
			$filters['category'] = intval( $_GET['category_filter'] );
		}
		if ( isset( $_GET['period'] ) && $_GET['period'] != '' ) {
			$filters['period'] = sanitize_text_field( $_GET['period'] );
		}
		if ( isset( $_GET['s'] ) && $_GET['s'] != '' ) {
			$filters['search'] = sanitize_text_field( $_GET['s'] );
		}

		// Get total count WITH filters for pagination
		$total_articles = $this->database->count_articles_with_filters( $filters );
		$total_pages    = $total_articles > 0 ? ceil( $total_articles / $per_page ) : 0;

		// Get total count WITHOUT filters to check if any articles exist
		$total_articles_unfiltered = $this->database->count_articles_with_filters( array() );
		$has_filters_active        = ! empty( $filters );

		$articles               = $this->database->get_articles_with_scores( $per_page, $offset, $filters );
		$prioritization_enabled = ! freshrank_is_free_version() && get_option( 'freshrank_prioritization_enabled', false );

		// BATCH FETCH: Get all analyses and drafts in one query to avoid N+1 problem
		$post_ids       = wp_list_pluck( $articles, 'ID' );
		$analyses_batch = $this->database->get_analyses_batch( $post_ids );
		$drafts_batch   = $this->database->get_drafts_batch( $post_ids );

		// Count analyzed articles
		$analyzed_count          = 0;
		$this->actionable_counts = array();
		$has_actionable_articles = false;

		foreach ( $articles as $article ) {
			$analysis      = isset( $analyses_batch[ $article->ID ] ) ? $analyses_batch[ $article->ID ] : null;
			$analysis_data = array();

			if ( $analysis && isset( $analysis->analysis_data ) ) {
				$analysis_data = is_array( $analysis->analysis_data )
					? $analysis->analysis_data
					: json_decode( $analysis->analysis_data, true );

				if ( ! is_array( $analysis_data ) ) {
					$analysis_data = array();
				} else {
					// Ensure downstream renders use the decoded array
					$analyses_batch[ $article->ID ]->analysis_data = $analysis_data;
				}
			}

			if ( $analysis && $analysis->status === 'completed' ) {
				++$analyzed_count;
			}

			if ( $analysis && $analysis->status === 'completed' && ! empty( $analysis_data ) ) {
				$counts = $this->data_provider->calculate_actionable_counts( $analysis_data, $article->ID );
			} else {
				$counts = array(
					'total_actionable' => 0,
					'total_filtered'   => 0,
					'total_dismissed'  => 0,
					'categories'       => array(),
				);
			}

			$this->actionable_counts[ $article->ID ] = $counts;

			if ( $counts['total_actionable'] > 0 ) {
				$has_actionable_articles = true;
			}
		}

		?>
		<div class="wsau-state-container">
			<h2><?php _e( 'Published Articles', 'freshrank-ai' ); ?></h2>

			<?php if ( empty( get_option( 'freshrank_openai_api_key', '' ) ) && ! get_user_meta( get_current_user_id(), 'freshrank_dismiss_api_notice', true ) ) : ?>
				<div class="notice notice-warning is-dismissible" id="freshrank-api-notice">
					<p>
						<?php _e( 'OpenAI API key is not configured. Please configure it in settings to enable content analysis.', 'freshrank-ai' ); ?>
						<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings' ); ?>" class="button button-small" style="margin-left: 10px;">
							<?php _e( 'Go to Settings', 'freshrank-ai' ); ?>
						</a>
					</p>
				</div>
				<script>
				jQuery(document).ready(function($) {
					$('#freshrank-api-notice').on('click', '.notice-dismiss', function() {
						$.post(ajaxurl, {
							action: 'freshrank_dismiss_api_notice',
							nonce: '<?php echo wp_create_nonce( 'freshrank_dismiss_notice' ); ?>'
						});
					});
				});
				</script>
			<?php endif; ?>

			<?php if ( $prioritization_enabled && ! get_option( 'freshrank_gsc_authenticated', false ) ) : ?>
				<div class="notice notice-warning">
					<p><?php _e( 'Prioritization is enabled but Google Search Console is not authenticated. Please configure GSC in settings or disable prioritization.', 'freshrank-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Check for articles currently creating drafts in background
			global $wpdb;
			$analysis_table  = $wpdb->prefix . 'freshrank_analysis';
			$creating_drafts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.post_id, p.post_title
					FROM {$analysis_table} a
					LEFT JOIN {$wpdb->posts} p ON a.post_id = p.ID
					WHERE a.status = %s
					AND p.post_status = %s
					ORDER BY a.updated_at DESC",
					'creating_draft',
					'publish'
				)
			);

			if ( ! empty( $creating_drafts ) ) :
				?>
				<div class="notice notice-info" style="border-left-color: #f0b849;">
					<p>
						<span class="dashicons dashicons-update spin" style="margin-right: 5px;"></span>
						<strong><?php _e( 'Background Processing:', 'freshrank-ai' ); ?></strong>
						<?php
						printf(
							// translators: %d is the number of articles
							_n( '%d article is currently having its draft created in the background.', '%d articles are currently having their drafts created in the background.', count( $creating_drafts ), 'freshrank-ai' ),
							count( $creating_drafts )
						);
						?>
						<?php _e( 'This may take 1-3 minutes per article. Refresh this page to check progress.', 'freshrank-ai' ); ?>
					</p>
					<ul style="margin-left: 30px; margin-top: 10px;">
						<?php foreach ( $creating_drafts as $draft ) : ?>
							<li>
								<strong><?php echo esc_html( $draft->post_title ); ?></strong>
								<span style="color: #f0b849;">(<?php _e( 'Processing...', 'freshrank-ai' ); ?>)</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! freshrank_is_free_version() ) : ?>
			<!-- Bulk Actions Bar (Pro Only) -->
			<div class="wsau-actions-bar">
				<!-- Workflow hint -->
				<div class="wsau-workflow-hint">
					<span class="dashicons dashicons-info-outline"></span>
					<?php _e( 'Workflow: Analyze → Create Drafts → Approve', 'freshrank-ai' ); ?>
				</div>

				<!-- Group 1: Analysis Actions -->
				<div class="wsau-action-group">
					<span class="wsau-action-group-label">
						<span class="wsau-step-number">1</span>
						<?php _e( 'Analysis', 'freshrank-ai' ); ?>
					</span>
					<div class="wsau-action-group-buttons">
						<?php if ( $prioritization_enabled && get_option( 'freshrank_gsc_authenticated', false ) ) : ?>
							<button id="freshrank-start-prioritization" class="button" title="<?php esc_attr_e( 'Fetch GSC data and prioritize articles by traffic opportunity', 'freshrank-ai' ); ?>">
								<span class="dashicons dashicons-update"></span>
								<?php _e( 'Prioritize', 'freshrank-ai' ); ?>
							</button>
						<?php endif; ?>
						<button id="freshrank-analyze-all-articles" class="button button-primary" title="<?php esc_attr_e( 'Analyze all articles for SEO issues and optimization opportunities', 'freshrank-ai' ); ?>">
							<span class="dashicons dashicons-analytics"></span>
							<?php _e( 'Analyze Current Page', 'freshrank-ai' ); ?>
						</button>
						<button id="freshrank-analyze-selected" class="button" title="<?php esc_attr_e( 'Analyze only the selected articles', 'freshrank-ai' ); ?>">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php _e( 'Analyze Selected', 'freshrank-ai' ); ?>
						</button>
					</div>
				</div>

				<!-- Group 2: Draft Creation Actions -->
				<?php if ( $analyzed_count > 0 ) : ?>
					<div class="wsau-action-group">
						<span class="wsau-action-group-label">
							<span class="wsau-step-number">2</span>
							<?php _e( 'Draft Creation', 'freshrank-ai' ); ?>
						</span>
						<div class="wsau-action-group-buttons">
							<button id="freshrank-update-all-articles" class="button button-primary" title="<?php esc_attr_e( 'Create draft updates for all analyzed articles', 'freshrank-ai' ); ?>">
								<span class="dashicons dashicons-edit"></span>
								<?php _e( 'Create for This Page', 'freshrank-ai' ); ?>
							</button>
							<button id="freshrank-update-selected" class="button" title="<?php esc_attr_e( 'Create drafts for selected articles only', 'freshrank-ai' ); ?>">
								<span class="dashicons dashicons-welcome-write-blog"></span>
								<?php _e( 'Create Selected', 'freshrank-ai' ); ?>
							</button>
						</div>
						<?php if ( ! $has_actionable_articles ) : ?>
							<p class="description" style="margin-top:6px; color:#666; max-width:280px;">
								<?php _e( 'No actionable issues currently match the selected filters. Draft creation will skip articles without actionable findings.', 'freshrank-ai' ); ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="wsau-list-controls">
				<p class="description">
					<?php _e( 'You can select articles using checkboxes and analyze them individually, or analyze all articles at once.', 'freshrank-ai' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $total_articles_unfiltered === 0 ) : ?>
				<!-- No articles exist at all -->
				<div class="wsau-empty-state">
					<div class="wsau-empty-state-content">
						<span class="dashicons dashicons-admin-post"></span>
						<h3><?php _e( 'No Published Articles Found', 'freshrank-ai' ); ?></h3>
						<p><?php _e( 'FreshRank AI analyzes your published articles to help improve their SEO and content quality. Create your first article to get started.', 'freshrank-ai' ); ?></p>
						<a href="<?php echo admin_url( 'post-new.php' ); ?>" class="button button-primary button-hero">
							<span class="dashicons dashicons-plus"></span>
							<?php _e( 'Create Your First Article', 'freshrank-ai' ); ?>
						</a>
					</div>
				</div>
			<?php else : ?>
				<!-- Articles exist - always show filters -->
				<?php $this->filters->render_filters(); ?>

				<?php if ( empty( $articles ) && $has_filters_active ) : ?>
					<!-- Articles exist but filter returns no results -->
					<div class="wsau-empty-state" style="margin-top: 20px;">
						<div class="wsau-empty-state-content">
							<span class="dashicons dashicons-filter" style="font-size: 48px; color: #666;"></span>
							<h3><?php _e( 'No Articles Match Your Filters', 'freshrank-ai' ); ?></h3>
							<p>
								<?php
								printf(
									// translators: %d is the total number of published articles
									__( 'No articles found matching the current filters. You have %d published articles in total.', 'freshrank-ai' ),
									$total_articles_unfiltered
								);
								?>
							</p>
							<p>
								<a href="<?php echo admin_url( 'admin.php?page=freshrank-ai' ); ?>" class="button button-secondary">
									<span class="dashicons dashicons-dismiss" style="margin-top: 4px;"></span>
									<?php _e( 'Clear All Filters', 'freshrank-ai' ); ?>
								</a>
							</p>
						</div>
					</div>
				<?php else : ?>
					<!-- Show articles table -->
					<?php $this->render_articles_table( $articles, 'list', $total_pages, $per_page, $offset, $total_articles, $analyses_batch, $drafts_batch ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render articles table
	 */
	private function render_articles_table( $articles, $state, $total_pages = 1, $per_page = 25, $offset = 0, $total_articles = 0, $analyses_batch = array(), $drafts_batch = array() ) {
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		?>
		<div class="wsau-table-container">
			<table class="wp-list-table widefat fixed striped" id="wsau-articles-table">
				<thead>
					<tr>
						<td class="check-column">
							<input type="checkbox" id="wsau-select-all">
						</td>

						<?php
						// Get current sorting parameters
						$current_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : '';
						$current_order   = isset( $_GET['order'] ) && $_GET['order'] === 'asc' ? 'asc' : 'desc';
						$next_order      = $current_order === 'desc' ? 'asc' : 'desc';

						// Helper function to generate sortable header
						$generate_sortable_header = function ( $column, $label, $default_order = 'desc' ) use ( $current_orderby, $current_order, $next_order ) {
							$is_active = ( $current_orderby === $column );
							$order     = $is_active ? $next_order : $default_order;
							$arrow     = $is_active ? ( $current_order === 'desc' ? ' ▼' : ' ▲' ) : '';
							$url       = add_query_arg(
								array(
									'orderby' => $column,
									'order'   => $order,
								)
							);
							return sprintf(
								'<a href="%s" style="text-decoration: none; color: inherit; font-weight: 600;">%s%s</a>',
								esc_url( $url ),
								esc_html( $label ),
								$arrow
							);
						};
		?>
						<th class="column-title-enhanced"><?php _e( 'Article', 'freshrank-ai' ); ?></th>
						<?php if ( get_option( 'freshrank_prioritization_enabled', false ) ) : ?>
							<th class="column-priority sortable">
								<?php echo $generate_sortable_header( 'priority', __( 'Priority', 'freshrank-ai' ) ); ?>
							</th>
						<?php endif; ?>
						<th class="column-analysis-status sortable">
							<?php echo $generate_sortable_header( 'analysis', __( 'Analysis', 'freshrank-ai' ) ); ?>
						</th>
						<th class="column-status"><?php _e( 'Status', 'freshrank-ai' ); ?></th>
						<th class="column-actions"><?php _e( 'Actions', 'freshrank-ai' ); ?></th>
					</tr>
				</thead>
				<tbody id="wsau-sortable-articles">
					<?php foreach ( $articles as $article ) : ?>
						<?php $this->render_article_row( $article, $state, $analyses_batch, $drafts_batch ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="freshrank-pagination">
					<div class="pagination-info">
						<?php
						$start = $offset + 1;
						$end   = min( $offset + $per_page, $total_articles );
						printf(
							// translators: %1$d is the start number, %2$d is the end number, %3$d is the total number of articles
							__( 'Showing %1$d-%2$d of %3$d articles', 'freshrank-ai' ),
							$start,
							$end,
							$total_articles
						);
						?>
					</div>

					<div class="pagination-controls">
						<?php
						$base_url = remove_query_arg( array( 'paged' ) );

						// Previous button
						if ( $current_page > 1 ) :
							$prev_url = add_query_arg( 'paged', $current_page - 1, $base_url );
							?>
							<a href="<?php echo esc_url( $prev_url ); ?>" class="button">&laquo; <?php _e( 'Previous', 'freshrank-ai' ); ?></a>
						<?php else : ?>
							<span class="button disabled">&laquo; <?php _e( 'Previous', 'freshrank-ai' ); ?></span>
						<?php endif; ?>

						<!-- Page numbers -->
						<span class="pagination-pages">
							<?php
							$range = 2;
							for ( $i = 1; $i <= $total_pages; $i++ ) {
								if ( $i == 1 || $i == $total_pages || ( $i >= $current_page - $range && $i <= $current_page + $range ) ) {
									$page_url = add_query_arg( 'paged', $i, $base_url );
									if ( $i == $current_page ) {
										echo '<span class="current-page">' . $i . '</span>';
									} else {
										echo '<a href="' . esc_url( $page_url ) . '">' . $i . '</a>';
									}
								} elseif ( $i == $current_page - $range - 1 || $i == $current_page + $range + 1 ) {
									echo '<span class="pagination-ellipsis">...</span>';
								}
							}
							?>
						</span>

						<!-- Next button -->
						<?php
						if ( $current_page < $total_pages ) :
							$next_url = add_query_arg( 'paged', $current_page + 1, $base_url );
							?>
							<a href="<?php echo esc_url( $next_url ); ?>" class="button"><?php _e( 'Next', 'freshrank-ai' ); ?> &raquo;</a>
						<?php else : ?>
							<span class="button disabled"><?php _e( 'Next', 'freshrank-ai' ); ?> &raquo;</span>
						<?php endif; ?>
					</div>

					<div class="pagination-per-page">
						<label><?php _e( 'Per page:', 'freshrank-ai' ); ?></label>
						<select id="freshrank-per-page" data-current="<?php echo $per_page; ?>">
							<?php foreach ( array( 10, 25, 50, 100 ) as $option ) : ?>
								<option value="<?php echo $option; ?>" <?php selected( $per_page, $option ); ?>>
									<?php echo $option; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render single article row
	 */
	private function render_article_row( $article, $state, $analyses_batch = array(), $drafts_batch = array() ) {
		$post_id = $article->ID;

		// Use batched data if available, otherwise fall back to individual queries
		if ( ! empty( $analyses_batch ) ) {
			$analysis = isset( $analyses_batch[ $post_id ] ) ? $analyses_batch[ $post_id ] : null;
		} else {
			$analysis = $this->database->get_analysis( $post_id );
		}

		if ( ! empty( $drafts_batch ) ) {
			$draft_info = isset( $drafts_batch[ $post_id ] ) ? $drafts_batch[ $post_id ] : null;
		} else {
			$draft_info = $this->data_provider->get_draft_info( $post_id );
		}

		$analysis_data = array();
		if ( $analysis && isset( $analysis->analysis_data ) ) {
			$analysis_data = is_array( $analysis->analysis_data )
				? $analysis->analysis_data
				: json_decode( $analysis->analysis_data, true );

			if ( ! is_array( $analysis_data ) ) {
				$analysis_data = array();
			} else {
				$analysis->analysis_data = $analysis_data;
			}
		}

		// Check for legacy revision-only draft system
		$last_ai_update     = get_post_meta( $post_id, '_freshrank_last_ai_update', true );
		$has_revision_draft = ! empty( $last_ai_update );

		$prioritization_enabled = ! freshrank_is_free_version() && get_option( 'freshrank_prioritization_enabled', false );

		// Build row classes
		$row_classes = array( 'freshrank-article-row', 'wsau-article-row' );
		if ( $draft_info ) {
			$row_classes[] = 'wsau-has-draft';
		}
		if ( $analysis && $analysis->status === 'analyzing' ) {
			$row_classes[] = 'wsau-analyzing-row';
		}
		if ( $analysis && $analysis->status === 'creating_draft' ) {
			$row_classes[] = 'wsau-creating-draft-row';
		}

		?>
		<tr data-post-id="<?php echo $post_id; ?>" class="<?php echo implode( ' ', $row_classes ); ?>">
			<th class="check-column">
				<input type="checkbox" name="article_ids[]" value="<?php echo $post_id; ?>" class="freshrank-article-checkbox wsau-article-checkbox">
			</th>

			<!-- Article Title + Metadata -->
			<td data-label="<?php _e( 'Article', 'freshrank-ai' ); ?>" class="column-title-enhanced">
				<div class="wsau-article-header">
					<strong class="wsau-article-title">
						<a href="<?php echo get_edit_post_link( $post_id ); ?>" target="_blank">
							<?php echo esc_html( $article->post_title ); ?>
						</a>
					</strong>
				</div>

				<div class="wsau-article-meta">
					<span class="wsau-meta-item">
						<span class="dashicons dashicons-calendar-alt"></span>
						<span class="wsau-meta-label"><?php _e( 'Published:', 'freshrank-ai' ); ?></span>
						<?php echo mysql2date( get_option( 'date_format' ), $article->post_date ); ?>
					</span>
					<span class="wsau-meta-separator">•</span>
					<span class="wsau-meta-item">
						<span class="dashicons dashicons-update"></span>
						<span class="wsau-meta-label"><?php _e( 'Modified:', 'freshrank-ai' ); ?></span>
						<?php echo mysql2date( get_option( 'date_format' ), $article->post_modified ); ?>
					</span>
				</div>

				<div class="row-actions">
					<span class="edit">
						<a href="<?php echo get_edit_post_link( $post_id ); ?>" target="_blank"><?php _e( 'Edit', 'freshrank-ai' ); ?></a> |
					</span>
					<span class="view">
						<a href="<?php echo get_permalink( $post_id ); ?>" target="_blank"><?php _e( 'View', 'freshrank-ai' ); ?></a>
					</span>

					<?php if ( $draft_info || $has_revision_draft ) : ?>
						| <span class="clear-draft">
							<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=freshrank-ai&action=clear_draft&post_id=' . $post_id ), 'clear_draft_' . $post_id ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear this draft? This will delete the draft post and all related data.', 'freshrank-ai' ) ); ?>');">
								<?php _e( 'Clear Draft', 'freshrank-ai' ); ?>
							</a>
						</span>
					<?php endif; ?>

					<?php if ( $analysis && $analysis->status === 'completed' ) : ?>
						| <span class="clear">
							<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=freshrank-ai&action=clear_analysis&post_id=' . $post_id ), 'clear_analysis_' . $post_id ); ?>">
								<?php _e( 'Clear Analysis', 'freshrank-ai' ); ?>
							</a>
						</span>
					<?php endif; ?>
				</div>
			</td>

			<!-- Priority Score Column -->
			<?php if ( $prioritization_enabled ) : ?>
				<td data-label="<?php _e( 'Priority', 'freshrank-ai' ); ?>" class="column-priority">
					<div style="display: flex; align-items: center; min-height: 60px; gap: 12px; pointer-events: auto;">
						<?php if ( $article->priority_score > 0 ) : ?>
							<div style="font-size: 15px; font-weight: 600; color: 
							<?php
								$score_color = '#999';
							if ( $article->priority_score >= 60 ) {
								$score_color = '#d63638'; // Red (high priority)
							} elseif ( $article->priority_score >= 40 ) {
								$score_color = '#f57c00'; // Orange (medium priority)
							} elseif ( $article->priority_score >= 20 ) {
								$score_color = '#0073aa'; // Blue (low priority)
							}
								echo $score_color;
							?>
							;">
								<?php echo number_format( $article->priority_score, 0 ); ?><span style="color: #666; font-weight: 400;">/90</span>
							</div>
						<?php else : ?>
							<span style="font-size: 15px; color: #666;">—</span>
						<?php endif; ?>
						<?php if ( $article->priority_score > 0 ) : ?>
							<button type="button" class="button-link freshrank-toggle-priority-details-inline"
									data-post-id="<?php echo $post_id; ?>"
									aria-expanded="false"
									aria-controls="priority-details-<?php echo $post_id; ?>"
									style="font-size: 11px; color: #2271b1; text-decoration: none; cursor: pointer !important;"
									aria-label="
									<?php
									echo esc_attr(
										sprintf(
										// translators: %s is the article title
											__( 'View priority score details for %s', 'freshrank-ai' ),
											$article->post_title
										)
									);
									?>
										">
								<?php _e( 'View Details', 'freshrank-ai' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</td>
			<?php endif; ?>

			<!-- Analysis Results -->
			<td data-label="<?php _e( 'Analysis', 'freshrank-ai' ); ?>" class="column-analysis-status">
				<?php if ( $analysis && $analysis->status === 'completed' ) : ?>
					<?php
					// Get overall score from analysis data
					$analysis_data = is_string( $analysis->analysis_data ) ? json_decode( $analysis->analysis_data, true ) : $analysis->analysis_data;
					$overall_score = isset( $analysis_data['overall_score']['overall_score'] ) ? $analysis_data['overall_score']['overall_score'] : null;
					$score_color   = '#999';
					if ( $overall_score !== null ) {
						if ( $overall_score >= 70 ) {
							$score_color = '#46b450'; // Green
						} elseif ( $overall_score >= 40 ) {
							$score_color = '#f57c00'; // Orange
						} else {
							$score_color = '#d63638'; // Red
						}
					}
					?>
					<div style="display: flex; align-items: center; min-height: 60px; gap: 12px;">
						<?php if ( $overall_score !== null ) : ?>
							<div style="font-size: 15px; font-weight: 600; color: <?php echo $score_color; ?>;">
								<?php echo $overall_score; ?><span style="color: #666; font-weight: 400;">/100</span>
							</div>
						<?php else : ?>
							<span style="font-size: 15px; color: #666;">—</span>
						<?php endif; ?>
						<?php if ( $analysis->issues_count > 0 ) : ?>
							<button type="button" class="button-link freshrank-toggle-issues"
									data-post-id="<?php echo $post_id; ?>"
									aria-expanded="false"
									aria-controls="analysis-details-<?php echo $post_id; ?>"
									style="font-size: 11px; color: #2271b1; text-decoration: none;"
									aria-label="
									<?php
									echo esc_attr(
										sprintf(
										// translators: %s is the article title
											__( 'View analysis details for %s', 'freshrank-ai' ),
											$article->post_title
										)
									);
									?>
										">
								<?php _e( 'View Details', 'freshrank-ai' ); ?>
							</button>
						<?php endif; ?>
					</div>
				<?php elseif ( $analysis && $analysis->status === 'analyzing' ) : ?>
					<?php
					// Check if analysis is actually running or stale
					$analyzing_timestamp = get_transient( 'freshrank_analyzing_' . $post_id );
					$is_analyzing_active = ( $analyzing_timestamp !== false );

					if ( $is_analyzing_active ) :
						?>
						<div class="wsau-analyzing-feedback" style="color: #0073aa; font-style: italic; display: flex; align-items: center; min-height: 60px; gap: 8px;">
							<span class="dashicons dashicons-update spin" style="font-size: 16px; width: 16px; height: 16px;"></span>
							<span><?php _e( 'Analyzing...', 'freshrank-ai' ); ?></span>
						</div>
					<?php else : ?>
						<div class="wsau-analysis-stale" style="color: #d63638; font-style: italic; display: flex; align-items: center; min-height: 60px; gap: 8px;">
							<span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span>
							<span><?php _e( 'Interrupted', 'freshrank-ai' ); ?></span>
						</div>
					<?php endif; ?>
				<?php elseif ( $analysis && $analysis->status === 'error' ) : ?>
					<div class="wsau-analysis-error" style="color: #d63638; display: flex; align-items: center; min-height: 60px; gap: 8px;">
						<span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span>
						<span><?php _e( 'Failed', 'freshrank-ai' ); ?></span>
					</div>
				<?php else : ?>
					<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">
						<span class="dashicons dashicons-minus" style="font-size: 14px; width: 14px; height: 14px; color: #999;"></span>
						<span style="color: #999;"><?php _e( 'Not analyzed', 'freshrank-ai' ); ?></span>
					</div>
				<?php endif; ?>
			</td>

			<!-- Status Column -->
			<td data-label="<?php _e( 'Status', 'freshrank-ai' ); ?>" class="column-status">
				<?php echo $this->get_article_status_badge( $article, $analysis, $draft_info ); ?>
			</td>

			<td data-label="<?php _e( 'Actions', 'freshrank-ai' ); ?>" class="column-actions">
				<?php $this->render_article_actions( $article, $analysis, $state, $draft_info ); ?>
			</td>
		</tr>
		
		<!-- Expandable priority details row -->
		<?php if ( $prioritization_enabled && $article->priority_score > 0 ) : ?>
			<tr class="freshrank-priority-details-row"
				id="priority-details-<?php echo $post_id; ?>"
				data-post-id="<?php echo $post_id; ?>"
				style="display: none;"
				role="region"
				aria-label="
				<?php
				echo esc_attr(
					sprintf(
					// translators: %s is the article title
						__( 'Priority score details for %s', 'freshrank-ai' ),
						$article->post_title
					)
				);
				?>
					">
				<td colspan="<?php echo ( $prioritization_enabled ? 6 : 5 ); ?>">
					<div class="freshrank-priority-details-content">
						<?php $this->render_priority_score_details( $article ); ?>
					</div>
				</td>
			</tr>
		<?php endif; ?>

		<!-- Expandable analysis details row -->
		<?php if ( $analysis && $analysis->status === 'completed' && $analysis->issues_count > 0 ) : ?>
			<tr class="freshrank-analysis-details-row"
				id="analysis-details-<?php echo $post_id; ?>"
				data-post-id="<?php echo $post_id; ?>"
				style="display: none;"
				role="region"
				aria-label="
				<?php
				echo esc_attr(
					sprintf(
					// translators: %s is the article title
						__( 'Analysis details for %s', 'freshrank-ai' ),
						$article->post_title
					)
				);
				?>
					">
				<td colspan="<?php echo ( $prioritization_enabled ? 6 : 5 ); ?>">
					<div class="freshrank-analysis-details-content">
						<?php $this->render_detailed_analysis( $analysis, $post_id ); ?>
					</div>
				</td>
			</tr>
		<?php endif; ?>

		<!-- Expandable draft details row -->
		<?php if ( $has_revision_draft || $draft_info ) : ?>
			<tr class="freshrank-draft-details-row"
				id="draft-details-<?php echo $post_id; ?>"
				data-post-id="<?php echo $post_id; ?>"
				style="display: none;"
				role="region"
				aria-label="
				<?php
				echo esc_attr(
					sprintf(
					// translators: %s is the article title
						__( 'Draft details for %s', 'freshrank-ai' ),
						$article->post_title
					)
				);
				?>
					">
				<td colspan="<?php echo ( $prioritization_enabled ? 6 : 5 ); ?>">
					<div class="freshrank-draft-details-content">
						<?php
						// Unified system: always use draft_info if available (which includes both draft post + revision)
						if ( $draft_info ) {
							$this->render_unified_draft_info( $post_id, $draft_info );
						} elseif ( $has_revision_draft ) {
							// Legacy: old revision-only system (for backwards compatibility)
							$this->render_revision_draft_info( $post_id );
						}
						?>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<?php
	}

	/**
	 * Get priority class for styling
	 */
	/**
	 * Get article status badge with improved visual feedback
	 */
	private function get_article_status_badge( $article, $analysis, $draft_info = null ) {
		$post_id = $article->ID;

		$flag = static function ( $class_name ) {
			return '<span class="freshrank-status-flag ' . esc_attr( $class_name ) . '" style="display:none;"></span>';
		};

		// Check for revision-based draft OR draft post
		$last_ai_update = get_post_meta( $post_id, '_freshrank_last_ai_update', true );
		$has_draft_post = ( $draft_info && ! empty( $draft_info['draft_id'] ) );
		$has_draft      = ( $last_ai_update || $has_draft_post );

		if ( $has_draft ) {
			$output  = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">';
			$output .= '<span class="dashicons dashicons-edit" style="color: #2271b1; font-size: 18px; width: 18px; height: 18px;"></span>';
			$output .= '<button type="button" class="button-link freshrank-toggle-draft-details" data-post-id="' . esc_attr( $post_id ) . '" style="font-size: 13px; text-decoration: none; color: #2271b1;">';
			$output .= esc_html__( 'View Draft Details', 'freshrank-ai' );
			$output .= '</button>';
			$output .= $flag( 'freshrank-status-draft' );
			$output .= '</div>';

			return $output;
		}

		if ( ! $analysis ) {
			return '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' .
				'<span class="dashicons dashicons-minus" style="font-size: 14px; width: 14px; height: 14px; color: #999;"></span>' .
				'<span style="color: #999;" title="' . esc_attr__( 'Not analyzed yet - click Analyze to start', 'freshrank-ai' ) . '">' . __( 'Pending', 'freshrank-ai' ) . '</span>' .
				$flag( 'freshrank-status-pending' ) .
				'</div>';
		}

		$base_style = 'display: flex; align-items: center; min-height: 60px; gap: 8px;';

		switch ( $analysis->status ) {
			case 'analyzing':
				return '<div style="' . $base_style . '">' .
					'<span class="dashicons dashicons-update spin" style="font-size: 14px; width: 14px; height: 14px; color: #0073aa;"></span>' .
					'<span style="color: #0073aa;" title="' . esc_attr__( 'Analysis in progress - this may take 30-60 seconds', 'freshrank-ai' ) . '">' . __( 'Analyzing...', 'freshrank-ai' ) . '</span>' .
					$flag( 'freshrank-status-analyzing' ) .
					'</div>';
			case 'creating_draft':
				return '<div style="' . $base_style . '">' .
					'<span class="dashicons dashicons-edit spin" style="font-size: 14px; width: 14px; height: 14px; color: #0073aa;"></span>' .
					'<span style="color: #0073aa;" title="' . esc_attr__( 'Creating draft in background - refresh page to check progress', 'freshrank-ai' ) . '">' . __( 'Creating Draft...', 'freshrank-ai' ) . '</span>' .
					$flag( 'freshrank-status-creating' ) .
					'</div>';
			case 'completed':
				return '<div style="' . $base_style . '">' .
					'<span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px; color: #46b450;"></span>' .
					'<span style="color: #46b450;" title="' . esc_attr__( 'Analysis complete - ready to create draft', 'freshrank-ai' ) . '">' . __( 'Ready', 'freshrank-ai' ) . '</span>' .
					$flag( 'freshrank-status-completed' ) .
					'</div>';
			case 'error':
				$error_msg = isset( $analysis->error_message ) ? $analysis->error_message : __( 'Unknown error', 'freshrank-ai' );
				return '<div style="' . $base_style . '">' .
					'<span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px; color: #d63638;"></span>' .
					'<span style="color: #d63638;" title="' . esc_attr(
						sprintf(
						// translators: %s is the error message
							__( 'Error: %s - Click Analyze to retry', 'freshrank-ai' ),
							$error_msg
						)
					) . '">' . __( 'Error', 'freshrank-ai' ) . '</span>' .
					$flag( 'freshrank-status-error' ) .
					'</div>';
			default:
				return '<div style="' . $base_style . '">' .
					'<span class="dashicons dashicons-minus" style="font-size: 14px; width: 14px; height: 14px; color: #999;"></span>' .
					'<span style="color: #999;" title="' . esc_attr__( 'Not analyzed yet - click Analyze to start', 'freshrank-ai' ) . '">' . __( 'Pending', 'freshrank-ai' ) . '</span>' .
					$flag( 'freshrank-status-pending' ) .
					'</div>';
		}
	}

	/**
	 * Render article actions
	 */
	private function render_article_actions( $article, $analysis, $state, $draft_info = null ) {
		$post_id = $article->ID;

		?>
		<div class="wsau-actions">
			<?php
			// Check if draft exists (unified system creates both draft post + revision)
			$has_draft = ( $draft_info && ! empty( $draft_info['draft_id'] ) );
			?>

			<?php if ( $has_draft ) : ?>
				<!-- Draft post exists -->
				<?php
				// Get draft metadata
				$draft_post   = get_post( $draft_info['draft_id'] );
				$created_date = $draft_post ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $draft_post->post_modified ) ) : __( 'Unknown', 'freshrank-ai' );

				// Get severity data and additional metadata from draft meta
				$update_severity  = get_post_meta( $draft_info['draft_id'], '_freshrank_update_severity', true );
				$severity_summary = get_post_meta( $draft_info['draft_id'], '_freshrank_severity_summary', true );
				$changes_count    = 0;
				if ( ! empty( $update_severity ) && is_array( $update_severity ) ) {
					$changes_count = count( $update_severity );
				}

				// Get additional change details from draft meta
				$seo_improvements = get_post_meta( $draft_info['draft_id'], '_freshrank_seo_improvements', true );
				$content_updates  = get_post_meta( $draft_info['draft_id'], '_freshrank_content_updates', true );
				$update_summary   = get_post_meta( $draft_info['draft_id'], '_freshrank_update_summary', true );

				// Get URLs for preview and edit
				$preview_url = get_preview_post_link( $draft_info['draft_id'] );
				$edit_url    = get_edit_post_link( $draft_info['draft_id'], 'raw' );
				?>

				<button class="button button-primary freshrank-approve-draft"
						data-draft-id="<?php echo $draft_info['draft_id']; ?>"
						data-original-id="<?php echo $post_id; ?>">
					<span class="dashicons dashicons-yes"></span>
					<?php _e( 'Approve Draft', 'freshrank-ai' ); ?>
				</button>

				<button class="button freshrank-reject-draft"
						data-draft-id="<?php echo $draft_info['draft_id']; ?>">
					<span class="dashicons dashicons-no"></span>
					<?php _e( 'Reject Draft', 'freshrank-ai' ); ?>
				</button>

				<div class="freshrank-draft-details" id="freshrank-draft-details-<?php echo $post_id; ?>" style="display: none; margin-top: 12px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1; border-radius: 4px;">
					<h4 style="margin: 0 0 12px 0; font-size: 14px;"><?php _e( 'Draft Details', 'freshrank-ai' ); ?></h4>

					<div style="margin-bottom: 16px;">
						<strong style="display: block; margin-bottom: 8px; font-size: 13px;"><?php _e( 'Changes Summary', 'freshrank-ai' ); ?></strong>

						<?php if ( ! empty( $severity_summary ) ) : ?>
							<div style="margin-bottom: 8px; padding: 6px 10px; background: #fff; border-left: 3px solid #2271b1; font-size: 12px;">
								<strong><?php _e( 'Severity Filters Applied:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $severity_summary ); ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $update_summary ) ) : ?>
							<div style="margin-bottom: 12px; padding: 8px 10px; background: #fff; border-radius: 3px; font-size: 12px; line-height: 1.5;">
								<?php echo esc_html( $update_summary ); ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $update_severity ) && is_array( $update_severity ) ) : ?>
							<div style="margin-bottom: 12px;">
								<strong style="display: block; margin-bottom: 6px; font-size: 12px; color: #2c3338;">
									<?php
									printf(
										// translators: %d is the number of issues addressed
										_n( '%d Issue Addressed:', '%d Issues Addressed:', count( $update_severity ), 'freshrank-ai' ),
										count( $update_severity )
									);
									?>
								</strong>
								<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
									<?php foreach ( $update_severity as $issue ) : ?>
										<li style="margin-bottom: 4px;"><?php echo esc_html( $issue ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $seo_improvements ) && is_array( $seo_improvements ) && count( $seo_improvements ) > 0 ) : ?>
							<div style="margin-bottom: 12px;">
								<strong style="display: block; margin-bottom: 6px; font-size: 12px; color: #2c3338;">
									<span class="dashicons dashicons-search" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
									<?php _e( 'SEO Improvements:', 'freshrank-ai' ); ?>
								</strong>
								<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
									<?php foreach ( $seo_improvements as $improvement ) : ?>
										<li style="margin-bottom: 4px;"><?php echo esc_html( $improvement ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $content_updates ) && is_array( $content_updates ) && count( $content_updates ) > 0 ) : ?>
							<div style="margin-bottom: 12px;">
								<strong style="display: block; margin-bottom: 6px; font-size: 12px; color: #2c3338;">
									<span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
									<?php _e( 'Content Updates:', 'freshrank-ai' ); ?>
								</strong>
								<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
									<?php foreach ( $content_updates as $update ) : ?>
										<li style="margin-bottom: 4px;"><?php echo esc_html( $update ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( empty( $update_severity ) && empty( $seo_improvements ) && empty( $content_updates ) && empty( $update_summary ) ) : ?>
							<div style="padding: 8px 10px; background: #fff; border-radius: 3px; font-size: 12px; color: #646970;">
								<?php _e( 'No detailed change information available. Review the draft to see changes.', 'freshrank-ai' ); ?>
							</div>
						<?php endif; ?>
					</div>

					<div style="margin-bottom: 12px;">
						<strong><?php _e( 'Created:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $created_date ); ?>
					</div>

					<div style="border-top: 1px solid #dcdcde; padding-top: 12px; margin-top: 12px;">
						<strong style="display: block; margin-bottom: 8px; font-size: 13px;"><?php _e( 'Actions', 'freshrank-ai' ); ?></strong>
						<div style="display: flex; gap: 8px; flex-wrap: wrap;">
							<?php
							// Get revision comparison URL
							$ai_revision_id = get_post_meta( $post_id, '_freshrank_ai_revision_id', true );
							if ( $ai_revision_id ) {
								$compare_url = admin_url( 'revision.php?revision=' . $ai_revision_id );
								?>
							<a href="<?php echo esc_url( $compare_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1; font-size: 12px;">
								<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
								<?php _e( 'View Changes', 'freshrank-ai' ); ?>
							</a>
							<?php } ?>

							<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1; font-size: 12px;">
								<span class="dashicons dashicons-visibility" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
								<?php _e( 'Preview Draft', 'freshrank-ai' ); ?>
							</a>
							<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" class="button" style="background: #2271b1; color: #fff; border-color: #2271b1; font-size: 12px;">
								<span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
								<?php _e( 'Edit Draft', 'freshrank-ai' ); ?>
							</a>
						</div>
					</div>
				</div>

			<?php elseif ( isset( $article->draft_status ) && $article->draft_status === 'creating' ) : ?>
				<!-- Currently creating draft -->
				<span class="wsau-creating-draft">
					<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>
					<?php _e( 'Creating Draft...', 'freshrank-ai' ); ?>
				</span>

			<?php elseif ( ! $analysis || $analysis->status === 'pending' ) : ?>
				<!-- No analysis yet - show analyze button -->
				<button class="button freshrank-analyze-single" data-post-id="<?php echo $post_id; ?>">
					<?php _e( 'Analyze', 'freshrank-ai' ); ?>
				</button>

			<?php elseif ( $analysis->status === 'analyzing' ) : ?>
				<!-- Currently analyzing -->
				<?php
				// Check if analysis is stale (transient expired = process died)
				$analyzing_timestamp = get_transient( 'freshrank_analyzing_' . $post_id );
				$is_stale            = ( $analyzing_timestamp === false );

				if ( $is_stale ) :
					// Analysis was interrupted or is very old - reset to pending
					global $wpdb;
					$analysis_table = $wpdb->prefix . 'freshrank_analysis';

					// Check when this analysis was last updated
					$updated_at  = isset( $analysis->updated_at ) ? strtotime( $analysis->updated_at ) : 0;
					$age_minutes = ( $updated_at > 0 ) ? ( time() - $updated_at ) / 60 : 999;

					// Only show "interrupted" message if analysis was started recently (within 10 minutes)
					// Otherwise just silently reset to pending
					if ( $age_minutes < 10 ) :
						?>
						<span class="wsau-stale-analysis" style="color: #d63638; display: block; margin-bottom: 8px;">
							⚠️ <?php _e( 'Analysis timed out or was interrupted', 'freshrank-ai' ); ?>
						</span>
						<button class="button freshrank-analyze-single" data-post-id="<?php echo $post_id; ?>">
							<?php _e( 'Retry Analysis', 'freshrank-ai' ); ?>
						</button>
						<?php
					else :
						// Silently reset old stale analyses to pending
						$wpdb->update(
							$analysis_table,
							array( 'status' => 'pending' ),
							array( 'post_id' => $post_id ),
							array( '%s' ),
							array( '%d' )
						);
						?>
						<!-- Old stale analysis - reset to pending and show analyze button -->
						<button class="button freshrank-analyze-single" data-post-id="<?php echo $post_id; ?>">
							<?php _e( 'Analyze', 'freshrank-ai' ); ?>
						</button>
					<?php endif; ?>
				<?php else : ?>
					<span class="wsau-analyzing">
						<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
						<?php _e( 'Analyzing...', 'freshrank-ai' ); ?>
					</span>
				<?php endif; ?>

			<?php elseif ( $analysis->status === 'completed' ) : ?>
				<!-- Analysis completed -->
				<?php if ( $analysis->issues_count > 0 ) : ?>
					<?php
					if ( isset( $this->actionable_counts[ $post_id ] ) ) {
						$counts = $this->actionable_counts[ $post_id ];
					} elseif ( ! empty( $analysis_data ) && $analysis && $analysis->status === 'completed' ) {
						$counts = $this->data_provider->calculate_actionable_counts( $analysis_data, $post_id );
					} else {
						$counts = array(
							'total_actionable' => 0,
							'total_filtered'   => 0,
							'total_dismissed'  => 0,
							'categories'       => array(),
						);
					}

					$disabled     = empty( $counts['total_actionable'] );
					$button_attrs = 'class="button button-primary freshrank-update-single"';
					?>
					<button <?php echo $button_attrs; ?> data-post-id="<?php echo $post_id; ?>">
						<?php _e( 'Create Draft', 'freshrank-ai' ); ?>
					</button>
					<?php if ( $disabled ) : ?>
						<p style="margin:4px 0 0; color:#666; font-size:12px; max-width:260px;">
							<?php _e( 'No actionable issues match the current filters. Draft creation will fail until you adjust your AI settings or re-run analysis.', 'freshrank-ai' ); ?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<span class="wsau-no-issues"><?php _e( 'No issues found', 'freshrank-ai' ); ?></span>
				<?php endif; ?>

				<button class="button freshrank-re-analyze" data-post-id="<?php echo $post_id; ?>">
					<?php _e( 'Re-analyze', 'freshrank-ai' ); ?>
				</button>

			<?php elseif ( $analysis->status === 'error' ) : ?>
				<!-- Analysis failed -->
				<button class="button freshrank-retry-analysis" data-post-id="<?php echo $post_id; ?>">
					<?php _e( 'Retry', 'freshrank-ai' ); ?>
				</button>

				<span class="wsau-error-indicator" title="<?php echo esc_attr( $analysis->error_message ); ?>">
					<?php _e( 'Error occurred', 'freshrank-ai' ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render analysis summary for table display
	 */
	private function render_analysis_summary( $analysis ) {
		if ( ! $analysis || ! isset( $analysis->analysis_data ) ) {
			return;
		}

		$data       = $analysis->analysis_data;
		$categories = array();

		// Count issues by category (NEW CONSOLIDATED CATEGORIES)
		if ( ! empty( $data['factual_updates'] ) ) {
			$categories[] = count( $data['factual_updates'] ) . ' Factual';
		}
		if ( ! empty( $data['user_experience']['issues'] ) ) {
			$categories[] = count( $data['user_experience']['issues'] ) . ' UX';
		}
		if ( ! empty( $data['search_optimization'] ) ) {
			$categories[] = count( $data['search_optimization'] ) . ' SEO';
		}
		if ( ! empty( $data['ai_visibility']['issues'] ) ) {
			$categories[] = count( $data['ai_visibility']['issues'] ) . ' AI';
		}
		if ( ! empty( $data['opportunities'] ) ) {
			$categories[] = count( $data['opportunities'] ) . ' Opportunities';
		}

		// OLD CATEGORIES (backward compatibility for old analysis data)
		if ( ! empty( $data['factual_updates'] ) && empty( $data['content_quality'] ) ) {
			$categories[] = count( $data['factual_updates'] ) . ' Factual';
		}
		if ( ! empty( $data['seo_issues'] ) && empty( $data['search_optimization'] ) ) {
			$categories[] = count( $data['seo_issues'] ) . ' SEO';
		}

		if ( ! empty( $categories ) ) {
			echo '<div class="wsau-issues-breakdown">' . implode( ', ', $categories ) . '</div>';
		}

		// Show overall score if available
		if ( isset( $data['overall_score']['overall_score'] ) ) {
			$score       = $data['overall_score']['overall_score'];
			$score_class = $score >= 70 ? 'good' : ( $score >= 40 ? 'average' : 'poor' );
			echo '<div class="wsau-overall-score wsau-score-' . $score_class . '">Score: ' . $score . '/100</div>';
		}
	}

	/**
	 * Render detailed analysis in expandable row
	 */
	private function render_detailed_analysis( $analysis, $post_id ) {
		if ( ! $analysis || ! isset( $analysis->analysis_data ) ) {
			return;
		}

		$data = $analysis->analysis_data;

		// Calculate actionable counts
		$counts = $this->data_provider->calculate_actionable_counts( $data, $post_id );

		// Get user's view preference
		$user_id         = get_current_user_id();
		$view_preference = get_user_meta( $user_id, 'freshrank_analysis_view_preference', true );
		if ( empty( $view_preference ) ) {
			$view_preference = 'actionable'; // Default to actionable view
		}

		?>
		<div class="wsau-detailed-analysis" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<div class="freshrank-analysis-header">
				<h4><?php _e( 'Analysis Details', 'freshrank-ai' ); ?></h4>

				<div class="freshrank-analysis-controls">
					<!-- View Toggle -->
					<div class="freshrank-view-toggle">
						<label for="freshrank-view-<?php echo esc_attr( $post_id ); ?>">
							<?php _e( 'View:', 'freshrank-ai' ); ?>
						</label>
						<select id="freshrank-view-<?php echo esc_attr( $post_id ); ?>"
								class="freshrank-view-selector"
								data-post-id="<?php echo esc_attr( $post_id ); ?>">
							<option value="actionable" <?php selected( $view_preference, 'actionable' ); ?>>
								<?php _e( 'Actionable issues only', 'freshrank-ai' ); ?>
							</option>
							<option value="all" <?php selected( $view_preference, 'all' ); ?>>
								<?php _e( 'All issues', 'freshrank-ai' ); ?>
							</option>
							<option value="dismissed" <?php selected( $view_preference, 'dismissed' ); ?>>
								<?php _e( 'Dismissed issues', 'freshrank-ai' ); ?>
							</option>
						</select>
					</div>

					<!-- Actionable Badge -->
					<div class="freshrank-actionable-badge">
						<?php if ( $counts['total_actionable'] > 0 ) : ?>
							<span class="badge badge-actionable">
								✓ 
								<?php
								printf(
									// translators: %d is the number of actionable issues
									__( 'Showing %d actionable issues', 'freshrank-ai' ),
									$counts['total_actionable']
								);
								?>
							</span>
						<?php else : ?>
							<span class="badge badge-info">
								<?php _e( 'No actionable issues', 'freshrank-ai' ); ?>
							</span>
						<?php endif; ?>

						<?php if ( $counts['total_filtered'] > 0 || $counts['total_dismissed'] > 0 ) : ?>
							<span class="badge badge-secondary">
								<?php
								$hidden = $counts['total_filtered'] + $counts['total_dismissed'];
								// translators: %d is the number of filtered out issues
								printf( __( '%d filtered out', 'freshrank-ai' ), $hidden );
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="freshrank-filter-info">
				<small>
					<?php
					// Get current filter settings
					$severity_high   = get_option( 'freshrank_severity_high', 1 );
					$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
					$severity_low    = get_option( 'freshrank_severity_low', 0 );

					$fix_factual = get_option( 'freshrank_fix_factual_updates', 1 );
					$fix_ux      = get_option( 'freshrank_fix_user_experience', 0 );
					$fix_search  = get_option( 'freshrank_fix_search_optimization', 0 );
					$fix_ai      = get_option( 'freshrank_fix_ai_visibility', 0 );

					// Build severity list
					$severity_list = array();
					if ( $severity_high ) {
						$severity_list[] = __( 'High', 'freshrank-ai' );
					}
					if ( $severity_medium ) {
						$severity_list[] = __( 'Medium', 'freshrank-ai' );
					}
					if ( $severity_low ) {
						$severity_list[] = __( 'Low', 'freshrank-ai' );
					}

					// Build category list
					$category_list = array();
					if ( $fix_factual ) {
						$category_list[] = __( 'Factual', 'freshrank-ai' );
					}
					if ( $fix_ux ) {
						$category_list[] = __( 'UX', 'freshrank-ai' );
					}
					if ( $fix_search ) {
						$category_list[] = __( 'Search', 'freshrank-ai' );
					}
					if ( $fix_ai ) {
						$category_list[] = __( 'AI', 'freshrank-ai' );
					}

					printf(
						// translators: %1$s is the list of severity issues, %2$s is the list of categories
						__( 'Showing %1$s severity issues in %2$s categories.', 'freshrank-ai' ),
						'<strong>' . implode( ', ', $severity_list ) . '</strong>',
						'<strong>' . implode( ', ', $category_list ) . '</strong>'
					);
					?>
					<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings' ); ?>">
						<?php _e( 'Change settings', 'freshrank-ai' ); ?>
					</a>
				</small>
			</div>

			<?php // NEW CONSOLIDATED 5-CATEGORY STRUCTURE ?>


			<?php if ( ! empty( $data['factual_updates'] ) ) : ?>
				<div class="wsau-analysis-section wsau-factual-updates">
					<?php
					$cat_counts = $counts['categories']['factual_updates'];
					?>
					<h5>
						📊 <?php _e( 'Factual Updates', 'freshrank-ai' ); ?>
						<span class="category-count">(<?php echo $cat_counts['actionable']; ?> actionable, <?php echo $cat_counts['filtered'] + $cat_counts['dismissed']; ?> filtered out)</span>
					</h5>
					<ul class="wsau-issues-list">
						<?php foreach ( $data['factual_updates'] as $index => $issue ) : ?>
							<?php
							$status        = $this->data_provider->is_issue_actionable( $issue, 'factual_updates', $index, $post_id );
							$is_dismissed  = ( $status === 'dismissed' );
							$is_actionable = ( $status === 'actionable' );
							$severity      = ! empty( $issue['severity'] ) ? strtolower( $issue['severity'] ) : 'low';
							?>
							<li class="wsau-issue wsau-severity-<?php echo esc_attr( $severity ); ?> freshrank-issue-status-<?php echo esc_attr( $status ); ?>"
								data-post-id="<?php echo esc_attr( $post_id ); ?>"
								data-category="factual_updates"
								data-index="<?php echo esc_attr( $index ); ?>"
								data-status="<?php echo esc_attr( $status ); ?>">

								<div class="freshrank-issue-header">
									<div class="freshrank-issue-content">
										<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $issue['type'] ) ) ); ?>:</strong>
										<?php echo esc_html( $issue['issue'] ); ?>
									</div>

									<!-- Dismiss/Restore Button -->
									<?php if ( $is_dismissed ) : ?>
										<button type="button" class="button-link freshrank-restore-item"
												title="<?php _e( 'Restore this item', 'freshrank-ai' ); ?>">
											<span class="dashicons dashicons-undo"></span>
										</button>
									<?php else : ?>
										<button type="button" class="button-link freshrank-dismiss-item"
												title="<?php _e( 'Dismiss this item', 'freshrank-ai' ); ?>">
											×
										</button>
									<?php endif; ?>
								</div>

								<?php if ( ! empty( $issue['current_value'] ) ) : ?>
									<div class="wsau-issue-detail">
										<strong><?php _e( 'Current:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['current_value'] ); ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $issue['suggested_update'] ) ) : ?>
									<div class="wsau-issue-recommendation">
										<strong><?php _e( 'Update to:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['suggested_update'] ); ?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['user_experience'] ) ) : ?>
				<div class="wsau-analysis-section wsau-user-experience">
					<h5>👤 <?php _e( 'User Experience', 'freshrank-ai' ); ?></h5>

					<?php if ( ! empty( $data['user_experience']['issues'] ) ) : ?>
						<ul class="wsau-issues-list">
							<?php foreach ( $data['user_experience']['issues'] as $index => $issue ) : ?>
								<?php
								$status       = $this->data_provider->is_issue_actionable( $issue, 'user_experience', $index, $post_id );
								$is_dismissed = ( $status === 'dismissed' );
								$severity     = ! empty( $issue['severity'] ) ? strtolower( $issue['severity'] ) : 'low';
								?>
								<li class="wsau-issue wsau-severity-<?php echo esc_attr( $severity ); ?> freshrank-issue-status-<?php echo esc_attr( $status ); ?>"
									data-post-id="<?php echo esc_attr( $post_id ); ?>"
									data-category="user_experience"
									data-index="<?php echo esc_attr( $index ); ?>"
									data-status="<?php echo esc_attr( $status ); ?>">

									<div class="freshrank-issue-header">
										<div class="freshrank-issue-content">
											<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $issue['type'] ) ) ); ?>:</strong>
											<?php echo esc_html( $issue['issue'] ); ?>
										</div>

										<!-- Dismiss/Restore Button -->
										<?php if ( $is_dismissed ) : ?>
											<button type="button" class="button-link freshrank-restore-item"
													title="<?php _e( 'Restore this item', 'freshrank-ai' ); ?>">
												<span class="dashicons dashicons-undo"></span>
											</button>
										<?php else : ?>
											<button type="button" class="button-link freshrank-dismiss-item"
													title="<?php _e( 'Dismiss this item', 'freshrank-ai' ); ?>">
												×
											</button>
										<?php endif; ?>
									</div>

									<?php if ( ! empty( $issue['user_impact'] ) ) : ?>
										<div class="wsau-issue-detail">
											<strong><?php _e( 'Impact:', 'freshrank-ai' ); ?></strong>
											<?php echo esc_html( $issue['user_impact'] ); ?>
										</div>
									<?php endif; ?>
									<div class="wsau-issue-recommendation">
										<strong><?php _e( 'Fix:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['recommendation'] ); ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( ! empty( $data['user_experience']['metrics'] ) ) : ?>
						<?php $metrics = $data['user_experience']['metrics']; ?>
						<div class="wsau-metrics">
							<?php if ( isset( $metrics['estimated_dwell_time'] ) ) : ?>
								<span><strong><?php _e( 'Dwell Time:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( ucfirst( $metrics['estimated_dwell_time'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( isset( $metrics['bounce_risk'] ) ) : ?>
								<span><strong><?php _e( 'Bounce Risk:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( ucfirst( $metrics['bounce_risk'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( isset( $metrics['information_accessibility_score'] ) ) : ?>
								<span><strong><?php _e( 'Info Access:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $metrics['information_accessibility_score'] ); ?>/100</span>
							<?php endif; ?>
							<?php if ( isset( $metrics['above_fold_quality_score'] ) ) : ?>
								<span><strong><?php _e( 'Above Fold:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $metrics['above_fold_quality_score'] ); ?>/100</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['search_optimization'] ) ) : ?>
				<div class="wsau-analysis-section wsau-search-optimization">
					<h5>🔍 <?php _e( 'Search Optimization', 'freshrank-ai' ); ?> (<?php echo count( $data['search_optimization'] ); ?>)</h5>
					<ul class="wsau-issues-list">
						<?php foreach ( $data['search_optimization'] as $index => $issue ) : ?>
							<?php
							$status       = $this->data_provider->is_issue_actionable( $issue, 'search_optimization', $index, $post_id );
							$is_dismissed = ( $status === 'dismissed' );
							$severity     = ! empty( $issue['severity'] ) ? strtolower( $issue['severity'] ) : 'low';
							?>
							<li class="wsau-issue wsau-severity-<?php echo esc_attr( $severity ); ?> freshrank-issue-status-<?php echo esc_attr( $status ); ?>"
								data-post-id="<?php echo esc_attr( $post_id ); ?>"
								data-category="search_optimization"
								data-index="<?php echo esc_attr( $index ); ?>"
								data-status="<?php echo esc_attr( $status ); ?>">

								<div class="freshrank-issue-header">
									<div class="freshrank-issue-content">
										<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $issue['type'] ) ) ); ?>:</strong>
										<?php echo esc_html( $issue['issue'] ); ?>
									</div>

									<!-- Dismiss/Restore Button -->
									<?php if ( $is_dismissed ) : ?>
										<button type="button" class="button-link freshrank-restore-item"
												title="<?php _e( 'Restore this item', 'freshrank-ai' ); ?>">
											<span class="dashicons dashicons-undo"></span>
										</button>
									<?php else : ?>
										<button type="button" class="button-link freshrank-dismiss-item"
												title="<?php _e( 'Dismiss this item', 'freshrank-ai' ); ?>">
											×
										</button>
									<?php endif; ?>
								</div>

								<div class="wsau-issue-recommendation">
									<strong><?php _e( 'Fix:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['recommendation'] ); ?>
								</div>
								<?php if ( ! empty( $issue['impact'] ) ) : ?>
									<div class="wsau-issue-detail">
										<strong><?php _e( 'Impact:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['impact'] ); ?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['ai_visibility'] ) ) : ?>
				<div class="wsau-analysis-section wsau-ai-visibility">
					<h5>🤖 <?php _e( 'AI Visibility', 'freshrank-ai' ); ?></h5>
					<?php if ( ! empty( $data['ai_visibility']['issues'] ) ) : ?>
						<ul class="wsau-issues-list">
							<?php foreach ( $data['ai_visibility']['issues'] as $index => $issue ) : ?>
								<?php
								$status       = $this->data_provider->is_issue_actionable( $issue, 'ai_visibility', $index, $post_id );
								$is_dismissed = ( $status === 'dismissed' );
								$severity     = ! empty( $issue['severity'] ) ? strtolower( $issue['severity'] ) : 'low';
								?>
								<li class="wsau-issue wsau-severity-<?php echo esc_attr( $severity ); ?> freshrank-issue-status-<?php echo esc_attr( $status ); ?>"
									data-post-id="<?php echo esc_attr( $post_id ); ?>"
									data-category="ai_visibility"
									data-index="<?php echo esc_attr( $index ); ?>"
									data-status="<?php echo esc_attr( $status ); ?>">

									<div class="freshrank-issue-header">
										<div class="freshrank-issue-content">
											<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $issue['type'] ) ) ); ?>:</strong>
											<?php echo esc_html( $issue['issue'] ); ?>
										</div>

										<!-- Dismiss/Restore Button -->
										<?php if ( $is_dismissed ) : ?>
											<button type="button" class="button-link freshrank-restore-item"
													title="<?php _e( 'Restore this item', 'freshrank-ai' ); ?>">
												<span class="dashicons dashicons-undo"></span>
											</button>
										<?php else : ?>
											<button type="button" class="button-link freshrank-dismiss-item"
													title="<?php _e( 'Dismiss this item', 'freshrank-ai' ); ?>">
												×
											</button>
										<?php endif; ?>
									</div>

									<div class="wsau-issue-recommendation">
										<strong><?php _e( 'Fix:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['recommendation'] ); ?>
									</div>
									<?php if ( ! empty( $issue['impact'] ) ) : ?>
										<div class="wsau-issue-detail">
											<strong><?php _e( 'Impact:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $issue['impact'] ); ?>
										</div>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( isset( $data['ai_visibility']['visibility_score'] ) ) : ?>
						<div class="wsau-metrics">
							<span><strong><?php _e( 'Visibility Score:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $data['ai_visibility']['visibility_score'] ); ?>/100</span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['opportunities'] ) ) : ?>
				<div class="wsau-analysis-section wsau-opportunities">
					<h5>💡 <?php _e( 'Growth Opportunities', 'freshrank-ai' ); ?> (<?php echo count( $data['opportunities'] ); ?>)</h5>
					<ul class="wsau-issues-list">
						<?php foreach ( $data['opportunities'] as $index => $opp ) : ?>
							<?php
							$status       = $this->data_provider->is_issue_actionable( $opp, 'opportunities', $index, $post_id );
							$is_dismissed = ( $status === 'dismissed' );
							// Opportunities use 'priority' field, map to severity colors for consistency
							$priority       = isset( $opp['priority'] ) ? strtolower( $opp['priority'] ) : 'low';
							$severity_class = 'wsau-severity-' . $priority;
							?>
							<li class="wsau-issue <?php echo esc_attr( $severity_class ); ?> freshrank-issue-status-<?php echo esc_attr( $status ); ?>"
								data-post-id="<?php echo esc_attr( $post_id ); ?>"
								data-category="opportunities"
								data-index="<?php echo esc_attr( $index ); ?>"
								data-status="<?php echo esc_attr( $status ); ?>">

								<div class="freshrank-issue-header">
									<div class="freshrank-issue-content">
										<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $opp['type'] ) ) ); ?>:</strong>
										<?php echo esc_html( $opp['opportunity'] ); ?>
									</div>

									<!-- Dismiss/Restore Button -->
									<?php if ( $is_dismissed ) : ?>
										<button type="button" class="button-link freshrank-restore-item"
												title="<?php _e( 'Restore this item', 'freshrank-ai' ); ?>">
											<span class="dashicons dashicons-undo"></span>
										</button>
									<?php else : ?>
										<button type="button" class="button-link freshrank-dismiss-item"
												title="<?php _e( 'Dismiss this item', 'freshrank-ai' ); ?>">
											×
										</button>
									<?php endif; ?>
								</div>

								<div class="wsau-issue-recommendation">
									<strong><?php _e( 'Implementation:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $opp['implementation'] ); ?>
								</div>
								<?php if ( ! empty( $opp['expected_benefit'] ) ) : ?>
									<div class="wsau-issue-detail">
										<strong><?php _e( 'Expected Benefit:', 'freshrank-ai' ); ?></strong>
										<?php echo esc_html( $opp['expected_benefit'] ); ?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			
			<?php if ( isset( $data['summary'] ) ) : ?>
				<div class="wsau-analysis-section">
					<h5><?php _e( 'Summary', 'freshrank-ai' ); ?></h5>
					<p><?php echo esc_html( $data['summary'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $analysis->tokens_used > 0 ) : ?>
				<div class="wsau-analysis-section wsau-token-usage">
					<details>
						<summary style="cursor: pointer; color: #666; font-size: 0.9em;">
							<?php _e( 'Usage Details', 'freshrank-ai' ); ?>
						</summary>
						<div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px; font-size: 0.85em; color: #666;">
							<div><strong><?php _e( 'Tokens:', 'freshrank-ai' ); ?></strong> <?php echo number_format( $analysis->tokens_used ); ?>
								<span style="color: #999;">(<?php echo number_format( $analysis->prompt_tokens ); ?> in + <?php echo number_format( $analysis->completion_tokens ); ?> out)</span>
							</div>
							<?php if ( ! empty( $analysis->model_used ) ) : ?>
								<div><strong><?php _e( 'Model:', 'freshrank-ai' ); ?></strong> <?php echo esc_html( $analysis->model_used ); ?></div>
							<?php endif; ?>
							<?php if ( $analysis->processing_time ) : ?>
								<div><strong><?php _e( 'Time:', 'freshrank-ai' ); ?></strong> <?php echo round( $analysis->processing_time, 1 ); ?>s</div>
							<?php endif; ?>
							<?php
							// Calculate estimated cost
							$cost = $this->data_provider->estimate_cost_from_tokens( $analysis->prompt_tokens, $analysis->completion_tokens, $analysis->model_used );
							if ( $cost > 0 ) :
								?>
								<div><strong><?php _e( 'Est. Cost:', 'freshrank-ai' ); ?></strong> $<?php echo number_format( $cost, 4 ); ?></div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function debug_data_flow() {
		if ( ! isset( $_GET['debug_data_flow'] ) || ! current_user_can( 'manage_freshrank' ) ) {
			return;
		}

		echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
		echo '<h2>FreshRank AI Data Flow Diagnostic</h2>';

		// Test 1: Database Direct Query
		echo '<h3>1. Direct Database Query Test</h3>';
		global $wpdb;
		$articles_table = $wpdb->prefix . 'freshrank_articles';

		$sample_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, priority_score, impressions_current, clicks_current, ctr_current, position_current, content_age_score, traffic_potential, last_gsc_update
        FROM {$articles_table} 
        WHERE impressions_current > %d OR priority_score > %d
        ORDER BY priority_score DESC 
        LIMIT %d
    ",
				0,
				0,
				5
			)
		);

		if ( ! empty( $sample_data ) ) {
			echo '<p style="color: green;">✓ Found ' . count( $sample_data ) . ' articles with GSC data in database</p>';
			echo '<table border="1" cellpadding="5">';
			echo '<tr><th>Post ID</th><th>Priority Score</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Position</th><th>Content Age</th><th>Traffic Potential</th><th>Last Update</th></tr>';
			foreach ( $sample_data as $row ) {
				echo '<tr>';
				echo '<td>' . $row->post_id . '</td>';
				echo '<td>' . $row->priority_score . '</td>';
				echo '<td>' . $row->impressions_current . '</td>';
				echo '<td>' . $row->clicks_current . '</td>';
				echo '<td>' . number_format( $row->ctr_current * 100, 2 ) . '%</td>';
				echo '<td>' . $row->position_current . '</td>';
				echo '<td>' . $row->content_age_score . '</td>';
				echo '<td>' . $row->traffic_potential . '</td>';
				echo '<td>' . $row->last_gsc_update . '</td>';
				echo '</tr>';
			}
			echo '</table>';
		} else {
			echo '<p style="color: red;">✗ No articles found with GSC data</p>';
		}

		// Test 2: Database Class Query
		echo '<h3>2. Database Class Query Test</h3>';
		$database            = FreshRank_Database::get_instance();
		$articles_from_class = $database->get_articles_with_scores( 5 );

		if ( ! empty( $articles_from_class ) ) {
			echo '<p style="color: green;">✓ Database class returned ' . count( $articles_from_class ) . ' articles</p>';
			echo '<table border="1" cellpadding="5">';
			echo '<tr><th>Post ID</th><th>Title</th><th>Priority Score</th><th>Impressions Current</th><th>Clicks Current</th><th>CTR Current</th><th>Traffic Potential</th></tr>';
			foreach ( array_slice( $articles_from_class, 0, 3 ) as $article ) {
				echo '<tr>';
				echo '<td>' . $article->ID . '</td>';
				echo '<td>' . esc_html( substr( $article->post_title, 0, 50 ) ) . '...</td>';
				echo '<td>' . ( $article->priority_score ?? 'NULL' ) . '</td>';
				echo '<td>' . ( $article->impressions_current ?? 'NULL' ) . '</td>';
				echo '<td>' . ( $article->clicks_current ?? 'NULL' ) . '</td>';
				echo '<td>' . ( isset( $article->ctr_current ) ? number_format( $article->ctr_current * 100, 2 ) . '%' : 'NULL' ) . '</td>';
				echo '<td>' . ( $article->traffic_potential ?? 'NULL' ) . '</td>';
				echo '</tr>';
			}
			echo '</table>';
		} else {
			echo '<p style="color: red;">✗ Database class returned no articles</p>';
		}

		// Test 3: Check if columns exist
		echo '<h3>3. Database Schema Check</h3>';
		$columns = $wpdb->get_results(
			$wpdb->prepare( "SHOW COLUMNS FROM {$articles_table}" )
		);
		echo '<p>Columns in freshrank_articles table:</p>';
		echo '<ul>';
		foreach ( $columns as $column ) {
			echo '<li><strong>' . $column->Field . '</strong> (' . $column->Type . ')</li>';
		}
		echo '</ul>';

		// Test 4: Sample article data flow
		echo '<h3>4. Sample Article Data Flow</h3>';
		if ( ! empty( $articles_from_class ) ) {
			$sample_article = $articles_from_class[0];
			echo '<p>Testing with Post ID: ' . $sample_article->ID . ' - "' . esc_html( $sample_article->post_title ) . '"</p>';

			echo '<h4>Raw Article Object Properties:</h4>';
			echo '<pre style="background: #f0f0f0; padding: 10px; max-height: 300px; overflow-y: scroll;">';
			foreach ( $sample_article as $property => $value ) {
				echo $property . ': ' . var_export( $value, true ) . "\n";
			}
			echo '</pre>';

			// Test priority score calculation
			echo '<h4>Priority Score Calculation Test:</h4>';
			$content_age       = $sample_article->content_age_score ?? 0;
			$traffic_decline   = 0; // We'll calculate this
			$traffic_potential = $sample_article->traffic_potential ?? 0;

			// Calculate traffic decline manually
			if ( isset( $sample_article->clicks_previous ) && $sample_article->clicks_previous > 0 ) {
				$decline            = max( 0, $sample_article->clicks_previous - $sample_article->clicks_current );
				$decline_percentage = ( $decline / $sample_article->clicks_previous ) * 100;

				if ( $decline_percentage >= 50 ) {
					$traffic_decline = 30;
				} elseif ( $decline_percentage >= 30 ) {
					$traffic_decline = 25;
				} elseif ( $decline_percentage >= 20 ) {
					$traffic_decline = 20;
				} elseif ( $decline_percentage >= 10 ) {
					$traffic_decline = 15;
				} elseif ( $decline_percentage > 0 ) {
					$traffic_decline = 10;
				} else {
					$traffic_decline = 0;
				}
			}

			echo '<ul>';
			echo '<li><strong>Content Age Score:</strong> ' . $content_age . '/30</li>';
			echo '<li><strong>Traffic Decline Score:</strong> ' . $traffic_decline . '/30</li>';
			echo '<li><strong>Traffic Potential Score:</strong> ' . $traffic_potential . '/30</li>';
			echo '<li><strong>Total Priority Score:</strong> ' . ( $content_age + $traffic_decline + $traffic_potential ) . '/90</li>';
			echo '<li><strong>Stored Priority Score:</strong> ' . ( $sample_article->priority_score ?? 'NULL' ) . '</li>';
			echo '</ul>';

			// Test explanation functions
			echo '<h4>Explanation Functions Test:</h4>';
			echo '<ul>';
			echo '<li><strong>Has Impressions:</strong> ' . ( ( $sample_article->impressions_current ?? 0 ) > 0 ? 'YES' : 'NO' ) . '</li>';
			echo '<li><strong>Impressions Current:</strong> ' . ( $sample_article->impressions_current ?? 'NULL' ) . '</li>';
			echo '<li><strong>Clicks Previous:</strong> ' . ( $sample_article->clicks_previous ?? 'NULL' ) . '</li>';
			echo '<li><strong>Should show "No impression data":</strong> ' . ( ( $sample_article->impressions_current ?? 0 ) == 0 ? 'YES' : 'NO' ) . '</li>';
			echo '</ul>';
		}

		// Test 5: Check actual render method
		echo '<h3>5. Render Method Test</h3>';
		if ( ! empty( $articles_from_class ) ) {
			$test_article = $articles_from_class[0];
			echo '<p>Testing render_priority_score_details() with Post ID: ' . $test_article->ID . '</p>';

			ob_start();
			try {
				$this->render_priority_score_details( $test_article );
				$render_output = ob_get_contents();
				ob_end_clean();

				if ( strlen( $render_output ) > 100 ) {
					echo '<p style="color: green;">✓ Render method executed successfully (' . strlen( $render_output ) . ' characters output)</p>';
					echo '<details><summary>View rendered HTML</summary>';
					echo '<div style="border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">';
					echo htmlspecialchars( $render_output );
					echo '</div></details>';
				} else {
					echo '<p style="color: orange;">⚠ Render method executed but produced minimal output</p>';
				}
			} catch ( Exception $e ) {
				ob_end_clean();
				echo '<p style="color: red;">✗ Render method failed: ' . $e->getMessage() . '</p>';
			}
		}

		// Test 6: JavaScript Data
		echo '<h3>6. JavaScript Data Test</h3>';
		echo '<p>Check browser console for JavaScript errors when clicking "View Details"</p>';

		echo '<h3>Summary</h3>';
		echo '<ul>';
		echo '<li>Direct database query shows: ' . ( ! empty( $sample_data ) ? count( $sample_data ) . ' articles with GSC data' : 'NO GSC data' ) . '</li>';
		echo '<li>Database class query shows: ' . ( ! empty( $articles_from_class ) ? count( $articles_from_class ) . ' articles returned' : 'NO articles returned' ) . '</li>';
		echo '<li>Sample article impressions: ' . ( isset( $articles_from_class[0]->impressions_current ) ? $articles_from_class[0]->impressions_current : 'NOT SET' ) . '</li>';
		echo '</ul>';

		echo '</div>';
		exit; // Stop normal page rendering
	}

	/**
	 * Debug log page
	 */
	public function debug_log_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Debug Log', 'freshrank-ai' ); ?></h1>

			<div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
				<p><?php _e( 'This page shows recent plugin activity and errors. Enable Debug Mode in Settings to see more detailed logs.', 'freshrank-ai' ); ?></p>

				<?php
				// Get recent database operations
				global $wpdb;
				$analysis_table = $wpdb->prefix . 'freshrank_analysis';
				$articles_table = $wpdb->prefix . 'freshrank_articles';
				$drafts_table   = $wpdb->prefix . 'freshrank_drafts';

				// Recent analysis operations (increased limit)
				$recent_analysis = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT a.*, p.post_title
						FROM {$analysis_table} a
						LEFT JOIN {$wpdb->posts} p ON a.post_id = p.ID
						ORDER BY a.updated_at DESC
						LIMIT %d",
						100
					)
				);

				echo '<h2>' . __( 'Recent Analysis Operations', 'freshrank-ai' ) . '</h2>';
				echo '<p style="color: #666; font-size: 13px;">' . __( 'Showing last 100 analysis operations from FreshRank AI plugin', 'freshrank-ai' ) . '</p>';

				if ( $recent_analysis ) {
					echo '<table class="wp-list-table widefat fixed striped">';
					echo '<thead><tr>';
					echo '<th>' . __( 'Time', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Post', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Status', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Issues', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Tokens', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Model', 'freshrank-ai' ) . '</th>';
					echo '<th>' . __( 'Error Message', 'freshrank-ai' ) . '</th>';
					echo '</tr></thead><tbody>';

					foreach ( $recent_analysis as $row ) {
						$status_color = array(
							'analyzing' => '#0073aa',
							'completed' => '#46b450',
							'error'     => '#dc3232',
							'pending'   => '#999',
						);
						$color        = isset( $status_color[ $row->status ] ) ? $status_color[ $row->status ] : '#666';

						echo '<tr>';
						echo '<td style="white-space: nowrap;">' . esc_html( $row->updated_at ) . '</td>';
						echo '<td><strong>' . esc_html( $row->post_title ? $row->post_title : 'Post #' . $row->post_id ) . '</strong></td>';
						echo '<td style="color: ' . $color . '; font-weight: bold;">' . esc_html( $row->status ) . '</td>';
						echo '<td>' . esc_html( $row->issues_count ) . '</td>';
						echo '<td>' . esc_html( $row->tokens_used ? $row->tokens_used : '-' ) . '</td>';
						echo '<td>' . esc_html( $row->model_used ? $row->model_used : '-' ) . '</td>';

						// Full error message with expand if long
						if ( ! empty( $row->error_message ) ) {
							$error = $row->error_message;
							if ( strlen( $error ) > 100 ) {
								$error_id = 'error-' . $row->id;
								echo '<td>';
								echo '<div style="color: #dc3232;">';
								echo '<span id="' . esc_attr( $error_id ) . '-short">' . esc_html( substr( $error, 0, 100 ) ) . '...</span>';
								echo '<span id="' . esc_attr( $error_id ) . '-full" style="display:none;">' . esc_html( $error ) . '</span>';
								echo '<br><a href="#" class="freshrank-toggle-error" data-error-id="' . esc_attr( $error_id ) . '" style="font-size: 11px;">Toggle Full Message</a>';
								echo '</div>';
								echo '</td>';
							} else {
								echo '<td style="color: #dc3232;">' . esc_html( $error ) . '</td>';
							}
						} else {
							echo '<td>-</td>';
						}

						echo '</tr>';
					}

					echo '</tbody></table>';
				} else {
					echo '<p>' . __( 'No analysis operations found.', 'freshrank-ai' ) . '</p>';
				}

				// Check for WordPress debug log - FILTER TO FRESHRANK ONLY
				$debug_log_path = WP_CONTENT_DIR . '/debug.log';
				if ( file_exists( $debug_log_path ) && is_readable( $debug_log_path ) ) {
					echo '<h2 style="margin-top: 30px;">' . __( 'FreshRank Plugin Debug Log', 'freshrank-ai' ) . '</h2>';
					echo '<p style="color: #666; font-size: 13px;">' . __( 'Filtered to show only FreshRank AI plugin entries (not WordPress core)', 'freshrank-ai' ) . '</p>';

					// Read log file and filter to FreshRank entries only
					$all_lines       = file( $debug_log_path );
					$freshrank_lines = array();

					foreach ( $all_lines as $line ) {
						// Only include lines related to our plugin
						if ( stripos( $line, 'freshrank' ) !== false ||
							stripos( $line, 'freshrank-ai' ) !== false ||
							stripos( $line, 'class-ai-analyzer' ) !== false ||
							stripos( $line, 'class-content-updater' ) !== false ||
							stripos( $line, 'class-dashboard' ) !== false ||
							stripos( $line, 'class-database' ) !== false ) {
							$freshrank_lines[] = $line;
						}
					}

					if ( ! empty( $freshrank_lines ) ) {
						// Show last 200 FreshRank entries
						$filtered_lines = array_slice( $freshrank_lines, -200 );
						echo '<div style="margin-bottom: 10px;">';
						echo '<strong>' . count( $filtered_lines ) . '</strong> ' . __( 'plugin-related log entries found (showing last 200)', 'freshrank-ai' );
						echo '</div>';

						echo '<pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 600px; overflow-y: auto; font-size: 12px; font-family: monospace; line-height: 1.6;">';
						foreach ( array_reverse( $filtered_lines ) as $line ) {
							// Highlight errors and warnings
							if ( stripos( $line, 'error' ) !== false || stripos( $line, 'fatal' ) !== false ) {
								echo '<span style="color: #f48771; font-weight: bold;">' . esc_html( $line ) . '</span>';
							} elseif ( stripos( $line, 'warning' ) !== false || stripos( $line, 'notice' ) !== false ) {
								echo '<span style="color: #ce9178;">' . esc_html( $line ) . '</span>';
							} elseif ( stripos( $line, 'success' ) !== false || stripos( $line, 'completed' ) !== false ) {
								echo '<span style="color: #4ec9b0;">' . esc_html( $line ) . '</span>';
							} else {
								echo '<span style="color: #d4d4d4;">' . esc_html( $line ) . '</span>';
							}
						}
						echo '</pre>';
					} else {
						echo '<p style="color: #999;">' . __( 'No FreshRank AI plugin entries found in debug log.', 'freshrank-ai' ) . '</p>';
					}
				} else {
					echo '<h2 style="margin-top: 30px;">' . __( 'FreshRank Plugin Debug Log', 'freshrank-ai' ) . '</h2>';
					echo '<p style="color: #999;">' . __( 'Debug log not found or not readable. Enable WP_DEBUG_LOG in wp-config.php to create it.', 'freshrank-ai' ) . '</p>';
				}
				?>

				<div style="margin-top: 30px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 4px;">
					<h3 style="margin-top: 0;"><?php _e( 'How to Enable Full Debug Logging', 'freshrank-ai' ); ?></h3>
					<p><?php _e( 'Add these lines to your wp-config.php file:', 'freshrank-ai' ); ?></p>
					<pre style="background: #1e1e1e; color: #d4d4d4; padding: 10px; border-radius: 4px; overflow-x: auto;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
					<p><?php _e( 'Then check FreshRank AI → Settings → Debug Mode checkbox to enable plugin-specific logging.', 'freshrank-ai' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Analytics page - shows traffic potential and before/after metrics
	 */
	public function analytics_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Analytics & Traffic Potential', 'freshrank-ai' ); ?></h1>

			<?php
			// Check if GSC is connected
			$gsc_authenticated      = get_option( 'freshrank_gsc_authenticated', false );
			$prioritization_enabled = ! freshrank_is_free_version() && get_option( 'freshrank_prioritization_enabled', false );

			if ( ! $gsc_authenticated || ! $prioritization_enabled ) :
				?>
				<div class="notice notice-warning" style="padding: 20px; margin: 20px 0;">
					<h2 style="margin-top: 0;"><?php _e( 'Google Search Console Not Connected', 'freshrank-ai' ); ?></h2>
					<p><?php _e( 'Analytics and traffic potential data require Google Search Console integration. Please connect your site to view detailed performance metrics.', 'freshrank-ai' ); ?></p>
					<p>
						<a href="<?php echo admin_url( 'admin.php?page=freshrank-settings&tab=gsc' ); ?>" class="button button-primary">
							<?php _e( 'Connect Google Search Console', 'freshrank-ai' ); ?>
						</a>
					</p>
				</div>
				</div>
				<?php
				return;
			endif;

			// Get traffic potential estimation
			$traffic_estimation = $this->database->estimate_traffic_potential( 20 );
			$updated_articles   = $this->database->get_updated_articles_with_analytics();
			?>

			<!-- Traffic Potential Card -->
			<div class="freshrank-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
				<div class="freshrank-stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
					<div style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;">
						<?php _e( 'Estimated Traffic Potential', 'freshrank-ai' ); ?>
					</div>
					<div style="font-size: 48px; font-weight: bold; margin: 10px 0;">
						<?php echo number_format( $traffic_estimation['total_potential_clicks'] ); ?>
					</div>
					<div style="font-size: 14px; opacity: 0.9;">
						<?php
						printf(
							// translators: %d is the number of articles analyzed
							__( 'additional monthly clicks possible by updating your top %d articles', 'freshrank-ai' ),
							$traffic_estimation['articles_analyzed']
						);
						?>
					</div>
				</div>

				<div class="freshrank-stat-card" style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
					<div style="font-size: 14px; color: #666; margin-bottom: 10px;">
						<?php _e( 'Articles Tracked', 'freshrank-ai' ); ?>
					</div>
					<div style="font-size: 48px; font-weight: bold; margin: 10px 0; color: #333;">
						<?php echo count( $updated_articles ); ?>
					</div>
					<div style="font-size: 14px; color: #666;">
						<?php _e( 'updated articles with analytics data', 'freshrank-ai' ); ?>
					</div>
				</div>
			</div>

			<!-- Traffic Potential Breakdown -->
			<div class="freshrank-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 20px 0;">
				<h2><?php _e( 'Traffic Potential Breakdown (Top 20 Articles)', 'freshrank-ai' ); ?></h2>
				<p style="color: #666; margin-bottom: 20px;">
					<?php _e( 'Articles sorted by current traffic (clicks). Shows how many additional clicks you could gain by improving CTR to industry benchmarks.', 'freshrank-ai' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php _e( 'Article', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Current Position', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Current CTR', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Target CTR', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Current Clicks', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Potential Clicks', 'freshrank-ai' ); ?></th>
							<th><?php _e( 'Priority Score', 'freshrank-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $traffic_estimation['breakdown'] ) ) : ?>
							<?php foreach ( $traffic_estimation['breakdown'] as $article ) : ?>
								<tr>
									<td>
										<a href="<?php echo get_edit_post_link( $article['post_id'] ); ?>" target="_blank">
											<?php echo esc_html( wp_trim_words( $article['post_title'], 10 ) ); ?>
										</a>
									</td>
									<td>
										<?php echo number_format( $article['current_position'], 1 ); ?>
										<?php if ( isset( $article['improved_position'] ) && $article['improved_position'] < $article['current_position'] ) : ?>
											<div style="color: #0073aa; font-size: 11px; margin-top: 2px;">
												↑ Improved: <?php echo number_format( $article['improved_position'], 1 ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<?php echo number_format( $article['current_ctr'] * 100, 2 ); ?>%
										<?php if ( ! empty( $article['is_outperforming'] ) ) : ?>
											<div style="color: #46b450; font-size: 11px; margin-top: 2px;">✓ Outperforming</div>
										<?php endif; ?>
									</td>
									<td>
										<strong style="color: #0073aa;"><?php echo number_format( $article['effective_target_ctr'] * 100, 2 ); ?>%</strong>
										<?php if ( ! empty( $article['is_outperforming'] ) ) : ?>
											<div style="color: #46b450; font-size: 11px; margin-top: 2px;">+15% growth target</div>
										<?php elseif ( isset( $article['improved_position'] ) ) : ?>
											<div style="color: #666; font-size: 11px; margin-top: 2px;">
												at pos. <?php echo number_format( $article['improved_position'], 1 ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td><strong><?php echo number_format( $article['current_clicks'] ); ?></strong></td>
									<td><strong style="color: #46b450;">+<?php echo number_format( $article['potential_clicks'] ); ?></strong></td>
									<td><?php echo number_format( $article['priority_score'], 1 ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="7" style="text-align: center; padding: 40px; color: #999;">
									<?php _e( 'No articles with GSC data yet. Run prioritization first.', 'freshrank-ai' ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Before/After Comparison for Updated Articles -->
			<?php if ( ! empty( $updated_articles ) ) : ?>
				<div class="freshrank-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 20px 0;">
					<h2><?php _e( 'Updated Articles Performance', 'freshrank-ai' ); ?></h2>
					<p style="color: #666; margin-bottom: 20px;">
						<?php _e( 'Track the impact of your content updates. Data is collected daily for 30 days after update.', 'freshrank-ai' ); ?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php _e( 'Article', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'Updated', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'Days Tracked', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'Clicks Change', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'Position Change', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'CTR Change', 'freshrank-ai' ); ?></th>
								<th><?php _e( 'Impressions Change', 'freshrank-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $updated_articles as $article ) : ?>
								<?php
								if ( ! $article['has_complete_data'] ) {
									continue;} // Only show articles with both before and after data
								?>
								<tr>
									<td>
										<a href="<?php echo get_edit_post_link( $article['post_id'] ); ?>" target="_blank">
											<?php echo esc_html( wp_trim_words( $article['post_title'], 8 ) ); ?>
										</a>
									</td>
									<td><?php echo date( 'M j, Y', strtotime( $article['update_date'] ) ); ?></td>
									<td><?php echo $article['days_tracked']; ?> days</td>
									<td>
										<?php
										$clicks_change  = $article['changes']['clicks_change'];
										$clicks_percent = $article['changes']['clicks_percent'];
										$color          = $clicks_change >= 0 ? '#46b450' : '#dc3232';
										$arrow          = $clicks_change >= 0 ? '↑' : '↓';
										?>
										<span style="color: <?php echo $color; ?>; font-weight: bold;">
											<?php echo $arrow; ?> <?php echo abs( $clicks_change ); ?>
											(<?php echo number_format( abs( $clicks_percent ), 1 ); ?>%)
										</span>
									</td>
									<td>
										<?php
										$position_change  = $article['changes']['position_change'];
										$position_percent = $article['changes']['position_percent'];
										// Positive position change is good (moved up)
										$color = $position_change > 0 ? '#46b450' : '#dc3232';
										$arrow = $position_change > 0 ? '↑' : '↓';
										?>
										<span style="color: <?php echo $color; ?>; font-weight: bold;">
											<?php echo $arrow; ?> <?php echo number_format( abs( $position_change ), 1 ); ?>
											(<?php echo number_format( abs( $position_percent ), 1 ); ?>%)
										</span>
									</td>
									<td>
										<?php
										$ctr_change  = $article['changes']['ctr_change'];
										$ctr_percent = $article['changes']['ctr_percent'];
										$color       = $ctr_change >= 0 ? '#46b450' : '#dc3232';
										$arrow       = $ctr_change >= 0 ? '↑' : '↓';
										?>
										<span style="color: <?php echo $color; ?>;">
											<?php echo $arrow; ?> <?php echo number_format( abs( $ctr_change * 100 ), 2 ); ?>%
											(<?php echo number_format( abs( $ctr_percent ), 1 ); ?>%)
										</span>
									</td>
									<td>
										<?php
										$impressions_change  = $article['changes']['impressions_change'];
										$impressions_percent = $article['changes']['impressions_percent'];
										$color               = $impressions_change >= 0 ? '#46b450' : '#dc3232';
										$arrow               = $impressions_change >= 0 ? '↑' : '↓';
										?>
										<span style="color: <?php echo $color; ?>;">
											<?php echo $arrow; ?> <?php echo abs( $impressions_change ); ?>
											(<?php echo number_format( abs( $impressions_percent ), 1 ); ?>%)
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="freshrank-card" style="background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 20px 0; text-align: center;">
					<h3><?php _e( 'No Updated Articles Yet', 'freshrank-ai' ); ?></h3>
					<p style="color: #666;">
						<?php _e( 'Once you approve drafts and publish updates, analytics will be collected daily to track performance changes.', 'freshrank-ai' ); ?>
					</p>
					<a href="<?php echo admin_url( 'admin.php?page=freshrank-ai' ); ?>" class="button button-primary">
						<?php _e( 'Go to Dashboard', 'freshrank-ai' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<!-- Methodology Note -->
			<div style="background: #f0f6fc; padding: 20px; border-radius: 8px; border-left: 4px solid #0073aa; margin: 20px 0;">
				<h3 style="margin-top: 0;"><?php _e( 'How Traffic Potential is Calculated', 'freshrank-ai' ); ?></h3>
				<p><?php _e( 'We use industry-standard CTR benchmarks based on your article\'s current position in Google search results:', 'freshrank-ai' ); ?></p>
				<ul>
					<li><?php _e( 'Position 1: ~28.9% CTR', 'freshrank-ai' ); ?></li>
					<li><?php _e( 'Position 2: ~12.5% CTR', 'freshrank-ai' ); ?></li>
					<li><?php _e( 'Position 3: ~7.4% CTR', 'freshrank-ai' ); ?></li>
					<li><?php _e( 'Position 4-10: Decreasing from 4.9% to 0.9%', 'freshrank-ai' ); ?></li>
				</ul>
				<p><?php _e( 'Potential clicks = (Current Impressions × Target CTR) - Current Clicks', 'freshrank-ai' ); ?></p>
				<p style="font-style: italic; color: #666; margin-bottom: 0;">
					<?php _e( 'Source: Advanced Web Ranking 2024 CTR Data', 'freshrank-ai' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
