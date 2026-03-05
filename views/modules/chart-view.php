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

$chart_args = is_array($args) ? $args : [];
if (isset($chart_args['args']) && is_array($chart_args['args'])) {
    $chart_args = $chart_args['args'];
}

$chart_args = wp_parse_args(
    $chart_args,
    [
        'start'       => 0,
        'end'         => 0,
        'granularity' => 'daily',
        'chart_type'  => 'line',
        'id'          => '',
    ]
);

$availableRange = $chart_args['end'] - $chart_args['start'];
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
$is_empty = (0 === ($totals['current']['v1'] ?? 0) && 0 === ($totals['current']['v2'] ?? 0));
?>
<div
    class="slimstat-chart-wrap <?php echo esc_attr(isset($chart_args['chart_type']) && $chart_args['chart_type'] === 'bar' ? 'chart-bar' : 'chart-line'); ?>">
    <div class="slimstat-chart-controls">
        <select
            id="slimstat_granularity_<?php echo esc_attr($chart_args['id']); ?>"
            name="granularity_<?php echo esc_attr($chart_args['id']); ?>"
            class="slimstat-granularity-select">
            <option value="yearly" <?php echo $disableYearly ? 'disabled' : ''; ?>
                <?php selected($chart_args['granularity'], 'yearly'); ?>><?php echo esc_html($translations['yearly']); ?>
            </option>
            <option value="monthly" <?php echo $disableMonthly ? 'disabled' : ''; ?>
                <?php selected($chart_args['granularity'], 'monthly'); ?>><?php echo esc_html($translations['monthly']); ?>
            </option>
            <option value="weekly" <?php echo $disableWeekly ? 'disabled' : ''; ?>
                <?php selected($chart_args['granularity'], 'weekly'); ?>><?php echo esc_html($translations['weekly']); ?>
            </option>
            <option value="daily" <?php echo $disableDaily ? 'disabled' : ''; ?>
                <?php selected($chart_args['granularity'], 'daily'); ?>><?php echo esc_html($translations['daily']); ?>
            </option>
            <option value="hourly" <?php echo $disableHourly ? 'disabled' : ''; ?>
                <?php selected($chart_args['granularity'], 'hourly'); ?>><?php echo esc_html($translations['hourly']); ?>
            </option>
        </select>
    </div>
    <div id="slimstat_chart_data_<?php echo esc_attr($chart_args['id']); ?>"
        data-args="<?php echo esc_attr(wp_json_encode($chart_args)); ?>"
        data-data="<?php echo esc_attr(wp_json_encode($data)); ?>"
        data-prev-data="<?php echo esc_attr(wp_json_encode($prevData)); ?>"
        data-granularity="<?php echo esc_attr($chart_args['granularity']); ?>"
        data-chart-type="<?php echo esc_attr($chart_args['chart_type'] ?? 'line'); ?>"
        data-chart-labels="<?php echo esc_attr(wp_json_encode($chartLabels)); ?>"
        data-translations="<?php echo esc_attr(wp_json_encode($translations)); ?>"
        data-totals="<?php echo esc_attr(wp_json_encode($totals ?? [])); ?>">
    </div>
    <div id="slimstat-postbox-custom-legend_<?php echo esc_attr($chart_args['id']); ?>"
        class="slimstat-postbox-chart--items">
        <?php if ($is_empty): ?>
            <?php
            $label_one = $chartLabels[0] ?? __('Search Terms', 'wp-slimstat');
            $label_two = $chartLabels[1] ?? __('Unique Terms', 'wp-slimstat');
            ?>
            <div class="slimstat-postbox-chart--item">
                <span class="slimstat-postbox-chart--item-label"><?php echo esc_html($label_one); ?></span>
                <span class="slimstat-postbox-chart--item--color" style="background-color: #e8294c"></span>
                <span class="slimstat-postbox-chart--item-value">0</span>
            </div>
            <div class="slimstat-postbox-chart--item">
                <span class="slimstat-postbox-chart--item-label"><?php echo esc_html($label_two); ?></span>
                <span class="slimstat-postbox-chart--item--color" style="background-color: #2b76f6"></span>
                <span class="slimstat-postbox-chart--item-value">0</span>
            </div>
        <?php endif; ?>
    </div>
    <canvas
        id="slimstat_chart_<?php echo esc_attr($chart_args['id']); ?>"
        class="slimstat-postbox-chart--canvas" height="240px"></canvas>
    <?php if (defined('DOING_AJAX') && DOING_AJAX): ?>
    <script>
        reinitializeSlimStatCharts(
            "<?php echo esc_js($chart_args['id']); ?>")
    </script>
    <?php endif; ?>
</div>
