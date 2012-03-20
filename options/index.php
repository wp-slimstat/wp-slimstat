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
	__('General','wp-slimstat-options'),
	__('Views','wp-slimstat-options'),
	__('Filters','wp-slimstat-options'),
	__('Permissions','wp-slimstat-options'),
	__('Maintenance','wp-slimstat-options'),
	__('Support','wp-slimstat-options')
);

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']);

global $wp_slimstat;

function slimstat_error_message($_faulty_fields){
	// Display an alert in the admin interface if something went wrong
	echo '<div class="updated fade"><p>';
	if (empty($_faulty_fields)){
			_e('Your settings have been successfully updated.','wp-slimstat-options');
	}
	else{
		_e('There was an error updating the following fields:','wp-slimstat-options');
		echo ' <strong>'.substr($_faulty_fields,0,-2).'</strong>';
	}
	echo "</p></div>\n";
}
?>
<div class="wrap">
	<div id="analytics-icon" class="icon32 <?php echo $wp_locale->text_direction ?>"></div>
	<h2 class="medium">
		<?php
		$admin_page_url = ($GLOBALS['wp_slimstat']->options['use_separate_menu'] == 'yes' || !current_user_can('manage_options'))?'admin.php':'options-general.php';
		foreach($array_panels as $a_panel_id => $a_panel_details){
			echo '<a class="nav-tab nav-tab';
			echo ($current_panel == $a_panel_id+1)?'-active':'-inactive';
			echo '" href="'.$admin_page_url.'?page=wp-slimstat/options/index.php&slimpanel='.($a_panel_id+1).'">'.$a_panel_details.'</a>';
		}
		?>
	</h2>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php"); ?>
	
</div>
