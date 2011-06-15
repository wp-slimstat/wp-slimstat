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
		case 'p1_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p1_01"><?php
echo '<h3 class="hndle">';
if (!$wp_slimstat_view->day_filter_active)
	_e('Pageviews by day - Click on a day for hourly metrics', 'wp-slimstat-view');
else
	_e('Pageviews by hour', 'wp-slimstat-view');
echo '</h3>';
$current = $wp_slimstat_view->extract_data_for_chart('COUNT(ip)', 'COUNT(DISTINCT(ip))', 1, __('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 0, '');
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

<?php break; case 'p1_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_02">
	<h3 class="hndle"><?php _e('About WP SlimStat', 'wp-slimstat-view'); ?></h3>
	<div class="container noscroll">
		<p><span class='element-title'><?php _e('Total Hits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('1=1', '*', false), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Data Size', 'wp-slimstat-view') ?></span> <span><?php echo $wp_slimstat_view->get_data_size() ?></span></p>
		<p><span class='element-title'><?php _e('Tracking Active', 'wp-slimstat-view') ?></span> <span><?php _e(get_option('slimstat_is_tracking', 'no'), 'countries-languages') ?></span></p>
		<p><span class='element-title'><?php _e('Auto purge', 'wp-slimstat-view') ?></span> <span><?php echo (($auto_purge = get_option('slimstat_auto_purge', '0')) > 0)?$auto_purge.' '.__('days','wp-slimstat-view'):__('No','wp-slimstat-view') ?></span></p>
		<p><span class='element-title'><?php _e('Latency', 'wp-slimstat-view') ?></span> <span><?php echo (($ignore_interval = get_option('slimstat_ignore_interval', '0')) > 0)?$ignore_interval.' '.__('seconds','wp-slimstat-view'):_('Off','wp-slimstat-view') ?></span></p>
		<p><span class='element-title'><?php _e('Oldest visit', 'wp-slimstat-view') ?></span> <span><?php echo date(get_option('date_format'), $wp_slimstat_view->get_oldest_visit()) ?></span></p>
		<p class="last"><span class='element-title'>Geo IP</span> <span><?php echo date(get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/geoip.csv')) ?></span></p>
	</div>
</div>

<?php break; case 'p1_03': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_03"><?php
title_period(__('Summary for', 'wp-slimstat-view'), $wp_slimstat_view);

if (!$wp_slimstat_view->day_filter_active){
	$today_pageviews = !empty($current->current_data1[$wp_slimstat_view->current_date['d']])?$current->current_data1[$wp_slimstat_view->current_date['d']]:0;
	$yesterday_pageviews = (intval($wp_slimstat_view->current_date['d'])==1)?(!empty($current->previous_data1[$wp_slimstat_view->yesterday['d']])?$current->previous_data1[$wp_slimstat_view->yesterday['d']]:0):(!empty($current->current_data1[$wp_slimstat_view->yesterday['d']])?$current->current_data1[$wp_slimstat_view->yesterday['d']]:0);
} ?>
		<p><span class='element-title'><?php _e('Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format($current_pageviews, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Unique IPs', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('1=1', 'DISTINCT ip'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Bots', 'wp-slimstat-view') ?></span> <span><?php echo number_format($wp_slimstat_view->count_records('visit_id = 0'), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('Avg Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format(($current->current_non_zero_count > 0)?intval($current_pageviews/$current->current_non_zero_count):0, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p><?php
if (!$wp_slimstat_view->day_filter_active && empty($wp_slimstat_view->day_interval)){ ?>
		<p><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$wp_slimstat_view->current_date['d'].'/'.$wp_slimstat_view->current_date['m'] ?></span> <span><?php echo number_format(intval($today_pageviews), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$wp_slimstat_view->yesterday['d'].'/'.$wp_slimstat_view->yesterday['m'] ?></span> <span><?php echo number_format(intval($yesterday_pageviews), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p class="last"><span class='element-title'><?php _e('Last Month', 'wp-slimstat-view'); ?></span> <span><?php echo number_format(intval(array_sum($current->previous_data1)), 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p><?php
} ?>
	</div>
</div>

<?php break; case 'p1_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_04">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_details_recent_visits&amp;user-op=is%20not%20empty<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Users', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.user', 't1.ip');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['user']);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.long2ip($results[$i]['ip'])."'";
	$clean_string = urlencode($results[$i]['user']);
	if (!isset($wp_slimstat_view->filters_parsed['user'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;user=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_05': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_05">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_details_recent_visits<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Spy View', 'wp-slimstat-view'); ?></h3>
	<div class="container"><?php
$results = $wp_slimstat_view->get_recent('t1.id', 't1.ip, t1.user, t1.resource, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser', 't1.visit_id > 0', 'browsers', '', 't1.visit_id DESC, t1.id ASC');
$count_results = count($results);
$visit_id = 0;
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	$results[$i]['dt'] = date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	if (empty($results[$i]['resource'])){
		$searchterms = trim_value($results[$i]['searchterms'], 32);
		$results[$i]['resource'] = __('Search for','wp-slimstat-view').': '.$searchterms['text'];
	}
	if ($visit_id != $results[$i]['visit_id']){
		if (empty($results[$i]['user']))
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$results[$i]['ip']}</a>";
		else
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
		$ip_address = "<a href='$ip_lookup_url{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/whois.gif' /></a> $ip_address";
		$country = __('c-'.$results[$i]['country'],'countries-languages');

		echo "<p class='header'>$ip_address <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>{$results[$i]['dt']}</span></p>";
		$visit_id = $results[$i]['visit_id'];
	}
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
	echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
	if (!empty($results[$i]['domain']))
		echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
	else
		echo __('Direct visit to','wp-slimstat-view');
	echo ' '.substr($results[$i]['resource'], 0, 40).'</p>';
} ?>
	</div>
</div>

<?php break; case 'p1_06': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_06">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_searchterms&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Keywords', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.searchterms', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['searchterms']);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;searchterms=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_07': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_07">
	<h3 class="hndle"><?php _e('Languages - Just Visitors', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.language', '', "t1.visit_id > 0 AND t1.language <> ''");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value(__('l-'.$results[$i]['language'], 'countries-languages'), 35);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($total_visitors > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$extra_info = "title='".__('Code','wp-slimstat-view').": {$results[$i]['language']}, ".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['language']);
	if (!isset($wp_slimstat_view->filters_parsed['language'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;language=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p1_08': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_08">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_top_resources&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div><?php
title_period(__('Popular pages for', 'wp-slimstat-view'), $wp_slimstat_view, ' slimstat-tooltips');

$results = $wp_slimstat_view->get_top('t1.resource', 't1.ip, t1.user', '', '');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 60);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$extra_info = "title='".__('Last','wp-slimstat-view').': '.date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
	$element_url = $wp_slimstat_view->blog_domain.$results[$i]['resource'];
	if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> <span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>".number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator)."</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p1_09': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p1_09">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_countries&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Countries', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.country', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][0])) $country = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p$last_element $extra_info>$country</p>";
} ?>
	</div>
</div>

<?php break; case 'p1_10': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p1_10">
	<h3 class="hndle"><span><?php _e('Traffic Sources Overview', 'wp-slimstat-view'); ?></span></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer', '', '');
$count_results = count($results);
$count_pageviews_with_referer = $wp_slimstat_view->count_records("referer <> ''");
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
for($i=0;$i<$count_results;$i++){
	if (strpos($wp_slimstat_view->blog_domain, $results[$i]['domain'])) continue;
	$strings = trim_value($results[$i]['domain'], 64);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($count_pageviews_with_referer > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_pageviews_with_referer)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain']);
	$element_url = 'http://'.$results[$i]['domain'].$results[$i]['referer'];
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['domain']);
	if (!isset($wp_slimstat_view->filters_parsed['domain'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=1$filters_query&amp;domain=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$strings['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div><?php break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;