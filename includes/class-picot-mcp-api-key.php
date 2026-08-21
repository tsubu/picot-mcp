<?php
/**
 * API key generation and verification (multiple keys).
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Api_Key class.
 */
class Picot_Mcp_Api_Key {

	const PREFIX = 'pmcp_';

	/**
	 * Generate a new API key bound to a WordPress user.
	 *
	 * @param int         $user_id     User ID to act as.
	 * @param string      $label       Optional label.
	 * @param array|null  $permissions Optional feature map; defaults to site settings.
	 * @param array|null  $operations  Optional operation map; defaults to site settings.
	 * @return array{plaintext:string,token:array}|WP_Error
	 */
	public static function generate( $user_id, $label = '', $permissions = null, $operations = null ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'A valid user is required to issue an API key.', 'picot-mcp' ) );
		}

		if ( ! Picot_Mcp_Settings::instance()->is_user_allowed_for_keys( $user_id ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'This user is not allowed for MCP API keys.', 'picot-mcp' ) );
		}

		try {
			$random = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $e ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to generate a secure API key.', 'picot-mcp' ) );
		}

		$settings = Picot_Mcp_Settings::instance()->all();
		if ( is_array( $permissions ) ) {
			$perm_map = self::sanitize_permissions_map( $permissions );
		} else {
			$perm_map = isset( $settings['permissions'] ) && is_array( $settings['permissions'] )
				? self::sanitize_permissions_map( $settings['permissions'] )
				: self::sanitize_permissions_map( array() );
		}
		if ( is_array( $operations ) ) {
			$op_map = self::sanitize_operations_map( $operations );
		} else {
			$op_map = isset( $settings['operations'] ) && is_array( $settings['operations'] )
				? self::sanitize_operations_map( $settings['operations'] )
				: self::sanitize_operations_map( array() );
		}

		$plaintext = self::PREFIX . $random;
		$secret    = self::encrypt( $plaintext );
		if ( '' === $secret ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to encrypt the API key for storage.', 'picot-mcp' ) );
		}

		$token = array(
			'token_id'     => wp_generate_uuid4(),
			'hash'         => self::hash( $plaintext ),
			'secret'       => $secret,
			'prefix'       => self::PREFIX . substr( $random, 0, 4 ),
			'user_id'      => $user_id,
			'label'        => sanitize_text_field( $label ),
			'enabled'      => true,
			'permissions'  => $perm_map,
			'operations'   => $op_map,
			'created_at'   => time(),
			'last_used_at' => null,
		);

		$tokens   = Picot_Mcp_Settings::instance()->get_tokens();
		$tokens[] = $token;
		if ( ! Picot_Mcp_Settings::instance()->save_tokens( $tokens ) ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to store the API key.', 'picot-mcp' ) );
		}

		return array(
			'plaintext' => $plaintext,
			'token'     => $token,
		);
	}

	/**
	 * Update an existing API key's label, user, features, and operations.
	 *
	 * @param string $token_id    Token id.
	 * @param array  $attributes  label, user_id, permissions, operations.
	 * @return true|WP_Error
	 */
	public static function update( $token_id, array $attributes ) {
		$token_id = (string) $token_id;
		$tokens   = Picot_Mcp_Settings::instance()->get_tokens();
		$found    = false;

		foreach ( $tokens as $i => $token ) {
			if ( empty( $token['token_id'] ) || $token['token_id'] !== $token_id ) {
				continue;
			}
			$found = true;

			if ( isset( $attributes['label'] ) ) {
				$tokens[ $i ]['label'] = sanitize_text_field( $attributes['label'] );
			}

			if ( isset( $attributes['user_id'] ) ) {
				$user_id = absint( $attributes['user_id'] );
				if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
					return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'A valid user is required.', 'picot-mcp' ) );
				}
				if ( ! Picot_Mcp_Settings::instance()->is_user_allowed_for_keys( $user_id ) ) {
					return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'This user is not allowed for MCP API keys.', 'picot-mcp' ) );
				}
				$tokens[ $i ]['user_id'] = $user_id;
			}

			if ( isset( $attributes['permissions'] ) && is_array( $attributes['permissions'] ) ) {
				$tokens[ $i ]['permissions'] = self::sanitize_permissions_map( $attributes['permissions'] );
			}

			if ( isset( $attributes['operations'] ) && is_array( $attributes['operations'] ) ) {
				$tokens[ $i ]['operations'] = self::sanitize_operations_map( $attributes['operations'] );
			}

			break;
		}

		if ( ! $found ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'API key not found.', 'picot-mcp' ) );
		}

		if ( ! Picot_Mcp_Settings::instance()->save_tokens( $tokens ) ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to save the API key.', 'picot-mcp' ) );
		}

		return true;
	}

	/**
	 * Sanitize a feature map.
	 *
	 * @param array $map Raw map.
	 * @return array
	 */
	public static function sanitize_permissions_map( array $map ) {
		$keys   = array( 'content', 'taxonomy', 'media', 'settings', 'plugins', 'themes', 'users' );
		$clean  = array();
		foreach ( $keys as $key ) {
			$clean[ $key ] = ! empty( $map[ $key ] );
		}
		return $clean;
	}

	/**
	 * Sanitize an operations map.
	 *
	 * @param array $map Raw map.
	 * @return array
	 */
	public static function sanitize_operations_map( array $map ) {
		$keys  = array( 'read', 'write', 'critical', 'zip_install' );
		$clean = array();
		foreach ( $keys as $key ) {
			$clean[ $key ] = ! empty( $map[ $key ] );
		}
		return $clean;
	}

	/**
	 * Revoke one API key by token_id.
	 *
	 * @param string $token_id Token id.
	 * @return bool
	 */
	public static function revoke( $token_id ) {
		$token_id = (string) $token_id;
		if ( '' === $token_id ) {
			return false;
		}
		$tokens = Picot_Mcp_Settings::instance()->get_tokens();
		$next   = array();
		$found  = false;
		foreach ( $tokens as $token ) {
			if ( empty( $token['token_id'] ) || $token['token_id'] !== $token_id ) {
				$next[] = $token;
				continue;
			}
			$found = true;
		}
		if ( ! $found ) {
			return false;
		}
		return Picot_Mcp_Settings::instance()->save_tokens( $next );
	}

	/**
	 * Hash an API key using WordPress salts (verification only; not reversible).
	 *
	 * @param string $plaintext Plaintext API key.
	 * @return string
	 */
	public static function hash( $plaintext ) {
		return hash_hmac( 'sha256', $plaintext, wp_salt( 'auth' ) );
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string Binary key material.
	 */
	private static function encryption_key() {
		// Keep this stable so previously stored secrets remain decryptable.
		return hash( 'sha256', wp_salt( 'auth' ) . '|picot-mcp-key', true );
	}

	/**
	 * Encrypt plaintext so admins can later decrypt/copy it.
	 *
	 * Simple reversible format: "v1:" + base64( iv[16] + ciphertext ).
	 * Auth uses hash(); this secret is only for admin reveal/copy.
	 *
	 * @param string $plaintext Plaintext API key.
	 * @return string Encrypted payload or empty on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $e ) {
			return '';
		}

		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', self::encryption_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}

		return 'v1:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored secret back to the plaintext API key.
	 *
	 * Supports current "v1:" payloads and legacy unversioned base64(iv+cipher).
	 *
	 * @param string $payload Payload from encrypt().
	 * @return string Plaintext or empty string.
	 */
	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw_b64 = $payload;
		if ( 0 === strpos( $payload, 'v1:' ) ) {
			$raw_b64 = substr( $payload, 3 );
		}

		$raw = base64_decode( $raw_b64, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Whether a token has an encrypted secret that can be decrypted for copy.
	 *
	 * @param array $token Token row.
	 * @return bool
	 */
	public static function has_copyable_secret( array $token ) {
		return '' !== self::reveal_plaintext( $token );
	}

	/**
	 * Reveal plaintext for an admin copy action (decrypt + hash check).
	 *
	 * @param array $token Token row.
	 * @return string
	 */
	public static function reveal_plaintext( array $token ) {
		if ( empty( $token['secret'] ) || ! is_string( $token['secret'] ) ) {
			return '';
		}
		$plain = self::decrypt( $token['secret'] );
		if ( '' === $plain ) {
			return '';
		}
		// Integrity: decrypted value must match the verification hash.
		if ( empty( $token['hash'] ) || ! hash_equals( (string) $token['hash'], self::hash( $plain ) ) ) {
			return '';
		}
		return $plain;
	}

	/**
	 * Verify a plaintext key against stored hashes.
	 *
	 * @param string $plaintext Plaintext API key from request.
	 * @return array|WP_Error Token data on success.
	 */
	public static function verify( $plaintext ) {
		if ( ! is_string( $plaintext ) || 0 !== strpos( $plaintext, self::PREFIX ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'Invalid API key.', 'picot-mcp' ) );
		}

		$tokens = Picot_Mcp_Settings::instance()->get_tokens();
		if ( empty( $tokens ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'No API key is configured.', 'picot-mcp' ) );
		}

		$candidate = self::hash( $plaintext );
		$matched   = null;
		foreach ( $tokens as $token ) {
			if ( empty( $token['enabled'] ) || empty( $token['hash'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $token['hash'], $candidate ) ) {
				$matched = $token;
				break;
			}
		}

		if ( ! $matched ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'Invalid API key.', 'picot-mcp' ) );
		}

		$user_id = (int) $matched['user_id'];
		if ( ! get_user_by( 'id', $user_id ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'API key owner no longer exists.', 'picot-mcp' ) );
		}

		if ( ! Picot_Mcp_Settings::instance()->is_user_allowed_for_keys( $user_id ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'API key owner is no longer allowed.', 'picot-mcp' ) );
		}

		self::touch_last_used( $matched );

		return $matched;
	}

	/**
	 * Update last_used_at.
	 *
	 * @param array $token Token data.
	 * @return void
	 */
	private static function touch_last_used( array $token ) {
		$now = time();
		if ( isset( $token['last_used_at'] ) && is_numeric( $token['last_used_at'] ) && ( $now - (int) $token['last_used_at'] ) < 60 ) {
			return;
		}
		if ( empty( $token['token_id'] ) ) {
			return;
		}
		Picot_Mcp_Settings::instance()->touch_token_last_used_by_id( $token['token_id'], $now );
	}

	/**
	 * Masked display string for admin UI.
	 *
	 * @param array|null $token Token metadata.
	 * @return string
	 */
	public static function masked_display( $token ) {
		if ( empty( $token ) || empty( $token['prefix'] ) ) {
			return '';
		}
		return $token['prefix'] . str_repeat( '•', 20 );
	}

	/**
	 * Users eligible to be selected as API key owners.
	 *
	 * @return WP_User[]
	 */
	public static function eligible_users() {
		$args = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 200,
		);

		$allowed = Picot_Mcp_Settings::instance()->allowed_user_ids();
		if ( ! empty( $allowed ) ) {
			$args['include'] = $allowed;
		} else {
			// Default pool: users who can at least edit posts (content operators).
			$args['capability'] = array( 'edit_posts' );
		}

		return get_users( $args );
	}
}
