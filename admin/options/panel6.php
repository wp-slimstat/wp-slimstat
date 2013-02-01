<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

wp_slimstat_admin::check_screenres();

if (isset($_GET['ds']) || isset($_GET['di2c'])){
	if (isset($_GET['ds']) && $_GET['ds']=='yes'){
		wp_slimstat_admin::show_alert_message(__('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat-options').'&nbsp;&nbsp;&nbsp;<a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&ds=confirm&slimpanel=6">'.__('Yes','wp-slimstat-options').'</a> <a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&slimpanel=6">'.__('No','wp-slimstat-options').'</a>', 'updated highlight below-h2');
	}
	if (isset($_GET['ds']) && $_GET['ds']=='confirm'){
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}slim_stats");
		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully emptied.','wp-slimstat-options'), 'updated below-h2');
	}
	if (isset($_GET['di2c']) && $_GET['di2c']=='confirm'){
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}slim_countries");
		if (wp_slimstat_admin::import_countries()){
			wp_slimstat_admin::show_alert_message(__('Your Geolocation data has been successfully updated.','wp-slimstat-options'), 'updated below-h2');
		}
		else{
			wp_slimstat_admin::show_alert_message(__('ERROR: Your Geolocation source file is not readable.','wp-slimstat-options'), 'error below-h2');
		}
	}
}
if (isset($_GET['rs']) && $_GET['rs']=='yes'){
	// Delete the two tables created by WP SlimStat 0.9.2
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_stats");
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_countries");

	if (wp_slimstat_admin::activate_single())
		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully reset.','wp-slimstat-options'), 'updated below-h2');
	else
		wp_slimstat_admin::show_alert_message(__('ERROR: Your Slimstat table could not be initialized.','wp-slimstat-options'), 'error below-h2');
}
if (isset($_GET['ot']) && $_GET['ot']=='yes'){
	$wpdb->query("OPTIMIZE TABLE {$wpdb->base_prefix}slim_browsers");
	$wpdb->query("OPTIMIZE TABLE {$wpdb->base_prefix}slim_countries");
	$wpdb->query("OPTIMIZE TABLE {$wpdb->base_prefix}slim_content_info");
	$wpdb->query("OPTIMIZE TABLE {$wpdb->base_prefix}slim_screenres");
	$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}slim_outbound");
	$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}slim_stats");

	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully optimized.','wp-slimstat-options'), 'updated below-h2');
}
if (isset($_GET['engine']) && $_GET['engine']=='innodb'){
	$have_innodb = $wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
	if ($have_innodb[0]['Value'] != 'YES') return;
	
	$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats ENGINE = InnoDB");
	$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_outbound ENGINE = InnoDB");
	$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_browsers ENGINE = InnoDB");
	$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_countries ENGINE = InnoDB");
	$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_screenres ENGINE = InnoDB");
	$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_content_info ENGINE = InnoDB");
	
	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat tables have been successfully converted to InnoDB.','wp-slimstat-options'), 'updated below-h2');
}
if (isset($_GET['ssidx'])){
	if($_GET['ssidx']=='create'){
		$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats ADD INDEX resource_idx(resource(20))");
		$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats ADD INDEX browser_idx(browser_id)");
		$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_browsers ADD INDEX all_idx(browser,version,platform,css_version,type)");
		$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_screenres ADD INDEX all_idx(resolution,colordepth,antialias)");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully created.','wp-slimstat-options'), 'updated below-h2');
	}
	if($_GET['ssidx']=='remove'){
		$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats DROP INDEX resource_idx");
		$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats DROP INDEX browser_idx");
		$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_browsers DROP INDEX all_idx");
		$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_screenres DROP INDEX all_idx");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully removed.','wp-slimstat-options'), 'updated below-h2');
	}
}
if (isset($_POST['options'])){
	if (isset($_POST['options']['conditional_delete_field']) &&
		isset($_POST['options']['conditional_delete_operator']) &&
		isset($_POST['options']['conditional_delete_value']) &&
		($_POST['options']['conditional_delete_field'] == 'country' ||
			$_POST['options']['conditional_delete_field'] == 'domain' ||
			$_POST['options']['conditional_delete_field'] == 'INET_NTOA(ip)' ||
			$_POST['options']['conditional_delete_field'] == 'language' ||
			$_POST['options']['conditional_delete_field'] == 'resource' ||
			$_POST['options']['conditional_delete_field'] == 'searchterms')){
			$escaped_value = $wpdb->escape($_POST['options']['conditional_delete_value']);
		switch($_POST['options']['conditional_delete_operator']){
			case 'equal':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} = '$escaped_value'";
				break;
			case 'like':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} LIKE '%$escaped_value%'";
				break;
			case 'not-like':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} NOT LIKE '%$escaped_value%'";
				break;
			case 'starts-with':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} LIKE '$escaped_value%'";
				break;
			case 'ends-with':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} LIKE '%$escaped_value'";
				break;
			case 'does-not-start-with':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} NOT LIKE '$escaped_value%'";
				break;
			case 'does-not-end-with':
				$delete_sql = "{$_POST['options']['conditional_delete_field']} NOT LIKE '%$escaped_value'";
				break;
		}
		$rows_affected = $wpdb->query("DELETE FROM {$wpdb->prefix}slim_stats WHERE $delete_sql");
		if (empty($rows_affected)) $rows_affected = 0;

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-options').' '.intval($rows_affected), 'updated below-h2');
	}
}
?>

<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
<?php
$details_wp_slim_tables = array_merge(
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}slim_stats'", ARRAY_A),
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}slim_outbound'", ARRAY_A),
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->base_prefix}slim_browsers'", ARRAY_A),
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->base_prefix}slim_countries'", ARRAY_A),
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->base_prefix}slim_screenres'", ARRAY_A),
	$wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->base_prefix}slim_content_info'", ARRAY_A)
);
echo '<tr><th scope="row">'.__('Database Information','wp-slimstat-options').'</th>';
echo '<td>'.__('Engine','wp-slimstat-options').": {$details_wp_slim_tables[0]['Engine']} ";
$have_innodb = $wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
$note_too_many_rows = ($details_wp_slim_tables[0]['Rows'] > 200000)?__(", it may take some time and exceed PHP's maximum execution time",'wp-slimstat-options'):'';
if ($have_innodb[0]['Value'] == 'YES' && $details_wp_slim_tables[0]['Engine'] == 'MyISAM') echo '[<a href="?page=wp-slimstat/admin/options/index.php&engine=innodb&slimpanel=6">'.__('switch to InnoDB','wp_slimstat_options')."</a> $note_too_many_rows]<br/>";

foreach ($details_wp_slim_tables as $a_table){
	$overhead_suffix = 'bytes'; $show_optimize = false;
	if ($a_table['Data_free'] > 1024){
		$a_table['Data_free'] = intval($a_table['Data_free']/1024);
		$overhead_suffix = 'KB';
	}
	if ($a_table['Data_free'] > 1024){
		$a_table['Data_free'] = intval($a_table['Data_free']/1024);
		$overhead_suffix = 'MB';
	}
	$data_size_suffix = 'KB';
	$table_size = ( $a_table['Data_length'] / 1024 ) + ( $a_table['Index_length'] / 1024 );
	if ($table_size > 1024){
		$table_size /= 1024;
		$data_size_suffix = 'MB';
	}
	$table_size = number_format($table_size, 2).' '.$data_size_suffix;
	
	echo "<br/><strong>{$a_table['Name']}</strong>: ".__('Size','wp-slimstat-options').": $table_size | ".__('Records','wp-slimstat-options').": {$a_table['Rows']} | ".__('Average Record Length','wp-slimstat-options').": {$a_table['Avg_row_length']} bytes | ".__('Created on','wp-slimstat-options').": {$a_table['Create_time']}";
	if ($a_table['Engine'] == 'MyISAM' && $a_table['Data_free'] > 0){
		echo ' | '.__('Approximate Overhead','wp-slimstat-options').": {$a_table['Data_free']} $overhead_suffix ";
		$show_optimize = true;
	}
}
if ($show_optimize) echo "[<a href='?page=wp-slimstat/admin/options/index.php&ot=yes&slimpanel=6'>".__('Optimize tables','wp-slimstat-options')."</a>]";
echo '</td></tr>';
?>
	<tr>
		<th scope="row"><label for="conditional_delete_field"><?php _e('Purge Data','wp-slimstat-options') ?></label></th>
		<td>
			<span class="nowrap">
				<?php _e('Delete rows where','wp-slimstat-options') ?>
				<select name="options[conditional_delete_field]" id="conditional_delete_field">
					<option value="country"><?php _e('Country Code','wp-slimstat-options') ?></option>
					<option value="INET_NTOA(ip)"><?php _e('IP Address','wp-slimstat-options') ?></option>
					<option value="language"><?php _e('Language Code','wp-slimstat-options') ?></option>
					<option value="resource"><?php _e('Permalink','wp-slimstat-options') ?></option>
					<option value="searchterms"><?php _e('Search Terms','wp-slimstat-options') ?></option>
				</select> 
				<select name="options[conditional_delete_operator]" id="conditional_delete_operator" style="width:12em">
					<option value="equal"><?php _e('Is equal to','wp-slimstat-options') ?></option>
					<option value="like"><?php _e('Contains','wp-slimstat-options') ?></option>
					<option value="not-like"><?php _e('Does not contain','wp-slimstat-options') ?></option>
					<option value="starts-with"><?php _e('Starts with','wp-slimstat-options') ?></option>
					<option value="ends-with"><?php _e('Ends with','wp-slimstat-options') ?></option>
					<option value="does-not-start-with"><?php _e('Does not start with','wp-slimstat-options') ?></option>
					<option value="does-not-end-with"><?php _e('Does not end with','wp-slimstat-options') ?></option>
				</select>
				<input type="text" name="options[conditional_delete_value]" id="delete_value" value="" size="20">
			</span>
			<input type="submit" value="<?php _e('DELETE','wp-slimstat-options') ?>" class="button-primary" name="Submit"
				onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these rows from your database?','wp-slimstat-options'); ?>'))">
		</td>
	</tr>
<?php
$check_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}slim_stats WHERE Key_name = 'resource_idx'");
if (empty($check_index)): ?>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&ssidx=create&slimpanel=6"><?php _e('Activate Indexes','wp-slimstat-options'); ?></a></th>
		<td><?php _e('Use this feature if you want to improve the overall performance of your stats. You will need about 30% more DB space, to store the extra information required.','wp-slimstat-options') ?></td>
	</tr>
<?php else: ?>
<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&ssidx=remove&slimpanel=6"><?php _e('Remove Indexes','wp-slimstat-options'); ?></a></th>
		<td><?php _e('Use this feature if you want to save some DB space, while slightly degrading WP SlimStat overall performances.','wp-slimstat-options') ?></td>
	</tr>
<?php endif ?>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&ds=yes&slimpanel=6"><?php _e('Factory Reset','wp-slimstat-options'); ?></a></th>
		<td><?php _e('Select this option if you want to empty your WP SlimStat database (does not reset your settings).','wp-slimstat-options') ?></td>
	</tr>

	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&di2c=confirm&slimpanel=6"><?php _e('Update Geolocation DB','wp-slimstat-options'); ?></a></th>
		<td><?php _e('Select this option if you want to load the new geolocation data into your database.','wp-slimstat-options') ?></td>
	</tr>
<?php 
$check_column = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}slim_stats LIKE 'browser_id'");
if (empty($check_column)): ?>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slimstat/admin/options/index.php&rs=yes&slimpanel=6"><?php _e('RESET STATS','wp-slimstat-options'); ?></a></th>
		<td><?php _e('It looks like you need to update the structure of one of the tables used by this plugin. Please click the button here above to reset your table (all the data will be lost, sorry), then deactivate/reactivate WP SlimStat to complete the installation process.','wp-slimstat-options') ?></td>
	</tr>
<?php endif; ?>
</tbody>
</table>