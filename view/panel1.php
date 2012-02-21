<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Data about our visits
$current_pageviews = $wp_slimstat_view->count_records();
$total_human_hits = $wp_slimstat_view->count_records('visit_id > 0');
$datachart = $wp_slimstat_view->extract_data_for_chart('COUNT(ip)', 'COUNT(DISTINCT(ip))', 1, __('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'));

foreach($panels_order as $a_panel_id)
	switch($a_panel_id):
		case 'p1_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p1_01"><?php
$tooltip_content = '<strong>'.htmlspecialchars(__('Chart interaction','wp-slimstat-view'), ENT_QUOTES).'</strong><ul><li>'.htmlspecialchars(__('Use your mouse wheel to zoom in and out','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('While zooming in, drag the chart to move to a different area','wp-slimstat-view'), ENT_QUOTES).'</li><li>'.htmlspecialchars(__('Double click on an empty region to reset zoom level','wp-slimstat-view'), ENT_QUOTES).'</li>';
$tooltip_content .= (!$wp_slimstat_view->day_filter_active)?'<li>'.htmlspecialchars(__('Click on a day for hourly metrics','wp-slimstat-view'), ENT_QUOTES).'</li>':'';
$tooltip_content .= '</ul>';

echo "<img class='module-tooltip' src='$wp_slimstat_view->plugin_url/images/info.png' width='10' height='10' title='$tooltip_content' /><h3 class='hndle'>";
if (!$wp_slimstat_view->day_filter_active)
	_e('Daily pageviews', 'wp-slimstat-view');
else
	_e('Hourly pageviews', 'wp-slimstat-view');
echo '</h3>';
if ($datachart->current_non_zero_count+$datachart->previous_non_zero_count == 0)
	echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
else{ ?>
	<div class="container noscroll"><div id="chart-placeholder"></div><div id="chart-legend"></div></div>
	<script id="source">function initChart(){
		window.chart_data = [[<?php echo $datachart->current_data1 ?>], [<?php echo $datachart->previous_data ?>], [<?php echo $datachart->current_data2 ?>]];
		window.ticks = [<?php echo $datachart->ticks ?>];
		var c = {label:"<?php echo $datachart->current_data1_label ?>",data:window.chart_data[0]}, b = {<?php echo !empty($datachart->previous_data_label)?"label:'$datachart->previous_data_label',data:window.chart_data[1]":''; ?>}, a = {label:"<?php echo $datachart->current_data2_label ?>",data:window.chart_data[2]};
		jQuery.plot(jQuery("#chart-placeholder"),[a,b,c],{zoom:{interactive:true},pan:{interactive:true},series:{lines:{show:true},points:{show:true},colors:[{opacity:0.85}]},xaxis:{tickSize:1,tickDecimals:0<?php echo "$datachart->min_max_ticks"; echo (!empty($wp_slimstat_view->day_interval) && $wp_slimstat_view->day_interval > 20)?',ticks:[]':',ticks:window.ticks' ?>,zoomRange:[5,window.ticks.length],panRange:[0,window.ticks.length]},yaxis:{tickDecimals:0,tickFormatter:tickFormatter,zoomRange:[5,<?php echo $datachart->max_yaxis+intval($datachart->max_yaxis/5) ?>],panRange:[0,<?php echo $datachart->max_yaxis+intval($datachart->max_yaxis/5) ?>]}, grid:{backgroundColor:"#ffffff",borderWidth:0,hoverable:true,clickable:true},legend:{container:"#chart-legend",noColumns:3}});
	}
	initChart();
	jQuery("#chart-placeholder").bind("dblclick",initChart);
	</script><?php 
} ?>
</div>

<?php break; case 'p1_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_02">
	<h3 class="hndle"><?php _e('About WP SlimStat', 'wp-slimstat-view'); ?></h3>
	<div class="container noscroll">
		<p><span class='element-title'><?php _e('Total Hits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('1=1', '*', false), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Data Size', 'wp-slimstat-view') ?></span> <span><?php echo $wp_slimstat_view->get_data_size() ?></span></p>
		<p><span class='element-title'><?php _e('Tracking Active', 'wp-slimstat-view') ?></span> <span><?php _e($wp_slimstat->options['is_tracking'], 'countries-languages') ?></span></p>
		<p><span class='element-title'><?php _e('Auto purge', 'wp-slimstat-view') ?></span> <span><?php echo ($wp_slimstat->options['auto_purge'] > 0)?$wp_slimstat->options['auto_purge'].' '.__('days','wp-slimstat-view'):__('No','wp-slimstat-view') ?></span></p>
		<p><span class='element-title'><?php _e('Latency', 'wp-slimstat-view') ?></span> <span><?php echo ($wp_slimstat->options['ignore_interval'] > 0)?$wp_slimstat->options['ignore_interval'].' '.__('seconds','wp-slimstat-view'):__('Off','wp-slimstat-view') ?></span></p>
		<p><span class='element-title'><?php _e('Oldest visit', 'wp-slimstat-view') ?></span> <span><?php echo date(get_option('date_format'), $wp_slimstat_view->get_oldest_visit()) ?></span></p>
		<p class="last"><span class='element-title'>Geo IP</span> <span><?php echo date(get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/geoip.csv')) ?></span></p>
	</div>
</div>

<?php break; case 'p1_03': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_03">
	<h3 class='hndle'><?php _e('Summary', 'wp-slimstat-view') ?></h3><div class='container'><?php
if (!$wp_slimstat_view->day_filter_active){
	$today_pageviews = !empty($datachart->current_data1[$wp_slimstat_view->current_date['d']])?$datachart->current_data1[$wp_slimstat_view->current_date['d']]:0;
	$yesterday_pageviews = (intval($wp_slimstat_view->current_date['d'])==1)?(!empty($datachart->previous_data1[$wp_slimstat_view->yesterday['d']])?$datachart->previous_data1[$wp_slimstat_view->yesterday['d']]:0):(!empty($datachart->current_data1[$wp_slimstat_view->yesterday['d']])?$datachart->current_data1[$wp_slimstat_view->yesterday['d']]:0);
} ?>
		<p><span class='element-title'><?php _e('Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format($current_pageviews, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('This number may differ from the sum of the points in the chart, because it counts each IP only once over the entire period.','wp-slimstat-view') ?>' />
			<span class='element-title'><?php _e('Unique IPs', 'wp-slimstat-view') ?></span><span><?php echo number_format($wp_slimstat_view->count_records('1=1', 'DISTINCT ip'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Avg Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format(($datachart->current_non_zero_count > 0)?intval($current_pageviews/$datachart->current_non_zero_count):0, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><img class='item-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('This number includes just those visitors identified as bots by the system. Browsers with Javascript disabled (but whose user agent is legit) are considered &quot;regular&quot; visitors.','wp-slimstat-view') ?>' />
			<span class='element-title'><?php _e('Bots', 'wp-slimstat-view') ?></span><span><?php echo number_format($wp_slimstat_view->count_records('tb.type = 1', '*', true, 'browsers'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p><?php
if (!$wp_slimstat_view->day_filter_active){ ?>
		<p><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$wp_slimstat_view->current_date['d'].'/'.$wp_slimstat_view->current_date['m'] ?></span> <span><?php echo number_format($datachart->today, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$wp_slimstat_view->yesterday['d'].'/'.$wp_slimstat_view->yesterday['m'] ?></span> <span><?php echo number_format($datachart->yesterday, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p class="last"><span class='element-title'><?php _e('Last Month', 'wp-slimstat-view'); ?></span> <span><?php echo number_format($datachart->previous_total, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p><?php
} ?>
	</div>
</div>

<?php break; case 'p1_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_04">
	<img class='module-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('When visitors comment on your blog, Wordpress assigns them cookies stored on their computer. WP SlimStat leverages this information to identify returning visitors.','wp-slimstat-view') ?>' />
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_known_visitors<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Known Visitors', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.user', 't1.ip');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['user']);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.long2ip($results[$i]['ip'])."'";
	if (strpos($results[$i]['user'], '[spam]') !== false) $extra_info .= ' class="is-spam"';
	$clean_string = urlencode($results[$i]['user']);
	if (!isset($wp_slimstat_view->filters_parsed['user'][1]) || $wp_slimstat_view->filters_parsed['user'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;user=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_05': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_05">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_details_recent_visits<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Spy View', 'wp-slimstat-view'); ?></h3>
	<div class="container"><?php
$results = $wp_slimstat_view->get_recent('t1.id', 't1.ip, t1.user, t1.resource, t1.searchterms, t1.visit_id, t1.domain, t1.referer, t1.country', 't1.visit_id > 0', '', '', 't1.visit_id DESC, t1.id ASC');
$count_results = count($results);
$visit_id = 0;
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	if ($wp_slimstat->options['convert_ip_addresses'] == 'yes'){
		$host_by_ip = gethostbyaddr( $results[$i]['ip'] );
		$host_by_ip = trim_value($host_by_ip, 40);
		$host_by_ip['text'] .= ($host_by_ip['text'] != $results[$i]['ip'])?" ({$results[$i]['ip']})":'';
	}
	else{
		$host_by_ip = array('text' => $results[$i]['ip'], 'tooltip' => '');
	}
	$results[$i]['dt'] = date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	if (empty($results[$i]['resource'])){
		$searchterms = trim_value($results[$i]['searchterms'], 32);
		$results[$i]['resource'] = __('Search for','wp-slimstat-view').': '.$searchterms['text'];
	}
	if ($visit_id != $results[$i]['visit_id']){
		$highlight_row = empty($results[$i]['searchterms'])?' is-direct':' is-search-engine';
		if (empty($results[$i]['user']))
			$host_by_ip['text'] = "<a{$host_by_ip['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$host_by_ip['text']}</a>";
		else{
			$host_by_ip['text'] = "<a{$host_by_ip['tooltip']} class='activate-filter highlight-user' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
			$highlight_row = ' is-known-user';
		}
		$host_by_ip['text'] = "<a href='$ip_lookup_url{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/images/whois.gif' /></a> {$host_by_ip['text']}";
		$country = __('c-'.$results[$i]['country'],'countries-languages');

		echo "<p class='header$highlight_row'>{$host_by_ip['text']} <span>$country</span> <span>{$results[$i]['dt']}</span></p>";
		$visit_id = $results[$i]['visit_id'];
	}
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
	echo "<p title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
	if (!empty($results[$i]['domain']))
		echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
	else
		echo __('Direct visit to','wp-slimstat-view');
	echo ' '.substr($results[$i]['resource'], 0, 40).'</p>';
} ?>
	</div>
</div>

<?php break; case 'p1_06': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_06">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_searchterms<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Search Terms', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.searchterms', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['searchterms']);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][1]) || $wp_slimstat_view->filters_parsed['searchterms'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;searchterms=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_07': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_07">
	<img class='module-tooltip' src='<?php echo $wp_slimstat_view->plugin_url ?>/images/info.png' width='10' height='10' title='<?php _e('Unique sessions initiated by your visitors. If a user is inactive on your site for 30 minutes or more, any future activity will be attributed to a new session. Users that leave your site and return within 30 minutes will be counted as part of the original session.','wp-slimstat-view') ?>' />
	<h3 class="hndle"><?php _e('Languages - Just Visitors', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.language', '', "t1.visit_id > 0 AND t1.language <> ''");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value(__('l-'.$results[$i]['language'], 'countries-languages'), 35);
	$percentage = ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_human_hits)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$extra_info = "title='".__('Code','wp-slimstat-view').": {$results[$i]['language']}, ".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['language']);
	if (!isset($wp_slimstat_view->filters_parsed['language'][1]) || $wp_slimstat_view->filters_parsed['language'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;language=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p1_08': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_08">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_top_resources<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Popular Resources', 'wp-slimstat-view') ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.resource', 't1.ip, t1.user', "t1.resource NOT LIKE '[%'", '');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 60);
	$extra_info = "title='".__('Last','wp-slimstat-view').': '.date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
	$element_url = $wp_slimstat_view->blog_domain.$results[$i]['resource'];
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a> <span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>".number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator)."</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p1_09': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_09">
	<div class="more"><a href="<?php echo $admin_url ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_countries<?php echo $wp_slimstat_view->filters_query ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Countries', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.country', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][1]) || $wp_slimstat_view->filters_parsed['country'][1]!='equals')
		$country = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p $extra_info>$country</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_10': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_10">
	<h3 class="hndle"><?php _e('Traffic Sources Overview', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer', '', '');
$count_results = count($results);
$count_pageviews_with_referer = $wp_slimstat_view->count_records("referer <> ''");
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
for($i=0;$i<$count_results;$i++){
	if (strpos($wp_slimstat_view->blog_domain, $results[$i]['domain'])) continue;
	$strings = trim_value($results[$i]['domain'], 64);
	$percentage = ($count_pageviews_with_referer > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_pageviews_with_referer)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain']);
	$element_url = 'http://'.$results[$i]['domain'].$results[$i]['referer'];
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['domain']);
	if (!isset($wp_slimstat_view->filters_parsed['domain'][1]) || $wp_slimstat_view->filters_parsed['domain'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1$wp_slimstat_view->filters_query&amp;domain=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/images/url.gif' /></a> {$strings['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div><?php break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;