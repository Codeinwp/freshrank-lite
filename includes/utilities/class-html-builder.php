<?php
/**
 * HTML Builder Utility Class
 *
 * Provides reusable HTML component builders to reduce code duplication.
 *
 * @package    FreshRank_AI
 * @subpackage FreshRank_AI/includes/utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FreshRank HTML Builder
 *
 * Static utility class for building common HTML components with proper
 * escaping and consistent styling.
 */
class FreshRank_HTML_Builder {

	/**
	 * Render a button element
	 *
	 * @param string $text       Button text
	 * @param string $classes    CSS classes (space-separated)
	 * @param array  $attributes Additional HTML attributes
	 *
	 * @return string HTML button element
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::button('Analyze', 'button-primary', ['data-post-id' => 123]);
	 */
	public static function button( $text, $classes = '', $attributes = array() ) {
		$attr_string = '';
		foreach ( $attributes as $key => $value ) {
			$attr_string .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}

		$class_attr = ! empty( $classes ) ? ' class="' . esc_attr( $classes ) . '"' : '';

		return sprintf(
			'<button%s%s>%s</button>',
			$class_attr,
			$attr_string,
			esc_html( $text )
		);
	}

	/**
	 * Render a colored badge
	 *
	 * @param string $text  Badge text
	 * @param string $color Badge color (gray, red, green, blue, yellow, orange)
	 * @param string $icon  Optional dashicon name
	 *
	 * @return string HTML badge element
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::badge('New', 'green', 'dashicons-star-filled');
	 */
	public static function badge( $text, $color = 'gray', $icon = '' ) {
		$color_classes = array(
			'gray'   => 'freshrank-badge-gray',
			'red'    => 'freshrank-badge-red',
			'green'  => 'freshrank-badge-green',
			'blue'   => 'freshrank-badge-blue',
			'yellow' => 'freshrank-badge-yellow',
			'orange' => 'freshrank-badge-orange',
		);

		$color_class = isset( $color_classes[ $color ] ) ? $color_classes[ $color ] : $color_classes['gray'];

		$icon_html = '';
		if ( ! empty( $icon ) ) {
			$icon_html = '<span class="dashicons ' . esc_attr( $icon ) . '" style="font-size: 14px; line-height: 1;"></span> ';
		}

		return sprintf(
			'<span class="freshrank-badge %s">%s%s</span>',
			esc_attr( $color_class ),
			$icon_html,
			esc_html( $text )
		);
	}

	/**
	 * Render an element with tooltip
	 *
	 * @param string $content      Main content
	 * @param string $tooltip_text Tooltip text
	 *
	 * @return string HTML with tooltip
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::tooltip('Priority Score', 'Calculated from content age and traffic data');
	 */
	public static function tooltip( $content, $tooltip_text ) {
		return sprintf(
			'<span class="freshrank-tooltip" data-tooltip="%s">%s <span class="dashicons dashicons-info" style="font-size: 14px; color: #999;"></span></span>',
			esc_attr( $tooltip_text ),
			$content
		);
	}

	/**
	 * Render a progress bar
	 *
	 * @param int    $percentage Progress percentage (0-100)
	 * @param string $label      Optional label
	 * @param string $color      Bar color (blue, green, yellow, red)
	 *
	 * @return string HTML progress bar
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::progress_bar(75, 'Completion', 'green');
	 */
	public static function progress_bar( $percentage, $label = '', $color = 'blue' ) {
		$percentage = max( 0, min( 100, intval( $percentage ) ) );

		$color_classes = array(
			'blue'   => '#2271b1',
			'green'  => '#00a32a',
			'yellow' => '#dba617',
			'red'    => '#d63638',
		);

		$bar_color = isset( $color_classes[ $color ] ) ? $color_classes[ $color ] : $color_classes['blue'];

		$label_html = '';
		if ( ! empty( $label ) ) {
			$label_html = '<div class="freshrank-progress-label" style="margin-bottom: 4px; font-size: 12px; color: #666;">' . esc_html( $label ) . ' (' . $percentage . '%)</div>';
		}

		return sprintf(
			'%s<div class="freshrank-progress-bar" style="width: 100%%; height: 20px; background: #f0f0f1; border-radius: 4px; overflow: hidden;">
                <div class="freshrank-progress-fill" style="width: %d%%; height: 100%%; background: %s; transition: width 0.3s ease;"></div>
            </div>',
			$label_html,
			$percentage,
			esc_attr( $bar_color )
		);
	}

	/**
	 * Render a card container
	 *
	 * @param string $title   Card title
	 * @param string $content Card body content
	 * @param string $footer  Optional footer content
	 * @param string $classes Additional CSS classes
	 *
	 * @return string HTML card element
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::card('Statistics', '<p>Content here</p>', '<button>Action</button>');
	 */
	public static function card( $title, $content, $footer = '', $classes = '' ) {
		$card_classes = 'freshrank-card' . ( ! empty( $classes ) ? ' ' . esc_attr( $classes ) : '' );

		$footer_html = '';
		if ( ! empty( $footer ) ) {
			$footer_html = '<div class="freshrank-card-footer" style="padding: 15px; border-top: 1px solid #dcdcde; background: #f6f7f7;">' . $footer . '</div>';
		}

		return sprintf(
			'<div class="%s" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
                <div class="freshrank-card-header" style="padding: 15px; border-bottom: 1px solid #dcdcde;">
                    <h3 style="margin: 0; font-size: 14px; font-weight: 600;">%s</h3>
                </div>
                <div class="freshrank-card-body" style="padding: 15px;">
                    %s
                </div>
                %s
            </div>',
			esc_attr( $card_classes ),
			esc_html( $title ),
			$content,
			$footer_html
		);
	}

	/**
	 * Render a table
	 *
	 * @param array  $headers Table headers (array of strings)
	 * @param array  $rows    Table rows (array of arrays)
	 * @param string $classes Additional CSS classes
	 *
	 * @return string HTML table element
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::table(
	 *     ['Name', 'Value'],
	 *     [['John', '100'], ['Jane', '200']],
	 *     'widefat'
	 * );
	 */
	public static function table( $headers, $rows, $classes = '' ) {
		$table_classes = 'freshrank-table' . ( ! empty( $classes ) ? ' ' . esc_attr( $classes ) : '' );

		$header_html = '<tr>';
		foreach ( $headers as $header ) {
			$header_html .= '<th style="text-align: left; padding: 8px; border-bottom: 1px solid #dcdcde;">' . esc_html( $header ) . '</th>';
		}
		$header_html .= '</tr>';

		$rows_html = '';
		foreach ( $rows as $row ) {
			$rows_html .= '<tr>';
			foreach ( $row as $cell ) {
				$rows_html .= '<td style="padding: 8px; border-bottom: 1px solid #f0f0f1;">' . wp_kses_post( $cell ) . '</td>';
			}
			$rows_html .= '</tr>';
		}

		return sprintf(
			'<table class="%s" style="width: 100%%; border-collapse: collapse;">
                <thead>%s</thead>
                <tbody>%s</tbody>
            </table>',
			esc_attr( $table_classes ),
			$header_html,
			$rows_html
		);
	}

	/**
	 * Render a form field
	 *
	 * @param string $type  Field type (text, textarea, select, checkbox, radio)
	 * @param string $name  Field name attribute
	 * @param mixed  $value Field value
	 * @param string $label Field label
	 * @param array  $args  Additional arguments (placeholder, options, description, etc.)
	 *
	 * @return string HTML form field
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::form_field(
	 *     'select',
	 *     'model',
	 *     'gpt-4o',
	 *     'AI Model',
	 *     ['options' => ['gpt-4o' => 'GPT-4', 'gpt-5' => 'GPT-5']]
	 * );
	 */
	public static function form_field( $type, $name, $value, $label, $args = array() ) {
		$field_id    = 'field_' . sanitize_key( $name );
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$required    = isset( $args['required'] ) && $args['required'] ? ' required' : '';

		$label_html = sprintf(
			'<label for="%s" style="display: block; margin-bottom: 5px; font-weight: 600;">%s%s</label>',
			esc_attr( $field_id ),
			esc_html( $label ),
			$required ? ' <span style="color: #d63638;">*</span>' : ''
		);

		$field_html = '';

		switch ( $type ) {
			case 'text':
			case 'email':
			case 'url':
			case 'number':
				$field_html = sprintf(
					'<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text"%s />',
					esc_attr( $type ),
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $placeholder ),
					$required
				);
				break;

			case 'textarea':
				$rows       = isset( $args['rows'] ) ? intval( $args['rows'] ) : 5;
				$field_html = sprintf(
					'<textarea id="%s" name="%s" rows="%d" class="large-text" placeholder="%s"%s>%s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					$rows,
					esc_attr( $placeholder ),
					$required,
					esc_textarea( $value )
				);
				break;

			case 'select':
				$options      = isset( $args['options'] ) ? $args['options'] : array();
				$options_html = '';
				foreach ( $options as $option_value => $option_label ) {
					$selected      = selected( $value, $option_value, false );
					$options_html .= sprintf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $option_value ),
						$selected,
						esc_html( $option_label )
					);
				}
				$field_html = sprintf(
					'<select id="%s" name="%s"%s>%s</select>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					$required,
					$options_html
				);
				break;

			case 'checkbox':
				$checked    = checked( $value, true, false );
				$field_html = sprintf(
					'<label><input type="checkbox" id="%s" name="%s" value="1"%s%s /> %s</label>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					$checked,
					$required,
					esc_html( $label )
				);
				$label_html = ''; // Label is inline with checkbox
				break;

			case 'radio':
				$options     = isset( $args['options'] ) ? $args['options'] : array();
				$radios_html = '';
				foreach ( $options as $option_value => $option_label ) {
					$checked      = checked( $value, $option_value, false );
					$radios_html .= sprintf(
						'<label style="display: block; margin-bottom: 8px;"><input type="radio" name="%s" value="%s"%s%s /> %s</label>',
						esc_attr( $name ),
						esc_attr( $option_value ),
						$checked,
						$required,
						esc_html( $option_label )
					);
				}
				$field_html = $radios_html;
				break;
		}

		$description_html = '';
		if ( ! empty( $description ) ) {
			$description_html = '<p class="description" style="margin-top: 5px; color: #646970; font-size: 13px;">' . esc_html( $description ) . '</p>';
		}

		return sprintf(
			'<div class="freshrank-form-field" style="margin-bottom: 20px;">
                %s
                %s
                %s
            </div>',
			$label_html,
			$field_html,
			$description_html
		);
	}

	/**
	 * Render an icon
	 *
	 * @param string $name Dashicon name (without 'dashicons-' prefix)
	 * @param string $size Icon size (small, medium, large)
	 *
	 * @return string HTML icon element
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::icon('admin-post', 'medium');
	 */
	public static function icon( $name, $size = 'medium' ) {
		$sizes = array(
			'small'  => '16px',
			'medium' => '20px',
			'large'  => '24px',
		);

		$icon_size = isset( $sizes[ $size ] ) ? $sizes[ $size ] : $sizes['medium'];

		return sprintf(
			'<span class="dashicons dashicons-%s" style="font-size: %s; width: %s; height: %s;"></span>',
			esc_attr( $name ),
			esc_attr( $icon_size ),
			esc_attr( $icon_size ),
			esc_attr( $icon_size )
		);
	}

	/**
	 * Render a status indicator
	 *
	 * @param string $status Status type (pending, processing, completed, error)
	 * @param string $label  Optional status label
	 *
	 * @return string HTML status indicator
	 *
	 * @example
	 * echo FreshRank_HTML_Builder::status_indicator('completed', 'Done');
	 */
	public static function status_indicator( $status, $label = '' ) {
		$status_colors = array(
			'pending'    => '#dba617',
			'processing' => '#2271b1',
			'completed'  => '#00a32a',
			'error'      => '#d63638',
		);

		$status_labels = array(
			'pending'    => __( 'Pending', 'freshrank-ai' ),
			'processing' => __( 'Processing', 'freshrank-ai' ),
			'completed'  => __( 'Completed', 'freshrank-ai' ),
			'error'      => __( 'Error', 'freshrank-ai' ),
		);

		$color      = isset( $status_colors[ $status ] ) ? $status_colors[ $status ] : $status_colors['pending'];
		$label_text = ! empty( $label ) ? $label : ( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status ) );

		return sprintf(
			'<span class="freshrank-status-indicator" style="display: inline-flex; align-items: center; gap: 6px;">
                <span class="status-dot" style="width: 10px; height: 10px; border-radius: 50%%; background: %s;"></span>
                <span class="status-label" style="font-size: 13px; color: #2c3338;">%s</span>
            </span>',
			esc_attr( $color ),
			esc_html( $label_text )
		);
	}
}
