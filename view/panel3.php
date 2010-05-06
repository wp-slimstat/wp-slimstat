<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
?>
<div class="metabox-holder wide <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'Traffic Sources by day - Click on a day to filter reports', 'wp-slimstat-view' ); ?></h3>
		<?php $current = $wp_slimstat_view->get_traffic_sources_by_day();  
		if ($current->current_non_zero_count+$current->previous_non_zero_count == 0){ ?>
			<p class="nodata"><?php _e('No data to display','wp-slimstat-view') ?></p>
		<?php } else { ?>
		<OBJECT classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase=http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0" width="765" height="170" id="line" >
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
		<?php
			$unique_referers = $wp_slimstat_view->count_unique_referers();
			$direct_visits = $wp_slimstat_view->count_direct_visits();
			$search_engines = $wp_slimstat_view->count_search_engines();
			$pages_referred = $wp_slimstat_view->count_pages_referred();
			$referred_from_internal = $wp_slimstat_view->count_referred_from_internal();
		?>
		<h3><?php _e( 'Summary for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div class="container noscroll">
			<p><span class="element-title"><?php _e( 'Unique Referers', 'wp-slimstat-view' ); ?></span> <span><?php echo $unique_referers ?></span></p>
			<p><span class="element-title"><?php _e( 'Direct Visits', 'wp-slimstat-view' ); ?></span> <span><?php echo $direct_visits ?></span></p>
			<p><span class="element-title"><?php _e( 'Search Engines', 'wp-slimstat-view' ); ?></span> <span><?php echo $search_engines ?></span></p>
			<p><span class="element-title"><?php _e( 'Unique Pages Referred', 'wp-slimstat-view' ); ?></span> <span><?php echo $pages_referred ?></span></p>
			<p class="last"><span class="element-title"><?php _e( 'Unique Internal', 'wp-slimstat-view' ); ?></span> <span><?php echo $referred_from_internal ?></span></p>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Top Keywords', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
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
					
						echo "<p$show_title_tooltip$last_element><span class='element-title'>$element_text</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Countries for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top('country', '', 30, true);
				$total_count = $wp_slimstat_view->count_total_pageviews();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($total_count > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$total_count)):0;
						$country = __('c-'.$results[$i]['short_string'],'countries-languages');
					
						// TODO: source clickable to enable filter				
						echo "<p$last_element><span class='element-title'>$country</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Traffic Sources for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
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
				
						// TODO: source clickable to enable filter
						echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $results[$i]['short_string']."</span> <span>$percentage%</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Search Engines for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div class="container">
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
						echo "<p$last_element><span class='element-title'>$search_engine_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Sites for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y'];?></h3>
		<div class="container">
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
						echo "<p$last_element><span class='element-title'>$search_engine_domain</span> <span class='narrowcolumn'>$percentage%</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Recent Keywords &raquo; Pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
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
						echo "<p$last_element><span class='element-title'$show_searchterms_tooltip><a target='_blank' title='$element_title'";
						echo " href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $trimmed_searchterms."</span> <span$show_resource_tooltip>$trimmed_resource</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>