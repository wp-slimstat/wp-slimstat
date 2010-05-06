<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
?>
<div class="metabox-holder wide <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php 
			if (!$wp_slimstat_view->day_filter_active){
				_e( 'Average pageviews per visit by day - Click on a day for hourly metrics', 'wp-slimstat-view' ); 
			}
			else{
				_e( 'Average pageviews per visit by hour', 'wp-slimstat-view' ); 
			}
			?></h3>
		<?php $current = $wp_slimstat_view->get_average_pageviews_by_day();  
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
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Recent Contents', "wp-slimstat-view" ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent('resource');
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$results[$i]['short_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['short_string']));
						$results[$i]['long_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['long_string']));
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['long_string'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'');
					
						// TODO: source clickable to enable filter
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
		<h3><?php _e( 'Top bouncing pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top_bouncing_pages();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
						$element_url = 'http://'.$_SERVER['SERVER_NAME'].$results[$i]['resource'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 23)?'...':'');
				
						// TODO: source clickable to enable filter
						echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
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
		<h3><?php _e( 'Recent Feeds', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_feeds();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 23)?' title="'.$results[$i]['resource'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 23)?'...':'');
					
						// TODO: source clickable to enable filter
						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Popular pages for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top('resource', '', 65, true);
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
					
						// TODO: source clickable to enable filter
						echo "<p$last_element$show_title_tooltip><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a>";
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
		<h3><?php _e( 'Recent 404 pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_404_pages();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 23)?' title="'.$results[$i]['resource'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 24)?'...':'');
					
						// TODO: source clickable to enable filter
						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><?php _e('More','wp-slimstat-view') ?></div>
		<h3><?php _e( 'Top Exit Pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top_exit_pages();
				$count_results = count($results); // 0 if $results is null
				$count_exit_pages = $wp_slimstat_view->count_exit_pages();
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$percentage = ($count_exit_pages > 0)?sprintf("%01.1f", (100*$results[$i]['count']/$count_exit_pages)):0;
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
						$element_url = 'http://'.$_SERVER['SERVER_NAME'].$results[$i]['resource'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 50)?'...':'');
				
						// TODO: source clickable to enable filter
						echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> ";
						echo $element_text."</span> <span>$percentage%</span> <span>{$results[$i]['count']}</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>