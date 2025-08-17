<?php
// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$translations = array_merge(
    $translations,
    [
        'yearly'  => __('Yearly', 'wp-slimstat'),
        'monthly' => __('Monthly', 'wp-slimstat'),
        'weekly'  => __('Weekly', 'wp-slimstat'),
        'daily'   => __('Daily', 'wp-slimstat'),
        'hourly'  => __('Hourly', 'wp-slimstat'),
    ]
);

$availableRange = $args['end'] - $args['start'];
$disableYearly  = $availableRange < (365 * 86400); // Less than 1 year of data
$disableMonthly = $availableRange < (30 * 86400); // Less than 1 month of data
$disableWeekly  = $availableRange < (7 * 86400); // Less than 1 week of data
$disableDaily   = ($availableRange < (2 * 86400)); // Disable daily if less than 2 days
$disableHourly  = $availableRange > (7 * 86400); // More than 7 days of data
$totals         = [
    'current' => [
        'v1' => (int) ($data['totals'][0]->v1 ?? 0),
        'v2' => (int) ($data['totals'][0]->v2 ?? 0),
    ],
    'previous' => [
        'v1' => (int) ($data['totals'][1]->v1 ?? 0),
        'v2' => (int) ($data['totals'][1]->v2 ?? 0),
    ],
];
?>
<div class="slimstat-chart-wrap">
    <div class="slimstat-chart-controls">
        <select
            id="slimstat_granularity_<?php echo esc_attr($args['id']); ?>"
            name="granularity_<?php echo esc_attr($args['id']); ?>"
            class="slimstat-granularity-select">
            <option value="yearly" <?php echo $disableYearly ? 'disabled' : ''; ?>
                <?php selected($args['granularity'], 'yearly'); ?>><?php echo esc_html($translations['yearly']); ?>
            </option>
            <option value="monthly" <?php echo $disableMonthly ? 'disabled' : ''; ?>
                <?php selected($args['granularity'], 'monthly'); ?>><?php echo esc_html($translations['monthly']); ?>
            </option>
            <option value="weekly" <?php echo $disableWeekly ? 'disabled' : ''; ?>
                <?php selected($args['granularity'], 'weekly'); ?>><?php echo esc_html($translations['weekly']); ?>
            </option>
            <option value="daily" <?php echo $disableDaily ? 'disabled' : ''; ?>
                <?php selected($args['granularity'], 'daily'); ?>><?php echo esc_html($translations['daily']); ?>
            </option>
            <option value="hourly" <?php echo $disableHourly ? 'disabled' : ''; ?>
                <?php selected($args['granularity'], 'hourly'); ?>><?php echo esc_html($translations['hourly']); ?>
            </option>
        </select>
    </div>
    <div id="slimstat_chart_data_<?php echo esc_attr($args['id']); ?>"
        data-args="<?php echo esc_attr(json_encode($args)); ?>"
        data-data="<?php echo esc_attr(json_encode($data)); ?>"
        data-prev-data="<?php echo esc_attr(json_encode($prevData)); ?>"
        data-granularity="<?php echo esc_attr($args['granularity']); ?>"
        data-chart-labels="<?php echo esc_attr(json_encode($chartLabels)); ?>"
        data-translations="<?php echo esc_attr(json_encode($translations)); ?>"
        data-totals="<?php echo esc_attr(json_encode($totals ?? [])); ?>">
    </div>
    <div id="slimstat-postbox-custom-legend_<?php echo esc_attr($args['id']); ?>"
        class="slimstat-postbox-chart--items"></div>
    <canvas
        id="slimstat_chart_<?php echo esc_attr($args['id']); ?>"
        class="slimstat-postbox-chart--canvas" height="240px"></canvas>
    <?php if (defined('DOING_AJAX') && DOING_AJAX): ?>
    <script>
        reinitializeSlimStatCharts(
            "<?php echo $args['id']; ?>")
    </script>
    <?php endif; ?>
</div>