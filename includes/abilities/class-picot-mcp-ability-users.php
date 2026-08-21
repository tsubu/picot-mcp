<?php
/**
 * Users ability.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Users class.
 */
class Picot_Mcp_Ability_Users {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/users',
			array(
				'label'               => __( 'WordPress Users', 'picot-mcp' ),
				'description'         => __( 'List, create, update, or delete users. Secrets are never returned.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'search', 'get', 'create', 'update', 'delete' ),
					array(
						'id'           => array( 'type' => 'integer' ),
						'search'       => array( 'type' => 'string' ),
						'user_login'   => array( 'type' => 'string' ),
						'user_email'   => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'first_name'   => array( 'type' => 'string' ),
						'last_name'    => array( 'type' => 'string' ),
						'user_url'     => array( 'type' => 'string' ),
						'description'  => array( 'type' => 'string' ),
						'locale'       => array( 'type' => 'string' ),
						'role'         => array( 'type' => 'string' ),
						'password'     => array( 'type' => 'string' ),
						'reassign'     => array( 'type' => 'integer' ),
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
		$check  = Picot_Mcp_Permissions::assert( 'users', $action, is_array( $input ) ? $input : array() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Critical: delete; password/role changes on update; non-subscriber create.
		if ( in_array( $action, array( 'create', 'update', 'delete' ), true ) ) {
			$role           = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : '';
			$needs_critical = ( 'delete' === $action );

			if ( 'create' === $action && '' !== $role && 'subscriber' !== $role ) {
				$needs_critical = true;
			}
			if ( 'update' === $action && ( ! empty( $input['password'] ) || ( '' !== $role ) ) ) {
				$needs_critical = true;
			}

			if ( $needs_critical ) {
				if ( ! Picot_Mcp_Settings::instance()->is_operation_allowed( 'critical' ) ) {
					return Picot_Mcp_Errors::make(
						'operation_not_allowed',
						__( 'User role changes, password changes, or delete operations require critical permissions.', 'picot-mcp' )
					);
				}
				$token = Picot_Mcp_Auth::instance()->get_current_token();
				if ( is_array( $token ) && ! Picot_Mcp_Permissions::token_allows_operation( $token, 'critical' ) ) {
					return Picot_Mcp_Errors::make(
						'operation_not_allowed',
						__( 'This API key is not allowed to perform critical user operations.', 'picot-mcp' )
					);
				}
			}
		}

		return true;
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
			case 'search':
				return Picot_Mcp_Abilities::respond( 'users', $action, self::list_users( $input ) );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'users', $action, self::get_user( $input ) );
			case 'create':
				return Picot_Mcp_Abilities::respond( 'users', $action, self::create_user( $input ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'users', $action, self::update_user( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'users', $action, self::delete_user( $input ) );
			default:
				return Picot_Mcp_Abilities::respond( 'users', $action, 					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List users.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function list_users( array $input ) {
		$args = array(
			'number' => 50,
			'fields' => 'all',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$users = get_users( $args );
		return array(
			'items' => array_map( array( __CLASS__, 'serialize_user' ), $users ),
		);
	}

	/**
	 * Get user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function get_user( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'User not found.', 'picot-mcp' ) );
		}
		return self::serialize_user( $user );
	}

	/**
	 * Create user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function create_user( array $input ) {
		$login = isset( $input['user_login'] ) ? sanitize_user( $input['user_login'], true ) : '';
		$email = isset( $input['user_email'] ) ? sanitize_email( $input['user_email'] ) : '';
		if ( ! $login || ! is_email( $email ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'user_login and user_email are required.', 'picot-mcp' ) );
		}
		if ( empty( $input['password'] ) || ! is_string( $input['password'] ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'password is required (secrets are never returned).', 'picot-mcp' ) );
		}

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : 'subscriber';
		if ( ! self::is_assignable_role( $role ) ) {
			return Picot_Mcp_Errors::make( 'wordpress_permission_denied', __( 'Cannot assign this role.', 'picot-mcp' ) );
		}

		$password = $input['password'];
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => isset( $input['display_name'] ) ? sanitize_text_field( $input['display_name'] ) : $login,
				'first_name'   => isset( $input['first_name'] ) ? sanitize_text_field( $input['first_name'] ) : '',
				'last_name'    => isset( $input['last_name'] ) ? sanitize_text_field( $input['last_name'] ) : '',
				'user_url'     => isset( $input['user_url'] ) ? esc_url_raw( $input['user_url'] ) : '',
				'description'  => isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '',
				'locale'       => isset( $input['locale'] ) ? sanitize_text_field( $input['locale'] ) : '',
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return self::serialize_user( get_user_by( 'id', $user_id ) );
	}

	/**
	 * Update user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function update_user( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'User not found.', 'picot-mcp' ) );
		}

		$edit = Picot_Mcp_Permissions::assert_user_cap( 'edit', $id );
		if ( is_wp_error( $edit ) ) {
			return $edit;
		}

		$data = array( 'ID' => $id );
		$map  = array(
			'display_name' => 'sanitize_text_field',
			'first_name'   => 'sanitize_text_field',
			'last_name'    => 'sanitize_text_field',
			'user_url'     => 'esc_url_raw',
			'description'  => 'sanitize_textarea_field',
			'locale'       => 'sanitize_text_field',
			'user_email'   => 'sanitize_email',
		);
		foreach ( $map as $field => $sanitizer ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $field ] = call_user_func( $sanitizer, $input[ $field ] );
			}
		}

		if ( ! empty( $input['password'] ) ) {
			$data['user_pass'] = $input['password'];
		}

		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $input['role'] ) ) {
			$role = sanitize_key( $input['role'] );
			if ( ! self::is_assignable_role( $role ) ) {
				return Picot_Mcp_Errors::make( 'wordpress_permission_denied', __( 'Cannot assign this role.', 'picot-mcp' ) );
			}
			$promote = Picot_Mcp_Permissions::assert_user_cap( 'promote', $id );
			if ( is_wp_error( $promote ) ) {
				return $promote;
			}
			$user->set_role( $role );
		}

		return self::serialize_user( get_user_by( 'id', $id ) );
	}

	/**
	 * Whether the current user may assign a role (editable roles only).
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	private static function is_assignable_role( $role ) {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable = get_editable_roles();
		return isset( $editable[ $role ] );
	}

	/**
	 * Delete user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function delete_user( array $input ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( ! get_user_by( 'id', $id ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'User not found.', 'picot-mcp' ) );
		}
		if ( (int) get_current_user_id() === $id ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Cannot delete the authenticated user.', 'picot-mcp' ) );
		}

		$delete = Picot_Mcp_Permissions::assert_user_cap( 'delete', $id );
		if ( is_wp_error( $delete ) ) {
			return $delete;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : null;
		$deleted  = wp_delete_user( $id, $reassign );
		if ( ! $deleted ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to delete user.', 'picot-mcp' ) );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Serialize user without secrets.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function serialize_user( $user ) {
		return array(
			'id'           => (int) $user->ID,
			'user_login'   => $user->user_login,
			'display_name' => $user->display_name,
			'user_email'   => $user->user_email,
			'roles'        => array_values( $user->roles ),
			'user_url'     => $user->user_url,
			'description'  => $user->description,
			'locale'       => get_user_locale( $user ),
			'registered'   => $user->user_registered,
		);
	}
}
