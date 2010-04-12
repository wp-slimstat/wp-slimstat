<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load the options
$wp_slimstat_options = array();
$wp_slimstat_options['is_tracking'] = get_option('slimstat_is_tracking', 'yes');
$wp_slimstat_options['ignore_interval'] = intval(get_option('slimstat_ignore_interval', '30'));
$wp_slimstat_options['ignore_bots'] = get_option('slimstat_ignore_bots', 'no');
$wp_slimstat_options['auto_purge'] = intval(get_option('slimstat_auto_purge', '0'));

?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Activate tracking</label></th>
		<td>
			<input type="radio" name="options[is_tracking]" value="yes"<?php echo ($wp_slimstat_options['is_tracking'] == 'yes')?' checked="checked"':''; ?>> Yes
			<input type="radio" name="options[is_tracking]" value="no" style="margin-left:40px" <?php echo ($wp_slimstat_options['is_tracking'] == 'no')?'  checked="checked"':''; ?>> No
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Ignore interval</label></th>
		<td>
			<input type="text" name="options[ignore_interval]" value="<?php echo $wp_slimstat_options['ignore_interval']; ?>" size="4"> seconds
			<br><span class="description">Ignores pageviews identical to an existing one recorded less than <strong>X</strong> seconds ago. Zero disables this feature.</span>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Ignore bots</label></th>
		<td>
			<input type="radio" name="options[ignore_bots]" value="yes"<?php echo ($wp_slimstat_options['ignore_bots'] == 'yes')?' checked="checked"':''; ?>> Yes
			<input type="radio" name="options[ignore_bots]" value="no" style="margin-left:40px" <?php echo ($wp_slimstat_options['ignore_bots'] == 'no')?'  checked="checked"':''; ?>> No
			<br><span class="description">Ignores requests from user agents whose operating system and CSS version are unknown</span>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Autopurge</label></th>
		<td>
			<input type="text" name="options[auto_purge]" value="<?php echo $wp_slimstat_options['auto_purge']; ?>" size="4"> days
			<?php if (wp_get_schedule('wp_slimstat_purge')) echo '. Next purge is scheduled on '.date_i18n(get_option('date_format').'  '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')); ?>
			<br><span class="description">Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.</span>
		</td>
	</tr>
</tbody>
</table>