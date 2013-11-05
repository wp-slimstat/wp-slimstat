<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action') || (!empty($_POST) && !check_admin_referer('maintenance_wp_slimstat','maintenance_wp_slimstat_nonce'))) exit(0);

wp_slimstat_admin::check_screenres();

// Import/Export
if (isset($_GET['export-slimstat-settings'])){
	ob_clean();
	$output = fopen('php://output', 'w');
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename=wp-slimstat-config.ini');
	fputs($output, serialize(wp_slimstat::$options));
	fclose($output);
	die();
}
if (!empty($_POST['import-slimstat-settings'])){
	$new_options = @unserialize(stripslashes($_POST['import-slimstat-settings']));
	$new_options = array_intersect_key($new_options, wp_slimstat::$options);
	if (!empty($new_options)){
		foreach ($new_options as $a_option_name => $a_option_value){
			wp_slimstat_admin::update_option($a_option_name, $a_option_value);
		}
	}
}

if (isset($_GET['ds'])){
	if ($_GET['ds']=='yes'){
		wp_slimstat_admin::show_alert_message(__('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat').'&nbsp;&nbsp;&nbsp;<a class="button-secondary" href="?page=wp-slim-config&ds=confirm&tab='.$current_tab.'">'.__('Yes','wp-slimstat').'</a> <a class="button-secondary" href="?page=wp-slim-config&tab='.$current_tab.'">'.__('No','wp-slimstat').'</a>', 'updated highlight below-h2');
	}
	if ($_GET['ds']=='confirm'){
		wp_slimstat::$wpdb->query("TRUNCATE TABLE {$GLOBALS['wpdb']->prefix}slim_stats");
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
	wp_slimstat::$wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->prefix}slim_stats");

	if (wp_slimstat_admin::activate_single())
		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully reset.','wp-slimstat'), 'updated below-h2');
	else
		wp_slimstat_admin::show_alert_message(__('ERROR: Your Slimstat table could not be initialized.','wp-slimstat'), 'error below-h2');
}
if (isset($_GET['ot']) && $_GET['ot']=='yes'){
	wp_slimstat::$wpdb->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers");
	wp_slimstat::$wpdb->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_content_info");
	wp_slimstat::$wpdb->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres");
	wp_slimstat::$wpdb->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->prefix}slim_outbound");
	wp_slimstat::$wpdb->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->prefix}slim_stats");

	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully optimized.','wp-slimstat'), 'updated below-h2');
}
if (isset($_GET['engine']) && $_GET['engine']=='innodb'){
	$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
	if ($have_innodb[0]['Value'] != 'YES') return;
	
	wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ENGINE = InnoDB");
	wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_outbound ENGINE = InnoDB");
	wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ENGINE = InnoDB");
	wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ENGINE = InnoDB");
	wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_content_info ENGINE = InnoDB");
	
	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat tables have been successfully converted to InnoDB.','wp-slimstat'), 'updated below-h2');
}
if (isset($_GET['ssidx'])){
	if($_GET['ssidx']=='create'){
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX resource_idx(resource(20))");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX browser_idx(browser_id)");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ADD INDEX all_idx(browser,version,platform,css_version,type)");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ADD INDEX all_idx(resolution,colordepth,antialias)");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully created.','wp-slimstat'), 'updated below-h2');
	}
	if($_GET['ssidx']=='remove'){
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX resource_idx");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX browser_idx");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers DROP INDEX all_idx");
		wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres DROP INDEX all_idx");

		wp_slimstat_admin::show_alert_message(__('Your WP SlimStat indexes have been successfully removed.','wp-slimstat'), 'updated below-h2');
	}
}
if (isset($_POST['options']['conditional_delete_field']) && isset($_POST['options']['conditional_delete_operator']) && isset($_POST['options']['conditional_delete_value']) && in_array($_POST['options']['conditional_delete_field'], array('tb.user_agent', 't1.country', 't1.domain', 'INET_NTOA(t1.ip)', 't1.language', 't1.resource', 't1.searchterms'))){
	$escaped_value = esc_sql($_POST['options']['conditional_delete_value']);
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
	$rows_affected = wp_slimstat::$wpdb->query("DELETE t1.* FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb ON t1.browser_id = tb.browser_id WHERE $delete_sql");
	if (empty($rows_affected)) $rows_affected = 0;

	wp_slimstat_admin::show_alert_message(__('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat').' '.intval($rows_affected), 'updated below-h2');
}
?>

<table class="form-table <?php echo $GLOBALS['wp_locale']->text_direction ?>">
<tbody>
<?php
$details_wp_slim_tables = array_merge(
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_stats'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_outbound'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_browsers'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_screenres'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_content_info'", ARRAY_A)
);
echo '<tr><th scope="row">'.__('Database Information','wp-slimstat').'</th>';
echo '<td>'.__('Engine','wp-slimstat').": {$details_wp_slim_tables[0]['Engine']} ";
$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
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
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ) ?>
				<span class="nowrap">
					<?php _e('Delete rows where','wp-slimstat') ?>
					<select name="options[conditional_delete_field]" id="conditional_delete_field">
						<option value="tb.user_agent"><?php _e('User Agent','wp-slimstat') ?></option>
						<option value="t1.country"><?php _e('Country Code','wp-slimstat') ?></option>
						<option value="INET_NTOA(t1.ip)"><?php _e('IP Address','wp-slimstat') ?></option>
						<option value="t1.language"><?php _e('Language Code','wp-slimstat') ?></option>
						<option value="t1.resource"><?php _e('Permalink','wp-slimstat') ?></option>
						<option value="t1.searchterms"><?php _e('Search Terms','wp-slimstat') ?></option>
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
			</form>
		</td>
	</tr>
<?php
$check_index = wp_slimstat::$wpdb->get_results("SHOW INDEX FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE Key_name = 'resource_idx'");
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
	<tr>
		<th scope="row"><a class="button-secondary" href="?page=wp-slim-config&export-slimstat-settings=yes&tab=<?php echo $current_tab ?>"><?php _e('Export Settings','wp-slimstat'); ?></a></th>
		<td><?php _e('Export all your SlimStat settings in a text file. You can import them later by pasting the content of that file in the text field below.','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row"><?php _e('Import Settings','wp-slimstat'); ?></a></th>
		<td>
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ) ?>
				<textarea name="import-slimstat-settings" style="width:100%" rows="5"></textarea><br/>
				<input type="submit" value="<?php _e('Import','wp-slimstat') ?>" class="button-primary"
					onclick="return(confirm('<?php _e('Are you sure you want to OVERWRITE your current settings?','wp-slimstat'); ?>'))">
		</td>
	</tr>
</tbody>
</table>
