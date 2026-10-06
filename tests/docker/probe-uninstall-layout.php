<?php
/** wp eval-file <this file> <uninstall.php>; all SQL writes use a fresh disposable schema. */
$core = $GLOBALS['wpdb'];
$source = file_get_contents($args[0]);
if (!preg_match('/function slimstat_uninstall\([^)]*\)\s*\{([\s\S]*)\}\s*$/', $source, $match)) {
    throw new RuntimeException('Uninstall function anchor missing');
}
$anchor = strpos($match[1], '// The goals/funnels/unique-visitor transients');
if (false === $anchor) { throw new RuntimeException('Layout cleanup anchor missing'); }
$cleanup = substr($match[1], $anchor);
$schema = 'qualification_uninstall_meta_' . bin2hex(random_bytes(6));
$failures = [];
if (false === $core->query("CREATE DATABASE `$schema`")) { throw new RuntimeException('Disposable schema creation failed'); }
try {
    $db = new wpdb(DB_USER, DB_PASSWORD, $schema, DB_HOST);
    $db->set_prefix('qual_');
    if (false === $db->query('CREATE TABLE qual_usermeta (umeta_id bigint unsigned AUTO_INCREMENT PRIMARY KEY, meta_key varchar(255), meta_value longtext)')) {
        throw new RuntimeException('Disposable metadata table creation failed');
    }
    $GLOBALS['wpdb'] = $db;
    foreach (['qual_', 'qual_2_'] as $prefix) {
        $db->prefix = $prefix;
        $db->query('TRUNCATE TABLE qual_usermeta');
        $owned = ['meta-box-order_slimstat_page_slimlayout', $prefix . 'metaboxhidden_admin_page_slimview1',
            'closedpostboxes_slimstat_page_slimlayout-network', 'screen_layout_admin_page_slimview', 'mmetaboxhidden_slimstat_page_slimlayout'];
        $unrelated = ['other_meta-box-order_slimstat_page_slimlayout', 'meta-box-order_slimstat_page_slimlayout_other',
            'metaboxhiddenXslimstat_page_slimlayout', 'closedpostboxes_slimstat_other', 'meta-box-order_dashboard',
            'qual_9_metaboxhidden_slimstat_page_slimlayout'];
        foreach (array_merge($owned, $unrelated) as $key) {
            if (false === $db->insert('qual_usermeta', ['meta_key' => $key, 'meta_value' => 'canary'])) {
                throw new RuntimeException('Canary insert failed');
            }
        }
        eval($cleanup); // Execute the shipped cleanup SQL, not a restated candidate predicate.
        if ('' !== $db->last_error) { $failures[] = $prefix . ': cleanup SQL failed'; }
        $actual = $db->get_col('SELECT meta_key FROM qual_usermeta ORDER BY meta_key');
        sort($unrelated);
        if ($actual !== $unrelated) { $failures[] = ['prefix' => $prefix, 'expected' => $unrelated, 'actual' => $actual]; }
    }
} finally {
    $GLOBALS['wpdb'] = $core;
    if (false === $core->query("DROP DATABASE `$schema`")) { throw new RuntimeException('Disposable schema cleanup failed'); }
}
echo json_encode(['source_sha256' => hash('sha256', $source), 'php' => PHP_VERSION,
    'mysql' => $core->db_version(), 'failures' => $failures, 'status' => $failures ? 'FAIL' : 'PASS']) . "\n";
if ($failures) { throw new RuntimeException('Uninstall layout ownership failed'); }
