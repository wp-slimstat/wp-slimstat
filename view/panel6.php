<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

$dataXML = @file_get_contents(WP_PLUGIN_DIR.'/wp-slimstat/view/swf/world.xml');
$dataXML .= "<data>";

$countries = $wp_slimstat_view->get_top('t1.country', '');
$total_count = $wp_slimstat_view->count_records('1=1', '*');

foreach($countries as $a_country){
	$percentage = ($total_count > 0)?sprintf("%01.1f", (100*$a_country['count']/$total_count)):0;
	$dataXML .= "<entity id='{$a_country['country']}' value='{$percentage}'/>";
}

$dataXML .= "</data></map>";
echo $dataXML;
?>

<div class="postbox tall <?php echo $wp_locale->text_direction ?>" style="height:535px" >
	<h3><?php _e('World Map - Values represent the percentage of hits coming from that Country', 'wp-slimstat-view'); ?></h3>
	<div class="container" style="height:505px">
		<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=https://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=7,0,0,0" width="765" height="570" id="worldmap">
			<param name="movie" value="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/world.swf" />
			<param name="FlashVars" value="&amp;dataXML=<?php echo $dataXML ?>&amp;mapWidth=765&amp;mapHeight=570">
			<param name="quality" value="high" />
			<embed src="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/world.swf" flashVars="&amp;dataXML=<?php echo $dataXML ?>&amp;mapWidth=765&amp;mapHeight=570" quality="high" width="1000" height="500" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
		</object>
	</div>
</div>
<p class="fusion-credits">Powered by <a target="_blank" href="http://www.fusioncharts.com/maps">FusionMaps</a>.</p>