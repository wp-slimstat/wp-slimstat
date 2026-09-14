<?php
/** S5 gate: machine-readable oracle contracts point at the real report registry. */

declare(strict_types=1);

$root = dirname(__DIR__);
$json = json_decode((string) file_get_contents(__DIR__ . '/oracle/report-contracts.json'), true);
$src  = (string) file_get_contents($root . '/admin/view/wp-slimstat-reports.php');
$dbSrc = (string) file_get_contents($root . '/admin/view/wp-slimstat-db.php');
$failures = [];
$recentColumns = [
    'id', 'ip', 'dt', 'username', 'referer', 'resource', 'browser', 'platform', 'country', 'city',
    'content_type', 'notes', 'visit_id', 'server_latency', 'page_performance', 'browser_version',
    'browser_type', 'language', 'fingerprint', 'user_agent', 'resolution', 'screen_width',
    'screen_height', 'category', 'author', 'content_id', 'outbound_resource', 'tz_offset', 'dt_out',
];
$countContracts = [
    'count_records_id'       => ['id', false, 'binary', false],
    'count_records_ip'       => ['ip', true, 'ascii_ci', false],
    'count_records_resource' => ['resource', true, 'ascii_ci', false],
    'rows_in_window'         => ['id', false, 'binary', true],
    'count_human_hits'       => ['id', false, 'binary', false],
    'count_records_visit_id' => ['visit_id', true, 'binary', false],
];
$summaryContracts = [
    'bouncing_visits_pinned'                => ['bouncing_visits', 'count_records_having'],
    'get_max_and_average_pages_per_visit'   => ['pages_per_visit', 'get_max_and_average_pages_per_visit'],
    'get_visitors_summary'                  => ['visitors_summary', 'get_visitors_summary'],
    'get_visits_duration'                   => ['visit_duration', 'get_visits_duration'],
];
$chartContracts = [
    'chart_daily'                    => ['ip', 5, 'DAY'],
    'chart_weekly'                   => ['ip', 60, 'WEEK'],
    'chart_searchterms_pinned'       => ['searchterms', 30, 'WEEK'],
    'chart_users_pinned'             => ['username', 30, 'WEEK'],
    'slim_p4_26_01_chart_daily'      => ['outbound_resource', 5, 'DAY'],
    'slim_p4_26_01_chart_weekly'     => ['outbound_resource', 60, 'WEEK'],
];
$pageContracts = [
    'slim_p2_24_top_bots'               => ['top_dimensions', ['get_top', 'browser, browser_version', 'browser_type = 1']],
    'slim_p2_25_top_human_browsers'     => ['top_dimensions', ['get_top', 'browser, browser_version', 'browser_type != 1']],
    'slim_p4_01_recent_outbound'        => ['recent_outbound', ['get_top_outbound', 'outbound_resource', 'sort_outbound', 'dt']],
    'slim_p4_02_recent_posts'           => ['recent_dimension', ['get_top', 'TRIM( TRAILING "/" FROM resource )', 'resource', 'content_type = "post"', 'MAX(dt) DESC', 'MAX(dt) AS dt']],
    'slim_p4_04_recent_feeds'           => ['recent_rows', ['get_recent', 'resource', '(resource LIKE %s OR resource LIKE %s OR resource LIKE %s OR content_type LIKE %s)', '%/feed%', '%?feed=>%', '%&feed=>%', '%feed%']],
    'slim_p4_05_recent_not_found'       => ['recent_dimension', ['get_top', 'resource', '(resource LIKE "[404]%" OR content_type LIKE "%404%")', 'MAX(dt) DESC', 'MAX(dt) AS dt']],
    'slim_p4_06_recent_internal_searches' => ['recent_rows', ['get_recent', 'searchterms', 'content_type LIKE %s AND searchterms <> "" AND searchterms IS NOT NULL', '%search%']],
    'slim_p4_07_top_categories'         => ['top_dimension', ['get_top', 'category', 'content_type LIKE "%category%"']],
    'slim_p4_09_top_downloads'          => ['top_dimension', ['get_top', 'resource', 'content_type = "download"']],
    'slim_p4_13_top_internal_searches'  => ['top_dimension', ['get_top', 'searchterms', 'content_type LIKE %s AND searchterms <> "" AND searchterms IS NOT NULL', '%search%']],
    'slim_p4_15_recent_categories'      => ['recent_dimension', ['get_top', 'TRIM( TRAILING "/" FROM resource )', 'resource', '(content_type = "category")', 'MAX(dt) DESC', 'MAX(dt) AS dt']],
    'slim_p4_152_recent_tags'            => ['recent_dimension', ['get_top', 'TRIM( TRAILING "/" FROM resource )', 'resource', '(content_type = "tag")', 'MAX(dt) DESC', 'MAX(dt) AS dt']],
    'slim_p4_16_top_not_found'           => ['top_dimension', ['get_top', 'resource', 'content_type LIKE "%404%"']],
    'slim_p4_18_top_authors'             => ['top_dimension', ['get_top', 'author']],
    'slim_p4_19_top_tags'                => ['top_dimension', ['get_top', 'category', '(content_type LIKE "%tag%")']],
    'slim_p4_20_recent_downloads'        => ['recent_downloads', ['get_top', 'resource', 'content_type = "download"', 'MAX(dt) DESC', 'MAX(dt) AS dt']],
    'slim_p4_24_exit_pages'              => ['exit_pages', ['get_top_aggr', 'visit_id', 'resource', 'MAX']],
    'slim_p4_25_entry_pages'             => ['entry_pages', ['get_top_aggr', 'visit_id', 'resource', 'MIN']],
];
$goalContracts = [
    'get_goal_results'   => 'goal_result',
    'get_goals_raw'      => 'goals_raw',
    'get_funnel_results' => 'funnel_result',
    'get_funnels_raw'    => 'funnels_raw',
];

if (!is_array($json) || 'SLIMSTAT-ORACLE-CONTRACTS-V1' !== ($json['schema'] ?? null)) {
    $failures[] = 'report-contracts.json is missing schema SLIMSTAT-ORACLE-CONTRACTS-V1';
}
$reports = is_array($json['reports'] ?? null) ? $json['reports'] : [];
if (!$reports) {
    $failures[] = 'report-contracts.json has no reports';
}

/** Decode the simple single-quoted literals used by the report registry. */
$literal = static function (string $token): string {
    $quote = $token[0];
    $body = substr($token, 1, -1);
    return '"' === $quote ? stripcslashes($body) : str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
};

$tokens = token_get_all($src);
$extract = static function (string $reportId) use ($tokens, $literal): array {
    $start = null;
    foreach ($tokens as $i => $token) {
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && $literal($token[1]) === $reportId) {
            $start = $i;
            break;
        }
    }
    if (null === $start) {
        return [];
    }
    $depth = 0;
    $opened = false;
    $strings = [];
    for ($i = $start + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if ('[' === $token) {
            $opened = true;
            $depth++;
            continue;
        }
        if (']' === $token && $opened) {
            $depth--;
            if (0 === $depth) {
                break;
            }
            continue;
        }
        if ($opened && is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $strings[] = $literal($token[1]);
        }
    }
    return $strings;
};

$seenIds = [];
foreach ($reports as $key => $contract) {
    $id = $contract['report_id'] ?? '';
    $family = $contract['family'] ?? null;
    $kind = $contract['kind'] ?? null;
    if (!is_string($id) || '' === $id) {
        $failures[] = "{$key}: report_id is absent";
        continue;
    }
    if (isset($seenIds[$id]) && $family !== ($reports[$seenIds[$id]]['family'] ?? null)) {
        $failures[] = "{$key}: report_id {$id} is also used by {$seenIds[$id]}";
    }
    $seenIds[$id] = $key;
    $strings = $extract($id);
    if (!$strings) {
        $failures[] = "{$key}: real report id {$id} does not exist in wp-slimstat-reports.php";
        continue;
    }
    $requiredStrings = 'top' === $family
        ? ('current' === $kind
            ? ('slim_p1_04' === $id
                ? ['type', 'top', 'columns', 'ip', '(dt_out > ', ') OR (dt > ', 'MAX(dt) DESC', 'MAX(dt) AS dt', 'raw', 'wp_slimstat_db', 'get_top']
                : ['type', 'top', 'columns', 'username', '((dt_out > ', ')) AND username <> "" AND username IS NOT NULL', 'raw', 'wp_slimstat_db', 'get_top'])
            : ('recent_top' === $kind
            ? ['type', 'top', 'columns', 'MAX(dt) DESC', 'MAX(dt) AS dt', 'raw', 'wp_slimstat_db', 'get_top']
            : ('top_events' === $kind
            ? ['type', 'top', 'columns', 'notes', 'raw', 'wp_slimstat_db', 'get_top_events']
            : ('top_outbound' === $kind
                ? ['type', 'top', 'columns', 'outbound_resource', 'raw', 'wp_slimstat_db', 'get_top_outbound']
                : ['type', 'top', 'columns', 'raw', 'wp_slimstat_db', 'get_top']))))
        : ('recent' === $family
            ? ('recent_events' === $kind
                ? ['show_events', 'type', 'recent', 'columns', 'notes', 'raw', 'wp_slimstat_db', 'get_recent_events']
                : ('filtered_recent' === $kind
                    ? ['type', 'recent', 'columns', 'searchterms', 'raw', 'wp_slimstat_db', 'get_recent']
                    : ['show_access_log', 'type', 'recent', 'columns', '*', 'raw', 'wp_slimstat_db', 'get_recent']))
            : ('chart' === $family
                ? ['show_chart', 'chart_data', 'data1', 'COUNT( ' . $contract['metric_column'] . ' )',
                    'data2', 'COUNT( DISTINCT ' . $contract['metric_column'] . ' )']
                : ('count' === $family
                    ? ('slim_p2_01' === $id
                        ? ['show_chart', 'chart_data', 'COUNT( DISTINCT visit_id )', '(visit_id > 0 AND browser_type <> 1)']
                        : ('slim_p4_23' === $id
                            ? ['raw_results_to_html', 'raw', 'wp_slimstat_db', 'get_top']
                            : ['raw_results_to_html', 'raw', 'wp_slimstat_db', 'get_overview_summary']))
                    : ('summary' === $family
                        ? ['raw_results_to_html', 'raw', 'wp_slimstat_db',
                            'slim_p2_02' === $id ? 'get_visitors_summary'
                                : ('slim_p2_12' === $id ? 'get_visits_duration' : 'get_top')]
                        : ('pages' === $family
                            ? array_merge(['raw_results_to_html', 'raw', 'wp_slimstat_db'],
                                $pageContracts[$key][1] ?? [])
                            : ('goals' === $family
                                ? ['raw', 'wp_slimstat_db', 'slim_p9_01' === $id ? 'get_goals_raw' : 'get_funnels_raw']
                                : []))))));
    if (!$requiredStrings) {
        $failures[] = "{$key}: unknown oracle family " . var_export($family, true);
    }
    foreach ($requiredStrings as $required) {
        if (!in_array($required, $strings, true)) {
            $failures[] = "{$key}: report {$id} does not carry literal " . var_export($required, true);
        }
    }
    foreach ('top' === $family ? ['dimension', 'title'] : ['title'] as $field) {
        $value = $contract[$field] ?? '';
        if (!is_string($value) || '' === $value) {
            $failures[] = "{$key}: contract is missing {$field}";
        } elseif (!in_array($value, $strings, true)) {
            $failures[] = "{$key}: report {$id} does not carry literal " . var_export($value, true);
        }
    }
    if ('top' === $family && 'counthits' !== ($contract['count_field'] ?? null)) {
        $failures[] = "{$key}: family/count_field must be top/counthits";
    }
    if ('recent' === $family && !in_array($kind, ['recent_events', 'filtered_recent'], true)) {
        if ($recentColumns !== ($contract['columns'] ?? null)) {
            $failures[] = "{$key}: recent contract must contain the exact 29 Access Log columns";
        }
        $manifest = '$manifest = [' . implode(', ', array_map(static function (string $column): string {
            return "'{$column}'";
        }, $recentColumns)) . '];';
        if (false === strpos($dbSrc, $manifest)) {
            $failures[] = "{$key}: wp_slimstat_db::recent_columns() does not match the 29-column contract";
        }
    }
    if ('filtered_recent' === $kind
        && (['searchterms', 'referer', 'resource', 'dt', 'ip'] !== ($contract['columns'] ?? null)
            || [['searchterms', 'not_in', [null, '', '_']]] !== ($contract['where'] ?? null))
    ) {
        $failures[] = "{$key}: filtered recent contract does not match the captured search-term shape";
    }
    if ('top_language_family_pinned' === $key && 'language_prefix' !== ($contract['transform'] ?? null)) {
        $failures[] = "{$key}: language-family transform is not pinned";
    }
    $p2Composite = [
        'top_user_agent_pinned' => ['browser', 'browser_version'],
        'top_screen_resolution_pinned' => ['screen_width', 'screen_height'],
        'recent_user_agent_pinned' => ['browser', 'browser_version'],
    ];
    if (isset($p2Composite[$key]) && $p2Composite[$key] !== ($contract['dimensions'] ?? null)) {
        $failures[] = "{$key}: composite top dimensions are not pinned";
    }
    if ('top_screen_resolution_pinned' === $key
        && [['screen_width', 'ne', 0], ['screen_height', 'ne', 0]] !== ($contract['where'] ?? null)
    ) {
        $failures[] = "{$key}: nonzero screen-size predicates are not pinned";
    }
    if (in_array($key, ['recent_user_pinned', 'top_user_pinned'], true)
        && [['notes', 'contains_ascii_ci', 'user:']] !== ($contract['where'] ?? null)
    ) {
        $failures[] = "{$key}: user-note predicate is not pinned";
    }
    if ('chart' === $family) {
        $actual = [$contract['metric_column'] ?? null, $contract['duration_days'] ?? null,
            $contract['granularity'] ?? null];
        if (!isset($chartContracts[$key]) || $chartContracts[$key] !== $actual
            || 1 !== ($contract['start_of_week'] ?? null)
            || 'UTC' !== ($contract['timezone'] ?? null)
        ) {
            $failures[] = "{$key}: chart contract must pin the captured metric/calendar semantics";
        }
        if ('chart_searchterms_pinned' === $key
            && (['', '_'] !== ($contract['excluded'] ?? null)
                || 'ascii_ci' !== ($contract['equality'] ?? null))
        ) {
            $failures[] = "{$key}: search-term chart contract must pin exclusions and collation";
        }
        if ('chart_users_pinned' === $key && 'ascii_ci' !== ($contract['equality'] ?? null)) {
            $failures[] = "{$key}: users chart contract must pin collation";
        }
    }
    if ('count' === $family && 'singletons' !== $kind) {
        $actual = [$contract['column'] ?? null, $contract['distinct'] ?? null,
            $contract['equality'] ?? null, $contract['windowed'] ?? null];
        if (!isset($countContracts[$key]) || $countContracts[$key] !== $actual) {
            $failures[] = "{$key}: count contract does not match its captured scalar semantics";
        }
        if ('count_human_hits' === $key
            && ['column' => 'browser_type', 'value' => 1] !== ($contract['where_not_equal'] ?? null)
        ) {
            $failures[] = "{$key}: human-hit contract must exclude bots and SQL NULLs";
        }
    }
    if ('singletons' === $kind
        && (!in_array($contract['equality'] ?? null, ['binary', 'ascii_ci'], true)
            || true !== ($contract['windowed'] ?? null)
            || !is_array($contract['where'] ?? null)
            || !is_string($contract['group_column'] ?? null)
            || !is_string($contract['counted_column'] ?? null))
    ) {
        $failures[] = "{$key}: singleton contract is incomplete";
    }
    if ('summary' === $family) {
        $actual = [$contract['kind'] ?? null, $contract['method'] ?? null];
        if (!isset($summaryContracts[$key]) || $summaryContracts[$key] !== $actual) {
            $failures[] = "{$key}: summary contract does not match its captured semantics";
        } elseif (false === strpos($dbSrc, 'function ' . $actual[1] . '(')) {
            $failures[] = "{$key}: summary method {$actual[1]} does not exist";
        }
    }
    if ('pages' === $family) {
        if (!isset($pageContracts[$key]) || $pageContracts[$key][0] !== ($contract['kind'] ?? null)
            || 'ascii_ci' !== ($contract['equality'] ?? null)
        ) {
            $failures[] = "{$key}: page contract does not match its report query semantics";
        }
    }
    if ('goals' === $family
        && (!isset($goalContracts[$key]) || $goalContracts[$key] !== ($contract['kind'] ?? null))) {
        $failures[] = "{$key}: goal contract does not match its pinned capture fixture";
    }
    // These literals describe the current runtime contract but do not prove how get_top reads it;
    // the live report/capture gate owns that behavior in S7 and Phase 2.
    if (('top' === $family || ('recent' === $family && 'recent_events' !== $kind) || 'pages' === $family)
        && ('limit_results' !== ($contract['limit_setting'] ?? null) || 200 !== ($contract['default_limit'] ?? null))
    ) {
        $failures[] = "{$key}: limit must come from limit_results with default 200, not a fixture constant";
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: oracle report contracts (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

printf("PASS: oracle report contracts — %d contract(s) resolve to real family reports\n", count($reports));
