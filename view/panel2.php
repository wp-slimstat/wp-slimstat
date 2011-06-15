<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Data about our visits
$current_pageviews = $wp_slimstat_view->count_records();
$total_visitors = $wp_slimstat_view->count_records('visit_id > 0');

foreach($panels_order as $a_panel_id)
	switch($a_panel_id):
		case 'p2_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p2_01"><?php
echo '<h3 class="hndle">';
if (!$wp_slimstat_view->day_filter_active)
	_e('Human Visits by day - Click on a day for hourly metrics', 'wp-slimstat-view');
else
	_e('Human Visits by hour', 'wp-slimstat-view');
echo '</h3>';
$current = $wp_slimstat_view->extract_data_for_chart('COUNT(DISTINCT(visit_id))', 'COUNT(DISTINCT(ip))', 2, __('Visits','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 0, 'AND visit_id > 0');
if ($current->current_non_zero_count+$current->previous_non_zero_count == 0)
	echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
else{ ?>
	<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=https://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="780" height="180" id="line" >
		<param name="movie" value="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/fcf.swf" />
		<param name="FlashVars" value="&amp;dataXML=<?php echo $current->xml ?>&amp;chartWidth=780&amp;chartHeight=180">
		<param name="quality" value="high" />
		<embed src="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&amp;dataXML=<?php echo $current->xml ?>&amp;chartWidth=780&amp;chartHeight=180" quality="high" width="780" height="180" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
	</object><?php
} ?>
</div>

<?php break; case 'p2_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_02"><?php
title_period(__('Summary for', 'wp-slimstat-view'), $wp_slimstat_view);

$new_visitors = $wp_slimstat_view->count_new_visitors();
$bounce_rate = ($total_visitors > 0)?sprintf("%01.2f", (100*$new_visitors/$total_visitors)):0;
if (intval($bounce_rate) > 99) $bounce_rate = '100';
$metrics_per_visit = $wp_slimstat_view->get_max_and_average_pages_per_visit(); ?>
		<p><span class="element-title"><?php _e('Human visits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($total_visitors, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Unique IPs', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('1=1', 'DISTINCT ip'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('New visitors', 'wp-slimstat-view') ?></span> <span><?php echo number_format($new_visitors, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Bounce rate', 'wp-slimstat-view') ?></span> <span><?php echo number_format($bounce_rate, 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?>%</span></p>
		<p><span class="element-title"><?php _e('Bots', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('visit_id = 0'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Pages per visit', 'wp-slimstat-view') ?></span> <span><?php echo number_format($metrics_per_visit['avg'], 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p class="last"><span class="element-title"><?php _e('Longest visit', 'wp-slimstat-view') ?></span> <span><?php echo number_format($metrics_per_visit['max'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator).' '.__('hits','wp-slimstat-view') ?></span></p>
	</div>
</div>

<?php break; case 'p2_03': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_03">
	<h3 class="hndle"><?php _e('Languages', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.language');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value(__('l-'.$results[$i]['language'], 'countries-languages'), 35);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$extra_info = "title='".__('Code','wp-slimstat-view').": {$results[$i]['language']}, ".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['language']);
	if (!isset($wp_slimstat_view->filters_parsed['language'][1]) || $wp_slimstat_view->filters_parsed['language'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;language=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_04">
	<h3 class="hndle"><?php _e('User agents', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tb.browser, tb.version', '', "tb.browser <> ''", 'browsers');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$browser_version = ($results[$i]['version']!=0)?$results[$i]['version']:'';
	$browser_string = "{$results[$i]['browser']} $browser_version";
	$clean_string = urlencode($results[$i]['browser']);
	if (!isset($wp_slimstat_view->filters_parsed['browser'][1]) || $wp_slimstat_view->filters_parsed['browser'][1]!='equals')
		$browser_string = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;browser=$clean_string".(!empty($browser_version)?"&amp;version=$browser_version":'')."'>$browser_string</a>";
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	echo "<p$last_element $extra_info><span class='element-title'>$browser_string</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_05': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p2_05">
	<h3 class="hndle"><?php _e('IP Addresses and Domains', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.ip', 't1.user, t1.country');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

$convert_ip_addresses = get_option('slimstat_convert_ip_addresses');
for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$long2ip = long2ip($results[$i]['ip']);
	$host_by_ip = ($convert_ip_addresses == 'yes')?gethostbyaddr( $long2ip )." ($long2ip)":$long2ip;
	$host_by_ip = trim_value($host_by_ip, 64);
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	$extra_info .= (!empty($results['user']))?", User: {$results['user']}'":"'";
	if (!isset($wp_slimstat_view->filters_parsed['ip'][1]) || $wp_slimstat_view->filters_parsed['ip'][1]!='equals')
		$host_by_ip['text'] = "<a{$host_by_ip['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;ip=$long2ip'>{$host_by_ip['text']}</a>";
	$host_by_ip['text'] = "[{$results[$i]['country']}] <a href='$ip_lookup_url$long2ip' target='_blank' title='WHOIS: $long2ip'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/whois.gif' /></a> ".$host_by_ip['text'];

	echo "<p$last_element $extra_info><span class='element-title'>{$host_by_ip['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_06': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_06">
	<h3 class="hndle"><?php _e('Operating Systems', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tb.platform', 't1.ip, t1.user', '', 'browsers');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$platform = __($results[$i]['platform'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['platform'][1]) || $wp_slimstat_view->filters_parsed['platform'][1]!='equals')
		$platform = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;platform={$results[$i]['platform']}'>$platform</a>";

	echo "<p$last_element $extra_info><span class='element-title'>$platform</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_07': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_07">
	<h3 class="hndle"><?php _e('Screen Resolutions', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tss.resolution', 't1.ip, t1.user', '', 'screenres');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($total_visitors > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['resolution'][1]) || $wp_slimstat_view->filters_parsed['resolution'][1]!='equals')
		$results[$i]['resolution'] = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;resolution={$results[$i]['resolution']}'>{$results[$i]['resolution']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'>{$results[$i]['resolution']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_08': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_08">
	<h3 class="hndle"><?php _e('Screen Resolutions with colordepth', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tss.resolution, tss.colordepth, tss.antialias', 't1.ip, t1.user', "tss.resolution <> ''", 'screenres');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($total_visitors > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	echo "<p$last_element $extra_info><span class='element-title'>{$results[$i]['resolution']} ({$results[$i]['colordepth']}, {$results[$i]['antialias']})</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_09': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_09">
	<h3 class="hndle"><?php _e('Plugins', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips noscroll"><?php
$wp_slim_plugins = array('flash', 'silverlight', 'acrobat', 'java', 'mediaplayer', 'director', 'real');
foreach($wp_slim_plugins as $i => $a_plugin){
	$count_results = $wp_slimstat_view->count_records("plugins LIKE '%{$a_plugin}%' AND visit_id > 0");
	echo '<p'.(($i == 6)?' class="last"':'')." title='".__('Hits','wp-slimstat-view').": $count_results'><span class='element-title'>".ucfirst($a_plugin).'</span> <span>';
	echo ($total_visitors > 0)?number_format(sprintf("%01.2f", (100*$count_results/$total_visitors)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	echo '%</span></p>';
} ?>
	</div>
</div>

<?php break; case 'p2_10': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_10">
	<h3 class="hndle"><?php _e('Top Countries', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.country', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][1]) || $wp_slimstat_view->filters_parsed['country'][1]!='equals')
		$country = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=2$filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p$last_element $extra_info><span class='element-title'>$country</span> <span class='narrowcolumn'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_11': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p2_11">
	<h3 class="hndle"><?php _e('Browsers and Operating Systems', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tb.browser, tb.version, tb.platform', 't1.ip, t1.user', "t1.visit_id > 0", 'browsers');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($total_visitors > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$platform = __($results[$i]['platform'],'countries-languages');
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	echo "<p$last_element $extra_info><span class='element-title'>{$results[$i]['browser']} {$results[$i]['version']} / $platform</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div><?php break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;