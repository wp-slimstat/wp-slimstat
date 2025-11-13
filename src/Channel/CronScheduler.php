<?php

namespace SlimStat\Channel;

use SlimStat\Database\ChannelMigration;

/**
 * WordPress cron scheduler for batch channel classification.
 *
 * Implements asynchronous batch classification via WordPress cron system with
 * configurable intervals, transient locking, and performance budgets.
 *
 * @package SlimStat\Channel
 * @since 5.1.0
 */
class CronScheduler
{
    /**
     * Cron hook name for channel classification.
     *
     * @var string
     */
    public const CRON_HOOK = 'slimstat_classify_channels';

    /**
     * Transient key for cron lock mechanism.
     *
     * @var string
     */
    public const LOCK_KEY = 'slimstat_cron_lock';

    /**
     * Maximum execution time per cron run (seconds).
     *
     * Stays under 5s budget to prevent timeouts (T021).
     *
     * @var int
     */
    public const MAX_EXECUTION_TIME = 4;

    /**
     * Batch size for classification (visits per query).
     *
     * @var int
     */
    public const BATCH_SIZE = 1000;

    /**
     * Classification engine instance.
     *
     * @var ClassificationEngine
     */
    private ClassificationEngine $engine;

    /**
     * Constructor.
     *
     * @param ClassificationEngine|null $engine Custom engine (for testing)
     */
    public function __construct(?ClassificationEngine $engine = null)
    {
        $this->engine = $engine ?? new ClassificationEngine();
    }

    /**
     * Register cron schedule and hook.
     *
     * Adds custom 15-minute interval (configurable via settings) and schedules
     * the classification task. Called on plugin activation (T022).
     *
     * @return void
     */
    public static function register(): void
    {
        // Add custom cron interval filter
        add_filter('cron_schedules', [self::class, 'add_custom_intervals']);

        // Schedule the cron event if not already scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $interval = get_option('slimstat_channel_cron_interval', 'fifteen_minutes');
            wp_schedule_event(time(), $interval, self::CRON_HOOK);
        }

        // Register the cron callback
        add_action(self::CRON_HOOK, [self::class, 'run_batch_classification']);
    }

    /**
     * Unregister cron schedule.
     *
     * Removes scheduled event. Called on plugin deactivation.
     *
     * @return void
     */
    public static function unregister(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // Clean up transient lock
        delete_transient(self::LOCK_KEY);
    }

    /**
     * Add custom cron intervals (T018).
     *
     * Provides configurable intervals: 5min, 15min, 30min, hourly, daily.
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified schedules with custom intervals
     */
    public static function add_custom_intervals(array $schedules): array
    {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'wp-slimstat'),
        ];

        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'wp-slimstat'),
        ];

        $schedules['thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'wp-slimstat'),
        ];

        return $schedules;
    }

    /**
     * Run batch classification (T019).
     *
     * Static callback for WordPress cron. Queries unclassified visits and
     * classifies them in batches with transient locking to prevent concurrent runs.
     *
     * @return array Statistics ['classified' => int, 'errors' => int, 'execution_time' => float]
     */
    public static function run_batch_classification(): array
    {
        // Check transient lock to prevent concurrent cron runs (T020)
        if (get_transient(self::LOCK_KEY)) {
            return [
                'classified' => 0,
                'errors' => 0,
                'execution_time' => 0,
                'message' => 'Skipped: Another cron run is in progress',
            ];
        }

        // Set transient lock (10 minutes expiry)
        set_transient(self::LOCK_KEY, time(), 10 * MINUTE_IN_SECONDS);

        // Initialize instance for processing
        $scheduler = new self();
        $result = $scheduler->batch_classify();

        // Release lock
        delete_transient(self::LOCK_KEY);

        // Update health monitoring timestamp (T023)
        update_option('slimstat_channel_last_cron_run', time());

        return $result;
    }

    /**
     * Batch classify unclassified visits (T019, T021).
     *
     * Queries up to BATCH_SIZE unclassified visits and processes them with
     * execution time monitoring to stay under MAX_EXECUTION_TIME budget.
     *
     * @return array Statistics ['classified' => int, 'errors' => int, 'execution_time' => float]
     */
    public function batch_classify(): array
    {
        global $wpdb;

        $table_stats = $wpdb->prefix . 'slim_stats';
        $table_channels = $wpdb->prefix . 'slim_channels';
        $start_time = microtime(true);

        // Get activation timestamp to exclude historical data (FR-002)
        $activation_timestamp = ChannelMigration::get_activation_timestamp();
        if (!$activation_timestamp) {
            return [
                'classified' => 0,
                'errors' => 0,
                'execution_time' => 0,
                'message' => 'Feature not activated yet',
            ];
        }

        $site_domain = parse_url(home_url(), PHP_URL_HOST);
        $classified_count = 0;
        $error_count = 0;

        // Loop until batch size reached or execution time limit hit (T021)
        while ($classified_count < self::BATCH_SIZE) {
            // Check execution time (break after 4 seconds to stay under 5s budget)
            if ((microtime(true) - $start_time) > self::MAX_EXECUTION_TIME) {
                break;
            }

            // Query unclassified visits (LEFT JOIN to find visits without channel classification)
            $sql = $wpdb->prepare(
                "SELECT s.id, s.referer, s.notes, s.resource
                 FROM {$table_stats} s
                 LEFT JOIN {$table_channels} c ON s.id = c.visit_id
                 WHERE c.id IS NULL
                   AND s.dt >= %d
                 ORDER BY s.dt ASC
                 LIMIT 100",
                $activation_timestamp
            );

            $visits = $wpdb->get_results($sql, ARRAY_A);

            // No more unclassified visits
            if (empty($visits)) {
                break;
            }

            // Process batch
            foreach ($visits as $visit) {
                // Classify visit
                $visit_data = [
                    'referer' => $visit['referer'] ?? '',
                    'notes' => $visit['notes'] ?? '',
                    'resource' => $visit['resource'] ?? '',
                    'domain' => $site_domain,
                ];

                $classification = $this->engine->classify_visit($visit_data);

                // Insert into wp_slim_channels
                $insert_result = $wpdb->insert(
                    $table_channels,
                    [
                        'visit_id' => $visit['id'],
                        'channel' => $classification['channel'],
                        'utm_source' => $classification['utm_source'],
                        'utm_medium' => $classification['utm_medium'],
                        'utm_campaign' => $classification['utm_campaign'],
                        'classified_at' => time(),
                        'classification_version' => 1,
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%d', '%d']
                );

                if ($insert_result) {
                    $classified_count++;
                } else {
                    $error_count++;
                    error_log(
                        sprintf(
                            '[SlimStat Cron] Failed to insert classification for visit_id %d: %s',
                            $visit['id'],
                            $wpdb->last_error
                        )
                    );
                }

                // Check execution time again within loop
                if ((microtime(true) - $start_time) > self::MAX_EXECUTION_TIME) {
                    break 2; // Break outer loop
                }
            }
        }

        $execution_time = microtime(true) - $start_time;

        return [
            'classified' => $classified_count,
            'errors' => $error_count,
            'execution_time' => round($execution_time, 3),
        ];
    }

    /**
     * Get cron schedule information.
     *
     * Returns next scheduled run time and interval for display in admin.
     *
     * @return array ['next_run' => int|false, 'interval' => string, 'interval_seconds' => int]
     */
    public static function get_schedule_info(): array
    {
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        $interval = get_option('slimstat_channel_cron_interval', 'fifteen_minutes');
        $schedules = wp_get_schedules();
        $interval_seconds = $schedules[$interval]['interval'] ?? 0;

        return [
            'next_run' => $next_run,
            'interval' => $interval,
            'interval_seconds' => $interval_seconds,
            'interval_display' => $schedules[$interval]['display'] ?? 'Unknown',
        ];
    }
}
