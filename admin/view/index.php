<?php if (!function_exists('add_action')) exit(0); ?>

<div class="wrap">
	<h2><?php echo wp_slimstat_reports::$screen_names[wp_slimstat_reports::$current_tab] ?></h2>
	
	<form action="<?php echo wp_slimstat_reports::fs_url(); ?>" method="post" id="slimstat-filters-form">
		<fieldset id="slimstat-filters">					
			<select name="f" id="slimstat-filter-name">
				<?php
					foreach (wp_slimstat_reports::$dropdown_filter_names as $a_filter_label => $a_filter_name){
						echo "<option value='$a_filter_label'>$a_filter_name</option>";
					}
				?>
			</select>

			<select name="o" id="slimstat-filter-operator">
				<option value="equals"><?php _e('equals','wp-slimstat') ?></option>
				<option value="is_not_equal_to"><?php _e('is not equal to','wp-slimstat') ?></option>
				<option value="contains"><?php _e('contains','wp-slimstat') ?></option>
				<option value="does_not_contain"><?php _e('does not contain','wp-slimstat') ?></option>
				<option value="starts_with"><?php _e('starts with','wp-slimstat') ?></option>
				<option value="ends_with"><?php _e('ends with','wp-slimstat') ?></option>
				<option value="sounds_like"><?php _e('sounds like','wp-slimstat') ?></option>
				<option value="is_greater_than"><?php _e('is greater than','wp-slimstat') ?></option>
				<option value="is_less_than"><?php _e('is less than','wp-slimstat') ?></option>
				<option value="matches"><?php _e('matches','wp-slimstat') ?></option>
				<option value="does_not_match"><?php _e('does not match','wp-slimstat') ?></option>
				<option value="is_empty"><?php _e('is empty','wp-slimstat') ?></option>
				<option value="is_not_empty"><?php _e('is not empty','wp-slimstat') ?></option>
			</select>
			<input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20">
			<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary">
		</fieldset><!-- slimstat-filters -->

		<fieldset id="slimstat-date-filters"><a href="#"><?php
			if (wp_slimstat_db::$timeframes['current_day']['hour_selected']){
				echo ucwords(date_i18n(wp_slimstat_db::$formats['date_time_format'], wp_slimstat_db::$timeframes['current_utime_start']).' - '.date_i18n(wp_slimstat_db::$formats['time_format'], wp_slimstat_db::$timeframes['current_utime_end']));
			}
			else if (wp_slimstat_db::$timeframes['current_day']['day_selected'] && (empty(wp_slimstat_db::$filters['parsed']['interval'][1]) || wp_slimstat_db::$filters['parsed']['interval'][1] == 0)){
				echo ucwords(date_i18n(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$timeframes['current_utime_start']));
			}
			else{
				echo ucwords(date_i18n(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$timeframes['current_utime_start']).' - '.date_i18n(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$timeframes['current_utime_end']));
			}			
		?></a>
			<span>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d'))) ?>"><?php _e('Today','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d',mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))))) ?>"><?php _e('Yesterday','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('interval' => 'equals -7')) ?>"><?php _e('Last 7 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('interval' => 'equals -30')) ?>"><?php _e('Last 30 Days','wp-slimstat') ?></a>
				<strong><?php _e('Date Range','wp-slimstat') ?></strong>
				<select name="day" id="slimstat-filter-day">
					<option value="0"><?php _e('Day','wp-slimstat') ?></option><?php
					for($i=1;$i<=31;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['day'][1]) && wp_slimstat_db::$filters['parsed']['day'][1] == $i)
							echo "<option selected='selected'>$i</option>";
						else
							echo "<option>$i</option>";
					} 
					?>
				</select> 
				<select name="month" id="slimstat-filter-month">
					<option value=""><?php _e('Month','wp-slimstat') ?></option><?php
					for($i=1;$i<=12;$i++){
						if(!empty(wp_slimstat_db::$filters['parsed']['month'][1]) && wp_slimstat_db::$filters['parsed']['month'][1] == $i)
							echo "<option value='$i' selected='selected'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
						else
							echo "<option value='$i'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
					} 
					?>
				</select>
				<input type="text" name="year" id="slimstat-filter-year" class="empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters['parsed']['year'][1])?wp_slimstat_db::$filters['parsed']['year'][1]:__('Year','wp-slimstat') ?>">
				<input type="hidden" class="slimstat-filter-date" name="slimstat-filter-date" value=""/>
				<br/>+ <input type="text" name="interval" id="slimstat-filter-interval" class="empty-on-focus" value="<?php _e('days', 'wp-slimstat') ?>">
				<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary">
			</span>
		</fieldset><!-- .slimstat-date-filters -->

		<?php foreach(wp_slimstat_db::$filters['parsed'] as $a_key => $a_details): ?>
		<input type="hidden" name="fs[<?php echo $a_key ?>]" class="slimstat-post-filter" value="<?php echo $a_details[0].' '.$a_details[1] ?>"/>
		<?php endforeach ?>
	</form>

	<?php
		$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters['parsed']);
		if (!empty($filters_html)){
			echo '<div id="slimstat-current-filters">'.wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters['parsed']).'</div>'; 
		}
	?>

	<div class="meta-box-sortables slimstat-sortable">
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
				$data_areas = array('xx'=>'{id:"XX",balloonText:"'.__('c-xx','wp-slimstat').': 0",value:0,color:"#ededed"}','af'=>'{id:"AF",balloonText:"'.__('c-af','wp-slimstat').': 0",value:0,color:"#ededed"}','ax'=>'{id:"AX",balloonText:"'.__('c-ax','wp-slimstat').': 0",value:0,color:"#ededed"}','al'=>'{id:"AL",balloonText:"'.__('c-al','wp-slimstat').': 0",value:0,color:"#ededed"}','dz'=>'{id:"DZ",balloonText:"'.__('c-dz','wp-slimstat').': 0",value:0,color:"#ededed"}','ad'=>'{id:"AD",balloonText:"'.__('c-ad','wp-slimstat').': 0",value:0,color:"#ededed"}','ao'=>'{id:"AO",balloonText:"'.__('c-ao','wp-slimstat').': 0",value:0,color:"#ededed"}','ai'=>'{id:"AI",balloonText:"'.__('c-ai','wp-slimstat').': 0",value:0,color:"#ededed"}','ag'=>'{id:"AG",balloonText:"'.__('c-ag','wp-slimstat').': 0",value:0,color:"#ededed"}','ar'=>'{id:"AR",balloonText:"'.__('c-ar','wp-slimstat').': 0",value:0,color:"#ededed"}','am'=>'{id:"AM",balloonText:"'.__('c-am','wp-slimstat').': 0",value:0,color:"#ededed"}','aw'=>'{id:"AW",balloonText:"'.__('c-aw','wp-slimstat').': 0",value:0,color:"#ededed"}','au'=>'{id:"AU",balloonText:"'.__('c-au','wp-slimstat').': 0",value:0,color:"#ededed"}','at'=>'{id:"AT",balloonText:"'.__('c-at','wp-slimstat').': 0",value:0,color:"#ededed"}','az'=>'{id:"AZ",balloonText:"'.__('c-az','wp-slimstat').': 0",value:0,color:"#ededed"}','bs'=>'{id:"BS",balloonText:"'.__('c-bs','wp-slimstat').': 0",value:0,color:"#ededed"}','bh'=>'{id:"BH",balloonText:"'.__('c-bh','wp-slimstat').': 0",value:0,color:"#ededed"}','bd'=>'{id:"BD",balloonText:"'.__('c-bd','wp-slimstat').': 0",value:0,color:"#ededed"}','bb'=>'{id:"BB",balloonText:"'.__('c-bb','wp-slimstat').': 0",value:0,color:"#ededed"}','by'=>'{id:"BY",balloonText:"'.__('c-by','wp-slimstat').': 0",value:0,color:"#ededed"}','be'=>'{id:"BE",balloonText:"'.__('c-be','wp-slimstat').': 0",value:0,color:"#ededed"}','bz'=>'{id:"BZ",balloonText:"'.__('c-bz','wp-slimstat').': 0",value:0,color:"#ededed"}','bj'=>'{id:"BJ",balloonText:"'.__('c-bj','wp-slimstat').': 0",value:0,color:"#ededed"}','bm'=>'{id:"BM",balloonText:"'.__('c-bm','wp-slimstat').': 0",value:0,color:"#ededed"}','bt'=>'{id:"BT",balloonText:"'.__('c-bt','wp-slimstat').': 0",value:0,color:"#ededed"}','bo'=>'{id:"BO",balloonText:"'.__('c-bo','wp-slimstat').': 0",value:0,color:"#ededed"}','ba'=>'{id:"BA",balloonText:"'.__('c-ba','wp-slimstat').': 0",value:0,color:"#ededed"}','bw'=>'{id:"BW",balloonText:"'.__('c-bw','wp-slimstat').': 0",value:0,color:"#ededed"}','br'=>'{id:"BR",balloonText:"'.__('c-br','wp-slimstat').': 0",value:0,color:"#ededed"}','bn'=>'{id:"BN",balloonText:"'.__('c-bn','wp-slimstat').': 0",value:0,color:"#ededed"}','bg'=>'{id:"BG",balloonText:"'.__('c-bg','wp-slimstat').': 0",value:0,color:"#ededed"}','bf'=>'{id:"BF",balloonText:"'.__('c-bf','wp-slimstat').': 0",value:0,color:"#ededed"}','bi'=>'{id:"BI",balloonText:"'.__('c-bi','wp-slimstat').': 0",value:0,color:"#ededed"}','kh'=>'{id:"KH",balloonText:"'.__('c-kh','wp-slimstat').': 0",value:0,color:"#ededed"}','cm'=>'{id:"CM",balloonText:"'.__('c-cm','wp-slimstat').': 0",value:0,color:"#ededed"}','ca'=>'{id:"CA",balloonText:"'.__('c-ca','wp-slimstat').': 0",value:0,color:"#ededed"}','cv'=>'{id:"CV",balloonText:"'.__('c-cv','wp-slimstat').': 0",value:0,color:"#ededed"}','ky'=>'{id:"KY",balloonText:"'.__('c-ky','wp-slimstat').': 0",value:0,color:"#ededed"}','cf'=>'{id:"CF",balloonText:"'.__('c-cf','wp-slimstat').': 0",value:0,color:"#ededed"}','td'=>'{id:"TD",balloonText:"'.__('c-td','wp-slimstat').': 0",value:0,color:"#ededed"}','cl'=>'{id:"CL",balloonText:"'.__('c-cl','wp-slimstat').': 0",value:0,color:"#ededed"}','cn'=>'{id:"CN",balloonText:"'.__('c-cn','wp-slimstat').': 0",value:0,color:"#ededed"}','co'=>'{id:"CO",balloonText:"'.__('c-co','wp-slimstat').': 0",value:0,color:"#ededed"}','km'=>'{id:"KM",balloonText:"'.__('c-km','wp-slimstat').': 0",value:0,color:"#ededed"}','cg'=>'{id:"CG",balloonText:"'.__('c-cg','wp-slimstat').': 0",value:0,color:"#ededed"}','cd'=>'{id:"CD",balloonText:"'.__('c-cd','wp-slimstat').': 0",value:0,color:"#ededed"}','cr'=>'{id:"CR",balloonText:"'.__('c-cr','wp-slimstat').': 0",value:0,color:"#ededed"}','ci'=>'{id:"CI",balloonText:"'.__('c-ci','wp-slimstat').': 0",value:0,color:"#ededed"}','hr'=>'{id:"HR",balloonText:"'.__('c-hr','wp-slimstat').': 0",value:0,color:"#ededed"}','cu'=>'{id:"CU",balloonText:"'.__('c-cu','wp-slimstat').': 0",value:0,color:"#ededed"}','cy'=>'{id:"CY",balloonText:"'.__('c-cy','wp-slimstat').': 0",value:0,color:"#ededed"}','cz'=>'{id:"CZ",balloonText:"'.__('c-cz','wp-slimstat').': 0",value:0,color:"#ededed"}','dk'=>'{id:"DK",balloonText:"'.__('c-dk','wp-slimstat').': 0",value:0,color:"#ededed"}','dj'=>'{id:"DJ",balloonText:"'.__('c-dj','wp-slimstat').': 0",value:0,color:"#ededed"}','dm'=>'{id:"DM",balloonText:"'.__('c-dm','wp-slimstat').': 0",value:0,color:"#ededed"}','do'=>'{id:"DO",balloonText:"'.__('c-do','wp-slimstat').': 0",value:0,color:"#ededed"}','ec'=>'{id:"EC",balloonText:"'.__('c-ec','wp-slimstat').': 0",value:0,color:"#ededed"}','eg'=>'{id:"EG",balloonText:"'.__('c-eg','wp-slimstat').': 0",value:0,color:"#ededed"}','sv'=>'{id:"SV",balloonText:"'.__('c-sv','wp-slimstat').': 0",value:0,color:"#ededed"}','gq'=>'{id:"GQ",balloonText:"'.__('c-gq','wp-slimstat').': 0",value:0,color:"#ededed"}','er'=>'{id:"ER",balloonText:"'.__('c-er','wp-slimstat').': 0",value:0,color:"#ededed"}','ee'=>'{id:"EE",balloonText:"'.__('c-ee','wp-slimstat').': 0",value:0,color:"#ededed"}','et'=>'{id:"ET",balloonText:"'.__('c-et','wp-slimstat').': 0",value:0,color:"#ededed"}','fo'=>'{id:"FO",balloonText:"'.__('c-fo','wp-slimstat').': 0",value:0,color:"#ededed"}','fk'=>'{id:"FK",balloonText:"'.__('c-fk','wp-slimstat').': 0",value:0,color:"#ededed"}','fj'=>'{id:"FJ",balloonText:"'.__('c-fj','wp-slimstat').': 0",value:0,color:"#ededed"}','fi'=>'{id:"FI",balloonText:"'.__('c-fi','wp-slimstat').': 0",value:0,color:"#ededed"}','fr'=>'{id:"FR",balloonText:"'.__('c-fr','wp-slimstat').': 0",value:0,color:"#ededed"}','gf'=>'{id:"GF",balloonText:"'.__('c-gf','wp-slimstat').': 0",value:0,color:"#ededed"}','ga'=>'{id:"GA",balloonText:"'.__('c-ga','wp-slimstat').': 0",value:0,color:"#ededed"}','gm'=>'{id:"GM",balloonText:"'.__('c-gm','wp-slimstat').': 0",value:0,color:"#ededed"}','ge'=>'{id:"GE",balloonText:"'.__('c-ge','wp-slimstat').': 0",value:0,color:"#ededed"}','de'=>'{id:"DE",balloonText:"'.__('c-de','wp-slimstat').': 0",value:0,color:"#ededed"}','gh'=>'{id:"GH",balloonText:"'.__('c-gh','wp-slimstat').': 0",value:0,color:"#ededed"}','gr'=>'{id:"GR",balloonText:"'.__('c-gr','wp-slimstat').': 0",value:0,color:"#ededed"}','gl'=>'{id:"GL",balloonText:"'.__('c-gl','wp-slimstat').': 0",value:0,color:"#ededed"}','gd'=>'{id:"GD",balloonText:"'.__('c-gd','wp-slimstat').': 0",value:0,color:"#ededed"}','gp'=>'{id:"GP",balloonText:"'.__('c-gp','wp-slimstat').': 0",value:0,color:"#ededed"}','gt'=>'{id:"GT",balloonText:"'.__('c-gt','wp-slimstat').': 0",value:0,color:"#ededed"}','gn'=>'{id:"GN",balloonText:"'.__('c-gn','wp-slimstat').': 0",value:0,color:"#ededed"}','gw'=>'{id:"GW",balloonText:"'.__('c-gw','wp-slimstat').': 0",value:0,color:"#ededed"}','gy'=>'{id:"GY",balloonText:"'.__('c-gy','wp-slimstat').': 0",value:0,color:"#ededed"}','ht'=>'{id:"HT",balloonText:"'.__('c-ht','wp-slimstat').': 0",value:0,color:"#ededed"}','hn'=>'{id:"HN",balloonText:"'.__('c-hn','wp-slimstat').': 0",value:0,color:"#ededed"}','hk'=>'{id:"HK",balloonText:"'.__('c-hk','wp-slimstat').': 0",value:0,color:"#ededed"}','hu'=>'{id:"HU",balloonText:"'.__('c-hu','wp-slimstat').': 0",value:0,color:"#ededed"}','is'=>'{id:"IS",balloonText:"'.__('c-is','wp-slimstat').': 0",value:0,color:"#ededed"}','in'=>'{id:"IN",balloonText:"'.__('c-in','wp-slimstat').': 0",value:0,color:"#ededed"}','id'=>'{id:"ID",balloonText:"'.__('c-id','wp-slimstat').': 0",value:0,color:"#ededed"}','ir'=>'{id:"IR",balloonText:"'.__('c-ir','wp-slimstat').': 0",value:0,color:"#ededed"}','iq'=>'{id:"IQ",balloonText:"'.__('c-iq','wp-slimstat').': 0",value:0,color:"#ededed"}','ie'=>'{id:"IE",balloonText:"'.__('c-ie','wp-slimstat').': 0",value:0,color:"#ededed"}','il'=>'{id:"IL",balloonText:"'.__('c-il','wp-slimstat').': 0",value:0,color:"#ededed"}','it'=>'{id:"IT",balloonText:"'.__('c-it','wp-slimstat').': 0",value:0,color:"#ededed"}','jm'=>'{id:"JM",balloonText:"'.__('c-jm','wp-slimstat').': 0",value:0,color:"#ededed"}','jp'=>'{id:"JP",balloonText:"'.__('c-jp','wp-slimstat').': 0",value:0,color:"#ededed"}','jo'=>'{id:"JO",balloonText:"'.__('c-jo','wp-slimstat').': 0",value:0,color:"#ededed"}','kz'=>'{id:"KZ",balloonText:"'.__('c-kz','wp-slimstat').': 0",value:0,color:"#ededed"}','ke'=>'{id:"KE",balloonText:"'.__('c-ke','wp-slimstat').': 0",value:0,color:"#ededed"}','nr'=>'{id:"NR",balloonText:"'.__('c-nr','wp-slimstat').': 0",value:0,color:"#ededed"}','kp'=>'{id:"KP",balloonText:"'.__('c-kp','wp-slimstat').': 0",value:0,color:"#ededed"}','kr'=>'{id:"KR",balloonText:"'.__('c-kr','wp-slimstat').': 0",value:0,color:"#ededed"}','kv'=>'{id:"KV",balloonText:"'.__('c-kv','wp-slimstat').': 0",value:0,color:"#ededed"}','kw'=>'{id:"KW",balloonText:"'.__('c-kw','wp-slimstat').': 0",value:0,color:"#ededed"}','kg'=>'{id:"KG",balloonText:"'.__('c-kg','wp-slimstat').': 0",value:0,color:"#ededed"}','la'=>'{id:"LA",balloonText:"'.__('c-la','wp-slimstat').': 0",value:0,color:"#ededed"}','lv'=>'{id:"LV",balloonText:"'.__('c-lv','wp-slimstat').': 0",value:0,color:"#ededed"}','lb'=>'{id:"LB",balloonText:"'.__('c-lb','wp-slimstat').': 0",value:0,color:"#ededed"}','ls'=>'{id:"LS",balloonText:"'.__('c-ls','wp-slimstat').': 0",value:0,color:"#ededed"}','lr'=>'{id:"LR",balloonText:"'.__('c-lr','wp-slimstat').': 0",value:0,color:"#ededed"}','ly'=>'{id:"LY",balloonText:"'.__('c-ly','wp-slimstat').': 0",value:0,color:"#ededed"}','li'=>'{id:"LI",balloonText:"'.__('c-li','wp-slimstat').': 0",value:0,color:"#ededed"}','lt'=>'{id:"LT",balloonText:"'.__('c-lt','wp-slimstat').': 0",value:0,color:"#ededed"}','lu'=>'{id:"LU",balloonText:"'.__('c-lu','wp-slimstat').': 0",value:0,color:"#ededed"}','mk'=>'{id:"MK",balloonText:"'.__('c-mk','wp-slimstat').': 0",value:0,color:"#ededed"}','mg'=>'{id:"MG",balloonText:"'.__('c-mg','wp-slimstat').': 0",value:0,color:"#ededed"}','mw'=>'{id:"MW",balloonText:"'.__('c-mw','wp-slimstat').': 0",value:0,color:"#ededed"}','my'=>'{id:"MY",balloonText:"'.__('c-my','wp-slimstat').': 0",value:0,color:"#ededed"}','ml'=>'{id:"ML",balloonText:"'.__('c-ml','wp-slimstat').': 0",value:0,color:"#ededed"}','mt'=>'{id:"MT",balloonText:"'.__('c-mt','wp-slimstat').': 0",value:0,color:"#ededed"}','mq'=>'{id:"MQ",balloonText:"'.__('c-mq','wp-slimstat').': 0",value:0,color:"#ededed"}','mr'=>'{id:"MR",balloonText:"'.__('c-mr','wp-slimstat').': 0",value:0,color:"#ededed"}','mu'=>'{id:"MU",balloonText:"'.__('c-mu','wp-slimstat').': 0",value:0,color:"#ededed"}','mx'=>'{id:"MX",balloonText:"'.__('c-mx','wp-slimstat').': 0",value:0,color:"#ededed"}','md'=>'{id:"MD",balloonText:"'.__('c-md','wp-slimstat').': 0",value:0,color:"#ededed"}','mn'=>'{id:"MN",balloonText:"'.__('c-mn','wp-slimstat').': 0",value:0,color:"#ededed"}','me'=>'{id:"ME",balloonText:"'.__('c-me','wp-slimstat').': 0",value:0,color:"#ededed"}','ms'=>'{id:"MS",balloonText:"'.__('c-ms','wp-slimstat').': 0",value:0,color:"#ededed"}','ma'=>'{id:"MA",balloonText:"'.__('c-ma','wp-slimstat').': 0",value:0,color:"#ededed"}','mz'=>'{id:"MZ",balloonText:"'.__('c-mz','wp-slimstat').': 0",value:0,color:"#ededed"}','mm'=>'{id:"MM",balloonText:"'.__('c-mm','wp-slimstat').': 0",value:0,color:"#ededed"}','na'=>'{id:"NA",balloonText:"'.__('c-na','wp-slimstat').': 0",value:0,color:"#ededed"}','np'=>'{id:"NP",balloonText:"'.__('c-np','wp-slimstat').': 0",value:0,color:"#ededed"}','nl'=>'{id:"NL",balloonText:"'.__('c-nl','wp-slimstat').': 0",value:0,color:"#ededed"}','nc'=>'{id:"NC",balloonText:"'.__('c-nc','wp-slimstat').': 0",value:0,color:"#ededed"}','nz'=>'{id:"NZ",balloonText:"'.__('c-nz','wp-slimstat').': 0",value:0,color:"#ededed"}','ni'=>'{id:"NI",balloonText:"'.__('c-ni','wp-slimstat').': 0",value:0,color:"#ededed"}','ne'=>'{id:"NE",balloonText:"'.__('c-ne','wp-slimstat').': 0",value:0,color:"#ededed"}','ng'=>'{id:"NG",balloonText:"'.__('c-ng','wp-slimstat').': 0",value:0,color:"#ededed"}','no'=>'{id:"NO",balloonText:"'.__('c-no','wp-slimstat').': 0",value:0,color:"#ededed"}','om'=>'{id:"OM",balloonText:"'.__('c-om','wp-slimstat').': 0",value:0,color:"#ededed"}','pk'=>'{id:"PK",balloonText:"'.__('c-pk','wp-slimstat').': 0",value:0,color:"#ededed"}','pw'=>'{id:"PW",balloonText:"'.__('c-pw','wp-slimstat').': 0",value:0,color:"#ededed"}','ps'=>'{id:"PS",balloonText:"'.__('c-ps','wp-slimstat').': 0",value:0,color:"#ededed"}','pa'=>'{id:"PA",balloonText:"'.__('c-pa','wp-slimstat').': 0",value:0,color:"#ededed"}','pg'=>'{id:"PG",balloonText:"'.__('c-pg','wp-slimstat').': 0",value:0,color:"#ededed"}','py'=>'{id:"PY",balloonText:"'.__('c-py','wp-slimstat').': 0",value:0,color:"#ededed"}','pe'=>'{id:"PE",balloonText:"'.__('c-pe','wp-slimstat').': 0",value:0,color:"#ededed"}','ph'=>'{id:"PH",balloonText:"'.__('c-ph','wp-slimstat').': 0",value:0,color:"#ededed"}','pl'=>'{id:"PL",balloonText:"'.__('c-pl','wp-slimstat').': 0",value:0,color:"#ededed"}','pt'=>'{id:"PT",balloonText:"'.__('c-pt','wp-slimstat').': 0",value:0,color:"#ededed"}','pr'=>'{id:"PR",balloonText:"'.__('c-pr','wp-slimstat').': 0",value:0,color:"#ededed"}','qa'=>'{id:"QA",balloonText:"'.__('c-qa','wp-slimstat').': 0",value:0,color:"#ededed"}','re'=>'{id:"RE",balloonText:"'.__('c-re','wp-slimstat').': 0",value:0,color:"#ededed"}','ro'=>'{id:"RO",balloonText:"'.__('c-ro','wp-slimstat').': 0",value:0,color:"#ededed"}','ru'=>'{id:"RU",balloonText:"'.__('c-ru','wp-slimstat').': 0",value:0,color:"#ededed"}','rw'=>'{id:"RW",balloonText:"'.__('c-rw','wp-slimstat').': 0",value:0,color:"#ededed"}','kn'=>'{id:"KN",balloonText:"'.__('c-kn','wp-slimstat').': 0",value:0,color:"#ededed"}','lc'=>'{id:"LC",balloonText:"'.__('c-lc','wp-slimstat').': 0",value:0,color:"#ededed"}','mf'=>'{id:"MF",balloonText:"'.__('c-mf','wp-slimstat').': 0",value:0,color:"#ededed"}','vc'=>'{id:"VC",balloonText:"'.__('c-vc','wp-slimstat').': 0",value:0,color:"#ededed"}','ws'=>'{id:"WS",balloonText:"'.__('c-ws','wp-slimstat').': 0",value:0,color:"#ededed"}','st'=>'{id:"ST",balloonText:"'.__('c-st','wp-slimstat').': 0",value:0,color:"#ededed"}','sa'=>'{id:"SA",balloonText:"'.__('c-sa','wp-slimstat').': 0",value:0,color:"#ededed"}','sn'=>'{id:"SN",balloonText:"'.__('c-sn','wp-slimstat').': 0",value:0,color:"#ededed"}','rs'=>'{id:"RS",balloonText:"'.__('c-rs','wp-slimstat').': 0",value:0,color:"#ededed"}','sl'=>'{id:"SL",balloonText:"'.__('c-sl','wp-slimstat').': 0",value:0,color:"#ededed"}','sg'=>'{id:"SG",balloonText:"'.__('c-sg','wp-slimstat').': 0",value:0,color:"#ededed"}','sk'=>'{id:"SK",balloonText:"'.__('c-sk','wp-slimstat').': 0",value:0,color:"#ededed"}','si'=>'{id:"SI",balloonText:"'.__('c-si','wp-slimstat').': 0",value:0,color:"#ededed"}','sb'=>'{id:"SB",balloonText:"'.__('c-sb','wp-slimstat').': 0",value:0,color:"#ededed"}','so'=>'{id:"SO",balloonText:"'.__('c-so','wp-slimstat').': 0",value:0,color:"#ededed"}','za'=>'{id:"ZA",balloonText:"'.__('c-za','wp-slimstat').': 0",value:0,color:"#ededed"}','gs'=>'{id:"GS",balloonText:"'.__('c-gs','wp-slimstat').': 0",value:0,color:"#ededed"}','es'=>'{id:"ES",balloonText:"'.__('c-es','wp-slimstat').': 0",value:0,color:"#ededed"}','lk'=>'{id:"LK",balloonText:"'.__('c-lk','wp-slimstat').': 0",value:0,color:"#ededed"}','sd'=>'{id:"SD",balloonText:"'.__('c-sd','wp-slimstat').': 0",value:0,color:"#ededed"}','ss'=>'{id:"SS",balloonText:"'.__('c-ss','wp-slimstat').': 0",value:0,color:"#ededed"}','sr'=>'{id:"SR",balloonText:"'.__('c-sr','wp-slimstat').': 0",value:0,color:"#ededed"}','sj'=>'{id:"SJ",balloonText:"'.__('c-sj','wp-slimstat').': 0",value:0,color:"#ededed"}','sz'=>'{id:"SZ",balloonText:"'.__('c-sz','wp-slimstat').': 0",value:0,color:"#ededed"}','se'=>'{id:"SE",balloonText:"'.__('c-se','wp-slimstat').': 0",value:0,color:"#ededed"}','ch'=>'{id:"CH",balloonText:"'.__('c-ch','wp-slimstat').': 0",value:0,color:"#ededed"}','sy'=>'{id:"SY",balloonText:"'.__('c-sy','wp-slimstat').': 0",value:0,color:"#ededed"}','tw'=>'{id:"TW",balloonText:"'.__('c-tw','wp-slimstat').': 0",value:0,color:"#ededed"}','tj'=>'{id:"TJ",balloonText:"'.__('c-tj','wp-slimstat').': 0",value:0,color:"#ededed"}','tz'=>'{id:"TZ",balloonText:"'.__('c-tz','wp-slimstat').': 0",value:0,color:"#ededed"}','th'=>'{id:"TH",balloonText:"'.__('c-th','wp-slimstat').': 0",value:0,color:"#ededed"}','tl'=>'{id:"TL",balloonText:"'.__('c-tl','wp-slimstat').': 0",value:0,color:"#ededed"}','tg'=>'{id:"TG",balloonText:"'.__('c-tg','wp-slimstat').': 0",value:0,color:"#ededed"}','to'=>'{id:"TO",balloonText:"'.__('c-to','wp-slimstat').': 0",value:0,color:"#ededed"}','tt'=>'{id:"TT",balloonText:"'.__('c-tt','wp-slimstat').': 0",value:0,color:"#ededed"}','tn'=>'{id:"TN",balloonText:"'.__('c-tn','wp-slimstat').': 0",value:0,color:"#ededed"}','tr'=>'{id:"TR",balloonText:"'.__('c-tr','wp-slimstat').': 0",value:0,color:"#ededed"}','tm'=>'{id:"TM",balloonText:"'.__('c-tm','wp-slimstat').': 0",value:0,color:"#ededed"}','tc'=>'{id:"TC",balloonText:"'.__('c-tc','wp-slimstat').': 0",value:0,color:"#ededed"}','ug'=>'{id:"UG",balloonText:"'.__('c-ug','wp-slimstat').': 0",value:0,color:"#ededed"}','ua'=>'{id:"UA",balloonText:"'.__('c-ua','wp-slimstat').': 0",value:0,color:"#ededed"}','ae'=>'{id:"AE",balloonText:"'.__('c-ae','wp-slimstat').': 0",value:0,color:"#ededed"}','gb'=>'{id:"GB",balloonText:"'.__('c-gb','wp-slimstat').': 0",value:0,color:"#ededed"}','us'=>'{id:"US",balloonText:"'.__('c-us','wp-slimstat').': 0",value:0,color:"#ededed"}','uy'=>'{id:"UY",balloonText:"'.__('c-uy','wp-slimstat').': 0",value:0,color:"#ededed"}','uz'=>'{id:"UZ",balloonText:"'.__('c-uz','wp-slimstat').': 0",value:0,color:"#ededed"}','vu'=>'{id:"VU",balloonText:"'.__('c-vu','wp-slimstat').': 0",value:0,color:"#ededed"}','ve'=>'{id:"VE",balloonText:"'.__('c-ve','wp-slimstat').': 0",value:0,color:"#ededed"}','vn'=>'{id:"VN",balloonText:"'.__('c-vn','wp-slimstat').': 0",value:0,color:"#ededed"}','vg'=>'{id:"VG",balloonText:"'.__('c-vg','wp-slimstat').': 0",value:0,color:"#ededed"}','vi'=>'{id:"VI",balloonText:"'.__('c-vi','wp-slimstat').': 0",value:0,color:"#ededed"}','eh'=>'{id:"EH",balloonText:"'.__('c-eh','wp-slimstat').': 0",value:0,color:"#ededed"}','ye'=>'{id:"YE",balloonText:"'.__('c-ye','wp-slimstat').': 0",value:0,color:"#ededed"}','zm'=>'{id:"ZM",balloonText:"'.__('c-zm','wp-slimstat').': 0",value:0,color:"#ededed"}','zw'=>'{id:"ZW",balloonText:"'.__('c-zw','wp-slimstat').': 0",value:0,color:"#ededed"}','gg'=>'{id:"GG",balloonText:"'.__('c-gg','wp-slimstat').': 0",value:0,color:"#ededed"}','je'=>'{id:"JE",balloonText:"'.__('c-je','wp-slimstat').': 0",value:0,color:"#ededed"}','im'=>'{id:"IM",balloonText:"'.__('c-im','wp-slimstat').': 0",value:0,color:"#ededed"}','mv'=>'{id:"MV",balloonText:"'.__('c-mv','wp-slimstat').': 0",value:0,color:"#ededed"}');
				$countries_not_represented = array( __('c-eu','wp-slimstat') );
				$max = 0;

				foreach($countries as $a_country){
					if (!array_key_exists($a_country['country'], $data_areas)) continue;

					$percentage = sprintf("%01.2f", (100*$a_country['count']/$total_count));
					$percentage_format = ($total_count > 0)?number_format($percentage, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
					$balloon_text = __('c-'.$a_country['country'], 'wp-slimstat').': '.$percentage_format.'% ('.number_format($a_country['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).')';
					$data_areas[$a_country['country']] = '{id:"'.strtoupper($a_country['country']).'",balloonText:"'.$balloon_text.'",value:'.$percentage.'}';

					if ($percentage > $max){
						$max = $percentage;
					}
				}
				?>

				<div class="postbox tall">
					<div class="hndle"><?php _e('World Map', 'wp-slimstat'); ?></div>
					<div class="container" style="height:633px" id="slimstat-world-map">
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
					
					<?php if ($max != 0): ?>
					var legend = new AmCharts.ValueLegend();
					legend.height = 20;
					legend.minValue = "0.01";
					legend.maxValue = "<?php echo $max ?>%";
					legend.right = 20;
					legend.showAsGradient = true;
					legend.width = 300;
					map.valueLegend = legend;
					<?php endif; ?>

					// Configuration
					map.areasSettings = {
						autoZoom: true,
						color: "#9dff98",
						colorSolid: "#fa8a50",
						outlineColor: "#888888",
						selectedColor: "#ffb739"
					};
					map.backgroundAlpha = .9;
					map.backgroundColor = "#7adafd";
					map.backgroundZoomsToTop = true;
					map.balloon.color = "#000000";
					map.colorSteps = 5;
					map.mouseWheelZoomEnabled = true;
					map.pathToImages = "<?php echo plugins_url('/js/ammap/images/', dirname(__FILE__)) ?>";
					
					
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
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Pageviews', 'wp-slimstat')));
							break;
						case 'slim_p1_04':
							wp_slimstat_reports::report_header($a_box_id, 'recent', __('When visitors leave a comment on your blog, WordPress assigns them a cookie. WP SlimStat leverages this information to identify returning visitors. Please note that visitors also include registered users.','wp-slimstat'));
							break;
						case 'slim_p1_05':
						case 'slim_p3_08':
							wp_slimstat_reports::report_header($a_box_id, 'wide recent', __('Take a sneak peek at what human visitors are doing on your website.','wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('From a search result page','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-known-visitor" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Known Visitor','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p1_06':
						case 'slim_p3_09':
							wp_slimstat_reports::report_header($a_box_id, 'recent', __('Keywords used by your visitors to find your website on a search engine.','wp-slimstat'));
							break;
						case 'slim_p1_15':
							wp_slimstat_reports::report_header($a_box_id, 'generic', __("WP SlimStat retrieves live information from Alexa, Facebook and Google, to measures your site's rankings. Values are updated every 12 hours. Filters set above don't apply to this report.",'wp-slimstat'));
							break;
						case 'slim_p1_16':
							wp_slimstat_reports::report_header($a_box_id, 'generic', __("We have teamed up with HackerNinja.com to offer you a free website security scan. By clicking on Start Free Scan, your website will be analyzed to detect viruses and other treats. Please note that no confidential information is being sent to HackerNinja.",'wp-slimstat'));
							break;
						case 'slim_p2_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Human Visits', 'wp-slimstat')));
							break;
						case 'slim_p2_05':
							wp_slimstat_reports::report_header($a_box_id, 'wide top', __('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'));
							break;
						case 'slim_p2_10':
							wp_slimstat_reports::report_header($a_box_id, 'top', __('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'));
							break;
						case 'slim_p2_18':
							wp_slimstat_reports::report_header($a_box_id, 'top', __('This report shows you what user agent families (no version considered) are popular among your visitors.','wp-slimstat'));
							break;
						case 'slim_p2_19':
							wp_slimstat_reports::report_header($a_box_id, 'top', __('This report shows you what operating system families (no version considered) are popular among your visitors.','wp-slimstat'));
							break;
						case 'slim_p3_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Traffic Sources', 'wp-slimstat')));
							break;
						case 'slim_p4_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Average Pageviews per Visit', 'wp-slimstat')));
							break;
						case 'slim_p4_03':
							wp_slimstat_reports::report_header($a_box_id, 'recent', __('A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.','wp-slimstat'));
							break;
						case 'slim_p4_06':
							wp_slimstat_reports::report_header($a_box_id, 'recent', __("Searches performed using Wordpress' built-in search functionality.",'wp-slimstat'));
							break;
						case 'slim_p4_08':
						case 'slim_p4_20':
							wp_slimstat_reports::report_header($a_box_id, 'wide recent', __("<strong>Link Details</strong><br>- <em>A:n</em> means that the n-th link in the page was clicked.<br>- <em>ID:xx</em> is shown when the corresponding link has an ID attribute associated to it.",'wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p4_10':
							wp_slimstat_reports::report_header($a_box_id, 'recent', __("This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to leverage this functionality.",'wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p4_22':
							wp_slimstat_reports::report_header($a_box_id, 'generic', __("Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.",'wp-slimstat'));
							break;
						case 'slim_p2_13':
						case 'slim_p2_14':
						case 'slim_p2_15':
						case 'slim_p2_16':
						case 'slim_p2_17':
						case 'slim_p2_20':
						case 'slim_p3_10':
						case 'slim_p4_04':
						case 'slim_p4_15':
							wp_slimstat_reports::report_header($a_box_id, 'recent');
							break;
						case 'slim_p4_02':
						case 'slim_p4_05':
							wp_slimstat_reports::report_header($a_box_id, 'recent wide');
							break;						
						case 'slim_p1_10':
						case 'slim_p1_11':
						case 'slim_p1_12':
						case 'slim_p1_13':
						case 'slim_p1_17':
						case 'slim_p2_03':
						case 'slim_p2_04':
						case 'slim_p2_06':
						case 'slim_p2_07':
						case 'slim_p2_21':
						case 'slim_p3_03':
						case 'slim_p3_04':
						case 'slim_p3_05':
						case 'slim_p3_06':
						case 'slim_p3_11':
						case 'slim_p4_07':
						case 'slim_p4_11':
						case 'slim_p4_12':
						case 'slim_p4_13':
						case 'slim_p4_14':
						case 'slim_p4_16':
						case 'slim_p4_17':
						case 'slim_p4_18':
						case 'slim_p4_19':
						case 'slim_p4_21':
							wp_slimstat_reports::report_header($a_box_id, 'top');
							break;
						case 'slim_p1_08':
							wp_slimstat_reports::report_header($a_box_id, 'top wide');
							break;
						case 'slim_p1_02':
						case 'slim_p1_03':
						case 'slim_p2_02':
						case 'slim_p2_09':
						case 'slim_p2_12':
						case 'slim_p3_02':
							wp_slimstat_reports::report_header($a_box_id);
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
<div id="slimstat-modal-dialog"></div>