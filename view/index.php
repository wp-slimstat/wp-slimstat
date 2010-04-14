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

// Let's extend the main class with the methods we use in this panel
class wp_slimstat_view extends wp_slimstat {

	function __construct(){
		parent::__construct();
	
		// TODO: get filters from $_GET
		$this->current_date = array();
		$this->current_date['d'] = date_i18n('d');
		$this->current_date['m'] = date_i18n('m');
		$this->current_date['y'] = date_i18n('Y');
	
		$this->yesterday['d'] = date_i18n('d', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 

		$this->previous_month['m'] = $this->current_date['m'] - 1;
		$this->previous_month['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
		$this->previous_month['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
	}
	
	public function get_recent($_field = 'id', $_field2 = '', $_limit_lenght = 30){
		global $wpdb;
	
		$sql = "SELECT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string, `$_field` long_string, LENGTH(`$_field`) len
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats` 
				WHERE `$_field` <> ''
				GROUP BY long_string
				ORDER BY `dt` DESC
				LIMIT 20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top($_field = 'id', $_field2 = '', $_limit_lenght = 30, $_only_current_month = false){
		global $wpdb;

		$sql = "SELECT DISTINCT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats`
				WHERE `$_field` <> ''
				".($_only_current_month?" AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})":'')."
				GROUP BY long_string
				ORDER BY count DESC
				LIMIT 20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_total_count(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats`";
	
		return intval($wpdb->get_var($sql));
	}
	
	public function get_referer_count(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats`
				WHERE `referer` <> ''";
	
		return intval($wpdb->get_var($sql));
	}

}

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