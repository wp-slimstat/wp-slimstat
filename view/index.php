<?php 

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load localization files
load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');
load_plugin_textdomain('countries-languages', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');

// Define the panels
$array_panels = array(
	__('Dashboard','wp-slimstat-view'), 
	__('Visitors','wp-slimstat-view'), 
	__('Traffic Sources','wp-slimstat-view'), 
	__('Content','wp-slimstat-view'), 
	__('Custom Reports','wp-slimstat-view')
);

// Import class definition
require_once(WP_PLUGIN_DIR."/wp-slimstat/view/wp-slimstat-view.php");

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']); 

?>

<div class="wrap">
	<div id="analytics-icon"></div>
	<h2 class="medium">
		<?php
		foreach($array_panels as $a_panel_id => $a_panel_name){
			echo '<a class="menu-tabs';
			if ($current_panel != $a_panel_id+1) echo ' menu-tab-inactive';
			echo '" href="index.php?page=wp-slimstat/view/index.php&slimpanel='.($a_panel_id+1).'">'.$a_panel_name.'</a>';
		}
		?>
	</h2>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php"); ?>
</div>