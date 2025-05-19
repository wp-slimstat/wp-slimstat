<?php
// don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$args = $this->args;
$data = $this->data;
$prev_data = $this->prev_data;
$daysBetween = $this->daysBetween;
$chart_labels = $this->chart_labels;
$translations = array_merge(
    $this->translations,
    array(
        'yearly' => __('Yearly', 'wp-slimstat'),
        'monthly' => __('Monthly', 'wp-slimstat'),
        'weekly' => __('Weekly', 'wp-slimstat'),
        'daily' => __('Daily', 'wp-slimstat'),
        'hourly' => __('Hourly', 'wp-slimstat'),
    )
);

$availableRange = $this->args['end'] - $this->args['start'];

// Dynamically calculate limits based on available data range
$disable_yearly = $availableRange < (365 * 86400); // Less than 1 year of data
$disable_monthly = $availableRange < (30 * 86400); // Less than 1 month of data
$disable_weekly = $availableRange < (7 * 86400); // Less than 1 week of data
$disable_daily = ($availableRange < (2 * 86400)); // Disable daily if less than 2 days
$disable_hourly = $availableRange > (7 * 86400); // More than 7 days of data
?>
<div class="slimstat-chart-wrap">
    <div class="slimstat-chart-controls">
        <select id="slimstat_granularity_<?php echo esc_attr($args['id']); ?>" name="xxx" class="slimstat-granularity-select">
            <option value="yearly" <?php echo $disable_yearly ? 'disabled' : ''; ?> <?php selected($args['granularity'], 'yearly'); ?>><?php echo esc_html($translations['yearly']); ?></option>
            <option value="monthly" <?php echo $disable_monthly ? 'disabled' : ''; ?> <?php selected($args['granularity'], 'monthly'); ?>><?php echo esc_html($translations['monthly']); ?></option>
            <option value="weekly" <?php echo $disable_weekly ? 'disabled' : ''; ?> <?php selected($args['granularity'], 'weekly'); ?>><?php echo esc_html($translations['weekly']); ?></option>
            <option value="daily" <?php echo $disable_daily ? 'disabled' : ''; ?> <?php selected($args['granularity'], 'daily'); ?>><?php echo esc_html($translations['daily']); ?></option>
            <option value="hourly" <?php echo $disable_hourly ? 'disabled' : ''; ?> <?php selected($args['granularity'], 'hourly'); ?>><?php echo esc_html($translations['hourly']); ?></option>
        </select>
    </div>
    <div id="slimstat_chart_data_<?php echo esc_attr($args['id']); ?>"
        data-args="<?php echo esc_attr(json_encode($args)); ?>"
        data-data="<?php echo esc_attr(json_encode($data)); ?>"
        data-prev-data="<?php echo esc_attr(json_encode($prev_data)); ?>"
        data-days-between="<?php echo esc_attr($daysBetween); ?>"
        data-granularity="<?php echo esc_attr($args['granularity']); ?>"
        data-chart-labels="<?php echo esc_attr(json_encode($chart_labels)); ?>"
        data-translations="<?php echo esc_attr(json_encode($translations)); ?>"></div>
    <div id="slimstat-postbox-custom-legend_<?php echo esc_attr($args['id']); ?>" class="slimstat-postbox-chart--items"></div>
    <canvas id="slimstat_chart_<?php echo esc_attr($args['id']); ?>" class="slimstat-postbox-chart--canvas" height="240px"></canvas>
    <?php if ( defined('DOING_AJAX') && DOING_AJAX): ?>
        <script>
            reinitializeSlimstatCharts("<?php echo $args['id']; ?>")
        </script>
    <?php endif; ?>
</div>
<?php
