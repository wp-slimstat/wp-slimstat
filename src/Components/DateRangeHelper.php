<?php

namespace SlimStat\Components;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/**
 * SlimStat Date Range Helper
 * Provides server-side date range calculations to keep logic consistent
 * between client-side picker and direct URL access
 */
class DateRangeHelper
{
    /**
     * Get WordPress timezone string or UTC offset
     */
    public static function get_wp_timezone()
    {
        $timezone_string = get_option('timezone_string');

        if ($timezone_string) {
            return $timezone_string;
        }

        $offset = get_option('gmt_offset');
        if ($offset) {
            $hours = (int) $offset;
            $minutes = abs(($offset - $hours) * 60);
            return sprintf('%+03d:%02d', $hours, $minutes);
        }

        return 'UTC';
    }

    /**
     * Get WordPress week start day
     */
    public static function get_week_start()
    {
        return get_option('start_of_week', 1);
    }

    /**
     * Get date format for display
     */
    public static function get_date_format()
    {
        $wp_format = get_option('date_format', 'F j, Y');

        // Convert common PHP date formats to display format
        $format_map = [
            'F j, Y' => 'DD/MM/YYYY',
            'Y-m-d' => 'YYYY-MM-DD',
            'm/d/Y' => 'MM/DD/YYYY',
            'd/m/Y' => 'DD/MM/YYYY',
            'j F Y' => 'DD/MM/YYYY',
            'M j, Y' => 'DD/MM/YYYY'
        ];

        return $format_map[$wp_format] ?? 'DD/MM/YYYY';
    }

    /**
     * Get preset date ranges based on WordPress timezone
     */
    public static function get_preset_ranges()
    {
        $timezone = self::get_wp_timezone();
        $week_start = self::get_week_start();

        // Create DateTime object with site timezone
        if (strpos($timezone, '+') !== false || strpos($timezone, '-') !== false) {
            $tz = new \DateTimeZone($timezone);
        } else {
            try {
                $tz = new \DateTimeZone($timezone);
            } catch (\Exception $e) {
                $tz = new \DateTimeZone('UTC');
            }
        }

        $now = new \DateTime('now', $tz);

        // Helper function to get start of week
        $get_week_start = function($date) use ($week_start) {
            $clone = clone $date;
            $current_day = (int) $clone->format('w'); // 0 = Sunday, 1 = Monday, etc.
            $days_to_subtract = ($current_day - $week_start + 7) % 7;
            return $clone->sub(new \DateInterval("P{$days_to_subtract}D"))->setTime(0, 0, 0);
        };

        $ranges = [
            'today' => [
                'start' => (clone $now)->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'yesterday' => [
                'start' => (clone $now)->sub(new \DateInterval('P1D'))->setTime(0, 0, 0),
                'end' => (clone $now)->sub(new \DateInterval('P1D'))->setTime(23, 59, 59)
            ],
            'this_week' => [
                'start' => $get_week_start($now),
                'end' => (clone $get_week_start($now))->add(new \DateInterval('P6D'))->setTime(23, 59, 59)
            ],
            'last_week' => [
                'start' => $get_week_start((clone $now)->sub(new \DateInterval('P1W'))),
                'end' => (clone $get_week_start((clone $now)->sub(new \DateInterval('P1W'))))->add(new \DateInterval('P6D'))->setTime(23, 59, 59)
            ],
            'this_month' => [
                'start' => (clone $now)->modify('first day of this month')->setTime(0, 0, 0),
                'end' => (clone $now)->modify('last day of this month')->setTime(23, 59, 59)
            ],
            'last_month' => [
                'start' => (clone $now)->modify('first day of last month')->setTime(0, 0, 0),
                'end' => (clone $now)->modify('last day of last month')->setTime(23, 59, 59)
            ],
            'last_7_days' => [
                'start' => (clone $now)->sub(new \DateInterval('P6D'))->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'last_28_days' => [
                'start' => (clone $now)->sub(new \DateInterval('P27D'))->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'last_30_days' => [
                'start' => (clone $now)->sub(new \DateInterval('P29D'))->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'last_90_days' => [
                'start' => (clone $now)->sub(new \DateInterval('P89D'))->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'last_6_months' => [
                'start' => (clone $now)->sub(new \DateInterval('P6M'))->setTime(0, 0, 0),
                'end' => (clone $now)->setTime(23, 59, 59)
            ],
            'this_year' => [
                'start' => (clone $now)->modify('first day of January')->setTime(0, 0, 0),
                'end' => (clone $now)->modify('last day of December')->setTime(23, 59, 59)
            ]
        ];

        // Convert to timestamps for SlimStat compatibility
        // Use UTC timestamps to avoid timezone offset issues
        $timestamp_ranges = [];
        foreach ($ranges as $key => $range) {
            // Convert to UTC to avoid timezone offset issues
            $start_utc = $range['start'];
            $end_utc = $range['end'];

            $timestamp_ranges[$key] = [
                'start' => $start_utc->getTimestamp(),
                'end' => $end_utc->getTimestamp()
            ];
        }

        return $timestamp_ranges;
    }

    /**
     * Parse preset name and return date range
     */
    public static function get_range_by_preset($preset)
    {
        $ranges = self::get_preset_ranges();
        return $ranges[$preset] ?? null;
    }

    /**
     * Convert date range to SlimStat filter format
     */
    public static function convert_to_slimstat_filters($start_date, $end_date)
    {
        $start_timestamp = is_numeric($start_date) ? $start_date : strtotime($start_date);
        $end_timestamp = is_numeric($end_date) ? $end_date : strtotime($end_date);

        if ($start_timestamp === false || $end_timestamp === false) {
            return null;
        }

        // Calculate interval in days (SlimStat style)
        // Normalize to midnight to avoid DST issues and off-by-one errors
        // Use UTC dates to avoid timezone offset issues
        $start_day = strtotime(gmdate('Y-m-d', $start_timestamp) . ' 00:00:00');
        $end_day = strtotime(gmdate('Y-m-d', $end_timestamp) . ' 23:59:59');
        $interval_days = (($end_day - $start_day) / 86400) + 1;

        return [
            'strtotime' => gmdate('Y-m-d', $end_timestamp),
            'interval' => -$interval_days
        ];
    }

    /**
     * Get localized strings for JavaScript
     */
    public static function get_localized_strings()
    {
        return [
            'today' => __('Today', 'wp-slimstat'),
            'yesterday' => __('Yesterday', 'wp-slimstat'),
            'this_week' => __('This week', 'wp-slimstat'),
            'last_week' => __('Last week', 'wp-slimstat'),
            'this_month' => __('This Month', 'wp-slimstat'),
            'last_month' => __('Previous Month', 'wp-slimstat'),
            'last_7_days' => __('Last 7 Days', 'wp-slimstat'),
            'last_28_days' => __('Last 28 Days', 'wp-slimstat'),
            'last_30_days' => __('Last 30 Days', 'wp-slimstat'),
            'last_90_days' => __('Last 90 Days', 'wp-slimstat'),
            'last_6_months' => __('Last 6 Months', 'wp-slimstat'),
            'this_year' => __('This Year', 'wp-slimstat'),
            'custom_range' => __('Custom Range', 'wp-slimstat'),
            'apply' => __('Apply', 'wp-slimstat'),
            'cancel' => __('Cancel', 'wp-slimstat'),
            'clear_cache' => __('Clear Cache', 'wp-slimstat'),
            'clearing' => __('Clearing...', 'wp-slimstat'),
            'cleared' => __('Cleared!', 'wp-slimstat'),
            'error' => __('Error', 'wp-slimstat')
        ];
    }

    /**
     * Validate custom date range
     */
    public static function validate_date_range($start_date, $end_date)
    {
        $start = is_numeric($start_date) ? $start_date : strtotime($start_date);
        $end = is_numeric($end_date) ? $end_date : strtotime($end_date);

        if ($start === false || $end === false) {
            return [
                'valid' => false,
                'error' => __('Invalid date format', 'wp-slimstat')
            ];
        }

        if ($start > $end) {
            return [
                'valid' => false,
                'error' => __('Start date must be before end date', 'wp-slimstat')
            ];
        }

        // Check if range is too long (more than 3 years)
        $max_range = 3 * 365 * 86400; // 3 years in seconds
        if (($end - $start) > $max_range) {
            return [
                'valid' => false,
                'error' => __('Date range too long. Maximum 3 years allowed.', 'wp-slimstat')
            ];
        }

        // Check if dates are in the future
        if ($start > time()) {
            return [
                'valid' => false,
                'error' => __('Start date cannot be in the future', 'wp-slimstat')
            ];
        }

        if ($end > time()) {
            return [
                'valid' => false,
                'error' => __('End date cannot be in the future', 'wp-slimstat')
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get current active date range from filters
     */
    public static function get_current_date_range()
    {
        $defaults = self::get_range_by_preset('last_30_days');

        // Check URL parameters - prioritize type parameter
        if (isset($_GET['type'])) {
            $type = sanitize_key($_GET['type']);
            if ($type !== 'custom') {
                $preset_range = self::get_range_by_preset($type);
                if ($preset_range) {
                    return [
                        'start' => $preset_range['start'],
                        'end' => $preset_range['end'],
                        'preset' => $type
                    ];
                }
            }
        }

        // Check from/to parameters if no valid type parameter
        if (isset($_GET['from']) && isset($_GET['to'])) {
            $from_date = sanitize_text_field($_GET['from']);
            $to_date = sanitize_text_field($_GET['to']);

            // Validate date format before processing
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
                $start = strtotime($from_date);
                $end = strtotime($to_date . ' 23:59:59'); // Include the full end date

                if ($start && $end && $start <= $end && $end <= time()) {
                    return [
                        'start' => $start,
                        'end' => $end,
                        'preset' => 'custom'
                    ];
                }
            }
        }

        // Check SlimStat filters - ensure class exists and property is accessible
        if (class_exists('\wp_slimstat_db') &&
            isset(\wp_slimstat_db::$filters_normalized['date']) &&
            !empty(\wp_slimstat_db::$filters_normalized['date'])) {
            $filters = \wp_slimstat_db::$filters_normalized['date'];

            if (!empty($filters['strtotime']) && !empty($filters['interval'])) {
                // Use UTC to avoid timezone offset issues
                $end_date = strtotime($filters['strtotime'] . ' 23:59:59 UTC'); // Include the full end date
                $interval_days = abs(intval($filters['interval']));
                $start_date = $end_date - (($interval_days - 1) * 86400);

                return [
                    'start' => $start_date,
                    'end' => $end_date,
                    'preset' => self::detect_preset($start_date, $end_date)
                ];
            }
        }

        return [
            'start' => $defaults['start'],
            'end' => $defaults['end'],
            'preset' => 'last_30_days'
        ];
    }

    /**
     * Try to detect which preset matches the given date range
     */
    public static function detect_preset($start_timestamp, $end_timestamp)
    {
        $ranges = self::get_preset_ranges();

        foreach ($ranges as $preset => $range) {
            // Allow some tolerance for time differences (within same day)
            $start_diff = abs($start_timestamp - $range['start']);
            $end_diff = abs($end_timestamp - $range['end']);

            if ($start_diff < 86400 && $end_diff < 86400) {
                return $preset;
            }
        }

        return 'custom';
    }

    /**
     * Format date range for display
     */
    public static function format_date_range($start_timestamp, $end_timestamp, $preset = null)
    {
        // If we have a preset, use the localized string for it
        if ($preset && $preset !== 'custom') {
            $strings = self::get_localized_strings();
            $preset_key = $preset;

            // Map preset names to string keys
            $preset_map = [
                'today' => 'today',
                'yesterday' => 'yesterday',
                'this_week' => 'this_week',
                'last_week' => 'last_week',
                'this_month' => 'this_month',
                'last_month' => 'last_month',
                'last_7_days' => 'last_7_days',
                'last_28_days' => 'last_28_days',
                'last_30_days' => 'last_30_days',
                'last_90_days' => 'last_90_days',
                'last_6_months' => 'last_6_months',
                'this_year' => 'this_year'
            ];

            if (isset($preset_map[$preset]) && isset($strings[$preset_map[$preset]])) {
                return $strings[$preset_map[$preset]];
            }
        }

        // Fallback to date range display
        $date_format = get_option('date_format');

        $start_date = date($date_format, $start_timestamp);
        $end_date = date($date_format, $end_timestamp);

        if ($start_date === $end_date) {
            return $start_date;
        }

        return $start_date . ' – ' . $end_date;
    }
}
