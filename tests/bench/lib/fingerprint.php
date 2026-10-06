<?php
// Environment fingerprint for benchmark results.
//
//   wp eval-file tests/bench/lib/fingerprint.php
//
// A latency number means nothing without the environment that produced it. Two
// results may only be compared when their `server` fingerprints match — that is
// the rule that stops "my laptop is busy today" being reported as a code
// regression.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file,
// where a declare() is not the first statement of the script and fatals.

if (!function_exists('slimstat_bench_fingerprint')) {
    /**
     * @return array{server: array<string,mixed>, build: array<string,mixed>, data: array<string,mixed>}
     */
    function slimstat_bench_fingerprint(wpdb $db): array
    {
        $vars = [];
        $rows = $db->get_results(
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN (
                'version','version_comment','innodb_buffer_pool_size','innodb_buffer_pool_instances',
                'sql_mode','innodb_flush_log_at_trx_commit','sync_binlog','log_bin',
                'tmp_table_size','max_heap_table_size','join_buffer_size','sort_buffer_size',
                'innodb_io_capacity','innodb_stats_persistent','lock_wait_timeout',
                'performance_schema','character_set_database'
            )",
            ARRAY_A
        ) ?: [];
        foreach ($rows as $row) {
            $vars[$row['Variable_name']] = $row['Value'];
        }

        $stats_table = $db->prefix . 'slim_stats';
        $sizes = $db->get_row(
            $db->prepare(
                "SELECT SUM(data_length) d, SUM(index_length) i, SUM(table_rows) r
                 FROM information_schema.TABLES
                 WHERE table_schema = DATABASE() AND table_name LIKE %s",
                $db->esc_like($db->prefix . 'slim_') . '%'
            ),
            ARRAY_A
        ) ?: [];

        return [
            // Everything a result must match on to be comparable.
            'server' => [
                'mysql_version'          => $vars['version'] ?? null,
                'mysql_flavour'          => $vars['version_comment'] ?? null,
                'buffer_pool_bytes'      => (int) ($vars['innodb_buffer_pool_size'] ?? 0),
                'buffer_pool_instances'  => (int) ($vars['innodb_buffer_pool_instances'] ?? 0),
                'tmp_table_size'         => (int) ($vars['tmp_table_size'] ?? 0),
                'max_heap_table_size'    => (int) ($vars['max_heap_table_size'] ?? 0),
                'join_buffer_size'       => (int) ($vars['join_buffer_size'] ?? 0),
                'sort_buffer_size'       => (int) ($vars['sort_buffer_size'] ?? 0),
                'flush_log_at_trx_commit' => $vars['innodb_flush_log_at_trx_commit'] ?? null,
                'sync_binlog'            => $vars['sync_binlog'] ?? null,
                'log_bin'                => $vars['log_bin'] ?? null,
                'sql_mode'               => $vars['sql_mode'] ?? null,
                'charset_database'       => $vars['character_set_database'] ?? null,
                'php_version'            => PHP_VERSION,
                'php_memory_limit'       => ini_get('memory_limit'),
            ],
            // Recorded, but not required to match — a code change is the point.
            'build' => [
                'wp_version'       => get_bloginfo('version'),
                'slimstat_version' => defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : null,
                'pro_active'       => (bool) (defined('WP_PLUGIN_DIR') && is_plugin_active_safe('wp-slimstat-pro/wp-slimstat-pro.php')),
                'object_cache'     => wp_using_ext_object_cache() ? 'external' : 'none',
                'timezone_string'  => get_option('timezone_string'),
            ],
            'data' => [
                'stats_rows'   => (int) $db->get_var("SELECT COUNT(*) FROM `{$stats_table}`"),
                'bytes_total'  => (int) (($sizes['d'] ?? 0) + ($sizes['i'] ?? 0)),
                'data_bytes'   => (int) ($sizes['d'] ?? 0),
                'index_bytes'  => (int) ($sizes['i'] ?? 0),
            ],
        ];
    }

    /**
     * is_plugin_active() lives in an admin-only file; wp eval-file does not load it.
     */
    function is_plugin_active_safe(string $basename): bool
    {
        $active = (array) get_option('active_plugins', []);
        if (in_array($basename, $active, true)) {
            return true;
        }
        $network = (array) get_site_option('active_sitewide_plugins', []);
        return isset($network[$basename]);
    }

    /** Stable hash over the server subset — the comparability key. */
    function slimstat_bench_fingerprint_hash(array $fingerprint): string
    {
        return substr(md5((string) wp_json_encode($fingerprint['server'] ?? [])), 0, 12);
    }
}

// Standalone invocation prints the fingerprint; when require'd, it does not.
if (!defined('SLIMSTAT_BENCH_FINGERPRINT_LIB')) {
    $db = (class_exists('wp_slimstat') && wp_slimstat::$wpdb instanceof wpdb) ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];
    $fp = slimstat_bench_fingerprint($db);

    printf("fingerprint %s\n\n", slimstat_bench_fingerprint_hash($fp));
    foreach ($fp as $section => $values) {
        echo strtoupper($section) . "\n";
        foreach ($values as $key => $value) {
            if (in_array($key, ['buffer_pool_bytes', 'bytes_total', 'data_bytes', 'index_bytes',
                'tmp_table_size', 'max_heap_table_size'], true) && is_int($value)) {
                $value = number_format($value / 1048576, 1) . ' MB';
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_int($value)) {
                $value = number_format($value);
            }
            printf("  %-26s %s\n", $key, (string) $value);
        }
        echo "\n";
    }

    // The one setting most likely to make every number meaningless.
    $pool = (int) $fp['server']['buffer_pool_bytes'];
    $data = (int) $fp['data']['bytes_total'];
    if ($data > 0 && $pool < $data) {
        printf(
            "WARNING: buffer pool (%s MB) is smaller than the SlimStat tables (%s MB).\n"
            . "         Every report below is a cold-disk benchmark dominated by page reads,\n"
            . "         not by SlimStat's SQL. Pin it before trusting any latency:\n"
            . "             SET GLOBAL innodb_buffer_pool_size = %d;\n",
            number_format($pool / 1048576, 1),
            number_format($data / 1048576, 1),
            max(268435456, $data * 2)
        );
    }
}
