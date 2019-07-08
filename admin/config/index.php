<?php

// Avoid direct access to this piece of code
if ( !function_exists( 'add_action' ) ) {
	exit(0);
}

if ( isset( $_POST[ 'options' ][ 'ignore_capabilities' ] ) ) {
	// Make sure all the capabilities exist in the system 
	$capability_not_found = false;
	foreach( wp_slimstat::string_to_array( $_POST[ 'options' ][ 'ignore_capabilities' ] ) as $a_capability ) {
		if ( isset( $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) && !array_key_exists( $a_capability, $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) ) {
			$capability_not_found = true;
			break;
		}
	}

	if ( !$capability_not_found ) {
		wp_slimstat::$settings[ 'ignore_capabilities' ] = $_POST[ 'options' ][ 'ignore_capabilities' ];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __( 'Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
	}
}

if ( isset( $_POST[ 'options' ][ 'can_view' ] ) ) {
	// Make sure all the users exist in the system 
	$post_data = trim( $_POST[ 'options' ][ 'can_view' ] );
	$user_array = wp_slimstat::string_to_array( $_POST[ 'options' ][ 'can_view' ] );

	if ( !empty( $post_data ) ) {
		$sql_user_placeholders = implode( ', ', array_fill( 0, count( $user_array ), '%s' ) );
		if ( $GLOBALS[ 'wpdb' ]->get_var( $GLOBALS[ 'wpdb' ]->prepare( "SELECT COUNT( * ) FROM {$GLOBALS[ 'wpdb' ]->users} WHERE user_login IN ( $sql_user_placeholders )", $user_array ) ) == count( $user_array ) ) {
			wp_slimstat::$settings[ 'can_view' ] = $_POST[ 'options' ][ 'can_view' ];
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __( '[Reports] Username not found', 'wp-slimstat' );
		}
	}
	else {
		wp_slimstat::$settings[ 'can_view' ] = '';
	}
}

if ( isset( $_POST[ 'options' ][ 'capability_can_view' ] ) ) {
	if ( isset( $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) && array_key_exists( $_POST[ 'options' ][ 'capability_can_view' ], $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) ) {
		wp_slimstat::$settings[ 'capability_can_view' ] = $_POST[ 'options' ][ 'capability_can_view' ];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __( '[Reports] Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
	}
}

if ( isset( $_POST[ 'options' ][ 'can_customize' ] ) ) {
	// Make sure all the users exist in the system
	$post_data = trim( $_POST[ 'options' ][ 'can_customize' ] );
	$user_array = wp_slimstat::string_to_array( $_POST[ 'options' ][ 'can_customize' ] );

	if ( is_array( $user_array ) && !empty( $post_data ) ) {
		$sql_user_placeholders = implode( ', ', array_fill( 0, count( $user_array ), '%s' ) );
		if ( $GLOBALS[ 'wpdb' ]->get_var( $GLOBALS[ 'wpdb' ]->prepare( "SELECT COUNT( * ) FROM {$GLOBALS[ 'wpdb' ]->users} WHERE user_login IN ( $sql_user_placeholders )", $user_array ) ) == count( $user_array ) ) {
			wp_slimstat::$settings[ 'can_customize' ] = $_POST[ 'options' ][ 'can_customize' ];
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __( '[Customize] Username not found', 'wp-slimstat' );
		}
	}
}

if ( isset( $_POST[ 'options' ][ 'capability_can_customize' ] ) ) {
	if ( isset( $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) && array_key_exists( $_POST[ 'options' ][ 'capability_can_customize' ], $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) ) {
		wp_slimstat::$settings[ 'capability_can_customize' ] = $_POST[ 'options' ][ 'capability_can_customize' ];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __( '[Customize] Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
	}
}

if ( isset( $_POST[ 'options' ][ 'can_admin' ] ) ) {
	// Make sure all the users exist in the system
	$post_data = trim( $_POST[ 'options' ][ 'can_admin' ] );
	$user_array = wp_slimstat::string_to_array( $_POST[ 'options' ][ 'can_admin' ] );

	if ( is_array( $user_array ) && !empty( $post_data ) ) {
		$sql_user_placeholders = implode( ', ', array_fill( 0, count( $user_array ), '%s' ) );
		if ( $GLOBALS[ 'wpdb' ]->get_var( $GLOBALS[ 'wpdb' ]->prepare( "SELECT COUNT( * ) FROM {$GLOBALS[ 'wpdb' ]->users} WHERE user_login IN ( $sql_user_placeholders )", $user_array ) ) == count( $user_array ) ) {
			wp_slimstat::$settings[ 'can_admin' ] = $_POST[ 'options' ][ 'can_admin' ];
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __( '[Settings] Username not found', 'wp-slimstat' );
		}
	}
}

if ( isset( $_POST[ 'options' ][ 'capability_can_admin' ] ) ) {
	if ( isset( $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) && array_key_exists( $_POST[ 'options' ][ 'capability_can_admin' ], $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) ) {
		wp_slimstat::$settings[ 'capability_can_admin' ] = $_POST[ 'options' ][ 'capability_can_admin' ];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __( '[Settings] Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
	}
}

$current_tab = empty( $_GET[ 'tab' ] ) ? 1 : intval( $_GET[ 'tab' ] );

// Define all the options
$settings = array(
	1 => array(
		'title' => __( 'General', 'wp-slimstat' ),
		'rows' => array(
			'general_tracking_header' => array( 'description' => __( 'Tracker', 'wp-slimstat' ), 'type' => 'section_header' ),
			'is_tracking' => array( 'description' => __( 'Enable Tracking', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Turn the tracker on or off, while keeping the reports accessible.', 'wp-slimstat' ) ),
			'track_admin_pages' => array( 'description' => __( 'Track Backend', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Enable this option to track your users' activity within the WordPress admin.", 'wp-slimstat') ),
			'javascript_mode' => array( 'description' => __( 'Tracking Mode', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Select <strong>Client</strong> if you are using a caching plugin (W3 Total Cache, WP SuperCache, HyperCache, etc). Slimstat will behave pretty much like Google Analytics, and visitors whose browser doesn't support Javascript will be ignored. A nice side effect is that <strong>most spammers, search engines and other crawlers</strong> will not be tracked. Select <strong>Server</strong> if you are not using a caching tool on your website, and would like to track <em>all</em> traffic.", 'wp-slimstat' ), 'custom_label_on' => __( 'Client', 'wp-slimstat' ), 'custom_label_off' => __( 'Server', 'wp-slimstat' ) ),

			'general_integration_header' => array( 'description' => __( 'WordPress Integration', 'wp-slimstat' ), 'type' => 'section_header' ),
			'add_dashboard_widgets' => array( 'description' => __( 'Dashboard Widgets', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option if you want to add reports to your WordPress Dashboard. Use the Customizer to choose which ones to display.', 'wp-slimstat' ) ),
			'use_separate_menu' => array( 'description' => __( 'Use Admin Bar', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Choose if you want to display the Slimstat menu in the sidebar or as a drop down in the admin bar (if visible).', 'wp-slimstat' ) ),
			'add_posts_column' => array( 'description' => __( 'Posts and Pages', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Add a new column to the Edit Posts/Pages screens, which will contain the hit count or unique visits per post. You can customize the default timeframe in Settings > Reports > Report Interval.', 'wp-slimstat' ) ),
			'posts_column_pageviews' => array( 'description' => __( 'Report Type','wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Customize the information displayed when activating the option here above: <strong>hits</strong> refers to the total amount of pageviews, regardless of the user; <strong>(unique) IPs</strong> displays the amount of distinct IP addresses tracked in the given time range.', 'wp-slimstat' ), 'custom_label_on' => __( 'Hits', 'wp-slimstat' ), 'custom_label_off' => __( 'IPs', 'wp-slimstat' ) ),
			'hide_addons' => array( 'description' => __( 'Hide Add-ons', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'If you are using our premium add-ons, you can enable this option to hide all the <strong>active</strong> ones from the list of plugins in WordPress. Please note that you will still be notified of new updates available for any given hidden add-on.', 'wp-slimstat' ) ),

			'general_database_header' => array( 'description' => __( 'Database', 'wp-slimstat' ), 'type' => 'section_header' ),
			'auto_purge' => array( 'description' => __( 'Data Retention', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( "Enable a daily cron job to erase or archive (see option here below) pageviews older than the number of days specified here. You can enter <strong>0</strong> (the number zero) if you want to disable this feature.", 'wp-slimstat' ), 'after_input_field' => __( 'days', 'wp-slimstat' ) ),
			'auto_purge_delete' => array( 'description' => __( 'Archive Records', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'If server space is not an issue for you, use this option to archive pageviews to a separate table, instead of deleting them. This will increase performance by reducing the amount of data to process in the main table, while allowing you to access your data at a later time, if needed. Please note that the archive table (<strong>wp_slim_stats_archive</strong>) will be <strong>DELETED</strong> along with all the other tables, when you uninstall Slimstat. Make sure to backup your data before you proceed.', 'wp-slimstat' ) )
		)
	),

	2 => array(
		'title' => __( 'Tracker', 'wp-slimstat' ),
		'rows' => array(
			'privacy_header' => array( 'description' => __( 'Data Protection', 'wp-slimstat' ), 'type' => 'section_header' ),
			'anonymize_ip' => array( 'description' => __( 'Privacy Mode', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Mask your visitors' IP addresses to comply with European privacy laws. This feature will convert the last octet into a ZERO.", 'wp-slimstat' ) ),
			'set_tracker_cookie' => array( 'description' => __( 'Set Cookie', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Disable this option if, for legal or security reasons, you do not want Slimstat to assign a <a href="https://en.wikipedia.org/wiki/HTTP_cookie" target="_blank">cookie</a> to your visitors. Please note that by deactivating this feature, Slimstat will not be able to identify returning visitors as such.', 'wp-slimstat' ) ),
			'display_opt_out' => array( 'description' => __( 'Allow Opt-out', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "The European <a href='https://en.wikipedia.org/wiki/General_Data_Protection_Regulation' target='_blank'>General Data Protection Regulation (GDPR)</a> requires website owners to provide a way for their visitors to opt-out of tracking. By enabling this option, the message here below will be displayed to all users who don't have the corresponding cookie set.", 'wp-slimstat' ) ),
			'opt_out_cookie_names' => array( 'description' => __( 'Opt-out Cookies', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "If you are already using another tool to monitor which users opt-out of tracking, and assuming that this tool sets its own cookie to remember their selection, you can enter the cookie names and values in this field to let Slimstat comply with their choice. Please use the following format: <code>cookie_name=value</code>. Slimstat will track any visitor who sends a cookie that <strong>does not</strong> have that value. Separate multiple duplets with commas.", 'wp-slimstat' ) ),
			'opt_in_cookie_names' => array( 'description' => __( 'Opt-in Cookies', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Similarly to the option here above, you can configure Slimstat to work with an opt-in mechanism. Please use the following format: <code>cookie_name=value</code>. Slimstat will only track visitors who send a cookie that <strong>has</strong> that value. Separate multiple duplets with commas.", 'wp-slimstat' ) ),
			'opt_out_message' => array( 'description' => __( 'Opt-out Message', 'wp-slimstat' ), 'type' => 'textarea', 'rows' => 4, 'long_description' => __( "Customize the message displayed to your visitors here below. Match your website styles and layout by adding the appropriate HTML markup to your message.", 'wp-slimstat' ), 'use_tag_list' => false, 'use_code_editor' => 'htmlmixed' ),

			'filters_outbound_header' => array( 'description' => __( 'Link Tracking', 'wp-slimstat' ), 'type' => 'section_header' ),
			'track_same_domain_referers' => array( 'description' => __( 'Same-Domain Referrers', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "By default, when a referrer's domain's pageview is the same as the current site, that information is not saved in the database. However, if you are running a multisite network with subfolders, you might need to enable this option to track same-domain referrers from one site to another, as they are technically 'independent' websites.", 'wp-slimstat' ) ),
			'do_not_track_outbound_classes_rel_href' => array( 'description' => __( 'Class Names', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Do not track links whose class names, <em>rel</em> attributes or <em>href</em> attribute contain one of the following strings. Please keep in mind that the class <code>noslimstat</code> is used to avoid tracking interactive links throughout the reports. If you remove it from this list, some features might not work as expected.", 'wp-slimstat' ) ),
			'extensions_to_track' => array( 'description' => __( 'Downloads', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'List all the file extensions that you want to be identified as Downloads. Please note that links pointing to external resources (i.e. PDFs on an external website) will be tracked as Downloads and not Outbound Links, if they match one of the extensions listed here below.', 'wp-slimstat' ) ),

			'advanced_tracker_header' => array( 'description' => __( 'Advanced Options', 'wp-slimstat' ), 'type' => 'section_header' ),
			'geolocation_country' => array( 'description' => __( 'Geolocation Precision', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Slimstat determines your visitors' Country of origin through a third-party data file <a href='https://dev.maxmind.com/geoip/geoip2/geolite2/' target='_blank'>distributed by MaxMind</a>. This information is available in two precision levels: country and city. By default, Slimstat will install the country precision level. Use this option to switch to the more granular level, if you don't mind its 60 Mb average size. After updating this option, please <strong>go to the Maintenance tab</strong> and uninstall/install the MaxMind GeoLite DB file by clicking on the corresponding button.", 'wp-slimstat' ), 'custom_label_on' => __( 'Country', 'wp-slimstat' ), 'custom_label_off' => __( 'City', 'wp-slimstat' ) ),
			'session_duration' => array( 'description' => __( 'Visit Duration', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'How many seconds should a human visit last? Google Analytics sets it to 1800 seconds.', 'wp-slimstat' ), 'after_input_field' => __( 'seconds', 'wp-slimstat' ) ),
			'extend_session' => array( 'description' => __( 'Extend Duration', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Reset your visitors' visit duration every time they access a new page within the current visit.", 'wp-slimstat' ) ),
			'enable_cdn' => array( 'description' => __( 'Enable CDN', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Use <a href='https://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving our tracking code from their fast and reliable network (free service).", 'wp-slimstat' ) ),
			'ajax_relative_path' => array( 'description' => __( 'Relative Ajax', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Try enabling this option if you are experiencing issues related to the header field X-Requested-With not being allowed by Access-Control-Allow-Headers in preflight response (or similar).', 'wp-slimstat' ) ),

			'advanced_external_pages_header' => array( 'description' => __( 'External Pages', 'wp-slimstat' ), 'type' => 'section_header' ),
			'external_pages_script' => array( 'type' => 'static', 'skip_update' => 'yes', 'description' => __( 'Add the following code to all the non-WordPress pages you would like to track, right before the closing BODY tag. Please make sure to change the protocol of all the URLs to HTTPS, if you external site is using a secure channel.', 'wp-slimstat' ), 'long_description' => '&lt;script type="text/javascript"&gt;
/* &lt;![CDATA[ */
var SlimStatParams = {
	ajaxurl: "'.admin_url('admin-ajax.php').'",
	ci: "YTo0OntzOjEyOiJjb250ZW50X3R5cGUiO3M6ODoiZXh0ZXJuYWwiO3M6ODoiY2F0ZWdvcnkiO3M6MDoiIjtzOjEwOiJjb250ZW50X2lkIjtpOjA7czo2OiJhdXRob3IiO3M6MTM6ImV4dGVybmFsLXBhZ2UiO30=.' . md5('YTo0OntzOjEyOiJjb250ZW50X3R5cGUiO3M6ODoiZXh0ZXJuYWwiO3M6ODoiY2F0ZWdvcnkiO3M6MDoiIjtzOjEwOiJjb250ZW50X2lkIjtpOjA7czo2OiJhdXRob3IiO3M6MTM6ImV4dGVybmFsLXBhZ2UiO30=' . wp_slimstat::$settings[ 'secret' ] ).'",
	extensions_to_track: "'.wp_slimstat::$settings[ 'extensions_to_track' ].'"
};
/* ]]&gt; */
&lt;/script&gt;
&lt;script type="text/javascript" src="https://cdn.jsdelivr.net/wp/wp-slimstat/trunk/wp-slimstat.min.js"&gt;&lt;/script&gt;', 'use_tag_list' => false ),
			'external_domains' => array( 'description' => __( 'Allowed Domains', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "If you are getting an error saying that no 'Access-Control-Allow-Origin' header is present on the requested resource, when using the external tracking code here above, list the domains (complete with scheme) you would like to allow. For example: <code>https://my.domain.ext</code> (no trailing slash). Please see <a href='https://www.w3.org/TR/cors/#security' target='_blank'>this W3 resource</a> for more information on the security implications of allowing CORS requests.", 'wp-slimstat' ) )
		)
	),

	3 => array(
		'title' => __( 'Reports', 'wp-slimstat' ),
		'rows' => array(
			'reports_functionality_header' => array( 'description' => __( 'Functionality', 'wp-slimstat' ), 'type' => 'section_header' ),
			'use_current_month_timespan' => array( 'description' => __( 'Current Month', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Determine what time window to use for the reports. Enable this option to default to the current month, disable it to use the past X number of days (see option here below). Use the date and time filters for a more granular analysis.', 'wp-slimstat' ) ),
			'posts_column_day_interval' => array( 'description' => __( 'Time Range', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Default number of days in the time window used to generate all the reports. We set it to 4 weeks so that the comparison charts will overlap nicely (i.e. Monday over Monday) for a more meaningful analysis. This value is ignored if the option here above is turned on.', 'wp-slimstat' ), 'after_input_field' => __( 'days', 'wp-slimstat' ) ),			
			'async_load' => array( 'description' => __( 'Async Mode', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Activate this feature if your reports take a while to load. It breaks down the load on your server into multiple smaller requests, thus avoiding memory issues and performance problems.', 'wp-slimstat' ) ),
			'rows_to_show' => array('description' => __( 'Rows to Display', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Define the number of rows to display in Top and Recent reports. You can adjust this number to improve your server performance.', 'wp-slimstat' ), 'after_input_field' => __( 'rows', 'wp-slimstat' ) ),
			'limit_results' => array( 'description' => __( 'SQL Limit', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( "You can limit the number of records that each SQL query will take into consideration when crunching aggregate values (maximum, average, etc). You might need to adjust this value if you're getting an error saying that you exceeded your PHP memory limit while accessing the statistics.", 'wp-slimstat' ), 'after_input_field' => __( 'rows', 'wp-slimstat' ) ),
			'ip_lookup_service' => array( 'description' => __( 'IP Geolocation', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Customize the URL of the geolocation service to be used in the Access Log. Default value: <code>https://www.infosniper.net/?ip_address=</code>', 'wp-slimstat' ) ),
			'comparison_chart' => array( 'description' => __( 'Comparison Chart', 'wp-slimstat'), 'type' => 'toggle', 'long_description' => __( "Slimstat displays two sets of charts, allowing you to compare the current time window with the previous one. Disable this option if you find those four charts confusing, and prefer seeing only the selected time range. Please keep in mind that you can always temporarily hide one series by clicking on the corresponding entry in the ", 'wp-slimstat' ) ),
			'show_display_name' => array( 'description' => __( 'Use Display Name', 'wp-slimstat'), 'type' => 'toggle', 'long_description' => __( 'By default, users are listed by their usernames. Enable this option to show their display names instead.', 'wp-slimstat' ) ),
			'convert_resource_urls_to_titles' => array( 'description' => __( 'Use Titles', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'For improved legibility, most reports list post and page titles instead of their permalinks. Use this option to change this behavior.', 'wp-slimstat' ) ),
			'convert_ip_addresses' => array( 'description' => __( 'Show Hostnames', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option to display the hostname associated to each IP address. Please note that this might affect performance, as Slimstat will need to query your DNS server for each address.', 'wp-slimstat' ) ),

			'reports_right_now_header' => array( 'description' => __( 'Access Log and World Map', 'wp-slimstat' ), 'type' => 'section_header' ),
			'refresh_interval' => array( 'description' => __( 'Auto Refresh', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'When a value greater than zero is entered, the Access Log view will refresh every X seconds. Enter <strong>0</strong> (the number zero) if you would like to deactivate this feature.', 'wp-slimstat' ), 'after_input_field' => __( 'seconds', 'wp-slimstat' ) ),
			'number_results_raw_data' => array('description' => __( 'Rows to Display', 'wp-slimstat'), 'type' => 'integer', 'long_description' => __( 'Define the number of rows to visualize in the Access Log.', 'wp-slimstat' ), 'after_input_field' => __( 'rows', 'wp-slimstat' ) ),
			'max_dots_on_map' => array('description' => __( 'Map Data Points', 'wp-slimstat'), 'type' => 'integer', 'long_description' => __( 'Customize the maximum number of data points displayed on the world map. Please note that large numbers might negatively affect rendering times.', 'wp-slimstat' ), 'after_input_field' => __( 'points', 'wp-slimstat' ) ),

			'reports_miscellaneous_header' => array( 'description' => __( 'Miscellaneous', 'wp-slimstat' ), 'type' => 'section_header' ),
			'custom_css' => array( 'description' => __( 'Custom CSS', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter your own stylesheet definitions to customize the way your reports look. <a href='https://slimstat.freshdesk.com/support/solutions/articles/5000528528-how-can-i-change-the-colors-associated-to-color-coded-pageviews-known-user-known-visitors-search-e' target='_blank'>Check our FAQs</a> for more information on how to use this option.", 'wp-slimstat' ), 'use_tag_list' => false, 'use_code_editor' => 'css' ),
			'chart_colors' => array( 'description' => __( 'Chart Colors', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Customize the look and feel of your charts by assigning your own colors to each metric. List four hex colors, in the following order: metric 1 previous, metric 2 previous, metric 1 current, metric 2 current. For example: <code>#ccc, #999, #bbcc44, #21759b</code>.", 'wp-slimstat' ) ),
			'mozcom_access_id' => array('description' => __( 'Mozscape Access ID', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Get accurate rankings for your website through the <a href="https://moz.com/community/join?redirect=/products/api/keys" target="_blank">Mozscape API</a>. Sign up for a free community account to get started. Then enter your personal identification code in this field.', 'wp-slimstat' ) ),
			'mozcom_secret_key' => array('description' => __( 'Mozscape Secret Key', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'This key is needed to query the Mozscape API (see option here above). Treat it like a password and do not share it with anyone, or they will be able to make API requests using your account.', 'wp-slimstat' ) ),
			'show_complete_user_agent_tooltip' => array( 'description' => __( 'Show User Agent', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option if you want to see the full user agent string when hovering over each browser icon in the Access Log and elsewhere.', 'wp-slimstat' ) ),
			'enable_sov' => array( 'description' => __( 'Enable SOV', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'In linguistic typology, a subject-object-verb (SOV) language is one in which the subject, object, and verb of a sentence appear in that order, like in Japanese.', 'wp-slimstat' ) )
		)
	),

	4 => array(
		'title' => __( 'Exclusions', 'wp-slimstat' ),
		'rows' => array(
			'filters_users_header' => array( 'description' => __( 'User Properties', 'wp-slimstat' ), 'type' => 'section_header' ),
			'ignore_wp_users' => array( 'description' => __( 'WP Users', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'If enabled, logged in WordPress users will not be tracked, neither on the website nor in the backend.', 'wp-slimstat' ) ),
			'ignore_spammers' => array( 'description' => __( 'Spammers', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "If enabled, visits from users identified as spammers by third-party tools like Akismet will not be tracked. Pageviews generated by users whose comments are later marked as spam, will also be removed from the database on a daily basis.", 'wp-slimstat' ) ),
			'ignore_bots' => array( 'description' => __( 'Bots', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'This option only applies when Tracking Mode is set to Server. If enabled, pageviews generated by crawlers, spiders, search engine bots, etc will not be tracked. Please note that in Client mode, bots are ignored regardless of this setting.', 'wp-slimstat' ) ),
			'ignore_prefetch' => array( 'description' => __( 'Prefetch Requests', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( '<a href="https://en.wikipedia.org/wiki/Link_prefetching" target="_blank">Link Prefetching</a> is a technique that allows web browsers to pre-load resources, before the user clicks on the corresponding link. If enabled, this kind of requests will not be tracked.', 'wp-slimstat' ) ),
			'ignore_users' => array( 'description' => __( 'Usernames', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of usernames that should not be tracked. Please note that spaces are <em>not</em> ignored and that usernames are case sensitive. See note at the bottom of this page for more information on how to use wildcards.", 'wp-slimstat' ) ),
			'ignore_capabilities' => array( 'description' => __( 'Capabilities', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress capabilities</a>, so that users who have any of them assigned to their role will not be tracked. Please note that although capabilities are case-insensitive, it is recommended to enter them all in lowercase. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat' ), 'skip_update' => true ),
			'ignore_ip' => array( 'description' => __( 'IP Addresses', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of IP addresses that should not be tracked. Each subnet <strong>must</strong> be defined using the <a href='https://www.iplocation.net/subnet-mask' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). This filter applies both to the public IP address and the originating IP address, if available. Using the CIDR notation, you will use octets to determine the mask. For example, 54.0.0.0/8 matches any address that has 54 as the first number; 54.12.0.0/16 matches any address that starts with 54.12, and so on.", 'wp-slimstat' ) ),
			'ignore_countries' => array( 'description' => __( 'Countries', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of lowercase <a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">ISO 3166-1 country codes</a> (i.e.: <code>en-us, it, es</code>) that should not be tracked. Please note: this field does not allow wildcards.', 'wp-slimstat' ) ),
			'ignore_browsers' => array( 'description' => __( 'User Agents', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of browser names that should not be tracked. You can specify the browser's version adding a slash after the name (i.e. <em>Firefox/36</em>). Technically speaking, Slimstat will match your list against the visitor's user agent string. Strings are case-insensitive. See note at the bottom of this page for more information on how to use wildcards.", 'wp-slimstat' ) ),
			'ignore_platforms' => array( 'description' => __( 'Operating Systems', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of operating system codes that should not be tracked. Please refer to <a href="https://slimstat.freshdesk.com/solution/articles/12000031504-what-are-the-operating-system-codes-used-by-slimstat-" target="_blank">this page</a> in our knowledge base to learn more about which codes can be used. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat' ) ),

			'filters_pageview_header' => array( 'description' => __( 'Page Properties', 'wp-slimstat' ), 'type' => 'section_header' ),
			'ignore_resources' => array( 'description' => __( 'Permalinks', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of permalinks that should not be tracked. Do not include your website domain name: <code>/about, ?p=1</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive.', 'wp-slimstat' ) ),
			'ignore_referers' => array( 'description' => __( 'Referring Sites', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of referring URLs that should not be tracked: <code>https://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive and must include the protocol (https://, https://).', 'wp-slimstat' ) ),
			'ignore_content_types' => array( 'description' => __( 'Content Types', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( 'Enter a list of Slimstat content types that should not be tracked: <code>post, page, attachment, tag, 404, taxonomy, author, archive, search, feed, login</code>, etc. See note at the bottom of this page for more information on how to use wildcards. String should be entered in lowercase.', 'wp-slimstat' ) ),
			'empty_field' => array( 'Wildcards', 'type' => 'custom', 'description' => '<p class="description">' . __( '<strong>Wildcards</strong><br>You can use the character <code>*</code> to match <em>any string, including the empty string</em>, and the character <code>!</code> to match <em>any character, including no character</em>. For example, <code>user*</code> matches user12 and userfoo, <code>u*100</code> matches user100 and ur100, <code>user!0</code> matches user10, user0 and user90, but not user100.', 'wp-slimstat' ) . '</p>', 'markup' => '' )
		)
	),

	5 => array(
		'title' => __( 'Access Control', 'wp-slimstat' ),
		'rows' => array(
			'permissions_reports_header' => array( 'description' => __( 'Reports', 'wp-slimstat' ), 'type' => 'section_header' ),
			'restrict_authors_view' => array( 'description' => __( 'Restrict Authors', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option if you want your authors to only see statistics related to their own content.', 'wp-slimstat' ) ),
			'capability_can_view' => array( 'description' => __( 'Minimum Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users need to have to access the reports (default: <code>manage_options</code>). If this field is left empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a the Usernames field here below is not empty. In this case, that list has precedence over the capability, and only those users will have access to the statistics.", 'wp-slimstat' ), 'skip_update' => true ),
			'can_view' => array( 'description' => __( 'Usernames', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of usernames who should have access to the statistics. Administrators are implicitly allowed, so you don't need to list them here below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat' ), 'skip_update' => true ),

			'permissions_customize_header' => array( 'description' => __( 'Customizer', 'wp-slimstat' ), 'type' => 'section_header' ),
			'capability_can_customize' => array( 'description' => __( 'Minimum Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users need to access the Customizer (default: <code>manage_options</code>). If this field is empty, all your users will be allowed to access the Customizer.", 'wp-slimstat' ), 'skip_update' => true ),
			'can_customize' => array( 'description' => __( 'Usernames', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of usernames who should have access to the Customizer. Administrators are implicitly allowed, so you don't need to list them here below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat' ), 'skip_update' => true ),

			'permissions_config_header' => array( 'description' => __( 'Settings', 'wp-slimstat' ), 'type' => 'section_header' ),
			'capability_can_admin' => array( 'description' => __( 'Minimum Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users need to configure Slimstat (default: <code>manage_options</code>). The field here below can be used to override this option, and allow specific users to access the settings.", 'wp-slimstat' ), 'skip_update' => true ),
			'can_admin' => array( 'description' => __( 'Usernames', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Enter a list of usernames who should have access to the plugin settings. Please be advised that administrators <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat' ), 'skip_update' => true ),

			'rest_api_header' => array( 'description' => __( 'REST API', 'wp-slimstat' ), 'type' => 'section_header' ),
			'rest_api_tokens' => array( 'description' => __( 'Tokens', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "In order to send requests to <a href='https://slimstat.freshdesk.com/support/solutions/articles/12000033661-slimstat-rest-api' target='_blank'>the Slimstat REST API</a>, you will need to pass a valid token to the endpoint (param ?token=XXX). Using the field here below, you can define as many tokens as you like, and distribute them to your API users. Please note: treat these tokens as passwords, as they will grant read access to your reports to anyone who knows them. Use a service like <a href='https://randomkeygen.com/#ci_key' target='_blank'>RandomKeyGen.com</a> to generate unique secure tokens.", 'wp-slimstat' ) )
		)
	),

	6 => array(
		'title' => __( 'Maintenance', 'wp-slimstat' ),
		'include' => dirname(__FILE__).'/maintenance.php'
	),

	7 => array(
		'title' => __( 'Add-ons', 'wp-slimstat' )
	)
);

$settings = apply_filters( 'slimstat_options_on_page', $settings );

$tabs_html = '';
foreach ( $settings as $a_tab_id => $a_tab_info ) {
	if ( !empty( $a_tab_info[ 'rows' ] ) || !empty( $a_tab_info[ 'include' ] ) ) {
		$tabs_html .= "<li class='nav-tab nav-tab".(($current_tab == $a_tab_id)?'-active':'-inactive')."'><a href='".wp_slimstat_admin::$config_url.$a_tab_id."'>{$a_tab_info[ 'title' ]}</a></li>";
	}
}

echo '<div class="wrap slimstat-config"><h2>'.__('Settings','wp-slimstat').'</h2><ul class="nav-tabs">'.$tabs_html.'</ul>';
echo '<div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px">' . __( '<strong>AdBlock browser extension detected</strong> - If you see this notice, it means that your browser is not loading our stylesheet and/or Javascript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://slimstat.freshdesk.com/support/solutions/articles/12000000414-the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat' ) . '</div>';

// The maintenance tab has its own separate file
if ( !empty( $settings[ $current_tab ][ 'include' ] ) ) {
	include_once( $settings[ $current_tab ][ 'include' ] );
}
else if ( !empty( $settings[ $current_tab ][ 'rows' ] ) ) {
	wp_slimstat_admin::update_settings( $settings[ $current_tab ][ 'rows' ] );
	wp_slimstat_admin::display_settings( $settings[ $current_tab ][ 'rows' ], $current_tab ); 
}

echo '</div>';