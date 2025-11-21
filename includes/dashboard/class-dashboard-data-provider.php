<?php
/**
 * Dashboard Data Provider
 * Handles data fetching, formatting, and calculations
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Dashboard_Data_Provider {

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
	}

	/**
	 * Get current dashboard state
	 */
	public function get_dashboard_state() {
		// Always show the initial state with consistent buttons
		return 'initial';
	}

	/**
	 * Get draft information for a specific post
	 */
	public function get_draft_info( $post_id ) {
		global $wpdb;

		$drafts_table = $wpdb->prefix . 'freshrank_drafts';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
		$draft = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT d.*,
					dp.post_title as draft_title,
					dp.post_date as draft_date
				FROM {$drafts_table} d
				LEFT JOIN {$wpdb->posts} dp ON d.draft_post_id = dp.ID
				WHERE d.original_post_id = %d
				AND dp.post_status = %s
				ORDER BY d.created_at DESC
        LIMIT %d",
				$post_id,
				'draft',
				1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $draft ) {
			return null;
		}

		// Get update information from meta
		$changes_made   = get_post_meta( $draft->draft_post_id, '_freshrank_changes_made', true );
		$update_summary = get_post_meta( $draft->draft_post_id, '_freshrank_update_summary', true );

		return array(
			'draft_id'          => $draft->draft_post_id,
			'original_id'       => $post_id,
			'draft_title'       => str_replace( ' (Updated Draft)', '', $draft->draft_title ),
			'created_date'      => $draft->created_at,
			'changes_count'     => is_array( $changes_made ) ? count( $changes_made ) : 0,
			'update_summary'    => $update_summary,
			'draft_edit_url'    => admin_url( 'post.php?post=' . $draft->draft_post_id . '&action=edit' ),
			'preview_url'       => get_preview_post_link( $draft->draft_post_id ),
			'tokens_used'       => isset( $draft->tokens_used ) ? $draft->tokens_used : 0,
			'prompt_tokens'     => isset( $draft->prompt_tokens ) ? $draft->prompt_tokens : 0,
			'completion_tokens' => isset( $draft->completion_tokens ) ? $draft->completion_tokens : 0,
			'model_used'        => isset( $draft->model_used ) ? $draft->model_used : null,
		);
	}

	/**
	 * Calculate actionable issue counts based on current severity and category filters
	 * Returns array with 'actionable', 'filtered', and 'dismissed' counts per category
	 */
	public function calculate_actionable_counts( $analysis_data, $post_id ) {
		$database        = FreshRank_Database::get_instance();
		$dismissed_items = $database->get_dismissed_items( $post_id );

		// Get current filter settings
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		$fix_factual       = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux            = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search        = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai            = get_option( 'freshrank_fix_ai_visibility', 0 );
		$fix_opportunities = get_option( 'freshrank_fix_opportunities', 0 );

		$counts = array(
			'total_actionable' => 0,
			'total_filtered'   => 0,
			'total_dismissed'  => 0,
			'categories'       => array(),
		);

		// Helper to check if item matches severity
		$matches_severity = function ( $severity ) use ( $severity_high, $severity_medium, $severity_low ) {
			$sev = strtolower( $severity );
			if ( in_array( $sev, array( 'high', 'urgent' ), true ) ) {
				return $severity_high;
			}
			if ( $sev === 'medium' ) {
				return $severity_medium;
			}
			if ( $sev === 'low' ) {
				return $severity_low;
			}
			return $severity_medium; // default
		};

		// Helper to check if item is dismissed
		$is_dismissed = function ( $category, $index ) use ( $dismissed_items ) {
			return in_array( "{$category}:{$index}", $dismissed_items, true );
		};

		// Process each category
		$categories = array(
			'factual_updates'     => array(
				'data'    => $analysis_data['factual_updates'] ?? array(),
				'enabled' => $fix_factual,
			),
			'user_experience'     => array(
				'data'    => $analysis_data['user_experience']['issues'] ?? array(),
				'enabled' => $fix_ux,
			),
			'search_optimization' => array(
				'data'    => $analysis_data['search_optimization'] ?? array(),
				'enabled' => $fix_search,
			),
			'ai_visibility'       => array(
				'data'    => $analysis_data['ai_visibility']['issues'] ?? array(),
				'enabled' => $fix_ai,
			),
			'opportunities'       => array(
				'data'    => $analysis_data['opportunities'] ?? array(),
				'enabled' => $fix_opportunities,
			),
		);

		foreach ( $categories as $category_name => $category_data ) {
			$actionable = 0;
			$filtered   = 0;
			$dismissed  = 0;

			foreach ( $category_data['data'] as $index => $issue ) {
				$severity = $issue['severity'] ?? $issue['priority'] ?? 'medium';

				if ( $is_dismissed( $category_name, $index ) ) {
					++$dismissed;
				} elseif ( ! $category_data['enabled'] ) {
					++$filtered; // Category disabled
				} elseif ( ! $matches_severity( $severity ) ) {
					++$filtered; // Severity filtered out
				} else {
					++$actionable; // Will be included in draft
				}
			}

			$counts['categories'][ $category_name ] = array(
				'actionable' => $actionable,
				'filtered'   => $filtered,
				'dismissed'  => $dismissed,
				'total'      => count( $category_data['data'] ),
			);

			$counts['total_actionable'] += $actionable;
			$counts['total_filtered']   += $filtered;
			$counts['total_dismissed']  += $dismissed;
		}

		return $counts;
	}

	/**
	 * Check if a specific issue is actionable (will be included in draft)
	 */
	public function is_issue_actionable( $issue, $category, $index, $post_id ) {
		$database        = FreshRank_Database::get_instance();
		$dismissed_items = $database->get_dismissed_items( $post_id );

		// Check if dismissed
		if ( in_array( "{$category}:{$index}", $dismissed_items, true ) ) {
			return 'dismissed';
		}

		// Check category enabled
		$category_map = array(
			'factual_updates'     => 'freshrank_fix_factual_updates',
			'user_experience'     => 'freshrank_fix_user_experience',
			'search_optimization' => 'freshrank_fix_search_optimization',
			'ai_visibility'       => 'freshrank_fix_ai_visibility',
			'opportunities'       => 'freshrank_fix_opportunities',
		);

		if ( isset( $category_map[ $category ] ) && ! get_option( $category_map[ $category ], 1 ) ) {
			return 'category_disabled';
		}

		// Check severity
		$severity        = strtolower( $issue['severity'] ?? $issue['priority'] ?? 'medium' );
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		if ( in_array( $severity, array( 'high', 'urgent' ), true ) && ! $severity_high ) {
			return 'severity_filtered';
		}
		if ( $severity === 'medium' && ! $severity_medium ) {
			return 'severity_filtered';
		}
		if ( $severity === 'low' && ! $severity_low ) {
			return 'severity_filtered';
		}

		return 'actionable';
	}

	/**
	 * Get priority class for styling
	 */
	public function get_priority_class( $score ) {
		if ( $score >= 60 ) {
			return 'high';
		}
		if ( $score >= 40 ) {
			return 'medium';
		}
		if ( $score >= 20 ) {
			return 'low';
		}
		return 'very-low';
	}

	/**
	 * Estimate cost from tokens (for dashboard display)
	 */
	public function estimate_cost_from_tokens( $prompt_tokens, $completion_tokens, $model ) {
		// Cost per 1M tokens (input/output) - Same as AI Analyzer
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

		if ( ! isset( $pricing[ $model ] ) ) {
			return 0;
		}

		$input_cost  = ( $prompt_tokens / 1000000 ) * $pricing[ $model ]['input'];
		$output_cost = ( $completion_tokens / 1000000 ) * $pricing[ $model ]['output'];

		return round( $input_cost + $output_cost, 4 );
	}
}
