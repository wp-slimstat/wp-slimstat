<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

?>

<h3><?php _e('Please note that these commands cannot be undone!','wp-slimstat-options') ?></h3>

<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
	<tr valign="top">
		<th scope="row"><?php _e('Clean database','wp-slimstat-options') ?></th>
		<td>
			<form action="options-general.php?page=wp-slimstat/options/index.php&slimpanel=4" method="post"
				onsubmit="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these rows from your database?','wp-slimstat-options'); ?>'))">
			<?php _e('Delete rows where','wp-slimstat-options') ?>
			<select name="options[conditional_delete_field]">
				<option value="country"><?php _e('Country Code','wp-slimstat-options') ?></option>
				<option value="domain"><?php _e('Domain','wp-slimstat-options') ?></option>
				<option value="language"><?php _e('Language Code','wp-slimstat-options') ?></option>
				<option value="resource"><?php _e('Permalink','wp-slimstat-options') ?></option>
				<option value="searchterms"><?php _e('Search Terms','wp-slimstat-options') ?></option>
			</select> 
			<select name="options[conditional_delete_operator]" style="width:12em">
				<option value="equal"><?php _e('Is equal to','wp-slimstat-options') ?></option>
				<option value="like"><?php _e('Contains','wp-slimstat-options') ?></option>
				<option value="not like"><?php _e('Does not contain','wp-slimstat-options') ?></option>
			</select>
			<input type="text" name="options[conditional_delete_value]" id="delete_value" value="" size="20">
			<input type="submit" value="<?php _e('DELETE','wp-slimstat-options') ?>" class="button-primary" name="Submit">
			</form>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="shortrow">&nbsp;</td>
	</tr>
	
	<tr valign="top">
		<th scope="row"><?php _e('Empty database','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=yes&slimpanel=4"><?php _e('DELETE STATS','wp-slimstat-options'); ?></a>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="shortrow">&nbsp;</td>
	</tr>
	
	<tr valign="top">
		<th scope="row"><?php _e('Reset Ip-to-Countries','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&di2c=yes&slimpanel=4"><?php _e('EMPTY IP-TO-COUNTRIES','wp-slimstat-options'); ?></a>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="shortrow">&nbsp;</td>
	</tr>
	
<?php 
if (!isset($wp_slimstat_object)) $wp_slimstat_object = new wp_slimstat();
$check_column = $wpdb->get_var("SHOW COLUMNS FROM `$wp_slimstat_object->table_stats` LIKE 'browser_id'");
if (empty($check_column)): ?>
	<tr valign="top">
		<th scope="row"><?php _e('Old table detected','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&rs=yes&slimpanel=4"><?php _e('RESET STATS','wp-slimstat-options'); ?></a>
			&mdash; <?php _e('It looks like you need to update the structure of one of the tables used by this plugin. Please click the button here above to reset your table (all the data will be lost, sorry), then deactivate/reactivate WP SlimStat to complete the installation process.','wp-slimstat-options') ?>
		</td>
	</tr>
<?php endif; ?>
</tbody>
</table>