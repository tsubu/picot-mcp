<?php
/**
 * Main plugin orchestrator.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Plugin class.
 */
class Picot_Mcp_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	public function init() {
		Picot_Mcp_Capabilities::register();
		Picot_Mcp_Settings::instance();
		Picot_Mcp_Auth::instance()->init();
		Picot_Mcp_Abilities::instance()->init();
		Picot_Mcp_Server::instance()->init();

		if ( is_admin() ) {
			Picot_Mcp_Admin::instance()->init();
		}
	}

	/**
	 * Load bundled translations (for installs outside WordPress.org language packs).
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$mofile = PICOT_MCP_PATH . 'languages/picot-mcp-' . $locale . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'picot-mcp', $mofile );
		}
	}

	/**
	 * Activation: seed defaults and capability.
	 *
	 * @return void
	 */
	public static function activate() {
		Picot_Mcp_Capabilities::register();
		$existing = get_option( PICOT_MCP_OPTION, null );
		if ( null === $existing ) {
			add_option( PICOT_MCP_OPTION, Picot_Mcp_Settings::defaults(), '', false );
		}
	}
}
