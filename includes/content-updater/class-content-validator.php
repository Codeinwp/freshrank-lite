<?php
/**
 * Content Validator for FreshRank AI
 * Validates and parses AI responses, repairs malformed JSON
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Content_Validator {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return FreshRank_Content_Validator
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
		// Singleton
	}

	/**
	 * Parse update result from AI
	 * Attempts multiple strategies to extract valid JSON
	 *
	 * @param string $result Raw AI response
	 * @return array Parsed content array
	 * @throws Exception If parsing fails
	 */
	public function parse_update_result( $result ) {
		if ( get_option( 'freshrank_debug_mode', 0 ) ) {
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = wp_tempnam( 'freshrank-json' );
			if ( $temp_file && is_writable( $temp_file ) ) {
				file_put_contents( $temp_file, $result );
				freshrank_debug_log( 'Saved JSON sample to ' . $temp_file );
			}
		}

		// Strip markdown code fences (some models like Gemini wrap JSON in ```json...```)
		$result = preg_replace( '/^```(?:json)?\s*\n?/m', '', $result );
		$result = preg_replace( '/\n?```\s*$/m', '', $result );

		// Trim trailing whitespace to avoid false truncated detection
		$result = rtrim( $result );

		if ( $result === '' ) {
			throw new Exception( 'AI response was empty after trimming whitespace.' );
		}

		// Check if JSON is complete
		$open_braces  = substr_count( $result, '{' );
		$close_braces = substr_count( $result, '}' );

		if ( $open_braces !== $close_braces ) {
			freshrank_debug_log( 'JSON IS INCOMPLETE - mismatched braces' );
			freshrank_debug_log( 'Last 500 chars of response: ' . substr( $result, -500 ) );
			throw new Exception( 'AI response was incomplete or truncated. The response may be too long for the current model. Try using a model with higher token limits or reduce the content length.' );
		}

		// Check for control characters BEFORE cleaning
		$has_control_chars = preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result );

		// Check if response ends abruptly
		$last_char = substr( $result, -1 );

		if ( $last_char !== '}' ) {
			freshrank_debug_log( 'Response does not end with closing brace - truncated' );
			freshrank_debug_log( 'Last 500 chars: ' . substr( $result, -500 ) );
			throw new Exception( 'AI response was truncated. Try using a model with higher token limits or reduce the content complexity.' );
		}

		// More aggressive cleaning
		// Remove ALL control characters except \n (0x0A) and \t (0x09)
		$cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $result );

		// Also try to fix common JSON issues
		$cleaned = preg_replace( '/[\x{FEFF}]/u', '', $cleaned ); // Remove BOM
		$cleaned = trim( $cleaned );

		// Strategy 1: Try direct JSON decode on cleaned string
		$parsed = json_decode( $cleaned, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
			return $parsed;
		}
		freshrank_debug_log( 'Strategy 1 failed: ' . json_last_error_msg() );

		// Try on original too
		$parsed = json_decode( $result, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
			return $parsed;
		}

		// Strategy 2: Extract JSON between first { and last }
		$json_start = strpos( $result, '{' );
		$json_end   = strrpos( $result, '}' );

		if ( $json_start !== false && $json_end !== false ) {
			$json_string = substr( $result, $json_start, $json_end - $json_start + 1 );

			// Clean the extracted JSON
			$json_string = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $json_string );

			$parsed = json_decode( $json_string, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				return $parsed;
			}
			freshrank_debug_log( 'Strategy 2 failed: ' . json_last_error_msg() );
		}

		// Strategy 3: Try to find JSON in markdown code blocks
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $result, $matches ) ) {
			$parsed = json_decode( $matches[1], true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				return $parsed;
			}
		}

		// Strategy 4: Try to repair JSON with bracket matching
		if ( $json_start !== false ) {
			$repaired = $this->attempt_json_repair( substr( $result, $json_start ) );
			if ( $repaired !== null ) {
				return $repaired;
			}
		}

		// All strategies failed - provide detailed error
		$error_msg = 'Failed to parse AI response. JSON error: ' . json_last_error_msg();

		// Add specific guidance based on the error
		if ( json_last_error() === JSON_ERROR_CTRL_CHAR ) {
			$error_msg .= ' The response contains invalid control characters. This usually happens when the AI output is corrupted or truncated.';
		} elseif ( $open_braces > $close_braces ) {
			$error_msg .= ' The response is incomplete (unclosed JSON). Try reducing content complexity or using a model with higher token limits.';
		}

		if ( get_option( 'freshrank_debug_mode', 0 ) ) {
			freshrank_debug_log( 'All JSON parsing strategies failed.' );
			freshrank_debug_log( 'First 500 chars: ' . substr( $result, 0, 500 ) );
			freshrank_debug_log( 'Last 500 chars: ' . substr( $result, -500 ) );
		}

		throw new Exception( $error_msg );
	}

	/**
	 * Attempt to repair malformed JSON
	 * Removes text before/after JSON, cleans control chars, adds missing brackets
	 *
	 * @param string $json_string Potentially malformed JSON
	 * @return array|null Parsed array on success, null on failure
	 */
	public function attempt_json_repair( $json_string ) {
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

		// Clean control characters more aggressively
		// Keep only: \n (newline), \t (tab), \r (carriage return) which are valid in JSON strings
		$json_string = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $json_string );

		// Try to decode
		$decoded = json_decode( $json_string, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $decoded;
		}

		freshrank_debug_log( 'JSON repair - decode error: ' . json_last_error_msg() );

		// Try to fix unclosed arrays/objects by adding closing brackets
		$open_braces   = substr_count( $json_string, '{' ) - substr_count( $json_string, '}' );
		$open_brackets = substr_count( $json_string, '[' ) - substr_count( $json_string, ']' );

		if ( $open_braces > 0 || $open_brackets > 0 ) {
			freshrank_debug_log( 'JSON repair - attempting to close ' . $open_braces . ' braces and ' . $open_brackets . ' brackets' );
			$json_string .= str_repeat( ']', $open_brackets ) . str_repeat( '}', $open_braces );
			$decoded      = json_decode( $json_string, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Validate and sanitize updated content
	 * Ensures all fields are safe and properly formatted
	 *
	 * @param array $updated_content Content from AI
	 * @param array $original_content Original content as fallback
	 * @return array Validated content array
	 */
	public function validate_updated_content( $updated_content, $original_content ) {
		$validated = array();

		// Validate title
		$validated['title'] = ! empty( $updated_content['title'] )
			? sanitize_text_field( $updated_content['title'] )
			: $original_content['title'];

		// Validate meta title (let SEO plugin handle length limits)
		$validated['meta_title'] = ! empty( $updated_content['meta_title'] )
			? sanitize_text_field( $updated_content['meta_title'] )
			: $original_content['meta_title'];

		// Validate meta description (let SEO plugin handle length limits)
		$validated['meta_description'] = ! empty( $updated_content['meta_description'] )
			? sanitize_text_field( $updated_content['meta_description'] )
			: $original_content['meta_description'];

		// Validate excerpt and remove unwanted UTM parameters
		if ( ! empty( $updated_content['excerpt'] ) ) {
			$excerpt = wp_kses_post( $updated_content['excerpt'] );
			// Remove AI-generated UTM tracking parameters
			$excerpt              = $this->remove_utm_parameters( $excerpt );
			$validated['excerpt'] = $excerpt;
		} else {
			$validated['excerpt'] = $original_content['excerpt'];
		}

		// Validate content and remove unwanted UTM parameters
		if ( ! empty( $updated_content['content'] ) ) {
			$content = wp_kses_post( $updated_content['content'] );
			// Remove AI-generated UTM tracking parameters
			$content              = $this->remove_utm_parameters( $content );
			$validated['content'] = $content;
		} else {
			$validated['content'] = $original_content['content'];
		}

		// Store additional information for review
		$validated['changes_made']               = isset( $updated_content['changes_made'] ) ? $updated_content['changes_made'] : array();
		$validated['seo_improvements']           = isset( $updated_content['seo_improvements'] ) ? $updated_content['seo_improvements'] : array();
		$validated['content_updates']            = isset( $updated_content['content_updates'] ) ? $updated_content['content_updates'] : array();
		$validated['internal_links_suggestions'] = isset( $updated_content['internal_links_suggestions'] ) ? $updated_content['internal_links_suggestions'] : array();
		$validated['addressed_issues']           = isset( $updated_content['addressed_issues'] ) ? $updated_content['addressed_issues'] : array();
		$validated['update_summary']             = isset( $updated_content['update_summary'] ) ? sanitize_text_field( $updated_content['update_summary'] ) : '';

		return $validated;
	}

	/**
	 * Remove unwanted UTM parameters from URLs in content
	 * Filters out utm_source=openai, utm_source=chatgpt, and similar tracking params
	 *
	 * @param string $content Content with potential UTM parameters
	 * @return string Cleaned content
	 */
	public function remove_utm_parameters( $content ) {
		// List of UTM parameters to remove (AI-generated tracking)
		$unwanted_utm_patterns = array(
			'utm_source=openai',
			'utm_source=chatgpt',
			'utm_source=chatgpt.com',
			'utm_source=claude',
			'utm_source=anthropic',
			'utm_medium=ai',
			'utm_campaign=ai_generated',
		);

		// Also remove all UTM parameters entirely for cleaner URLs
		$all_utm_params = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
		);

		// Pattern to match URLs with query parameters
		$pattern = '/(href=["\'])(https?:\/\/[^"\']+?)(["\'])/i';

		$content = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $all_utm_params ) {
				$full_match  = $matches[0];
				$href_prefix = $matches[1]; // href=" or href='
				$url         = $matches[2]; // The actual URL
				$href_suffix = $matches[3]; // " or '

				// Parse URL
				$parsed = parse_url( $url );

				// If no query string, return as is
				if ( empty( $parsed['query'] ) ) {
					return $full_match;
				}

				// Parse query parameters
				parse_str( $parsed['query'], $params );

				// Remove all UTM parameters
				foreach ( $all_utm_params as $utm_param ) {
					unset( $params[ $utm_param ] );
				}

				// Rebuild URL
				$clean_url = $parsed['scheme'] . '://' . $parsed['host'];
				if ( ! empty( $parsed['path'] ) ) {
					$clean_url .= $parsed['path'];
				}

				// Add back non-UTM query parameters
				if ( ! empty( $params ) ) {
					$clean_url .= '?' . http_build_query( $params );
				}

				// Add fragment if exists
				if ( ! empty( $parsed['fragment'] ) ) {
					$clean_url .= '#' . $parsed['fragment'];
				}

				return $href_prefix . $clean_url . $href_suffix;
			},
			$content
		);

		return $content;
	}
}
