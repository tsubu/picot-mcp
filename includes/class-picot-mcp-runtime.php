<?php
/**
 * Official MCP Adapter runtime detection / status.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Runtime class.
 */
class Picot_Mcp_Runtime {

	/**
	 * Bundled Adapter package version (Composer).
	 *
	 * @return string
	 */
	public static function bundled_adapter_version() {
		// Prefer this plugin's own vendor/composer/installed.php (reliable with Jetpack Autoloader).
		$installed = PICOT_MCP_PATH . 'vendor/composer/installed.php';
		if ( is_readable( $installed ) ) {
			$data = include $installed;
			if ( is_array( $data ) && ! empty( $data['versions']['wordpress/mcp-adapter']['pretty_version'] ) ) {
				return (string) $data['versions']['wordpress/mcp-adapter']['pretty_version'];
			}
			if ( is_array( $data ) && ! empty( $data['versions']['wordpress/mcp-adapter']['version'] ) ) {
				return (string) $data['versions']['wordpress/mcp-adapter']['version'];
			}
		}

		if ( class_exists( '\Composer\InstalledVersions' ) ) {
			try {
				if ( \Composer\InstalledVersions::isInstalled( 'wordpress/mcp-adapter' ) ) {
					$v = \Composer\InstalledVersions::getPrettyVersion( 'wordpress/mcp-adapter' );
					if ( is_string( $v ) && '' !== $v ) {
						return $v;
					}
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through.
			}
		}

		$path = PICOT_MCP_PATH . 'vendor/wordpress/mcp-adapter/composer.json';
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $json ) && ! empty( $json['version'] ) ) {
				return (string) $json['version'];
			}
		}

		$plugin_file = PICOT_MCP_PATH . 'vendor/wordpress/mcp-adapter/mcp-adapter.php';
		if ( is_readable( $plugin_file ) && function_exists( 'get_file_data' ) ) {
			$meta = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
			if ( ! empty( $meta['Version'] ) ) {
				return (string) $meta['Version'];
			}
		}

		return __( 'unknown', 'picot-mcp' );
	}

	/**
	 * Whether a standalone mcp-adapter plugin appears active.
	 *
	 * @return bool
	 */
	public static function is_standalone_adapter_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$candidates = array(
			'mcp-adapter/mcp-adapter.php',
			'wordpress-mcp-adapter/mcp-adapter.php',
		);

		foreach ( $candidates as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		/**
		 * Filter whether a standalone MCP Adapter plugin is considered active.
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'picot_mcp_standalone_adapter_active', false );
	}

	/**
	 * Observability handler class for create_server().
	 *
	 * @return string Class name.
	 */
	public static function observability_handler_class() {
		$mode = Picot_Mcp_Settings::instance()->observability();
		if ( 'error_log' === $mode && class_exists( '\WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler' ) ) {
			return \WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler::class;
		}
		return \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class;
	}
}
