<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
if (!defined('ABSPATH')) { exit; }
use SlimStat\Components\DateRangeHelper;
?>
        <form action="<?php echo esc_url(wp_slimstat_reports::fs_url()); ?>" method="post" id="slimstat-filters-form">
            <fieldset id="slimstat-filters"><?php
                $filter_name_html = '<div class="form-field"><select name="f" id="slimstat-filter-name" aria-label="' . esc_attr__('Filter dimension', 'wp-slimstat') . '"><option value="" disabled selected>' . esc_html__('Dimension', 'wp-slimstat') . '</option>';
foreach (wp_slimstat_db::$columns_names as $a_filter_label => $a_filter_info) {
    $filter_name_html .= sprintf("<option value='%s'>%s</option>", esc_attr($a_filter_label), esc_html($a_filter_info[0]));
}
$filter_name_html .= '</select></div>';

$filter_operator_html = '<div class="form-field"><select name="o" id="slimstat-filter-operator" aria-label="' . esc_attr__('Filter operator', 'wp-slimstat') . '">';
foreach (wp_slimstat_db::$operator_names as $a_operator_label => $a_operator_name) {
    $filter_operator_html .= sprintf("<option value='%s'>%s</option>", esc_attr($a_operator_label), esc_html($a_operator_name));
}
$filter_operator_html .= '</select></div>';

$filter_value_html = '<div class="form-field">
    <input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20" aria-label="' . esc_attr__('Filter value', 'wp-slimstat') . '">
</div>';

if ('on' == wp_slimstat::$settings['enable_sov']) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed input/select markup; option values and labels escaped at construction above.
    echo $filter_value_html . $filter_operator_html . $filter_name_html;
} else {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same escaped option fragments, ordered for subject-object-verb languages.
    echo $filter_name_html . $filter_operator_html . $filter_value_html;
}

echo '<input type="submit" value="' . esc_attr__('Apply', 'wp-slimstat') . '" class="button-secondary">';

$saved_filters = get_option('slimstat_filters', []);
if (!empty($saved_filters)) {
    echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary noslimstat" title="' . esc_attr__('Saved Filters', 'wp-slimstat') . '">' . esc_html__('Saved Filters', 'wp-slimstat') . '</a>';
}
?></fieldset><!-- #slimstat-filters -->

            <?php
            if ($show_date ?? true) {
            $current_range = DateRangeHelper::get_current_date_range();
            wp_slimstat_admin::get_template('date-range-picker', [
                'label' => DateRangeHelper::format_date_range($current_range['start'], $current_range['end'], $current_range['preset']),
                'start' => gmdate('Y-m-d', (int) wp_slimstat_db::$filters_normalized['utime']['start']),
                'end'   => gmdate('Y-m-d', (int) wp_slimstat_db::$filters_normalized['utime']['end']),
            ]);
            }
            ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['columns'] as $a_key => $a_details) : ?>
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars preserves literal entities in the escaped attribute. ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="<?php echo htmlspecialchars($a_details[0] . ' ' . $a_details[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"/>
            <?php endforeach ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['date'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter slimstat-date-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
            <?php endif;
            endforeach; ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['misc'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
            <?php endif;
            endforeach; ?>
        </form>
