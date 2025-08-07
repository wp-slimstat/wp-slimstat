<?php

namespace SlimStat\Modules;

// don't load directly.
if (!defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');

    exit;
}

use SlimStat\Components\View;
use SlimStat\Helpers\DataBuckets;

class Chart
{
    public const DAY            = 86400;
    public const YEAR           = 365 * self::DAY;
    private const GRANULARITIES = array('yearly', 'monthly', 'weekly', 'daily', 'hourly');
    private array $args         = array();
    private array $data         = array();
    private array $prevData     = array();
    private array $chartLabels  = array();
    private array $translations = array();

    public function showChart(array $args): void
    {
        $this->init($args);
        $this->enqueueAssets();
        $this->renderChart();
    }

    public static function ajaxFetchChartData()
    {
        check_ajax_referer('slimstat_chart_nonce', 'nonce');

        $args        = isset($_POST['args']) ? json_decode(stripslashes($_POST['args']), true) : array();
        $granularity = isset($_POST['granularity']) ? sanitize_text_field($_POST['granularity']) : 'daily';

        if (!in_array($granularity, array('yearly', 'monthly', 'weekly', 'daily', 'hourly'), true)) {
            wp_send_json_error(array('message' => __('Invalid granularity', 'wp-slimstat')));
        }

        if (!class_exists('\wp_slimstat_db')) {
            include_once SLIMSTAT_DIR.'/admin/view/wp-slimstat-db.php';
            \wp_slimstat_db::init();
        }

        \wp_slimstat_db::$filters_normalized['utime']['start'] = $args['start'];
        \wp_slimstat_db::$filters_normalized['utime']['end']   = $args['end'];
        \wp_slimstat_db::$filters_normalized['utime']['range'] = $args['end'] - $args['start'];

        try {
            $chart               = new self();
            $args['granularity'] = $granularity;
            $chart->init($args);
            $totals = array(
                'current'  => array(
                    'v1' => (int) ($chart->data['totals'][0]->v1 ?? 0),
                    'v2' => (int) ($chart->data['totals'][0]->v2 ?? 0),
                ),
                'previous' => array(
                    'v1' => (int) ($chart->data['totals'][1]->v1 ?? 0),
                    'v2' => (int) ($chart->data['totals'][1]->v2 ?? 0),
                ),
            );
            wp_send_json_success(array(
                'args'         => $chart->args,
                'data'         => $chart->data,
                'totals'       => $totals,
                'prev_data'    => $chart->prevData,
                'chart_labels' => $chart->chartLabels,
                'translations' => $chart->translations,
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    protected function countDays(int $start, int $end): int
    {
        return max(1, intval(($end - $start) / self::DAY) + 1);
    }

    private function init(array $args): void
    {
        $normalized         = $this->normalizeArgs($args);
        $this->args         = $normalized;
        $this->data         = $this->fetchChartData($normalized);
        $this->prevData     = $this->extractPreviousData($this->data);
        $this->translations = array(
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'previous_period_tooltip' => __('Click Tap “Previous Period” to hide or show the previous period line.', 'wp-slimstat'),
            'today'                   => __('Today', 'wp-slimstat'),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'day_ago'                 => __('Day ago', 'wp-slimstat'),
            'year_ago'                => __('Year ago', 'wp-slimstat'),
            'now'                     => __('Now', 'wp-slimstat'),
        );
        $this->chartLabels = isset($this->args['chart_labels']) ? $this->args['chart_labels'] : array_keys($this->data['datasets']);
    }

    private function normalizeArgs(array $args): array
    {
        $defaults = array(
            'start' => \wp_slimstat_db::$filters_normalized['utime']['start'],
            'end'   => \wp_slimstat_db::$filters_normalized['utime']['end'],
        );
        $args = array_merge($defaults, $args);

        $args['granularity'] = $this->detectGranularity($args);
        $args['rangeDays']   = $this->countDays($args['start'], $args['end']);

        return $args;
    }

    private function detectGranularity(array $args): string
    {
        if (!empty($_REQUEST['granularity']) && in_array($_REQUEST['granularity'], self::GRANULARITIES, true)) {
            return sanitize_text_field($_REQUEST['granularity']);
        }

        $diff = $args['end'] - $args['start'];

        return match (true) {
            $diff > 1.5 * self::YEAR => 'yearly',
            $diff > 90 * self::DAY   => 'monthly',
            $diff > 2 * self::DAY    => 'daily',
            $diff > 7 * self::DAY    => 'weekly',
            default                  => 'hourly',
        };
    }

    private function fetchChartData(array $args): array
    {
        global $wpdb;

        $prevArgs = $this->calculatePreviousArgs($args);
        $sqlInfo  = $this->buildSql($args, $prevArgs);
        $results  = $wpdb->get_results($sqlInfo['sql']);
        $totals   = $wpdb->get_results($sqlInfo['totalsSql']);

        return $this->processResults(
            $results,
            $totals,
            $sqlInfo['params'],
            $args['start'],
            $args['end'],
            $prevArgs['start'],
            $prevArgs['end']
        );
    }

    private function calculatePreviousArgs(array $args): array
    {
        $rangeSeconds = $args['end'] - $args['start'];

        $tz      = \wp_timezone();
        $dtStart = (new \DateTime())->setTimestamp($args['start']);
        $dtEnd   = (new \DateTime())->setTimestamp($args['end']);

        $dtStart->modify("-{$rangeSeconds} seconds")->setTime(0, 0, 0);
        $dtEnd->modify("-{$rangeSeconds} seconds");

        return array(
            'start' => $dtStart->getTimestamp(),
            'end'   => $dtEnd->getTimestamp(),
        );
    }

    private function buildSql(array $args, array $prevArgs): array
    {
        $where = $args['chart_data']['where'] ?? array();
        $range = $args['end'] - $args['start'];

        $common = array(
            'start' => $prevArgs['start'],
            'end'   => $prevArgs['end'],
            'range' => $range,
        );

        return match ($args['granularity']) {
            'hourly'  => $this->sqlFor('HOUR', $args, $common),
            'daily'   => $this->sqlFor('DAY', $args, $common),
            'monthly' => $this->sqlFor('MONTH', $args, $common),
            'weekly'  => $this->sqlFor('WEEK', $args, $common),
            'yearly'  => $this->sqlFor('YEAR', $args, $common),
            default   => throw new \WP_Error('invalid_granularity'),
        };
    }

    private function sqlFor(string $gran, array $args, array $prevArgs): array
    {
        global $wpdb;

        $where = $args['where'] ?? array();
        $data1 = $args['chart_data']['data1'] ?? '';
        $data2 = $args['chart_data']['data2'] ?? '';
        $start = $args['start'];
        $end   = $args['end'];

        $offset_seconds = $wpdb->get_var("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())");
        $sign           = ($offset_seconds < 0) ? '+' : '-';
        $abs            = abs($offset_seconds);
        $h              = floor($abs / 3600);
        $m              = floor(($abs % 3600) / 60);
        $tzOffset       = sprintf('%s%02d:%02d', $sign, $h, $m);
        $startOfWeek    = (int) get_option('start_of_week', 1); // default Monday

        switch ($gran) {
            case 'HOUR':
                $dtExpr = "UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '$tzOffset'), '%Y-%m-%d %H:00:00'))";
                break;
            case 'DAY':
                $dtExpr = "UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '$tzOffset'), '%Y-%m-%d'))";
                break;
            case 'MONTH':
                $dtExpr = "UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '$tzOffset'), '%Y-%m-01'))";
                break;
            case 'WEEK':
                $dtExpr = "UNIX_TIMESTAMP(DATE_FORMAT(DATE_SUB(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '{$tzOffset}'), INTERVAL ((DAYOFWEEK(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '{$tzOffset}')) - 1 - {$startOfWeek} + 7) % 7) DAY), '%Y-%m-%d'))";
                break;
            case 'YEAR':
                $dtExpr = "UNIX_TIMESTAMP(STR_TO_DATE(CONCAT(YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '{$tzOffset}')), '-01-01'), '%Y-%m-%d'))";
                break;
            default:
                throw new \WP_Error('invalid_granularity');
        }

        $periods = array(
            'HOUR'  => array('label' => 'Y/m/d H:00:00'),
            'DAY'   => array('label' => 'Y/m/d'),
            'MONTH' => array('label' => 'F Y'),
            'WEEK'  => array('label' => 'Y/m/d'),
            'YEAR'  => array('label' => 'Y'),
        );

        $sql = "
            SELECT
                grouped_date AS dt,
                v1,
                v2,
                period
            FROM (
                SELECT
                    MIN(dt) AS dt,
                    {$data1} AS v1,
                    {$data2} AS v2,
                    CASE
                        WHEN dt BETWEEN {$start} AND {$end} THEN 'current'
                        ELSE 'previous'
                    END AS period,
                    {$dtExpr} AS grouped_date
                FROM {$wpdb->prefix}slim_stats
                WHERE dt BETWEEN {$prevArgs['start']} AND {$prevArgs['end']}
                OR dt BETWEEN {$start} AND {$end}
                GROUP BY grouped_date, period
            ) AS grouped_data
            ORDER BY dt, period
        ";

        // Total V1 and V2
        $totalsSql = "
            SELECT
                {$data1} AS v1,
                {$data2} AS v2,
                CASE
                    WHEN dt BETWEEN {$start} AND {$end} THEN 'current'
                    ELSE 'previous'
                END AS period
            FROM {$wpdb->prefix}slim_stats
            WHERE CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '{$tzOffset}') BETWEEN FROM_UNIXTIME({$prevArgs['start']}) AND FROM_UNIXTIME({$prevArgs['end']})
            OR CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '{$tzOffset}') BETWEEN FROM_UNIXTIME({$start}) AND FROM_UNIXTIME({$end})
            GROUP BY period
            ORDER BY period
        ";

        return array(
            'sql'       => $sql,
            'totalsSql' => $totalsSql,
            'params'    => array('label' => $periods[$gran]['label'], 'gran' => $gran),
        );
    }

    private function processResults(array $rows, array $totals, array $params, int $start, int $end, int $prevStart, int $prevEnd): array
    {
        $buckets = new DataBuckets($params['label'], $params['gran'], $start, $end, $prevStart, $prevEnd, $totals);
        foreach ($rows as $row) {
            $buckets->addRow((int) $row->dt, (int) $row->v1, (int) $row->v2, (string) $row->period);
        }

        return $buckets->toArray();
    }

    private function extractPreviousData(array $data): array
    {
        $prev             = $data;
        $prev['datasets'] = $prev['datasets_prev'] ?? array();
        unset($prev['datasets_prev']);

        return $prev;
    }

    private function enqueueAssets(): void
    {
        wp_enqueue_script(
            'slimstat_chartjs',
            plugins_url('/admin/assets/js/chartjs/chart.min.js', SLIMSTAT_FILE),
            array(),
            '4.2.1',
            false
        );
        wp_enqueue_script(
            'slimstat_chart',
            plugins_url('/admin/assets/js/slimstat-chart.js', SLIMSTAT_FILE),
            array('slimstat_chartjs'),
            '1.0',
            false
        );
        wp_localize_script('slimstat_chart', 'slimstat_chart_vars', array(
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('slimstat_chart_nonce'),
            'end_date'        => isset($this->args['end']) ? $this->args['end'] : null,
            'end_date_string' => isset($this->args['end']) ? date('Y/m/d H:i:s', $this->args['end']) : null,
            'timezone'        => get_option('timezone_string') ?: 'UTC',
            'start_of_week'   => get_option('start_of_week', 1),
        ));
    }

    private function renderChart(): void
    {
        View::load('modules/chart-view', array(
            'args'         => $this->args,
            'data'         => $this->data,
            'prevData'     => $this->prevData,
            'chartLabels'  => $this->chartLabels,
            'translations' => $this->translations,
        ));
    }
}
