<?php

namespace SlimStat\Admin;

use SlimStat\Channel\CronScheduler;

/**
 * Settings tab for Traffic Channel Report configuration.
 *
 * Adds Marketing settings to SlimStat's settings page including cron interval
 * configuration and health status display.
 *
 * @package SlimStat\Admin
 * @since 5.1.0
 */
class SettingsTab
{
    /**
     * Initialize settings tab.
     *
     * Registers settings and adds settings fields.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
        add_filter('slimstat_options_on_page', [self::class, 'add_settings_tab'], 10, 2);
    }

    /**
     * Register settings with WordPress Settings API (T039).
     *
     * Registers slimstat_channel_cron_interval option.
     *
     * @return void
     */
    public static function register_settings(): void
    {
        register_setting(
            'slimstat_options',
            'slimstat_channel_cron_interval',
            [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_cron_interval'],
                'default' => 'fifteen_minutes',
            ]
        );
    }

    /**
     * Add Marketing settings tab to SlimStat settings page (T039, T040).
     *
     * Adds cron interval dropdown and health status display.
     *
     * @param array $options Current options array
     * @param string $current_tab Current settings tab
     * @return array Modified options array
     */
    public static function add_settings_tab(array $options, string $current_tab): array
    {
        // Only add to a specific tab (e.g., 'tracker' or create new 'marketing' tab)
        // For simplicity, adding to 'tracker' tab
        if ($current_tab !== 'tracker') {
            return $options;
        }

        // Add section header
        $options['channel_settings_header'] = [
            'description' => __('Configure Traffic Channel Report settings including classification schedule and data processing.', 'wp-slimstat'),
            'type' => 'section_header',
            'title' => __('Traffic Channel Report', 'wp-slimstat'),
        ];

        // Cron interval dropdown (T040)
        $options['slimstat_channel_cron_interval'] = [
            'title' => __('Classification Schedule', 'wp-slimstat'),
            'description' => __('How often should SlimStat classify unclassified visits into traffic channels? More frequent classification provides fresher data but increases server load.', 'wp-slimstat'),
            'type' => 'select',
            'values' => [
                'five_minutes' => __('Every 5 Minutes', 'wp-slimstat'),
                'fifteen_minutes' => __('Every 15 Minutes (Recommended)', 'wp-slimstat'),
                'thirty_minutes' => __('Every 30 Minutes', 'wp-slimstat'),
                'hourly' => __('Hourly', 'wp-slimstat'),
                'twicedaily' => __('Twice Daily', 'wp-slimstat'),
                'daily' => __('Daily', 'wp-slimstat'),
                'manual' => __('Manual Only (Disable Auto Classification)', 'wp-slimstat'),
            ],
            'default_value' => 'fifteen_minutes',
        ];

        // Health status display (informational)
        $health_status = \SlimStat\Channel\HealthMonitor::get_health_status();
        $schedule_info = CronScheduler::get_schedule_info();

        $health_html = '<div class="channel-health-status">';
        $health_html .= '<p><strong>' . esc_html__('Classification Status:', 'wp-slimstat') . '</strong></p>';
        $health_html .= '<ul style="list-style: disc; margin-left: 20px;">';
        $health_html .= '<li>' . esc_html__('Last Run:', 'wp-slimstat') . ' <strong>' . esc_html($health_status['last_run_human']) . '</strong></li>';
        $health_html .= '<li>' . esc_html__('Next Scheduled:', 'wp-slimstat') . ' <strong>' . esc_html($health_status['next_scheduled_human']) . '</strong></li>';
        $health_html .= '<li>' . esc_html__('Unclassified Visits:', 'wp-slimstat') . ' <strong>' . esc_html(number_format_i18n($health_status['unclassified_count'])) . '</strong></li>';
        $health_html .= '<li>' . esc_html__('Current Interval:', 'wp-slimstat') . ' <strong>' . esc_html($schedule_info['interval_display']) . '</strong></li>';
        $health_html .= '</ul>';

        if ($health_status['cron_stale']) {
            $health_html .= '<p class="notice notice-warning inline">';
            $health_html .= esc_html__('⚠️ Warning: Classification cron has not run in over 2 hours. Check WordPress cron configuration.', 'wp-slimstat');
            $health_html .= '</p>';
        }

        $health_html .= '</div>';

        $options['channel_health_status'] = [
            'title' => __('Health Status', 'wp-slimstat'),
            'description' => $health_html,
            'type' => 'custom',
        ];

        return $options;
    }

    /**
     * Sanitize cron interval setting (T040).
     *
     * Validates interval value and reschedules cron if changed.
     *
     * @param string $value User input value
     * @return string Sanitized value
     */
    public static function sanitize_cron_interval(string $value): string
    {
        // Validate interval
        $valid_intervals = [
            'five_minutes',
            'fifteen_minutes',
            'thirty_minutes',
            'hourly',
            'twicedaily',
            'daily',
            'manual',
        ];

        if (!in_array($value, $valid_intervals, true)) {
            $value = 'fifteen_minutes';
        }

        // Get current interval
        $current_interval = get_option('slimstat_channel_cron_interval', 'fifteen_minutes');

        // If interval changed, reschedule cron
        if ($value !== $current_interval) {
            self::reschedule_cron($value);
        }

        return $value;
    }

    /**
     * Reschedule cron with new interval.
     *
     * Unregisters old schedule and registers new one.
     *
     * @param string $new_interval New cron interval
     * @return void
     */
    private static function reschedule_cron(string $new_interval): void
    {
        // Unschedule existing cron
        CronScheduler::unregister();

        // If manual mode, don't reschedule
        if ('manual' === $new_interval) {
            return;
        }

        // Reschedule with new interval
        if (!wp_next_scheduled(CronScheduler::CRON_HOOK)) {
            wp_schedule_event(time(), $new_interval, CronScheduler::CRON_HOOK);
        }
    }
}
