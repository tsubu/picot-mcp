<?php
/**
 * Shared error helpers.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Errors class.
 */
class Picot_Mcp_Errors {

	/**
	 * Create a WP_Error with a stable code and safe message.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message (no secrets).
	 * @param array  $data    Optional error data (status etc).
	 * @return WP_Error
	 */
	public static function make( $code, $message, $data = array() ) {
		$status_map = array(
			'authentication_required'      => 401,
			'invalid_api_key'              => 401,
			'mcp_disabled'                 => 403,
			'feature_disabled'             => 403,
			'operation_not_allowed'        => 403,
			'wordpress_permission_denied'  => 403,
			'invalid_parameter'            => 400,
			'resource_not_found'           => 404,
			'upload_failed'                => 400,
			'origin_forbidden'             => 403,
			'internal_error'               => 500,
		);

		if ( ! isset( $data['status'] ) && isset( $status_map[ $code ] ) ) {
			$data['status'] = $status_map[ $code ];
		}

		return new WP_Error( $code, $message, $data );
	}
}
