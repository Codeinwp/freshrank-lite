<?php
/**
 * AJAX Response Utility Class
 *
 * Provides standardized AJAX response formats for consistent API responses.
 *
 * @package    FreshRank_AI
 * @subpackage FreshRank_AI/includes/utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FreshRank AJAX Response
 *
 * Static utility class for sending consistent AJAX responses with
 * proper formatting and error codes.
 */
class FreshRank_AJAX_Response {

	/**
	 * Send success response
	 *
	 * @param array  $data    Response data
	 * @param string $message Optional success message
	 *
	 * @example
	 * FreshRank_AJAX_Response::success(['post_id' => 123], 'Analysis completed');
	 */
	public static function success( $data = array(), $message = '' ) {
		if ( ! is_array( $data ) ) {
			$data = array(
				'value' => $data,
			);
		}

		if ( ! empty( $message ) ) {
			$data['message'] = $message;
		}

		wp_send_json_success( $data );
	}

	/**
	 * Send error response
	 *
	 * @param string $message Error message
	 * @param string $code    Error code (default: 'error')
	 * @param array  $data    Optional error data
	 *
	 * @example
	 * FreshRank_AJAX_Response::error('Invalid post ID', 'invalid_input');
	 */
	public static function error( $message, $code = 'error', $data = array() ) {
		$response = array(
			'success' => false,
			'message' => $message,
			'code'    => $code,
		);

		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}

		wp_send_json_error( $response );
	}

	/**
	 * Send authorization error response
	 *
	 * @param string $message Optional custom message
	 *
	 * @example
	 * FreshRank_AJAX_Response::not_authorized('You do not have permission to perform this action');
	 */
	public static function not_authorized( $message = '' ) {
		if ( empty( $message ) ) {
			$message = __( 'You do not have permission to perform this action.', 'freshrank-ai' );
		}

		$response = array(
			'success' => false,
			'message' => $message,
			'code'    => 'not_authorized',
		);

		wp_send_json_error( $response );
	}
}
