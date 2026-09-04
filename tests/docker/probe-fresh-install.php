<?php
// A FRESH install must be born with ua_id and must NOT be asked to migrate.
//
// This is C39 and C41 in one probe. Before ua_id was declared in the manifest, a new site got
// slim_stats without it, then the migration screen offered a fact-table rebuild of an empty
// table — the exact pair of defects F2 closed and this seam nearly reopened.
if (!defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "runs under WP-CLI\n"); exit(1); }

require_once WP_PLUGIN_DIR . '/wp-slimstat/src/Migration/Migrations/AddUserAgentDimension.php';
global $wpdb;
$stats = $wpdb->prefix . 'slim_stats';
$fail = [];

$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$stats}`");
printf("  [%s] this is a FRESH install: %d rows\n", 0 === $rows ? 'PASS' : 'FAIL', $rows);
if (0 !== $rows) { echo "\nVERDICT: ABORTED — not a fresh install, the probe would prove nothing\n"; exit(1); }

$has = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='{$stats}' AND COLUMN_NAME='ua_id'"
);
if (1 !== $has) { $fail[] = 'a fresh install did NOT get ua_id from the manifest (C39: fresh and upgraded diverge)'; }

// Same assertion for D68's identity column, on BOTH tables it is declared for — and the
// REQUIRED migration must have nothing to do here, or every fresh install greets its admin
// with a demand to migrate an empty table (C41's shape, on the required list this time).
require_once WP_PLUGIN_DIR . '/wp-slimstat/src/Migration/Migrations/AddVisitIdentity.php';
foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
    $t = $wpdb->prefix . $suffix;
    $has_vid = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='{$t}' AND COLUMN_NAME='vid_hash'"
    );
    if (1 !== $has_vid) { $fail[] = "a fresh install did NOT get vid_hash on {$t} (C39/D68)"; }
}
$vid_m = new SlimStat\Migration\Migrations\AddVisitIdentity($wpdb, $wpdb);
if ($vid_m->shouldRun()) {
    $fail[] = 'AddVisitIdentity wants to run on a FRESH install (C41: a required migration demanding work on tables born complete)';
}

$m = new SlimStat\Migration\Migrations\AddUserAgentDimension($wpdb, $wpdb);
if ($m->shouldRun()) {
    $fail[] = 'the migration wants to run on an EMPTY fresh install (C41: offering a rebuild of a table with no rows)';
}

echo "\n";
if ($fail) {
    fwrite(STDERR, "FAIL: fresh-install schema (" . count($fail) . " problem(s))\n");
    foreach ($fail as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "PASS: fresh install is born with ua_id and is not asked to migrate\n";
