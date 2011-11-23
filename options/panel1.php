<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['is_tracking']) && !slimstat_update_option('is_tracking', $_POST['options']['is_tracking'], 'yesno')) $faulty_fields = __('Activate tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_javascript']) && !slimstat_update_option('enable_javascript', $_POST['options']['enable_javascript'], 'yesno')) $faulty_fields = __('Enable JS Tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['custom_js_path']) && !slimstat_update_option('custom_js_path', $_POST['options']['custom_js_path'], 'text')) $faulty_fields = __('Custom path','wp-slimstat-options').', ';
	if (isset($_POST['options']['browscap_autoupdate']) && !slimstat_update_option('browscap_autoupdate', $_POST['options']['browscap_autoupdate'], 'yesno')) $faulty_fields = __('Autoupdate DB','wp-slimstat-options').', ';
	if (isset($_POST['options']['track_users']) && !slimstat_update_option('track_users', $_POST['options']['track_users'], 'yesno')) $faulty_fields .= __('Track users','wp-slimstat-options').', ';	
	if (isset($_POST['options']['auto_purge']) && !slimstat_update_option('auto_purge', $_POST['options']['auto_purge'], 'integer')) $faulty_fields .= __('Auto purge','wp-slimstat-options').', ';
	if (isset($_POST['options']['use_separate_menu']) && !slimstat_update_option('use_separate_menu', $_POST['options']['use_separate_menu'], 'yesno')) $faulty_fields .= __('Use separate menu','wp-slimstat-options').', ';

	slimstat_error_message($faulty_fields);
}

// If autopurge = 0, we can unschedule our cron job. If autopurge > 0 and the hook was not scheduled, we schedule it
if (isset($_POST['options']['auto_purge'])){
	if ($_POST['options']['auto_purge'] == 0){
		wp_clear_scheduled_hook('wp_slimstat_purge');
	}
	else if (wp_next_scheduled( 'my_schedule_hook' ) == 0){
		wp_schedule_event(time(), 'daily', 'wp_slimstat_purge');
	}
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=1" method="post">
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr>
		<th scope="row"><label for="is_tracking"><?php _e('Activate tracking','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[is_tracking]" id="is_tracking" value="yes"<?php echo (slimstat_get_option('is_tracking', 'yes') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[is_tracking]" value="no" <?php echo (slimstat_get_option('is_tracking', 'yes') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('You may want to stop WP SlimStat from tracking users for a while, but still be able to access your metrics.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="enable_javascript"><?php _e('Enable JS Tracking','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[enable_javascript]" id="ignore_bots" value="yes"<?php echo (slimstat_get_option('enable_javascript','yes') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[enable_javascript]" value="no" <?php echo (slimstat_get_option('enable_javascript','yes') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('Adds a javascript code to your pages to track visits, screen resolutions, outbound links, downloads and more','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="custom_js_path"><?php _e('Custom path','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" class="longtext" name="options[custom_js_path]" id="custom_js_path" value="<?php echo slimstat_get_option('custom_js_path', get_option('siteurl').'/wp-content/plugins/wp-slimstat'); ?>" size="50">
			<span class="description"><?php _e('If you moved <code>wp-slimstat-js.php</code> out of the original folder, specify here the new path. Default:','wp-slimstat-options'); echo " <code>".get_option('siteurl')."/wp-content/plugins/wp-slimstat</code>"; ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="browscap_autoupdate"><?php _e('Autoupdate DB','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[browscap_autoupdate]" id="ignore_bots" value="yes"<?php echo (slimstat_get_option('browscap_autoupdate','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[browscap_autoupdate]" value="no" <?php echo (slimstat_get_option('browscap_autoupdate','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e("Enables Browscap's autoupdate feature. Please make sure your <code>cache</code> subfolder is writable.",'wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="track_users"><?php _e('Track users','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[track_users]" id="track_users" value="yes"<?php echo (slimstat_get_option('track_users','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[track_users]" value="no" <?php echo (slimstat_get_option('track_users','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>			
			<span class="description"><?php _e('Tracks logged in users, adding their login to the resource they requested','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="auto_purge"><?php _e('Autopurge','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" name="options[auto_purge]" id="auto_purge" value="<?php echo slimstat_get_option('auto_purge','0'); ?>" size="4"> <?php _e('days','wp-slimstat-options') ?>
			<?php if (wp_get_schedule('wp_slimstat_purge')) echo '&mdash; '.__('Next purge is scheduled on','wp-slimstat-options').' '.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')); ?>
			<span class="description"><?php _e('Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="use_separate_menu"><?php _e('Use separate menu','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[use_separate_menu]" id="use_separate_menu" value="yes"<?php echo (slimstat_get_option('use_separate_menu','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>  &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[use_separate_menu]" value="no" <?php echo (slimstat_get_option('use_separate_menu','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('Lets you decide if you want to have a separate admin menu for WP SlimStat or not.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>