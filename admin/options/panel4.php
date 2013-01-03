<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (!empty($_POST['options']['can_view'])){
		// Make sure all the users exist in the system 
		$user_array = wp_slimstat::string_to_array($_POST['options']['can_view']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!wp_slimstat_admin::update_option('can_view', $_POST['options']['can_view'], 'text')) $faulty_fields .= __('Read access','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Read access: username not found','wp-slimstat-options').', ';
		}
	}
	else{
		wp_slimstat_admin::update_option('can_view', '', 'text');
	}

	if (!empty($_POST['options']['capability_can_view'])){
		if (isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) && array_key_exists($_POST['options']['capability_can_view'], $GLOBALS['wp_roles']->role_objects['administrator']->capabilities)){
			if (!wp_slimstat_admin::update_option('capability_can_view', $_POST['options']['capability_can_view'], 'text')) $faulty_fields .= __('Minimum capability','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Invalid minimum capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat-options').', ';
		}
	}
	else{
		wp_slimstat_admin::update_option('capability_can_view', '', 'text');
	}

	if (!empty($_POST['options']['can_admin'])){
		// Make sure all the users exist in the system 
		$user_array = wp_slimstat::string_to_array($_POST['options']['can_admin']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!wp_slimstat_admin::update_option('can_admin', $_POST['options']['can_admin'], 'text')) $faulty_fields .= __('Config access','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Config access: username not found','wp-slimstat-options').', ';
		}
	}
	else{
		wp_slimstat_admin::update_option('can_admin', '', 'text');
	}
	slimstat_error_message($faulty_fields);
}
?>
<h3><label for="can_view"><?php _e('Read access','wp-slimstat-options') ?></label></h3>
<p><?php _e("List all the users who can view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all your users</strong> are granted access. Usernames are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_view]" id="can_view"><?php echo wp_slimstat::$options['can_view'] ?></textarea></p>

<h3><label for="capability_can_view"><?php _e('Minimum capability','wp-slimstat-options') ?></label></h3>
<p><?php _e("Define the minimum <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> needed to view the reports (default: <code>read</code>). If this field is empty, <strong>all your users</strong> (including subscribers) will have access to the reports, unless a 'Read access' whitelist has been specified here above. In this case, the list has precedence over the capability.",'wp-slimstat-options') ?></p>
<p><input type="text" name="options[capability_can_view]" id="capability_can_view" value="<?php echo wp_slimstat::$options['capability_can_view'] ?>"></p>

<h3><label for="can_admin"><?php _e('Config access','wp-slimstat-options') ?></label></h3>
<p><?php _e("List all the users who can edit these options, separated by commas. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all your users</strong> (except <em>Subscribers</em>) will be granted access. Users listed here automatically inherit 'Read access' to the reports. Usernames are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_admin]" id="can_admin"><?php echo wp_slimstat::$options['can_admin'] ?></textarea></p>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
