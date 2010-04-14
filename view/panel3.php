<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Let's extend the main class with the methods we use in this panel
class wp_slimstat_panel extends wp_slimstat_view {
	
	public function get_traffic_sources_by_day(){
		global $wpdb;
	
		$sql = "SELECT YEAR(FROM_UNIXTIME(`dt`)) y, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) count_sources
				FROM `$this->table_stats`
				WHERE ((YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}) 
					OR (YEAR(FROM_UNIXTIME(`dt`)) = {$this->previous_month['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->previous_month['m']}))
					AND `domain` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}'
				GROUP BY YEAR(FROM_UNIXTIME(`dt`)), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m'), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d')
				ORDER BY d,m ASC";
		
		$array_results = $wpdb->get_results($sql, ARRAY_A);
	
		$array_current_month = $array_previous_month = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
		$current_month_xml = $previous_month_xml = '';
		
		// Let's reorganize the result
		if (is_array($array_results)){
			foreach($array_results as $a_result) {

				if ($a_result['m'] == $this->current_date['m']) {
					$array_current_month[intval($a_result['d'])] = $a_result['count_sources'];
					if ($a_result['count_sources'] > 0) $current_non_zero_count++;
				}
				else {
					$array_previous_month[intval($a_result['d'])] = $a_result['count_sources'];			
					if ($a_result['count_sources'] > 0) $previous_non_zero_count++;
				}
			}

			// Let's generate the XML for the flash chart
			for($i=1;$i<32;$i++) { // a month can have 31 days at maximum
				$categories_xml .= "<category name='$i'/>";
				if ($i <= $this->current_date['d']) {
					$current_month_xml .= "<set value='".intval($array_current_month[$i])."' link='/'/>";
				}
				$previous_month_xml .= "<set value='".intval($array_previous_month[$i])."' alpha='80' link='/'/>";
			}
		}
	
		$xml = "<graph canvasBorderThickness='0' canvasBorderColor='ffffff' decimalPrecision='0' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='".__('Sources','wp-slimstat-view')."'>";
		$xml .= "<categories>$categories_xml</categories>";
		$xml .= "<dataset seriesname='".__('Sources','wp-slimstat-view')." {$this->previous_month['m']}/{$this->previous_month['y']}' color='0099ff' showValue='1'>$previous_month_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Sources','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_month_xml</dataset>";
		$xml .= "</graph>";
	
		$result->xml = $xml;
		$result->visits_current_month = $array_current_month;
		$result->visits_previous_month = $array_previous_month;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;
	
		return $result;
	}
	
	public function get_top_search_engines(){
		global $wpdb;

		$sql = "SELECT `domain`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `searchterms` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}'
					AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
				GROUP BY `domain`
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_other_referers(){
		global $wpdb;

		$sql = "SELECT `domain`, `referer`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `searchterms` = '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' AND `domain` <> ''
					AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
				GROUP BY `domain`
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_keywords_pages(){
		global $wpdb;
	
		$sql = "SELECT SUBSTRING(`searchterms`, 1, 40) short_searchterms, `searchterms`, LENGTH(`searchterms`) len_searchterms,
						SUBSTRING(`resource`, 1, 40) short_resource, `resource`, LENGTH(`resource`) len_resource, 
						`domain`, `referer`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `searchterms` <> ''
				GROUP BY `searchterms`, `resource`, `domain`, `referer`
				ORDER BY `dt` DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
}

// Instantiate a new copy of the class
$wp_slimstat_view = new wp_slimstat_panel();

?>
<div class="metabox-holder wide">
	<div class="postbox">
		<h3><?php _e( 'Traffic Sources by day - Click on a day to filter reports', 'wp-slimstat-view' ); ?></h3>
		<?php $current = $wp_slimstat_view->get_traffic_sources_by_day(); ?>
		<OBJECT classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="780" height="170" id="line" >
         <param name="movie" value="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" />
         <param name="FlashVars" value="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170">
         <param name="quality" value="high" />
         <embed src="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170" quality="high" width="780" height="170" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
      </object>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Summary for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<p><span class="left"><?php _e( 'Unique Referers', 'wp-slimstat-view' ); ?></span> <span>TBD</span></p>
		<p><span class="left"><?php _e( 'Direct Visits', 'wp-slimstat-view' ); ?></span> <span>TBD</span></p>
		<p><span class="left"><?php _e( 'Search Engines', 'wp-slimstat-view' ); ?></span> <span>TBD</span></p>
		<p><span class="left"><?php _e( 'Referred', 'wp-slimstat-view' ); ?></span> <span>TBD</span></p>
		<p class="last"><span class="left"><?php _e( 'Internal', 'wp-slimstat-view' ); ?></span> <span>TBD</span></p>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Top Keywords', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('searchterms');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						// TODO: source clickable to enable filter
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['long_string'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'');
					
						echo "<p$show_title_tooltip$last_element><span class='left'>$element_text</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Countries for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('country', '', 30, true);
				$total_count = $wp_slimstat_view->get_total_count();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$country = __('c-'.$results[$i]['short_string'],'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$country</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'Traffic Sources for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('domain', 'referer', 30, true);
				$count_results = count($results); // 0 if $results is null
				$count_pageviews_with_referer = $wp_slimstat_view->get_referer_count();
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						if (strpos(get_bloginfo('url'), $results[$i]['long_string'])) continue;
				
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($count_pageviews_with_referer > 0)?intval(100*$results[$i]['count']/$count_pageviews_with_referer):0;
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
						$element_url = 'http://'.$results[$i]['long_string'].$results[$i]['referer'];
				
						// TODO: source clickable to enable filter
						echo "<p$last_element><span class='left'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $results[$i]['short_string']."</span> <span>$percentage%</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Search Engines for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_search_engines();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {		
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$search_engine_domain = str_replace('www.','', $results[$i]['domain']);
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$search_engine_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Sites for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y'];?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_other_referers();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$search_engine_domain = str_replace('www.','', $results[$i]['domain']);
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$search_engine_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'Recent Keywords &raquo; Pages', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_recent_keywords_pages();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {		
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_title = __('Open referer in a new window','wp-slimstat-view');
						$trimmed_searchterms = $results[$i]['short_searchterms'].(($results[$i]['len_searchterms'] > 40)?'...':'');
						$show_searchterms_tooltip = ($results[$i]['len_searchterms'] > 40)?" title='{$results[$i]['searchterms']}'":'';
						$trimmed_resource = $results[$i]['short_resource'].(($results[$i]['len_resource'] > 40)?'...':'');
						$show_resource_tooltip = ($results[$i]['len_resource'] > 40)?" title='{$results[$i]['resource']}'":'';
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'$show_searchterms_tooltip><a target='_blank' title='$element_title'";
						echo " href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $trimmed_searchterms."</span> <span$show_resource_tooltip>$trimmed_resource</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>