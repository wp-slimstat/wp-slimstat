<?php
// Entry point for the benchmark seeder.
//
//   wp eval-file tests/bench/lib/seed.php <rows> [days]
//   wp eval-file tests/bench/lib/seed.php purge
//
// Tiers used by the benchmark matrix:
//   150000    smoke
//   1500000   primary — ~20x the reference site at the 420-day retention default
//   5000000   headroom
//   10000000  stress
//
// No declare(strict_types=1): `wp eval-file` eval()s this file, where a
// declare() is not the first statement of the script and fatals.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

require_once __DIR__ . '/seeder.php';

$db = (class_exists('wp_slimstat') && wp_slimstat::$wpdb instanceof wpdb)
    ? wp_slimstat::$wpdb
    : $GLOBALS['wpdb'];

$log = static function (string $line): void {
    echo $line . "\n";
};

try {
    // Third argument: a profile (or overlay) filename inside tests/bench/. Seam I8 passes
    // seed-profile-i8.json, which extends the measured profile and raises resource/referer
    // cardinality past A4's MEMORY temp-table cliff.
    $profile = isset($args[2]) && $args[2] !== ''
        ? dirname(__DIR__) . '/' . basename((string) $args[2])
        : null;

    $seeder = new SlimStat_Bench_Seeder($db, $profile);

    if (($args[0] ?? '') === 'purge') {
        printf("purged %s seeded rows\n", number_format($seeder->purge()));
        return;
    }

    $rows = (int) ($args[0] ?? 150000);
    $days = (int) ($args[1] ?? 365);

    $seeder->seedTo($rows, $days, $log);

    $table = $db->prefix . 'slim_stats';
    $now   = time();

    // The SHAPE, not just the size. I8 exists because the previous fixture held 443,535 rows
    // and still could not tell a 30-day report from a 90-day one, so a row count on its own
    // says nothing about whether the fixture can support a conclusion.
    printf(
        "\n%s now holds %s rows across %s visits\n"
            . "distinct resources: %s   distinct referers: %s\n"
            . "rows last 30d: %s   last 90d: %s   all: %s\n",
        $table,
        number_format((int) $db->get_var("SELECT COUNT(*) FROM `{$table}`")),
        number_format((int) $db->get_var("SELECT COUNT(DISTINCT visit_id) FROM `{$table}`")),
        number_format((int) $db->get_var("SELECT COUNT(DISTINCT resource) FROM `{$table}`")),
        number_format((int) $db->get_var("SELECT COUNT(DISTINCT referer) FROM `{$table}`")),
        number_format((int) $db->get_var("SELECT COUNT(*) FROM `{$table}` WHERE dt >= " . ($now - 30 * 86400))),
        number_format((int) $db->get_var("SELECT COUNT(*) FROM `{$table}` WHERE dt >= " . ($now - 90 * 86400))),
        number_format((int) $db->get_var("SELECT COUNT(*) FROM `{$table}`"))
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
