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
         <param name="movie" value="<?php echo $slimstat_plugin_url ?>/wp-slimstat/view/swf/fcf.swf" />
         <param name="FlashVars" value="&dataXML=<?php echo $current->xml ?>&chartWidth=765&chartHeight=170">
         <param name="quality" value="high" />
         <embed src="<?php echo $slimstat_plugin_url ?>/wp-slimstat/view/swf/fcf.swf" flashVars="&dataXML=<?php echo $current->xml ?>&chartWidth=765&chartHeight=170" quality="high" width="765" height="170" name="line" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" />
      </object>
	  <?php } ?>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&slimpanel=5&ftu=get_recent_resources&cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
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
						if (!isset($wp_slimstat_view->filters_parsed['resource'][0]))	$element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&resource={$results[$i]['long_string']}'>$element_text</a>";
						
						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>
	
<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&slimpanel=5&ftu=get_recent_bouncing_pages&cmo=1<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
		<h3><?php _e( 'Recent bouncing pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_bouncing_pages();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					$current_resource = '';
					for($i=0;$i<$count_results;$i++){
						if ($current_resource == $results[$i]['resource']) continue;
						$current_resource = $results[$i]['resource'];
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$current_resource.'"':'';
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $current_resource);
						$element_url = get_bloginfo('url').$current_resource;
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'');
						if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&resource=$current_resource'>$element_text</a>";

						echo "<p$last_element$show_title_tooltip><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
						echo $element_text."</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'Recent Feeds', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_feeds();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['resource'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'');

						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php 
			_e( 'Popular pages for', 'wp-slimstat-view' ); 
			echo ' ';
			if (empty($wp_slimstat_view->day_interval)){
				if ($wp_slimstat_view->day_filter_active) echo $wp_slimstat_view->current_date['d'].'/';
				echo $wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; 
			}
			else{
				_e('this period', 'wp-slimstat-view');
			} ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_top('resource', '', 62, true);
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 62)?' title="'.$results[$i]['long_string'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
						$element_url = get_bloginfo('url').$results[$i]['long_string'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 62)?'...':'');
						if (!isset($wp_slimstat_view->filters_parsed['resource'][0]))	$element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&resource={$results[$i]['long_string']}'>$element_text</a>";

						echo "<p$last_element$show_title_tooltip><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a>";
						echo $element_text."</span> <span>".number_format($results[$i]['count'])."</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<div class="more"><a href="index.php?page=wp-slimstat/view/index.php&slimpanel=5&ftu=get_recent_404<?php echo $filters_query; ?>"><?php _e('More','wp-slimstat-view') ?></a></div>
		<h3><?php _e( 'Recent 404 pages', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_404_pages();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 25)?' title="'.$results[$i]['resource'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 25)?'...':'');
						if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&resource={$results[$i]['resource']}'>$element_text</a>";

						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'Recent Internal Searches', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_internal_searches();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['searchterms'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 30)?'...':'');
						if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&searchterms={$results[$i]['searchterms']}'>$element_text</a>";

						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php 
			_e( 'Top Exit Pages for', 'wp-slimstat-view' );
			echo ' ';
			if (empty($wp_slimstat_view->day_interval)){
				if ($wp_slimstat_view->day_filter_active) echo $wp_slimstat_view->current_date['d'].'/';
				echo $wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; 
			}
			else{
				_e('this period', 'wp-slimstat-view');
			} ?></h3>
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
						$element_url = get_bloginfo('url').$results[$i]['resource'];
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 50)?'...':'');
						if (!isset($wp_slimstat_view->filters_parsed['resource'][0])) $element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=4$filters_query&resource={$results[$i]['resource']}'>$element_text</a>";

						echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
						echo " href='$element_url'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
						echo $element_text."</span> <span>$percentage%</span> <span>".number_format($results[$i]['count'])."</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder medium <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'Recent Outbound Links', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_outbound();
				$count_results = count($results); // 0 if $results is null
				$outbound_id = 0;
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						if ($outbound_id != $results[$i]['outbound_id']){
							$ip_address = long2ip($results[$i]['ip']);
							$ip_address = "<a href='http://www.ip2location.com/$ip_address' target='_blank' title='WHOIS: $ip_address'><img src='$slimstat_plugin_url/wp-slimstat/images/whois.gif' /></a> $ip_address";
							$country = __('c-'.$results[$i]['country'],'countries-languages');
							$time_of_pageview = $results[$i]['date_f'].'@'.$results[$i]['time_f'];
							$results[$i]['searchterms'] = str_replace('\\', '', htmlspecialchars($results[$i]['searchterms']));
						
							echo "<p class='header'>$ip_address <span>$country</span> <span style='margin-right:10px'>{$time_of_pageview}</span> <span style='margin-right:5px'><strong>{$results[$i]['searchterms']}</strong></span></p>";
							$outbound_id = $results[$i]['outbound_id'];
						}					
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$resource_title = $results[$i]['resource'];
						$resource_text = $results[$i]['short_resource'];
						$resource_text .= (($results[$i]['len_resource'] > 35)?'...':'');
						
						$outbound_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['outbound_domain'].$results[$i]['outbound_resource']);
						$outbound_url = 'http://'.$results[$i]['long_outbound'];
						$outbound_text = (strlen($results[$i]['outbound_resource']) < 2)?$results[$i]['outbound_domain']:$results[$i]['short_outbound'];
						$outbound_text .= (($results[$i]['len_outbound'] > 35)?'...':'');

						echo "<p$last_element><span class='element-title' title='$resource_title'><a target='_blank' title='$outbound_title'";
						echo " href='$outbound_url'><img src='$slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
						echo "$resource_text &raquo; $outbound_text</span></p>";
					}
				}
			?>
		</div>
	</div>
</div>

<div class="metabox-holder <?php echo $wp_locale->text_direction ?>">
	<div class="postbox">
		<h3><?php _e( 'Recent Downloads', 'wp-slimstat-view' ); ?></h3>
		<div class="container">
			<?php
				$results = $wp_slimstat_view->get_recent_downloads();
				$count_results = count($results); // 0 if $results is null
				if ($count_results == 0) {
					echo '<p class="nodata">'.__('No data to display','wp-slimstat-view').'</p>';
				} else {
					for($i=0;$i<$count_results;$i++){
						$show_title_tooltip = ($results[$i]['len'] > 30)?' title="'.$results[$i]['outbound_resource'].'"':'';
						$last_element = ($i == $count_results-1)?' class="last"':'';
						$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 35)?'...':'');

						echo "<p$last_element$show_title_tooltip>$element_text</p>";
					}
				}
			?>
		</div>
	</div>
</div>