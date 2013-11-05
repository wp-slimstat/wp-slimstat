<?php

// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Define the tabs
$slimtabs_html = '';
$is_date_filters_set = (count(array_intersect(array_keys(wp_slimstat_db::$filters['parsed']), array('day','month','year'))) > 0);

foreach ( array(
	!$is_date_filters_set?__('Right Now','wp-slimstat'):__('Details','wp-slimstat'),
	__('Overview','wp-slimstat'),
	__('Visitors','wp-slimstat'),
	__('Content','wp-slimstat'),  
	__('Traffic Sources','wp-slimstat'),
	__('World Map','wp-slimstat'), 
	has_action('wp_slimstat_custom_report')?__('Custom Reports','wp-slimstat'):'none'
) as $a_tab_id => $a_tab_name){
	if ($a_tab_name != 'none') $slimtabs_html .= "<a class='slimstat-filter-link nav-tab nav-tab".((wp_slimstat_reports::$current_tab == $a_tab_id+1)?'-active':'-inactive')."' href='".wp_slimstat_reports::fs_url('', wp_slimstat_admin::$view_url.($a_tab_id+1))."'>$a_tab_name</a>";
}

?>

<div class="wrap">
	<h2>WP SlimStat	<?php if (!$is_date_filters_set) echo ' - '.ucfirst(date_i18n('F Y')); ?></h2>
	<p class="nav-tabs"><?php echo $slimtabs_html; $using_screenres = wp_slimstat_admin::check_screenres(); ?></p>
	
	<form action="<?php echo wp_slimstat_reports::fs_url(); ?>" method="post" name="setslimstatfilters" id="slimstat-filters"
		onsubmit="if (this.year.value == '<?php _e('Year','wp-slimstat') ?>') this.year.value = ''; if(this.year.value == '<?php _e('days','wp-slimstat') ?>') this.interval.value = '';">
		<p>
			<span class="nowrap"><?php _e('Show records where','wp-slimstat') ?>
				<span class='inline-help' style='float:left;margin:8px 5px 8px 0' title='<?php _e('Please refer to the contextual help (available on WP 3.3+) for more information on what these filters mean.','wp-slimstat') ?>'></span>
				<select name="f" id="slimstat_filter_name" style="width:9em">
					<option value="no-filter-selected-1">&nbsp;</option>
					<option value="browser"><?php _e('Browser','wp-slimstat') ?></option>
					<option value="country"><?php _e('Country Code','wp-slimstat') ?></option>
					<option value="ip"><?php _e('IP','wp-slimstat') ?></option>
					<option value="searchterms"><?php _e('Search Terms','wp-slimstat') ?></option>
					<option value="language"><?php _e('Language Code','wp-slimstat') ?></option>
					<option value="platform"><?php _e('Operating System','wp-slimstat') ?></option>
					<option value="resource"><?php _e('Permalink','wp-slimstat') ?></option>
					<option value="referer"><?php _e('Referer','wp-slimstat') ?></option>
					<option value="user"><?php _e('Visitor\'s Name','wp-slimstat') ?></option>
					<option value="no-filter-selected-2">&nbsp;</option>
					<option value="no-filter-selected-3"><?php _e('-- Advanced filters --','wp-slimstat') ?></option>
					<option value="plugins"><?php _e('Browser Capabilities','wp-slimstat') ?></option>
					<option value="version"><?php _e('Browser Version','wp-slimstat') ?></option>
					<option value="type"><?php _e('Browser Type','wp-slimstat') ?></option>
					<option value="user_agent"><?php _e('User Agent','wp-slimstat') ?></option>
					<option value="colordepth"><?php _e('Color Depth','wp-slimstat') ?></option>
					<option value="css_version"><?php _e('CSS Version','wp-slimstat') ?></option>
					<option value="notes"><?php _e('Pageview Attributes','wp-slimstat') ?></option>
					<option value="outbound_resource"><?php _e('Outbound Link','wp-slimstat') ?></option>
					<option value="author"><?php _e('Post Author','wp-slimstat') ?></option>
					<option value="category"><?php _e('Post Category ID','wp-slimstat') ?></option>
					<option value="other_ip"><?php _e('Originating IP','wp-slimstat') ?></option>
					<option value="content_type"><?php _e('Resource Content Type','wp-slimstat') ?></option>
					<option value="content_id"><?php _e('Resource ID','wp-slimstat') ?></option>
					<option value="resolution"><?php _e('Screen Resolution','wp-slimstat') ?></option>
					<option value="visit_id"><?php _e('Visit ID','wp-slimstat') ?></option>
				</select>
				<select name="o" id="slimstat_filter_operator" style="width:9em" onchange="if(this.value=='is_empty'||this.value=='is_not_empty'){document.getElementById('slimstat_filter_value').disabled=true;}else{document.getElementById('slimstat_filter_value').disabled=false;}">
					<option value="equals"><?php _e('equals','wp-slimstat') ?></option>
					<option value="is_not_equal_to"><?php _e('is not equal to','wp-slimstat') ?></option>
					<option value="contains"><?php _e('contains','wp-slimstat') ?></option>
					<option value="does_not_contain"><?php _e('does not contain','wp-slimstat') ?></option>
					<option value="starts_with"><?php _e('starts with','wp-slimstat') ?></option>
					<option value="ends_with"><?php _e('ends with','wp-slimstat') ?></option>
					<option value="sounds_like"><?php _e('sounds like','wp-slimstat') ?></option>
					<option value="is_empty"><?php _e('is empty','wp-slimstat') ?></option>
					<option value="is_not_empty"><?php _e('is not empty','wp-slimstat') ?></option>
					<option value="is_greater_than"><?php _e('is greater than','wp-slimstat') ?></option>
					<option value="is_less_than"><?php _e('is less than','wp-slimstat') ?></option>
					<option value="matches"><?php _e('matches','wp-slimstat') ?></option>
					<option value="does_not_match"><?php _e('does not match','wp-slimstat') ?></option>
				</select>
				<input type="text" name="v" id="slimstat_filter_value" value="" size="12">
			</span>
			<span class="nowrap">
				<span class='inline-help' style='float:left;margin:8px 5px 8px 0' title='<?php _e('Select a day to make the interval field appear.','wp-slimstat') ?>'></span>
				<?php _e('Filter by date','wp-slimstat') ?>
				
				<select name="day" id="slimstat_filter_day" onchange="if(this.value>0){document.getElementById('slimstat_interval_block').style.display='inline'}else{document.getElementById('slimstat_interval_block').style.display='none'}">
					<option value="0"><?php _e('Day','wp-slimstat') ?></option><?php
					for($i=1;$i<=31;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['day'][1]) && wp_slimstat_db::$filters['parsed']['day'][1] == $i)
							echo "<option selected='selected'>$i</option>";
						else
							echo "<option>$i</option>";
					} 
					?></select> 
				<select name="month" id="slimstat_filter_month">
					<option value=""><?php _e('Month','wp-slimstat') ?></option><?php
					for($i=1;$i<=12;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['month'][1]) && wp_slimstat_db::$filters['parsed']['month'][1] == $i)
							echo "<option value='$i' selected='selected'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
						else
							echo "<option value='$i'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
					} 
					?></select>
				<input type="text" name="year" id="slimstat_filter_year" size="4" onfocus="if(this.value == '<?php _e('Year','wp-slimstat') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('Year','wp-slimstat') ?>';"
					value="<?php echo !empty(wp_slimstat_db::$filters['parsed']['year'][1])?wp_slimstat_db::$filters['parsed']['year'][1]:__('Year','wp-slimstat') ?>">
				
				<span id="slimstat_interval_block"<?php if (!empty(wp_slimstat_db::$filters['parsed']['day'][1])) echo ' style="display:inline"' ?>>+ <input type="text" name="interval"  id="slimstat_filter_interval" size="4" value="<?php _e('days', 'wp-slimstat') ?>" onfocus="if(this.value == '<?php _e('days','wp-slimstat') ?>') this.value = '';" onblur="if(this.value == '') this.value = '<?php _e('days','wp-slimstat') ?>';"> &nbsp;&nbsp;&nbsp;</span>
			</span>
			<input type="hidden" class="slimstat-filter-date" name="filter_date" value=""/>
			<?php foreach(wp_slimstat_db::$filters['parsed'] as $a_key => $a_details): ?>
			<input type="hidden" name="fs[<?php echo $a_key ?>]" value="<?php echo $a_details[0].' '.$a_details[1] ?>"/>
			<?php endforeach ?>
			<input type="submit" value="<?php _e('Go','wp-slimstat') ?>" class="button-primary">
		</p>
	</form>
	<?php echo '<p class="current-filters">'.wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters['parsed']).'</p>'; ?>
	<div class="meta-box-sortables">
		<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_slimstat_reports::$meta_report_order_nonce ?>" /></form><?php

		switch(wp_slimstat_reports::$current_tab){
			case 1:
				include_once(dirname(__FILE__).'/right-now.php');
				break;
			case 6:
				// Unset any limits
				wp_slimstat_db::$filters['parsed']['limit_results'][1] = 9999;
				$countries = wp_slimstat_db::get_popular('t1.country');
				$total_count = wp_slimstat_db::count_records('1=1', '*');
				$data_areas = array('XX'=>'{id:"XX",balloonText:"'.__('c-xx','wp-slimstat').': 0",value:0,color:"#ededed"}','AF'=>'{id:"AF",balloonText:"'.__('c-af','wp-slimstat').': 0",value:0,color:"#ededed"}','AX'=>'{id:"AX",balloonText:"'.__('c-ax','wp-slimstat').': 0",value:0,color:"#ededed"}','AL'=>'{id:"AL",balloonText:"'.__('c-al','wp-slimstat').': 0",value:0,color:"#ededed"}','DZ'=>'{id:"DZ",balloonText:"'.__('c-dz','wp-slimstat').': 0",value:0,color:"#ededed"}','AD'=>'{id:"AD",balloonText:"'.__('c-ad','wp-slimstat').': 0",value:0,color:"#ededed"}','AO'=>'{id:"AO",balloonText:"'.__('c-ao','wp-slimstat').': 0",value:0,color:"#ededed"}','AI'=>'{id:"AI",balloonText:"'.__('c-ai','wp-slimstat').': 0",value:0,color:"#ededed"}','AG'=>'{id:"AG",balloonText:"'.__('c-ag','wp-slimstat').': 0",value:0,color:"#ededed"}','AR'=>'{id:"AR",balloonText:"'.__('c-ar','wp-slimstat').': 0",value:0,color:"#ededed"}','AM'=>'{id:"AM",balloonText:"'.__('c-am','wp-slimstat').': 0",value:0,color:"#ededed"}','AW'=>'{id:"AW",balloonText:"'.__('c-aw','wp-slimstat').': 0",value:0,color:"#ededed"}','AU'=>'{id:"AU",balloonText:"'.__('c-au','wp-slimstat').': 0",value:0,color:"#ededed"}','AT'=>'{id:"AT",balloonText:"'.__('c-at','wp-slimstat').': 0",value:0,color:"#ededed"}','AZ'=>'{id:"AZ",balloonText:"'.__('c-az','wp-slimstat').': 0",value:0,color:"#ededed"}','BS'=>'{id:"BS",balloonText:"'.__('c-bs','wp-slimstat').': 0",value:0,color:"#ededed"}','BH'=>'{id:"BH",balloonText:"'.__('c-bh','wp-slimstat').': 0",value:0,color:"#ededed"}','BD'=>'{id:"BD",balloonText:"'.__('c-bd','wp-slimstat').': 0",value:0,color:"#ededed"}','BB'=>'{id:"BB",balloonText:"'.__('c-bb','wp-slimstat').': 0",value:0,color:"#ededed"}','BY'=>'{id:"BY",balloonText:"'.__('c-by','wp-slimstat').': 0",value:0,color:"#ededed"}','BE'=>'{id:"BE",balloonText:"'.__('c-be','wp-slimstat').': 0",value:0,color:"#ededed"}','BZ'=>'{id:"BZ",balloonText:"'.__('c-bz','wp-slimstat').': 0",value:0,color:"#ededed"}','BJ'=>'{id:"BJ",balloonText:"'.__('c-bj','wp-slimstat').': 0",value:0,color:"#ededed"}','BM'=>'{id:"BM",balloonText:"'.__('c-bm','wp-slimstat').': 0",value:0,color:"#ededed"}','BT'=>'{id:"BT",balloonText:"'.__('c-bt','wp-slimstat').': 0",value:0,color:"#ededed"}','BO'=>'{id:"BO",balloonText:"'.__('c-bo','wp-slimstat').': 0",value:0,color:"#ededed"}','BA'=>'{id:"BA",balloonText:"'.__('c-ba','wp-slimstat').': 0",value:0,color:"#ededed"}','BW'=>'{id:"BW",balloonText:"'.__('c-bw','wp-slimstat').': 0",value:0,color:"#ededed"}','BR'=>'{id:"BR",balloonText:"'.__('c-br','wp-slimstat').': 0",value:0,color:"#ededed"}','BN'=>'{id:"BN",balloonText:"'.__('c-bn','wp-slimstat').': 0",value:0,color:"#ededed"}','BG'=>'{id:"BG",balloonText:"'.__('c-bg','wp-slimstat').': 0",value:0,color:"#ededed"}','BF'=>'{id:"BF",balloonText:"'.__('c-bf','wp-slimstat').': 0",value:0,color:"#ededed"}','BI'=>'{id:"BI",balloonText:"'.__('c-bi','wp-slimstat').': 0",value:0,color:"#ededed"}','KH'=>'{id:"KH",balloonText:"'.__('c-kh','wp-slimstat').': 0",value:0,color:"#ededed"}','CM'=>'{id:"CM",balloonText:"'.__('c-cm','wp-slimstat').': 0",value:0,color:"#ededed"}','CA'=>'{id:"CA",balloonText:"'.__('c-ca','wp-slimstat').': 0",value:0,color:"#ededed"}','CV'=>'{id:"CV",balloonText:"'.__('c-cv','wp-slimstat').': 0",value:0,color:"#ededed"}','KY'=>'{id:"KY",balloonText:"'.__('c-ky','wp-slimstat').': 0",value:0,color:"#ededed"}','CF'=>'{id:"CF",balloonText:"'.__('c-cf','wp-slimstat').': 0",value:0,color:"#ededed"}','TD'=>'{id:"TD",balloonText:"'.__('c-td','wp-slimstat').': 0",value:0,color:"#ededed"}','CL'=>'{id:"CL",balloonText:"'.__('c-cl','wp-slimstat').': 0",value:0,color:"#ededed"}','CN'=>'{id:"CN",balloonText:"'.__('c-cn','wp-slimstat').': 0",value:0,color:"#ededed"}','CO'=>'{id:"CO",balloonText:"'.__('c-co','wp-slimstat').': 0",value:0,color:"#ededed"}','KM'=>'{id:"KM",balloonText:"'.__('c-km','wp-slimstat').': 0",value:0,color:"#ededed"}','CG'=>'{id:"CG",balloonText:"'.__('c-cg','wp-slimstat').': 0",value:0,color:"#ededed"}','CD'=>'{id:"CD",balloonText:"'.__('c-cd','wp-slimstat').': 0",value:0,color:"#ededed"}','CR'=>'{id:"CR",balloonText:"'.__('c-cr','wp-slimstat').': 0",value:0,color:"#ededed"}','CI'=>'{id:"CI",balloonText:"'.__('c-ci','wp-slimstat').': 0",value:0,color:"#ededed"}','HR'=>'{id:"HR",balloonText:"'.__('c-hr','wp-slimstat').': 0",value:0,color:"#ededed"}','CU'=>'{id:"CU",balloonText:"'.__('c-cu','wp-slimstat').': 0",value:0,color:"#ededed"}','CY'=>'{id:"CY",balloonText:"'.__('c-cy','wp-slimstat').': 0",value:0,color:"#ededed"}','CZ'=>'{id:"CZ",balloonText:"'.__('c-cz','wp-slimstat').': 0",value:0,color:"#ededed"}','DK'=>'{id:"DK",balloonText:"'.__('c-dk','wp-slimstat').': 0",value:0,color:"#ededed"}','DJ'=>'{id:"DJ",balloonText:"'.__('c-dj','wp-slimstat').': 0",value:0,color:"#ededed"}','DM'=>'{id:"DM",balloonText:"'.__('c-dm','wp-slimstat').': 0",value:0,color:"#ededed"}','DO'=>'{id:"DO",balloonText:"'.__('c-do','wp-slimstat').': 0",value:0,color:"#ededed"}','EC'=>'{id:"EC",balloonText:"'.__('c-ec','wp-slimstat').': 0",value:0,color:"#ededed"}','EG'=>'{id:"EG",balloonText:"'.__('c-eg','wp-slimstat').': 0",value:0,color:"#ededed"}','SV'=>'{id:"SV",balloonText:"'.__('c-sv','wp-slimstat').': 0",value:0,color:"#ededed"}','GQ'=>'{id:"GQ",balloonText:"'.__('c-gq','wp-slimstat').': 0",value:0,color:"#ededed"}','ER'=>'{id:"ER",balloonText:"'.__('c-er','wp-slimstat').': 0",value:0,color:"#ededed"}','EE'=>'{id:"EE",balloonText:"'.__('c-ee','wp-slimstat').': 0",value:0,color:"#ededed"}','ET'=>'{id:"ET",balloonText:"'.__('c-et','wp-slimstat').': 0",value:0,color:"#ededed"}','FO'=>'{id:"FO",balloonText:"'.__('c-fo','wp-slimstat').': 0",value:0,color:"#ededed"}','FK'=>'{id:"FK",balloonText:"'.__('c-fk','wp-slimstat').': 0",value:0,color:"#ededed"}','FJ'=>'{id:"FJ",balloonText:"'.__('c-fj','wp-slimstat').': 0",value:0,color:"#ededed"}','FI'=>'{id:"FI",balloonText:"'.__('c-fi','wp-slimstat').': 0",value:0,color:"#ededed"}','FR'=>'{id:"FR",balloonText:"'.__('c-fr','wp-slimstat').': 0",value:0,color:"#ededed"}','GF'=>'{id:"GF",balloonText:"'.__('c-gf','wp-slimstat').': 0",value:0,color:"#ededed"}','GA'=>'{id:"GA",balloonText:"'.__('c-ga','wp-slimstat').': 0",value:0,color:"#ededed"}','GM'=>'{id:"GM",balloonText:"'.__('c-gm','wp-slimstat').': 0",value:0,color:"#ededed"}','GE'=>'{id:"GE",balloonText:"'.__('c-ge','wp-slimstat').': 0",value:0,color:"#ededed"}','DE'=>'{id:"DE",balloonText:"'.__('c-de','wp-slimstat').': 0",value:0,color:"#ededed"}','GH'=>'{id:"GH",balloonText:"'.__('c-gh','wp-slimstat').': 0",value:0,color:"#ededed"}','GR'=>'{id:"GR",balloonText:"'.__('c-gr','wp-slimstat').': 0",value:0,color:"#ededed"}','GL'=>'{id:"GL",balloonText:"'.__('c-gl','wp-slimstat').': 0",value:0,color:"#ededed"}','GD'=>'{id:"GD",balloonText:"'.__('c-gd','wp-slimstat').': 0",value:0,color:"#ededed"}','GP'=>'{id:"GP",balloonText:"'.__('c-gp','wp-slimstat').': 0",value:0,color:"#ededed"}','GT'=>'{id:"GT",balloonText:"'.__('c-gt','wp-slimstat').': 0",value:0,color:"#ededed"}','GN'=>'{id:"GN",balloonText:"'.__('c-gn','wp-slimstat').': 0",value:0,color:"#ededed"}','GW'=>'{id:"GW",balloonText:"'.__('c-gw','wp-slimstat').': 0",value:0,color:"#ededed"}','GY'=>'{id:"GY",balloonText:"'.__('c-gy','wp-slimstat').': 0",value:0,color:"#ededed"}','HT'=>'{id:"HT",balloonText:"'.__('c-ht','wp-slimstat').': 0",value:0,color:"#ededed"}','HN'=>'{id:"HN",balloonText:"'.__('c-hn','wp-slimstat').': 0",value:0,color:"#ededed"}','HK'=>'{id:"HK",balloonText:"'.__('c-hk','wp-slimstat').': 0",value:0,color:"#ededed"}','HU'=>'{id:"HU",balloonText:"'.__('c-hu','wp-slimstat').': 0",value:0,color:"#ededed"}','IS'=>'{id:"IS",balloonText:"'.__('c-is','wp-slimstat').': 0",value:0,color:"#ededed"}','IN'=>'{id:"IN",balloonText:"'.__('c-in','wp-slimstat').': 0",value:0,color:"#ededed"}','ID'=>'{id:"ID",balloonText:"'.__('c-id','wp-slimstat').': 0",value:0,color:"#ededed"}','IR'=>'{id:"IR",balloonText:"'.__('c-ir','wp-slimstat').': 0",value:0,color:"#ededed"}','IQ'=>'{id:"IQ",balloonText:"'.__('c-iq','wp-slimstat').': 0",value:0,color:"#ededed"}','IE'=>'{id:"IE",balloonText:"'.__('c-ie','wp-slimstat').': 0",value:0,color:"#ededed"}','IL'=>'{id:"IL",balloonText:"'.__('c-il','wp-slimstat').': 0",value:0,color:"#ededed"}','IT'=>'{id:"IT",balloonText:"'.__('c-it','wp-slimstat').': 0",value:0,color:"#ededed"}','JM'=>'{id:"JM",balloonText:"'.__('c-jm','wp-slimstat').': 0",value:0,color:"#ededed"}','JP'=>'{id:"JP",balloonText:"'.__('c-jp','wp-slimstat').': 0",value:0,color:"#ededed"}','JO'=>'{id:"JO",balloonText:"'.__('c-jo','wp-slimstat').': 0",value:0,color:"#ededed"}','KZ'=>'{id:"KZ",balloonText:"'.__('c-kz','wp-slimstat').': 0",value:0,color:"#ededed"}','KE'=>'{id:"KE",balloonText:"'.__('c-ke','wp-slimstat').': 0",value:0,color:"#ededed"}','NR'=>'{id:"NR",balloonText:"'.__('c-nr','wp-slimstat').': 0",value:0,color:"#ededed"}','KP'=>'{id:"KP",balloonText:"'.__('c-kp','wp-slimstat').': 0",value:0,color:"#ededed"}','KR'=>'{id:"KR",balloonText:"'.__('c-kr','wp-slimstat').': 0",value:0,color:"#ededed"}','KV'=>'{id:"KV",balloonText:"'.__('c-kv','wp-slimstat').': 0",value:0,color:"#ededed"}','KW'=>'{id:"KW",balloonText:"'.__('c-kw','wp-slimstat').': 0",value:0,color:"#ededed"}','KG'=>'{id:"KG",balloonText:"'.__('c-kg','wp-slimstat').': 0",value:0,color:"#ededed"}','LA'=>'{id:"LA",balloonText:"'.__('c-la','wp-slimstat').': 0",value:0,color:"#ededed"}','LV'=>'{id:"LV",balloonText:"'.__('c-lv','wp-slimstat').': 0",value:0,color:"#ededed"}','LB'=>'{id:"LB",balloonText:"'.__('c-lb','wp-slimstat').': 0",value:0,color:"#ededed"}','LS'=>'{id:"LS",balloonText:"'.__('c-ls','wp-slimstat').': 0",value:0,color:"#ededed"}','LR'=>'{id:"LR",balloonText:"'.__('c-lr','wp-slimstat').': 0",value:0,color:"#ededed"}','LY'=>'{id:"LY",balloonText:"'.__('c-ly','wp-slimstat').': 0",value:0,color:"#ededed"}','LI'=>'{id:"LI",balloonText:"'.__('c-li','wp-slimstat').': 0",value:0,color:"#ededed"}','LT'=>'{id:"LT",balloonText:"'.__('c-lt','wp-slimstat').': 0",value:0,color:"#ededed"}','LU'=>'{id:"LU",balloonText:"'.__('c-lu','wp-slimstat').': 0",value:0,color:"#ededed"}','MK'=>'{id:"MK",balloonText:"'.__('c-mk','wp-slimstat').': 0",value:0,color:"#ededed"}','MG'=>'{id:"MG",balloonText:"'.__('c-mg','wp-slimstat').': 0",value:0,color:"#ededed"}','MW'=>'{id:"MW",balloonText:"'.__('c-mw','wp-slimstat').': 0",value:0,color:"#ededed"}','MY'=>'{id:"MY",balloonText:"'.__('c-my','wp-slimstat').': 0",value:0,color:"#ededed"}','ML'=>'{id:"ML",balloonText:"'.__('c-ml','wp-slimstat').': 0",value:0,color:"#ededed"}','MT'=>'{id:"MT",balloonText:"'.__('c-mt','wp-slimstat').': 0",value:0,color:"#ededed"}','MQ'=>'{id:"MQ",balloonText:"'.__('c-mq','wp-slimstat').': 0",value:0,color:"#ededed"}','MR'=>'{id:"MR",balloonText:"'.__('c-mr','wp-slimstat').': 0",value:0,color:"#ededed"}','MU'=>'{id:"MU",balloonText:"'.__('c-mu','wp-slimstat').': 0",value:0,color:"#ededed"}','MX'=>'{id:"MX",balloonText:"'.__('c-mx','wp-slimstat').': 0",value:0,color:"#ededed"}','MD'=>'{id:"MD",balloonText:"'.__('c-md','wp-slimstat').': 0",value:0,color:"#ededed"}','MN'=>'{id:"MN",balloonText:"'.__('c-mn','wp-slimstat').': 0",value:0,color:"#ededed"}','ME'=>'{id:"ME",balloonText:"'.__('c-me','wp-slimstat').': 0",value:0,color:"#ededed"}','MS'=>'{id:"MS",balloonText:"'.__('c-ms','wp-slimstat').': 0",value:0,color:"#ededed"}','MA'=>'{id:"MA",balloonText:"'.__('c-ma','wp-slimstat').': 0",value:0,color:"#ededed"}','MZ'=>'{id:"MZ",balloonText:"'.__('c-mz','wp-slimstat').': 0",value:0,color:"#ededed"}','MM'=>'{id:"MM",balloonText:"'.__('c-mm','wp-slimstat').': 0",value:0,color:"#ededed"}','NA'=>'{id:"NA",balloonText:"'.__('c-na','wp-slimstat').': 0",value:0,color:"#ededed"}','NR'=>'{id:"NR",balloonText:"'.__('c-nr','wp-slimstat').': 0",value:0,color:"#ededed"}','NP'=>'{id:"NP",balloonText:"'.__('c-np','wp-slimstat').': 0",value:0,color:"#ededed"}','NL'=>'{id:"NL",balloonText:"'.__('c-nl','wp-slimstat').': 0",value:0,color:"#ededed"}','NC'=>'{id:"NC",balloonText:"'.__('c-nc','wp-slimstat').': 0",value:0,color:"#ededed"}','NZ'=>'{id:"NZ",balloonText:"'.__('c-nz','wp-slimstat').': 0",value:0,color:"#ededed"}','NI'=>'{id:"NI",balloonText:"'.__('c-ni','wp-slimstat').': 0",value:0,color:"#ededed"}','NE'=>'{id:"NE",balloonText:"'.__('c-ne','wp-slimstat').': 0",value:0,color:"#ededed"}','NG'=>'{id:"NG",balloonText:"'.__('c-ng','wp-slimstat').': 0",value:0,color:"#ededed"}','NO'=>'{id:"NO",balloonText:"'.__('c-no','wp-slimstat').': 0",value:0,color:"#ededed"}','OM'=>'{id:"OM",balloonText:"'.__('c-om','wp-slimstat').': 0",value:0,color:"#ededed"}','PK'=>'{id:"PK",balloonText:"'.__('c-pk','wp-slimstat').': 0",value:0,color:"#ededed"}','PW'=>'{id:"PW",balloonText:"'.__('c-pw','wp-slimstat').': 0",value:0,color:"#ededed"}','PS'=>'{id:"PS",balloonText:"'.__('c-ps','wp-slimstat').': 0",value:0,color:"#ededed"}','PA'=>'{id:"PA",balloonText:"'.__('c-pa','wp-slimstat').': 0",value:0,color:"#ededed"}','PG'=>'{id:"PG",balloonText:"'.__('c-pg','wp-slimstat').': 0",value:0,color:"#ededed"}','PY'=>'{id:"PY",balloonText:"'.__('c-py','wp-slimstat').': 0",value:0,color:"#ededed"}','PE'=>'{id:"PE",balloonText:"'.__('c-pe','wp-slimstat').': 0",value:0,color:"#ededed"}','PH'=>'{id:"PH",balloonText:"'.__('c-ph','wp-slimstat').': 0",value:0,color:"#ededed"}','PL'=>'{id:"PL",balloonText:"'.__('c-pl','wp-slimstat').': 0",value:0,color:"#ededed"}','PT'=>'{id:"PT",balloonText:"'.__('c-pt','wp-slimstat').': 0",value:0,color:"#ededed"}','PR'=>'{id:"PR",balloonText:"'.__('c-pr','wp-slimstat').': 0",value:0,color:"#ededed"}','QA'=>'{id:"QA",balloonText:"'.__('c-qa','wp-slimstat').': 0",value:0,color:"#ededed"}','RE'=>'{id:"RE",balloonText:"'.__('c-re','wp-slimstat').': 0",value:0,color:"#ededed"}','RO'=>'{id:"RO",balloonText:"'.__('c-ro','wp-slimstat').': 0",value:0,color:"#ededed"}','RU'=>'{id:"RU",balloonText:"'.__('c-ru','wp-slimstat').': 0",value:0,color:"#ededed"}','RW'=>'{id:"RW",balloonText:"'.__('c-rw','wp-slimstat').': 0",value:0,color:"#ededed"}','KN'=>'{id:"KN",balloonText:"'.__('c-kn','wp-slimstat').': 0",value:0,color:"#ededed"}','LC'=>'{id:"LC",balloonText:"'.__('c-lc','wp-slimstat').': 0",value:0,color:"#ededed"}','MF'=>'{id:"MF",balloonText:"'.__('c-mf','wp-slimstat').': 0",value:0,color:"#ededed"}','VC'=>'{id:"VC",balloonText:"'.__('c-vc','wp-slimstat').': 0",value:0,color:"#ededed"}','WS'=>'{id:"WS",balloonText:"'.__('c-ws','wp-slimstat').': 0",value:0,color:"#ededed"}','ST'=>'{id:"ST",balloonText:"'.__('c-st','wp-slimstat').': 0",value:0,color:"#ededed"}','SA'=>'{id:"SA",balloonText:"'.__('c-sa','wp-slimstat').': 0",value:0,color:"#ededed"}','SN'=>'{id:"SN",balloonText:"'.__('c-sn','wp-slimstat').': 0",value:0,color:"#ededed"}','RS'=>'{id:"RS",balloonText:"'.__('c-rs','wp-slimstat').': 0",value:0,color:"#ededed"}','SL'=>'{id:"SL",balloonText:"'.__('c-sl','wp-slimstat').': 0",value:0,color:"#ededed"}','SG'=>'{id:"SG",balloonText:"'.__('c-sg','wp-slimstat').': 0",value:0,color:"#ededed"}','SK'=>'{id:"SK",balloonText:"'.__('c-sk','wp-slimstat').': 0",value:0,color:"#ededed"}','SI'=>'{id:"SI",balloonText:"'.__('c-si','wp-slimstat').': 0",value:0,color:"#ededed"}','SB'=>'{id:"SB",balloonText:"'.__('c-sb','wp-slimstat').': 0",value:0,color:"#ededed"}','SO'=>'{id:"SO",balloonText:"'.__('c-so','wp-slimstat').': 0",value:0,color:"#ededed"}','ZA'=>'{id:"ZA",balloonText:"'.__('c-za','wp-slimstat').': 0",value:0,color:"#ededed"}','GS'=>'{id:"GS",balloonText:"'.__('c-gs','wp-slimstat').': 0",value:0,color:"#ededed"}','ES'=>'{id:"ES",balloonText:"'.__('c-es','wp-slimstat').': 0",value:0,color:"#ededed"}','LK'=>'{id:"LK",balloonText:"'.__('c-lk','wp-slimstat').': 0",value:0,color:"#ededed"}','SD'=>'{id:"SD",balloonText:"'.__('c-sd','wp-slimstat').': 0",value:0,color:"#ededed"}','SS'=>'{id:"SS",balloonText:"'.__('c-ss','wp-slimstat').': 0",value:0,color:"#ededed"}','SR'=>'{id:"SR",balloonText:"'.__('c-sr','wp-slimstat').': 0",value:0,color:"#ededed"}','SJ'=>'{id:"SJ",balloonText:"'.__('c-sj','wp-slimstat').': 0",value:0,color:"#ededed"}','SZ'=>'{id:"SZ",balloonText:"'.__('c-sz','wp-slimstat').': 0",value:0,color:"#ededed"}','SE'=>'{id:"SE",balloonText:"'.__('c-se','wp-slimstat').': 0",value:0,color:"#ededed"}','CH'=>'{id:"CH",balloonText:"'.__('c-ch','wp-slimstat').': 0",value:0,color:"#ededed"}','SY'=>'{id:"SY",balloonText:"'.__('c-sy','wp-slimstat').': 0",value:0,color:"#ededed"}','TW'=>'{id:"TW",balloonText:"'.__('c-tw','wp-slimstat').': 0",value:0,color:"#ededed"}','TJ'=>'{id:"TJ",balloonText:"'.__('c-tj','wp-slimstat').': 0",value:0,color:"#ededed"}','TZ'=>'{id:"TZ",balloonText:"'.__('c-tz','wp-slimstat').': 0",value:0,color:"#ededed"}','TH'=>'{id:"TH",balloonText:"'.__('c-th','wp-slimstat').': 0",value:0,color:"#ededed"}','TL'=>'{id:"TL",balloonText:"'.__('c-tl','wp-slimstat').': 0",value:0,color:"#ededed"}','TG'=>'{id:"TG",balloonText:"'.__('c-tg','wp-slimstat').': 0",value:0,color:"#ededed"}','TO'=>'{id:"TO",balloonText:"'.__('c-to','wp-slimstat').': 0",value:0,color:"#ededed"}','TT'=>'{id:"TT",balloonText:"'.__('c-tt','wp-slimstat').': 0",value:0,color:"#ededed"}','TN'=>'{id:"TN",balloonText:"'.__('c-tn','wp-slimstat').': 0",value:0,color:"#ededed"}','TR'=>'{id:"TR",balloonText:"'.__('c-tr','wp-slimstat').': 0",value:0,color:"#ededed"}','TM'=>'{id:"TM",balloonText:"'.__('c-tm','wp-slimstat').': 0",value:0,color:"#ededed"}','TC'=>'{id:"TC",balloonText:"'.__('c-tc','wp-slimstat').': 0",value:0,color:"#ededed"}','UG'=>'{id:"UG",balloonText:"'.__('c-ug','wp-slimstat').': 0",value:0,color:"#ededed"}','UA'=>'{id:"UA",balloonText:"'.__('c-ua','wp-slimstat').': 0",value:0,color:"#ededed"}','AE'=>'{id:"AE",balloonText:"'.__('c-ae','wp-slimstat').': 0",value:0,color:"#ededed"}','GB'=>'{id:"GB",balloonText:"'.__('c-gb','wp-slimstat').': 0",value:0,color:"#ededed"}','US'=>'{id:"US",balloonText:"'.__('c-us','wp-slimstat').': 0",value:0,color:"#ededed"}','UY'=>'{id:"UY",balloonText:"'.__('c-uy','wp-slimstat').': 0",value:0,color:"#ededed"}','UZ'=>'{id:"UZ",balloonText:"'.__('c-uz','wp-slimstat').': 0",value:0,color:"#ededed"}','VU'=>'{id:"VU",balloonText:"'.__('c-vu','wp-slimstat').': 0",value:0,color:"#ededed"}','VE'=>'{id:"VE",balloonText:"'.__('c-ve','wp-slimstat').': 0",value:0,color:"#ededed"}','VN'=>'{id:"VN",balloonText:"'.__('c-vn','wp-slimstat').': 0",value:0,color:"#ededed"}','VG'=>'{id:"VG",balloonText:"'.__('c-vg','wp-slimstat').': 0",value:0,color:"#ededed"}','VI'=>'{id:"VI",balloonText:"'.__('c-vi','wp-slimstat').': 0",value:0,color:"#ededed"}','EH'=>'{id:"EH",balloonText:"'.__('c-eh','wp-slimstat').': 0",value:0,color:"#ededed"}','YE'=>'{id:"YE",balloonText:"'.__('c-ye','wp-slimstat').': 0",value:0,color:"#ededed"}','ZM'=>'{id:"ZM",balloonText:"'.__('c-zm','wp-slimstat').': 0",value:0,color:"#ededed"}','ZW'=>'{id:"ZW",balloonText:"'.__('c-zw','wp-slimstat').': 0",value:0,color:"#ededed"}','GG'=>'{id:"GG",balloonText:"'.__('c-gg','wp-slimstat').': 0",value:0,color:"#ededed"}','JE'=>'{id:"JE",balloonText:"'.__('c-je','wp-slimstat').': 0",value:0,color:"#ededed"}','IM'=>'{id:"IM",balloonText:"'.__('c-im','wp-slimstat').': 0",value:0,color:"#ededed"}','MV'=>'{id:"MV",balloonText:"'.__('c-mv','wp-slimstat').': 0",value:0,color:"#ededed"}');

				foreach($countries as $a_country){
					if (!array_key_exists(strtoupper($a_country['country']), $data_areas)) continue;

					$percentage = sprintf("%01.2f", (100*$a_country['count']/$total_count));
					$percentage_format = ($total_count > 0)?number_format($percentage, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
					$balloon_text = __('c-'.$a_country['country'], 'wp-slimstat').': '.$percentage_format.'% ('.number_format($a_country['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).')';
					$data_areas[$a_country['country']] = '{id:"'.strtoupper($a_country['country']).'",balloonText:"'.$balloon_text.'",value:'.$percentage.'}';
				}
				?>

				<div class="postbox tall">
					<h3 class="hndle"><?php _e('World Map', 'wp-slimstat'); ?></h3>
					<div class="container" style="height:605px" id="slimstat-world-map">
						<p class="nodata"><?php _e('No data to display','wp-slimstat') ?></p>
					</div>
				</div>
				<script src="<?php echo plugins_url('/js/ammap/ammap.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
				<script src="<?php echo plugins_url('/js/ammap/world.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
				<script type="text/javascript">
				AmCharts.ready(function(){
					var dataProvider = {
						mapVar: AmCharts.maps.worldLow,
						getAreasFromMap:true,
						areas:[<?php echo implode(',', $data_areas) ?>]
					}; 

					// Create AmMap object
					var map = new AmCharts.AmMap();
					var legend = new AmCharts.ValueLegend();
					legend.height = 20;
					legend.minValue = "min";
					legend.maxValue = "max";
					legend.showAsGradient = true;

					// Configuration
					map.areasSettings = {
						autoZoom: true,
						color: "#9dff98",
						colorSolid: "#fa8a50",
						outlineColor: "#666666",
						selectedColor: "#ffb739"
					};
					map.backgroundAlpha = .9;
					map.backgroundColor = "#7adafd";
					map.backgroundZoomsToTop = true;
					map.balloon.color = "#000000";
					map.colorSteps = 20;
					map.mouseWheelZoomEnabled = true;
					map.pathToImages = "<?php echo plugins_url('/js/ammap/images/', dirname(__FILE__)) ?>";
					map.valueLegend = legend;
					
					// Init Data
					map.dataProvider = dataProvider;

					// Display Map
					map.write("slimstat-world-map");
				});
				</script><?php
				break;
			case 7:
				if (!has_action('wp_slimstat_custom_report')){ ?>

				<div class="postbox medium">
					<h3 class="hndle"><?php _e('Your report here', 'wp-slimstat'); ?></h3>
					<div class="container noscroll">
						<p style="padding:10px;line-height:2em;white-space:normal"><?php _e( 'Yes, you can! Create and view your personalized analytics for WP SlimStat. Just write a new plugin that retrieves the desired information from the database and then hook it to the action <code>wp_slimstat_custom_report</code>. For more information, visit my <a href="http://wordpress.org/tags/wp-slimstat?forum_id=10" target="_blank">support forum</a>.', 'wp-slimstat' ); ?></p>
					</div>
				</div>

				<?php
				}
				else {
					do_action('wp_slimstat_custom_report');
				}
				break;
			default:
				foreach(wp_slimstat_reports::$all_reports as $a_box_id){
					switch($a_box_id){
						case 'slim_p1_01':
							wp_slimstat_reports::report_header($a_box_id, wp_slimstat_reports::$chart_tooltip, 'wide chart', false, 'noscroll', wp_slimstat_reports::chart_title(__('Pageviews', 'wp-slimstat')));
							break;
						case 'slim_p1_04':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('When visitors leave a comment on your blog, WordPress assigns them a cookie. WP SlimStat leverages this information to identify returning visitors. Please note that visitors also include registered users.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
							break;
						case 'slim_p1_05':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Take a sneak peek at what human visitors are doing on your website','wp-slimstat'), ENT_QUOTES, 'UTF-8').'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('From a search result page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-known-visitor" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Known Visitor','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Known Users','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Other Humans','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p>', 'medium', true);
							break;
						case 'slim_p1_06':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Keywords used by your visitors to find your website on a search engine','wp-slimstat'), ENT_QUOTES, 'UTF-8'), '', true);
							break;
						case 'slim_p1_07':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Unique sessions initiated by your visitors. If a user is inactive on your site for 30 minutes or more, any future activity will be attributed to a new session. Users that leave your site and return within 30 minutes will be counted as part of the original session.','wp-slimstat'), ENT_QUOTES, 'UTF-8'), '', true);
							break;
						case 'slim_p2_01':
							wp_slimstat_reports::report_header($a_box_id, wp_slimstat_reports::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_reports::chart_title(__('Human Visits', 'wp-slimstat')));
							break;
						case 'slim_p2_03':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows you what languages your users have installed on their computers.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_04':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('A user agent is a generic term for any program used for accessing a website. This includes browsers (such as Chrome), robots and spiders, and any other software program that retrieves information from a website.<br><br>You can ignore any given user agent by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_05':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), 'medium', true);
							break;
						case 'slim_p2_06':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Which operating systems do your visitors use? Optimizing your site for the appropriate technical capabilities helps make your site more engaging and usable and can result in higher conversion rates and more sales.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_07':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows the most common screen resolutions used by your visitors. Knowing the most popular screen resolution of your visitors will help you create content optimized for that resolution or you may opt for resolution-independence.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_09':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("Which versions of Flash do your visitors have installed? Is Java supported on your visitors' platforms?",'wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_10':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_13':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('You can ignore any specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_14':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows the most recent screen resolutions used by your visitors. Knowing the most popular screen resolution of your visitors will help you create content optimized for that resolution or you may opt for resolution-independence.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_15':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Which operating systems do your visitors use? Optimizing your site for the appropriate technical capabilities helps make your site more engaging and usable and can result in higher conversion rates and more sales.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_16':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('A user agent is a generic term for any program used for accessing a website. This includes browsers (such as Chrome), robots and spiders, and any other software program that retrieves information from a website.<br><br>You can ignore any given user agent by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_17':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows you what languages your users have installed on their computers.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_18':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows you what user agent families (no version considered) are popular among your visitors.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_19':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report shows you what operating system families (no version considered) are popular among your visitors.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_20':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('List of registered users who recently visited your website.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p2_21':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('This report lists your most active registered users.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p3_01':
							wp_slimstat_reports::report_header($a_box_id, wp_slimstat_reports::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_reports::chart_title(__('Traffic Sources', 'wp-slimstat')));
							break;
						case 'slim_p3_04':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'), ENT_QUOTES), '', true);
							break;
						case 'slim_p3_08':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Take a sneak peek at what human visitors are doing on your website','wp-slimstat'), ENT_QUOTES, 'UTF-8').'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('From a search result page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-known-visitor" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Known Visitor','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Known Users','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span> '.htmlspecialchars(__('Other Humans','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</p>', 'medium', true);
							break;
						case 'slim_p3_09':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('Keywords used by your visitors to find your website on a search engine','wp-slimstat'), ENT_QUOTES), 'medium', true);
							break;
						case 'slim_p4_01':
							wp_slimstat_reports::report_header($a_box_id, wp_slimstat_reports::$chart_tooltip, 'wide', false, 'noscroll', wp_slimstat_reports::chart_title(__('Average Pageviews per Visit', 'wp-slimstat')));
							break;
						case 'slim_p4_02':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("This report lists the most recent posts viewed on your site, by title.",'wp-slimstat'), ENT_QUOTES), 'medium');
							break;
						case 'slim_p4_03':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.','wp-slimstat'), ENT_QUOTES), 'medium');
							break;
						case 'slim_p4_05':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__('The 404 or Not Found error message is a HTTP standard response code indicating that the client was able to communicate with the server, but the server could not find what was requested.<br><br>This report can be useful to detect attack attempts, by looking at patterns in 404 URLs.','wp-slimstat'), ENT_QUOTES));
							break;
						case 'slim_p4_06':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("Searches performed using Wordpress' built-in search functionality.",'wp-slimstat'), ENT_QUOTES));
							break;
						case 'slim_p4_07':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("Categories provide a helpful way to group related posts together, and to quickly tell readers what a post is about. Categories also make it easier for people to find your content.",'wp-slimstat'), ENT_QUOTES));
							break;
						case 'slim_p4_08':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("<strong>Link Details</strong><br>- <em>A:n</em> means that the n-th link in the page was clicked.<br>- <em>ID:xx</em> is shown when the corresponding link has an ID attribute associated to it.",'wp-slimstat'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat'), ENT_QUOTES).'</p>', 'medium');
							break;
						case 'slim_p4_10':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to leverage this functionality.",'wp-slimstat'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat'), ENT_QUOTES).'</p>', 'medium');
							break;
						case 'slim_p4_11':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("This report lists the most popular posts on your site, by title.",'wp-slimstat'), ENT_QUOTES), 'medium', true);
							break;
						case 'slim_p4_20':
							wp_slimstat_reports::report_header($a_box_id, htmlspecialchars(__("<strong>Link Details</strong><br>- <em>A:n</em> means that the n-th link in the page was clicked.<br>- <em>ID:xx</em> is shown when the corresponding link has an ID attribute associated to it.",'wp-slimstat'), ENT_QUOTES).'<br><br><strong>'.htmlspecialchars(__('Color codes','wp-slimstat'), ENT_QUOTES).'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Known Users','wp-slimstat'), ENT_QUOTES).'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.htmlspecialchars(__('Other Humans','wp-slimstat'), ENT_QUOTES).'</p>', 'medium');
							break;
						case 'slim_p1_09':
						case 'slim_p1_10':
						case 'slim_p1_11':
						case 'slim_p1_12':
						case 'slim_p1_13':
						case 'slim_p2_12':
						case 'slim_p3_03':
						case 'slim_p3_05':
						case 'slim_p3_10':
						case 'slim_p4_13':
						case 'slim_p4_14':
						case 'slim_p4_16':
						case 'slim_p4_18':
						case 'slim_p4_19':
							wp_slimstat_reports::report_header($a_box_id);
							break;
						case 'slim_p1_08':
						case 'slim_p1_14':
						case 'slim_p3_06':
						case 'slim_p3_11':
						case 'slim_p4_04':
						case 'slim_p4_12':
						case 'slim_p4_15':
						case 'slim_p4_17':
						case 'slim_p4_21':
							wp_slimstat_reports::report_header($a_box_id, '', 'medium');
							break;
						case 'slim_p1_02':
						case 'slim_p1_03':
						case 'slim_p2_02': 
						case 'slim_p3_02':
							wp_slimstat_reports::report_header($a_box_id, '', '', false, 'noscroll');
							break;
						default:
							break;
					}
					wp_slimstat_reports::show_report_wrapper($a_box_id);
					wp_slimstat_reports::report_footer();
				}
				break;
		}
	?></div>
</div>
<div id="modal-dialog" class="widget"></div>