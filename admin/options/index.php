<?php 
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Load localization files
load_plugin_textdomain('wp-slimstat-options', WP_PLUGIN_DIR .'/wp-slimstat/admin/lang', '/wp-slimstat/admin/lang');

// Define the panels (true or false if you want the FORM wrapper around your panel)
$array_panels = array(
	__('General','wp-slimstat-options'),
	__('Views','wp-slimstat-options'),
	__('Filters','wp-slimstat-options'),
	__('Permissions','wp-slimstat-options'),
	__('Advanced','wp-slimstat-options'),
	__('Maintenance','wp-slimstat-options'),
	__('Support','wp-slimstat-options')
);

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']);

?>
<div class="wrap">
	<div id="analytics-icon" class="icon32 <?php echo $wp_locale->text_direction ?>"></div>
	<h2>WP SlimStat</h2>
	<p class="nav-tabs">
<?php
	$admin_page_url = (wp_slimstat::$options['use_separate_menu'] == 'yes' || !current_user_can('manage_options'))?'admin.php':'options-general.php';
	foreach($array_panels as $a_panel_id => $a_panel_details){
		echo "<a class='nav-tab nav-tab".(($current_panel == $a_panel_id+1)?'-active':'-inactive')."' href='$admin_page_url?page=wp-slimstat/admin/options/index.php&slimpanel=".($a_panel_id+1)."'>$a_panel_details</a>";
	}
?>
	</p>
	<form action="<?php echo "$admin_page_url?page=wp-slimstat/admin/options/index.php&slimpanel=$current_panel" ?>" method="post">
<?php
	if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/admin/options/panel$current_panel.php")) include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/options/panel$current_panel.php");

	// Update the options, defined by the file here above
	if (isset($_POST['options']) && isset($options_on_this_page)){
		foreach($options_on_this_page as $_option_name => $_option_details){
			wp_slimstat_admin::update_setting($_option_name, $_option_details);
		}

		if (!empty(wp_slimstat_admin::$faulty_fields)){
			wp_slimstat_admin::show_alert_message(__('There was an error updating the following fields:','wp-slimstat-options').' '.implode(', ', wp_slimstat_admin::$faulty_fields), 'updated below-h2');
		}
		else{
			wp_slimstat_admin::show_alert_message(__('Your settings have been successfully updated.','wp-slimstat-options'), 'updated below-h2');
		}
	}
	
	if (isset($options_on_this_page)):
?>
		<table class="form-table <?php echo $wp_locale->text_direction ?>">
		<tbody>
<?php
			foreach($options_on_this_page as $_option_name => $_option_details){
				wp_slimstat_admin::settings_table_row($_option_name, $_option_details);
			}
?>
		</tbody>
		</table>
<?php
		foreach($options_on_this_page as $_option_name => $_option_details){
			wp_slimstat_admin::settings_textarea($_option_name, $_option_details);
		}
?>
		<p class="submit"><input type="submit" value="<?php _e('Save Changes') ?>" class="button-primary" name="Submit"></p>	
	</form>
<?php 
	endif;
?>
</div>
