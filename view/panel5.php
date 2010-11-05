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

$wp_slimstat_view->starting_from = 0;
if (!empty($_GET['starting'])) $wp_slimstat_view->starting_from = intval($_GET['starting']);

// Restrict results to current month only ( cmo = current month only )
if (!empty($_GET['cmo']) && $_GET['cmo'] == 1) $wp_slimstat_view->custom_data_filter = true;

// Show details based on a different function
$allowed_functions = array(
	'get_details_recent_visits',
	'get_recent_404',
	'get_recent_bouncing_pages',
	'get_recent_browsers',
	'get_recent_countries',
	'get_recent_resources',
	'get_recent_searchterms',
	'get_top_resources',
	'get_top_searchterms'
);
if (!empty($_GET['ftu']) && in_array($_GET['ftu'], $allowed_functions)) $function_to_use = $_GET['ftu'];

// Retrieve results
$wp_slimstat_view->limit_results = 50;
if (empty($function_to_use)) $function_to_use = '';
switch ($function_to_use){
	case 'get_details_recent_visits':
		$results = $wp_slimstat_view->get_details_recent_visits();
		$count_raw_data = $wp_slimstat_view->count_details_recent_visits();
		$add_to_box_title = __( 'Details about Recent Visits', 'wp-slimstat-view' );
		break;
	case 'get_recent_404':
		$results = $wp_slimstat_view->get_recent_404_pages();
		$count_raw_data = $wp_slimstat_view->count_recent_404_pages();
		$add_to_box_title = __( 'Recent 404 pages', 'wp-slimstat-view' );
		break;
	case 'get_recent_bouncing_pages':
		$results = $wp_slimstat_view->get_recent_bouncing_pages();
		$count_raw_data = $wp_slimstat_view->count_recent_bouncing_pages();
		$add_to_box_title = __( 'Recent bouncing pages', 'wp-slimstat-view' );
		break;
	case 'get_recent_browsers':
		$results = $wp_slimstat_view->get_recent_browsers();
		$count_raw_data = $wp_slimstat_view->count_recent_browsers();
		$add_to_box_title = __( 'Recent Browsers', 'wp-slimstat-view' );
		break;
	case 'get_recent_countries':
		$results = $wp_slimstat_view->get_recent('country');
		$count_raw_data = $wp_slimstat_view->count_recent('country');
		$add_to_box_title = __( 'Recent Countries', 'wp-slimstat-view' );
		break;
	case 'get_recent_resources':
		$results = $wp_slimstat_view->get_recent('resource');
		$count_raw_data = $wp_slimstat_view->count_recent('resource');
		$add_to_box_title = __( 'Recent Contents', 'wp-slimstat-view' );
		break;
	case 'get_recent_searchterms':
		$results = $wp_slimstat_view->get_recent('searchterms');
		$count_raw_data = $wp_slimstat_view->count_recent('searchterms');
		$add_to_box_title = __( 'Recent Keywords', 'wp-slimstat-view' );
		break;
	case 'get_top_resources':
		$results = $wp_slimstat_view->get_top('resource');
		$count_raw_data = $wp_slimstat_view->count_top('resource');
		$add_to_box_title = __( 'Top Resources', 'wp-slimstat-view' );
		$orderby_column = 'count';
		break;
	case 'get_top_searchterms':
		$results = $wp_slimstat_view->get_top('searchterms');
		$count_raw_data = $wp_slimstat_view->count_top('searchterms');
		$add_to_box_title = __( 'Top Keywords', 'wp-slimstat-view' );
		$orderby_column = 'count';
		break;
	default:
		$results = $wp_slimstat_view->get_raw_data($orderby_column);
		$count_raw_data = $wp_slimstat_view->count_raw_data();
		$add_to_box_title = '';
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
		<input type="text" name="starting" value="" size="15"> / <?php echo $count_raw_data ?>&nbsp;</span>
		<input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary"></span>
	</p>
</form>

<div class="metabox-holder tall <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php 
			$count_results = count($results); // 0 if $results is null
			$ending_point = min($count_raw_data, $wp_slimstat_view->starting_from+50);
			if ($wp_slimstat_view->starting_from > 0){
				$new_starting = ($wp_slimstat_view->starting_from > 50)?$wp_slimstat_view->starting_from-50:0;
				echo "<a href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&starting=$new_starting&orderby=$orderby_column&direction=$wp_slimstat_view->direction&ftu=$function_to_use&cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('&laquo; Previous','wp-slimstat-view')."</a> ";
			}
			if ($ending_point < $count_raw_data && $count_results > 0){
				$new_starting = $wp_slimstat_view->starting_from + 50;
				echo "<a href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&starting=$new_starting&orderby=$orderby_column&direction=$wp_slimstat_view->direction&ftu=$function_to_use&cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('Next &raquo;','wp-slimstat-view')."</a> ";
			} ?></div>
		<h3><?php
			if ($count_results == 0) {
				_e('No records found', 'wp-slimstat-view');
			}
			else {
				$reverse_orderby = ($wp_slimstat_view->direction == 'ASC')?'DESC':'ASC';
				$invert_direction_link = "<a href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&starting=$wp_slimstat_view->starting_from&orderby=$orderby_column&direction=$reverse_orderby&ftu=$function_to_use&cmo=".intval($wp_slimstat_view->custom_data_filter)."'>".__('reverse','wp-slimstat-view')."</a>";
				echo $add_to_box_title.'  '.sprintf(__('Records: %d - %d. Order by: %s %s (%s)', 'wp-slimstat-view'), $wp_slimstat_view->starting_from, $ending_point, $orderby_column, $wp_slimstat_view->direction, $invert_direction_link); 
			}
		?></h3>
		<div class="container">
			<?php
				
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					switch ($function_to_use){
						case 'get_details_recent_visits':
							$visit_id = 0;
							for($i=0;$i<$count_results;$i++){
								if ($visit_id != $results[$i]['visit_id']){
									$ip_address = "<a href='http://www.ip2location.com/{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$slimstat_plugin_url/wp-slimstat/images/whois.gif' /></a> {$results[$i]['ip']}";
									$country = __('c-'.$results[$i]['country'],'countries-languages');
									$language = __('l-'.$results[$i]['language'], 'countries-languages');
									$platform = __($results[$i]['platform'],'countries-languages');

									echo "<p class='header'>$ip_address <span class='widecolumn'>$platform</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>$country</span> <span class='widecolumn'>$language</span> <span class='widecolumn'>{$results[$i]['customdatetime']}</span></p>";
									$visit_id = $results[$i]['visit_id'];
								}
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
								echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
								if (!empty($results[$i]['domain'])){
									echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
								}
								else{
									echo __('Direct visit to','wp-slimstat-view');
								}
								echo ' '.substr($results[$i]['resource'],0,40).'</p>';				
							}
							break;
						case 'get_recent_404':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$language = __('l-'.$results[$i]['language'], 'countries-languages');
								$country = __('c-'.$results[$i]['country'],'countries-languages');
								$title_domain = (strlen($results[$i]['domain']) > 25)?" title='{$results[$i]['domain']}'":'';
								$resource_short = $results[$i]['short_string'];
								$clean_long_string = urlencode($results[$i]['resource']);
								$title_resource = '';
								if (strlen($results[$i]['resource']) > 40){
									$resource_short .= '...';
									$title_resource = " title='{$results[$i]['resource']}'";
								}
								if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $resource_short = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&resource=$clean_long_string'>$resource_short</a>";

								echo "<p$last_element$title_resource>$resource_short <span>{$results[$i]['customdatetime']}</span> <span>$country</span> <span>$language</span> <span$title_domain>{$results[$i]['domain_short']}</span> <span>{$results[$i]['ip']}</span></p>";
							}
							break;
						case 'get_recent_bouncing_pages':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$resource_short = $results[$i]['resource'];
								$clean_long_string = urlencode($results[$i]['resource']);
								if (strlen($results[$i]['resource']) > 50){
									$title_resource = " title='{$results[$i]['resource']}'";
									$resource_short = substr($results[$i]['resource'], 0, 50).'...';
								}
								$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
								$element_url = get_bloginfo('url').preg_replace('/\[.*\]/','', $results[$i]['resource']);
								$resource_short = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&resource=$clean_long_string'>$resource_short</a>";
								$title_domain = (strlen($results[$i]['domain']) > 35)?" title='{$results[$i]['domain']}'":'';
								
								echo "<p$last_element$show_title_tooltip>";
								if (strpos($results[$i]['resource'], '[404]') === false){
									echo "<a target='_blank' title='$element_title'";
									echo " href='$element_url'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
								}
								echo $resource_short." <span>{$results[$i]['customdatetime']}</span> <span$title_domain>{$results[$i]['domain_short']}</span></p>";
							}
							break;
						case 'get_recent_browsers':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$country = __('c-'.$results[$i]['country'],'countries-languages');
								$clean_long_string = urlencode($results[$i]['browser']);
								$browser = $results[$i]['browser'];
								if (!isset($wp_slimstat_view->filters_parsed['browser'][0])) $browser = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&browser=$clean_long_string'>$browser</a>";	
								$resource_short = $results[$i]['resource'];
								$title_resource = '';
								if (strlen($results[$i]['resource']) > 30){
									$title_resource = " title='{$results[$i]['resource']}'";
									$resource_short = substr($results[$i]['resource'], 0, 30);
								}
								$results[$i]['browser'] = $results[$i]['browser'] . (!empty($results[$i]['version'])?' '.$results[$i]['version']:'');

								echo "<p$last_element>{$results[$i]['browser']} <span>{$results[$i]['customdatetime']}</span> <span>$country</span> <span>CSS {$results[$i]['css_version']}</span> <span$title_resource>$resource_short</span></p>";
							}
							break;
						case 'get_recent_countries':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$country = __('c-'.$results[$i]['short_string'],'countries-languages');
								$searchterms = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
								$clean_long_string = urlencode($results[$i]['short_string']);
								if (empty($searchterms)) $searchterms = __('N/A', 'wp-slimstat-view');
								if (!isset($wp_slimstat_view->filters_parsed['country'][0])) $country = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&country=$clean_long_string'>$country</a>";	
								$resource_short = $results[$i]['resource'];
								$title_resource = '';
								if (strlen($results[$i]['resource']) > 50){
									$title_resource = " title='{$results[$i]['resource']}'";
									$resource_short = substr($results[$i]['resource'], 0, 50);
								}

								echo "<p$last_element>$country <span>{$results[$i]['customdatetime']}</span> <span>$searchterms</span> <span$title_resource>$resource_short</span></p>";
							}
							break;
						case 'get_recent_resources':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$country = __('c-'.$results[$i]['country'],'countries-languages');
								$searchterms = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
								$clean_long_string = urlencode($results[$i]['resource']);
								$resource_short = $results[$i]['resource'];
								$title_resource = '';
								if (strlen($results[$i]['resource']) > 50){
									$title_resource = " title='{$results[$i]['resource']}'";
									$resource_short = substr($results[$i]['resource'], 0, 50);
								}
								if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $resource_short = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&resource=$clean_long_string'>$resource_short</a>";	
								echo "<p$last_element$title_resource>$resource_short <span>{$results[$i]['customdatetime']}</span> <span>$country</span> <span>$searchterms</span></p>";
							}
							break;
						case 'get_recent_searchterms':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$country = __('c-'.$results[$i]['country'],'countries-languages');
								$searchterms = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
								$clean_long_string = urlencode($results[$i]['searchterms']);
								if (empty($searchterms)) $searchterms = __('N/A', 'wp-slimstat-view');
								if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $searchterms = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&searchterms=$clean_long_string'>$searchterms</a>";	
								$resource_short = $results[$i]['resource'];
								$title_resource = '';
								if (strlen($results[$i]['resource']) > 50){
									$title_resource = " title='{$results[$i]['resource']}'";
									$resource_short = substr($results[$i]['resource'], 0, 50);
								}

								echo "<p$last_element>$searchterms <span>{$results[$i]['customdatetime']}</span> <span>$country</span> <span$title_resource>$resource_short</span></p>";
							}
							break;
						case 'get_top_resources':
						case 'get_top_searchterms':
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$long_string = str_replace('\\', '', htmlspecialchars($results[$i]['long_string']));
								$clean_long_string = urlencode($results[$i]['long_string']);
								if (empty($long_string)) $long_string = __('N/A', 'wp-slimstat-view');

								echo "<p$last_element>$long_string <span>{$results[$i]['count']}</span></p>";
							}
							break;
						
						default:
							echo '<p class="header"><span class="element-title">'.__('IP','wp-slimstat-view').'</span> <span>'.__('Date and time','wp-slimstat-view').'</span> <span>'.__('Referer','wp-slimstat-view').'</span> <span>'.__('Keywords','wp-slimstat-view').'</span> <span>'.__('Permalink','wp-slimstat-view').'</span></p>';
							echo '<p><span class="element-title">'.__('Browser','wp-slimstat-view').'</span> <span>'.__('Language','wp-slimstat-view').'</span> <span>'.__('Country','wp-slimstat-view').'</span> <span>'.__('Operating System','wp-slimstat-view').'</span> <span>'.__('Screen Resolution','wp-slimstat-view').'</span></p>';
							for($i=0;$i<$count_results;$i++){
								$last_element = ($i == $count_results-1)?' class="last"':'';
								$language = __('l-'.$results[$i]['language'], 'countries-languages');
								$country = __('c-'.$results[$i]['country'],'countries-languages');
								$platform = __($results[$i]['platform'],'countries-languages');
								$searchterms = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
								if (empty($searchterms)) $searchterms = __('N/A', 'wp-slimstat-view');
								if (empty($results[$i]['domain_short'])) $results[$i]['domain_short'] = __('Direct visit', 'wp-slimstat-view');
								if (empty($results[$i]['resolution'])) $results[$i]['resolution'] = __('N/A', 'wp-slimstat-view');
								if (empty($results[$i]['platform'])) $results[$i]['platform'] = __('N/A', 'wp-slimstat-view');
								$results[$i]['browser'] = $results[$i]['browser'] . (!empty($results[$i]['version'])?' '.$results[$i]['version']:'');
								$title_domain = (strlen($results[$i]['domain']) > 35)?" title='{$results[$i]['domain']}'":'';
								$ip_address = "<a href='http://www.ip2location.com/{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$slimstat_plugin_url/wp-slimstat/images/whois.gif' /></a> {$results[$i]['ip']}";

								echo "<p class='header'><span class='element-title'>$ip_address</span> <span>{$results[$i]['customdatetime']}</span> <span$title_domain>{$results[$i]['domain_short']}</span> <span>$searchterms</span> <span>{$results[$i]['resource']}</span></p>";
								echo "<p$last_element><span class='element-title'>{$results[$i]['browser']}</span> <span>$language</span> <span>$country</span> <span>$platform</span> <span>{$results[$i]['resolution']}</span></p>";
							}
					}
				}
			?>
		</div>
	</div>
</div>