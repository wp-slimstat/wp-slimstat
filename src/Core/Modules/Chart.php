<?php
namespace SlimStat\Core\Modules;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class Chart
{
    public  $data        = array();
    public  $prevData    = array();
    public  $args        = array();
    private $daysBetween = 0;
    private $chartLabels = array();
    private $translations = array();

    public function showChart($args)
    {
        $this->setupArgs($args);
        $this->renderChart($this->args, $this->data, $this->prevData);
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
    public function setupArgs($args) {
        $this->args      = $this->normalizeArgs($args);
        $this->data      = $this->getDataForChart($this->args);
        $this->prevData  = $this->data;

        if (isset($this->data['datasets_prev'])) {
            unset($this->data['datasets_prev']);
            $this->prevData['datasets'] = $this->prevData['datasets_prev'];
            unset($this->prevData['datasets_prev']);
        }
    }

    private function normalizeArgs($args)
    {
        $args['start'] = isset($args['start']) ? $args['start'] : \wp_slimstat_db::$filters_normalized['utime']['start'];
        $args['end'] = isset($args['end']) ? $args['end'] : \wp_slimstat_db::$filters_normalized['utime']['end'];
        if(isset($_REQUEST['granularity']) && !empty($_REQUEST['granularity']) && in_array($_REQUEST['granularity'], ['yearly', 'monthly', 'daily', 'hourly'])) {
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

        $args['daysBetween'] = $this->countDaysBetween($args['start'], $args['end']);

        if (isset($args['granularity']) && $args['granularity'] === 'daily') {
            if (date('H:i:s', $args['end']) === '00:00:00' || date('H:i:s', $args['end']) === '00:00') {
                $args['end'] += 86399;
            } else if (date('H:i:s', $args['end']) !== '23:59:59') {
                $args['end'] = strtotime(date('Y-m-d', $args['end']) . ' 23:59:59');
            }
        }

        return $args;
    }

    private function getPreviousArgs($args)
    {
        $prevArgs   = $args;
        $dtStart    = (new \DateTime())->setTimestamp($args['start']);
        $dtEnd      = (new \DateTime())->setTimestamp($args['end']);
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
            case 'monthly':
                $dtStart->modify('-1 year');
                $dtEnd->modify('-1 year');
                break;
            case 'yearly':
                $dtStart->modify('-1 year');
                $dtEnd->modify('-1 year');
                break;
        }
        $prevArgs['start'] = $dtStart->getTimestamp();
        $prevArgs['end'] = $dtEnd->getTimestamp();
        $prevArgs['daysBetween'] = $daysBetween;

        return $prevArgs;
    }


    public function getDataForChart($args)
    {
        global $wpdb;
        $params = array();
        $_args  = $args['chart_data'];

        // Set default values
        $start         = isset($args['start']) ? intval($args['start']) : \wp_slimstat_db::$filters_normalized['utime']['start'];
        $end           = isset($args['end']) ? intval($args['end']) : \wp_slimstat_db::$filters_normalized['utime']['end'];
        $granularity   = isset($args['granularity']) ? strtolower($args['granularity']) : 'daily';
        $range         = $end - $start;
        $wpTimezone    = wp_timezone();
        $dateFormat    = 'Y/m/d';

        $minDt = $wpdb->get_var("SELECT MIN(dt) FROM {$wpdb->prefix}slim_stats");
        $dbQueryStart = $start;
        if ($minDt && isset($start) && $start < $minDt) {
            $dbQueryStart = $minDt;
        }

        switch ($granularity) {
            case 'hourly':
                $params['group_by']          = "DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), HOUR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'Y/m/d H:00';
                $params['data_points_count'] = ceil($range / 3600);
                $params['granularity']       = 'HOUR';
                break;
            case 'monthly':
                $params['group_by']          = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'F Y';
                $params['data_points_count'] = $this->countMonthsBetween($start, $end);
                $params['granularity']       = 'MONTH';
                $month_labels = [];
                $month_keys = [];
                $cur = strtotime(date('Y-m-01', $start));
                $i = 0;
                while ($cur <= $end) {
                    $label = date($params['data_points_label'], $cur);
                    $month_labels[] = "'$label'";
                    $month_keys[$label] = $i;
                    $cur = strtotime('+1 month', $cur);
                    $i++;
                }
                $params['custom_labels'] = $month_labels;
                break;
            case 'yearly':
                $params['group_by']          = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = 'Y';
                $params['data_points_count'] = $this->countYearsBetween($start, $end);
                $params['granularity']       = 'YEAR';
                break;

            case 'daily':
            default:
                $params['group_by']          = "MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
                $params['data_points_label'] = $dateFormat;
                $params['data_points_count'] = floor($range / 86400) + 1;
                $params['granularity']       = 'DAY';
                $day_labels = [];
                $day_keys = [];
                $cur = $start;
                $i = 0;
                while ($cur <= $end) {
                    $label = date($params['data_points_label'], $cur);
                    $day_labels[] = "'$label'";
                    $day_keys[$label] = $i;
                    $cur = strtotime('+1 day', $cur);
                    $i++;
                }
                $params['custom_labels'] = $day_labels;
                break;
        }


        $params['previous_end']   = \wp_slimstat_db::$filters_normalized['utime']['start'] - 1;
        $params['previous_start'] = $params['previous_end'] - \wp_slimstat_db::$filters_normalized['utime']['range'];

        if (empty($_args['where'])) {
            $_args['where'] = '';
        }

        $sql = "
        SELECT MIN(dt) AS dt, {$_args['data1']} AS v1, {$_args['data2']} AS v2
        FROM {$GLOBALS['wpdb']->prefix}slim_stats
        WHERE " . \wp_slimstat_db::get_combined_where($_args['where'], '*', false) . " AND (dt BETWEEN {$params['previous_start']} AND {$params['previous_end']} OR dt BETWEEN $dbQueryStart AND $end )
        GROUP BY {$params['group_by']}";

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

        if (isset($params['custom_keys']) && isset($params['custom_labels'])) {
            foreach ($params['custom_keys'] as $label => $index) {
                $output['keys'][$label] = $index;
                $output['labels'][] = "'$label'";
                $output['datasets']['v1'][] = 0;
                $output['datasets']['v2'][] = 0;
                $output['datasets']['v3'][] = 0;
                $output['datasets']['v4'][] = 0;
            }
        } else {
            for ($i = 0; $i < $params['data_points_count']; $i++) {
                $v1_label = date($params['data_points_label'], strtotime("+$i {$params['granularity']}", \wp_slimstat_db::$filters_normalized['utime']['start']));
                $v3_label = date($params['data_points_label'], strtotime("+$i {$params['granularity']}", $params['previous_start']));

                $output['keys'][$v1_label] = $i;
                $output['keys'][$v3_label] = $i;

                $output['labels'][] = "'$v1_label'";
                $output['datasets']['v1'][] = 0;
                $output['datasets']['v2'][] = 0;
                $output['datasets']['v3'][] = 0;
                $output['datasets']['v4'][] = 0;
            }
        }

        foreach ($results as $aResult) {
            $label = date($params['data_points_label'], $aResult['dt']);
            if (!isset($output['keys'][$label])) {
                continue;
            }

            if ($aResult['dt'] >= \wp_slimstat_db::$filters_normalized['utime']['start'] && $aResult['dt'] <= \wp_slimstat_db::$filters_normalized['utime']['end']) {
                $output['datasets']['v1'][$output['keys'][$label]] = intval($aResult['v1']);
                $output['datasets']['v2'][$output['keys'][$label]] = intval($aResult['v2']);
            } else {
                $output['datasets']['v3'][$output['keys'][$label]] = intval($aResult['v1']);
                $output['datasets']['v4'][$output['keys'][$label]] = intval($aResult['v2']);
            }
        }

        $today = wp_date($params['data_points_label'], time(), $wpTimezone);

        return array(
            'labels'      => $output['labels'],
            'prev_labels' => array_map(function ($label, $index) use ($params, $wpTimezone) {
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

    public function countYearsBetween($start, $end)
    {
        $start = date('Y', $start);
        $end   = date('Y', $end);

        return abs($end - $start) + 1;
    }

    protected function countMonthsBetween($minTimestamp = 0, $maxTimestamp = 0)
    {
        $i         = 0;
        $minMonth  = date('Ym', $minTimestamp);
        $maxMonth  = date('Ym', $maxTimestamp);

        while ($minMonth <= $maxMonth) {
            $minTimestamp = strtotime("+1 month", $minTimestamp);
            $minMonth     = date('Ym', $minTimestamp);
            $i++;
        }

        return $i;
    }

    protected function countDaysBetween($start, $end)
    {
        return abs(intval(($end - $start) / 86400)) + 1;
    }

    public static function ajaxFetchChartData()
    {
        check_ajax_referer('slimstat_chart_nonce', 'nonce');

        $args = isset($_POST['args']) ? json_decode(stripslashes($_POST['args']), true) : [];
        $granularity = isset($_POST['granularity']) ? sanitize_text_field($_POST['granularity']) : 'daily';

        if (!in_array($granularity, ['yearly', 'monthly', 'daily', 'hourly'])) {
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

        $chart->setupArgs($args);

        $data      = $chart->data;
        $prev_data = $chart->prevData;
        $args      = $chart->args;

        $args['daysBetween'] = $chart->countDaysBetween($args['start'], $args['end']);

        $chartLabels = isset($args['chart_labels']) ? $args['chart_labels'] : array_keys($data['datasets']);
        $translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'days_ago'                => sprintf(__('%s Days ago', 'wp-slimstat'), $args['daysBetween'] ?? 0),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'previous_period_tooltip' => __("Tap “Previous Period” to hide or show the previous period line.", 'wp-slimstat'),
            'today'    => __('Today', 'wp-slimstat'),
            'day_ago'  => __('Day ago', 'wp-slimstat'),
            'year_ago' => __('Year ago', 'wp-slimstat'),
            'yearly'   => __('Yearly', 'wp-slimstat'),
            'monthly'  => __('Monthly', 'wp-slimstat'),
            'daily'    => __('Daily', 'wp-slimstat'),
            'hourly'   => __('Hourly', 'wp-slimstat'),
            'now'      => __('Now', 'wp-slimstat'),
        ];

        wp_send_json_success([
            'args' => $args,
            'data' => $data,
            'prev_data'    => $prev_data,
            'days_between' => $args['daysBetween'] ?? 0,
            'chart_labels' => $chartLabels,
            'translations' => $translations
        ]);
    }

    private function renderChart()
    {
        $this->chartLabels = isset($this->args['chart_labels']) ? $this->args['chart_labels'] : array_keys($this->data['datasets']);
        $this->translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'previous_period_tooltip' => __('Click Tap “Previous Period” to hide or show the previous period line.', 'wp-slimstat'),
            'today'                   => __('Today', 'wp-slimstat'),
            'days_ago'                => sprintf(__('%s Days ago', 'wp-slimstat'), $this->args['daysBetween'] ?? 0),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'day_ago'                 => __('Day ago', 'wp-slimstat'),
            'year_ago'                => __('Year ago', 'wp-slimstat'),
            'now'                     => __('Now', 'wp-slimstat'),
        ];

        $path_slimstat = dirname(dirname(__FILE__));
        wp_enqueue_script('slimstat_chartjs', plugins_url('/admin/assets/js/chartjs/chart.min.js', SLIMSTAT_FILE), [], '4.2.1', false);
        wp_enqueue_script('slimstat_chart', plugins_url('/admin/assets/js/slimstat-chart.js', SLIMSTAT_FILE), ['slimstat_chartjs'], '1.0', false);

        wp_localize_script('slimstat_chart', 'slimstat_chart_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('slimstat_chart_nonce')
        ]);

        include SLIMSTAT_DIR . '/src/Core/Views/Modules/ChartView.php';
    }
}
