<?php
/**
 * Taxonomy ability (categories & tags).
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Taxonomy class.
 */
class Picot_Mcp_Ability_Taxonomy {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/taxonomy',
			array(
				'label'               => __( 'WordPress Taxonomy', 'picot-mcp' ),
				'description'         => __( 'Manage categories and tags.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'search', 'get', 'create', 'update', 'delete' ),
					array(
						'taxonomy'    => array(
							'type' => 'string',
							'enum' => array( 'category', 'tag', 'post_tag' ),
						),
						'id'          => array( 'type' => 'integer' ),
						'search'      => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
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
		return Picot_Mcp_Permissions::assert( 'taxonomy', $action, is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		$action   = sanitize_key( $input['action'] );
		$taxonomy = self::normalize_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : 'category' );

		switch ( $action ) {
			case 'list':
			case 'search':
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, self::list_terms( $input, $taxonomy ) );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, self::get_term( $input, $taxonomy ) );
			case 'create':
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, self::create_term( $input, $taxonomy ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, self::update_term( $input, $taxonomy ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, self::delete_term( $input, $taxonomy ) );
			default:
				return Picot_Mcp_Abilities::respond( 'taxonomy', $action, 					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * Normalize taxonomy slug.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function normalize_taxonomy( $taxonomy ) {
		$taxonomy = sanitize_key( $taxonomy );
		if ( 'tag' === $taxonomy ) {
			return 'post_tag';
		}
		return in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ? $taxonomy : 'category';
	}

	/**
	 * List terms.
	 *
	 * @param array  $input    Input.
	 * @param string $taxonomy Taxonomy.
	 * @return array|WP_Error
	 */
	private static function list_terms( array $input, $taxonomy ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 100,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array(
			'items' => array_map( array( __CLASS__, 'serialize_term' ), $terms ),
		);
	}

	/**
	 * Get term.
	 *
	 * @param array  $input    Input.
	 * @param string $taxonomy Taxonomy.
	 * @return array|WP_Error
	 */
	private static function get_term( array $input, $taxonomy ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$term = get_term( $id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Term not found.', 'picot-mcp' ) );
		}
		return self::serialize_term( $term );
	}

	/**
	 * Create term.
	 *
	 * @param array  $input    Input.
	 * @param string $taxonomy Taxonomy.
	 * @return array|WP_Error
	 */
	private static function create_term( array $input, $taxonomy ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'name is required.', 'picot-mcp' ) );
		}
		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( 'category' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = absint( $input['parent'] );
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::serialize_term( get_term( (int) $result['term_id'], $taxonomy ) );
	}

	/**
	 * Update term.
	 *
	 * @param array  $input    Input.
	 * @param string $taxonomy Taxonomy.
	 * @return array|WP_Error
	 */
	private static function update_term( array $input, $taxonomy ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( ! $id ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'id is required.', 'picot-mcp' ) );
		}
		$args = array();
		if ( isset( $input['name'] ) ) {
			$args['name'] = sanitize_text_field( $input['name'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( 'category' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = absint( $input['parent'] );
		}

		$result = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::serialize_term( get_term( $id, $taxonomy ) );
	}

	/**
	 * Delete term.
	 *
	 * @param array  $input    Input.
	 * @param string $taxonomy Taxonomy.
	 * @return array|WP_Error
	 */
	private static function delete_term( array $input, $taxonomy ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$deleted = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}
		if ( ! $deleted ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Term not found.', 'picot-mcp' ) );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Serialize term.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	public static function serialize_term( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => 'post_tag' === $term->taxonomy ? 'tag' : $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}
}
