<?php
// Avoid direct access
if ( !function_exists( 'add_action' ) ) {
	exit(0);
}

$is_dashboard = empty( $_REQUEST[ 'page' ] ) || $_REQUEST[ 'page' ] != 'slimview1';

// Load the search engines list to mark pageviews accordingly
// Each entry contains the following attributes
// - params: which query string params is associated to the search keyword
// - backlink: format of the URL point to the search engine result page
// - charsets: list of charset used to encode the keywords
//
$search_engines = file_get_contents( plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'vendor/matomo-searchengine.json' );
$search_engines = json_decode( $search_engines, TRUE );
// COMPLETE THIS FEATURE!!


// Available icons
$supported_browser_icons = array( 'Android', 'Anonymouse', 'Baiduspider', 'BlackBerry', 'BingBot', 'CFNetwork', 'Chrome', 'Chromium', 'Default Browser', 'Edge', 'Exabot/BiggerBetter', 'FacebookExternalHit', 'FeedBurner', 'Feedfetcher-Google', 'Firefox', 'Internet Archive', 'Googlebot', 'Google Bot', 'Google Feedfetcher', 'Google Web Preview', 'IE', 'IEMobile', 'iPad', 'iPhone', 'iPod Touch', 'Maxthon', 'Mediapartners-Google', 'Microsoft-WebDAV', 'msnbot', 'Mozilla', 'NewsGatorOnline', 'Netscape', 'Nokia', 'Opera', 'Opera Mini', 'Opera Mobi', 'Pingdom', 'Python', 'PycURL', 'Safari', 'W3C_Validator', 'WordPress', 'Yahoo! Slurp', 'YandexBot' );
$supported_os_icons = array( 'android',' blackberry os', 'cellos', 'chromeos', 'ios', 'iphone osx', 'java', 'linux', 'macos', 'macosx', 'rim os', 'symbianos', 'ubuntu', 'win7', 'win8', 'win8.1', 'win10', 'winphone7', 'winphone7.5', 'winphone8', 'winphone8.1', 'winvista', 'winxp' );
$supported_browser_types = array( __( 'Human', 'wp-slimstat' ), __( 'Bot/Crawler', 'wp-slimstat' ), __( 'Mobile Device', 'wp-slimstat' ), __( 'Syndication Reader', 'wp-slimstat' ) );

$plugin_url = plugins_url( '', dirname( __FILE__ ) );

// Get the data
wp_slimstat_db::$debug_message = '';
$all_results = wp_slimstat_db::get_recent( wp_slimstat_reports::$reports[ 'slim_p7_02' ][ 'callback_args' ] );
$results = array_slice(
	$all_results,
	wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
	wp_slimstat::$settings[ 'number_results_raw_data' ]
);

$count_all_results = count( $all_results );
$count_page_results = count( $results );

// Echo the debug message
echo wp_slimstat_db::$debug_message;

if ( $count_page_results == 0 ) {
	echo '<p class="nodata">' . __( 'No data to display', 'wp-slimstat' ) . '</p>';
	return 0;
}

// Return the results if we are not echoing them (export, email, etc)
if ( isset( $_args[ 'echo' ] ) && $_args[ 'echo' ] === false ) {

	// Process the data before returning it
	if ( wp_slimstat::$settings[ 'convert_ip_addresses' ] == 'on' ) {
		for ( $i = 0; $i < $count_page_results; $i++ ) {
			// When the IP conversion feature is enabled, we need to return the correct values
			$hostname = wp_slimstat::gethostbyaddr( $results[ $i ][ 'ip' ] );

			if ( $hostname != $results[ $i ][ 'ip' ] ) {
				$results[ $i ][ 'ip' ] .= ', ' . $hostname;
			}
		}
	}

	return $results;
}

// Pagination
echo wp_slimstat_reports::report_pagination( $count_page_results, $count_all_results, !$is_dashboard, wp_slimstat::$settings[ 'number_results_raw_data' ] );

// Show delete button? (only those who can access the settings can see it)
$current_user_can_delete = ( current_user_can( wp_slimstat::$settings[ 'capability_can_admin' ] ) && !is_network_admin() );
$delete_row = '';

// Loop through the results
for ( $i=0; $i < $count_page_results; $i++ ) {
	$date_time = "<i class='spaced slimstat-font-clock slimstat-tooltip-trigger' title='".__( 'Date and Time', 'wp-slimstat' )."'></i> " . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $results[ $i ][ 'dt' ], true );

	// Print visit header?
	if ( $i == 0 || $results[ $i - 1 ][ 'visit_id' ] != $results[ $i ][ 'visit_id' ] || $results[ $i - 1 ][ 'ip' ] != $results[ $i ][ 'ip' ] || $results[ $i - 1 ][ 'browser' ] != $results[ $i ][ 'browser' ] || $results[ $i - 1 ][ 'platform' ] != $results[ $i ][ 'platform' ] || $results[ $i - 1 ][ 'username' ] != $results[ $i ][ 'username' ] ) {

		// Color-coded headers
		$sek = wp_slimstat::get_lossy_url( parse_url( $results[ $i ][ 'referer' ], PHP_URL_HOST ) );
		$highlight_row = !empty( $search_engines[ $sek ] ) ? ' is-search-engine' : ( ( $results[ $i ][ 'browser_type' ] != 1 ) ? ' is-direct' : '' );

		// Country
		if ( !empty( $results[ $i ][ 'country' ] ) && $results[ $i ][ 'country' ] != 'xx' ) {
			$country_filter = "<a class='slimstat-filter-link inline-icon' href='" . wp_slimstat_reports::fs_url( 'country equals ' . $results[ $i ][ 'country' ] ) . "'><img class='slimstat-tooltip-trigger' src='$plugin_url/assets/images/flags/{$results[ $i ][ 'country' ]}.png' width='16' height='16' title='" . wp_slimstat_i18n::get_string( 'c-' . $results[ $i ][ 'country' ] ) . "'></a>";
		}
		else {
			$country_filter = "<a class='slimstat-filter-link inline-icon' href='" . wp_slimstat_reports::fs_url( 'country is_empty #' ) . "'><img class='slimstat-tooltip-trigger' src='$plugin_url/assets/images/flags/xx.png' width='16' height='16' title='" . wp_slimstat_i18n::get_string( 'c-' ) . "'></a>";
		}

		// City, if tracked
		$city_filter = '';
		if ( !empty( $results[ $i ][ 'city' ] ) ) {
			$city_filter = "<a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'city equals ' . $results[ $i ][ 'city' ] ) . "'>{$results[ $i ][ 'city' ]}</a>";
		}

		// Browser
		if ( empty( $results[ $i ][ 'browser_version' ] ) ) {
			$results[ $i ][ 'browser_version' ] = '';
		}
		$browser_title = ( wp_slimstat::$settings[ 'show_complete_user_agent_tooltip' ] != 'on' ) ? "{$results[ $i ][ 'browser' ]} {$results[ $i ][ 'browser_version' ]}" : $results[ $i ][ 'user_agent' ];
		$browser_filter = 'default-browser';
		if ( !empty( $results[ $i ][ 'browser' ] ) && in_array( $results[ $i ][ 'browser' ], $supported_browser_icons ) ) {
			$browser_filter = sanitize_title( $results[ $i ][ 'browser' ] );
		}
		$browser_filter = "<a class='slimstat-filter-link inline-icon' href='" . wp_slimstat_reports::fs_url( 'browser equals ' . $results[ $i ][ 'browser' ] ) . "'><img class='slimstat-tooltip-trigger' src='$plugin_url/assets/images/browsers/{$browser_filter}.png' width='16' height='16' title='{$browser_title}'></a>";

		// Operating System
		$platform_filter = 'unknown';
		if ( !empty( $results[ $i ][ 'platform' ] ) && in_array( $results[ $i ][ 'platform' ], $supported_os_icons ) ) {
			$platform_filter = esc_attr( $results[ $i ][ 'platform' ] );
		}
		$platform_filter = "<a class='slimstat-filter-link inline-icon' href='" . wp_slimstat_reports::fs_url( 'platform equals ' . $results[ $i ][ 'platform' ] ) . "'><img class='slimstat-tooltip-trigger' src='$plugin_url/assets/images/platforms/{$platform_filter}.png' width='16' height='16' title='" . wp_slimstat_i18n::get_string( $results[ $i ][ 'platform' ] ) . "'></a>";

		// Language
		$language_filter = '';
		// if ( !empty( $results[ $i ][ 'language' ] ) && $results[ $i ][ 'language' ] != 'xx' ) {
		// 	$language_filter = '<span class="pageview-language"><a class="slimstat-filter-link" href="' . wp_slimstat_reports::fs_url( 'language equals ' . $results[ $i ][ 'language' ] ) . '">' . wp_slimstat_i18n::get_string( 'l-' . $results[ $i ][ 'language' ] ) . '</a>';
		// }

		// Browser Type
		$browser_type_filter = '';
		if ( $results[ $i ][ 'browser_type' ] != 0 ) {
			$browser_type_filter = "<a class='slimstat-filter-link inline-icon' href='" . wp_slimstat_reports::fs_url( 'browser_type equals ' . $results[ $i ][ 'browser_type' ] ) . "'><img class='slimstat-tooltip-trigger' src='$plugin_url/assets/images/browsers/type{$results[ $i ][ 'browser_type' ]}.png' width='16' height='16' title='{$supported_browser_types[ $results[ $i ][ 'browser_type' ] ]}'></a>";
		}

		// IP Address and user
		$host_by_ip = $results[ $i ][ 'ip' ];
		if ( wp_slimstat::$settings[ 'convert_ip_addresses' ] == 'on' ) {
			// When the IP conversion feature is enabled, we need to return the correct values
			$host_by_ip = wp_slimstat::gethostbyaddr( $results[ $i ][ 'ip' ] );
		}

		if ( empty( $results[ $i ][ 'username' ] ) ) {
			$ip_address = "<a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'ip equals ' . $results[ $i ][ 'ip' ] ) . "'>$host_by_ip</a>";
		}
		else {
			$display_user_name = $results[ $i ][ 'username' ];
			if ( wp_slimstat::$settings[ 'show_display_name' ] == 'on' && strpos( $results[ $i ][ 'notes' ], 'user:' ) !== false ) {
				$display_real_name = get_user_by( 'login', $results[ $i ][ 'username' ] );
				if ( is_object( $display_real_name ) ) {
					$display_user_name = $display_real_name->display_name;
				}
			}
			$ip_address = "<a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'username equals ' . $results[ $i ][ 'username' ] ) . "'>{$display_user_name}</a>";
			$ip_address .= " <a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'ip equals ' . $results[ $i ][ 'ip' ] ) . "'>($host_by_ip)</a>";
			$highlight_row = ( strpos( $results[ $i ][ 'notes' ], 'user:' ) !== false ) ? ' is-known-user' : ' is-known-visitor';
		}

		$whois_pin = '';
		if ( is_admin() && !empty( wp_slimstat::$settings[ 'ip_lookup_service' ] ) && !wp_slimstat::is_local_ip_address( $results[ $i ][ 'ip' ] ) ) {
			$whois_pin = "<a class='slimstat-font-location-1 whois' href='" . wp_slimstat::$settings[ 'ip_lookup_service' ] . "{$results[ $i ][ 'ip' ]}' target='_blank' title='WHOIS: {$results[ $i ][ 'ip' ]}'></a>";
		}

		// Originating IP Address
		$other_ip_address = '';
		if ( !empty( $results[ $i ][ 'other_ip' ] ) ) {
			$other_ip_address = "(<a class='slimstat-font-location-1 whois' href='" . wp_slimstat::$settings[ 'ip_lookup_service' ] . "{$results[ $i ][ 'other_ip' ]}' target='_blank' title='WHOIS: {$results[ $i ][ 'other_ip' ]}'></a> <a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'other_ip equals '. $results[ $i ][ 'other_ip' ] ) . "'>" . __( 'Originating IP', 'wp-slimstat' ) . ": {$results[$i]['other_ip']})</a>";
		}

		// Screen Resolution
		$screen_resolution = '';
		if ( !empty( $results[ $i ][ 'screen_width' ] ) && !empty( $results[ $i ][ 'screen_height' ] ) ) {
			$screen_resolution = "<span class='pageview-screenres'>{$results[ $i ][ 'screen_width' ]}x{$results[ $i ][ 'screen_height' ]}</span>";
		}

		// Fingerprint
		$fingerprint = '';
		if ( !$is_dashboard && !empty( $results[ $i ][ 'fingerprint' ] ) ) {
			$fingerprint = "<span class='pageview-screenres'><code><a class='slimstat-filter-link slimstat-tooltip-trigger' href='" . wp_slimstat_reports::fs_url( 'fingerprint equals ' . $results[ $i ][ 'fingerprint' ] ) . "' title='" . $results[ $i ][ 'fingerprint' ] . "'>" . substr( $results[ $i ][ 'fingerprint' ], 0, 8 ) . "</a></code></span>";
		}

		$row_output = "<p class='header$highlight_row'>$browser_filter $platform_filter $browser_type_filter $country_filter $whois_pin $city_filter $ip_address $other_ip_address $fingerprint $screen_resolution $language_filter</p>";

		// Strip all the filter links, if this information is shown on the frontend
		if ( !is_admin() ) {
			$row_output = preg_replace('/<a (.*?)>(.*?)<\/a>/', "\\2", $row_output);
		}

		echo $row_output;
	}

	// Permalink: find post title, if available
	if ( !empty( $results[ $i ][ 'resource' ] ) ) {
		if ( isset( $results[ $i ][ 'blog_id' ] ) ) {
			$results[ $i ][ 'resource' ] = get_site_url( $results[ $i ][ 'blog_id' ] ) . $results[ $i ][ 'resource' ];
			$resource_title = $results[ $i ][ 'resource' ];
		}
		else {
			$resource_title = wp_slimstat_reports::get_resource_title( $results[$i][ 'resource' ] );
		}

		$results[ $i ][ 'resource' ] = "<a class='slimstat-font-logout slimstat-tooltip-trigger' target='_blank' title='" . htmlentities( __( 'Open this URL in a new window', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . "' href='" . htmlentities( $results[$i][ 'resource' ], ENT_QUOTES, 'UTF-8' ) . "'></a> <a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'resource equals ' . $results[ $i ][ 'resource' ] ) . "'>" . $resource_title . '</a>';
	}
	else {
		if ( !empty( $results[$i][ 'notes' ] ) ) {
			$exploded_notes = explode( '][', substr( $results[ $i ][ 'notes' ], 1, -1 ) );

			foreach ( $exploded_notes as $a_note ) {
				if ( strpos( $a_note, 'results:') !== false ) {
					$search_terms_info = $results[ $i ][ 'searchterms' ] . ' (' . $a_note . ')';
					break;
				}
			}
		}
		$results[$i]['resource'] = __( 'Local search results page', 'wp-slimstat' );
	}

	if ( empty( $search_terms_info ) ) {
		$search_terms_info = wp_slimstat_reports::get_search_terms_info( $results[ $i ][ 'searchterms' ], $results[ $i ][ 'referer' ] );
	}

	// Search Terms, with link to original SERP, and Outbound Resource
	if ( !empty( $search_terms_info ) ) {
		$results[$i]['searchterms'] = "<i class='spaced slimstat-font-search' title='" . __( 'Search Terms', 'wp-slimstat' ) . "'></i> $search_terms_info";
	}
	else {
		$results[$i]['searchterms'] = '';
	}

	// Let's reset this variable for the next item
	$search_terms_info = '';

	// Server Latency and Page Speed
	$performance = '';
	if ( !$is_dashboard && ( !empty( $results[ $i ][ 'server_latency' ] ) || !empty( $results[ $i ][ 'page_performance' ] ) ) ) {
		$performance = "<i class='slimstat-font-gauge spaced slimstat-tooltip-trigger' title='".__('Server Latency and Page Speed in milliseconds','wp-slimstat')."'></i> ".__('SL','wp-slimstat').": {$results[$i]['server_latency']} / ".__('PS','wp-slimstat').": {$results[$i]['page_performance']}";
	}

	// Time on page
	$time_on_page = '';
	if ( !$is_dashboard && !empty( $results[ $i ][ 'dt_out' ] ) ) {
		$duration = $results[ $i ][ 'dt_out' ] - $results[ $i ][ 'dt' ];
		$time_on_page = "<i class='slimstat-font-stopwatch spaced slimstat-tooltip-trigger' title='" . __( 'Time spent on this page', 'wp-slimstat' ) . "'></i> " . date( ( $duration > 3599 ? 'H:i:s' : 'i:s' ), $duration );
	}

	// Pageview Notes
	$notes = '';
	if ( is_admin() && !empty( $results[ $i ][ 'notes' ] ) ) {
		$notes = str_replace( array( '][', ':', '[', ']' ), array( '<br/>', ': ', '', '' ), $results[ $i ][ 'notes' ] );
		$notes = "<i class='slimstat-font-edit slimstat-tooltip-trigger'><b class='slimstat-tooltip-content'>{$notes}</b></i>";
	}

	// Avoid XSS attacks through the referer URL
	$results[ $i ] [ 'referer' ] = str_replace( array( '<', '>', '%22', '%27', '\'', '"', '%3C', '%3E' ), array( '&lt;', '&gt;', '', '', '', '', '&lt;', '&gt;' ), urldecode( $results[ $i ] [ 'referer' ] ) );

	$login_logout = '';
	if ( !$is_dashboard ) {
		$domain = parse_url( $results[ $i ] [ 'referer' ] );
		$domain = !empty( $domain[ 'host' ] ) ? $domain[ 'host' ] : __( 'Invalid Referrer', 'wp-slimstat' );
		$results[$i][ 'referer' ] = (!empty($results[$i]['referer']) && empty($results[$i]['searchterms']))?"<a class='spaced slimstat-font-login slimstat-tooltip-trigger' target='_blank' title='".htmlentities(__('Open this referrer in a new window','wp-slimstat'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['referer']}'></a> $domain":'';
		$results[$i][ 'content_type' ] = !empty($results[$i]['content_type'])?"<i class='spaced slimstat-font-doc slimstat-tooltip-trigger' title='".__('Content Type','wp-slimstat')."'></i> <a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('content_type equals '.$results[$i]['content_type'])."'>{$results[$i]['content_type']}</a> ":'';

		// The Outbound Links field might contain more than one link
		if ( !empty( $results[ $i ][ 'outbound_resource' ] ) ) {
			if ( substr( $results[ $i ][ 'outbound_resource' ], 0, 1 ) != '#' ) {
				$results[ $i ][ 'outbound_resource' ] = "<a class='inline-icon spaced slimstat-font-logout slimstat-tooltip-trigger' target='_blank' title='" . htmlentities( __( 'Open this outbound link in a new window', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . "' href='{$results[ $i ][ 'outbound_resource' ]}'></a> {$results[ $i ][ 'outbound_resource' ]}";
			}
			else {
				$results[ $i ][ 'outbound_resource' ] = "<i class='inline-icon spaced slimstat-font-logout'></i> {$results[ $i ][ 'outbound_resource' ]}";
			}
		}
		else {
			$results[$i][ 'outbound_resource' ] = '';
		}

		if ( $current_user_can_delete ){
			$delete_row = "<a class='slimstat-delete-entry slimstat-font-cancel slimstat-tooltip-trigger' data-pageview-id='{$results[$i]['id']}' title='".htmlentities(__('Delete this entry from the database','wp-slimstat'), ENT_QUOTES, 'UTF-8')."' href='#'></a>";
		}

		// Login / Logout Event
		$login_logout = '';
		if ( strpos( $results[ $i ][ 'notes' ], 'loggedin:' ) !== false ) {
			$exploded_notes = explode( ';', $results[ $i ][ 'notes' ] );
			foreach ( $exploded_notes as $a_note ) {
				if ( strpos( $a_note, 'loggedin:' ) === false ) {
					continue;
				}

				$login_logout .= "<i class='slimstat-font-user-plus spaced slimstat-tooltip-trigger' title='" . __( 'User Logged In', 'wp-slimstat' ) . "'></i> " . str_replace( 'loggedin:', '', $a_note );
			}
		}
		if ( strpos( $results[ $i ][ 'notes' ], 'loggedout:' ) !== false ) {
			$exploded_notes = explode( ';', $results[ $i ][ 'notes' ] );
			foreach ( $exploded_notes as $a_note ) {
				if ( strpos( $a_note, 'loggedout:' ) === false ) {
					continue;
				}

				$login_logout .= "<i class='slimstat-font-user-times spaced slimstat-tooltip-trigger' title='" . __( 'User Logged Out', 'wp-slimstat' ) . "'></i> " . str_replace( 'loggedout:', '', $a_note );
			}
		}
	}
	else {
		$results[$i]['referer'] = $results[$i][ 'outbound_resource' ] = $results[$i][ 'content_type' ] = '';
	}

	$row_output = "<p>{$results[$i]['resource']} <span class='details'>$time_on_page $login_logout {$results[$i]['searchterms']} {$results[$i]['referer']} {$results[$i]['outbound_resource']} {$results[$i]['content_type']} $performance $date_time {$notes} {$delete_row}</span></p>";

	// Strip all the filter links, if this information is shown on the frontend
	if ( !is_admin() ) {
		$row_output = preg_replace('/<a (.*?)>(.*?)<\/a>/', "\\2", $row_output);
	}

	echo $row_output;
}

// Pagination
if ( $count_page_results > 20 ) {
	echo wp_slimstat_reports::report_pagination( $count_page_results, $count_all_results, true, wp_slimstat::$settings[ 'number_results_raw_data' ] );
}

?>

<script type="text/javascript">
var slimstat_refresh_timer = 0;

function slimstat_refresh_countdown() {
	slimstat_refresh_timer--;
	minutes = parseInt( slimstat_refresh_timer / 60 );
	seconds = parseInt( slimstat_refresh_timer % 60 );

	jQuery( '.refresh-timer' ).html( minutes + ':' + ( ( seconds < 10 ) ? '0' : '' ) + seconds );

	if ( slimstat_refresh_timer == 0 ) {
		// Request the data from the server
		refresh = SlimStatAdmin.refresh_report( 'slim_p7_02' );
		refresh();

		// Reset the countdown timer
		slimstat_refresh_timer = parseInt( SlimStatAdminParams.refresh_interval );
	}
}

if ( jQuery( '.refresh-timer' ).length > 0 && typeof SlimStatAdminParams.refresh_interval != 'undefined' ) {
	slimstat_refresh_timer = SlimStatAdminParams.refresh_interval;
	SlimStatAdmin.refresh_handle = window.setInterval( slimstat_refresh_countdown, 1000 );
}
</script>