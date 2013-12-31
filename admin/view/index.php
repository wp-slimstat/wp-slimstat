<?php if (!function_exists('add_action')) exit(0); ?>

<div class="wrap slimstat">
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

		<fieldset id="slimstat-date-filters" class="wp-ui-highlight"><a href="#"><?php
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
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))))) ?>"><?php _e('Yesterday','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d'), 'interval' => 'equals -7')) ?>"><?php _e('Last 7 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d'), 'interval' => 'equals -30')) ?>"><?php _e('Last 30 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('day' => 'equals '.date_i18n('d'), 'interval' => 'equals -90')) ?>"><?php _e('Last 90 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link" href="<?php echo wp_slimstat_reports::fs_url(array('year' => 'equals '.date_i18n('Y'))) ?>"><?php _e('This Year','wp-slimstat') ?></a>
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
		$filters_html = wp_slimstat_reports::get_filters_html(array_diff_key(wp_slimstat_db::$filters['parsed'], wp_slimstat_reports::$system_filters));
		if (!empty($filters_html)){
			echo "<div id='slimstat-current-filters'>$filters_html</div>";
		}
	?>

	<div class="meta-box-sortables">
		<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_slimstat_reports::$meta_report_order_nonce ?>" /></form><?php

		switch(wp_slimstat_reports::$current_tab){
			case 1:
				include_once(dirname(__FILE__).'/right-now.php');
				break;
			case 6:
				wp_slimstat_reports::report_header('slim_p6_01', 'tall');
				wp_slimstat_reports::show_world_map('slim_p6_01');
				wp_slimstat_reports::report_footer();
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
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('When visitors leave a comment on your blog, WordPress assigns them a cookie. WP SlimStat leverages this information to identify returning visitors. Please note that visitors also include registered users.','wp-slimstat'));
							break;
						case 'slim_p1_05':
						case 'slim_p3_08':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __('Take a sneak peek at what human visitors are doing on your website.','wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-search-engine" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('From a search result page','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-known-visitor" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Known Visitor','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span> '.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p1_06':
						case 'slim_p3_09':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('Keywords used by your visitors to find your website on a search engine.','wp-slimstat'));
							break;
						case 'slim_p1_15':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __("WP SlimStat retrieves live information from Alexa, Facebook and Google, to measures your site's rankings. Values are updated every 12 hours. Filters set above don't apply to this report.",'wp-slimstat'));
							break;
						case 'slim_p1_16':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __("We have teamed up with HackerNinja.com to offer you a free website security scan. By clicking on Start Free Scan, your website will be analyzed to detect viruses and other treats. Please note that no confidential information is being sent to HackerNinja.",'wp-slimstat'));
							break;
						case 'slim_p2_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Human Visits', 'wp-slimstat')));
							break;
						case 'slim_p2_05':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'));
							break;
						case 'slim_p2_10':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('You can configure WP SlimStat to ignore a specific Country by setting the corresponding filter under Settings > SlimStat > Filters.','wp-slimstat'));
							break;
						case 'slim_p2_18':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('This report shows you what user agent families (no version considered) are popular among your visitors.','wp-slimstat'));
							break;
						case 'slim_p2_19':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('This report shows you what operating system families (no version considered) are popular among your visitors.','wp-slimstat'));
							break;
						case 'slim_p3_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Traffic Sources', 'wp-slimstat')));
							break;
						case 'slim_p4_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Average Pageviews per Visit', 'wp-slimstat')));
							break;
						case 'slim_p4_03':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.','wp-slimstat'));
							break;
						case 'slim_p4_06':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __("Searches performed using Wordpress' built-in search functionality.",'wp-slimstat'));
							break;
						case 'slim_p4_08':
						case 'slim_p4_20':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __("<strong>Link Details</strong><br>- <em>A:n</em> means that the n-th link in the page was clicked.<br>- <em>ID:xx</em> is shown when the corresponding link has an ID attribute associated to it.",'wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p4_10':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __("This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to leverage this functionality.",'wp-slimstat').'<br><br><strong>'.__('Color codes','wp-slimstat').'</strong><p class="legend"><span class="little-color-box is-known-user" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Known Users','wp-slimstat').'</p><p class="legend"><span class="little-color-box is-direct" style="padding:0 5px">&nbsp;&nbsp;</span>'.__('Other Humans','wp-slimstat').'</p>');
							break;
						case 'slim_p4_22':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __("Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.",'wp-slimstat'));
							break;
						case 'slim_p4_02':
						case 'slim_p4_05':
							wp_slimstat_reports::report_header($a_box_id, 'wide');
							break;
						case 'slim_p1_02':
						case 'slim_p1_03':
						case 'slim_p1_08':
						case 'slim_p1_10':
						case 'slim_p1_11':
						case 'slim_p1_12':
						case 'slim_p1_13':
						case 'slim_p1_17':
						case 'slim_p2_02':
						case 'slim_p2_03':
						case 'slim_p2_04':
						case 'slim_p2_06':
						case 'slim_p2_07':
						case 'slim_p2_09':
						case 'slim_p2_12':
						case 'slim_p2_13':
						case 'slim_p2_14':
						case 'slim_p2_15':
						case 'slim_p2_16':
						case 'slim_p2_17':
						case 'slim_p2_20':
						case 'slim_p2_21':
						case 'slim_p3_02':
						case 'slim_p3_03':
						case 'slim_p3_04':
						case 'slim_p3_05':
						case 'slim_p3_06':
						case 'slim_p3_10':
						case 'slim_p3_11':
						case 'slim_p4_04':
						case 'slim_p4_07':
						case 'slim_p4_11':
						case 'slim_p4_12':
						case 'slim_p4_13':
						case 'slim_p4_14':
						case 'slim_p4_15':
						case 'slim_p4_16':
						case 'slim_p4_17':
						case 'slim_p4_18':
						case 'slim_p4_19':
						case 'slim_p4_21':
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