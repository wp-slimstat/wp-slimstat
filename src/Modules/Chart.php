<?php

namespace SlimStat\Modules;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use SlimStat\Components\View;
use SlimStat\Helpers\DataBuckets;

class Chart
{
    public const DAY = 86400;

    public const YEAR = 365 * self::DAY;

    private const GRANULARITIES = ['yearly', 'monthly', 'weekly', 'daily', 'hourly'];

    private array $args = [];

    private array $data = [];

    private array $prevData = [];

    private array $chartLabels = [];

    private array $translations = [];

    public function showChart(array $args): void
    {
        $this->init($args);
        $this->enqueueAssets();
        $this->renderChart();
    }

    public static function ajaxFetchChartData()
    {
        check_ajax_referer('slimstat_chart_nonce', 'nonce');

        $args        = isset($_POST['args']) ? json_decode(stripslashes($_POST['args']), true) : [];
        $granularity = isset($_POST['granularity']) ? sanitize_text_field($_POST['granularity']) : 'daily';

        if (!in_array($granularity, ['yearly', 'monthly', 'weekly', 'daily', 'hourly'], true)) {
            wp_send_json_error(['message' => __('Invalid granularity', 'wp-slimstat')]);
        }

        if (!class_exists('\wp_slimstat_db')) {
            include_once SLIMSTAT_DIR . '/admin/view/wp-slimstat-db.php';
            \wp_slimstat_db::init();
        }

        \wp_slimstat_db::$filters_normalized['utime']['start'] = $args['start'];
        \wp_slimstat_db::$filters_normalized['utime']['end']   = $args['end'];
        \wp_slimstat_db::$filters_normalized['utime']['range'] = $args['end'] - $args['start'];

        try {
            $chart               = new self();
            $args['granularity'] = $granularity;
            $chart->init($args);
            $totals = [
                'current' => [
                    'v1' => (int) ($chart->data['totals'][0]->v1 ?? 0),
                    'v2' => (int) ($chart->data['totals'][0]->v2 ?? 0),
                ],
                'previous' => [
                    'v1' => (int) ($chart->data['totals'][1]->v1 ?? 0),
                    'v2' => (int) ($chart->data['totals'][1]->v2 ?? 0),
                ],
            ];
            wp_send_json_success([
                'args'         => $chart->args,
                'data'         => $chart->data,
                'totals'       => $totals,
                'prev_data'    => $chart->prevData,
                'chart_labels' => $chart->chartLabels,
                'translations' => $chart->translations,
            ]);
        } catch (Exception $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
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
        $this->translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'previous_period_tooltip' => __('Click Tap “Previous Period” to hide or show the previous period line.', 'wp-slimstat'),
            'today'                   => __('Today', 'wp-slimstat'),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'day_ago'                 => __('Day ago', 'wp-slimstat'),
            'year_ago'                => __('Year ago', 'wp-slimstat'),
            'now'                     => __('Now', 'wp-slimstat'),
        ];
        $this->chartLabels = $this->args['chart_labels'] ?? array_keys($this->data['datasets']);
    }

    private function normalizeArgs(array $args): array
    {
        $defaults = [
            'start' => \wp_slimstat_db::$filters_normalized['utime']['start'],
            'end'   => \wp_slimstat_db::$filters_normalized['utime']['end'],
        ];
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

        if ($diff > 1.5 * self::YEAR) {
            return 'yearly';
        }

        if ($diff > 90 * self::DAY) {
            return 'monthly';
        }

        if ($diff > 2 * self::DAY) {
            return 'daily';
        }

        if ($diff > 7 * self::DAY) {
            return 'weekly';
        }

        return 'hourly';
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

        \wp_timezone();
        $dtStart = (new \DateTime())->setTimestamp($args['start']);
        $dtEnd   = (new \DateTime())->setTimestamp($args['end']);

        $dtStart->modify(sprintf('-%s seconds', $rangeSeconds))->setTime(0, 0, 0);
        $dtEnd->modify(sprintf('-%s seconds', $rangeSeconds));

        return [
            'start' => $dtStart->getTimestamp(),
            'end'   => $dtEnd->getTimestamp(),
        ];
    }

    private function buildSql(array $args, array $prevArgs): array
    {
        $range = $args['end'] - $args['start'];

        $common = [
            'start' => $prevArgs['start'],
            'end'   => $prevArgs['end'],
            'range' => $range,
        ];

        switch ($args['granularity']) {
            case 'hourly':
                return $this->sqlFor('HOUR', $args, $common);
            case 'daily':
                return $this->sqlFor('DAY', $args, $common);
            case 'monthly':
                return $this->sqlFor('MONTH', $args, $common);
            case 'weekly':
                return $this->sqlFor('WEEK', $args, $common);
            case 'yearly':
                return $this->sqlFor('YEAR', $args, $common);
            default:
                throw new \WP_Error('invalid_granularity');
        }
    }

    private function sqlFor(string $gran, array $args, array $prevArgs): array
    {
        global $wpdb;
        $data1 = $args['chart_data']['data1'] ?? '';
        $data2 = $args['chart_data']['data2'] ?? '';
        $start = $args['start'];
        $end   = $args['end'];

        $totalOffsetSeconds = (int) $wpdb->get_var('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
        $sign               = ($totalOffsetSeconds < 0) ? '+' : '-';
        $abs                = abs($totalOffsetSeconds);
        $h                  = floor($abs / 3600);
        $m                  = floor(($abs % 3600) / 60);
        $tzOffset           = sprintf('%s%02d:%02d', $sign, $h, $m);

        $startOfWeek = (int) get_option('start_of_week', 1); // default Monday

        switch ($gran) {
            case 'HOUR':
                $dtExpr = sprintf("UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s'), '%%Y-%%m-%%d %%H:00:00'))", $tzOffset);
                break;
            case 'DAY':
                $dtExpr = sprintf("UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s'), '%%Y-%%m-%%d'))", $tzOffset);
                break;
            case 'MONTH':
                $dtExpr = sprintf("UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s'), '%%Y-%%m-01'))", $tzOffset);
                break;
            case 'WEEK':
                $dtExpr = sprintf("UNIX_TIMESTAMP(DATE_FORMAT(DATE_SUB(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s'), INTERVAL ((DAYOFWEEK(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s')) - 1 - %d + 7) %% 7) DAY), '%%Y-%%m-%%d'))", $tzOffset, $tzOffset, $startOfWeek);
                break;
            case 'YEAR':
                $dtExpr = sprintf("UNIX_TIMESTAMP(STR_TO_DATE(CONCAT(YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), '+00:00', '%s')), '-01-01'), '%%Y-%%m-%%d'))", $tzOffset);
                break;
            default:
                throw new \WP_Error('invalid_granularity');
        }

        $periods = [
            'HOUR'  => ['label' => 'Y/m/d H:00:00'],
            'DAY'   => ['label' => 'Y/m/d'],
            'MONTH' => ['label' => 'F Y'],
            'WEEK'  => ['label' => 'Y/m/d'],
            'YEAR'  => ['label' => 'Y'],
        ];

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

        return [
            'sql'       => $sql,
            'totalsSql' => $totalsSql,
            'params'    => ['label' => $periods[$gran]['label'], 'gran' => $gran],
        ];
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
        $prev['datasets'] = $prev['datasets_prev'] ?? [];
        unset($prev['datasets_prev']);

        return $prev;
    }

    private function enqueueAssets(): void
    {
        wp_enqueue_script(
            'slimstat_chartjs',
            plugins_url('/admin/assets/js/chartjs/chart.min.js', SLIMSTAT_FILE),
            [],
            '4.2.1',
            false
        );
        wp_enqueue_script(
            'slimstat_chart',
            plugins_url('/admin/assets/js/slimstat-chart.js', SLIMSTAT_FILE),
            ['slimstat_chartjs'],
            '1.0',
            false
        );
        wp_localize_script('slimstat_chart', 'slimstat_chart_vars', [
            // Use a relative admin-ajax path for the admin chart to avoid cross-origin issues in dev setups
            'ajax_url'        => admin_url('admin-ajax.php', 'relative'),
            'nonce'           => wp_create_nonce('slimstat_chart_nonce'),
            'end_date'        => $this->args['end'] ?? null,
            'end_date_string' => isset($this->args['end']) ? date('Y/m/d H:i:s', $this->args['end']) : null,
            'timezone'        => get_option('timezone_string') ?: 'UTC',
            'start_of_week'   => get_option('start_of_week', 1),
        ]);
    }

    private function renderChart(): void
    {
        View::load('modules/chart-view', [
            'args'         => $this->args,
            'data'         => $this->data,
            'prevData'     => $this->prevData,
            'chartLabels'  => $this->chartLabels,
            'translations' => $this->translations,
        ]);
    }
}
