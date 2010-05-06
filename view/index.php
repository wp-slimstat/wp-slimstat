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

// Detect filters
$filters_to_parse = array(
	'day' => 'integer',
	'month' => 'integer', 
	'year' => 'integer',
	'browser' => 'string',
	'version' => 'string',
	'css_version' => 'string',
	'country' => 'string',
	'domain' => 'string',
	'ip' => 'string',
	'language' => 'string',
	'platform' => 'string',
	'resource' => 'string',
	'referer' => 'string',
	'resolution' => 'string',
	'searchterms' => 'string'
);

$filters_parsed = array();
		
foreach ($filters_to_parse as $a_filter_label => $a_filter_type){
	if (!empty($_GET['filter']) && !empty($_GET['f_value']) && !empty($_GET['f_operator']) && $_GET['filter']==$a_filter_label){
		$f_value = ($a_filter_type == 'integer')?intval($_GET['f_value']):$wpdb->escape(strip_tags(str_replace('\\', '', $_GET['f_value'])));
		$f_operator = $wpdb->escape(strip_tags(str_replace('\\', '', $_GET['f_operator'])));
		$filters_parsed[$a_filter_label] = array($f_value, $f_operator);
	}
	else if(!empty($_GET[$a_filter_label])){
		$f_value = ($a_filter_type == 'integer')?intval($_GET[$a_filter_label]):$wpdb->escape(strip_tags(str_replace('\\', '', $_GET[$a_filter_label])));
		$f_operator = !empty($_GET[$a_filter_label.'-op'])?$wpdb->escape(strip_tags(str_replace('\\', '', $_GET[$a_filter_label.'-op']))):'equals';
		$filters_parsed[$a_filter_label] = array($f_value, $f_operator);
	}
}
		
$filters_list = $filters_query = '';
if (!empty($filters_parsed)){
	$filters_list = __('Current filters:','wp-slimstat-view').' ';
	foreach($filters_parsed as $a_filter_label => $a_filter_details){
		$a_filter_value_no_slashes = str_replace('\\','', $a_filter_details[0]);
		$filters_list .= "<code>{$a_filter_label} {$a_filter_details[1]} {$a_filter_value_no_slashes}</code>, ";
		$filters_query .= "&amp;{$a_filter_label}={$a_filter_value_no_slashes}&amp;{$a_filter_label}-op={$a_filter_details[1]}";
	}
}

// Reset MySQL timezone settings, our dates and times are recorded using WP settings
$wpdb->query("SET @@session.time_zone = '+00:00'");

// Import class definition
require_once(WP_PLUGIN_DIR."/wp-slimstat/view/wp-slimstat-view.php");

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']); 

// Text direction
if ($wp_locale->text_direction != 'ltr') $array_panels = array_reverse($array_panels, true);

?>

<div class="wrap">
	<div id="analytics-icon" class="<?php echo $wp_locale->text_direction ?>"></div>
	<h2 class="medium">
		<?php		
		foreach($array_panels as $a_panel_id => $a_panel_name){
			echo '<a class="menu-tabs';
			if ($current_panel != $a_panel_id+1) echo ' menu-tab-inactive';
			echo '" href="index.php?page=wp-slimstat/view/index.php&slimpanel='.($a_panel_id+1).$filters_query.'">'.$a_panel_name.'</a>';
		}
		?>
	</h2>
	
	<form action="index.php" method="get">
		<input type="hidden" name="page" value="wp-slimstat/view/index.php">
		<input type="hidden" name="slimpanel" value="<?php echo intval($_GET['slimpanel']) ?>">
		<?php // Keep other filters persistent
			foreach($filters_parsed as $a_filter_label => $a_filter_details){
				echo "<input type='hidden' name='{$a_filter_label}' value='{$a_filter_details[0]}'>";
				echo "<input type='hidden' name='{$a_filter_label}-op' value='{$a_filter_details[1]}'>";
			}
		?>
		<p><span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Filter pageviews where','wp-slimstat-view') ?>
			<select name="filter">
				<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
				<option value="version"><?php _e('Browser version','wp-slimstat-view') ?></option>
				<option value="css_version"><?php _e('CSS version','wp-slimstat-view') ?></option>
				<option value="country"><?php _e('Country Code','wp-slimstat-view') ?></option>
				<option value="domain"><?php _e('Domain','wp-slimstat-view') ?></option>
				<option value="ip"><?php _e('IP','wp-slimstat-view') ?></option>
				<option value="searchterms"><?php _e('Keywords','wp-slimstat-view') ?></option>
				<option value="language"><?php _e('Language Code','wp-slimstat-view') ?></option>
				<option value="platform"><?php _e('Operating System','wp-slimstat-view') ?></option>
				<option value="resource"><?php _e('Permalink','wp-slimstat-view') ?></option>
				<option value="referer"><?php _e('Referer','wp-slimstat-view') ?></option>
				<option value="resolution"><?php _e('Screen Resolution','wp-slimstat-view') ?></option>
			</select> 
			<select name="f_operator" style="width:12em">
				<option value="equals"><?php _e('Is equal to','wp-slimstat-view') ?></option>
				<option value="contains"><?php _e('Contains','wp-slimstat-view') ?></option>
				<option value="does not contain"><?php _e('Does not contain','wp-slimstat-view') ?></option>
				<option value="starts with"><?php _e('Starts with','wp-slimstat-view') ?></option>
				<option value="ends with"><?php _e('Ends with','wp-slimstat-view') ?></option>
			</select>
			<input type="text" name="f_value" value="" size="15">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Filter by date','wp-slimstat-view') ?> <select name="day" style="width:8em">
				<option value=""><?php _e('Day','wp-slimstat-view') ?></option>
				<option>01</option><option>02</option><option>03</option><option>04</option><option>05</option>
				<option>06</option><option>07</option><option>08</option><option>09</option><option>10</option>
				<option>11</option><option>12</option><option>13</option><option>14</option><option>15</option>
				<option>16</option><option>17</option><option>18</option><option>19</option><option>20</option>
				<option>21</option><option>22</option><option>23</option><option>24</option><option>25</option>
				<option>26</option><option>27</option><option>28</option><option>29</option><option>30</option>
				<option>31</option>
			</select> 
			<select name="month" style="width:8em">
				<option value=""><?php _e('Month','wp-slimstat-view') ?></option>
				<option>01</option><option>02</option><option>03</option><option>04</option><option>05</option>
				<option>06</option><option>07</option><option>08</option><option>09</option><option>10</option>
				<option>11</option><option>12</option>
			</select>
			<select name="year" style="width:8em">
				<option value=""><?php _e('Year','wp-slimstat-view') ?></option>
				<?php
					$current_year = date_i18n('Y'); 
					for($i=$current_year;$i>$current_year-3;$i--)
						echo "<option>$i</option>";
				?>
			</select>
			<input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary"></span>
		</p>
	</form>
	
	<p style="clear:both;padding:6px 6px 0"><?php if (!empty($filters_list)) echo substr($filters_list, 0, -2).' [<a href="index.php?page=wp-slimstat/view/index.php&slimpanel='.($current_panel).'">'.__('reset','wp-slimstat-view').'</a>]'; ?></p>
	
	<?php 
		// Instantiate a new copy of the class
		$wp_slimstat_view = new wp_slimstat_view();
		
		if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php"); 
	?>
</div>