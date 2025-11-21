<?php
/**
 * Settings Filters - Content Update Filter Configuration
 * Handles severity and category filter matrix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings_Filters {

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
	 * Render filter settings section (integrated into general settings)
	 */
	public function render_filters_card() {
		// Matrix system - Category filters
		$fix_factual       = get_option( 'freshrank_fix_factual_updates', 1 );
		$fix_ux            = get_option( 'freshrank_fix_user_experience', 0 );
		$fix_search        = get_option( 'freshrank_fix_search_optimization', 0 );
		$fix_ai            = get_option( 'freshrank_fix_ai_visibility', 0 );
		$fix_opportunities = get_option( 'freshrank_fix_opportunities', 0 );

		// Matrix system - Severity filters
		$severity_high   = get_option( 'freshrank_severity_high', 1 );
		$severity_medium = get_option( 'freshrank_severity_medium', freshrank_is_free_version() ? 1 : 0 );
		$severity_low    = get_option( 'freshrank_severity_low', 0 );

		$is_free = freshrank_is_free_version();
		?>
		<!-- Matrix Filters Card -->
		<div class="freshrank-card">
			<h3>
				<span class="dashicons dashicons-admin-tools"></span> <?php _e( 'Content Update Filters', 'freshrank-ai' ); ?>
				<?php if ( $is_free ) : ?>
					<span style="background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px; font-weight: 600;"><?php _e( 'PRO ONLY', 'freshrank-ai' ); ?></span>
				<?php endif; ?>
			</h3>
			<div class="freshrank-card-body">
				<?php if ( $is_free ) : ?>
					<div style="background: #fff3cd; border-left: 4px solid #f0ad4e; padding: 12px; margin-bottom: 20px;">
						<p style="margin: 0; font-weight: 600;">
							🔒 <?php _e( 'Free Version: Locked to Factual Updates + HIGH Severity', 'freshrank-ai' ); ?>
						</p>
						<p style="margin: 8px 0 0 0; font-size: 13px;">
							<?php _e( 'Free version draft creation is limited to fixing critical Factual Updates issues only.', 'freshrank-ai' ); ?>
							<a href="<?php echo esc_url( FRESHRANK_UPGRADE_URL ); ?>" target="_blank" style="font-weight: 600;"><?php _e( 'Upgrade to Pro', 'freshrank-ai' ); ?></a>
							<?php _e( 'to customize categories and severity levels.', 'freshrank-ai' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<p class="description" style="margin-top: 0;"><?php _e( 'Select which types of issues should be fixed when creating content drafts. Analysis will still identify all issues regardless of these settings.', 'freshrank-ai' ); ?></p>

				<?php $this->render_category_filters( $fix_factual, $fix_ux, $fix_search, $fix_ai, $fix_opportunities, $is_free ); ?>
				<?php $this->render_severity_filters( $severity_high, $severity_medium, $severity_low, $is_free ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render category selection filters
	 */
	private function render_category_filters( $fix_factual, $fix_ux, $fix_search, $fix_ai, $fix_opportunities, $is_free = false ) {
		?>
		<!-- STEP 1: Category Selection -->
		<div class="freshrank-field" style="background: #f0f6fc; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px; <?php echo $is_free ? 'opacity: 0.6;' : ''; ?>">
			<label style="font-size: 14px; font-weight: 600; margin-bottom: 10px; display: block;">
				<?php _e( 'STEP 1: Choose Categories to Fix', 'freshrank-ai' ); ?>
			</label>
			<p class="description" style="margin-bottom: 12px;">
				<?php _e( 'Select which types of issues you want fixed in draft updates.', 'freshrank-ai' ); ?>
			</p>

			<?php
			// In free version, force Factual checked and others unchecked/disabled
			if ( $is_free ) {
				$fix_factual       = 1;
				$fix_ux            = 0;
				$fix_search        = 0;
				$fix_ai            = 0;
				$fix_opportunities = 0;
			}

			$categories = array(
				array(
					'name'        => 'fix_factual_updates',
					'id'          => 'fix_factual',
					'checked'     => $fix_factual,
					'color'       => '#d63638',
					'icon'        => '📊',
					'title'       => __( 'Factual Updates', 'freshrank-ai' ),
					'description' => __( 'Outdated statistics, broken links, factual errors, discontinued tools/services', 'freshrank-ai' ),
				),
				array(
					'name'        => 'fix_user_experience',
					'id'          => 'fix_ux',
					'checked'     => $fix_ux,
					'color'       => '#f56e28',
					'icon'        => '👤',
					'title'       => __( 'User Experience', 'freshrank-ai' ),
					'description' => __( 'Poor navigation, intent mismatch, slow value delivery, accessibility problems', 'freshrank-ai' ),
				),
				array(
					'name'        => 'fix_search_optimization',
					'id'          => 'fix_search',
					'checked'     => $fix_search,
					'color'       => '#46b450',
					'icon'        => '🔍',
					'title'       => __( 'Search Optimization', 'freshrank-ai' ),
					'description' => __( 'Meta descriptions, keywords, image alt text, technical SEO structure', 'freshrank-ai' ),
				),
				array(
					'name'        => 'fix_ai_visibility',
					'id'          => 'fix_ai',
					'checked'     => $fix_ai,
					'color'       => '#7c3aed',
					'icon'        => '🤖',
					'title'       => __( 'AI Visibility', 'freshrank-ai' ),
					'description' => __( 'ChatGPT, Claude, Perplexity citation optimization (527% traffic growth in 2025!)', 'freshrank-ai' ),
				),
				array(
					'name'        => 'fix_opportunities',
					'id'          => 'fix_opp',
					'checked'     => $fix_opportunities,
					'color'       => '#2271b1',
					'icon'        => '💡',
					'title'       => __( 'Growth Opportunities', 'freshrank-ai' ),
					'description' => __( 'Featured snippets, FAQ schema markup, internal linking strategies, keyword clustering', 'freshrank-ai' ),
				),
			);

			foreach ( $categories as $category ) {
				$this->render_filter_option(
					$category['name'],
					$category['id'],
					$category['checked'],
					$category['color'],
					$category['icon'],
					$category['title'],
					$category['description'],
					$is_free
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render severity level filters
	 */
	private function render_severity_filters( $severity_high, $severity_medium, $severity_low, $is_free = false ) {
		?>
		<!-- STEP 2: Severity Selection -->
		<div class="freshrank-field" style="background: #fff9e6; padding: 15px; border-left: 4px solid #f0b849; margin-bottom: 20px; <?php echo $is_free ? 'opacity: 0.6;' : ''; ?>">
			<label style="font-size: 14px; font-weight: 600; margin-bottom: 10px; display: block;">
				<?php _e( 'STEP 2: Choose Severity Levels', 'freshrank-ai' ); ?>
			</label>
			<p class="description" style="margin-bottom: 12px;">
				<?php _e( 'Within selected categories above, fix issues based on how critical they are.', 'freshrank-ai' ); ?>
			</p>

			<?php
			// In free version, enforce High + Medium only
			if ( $is_free ) {
				if ( (int) get_option( 'freshrank_severity_medium', -1 ) !== 1 ) {
					update_option( 'freshrank_severity_medium', 1 );
				}
				update_option( 'freshrank_severity_high', 1 );
				update_option( 'freshrank_severity_low', 0 );

				$severity_high   = 1;
				$severity_medium = 1;
				$severity_low    = 0;
			}

			$severities = array(
				array(
					'name'        => 'severity_high',
					'id'          => 'sev_high',
					'checked'     => $severity_high,
					'color'       => '#d63638',
					'icon'        => '🔴',
					'title'       => __( 'High Severity', 'freshrank-ai' ),
					'description' => __( 'Critical problems with direct revenue impact (broken navigation, missing meta, intent mismatches)', 'freshrank-ai' ),
				),
				array(
					'name'        => 'severity_medium',
					'id'          => 'sev_medium',
					'checked'     => $severity_medium,
					'color'       => '#f56e28',
					'icon'        => '🟠',
					'title'       => __( 'Medium Severity', 'freshrank-ai' ),
					'description' => __( 'Important improvements with growing value (weak keywords, AI comprehension issues, schema opportunities)', 'freshrank-ai' ),
				),
				array(
					'name'        => 'severity_low',
					'id'          => 'sev_low',
					'checked'     => $severity_low,
					'color'       => '#ffc107',
					'icon'        => '🟡',
					'title'       => __( 'Low Severity', 'freshrank-ai' ),
					'description' => __( 'Minor tweaks and incremental gains (readability improvements, style consistency, formatting polish)', 'freshrank-ai' ),
				),
			);

			foreach ( $severities as $severity ) {
				$this->render_filter_option(
					$severity['name'],
					$severity['id'],
					$severity['checked'],
					$severity['color'],
					$severity['icon'],
					$severity['title'],
					$severity['description'],
					$is_free
				);
			}
			?>

			<p class="description" style="margin-top: 15px;">
				<strong><?php _e( '💡 How it works:', 'freshrank-ai' ); ?></strong><br>
				<?php _e( 'Analysis finds ALL issues. These checkboxes control which issues get FIXED in drafts.', 'freshrank-ai' ); ?><br>
				<strong><?php _e( 'Cost Impact:', 'freshrank-ai' ); ?></strong> <?php _e( 'More categories + severity levels = longer AI prompts = higher costs', 'freshrank-ai' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render individual filter option
	 */
	private function render_filter_option( $name, $id, $checked, $color, $icon, $title, $description, $is_free = false ) {
		$is_medium_option = ( $name === 'severity_medium' );
		$disabled         = ( $is_free && ! $is_medium_option ) ? 'disabled' : '';
		$cursor           = ( $is_free && ! $is_medium_option ) ? 'cursor: not-allowed;' : '';
		?>
		<div class="freshrank-severity-option" style="<?php echo $cursor; ?>">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" id="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?> <?php echo $disabled; ?>>
			<?php if ( $is_free ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $checked ? '1' : '0'; ?>">
			<?php endif; ?>
			<div class="freshrank-severity-label">
				<span class="freshrank-severity-title" style="color: <?php echo esc_attr( $color ); ?>;">
					<?php echo $icon; ?> <?php echo esc_html( $title ); ?>
				</span>
				<span class="freshrank-severity-desc"><?php echo esc_html( $description ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Save filter settings
	 */
	public function save_filter_settings() {
		// Save matrix system - Category filters
		update_option( 'freshrank_fix_factual_updates', isset( $_POST['fix_factual_updates'] ) ? 1 : 0 );
		update_option( 'freshrank_fix_user_experience', isset( $_POST['fix_user_experience'] ) ? 1 : 0 );
		update_option( 'freshrank_fix_search_optimization', isset( $_POST['fix_search_optimization'] ) ? 1 : 0 );
		update_option( 'freshrank_fix_ai_visibility', isset( $_POST['fix_ai_visibility'] ) ? 1 : 0 );
		update_option( 'freshrank_fix_opportunities', isset( $_POST['fix_opportunities'] ) ? 1 : 0 );

		// Save matrix system - Severity filters
		if ( freshrank_is_free_version() ) {
			update_option( 'freshrank_severity_high', 1 );
			update_option( 'freshrank_severity_medium', 1 );
			update_option( 'freshrank_severity_low', 0 );
		} else {
			update_option( 'freshrank_severity_high', isset( $_POST['severity_high'] ) ? 1 : 0 );
			update_option( 'freshrank_severity_medium', isset( $_POST['severity_medium'] ) ? 1 : 0 );
			update_option( 'freshrank_severity_low', isset( $_POST['severity_low'] ) ? 1 : 0 );
		}
	}
}
