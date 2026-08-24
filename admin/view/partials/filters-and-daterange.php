<!-- Filters + date-range picker. Shared by every screen that needs the
     standard Dimension/Operator/Value filter builder and date-range picker
     (admin/view/index.php and admin/view/general.php) so the two can never
     drift into two different-looking/behaving top bars. -->
<?php
if (!function_exists('add_action')) {
    exit();
}

use SlimStat\Components\DateRangeHelper;
?>

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

        $filter_value_html = '<div class="form-field">
            <input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20">
        </div>';

        if ('on' == wp_slimstat::$settings['enable_sov']) {
            echo $filter_value_html . $filter_operator_html . $filter_name_html;
        } else {
            echo $filter_name_html . $filter_operator_html . $filter_value_html;
        }

        echo '<input type="submit" value="' . __('Apply', 'wp-slimstat') . '" class="button-secondary">';

        $saved_filters = get_option('slimstat_filters', []);
        if (!empty($saved_filters)) {
            echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary noslimstat" title="Saved Filters">' . __('Saved Filters', 'wp-slimstat') . '</a>';
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
        <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="<?php echo esc_attr($a_details[0] . ' ' . $a_details[1]) ?>"/>
    <?php endforeach ?>

    <?php foreach (wp_slimstat_db::$filters_normalized['date'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
        <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
    <?php endif;
    endforeach; ?>

    <?php foreach (wp_slimstat_db::$filters_normalized['misc'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
        <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
    <?php endif;
    endforeach; ?>
</form>
