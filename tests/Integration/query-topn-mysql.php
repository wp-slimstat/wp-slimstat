<?php
/** Compare split reports with native SQL on a disposable database, never the site's tables. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('A disposable WordPress test database is required.');
}
$originalPrefix = $wpdb->prefix;
$prefix = 'slimstat_topn_' . bin2hex(random_bytes(6)) . '_';
$table = $prefix . 'slim_stats';
$today = (int) floor(wp_slimstat::now() / 86400) * 86400;
$run = static function ($sql) use ($wpdb) {
    if (false === $wpdb->query($sql)) { throw new RuntimeException($wpdb->last_error); }
};
$checks = 0;
try {
    $run("CREATE TABLE {$table} (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, resource varchar(100) NULL, region varchar(20) NULL, n bigint, amount decimal(20,4), dt bigint) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Values differ across midnight, including keys equal under the host's collation.
    foreach ([['X', 10, 0], ['Y', 9, 0], ['Z', 8, 0], ['Z', 5, 1], ['W', 4, 1],
        ['Alpha', 11, 0], ['ALPHA', 9, 1], ['é', 8, 0], ['e', 7, 1],
        [null, 12, 0], [null, 8, 1], ["q'_%", 3, 0], ["q'_%", 2, 1]] as [$key, $n, $live]) {
        for ($i = 0; $i < $n; ++$i) {
            if (false === $wpdb->insert($table, ['resource' => $key, 'region' => $i % 2 ? null : 'one',
                'n' => $i % 3 ? $i : -$i, 'amount' => ($i - 5) . '.1250', 'dt' => $today + ($live ? 1 : -1)])) {
                throw new RuntimeException($wpdb->last_error);
            }
        }
    }
    foreach ([['resource', 'COUNT(*)'], ['resource, region', 'COUNT(*)'], ['resource', 'COUNT(resource)'],
        ['resource', 'SUM(n)'], ['resource', 'SUM(amount)'], ['resource', 'MAX(n)'],
        ['resource', 'MIN(n)'], ['resource', 'AVG(n)'], ['resource', 'COUNT(DISTINCT region)']] as [$groups, $aggregate]) {
        foreach ([1, 2, 5, 20] as $limit) {
            foreach ([0, 1, 3, 20] as $offset) {
                foreach (['DESC', 'ASC'] as $direction) {
                    $query = \SlimStat\Utils\Query::select("{$groups}, {$aggregate} AS counthits")->from($table)
                        ->groupBy($groups)->orderBy("counthits {$direction}, {$groups}")->limit($limit, $offset);
                    $expected = (clone $query)->whereDate('dt', ['from' => $today - 86400, 'to' => $today + 3600], true)->getAll();
                    $actual = (clone $query)->whereDate('dt', ['from' => $today - 86400, 'to' => $today + 3600])->getAll();
                    // SQL may choose either spelling of a collated key; compare its identity and exact value.
                    $normalize = static function ($rows) {
                        return array_map(static function ($row) {
                            if (isset($row['resource'])) { $row['resource'] = strtr(strtolower($row['resource']), ['é' => 'e']); }
                            return $row;
                        }, $rows);
                    };
                    if ($normalize($actual) !== $normalize($expected) || $wpdb->last_error) {
                        throw new RuntimeException("{$aggregate} / {$groups} / {$direction} / {$limit},{$offset}: " . wp_json_encode([$actual, $expected, $wpdb->last_error]));
                    }
                    ++$checks;
                }
            }
        }
    }
    // Repeated date filters retain SQL AND/intersection semantics, including entirely
    // historical ranges which previously recursively reprocessed each other.
    foreach ([[$today - 86400, $today + 3600], [$today - 86400, $today - 1], [$today, $today + 3600]] as $outer) {
        foreach ([[$today - 100, $today + 100], [$today + 2, $today + 3]] as $inner) {
            $q = \SlimStat\Utils\Query::select('resource, COUNT(*) AS n')->from($table)
                ->where('dt', 'BETWEEN', $outer)->groupBy('resource')->orderBy('resource');
            $expected = (clone $q)->whereDate('dt', ['from' => $inner[0], 'to' => $inner[1]], true)->getAll();
            $before = $wpdb->num_queries;
            $actual = (clone $q)->where('dt', 'BETWEEN', $inner)->getAll();
            if ($actual !== $expected || $wpdb->num_queries !== $before + 1) {
                throw new RuntimeException('Repeated date filters must execute one exact SQL intersection');
            }
        }
    }
    echo "PASS: six repeated-date-filter intersections, including historical and empty results\n";
    $wpdb->prefix = $prefix;
    \SlimStat\Utils\Query::setProcessingTimestamp($today + 3600);
    $count = static function () use ($table, $today) {
        return \SlimStat\Utils\Query::select('COUNT(id)')->from($table)
            ->whereDate('dt', ['from' => $today - 86400, 'to' => $today + 3600])->getVar();
    };
    $selects = 0;
    $ledger = static function ($sql) use ($table, &$selects) {
        if (strpos($sql, "FROM {$table}") !== false && strpos($sql, 'SELECT COUNT(id)') === 0) { ++$selects; }
        return $sql;
    };
    add_filter('query', $ledger);
    $expected = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    if ($count() !== $expected || 2 !== $selects) { throw new RuntimeException('Cold scalar must combine two exact halves'); }
    $run("INSERT INTO {$table} (dt) VALUES (" . ($today + 2) . ')');
    $selects = 0;
    if ((int) $count() !== (int) $expected + 1 || 1 !== $selects) { throw new RuntimeException('Warm scalar must reuse history and count the new live pageview'); }
    $run("DELETE FROM {$table} WHERE dt < {$today} ORDER BY id LIMIT 2");
    wp_slimstat::bump_data_version();
    if ((int) $count() !== (int) $expected - 1) { throw new RuntimeException('Deletion must invalidate cached history'); }
    remove_filter('query', $ledger);
    echo "PASS: native scalar counts reuse history, include new tracking and refresh after deletion\n";
} finally {
    \SlimStat\Utils\Query::setProcessingTimestamp(null);
    $wpdb->prefix = $originalPrefix;
    $run("DROP TABLE IF EXISTS {$table}");
}
echo "PASS: {$checks} native SQL top-N comparisons: collations, NULL/quoted keys, tuples, decimal/negative sums, ties, offsets and aggregate fallbacks\n";
