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
use SlimStat\Utils\Query;

class Chart
{
    public const DAY = 86400;

    public const YEAR = 365 * self::DAY;

    private const GRANULARITIES = ['yearly', 'monthly', 'weekly', 'daily', 'hourly'];

    private const CHART_TYPES = ['line', 'bar'];

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

        // Additional capability check - users must be able to view stats
        $minimum_capability = 'read';
        if (!current_user_can($minimum_capability)) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        $args        = isset($_POST['args']) ? json_decode(stripslashes($_POST['args']), true) : [];
        $granularity = isset($_POST['granularity']) ? sanitize_text_field($_POST['granularity']) : 'daily';

        if (!in_array($granularity, ['yearly', 'monthly', 'weekly', 'daily', 'hourly'], true)) {
            wp_send_json_error(['message' => __('Invalid granularity', 'wp-slimstat')]);
        }
        
        // Validate and sanitize start/end timestamps
        if (isset($args['start'])) {
            $args['start'] = absint($args['start']);
        }
        if (isset($args['end'])) {
            $args['end'] = absint($args['end']);
        }

        if (!class_exists('\wp_slimstat_db')) {
            include_once SLIMSTAT_DIR . '/admin/view/wp-slimstat-db.php';
            \wp_slimstat_db::init();
        }

        // Restore filters from args if provided; validate column keys against known schema
        if (!empty($args['filters']) && is_array($args['filters'])) {
            $allowed_columns = array_keys(\wp_slimstat_db::$columns_names);
            foreach ($args['filters'] as $col => $val) {
                if (in_array($col, $allowed_columns, true)) {
                    \wp_slimstat_db::$filters_normalized['columns'][$col] = $val;
                }
            }
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
        } catch (\Exception $exception) {
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
            'start'      => \wp_slimstat_db::$filters_normalized['utime']['start'],
            'end'        => \wp_slimstat_db::$filters_normalized['utime']['end'],
            'chart_type' => 'line',
        ];
        $args = array_merge($defaults, $args);

        // Validate chart type
        if (!in_array($args['chart_type'], self::CHART_TYPES, true)) {
            $args['chart_type'] = 'line';
        }

        $args['granularity'] = $this->detectGranularity($args);
        $args['rangeDays']   = $this->countDays($args['start'], $args['end']);

        // Preserve active filters for AJAX requests
        if (!isset($args['filters'])) {
            $args['filters'] = \wp_slimstat_db::$filters_normalized['columns'] ?? [];
        }

        // Ensure chart_data is present with defaults
        if (!isset($args['chart_data'])) {
            $args['chart_data'] = [
                'data1' => 'COUNT( ip )',
                'data2' => 'COUNT( DISTINCT ip )',
            ];
        }

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

        if ($diff > 7 * self::DAY) {
            return 'weekly';
        }

        if ($diff > 2 * self::DAY) {
            return 'daily';
        }

        return 'hourly';
    }

    private function fetchChartData(array $args): array
    {
        $prevArgs = $this->calculatePreviousArgs($args);
        $sqlInfo  = $this->buildSql($args, $prevArgs);

        // Allow caching only if both current and previous ranges end before today
        $todayStart     = strtotime(date('Y-m-d 00:00:00'));
        $canCacheRanges = ($args['end'] < $todayStart && $prevArgs['end'] < $todayStart);

        $rowsQuery   = $sqlInfo['query'];
        $totalsQuery = $sqlInfo['totalsQuery'];

        if ($rowsQuery instanceof Query) {
            $rowsQuery->allowCaching($canCacheRanges, DAY_IN_SECONDS);
        }

        if ($totalsQuery instanceof Query) {
            $totalsQuery->allowCaching($canCacheRanges, DAY_IN_SECONDS);
        }

        $results = $rowsQuery instanceof Query ? $rowsQuery->getAll() : [];
        $totals  = $totalsQuery instanceof Query ? $totalsQuery->getAll() : [];

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
        $wpdb = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $data1 = $args['chart_data']['data1'] ?? '';
        $data2 = $args['chart_data']['data2'] ?? '';
        
        // Validate SQL expressions to prevent SQL injection
        $data1 = $this->validateSqlExpression($data1);
        $data2 = $this->validateSqlExpression($data2);
        
        // Ensure timestamps are integers (defense in depth)
        $start = absint($args['start']);
        $end   = absint($args['end']);
        $prevStart = absint($prevArgs['start']);
        $prevEnd = absint($prevArgs['end']);

        // Build WHERE clause from active filters (excluding time filters)
        $filterWhere = $this->buildFilterWhere();

        // Add chart-specific WHERE clause if provided
        if (!empty($args['chart_data']['where'])) {
            $chartWhere = $args['chart_data']['where'];
            $filterWhere = !empty($filterWhere) ? $filterWhere . ' AND ' . $chartWhere : $chartWhere;
        }

        // Use UNIX_TIMESTAMP difference for broad MySQL 5.0.x compatibility.
        // The sign appears inverted vs DataBuckets.php — this is INTENTIONAL:
        // FROM_UNIXTIME(dt) returns server-local time, but CONVERT_TZ source '+00:00'
        // declares it as UTC. The "inverted" sign cancels the implicit timezone shift,
        // producing actual UTC. DataBuckets then applies the correct offset for display.
        $totalOffsetSeconds = (int) $wpdb->get_var('SELECT UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(UTC_TIMESTAMP())');
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

        // Build main grouped query via Query builder
        $fields = implode(",\n                ", [
            $dtExpr . ' AS dt',
            'MIN(dt) AS sort_dt',
            $data1 . ' AS v1',
            $data2 . ' AS v2',
            sprintf("CASE WHEN dt BETWEEN %s AND %s THEN 'current' ELSE 'previous' END AS period", $start, $end),
        ]);

        // Wrap the OR time ranges in an extra pair of parentheses so subsequent
        // AND filters are applied to the whole time expression instead of
        // binding tighter to only the latter OR clause.
        $rowsQuery = Query::select($fields)
            ->from($wpdb->prefix . 'slim_stats')
            ->whereRaw('((dt BETWEEN %d AND %d) OR (dt BETWEEN %d AND %d))', [$prevArgs['start'], $prevArgs['end'], $start, $end]);

        // Apply additional filters if any
        if (!empty($filterWhere)) {
            $rowsQuery->whereRaw($filterWhere);
        }

        $rowsQuery->groupBy($dtExpr . ', period')
            ->orderBy('sort_dt ASC, period ASC');

        // Build totals query via Query builder
        // No CONVERT_TZ needed for totals - dt is already stored as UTC timestamp and filters use UTC
    $totalsFields = sprintf("%s AS v1, %s AS v2, CASE WHEN dt BETWEEN %s AND %s THEN 'current' ELSE 'previous' END AS period", $data1, $data2, $start, $end);
    // Ensure totals WHERE uses grouped OR so filters are applied correctly.
    $totalsWhere  = '((dt BETWEEN %d AND %d) OR (dt BETWEEN %d AND %d))';
        $totalsQuery  = Query::select($totalsFields)
            ->from($wpdb->prefix . 'slim_stats')
            ->whereRaw($totalsWhere, [$prevArgs['start'], $prevArgs['end'], $start, $end]);

        // Apply additional filters if any
        if (!empty($filterWhere)) {
            $totalsQuery->whereRaw($filterWhere);
        }

        $totalsQuery->groupBy('period')
            ->orderBy('period ASC');

        return [
            'query'       => $rowsQuery,
            'totalsQuery' => $totalsQuery,
            'params'      => ['label' => $periods[$gran]['label'], 'gran' => $gran],
        ];
    }

    /**
     * Build WHERE clause from active filters (excluding time filters)
     *
     * @return string SQL WHERE clause conditions or empty string
     */
    private function buildFilterWhere(): string
    {
        if (!class_exists('\wp_slimstat_db')) {
            return '';
        }

        // Get active filters (excluding time filters)
        if (empty(\wp_slimstat_db::$filters_normalized['columns'])) {
            return '';
        }

        $whereClauses = [];

        foreach (\wp_slimstat_db::$filters_normalized['columns'] as $column => $filterData) {
            // Skip addon filters
            if (false !== strpos($column, 'addon_')) {
                continue;
            }

            $operator = $filterData[0] ?? 'equals';
            $value    = $filterData[1] ?? '';

            $clause = \wp_slimstat_db::get_single_where_clause($column, $operator, $value);

            if (!empty($clause)) {
                $whereClauses[] = $clause;
            }
        }

        if (empty($whereClauses)) {
            return '';
        }

        return implode(' AND ', $whereClauses);
    }

    /**
     * Validates SQL expressions to prevent SQL injection attacks.
     * Uses a predefined metrics system for maximum security.
     *
     * @param string $expression The SQL expression to validate
     * @return string The safe SQL expression
     * @throws \Exception If the expression is invalid or potentially malicious
     */
    private function validateSqlExpression(string $expression): string
    {
        // Remove extra whitespace and normalize
        $expression = preg_replace('/\s+/', ' ', trim($expression));

        // Empty expressions default to COUNT(*)
        if (empty($expression)) {
            return 'COUNT(*)';
        }

        // Define allowed columns from wp_slim_stats table
        $allowedColumns = [
            'id', 'ip', 'other_ip', 'username', 'email',
            'country', 'location', 'city',
            'referer', 'resource', 'searchterms', 'notes', 'visit_id',
            'server_latency', 'page_performance',
            'browser', 'browser_version', 'browser_type', 'platform',
            'language', 'fingerprint', 'user_agent',
            'resolution', 'screen_width', 'screen_height',
            'content_type', 'category', 'author', 'content_id',
            'outbound_resource',
            'tz_offset', 'dt_out', 'dt'
        ];

        // Define allowed aggregate functions
        $allowedFunctions = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];

        // Strict pattern matching with anchors to prevent bypass attempts
        // Pattern 1: COUNT(*) or SUM(*) etc (no spaces allowed in function name)
        if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN)\s*\(\s*\*\s*\)$/i', $expression, $matches)) {
            $function = strtoupper($matches[1]);
            return $function . '(*)';
        }

        // Pattern 2: COUNT(column) or COUNT( column )
        if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN)\s*\(\s*([a-z_][a-z0-9_]*)\s*\)$/i', $expression, $matches)) {
            $function = strtoupper($matches[1]);
            $column = strtolower($matches[2]);

            if (!in_array($function, $allowedFunctions, true)) {
                throw new \Exception(__('Invalid SQL function in chart data expression', 'wp-slimstat'));
            }

            if (!in_array($column, $allowedColumns, true)) {
                throw new \Exception(__('Invalid column name in chart data expression', 'wp-slimstat'));
            }

            // Use esc_sql as additional protection (though column is whitelisted)
            return $function . '( ' . esc_sql($column) . ' )';
        }

        // Pattern 3: COUNT(DISTINCT column) or COUNT( DISTINCT column )
        if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN)\s*\(\s*DISTINCT\s+([a-z_][a-z0-9_]*)\s*\)$/i', $expression, $matches)) {
            $function = strtoupper($matches[1]);
            $column = strtolower($matches[2]);

            if (!in_array($function, $allowedFunctions, true)) {
                throw new \Exception(__('Invalid SQL function in chart data expression', 'wp-slimstat'));
            }

            if (!in_array($column, $allowedColumns, true)) {
                throw new \Exception(__('Invalid column name in chart data expression', 'wp-slimstat'));
            }

            // Use esc_sql as additional protection (though column is whitelisted)
            return $function . '( DISTINCT ' . esc_sql($column) . ' )';
        }

        // If none of the patterns match, reject the expression
        throw new \Exception(__('Invalid SQL expression in chart data. Only whitelisted aggregate functions on valid columns are allowed.', 'wp-slimstat'));
    }

    private function processResults(array $rows, array $totals, array $params, int $start, int $end, int $prevStart, int $prevEnd): array
    {
        // Normalize totals to array of stdClass for backward compatibility
        $totalsObjects = array_map(function ($t) {
            if (is_object($t)) {
                return $t;
            }

            $o         = new \stdClass();
            $o->v1     = isset($t['v1']) ? (int) $t['v1'] : 0;
            $o->v2     = isset($t['v2']) ? (int) $t['v2'] : 0;
            $o->period = isset($t['period']) ? (string) $t['period'] : '';
            return $o;
        }, $totals);

        $buckets = new DataBuckets($params['label'], $params['gran'], $start, $end, $prevStart, $prevEnd, $totalsObjects);
        foreach ($rows as $row) {
            $dt     = (int) (is_object($row) ? $row->dt : ($row['dt'] ?? 0));
            $v1     = (int) (is_object($row) ? $row->v1 : ($row['v1'] ?? 0));
            $v2     = (int) (is_object($row) ? $row->v2 : ($row['v2'] ?? 0));
            $period = (string) (is_object($row) ? $row->period : ($row['period'] ?? ''));
            $buckets->addRow($dt, $v1, $v2, $period);
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
            true
        );
        wp_enqueue_script(
            'slimstat_chart',
            plugins_url('/admin/assets/js/slimstat-chart.js', SLIMSTAT_FILE),
            ['slimstat_chartjs'],
            '1.3',
            true
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

    /**
     * Get supported chart types
     *
     * @return array<string>
     */
    public static function get_supported_chart_types(): array
    {
        return self::CHART_TYPES;
    }
}
