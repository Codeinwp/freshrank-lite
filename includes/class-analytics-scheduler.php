<?php
/**
 * Analytics Scheduler for FreshRank AI
 * Handles daily GSC data collection for updated articles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class FreshRank_Analytics_Scheduler {

	private static $instance = null;
	private $database;
	private $gsc_api;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->database = FreshRank_Database::get_instance();

		// Register WordPress cron hooks (works with both WP Cron and ActionScheduler)
		add_action( 'freshrank_daily_analytics_snapshot', array( $this, 'capture_daily_snapshots' ) );

		// Schedule daily cron job
		add_action( 'init', array( $this, 'schedule_daily_snapshots' ) );

		// Add health check for ActionScheduler (ensures recurring actions stay scheduled)
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			add_action( 'action_scheduler_after_process_queue', array( $this, 'ensure_scheduled' ) );
		}
	}

	/**
	 * Schedule daily analytics snapshots if not already scheduled
	 */
	public function schedule_daily_snapshots() {
		// Use ActionScheduler if available, fall back to WP Cron
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			// Check using as_has_scheduled_action() for better performance
			if ( ! as_has_scheduled_action( 'freshrank_daily_analytics_snapshot' ) ) {
				// Calculate next 3 AM
				$tomorrow_3am = strtotime( 'tomorrow 3:00 AM' );

				// Schedule recurring action (runs every 24 hours)
				as_schedule_recurring_action(
					$tomorrow_3am,
					DAY_IN_SECONDS,
					'freshrank_daily_analytics_snapshot',
					array(), // No args needed
					'freshrank' // Group for organization
				);
			}
		} else {
			// Fallback to WP Cron if ActionScheduler not available
			if ( ! wp_next_scheduled( 'freshrank_daily_analytics_snapshot' ) ) {
				// Schedule to run daily at 3 AM
				wp_schedule_event( strtotime( 'tomorrow 3:00 AM' ), 'daily', 'freshrank_daily_analytics_snapshot' );
			}
		}
	}

	/**
	 * Capture daily snapshots for all updated articles
	 */
	public function capture_daily_snapshots() {
		try {
			// Check if GSC is connected
			if ( ! get_option( 'freshrank_gsc_authenticated', false ) ) {
				return;
			}

			// Initialize GSC API
			$this->gsc_api = freshrank_get_gsc_api();

			// Get all posts that have been updated (have analytics tracking)
			$updated_posts = $this->get_updated_posts_to_track();

			if ( empty( $updated_posts ) ) {
				return;
			}

			$snapshots_captured = 0;
			$errors             = 0;

			foreach ( $updated_posts as $post_data ) {
				try {
					$this->capture_snapshot_for_post( $post_data );
					++$snapshots_captured;

					// Rate limiting - wait 300ms between requests
					usleep( 300000 );

				} catch ( Exception $e ) {
					++$errors;
				}
			}
		} catch ( Exception $e ) {
			// Silently fail - this is a background job
		}
	}

	/**
	 * Get posts that need analytics tracking
	 */
	private function get_updated_posts_to_track() {
		global $wpdb;

		// Get posts that have been updated (have _freshrank_update_approved_date meta)
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID as post_id,
					pm.meta_value as update_date,
					DATEDIFF(NOW(), pm.meta_value) as days_since_update
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_status = %s
			   AND p.post_type = %s
			   AND DATEDIFF(NOW(), pm.meta_value) <= %d
			 ORDER BY pm.meta_value DESC",
				'_freshrank_update_approved_date',
				'publish',
				'post',
				30
			),
			ARRAY_A
		);

		return $results;
	}

	/**
	 * Capture snapshot for a single post
	 */
	private function capture_snapshot_for_post( $post_data ) {
		$post_id           = $post_data['post_id'];
		$update_date       = $post_data['update_date'];
		$days_since_update = $post_data['days_since_update'];

		// Get current GSC data for this post
		$post_url = get_permalink( $post_id );

		// Fetch data from GSC for last 7 days
		$end_date   = date( 'Y-m-d' );
		$start_date = date( 'Y-m-d', strtotime( '-7 days' ) );

		$analytics_data = $this->gsc_api->get_url_analytics( $post_url, $start_date, $end_date );

		if ( ! $analytics_data ) {
			throw new Exception( 'No GSC data available' );
		}

		// Get top queries
		$top_queries = $this->gsc_api->get_top_queries_for_url( $post_url, 5 );

		// Prepare snapshot data
		$gsc_data = array(
			'clicks'       => $analytics_data['clicks'] ?? 0,
			'impressions'  => $analytics_data['impressions'] ?? 0,
			'ctr'          => $analytics_data['ctr'] ?? 0,
			'position'     => $analytics_data['position'] ?? 0,
			'top_queries'  => $top_queries,
			'period_start' => $start_date,
			'period_end'   => $end_date,
		);

		// Save the "after" snapshot
		$snapshot_id = $this->database->save_after_snapshot(
			$post_id,
			$update_date,
			$gsc_data,
			$days_since_update
		);

		if ( ! $snapshot_id ) {
			throw new Exception( 'Failed to save snapshot to database' );
		}

		return $snapshot_id;
	}

	/**
	 * Manually trigger snapshot capture (for testing or manual runs)
	 */
	public function manual_snapshot() {
		return $this->capture_daily_snapshots();
	}

	/**
	 * Ensure the recurring action is still scheduled (health check)
	 * Runs periodically to verify ActionScheduler task is still active
	 */
	public function ensure_scheduled() {
		// Only check if ActionScheduler is available
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Check if action is scheduled
		if ( ! as_has_scheduled_action( 'freshrank_daily_analytics_snapshot' ) ) {
			// Re-schedule if missing
			$this->schedule_daily_snapshots();
		}
	}

	/**
	 * Clear all scheduled events (for deactivation)
	 */
	public static function clear_scheduled_events() {
		// Clear ActionScheduler events
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'freshrank_daily_analytics_snapshot', array(), 'freshrank' );
		}

		// Also clear WP Cron events for backward compatibility
		$timestamp = wp_next_scheduled( 'freshrank_daily_analytics_snapshot' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'freshrank_daily_analytics_snapshot' );
		}
	}

	/**
	 * Get next scheduled run time
	 */
	public function get_next_scheduled_run() {
		// Try ActionScheduler first
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$timestamp = as_next_scheduled_action( 'freshrank_daily_analytics_snapshot' );
			if ( $timestamp && is_int( $timestamp ) ) {
				return date( 'Y-m-d H:i:s', $timestamp );
			}
		}

		// Fallback to WP Cron
		$timestamp = wp_next_scheduled( 'freshrank_daily_analytics_snapshot' );
		if ( $timestamp ) {
			return date( 'Y-m-d H:i:s', $timestamp );
		}

		return null;
	}
}
