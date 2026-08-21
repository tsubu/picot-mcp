<?php
/**
 * Themes ability.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Themes class.
 */
class Picot_Mcp_Ability_Themes {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/themes',
			array(
				'label'               => __( 'WordPress Themes', 'picot-mcp' ),
				'description'         => __( 'List and manage themes. Install from wordpress.org, transfer ZIP packages via Base64 export/install.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'get', 'install', 'install_zip', 'export_zip', 'activate', 'update', 'delete' ),
					array(
						'stylesheet'  => array(
							'type'        => 'string',
							'description' => __( 'Theme stylesheet / slug.', 'picot-mcp' ),
						),
						'slug'        => array( 'type' => 'string' ),
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
							'description' => __( 'Overwrite an existing theme package when using install_zip.', 'picot-mcp' ),
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
		return Picot_Mcp_Permissions::assert( 'themes', $action, is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		$action = sanitize_key( $input['action'] );
		switch ( $action ) {
			case 'list':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::list_themes() );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::get_theme( $input ) );
			case 'install':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::install_theme( $input ) );
			case 'install_zip':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::install_theme_zip( $input ) );
			case 'export_zip':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::export_theme_zip( $input ) );
			case 'activate':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::activate_theme( $input ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::update_theme( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::delete_theme( $input ) );
			default:
				return Picot_Mcp_Abilities::respond( 'themes', $action, 					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List themes.
	 *
	 * @return array
	 */
	private static function list_themes() {
		$themes  = wp_get_themes();
		$current = get_stylesheet();
		$items   = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$items[] = self::serialize_theme( $theme, $stylesheet === $current );
		}
		return array(
			'items'   => $items,
			'active'  => $current,
		);
	}

	/**
	 * Get theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function get_theme( array $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Theme not found.', 'picot-mcp' ) );
		}
		return self::serialize_theme( $theme, get_stylesheet() === $theme->get_stylesheet() );
	}

	/**
	 * Install from wordpress.org.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function install_theme( array $input ) {
		$slug = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
		if ( '' === $slug && ! empty( $input['stylesheet'] ) ) {
			$slug = sanitize_title( $input['stylesheet'] );
		}
		if ( '' === $slug || preg_match( '/[^a-z0-9-]/', $slug ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'A wordpress.org theme slug is required.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$api = themes_api(
			'theme_information',
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
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Theme installation failed.', 'picot-mcp' ) );
		}

		return array(
			'slug'      => $slug,
			'installed' => true,
		);
	}

	/**
	 * Install (or overwrite) a theme from a Base64 ZIP package.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function install_theme_zip( array $input ) {
		$filename = isset( $input['filename'] ) ? (string) $input['filename'] : 'theme.zip';
		$b64      = isset( $input['base64_data'] ) ? $input['base64_data'] : '';
		$tmp      = Picot_Mcp_Util::write_base64_zip_temp( $filename, $b64 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$overwrite = ! empty( $input['overwrite'] );
		$skin      = new Automatic_Upgrader_Skin();
		$upgrader  = new Theme_Upgrader( $skin );
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
				$detail ? $detail : __( 'Theme ZIP installation failed.', 'picot-mcp' )
			);
		}

		$stylesheet = '';
		if ( method_exists( $upgrader, 'theme_info' ) ) {
			$info = $upgrader->theme_info();
			if ( $info instanceof WP_Theme ) {
				$stylesheet = $info->get_stylesheet();
			}
		}

		return array(
			'stylesheet' => $stylesheet,
			'installed'  => true,
			'overwrite'  => $overwrite,
			'source'     => 'zip',
		);
	}

	/**
	 * Export an installed theme as a Base64 ZIP package.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function export_theme_zip( array $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
		if ( '' === $stylesheet && ! empty( $input['slug'] ) ) {
			$stylesheet = sanitize_key( $input['slug'] );
		}
		if ( '' === $stylesheet ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'stylesheet is required.', 'picot-mcp' ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Theme not found.', 'picot-mcp' ) );
		}

		$source = $theme->get_stylesheet_directory();
		$allowed = Picot_Mcp_Util::assert_path_under_base( $source, get_theme_root( $theme->get_stylesheet() ) );
		if ( is_wp_error( $allowed ) ) {
			// Fallback: any path under the global theme root.
			$allowed = Picot_Mcp_Util::assert_path_under_base( $source, get_theme_root() );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$root_name = $theme->get_stylesheet();
		$filename  = isset( $input['filename'] ) ? (string) $input['filename'] : ( $root_name . '.zip' );
		$payload   = Picot_Mcp_Util::zip_path_to_base64( $source, $root_name, $filename );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return array(
			'stylesheet'  => $root_name,
			'filename'    => $payload['filename'],
			'base64_data' => $payload['base64_data'],
			'bytes'       => $payload['bytes'],
			'source'      => 'zip',
		);
	}

	/**
	 * Activate theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function activate_theme( array $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Theme not found.', 'picot-mcp' ) );
		}
		switch_theme( $theme->get_stylesheet() );
		return array(
			'stylesheet' => $theme->get_stylesheet(),
			'active'     => true,
		);
	}

	/**
	 * Update theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function update_theme( array $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
		if ( ! $stylesheet ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'stylesheet is required.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( empty( $updates->response[ $stylesheet ]['package'] ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'No update package available for this theme.', 'picot-mcp' ) );
		}
		$link_ok = Picot_Mcp_Util::assert_wporg_download_url( $updates->response[ $stylesheet ]['package'] );
		if ( is_wp_error( $link_ok ) ) {
			return $link_ok;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'stylesheet' => $stylesheet,
			'updated'    => (bool) $result,
		);
	}

	/**
	 * Delete theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function delete_theme( array $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
		if ( ! $stylesheet ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'stylesheet is required.', 'picot-mcp' ) );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Cannot delete the active theme.', 'picot-mcp' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to delete theme.', 'picot-mcp' ) );
		}
		return array(
			'stylesheet' => $stylesheet,
			'deleted'    => true,
		);
	}

	/**
	 * Serialize theme.
	 *
	 * @param WP_Theme $theme  Theme.
	 * @param bool     $active Active.
	 * @return array
	 */
	private static function serialize_theme( $theme, $active = false ) {
		return array(
			'stylesheet'  => $theme->get_stylesheet(),
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'author'      => $theme->get( 'Author' ),
			'description' => $theme->get( 'Description' ),
			'block_theme' => $theme->is_block_theme(),
			'active'      => (bool) $active,
		);
	}
}
