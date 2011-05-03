<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>"><?php 
echo '<h3>';
if (!$wp_slimstat_view->day_filter_active)
	_e('Traffic Sources by day - Click on a day for hourly metrics', 'wp-slimstat-view');
else
	_e('Traffic Sources by hour', 'wp-slimstat-view');
echo '</h3>';
$current = $wp_slimstat_view->extract_data_for_chart('COUNT(DISTINCT(`domain`))', 'COUNT(DISTINCT(ip))', 3, __('Domains','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 0, "AND domain <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");
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

<div class="postbox <?php echo $wp_locale->text_direction ?>"><?php
$current_pageviews = $wp_slimstat_view->count_records();
$unique_referers = $wp_slimstat_view->count_records("domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''", "DISTINCT domain");
$direct_visits = $wp_slimstat_view->count_records("domain = ''", "DISTINCT id");
$search_engines = $wp_slimstat_view->count_records("searchterms <> '' AND domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''", "DISTINCT id");
$pages_referred = $wp_slimstat_view->count_records("domain <> ''", "DISTINCT resource");
$bouncing_pages = $wp_slimstat_view->count_bouncing_pages();
$referred_from_internal = $wp_slimstat_view->count_records("domain = '{$_SERVER['SERVER_NAME']}'", "DISTINCT resource");

title_period(__('Summary for', 'wp-slimstat-view')); ?>

		<p><span class="element-title"><?php _e('Total Hits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($current_pageviews, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Unique Referers', 'wp-slimstat-view') ?></span> <span><?php echo number_format($unique_referers, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Direct Visits', 'wp-slimstat-view') ?></span> <span><?php echo number_format($direct_visits, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Search Engines', 'wp-slimstat-view') ?></span> <span><?php echo number_format($search_engines, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Unique Pages Referred', 'wp-slimstat-view') ?></span> <span><?php echo number_format($pages_referred, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p><span class="element-title"><?php _e('Bounces', 'wp-slimstat-view') ?></span> <span><?php echo number_format($bouncing_pages, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
		<p class="last"><span class="element-title"><?php _e('Unique Internal', 'wp-slimstat-view') ?></span> <span><?php echo number_format($referred_from_internal, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?></span></p>
	</div>
</div>

<div class="postbox <?php echo $wp_locale->text_direction ?>">
	<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5&amp;ftu=get_top_searchterms&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3><?php _e('Top Keywords', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.searchterms', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['searchterms']);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$extra_info = "title='".__('Last','wp-slimstat-view').': '.date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";

	$clean_long_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;searchterms=$clean_long_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>".number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator)."</span></p>";
} ?>
	</div>
</div>

<div class="postbox <?php echo $wp_locale->text_direction ?>"><?php
title_period(__('Top Countries for', 'wp-slimstat-view'), ' slimstat-tooltips');

$results = $wp_slimstat_view->get_top('t1.country', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$country = __('c-'.$results[$i]['country'],'countries-languages');
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['country'][0])) $country = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;country={$results[$i]['country']}'>$country</a>";

	echo "<p$last_element $extra_info><span class='element-title'>$country</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<div class="postbox medium <?php echo $wp_locale->text_direction ?>">
	<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5&amp;ftu=get_top_traffic_sources&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div><?php
title_period(__('Top Traffic Sources for', 'wp-slimstat-view'), ' slimstat-tooltips');

$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer');
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
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['domain']);
	if (!isset($wp_slimstat_view->filters_parsed['domain'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;domain=$clean_string'>{$strings['text']}</a>";

	echo "<p$last_element $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$strings['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<div class="postbox <?php echo $wp_locale->text_direction ?>">
	<h3><?php _e('Search Engines', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_top('t1.domain', '', "searchterms <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['domain'], 36);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($search_engines > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$search_engines)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$clean_domain = str_replace('www.','', $strings['text']);
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	if (!isset($wp_slimstat_view->filters_parsed['domain'][0])) $clean_domain = "<a{$strings['tooltip']} class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;domain={$results[$i]['domain']}'>$clean_domain</a>";

	echo "<p$last_element $extra_info><span class='element-title'>$clean_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
} ?>
	</div>
</div>

<div class="postbox <?php echo $wp_locale->text_direction ?>"><?php
title_period(__('Sites for', 'wp-slimstat-view'), ' slimstat-tooltips');

$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.referer', "searchterms = '' AND domain <> '{$_SERVER['SERVER_NAME']}' AND domain <> ''");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['domain'], 36);
	$clean_domain = str_replace('www.','', $strings['text']);
	$strings = trim_value($results[$i]['referer'], 200);
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$percentage = ($count_pageviews_with_referer > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_pageviews_with_referer)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain'].$strings['text']);
	
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info =  "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}, ".__('Last','wp-slimstat-view').": ".(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	if (!isset($wp_slimstat_view->filters_parsed['domain'][0])) $clean_domain = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;domain={$results[$i]['domain']}'>$clean_domain</a>";

	echo "<p$last_element $extra_info><span class='element-title'><a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a>$clean_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
} ?>
	</div>
</div>

<div class="postbox medium <?php echo $wp_locale->text_direction ?>">
	<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5&amp;ftu=get_recent_searchterms&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3><?php _e('Recent Keywords &raquo; Pages', 'wp-slimstat-view'); ?></h3>
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

	$extra_info = " title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$clean_long_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $searchterms_to_show = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;searchterms=$clean_long_string'>$searchterms_to_show</a>";
	$clean_long_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $resource_to_show = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=3$filters_query&amp;resource=$clean_long_string'>$resource_to_show</a>";

	echo "<p$last_element$extra_info><span class='element-title'$show_searchterms_tooltip><a target='_blank' title='".__('Open referer in a new window','wp-slimstat-view')."'";
	echo " href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> ";
	echo $searchterms_to_show."</span> <span$show_resource_tooltip>$resource_to_show</span></p>";
} ?>
	</div>
</div>