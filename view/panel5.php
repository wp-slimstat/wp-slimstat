<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Parameters for the SQL query: orderby column, direction (asc, desc), starting point
$orderby_column = 'dt';
$allowed_columns = array('ip', 'language', 'country', 'domain', 'searchterms', 'resource', 'browser', 'platform', 'plugins', 'resolution', 'dt');
if (!empty($_GET['orderby']) && in_array($_GET['orderby'], $allowed_columns)) $orderby_column = $_GET['orderby'];

$direction_orderby = 'desc';
if (!empty($_GET['direction']) && $_GET['direction'] == 'asc') $direction_orderby = 'asc';

$starting_point = 0;
if (!empty($_GET['starting'])) $starting_point = intval($_GET['starting']);

?>

<form action="index.php" method="get">
	<input type="hidden" name="page" value="wp-slimstat/view/index.php">
	<input type="hidden" name="slimpanel" value="5">
	<?php // Keep other filters persistent
		foreach($filters_parsed as $a_filter_label => $a_filter_details){
			echo "<input type='hidden' name='{$a_filter_label}' value='{$a_filter_details[0]}'>";
			echo "<input type='hidden' name='{$a_filter_label}-op' value='{$a_filter_details[1]}'>";
		}
	?>
	<p><span class="<?php echo $wp_locale->text_direction ?>"><?php _e('Order by','wp-slimstat-view') ?>
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
		<?php _e('Starting from record #', 'wp-slimstat-view'); ?>
		<input type="text" name="starting" value="" size="15"> / <?php echo $count_raw_data = $wp_slimstat_view->count_raw_data() ?>&nbsp;</span>
		<input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary"></span>
	</p>
</form>

<div class="metabox-holder tall <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php 
			$results = $wp_slimstat_view->get_raw_data($orderby_column, $direction_orderby, $starting_point);
			$count_results = count($results); // 0 if $results is null
			$ending_point = min($count_raw_data, $starting_point+50);
			if ($starting_point > 0){
				$new_starting = ($starting_point > 50)?$starting_point-50:0;
				echo "<a href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&starting=$new_starting'>".__('&laquo; Previous','wp-slimstat-view')."</a> ";
			}
			if ($ending_point < $count_raw_data){
				$new_starting = $starting_point + 50;
				echo "<a href='index.php?page=wp-slimstat/view/index.php&slimpanel=5$filters_query&starting=$new_starting'>".__('Next &raquo;','wp-slimstat-view')."</a> ";
			} ?></div>
		<h3><?php
			if ($count_results == 0) {
				_e('No records found', 'wp-slimstat-view');
			}
			else {
				
				echo sprintf(__('Records: %d - %d. Order by: %s %s', 'wp-slimstat-view'), $starting_point, $ending_point, $orderby_column, $direction_orderby); 
			}
		?></h3>
		<div class="container">
			<?php
				
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$language = __('l-'.$results[$i]['language'], 'countries-languages');
						$country = __('c-'.$results[$i]['country'],'countries-languages');
						$platform = __($results[$i]['platform'],'countries-languages');
						$searchterms = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
						$ip_address = "<a href='http://www.ip2location.com/{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/whois.gif' /></a> {$results[$i]['ip']}";
						echo "<p class='header'><span class='element-title'>$ip_address</span> <span>$language</span> <span>$country</span> <span>{$results[$i]['domain']}</span> <span>$searchterms</span> <span>{$results[$i]['resource']}</span></p>";
						echo "<p$last_element><span class='element-title'>{$results[$i]['browser']}</span> <span>{$results[$i]['datetime']}</span> <span>$platform</span> <span>{$results[$i]['plugins']}</span> <span>{$results[$i]['resolution']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>