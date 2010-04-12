<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Instantiate a new copy of the class
$wp_slimstat_view = new wp_slimstat_panel();

?>

<div class="metabox-holder wide">
	<div class="postbox">
		<h3><?php _e( 'Pageviews by day - Click on a day to filter reports', 'wp-slimstat-view' ); ?></h3>
		<?php $current = $wp_slimstat_view->get_pageviews_by_day(); ?>
		<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="780" height="170" id="line" >
         <param name="movie" value="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" />
         <param name="FlashVars" value="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170">
         <param name="quality" value="high" />
         <embed src="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170" quality="high" width="780" height="170" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
		</object>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'About WP-SlimStat', 'wp-slimstat-view' ); ?></h3>
		<p><span class="left"><?php _e( 'Total Hits', 'wp-slimstat-view' ); ?></span> <span><?php echo $wp_slimstat_view->get_total_count(); ?></span></p>
		<p><span class="left"><?php _e( 'Data Size', 'wp-slimstat-view' ); ?></span> <span><?php echo $wp_slimstat_view->get_data_size() ?></span></p>
		<p><span class="left"><?php _e( 'Tracking Active', 'wp-slimstat-view' ); ?></span> <span><?php _e(get_option('slimstat_is_tracking', 'no'), 'countries-languages') ?></span></p>
		<p><span class="left"><?php _e( 'Auto purge', 'wp-slimstat-view' ); ?></span> <span><?php echo (($auto_purge = get_option('slimstat_auto_purge', '0')) > 0)?$auto_purge.' days':'No'; ?></span></p>
		<p><span class="left">Geo IP</span> <span><?php echo date (get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/geoip.csv')) ?></span></p>
		<p class="last"><span class="left">BrowsCap</span> <span><?php echo date (get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/cache/browscap.ini')) ?></span></p>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Summary for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<?php
			$today_pageviews = intval($current->pageviews_current_month[intval($wp_slimstat_view->current_date['d'])]);
			$yesterday_pageviews = (intval($wp_slimstat_view->current_date['d'])==1)?$current->pageviews_previous_month[intval($wp_slimstat_view->yesterday['d'])]:$current->pageviews_current_month[intval($wp_slimstat_view->yesterday['d'])];
		?>
		<p><span class="left"><?php _e( 'Pageviews', 'wp-slimstat-view' ); ?></span> <span><?php echo ($current_pageviews = intval(array_sum($current->pageviews_current_month))); ?></span></p>
		<p><span class="left"><?php _e( 'Unique IPs', 'wp-slimstat-view' ); ?></span> <span><?php echo array_sum($current->unique_ips_current_month); ?></span></p>
		<p><span class="left"><?php _e( 'Avg Pageviews/day', 'wp-slimstat-view' ); ?></span> <span><?php echo ($current->current_non_zero_count > 0)?intval($current_pageviews/$current->current_non_zero_count):0; ?></span></p>
		<p><span class="left"><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['d'].'/'.$wp_slimstat_view->current_date['m'] ?></span> <span><?php echo intval($today_pageviews); ?></span></p>
		<p><span class="left"><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->yesterday['d'].'/'.$wp_slimstat_view->yesterday['m'] ?></span> <span><?php echo intval($yesterday_pageviews); ?></span></p>
		<p class="last"><span class="left"><?php _e( 'Last Month', 'wp-slimstat-view' ); ?></span> <span><?php echo intval(array_sum($current->pageviews_previous_month)); ?></span></p>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'User agents', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_browsers();
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					$last_element = ($i == $count_results-1)?' class="last"':'';
					$percentage = ($current_pageviews > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)):0;
				
					// TODO: source clickable to enable filter				
					echo '<p'.$last_element.'><span class="left">'.$results[$i]['browser'].' '.(($results[$i]['version']!=0)?$results[$i]['version']:'').'</span> <span>'.$percentage.'%</span></p>';
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php
			_e( 'Popular pages of all time', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('resource', '', 65);
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					// TODO: source clickable to enable filter
					$show_title_tooltip = ($results[$i]['len'] > 65)?' title="'.$results[$i]['long_string'].'"':'';
					$last_element = ($i == $count_results-1)?' class="last"':'';
					
					echo '<p'.$show_title_tooltip.$last_element.'><span class="left"><a target="_blank" title="'.sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
					echo '" href="'.get_bloginfo('url').$results[$i]['long_string'].'"><img src="'.WP_PLUGIN_URL.'/wp-slimstat/images/url.gif" /></a>';
					echo $results[$i]['short_string'].(($results[$i]['len'] > 65)?'...':'').'</span> <span>'.$results[$i]['count'].'</span></p>';
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Recent Keywords', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_recent('searchterms');
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					$results[$i]['short_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['short_string']));
					$results[$i]['long_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['long_string']));
			
					// TODO: source clickable to enable filter
					$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['long_string'].'"':'';
					$last_element = ($i == $count_results-1)?' class="last"':'';
					echo '<p'.$last_element.$show_title_tooltip.'>'.$results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'').'</p>';
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Recent Countries', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_recent('country');
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					$last_element = ($i == $count_results-1)?' class="last"':'';
					$country_code = 'c-'.$results[$i]['short_string'];
					
					// TODO: source clickable to enable filter				
					echo '<p'.$last_element.'>'.__($country_code, 'countries-languages').'</p>';
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'Traffic Sources Overview', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('domain', 'referer');
				$count_pageviews_with_referer = $wp_slimstat_view->get_referer_count();
			
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					if (strpos(get_bloginfo('url'), $results[$i]['long_string'])) continue;
				
					$last_element = ($i == $count_results-1)?' class="last"':'';
					$percentage = ($count_pageviews_with_referer > 0)?intval(100*$results[$i]['count']/$count_pageviews_with_referer):0;
				
					// TODO: source clickable to enable filter
					echo '<p'.$last_element.'><span class="left"><a target="_blank" title="'.sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
					echo '" href="http://'.$results[$i]['long_string'].$results[$i]['referer'].'"><img src="'.WP_PLUGIN_URL.'/wp-slimstat/images/url.gif" /></a> ';
					echo $results[$i]['short_string'].'</span> <span>'.$percentage.'%</span> <span>'.$results[$i]['count'].'</span></p>';
				}
			?>
		</div>
	</div>
</div>

<?php

// Let's extend the main class with the methods we use in this panel
class wp_slimstat_panel extends wp_slimstat_view {

	public function get_pageviews_by_day(){
		global $wpdb;
	
		$sql = "SELECT YEAR(FROM_UNIXTIME(`dt`)) y, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) count_pageviews, COUNT(DISTINCT(`ip`)) count_unique
				FROM `$this->table_stats`
				WHERE (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}) 
					OR (YEAR(FROM_UNIXTIME(`dt`)) = {$this->previous_month['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->previous_month['m']})
				GROUP BY YEAR(FROM_UNIXTIME(`dt`)), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m'), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d')
				ORDER BY d,m ASC";
		$array_result = $wpdb->get_results($sql, ARRAY_A);
	
		$array_current_month = $array_previous_month = $array_unique_ips_current = $array_unique_ips_previous = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
	
		// Let's reorganize the result
		foreach($array_result as $a_result) {
		
			if ($a_result['m'] == $this->current_date['m']) {
				// Pageviews
				$array_current_month[intval($a_result['d'])] = $a_result['count_pageviews'];
			
				// Unique IPs
				$array_unique_ips_current[intval($a_result['d'])] = $a_result['count_unique'];
			
				if ($a_result['count_unique'] > 0) $current_non_zero_count++;
			}
			else {
				$array_previous_month[intval($a_result['d'])] = $a_result['count_pageviews'];		
				$array_unique_ips_previous[intval($a_result['d'])] = $a_result['count_unique'];
			
				if ($a_result['count_unique'] > 0) $previous_non_zero_count++;
			}
		}

		// Let's generate the XML for the flash chart
		for($i=1;$i<32;$i++) { // a month can have 31 days at maximum
			$categories_xml .= "<category name='$i'/>";
			if ($i <= $this->current_date['d']) {
				$current_month_xml .= "<set value='".intval($array_current_month[$i])."' link='/'/>";
				$unique_ips_xml .= "<set value='".intval($array_unique_ips_current[$i])."'/>";
			}
			$previous_month_xml .= "<set value='".intval($array_previous_month[$i])."' alpha='80' link='/'/>";
		}
	
		$xml = "<graph canvasBorderThickness='0' canvasBorderColor='ffffff' decimalPrecision='0' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='".__('Pageviews','wp-slimstat-view')."'>";
		$xml .= "<categories>$categories_xml</categories>";
		$xml .= "<dataset seriesname='".__('Unique IPs','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='3'>$unique_ips_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Pageviews','wp-slimstat-view')." {$this->previous_month['m']}/{$this->previous_month['y']}' color='0099ff' showValue='1'>$previous_month_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Pageviews','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_month_xml</dataset>";
		$xml .= "</graph>";
	
		$result->xml = $xml;
		$result->pageviews_current_month = $array_current_month;
		$result->pageviews_previous_month = $array_previous_month;
		$result->unique_ips_current_month = $array_unique_ips_current;
		$result->unique_ips_previous_month = $array_unique_ips_previous;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;
	
		return $result;
	}

	public function get_browsers(){
		global $wpdb;

		$sql = "SELECT DISTINCT `browser`,`version`, COUNT(*) count
				FROM `$this->table_stats` ts INNER JOIN `$this->table_browsers` tb ON ts.`browser_id` = tb.`browser_id`
				WHERE tb.`browser` <> ''
				GROUP BY `browser`, `version`
				ORDER BY count DESC
				LIMIT 20";
	
		$array_result = $wpdb->get_results($sql, ARRAY_A);
	
		return $array_result;	
	}
	
	public function get_data_size(){
		global $wpdb;

		$suffix = 'KB';

		$sql = "SHOW TABLE STATUS LIKE '$this->table_stats'";
		$myTableDetails = $wpdb->get_row($sql, 'ARRAY_A', 0);
	
		$myTableSize = ( $myTableDetails['Data_length'] / 1024 ) + ( $myTableDetails['Index_length'] / 1024 );
	
		if ($myTableSize > 1024){
			$myTableSize /= 1024;
			$suffix = 'MB';
		}
		return number_format($myTableSize, 2, ",", ".").' '.$suffix;
	}
}
?>