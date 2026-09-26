<?php
/** Run: wp eval-file wp-content/plugins/wp-slimstat/tests/acquisition-reports-db-test.php
 * Uses disposable tables only. Also exercises a distinct analytics connection.
 */

use SlimStat\Migration\Migrations\AddAcquisitionColumns;
use SlimStat\Reports\AcquisitionReport;
use SlimStat\Tracker\Acquisition;
use SlimStat\Tracker\Storage;
use SlimStat\Utils\PurgeArchive;

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';

$core = $GLOBALS['wpdb'];
$originalDb = wp_slimstat::$wpdb;
$prefix = $core->prefix;
$settings = wp_slimstat::$settings;
$testPrefix = 'test_acquisition_' . bin2hex(random_bytes(4)) . '_';
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$checks = 0;
$check = static function ($condition, $message) use (&$checks) {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
        $check(false !== $db->query("CREATE TABLE `{$testPrefix}{$suffix}` LIKE `{$prefix}slim_stats`"), 'Create isolated table');
        $columns = array_intersect(Acquisition::COLUMNS, $db->get_col("SHOW COLUMNS FROM `{$testPrefix}{$suffix}`"));
        if ($columns) {
            $check(false !== $db->query("ALTER TABLE `{$testPrefix}{$suffix}` " . implode(', ', array_map(static function ($column) {
                return 'DROP COLUMN `' . $column . '`';
            }, $columns))), 'Emulate pre-upgrade schema');
        }
    }
    $core->prefix = $testPrefix;
    wp_slimstat::$wpdb = $db;
    $now = wp_slimstat::now();
    $check(!Acquisition::checkSchema(), 'Old schema must not enable attribution');
    $check(Storage::insertRow(['resource' => '/legacy', 'dt' => $now], $testPrefix . 'slim_stats')->isStored(), 'Old-schema tracking survives');
    $migration = new AddAcquisitionColumns($db, $core);
    $check($migration->shouldRun(), 'Upgrade is discoverable');
    $check($migration->run(), 'Upgrade succeeds');
    $check(!$migration->shouldRun(), 'Upgrade converges');
    $check($migration->run(), 'Upgrade is idempotent');
    $check(false !== $db->query("ALTER TABLE `{$testPrefix}slim_stats` DROP COLUMN utm_id"), 'Emulate a partially restored older table');
    $suppressed = $db->suppress_errors(true);
    $stored = Storage::insertRow(['resource' => '/restored-schema', 'dt' => $now, 'utm_id' => 'test'], $testPrefix . 'slim_stats');
    $db->suppress_errors($suppressed);
    $check($stored->isStored(), 'Tracking survives an unexpectedly missing acquisition column');
    $check('0' === get_option(Acquisition::readinessKey()), 'Missing-column fallback disables repeated failed inserts');
    $check([] === AcquisitionReport::rows(['mode' => 'utm']), 'Raw CSV/email consumers stay safe while setup is pending');
    $check($migration->shouldRun() && $migration->run(), 'Partial upgrade resumes safely');
    $db->query("DELETE FROM `{$testPrefix}slim_stats` WHERE resource = '/restored-schema'");
    $check(null === $db->get_var("SELECT traffic_channel FROM `{$testPrefix}slim_stats` WHERE resource = '/legacy'"), 'Historical data stays explicitly unattributed');
    $copy = PurgeArchive::copyableColumns($db, $testPrefix, 'slim_stats');
    $check(!$copy['lost'] && !array_diff(Acquisition::COLUMNS, $copy['copy']), 'Archive preserves every attribution field');
    $check(Storage::insertRow(['resource' => '/unicode', 'dt' => $now, 'traffic_source' => '📚café', 'utm_source' => '📚café'], $testPrefix . 'slim_stats')->isStored(), 'Four-byte sources are stored on upgraded tables');
    $check('📚café' === $db->get_var("SELECT traffic_source FROM `{$testPrefix}slim_stats` WHERE resource = '/unicode'"), 'Normalized source keeps its UTF-8 bytes');
    $db->query("DELETE FROM `{$testPrefix}slim_stats` WHERE resource = '/unicode'");

    $insert = static function (string $query, string $ref, string $agent = '', int $dt = 0, string $author = 'alice') use ($testPrefix, $now, $check) {
        $params = Acquisition::parameters('/?' . $query);
        $stat = array_merge(['dt' => $dt ?: $now, 'resource' => '/campaign', 'author' => $author], array_intersect_key($params, array_flip(Acquisition::UTM_FIELDS)), Acquisition::classify($params, $ref, $agent, 0, 'https://example.org'));
        $check(Storage::insertRow($stat, $testPrefix . 'slim_stats')->isStored(), 'Attributed row is stored');
    };
    $insert('utm_source=A%26B&utm_medium=email&utm_campaign=Summer%2B2026&utm_id=%2520&utm_content=0', '');
    $insert('utm_source=A%26B&utm_medium=email&utm_campaign=Summer%2B2026&utm_id=%2520&utm_content=0', '');
    $insert('utm_source=A%26B&utm_medium=email&utm_campaign=summer%2B2026', '');
    $insert('', 'https://chatgpt.com');
    $insert('utm_source=A%26B&utm_medium=email&utm_campaign=Summer%2B2026', '', 'ChatGPT-User/1.0');
    $insert('', 'https://example.org/previous');
    $insert('utm_campaign=outside', '', '', $now - 10 * DAY_IN_SECONDS);
    $insert('utm_source=private&utm_medium=email', '', '', 0, 'bob');

    // A later charset upgrade must not merge differently cased campaign names.
    $check(false !== $db->query("ALTER TABLE `{$testPrefix}slim_stats` MODIFY resource VARCHAR(2048) CHARACTER SET utf8 DEFAULT NULL"), 'Emulate a legacy text column');
    $conversion = new SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4($db, $core);
    $check($conversion->run(), 'Charset conversion succeeds with campaign columns present');
    $check('varbinary' === $db->get_var("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$testPrefix}slim_stats' AND COLUMN_NAME = 'utm_campaign'"), 'Charset conversion preserves exact campaign storage');

    wp_slimstat::$settings['limit_results'] = 1000;
    wp_slimstat::$settings['rows_to_show'] = 2;
    wp_slimstat::$settings['async_load'] = 'off';
    wp_slimstat_db::init();
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $now - 3600, 'end' => $now + 1];
    wp_slimstat_db::$filters_normalized['columns'] = [];
    wp_slimstat_db::$filters_normalized['misc']['start_from'] = 0;
    $rows = AcquisitionReport::rows(['mode' => 'utm']);
    $check(3 === count($rows), 'Group all six tags, preserving case, excluding bots and out-of-range rows');
    $check(2 === (int) $rows[0]['counthits'], 'Repeated tagged pageviews aggregate');
    $check('A&B' === $rows[0]['utm_source'] && 'Summer+2026' === $rows[0]['utm_campaign'], 'Encoded delimiters survive the database');
    $check('%20' === $rows[0]['utm_id'] && '0' === $rows[0]['utm_content'], 'Literal percent escapes and zero-valued tags survive');
    $insert('utm_source=second&utm_medium=email&utm_campaign=Summer%2B2026', '');
    wp_slimstat::$settings['limit_results'] = 1;
    $summary = AcquisitionReport::rows(['mode' => 'utm', 'summary' => true]);
    $check('Summer+2026' === $summary[0]['utm_campaign'] && 3 === (int) $summary[0]['counthits'], 'Campaign summary aggregates distinct tag combinations before applying the result cap');
    $summary = AcquisitionReport::rows(['mode' => 'channels', 'summary' => true]);
    $check('email' === $summary[0]['traffic_channel'] && 5 === (int) $summary[0]['counthits'], 'Channel summary combines all sources before applying the result cap');
    $db->query("DELETE FROM `{$testPrefix}slim_stats` WHERE utm_source = 'second'");
    wp_slimstat::$settings['limit_results'] = 1000;
    $rows = AcquisitionReport::rows(['mode' => 'channels']);
    $check(8 === array_sum(array_column($rows, 'counthits')), 'Channels partition all matching pageviews including legacy and bots');
    $check(1 === (int) array_values(array_filter($rows, static function ($row) { return 'ai_fetcher' === $row['traffic_channel']; }))[0]['counthits'], 'AI fetches stay separate');
    $check(1 === array_sum(array_column(AcquisitionReport::rows(['mode' => 'utm', 'where' => $db->prepare('author = %s', 'bob')]), 'counthits')), 'Raw callback honors the per-author email scope');
    wp_slimstat_db::$filters_normalized['columns']['author'] = ['equals', 'alice'];
    $check(3 === array_sum(array_column(AcquisitionReport::rows(['mode' => 'utm']), 'counthits')), 'Author scope applies to campaign data');
    wp_slimstat_db::$filters_normalized['columns']['utm_campaign'] = ['equals', 'Summer+2026'];
    $check(2 === array_sum(array_column(AcquisitionReport::rows(['mode' => 'utm']), 'counthits')), 'Case-sensitive campaign filters');
    wp_slimstat_db::$filters_normalized['columns']['utm_campaign'] = ['matches', '^Summer'];
    $check(2 === array_sum(array_column(AcquisitionReport::rows(['mode' => 'utm']), 'counthits')), 'UTF-8 regex filters preserve campaign case');

    // The same group's contributions on either side of midnight must survive the top-N cap.
    wp_slimstat_db::$filters_normalized['columns'] = [];
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $now - 2 * DAY_IN_SECONDS, 'end' => $now + 1];
    $insert('utm_campaign=winner&utm_source=test&utm_medium=email', '', '', $now - DAY_IN_SECONDS);
    $insert('utm_campaign=winner&utm_source=test&utm_medium=email', '', '', $now - DAY_IN_SECONDS);
    $insert('utm_campaign=winner&utm_source=test&utm_medium=email', '');
    wp_slimstat::$settings['limit_results'] = 1;
    $rows = AcquisitionReport::rows(['mode' => 'utm']);
    $check('winner' === $rows[0]['utm_campaign'] && 3 === (int) $rows[0]['counthits'], 'Top-N uses one aggregation across midnight');
    ob_start();
    AcquisitionReport::render(['mode' => 'utm']);
    $html = ob_get_clean();
    $check(false !== strpos($html, '42.9%'), 'Share denominator includes all seven tagged views beyond the cap');
    $check(false !== strpos($html, 'result limit'), 'Result cap is disclosed');
    $check(false !== strpos($html, 'scope="col"') && false !== strpos($html, 'role="region"'), 'Semantic table and keyboard scroll region');
    wp_slimstat::$settings['async_load'] = 'on';
    ob_start();
    AcquisitionReport::render(['mode' => 'utm', 'is_widget' => true]);
    $widget = ob_get_clean();
    $check(false !== strpos($widget, 'winner') && wp_style_is('wp-slimstat-acquisition', 'enqueued'), 'Widgets render synchronously with their own styles');
    $savedFilters = wp_slimstat_db::$filters_normalized;
    $shortcode = do_shortcode('[slimstat f="widget" w="slim_p3_04"]utm_campaign equals winner&&&interval equals -2[/slimstat]');
    $check(false !== strpos($shortcode, 'winner') && false !== strpos($shortcode, '3 pageviews'), 'Public shortcode uses the same campaign and date filters');
    wp_slimstat_db::$filters_normalized = $savedFilters;

    // Bounded performance fixture, private to these disposable tables: 98,304 rows.
    wp_slimstat::$settings['limit_results'] = 1000;
    $db->query("TRUNCATE TABLE `{$testPrefix}slim_stats`");
    for ($day = 0; $day < 96; ++$day) {
        $insert('utm_source=benchmark&utm_medium=email&utm_campaign=day' . $day, '', '', $now - $day * DAY_IN_SECONDS);
    }
    for ($i = 0; $i < 10; ++$i) {
        $names = 'dt, resource, traffic_channel, traffic_source, utm_source, utm_medium, utm_campaign';
        $check(false !== $db->query("INSERT INTO `{$testPrefix}slim_stats` ({$names}) SELECT {$names} FROM `{$testPrefix}slim_stats`"), 'Grow performance fixture');
    }
    $timings = [];
    $summaryTimings = [];
    foreach ([1, 30, 96] as $days) {
        wp_slimstat_db::$filters_normalized['utime'] = ['start' => $now - ($days - 1) * DAY_IN_SECONDS, 'end' => $now + 1];
        $start = microtime(true);
        $rows = AcquisitionReport::rows(['mode' => 'utm']);
        $timings[$days] = round((microtime(true) - $start) * 1000, 2);
        $check($days * 1024 === array_sum(array_column($rows, 'counthits')), 'Performance fixture totals remain exact');
        if (1 === $days) {
            $sql = $db->last_query;
            $plan = $db->get_row('EXPLAIN ' . $sql, ARRAY_A);
            $check('range' === $plan['type'], 'Selective report uses an indexed date range');
        }
        $start = microtime(true);
        $summary = AcquisitionReport::rows(['mode' => 'utm', 'summary' => true]);
        $summaryTimings[$days] = round((microtime(true) - $start) * 1000, 2);
        $check($days * 1024 === array_sum(array_column($summary, 'counthits')), 'Summary totals match the performance fixture');
        if (1 === $days) {
            $summaryPlan = $db->get_row('EXPLAIN ' . $db->last_query, ARRAY_A);
            $check('range' === $summaryPlan['type'], 'Selective summary uses an indexed date range');
        }
    }
    echo json_encode(['checks' => $checks, 'fixture_rows' => 98304, 'query_ms_by_days' => $timings, 'summary_query_ms_by_days' => $summaryTimings, 'selective_explain' => $plan], JSON_PRETTY_PRINT) . "\n";
} finally {
    delete_option(Acquisition::readinessKey());
    $degradations = get_option(wp_slimstat::DEGRADATION_OPTION, []);
    unset($degradations['tracker write dropped columns absent from ' . $testPrefix . 'slim_stats']);
    update_option(wp_slimstat::DEGRADATION_OPTION, $degradations, false);
    $core->prefix = $prefix;
    wp_slimstat::$wpdb = $originalDb;
    wp_slimstat::$settings = $settings;
    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
        $db->query("DROP TABLE IF EXISTS `{$testPrefix}{$suffix}`");
    }
    $db->close();
}
