<?php
/**
 * Media ability.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Ability_Media class.
 */
class Picot_Mcp_Ability_Media {

	/**
	 * Register ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'picot-mcp/media',
			array(
				'label'               => __( 'WordPress Media', 'picot-mcp' ),
				'description'         => __( 'List, upload, update, or delete media. Upload uses Base64.', 'picot-mcp' ),
				'category'            => 'picot-mcp',
				'input_schema'        => Picot_Mcp_Abilities::action_input_schema(
					array( 'list', 'search', 'get', 'upload', 'update', 'delete' ),
					array(
						'id'          => array( 'type' => 'integer' ),
						'search'      => array( 'type' => 'string' ),
						'filename'    => array( 'type' => 'string' ),
						'mime_type'   => array( 'type' => 'string' ),
						'base64_data' => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'post'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
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
		return Picot_Mcp_Permissions::assert( 'media', $action, is_array( $input ) ? $input : array() );
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
				return Picot_Mcp_Abilities::respond( 'media', $action, self::list_media( $input ) );
			case 'get':
				return Picot_Mcp_Abilities::respond( 'media', $action, self::get_media( $input ) );
			case 'upload':
				return Picot_Mcp_Abilities::respond( 'media', $action, self::upload_media( $input ) );
			case 'update':
				return Picot_Mcp_Abilities::respond( 'media', $action, self::update_media( $input ) );
			case 'delete':
				return Picot_Mcp_Abilities::respond( 'media', $action, self::delete_media( $input ) );
			default:
				return Picot_Mcp_Abilities::respond( 'media', $action, 					Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Unknown action.', 'picot-mcp' ) )
				);
		}
	}

	/**
	 * List media.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function list_media( array $input ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
			'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		// Users without edit_others_posts only see their own attachments.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = self::serialize_media( $post );
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Get media item.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function get_media( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Media not found.', 'picot-mcp' ) );
		}

		$cap = Picot_Mcp_Permissions::assert_post_cap( 'edit', $id );
		if ( is_wp_error( $cap ) ) {
			$read = Picot_Mcp_Permissions::assert_post_cap( 'read', $id );
			if ( is_wp_error( $read ) ) {
				return $cap;
			}
		}

		return self::serialize_media( $post, true );
	}

	/**
	 * Upload via Base64.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function upload_media( array $input ) {
		$filename = isset( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : '';
		$mime     = isset( $input['mime_type'] ) ? sanitize_mime_type( $input['mime_type'] ) : '';
		$b64      = isset( $input['base64_data'] ) ? $input['base64_data'] : '';

		if ( '' === $filename || '' === $b64 ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'filename and base64_data are required.', 'picot-mcp' ) );
		}

		if ( preg_match( '/^data:[^;]+;base64,/', $b64 ) ) {
			$b64 = preg_replace( '/^data:[^;]+;base64,/', '', $b64 );
		}

		$binary = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Intentional media upload.
		if ( false === $binary ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Invalid Base64 data.', 'picot-mcp' ) );
		}

		$max_size = wp_max_upload_size();
		if ( $max_size && strlen( $binary ) > $max_size ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'File exceeds the maximum upload size.', 'picot-mcp' ) );
		}

		$filetype = wp_check_filetype( $filename, null );
		if ( empty( $filetype['type'] ) || empty( $filetype['ext'] ) ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'File type is not allowed.', 'picot-mcp' ) );
		}

		$allowed_mimes = get_allowed_mime_types();
		$mime_ok       = false;
		foreach ( $allowed_mimes as $exts => $allowed_type ) {
			$ext_list = explode( '|', $exts );
			if ( in_array( $filetype['ext'], $ext_list, true ) && $allowed_type === $filetype['type'] ) {
				$mime_ok = true;
				break;
			}
		}
		if ( ! $mime_ok ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'File type is not allowed.', 'picot-mcp' ) );
		}

		$mime = $filetype['type'];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Could not create a temporary file.', 'picot-mcp' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp binary write before sideload.
		if ( false === file_put_contents( $tmp, $binary ) ) {
			wp_delete_file( $tmp );
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Could not write temporary file.', 'picot-mcp' ) );
		}

		$check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
			wp_delete_file( $tmp );
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'File type validation failed.', 'picot-mcp' ) );
		}

		$file_array = array(
			'name'     => $filename,
			'type'     => $check['type'],
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => strlen( $binary ),
		);

		$post_parent = isset( $input['post'] ) ? absint( $input['post'] ) : 0;
		if ( $post_parent > 0 && ! current_user_can( 'edit_post', $post_parent ) ) {
			return Picot_Mcp_Errors::make(
				'wordpress_permission_denied',
				__( 'WordPress capability "edit_post" is required for the parent post.', 'picot-mcp' )
			);
		}
		$attach_id = media_handle_sideload( $file_array, $post_parent );
		if ( is_wp_error( $attach_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return Picot_Mcp_Errors::make( 'upload_failed', $attach_id->get_error_message() );
		}

		$update = array( 'ID' => $attach_id );
		if ( isset( $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $input['description'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		return self::serialize_media( get_post( $attach_id ), true );
	}

	/**
	 * Update media metadata.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function update_media( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Media not found.', 'picot-mcp' ) );
		}

		$cap = Picot_Mcp_Permissions::assert_post_cap( 'edit', $id );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$data = array( 'ID' => $id );
		if ( isset( $input['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$data['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$data['post_content'] = sanitize_textarea_field( $input['description'] );
		}
		if ( isset( $input['post'] ) ) {
			$parent = absint( $input['post'] );
			if ( $parent > 0 && ! current_user_can( 'edit_post', $parent ) ) {
				return Picot_Mcp_Errors::make(
					'wordpress_permission_denied',
					__( 'WordPress capability "edit_post" is required for the parent post.', 'picot-mcp' )
				);
			}
			$data['post_parent'] = $parent;
		}
		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}
		return self::serialize_media( get_post( $id ), true );
	}

	/**
	 * Delete media.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function delete_media( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Media not found.', 'picot-mcp' ) );
		}

		$cap = Picot_Mcp_Permissions::assert_post_cap( 'delete', $id );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$deleted = wp_delete_attachment( $id, true );
		if ( ! $deleted ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Failed to delete media.', 'picot-mcp' ) );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Serialize media.
	 *
	 * @param WP_Post $post   Attachment.
	 * @param bool    $detail Detail.
	 * @return array
	 */
	private static function serialize_media( $post, $detail = false ) {
		$data = array(
			'id'        => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'url'       => wp_get_attachment_url( $post->ID ),
			'mime_type' => $post->post_mime_type,
			'alt_text'  => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'   => $post->post_excerpt,
		);
		if ( $detail ) {
			$data['description'] = $post->post_content;
			$data['post']        = (int) $post->post_parent;
		}
		return $data;
	}
}
