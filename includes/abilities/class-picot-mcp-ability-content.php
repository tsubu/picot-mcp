<?php
/**
 * Content ability (posts & pages).
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Content class.
 */
class Picot_Mcp_Ability_Content {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/content',
			array(
				'label'               => __( 'WordPress Content', 'picot-mcp' ),
				'description'         => __( 'List, search, get, create, update, or trash posts and pages.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'search', 'get', 'create', 'update', 'delete' ),
					array(
						'type'    => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'page' ),
							'description' => __( 'Content type.', 'picot-mcp' ),
						),
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'Post or page ID.', 'picot-mcp' ),
						),
						'search'  => array(
							'type'        => 'string',
							'description' => __( 'Search keyword.', 'picot-mcp' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'Post status.', 'picot-mcp' ),
						),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'date'    => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'tags'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'featured_media' => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
						'page'     => array( 'type' => 'integer' ),
					)
				),
				'output_schema'       => Picot_Mcp_Abilities::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly' => false,
					),
				),
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
		return Picot_Mcp_Permissions::assert( 'content', $action, is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute callback.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		$action = sanitize_key( $input['action'] );
		$type   = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'post';
		if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
			return Picot_Mcp_Abilities::respond(
				'content',
				$action,
				Picot_Mcp_Errors::make( 'invalid_parameter', __( 'type must be post or page.', 'picot-mcp' ) )
			);
		}

		switch ( $action ) {
			case 'list':
			case 'search':
				return Picot_Mcp_Abilities::respond( 'content', $action, self::list_items( $input, $type ) );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'content', $action, self::get_item( $input, $type ) );
			case 'create':
				return Picot_Mcp_Abilities::respond( 'content', $action, self::create_item( $input, $type ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'content', $action, self::update_item( $input, $type ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'content', $action, self::delete_item( $input, $type ) );
			default:
				return Picot_Mcp_Abilities::respond(
					'content',
					$action,
					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List / search posts or pages.
	 *
	 * @param array  $input Input.
	 * @param string $type  post|page.
	 * @return array|WP_Error
	 */
	private static function list_items( array $input, $type ) {
		$args = array(
			'post_type'      => $type,
			'post_status'    => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any',
			'posts_per_page' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
			'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'perm'           => 'readable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		// Authors without edit_others_* only see their own posts in MCP lists.
		$others_cap = ( 'page' === $type ) ? 'edit_others_pages' : 'edit_others_posts';
		if ( ! current_user_can( $others_cap ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = self::serialize_post( $post );
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Get a single post/page.
	 *
	 * @param array  $input Input.
	 * @param string $type  Type.
	 * @return array|WP_Error
	 */
	private static function get_item( array $input, $type ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Content not found.', 'picot-mcp' ) );
		}
		$cap = Picot_Mcp_Permissions::assert_post_cap( 'edit', $id );
		if ( is_wp_error( $cap ) ) {
			// Allow read-only get when the user can read but not edit.
			$read = Picot_Mcp_Permissions::assert_post_cap( 'read', $id );
			if ( is_wp_error( $read ) ) {
				return $cap;
			}
		}
		return self::serialize_post( $post, true );
	}

	/**
	 * Create post/page.
	 *
	 * @param array  $input Input.
	 * @param string $type  Type.
	 * @return array|WP_Error
	 */
	private static function create_item( array $input, $type ) {
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
		$status_check = self::assert_post_status( $status, $type );
		if ( is_wp_error( $status_check ) ) {
			return $status_check;
		}
		$data = array(
			'post_type'    => $type,
			'post_title'   => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '',
			'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $input['date'] ) ) {
			$data['post_date'] = sanitize_text_field( $input['date'] );
		}

		$post_id = wp_insert_post( $data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_terms_and_thumb( $post_id, $input, $type );
		return self::serialize_post( get_post( $post_id ), true );
	}

	/**
	 * Update post/page.
	 *
	 * @param array  $input Input.
	 * @param string $type  Type.
	 * @return array|WP_Error
	 */
	private static function update_item( array $input, $type ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Content not found.', 'picot-mcp' ) );
		}

		$cap = Picot_Mcp_Permissions::assert_post_cap( 'edit', $id );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$data = array( 'ID' => $id );
		if ( isset( $input['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$data['post_content'] = wp_kses_post( $input['content'] );
		}
		if ( isset( $input['excerpt'] ) ) {
			$data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}
		if ( isset( $input['status'] ) ) {
			$status       = sanitize_key( $input['status'] );
			$status_check = self::assert_post_status( $status, $type );
			if ( is_wp_error( $status_check ) ) {
				return $status_check;
			}
			$data['post_status'] = $status;
		}
		if ( ! empty( $input['date'] ) ) {
			$data['post_date'] = sanitize_text_field( $input['date'] );
		}

		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::apply_terms_and_thumb( $id, $input, $type );
		return self::serialize_post( get_post( $id ), true );
	}

	/**
	 * Validate post status and required capabilities.
	 *
	 * @param string $status Status.
	 * @param string $type   post|page.
	 * @return true|WP_Error
	 */
	private static function assert_post_status( $status, $type ) {
		$allowed = array( 'draft', 'pending', 'publish', 'private', 'future' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unsupported post status.', 'picot-mcp' ) );
		}

		if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
			$cap = ( 'page' === $type ) ? 'publish_pages' : 'publish_posts';
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

		if ( 'private' === $status ) {
			$cap = ( 'page' === $type ) ? 'edit_private_pages' : 'edit_private_posts';
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
	 * Move to trash (no force delete in v1).
	 *
	 * @param array  $input Input.
	 * @param string $type  Type.
	 * @return array|WP_Error
	 */
	private static function delete_item( array $input, $type ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Content not found.', 'picot-mcp' ) );
		}

		$cap = Picot_Mcp_Permissions::assert_post_cap( 'delete', $id );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$trashed = wp_trash_post( $id );
		if ( ! $trashed ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to move content to trash.', 'picot-mcp' ) );
		}

		return array(
			'id'     => $id,
			'status' => 'trash',
		);
	}

	/**
	 * Apply categories/tags/featured image.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $input   Input.
	 * @param string $type    Type.
	 * @return void
	 */
	private static function apply_terms_and_thumb( $post_id, array $input, $type ) {
		if ( 'post' === $type && isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'absint', $input['categories'] ) );
		}
		if ( 'post' === $type && isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'absint', $input['tags'] ) );
		}
		if ( isset( $input['featured_media'] ) ) {
			$thumb = absint( $input['featured_media'] );
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
	}

	/**
	 * Serialize post for MCP response.
	 *
	 * @param WP_Post $post   Post.
	 * @param bool    $detail Full detail.
	 * @return array
	 */
	private static function serialize_post( $post, $detail = false ) {
		$data = array(
			'id'      => (int) $post->ID,
			'type'    => $post->post_type,
			'title'   => get_the_title( $post ),
			'status'  => $post->post_status,
			'date'    => $post->post_date,
			'author'  => (int) $post->post_author,
			'link'    => get_permalink( $post ),
			'excerpt' => $post->post_excerpt,
		);

		if ( $detail ) {
			$data['content']        = $post->post_content;
			$data['featured_media'] = (int) get_post_thumbnail_id( $post );
			if ( 'post' === $post->post_type ) {
				$data['categories'] = wp_get_post_categories( $post->ID );
				$data['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
			}
		}

		return $data;
	}
}
