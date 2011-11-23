<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['ignore_bots']) && !slimstat_update_option('ignore_bots', $_POST['options']['ignore_bots'], 'yesno')) $faulty_fields .= __('Ignore bots','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_spammers']) && !slimstat_update_option('ignore_spammers', $_POST['options']['ignore_spammers'], 'yesno')) $faulty_fields .= __('Ignore Spammers','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_prefetch']) && !slimstat_update_option('ignore_prefetch', $_POST['options']['ignore_prefetch'], 'yesno')) $faulty_fields .= __('Ignore Prefetch','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_interval']) && !slimstat_update_option('ignore_interval', $_POST['options']['ignore_interval'], 'integer')) $faulty_fields .= __('Ignore interval','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_ip']) && !slimstat_update_option('ignore_ip', $_POST['options']['ignore_ip'], 'list')) $faulty_fields .= __('Ignore IPs','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_countries']) && !slimstat_update_option('ignore_countries', $_POST['options']['ignore_countries'], 'list')) $faulty_fields .= __('Ignore Countries','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_resources']) && !slimstat_update_option('ignore_resources', $_POST['options']['ignore_resources'], 'list')) $faulty_fields .= __('Ignore resources','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_referers']) && !slimstat_update_option('ignore_referers', $_POST['options']['ignore_referers'], 'list')) $faulty_fields .= __('Ignore referers','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_browsers']) && !slimstat_update_option('ignore_browsers', $_POST['options']['ignore_browsers'], 'list')) $faulty_fields .= __('Ignore browsers','wp-slimstat-options').', ';
	if (!empty($_POST['options']['ignore_users'])){
		// Make sure all the users exist in the system 
		$user_array = explode(',', $_POST['options']['ignore_users']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!slimstat_update_option('ignore_users', $_POST['options']['ignore_users'], 'list')) $faulty_fields .= __('Ignore users','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Ignore users (username not found)','wp-slimstat-options').', ';
		}
	}
	else{
		slimstat_update_option('ignore_users', '', 'list');
	}
	slimstat_error_message($faulty_fields);
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=3" method="post">
<h3><label for="ignore_ip"><?php _e('General settings','wp-slimstat-options') ?></label></h3>
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr>
		<th scope="row"><label for="ignore_bots"><?php _e('Ignore bots','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[ignore_bots]" id="ignore_bots" value="yes"<?php echo (slimstat_get_option('ignore_bots','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[ignore_bots]" value="no" <?php echo (slimstat_get_option('ignore_bots','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('Do not track visits from crawlers, search engine spiders and other bots','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_spammers"><?php _e('Ignore Spammers','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[ignore_spammers]" id="ignore_spammers" value="yes"<?php echo (slimstat_get_option('ignore_spammers','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[ignore_spammers]" value="no" <?php echo (slimstat_get_option('ignore_spammers','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('Do not track visits from users identified as spammers by a third-party tool (i.e. Akismet)','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_spammers"><?php _e('Ignore Prefetch','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[ignore_prefetch]" id="ignore_prefetch" value="yes"<?php echo (slimstat_get_option('ignore_prefetch','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[ignore_prefetch]" value="no" <?php echo (slimstat_get_option('ignore_prefetch','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e("Do not track visits generated by Firefox' <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>" ,'wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_interval"><?php _e('Latency','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" name="options[ignore_interval]" id="ignore_interval" value="<?php echo slimstat_get_option('ignore_interval','30'); ?>" size="4"> <?php _e('seconds','wp-slimstat-options') ?>
			<span class="description"><?php _e('Do not track visits identical to an existing one recorded less than <strong>X</strong> seconds ago. Zero disables this feature.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<h3><label for="ignore_ip"><?php _e('IP addresses','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of networks you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://lab.duechiacchiere.it/index.php?topic=26.0' target='_blank'>CIDR notation</a> (i.e. <em>192.168.1.1/24</em>). If the format is incorrect, WP SlimStat may not track pageviews properly.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_ip]" id="ignore_ip"><?php echo implode(',', slimstat_get_option('ignore_ip',array())) ?></textarea></p>

<h3><label for="ignore_resources"><?php _e('Pages and posts','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of permalinks you don't want to track, separated by commas. You should omit the domain name from these resources: <em>/about, ?p=1</em>, etc. WP SlimStat will ignore all the pageviews whose permalink <strong>contains</strong> at least one of them.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_resources]" id="ignore_resources"><?php echo implode(',', slimstat_get_option('ignore_resources',array())) ?></textarea></p>

<h3><label for="ignore_countries"><?php _e('Countries','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of Country codes (i.e.: <code>en-us, it, es</code>) you don't want to track, separated by commas.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_countries]" id="ignore_countries"><?php echo implode(',', slimstat_get_option('ignore_countries',array())) ?></textarea></p>

<h3><label for="ignore_browsers"><?php _e('User agents','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of browsers (user agents) you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). WP SlimStat will ignore all the browsers whose identification string <strong>starts</strong> with one of these.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_browsers]" id="ignore_browsers"><?php echo implode(',', slimstat_get_option('ignore_browsers',array())) ?></textarea></p>

<h3><label for="ignore_referers"><?php _e('Referers','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of referring URL's you don't want to track, separated by commas: <code>mysite.com, /ignore-me-please</code>, etc. WP SlimStat will ignore all the referers that <strong>contain</strong> at least one of them.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_referers]" id="ignore_referers"><?php echo implode(',', slimstat_get_option('ignore_referers',array())) ?></textarea></p>

<h3><label for="ignore_users"><?php _e('Users','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of Wordpress users you don't want to track, separated by commas. Please be aware that spaces are <em>not</em> ignored.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_users]" id="ignore_users"><?php echo implode(',', slimstat_get_option('ignore_users',array())) ?></textarea></p>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>