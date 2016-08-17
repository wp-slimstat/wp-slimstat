<?php

class slim_browser {
	/**
	 * Converts the USER AGENT string into a more user-friendly browser data structure, with name, version and operating system
	 */
	public static function get_browser( $_user_agent = '' ) {
		$browser = array( 'browser' => 'Default Browser', 'browser_version' => '', 'browser_type' => 1, 'platform' => 'unknown', 'user_agent' => empty( $_user_agent ) ? self::_get_user_agent() : $_user_agent );

		if ( empty( $browser[ 'user_agent' ] ) ) {
			return $browser;
		}

		if ( wp_slimstat::$settings[ 'browser_detection_mode' ] == 'no' ) {
			include_once( plugin_dir_path( __FILE__ ) . 'uadetector.php' );
			$browser = slim_uadetector::get_browser( $browser[ 'user_agent' ] );

			// If we found a match...
			if ( $browser[ 'browser' ] != 'Default Browser' ) {
				return $browser;
			}
		}

		// ... otherwise we need to resort to the bruteforce approach (browscap database)
		$search = array();
		@include( plugin_dir_path( __FILE__ ) . "browscap-db.php" );

		foreach ( $patterns as $pattern => $pattern_data ) {
			if ( preg_match( $pattern . 'i', $browser[ 'user_agent' ], $matches ) ) {
				if ( 1 == count( $matches ) ) {
					$key = $pattern_data;
					$simple_match = true;
				}
				else{
					$pattern_data = unserialize( $pattern_data );
					array_shift( $matches );
					
					$match_string = '@' . implode( '|', $matches );

					if ( !isset( $pattern_data[ $match_string ] ) ) {
						continue;
					}

					$key = $pattern_data[ $match_string ];

					$simple_match = false;
				}

				$search = array(
					$browser[ 'user_agent' ],
					trim( strtolower( $pattern ), '@' ),
					self::_preg_unquote( $pattern, $simple_match ? false : $matches )
				);

				$search = $value = $search + unserialize( $browsers[ $key ] );

				while ( array_key_exists( 3, $value ) ) {
					$value = unserialize( $browsers[ $value[ 3 ] ] );
					$search += $value;
				}

				if ( !empty( $search[ 3 ] ) && array_key_exists( $search[ 3 ], $userAgents ) ) {
					$search[ 3 ] = $userAgents[ $search[ 3 ] ];
				}

				break;
			}
		}

		unset( $browsers );
		unset( $userAgents );
		unset( $patterns );

		// Add the keys for each property
		$search_normalized = array();
		foreach ($search as $key => $value) {
			if ($value === 'true') {
				$value = true;
			} elseif ($value === 'false') {
				$value = false;
			}
			$search_normalized[strtolower($properties[$key])] = $value;
		}

		if (!empty($search_normalized) && $search_normalized['browser'] != 'Default Browser' && $search_normalized['browser'] != 'unknown'){
			$browser[ 'browser' ] = $search_normalized[ 'browser' ];
			$browser[ 'browser_version' ] = floatval( $search_normalized[ 'version' ] );
			$browser[ 'platform' ] = strtolower( $search_normalized[ 'platform' ] );
			$browser[ 'user_agent' ] =  $search_normalized[ 'browser_name' ];

			// Browser Types:
			//		0: regular
			//		1: crawler
			//		2: mobile
			if ($search_normalized['ismobiledevice'] || $search_normalized['istablet']){
				$browser['browser_type'] = 2;
			}
			elseif (!$search_normalized['crawler']){
				$browser['browser_type'] = 0;
			}

			if ( $browser[ 'browser_version' ] != 0 || $browser[ 'browser_type' ] != 0 ) {
				return $browser;
			}
		}

		if ( wp_slimstat::$settings[ 'browser_detection_mode' ] != 'no' ) {
			include_once( plugin_dir_path( __FILE__ ) . 'uadetector.php' );
			$browser = slim_uadetector::get_browser( $browser[ 'user_agent' ] );

			// If we found a match...
			if ( $browser[ 'browser' ] != 'Default Browser' ) {
				return $browser;
			}
		}

		return $browser;
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

	/**
	 * Helper function for get_browser [ courtesy of: GaretJax/PHPBrowsCap ]
	 */
	protected static function _preg_unquote($pattern, $matches){
		$search = array('\\@', '\\.', '\\\\', '\\+', '\\[', '\\^', '\\]', '\\$', '\\(', '\\)', '\\{', '\\}', '\\=', '\\!', '\\<', '\\>', '\\|', '\\:', '\\-', '.*', '.', '\\?');
		$replace = array('@', '\\?', '\\', '+', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':', '-', '*', '?', '.');

		$result = substr(str_replace($search, $replace, $pattern), 2, -2);

		if (!empty($matches)){
			foreach ($matches as $one_match){
				$num_pos = strpos($result, '(\d)');
				$result = substr_replace($result, $one_match, $num_pos, 4);
			}
		}

		return $result;
	}
}