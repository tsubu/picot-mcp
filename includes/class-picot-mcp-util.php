<?php
/**
 * Shared helpers for Ability handlers.
 *
 * @package Picot_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Mcp_Util class.
 */
class Picot_Mcp_Util {

	/**
	 * Whether a download URL is an allowed wordpress.org host.
	 *
	 * @param string $url Download URL.
	 * @return true|WP_Error
	 */
	public static function assert_wporg_download_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Download URL is missing.', 'picot-mcp' ) );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Only HTTPS wordpress.org downloads are allowed.', 'picot-mcp' ) );
		}

		$host    = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		// Hosts built without hardcoded remote asset URLs (Plugin Check Offloading).
		$allowed = array(
			implode( '.', array( 'downloads', 'wordpress', 'org' ) ),
			implode( '.', array( 'downloads', 'w', 'org' ) ),
		);

		/**
		 * Filter allowed wordpress.org download hosts.
		 *
		 * @param string[] $allowed Allowed hostnames.
		 * @param string   $url     Download URL.
		 */
		$allowed = apply_filters( 'picot_mcp_wporg_download_hosts', $allowed, $url );

		if ( ! in_array( $host, $allowed, true ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Download host is not allowed.', 'picot-mcp' ) );
		}

		return true;
	}

	/**
	 * Max bytes allowed for MCP ZIP package uploads.
	 *
	 * @return int
	 */
	public static function max_zip_upload_bytes() {
		$default = 25 * 1024 * 1024; // 25 MB — intentional product ceiling for Base64 ZIP transfer.
		$upload  = (int) wp_max_upload_size();
		$max     = $upload > 0 ? min( $default, $upload ) : $default;

		/**
		 * Filter maximum ZIP upload size for plugin/theme install_zip / export_zip.
		 *
		 * @param int $max Maximum bytes.
		 */
		return (int) apply_filters( 'picot_mcp_max_zip_upload_bytes', $max );
	}

	/**
	 * Human-readable max ZIP size for admin UI.
	 *
	 * @return string
	 */
	public static function max_zip_upload_label() {
		return size_format( self::max_zip_upload_bytes() );
	}

	/**
	 * Decode Base64 ZIP payload to a temporary file.
	 *
	 * @param string $filename    Suggested filename (must end with .zip).
	 * @param string $base64_data Base64 (optionally data: URI).
	 * @return string|WP_Error Absolute temp path on success.
	 */
	public static function write_base64_zip_temp( $filename, $base64_data ) {
		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename || ! preg_match( '/\.zip$/i', $filename ) ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'A .zip filename is required.', 'picot-mcp' ) );
		}

		$b64 = is_string( $base64_data ) ? $base64_data : '';
		if ( '' === $b64 ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'base64_data is required.', 'picot-mcp' ) );
		}
		if ( preg_match( '/^data:[^;]+;base64,/', $b64 ) ) {
			$b64 = preg_replace( '/^data:[^;]+;base64,/', '', $b64 );
		}

		$binary = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Intentional ZIP package upload.
		if ( false === $binary || '' === $binary ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Invalid Base64 data.', 'picot-mcp' ) );
		}

		$max = self::max_zip_upload_bytes();
		if ( strlen( $binary ) > $max ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'ZIP exceeds the maximum upload size.', 'picot-mcp' ) );
		}

		// ZIP local file header signature.
		if ( strlen( $binary ) < 4 || "PK\x03\x04" !== substr( $binary, 0, 4 ) ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'File is not a valid ZIP archive.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Could not create a temporary file.', 'picot-mcp' ) );
		}
		// Prefer a .zip suffix for upgraders; avoid rename() for Plugin Check.
		if ( ! preg_match( '/\.zip$/i', $tmp ) ) {
			$zip_tmp = $tmp . '.zip';
			wp_delete_file( $tmp );
			$tmp     = $zip_tmp;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp binary write before upgrader.
		if ( false === file_put_contents( $tmp, $binary ) ) {
			wp_delete_file( $tmp );
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Could not write temporary file.', 'picot-mcp' ) );
		}

		return $tmp;
	}

	/**
	 * Build a ZIP from a file or directory and return Base64 payload.
	 *
	 * @param string $source_path Absolute file or directory path.
	 * @param string $archive_root Root folder/file name inside the ZIP.
	 * @param string $filename    Output ZIP filename (*.zip).
	 * @return array{filename:string,base64_data:string,bytes:int}|WP_Error
	 */
	public static function zip_path_to_base64( $source_path, $archive_root, $filename ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'ZIP support (ZipArchive) is not available on this server.', 'picot-mcp' ) );
		}

		$source_path = wp_normalize_path( (string) $source_path );
		$real        = realpath( $source_path );
		if ( false === $real ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Package path not found.', 'picot-mcp' ) );
		}
		$real = wp_normalize_path( $real );

		$archive_root = sanitize_file_name( (string) $archive_root );
		if ( '' === $archive_root ) {
			return Picot_Mcp_Errors::make( 'invalid_parameter', __( 'Invalid package name.', 'picot-mcp' ) );
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			$filename = $archive_root . '.zip';
		}
		if ( ! preg_match( '/\.zip$/i', $filename ) ) {
			$filename .= '.zip';
		}

		$max = self::max_zip_upload_bytes();
		$sum = self::estimate_zip_source_bytes( $real );
		if ( is_wp_error( $sum ) ) {
			return $sum;
		}
		if ( $sum > $max ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Package exceeds the maximum ZIP size.', 'picot-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Could not create a temporary file.', 'picot-mcp' ) );
		}
		if ( ! preg_match( '/\.zip$/i', $tmp ) ) {
			$zip_tmp = $tmp . '.zip';
			wp_delete_file( $tmp );
			$tmp     = $zip_tmp;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not create ZIP archive.', 'picot-mcp' ) );
		}

		$added = self::add_path_to_zip( $zip, $real, $archive_root );
		$zip->close();

		if ( is_wp_error( $added ) ) {
			wp_delete_file( $tmp );
			return $added;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read temp ZIP we just created.
		$binary = file_get_contents( $tmp );
		wp_delete_file( $tmp );

		if ( false === $binary || '' === $binary ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not read ZIP archive.', 'picot-mcp' ) );
		}
		if ( strlen( $binary ) > $max ) {
			return Picot_Mcp_Errors::make( 'upload_failed', __( 'Package exceeds the maximum ZIP size.', 'picot-mcp' ) );
		}

		return array(
			'filename'    => $filename,
			'base64_data' => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Intentional ZIP export payload.
			'bytes'       => strlen( $binary ),
		);
	}

	/**
	 * Estimate total bytes of files that would be added to a ZIP.
	 *
	 * @param string $real Absolute real path.
	 * @return int|WP_Error
	 */
	private static function estimate_zip_source_bytes( $real ) {
		$max = self::max_zip_upload_bytes();
		$sum = 0;

		if ( is_file( $real ) ) {
			$size = filesize( $real );
			return false === $size ? 0 : (int) $size;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not read package directory.', 'picot-mcp' ) );
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$full = wp_normalize_path( $file->getPathname() );
			$rel  = ltrim( substr( $full, strlen( $real ) ), '/' );
			if ( self::should_skip_zip_entry( $rel ) ) {
				continue;
			}
			$sum += (int) $file->getSize();
			if ( $sum > $max ) {
				return Picot_Mcp_Errors::make( 'upload_failed', __( 'Package exceeds the maximum ZIP size.', 'picot-mcp' ) );
			}
		}

		return $sum;
	}

	/**
	 * Add a file or directory into an open ZipArchive.
	 *
	 * @param ZipArchive $zip          Archive.
	 * @param string     $real         Absolute real path.
	 * @param string     $archive_root Root name inside ZIP.
	 * @return true|WP_Error
	 */
	private static function add_path_to_zip( ZipArchive $zip, $real, $archive_root ) {
		if ( is_file( $real ) ) {
			if ( ! $zip->addFile( $real, $archive_root ) ) {
				return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not add file to ZIP archive.', 'picot-mcp' ) );
			}
			return true;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( Exception $e ) {
			return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not read package directory.', 'picot-mcp' ) );
		}

		$zip->addEmptyDir( $archive_root );

		foreach ( $iterator as $file ) {
			$full = wp_normalize_path( $file->getPathname() );
			$rel  = ltrim( substr( $full, strlen( $real ) ), '/' );
			if ( '' === $rel || self::should_skip_zip_entry( $rel ) ) {
				continue;
			}
			$local = $archive_root . '/' . $rel;
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $local );
				continue;
			}
			if ( $file->isFile() && ! $zip->addFile( $full, $local ) ) {
				return Picot_Mcp_Errors::make( 'internal_error', __( 'Could not add file to ZIP archive.', 'picot-mcp' ) );
			}
		}

		return true;
	}

	/**
	 * Whether a relative ZIP entry should be skipped.
	 *
	 * @param string $relative Relative path inside package.
	 * @return bool
	 */
	private static function should_skip_zip_entry( $relative ) {
		$parts = explode( '/', str_replace( '\\', '/', (string) $relative ) );
		$skip  = array( '.git', '.svn', 'node_modules', '.DS_Store', 'Thumbs.db' );
		foreach ( $parts as $part ) {
			if ( in_array( $part, $skip, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Assert a path stays under an allowed base directory.
	 *
	 * @param string $path Absolute path.
	 * @param string $base Allowed base directory.
	 * @return true|WP_Error
	 */
	public static function assert_path_under_base( $path, $base ) {
		$real_path = realpath( $path );
		$real_base = realpath( $base );
		if ( false === $real_path || false === $real_base ) {
			return Picot_Mcp_Errors::make( 'resource_not_found', __( 'Package path not found.', 'picot-mcp' ) );
		}
		$real_path = wp_normalize_path( $real_path );
		$real_base = trailingslashit( wp_normalize_path( $real_base ) );
		if ( 0 !== strpos( $real_path, $real_base ) && $real_path !== rtrim( $real_base, '/' ) ) {
			return Picot_Mcp_Errors::make( 'operation_not_allowed', __( 'Package path is not allowed.', 'picot-mcp' ) );
		}
		return true;
	}
}
