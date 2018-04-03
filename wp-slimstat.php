<?php
/*
Plugin Name: Slimstat Analytics
Plugin URI: http://wordpress.org/plugins/wp-slimstat/
Description: The leading web analytics plugin for WordPress
Version: 4.7.7
Author: Jason Crouse
Author URI: http://www.wp-slimstat.com/
Text Domain: wp-slimstat
Domain Path: /languages
*/

if ( !empty( wp_slimstat::$settings ) ) {
	return true;
}

class wp_slimstat {
	public static $version = '4.7.7';
	public static $settings = array();

	public static $wpdb = '';
	public static $upload_dir = '';
	public static $maxmind_path = '';

	public static $update_checker = array();
	public static $raw_post_array = array();

	protected static $data_js = array( 'id' => 0 );
	protected static $stat = array();
	protected static $settings_signature = '';
	protected static $browser = array();
	protected static $heuristic_key = 0;
	protected static $date_i18n_filters = array();

	/**
	 * Initializes variables and actions
	 */
	public static function init() {

		// Load all the settings
		if ( is_network_admin() && ( empty($_GET[ 'page' ] ) || strpos( $_GET[ 'page' ], 'slimview' ) === false ) ) {
			self::$settings = get_site_option( 'slimstat_options', array() );
		}
		else {
			self::$settings = get_option( 'slimstat_options', array() );
		}

		self::$settings = array_merge( self::init_options(), self::$settings );

		// Allow third party tools to edit the options
		self::$settings = apply_filters( 'slimstat_init_options', self::$settings );

		// Determine the options' signature: if it hasn't changed, there's no need to update/save them in the database
		self::$settings_signature = md5( serialize( self::$settings ) );

		// Allow third-party tools to use a custom database for Slimstat
		self::$wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );

		// Hook a DB clean-up routine to the daily cronjob
		add_action( 'wp_slimstat_purge', array( __CLASS__, 'wp_slimstat_purge' ) );

		// Allow external domains on CORS requests
		add_filter( 'allowed_http_origins', array(__CLASS__, 'open_cors_admin_ajax' ) );

		// Define the folder where to store the geolocation database (shared among sites in a network, by default)
		self::$upload_dir = wp_upload_dir();
		self::$upload_dir = self::$upload_dir[ 'basedir' ];
		if ( is_multisite() && ! ( is_main_network() && is_main_site() && defined( 'MULTISITE' ) ) ) {
			self::$upload_dir = str_replace( '/sites/' . get_current_blog_id(), '', self::$upload_dir );
		}
		self::$upload_dir .= '/wp-slimstat';
		self::$upload_dir = apply_filters( 'slimstat_maxmind_path', self::$upload_dir );

		self::$maxmind_path = self::$upload_dir . '/maxmind.mmdb';

		// Enable the tracker (both server- and client-side)
		if ( !is_admin() || self::$settings[ 'track_admin_pages' ] == 'on' ) {

			// Allow add-ons to turn off the tracker based on other conditions
			$is_tracking_filter = apply_filters( 'slimstat_filter_pre_tracking', strpos( self::get_request_uri(), 'wp-admin/admin-ajax.php' ) === false );
			$is_tracking_filter_js = apply_filters( 'slimstat_filter_pre_tracking_js', true );

			// Is server-side tracking active?
			if ( self::$settings[ 'javascript_mode' ] != 'on' && self::$settings[ 'is_tracking' ] == 'on' && $is_tracking_filter ) {
				add_action( is_admin() ? 'admin_init' : 'wp', array( __CLASS__, 'slimtrack' ), 5 );

				if ( self::$settings[ 'track_users' ] == 'on' ) {
					add_action( 'login_init', array( __CLASS__, 'slimtrack' ), 10 );
				}
			}

			// Slimstat tracks screen resolutions, outbound links and other client-side information using a client-side tracker
			add_action( is_admin() ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts' , array( __CLASS__, 'wp_slimstat_enqueue_tracking_script' ), 15 );
			if ( self::$settings[ 'track_users' ] == 'on' ) {
				add_action( 'login_enqueue_scripts', array( __CLASS__, 'wp_slimstat_enqueue_tracking_script' ), 10 );
			}
		}

		// Shortcodes
		add_shortcode('slimstat', array(__CLASS__, 'slimstat_shortcode'), 15);

		// Load the admin library
		if ( is_user_logged_in() ) {
			include_once ( plugin_dir_path( __FILE__ ) . 'admin/wp-slimstat-admin.php' );
			add_action( 'init', array( 'wp_slimstat_admin', 'init' ), 60 );
		}

		// Include our browser detector library
		include_once( plugin_dir_path( __FILE__ ) . 'browscap/browser.php' );
		add_action( 'init', array( 'slim_browser', 'init' ) );

		// If add-ons are installed, check for updates
		add_action( 'wp_loaded', array( __CLASS__, 'update_checker' ) );

		// Update the options before shutting down
		add_action( 'shutdown', array( __CLASS__, 'slimstat_save_options' ), 100 );

		// REST API Support
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
	}
	// end init

	/**
	 * Ajax Tracking
	 */
	public static function slimtrack_ajax() {
		// If the website is using a caching plugin, the tracking code might still be there, even if the user turned off tracking
		if ( self::$settings[ 'is_tracking' ] != 'on' ) {
			self::$stat[ 'id' ] = -204;
			self::_set_error_array( __( 'Tracker is turned off, but client-side tracking code is still running.', 'wp-slimstat' ), true );
			self::slimstat_save_options();
			exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
		}

		// This function also initializes self::$data_js and removes the checksum from self::$data_js['id']
		self::_check_data_integrity( self::$raw_post_array );

		// Is this a request to record a new pageview?
		if ( self::$data_js[ 'op' ] == 'add' || self::$data_js[ 'op' ] == 'update' ) {

			// Track client-side information (screen resolution, plugins, etc)
			if ( !empty( self::$data_js[ 'bw' ] ) ) {
				self::$stat[ 'resolution' ] = strip_tags( trim( self::$data_js[ 'bw' ] . 'x' . self::$data_js[ 'bh' ] ) );
			}
			if ( !empty( self::$data_js[ 'sw' ] ) ) {
				self::$stat[ 'screen_width' ] = intval( self::$data_js[ 'sw' ] );
			}
			if ( !empty( self::$data_js[ 'sh' ] ) ) {
				self::$stat[ 'screen_height' ] = intval( self::$data_js[ 'sh' ] );
			}
			if ( !empty( self::$data_js[ 'pl' ] ) ) {
				self::$stat[ 'plugins' ] = strip_tags( trim( self::$data_js[ 'pl' ] ) );
			}
			if ( !empty( self::$data_js[ 'sl' ] ) && self::$data_js[ 'sl' ] > 0 && self::$data_js[ 'sl' ] < 60000 ) {
				self::$stat[ 'server_latency' ] = intval( self::$data_js[ 'sl' ] );
			}
			if ( !empty( self::$data_js[ 'pp' ] ) && self::$data_js[ 'pp' ] > 0 && self::$data_js[ 'pp' ] < 60000 ) {
				self::$stat[ 'page_performance' ] = intval( self::$data_js[ 'pp' ] );
			}
		}

		if ( self::$data_js[ 'op' ] == 'add' ) {
			self::slimtrack();
		}
		else if ( self::$data_js[ 'op' ] == 'update' ) {
			// Update an existing pageview with client-based information (resolution, plugins installed, etc)
			self::_set_visit_id( true );

			// ID of the pageview to update
			self::$stat[ 'id' ] = abs( intval( self::$data_js[ 'id' ] ) );

			// Visitor is still on this page, record the timestamp in the corresponding field
			self::toggle_date_i18n_filters( false );
			self::$stat['dt_out'] = date_i18n( 'U' );
			self::toggle_date_i18n_filters( true );

			// Are we tracking an outbound click?
			if ( !empty( self::$data_js[ 'res' ] ) ) {
				$outbound_resource = strip_tags( trim( base64_decode( self::$data_js[ 'res' ] ) ) );
				$outbound_host = parse_url( $outbound_resource, PHP_URL_HOST );
				$site_host = parse_url( get_site_url(), PHP_URL_HOST );
				if ( $outbound_host != $site_host ) {
					$existing_outbound_resource = self::$wpdb->get_var( self::$wpdb->prepare( "
						SELECT outbound_resource
						FROM {$GLOBALS['wpdb']->prefix}slim_stats
						WHERE id = %d", self::$stat[ 'id' ]
					) );

					self::$stat[ 'outbound_resource' ] = $outbound_resource;
					if ( !empty( $existing_outbound_resource ) ) {
						self::$stat[ 'outbound_resource' ] = $existing_outbound_resource . ';;;' .$outbound_resource;
					}
				}
			}

			self::update_row( self::$stat, $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats' );
		}
		
		// Are we tracking events, coordinates and other details?
		if ( self::$data_js[ 'op' ] == 'event' || !empty( self::$data_js[ 'pos' ] ) ) {
			self::$stat[ 'id' ] = abs( intval( self::$data_js[ 'id' ] ) );

			self::toggle_date_i18n_filters( false );
			$event_info = array(
				'position' => strip_tags( trim( self::$data_js[ 'pos' ] ) ),
				'id' => self::$stat[ 'id' ],
				'dt' => date_i18n( 'U' )
			);
			self::toggle_date_i18n_filters( true );

			if ( !empty( self::$data_js[ 'ty' ] ) ) {
				$event_info[ 'type' ] = abs( intval( self::$data_js[ 'ty' ] ) );
			}
			if ( !empty( self::$data_js[ 'des' ] ) ) {
				$event_info[ 'event_description' ] = strip_tags( trim( base64_decode( self::$data_js[ 'des' ] ) ) );
			}
			if ( !empty( self::$data_js[ 'no' ] ) ) {
				$event_info[ 'notes' ] = strip_tags( trim( base64_decode( self::$data_js[ 'no' ] ) ) );
			}

			self::insert_row( $event_info, $GLOBALS[ 'wpdb' ]->prefix . 'slim_events' );
		}

		// Was this pageview tracked?
		if ( self::$stat[ 'id' ] <= 0 ) {
			$abs_error_code = abs( self::$stat[ 'id' ] );
			do_action( 'slimstat_track_exit_' . $abs_error_code, self::$stat );
			self::slimstat_save_options();
			exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
		}

		// Send the ID back to Javascript to track future interactions
		do_action( 'slimstat_track_success' );

		// If we tracked an internal download, we return the original ID, not the new one
		exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
	}

	/**
	 * Core tracking functionality
	 */
	public static function slimtrack( $_argument = '' ) {
		// If the website is using a caching plugin, the tracking code might still be there, even if the user turned off tracking
		if ( self::$settings[ 'is_tracking' ] != 'on' ) {
			self::$stat[ 'id' ] = -204;
			self::_set_error_array( __( 'Tracker is turned off, but client-side tracking code is still running.', 'wp-slimstat' ), true );
			return $_argument;
		}

		self::toggle_date_i18n_filters( false );
		self::$stat[ 'dt' ] = date_i18n( 'U' );
		self::$stat[ 'notes' ] = array();
		self::toggle_date_i18n_filters( true );

		// Allow third-party tools to initialize the stat array
		self::$stat = apply_filters( 'slimstat_filter_pageview_stat_init', self::$stat );

		// Third-party tools can decide that this pageview should not be tracked, by setting its datestamp to zero
		if ( empty( self::$stat ) || empty( self::$stat[ 'dt' ] ) ) {
			self::$stat[ 'id' ] = -300;
			self::_set_error_array( __( 'Pageview filtered by third-party code', 'wp-slimstat' ), true );
			return $_argument;
		}

		// User's IP address
		list ( self::$stat[ 'ip' ], self::$stat[ 'other_ip' ] ) = self::_get_remote_ip();

		if ( empty( self::$stat[ 'ip' ] ) || self::$stat[ 'ip' ] == '0.0.0.0' ) {
			self::$stat[ 'id' ] = -202;
			self::_set_error_array( __( 'Empty or not supported IP address format (IPv6)', 'wp-slimstat' ), false );
			return $_argument;
		}

		// Referrer URL
		if ( !empty( self::$data_js[ 'ref' ] ) ) {
			self::$stat[ 'referer' ] = base64_decode( self::$data_js[ 'ref' ] );
		}
		else if ( !empty( $_SERVER[ 'HTTP_REFERER' ] ) ) {
			self::$stat[ 'referer' ] = $_SERVER[ 'HTTP_REFERER' ];
		}

		if ( !empty( self::$stat[ 'referer' ] ) ) {

			// Is this a 'seriously malformed' URL?
			$referer = parse_url( self::$stat[ 'referer' ] );
			if ( !$referer ) {
				self::_set_error_array( sprintf( __( 'Malformed referrer URL: %s (IP: %s)', 'wp-slimstat' ), self::$stat[ 'referer' ], self::$stat[ 'ip' ] ), false, 201 );
				self::$stat[ 'notes' ][] = sprintf( __( 'Malformed referrer URL: %s', 'wp-slimstat' ), self::$stat[ 'referer' ] );
				unset( self::$stat[ 'referer' ] );
			}

			if ( !empty( $referer[ 'scheme' ] ) && !in_array( strtolower( $referer[ 'scheme' ] ), array( 'http', 'https', 'android-app' ) ) ) {
				self::_set_error_array( sprintf( __( 'Attempted XSS Injection: %s (IP: %s)', 'wp-slimstat' ), self::$stat[ 'referer' ], self::$stat[ 'ip' ] ), false, 203 );
				self::$stat[ 'notes' ][] = sprintf( __( 'Attempted XSS Injection: %s', 'wp-slimstat' ), self::$stat[ 'referer' ] );
				unset( self::$stat[ 'referer' ] );
			}

			if ( !empty( self::$stat[ 'referer' ] ) ) {
				$parsed_site_url_host = parse_url( get_site_url(), PHP_URL_HOST );
				if ( !empty( $referer[ 'host' ] ) && $referer[ 'host' ] == $parsed_site_url_host && self::$settings[ 'track_same_domain_referers' ] != 'on' ) {
					unset( self::$stat[ 'referer' ] );
				}
				else {
					// Fix Google Images referring domain
					if ( strpos( self::$stat[ 'referer' ], 'www.google' ) !== false ) {
						if ( strpos( self::$stat[ 'referer' ], '/imgres?' ) !== false ) {
							self::$stat[ 'referer' ] = str_replace( 'www.google', 'images.google', self::$stat[ 'referer' ] );
						}
						if ( strpos( self::$stat[ 'referer' ], '/url?' ) !== false ) {
							self::$stat[ 'referer' ] = str_replace( '/url?', '/search?', self::$stat[ 'referer' ] );
						}
					}

					// Is this referer blacklisted?
					if ( !empty( self::$settings[ 'ignore_referers' ] ) ) {
						$return_error_code = array(
							-301,
							sprintf( __( 'Referrer %s is blacklisted', 'wp-slimstat' ), self::$stat[ 'referer' ] ),
							true
						);
						if ( self::_is_blacklisted( self::$stat[ 'referer' ], self::$settings[ 'ignore_referers' ], $return_error_code ) ) {
							return $_argument;
						}
					}
				}
			}
		}

		$content_info = self::_get_content_info();

		// Is this content type blacklisted?
		if ( !empty( self::$settings[ 'ignore_content_types' ] ) ) {
			$return_error_code = array(
				-313,
				sprintf( __( 'Content Type %s is blacklisted', 'wp-slimstat' ), $content_info[ 'content_type' ] ),
				true
			);
			if ( self::_is_blacklisted( $content_info[ 'content_type' ], self::$settings[ 'ignore_content_types' ], $return_error_code ) ) {
				return $_argument;
			}
		}

		// Did we receive data from an Ajax request?
		if ( !empty( self::$data_js[ 'id' ] ) ) {

			// Are we tracking a new pageview? (pos is empty = no event was triggered)
			if ( empty( self::$data_js[ 'pos' ] ) ) {
				$content_info = unserialize( base64_decode( self::$data_js[ 'id' ] ) );
				if ( $content_info === false || empty( $content_info[ 'content_type' ] ) ) {
					$content_info = array();
				}
			}

			// If pos is not empty and slimtrack was called, it means we are tracking a new internal download
			else if ( !empty( self::$data_js[ 'res' ] ) ) {
				$download_url = base64_decode( self::$data_js[ 'res' ] );
				if ( is_string( $download_url ) ) {
					if ( !empty( self::$data_js[ 'ty' ] ) && self::$data_js[ 'ty' ] == 1 ) {
						unset( self::$stat[ 'id' ] );
						$content_info = array( 'content_type' => 'download' );
					}
				}
			}
		}

		self::$stat = self::$stat + $content_info;

		// We want to record both hits and searches (performed through the site search form)
		if ( is_array( self::$data_js ) && isset( self::$data_js[ 'res' ] ) ) {
			$decoded_permalink = base64_decode( self::$data_js[ 'res' ] );
			$parsed_permalink = parse_url( $decoded_permalink );
			if ( !empty( $referer ) ) {
				self::$stat[ 'searchterms' ] = self::_get_search_terms( $referer );
			}

			// Was this an internal search?
			if ( empty( self::$stat[ 'searchterms' ] ) ) {
				self::$stat[ 'searchterms' ] = self::_get_search_terms( $parsed_permalink );
			}

			if ( self::$stat[ 'content_type' ] == 'external' ) {
				self::$stat['resource'] = $decoded_permalink;
			}
			else {
				self::$stat['resource'] = !is_array( $parsed_permalink ) ? __( 'Malformed URL', 'wp-slimstat' ) : urldecode( $parsed_permalink[ 'path' ] ) . ( !empty( $parsed_permalink[ 'query' ] ) ? '?' . urldecode( $parsed_permalink[ 'query' ] ) : '' );
			}
		}
		elseif ( empty( $_REQUEST[ 's' ] ) ) {
			if ( !empty( $referer ) ) {
				self::$stat[ 'searchterms' ] = self::_get_search_terms( $referer );
			}
			self::$stat[ 'resource' ] = self::get_request_uri();
		}
		else {
			self::$stat[ 'resource' ] = parse_url( self::get_request_uri(), PHP_URL_PATH );
			self::$stat[ 'searchterms' ] = str_replace( '\\', '', $_REQUEST[ 's' ] );
			self::$stat[ 'referer' ] = self::get_request_uri();
			if ( isset( $GLOBALS['wp_query']->found_posts ) ) {
				self::$stat[ 'notes' ][] = 'results:' . intval( $GLOBALS['wp_query']->found_posts );
			}
		}

		// Don't store empty values in the database
		if ( empty( self::$stat[ 'searchterms' ] ) ) {
			unset( self::$stat[ 'searchterms' ] );
		}

		// Do not track report pages in the admin
		if ( ( !empty( self::$stat[ 'resource' ] ) && strpos( self::$stat[ 'resource' ], 'wp-admin/admin-ajax.php' ) !== false ) || ( !empty( $_GET[ 'page' ] ) && strpos( $_GET[ 'page' ], 'slimview' ) !== false ) ) {
			self::$stat = array();
			return $_argument;
		}

		// Is this resource blacklisted?
		if ( !empty( self::$settings[ 'ignore_resources' ] ) ) {
			$return_error_code = array(
				-302,
				sprintf( __( 'Permalink %s is blacklisted', 'wp-slimstat' ), self::$stat[ 'resource' ] ),
				true
			);
			if ( self::_is_blacklisted( self::$stat[ 'resource' ], self::$settings[ 'ignore_resources' ], $return_error_code ) ) {
				return $_argument;
			}
		}

		// Should we ignore this user?
		if ( !empty( $GLOBALS[ 'current_user' ]->ID ) ) {
			// Don't track logged-in users, if the corresponding option is enabled
			if ( self::$settings[ 'track_users' ] == 'no' ) {
				self::$stat[ 'id' ] = -303;
				self::_set_error_array( sprintf( __( 'Logged in user %s not tracked', 'wp-slimstat' ), $GLOBALS[ 'current_user' ]->data->user_login ), true );
				return $_argument;
			}

			// Don't track users with given capabilities
			foreach ( self::string_to_array( self::$settings[ 'ignore_capabilities' ] ) as $a_capability ) {
				if ( array_key_exists( strtolower( $a_capability ), $GLOBALS[ 'current_user' ]->allcaps ) ) {
					self::$stat[ 'id' ] = -304;
					self::_set_error_array( sprintf( __( 'User with capability %s not tracked', 'wp-slimstat' ), $a_capability ), true );
					return $_argument;
				}
			}

			// Is this user blacklisted?
			if ( !empty( self::$settings[ 'ignore_users' ] ) ) {
				$return_error_code = array(
					-305,
					sprintf( __( 'User %s is blacklisted', 'wp-slimstat' ), $GLOBALS[ 'current_user' ]->data->user_login ),
					true
				);
				if ( self::_is_blacklisted( $GLOBALS[ 'current_user' ]->data->user_login, self::$settings[ 'ignore_users' ], $return_error_code ) ) {
					return $_argument;
				}
			}

			self::$stat[ 'username' ] = $GLOBALS[ 'current_user' ]->data->user_login;
			self::$stat[ 'notes' ][] = 'user:' . $GLOBALS[ 'current_user' ]->data->ID;
			$not_spam = true;
		}
		elseif ( isset( $_COOKIE[ 'comment_author_' . COOKIEHASH ] ) ) {
			// Is this a spammer?
			$spam_comment = self::$wpdb->get_row( self::$wpdb->prepare( "
				SELECT comment_author, COUNT(*) comment_count
				FROM `" . DB_NAME . "`.{$GLOBALS['wpdb']->comments}
				WHERE comment_author_IP = %s AND comment_approved = 'spam'
				GROUP BY comment_author
				LIMIT 0,1", self::$stat[ 'ip' ] ), ARRAY_A );

			if ( !empty( $spam_comment[ 'comment_count' ] ) ) {
				if ( self::$settings[ 'ignore_spammers' ] == 'on' ){
					self::$stat[ 'id' ] = -306;
					self::_set_error_array( sprintf( __( 'Spammer %s not tracked', 'wp-slimstat' ), $spam_comment[ 'comment_author' ] ), true );
					return $_argument;
				}
				else{
					self::$stat[ 'notes' ][] = 'spam:yes';
					self::$stat[ 'username' ] = $spam_comment[ 'comment_author' ];
				}
			}
			else
				self::$stat[ 'username' ] = $_COOKIE[ 'comment_author_' . COOKIEHASH ];
		}

		// Should we ignore this IP address?
		foreach ( self::string_to_array( self::$settings[ 'ignore_ip' ] ) as $a_ip_range ) {
			$ip_to_ignore = $a_ip_range;

			if ( strpos( $ip_to_ignore, '/' ) !== false ) {
				list( $ip_to_ignore, $cidr_mask ) = explode( '/', trim( $ip_to_ignore ) );
			}
			else{
				$cidr_mask = self::get_mask_length( $ip_to_ignore );
			}

			$long_masked_ip_to_ignore = substr( self::dtr_pton( $ip_to_ignore ), 0, $cidr_mask );
			$long_masked_user_ip = substr( self::dtr_pton( self::$stat[ 'ip' ] ), 0, $cidr_mask );
			$long_masked_user_other_ip = substr( self::dtr_pton( self::$stat[ 'other_ip' ] ), 0 , $cidr_mask );

			if ( $long_masked_user_ip === $long_masked_ip_to_ignore || $long_masked_user_other_ip === $long_masked_ip_to_ignore ) {
				self::$stat[ 'id' ] = -307;
				self::_set_error_array( sprintf( __( 'IP address %s is blacklisted', 'wp-slimstat' ), self::$stat[ 'ip' ] . ( !empty( self::$stat[ 'other_ip' ] ) ? ' (' . self::$stat[ 'other_ip' ] . ')' : '' ) ), true );
				return $_argument;
			}
		}

		// Language
		self::$stat[ 'language' ] = self::_get_language();

		// Geolocation 
		include_once ( plugin_dir_path( __FILE__ ) . 'maxmind.php' );
		$geolocation_data = maxmind_geolite2_connector::get_geolocation_info( self::$stat[ 'ip' ] );

		// Invalid MaxMind data file		

		if ( !empty( $geolocation_data[ 'country' ][ 'iso_code' ] ) ) {
			
			if ( $geolocation_data[ 'country' ][ 'iso_code' ] == 'xx' ) {
				self::_set_error_array( __( 'Your MaxMind data file is invalid. Please uninstall it using the button in Settings > Maintenance.', 'wp-slimstat' ), false, 205 );
			}
			else {
				self::$stat[ 'country' ] = strtolower( $geolocation_data[ 'country' ][ 'iso_code' ] );
			

				if ( !empty( $geolocation_data[ 'city' ][ 'names' ][ 'en' ] ) ) {
					self::$stat[ 'city' ] = $geolocation_data[ 'city' ][ 'names' ][ 'en' ];
				}

				if ( !empty( $geolocation_data[ 'subdivisions' ][ 0 ][ 'iso_code' ] ) && !empty( self::$stat[ 'city' ] ) ) {
					self::$stat[ 'city' ] .= ' (' . $geolocation_data[ 'subdivisions' ][ 0 ][ 'iso_code' ] . ')';
				}

				if ( !empty( $geolocation_data[ 'location' ][ 'latitude' ] ) && !empty( $geolocation_data[ 'location' ][ 'longitude' ] ) ) {
					self::$stat[ 'location' ] = $geolocation_data[ 'location' ][ 'latitude' ] . ',' .  $geolocation_data[ 'location' ][ 'longitude' ];
				}
			}
		}

		unset( $geolocation_data );

		// Anonymize IP Address?
		if ( self::$settings[ 'anonymize_ip' ] == 'on' ) {
			// IPv4 or IPv6
			$needle = '.';
			$replace = '.0';
			if ( self::get_mask_length( self::$stat['ip'] ) == 128 ) {
				$needle = ':';
				$replace = ':0000';
			}

			self::$stat[ 'ip' ] = substr( self::$stat[ 'ip' ], 0, strrpos( self::$stat[ 'ip' ], $needle ) ) . $replace;

			if ( !empty( self::$stat[ 'other_ip' ] ) ) {
				self::$stat[ 'other_ip' ] = substr( self::$stat[ 'other_ip' ], 0, strrpos( self::$stat[ 'other_ip' ], $needle ) ) . $replace;
			}
		}

		// Is this country blacklisted?
		if ( !empty( self::$stat[ 'country' ] ) && !empty( self::$settings[ 'ignore_countries' ] ) && stripos( self::$settings[ 'ignore_countries' ], self::$stat[ 'country' ] ) !== false ) {
			self::$stat['id'] = -308;
			self::_set_error_array( sprintf( __('Country %s is blacklisted', 'wp-slimstat'), self::$stat[ 'country' ] ), true );
			return $_argument;
		}

		// Mark or ignore Firefox/Safari prefetching requests (X-Moz: Prefetch and X-purpose: Preview)
		if ( ( isset( $_SERVER[ 'HTTP_X_MOZ' ] ) && ( strtolower( $_SERVER[ 'HTTP_X_MOZ' ] ) == 'prefetch' ) ) ||
			( isset( $_SERVER[ 'HTTP_X_PURPOSE' ] ) && ( strtolower( $_SERVER[ 'HTTP_X_PURPOSE' ] ) == 'preview' ) ) ) {
			if ( self::$settings[ 'ignore_prefetch' ] == 'on' ) {
				self::$stat[ 'id' ] = -309;
				self::_set_error_array( __( 'Prefetch requests are ignored', 'wp-slimstat' ), true );
				return $_argument;
			}
			else{
				self::$stat[ 'notes' ][] = 'pre:yes';
			}
		}

		// Detect user agent
		if ( empty( self::$browser ) ) {
			self::$browser = slim_browser::get_browser();
		}

		// Are we ignoring bots?
		if ( ( self::$settings[ 'javascript_mode' ] == 'on' || self::$settings[ 'ignore_bots' ] == 'on' ) && self::$browser[ 'browser_type' ] == 1 ) {
			self::$stat[ 'id' ] = -310;
			self::_set_error_array( __( 'Bot not tracked', 'wp-slimstat' ), true );
			return $_argument;
		}

		// Is this browser blacklisted?
		if ( !empty( self::$settings[ 'ignore_browsers' ] ) ) {
			$return_error_code = array(
				-311,
				sprintf( __( 'Browser %s is blacklisted', 'wp-slimstat' ), self::$browser[ 'browser' ] ),
				true
			);
			if ( self::_is_blacklisted( array( self::$browser[ 'browser' ], self::$browser[ 'user_agent' ] ), self::$settings[ 'ignore_browsers' ], $return_error_code ) ) {
				return $_argument;
			}
		}

		// Is this operating system blacklisted?
		if ( !empty( self::$settings[ 'ignore_platforms' ] ) ) {
			$return_error_code = array(
				-312,
				sprintf( __( 'Operating System %s is blacklisted', 'wp-slimstat' ), self::$browser[ 'platform' ] ),
				true
			);
			if ( self::_is_blacklisted( self::$browser[ 'platform' ], self::$settings[ 'ignore_platforms' ], $return_error_code ) ) {
				return $_argument;
			}
		}

		self::$stat = self::$stat + self::$browser;

		// This function can be called in "simulate" mode: no data will be actually saved in the database
		if ( is_array( $_argument ) && isset( $_argument[ 'slimtrack_simulate' ] ) ) {
			$_argument[ 'slimtrack_would_track' ] = true;
			return ( $_argument );
		}

		// Do we need to assign a visit_id to this user?
		$cookie_has_been_set = self::_set_visit_id( false );

		// Allow third-party tools to modify all the data we've gathered so far
		self::$stat = apply_filters( 'slimstat_filter_pageview_stat', self::$stat );
		do_action( 'slimstat_track_pageview', self::$stat );

		// Third-party tools can decide that this pageview should not be tracked, by setting its datestamp to zero
		if (empty(self::$stat) || empty(self::$stat['dt'])){
			self::$stat['id'] = -300;
			self::_set_error_array( __( 'Pageview filtered by third-party code', 'wp-slimstat' ), true );
			return $_argument;
		}

		// Implode the notes
		if ( !empty( self::$stat[ 'notes' ] ) ) {
			self::$stat[ 'notes' ] = implode( ';', self::$stat[ 'notes' ] );
		}
		else {
			unset( self::$stat[ 'notes' ] );
		}

		// Now let's save this information in the database
		self::$stat[ 'id' ] = self::insert_row( self::$stat, $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats' );

		// Something went wrong during the insert
		if ( empty( self::$stat[ 'id' ] ) ) {

			// Attempt to init the environment (plugin just activated on a blog in a MU network?)
			include_once ( plugin_dir_path( __FILE__ ) . 'admin/wp-slimstat-admin.php' );
			wp_slimstat_admin::init_environment( true );

			// Now let's try again
			self::$stat['id'] = self::insert_row(self::$stat, $GLOBALS['wpdb']->prefix.'slim_stats');

			if ( empty( self::$stat[ 'id' ] ) ) {
				self::$stat[ 'id' ] = -200;
				self::_set_error_array( self::$wpdb->last_error, false );
				return $_argument;
			}
		}

		// Is this a new visitor?
		$set_cookie = apply_filters( 'slimstat_set_visit_cookie', ( !empty( self::$settings[ 'set_tracker_cookie' ] ) && self::$settings[ 'set_tracker_cookie' ] == 'on' ) );
		if ( $set_cookie ) {
			if ( empty( self::$stat[ 'visit_id' ] ) && !empty( self::$stat[ 'id' ] ) ) {
				// Set a cookie to track this visit (Google and other non-human engines will just ignore it)
				@setcookie(
					'slimstat_tracking_code',
					self::_get_id_with_checksum( self::$stat[ 'id' ] . 'id' ),
					time() + 2678400, // one month
					COOKIEPATH
				);
			}
			elseif ( !$cookie_has_been_set && self::$settings[ 'extend_session' ] == 'on' && self::$stat[ 'visit_id' ] > 0 ) {
				@setcookie(
					'slimstat_tracking_code',
					self::_get_id_with_checksum( self::$stat[ 'visit_id' ] ),
					time() + self::$settings[ 'session_duration' ],
				 	COOKIEPATH
				);
			}
		}

		return $_argument;
	}
	// end slimtrack

	/**
	 * Decodes the permalink
	 */
	public static function get_request_uri(){
		if (isset($_SERVER['REQUEST_URI'])){
			return urldecode($_SERVER['REQUEST_URI']);
		}
		elseif (isset($_SERVER['SCRIPT_NAME'])){
			return isset($_SERVER['QUERY_STRING'])?$_SERVER['SCRIPT_NAME']."?".$_SERVER['QUERY_STRING']:$_SERVER['SCRIPT_NAME'];
		}
		else{
			return isset($_SERVER['QUERY_STRING'])?$_SERVER['PHP_SELF']."?".$_SERVER['QUERY_STRING']:$_SERVER['PHP_SELF'];
		}
	}
	// end get_request_uri

	/**
	 * Stores the information (array) in the appropriate table and returns the corresponding ID
	 */
	public static function insert_row($_data = array(), $_table = ''){
		if ( empty( $_data ) || empty( $_table ) ) {
			return -1;
		}

		// Remove unwanted characters (SQL injections, anyone?)
		$data_keys = array();
		foreach (array_keys($_data) as $a_key){
			$data_keys[] = sanitize_key($a_key);
		}

		self::$wpdb->query(self::$wpdb->prepare("
			INSERT IGNORE INTO $_table (".implode(", ", $data_keys).')
			VALUES ('.substr(str_repeat('%s,', count($_data)), 0, -1).")", $_data));

		return intval(self::$wpdb->insert_id);
	}
	// end insert_row

	/**
	 * Updates an existing row
	 */
	public static function update_row($_data = array(), $_table = ''){
		if (empty($_data) || empty($_table)){
			return -1;
		}

		// Move the ID at the end of the array
		$id = $_data['id'];
		unset($_data['id']);

		// Remove unwanted characters (SQL injections, anyone?)
		$data_keys = array();
		foreach (array_keys($_data) as $a_key){
			$data_keys[] = sanitize_key($a_key);
		}

		// Add the id at the end
		$_data['id'] = $id;

		self::$wpdb->query(self::$wpdb->prepare("
			UPDATE IGNORE $_table
			SET ".implode(' = %s, ', $data_keys)." = %s
			WHERE id = %d", $_data));

		return 0;
	}

	/**
	 * Tries to find the user's REAL IP address
	 */
	protected static function _get_remote_ip(){
		$ip_array = array( '', '' );

		if ( !empty( $_SERVER[ 'REMOTE_ADDR' ] ) && filter_var( $_SERVER[ 'REMOTE_ADDR' ], FILTER_VALIDATE_IP ) !== false ) {
			$ip_array[ 0 ] = $_SERVER[ 'REMOTE_ADDR' ];
		}

		$originating_ip_headers = array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', 'CF-Connecting-IP', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_X_FORWARDED' );
		foreach ( $originating_ip_headers as $a_header ) {
			if ( !empty( $_SERVER[ $a_header ] ) ) {
				foreach ( explode( ',', $_SERVER[ $a_header ] ) as $a_ip ) {
					if ( filter_var( $a_ip, FILTER_VALIDATE_IP ) !== false && $a_ip != $ip_array[ 0 ] ) {
						$ip_array[ 1 ] = $a_ip;
						break;
					}
				}
			}
		}

		return $ip_array;
	}
	// end _get_remote_ip

	/**
	 * Extracts the accepted language from browser headers
	 */
	protected static function _get_language(){
		if(isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])){

			// Capture up to the first delimiter (, found in Safari)
			preg_match("/([^,;]*)/", $_SERVER["HTTP_ACCEPT_LANGUAGE"], $array_languages);

			// Fix some codes, the correct syntax is with minus (-) not underscore (_)
			return str_replace( "_", "-", strtolower( $array_languages[0] ) );
		}
		return 'xx';  // Indeterminable language
	}
	// end _get_language

	/**
	 * Sniffs out referrals from search engines and tries to determine the query string
	 */
	protected static function _get_search_terms( $_url = array() ) {
		if ( empty( $_url ) || !isset( $_url[ 'host' ] ) ) {
			return '';
		}

		// Engines with different character encodings should always be listed here, regardless of their query string format
		$query_formats = array(
			'baidu.com' => 'wd',
			'bing' => 'q',
			'dogpile.com' => 'q',
			'duckduckgo' => 'q',
			'eniro' => 'search_word',
			'exalead.com' => 'q',
			'excite' => 'q',
			'gigablast' => 'q',
			'google' => 'q',
			'hotbot' => 'q',
			'maktoob' => 'p',
			'mamma' => 'q',
			'naver' => 'query',
			'qwant' => 'q',
			'rambler' => 'query',
			'seznam' => 'oq',
			'soso.com' => 'query',
			'virgilio' => 'qs',
			'voila' => 'rdata',
			'yahoo' => 'p',
			'yam' => 'k',
			'yandex' => 'text',
			'yell' => 'keywords',
			'yippy' => 'query',
			'youdao' => 'q'
		);

		$charsets = array( 'baidu' => 'EUC-CN' );
		$regex_match = implode( '|', array_keys( $query_formats ) );
		$searchterms = '';

		if ( !empty( $_url[ 'query' ] ) ) {
			parse_str( $_url[ 'query' ], $query );
		}

		if ( !empty( $_url[ 'host' ] ) ) {
			preg_match( "~($regex_match).~i", $_url[ 'host' ], $matches );
		}

		if ( !empty( $matches[ 1 ] ) ) {
			// Let's remember that this is a search engine, regardless of the URL containing searchterms (thank you, NSA)
			$searchterms = '_';
			if ( !empty( $query[ $query_formats[ $matches[ 1 ] ] ] ) ) {
				$searchterms = str_replace( '\\', '', trim( urldecode( $query[ $query_formats[ $matches[ 1 ] ] ] ) ) );
				// Test for encodings different from UTF-8
				if ( function_exists( 'mb_check_encoding' ) && !mb_check_encoding( $query[ $query_formats[ $matches[ 1 ] ] ], 'UTF-8' ) && !empty( $charsets[ $matches[ 1 ] ] ) ) {
					$searchterms = mb_convert_encoding( urldecode( $query[ $query_formats[ $matches[ 1 ] ] ] ), 'UTF-8', $charsets[ $matches[ 1 ] ] );
				}
			}
		}
		else {
			// We weren't lucky, but there's still hope
			foreach( array( 'q','s','k','qt' ) as $a_format ) {
				if ( !empty( $query[ $a_format ] ) ) {
					$searchterms = str_replace( '\\', '', trim( urldecode( $query[ $a_format ] ) ) );
					break;
				}
			}
		}

		return $searchterms;
	}
	// end _get_search_terms

	/**
	 * Returns details about the resource being accessed
	 */
	protected static function _get_content_info(){
		$content_info = array( 'content_type' => 'unknown' );

		// Mark 404 pages
		if ( is_404() ) {
			$content_info[ 'content_type' ] = '404';
		}

		// Type
		else if ( is_single() ) {
			if ( ( $post_type = get_post_type() ) != 'post' ) {
				$post_type = 'cpt:' . $post_type;
			}

			$content_info[ 'content_type' ] = $post_type;
			$content_info_array = array();
			foreach ( get_object_taxonomies( $GLOBALS[ 'post' ] ) as $a_taxonomy ) {
				$terms = get_the_terms( $GLOBALS[ 'post' ]->ID, $a_taxonomy );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $a_term ) {
						$content_info_array[] = $a_term->term_id;
					}
					$content_info[ 'category' ] = implode( ',', $content_info_array );
				}
			}
			$content_info[ 'content_id' ] = $GLOBALS[ 'post' ]->ID;
		}
		else if ( is_page() ) {
			$content_info[ 'content_type' ] = 'page';
			$content_info[ 'content_id' ] = $GLOBALS[ 'post' ]->ID;
		}
		elseif (is_attachment()){
			$content_info['content_type'] = 'attachment';
		}
		elseif (is_singular()){
			$content_info['content_type'] = 'singular';
		}
		elseif (is_post_type_archive()){
			$content_info['content_type'] = 'post_type_archive';
		}
		elseif (is_tag()){
			$content_info['content_type'] = 'tag';
			$list_tags = get_the_tags();
			if (is_array($list_tags)){
				$tag_info = array_pop($list_tags);
				if (!empty($tag_info)) $content_info['category'] = "$tag_info->term_id";
			}
		}
		elseif (is_tax()){
			$content_info['content_type'] = 'taxonomy';
		}
		elseif (is_category()){
			$content_info['content_type'] = 'category';
			$list_categories = get_the_category();
			if (is_array($list_categories)){
				$cat_info = array_pop($list_categories);
				if (!empty($cat_info)) $content_info['category'] = "$cat_info->term_id";
			}
		}
		else if (is_date()){
			$content_info['content_type']= 'date';
		}
		else if (is_author()){
			$content_info['content_type'] = 'author';
		}
		else if ( is_archive() ) {
			$content_info['content_type'] = 'archive';
		}
		else if ( is_search() ) {
			$content_info[ 'content_type' ] = 'search';
		}
		else if ( is_feed() ) {
			$content_info[ 'content_type' ] = 'feed';
		}
		else if ( is_home() || is_front_page() ) {
			$content_info['content_type'] = 'home';
		}
		else if ( !empty( $GLOBALS[ 'pagenow' ] ) && $GLOBALS[ 'pagenow' ] == 'wp-login.php' ) {
			$content_info[ 'content_type' ] = 'login';
		}
		else if ( !empty( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] == 'wp-register.php' ) {
			$content_info[ 'content_type' ] = 'registration';
		}
		// WordPress sets is_admin() to true for all ajax requests ( front-end or admin-side )
		elseif ( is_admin() && ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ) ) {
			$content_info[ 'content_type' ] = 'admin';
		}

		if (is_paged()){
			$content_info[ 'content_type' ] .= ',paged';
		}

		// Author
		if ( is_singular() ) {
			$author = get_the_author_meta( 'user_login', $GLOBALS[ 'post' ]->post_author );
			if ( !empty( $author ) ) {
				$content_info[ 'author' ] = $author;
			}
		}

		return $content_info;
	}
	// end _get_content_info

	/**
	 * Reads the cookie to get the visit_id and sets the variable accordingly
	 */
	protected static function _set_visit_id($_force_assign = false){
		$is_new_session = true;
		$identifier = 0;

		if ( isset( $_COOKIE[ 'slimstat_tracking_code' ] ) ) {
			// Make sure only authorized information is recorded
			$identifier = self::_separate_id_from_checksum( $_COOKIE[ 'slimstat_tracking_code' ] );
			if ( $identifier === false ) {
				return false;
			}

			$is_new_session = ( strpos( $identifier, 'id' ) !== false );
			$identifier = intval( $identifier );
		}

		// User doesn't have an active session
		if ( $is_new_session && ( $_force_assign || self::$settings[ 'javascript_mode' ] == 'on' ) ) {
			if ( empty( self::$settings[ 'session_duration' ] ) ) {
				self::$settings[ 'session_duration' ] = 1800;
			}

			self::$stat[ 'visit_id' ] = get_transient( 'slimstat_visit_id' );
			if ( self::$stat[ 'visit_id' ] === false ) {
				self::$stat[ 'visit_id' ] = intval( self::$wpdb->get_var( "SELECT MAX( visit_id ) FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats" ) );
			}
			self::$stat[ 'visit_id' ]++;
			set_transient( 'slimstat_visit_id', self::$stat[ 'visit_id' ] );

			$set_cookie = apply_filters( 'slimstat_set_visit_cookie', ( !empty( self::$settings[ 'set_tracker_cookie' ] ) && self::$settings[ 'set_tracker_cookie' ] == 'on' ) );
			if ( $set_cookie ) {
				@setcookie(
					'slimstat_tracking_code',
					self::_get_id_with_checksum( self::$stat[ 'visit_id' ] ),
					time() + self::$settings[ 'session_duration' ],
					COOKIEPATH
				);
			}

		}
		elseif ( $identifier > 0 ) {
			self::$stat[ 'visit_id' ] = $identifier;
		}

		if ( $is_new_session && $identifier > 0 ) {
			self::$wpdb->query( self::$wpdb->prepare( "
				UPDATE {$GLOBALS['wpdb']->prefix}slim_stats
				SET visit_id = %d
				WHERE id = %d AND visit_id = 0", self::$stat[ 'visit_id' ], $identifier
			) );
		}
		return ( $is_new_session && ( $_force_assign || self::$settings[ 'javascript_mode' ] == 'on' ) );
	}
	// end _set_visit_id

	/**
	 * Makes sure that the data received from the client is well-formed (and that nobody is trying to do bad stuff)
	 */
	protected static function _check_data_integrity( $_data = '' ) {
		// Parse the information we received
		self::$data_js = apply_filters( 'slimstat_filter_pageview_data_js', $_data );

		// Do we have an id for this request?
		if ( empty( self::$data_js[ 'id' ] ) || empty( self::$data_js[ 'op' ] ) ) {
			do_action( 'slimstat_track_exit_102' );
			self::$stat[ 'id' ] = -100;
			self::_set_error_array( __( 'Invalid payload string. Try clearing your WordPress cache.', 'wp-slimstat' ), false );
			self::slimstat_save_options();
			exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
		}

		// Make sure that the control code is valid
		self::$data_js[ 'id' ] = self::_separate_id_from_checksum( self::$data_js[ 'id' ] );

		if ( self::$data_js['id'] === false ) {
			do_action( 'slimstat_track_exit_103' );
			self::$stat[ 'id' ] = -101;
			self::_set_error_array( __( 'Invalid data signature. Try clearing your WordPress cache.', 'wp-slimstat' ), false );
			self::slimstat_save_options();
			exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
		}

		$intval_id = intval( self::$data_js[ 'id' ] );
		if ( $intval_id < 0 ) {
			do_action( 'slimstat_track_exit_' . abs( $intval_id ) );
			self::$stat[ 'id' ] = $intval_id;
			self::slimstat_save_options();
			exit( self::_get_id_with_checksum( self::$stat[ 'id' ] ) );
		}
	}
	// end _check_data_integrity

	protected static function _set_error_array( $_error_message = '', $_is_notice = false, $_error_code = 0 ) {
		$error_code = empty( $_error_code ) ? abs( self::$stat[ 'id' ] ) : $_error_code;
		self::toggle_date_i18n_filters( false );
		if ( $_is_notice ) {
			self::$settings[ 'last_tracker_notice' ] = array( $error_code, $_error_message, date_i18n( 'U' ) );
		}
		else {
			self::$settings[ 'last_tracker_error' ] = array( $error_code, $_error_message, date_i18n( 'U' ) );
		}
		self::toggle_date_i18n_filters( true );
	}

	protected static function _get_id_with_checksum( $_id = 0 ) {
		return $_id . '.' . md5( $_id . self::$settings[ 'secret' ] );
	}

	protected static function _separate_id_from_checksum( $_id_with_checksum = '' ) {
		list( $id, $checksum ) = explode( '.', $_id_with_checksum );

		if ( $checksum === md5( $id . self::$settings[ 'secret' ] ) ) {
			return $id;
		}

		return false;
	}

	protected static function _is_blacklisted( $_needles = array(), $_haystack_string = '', $_return_error_code = array( 0, '', false ) ) {
		foreach ( self::string_to_array( $_haystack_string ) as $a_item ) {
			$pattern = str_replace( array( '\*', '\!' ) , array( '(.*)', '.' ), preg_quote( $a_item, '@' ) );

			if ( !is_array( $_needles ) ) {
				$_needles = array( $_needles );
			}

			foreach ( $_needles as $a_needle ) {
				if ( preg_match( "@^$pattern$@i", $a_needle ) ) {

					self::$stat[ 'id' ] = $_return_error_code[ 0 ];
					self::_set_error_array( $_return_error_code[ 1 ], $_return_error_code[ 2 ] );
					return true;
				}
			}
		}

		return false;
	}

	public static function is_local_ip_address( $ip_address = '' ) {
		if ( !filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return false;
	}

	public static function dtr_pton( $ip ){
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$unpacked = unpack( 'A4', inet_pton( $ip ) );
		}
		else if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && defined( 'AF_INET6' ) ) {
			$unpacked = unpack( 'A16', inet_pton( $ip ) );
		}

		$binary_ip = '';
		if ( !empty( $unpacked ) ) {
			$unpacked = str_split( $unpacked[ 1 ] );
			foreach ( $unpacked as $char ) {
				$binary_ip .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
			}
		}

		return $binary_ip;
	}

	public static function get_mask_length( $ip ){
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 32;
		}
		else if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return 128;
		}

		return false;
	}

	/**
	 * Opens given domains during CORS requests to admin-ajax.php
	 */
	public static function open_cors_admin_ajax( $_allowed_origins = array() ) {
		$exploded_domains = self::string_to_array( self::$settings[ 'external_domains' ] );

		if ( !empty( $exploded_domains ) && !empty( $exploded_domains[ 0 ] ) ) {
			$_allowed_origins = array_merge( $_allowed_origins, $exploded_domains );
		}

		return $_allowed_origins;
	}

	/**
	 * Downloads the MaxMind geolocation database from their repository
	 */
	public static function download_maxmind_database() {
		// Create the folder, if it doesn't exist
		if ( !file_exists( dirname( self::$maxmind_path ) ) ) {
			mkdir( dirname( self::$maxmind_path ) );
		}

		if ( file_exists( self::$maxmind_path ) ) {
			if ( is_file( self::$maxmind_path ) ) {
				$is_deleted = @unlink( self::$maxmind_path );
			}
			else {
				// This should not happen, but hey...
				$is_deleted = @rmdir( self::$maxmind_path );
			}

			if ( !$is_deleted ) {
				return __( "The geolocation database cannot be updated. Please check your server's file permissions and try again.", 'wp-slimstat' );
			}
		}

		// Download the most recent database directly from MaxMind's repository
		if ( self::$settings[ 'geolocation_country' ] == 'on' ) {
			$maxmind_tmp = self::download_url( 'http://geolite.maxmind.com/download/geoip/database/GeoLite2-Country.mmdb.gz' );
		}
		else {
			$maxmind_tmp = self::download_url( 'http://geolite.maxmind.com/download/geoip/database/GeoLite2-City.mmdb.gz' );
		}

		if ( is_wp_error( $maxmind_tmp ) ) {
			return __( 'There was an error downloading the MaxMind Geolite DB:', 'wp-slimstat' ) . ' ' . $maxmind_tmp->get_error_message();
		}

		$zh = false;

		if ( !function_exists( 'gzopen' ) ) {
			if ( function_exists( 'gzopen64' ) ) {
				if ( false === ( $zh = gzopen64( $maxmind_tmp, 'rb' ) ) ) {
					return __( 'There was an error opening the zipped MaxMind Geolite DB.', 'wp-slimstat' );
				}
			}
			else {
				return __( 'Function gzopen not defined. Aborting.', 'wp-slimstat' );
			}
		}
		else{
			if ( false === ( $zh = gzopen( $maxmind_tmp, 'rb' ) ) ) {
				return __( 'There was an error opening the zipped MaxMind Geolite DB.', 'wp-slimstat' );
			}
		}

		if ( false === ( $fh = fopen( self::$maxmind_path, 'wb' ) ) ) {
			return __( 'There was an error opening the MaxMind Geolite DB.', 'wp-slimstat' );
		}

		while ( ( $data = gzread( $zh, 4096 ) ) != false ) {
			fwrite( $fh, $data );
		}

		@gzclose( $zh );
		@fclose( $fh );

		if ( !is_file( self::$maxmind_path ) ) {
			// Something went wrong, maybe a folder was created instead of a regular file
			@rmdir( self::$maxmind_path );
			return __( 'There was an error creating the MaxMind Geolite DB.', 'wp-slimstat' );
		}

		@unlink( $maxmind_tmp );

		return '';
	}

	public static function download_url( $url ) {
		// Include the FILE API, if it's not defined
		if ( !function_exists( 'download_url' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		if ( !$url ) {
			return new WP_Error('http_no_url', __('Invalid URL Provided.'));
		}

		$url_filename = basename( parse_url( $url, PHP_URL_PATH ) );

		$tmpfname = wp_tempnam( $url_filename );
		if ( ! $tmpfname ) {
			return new WP_Error('http_no_file', __('Could not create Temporary file.'));
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmpfname, 'user-agent'  => 'Slimstat Analytics/' . self::$version . '; ' . home_url() ) );

		if ( is_wp_error( $response ) ) {
		        unlink( $tmpfname );
		        return $response;
		}

		if ( 200 != wp_remote_retrieve_response_code( $response ) ){
		        unlink( $tmpfname );
		        return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		return $tmpfname;
	}

	public static function slimstat_shortcode( $_attributes = '', $_content = '' ) {
		extract( shortcode_atts( array(
			'f' => '',		// recent, popular, count, widget
			'w' => '',		// column to use (for recent, popular and count) or widget to use
			's' => ' ',		// separator
			'o' => 0		// offset for counters
		), $_attributes));

		$output = $where = $as_column = '';
		$s = "<span class='slimstat-item-separator'>$s</span>";

		// Load the localization files (for languages, operating systems, etc)
		load_plugin_textdomain( 'wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/languages', '/wp-slimstat/languages' );

		// Look for required fields
		if ( empty( $f ) || empty( $w ) ) {
			return '<!-- Slimstat Shortcode Error: missing parameter -->';
		}

		// Include the Reports Library, but don't initialize the database, since we will do that separately later
		include_once( dirname(__FILE__) . '/admin/view/wp-slimstat-reports.php' );
		wp_slimstat_reports::init();

		// Init the database library with the appropriate filters
		if ( strpos ( $_content, 'WHERE:' ) !== false ) {
			$where = html_entity_decode( str_replace( 'WHERE:', '', $_content ), ENT_QUOTES, 'UTF-8' );
			// wp_slimstat_db::init();
		}
		else{
			wp_slimstat_db::init( html_entity_decode( $_content, ENT_QUOTES, 'UTF-8' ) );
		}

		switch( $f ) {
			case 'count':
			case 'count-all':
				$output = wp_slimstat_db::count_records( $w, $where, strpos( $f, 'all') === false ) + $o;
				break;

			case 'widget':
				if ( empty( wp_slimstat_reports::$reports_info[ $w ] ) ) {
					return __( 'Undefined report ID', 'wp-slimstat' );
				}

				wp_register_style( 'wp-slimstat-frontend', plugins_url( '/admin/css/slimstat.frontend.css', __FILE__ ) );
				wp_enqueue_style( 'wp-slimstat-frontend' );

				ob_start();
				echo wp_slimstat_reports::report_header( $w );
				call_user_func( wp_slimstat_reports::$reports_info[ $w ][ 'callback' ], wp_slimstat_reports::$reports_info[ $w ][ 'callback_args' ] );
				wp_slimstat_reports::report_footer();
				$output = ob_get_contents();
				ob_end_clean();
				break;

			case 'recent':
			case 'recent-all':
			case 'top':
			case 'top-all':
				$function = 'get_' . str_replace( '-all', '', $f );

				if ( $w == '*' ) {
					$w = 'id';
				}

				$w = self::string_to_array( $w );

				// Some columns are 'special' and need be removed from the list
				$w_clean = array_diff( $w, array( 'count', 'display_name', 'hostname', 'post_link', 'post_link_no_qs', 'dt' ) );

				// The special value 'display_name' requires the username to be retrieved
				if ( in_array( 'display_name', $w ) ) {
					$w_clean[] = 'username';
				}

				// The special value 'post_list' requires the resource to be retrieved
				if ( in_array( 'post_link', $w ) ) {
					$w_clean[] = 'resource';
				}

				// The special value 'post_list_no_qs' requires a substring to be calculated
				if ( in_array( 'post_link_no_qs', $w ) ) {
					$w_clean =  array( 'SUBSTRING_INDEX( resource, "' . ( !get_option( 'permalink_structure' ) ? '&' : '?' ) . '", 1 )' );
					$as_column = 'resource_calculated';
				}

				// Retrieve the data
				$results = wp_slimstat_db::$function( implode( ', ', $w_clean ), $where, '', strpos( $f, 'all' ) === false, $as_column );

				// No data? No problem!
				if ( empty( $results ) ) {
					return '<!--  Slimstat Shortcode: No Data -->';
				}

				// Are nice permalinks enabled?
				$permalinks_enabled = get_option( 'permalink_structure' );

				// Format results
				$output = array();
				foreach( $results as $result_idx => $a_result ) {
					foreach( $w as $a_column ) {
						$output[ $result_idx ][ $a_column ] = "<span class='col-$a_column'>";

						switch( $a_column ) {
							case 'count':
								$output[ $result_idx ][ $a_column ] .= $a_result[ 'counthits' ];
								break;

							case 'country':
								$output[ $result_idx ][ $a_column ] .= __( 'c-' . $a_result[ $a_column ], 'wp-slimstat' );
								break;

							case 'display_name':
								$user_details = get_user_by( 'login', $a_result[ 'username' ] );
								if ( !empty( $user_details ) ) {
									$output[ $result_idx ][ $a_column ] .= $user_details->display_name;
								}
								else {
									$output[ $result_idx ][ $a_column ] .=  $a_result[ 'username' ];
								}
								
								break;

							case 'dt':
								$output[ $result_idx ][ $a_column ] .= date_i18n( self::$settings[ 'date_format' ] . ' ' . self::$settings[ 'time_format' ], $a_result[ 'dt' ] );
								break;

							case 'hostname':
								$output[ $result_idx ][ $a_column ] .= gethostbyaddr( $a_result[ 'ip' ] );
								break;

							case 'language':
								$output[ $result_idx ][ $a_column ] .= __( 'l-' . $a_result[ $a_column ], 'wp-slimstat' );
								break;

							case 'platform':
								$output[ $result_idx ][ $a_column ] .= __( $a_result[ $a_column ], 'wp-slimstat' );
								break;

							case 'post_link':
							case 'post_link_no_qs':
								$resource_key = ( $a_column == 'post_link' ) ? 'resource' : 'resource_calculated';
								$post_id = url_to_postid( $a_result[ $resource_key ] );
								if ( $post_id > 0 ) {
									$output[ $result_idx ][ $a_column ] .= "<a href='{$a_result[ $resource_key ]}'>" . get_the_title( $post_id ) . '</a>';
								}
								else {
									$output[ $result_idx ][ $a_column ] .= "<a href='{$a_result[ $resource_key ]}'>{$a_result[ $resource_key ]}</a>";
								}
								break;

							default:
								$output[ $result_idx ][ $a_column ] .= $a_result[ $a_column ];
								break;
						}
						$output[ $result_idx ][ $a_column ] .= '</span>';
					}
					$output[ $result_idx ] = '<li>' . implode( $s, $output[ $result_idx ] ). '</li>';
				}

				$output = '<ul class="slimstat-shortcode ' . $f . implode( '-', $w ). '">' . implode( '', $output ) . '</ul>';
				break;

			default:
				break;
		}

		return $output;
	}

	public static function rest_api_response( $_request = array() ) {
		$filters = '';
		if ( !empty( $_request[ 'filters' ] ) ) {
			$filters = $_request[ 'filters' ];
		}

		if ( empty( $_request[ 'dimension' ] ) ) {
			return new WP_Error( 'rest_invalid', esc_html__( 'The dimension parameter is required. Please review your request and try again.', 'wp-slimstat' ), array( 'status' => 400 ) );
		}

		if ( empty( $_request[ 'function' ] ) ) {
			return new WP_Error( 'rest_invalid', esc_html__( 'The function parameter is required. Please review your request and try again.', 'wp-slimstat' ), array( 'status' => 400 ) );
		}

		include_once( dirname(__FILE__) . '/admin/view/wp-slimstat-db.php' );
		wp_slimstat_db::init( $filters );

		$response = array(
			'function' => htmlentities( $_request[ 'function' ], ENT_QUOTES, 'UTF-8' ),
			'dimension' => htmlentities( $_request[ 'dimension' ], ENT_QUOTES, 'UTF-8' ),

			'data' => 0
		);

		switch( $_request[ 'function' ] ) {
			case 'count':
			case 'count-all':
				$response[ 'data' ] = wp_slimstat_db::count_records( $_request[ 'dimension' ], '', strpos( $_request[ 'function' ], '-all') === false );
				break;

			case 'recent':
			case 'recent-all':
			case 'top':
			case 'top-all':
				$function = 'get_' . str_replace( '-all', '', $_request[ 'function' ] );

				// Retrieve the data
				$response[ 'data' ] = array_values( wp_slimstat_db::$function( $_request[ 'dimension' ], '', '', strpos( $_request[ 'function' ], '-all' ) === false ) );
				break;

			default:
				// This should never happen, because of the 'enum' condition for this parameter. But never say never...
				$response[ 'data' ] = new WP_Error( 'rest_invalid', esc_html__( 'Valid function values are count, count-all, recent, recent-all, top and top-all. Please review your request and try again.', 'wp-slimstat' ), array( 'status' => 400 ) );
				break;
		}

		return rest_ensure_response( $response );
	}

	public static function rest_api_authorization( $_request = array() ) {
		if ( empty( $_request[ 'token' ] ) ) {
			return new WP_Error( 'rest_invalid', esc_html__( 'Please specify a valid token in order to access this REST API.', 'wp-slimstat' ), array( 'status' => 400 ) );
		}

		if ( !in_array( $_request[ 'token' ], self::string_to_array( self::$settings['rest_api_tokens'] ) ) ) {
			return false;
		}

		return true;
	}

	public static function register_rest_route() {
		register_rest_route( 'slimstat/v1', '/get', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'rest_api_response' ),
			'permission_callback' => array( __CLASS__, 'rest_api_authorization' ),
			'args' => array(
				'token' => array(
					'description' => __( 'You will need to specify a valid token to be able to query the data. Tokens are defined in Slimstat > Settings > Access Control.', 'wp-slimstat' ),
					'type' => 'string'
				),
				'function' => array(
					'description' => __( 'This parameter specifies the type of QUERY for the dimension. Valid values are: count, count-all, recent, recent-all, top and top-all.', 'wp-slimstat' ),
					'type' => 'string',
					'enum' => array( 'count', 'count-all', 'recent', 'recent-all', 'top', 'top-all' )
				),
				'dimension' => array(
					'description' => __( 'This parameter indicates what dimension to return: * (all data), ip, resource, browser, operating system, etc. You can only specify one dimension at a time.', 'wp-slimstat' ),
					'type' => 'string',
					'enum' => array( '*', 'id', 'ip', 'username', 'country', 'referer', 'resource', 'searchterms', 'browser', 'platform', 'language', 'resolution', 'content_type', 'content_id', 'outbound_resource' )
				),
				'filters' => array(
					'description' => __( 'This parameter is used to filter a given dimension (resources, browsers, operating systems, etc) so that it satisfies certain conditions (i.e.: browser contains Chrome). Please make sure to urlencode this value, and to use the usual filter format: browser contains Chrome&&&referer contains slim (encoded: browser%20contains%20Chrome%26%26%26referer%20contains%20slim)', 'wp-slimstat' ),
					'type' => 'string'
				)
			)
		) );
	}

	/**
	 * Converts a series of comma separated values into an array
	 */
	public static function string_to_array( $_option = '' ) {
		if ( empty( $_option ) || !is_string( $_option ) ) {
			return array();
		}
		else {
			return array_filter( array_map( 'trim', explode( ',', $_option ) ) );
		}
	}

	/**
	 * Toggles WordPress filters on date_i18n function
	 */
	public static function toggle_date_i18n_filters( $_turn_on = true ) {
		if ( $_turn_on && !empty( self::$date_i18n_filters ) && is_array( self::$date_i18n_filters ) ) {
			foreach ( self::$date_i18n_filters as $i18n_priority => $i18n_func_list ) {
				foreach ( $i18n_func_list as $func_name => $func_args ) {
					if ( !empty( $func_args[ 'function' ] ) && is_string( $func_args[ 'function' ] ) ) {
						add_filter( 'date_i8n', $func_args[ 'function' ], $i18n_priority, intval( $func_args[ 'accepted_args' ] ) );
					}
				}
			}
		}
		else if ( !empty( $GLOBALS[ 'wp_filter' ][ 'date_i18n' ][ 'callbacks' ] ) && is_array( $GLOBALS[ 'wp_filter' ][ 'date_i18n' ][ 'callbacks' ] ) ) {
			self::$date_i18n_filters = $GLOBALS[ 'wp_filter' ][ 'date_i18n' ][ 'callbacks' ];
			remove_all_filters( 'date_i18n' );
		}
	}

	/**
	 * Imports all the 'old' options into the new array, and saves them
	 */
	public static function init_options(){
		return array(
			'version' => self::$version,
			'secret' => wp_hash( uniqid( time(), true ) ),
			'show_admin_notice' => 0,
			'browscap_last_modified' => 0,

			// General
			'is_tracking' => 'on',
			'javascript_mode' => 'on',
			'enable_javascript' => 'on',
			'track_admin_pages' => 'no',
			'use_separate_menu' => 'on',
			'add_posts_column' => 'no',
			'posts_column_day_interval' => 30,
			'posts_column_pageviews' => 'on',
			'add_dashboard_widgets' => 'on',
			'hide_addons' => 'no',
			'auto_purge' => 0,
			'auto_purge_delete' => 'on',

			// Tracker
			'do_not_track_outbound_classes_rel_href' => 'noslimstat,ab-item',
			'extensions_to_track' => 'pdf,doc,xls,zip',
			'track_same_domain_referers' => 'on',
			'geolocation_country' => 'on',
			'session_duration' => 1800,
			'extend_session' => 'no',
			'set_tracker_cookie' => 'on',
			'enable_cdn' => 'on',
			'ajax_relative_path' => 'no',
			'external_domains' => '',

			// Filters
			'track_users' => 'on',
			'ignore_spammers' => 'on',
			'ignore_bots' => 'no',
			'ignore_prefetch' => 'on',
			'anonymize_ip' => 'no',

			'ignore_users' => '',
			'ignore_ip' => '',
			'ignore_countries' => '',
			'ignore_browsers' => '',
			'ignore_platforms' => '',
			'ignore_capabilities' => '',

			'ignore_resources' => '',
			'ignore_referers' => '',
			'ignore_content_types' => '',

			// Reports
			'use_european_separators' => 'on',
			'date_format' => 'm-d-y',
			'time_format' => 'h:i a',
			'show_display_name' => 'no',
			'convert_resource_urls_to_titles' => 'on',
			'convert_ip_addresses' => 'no',
			'async_load' => 'no',
			'use_current_month_timespan' => 'no',
			'expand_details' => 'no',
			'rows_to_show' => '20',
			'limit_results' => '1000',
			'ip_lookup_service' => 'http://www.infosniper.net/?ip_address=',
			'mozcom_access_id' => '',
			'mozcom_secret_key' => '',
			'refresh_interval' => '60',
			'number_results_raw_data' => '50',
			'max_dots_on_map' => '50',
			'custom_css' => '',
			'chart_colors' => '',
			'comparison_chart' => 'on',
			'show_complete_user_agent_tooltip' => 'no',
			'enable_sov' => 'no',

			// Access Control
			'restrict_authors_view' => 'on',
			'capability_can_view' => 'activate_plugins',
			'can_view' => '',
			'rest_api_tokens' => wp_hash( uniqid( time() - 3600, true ) ),
			'capability_can_admin' => 'activate_plugins',
			'can_admin' => '',

			// Maintenance
			'last_tracker_error' => array( 0, '', 0 ),
			'show_sql_debug' => 'no',
			'no_maxmind_warning' => 'no',
			'no_browscap_warning' => 'no',

			// Network-wide Settings
			'locked_options' => ''
		);
	}
	// end init_options

	/**
	 * Saves the options in the database, if necessary
	 */
	public static function slimstat_save_options() {
		// Allow third-party functions to manipulate the options right before they are saved
		self::$settings = apply_filters( 'slimstat_save_options', self::$settings );

		if ( self::$settings_signature === md5( serialize( self::$settings ) ) ) {
			return true;
		}

		if ( !is_network_admin() ) {
			update_option( 'slimstat_options', self::$settings );
		}
		else {
			update_site_option( 'slimstat_options', self::$settings );
		}

		return true;
	}

	/**
	 * Enqueue a javascript to track users' screen resolution and other browser-based information
	 */
	public static function wp_slimstat_enqueue_tracking_script() {
		// Do not enqueue the script if the corresponding options are turned off
		$is_tracking_filter_js = apply_filters( 'slimstat_filter_pre_tracking_js', true );
		if ( ( self::$settings[ 'enable_javascript' ] != 'on' && self::$settings[ 'javascript_mode' ] != 'on' ) || self::$settings[ 'is_tracking' ] != 'on' || !$is_tracking_filter_js ) {
			return 0;
		}

		// Register the script in WordPress
		$jstracker_suffix = ( defined( 'SCRIPT_DEBUG' ) && is_bool( SCRIPT_DEBUG ) && SCRIPT_DEBUG ) ? '' : '.min';

		// Pass some information to the tracker
		$params = array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) );

		if ( self::$settings[ 'ajax_relative_path' ] == 'on' ) {	
			$params[ 'ajaxurl' ] = admin_url( 'admin-ajax.php', 'relative' );
		}

		if ( !empty( self::$settings[ 'extensions_to_track' ] ) ) {
			$params[ 'extensions_to_track' ] = str_replace( ' ', '', self::$settings[ 'extensions_to_track' ] );
		}
		if ( !empty( self::$settings[ 'do_not_track_outbound_classes_rel_href' ] ) ) {
			$params[ 'outbound_classes_rel_href_to_not_track' ] = str_replace( ' ', '', self::$settings[ 'do_not_track_outbound_classes_rel_href' ] );
		}

		if ( self::$settings[ 'javascript_mode' ] != 'on' ) {
			// Do not enqueue the tracker if this page view was not tracked for some reason
			if ( intval( self::$stat[ 'id' ] ) < 0 ) {
				return false;
			}

			if ( !empty( self::$stat[ 'id' ] ) ) {
				$params[ 'id' ] = self::_get_id_with_checksum( self::$stat[ 'id' ] );
			}
			else {
				$params[ 'id' ] = self::_get_id_with_checksum( '-300' );
			}
		}
		else {
			// Check filters and blacklists to see if this page should be tracked
			$simulate = self::slimtrack( array( 'slimtrack_simulate' => true ) );
			if ( empty( $simulate[ 'slimtrack_would_track' ] ) ) {
				return false;
			}

			$encoded_ci = base64_encode( serialize( self::_get_content_info() ) );
			$params[ 'ci' ] = self::_get_id_with_checksum( $encoded_ci );
		}

		$params = apply_filters( 'slimstat_js_params', $params );

		if ( self::$settings[ 'enable_cdn' ] == 'on' ) {
			$schema = is_ssl() ? 'https' : 'http';
			wp_register_script( 'wp_slimstat', $schema . '://cdn.jsdelivr.net/wp/wp-slimstat/tags/' . self::$version . '/wp-slimstat.min.js', array(), null, true );
		}
		else{
			wp_register_script( 'wp_slimstat', plugins_url( "/wp-slimstat{$jstracker_suffix}.js", __FILE__ ), array(), null, true );
		}

		wp_enqueue_script( 'wp_slimstat' );
		wp_localize_script( 'wp_slimstat', 'SlimStatParams', $params );
	}

	/**
	 * Removes old entries from the main table and performs other daily tasks
	 */
	public static function wp_slimstat_purge() {
		$autopurge_interval = intval( self::$settings[ 'auto_purge' ] );

		if ( $autopurge_interval <= 0 ) {
			return;
		}

		self::toggle_date_i18n_filters( false );
		$days_ago = strtotime( date_i18n( 'Y-m-d H:i:s' ) . " -$autopurge_interval days" );
		self::toggle_date_i18n_filters( true );

		// Copy entries to the archive table, if needed
		if ( self::$settings[ 'auto_purge_delete' ] != 'no' ) {
			$is_copy_done = self::$wpdb->query("
				INSERT INTO {$GLOBALS['wpdb']->prefix}slim_stats_archive (id, ip, other_ip, username, country, location, city, referer, resource, searchterms, plugins, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, outbound_resource, dt_out, dt)
				SELECT id, ip, other_ip, username, country, location, city, referer, resource, searchterms, plugins, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, outbound_resource, dt_out, dt
				FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats
				WHERE dt < $days_ago");

			if ( $is_copy_done !== false ) {
				self::$wpdb->query("DELETE ts FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ts WHERE ts.dt < $days_ago");
			}

			$is_copy_done = self::$wpdb->query("
				INSERT INTO {$GLOBALS['wpdb']->prefix}slim_events_archive (type, event_description, notes, position, id, dt)
				SELECT type, event_description, notes, position, id, dt
				FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events
				WHERE dt < $days_ago");

			if ( $is_copy_done !== false ) {
				self::$wpdb->query("DELETE te FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events te WHERE te.dt < $days_ago");
			}
		}
		else {
			// Delete old entries
			self::$wpdb->query("DELETE ts FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ts WHERE ts.dt < $days_ago");
			self::$wpdb->query("DELETE te FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events te WHERE te.dt < $days_ago");
		}

		// Optimize tables
		self::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats" );
		self::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive" );
		self::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_events" );
		self::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_events_archive" );
	}

	/**
	 * Checks for add-on updates
	 */
	public static function update_checker() {
		if ( empty( self::$update_checker ) || !is_admin() ) {
			return true;
		}

		$update_checker_objects = array();
		
		// This is only included if add-ons are installed
		include_once( plugin_dir_path( __FILE__ ) . 'admin/update-checker/plugin-update-checker.php' );

		foreach ( self::$update_checker as $a_slug ) {
			$a_clean_slug = str_replace( array( 'wp_slimstat_', '_' ), array( '', '-' ), $a_slug );
			
			if ( !empty( self::$settings[ 'addon_licenses' ][ 'wp-slimstat-' . $a_clean_slug ] ) ) {
				$update_checker_objects[ $a_clean_slug ] = Puc_v4_Factory::buildUpdateChecker( 'http://www.wp-slimstat.com/update-checker/?slug=' . $a_clean_slug . '&key=' . urlencode( self::$settings[ 'addon_licenses' ][ 'wp-slimstat-' . $a_clean_slug ] ), dirname( dirname( __FILE__ ) ) . '/wp-slimstat-' . $a_clean_slug . '/index.php', 'wp-slimstat-' . $a_clean_slug );

				add_filter( "plugin_action_links_wp-slimstat-{$a_clean_slug}/index.php", array( __CLASS__, 'add_plugin_manual_download_link' ), 10, 2 );
			}
		}
	}

	public static function add_plugin_manual_download_link( $_links = array(), $_plugin_file = '' ) {
		$a_clean_slug = str_replace( array( 'wp-slimstat-', '/index.php' ), array( '', '' ), $_plugin_file );

		if ( false !== ( $download_url = get_transient( 'wp-slimstat-download-link-' . $a_clean_slug ) ) ) {
			$_links[] = '<a href="' . $download_url . '">Download ZIP</a>';
		}
		else {
			$url = 'http://www.wp-slimstat.com/update-checker/?slug=' . $a_clean_slug . '&key=' . urlencode( self::$settings[ 'addon_licenses' ][ 'wp-slimstat-' . $a_clean_slug ] );
			$response = wp_safe_remote_get( $url, array( 'timeout' => 300, 'user-agent'  => 'Slimstat Analytics/' . self::$version . '; ' . home_url() ) );

			if ( !is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
				$data = @json_decode( $response[ 'body' ] );

				if ( is_object( $data ) ) {
					$_links[] = '<a href="' . $data->download_url . '">Download ZIP</a>';
					set_transient( 'wp-slimstat-download-link-' . $a_clean_slug, $data->download_url, 172800 ); // 48 hours
				}
			}
		}

		return $_links;
	}

	public static function register_widget() {
		return register_widget( "slimstat_widget" );
	}
}
// end of class declaration

class slimstat_widget extends WP_Widget {

	/**
	 * Sets up the widgets name etc
	 */
	public function __construct() {
		parent::__construct( 'slimstat_widget', 'SlimStat', array( 
			'classname' => 'slimstat_widget',
			'description' => 'Add a SlimStat report to your sidebar',
		) );
	}

	/**
	 * Outputs the content of the widget
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {
		extract( $instance );

		$slimstat_widget_filters = empty( $slimstat_widget_filters ) ? '' : $slimstat_widget_filters;

		if ( !empty( $slimstat_widget_id ) ) {
			echo do_shortcode( "[slimstat f='widget' w='{$slimstat_widget_id}']{$slimstat_widget_filters}[/slimstat]" );
		}
		else {
			echo '';
		}
	}

	/**
	 * Outputs the options form on admin
	 *
	 * @param array $instance The widget options
	 */
	public function form( $instance ) {
		// Let's build the dropdown
		include_once( dirname(__FILE__) . '/admin/view/wp-slimstat-reports.php' );
		wp_slimstat_reports::init();
		$select_options = '';
		$slimstat_widget_id = !empty( $instance[ 'slimstat_widget_id' ] ) ? $instance[ 'slimstat_widget_id' ] : '';
		$slimstat_widget_filters = !empty( $instance[ 'slimstat_widget_filters' ] ) ? $instance[ 'slimstat_widget_filters' ] : '';

		foreach ( wp_slimstat_reports::$reports_info as $a_report_id => $a_report_info ) {
			$select_options .= "<option value='$a_report_id' " . ( ( $slimstat_widget_id == $a_report_id ) ? 'selected="selected"' : '' ) . ">{$a_report_info[ 'title' ]}</option>";
		}
		?>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'slimstat_widget_id' ) ); ?>">Widget</label> 
		<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'slimstat_widget_id' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'slimstat_widget_id' ) ); ?>">
			<option value="">Select a widget</option>
			<?php echo $select_options ?>
		</select>
		</p>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'slimstat_widget_filters' ) ); ?>"><?php _e( 'Optional filters', 'wp-slimstat' ); ?></label> 
		<a href="https://slimstat.freshdesk.com/solution/articles/5000631833-what-is-the-syntax-of-a-slimstat-shortcode-#slimstat-operators" target="_blank">[?]</a>
		<textarea class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'slimstat_widget_filters' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'slimstat_widget_filters' ) ); ?>"><?php echo trim( strip_tags( $slimstat_widget_filters ) ) ?></textarea>
		</p>
		<?php
	}

	/**
	 * Processing widget options on save
	 *
	 * @param array $new_instance The new options
	 * @param array $old_instance The previous options
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance[ 'slimstat_widget_id' ] = $new_instance[ 'slimstat_widget_id' ];
		$instance[ 'slimstat_widget_filters' ] = $new_instance[ 'slimstat_widget_filters' ];
		return $instance;
	}
}

// Ok, let's go, Sparky!
if ( function_exists( 'add_action' ) ) {
	// Since we use sendBeacon, this function sends raw POST data, which does not populate the $_POST variable automatically
	if ( ( !empty( $_SERVER[ 'HTTP_CONTENT_TYPE' ] ) || !empty( $_SERVER[ 'CONTENT_TYPE' ] ) ) && empty( $_POST ) ) {
		$raw_post_string = file_get_contents( 'php://input' );
		parse_str( $raw_post_string, wp_slimstat::$raw_post_array );
	}
	else if ( !empty( $_POST ) ) {
		wp_slimstat::$raw_post_array = $_POST;
	}

	// Init the Ajax listener
	if ( !empty( wp_slimstat::$raw_post_array[ 'action' ] ) && wp_slimstat::$raw_post_array[ 'action' ] == 'slimtrack' ) {

		// This is needed because admin-ajax.php is reading $_REQUEST to fire the corresponding action
		if ( empty( $_POST[ 'action' ] ) ) {
			$_POST[ 'action' ] = wp_slimstat::$raw_post_array[ 'action' ];
		}

		add_action( 'wp_ajax_nopriv_slimtrack', array( 'wp_slimstat', 'slimtrack_ajax' ) );
		add_action( 'wp_ajax_slimtrack', array( 'wp_slimstat', 'slimtrack_ajax' ) );
	}

	// From the codex: You can't call register_activation_hook() inside a function hooked to the 'plugins_loaded' or 'init' hooks (or any other hook). These hooks are called before the plugin is loaded or activated.
	if ( is_admin() ) {
		include_once( WP_PLUGIN_DIR . '/wp-slimstat/admin/wp-slimstat-admin.php' );
		register_activation_hook( __FILE__, array( 'wp_slimstat_admin', 'init_environment' ) );
		register_deactivation_hook( __FILE__, array( 'wp_slimstat_admin', 'deactivate' ) );
	}

	add_action( 'widgets_init', array( 'wp_slimstat', 'register_widget' ) );

	// Add the appropriate actions
	add_action( 'plugins_loaded', array( 'wp_slimstat', 'init' ), 20 );
}
