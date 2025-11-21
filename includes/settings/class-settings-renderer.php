<?php
/**
 * Settings Renderer - Reusable form components
 * Provides common HTML rendering methods for settings pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Settings_Renderer {

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
	 * Render text input field
	 *
	 * @param string $name Field name
	 * @param string $value Current value
	 * @param array $args Additional arguments (label, description, placeholder, type, class)
	 */
	public function render_text_field( $name, $value, $args = array() ) {
		$defaults = array(
			'label'       => '',
			'description' => '',
			'placeholder' => '',
			'type'        => 'text',
			'class'       => 'regular-text',
			'readonly'    => false,
			'disabled'    => false,
		);
		$args     = wp_parse_args( $args, $defaults );

		$id = sanitize_key( $name );

		?>
		<div class="freshrank-field">
			<?php if ( $args['label'] ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $args['label'] ); ?>
				</label>
			<?php endif; ?>

			<input type="<?php echo esc_attr( $args['type'] ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="<?php echo esc_attr( $args['class'] ); ?>"
					<?php
					if ( $args['placeholder'] ) :
						?>
						placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"<?php endif; ?>
					<?php
					if ( $args['readonly'] ) :
						?>
						readonly <?php endif; ?>
					<?php
					if ( $args['disabled'] ) :
						?>
						disabled<?php endif; ?> />

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo $args['description']; ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render textarea field
	 *
	 * @param string $name Field name
	 * @param string $value Current value
	 * @param array $args Additional arguments (label, description, placeholder, rows, class, maxlength)
	 */
	public function render_textarea( $name, $value, $args = array() ) {
		$defaults = array(
			'label'       => '',
			'description' => '',
			'placeholder' => '',
			'rows'        => 4,
			'class'       => 'large-text',
			'maxlength'   => '',
			'counter_id'  => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$id = sanitize_key( $name );

		?>
		<div class="freshrank-field">
			<?php if ( $args['label'] ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $args['label'] ); ?>
				</label>
			<?php endif; ?>

			<textarea id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						rows="<?php echo esc_attr( $args['rows'] ); ?>"
						class="<?php echo esc_attr( $args['class'] ); ?>"
						<?php
						if ( $args['placeholder'] ) :
							?>
							placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"<?php endif; ?>
						<?php
						if ( $args['maxlength'] ) :
							?>
							maxlength="<?php echo esc_attr( $args['maxlength'] ); ?>"<?php endif; ?>><?php echo esc_textarea( $value ); ?></textarea>

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo $args['description']; ?></p>
			<?php endif; ?>

			<?php if ( $args['counter_id'] ) : ?>
				<span id="<?php echo esc_attr( $args['counter_id'] ); ?>" style="color: #666; font-size: 12px;">
					0/<?php echo esc_html( $args['maxlength'] ); ?> <?php _e( 'characters', 'freshrank-ai' ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render checkbox field
	 *
	 * @param string $name Field name
	 * @param bool $checked Current checked state
	 * @param array $args Additional arguments (label, description)
	 */
	public function render_checkbox( $name, $checked, $args = array() ) {
		$defaults = array(
			'label'       => '',
			'description' => '',
			'disabled'    => false,
		);
		$args     = wp_parse_args( $args, $defaults );

		$id = sanitize_key( $name );

		?>
		<div class="freshrank-field">
			<label>
				<input type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="1"
						<?php checked( $checked ); ?>
						<?php
						if ( $args['disabled'] ) :
							?>
							disabled<?php endif; ?> />
				<?php if ( $args['label'] ) : ?>
					<strong><?php echo esc_html( $args['label'] ); ?></strong>
				<?php endif; ?>
			</label>

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo $args['description']; ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render select dropdown
	 *
	 * @param string $name Field name
	 * @param string $value Current value
	 * @param array $options Associative array of options (value => label)
	 * @param array $args Additional arguments (label, description, class)
	 */
	public function render_select( $name, $value, $options, $args = array() ) {
		$defaults = array(
			'label'       => '',
			'description' => '',
			'class'       => 'regular-text',
			'disabled'    => false,
		);
		$args     = wp_parse_args( $args, $defaults );

		$id = sanitize_key( $name );

		?>
		<div class="freshrank-field">
			<?php if ( $args['label'] ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $args['label'] ); ?>
				</label>
			<?php endif; ?>

			<select id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="<?php echo esc_attr( $args['class'] ); ?>"
					<?php
					if ( $args['disabled'] ) :
						?>
						disabled<?php endif; ?>>
				<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
						<?php echo esc_html( $option_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo $args['description']; ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render radio button group
	 *
	 * @param string $name Field name
	 * @param string $value Current value
	 * @param array $options Associative array of options (value => label)
	 * @param array $args Additional arguments (label, description)
	 */
	public function render_radio_group( $name, $value, $options, $args = array() ) {
		$defaults = array(
			'label'       => '',
			'description' => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		?>
		<div class="freshrank-field">
			<?php if ( $args['label'] ) : ?>
				<label><?php echo esc_html( $args['label'] ); ?></label>
			<?php endif; ?>

			<?php foreach ( $options as $option_value => $option_label ) : ?>
				<label style="display: block; margin: 10px 0;">
					<input type="radio"
							name="<?php echo esc_attr( $name ); ?>"
							value="<?php echo esc_attr( $option_value ); ?>"
							<?php checked( $value, $option_value ); ?> />
					<?php echo esc_html( $option_label ); ?>
				</label>
			<?php endforeach; ?>

			<?php if ( $args['description'] ) : ?>
				<p class="description"><?php echo $args['description']; ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render section header
	 *
	 * @param string $title Section title
	 * @param string $icon Dashicon name (without 'dashicons-' prefix)
	 */
	public function render_section_header( $title, $icon = '' ) {
		?>
		<h3>
			<?php if ( $icon ) : ?>
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
			<?php endif; ?>
			<?php echo esc_html( $title ); ?>
		</h3>
		<?php
	}

	/**
	 * Render info box
	 *
	 * @param string $content Info content (can contain HTML)
	 * @param string $type Type of info box (info, success, warning, error)
	 */
	public function render_info_box( $content, $type = 'info' ) {
		$colors = array(
			'info'    => array(
				'border' => '#0073aa',
				'bg'     => '#f0f6fc',
			),
			'success' => array(
				'border' => '#00a32a',
				'bg'     => '#e7f7ec',
			),
			'warning' => array(
				'border' => '#f0b849',
				'bg'     => '#fff9e6',
			),
			'error'   => array(
				'border' => '#d63638',
				'bg'     => '#fce8e9',
			),
		);

		$color = isset( $colors[ $type ] ) ? $colors[ $type ] : $colors['info'];

		?>
		<div style="background: <?php echo esc_attr( $color['bg'] ); ?>; padding: 15px; border-left: 4px solid <?php echo esc_attr( $color['border'] ); ?>; margin: 20px 0; border-radius: 4px;">
			<?php echo $content; ?>
		</div>
		<?php
	}

	/**
	 * Render submit button
	 *
	 * @param string $text Button text
	 * @param array $args Additional arguments (class, id)
	 */
	public function render_submit_button( $text = null, $args = array() ) {
		if ( $text === null ) {
			$text = __( 'Save Changes', 'freshrank-ai' );
		}

		$defaults = array(
			'class' => 'button button-primary',
			'id'    => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		?>
		<p class="submit">
			<button type="submit"
					class="<?php echo esc_attr( $args['class'] ); ?>"
					<?php
					if ( $args['id'] ) :
						?>
						id="<?php echo esc_attr( $args['id'] ); ?>"<?php endif; ?>>
				<?php echo esc_html( $text ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Render card container
	 *
	 * @param string $title Card title
	 * @param string $content Card content (HTML)
	 * @param string $icon Dashicon name (without 'dashicons-' prefix)
	 */
	public function render_card( $title, $content, $icon = '' ) {
		?>
		<div class="freshrank-card">
			<h3>
				<?php if ( $icon ) : ?>
					<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
				<?php endif; ?>
				<?php echo esc_html( $title ); ?>
			</h3>
			<div class="freshrank-card-body">
				<?php echo $content; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render status badge
	 *
	 * @param string $text Badge text
	 * @param string $status Status type (connected, disconnected, pending, error)
	 */
	public function render_status_badge( $text, $status = 'disconnected' ) {
		$classes = array(
			'connected'    => 'freshrank-status-connected',
			'disconnected' => 'freshrank-status-disconnected',
			'pending'      => 'freshrank-status-pending',
			'error'        => 'freshrank-status-error',
		);

		$class = isset( $classes[ $status ] ) ? $classes[ $status ] : $classes['disconnected'];

		?>
		<span class="freshrank-status-badge <?php echo esc_attr( $class ); ?>">
			<?php echo esc_html( $text ); ?>
		</span>
		<?php
	}
}
