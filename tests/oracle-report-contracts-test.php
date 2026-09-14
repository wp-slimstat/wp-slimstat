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
        ? ['type', 'top', 'columns', 'raw', 'wp_slimstat_db', 'get_top']
        : ('recent' === $family
            ? ['show_access_log', 'type', 'recent', 'columns', '*', 'raw', 'wp_slimstat_db', 'get_recent']
            : ('chart' === $family
                ? ['show_chart', 'chart_data', 'data1', 'COUNT( ip )', 'data2', 'COUNT( DISTINCT ip )']
                : ('count' === $family
                    ? ('slim_p2_01' === $id
                        ? ['show_chart', 'chart_data', 'COUNT( DISTINCT visit_id )', '(visit_id > 0 AND browser_type <> 1)']
                        : ['raw_results_to_html', 'raw', 'wp_slimstat_db', 'get_overview_summary'])
                    : [])));
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
    if ('recent' === $family) {
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
    if ('chart' === $family) {
        $chartDurations = ['DAY' => 5, 'WEEK' => 60];
        $granularity = $contract['granularity'] ?? null;
        if ('ip' !== ($contract['metric_column'] ?? null)
            || !isset($chartDurations[$granularity])
            || $chartDurations[$granularity] !== ($contract['duration_days'] ?? null)
            || 1 !== ($contract['start_of_week'] ?? null)
            || 'UTC' !== ($contract['timezone'] ?? null)
        ) {
            $failures[] = "{$key}: chart contract must pin the captured IP/calendar semantics";
        }
    }
    if ('count' === $family) {
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
    // These literals describe the current runtime contract but do not prove how get_top reads it;
    // the live report/capture gate owns that behavior in S7 and Phase 2.
    if (in_array($family, ['top', 'recent'], true)
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
