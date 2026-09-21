<?php
/**
 * MCP usage / audit log.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Usage_Log class.
 */
class Picot_Mcp_Usage_Log {

	const OPTION     = 'picot_mcp_usage_log';
	const MAX_HARD   = 500;
	const MAX_LEGACY = 50;

	/**
	 * Feature labels for admin display.
	 *
	 * @return array<string, string>
	 */
	public static function feature_labels() {
		return Picot_Mcp_Settings::feature_labels();
	}

	/**
	 * Max entries kept.
	 *
	 * @return int
	 */
	public static function max_entries() {
		if ( class_exists( 'Picot_Mcp_Settings' ) ) {
			return Picot_Mcp_Settings::instance()->log_retention();
		}
		return self::MAX_LEGACY;
	}

	/**
	 * Client IP for audit (best effort).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		/**
		 * Filter logged client IP.
		 *
		 * @param string $ip Detected IP.
		 */
		$ip = apply_filters( 'picot_mcp_log_client_ip', $ip );
		return is_string( $ip ) ? substr( $ip, 0, 45 ) : '';
	}

	/**
	 * Record one usage event.
	 *
	 * @param string $feature Feature key.
	 * @param string $action  Action name.
	 * @param bool   $success Whether the operation succeeded.
	 * @param string $error_code Optional error code.
	 * @param string $error_message Optional short error message.
	 * @return void
	 */
	public static function record( $feature, $action, $success = true, $error_code = '', $error_message = '' ) {
		$feature = sanitize_key( (string) $feature );
		$action  = sanitize_key( (string) $action );
		if ( '' === $feature || '' === $action ) {
			return;
		}

		$user    = wp_get_current_user();
		$user_id = ( $user && $user->ID ) ? (int) $user->ID : 0;
		$login   = ( $user && $user->ID ) ? (string) $user->user_login : __( '(none)', 'picot-mcp' );

		$token_label = '';
		$token_id    = '';
		$token       = Picot_Mcp_Auth::instance()->get_current_token();
		if ( is_array( $token ) ) {
			if ( ! empty( $token['token_id'] ) ) {
				$token_id = (string) $token['token_id'];
			}
			if ( ! empty( $token['label'] ) ) {
				$token_label = sanitize_text_field( (string) $token['label'] );
			} elseif ( ! empty( $token['prefix'] ) ) {
				$token_label = sanitize_text_field( (string) $token['prefix'] ) . '…';
			}
		}

		$entry = array(
			'time'     => time(),
			'user_id'  => $user_id,
			'user'     => $login,
			'feature'  => $feature,
			'action'   => $action,
			'success'  => (bool) $success,
			'key'      => $token_label,
			'token_id' => $token_id,
			'ip'       => self::client_ip(),
			'code'     => $success ? '' : sanitize_key( (string) $error_code ),
			'error'    => $success ? '' : substr( sanitize_text_field( (string) $error_message ), 0, 200 ),
		);

		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		array_unshift( $entries, $entry );
		$max     = min( self::MAX_HARD, self::max_entries() );
		$entries = array_slice( $entries, 0, $max );

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Get recent log entries (newest first).
	 *
	 * @return array<int, array>
	 */
	public static function get_entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Clear all log entries.
	 *
	 * @return bool
	 */
	public static function clear() {
		return delete_option( self::OPTION );
	}
}
