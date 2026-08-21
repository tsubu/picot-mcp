<?php
/**
 * Capability helpers.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Capabilities class.
 */
class Picot_Mcp_Capabilities {

	/**
	 * Legacy custom capability (still granted to administrator for uninstall cleanup).
	 * Access checks use manage_options so only site administrators see the settings UI.
	 */
	const CAP = 'manage_picot_mcp';

	/**
	 * WordPress capability that maps to site administrators (Settings screens).
	 */
	const ADMIN_CAP = 'manage_options';

	/**
	 * Whether the current user can manage Picot MCP settings.
	 * Restricted to users with manage_options (administrators on a standard install).
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::ADMIN_CAP );
	}

	/**
	 * Capability string for add_options_page / menu registration.
	 *
	 * @return string
	 */
	public static function menu_capability() {
		return self::ADMIN_CAP;
	}

	/**
	 * Register capabilities: ensure only the administrator role has the legacy CAP.
	 *
	 * @return void
	 */
	public static function register() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}

		// Prevent non-admin roles from keeping manage_picot_mcp if it was granted elsewhere.
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			if ( 'administrator' === $role_name ) {
				continue;
			}
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	/**
	 * Remove the custom capability from all roles (uninstall).
	 *
	 * @return void
	 */
	public static function unregister() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}
}
