<?php

// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Define the tabs
$slimtabs = '';
$current_tab = empty($_GET['tab'])?1:intval($_GET['tab']);
$config_tabs = apply_filters('slimstat_config_tabs', array(__('General','wp-slimstat'),__('Views','wp-slimstat'),__('Filters','wp-slimstat'),__('Permissions','wp-slimstat'),__('Advanced','wp-slimstat'),has_filter('slimstat_options_on_page')?__('Add-ons','wp-slimstat'):'none',__('Maintenance','wp-slimstat'),__('Support','wp-slimstat')));
foreach ($config_tabs as $a_tab_id => $a_tab_name){
	if ($a_tab_name != 'none') $slimtabs .= "<a class='nav-tab nav-tab".(($current_tab == $a_tab_id+1)?'-active':'-inactive')."' href='".wp_slimstat_admin::$config_url.($a_tab_id+1)."'>$a_tab_name</a>";
}

echo '<div class="wrap"><!-- div id="analytics-icon" class="icon32 '.$GLOBALS['wp_locale']->text_direction.'"></div --><h2>WP SlimStat</h2><p class="nav-tabs">'.$slimtabs.'</p>';

switch ($config_tabs[$current_tab-1]){
	case __('General','wp-slimstat'):
		$options_on_this_page = array(
			'is_tracking' => array( 'description' => __('Activate tracking','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('You may want to prevent WP SlimStat from tracking users, but still be able to access your stats.','wp-slimstat') ),
			'track_admin_pages' => array( 'description' => __('Track Admin Pages','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option if you want to track your users' activity within the admin.",'wp-slimstat') ),
			'javascript_mode' => array( 'description' => __('Javascript Mode','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Turn this feature on if you are using a caching plugin (W3 Total Cache, WP SuperCache, HyperCache, etc). WP SlimStat will behave pretty much like Google Analytics, and visitors whose browser does not support Javascript will be ignored. A nice side effect is that <strong>most</strong> spammers, search engines and other crawlers will not be tracked.','wp-slimstat') ),
			'auto_purge' => array( 'description' => __('Store Data For','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.','wp-slimstat').(wp_get_schedule('wp_slimstat_purge')?' <br> '.__('Next clean-up on','wp-slimstat').' '.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')).'. '.sprintf(__('Entries recorded on or before %s will be permanently deleted.','wp-slimstat'), date_i18n(get_option('date_format'), strtotime('-'.wp_slimstat::$options['auto_purge'].' days'))):''), 'after_input_field' => __('days','wp-slimstat') ),
			'add_posts_column' => array( 'description' => __('Add Column to Posts','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Add a new column to the Edit Posts screen, with the number of hits per post (may slow down page rendering).','wp-slimstat') ),
			'use_separate_menu' => array( 'description' => __('Menu Position','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Lets you decide if you want to have a standalone admin menu for WP SlimStat or a drop down in the admin bar.','wp-slimstat'), 'custom_label_yes' => __('Side Menu','wp-slimstat'), 'custom_label_no' => __('Admin Bar','wp-slimstat') )
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
			'convert_ip_addresses' => array('description' => __('Convert IP Addresses','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Display hostnames instead of IP addresses. It slows down the rendering of your metrics.','wp-slimstat')),
			'async_load' => array('description' => __('Asynchronous Views','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enables Ajax to load all the stats at runtime. It makes the panels render faster, but it increases the load on your server.','wp-slimstat')),
			'use_european_separators' => array('description' => __('Number Format','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose what number format you want to use, European or American.','wp-slimstat'), 'custom_label_yes' => '1.234,56', 'custom_label_no' => '1,234.56'),
			'show_display_name' => array('description' => __('Show Display Name','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Users are listed by their usernames, by default. Use this option to switch to their display names instead.','wp-slimstat')),
			'rows_to_show' => array('description' => __('Limit Results to','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Specify the number of results to return for each module. Please use a <strong>positive</strong> value.','wp-slimstat')),
			'expand_details' => array('description' => __('Expand Details','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Expand each row's details by default, insted of on mousehover.",'wp-slimstat')),
			'number_results_raw_data' => array('description' => __('Right Now Rows','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Set the number of rows that will displayed under the Right Now panel','wp-slimstat')),
			'include_outbound_links_right_now' => array('description' => __('Right Now Extended','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to see outbound links listed under Right Now. It might make the rendering of this report slower or give errors on slower systems.','wp-slimstat')),
			'ip_lookup_service' => array('description' => __('IP Lookup','wp-slimstat'), 'type' => 'text', 'long_description' => __('Customize the IP lookup service URL.','wp-slimstat')),
			'refresh_interval' => array('description' => __('Refresh Every','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Refresh the Right Now screen every X seconds. Zero disables this feature.','wp-slimstat')),
			'hide_stats_link_edit_posts' => array('description' => __('Hide Stats Link','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option if your users are confused by the Stats link associate to each post in the Edit Posts page.','wp-slimstat')),
			'show_complete_user_agent_tooltip' => array('description' => __('Show User Agent','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Choose if you want to see the browser name or a complete user agent string when hovering on browser icons.','wp-slimstat'))
			
		);
		break;
	case __('Filters','wp-slimstat'):
		$options_on_this_page = array(
			'track_users' => array('description' => __('Track users','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Select YES if you want to track logged in users.','wp-slimstat')),
			'ignore_spammers' => array('description' => __('Ignore Spammers','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this option if you don't want to track visits from users identified as spammers by a third-party tool (i.e. Akismet). Visits from people whose comments are later marked as spam by you, will also be removed from the database.",'wp-slimstat')),
			'anonymize_ip' => array('description' => __('Anonymize IP Addresses','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("This option masks the last octet of your visitors' IP addresses to comply with European Privacy Laws.",'wp-slimstat')),
			'ignore_prefetch' => array('description' => __('Filter Prefetch','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enable this filter if you want to prevent WP SlimStat from tracking pageviews generated by Firefox's <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>.",'wp-slimstat')),
			'ignore_ip' => array('description' => __('IP Addresses','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("IP addresses that you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). If the format is incorrect, WP SlimStat may not track pageviews properly.",'wp-slimstat')),
			'ignore_resources' => array('description' => __('Permalinks','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("URLs from your website that you don't want to track, separated by commas. Don't include the domain name: <em>/about, ?p=1</em>, etc. Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. For example, <code>/abou*</code> will match /about and /abound, <code>/abo*t</code> will match /aboundant and /about, <code>/abo!t</code> will match /about and /abort. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_countries' => array('description' => __('Countries','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Country codes (i.e.: <code>en-us, it, es</code>) that you don't want to track, separated by commas.",'wp-slimstat')),
			'ignore_browsers' => array('description' => __('User Agents','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Browsers (user agents) you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. For example, <code>Chr*</code> will match Chrome and Chromium, <code>IE/!.0</code> will match IE/7.0 and IE/8.0. Strings are case-insensitive.",'wp-slimstat')),
			'ignore_referers' => array('description' => __('Referring Sites','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Referring URLs that you don't want to track, separated by commas: <code>http://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. Strings are case-insensitive. Please include either a wildcard or the protocol you want to filter (http://, https://).",'wp-slimstat')),
			'ignore_users' => array('description' => __('Users','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Wordpress users you don't want to track, separated by commas. Please be aware that spaces are <em>not</em> ignored and that usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true),
			'ignore_capabilities' => array('description' => __('Users by Capability','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Users having at least one of the <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capabilities</a> listed here below will not be tracked. Capabilities are case-insensitive.",'wp-slimstat'), 'skip_update' => true)
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
			'restrict_authors_view' => array('description' => __('Restrict Authors','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enable this option if you want your authours to only see stats related to their own content.','wp-slimstat')),
			'capability_can_view' => array('description' => __('Capability to View','wp-slimstat'), 'type' => 'text', 'long_description' => __("Define the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> needed to view the reports (default: <code>read</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here below. In this case, the list has precedence over the capability.",'wp-slimstat')),
			'capability_can_admin' => array('description' => __('Capability to Admin','wp-slimstat'), 'type' => 'text', 'long_description' => __("Define the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> required to access these option scrrens (default: <code>activate_plugins</code>). The whitelist here below can be used to override this option for specific users.",'wp-slimstat')),
			'can_view' => array('description' => __('Read access','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who can view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all your users</strong> are granted access. Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true),
			'can_admin' => array('description' => __('Config access','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("List all the users who can edit these options, separated by commas. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all your users</strong> (except <em>Subscribers</em>) will be granted access. Users listed here automatically inherit 'Read access' to the reports. Usernames are case sensitive.",'wp-slimstat'), 'skip_update' => true)
			
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
		$options_on_this_page = array(
			'enable_javascript' => array('description' => __('Track Browser Capabilities','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Enables a client-side tracking code to collect data about screen resolutions, outbound links, downloads and other relevant information. If Javascript Mode is enabled, browers capabilities will be tracked regardless of which value you set for this option.','wp-slimstat')),
			'detect_smoothing' => array('description' => __('Detect Smoothing','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Activates a client-side function to detect if the visitor's browser supports anti-aliasing (font smoothing). If Browser Capabilities are not tracked, this setting is ignored.",'wp-slimstat')),
			'enable_outbound_tracking' => array('description' => __('Track Outbound Clicks','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Hooks a javascript event handler to each external link on your site, to track when visitors click on them. If Browser Capabilities is disabled, outbound clicks <strong>will not</strong> be tracked regardless of which value you set for this option.','wp-slimstat')),
			'session_duration' => array('description' => __('Session Duration','wp-slimstat'), 'type' => 'integer', 'long_description' => __('Defines how many seconds a visit should last. Google Analytics sets its duration to 1800 seconds.','wp-slimstat'), 'after_input_field' => __('seconds','wp-slimstat')),
			'extend_session' => array('description' => __('Extend Session','wp-slimstat'), 'type' => 'yesno', 'long_description' => __('Extends the duration of a session each time the user visits a new page, by the number of seconds set here above.','wp-slimstat')),
			'enable_cdn' => array('description' => __('Enable CDN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Enables <a href='http://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving WP SlimStat's Javascript tracker from their fast and reliable network.",'wp-slimstat')),
			'extensions_to_track' => array('description' => __('Extensions to Track','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("The following file extensions (to be listed as comma separated values) will be tracked as Downloads by WP SlimStat. Please note that links pointing to external resources (i.e. PDFs on a different website) are considered Downloads and not Outbound Links (and tracked as such), if their extension matches one of the ones listed here below.",'wp-slimstat')),
			'custom_css' => array('description' => __('Custom CSS','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Paste here your custom stylesheet definitions, to personalize the way your reports look, including color-coded pageviews, font sizes, etc.",'wp-slimstat')),
			'markings' => array('description' => __('Chart Annotations','wp-slimstat'), 'type' => 'textarea', 'long_description' => __("Add <em>markings</em> to each chart by specifying a date and its description in the field below. Useful to keep track of special events and correlate them to your analytics. Please use the following format:<code>YYYY MM DD HH:mm=Description 1,YYYY MM DD HH:mm=Description 2</code>. For example: 2012 12 31 23:55=New Year's Eve.",'wp-slimstat')),
			'enable_ads_network' => array('description' => __('Enable UAN','wp-slimstat'), 'type' => 'yesno', 'long_description' => __("Collect data about unknown user agents, and send it anonymously to our server for analysis. This allows us to contribute to the <a href='http://browscap.co/' target='_blank'>BrowsCap opensource project</a>, and improve the accuracy of SlimStat's browser detection functionality.",'wp-slimstat'))
		);
		break;
	case __('Maintenance','wp-slimstat'):
		include_once(dirname(__FILE__).'/maintenance.php');
		break;
	case __('Support','wp-slimstat'):
		include_once(dirname(__FILE__).'/support.php');
		break;
	case __('Add-ons','wp-slimstat'):
		$options_on_this_page = array();
		break;
	default:
		break;
}

if (isset($options_on_this_page)){
	if (has_filter('slimstat_options_on_page') && $config_tabs[$current_tab-1] == __('Add-ons','wp-slimstat')) $options_on_this_page = apply_filters('slimstat_options_on_page', $options_on_this_page);
	wp_slimstat_admin::update_options($options_on_this_page); 
	wp_slimstat_admin::display_options($options_on_this_page, $current_tab); 
}

echo '</div>';
