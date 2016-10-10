<?php

class slim_browser {
	public static $browser = array();
	protected static $browscap_exists = false;

	public static function init() {
		self::$browser = array(
			'browser' => 'Default Browser',
			'browser_version' => '',
			'browser_type' => 1,
			'platform' => 'unknown',
			'user_agent' => self::_get_user_agent()
		);

		self::$browscap_exists = ( file_exists( wp_slimstat::$browscap_path ) || ( !empty( wp_slimstat::$settings[ 'enable_ads_network' ] ) && wp_slimstat::$settings[ 'enable_ads_network' ] == 'yes' ) );

		if ( self::$browscap_exists ) {
			wp_slimstat::update_browscap_database();
			@include_once( wp_slimstat::$browscap_path );

			if ( function_exists( 'slimstat_get_browser_from_browscap' ) ) {
				self::$browser = slimstat_get_browser_from_browscap( self::$browser, $browsers, $userAgents, $patterns, $properties );
			}
			else {
				@unlink( wp_slimstat::$browscap_path );
				wp_slimstat::update_browscap_database();

				if ( function_exists( 'slimstat_get_browser_from_browscap' ) ) {
					self::$browser = slimstat_get_browser_from_browscap( self::$browser, $browsers, $userAgents, $patterns, $properties );
				}
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

		if ( !self::$browscap_exists ) {
			include_once( plugin_dir_path( __FILE__ ) . 'uadetector.php' );
			self::$browser = slim_uadetector::get_browser( self::$browser[ 'user_agent' ] );

			// If we found a match...
			if ( self::$browser[ 'browser' ] != 'Default Browser' ) {
				return self::$browser;
			}
		}

		return self::$browser;
	}
	// end get_browser

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