<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Data about our visits
$current_pageviews = wp_slimstat_db::count_records();
$chart_data = wp_slimstat_db::extract_data_for_chart('COUNT(t1.ip)', 'COUNT(DISTINCT(t1.ip))');

foreach(wp_slimstat_boxes::$all_boxes as $a_box_id)
	switch($a_box_id){
		case 'slim_p1_01':
			wp_slimstat_boxes::box_header('slim_p1_01', wp_slimstat_boxes::$chart_tooltip, 'wide chart', false, 'noscroll', wp_slimstat_boxes::chart_title(__('Pageviews', 'wp-slimstat-view')));
			wp_slimstat_boxes::show_chart('slim_p1_01', $chart_data, array(__('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_02':
			wp_slimstat_boxes::box_header('slim_p1_02', '', '', false, 'noscroll');
			wp_slimstat_boxes::show_about_wpslimstat('slim_p1_02');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_03':
			wp_slimstat_boxes::box_header('slim_p1_03', '', '', false, 'noscroll');
			wp_slimstat_boxes::show_overview_summary('slim_p1_03', $current_pageviews, $chart_data);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_04':
			wp_slimstat_boxes::box_header('slim_p1_04', htmlspecialchars(__('When visitors leave a comment on your blog, Wordpress assigns them a cookie. WP SlimStat leverages this information to identify returning visitors.','wp-slimstat-view'), ENT_QUOTES, 'UTF-8'));
			wp_slimstat_boxes::show_results('recent', 'slim_p1_04', 'user');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_05':
			wp_slimstat_boxes::box_header('slim_p1_05', htmlspecialchars(__('Take a sneak peek at what human visitors are doing on your website','wp-slimstat-view'), ENT_QUOTES, 'UTF-8').'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat-view'), ENT_QUOTES, 'UTF-8').'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('From a search result page','wp-slimstat-view'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Known Users','wp-slimstat-view'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Other Humans','wp-slimstat-view'), ENT_QUOTES, 'UTF-8').'</p>', 'medium', true);
			wp_slimstat_boxes::show_spy_view('slim_p1_05');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_06':
			wp_slimstat_boxes::box_header('slim_p1_06', htmlspecialchars(__('Keywords used by your visitors to find your website on a search engine','wp-slimstat-view'), ENT_QUOTES, 'UTF-8'), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p1_06', 'searchterms');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_07':
			wp_slimstat_boxes::box_header('slim_p1_07', htmlspecialchars(__('Unique sessions initiated by your visitors. If a user is inactive on your site for 30 minutes or more, any future activity will be attributed to a new session. Users that leave your site and return within 30 minutes will be counted as part of the original session.','wp-slimstat-view'), ENT_QUOTES, 'UTF-8'), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p1_07', 'language', array('total_for_percentage' => wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1'), 'custom_where' => 't1.visit_id > 0 AND tb.type <> 1'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_08':
			wp_slimstat_boxes::box_header('slim_p1_08', '', 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p1_08', 'SUBSTRING_INDEX(t1.resource, "?", 1)', array('total_for_percentage' => $current_pageviews, 'as_column' => ' AS resource'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_09':
			wp_slimstat_boxes::box_header('slim_p1_09', '', '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p1_09', 'country');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_10':
			wp_slimstat_boxes::box_header('slim_p1_10', '', '', true);
			wp_slimstat_boxes::show_results('popular_complete', 'slim_p1_10', 'domain', array('total_for_percentage' => wp_slimstat_db::count_records('t1.domain <> "" AND t1.domain <> "'.wp_slimstat_boxes::$home_url_parsed['host'].'"'), 'custom_where' => 't1.domain <> "" AND t1.domain <> "'.wp_slimstat_boxes::$home_url_parsed['host'].'"'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_11':
			wp_slimstat_boxes::box_header('slim_p1_11', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p1_11', 'user', array('total_for_percentage' => wp_slimstat_db::count_records('t1.user <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_12':
			wp_slimstat_boxes::box_header('slim_p1_12', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p1_12', 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p1_13':
			wp_slimstat_boxes::box_header('slim_p1_13', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p1_13', 'country', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		default:
	}