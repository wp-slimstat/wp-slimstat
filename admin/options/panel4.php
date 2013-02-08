<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$options_on_this_page = array(
	'restrict_authors_view' => array('description' => __('Restrict Authors','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Enable this option if you want your authours to only see stats related to their own content.','wp-slimstat-options')),
	'capability_can_view' => array('description' => __('Minimum capability','wp-slimstat-options'), 'type' => 'text', 'long_description' => __("Define the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> needed to view the reports (default: <code>read</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here above. In this case, the list has precedence over the capability.",'wp-slimstat-options')),
	'can_view' => array('description' => __('Read access','wp-slimstat-options'), 'type' => 'textarea', 'long_description' => __("List all the users who can view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all your users</strong> are granted access. Usernames are case sensitive.",'wp-slimstat-options'), 'skip_update' => true),
	'can_admin' => array('description' => __('Config access','wp-slimstat-options'), 'type' => 'textarea', 'long_description' => __("List all the users who can edit these options, separated by commas. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all your users</strong> (except <em>Subscribers</em>) will be granted access. Users listed here automatically inherit 'Read access' to the reports. Usernames are case sensitive.",'wp-slimstat-options'), 'skip_update' => true)
);

// Some options need a special treatment
if (isset($_POST['options'])){
	if (!empty($_POST['options']['can_view'])){
		// Make sure all the users exist in the system 
		$user_array = wp_slimstat::string_to_array($_POST['options']['can_view']);
		$sql_user_list = "'".implode("' COLLATE utf8_bin,'", $user_array)."' COLLATE utf8_bin";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!wp_slimstat_admin::update_option('can_view', $_POST['options']['can_view'], 'text')) wp_slimstat_admin::$faulty_fields[] = __('Read access','wp-slimstat-options');
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __('Read access: username not found','wp-slimstat-options');
		}
	}
	else{
		wp_slimstat_admin::update_option('can_view', '', 'text');
	}

	if (!empty($_POST['options']['capability_can_view'])){
		if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_view'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
			if (!wp_slimstat_admin::update_option('capability_can_view', $_POST['options']['capability_can_view'], 'text')) wp_slimstat_admin::$faulty_fields[] = __('Minimum capability','wp-slimstat-options');
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat-options');
		}
	}
	else{
		wp_slimstat_admin::update_option('capability_can_view', '', 'text');
	}

	if (!empty($_POST['options']['can_admin'])){
		// Make sure all the users exist in the system 
		$user_array = wp_slimstat::string_to_array($_POST['options']['can_admin']);
		$sql_user_list = "'".implode("' COLLATE utf8_bin,'", $user_array)."' COLLATE utf8_bin";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!wp_slimstat_admin::update_option('can_admin', $_POST['options']['can_admin'], 'text')) wp_slimstat_admin::$faulty_fields[] = __('Config access','wp-slimstat-options');
		}
		else{
			wp_slimstat_admin::$faulty_fields[] = __('Config access: username not found','wp-slimstat-options');
		}
	}
	else{
		wp_slimstat_admin::update_option('can_admin', '', 'text');
	}
}
