<?php
/**
 * Validation Helper Utility Class
 *
 * Provides input validation and sanitization utilities.
 *
 * @package    FreshRank_AI
 * @subpackage FreshRank_AI/includes/utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FreshRank Validation Helper
 *
 * Static utility class for validating and sanitizing user inputs
 * with consistent error handling.
 */
class FreshRank_Validation_Helper {

	/**
	 * Sanitize and validate post ID
	 *
	 * @param mixed $value Value to sanitize
	 *
	 * @return int Valid post ID
	 * @throws Exception If value is not a positive integer
	 *
	 * @example
	 * try {
	 *     $post_id = FreshRank_Validation_Helper::sanitize_post_id($_POST['post_id']);
	 * } catch (Exception $e) {
	 *     FreshRank_AJAX_Response::error($e->getMessage());
	 * }
	 */
	public static function sanitize_post_id( $value ) {
		$post_id = intval( $value );

		if ( $post_id <= 0 ) {
			throw new Exception( __( 'Invalid post ID. Must be a positive integer.', 'freshrank-ai' ) );
		}

		// Verify post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( __( 'Post not found.', 'freshrank-ai' ) );
		}

		return $post_id;
	}

	/**
	 * Sanitize and validate AI model name
	 *
	 * @param mixed $value Value to sanitize
	 *
	 * @return string Valid model name
	 * @throws Exception If value contains invalid characters
	 *
	 * @example
	 * $model = FreshRank_Validation_Helper::sanitize_model_name($_POST['model']);
	 */
	public static function sanitize_model_name( $value ) {
		$model = sanitize_text_field( $value );

		// Allow empty (for when switching providers before models are loaded)
		if ( empty( $model ) ) {
			return '';
		}

		// Allow alphanumeric, dash, dot, slash, and colon (for provider prefixes)
		if ( ! preg_match( '/^[a-zA-Z0-9\-\.\/:]+$/', $model ) ) {
			throw new Exception( __( 'Invalid model name. Only alphanumeric characters, dashes, dots, slashes, and colons are allowed.', 'freshrank-ai' ) );
		}

		if ( strlen( $model ) > 100 ) {
			throw new Exception( __( 'Model name is too long. Maximum 100 characters.', 'freshrank-ai' ) );
		}

		return $model;
	}

	/**
	 * Sanitize and validate API key
	 *
	 * @param mixed $value Value to sanitize
	 *
	 * @return string Valid API key
	 * @throws Exception If value is invalid
	 *
	 * @example
	 * $api_key = FreshRank_Validation_Helper::sanitize_api_key($_POST['api_key']);
	 */
	public static function sanitize_api_key( $value ) {
		$api_key = sanitize_text_field( $value );

		if ( empty( $api_key ) ) {
			throw new Exception( __( 'API key cannot be empty.', 'freshrank-ai' ) );
		}

		// Basic format validation (alphanumeric and common special chars)
		if ( ! preg_match( '/^[a-zA-Z0-9\-_\.]+$/', $api_key ) ) {
			throw new Exception( __( 'Invalid API key format.', 'freshrank-ai' ) );
		}

		if ( strlen( $api_key ) < 10 ) {
			throw new Exception( __( 'API key is too short. Minimum 10 characters.', 'freshrank-ai' ) );
		}

		if ( strlen( $api_key ) > 200 ) {
			throw new Exception( __( 'API key is too long. Maximum 200 characters.', 'freshrank-ai' ) );
		}

		return $api_key;
	}

	/**
	 * Validate and sanitize URL
	 *
	 * @param mixed $url               URL to validate
	 * @param array $allowed_protocols Allowed protocols (default: http, https)
	 *
	 * @return string Valid URL
	 * @throws Exception If URL is invalid
	 *
	 * @example
	 * $url = FreshRank_Validation_Helper::validate_url($_POST['url']);
	 */
	public static function validate_url( $url, $allowed_protocols = array( 'http', 'https' ) ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			throw new Exception( __( 'URL cannot be empty.', 'freshrank-ai' ) );
		}

		// Validate URL format
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new Exception( __( 'Invalid URL format.', 'freshrank-ai' ) );
		}

		// Check protocol
		$protocol = parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $protocol, $allowed_protocols, true ) ) {
			throw new Exception(
				sprintf(
					// translators: %s is the list of allowed protocols
					__( 'Invalid URL protocol. Allowed protocols: %s', 'freshrank-ai' ),
					implode( ', ', $allowed_protocols )
				)
			);
		}

		return $url;
	}

	/**
	 * Validate and sanitize email address
	 *
	 * @param mixed $email Email to validate
	 *
	 * @return string Valid email address
	 * @throws Exception If email is invalid
	 *
	 * @example
	 * $email = FreshRank_Validation_Helper::validate_email($_POST['email']);
	 */
	public static function validate_email( $email ) {
		$email = sanitize_email( $email );

		if ( empty( $email ) ) {
			throw new Exception( __( 'Email address cannot be empty.', 'freshrank-ai' ) );
		}

		if ( ! is_email( $email ) ) {
			throw new Exception( __( 'Invalid email address format.', 'freshrank-ai' ) );
		}

		return $email;
	}

	/**
	 * Validate and sanitize hex color code
	 *
	 * @param mixed $color Color code to validate
	 *
	 * @return string Valid hex color code
	 * @throws Exception If color code is invalid
	 *
	 * @example
	 * $color = FreshRank_Validation_Helper::validate_hex_color($_POST['color']);
	 */
	public static function validate_hex_color( $color ) {
		$color = sanitize_text_field( $color );

		// Remove # if present
		$color = ltrim( $color, '#' );

		// Validate hex format (RGB or RRGGBB)
		if ( ! preg_match( '/^([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
			throw new Exception( __( 'Invalid color format. Must be a valid hex color code (e.g., #FF0000 or #F00).', 'freshrank-ai' ) );
		}

		// Return with # prefix
		return '#' . strtoupper( $color );
	}

	/**
	 * Sanitize array of post IDs
	 *
	 * @param mixed $value Array of values to sanitize
	 *
	 * @return array Array of valid post IDs
	 * @throws Exception If value is not an array
	 *
	 * @example
	 * $post_ids = FreshRank_Validation_Helper::sanitize_array_of_ids($_POST['post_ids']);
	 */
	public static function sanitize_array_of_ids( $value ) {
		if ( ! is_array( $value ) ) {
			throw new Exception( __( 'Value must be an array.', 'freshrank-ai' ) );
		}

		$sanitized = array();
		foreach ( $value as $id ) {
			$id = intval( $id );
			if ( $id > 0 ) {
				$sanitized[] = $id;
			}
		}

		return $sanitized;
	}

	/**
	 * Validate value against allowed list
	 *
	 * @param mixed  $value          Value to validate
	 * @param array  $allowed_values Array of allowed values
	 * @param mixed  $default_value        Default value if validation fails
	 * @param bool   $throw_error    Whether to throw exception on failure
	 *
	 * @return mixed Valid value or default
	 * @throws Exception If value is invalid and $throw_error is true
	 *
	 * @example
	 * $status = FreshRank_Validation_Helper::validate_enum(
	 *     $_POST['status'],
	 *     ['pending', 'completed', 'error'],
	 *     'pending'
	 * );
	 */
	public static function validate_enum( $value, $allowed_values, $default_value, $throw_error = false ) {
		$value = sanitize_text_field( $value );

		if ( in_array( $value, $allowed_values, true ) ) {
			return $value;
		}

		if ( $throw_error ) {
			throw new Exception(
				sprintf(
					// translators: %s is the list of allowed values
					__( 'Invalid value. Allowed values: %s', 'freshrank-ai' ),
					implode( ', ', $allowed_values )
				)
			);
		}

		return $default_value;
	}

	/**
	 * Sanitize and validate integer within range
	 *
	 * @param mixed $value Value to sanitize
	 * @param int   $min   Minimum allowed value
	 * @param int   $max   Maximum allowed value
	 *
	 * @return int Valid integer within range
	 * @throws Exception If value is out of range
	 *
	 * @example
	 * $severity = FreshRank_Validation_Helper::validate_int_range($_POST['severity'], 1, 3);
	 */
	public static function validate_int_range( $value, $min, $max ) {
		$value = intval( $value );

		if ( $value < $min || $value > $max ) {
			throw new Exception(
				sprintf(
					// translators: %1$d is the minimum value, %2$d is the maximum value
					__( 'Value must be between %1$d and %2$d.', 'freshrank-ai' ),
					$min,
					$max
				)
			);
		}

		return $value;
	}

	/**
	 * Sanitize and validate boolean value
	 *
	 * @param mixed $value Value to sanitize
	 *
	 * @return bool Boolean value
	 *
	 * @example
	 * $enabled = FreshRank_Validation_Helper::sanitize_boolean($_POST['enabled']);
	 */
	public static function sanitize_boolean( $value ) {
		// Handle various boolean representations
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}

		// Use PHP's boolean conversion
		return (bool) $value;
	}

	/**
	 * Sanitize and validate JSON string
	 *
	 * @param mixed $value JSON string to validate
	 *
	 * @return array Decoded JSON as associative array
	 * @throws Exception If JSON is invalid
	 *
	 * @example
	 * $data = FreshRank_Validation_Helper::validate_json($_POST['data']);
	 */
	public static function validate_json( $value ) {
		if ( empty( $value ) ) {
			throw new Exception( __( 'JSON data cannot be empty.', 'freshrank-ai' ) );
		}

		$decoded = json_decode( $value, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception(
				sprintf(
					// translators: %s is the error message
					__( 'Invalid JSON: %s', 'freshrank-ai' ),
					json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize text field with length validation
	 *
	 * @param mixed $value     Value to sanitize
	 * @param int   $min_length Minimum length (default: 0)
	 * @param int   $max_length Maximum length (default: 255)
	 *
	 * @return string Sanitized text
	 * @throws Exception If length is invalid
	 *
	 * @example
	 * $name = FreshRank_Validation_Helper::sanitize_text($_POST['name'], 3, 50);
	 */
	public static function sanitize_text( $value, $min_length = 0, $max_length = 255 ) {
		$value  = sanitize_text_field( $value );
		$length = strlen( $value );

		if ( $length < $min_length ) {
			throw new Exception(
				sprintf(
					// translators: %d is the number of characters
					__( 'Text must be at least %d characters long.', 'freshrank-ai' ),
					$min_length
				)
			);
		}

		if ( $length > $max_length ) {
			throw new Exception(
				sprintf(
					// translators: %d is the number of characters
					__( 'Text must not exceed %d characters.', 'freshrank-ai' ),
					$max_length
				)
			);
		}

		return $value;
	}

	/**
	 * Sanitize textarea with length validation
	 *
	 * @param mixed $value     Value to sanitize
	 * @param int   $min_length Minimum length (default: 0)
	 * @param int   $max_length Maximum length (default: 10000)
	 *
	 * @return string Sanitized textarea content
	 * @throws Exception If length is invalid
	 *
	 * @example
	 * $content = FreshRank_Validation_Helper::sanitize_textarea($_POST['content'], 10, 5000);
	 */
	public static function sanitize_textarea( $value, $min_length = 0, $max_length = 10000 ) {
		$value  = sanitize_textarea_field( $value );
		$length = strlen( $value );

		if ( $length < $min_length ) {
			throw new Exception(
				sprintf(
					// translators: %d is the number of characters
					__( 'Content must be at least %d characters long.', 'freshrank-ai' ),
					$min_length
				)
			);
		}

		if ( $length > $max_length ) {
			throw new Exception(
				sprintf(
					// translators: %d is the number of characters
					__( 'Content must not exceed %d characters.', 'freshrank-ai' ),
					$max_length
				)
			);
		}

		return $value;
	}
}
