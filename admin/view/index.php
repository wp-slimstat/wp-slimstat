<?php 
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Define the screens
$array_screens = array(
	(substr(wp_slimstat_boxes::get_filters_html(wp_slimstat_db::$filters_parsed), 0, 3) == '<h3')?__('Right Now','wp-slimstat-view'):__('Details','wp-slimstat-view'),
	__('Overview','wp-slimstat-view'),
	__('Visitors','wp-slimstat-view'),
	__('Content','wp-slimstat-view'),  
	__('Traffic Sources','wp-slimstat-view'),
	__('World Map','wp-slimstat-view'), 
	__('Custom Reports','wp-slimstat-view')
);

// Pass some information to Javascript, to be used when hidden modules are activated
echo '<script type="text/javascript"> var slimstat_filters_string = "'.urldecode(wp_slimstat_boxes::$filters_string).'"; ';
if ((wp_slimstat::$options['refresh_interval'] > 0) && (wp_slimstat_boxes::$current_screen == 1)) echo "window.setTimeout('location.reload()', ".wp_slimstat::$options['refresh_interval']."*1000);";
echo '</script>';

?>

<div class="wrap">
	<div id="analytics-icon" class="icon32"></div>
	<h2>WP SlimStat</h2>
	<p class="nav-tabs"><?php
		$filters_no_starting = wp_slimstat_boxes::replace_query_arg('starting');
		if (!empty($filters_no_starting)) $filters_no_starting .= '&fs=';
		foreach($array_screens as $a_panel_id => $a_panel_name){
			echo "<a class='nav-tab nav-tab".((wp_slimstat_boxes::$current_screen == $a_panel_id+1)?'-active':'-inactive')."' href='".wp_slimstat_admin::$admin_url.($a_panel_id+1).$filters_no_starting."'>$a_panel_name</a>";
		} ?>
	</p>
	
	<form action="<?php echo wp_slimstat_boxes::fs_url(); ?>" method="post" name="setslimstatfilters"
		onsubmit="if (this.year.value == '<?php _e('Year','wp-slimstat-view') ?>') this.year.value = ''">
		<p><span class='inline-help' style='float:left;margin:8px 5px' title='<?php _e('Please refer to the contextual help (available on WP 3.3+) for more information on what these filters mean.','wp-slimstat-view') ?>'></span>
		<span><?php _e('Show records where','wp-slimstat-view') ?>
			<select name="f" id="filter" style="width:9em">
				<option value="no-filter-selected-1">&nbsp;</option>
				<option value="browser"><?php _e('Browser','wp-slimstat-view') ?></option>
				<option value="country"><?php _e('Country Code','wp-slimstat-view') ?></option>
				<option value="domain"><?php _e('Referring Domain','wp-slimstat-view') ?></option>
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
			<select name="o" id="operator" style="width:9em" onchange="if(this.value=='is_empty'||this.value=='is_not_empty'){document.getElementById('value').disabled=true;} else {document.getElementById('value').disabled=false;}">
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
			<input type="text" name="v" id="value" value="" size="12">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span><?php _e('Filter by date','wp-slimstat-view') ?> <select name="day">
				<option value=""><?php _e('Day','wp-slimstat-view') ?></option><?php
				for($i=1;$i<=31;$i++){
					if(!empty(wp_slimstat_db::$filters_parsed['day'][1]) && wp_slimstat_db::$filters_parsed['day'][1] == $i)
						echo "<option selected='selected'>$i</option>";
					else
						echo "<option>$i</option>";
				} 
				?></select> 
			<select name="month">
				<option value=""><?php _e('Month','wp-slimstat-view') ?></option><?php
				for($i=1;$i<=12;$i++){
					if(!empty(wp_slimstat_db::$filters_parsed['month'][1]) && wp_slimstat_db::$filters_parsed['month'][1] == $i)
						echo "<option value='$i' selected='selected'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
					else
						echo "<option value='$i'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
				} 
				?>
			</select>
			<input type="text" name="year" size="4" onfocus="if(this.value == '<?php _e('Year','wp-slimstat-view') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('Year','wp-slimstat-view') ?>';"
				value="<?php echo !empty(wp_slimstat_db::$filters_parsed['year'][1])?wp_slimstat_db::$filters_parsed['year'][1]:__('Year','wp-slimstat-view') ?>">
			+ <input type="text" name="interval" value="" size="3" title="<?php _e('days', 'wp-slimstat-view') ?>"> <?php if ($GLOBALS['wp_locale']->text_direction == 'ltr') _e('days', 'wp-slimstat-view') ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
			<span class="go-button"><input type="submit" value="<?php _e('Go','wp-slimstat-view') ?>" class="button-primary">
			<a class="button-primary" href="<?php echo wp_slimstat_boxes::$current_screen_url ?>&fs=<?php echo wp_slimstat_boxes::replace_query_arg('direction', (wp_slimstat_db::$filters_parsed['direction']=='asc')?'desc':'asc') ?>"><?php _e('Reverse Order','wp-slimstat-view') ?></a></span>
		</p>
	</form>
<?php echo "<p class='current-filters'>".wp_slimstat_boxes::get_filters_html(wp_slimstat_db::$filters_parsed).'</p>'; ?>
<div class="meta-box-sortables">
<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_slimstat_boxes::$meta_box_order_nonce ?>" /></form>
<?php if (is_readable(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/panel'.wp_slimstat_boxes::$current_screen.'.php')) require_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/panel'.wp_slimstat_boxes::$current_screen.'.php'); ?>
</div>
<div id="more-dialog" class="widget">
	<h3>Blah Blah</h3>
	<div class="container details" style="height:350px"><p class="header is-known-user"><a class="whois" href="http://www.maxmind.com/app/lookup_city?ips=127.0.0.1" target="_blank" title="WHOIS: 127.0.0.1"></a> <a class="highlight-user" href="admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=user+equals+admin%7C">admin</a> <span>May 22, 2012 4:38 pm</span> <span></span> </p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/index.php?page=wp-slimstat&amp;slimpanel=1&amp;filter=resource&amp;f_operator=contains&amp;f_value=/blog/2012/05/09/hello-world/&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource%20contains%20/blog/2012/05/09/hello-world/%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource%20contains%20/blog/2012/05/09/hello-world/%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource%20contains%20/blog/2012/05/09/hello-world/%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource%20contains%20/blog/2012/05/09/hello-world/%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=content_type+equals+404%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=content_type+equals+404%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=content_type+equals+404%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=content_type+equals+404%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/whois.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/admin.php?page=wp-slimstat&amp;slimpanel=1&amp;fs=resource+contains+%2Fblog%2F2012%2F05%2F09%2Fhello-world%2F%7C&lt;/a&gt;">/wp-content/plugins/wp-slimstat/admin/images/url.gif</p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/edit.php&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/edit.php&lt;/a&gt;">/</p><p class="header is-direct"><a class="whois" href="http://www.maxmind.com/app/lookup_city?ips=127.0.0.1" target="_blank" title="WHOIS: 127.0.0.1"></a> <a href="admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=ip+equals+127.0.0.1%7C">127.0.0.1</a> <span>May 21, 2012 5:43 pm</span> <span></span> </p><p>/</p><p class="header is-known-user"><a class="whois" href="http://www.maxmind.com/app/lookup_city?ips=127.0.0.1" target="_blank" title="WHOIS: 127.0.0.1"></a> <a class="highlight-user" href="admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=user+equals+admin%7C">admin</a> <span>May 21, 2012 5:42 pm</span> <span></span> </p><p title="&lt;a target=&quot;_blank&quot; class=&quot;url&quot; title=&quot;Open this URL in a new window&quot; href=&quot;http://multisite.dev/wp-admin/index.php?page=wp-slimstat&quot;&gt;&lt;/a&gt; &lt;a title=&quot;Filter results where domain equals multisite.dev&quot; href=&quot;admin.php?page=wp-slimstat&amp;slimpanel=2&amp;fs=domain+equals+multisite.dev%7C&quot;&gt;Referer: http://multisite.dev/wp-admin/index.php?page=wp-slimstat&lt;/a&gt;">/</p></div>
</div>
</div>