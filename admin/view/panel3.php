<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Data about our visits
$current_pageviews = wp_slimstat_db::count_records();
$total_human_hits = wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1');
$total_human_visits = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id');

foreach(wp_slimstat_boxes::$all_boxes as $a_box_id)
	switch($a_box_id){
		case 'slim_p2_01':
			wp_slimstat_boxes::box_header('slim_p2_01', wp_slimstat_boxes::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_boxes::chart_title(__('Human Visits', 'wp-slimstat-view')));
			wp_slimstat_boxes::show_chart('slim_p2_01', wp_slimstat_db::extract_data_for_chart('COUNT(DISTINCT t1.visit_id)', 'COUNT(DISTINCT t1.ip)', 'AND (tb.type = 0 OR tb.type = 2) AND t1.visit_id <> 0'), array(__('Visits','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_02': 
			wp_slimstat_boxes::box_header('slim_p2_02', '', '', false, 'noscroll');
			wp_slimstat_boxes::show_visitors_summary('slim_p2_02', $total_human_hits, $total_human_visits);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_03':
			wp_slimstat_boxes::box_header('slim_p2_03', htmlspecialchars(__('This report shows you what languages your users have installed on their computers.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_03', 'language', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_04':
			wp_slimstat_boxes::box_header('slim_p2_04', htmlspecialchars(__('A user agent is a generic term for any program used for accessing a website. This includes browsers (such as Chrome), robots and spiders, and any other software program that retrieves information from a website.<br><br>You can ignore any given user agent by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_04', 'browser', array('total_for_percentage' => $current_pageviews, 'more_columns' => ',tb.version'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_05':
			wp_slimstat_boxes::box_header('slim_p2_05', htmlspecialchars(__('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_05', 'ip', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_06':
			wp_slimstat_boxes::box_header('slim_p2_06', htmlspecialchars(__('Which operating systems do your visitors use? Optimizing your site for the appropriate technical capabilities helps make your site more engaging and usable and can result in higher conversion rates and more sales.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_06', 'platform', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_07':
			wp_slimstat_boxes::box_header('slim_p2_07', htmlspecialchars(__('This report shows the most common screen resolutions used by your visitors. Knowing the most popular screen resolution of your visitors will help you create content optimized for that resolution or you may opt for resolution-independence.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_07', 'resolution', array('total_for_percentage' => wp_slimstat_db::count_records('tss.resolution <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_09':
			wp_slimstat_boxes::box_header('slim_p2_09', htmlspecialchars(__("Which versions of Flash do your visitors have installed? Is Java supported on your visitors' platforms?",'wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_plugins('slim_p2_09', $total_human_hits);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_10':
			wp_slimstat_boxes::box_header('slim_p2_10', htmlspecialchars(__('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p2_10', 'country', array('total_for_percentage' => $current_pageviews));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_12':
			wp_slimstat_boxes::box_header('slim_p2_12');
			wp_slimstat_boxes::show_visit_duration('slim_p2_12', $total_human_visits);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_13':
			wp_slimstat_boxes::box_header('slim_p2_13', htmlspecialchars(__('You can ignore any specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p2_13', 'country');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_14':
			wp_slimstat_boxes::box_header('slim_p2_14', htmlspecialchars(__('This report shows the most recent screen resolutions used by your visitors. Knowing the most popular screen resolution of your visitors will help you create content optimized for that resolution or you may opt for resolution-independence.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p2_14', 'resolution');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_15':
			wp_slimstat_boxes::box_header('slim_p2_15', htmlspecialchars(__('Which operating systems do your visitors use? Optimizing your site for the appropriate technical capabilities helps make your site more engaging and usable and can result in higher conversion rates and more sales.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p2_15', 'platform');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_16':
			wp_slimstat_boxes::box_header('slim_p2_16', htmlspecialchars(__('A user agent is a generic term for any program used for accessing a website. This includes browsers (such as Chrome), robots and spiders, and any other software program that retrieves information from a website.<br><br>You can ignore any given user agent by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p2_16', 'browser');
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p2_17':
			wp_slimstat_boxes::box_header('slim_p2_17', htmlspecialchars(__('This report shows you what languages your users have installed on their computers.','wp-slimstat-view'), ENT_QUOTES), '', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p2_17', 'language');
			wp_slimstat_boxes::box_footer();
			break;
		default:
	}