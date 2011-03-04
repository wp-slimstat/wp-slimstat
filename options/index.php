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

// Text direction
if ($wp_locale->text_direction != 'ltr') $array_panels = array_reverse($array_panels, true);

function slimstat_update_option( $_option, $_value, $_type ){
	if (!isset($_value)) return true;

	switch($_type){
		case 'list':
			// Avoid XSS attacks
			$clean_value = preg_replace('/[^a-zA-Z0-9\,\.\/\ \-\_]/', '', $_value);
			if (strlen($_value)==0){
				update_option('slimstat_'.$_option, array());
			}
			else {
				$array_values = explode(',',$clean_value);
				update_option('slimstat_'.$_option, $array_values);
			}
			return true;
		case 'yesno':
			if ($_value=='yes' || $_value=='no'){
				update_option('slimstat_'.$_option, $_value);
				return true;
			}
			break;
		case 'integer':
			update_option('slimstat_'.$_option, abs(intval($_value)));
			return true;
			
		default:
			update_option('slimstat_'.$_option, strip_tags($_value));
			return true;
	}
	
	return false;
}
function slimstat_get_option($_option = '', $_default = ''){
	$value = get_option('slimstat_'.$_option, $_default);
	if (is_string($value)) $value = stripslashes($value);
	return($value);
}
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
		foreach($array_panels as $a_panel_id => $a_panel_details){
			echo '<a class="nav-tab nav-tab';
			echo ($current_panel == $a_panel_id+1)?'-active':'-inactive';
			echo '" href="options-general.php?page=wp-slimstat/options/index.php&slimpanel='.($a_panel_id+1).'">'.$a_panel_details.'</a>';
		}
		?>
	</h2>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/options/panel$current_panel.php"); ?>
	
</div>
