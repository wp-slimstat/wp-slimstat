<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Update the options
if (isset($_POST['options'])){
	$faulty_fields = '';
	if (isset($_POST['options']['convert_ip_addresses']) && !slimstat_update_option('convert_ip_addresses', $_POST['options']['convert_ip_addresses'], 'yesno')) $faulty_fields .= __('Convert IP addresses','wp-slimstat-options').', ';
	if (isset($_POST['options']['rows_to_show']) && !slimstat_update_option('rows_to_show', $_POST['options']['rows_to_show'], 'integer')) $faulty_fields .= __('Limit results to','wp-slimstat-options').', ';
	
	slimstat_error_message($faulty_fields);
}
?>
<form action="admin.php?page=wp-slimstat/options/index.php&slimpanel=2" method="post">
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr>
		<th scope="row"><label for="convert_ip_addresses"><?php _e('Convert IP addresses','wp-slimstat-options') ?></label></th>
		<td>
			<input type="radio" name="options[convert_ip_addresses]" id="convert_ip_addresses" value="yes"<?php echo (slimstat_get_option('convert_ip_addresses','no') == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?> &nbsp; &nbsp; &nbsp;
			<input type="radio" name="options[convert_ip_addresses]" value="no" <?php echo (slimstat_get_option('convert_ip_addresses','no') == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
			<span class="description"><?php _e('Shows hostnames instead of IP addresses. It slows down the rendering of your metrics.','wp-slimstat-options') ?></span>
		</td>
	</tr>	
	<tr>
		<th scope="row"><label for="rows_to_show"><?php _e('Limit results to','wp-slimstat-options') ?></label></th>
		<td>
			<input type="text" name="options[rows_to_show]" id="rows_to_show" value="<?php echo slimstat_get_option('rows_to_show','20'); ?>" size="4"> <?php _e('rows','wp-slimstat-options') ?>
			<span class="description"><?php _e('Defines the number of results to return for each module. Please use a <strong>positive</strong> value.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>
<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>
</form>