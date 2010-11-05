<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}

if (isset($_GET['ds']) || isset($_GET['di2c'])){
	echo '<div id="wp-slimstat-message" class="updated fade"><p>';
	if (isset($_GET['ds']) && $_GET['ds']=='yes'){
		_e('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat-options');
		echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=confirm&slimpanel=5">'.__('Yes','wp-slimstat-options').'</a>';
		echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&slimpanel=5">'.__('No','wp-slimstat-options').'</a>';
	}
	if (isset($_GET['ds']) && $_GET['ds']=='confirm'){
		$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_stats`");
		$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_visits`");
		_e('Your WP SlimStat table has been successfully emptied.','wp-slimstat-options');
	}
	if (isset($_GET['di2c']) && $_GET['di2c']=='yes'){
		_e('Are you sure you want to empty the ip-to-countries table?','wp-slimstat-options');
		echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&di2c=confirm&slimpanel=5">'.__('Yes','wp-slimstat-options').'</a>';
		echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&slimpanel=5">'.__('No','wp-slimstat-options').'</a>';
	}
	if (isset($_GET['di2c']) && $_GET['di2c']=='confirm'){
		$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_countries`");
		_e('Your IP-to-countries table has been successfully emptied. Now go to your Plugins panel and deactivate/reactivate WP SlimStat to load the new data.','wp-slimstat-options');
	}
	echo '</p></div>';
}
if (isset($_GET['rs']) && $_GET['rs']=='yes'){
	$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}slim_stats`");
	echo '<div id="wp-slimstat-message" class="updated fade"><p>';
	_e('Your WP SlimStat table has been successfully reset. Now go to your Plugins panel and deactivate/reactivate WP SlimStat.','wp-slimstat-options');		
	echo '</p></div>';
}
if (isset($_GET['ot']) && $_GET['ot']=='yes'){
	$wpdb->query("OPTIMIZE TABLE `{$wpdb->prefix}slim_stats`");
	echo '<div id="wp-slimstat-message" class="updated fade"><p>';
	_e('Your WP SlimStat table has been successfully optimized.','wp-slimstat-options');		
	echo '</p></div>';
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
		$rows_affected =  $wpdb->query("DELETE FROM `{$wpdb->prefix}slim_stats` WHERE $delete_sql");
		echo '<div id="wp-slimstat-message" class="updated fade"><p>';
		_e('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-options').' '.intval($rows_affected);
		echo '</p></div>';
	}
}
?>

<h3><?php _e('Please note that these commands cannot be undone!','wp-slimstat-options') ?></h3>
<table class="form-table <?php echo $wp_locale->text_direction ?>">
<tbody>
<?php
$details_wp_slim_stat = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}slim_stats'", ARRAY_A);
if (count($details_wp_slim_stat) == 1) {
	$overhead_suffix = 'bytes';
	if ($details_wp_slim_stat[0]['Data_free'] > 1024){
		$details_wp_slim_stat[0]['Data_free'] = intval($details_wp_slim_stat[0]['Data_free']/1024);
		$overhead_suffix = 'KB';
	}
	if ($details_wp_slim_stat[0]['Data_free'] > 1024){
		$details_wp_slim_stat[0]['Data_free'] = intval($details_wp_slim_stat[0]['Data_free']/1024);
		$overhead_suffix = 'MB';
	}
	echo '<tr><th scope="row">'.__('Current Status','wp-slimstat-options').'</th>';
	echo '<td>'.__('Engine','wp-slimstat-options').": {$details_wp_slim_stat[0]['Engine']}<br/>";
	echo __('Records','wp-slimstat-options').": {$details_wp_slim_stat[0]['Rows']}<br/>";
	echo __('Average Record Length','wp-slimstat-options').": {$details_wp_slim_stat[0]['Avg_row_length']} bytes<br/>";
	echo __('Created on','wp-slimstat-options').": {$details_wp_slim_stat[0]['Create_time']}<br/>";
	echo __('Approximate Overhead','wp-slimstat-options').": {$details_wp_slim_stat[0]['Data_free']} $overhead_suffix ";
	if ($details_wp_slim_stat[0]['Data_free'] > 0) echo "[<a href='?page=wp-slimstat/options/index.php&ot=yes&slimpanel=5'>optimize</a>]";
	echo '</td></tr>';
}
?>
	<tr>
		<th scope="row"><?php _e('Clean database','wp-slimstat-options') ?></th>
		<td>
			<form action="options-general.php?page=wp-slimstat/options/index.php&slimpanel=5" method="post"
				onsubmit="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these rows from your database?','wp-slimstat-options'); ?>'))">
			<?php _e('Delete rows where','wp-slimstat-options') ?>
			<select name="options[conditional_delete_field]">
				<option value="country"><?php _e('Country Code','wp-slimstat-options') ?></option>
				<option value="domain"><?php _e('Domain','wp-slimstat-options') ?></option>
				<option value="INET_NTOA(ip)"><?php _e('IP Address','wp-slimstat-options') ?></option>
				<option value="language"><?php _e('Language Code','wp-slimstat-options') ?></option>
				<option value="resource"><?php _e('Permalink','wp-slimstat-options') ?></option>
				<option value="searchterms"><?php _e('Search Terms','wp-slimstat-options') ?></option>
			</select> 
			<select name="options[conditional_delete_operator]" style="width:12em">
				<option value="equal"><?php _e('Is equal to','wp-slimstat-options') ?></option>
				<option value="like"><?php _e('Contains','wp-slimstat-options') ?></option>
				<option value="not-like"><?php _e('Does not contain','wp-slimstat-options') ?></option>
				<option value="starts-with"><?php _e('Starts with','wp-slimstat-options') ?></option>
				<option value="ends-with"><?php _e('Ends with','wp-slimstat-options') ?></option>
				<option value="does-not-start-with"><?php _e('Does not start with','wp-slimstat-options') ?></option>
				<option value="does-not-end-with"><?php _e('Does not end with','wp-slimstat-options') ?></option>
			</select>
			<input type="text" name="options[conditional_delete_value]" id="delete_value" value="" size="20">
			<input type="submit" value="<?php _e('DELETE','wp-slimstat-options') ?>" class="button-primary" name="Submit">
			</form>
		</td>
	</tr>
	<tr><td>&nbsp;</td></tr>
	<tr>
		<th scope="row"><?php _e('Empty database','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=yes&slimpanel=5"><?php _e('DELETE STATS','wp-slimstat-options'); ?></a>
		</td>
	</tr>
	<tr><td>&nbsp;</td></tr>
	<tr valign="top">
		<th scope="row"><?php _e('Reset Ip-to-Countries','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&di2c=yes&slimpanel=5"><?php _e('EMPTY IP-TO-COUNTRIES','wp-slimstat-options'); ?></a>
		</td>
	</tr>
	<tr><td>&nbsp;</td></tr>
	
<?php 
$check_column = $wpdb->get_var("SHOW COLUMNS FROM `{$wpdb->prefix}slim_stats` LIKE 'browser_id'");
if (empty($check_column)): ?>
	<tr valign="top">
		<th scope="row"><?php _e('Old table detected','wp-slimstat-options') ?></th>
		<td>
			<a class="button-secondary" href="?page=wp-slimstat/options/index.php&rs=yes&slimpanel=5"><?php _e('RESET STATS','wp-slimstat-options'); ?></a>
			&mdash; <?php _e('It looks like you need to update the structure of one of the tables used by this plugin. Please click the button here above to reset your table (all the data will be lost, sorry), then deactivate/reactivate WP SlimStat to complete the installation process.','wp-slimstat-options') ?>
		</td>
	</tr>
<?php endif; ?>
</tbody>
</table>