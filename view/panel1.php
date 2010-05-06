<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
?>
<div class="metabox-holder <?php echo $wp_locale->text_direction ?> wide">
	<div class="postbox">
		<h3><?php 
			if (!$wp_slimstat_view->day_filter_active){
				_e( 'Pageviews by day - Click on a day for hourly metrics', 'wp-slimstat-view' ); 
			}
			else{
				_e( 'Pageviews by hour', 'wp-slimstat-view' ); 
			}
			?></h3>
		<?php $current = $wp_slimstat_view->get_pageviews_by_day(); 
		if ($current->current_non_zero_count+$current->previous_non_zero_count == 0){ ?>
			<p class="nodata"><?php _e('No data to display','wp-slimstat-view') ?></p>
		<?php } else { ?>
		<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="765" height="170" id="line" >
         <param name="movie" value="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" />
         <param name="FlashVars" value="&dataXML=<?php echo $current->xml ?>&chartWidth=765&chartHeight=170">
         <param name="quality" value="high" />
         <embed src="<?php echo WP_PLUGIN_URL ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&dataXML=<?php echo $current->xml ?>&chartWidth=765&chartHeight=170" quality="high" width="765" height="170" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
		</object>
		<?php } ?>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'About WP-SlimStat', 'wp-slimstat-view' ); ?></h3>
		<div class="container noscroll">
			<p><span class='element-title'><?php _e( 'Total Hits', 'wp-slimstat-view' ); ?></span> <span><?php echo $wp_slimstat_view->count_total_pageviews(); ?></span></p>
			<p><span class='element-title'><?php _e( 'Data Size', 'wp-slimstat-view' ); ?></span> <span><?php echo $wp_slimstat_view->get_data_size() ?></span></p>
			<p><span class='element-title'><?php _e( 'Tracking Active', 'wp-slimstat-view' ); ?></span> <span><?php _e(get_option('slimstat_is_tracking', 'no'), 'countries-languages') ?></span></p>
			<p><span class='element-title'><?php _e( 'Auto purge', 'wp-slimstat-view' ); ?></span> <span><?php echo (($auto_purge = get_option('slimstat_auto_purge', '0')) > 0)?$auto_purge.' days':'No'; ?></span></p>
			<p><span class='element-title'>Geo IP</span> <span><?php echo date (get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/geoip.csv')) ?></span></p>
			<p class="last"><span class='element-title'>BrowsCap</span> <span><?php echo date (get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/cache/browscap.ini')) ?></span></p>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php 
			_e( 'Summary for', 'wp-slimstat-view' ); 
			echo ' ';
			if ($wp_slimstat_view->day_filter_active) echo $wp_slimstat_view->current_date['d'].'/';
			echo $wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?>
		</h3>
		<div class="container noscroll">
		<?php
			if (!$wp_slimstat_view->day_filter_active){
				$today_pageviews = intval($current->current_data1[intval($wp_slimstat_view->current_date['d'])]);
				$yesterday_pageviews = (intval($wp_slimstat_view->current_date['d'])==1)?$current->previous_data1[intval($wp_slimstat_view->yesterday['d'])]:$current->current_data1[intval($wp_slimstat_view->yesterday['d'])];
			}
			$current_pageviews = intval(array_sum($current->current_data1));
		?>
			<p><span class='element-title'><?php _e( 'Pageviews', 'wp-slimstat-view' ); ?></span> <span><?php echo $current_pageviews; ?></span></p>
			<p><span class='element-title'><?php _e( 'Unique IPs', 'wp-slimstat-view' ); ?></span> <span><?php echo array_sum($current->current_data2); ?></span></p>
			<p><span class='element-title'><?php _e( 'Avg Pageviews', 'wp-slimstat-view' ); ?></span> <span><?php echo ($current->current_non_zero_count > 0)?intval($current_pageviews/$current->current_non_zero_count):0; ?></span></p>
			<?php if (!$wp_slimstat_view->day_filter_active){ ?>
			<p><span class='element-title'><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['d'].'/'.$wp_slimstat_view->current_date['m'] ?></span> <span><?php echo intval($today_pageviews); ?></span></p>
			<p><span class='element-title'><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->yesterday['d'].'/'.$wp_slimstat_view->yesterday['m'] ?></span> <span><?php echo intval($yesterday_pageviews); ?></span></p>
			<p class="last"><span class='element-title'><?php _e( 'Last Month', 'wp-slimstat-view' ); ?></span> <span><?php echo intval(array_sum($current->previous_data1)); ?></span></p>
			<?php } ?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'User agents', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_browsers();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($current_pageviews > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$current_pageviews)):0;
						$browser_version = ($results[$i]['version']!=0)?$results[$i]['version']:'';				
						echo "<p$last_element><span class='element-title'>{$results[$i]['browser']} $browser_version</span> <span>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Popular pages of all time', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top('resource', '', 65);
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 65)?' title="'.$results[$i]['long_string'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
						$element_url = 'http://'.get_bloginfo('url').$results[$i]['long_string'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 65)?'...':'');
						echo "<p$last_element$show_title_tooltip><span class='element-title'>";
						if (strpos($element_url, '[404]') == 0){
							echo "<a target='_blank' title='$element_title'";
							echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a>";
						}
						echo $element_text."</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Recent Keywords', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent('searchterms', '', 35);
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$results[$i]['short_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['short_string']));
						$results[$i]['long_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['long_string']));
						$show_title_tooltip = ($results[$i]['len'] > 35)?' title="'.$results[$i]['long_string'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 35)?'...':'');
						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Recent Countries', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent('country');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$country = __('c-'.$results[$i]['short_string'],'countries-languages');			
						echo "<p$last_element>$country</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><span><?php _e( 'Traffic Sources Overview', 'wp-slimstat-view' ); ?></span></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top('domain', 'referer', 65, true);
				$count_results = count($results); // 0 if $results is null
				$count_pageviews_with_referer = $wp_slimstat_view->count_referers();
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						if (strpos(get_bloginfo('url'), $results[$i]['long_string'])) continue;	
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($count_pageviews_with_referer > 0)?intval(100*$results[$i]['count']/$count_pageviews_with_referer):0;
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
						$element_url = 'http://'.$results[$i]['long_string'].$results[$i]['referer'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 65)?'...':'');
						echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $element_text."</span> <span>$percentage%</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>