<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['is_tracking']) && !wp_slimstat_admin::update_option('is_tracking', $_POST['options']['is_tracking'], 'yesno')) $faulty_fields = __('Activate tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_javascript']) && !wp_slimstat_admin::update_option('enable_javascript', $_POST['options']['enable_javascript'], 'yesno')) $faulty_fields = __('Enable JS Tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['custom_js_path']) && !wp_slimstat_admin::update_option('custom_js_path', $_POST['options']['custom_js_path'], 'text')) $faulty_fields = __('Custom path','wp-slimstat-options').', ';
	if (isset($_POST['options']['auto_purge']) && !wp_slimstat_admin::update_option('auto_purge', $_POST['options']['auto_purge'], 'integer')) $faulty_fields .= __('Auto purge','wp-slimstat-options').', ';
	if (isset($_POST['options']['add_posts_column']) && !wp_slimstat_admin::update_option('add_posts_column', $_POST['options']['add_posts_column'], 'yesno')) $faulty_fields .= __('Add column to Posts','wp-slimstat-options').', ';
	if (isset($_POST['options']['use_separate_menu']) && !wp_slimstat_admin::update_option('use_separate_menu', $_POST['options']['use_separate_menu'], 'yesno')) $faulty_fields .= __('Use separate menu','wp-slimstat-options').', ';

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
		<th scope="row"><label for="is_tracking"><?php _e('Activate tracking','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[is_tracking]" id="is_tracking" value="yes"<?php echo (wp_slimstat::$options['is_tracking'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[is_tracking]" value="no" <?php echo (wp_slimstat::$options['is_tracking'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('You may want to stop WP SlimStat from tracking users for a while, but still be able to access your metrics.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="enable_javascript"><?php _e('Enable JS Tracking','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[enable_javascript]" id="ignore_bots" value="yes"<?php echo (wp_slimstat::$options['enable_javascript'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[enable_javascript]" value="no" <?php echo (wp_slimstat::$options['enable_javascript'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Adds a javascript code to your pages to track visits, screen resolutions, outbound links, downloads and more','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="custom_js_path"><?php _e('Custom path','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><?php echo home_url() ?> <input type="text" class="longtext" name="options[custom_js_path]" id="custom_js_path" value="<?php echo wp_slimstat::$options['custom_js_path'] ?>" size="50"></span>
			<span class="description"><?php _e('If you moved <code>wp-slimstat-js.php</code> out of its default folder, specify the new path here. Default:','wp-slimstat-options'); ?> <code><?php echo home_url() ?>/wp-content/plugins/wp-slimstat</code>. The appropriate protocol (http or https) will be chosen by the system.</span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="auto_purge"><?php _e('Autopurge','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="text" name="options[auto_purge]" id="auto_purge" value="<?php echo wp_slimstat::$options['auto_purge'] ?>" size="4"> <?php _e('days','wp-slimstat-options') ?></span>
			<?php if (wp_get_schedule('wp_slimstat_purge')) echo '&mdash; '.__('Next clean-up on','wp-slimstat-options').' '.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')).'. '.sprintf(__('Entries recorded on or before %s will be permanently deleted.','wp-slimstat-view'), date_i18n(get_option('date_format'), strtotime('-'.wp_slimstat::$options['auto_purge'].' days'))); ?>
			<span class="description"><?php _e('Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="add_posts_column"><?php _e('Add Posts column','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[add_posts_column]" id="add_posts_column" value="yes"<?php echo (wp_slimstat::$options['add_posts_column'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[add_posts_column]" value="no" <?php echo (wp_slimstat::$options['add_posts_column'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Shows a new column to the Posts page with the number of hits per post (may slow down page rendering)','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="use_separate_menu"><?php _e('Use separate menu','wp-slimstat-options') ?></label></th>
		<td>
			<span class="block-element"><input type="radio" name="options[use_separate_menu]" id="use_separate_menu" value="yes"<?php echo (wp_slimstat::$options['use_separate_menu'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span>
			<span class="block-element"><input type="radio" name="options[use_separate_menu]" value="no" <?php echo (wp_slimstat::$options['use_separate_menu'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?></span>
			<span class="description"><?php _e('Lets you decide if you want to have a separate admin menu for WP SlimStat or not.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
