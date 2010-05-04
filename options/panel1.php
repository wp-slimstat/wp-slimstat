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
$wp_slimstat_options['convert_ip_addresses'] = get_option('slimstat_convert_ip_addresses', 'no');

?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row"><label for="is_tracking"><?php _e('Activate tracking','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[is_tracking]" id="is_tracking" value="yes"<?php echo ($wp_slimstat_options['is_tracking'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>
			<input type="radio" name="options[is_tracking]" value="no" style="margin-left:40px" <?php echo ($wp_slimstat_options['is_tracking'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="ignore_interval"><?php _e('Ignore interval','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" name="options[ignore_interval]" id="ignore_interval" value="<?php echo $wp_slimstat_options['ignore_interval']; ?>" size="4"> <?php _e('seconds','wp-slimstat-options') ?>
			<br><span class="description"><?php _e('Ignores pageviews identical to an existing one recorded less than <strong>X</strong> seconds ago. Zero disables this feature.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="ignore_bots"><?php _e('Ignore bots','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[ignore_bots]" id="ignore_bots" value="yes"<?php echo ($wp_slimstat_options['ignore_bots'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>
			<input type="radio" name="options[ignore_bots]" value="no" style="margin-left:40px" <?php echo ($wp_slimstat_options['ignore_bots'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<br><span class="description"><?php _e('Ignores requests from user agents whose operating system and CSS version are unknown','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="auto_purge"><?php _e('Autopurge','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" name="options[auto_purge]" id="auto_purge" value="<?php echo $wp_slimstat_options['auto_purge']; ?>" size="4"> <?php _e('days','wp-slimstat-options') ?>
			<?php if (wp_get_schedule('wp_slimstat_purge')) echo '. '.__('Next purge is scheduled on','wp-slimstat-options').' '.date_i18n(get_option('date_format').'  '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')); ?>
			<br><span class="description"><?php _e('Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><label for="convert_ip_addresses"><?php _e('Convert IP addresses','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[convert_ip_addresses]" id="convert_ip_addresses" value="yes"<?php echo ($wp_slimstat_options['convert_ip_addresses'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>
			<input type="radio" name="options[convert_ip_addresses]" value="no" style="margin-left:40px" <?php echo ($wp_slimstat_options['convert_ip_addresses'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<br><span class="description"><?php _e('Shows hostnames instead of IP addresses. It slows down the rendering of your metrics.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>