<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['enable_javascript']) && !wp_slimstat_admin::update_option('enable_javascript', $_POST['options']['enable_javascript'], 'yesno')) $faulty_fields = __('Track Browser Capabilities','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_outbound_tracking']) && !wp_slimstat_admin::update_option('enable_outbound_tracking', $_POST['options']['enable_outbound_tracking'], 'yesno')) $faulty_fields = __('Track Outbound Clicks','wp-slimstat-options').', ';
	if (isset($_POST['options']['session_duration']) && !wp_slimstat_admin::update_option('session_duration', $_POST['options']['session_duration'], 'integer')) $faulty_fields .= __('Session Duration','wp-slimstat-options').', ';
	if (isset($_POST['options']['extend_session']) && !wp_slimstat_admin::update_option('extend_session', $_POST['options']['extend_session'], 'yesno')) $faulty_fields = __('Extend Session','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_cdn']) && !wp_slimstat_admin::update_option('enable_cdn', $_POST['options']['enable_cdn'], 'yesno')) $faulty_fields = __('Enable CDN','wp-slimstat-options').', ';

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
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr>
		<th scope="row"><label for="enable_javascript"><?php _e('Track Browser Capabilities','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[enable_javascript]" id="enable_javascript" value="yes"<?php echo (wp_slimstat::$options['enable_javascript'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[enable_javascript]" value="no" <?php echo (wp_slimstat::$options['enable_javascript'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Enables a client-side tracking code to collect data about screen resolutions, outbound links, downloads and other relevant information. If Javascript Mode is enabled, browers capabilities will be tracked regardless of which value you set for this option.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="enable_outbound_tracking"><?php _e('Track Outbound Clicks','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[enable_outbound_tracking]" id="enable_outbound_tracking" value="yes"<?php echo (wp_slimstat::$options['enable_outbound_tracking'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[enable_outbound_tracking]" value="no" <?php echo (wp_slimstat::$options['enable_outbound_tracking'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Adds a javascript event handler to each external link on your site, to track when visitors click on them. If Browser Capabilities is disabled, outbound clicks <strong>will not</strong> be tracked regardless of which value you set for this option.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="session_duration"><?php _e('Session Duration','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="text" name="options[session_duration]" id="session_duration" value="<?php echo wp_slimstat::$options['session_duration'] ?>" size="4"> <?php _e('seconds','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Defines how many seconds a visit should last. Google Analytics sets its duration to 1800 seconds.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="extend_session"><?php _e('Extend Session','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[extend_session]" id="extend_session" value="yes"<?php echo (wp_slimstat::$options['extend_session'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[extend_session]" value="no" <?php echo (wp_slimstat::$options['extend_session'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Extends the duration of a session each time the user visits a new page, by the number of seconds set here above.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="enable_cdn"><?php _e('Enable CDN','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[enable_cdn]" id="enable_cdn" value="yes"<?php echo (wp_slimstat::$options['enable_cdn'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[enable_cdn]" value="no" <?php echo (wp_slimstat::$options['enable_cdn'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Enables <a href="http://www.jsdelivr.com/" target="_blank">JSDelivr</a>\'s CDN, by serving WP SlimStat\'s Javascript tracker from their fast and reliable network of servers.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
