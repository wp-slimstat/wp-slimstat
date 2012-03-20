<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Data about our visits
$current_pageviews = $wp_slimstat_view->count_records();
$total_human_hits = $wp_slimstat_view->count_records('visit_id > 0');
$total_human_visits = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id');
$datachart = $wp_slimstat_view->extract_data_for_chart('COUNT(DISTINCT visit_id)', 'COUNT(DISTINCT ip)', 2, __('Visits','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 'AND (tb.type = 0 OR tb.type = 2)', '', 'browsers');

foreach($panels_order as $a_panel_id)
	switch($a_panel_id):
		case 'p2_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p2_01"><?php
$tooltip_content = '<strong>'.htmlspecialchars(__('Chart interaction','wp-slimstat-view'), ENT_QUOTES).'</strong><ul><li>'.htmlspecialchars(__('Use your mouse wheel to zoom in and out','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('While zooming in, drag the chart to move to a different area','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('Double click on an empty region to reset zoom level','wp-slimstat-view'), ENT_QUOTES).'</li>';
$tooltip_content .= (!$wp_slimstat_view->day_filter_active)?'<li>'.htmlspecialchars(__('Click on a day for hourly metrics','wp-slimstat-view'), ENT_QUOTES).'</li>':'';
$tooltip_content .= '</ul>';

echo "<img class='module-tooltip' src='$wp_slimstat_view->plugin_url/images/info.png' width='10' height='10' title='$tooltip_content' /><h3 class='hndle'>";
if (!$wp_slimstat_view->day_filter_active)
	_e('Daily Human Visits', 'wp-slimstat-view');
else
	_e('Hourly Human Visits', 'wp-slimstat-view');
echo '</h3>';
if ($datachart->current_non_zero_count+$datachart->previous_non_zero_count == 0)
	echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
else{ ?>
	<div class="container noscroll"><div id="chart-placeholder"></div><div id="chart-legend"></div></div>
	<script id="source">function initChart(){
		window.chart_data = [[<?php echo $datachart->current_data1 ?>], [<?php echo $datachart->previous_data ?>], [<?php echo $datachart->current_data2 ?>]];
		window.ticks = [<?php echo $datachart->ticks ?>];
		var c = {label:"<?php echo $datachart->current_data1_label ?>",data:window.chart_data[0]}, b = {label:"<?php echo $datachart->previous_data_label ?>",data:window.chart_data[1]}, a = {label:"<?php echo $datachart->current_data2_label ?>",data:window.chart_data[2]};
		jQuery.plot(jQuery("#chart-placeholder"),[a,b,c],{zoom:{interactive:true},pan:{interactive:true},series:{lines:{show:true},points:{show:true},colors:[{opacity:0.85}]},xaxis:{tickSize:1,tickDecimals:0<?php echo "$datachart->min_max_ticks"; echo (!empty($wp_slimstat_view->day_interval) && $wp_slimstat_view->day_interval > 20)?',ticks:[]':',ticks:window.ticks' ?>,zoomRange:[5,window.ticks.length],panRange:[0,window.ticks.length]},yaxis:{tickDecimals:0,tickFormatter:tickFormatter,zoomRange:[5,<?php echo $datachart->max_yaxis+intval($datachart->max_yaxis/5) ?>],panRange:[0,<?php echo $datachart->max_yaxis+intval($datachart->max_yaxis/5) ?>]}, grid:{backgroundColor:"#ffffff",borderWidth:0,hoverable:true,clickable:true},legend:{container:"#chart-legend",noColumns:3}});
	}
	initChart();
	jQuery("#chart-placeholder").bind("dblclick",initChart);
	</script><?php
} ?>
</div>

<?php break; case 'p2_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_02">
	<h3 class='hndle'><?php _e('Summary', 'wp-slimstat-view') ?></h3><div class='container'><?php
$new_visitors = $wp_slimstat_view->count_records_having('visit_id > 0', 'ip', 'COUNT(visit_id) = 1');
$bounce_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
if (intval($bounce_rate) > 99) $bounce_rate = '100';
$metrics_per_visit = $wp_slimstat_view->get_max_and_average_pages_per_visit(); ?>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('A visit is a session of at most 30 minutes. Returning visitors are counted multiple times if they perform multiple visits.','wp-slimstat-view') ?>' />
			<span class="element-title"><?php _e('Human visits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($total_human_visits, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Bots', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('tb.type = 1', '*', true, 'browsers'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('This number includes <strong>human visits</strong> only.','wp-slimstat-view') ?>' />
			<span class="element-title"><?php _e('Unique IPs', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('visit_id > 0', 'DISTINCT ip'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('New visitors', 'wp-slimstat-view') ?></span> <span><?php echo number_format($new_visitors, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('The percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat-view') ?>' />
			<span class="element-title"><?php _e('Bounce rate', 'wp-slimstat-view') ?></span> <span><?php echo number_format($bounce_rate, 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?>%</span></p>
		
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
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Code','wp-slimstat-view').": {$results[$i]['language']}, ".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['language']);
	if (!isset($wp_slimstat_view->filters_parsed['language'][1]) || $wp_slimstat_view->filters_parsed['language'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;language=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_04">
	<img class='module-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('WP SlimStat can be configured to ignore given user agents under Settings > SlimStat > Filters.<br/><br/><strong>Legend</strong><ul><li>0: Regular user</li><li>1: Bot</li><li>2: Mobile</li><li>3: Syndication reader</li></ul>','wp-slimstat-view') ?>' />
	<h3 class="hndle"><?php _e('User Agents', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('tb.browser, tb.version, tb.type', '', "tb.browser <> ''", 'browsers');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$browser_version = ($results[$i]['version']!=0)?$results[$i]['version']:'';
	$browser_string = "{$results[$i]['browser']} $browser_version";
	$clean_string = urlencode($results[$i]['browser']);
	if (!isset($wp_slimstat_view->filters_parsed['browser'][1]) || $wp_slimstat_view->filters_parsed['browser'][1]!='equals')
		$browser_string = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;browser=$clean_string".(!empty($browser_version)?"&amp;version=$browser_version":'')."'>$browser_string</a>";
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	echo "<p $extra_info><span class='element-title'>[<strong>{$results[$i]['type']}</strong>] $browser_string</span> <span>$percentage%</span></p>";
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

for($i=0;$i<$count_results;$i++){
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	if ($GLOBALS['wp_slimstat']->options['convert_ip_addresses'] == 'yes'){
		$host_by_ip = gethostbyaddr( $results[$i]['ip'] );
		$host_by_ip = trim_value($host_by_ip, 60);
		$host_by_ip['text'] .= ($host_by_ip['text'] != $results[$i]['ip'])?" ({$results[$i]['ip']})":'';
	}
	else{
		$host_by_ip = array('text' => $results[$i]['ip'], 'tooltip' => '');
	}
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	$extra_info .= (!empty($results['user']))?", User: {$results['user']}'":"'";
	if (!isset($wp_slimstat_view->filters_parsed['ip'][1]) || $wp_slimstat_view->filters_parsed['ip'][1]!='equals')
		$host_by_ip['text'] = "<a{$host_by_ip['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;ip={$results[$i]['ip']}'>{$host_by_ip['text']}</a>";
	$host_by_ip['text'] = "[{$results[$i]['country']}] <a href='$ip_lookup_url{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/images/whois.gif' /></a> ".$host_by_ip['text'];

	echo "<p $extra_info><span class='element-title'>{$host_by_ip['text']}</span> <span>$percentage%</span></p>";
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
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$platform = __($results[$i]['platform'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['platform'][1]) || $wp_slimstat_view->filters_parsed['platform'][1]!='equals')
		$platform = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;platform={$results[$i]['platform']}'>$platform</a>";

	echo "<p $extra_info><span class='element-title'>$platform</span> <span>$percentage%</span></p>";
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
	$percentage = ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_human_hits)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['resolution'][1]) || $wp_slimstat_view->filters_parsed['resolution'][1]!='equals')
		$results[$i]['resolution'] = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;resolution={$results[$i]['resolution']}'>{$results[$i]['resolution']}</a>";

	echo "<p $extra_info><span class='element-title'>{$results[$i]['resolution']}</span> <span>$percentage%</span></p>";
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
	$percentage = ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_human_hits)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	echo "<p $extra_info><span class='element-title'>{$results[$i]['resolution']} ({$results[$i]['colordepth']}, {$results[$i]['antialias']})</span> <span>$percentage%</span></p>";
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
	echo ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$count_results/$total_human_hits)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
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
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][1]) || $wp_slimstat_view->filters_parsed['country'][1]!='equals')
		$country = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=2$wp_slimstat_view->filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p $extra_info><span class='element-title'>$country</span> <span class='narrowcolumn'>$percentage%</span></p>";
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
	$percentage = ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_human_hits)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$platform = __($results[$i]['platform'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	echo "<p $extra_info><span class='element-title'>{$results[$i]['browser']} {$results[$i]['version']} / $platform</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p2_12': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p2_12">
	<h3 class="hndle"><?php _e('Visit Duration', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) >= 0 AND max(dt) - min(dt) <= 30');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('0 - 30 seconds','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 30 AND max(dt) - min(dt) <= 60');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('31 - 60 seconds','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 60 AND max(dt) - min(dt) <= 180');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('1 - 3 minutes','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 180 AND max(dt) - min(dt) <= 300');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('3 - 5 minutes','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 300 AND max(dt) - min(dt) <= 600');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('5 - 10 minutes','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 600 AND max(dt) - min(dt) <= 1200');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p $extra_info><span class='element-title'>".__('10 - 20 minutes','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

$count = $wp_slimstat_view->count_records_having('visit_id > 0', 'visit_id', 'max(dt) - min(dt) > 1200');
$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$count}'";
echo "<p class='last' $extra_info><span class='element-title'>".__('More than 20 minutes','wp-slimstat-view')."</span> <span>$percentage%</span></p>";

?>
	</div>
</div><?php break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;