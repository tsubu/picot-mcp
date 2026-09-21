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
		add_filter( 'mcp_adapter_initialize_response', array( $this, 'filter_initialize_response' ), 10, 2 );
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
			'picot-mcp/content'                       => 'wp_content',
			'picot-mcp/taxonomy'                      => 'wp_taxonomy',
			'picot-mcp/media'                         => 'wp_media',
			'picot-mcp/settings'                      => 'wp_settings',
			'picot-mcp/plugins'                       => 'wp_plugins',
			'picot-mcp/themes'                        => 'wp_themes',
			'picot-mcp/users'                         => 'wp_users',
			// Built-in Picot product integrations (also filterable).
			'picot-ai-seo-writer/article'             => 'picot_seo_writer',
			'picot-aio-ai-content-optimizer/optimize' => 'picot_aio_optimizer',
			'picot-editor-converter/convert'          => 'picot_editor_converter',
		);

		/**
		 * Filter ability-name → MCP tool-name map.
		 *
		 * @param array<string, string> $map Ability name => tool name.
		 */
		$map = apply_filters( 'picot_mcp_tool_name_map', $map );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		$ability_name = $ability->get_name();
		return isset( $map[ $ability_name ] ) ? $map[ $ability_name ] : $name;
	}

	/**
	 * Advertise that the tools list can change (settings / product plugins).
	 *
	 * Cursor and other clients cache tools aggressively when listChanged is false,
	 * so newly enabled product tools never appear until a full client reset.
	 *
	 * @param \WP\McpSchema\Common\Protocol\DTO\InitializeResult $result Initialize result.
	 * @param \WP\MCP\Core\McpServer                             $server Server.
	 * @return \WP\McpSchema\Common\Protocol\DTO\InitializeResult
	 */
	public function filter_initialize_response( $result, $server ) {
		unset( $server );
		if ( ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
			return $result;
		}

		$data = $result->toArray();
		if ( ! is_array( $data ) ) {
			return $result;
		}

		if ( ! isset( $data['capabilities'] ) || ! is_array( $data['capabilities'] ) ) {
			$data['capabilities'] = array();
		}
		if ( ! isset( $data['capabilities']['tools'] ) || ! is_array( $data['capabilities']['tools'] ) ) {
			$data['capabilities']['tools'] = array();
		}
		$data['capabilities']['tools']['listChanged'] = true;

		if ( ! class_exists( '\WP\McpSchema\Common\Protocol\DTO\InitializeResult' ) ) {
			return $result;
		}

		try {
			return \WP\McpSchema\Common\Protocol\DTO\InitializeResult::fromArray( $data );
		} catch ( \Throwable $e ) {
			return $result;
		}
	}

	/**
	 * Hide tools the current API key is not allowed to use.
	 *
	 * @param array                  $tools  Tools.
	 * @param \WP\MCP\Core\McpServer $server Server.
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
			'wp_content'             => 'content',
			'wp_taxonomy'            => 'taxonomy',
			'wp_media'               => 'media',
			'wp_settings'            => 'settings',
			'wp_plugins'             => 'plugins',
			'wp_themes'              => 'themes',
			'wp_users'               => 'users',
			'picot_seo_writer'       => 'seo_writer',
			'picot_aio_optimizer'    => 'aio_optimizer',
			'picot_editor_converter' => 'editor_converter',
		);

		/**
		 * Filter MCP tool-name → feature-key map used for tools/list scoping.
		 *
		 * @param array<string, string> $tool_feature Tool name => feature key.
		 */
		$tool_feature = apply_filters( 'picot_mcp_tool_feature_map', $tool_feature );
		if ( ! is_array( $tool_feature ) ) {
			$tool_feature = array();
		}

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
