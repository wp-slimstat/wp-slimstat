<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

// Load the options
$wp_slimstat_options = array();
$wp_slimstat_options['convert_ip_addresses'] = get_option('slimstat_convert_ip_addresses', 'no');
$wp_slimstat_options['rows_to_show'] = get_option('slimstat_rows_to_show', '20');

?>

<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr valign="top">
		<th scope="row" rowspan="2"><label for="convert_ip_addresses"><?php _e('Convert IP addresses','wp-slimstat-options') ?></label></th>
		<td class="narrowcolumn">
			<input type="radio" name="options[convert_ip_addresses]" id="convert_ip_addresses" value="yes"<?php echo ($wp_slimstat_options['convert_ip_addresses'] == 'yes')?' checked="checked"':''; ?>> <?php _e('Yes','wp-slimstat-options') ?>
		</td>
		<td class="widecolumn">
			<input type="radio" name="options[convert_ip_addresses]" value="no" <?php echo ($wp_slimstat_options['convert_ip_addresses'] == 'no')?'  checked="checked"':''; ?>> <?php _e('No','wp-slimstat-options') ?>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="shortrow">
			<span class="description"><?php _e('Shows hostnames instead of IP addresses. It slows down the rendering of your metrics.','wp-slimstat-options') ?></span>
		</td>
	</tr>
	
	<tr valign="top">
		<th scope="row" rowspan="2"><label for="rows_to_show"><?php _e('Limit results to','wp-slimstat-options') ?></label></th>
		<td colspan="2">
			<input type="text" name="options[rows_to_show]" id="rows_to_show" value="<?php echo $wp_slimstat_options['rows_to_show']; ?>" size="4"> <?php _e('rows','wp-slimstat-options') ?>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="shortrow">
			<span class="description"><?php _e('Defines the number of results to return for each module. Please use a <strong>positive</strong> value.','wp-slimstat-options') ?></span>
		</td>
	</tr>
</tbody>
</table>