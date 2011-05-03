<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Parameters for the SQL query: orderby column, direction (asc, desc)
$orderby_column = 'dt';
$allowed_columns = array('ip', 'language', 'country', 'domain', 'searchterms', 'resource', 'browser', 'platform', 'plugins', 'resolution', 'dt');
if (!empty($_GET['orderby']) && in_array($_GET['orderby'], $allowed_columns)) $orderby_column = $_GET['orderby'];

// Results from the database
if (!empty($_GET['starting'])) $wp_slimstat_view->starting_from = intval($_GET['starting']);

// Restrict results to current month only ( cmo = current month only )
if (!empty($_GET['cmo']) && $_GET['cmo'] == 1) $wp_slimstat_view->custom_data_filter = true;

// Retrieve results
$wp_slimstat_view->limit_results = 50;
if (empty($function_to_use)) $function_to_use = '';
switch ($function_to_use){
	case 'get_details_recent_visits':
		$results = $wp_slimstat_view->get_recent('t1.id', 't1.ip, t1.user, t1.language, t1.resource, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser, tb.platform', 't1.visit_id > 0', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records('t1.visit_id > 0');
		$add_to_box_title = __('Spy View', 'wp-slimstat-view');
		break;
	case 'get_recent_404':
		$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user, t1.language, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser, tb.platform', "t1.resource LIKE '[404]%'", 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.resource LIKE '[404]%'", "DISTINCT t1.resource");
		$add_to_box_title = __('Recent 404 pages', 'wp-slimstat-view');
		break;
	case 'get_recent_bouncing_pages':
		$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user, t1.language, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser, tb.platform', '', 'browsers', 'HAVING COUNT(visit_id) = 1');
		$count_raw_data = $wp_slimstat_view->count_bouncing_pages();
		$add_to_box_title = __('Recent bouncing pages', 'wp-slimstat-view');
		break;
	case 'get_recent_countries':
		$results = $wp_slimstat_view->get_recent('t1.country', 't1.ip, t1.user, t1.language, t1.searchterms, t1.visit_id, t1.resource, t1.domain, t1.referer, tb.browser, tb.platform', "t1.country <> '' AND t1.country <> 'xx'", 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.country <> '' AND t1.country <> 'xx'", "DISTINCT t1.country");
		$add_to_box_title = __( 'Recent Countries', 'wp-slimstat-view' );
		break;
	case 'get_recent_searchterms':
		$results = $wp_slimstat_view->get_recent('t1.searchterms', 't1.ip, t1.user, t1.language, t1.country, t1.visit_id, t1.resource, t1.domain, t1.referer, tb.browser, tb.platform', '', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.searchterms <> ''", "DISTINCT t1.searchterms");
		$add_to_box_title = __('Recent Keywords', 'wp-slimstat-view');
		break;
	case 'get_top_resources':
		$results = $wp_slimstat_view->get_top('t1.resource', 't1.ip, t1.user, t1.language, t1.country, t1.visit_id, t1.searchterms, t1.domain, t1.referer, tb.browser, tb.platform', '', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.resource <> ''", 'DISTINCT t1.resource');
		$add_to_box_title = __('Top Resources', 'wp-slimstat-view');
		$orderby_column = 'count';
		break;
	case 'get_top_searchterms':
		$results = $wp_slimstat_view->get_top('t1.searchterms', 't1.ip, t1.user, t1.language, t1.country, t1.visit_id, t1.resource, t1.domain, t1.referer, tb.browser, tb.platform', '', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.searchterms <> ''", 'DISTINCT t1.searchterms');
		$add_to_box_title = __('Top Keywords', 'wp-slimstat-view');
		$orderby_column = 'count';
		break;
	case 'get_top_traffic_sources':
		$results = $wp_slimstat_view->get_top('t1.domain', 't1.ip, t1.user, t1.language, t1.country, t1.visit_id, t1.resource, t1.searchterms, t1.referer, tb.browser, tb.platform', '', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.domain <> ''", 'DISTINCT t1.domain');
		$add_to_box_title = __('Top Traffic Sources', 'wp-slimstat-view');
		$orderby_column = 'count';
		break;
	default:
		$results = $wp_slimstat_view->get_recent('t1.resource', 't1.ip, t1.user, t1.language, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser, tb.platform', '', 'browsers');
		$count_raw_data = $wp_slimstat_view->count_records("t1.resource <> ''", "DISTINCT t1.resource");
		$add_to_box_title = __('Recent Contents', 'wp-slimstat-view');
}
if (!empty($add_to_box_title)) $add_to_box_title .= ' - ';
?>

<form action="index.php" method="get">
	<input type="hidden" name="page" value="wp-slimstat/view/index.php">
	<input type="hidden" name="slimpanel" value="5">
	<input type='hidden' name='ftu' value='<?php echo $function_to_use; ?>'>
	
	<?php // Keep other filters persistent
		if (!empty($_GET['cmo'])) echo "<input type='hidden' name='cmo' value='yes'>";
		foreach($wp_slimstat_view->filters_parsed as $a_filter_label => $a_filter_details){
			echo "<input type='hidden' name='{$a_filter_label}' value='{$a_filter_details[0]}'>";
			echo "<input type='hidden' name='{$a_filter_label}-op' value='{$a_filter_details[1]}'>";
		}
	?>
	<p><span class="<?php echo $wp_locale->text_direction ?>">
		<?php if (empty($function_to_use)): _e('Order by','wp-slimstat-view'); ?>
		<select name="orderby">
			<option value=""><?php _e('Select filter','wp-slimstat-view') ?></option>
			<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
			<option value="country"><?php _e('Country','wp-slimstat-view') ?></option>
			<option value="dt"><?php _e('Date/Time','wp-slimstat-view') ?></option>
			<option value="domain"><?php _e('Domain','wp-slimstat-view') ?></option>
			<option value="ip"><?php _e('IP','wp-slimstat-view') ?></option>
			<option value="searchterms"><?php _e('Keywords','wp-slimstat-view') ?></option>
			<option value="language"><?php _e('Language','wp-slimstat-view') ?></option>
			<option value="platform"><?php _e('Operating System','wp-slimstat-view') ?></option>
			<option value="resource"><?php _e('Permalink','wp-slimstat-view') ?></option>
			<option value="plugins"><?php _e('Plugins','wp-slimstat-view') ?></option>
			<option value="resolution"><?php _e('Screen Resolution','wp-slimstat-view') ?></option>
		</select> 
		<select name="direction" style="width:12em">
			<option value=""><?php _e('Select sorting','wp-slimstat-view') ?></option>
			<option value="asc"><?php _e('Ascending','wp-slimstat-view') ?></option>
			<option value="desc"><?php _e('Descending','wp-slimstat-view') ?></option>
		</select>
		<?php endif; // empty($function_to_use)
		_e('Starting from record #', 'wp-slimstat-view'); if (empty($count_raw_data)) $count_raw_data = 0; ?>
		<input type="text" name="starting" value="" size="15"> / <?php echo number_format($count_raw_data, 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator) ?>&nbsp;</span>
		<input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary"></span>
	</p>
</form>

<div class="postbox tall <?php echo $wp_locale->text_direction ?>">
	<div class="more"><?php 
$count_results = count($results);
$ending_point = min($count_raw_data, $wp_slimstat_view->starting_from+50);
if ($wp_slimstat_view->starting_from > 0){
	$new_starting = ($wp_slimstat_view->starting_from > 50)?$wp_slimstat_view->starting_from-50:0;
	echo "<a href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5$filters_query&amp;starting=$new_starting&amp;orderby=$orderby_column&amp;direction=$wp_slimstat_view->direction&amp;ftu=$function_to_use&amp;cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('&laquo; Previous','wp-slimstat-view')."</a> ";
}
if ($ending_point < $count_raw_data && $count_results > 0){
	$new_starting = $wp_slimstat_view->starting_from + 50;
	echo "<a href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5$filters_query&amp;starting=$new_starting&amp;orderby=$orderby_column&amp;direction=$wp_slimstat_view->direction&amp;ftu=$function_to_use&amp;cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('Next &raquo;','wp-slimstat-view')."</a> ";
} ?></div>
<h3><?php
if ($count_results == 0)
	_e('No records found', 'wp-slimstat-view');
else {
	$reverse_orderby = ($wp_slimstat_view->direction == 'ASC')?'DESC':'ASC';
	$invert_direction_link = "<a href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5$filters_query&amp;starting=$wp_slimstat_view->starting_from&amp;orderby=$orderby_column&amp;direction=$reverse_orderby&amp;ftu=$function_to_use&amp;cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('reverse','wp-slimstat-view')."</a>";
	echo $add_to_box_title.'  '.sprintf(__('Records: %d - %d. Order by: %s %s (%s)', 'wp-slimstat-view'), $wp_slimstat_view->starting_from, $ending_point, $orderby_column, $wp_slimstat_view->direction, $invert_direction_link); 
} ?></h3><div class="container"><?php
				
if ($count_results == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';

$visit_id = -1;
for($i=0;$i<$count_results;$i++){
	$results[$i]['ip'] = long2ip($results[$i]['ip']);
	$results[$i]['dt'] = date_i18n($wp_slimstat_view->date_time_format, $results[$i]['dt']);

	if ($visit_id != $results[$i]['visit_id'] || $results[$i]['visit_id'] == 0){
		
		if (empty($results[$i]['user']))
			$ip_address = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5$filters_query&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$results[$i]['ip']}</a>";
		else
			$ip_address = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&amp;slimpanel=5$filters_query&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
		$ip_address = "<a href='http://www.infosniper.net/index.php?ip_address={$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/whois.gif' /></a> $ip_address";
		$country = __('c-'.$results[$i]['country'],'countries-languages');
		$language = __('l-'.$results[$i]['language'], 'countries-languages');
		$platform = __($results[$i]['platform'],'countries-languages');

		echo "<p class='header'>$ip_address <span class='widecolumn'>$platform</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>$country</span> <span class='widecolumn'>$language</span> <span class='widecolumn'>{$results[$i]['dt']}</span></p>";
		$visit_id = $results[$i]['visit_id'];
	}
	$last_element = ($i == $count_results-1)?' class="last"':'';
	$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
	echo "<p$last_element>";
	switch ($function_to_use){
		case 'get_details_recent_visits':
			if (!empty($results[$i]['domain']))
				echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
			else
				echo __('Direct visit to','wp-slimstat-view');
			if (empty($results[$i]['resource'])){
				$searchterms = trim_value($results[$i]['searchterms'], 70);
				$results[$i]['resource'] = __('Search results page for','wp-slimstat-view')."<strong>{$searchterms['text']}</strong>";
			}
			echo ' '.substr($results[$i]['resource'], 0, 70);
			break;
		case 'get_recent_searchterms':
			if (empty($results[$i]['resource'])) $results[$i]['resource'] = __('Local search results page','wp-slimstat-view');
			$resource = trim_value($results[$i]['resource'], 70);
			$searchterms = trim_value($results[$i]['searchterms'], 70);			
			echo "{$searchterms['text']} &raquo; {$resource['text']}";
			break;
		case 'get_top_resources':
			$count_top = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$percentage = number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_raw_data)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$resource = trim_value($results[$i]['resource'], 120);
			echo "<span class='element-title'{$resource['tooltip']}>{$resource['text']}</span><span>$percentage%</span><span>$count_top</span>";
			break;
		case 'get_top_searchterms':
			$count_top = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$percentage = number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_raw_data)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$searchterms = trim_value($results[$i]['searchterms'], 120);
			echo "<span class='element-title'{$searchterms['tooltip']}>{$searchterms['text']}</span><span>$percentage%</span><span>$count_top</span>";
			break;
		case 'get_top_traffic_sources':
			$count_top = number_format($results[$i]['count'], 0, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$percentage = number_format(sprintf("%01.2f", (100*$results[$i]['count']/$count_raw_data)), 2, $wp_slimstat_view->decimal_separator, $wp_slimstat_view->thousand_separator);
			$domain = trim_value($results[$i]['domain'], 120);
			echo "<span class='element-title'{$domain['tooltip']}>{$domain['text']}</span><span>$percentage%</span><span>$count_top</span>";
			break;
		default:
			if (empty($results[$i]['resource'])) $results[$i]['resource'] = __('Local search results page','wp-slimstat-view');
			$resource = trim_value($results[$i]['resource'], 120);
			$searchterms = trim_value($results[$i]['searchterms'], 70);
			$domain = trim_value($results[$i]['domain'], 50);
			$referer = trim_value($results[$i]['referer'], 200);
			$url_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['domain'].$referer['text']);
			$domain_span = !empty($results[$i]['domain'])?"<span><a title='$url_title' href='http://{$results[$i]['domain']}{$referer['text']}'><img src='$wp_slimstat_view->plugin_url/wp-slimstat/images/url.gif' /></a> {$domain['text']}</span>":'';
			echo "<span class='element-title'{$resource['tooltip']}>{$resource['text']}</span><span><strong>{$searchterms['text']}</strong></span>$domain_span";
	}
	echo '</p>';
} ?>
	</div>
</div>