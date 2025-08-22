<?php
if (!function_exists('add_action')) exit();

// Load header
wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]);
?>

<div class="backdrop-container">
    <div class="wrap slimstat">
        <h2><?php echo wp_slimstat_admin::$screens_info[$_GET['page']]['title'] ?></h2>

        <div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px"><?php _e('<strong>AdBlock browser extension detected</strong> - If you see this notice, it means that your browser is not loading our stylesheet and/or Javascript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://wp-slimstat.com/resources/the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat'); ?></div>

        <form action="<?php echo esc_url(wp_slimstat_reports::fs_url()); ?>" method="post" id="slimstat-filters-form">
            <fieldset id="slimstat-filters"><?php
                $filter_name_html = '<div class="form-field"><select name="f" id="slimstat-filter-name"><option value="" disabled selected>' . __('Dimension', 'wp-slimstat') . '</option>';
                foreach (wp_slimstat_db::$columns_names as $a_filter_label => $a_filter_info) {
                    $filter_name_html .= "<option value='$a_filter_label'>{$a_filter_info[0]}</option>";
                }
                $filter_name_html .= '</select></div>';

                $filter_operator_html = '<div class="form-field"><select name="o" id="slimstat-filter-operator">';
                foreach (wp_slimstat_db::$operator_names as $a_operator_label => $a_operator_name) {
                    $filter_operator_html .= "<option value='$a_operator_label'>$a_operator_name</option>";
                }
                $filter_operator_html .= '</select></div>';

                $filter_value_html = '<div class="form-field"><input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20"></div>';

                if (wp_slimstat::$settings['enable_sov'] == 'on') {
                    echo $filter_value_html . $filter_operator_html . $filter_name_html;
                } else {
                    echo $filter_name_html . $filter_operator_html . $filter_value_html;
                }

                echo '<input type="submit" value="' . __('Apply', 'wp-slimstat') . '" class="button-secondary">';

                $saved_filters = get_option('slimstat_filters', array());
                if (!empty($saved_filters)) {
                    echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary noslimstat" title="Saved Filters">' . __('Load', 'wp-slimstat') . '</a>';
                }
                ?></fieldset><!-- #slimstat-filters -->

            <fieldset id="slimstat-date-filters" class="wp-ui-highlight">
                <a href="#" class="noslimstat"><?php
                    if (!empty(wp_slimstat_db::$filters_normalized['date']['hour']) || !empty(wp_slimstat_db::$filters_normalized['date']['interval_hours'])) {
                        echo gmdate(get_option('date_format') . ' ' . get_option('time_format'), wp_slimstat_db::$filters_normalized['utime']['start']) . ' - ';

                        $end_format = (date('Ymd', wp_slimstat_db::$filters_normalized['utime']['start']) != date('Ymd', wp_slimstat_db::$filters_normalized['utime']['end'])) ? get_option('date_format') . ' ' . get_option('time_format') : get_option('time_format');
                        echo gmdate($end_format, wp_slimstat_db::$filters_normalized['utime']['end']);
                    } else {
                        $start_date = gmdate(get_option('date_format'), wp_slimstat_db::$filters_normalized['utime']['start']);
                        $end_date   = gmdate(get_option('date_format'), wp_slimstat_db::$filters_normalized['utime']['end']);

                        if ($start_date == $end_date) {
                            echo ucwords($start_date);
                        } else {
                            echo ucwords($start_date) . ' &ndash; ' . ucwords($end_date);
                        }
                    }
                    ?></a>
                <div class="dropdown">
                    <div id="slimstat-quick-filters">
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals today&&&interval equals -1') ?>"><?php _e('Today', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals yesterday&&&interval equals -1') ?>"><?php _e('Yesterday', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals ' . date('Y-m-d', strtotime('monday this week')) . '&&&interval equals -' . (date('j') - date('j', strtotime('monday this week')) + 1)) ?>"><?php _e('This Week', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals ' . date('Y-m-d', strtotime('monday last week')) . '&&&interval equals -7') ?>"><?php _e('Last Week', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals today&&&interval equals -30') ?>"><?php _e('Last 30 Days', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals today&&&interval equals -90') ?>"><?php _e('Last 90 Days', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals today&&&interval equals -' . date('j')) ?>"><?php _e('This Month', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals last day of -1 month 00:00:00 + 1 day &&&interval equals -' . date('d', strtotime('last day of -1 month 23:59:59'))) ?>"><?php _e('Last Month', 'wp-slimstat') ?></a>
                        <a class="slimstat-filter-link noslimstat" href="<?php echo wp_slimstat_reports::fs_url('strtotime equals ' . date('Y-01-01') . '&&&interval equals -' . (date('z') + 1)) ?>"><?php _e('This Year', 'wp-slimstat') ?></a>
                    </div>

                    <strong><?php _e('Date Range', 'wp-slimstat') ?></strong>
                    
                    <input type="text" id="slimstat-range-input" class="text" placeholder="<?php _e('Select range...', 'wp-slimstat') ?>" readonly>
                    
                    <!-- Hidden fields for form submission -->
                    <input type="hidden" name="hour" id="slimstat-filter-hour" value="">
                    <input type="hidden" name="day" id="slimstat-filter-day" value="">
                    <input type="hidden" name="month" id="slimstat-filter-month" value="">
                    <input type="hidden" name="year" id="slimstat-filter-year" value="">
                    <input type="hidden" name="interval" id="slimstat-filter-interval" value="">
                    <input type="hidden" name="interval_hours" id="slimstat-filter-interval_hours" value="0">
                    <input type="hidden" class="slimstat-filter-date" name="slimstat-filter-date" value=""/>

                    <input type="submit" value="<?php _e('Apply', 'wp-slimstat') ?>" class="button button-primary noslimstat right">

                    <?php
                    wp_slimstat::toggle_date_i18n_filters(false);

                    if (
                        wp_slimstat_db::$filters_normalized['date']['day'] != intval(date_i18n('j')) ||
                        wp_slimstat_db::$filters_normalized['date']['month'] != intval(date_i18n('n')) ||
                        wp_slimstat_db::$filters_normalized['date']['year'] != intval(date_i18n('Y')) ||
                        (wp_slimstat_db::$filters_normalized['date']['interval'] != -abs(wp_slimstat::$settings['posts_column_day_interval']) && wp_slimstat_db::$filters_normalized['date']['interval'] != -intval(date_i18n('j')) + 1)
                    ) {
                        echo '<a class="slimstat-filter-link button button-secondary noslimstat" data-reset-filters="true" href="' . wp_slimstat_reports::fs_url() . '">' . __('Reset Filters', 'wp-slimstat') . '</a>';
                    }
                    ?>
                </div>
                <div id="datepicker-backdrop"></div>
            </fieldset><!-- .slimstat-date-filters -->

            <?php foreach (wp_slimstat_db::$filters_normalized['columns'] as $a_key => $a_details) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="<?php echo htmlspecialchars($a_details[0] . ' ' . $a_details[1]) ?>"/>
            <?php endforeach ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['date'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo htmlspecialchars($a_value) ?>"/>
            <?php endif;
            endforeach; ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['misc'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo htmlspecialchars($a_value) ?>"/>
            <?php endif;
            endforeach; ?>
        </form>

        <?php
        if ((wp_slimstat::$settings['enable_maxmind'] == 'disable' or !\SlimStat\Services\GeoIP::database_exists()) && wp_slimstat::$settings['notice_geolite'] == 'on') {
            wp_slimstat_admin::show_message(sprintf(__("GeoIP collection is not enabled. Please go to <a href='%s' class='noslimstat'>setting page</a> to enable GeoIP for getting more information and location (country) from the visitor.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
        }

        if (version_compare(PHP_VERSION, '7.1', '>=') && !file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt') && wp_slimstat::$settings['notice_browscap'] == 'on') {
            wp_slimstat_admin::show_message(sprintf(__("Install our <a href='%s' class='noslimstat'>Browscap Library</a> to identify your visitors' browser and operating system.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
        }

        // Path to wp-content folder, used to detect caching plugins via advanced-cache.php
        if (file_exists(dirname(dirname(dirname(dirname(plugin_dir_path(__FILE__))))) . '/advanced-cache.php') && wp_slimstat::$settings['notice_caching'] == 'on' && (empty(wp_slimstat::$settings['javascript_mode']) || wp_slimstat::$settings['javascript_mode'] != 'on')) {
            wp_slimstat_admin::show_message(sprintf(__("A caching plugin might be enabled on your website. Please <a href='%s' target='_blank' class='noslimstat'>make sure to configure</a> Slimstat Analytics accordingly, to get accurate information.", 'wp-slimstat'), 'https://wp-slimstat.com/resources/i-am-using-w3-total-cache-or-wp-super-cache-hypercache-etc-and-it-looks-like-slimstat-is-not-tra'), 'warning', 'caching');
        }

        $filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
        if (!empty($filters_html)) {
            echo "<div id='slimstat-current-filters'>$filters_html</div>";
        }
        ?>

        <div class="meta-box-sortables">
            <form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>"/></form><?php

            foreach (wp_slimstat_reports::$user_reports[wp_slimstat_admin::$current_screen] as $a_report_id) {
                // A report could have been deprecated...
                if (empty(wp_slimstat_reports::$reports[$a_report_id])) {
                    continue;
                }

                wp_slimstat_reports::report_header($a_report_id);
                wp_slimstat_reports::callback_wrapper(array('id' => $a_report_id));
                wp_slimstat_reports::report_footer();
            }
            ?>
        </div>
    </div>
    <div id="slimstat-modal-dialog"></div>
</div>