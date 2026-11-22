<?php
/** #429: run via wp eval-file on a disposable tests-* database. Optional argument: analytics DB host. */
if (!defined('ABSPATH') || !preg_match('/^tests-/', DB_NAME)) {
    throw new RuntimeException('A disposable tests-* WordPress database is required');
}
$db = empty($args[0]) ? $GLOBALS['wpdb'] : new wpdb('root', 'root', 'tests-issue360', $args[0]);
if (!preg_match('/^tests-/', (string) $db->get_var('SELECT DATABASE()'))) {
    throw new RuntimeException('A disposable tests-* analytics database is required');
}
$prefix = 'issue429_' . bin2hex(random_bytes(4)) . '_';
$core = clone $GLOBALS['wpdb'];
$core->prefix = $prefix;
$check = static function ($ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$query = static function (string $sql) use ($db, $check): void {
    $check(false !== $db->query($sql), 'Fixture SQL failed: ' . $db->last_error);
};
$results = [];
try {
    foreach (['latin1', 'myisam', 'custom-default', 'custom-comment'] as $scenario) {
        $before = $fields = $definitions = [];
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $table = $prefix . $suffix;
            $query(\SlimStat\Schema\Schema::createTableSql($suffix, $prefix, 'utf8mb4_unicode_ci'));
            $query("ALTER TABLE `$table` MODIFY city VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL, MODIFY username VARCHAR(255) NOT NULL, MODIFY email VARCHAR(255) DEFAULT NULL, MODIFY fingerprint VARCHAR(255) DEFAULT NULL");
            if ('myisam' === $scenario) { $query("ALTER TABLE `$table` ENGINE=MyISAM"); }
            if ('custom-default' === $scenario) { $query("ALTER TABLE `$table` ALTER city SET DEFAULT ''"); }
            if ('custom-comment' === $scenario) { $query("ALTER TABLE `$table` MODIFY city VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'keep this comment'"); }
            if ('slim_stats_archive' === $suffix) { $query("ALTER TABLE `$table` DROP COLUMN dt_out, DROP COLUMN tz_offset"); }
            for ($i = 1; $i <= 3; $i++) {
                $query($db->prepare("INSERT INTO `$table` (dt,resource,city,username,email,fingerprint) VALUES (%d,%s,%s,%s,%s,%s)",
                    946684800 + $i, '/historical-' . $i, str_repeat('é', 255), 'old visitor ' . $i, str_repeat('a', 255), str_repeat('f', 255)));
            }
            $fields[$suffix] = implode(',', array_map(static fn ($name) => '`' . $name . '`', $db->get_col("SHOW COLUMNS FROM `$table`")));
            $before[$suffix] = $db->get_results("SELECT {$fields[$suffix]} FROM `$table` ORDER BY id", ARRAY_A);
            $definitions[$suffix] = $db->get_results("SHOW FULL COLUMNS FROM `$table`", ARRAY_A);
        }
        $repair = new \SlimStat\Migration\Migrations\RepairLegacyColumnWidths($db, $core);
        $guidance = $repair->getManualRepairGuidance();
        $check(count($guidance) === 2, 'Both tables need recovery guidance');
        foreach ($guidance as $item) {
            if (strpos($scenario, 'custom-') === 0) {
                $check('' === $item['sql'], 'Custom attributes must not be overwritten');
                $check(strpos(implode(' ', $item['reasons']), 'city') !== false, 'Custom column must be identified');
            } else {
                $check(strpos($item['sql'], 'ALGORITHM=COPY') !== false, 'Missing rebuild SQL');
                $query($item['sql']);
            }
        }
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $table = $prefix . $suffix;
            $check($before[$suffix] === $db->get_results("SELECT {$fields[$suffix]} FROM `$table` ORDER BY id", ARRAY_A), 'Historical values changed');
            $after = $db->get_results("SHOW FULL COLUMNS FROM `$table`", ARRAY_A);
            foreach ($definitions[$suffix] as $i => $column) {
                if (strpos($scenario, 'custom-') !== 0 && 'varchar(255)' === strtolower($column['Type'])) { $column['Type'] = 'varchar(256)'; }
                $check($column === $after[$i], 'Column attributes changed unexpectedly: ' . $column['Field']);
            }
        }
        if (strpos($scenario, 'custom-') !== 0) {
            $check([] === $repair->getManualRepairGuidance(), 'Recovery guidance remains after repair');
            $check('0' === (string) $db->get_var("SELECT SUM(dt_out + tz_offset) FROM `{$prefix}slim_stats_archive`"), 'Incorrect archive defaults');
        }
        $results[] = ['scenario' => $scenario, 'rows_preserved' => 6, 'attributes_preserved' => true];
        foreach (['slim_stats_archive', 'slim_stats'] as $suffix) { $query("DROP TABLE `{$prefix}{$suffix}`"); }
    }
    echo json_encode(['pass' => true, 'server' => $db->db_version(), 'separate_analytics_connection' => !empty($args[0]), 'results' => $results], JSON_PRETTY_PRINT) . "\n";
} finally {
    foreach (['slim_stats_archive', 'slim_stats'] as $suffix) { $db->query("DROP TABLE IF EXISTS `{$prefix}{$suffix}`"); }
}
