<?php if (!function_exists('add_action')) exit(0); ?>

<div class="wrap slimstat">
	<h2><?php echo wp_slimstat_reports::$screen_names[wp_slimstat_admin::$current_tab] ?></h2>

	<form action="<?php echo wp_slimstat_reports::fs_url(); ?>" method="post" id="slimstat-filters-form">
		<fieldset id="slimstat-filters"><?php
			$filter_name_html = '<select name="f" id="slimstat-filter-name">';
			foreach (wp_slimstat_reports::$dropdown_filter_names as $a_filter_label => $a_filter_name){
				$filter_name_html .= "<option value='$a_filter_label'>$a_filter_name</option>";
			}
			$filter_name_html .= '</select>';

			$filter_operator_html = '<select name="o" id="slimstat-filter-operator">';
			$filter_operator_html .= '<option value="equals">'.__('equals','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="is_not_equal_to">'.__('is not equal to','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="contains">'.__('contains','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="does_not_contain">'.__('does not contain','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="starts_with">'.__('starts with','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="ends_with">'.__('ends with','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="sounds_like">'.__('sounds like','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="is_greater_than">'.__('is greater than','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="is_less_than">'.__('is less than','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="between">'.__('is between (x,y)','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="matches">'.__('matches','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="does_not_match">'.__('does not match','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="is_empty">'.__('is empty','wp-slimstat').'</option>';
			$filter_operator_html .= '<option value="is_not_empty">'.__('is not empty','wp-slimstat').'</option>';
			$filter_operator_html .= '</select>';
			
			$filter_value_html = '<input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20">';
			
			if (wp_slimstat::$options['enable_sov'] == 'yes'){
				echo $filter_value_html.$filter_operator_html.$filter_name_html;
			}
			else{
				echo $filter_name_html.$filter_operator_html.$filter_value_html;
			}
			
			?>
			<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary">
			<a href="#" id="slimstat-load-saved-filters" class="button-secondary" title="Saved Filters"><?php _e('Load','wp-slimstat') ?></a>
		</fieldset><!-- slimstat-filters -->

		<fieldset id="slimstat-date-filters" class="wp-ui-highlight">
			<a href="#"><?php
				if (!empty(wp_slimstat_db::$filters_normalized['date']['hour'])){
					echo ucwords(gmdate(wp_slimstat::$options['date_time_format'], wp_slimstat_db::$filters_normalized['utime']['start']).' - '.gmdate(wp_slimstat_db::$formats['time_format'], wp_slimstat_db::$filters_normalized['utime']['end']));
				}
				else if (!empty(wp_slimstat_db::$filters_normalized['date']['day']) && empty(wp_slimstat_db::$filters_normalized['date']['interval'])){
					echo ucwords(gmdate(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$filters_normalized['utime']['start']));
				}
				else{
					echo ucwords(gmdate(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$filters_normalized['utime']['start']).' - '.gmdate(wp_slimstat_db::$formats['date_format'], wp_slimstat_db::$filters_normalized['utime']['end']));
				}
			?></a>
			<span>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals 0') ?>"><?php _e('Today','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&month equals '.date_i18n('m', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&year equals '.date_i18n('Y', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&interval equals 0') ?>"><?php _e('Yesterday','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals -7') ?>"><?php _e('Last 7 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals -60') ?>"><?php _e('Last 60 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals -90') ?>"><?php _e('Last 90 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals 0&&&month equals 0&&&year equals '.date_i18n('Y').'&&&interval equals 0') ?>"><?php _e('This Year So Far','wp-slimstat') ?></a>
				<strong><?php _e('Date Range','wp-slimstat') ?></strong>
				<select name="day" id="slimstat-filter-day" class="empty-on-submit">
					<option value="0"><?php _e('Day','wp-slimstat') ?></option><?php
					for($i=1;$i<=31;$i++){
						if(!empty(wp_slimstat_db::$filters_normalized['date']['day']) && wp_slimstat_db::$filters_normalized['date']['day'] == $i)
							echo "<option selected='selected'>$i</option>";
						else
							echo "<option>$i</option>";
					} 
					?>
				</select> 
				<select name="month" id="slimstat-filter-month" class="empty-on-submit">
					<option value="0"><?php _e('Month','wp-slimstat') ?></option><?php
					for($i=1;$i<=12;$i++){
						if(!empty(wp_slimstat_db::$filters_normalized['date']['month']) && wp_slimstat_db::$filters_normalized['date']['month'] == $i)
							echo "<option value='$i' selected='selected'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
						else
							echo "<option value='$i'>{$GLOBALS['month'][zeroise($i, 2)]}</option>";
					} 
					?>
				</select>
				<input type="text" name="year" id="slimstat-filter-year" placeholder="<?php _e('Year','wp-slimstat') ?>" class="empty-on-focus empty-on-submit" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['year'])?wp_slimstat_db::$filters_normalized['date']['year']:'' ?>">
				<input type="hidden" class="slimstat-filter-date" name="slimstat-filter-date" value=""/>
				<br/>+ <input type="text" name="interval" id="slimstat-filter-interval" class="empty-on-focus empty-on-submit" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['interval'])?wp_slimstat_db::$filters_normalized['date']['interval']:__('days', 'wp-slimstat') ?>">
				<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary">
				<?php if (!empty(wp_slimstat_db::$filters_normalized['date']['day']) || !empty(wp_slimstat_db::$filters_normalized['date']['month']) || !empty(wp_slimstat_db::$filters_normalized['date']['year'])): ?>
				<a class="slimstat-filter-link button-secondary" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals 0&&&month equals '.date_i18n('n').'&&&year equals '.date_i18n('Y').'&&&interval equals 0') ?>"><?php _e('Reset Filters','wp-slimstat') ?></a>
				<?php endif ?>
			</span>
		</fieldset><!-- .slimstat-date-filters -->

		<?php foreach(wp_slimstat_db::$filters_normalized['columns'] as $a_key => $a_details): ?>
		<input type="hidden" name="fs[<?php echo $a_key ?>]" class="slimstat-post-filter" value="<?php echo htmlspecialchars($a_details[0].' '.$a_details[1]) ?>"/>
		<?php endforeach ?>

		<?php foreach(wp_slimstat_db::$filters_normalized['date'] as $a_key => $a_value): if (!empty($a_value) && !empty(wp_slimstat_db::$filters_normalized['date'][$a_key])): ?>
		<input type="hidden" name="fs[<?php echo $a_key ?>]" class="slimstat-post-filter" value="equals <?php echo htmlspecialchars($a_value) ?>"/>
		<?php endif; endforeach; ?>
		
		<?php foreach(wp_slimstat_db::$filters_normalized['misc'] as $a_key => $a_value): if (!empty($a_value) && !empty(wp_slimstat_db::$filters_normalized['misc'][$a_key])): ?>
		<input type="hidden" name="fs[<?php echo $a_key ?>]" class="slimstat-post-filter" value="equals <?php echo htmlspecialchars($a_value) ?>"/>
		<?php endif; endforeach; ?>
	</form>

	<?php
		$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
		if (!empty($filters_html)){
			echo "<div id='slimstat-current-filters'>$filters_html</div>";
		}
	?>

	<div class="meta-box-sortables">
		<form style="display:none" method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_slimstat_reports::$meta_report_order_nonce ?>" /></form><?php

		switch(wp_slimstat_admin::$current_tab){
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
						<p style="padding:10px;line-height:2em;white-space:normal"><?php _e( 'Yes, you can! Create and view your personalized analytics for Slimstat. Just write a new plugin that retrieves the desired information from the database and then hook it to the action <code>wp_slimstat_custom_report</code>. For more information, visit my <a href="http://wordpress.org/tags/wp-slimstat?forum_id=10" target="_blank">support forum</a>.', 'wp-slimstat' ); ?></p>
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
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('When visitors leave a comment on your blog, WordPress assigns them a cookie. Slimstat leverages this information to identify returning visitors. Please note that visitors also include registered users.','wp-slimstat'));
							break;
						case 'slim_p1_05':
						case 'slim_p3_08':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __('Color codes','wp-slimstat').'</strong><p><span class="little-color-box is-search-engine"></span> '.__('From search result page','wp-slimstat').'</p><p><span class="little-color-box is-known-visitor"></span> '.__('Known Visitor','wp-slimstat').'</p><p><span class="little-color-box is-known-user"></span> '.__('Known Users','wp-slimstat').'</p><p><span class="little-color-box is-direct"></span> '.__('Other Humans','wp-slimstat').'</p><p><span class="little-color-box"></span> '.__('Bot or Crawler','wp-slimstat').'</p>');
							break;
						case 'slim_p1_06':
						case 'slim_p3_09':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('Keywords used by your visitors to find your website on a search engine.','wp-slimstat'));
							break;
						case 'slim_p1_15':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __("Slimstat retrieves live information from Alexa, Facebook and Google, to measures your site's rankings. Values are updated every 12 hours. Filters set above don't apply to this report.",'wp-slimstat'));
							break;
						case 'slim_p2_01':
							wp_slimstat_reports::report_header($a_box_id, 'wide chart', wp_slimstat_reports::$chart_tooltip, wp_slimstat_reports::chart_title(__('Human Visits', 'wp-slimstat')));
							break;
						case 'slim_p2_05':
							wp_slimstat_reports::report_header($a_box_id, 'wide', __('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.','wp-slimstat'));
							break;
						case 'slim_p2_10':
							wp_slimstat_reports::report_header($a_box_id, 'normal', __('You can configure Slimstat to ignore a specific Country by setting the corresponding filter under Settings > Slimstat > Filters.','wp-slimstat'));
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