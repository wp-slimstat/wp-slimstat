<?php
/** WP-CLI only; synthetic data in the isolated custom-prefix network. */
global $wpdb;
if (!defined('SLIMSTAT_NETWORK_REHEARSAL') || 'disposable' !== SLIMSTAT_NETWORK_REHEARSAL || 'ssnw_' !== $wpdb->base_prefix) {
    throw new RuntimeException('Refusing a non-rehearsal network');
}
require_once '/tmp/network-schema.php';
$fixture = json_decode(file_get_contents('/tmp/network-fixture.json'), true);
if (($fixture['format'] ?? '') !== 'slimstat-network-v1' || 100 !== $fixture['subsites']) { throw new RuntimeException('Invalid network fixture'); }
$mode = $args[0] ?? '';
$must = static function ($ok, $label) { if (!$ok) { throw new RuntimeException($label); } };
$owner = defined('SLIMSTAT_NETWORK_STORAGE') ? SLIMSTAT_NETWORK_STORAGE : 'local';
$must(in_array($owner, ['local', 'external'], true), 'Invalid analytics owner');
if ('create' === $mode) {
    for ($i = 1; $i <= $fixture['subsites']; ++$i) {
        $id = wp_insert_site(['domain' => is_subdomain_install() ? 'site-' . $i . '.' . get_network()->domain : get_network()->domain, 'path' => is_subdomain_install() ? '/' : '/site-' . $i . '/', 'network_id' => get_current_network_id(), 'title' => 'Rehearsal ' . $i, 'user_id' => 1]);
        $must(!is_wp_error($id), 'Site creation failed');
    }
    echo "NETWORK-CREATED\n";
    return;
}
$sites = get_sites(['number' => 0, 'orderby' => 'id', 'order' => 'ASC']);
$must(101 === count($sites), 'Expected main site and 100 subsites');
if ('urls' === $mode) {
    foreach ($sites as $site) { echo get_site_url((int) $site->blog_id) . "\n"; }
    return;
}
if ('seed' === $mode) {
    foreach ($sites as $site) {
        $prefix = $wpdb->get_blog_prefix((int) $site->blog_id);
        $settings = get_blog_option((int) $site->blog_id, 'slimstat_options', []);
        $must('5.5.0' === ($settings['version'] ?? ''), 'Vintage settings mismatch on blog ' . $site->blog_id . ': ' . (string) ($settings['version'] ?? 'absent'));
        $must(array_key_exists('auto_purge', $settings), 'Vintage settings defaults missing on blog ' . $site->blog_id);
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $must(false !== $wpdb->insert($prefix . $suffix, ['resource' => $fixture['resource'] . '/' . $site->blog_id,
                'ip' => '192.0.2.' . $site->blog_id, 'dt' => 1700000000 + (int) $site->blog_id,
                'notes' => $fixture['notes'], 'visit_id' => (int) $site->blog_id]), 'Vintage row insert failed');
        }
    }
    update_blog_status($fixture['archived_blog'], 'archived', 1);
    echo "NETWORK-SEEDED\n";
    return;
}
$decoyFingerprint = static function ($db, $prefix) use ($must) {
    $state = [];
    foreach (\SlimStat\Schema\Schema::tableNames($prefix) as $table) {
        $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
        $must('' === $db->last_error, 'Isolation metadata unavailable');
        if (null === $found) { $state[$table] = null; continue; }
        $ddl = $db->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        $rows = $db->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A);
        $must('' === $db->last_error && is_array($ddl) && is_array($rows), 'Isolation data unavailable');
        $state[$table] = [$ddl[1], $rows];
    }
    return hash('sha256', serialize($state));
};
if ('offline' === $mode) {
    $must('external' === $owner, 'Offline proof requires external owner');
    $before = json_decode(file_get_contents('/tmp/network-before.json'), true);
    $must(isset($before['blogs']), 'Offline baseline unavailable');
    $pending = array_values((array) get_site_option('slimstat_network_activation_pending', []));
    $must([] !== $pending, 'Offline proof must start with pending work');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    $_SERVER['REQUEST_URI'] = '/network-offline-hit';
    wp_slimstat::slimtrack();
    foreach ($sites as $site) {
        $must($before['blogs'][(string) $site->blog_id]['isolation_fingerprint'] === $decoyFingerprint($wpdb, $wpdb->get_blog_prefix((int) $site->blog_id)), 'Offline tracking forked into local database');
    }
    $must(wp_slimstat::$wpdb !== $wpdb && false === wp_slimstat::$wpdb->query('SELECT 1'), 'Offline analytics handle did not fail closed');
    echo 'NETWORK-JSON:' . json_encode(['owner' => $owner, 'pending' => $pending, 'local_unchanged' => true, 'analytics_unavailable' => true, 'outage_hit_lost' => true]) . "\n";
    return;
}
$external = new wpdb('root', 'root', 'analytics', 'analytics-db:3306');
$must('1' === (string) $external->get_var('SELECT 1') && '' === $external->last_error, 'External database unavailable');
$externalServer = $external->get_var('SELECT @@server_uuid');
$coreServer = $wpdb->get_var('SELECT @@server_uuid');
$must(is_string($externalServer) && '' !== $externalServer && is_string($coreServer) && '' !== $coreServer && $externalServer !== $coreServer, 'Analytics server isolation not proven');
if ('prepare-storage' === $mode) {
    foreach ($sites as $site) {
        $prefix = $wpdb->get_blog_prefix((int) $site->blog_id);
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $table = $prefix . $suffix;
            $ddl = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            $must(is_array($ddl) && false !== $external->query($ddl[1]), 'Vintage external schema copy failed');
            foreach ($wpdb->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A) as $row) {
                $must(false !== $external->insert($table, $row), 'Vintage external row copy failed');
            }
        }
        $settings = get_blog_option((int) $site->blog_id, 'slimstat_options', []);
        $settings['addon_custom_db_enable'] = 'external' === $owner ? 'on' : 'no';
        $settings['addon_custom_db_dbhost'] = 'analytics-db:3306';
        $settings['addon_custom_db_dbname'] = 'analytics';
        $settings['addon_custom_db_dbuser'] = 'root';
        $settings['addon_custom_db_dbpass'] = 'root';
        update_blog_option((int) $site->blog_id, 'slimstat_options', $settings);
    }
    // Network-admin requests load the network row; CLI/site requests load per-blog rows.
    update_site_option('slimstat_options', get_blog_option(1, 'slimstat_options', []));
    echo "NETWORK-STORAGE-READY\n";
    return;
}
$analytics = 'external' === $owner ? $external : $wpdb;
$decoy = 'external' === $owner ? $wpdb : $external;
if ('mutate-isolation' === $mode) {
    $must(false !== $decoy->query("UPDATE ssnw_slim_stats SET resource='/required-red-isolation' LIMIT 1"), 'Isolation control failed');
    return;
}
if ('snapshot' !== $mode && 'baseline' !== $mode) { throw new RuntimeException('Unknown network mode'); }
$baseline = 'snapshot' === $mode ? json_decode(file_get_contents('/tmp/network-before.json'), true) : null;
$networkSettings = get_site_option('slimstat_options', []);
$result = ['owner' => $owner, 'network_credentials_present' => !empty($networkSettings['addon_custom_db_dbpass']), 'blogs' => [], 'pending' => array_values((array) get_site_option('slimstat_network_activation_pending', [])),
    'attempting' => (int) get_site_option('slimstat_network_activation_attempting', 0),
    'network_active' => isset(get_site_option('active_sitewide_plugins', [])['wp-slimstat/wp-slimstat.php'])];
foreach ($sites as $site) {
    $id = (string) $site->blog_id;
    $prefix = $wpdb->get_blog_prefix((int) $id);
    $columns = [];
    $rows = [];
    // Project the physical vintage columns, not a second schema definition; new columns are allowed.
    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
        $table = $prefix . $suffix;
        $columns[$suffix] = null === $baseline ? $analytics->get_col("SHOW COLUMNS FROM `$table`") : $baseline['blogs'][$id]['columns'][$suffix];
        $must(is_array($columns[$suffix]) && [] !== $columns[$suffix], 'Missing vintage columns');
        $select = implode(',', array_map(static fn ($column) => '`' . str_replace('`', '``', $column) . '`', $columns[$suffix]));
        $values = $analytics->get_results("SELECT $select FROM `$table` ORDER BY id", ARRAY_A);
        $must('' === $analytics->last_error && is_array($values) && count($values) > 0, 'Missing vintage data');
        $rows[$suffix] = $values;
    }
    $missing = [];
    foreach (\SlimStat\Schema\Schema::tableNames($prefix) as $table) {
        if (null === $analytics->get_var($analytics->prepare('SHOW TABLES LIKE %s', $analytics->esc_like($table)))) { $missing[] = $table; }
        $must('' === $analytics->last_error, 'Unreadable table metadata');
    }
    $settings = get_blog_option((int) $id, 'slimstat_options', []);
    $result['blogs'][$id] = ['fingerprint' => hash('sha256', serialize($rows)), 'columns' => $columns,
        'isolation_fingerprint' => $decoyFingerprint($decoy, $prefix), 'credentials_present' => !empty($settings['addon_custom_db_dbpass']),
        'archived' => '1' === (string) $site->archived, 'domain' => $site->domain, 'path' => $site->path, 'missing' => $missing];
}
if ('baseline' === $mode) { file_put_contents('/tmp/network-before.json', json_encode($result)); }
echo 'NETWORK-JSON:' . json_encode($result) . "\n";
