<?php
/**
 * Content Updater Orchestrator for FreshRank AI
 * Main entry point that coordinates API calls, prompts, validation, and draft creation
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Content_Updater {

	private $api_client;
	private $validator;
	private $prompt_builder;
	private $draft_generator;
	private $database;
	private $analyzer;

	/**
	 * Constructor - Initialize all components
	 */
	public function __construct() {
		// Initialize API client (detects provider and loads settings automatically)
		$this->api_client = new FreshRank_API_Client();

		// Initialize singleton components
		$this->validator       = FreshRank_Content_Validator::get_instance();
		$this->prompt_builder  = FreshRank_Prompt_Builder::get_instance();
		$this->draft_generator = FreshRank_Draft_Generator::get_instance();

		// Initialize database and analyzer
		$this->database = FreshRank_Database::get_instance();
		$this->analyzer = new FreshRank_AI_Analyzer();
	}

	/**
	 * Create updated draft for a single article
	 * Main entry point for draft creation
	 *
	 * @param int $post_id Post ID
	 * @return int Post ID after draft creation
	 * @throws Exception If validation or creation fails
	 */
	public function create_updated_draft( $post_id ) {
		$start_time = microtime( true );
		freshrank_debug_log( '=== DRAFT CREATION STARTED for post ID: ' . $post_id . ' ===' );

		// Capability check - Requires manage_freshrank or edit_posts as fallback
		if ( ! current_user_can( 'manage_freshrank' ) && ! current_user_can( 'edit_posts' ) ) {
			freshrank_debug_log( 'Permission denied for post ID: ' . $post_id );
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		// Get post
		$post = get_post( $post_id );
		if ( ! $post ) {
			freshrank_debug_log( 'Post not found for ID: ' . $post_id );
			throw new Exception( 'Post not found.' );
		}

		// Get analysis data
		$analysis = $this->database->get_analysis( $post_id );
		if ( ! $analysis || $analysis->status !== 'completed' ) {
			freshrank_debug_log( 'No completed analysis found for post ID: ' . $post_id . ', status: ' . ( $analysis ? $analysis->status : 'none' ) );
			throw new Exception( 'No completed analysis found for this post. Please analyze the article first.' );
		}

		// Check if any matrix filters are enabled (MATRIX SYSTEM)
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', 1 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		$fix_factual       = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux            = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search        = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai            = get_option( 'freshrank_fix_ai_visibility', 0 );
		$fix_opportunities = get_option( 'freshrank_fix_opportunities', 0 );

		// Check if at least one severity level AND one category is enabled
		$has_severity = ( $severity_high || $severity_medium || $severity_low );
		$has_category = ( $fix_factual || $fix_ux || $fix_search || $fix_ai || $fix_opportunities );

		if ( ! $has_severity || ! $has_category ) {
			freshrank_debug_log( 'Matrix filters invalid for post ID: ' . $post_id . ' (severity: ' . ( $has_severity ? 'yes' : 'no' ) . ', category: ' . ( $has_category ? 'yes' : 'no' ) . ')' );
			throw new Exception( 'Matrix filters are not properly configured. Please ensure at least one severity level AND one category are enabled in AI settings.' );
		}

		// Generate updated content
		try {
			$update_result   = $this->generate_updated_content( $post, $analysis->analysis_data );
			$updated_content = $update_result['content'];
			$token_data      = $update_result['usage'];
		} catch ( Exception $e ) {
			freshrank_debug_log( 'FATAL ERROR in generate_updated_content: ' . $e->getMessage() );
			freshrank_debug_log( 'Stack trace: ' . $e->getTraceAsString() );
			throw $e;
		}

		// Create revision-based draft (preferred method)
		return $this->draft_generator->create_revision_draft( $post_id, $post, $updated_content, $token_data, $analysis );
	}

	/**
	 * Generate updated content using AI
	 * Coordinates prompt building, API call, and validation
	 *
	 * @param WP_Post $post Post object
	 * @param string $analysis_data Analysis results (JSON string)
	 * @return array Array with 'content' and 'usage' keys
	 * @throws Exception If generation or validation fails
	 */
	private function generate_updated_content( $post, $analysis_data ) {
		// Decode analysis data if it's a JSON string
		if ( is_string( $analysis_data ) ) {
			$analysis_data = json_decode( $analysis_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new Exception( 'Invalid analysis data: ' . json_last_error_msg() );
			}
		}

		// Prepare current content
		$current_content = $this->prompt_builder->prepare_content_for_update( $post );

		// Create update prompt
		$prompt = $this->prompt_builder->create_update_prompt( $post, $current_content, $analysis_data );

		// Call AI API
		$result = $this->api_client->call_api( $prompt );

		// Parse and validate the result
		$parsed_content    = $this->validator->parse_update_result( $result['content'] );
		$validated_content = $this->validator->validate_updated_content( $parsed_content, $current_content );

		return array(
			'content' => $validated_content,
			'usage'   => $result['usage'],
		);
	}

	/**
	 * Create drafts for multiple articles (bulk operation)
	 *
	 * @param array $post_ids Array of post IDs
	 * @return array Results array with success/error for each post
	 */
	public function create_bulk_drafts( $post_ids ) {
		$results          = array();
		$rate_limit_delay = get_option( 'freshrank_rate_limit_delay', 1000 ) * 1000; // Convert to microseconds

		foreach ( $post_ids as $post_id ) {
			try {
				$draft_id            = $this->create_updated_draft( $post_id );
				$results[ $post_id ] = array(
					'success'  => true,
					'draft_id' => $draft_id,
				);
			} catch ( Exception $e ) {
				$results[ $post_id ] = array(
					'success' => false,
					'error'   => $e->getMessage(),
				);
			}

			// Rate limiting delay
			if ( count( $post_ids ) > 1 ) {
				usleep( $rate_limit_delay );
			}
		}

		return $results;
	}

	/**
	 * Approve draft and replace original content
	 * CRITICAL: This performs a complete reset - treats article as brand new content
	 *
	 * @param int $draft_id Draft post ID
	 * @param int $original_id Original post ID
	 * @return bool True on success
	 * @throws Exception If approval fails
	 */
	public function approve_draft( $draft_id, $original_id ) {
		// Capability check - Requires manage_freshrank capability
		if ( ! current_user_can( 'manage_freshrank' ) ) {
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		$draft    = get_post( $draft_id );
		$original = get_post( $original_id );

		if ( ! $draft || ! $original ) {
			throw new Exception( 'Draft or original post not found.' );
		}

		global $wpdb;

		// START TRANSACTION for data integrity
		$wpdb->query( 'START TRANSACTION' );

		try {
			// STEP 1: Capture "before update" analytics snapshot
			$this->draft_generator->capture_before_analytics( $original_id );

			// STEP 2: Backup original content
			$this->draft_generator->create_content_backup( $original_id );

			// STEP 3: Update original post with draft content
			// Clean the title: remove [FreshRank Draft] prefix and any legacy suffixes
			$clean_title = $draft->post_title;
			$clean_title = preg_replace( '/^\[FreshRank Draft\]\s*/', '', $clean_title );
			$clean_title = str_replace( ' (Updated Draft)', '', $clean_title );

			$update_data = array(
				'ID'                => $original_id,
				'post_title'        => $clean_title,
				'post_content'      => $draft->post_content,
				'post_excerpt'      => $draft->post_excerpt,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			);

			$result = wp_update_post( $update_data );

			if ( is_wp_error( $result ) ) {
				throw new Exception( 'Failed to update original post: ' . $result->get_error_message() );
			}

			// STEP 4: Copy meta fields from draft to original
			$this->draft_generator->copy_draft_meta_to_original( $draft_id, $original_id );

			// STEP 5: Mark article as updated for analytics tracking
			update_post_meta( $original_id, '_freshrank_update_approved_date', current_time( 'mysql' ) );

			// STEP 6: *** COMPLETE RESET - TREAT AS BRAND NEW CONTENT ***
			$this->draft_generator->complete_article_reset( $original_id );

			// STEP 7: Remove draft relationship
			$this->database->remove_draft_relationship( $draft_id );

			// STEP 8: Delete the draft
			wp_delete_post( $draft_id, true );

			// STEP 9: Log the approval
			$this->draft_generator->log_content_update( $original_id, 'approved' );

			// STEP 10: COMMIT transaction - all operations succeeded
			$wpdb->query( 'COMMIT' );

			return true;

		} catch ( Exception $e ) {
			// ROLLBACK on any error
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Get drafts with their original posts
	 * Includes detailed information about each draft
	 *
	 * @return array Array of draft details
	 */
	public function get_drafts_with_details() {
		$drafts          = $this->database->get_drafts();
		$detailed_drafts = array();

		foreach ( $drafts as $draft ) {
			$draft_post    = get_post( $draft->draft_post_id );
			$original_post = get_post( $draft->original_post_id );

			if ( ! $draft_post || ! $original_post ) {
				continue;
			}

			// Get update information from meta
			$changes_made   = get_post_meta( $draft->draft_post_id, '_freshrank_changes_made', true );
			$update_summary = get_post_meta( $draft->draft_post_id, '_freshrank_update_summary', true );

			if ( is_array( $update_summary ) ) {
				$summary_pieces = array();
				foreach ( $update_summary as $summary_item ) {
					if ( is_string( $summary_item ) && trim( $summary_item ) !== '' ) {
						$summary_pieces[] = trim( $summary_item );
					}
				}
				$update_summary = ! empty( $summary_pieces ) ? implode( "\n", $summary_pieces ) : '';
			} elseif ( ! is_string( $update_summary ) ) {
				$update_summary = '';
			}

			$detailed_drafts[] = array(
				'id'                => $draft->id,
				'original_id'       => $draft->original_post_id,
				'draft_id'          => $draft->draft_post_id,
				'original_title'    => $original_post->post_title,
				'draft_title'       => str_replace( ' (Updated Draft)', '', $draft_post->post_title ),
				'created_date'      => $draft->created_at,
				'status'            => $draft->status,
				'changes_count'     => is_array( $changes_made ) ? count( $changes_made ) : 0,
				'update_summary'    => $update_summary,
				'draft_edit_url'    => admin_url( 'post.php?post=' . $draft->draft_post_id . '&action=edit' ),
				'original_edit_url' => admin_url( 'post.php?post=' . $draft->original_post_id . '&action=edit' ),
				'preview_url'       => get_preview_post_link( $draft->draft_post_id ),
			);
		}

		return $detailed_drafts;
	}

	/**
	 * Get filtering statistics for reporting (MATRIX SYSTEM)
	 * Shows how many issues were found vs. will be fixed
	 *
	 * @param array|string $analysis_data Analysis results
	 * @return array Statistics array
	 */
	public function get_filtering_stats( $analysis_data ) {
		// Decode if it's a JSON string
		if ( is_string( $analysis_data ) ) {
			$analysis_data = json_decode( $analysis_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return array(
					'total_found' => 0,
					'will_fix'    => 0,
					'skipped'     => 0,
					'breakdown'   => array(),
				);
			}
		}

		// Get matrix filter settings
		$fix_factual       = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux            = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search        = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai            = get_option( 'freshrank_fix_ai_visibility', 0 );
		$fix_opportunities = get_option( 'freshrank_fix_opportunities', 0 );

		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		$stats = array(
			'total_found' => 0,
			'will_fix'    => 0,
			'skipped'     => 0,
			'breakdown'   => array(),
		);

		// Count Factual Updates
		if ( ! empty( $analysis_data['factual_updates'] ) ) {
			$total    = count( $analysis_data['factual_updates'] );
			$filtered = $fix_factual ? count( $this->prompt_builder->filter_issues_by_severity( $analysis_data['factual_updates'], $severity_high, $severity_medium, $severity_low ) ) : 0;

			$stats['total_found']         += $total;
			$stats['will_fix']            += $filtered;
			$stats['skipped']             += ( $total - $filtered );
			$stats['breakdown']['factual'] = array(
				'total'  => $total,
				'fixing' => $filtered,
			);
		}

		// Count User Experience
		if ( ! empty( $analysis_data['user_experience']['issues'] ) ) {
			$total    = count( $analysis_data['user_experience']['issues'] );
			$filtered = $fix_ux ? count( $this->prompt_builder->filter_issues_by_severity( $analysis_data['user_experience']['issues'], $severity_high, $severity_medium, $severity_low ) ) : 0;

			$stats['total_found']    += $total;
			$stats['will_fix']       += $filtered;
			$stats['skipped']        += ( $total - $filtered );
			$stats['breakdown']['ux'] = array(
				'total'  => $total,
				'fixing' => $filtered,
			);
		}

		// Count Search Optimization
		if ( ! empty( $analysis_data['search_optimization'] ) ) {
			$total    = count( $analysis_data['search_optimization'] );
			$filtered = $fix_search ? count( $this->prompt_builder->filter_issues_by_severity( $analysis_data['search_optimization'], $severity_high, $severity_medium, $severity_low ) ) : 0;

			$stats['total_found']        += $total;
			$stats['will_fix']           += $filtered;
			$stats['skipped']            += ( $total - $filtered );
			$stats['breakdown']['search'] = array(
				'total'  => $total,
				'fixing' => $filtered,
			);
		}

		// Count AI Visibility
		if ( ! empty( $analysis_data['ai_visibility']['issues'] ) ) {
			$total    = count( $analysis_data['ai_visibility']['issues'] );
			$filtered = $fix_ai ? count( $this->prompt_builder->filter_issues_by_severity( $analysis_data['ai_visibility']['issues'], $severity_high, $severity_medium, $severity_low ) ) : 0;

			$stats['total_found']    += $total;
			$stats['will_fix']       += $filtered;
			$stats['skipped']        += ( $total - $filtered );
			$stats['breakdown']['ai'] = array(
				'total'  => $total,
				'fixing' => $filtered,
			);
		}

		// Count Opportunities
		if ( ! empty( $analysis_data['opportunities'] ) ) {
			$total    = count( $analysis_data['opportunities'] );
			$filtered = ( $fix_opportunities ) ? count(
				array_filter(
					$analysis_data['opportunities'],
					function ( $opp ) use ( $severity_high, $severity_medium, $severity_low ) {
						// If opportunity has severity, apply severity filter
						if ( isset( $opp['severity'] ) ) {
							return $this->prompt_builder->should_include_by_severity( $opp['severity'], $severity_high, $severity_medium, $severity_low );
						}
						return true;
					}
				)
			) : 0;

			$stats['total_found']               += $total;
			$stats['will_fix']                  += $filtered;
			$stats['skipped']                   += ( $total - $filtered );
			$stats['breakdown']['opportunities'] = array(
				'total'  => $total,
				'fixing' => $filtered,
			);
		}

		return $stats;
	}
}
