<?php
/**
 * Simple MCP usage log (recent entries only).
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

	const OPTION = 'picot_mcp_usage_log';
	const MAX   = 50;

	/**
	 * Feature labels for admin display.
	 *
	 * @return array<string, string>
	 */
	public static function feature_labels() {
		return array(
			'content'  => __( 'Posts & pages', 'picot-mcp' ),
			'taxonomy' => __( 'Categories & tags', 'picot-mcp' ),
			'media'    => __( 'Media', 'picot-mcp' ),
			'settings' => __( 'Site settings', 'picot-mcp' ),
			'plugins'  => __( 'Plugins', 'picot-mcp' ),
			'themes'   => __( 'Themes', 'picot-mcp' ),
			'users'    => __( 'Users', 'picot-mcp' ),
		);
	}

	/**
	 * Record one usage event.
	 *
	 * @param string $feature Feature key.
	 * @param string $action  Action name.
	 * @param bool   $success Whether the operation succeeded.
	 * @return void
	 */
	public static function record( $feature, $action, $success = true ) {
		$feature = sanitize_key( (string) $feature );
		$action  = sanitize_key( (string) $action );
		if ( '' === $feature || '' === $action ) {
			return;
		}

		$user    = wp_get_current_user();
		$user_id = ( $user && $user->ID ) ? (int) $user->ID : 0;
		$login   = ( $user && $user->ID ) ? (string) $user->user_login : __( '(none)', 'picot-mcp' );

		$token_label = '';
		$token       = Picot_Mcp_Auth::instance()->get_current_token();
		if ( is_array( $token ) ) {
			if ( ! empty( $token['label'] ) ) {
				$token_label = sanitize_text_field( (string) $token['label'] );
			} elseif ( ! empty( $token['prefix'] ) ) {
				$token_label = sanitize_text_field( (string) $token['prefix'] ) . '…';
			}
		}

		$entry = array(
			'time'    => time(),
			'user_id' => $user_id,
			'user'    => $login,
			'feature' => $feature,
			'action'  => $action,
			'success' => (bool) $success,
			'key'     => $token_label,
		);

		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::MAX );

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
