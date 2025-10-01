<?php
if (!function_exists('add_action')) {
    exit();
}

use SlimStat\Components\DateRangeHelper;
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
    $filter_name_html .= sprintf("<option value='%s'>%s</option>", $a_filter_label, $a_filter_info[0]);
}
$filter_name_html .= '</select></div>';

$filter_operator_html = '<div class="form-field"><select name="o" id="slimstat-filter-operator">';
foreach (wp_slimstat_db::$operator_names as $a_operator_label => $a_operator_name) {
    $filter_operator_html .= sprintf("<option value='%s'>%s</option>", $a_operator_label, $a_operator_name);
}
$filter_operator_html .= '</select></div>';

$filter_value_html = '<div class="form-field"><input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20"></div>';

if ('on' == wp_slimstat::$settings['enable_sov']) {
    echo $filter_value_html . $filter_operator_html . $filter_name_html;
} else {
    echo $filter_name_html . $filter_operator_html . $filter_value_html;
}

echo '<input type="submit" value="' . __('Apply', 'wp-slimstat') . '" class="button-secondary">';

$saved_filters = get_option('slimstat_filters', []);
if (!empty($saved_filters)) {
    echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary noslimstat" title="Saved Filters">' . __('Load', 'wp-slimstat') . '</a>';
}
?></fieldset><!-- #slimstat-filters -->

            <fieldset id="slimstat-date-filters" class="wp-ui-highlight">
                <?php
                // Get current date range for display
                $current_range = DateRangeHelper::get_current_date_range();
                $display_label = DateRangeHelper::format_date_range($current_range['start'], $current_range['end'], $current_range['preset']);
                ?>
                
                <!-- New Statistics-style Date Range Picker -->
                <div class="slimstat-date-range-picker">
                    <button type="button" class="slimstat-date-range-btn" aria-haspopup="true" aria-expanded="false">
                        <div class="datepicker-badge-elements">
                            <svg class="calendar-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none">
                                <defs>
                                    <clipPath id="slimstat-calendar-clip">
                                        <path fill="#fff" d="M0 0h16v16H0z"/>
                                    </clipPath>
                                </defs>
                                <g clip-path="url(#slimstat-calendar-clip)" stroke="currentColor" stroke-linejoin="round">
                                    <path d="M13 2.5H3a.5.5 0 0 0-.5.5v10a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5V3a.5.5 0 0 0-.5-.5z"/>
                                    <g stroke-linecap="round">
                                        <path d="M11 1.5v2m-6-2v2m-2.5 2h11"/>
                                    </g>
                                </g>
                            </svg>
                            <span class="date-label"><?php echo esc_html($display_label); ?></span>
                        </div>
                        <div class="datepicker-badge-elements">
                            <span class="caret"></span>
                        </div>
                    </button>
                    <input type="text" class="slimstat-date-range-input" style="display: none;" />
                </div>
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
        if (('disable' == wp_slimstat::$settings['enable_maxmind'] || !\SlimStat\Services\GeoIP::database_exists()) && 'on' == wp_slimstat::$settings['notice_geolite']) {
            wp_slimstat_admin::show_message(sprintf(__("GeoIP collection is not enabled. Please go to <a href='%s' class='noslimstat'>setting page</a> to enable GeoIP for getting more information and location (country) from the visitor.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
        }

if (PHP_VERSION_ID >= 70100 && !file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt') && 'on' == wp_slimstat::$settings['notice_browscap']) {
    wp_slimstat_admin::show_message(sprintf(__("Install our <a href='%s' class='noslimstat'>Browscap Library</a> to identify your visitors' browser and operating system.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
}

// Path to wp-content folder, used to detect caching plugins via advanced-cache.php
if (file_exists(dirname(plugin_dir_path(__FILE__), 4) . '/advanced-cache.php') && 'on' == wp_slimstat::$settings['notice_caching'] && (empty(wp_slimstat::$settings['javascript_mode']) || 'on' != wp_slimstat::$settings['javascript_mode'])) {
    wp_slimstat_admin::show_message(sprintf(__("A caching plugin might be enabled on your website. Please <a href='%s' target='_blank' class='noslimstat'>make sure to configure</a> Slimstat Analytics accordingly, to get accurate information.", 'wp-slimstat'), 'https://wp-slimstat.com/resources/i-am-using-w3-total-cache-or-wp-super-cache-hypercache-etc-and-it-looks-like-slimstat-is-not-tra'), 'warning', 'caching');
}

$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
if (!empty($filters_html)) {
    echo sprintf("<div id='slimstat-current-filters'>%s</div>", $filters_html);
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
        wp_slimstat_reports::callback_wrapper(['id' => $a_report_id]);
        wp_slimstat_reports::report_footer();
    }
?>
        </div>
    </div>
    <div id="slimstat-modal-dialog"></div>
</div>