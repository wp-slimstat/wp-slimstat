<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$dataXML = "<map map_file='".plugins_url('/swf/world3.swf', dirname(__FILE__))."' tl_long='-168.49' tl_lat='83.63' br_long='190.3' br_lat='-55.58' zoom_x='0%' zoom_y='0%' zoom='100%'>";
$dataXML .= @file_get_contents(WP_PLUGIN_DIR.'/wp-slimstat/admin/swf/map_data.xml');
$dataXML .= "</map>";
// Limit results doesn't apply here
wp_slimstat_db::$filters['parsed']['limit_results'][1] = 9999;
$countries = wp_slimstat_db::get_popular('t1.country');
$total_count = wp_slimstat_db::count_records('1=1', '*');

foreach($countries as $a_country){
	$percentage = sprintf("%01.2f", (100*$a_country['count']/$total_count));
	$percentage_format = ($total_count > 0)?number_format($percentage, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
	$a_country['count'] = number_format($a_country['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']);
	$dataXML = str_replace(": 0' mc_name='".strtoupper($a_country['country'])."'", ": $percentage_format% ({$a_country['count']})' mc_name='".strtoupper($a_country['country'])."' value='$percentage' url='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('country', $a_country['country'])."'", $dataXML);
}

?>

<div class="postbox tall" style="position:relative;padding-bottom:67.9%;height:0;overflow:hidden;">
	<h3 class="hndle"><?php _e('World Map - Click on active Countries to activate the corresponding filter', 'wp-slimstat-view'); ?></h3>
	<div class="container" style="height:605px" id="slimstat-world-map">
		<p class="nodata"><?php _e('No data to display','wp-slimstat-view') ?></p>
	</div>
</div>

<script type="text/javascript" src="<?php echo plugins_url('/swf/swfobject.js', dirname(__FILE__)); ?>"></script>
<script type="text/javascript">
// <![CDATA[
var flashvars = {
	settings_file: escape("<?php echo plugins_url('/swf/map_settings.xml', dirname(__FILE__)); ?>"),
	map_data: escape("<?php echo $dataXML ?>")
};
var params = {};
var attributes = {};
swfobject.embedSWF("<?php echo plugins_url('/swf/map.swf', dirname(__FILE__)); ?>", "slimstat-world-map", "700", "440", "8.0.0", "expressInstall.swf", flashvars, params, attributes);
// ]]>
</script>

