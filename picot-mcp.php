<?php
/**
 * Plugin Name: Picot MCP
 * Plugin URI: https://github.com/tsubu/picot-mcp
 * Description: Exposes WordPress as an MCP server with API key authentication and scoped permissions.
 * Version: 0.2.3
 * Requires at least: 6.9
 * Tested up to: 7.2
 * Requires PHP: 7.4
 * Author: PICOT
 * Author URI: https://picot.tokyo/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: picot-mcp
 * Domain Path: /languages
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PICOT_MCP_VERSION', '0.2.3' );
define( 'PICOT_MCP_FILE', __FILE__ );
define( 'PICOT_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PICOT_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'PICOT_MCP_OPTION', 'picot_mcp_settings' );

if ( ! file_exists( PICOT_MCP_PATH . 'vendor/autoload_packages.php' ) && ! file_exists( PICOT_MCP_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) || ! picot_mcp_is_plugin_admin_screen() ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Picot MCP requires Composer dependencies. Run "composer install" in the plugin directory.', 'picot-mcp' );
			echo '</p></div>';
		}
	);
	return;
}

// Prefer Jetpack Autoloader so the newest wordpress/mcp-adapter wins across plugins.
if ( file_exists( PICOT_MCP_PATH . 'vendor/autoload_packages.php' ) ) {
	require_once PICOT_MCP_PATH . 'vendor/autoload_packages.php';
} else {
	require_once PICOT_MCP_PATH . 'vendor/autoload.php';
}

/**
 * Whether the current admin screen is plugin-related (limit admin notices).
 *
 * @return bool
 */
function picot_mcp_is_plugin_admin_screen() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen || empty( $screen->id ) ) {
		return false;
	}
	return in_array( $screen->id, array( 'plugins', 'plugin-install', 'settings_page_picot-mcp' ), true );
}

/**
 * Bootstrap the plugin after plugins are loaded.
 *
 * @return void
 */
function picot_mcp_bootstrap() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', 'picot_mcp_abilities_missing_notice' );
		return;
	}

	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		add_action( 'admin_notices', 'picot_mcp_adapter_missing_notice' );
		return;
	}

	Picot_Mcp_Plugin::instance()->init();
}
add_action( 'init', array( 'Picot_Mcp_Plugin', 'load_textdomain' ) );
add_action( 'plugins_loaded', 'picot_mcp_bootstrap' );

/**
 * Admin notice when Abilities API is unavailable.
 *
 * @return void
 */
function picot_mcp_abilities_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || ! picot_mcp_is_plugin_admin_screen() ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Picot MCP requires WordPress 6.9 or later (Abilities API).', 'picot-mcp' );
	echo '</p></div>';
}

/**
 * Admin notice when MCP Adapter class is missing.
 *
 * @return void
 */
function picot_mcp_adapter_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || ! picot_mcp_is_plugin_admin_screen() ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Picot MCP could not load the WordPress MCP Adapter. Re-run composer install.', 'picot-mcp' );
	echo '</p></div>';
}

register_activation_hook( __FILE__, array( 'Picot_Mcp_Plugin', 'activate' ) );
