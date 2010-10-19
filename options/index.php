<?php 

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load localization files
load_plugin_textdomain('wp-slimstat-options', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

// Define the panels (true or false if you want the FORM wrapper around your panel)
$array_panels = array(
	array(__('General','wp-slimstat-options'), true),
	array(__('Views','wp-slimstat-options'), true),
	array(__('Filters','wp-slimstat-options'), true),
	array(__('Permissions','wp-slimstat-options'), true),
	array(__('Maintenance','wp-slimstat-options'), false),
	array(__('Thank you','wp-slimstat-options'), false)
);

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']);

// Text direction
if ($wp_locale->text_direction != 'ltr') $array_panels = array_reverse($array_panels, true);

// Update the options
if (isset($_POST['options'])){

	$faulty_fields = '';
	if (isset($_POST['options']['is_tracking']) && !slimstat_update_option('slimstat_is_tracking', $_POST['options']['is_tracking'], 'yesno')) $faulty_fields = __('Activate tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_javascript']) && !slimstat_update_option('slimstat_enable_javascript', $_POST['options']['enable_javascript'], 'yesno')) $faulty_fields = __('Enable JS Tracking','wp-slimstat-options').', ';
	if (isset($_POST['options']['custom_js_path']) && !slimstat_update_option('slimstat_custom_js_path', $_POST['options']['custom_js_path'], 'text')) $faulty_fields = __('Custom path','wp-slimstat-options').', ';
	if (isset($_POST['options']['browscap_autoupdate']) && !slimstat_update_option('slimstat_browscap_autoupdate', $_POST['options']['browscap_autoupdate'], 'yesno')) $faulty_fields = __('Autoupdate Browsers DB','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_interval']) && !slimstat_update_option('slimstat_ignore_interval', $_POST['options']['ignore_interval'], 'integer')) $faulty_fields .= __('Ignore interval','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_bots']) && !slimstat_update_option('slimstat_ignore_bots', $_POST['options']['ignore_bots'], 'yesno')) $faulty_fields .= __('Ignore bots','wp-slimstat-options').', ';
	if (isset($_POST['options']['track_users']) && !slimstat_update_option('slimstat_track_users', $_POST['options']['track_users'], 'yesno')) $faulty_fields .= __('Track users','wp-slimstat-options').', ';	
	if (isset($_POST['options']['auto_purge']) && !slimstat_update_option('slimstat_auto_purge', $_POST['options']['auto_purge'], 'integer')) $faulty_fields .= __('Auto purge','wp-slimstat-options').', ';
	if (isset($_POST['options']['use_separate_menu']) && !slimstat_update_option('slimstat_use_separate_menu', $_POST['options']['use_separate_menu'], 'yesno')) $faulty_fields .= __('Use separate menu','wp-slimstat-options').', ';
	if (isset($_POST['options']['convert_ip_addresses']) && !slimstat_update_option('slimstat_convert_ip_addresses', $_POST['options']['convert_ip_addresses'], 'yesno')) $faulty_fields .= __('Convert IP addresses','wp-slimstat-options').', ';
	if (isset($_POST['options']['rows_to_show']) && !slimstat_update_option('slimstat_rows_to_show', $_POST['options']['rows_to_show'], 'integer')) $faulty_fields .= __('Limit results to','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_ip']) && !slimstat_update_option('slimstat_ignore_ip', $_POST['options']['ignore_ip'], 'list')) $faulty_fields .= __('Ignore IPs','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_resources']) && !slimstat_update_option('slimstat_ignore_resources', $_POST['options']['ignore_resources'], 'list')) $faulty_fields .= __('Ignore resources','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_browsers']) && !slimstat_update_option('slimstat_ignore_browsers', $_POST['options']['ignore_browsers'], 'list')) $faulty_fields .= __('Ignore browsers','wp-slimstat-options').', ';
	if (isset($_POST['options']['ignore_users']) && !slimstat_update_option('slimstat_ignore_users', $_POST['options']['ignore_users'], 'list')) $faulty_fields .= __('Ignore users','wp-slimstat-options').', ';
	if (isset($_POST['options']['can_view']) && !slimstat_update_option('slimstat_can_view', $_POST['options']['can_view'], 'list')) $faulty_fields .= __('Who can view the reports','wp-slimstat-options').', ';
	if (isset($_POST['options']['can_admin']) && !slimstat_update_option('slimstat_can_admin', $_POST['options']['can_admin'], 'list')) $faulty_fields .= __('Who can manage these options','wp-slimstat-options').', ';
	if (isset($_POST['options']['enable_footer_link']) && !slimstat_update_option('slimstat_enable_footer_link', $_POST['options']['enable_footer_link'], 'yesno')) $faulty_fields .= __('Show footer link','wp-slimstat-options').', ';
	
	// If the case, delete rows
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
		$message_to_show = __('Your WP SlimStat table has been successfully cleaned. Rows affected:','wp-slimstat-options').' '.intval($rows_affected);
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
			_e('Your settings have been successfully updated.','wp-slimstat-options');
		}
		else{
			echo $message_to_show;
		}
	}
	else{
		_e('There was an error updating the following fields:','wp-slimstat-options');
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
			update_option($_option, strip_tags($_value));
			return true;
			break;
	}
	
	return false;
}
?>
<div class="wrap">
	<div id="analytics-icon" class="<?php echo $wp_locale->text_direction ?>"></div>
	<h2 class="medium">
		<?php
		foreach($array_panels as $a_panel_id => $a_panel_details){
			echo '<a class="menu-tabs';
			if ($current_panel != $a_panel_id+1) echo ' menu-tab-inactive';
			echo '" href="admin.php?page=wp-slimstat/options/index.php&slimpanel='.($a_panel_id+1).'">'.$a_panel_details[0].'</a>';
		}
		?>
	</h2>

	<?php
		if (isset($_GET['ds']) || isset($_GET['di2c'])){
			echo '<div id="wp-slimstat-message" class="updated fade"><p>';
			if (isset($_GET['ds']) && $_GET['ds']=='yes'){
				_e('Are you sure you want to remove all the information about your hits and visits?','wp-slimstat-options');
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&ds=confirm">'.__('Yes','wp-slimstat-options').'</a>';
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php">'.__('No','wp-slimstat-options').'</a>';
			}
			if (isset($_GET['ds']) && $_GET['ds']=='confirm'){
				$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_stats`");
				$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_visits`");
				_e('Your WP SlimStat table has been successfully emptied.','wp-slimstat-options');
			}
			if (isset($_GET['di2c']) && $_GET['di2c']=='yes'){
				_e('Are you sure you want to drop the ip-to-countries table?','wp-slimstat-options');
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php&di2c=confirm">'.__('Yes','wp-slimstat-options').'</a>';
				echo ' <a class="button-secondary" href="?page=wp-slimstat/options/index.php">'.__('No','wp-slimstat-options').'</a>';
			}
			if (isset($_GET['di2c']) && $_GET['di2c']=='confirm'){
				$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}slim_countries`");
				_e('Your IP-to-countries table has been successfully emptied. Now go to your Plugins panel and deactivate/reactivate WP SlimStat to load the new data.','wp-slimstat-options');
			}
			echo '</p></div>';
		}
		if (isset($_GET['rs']) && $_GET['rs']=='yes'){
			if (!isset($wp_slimstat_object)) $wp_slimstat_object = new wp_slimstat();
			$wpdb->query("DROP TABLE IF EXISTS `$wp_slimstat_object->table_stats`");
			echo '<div id="wp-slimstat-message" class="updated fade"><p>';
			_e('Your WP SlimStat table has been successfully reset. Now go to your Plugins panel and deactivate/reactivate WP SlimStat.','wp-slimstat-options');		
			echo '</p></div>';
		}
		if (isset($_GET['ot']) && $_GET['ot']=='yes'){
			if (!isset($wp_slimstat_object)) $wp_slimstat_object = new wp_slimstat();
			$wpdb->query("OPTIMIZE TABLE `$wp_slimstat_object->table_stats`");
			echo '<div id="wp-slimstat-message" class="updated fade"><p>';
			_e('Your WP SlimStat table has been successfully optimized.','wp-slimstat-options');		
			echo '</p></div>';
		}
	?>
	
	<?php if ($array_panels[$current_panel-1][1]) { ?><form action="admin.php?page=wp-slimstat/options/index.php<?php if(!empty($_GET['slimpanel'])) echo '&slimpanel='.$_GET['slimpanel']; ?>" method="post"><?php } ?>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php"); ?>
	
	<?php if ($array_panels[$current_panel-1][1]) { ?><p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p><?php } ?>

<?php if ($array_panels[$current_panel-1][1]) { ?></form><?php } ?>
</div>
