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
			wp_slimstat_admin::$faulty_fields[] = __( 'Read access: username not found', 'wp-slimstat' );
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
		wp_slimstat_admin::$faulty_fields[] = __( 'Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
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
			wp_slimstat_admin::$faulty_fields[] = __( 'Config access: username not found', 'wp-slimstat' );
		}
	}
}

if ( isset( $_POST[ 'options' ][ 'capability_can_admin' ] ) ) {
	if ( isset( $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) && array_key_exists( $_POST[ 'options' ][ 'capability_can_admin' ], $GLOBALS[ 'wp_roles' ]->role_objects[ 'administrator' ]->capabilities ) ) {
		wp_slimstat::$settings[ 'capability_can_admin' ] = $_POST[ 'options' ][ 'capability_can_admin' ];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __( 'Invalid capability. You may want to take a look at the official <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress documentation</a> on roles and capabilities.', 'wp-slimstat' );
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
			'auto_purge_delete' => array( 'description' => __( 'Archive records', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'If server space is not an issue for you, use this option to archive pageviews to a separate table, instead of deleting them. This will increase performance by reducing the amount of data to process in the main table, while allowing you to access your data at a later time, if needed. Please note that the archive table (<strong>wp_slim_stats_archive</strong>) will be <strong>DELETED</strong> along with all the other tables, when you uninstall Slimstat. Make sure to backup your data before you proceed.', 'wp-slimstat' ) )
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
			'posts_column_day_interval' => array( 'description' => __( 'Data Range', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Enter the time range, in days, that should be used to count the number of pageviews on Posts/Pages (see option here below) and for the Default Time Span option under the Reports tab.', 'wp-slimstat' ), 'after_input_field' => __('days', 'wp-slimstat' ) ),
			'use_current_month_timespan' => array( 'description' => __( 'Default Time Span', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Determine what is the default time period for calculating all the data in each report: current month or past given number of days. The number of days is defined under Basic > Report Interval. You can always use the time filter dropdown to customize this value even further.', 'wp-slimstat' ), 'custom_label_on' => 'Month', 'custom_label_off' => 'Days' ),
			'async_load' => array( 'description' => __( 'Async Mode', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Activate this feature if your reports take a while to load. It breaks down the load on your server into multiple requests, thus avoiding memory issues and performance problems.', 'wp-slimstat' ) ),
			'rows_to_show' => array('description' => __('Rows to Display','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of items in each report.','wp-slimstat')),
			'limit_results' => array( 'description' => __( 'Max Results','wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Decide how many records should be retrieved from the database in total. Depending on your server configuration, you may want to fine tune this value to avoid exceeding your PHP memory limit.', 'wp-slimstat' ) ),
			'ip_lookup_service' => array('description' => __( 'IP Lookup', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Customize the Geolocation service to be used in the reports. Default: <code>https://www.infosniper.net/?ip_address=</code>', 'wp-slimstat' ) ),
			'comparison_chart' => array( 'description' => __( 'Comparison Chart', 'wp-slimstat'), 'type' => 'toggle', 'long_description' => __( "Disable this option if you find the four line charts confusing, and prefer seeing only the selected time range.", 'wp-slimstat' ) ),
			
			'reports_basic_header' => array( 'description' => __( 'Data Formats', 'wp-slimstat' ), 'type' => 'section_header' ),
			'use_european_separators' => array( 'description' => __( 'Number Format', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Choose the number format you want to use for your reports.','wp-slimstat' ), 'custom_label_on' => '1.234,5', 'custom_label_off' => '1,234.5' ),
			'date_format' => array( 'description' => __( 'Date Format', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "<a href='https://php.net/manual/en/function.date.php' target='_blank'>PHP Format</a> to use when displaying a pageview's date.", 'wp-slimstat' ) ),
			'time_format' => array( 'description' => __( 'Time Format', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "<a href='https://php.net/manual/en/function.date.php' target='_blank'>PHP Format</a> to use when displaying a pageview's time.", 'wp-slimstat' ) ),
			'show_display_name' => array( 'description' => __( 'Use Display Name', 'wp-slimstat'), 'type' => 'toggle', 'long_description' => __( 'By default, users are listed by their usernames. Use this option to visualize their display names instead.','wp-slimstat')),
			'convert_resource_urls_to_titles' => array('description' => __('Use Titles','wp-slimstat'), 'type' => 'toggle', 'long_description' => __('Slimstat converts your permalinks into post, page and category titles. Disable this feature if you need to see the URL in your reports.', 'wp-slimstat')),
			'convert_ip_addresses' => array('description' => __('Convert IP Addresses','wp-slimstat'), 'type' => 'toggle', 'long_description' => __('Display provider names instead of IP addresses.','wp-slimstat')),

			'reports_right_now_header' => array( 'description' => __( 'Access Log and World Map', 'wp-slimstat' ), 'type' => 'section_header' ),
			'refresh_interval' => array( 'description' => __( 'Auto Refresh', 'wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Enable the Live View, which refreshes the Access Log every X seconds. Enter <strong>0</strong> (number zero) to deactivate this feature.', 'wp-slimstat' ), 'after_input_field' => __( 'seconds', 'wp-slimstat' ) ),
			'number_results_raw_data' => array('description' => __( 'Rows to Display', 'wp-slimstat'), 'type' => 'integer', 'long_description' => __( 'Specify the number of items in the Access Log.', 'wp-slimstat' ) ),
			'max_dots_on_map' => array('description' => __( 'Map Data Points', 'wp-slimstat'), 'type' => 'integer', 'long_description' => __( 'Customize the maximum number of dots displayed on the world map. Please note that large numbers might increase the amount of time needed to render the map.', 'wp-slimstat' ) ),

			'reports_miscellaneous_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'custom_css' => array( 'description' => __( 'Custom CSS', 'wp-slimstat' ), 'type' => 'textarea', 'rows' => 8, 'long_description' => __( "Paste here your custom stylesheet to personalize the way your reports look. <a href='https://slimstat.freshdesk.com/support/solutions/articles/5000528528-how-can-i-change-the-colors-associated-to-color-coded-pageviews-known-user-known-visitors-search-e' target='_blank'>Check the FAQ</a> for more information on how to use this setting.", 'wp-slimstat' ), 'use_tag_list' => false, 'use_code_editor' => 'css' ),
			'chart_colors' => array( 'description' => __( 'Chart Colors', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Customize the look and feel of your charts by assigning personalized colors to each metric. List 4 hex colors, strictly in the following order: metric 1 previous, metric 2 previous, metric 1 current, metric 2 current. For example: <code>#ccc, #999, #bbcc44, #21759b</code>.", 'wp-slimstat' ) ),
			'mozcom_access_id' => array('description' => __( 'Mozscape Access ID', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Get accurate rankings for your website through the free <a href="https://moz.com/community/join?redirect=/products/api/keys" target="_blank">Mozscape API</a> service. Sign up for a free community account to get started. Then enter your personal identification code here.', 'wp-slimstat' ) ),
			'mozcom_secret_key' => array('description' => __( 'Mozscape Secret Key', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Do not share your secret key with anyone or they will be able to make API requests on your account!', 'wp-slimstat' ) ),
			'show_complete_user_agent_tooltip' => array('description' => __('Show User Agent','wp-slimstat'), 'type' => 'toggle', 'long_description' => __('Choose if you want to see the browser name or a complete user agent string when hovering over each browser icon.','wp-slimstat')),
			'enable_sov' => array('description' => __('Enable SOV','wp-slimstat'), 'type' => 'toggle', 'long_description' => __('In linguistic typology, a subject-object-verb (SOV) language is one in which the subject, object, and verb of a sentence appear in that order, like in Japanese.','wp-slimstat'))
		)
	),

	4 => array(
		'title' => __( 'Exclusions', 'wp-slimstat' ),
		'rows' => array(
			'filters_users_header' => array( 'description' => __( 'User Properties', 'wp-slimstat' ), 'type' => 'section_header' ),
			'track_users' => array( 'description' => __( 'WP Users', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option to track logged in users.', 'wp-slimstat' ) ),
			'ignore_spammers' => array( 'description' => __( "Spammers", 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Enable this option if you don't want to track visits from users identified as spammers by third-party tools like Akismet. Pageviews generated by users whose comments are later marked as spam, will also be removed from the database.", 'wp-slimstat' ) ),
			'ignore_bots' => array( 'description' => __( "Bots", 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Turn on this feature if you want to have the accuracy level of server-side tracking, but not the inconvenience of getting your database clogged with pageviews generated by crawlers, spiders, search engine bots, etc. Please note that in Client mode, bots are ignored regardless of this setting.", 'wp-slimstat' ) ),
			'ignore_prefetch' => array( 'description' => __( "Prefetch Requests", 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( "Prevent Slimstat from tracking pageviews generated by Firefox's <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>.", 'wp-slimstat' ) ),
			'ignore_users' => array( 'description' => __( 'Usernames', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the usernames you don't want to track. Please be aware that spaces are <em>not</em> ignored and that usernames are case sensitive. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>user*</code> will match user12 and userfoo, <code>u*100</code> will match user100 and uber100, <code>user!0</code> will match user10 and user90.", 'wp-slimstat' ) ),
			'ignore_ip' => array( 'description' => __( 'IP Addresses', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the IP addresses you don't want to track. Each subnet <strong>must</strong> be defined using the <a href='https://www.iplocation.net/subnet-mask' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). This filter applies both to the public IP and the originating IP, if available. Using the CIDR notation, you will use octets to determine the subnet, so for example 54.0.0.0/8 means that the first number is represented by 8 bits, hence 8 after the slash. Then the second number would be another 8 bits, so you would write 54.12.0.0/16 (16 = 8 + 8), and you could do the same for the third number, for example 54.12.34.0/24 (24 = 8 + 8 + 8).", 'wp-slimstat' ) ),
			'ignore_countries' => array( 'description' => __( 'Countries', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Country codes (i.e.: <code>en-us, it, es</code>) that you don't want to track.", 'wp-slimstat' ) ),
			'ignore_browsers' => array('description' => __( 'User Agents', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Browsers (user agents) you don't want to track. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>Chr*</code> will match Chrome and Chromium, <code>IE/!.0</code> will match IE/7.0 and IE/8.0. Strings are case-insensitive.", 'wp-slimstat' ) ),
			'ignore_platforms' => array( 'description' => __( 'Operating Systems', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Operating system codes you don't want to track. Please refer to <a href='https://slimstat.freshdesk.com/solution/articles/12000031504-what-are-the-operating-system-codes-used-by-slimstat-' target='_blank'>this page</a> in our knowledge base to learn more about what codes you can use. Usual rules for using wildcards apply (see fields here above).", 'wp-slimstat' ) ),
			'ignore_capabilities' => array( 'description' => __( 'Capabilities', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Users having at least one of the <a href='https://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capabilities</a> listed here below will not be tracked. Capabilities are case-insensitive.", 'wp-slimstat' ), 'skip_update' => true ),

			'filters_pageview_header' => array( 'description' => __( 'Page Properties', 'wp-slimstat' ), 'type' => 'section_header' ),
			'ignore_resources' => array( 'description' => __( 'Permalinks', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the URLs on your website that you don't want to track. Don't include the domain name: <em>/about, ?p=1</em>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>/abou*</code> will match /about and /abound, <code>/abo*t</code> will match /aboundant and /about, <code>/abo!t</code> will match /about and /abort. Strings are case-insensitive.", 'wp-slimstat' ) ),
			'ignore_referers' => array( 'description' => __( 'Referring Sites', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Referring URLs that you don't want to track: <code>https://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. Strings are case-insensitive. Please include either a wildcard or the protocol you want to filter (https://, https://).", 'wp-slimstat' ) ),
			'ignore_content_types' => array( 'description' => __( 'Content Types', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "Slimstat categorizes pageviews by the associated WordPress content type: post, page, attachment, tag, 404, taxonomy, author, archive, search, feed, login and others. You can use this field to avoid tracking pages whose content type matches the ones you set here below.", 'wp-slimstat' ) )
		)
	),

	5 => array(
		'title' => __( 'Access Control', 'wp-slimstat' ),
		'rows' => array(
			'permissions_reports_header' => array( 'description' => __( 'Reports', 'wp-slimstat' ), 'type' => 'section_header' ),
			'restrict_authors_view' => array( 'description' => __( 'Restrict Authors', 'wp-slimstat' ), 'type' => 'toggle', 'long_description' => __( 'Enable this option if you want your authors to only see stats related to their own content.', 'wp-slimstat' ) ),
			'capability_can_view' => array( 'description' => __( 'Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> needed to access the reports (default: <code>manage_options</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here below. In this case, the list has precedence over the capability.", 'wp-slimstat' ) ),
			'can_view' => array( 'description' => __( 'Whitelist', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the users who should have access to the reports. Administrators are implicitly allowed, so you don't need to list them in here. Usernames are case sensitive.", 'wp-slimstat' ), 'skip_update' => true ),

			'permissions_customize_header' => array( 'description' => __( 'Customizer', 'wp-slimstat' ), 'type' => 'section_header' ),
			'capability_can_customize' => array( 'description' => __( 'Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> needed to be able to customize the reports layout (default: <code>manage_options</code>). If this field is empty, your users will be permitted to use this features.", 'wp-slimstat' ) ),
			'can_customize' => array( 'description' => __( 'Whitelist', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the users who should be allowed to customize their report layout. Administrators are implicitly allowed, so you don't need to list them in here. Usernames are case sensitive.", 'wp-slimstat' ), 'skip_update' => true ),

			'permissions_config_header' => array( 'description' => __( 'Settings', 'wp-slimstat' ), 'type' => 'section_header' ),
			'capability_can_admin' => array( 'description' => __( 'Capability', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( "Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> required to configure Slimstat (default: <code>manage_options</code>). The whitelist here below can be used to override this option for specific users.", 'wp-slimstat' ) ),
			'can_admin' => array( 'description' => __( 'Whitelist', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the users who can edit these options. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive.", 'wp-slimstat' ), 'skip_update' => true ),

			'rest_api_header' => array( 'description' => __( 'REST API', 'wp-slimstat' ), 'type' => 'section_header' ),
			'rest_api_tokens' => array( 'description' => __( 'Tokens', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "In order to send requests to <a href='https://slimstat.freshdesk.com/support/solutions/articles/12000033661-slimstat-rest-api' target='_blank'>the Slimstat REST API</a>, you will need to pass a valid token to the endpoint (param ?token=XXX). Using the field here below, you can define as many tokens as you like, to distribute them to your API users. Please note: treat these tokens as passwords, as they will grant read access to your reports to anyone who knows them. Use a service like <a href='https://randomkeygen.com/#ci_key' target='_blank'>RandomKeyGen.com</a> to generate unique secure tokens.", 'wp-slimstat' ) )
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