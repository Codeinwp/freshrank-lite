<?php
/**
 * COMPLETE FIXED: Google Search Console API integration for Updatatron
 * Includes URL formatting fixes AND scoring logic fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_GSC_API {

	private $client_id;
	private $client_secret;
	private $redirect_uri;
	private $access_token;
	private $refresh_token;
	private $site_url;

	public function __construct() {
		// These should be set in plugin settings or wp-config.php
		$this->client_id = defined( 'FRESHRANK_GSC_CLIENT_ID' ) ? FRESHRANK_GSC_CLIENT_ID : get_option( 'freshrank_gsc_client_id', '' );

		// Client secret is encrypted in database - decrypt it
		if ( defined( 'FRESHRANK_GSC_CLIENT_SECRET' ) ) {
			$this->client_secret = FRESHRANK_GSC_CLIENT_SECRET;
		} else {
			$encrypted_secret = get_option( 'freshrank_gsc_client_secret', '' );
			if ( ! empty( $encrypted_secret ) ) {
				try {
					$this->client_secret = FreshRank_Encryption::decrypt( $encrypted_secret );
				} catch ( Exception $e ) {
					// If decryption fails, might be old unencrypted value
					freshrank_debug_log( 'GSC: Failed to decrypt client secret, using as-is: ' . $e->getMessage() );
					$this->client_secret = $encrypted_secret;
				}
			} else {
				$this->client_secret = '';
			}
		}

		$this->redirect_uri  = admin_url( 'admin.php?page=freshrank-settings&tab=gsc&action=callback' );
		$this->access_token  = $this->decrypt_token_option( 'freshrank_gsc_access_token' );
		$this->refresh_token = $this->decrypt_token_option( 'freshrank_gsc_refresh_token' );
		$this->site_url      = get_site_url();
	}

	/**
	 * Decrypt a stored token option (backwards compatible with plaintext).
	 *
	 * @param string $option_name Option key.
	 * @return string Decrypted token or empty string on failure.
	 */
	private function decrypt_token_option( $option_name ) {
		$stored = get_option( $option_name, '' );

		if ( empty( $stored ) ) {
			return '';
		}

		try {
			return FreshRank_Encryption::decrypt( $stored );
		} catch ( Exception $e ) {
			freshrank_debug_log( 'GSC: Failed to decrypt stored token (' . $option_name . '): ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Securely store access and refresh tokens.
	 *
	 * @param string      $access_token  New access token.
	 * @param string|null $refresh_token Optional refresh token.
	 * @throws Exception When encryption fails.
	 */
	private function store_tokens( $access_token, $refresh_token = null ) {
		if ( ! empty( $access_token ) ) {
			try {
				$encrypted_access = FreshRank_Encryption::encrypt( $access_token );
				update_option( 'freshrank_gsc_access_token', $encrypted_access );
			} catch ( Exception $e ) {
				freshrank_debug_log( 'GSC: Failed to encrypt access token: ' . $e->getMessage() );
				throw new Exception( 'Failed to securely store Google access token: ' . $e->getMessage() );
			}
			$this->access_token = $access_token;
		} else {
			delete_option( 'freshrank_gsc_access_token' );
			$this->access_token = '';
		}

		if ( $refresh_token !== null ) {
			if ( ! empty( $refresh_token ) ) {
				try {
					$encrypted_refresh = FreshRank_Encryption::encrypt( $refresh_token );
					update_option( 'freshrank_gsc_refresh_token', $encrypted_refresh );
				} catch ( Exception $e ) {
					freshrank_debug_log( 'GSC: Failed to encrypt refresh token: ' . $e->getMessage() );
					throw new Exception( 'Failed to securely store Google refresh token: ' . $e->getMessage() );
				}
				$this->refresh_token = $refresh_token;
			} else {
				delete_option( 'freshrank_gsc_refresh_token' );
				$this->refresh_token = '';
			}
		}
	}

	/**
	 * Get OAuth authorization URL
	 * CSRF Protection: State parameter added to prevent OAuth flow attacks
	 *
	 * Testing:
	 * 1. Click "Connect to Google Search Console" button
	 * 2. Inspect the authorization URL in browser - should contain a 'state' parameter
	 * 3. Verify transient is set: wp transient list | grep freshrank_oauth_state
	 * 4. Complete OAuth flow - state should be validated in exchange_code()
	 */
	public function get_auth_url() {
		// Generate CSRF state token tied to current user
		$state = wp_create_nonce( 'freshrank_gsc_oauth_' . get_current_user_id() );

		// Store state in transient (expires in 10 minutes)
		set_transient( 'freshrank_oauth_state_' . get_current_user_id(), $state, 600 );

		$params = array(
			'client_id'     => $this->client_id,
			'redirect_uri'  => $this->redirect_uri,
			'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
			'response_type' => 'code',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,  // CSRF protection
		);

		return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query( $params );
	}

	/**
	 * Exchange authorization code for access token
	 * CSRF Protection: Validates state parameter from OAuth callback
	 *
	 * Testing:
	 * 1. Complete OAuth flow normally - should succeed
	 * 2. Test CSRF attack simulation:
	 *    - Manually construct a callback URL without valid state parameter
	 *    - Should throw "CSRF validation failed" exception
	 * 3. Test replay attack:
	 *    - Try to reuse the same OAuth callback URL twice
	 *    - Second attempt should fail (state transient is deleted after first use)
	 * 4. Test timeout:
	 *    - Wait 10+ minutes after clicking "Connect" before completing OAuth
	 *    - Should fail with "CSRF validation failed" (transient expired)
	 */
	public function exchange_code( $code ) {

		// Capability check - Administrator only
		if ( ! current_user_can( 'manage_options' ) ) {
			freshrank_debug_log( 'GSC: Permission denied - user lacks manage_options capability' );
			throw new Exception( __( 'Permission denied. Administrator access required.', 'freshrank-ai' ) );
		}

		// CSRF Protection: Validate state parameter
		$received_state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';
		$stored_state   = get_transient( 'freshrank_oauth_state_' . get_current_user_id() );

		freshrank_debug_log( 'GSC: CSRF validation - received: ' . ( ! empty( $received_state ) ? 'present' : 'missing' ) . ', stored: ' . ( ! empty( $stored_state ) ? 'present' : 'missing' ) );

		// Verify state exists, matches, and is a valid nonce
		if ( ! $received_state ||
			! $stored_state ||
			$received_state !== $stored_state ||
			! wp_verify_nonce( $received_state, 'freshrank_gsc_oauth_' . get_current_user_id() ) ) {
			throw new Exception( 'CSRF validation failed. Please try connecting again.' );
		}

		// Delete transient immediately to prevent replay attacks
		delete_transient( 'freshrank_oauth_state_' . get_current_user_id() );

		// Sanitize and validate authorization code
		$code = sanitize_text_field( $code );
		if ( empty( $code ) || ! preg_match( '/^[a-zA-Z0-9\-_\/\.]+$/', $code ) ) {
			freshrank_debug_log( 'GSC: Invalid authorization code format' );
			throw new Exception( 'Invalid authorization code format' );
		}

		freshrank_debug_log( 'GSC: Authorization code validated, making token request' );

		$url = 'https://oauth2.googleapis.com/token';

		$params = array(
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri'  => $this->redirect_uri,
			'grant_type'    => 'authorization_code',
			'code'          => $code,
		);

		freshrank_debug_log( 'GSC: Token request URL: ' . $url );
		freshrank_debug_log( 'GSC: Redirect URI: ' . $this->redirect_uri );
		freshrank_debug_log( 'GSC: Client ID (first 20 chars): ' . substr( $this->client_id, 0, 20 ) . '...' );
		freshrank_debug_log( 'GSC: Client Secret length: ' . strlen( $this->client_secret ) );
		freshrank_debug_log( 'GSC: Client ID has whitespace: ' . ( trim( $this->client_id ) !== $this->client_id ? 'YES' : 'NO' ) );
		freshrank_debug_log( 'GSC: Client Secret has whitespace: ' . ( trim( $this->client_secret ) !== $this->client_secret ? 'YES' : 'NO' ) );

		$response = wp_remote_post(
			$url,
			array(
				'body'    => $params,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			freshrank_debug_log( 'GSC: Token request WP_Error: ' . $response->get_error_message() );
			throw new Exception( 'Failed to connect to Google: ' . $response->get_error_message() );
		}

		$body      = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );
		freshrank_debug_log( 'GSC: Token response HTTP code: ' . $http_code );

		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) ) {
			freshrank_debug_log( 'GSC: Google OAuth error: ' . $data['error'] . ' - ' . ( $data['error_description'] ?? 'no description' ) );
			throw new Exception( 'Google OAuth error: ' . ( $data['error_description'] ?? $data['error'] ) );
		}

		if ( ! isset( $data['access_token'] ) || ! isset( $data['refresh_token'] ) ) {
			freshrank_debug_log( 'GSC: Token response missing access_token or refresh_token' );
			freshrank_debug_log( 'GSC: Response body: ' . substr( $body, 0, 500 ) );
			throw new Exception( 'Invalid token response from Google' );
		}

		// Save tokens
		$this->store_tokens( $data['access_token'], $data['refresh_token'] );
		update_option( 'freshrank_gsc_authenticated', true );

		return true;
	}

	/**
	 * Refresh access token
	 */
	private function refresh_access_token() {
		if ( empty( $this->refresh_token ) ) {
			throw new Exception( 'No refresh token available. Please re-authenticate.' );
		}

		$url = 'https://oauth2.googleapis.com/token';

		$params = array(
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'grant_type'    => 'refresh_token',
			'refresh_token' => $this->refresh_token,
		);

		$response = wp_remote_post(
			$url,
			array(
				'body'    => $params,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Failed to refresh token: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) ) {
			throw new Exception( 'Token refresh error: ' . $data['error_description'] );
		}

		$this->store_tokens( $data['access_token'] );

		return true;
	}

	/**
	 * Make authenticated API request
	 * RATE LIMITING: Adds 300ms delay after successful API calls to respect GSC API rate limits
	 */
	private function make_api_request( $endpoint, $params = array() ) {
		if ( empty( $this->access_token ) ) {
			throw new Exception( 'No access token available. Please authenticate first.' );
		}

		$url = 'https://www.googleapis.com/webmasters/v3/' . ltrim( $endpoint, '/' );

		$headers = array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
		);

		$args = array(
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( ! empty( $params ) ) {
			$args['body']   = wp_json_encode( $params );
			$args['method'] = 'POST';
		}

		$response = wp_remote_request( $url, $args );

		// Handle token expiration
		if ( wp_remote_retrieve_response_code( $response ) === 401 ) {
			$this->refresh_access_token();

			// Retry with new token
			$headers['Authorization'] = 'Bearer ' . $this->access_token;
			$args['headers']          = $headers;
			$response                 = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body          = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown API error';
			throw new Exception( "API error (HTTP $code): $error_message" );
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		// Rate limiting: Add 300ms delay after successful API call to respect GSC API quotas
		// This delay ONLY happens on actual API calls, NOT on cache hits
		usleep( 300000 ); // 300ms delay

		return $result;
	}

	/**
	 * Get the correct site property for this WordPress site
	 */
	private function get_current_site_property() {
		static $cached_property = null;

		if ( $cached_property !== null ) {
			return $cached_property;
		}

		$properties   = $this->get_site_properties();
		$current_site = $this->site_url;

		// Normalize URLs for comparison
		$current_site_normalized = $this->normalize_url( $current_site );
		$current_host            = parse_url( $current_site, PHP_URL_HOST );

		// Try to find exact match first (URL properties)
		foreach ( $properties as $property ) {
			// Skip domain properties in exact match
			if ( strpos( $property['url'], 'sc-domain:' ) === 0 ) {
				continue;
			}

			$property_normalized = $this->normalize_url( $property['url'] );
			if ( $property_normalized === $current_site_normalized ) {
				$cached_property = $property['url'];
				freshrank_debug_log( 'GSC: Matched URL property - ' . $property['url'] );
				return $cached_property;
			}
		}

		// Try to find partial match (same domain) - supports both URL and domain properties
		foreach ( $properties as $property ) {
			// Handle domain properties (sc-domain:example.com)
			if ( strpos( $property['url'], 'sc-domain:' ) === 0 ) {
				$domain = str_replace( 'sc-domain:', '', $property['url'] );
				// Remove www. from both for comparison
				$domain_clean       = str_replace( 'www.', '', strtolower( $domain ) );
				$current_host_clean = str_replace( 'www.', '', strtolower( $current_host ) );

				if ( $domain_clean === $current_host_clean ) {
					$cached_property = $property['url'];
					freshrank_debug_log( 'GSC: Matched domain property - ' . $property['url'] );
					return $cached_property;
				}
			} else {
				// Handle URL properties
				$property_host = parse_url( $property['url'], PHP_URL_HOST );
				if ( $property_host === $current_host ) {
					$cached_property = $property['url'];
					return $cached_property;
				}
			}
		}

		// If no match found, throw an error with available properties
		$available = array_column( $properties, 'url' );
		freshrank_debug_log( 'GSC: No match found for site - ' . $current_site );
		freshrank_debug_log( 'GSC: Current host - ' . $current_host );
		freshrank_debug_log( 'GSC: Available properties - ' . implode( ', ', $available ) );

		$error_message  = "No matching Google Search Console property found for site: $current_site (host: $current_host)";
		$error_message .= "\n\nAvailable properties: " . implode( ', ', $available );
		$error_message .= "\n\nMake sure your WordPress site URL matches one of the properties above.";

		throw new Exception( $error_message );
	}

	/**
	 * Normalize URL for consistent comparison
	 */
	private function normalize_url( $url ) {
		// Remove trailing slash and convert to lowercase
		return strtolower( rtrim( $url, '/' ) );
	}

	/**
	 * FIXED: Get search analytics data for a specific URL with multiple URL format attempts
	 */
	public function get_url_analytics( $url, $start_date, $end_date, $force_refresh = false ) {
		// Capability check with cron/WP-CLI allowance
		if ( ! $this->can_access_gsc_data() ) {
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		// Sanitize and validate URL
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			throw new Exception( 'Invalid URL' );
		}

		// Validate date formats (YYYY-MM-DD)
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ||
			! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			throw new Exception( 'Invalid date format. Use YYYY-MM-DD' );
		}

		// Check cache (24 hour TTL) unless force refresh
		if ( ! $force_refresh ) {
			$cache_key   = 'freshrank_gsc_' . md5( $url . $start_date . $end_date );
			$cached_data = get_transient( $cache_key );
			if ( $cached_data !== false ) {
				return $cached_data;
			}
		}

		// Get the correct site property for this WordPress installation
		$site_property = $this->get_current_site_property();

		// Try multiple URL formats to increase chances of finding data
		$url_variations = $this->generate_url_variations( $url );

		foreach ( $url_variations as $test_url ) {
			$result = $this->try_url_analytics( $site_property, $test_url, $start_date, $end_date );

			// If we found data (non-zero impressions), cache and return it
			if ( $result && $result['impressions'] > 0 ) {
				if ( get_option( 'freshrank_debug_mode', false ) ) {
					freshrank_debug_log( "GSC Success with URL: $test_url - Impressions: {$result['impressions']}, Clicks: {$result['clicks']}" );
				}

				// Cache the result for 24 hours
				$cache_key = 'freshrank_gsc_' . md5( $url . $start_date . $end_date );
				set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );

				// Store last refresh timestamp
				update_post_meta( $this->get_post_id_from_url( $url ), '_freshrank_gsc_last_refresh', time() );

				return $result;
			}
		}

		// If no variation returned data, log all attempts and return zeros
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			freshrank_debug_log( "GSC: No data found for any URL variation of: $url" );
			freshrank_debug_log( 'GSC: Tried URLs: ' . implode( ', ', $url_variations ) );
		}

		$zero_result = array(
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
		);

		// Cache zero results too (shorter TTL - 1 hour)
		$cache_key = 'freshrank_gsc_' . md5( $url . $start_date . $end_date );
		set_transient( $cache_key, $zero_result, 1 * HOUR_IN_SECONDS );

		return $zero_result;
	}

	/**
	 * Get post ID from URL
	 */
	private function get_post_id_from_url( $url ) {
		return url_to_postid( $url );
	}

	/**
	 * Generate multiple URL variations to try
	 */
	private function generate_url_variations( $original_url ) {
		$variations = array();

		// Clean the original URL
		$clean_url = ltrim( $original_url, '/' );
		$base_site = rtrim( $this->site_url, '/' );

		// 1. Full URL with trailing slash
		$variations[] = $base_site . '/' . $clean_url . '/';

		// 2. Full URL without trailing slash
		$variations[] = $base_site . '/' . $clean_url;

		// 3. Just the path with trailing slash
		$variations[] = '/' . $clean_url . '/';

		// 4. Just the path without trailing slash
		$variations[] = '/' . $clean_url;

		// 5. Root-relative URL
		if ( strpos( $clean_url, $base_site ) === false ) {
			$variations[] = $clean_url . '/';
			$variations[] = $clean_url;
		}

		// 6. Try extracting just the slug if it's a full URL
		if ( strpos( $original_url, 'http' ) === 0 ) {
			$path = parse_url( $original_url, PHP_URL_PATH );
			if ( $path && $path !== '/' ) {
				$variations[] = $base_site . $path;
				$variations[] = $base_site . rtrim( $path, '/' ) . '/';
				$variations[] = $path;
				$variations[] = rtrim( $path, '/' ) . '/';
			}
		}

		// Remove duplicates and empty values
		$variations = array_unique( array_filter( $variations ) );

		return $variations;
	}

	/**
	 * Get top search queries for a specific URL
	 */
	public function get_top_queries_for_url( $url, $limit = 5, $start_date = null, $end_date = null ) {
		if ( ! $this->can_access_gsc_data() ) {
			throw new Exception( __( 'Permission denied. You do not have sufficient permissions to perform this action.', 'freshrank-ai' ) );
		}

		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			throw new Exception( 'Invalid URL' );
		}

		$limit      = max( 1, intval( $limit ) );
		$end_date   = $end_date ? $end_date : date( 'Y-m-d' );
		$start_date = $start_date ? $start_date : date( 'Y-m-d', strtotime( '-30 days' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			throw new Exception( 'Invalid date format. Use YYYY-MM-DD' );
		}

		$site_property  = $this->get_current_site_property();
		$url_variations = $this->generate_url_variations( $url );

		foreach ( $url_variations as $test_url ) {
			$queries = $this->try_get_top_queries( $site_property, $test_url, $start_date, $end_date, $limit );
			if ( ! empty( $queries ) ) {
				return $queries;
			}
		}

		if ( get_option( 'freshrank_debug_mode', false ) ) {
			freshrank_debug_log( "GSC: No top queries found for any URL variation of: $url" );
			freshrank_debug_log( 'GSC: Tried URLs for queries: ' . implode( ', ', $url_variations ) );
		}

		return array();
	}

	/**
	 * Attempt to fetch top queries for a URL variation
	 */
	private function try_get_top_queries( $site_property, $url, $start_date, $end_date, $limit ) {
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			freshrank_debug_log( "GSC API - Fetching top queries for URL: $url" );
		}

		$params = array(
			'startDate'             => $start_date,
			'endDate'               => $end_date,
			'dimensions'            => array( 'query' ),
			'dimensionFilterGroups' => array(
				array(
					'groupType' => 'and',
					'filters'   => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $url,
						),
					),
				),
			),
			'startRow'              => 0,
			'rowLimit'              => $limit,
		);

		$endpoint = 'sites/' . urlencode( $site_property ) . '/searchAnalytics/query';

		try {
			$response = $this->make_api_request( $endpoint, $params );

			if ( empty( $response['rows'] ) ) {
				return array();
			}

			$results = array();
			foreach ( $response['rows'] as $row ) {
				$query = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
				if ( $query === '' ) {
					continue;
				}

				$results[] = array(
					'query'       => $query,
					'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
					'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
					'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
					'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0,
				);
			}

			return $results;
		} catch ( Exception $e ) {
			if ( get_option( 'freshrank_debug_mode', false ) ) {
				freshrank_debug_log( "GSC API Error (top queries) for $url: " . $e->getMessage() );
			}
			return array();
		}
	}

	/**
	 * Try getting analytics for a specific URL format
	 */
	private function try_url_analytics( $site_property, $url, $start_date, $end_date ) {
		// Debug logging
		if ( get_option( 'freshrank_debug_mode', false ) ) {
			freshrank_debug_log( "GSC API - Trying URL: $url" );
			freshrank_debug_log( "GSC API - Site Property: $site_property" );
			freshrank_debug_log( "GSC API - Date Range: $start_date to $end_date" );
		}

		// Use dimensionFilterGroups for proper URL filtering
		$params = array(
			'startDate'             => $start_date,
			'endDate'               => $end_date,
			'dimensions'            => array( 'page' ),
			'dimensionFilterGroups' => array(
				array(
					'groupType' => 'and',
					'filters'   => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $url,
						),
					),
				),
			),
			'aggregationType'       => 'byPage',
			'startRow'              => 0,
			'rowLimit'              => 1,
		);

		$endpoint = 'sites/' . urlencode( $site_property ) . '/searchAnalytics/query';

		try {
			$response = $this->make_api_request( $endpoint, $params );

			if ( empty( $response['rows'] ) ) {
				return array(
					'clicks'      => 0,
					'impressions' => 0,
					'ctr'         => 0,
					'position'    => 0,
				);
			}

			$row    = $response['rows'][0];
			$result = array(
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
				'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0,
			);

			return $result;

		} catch ( Exception $e ) {
			if ( get_option( 'freshrank_debug_mode', false ) ) {
				freshrank_debug_log( "GSC API Error for $url: " . $e->getMessage() );
			}

			return array(
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'position'    => 0,
			);
		}
	}

	/**
	 * Process GSC data for a single post
	 * Used by both batch processor and legacy prioritization
	 *
	 * @param int $post_id Post ID to process
	 * @return array Result with keys: success, cache_hit, error, metrics
	 */
	public function process_single_post_gsc_data( $post_id ) {
		$result = array(
			'success'   => false,
			'cache_hit' => false,
			'error'     => null,
			'metrics'   => array(),
		);

		try {
			// Get database instance
			$database = FreshRank_Database::get_instance();

			// Get post object
			$post = get_post( $post_id );
			if ( ! $post || $post->post_status !== 'publish' ) {
				throw new Exception( 'Post not found or not published' );
			}

			$post_url = get_permalink( $post_id );

			// Date ranges for comparison
			$current_end    = date( 'Y-m-d' );
			$current_start  = date( 'Y-m-d', strtotime( '-90 days' ) );
			$previous_end   = date( 'Y-m-d', strtotime( '-91 days' ) );
			$previous_start = date( 'Y-m-d', strtotime( '-181 days' ) );

			// Check if we'll hit cache
			$cache_key_current  = 'freshrank_gsc_' . md5( $post_url . $current_start . $current_end );
			$cache_key_previous = 'freshrank_gsc_' . md5( $post_url . $previous_start . $previous_end );
			$cache_hit          = ( get_transient( $cache_key_current ) !== false ) && ( get_transient( $cache_key_previous ) !== false );

			// Get current period data
			$current_data = $this->get_url_analytics( $post_url, $current_start, $current_end );

			// Get previous period data
			$previous_data = $this->get_url_analytics( $post_url, $previous_start, $previous_end );

			// Calculate priority scores
			$content_age_score       = $this->calculate_content_age_score_simplified( $post );
			$traffic_decline_score   = $this->calculate_traffic_decline_score( $current_data, $previous_data );
			$traffic_potential_score = $this->calculate_traffic_potential_simplified( $current_data );

			// Final priority score (0-90)
			$priority_score = $content_age_score + $traffic_decline_score + $traffic_potential_score;

			// Validation: Score should never exceed 90
			if ( $priority_score > 90 ) {
				freshrank_debug_log( "WARNING: Priority score ($priority_score) exceeds maximum for post {$post_id}" );
				$priority_score = 90;
			}

			// Prepare GSC data for database
			$gsc_data = array(
				'ctr_current'          => $current_data['ctr'],
				'ctr_previous'         => $previous_data['ctr'],
				'ctr_decline'          => 0, // Not used in simplified scoring
				'position_current'     => $current_data['position'],
				'position_previous'    => $previous_data['position'],
				'position_drop'        => 0, // Not used in simplified scoring
				'impressions_current'  => $current_data['impressions'],
				'impressions_previous' => $previous_data['impressions'],
				'clicks_current'       => $current_data['clicks'],
				'clicks_previous'      => $previous_data['clicks'],
				'traffic_potential'    => $traffic_potential_score,
				'content_age_score'    => $content_age_score,
				'priority_score'       => $priority_score,
			);

			// Save to database
			$database->save_gsc_data( $post_id, $gsc_data );

			$result['success']   = true;
			$result['cache_hit'] = $cache_hit;
			$result['metrics']   = array(
				'priority_score'          => $priority_score,
				'content_age_score'       => $content_age_score,
				'traffic_decline_score'   => $traffic_decline_score,
				'traffic_potential_score' => $traffic_potential_score,
				'impressions'             => $current_data['impressions'],
				'clicks'                  => $current_data['clicks'],
			);

		} catch ( Exception $e ) {
			$result['error'] = $e->getMessage();

			// Still save minimal data to database
			try {
				$database = FreshRank_Database::get_instance();
				$post     = get_post( $post_id );
				if ( $post ) {
					$content_age_score = $this->calculate_content_age_score_simplified( $post );
					$priority_score    = $content_age_score;

					$gsc_data = array(
						'ctr_current'          => 0,
						'ctr_previous'         => 0,
						'ctr_decline'          => 0,
						'position_current'     => 0,
						'position_previous'    => 0,
						'position_drop'        => 0,
						'impressions_current'  => 0,
						'impressions_previous' => 0,
						'clicks_current'       => 0,
						'clicks_previous'      => 0,
						'traffic_potential'    => 0,
						'content_age_score'    => $content_age_score,
						'priority_score'       => $priority_score,
					);

					$database->save_gsc_data( $post_id, $gsc_data );
				}
			} catch ( Exception $db_error ) {
				// Ignore database errors in error handler
			}
		}

		return $result;
	}

	/**
	 * Calculate content age score (0-30 points)
	 * Older content = higher priority for updates
	 */
	private function calculate_content_age_score_simplified( $post ) {
		// Get the date type setting (default to published date)
		$date_type = get_option( 'freshrank_gsc_date_type', 'post_date' );

		// Determine which date to use
		$date_to_use = $post->post_date;

		if ( $date_type === 'post_modified' ) {
			// Check if post has been modified (modified date differs from published date)
			$post_date_timestamp     = strtotime( $post->post_date );
			$modified_date_timestamp = strtotime( $post->post_modified );

			// Use modified date only if it's different from published date
			if ( $modified_date_timestamp > $post_date_timestamp ) {
				$date_to_use = $post->post_modified;
			}
			// Otherwise fall back to published date
		}

		$timestamp = strtotime( $date_to_use );
		$days_old  = ( time() - $timestamp ) / ( 24 * 60 * 60 );

		if ( $days_old > 365 ) {
			return 30; // Very old content = highest priority
		} elseif ( $days_old > 180 ) {
			return 23; // Old content
		} elseif ( $days_old > 90 ) {
			return 15; // Moderately old
		} elseif ( $days_old > 30 ) {
			return 8;  // Recent
		} else {
			return 0;  // Very recent = lowest priority
		}
	}

	/**
	 * Calculate traffic decline score (0-30 points)
	 * Based on how much traffic (clicks) has decreased
	 */
	private function calculate_traffic_decline_score( $current_data, $previous_data ) {
		$current_clicks  = $current_data['clicks'];
		$previous_clicks = $previous_data['clicks'];

		// If no previous data, can't calculate decline
		if ( $previous_clicks == 0 ) {
			return 0;
		}

		// Calculate percentage decline
		$clicks_decline     = max( 0, $previous_clicks - $current_clicks );
		$decline_percentage = ( $clicks_decline / $previous_clicks ) * 100;

		// Convert to 0-30 scale
		if ( $decline_percentage >= 50 ) {
			return 30; // 50%+ decline = highest priority
		} elseif ( $decline_percentage >= 30 ) {
			return 25; // 30-50% decline
		} elseif ( $decline_percentage >= 20 ) {
			return 20; // 20-30% decline
		} elseif ( $decline_percentage >= 10 ) {
			return 15; // 10-20% decline
		} elseif ( $decline_percentage > 0 ) {
			return 10; // Some decline (1-10%)
		} else {
			return 0;  // No decline or traffic increased
		}
	}

	/**
	 * FIXED: Calculate traffic potential score (0-30 points)
	 * Based on high impressions with low CTR = opportunity for improvement
	 * CRITICAL FIX: Return 0 when no impression data available
	 */
	private function calculate_traffic_potential_simplified( $data ) {
		$impressions = $data['impressions'];
		$ctr         = $data['ctr'];
		$position    = $data['position'];

		if ( $impressions == 0 ) {
			return 0;
		}

		$benchmark_ctrs = array(
			1  => 0.2895,
			2  => 0.1247,
			3  => 0.0741,
			4  => 0.0490,
			5  => 0.0344,
			6  => 0.0251,
			7  => 0.0184,
			8  => 0.0140,
			9  => 0.0111,
			10 => 0.0091,
			11 => 0.0084,
			12 => 0.0088,
			13 => 0.0101,
			14 => 0.0114,
			15 => 0.0125,
			16 => 0.0136,
			17 => 0.0136,
			18 => 0.0143,
			19 => 0.0146,
			20 => 0.0139,
		);

		$expected_ctr = 0.01;
		if ( $position > 0 && $position <= 20 ) {
			$expected_ctr = $benchmark_ctrs[ round( $position ) ];
		}

		$ctr_gap = max( 0, $expected_ctr - $ctr );

		if ( $ctr_gap == 0 ) {
			return 0;
		}

		$impression_factor = min( 1, $impressions / 10000 );

		$potential_score = ( $ctr_gap / $expected_ctr ) * $impression_factor * 30;

		return min( 30, round( $potential_score ) );
	}

	/**
	 * Determine if the current context can access GSC data
	 */
	private function can_access_gsc_data() {
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		return current_user_can( 'manage_freshrank' ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Check if GSC is authenticated
	 */
	public function is_authenticated() {
		return ! empty( $this->access_token ) && ! empty( $this->refresh_token );
	}

	/**
	 * Disconnect GSC authentication
	 */
	public function disconnect() {
		delete_option( 'freshrank_gsc_access_token' );
		delete_option( 'freshrank_gsc_refresh_token' );
		delete_option( 'freshrank_gsc_authenticated' );

		return true;
	}

	/**
	 * Get site properties from GSC
	 */
	public function get_site_properties() {
		$response = $this->make_api_request( 'sites' );

		if ( empty( $response['siteEntry'] ) ) {
			return array();
		}

		$properties = array();
		foreach ( $response['siteEntry'] as $site ) {
			$properties[] = array(
				'url'        => $site['siteUrl'],
				'permission' => $site['permissionLevel'],
			);
		}

		return $properties;
	}

	/**
	 * Test GSC connection and URL matching
	 */
	public function test_connection() {
		try {
			$properties = $this->get_site_properties();

			// Check if current site is in the properties
			$site_found        = false;
			$matching_property = null;

			foreach ( $properties as $property ) {
				if ( $property['url'] === $this->site_url ||
					$property['url'] === $this->site_url . '/' ||
					parse_url( $property['url'], PHP_URL_HOST ) === parse_url( $this->site_url, PHP_URL_HOST ) ) {
					$site_found        = true;
					$matching_property = $property['url'];
					break;
				}
			}

			// If site found, test with a recent post
			$test_results = array();
			if ( $site_found ) {
				$recent_posts = get_posts(
					array(
						'post_type'   => 'post',
						'post_status' => 'publish',
						'numberposts' => 3,
						'orderby'     => 'date',
						'order'       => 'DESC',
					)
				);

				foreach ( $recent_posts as $post ) {
					$post_url   = get_permalink( $post->ID );
					$end_date   = date( 'Y-m-d' );
					$start_date = date( 'Y-m-d', strtotime( '-30 days' ) );

					$data           = $this->get_url_analytics( $post_url, $start_date, $end_date );
					$test_results[] = array(
						'title'       => $post->post_title,
						'url'         => $post_url,
						'impressions' => $data['impressions'],
						'clicks'      => $data['clicks'],
					);
				}
			}

			return array(
				'success'           => true,
				'properties_count'  => count( $properties ),
				'site_found'        => $site_found,
				'matching_property' => $matching_property,
				'current_site'      => $this->site_url,
				'test_results'      => $test_results,
				'message'           => $site_found
					? 'Connection successful. Your site is verified in Search Console.'
					: 'Connection successful, but your site was not found in Search Console properties. Available: ' . implode( ', ', array_column( $properties, 'url' ) ),
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * DEBUG: Helper method to validate scoring logic for individual posts
	 */
	public function debug_scoring_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found' );
		}

		$post_url       = get_permalink( $post_id );
		$current_end    = date( 'Y-m-d' );
		$current_start  = date( 'Y-m-d', strtotime( '-90 days' ) );
		$previous_end   = date( 'Y-m-d', strtotime( '-91 days' ) );
		$previous_start = date( 'Y-m-d', strtotime( '-181 days' ) );

		// Get GSC data
		$current_data  = $this->get_url_analytics( $post_url, $current_start, $current_end );
		$previous_data = $this->get_url_analytics( $post_url, $previous_start, $previous_end );

		// Calculate scores
		$content_age_score       = $this->calculate_content_age_score_simplified( $post );
		$traffic_decline_score   = $this->calculate_traffic_decline_score( $current_data, $previous_data );
		$traffic_potential_score = $this->calculate_traffic_potential_simplified( $current_data );
		$total_score             = $content_age_score + $traffic_decline_score + $traffic_potential_score;

		return array(
			'post_title'    => $post->post_title,
			'post_url'      => $post_url,
			'current_data'  => $current_data,
			'previous_data' => $previous_data,
			'scores'        => array(
				'content_age'       => $content_age_score,
				'traffic_decline'   => $traffic_decline_score,
				'traffic_potential' => $traffic_potential_score,
				'total'             => $total_score,
			),
			'validation'    => array(
				'has_current_impressions'          => $current_data['impressions'] > 0,
				'has_previous_impressions'         => $previous_data['impressions'] > 0,
				'traffic_potential_should_be_zero' => $current_data['impressions'] == 0,
				'score_validation'                 => $total_score <= 90 ? 'PASS' : 'FAIL - Exceeds maximum',
			),
		);
	}

	/**
	 * DIAGNOSTIC: Complete GSC connection diagnostics
	 * Returns detailed information about GSC setup and potential issues
	 */
	public function diagnose_connection() {
		$diagnostics = array(
			'status'   => 'unknown',
			'issues'   => array(),
			'warnings' => array(),
			'info'     => array(),
			'steps'    => array(),
		);

		// 1. Check OAuth Credentials
		$diagnostics['info']['client_id']     = ! empty( $this->client_id ) ? 'Configured (hidden)' : 'NOT CONFIGURED';
		$diagnostics['info']['client_secret'] = ! empty( $this->client_secret ) ? 'Configured (hidden)' : 'NOT CONFIGURED';
		$diagnostics['info']['redirect_uri']  = $this->redirect_uri;
		$diagnostics['info']['site_url']      = $this->site_url;

		if ( empty( $this->client_id ) ) {
			$diagnostics['issues'][] = 'OAuth Client ID is missing';
			$diagnostics['steps'][]  = 'Go to Google Cloud Console and create OAuth 2.0 credentials';
		}

		if ( empty( $this->client_secret ) ) {
			$diagnostics['issues'][] = 'OAuth Client Secret is missing';
			$diagnostics['steps'][]  = 'Copy the Client Secret from Google Cloud Console';
		}

		// 2. Check Authentication Status
		$diagnostics['info']['access_token']  = ! empty( $this->access_token ) ? 'Present (expires periodically)' : 'Missing';
		$diagnostics['info']['refresh_token'] = ! empty( $this->refresh_token ) ? 'Present (permanent)' : 'Missing';
		$diagnostics['info']['authenticated'] = get_option( 'freshrank_gsc_authenticated', false ) ? 'Yes' : 'No';

		if ( empty( $this->access_token ) && empty( $this->refresh_token ) ) {
			$diagnostics['issues'][] = 'Not authenticated with Google - OAuth flow not completed';
			$diagnostics['steps'][]  = 'Click "Connect to Google Search Console" button after adding credentials';
		} elseif ( empty( $this->access_token ) && ! empty( $this->refresh_token ) ) {
			$diagnostics['warnings'][] = 'Access token expired - will auto-refresh on next API call';
		}

		// 3. Test API Connection (if authenticated)
		if ( $this->is_authenticated() ) {
			try {
				$properties                                  = $this->get_site_properties();
				$diagnostics['info']['properties_count']     = count( $properties );
				$diagnostics['info']['available_properties'] = array_column( $properties, 'url' );

				if ( count( $properties ) === 0 ) {
					$diagnostics['issues'][] = 'No GSC properties found - verify your Google account has Search Console access';
					$diagnostics['steps'][]  = 'Add your site to Google Search Console first';
				}

				// Check for site match
				$site_match = false;
				foreach ( $properties as $property ) {
					if ( $this->normalize_url( $property['url'] ) === $this->normalize_url( $this->site_url ) ) {
						$site_match                               = true;
						$diagnostics['info']['matching_property'] = $property['url'];
						break;
					}
				}

				if ( ! $site_match ) {
					$diagnostics['warnings'][] = 'Your site URL does not exactly match any GSC property';
					$diagnostics['warnings'][] = 'Site: ' . $this->site_url;
					$diagnostics['warnings'][] = 'Properties: ' . implode( ', ', $diagnostics['info']['available_properties'] );
					$diagnostics['steps'][]    = 'Verify your WordPress site URL matches a GSC property (including http/https and www)';
				}
			} catch ( Exception $e ) {
				$diagnostics['issues'][] = 'API Connection Error: ' . $e->getMessage();

				if ( strpos( $e->getMessage(), '401' ) !== false || strpos( $e->getMessage(), 'invalid_grant' ) !== false ) {
					$diagnostics['steps'][] = 'Re-authenticate: Disconnect and reconnect to Google Search Console';
				} elseif ( strpos( $e->getMessage(), '403' ) !== false ) {
					$diagnostics['steps'][] = 'Check API permissions in Google Cloud Console - ensure Search Console API is enabled';
				} elseif ( strpos( $e->getMessage(), 'refresh token' ) !== false ) {
					$diagnostics['steps'][] = 'Refresh token invalid - disconnect and reconnect';
				}
			}
		}

		// 4. Check WordPress Configuration
		if ( get_option( 'permalink_structure' ) === '' ) {
			$diagnostics['warnings'][] = 'Using plain permalinks - GSC typically does not track URLs like ?p=123';
			$diagnostics['steps'][]    = 'Change to SEO-friendly permalinks in Settings > Permalinks';
		}

		// 5. Check for domain vs URL-prefix property
		if ( isset( $diagnostics['info']['available_properties'] ) ) {
			foreach ( $diagnostics['info']['available_properties'] as $prop ) {
				if ( strpos( $prop, 'sc-domain:' ) === 0 ) {
					$diagnostics['warnings'][] = 'Domain property detected: ' . $prop . ' - This plugin works best with URL-prefix properties';
					$diagnostics['steps'][]    = 'Add a URL-prefix property in GSC (e.g., https://example.com/)';
				}
			}
		}

		// 6. Determine Overall Status
		if ( count( $diagnostics['issues'] ) === 0 && $this->is_authenticated() ) {
			$diagnostics['status']  = 'healthy';
			$diagnostics['message'] = 'GSC connection is configured and working';
		} elseif ( ! empty( $this->client_id ) && ! empty( $this->client_secret ) && ! $this->is_authenticated() ) {
			$diagnostics['status']  = 'needs_auth';
			$diagnostics['message'] = 'Credentials configured but not authenticated';
		} elseif ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
			$diagnostics['status']  = 'not_configured';
			$diagnostics['message'] = 'OAuth credentials not configured';
		} else {
			$diagnostics['status']  = 'error';
			$diagnostics['message'] = 'Connection issues detected';
		}

		return $diagnostics;
	}
}
