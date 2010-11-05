<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['can_view']) && !slimstat_update_option('can_view', $_POST['options']['can_view'], 'list')) $faulty_fields .= __('Who can view the reports','wp-slimstat-options').', ';
	if (isset($_POST['options']['can_admin']) && !slimstat_update_option('can_admin', $_POST['options']['can_admin'], 'list')) $faulty_fields .= __('Who can manage these options','wp-slimstat-options').', ';
	
	slimstat_error_message($faulty_fields);
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=4" method="post">
<h3><label for="can_view"><?php _e('Who can view the reports','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of users who are allowed to view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all</strong> users (but subscribers) will have access to the reports.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_view]" id="can_view"><?php echo implode(', ', slimstat_get_option('can_view',array())) ?></textarea></p>

<h3><label for="can_admin"><?php _e('Who can manage these options','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of users who are allowed to update these options. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all</strong> users (but subscribers) will have access to the options panel.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_admin]" id="can_admin"><?php echo implode(', ', slimstat_get_option('can_admin',array())) ?></textarea></p>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>