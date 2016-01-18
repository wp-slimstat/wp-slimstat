<?php if (!function_exists('add_action')) exit(0); ?>

<div class="wrap slimstat">
	<h2><?php echo wp_slimstat_admin::$screens_info[ $_GET[ 'page' ] ][ 'title' ] ?></h2>

	<form action="<?php echo wp_slimstat_reports::fs_url(); ?>" method="post" id="slimstat-filters-form">
		<fieldset id="slimstat-filters"><?php
			$filter_name_html = '<select name="f" id="slimstat-filter-name">';
			foreach (wp_slimstat_db::$columns_names as $a_filter_label => $a_filter_info){
				$filter_name_html .= "<option value='$a_filter_label'>{$a_filter_info[0]}</option>";
			}
			$filter_name_html .= '</select>';

			$filter_operator_html = '
				<select name="o" id="slimstat-filter-operator">
					<option value="equals">'.__('equals','wp-slimstat').'</option>
					<option value="is_not_equal_to">'.__('is not equal to','wp-slimstat').'</option>
					<option value="contains">'.__('contains','wp-slimstat').'</option>
					<option value="includes_in_set">'.__('is included in','wp-slimstat').'</option>
					<option value="does_not_contain">'.__('does not contain','wp-slimstat').'</option>
					<option value="starts_with">'.__('starts with','wp-slimstat').'</option>
					<option value="ends_with">'.__('ends with','wp-slimstat').'</option>
					<option value="sounds_like">'.__('sounds like','wp-slimstat').'</option>
					<option value="is_greater_than">'.__('is greater than','wp-slimstat').'</option>
					<option value="is_less_than">'.__('is less than','wp-slimstat').'</option>
					<option value="between">'.__('is between (x,y)','wp-slimstat').'</option>
					<option value="matches">'.__('matches','wp-slimstat').'</option>
					<option value="does_not_match">'.__('does not match','wp-slimstat').'</option>
					<option value="is_empty">'.__('is empty','wp-slimstat').'</option>
					<option value="is_not_empty">'.__('is not empty','wp-slimstat').'</option>
				</select>';
			
			$filter_value_html = '<input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20">';
			
			if (wp_slimstat::$options['enable_sov'] == 'yes'){
				echo $filter_value_html.$filter_operator_html.$filter_name_html;
			}
			else{
				echo $filter_name_html.$filter_operator_html.$filter_value_html;
			}
			
			echo '<input type="submit" value="'.__('Apply','wp-slimstat').'" class="button-secondary">';
			
			$saved_filters = get_option('slimstat_filters', array());
			if (!empty($saved_filters)){
				echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary" title="Saved Filters">'.__('Load','wp-slimstat').'</a>';
			}
			?>
		</fieldset><!-- slimstat-filters -->

		<fieldset id="slimstat-date-filters" class="wp-ui-highlight">
			<a href="#"><?php
				if (!empty(wp_slimstat_db::$filters_normalized['date']['hour']) || !empty(wp_slimstat_db::$filters_normalized['date']['interval_hours'])){
					echo gmdate(wp_slimstat::$options['date_format'].' '.wp_slimstat::$options['time_format'], wp_slimstat_db::$filters_normalized['utime']['start']).' - ';
					$end_format = (date('Ymd', wp_slimstat_db::$filters_normalized['utime']['start']) != date('Ymd', wp_slimstat_db::$filters_normalized['utime']['end']))?wp_slimstat::$options['date_format'].' '.wp_slimstat::$options['time_format']:wp_slimstat::$options['time_format'];
					echo gmdate($end_format, wp_slimstat_db::$filters_normalized['utime']['end']);
				}
				else if (!empty(wp_slimstat_db::$filters_normalized['date']['day']) && empty(wp_slimstat_db::$filters_normalized['date']['interval'])){
					echo ucwords(gmdate(wp_slimstat::$options['date_format'], wp_slimstat_db::$filters_normalized['utime']['start']));
				}
				else{
					echo ucwords(gmdate(wp_slimstat::$options['date_format'], wp_slimstat_db::$filters_normalized['utime']['start']).' - '.gmdate(wp_slimstat::$options['date_format'], wp_slimstat_db::$filters_normalized['utime']['end']));
				}
			?></a>
			<span>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals 0') ?>"><?php _e('Today','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&month equals '.date_i18n('m', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&year equals '.date_i18n('Y', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))).'&&&interval equals 0') ?>"><?php _e('Yesterday','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals 7&&&interval_direction equals minus') ?>"><?php _e('Last 7 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals 60&&&interval_direction equals minus') ?>"><?php _e('Last 60 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals '.date_i18n('d').'&&&month equals '.date_i18n('m').'&&&year equals '.date_i18n('Y').'&&&interval equals 90&&&interval_direction equals minus') ?>"><?php _e('Last 90 Days','wp-slimstat') ?></a>
				<a class="slimstat-filter-link slimstat-date-choice" href="<?php echo wp_slimstat_reports::fs_url('hour equals 0&&&day equals 0&&&month equals 0&&&year equals '.date_i18n('Y').'&&&interval equals 0') ?>"><?php _e('This Year So Far','wp-slimstat') ?></a>
				<strong><?php _e('Date Range','wp-slimstat') ?></strong>
				<select name="day" id="slimstat-filter-day">
					<option value="0"><?php _e('Day','wp-slimstat') ?></option><?php
					for($i=1;$i<=31;$i++){
						if(!empty(wp_slimstat_db::$filters_normalized['date']['day']) && wp_slimstat_db::$filters_normalized['date']['day'] == $i)
							echo "<option selected='selected'>$i</option>";
						else
							echo "<option>$i</option>";
					} 
					?>
				</select> 
				<select name="month" id="slimstat-filter-month">
					<option value="0"><?php _e('Month','wp-slimstat') ?></option><?php
					for($i=1;$i<=12;$i++){
						if(!empty(wp_slimstat_db::$filters_normalized['date']['month']) && wp_slimstat_db::$filters_normalized['date']['month'] == $i)
							echo "<option value='$i' selected='selected'>".substr($GLOBALS['month'][zeroise($i, 2)], 0, 3)."</option>";
						else
							echo "<option value='$i'>".substr($GLOBALS['month'][zeroise($i, 2)], 0, 3)."</option>";
					} 
					?>
				</select>
				<input type="text" name="year" id="slimstat-filter-year" placeholder="<?php _e('Year','wp-slimstat') ?>" class="empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['year'])?wp_slimstat_db::$filters_normalized['date']['year']:'' ?>"> @
				<input type="text" name="hour" id="slimstat-filter-hour" placeholder="<?php _e('Hour','wp-slimstat') ?>" class="short empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['hour'])?wp_slimstat_db::$filters_normalized['date']['hour']:'' ?>">:
				<input type="text" name="minute" id="slimstat-filter-minute" placeholder="<?php _e('Min','wp-slimstat') ?>" class="short empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['minute'])?wp_slimstat_db::$filters_normalized['date']['minute']:'' ?>">
				<input type="hidden" class="slimstat-filter-date" name="slimstat-filter-date" value=""/>
				<br/>
				<select name="interval_direction" class="short" id="slimstat-filter-interval_direction">
					<option value="minus" <?php selected(wp_slimstat_db::$filters_normalized['date']['interval_direction'], 'minus') ?>>-</option>
					<option value="plus" <?php selected(wp_slimstat_db::$filters_normalized['date']['interval_direction'], 'plus') ?>>+</option>
				</select>
				<input type="text" name="interval" id="slimstat-filter-interval" placeholder="<?php _e('days', 'wp-slimstat') ?>" class="short empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['interval'])?wp_slimstat_db::$filters_normalized['date']['interval']:'' ?>">,
				<input type="text" name="interval_hours" id="slimstat-filter-interval_hours" placeholder="<?php _e('hours', 'wp-slimstat') ?>" class="short empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['interval_hours'])?wp_slimstat_db::$filters_normalized['date']['interval_hours']:'' ?>">:
				<input type="text" name="interval_minutes" id="slimstat-filter-interval_minutes" placeholder="<?php _e('mins', 'wp-slimstat') ?>" class="short empty-on-focus" value="<?php echo !empty(wp_slimstat_db::$filters_normalized['date']['interval_minutes'])?wp_slimstat_db::$filters_normalized['date']['interval_minutes']:'' ?>">
				<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary">
				<?php 
				if (!empty(wp_slimstat_db::$filters_normalized['date']['day']) ||
							!(empty(wp_slimstat_db::$filters_normalized['date']['month']) || wp_slimstat_db::$filters_normalized['date']['month'] == date_i18n('n')) || 
							!empty(wp_slimstat_db::$filters_normalized['date']['year']) ||
							!empty(wp_slimstat_db::$filters_normalized['date']['interval']) ||
							!empty(wp_slimstat_db::$filters_normalized['date']['interval_hours']) ||
							!empty(wp_slimstat_db::$filters_normalized['date']['interval_minutes'])): ?>
				<a class="slimstat-filter-link button-secondary" href="<?php echo wp_slimstat_reports::fs_url('minute equals 0&&&hour equals 0&&&day equals 0&&&month equals '.date_i18n('n').'&&&year equals 0&&&interval_direction equals plus&&&interval equals 0&&&interval_hours equals 0&&&interval_minutes equals 0') ?>"><?php _e('Reset Filters','wp-slimstat') ?></a>
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
		if ( !file_exists( wp_slimstat::$maxmind_path ) && ( empty( wp_slimstat::$options[ 'no_maxmind_warning' ] ) || wp_slimstat::$options[ 'no_maxmind_warning' ] != 'yes' ) ) {
			wp_slimstat_admin::show_alert_message( sprintf( __( "Install MaxMind's <a href='%s'>GeoLite DB</a> to determine your visitors' country of origin.", 'wp-slimstat' ), self::$config_url . '6' ) . '<a id="slimstat-hide-geolite-notice" class="slimstat-font-cancel slimstat-float-right" title="Hide this notice" href="#"></a>', 'wp-ui-notification below-h2' );
		}

		$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
		if (!empty($filters_html)){
			echo "<div id='slimstat-current-filters'>$filters_html</div>";
		}
	?>

	<div class="meta-box-sortables">
		<form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>" /></form><?php

		foreach( wp_slimstat_reports::$reports_info as $a_report_id => $a_report_info ) {
			wp_slimstat_reports::report_header( $a_report_id );

			// Third party reports can add their own methods via the callback parameter
			if ( !in_array( 'hidden', $a_report_info[ 'classes' ] ) ) {
				wp_slimstat_reports::callback_wrapper( array( 'id' => $a_report_id ) );
			}

			wp_slimstat_reports::report_footer();
		}
	?></div>
</div>
<div id="slimstat-modal-dialog"></div>