<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Data about our visits
$current_pageviews = $wp_slimstat_view->count_records();
$search_engines = $wp_slimstat_view->count_records("searchterms <> '' AND domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''", "DISTINCT id");
$count_pageviews_with_referer = $wp_slimstat_view->count_records("referer <> ''");
$datachart = $wp_slimstat_view->extract_data_for_chart('COUNT(DISTINCT(`domain`))', 'COUNT(DISTINCT(ip))', 3, __('Domains','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), "AND domain <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");

foreach($panels_order as $a_panel_id)
	switch($a_panel_id):
		case 'p3_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p3_01"><?php
$tooltip_content = '<strong>'.htmlspecialchars(__('Chart interaction','wp-slimstat-view'), ENT_QUOTES).'</strong><ul><li>'.htmlspecialchars(__('Use your mouse wheel to zoom in and out','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('While zooming in, drag the chart to move to a different area','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('Double click on an empty region to reset zoom level','wp-slimstat-view'), ENT_QUOTES).'</li>';
$tooltip_content .= (!$wp_slimstat_view->day_filter_active)?'<li>'.htmlspecialchars(__('Click on a day for hourly metrics','wp-slimstat-view'), ENT_QUOTES).'</li>':'';
$tooltip_content .= '</ul>';

echo "<img class='module-tooltip' src='$wp_slimstat_view->plugin_url/images/info.png' width='10' height='10' title='$tooltip_content' /><h3 class='hndle'>";
if (!$wp_slimstat_view->day_filter_active)
	_e('Daily Traffic Sources', 'wp-slimstat-view');
else
	_e('Hourly Traffic Sources', 'wp-slimstat-view');
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

<?php break; case 'p3_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p3_02"><?php
$unique_referers = $wp_slimstat_view->count_records("domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''", "DISTINCT domain");
$direct_visits = $wp_slimstat_view->count_records("domain = ''", "DISTINCT id");
$pages_referred = $wp_slimstat_view->count_records("domain <> ''", "DISTINCT resource");
$bouncing_pages = $wp_slimstat_view->count_bouncing_pages();
$referred_from_internal = $wp_slimstat_view->count_records("domain = '{$_SERVER['SERVER_NAME']}'", "DISTINCT resource"); ?>
	<h3 class='hndle'><?php _e('Summary', 'wp-slimstat-view') ?></h3><div class='container'>
		<p><span class="element-title"><?php _e('Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format($current_pageviews, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Unique Referers', 'wp-slimstat-view') ?></span> <span><?php echo number_format($unique_referers, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Direct Visits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($direct_visits, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Search Engines', 'wp-slimstat-view') ?></span> <span><?php echo number_format($search_engines, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Unique Pages Referred', 'wp-slimstat-view') ?></span> <span><?php echo number_format($pages_referred, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('This field identifies the number of single-page visits to your site over the selected period.','wp-slimstat-view') ?>' />
			<span class="element-title"><?php _e('Bounces', 'wp-slimstat-view') ?></span> <span><?php echo number_format($bouncing_pages, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p class="last"><span class="element-title"><?php _e('Unique Internal', 'wp-slimstat-view') ?></span> <span><?php echo number_format($referred_from_internal, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
	</div>
</div>

<?php break; case 'p3_03': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p3_03">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_top_searchterms<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Top Search Terms', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.searchterms', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['searchterms']);
	$extra_info = "title='".__('Last','wp-slimstat-view').': '.date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	$clean_long_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][1]) || $wp_slimstat_view->filters_parsed['searchterms'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;searchterms=$clean_long_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>".number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator)."</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p3_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p3_04"><h3 class="hndle"><?php _e('Top Countries', 'wp-slimstat-view') ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.country', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][1]) || $wp_slimstat_view->filters_parsed['country'][1]!='equals')
		$country = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p $extra_info><span class='element-title'>$country</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p3_05': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p3_05">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_top_traffic_sources<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Top Traffic Sources', 'wp-slimstat-view') ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
for($i=0;$i<$count_results;$i++){
	if (strpos($wp_slimstat_view->blog_domain, $results[$i]['domain'])) continue;
	$strings = trim_value($results[$i]['domain'], 64);
	$percentage = ($count_pageviews_with_referer > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_pageviews_with_referer)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain']);
	$element_url = (strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'];
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['domain']);
	if (!isset($wp_slimstat_view->filters_parsed['domain'][1]) || $wp_slimstat_view->filters_parsed['domain'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;domain=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a> {$strings['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p3_06': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p3_06">
	<h3 class="hndle"><?php _e('Top Search Engines', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', '', "searchterms <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['domain'], 36);
	$percentage = ($search_engines > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$search_engines)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$clean_domain = str_replace('www.','', $strings['text']);
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	if (!isset($wp_slimstat_view->filters_parsed['domain'][1]) || $wp_slimstat_view->filters_parsed['domain'][1]!='equals')
		$clean_domain = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;domain={$results[$i]['domain']}'>$clean_domain</a>";

	echo "<p $extra_info><span class='element-title'>$clean_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p3_07': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p3_07">
	<h3 class="hndle"><?php _e('Sites', 'wp-slimstat-view') ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer', "searchterms = '' AND domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['domain'], 36);
	$clean_domain = str_replace('www.','', $strings['text']);
	$strings = trim_value($results[$i]['referer'], 200);
	$percentage = ($count_pageviews_with_referer > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_pageviews_with_referer)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain'].$strings['text']);
	$element_url = (strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'];
	
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['domain'][1]) || $wp_slimstat_view->filters_parsed['domain'][1]!='equals')
		$clean_domain = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;domain={$results[$i]['domain']}'>$clean_domain</a>";

	echo "<p $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a>$clean_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p3_08': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p3_08">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_searchterms<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Search Terms &raquo; Pages', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.searchterms', 't1.ip, t1.user, t1.resource, t1.domain, t1.referer');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	// Trim searchterms
	if (strlen($results[$i]['searchterms']) > 35){
		$searchterms_to_show = substr($results[$i]['searchterms'], 0, 35).'...';
		$show_searchterms_tooltip = ' title="'.htmlspecialchars($results[$i]['searchterms'], ENT_QUOTES).'"';
	}
	else{
		$searchterms_to_show = $results[$i]['searchterms'];
		$show_searchterms_tooltip = '';
	}
	$searchterms_to_show = str_replace('\\', '', htmlspecialchars($searchterms_to_show, ENT_QUOTES));

	// Trim permalinks
	if (strlen($results[$i]['resource']) > 35){
		$resource_to_show = substr($results[$i]['resource'], 0, 35).'...';
		$show_resource_tooltip = ' title="'.htmlspecialchars($results[$i]['resource'], ENT_QUOTES).'"';
	}
	else{
		$resource_to_show = $results[$i]['resource'];
		$show_resource_tooltip = '';
	}
	$resource_to_show = str_replace('\\', '', htmlspecialchars($resource_to_show, ENT_QUOTES));

	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$highlight_row = !empty($results[$i]['user'])?'class="is-known-user"':'';
	$clean_long_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][1]) || $wp_slimstat_view->filters_parsed['searchterms'][1]!='equals')
		$searchterms_to_show = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;searchterms=$clean_long_string'>$searchterms_to_show</a>";
	$clean_long_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$resource_to_show = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=3$wp_slimstat_view->filters_query&amp;resource=$clean_long_string'>$resource_to_show</a>";
	$element_url = (strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'];
	echo "<p $highlight_row$extra_info><span class='element-title'$show_searchterms_tooltip><a target='_blank' title='".__('Open referer in a new window','wp-slimstat-view')."' href='$element_url'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a> ";
	echo $searchterms_to_show."</span> <span$show_resource_tooltip>$resource_to_show</span></p>";
} ?>
	</div>
</div><?php	break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;