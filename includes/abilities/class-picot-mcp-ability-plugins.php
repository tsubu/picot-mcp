<?php
/**
 * Plugins ability.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Plugins class.
 */
class Picot_Mcp_Ability_Plugins {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/plugins',
			array(
				'label'               => __( 'WordPress Plugins', 'picot-mcp' ),
				'description'         => __( 'List and manage plugins. Install and update from wordpress.org only. Activation and deactivation must be done in WordPress admin.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'get', 'install', 'update', 'delete' ),
					array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'Plugin file (folder/file.php) or wordpress.org slug for install.', 'picot-mcp' ),
						),
						'slug'   => array(
							'type'        => 'string',
							'description' => __( 'wordpress.org plugin slug (install only).', 'picot-mcp' ),
						),
					)
				),
				'output_schema'       => Picot_Mcp_Abilities::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param array $input Input.
	 * @return bool|WP_Error
	 */
	public static function permission( $input ) {
		$action = isset( $input['action'] ) ? sanitize_key( $input['action'] ) : '';
		return Picot_Mcp_Permissions::assert( 'plugins', $action, is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$action = sanitize_key( $input['action'] );
		switch ( $action ) {
			case 'list':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::list_plugins() );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::get_plugin( $input ) );
			case 'install':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::install_plugin( $input ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::update_plugin( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::delete_plugin( $input ) );
			default:
				return Picot_Mcp_Abilities::respond(
					'plugins',
					$action,
					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List plugins.
	 *
	 * @return array
	 */
	private static function list_plugins() {
		$all     = get_plugins();
		$active  = get_option( 'active_plugins', array() );
		$updates = get_site_transient( 'update_plugins' );
		$items   = array();

		foreach ( $all as $file => $data ) {
			$items[] = array(
				'plugin'      => $file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'description' => $data['Description'],
				'active'      => in_array( $file, $active, true ),
				'update'      => isset( $updates->response[ $file ] ),
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Get one plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function get_plugin( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		$all  = get_plugins();
		if ( ! $file || ! isset( $all[ $file ] ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Plugin not found.', 'picot-mcp' ) );
		}
		$data   = $all[ $file ];
		$active = get_option( 'active_plugins', array() );
		return array(
			'plugin'      => $file,
			'name'        => $data['Name'],
			'version'     => $data['Version'],
			'description' => $data['Description'],
			'author'      => $data['Author'],
			'active'      => in_array( $file, $active, true ),
		);
	}

	/**
	 * Install from wordpress.org by slug only.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function install_plugin( array $input ) {
		$slug = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
		if ( '' === $slug && ! empty( $input['plugin'] ) ) {
			// Accept slug-only in plugin field if it has no path.
			$candidate = sanitize_title( $input['plugin'] );
			if ( false === strpos( $input['plugin'], '/' ) && false === strpos( $input['plugin'], '.' ) ) {
				$slug = $candidate;
			}
		}
		if ( '' === $slug || preg_match( '/[^a-z0-9-]/', $slug ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'A wordpress.org plugin slug is required.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$link_ok = Picot_Mcp_Util::assert_wporg_download_url( isset( $api->download_link ) ? $api->download_link : '' );
		if ( is_wp_error( $link_ok ) ) {
			return $link_ok;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Plugin installation failed.', 'picot-mcp' ) );
		}

		return array(
			'slug'      => $slug,
			'plugin'    => $upgrader->plugin_info(),
			'installed' => true,
		);
	}

	/**
	 * Update plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function update_plugin( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		if ( ! $file ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'plugin is required.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( empty( $updates->response[ $file ]->package ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'No update package available for this plugin.', 'picot-mcp' ) );
		}
		$link_ok = Picot_Mcp_Util::assert_wporg_download_url( $updates->response[ $file ]->package );
		if ( is_wp_error( $link_ok ) ) {
			return $link_ok;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'plugin'  => $file,
			'updated' => (bool) $result,
		);
	}

	/**
	 * Delete plugin (must already be inactive in WordPress admin).
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function delete_plugin( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		if ( ! $file ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'plugin is required.', 'picot-mcp' ) );
		}
		if ( is_plugin_active( $file ) ) {
			return Picot_Mcp_Errors::make(
				'operation_not_allowed',
				__( 'Deactivate the plugin in WordPress admin before deleting via MCP.', 'picot-mcp' )
			);
		}
		if ( plugin_basename( PICOT_MCP_FILE ) === $file ) {
			return Picot_Mcp_Errors::make(
				'operation_not_allowed',
				__( 'This plugin cannot delete itself via MCP.', 'picot-mcp' )
			);
		}
		$result = delete_plugins( array( $file ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'plugin'  => $file,
			'deleted' => true,
		);
	}

	/**
	 * Sanitize plugin basename.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private static function sanitize_plugin_file( $plugin ) {
		$plugin = str_replace( '\\', '/', (string) $plugin );
		$plugin = ltrim( $plugin, '/' );
		if ( false !== strpos( $plugin, '..' ) ) {
			return '';
		}
		return sanitize_text_field( $plugin );
	}
}
