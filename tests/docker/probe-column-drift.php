<?php
// Does the column read model see real drift on a real install? (F4)
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// WHAT IS BEING TESTED. `Schema::ensure()` reconciles tables and indexes and never columns, so a
// column reaches a FRESH install through CREATE TABLE and an UPGRADED one only if somebody
// hand-wrote a version-gated ALTER. Forget the second and the two diverge — C39, and the shape
// `ua_id` shipped in for one commit.
//
// Until this change that divergence was reportable by NOTHING. Two bespoke probes and a
// write-path column-dropper absorbed the symptom; no code path could say "this install's schema
// does not match the manifest". So the question here is not "does ensure() repair it" — it
// deliberately does not, because an ALTER on a 443k-row fact table during admin_init is the
// hazard S7 removed. The question is whether the drift is now VISIBLE.
//
// THE MEASUREMENT IS A BEFORE/AFTER ON THE DATABASE, not on the code: take a healthy install,
// introduce each drift class by hand, and check the read model names it. A probe that only
// looked at a healthy install would report `[]` and prove nothing at all — which is exactly what
// two reports in the answers harness did for weeks (PITFALLS 38).

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

require_once WP_PLUGIN_DIR . '/wp-slimstat/src/Schema/Schema.php';

use SlimStat\Schema\Schema;

global $wpdb;

// EVERY ALTER BELOW LANDS ON A SCRATCH COPY, NEVER ON slim_stats.
//
// The first version drifted the live table and restored it afterwards. Two things were wrong
// with that, and only the second is obvious:
//
//   - DROP COLUMN followed by ADD COLUMN destroys every row's value. columnState() compares
//     presence and type, so the final "matches the baseline exactly" check was TRUE over a
//     wiped ADR-9 Layer 1 backfill. The probe would have reported success having silently
//     destroyed the thing it was measuring around.
//   - Nothing restored the table if a restore statement itself failed, and this is the only
//     probe in tests/docker that issues DDL at all — the others are read-only. The workspace's
//     own standing note is that the local install holds the ONLY copy of the parity dataset.
//
// A copy removes the whole class rather than guarding it. `columnState()` takes a prefix, so
// pointing it at `wp_ssdrift_` reads a table with the same shape and no data worth keeping.
$prefix        = $wpdb->prefix;
$stats         = $prefix . 'slim_stats';
$scratchPrefix = $prefix . 'ssdrift_';
$scratch       = $scratchPrefix . 'slim_stats';
$fail          = 0;

$state = static function () use ($wpdb, $scratchPrefix) {
    return Schema::columnState($wpdb, 'slim_stats', $scratchPrefix);
};

// Dropped on every exit path, including a fatal — the scratch table must not outlive the probe
// and be picked up as a stray `slim_` table by the inventory gates.
register_shutdown_function(static function () use ($wpdb, $scratch) {
    $wpdb->query("DROP TABLE IF EXISTS `{$scratch}`");
});

echo "CONTROLS\n";

$exists = '' !== (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats));
if (!$exists) {
    echo "  [FAIL] {$stats} does not exist — nothing below would be measuring a schema\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] {$stats} exists\n";

// LIKE copies columns and indexes and no rows, which is exactly what a shape probe needs.
$wpdb->query("DROP TABLE IF EXISTS `{$scratch}`");
if (false === $wpdb->query("CREATE TABLE `{$scratch}` LIKE `{$stats}`")) {
    printf("  [FAIL] could not create the scratch copy: %s\n", (string) $wpdb->last_error);
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] scratch copy created — no statement below touches the real table\n";

// The install must START clean, or "drift detected" below could be drift that was already there
// and the introduce-it step would be measuring nothing.
$before = $state();
printf(
    "  baseline: %d present, %d missing, %d narrow, %d undeclared\n",
    count($before['present']),
    count($before['missing']),
    count($before['narrow']),
    count($before['undeclared'])
);

if ([] !== $before['missing'] || [] !== $before['narrow']) {
    printf(
        "  [FAIL] the install is already drifted (missing: %s / narrow: %s) — every result below\n"
            . "         would be indistinguishable from that pre-existing state\n",
        implode(', ', $before['missing']) ?: 'none',
        implode(', ', array_keys($before['narrow'])) ?: 'none'
    );
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the baseline is clean, so any drift below is drift THIS probe introduced\n";

if (count($before['present']) < 20) {
    echo "  [FAIL] fewer than 20 columns seen — the probe is not reading the fact table\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the read model sees the whole declared column set\n";

// ── 1. A MISSING column — the ua_id / C39 shape ────────────────────────────
echo "\n1. MISSING (a column the manifest declares and the table does not have)\n";

$wpdb->query("ALTER TABLE `{$scratch}` DROP COLUMN ua_id");
$after = $state();

if (in_array('ua_id', $after['missing'], true)) {
    echo "  [PASS] ua_id reported missing\n";
} else {
    echo "  [FAIL] ua_id dropped from the table and NOT reported missing\n";
    $fail = 1;
}

$wpdb->query(Schema::addColumnSql('slim_stats', 'ua_id', $scratchPrefix));

// ── 2. A NARROW column — the email 255-vs-256 shape ────────────────────────
echo "\n2. NARROW (a VARCHAR shorter on the table than in the manifest)\n";

$wpdb->query("ALTER TABLE `{$scratch}` MODIFY email VARCHAR(255) DEFAULT NULL");
$after = $state();

if (isset($after['narrow']['email'])) {
    printf("  [PASS] email reported narrow: %s\n", $after['narrow']['email']);
} else {
    echo "  [FAIL] email narrowed to 255 against a declared 256 and NOT reported\n";
    $fail = 1;
}

$wpdb->query("ALTER TABLE `{$scratch}` MODIFY email VARCHAR(256) DEFAULT NULL");

// ── 3. A WIDER column is NOT drift ─────────────────────────────────────────
echo "\n3. WIDER (someone else's ALTER — not a hazard, must not be reported)\n";

$wpdb->query("ALTER TABLE `{$scratch}` MODIFY email VARCHAR(512) DEFAULT NULL");
$after = $state();

if (isset($after['narrow']['email'])) {
    echo "  [FAIL] a WIDER column was reported as drift — narrower truncates, wider does not\n";
    $fail = 1;
} else {
    echo "  [PASS] a wider column is not reported\n";
}

$wpdb->query("ALTER TABLE `{$scratch}` MODIFY email VARCHAR(256) DEFAULT NULL");

// ── 4. INTEGER WIDTHS are never drift — the 8.0.19 trap ────────────────────
echo "\n4. INTEGER (display widths differ by SERVER VERSION and must never be compared)\n";

printf("  server reports version %s\n", (string) $wpdb->get_var('SELECT VERSION()'));

$dt_type = (string) $wpdb->get_var(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$scratch}' AND COLUMN_NAME = 'dt'"
);
printf("  dt is declared 'INT(10) UNSIGNED' and reported as '%s'\n", $dt_type);

$after = $state();
$ints  = array_intersect(
    array_keys($after['narrow']),
    ['dt', 'dt_out', 'content_id', 'screen_width', 'screen_height', 'visit_id', 'id']
);

if ([] !== $ints) {
    printf("  [FAIL] integer column(s) reported as drift: %s. MySQL 8.0.19 removed the display\n"
        . "         width, so this comparison is right on one server and wrong on the other\n", implode(', ', $ints));
    $fail = 1;
} else {
    echo "  [PASS] no integer column reported, on this server version\n";
}

// ── 5. And the scratch copy is back where it started ───────────────────────
//
// Still asserted even though nothing here can reach the real table: a restore that silently
// failed would leave sections 2-4 measuring a shape nobody intended, and each of those results
// would read as a finding about the read model rather than about the harness.
echo "\n5. RESTORED (each step must undo itself, or the next one measures the wrong shape)\n";

$final = $state();

if ($final == $before) {
    echo "  [PASS] the column state matches the baseline exactly\n";
} else {
    printf(
        "  [FAIL] a restore did not restore — missing: %s / narrow: %s\n",
        implode(', ', $final['missing']) ?: 'none',
        implode(', ', array_keys($final['narrow'])) ?: 'none'
    );
    $fail = 1;
}

echo "\nVERDICT: " . (0 === $fail ? 'MEASURED — drift is visible' : 'FAILED') . "\n";
exit($fail);
