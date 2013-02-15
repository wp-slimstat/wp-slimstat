<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Available icons
$supported_browser_icons = array('Android','Anonymouse','Baiduspider','BlackBerry','BingBot','CFNetwork','Chrome','Chromium','Default Browser','Exabot/BiggerBetter','FacebookExternalHit','FeedBurner','Feedfetcher-Google','Firefox','Googlebot','Google Feedfetcher','Google Web Preview','IE','IEMobile','iPad','iPhone','iPod Touch','Maxthon','Mediapartners-Google','msnbot','Mozilla','NewsGatorOnline','Netscape','Nokia','Opera','Opera Mini','Opera Mobi','Python','PycURL','Safari','WordPress','Yahoo! Slurp','YandexBot');
$supported_os_icons = array('android','blackberry os','iphone osx','ios','java','linux','macosx','symbianos','win7','win8','winphone7','winvista','winxp','unknown');

// Retrieve results
wp_slimstat_db::$filters['parsed']['limit_results'][1] = wp_slimstat::$options['number_results_raw_data'];

$tables_to_join = 'tb.*,tci.*';
if ($using_screenres) $tables_to_join .= ',tss.*';
$results = wp_slimstat_db::get_recent('t1.id', '', $tables_to_join);

// Pagination
$count_raw_data = wp_slimstat_db::count_records('1=1', '*', true, $tables_to_join);
$count_results = count($results);
$ending_point = min($count_raw_data, wp_slimstat_db::$filters['parsed']['starting'][1] + wp_slimstat_db::$filters['parsed']['limit_results'][1]);
$previous_next = '';

if (wp_slimstat_db::$filters['parsed']['starting'][1] - wp_slimstat_db::$filters['parsed']['limit_results'][1] > 0){
	$previous_next .= '<a class="first" href="'.wp_slimstat_boxes::fs_url('starting', 0, 'equals').'"></a> ';
}
if (wp_slimstat_db::$filters['parsed']['starting'][1] > 0){
	$new_starting = (wp_slimstat_db::$filters['parsed']['starting'][1] > wp_slimstat_db::$filters['parsed']['limit_results'][1])?wp_slimstat_db::$filters['parsed']['starting'][1]-wp_slimstat_db::$filters['parsed']['limit_results'][1]:0;
	$previous_next .= '<a class="previous" href="'.wp_slimstat_boxes::fs_url('starting', $new_starting, 'equals').'"></a> ';
}
if ($ending_point < $count_raw_data && $count_results > 0){
	$new_starting = wp_slimstat_db::$filters['parsed']['starting'][1] + wp_slimstat_db::$filters['parsed']['limit_results'][1];
	$previous_next .= '<a class="next" href="'.wp_slimstat_boxes::fs_url('starting', $new_starting, 'equals').'"></a> ';
}
if ($ending_point + wp_slimstat_db::$filters['parsed']['limit_results'][1] < $count_raw_data && $count_results > 0){
	$new_starting = $count_raw_data - $count_raw_data%wp_slimstat_db::$filters['parsed']['limit_results'][1];
	$previous_next .= '<a class="last" href="'.wp_slimstat_boxes::fs_url('starting', $new_starting, 'equals').'"></a> ';
}

// Display results
echo "<div class='postbox tall slimstat'><div class='previous-next'>$previous_next</div><h3 class='hndle'>";
if ($count_results == 0){
	_e('No records found', 'wp-slimstat-view');
}
else {
	echo sprintf(__('Records: %d - %d of %d', 'wp-slimstat-view'), wp_slimstat_db::$filters['parsed']['starting'][1], $ending_point, $count_raw_data); 
}
if (wp_slimstat::$options['refresh_interval'] > 0) echo ' <span>['.__('refreshing in','wp-slimstat-view').' <span class="refresh-timer"></span>]</span>';
echo '</h3><div class="inside">';
				
if ($count_results == 0){
	echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
}

for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
		$host_by_ip = gethostbyaddr( $results[$i]['ip'] );
	}
	else{
		$host_by_ip = $results[$i]['ip'];
	}
	$results[$i]['dt'] = date_i18n(wp_slimstat_db::$formats['date_time_format'], $results[$i]['dt'], true);

	if ($i == 0 || $results[$i-1]['visit_id'] != $results[$i]['visit_id'] || ($results[$i]['visit_id'] == 0 && ($results[$i-1]['ip'] != $results[$i]['ip'] || $results[$i-1]['browser'] != $results[$i]['browser'] || $results[$i-1]['platform'] != $results[$i]['platform']))){
		$highlight_row = !empty($results[$i]['searchterms'])?' is-search-engine':(($results[$i]['type'] != 1)?' is-direct':'');
		
		// IP Address and user
		if (empty($results[$i]['user'])){
			$ip_address = "<a title='".htmlentities(sprintf(__('Filter results where IP equals %s','wp-slimstat-view'), $results[$i]['ip']), ENT_QUOTES, 'UTF-8')."' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('ip', $results[$i]['ip'])."'>$host_by_ip</a>";
		}
		else{
			$ip_address = "<a title='".htmlentities(sprintf(__('Filter results where user equals %s','wp-slimstat-view'), $results[$i]['user']), ENT_QUOTES, 'UTF-8')."' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('user', $results[$i]['user'])."'>{$results[$i]['user']}</a>";
			$ip_address .= " <a title='".htmlentities(sprintf(__('Filter results where IP equals %s','wp-slimstat-view'), $results[$i]['ip']), ENT_QUOTES, 'UTF-8')."' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('ip', $results[$i]['ip'])."'>({$results[$i]['ip']})</a>";
			$highlight_row = ' is-known-user';
		}
		if (!empty(wp_slimstat::$options['ip_lookup_service'])) $ip_address = "<a class='whois16 image' href='".wp_slimstat::$options['ip_lookup_service']."{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'></a> $ip_address";
		$other_ip_address = '';
		if (!empty($results[$i]['other_ip'])){
			$results[$i]['other_ip'] = long2ip($results[$i]['other_ip']);
			$other_ip_address = "<a class='text-filter' title='".htmlentities(sprintf(__('Filter results where ther user\'s real IP equals %s','wp-slimstat-view'), $results[$i]['other_ip']), ENT_QUOTES, 'UTF-8')."' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('other_ip', $results[$i]['other_ip'])."'>(".__('Originating IP','wp-slimstat-view').": {$results[$i]['other_ip']})</a>";
		}

		// Country
		$results[$i]['country'] = "<a class='image first' href='".wp_slimstat_boxes::fs_url('country', $results[$i]['country'])."'><img src='".wp_slimstat_boxes::$plugin_url."/images/flags/{$results[$i]['country']}.png' title='".__('Country','wp-slimstat-view').': '.__('c-'.$results[$i]['country'],'countries-languages')."' width='16' height='16'/></a>";

		// Browser
		if ($results[$i]['version'] == 0) $results[$i]['version'] = '';
		if (in_array($results[$i]['browser'], $supported_browser_icons)){
			$browser_icon = "<img src='".wp_slimstat_boxes::$plugin_url.'/images/browsers/'.sanitize_title($results[$i]['browser']).'.png'."' title='".__('Browser','wp-slimstat-view').": {$results[$i]['browser']} {$results[$i]['version']}' width='16' height='16'/>";
		}
		else{
			$browser_icon = "<img src='".wp_slimstat_boxes::$plugin_url."/images/browsers/other-browsers-and-os.png' title='".__('Browser','wp-slimstat-view').": {$results[$i]['browser']} {$results[$i]['version']}' width='16' height='16'/>";
		}
		$browser_filtered = "<a class='image' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('browser', $results[$i]['browser'])."'>$browser_icon</a>";

		// Platform
		if (in_array(strtolower($results[$i]['platform']), $supported_os_icons)){
			$platform_icon = "<img src='".wp_slimstat_boxes::$plugin_url.'/images/platforms/'.sanitize_title($results[$i]['platform']).'.png'."' title='".__('Platform','wp-slimstat-view').': '.__($results[$i]['platform'],'countries-languages')."' width='16' height='16'/>";
		}
		else{
			$platform_icon = "<img src='".wp_slimstat_boxes::$plugin_url."/images/browsers/other-browsers-and-os.png' title='".__('Platform','wp-slimstat-view').': '.__($results[$i]['platform'],'countries-languages')."' width='16' height='16'/>";
		}
		$platform_filtered = "<a class='image' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('platform', $results[$i]['platform'])."'>$platform_icon</a>";
	
		// Plugins
		$plugins = '';
		if (!empty($results[$i]['plugins'])){
			$results[$i]['plugins'] = explode(',', $results[$i]['plugins']);
			foreach($results[$i]['plugins'] as $a_plugin){
				$a_plugin = trim($a_plugin);
				$plugins .= "<a class='image' href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('plugins', $a_plugin)."'><img src='".wp_slimstat_boxes::$plugin_url."/images/plugins/$a_plugin.png' title='".__($a_plugin,'countries-languages')."' width='16' height='16'/></a>";
			}
		}
		if ($using_screenres) $plugins .= '&nbsp;&nbsp;<a title="'.htmlentities(sprintf(__('Filter results where screen resolution equals %s','wp-slimstat-view'), $results[$i]['resolution']), ENT_QUOTES, 'UTF-8').'" href="'.wp_slimstat_boxes::fs_url('resolution', $results[$i]['resolution']).'">'.$results[$i]['resolution'].'</a>';

		echo "<p class='header$highlight_row'><em class='user-details'>{$results[$i]['country']} $browser_filtered $platform_filtered $ip_address $other_ip_address</em> <span class='plugins'>$plugins</span></p>";
	}

	echo "<p>";
	$results[$i]['referer'] = (strpos($results[$i]['referer'], '://') === false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'];
	
	// Permalink: find post title, if available
	if (!empty($results[$i]['resource'])){
		$results[$i]['resource'] = "<a class='url' target='_blank' title='".htmlentities(__('Open this page in a new window','wp-slimstat-view'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['resource']}'></a> <a title='".htmlentities(sprintf(__('Filter results where resource equals %s','wp-slimstat-view'), $results[$i]['resource']), ENT_QUOTES, 'UTF-8')."' href='".wp_slimstat_boxes::fs_url('resource', $results[$i]['resource'])."'>".wp_slimstat_boxes::get_resource_title($results[$i]['resource']).'</a>';
	}
	else{
		$results[$i]['resource'] = __('Local search results page','wp-slimstat-view');
	}

	// Search Terms, with link to original SERP
	parse_str("daum=search?q&naver=search.naver?query&google=search?q&yahoo=search?p&bing=search?q&aol=search?query&lycos=web?q&ask=web?q&cnn=search/?query&about=?q&mamma=result.php?q&voila=S/voila?rdata&virgilio=ricerca?qs&baidu=s?wd&yandex=yandsearch?text&najdi=search.jsp?q&seznam=?q&onet=wyniki.html?qt&yam=Search/Web/DefaultCSA.aspx?k&pchome=/search/?q&kvasir=alle?q&arama.mynet=web/goal/1/?q&nova_rambler=search?query", $query_formats);
	preg_match("/(daum|naver|google|yahoo|bing|aol|lycos|ask|cnn|about|mamma|voila|virgilio|baidu|yandex|najdi|seznam|onet|szukacz|yam|pchome|kvasir|mynet|ekolay|rambler)./", $results[$i]['domain'], $matches);

	parse_str($results[$i]['referer'], $query_parse_str);
	$query_details = '';
	if (!empty($query_parse_str['source'])) $query_details = __('src','wp-slimstat-view').": {$query_parse_str['source']}";
	if (!empty($query_parse_str['cd'])) $query_details = __('serp','wp-slimstat-view').": {$query_parse_str['cd']}";
	if (!empty($query_details)) $query_details = "($query_details)";

	if (!empty($results[$i]['searchterms'])){		
		if (!empty($matches) && !empty($query_formats[$matches[1]])){
			$results[$i]['searchterms'] = "<a class='url' target='_blank' title='".htmlentities(__('Go to the corresponding search engine result page','wp-slimstat-view'), ENT_QUOTES, 'UTF-8')."' href='http://{$results[$i]['domain']}/".$query_formats[$matches[1]].'='.urlencode($results[$i]['searchterms'])."'></a> ".htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
		}
		else{
			$results[$i]['searchterms'] = "<a class='url' target='_blank' title='".htmlentities(__('Go to the referring page','wp-slimstat-view'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['referer']}'></a> ".htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
		}		
		$results[$i]['searchterms'] = "<em class='searchterms'>{$results[$i]['searchterms']} $query_details</em>";
	}
	
	$results[$i]['domain'] = (!empty($results[$i]['domain']) && empty($results[$i]['searchterms']))?"<em class='domain'><a class='url' target='_blank' title='".htmlentities(__('Open in a new window','wp-slimstat-view'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['referer']}'></a> {$results[$i]['domain']} $query_details</em>":'';
	$results[$i]['dt'] = "<em class='datetime'>{$results[$i]['dt']}</em>";
	$results[$i]['content_type'] = !empty($results[$i]['content_type'])?"<a href='".wp_slimstat_boxes::$current_screen_url.'&amp;fs='.wp_slimstat_boxes::replace_query_arg('content_type', $results[$i]['content_type'])."' title='".htmlentities(sprintf(__('Filter results where content type equals %s','wp-slimstat-view'), $results[$i]['content_type']), ENT_QUOTES, 'UTF-8')."'>{$results[$i]['content_type']}</a>: ":'';
	echo "<em class='resource'>{$results[$i]['content_type']} {$results[$i]['resource']}</em> <span class='details'>{$results[$i]['searchterms']} {$results[$i]['domain']} {$results[$i]['dt']}</span>";
	echo '</p>';
} ?>
	</div>
</div>
<p style="clear:both" class="legend"><span class="legend-title"><?php _e('Color codes','wp-slimstat-view') ?>:</span>
	<span class="little-color-box is-search-engine"><?php _e('From a search result page','wp-slimstat-view') ?></span>
	<span class="little-color-box is-known-user"><?php _e('Known Users','wp-slimstat-view') ?></span>
	<span class="little-color-box is-direct"><?php _e('Other Humans','wp-slimstat-view') ?></span>
	<span class="little-color-box"><?php _e('Bots, Crawlers and others','wp-slimstat-view') ?></span>
</p>