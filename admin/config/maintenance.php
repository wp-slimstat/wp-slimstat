<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

wp_slimstat_admin::check_screenres();

if (isset($_GET['ds'])){
	if (isset($_GET['ds']) && $_GET['ds']=='yes'){
		wp_slimstat_admin::show_alert_message(__('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat').'&nbsp;&nbsp;&nbsp;<a class="button-secondary" href="?page=wp-slim-config&ds=confirm&tab='.$current_tab.'">'.__('Yes','wp-slimstat').'</a> <a class="button-secondary" href="?page=wp-slim-config&tab='.$current_tab.'">'.__('No','wp-slimstat').'</a>', 'updated highlight below-h2');
	}
	if (isset($_GET['ds']) && $_GET['ds']=='confirm'){
		$GLOBALS['wpdb']->query("TRUNCATE TABLE {$GLOBALS['wpdb']->prefix}slim_stats");
		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully emptied.','wp-slimstat'), 'updated below-h2');
	}
}
if (isset($_GET['rbo'])){
	if ($_GET['rbo']=='confirm'){
		wp_slimstat_admin::show_alert_message(__('Are you sure you want to reset your tabs?','wp-slimstat').'&nbsp;&nbsp;&nbsp;<a class="button-secondary" href="?page=wp-slim-config&rbo=yes&tab='.$current_tab.'">'.__('Yes','wp-slimstat').'</a> <a class="button-secondary" href="?page=wp-slim-config&tab='.$current_tab.'">'.__('No','wp-slimstat').'</a>', 'updated highlight below-h2');
	}
	if ($_GET['rbo']=='yes'){
		// Delete the two tables created by WP SlimStat 0.9.2
		$GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%slim%'");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat tabs have been successfully reset.','wp-slimstat'), 'updated below-h2');
	}
}
if (isset($_GET['rs']) && $_GET['rs']=='yes'){
	// Delete the two tables created by WP SlimStat 0.9.2
	$GLOBALS['wpdb']->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->prefix}slim_stats");

	if (wp_slimstat_admin::activate_single())
		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully reset.','wp-slimstat'), 'updated below-h2');
	else
		wp_slimstat_admin::show_alert_message(__('ERROR: Your Slimstat table could not be initialized.','wp-slimstat'), 'error below-h2');
}
if (isset($_GET['ot']) && $_GET['ot']=='yes'){
	$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers");
	$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_content_info");
	$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres");
	$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->prefix}slim_outbound");
	$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->prefix}slim_stats");

	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully optimized.','wp-slimstat'), 'updated below-h2');
}
if (isset($_GET['engine']) && $_GET['engine']=='innodb'){
	$have_innodb = $GLOBALS['wpdb']->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
	if ($have_innodb[0]['Value'] != 'YES') return;
	
	$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ENGINE = InnoDB");
	$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_outbound ENGINE = InnoDB");
	$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ENGINE = InnoDB");
	$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ENGINE = InnoDB");
	$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_content_info ENGINE = InnoDB");
	
	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat tables have been successfully converted to InnoDB.','wp-slimstat'), 'updated below-h2');
}
if (isset($_GET['ssidx'])){
	if($_GET['ssidx']=='create'){
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX resource_idx(resource(20))");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX browser_idx(browser_id)");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ADD INDEX all_idx(browser,version,platform,css_version,type)");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ADD INDEX all_idx(resolution,colordepth,antialias)");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully created.','wp-slimstat'), 'updated below-h2');
	}
	if($_GET['ssidx']=='remove'){
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX resource_idx");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX browser_idx");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers DROP INDEX all_idx");
		$GLOBALS['wpdb']->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres DROP INDEX all_idx");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully removed.','wp-slimstat'), 'updated below-h2');
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
			$escaped_value = $GLOBALS['wpdb']->escape($_POST['options']['conditional_delete_value']);
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
		$rows_affected = $GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE $delete_sql");
		if (empty($rows_affected)) $rows_affected = 0;

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat').' '.intval($rows_affected), 'updated below-h2');
	}
}
?>

<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
<table class="form-table <?php echo $GLOBALS['wp_locale']->text_direction ?>">
<tbody>
<?php
$details_wp_slim_tables = array_merge(
	$GLOBALS['wpdb']->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_stats'", ARRAY_A),
	$GLOBALS['wpdb']->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_outbound'", ARRAY_A),
	$GLOBALS['wpdb']->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_browsers'", ARRAY_A),
	$GLOBALS['wpdb']->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_screenres'", ARRAY_A),
	$GLOBALS['wpdb']->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_content_info'", ARRAY_A)
);
echo '<tr><th scope="row">'.__('Database Information','wp-slimstat').'</th>';
echo '<td>'.__('Engine','wp-slimstat').": {$details_wp_slim_tables[0]['Engine']} ";
$have_innodb = $GLOBALS['wpdb']->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
$note_too_many_rows = ($details_wp_slim_tables[0]['Rows'] > 200000)?__(", it may take some time and exceed PHP's maximum execution time",'wp-slimstat'):'';
if ($have_innodb[0]['Value'] == 'YES' && $details_wp_slim_tables[0]['Engine'] == 'MyISAM') echo '[<a href="?page=wp-slim-config&engine=innodb&tab=6">'.__('switch to InnoDB','wp-slimstat')."</a> $note_too_many_rows]<br/>";

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
	
	echo "<br/><strong>{$a_table['Name']}</strong>: ".__('Size','wp-slimstat').": $table_size | ".__('Records','wp-slimstat').": {$a_table['Rows']} | ".__('Average Record Length','wp-slimstat').": {$a_table['Avg_row_length']} bytes | ".__('Created on','wp-slimstat').": {$a_table['Create_time']}";
	if ($a_table['Engine'] == 'MyISAM' && $a_table['Data_free'] > 0){
		echo ' | '.__('Approximate Overhead','wp-slimstat').": {$a_table['Data_free']} $overhead_suffix ";
		$show_optimize = true;
	}
}
if ($show_optimize) echo "[<a href='?page=wp-slim-config&ot=yes&tab=".$current_tab."'>".__('Optimize tables','wp-slimstat')."</a>]";
echo '</td></tr>';
?>
	<tr>
		<th scope="row"><label for="conditional_delete_field"><?php _e('Purge Data','wp-slimstat') ?></label></th>
		<td>
			<span class="nowrap">
				<?php _e('Delete rows where','wp-slimstat') ?>
				<select name="options[conditional_delete_field]" id="conditional_delete_field">
					<option value="country"><?php _e('Country Code','wp-slimstat') ?></option>
					<option value="INET_NTOA(ip)"><?php _e('IP Address','wp-slimstat') ?></option>
					<option value="language"><?php _e('Language Code','wp-slimstat') ?></option>
					<option value="resource"><?php _e('Permalink','wp-slimstat') ?></option>
					<option value="searchterms"><?php _e('Search Terms','wp-slimstat') ?></option>
				</select> 
				<select name="options[conditional_delete_operator]" id="conditional_delete_operator" style="width:12em">
					<option value="equal"><?php _e('Is equal to','wp-slimstat') ?></option>
					<option value="like"><?php _e('Contains','wp-slimstat') ?></option>
					<option value="not-like"><?php _e('Does not contain','wp-slimstat') ?></option>
					<option value="starts-with"><?php _e('Starts with','wp-slimstat') ?></option>
					<option value="ends-with"><?php _e('Ends with','wp-slimstat') ?></option>
					<option value="does-not-start-with"><?php _e('Does not start with','wp-slimstat') ?></option>
					<option value="does-not-end-with"><?php _e('Does not end with','wp-slimstat') ?></option>
				</select>
				<input type="text" name="options[conditional_delete_value]" id="delete_value" value="" size="20">
			</span>
			<input type="submit" value="<?php _e('DELETE','wp-slimstat') ?>" class="button-primary" name="Submit"
				onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these rows from your database?','wp-slimstat'); ?>'))">
		</td>
	</tr>
<?php
$check_index = $GLOBALS['wpdb']->get_results("SHOW INDEX FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE Key_name = 'resource_idx'");
if (empty($check_index)): ?>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slim-config&ssidx=create&tab=<?php echo $current_tab ?>"><?php _e('Activate Indexes','wp-slimstat'); ?></a></th>
		<td><?php _e('Use this feature if you want to improve the overall performance of your stats. You will need about 30% more DB space, to store the extra information required.','wp-slimstat') ?></td>
	</tr>
<?php else: ?>
<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slim-config&ssidx=remove&tab=<?php echo $current_tab ?>"><?php _e('Remove Indexes','wp-slimstat'); ?></a></th>
		<td><?php _e('Use this feature if you want to save some DB space, while slightly degrading WP SlimStat overall performances.','wp-slimstat') ?></td>
	</tr>
<?php endif ?>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slim-config&rbo=confirm&tab=<?php echo $current_tab ?>"><?php _e('Reset Tabs','wp-slimstat'); ?></a></th>
		<td><?php _e("Reset SlimStat's box order settings if one or more tabs are empty (no reports shown) or metrics are missing.",'wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slim-config&ds=yes&tab=<?php echo $current_tab ?>"><?php _e('Reset Stats','wp-slimstat'); ?></a></th>
		<td><?php _e('Select this option if you want to empty your WP SlimStat database (does not reset your settings).','wp-slimstat') ?></td>
	</tr>
</tbody>
</table>
</form>