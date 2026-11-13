<?php
/** Live, data-preserving #360 regression. Run with wp eval-file on a disposable tests-* database. */
if (!defined('ABSPATH') || !preg_match('/^tests-/', DB_NAME)) {
    throw new RuntimeException('A disposable tests-* database is required');
}
$db = empty($args[0]) ? $GLOBALS['wpdb'] : new wpdb('root', 'root', 'tests-issue360', $args[0]);
if (!preg_match('/^tests-/', (string) $db->get_var('SELECT DATABASE()'))) {
    throw new RuntimeException('The target connection must use a disposable tests-* database');
}
$prefix = 'issue360_' . bin2hex(random_bytes(4)) . '_';
$core = clone $db;
$core->prefix = $prefix;
$check = static function ($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$query = static function (string $sql) use ($db, $check): void {
    $check(false !== $db->query($sql), 'Fixture SQL failed: ' . $db->last_error);
};
$before = [];
$fields = [];
$timeout = $db->get_var('SELECT @@SESSION.lock_wait_timeout');
try {
    $query(\SlimStat\Schema\Schema::createTableSql('slim_stats', $prefix, 'utf8mb4_unicode_ci'));
    $query(\SlimStat\Schema\Schema::createTableSql('slim_stats_archive', $prefix, 'utf8mb4_unicode_ci'));
    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
        $table = $prefix . $suffix;
        $query("ALTER TABLE `$table` MODIFY city VARCHAR(255) DEFAULT NULL, MODIFY username VARCHAR(255) DEFAULT NULL, MODIFY email VARCHAR(255) DEFAULT NULL, MODIFY fingerprint VARCHAR(255) DEFAULT NULL");
        if ('slim_stats_archive' === $suffix) {
            $query("ALTER TABLE `$table` DROP COLUMN dt_out, DROP COLUMN tz_offset");
        }
        for ($i = 1; $i <= 3; $i++) {
            $query($db->prepare("INSERT INTO `$table` (dt,resource,city,username,email,fingerprint) VALUES (%d,%s,%s,%s,%s,%s)",
                946684800 + $i, '/historical-' . $i, str_repeat('é', 255), 'legacy user', str_repeat('a', 255), str_repeat('f', 255)));
        }
        $fields[$suffix] = $db->get_col("SHOW COLUMNS FROM `$table`");
        $projection = implode(',', array_map(static fn ($name) => '`' . $name . '`', $fields[$suffix]));
        $before[$suffix] = $db->get_results("SELECT $projection FROM `$table` ORDER BY id", ARRAY_A);
    }
    $repair = new \SlimStat\Migration\Migrations\RepairLegacyColumnWidths($db, $core);
    $check($repair->isOptional() && $repair->shouldRun(), 'Legacy repair was not offered');
    $check($repair->run(), 'Legacy repair did not finish');
    $check(!$repair->shouldRun() && $repair->run(), 'Repair is not idempotent');
    foreach ($fields as $suffix => $names) {
        $projection = implode(',', array_map(static fn ($name) => '`' . $name . '`', $names));
        $after = $db->get_results("SELECT $projection FROM `{$prefix}{$suffix}` ORDER BY id", ARRAY_A);
        $check($before[$suffix] === $after, 'Historical values changed in ' . $suffix);
        $state = \SlimStat\Schema\Schema::columnState($db, $suffix, $prefix);
        $check(empty($state['missing']) && empty($state['narrow']) && empty($state['unavailable']), 'Column drift remains');
    }
    $check('0' === (string) $db->get_var("SELECT SUM(dt_out + tz_offset) FROM `{$prefix}slim_stats_archive`"), 'Archive defaults are incorrect');
    $check($timeout === $db->get_var('SELECT @@SESSION.lock_wait_timeout'), 'Session timeout was not restored');
    echo json_encode(['pass' => true, 'server' => $db->db_version(), 'rows_preserved' => 6,
        'historical_sha256' => hash('sha256', serialize($before)), 'idempotent' => true,
        'metadata_timeout_restored' => true], JSON_PRETTY_PRINT) . "\n";
} finally {
    foreach (['slim_stats_archive', 'slim_stats'] as $suffix) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}{$suffix}`");
    }
}
