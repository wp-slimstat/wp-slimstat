<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

$hide_submit = true;

?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Empty database</label></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=yes&slimpanel=4"><?php _e('DELETE STATS','wp-slimstat-view'); ?></a>
			&mdash; Please be advised that this command <strong>cannot be undone</strong>!
		</td>
	</tr>
<?php 
if (!isset($wp_slimstat_object)) $wp_slimstat_object = new wp_slimstat();
$check_column = $wpdb->get_var("SHOW COLUMNS FROM `$wp_slimstat_object->table_stats` LIKE 'browser_id'");
if (empty($check_column)): ?>
	<tr valign="top">
		<th scope="row"><label for="upload_path">Old table detected</label></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&rs=yes&slimpanel=4"><?php _e('RESET STATS','wp-slimstat-view'); ?></a>
			&mdash; It looks like you need to update the structure of one of the tables used by this plugin.
			Please click the button here above to reset your table (all the data will be lost, sorry), then deactivate/reactivate
			WP SlimStat to complete the installation process.
		</td>
	</tr>
<?php endif; ?>
</tbody>
</table>