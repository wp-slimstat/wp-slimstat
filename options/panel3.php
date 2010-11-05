<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['ignore_ip']) && !slimstat_update_option('ignore_ip', $_POST['options']['ignore_ip'], 'list')) $faulty_fields .= __('Ignore IPs','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_resources']) && !slimstat_update_option('ignore_resources', $_POST['options']['ignore_resources'], 'list')) $faulty_fields .= __('Ignore resources','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_browsers']) && !slimstat_update_option('ignore_browsers', $_POST['options']['ignore_browsers'], 'list')) $faulty_fields .= __('Ignore browsers','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_users']) && !slimstat_update_option('ignore_users', $_POST['options']['ignore_users'], 'list')) $faulty_fields .= __('Ignore users','wp-slimstat-options').', ';
	
	slimstat_error_message($faulty_fields);
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=3" method="post">
<h3><label for="ignore_ip"><?php _e('IP addresses to ignore','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of networks you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://lab.duechiacchiere.it/index.php?topic=26.0' target='_blank'>CIDR notation</a> (i.e. <em>192.168.1.1/24</em>). If the format is incorrect, WP SlimStat may not track pageviews properly.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_ip]" id="ignore_ip"><?php echo implode(', ', slimstat_get_option('ignore_ip',array())) ?></textarea></p>

<h3><label for="ignore_resources"><?php _e('Pages and posts to ignore','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of permalinks you don't want to track, separated by commas. You should omit the domain name from these resources: <em>/about, ?p=1, etc. WP SlimStat will ignore all the pageviews whose permalink <strong>starts</strong> with any of them.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_resources]" id="ignore_resources"><?php echo implode(', ', slimstat_get_option('ignore_resources',array())) ?></textarea></p>

<h3><label for="ignore_browsers"><?php _e('Browsers to ignore','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of browsers you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). WP SlimStat will ignore all the browsers whose identification string <strong>starts</strong> with one of these.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_browsers]" id="ignore_browsers"><?php echo implode(', ', slimstat_get_option('ignore_browsers',array())) ?></textarea></p>

<h3><label for="ignore_users"><?php _e('Users to ignore','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of Wordpress users you don't want to track, separated by commas.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_users]" id="ignore_users"><?php echo implode(', ', slimstat_get_option('ignore_users',array())) ?></textarea></p>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>