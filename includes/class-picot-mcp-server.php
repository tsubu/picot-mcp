<?php
/**
 * MCP Adapter server registration.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Server class.
 */
class Picot_Mcp_Server {

	const SERVER_ID = 'picot-mcp';

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Server|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Server
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize MCP adapter and register custom server.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		\WP\MCP\Core\McpAdapter::instance();

		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
		add_filter( 'mcp_adapter_tool_name', array( $this, 'filter_tool_name' ), 10, 2 );
		add_filter( 'mcp_adapter_tools_list', array( $this, 'filter_tools_list' ), 10, 2 );
	}

	/**
	 * REST namespace from settings.
	 *
	 * @return string
	 */
	public static function route_namespace() {
		return Picot_Mcp_Settings::instance()->route_namespace();
	}

	/**
	 * REST route from settings.
	 *
	 * @return string
	 */
	public static function route() {
		return Picot_Mcp_Settings::instance()->route();
	}

	/**
	 * Register the Picot MCP server with enabled abilities only.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return void
	 */
	public function register_server( $adapter ) {
		if ( ! Picot_Mcp_Settings::instance()->is_enabled() ) {
			return;
		}

		$tools = Picot_Mcp_Abilities::enabled_ability_names();
		if ( empty( $tools ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::route_namespace(),
			self::route(),
			__( 'Picot MCP', 'picot-mcp' ),
			__( 'Picot MCP server for WordPress content and site operations.', 'picot-mcp' ),
			PICOT_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			Picot_Mcp_Runtime::observability_handler_class(),
			$tools,
			array(),
			array(),
			array( Picot_Mcp_Auth::instance(), 'transport_permission' )
		);
	}

	/**
	 * Map ability names to stable MCP tool names from the spec.
	 *
	 * @param string      $name    Sanitized name.
	 * @param \WP_Ability $ability Ability.
	 * @return string
	 */
	public function filter_tool_name( $name, $ability ) {
		$map = array(
			'picot-mcp/content'  => 'wp_content',
			'picot-mcp/taxonomy' => 'wp_taxonomy',
			'picot-mcp/media'    => 'wp_media',
			'picot-mcp/settings' => 'wp_settings',
			'picot-mcp/plugins'  => 'wp_plugins',
			'picot-mcp/themes'   => 'wp_themes',
			'picot-mcp/users'    => 'wp_users',
		);

		$ability_name = $ability->get_name();
		return isset( $map[ $ability_name ] ) ? $map[ $ability_name ] : $name;
	}

	/**
	 * Hide tools the current API key is not allowed to use.
	 *
	 * @param array                   $tools Tools.
	 * @param \WP\MCP\Core\McpServer  $server Server.
	 * @return array
	 */
	public function filter_tools_list( $tools, $server ) {
		unset( $server );
		if ( ! is_array( $tools ) ) {
			return $tools;
		}

		$token = Picot_Mcp_Auth::instance()->get_current_token();
		if ( ! is_array( $token ) ) {
			return $tools;
		}

		$tool_feature = array(
			'wp_content'  => 'content',
			'wp_taxonomy' => 'taxonomy',
			'wp_media'    => 'media',
			'wp_settings' => 'settings',
			'wp_plugins'  => 'plugins',
			'wp_themes'   => 'themes',
			'wp_users'    => 'users',
		);

		$filtered = array();
		foreach ( $tools as $tool ) {
			$name = '';
			if ( is_object( $tool ) && method_exists( $tool, 'getName' ) ) {
				$name = $tool->getName();
			} elseif ( is_array( $tool ) && isset( $tool['name'] ) ) {
				$name = $tool['name'];
			} elseif ( is_object( $tool ) && isset( $tool->name ) ) {
				$name = $tool->name;
			}

			$feature = isset( $tool_feature[ $name ] ) ? $tool_feature[ $name ] : '';
			if ( $feature && ! Picot_Mcp_Permissions::token_allows_feature( $token, $feature ) ) {
				continue;
			}
			if ( $feature && ! Picot_Mcp_Settings::instance()->is_feature_enabled( $feature ) ) {
				continue;
			}
			$filtered[] = $tool;
		}

		return $filtered;
	}

	/**
	 * Public MCP endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::route_namespace() . '/' . self::route() );
	}
}
