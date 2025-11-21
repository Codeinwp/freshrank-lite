<?php
/**
 * Dashboard Statistics
 * Handles statistics calculations and explanations
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Dashboard_Statistics {

	private static $instance = null;
	private $database;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->database = FreshRank_Database::get_instance();

		// Hook into analysis and draft creation to clear cache
		add_action( 'freshrank_after_analysis', array( $this, 'clear_token_stats_cache' ) );
		add_action( 'freshrank_after_update', array( $this, 'clear_token_stats_cache' ) );
	}

	/**
	 * Get cached token usage statistics
	 * Cache for 5 minutes to reduce database queries
	 *
	 * @return array Token usage statistics
	 */
	private function get_cached_token_stats() {
		$cache_key    = 'freshrank_token_stats';
		$cached_stats = get_transient( $cache_key );

		if ( $cached_stats !== false ) {
			return $cached_stats;
		}

		// Calculate stats and cache them
		$token_stats = $this->database->get_token_usage_stats();
		set_transient( $cache_key, $token_stats, 5 * MINUTE_IN_SECONDS );

		return $token_stats;
	}

	/**
	 * Clear cached token statistics
	 * Called after analysis or draft creation
	 */
	public function clear_token_stats_cache() {
		delete_transient( 'freshrank_token_stats' );
	}

	/**
	 * Display statistics - Sidebar version
	 */
	public function display_statistics( $stats ) {
		// Get token usage for summary (with caching)
		$token_stats    = $this->get_cached_token_stats();
		$has_token_data = $token_stats['combined']['total_tokens'] > 0;

		?>
		<div id="wsau-statistics" class="wsau-stats-sidebar">
			<h3 class="wsau-sidebar-title"><?php _e( 'Overview', 'freshrank-ai' ); ?></h3>

			<div class="wsau-stat-item">
				<span class="wsau-stat-value"><?php echo number_format( $stats['total_articles'] ); ?></span>
				<span class="wsau-stat-label"><?php _e( 'Total Articles', 'freshrank-ai' ); ?></span>
			</div>

			<div class="wsau-stat-item">
				<span class="wsau-stat-value"><?php echo number_format( $stats['analyzed_articles'] ); ?></span>
				<span class="wsau-stat-label"><?php _e( 'Analyzed', 'freshrank-ai' ); ?></span>
			</div>

			<div class="wsau-stat-item">
				<span class="wsau-stat-value"><?php echo number_format( $stats['pending_drafts'] ); ?></span>
				<span class="wsau-stat-label"><?php _e( 'Pending Drafts', 'freshrank-ai' ); ?></span>
			</div>

			<?php if ( $has_token_data ) : ?>
				<div class="wsau-stat-item wsau-stat-clickable freshrank-goto-settings"
					data-url="<?php echo esc_url( admin_url( 'admin.php?page=freshrank-settings' ) ); ?>"
					title="<?php esc_attr_e( 'Click to view detailed usage breakdown', 'freshrank-ai' ); ?>">
					<span class="wsau-stat-value">
						<?php
						$total_cost = $this->calculate_total_cost_quick( $token_stats );
						if ( $total_cost > 0 ) {
							echo '~$' . number_format( $total_cost, 2 );
						} else {
							echo number_format( $token_stats['combined']['total_tokens'] );
						}
						?>
					</span>
					<span class="wsau-stat-label">
						<?php
						if ( $total_cost > 0 ) {
							echo __( 'Est. Usage', 'freshrank-ai' );
						} else {
							echo __( 'Total Tokens', 'freshrank-ai' );
						}
						?>
					</span>
				</div>
			<?php endif; ?>

			<?php
			// Show GSC last refresh indicator if prioritization is enabled
			$prioritization_enabled = get_option( 'freshrank_prioritization_enabled', false );
			if ( $prioritization_enabled ) :
				$last_prioritization = get_option( 'freshrank_last_prioritization_run', 0 );
				if ( $last_prioritization > 0 ) :
					?>
				<div class="wsau-stat-item" style="border-top: 1px solid #dcdcde; padding-top: 12px; margin-top: 12px;">
					<span class="wsau-stat-label" style="font-size: 11px; color: #666;">
						<?php _e( 'GSC Data Updated:', 'freshrank-ai' ); ?>
					</span>
					<span class="wsau-stat-value" style="font-size: 12px; color: #2271b1;">
						<?php echo human_time_diff( $last_prioritization, to: time() ) . ' ago'; ?>
					</span>
				</div>
					<?php
				endif;
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Display workflow progress tracker
	 */
	public function display_workflow_progress( $stats ) {
		$total_articles    = $stats['total_articles'];
		$analyzed_articles = $stats['analyzed_articles'];
		$pending_drafts    = $stats['pending_drafts'];

		// Calculate percentages
		$analyzed_percent = $total_articles > 0 ? round( ( $analyzed_articles / $total_articles ) * 100 ) : 0;
		$drafts_percent   = $analyzed_articles > 0 ? round( ( $pending_drafts / $analyzed_articles ) * 100 ) : 0;

		// Determine step completion status
		$step1_complete    = $analyzed_articles > 0;
		$step2_complete    = $pending_drafts > 0;
		$step3_in_progress = $pending_drafts > 0;

		?>
		<div class="wsau-workflow-tracker">
			<div class="wsau-workflow-step <?php echo $step1_complete ? 'wsau-step-complete' : 'wsau-step-active'; ?>">
				<div class="wsau-step-icon">
					<?php if ( $step1_complete ) : ?>
						<span class="dashicons dashicons-yes-alt"></span>
					<?php else : ?>
						<span class="wsau-step-num">1</span>
					<?php endif; ?>
				</div>
				<div class="wsau-step-content">
					<div class="wsau-step-title"><?php _e( 'Analyze', 'freshrank-ai' ); ?></div>
					<div class="wsau-step-status">
						<?php if ( $analyzed_articles > 0 ) : ?>
							<span class="wsau-step-count"><?php echo $analyzed_articles; ?>/<?php echo $total_articles; ?></span>
							<span class="wsau-step-label"><?php _e( 'analyzed', 'freshrank-ai' ); ?></span>
						<?php else : ?>
							<span class="wsau-step-label"><?php _e( 'Click "Analyze All" to start', 'freshrank-ai' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="wsau-workflow-connector <?php echo $step1_complete ? 'wsau-connector-complete' : ''; ?>"></div>

			<div class="wsau-workflow-step <?php echo $step2_complete ? 'wsau-step-complete' : ( $step1_complete ? 'wsau-step-active' : 'wsau-step-disabled' ); ?>">
				<div class="wsau-step-icon">
					<?php if ( $step2_complete ) : ?>
						<span class="dashicons dashicons-yes-alt"></span>
					<?php else : ?>
						<span class="wsau-step-num">2</span>
					<?php endif; ?>
				</div>
				<div class="wsau-step-content">
					<div class="wsau-step-title"><?php _e( 'Create Drafts', 'freshrank-ai' ); ?></div>
					<div class="wsau-step-status">
						<?php if ( $analyzed_articles > 0 ) : ?>
							<span class="wsau-step-count"><?php echo $analyzed_articles; ?></span>
							<span class="wsau-step-label"><?php _e( 'ready for drafts', 'freshrank-ai' ); ?></span>
						<?php else : ?>
							<span class="wsau-step-label"><?php _e( 'Analyze articles first', 'freshrank-ai' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="wsau-workflow-connector <?php echo $step2_complete ? 'wsau-connector-complete' : ''; ?>"></div>

			<div class="wsau-workflow-step <?php echo $step3_in_progress ? 'wsau-step-active' : 'wsau-step-disabled'; ?>">
				<div class="wsau-step-icon">
					<span class="wsau-step-num">3</span>
				</div>
				<div class="wsau-step-content">
					<div class="wsau-step-title"><?php _e( 'Review & Approve', 'freshrank-ai' ); ?></div>
					<div class="wsau-step-status">
						<?php if ( $pending_drafts > 0 ) : ?>
							<span class="wsau-step-count"><?php echo $pending_drafts; ?></span>
							<span class="wsau-step-label"><?php _e( 'awaiting approval', 'freshrank-ai' ); ?></span>
						<?php else : ?>
							<span class="wsau-step-label"><?php _e( 'No drafts pending', 'freshrank-ai' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Quick cost calculation for dashboard stat box
	 */
	public function calculate_total_cost_quick( $token_stats ) {
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

		foreach ( $token_stats['analysis']['by_model'] as $model => $data ) {
			if ( isset( $pricing[ $model ] ) ) {
				$input_cost  = ( $data['prompt_tokens'] / 1000000 ) * $pricing[ $model ]['input'];
				$output_cost = ( $data['completion_tokens'] / 1000000 ) * $pricing[ $model ]['output'];
				$total_cost += $input_cost + $output_cost;
			}
		}

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
	 * Calculate traffic decline score
	 */
	public function calculate_traffic_decline_score( $article ) {
		if ( $article->clicks_previous == 0 ) {
			return 0;
		}

		$decline            = max( 0, $article->clicks_previous - $article->clicks_current );
		$decline_percentage = ( $decline / $article->clicks_previous ) * 100;

		if ( $decline_percentage >= 50 ) {
			return 30;
		}
		if ( $decline_percentage >= 30 ) {
			return 25;
		}
		if ( $decline_percentage >= 20 ) {
			return 20;
		}
		if ( $decline_percentage >= 10 ) {
			return 15;
		}
		if ( $decline_percentage > 0 ) {
			return 10;
		}
		return 0;
	}

	/**
	 * Get traffic decline explanation
	 */
	public function get_traffic_decline_explanation( $article ) {
		if ( $article->clicks_previous == 0 ) {
			return __( 'No previous traffic data available', 'freshrank-ai' );
		}

		$decline            = max( 0, $article->clicks_previous - $article->clicks_current );
		$decline_percentage = ( $decline / $article->clicks_previous ) * 100;

		if ( $decline_percentage >= 50 ) {
			// translators: %d is the percentage of traffic decline
			return sprintf( __( 'Major traffic decline: %d%% drop in clicks', 'freshrank-ai' ), round( $decline_percentage ) );
		} elseif ( $decline_percentage >= 30 ) {
			// translators: %d is the percentage of traffic decline
			return sprintf( __( 'Significant traffic decline: %d%% drop in clicks', 'freshrank-ai' ), round( $decline_percentage ) );
		} elseif ( $decline_percentage >= 10 ) {
			// translators: %d is the percentage of traffic decline
			return sprintf( __( 'Moderate traffic decline: %d%% drop in clicks', 'freshrank-ai' ), round( $decline_percentage ) );
		} elseif ( $decline_percentage > 0 ) {
			// translators: %d is the percentage of traffic decline
			return sprintf( __( 'Minor traffic decline: %d%% drop in clicks', 'freshrank-ai' ), round( $decline_percentage ) );
		} else {
			return __( 'Traffic maintained or increased', 'freshrank-ai' );
		}
	}

	/**
	 * Get traffic potential explanation
	 */
	public function get_traffic_potential_explanation( $article ) {
		if ( $article->impressions_current == 0 ) {
			return __( 'No impression data available', 'freshrank-ai' );
		}

		$ctr_percentage = $article->ctr_current * 100;
		$expected_ctr   = 5; // Default 5%

		if ( $article->position_current > 0 ) {
			if ( $article->position_current <= 3 ) {
				$expected_ctr = 25;
			} elseif ( $article->position_current <= 10 ) {
				$expected_ctr = 10;
			} elseif ( $article->position_current <= 20 ) {
				$expected_ctr = 5;
			} else {
				$expected_ctr = 2;
			}
		}

		if ( $ctr_percentage < $expected_ctr ) {
			$potential_improvement = $expected_ctr - $ctr_percentage;
			return sprintf(
				// translators: %1$s is the number of impressions, %2$s is the percentage of CTR, %3$s is the expected percentage of CTR, %4$s is the percentage of improvement
				__( 'High potential: %1$s impressions with %2$s%% CTR (expected %3$s%%). Could improve by %4$s%%.', 'freshrank-ai' ),
				number_format( $article->impressions_current ),
				number_format( $ctr_percentage, 2 ),
				number_format( $expected_ctr, 1 ),
				number_format( $potential_improvement, 1 )
			);
		} else {
			return sprintf(
				// translators: %1$s is the number of impressions, %2$s is the percentage of CTR
				__( 'Good performance: %1$s impressions with %2$s%% CTR meeting expectations', 'freshrank-ai' ),
				number_format( $article->impressions_current ),
				number_format( $ctr_percentage, 2 )
			);
		}
	}

	/**
	 * Get content age explanation
	 */
	public function get_content_age_explanation( $score ) {
		if ( $score >= 25 ) {
			return __( 'Very old content (1+ years) - highest priority for updates', 'freshrank-ai' );
		} elseif ( $score >= 20 ) {
			return __( 'Old content (6+ months) - high priority for updates', 'freshrank-ai' );
		} elseif ( $score >= 15 ) {
			return __( 'Moderately old content (3+ months) - medium priority', 'freshrank-ai' );
		} elseif ( $score >= 8 ) {
			return __( 'Recent content (1+ months) - lower priority', 'freshrank-ai' );
		} else {
			return __( 'Very recent content (less than 1 month) - lowest priority', 'freshrank-ai' );
		}
	}

	/**
	 * Get priority level explanation
	 */
	public function get_priority_level_explanation( $score ) {
		if ( $score >= 60 ) {
			return __( 'High priority - Immediate attention recommended', 'freshrank-ai' );
		} elseif ( $score >= 40 ) {
			return __( 'Medium priority - Should be updated soon', 'freshrank-ai' );
		} elseif ( $score >= 20 ) {
			return __( 'Low priority - Can be updated when convenient', 'freshrank-ai' );
		} else {
			return __( 'Very low priority - No immediate action needed', 'freshrank-ai' );
		}
	}
}
