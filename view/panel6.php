<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
$dataXML = "<map map_file='".plugins_url('/swf/world3.swf', __FILE__)."' tl_long='-168.49' tl_lat='83.63' br_long='190.3' br_lat='-55.58' zoom_x='0%' zoom_y='0%' zoom='100%'>";
$dataXML .= @file_get_contents(WP_PLUGIN_DIR.'/wp-slimstat/view/swf/map_data.xml');
$dataXML .= "</map>";

$countries = $wp_slimstat_view->get_top('t1.country', '');
$total_count = $wp_slimstat_view->count_records('1=1', '*');

foreach($countries as $a_country){
	$percentage = sprintf("%01.2f", (100*$a_country['count']/$total_count));
	$percentage_format = ($total_count > 0)?number_format($percentage, 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$a_country['count'] = number_format($a_country['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$dataXML = str_replace(": 0' mc_name='".strtoupper($a_country['country'])."'", ": $percentage_format% ({$a_country['count']})' mc_name='".strtoupper($a_country['country'])."' value='$percentage' url='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;country={$a_country['country']}'", $dataXML);
}

?>

<div class="postbox tall <?php echo $wp_locale->text_direction ?>" style="height:623px;overflow-y:hidden">
	<h3><?php _e('World Map - Click on a Country to activate the corresponding filter', 'wp-slimstat-view'); ?></h3>
	<div class="container" style="height:605px" id="slimstat-world-map">
		<p class="nodata"><?php _e('No data to display','wp-slimstat-view') ?></p>
	</div>
</div>

<script type="text/javascript" src="<?php echo plugins_url('/swf/swfobject.js', __FILE__); ?>"></script>
<script type="text/javascript">
// <![CDATA[
var flashvars = {
	settings_file: escape("<?php echo plugins_url('/swf/map_settings.xml', __FILE__); ?>"),
	map_data: escape("<?php echo $dataXML ?>")
};
var params = {};
var attributes = {};
swfobject.embedSWF("<?php echo plugins_url('/swf/map.swf', __FILE__); ?>", "slimstat-world-map", "900", "600", "8.0.0", "expressInstall.swf", flashvars, params, attributes);
// ]]>
</script>

