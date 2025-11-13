<?php

namespace SlimStat\Channel;

/**
 * Cron health monitoring for traffic channel classification.
 *
 * Tracks cron execution health and displays admin notices if cron hasn't
 * run within expected intervals (indicates cron system issues).
 *
 * @package SlimStat\Channel
 * @since 5.1.0
 */
class HealthMonitor
{
    /**
     * Maximum time since last cron run before showing warning (seconds).
     *
     * @var int
     */
    public const MAX_CRON_IDLE_TIME = 7200; // 2 hours

    /**
     * Initialize health monitoring.
     *
     * Registers admin notice hooks to display warnings if needed.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_notices', [self::class, 'display_health_notices']);
    }

    /**
     * Display admin notices for cron health issues (T024).
     *
     * Shows warning if cron hasn't run in >2 hours (indicates stuck/disabled cron).
     *
     * @return void
     */
    public static function display_health_notices(): void
    {
        // Only show on SlimStat admin pages
        if (!self::is_slimstat_admin_page()) {
            return;
        }

        // Check if user has permission to see admin notices
        if (!current_user_can('manage_options')) {
            return;
        }

        $health_status = self::get_health_status();

        // Display warning if cron is stale
        if ($health_status['cron_stale']) {
            $message = sprintf(
                /* translators: 1: Time since last run (e.g., "3 hours ago") */
                __(
                    '<strong>SlimStat Traffic Channel Report:</strong> The classification cron has not run in over 2 hours. Last run: %s. This may indicate a WordPress cron issue. <a href="%s" target="_blank">Learn more about WordPress cron</a>.',
                    'wp-slimstat'
                ),
                $health_status['last_run_human'],
                'https://developer.wordpress.org/plugins/cron/'
            );

            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                wp_kses(
                    $message,
                    [
                        'strong' => [],
                        'a' => [
                            'href' => [],
                            'target' => [],
                        ],
                    ]
                )
            );
        }

        // Display info notice if never run (just activated)
        if ($health_status['never_run']) {
            $message = __(
                '<strong>SlimStat Traffic Channel Report:</strong> The classification cron is scheduled but has not run yet. Visit your site to trigger WordPress cron, or wait for the next scheduled run.',
                'wp-slimstat'
            );

            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                wp_kses($message, ['strong' => []])
            );
        }
    }

    /**
     * Get cron health status.
     *
     * @return array Health status with keys:
     *               - 'last_run' (int|null): Unix timestamp of last cron run
     *               - 'last_run_human' (string): Human-readable time since last run
     *               - 'cron_stale' (bool): True if cron hasn't run in >2 hours
     *               - 'never_run' (bool): True if cron has never run
     *               - 'next_scheduled' (int|false): Next scheduled cron run
     *               - 'unclassified_count' (int): Number of visits awaiting classification
     */
    public static function get_health_status(): array
    {
        global $wpdb;

        $last_run = get_option('slimstat_channel_last_cron_run');
        $never_run = empty($last_run);
        $cron_stale = false;

        if (!$never_run) {
            $time_since_run = time() - $last_run;
            $cron_stale = $time_since_run > self::MAX_CRON_IDLE_TIME;
        }

        $last_run_human = $never_run
            ? __('Never', 'wp-slimstat')
            : sprintf(
                /* translators: %s: Time difference (e.g., "2 hours ago") */
                __('%s ago', 'wp-slimstat'),
                human_time_diff($last_run, time())
            );

        // Get next scheduled run
        $next_scheduled = wp_next_scheduled(CronScheduler::CRON_HOOK);

        // Count unclassified visits
        $table_stats = $wpdb->prefix . 'slim_stats';
        $table_channels = $wpdb->prefix . 'slim_channels';
        $unclassified_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table_stats} s
             LEFT JOIN {$table_channels} c ON s.id = c.visit_id
             WHERE c.id IS NULL"
        );

        return [
            'last_run' => $last_run,
            'last_run_human' => $last_run_human,
            'cron_stale' => $cron_stale,
            'never_run' => $never_run,
            'next_scheduled' => $next_scheduled,
            'next_scheduled_human' => $next_scheduled
                ? sprintf(
                    /* translators: %s: Time difference (e.g., "in 10 minutes") */
                    __('in %s', 'wp-slimstat'),
                    human_time_diff(time(), $next_scheduled)
                )
                : __('Not scheduled', 'wp-slimstat'),
            'unclassified_count' => $unclassified_count,
        ];
    }

    /**
     * Check if current admin page is a SlimStat page.
     *
     * @return bool True if on SlimStat admin page
     */
    private static function is_slimstat_admin_page(): bool
    {
        $screen = get_current_screen();

        if (!$screen) {
            return false;
        }

        // Check if page ID contains 'slimstat'
        return false !== strpos($screen->id, 'slimstat');
    }

    /**
     * Force a manual cron run (for testing/debugging).
     *
     * Bypasses the transient lock and runs classification immediately.
     *
     * @return array Statistics from CronScheduler::run_batch_classification()
     */
    public static function force_cron_run(): array
    {
        // Delete lock to allow immediate run
        delete_transient(CronScheduler::LOCK_KEY);

        // Run classification
        return CronScheduler::run_batch_classification();
    }
}
