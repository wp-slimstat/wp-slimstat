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
    $seeder = new SlimStat_Bench_Seeder($db);

    if (($args[0] ?? '') === 'purge') {
        printf("purged %s seeded rows\n", number_format($seeder->purge()));
        return;
    }

    $rows = (int) ($args[0] ?? 150000);
    $days = (int) ($args[1] ?? 365);

    $seeder->seedTo($rows, $days, $log);

    $table = $db->prefix . 'slim_stats';
    printf(
        "\n%s now holds %s rows across %s visits\n",
        $table,
        number_format((int) $db->get_var("SELECT COUNT(*) FROM `{$table}`")),
        number_format((int) $db->get_var("SELECT COUNT(DISTINCT visit_id) FROM `{$table}`"))
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
