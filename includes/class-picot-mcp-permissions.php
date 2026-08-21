<?php
/**
 * Feature + operation + capability permission checks.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Permissions class.
 */
class Picot_Mcp_Permissions {

	/**
	 * Map actions to operation levels.
	 *
	 * @var array<string, string>
	 */
	private static $action_operations = array(
		'list'        => 'read',
		'search'      => 'read',
		'get'         => 'read',
		'create'      => 'write',
		'update'      => 'write',
		'upload'      => 'write',
		'delete'      => 'critical',
		'install'     => 'critical',
		'install_zip' => 'zip_install',
		'export_zip'  => 'zip_install',
		'activate'    => 'critical',
		'deactivate'  => 'critical',
	);

	/**
	 * Baseline WordPress capabilities per feature/action.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $capability_map = array(
		'content'  => array(
			'list'   => 'edit_posts',
			'search' => 'edit_posts',
			'get'    => 'edit_posts',
			'create' => 'edit_posts',
			'update' => 'edit_posts',
			'delete' => 'delete_posts',
		),
		'taxonomy' => array(
			'list'   => 'manage_categories',
			'search' => 'manage_categories',
			'get'    => 'manage_categories',
			'create' => 'manage_categories',
			'update' => 'manage_categories',
			'delete' => 'manage_categories',
		),
		'media'    => array(
			'list'   => 'upload_files',
			'search' => 'upload_files',
			'get'    => 'upload_files',
			'upload' => 'upload_files',
			'update' => 'upload_files',
			'delete' => 'delete_posts',
		),
		'settings' => array(
			'get'    => 'manage_options',
			'update' => 'manage_options',
		),
		'plugins'  => array(
			'list'        => 'activate_plugins',
			'get'         => 'activate_plugins',
			'install'     => 'install_plugins',
			'install_zip' => 'install_plugins',
			'export_zip'  => 'install_plugins',
			'activate'    => 'activate_plugins',
			'deactivate'  => 'activate_plugins',
			'update'      => 'update_plugins',
			'delete'      => 'delete_plugins',
		),
		'themes'   => array(
			'list'        => 'switch_themes',
			'get'         => 'switch_themes',
			'install'     => 'install_themes',
			'install_zip' => 'install_themes',
			'export_zip'  => 'install_themes',
			'activate'    => 'switch_themes',
			'update'      => 'update_themes',
			'delete'      => 'delete_themes',
		),
		'users'    => array(
			'list'   => 'list_users',
			'search' => 'list_users',
			'get'    => 'list_users',
			'create' => 'create_users',
			'update' => 'edit_users',
			'delete' => 'delete_users',
		),
	);

	/**
	 * Assert feature + operation + capability for an action.
	 *
	 * @param string $feature Feature key.
	 * @param string $action  Action name.
	 * @param array  $input   Optional input for finer checks.
	 * @return true|WP_Error
	 */
	public static function assert( $feature, $action, $input = array() ) {
		$settings = Picot_Mcp_Settings::instance();

		if ( ! $settings->is_enabled() ) {
			return Picot_Mcp_Errors::make( 'mcp_disabled', __( 'MCP server is disabled.', 'picot-mcp' ) );
		}

		if ( ! $settings->is_feature_enabled( $feature ) ) {
			return Picot_Mcp_Errors::make(
				'feature_disabled',
				sprintf(
					/* translators: %s: feature name */
					__( 'The "%s" feature is disabled in MCP settings.', 'picot-mcp' ),
					$feature
				)
			);
		}

		$token = Picot_Mcp_Auth::instance()->get_current_token();
		if ( is_array( $token ) && ! self::token_allows_feature( $token, $feature ) ) {
			return Picot_Mcp_Errors::make(
				'feature_disabled',
				sprintf(
					/* translators: %s: feature name */
					__( 'The "%s" feature is disabled for this API key.', 'picot-mcp' ),
					$feature
				)
			);
		}

		$operation = self::operation_for_action( $action, $feature );
		if ( ! $settings->is_operation_allowed( $operation ) ) {
			return Picot_Mcp_Errors::make(
				'operation_not_allowed',
				sprintf(
					/* translators: %s: action name */
					__( 'The "%s" action is disabled by MCP operation settings.', 'picot-mcp' ),
					$action
				)
			);
		}

		if ( is_array( $token ) && ! self::token_allows_operation( $token, $operation ) ) {
			return Picot_Mcp_Errors::make(
				'operation_not_allowed',
				sprintf(
					/* translators: %s: action name */
					__( 'The "%s" action is disabled for this API key.', 'picot-mcp' ),
					$action
				)
			);
		}

		$caps = self::capabilities_for( $feature, $action, $input );
		foreach ( $caps as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return Picot_Mcp_Errors::make(
					'wordpress_permission_denied',
					sprintf(
						/* translators: %s: capability name */
						__( 'WordPress capability "%s" is required.', 'picot-mcp' ),
						$cap
					)
				);
			}
		}

		return true;
	}

	/**
	 * Assert object-level capability for a post/attachment.
	 *
	 * @param string $action edit|delete|read.
	 * @param int    $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function assert_post_cap( $action, $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Content not found.', 'picot-mcp' ) );
		}

		$map = array(
			'read'   => 'read_post',
			'edit'   => 'edit_post',
			'delete' => 'delete_post',
		);
		$cap = isset( $map[ $action ] ) ? $map[ $action ] : 'edit_post';

		if ( ! current_user_can( $cap, $post_id ) ) {
			return Picot_Mcp_Errors::make(
				'wordpress_permission_denied',
				sprintf(
					/* translators: %s: capability name */
					__( 'WordPress capability "%s" is required for this item.', 'picot-mcp' ),
					$cap
				)
			);
		}

		return true;
	}

	/**
	 * Assert object-level capability for a user.
	 *
	 * @param string $action edit|delete|promote.
	 * @param int    $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function assert_user_cap( $action, $user_id ) {
		$user_id = absint( $user_id );
		if ( ! get_user_by( 'id', $user_id ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'User not found.', 'picot-mcp' ) );
		}

		$map = array(
			'edit'    => 'edit_user',
			'delete'  => 'delete_user',
			'promote' => 'promote_user',
		);
		$cap = isset( $map[ $action ] ) ? $map[ $action ] : 'edit_user';

		if ( ! current_user_can( $cap, $user_id ) ) {
			return Picot_Mcp_Errors::make(
				'wordpress_permission_denied',
				sprintf(
					/* translators: %s: capability name */
					__( 'WordPress capability "%s" is required for this user.', 'picot-mcp' ),
					$cap
				)
			);
		}

		return true;
	}

	/**
	 * Whether a token allows a feature.
	 *
	 * Missing maps are treated as deny (explicit maps are always written for new keys;
	 * legacy tokens are normalized when loaded).
	 *
	 * @param array  $token   Token.
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function token_allows_feature( array $token, $feature ) {
		if ( empty( $token['permissions'] ) || ! is_array( $token['permissions'] ) ) {
			return false;
		}
		return ! empty( $token['permissions'][ $feature ] );
	}

	/**
	 * Whether a token allows an operation level.
	 *
	 * @param array  $token     Token.
	 * @param string $operation Operation key.
	 * @return bool
	 */
	public static function token_allows_operation( array $token, $operation ) {
		if ( empty( $token['operations'] ) || ! is_array( $token['operations'] ) ) {
			return false;
		}
		return ! empty( $token['operations'][ $operation ] );
	}

	/**
	 * Resolve operation level for an action.
	 *
	 * @param string $action  Action.
	 * @param string $feature Optional feature key.
	 * @return string
	 */
	public static function operation_for_action( $action, $feature = '' ) {
		// Spec: plugin/theme mutating actions are "critical", except ZIP package install.
		if ( in_array( $feature, array( 'plugins', 'themes' ), true ) ) {
			if ( in_array( $action, array( 'list', 'get', 'search' ), true ) ) {
				return 'read';
			}
			if ( in_array( $action, array( 'install_zip', 'export_zip' ), true ) ) {
				return 'zip_install';
			}
			return 'critical';
		}

		return isset( self::$action_operations[ $action ] ) ? self::$action_operations[ $action ] : 'critical';
	}

	/**
	 * Resolve required WordPress capabilities (may be multiple).
	 *
	 * @param string $feature Feature.
	 * @param string $action  Action.
	 * @param array  $input   Input.
	 * @return string[]
	 */
	public static function capabilities_for( $feature, $action, $input = array() ) {
		if ( 'content' === $feature ) {
			$type = isset( $input['type'] ) ? $input['type'] : 'post';
			if ( 'page' === $type ) {
				$map = array(
					'list'   => 'edit_pages',
					'search' => 'edit_pages',
					'get'    => 'edit_pages',
					'create' => 'edit_pages',
					'update' => 'edit_pages',
					'delete' => 'delete_pages',
				);
				return array( isset( $map[ $action ] ) ? $map[ $action ] : 'edit_pages' );
			}
			if ( 'create' === $action && isset( $input['status'] ) && 'publish' === $input['status'] ) {
				return array( 'publish_posts' );
			}
		}

		if ( 'users' === $feature ) {
			$caps = array();
			if ( 'create' === $action ) {
				$caps[] = 'create_users';
				$role   = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : 'subscriber';
				if ( 'subscriber' !== $role ) {
					$caps[] = 'promote_users';
				}
				return array_values( array_unique( $caps ) );
			}
			if ( 'update' === $action ) {
				$caps[] = 'edit_users';
				if ( ! empty( $input['role'] ) ) {
					$caps[] = 'promote_users';
				}
				return array_values( array_unique( $caps ) );
			}
		}

		if ( ! isset( self::$capability_map[ $feature ][ $action ] ) ) {
			return array( 'manage_options' );
		}

		return array( self::$capability_map[ $feature ][ $action ] );
	}

	/**
	 * Back-compat single capability helper.
	 *
	 * @param string $feature Feature.
	 * @param string $action  Action.
	 * @param array  $input   Input.
	 * @return string|null
	 */
	public static function capability_for( $feature, $action, $input = array() ) {
		$caps = self::capabilities_for( $feature, $action, $input );
		return isset( $caps[0] ) ? $caps[0] : null;
	}
}
