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
				'description'         => __( 'List and manage themes. Install and update from wordpress.org only. Theme activation must be done in WordPress admin.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'get', 'install', 'update', 'delete' ),
					array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => __( 'Theme stylesheet / slug.', 'picot-mcp' ),
						),
						'slug'       => array( 'type' => 'string' ),
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
			case 'update':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::update_theme( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'themes', $action, self::delete_theme( $input ) );
			default:
				return Picot_Mcp_Abilities::respond(
					'themes',
					$action,
					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
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
			'items'  => $items,
			'active' => $current,
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
