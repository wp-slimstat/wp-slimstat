<?php 

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load localization files
load_plugin_textdomain('wp-slimstat-options', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');

// Define the panels (true or false if you want the FORM wrapper around your panel)
$array_panels = array(
	array(__('General','wp-slimstat-options'), true), 
	array(__('Filters','wp-slimstat-options'), true), 
	array(__('Permissions','wp-slimstat-options'), true), 
	array(__('Maintenance','wp-slimstat-options'), false),
	array(__('Thank you','wp-slimstat-options'), false)
);

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']);

// Update the options
if (isset($_POST['options'])){

	$faulty_fields = '';
	
	if (!slimstat_update_option('slimstat_is_tracking', $_POST['options']['is_tracking'], 'yesno')) $faulty_fields = __('Activate tracking','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_ignore_interval', $_POST['options']['ignore_interval'], 'integer')) $faulty_fields .= __('Ignore interval','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_ignore_bots', $_POST['options']['ignore_bots'], 'yesno')) $faulty_fields .= __('Ignore bots','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_auto_purge', $_POST['options']['auto_purge'], 'integer')) $faulty_fields .= __('Auto purge','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_ignore_ip', $_POST['options']['ignore_ip'], 'list')) $faulty_fields .= __('Ignore IPs','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_ignore_resources', $_POST['options']['ignore_resources'], 'list')) $faulty_fields .= __('Ignore resources','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_ignore_browsers', $_POST['options']['ignore_browsers'], 'list')) $faulty_fields .= __('Ignore browsers','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_can_view', $_POST['options']['can_view'], 'list')) $faulty_fields .= __('Who can view the reports','wp-slimstat-view').', ';
	if (!slimstat_update_option('slimstat_can_admin', $_POST['options']['can_admin'], 'list')) $faulty_fields .= __('Who can manage these options','wp-slimstat-view').', ';
	
	// If the case, delete rows
	if (isset($_POST['options']['conditional_delete_field']) &&
		isset($_POST['options']['conditional_delete_operator']) &&
		isset($_POST['options']['conditional_delete_value']) &&
		($_POST['options']['conditional_delete_field'] == 'country' ||
			$_POST['options']['conditional_delete_field'] == 'domain' ||
			$_POST['options']['conditional_delete_field'] == 'ip' ||
			$_POST['options']['conditional_delete_field'] == 'language' ||
			$_POST['options']['conditional_delete_field'] == 'resource' ||
			$_POST['options']['conditional_delete_field'] == 'searchterms')){
		$escaped_value = $wpdb->escape($_POST['options']['conditional_delete_value']);
		if ($_POST['options']['conditional_delete_operator'] == 'equal'){
			$rows_affected =  $wpdb->query("DELETE FROM `{$table_prefix}slim_stats` 
							WHERE `{$_POST['options']['conditional_delete_field']}` = '$escaped_value'");
			$message_to_show = __('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-view').' '.intval($rows_affected);
		}
		if ($_POST['options']['conditional_delete_operator'] == 'like'){
			$rows_affected = $wpdb->query("DELETE FROM `{$table_prefix}slim_stats` 
							WHERE `{$_POST['options']['conditional_delete_field']}` LIKE '%$escaped_value%'");
			$message_to_show = __('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-view').' '.intval($rows_affected);
		}
		if ($_POST['options']['conditional_delete_operator'] == 'not like'){
			$rows_affected = $wpdb->query("DELETE FROM `{$table_prefix}slim_stats` 
							WHERE `{$_POST['options']['conditional_delete_field']}` NOT LIKE '%$escaped_value%'");
			$message_to_show = __('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-view').' '.intval($rows_affected);
		}
	}
	
	// If autopurge = 0, we can unschedule our cron job. If autopurge > 0 and the hook was not scheduled, we schedule it
	if (isset($_POST['options']['auto_purge'])){
		if ($_POST['options']['auto_purge'] == 0){
			wp_clear_scheduled_hook('wp_slimstat_purge');
		}
		else if (wp_next_scheduled( 'my_schedule_hook' ) == 0){
			wp_schedule_event(time(), 'daily', 'wp_slimstat_purge');
		}
	}
	// Display an alert in the admin interface if something went wrong
	echo '<div id="wp-slimstat-message" class="updated fade"><p>';
	if (empty($faulty_fields)){
		if (empty($message_to_show)){
			_e('Your settings have been successfully updated.','wp-slimstat-view');
		}
		else{
			echo $message_to_show;
		}
	}
	else{
		_e('There was an error updating the following fields:','wp-slimstat-view');
		echo ' <strong>'.substr($faulty_fields,0,-2).'</strong>';
	}
	echo "</p></div>\n";
}

function slimstat_update_option( $_option, $_value, $_type ){
	if (!isset($_value)) return true;

	switch($_type){
		case 'list':
			// Avoid XSS attacks
			$clean_value = preg_replace('/[^a-zA-Z0-9\,\.\/]/', '', $_value);
			if (strlen($_value)==0){
				update_option($_option, array());
			}
			else {
				$array_values = explode(',',$clean_value);
				update_option($_option, $array_values);
			}
			
			return true;
			break;
		case 'yesno':
			if ($_value=='yes' || $_value=='no'){
				update_option($_option, $_value);
				return true;
			}
			
			break;
		case 'integer':
			update_option($_option, abs(intval($_value)));
			
			return true;
			break;
		default:
			break;
	}
	
	return false;
}

?>

<div class="wrap">
	<div id="analytics-icon"></div>
	<h2 class="medium">
		<?php
		foreach($array_panels as $a_panel_id => $a_panel_details){
			echo '<a class="menu-tabs';
			if ($current_panel != $a_panel_id+1) echo ' menu-tab-inactive';
			echo '" href="options-general.php?page=wp-slimstat/options/index.php&slimpanel='.($a_panel_id+1).'">'.$a_panel_details[0].'</a>';
		}
		?>
	</h2>

	<?php
		if (isset($_GET['ds'])){
			echo '<div id="wp-slimstat-message" class="updated fade"><p>';
			if ($_GET['ds']=='yes'){
				_e('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat-view');
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=confirm">'.__('Yes','wp-slimstat-view').'</a>';
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php">'.__('No','wp-slimstat-view').'</a>';
			}
			if ($_GET['ds']=='confirm'){
				$wpdb->query("TRUNCATE TABLE `{$table_prefix}slim_stats`");
				$wpdb->query("TRUNCATE TABLE `{$table_prefix}slim_visits`");
				_e('Your WP SlimStat table has been successfully emptied.','wp-slimstat-view');
			}
			echo '</p></div>';
		}
		if (isset($_GET['rs']) && $_GET['rs']=='yes'){
			$wp_slimstat_object = new wp_slimstat();
			$wpdb->query("DROP TABLE IF EXISTS `$wp_slimstat_object->table_stats`");
			echo '<div id="wp-slimstat-message" class="updated fade"><p>';
			_e('Your WP SlimStat table has been successfully reset. Now go to your Plugins panel and deactivate/reactivate WP SlimStat.','wp-slimstat-view');		
			echo '</p></div>';
		}
	?>
	
	<?php if ($array_panels[$current_panel][1]) { ?><form action="options-general.php?page=wp-slimstat/options/index.php<?php if(!empty($_GET['slimpanel'])) echo '&slimpanel='.$_GET['slimpanel']; ?>" method="post"><?php } ?>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php"); ?>
	
	<?php if ($array_panels[$current_panel][1]) { ?><p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p><?php } ?>

<?php if ($array_panels[$current_panel][1]) { ?></form><?php } ?>
</div>
