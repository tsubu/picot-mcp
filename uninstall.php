<?php
/**
 * Uninstall cleanup.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'picot_mcp_settings' );
delete_option( 'picot_mcp_token' );
delete_option( 'picot_mcp_tokens' );
delete_option( 'picot_mcp_usage_log' );

$picot_mcp_users = get_users(
	array(
		'fields'   => 'ID',
		'meta_key' => 'picot_mcp_issued_key_once', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup only.
	)
);
foreach ( $picot_mcp_users as $picot_mcp_user_id ) {
	delete_user_meta( (int) $picot_mcp_user_id, 'picot_mcp_issued_key_once' );
}

// Remove custom capability from roles.
if ( ! function_exists( 'get_role' ) ) {
	return;
}
global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
foreach ( array_keys( $wp_roles->roles ) as $picot_mcp_role_name ) {
	$picot_mcp_role = get_role( $picot_mcp_role_name );
	if ( $picot_mcp_role && $picot_mcp_role->has_cap( 'manage_picot_mcp' ) ) {
		$picot_mcp_role->remove_cap( 'manage_picot_mcp' );
	}
}
