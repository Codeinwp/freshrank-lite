<?php
/**
 * Dashboard Actions
 * Handles user actions (clear, exclude, include)
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Dashboard_Actions {

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
	 * Handle clear analysis action
	 */
	public function handle_clear_analysis( $post_id ) {
		try {
			$analyzer = new FreshRank_AI_Analyzer();
			$analyzer->clear_analysis( $post_id );

			wp_redirect(
				add_query_arg(
					array(
						'message' => 'analysis_cleared',
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		} catch ( Exception $e ) {
			wp_redirect(
				add_query_arg(
					array(
						'error' => urlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle clear draft action - removes draft post and all related data
	 */
	public function handle_clear_draft( $post_id ) {
		try {
			global $wpdb;

			// Get draft info
			$drafts_table = $wpdb->prefix . 'freshrank_drafts';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
			$draft = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT draft_post_id FROM {$drafts_table} WHERE original_post_id = %d",
					$post_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Delete draft post if exists
			if ( $draft && $draft->draft_post_id ) {
				wp_delete_post( $draft->draft_post_id, true );
			}

			// Remove draft relationship from database
			$wpdb->delete( $drafts_table, array( 'original_post_id' => $post_id ), array( '%d' ) );

			// Clear ALL draft-related meta fields
			delete_post_meta( $post_id, '_freshrank_ai_revision_id' );
			delete_post_meta( $post_id, '_freshrank_has_revision_draft' );
			delete_post_meta( $post_id, '_freshrank_last_ai_update' );
			delete_post_meta( $post_id, '_freshrank_original_content_backup' );
			delete_post_meta( $post_id, '_freshrank_original_title_backup' );
			delete_post_meta( $post_id, '_freshrank_original_excerpt_backup' );

			// Clear draft status from analysis table
			$analysis_table = $wpdb->prefix . 'freshrank_analysis';
			$wpdb->update(
				$analysis_table,
				array( 'status' => 'completed' ),
				array( 'post_id' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);

			wp_redirect(
				add_query_arg(
					array(
						'message' => 'draft_cleared',
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		} catch ( Exception $e ) {
			wp_redirect(
				add_query_arg(
					array(
						'error' => urlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle exclude article action
	 */
	public function handle_exclude_article( $post_id ) {
		try {
			$this->database->set_article_excluded( $post_id, true );

			wp_redirect(
				add_query_arg(
					array(
						'message' => 'article_excluded',
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		} catch ( Exception $e ) {
			wp_redirect(
				add_query_arg(
					array(
						'error' => urlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle include article action
	 */
	public function handle_include_article( $post_id ) {
		try {
			$this->database->set_article_excluded( $post_id, false );

			wp_redirect(
				add_query_arg(
					array(
						'message' => 'article_included',
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		} catch ( Exception $e ) {
			wp_redirect(
				add_query_arg(
					array(
						'error' => urlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php?page=freshrank-ai' )
				)
			);
			exit;
		}
	}
}
