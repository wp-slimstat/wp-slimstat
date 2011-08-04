<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

foreach($panels_order as $a_panel_id)
	switch($a_panel_id):
		case 'p4_01':
?>

<div class="postbox wide <?php echo $wp_locale->text_direction ?>" id="p4_01"><?php 
echo '<h3 class="hndle">';
if (!$wp_slimstat_view->day_filter_active)
	_e('Average pageviews per visit by day - Click on a day for hourly metrics', 'wp-slimstat-view');
else
	_e('Average pageviews per visit by hour', 'wp-slimstat-view');
echo '</h3>';

// This panel has a slightly different query
$sql_from_where = "
	FROM (
		SELECT visit_id, count(ip) count, MAX(dt) dt
		FROM [from_tables]
		WHERE [where_clause]
		GROUP BY visit_id
	) AS ts1";
$current = $wp_slimstat_view->extract_data_for_chart('AVG(ts1.count)', 'MAX(ts1.count)', 4, __('Avg Pageviews','wp-slimstat-view'), __('Longest visit','wp-slimstat-view'), 2, "AND visit_id > 0", $sql_from_where);
if ($current->current_non_zero_count+$current->previous_non_zero_count == 0)
	echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
else{ ?>
	<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=https://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="780" height="180" id="line" >
		<param name="movie" value="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/fcf.swf" />
		<param name="FlashVars" value="&amp;dataXML=<?php echo $current->xml ?>&amp;chartWidth=780&amp;chartHeight=180">
		<param name="quality" value="high" />
		<param name="wmode" value="transparent" />
		<embed src="<?php echo $wp_slimstat_view->plugin_url ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&amp;dataXML=<?php echo $current->xml ?>&amp;chartWidth=780&amp;chartHeight=180" quality="high" width="780" height="180" name="line" wmode="transparent" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
	</object><?php
} ?>
</div>

<?php break; case 'p4_02': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_02">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_resources&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent Contents', "wp-slimstat-view"); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 40);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text']= "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_03': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_03">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_bouncing_pages&amp;cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent bouncing pages', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user', '', '', 'HAVING COUNT(visit_id) = 1');
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 30);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
	$element_url = $wp_slimstat_view->blog_domain.$results[$i]['resource'];
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_04': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_04">
	<h3 class="hndle"><?php _e('Recent Feeds', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user', "(resource LIKE '%/feed' OR resource LIKE '%?feed=%' OR resource LIKE '%&feed=%')");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 40);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_05': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p4_05">
	<div class="more"><a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=wp-slimstat&amp;slimpanel=5&amp;ftu=get_recent_404<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
	<h3 class="hndle"><?php _e('Recent 404 pages', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user', "resource LIKE '[404]%'");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 65);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$clean_string = urlencode($results[$i]['resource']);
	$strings['text'] = str_replace('[404]', '', $strings['text']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_06': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_06">
	<h3 class="hndle"><?php _e('Recent Internal Searches', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent('t1.searchterms', 't1.ip, t1.user', "(resource = '__l_s__' OR resource = '')");
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['searchterms']);
	$extra_info = "title='".date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
	$highlight_row = !empty($results[$i]['user'])?'class="is-known-user"':'';
	$clean_string = urlencode($results[$i]['searchterms']);
	if (!isset($wp_slimstat_view->filters_parsed['searchterms'][1]) || $wp_slimstat_view->filters_parsed['searchterms'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;searchterms=$clean_string'>{$strings['text']}</a>";

	echo "<p $highlight_row$extra_info>{$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_07': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_07"><?php
title_period(__('Top Categories for', 'wp-slimstat-view'), $wp_slimstat_view, ' slimstat-tooltips');

$categories = get_categories();
$where_categories = 'resource IN (';
foreach ($categories as $a_category){
	$where_categories .= "'".str_replace($wp_slimstat_view->blog_domain, '', get_category_link($a_category->term_id))."',";
}
$where_categories = substr($where_categories, 0, -1).')';
$current_pageviews = $wp_slimstat_view->count_records();

$results = $wp_slimstat_view->get_top('t1.resource', 't1.ip, t1.user', $where_categories);
$count_results = count($results);
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 32);
	$percentage = ($current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator):0;
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['resource']);
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><span class='element-title'>{$strings['text']}</span> <span>$percentage%</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p4_08': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p4_08">
	<h3 class="hndle"><?php _e('Recent Outbound Links', 'wp-slimstat-view'); ?></h3>
	<div class="container"><?php
$results = $wp_slimstat_view->get_recent_outbound(0);
$count_results = count($results);
$outbound_id = 0;
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	$results[$i]['dt'] = date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	if ($outbound_id != $results[$i]['outbound_id']){
		$highlight_row = '';
		if (empty($results[$i]['user']))
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$results[$i]['ip']}</a>";
		else{
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
			$highlight_row = ' is-known-user';
		}
		$ip_address = "<a href='$ip_lookup_url{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/whois.gif' /></a> $ip_address";
		$country = __('c-'.$results[$i]['country'],'countries-languages');

		echo "<p class='header$highlight_row'>$ip_address <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['dt']}</span></p>";
		$outbound_id = $results[$i]['outbound_id'];
	}
	$resource = trim_value($results[$i]['resource'], 35);
	$outbound_resource = trim_value($results[$i]['outbound_domain'], 40);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['outbound_domain'].$results[$i]['outbound_resource']);
	echo "<p><span class='element-title'{$resource['tooltip']}>{$resource['text']}</span>";
	echo " <span><a target='_blank' title='$element_title' href='http://{$results[$i]['outbound_domain']}{$results[$i]['outbound_resource']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$outbound_resource['text']}</span></p>";
} ?>
	</div>
</div>

<?php break; case 'p4_09': ?>
<div class="postbox <?php echo $wp_locale->text_direction ?>" id="p4_09"><?php
title_period(__('Top Exit Pages for', 'wp-slimstat-view'), $wp_slimstat_view, ' slimstat-tooltips');

$results = $wp_slimstat_view->get_top('t1.resource', 't1.ip, t1.user', "visit_id > 0 AND resource <> '' AND resource <> '__l_s__'");
$count_results = count($results);
$count_exit_pages = $wp_slimstat_view->count_exit_pages();
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$strings = trim_value($results[$i]['resource'], 36);
	$results[$i]['count'] = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
	$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
	$clean_string = urlencode($results[$i]['resource']);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
	$element_url = $wp_slimstat_view->blog_domain.$results[$i]['resource'];
	if (!isset($wp_slimstat_view->filters_parsed['resource'][1]) || $wp_slimstat_view->filters_parsed['resource'][1]!='equals')
		$strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;resource=$clean_string'>{$strings['text']}</a>";

	echo "<p $extra_info><a target='_blank' title='$element_title' href='$element_url'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$strings['text']}</p>";
} ?>
	</div>
</div>

<?php break; case 'p4_10': ?>
<div class="postbox medium <?php echo $wp_locale->text_direction ?>" id="p4_10">
	<h3 class="hndle"><?php _e('Recent Downloads', 'wp-slimstat-view'); ?></h3>
	<div class="container slimstat-tooltips"><?php
$results = $wp_slimstat_view->get_recent_outbound(1);
$count_results = count($results);
$outbound_id = 0;
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	$results[$i]['dt'] = date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);
	if ($outbound_id != $results[$i]['outbound_id']){
		if (empty($results[$i]['user']))
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$results[$i]['ip']}</a>";
		else
			$ip_address = "<a class='activate-filter' href='{$_SERVER['PHP_SELF']}?page=wp-slimstat&amp;slimpanel=4$filters_query&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
		$ip_address = "<a href='$ip_lookup_url{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/whois.gif' /></a> $ip_address";
		$country = __('c-'.$results[$i]['country'],'countries-languages');
		$browser_version = ($results[$i]['version']!='0')?$results[$i]['version']:'';

		echo "<p class='header'>$ip_address <span>{$results[$i]['browser']} $browser_version</span> <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['dt']}</span></p>";
		$outbound_id = $results[$i]['outbound_id'];
	}
	$outbound_resource = trim_value($results[$i]['outbound_resource'], 45);
	$outbound_domain = trim_value($results[$i]['outbound_domain'], 36);
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['outbound_resource']);
	echo "<p><a target='_blank' title='$element_title' href='http://{$results[$i]['outbound_domain']}{$results[$i]['outbound_resource']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> <span class='element-title'{$outbound_resource['tooltip']}>{$outbound_resource['text']}</span> <span>{$outbound_domain['text']}</span></p>";
} ?>
	</div>
</div><?php	break;
	default:
		echo "<div class='postbox hidden' id='$a_panel_id'></div>";
endswitch;