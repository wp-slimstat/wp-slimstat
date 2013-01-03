<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['track_users']) && !wp_slimstat_admin::update_option('track_users', $_POST['options']['track_users'], 'yesno')) $faulty_fields .= __('Track users','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_bots']) && !wp_slimstat_admin::update_option('ignore_bots', $_POST['options']['ignore_bots'], 'yesno')) $faulty_fields .= __('Ignore bots','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_spammers']) && !wp_slimstat_admin::update_option('ignore_spammers', $_POST['options']['ignore_spammers'], 'yesno')) $faulty_fields .= __('Ignore Spammers','wp-slimstat-options').', ';
	if (isset($_POST['options']['anonymize_ip']) && !wp_slimstat_admin::update_option('anonymize_ip', $_POST['options']['anonymize_ip'], 'yesno')) $faulty_fields .= __('Anonymize IP Addresses','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_prefetch']) && !wp_slimstat_admin::update_option('ignore_prefetch', $_POST['options']['ignore_prefetch'], 'yesno')) $faulty_fields .= __('Ignore Prefetch','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_ip']) && !wp_slimstat_admin::update_option('ignore_ip', $_POST['options']['ignore_ip'], 'text')) $faulty_fields .= __('Ignore IPs','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_countries']) && !wp_slimstat_admin::update_option('ignore_countries', $_POST['options']['ignore_countries'], 'text')) $faulty_fields .= __('Ignore Countries','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_resources']) && !wp_slimstat_admin::update_option('ignore_resources', $_POST['options']['ignore_resources'], 'text')) $faulty_fields .= __('Ignore resources','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_referers']) && !wp_slimstat_admin::update_option('ignore_referers', $_POST['options']['ignore_referers'], 'text')) $faulty_fields .= __('Ignore referers','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_browsers']) && !wp_slimstat_admin::update_option('ignore_browsers', $_POST['options']['ignore_browsers'], 'text')) $faulty_fields .= __('Ignore browsers','wp-slimstat-options').', ';
	if (!empty($_POST['options']['ignore_users'])){
		// Make sure all the users exist in the system 
		$user_array = wp_slimstat::string_to_array($_POST['options']['ignore_users']);
		$sql_user_list = "'".implode("','", $user_array)."'";
		if ($wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users WHERE user_login IN ($sql_user_list)") == count($user_array)){
			if (!wp_slimstat_admin::update_option('ignore_users', $_POST['options']['ignore_users'], 'text')) $faulty_fields .= __('Ignore users','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Ignore users: username not found','wp-slimstat-options').', ';
		}
	}
	else{
		wp_slimstat_admin::update_option('ignore_users', '', 'text');
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
			if (!wp_slimstat_admin::update_option('ignore_capabilities', $_POST['options']['ignore_capabilities'], 'text')) $faulty_fields .= __('Users by capability','wp-slimstat-options').', ';
		}
		else{
			$faulty_fields .= __('Invalid capability. Please check <a href="http://codex.wordpress.org/Roles_and_Capabilities" target="_new">this page</a> for more information','wp-slimstat-options').', ';
		}
	}
	else{
		wp_slimstat_admin::update_option('ignore_capabilities', '', 'text');
	}
	slimstat_error_message($faulty_fields);
}
?>
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr>
		<th scope="row"><label for="track_users"><?php _e('Track users','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[track_users]" id="track_users" value="yes"<?php echo (wp_slimstat::$options['track_users'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[track_users]" value="no" <?php echo (wp_slimstat::$options['track_users'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Select YES if you want to track logged in users, adding their login to the resource they requested','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_bots"><?php _e('Ignore bots','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[ignore_bots]" id="ignore_bots" value="yes"<?php echo (wp_slimstat::$options['ignore_bots'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[ignore_bots]" value="no" <?php echo (wp_slimstat::$options['ignore_bots'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('If this option is active, visits from crawlers, search engine spiders and other bots will not be tracked','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_spammers"><?php _e('Ignore Spammers','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[ignore_spammers]" id="ignore_spammers" value="yes"<?php echo (wp_slimstat::$options['ignore_spammers'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[ignore_spammers]" value="no" <?php echo (wp_slimstat::$options['ignore_spammers'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e("Select YES if you don't want to track visits from users identified as spammers by a third-party tool (i.e. Akismet)",'wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="anonymize_ip"><?php _e('Anonymize IP Addresses','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[anonymize_ip]" id="anonymize_ip" value="yes"<?php echo (wp_slimstat::$options['anonymize_ip'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[anonymize_ip]" value="no" <?php echo (wp_slimstat::$options['anonymize_ip'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e("This option masks the last octet of your visitors' IP addresses to comply with European Privacy Laws" ,'wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ignore_prefetch"><?php _e('Ignore Prefetch','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[ignore_prefetch]" id="ignore_prefetch" value="yes"<?php echo (wp_slimstat::$options['ignore_prefetch'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[ignore_prefetch]" value="no" <?php echo (wp_slimstat::$options['ignore_prefetch'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e("Select YES if you don't want to track visits generated by Firefox's <a href='https://developer.mozilla.org/en/Link_prefetching_FAQ' target='_blank'>Link Prefetching functionality</a>" ,'wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<h3><label for="ignore_ip"><?php _e('IP addresses','wp-slimstat-options') ?></label></h3>
<p><?php _e("List all the IP addresses you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). If the format is incorrect, WP SlimStat may not track pageviews properly.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_ip]" id="ignore_ip"><?php echo wp_slimstat::$options['ignore_ip'] ?></textarea></p>

<h3><label for="ignore_resources"><?php _e('Pages and posts','wp-slimstat-options') ?></label></h3>
<p><?php _e("List all the URLs you don't want to track, separated by commas. Don't include the domain name: <em>/about, ?p=1</em>, etc. Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. For example, <code>/abou*</code> will match /about and /abound, <code>/abo*t</code> will match /aboundant and /about, <code>/abo!t</code> will match /about and /abort. Strings are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_resources]" id="ignore_resources"><?php echo wp_slimstat::$options['ignore_resources'] ?></textarea></p>

<h3><label for="ignore_countries"><?php _e('Countries','wp-slimstat-options') ?></label></h3>
<p><?php _e("List all the Country codes (i.e.: <code>en-us, it, es</code>) you don't want to track, separated by commas.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_countries]" id="ignore_countries"><?php echo wp_slimstat::$options['ignore_countries'] ?></textarea></p>

<h3><label for="ignore_browsers"><?php _e('User agents','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of browsers (user agents) you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. For example, <code>Chr*</code> will match Chrome and Chromium, <code>IE/!.0</code> will match IE/7.0 and IE/8.0. Strings are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_browsers]" id="ignore_browsers"><?php echo wp_slimstat::$options['ignore_browsers'] ?></textarea></p>

<h3><label for="ignore_referers"><?php _e('Referers','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of referring URL's you don't want to track, separated by commas: <code>mysite.com, /ignore-me-please</code>, etc. Wildcards: <code>*</code> means 'any string, including the empty string', <code>!</code> means 'any character'. Strings are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_referers]" id="ignore_referers"><?php echo wp_slimstat::$options['ignore_referers'] ?></textarea></p>

<h3><label for="ignore_users"><?php _e('Users','wp-slimstat-options') ?></label></h3>
<p><?php _e("Enter a list of Wordpress users you don't want to track, separated by commas. Please be aware that spaces are <em>not</em> ignored and that usernames are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_users]" id="ignore_users"><?php echo wp_slimstat::$options['ignore_users'] ?></textarea></p>

<h3><label for="ignore_capabilities"><?php _e('Users by capability','wp-slimstat-options') ?></label></h3>
<p><?php _e("Users having at least one of the <a href='http://codex.wordpress.org/Roles_and_Capabilities' target='_new'>capabilities</a> listed here below will not be tracked. Capabilities are case-insensitive.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_capabilities]" id="ignore_capabilities"><?php echo wp_slimstat::$options['ignore_capabilities'] ?></textarea></p>

<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>