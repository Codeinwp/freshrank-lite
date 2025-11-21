<?php
/**
 * Settings API - AI Provider Configuration
 * Handles OpenAI and OpenRouter settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings_API {

	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Private constructor for singleton
	}

	/**
	 * Render AI configuration settings
	 */
	public function render_ai_settings() {
		$api_key                     = get_option( 'freshrank_openai_api_key', '' );
		$analysis_model              = get_option( 'freshrank_analysis_model', 'gpt-5' );
		$content_model               = get_option( 'freshrank_content_model', 'gpt-5' );
		$ai_provider                 = get_option( 'freshrank_ai_provider', 'openai' );
		$custom_instructions_enabled = get_option( 'freshrank_custom_instructions_enabled', 0 );
		$custom_analysis_prompt      = get_option( 'freshrank_custom_analysis_prompt', '' );
		$custom_rewrite_prompt       = get_option( 'freshrank_custom_rewrite_prompt', '' );

		// Get available models
		$available_models = array();
		if ( ! empty( $api_key ) ) {
			try {
				$analyzer         = new FreshRank_AI_Analyzer();
				$available_models = $analyzer->get_available_models();
			} catch ( Exception $e ) {
				$available_models = array();
			}
		}

		?>
		<!-- AI Provider Selection -->
		<div class="freshrank-field" style="background: #f0f6fc; padding: 15px; border-left: 4px solid #0073aa; margin-bottom: 25px;">
			<label style="font-weight: 600; font-size: 14px; margin-bottom: 10px; display: block;">
				<?php _e( 'Select AI Provider', 'freshrank-ai' ); ?>
			</label>

			<?php $this->render_provider_selection( $ai_provider ); ?>
		</div>

		<!-- OpenAI Settings Section -->
		<div id="freshrank-openai-settings" style="<?php echo ( $ai_provider === 'openrouter' ) ? 'display:none;' : ''; ?>">
			<?php $this->render_openai_settings( $api_key, $analysis_model, $content_model, $available_models ); ?>
		</div>

		<?php if ( ! freshrank_is_free_version() ) : ?>
		<!-- OpenRouter Settings Section (Pro only) -->
		<div id="freshrank-openrouter-settings" style="<?php echo ( $ai_provider === 'openai' ) ? 'display:none;' : ''; ?>">
			<?php $this->render_openrouter_settings(); ?>
		</div>
		<?php endif; ?>

		<!-- Custom Instructions Section -->
		<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
			<?php $this->render_custom_instructions( $custom_instructions_enabled, $custom_analysis_prompt, $custom_rewrite_prompt, freshrank_is_free_version() ); ?>
		</div>

		<?php $this->render_character_counter_script(); ?>
		<?php
	}

	/**
	 * Render provider selection radio buttons
	 */
	private function render_provider_selection( $ai_provider ) {
		if ( freshrank_is_free_version() ) {
			// Free version: Locked to OpenAI
			?>
			<label style="display: block; margin: 10px 0; cursor: not-allowed; opacity: 0.7;">
				<input type="radio" name="ai_provider" value="openai" checked disabled style="margin-right: 8px;" />
				<strong><?php _e( 'OpenAI', 'freshrank-ai' ); ?></strong>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(GPT-5 - Free Version Locked)', 'freshrank-ai' ); ?></span>
			</label>
			<input type="hidden" name="ai_provider" value="openai" />

			<label style="display: block; margin: 10px 0; cursor: not-allowed; opacity: 0.4;">
				<input type="radio" name="ai_provider" value="openrouter" disabled style="margin-right: 8px;" />
				<strong><?php _e( 'OpenRouter', 'freshrank-ai' ); ?></strong>
				<span style="background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'PRO ONLY', 'freshrank-ai' ); ?></span>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(Access 450+ models - Gemini, Claude, Llama, etc.)', 'freshrank-ai' ); ?></span>
			</label>

			<label style="display: block; margin: 10px 0; cursor: not-allowed; opacity: 0.4;" class="freshrank-agent-label">
				<input type="radio" name="ai_provider" value="freshrank-agent" disabled style="margin-right: 8px;" />
				<strong><?php _e( 'FreshRank Agent', 'freshrank-ai' ); ?></strong>
				<span style="background: #4CAF50; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'COMING SOON', 'freshrank-ai' ); ?></span>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(Managed service - no API key needed)', 'freshrank-ai' ); ?></span>
			</label>

			<p class="description" style="margin-top: 10px; color: #666;">
				<?php _e( 'Free version uses OpenAI with GPT-5 for analysis.', 'freshrank-ai' ); ?>
				<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
				<?php _e( 'to access 450+ models via OpenRouter (Gemini, Claude, Llama, etc.).', 'freshrank-ai' ); ?>
			</p>
		<?php } else { ?>
			<!-- Pro version: Full provider selection -->
			<label style="display: block; margin: 10px 0; cursor: pointer;">
				<input type="radio" name="ai_provider" value="openai" <?php checked( $ai_provider, 'openai' ); ?> style="margin-right: 8px;" />
				<strong><?php _e( 'OpenAI', 'freshrank-ai' ); ?></strong>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(Default - GPT-5, O3 models)', 'freshrank-ai' ); ?></span>
			</label>

			<label style="display: block; margin: 10px 0; cursor: pointer;">
				<input type="radio" name="ai_provider" value="openrouter" <?php checked( $ai_provider, 'openrouter' ); ?> style="margin-right: 8px;" />
				<strong><?php _e( 'OpenRouter', 'freshrank-ai' ); ?></strong>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(Access 450+ models - Gemini, Claude, Llama, etc.)', 'freshrank-ai' ); ?></span>
			</label>

			<label style="display: block; margin: 10px 0; cursor: pointer; opacity: 0.7; position: relative;" class="freshrank-agent-label">
				<input type="radio" name="ai_provider" value="freshrank-agent" disabled style="margin-right: 8px;" />
				<strong><?php _e( 'FreshRank Agent', 'freshrank-ai' ); ?></strong>
				<span style="background: #4CAF50; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'COMING SOON', 'freshrank-ai' ); ?></span>
				<span style="color: #666; margin-left: 5px;"><?php _e( '(Managed service - no API key needed)', 'freshrank-ai' ); ?></span>
				<span class="dashicons dashicons-info-outline" style="color: #667eea; margin-left: 5px; font-size: 18px; vertical-align: middle; cursor: help;"></span>
			</label>

			<p class="description" style="margin-top: 10px;">
				<?php _e( 'OpenAI is recommended for most users. Choose OpenRouter to access additional models like Gemini 2.5 Pro (best for creative writing) or Claude 4.', 'freshrank-ai' ); ?>
			</p>
			<?php
		}
	}

	/**
	 * Render OpenAI settings
	 */
	private function render_openai_settings( $api_key, $analysis_model, $content_model, $available_models ) {
		// Show placeholder if key exists, empty if no key
		$display_value = ! empty( $api_key ) ? str_repeat( '•', 20 ) : '';
		$has_key       = ! empty( $api_key );
		?>
		<div class="freshrank-field">
			<label for="openai_api_key"><?php _e( 'OpenAI API Key', 'freshrank-ai' ); ?></label>
			<input type="password" name="openai_api_key" id="openai_api_key"
					value="<?php echo esc_attr( $display_value ); ?>"
					placeholder="<?php echo $has_key ? esc_attr__( 'Key saved - enter new key to change', 'freshrank-ai' ) : esc_attr__( 'sk-...', 'freshrank-ai' ); ?>"
					class="regular-text" />
			<p class="description">
				<?php if ( $has_key ) : ?>
					<?php _e( 'API key is saved. Leave empty to keep current key, or enter a new key to replace it.', 'freshrank-ai' ); ?>
				<?php else : ?>
					<?php _e( 'Get your API key from', 'freshrank-ai' ); ?>
					<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>
				<?php endif; ?>
			</p>
			<?php if ( $has_key ) : ?>
				<p>
					<button type="button" id="freshrank-test-openai-connection" class="button">
						<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
						<?php _e( 'Test Connection', 'freshrank-ai' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</div>

		<div class="freshrank-field">
			<label for="analysis_model"><?php _e( 'Analysis Model', 'freshrank-ai' ); ?></label>
			<?php if ( freshrank_is_free_version() ) : ?>
				<!-- Free version: Locked to GPT-5 -->
				<select name="analysis_model" id="analysis_model" disabled style="background: #f6f7f7; cursor: not-allowed;">
					<option value="gpt-5" selected>GPT-5 - Flagship (Free Version - Locked)</option>
				</select>
				<input type="hidden" name="analysis_model" value="gpt-5" />
				<p class="description" style="color: #666;">
					<?php _e( 'Free version uses GPT-5 for high-quality analysis.', 'freshrank-ai' ); ?>
					<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
					<?php _e( 'to choose from all OpenAI models plus 450+ OpenRouter models (Gemini, Claude, Llama, etc.).', 'freshrank-ai' ); ?>
				</p>
			<?php else : ?>
			<!-- Pro version: Full model selection -->
			<select name="analysis_model" id="analysis_model">
				<?php $this->render_model_options( $available_models, $analysis_model ); ?>
			</select>
			<p class="description">
				<strong><?php _e( 'Recommended:', 'freshrank-ai' ); ?></strong> GPT-5 (best balance of speed and quality)
				<br><?php _e( 'This model is used to analyze content and identify issues.', 'freshrank-ai' ); ?>
				<br><small style="color: #666;">Current: <?php echo esc_html( $analysis_model ); ?></small>
			</p>
			<?php endif; ?>
		</div>

		<!-- Content Rewrite Model -->
		<div class="freshrank-field">
			<label for="content_model">
				<?php _e( 'Content Rewrite Model', 'freshrank-ai' ); ?>
				<?php if ( freshrank_is_free_version() ) : ?>
					<span style="background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'PRO ONLY', 'freshrank-ai' ); ?></span>
				<?php endif; ?>
			</label>
			<?php if ( freshrank_is_free_version() ) : ?>
				<select name="content_model" id="content_model" disabled style="background: #f6f7f7; cursor: not-allowed; opacity: 0.7;">
					<option>GPT-5 - Free Version (Limited to Factual Updates + HIGH severity)</option>
				</select>
				<input type="hidden" name="content_model" value="gpt-5" />
				<p class="description">
					<?php _e( 'Free version includes draft creation using GPT-5, limited to Factual Updates issues with HIGH severity only.', 'freshrank-ai' ); ?>
					<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
					<?php _e( 'to unlock all categories/severities and choose from 450+ models (Gemini, Claude, Llama, etc.).', 'freshrank-ai' ); ?>
				</p>
			<?php else : ?>
				<select name="content_model" id="content_model">
					<?php $this->render_model_options( $available_models, $content_model ); ?>
				</select>
				<p class="description">
					<strong><?php _e( 'Note:', 'freshrank-ai' ); ?></strong> GPT-5 is excellent for factual accuracy and reasoning. For superior creative writing quality, consider OpenRouter with Gemini 2.5 Pro or Claude 4.
					<br><?php _e( 'This model is used to generate updated content drafts.', 'freshrank-ai' ); ?>
					<br><small style="color: #666;">Current: <?php echo esc_html( $content_model ); ?></small>
				</p>
			<?php endif; ?>
		</div>

		<!-- Web Search -->
		<div class="freshrank-field">
			<label>
				<?php if ( freshrank_is_free_version() ) : ?>
					<input type="checkbox" name="enable_web_search" value="1" disabled style="cursor: not-allowed; opacity: 0.7;">
					<strong><?php _e( 'Enable Web Search', 'freshrank-ai' ); ?></strong>
					<span style="background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'PRO ONLY', 'freshrank-ai' ); ?></span>
				<?php else : ?>
					<input type="checkbox" name="enable_web_search" value="1" <?php checked( get_option( 'freshrank_enable_web_search', 0 ) ); ?>>
					<strong><?php _e( 'Enable Web Search', 'freshrank-ai' ); ?></strong>
				<?php endif; ?>
			</label>
			<p class="description">
				<?php if ( freshrank_is_free_version() ) : ?>
					<?php _e( 'Web search enables GPT-5 models to access real-time information during analysis and content generation.', 'freshrank-ai' ); ?>
					<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
					<?php _e( 'to enable this feature.', 'freshrank-ai' ); ?>
				<?php else : ?>
					<?php _e( 'Allow GPT-5 models to search the web for up-to-date information during analysis and content generation. This helps with current events, recent data, and fact-checking.', 'freshrank-ai' ); ?>
					<br><span style="color: #d63638;"><strong><?php _e( 'Note:', 'freshrank-ai' ); ?></strong>
					<?php _e( 'Only works with GPT-5 models. Slightly slower and more expensive.', 'freshrank-ai' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render model options for select dropdown
	 */
	private function render_model_options( $available_models, $selected_model ) {
		if ( ! empty( $available_models ) ) {
			foreach ( $available_models as $model_key => $model_info ) {
				?>
				<option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $selected_model, $model_key ); ?>>
					<?php echo esc_html( $model_info['name'] ); ?> -
					<?php echo esc_html( $model_info['description'] ); ?>
				</option>
				<?php
			}
		} else {
			// Fallback model list
			$fallback_models = array(
				'gpt-5-nano'  => 'GPT-5 Nano - Ultra-fast, best for analysis (400K)',
				'gpt-5-mini'  => 'GPT-5 Mini - Balanced (400K)',
				'gpt-5'       => 'GPT-5 - Flagship (400K)',
				'gpt-5-pro'   => 'GPT-5 Pro - Most Advanced (400K)',
				'o3-mini'     => 'O3 Mini - Reasoning (200K)',
				'o3-pro'      => 'O3 Pro - Highest Reasoning (200K)',
				'gpt-4o-mini' => 'GPT-4o Mini - Legacy',
				'gpt-4o'      => 'GPT-4o - Legacy',
			);
			foreach ( $fallback_models as $model_key => $model_label ) {
				?>
				<option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $selected_model, $model_key ); ?>>
					<?php echo esc_html( $model_label ); ?>
				</option>
				<?php
			}
		}
	}

	/**
	 * Render OpenRouter settings (Pro only)
	 */
	private function render_openrouter_settings() {
		$openrouter_api_key               = get_option( 'freshrank_openrouter_api_key', '' );
		$openrouter_model_analysis        = get_option( 'freshrank_openrouter_model_analysis', '' );
		$openrouter_model_writing         = get_option( 'freshrank_openrouter_model_writing', '' );
		$openrouter_custom_model_analysis = get_option( 'freshrank_openrouter_custom_model_analysis', '' );
		$openrouter_custom_model_writing  = get_option( 'freshrank_openrouter_custom_model_writing', '' );

		// Show placeholder if key exists, empty if no key
		$display_value = ! empty( $openrouter_api_key ) ? str_repeat( '•', 20 ) : '';
		$has_key       = ! empty( $openrouter_api_key );

		freshrank_debug_log( 'render_openrouter_settings: $has_key = ' . ( $has_key ? 'TRUE' : 'FALSE' ) );

		?>
		<div class="freshrank-field">
			<label for="openrouter_api_key"><?php _e( 'OpenRouter API Key', 'freshrank-ai' ); ?></label>
			<input type="password" name="openrouter_api_key" id="openrouter_api_key"
					value="<?php echo esc_attr( $display_value ); ?>"
					placeholder="<?php echo $has_key ? esc_attr__( 'Key saved - enter new key to change', 'freshrank-ai' ) : esc_attr__( 'sk-or-v1-...', 'freshrank-ai' ); ?>"
					class="regular-text" />
			<p class="description">
				<?php if ( $has_key ) : ?>
					<?php _e( 'API key is saved. Leave empty to keep current key, or enter a new key to replace it.', 'freshrank-ai' ); ?>
				<?php else : ?>
					<?php _e( 'Get your API key from', 'freshrank-ai' ); ?>
					<a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" id="freshrank-test-openrouter-connection" class="button">
					<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
					<?php _e( 'Test Connection', 'freshrank-ai' ); ?>
				</button>
				<?php if ( $has_key ) : ?>
				<button type="button" id="freshrank-refresh-openrouter-models" class="button" style="margin-left: 5px;">
					<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
					<?php _e( 'Refresh Model List', 'freshrank-ai' ); ?>
				</button>
				<?php endif; ?>
			</p>
		</div>

		<div class="freshrank-field">
			<label for="openrouter_model_analysis"><?php _e( 'Analysis Model', 'freshrank-ai' ); ?></label>
			<div class="freshrank-model-selector">
				<input type="search"
						id="openrouter_model_analysis_search"
						class="regular-text freshrank-model-search"
						placeholder="<?php echo esc_attr__( 'Search models...', 'freshrank-ai' ); ?>"
						autocomplete="off"
						data-target="analysis" />
				<select name="openrouter_model_analysis"
						id="openrouter_model_analysis"
						class="regular-text"
						data-placeholder="<?php echo esc_attr__( 'Select a model...', 'freshrank-ai' ); ?>">
				<option value=""><?php _e( 'Select a model...', 'freshrank-ai' ); ?></option>
				<?php if ( ! empty( $openrouter_model_analysis ) ) : ?>
					<option value="<?php echo esc_attr( $openrouter_model_analysis ); ?>" selected>
						<?php echo esc_html( $openrouter_model_analysis ); ?>
					</option>
				<?php endif; ?>
				</select>
			</div>
			<p class="description">
				<strong><?php _e( 'Recommended:', 'freshrank-ai' ); ?></strong> google/gemini-2.5-pro (best quality), anthropic/claude-4 (high quality), or meta-llama/llama-3.3-70b (budget)
				<br><?php _e( 'This model is used to analyze content and identify issues.', 'freshrank-ai' ); ?>
				<br><?php _e( 'Type in the search box to filter models. Results are sorted by real usage popularity.', 'freshrank-ai' ); ?>
			</p>
			<p class="description freshrank-cost-disclaimer">
				<?php _e( 'Cost estimates shown below are approximate and based on current OpenRouter pricing. Actual charges may differ.', 'freshrank-ai' ); ?>
			</p>
		</div>

		<div class="freshrank-field">
			<label for="openrouter_custom_model_analysis"><?php _e( 'Or enter custom model ID for Analysis', 'freshrank-ai' ); ?></label>
			<input type="text" name="openrouter_custom_model_analysis" id="openrouter_custom_model_analysis"
					value="<?php echo esc_attr( $openrouter_custom_model_analysis ); ?>" class="regular-text"
					placeholder="e.g., google/gemini-2.5-pro" />
			<p class="description">
				<?php _e( 'Enter a custom OpenRouter model ID if you want to use a model not listed above.', 'freshrank-ai' ); ?>
				<br><a href="https://openrouter.ai/docs/models" target="_blank"><?php _e( 'Browse all models', 'freshrank-ai' ); ?></a>
			</p>
		</div>

		<div class="freshrank-field">
			<label for="openrouter_model_writing"><?php _e( 'Content Rewrite Model', 'freshrank-ai' ); ?></label>
			<div class="freshrank-model-selector">
				<input type="search"
						id="openrouter_model_writing_search"
						class="regular-text freshrank-model-search"
						placeholder="<?php echo esc_attr__( 'Search models...', 'freshrank-ai' ); ?>"
						autocomplete="off"
						data-target="writing" />
				<select name="openrouter_model_writing"
						id="openrouter_model_writing"
						class="regular-text"
						data-placeholder="<?php echo esc_attr__( 'Select a model...', 'freshrank-ai' ); ?>">
				<option value=""><?php _e( 'Select a model...', 'freshrank-ai' ); ?></option>
				<?php if ( ! empty( $openrouter_model_writing ) ) : ?>
					<option value="<?php echo esc_attr( $openrouter_model_writing ); ?>" selected>
						<?php echo esc_html( $openrouter_model_writing ); ?>
					</option>
				<?php endif; ?>
				</select>
			</div>
			<p class="description">
				<strong><?php _e( 'Recommended:', 'freshrank-ai' ); ?></strong> google/gemini-2.5-pro (best for creative writing), anthropic/claude-4 (high quality), or openai/gpt-5
				<br><?php _e( 'This model is used to generate updated content drafts.', 'freshrank-ai' ); ?>
				<br><?php _e( 'Use the search field to quickly find specific models. List order reflects current OpenRouter usage.', 'freshrank-ai' ); ?>
			</p>
			<p class="description freshrank-cost-disclaimer">
				<?php _e( 'Draft generation costs depend on output length. Use these estimates as guidance only; OpenRouter invoices reflect actual usage.', 'freshrank-ai' ); ?>
			</p>
		</div>

		<div class="freshrank-field">
			<label for="openrouter_custom_model_writing"><?php _e( 'Or enter custom model ID for Writing', 'freshrank-ai' ); ?></label>
			<input type="text" name="openrouter_custom_model_writing" id="openrouter_custom_model_writing"
					value="<?php echo esc_attr( $openrouter_custom_model_writing ); ?>" class="regular-text"
					placeholder="e.g., google/gemini-2.5-pro" />
			<p class="description">
				<?php _e( 'Enter a custom OpenRouter model ID if you want to use a model not listed above.', 'freshrank-ai' ); ?>
				<br><a href="https://openrouter.ai/docs/models" target="_blank"><?php _e( 'Browse all models', 'freshrank-ai' ); ?></a>
			</p>
		</div>

		<!-- Model Recommendations -->
		<div class="freshrank-field" style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-top: 20px;">
			<h4 style="margin-top: 0;"><?php _e( 'Model Recommendations', 'freshrank-ai' ); ?></h4>
			<p><strong><?php _e( 'Best for Creative Writing:', 'freshrank-ai' ); ?></strong></p>
			<ul style="margin: 10px 0;">
				<li><code>google/gemini-2.5-pro</code> - Best overall for creative content</li>
				<li><code>anthropic/claude-4</code> - Excellent quality and reasoning</li>
				<li><code>x-ai/grok-3</code> - Creative and engaging style</li>
			</ul>

			<p><strong><?php _e( 'Best for Analysis:', 'freshrank-ai' ); ?></strong></p>
			<ul style="margin: 10px 0;">
				<li><code>openai/o3-mini</code> - Strong reasoning capabilities</li>
				<li><code>google/gemini-2.5-pro</code> - Comprehensive analysis</li>
				<li><code>anthropic/claude-4</code> - Detailed insights</li>
			</ul>

			<p><strong><?php _e( 'Budget-Friendly:', 'freshrank-ai' ); ?></strong></p>
			<ul style="margin: 10px 0;">
				<li><code>meta-llama/llama-3.3-70b</code> - Great quality, lower cost</li>
				<li><code>google/gemini-2.0-flash</code> - Fast and affordable</li>
				<li><code>microsoft/phi-4</code> - Efficient small model</li>
			</ul>

			<p class="description">
				<a href="https://openrouter.ai/rankings" target="_blank"><?php _e( 'View full rankings and pricing', 'freshrank-ai' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render custom instructions section (Pro only)
	 */
	private function render_custom_instructions( $enabled, $analysis_prompt, $rewrite_prompt, $is_free = false ) {
		?>
		<!-- Enable Custom Instructions Checkbox -->
		<div class="freshrank-field" style="background: #f0f6fc; padding: 15px; border-left: 4px solid <?php echo $is_free ? '#f0ad4e' : '#2271b1'; ?>; margin-bottom: 20px;">
			<label style="<?php echo $is_free ? 'cursor: not-allowed; opacity: 0.7;' : ''; ?>">
				<input type="checkbox" name="custom_instructions_enabled" id="custom_instructions_enabled" value="1" <?php checked( $enabled, 1 ); ?> <?php echo $is_free ? 'disabled' : ''; ?> />
				<strong><?php _e( 'Enable Custom Instructions (Optional)', 'freshrank-ai' ); ?></strong>
				<?php if ( $is_free ) : ?>
					<span style="background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; font-weight: 600;"><?php _e( 'PRO ONLY', 'freshrank-ai' ); ?></span>
				<?php endif; ?>
			</label>
			<p class="description" style="margin-top: 10px;">
				<?php if ( $is_free ) : ?>
					<?php _e( 'Custom instructions allow you to guide the AI\'s behavior with your brand voice, terminology preferences, and content guidelines.', 'freshrank-ai' ); ?>
					<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
					<?php _e( 'to customize AI behavior for your specific needs.', 'freshrank-ai' ); ?>
				<?php else : ?>
					<?php _e( 'Add custom instructions to guide the AI\'s analysis and content generation. Use this for brand voice guidelines, industry terminology, or content preferences. Keep it concise to minimize API costs.', 'freshrank-ai' ); ?>
				<?php endif; ?>
			</p>
		</div>

		<?php if ( $is_free ) : ?>
			<!-- Free Version: Show locked preview -->
			<div style="opacity: 0.5; pointer-events: none;">
		<?php endif; ?>

		<!-- Custom Instructions Textareas (Hidden by default in pro, always hidden in free) -->
		<div id="freshrank-custom-instructions-fields" style="<?php echo ( $enabled && ! $is_free ) ? '' : 'display:none;'; ?>">
			<div class="freshrank-field">
				<label for="custom_analysis_prompt"><?php _e( 'Analysis Prompt Append', 'freshrank-ai' ); ?></label>
				<textarea name="custom_analysis_prompt" id="custom_analysis_prompt"
							rows="4" class="large-text code freshrank-custom-prompt-textarea"
							maxlength="1000"
							placeholder="<?php esc_attr_e( 'Example: Please ignore heading hierarchy issues. Focus only on content quality and keyword optimization.', 'freshrank-ai' ); ?>"><?php echo esc_textarea( $analysis_prompt ); ?></textarea>
				<p class="description">
					<?php _e( 'Add custom instructions to the analysis prompt. This will be appended to every analysis request.', 'freshrank-ai' ); ?>
					<br><strong><?php _e( 'Examples:', 'freshrank-ai' ); ?></strong>
					<br>• <?php _e( '"Write in a conversational, approachable tone"', 'freshrank-ai' ); ?>
					<br>• <?php _e( '"Use British English spelling throughout"', 'freshrank-ai' ); ?>
					<br>• <?php _e( '"Focus on technical accuracy over keyword optimization"', 'freshrank-ai' ); ?>
					<br><span id="custom_analysis_prompt_counter" style="color: #666; font-size: 12px;">0/1000 <?php _e( 'characters', 'freshrank-ai' ); ?></span>
				</p>
			</div>

			<div class="freshrank-field">
				<label for="custom_rewrite_prompt"><?php _e( 'Content Rewrite Prompt Append', 'freshrank-ai' ); ?></label>
				<textarea name="custom_rewrite_prompt" id="custom_rewrite_prompt"
							rows="4" class="large-text code freshrank-custom-prompt-textarea"
							maxlength="1000"
							placeholder="<?php esc_attr_e( 'Example: Do not modify the title or headings. Keep the existing tone and writing style.', 'freshrank-ai' ); ?>"><?php echo esc_textarea( $rewrite_prompt ); ?></textarea>
				<p class="description">
					<?php _e( 'Add custom instructions to the content rewrite prompt. This will be appended to every draft creation request.', 'freshrank-ai' ); ?>
					<br><strong><?php _e( 'Examples:', 'freshrank-ai' ); ?></strong>
					<br>• <?php _e( '"Always maintain a professional tone"', 'freshrank-ai' ); ?>
					<br>• <?php _e( '"Use \'clients\' not \'customers\'"', 'freshrank-ai' ); ?>
					<br>• <?php _e( '"Include data citations for all statistics mentioned"', 'freshrank-ai' ); ?>
					<br><span id="custom_rewrite_prompt_counter" style="color: #666; font-size: 12px;">0/1000 <?php _e( 'characters', 'freshrank-ai' ); ?></span>
				</p>
			</div>
		</div>

		<?php if ( $is_free ) : ?>
			</div><!-- Close locked preview wrapper -->
		<?php endif; ?>
		<?php
	}

	/**
	 * Render character counter JavaScript
	 */
	private function render_character_counter_script() {
		?>
		<script>
		jQuery(document).ready(function($) {
			// Custom Instructions Toggle
			$('#custom_instructions_enabled').on('change', function() {
				var isChecked = $(this).is(':checked');
				if (isChecked) {
					$('#freshrank-custom-instructions-fields').slideDown(300);
				} else {
					$('#freshrank-custom-instructions-fields').slideUp(300);
				}
			});

			// Character counter for custom prompts
			function updateCharCounter(textarea, counter) {
				var length = textarea.val().length;
				var maxLength = textarea.attr('maxlength') || 1000;
				counter.text(length + '/' + maxLength + ' characters');

				// Color feedback
				if (length > maxLength * 0.9) {
					counter.css('color', '#d63638'); // Red when approaching limit
				} else if (length > maxLength * 0.75) {
					counter.css('color', '#dba617'); // Orange at 75%
				} else {
					counter.css('color', '#666'); // Gray default
				}
			}

			// Initialize character counters
			var $analysisPrompt = $('#custom_analysis_prompt');
			var $analysisCounter = $('#custom_analysis_prompt_counter');
			var $rewritePrompt = $('#custom_rewrite_prompt');
			var $rewriteCounter = $('#custom_rewrite_prompt_counter');

			if ($analysisPrompt.length && $analysisCounter.length) {
				updateCharCounter($analysisPrompt, $analysisCounter);
				$analysisPrompt.on('input', function() {
					updateCharCounter($analysisPrompt, $analysisCounter);
				});
			}

			if ($rewritePrompt.length && $rewriteCounter.length) {
				updateCharCounter($rewritePrompt, $rewriteCounter);
				$rewritePrompt.on('input', function() {
					updateCharCounter($rewritePrompt, $rewriteCounter);
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Save AI settings
	 */
	public function save_ai_settings() {
		// Save OpenAI API key
		if ( isset( $_POST['openai_api_key'] ) ) {
			// Get raw value without length validation first
			$api_key = sanitize_text_field( $_POST['openai_api_key'] );

			// Skip if this is the placeholder being sent back (user didn't change the field)
			if ( $api_key === str_repeat( '•', 20 ) ) {
				freshrank_debug_log( 'OpenAI API key unchanged (placeholder detected)' );
				// Skip if empty (user cleared field = keep existing key)
			} elseif ( empty( $api_key ) ) {
				freshrank_debug_log( 'OpenAI API key unchanged (empty field = keep existing)' );
				// Skip if this is the encrypted value being sent back (shouldn't happen with new UI, but keep for safety)
			} elseif ( strpos( $api_key, 'encrypted:' ) === 0 ) {
				freshrank_debug_log( 'OpenAI API key unchanged (encrypted value detected)' );
				// Validate and save NEW key
			} else {
				// Length validation for new keys
				if ( strlen( $api_key ) > 500 ) {
					throw new Exception( __( 'API key is too long. Please check you pasted the correct key.', 'freshrank-ai' ) );
				}

				// Basic validation for API key format
				if ( ! preg_match( '/^sk-[a-zA-Z0-9_-]+$/', $api_key ) ) {
					freshrank_debug_log( 'Invalid OpenAI API key format: ' . substr( $api_key, 0, 10 ) . '...' );
					throw new Exception( __( 'Invalid OpenAI API key format. OpenAI keys start with "sk-" followed by letters, numbers, underscores and dashes. Please check your key and try again.', 'freshrank-ai' ) );
				}

				// Encrypt API key before saving
				$encrypted_key = FreshRank_Encryption::encrypt( $api_key );
				update_option( 'freshrank_openai_api_key', $encrypted_key );
				freshrank_debug_log( 'New OpenAI API key saved successfully' );
			}
		}

		// Valid models list
		$valid_models = array(
			'gpt-5-pro',
			'gpt-5',
			'gpt-5-mini',
			'gpt-5-nano',
			'o3-mini',
			'o3-pro',
			'gpt-4o-mini',
			'gpt-4o',
		);

		// Save analysis model
		if ( isset( $_POST['analysis_model'] ) ) {
			$analysis_model = FreshRank_Validation_Helper::validate_enum(
				$_POST['analysis_model'],
				$valid_models,
				'gpt-5'
			);
			update_option( 'freshrank_analysis_model', $analysis_model );
		}

		// Save content model
		if ( isset( $_POST['content_model'] ) ) {
			$content_model = FreshRank_Validation_Helper::validate_enum(
				$_POST['content_model'],
				$valid_models,
				'gpt-5'
			);
			update_option( 'freshrank_content_model', $content_model );
		}

		// Web search toggle
		update_option( 'freshrank_enable_web_search', isset( $_POST['enable_web_search'] ) ? 1 : 0 );

		// Save AI provider selection
		if ( isset( $_POST['ai_provider'] ) ) {
			$ai_provider = FreshRank_Validation_Helper::validate_enum(
				$_POST['ai_provider'],
				array( 'openai', 'openrouter' ),
				'openai'
			);
			update_option( 'freshrank_ai_provider', $ai_provider );
		}

		// Save OpenRouter settings
		if ( isset( $_POST['openrouter_api_key'] ) ) {
			$openrouter_api_key = FreshRank_Validation_Helper::sanitize_text( $_POST['openrouter_api_key'], 0, 500 );

			// Skip if this is the placeholder being sent back (user didn't change the field)
			if ( $openrouter_api_key === str_repeat( '•', 20 ) ) {
				freshrank_debug_log( 'OpenRouter API key unchanged (placeholder detected)' );
				// Skip if empty (user cleared field = keep existing key)
			} elseif ( empty( $openrouter_api_key ) ) {
				freshrank_debug_log( 'OpenRouter API key unchanged (empty field = keep existing)' );
				// Skip if this is the encrypted value being sent back (shouldn't happen with new UI, but keep for safety)
			} elseif ( strpos( $openrouter_api_key, 'encrypted:' ) === 0 ) {
				freshrank_debug_log( 'OpenRouter API key unchanged (encrypted value detected)' );
				// Validate and save NEW key
			} else {
				// Encrypt API key before saving
				$encrypted_key = FreshRank_Encryption::encrypt( $openrouter_api_key );
				update_option( 'freshrank_openrouter_api_key', $encrypted_key );
				freshrank_debug_log( 'New OpenRouter API key saved successfully' );
			}
		}

		// Save OpenRouter model selections
		if ( isset( $_POST['openrouter_model_analysis'] ) ) {
			$raw_model = trim( (string) wp_unslash( $_POST['openrouter_model_analysis'] ) );
			if ( '' !== $raw_model ) {
				$model = FreshRank_Validation_Helper::sanitize_model_name( $raw_model );
				update_option( 'freshrank_openrouter_model_analysis', $model );
			}
		}

		if ( isset( $_POST['openrouter_model_writing'] ) ) {
			$raw_model = trim( (string) wp_unslash( $_POST['openrouter_model_writing'] ) );
			if ( '' !== $raw_model ) {
				$model = FreshRank_Validation_Helper::sanitize_model_name( $raw_model );
				update_option( 'freshrank_openrouter_model_writing', $model );
			}
		}

		// Save OpenRouter custom model IDs
		if ( isset( $_POST['openrouter_custom_model_analysis'] ) ) {
			$custom_model = FreshRank_Validation_Helper::sanitize_model_name( $_POST['openrouter_custom_model_analysis'] );
			update_option( 'freshrank_openrouter_custom_model_analysis', $custom_model );
		}

		if ( isset( $_POST['openrouter_custom_model_writing'] ) ) {
			$custom_model = FreshRank_Validation_Helper::sanitize_model_name( $_POST['openrouter_custom_model_writing'] );
			update_option( 'freshrank_openrouter_custom_model_writing', $custom_model );
		}

		if ( isset( $_POST['rate_limit_delay'] ) ) {
			$delay = FreshRank_Validation_Helper::validate_int_range( $_POST['rate_limit_delay'], 500, 10000 );
			update_option( 'freshrank_rate_limit_delay', $delay );
		}

		// Save custom instructions enabled checkbox
		update_option( 'freshrank_custom_instructions_enabled', isset( $_POST['custom_instructions_enabled'] ) ? 1 : 0 );

		// Save custom prompt appends (only if enabled)
		if ( isset( $_POST['custom_instructions_enabled'] ) ) {
			if ( isset( $_POST['custom_analysis_prompt'] ) ) {
				$custom_analysis_prompt = sanitize_textarea_field( trim( $_POST['custom_analysis_prompt'] ) );
				// Limit to 1000 characters
				$custom_analysis_prompt = substr( $custom_analysis_prompt, 0, 1000 );
				update_option( 'freshrank_custom_analysis_prompt', $custom_analysis_prompt );
			}

			if ( isset( $_POST['custom_rewrite_prompt'] ) ) {
				$custom_rewrite_prompt = sanitize_textarea_field( trim( $_POST['custom_rewrite_prompt'] ) );
				// Limit to 1000 characters
				$custom_rewrite_prompt = substr( $custom_rewrite_prompt, 0, 1000 );
				update_option( 'freshrank_custom_rewrite_prompt', $custom_rewrite_prompt );
			}
		} else {
			// If disabled, clear the prompts
			update_option( 'freshrank_custom_analysis_prompt', '' );
			update_option( 'freshrank_custom_rewrite_prompt', '' );
		}
	}
}
