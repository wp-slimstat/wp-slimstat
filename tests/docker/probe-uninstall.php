<?php
/** wp eval-file probe; every write is confined to the freshly installed rehearsal network. */
global $wpdb;
require_once '/tmp/uninstall-schema.php';
use SlimStat\Schema\Schema;
$mode = $args[0] ?? '';
$case = $args[1] ?? '';
$owner = $args[2] ?? '';
if (!defined('SLIMSTAT_UNINSTALL_REHEARSAL') || 'disposable' !== SLIMSTAT_UNINSTALL_REHEARSAL ||
    'ssun_' !== $wpdb->base_prefix || !in_array($owner, ['local', 'external'], true)) {
    throw new RuntimeException('Refusing a non-rehearsal site');
}
$fixture = json_decode(file_get_contents('/tmp/uninstall-fixture.json'), true);
if (($fixture['format'] ?? '') !== 'slimstat-uninstall-v1') { throw new RuntimeException('Invalid fixture'); }
$external = new wpdb('root', 'root', 'analytics', 'analytics-db:3306');
$databases = ['local' => $wpdb, 'external' => $external];
$blogs = get_sites(['fields' => 'ids', 'number' => 0]);
if (count($blogs) !== 2) { throw new RuntimeException('Expected two disposable blogs'); }
$freeHooks = require '/tmp/uninstall-cron-hooks.php';
$proHooks = ['slimstat_pro_rehearsal_cron'];
// Load just the literal declared hook inventory, not Pro's uninstall implementation.
$proSource = file_get_contents('/tmp/uninstall-pro.php');
if (preg_match('/function slimstat_pro_uninstall_cron_hooks\(\)\s*\{(.*?)\n\}/s', $proSource, $match)) {
    preg_match_all("/'([^']+)'/", $match[1], $hooks);
    $proHooks = $hooks[1];
}
$base = WP_CONTENT_DIR . '/uploads';
$files = ['geo' => $base . '/wp-slimstat/manual.mmdb', 'cache' => $base . '/wp-slimstat/browscap-cache-master/cache', 'unrelated' => $base . '/unrelated-proof'];
$must = static function ($ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } };
$execute = static function ($db, string $sql) use ($must): void { $must(false !== $db->query($sql), 'Fixture SQL failed'); };
$allTables = static function (string $prefix): array {
    return array_unique(array_merge(Schema::tableNames($prefix), array_map(static fn ($s) => $prefix . $s, Schema::legacyTables('prefix')),
        array_map(static fn ($s) => 'ssun_' . $s, Schema::legacyTables('base_prefix'))));
};
if ('seed' === $mode) {
    foreach ($blogs as $blogId) {
        switch_to_blog((int) $blogId);
        foreach ($databases as $db) {
            $execute($db, 'SET FOREIGN_KEY_CHECKS=0');
            foreach ($allTables($wpdb->prefix) as $table) { $execute($db, "DROP TABLE IF EXISTS `$table`"); }
            $execute($db, 'SET FOREIGN_KEY_CHECKS=1');
            $result = Schema::ensure($db, $wpdb->prefix, static fn () => 'utf8mb4_unicode_ci');
            $must([] === $result['failed'], 'Manifest fixture creation failed');
            $tables = Schema::tables();
            usort($tables, static fn ($a, $b) => ('slim_stats' === $a ? -1 : ('slim_stats' === $b ? 1 : strcmp($a, $b))));
            foreach ($tables as $suffix) {
                $table = $wpdb->prefix . $suffix;
                $row = [];
                foreach ($db->get_results("SHOW FULL COLUMNS FROM `$table`", ARRAY_A) as $column) {
                    if (false !== strpos($column['Extra'], 'auto_increment')) { continue; }
                    if ('YES' === $column['Null'] || null !== $column['Default']) { continue; }
                    $row[$column['Field']] = preg_match('/int|decimal|float|double/', $column['Type']) ? 1 : 'fixture';
                }
                if (in_array($suffix, ['slim_events', 'slim_events_archive'], true)) { $row['id'] = 1; }
                if (in_array($suffix, ['slim_stats', 'slim_stats_archive'], true)) { $row['resource'] = $fixture['resource']; }
                if ([] === $row) { $execute($db, "INSERT INTO `$table` () VALUES ()"); }
                else { $must(false !== $db->insert($table, $row), 'Fixture insert failed'); }
            }
            foreach (array_diff($allTables($wpdb->prefix), Schema::tableNames($wpdb->prefix)) as $table) {
                $execute($db, "CREATE TABLE IF NOT EXISTS `$table` (id int PRIMARY KEY)");
                $execute($db, "INSERT IGNORE INTO `$table` VALUES (1)");
            }
            foreach ([$wpdb->prefix . 'unrelated_proof', 'wp_slim_stats'] as $table) {
                $execute($db, "CREATE TABLE IF NOT EXISTS `$table` (id int PRIMARY KEY)");
                $execute($db, "INSERT IGNORE INTO `$table` VALUES (42)");
            }
        }
        update_user_meta(1, $wpdb->prefix . 'metaboxhidden_slimstat_page_slimlayout', 'owned-layout');
        foreach (['other_meta-box-order_slimstat_page_slimlayout', 'metaboxhidden_slimstat_page_slimlayout_other', 'closedpostboxesXslimstat_page_slimlayout'] as $near) {
            update_user_meta(1, $wpdb->prefix . $near, 'unrelated-layout-' . $blogId);
        }
        $settings = ['is_tracking' => 'on', 'addon_heatmap_enable' => 'on', 'addon_licenses' => 'fixture',
            'addon_custom_db_dbhost' => 'analytics-db:3306', 'addon_custom_db_dbname' => 'analytics',
            'addon_custom_db_dbuser' => 'root', 'addon_custom_db_dbpass' => 'root'];
        if ('external' !== $owner) { $settings['addon_custom_db_dbhost'] = ''; }
        if ('keep-no' === $case) { $settings['delete_data_on_uninstall'] = 'no'; }
        if (0 === strpos($case, 'delete') || 'pro-then-free' === $case) { $settings['delete_data_on_uninstall'] = 'on'; }
        update_option('slimstat_options', $settings);
        foreach (array_merge($freeHooks, $proHooks, ['unrelated_rehearsal_cron']) as $hook) {
            wp_clear_scheduled_hook($hook);
            wp_schedule_single_event(time() + 86400, $hook);
        }
        restore_current_blog();
    }
    update_site_option('slimstat_options', $settings);
    wp_mkdir_p(dirname($files['cache']));
    foreach ($files as $key => $file) { $must(false !== file_put_contents($file, $fixture[$key] ?? $fixture['marker']), 'File fixture failed'); }
    echo "UNINSTALL-SEEDED\n";
    return;
}
if ('snapshot' !== $mode) { throw new RuntimeException('Unknown probe mode'); }
$snapshot = ['tables' => [], 'sentinels' => [], 'blogs' => [], 'files' => []];
$fingerprint = static function ($db, string $table) use ($must) {
    $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
    $must('' === $db->last_error, 'Table probe failed');
    if (null === $found) { return null; }
    $rows = $db->get_results("SELECT * FROM `$table`", ARRAY_A);
    $must('' === $db->last_error && is_array($rows), 'Row probe failed');
    $encoded = array_map('serialize', $rows);
    sort($encoded);
    $ddl = $db->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
    $must(is_array($ddl), 'DDL probe failed');
    return ['sha256' => hash('sha256', serialize([$ddl[1], $encoded])), 'rows' => count($rows)];
};
$credentials = static fn ($settings) => is_array($settings) && !empty($settings['addon_custom_db_dbpass']);
foreach ($blogs as $blogId) {
    switch_to_blog((int) $blogId);
    foreach ($databases as $name => $db) {
        foreach ($allTables($wpdb->prefix) as $table) { $snapshot['tables'][$name][$table] = $fingerprint($db, $table); }
        foreach ([$wpdb->prefix . 'unrelated_proof', 'wp_slim_stats'] as $table) { $snapshot['sentinels'][$name . '.' . $table] = $fingerprint($db, $table); }
    }
    $settings = get_option('slimstat_options', null);
    $scheduled = static function (array $hooks): array { $state = []; foreach ($hooks as $hook) { $state[$hook] = false !== wp_next_scheduled($hook); } return $state; };
    $snapshot['blogs'][(string) $blogId] = ['settings' => is_array($settings), 'credentials' => $credentials($settings),
        'layout_metadata' => '' !== get_user_meta(1, $wpdb->prefix . 'metaboxhidden_slimstat_page_slimlayout', true),
        'free_setting' => $settings['is_tracking'] ?? null, 'pro_setting' => isset($settings['addon_heatmap_enable']),
        'free_cron' => $scheduled($freeHooks), 'pro_cron' => $scheduled($proHooks)];
    foreach (['other_meta-box-order_slimstat_page_slimlayout', 'metaboxhidden_slimstat_page_slimlayout_other', 'closedpostboxesXslimstat_page_slimlayout'] as $near) {
        $snapshot['sentinels']['usermeta.' . $blogId . '.' . $near] = get_user_meta(1, $wpdb->prefix . $near, true);
    }
    $snapshot['sentinels']['cron.' . $blogId] = wp_next_scheduled('unrelated_rehearsal_cron');
    restore_current_blog();
}
$network = get_site_option('slimstat_options', []);
$snapshot['network_credentials'] = $credentials($network);
$snapshot['network_free_setting'] = $network['is_tracking'] ?? null;
$snapshot['network_pro_setting'] = isset($network['addon_heatmap_enable']);
foreach ($files as $key => $file) { $snapshot['files'][$key] = is_file($file) ? hash_file('sha256', $file) : null; }
$snapshot['plugins'] = ['free' => is_file(WP_PLUGIN_DIR . '/wp-slimstat/wp-slimstat.php'), 'pro' => is_file(WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php')];
echo 'UNINSTALL-JSON:' . json_encode($snapshot) . "\n";
