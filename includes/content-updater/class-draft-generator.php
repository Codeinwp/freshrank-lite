<?php
/**
 * Draft Generator for FreshRank AI Content Updater
 * Creates and manages draft posts, handles approvals/rejections
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Draft_Generator {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Get singleton instance
	 *
	 * @return FreshRank_Draft_Generator
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton
	 */
	private function __construct() {
		$this->database = FreshRank_Database::get_instance();
	}

	/**
	 * Detect which SEO plugin is active
	 * Checks for Yoast SEO, RankMath, and SEOPress
	 *
	 * @return string|null Plugin slug ('yoast', 'rankmath', 'seopress') or null if none detected
	 */
	private function get_active_seo_plugin() {
		// Check for Yoast SEO
		if ( class_exists( 'WPSEO' ) || defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		// Check for RankMath
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}

		// Check for SEOPress
		if ( class_exists( 'SEOPRESS_Class' ) || defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}

		return null;
	}

	/**
	 * Get the meta key for SEO title based on active plugin
	 *
	 * @param string $plugin Plugin slug
	 * @return string|null Meta key or null if plugin not recognized
	 */
	private function get_seo_title_meta_key( $plugin ) {
		$meta_keys = array(
			'yoast'    => '_yoast_wpseo_title',
			'rankmath' => 'rank_math_title',
			'seopress' => '_seopress_titles_title',
		);

		return isset( $meta_keys[ $plugin ] ) ? $meta_keys[ $plugin ] : null;
	}

	/**
	 * Get the meta key for SEO description based on active plugin
	 *
	 * @param string $plugin Plugin slug
	 * @return string|null Meta key or null if plugin not recognized
	 */
	private function get_seo_description_meta_key( $plugin ) {
		$meta_keys = array(
			'yoast'    => '_yoast_wpseo_metadesc',
			'rankmath' => 'rank_math_description',
			'seopress' => '_seopress_titles_desc',
		);

		return isset( $meta_keys[ $plugin ] ) ? $meta_keys[ $plugin ] : null;
	}

	/**
	 * Create revision draft
	 * Creates a temporary draft in the main post, then immediately restores original
	 * This preserves the draft as a revision for comparison
	 *
	 * @param int $post_id Post ID
	 * @param WP_Post $post Post object
	 * @param array $updated_content Updated content from AI
	 * @param array $token_data Token usage data
	 * @param object $analysis Analysis object
	 * @return int Post ID
	 * @throws Exception If draft creation fails
	 */
	public function create_revision_draft( $post_id, $post, $updated_content, $token_data, $analysis ) {
		$severity_info = $this->get_severity_filter_summary();

		// Extract addressed issues from the AI response
		$addressed_issues = array();
		if ( ! empty( $updated_content['addressed_issues'] ) && is_array( $updated_content['addressed_issues'] ) ) {
			$addressed_issues = $updated_content['addressed_issues'];
		} elseif ( ! empty( $updated_content['changes_made'] ) && is_array( $updated_content['changes_made'] ) ) {
			$addressed_issues = $updated_content['changes_made'];
		}

		// Store original content and dates before making any changes
		$original_title        = $post->post_title;
		$original_content      = $post->post_content;
		$original_excerpt      = $post->post_excerpt;
		$original_modified     = $post->post_modified;
		$original_modified_gmt = $post->post_modified_gmt;

		// STEP 1: Create actual WordPress draft post
		$draft_data = array(
			'post_title'     => '[FreshRank Draft] ' . $updated_content['title'],
			'post_content'   => $updated_content['content'],
			'post_excerpt'   => $updated_content['excerpt'],
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'post_parent'    => 0,
			'post_author'    => get_current_user_id(),
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_category'  => wp_get_post_categories( $post_id ),
		);

		$draft_id = wp_insert_post( $draft_data, true );

		if ( is_wp_error( $draft_id ) ) {
			throw new Exception( 'Failed to create draft post: ' . $draft_id->get_error_message() );
		}

		// Copy tags to draft
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $draft_id, $tags );
		}

		// Store link to original post
		update_post_meta( $draft_id, '_freshrank_original_post_id', $post_id );

		// Mark this as a FreshRank draft
		update_post_meta( $draft_id, '_freshrank_is_draft', true );
		update_post_meta( $draft_id, '_freshrank_created_date', current_time( 'mysql' ) );

		// Copy meta fields to draft
		$this->update_post_meta( $draft_id, $updated_content );
		update_post_meta( $draft_id, '_freshrank_update_severity', $addressed_issues );
		update_post_meta( $draft_id, '_freshrank_severity_summary', $severity_info );

		// STEP 2: Update post with AI content temporarily (for revision)
		// Use filter to prevent WordPress from updating post_modified date
		$preserve_dates = function ( $data, $postarr ) use ( $post_id, $original_modified, $original_modified_gmt ) {
			if ( isset( $postarr['ID'] ) && $postarr['ID'] == $post_id ) {
				$data['post_modified']     = $original_modified;
				$data['post_modified_gmt'] = $original_modified_gmt;
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $preserve_dates, 99, 2 );

		$update_data = array(
			'ID'           => $post_id,
			'post_title'   => $updated_content['title'],
			'post_content' => $updated_content['content'],
			'post_excerpt' => $updated_content['excerpt'],
			'edit_date'    => true, // Prevent auto-update of post_modified
		);

		$result = wp_update_post( $update_data, true );

		remove_filter( 'wp_insert_post_data', $preserve_dates, 99 );

		// Double-check: Force the dates back if WordPress changed them
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $original_modified,
				'post_modified_gmt' => $original_modified_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( is_wp_error( $result ) ) {
			// If revision creation fails, delete the draft post
			wp_delete_post( $draft_id, true );
			throw new Exception( 'Failed to create AI revision: ' . $result->get_error_message() );
		}

		// STEP 3: Immediately restore original content to main post
		// This creates a second revision and leaves the main post unchanged
		add_filter( 'wp_insert_post_data', $preserve_dates, 99, 2 );

		$restore_data = array(
			'ID'           => $post_id,
			'post_title'   => $original_title,
			'post_content' => $original_content,
			'post_excerpt' => $original_excerpt,
			'edit_date'    => true, // Prevent auto-update of post_modified
		);

		$restore_result = wp_update_post( $restore_data, true );

		remove_filter( 'wp_insert_post_data', $preserve_dates, 99 );

		// Double-check: Force the dates back if WordPress changed them
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $original_modified,
				'post_modified_gmt' => $original_modified_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( is_wp_error( $restore_result ) ) {
			// If restoration fails, delete the draft post
			wp_delete_post( $draft_id, true );
			throw new Exception( 'Failed to restore original content: ' . $restore_result->get_error_message() );
		}

		// Get the AI revision ID (second-to-last revision) to save for later reference
		$revisions       = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 2 ) );
		$revisions_array = array_values( $revisions );
		$ai_revision_id  = isset( $revisions_array[1] ) ? $revisions_array[1]->ID : 0;

		// Store AI update metadata for tracking (on main post)
		update_post_meta( $post_id, '_freshrank_last_ai_update', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_freshrank_ai_revision_id', $ai_revision_id );
		update_post_meta( $post_id, '_freshrank_draft_post_id', $draft_id );
		update_post_meta( $post_id, '_freshrank_update_severity', $addressed_issues );
		update_post_meta( $post_id, '_freshrank_severity_summary', $severity_info );
		update_post_meta( $post_id, '_freshrank_token_usage', $token_data );

		// Store additional metadata if available
		if ( ! empty( $updated_content['seo_improvements'] ) ) {
			update_post_meta( $post_id, '_freshrank_seo_improvements', $updated_content['seo_improvements'] );
		}
		if ( ! empty( $updated_content['content_updates'] ) ) {
			update_post_meta( $post_id, '_freshrank_content_updates', $updated_content['content_updates'] );
		}
		if ( ! empty( $updated_content['update_summary'] ) ) {
			update_post_meta( $post_id, '_freshrank_update_summary', $updated_content['update_summary'] );
		}

		// Save draft relationship in database
		$this->database->save_draft_relationship( $post_id, $draft_id, $analysis->id, $token_data );

		// Set draft status to 'completed'
		$this->database->update_draft_status( $post_id, 'completed' );

		return $post_id;
	}

	/**
	 * Create separate draft post for review (fallback when revisions disabled)
	 * Creates actual WordPress draft post with unique identifier
	 *
	 * @param int $post_id Post ID
	 * @param WP_Post $post Post object
	 * @param array $updated_content Updated content from AI
	 * @param array $token_data Token usage data
	 * @param object $analysis Analysis object
	 * @return int Draft post ID
	 * @throws Exception If draft creation fails
	 */
	public function create_draft_post( $post_id, $post, $updated_content, $token_data, $analysis ) {
		$severity_info = $this->get_severity_filter_summary();

		// Extract addressed issues from the AI response
		$addressed_issues = array();
		if ( ! empty( $updated_content['addressed_issues'] ) && is_array( $updated_content['addressed_issues'] ) ) {
			$addressed_issues = $updated_content['addressed_issues'];
		} elseif ( ! empty( $updated_content['changes_made'] ) && is_array( $updated_content['changes_made'] ) ) {
			// Fallback to changes_made if addressed_issues not present
			$addressed_issues = $updated_content['changes_made'];
		}

		// use old title if it contains '(only if analysis found title issues)' else use the new title
		$draft_title = strpos( $updated_content['title'], '(only if analysis found title issues)' ) !== false ? $post->post_title : $updated_content['title'];

		// Create draft post with unique identifier in title
		$draft_data = array(
			'post_title'     => '[FreshRank Draft] ' . $draft_title,
			'post_content'   => $updated_content['content'],
			'post_excerpt'   => $updated_content['excerpt'],
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'post_parent'    => 0,
			'post_author'    => get_current_user_id(),
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_category'  => wp_get_post_categories( $post_id ),
		);

		$draft_id = wp_insert_post( $draft_data, true );

		if ( is_wp_error( $draft_id ) ) {
			throw new Exception( 'Failed to create draft post: ' . $draft_id->get_error_message() );
		}

		// Copy tags
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $draft_id, $tags );
		}

		// Update meta fields on draft
		$this->update_post_meta( $draft_id, $updated_content );

		// Store link to original post
		update_post_meta( $draft_id, '_freshrank_original_post_id', $post_id );

		// Store severity info and addressed issues on draft for display
		update_post_meta( $draft_id, '_freshrank_update_severity', $addressed_issues );
		update_post_meta( $draft_id, '_freshrank_severity_summary', $severity_info );

		// Mark this as a FreshRank draft
		update_post_meta( $draft_id, '_freshrank_is_draft', true );
		update_post_meta( $draft_id, '_freshrank_created_date', current_time( 'mysql' ) );

		// Save draft relationship
		$this->database->save_draft_relationship( $post_id, $draft_id, $analysis->id, $token_data );
		$this->database->update_draft_status( $post_id, 'completed' );

		return $draft_id;
	}

	/**
	 * Complete article reset
	 * Clears all analysis data and resets article status
	 *
	 * @param int $post_id Post ID
	 */
	public function complete_article_reset( $post_id ) {
		// 1. Clear ALL analysis data completely
		$analyzer = new FreshRank_AI_Analyzer();
		$analyzer->clear_analysis( $post_id );

		// 2. Reset article status to pending in database
		global $wpdb;
		$articles_table = $wpdb->prefix . 'freshrank_articles';
		$analysis_table = $wpdb->prefix . 'freshrank_analysis';

		// Delete ALL analysis records for this post
		$wpdb->delete( $analysis_table, array( 'post_id' => $post_id ), array( '%d' ) );

		// Check if article exists in our tracking table
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$articles_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			// Reset only analysis/draft status - PRESERVE all GSC/priority data
			$wpdb->update(
				$articles_table,
				array(
					'analysis_status' => 'pending',
					'draft_status'    => 'pending',
				),
				array( 'post_id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Create new record with pending status
			$wpdb->insert(
				$articles_table,
				array(
					'post_id'         => $post_id,
					'analysis_status' => 'pending',
					'draft_status'    => 'pending',
					'priority_score'  => 0,
					'excluded'        => 0,
				),
				array( '%d', '%s', '%s', '%f', '%d' )
			);
		}

		// 3. Clear ALL analysis and update related meta data
		$this->clear_all_freshrank_meta( $post_id );

		// 4. Remove any draft relationships that might still exist
		$wpdb->delete(
			$wpdb->prefix . 'freshrank_drafts',
			array( 'original_post_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Clear ALL FreshRank-related meta data - complete reset
	 *
	 * @param int $post_id Post ID
	 */
	public function clear_all_freshrank_meta( $post_id ) {
		// Analysis-related meta
		$meta_keys_to_clear = array(
			// Analysis data
			'_freshrank_analysis_completed',
			'_freshrank_analysis_issues_count',
			'_freshrank_analysis_summary',
			'_freshrank_analysis_data',
			'_freshrank_analysis_errors',
			'_freshrank_seo_score',
			'_freshrank_freshness_score',
			'_freshrank_overall_score',

			// Update status flags
			'_freshrank_content_updated_flag',
			'_freshrank_last_updated',
			'_freshrank_last_updated_by',
			'_freshrank_content_backup_timestamp',

			// Draft-related meta from previous updates
			'_freshrank_changes_made',
			'_freshrank_seo_improvements',
			'_freshrank_content_updates',
			'_freshrank_internal_links',
			'_freshrank_update_summary',
			'_freshrank_created_date',
			'_freshrank_addressed_issues',
			'_freshrank_analysis_driven',
			'_freshrank_last_ai_update',
			'_freshrank_ai_revision_id',
			'_freshrank_draft_post_id',
			'_freshrank_update_severity',
			'_freshrank_severity_summary',
			'_freshrank_has_revision_draft',
			// '_freshrank_token_usage', // DO NOT clear - needed for permanent cost tracking
			'_freshrank_original_content_backup',
			'_freshrank_original_title_backup',
			'_freshrank_original_excerpt_backup',

			// Status indicators
			'_freshrank_status',
			'_freshrank_processing_status',
			'_freshrank_has_draft',
			'_freshrank_recently_updated',
		);

		foreach ( $meta_keys_to_clear as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}

		// Also clear any backup meta that's not recent (keep only the most recent backup)
		$all_meta = get_post_meta( $post_id );
		foreach ( $all_meta as $key => $value ) {
			if ( strpos( $key, '_freshrank_content_backup_' ) === 0 ) {
				// Keep only the most recent backup, delete older ones
				$backups = array();
				foreach ( $all_meta as $backup_key => $backup_value ) {
					if ( strpos( $backup_key, '_freshrank_content_backup_' ) === 0 ) {
						$timestamp = str_replace( '_freshrank_content_backup_', '', $backup_key );
						if ( is_numeric( $timestamp ) ) {
							$backups[ $timestamp ] = $backup_key;
						}
					}
				}

				if ( count( $backups ) > 1 ) {
					krsort( $backups ); // Sort by timestamp descending
					$most_recent = array_shift( $backups ); // Keep the most recent

					// Delete all others
					foreach ( $backups as $old_backup_key ) {
						delete_post_meta( $post_id, $old_backup_key );
					}
				}
				break; // We only need to do this once
			}
		}
	}

	/**
	 * Copy meta fields from draft to original
	 *
	 * @param int $draft_id Draft post ID
	 * @param int $original_id Original post ID
	 */
	public function copy_draft_meta_to_original( $draft_id, $original_id ) {
		// Detect which SEO plugin is active
		$seo_plugin = $this->get_active_seo_plugin();

		// Build meta fields array based on active SEO plugin
		$meta_fields = array(
			'_freshrank_changes_made',
			'_freshrank_seo_improvements',
			'_freshrank_content_updates',
			'_freshrank_internal_links',
			'_freshrank_update_summary',
			'_freshrank_addressed_issues',
		);

		// Add SEO plugin-specific meta fields
		if ( $seo_plugin ) {
			$title_key       = $this->get_seo_title_meta_key( $seo_plugin );
			$description_key = $this->get_seo_description_meta_key( $seo_plugin );

			if ( $title_key ) {
				$meta_fields[] = $title_key;
			}
			if ( $description_key ) {
				$meta_fields[] = $description_key;
			}
		}

		foreach ( $meta_fields as $meta_key ) {
			$meta_value = get_post_meta( $draft_id, $meta_key, true );
			if ( ! empty( $meta_value ) ) {
				update_post_meta( $original_id, $meta_key, $meta_value );
			}
		}

		// Add approval timestamp
		update_post_meta( $original_id, '_freshrank_last_updated', current_time( 'mysql' ) );
		update_post_meta( $original_id, '_freshrank_last_updated_by', get_current_user_id() );
	}

	/**
	 * Create backup of original content
	 *
	 * @param int $post_id Post ID
	 */
	public function create_content_backup( $post_id ) {
		$post = get_post( $post_id );

		$backup_data = array(
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_date'     => $post->post_date,
			'post_modified' => $post->post_modified,
			'backup_date'   => current_time( 'mysql' ),
			'backup_reason' => 'Pre-AI-update backup',
		);

		update_post_meta( $post_id, '_freshrank_content_backup_' . time(), $backup_data );
	}

	/**
	 * Log content update
	 *
	 * @param int $post_id Post ID
	 * @param string $action Action performed
	 */
	public function log_content_update( $post_id, $action ) {
		$log_entry = array(
			'post_id'    => $post_id,
			'action'     => $action,
			'user_id'    => get_current_user_id(),
			'timestamp'  => current_time( 'mysql' ),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
		);

		$existing_log = get_post_meta( $post_id, '_freshrank_update_log', true );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}

		$existing_log[] = $log_entry;

		// Keep only last 10 log entries
		if ( count( $existing_log ) > 10 ) {
			$existing_log = array_slice( $existing_log, -10 );
		}

		update_post_meta( $post_id, '_freshrank_update_log', $existing_log );
	}

	/**
	 * Update post meta fields after AI update
	 *
	 * @param int $post_id Post ID
	 * @param array $updated_content Updated content array
	 */
	public function update_post_meta( $post_id, $updated_content ) {
		// Detect which SEO plugin is active and update appropriate meta fields
		$seo_plugin = $this->get_active_seo_plugin();

		if ( $seo_plugin ) {
			// Update SEO title if provided
			if ( ! empty( $updated_content['meta_title'] ) ) {
				$title_key = $this->get_seo_title_meta_key( $seo_plugin );
				if ( $title_key ) {
					update_post_meta( $post_id, $title_key, $updated_content['meta_title'] );
				}
			}

			// Update SEO meta description if provided
			if ( ! empty( $updated_content['meta_description'] ) ) {
				$description_key = $this->get_seo_description_meta_key( $seo_plugin );
				if ( $description_key ) {
					update_post_meta( $post_id, $description_key, $updated_content['meta_description'] );
				}
			}
		}

		// Store update information in meta
		update_post_meta( $post_id, '_freshrank_changes_made', $updated_content['changes_made'] );
		update_post_meta( $post_id, '_freshrank_seo_improvements', $updated_content['seo_improvements'] );
		update_post_meta( $post_id, '_freshrank_content_updates', $updated_content['content_updates'] );
		update_post_meta( $post_id, '_freshrank_internal_links', $updated_content['internal_links_suggestions'] );
		update_post_meta( $post_id, '_freshrank_update_summary', $updated_content['update_summary'] );

		// Store analysis-specific metadata
		update_post_meta( $post_id, '_freshrank_addressed_issues', $updated_content['addressed_issues'] ?? array() );
		update_post_meta( $post_id, '_freshrank_analysis_driven', true );
	}

	/**
	 * Capture before-update analytics snapshot from GSC
	 *
	 * @param int $post_id Post ID
	 */
	public function capture_before_analytics( $post_id ) {
		try {
			// Only capture if GSC is connected
			if ( ! get_option( 'freshrank_gsc_authenticated', false ) ) {
				return;
			}

			// Get current article data from database
			global $wpdb;
			$articles_table = $wpdb->prefix . 'freshrank_articles';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
			$article_data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$articles_table} WHERE post_id = %d",
					$post_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $article_data ) {
				return;
			}

			// Prepare GSC snapshot data
			$gsc_data = array(
				'clicks'       => $article_data->clicks_current,
				'impressions'  => $article_data->impressions_current,
				'ctr'          => $article_data->ctr_current,
				'position'     => $article_data->position_current,
				'period_start' => date( 'Y-m-d', strtotime( '-30 days' ) ),
				'period_end'   => date( 'Y-m-d' ),
			);

			// Try to get top queries for this URL
			try {
				$gsc_api     = freshrank_get_gsc_api();
				$post        = get_post( $post_id );
				$top_queries = $gsc_api->get_top_queries_for_url( get_permalink( $post_id ), 5 );
				if ( ! empty( $top_queries ) ) {
					$gsc_data['top_queries'] = $top_queries;
				}
			} catch ( Exception $e ) {
				$gsc_data['top_queries'] = array();
			}

			// Save the "before" snapshot
			$this->database->save_before_snapshot( $post_id, $gsc_data );

		} catch ( Exception $e ) {
			// Don't throw - analytics failure shouldn't block draft approval
		}
	}

	/**
	 * Get summary of current matrix filter settings (MATRIX SYSTEM)
	 *
	 * @return string Summary of enabled filters
	 */
	private function get_severity_filter_summary() {
		// FREE VERSION: Always Factual with High + Medium severity
		if ( freshrank_is_free_version() ) {
			return 'Factual [High + Med]';
		}

		$categories = array();
		$severities = array();

		// Get enabled categories
		if ( get_option( 'freshrank_fix_factual_updates', 1 ) ) {
			$categories[] = 'Factual';
		}
		if ( get_option( 'freshrank_fix_user_experience', 0 ) ) {
			$categories[] = 'UX';
		}
		if ( get_option( 'freshrank_fix_search_optimization', 0 ) ) {
			$categories[] = 'Search';
		}
		if ( get_option( 'freshrank_fix_ai_visibility', 0 ) ) {
			$categories[] = 'AI';
		}
		if ( get_option( 'freshrank_fix_opportunities', 0 ) ) {
			$categories[] = 'Opportunities';
		}

		// Get enabled severity levels
		if ( get_option( 'freshrank_severity_high', 1 ) ) {
			$severities[] = 'High';
		}
		if ( get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 ) ) {
			$severities[] = 'Med';
		}
		if ( get_option( 'freshrank_severity_low', 0 ) ) {
			$severities[] = 'Low';
		}

		if ( empty( $categories ) || empty( $severities ) ) {
			return 'No filters enabled';
		}

		return implode( '+', $categories ) . ' [' . implode( '+', $severities ) . ']';
	}
}
