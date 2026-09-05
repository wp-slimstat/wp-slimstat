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

    /**
     * How coarsely the end of a today-inclusive window is quantised, and how long the
     * resulting cache entry lives.
     *
     * Deliberately one number for both jobs: the bucket makes the key stable long
     * enough to be hit, the TTL bounds staleness at one bucket. Two constants could
     * drift into a cache that is written and never read.
     *
     * 60s matches the once-a-minute cadence `adminbar-realtime.js` refreshes at, so a
     * chart is never more out of date than the figures printed beside it.
     *
     * @see \wp_slimstat_db::CACHE_RANGE_BUCKET_SECONDS for the same technique applied
     *      to the goal and funnel transients (D33).
     */
    private const CACHE_LIVE_BUCKET_SECONDS = 60;

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
        // Quantise the live end BEFORE anything else reads $args, so the WHERE and the
        // current-vs-previous CASE describe the same window. The end is otherwise `now`
        // to the second and the cache key derives from the generated SQL, so the key
        // moved every render and the cache could never be hit — the same defect as the
        // goal transients (D33). Enabling caching without this achieves nothing. Cost:
        // the last <60s of today is ignored, well inside the hourly granularity.
        //
        // Not routed through Query::processDateRange(), which already splits a
        // today-inclusive range into a cached historical half and a live half. Four
        // reasons, recorded because "just use the builder" is the obvious cleanup and
        // it would silently break the coarse granularities:
        //   1. getSplitDateRanges() matches ONE contiguous `dt BETWEEN`; this predicate
        //      is current OR previous in a single clause.
        //   2. $end is also inlined into the SELECT list's CASE label, so splitting the
        //      WHERE alone still leaves a key that moves every second.
        //   3. mergeGroupResults() sums 'counthits', not v1/v2, and for WEEK/MONTH/YEAR
        //      the bucket containing today appears in BOTH halves — it would keep one
        //      and discard the other's counts.
        //   4. getAll() takes the split path whenever it matches, regardless of whether
        //      caching is on, so this would apply even with the cache disabled.
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $isLive     = ($args['end'] >= $todayStart);

        if ($isLive) {
            $args['end'] = (int) (floor($args['end'] / self::CACHE_LIVE_BUCKET_SECONDS) * self::CACHE_LIVE_BUCKET_SECONDS);
        }

        $prevArgs = $this->calculatePreviousArgs($args);
        $sqlInfo  = $this->buildSql($args, $prevArgs);

        // Historical windows are immutable, so they keep the long TTL. Live ones expire
        // with their bucket: a stale entry must never outlive the window it describes.
        $expiration = $isLive ? self::CACHE_LIVE_BUCKET_SECONDS : DAY_IN_SECONDS;

        $rowsQuery = $sqlInfo['query'];

        if ($rowsQuery instanceof Query) {
            $rowsQuery->allowCaching(true, $expiration);
        }

        $merged = $rowsQuery instanceof Query ? $rowsQuery->getAll() : [];

        // Split the ROLLUP result: bucket rows, per-period super-rows (the totals the
        // second query used to fetch), and the (NULL, NULL) grand total nothing renders.
        // A bucket expression over a valid dt is never NULL, so dt IS NULL identifies a
        // super-row on every supported MySQL (GROUPING() would need 8.0).
        $results = [];
        $totals  = [];
        foreach ($merged as $row) {
            $dt     = self::rowField($row, 'dt');
            $period = self::rowField($row, 'period');
            if (null === $dt) {
                if (null !== $period) {
                    $totals[] = $row;
                }
                continue;
            }
            $results[] = $row;
        }

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

        // Both ends shift by the range and NEITHER is snapped. The start used to take a
        // ->setTime(0, 0, 0), which made the previous window longer than the current one by the
        // current start's time-of-day — 9h17m on a live 60-day chart, measured.
        //
        // That surplus is what produced a total no bar accounted for. The totals are a SQL
        // aggregate over the window, so they counted it; DataBuckets::addRow() drops anything
        // outside `[0, points)`, so no bar showed it; and nothing reconciles the two, because the
        // totals are passed INTO DataBuckets and returned verbatim. On R20260824-2c8d1a that was
        // 2,475 hits — chart_weekly reported a previous total of 47,552 over bars summing to
        // 45,077, and the headline "up 14.2%" should have read up 20.5%.
        //
        // It also put the previous LABELS on a different week grid from the previous VALUES:
        // mapPrevLabels() steps `+N WEEK` from previous_start while the buckets are aligned by
        // getWeekStartTimestamp(), so a midnight-snapped start drifted the two apart — Sundays
        // against Mondays in that run.
        //
        // Two windows of equal length that abut is what "the previous period" means, and the
        // bucket alignment and the reconciliation both follow from it rather than being patched
        // separately.
        $dtStart->modify(sprintf('-%s seconds', $rangeSeconds));
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

        // Add chart-specific WHERE clause if provided.
        // SECURITY: $args['chart_data']['where'] arrives via $_POST in the AJAX
        // path (ajaxFetchChartData) and is later inlined into raw SQL through
        // Query::whereRaw() with no parameter binding. To prevent SQL injection
        // (Patchstack disclosure, CVSS 8.5), require the supplied clause to
        // match — after whitespace normalization — one of the WHERE strings
        // declared by a report registered in wp_slimstat_reports::$reports.
        if (!empty($args['chart_data']['where'])) {
            // Reject non-string input before normalization. Chart.php does not
            // declare(strict_types=1), so casting an array (E_WARNING) or an
            // object without __toString (fatal Error → 500) would otherwise
            // produce noisy logs or crash the AJAX handler instead of the
            // generic security rejection below.
            if (!is_string($args['chart_data']['where'])) {
                throw new \Exception(__('Invalid chart filter expression.', 'wp-slimstat'));
            }
            $normalized = self::normalizeSqlWhitespace($args['chart_data']['where']);
            $allowed    = self::getAllowedWhereClauses();
            if (!isset($allowed[$normalized])) {
                throw new \Exception(__('Invalid chart filter expression.', 'wp-slimstat'));
            }
            $canonical   = $allowed[$normalized]; // splice trusted text, never the user-derived $normalized
            // Wrap: allowlisted clauses may contain a top-level OR that would
            // otherwise rebind and drop the preceding AND filters.
            $wrapped     = '(' . $canonical . ')';
            $filterWhere = !empty($filterWhere) ? $filterWhere . ' AND ' . $wrapped : $wrapped;
        }

        // One probe per request, shared with DataBuckets, which asks the same question
        // of the same connection. The sign below is INVERTED relative to it, and that
        // is INTENTIONAL: FROM_UNIXTIME(dt) returns server-local time while CONVERT_TZ
        // declares its source as UTC, so the inversion cancels the implicit shift and
        // produces actual UTC. DataBuckets then applies the offset for display.
        $totalOffsetSeconds = DataBuckets::serverTimezoneOffset();
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
            ->from($GLOBALS['wpdb']->prefix . 'slim_stats')
            ->whereRaw('((dt BETWEEN %d AND %d) OR (dt BETWEEN %d AND %d))', [$prevStart, $prevEnd, $start, $end]);

        // Apply additional filters if any
        if (!empty($filterWhere)) {
            $rowsQuery->whereRaw($filterWhere);
        }

        // ONE pass, not two (Run 25's licence): the per-period totals ride the SAME query
        // as the buckets. GROUP BY period FIRST, then the bucket, WITH ROLLUP — each
        // (period, NULL) super-row is that period's total computed over the underlying
        // rows, which is correct even for COUNT(DISTINCT ...); summing the buckets is not
        // (Run 8: 254 vs 21,410). Measured on the I8 corpus: Handler_read_rnd_next
        // exactly halves, byte-stable A-B-B-A.
        //
        // No ORDER BY: MySQL below 8.0.12 rejects ORDER BY with ROLLUP outright, the
        // supported floor is 5.6, and processResults() keys every row into DataBuckets
        // by (dt, period) — the old ORDER BY was decoration. fetchChartData() splits the
        // super-rows from the bucket rows and discards the (NULL, NULL) grand total.
        $rowsQuery->groupBy('period, ' . $dtExpr . ' WITH ROLLUP');

        return [
            'query'  => $rowsQuery,
            'params' => ['label' => $periods[$gran]['label'], 'gran' => $gran],
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

    /**
     * Allowlist of legitimate chart `where` clauses harvested from every report
     * registered in wp_slimstat_reports::$reports (including those added by
     * third-party Pro addons via the `slimstat_reports_info` filter).
     *
     * Rebuilt per request because dynamic clauses (home_url(), date_i18n(...))
     * are evaluated at init() time.
     *
     * @return array<string,string> normalized-clause => canonical clause text
     */
    private static function getAllowedWhereClauses(): array
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }

        if (!class_exists('\wp_slimstat_reports')) {
            $reportsFile = SLIMSTAT_DIR . '/admin/view/wp-slimstat-reports.php';
            if (file_exists($reportsFile)) {
                include_once $reportsFile;
            }
        }
        if (!class_exists('\wp_slimstat_reports')) {
            // Don't cache the failure — let a later call retry once the file
            // has had a chance to load (e.g. via a downstream filter).
            return [];
        }

        \wp_slimstat_reports::init();

        $cache = [];
        foreach ((array) \wp_slimstat_reports::$reports as $report) {
            $where = $report['callback_args']['chart_data']['where'] ?? null;
            // Skip non-string values defensively — a third-party report could
            // register an array/object/null; normalizeSqlWhitespace is typed
            // for string and Chart.php does not declare(strict_types=1).
            if (!is_string($where) || '' === $where) {
                continue;
            }
            $normalized = self::normalizeSqlWhitespace($where);
            if ('' !== $normalized) {
                $cache[$normalized] = $where;
            }
        }

        return $cache;
    }

    /**
     * Both sides of the `where` allowlist comparison must run through the
     * same whitespace normalization for the equality check to be sound.
     */
    private static function normalizeSqlWhitespace(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * One field from a result row that may be a stdClass or ARRAY_A. Six call sites had
     * grown six inline copies of this duality, and the object branches lacked the
     * missing-key guard their array twins had. Null when absent — load-bearing for the
     * ROLLUP split, where `dt IS NULL` identifies a super-row.
     */
    private static function rowField($row, string $key)
    {
        return is_object($row) ? ($row->{$key} ?? null) : ($row[$key] ?? null);
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
            $dt     = (int) self::rowField($row, 'dt');
            $v1     = (int) self::rowField($row, 'v1');
            $v2     = (int) self::rowField($row, 'v2');
            $period = (string) self::rowField($row, 'period');
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
            SLIMSTAT_ANALYTICS_VERSION,
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
