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
	 * Plaintext is returned once and never stored (hash only).
	 *
	 * @param int        $user_id     User ID to act as.
	 * @param string     $label       Optional label.
	 * @param array|null $permissions Optional feature map; defaults to site settings.
	 * @param array|null $operations  Optional operation map; defaults to site settings.
	 * @param int|null   $expires_at  Optional Unix expiry timestamp (null = never).
	 * @return array{plaintext:string,token:array}|WP_Error
	 */
	public static function generate( $user_id, $label = '', $permissions = null, $operations = null, $expires_at = null ) {
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
		$expires   = self::sanitize_expires_at( $expires_at );

		$token = array(
			'token_id'     => wp_generate_uuid4(),
			'hash'         => self::hash( $plaintext ),
			'prefix'       => self::PREFIX . substr( $random, 0, 4 ),
			'user_id'      => $user_id,
			'label'        => sanitize_text_field( $label ),
			'enabled'      => true,
			'permissions'  => $perm_map,
			'operations'   => $op_map,
			'created_at'   => time(),
			'expires_at'   => $expires,
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
	 * Remove reversible secrets from all stored tokens (hash-only).
	 *
	 * @return void
	 */
	public static function strip_stored_secrets() {
		$tokens = get_option( Picot_Mcp_Settings::TOKENS_OPTION, array() );
		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return;
		}
		$changed = false;
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( array_key_exists( 'secret', $token ) ) {
				unset( $tokens[ $i ]['secret'] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( Picot_Mcp_Settings::TOKENS_OPTION, array_values( $tokens ), false );
		}
	}

	/**
	 * Sanitize optional expiry timestamp.
	 *
	 * @param mixed $expires_at Unix timestamp or null.
	 * @return int|null
	 */
	public static function sanitize_expires_at( $expires_at ) {
		if ( null === $expires_at || '' === $expires_at || false === $expires_at ) {
			return null;
		}
		$ts = (int) $expires_at;
		if ( $ts < 1 ) {
			return null;
		}
		return $ts;
	}

	/**
	 * Days until expiry from create form (0 = never).
	 *
	 * @param mixed $days Days.
	 * @return int|null Unix timestamp or null.
	 */
	public static function expires_at_from_days( $days ) {
		$days = absint( $days );
		if ( $days < 1 ) {
			return null;
		}
		if ( $days > 3650 ) {
			$days = 3650;
		}
		return time() + ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Update an existing API key's label, user, features, operations, expiry.
	 *
	 * @param string $token_id   Token id.
	 * @param array  $attributes Attributes.
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

			if ( array_key_exists( 'expires_at', $attributes ) ) {
				$tokens[ $i ]['expires_at'] = self::sanitize_expires_at( $attributes['expires_at'] );
			}

			if ( array_key_exists( 'secret', $tokens[ $i ] ) ) {
				unset( $tokens[ $i ]['secret'] );
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
		$keys  = Picot_Mcp_Settings::feature_keys();
		$clean = array();
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
		$keys  = array( 'read', 'write', 'critical' );
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
	 * Keys are not re-displayable after issue (hash-only storage).
	 *
	 * @param array $token Token row.
	 * @return bool
	 */
	public static function has_copyable_secret( array $token ) {
		unset( $token );
		return false;
	}

	/**
	 * Reveal plaintext — always empty (issue-once policy).
	 *
	 * @param array $token Token row.
	 * @return string
	 */
	public static function reveal_plaintext( array $token ) {
		unset( $token );
		return '';
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

		if ( ! empty( $matched['expires_at'] ) && (int) $matched['expires_at'] > 0 && time() > (int) $matched['expires_at'] ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'API key has expired.', 'picot-mcp' ) );
		}

		$user_id = (int) $matched['user_id'];
		if ( ! get_user_by( 'id', $user_id ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'API key owner no longer exists.', 'picot-mcp' ) );
		}

		if ( ! Picot_Mcp_Settings::instance()->is_user_allowed_for_keys( $user_id ) ) {
			return Picot_Mcp_Errors::make( 'invalid_api_key', __( 'API key owner is no longer allowed.', 'picot-mcp' ) );
		}

		$rate = self::check_rate_limit( $matched );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		self::touch_last_used( $matched );

		return $matched;
	}

	/**
	 * Per-key request rate limit.
	 *
	 * @param array $token Token data.
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( array $token ) {
		$limit = Picot_Mcp_Settings::instance()->rate_limit_per_minute();
		if ( $limit < 1 || empty( $token['token_id'] ) ) {
			return true;
		}

		$key   = 'picot_mcp_rl_' . md5( (string) $token['token_id'] );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return Picot_Mcp_Errors::make( 'rate_limited', __( 'API key rate limit exceeded. Try again shortly.', 'picot-mcp' ) );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
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
			$args['capability'] = array( 'edit_posts' );
		}

		return get_users( $args );
	}
}
