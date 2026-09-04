<?php
/** S5 gate: machine-readable oracle contracts point at the real report registry. */

declare(strict_types=1);

$root = dirname(__DIR__);
$json = json_decode((string) file_get_contents(__DIR__ . '/oracle/report-contracts.json'), true);
$src  = (string) file_get_contents($root . '/admin/view/wp-slimstat-reports.php');
$failures = [];

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
    if (!is_string($id) || '' === $id) {
        $failures[] = "{$key}: report_id is absent";
        continue;
    }
    if (isset($seenIds[$id])) {
        $failures[] = "{$key}: report_id {$id} is also used by {$seenIds[$id]}";
    }
    $seenIds[$id] = $key;
    $strings = $extract($id);
    if (!$strings) {
        $failures[] = "{$key}: real report id {$id} does not exist in wp-slimstat-reports.php";
        continue;
    }
    // This literal footprint is specific to the top family. Add family dispatch when the second
    // oracle family lands; a generic abstraction with one case would hide rather than remove work.
    foreach (['type', 'top', 'columns', 'raw', 'wp_slimstat_db', 'get_top'] as $required) {
        if (!in_array($required, $strings, true)) {
            $failures[] = "{$key}: report {$id} does not carry literal " . var_export($required, true);
        }
    }
    foreach (['dimension', 'title'] as $field) {
        $value = $contract[$field] ?? '';
        if (!is_string($value) || '' === $value) {
            $failures[] = "{$key}: contract is missing {$field}";
        } elseif (!in_array($value, $strings, true)) {
            $failures[] = "{$key}: report {$id} does not carry literal " . var_export($value, true);
        }
    }
    if ('top' !== ($contract['family'] ?? null) || 'counthits' !== ($contract['count_field'] ?? null)) {
        $failures[] = "{$key}: family/count_field must be top/counthits";
    }
    // These literals describe the current runtime contract but do not prove how get_top reads it;
    // the live report/capture gate owns that behavior in S7 and Phase 2.
    if ('limit_results' !== ($contract['limit_setting'] ?? null) || 200 !== ($contract['default_limit'] ?? null)) {
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

printf("PASS: oracle report contracts — %d contract(s) resolve to real top reports\n", count($reports));
