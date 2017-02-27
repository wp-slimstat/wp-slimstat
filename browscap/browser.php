<?php

class slim_browser {
	public static $browser = array();
	public static $browscap_autoload_path = '';

	public static function init() {
		self::$browser = array(
			'browser' => 'Default Browser',
			'browser_version' => '',
			'browser_type' => 1,
			'platform' => 'unknown',
			'user_agent' => self::_get_user_agent()
		);

		// Path to the Browscap data and library
		self::$browscap_autoload_path = wp_slimstat::$upload_dir . '/browscap-db/autoload.php';

		if ( file_exists( self::$browscap_autoload_path ) && version_compare( PHP_VERSION, '5.5', '>=' ) ) {
			$error = self::update_browscap_database( false );
			require_once( self::$browscap_autoload_path );

			if ( method_exists( 'slimBrowscapConnector', 'get_browser_from_browscap' ) ) {
				self::$browser = slimBrowscapConnector::get_browser_from_browscap( self::$browser );
			}
		}
	}

	/**
	 * Converts the USER AGENT string into a more user-friendly browser data structure, with name, version and operating system
	 */
	public static function get_browser( $_user_agent = '' ) {
		if ( empty( self::$browser[ 'user_agent' ] ) ) {
			return self::$browser;
		}

		if ( self::$browser[ 'browser' ] == 'Default Browser' ) {
			require_once( plugin_dir_path( __FILE__ ) . 'uadetector.php' );
			self::$browser = slim_uadetector::get_browser( self::$browser[ 'user_agent' ] );
		}

		return self::$browser;
	}
	// end get_browser

	/**
	 * Downloads the Browscap User Agent database from our repository
	 */
	public static function update_browscap_database( $_force_download = false ) {
		if ( version_compare( PHP_VERSION, '5.5', '<' ) ) {
			return array( 1, __( 'This library requires at least PHP 5.5. Please ask your service provider to upgrade your server accordingly.', 'wp-slimstat' ) );
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return array( 2, __( 'No updates are performed during AJAX requests.', 'wp-slimstat' ) );
		}

		// Create the folder, if it doesn't exist
		if ( !file_exists( wp_slimstat::$upload_dir ) ) {
			@mkdir( wp_slimstat::$upload_dir );
		}

		$download_remote_file = $_force_download;
		$local_version = 0;
		$browscap_zip = wp_slimstat::$upload_dir . '/browscap-db.zip';

		if ( empty( wp_slimstat::$settings[ 'browscap_last_modified' ] ) ) {

			if ( file_exists( self::$browscap_autoload_path ) ) {
				$file_stat = @stat( self::$browscap_autoload_path );
				if ( false !== $file_stat ) {
					wp_slimstat::$settings[ 'browscap_last_modified' ] = intval( $file_stat[ 'mtime' ] );
				}
			}
			
			// The variable could be still empty if the file does not exist or stat failed to open it
			if ( empty( wp_slimstat::$settings[ 'browscap_last_modified' ] ) ) {
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
			}

			wp_slimstat::slimstat_save_options();
		}

		// Check for updates once a week ( 604800 seconds ), if $_force_download is not true
		if ( ( false === $_force_download ) && ( date( 'U' ) - wp_slimstat::$settings[ 'browscap_last_modified' ] > 604800 ) && file_exists( self::$browscap_autoload_path ) ) {

			// Find the version of the local data file
			$handle = @fopen( self::$browscap_autoload_path, "rb" );
			if ( false === $handle ) {
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 3, __( 'The Browscap Library could not be opened on your filesystem. Please check your server permissions and try again.', 'wp-slimstat' ) );
			}

			while ( ( $buffer = @fgets( $handle, 4096 ) ) !== false ) {
				if ( strpos( $buffer, 'source_version' ) !== false ) {
					$local_version = intval( filter_var( $buffer, FILTER_SANITIZE_NUMBER_INT ) );
					break;
				}
			}

			fclose( $handle );

			// Now check the version number on the server
			$response = wp_remote_get( 'http://s3.amazonaws.com/browscap/autoload.txt' );
			if ( !is_array( $response ) || is_wp_error( $response ) || 200 != wp_remote_retrieve_response_code( $response ) ) {
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 4, __( 'There was an error checking the remote library version. Please try again later.', 'wp-slimstat' ) );
			}

			$remote_version = intval( wp_remote_retrieve_body( $response ) );
			$download_remote_file = ( $local_version < $remote_version );
		}

		// Download the most recent version of our pre-processed Browscap database
		if ( $download_remote_file ) {
			$response = wp_safe_remote_get( 'http://s3.amazonaws.com/browscap/browscap-db.zip', array( 'timeout' => 300, 'stream' => true, 'filename' => $browscap_zip ) );

			if ( !file_exists( $browscap_zip ) ) {
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 5, __( 'There was an error saving the Browscap data file on your server. Please check your folder permissions.', 'wp-slimstat' ) );
			}

			if ( is_wp_error( $response ) || 200 != wp_remote_retrieve_response_code( $response ) ) {
				@unlink( $browscap_zip );
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 6, __( 'There was an error downloading the Browscap data file from our server. Please try again later.', 'wp-slimstat' ) );
			}

			// Init the filesystem API
			require_once( ABSPATH . '/wp-admin/includes/file.php' );
			if ( !function_exists( 'wp_filesystem' ) ) {
				@unlink( $browscap_zip );
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 7, __( 'Could not initialize the WP Filesystem API. Please check your folder permissions and PHP configuration.', 'wp-slimstat' ) );
			}

			wp_filesystem();

			// Delete the existing folder, if there
			$GLOBALS[ 'wp_filesystem' ]->rmdir( dirname( self::$browscap_autoload_path ) . '/', true );

			// We're ready to unzip the file
			$unzip_done = unzip_file( $browscap_zip, wp_slimstat::$upload_dir );

			if ( !$unzip_done || !file_exists( self::$browscap_autoload_path ) ) {
				@unlink( $browscap_zip );
				$GLOBALS[ 'wp_filesystem' ]->rmdir( dirname( self::$browscap_autoload_path ) . '/', true );
				wp_slimstat::$settings[ 'browscap_last_modified' ] = date( 'U' );
				wp_slimstat::slimstat_save_options();
				return array( 8, __( 'There was an error uncompressing the Browscap data file on your server. Please check your folder permissions and PHP configuration.', 'wp-slimstat' ) );
			}

			if ( file_exists( $browscap_zip ) ) {
				@unlink( $browscap_zip );
			}
		}

		return array( 0, __( 'The Browscap data file has been installed on your server.', 'wp-slimstat' ) );
	}

	protected static function _get_user_agent() {
		$user_agent = ( !empty( $_SERVER[ 'HTTP_USER_AGENT' ] ) ? trim( $_SERVER[ 'HTTP_USER_AGENT' ] ) : '' );

		if ( !empty( $_SERVER[ 'HTTP_X_DEVICE_USER_AGENT' ] ) ) {
			$real_user_agent = trim( $_SERVER[ 'HTTP_X_DEVICE_USER_AGENT' ] );
		}
		elseif ( !empty( $_SERVER[ 'HTTP_X_ORIGINAL_USER_AGENT' ] ) ) {
			$real_user_agent = trim( $_SERVER[ 'HTTP_X_ORIGINAL_USER_AGENT' ] );
		}
		elseif( !empty( $_SERVER[ 'HTTP_X_MOBILE_UA' ] ) ) {
			$real_user_agent = trim( $_SERVER[ 'HTTP_X_MOBILE_UA' ] );
		}
		elseif( !empty( $_SERVER[ 'HTTP_X_OPERAMINI_PHONE_UA' ] ) ) {
			$real_user_agent = trim( $_SERVER[ 'HTTP_X_OPERAMINI_PHONE_UA' ] );
		}

		if ( !empty( $real_user_agent ) && ( strlen( $real_user_agent ) >= 5 || empty( $user_agent ) ) ) {
			return $real_user_agent;
		}

		return $user_agent;
	}
}