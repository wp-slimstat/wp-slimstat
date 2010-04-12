<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}
?>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( 'Recent Content', "wp-slimstat-view" ); ?></h3>
		TBD
	</div>
</div>
	
<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( "Top bouncing pages", "wp-slimstat-view" ); ?></h3>
		TBD
	</div>
</div>
	
<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( "Average time on site", "wp-slimstat-view" ); ?></h3>
		Content
	</div>
</div>
	
<div class="metabox-holder medium">
	<div class="postbox">
		<h3><?php 
			// TODO: add '...for FILTER' when a date is set
			_e( 'Popular pages for', 'wp-slimstat-view' ); echo ' '.$wp_slimstat_view->current_date['m'].'/'.$wp_slimstat_view->current_date['y']; ?> <span class="right">View All</span></h3>
		<div>
			<?php /*
				$results = $wp_slimstat_view->get_top('resource', '', 65, true);
				$count_results = count($results);
				for($i=0;$i<$count_results;$i++){
					// TODO: source clickable to enable filter
					$show_title_tooltip = ($results[$i]['len'] > 65)?' title="'.$results[$i]['long_string'].'"':'';
					$last_element = ($i == $count_results-1)?' class="last"':'';
					echo '<p'.$show_title_tooltip.$last_element.'><span class="left"><a target="_blank" title="'.sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
					echo '" href="'.get_bloginfo('url').$results[$i]['long_string'].'"><img src="'.WP_PLUGIN_URL.'/wp-slimstat/images/url.gif" /></a>';
					echo $results[$i]['short_string'].(($results[$i]['len'] > 65)?'...':'').'</span> <span>'.$results[$i]['count'].'</span></p>';
				}
			*/ ?>
		</div>
	</div>
</div>

<div class="metabox-holder">
	<div class="postbox">
		<h3><?php _e( "Recent 404 pages", "wp-slimstat-view" ); ?></h3>
		Content
	</div>
</div>