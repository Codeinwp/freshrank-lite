<?php
/**
 * Prompt Builder for FreshRank AI Content Updater
 * Builds AI prompts based on analysis findings and user settings
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Prompt_Builder {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Number of actionable issues included in the most recent instructions build.
	 */
	private $last_actionable_count = 0;

	/**
	 * Get singleton instance
	 *
	 * @return FreshRank_Prompt_Builder
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
	 * Prepare content for update
	 * Extracts all relevant content from post and meta
	 *
	 * @param WP_Post $post Post object
	 * @return array Prepared content array
	 */
	public function prepare_content_for_update( $post ) {
		// Get current meta from active SEO plugin
		$meta_title = $this->get_seo_title( $post->ID );
		if ( empty( $meta_title ) ) {
			$meta_title = $post->post_title;
		}

		$meta_description = $this->get_seo_description( $post->ID );
		$focus_keyword    = $this->get_focus_keyword( $post->ID );

		return array(
			'title'            => $post->post_title,
			'content'          => $post->post_content,
			'excerpt'          => $post->post_excerpt,
			'meta_title'       => $meta_title,
			'meta_description' => $meta_description,
			'focus_keyword'    => $focus_keyword,
			'published_date'   => $post->post_date,
			'categories'       => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'             => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Get SEO title from popular SEO plugins
	 *
	 * @param int $post_id Post ID
	 * @return string SEO title or empty string if not found
	 */
	private function get_seo_title( $post_id ) {
		// Check Yoast SEO
		$title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		if ( ! empty( $title ) ) {
			return $title;
		}

		// Check RankMath
		$title = get_post_meta( $post_id, 'rank_math_title', true );
		if ( ! empty( $title ) ) {
			return $title;
		}

		// Check SEOPress
		$title = get_post_meta( $post_id, '_seopress_titles_title', true );
		if ( ! empty( $title ) ) {
			return $title;
		}

		return '';
	}

	/**
	 * Get SEO meta description from popular SEO plugins
	 *
	 * @param int $post_id Post ID
	 * @return string SEO meta description or empty string if not found
	 */
	private function get_seo_description( $post_id ) {
		// Check Yoast SEO
		$description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $description ) ) {
			return $description;
		}

		// Check RankMath
		$description = get_post_meta( $post_id, 'rank_math_description', true );
		if ( ! empty( $description ) ) {
			return $description;
		}

		// Check SEOPress
		$description = get_post_meta( $post_id, '_seopress_titles_desc', true );
		if ( ! empty( $description ) ) {
			return $description;
		}

		return '';
	}

	/**
	 * Get focus keyword from popular SEO plugins
	 *
	 * @param int $post_id Post ID
	 * @return string Focus keyword or empty string if not found
	 */
	private function get_focus_keyword( $post_id ) {
		// Check Yoast SEO
		$keyword = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( ! empty( $keyword ) ) {
			return $keyword;
		}

		// Check RankMath
		$keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		if ( ! empty( $keyword ) ) {
			return $keyword;
		}

		// Check SEOPress
		$keyword = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );
		if ( ! empty( $keyword ) ) {
			return $keyword;
		}

		return '';
	}

	/**
	 * Create update prompt for AI
	 * ENHANCED VERSION with detailed analysis integration
	 *
	 * @param WP_Post $post Post object
	 * @param array $current_content Current content array
	 * @param array $analysis_data Analysis results
	 * @return string Complete prompt for AI
	 */
	public function create_update_prompt( $post, $current_content, $analysis_data ) {
		$current_year = date( 'Y' );
		$current_date = date( 'F Y' );

		// Build comprehensive, structured instructions based on actual analysis findings
		$specific_instructions = $this->build_specific_instructions( $post, $analysis_data );

		if ( $this->get_last_actionable_count() === 0 ) {
			throw new Exception( __( 'No actionable analysis findings matched your current severity and category filters. Adjust your settings or re-run the analysis before creating a draft.', 'freshrank-ai' ) );
		}

		$priority_focus = $this->determine_priority_focus( $analysis_data );

		$prompt = "
Please update this blog post based on SPECIFIC analysis findings while maintaining the original voice, style, and core message.

**IMPORTANT CONTEXT:**
- The content below has been sanitized and all HTML/links have been stripped for analysis
- Internal and external links ARE present in the actual published content - assume links exist
- Focus on improving text content, headings, keywords, readability, and factual accuracy

**CURRENT POST INFORMATION:**
- SEO Title: {$current_content['meta_title']}
- SEO Meta Description: {$current_content['meta_description']}
- Published: {$current_content['published_date']}
- Current Date: {$current_date}
- Categories: " . implode( ', ', $current_content['categories'] ) . "
- Focus Keyword: {$current_content['focus_keyword']}

**CURRENT CONTENT:**
{$current_content['content']}

**ANALYSIS-DRIVEN UPDATE REQUIREMENTS:**
{$specific_instructions}

**PRIORITY FOCUS AREAS (Address these first):**
{$priority_focus}

**RESPONSE FORMAT:**
Provide your response in this JSON format:

{
    \"title\": \"Updated article title (only if analysis found title issues)\",
    \"meta_title\": \"Updated SEO title - optimal 50-60 characters, max 70 (only if analysis found meta issues)\",
    \"meta_description\": \"Updated SEO meta description - optimal 150-160 characters to avoid cutoff in search results (only if analysis found meta description issues)\",
    \"excerpt\": \"Updated excerpt if needed\",
    \"content\": \"Updated article content in proper HTML format with correct paragraph structure. IMPORTANT: Use <p> tags for all paragraphs, proper heading hierarchy (H2, H3, etc.), and maintain readability with appropriate spacing. Do NOT return one giant wall of text - break content into proper paragraphs.\",
    \"changes_made\": [
        \"ONLY list changes you ACTUALLY made - be specific and truthful\",
        \"Example: Updated outdated statistic in paragraph 3\",
        \"Example: Fixed broken heading hierarchy by changing H4 to H3\"
    ],
    \"internal_links_suggestions\": [
        {
            \"anchor_text\": \"Suggested anchor text\",
            \"context\": \"Where in content this should be placed\",
            \"target_topic\": \"What the link should point to\",
            \"reason\": \"Why this link improves SEO (based on analysis)\"
        }
    ],
    \"addressed_issues\": [
        \"Analysis issue 1: How you addressed it\",
        \"Analysis issue 2: How you addressed it\"
    ],
    \"update_summary\": \"Brief summary focusing on analysis-driven improvements\"
}

**CRITICAL GUIDELINES:**
- ONLY make changes that address the specific issues identified in the analysis
- If no title issues were found, keep the existing title
- Metadata updates beyond the title are optional; only include fields you actually changed
- Focus on the highest severity issues first
- Every change must be tied to a specific analysis finding
- Preserve all content that the analysis did not flag as problematic
- Maintain the author's voice and writing style
- Don't add unnecessary content - only address identified issues
- Do NOT include utm_source=openai or any similar UTM tracking parameters in links

**HTML FORMATTING REQUIREMENTS:**
- Use proper HTML paragraph structure with <p> tags for ALL paragraphs
- Maintain proper heading hierarchy (H2, H3, H4) - do not skip levels
- Add line breaks between paragraphs for readability
- Use <br> tags only for line breaks within paragraphs, not between paragraphs
- Format lists properly with <ul>/<ol> and <li> tags
- Preserve or add proper spacing to avoid walls of text
- Keep the HTML clean and semantic
- Keep all URLs clean without unnecessary query parameters
- **DO NOT add citations, references, or sources** that weren't in the original content
- **DO NOT add footnotes, superscript numbers, or citation markers** like [1], [2], etc.
- **DO NOT add editorial comments, advice, or notes in the content itself**
- **DO NOT add phrases like \"Remove this\", \"Update that\", \"Consider changing\" in the actual content**
- **JUST UPDATE THE CONTENT DIRECTLY - don't leave comments or suggestions for the author**
- Make focused changes that directly address analysis findings
- **PRESERVE all existing links unless the analysis specifically flagged them as broken or problematic**

**FACTUAL DATA UPDATES:**
- **DO verify and update factual data** when the analysis identifies it as outdated (commission rates, pricing, cookie windows, statistics, dates)
- Use web search tools if available to verify current information
- Update outdated facts directly in the content without adding verification timestamps
- If you find pricing/commission data is outdated, update it with current verified information
- Do NOT expand content unnecessarily - focus on updating what's flagged as outdated
- If you cannot verify specific data even with web search, update what you can and leave the rest

**YEAR REFERENCES - BE SUBTLE:**
- Update outdated years/dates in the content naturally when found (e.g., \"in 2022\" → \"in {$current_year}\")
- Do NOT mention the update year in the excerpt unless it was already there
- Do NOT add \"Updated for {$current_year}\" or \"Last updated: [date]\" text throughout the article
- **CRITICAL: Do NOT add \"Pricing last verified {$current_date}\" or \"(verified {$current_date})\" repeatedly after each price**
- **Do NOT add parenthetical notes like \"(as of {$current_date})\" after every price or fact - mentioning it once is enough**
- The post modified date will automatically reflect when content was updated - avoid excessive date mentions
- Be natural and subtle - only update dates/years that are factually outdated
- Focus on freshness through updated information, not by advertising the update date or verification date

**HTML PRESERVATION:**
- Do NOT change, remove, or modify existing HTML markup, classes, or attributes
- Keep all <div>, <span>, <p>, and other HTML tags exactly as they are
- Preserve CSS classes, IDs, and inline styles
- Only update the text content within HTML elements, never the structure
- If you need to update text inside an HTML element, keep the element wrapper intact

**TARGETED UPDATE APPROACH:**
Make surgical, precise updates that directly address the analysis findings rather than wholesale rewrites.
Keep changes to an absolute minimum - if the analysis didn't flag it, don't touch it.

**ACCURATE CHANGE REPORTING:**
- In the JSON response, ONLY report changes you ACTUALLY made to the content
- Be specific and truthful - don't list hypothetical improvements or things you planned but didn't do
- If you made NO changes to a category (e.g., no content updates), leave that array EMPTY []
- The change summary will be shown to the user - it must accurately reflect what changed
";

		// Append custom rewrite instructions if provided
		$custom_rewrite_prompt = get_option( 'freshrank_custom_rewrite_prompt', '' );
		if ( ! empty( $custom_rewrite_prompt ) ) {
			$prompt .= "\n\n**ADDITIONAL CUSTOM INSTRUCTIONS:**\n" . $custom_rewrite_prompt . "\n";
		}

		return $prompt;
	}

	/**
	 * Build specific instructions based on analysis findings AND user settings
	 * UPDATED to respect severity filter settings and exclude dismissed items
	 *
	 * @param WP_Post $post Post object
	 * @param array $analysis_data Analysis results
	 * @return string Formatted instructions
	 */
	public function build_specific_instructions( $post, $analysis_data ) {
		$instructions                = array();
		$this->last_actionable_count = 0;

		// Get dismissed items for this post
		$dismissed_items = $this->database->get_dismissed_items( $post->ID );

		// Get user's category selections (MATRIX SYSTEM)
		$fix_factual       = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux            = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search        = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai            = get_option( 'freshrank_fix_ai_visibility', 0 );
		$fix_opportunities = get_option( 'freshrank_fix_opportunities', 0 );

		// Get user's severity selections (MATRIX SYSTEM)
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', 1 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		// FREE VERSION: Force Factual only + HIGH severity only
		if ( freshrank_is_free_version() ) {
			$fix_factual       = 1;
			$fix_ux            = 0;
			$fix_search        = 0;
			$fix_ai            = 0;
			$fix_opportunities = 0;
			$severity_high     = 1;
			$severity_medium   = 1; // Lite allows medium severity factual fixes
			$severity_low      = 0;
		}

		// MAP OLD CATEGORY NAMES TO NEW (for backward compatibility with old analysis data)
		if ( isset( $analysis_data['seo_issues'] ) && ! isset( $analysis_data['search_optimization'] ) ) {
			$analysis_data['search_optimization'] = $analysis_data['seo_issues'];
		}
		if ( isset( $analysis_data['content_freshness'] ) && ! isset( $analysis_data['factual_updates'] ) ) {
			$analysis_data['factual_updates'] = $analysis_data['content_freshness'];
		}
		if ( isset( $analysis_data['content_quality'] ) && ! isset( $analysis_data['factual_updates'] ) ) {
			// Old content_quality mapped to factual_updates (content_quality is now deprecated)
			$analysis_data['factual_updates'] = $analysis_data['content_quality'];
		}
		if ( isset( $analysis_data['ux_issues'] ) && ! isset( $analysis_data['user_experience']['issues'] ) ) {
			$analysis_data['user_experience'] = array( 'issues' => $analysis_data['ux_issues'] );
		}
		if ( isset( $analysis_data['geo_analysis'] ) && ! isset( $analysis_data['ai_visibility'] ) ) {
			$analysis_data['ai_visibility'] = $analysis_data['geo_analysis'];
		}

		// Track what we're including for summary
		$included_categories = array();
		$skipped_count       = 0;
		$total_actionable    = 0;

		// 1. FACTUAL UPDATES (dedicated factual_updates category)
		if ( $fix_factual && ! empty( $analysis_data['factual_updates'] ) ) {
			$filtered       = $this->filter_issues_by_severity( $analysis_data['factual_updates'], $severity_high, $severity_medium, $severity_low, $dismissed_items, 'factual_updates' );
			$skipped_count += count( $analysis_data['factual_updates'] ) - count( $filtered );

			if ( ! empty( $filtered ) ) {
				$instructions[] = '**FACTUAL UPDATES (Content Quality):**';
				foreach ( $filtered as $issue ) {
					$severity_prefix = strtoupper( $issue['severity'] );
					$type            = $issue['type'] ?? 'update';
					$instructions[]  = "- [{$severity_prefix}] {$type}: {$issue['issue']}";
					if ( ! empty( $issue['current_value'] ) ) {
						$instructions[] = "  → CURRENT: {$issue['current_value']}";
					}
					if ( ! empty( $issue['suggested_update'] ) ) {
						$instructions[] = "  → UPDATE TO: {$issue['suggested_update']}";
					}
				}
				$instructions[]        = '';
				$included_categories[] = count( $filtered ) . ' factual updates';
				$total_actionable     += count( $filtered );
			}
		} elseif ( ! $fix_factual && ! empty( $analysis_data['factual_updates'] ) ) {
			$skipped_count += count( $analysis_data['factual_updates'] );
		}

		// 2. USER EXPERIENCE (user_experience category)
		if ( $fix_ux && ! empty( $analysis_data['user_experience']['issues'] ) ) {
			$filtered       = $this->filter_issues_by_severity( $analysis_data['user_experience']['issues'], $severity_high, $severity_medium, $severity_low, $dismissed_items, 'user_experience' );
			$skipped_count += count( $analysis_data['user_experience']['issues'] ) - count( $filtered );

			if ( ! empty( $filtered ) ) {
				$instructions[] = '**USER EXPERIENCE IMPROVEMENTS:**';
				foreach ( $filtered as $issue ) {
					$severity_prefix = strtoupper( $issue['severity'] );
					$instructions[]  = "- [{$severity_prefix}] {$issue['issue']}";
					$instructions[]  = "  → FIX: {$issue['recommendation']}";
					if ( ! empty( $issue['impact'] ) ) {
						$instructions[] = "  → IMPACT: {$issue['impact']}";
					}
				}
				$instructions[]        = '';
				$included_categories[] = count( $filtered ) . ' UX improvements';
				$total_actionable     += count( $filtered );
			}
		} elseif ( ! $fix_ux && ! empty( $analysis_data['user_experience']['issues'] ) ) {
			$skipped_count += count( $analysis_data['user_experience']['issues'] );
		}

		// 3. SEARCH OPTIMIZATION (search_optimization category)
		if ( $fix_search && ! empty( $analysis_data['search_optimization'] ) ) {
			$filtered       = $this->filter_issues_by_severity( $analysis_data['search_optimization'], $severity_high, $severity_medium, $severity_low, $dismissed_items, 'search_optimization' );
			$skipped_count += count( $analysis_data['search_optimization'] ) - count( $filtered );

			if ( ! empty( $filtered ) ) {
				$instructions[] = '**SEARCH OPTIMIZATION (Technical SEO):**';
				foreach ( $filtered as $issue ) {
					$severity_prefix = strtoupper( $issue['severity'] );
					$category        = $issue['category'] ?? 'seo';
					$instructions[]  = "- [{$severity_prefix}] {$category}: {$issue['issue']}";
					$instructions[]  = "  → ACTION: {$issue['recommendation']}";
					if ( ! empty( $issue['impact'] ) ) {
						$instructions[] = "  → IMPACT: {$issue['impact']}";
					}
				}
				$instructions[]        = '';
				$included_categories[] = count( $filtered ) . ' SEO optimizations';
				$total_actionable     += count( $filtered );
			}
		} elseif ( ! $fix_search && ! empty( $analysis_data['search_optimization'] ) ) {
			$skipped_count += count( $analysis_data['search_optimization'] );
		}

		// 4. AI VISIBILITY / GEO (ai_visibility category)
		if ( $fix_ai && ! empty( $analysis_data['ai_visibility']['issues'] ) ) {
			$filtered       = $this->filter_issues_by_severity( $analysis_data['ai_visibility']['issues'], $severity_high, $severity_medium, $severity_low, $dismissed_items, 'ai_visibility' );
			$skipped_count += count( $analysis_data['ai_visibility']['issues'] ) - count( $filtered );

			if ( ! empty( $filtered ) ) {
				$instructions[] = '**AI VISIBILITY (GEO) IMPROVEMENTS:**';
				foreach ( $filtered as $issue ) {
					$severity_prefix = strtoupper( $issue['severity'] );
					$instructions[]  = "- [{$severity_prefix}] {$issue['issue']}";
					$instructions[]  = "  → FIX: {$issue['recommendation']}";
					if ( ! empty( $issue['impact'] ) ) {
						$instructions[] = "  → IMPACT: {$issue['impact']}";
					}
				}
				$instructions[]        = '';
				$included_categories[] = count( $filtered ) . ' GEO improvements';
				$total_actionable     += count( $filtered );
			}
		} elseif ( ! $fix_ai && ! empty( $analysis_data['ai_visibility']['issues'] ) ) {
			$skipped_count += count( $analysis_data['ai_visibility']['issues'] );
		}

		// 5. OPTIMIZATION OPPORTUNITIES (opportunities category)
		if ( $fix_opportunities && ! empty( $analysis_data['opportunities'] ) ) {
			// Filter opportunities by severity (they may or may not have severity)
			$filtered = array_filter(
				$analysis_data['opportunities'],
				function ( $opp ) use ( $severity_high, $severity_medium, $severity_low ) {
					// If opportunity has severity, apply severity filter
					if ( isset( $opp['severity'] ) ) {
						return $this->should_include_by_severity( $opp['severity'], $severity_high, $severity_medium, $severity_low );
					}

					// Opportunities without severity are included if category is enabled
					return true;
				}
			);

			$skipped_count += count( $analysis_data['opportunities'] ) - count( $filtered );

			if ( ! empty( $filtered ) ) {
				$instructions[] = '**OPTIMIZATION OPPORTUNITIES:**';
				foreach ( $filtered as $opp ) {
					$type           = $opp['type'] ?? 'optimization';
					$instructions[] = "- OPPORTUNITY ({$type}): {$opp['opportunity']}";
					if ( ! empty( $opp['implementation'] ) ) {
						$instructions[] = "  → IMPLEMENT: {$opp['implementation']}";
					}
					if ( ! empty( $opp['expected_benefit'] ) ) {
						$instructions[] = "  → BENEFIT: {$opp['expected_benefit']}";
					}
				}
				$instructions[]        = '';
				$included_categories[] = count( $filtered ) . ' opportunities';
				$total_actionable     += count( $filtered );
			}
		} elseif ( ! $fix_opportunities && ! empty( $analysis_data['opportunities'] ) ) {
			$skipped_count += count( $analysis_data['opportunities'] );
		}

		// Add filtering summary
		if ( ! empty( $included_categories ) ) {
			$summary = '**SCOPE: ' . implode( ', ', $included_categories ) . '**';
			if ( $skipped_count > 0 ) {
				$summary .= "\n**NOTE: {$skipped_count} lower-priority items skipped based on your severity filter settings.**";
			}
			$instructions = array_merge( array( $summary, '' ), $instructions );
		}

		$this->last_actionable_count = $total_actionable;

		return implode( "\n", $instructions );
	}

	/**
	 * Retrieve the actionable issue count from the most recent instruction build.
	 *
	 * @return int
	 */
	public function get_last_actionable_count() {
		return $this->last_actionable_count;
	}

	/**
	 * Filter issues by severity based on user settings (MATRIX SYSTEM)
	 * Now also filters out dismissed items
	 *
	 * @param array $issues Array of issues to filter
	 * @param bool $severity_high Include high severity items
	 * @param bool $severity_medium Include medium severity items
	 * @param bool $severity_low Include low severity items
	 * @param array $dismissed_items Array of dismissed item identifiers
	 * @param string $category Category name
	 * @return array Filtered issues
	 */
	public function filter_issues_by_severity( $issues, $severity_high, $severity_medium, $severity_low, $dismissed_items = array(), $category = '' ) {
		return array_filter(
			$issues,
			function ( $issue, $index ) use ( $severity_high, $severity_medium, $severity_low, $dismissed_items, $category ) {
				// Check if this item is dismissed
				if ( ! empty( $category ) && ! empty( $dismissed_items ) ) {
					$identifier = $category . ':' . $index;
					if ( in_array( $identifier, $dismissed_items, true ) ) {
						return false; // Exclude dismissed items
					}
				}

				// Check severity
				$severity = $issue['severity'] ?? $issue['priority'] ?? 'medium';

				switch ( strtolower( $severity ) ) {
					case 'high':
					case 'urgent':
						return $severity_high;
					case 'medium':
						return $severity_medium;
					case 'low':
						return $severity_low;
					default:
						return $severity_medium; // Default to medium handling
				}
			},
			ARRAY_FILTER_USE_BOTH
		); // Use BOTH to get the index
	}

	/**
	 * Helper for checking if an issue should be included based on severity
	 * Used for inline filtering (e.g., opportunities array_filter)
	 *
	 * @param string $severity Severity level
	 * @param bool $severity_high Include high severity
	 * @param bool $severity_medium Include medium severity
	 * @param bool $severity_low Include low severity
	 * @return bool True if should be included
	 */
	public function should_include_by_severity( $severity, $severity_high, $severity_medium, $severity_low ) {
		$severity = strtolower( $severity );

		if ( in_array( $severity, array( 'high', 'urgent' ), true ) && $severity_high ) {
			return true;
		}
		if ( $severity === 'medium' && $severity_medium ) {
			return true;
		}
		if ( $severity === 'low' && $severity_low ) {
			return true;
		}

		return false;
	}

	/**
	 * Enhanced priority focus that respects user settings (MATRIX SYSTEM)
	 *
	 * @param array $analysis_data Analysis results
	 * @return string Priority focus text
	 */
	public function determine_priority_focus( $analysis_data ) {
		$priority_items = array();

		// Get user's category selections
		$fix_factual = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux      = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search  = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai      = get_option( 'freshrank_fix_ai_visibility', 0 );

		// Get user's severity selections
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		// FREE VERSION: Force Factual only + HIGH severity only
		if ( freshrank_is_free_version() ) {
			$fix_factual     = 1;
			$fix_ux          = 0;
			$fix_search      = 0;
			$fix_ai          = 0;
			$severity_high   = 1;
			$severity_medium = 1; // Allow medium severity factual actions in Lite
			$severity_low    = 0;
		}

		// High severity factual updates (if enabled)
		if ( $severity_high && $fix_factual && ! empty( $analysis_data['content_quality'] ) ) {
			foreach ( $analysis_data['content_quality'] as $issue ) {
				if ( strtolower( $issue['severity'] ) === 'high' ) {
					$priority_items[] = '1. CRITICAL FACTUAL: ' . $issue['issue'];
				}
			}
		}

		// High severity UX issues (if enabled)
		if ( $severity_high && $fix_ux && ! empty( $analysis_data['user_experience']['issues'] ) ) {
			foreach ( $analysis_data['user_experience']['issues'] as $issue ) {
				if ( strtolower( $issue['severity'] ) === 'high' ) {
					$priority_items[] = '2. CRITICAL UX: ' . $issue['issue'];
				}
			}
		}

		// High severity search optimization (if enabled)
		if ( $severity_high && $fix_search && ! empty( $analysis_data['search_optimization'] ) ) {
			foreach ( $analysis_data['search_optimization'] as $issue ) {
				if ( strtolower( $issue['severity'] ) === 'high' ) {
					$priority_items[] = '3. CRITICAL SEO: ' . $issue['issue'];
				}
			}
		}

		// High severity AI visibility (if enabled)
		if ( $severity_high && $fix_ai && ! empty( $analysis_data['ai_visibility']['issues'] ) ) {
			foreach ( $analysis_data['ai_visibility']['issues'] as $issue ) {
				if ( strtolower( $issue['severity'] ) === 'high' ) {
					$priority_items[] = '4. CRITICAL GEO: ' . $issue['issue'];
				}
			}
		}

		// Medium priority items (if enabled and no high priority items)
		if ( empty( $priority_items ) && $severity_medium ) {
			if ( $fix_factual && ! empty( $analysis_data['content_quality'] ) ) {
				foreach ( $analysis_data['content_quality'] as $issue ) {
					if ( strtolower( $issue['severity'] ) === 'medium' ) {
						$priority_items[] = '1. IMPORTANT FACTUAL: ' . $issue['issue'];
						break; // Just show one example
					}
				}
			}
			if ( empty( $priority_items ) && $fix_search && ! empty( $analysis_data['search_optimization'] ) ) {
				foreach ( $analysis_data['search_optimization'] as $issue ) {
					if ( strtolower( $issue['severity'] ) === 'medium' ) {
						$priority_items[] = '1. IMPORTANT SEO: ' . $issue['issue'];
						break; // Just show one example
					}
				}
			}
		}

		if ( empty( $priority_items ) ) {
			if ( $severity_high || $severity_medium || $severity_low ) {
				$priority_items[] = 'Address selected issues in order of severity as configured in settings.';
			} else {
				$priority_items[] = 'No severity levels are enabled for fixing. Check your AI settings.';
			}
		}

		return implode( "\n", $priority_items );
	}

	/**
	 * Get summary of current matrix filter settings (MATRIX SYSTEM)
	 *
	 * @return string Summary of enabled filters
	 */
	public function get_severity_filter_summary() {
		// FREE VERSION: Always Factual + HIGH only
		if ( freshrank_is_free_version() ) {
			return 'Factual [High]';
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
