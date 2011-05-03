<?php 
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load localization files
load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');
load_plugin_textdomain('countries-languages', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

// If a local translation for countries and languages does not exist, use English
if (!isset($l10n['countries-languages'])){
	load_textdomain('countries-languages', WP_PLUGIN_DIR .'/wp-slimstat/lang/countries-languages-en_US.mo');
}

// Define the panels
$array_panels = array(
	__('Dashboard','wp-slimstat-view'), 
	__('Visitors','wp-slimstat-view'), 
	__('Traffic Sources','wp-slimstat-view'), 
	__('Content','wp-slimstat-view'), 
	__('Raw Data','wp-slimstat-view'), 
	__('World Map','wp-slimstat-view'), 
	__('Custom Reports','wp-slimstat-view')
);

// What panel to display
$current_panel = empty($_GET['slimpanel'])?1:intval($_GET['slimpanel']);


// Import class definition
require_once(WP_PLUGIN_DIR."/wp-slimstat/view/wp-slimstat-view.php");

// Instantiate a new copy of the class
$wp_slimstat_view = new wp_slimstat_view();

$filters_list = $filters_query = '';
if (!empty($wp_slimstat_view->filters_parsed)){
	$filters_list = __('Current filters:','wp-slimstat-view').' ';
	foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
		$a_filter_value_no_slashes = str_replace('\\','', $a_filter_details[0]);
		$filters_list .= "<code>".htmlspecialchars("{$a_filter_label} {$a_filter_details[1]} {$a_filter_value_no_slashes}")."</code>, ";
		$filters_query .= "&amp;{$a_filter_label}={$a_filter_value_no_slashes}&amp;{$a_filter_label}-op={$a_filter_details[1]}";
	}
}

// Reset MySQL timezone settings, our dates and times are recorded using WP settings
$wpdb->query("SET @@session.time_zone = '+00:00'");

// Invert the order of all panels
$reverse = ($wp_slimstat_view->direction == 'ASC')?'DESC':'ASC';

// Filter for the Raw Data page
$allowed_functions = array(
	'get_details_recent_visits',
	'get_recent_404',
	'get_recent_bouncing_pages',
	'get_recent_countries',
	'get_recent_resources',
	'get_recent_searchterms',
	'get_top_resources',
	'get_top_searchterms',
	'get_top_traffic_sources'
);
$function_to_use = '';
if (!empty($_GET['ftu']) && in_array($_GET['ftu'], $allowed_functions)) $function_to_use = $_GET['ftu'];

// Utilities
function title_period($_title_string, $_extra_class = ' noscroll'){
	global $wp_slimstat_view;
	
	echo "<h3>$_title_string ";
	if (empty($wp_slimstat_view->day_interval)){
		if ($wp_slimstat_view->day_filter_active) echo $wp_slimstat_view->current_date['d'].'/';
		echo $wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; 
	}
	else
		_e('this period', 'wp-slimstat-view');
	echo "</h3><div class='container$_extra_class'>";
}
function trim_value($_string = '', $_length = 32){
	if (strlen($_string) > $_length){
		$result['text'] = substr($_string, 0, $_length).'...';
		$result['tooltip'] = " title='".htmlspecialchars($_string, ENT_QUOTES)."'";
	}
	else{
		$result['text'] = $_string;
		$result['tooltip'] = '';
	}
	$result['text'] = str_replace('\\', '', htmlspecialchars($result['text'], ENT_QUOTES));
	return $result;
}
?>

<script type="text/javascript">
jQuery(document).ready(function(){
 jQuery(".slimstat-tooltips p").hover(
	function(){
		this.savetitle = this.title;
		jQuery(this).append('<b id="wp-element-details">'+this.title+'</b>');
		this.title = '';
	},
	function(){
		this.title = this.savetitle;
		jQuery("#wp-element-details").remove();
	});
});
</script>

<div class="wrap">
	<div id="analytics-icon" class="icon32 <?php echo $wp_locale->text_direction ?>"></div>
	<h2 class="medium">
		<?php		
		foreach($array_panels as $a_panel_id => $a_panel_name){
			echo '<a class="nav-tab nav-tab';
			echo ($current_panel == $a_panel_id+1)?'-active':'-inactive';
			echo '" href="index.php?page=wp-slimstat/view/index.php&slimpanel='.($a_panel_id+1).$filters_query.'&direction='.$wp_slimstat_view->direction.'">'.$a_panel_name.'</a>';
		}
		?>
	</h2>
	
	<form action="index.php" method="get" name="setslimstatfilters">
		<input type="hidden" name="page" value="wp-slimstat/view/index.php">
		<input type="hidden" name="slimpanel" value="<?php echo !empty($_GET['slimpanel'])?intval($_GET['slimpanel']):1; ?>">
		<?php if ($current_panel == 5) echo "<input type='hidden' name='ftu' value='$function_to_use'>"; ?>
		<?php // Keep other filters persistent
			foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
				echo "<input type='hidden' name='{$a_filter_label}' value='{$a_filter_details[0]}'>";
				echo "<input type='hidden' name='{$a_filter_label}-op' value='{$a_filter_details[1]}'>";
			}
		?>
		<p><span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Show records where','wp-slimstat-view') ?>
			<select name="filter" style="width:9em" onchange="if(this.value=='author'||this.value=='category-id'){document.setslimstatfilters.f_operator.value='equals';document.setslimstatfilters.f_operator.disabled=true;} else {document.setslimstatfilters.f_operator.disabled=false;}">
				<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
				<option value="version"><?php _e('Browser version','wp-slimstat-view') ?></option>
				<option value="css_version"><?php _e('CSS version','wp-slimstat-view') ?></option>
				<option value="country"><?php _e('Country Code','wp-slimstat-view') ?></option>
				<option value="domain"><?php _e('Domain','wp-slimstat-view') ?></option>
				<option value="ip"><?php _e('IP','wp-slimstat-view') ?></option>
				<option value="user"><?php _e('User','wp-slimstat-view') ?></option>
				<option value="searchterms"><?php _e('Keywords','wp-slimstat-view') ?></option>
				<option value="language"><?php _e('Language Code','wp-slimstat-view') ?></option>
				<option value="platform"><?php _e('Operating System','wp-slimstat-view') ?></option>
				<option value="resource"><?php _e('Permalink','wp-slimstat-view') ?></option>
				<option value="referer"><?php _e('Referer','wp-slimstat-view') ?></option>
				<option value="resolution"><?php _e('Screen Resolution','wp-slimstat-view') ?></option>
				<option value="author"><?php _e('Post Author','wp-slimstat-view') ?></option>
				<option value="category-id"><?php _e('Post Category ID','wp-slimstat-view') ?></option>
			</select> 
			<select name="f_operator" style="width:9em" onchange="if(this.value=='is empty'||this.value=='is not empty'){document.setslimstatfilters.f_value.disabled=true;} else {document.setslimstatfilters.f_value.disabled=false;}">
				<option value="equals"><?php _e('Equals','wp-slimstat-view') ?></option>
				<option value="contains"><?php _e('Contains','wp-slimstat-view') ?></option>
				<option value="does not contain"><?php _e('Does not contain','wp-slimstat-view') ?></option>
				<option value="starts with"><?php _e('Starts with','wp-slimstat-view') ?></option>
				<option value="ends with"><?php _e('Ends with','wp-slimstat-view') ?></option>
				<option value="is empty"><?php _e('Is empty','wp-slimstat-view') ?></option>
				<option value="is not empty"><?php _e('Is not empty','wp-slimstat-view') ?></option>
			</select>
			<input type="text" name="f_value" value="" size="12">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Filter by date','wp-slimstat-view') ?> <select name="day">
				<option value=""><?php _e('Day','wp-slimstat-view') ?></option>
				<option>01</option><option>02</option><option>03</option><option>04</option><option>05</option>
				<option>06</option><option>07</option><option>08</option><option>09</option><option>10</option>
				<option>11</option><option>12</option><option>13</option><option>14</option><option>15</option>
				<option>16</option><option>17</option><option>18</option><option>19</option><option>20</option>
				<option>21</option><option>22</option><option>23</option><option>24</option><option>25</option>
				<option>26</option><option>27</option><option>28</option><option>29</option><option>30</option>
				<option>31</option>
			</select> 
			<select name="month">
				<option value=""><?php _e('Month','wp-slimstat-view') ?></option>
				<option value="01"><?php _e('January') ?></option><option value="02"><?php _e('February') ?></option><option value="03"><?php _e('March') ?></option>
				<option value="04"><?php _e('April') ?></option><option value="05"><?php _e('May') ?></option><option value="06"><?php _e('June') ?></option>
				<option value="07"><?php _e('July') ?></option><option value="08"><?php _e('August') ?></option><option value="09"><?php _e('September') ?></option>
				<option value="10"><?php _e('October') ?></option><option value="11"><?php _e('November') ?></option><option value="12"><?php _e('December') ?></option>
			</select>
			<select name="year">
				<option value=""><?php _e('Year','wp-slimstat-view') ?></option>
				<?php
					$current_year = date_i18n('Y'); 
					for($i=$current_year;$i>$current_year-3;$i--)
						echo "<option>$i</option>";
				?>
			</select>
			+ <input type="text" name="interval" value="" size="3" title="<?php _e('days', 'wp-slimstat-view') ?>">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="<?php echo $wp_locale->text_direction ?>"><input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary">
			<?php if ($current_panel != 5): ?><a class="button-primary" href="index.php?page=wp-slimstat/view/index.php&slimpanel=<?php echo $current_panel ?>&direction=<?php echo $reverse.$filters_query; ?>"><?php _e('Reverse','wp-slimstat-view') ?></a><?php endif; ?></span>
		</p>
	</form>
	
	<p class="current-filters"><?php 
		$get_filter_to_use = !empty($_GET['ftu'])?"&ftu={$_GET['ftu']}":'';
		$get_orderby = !empty($_GET['orderby'])?"&ftu={$_GET['orderby']}":'';
		$get_direction = !empty($_GET['direction'])?"&ftu={$_GET['direction']}":'';
		
		if (!empty($filters_list)) echo substr($filters_list, 0, -2)." <a href='index.php?page=wp-slimstat/view/index.php&slimpanel=$current_panel$get_filter_to_use$get_orderby$get_direction'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/cancel.gif' alt='".__('reset','wp-slimstat-view')."'/></a>"; ?></p>
	
	<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php"); ?>
</div>