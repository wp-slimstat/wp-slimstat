<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action') || (!empty($_POST) && !check_admin_referer('maintenance_wp_slimstat','maintenance_wp_slimstat_nonce'))) exit(0);

include_once(dirname(dirname(__FILE__))."/view/wp-slimstat-reports.php");
wp_slimstat_reports::init();

if (!empty($_REQUEST['action'])){
	switch ($_REQUEST['action']){
		case 'switch-engine':
			$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
			if ($have_innodb[0]['Value'] != 'YES') return;
			
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ENGINE = InnoDB");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_outbound ENGINE = InnoDB");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ENGINE = InnoDB");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ENGINE = InnoDB");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_content_info ENGINE = InnoDB");
			
			wp_slimstat_admin::show_alert_message(__('Your Slimstat tables have been successfully converted to InnoDB.','wp-slimstat'), 'updated below-h2');
			break;
		case 'delete-records':
			$rows_affected = 0;
			if (key_exists($_POST['f'], wp_slimstat_reports::$dropdown_filter_names)){
				$rows_affected = wp_slimstat::$wpdb->query('
					DELETE t1.* 
					FROM '.wp_slimstat_db::$sql_filters['from']['all_tables'].'
					WHERE 1=1 '.wp_slimstat_db::$sql_filters['where']);
			}
			wp_slimstat_admin::show_alert_message(intval($rows_affected).' '.__('records deleted from your database.','wp-slimstat'), 'updated below-h2');
			break;
		case 'truncate-table':
			wp_slimstat::$wpdb->query("DELETE tob FROM {$GLOBALS['wpdb']->prefix}slim_outbound tob");
			wp_slimstat::$wpdb->query("DELETE t1 FROM {$GLOBALS['wpdb']->prefix}slim_stats t1");
			wp_slimstat_admin::show_alert_message(__('All the records were successfully deleted.','wp-slimstat'), 'updated below-h2');
			break;
		case 'restore-views':
			$GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%slim%'");
			wp_slimstat_admin::show_alert_message(__('Your reports were successfully restored to their default arrangement.','wp-slimstat'), 'updated below-h2');
			break;
		case 'activate-indexes':
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX stats_resource_idx(resource(20))");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX stats_browser_idx(browser_id)");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers ADD INDEX browser_all_idx(browser,version,platform,css_version,type)");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres ADD INDEX screenres_all_idx(resolution,colordepth,antialias)");
			wp_slimstat_admin::show_alert_message(__('Congrats! Slimstat is now optimized for <a href="http://www.youtube.com/watch?v=ygE01sOhzz0" target="_blank">ludicrous speed</a>.','wp-slimstat'), 'updated below-h2');
			break;
		case 'deactivate-indexes':
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX stats_resource_idx");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX stats_browser_idx");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_browsers DROP INDEX browser_all_idx");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->base_prefix}slim_screenres DROP INDEX screenres_all_idx");
			wp_slimstat_admin::show_alert_message(__('Indexing has been successfully disabled. Enjoy the extra database space you just gained!','wp-slimstat'), 'updated below-h2');
			break;
		case 'import-settings':
			$new_options = @unserialize(stripslashes($_POST['import-slimstat-settings']));
			$new_options = array_intersect_key($new_options, wp_slimstat::$options);
			if (!empty($new_options)){
				foreach ($new_options as $a_option_name => $a_option_value){
					wp_slimstat::$options[$a_option_name] = $a_option_value;
				}
			}
			break;
		default:
	}
}

// Retrieve some information about the tables used by Slimstat
$check_index = wp_slimstat::$wpdb->get_results("SHOW INDEX FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE Key_name = 'stats_resource_idx'");
$details_wp_slim_tables = array_merge(
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_stats'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->prefix}slim_outbound'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_browsers'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_screenres'", ARRAY_A),
	wp_slimstat::$wpdb->get_results("SHOW TABLE STATUS LIKE '{$GLOBALS['wpdb']->base_prefix}slim_content_info'", ARRAY_A)
);
$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
$suffixes = array('bytes', 'KB', 'MB', 'GB', 'TB');
?>

<table class="form-table widefat">
<tbody>
	<tr>
		<td colspan="2" class="slimstat-options-section-header"><?php _e('Database Information','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row"><?php _e('Engine','wp-slimstat') ?></th>
		<td><?php 
			echo $details_wp_slim_tables[0]['Engine']; 
			if (!empty($have_innodb) && $have_innodb[0]['Value'] == 'YES' && $details_wp_slim_tables[0]['Engine'] == 'MyISAM'){
				echo ' [<a href="'.wp_slimstat_admin::$config_url.$current_tab.'&amp;action=switch-engine">'.__('switch to InnoDB','wp-slimstat').'</a>]';
			}
		?></td>
	</tr>
	<?php
		foreach ($details_wp_slim_tables as $i => $a_table){
			$base = ($a_table['Data_length'] != 0)?(log($a_table['Data_length']) / log(1024)):0;
			$a_table['Data_length_with_suffix'] = round(pow(1024, $base - floor($base)), 2).' '.$suffixes[floor($base)];
			
			echo '<tr '.(($i%2==0)?'class="alternate"':'').">
					<th scope='row'>{$a_table['Name']}</th>
					<td>".$a_table['Data_length_with_suffix'].'<br/>'.number_format($a_table['Rows'], 0).' '.__('records','wp-slimstat').'</td>
				  </tr>';
		}
	?>
	<tr>
		<td colspan="2" class="slimstat-options-section-header"><?php _e('Data Maintenance','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row" style="padding-top: 20px"><?php _e('Delete pageviews where','wp-slimstat') ?></th>
		<td>
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ); ?>
				<input type="hidden" name="action" value="delete-records" />
				
				<select name="f" id="slimstat-filter-name">
					<?php 
						foreach (wp_slimstat_reports::$dropdown_filter_names as $a_filter_id => $a_filter_name){
							echo "<option value='$a_filter_id'>$a_filter_name</option>";
						}
					?>
				</select> 
				<select name="o" id="slimstat-filter-operator">
					<option value="equals"><?php _e('equals','wp-slimstat') ?></option>
					<option value="is_not_equal_to"><?php _e('is not equal to','wp-slimstat') ?></option>
					<option value="contains"><?php _e('contains','wp-slimstat') ?></option>
					<option value="does_not_contain"><?php _e('does not contain','wp-slimstat') ?></option>
					<option value="starts_with"><?php _e('starts with','wp-slimstat') ?></option>
					<option value="ends_with"><?php _e('ends with','wp-slimstat') ?></option>
					<option value="sounds_like"><?php _e('sounds like','wp-slimstat') ?></option>
					<option value="is_greater_than"><?php _e('is greater than','wp-slimstat') ?></option>
					<option value="is_less_than"><?php _e('is less than','wp-slimstat') ?></option>
					<option value="matches"><?php _e('matches','wp-slimstat') ?></option>
					<option value="does_not_match"><?php _e('does not match','wp-slimstat') ?></option>
					<option value="is_empty"><?php _e('is empty','wp-slimstat') ?></option>
					<option value="is_not_empty"><?php _e('is not empty','wp-slimstat') ?></option>
				</select>
				<input type="text" name="v" id="slimstat-filter-value" value="" size="20">
				<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary" name="Submit"
					onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these records from your database?','wp-slimstat'); ?>'))" />
			</form>
		</td>
	</tr>
	<tr class="alternate">
		<th scope="row"><?php _e('Empty Database','wp-slimstat') ?></th>
		<td>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=truncate-table"
				onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY DELETE ALL the records from your database?','wp-slimstat'); ?>'))"><?php _e('Delete All Pageviews','wp-slimstat'); ?></a>
			<span class="description"><?php _e('Erase all the information collected so far by Slimstat. This operation <strong>does not</strong> reset your settings.','wp-slimstat') ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e('Reset Reports','wp-slimstat') ?></th>
		<td>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=restore-views"
				onclick="return(confirm('<?php _e('Are you sure you want to restore the default arrangement of your reports?','wp-slimstat'); ?>'))"><?php _e('No Panic Button','wp-slimstat'); ?></a>
			<span class="description"><?php _e("Reset the default arrangement of your reports. Helpful when, for some reason, reports disappear from your panels.",'wp-slimstat') ?></span>
		</td>
	</tr>
	<tr class="alternate">
		<th scope="row"><?php _e('Performance','wp-slimstat') ?></th>
		<?php if (empty($check_index)): ?>	
		<td>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=activate-indexes"><?php _e("Improve Performance",'wp-slimstat'); ?></a>
			<span class="description"><?php _e("Please note that you will need about 30% more DB space to store the extra information required.",'wp-slimstat') ?></span>
		</td>
		<?php else: ?>
		<td>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=deactivate-indexes"><?php _e('Save DB Space','wp-slimstat'); ?></a>
			<span class="description"><?php _e("Please note that by removing table indexes, Slimstat's performance will be affected.",'wp-slimstat') ?></span>
		</td>
		<?php endif ?>
	</tr>
	<tr>
		<td colspan="2" class="slimstat-options-section-header"><?php _e('Import and Export','wp-slimstat') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<strong><?php _e("Here below you can find the current configuration string for Slimstat. You can update your settings by pasting a new string here below and clicking on Import.",'wp-slimstat') ?></strong>
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ) ?>
				<input type="hidden" name="action" value="import-settings" />
				<textarea name="import-slimstat-settings" style="width:100%" rows="5" onClick="this.select();"><?php echo serialize(wp_slimstat::$options) ?></textarea><br/>
				<input type="submit" value="<?php _e('Import','wp-slimstat') ?>" class="button-secondary"
					onclick="return(confirm('<?php _e('Are you sure you want to OVERWRITE your current settings?','wp-slimstat'); ?>'))">
			</form>
		</td>
	</tr>
</tbody>
</table>