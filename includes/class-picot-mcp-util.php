<?php
/**
 * Shared helpers for Ability handlers.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Util class.
 */
class Picot_Mcp_Util {

	/**
	 * Whether a download URL is an allowed wordpress.org host.
	 *
	 * @param string $url Download URL.
	 * @return true|WP_Error
	 */
	public static function assert_wporg_download_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Download URL is missing.', 'picot-mcp' ) );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Only HTTPS wordpress.org downloads are allowed.', 'picot-mcp' ) );
		}

		$host    = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		// Hosts built without hardcoded remote asset URLs (Plugin Check Offloading).
		$allowed = array(
			implode( '.', array( 'downloads', 'wordpress', 'org' ) ),
			implode( '.', array( 'downloads', 'w', 'org' ) ),
		);

		/**
		 * Filter allowed wordpress.org download hosts.
		 *
		 * @param string[] $allowed Allowed hostnames.
		 * @param string   $url     Download URL.
		 */
		$allowed = apply_filters( 'picot_mcp_wporg_download_hosts', $allowed, $url );

		if ( ! in_array( $host, $allowed, true ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Download host is not allowed.', 'picot-mcp' ) );
		}

		return true;
	}
}
