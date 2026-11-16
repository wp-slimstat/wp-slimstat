<?php
/** Real separate-schema privacy check: core lookalikes must never be erased. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) { throw new RuntimeException('Disposable database required'); }
$database = 'test_slimstat_privacy_' . bin2hex(random_bytes(5));
$prefix = 'privacy_' . bin2hex(random_bytes(5)) . '_';
$originalPrefix = $wpdb->prefix;
$originalAnalytics = wp_slimstat::$wpdb;
$settings = wp_slimstat::$settings;
$options = [];
foreach (['slimstat_data_ver', 'slimstat_goals_cache_ver', \SlimStat\Ecommerce\Integration::STATE] as $key) { $options[$key] = get_option($key, null); }
$run = static function ($db, $sql) { if (false === $db->query($sql)) { throw new RuntimeException($db->last_error); } };
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$db = null;
try {
    $run($wpdb, "CREATE DATABASE {$database}");
    $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
    foreach ([$wpdb, $db] as $handle) {
        foreach (['', '_archive'] as $suffix) {
            $run($handle, "CREATE TABLE {$prefix}slim_stats{$suffix} (id BIGINT PRIMARY KEY, ip VARCHAR(40), other_ip VARCHAR(40), username VARCHAR(100), email VARCHAR(100), fingerprint VARCHAR(100), vid_hash BINARY(16), visit_id BIGINT, resource VARCHAR(100), dt BIGINT) ENGINE=InnoDB");
            $run($handle, "CREATE TABLE {$prefix}slim_events{$suffix} (event_id BIGINT PRIMARY KEY, id BIGINT, dt BIGINT, KEY id(id)) ENGINE=InnoDB");
        }
    }
    $wpdb->prefix = $prefix;
    wp_slimstat::$wpdb = $db;
    // Deliberately leave the analytics handle's prefix different, as CustomDB does.
    $db->prefix = 'wrong_';
    update_option(\SlimStat\Ecommerce\Integration::STATE, []);
    foreach ([$wpdb, $db] as $handle) {
        foreach (['', '_archive'] as $suffix) {
            $run($handle, "INSERT INTO {$prefix}slim_stats{$suffix} VALUES (1,'192.0.2.1','','visitor','erase@example.test','fingerprint',UNHEX(REPEAT('ab',16)),1,'/retained',1000000)");
            $run($handle, "INSERT INTO {$prefix}slim_events{$suffix} VALUES (1,1,1000001)");
        }
    }
    $version = get_option('slimstat_data_ver', '0');
    $result = \SlimStat\Services\Privacy\DataEraser::eraseEvents('erase@example.test');
    $check(!is_wp_error($result) && $result['done'], 'External event erasure completes');
    foreach (['', '_archive'] as $suffix) {
        $check('0' === $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_events{$suffix}"), 'External events must be erased');
        $check('1' === $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}slim_events{$suffix}"), 'Core event lookalikes must stay untouched');
    }
    $check(get_option('slimstat_data_ver') !== $version, 'Event erasure invalidates report caches');
    $result = \SlimStat\Services\Privacy\DataEraser::erasePageviews('erase@example.test');
    $check(!is_wp_error($result) && $result['done'], 'External pageview erasure completes');
    foreach (['', '_archive'] as $suffix) {
        $check('0' === $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats{$suffix}"), 'External pageviews must be erased');
        $check('1' === $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats{$suffix}"), 'Core pageview lookalikes must stay untouched');
        $run($db, "INSERT INTO {$prefix}slim_stats{$suffix} SELECT * FROM `" . DB_NAME . "`.{$prefix}slim_stats{$suffix}");
    }
    $version = get_option('slimstat_data_ver');
    $result = \SlimStat\Services\Privacy\DataEraser::anonymizeByIp('192.0.2.1');
    $check(!is_wp_error($result) && $result['done'], 'External IP anonymization completes');
    foreach (['', '_archive'] as $suffix) {
        $check('0' === $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats{$suffix} WHERE ip='192.0.2.1' OR email<>'' OR fingerprint<>'' OR vid_hash IS NOT NULL"), 'External live and archive identifiers must be cleared');
        $check('192.0.2.1' === $wpdb->get_var("SELECT ip FROM {$prefix}slim_stats{$suffix} WHERE id=1"), 'Core IP lookalike must stay untouched');
    }
    $check(get_option('slimstat_data_ver') !== $version, 'IP anonymization invalidates report caches');
    foreach (['text', 'integer'] as $format) {
        foreach (['', '_archive'] as $suffix) {
            $run($db, "DELETE FROM {$prefix}slim_stats{$suffix}");
            if ($format === 'integer') {
                $run($db, "ALTER TABLE {$prefix}slim_stats{$suffix} MODIFY ip BIGINT UNSIGNED, MODIFY other_ip BIGINT UNSIGNED, DROP COLUMN fingerprint, DROP COLUMN vid_hash");
            }
            $storedIp = $format === 'integer' ? sprintf('%u', ip2long('192.0.2.1')) : '192.0.2.1';
            for ($id = 1; $id <= 501; ++$id) {
                $run($db, $db->prepare("INSERT INTO {$prefix}slim_stats{$suffix} (id, ip, other_ip, username, email, dt) VALUES (%d,%s,%s,'visitor','erase@example.test',1000000)", $id, $storedIp, $format === 'integer' ? '0' : ''));
            }
        }
        for ($page = 1; $page <= 3; ++$page) {
            $result = \SlimStat\Services\Privacy\DataEraser::anonymizeByIp('192.0.2.1', $page);
            $check(!is_wp_error($result), 'Legacy IP anonymization must not fail');
            if ($result['done']) { break; }
        }
        $check($page === 2, '501 IP rows per live/archive table drain in two calls');
        foreach (['', '_archive'] as $suffix) {
            $check('501' === $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats{$suffix} WHERE username='' AND email=''"), 'IP anonymization preserves rows but removes identifiers');
        }
        $result = \SlimStat\Services\Privacy\DataEraser::anonymizeByIp('0.0.0.0');
        $check(!is_wp_error($result) && $result['done'] && !$result['items_removed'], 'Already anonymous marker must finish without repeated updates');
    }
    echo "PASS: 501-row text/integer IP archives, absent optional fields and anonymous-marker idempotence\n";
    $run($db, "DROP TABLE {$prefix}slim_stats");
    $db->suppress_errors(true);
    $check(is_wp_error(\SlimStat\Services\Privacy\DataEraser::anonymizeByIp('192.0.2.1')), 'External anonymization failure must refuse completion');
    echo "PASS: external live/archive privacy, core lookalikes, independent prefix, cache invalidation and errors\n";
} finally {
    wp_slimstat::$wpdb = $originalAnalytics; wp_slimstat::$settings = $settings; $wpdb->prefix = $originalPrefix;
    foreach (['slim_events','slim_events_archive','slim_stats','slim_stats_archive'] as $suffix) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$suffix}"); }
    if ($db) { $db->close(); }
    $wpdb->query("DROP DATABASE IF EXISTS {$database}");
    foreach ($options as $key => $value) { if (null === $value) { delete_option($key); } else { update_option($key, $value); } }
}
