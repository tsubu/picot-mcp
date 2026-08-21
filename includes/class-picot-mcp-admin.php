<?php
/**
 * Settings → MCP admin screen.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Admin class.
 */
class Picot_Mcp_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Picot_Mcp_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Picot_Mcp_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook admin UI.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_https_notice' ) );
		add_action( 'wp_ajax_picot_mcp_reveal_key', array( $this, 'ajax_reveal_key' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PICOT_MCP_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add Settings → MCP.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Picot MCP', 'picot-mcp' ),
			__( 'MCP', 'picot-mcp' ),
			Picot_Mcp_Capabilities::menu_capability(),
			'picot-mcp',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Plugin list settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( ! Picot_Mcp_Capabilities::current_user_can_manage() ) {
			return $links;
		}
		$url = admin_url( 'options-general.php?page=picot-mcp' );
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'picot-mcp' ) )
		);
		return $links;
	}

	/**
	 * Warn when the site is not served over HTTPS.
	 *
	 * @return void
	 */
	public function maybe_https_notice() {
		if ( ! Picot_Mcp_Capabilities::current_user_can_manage() || is_ssl() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_picot-mcp' !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'MCP API keys are sent with Bearer authentication. HTTPS is strongly recommended in production.', 'picot-mcp' );
		echo '</p></div>';
	}

	/**
	 * Enqueue admin copy helper.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_picot-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'picot-mcp-admin',
			PICOT_MCP_URL . 'assets/admin.css',
			array(),
			PICOT_MCP_VERSION
		);
		wp_enqueue_script(
			'picot-mcp-admin',
			PICOT_MCP_URL . 'assets/admin.js',
			array(),
			PICOT_MCP_VERSION,
			true
		);
		wp_localize_script(
			'picot-mcp-admin',
			'picotMcpAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'picot_mcp_reveal_key' ),
				'siteName' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'mcpUrl'   => Picot_Mcp_Server::endpoint_url(),
				'i18n'     => array(
					'copyFailed' => __( 'Failed to retrieve the API key.', 'picot-mcp' ),
				),
			)
		);
	}

	/**
	 * AJAX: reveal plaintext API key for clipboard copy (admins only).
	 *
	 * @return void
	 */
	public function ajax_reveal_key() {
		if ( ! Picot_Mcp_Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'picot-mcp' ) ), 403 );
		}
		check_ajax_referer( 'picot_mcp_reveal_key', 'nonce' );

		$token_id = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		if ( '' === $token_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'picot-mcp' ) ), 400 );
		}

		$plaintext = '';
		foreach ( Picot_Mcp_Settings::instance()->get_tokens() as $token ) {
			if ( empty( $token['token_id'] ) || $token['token_id'] !== $token_id ) {
				continue;
			}
			$plaintext = Picot_Mcp_Api_Key::reveal_plaintext( $token );
			break;
		}

		if ( '' === $plaintext ) {
			wp_send_json_error( array( 'message' => __( 'This key has no copyable data. Please issue a new key.', 'picot-mcp' ) ), 404 );
		}

		wp_send_json_success( array( 'key' => $plaintext ) );
	}

	/**
	 * Handle form posts with Post-Redirect-Get.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! Picot_Mcp_Capabilities::current_user_can_manage() ) {
			return;
		}

		if ( empty( $_POST['picot_mcp_action'] ) ) {
			return;
		}

		check_admin_referer( 'picot_mcp_admin', 'picot_mcp_nonce' );

		$action   = sanitize_key( wp_unslash( $_POST['picot_mcp_action'] ) );
		$redirect = admin_url( 'options-general.php?page=picot-mcp' );
		$query    = array();

		switch ( $action ) {
			case 'save_settings':
				$this->save_settings_from_post();
				$query['picot_mcp_notice'] = 'saved';
				$query['picot_mcp_tab']    = 'settings';
				break;

			case 'issue_key':
				$user_id = isset( $_POST['picot_mcp_key_user'] ) ? absint( wp_unslash( $_POST['picot_mcp_key_user'] ) ) : 0;
				if ( $user_id < 1 ) {
					$query['picot_mcp_notice'] = 'key_error';
					$query['picot_mcp_tab']    = 'create';
					break;
				}
				$label    = isset( $_POST['picot_mcp_key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['picot_mcp_key_label'] ) ) : '';
				$settings = Picot_Mcp_Settings::instance();
				$features = array( 'content', 'taxonomy', 'media', 'settings', 'plugins', 'themes', 'users' );
				$ops      = array( 'read', 'write', 'critical', 'zip_install' );
				$perms    = array();
				$opers    = array();
				$feat_src = $this->sanitize_checkbox_map( $this->post_array( 'picot_mcp_new_feature' ) );
				$op_src   = $this->sanitize_checkbox_map( $this->post_array( 'picot_mcp_new_operation' ) );
				foreach ( $features as $feature ) {
					$perms[ $feature ] = ! empty( $feat_src[ $feature ] ) && $settings->is_feature_enabled( $feature );
				}
				foreach ( $ops as $op ) {
					$opers[ $op ] = ! empty( $op_src[ $op ] ) && $settings->is_operation_allowed( $op );
				}
				$result = Picot_Mcp_Api_Key::generate( $user_id, $label, $perms, $opers );
				if ( is_wp_error( $result ) ) {
					$query['picot_mcp_notice'] = 'key_error';
					$query['picot_mcp_tab']    = 'create';
				} else {
					$flash_id = strtolower( wp_generate_password( 20, false, false ) );
					set_transient(
						'picot_mcp_flash_' . $flash_id,
						array(
							'key'   => $result['plaintext'],
							'label' => $label,
						),
						60
					);
					$query['picot_mcp_notice'] = 'key_issued';
					$query['picot_mcp_flash']  = $flash_id;
					$query['picot_mcp_tab']    = 'list';
				}
				break;

			case 'revoke_key':
				$token_id = isset( $_POST['picot_mcp_token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['picot_mcp_token_id'] ) ) : '';
				$query['picot_mcp_notice'] = Picot_Mcp_Api_Key::revoke( $token_id ) ? 'key_revoked' : 'key_error';
				$query['picot_mcp_tab']    = 'list';
				break;

			case 'update_key':
				$token_id = isset( $_POST['picot_mcp_token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['picot_mcp_token_id'] ) ) : '';
				$user_id  = isset( $_POST['picot_mcp_edit_user'][ $token_id ] ) ? absint( wp_unslash( $_POST['picot_mcp_edit_user'][ $token_id ] ) ) : 0;
				$label    = isset( $_POST['picot_mcp_edit_label'][ $token_id ] ) ? sanitize_text_field( wp_unslash( $_POST['picot_mcp_edit_label'][ $token_id ] ) ) : '';
				$settings = Picot_Mcp_Settings::instance();
				$features = array( 'content', 'taxonomy', 'media', 'settings', 'plugins', 'themes', 'users' );
				$ops      = array( 'read', 'write', 'critical', 'zip_install' );
				$perms    = array();
				$opers    = array();
				$edit_features = $this->post_array( 'picot_mcp_edit_feature' );
				$edit_ops      = $this->post_array( 'picot_mcp_edit_operation' );
				$feat_src      = $this->sanitize_checkbox_map( isset( $edit_features[ $token_id ] ) ? $edit_features[ $token_id ] : array() );
				$op_src        = $this->sanitize_checkbox_map( isset( $edit_ops[ $token_id ] ) ? $edit_ops[ $token_id ] : array() );

				$existing_token = null;
				foreach ( $settings->get_tokens() as $token_row ) {
					if ( ! empty( $token_row['token_id'] ) && $token_row['token_id'] === $token_id ) {
						$existing_token = $token_row;
						break;
					}
				}

				foreach ( $features as $feature ) {
					if ( ! $settings->is_feature_enabled( $feature ) ) {
						// Keep prior key preference while the site feature is disabled (checkbox not posted).
						$perms[ $feature ] = ( is_array( $existing_token ) && ! empty( $existing_token['permissions'][ $feature ] ) );
						continue;
					}
					$perms[ $feature ] = ! empty( $feat_src[ $feature ] );
				}
				foreach ( $ops as $op ) {
					if ( ! $settings->is_operation_allowed( $op ) ) {
						$opers[ $op ] = ( is_array( $existing_token ) && ! empty( $existing_token['operations'][ $op ] ) );
						continue;
					}
					$opers[ $op ] = ! empty( $op_src[ $op ] );
				}
				$result = Picot_Mcp_Api_Key::update(
					$token_id,
					array(
						'label'       => $label,
						'user_id'     => $user_id,
						'permissions' => $perms,
						'operations'  => $opers,
					)
				);
				$query['picot_mcp_notice'] = is_wp_error( $result ) ? 'key_error' : 'key_updated';
				$query['picot_mcp_tab']    = 'list';
				break;

			case 'clear_logs':
				Picot_Mcp_Usage_Log::clear();
				$query['picot_mcp_notice'] = 'logs_cleared';
				$query['picot_mcp_tab']    = 'logs';
				break;

			default:
				return;
		}

		if ( isset( $query['picot_mcp_tab'] ) && ! in_array( $query['picot_mcp_tab'], array( 'list', 'create', 'settings', 'logs' ), true ) ) {
			$query['picot_mcp_tab'] = 'list';
		}

		wp_safe_redirect( add_query_arg( $query, $redirect ) );
		exit;
	}

	/**
	 * Consume one-time issued key flash (plaintext + label).
	 *
	 * @param string $flash_id Flash id from redirect query.
	 * @return array{key:string,label:string}
	 */
	private function consume_issued_key( $flash_id ) {
		$flash_id = sanitize_key( $flash_id );
		$empty    = array(
			'key'   => '',
			'label' => '',
		);
		if ( '' === $flash_id ) {
			return $empty;
		}
		$key_name = 'picot_mcp_flash_' . $flash_id;
		$data     = get_transient( $key_name );
		delete_transient( $key_name );

		if ( is_array( $data ) && ! empty( $data['key'] ) && is_string( $data['key'] ) ) {
			return array(
				'key'   => $data['key'],
				'label' => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '',
			);
		}
		// Legacy flash: plaintext string only.
		if ( is_string( $data ) && '' !== $data ) {
			return array(
				'key'   => $data,
				'label' => '',
			);
		}
		return $empty;
	}

	/**
	 * Persist settings from POST.
	 *
	 * Nonce is verified in handle_actions() before this runs.
	 *
	 * @return void
	 */
	private function save_settings_from_post() {
		$settings = Picot_Mcp_Settings::instance()->all();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified via check_admin_referer() in handle_actions().
		if ( ! empty( $_POST['picot_mcp_settings_section'] ) ) {
			$settings['enabled']         = ! empty( $_POST['picot_mcp_enabled'] );
			$settings['route_namespace'] = isset( $_POST['picot_mcp_route_namespace'] )
				? sanitize_text_field( wp_unslash( $_POST['picot_mcp_route_namespace'] ) )
				: $settings['route_namespace'];
			$settings['route']           = isset( $_POST['picot_mcp_route'] )
				? sanitize_text_field( wp_unslash( $_POST['picot_mcp_route'] ) )
				: $settings['route'];

			$features = array( 'content', 'taxonomy', 'media', 'settings', 'plugins', 'themes', 'users' );
			foreach ( $features as $feature ) {
				$settings['permissions'][ $feature ] = ! empty( $_POST['picot_mcp_feature'][ $feature ] );
			}

			$ops = array( 'read', 'write', 'critical', 'zip_install' );
			foreach ( $ops as $op ) {
				$settings['operations'][ $op ] = ! empty( $_POST['picot_mcp_operation'][ $op ] );
			}

			$allowed = array();
			foreach ( $this->post_array( 'picot_mcp_allowed_users' ) as $uid ) {
				$uid = absint( $uid );
				if ( $uid > 0 ) {
					$allowed[] = $uid;
				}
			}
			$settings['allowed_user_ids'] = $allowed;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Picot_Mcp_Settings::instance()->save( $settings );
	}

	/**
	 * Read an array field from POST (already unslashed).
	 *
	 * @param string $key POST key.
	 * @return array
	 */
	private function post_array( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verifies nonce; values sanitized by callers.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslash only; sanitized by callers.
		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Sanitize a checkbox-style associative map from POST.
	 *
	 * @param mixed $raw Raw input (expected array).
	 * @return array<string, string>
	 */
	private function sanitize_checkbox_map( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ sanitize_key( (string) $key ) ] = $this->sanitize_checkbox_map( $value );
				continue;
			}
			$out[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
		return $out;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! Picot_Mcp_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'picot-mcp' ) );
		}

		$settings = Picot_Mcp_Settings::instance()->all();
		$tokens   = Picot_Mcp_Settings::instance()->get_tokens();

		// Display-only PRG query args (not form processing).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice   = isset( $_GET['picot_mcp_notice'] ) ? sanitize_key( wp_unslash( $_GET['picot_mcp_notice'] ) ) : '';
		$flash_id = isset( $_GET['picot_mcp_flash'] ) ? sanitize_key( wp_unslash( $_GET['picot_mcp_flash'] ) ) : '';
		$keys_tab = isset( $_GET['picot_mcp_tab'] ) ? sanitize_key( wp_unslash( $_GET['picot_mcp_tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$issued     = ( 'key_issued' === $notice ) ? $this->consume_issued_key( $flash_id ) : array( 'key' => '', 'label' => '' );
		$issued_key = isset( $issued['key'] ) ? $issued['key'] : '';
		$issued_label = isset( $issued['label'] ) ? $issued['label'] : '';
		if ( ! in_array( $keys_tab, array( 'list', 'create', 'settings', 'logs' ), true ) ) {
			if ( 'saved' === $notice ) {
				$keys_tab = 'settings';
			} elseif ( 'logs_cleared' === $notice ) {
				$keys_tab = 'logs';
			} elseif ( 'key_error' === $notice && ! $issued_key ) {
				$keys_tab = 'create';
			} else {
				$keys_tab = 'list';
			}
		}
		$eligible   = Picot_Mcp_Api_Key::eligible_users();
		$all_users  = get_users(
			array(
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'number'     => 200,
				'capability' => array( 'edit_posts' ),
			)
		);

		$features = array(
			'content'  => __( 'Posts & pages', 'picot-mcp' ),
			'taxonomy' => __( 'Categories & tags', 'picot-mcp' ),
			'media'    => __( 'Media', 'picot-mcp' ),
			'settings' => __( 'Site settings', 'picot-mcp' ),
			'plugins'  => __( 'Plugins', 'picot-mcp' ),
			'themes'   => __( 'Themes', 'picot-mcp' ),
			'users'    => __( 'Users', 'picot-mcp' ),
		);

		$operations = array(
			'read'        => __( 'Read', 'picot-mcp' ),
			'write'       => __( 'Create & edit', 'picot-mcp' ),
			'critical'    => __( 'Delete & critical', 'picot-mcp' ),
			'zip_install' => __( 'Plugin/theme ZIP transfer', 'picot-mcp' ),
		);

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'picot-mcp' ) . '</p></div>';
		} elseif ( 'key_updated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API key settings updated.', 'picot-mcp' ) . '</p></div>';
		} elseif ( 'key_revoked' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API key deleted.', 'picot-mcp' ) . '</p></div>';
		} elseif ( 'logs_cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Usage log cleared.', 'picot-mcp' ) . '</p></div>';
		} elseif ( 'key_error' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'API key operation failed. Make sure the user is on the allow list.', 'picot-mcp' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Picot MCP', 'picot-mcp' ); ?></h1>

			<?php if ( $issued_key ) : ?>
				<div class="notice notice-success">
					<p><strong><?php echo esc_html__( 'API key issued.', 'picot-mcp' ); ?></strong></p>
					<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
						<input type="text" readonly class="large-text code" id="picot-mcp-issued-key" value="<?php echo esc_attr( $issued_key ); ?>" style="max-width:36em;font-family:Consolas,Monaco,monospace;" onclick="this.select();" />
						<button type="button" class="button button-primary picot-mcp-copy" data-copy-target="picot-mcp-issued-key" data-label="<?php echo esc_attr__( 'Copy', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy', 'picot-mcp' ); ?></button>
						<button type="button" class="button picot-mcp-copy" data-copy-bundle="1" data-copy-target="picot-mcp-issued-key" data-key-label="<?php echo esc_attr( $issued_label ); ?>" data-label="<?php echo esc_attr__( 'Copy all', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy all', 'picot-mcp' ); ?></button>
					</p>
					<p class="description"><?php echo esc_html__( 'You can copy this key again later from the list while it stays masked.', 'picot-mcp' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'picot_mcp_admin', 'picot_mcp_nonce' ); ?>

				<?php
				$base_tab_url  = admin_url( 'options-general.php?page=picot-mcp' );
				$list_url      = add_query_arg( 'picot_mcp_tab', 'list', $base_tab_url );
				$create_url    = add_query_arg( 'picot_mcp_tab', 'create', $base_tab_url );
				$settings_url  = add_query_arg( 'picot_mcp_tab', 'settings', $base_tab_url );
				$logs_url      = add_query_arg( 'picot_mcp_tab', 'logs', $base_tab_url );
				?>
				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr__( 'Picot MCP', 'picot-mcp' ); ?>">
					<a href="<?php echo esc_url( $list_url ); ?>" class="nav-tab<?php echo 'list' === $keys_tab ? ' nav-tab-active' : ''; ?>">
						<?php
						echo esc_html__( 'API keys', 'picot-mcp' );
						if ( ! empty( $tokens ) ) {
							echo ' (' . esc_html( (string) count( $tokens ) ) . ')';
						}
						?>
					</a>
					<a href="<?php echo esc_url( $create_url ); ?>" class="nav-tab<?php echo 'create' === $keys_tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html__( 'Create API key', 'picot-mcp' ); ?>
					</a>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab<?php echo 'settings' === $keys_tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html__( 'Settings', 'picot-mcp' ); ?>
					</a>
					<a href="<?php echo esc_url( $logs_url ); ?>" class="nav-tab<?php echo 'logs' === $keys_tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html__( 'Logs', 'picot-mcp' ); ?>
					</a>
				</nav>

				<?php if ( 'settings' === $keys_tab ) : ?>
					<div class="picot-mcp-tab-panel" id="picot-mcp-tab-settings" style="padding-top:1em;">
						<input type="hidden" name="picot_mcp_settings_section" value="1" />
						<input type="hidden" name="picot_mcp_tab" value="settings" />

						<h2><?php echo esc_html__( 'MCP connection', 'picot-mcp' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__( 'MCP server', 'picot-mcp' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="picot_mcp_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
										<?php echo esc_html__( 'Enable MCP', 'picot-mcp' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Endpoint path', 'picot-mcp' ); ?></th>
								<td>
									<code><?php echo esc_html( home_url( '/wp-json/' ) ); ?></code>
									<input type="text" name="picot_mcp_route_namespace" value="<?php echo esc_attr( $settings['route_namespace'] ); ?>" class="regular-text" style="width:8em" pattern="[a-z0-9\-_]+" required />
									<code>/</code>
									<input type="text" name="picot_mcp_route" value="<?php echo esc_attr( $settings['route'] ); ?>" class="regular-text" style="width:10em" pattern="[a-z0-9\-_]+" required />
									<p class="description">
										<?php echo esc_html__( 'Lowercase letters, numbers, hyphens, and underscores only. Changing this disconnects existing clients. External domains are not allowed (site REST API path only).', 'picot-mcp' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'MCP URL', 'picot-mcp' ); ?></th>
								<td>
									<input type="text" readonly class="large-text code" id="picot-mcp-url" value="<?php echo esc_attr( Picot_Mcp_Server::endpoint_url() ); ?>" style="max-width:36em;" onclick="this.select();" />
									<button type="button" class="button button-small picot-mcp-copy" data-copy-target="picot-mcp-url" data-label="<?php echo esc_attr__( 'Copy', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy', 'picot-mcp' ); ?></button>
								</td>
							</tr>
						</table>

						<hr />

						<h2><?php echo esc_html__( 'Site-wide features & operations (ceiling)', 'picot-mcp' ); ?></h2>
						<p class="description"><?php echo esc_html__( 'Turning an item off disables it for every API key. Site settings override per-key settings. ZIP transfer (export/install) is separate from Delete & critical.', 'picot-mcp' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__( 'Features', 'picot-mcp' ); ?></th>
								<td>
									<fieldset>
										<?php foreach ( $features as $key => $label ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="picot_mcp_feature[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['permissions'][ $key ] ) ); ?> />
												<?php echo esc_html( $label ); ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Operations', 'picot-mcp' ); ?></th>
								<td>
									<fieldset>
										<?php foreach ( $operations as $key => $label ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="picot_mcp_operation[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['operations'][ $key ] ) ); ?> />
												<?php echo esc_html( $label ); ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
						</table>

						<hr />

						<h2><?php echo esc_html__( 'Accounts allowed for keys', 'picot-mcp' ); ?></h2>
						<p class="description"><?php echo esc_html__( 'If none are selected, all users who can edit posts are eligible. Checked users become the only candidates.', 'picot-mcp' ); ?></p>
						<div style="max-height:180px;overflow:auto;border:1px solid #c3c4c7;padding:8px;max-width:480px;margin-bottom:1em;">
							<?php foreach ( $all_users as $user ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="picot_mcp_allowed_users[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $settings['allowed_user_ids'], true ) ); ?> />
									<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
								</label>
							<?php endforeach; ?>
						</div>

						<p>
							<button type="submit" class="button button-primary" name="picot_mcp_action" value="save_settings"><?php echo esc_html__( 'Save settings', 'picot-mcp' ); ?></button>
						</p>
					</div>

				<?php elseif ( 'create' === $keys_tab ) : ?>
					<div class="picot-mcp-tab-panel" id="picot-mcp-tab-create" style="padding-top:1em;">
						<input type="hidden" name="picot_mcp_tab" value="create" />
						<p class="description"><?php echo esc_html__( 'Choose features and operations for this key. Items disabled on the Settings tab cannot be used (site ceiling).', 'picot-mcp' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__( 'Label', 'picot-mcp' ); ?></th>
								<td>
									<input type="text" name="picot_mcp_key_label" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. Cursor', 'picot-mcp' ); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Acting user', 'picot-mcp' ); ?></th>
								<td>
									<select name="picot_mcp_key_user">
										<option value=""><?php echo esc_html__( 'Select a user', 'picot-mcp' ); ?></option>
										<?php foreach ( $eligible as $user ) : ?>
											<option value="<?php echo esc_attr( (string) $user->ID ); ?>">
												<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Features', 'picot-mcp' ); ?></th>
								<td>
									<fieldset>
										<?php foreach ( $features as $fkey => $flabel ) : ?>
											<?php $site_on = ! empty( $settings['permissions'][ $fkey ] ); ?>
											<label style="display:inline-block;margin:0 12px 4px 0;<?php echo $site_on ? '' : 'opacity:0.55;'; ?>">
												<input type="checkbox" name="picot_mcp_new_feature[<?php echo esc_attr( $fkey ); ?>]" value="1" <?php checked( $site_on ); ?> <?php disabled( ! $site_on ); ?> />
												<?php echo esc_html( $flabel ); ?>
												<?php if ( ! $site_on ) : ?>
													<span class="description"><?php echo esc_html__( '(disabled in site settings)', 'picot-mcp' ); ?></span>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Operations', 'picot-mcp' ); ?></th>
								<td>
									<fieldset>
										<?php foreach ( $operations as $okey => $olabel ) : ?>
											<?php $site_on = ! empty( $settings['operations'][ $okey ] ); ?>
											<label style="display:inline-block;margin:0 12px 4px 0;<?php echo $site_on ? '' : 'opacity:0.55;'; ?>">
												<input type="checkbox" name="picot_mcp_new_operation[<?php echo esc_attr( $okey ); ?>]" value="1" <?php checked( $site_on ); ?> <?php disabled( ! $site_on ); ?> />
												<?php echo esc_html( $olabel ); ?>
												<?php if ( ! $site_on ) : ?>
													<span class="description"><?php echo esc_html__( '(disabled in site settings)', 'picot-mcp' ); ?></span>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
						</table>
						<p>
							<button type="submit" class="button button-primary" name="picot_mcp_action" value="issue_key"><?php echo esc_html__( 'Issue API key', 'picot-mcp' ); ?></button>
						</p>
					</div>

				<?php elseif ( 'logs' === $keys_tab ) : ?>
					<?php
					$log_entries    = Picot_Mcp_Usage_Log::get_entries();
					$feature_labels = Picot_Mcp_Usage_Log::feature_labels();
					?>
					<div class="picot-mcp-tab-panel" id="picot-mcp-tab-logs" style="padding-top:1em;">
						<input type="hidden" name="picot_mcp_tab" value="logs" />
						<p class="description">
							<?php
							printf(
								/* translators: %d: max number of log entries kept */
								esc_html__( 'Recent MCP tool usage (up to %d entries). Shows who ran which operation; change details are not stored.', 'picot-mcp' ),
								(int) Picot_Mcp_Usage_Log::MAX
							);
							?>
						</p>
						<?php if ( ! empty( $log_entries ) ) : ?>
							<p>
								<button type="submit" class="button" name="picot_mcp_action" value="clear_logs" onclick="return confirm('<?php echo esc_js( __( 'Clear all usage log entries?', 'picot-mcp' ) ); ?>');">
									<?php echo esc_html__( 'Clear log', 'picot-mcp' ); ?>
								</button>
							</p>
							<table class="widefat striped" style="max-width:960px;">
								<thead>
									<tr>
										<th><?php echo esc_html__( 'Time', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'User', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'API key', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Feature', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Action', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Result', 'picot-mcp' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $log_entries as $entry ) : ?>
										<?php
										$when = ! empty( $entry['time'] ) ? (int) $entry['time'] : 0;
										$feat = isset( $entry['feature'] ) ? (string) $entry['feature'] : '';
										$feat_label = isset( $feature_labels[ $feat ] ) ? $feature_labels[ $feat ] : $feat;
										$key_label  = isset( $entry['key'] ) && '' !== (string) $entry['key'] ? (string) $entry['key'] : '—';
										$ok         = ! isset( $entry['success'] ) || ! empty( $entry['success'] );
										?>
										<tr>
											<td><?php echo esc_html( $when ? wp_date( 'Y-m-d H:i:s', $when ) : '—' ); ?></td>
											<td><?php echo esc_html( isset( $entry['user'] ) ? (string) $entry['user'] : '—' ); ?></td>
											<td><?php echo esc_html( $key_label ); ?></td>
											<td><?php echo esc_html( $feat_label ); ?></td>
											<td><code><?php echo esc_html( isset( $entry['action'] ) ? (string) $entry['action'] : '—' ); ?></code></td>
											<td><?php echo esc_html( $ok ? __( 'OK', 'picot-mcp' ) : __( 'Failed', 'picot-mcp' ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php echo esc_html__( 'No usage has been recorded yet.', 'picot-mcp' ); ?></p>
						<?php endif; ?>
					</div>

				<?php else : ?>
					<div class="picot-mcp-tab-panel" id="picot-mcp-tab-list" style="padding-top:1em;">
						<p class="description"><?php echo esc_html__( 'Each key has an acting user and its own features/operations. Effective access is site settings ∩ key settings ∩ user capabilities.', 'picot-mcp' ); ?></p>
						<?php if ( ! empty( $tokens ) ) : ?>
							<table class="widefat striped picot-mcp-keys-table" style="max-width:960px;">
								<thead>
									<tr>
										<th><?php echo esc_html__( 'Label', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Key', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Acting user', 'picot-mcp' ); ?></th>
										<th><?php echo esc_html__( 'Created / last used', 'picot-mcp' ); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tokens as $token ) : ?>
										<?php
										$user        = get_user_by( 'id', (int) $token['user_id'] );
										$user_label  = $user ? $user->user_login : __( '(deleted)', 'picot-mcp' );
										$token_perms = isset( $token['permissions'] ) && is_array( $token['permissions'] )
											? $token['permissions']
											: array_fill_keys( array_keys( $features ), true );
										$token_ops   = isset( $token['operations'] ) && is_array( $token['operations'] )
											? $token['operations']
											: array_fill_keys( array_keys( $operations ), true );
										$has_secret = Picot_Mcp_Api_Key::has_copyable_secret( $token );
										$modal_id   = 'picot-mcp-modal-' . sanitize_html_class( $token['token_id'] );
										$key_label  = isset( $token['label'] ) ? (string) $token['label'] : '';
										$created    = wp_date( get_option( 'date_format' ), (int) $token['created_at'] );
										$last_used  = ! empty( $token['last_used_at'] )
											? wp_date( get_option( 'date_format' ), (int) $token['last_used_at'] )
											: '—';
										?>
										<tr>
											<td>
												<strong><?php echo esc_html( ! empty( $token['label'] ) ? $token['label'] : __( '(no label)', 'picot-mcp' ) ); ?></strong>
											</td>
											<td>
												<code class="picot-mcp-key-mask"><?php echo esc_html( Picot_Mcp_Api_Key::masked_display( $token ) ); ?></code>
												<?php if ( $has_secret ) : ?>
													<button type="button" class="button button-small picot-mcp-copy" data-copy-token-id="<?php echo esc_attr( $token['token_id'] ); ?>" data-label="<?php echo esc_attr__( 'Copy', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy', 'picot-mcp' ); ?></button>
													<button type="button" class="button button-small picot-mcp-copy" data-copy-bundle="1" data-copy-token-id="<?php echo esc_attr( $token['token_id'] ); ?>" data-key-label="<?php echo esc_attr( $key_label ); ?>" data-label="<?php echo esc_attr__( 'Copy all', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy all', 'picot-mcp' ); ?></button>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $user_label ); ?></td>
											<td>
												<span class="description"><?php echo esc_html( $created . ' / ' . $last_used ); ?></span>
											</td>
											<td class="picot-mcp-row-actions">
												<button type="button" class="button button-small picot-mcp-open-modal" data-modal="<?php echo esc_attr( $modal_id ); ?>">
													<?php echo esc_html__( 'Edit', 'picot-mcp' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<?php foreach ( $tokens as $token ) : ?>
								<?php
								$user        = get_user_by( 'id', (int) $token['user_id'] );
								$token_perms = isset( $token['permissions'] ) && is_array( $token['permissions'] )
									? $token['permissions']
									: array_fill_keys( array_keys( $features ), true );
								$token_ops   = isset( $token['operations'] ) && is_array( $token['operations'] )
									? $token['operations']
									: array_fill_keys( array_keys( $operations ), true );
								$has_secret = Picot_Mcp_Api_Key::has_copyable_secret( $token );
								$modal_id   = 'picot-mcp-modal-' . sanitize_html_class( $token['token_id'] );
								$key_label  = isset( $token['label'] ) ? (string) $token['label'] : '';
								?>
								<div class="picot-mcp-modal" id="<?php echo esc_attr( $modal_id ); ?>" hidden>
									<div class="picot-mcp-modal__backdrop" data-close-modal></div>
									<div class="picot-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title">
										<div class="picot-mcp-modal__header">
											<h2 id="<?php echo esc_attr( $modal_id ); ?>-title"><?php echo esc_html__( 'Edit API key', 'picot-mcp' ); ?></h2>
											<button type="button" class="picot-mcp-modal__close" data-close-modal aria-label="<?php echo esc_attr__( 'Close', 'picot-mcp' ); ?>">&times;</button>
										</div>
										<div class="picot-mcp-modal__body">
											<p>
												<code><?php echo esc_html( Picot_Mcp_Api_Key::masked_display( $token ) ); ?></code>
												<?php if ( $has_secret ) : ?>
													<button type="button" class="button button-small picot-mcp-copy" data-copy-token-id="<?php echo esc_attr( $token['token_id'] ); ?>" data-label="<?php echo esc_attr__( 'Copy key', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy key', 'picot-mcp' ); ?></button>
													<button type="button" class="button button-small picot-mcp-copy" data-copy-bundle="1" data-copy-token-id="<?php echo esc_attr( $token['token_id'] ); ?>" data-key-label="<?php echo esc_attr( $key_label ); ?>" data-label="<?php echo esc_attr__( 'Copy all', 'picot-mcp' ); ?>" data-copied-label="<?php echo esc_attr__( 'Copied', 'picot-mcp' ); ?>"><?php echo esc_html__( 'Copy all', 'picot-mcp' ); ?></button>
												<?php else : ?>
													<span class="description"><?php echo esc_html__( 'No copyable data. Please issue a new key.', 'picot-mcp' ); ?></span>
												<?php endif; ?>
											</p>
											<table class="form-table" role="presentation">
												<tr>
													<th scope="row"><?php echo esc_html__( 'Label', 'picot-mcp' ); ?></th>
													<td>
														<input type="text" name="picot_mcp_edit_label[<?php echo esc_attr( $token['token_id'] ); ?>]" value="<?php echo esc_attr( isset( $token['label'] ) ? $token['label'] : '' ); ?>" class="regular-text" />
													</td>
												</tr>
												<tr>
													<th scope="row"><?php echo esc_html__( 'Acting user', 'picot-mcp' ); ?></th>
													<td>
														<select name="picot_mcp_edit_user[<?php echo esc_attr( $token['token_id'] ); ?>]">
															<?php foreach ( $eligible as $elig ) : ?>
																<option value="<?php echo esc_attr( (string) $elig->ID ); ?>" <?php selected( (int) $token['user_id'], (int) $elig->ID ); ?>>
																	<?php echo esc_html( $elig->display_name . ' (' . $elig->user_login . ')' ); ?>
																</option>
															<?php endforeach; ?>
															<?php if ( $user && ! in_array( (int) $user->ID, wp_list_pluck( $eligible, 'ID' ), true ) ) : ?>
																<option value="<?php echo esc_attr( (string) $user->ID ); ?>" selected><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
															<?php endif; ?>
														</select>
													</td>
												</tr>
												<tr>
													<th scope="row"><?php echo esc_html__( 'Features', 'picot-mcp' ); ?></th>
													<td>
														<fieldset>
															<?php foreach ( $features as $fkey => $flabel ) : ?>
																<?php $site_on = ! empty( $settings['permissions'][ $fkey ] ); ?>
																<label style="display:inline-block;margin:0 12px 4px 0;<?php echo $site_on ? '' : 'opacity:0.55;'; ?>">
																	<input type="checkbox" name="picot_mcp_edit_feature[<?php echo esc_attr( $token['token_id'] ); ?>][<?php echo esc_attr( $fkey ); ?>]" value="1" <?php checked( $site_on && ! empty( $token_perms[ $fkey ] ) ); ?> <?php disabled( ! $site_on ); ?> />
																	<?php echo esc_html( $flabel ); ?>
																</label>
															<?php endforeach; ?>
														</fieldset>
													</td>
												</tr>
												<tr>
													<th scope="row"><?php echo esc_html__( 'Operations', 'picot-mcp' ); ?></th>
													<td>
														<fieldset>
															<?php foreach ( $operations as $okey => $olabel ) : ?>
																<?php $site_on = ! empty( $settings['operations'][ $okey ] ); ?>
																<label style="display:inline-block;margin:0 12px 4px 0;<?php echo $site_on ? '' : 'opacity:0.55;'; ?>">
																	<input type="checkbox" name="picot_mcp_edit_operation[<?php echo esc_attr( $token['token_id'] ); ?>][<?php echo esc_attr( $okey ); ?>]" value="1" <?php checked( $site_on && ! empty( $token_ops[ $okey ] ) ); ?> <?php disabled( ! $site_on ); ?> />
																	<?php echo esc_html( $olabel ); ?>
																</label>
															<?php endforeach; ?>
														</fieldset>
													</td>
												</tr>
											</table>
										</div>
										<div class="picot-mcp-modal__footer">
											<button type="submit" class="button button-primary picot-mcp-update-key" name="picot_mcp_action" value="update_key" data-token-id="<?php echo esc_attr( $token['token_id'] ); ?>">
												<?php echo esc_html__( 'Save', 'picot-mcp' ); ?>
											</button>
											<button type="button" class="button" data-close-modal><?php echo esc_html__( 'Cancel', 'picot-mcp' ); ?></button>
											<button type="submit" class="button button-link-delete picot-mcp-revoke" name="picot_mcp_action" value="revoke_key" data-token-id="<?php echo esc_attr( $token['token_id'] ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this API key? This cannot be undone.', 'picot-mcp' ) ); ?>');">
												<?php echo esc_html__( 'Delete', 'picot-mcp' ); ?>
											</button>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<p><?php echo esc_html__( 'No API keys have been issued yet.', 'picot-mcp' ); ?>
								<a href="<?php echo esc_url( $create_url ); ?>"><?php echo esc_html__( 'Create an API key', 'picot-mcp' ); ?></a>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}
