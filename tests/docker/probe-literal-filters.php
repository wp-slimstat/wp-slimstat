<?php
/**
 * Live SQL regression: run with `wp eval-file` on a disposable test database.
 * Only a connection-local TEMPORARY table is written; analytics tables are never touched.
 */
if (PHP_SAPI !== 'cli' || !isset($GLOBALS['wpdb'])) {
    throw new RuntimeException('Run this probe from a CLI with WordPress wpdb loaded.');
}
require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
$db = $GLOBALS['wpdb'];
$table = 'slimstat_literal_filter_' . bin2hex(random_bytes(6));
$originalColumns = wp_slimstat_db::$columns_names;
wp_slimstat_db::$columns_names = ['browser' => ['Browser', 'varchar'], 'resource' => ['Resource', 'varchar'], 'notes' => ['Notes', 'varchar']];
$values = ['100%', '100X', 'x100%y', 'my_page', 'myXpage', 'my\\page', 'mypage', 'xmy\\pagey', 'plain'];
$cases = [
    ['browser', 'contains', '100%', [1, 3]],
    ['browser', 'does_not_contain', '100%', [2, 4, 5, 6, 7, 8, 9]],
    ['browser', 'starts_with', '100%', [1]],
    ['browser', 'ends_with', '100%', [1]],
    ['browser', 'contains', 'my_', [4]],
    ['browser', 'does_not_contain', 'my_', [1, 2, 3, 5, 6, 7, 8, 9]],
    ['browser', 'starts_with', 'my_', [4]],
    ['browser', 'ends_with', '_page', [4]],
    ['browser', 'contains', 'my\\', [6, 8]],
    ['browser', 'does_not_contain', 'my\\', [1, 2, 3, 4, 5, 7, 9]],
    ['browser', 'starts_with', 'my\\', [6]],
    ['browser', 'ends_with', '\\page', [6]],
    ['browser', 'matches', '^100.', [1, 2]],
    ['browser', 'does_not_match', '^100.', [3, 4, 5, 6, 7, 8, 9]],
    ['resource', 'contains', '100%', [1, 3]],
    ['resource', 'starts_with', '/my_', [4]],
    ['resource', 'contains', 'my\\', [6, 8]],
    ['notes', 'equals', '100%', [1, 3]],
    ['notes', 'is_not_equal_to', '100%', [2, 4, 5, 6, 7, 8, 9]],
];
$failures = [];
try {
    if (false === $db->query("CREATE TEMPORARY TABLE `$table` (id INT PRIMARY KEY, browser VARCHAR(255), resource VARCHAR(255), notes VARCHAR(255))")) {
        throw new RuntimeException('Cannot create temporary fixture: ' . $db->last_error);
    }
    foreach ($values as $i => $value) {
        if (false === $db->query($db->prepare("INSERT INTO `$table` VALUES (%d, %s, %s, %s)", $i + 1, $value, '/' . urlencode($value), $value))) {
            throw new RuntimeException('Cannot insert temporary fixture: ' . $db->last_error);
        }
    }
    if (false === $db->query("INSERT INTO `$table` VALUES (10, NULL, NULL, NULL)")) {
        throw new RuntimeException('Cannot insert NULL control: ' . $db->last_error);
    }
    foreach ($cases as [$column, $operator, $value, $expected]) {
        $where = wp_slimstat_db::get_single_where_clause($column, $operator, $value);
        $actual = array_map('intval', $db->get_col("SELECT id FROM `$table` WHERE $where ORDER BY id"));
        if ($expected !== $actual || '' !== $db->last_error) {
            $failures[] = "$column $operator " . json_encode($value) . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ' ' . $db->last_error;
        }
    }
} finally {
    $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    wp_slimstat_db::$columns_names = $originalColumns;
}
if ($failures) {
    throw new RuntimeException(implode("\n", $failures));
}
echo 'PASS: ' . count($cases) . ' live literal-filter SQL cases on ' . $db->get_var('SELECT VERSION()')
    . '; sql_mode=' . $db->get_var('SELECT @@SESSION.sql_mode')
    . "; wildcard decoys and NULL rows distinguished\n";
