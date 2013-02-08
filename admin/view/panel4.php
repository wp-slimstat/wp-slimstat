<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// This panel has a slightly different query
$sql_from_where = "
	FROM (
		SELECT t1.visit_id, count(t1.ip) count, MAX(t1.dt) dt
		FROM [from_tables]
		WHERE [where_clause]
		GROUP BY t1.visit_id
	) AS ts1";

foreach(wp_slimstat_boxes::$all_boxes as $a_box_id)
	switch($a_box_id){
		case 'slim_p4_01':
			wp_slimstat_boxes::box_header('slim_p4_01', wp_slimstat_boxes::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_boxes::chart_title(__('Average Pageviews per Visit', 'wp-slimstat-view')));
			wp_slimstat_boxes::show_chart('slim_p4_01', wp_slimstat_db::extract_data_for_chart('ROUND(AVG(ts1.count),2)', 'MAX(ts1.count)', 'AND t1.visit_id > 0', $sql_from_where), array(__('Avg Pageviews','wp-slimstat-view'), __('Longest visit','wp-slimstat-view')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_02':
			wp_slimstat_boxes::box_header('slim_p4_02', htmlspecialchars(__("This report lists the most recent posts viewed on your site, by title.",'wp-slimstat-view'), ENT_QUOTES), 'medium');
			wp_slimstat_boxes::show_results('recent', 'slim_p4_02', 'resource', array('custom_where' => 'tci.content_type = "post"'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_03':
			wp_slimstat_boxes::box_header('slim_p4_03', htmlspecialchars(__('A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.','wp-slimstat-view'), ENT_QUOTES), 'medium');
			wp_slimstat_boxes::show_results('recent', 'slim_p4_03', 'resource', array('having_clause' => 'HAVING COUNT(visit_id) = 1'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_04':
			wp_slimstat_boxes::box_header('slim_p4_04', '', 'medium');
			wp_slimstat_boxes::show_results('recent', 'slim_p4_04', 'resource', array('custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_05':
			wp_slimstat_boxes::box_header('slim_p4_05', htmlspecialchars(__('The 404 or Not Found error message is a HTTP standard response code indicating that the client was able to communicate with the server, but the server could not find what was requested.<br><br>This report can be useful to detect attack attempts, by looking at patterns in 404 URLs.','wp-slimstat-view'), ENT_QUOTES));
			wp_slimstat_boxes::show_results('recent', 'slim_p4_05', 'resource', array('custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_06':
			wp_slimstat_boxes::box_header('slim_p4_06', htmlspecialchars(__("Searches performed using Wordpress' built-in search functionality.",'wp-slimstat-view'), ENT_QUOTES));
			wp_slimstat_boxes::show_results('recent', 'slim_p4_06', 'searchterms', array('custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_07':
			wp_slimstat_boxes::box_header('slim_p4_07', htmlspecialchars(__("Categories provide a helpful way to group related posts together, and to quickly tell readers what a post is about. Categories also make it easier for people to find your content.",'wp-slimstat-view'), ENT_QUOTES));
			wp_slimstat_boxes::show_results('popular', 'slim_p4_07', 'category', array('total_for_percentage' => wp_slimstat_db::count_records('tci.category <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_08':
			wp_slimstat_boxes::box_header('slim_p4_08', htmlspecialchars(__("<strong>Link Details</strong><br>- <em>A:n</em> means that the n-th link in the page was clicked.<br>- <em>ID:xx</em> is shown when the corresponding link has an ID attribute associated to it.",'wp-slimstat-view'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat-view'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat-view'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat-view'), ENT_QUOTES).'</p>', 'medium');
			wp_slimstat_boxes::show_spy_view('slim_p4_08', 0);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_10':
			wp_slimstat_boxes::box_header('slim_p4_10', htmlspecialchars(__("This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to leverage this functionality.",'wp-slimstat-view'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat-view'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat-view'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat-view'), ENT_QUOTES).'</p>', 'medium');
			wp_slimstat_boxes::show_spy_view('slim_p4_10', -1);
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_11':
			wp_slimstat_boxes::box_header('slim_p4_11', htmlspecialchars(__("This report lists the most popular posts on your site, by title.",'wp-slimstat-view'), ENT_QUOTES), 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_11', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('tci.content_type = "post"'), 'custom_where' => 'tci.content_type = "post"'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_12':
			wp_slimstat_boxes::box_header('slim_p4_12', '', 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_12', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'), 'custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_13':
			wp_slimstat_boxes::box_header('slim_p4_13', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_13', 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'), 'custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_14':
			wp_slimstat_boxes::box_header('slim_p4_14', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_14', 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_15':
			wp_slimstat_boxes::box_header('slim_p4_15', '', 'medium', true);
			wp_slimstat_boxes::show_results('recent', 'slim_p4_15', 'resource', array('custom_where' => '(tci.content_type = "category" OR tci.content_type = "tag")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_16':
			wp_slimstat_boxes::box_header('slim_p4_16', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_16', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'), 'custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_17':
			wp_slimstat_boxes::box_header('slim_p4_17', '', 'medium', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_17', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('t1.domain <> ""'), 'custom_where' => 't1.domain <> ""'));
			wp_slimstat_boxes::box_footer();
			break;
		case 'slim_p4_18':
			wp_slimstat_boxes::box_header('slim_p4_18', '', '', true);
			wp_slimstat_boxes::show_results('popular', 'slim_p4_18', 'author', array('total_for_percentage' => wp_slimstat_db::count_records('tci.author <> ""')));
			wp_slimstat_boxes::box_footer();
			break;
		default:
			break;
	}