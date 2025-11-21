<?php
/**
 * Dashboard Filters
 * Handles filter rendering (author, category, period, search)
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Dashboard_Filters {

	private static $instance = null;

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
	 * Render filters form
	 */
	public function render_filters() {
		$author_id   = isset( $_GET['author_filter'] ) ? intval( $_GET['author_filter'] ) : 0;
		$category_id = isset( $_GET['category_filter'] ) ? intval( $_GET['category_filter'] ) : 0;
		$period      = isset( $_GET['period'] ) ? sanitize_text_field( $_GET['period'] ) : '';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

		?>
		<div class="freshrank-filters">
			<form method="get" id="freshrank-filter-form">
				<input type="hidden" name="page" value="freshrank-ai">

				<div class="filter-left">
					<?php
					$this->render_author_filter( $author_id );
					$this->render_category_filter( $category_id );
					// $this->render_period_filter( $period );
					?>
				</div>

				<div class="filter-right">
					<?php $this->render_search_box( $search ); ?>

					<button type="submit" class="button">
						<?php _e( 'Filter', 'freshrank-ai' ); ?>
					</button>

					<button type="button" class="button" id="freshrank-reset-filters">
						<?php _e( 'Reset', 'freshrank-ai' ); ?>
					</button>
				</div>
			</form>
		</div>

		<?php $this->render_active_filters( $author_id, $category_id, $period, $search ); ?>
		<?php
	}

	/**
	 * Render author filter dropdown
	 */
	private function render_author_filter( $selected ) {
		$authors = get_transient( 'freshrank_filter_authors' );
		if ( false === $authors ) {
			$authors = get_users(
				array(
					'capability' => 'edit_posts', // Replaces deprecated 'who' => 'authors' (WP 5.9+)
					'orderby'    => 'display_name',
					'order'      => 'ASC',
				)
			);
			set_transient( 'freshrank_filter_authors', $authors, HOUR_IN_SECONDS );
		}

		?>
		<label for="author-filter"><?php _e( 'Author:', 'freshrank-ai' ); ?></label>
		<select name="author_filter" id="author-filter">
			<option value="0"><?php _e( 'All Authors', 'freshrank-ai' ); ?></option>
			<?php foreach ( $authors as $author ) : ?>
				<option value="<?php echo esc_attr( $author->ID ); ?>"
						<?php selected( $selected, $author->ID ); ?>>
					<?php printf( '%s (%d)', esc_html( $author->display_name ), count_user_posts( $author->ID, 'post' ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render category filter dropdown
	 */
	private function render_category_filter( $selected ) {
		?>
		<label for="category-filter"><?php _e( 'Category:', 'freshrank-ai' ); ?></label>
		<?php
		wp_dropdown_categories(
			array(
				'name'            => 'category_filter',
				'id'              => 'category-filter',
				'show_option_all' => __( 'All Categories', 'freshrank-ai' ),
				'show_count'      => true,
				'hide_empty'      => false,
				'hierarchical'    => true,
				'selected'        => $selected,
				'orderby'         => 'name',
				'taxonomy'        => 'category',
				'value_field'     => 'term_id',
			)
		);
	}

	/**
	 * Render period filter dropdown
	 */
	private function render_period_filter( $selected ) {
		$periods = array(
			''        => __( 'All Time', 'freshrank-ai' ),
			'today'   => __( 'Today', 'freshrank-ai' ),
			'week'    => __( 'Last 7 Days', 'freshrank-ai' ),
			'month'   => __( 'Last 30 Days', 'freshrank-ai' ),
			'quarter' => __( 'Last 90 Days', 'freshrank-ai' ),
			'year'    => __( 'Last Year', 'freshrank-ai' ),
		);

		?>
		<label for="period-filter"><?php _e( 'Period:', 'freshrank-ai' ); ?></label>
		<select name="period" id="period-filter">
			<?php foreach ( $periods as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $selected, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render search box
	 */
	private function render_search_box( $search_query ) {
		?>
		<label class="screen-reader-text" for="post-search-input">
			<?php _e( 'Search Articles:', 'freshrank-ai' ); ?>
		</label>
		<input type="search"
				id="post-search-input"
				name="s"
				value="<?php echo esc_attr( $search_query ); ?>"
				placeholder="<?php esc_attr_e( 'Search articles...', 'freshrank-ai' ); ?>">
		<?php
	}

	/**
	 * Render active filters display
	 */
	private function render_active_filters( $author_id, $category_id, $period, $search ) {
		if ( ! $author_id && ! $category_id && ! $period && ! $search ) {
			return;
		}

		$filters = array();

		if ( $author_id ) {
			$author = get_userdata( $author_id );
			if ( $author ) {
				// translators: %s is the author name
				$filters[] = sprintf( __( 'Author: %s', 'freshrank-ai' ), $author->display_name );
			}
		}

		if ( $category_id ) {
			$category = get_category( $category_id );
			if ( $category ) {
				// translators: %s is the category name
				$filters[] = sprintf( __( 'Category: %s', 'freshrank-ai' ), $category->name );
			}
		}

		if ( $period ) {
			$period_labels = array(
				'today'   => __( 'Today', 'freshrank-ai' ),
				'week'    => __( 'Last 7 Days', 'freshrank-ai' ),
				'month'   => __( 'Last 30 Days', 'freshrank-ai' ),
				'quarter' => __( 'Last 90 Days', 'freshrank-ai' ),
				'year'    => __( 'Last Year', 'freshrank-ai' ),
			);
			// translators: %s is the period label
			$filters[] = sprintf( __( 'Period: %s', 'freshrank-ai' ), $period_labels[ $period ] );
		}

		if ( $search ) {
			// translators: %s is the search query
			$filters[] = sprintf( __( 'Search: "%s"', 'freshrank-ai' ), esc_html( $search ) );
		}

		?>
		<div class="freshrank-active-filters">
			<strong><?php _e( 'Filtering by:', 'freshrank-ai' ); ?></strong>
			<?php echo implode( ' | ', $filters ); ?>
			<a href="<?php echo admin_url( 'admin.php?page=freshrank-ai' ); ?>">
				<?php _e( 'Clear all', 'freshrank-ai' ); ?>
			</a>
		</div>
		<?php
	}
}
