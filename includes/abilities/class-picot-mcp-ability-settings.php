<?php
/**
 * Site settings ability (allow-list only).
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Settings class.
 */
class Picot_Mcp_Ability_Settings {

	/**
	 * Readable/writable option map: mcp key => wp option.
	 *
	 * @return array<string, string>
	 */
	private static function allowlist() {
		return array(
			'title'                 => 'blogname',
			'tagline'               => 'blogdescription',
			'timezone'              => 'timezone_string',
			'date_format'           => 'date_format',
			'time_format'           => 'time_format',
			'start_of_week'         => 'start_of_week',
			'language'              => 'WPLANG',
			'posts_per_page'        => 'posts_per_page',
			'show_on_front'         => 'show_on_front',
			'page_on_front'         => 'page_on_front',
			'page_for_posts'        => 'page_for_posts',
			'default_category'      => 'default_category',
			'default_comment_status'=> 'default_comment_status',
			'default_ping_status'   => 'default_ping_status',
			'site_logo'             => 'site_logo',
			'site_icon'             => 'site_icon',
		);
	}

	/**
	 * Never writable.
	 *
	 * @return string[]
	 */
	private static function blocked() {
		return array( 'siteurl', 'home', 'admin_email' );
	}

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		$value_properties = array();
		foreach ( array_keys( self::allowlist() ) as $key ) {
			$value_properties[ $key ] = array(
				'description' => $key,
			);
		}

		wp_register_ability(
			'picot-mcp/settings',
			array(
				'label'               => __( 'WordPress Settings', 'picot-mcp' ),
				'description'         => __( 'Get or update allow-listed site settings.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'get', 'update' ),
					array(
						'keys'   => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => array_keys( self::allowlist() ),
							),
						),
						'values' => array(
							'type'                 => 'object',
							'properties'           => $value_properties,
							'additionalProperties' => false,
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
		return Picot_Mcp_Permissions::assert( 'settings', $action, is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		$action = sanitize_key( $input['action'] );
		if ( 'get' === $action ) {
			return Picot_Mcp_Abilities::respond( 'settings', $action, self::get_settings( $input ) );
		}
		if ( 'update' === $action ) {
			return Picot_Mcp_Abilities::respond( 'settings', $action, self::update_settings( $input ) );
		}
		return Picot_Mcp_Abilities::respond( 'settings', $action, 			Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
		);
	}

	/**
	 * Get settings.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function get_settings( array $input ) {
		$map  = self::allowlist();
		$keys = isset( $input['keys'] ) && is_array( $input['keys'] ) ? $input['keys'] : array_keys( $map );
		$out  = array();
		foreach ( $keys as $key ) {
			$key = sanitize_key( $key );
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = get_option( $map[ $key ] );
		}
		return $out;
	}

	/**
	 * Update settings.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function update_settings( array $input ) {
		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		if ( empty( $values ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'values is required.', 'picot-mcp' ) );
		}

		$map     = self::allowlist();
		$blocked = self::blocked();
		$updated = array();

		foreach ( $values as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $blocked, true ) || ( isset( $map[ $key ] ) && in_array( $map[ $key ], $blocked, true ) ) ) {
				return Picot_Mcp_Errors::make(
					'operation_not_allowed',
					sprintf(
						/* translators: %s: setting key */
						__( 'Setting "%s" cannot be changed via MCP.', 'picot-mcp' ),
						$key
					)
				);
			}
			if ( ! isset( $map[ $key ] ) ) {
				return Picot_Mcp_Errors::make(
					'invalid_parameter',
					sprintf(
						/* translators: %s: setting key */
						__( 'Setting "%s" is not in the allow list.', 'picot-mcp' ),
						$key
					)
				);
			}

			$option = $map[ $key ];
			if ( in_array( $key, array( 'posts_per_page', 'start_of_week', 'page_on_front', 'page_for_posts', 'default_category', 'site_logo', 'site_icon' ), true ) ) {
				$value = absint( $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}

			update_option( $option, $value );
			$updated[ $key ] = get_option( $option );
		}

		return $updated;
	}
}
