<?php

// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Define the tabs
$slimtabs = '';
$current_tab = empty($_GET['tab'])?1:intval($_GET['tab']);
$config_tabs = apply_filters('slimstat_config_tabs', array(__('General','wp-slimstat'),__('Views','wp-slimstat'),__('Filters','wp-slimstat'),__('Permissions','wp-slimstat'),__('Advanced','wp-slimstat'),has_filter('slimstat_options_on_page')?__('Add-ons','wp-slimstat'):'none',__('Maintenance','wp-slimstat')));
foreach ($config_tabs as $a_tab_id => $a_tab_name){
	if ($a_tab_name != 'none') $slimtabs .= "<li class='nav-tab nav-tab".(($current_tab == $a_tab_id+1)?'-active':'-inactive')."'><a href='".wp_slimstat_admin::$config_url.($a_tab_id+1)."'>$a_tab_name</a></li>";
}

echo '<div class="wrap slimstat"><h2>'.__('Settings','wp-slimstat').'</h2><ul class="nav-tabs">'.$slimtabs.'</ul>';

$options_on_this_page = array();
switch ($config_tabs[$current_tab-1]){
	case __('General','wp-slimstat'):
		$options_on_this_page = array(
			'general_tracking_header' => array('description' => __('Tracker','wp-slimstat'), 'type' => 'section_header'),
			'is_tracking' => array( 'description' => __('Enable Tracking','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Turn the tracker on or off, but keep the reports accessible.','wp-slimstat') ),
			'track_admin_pages' => array( 'description' => __('Monitor Admin Pages','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option to track your users' activity within the admin.",'wp-slimstat') ),
			'enable_javascript' => array('description' => __('Enable Spy Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Collect information about screen resolutions, outbound links, downloads, etc. If Tracking Mode is set to Javascript, this data will be tracked regardless of which value you set for this option.",'wp-slimstat')),
			'javascript_mode' => array( 'description' => __('Tracking Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Select <strong>Javascript</strong> if you are using a caching plugin (W3 Total Cache, WP SuperCache, HyperCache, etc). Slimstat will behave pretty much like Google Analytics, and visitors whose browser does not support Javascript will be ignored. A nice side effect is that <strong>most spammers, search engines and other crawlers</strong> will not be tracked.','wp-slimstat'), 'custom_label_yes' => __('Javascript','wp-slimstat'), 'custom_label_no' => __('Server-side','wp-slimstat') ),

			'general_integration_header' => array('description' => __('WordPress Integration','wp-slimstat'), 'type' => 'section_header'),
			'use_separate_menu' => array( 'description' => __('Menu Position','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose between a standalone admin menu for Slimstat or a drop down in the admin bar (if visible).','wp-slimstat'), 'custom_label_yes' => __('Side Menu','wp-slimstat'), 'custom_label_no' => __('Admin Bar','wp-slimstat') ),
			'add_posts_column' => array( 'description' => __('Add Stats to Posts and Pages','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Add a new column to the Edit Posts/Pages screens, with the number of hits per post.','wp-slimstat') ),

			'general_database_header' => array('description' => __('Database','wp-slimstat'), 'type' => 'section_header'),
			'auto_purge' => array( 'description' => __('Retain data for','wp-slimstat'), 'type' => 'integer', 'long_description' => __("Delete log entries older than the number of days specified here above. Enter <strong>0</strong> (number zero) if you want to preserve your data regardless of its age.",'wp-slimstat').(wp_get_schedule('wp_slimstat_purge')?' <br> '.__('Next clean-up on','wp-slimstat').' '.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')).'. '.sprintf(__('Entries logged on or before %s will be permanently deleted.','wp-slimstat'), date_i18n(get_option('date_format'), strtotime('-'.wp_slimstat::$options['auto_purge'].' days'))):''), 'after_input_field' => __('days','wp-slimstat') )
		);

		// If autopurge = 0, we can unschedule our cron job. If autopurge > 0 and the hook was not scheduled, we schedule it
		if (isset($_POST['options']['auto_purge'])){
			if ($_POST['options']['auto_purge'] == 0){
				wp_clear_scheduled_hook('wp_slimstat_purge');
			}
			else if (wp_next_scheduled( 'my_schedule_hook' ) == 0){
				wp_schedule_event(time(), 'daily', 'wp_slimstat_purge');
			}
		}
		break;
	case __('Views','wp-slimstat'):
		$options_on_this_page = array(
			'views_basic_header' => array('description' => __('Data and Formats','wp-slimstat'), 'type' => 'section_header'),
			'convert_ip_addresses' => array('description' => __('Convert IP Addresses','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Display provider names instead of IP addresses.','wp-slimstat')),
			'use_european_separators' => array('description' => __('Number Format','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose the number format you want to use for your reports.','wp-slimstat'), 'custom_label_yes' => '1.234,56', 'custom_label_no' => '1,234.56'),
			'show_display_name' => array('description' => __('Show Display Name','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('By default, users are listed by their usernames. Use this option to visualize their display names instead.','wp-slimstat')),
			'show_complete_user_agent_tooltip' => array('description' => __('Show User Agent','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to see the browser name or a complete user agent string when hovering on browser icons.','wp-slimstat')),
			'convert_resource_urls_to_titles' => array('description' => __('Show Titles','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Slimstat converts your permalinks into post and page titles. Disable this feature if you need to see the URL in your reports.','wp-slimstat')),
			
			'views_functionality_header' => array('description' => __('Functionality','wp-slimstat'), 'type' => 'section_header'),
			'async_load' => array('description' => __('Asynchronous Views','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Load all the reports dynamically. It makes the reports render faster, but it increases the load on your server.','wp-slimstat')),
			'use_slimscroll' => array('description' => __('SlimScroll','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable SlimScroll, a slick jQuery library that replaces the built-in browser scrollbar.','wp-slimstat')),
			'expand_details' => array('description' => __('Expand Details','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Expand each row's details by default, insted of on mousehover.",'wp-slimstat')),
			'rows_to_show' => array('description' => __('Rows to Display','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of items in each report.','wp-slimstat')),
			
			'views_right_now_header' => array('description' => __('Activity Log','wp-slimstat'), 'type' => 'section_header'),
			'refresh_interval' => array('description' => __('Live Stream','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Enable the Live view, which refreshes the Activity Log every X seconds. Enter <strong>0</strong> (number zero) to disable this functionality.','wp-slimstat'), 'after_input_field' => __('seconds','wp-slimstat')),
			'number_results_raw_data' => array('description' => __('Rows to Display','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of items in the Activity Log.','wp-slimstat')),
			'include_outbound_links_right_now' => array('description' => __('Activity Log Extended','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to see outbound links listed in the Activity Log. It might slow down the rendering of this report.','wp-slimstat'))
		);
		break;
	case __('Filters','wp-slimstat'):
		$options_on_this_page = array(
			'filters_users_header' => array('description' => __('Visitors and Known Users','wp-slimstat'), 'type' => 'section_header'),
			'track_users' => array('description' => __('Track Registered Users','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option to track logged in users.','wp-slimstat')),
			'ignore_users' => array('description' => __('Blacklist by Username','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the usernames you don't want to track, separated by commas. Please be aware that spaces are <em>not</em> ignored and that usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true),
			'ignore_ip' => array('description' => __('Blacklist by IP Address','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the IP addresses you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). This filter applies both to the public IP and the originating IP, if available.",'wp-slimstat')),
			'ignore_capabilities' => array('description' => __('Blacklist by Capability','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Users having at least one of the <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capabilities</a> listed here below will not be tracked. Capabilities are case-insensitive.",'wp-slimstat'), 'skip_update' => true),

			'filters_categories_header' => array('description' => __('Profiling','wp-slimstat'), 'type' => 'section_header'),
			'ignore_spammers' => array('description' => __('Ignore Spammers','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option if you don't want to track visits from users identified as spammers by third-party tools like Akismet. Pageviews generated by users whose comments are later marked as spam, will also be removed from the database.",'wp-slimstat')),
			'ignore_resources' => array('description' => __('Permalinks','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the URLs on your website that you don't want to track, separated by commas. Don't include the domain name: <em>/about, ?p=1</em>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>/abou*</code> will match /about and /abound, <code>/abo*t</code> will match /aboundant and /about, <code>/abo!t</code> will match /about and /abort. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_countries' => array('description' => __('Countries','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Country codes (i.e.: <code>en-us, it, es</code>) that you don't want to track, separated by commas.",'wp-slimstat')),
			'ignore_browsers' => array('description' => __('User Agents','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Browsers (user agents) you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. For example, <code>Chr*</code> will match Chrome and Chromium, <code>IE/!.0</code> will match IE/7.0 and IE/8.0. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_referers' => array('description' => __('Referring Sites','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Referring URLs that you don't want to track, separated by commas: <code>http://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. Wildcards: <code>*</code> matches 'any string, including the empty string', <code>!</code> matches 'any character'. Strings are case-insensitive. Please include either a wildcard or the protocol you want to filter (http://, https://).",'wp-slimstat')),
			
			'filters_miscellaneous_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'anonymize_ip' => array('description' => __('Enable Privacy Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Mask your visitors' IP addresses to comply with European Privacy Laws.",'wp-slimstat')),
			'ignore_prefetch' => array('description' => __('Ignore Prefetch Requests','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Prevent Slimstat from tracking pageviews generated by Firefox's <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>.",'wp-slimstat'))
		);

		// Some options need a special treatment
		if (isset($_POST['options'])){
			if (!empty($_POST['options']['ignore_users'])){
				// Make sure all the users exist in the system 
				$user_array = wp_slimstat::string_to_array($_POST['options']['ignore_users']);
				$post_data = trim($_POST['options']['ignore_users']);

				if (is_array($user_array) && !empty($post_data)){
					$sql_user_placeholders = implode(', ', array_fill(0, count($user_array), '%s COLLATE utf8_bin'));
					if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users} WHERE user_login IN ($sql_user_placeholders)", $user_array)) == count($user_array)){
						wp_slimstat::$options['ignore_users'] = $_POST['options']['ignore_users'];
					}
					else{
						wp_slimstat_admin::$faulty_fields[] = __('Ignore users (username not found)','wp-slimstat');
					}
				}
			}
			else{
				wp_slimstat::$options['ignore_users'] = '';
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
			else{
				wp_slimstat::$options['ignore_capabilities'] = '';
			}
		}
		break;
	case __('Permissions','wp-slimstat'):
		$options_on_this_page = array(
			'permissions_reports_header' => array('description' => __('Reports','wp-slimstat'), 'type' => 'section_header'),
			'restrict_authors_view' => array('description' => __('Restrict Authors','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option if you want your authors to only see stats related to their own content.','wp-slimstat')),
			'capability_can_view' => array('description' => __('Capability','wp-slimstat'), 'type' => 'text', 'long_description' => __("Specify the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> needed to access the reports (default: <code>activate_plugins</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here below. In this case, the list has precedence over the capability.",'wp-slimstat')),
			'can_view' => array('description' => __('Whitelist','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who should have access to the reports, separated by commas. Administrators are implicitly allowed, so you don't need to list them in here. Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true),
			
			'permissions_config_header' => array('description' => __('Settings','wp-slimstat'), 'type' => 'section_header'),
			'capability_can_admin' => array('description' => __('Capability','wp-slimstat'), 'type' => 'text', 'long_description' => __("Specify the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> required to configure Slimstat (default: <code>activate_plugins</code>). The whitelist here below can be used to override this option for specific users.",'wp-slimstat')),
			'can_admin' => array('description' => __('Whitelist','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who can edit these options, separated by commas. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true)
		);

		// Some options need a special treatment
		if (isset($_POST['options'])){
			if (!empty($_POST['options']['can_view'])){
				// Make sure all the users exist in the system 
				$post_data = trim($_POST['options']['can_view']);
				$user_array = wp_slimstat::string_to_array($_POST['options']['can_view']);

				if (is_array($user_array) && !empty($post_data)){
					$sql_user_placeholders = implode(', ', array_fill(0, count($user_array), '%s COLLATE utf8_bin'));
					if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users} WHERE user_login IN ($sql_user_placeholders)", $user_array)) == count($user_array)){
						wp_slimstat::$options['can_view'] = $_POST['options']['can_view'];
					}
					else{
						wp_slimstat_admin::$faulty_fields[] = __('Read access: username not found','wp-slimstat');
					}
				}
			}
			else{
				wp_slimstat::$options['can_view'] = '';
			}

			if (!empty($_POST['options']['capability_can_view'])){
				if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_view'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
					wp_slimstat::$options['capability_can_view'] = $_POST['options']['capability_can_view'];
				}
				else{
					wp_slimstat_admin::$faulty_fields[] = __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat');
				}
			}
			else{
				wp_slimstat::$options['capability_can_view'] = '';
			}

			if (!empty($_POST['options']['can_admin'])){
				// Make sure all the users exist in the system
				$post_data = trim($_POST['options']['can_admin']);
				$user_array = wp_slimstat::string_to_array($_POST['options']['can_admin']);

				if (is_array($user_array) && !empty($post_data)){
					$sql_user_placeholders = implode(', ', array_fill(0, count($user_array), '%s COLLATE utf8_bin'));
					if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users} WHERE user_login IN ($sql_user_placeholders)", $user_array)) == count($user_array)){
						wp_slimstat::$options['can_admin'] = $_POST['options']['can_admin'];
					}
					else{
						wp_slimstat_admin::$faulty_fields[] = __('Config access: username not found','wp-slimstat');
					}
				}
			}
			else{
				wp_slimstat::$options['can_admin'] = '';
			}
			
			if (!empty($_POST['options']['capability_can_admin'])){
				if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_admin'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
					wp_slimstat::$options['capability_can_admin'] = $_POST['options']['capability_can_admin'];
				}
				else{
					wp_slimstat_admin::$faulty_fields[] = __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat');
				}
			}
			else{
				wp_slimstat::$options['capability_can_admin'] = '';
			}
		}
		break;
	case __('Advanced','wp-slimstat'):
		$this_domain = parse_url(get_bloginfo('url'));
		$encoded_ci = 'YTo0OntzOjEyOiJjb250ZW50X3R5cGUiO3M6ODoiZXh0ZXJuYWwiO3M6ODoiY2F0ZWdvcnkiO3M6MDoiIjtzOjEwOiJjb250ZW50X2lkIjtpOjA7czo2OiJhdXRob3IiO3M6MTM6ImV4dGVybmFsLXBhZ2UiO30=';
		$encoded_ci = $encoded_ci.'.'.md5($encoded_ci.wp_slimstat::$options['secret']);

		$options_on_this_page = array(
			'advanced_tracker_header' => array('description' => __('Tracker','wp-slimstat'), 'type' => 'section_header'),
			'detect_smoothing' => array('description' => __('Detect Smoothing','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Detect if your visitors' browsers support anti-aliasing (font smoothing). This option required Spy Mode to be enabled.",'wp-slimstat')),
			'enable_outbound_tracking' => array('description' => __('Track Outbound Clicks','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Track when your visitors click on link to external websites. This option required Spy Mode to be enabled.','wp-slimstat')),
			'session_duration' => array('description' => __('Session Duration','wp-slimstat'), 'type' => 'integer', 'long_description' => __('How many seconds should a human session last? Google Analytics sets it to 1800 seconds.','wp-slimstat'), 'after_input_field' => __('seconds','wp-slimstat')),
			'extend_session' => array('description' => __('Extend Session','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Extend the duration of a session each time the user visits a new page.','wp-slimstat')),
			'enable_cdn' => array('description' => __('Enable CDN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Use <a href='http://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving our tracking code from their fast and reliable network (free service).",'wp-slimstat')),
			'extensions_to_track' => array('description' => __('Extensions to Track','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the file extensions that you want to be treated as Downloads. Please note that links pointing to external resources (i.e. PDFs on a different website) are considered Downloads and not Outbound Links (and tracked as such), if their extension matches one of the ones listed here below.",'wp-slimstat')),

			'advanced_external_pages_header' => array('description' => __('External Pages','wp-slimstat'), 'type' => 'section_header'),
			'external_pages_script' => array('type' => 'static', 'skip_update' => 'yes', 'description' => __('Add the following code to all the non-WP pages you want to track','wp-slimstat'), 'long_description' => '&lt;script type="text/javascript"&gt;
/* &lt;![CDATA[ */
var SlimStatParams = {
	ajaxurl: "'.admin_url('admin-ajax.php').'",
	ci: "'.$encoded_ci.'",
	extensions_to_track: "'.wp_slimstat::$options['extensions_to_track'].'"
};
/* ]]&gt; */
&lt;/script&gt;
&lt;script type="text/javascript" src="http://cdn.jsdelivr.net/wp-slimstat/'.wp_slimstat::$version.'/wp-slimstat.js"&gt;&lt;/script&gt;'),

			'advanced_misc_header' => array('description' => __('Miscellaneous','wp-slimstat'), 'type' => 'section_header'),
			'show_sql_debug' => array('description' => __('Debug Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Display the SQL queries used to retrieve the data.','wp-slimstat')),
			'ip_lookup_service' => array('description' => __('IP Lookup','wp-slimstat'), 'type' => 'text', 'long_description' => __('Customize the Geolocation service to be used in the reports.','wp-slimstat')),
			'custom_css' => array('description' => __('Custom CSS','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Paste here your custom stylesheet to personalize the way your reports look. <a href='http://wordpress.org/plugins/wp-slimstat/faq/' target='_blank'>Check the FAQ</a> for more information on how to use this setting.",'wp-slimstat')),
			'enable_ads_network' => array('description' => __('Enable UAN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Send anonymous data about user agents to our server for analysis. This allows us to contribute to the <a href='http://browscap.org/' target='_blank'>BrowsCap opensource project</a>, and improve the accuracy of Slimstat's browser detection functionality. It also enables our transparent ads network. No worries, your site will not be affected in any way.",'wp-slimstat'))
		);
		break;
	case __('Maintenance','wp-slimstat'):
		include_once(dirname(__FILE__).'/maintenance.php');
		break;
	default:
		break;
}

if (has_filter('slimstat_options_on_page') && $config_tabs[$current_tab-1] == __('Add-ons','wp-slimstat')){
	$options_on_this_page = apply_filters('slimstat_options_on_page', $options_on_this_page);
}

if (isset($options_on_this_page)){
	wp_slimstat_admin::update_options($options_on_this_page);
	wp_slimstat_admin::display_options($options_on_this_page, $current_tab); 
}
echo '</div>';
