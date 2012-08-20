<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Data about our visits
$current_pageviews = wp_slimstat_db::count_records();
$count_pageviews_with_referer = wp_slimstat_db::count_records('t1.referer <> ""');

foreach(wp_slimstat_boxes::$all_boxes as $a_box_id)
	switch($a_box_id){
		case 'slim_p3_01':
			wp_slimstat_boxes::box_header('slim_p3_01', wp_slimstat_boxes::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_boxes::chart_title(__('Traffic Sources', 'wp-slimstat-view')));
			wp_slimstat_boxes::show_chart('slim_p3_01', wp_slimstat_db::extract_data_for_chart('COUNT(DISTINCT(`domain`))', 'COUNT(DISTINCT(ip))', "AND domain <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'"), array(__('Domains','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_02':
			wp_slimstat_boxes::box_header('slim_p3_02', '', '', false, 'noscroll');
			wp_slimstat_boxes::show_traffic_sources_summary('slim_p3_02', $current_pageviews);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_03':
			wp_slimstat_boxes::box_header('slim_p3_03', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p3_03', 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_04':
			wp_slimstat_boxes::box_header('slim_p3_04', htmlspecialchars(__('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p3_04', 'country', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_05':
			wp_slimstat_boxes::box_header('slim_p3_05', '', '', true);
			wp_slimstat_boxes::show_results('popular_complete', 'slim_p3_05', 'domain', array('total_for_percentage' => wp_slimstat_db::count_records('t1.referer <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_06':
			wp_slimstat_boxes::box_header('slim_p3_06', '', 'medium', true);
			wp_slimstat_boxes::show_results('popular_complete', 'slim_p3_06', 'domain', array('total_for_percentage' => wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.id'), 'custom_where' => "t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}'"));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_08':
			wp_slimstat_boxes::box_header('slim_p3_08', htmlspecialchars(__('Take a sneak peek at what human visitors are doing on your website','wp-slimstat-view'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat-view'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('From a search result page','wp-slimstat-view'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat-view'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat-view'), ENT_QUOTES).'</p>', 'medium', true);
			wp_slimstat_boxes::show_spy_view('slim_p3_08');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_09':
			wp_slimstat_boxes::box_header('slim_p3_09', htmlspecialchars(__('Keywords used by your visitors to find your website on a search engine','wp-slimstat-view'), ENT_QUOTES), 'medium', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p3_09', 'searchterms');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_10':
			wp_slimstat_boxes::box_header('slim_p3_10', '', '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p3_10', 'country');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p3_11':
			wp_slimstat_boxes::box_header('slim_p3_11', '', 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p3_11', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('t1.domain <> ""'), 'custom_where' => 't1.domain <> ""'));
			wp_slimstat_boxes::box_footer();
		default:
			break;
	}