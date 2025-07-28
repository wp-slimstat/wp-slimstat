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
use DateTime;
use WP_Error;

class Chart
{
    const DAY  = 86400;
    const YEAR = 365 * self::DAY;

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

    private function init(array $args): void
    {
        $normalized = $this->normalizeArgs($args);
        $this->args    = $normalized;
        $this->data    = $this->fetchChartData($normalized);
        $this->prevData = $this->extractPreviousData($this->data);
        $this->translations = [
            'previous_period'         => __('-- Previous Period', 'wp-slimstat'),
            'previous_period_tooltip' => __('Click Tap “Previous Period” to hide or show the previous period line.', 'wp-slimstat'),
            'today'                   => __('Today', 'wp-slimstat'),
            '30_days_ago'             => __('30 Days ago', 'wp-slimstat'),
            'day_ago'                 => __('Day ago', 'wp-slimstat'),
            'year_ago'                => __('Year ago', 'wp-slimstat'),
            'now'                     => __('Now', 'wp-slimstat'),
        ];
        $this->chartLabels = isset($this->args['chart_labels']) ? $this->args['chart_labels'] : array_keys($this->data['datasets']);
    }

    private function normalizeArgs(array $args): array
    {
        $defaults = [
            'start' => \wp_slimstat_db::$filters_normalized['utime']['start'],
            'end'   => \wp_slimstat_db::$filters_normalized['utime']['end'],
        ];
        $args = array_merge($defaults, $args);

        $args['granularity'] = $this->detectGranularity($args);
        // $args['granularity'] = 'monthly'; // Default to monthly for now
        $args['rangeDays']   = $this->countDays($args['start'], $args['end']);
        $args['end']         = $this->adjustEndForDaily($args);

        return $args;
    }

    protected function countDays(int $start, int $end): int
    {
        return max(1, intval(($end - $start) / self::DAY) + 1);
    }

    private function detectGranularity(array $args): string
    {
        if (!empty($_REQUEST['granularity']) && in_array($_REQUEST['granularity'], self::GRANULARITIES, true)) {
            return sanitize_text_field($_REQUEST['granularity']);
        }

        $diff = $args['end'] - $args['start'];
        return match (true) {
            $diff > 1.5 * self::YEAR => 'yearly',
            $diff > 90 *  self::DAY  => 'monthly',
            $diff > 2 * self::DAY   => 'daily',
            $diff > 7  *  self::DAY  => 'weekly',
            default            => 'hourly',
        };
    }

    private function adjustEndForDaily(array $args): int
    {
        if ($args['granularity'] !== 'daily') {
            return $args['end'];
        }

        $time = date('H:i:s', $args['end']);
        if ($time === '00:00:00') {
            return $args['end'] + self::DAY - 1;
        }
        if ($time !== '23:59:59') {
            return strtotime(date('Y-m-d', $args['end']) . ' 23:59:59');
        }

        return $args['end'];
    }

    private function fetchChartData(array $args): array
    {
        global $wpdb;

        $prevArgs = $this->calculatePreviousArgs($args);
        $sqlInfo  = $this->buildSql($args, $prevArgs);
        $results  = $wpdb->get_results($sqlInfo['sql']);

        return $this->processResults(
            $results,
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

        $dtStart = (new DateTime())->setTimestamp($args['start']);
        $dtEnd   = (new DateTime())->setTimestamp($args['end']);

        $dtStart->modify("-{$rangeSeconds} seconds")->modify('midnight');
        $dtEnd->modify("-{$rangeSeconds} seconds");

        return [
            'start' => $dtStart->getTimestamp(),
            'end'   => $dtEnd->getTimestamp(),
        ];
    }

    private function buildSql(array $args, array $prevArgs): array
    {
        $where = $args['chart_data']['where'] ?? [];
        $range = $args['end'] - $args['start'];

        $common = [
            'start' => $prevArgs['start'],
            'end'   => $prevArgs['end'],
            'range' => $range,
        ];

        return match ($args['granularity']) {
            'hourly'  => $this->sqlFor('HOUR', $args, $common),
            'daily'   => $this->sqlFor('DAY', $args, $common),
            'monthly' => $this->sqlFor('MONTH', $args, $common),
            'weekly'  => $this->sqlFor('WEEK', $args, $common),
            'yearly'  => $this->sqlFor('YEAR', $args, $common),
            default   => throw new WP_Error('invalid_granularity'),
        };
    }

    private function sqlFor(string $gran, array $args, array $prevArgs): array
    {
        global $wpdb;
        $where = $args['where'] ?? [];
        $data1 = $args['chart_data']['data1'] ?? '';
        $data2 = $args['chart_data']['data2'] ?? '';
        $start = $args['start'];
        $end   = $args['end'];

        $dtExpr = match ($gran) {
            'HOUR'  => "UNIX_TIMESTAMP(DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'), '%Y-%m-%d %H:00:00'))",
            'DAY'   => "UNIX_TIMESTAMP(DATE(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')))",
            'MONTH' => "UNIX_TIMESTAMP(STR_TO_DATE(CONCAT(YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), '-', LPAD(MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), 2, '0'),'-01'), '%Y-%m-%d'))",
            'WEEK' => "UNIX_TIMESTAMP(STR_TO_DATE(CONCAT(YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), '-', LPAD(WEEK(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'), 3), 2, '0'), '-1'), '%Y-%u-%w'))",
            'YEAR'  => "UNIX_TIMESTAMP(STR_TO_DATE(CONCAT(YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), '-01-01'), '%Y-%m-%d'))",
            default => throw new WP_Error('invalid_granularity'),
        };

        $periods = [
            'HOUR'  => ['label' => 'Y/m/d H:00'],
            'DAY'   => ['label' => 'Y/m/d'],
            'MONTH' => ['label' => 'F Y'],
            'WEEK'  => ['label' => 'W, Y'],
            'YEAR'  => ['label' => 'Y'],
        ];

        // Start of SQL query
        $sql = "
            SELECT
                UNIX_TIMESTAMP(DATE(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))) AS grouped_date,
                MIN(dt) AS dt,
                COUNT(ip) AS v1,
                COUNT(DISTINCT ip) AS v2,
                CASE
                    WHEN dt BETWEEN $start AND $end THEN 'current'
                    ELSE 'previous'
                END AS period
            FROM {$wpdb->prefix}slim_stats
            WHERE (dt BETWEEN {$prevArgs['start']} AND {$prevArgs['end']} OR dt BETWEEN $start AND $end)
            GROUP BY grouped_date, period
            ORDER BY dt, period
        ";
        // End of SQL query

        return [
            'sql'    => $sql,
            'params' => ['label' => $periods[$gran]['label'], 'gran' => $gran],
        ];
    }

    private function processResults(array $rows, array $params, int $start, int $end, int $prevStart, int $prevEnd): array
    {
        $buckets = new DataBuckets($params['label'], $params['gran'], $start, $end, $prevStart, $prevEnd);
        foreach ($rows as $row) {
            $buckets->addRow((int)$row->dt, (int)$row->v1, (int)$row->v2, (string)$row->period);
        }
        return $buckets->toArray();
    }

    private function extractPreviousData(array $data): array
    {
        $prev = $data;
        $prev['datasets'] = $prev['datasets_prev'] ?? [];
        unset($prev['datasets_prev']);
        return $prev;
    }

    private function enqueueAssets(): void
    {
        wp_enqueue_script(
            'slimstat_chartjs',
            plugins_url('/admin/assets/js/chartjs/chart.min.js', SLIMSTAT_FILE),
            [], '4.2.1', false
        );
        wp_enqueue_script(
            'slimstat_chart',
            plugins_url('/admin/assets/js/slimstat-chart.js', SLIMSTAT_FILE),
            ['slimstat_chartjs'], '1.0', false
        );
        wp_localize_script('slimstat_chart', 'slimstat_chart_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('slimstat_chart_nonce'),
        ]);
    }

    public static function ajaxFetchChartData()
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

        try {
            $chart = new self();
            $args['granularity'] = $granularity;
            $chart->init($args);

            wp_send_json_success([
                'args' => $chart->args,
                'data' => $chart->data,
                'prev_data' => $chart->prevData,
                'chart_labels' => $chart->chartLabels,
                'translations' => $chart->translations,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function renderChart(): void
    {
        View::load('modules/chart-view', [
            'args'        => $this->args,
            'data'        => $this->data,
            'prevData'    => $this->prevData,
            'chartLabels' => $this->chartLabels,
            'translations' => $this->translations
        ]);
    }
}