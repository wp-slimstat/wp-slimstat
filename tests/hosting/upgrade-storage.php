<?php
/** Real WordPress charset rejection before the visit-identity migration. */
use SlimStat\Tracker\Storage;

if (!defined('WP_CLI') || !WP_CLI || getenv('SLIMSTAT_HOSTING_DISPOSABLE') !== '1'
    || !preg_match('/^tests-[a-z0-9-]+$/D', DB_NAME) || DB_HOST !== 'db') {
    throw new RuntimeException('Requires the explicitly armed disposable Docker stack.');
}
$source = $GLOBALS['wpdb'];
$analytics = wp_slimstat::$wpdb;
$database = 'test_slimstat_upgrade_' . bin2hex(random_bytes(4));
if (false === $source->query("CREATE DATABASE `$database`")) { throw new RuntimeException($source->last_error); }
$checks = [];
$counts = [];
try {
    $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
    $db->set_prefix('up_');
    if (false === $db->query("CREATE TABLE up_options LIKE `" . DB_NAME . "`.{$source->prefix}options")) {
        throw new RuntimeException($db->last_error);
    }
    $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db;
    wp_cache_flush();
    foreach (['legacy', 'legacy-translated', 'migrated'] as $shape) {
        $table = 'up_' . str_replace('-', '_', $shape);
        $identity = 'migrated' === $shape ? ', vid_hash BINARY(16) DEFAULT NULL' : '';
        if (false === $db->query("CREATE TABLE `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            resource VARCHAR(2048), dt INT UNSIGNED $identity) DEFAULT CHARSET=utf8mb4")) {
            throw new RuntimeException($db->last_error);
        }
        // 0xff is invalid UTF-8: the old table has no binary column, so wpdb rejects
        // the first query before MySQL can report the absent vid_hash column.
        $queries = [];
        $capture = static function ($query) use (&$queries) { $queries[] = $query; return $query; };
        $translate = static function ($translation, $text) {
            return 'WordPress database error: Could not perform query because it contains invalid data.' === $text
                ? 'Datenbankfehler: Die Abfrage enthaelt ungueltige Daten.' : $translation;
        };
        if ('legacy-translated' === $shape) { add_filter('gettext', $translate, 10, 2); }
        add_filter('query', $capture);
        $write = Storage::insertRow(['resource' => '/upgrade-' . $shape, 'dt' => 1,
            'vid_hash' => str_repeat('ff', 16)], $table);
        remove_filter('query', $capture);
        remove_filter('gettext', $translate);
        $counts[$shape] = ['charset_probes' => count(preg_grep('/^SHOW FULL COLUMNS FROM `?' . $table . '`?$/', $queries)), 'inserts' => count(preg_grep('/^INSERT(?: IGNORE)? INTO ' . $table . '\b/', $queries)), 'column_probes' => count(preg_grep('/^SHOW COLUMNS/', $queries))];
        $inserts = $counts[$shape]['inserts'];
        $probes = $counts[$shape]['column_probes'];
        $checks[$shape . '_no_binary_charset_probe'] = 0 === $counts[$shape]['charset_probes'];
        $checks[$shape . '_stored_once'] = $write->isStored()
            && 1 === (int) $db->get_var("SELECT COUNT(*) FROM `$table`");
        $checks[$shape . '_resource_preserved'] = '/upgrade-' . $shape === $db->get_var("SELECT resource FROM `$table`");
        if ('migrated' === $shape) {
            $checks['identity_bytes_preserved'] = str_repeat('FF', 16) === $db->get_var("SELECT HEX(vid_hash) FROM `$table`");
            $checks['migrated_no_retry_or_storage_probe'] = 1 === $inserts && 0 === $probes;
        } else {
            $checks[$shape . '_reactive_retry'] = 2 === $inserts && 1 === $probes;
        }
    }
    $db->suppress_errors(true);
    $missing = Storage::insertRow(['resource' => '/missing', 'dt' => 1, 'vid_hash' => str_repeat('ff', 16)], 'up_missing');
    $checks['missing_table_error_reaches_recovery'] = $missing->isFailed()
        && (bool) preg_match('/doesn.t exist|does not exist|1146/i', $missing->error());
    echo json_encode(['wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
        'checks' => $checks, 'query_counts' => $counts], JSON_PRETTY_PRINT) . "\n";
} finally {
    $GLOBALS['wpdb'] = $source;
    wp_slimstat::$wpdb = $analytics;
    wp_cache_flush();
    if (false === $source->query("DROP DATABASE `$database`")) { throw new RuntimeException($source->last_error); }
}
if (in_array(false, $checks, true)) { WP_CLI::error('Upgrade storage contract failed.'); }
