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
				'description'         => __( 'List and manage plugins. Install from wordpress.org, transfer ZIP packages via Base64 export/install.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'get', 'install', 'install_zip', 'export_zip', 'activate', 'deactivate', 'update', 'delete' ),
					array(
						'plugin'      => array(
							'type'        => 'string',
							'description' => __( 'Plugin file (folder/file.php) or wordpress.org slug for install.', 'picot-mcp' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'wordpress.org plugin slug (install only).', 'picot-mcp' ),
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => __( 'ZIP filename for install_zip / optional export_zip name (must end with .zip).', 'picot-mcp' ),
						),
						'base64_data' => array(
							'type'        => 'string',
							'description' => __( 'Base64-encoded ZIP contents for install_zip.', 'picot-mcp' ),
						),
						'overwrite'   => array(
							'type'        => 'boolean',
							'description' => __( 'Overwrite an existing plugin package when using install_zip.', 'picot-mcp' ),
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
			case 'install_zip':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::install_plugin_zip( $input ) );
			case 'export_zip':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::export_plugin_zip( $input ) );
			case 'activate':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::activate_plugin_action( $input ) );
			case 'deactivate':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::deactivate_plugin_action( $input ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::update_plugin( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'plugins', $action, self::delete_plugin( $input ) );
			default:
				return Picot_Mcp_Abilities::respond( 'plugins', $action, 					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List plugins.
	 *
	 * @return array
	 */
	private static function list_plugins() {
		$all      = get_plugins();
		$active   = get_option( 'active_plugins', array() );
		$updates  = get_site_transient( 'update_plugins' );
		$items    = array();

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
	 * Install (or overwrite) a plugin from a Base64 ZIP package.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function install_plugin_zip( array $input ) {
		$filename = isset( $input['filename'] ) ? (string) $input['filename'] : 'plugin.zip';
		$b64      = isset( $input['base64_data'] ) ? $input['base64_data'] : '';
		$tmp      = Picot_Mcp_Util::write_base64_zip_temp( $filename, $b64 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$overwrite = ! empty( $input['overwrite'] );
		$skin      = new Automatic_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$result    = $upgrader->install(
			$tmp,
			array(
				'overwrite_package' => $overwrite,
			)
		);

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			$detail   = is_array( $messages ) && ! empty( $messages ) ? implode( ' ', $messages ) : '';
			return Picot_Mcp_Errors::make(
				'internal_error',
				$detail ? $detail : __( 'Plugin ZIP installation failed.', 'picot-mcp' )
			);
		}

		return array(
			'plugin'    => $upgrader->plugin_info(),
			'installed' => true,
			'overwrite' => $overwrite,
			'source'    => 'zip',
		);
	}

	/**
	 * Export an installed plugin as a Base64 ZIP package.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function export_plugin_zip( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		if ( ! $file ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'plugin is required.', 'picot-mcp' ) );
		}

		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Plugin not found.', 'picot-mcp' ) );
		}

		$plugin_root = wp_normalize_path( WP_PLUGIN_DIR );
		$dir_name    = dirname( $file );
		if ( '.' === $dir_name || '' === $dir_name ) {
			$source       = $plugin_root . '/' . $file;
			$archive_root = wp_basename( $file );
			$slug         = pathinfo( $archive_root, PATHINFO_FILENAME );
		} else {
			$source       = $plugin_root . '/' . $dir_name;
			$archive_root = $dir_name;
			$slug         = $dir_name;
		}

		$allowed = Picot_Mcp_Util::assert_path_under_base( $source, $plugin_root );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$filename = isset( $input['filename'] ) ? (string) $input['filename'] : ( $slug . '.zip' );
		$payload  = Picot_Mcp_Util::zip_path_to_base64( $source, $archive_root, $filename );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return array(
			'plugin'      => $file,
			'filename'    => $payload['filename'],
			'base64_data' => $payload['base64_data'],
			'bytes'       => $payload['bytes'],
			'source'      => 'zip',
		);
	}

	/**
	 * Activate plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function activate_plugin_action( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		if ( ! $file ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'plugin is required.', 'picot-mcp' ) );
		}
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'plugin' => $file,
			'active' => true,
		);
	}

	/**
	 * Deactivate plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function deactivate_plugin_action( array $input ) {
		$file = isset( $input['plugin'] ) ? self::sanitize_plugin_file( $input['plugin'] ) : '';
		if ( ! $file ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'plugin is required.', 'picot-mcp' ) );
		}
		deactivate_plugins( $file );
		return array(
			'plugin' => $file,
			'active' => false,
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
	 * Delete plugin.
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
			deactivate_plugins( $file );
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
