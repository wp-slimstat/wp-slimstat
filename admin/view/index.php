<?php 
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Define the screens
$array_screens = array(
	(substr(wp_slimstat_boxes::get_filters_html(wp_slimstat_db::$filters['parsed']), 0, 3) == '<h3')?__('Right Now','wp-slimstat-view'):__('Details','wp-slimstat-view'),
	__('Overview','wp-slimstat-view'),
	__('Visitors','wp-slimstat-view'),
	__('Content','wp-slimstat-view'),  
	__('Traffic Sources','wp-slimstat-view'),
	__('World Map','wp-slimstat-view'), 
	__('Custom Reports','wp-slimstat-view')
);

?>

<div class="wrap">
	<div id="analytics-icon" class="icon32"></div>
	<h2>WP SlimStat</h2>
	<p class="nav-tabs"><?php
		$filters_no_starting = wp_slimstat_boxes::replace_query_arg('starting');
		if (!empty($filters_no_starting)) $filters_no_starting = '&fs='.$filters_no_starting;
		foreach($array_screens as $a_panel_id => $a_panel_name){
			echo "<a class='nav-tab nav-tab".((wp_slimstat_boxes::$current_screen == $a_panel_id+1)?'-active':'-inactive')."' href='".wp_slimstat_admin::$admin_url.($a_panel_id+1).$filters_no_starting."'>$a_panel_name</a>";
		} 
		$using_screenres = wp_slimstat_admin::check_screenres(); 
	?></p>
	
	<form action="<?php echo wp_slimstat_boxes::fs_url(); ?>" method="post" name="setslimstatfilters" id="slimstat-filters"
		onsubmit="if (this.year.value == '<?php _e('Year','wp-slimstat-view') ?>') this.year.value = ''">
		<p>
			<span class="nowrap"><?php _e('Show records where','wp-slimstat-view') ?>
				<span class='inline-help' style='float:left;margin:8px 5px 8px 0' title='<?php _e('Please refer to the contextual help (available on WP 3.3+) for more information on what these filters mean.','wp-slimstat-view') ?>'></span>
				<select name="f" id="slimstat_filter_name" style="width:9em">
					<option value="no-filter-selected-1">&nbsp;</option>
					<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
					<option value="country"><?php _e('Country Code','wp-slimstat-view') ?></option>
					<option value="ip"><?php _e('IP','wp-slimstat-view') ?></option>
					<option value="searchterms"><?php _e('Search Terms','wp-slimstat-view') ?></option>
					<option value="language"><?php _e('Language Code','wp-slimstat-view') ?></option>
					<option value="platform"><?php _e('Operating System','wp-slimstat-view') ?></option>
					<option value="resource"><?php _e('Permalink','wp-slimstat-view') ?></option>
					<option value="referer"><?php _e('Referer','wp-slimstat-view') ?></option>
					<option value="user"><?php _e('Visitor\'s Name','wp-slimstat-view') ?></option>
					<option value="no-filter-selected-2">&nbsp;</option>
					<option value="no-filter-selected-3"><?php _e('-- Advanced filters --','wp-slimstat-view') ?></option>
					<option value="plugins"><?php _e('Browser Capabilities','wp-slimstat-view') ?></option>
					<option value="version"><?php _e('Browser Version','wp-slimstat-view') ?></option>
					<option value="type"><?php _e('Browser Type','wp-slimstat-view') ?></option>
					<option value="colordepth"><?php _e('Color Depth','wp-slimstat-view') ?></option>
					<option value="css_version"><?php _e('CSS Version','wp-slimstat-view') ?></option>
					<option value="notes"><?php _e('Pageview Attributes','wp-slimstat-view') ?></option>
					<option value="author"><?php _e('Post Author','wp-slimstat-view') ?></option>
					<option value="category"><?php _e('Post Category ID','wp-slimstat-view') ?></option>
					<option value="other_ip"><?php _e('Originating IP','wp-slimstat-view') ?></option>
					<option value="content_type"><?php _e('Resource Content Type','wp-slimstat-view') ?></option>
					<option value="resolution"><?php _e('Screen Resolution','wp-slimstat-view') ?></option>
					<option value="visit_id"><?php _e('Visit ID','wp-slimstat-view') ?></option>
				</select>
				<select name="o" id="slimstat_filter_operator" style="width:9em" onchange="if(this.value=='is_empty'||this.value=='is_not_empty'){document.getElementById('slimstat_filter_value').disabled=true;}else{document.getElementById('slimstat_filter_value').disabled=false;}">
					<option value="equals"><?php _e('equals','wp-slimstat-view') ?></option>
					<option value="is_not_equal_to"><?php _e('is not equal to','wp-slimstat-view') ?></option>
					<option value="contains"><?php _e('contains','wp-slimstat-view') ?></option>
					<option value="does_not_contain"><?php _e('does not contain','wp-slimstat-view') ?></option>
					<option value="starts_with"><?php _e('starts with','wp-slimstat-view') ?></option>
					<option value="ends_with"><?php _e('ends with','wp-slimstat-view') ?></option>
					<option value="sounds_like"><?php _e('sounds like','wp-slimstat-view') ?></option>
					<option value="is_empty"><?php _e('is empty','wp-slimstat-view') ?></option>
					<option value="is_not_empty"><?php _e('is not empty','wp-slimstat-view') ?></option>
					<option value="is_greater_than"><?php _e('is greater than','wp-slimstat-view') ?></option>
					<option value="is_less_than"><?php _e('is less than','wp-slimstat-view') ?></option>
				</select>
				<input type="text" name="v" id="slimstat_filter_value" value="" size="12">
			</span>
			<span class="nowrap">
				<span class='inline-help' style='float:left;margin:8px 5px 8px 0' title='<?php _e('Select a day to make the interval field appear.','wp-slimstat-view') ?>'></span>
				<?php _e('Filter by date','wp-slimstat-view') ?>
				<select name="day" id="slimstat_filter_day" onchange="if(this.value>0){document.getElementById('slimstat_interval_block').style.display='inline'}else{document.getElementById('slimstat_interval_block').style.display='none'}">
					<option value="0"><?php _e('Day','wp-slimstat-view') ?></option><?php
					for($i=1;$i<=31;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['day'][1]) && wp_slimstat_db::$filters['parsed']['day'][1] == $i)
							echo "<option selected='selected'>$i</option>";
						else
							echo "<option>$i</option>";
					} 
					?></select> 
				<select name="month" id="slimstat_filter_month">
					<option value=""><?php _e('Month','wp-slimstat-view') ?></option><?php
					for($i=1;$i<=12;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['month'][1]) && wp_slimstat_db::$filters['parsed']['month'][1] == $i)
							echo "<option value='$i' selected='selected'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
						else
							echo "<option value='$i'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
					} 
					?></select>
				<input type="text" name="year" id="slimstat_filter_year" size="4" onfocus="if(this.value == '<?php _e('Year','wp-slimstat-view') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('Year','wp-slimstat-view') ?>';"
					value="<?php echo !empty(wp_slimstat_db::$filters['parsed']['year'][1])?wp_slimstat_db::$filters['parsed']['year'][1]:__('Year','wp-slimstat-view') ?>">
				<span id="slimstat_interval_block"<?php if (!empty(wp_slimstat_db::$filters['parsed']['day'][1])) echo ' style="display:inline"' ?>>+ <input type="text" name="interval"  id="slimstat_filter_interval" size="4" value="<?php _e('days', 'wp-slimstat-view') ?>" onfocus="if(this.value == '<?php _e('days','wp-slimstat-view') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('days','wp-slimstat-view') ?>';"> &nbsp;&nbsp;&nbsp;</span>
			</span>
			<input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary">
		</p>
	</form>
	<?php echo "<p class='current-filters'>".wp_slimstat_boxes::get_filters_html(wp_slimstat_db::$filters['parsed']).'</p>'; ?>
	<div class="meta-box-sortables">
		<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_slimstat_boxes::$meta_box_order_nonce ?>" /></form>
		<?php if (is_readable(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/panel'.wp_slimstat_boxes::$current_screen.'.php')) require_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/panel'.wp_slimstat_boxes::$current_screen.'.php'); ?>
	</div>
</div>
<div id="modal-dialog" class="widget"></div>