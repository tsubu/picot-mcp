<?php
/**
 * Bearer API key authentication and Origin checks.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Auth class.
 */
class Picot_Mcp_Auth {

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Auth|null
	 */
	private static $instance = null;

	/**
	 * Last auth error for MCP route (for HTTP status).
	 *
	 * @var WP_Error|null
	 */
	private $last_error = null;

	/**
	 * Currently authenticated API key token for this request.
	 *
	 * @var array|null
	 */
	private $current_token = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Auth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Token authenticated for the current MCP request.
	 *
	 * @return array|null
	 */
	public function get_current_token() {
		return $this->current_token;
	}

	/**
	 * Set the current request token.
	 *
	 * @param array|null $token Token metadata.
	 * @return void
	 */
	public function set_current_token( $token ) {
		$this->current_token = is_array( $token ) ? $token : null;
	}

	/**
	 * Hook early auth for MCP REST routes.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'determine_current_user', array( $this, 'maybe_authenticate_bearer' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 99 );
	}

	/**
	 * Authenticate via Authorization: Bearer pmcp_… for MCP routes.
	 *
	 * @param int|false $user_id Current user ID.
	 * @return int|false
	 */
	public function maybe_authenticate_bearer( $user_id ) {
		if ( ! $this->is_mcp_request() ) {
			return $user_id;
		}

		$token = $this->extract_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		$verified = Picot_Mcp_Api_Key::verify( $token );
		if ( is_wp_error( $verified ) ) {
			return $user_id;
		}

		$this->set_current_token( $verified );
		return (int) $verified['user_id'];
	}

	/**
	 * Enforce Bearer + Origin on MCP routes with correct HTTP status codes.
	 *
	 * @param WP_Error|null|true $result Prior auth result.
	 * @return WP_Error|null|true
	 */
	public function rest_authentication_errors( $result ) {
		if ( ! $this->is_mcp_request() ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$check = $this->authenticate_mcp_request( null );
		if ( is_wp_error( $check ) ) {
			$this->last_error = $check;
			return $check;
		}

		return true;
	}

	/**
	 * Transport permission callback for MCP Adapter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function transport_permission( $request ) {
		$check = $this->authenticate_mcp_request( $request );
		if ( is_wp_error( $check ) ) {
			$this->last_error = $check;
			return $check;
		}
		return true;
	}

	/**
	 * Shared MCP authentication + origin checks.
	 *
	 * @param WP_REST_Request|null $request Request or null.
	 * @return true|WP_Error
	 */
	private function authenticate_mcp_request( $request ) {
		if ( ! Picot_Mcp_Settings::instance()->is_enabled() ) {
			return Picot_Mcp_Errors::make( 'mcp_disabled', __( 'MCP server is disabled.', 'picot-mcp' ) );
		}

		$origin_check = $this->validate_origin( $request );
		if ( is_wp_error( $origin_check ) ) {
			return $origin_check;
		}

		$token = $this->extract_bearer_token( $request );
		if ( ! $token ) {
			return Picot_Mcp_Errors::make( 'authentication_required', __( 'Authorization Bearer API key is required.', 'picot-mcp' ) );
		}

		// Reuse token already verified earlier in this request (determine_current_user).
		$current = $this->get_current_token();
		if ( is_array( $current ) && ! empty( $current['user_id'] ) && ! empty( $current['hash'] ) ) {
			$candidate = Picot_Mcp_Api_Key::hash( $token );
			if ( hash_equals( (string) $current['hash'], $candidate ) ) {
				wp_set_current_user( (int) $current['user_id'] );
				if ( ! is_user_logged_in() ) {
					return Picot_Mcp_Errors::make( 'authentication_required', __( 'Authentication failed.', 'picot-mcp' ) );
				}
				return true;
			}
		}

		$verified = Picot_Mcp_Api_Key::verify( $token );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$this->set_current_token( $verified );
		wp_set_current_user( (int) $verified['user_id'] );

		if ( ! is_user_logged_in() ) {
			return Picot_Mcp_Errors::make( 'authentication_required', __( 'Authentication failed.', 'picot-mcp' ) );
		}

		return true;
	}

	/**
	 * Validate Origin header when present.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return true|WP_Error
	 */
	public function validate_origin( $request = null ) {
		$origin = '';
		if ( $request instanceof WP_REST_Request ) {
			$origin = (string) $request->get_header( 'origin' );
		} elseif ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}

		if ( empty( $origin ) ) {
			return true;
		}

		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$allowed     = array_filter(
			array(
				$site_host,
				wp_parse_url( site_url(), PHP_URL_HOST ),
			)
		);

		/**
		 * Filter allowed Origin hosts for MCP requests.
		 *
		 * @param string[] $allowed Allowed hostnames.
		 * @param string   $origin  Request Origin header value.
		 */
		$allowed = apply_filters( 'picot_mcp_allowed_origins', $allowed, $origin );

		if ( ! is_string( $origin_host ) || '' === $origin_host ) {
			return Picot_Mcp_Errors::make( 'origin_forbidden', __( 'Origin is not allowed.', 'picot-mcp' ) );
		}

		$origin_host = strtolower( $origin_host );
		foreach ( $allowed as $host ) {
			if ( is_string( $host ) && '' !== $host && strtolower( $host ) === $origin_host ) {
				return true;
			}
		}

		return Picot_Mcp_Errors::make( 'origin_forbidden', __( 'Origin is not allowed.', 'picot-mcp' ) );
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @param WP_REST_Request|null $request Optional request.
	 * @return string|null
	 */
	public function extract_bearer_token( $request = null ) {
		$header = '';
		if ( $request instanceof WP_REST_Request ) {
			$header = (string) $request->get_header( 'authorization' );
		}
		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( ! preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Whether the current request targets our MCP endpoint exclusively.
	 *
	 * @return bool
	 */
	private function is_mcp_request() {
		$ns    = preg_quote( Picot_Mcp_Server::route_namespace(), '#' );
		$route = preg_quote( Picot_Mcp_Server::route(), '#' );

		if ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$rest_route = (string) $GLOBALS['wp']->query_vars['rest_route'];
			if ( preg_match( '#^/' . $ns . '/' . $route . '/?$#', $rest_route ) ) {
				return true;
			}
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri   = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path  = wp_parse_url( $uri, PHP_URL_PATH );
		$query = wp_parse_url( $uri, PHP_URL_QUERY );

		if ( is_string( $path ) && preg_match( '#/wp-json/' . $ns . '/' . $route . '/?$#', $path ) ) {
			return true;
		}

		if ( is_string( $query ) && preg_match( '#(?:^|&)rest_route=/' . $ns . '/' . $route . '/??(?:&|$)#', $query ) ) {
			return true;
		}

		return false;
	}
}
