<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Available icons
$supported_browser_icons = array('Android','Anonymouse','Baiduspider','BlackBerry','BingBot','CFNetwork','Chrome','Chromium','Default Browser','Exabot/BiggerBetter','FacebookExternalHit','FeedBurner','Feedfetcher-Google','Firefox','Internet Archive','Googlebot','Google Bot','Google Feedfetcher','Google Web Preview','IE','IEMobile','iPad','iPhone','iPod Touch','Maxthon','Mediapartners-Google','Microsoft-WebDAV','msnbot','Mozilla','NewsGatorOnline','Netscape','Nokia','Opera','Opera Mini','Opera Mobi','Python','PycURL','Safari','W3C_Validator','WordPress','Yahoo! Slurp','YandexBot');
$supported_os_icons = array('android','blackberry os','iphone osx','ios','java','linux','macosx','symbianos','win7','win8','win8.1','winphone7','winvista','winxp','unknown');
$supported_browser_types = array(__('Human','wp-slimstat'),__('Bot/Crawler','wp-slimstat'),__('Mobile Device','wp-slimstat'),__('Syndication Reader','wp-slimstat'));

$plugin_url = plugins_url('', dirname(__FILE__));

// Set the filters
$tables_to_join = 'tb.browser,tb.version,tb.platform,tb.css_version,tb.type,tb.user_agent,tci.content_type,tci.category,tci.author,tci.content_id';
wp_slimstat_db::$filters_normalized['misc']['limit_results'] = wp_slimstat::$options['number_results_raw_data'];
if (wp_slimstat::$options['include_outbound_links_right_now'] == 'yes'){
	$tables_to_join .= ',tob.outbound_domain,tob.outbound_resource';
}

// Report Header
if (empty($_POST['report_id'])){
	wp_slimstat_reports::report_header('slim_p7_02', 'tall', __('Color codes','wp-slimstat').'</strong><p><span class="little-color-box is-search-engine"></span> '.__('From search result page','wp-slimstat').'</p><p><span class="little-color-box is-known-visitor"></span> '.__('Known Visitor','wp-slimstat').'</p><p><span class="little-color-box is-known-user"></span> '.__('Known Users','wp-slimstat').'</p><p><span class="little-color-box is-direct"></span> '.__('Other Humans','wp-slimstat').'</p><p><span class="little-color-box"></span> '.__('Bot or Crawler','wp-slimstat').'</p>');
}

// Get the data
$results = wp_slimstat_db::get_recent('t1.id', '', $tables_to_join);
$count_page_results = count($results);
$count_all_results = wp_slimstat_db::count_records('1=1', '*', true, true, $tables_to_join);

if ($count_page_results == 0){
	echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
}
else if (wp_slimstat::$options['async_load'] != 'yes' || !empty($_POST['report_id'])){
	
	// Pagination
	echo wp_slimstat_reports::report_pagination('slim_p7_02', $count_page_results, $count_all_results);

	// Loop through the results
	for($i=0;$i<$count_page_results;$i++){
		
		$results[$i]['ip'] = long2ip($results[$i]['ip']);
		$host_by_ip = $results[$i]['ip'];
		if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
			$gethostbyaddr = gethostbyaddr( $results[$i]['ip'] );
			if ($gethostbyaddr != $host_by_ip && !empty($gethostbyaddr)) $host_by_ip .= ', '.$gethostbyaddr;
		}
		
		$results[$i]['dt'] = date_i18n(wp_slimstat::$options['date_time_format'], $results[$i]['dt'], true);

		// Print session header?
		if ($i == 0 || $results[$i-1]['visit_id'] != $results[$i]['visit_id'] || ($results[$i]['visit_id'] == 0 && ($results[$i-1]['ip'] != $results[$i]['ip'] || $results[$i-1]['browser'] != $results[$i]['browser'] || $results[$i-1]['platform'] != $results[$i]['platform']))){

			// Color-coded headers
			$highlight_row = !empty($results[$i]['searchterms'])?' is-search-engine':(($results[$i]['type'] != 1)?' is-direct':'');

			// Country
			$results[$i]['country'] = "<a class='slimstat-filter-link inline-icon' href='".wp_slimstat_reports::fs_url('country equals '.$results[$i]['country'])."'><img class='slimstat-tooltip-trigger' src='$plugin_url/images/flags/{$results[$i]['country']}.png' width='16' height='16'/><span class='slimstat-tooltip-content'>".__('c-'.$results[$i]['country'],'wp-slimstat')."</span></a>";

			// Browser
			if ($results[$i]['version'] == 0) $results[$i]['version'] = '';
			$browser_title = (wp_slimstat::$options['show_complete_user_agent_tooltip'] == 'no')?"{$results[$i]['browser']} {$results[$i]['version']}":$results[$i]['user_agent'];
			$browser_icon = $plugin_url.'/images/browsers/other-browsers-and-os.png';
			if (in_array($results[$i]['browser'], $supported_browser_icons)){
				$browser_icon = $plugin_url.'/images/browsers/'.sanitize_title($results[$i]['browser']).'.png';
			}
			$browser_filtered = "<a class='slimstat-filter-link inline-icon' href='".wp_slimstat_reports::fs_url('browser equals '.$results[$i]['browser'])."'><img class='slimstat-tooltip-trigger' src='$browser_icon' width='16' height='16'/><span class='slimstat-tooltip-content'>$browser_title</span></a>";

			// Platform
			$platform_icon = $plugin_url."/images/browsers/other-browsers-and-os.png' title='".__($results[$i]['platform'],'wp-slimstat')."' width='16' height='16'/>";
			if (in_array(strtolower($results[$i]['platform']), $supported_os_icons)){
				$platform_icon = $plugin_url.'/images/platforms/'.sanitize_title($results[$i]['platform']).'.png';
			}
			$platform_filtered = "<a class='slimstat-filter-link inline-icon' href='".wp_slimstat_reports::fs_url('platform equals '.$results[$i]['platform'])."'><img class='slimstat-tooltip-trigger' src='$platform_icon' width='16' height='16'/><span class='slimstat-tooltip-content'>".__($results[$i]['platform'],'wp-slimstat')."</span></a>";

			// Browser Type
			$browser_type_filtered = '';
			if ($results[$i]['type'] != 0){
				$browser_type_filtered = "<a class='slimstat-filter-link inline-icon' href='".wp_slimstat_reports::fs_url('type equals '.$results[$i]['type'])."'><img class='slimstat-tooltip-trigger' src='$plugin_url/images/browsers/type{$results[$i]['type']}.png' width='16' height='16'/><span class='slimstat-tooltip-content'>{$supported_browser_types[$results[$i]['type']]}</span></a>";
			}

			$notes = '';
			if (!empty($results[$i]['notes'])){
				$notes = str_replace(array(';', ':'), array('<br/>', ': '), $results[$i]['notes']);
				$notes = "<span id='pageview-notes'><i class='slimstat-font-edit inline-icon slimstat-tooltip-trigger'></i><b class='slimstat-tooltip-content'>{$notes}</b></span>";
			}

			// IP Address and user
			if (empty($results[$i]['user'])){
				$ip_address = "<a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('ip equals '.$results[$i]['ip'])."'>$host_by_ip</a>";
			}
			else{
				$display_user_name = $results[$i]['user'];
				if (wp_slimstat::$options['show_display_name'] == 'yes' && strpos($results[$i]['notes'], 'user:') !== false){
					$display_real_name = get_user_by('login', $results[$i]['user']);
					if (is_object($display_real_name)) $display_user_name = $display_real_name->display_name;
				}
				$ip_address = "<a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('user equals '.$results[$i]['user'])."'>{$display_user_name}</a>";
				$ip_address .= " <a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('ip equals '.$results[$i]['ip'])."'>({$results[$i]['ip']})</a>";
				$highlight_row = (strpos( $results[$i]['notes'], 'user:') !== false)?' is-known-user':' is-known-visitor';
				
			}
			if (!empty(wp_slimstat::$options['ip_lookup_service'])){
				$ip_address = "<a class='slimstat-font-location-1 whois' href='".wp_slimstat::$options['ip_lookup_service']."{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'></a> $ip_address";
			}

			// Originating IP Address
			$other_ip_address = '';
			if (!empty($results[$i]['other_ip'])){
				$results[$i]['other_ip'] = long2ip($results[$i]['other_ip']);
				$other_ip_address = "<a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('other_ip equals '.$results[$i]['other_ip'])."'>(".__('Originating IP','wp-slimstat').": {$results[$i]['other_ip']})</a>";
			}

			// Plugins
			$plugins = '';
			if (!empty($results[$i]['plugins'])){
				$results[$i]['plugins'] = explode(',', $results[$i]['plugins']);
				foreach($results[$i]['plugins'] as $a_plugin){
					$a_plugin = trim($a_plugin);
					$plugins .= "<a class='slimstat-filter-link inline-icon' href='".wp_slimstat_reports::fs_url('plugins contains '.$a_plugin)."'><img class='slimstat-tooltip-trigger' src='$plugin_url/images/plugins/$a_plugin.png' width='16' height='16'/><span class='slimstat-tooltip-content'>".__($a_plugin,'wp-slimstat')."</span></a> ";
				}
			}				

			echo "<p class='header$highlight_row'>{$results[$i]['country']} $browser_filtered $platform_filtered $browser_type_filtered $ip_address $other_ip_address $notes <span class='plugins'>$plugins</span></p>";
		}

		echo "<p>";
		$results[$i]['referer'] = (strpos($results[$i]['referer'], '://') === false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'];

		$performance = '';
		if (!empty($results[$i]['server_latency']) || !empty($results[$i]['page_performance'])){
			$performance = "<i class='slimstat-font-gauge spaced' title='".__('Server Latency and Page Speed in milliseconds','wp-slimstat')."'></i> ".__('SL','wp-slimstat').": {$results[$i]['server_latency']} / ".__('PS','wp-slimstat').": {$results[$i]['page_performance']}";
		}

		// Permalink: find post title, if available
		if (!empty($results[$i]['resource'])){
			$base_url = '';
			if (!empty($results[$i]['blog_id'])){
				$base_url = parse_url(get_site_url($results[$i]['blog_id']));
				$base_url = $base_url['scheme'].'://'.$base_url['host'];
			}
			$results[$i]['resource'] = "<a class='slimstat-font-logout' target='_blank' title='".htmlentities(__('Open this URL in a new window','wp-slimstat'), ENT_QUOTES, 'UTF-8')."' href='".$base_url.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8')."'></a> $base_url<a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('resource equals '.$results[$i]['resource'])."'>".wp_slimstat_reports::get_resource_title($results[$i]['resource']).'</a>';
		}
		else{
			$results[$i]['resource'] = __('Local search results page','wp-slimstat');
		}

		// Search Terms, with link to original SERP
		if (!empty($results[$i]['searchterms'])){
			$results[$i]['searchterms'] = "<i class='spaced slimstat-font-search' title='".__('Search Terms','wp-slimstat')."'></i> ".wp_slimstat_reports::get_search_terms_info($results[$i]['searchterms'], $results[$i]['domain'], $results[$i]['referer']);
		}
		$results[$i]['domain'] = (!empty($results[$i]['domain']) && empty($results[$i]['searchterms']))?"<a class='spaced slimstat-font-login' target='_blank' title='".htmlentities(__('Open this referrer in a new window','wp-slimstat'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['referer']}'></a> {$results[$i]['domain']}":'';
		$results[$i]['outbound_domain'] = (!empty($results[$i]['outbound_domain']))?"<a class='inline-icon spaced slimstat-font-logout' target='_blank' title='".htmlentities(__('Open this outbound link in a new window','wp-slimstat'), ENT_QUOTES, 'UTF-8')."' href='{$results[$i]['outbound_resource']}'></a> {$results[$i]['outbound_domain']}":'';
		$results[$i]['dt'] = "<i class='spaced slimstat-font-clock' title='".__('Date and Time','wp-slimstat')."'></i> {$results[$i]['dt']}";
		$results[$i]['content_type'] = !empty($results[$i]['content_type'])?"<i class='spaced slimstat-font-doc' title='".__('Content Type','wp-slimstat')."'></i> <a class='slimstat-filter-link' href='".wp_slimstat_reports::fs_url('content_type equals '.$results[$i]['content_type'])."'>{$results[$i]['content_type']}</a> ":'';
		echo "{$results[$i]['resource']} <span class='details'>{$results[$i]['searchterms']} {$results[$i]['domain']} {$results[$i]['outbound_domain']} {$results[$i]['content_type']} $performance {$results[$i]['dt']}</span>";
		echo '</p>';
	}
	
	// Pagination
	if ($count_page_results > 20){
		echo wp_slimstat_reports::report_pagination('slim_p7_02', $count_page_results, $count_all_results);
	}
}

if (empty($_POST['report_id'])): ?>
	</div>
</div>
<?php
endif; 