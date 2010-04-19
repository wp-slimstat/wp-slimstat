<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Instantiate a new copy of the class
$wp_slimstat_view = new wp_slimstat_view();

?>
<div class="metabox-holder wide">
	<div class="postbox">
		<h3><?php _e( 'Unique (human) visitors by day - Click on a day to filter reports', 'wp-slimstat-view' ); ?></h3>
		<?php $current = $wp_slimstat_view->get_visits_by_day(); 
		if ($current->current_non_zero_count+$current->previous_non_zero_count == 0){ ?>
			<p class="nodata"><?php _e('No data to display','wp-slimstat-view') ?></p>
		<?php } else { ?>
		<OBJECT classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="780" height="170" id="line" >
         <param name="movie" value="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" />
         <param name="FlashVars" value="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170">
         <param name="quality" value="high" />
         <embed src="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&dataXML=<?php echo $current->xml ?>&chartWidth=780&chartHeight=170" quality="high" width="780" height="170" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
      </object>
	  <?php } ?>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Summary for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<?php
			$total_visitors = array_sum($current->visits_current_month);
			$one_time_visitors = $wp_slimstat_view->count_new_visitors();
			$bounce_rate = ($total_visitors > 0)?sprintf("%01.2f", (100*$one_time_visitors/$total_visitors)):0;
			$metrics_per_visit = $wp_slimstat_view->get_max_and_average_pages_per_visit();
		?>
		<p><span class="left"><?php _e( 'Human visitors', 'wp-slimstat-view' ); ?></span> <span><?php echo $total_visitors ?></span></p>
		<p><span class="left"><?php _e( 'One time visitors', 'wp-slimstat-view' ); ?></span> <span><?php echo $one_time_visitors = $wp_slimstat_view->count_new_visitors() ?></span></p>
		<p><span class="left"><?php _e( 'Bounce rate', 'wp-slimstat-view' ); ?></span> <span><?php echo $bounce_rate ?>%</span></p>
		<p><span class="left"><?php _e( 'Bots', 'wp-slimstat-view' ); ?></span> <span><?php echo $wp_slimstat_view->count_bots() ?></span></p>
		<p><span class="left"><?php _e( 'Pages per visit', 'wp-slimstat-view' ); ?></span> <span><?php echo $metrics_per_visit->avg ?></span></p>
		<p class="last"><span class="left"><?php _e( 'Longest visit', 'wp-slimstat-view' ); ?></span> <span><?php echo $metrics_per_visit->max ?> hits</span></p>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Languages', 'wp-slimstat-view' ); ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('language');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$total_count = $wp_slimstat_view->get_total_count();
						$percentage = ($total_count > 0)?sprintf("%01.1f", (100*$results[$i]['count']/$total_count)):0;
						$language = __('l-'.$results[$i]['short_string'], 'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$language ({$results[$i]['short_string']})</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Languages - Just Visitors', 'wp-slimstat-view' ); ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_only_visits('language');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_visitors > 0)?sprintf("%01.1f", (100*$results[$i]['count']/$total_visitors)):0;
						$language = __('l-'.$results[$i]['short_string'], 'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$language ({$results[$i]['short_string']})</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'IP Addresses and Domains', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('ip');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.1f", (100*$results[$i]['count']/$total_count)):0;
						$host_by_ip = gethostbyaddr( $ip_address = long2ip($results[$i]['short_string']));
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$host_by_ip ($ip_address)</span> <span>{$results[$i]['count']}</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Recent Browsers', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<p>Browser <span class="narrowcolumn">CSS</span> <span class="narrowcolumn">Ver</span> </p>
			<?php
				$results = $wp_slimstat_view->get_recent_browsers();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
					
						// TODO: source clickable to enable filter
						echo "<p$last_element>{$results[$i]['browser']} <span class='narrowcolumn'>{$results[$i]['css_version']}</span> <span class='narrowcolumn'>{$results[$i]['version']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Operating Systems', 'wp-slimstat-view' ); ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_operating_systems();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$platform = __($results[$i]['platform'],'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$platform</span> <span>{$results[$i]['count']}</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'Browsers and Operating Systems', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_browsers_by_operating_system();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$platform = __($results[$i]['platform'],'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>{$results[$i]['browser']} {$results[$i]['version']} / $platform</span> <span>{$results[$i]['count']}</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
		
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Screen Resolutions', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_screenres(false);
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_visitors > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)):0;
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>{$results[$i]['resolution']}</span> <span>{$results[$i]['count']}</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Screen Resolutions with colordepth', 'wp-slimstat-view' ); ?></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top_screenres(true);
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_visitors > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_visitors)):0;
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>{$results[$i]['resolution']} ({$results[$i]['colordepth']}, {$results[$i]['antialias']})</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>
	
<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Plugins', 'wp-slimstat-view' ); ?></h3>
		<?php
			$percentage_java = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('java')/$total_visitors)):0;
			$percentage_flash = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('flash')/$total_visitors)):0;
			$percentage_mediaplayer = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('mediaplayer')/$total_visitors)):0;
			$percentage_acrobat = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('acrobat')/$total_visitors)):0;
			$percentage_silverlight = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('silverlight')/$total_visitors)):0;
			$percentage_quicktime = ($total_visitors > 0)?sprintf("%01.2f", (100*$wp_slimstat_view->count_plugin('quicktime')/$total_visitors)):0;
		?>
		<p><span class="left"><?php _e( 'Java', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_java ?>%</span></p>
		<p><span class="left"><?php _e( 'Flash', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_flash ?>%</span></p>
		<p><span class="left"><?php _e( 'Media Player', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_mediaplayer ?>%</span></p>
		<p><span class="left"><?php _e( 'Acrobat', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_acrobat ?>%</span></p>
		<p><span class="left"><?php _e( 'Silverlight', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_silverlight ?>%</span></p>
		<p class="last"><span class="left"><?php _e( 'Quicktime', 'wp-slimstat-view' ); ?></span> <span><?php echo $percentage_quicktime ?>%</span></p>
	</div>
</div>
	
<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Top Countries', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_top('country');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.1f", (100*$results[$i]['count']/$total_count)):0;
						$country = __('c-'.$results[$i]['short_string'],'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='left'>$country ({$results[$i]['short_string']})</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php _e( 'Details about Recent Visits', 'wp-slimstat-view' ); ?> <span class="right">More</span></h3>
		<div>
			<?php
				$results = $wp_slimstat_view->get_details_recent_visits();
				$count_results = count($results);
				$visit_id = 0;
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						if ($visit_id != $results[$i]['visit_id']){
							$ip_address = long2ip($results[$i]['ip']);
							$country = __('c-'.$results[$i]['country'],'countries-languages');
							$time_of_pageview = date_i18n(get_option('date_format'),$results[$i]['dt']).'@'.date_i18n(get_option('time_format'),$results[$i]['dt']);
						
							echo "<p class='header'>$ip_address <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>{$time_of_pageview}</span></p>";
							$visit_id = $results[$i]['visit_id'];
						}
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
						echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
						if (!empty($results[$i]['domain'])){
							echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
						}
						else{
							echo __('Direct visit to','wp-slimstat-view');
						}
						echo ' '.substr($results[$i]['resource'],0,40).'</p>';				
					}
				}
			?>
		</div>
	</div>
</div>