<?php
/**
 * Ability category and tool registration.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Abilities class.
 */
class Picot_Mcp_Abilities {

	/**
	 * Feature key => ability name.
	 *
	 * @var array<string, string>
	 */
	const FEATURE_ABILITIES = array(
		'content'  => 'picot-mcp/content',
		'taxonomy' => 'picot-mcp/taxonomy',
		'media'    => 'picot-mcp/media',
		'settings' => 'picot-mcp/settings',
		'plugins'  => 'picot-mcp/plugins',
		'themes'   => 'picot-mcp/themes',
		'users'    => 'picot-mcp/users',
	);

	/**
	 * Optional Picot product integrations (ability must be registered by the product plugin).
	 *
	 * @var array<string, string>
	 */
	const INTEGRATION_ABILITIES = array(
		'seo_writer'       => 'picot-ai-seo-writer/article',
		'aio_optimizer'    => 'picot-aio-ai-content-optimizer/optimize',
		'editor_converter' => 'picot-editor-converter/convert',
	);

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Abilities|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Abilities
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Ability names enabled by current settings.
	 *
	 * @return string[]
	 */
	public static function enabled_ability_names() {
		$settings = Picot_Mcp_Settings::instance();
		$names    = array();
		foreach ( self::FEATURE_ABILITIES as $feature => $ability ) {
			if ( $settings->is_feature_enabled( $feature ) ) {
				$names[] = $ability;
			}
		}
		foreach ( self::INTEGRATION_ABILITIES as $feature => $ability ) {
			if ( ! $settings->is_feature_enabled( $feature ) ) {
				continue;
			}
			// Only expose when the product plugin registered the ability.
			if ( function_exists( 'wp_get_ability' ) && wp_get_ability( $ability ) ) {
				$names[] = $ability;
			}
		}

		/**
		 * Filter ability names exposed on the Picot MCP server.
		 *
		 * Product plugins may append additional ability names.
		 *
		 * @param string[] $names Ability names.
		 */
		$names = apply_filters( 'picot_mcp_ability_names', $names );

		if ( ! is_array( $names ) ) {
			return array();
		}

		$clean = array();
		foreach ( $names as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( '' !== $name ) {
				$clean[] = $name;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Register ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		wp_register_ability_category(
			'picot-mcp',
			array(
				'label'       => __( 'Picot MCP', 'picot-mcp' ),
				'description' => __( 'WordPress operations exposed through Picot MCP.', 'picot-mcp' ),
			)
		);
	}

	/**
	 * Register all abilities (permission callbacks still gate execution).
	 *
	 * @return void
	 */
	public function register_abilities() {
		Picot_Mcp_Ability_Content::register();
		Picot_Mcp_Ability_Taxonomy::register();
		Picot_Mcp_Ability_Media::register();
		Picot_Mcp_Ability_Settings::register();
		Picot_Mcp_Ability_Plugins::register();
		Picot_Mcp_Ability_Themes::register();
		Picot_Mcp_Ability_Users::register();
	}

	/**
	 * Shared input schema fragment for action-based tools.
	 *
	 * @param string[] $actions Allowed actions.
	 * @param array    $extra   Extra properties.
	 * @param string[] $required Extra required fields beyond action.
	 * @return array
	 */
	public static function action_input_schema( array $actions, array $extra = array(), array $required = array() ) {
		$properties = array_merge(
			array(
				'action' => array(
					'type'        => 'string',
					'description' => __( 'Operation to perform.', 'picot-mcp' ),
					'enum'        => $actions,
				),
			),
			$extra
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_merge( array( 'action' ), $required ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Shared output schema.
	 *
	 * @return array
	 */
	public static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'picot-mcp' ),
				),
				'data'    => array(
					'type'        => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ),
					'description' => __( 'Result payload.', 'picot-mcp' ),
				),
				'error'   => array(
					'type'        => 'string',
					'description' => __( 'Error message when unsuccessful.', 'picot-mcp' ),
				),
				'code'    => array(
					'type'        => 'string',
					'description' => __( 'Stable error code when unsuccessful.', 'picot-mcp' ),
				),
			),
		);
	}

	/**
	 * Normalize ability result / WP_Error to response array and record usage.
	 *
	 * @param string $feature Feature key.
	 * @param string $action  Action name.
	 * @param mixed  $result  Handler result.
	 * @return array
	 */
	public static function respond( $feature, $action, $result ) {
		$ok      = ! is_wp_error( $result );
		$code    = '';
		$message = '';
		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$message = $result->get_error_message();
		}
		Picot_Mcp_Usage_Log::record( $feature, $action, $ok, $code, $message );
		return self::format_result( $result );
	}

	/**
	 * Normalize ability result / WP_Error to response array.
	 *
	 * @param mixed $result Handler result.
	 * @return array
	 */
	public static function format_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}
}
