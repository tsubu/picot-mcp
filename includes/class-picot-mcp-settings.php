<?php
/**
 * Plugin settings storage.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Settings class.
 */
class Picot_Mcp_Settings {

	const TOKEN_OPTION  = 'picot_mcp_token';
	const TOKENS_OPTION = 'picot_mcp_tokens';
	const SCHEMA_VERSION = 8;

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Settings|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Cached tokens list.
	 *
	 * @var array|null|false
	 */
	private $tokens_cache = false;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default settings (safe for new installs).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'               => false,
			'route_namespace'       => 'picot-mcp',
			'route'                 => 'mcp-server',
			'allowed_user_ids'      => array(),
			'permissions'           => array(
				'content'           => true,
				'taxonomy'          => true,
				'media'             => true,
				'settings'          => false,
				'plugins'           => false,
				'themes'            => false,
				'users'             => false,
				'seo_writer'        => false,
				'aio_optimizer'     => false,
				'editor_converter'  => false,
			),
			'operations'            => array(
				'read'     => true,
				'write'    => false,
				'critical' => false,
			),
			'log_retention'         => 200,
			'rate_limit_per_minute' => 120,
			'observability'         => 'null',
			'schema_version'        => self::SCHEMA_VERSION,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored   = get_option( PICOT_MCP_OPTION, array() );
		$defaults = self::defaults();

		// Migrate legacy embedded token.
		if ( is_array( $stored ) && array_key_exists( 'token', $stored ) ) {
			$legacy = $stored['token'];
			unset( $stored['token'] );
			update_option( PICOT_MCP_OPTION, wp_parse_args( $stored, $defaults ), false );
			if ( is_array( $legacy ) && ! empty( $legacy['hash'] ) ) {
				$this->migrate_legacy_token( $legacy );
			}
		}

		$this->migrate_single_token_option();

		$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
		$this->cache['permissions'] = wp_parse_args(
			isset( $this->cache['permissions'] ) && is_array( $this->cache['permissions'] ) ? $this->cache['permissions'] : array(),
			$defaults['permissions']
		);
		$this->cache['operations'] = wp_parse_args(
			isset( $this->cache['operations'] ) && is_array( $this->cache['operations'] ) ? $this->cache['operations'] : array(),
			$defaults['operations']
		);
		unset( $this->cache['operations']['zip_install'] );

		$this->cache['route_namespace'] = self::sanitize_route_segment(
			isset( $this->cache['route_namespace'] ) ? $this->cache['route_namespace'] : $defaults['route_namespace'],
			$defaults['route_namespace']
		);
		$this->cache['route'] = self::sanitize_route_segment(
			isset( $this->cache['route'] ) ? $this->cache['route'] : $defaults['route'],
			$defaults['route']
		);

		$allowed = array();
		if ( ! empty( $this->cache['allowed_user_ids'] ) && is_array( $this->cache['allowed_user_ids'] ) ) {
			foreach ( $this->cache['allowed_user_ids'] as $uid ) {
				$uid = absint( $uid );
				if ( $uid > 0 ) {
					$allowed[] = $uid;
				}
			}
		}
		$this->cache['allowed_user_ids'] = array_values( array_unique( $allowed ) );

		$this->cache['log_retention'] = self::sanitize_log_retention(
			isset( $this->cache['log_retention'] ) ? $this->cache['log_retention'] : $defaults['log_retention']
		);
		$this->cache['rate_limit_per_minute'] = self::sanitize_rate_limit(
			isset( $this->cache['rate_limit_per_minute'] ) ? $this->cache['rate_limit_per_minute'] : $defaults['rate_limit_per_minute']
		);
		$this->cache['observability'] = self::sanitize_observability(
			isset( $this->cache['observability'] ) ? $this->cache['observability'] : $defaults['observability']
		);

		$this->maybe_migrate_schema();

		return $this->cache;
	}

	/**
	 * Schema migrations (never force-open dangerous site ceilings).
	 *
	 * @return void
	 */
	private function maybe_migrate_schema() {
		$version = isset( $this->cache['schema_version'] ) ? (int) $this->cache['schema_version'] : 0;
		if ( $version >= self::SCHEMA_VERSION ) {
			return;
		}

		// v5 historically force-enabled all checkboxes; do not repeat that for v6+.
		// v7: drop removed zip_install operation from site ceilings.
		// v8: add product integration features (default off).
		if ( isset( $this->cache['operations'] ) && is_array( $this->cache['operations'] ) ) {
			unset( $this->cache['operations']['zip_install'] );
		}
		$defaults_perm = self::defaults()['permissions'];
		if ( ! isset( $this->cache['permissions'] ) || ! is_array( $this->cache['permissions'] ) ) {
			$this->cache['permissions'] = $defaults_perm;
		} else {
			$this->cache['permissions'] = wp_parse_args( $this->cache['permissions'], $defaults_perm );
		}

		$this->cache['schema_version'] = self::SCHEMA_VERSION;
		update_option( PICOT_MCP_OPTION, $this->cache, false );

		// Strip reversible API key secrets (hash-only storage).
		Picot_Mcp_Api_Key::strip_stored_secrets();

		// Normalize tokens so removed operation keys (e.g. zip_install) are purged from storage.
		$this->get_tokens();
	}

	/**
	 * Sanitize log retention count.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_log_retention( $value ) {
		$n = absint( $value );
		if ( $n < 10 ) {
			$n = 10;
		}
		if ( $n > 500 ) {
			$n = 500;
		}
		return $n;
	}

	/**
	 * Sanitize rate limit (0 = disabled).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_rate_limit( $value ) {
		$n = absint( $value );
		if ( $n > 10000 ) {
			$n = 10000;
		}
		return $n;
	}

	/**
	 * Sanitize observability mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_observability( $value ) {
		$value = is_string( $value ) ? $value : 'null';
		return in_array( $value, array( 'null', 'error_log' ), true ) ? $value : 'null';
	}

	/**
	 * Sanitize a REST path segment (namespace or route).
	 *
	 * @param string $value   Raw value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function sanitize_route_segment( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9\-_]/', '', $value );
		$value = trim( (string) $value, '-_' );
		if ( '' === $value || strlen( $value ) > 64 ) {
			return $fallback;
		}
		return $value;
	}

	/**
	 * Migrate legacy single token option into tokens list.
	 *
	 * @return void
	 */
	private function migrate_single_token_option() {
		$single = get_option( self::TOKEN_OPTION, null );
		if ( ! is_array( $single ) || empty( $single['hash'] ) ) {
			return;
		}
		$tokens = get_option( self::TOKENS_OPTION, null );
		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			$single['label']   = isset( $single['label'] ) ? $single['label'] : __( 'Default', 'picot-mcp' );
			$single['enabled'] = true;
			add_option( self::TOKENS_OPTION, array( $single ), '', false );
		}
		delete_option( self::TOKEN_OPTION );
	}

	/**
	 * Migrate a legacy token array into the tokens list.
	 *
	 * @param array $legacy Legacy token.
	 * @return void
	 */
	private function migrate_legacy_token( array $legacy ) {
		$tokens = get_option( self::TOKENS_OPTION, null );
		if ( is_array( $tokens ) && ! empty( $tokens ) ) {
			return;
		}
		$legacy['label']   = __( 'Default', 'picot-mcp' );
		$legacy['enabled'] = true;
		if ( false === get_option( self::TOKENS_OPTION, false ) ) {
			add_option( self::TOKENS_OPTION, array( $legacy ), '', false );
		}
	}

	/**
	 * Get a top-level setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( 'token' === $key ) {
			$tokens = $this->get_tokens();
			return ! empty( $tokens[0] ) ? $tokens[0] : $default;
		}
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether MCP server is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->get( 'enabled' ) );
	}

	/**
	 * REST namespace for MCP.
	 *
	 * @return string
	 */
	public function route_namespace() {
		return (string) $this->get( 'route_namespace', 'picot-mcp' );
	}

	/**
	 * REST route for MCP.
	 *
	 * @return string
	 */
	public function route() {
		return (string) $this->get( 'route', 'mcp-server' );
	}

	/**
	 * Log retention count.
	 *
	 * @return int
	 */
	public function log_retention() {
		return self::sanitize_log_retention( $this->get( 'log_retention', 200 ) );
	}

	/**
	 * Requests per minute per API key (0 = off).
	 *
	 * @return int
	 */
	public function rate_limit_per_minute() {
		return self::sanitize_rate_limit( $this->get( 'rate_limit_per_minute', 120 ) );
	}

	/**
	 * Observability mode.
	 *
	 * @return string
	 */
	public function observability() {
		return self::sanitize_observability( $this->get( 'observability', 'null' ) );
	}

	/**
	 * Allowed user IDs for API key binding (empty = any eligible user).
	 *
	 * @return int[]
	 */
	public function allowed_user_ids() {
		$ids = $this->get( 'allowed_user_ids', array() );
		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Whether a user may be bound to an API key.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_user_allowed_for_keys( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}
		$allowed = $this->allowed_user_ids();
		if ( ! empty( $allowed ) ) {
			return in_array( $user_id, $allowed, true );
		}
		return user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Feature key => admin label (includes product integrations).
	 *
	 * @return array<string, string>
	 */
	public static function feature_labels() {
		return array(
			'content'          => __( 'Posts & pages', 'picot-mcp' ),
			'taxonomy'         => __( 'Categories & tags', 'picot-mcp' ),
			'media'            => __( 'Media', 'picot-mcp' ),
			'settings'         => __( 'Site settings', 'picot-mcp' ),
			'plugins'          => __( 'Plugins', 'picot-mcp' ),
			'themes'           => __( 'Themes', 'picot-mcp' ),
			'users'            => __( 'Users', 'picot-mcp' ),
			'seo_writer'       => __( 'Picot AI SEO Writer', 'picot-mcp' ),
			'aio_optimizer'    => __( 'Picot AIO AI Content Optimizer', 'picot-mcp' ),
			'editor_converter' => __( 'Picot Editor Converter', 'picot-mcp' ),
		);
	}

	/**
	 * Permission feature keys in stable order.
	 *
	 * @return string[]
	 */
	public static function feature_keys() {
		return array_keys( self::defaults()['permissions'] );
	}

	/**
	 * Whether a feature category is enabled.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function is_feature_enabled( $feature ) {
		$permissions = $this->get( 'permissions', array() );
		return ! empty( $permissions[ $feature ] );
	}

	/**
	 * Whether an operation level is enabled.
	 *
	 * @param string $operation Operation key.
	 * @return bool
	 */
	public function is_operation_allowed( $operation ) {
		$operations = $this->get( 'operations', array() );
		return ! empty( $operations[ $operation ] );
	}

	/**
	 * Get all API key token metadata (no plaintext).
	 *
	 * @return array[]
	 */
	public function get_tokens() {
		if ( false !== $this->tokens_cache ) {
			return $this->tokens_cache;
		}
		$this->all(); // Ensure migrations ran.
		$tokens = get_option( self::TOKENS_OPTION, array() );
		$tokens = is_array( $tokens ) ? array_values( $tokens ) : array();
		$tokens = $this->normalize_tokens( $tokens );
		$this->tokens_cache = $tokens;
		return $this->tokens_cache;
	}

	/**
	 * Ensure every token has explicit permissions/operations maps.
	 *
	 * @param array $tokens Raw tokens.
	 * @return array
	 */
	private function normalize_tokens( array $tokens ) {
		$settings = $this->cache ? $this->cache : self::defaults();
		$changed  = false;
		$site_perm = isset( $settings['permissions'] ) && is_array( $settings['permissions'] )
			? Picot_Mcp_Api_Key::sanitize_permissions_map( $settings['permissions'] )
			: Picot_Mcp_Api_Key::sanitize_permissions_map( array() );
		$site_ops = isset( $settings['operations'] ) && is_array( $settings['operations'] )
			? Picot_Mcp_Api_Key::sanitize_operations_map( $settings['operations'] )
			: Picot_Mcp_Api_Key::sanitize_operations_map( array() );

		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( empty( $token['permissions'] ) || ! is_array( $token['permissions'] ) ) {
				$tokens[ $i ]['permissions'] = $site_perm;
				$changed                     = true;
			} else {
				$sanitized = Picot_Mcp_Api_Key::sanitize_permissions_map( $token['permissions'] );
				if ( $sanitized !== $token['permissions'] ) {
					$tokens[ $i ]['permissions'] = $sanitized;
					$changed                     = true;
				}
			}
			if ( empty( $token['operations'] ) || ! is_array( $token['operations'] ) ) {
				$tokens[ $i ]['operations'] = $site_ops;
				$changed                    = true;
			} else {
				$sanitized = Picot_Mcp_Api_Key::sanitize_operations_map( $token['operations'] );
				if ( $sanitized !== $token['operations'] ) {
					$tokens[ $i ]['operations'] = $sanitized;
					$changed                    = true;
				}
			}
			if ( array_key_exists( 'secret', $token ) ) {
				unset( $tokens[ $i ]['secret'] );
				$changed = true;
			}
			if ( ! array_key_exists( 'expires_at', $tokens[ $i ] ) ) {
				$tokens[ $i ]['expires_at'] = null;
				$changed                    = true;
			}
		}

		if ( $changed ) {
			update_option( self::TOKENS_OPTION, $tokens, false );
		}

		return $tokens;
	}

	/**
	 * Back-compat: first token or null.
	 *
	 * @return array|null
	 */
	public function get_token() {
		$tokens = $this->get_tokens();
		return ! empty( $tokens[0] ) ? $tokens[0] : null;
	}

	/**
	 * Persist settings.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public function save( array $settings ) {
		$defaults = self::defaults();
		$clean    = array(
			'enabled'               => ! empty( $settings['enabled'] ),
			'route_namespace'       => self::sanitize_route_segment(
				isset( $settings['route_namespace'] ) ? $settings['route_namespace'] : $defaults['route_namespace'],
				$defaults['route_namespace']
			),
			'route'                 => self::sanitize_route_segment(
				isset( $settings['route'] ) ? $settings['route'] : $defaults['route'],
				$defaults['route']
			),
			'allowed_user_ids'      => array(),
			'permissions'           => array(),
			'operations'            => array(),
			'log_retention'         => self::sanitize_log_retention(
				isset( $settings['log_retention'] ) ? $settings['log_retention'] : $defaults['log_retention']
			),
			'rate_limit_per_minute' => self::sanitize_rate_limit(
				isset( $settings['rate_limit_per_minute'] ) ? $settings['rate_limit_per_minute'] : $defaults['rate_limit_per_minute']
			),
			'observability'         => self::sanitize_observability(
				isset( $settings['observability'] ) ? $settings['observability'] : $defaults['observability']
			),
			'schema_version'        => self::SCHEMA_VERSION,
		);

		if ( ! empty( $settings['allowed_user_ids'] ) && is_array( $settings['allowed_user_ids'] ) ) {
			foreach ( $settings['allowed_user_ids'] as $uid ) {
				$uid = absint( $uid );
				if ( $uid > 0 ) {
					$clean['allowed_user_ids'][] = $uid;
				}
			}
			$clean['allowed_user_ids'] = array_values( array_unique( $clean['allowed_user_ids'] ) );
		}

		foreach ( array_keys( $defaults['permissions'] ) as $key ) {
			$clean['permissions'][ $key ] = ! empty( $settings['permissions'][ $key ] );
		}
		foreach ( array_keys( $defaults['operations'] ) as $key ) {
			$clean['operations'][ $key ] = ! empty( $settings['operations'][ $key ] );
		}

		$this->cache = null;
		$updated     = update_option( PICOT_MCP_OPTION, $clean, false );
		$this->cache = $clean;
		return $updated || get_option( PICOT_MCP_OPTION ) == $clean; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	/**
	 * Replace the full tokens list.
	 *
	 * @param array $tokens Token list.
	 * @return bool
	 */
	public function save_tokens( array $tokens ) {
		$this->tokens_cache = false;
		$existing           = get_option( self::TOKENS_OPTION, false );
		if ( false === $existing ) {
			return add_option( self::TOKENS_OPTION, array_values( $tokens ), '', false );
		}
		return update_option( self::TOKENS_OPTION, array_values( $tokens ), false );
	}

	/**
	 * @deprecated Use save_tokens.
	 *
	 * @param array|null $token Token or null.
	 * @return bool
	 */
	public function save_token( $token ) {
		if ( null === $token ) {
			return $this->save_tokens( array() );
		}
		return $this->save_tokens( array( $token ) );
	}

	/**
	 * Update last_used_at for a token id.
	 *
	 * @param string $token_id Token id.
	 * @param int    $timestamp Unix timestamp.
	 * @return void
	 */
	public function touch_token_last_used_by_id( $token_id, $timestamp ) {
		$tokens  = $this->get_tokens();
		$changed = false;
		foreach ( $tokens as $i => $token ) {
			if ( isset( $token['token_id'] ) && $token['token_id'] === $token_id ) {
				$tokens[ $i ]['last_used_at'] = (int) $timestamp;
				$changed                      = true;
				break;
			}
		}
		if ( $changed ) {
			$this->save_tokens( $tokens );
		}
	}

	/**
	 * @deprecated
	 *
	 * @param int $timestamp Timestamp.
	 * @return void
	 */
	public function touch_token_last_used( $timestamp ) {
		$tokens = $this->get_tokens();
		if ( empty( $tokens[0]['token_id'] ) ) {
			return;
		}
		$this->touch_token_last_used_by_id( $tokens[0]['token_id'], $timestamp );
	}

	/**
	 * Clear caches.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache        = null;
		$this->tokens_cache = false;
	}
}
