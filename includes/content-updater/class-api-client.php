<?php
/**
 * API Client for FreshRank AI Content Updater
 * Handles API communication with OpenAI and OpenRouter
 *
 * @package FreshRank_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_API_Client {

	private $api_key;
	private $model;
	private $provider; // 'openai' or 'openrouter'

	/**
	 * Constructor
	 *
	 * @param string $api_key API key for the provider
	 * @param string $model Model identifier
	 * @param string $provider Provider name (openai or openrouter)
	 */
	public function __construct( $api_key = null, $model = null, $provider = null ) {
		if ( $api_key === null ) {
			// Auto-detect provider and load settings
			$this->provider = get_option( 'freshrank_ai_provider', 'openai' );

			if ( $this->provider === 'openrouter' ) {
				$stored_api_key = get_option( 'freshrank_openrouter_api_key', '' );
				$this->api_key  = FreshRank_Encryption::decrypt( $stored_api_key );

				// Load OpenRouter model - custom ID takes precedence
				$custom_model = get_option( 'freshrank_openrouter_custom_model_writing', '' );
				if ( ! empty( $custom_model ) ) {
					$this->model = $custom_model;
				} else {
					$this->model = get_option( 'freshrank_openrouter_model_writing', 'google/gemini-2.5-pro' );
				}
			} else {
				// OpenAI (default)
				$stored_api_key = get_option( 'freshrank_openai_api_key', '' );
				$this->api_key  = FreshRank_Encryption::decrypt( $stored_api_key );
				$this->model    = get_option( 'freshrank_content_model', 'gpt-5' );
			}
		} else {
			$this->api_key  = $api_key;
			$this->model    = $model;
			$this->provider = $provider;
		}
	}

	/**
	 * Unified API call method - routes to appropriate provider
	 *
	 * @param string $prompt The prompt to send to the API
	 * @return array Array with 'content' and 'usage' keys
	 * @throws Exception If API call fails
	 */
	public function call_api( $prompt ) {
		if ( $this->provider === 'openrouter' ) {
			return $this->call_openrouter_api( $prompt );
		} else {
			return $this->call_openai_api( $prompt );
		}
	}

	/**
	 * Call OpenAI API
	 * Supports both GPT-5 (Responses API) and other models (Chat Completions API)
	 *
	 * @param string $prompt The prompt to send
	 * @return array Array with 'content' and 'usage' keys
	 * @throws Exception If API call fails
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

		// Build system instructions with custom instructions if enabled
		$system_instructions = 'You are an expert content writer focused on creating valuable, user-centric content. Your updates should serve users\' needs first, answer their questions clearly, provide accurate current information, make information easy to find, and maintain natural engaging tone. CRITICAL: When generating HTML content, always use proper paragraph structure with <p> tags, maintain heading hierarchy, and add appropriate spacing between sections. Never return walls of text without proper paragraph breaks. Always respond with valid JSON format as specified. Ensure all JSON strings are properly escaped.';

		// Append custom rewrite instructions if enabled
		$custom_instructions = freshrank_get_custom_rewrite_prompt();
		if ( ! empty( $custom_instructions ) ) {
			$system_instructions .= "\n\nAdditional Instructions: " . $custom_instructions;
		}

		if ( $is_gpt5 ) {
			// Responses API format for GPT-5
			$body = array(
				'model'             => $this->model,
				'instructions'      => $system_instructions,
				'input'             => $prompt,
				'reasoning'         => array( 'effort' => 'low' ), // Fast content generation
				'text'              => array( 'verbosity' => 'high' ), // More detailed content
				'max_output_tokens' => 132000, // GPT-5 supports up to 132k output tokens
			);

			// Add web search if enabled
			if ( get_option( 'freshrank_enable_web_search', 0 ) ) {
				$body['tools'] = array(
					array( 'type' => 'web_search_preview' ),
				);
			}
		} else {
			// Chat Completions API format for other models
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

			// O1/O3 models don't support temperature parameter
			$is_reasoning_model = ( stripos( $this->model, 'o1' ) !== false || stripos( $this->model, 'o3' ) !== false );

			if ( ! $is_reasoning_model ) {
				$body['temperature'] = 0.4;

				// Enable JSON mode for GPT-4 and newer (not available for o1/o3 or gpt-3.5)
				if ( stripos( $this->model, 'gpt-4' ) !== false || stripos( $this->model, 'gpt-5' ) !== false ) {
					$body['response_format'] = array( 'type' => 'json_object' );
				}
			}

			// Use max_completion_tokens for modern models, max_tokens for legacy
			if ( stripos( $this->model, 'gpt-3.5' ) !== false ) {
				$body['max_tokens'] = 16000; // GPT-3.5 limit
			} elseif ( stripos( $this->model, 'gpt-4o' ) !== false ) {
				$body['max_completion_tokens'] = 16384; // GPT-4o max output
			} elseif ( stripos( $this->model, 'gpt-4' ) !== false ) {
				$body['max_completion_tokens'] = 16384; // GPT-4 max output
			} else {
				$body['max_completion_tokens'] = 100000; // O1/O3 and future models
			}
		}

		// Determine timeout based on model and web search
		$web_search_enabled = get_option( 'freshrank_enable_web_search', 0 );
		$timeout            = 300; // Default 5 minutes

		if ( $is_gpt5 && $web_search_enabled ) {
			$timeout = 900; // 15 minutes for GPT-5 with web search
			freshrank_debug_log( 'Using extended timeout (15 min) for GPT-5 content generation with web search' );
		} elseif ( $is_gpt5 ) {
			$timeout = 600; // 10 minutes for GPT-5 without web search
			freshrank_debug_log( 'Using extended timeout (10 min) for GPT-5 content generation' );
		}

		// Increase PHP timeout limits
		if ( function_exists( 'set_time_limit' ) ) {
			if ( @set_time_limit( $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase execution time limit. Long operations may timeout.' );
			}
		} else {
			freshrank_debug_log( 'set_time_limit() is unavailable. Long operations may timeout.' );
		}
		if ( function_exists( 'ini_set' ) ) {
			if ( @ini_set( 'max_execution_time', (string) $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase max_execution_time. Large operations may fail.' );
			}
			if ( @ini_set( 'default_socket_timeout', (string) $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase socket timeout. API requests may timeout.' );
			}
		} else {
			freshrank_debug_log( 'ini_set() is unavailable. API requests may timeout.' );
		}

		freshrank_debug_log( 'Making API request to ' . $url . ' with model ' . $this->model . ' (timeout: ' . $timeout . 's)' );

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'httpversion' => '1.1',
				'blocking'    => true,
				'sslverify'   => apply_filters( 'freshrank_ai_http_request_sslverify', true ),
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$error_message = $response->get_error_message();
			$error_data    = $response->get_error_data();

			freshrank_debug_log( 'OpenAI content update transport error: ' . $error_code . ' - ' . $error_message . ' | data=' . wp_json_encode( $error_data ) );

			throw new Exception( 'OpenAI API request failed (' . $error_code . '): ' . $error_message );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			$error_body    = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $error_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown API error';
			freshrank_debug_log( 'OpenAI content update non-200 (' . $response_code . '): ' . $error_message . ' | body=' . substr( $error_body, 0, 1000 ) );
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
	 * Call OpenRouter API
	 * Uses OpenAI-compatible Chat Completions format
	 *
	 * @param string $prompt The prompt to send
	 * @return array Array with 'content' and 'usage' keys
	 * @throws Exception If API call fails
	 */
	private function call_openrouter_api( $prompt ) {
		$url = 'https://openrouter.ai/api/v1/chat/completions';

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => home_url(), // Required by OpenRouter
			'X-Title'       => get_bloginfo( 'name' ) . ' - FreshRank AI',
			'Connection'    => 'close', // Mitigate cURL error 18 in local envs
			'Expect'        => '', // Disable 100-continue header for proxy compatibility
		);

		// Build system instructions
		$system_instructions = 'You are an expert content writer focused on creating valuable, user-centric content. Always maintain natural, engaging tone. CRITICAL: When generating HTML content, always use proper paragraph structure with <p> tags, maintain heading hierarchy, and add appropriate spacing between sections. Never return walls of text without proper paragraph breaks.';

		// Append custom rewrite instructions if enabled
		$custom_instructions = freshrank_get_custom_rewrite_prompt();
		if ( ! empty( $custom_instructions ) ) {
			$system_instructions .= "\n\nAdditional Instructions: " . $custom_instructions;
		}

		// OpenRouter uses Chat Completions API format (OpenAI-compatible)
		$body = array(
			'model'       => $this->model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_instructions,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.7, // Higher for creative writing
			'max_tokens'  => 65536, // Gemini 2.5 Pro's actual output limit (OpenRouter may not respect higher values)
		);

		// Log the request details
		freshrank_debug_log( 'OpenRouter API Request - Model: ' . $this->model );
		freshrank_debug_log( 'OpenRouter API Request - max_tokens: 65536' );
		freshrank_debug_log( 'OpenRouter API Request - Prompt length: ' . strlen( $prompt ) . ' chars' );

		$timeout = 720; // 12 minutes for content generation (allows for very long outputs with high token limits)

		// Increase PHP timeout limits
		if ( function_exists( 'set_time_limit' ) ) {
			if ( @set_time_limit( $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase execution time limit. Long operations may timeout.' );
			}
		} else {
			freshrank_debug_log( 'set_time_limit() is unavailable. Long operations may timeout.' );
		}
		if ( function_exists( 'ini_set' ) ) {
			if ( @ini_set( 'max_execution_time', (string) $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase max_execution_time. Large operations may fail.' );
			}
			if ( @ini_set( 'default_socket_timeout', (string) $timeout ) === false ) {
				freshrank_debug_log( 'Unable to increase socket timeout. API requests may timeout.' );
			}
		} else {
			freshrank_debug_log( 'ini_set() is unavailable. API requests may timeout.' );
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'httpversion' => '1.1',
				'blocking'    => true,
				'sslverify'   => apply_filters( 'freshrank_ai_http_request_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$error_message = $response->get_error_message();
			$error_data    = $response->get_error_data();

			freshrank_debug_log( 'OpenRouter API transport error: ' . $error_code . ' - ' . $error_message . ' | data=' . wp_json_encode( $error_data ) );

			throw new Exception( 'OpenRouter API request failed (' . $error_code . '): ' . $error_message );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			$error_body    = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $error_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown API error';

			// Log detailed error for debugging
			freshrank_debug_log( 'OpenRouter API Error - Code: ' . $response_code );
			freshrank_debug_log( 'OpenRouter API Error - Message: ' . $error_message );
			freshrank_debug_log( 'OpenRouter API Error - Model: ' . $this->model );

			throw new Exception( "OpenRouter API error (HTTP $response_code): $error_message" );
		}

		$body = wp_remote_retrieve_body( $response );

		// Log response size
		freshrank_debug_log( 'Response body size: ' . strlen( $body ) . ' bytes' );

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			freshrank_debug_log( 'JSON decode error: ' . json_last_error_msg() );
			freshrank_debug_log( 'Last 1000 chars of body: ' . substr( $body, -1000 ) );
		}

		// Check finish_reason to understand if response was truncated
		$finish_reason = isset( $data['choices'][0]['finish_reason'] ) ? $data['choices'][0]['finish_reason'] : 'unknown';
		freshrank_debug_log( 'OpenRouter finish_reason: ' . $finish_reason );
		freshrank_debug_log( 'OpenRouter model: ' . $this->model );

		// Log token usage
		if ( isset( $data['usage'] ) ) {
			freshrank_debug_log( 'Prompt tokens: ' . ( $data['usage']['prompt_tokens'] ?? 'unknown' ) );
			freshrank_debug_log( 'Completion tokens: ' . ( $data['usage']['completion_tokens'] ?? 'unknown' ) );
			freshrank_debug_log( 'Total tokens: ' . ( $data['usage']['total_tokens'] ?? 'unknown' ) );
		}

		// Log if response was cut off due to length
		if ( $finish_reason === 'length' ) {
			freshrank_debug_log( 'WARNING: Response hit max_tokens limit! Response may be incomplete.' );
			freshrank_debug_log( 'Requested max_tokens: 128000' );
		}

		$content = $this->extract_openrouter_message_content( $data );

		// Log extracted content length
		freshrank_debug_log( 'Extracted content length: ' . strlen( $content ) . ' characters' );

		if ( $content === '' ) {
			$this->log_openrouter_debug_response( $data );
			throw new Exception( 'Empty response from OpenRouter API' );
		}

		// Check if content looks truncated (doesn't end with closing brace for JSON responses)
		$trimmed = rtrim( $content );
		if ( strpos( $trimmed, '{' ) === 0 && substr( $trimmed, -1 ) !== '}' ) {
			freshrank_debug_log( 'WARNING: Extracted content appears truncated (starts with { but does not end with })' );
			freshrank_debug_log( 'Last 500 chars: ' . substr( $content, -500 ) );
		}

		$usage = array(
			'prompt_tokens'     => isset( $data['usage']['prompt_tokens'] ) ? $data['usage']['prompt_tokens'] : 0,
			'completion_tokens' => isset( $data['usage']['completion_tokens'] ) ? $data['usage']['completion_tokens'] : 0,
			'total_tokens'      => isset( $data['usage']['total_tokens'] ) ? $data['usage']['total_tokens'] : 0,
			'model'             => $this->model,
		);

		return array(
			'content' => $content,
			'usage'   => $usage,
		);
	}

	/**
	 * Extract assistant content from OpenRouter chat completion payload.
	 *
	 * Handles string content, content arrays (multimodal), and function/tool call fallbacks.
	 *
	 * @param array $data Decoded API response.
	 * @return string Assistant message content or empty string on failure.
	 */
	private function extract_openrouter_message_content( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$choice  = isset( $data['choices'][0] ) ? $data['choices'][0] : array();
		$message = isset( $choice['message'] ) ? $choice['message'] : array();

		// Direct string content.
		if ( isset( $message['content'] ) && is_string( $message['content'] ) && trim( $message['content'] ) !== '' ) {
			return trim( $message['content'] );
		}

		// Content provided as array (OpenAI style multimodal responses).
		if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
			$buffer = '';

			foreach ( $message['content'] as $block ) {
				if ( is_string( $block ) && trim( $block ) !== '' ) {
					$buffer .= trim( $block ) . "\n";
				} elseif ( is_array( $block ) ) {
					if ( isset( $block['text'] ) && trim( $block['text'] ) !== '' ) {
						$buffer .= trim( $block['text'] ) . "\n";
					} elseif ( isset( $block['content'] ) && is_string( $block['content'] ) && trim( $block['content'] ) !== '' ) {
						$buffer .= trim( $block['content'] ) . "\n";
					}
				}
			}

			if ( trim( $buffer ) !== '' ) {
				return trim( $buffer );
			}
		}

		// Function/tool call fallback (some models respond via arguments).
		if ( isset( $message['tool_calls'][0]['function']['arguments'] ) ) {
			$arguments = $message['tool_calls'][0]['function']['arguments'];
			if ( is_string( $arguments ) && trim( $arguments ) !== '' ) {
				return trim( $arguments );
			}
		}

		// Legacy field
		if ( isset( $choice['text'] ) && is_string( $choice['text'] ) && trim( $choice['text'] ) !== '' ) {
			return trim( $choice['text'] );
		}

		return '';
	}

	/**
	 * Log a trimmed snapshot of the raw OpenRouter response for debugging.
	 *
	 * @param array $data Decoded API response.
	 * @return void
	 */
	private function log_openrouter_debug_response( $data ) {
		$snapshot = substr( wp_json_encode( $data ), 0, 1200 );
		freshrank_debug_log( 'OpenRouter response missing content. Snapshot: ' . $snapshot );
	}
}
