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
if ('create' === $mode) {
    for ($i = 1; $i <= $fixture['subsites']; ++$i) {
        $id = wp_insert_site(['domain' => get_network()->domain, 'path' => '/site-' . $i . '/', 'network_id' => get_current_network_id(), 'title' => 'Rehearsal ' . $i, 'user_id' => 1]);
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
if ('snapshot' !== $mode && 'baseline' !== $mode) { throw new RuntimeException('Unknown network mode'); }
$baseline = 'snapshot' === $mode ? json_decode(file_get_contents('/tmp/network-before.json'), true) : null;
$result = ['blogs' => [], 'pending' => array_values((array) get_site_option('slimstat_network_activation_pending', [])),
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
        $columns[$suffix] = null === $baseline ? $wpdb->get_col("SHOW COLUMNS FROM `$table`") : $baseline['blogs'][$id]['columns'][$suffix];
        $must(is_array($columns[$suffix]) && [] !== $columns[$suffix], 'Missing vintage columns');
        $select = implode(',', array_map(static fn ($column) => '`' . str_replace('`', '``', $column) . '`', $columns[$suffix]));
        $values = $wpdb->get_results("SELECT $select FROM `$table` ORDER BY id", ARRAY_A);
        $must('' === $wpdb->last_error && is_array($values) && count($values) > 0, 'Missing vintage data');
        $rows[$suffix] = $values;
    }
    $missing = [];
    foreach (\SlimStat\Schema\Schema::tableNames($prefix) as $table) {
        if (null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) { $missing[] = $table; }
        $must('' === $wpdb->last_error, 'Unreadable table metadata');
    }
    $result['blogs'][$id] = ['fingerprint' => hash('sha256', serialize($rows)), 'columns' => $columns,
        'archived' => '1' === (string) $site->archived, 'missing' => $missing];
}
if ('baseline' === $mode) { file_put_contents('/tmp/network-before.json', json_encode($result)); }
echo 'NETWORK-JSON:' . json_encode($result) . "\n";
