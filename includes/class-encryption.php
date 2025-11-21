<?php
/**
 * Encryption Helper for FreshRank.ai
 * Handles encryption/decryption of sensitive credentials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshRank_Encryption {

	/**
	 * Get encryption key from WordPress salts
	 * Uses a combination of auth salts for stronger key generation
	 */
	private static function get_key() {
		// Combine multiple WordPress salts for stronger encryption
		$salt_string = wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ) . wp_salt( 'nonce' );

		// Hash the combined salts to get a consistent 32-byte key for AES-256
		return hash( 'sha256', $salt_string, true );
	}

	/**
	 * Encrypt a value
	 *
	 * @param string $value The value to encrypt
	 * @return string The encrypted value with prefix
	 */
	public static function encrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Check if OpenSSL is available
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// SECURITY: Do NOT allow insecure fallback - OpenSSL is required
			error_log( 'FreshRank.ai: CRITICAL - OpenSSL not available. Cannot encrypt API keys securely.' );
			throw new Exception( __( 'OpenSSL is required for secure API key storage. Please enable the OpenSSL extension on your server.', 'freshrank-ai' ) );
		}

		try {
			$key    = self::get_key();
			$cipher = 'aes-256-cbc';

			// Generate random IV
			$iv_length = openssl_cipher_iv_length( $cipher );
			$iv        = openssl_random_pseudo_bytes( $iv_length );

			// Encrypt the data
			$encrypted = openssl_encrypt( $value, $cipher, $key, OPENSSL_RAW_DATA, $iv );

			if ( $encrypted === false ) {
				error_log( 'FreshRank.ai: Encryption failed - ' . openssl_error_string() );
				throw new Exception( __( 'Failed to encrypt data. Please check server configuration.', 'freshrank-ai' ) );
			}

			// Combine IV and encrypted data, then base64 encode
			$combined = $iv . $encrypted;

			return 'encrypted:' . base64_encode( $combined );

		} catch ( Exception $e ) {
			error_log( 'FreshRank.ai: Encryption error - ' . $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Decrypt a value
	 *
	 * @param string $value The encrypted value
	 * @return string The decrypted value
	 */
	public static function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Handle unencrypted legacy values (backward compatibility)
		if ( strpos( $value, 'encrypted:' ) !== 0 && strpos( $value, 'base64:' ) !== 0 ) {
			return $value;
		}

		// Handle base64 fallback (DEPRECATED - backward compatibility only)
		if ( strpos( $value, 'base64:' ) === 0 ) {
			error_log( 'FreshRank.ai: WARNING - API key stored with insecure base64 encoding. Please re-save your API key in Settings to use secure encryption.' );
			return base64_decode( substr( $value, 7 ) );
		}

		// Handle encrypted values - OpenSSL is required
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			error_log( 'FreshRank.ai: CRITICAL - OpenSSL not available for decryption.' );
			throw new Exception( __( 'OpenSSL is required to decrypt stored API keys. Please enable the OpenSSL extension on your server.', 'freshrank-ai' ) );
		}

		try {
			// Remove the 'encrypted:' prefix
			$encoded  = substr( $value, 10 );
			$combined = base64_decode( $encoded );

			if ( $combined === false ) {
				return '';
			}

			$key       = self::get_key();
			$cipher    = 'aes-256-cbc';
			$iv_length = openssl_cipher_iv_length( $cipher );

			// Extract IV and encrypted data
			$iv             = substr( $combined, 0, $iv_length );
			$encrypted_data = substr( $combined, $iv_length );

			// Decrypt
			$decrypted = openssl_decrypt( $encrypted_data, $cipher, $key, OPENSSL_RAW_DATA, $iv );

			if ( $decrypted === false ) {
				return '';
			}

			return $decrypted;

		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Check if a value is encrypted
	 *
	 * @param string $value The value to check
	 * @return bool True if encrypted, false otherwise
	 */
	public static function is_encrypted( $value ) {
		return strpos( $value, 'encrypted:' ) === 0 || strpos( $value, 'base64:' ) === 0;
	}

	/**
	 * Test encryption/decryption functionality
	 *
	 * @return array Test results
	 */
	public static function test_encryption() {
		$test_value = 'test_secret_12345';

		$encrypted = self::encrypt( $test_value );
		$decrypted = self::decrypt( $encrypted );

		$result = array(
			'openssl_available' => function_exists( 'openssl_encrypt' ),
			'encryption_method' => strpos( $encrypted, 'encrypted:' ) === 0 ? 'OpenSSL AES-256-CBC' : 'Base64 fallback',
			'test_passed'       => $decrypted === $test_value,
			'encrypted_length'  => strlen( $encrypted ),
			'original_length'   => strlen( $test_value ),
		);

		return $result;
	}
}
