<?php

namespace SlimStat\Widgets;

/**
 * Widget registrar for channel widgets.
 *
 * Registers channel widgets with SlimStat's report system following
 * the self::$reports array pattern.
 *
 * @package SlimStat\Widgets
 * @since 5.1.0
 */
class ChannelWidgetRegistrar
{
    /**
     * Initialize widget registration.
     *
     * Hooks into SlimStat's reports initialization to register channel widgets.
     *
     * @return void
     */
    public static function init(): void
    {
        // Hook after SlimStat admin and reports are fully initialized (BUG-006 fix)
        // Priority 100 ensures wp_slimstat_reports class is loaded and $reports array initialized
        add_action('admin_init', [self::class, 'register_channel_widgets'], 100);
    }

    /**
     * Register channel widgets with SlimStat reports system (T048-T050).
     *
     * Adds channel widgets to wp_slimstat_reports::$reports array.
     *
     * @return void
     */
    public static function register_channel_widgets(): void
    {
        // Check if SlimStat reports class exists
        if (!class_exists('wp_slimstat_reports')) {
            return;
        }

        // Initialize widgets
        $top_channel_widget = new TopChannelWidget();
        $distribution_widget = new ChannelDistributionWidget();

        // Register AJAX handlers for widgets
        $top_channel_widget->register_ajax_handler();
        $distribution_widget->register_ajax_handler();

        // Register Top Channel Widget (T048)
        \wp_slimstat_reports::$reports['slim_channel_top'] = [
            'title' => __('Top Traffic Channel', 'wp-slimstat'),
            'callback' => [self::class, 'render_top_channel_widget'],
            'callback_args' => [
                'widget' => $top_channel_widget,
            ],
            'classes' => ['normal'], // Widget size class
            'locations' => ['slimstat-marketing'], // Display on Marketing page (BUG-007 fix: match screen ID)
            'tooltip' => __('Shows the traffic channel with the most visits for the selected time period.', 'wp-slimstat'),
        ];

        // Register Channel Distribution Widget (T049)
        \wp_slimstat_reports::$reports['slim_channel_distribution'] = [
            'title' => __('Channel Distribution', 'wp-slimstat'),
            'callback' => [self::class, 'render_distribution_widget'],
            'callback_args' => [
                'widget' => $distribution_widget,
            ],
            'classes' => ['large'], // Larger widget for table + chart
            'locations' => ['slimstat-marketing'], // Display on Marketing page (BUG-007 fix: match screen ID)
            'tooltip' => __('Shows the breakdown of all 8 traffic channel categories with visit counts and percentages.', 'wp-slimstat'),
        ];

        // Allow Pro plugin to register additional widgets via hook
        do_action('slimstat_marketing_widgets');
    }

    /**
     * Render callback for Top Channel Widget.
     *
     * Called by SlimStat's report rendering system.
     * Returns content only (no wrapper) since marketing-page.php provides the postbox container.
     *
     * @param array $args Callback arguments including widget instance
     * @return string Widget HTML output (content only)
     */
    public static function render_top_channel_widget(array $args): string
    {
        $widget = $args['widget'] ?? null;

        if (!$widget instanceof TopChannelWidget) {
            return '<p>' . esc_html__('Widget instance not found.', 'wp-slimstat') . '</p>';
        }

        // Get date range from SlimStat's filter system (will integrate in Phase 5)
        $widget_args = [
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ];

        // Render content only (no wrapper) - postbox container provided by marketing-page.php
        return $widget->render_content_only($widget_args);
    }

    /**
     * Render callback for Channel Distribution Widget.
     *
     * Called by SlimStat's report rendering system.
     * Returns content only (no wrapper) since marketing-page.php provides the postbox container.
     *
     * @param array $args Callback arguments including widget instance
     * @return string Widget HTML output (content only)
     */
    public static function render_distribution_widget(array $args): string
    {
        $widget = $args['widget'] ?? null;

        if (!$widget instanceof ChannelDistributionWidget) {
            return '<p>' . esc_html__('Widget instance not found.', 'wp-slimstat') . '</p>';
        }

        // Get date range from SlimStat's filter system (will integrate in Phase 5)
        $widget_args = [
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ];

        // Render content only (no wrapper) - postbox container provided by marketing-page.php
        return $widget->render_content_only($widget_args);
    }
}
