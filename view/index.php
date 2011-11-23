<?php 
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

global $wpdb, $wp_locale, $month;

// IP Lookup service URL
$ip_lookup_url = get_option('slimstat_ip_lookup_service', 'http://www.maxmind.com/app/lookup_city?ips=');

// Retrieve the order of this tab's panels
$user = wp_get_current_user();
$admin_url = admin_url('index.php');
$option = (get_option('slimstat_use_separate_menu', 'no') == 'yes')?'meta-box-order_toplevel_page_wp-slimstat':'meta-box-order_dashboard_page_wp-slimstat';
$panels_order = get_user_option($option, $user->ID);
$panels_order = explode(',', $panels_order[0]);
if(!$panels_order || count($panels_order)!=39) $panels_order = array('p1_01','p1_02','p1_03','p1_04','p1_05','p1_06','p1_07','p1_08','p1_09','p1_10','p2_01','p2_02','p2_03','p2_04','p2_05','p2_06','p2_07','p2_08','p2_09','p2_10','p2_11','p3_01','p3_02','p3_03','p3_04','p3_05','p3_06','p3_07','p3_08','p4_01','p4_02','p4_03','p4_04','p4_05','p4_06','p4_07','p4_08','p4_09','p4_10');

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

$get_filter_to_use = !empty($_GET['ftu'])?"&ftu={$_GET['ftu']}":'';
$get_orderby = !empty($_GET['orderby'])?"&ftu={$_GET['orderby']}":'';
$get_direction = !empty($_GET['direction'])?"&ftu={$_GET['direction']}":'';
$filters_list = '';

if (!empty($wp_slimstat_view->filters_parsed)){
	$filters_list = __('Current filters:','wp-slimstat-view').' ';
	foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
		$a_filter_value_no_slashes = str_replace('\\','', $a_filter_details[0]);
		$filters_list .= "<code>".htmlspecialchars("$a_filter_label {$a_filter_details[1]} $a_filter_value_no_slashes")."</code> [[$a_filter_label]], ";
	}
	foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
		$a_filter_value_no_slashes = str_replace('\\','', $a_filter_details[0]);
		$url_filter_removed = str_replace("&$a_filter_label=$a_filter_value_no_slashes&$a_filter_label-op={$a_filter_details[1]}", '', $wp_slimstat_view->filters_query);
		$filters_list = str_replace("[[$a_filter_label]]", 
				" <a href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&slimpanel=$current_panel$get_filter_to_use$get_orderby$get_direction$url_filter_removed'><img src='$wp_slimstat_view->plugin_url/images/cancel.gif' alt='".__('x','wp-slimstat-view')."'/></a>",
				$filters_list);
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
function title_period($_title_string, $_wp_slimstat_view, $_extra_class = ' noscroll'){
	
	echo "<h3 class='hndle'>$_title_string ";
	if (empty($_wp_slimstat_view->day_interval)){
		if ($_wp_slimstat_view->day_filter_active) echo $_wp_slimstat_view->current_date['d'].'/';
		echo $_wp_slimstat_view->current_date['m'].'/'.$_wp_slimstat_view->current_date['y']; 
	}
	else
		_e('this period', 'wp-slimstat-view');
	echo "</h3><div class='container$_extra_class'>";
}
function trim_value($_string = '', $_length = 40){
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
<?php $refresh_interval = get_option('slimstat_refresh_interval', '0'); if (($refresh_interval > 0) && ($current_panel == 5)) echo "window.setTimeout('location.reload()', $refresh_interval*1000);"; 
if ($current_panel < 5): ?>
function tickFormatter(n){
	n += '';
	x = n.split('.');
	x1 = x[0];
	x2 = x.length > 1 ? '<?php echo $wp_slimstat_view->decimal_separator ?>' + x[1] : '';
	var rgx = /(\d+)(\d{3})/;
	while (rgx.test(x1)) {
		x1 = x1.replace(rgx, '$1' + '<?php echo $wp_slimstat_view->thousand_separator ?>' + '$2');
	}
	return x1 + x2;
}
function showTooltip(x, y, content, class_label){
	var class_attribute = class_label ? ' class="'+class_label+'"':'';
	jQuery('<div id="jquery-tooltip"' + class_attribute + '>' + content + '</div>').css({
		top:y-15,
		left:x+10,
	}).appendTo("body").fadeIn(200);
}
var previousPoint = null;

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
		}
	);
	jQuery("#chart-placeholder").bind("plothover", function(event, pos, item){
		jQuery("#x").text(pos.x.toFixed(2));
		jQuery("#y").text(pos.y.toFixed(2));
		if (item){
			if (previousPoint != item.dataIndex){
				previousPoint = item.dataIndex;
				showTooltip(item.pageX, item.pageY, item.series.label+': <b>'+window.ticks[item.datapoint[0]][1]+'</b> = '+tickFormatter(item.datapoint[1]));
			}
		}
		else{
			jQuery("#jquery-tooltip").remove();
			previousPoint = null;            
		}
	});
	jQuery("#chart-placeholder").bind("plotclick", function(event, pos, item){
        if (item && typeof(window.chart_data[item.seriesIndex][item.datapoint[0]][2]) != 'undefined'){
			document.location.href = '<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat'+window.chart_data[item.seriesIndex][item.datapoint[0]][2];
        }
    });
	jQuery(".module-tooltip").hover(
			function(event){
				this.savetitle = this.title;
				showTooltip(event.pageX-240, event.pageY+10, this.title, 'tooltip-fixed-width');
				this.title = '';
			},
			function(){
				this.title = this.savetitle;
				jQuery("#jquery-tooltip").remove();
			}
	);
	jQuery(".item-tooltip").hover(
			function(event){
				this.savetitle = this.title;
				showTooltip(event.pageX+10, event.pageY+10, this.title, 'tooltip-fixed-width');
				this.title = '';
			},
			function(){
				this.title = this.savetitle;
				jQuery("#jquery-tooltip").remove();
			}
	);
});
<?php endif ?>
</script>

<div class="wrap">
	<div id="analytics-icon" class="icon32 <?php echo $wp_locale->text_direction ?>"></div>
	<h2 class="medium">
		<?php		
		foreach($array_panels as $a_panel_id => $a_panel_name){
			echo '<a class="nav-tab nav-tab';
			echo ($current_panel == $a_panel_id+1)?'-active':'-inactive';
			echo '" href="'.$_SERVER['PHP_SELF'].'?page=wp-slimstat&slimpanel='.($a_panel_id+1).$wp_slimstat_view->filters_query.'&direction='.$wp_slimstat_view->direction.'">'.$a_panel_name.'</a>';
		}
		?>
	</h2>
	
	<form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="get" name="setslimstatfilters"
		onsubmit="if (this.year.value == '<?php _e('Year','wp-slimstat-view') ?>') this.year.value = ''">
		<input type="hidden" name="page" value="wp-slimstat">
		<input type="hidden" name="slimpanel" value="<?php echo !empty($_GET['slimpanel'])?intval($_GET['slimpanel']):1; ?>">
		<?php if ($current_panel == 5) echo "<input type='hidden' name='ftu' value='$function_to_use'>"; ?>
		<?php // Keep other filters persistent
			foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
				echo "<input type='hidden' name='$a_filter_label' value='{$a_filter_details[0]}'>";
				echo "<input type='hidden' name='$a_filter_label-op' value='{$a_filter_details[1]}'>";
			}
		?>
		<p><span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Show records where','wp-slimstat-view') ?>
			<select name="filter" style="width:9em" onchange="if(this.value=='author'||this.value=='category-id'){document.setslimstatfilters.f_operator.value='equals';document.setslimstatfilters.f_operator.disabled=true;} else {document.setslimstatfilters.f_operator.disabled=false;}">
				<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
				<option value="version"><?php _e('Browser version','wp-slimstat-view') ?></option>
				<option value="css_version"><?php _e('CSS version','wp-slimstat-view') ?></option>
				<option value="type"><?php _e('Browser type','wp-slimstat-view') ?></option>
				<option value="platform"><?php _e('Operating System','wp-slimstat-view') ?></option>
				<option value="country"><?php _e('Country Code','wp-slimstat-view') ?></option>
				<option value="domain"><?php _e('Domain','wp-slimstat-view') ?></option>
				<option value="ip"><?php _e('IP','wp-slimstat-view') ?></option>
				<option value="user"><?php _e('User','wp-slimstat-view') ?></option>
				<option value="visit_id"><?php _e('Visit ID','wp-slimstat-view') ?></option>
				<option value="searchterms"><?php _e('Keywords','wp-slimstat-view') ?></option>
				<option value="language"><?php _e('Language Code','wp-slimstat-view') ?></option>
				<option value="resource"><?php _e('Permalink','wp-slimstat-view') ?></option>
				<option value="referer"><?php _e('Referer','wp-slimstat-view') ?></option>
				<option value="resolution"><?php _e('Screen Resolution','wp-slimstat-view') ?></option>
				<option value="colordepth"><?php _e('Color depth','wp-slimstat-view') ?></option>
				<option value="author"><?php _e('Post Author','wp-slimstat-view') ?></option>
				<option value="category-id"><?php _e('Post Category ID','wp-slimstat-view') ?></option>
			</select> 
			<select name="f_operator" style="width:9em" onchange="if(this.value=='is empty'||this.value=='is not empty'){document.setslimstatfilters.f_value.disabled=true;} else {document.setslimstatfilters.f_value.disabled=false;}">
				<option value="equals"><?php _e('Equals','wp-slimstat-view') ?></option>
				<option value="contains"><?php _e('Contains','wp-slimstat-view') ?></option>
				<option value="does not contain"><?php _e('Does not contain','wp-slimstat-view') ?></option>
				<option value="starts with"><?php _e('Starts with','wp-slimstat-view') ?></option>
				<option value="ends with"><?php _e('Ends with','wp-slimstat-view') ?></option>
				<option value="sounds like"><?php _e('Sounds like','wp-slimstat-view') ?></option>
				<option value="is empty"><?php _e('Is empty','wp-slimstat-view') ?></option>
				<option value="is not empty"><?php _e('Is not empty','wp-slimstat-view') ?></option>
			</select>
			<input type="text" name="f_value" value="" size="12">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Filter by date','wp-slimstat-view') ?> <select name="day">
				<option value=""><?php _e('Day','wp-slimstat-view') ?></option><?php
				for($i=1;$i<=31;$i++){
					if(!empty($wp_slimstat_view->filters_parsed['day'][0]) && $wp_slimstat_view->filters_parsed['day'][0] == $i)
						echo "<option selected='selected'>$i</option>";
					else
						echo "<option>$i</option>";
				} 
				?></select> 
			<select name="month">
				<option value=""><?php _e('Month','wp-slimstat-view') ?></option><?php
				for($i=1;$i<=12;$i++){
					if(!empty($wp_slimstat_view->filters_parsed['month'][0]) && $wp_slimstat_view->filters_parsed['month'][0] == $i)
						echo "<option value='$i' selected='selected'>{$month[zeroise($i, 2)]}</option>";
					else
						echo "<option value='$i'>{$month[zeroise($i, 2)]}</option>";
				} 
				?>
			</select>
			<input type="text" name="year" size="4" onfocus="if(this.value == '<?php _e('Year','wp-slimstat-view') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('Year','wp-slimstat-view') ?>';"
				value="<?php echo !empty($wp_slimstat_view->filters_parsed['year'][0])?$wp_slimstat_view->filters_parsed['year'][0]:__('Year','wp-slimstat-view') ?>">
			+ <input type="text" name="interval" value="" size="3" title="<?php _e('days', 'wp-slimstat-view') ?>">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="<?php echo $wp_locale->text_direction ?>"><input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary">
			<?php if ($current_panel != 5): ?><a class="button-primary" href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&slimpanel=<?php echo $current_panel ?>&direction=<?php echo $reverse.$wp_slimstat_view->filters_query; ?>"><?php _e('Reverse','wp-slimstat-view') ?></a><?php endif; ?></span>
		</p>
	</form>
<?php if (!empty($filters_list)): ?>
	<p class="current-filters"><?php if(count($wp_slimstat_view->filters_parsed) > 1): ?><a href='<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&slimpanel=<?php echo $current_panel ?>'><img src='<?php echo $wp_slimstat_view->plugin_url ?>/images/cancel.gif'/></a> <?php endif; echo substr($filters_list, 0, -2) ?></p>
<?php endif; $meta_box_order_nonce = wp_create_nonce('meta-box-order'); ?>
<div class="meta-box-sortables">
<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo $meta_box_order_nonce ?>" /></form>
<?php if (is_readable(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php")) require_once(WP_PLUGIN_DIR."/wp-slimstat/view/panel$current_panel.php"); ?>

</div>
</div>