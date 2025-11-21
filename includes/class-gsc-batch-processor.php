<?php
/**
 * GSC Batch Processor for FreshRank AI
 * Handles batched prioritization using ActionScheduler
 */

defined( 'ABSPATH' ) || exit;

class FreshRank_GSC_Batch_Processor {

	private static $instance = null;
	private $database;
	private $gsc_api;

	const BATCH_SIZE         = 100;
	const PROGRESS_TRANSIENT = 'freshrank_prioritization_progress';
	const AS_HOOK            = 'freshrank_process_prioritization_batch';
	const AS_GROUP           = 'freshrank';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->database = FreshRank_Database::get_instance();

		// Register ActionScheduler hook
		add_action( self::AS_HOOK, array( $this, 'process_prioritization_batch' ), 10, 4 );
	}

	/**
	 * Start a new prioritization job
	 *
	 * @return array Job metadata including job_id, total_posts, total_batches
	 * @throws Exception If job already running or GSC not authenticated
	 */
	public function start_prioritization() {
		// Check for existing job
		$existing_progress = get_transient( self::PROGRESS_TRANSIENT );
		if ( $existing_progress && $existing_progress['status'] === 'running' ) {
			throw new Exception( __( 'Prioritization already in progress. Please wait for it to complete.', 'freshrank-ai' ) );
		}

		// Verify GSC authentication
		$this->gsc_api = freshrank_get_gsc_api();
		if ( ! $this->gsc_api->is_authenticated() ) {
			throw new Exception( __( 'Google Search Console is not authenticated.', 'freshrank-ai' ) );
		}

		// Get total count of posts.
		$total_posts = $this->get_total_posts_count();

		if ( $total_posts === 0 ) {
			throw new Exception( __( 'No posts found to prioritize.', 'freshrank-ai' ) );
		}

		$total_batches = ceil( $total_posts / self::BATCH_SIZE );
		$job_id        = 'pr_' . time();

		// Initialize progress tracking
		$progress = array(
			'status'        => 'running',
			'job_id'        => $job_id,
			'total_posts'   => $total_posts,
			'processed'     => 0,
			'current_batch' => 0,
			'total_batches' => $total_batches,
			'started_at'    => time(),
			'last_update'   => time(),
			'success_count' => 0,
			'cache_hits'    => 0,
			'errors'        => array(),
		);
		set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );

		// Schedule first batch immediately
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::AS_HOOK,
				array(
					'job_id'       => $job_id,
					'batch_number' => 1,
					'offset'       => 0,
					'batch_size'   => self::BATCH_SIZE,
				),
				self::AS_GROUP
			);
		} else {
			throw new Exception( __( 'ActionScheduler is not available. Cannot start batched prioritization.', 'freshrank-ai' ) );
		}

		freshrank_debug_log( "GSC Batch Prioritization started: Job {$job_id}, {$total_posts} posts, {$total_batches} batches" );

		return array(
			'job_id'        => $job_id,
			'total_posts'   => $total_posts,
			'total_batches' => $total_batches,
		);
	}

	/**
	 * Process a single batch of posts
	 * Called by ActionScheduler
	 *
	 * @param string $job_id Unique job identifier
	 * @param int $batch_number Current batch number (1-indexed)
	 * @param int $offset Starting offset in post array
	 * @param int $batch_size Number of posts to process
	 */
	public function process_prioritization_batch( $job_id, $batch_number, $offset, $batch_size ) {
		try {
			// Load GSC API
			$this->gsc_api = freshrank_get_gsc_api();

			// Get progress
			$progress = get_transient( self::PROGRESS_TRANSIENT );
			if ( ! $progress || $progress['job_id'] !== $job_id ) {
				throw new Exception( 'Progress data mismatch or expired' );
			}

			// Fetch ONLY the posts we need for this batch (on-demand)
			$batch_posts = $this->get_posts_batch( $offset, $batch_size );

			if ( empty( $batch_posts ) ) {
				throw new Exception( 'No posts found for this batch' );
			}

			$batch_errors  = array();
			$success_count = 0;
			$cache_hits    = 0;

			freshrank_debug_log( "GSC Batch {$batch_number}: Processing " . count( $batch_posts ) . ' posts' );

			// Process each post in the batch
			foreach ( $batch_posts as $post_id ) {
				try {
					$result = $this->gsc_api->process_single_post_gsc_data( $post_id );

					if ( $result['success'] ) {
						++$success_count;
						if ( $result['cache_hit'] ) {
							++$cache_hits;
						}
					} else {
						$batch_errors[] = array(
							'batch'   => $batch_number,
							'post_id' => $post_id,
							'error'   => $result['error'] ?? 'Unknown error',
						);
					}
				} catch ( Exception $e ) {
					// Log error but continue batch
					$batch_errors[] = array(
						'batch'   => $batch_number,
						'post_id' => $post_id,
						'error'   => $e->getMessage(),
					);
					freshrank_debug_log( "GSC Batch {$batch_number} Error: Post {$post_id} - " . $e->getMessage() );
				}
			}

			// Update progress
			$progress['processed']     += count( $batch_posts );
			$progress['current_batch']  = $batch_number;
			$progress['success_count'] += $success_count;
			$progress['cache_hits']    += $cache_hits;
			$progress['errors']         = array_merge( $progress['errors'], $batch_errors );
			$progress['last_update']    = time();
			set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );

			freshrank_debug_log( "GSC Batch {$batch_number}: Completed. Success: {$success_count}, Cache hits: {$cache_hits}, Errors: " . count( $batch_errors ) );

			// Check if there are more batches
			$next_offset = $offset + $batch_size;
			if ( $next_offset < $progress['total_posts'] ) {
				// Schedule next batch
				as_enqueue_async_action(
					self::AS_HOOK,
					array(
						'job_id'       => $job_id,
						'batch_number' => $batch_number + 1,
						'offset'       => $next_offset,
						'batch_size'   => $batch_size,
					),
					self::AS_GROUP
				);
				freshrank_debug_log( "GSC Batch {$batch_number}: Scheduled next batch" );
			} else {
				// Final batch - finalize prioritization
				$this->finalize_prioritization( $job_id );
			}
		} catch ( Exception $e ) {
			// Critical error - mark job as failed
			freshrank_debug_log( "GSC Batch {$batch_number}: Critical error - " . $e->getMessage() );

			$progress = get_transient( self::PROGRESS_TRANSIENT );
			if ( $progress ) {
				$progress['status']      = 'failed';
				$progress['errors'][]    = array(
					'critical' => true,
					'batch'    => $batch_number,
					'error'    => $e->getMessage(),
				);
				$progress['last_update'] = time();
				set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );
			}

			// Re-throw for ActionScheduler retry logic
			throw $e;
		}
	}

	/**
	 * Finalize prioritization after all batches complete
	 * Sorts articles and sets display order
	 *
	 * @param string $job_id Job identifier
	 */
	private function finalize_prioritization( $job_id ) {
		freshrank_debug_log( "GSC Prioritization finalizing: Job {$job_id}" );

		try {
			// Set display order in single query (replaces fetch + sort + loop)
			$this->database->set_display_order_by_priority();

			// Update progress to complete
			$progress = get_transient( self::PROGRESS_TRANSIENT );
			if ( $progress ) {
				$progress['status']       = 'complete';
				$progress['completed_at'] = time();
				$progress['last_update']  = time();
				set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );
			}

			// Update last prioritization timestamp
			update_option( 'freshrank_last_prioritization_run', time() );
			update_option( 'freshrank_last_prioritization_status', 'success' );
			update_option( 'freshrank_last_prioritization_post_count', $progress['total_posts'] ?? 0 );

			freshrank_debug_log( "GSC Prioritization complete: Job {$job_id}" );

		} catch ( Exception $e ) {
			freshrank_debug_log( 'GSC Prioritization finalization error: ' . $e->getMessage() );

			$progress = get_transient( self::PROGRESS_TRANSIENT );
			if ( $progress ) {
				$progress['status']   = 'failed';
				$progress['errors'][] = array(
					'critical'     => true,
					'finalization' => true,
					'error'        => $e->getMessage(),
				);
				set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Cancel in-progress prioritization
	 *
	 * @return bool Success status
	 */
	public function cancel_prioritization() {
		// Unschedule all pending batches
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AS_HOOK, null, self::AS_GROUP );
		}

		// Update progress status
		$progress = get_transient( self::PROGRESS_TRANSIENT );
		if ( $progress ) {
			$job_id                   = $progress['job_id'];
			$progress['status']       = 'cancelled';
			$progress['cancelled_at'] = time();
			$progress['last_update']  = time();
			set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );

			freshrank_debug_log( "GSC Prioritization cancelled: Job {$job_id}" );
		}

		return true;
	}

	/**
	 * Get current prioritization progress
	 *
	 * @return array|false Progress data or false if no job running
	 */
	public function get_progress() {
		return get_transient( self::PROGRESS_TRANSIENT );
	}

	/**
	 * Get total count of posts for prioritization
	 * Much more efficient than loading all IDs
	 *
	 * @return int Total number of published posts
	 */
	private function get_total_posts_count() {
		return intval( wp_count_posts()->publish );
	}

	/**
	 * Get a batch of post IDs for processing
	 * Fetches only the posts needed for current batch
	 *
	 * @param int $offset Starting offset
	 * @param int $limit Number of posts to fetch
	 * @return array Array of post IDs
	 */
	private function get_posts_batch( $offset, $limit ) {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'post'
                 AND post_status = 'publish'
                 ORDER BY post_date DESC
                 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return array_map( 'intval', $post_ids );
	}

	/**
	 * Check if a prioritization job is currently running
	 *
	 * @return bool
	 */
	public function is_running() {
		$progress = get_transient( self::PROGRESS_TRANSIENT );
		return $progress && $progress['status'] === 'running';
	}

	/**
	 * Cleanup stale jobs (older than 2 hours stuck in 'running' status)
	 * Should be called by a daily cron job
	 */
	public function cleanup_stale_jobs() {
		$progress = get_transient( self::PROGRESS_TRANSIENT );

		if ( $progress && $progress['status'] === 'running' ) {
			$age = time() - $progress['started_at'];

			if ( $age > HOUR_IN_SECONDS ) {
				freshrank_debug_log( "Cleaning up stale job: {$progress['job_id']} (age: {$age}s)" );

				$progress['status']   = 'timeout';
				$progress['errors'][] = array(
					'critical' => true,
					'error'    => 'Job timeout - exceeded 2 hour limit',
				);
				set_transient( self::PROGRESS_TRANSIENT, $progress, HOUR_IN_SECONDS );

				// Unschedule any pending batches
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( self::AS_HOOK, null, self::AS_GROUP );
				}
			}
		}
	}
}
