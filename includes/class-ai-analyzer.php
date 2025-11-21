<?php
/**
 * AI Content Analyzer for FreshRank AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$openrouter_trait = FRESHRANK_PLUGIN_DIR . 'includes/pro/trait-openrouter-support.php';
if ( file_exists( $openrouter_trait ) ) {
	require_once $openrouter_trait;
}

if ( ! trait_exists( 'FreshRank_OpenRouter_Support' ) ) {
	trait FreshRank_OpenRouter_Support {
		// Empty stub used when OpenRouter support is stripped from Lite builds.
	}
}

class FreshRank_AI_Analyzer {
	use FreshRank_OpenRouter_Support;

	private $api_key;
	private $model;
	private $database;
	private $provider; // 'openai' or 'openrouter'

	public function __construct() {
		// Detect AI provider
		$this->provider = get_option( 'freshrank_ai_provider', 'openai' );

		// If OpenRouter support is unavailable (Lite build), force provider back to OpenAI
		$openrouter_supported = method_exists( $this, 'call_openrouter_api' ) && ! freshrank_is_free_version();
		if ( $this->provider === 'openrouter' && ! $openrouter_supported ) {
			$this->provider = 'openai';
		}

		// Load appropriate API key based on provider
		if ( $this->provider === 'openrouter' ) {
			$stored_api_key = get_option( 'freshrank_openrouter_api_key', '' );
			$this->api_key  = FreshRank_Encryption::decrypt( $stored_api_key );

			// Load OpenRouter model - custom ID takes precedence
			$custom_model = get_option( 'freshrank_openrouter_custom_model_analysis', '' );
			if ( ! empty( $custom_model ) ) {
				$this->model = $custom_model;
			} else {
				$this->model = get_option( 'freshrank_openrouter_model_analysis', 'google/gemini-2.5-pro' );
			}
		} else {
			// OpenAI (default)
			$stored_api_key = get_option( 'freshrank_openai_api_key', '' );
			$this->api_key  = FreshRank_Encryption::decrypt( $stored_api_key );
			$this->model    = get_option( 'freshrank_analysis_model', 'gpt-5' );
		}

		$this->database = FreshRank_Database::get_instance();

		// Don't throw exception in constructor - let individual methods handle it
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
	 * Sanitize content before sending to AI to prevent prompt injection
	 *
	 * @param string $text Content to sanitize
	 * @return string Sanitized content
	 */
	private function sanitize_for_ai( $text ) {
		if ( empty( $text ) ) {
			return '';
		}

		// Remove shortcodes
		$text = strip_shortcodes( $text );

		// Strip ALL HTML tags
		$text = wp_strip_all_tags( $text, true );

		// Remove potential prompt injection patterns
		$text = preg_replace( '/\{[^}]*\}/u', '', $text ); // Remove JSON-like structures
		$text = preg_replace( '/^\s*(system|assistant|user|role)\s*:/mi', '', $text ); // Remove role markers
		$text = preg_replace( '/```[\s\S]*?```/u', '', $text ); // Remove code blocks
		$text = str_replace( array( '```', '###', '---', '***' ), '', $text ); // Remove markdown delimiters

		// Remove control characters except newlines and tabs
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		// Limit length to prevent token exhaustion attacks
		$text = mb_substr( $text, 0, 50000 );

		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return $text;
	}

	/**
	 * Analyze a single article
	 */
	public function analyze_article( $post_id ) {
		// Check if this is a demo post (bypass permission check for demo)
		$is_demo_post = get_post_meta( $post_id, '_freshrank_demo_post', true );

		// Capability check - Requires manage_freshrank or edit_posts as fallback
		// Skip for demo posts
		if ( ! $is_demo_post && ! current_user_can( 'manage_freshrank' ) && ! current_user_can( 'edit_posts' ) ) {
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		if ( empty( $this->api_key ) ) {
			throw new Exception( 'OpenAI API key is not configured.' );
		}

		$start_time = microtime( true );

		// RACE CONDITION PROTECTION: Check for active transient
		$active_transient = get_transient( 'freshrank_analyzing_' . $post_id );
		if ( $active_transient !== false ) {
			throw new Exception( __( 'Analysis already in progress for this article. Please wait or refresh the page.', 'freshrank-ai' ) );
		}

		// Set transient immediately to claim this analysis
		set_transient( 'freshrank_analyzing_' . $post_id, time(), 300 ); // 5 minute expiry

		// Get existing analysis if any
		$existing_analysis = $this->database->get_analysis( $post_id );

		global $wpdb;
		$analysis_table = $wpdb->prefix . 'freshrank_analysis';

		if ( $existing_analysis ) {
			// Update existing record
			$updated = $wpdb->update(
				$analysis_table,
				array(
					'status'     => 'analyzing',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'post_id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( $updated === false ) {
				// Clear transient on failure
				delete_transient( 'freshrank_analyzing_' . $post_id );
				throw new Exception( 'Database update failed: ' . $wpdb->last_error );
			}
		} else {
			// Insert new record
			$inserted = $wpdb->insert(
				$analysis_table,
				array(
					'post_id'       => $post_id,
					'analysis_data' => '{}',
					'issues_count'  => 0,
					'status'        => 'analyzing',
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);

			if ( ! $inserted ) {
				// Check if another process created it
				$check = $this->database->get_analysis( $post_id );
				if ( $check && $check->status === 'analyzing' ) {
					throw new Exception( __( 'Analysis already started by another process.', 'freshrank-ai' ) );
				}
			}
		}

		// Also update articles table status
		$this->database->update_analysis_status( $post_id, 'analyzing' );

		// Force database write to complete before continuing
		// This ensures status persists even if request is interrupted
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post ) {
				throw new Exception( 'Post not found.' );
			}

			// Prepare content for analysis
			$content = $this->prepare_content_for_analysis( $post );

			// Create analysis prompt
			$prompt = $this->create_analysis_prompt( $post, $content );

			// Call AI API (routes to OpenAI or OpenRouter based on settings)
			$api_response    = $this->call_ai_api( $prompt );
			$analysis_result = $api_response['content'];
			$token_data      = $api_response['usage'];

			// Parse and structure the analysis
			$structured_analysis = $this->parse_analysis_result( $analysis_result );

			// Count issues
			$issues_count = $this->count_issues( $structured_analysis );

			// Calculate processing time
			$processing_time = round( microtime( true ) - $start_time, 2 );

			// Save results with token usage
			$this->database->save_analysis( $post_id, $structured_analysis, $issues_count, $processing_time, $token_data );

			// Clear the analyzing transient on success
			delete_transient( 'freshrank_analyzing_' . $post_id );

			return array(
				'success'         => true,
				'analysis'        => $structured_analysis,
				'issues_count'    => $issues_count,
				'processing_time' => $processing_time,
				'tokens_used'     => $token_data['total_tokens'],
				'cost_estimate'   => $this->estimate_cost( $token_data ),
			);

		} catch ( Exception $e ) {
			// Clear the analyzing transient on error
			delete_transient( 'freshrank_analyzing_' . $post_id );

			// Save error
			$this->database->save_analysis_error( $post_id, $e->getMessage() );

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Analyze multiple articles in bulk
	 */
	public function analyze_bulk( $post_ids ) {
		// Capability check - Requires manage_freshrank capability
		if ( ! current_user_can( 'manage_freshrank' ) ) {
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		$results          = array();
		$rate_limit_delay = get_option( 'freshrank_rate_limit_delay', 1000 ) * 1000; // Convert to microseconds

		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->analyze_article( $post_id );

			// Rate limiting delay
			if ( count( $post_ids ) > 1 ) {
				usleep( $rate_limit_delay );
			}
		}

		return $results;
	}

	/**
	 * Prepare post content for analysis
	 */
	private function prepare_content_for_analysis( $post ) {
		// Get post content and sanitize it to prevent prompt injection
		$content = $this->sanitize_for_ai( $post->post_content );

		// Get meta title from SEO plugins (Yoast, RankMath, SEOPress)
		$meta_title = $this->get_seo_title( $post->ID );
		if ( empty( $meta_title ) ) {
			$meta_title = $post->post_title;
		}
		$meta_title = $this->sanitize_for_ai( $meta_title );

		// Get excerpt and sanitize (needed for meta description fallback)
		$excerpt = $post->post_excerpt;
		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( $content, 55, '...' );
		}
		$excerpt = $this->sanitize_for_ai( $excerpt );

		// Get meta description from SEO plugins (Yoast, RankMath, SEOPress)
		$meta_description = $this->get_seo_description( $post->ID );
		if ( empty( $meta_description ) ) {
			$meta_description = $excerpt;
		}
		$meta_description = $this->sanitize_for_ai( $meta_description );

		// Get categories and tags
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		// Sanitize title
		$title = $this->sanitize_for_ai( $post->post_title );

		return array(
			'title'            => $title,
			'meta_title'       => $meta_title,
			'meta_description' => $meta_description,
			'excerpt'          => $excerpt,
			'content'          => $content,
			'categories'       => $categories,
			'tags'             => $tags,
			'published_date'   => $post->post_date,
			'modified_date'    => $post->post_modified,
			'word_count'       => str_word_count( $content ),
			'url'              => get_permalink( $post->ID ),
		);
	}

	/**
	 * Create analysis prompt for AI
	 */
	private function create_analysis_prompt( $post, $content ) {
		$current_year  = date( 'Y' );
		$post_year     = date( 'Y', strtotime( $post->post_date ) );
		$deep_research = get_option( 'freshrank_deep_research_mode', 0 );

		// Use deeper analysis prompt for deep research mode
		if ( $deep_research ) {
			return $this->create_deep_research_prompt( $post, $content );
		}

		// Check if factual updates are enabled (default: ON) - MATRIX SYSTEM
		$factual_updates = get_option( 'freshrank_fix_factual_updates', 1 );

		$prompt = '
Please analyze the following blog post';

		if ( $factual_updates ) {
			$prompt .= ' with primary focus on FACTUAL UPDATES and information currency';
		}

		$prompt .= " for SEO optimization opportunities and content freshness issues.

**IMPORTANT CONTEXT:**
- The content below has been sanitized and all HTML/links have been stripped for analysis
- Internal and external links ARE present in the actual published content, even if not visible here
- DO NOT flag missing links as an issue - assume appropriate links exist in the live content
- Focus on content quality, structure, keywords, readability, and factual accuracy instead

**POST INFORMATION:**
- Title: {$content['title']}
- Meta Title: {$content['meta_title']}
- Meta Description: {$content['meta_description']}
- Published: {$content['published_date']}
- Last Modified: {$content['modified_date']}
- Word Count: {$content['word_count']}
- Categories: " . implode( ', ', $content['categories'] ) . '
- Tags: ' . implode( ', ', $content['tags'] ) . "

**CONTENT:**
{$content['content']}

**ANALYSIS REQUIREMENTS:**
IMPORTANT:
- Do NOT flag missing internal_links or external_links - links exist but were stripped during content sanitization.
- Meta titles using %%title%% or similar Yoast SEO placeholders are CORRECT and should NOT be flagged as issues - these are dynamic template variables.

**ANALYSIS PRIORITIES:**

**PRIMARY FOCUS: USER EXPERIENCE & VALUE**
Analyze this content from the user's perspective first. Good search visibility comes naturally from excellent user experience.

1. USER EXPERIENCE (PRIMARY):
   - **Intent Matching**: Does content quickly answer the user's question?
   - **Information Accessibility**: Can users find what they need easily?
   - **Clarity**: Is the content clear and understandable?
   - **Directness**: Does it get to the point without excessive preamble?
   - **Accuracy**: Is all information correct and current?

2. ENGAGEMENT FACTORS:
   - **Above-the-fold**: Is key information visible immediately?
   - **Information Hierarchy**: Is content well-organized?
   - **Readability**: Is it easy to scan and digest?
   - **Value Delivery**: Does it genuinely help users?

3. TRUST & AUTHORITY (E-E-A-T):
   - **Experience**: Does content show first-hand knowledge?
   - **Expertise**: Is expertise demonstrated?
   - **Trustworthiness**: Is information accurate and sourced?

**REMEMBER:** Excellent search visibility comes naturally from excellent user-focused content. Technical SEO should support user experience, never compromise it.
";

		if ( $factual_updates ) {
			$prompt .= '
4. FACTUAL UPDATES:
   - Outdated statistics or data points
   - Old examples, case studies, or references
   - Information that has changed since publication
   - Factual errors or inaccuracies
   - Links to outdated resources

';
		}

		$prompt .= '
' . ( $factual_updates ? '5' : '4' ) . ". TECHNICAL SEO (AS NEEDED):
   - Title, meta description, headings optimization
   - Keyword usage and relevance
   - Content structure and readability
   - Image optimization

Please provide a comprehensive analysis in the following JSON format (5 categories with dedicated factual updates):

{
    \"user_experience\": {
        \"issues\": [
            {
                \"type\": \"intent_mismatch|poor_accessibility|clarity_problems|poor_structure|slow_value_delivery|grammar_errors|tone_inconsistency|poor_examples\",
                \"severity\": \"high|medium|low\",
                \"issue\": \"Description of the user experience or content quality issue\",
                \"user_impact\": \"How this affects users (e.g., 'Users may bounce before finding answer')\",
                \"recommendation\": \"How to improve user experience or content quality\"
            }
        ],
        \"metrics\": {
            \"estimated_dwell_time\": \"low|medium|high\",
            \"bounce_risk\": \"high|medium|low\",
            \"information_accessibility_score\": 0-100,
            \"above_fold_quality_score\": 0-100
        }
    },
    \"factual_updates\": [
        {
            \"type\": \"outdated_data|factual_errors|broken_resources|technology_changes|discontinued_tools\",
            \"severity\": \"high|medium|low\",
            \"issue\": \"Description of factual issue or outdated information\",
            \"recommendation\": \"How to update or correct this information\",
            \"current_value\": \"The outdated/incorrect information\",
            \"suggested_update\": \"The corrected or updated information\"
        }
    ],
    \"search_optimization\": [
        {
            \"type\": \"meta_data|keywords|images|content_length\",
            \"severity\": \"high|medium|low\",
            \"issue\": \"Description of technical SEO issue\",
            \"recommendation\": \"Specific recommendation to fix the issue\",
            \"impact\": \"Expected SEO impact\"
        }
    ],
    \"ai_visibility\": {
        \"issues\": [
            {
                \"type\": \"citation_worthiness|ai_comprehension|entity_recognition|answer_extraction|schema_markup\",
                \"severity\": \"high|medium|low\",
                \"issue\": \"Description of AI visibility issue\",
                \"recommendation\": \"How to optimize for AI platforms (ChatGPT, Claude, Perplexity)\",
                \"impact\": \"Expected impact on AI platform citations\"
            }
        ],
        \"visibility_score\": 0-100,
        \"summary\": \"Brief summary of AI optimization potential\"
    },
    \"opportunities\": [
        {
            \"type\": \"keyword_expansion|content_expansion|technical_enhancements|ai_optimization\",
            \"priority\": \"high|medium|low\",
            \"opportunity\": \"Description of the enhancement opportunity\",
            \"implementation\": \"How to implement this optimization\",
            \"expected_benefit\": \"Expected benefit\"
        }
    ],
    \"overall_score\": {
        \"seo_score\": 0-100,
        \"geo_score\": 0-100,
        \"freshness_score\": 0-100,
        \"overall_score\": 0-100,
        \"priority_level\": \"urgent|high|medium|low\"
    },
    \"summary\": \"Brief summary of main SEO and GEO issues and recommended actions\"
}

**CATEGORY-SPECIFIC GUIDELINES** (to eliminate redundancy):

**1. user_experience** - All issues affecting how users interact with content + content quality:
   - intent_mismatch: Content doesn't answer user's main question
   - poor_accessibility: Key information hard to find
   - clarity_problems: Confusing language, poor explanations
   - poor_structure: Bad hierarchy, excessive preamble, disorganized
   - slow_value_delivery: Answer buried below fold
   - grammar_errors: Typos, grammatical mistakes
   - tone_inconsistency: Voice shifts, inappropriate tone
   - poor_examples: Weak or confusing examples that don't help users understand
   - **NOTE:** This includes ALL UX and quality issues EXCEPT factual/outdated information

**2. factual_updates** - Information accuracy and currency (DEDICATED CATEGORY FOR FACTS ONLY):
   - outdated_data: Statistics, examples, case studies from {$post_year} or earlier
   - factual_errors: Incorrect information, wrong data
   - broken_resources: Dead links, discontinued tools/services, deprecated APIs
   - technology_changes: Outdated technical information, old versions
   - discontinued_tools: Products/services no longer available
   - **NOTE:** Report ALL factual and currency issues HERE ONLY, not in user_experience or opportunities

**3. search_optimization** - PURE technical SEO (NO UX/readability issues):
   - meta_data: Title tags, meta descriptions only
   - keywords: Keyword usage and relevance
   - images: Alt text optimization
   - content_length: Whether content depth is sufficient
   - **EXCLUDE:** Structure, headings, readability → those are user_experience issues

**4. ai_visibility** - AI platform optimization (ChatGPT, Claude, Perplexity):
   - citation_worthiness: Authoritative tone, citations
   - ai_comprehension: Structure that AI can parse
   - entity_recognition: Clear entity definitions
   - answer_extraction: Direct answers to questions
   - schema_markup: Structured data

**5. opportunities** - Future enhancements (NOT fixing current problems):
   - keyword_expansion: New keywords to target
   - content_expansion: Missing sections to add
   - technical_enhancements: Schema, rich snippets
   - ai_optimization: Additional GEO improvements
   - **Priority levels:**
     - HIGH: Easy to implement with significant traffic/ranking impact
     - MEDIUM: Moderate effort with good potential return
     - LOW: Nice-to-have improvements with modest impact

**IMPORTANT - AVOID DUPLICATES:**
- Outdated statistics, old data, factual errors → factual_updates ONLY (never in user_experience, opportunities, or search_optimization)
- Grammar, tone, clarity → user_experience ONLY (not search_optimization)
- Structure/hierarchy → user_experience ONLY (not search_optimization)
- Readability/quality → user_experience ONLY (not search_optimization)
- If unsure, prioritize user_experience over search_optimization

**SEVERITY GUIDELINES:**
- **Heading hierarchy issues** (h1 → h2 → h3 order) should be marked as **LOW severity** - these are best practices but not critical SEO issues
- **Keyword optimization** suggestions should be marked as **LOW severity** unless there are critical issues
- Reserve **HIGH severity** for:
  - Missing or very poor meta descriptions
  - Outdated statistics in opening paragraphs
  - Very thin content (under 300 words for topic requiring depth)
  - Broken critical functionality mentioned in content

**CRITICAL - Outdated Facts at Article Beginning:**
Outdated facts that appear in the first 2-3 paragraphs of the article are especially damaging because they:
- Immediately undermine reader trust
- Signal to search engines that content is stale
- Cause immediate bounce if readers spot obvious inaccuracies
- Harm the entire article's credibility

**SEVERITY RULES FOR OUTDATED CONTENT:**
- **HIGH severity**: Outdated facts in the opening paragraphs (first 2-3 paragraphs) that are clearly wrong or misleading
- **HIGH severity**: Statistics, dates, or facts you are confident are outdated (use web search to verify if available)
- **MEDIUM severity**: Outdated information in the middle/end of the article
- **LOW severity**: Minor updates or uncertain outdated information

**If web search is available:** Use it to verify the current accuracy of facts, statistics, and claims. Mark definitively outdated information as HIGH severity.

**CONTENT UPDATE RECOMMENDATIONS:**
- When recommending updates for outdated pricing, statistics, or facts, suggest updating the content directly
- **DO NOT recommend adding \"Last verified: [date]\" or \"(as of [date])\" timestamps after each price or fact**
- **DO NOT suggest adding verification dates or \"last checked\" annotations throughout the content**
- The post's modified date will automatically reflect when content was updated
- Focus recommendations on updating the actual content, not on adding date annotations

**GEO (Generative Engine Optimization):**
1. **Citation-Worthiness**: Evaluate if content has authoritative tone, proper citations, verifiable facts that AI platforms would trust and cite
2. **AI Comprehension**: Check if content uses clear headings, bullet points, numbered lists, definitions, and structured format that AI can easily parse
3. **Entity Recognition**: Identify if main entities (people, places, organizations, concepts) are clearly defined with context
4. **Answer Extraction**: Assess if content directly answers common questions in a format AI can extract (definitions, clear statements, FAQs)
5. **Platform Optimization**:
   - ChatGPT/Claude/Perplexity: Strong factual content with sources, current information
   - All platforms: Clear topic sentences, logical flow, scannable structure

Please be thorough and specific in your analysis. Focus on actionable recommendations that will improve both traditional search engine rankings AND visibility in AI-generated responses (ChatGPT, Claude, Perplexity).

**CRITICAL: Your response MUST be valid JSON only. Do not include any explanatory text before or after the JSON. Start your response with { and end with }. No markdown code blocks, no additional text.**
";

		// Append custom instructions if provided
		$custom_analysis_prompt = get_option( 'freshrank_custom_analysis_prompt', '' );
		if ( ! empty( $custom_analysis_prompt ) ) {
			$prompt .= "\n\n**ADDITIONAL CUSTOM INSTRUCTIONS:**\n" . $custom_analysis_prompt . "\n";
		}

		return $prompt;
	}

	/**
	 * Create deep research analysis prompt for comprehensive AI analysis
	 * Optimized for O-series reasoning models (o1, o1-mini, o3-mini)
	 */
	private function create_deep_research_prompt( $post, $content ) {
		$current_year = date( 'Y' );
		$post_year    = date( 'Y', strtotime( $post->post_date ) );

		$prompt = "
Perform a comprehensive, multi-step analysis of this blog post using deep reasoning and research methodologies.

**POST INFORMATION:**
- Title: {$content['title']}
- Meta Title: {$content['meta_title']}
- Meta Description: {$content['meta_description']}
- Published: {$content['published_date']} (Age: " . ( ( $current_year - $post_year ) ) . " years)
- Last Modified: {$content['modified_date']}
- Word Count: {$content['word_count']}
- Categories: " . implode( ', ', $content['categories'] ) . '
- Tags: ' . implode( ', ', $content['tags'] ) . "

**CONTENT:**
{$content['content']}

**DEEP RESEARCH ANALYSIS REQUIREMENTS:**

Perform a multi-step reasoning analysis:

STEP 1: COMPETITIVE LANDSCAPE ANALYSIS
- What search intent does this content target?
- What are the current SERP expectations for this topic in {$current_year}?
- What content depth and quality do top-ranking competitors likely offer?
- What unique angles or insights are missing from typical coverage?

STEP 2: CONTENT QUALITY ASSESSMENT
- Evaluate E-E-A-T signals (Experience, Expertise, Authoritativeness, Trustworthiness)
- Assess content depth vs topic complexity
- Identify logical gaps or incomplete explanations
- Check for outdated examples, statistics, or references since {$post_year}

STEP 3: TECHNICAL SEO AUDIT
- Title tag optimization (length, keywords, CTR potential)
- Meta description effectiveness
- Heading hierarchy and structure
- Content-to-HTML ratio
- Internal linking opportunities
- Image optimization potential
- Schema markup opportunities

STEP 4: GEO (GENERATIVE ENGINE OPTIMIZATION) ANALYSIS
Evaluate how well this content is optimized for AI platforms (ChatGPT, Claude, Perplexity):
- **Citation-Worthiness**: How likely is this content to be cited by AI platforms? (source quality, verifiability, unique insights)
- **AI Comprehension**: How easily can AI extract information? (heading structure, lists, tables, clear formatting)
- **Entity Recognition**: Are key entities properly identified with context?
- **Answer Extraction**: Can AI easily extract answers to questions? (FAQ-style, clear definitions, structured data)
- **Schema Markup**: Opportunities for schema.org markup to improve AI understanding

STEP 5: USER EXPERIENCE EVALUATION
- Content flow and readability
- Engagement potential (hooks, storytelling, examples)
- Call-to-action effectiveness
- Mobile-friendliness considerations
- Page speed impact from content recommendations

STEP 6: STRATEGIC RECOMMENDATIONS
- Prioritize updates by ROI potential
- Identify quick wins vs. major rewrites
- Suggest content expansion opportunities
- Recommend strategic keyword targeting
- Propose internal linking strategies

Provide your analysis in this JSON format:

{
    \"reasoning_summary\": \"Multi-paragraph explanation of your deep analysis process, key insights discovered, and strategic reasoning\",
    \"competitive_insights\": [
        {
            \"insight\": \"Specific competitive observation\",
            \"implication\": \"What this means for this content\",
            \"action\": \"Recommended strategic action\"
        }
    ],
    \"seo_issues\": [
        {
            \"type\": \"title|meta_description|headings|keywords|internal_links|external_links|images|content_length|readability|schema\",
            \"severity\": \"high|medium|low\",
            \"issue\": \"Detailed description with specific examples\",
            \"recommendation\": \"Specific, actionable fix with examples\",
            \"impact\": \"Expected traffic/ranking impact with reasoning\",
            \"effort\": \"Quick win|Medium effort|Major rewrite\"
        }
    ],
    \"content_freshness\": [
        {
            \"type\": \"outdated_statistics|outdated_information|broken_references|outdated_examples|technology_changes|methodology_changes\",
            \"severity\": \"high|medium|low\",
            \"issue\": \"Specific outdated element with context\",
            \"suggestion\": \"Detailed update recommendation\",
            \"priority\": \"urgent|high|medium|low\",
            \"research_needed\": \"What research/data is needed for the update\"
        }
    ],
    \"optimization_opportunities\": [
        {
            \"type\": \"keyword_optimization|content_expansion|structure_improvement|user_experience|technical_seo|engagement_optimization\",
            \"opportunity\": \"Detailed opportunity description\",
            \"implementation\": \"Step-by-step implementation guide\",
            \"expected_benefit\": \"Quantified expected benefit with reasoning\",
            \"priority\": \"high|medium|low\",
            \"dependencies\": \"What else needs to happen first\"
        }
    ],
    \"content_gaps\": [
        {
            \"gap\": \"Specific missing content or angle\",
            \"reason\": \"Why this matters for SEO and users\",
            \"suggestion\": \"Detailed content to add\",
            \"competitive_advantage\": \"How this differentiates from competitors\",
            \"word_count_estimate\": \"Estimated words needed\"
        }
    ],
    \"geo_analysis\": {
        \"citation_worthiness\": {
            \"score\": 0-100,
            \"assessment\": \"Detailed evaluation of citation potential for AI platforms\",
            \"strengths\": [\"What makes this content citeable\"],
            \"weaknesses\": [\"What reduces citation potential\"],
            \"recommendations\": [\"Specific improvements for AI citation\"]
        },
        \"ai_comprehension\": {
            \"score\": 0-100,
            \"structure_quality\": \"Assessment of how AI-friendly the structure is\",
            \"recommendations\": [\"How to improve AI comprehension\"]
        },
        \"entity_recognition\": {
            \"score\": 0-100,
            \"identified_entities\": [\"Key entities found in content\"],
            \"missing_context\": [\"Entities needing better definition\"],
            \"schema_recommendations\": [\"Specific schema.org markup to add\"]
        },
        \"answer_extraction\": {
            \"score\": 0-100,
            \"direct_answers\": [\"Questions this content answers well\"],
            \"missing_qa\": [\"Questions that should be added\"],
            \"format_improvements\": [\"How to structure for better extraction\"]
        },
        \"platform_specific\": {
            \"chatgpt_optimization\": {
                \"score\": 0-100,
                \"analysis\": \"How well optimized for ChatGPT\",
                \"improvements\": [\"Specific ChatGPT optimizations\"]
            },
            \"perplexity_optimization\": {
                \"score\": 0-100,
                \"analysis\": \"How well optimized for Perplexity\",
                \"improvements\": [\"Specific Perplexity optimizations\"]
            }
        },
        \"overall_geo_score\": 0-100,
        \"geo_priority\": \"urgent|high|medium|low\",
        \"geo_summary\": \"Comprehensive GEO assessment with strategic recommendations\"
    },
    \"strategic_roadmap\": {
        \"quick_wins\": [\"List of changes that take <1 hour and have immediate impact\"],
        \"medium_updates\": [\"Changes that take 2-4 hours but significantly improve content\"],
        \"major_rewrites\": [\"Sections that need substantial rework\"],
        \"ongoing_maintenance\": [\"Items to update quarterly/annually\"]
    },
    \"overall_score\": {
        \"seo_score\": 0-100,
        \"geo_score\": 0-100,
        \"freshness_score\": 0-100,
        \"content_depth_score\": 0-100,
        \"eeat_score\": 0-100,
        \"overall_score\": 0-100,
        \"priority_level\": \"urgent|high|medium|low\",
        \"estimated_traffic_impact\": \"Percentage estimate with reasoning\"
        },
    \"summary\": \"Comprehensive executive summary (3-5 paragraphs) covering main SEO findings, GEO optimization potential, strategic recommendations, and expected outcomes for both traditional search and AI platform visibility\"
}

**CONTENT UPDATE RECOMMENDATIONS:**
- When recommending updates for outdated pricing, statistics, or facts, suggest updating the content directly
- **DO NOT recommend adding \"Last verified: [date]\" or \"(as of [date])\" timestamps after each price or fact**
- **DO NOT suggest adding verification dates or \"last checked\" annotations throughout the content**
- The post's modified date will automatically reflect when content was updated
- Focus recommendations on updating the actual content, not on adding date annotations

**DEEP RESEARCH FOCUS:**
- Use multi-step reasoning to evaluate each aspect
- Consider search intent evolution since {$post_year}
- Think strategically about competitive positioning
- Prioritize recommendations by ROI and effort
- Provide specific, actionable guidance (not generic advice)
- Consider the full user journey and conversion potential
- Think about how this content fits in the site's overall authority

Be thorough, strategic, and specific. This is a deep research analysis - take time to reason through each recommendation.

**CRITICAL: Your response MUST be valid JSON only. Do not include any explanatory text before or after the JSON. Start your response with { and end with }. No markdown code blocks, no additional text.**
";

		return $prompt;
	}

	/**
	 * Call OpenAI API
	 */
	private function call_openai_api( $prompt ) {
		$is_gpt5 = ( stripos( $this->model, 'gpt-5' ) !== false );

		// GPT-5 uses Responses API, others use Chat Completions API
		$url = $is_gpt5
			? 'https://api.openai.com/v1/responses'
			: 'https://api.openai.com/v1/chat/completions';

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);

		if ( $is_gpt5 ) {
			// Set reasoning effort based on web search availability
			$web_search_enabled = get_option( 'freshrank_enable_web_search', 0 );
			$reasoning_effort   = $web_search_enabled ? 'medium' : 'low';

			// Build system instructions with custom instructions if enabled
			$system_instructions = 'You are an expert content analyst specializing in user experience and content quality. You evaluate content based on how well it serves users\' needs while naturally achieving strong search visibility. Focus on user value, clarity, accuracy, and intent matching. Always respond with valid JSON format as specified in the prompt.';

			// Append custom analysis instructions if enabled
			$custom_instructions = freshrank_get_custom_analysis_prompt();
			if ( ! empty( $custom_instructions ) ) {
				$system_instructions .= "\n\nAdditional Instructions: " . $custom_instructions;
			}

			// Responses API format for GPT-5
			// Best practice: Use 'instructions' for system message, 'input' for user message
			$body = array(
				'model'             => $this->model,
				'instructions'      => $system_instructions,
				'input'             => $prompt,
				'reasoning'         => array( 'effort' => $reasoning_effort ), // Use medium effort when web search is enabled for better fact-checking
				'text'              => array( 'verbosity' => 'medium' ),
				'max_output_tokens' => 132000, // GPT-5 maximum output tokens
			);

			// Add web search if enabled
			if ( $web_search_enabled ) {
				$body['tools'] = array(
					array( 'type' => 'web_search_preview' ),
				);
			}
		} else {
			// Build system instructions with custom instructions if enabled
			$system_instructions = 'You are an expert content analyst specializing in user experience and content quality. You evaluate content based on how well it serves users\' needs while naturally achieving strong search visibility. Focus on user value, clarity, accuracy, and intent matching. Always respond with valid JSON format as specified in the prompt.';

			// Append custom analysis instructions if enabled
			$custom_instructions = freshrank_get_custom_analysis_prompt();
			if ( ! empty( $custom_instructions ) ) {
				$system_instructions .= "\n\nAdditional Instructions: " . $custom_instructions;
			}

			// Chat Completions API format for other models
			// Best practice: Use system message for role/context, user message for task
			$body = array(
				'model'    => $this->model,
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => $system_instructions,
					),
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			);

			// O1/O3 models support reasoning_effort parameter
			$is_reasoning_model = ( stripos( $this->model, 'o1' ) !== false || stripos( $this->model, 'o3' ) !== false );

			if ( $is_reasoning_model ) {
				// Set reasoning effort for O-series models
				$body['reasoning_effort'] = 'low';
			} else {
				// Non-reasoning models support temperature
				$body['temperature'] = 0.3;
			}

			// Use max_completion_tokens for modern models, max_tokens for legacy
			// Set maximum output tokens based on model
			if ( stripos( $this->model, 'gpt-3.5' ) !== false ) {
				$body['max_tokens'] = 16384; // GPT-3.5 max
			} elseif ( stripos( $this->model, 'gpt-4o' ) !== false ) {
				$body['max_completion_tokens'] = 16384; // GPT-4o max output
			} elseif ( stripos( $this->model, 'gpt-4' ) !== false ) {
				$body['max_completion_tokens'] = 16384; // GPT-4 max output
			} elseif ( stripos( $this->model, 'o1' ) !== false || stripos( $this->model, 'o3' ) !== false ) {
				$body['max_completion_tokens'] = 100000; // O1/O3 max output
			} else {
				$body['max_completion_tokens'] = 16384; // Default for unknown models
			}
		}

		// Determine timeout based on reasoning effort and web search
		// Medium reasoning + web search can take 5-8 minutes
		$web_search_enabled = get_option( 'freshrank_enable_web_search', 0 );
		$timeout            = 240; // Default 4 minutes

		if ( $is_gpt5 && $web_search_enabled ) {
			// GPT-5 with web search needs more time (research + reasoning)
			$timeout = 900; // 15 minutes
			freshrank_debug_log( 'Using extended timeout (15 min) for GPT-5 with web search' );
		} elseif ( $is_gpt5 ) {
			// GPT-5 without web search
			$timeout = 600; // 10 minutes
			freshrank_debug_log( 'Using extended timeout (10 min) for GPT-5' );
		}

		// Check if we can increase timeout limits (not available on all hosts)
		$disabled_functions = ini_get( 'disable_functions' );
		$can_extend_timeout = empty( $disabled_functions ) || ! in_array( 'set_time_limit', explode( ',', $disabled_functions ), true );

		if ( $can_extend_timeout ) {
			// Try to increase PHP timeout limits
			@set_time_limit( $timeout );
			@ini_set( 'max_execution_time', (string) $timeout );
			@ini_set( 'default_socket_timeout', (string) $timeout );

			freshrank_debug_log( 'Extended PHP timeout limits to ' . $timeout . 's' );
		} else {
			// Function disabled on this host - rely on wp_remote_post timeout only
			freshrank_debug_log( 'Cannot extend PHP timeout (disabled by host). Using wp_remote_post timeout of ' . $timeout . 's' );
		}

		freshrank_debug_log( 'Making API request with ' . $timeout . 's timeout (model: ' . $this->model . ')' );

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'httpversion' => '1.1',
				'blocking'    => true,
				'sslverify'   => true,
				'redirection' => 0, // Don't follow redirects
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$error_message = $response->get_error_message();
			$error_data    = $response->get_error_data();

			freshrank_debug_log( 'OpenAI analysis transport error: ' . $error_code . ' - ' . $error_message . ' | data=' . wp_json_encode( $error_data ) );

			throw new Exception( 'OpenAI API request failed (' . $error_code . '): ' . $error_message );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			$error_body    = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $error_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown API error';
			freshrank_debug_log( 'OpenAI analysis non-200 (' . $response_code . '): ' . $error_message . ' | body=' . substr( $error_body, 0, 1000 ) );
			throw new Exception( "OpenAI API error (HTTP $response_code): $error_message" );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Parse response based on API type
		if ( $is_gpt5 ) {
			// Responses API format
			if ( empty( $data['output'] ) ) {
				throw new Exception( 'Empty response from OpenAI API' );
			}

			// Find the message item in output
			$content = '';
			foreach ( $data['output'] as $item ) {
				if ( $item['type'] === 'message' && isset( $item['content'] ) ) {
					foreach ( $item['content'] as $content_item ) {
						if ( $content_item['type'] === 'output_text' ) {
							$content = $content_item['text'];
							break 2;
						}
					}
				}
			}

			if ( empty( $content ) ) {
				throw new Exception( 'No text content in response' );
			}

			// Extract usage data from Responses API
			$usage = array(
				'prompt_tokens'     => isset( $data['usage']['input_tokens'] ) ? $data['usage']['input_tokens'] : 0,
				'completion_tokens' => isset( $data['usage']['output_tokens'] ) ? $data['usage']['output_tokens'] : 0,
				'total_tokens'      => isset( $data['usage']['total_tokens'] ) ? $data['usage']['total_tokens'] : 0,
				'model'             => $this->model,
			);

		} else {
			// Chat Completions API format
			if ( empty( $data['choices'][0]['message']['content'] ) ) {
				throw new Exception( 'Empty response from OpenAI API' );
			}

			$content = $data['choices'][0]['message']['content'];

			// Extract usage data
			$usage = array(
				'prompt_tokens'     => isset( $data['usage']['prompt_tokens'] ) ? $data['usage']['prompt_tokens'] : 0,
				'completion_tokens' => isset( $data['usage']['completion_tokens'] ) ? $data['usage']['completion_tokens'] : 0,
				'total_tokens'      => isset( $data['usage']['total_tokens'] ) ? $data['usage']['total_tokens'] : 0,
				'model'             => $this->model,
			);
		}

		return array(
			'content' => $content,
			'usage'   => $usage,
		);
	}

	/**
	 * Router method - calls appropriate API based on provider
	 */
	private function call_ai_api( $prompt ) {
		if ( $this->provider === 'openrouter' ) {
			if ( method_exists( $this, 'call_openrouter_api' ) ) {
				return $this->call_openrouter_api( $prompt );
			}

			throw new Exception( __( 'OpenRouter support is not available in this build.', 'freshrank-ai' ) );
		}

		return $this->call_openai_api( $prompt );
	}

	/**
	 * Estimate cost based on token usage and model
	 */
	private function estimate_cost( $token_data ) {
		// Cost per 1M tokens (input/output) - Updated with GPT-5 series pricing
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

		$model             = isset( $token_data['model'] ) ? $token_data['model'] : $this->model;
		$prompt_tokens     = isset( $token_data['prompt_tokens'] ) ? $token_data['prompt_tokens'] : 0;
		$completion_tokens = isset( $token_data['completion_tokens'] ) ? $token_data['completion_tokens'] : 0;

		if ( ! isset( $pricing[ $model ] ) ) {
			return 0; // Unknown model
		}

		$input_cost  = ( $prompt_tokens / 1000000 ) * $pricing[ $model ]['input'];
		$output_cost = ( $completion_tokens / 1000000 ) * $pricing[ $model ]['output'];

		return round( $input_cost + $output_cost, 4 );
	}

	/**
	 * Parse and validate analysis result
	 */
	private function parse_analysis_result( $result ) {
		// Log raw result for debugging
		// Only emit truncated samples in debug mode to avoid leaking content into logs
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			$preview = mb_substr( $result, 0, 500 );
			if ( strlen( $result ) > 500 ) {
				$preview .= '…';
			}
			freshrank_debug_log( 'Raw API response preview (debug mode): ' . $preview );
		}

		// Try multiple JSON extraction strategies
		$json_string = null;

		// Strategy 1: Direct JSON decode (if response is pure JSON)
		$parsed = json_decode( $result, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
			return $this->validate_analysis_structure( $parsed );
		}

		// Strategy 2: Extract JSON between first { and last }
		$json_start = strpos( $result, '{' );
		$json_end   = strrpos( $result, '}' );

		if ( $json_start !== false && $json_end !== false ) {
			$json_string = substr( $result, $json_start, $json_end - $json_start + 1 );
			$parsed      = json_decode( $json_string, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				return $this->validate_analysis_structure( $parsed );
			}
		}

		// Strategy 3: Look for JSON code blocks (```json ... ```)
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $result, $matches ) ) {
			$json_string = $matches[1];
			$parsed      = json_decode( $json_string, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				return $this->validate_analysis_structure( $parsed );
			}
		}

		// Strategy 4: Look for JSON code blocks (``` ... ``` without json marker)
		if ( preg_match( '/```\s*(.*?)\s*```/s', $result, $matches ) ) {
			$json_string = $matches[1];
			$parsed      = json_decode( $json_string, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				return $this->validate_analysis_structure( $parsed );
			}
		}

		// All strategies failed - log concise error
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			$json_error_msg = json_last_error_msg();
			freshrank_debug_log( 'JSON parsing failed. Error: ' . $json_error_msg . ' (length: ' . strlen( $result ) . ')' );
		}

		// Strategy 5: Try to fix common JSON issues
		$fixed_result = $this->attempt_json_repair( $result );
		if ( $fixed_result !== null ) {
			if ( get_option( 'freshrank_debug_mode', false ) ) {
				freshrank_debug_log( 'JSON repair successful' );
			}
			return $this->validate_analysis_structure( $fixed_result );
		}

		// If JSON parsing fails, throw exception to trigger retry
		throw new Exception( 'Failed to parse AI analysis result. JSON Error: ' . $json_error_msg );
	}

	/**
	 * Attempt to repair malformed JSON
	 */
	private function attempt_json_repair( $json_string ) {
		// Remove any text before first {
		$start = strpos( $json_string, '{' );
		if ( $start === false ) {
			return null;
		}
		$json_string = substr( $json_string, $start );

		// Remove any text after last }
		$end = strrpos( $json_string, '}' );
		if ( $end === false ) {
			return null;
		}
		$json_string = substr( $json_string, 0, $end + 1 );

		// Try to decode
		$decoded = json_decode( $json_string, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $decoded;
		}

		// Try to fix unclosed arrays/objects by adding closing brackets
		$open_braces   = substr_count( $json_string, '{' ) - substr_count( $json_string, '}' );
		$open_brackets = substr_count( $json_string, '[' ) - substr_count( $json_string, ']' );

		if ( $open_braces > 0 || $open_brackets > 0 ) {
			$json_string .= str_repeat( ']', $open_brackets ) . str_repeat( '}', $open_braces );
			$decoded      = json_decode( $json_string, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Validate and structure analysis result
	 */
	private function validate_analysis_structure( $data ) {
		// New consolidated 5-category structure with dedicated factual_updates
		$defaults = array(
			'user_experience'     => array(
				'issues'  => array(),
				'metrics' => array(
					'estimated_dwell_time'            => 'medium',
					'bounce_risk'                     => 'medium',
					'information_accessibility_score' => 50,
					'above_fold_quality_score'        => 50,
				),
			),
			'factual_updates'     => array(),
			'search_optimization' => array(),
			'ai_visibility'       => array(
				'issues'           => array(),
				'visibility_score' => 50,
				'summary'          => '',
			),
			'opportunities'       => array(),
			'content_quality'     => array(), // Deprecated - kept for backward compatibility
			'overall_score'       => array(
				'seo_score'       => 50,
				'geo_score'       => 50,
				'freshness_score' => 50,
				'overall_score'   => 50,
				'priority_level'  => 'medium',
			),
			'summary'             => 'Analysis completed',
		);

		// Backward compatibility: Map old structure to new
		$mapped_data = $data;

		// Map old ux_issues + engagement_metrics → user_experience
		if ( isset( $data['ux_issues'] ) || isset( $data['engagement_metrics'] ) ) {
			$mapped_data['user_experience'] = array(
				'issues'  => isset( $data['ux_issues'] ) ? $data['ux_issues'] : array(),
				'metrics' => isset( $data['engagement_metrics'] ) ? $data['engagement_metrics'] : array(),
			);
			unset( $mapped_data['ux_issues'], $mapped_data['engagement_metrics'] );
		}

		// Map old content_freshness → factual_updates (new dedicated category)
		// Note: old "factual_updates" stays as is - it's now the official category name
		if ( isset( $data['content_freshness'] ) && ! isset( $data['factual_updates'] ) ) {
			$mapped_data['factual_updates'] = $data['content_freshness'];
			unset( $mapped_data['content_freshness'] );
		} elseif ( isset( $data['content_freshness'] ) && isset( $data['factual_updates'] ) ) {
			// Merge both if present
			$mapped_data['factual_updates'] = array_merge( $data['factual_updates'], $data['content_freshness'] );
			unset( $mapped_data['content_freshness'] );
		}

		// Map old seo_issues → search_optimization
		if ( isset( $data['seo_issues'] ) ) {
			$mapped_data['search_optimization'] = $data['seo_issues'];
			unset( $mapped_data['seo_issues'] );
		}

		// Map old geo_analysis → ai_visibility
		if ( isset( $data['geo_analysis'] ) ) {
			$mapped_data['ai_visibility'] = $data['geo_analysis'];
			unset( $mapped_data['geo_analysis'] );
		}

		// Map old optimization_opportunities + content_gaps → opportunities
		if ( isset( $data['optimization_opportunities'] ) || isset( $data['content_gaps'] ) ) {
			$opportunities = array();
			if ( isset( $data['optimization_opportunities'] ) ) {
				$opportunities = $data['optimization_opportunities'];
			}
			if ( isset( $data['content_gaps'] ) ) {
				// Convert content_gaps format to opportunities format
				foreach ( $data['content_gaps'] as $gap ) {
					$opportunities[] = array(
						'type'             => 'content_expansion',
						'opportunity'      => $gap['gap'],
						'implementation'   => $gap['suggestion'],
						'expected_benefit' => isset( $gap['reason'] ) ? $gap['reason'] : '',
					);
				}
			}
			$mapped_data['opportunities'] = $opportunities;
			unset( $mapped_data['optimization_opportunities'], $mapped_data['content_gaps'] );
		}

		return wp_parse_args( $mapped_data, $defaults );
	}

	/**
	 * Count total issues from analysis
	 */
	private function count_issues( $analysis ) {
		$count = 0;

		// New 5-category structure with dedicated factual_updates
		if ( isset( $analysis['user_experience']['issues'] ) ) {
			$count += count( $analysis['user_experience']['issues'] );
		}

		if ( isset( $analysis['factual_updates'] ) ) {
			$count += count( $analysis['factual_updates'] );
		}

		if ( isset( $analysis['search_optimization'] ) ) {
			$count += count( $analysis['search_optimization'] );
		}

		if ( isset( $analysis['ai_visibility']['issues'] ) ) {
			$count += count( $analysis['ai_visibility']['issues'] );
		}

		// Don't count opportunities as "issues" - they're enhancements

		// Backward compatibility: Count old structure if present
		if ( isset( $analysis['content_quality'] ) ) {
			$count += count( $analysis['content_quality'] ); // Deprecated category
		}

		if ( isset( $analysis['ux_issues'] ) ) {
			$count += count( $analysis['ux_issues'] );
		}

		if ( isset( $analysis['seo_issues'] ) ) {
			$count += count( $analysis['seo_issues'] );
		}

		if ( isset( $analysis['content_freshness'] ) ) {
			$count += count( $analysis['content_freshness'] );
		}

		return $count;
	}

	/**
	 * Get analysis for a specific post
	 */
	public function get_analysis( $post_id ) {
		return $this->database->get_analysis( $post_id );
	}

	/**
	 * Get analysis summary for multiple posts
	 */
	public function get_bulk_analysis_summary( $post_ids ) {
		$summary = array(
			'total_posts'         => count( $post_ids ),
			'analyzed_posts'      => 0,
			'pending_posts'       => 0,
			'error_posts'         => 0,
			'total_issues'        => 0,
			'high_priority_posts' => 0,
			'posts'               => array(),
		);

		foreach ( $post_ids as $post_id ) {
			$analysis = $this->database->get_analysis( $post_id );
			$post     = get_post( $post_id );

			$post_summary = array(
				'id'             => $post_id,
				'title'          => $post ? $post->post_title : 'Unknown',
				'status'         => 'pending',
				'issues_count'   => 0,
				'priority_level' => 'medium',
				'analysis_date'  => null,
			);

			if ( $analysis ) {
				if ( $analysis->status === 'completed' ) {
					++$summary['analyzed_posts'];
					$post_summary['status']        = 'completed';
					$post_summary['issues_count']  = $analysis->issues_count;
					$post_summary['analysis_date'] = $analysis->created_at;
					$summary['total_issues']      += $analysis->issues_count;

					if ( isset( $analysis->analysis_data['overall_score']['priority_level'] ) ) {
						$post_summary['priority_level'] = $analysis->analysis_data['overall_score']['priority_level'];

						if ( in_array( $post_summary['priority_level'], array( 'urgent', 'high' ), true ) ) {
							++$summary['high_priority_posts'];
						}
					}
				} elseif ( $analysis->status === 'error' ) {
					++$summary['error_posts'];
					$post_summary['status']        = 'error';
					$post_summary['error_message'] = $analysis->error_message;
				} else {
					++$summary['pending_posts'];
				}
			} else {
				++$summary['pending_posts'];
			}

			$summary['posts'][] = $post_summary;
		}

		return $summary;
	}

	/**
	 * Generate content improvement suggestions
	 */
	public function generate_improvement_suggestions( $post_id ) {
		$analysis = $this->get_analysis( $post_id );

		if ( ! $analysis || $analysis->status !== 'completed' ) {
			throw new Exception( 'No completed analysis found for this post.' );
		}

		$analysis_data = $analysis->analysis_data;
		$suggestions   = array();

		// Process SEO issues
		if ( ! empty( $analysis_data['seo_issues'] ) ) {
			foreach ( $analysis_data['seo_issues'] as $issue ) {
				$suggestions[] = array(
					'category'       => 'SEO',
					'type'           => $issue['type'],
					'severity'       => $issue['severity'],
					'title'          => $this->format_issue_title( $issue['type'] ),
					'description'    => $issue['issue'],
					'recommendation' => $issue['recommendation'],
					'impact'         => $issue['impact'],
				);
			}
		}

		// Process content freshness issues
		if ( ! empty( $analysis_data['content_freshness'] ) ) {
			foreach ( $analysis_data['content_freshness'] as $freshness ) {
				$suggestions[] = array(
					'category'       => 'Content Freshness',
					'type'           => $freshness['type'],
					'severity'       => $freshness['severity'],
					'title'          => $this->format_freshness_title( $freshness['type'] ),
					'description'    => $freshness['issue'],
					'recommendation' => $freshness['suggestion'],
					'priority'       => $freshness['priority'],
				);
			}
		}

		// Process optimization opportunities
		if ( ! empty( $analysis_data['optimization_opportunities'] ) ) {
			foreach ( $analysis_data['optimization_opportunities'] as $opportunity ) {
				$suggestions[] = array(
					'category'       => 'Optimization',
					'type'           => $opportunity['type'],
					'severity'       => 'medium',
					'title'          => $this->format_opportunity_title( $opportunity['type'] ),
					'description'    => $opportunity['opportunity'],
					'recommendation' => $opportunity['implementation'],
					'benefit'        => $opportunity['expected_benefit'],
				);
			}
		}

		// Sort by severity and priority
		usort(
			$suggestions,
			function ( $a, $b ) {
				$severity_order = array(
					'high'   => 3,
					'urgent' => 3,
					'medium' => 2,
					'low'    => 1,
				);
				$a_weight       = isset( $severity_order[ $a['severity'] ] ) ? $severity_order[ $a['severity'] ] : 1;
				$b_weight       = isset( $severity_order[ $b['severity'] ] ) ? $severity_order[ $b['severity'] ] : 1;

				return $b_weight <=> $a_weight;
			}
		);

		return $suggestions;
	}

	/**
	 * Format issue type for display
	 */
	private function format_issue_title( $type ) {
		$titles = array(
			'title'            => 'Page Title Optimization',
			'meta_description' => 'Meta Description Issues',
			'headings'         => 'Heading Structure Problems',
			'keywords'         => 'Keyword Optimization',
			'internal_links'   => 'Internal Linking Issues',
			'external_links'   => 'External Link Problems',
			'images'           => 'Image Optimization Issues',
			'content_length'   => 'Content Length Concerns',
			'readability'      => 'Readability Issues',
			'analysis_error'   => 'Analysis Error',
		);

		return isset( $titles[ $type ] ) ? $titles[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Format freshness type for display
	 */
	private function format_freshness_title( $type ) {
		$titles = array(
			'outdated_statistics'  => 'Outdated Statistics',
			'outdated_information' => 'Outdated Information',
			'broken_references'    => 'Broken References',
			'outdated_examples'    => 'Outdated Examples',
			'technology_changes'   => 'Technology Changes',
		);

		return isset( $titles[ $type ] ) ? $titles[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Format opportunity type for display
	 */
	private function format_opportunity_title( $type ) {
		$titles = array(
			'keyword_optimization'  => 'Keyword Optimization Opportunity',
			'content_expansion'     => 'Content Expansion Opportunity',
			'structure_improvement' => 'Structure Improvement',
			'user_experience'       => 'User Experience Enhancement',
			'technical_seo'         => 'Technical SEO Improvement',
		);

		return isset( $titles[ $type ] ) ? $titles[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Get available AI models with descriptions (OpenAI)
	 */
	public function get_available_models() {
		return array(
			// GPT-5 Pro (most advanced reasoning model)
			'gpt-5-pro'   => array(
				'name'        => 'GPT-5 Pro',
				'description' => 'Most advanced reasoning model, smartest and most precise responses',
				'context'     => '400K tokens',
				'speed'       => 'Slowest (uses more compute to think harder)',
				'cost'        => 'Premium ($15/$120 per 1M tokens)',
				'provider'    => 'openai',
			),
			// GPT-5 series (flagship - August 2025 release)
			'gpt-5'       => array(
				'name'        => 'GPT-5',
				'description' => 'Flagship model for coding, reasoning, and agentic tasks (recommended)',
				'context'     => '400K tokens',
				'speed'       => 'Fast',
				'cost'        => 'Premium ($1.25/$10 per 1M tokens)',
				'provider'    => 'openai',
			),
			'gpt-5-mini'  => array(
				'name'        => 'GPT-5 Mini',
				'description' => 'Cost-optimized GPT-5 model',
				'context'     => '400K tokens',
				'speed'       => 'Very Fast',
				'cost'        => 'Moderate',
				'provider'    => 'openai',
			),
			'gpt-5-nano'  => array(
				'name'        => 'GPT-5 Nano',
				'description' => 'High-throughput, ultra-fast for bulk analysis',
				'context'     => '400K tokens',
				'speed'       => 'Ultra Fast',
				'cost'        => 'Low',
				'provider'    => 'openai',
			),
			// O3 Pro (highest reasoning model - June 2025)
			'o3-pro'      => array(
				'name'        => 'O3 Pro',
				'description' => 'Highest reasoning model for complex problems',
				'context'     => '200K tokens',
				'speed'       => 'Slow',
				'cost'        => 'Very High ($20/$160 per 1M tokens)',
				'provider'    => 'openai',
			),
			// O3-mini (latest OpenAI reasoning model - Jan 2025)
			'o3-mini'     => array(
				'name'        => 'O3 Mini',
				'description' => 'Latest reasoning model, excellent for strategic analysis',
				'context'     => '200K tokens',
				'speed'       => 'Medium',
				'cost'        => 'Medium',
				'provider'    => 'openai',
			),
			// Legacy models (kept for backward compatibility)
			'gpt-4o-mini' => array(
				'name'        => 'GPT-4o Mini (Legacy)',
				'description' => 'Legacy cost-effective model',
				'context'     => '128K tokens',
				'speed'       => 'Very Fast',
				'cost'        => 'Low',
				'provider'    => 'openai',
			),
			'gpt-4o'      => array(
				'name'        => 'GPT-4o (Legacy)',
				'description' => 'Legacy multimodal model',
				'context'     => '128K tokens',
				'speed'       => 'Fast',
				'cost'        => 'High',
				'provider'    => 'openai',
			),
		);
	}

	/**
	 * Test OpenAI API connection
	 */
	public function test_api_connection() {
		if ( empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'message' => 'OpenAI API key is not configured.',
			);
		}

		try {
			$test_prompt = 'Please respond with a simple JSON object: {"status": "success", "message": "API connection working"}';

			$result = $this->call_ai_api( $test_prompt );

			// Extract content from result array (call_ai_api returns array with 'content' and 'usage')
			$content = isset( $result['content'] ) ? $result['content'] : $result;

			// Try to parse the result
			$parsed = json_decode( $content, true );

			if ( json_last_error() === JSON_ERROR_NONE && isset( $parsed['status'] ) ) {
				return array(
					'success' => true,
					'message' => 'OpenAI API connection successful',
					'model'   => $this->model,
				);
			} else {
				return array(
					'success'  => true,
					'message'  => 'API responded but format was unexpected',
					'model'    => $this->model,
					'response' => substr( $content, 0, 200 ),
				);
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Clear analysis data for a post
	 * Note: Does NOT clear priority_score as it comes from GSC data, not analysis
	 */
	public function clear_analysis( $post_id ) {
		global $wpdb;

		$analysis_table = $wpdb->prefix . 'freshrank_analysis';
		$articles_table = $wpdb->prefix . 'freshrank_articles';

		// Remove all analysis records for this post
		$wpdb->delete( $analysis_table, array( 'post_id' => $post_id ), array( '%d' ) );

		// Reset analysis status in articles table (but keep priority_score)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe use of interpolated variable
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$articles_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$wpdb->update(
				$articles_table,
				array(
					'analysis_status' => 'pending',
				),
				array( 'post_id' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return true;
	}
}
