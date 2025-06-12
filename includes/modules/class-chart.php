<?php

namespace Slimstat\Core\Modules;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class Chart
{

    public  $data         = array();
    public  $prev_data    = array();
    public  $args         = array();
    private $daysBetween  = 0;
    private $chart_labels = array();
    private $translations = array();

    public function show_chart($args)
    {
        $this->setup_args( $args );
        $this->render_chart($this->args, $this->data, $this->prev_data);
    }

    /**
     * Sets up the chart arguments by normalizing them and fetching the data.
     *
     * This method initializes the class properties with the normalized version
     * of the provided arguments and retrieves the data required for charting.
     * It also manages the datasets for the current and previous data states.
     *
     * @param array $args The arguments to be set up for the chart.
     */
    public function setup_args($args) {
        $this->args      = $this->normalize_args($args);
        $this->data      = $this->get_data_for_chart($this->args);
        $this->prev_data = $this->data;

        if (isset($this->data['datasets_prev'])) {
            unset($this->data['datasets_prev']);
            $this->prev_data['datasets'] = $this->prev_data['datasets_prev'];
            unset($this->prev_data['datasets_prev']);
        }
    }

    private function normalize_args($args)
    {
        $args['start'] = isset($args['start']) ? $args['start'] : \wp_slimstat_db::$filters_normalized['utime']['start'];
        $args['end'] = isset($args['end']) ? $args['end'] : \wp_slimstat_db::$filters_normalized['utime']['end'];
        if(isset($_REQUEST['granularity']) && !empty($_REQUEST['granularity']) && in_array($_REQUEST['granularity'], ['yearly', 'monthly', 'weekly', 'daily', 'hourly'])) {
            $args['granularity'] = sanitize_text_field($_REQUEST['granularity']);
        }
        else if (!isset($args['granularity'])) {
            $diff = $args['end'] - $args['start'];
            if ($diff === 27 * 86400) {
                $args['granularity'] = 'daily';
            } else if ($diff >= 1.5 * 365 * 86400) {
                $args['granularity'] = 'yearly';
            } elseif ($diff >= 90 * 86400) {
                $args['granularity'] = 'monthly';
            } elseif ($diff >= 60 * 86400) {
                $args['granularity'] = 'weekly';
            } elseif ($diff >= 45 * 86400) {
                $args['granularity'] = 'daily';
            } elseif ($diff >= 14 * 86400) {
                $args['granularity'] = 'daily';
            } elseif ($diff >= 2 * 86400) {
                $args['granularity'] = 'daily';
            } else {
                $args['granularity'] = 'hourly';
            }
        }

        $args['days_between'] = $this->count_days_between($args['start'], $args['end']);

        return $args;
    }

    private function get_previous_args($args)
    {
        $prev_args   = $args;
        $dtStart     = (new \DateTime())->setTimestamp($args['start']);
        $dtEnd       = (new \DateTime())->setTimestamp($args['end']);
        $daysBetween = 0;
        switch ($args['granularity']) {
            case 'hourly':
                $dtStart->modify('-1 day');
                $dtEnd->modify('-1 day');
                break;
            case 'daily':
                $daysBetween = $dtStart->diff($dtEnd)->days;
                $dtStart->modify('-' . $daysBetween . ' days');
                $dtEnd->modify('-' . $daysBetween . ' days');
                break;
            case 'weekly':
                $dtStart->modify('-1 week');
                $dtEnd->modify('-1 week');
                break;
            case 'monthly':
                $dtStart->modify('-1 year');
                $dtEnd->modify('-1 year');
                break;
            case 'yearly':
                $dtStart->modify('-1 year');
                $dtEnd->modify('-1 year');
                break;
        }
        $prev_args['start'] = $dtStart->getTimestamp();
        $prev_args['end'] = $dtEnd->getTimestamp();
        $prev_args['days_between'] = $daysBetween;

        return $prev_args;
    }


    public function get_data_for_chart($args)
    {
        global $wpdb;
        $params = array();
        $_args  = $args['chart_data'];

        // Set default values
        $start         = isset($args['start']) ? intval($args['start']) : \wp_slimstat_db::$filters_normalized['utime']['start'];
        $end           = isset($args['end']) ? intval($args['end']) : \wp_slimstat_db::$filters_normalized['utime']['end'];
        $granularity   = isset($args['granularity']) ? strtolower($args['granularity']) : 'daily';
        $range         = $end - $start;
        $wp_timezone   = wp_timezone();
        $date_format   = 'Y/m/d';
        $start_of_week = (int) get_option('start_of_week', 1);

        $min_dt = $wpdb->get_var("SELECT MIN(dt) FROM {$wpdb->prefix}slim_stats") - 1;
        if ($min_dt && isset($start) && $start < $min_dt) {
            \wp_slimstat_db::$filters_normalized['utime']['start'] = $min_dt;
            $start = $min_dt;
            $range = $end - $start;
        }

        switch ($granularity) {
            case 'hourly':
                $params['group_by']          = "DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), HOUR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'Y/m/d H:00';
                $params['data_points_count'] = ceil($range / 3600);
                $params['granularity']       = 'HOUR';
                break;
            case 'weekly':
                $params['group_by']          = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), WEEK(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'W, Y';
                $params['data_points_count'] = $this->count_weeks_between($start, $end);
                $params['granularity']       = 'WEEK';

                $start_of_week  = (int) get_option('start_of_week', 1);
                $weekdays       = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
                $weekday_name   = $weekdays[$start_of_week];


                $adjusted_start = strtotime("last $weekday_name", $start + 86400);
                if (date('w', $start) == $start_of_week) {
                    $adjusted_start = strtotime("this $weekday_name", $start);
                }
                $adjusted_end = strtotime("next $weekday_name", $end) - 1;
                $params['adjusted_start']      = $adjusted_start;
                $params['adjusted_end']        = $adjusted_end;
                $params['adjusted_prev_start'] = strtotime("-" . ($adjusted_end - $adjusted_start) . " seconds", $adjusted_start);
                $start = $adjusted_start;
                $end = $adjusted_end;
                break;
            case 'monthly':
                $params['group_by']          = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'F Y';
                $params['data_points_count'] = $this->count_months_between($start, $end);
                $params['granularity']       = 'MONTH';
                break;
            case 'yearly':
                $params['group_by']          = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'Y';
                $params['data_points_count'] = $this->count_years_between($start, $end);
                $params['granularity']       = 'YEAR';
                break;

            case 'daily':
            default:
                $params['group_by']          = "MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = $date_format;
                $params['data_points_count'] = ceil($range / 86400);
                $params['granularity']       = 'DAY';
                break;
        }


        $params['previous_end']   = \wp_slimstat_db::$filters_normalized['utime']['start'] - 1;
        $params['previous_start'] = $params['previous_end'] - \wp_slimstat_db::$filters_normalized['utime']['range'];

        if (empty($_args['where'])) {
            $_args['where'] = '';
        }

        $sql = "
        SELECT MIN(dt) AS dt, {$_args[ 'data1' ]} AS v1, {$_args[ 'data2' ]} AS v2
        FROM {$GLOBALS['wpdb']->prefix}slim_stats
        WHERE " . \wp_slimstat_db::get_combined_where($_args['where'], '*', false) . " AND (dt BETWEEN {$params[ 'previous_start' ]} AND {$params[ 'previous_end' ]} OR dt BETWEEN " . \wp_slimstat_db::$filters_normalized['utime']['start'] . ' AND ' . \wp_slimstat_db::$filters_normalized['utime']['end'] . ")
        GROUP BY {$params[ 'group_by' ]}";

        // Get the data
        $results = \wp_slimstat_db::get_results(
            $sql,
            'dt',
            '',
            $params['group_by'], 'SUM(v1) AS v1, SUM(v2) AS v2'
        );


        $output = array(
            'keys'     => array(),
            'labels'   => array(),
            'datasets' => array(
                'v1' => array(),
                'v2' => array(),
                'v3' => array(),
                'v4' => array()
            )
        );

        // No data? No problem!
        if (!is_array($results) || empty($results)) {
            return $output;
        }

        // Generate the output array (sent to the chart library) by combining all the data collected so far

        // Let's start by initializing all the data points to zero
        for ($i = 0; $i < $params['data_points_count']; $i++) {
            $v1_label = date($params['data_points_label'], strtotime("+$i {$params[ 'granularity' ]}", \wp_slimstat_db::$filters_normalized['utime']['start']));
            $v3_label = date($params['data_points_label'], strtotime("+$i {$params[ 'granularity' ]}", $params['previous_start']));

            $output['keys'][$v1_label] = $i;
            $output['keys'][$v3_label] = $i;

            $output['labels'][] = "'$v1_label'";

            // This is how AmCharts expects the data to be formatted
            $output['datasets']['v1'][] = $output['datasets']['v2'][] = $output['datasets']['v3'][] = $output['datasets']['v4'][] = 0;
        }

        // Now populate all the data points
        foreach ($results as $a_result) {
            $label = date($params['data_points_label'], $a_result['dt']);

            // Data out of range?
            if (!isset($output['keys'][$label])) {
                continue;
            }

            // Does this value belong to the "current" range?
            if ($a_result['dt'] >= \wp_slimstat_db::$filters_normalized['utime']['start'] && $a_result['dt'] <= \wp_slimstat_db::$filters_normalized['utime']['end']) {
                $output['datasets']['v1'][$output['keys'][$label]] = intval($a_result['v1']);
                $output['datasets']['v2'][$output['keys'][$label]] = intval($a_result['v2']);
            } else {
                $output['datasets']['v3'][$output['keys'][$label]] = intval($a_result['v1']);
                $output['datasets']['v4'][$output['keys'][$label]] = intval($a_result['v2']);
            }
        }

        $today = wp_date($params['data_points_label'], time(), $wp_timezone);

        return array(
            'labels'      => $output['labels'],
            'prev_labels' => array_map(function ($label, $index) use ($params, $wp_timezone) {
                $prev_start = $params['previous_start'];
                return date($params['data_points_label'], strtotime("+{$index} {$params['granularity']}", $prev_start));
            }, $output['labels'], array_keys($output['labels'])),
            'datasets'    => array(
                'v1' => $output['datasets']['v1'],
                'v2' => $output['datasets']['v2'],
            ),
            'datasets_prev' => array(
                'v1' => $output['datasets']['v3'],
                'v2' => $output['datasets']['v4'],
            ),
            'today'       => $today,
            'granularity' => $params['granularity'],
        );
    }

    public function count_years_between($start, $end)
    {
        $start = date('Y', $start);
        $end   = date('Y', $end);

        return abs($end - $start) + 1;
    }

    public function count_weeks_between($start, $end)
    {
        $start_week = (int)date('W', $start);
        $start_year = (int)date('Y', $start);
        $end_week   = (int)date('W', $end);
        $end_year   = (int)date('Y', $end);

        $weeks = 0;
        while ($start_year < $end_year || $start_week <= $end_week) {
            $weeks++;
            $start_week++;
            if ($start_week > 52) {
                $start_week = 1;
                $start_year++;
            }
        }

        return $weeks;
    }

    protected function count_months_between($min_timestamp = 0, $max_timestamp = 0)
    {
        $i         = 0;
        $min_month = date('Ym', $min_timestamp);
        $max_month = date('Ym', $max_timestamp);

        while ($min_month <= $max_month) {
            $min_timestamp = strtotime("+1 month", $min_timestamp);
            $min_month     = date('Ym', $min_timestamp);
            $i++;
        }

        return $i;
    }

    protected function count_days_between($start, $end)
    {
        return abs(intval(($end - $start) / 86400)) + 1;
    }

    public static function ajax_fetch_chart_data()
    {
        check_ajax_referer('slimstat_chart_nonce', 'nonce');

        $args = isset($_POST['args']) ? json_decode(stripslashes($_POST['args']), true) : [];
        $granularity = isset($_POST['granularity']) ? sanitize_text_field($_POST['granularity']) : 'daily';

        if (!in_array($granularity, ['yearly', 'monthly', 'weekly', 'daily', 'hourly'])) {
            wp_send_json_error(['message' => __('Invalid granularity', 'wp-slimstat')]);
        }

        if (!class_exists('\wp_slimstat_db')) {
            include_once(SLIMSTAT_DIR . '/admin/view/wp-slimstat-db.php');
            \wp_slimstat_db::init();
        }


        \wp_slimstat_db::$filters_normalized['utime']['start'] = $args['start'];
        \wp_slimstat_db::$filters_normalized['utime']['end']   = $args['end'];
        \wp_slimstat_db::$filters_normalized['utime']['range'] = $args['end'] - $args['start'];

        $chart = (new self());
        $args['granularity'] = $granularity;

        $chart->setup_args($args);

        $data      = $chart->data;
        $prev_data = $chart->prev_data;
        $args      = $chart->args;

        $args['days_between'] = $chart->count_days_between($args['start'], $args['end']);

        $chart_labels = isset($args['chart_labels']) ? $args['chart_labels'] : array_keys($data['datasets']);
        $translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'days_ago'                => sprintf(__('%s Days ago', 'wp-slimstat'), $args['days_between'] ?? 0),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'previous_period_tooltip' => __("-- Previous Period\nTap here to show/hide comparison.", 'wp-slimstat'),
            'today'    => __('Today', 'wp-slimstat'),
            'day_ago'  => __('Day ago', 'wp-slimstat'),
            'year_ago' => __('Year ago', 'wp-slimstat'),
            'yearly'   => __('Yearly', 'wp-slimstat'),
            'monthly'  => __('Monthly', 'wp-slimstat'),
            'weekly'   => __('Weekly', 'wp-slimstat'),
            'daily'    => __('Daily', 'wp-slimstat'),
            'hourly'   => __('Hourly', 'wp-slimstat'),
            'now'      => __('Now', 'wp-slimstat'),
        ];

        wp_send_json_success([
            'args' => $args,
            'data' => $data,
            'prev_data'    => $prev_data,
            'days_between' => $args['days_between'] ?? 0,
            'chart_labels' => $chart_labels,
            'translations' => $translations
        ]);
    }

    private function render_chart()
    {
        $this->chart_labels = isset($this->args['chart_labels']) ? $this->args['chart_labels'] : array_keys($this->data['datasets']);
        $this->translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'previous_period_tooltip' => __('Click to Show or Hide data from the previous period for comparison.', 'wp-slimstat'),
            'today'                   => __('Today', 'wp-slimstat'),
            'days_ago'                => sprintf(__('%s Days ago', 'wp-slimstat'), $this->args['days_between'] ?? 0),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'day_ago'                 => __('Day ago', 'wp-slimstat'),
            'year_ago'                => __('Year ago', 'wp-slimstat'),
            'now'                     => __('Now', 'wp-slimstat'),
        ];

        $path_slimstat = dirname(dirname(__FILE__));
        wp_enqueue_script('slimstat_chartjs', plugins_url('/admin/assets/js/chartjs/chart.min.js', $path_slimstat), [], '4.2.1', false);
        wp_enqueue_script('slimstat_chart', plugins_url('/admin/assets/js/slimstat-chart.js', $path_slimstat), ['slimstat_chartjs'], '1.0', false);

        wp_localize_script('slimstat_chart', 'slimstat_chart_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('slimstat_chart_nonce')
        ]);

        include SLIMSTAT_DIR . '/includes/views/modules/chart.php';
    }
}

add_action('wp_ajax_slimstat_fetch_chart_data', array(__NAMESPACE__ . '\Chart', 'ajax_fetch_chart_data'));
