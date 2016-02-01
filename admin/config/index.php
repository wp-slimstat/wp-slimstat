<?php

// Avoid direct access to this piece of code
if ( !function_exists( 'add_action' ) ) {
	exit(0);
}

// Handle special options
if (isset($_POST['options']['auto_purge'])){
	if ($_POST['options']['auto_purge'] == 0){
		wp_clear_scheduled_hook('wp_slimstat_purge');
	}
	else if (wp_next_scheduled( 'my_schedule_hook' ) == 0){
		wp_schedule_event(time(), 'daily', 'wp_slimstat_purge');
	}
}

if (!empty($_POST['options']['ignore_capabilities'])){
	// Make sure all the capabilities exist in the system 
	$capability_array = wp_slimstat::string_to_array($_POST['options']['ignore_capabilities']);
	$capability_not_found = false;
	foreach(wp_slimstat::string_to_array($_POST['options']['ignore_capabilities']) as $a_capability){
		if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && !array_key_exists($a_capability, $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
			$capability_not_found = true;
			break;
		}
	}
	
	if (!$capability_not_found){		
		wp_slimstat::$options['ignore_capabilities'] = $_POST['options']['ignore_capabilities'];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __('Invalid capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat');
	}
}

if (!empty($_POST['options']['can_view'])){
	// Make sure all the users exist in the system 
	$post_data = trim($_POST['options']['can_view']);
	$user_array = wp_slimstat::string_to_array($_POST['options']['can_view']);

	if (is_array($user_array) && !empty($post_data)){
		$sql_user_placeholders = implode(', ', array_fill(0, count($user_array), '%s'));
		if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users} WHERE user_login IN ($sql_user_placeholders)", $user_array)) == count($user_array)){
			wp_slimstat::$options['can_view'] = $_POST['options']['can_view'];
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __('Read access: username not found','wp-slimstat');
		}
	}
}

if (!empty($_POST['options']['capability_can_view'])){
	if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_view'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
		wp_slimstat::$options['capability_can_view'] = $_POST['options']['capability_can_view'];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat');
	}
}

if (!empty($_POST['options']['can_admin'])){
	// Make sure all the users exist in the system
	$post_data = trim($_POST['options']['can_admin']);
	$user_array = wp_slimstat::string_to_array($_POST['options']['can_admin']);

	if (is_array($user_array) && !empty($post_data)){
		$sql_user_placeholders = implode(', ', array_fill(0, count($user_array), '%s'));
		if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users} WHERE user_login IN ($sql_user_placeholders)", $user_array)) == count($user_array)){
			wp_slimstat::$options['can_admin'] = $_POST['options']['can_admin'];
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __('Config access: username not found','wp-slimstat');
		}
	}
}
			
if (!empty($_POST['options']['capability_can_admin'])){
	if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_admin'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
		wp_slimstat::$options['capability_can_admin'] = $_POST['options']['capability_can_admin'];
	}
	else{
		wp_slimstat_admin::$faulty_fields[] = __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat');
	}
}

$current_tab = empty( $_GET[ 'tab' ] ) ? 1 : intval( $_GET[ 'tab' ] );

// Define all the options
$options = array(
	1 => array(
		'title' => __( 'Basic', 'wp-slimstat' ),
		'rows' => array(
			'general_tracking_header' => array('description' => __('Tracker','wp-slimstat'), 'type' => 'section_header'),
			'is_tracking' => array( 'description' => __( 'Enable Tracking', 'wp-slimstat' ), 'type' => 'yesno', 'long_description' => __( 'Turn the tracker on or off, while keeping the reports accessible.', 'wp-slimstat' ) ),
			'javascript_mode' => array( 'description' => __( 'Tracking Mode', 'wp-slimstat' ), 'type' => 'yesno', 'long_description' => __('Select <strong>Client</strong> if you are using a caching plugin (W3 Total Cache, WP SuperCache, HyperCache, etc). Slimstat will behave pretty much like Google Analytics, and visitors whose browser does not support Javascript will be ignored. A nice side effect is that <strong>most spammers, search engines and other crawlers</strong> will not be tracked.','wp-slimstat'), 'custom_label_yes' => __('Client Side','wp-slimstat'), 'custom_label_no' => __('Server Side','wp-slimstat') ),
			'enable_javascript' => array('description' => __('Stealth Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Do not add the javascript tracking code to your pages, if tracking mode is set to Server. Please note: if enabled, this will prevent the tracker from collecting information such as screen resolution, outbound links, downloads, etc. This option is ignored if Tracking Mode is set to Client.",'wp-slimstat'), 'custom_label_yes' => __('Off','wp-slimstat'), 'custom_label_no' => __('On','wp-slimstat') ),
			'track_admin_pages' => array( 'description' => __('Admin Pages','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option to track your users' activity within the admin.",'wp-slimstat'), 'custom_label_yes' => __('Track','wp-slimstat'), 'custom_label_no' => __('Do not track','wp-slimstat') ),

			'general_integration_header' => array('description' => __('WordPress Integration','wp-slimstat'), 'type' => 'section_header'),
			'use_separate_menu' => array( 'description' => __('Menu Position','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose between a standalone admin menu for Slimstat or a drop down in the admin bar (if visible).','wp-slimstat'), 'custom_label_yes' => __('Side Menu','wp-slimstat'), 'custom_label_no' => __('Admin Bar','wp-slimstat') ),
			'add_posts_column' => array( 'description' => __('Posts and Pages','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Add a new column to the Edit Posts/Pages screens, with the number of hits per post within the timeframe specified here below.','wp-slimstat') ),
			'posts_column_day_interval' => array( 'description' => __('Report Interval','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Enter the time range, in days, that should be used to calculate the value here above.','wp-slimstat') ),
			'posts_column_pageviews' => array( 'description' => __('Report Type','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Select what kind of information you would like to see displayed on the Posts admin screen. Pageviews include all the hits regardless of the user, Unique IPs consider only one hit per user in the given time range.','wp-slimstat'), 'custom_label_yes' => __('Pageviews','wp-slimstat'), 'custom_label_no' => __('Unique IPs','wp-slimstat') ),
			'add_dashboard_widgets' => array( 'description' => __('Dashboard Widgets','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to have the most important reports on your WordPress Dashboard. Use the Screen Options dropdown to select which ones to display.','wp-slimstat') ),
			'hide_addons' => array( 'description' => __('Hide Add-ons','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option to hide all your <strong>active</strong> premium add-ons from the list of plugins in WordPress. Please note that you will still receive updates for hidden add-ons.','wp-slimstat') ),

			'general_database_header' => array('description' => __('Database','wp-slimstat'), 'type' => 'section_header'),
			'auto_purge' => array( 'description' => __('Retain data for','wp-slimstat'), 'type' => 'integer', 'long_description' => __("Clean-up log entries older than the number of days specified here above. Enter <strong>0</strong> (number zero) if you want to preserve your data regardless of its age.",'wp-slimstat').( (wp_slimstat::$options[ 'auto_purge' ] > 0)?' '.__('Next clean-up on','wp-slimstat').' <strong>'.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')).'</strong>. '.sprintf(__('Entries logged on or before %s will be archived or deleted according to the option here below.','wp-slimstat'), date_i18n(get_option('date_format'), strtotime('-'.wp_slimstat::$options['auto_purge'].' days'))):''), 'after_input_field' => __('days','wp-slimstat') ),
			'auto_purge_delete' => array( 'description' => __('Archive records','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('If DB space is not an issue, you can decide to archive older records in another table, instead of deleting them. This way performance is preserved, but you will still be able to access your data at a later time, if needed. Please note that the archive table (<code>wp_slim_stats_archive</code>) will be <strong>deleted</strong> along with all the other tables, when Slimstat is uninstalled. Make sure to backup your data before you proceed.','wp-slimstat') )
		)
	),

	2 => array(
		'title' => __( 'Tracker', 'wp-slimstat' ),
		'rows' => array(
			'advanced_tracker_header' => array('description' => __('Advanced Options','wp-slimstat'), 'type' => 'section_header'),
			'session_duration' => array('description' => __('Session Duration','wp-slimstat'), 'type' => 'integer', 'long_description' => __('How many seconds should a human session last? Google Analytics sets it to 1800 seconds.','wp-slimstat'), 'after_input_field' => __('seconds','wp-slimstat')),
			'extend_session' => array('description' => __('Extend Session','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Extend the duration of a session each time the user visits a new page.','wp-slimstat')),
			'browser_detection_mode' => array( 'description' => __( 'Browser Detection', 'wp-slimstat' ), 'type' => 'yesno', 'long_description' => __( "The heuristic function is much faster and requires very little memory, but for uncommon user agent strings it might be less accurate, and produce a unreliable match. Browscap.ini, the third party database we use, is memory intensive and it uses a bruteforce approach to determine a visitor's browser, but it's very accurate and precise even with the most obscure user agent strings (almost all of them). You decide which one should be used first: the other one will only be invoked if the one you chose did not produce a match.", 'wp-slimstat' ), 'custom_label_yes' => __( 'Browscap', 'wp-slimstat' ), 'custom_label_no' => __( 'Heuristic', 'wp-slimstat' ) ),
			'enable_cdn' => array('description' => __('Enable CDN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Use <a href='http://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving our tracking code from their fast and reliable network (free service).",'wp-slimstat')),
			'extensions_to_track' => array('description' => __('Extensions to Track','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the file extensions that you want to be treated as Downloads. Please note that links pointing to external resources (i.e. PDFs on a different website) are considered Downloads and not Outbound Links (and tracked as such), if their extension matches one of the ones listed here below.",'wp-slimstat')),

			'filters_outbound_header' => array('description' => __('Internal and Outbound Links','wp-slimstat'), 'type' => 'section_header'),
			'enable_outbound_tracking' => array('description' => __('Track Outbound Clicks','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Track when your visitors click on link to external websites. This option required Spy Mode to be enabled.','wp-slimstat')),
			'track_internal_links' => array('description' => __('Track Coordinates','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Collect mouse coordinates and other information for clicks on internal links. Strongly recommended if you're using the heatmap add-on. By default, this information is only collected for external links.",'wp-slimstat')),
			'ignore_outbound_classes_rel_href' => array('description' => __('No Callback','wp-slimstat'), 'type' => 'text', 'long_description' => __("Track the event but do not invoke the callback function on links marked with one of these class names, <em>rel</em> attribute or whose <em>href</em> attribute contains one of these strings (separated by comma). Useful to prevent conflicts with lightbox and similar libraries.",'wp-slimstat')),
			'do_not_track_outbound_classes_rel_href' => array('description' => __('Do Not Track','wp-slimstat'), 'type' => 'text', 'long_description' => __("Do not track links marked with one of these class names, <em>rel</em> attributes or whose <em>href</em> attribute contains one of these strings (separated by comma).",'wp-slimstat')),

			'advanced_external_pages_header' => array('description' => __('Pages not belonging to this site','wp-slimstat'), 'type' => 'section_header'),
			'external_pages_script' => array('type' => 'static', 'skip_update' => 'yes', 'description' => __('Add the following code to all the non-WP pages you want to track, right before the closing BODY tag. Please make sure to change the protocol of all the URLs to HTTPS, if you external site is served over a secure channel.','wp-slimstat'), 'long_description' => '&lt;script type="text/javascript"&gt;
	/* &lt;![CDATA[ */
	var SlimStatParams = {
		ajaxurl: "'.admin_url('admin-ajax.php').'",
		ci: "YTo0OntzOjEyOiJjb250ZW50X3R5cGUiO3M6ODoiZXh0ZXJuYWwiO3M6ODoiY2F0ZWdvcnkiO3M6MDoiIjtzOjEwOiJjb250ZW50X2lkIjtpOjA7czo2OiJhdXRob3IiO3M6MTM6ImV4dGVybmFsLXBhZ2UiO30=.' . md5('YTo0OntzOjEyOiJjb250ZW50X3R5cGUiO3M6ODoiZXh0ZXJuYWwiO3M6ODoiY2F0ZWdvcnkiO3M6MDoiIjtzOjEwOiJjb250ZW50X2lkIjtpOjA7czo2OiJhdXRob3IiO3M6MTM6ImV4dGVybmFsLXBhZ2UiO30=' . wp_slimstat::$options[ 'secret' ] ).'",
		extensions_to_track: "'.wp_slimstat::$options['extensions_to_track'].'"
	};
	/* ]]&gt; */
	&lt;/script&gt;
	&lt;script type="text/javascript" src="http://cdn.jsdelivr.net/wp/wp-slimstat/trunk/wp-slimstat.js"&gt;&lt;/script&gt;'),
			'external_domains' => array('description' => __('Allow External Domains','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("If you are getting an error saying that no 'Access-Control-Allow-Origin' header is present on the requested resource, when using the external tracking code here above, list the domains (complete with scheme, separated by commas) you would like to allow. For example: <code>http://my.domain.ext</code> (no trailing slash). Please see <a href='http://www.w3.org/TR/cors/#security' target='_blank'>this W3 resource</a> for more information on the security implications of allowing CORS requests.",'wp-slimstat')),
			'advanced_misc_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'enable_ads_network' => array('description' => __('Enable UAN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Send anonymous data about user agents to our server for analysis. This allows us to contribute to the <a href='http://browscap.org/' target='_blank'>BrowsCap opensource project</a>, and improve the accuracy of Slimstat's browser detection functionality. It also enables our transparent ads network. No worries, your site will not be affected in any way.",'wp-slimstat'))
		)
	),

	3 => array(
		'title' => __( 'Filters', 'wp-slimstat' ),
		'rows' => array(
			'filters_header' => array('description' => __('Do not track settings','wp-slimstat'), 'type' => 'section_header'),
			'track_users' => array('description' => __('Track Registered Users','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option to track logged in users.','wp-slimstat')),
			'ignore_users' => array( 'description' => __( 'Blacklist by Username', 'wp-slimstat' ), 'type' => 'textarea', 'long_description' => __( "List all the usernames you don't want to track, separated by commas. Please be aware that spaces are <em>not</em> ignored and that usernames are case sensitive. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>user*</code> will match user12 and userfoo, <code>u*100</code> will match user100 and uber100, <code>user!0</code> will match user10 and user90.", 'wp-slimstat' ) ),
			'ignore_ip' => array('description' => __('Blacklist by IP Address','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the IP addresses you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). This filter applies both to the public IP and the originating IP, if available.",'wp-slimstat')),
			'ignore_capabilities' => array('description' => __('Blacklist by Capability','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Users having at least one of the <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capabilities</a> listed here below will not be tracked. Capabilities are case-insensitive.",'wp-slimstat'), 'skip_update' => true),

			'filters_categories_header' => array('description' => __('Profiling','wp-slimstat'), 'type' => 'section_header'),
			'ignore_spammers' => array('description' => __('Ignore Spammers','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option if you don't want to track visits from users identified as spammers by third-party tools like Akismet. Pageviews generated by users whose comments are later marked as spam, will also be removed from the database.",'wp-slimstat')),
			'ignore_bots' => array('description' => __('Ignore Bots','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Turn on this feature if you want to have the accuracy level of server-side tracking, but not the inconvenience of getting your database clogged with pageviews generated by crawlers, spiders, search engine bots, etc. Please note that in Client mode, bots are ignored regardless of this setting.",'wp-slimstat')),
			'ignore_resources' => array('description' => __('Permalinks','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the URLs on your website that you don't want to track, separated by commas. Don't include the domain name: <em>/about, ?p=1</em>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>/abou*</code> will match /about and /abound, <code>/abo*t</code> will match /aboundant and /about, <code>/abo!t</code> will match /about and /abort. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_countries' => array('description' => __('Countries','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Country codes (i.e.: <code>en-us, it, es</code>) that you don't want to track, separated by commas.",'wp-slimstat')),
			'ignore_browsers' => array('description' => __('User Agents','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Browsers (user agents) you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>Chr*</code> will match Chrome and Chromium, <code>IE/!.0</code> will match IE/7.0 and IE/8.0. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_referers' => array('description' => __('Referring Sites','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Referring URLs that you don't want to track, separated by commas: <code>http://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. Strings are case-insensitive. Please include either a wildcard or the protocol you want to filter (http://, https://).",'wp-slimstat')),

			'filters_miscellaneous_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'anonymize_ip' => array('description' => __('Enable Privacy Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Mask your visitors' IP addresses to comply with European Privacy Laws.",'wp-slimstat')),
			'ignore_prefetch' => array('description' => __('Ignore Prefetch Requests','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Prevent Slimstat from tracking pageviews generated by Firefox's <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>.",'wp-slimstat'))
		)
	),

	4 => array(
		'title' => __( 'Reports', 'wp-slimstat' ),
		'rows' => array(
			'reports_basic_header' => array('description' => __('Formats and Conversions','wp-slimstat'), 'type' => 'section_header'),
			'use_european_separators' => array('description' => __('Number Format','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose the number format you want to use for your reports.','wp-slimstat'), 'custom_label_yes' => '1.234,56', 'custom_label_no' => '1,234.56'),
			'date_format' => array('description' => __('Date Format','wp-slimstat'), 'type' => 'text', 'long_description' => __("<a href='http://php.net/manual/en/function.date.php' target='_blank'>PHP Format</a> to use when displaying a pageview's date.", 'wp-slimstat')),
			'time_format' => array('description' => __('Time Format','wp-slimstat'), 'type' => 'text', 'long_description' => __("<a href='http://php.net/manual/en/function.date.php' target='_blank'>PHP Format</a> to use when displaying a pageview's time.", 'wp-slimstat')),
			'show_display_name' => array('description' => __('Use Display Name','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('By default, users are listed by their usernames. Use this option to visualize their display names instead.','wp-slimstat')),
			'convert_resource_urls_to_titles' => array('description' => __('Use Titles','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Slimstat converts your permalinks into post, page and category titles. Disable this feature if you need to see the URL in your reports.', 'wp-slimstat')),
			'convert_ip_addresses' => array('description' => __('Convert IP Addresses','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Display provider names instead of IP addresses.','wp-slimstat')),

			'reports_functionality_header' => array( 'description' => __( 'Functionality', 'wp-slimstat' ), 'type' => 'section_header' ),
			'async_load' => array( 'description' => __( 'Async Mode', 'wp-slimstat' ), 'type' => 'yesno', 'long_description' => __( 'Activate this feature if your reports take a while to load. It breaks down the load on your server into multiple requests, thus avoiding memory issues and performance problems.', 'wp-slimstat' ) ),
			'use_slimscroll' => array('description' => __('SlimScroll','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable SlimScroll, a slick jQuery library that replaces the built-in browser scrollbar.','wp-slimstat')),
			'expand_details' => array('description' => __('Expand Details','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Expand each row's details by default, insted of on mousehover.",'wp-slimstat')),
			'rows_to_show' => array('description' => __('Rows to Display','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of items in each report.','wp-slimstat')),
			'limit_results' => array( 'description' => __( 'Max Results','wp-slimstat' ), 'type' => 'integer', 'long_description' => __( 'Decide how many records should be retrieved from the database in total. Depending on your server configuration, you may want to fine tune this value to avoid exceeding your PHP memory limit.', 'wp-slimstat' ) ),
			'ip_lookup_service' => array('description' => __( 'IP Lookup', 'wp-slimstat' ), 'type' => 'text', 'long_description' => __( 'Customize the Geolocation service to be used in the reports.', 'wp-slimstat' ) ),

			'reports_right_now_header' => array('description' => __('Activity Log','wp-slimstat'), 'type' => 'section_header'),
			'refresh_interval' => array('description' => __('Live Stream','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Enable the Live view, which refreshes the Activity Log every X seconds. Enter <strong>0</strong> (number zero) to deactivate this feature.','wp-slimstat'), 'after_input_field' => __('seconds','wp-slimstat')),
			'number_results_raw_data' => array('description' => __('Rows to Display','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of items in the Activity Log.','wp-slimstat')),

			'reports_miscellaneous_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'custom_css' => array('description' => __('Custom CSS','wp-slimstat'), 'type' => 'textarea', 'rows' => 8, 'long_description' => __("Paste here your custom stylesheet to personalize the way your reports look. <a href='https://slimstat.freshdesk.com/support/solutions/articles/5000528528-how-can-i-change-the-colors-associated-to-color-coded-pageviews-known-user-known-visitors-search-e' target='_blank'>Check the FAQ</a> for more information on how to use this setting.",'wp-slimstat')),
			'chart_colors' => array('description' => __('Chart Colors','wp-slimstat'), 'type' => 'text', 'long_description' => __("Customize the look and feel of your charts by assigning personalized colors to each metric. List 4 hex colors separated by commas, strictly in the following order: metric 1 previous, metric 2 previous, metric 1 current, metric 2 current. For example: <code>#ccc, #999, #bbcc44, #21759b</code>.",'wp-slimstat')),
			'show_complete_user_agent_tooltip' => array('description' => __('Show User Agent','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to see the browser name or a complete user agent string when hovering on browser icons.','wp-slimstat')),
			'enable_sov' => array('description' => __('Enable SOV','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('In linguistic typology, a subject-object-verb (SOV) language is one in which the subject, object, and verb of a sentence appear in that order, like in Japanese.','wp-slimstat'))
		)
	),

	5 => array(
		'title' => __( 'Access Control', 'wp-slimstat' ),
		'rows' => array(
			'permissions_reports_header' => array('description' => __('Reports','wp-slimstat'), 'type' => 'section_header'),
			'restrict_authors_view' => array('description' => __('Restrict Authors','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option if you want your authors to only see stats related to their own content.','wp-slimstat')),
			'capability_can_view' => array('description' => __('Capability','wp-slimstat'), 'type' => 'text', 'long_description' => __("Specify the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> needed to access the reports (default: <code>activate_plugins</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here below. In this case, the list has precedence over the capability.",'wp-slimstat')),
			'can_view' => array('description' => __('Whitelist','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who should have access to the reports, separated by commas. Administrators are implicitly allowed, so you don't need to list them in here. Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true),

			'permissions_config_header' => array('description' => __('Settings','wp-slimstat'), 'type' => 'section_header'),
			'capability_can_admin' => array('description' => __('Capability','wp-slimstat'), 'type' => 'text', 'long_description' => __("Specify the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> required to configure Slimstat (default: <code>activate_plugins</code>). The whitelist here below can be used to override this option for specific users.",'wp-slimstat')),
			'can_admin' => array('description' => __('Whitelist','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who can edit these options, separated by commas. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true)
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

$options = apply_filters( 'slimstat_options_on_page', $options );

$tabs_html = '';
foreach ( $options as $a_tab_id => $a_tab_info ) {
	if ( !empty( $a_tab_info[ 'rows' ] ) || !empty( $a_tab_info[ 'include' ] ) ) {
		$tabs_html .= "<li class='nav-tab nav-tab".(($current_tab == $a_tab_id)?'-active':'-inactive')."'><a href='".wp_slimstat_admin::$config_url.$a_tab_id."'>{$a_tab_info[ 'title' ]}</a></li>";
	}
}

echo '<div class="wrap slimstat-config"><h2>'.__('Settings','wp-slimstat').'</h2><ul class="nav-tabs">'.$tabs_html.'</ul>';

// The maintenance tab has its own separate file
if ( !empty( $options[ $current_tab ][ 'include' ] ) ) {
	include_once( $options[ $current_tab ][ 'include' ] );
}
else if ( !empty( $options[ $current_tab ][ 'rows' ] ) ) {
	wp_slimstat_admin::update_options( $options[ $current_tab ][ 'rows' ] );
	wp_slimstat_admin::display_options( $options[ $current_tab ][ 'rows' ], $current_tab); 
}

echo '</div>';