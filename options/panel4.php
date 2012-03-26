<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (!empty($_POST['options']['can_view'])){
		// Make sure all the users exist in the system 
		$user_array = explode(',', $_POST['options']['can_view']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!$GLOBALS['wp_slimstat']->update_option('can_view', $_POST['options']['can_view'], 'list')) $faulty_fields .= __('Users who can access the reports','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Users who can access the reports (username not found)','wp-slimstat-options').', ';
		}
	}
	else{
		$GLOBALS['wp_slimstat']->update_option('can_view', '', 'list');
	}
	if (isset($_POST['options']['capability_can_view']) && !$GLOBALS['wp_slimstat']->update_option('capability_can_view', $_POST['options']['capability_can_view'], 'text')) $faulty_fields .= __('Roles and capabilities','wp-slimstat-options').', ';
	if (!empty($_POST['options']['can_admin'])){
		// Make sure all the users exist in the system 
		$user_array = explode(',', $_POST['options']['can_admin']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!$GLOBALS['wp_slimstat']->update_option('can_admin', $_POST['options']['can_admin'], 'list')) $faulty_fields .= __('Users who can configure WP SlimStat','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Users who can configure WP SlimStat (username not found)','wp-slimstat-options').', ';
		}
	}
	else{
		$GLOBALS['wp_slimstat']->update_option('can_admin', '', 'list');
	}
	slimstat_error_message($faulty_fields);
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=4" method="post">
<h3><label for="can_view"><?php _e('Users who can access the reports','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of users allowed to view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all</strong> users (including subscribers) will have access to the reports.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_view]" id="can_view"><?php echo implode(',', $GLOBALS['wp_slimstat']->options['can_view']) ?></textarea></p>

<h3><label for="capability_can_view"><?php _e('Roles and capabilities','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter the lowest <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capability</a> that users need to have in order to access all the stats (<code>read</code> is the default value). If this field is empty, <strong>all</strong> users (including subscribers) will have access to the reports.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[capability_can_view]" id="capability_can_view"><?php echo $GLOBALS['wp_slimstat']->options['capability_can_view'] ?></textarea></p>

<h3><label for="can_admin"><?php _e('Users who can configure WP SlimStat','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of users allowed to edit these options. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all</strong> users (but subscribers) will have access to the options panel.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_admin]" id="can_admin"><?php echo implode(',', $GLOBALS['wp_slimstat']->options['can_admin']) ?></textarea></p>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>