<?php
/**
 * Load the golden fixture into a live multisite install, via WP-CLI.
 *
 *   wp eval-file tests/fixtures/golden/load.php [--url=<main-site-url>]
 *
 * Writes each blog's rows to that blog's own slim_stats table, using switch_to_blog() so the
 * prefix comes from WordPress rather than from string concatenation here — the per-blog table
 * name is precisely what D10 and F9 are about, and hardcoding it would make this loader agree
 * with a bug rather than expose it.
 *
 * REFUSES to run against a database holding real data. This is destructive by design (it
 * TRUNCATEs before loading so the expected counts hold), and the reference dataset is the only
 * copy of the parity corpus. The guard is the same shape as the E2E suite's ALLOW_LIVE_DB
 * refusal, which is load-bearing for the same reason.
 */

// NO `declare(strict_types=1)` here, deliberately. WP-CLI's own `eval-file` command loads a
// file by evaluating it (wp-cli/eval-command, EvalFile_Command.php) — nothing in this repo
// does — and inside that wrapper a declare() is no longer the first statement of the script,
// which PHP rejects with a fatal. The required files below keep theirs: they are separate
// compilation units, loaded by require, and unaffected.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This loader runs under WP-CLI: wp eval-file tests/fixtures/golden/load.php\n");
    exit(1);
}

if (!is_multisite()) {
    WP_CLI::error('the golden fixture describes a network; this install is single-site');
}

require_once __DIR__ . '/expand.php';
$spec = require __DIR__ . '/spec.php';

$allow = getenv('SLIMSTAT_GOLDEN_ALLOW_DESTRUCTIVE');
if ('1' !== $allow) {
    WP_CLI::error(
        "refusing to truncate analytics tables.\n"
            . "This loader TRUNCATEs slim_stats on every blog it touches, because the fixture's\n"
            . "expected counts are absolute, not relative. Set SLIMSTAT_GOLDEN_ALLOW_DESTRUCTIVE=1\n"
            . 'only on a disposable install.'
    );
}

$rows_by_blog = [];
foreach (slimstat_golden_rows($spec) as $row) {
    $rows_by_blog[$row['blog_id']][] = $row;
}

$sites = get_sites(['number' => 0, 'archived' => null, 'deleted' => null, 'spam' => null]);
$known = [];
foreach ($sites as $site) {
    $known[(int) $site->blog_id] = $site;
}

$missing = array_diff(array_keys($rows_by_blog), array_keys($known));
if ($missing !== []) {
    WP_CLI::error(sprintf(
        'the network has no blog(s) %s. Provision it with tests/docker/run-topology.sh first — '
            . 'loading a partial fixture would give expected counts that cannot be met.',
        implode(', ', $missing)
    ));
}

global $wpdb;
$loaded = 0;

foreach ($rows_by_blog as $blog_id => $rows) {
    switch_to_blog($blog_id);

    // The prefix comes from WordPress, not from concatenation here. Which table a blog's rows
    // belong in is the question D10/F9 exist to answer; a loader that decided it independently
    // would happily agree with a broken implementation.
    $table = $wpdb->prefix . 'slim_stats';

    $wpdb->query("TRUNCATE TABLE `{$table}`");

    foreach ($rows as $row) {
        $wpdb->insert(
            $table,
            [
                'ip'           => $row['ip'],
                'resource'     => $row['resource'],
                'visit_id'     => $row['visit_id'],
                'dt'           => $row['dt'],
                'browser_type' => $row['browser_type'],
                'browser'      => 'Chrome',
                'platform'     => 'Windows',
                'country'      => 'us',
                'language'     => 'en-US',
            ],
            ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
        $loaded++;
    }

    $actual = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    if ($actual !== count($rows)) {
        restore_current_blog();
        WP_CLI::error(sprintf(
            'blog %d: inserted %d rows but the table holds %d. Every expected figure is absolute, '
                . 'so a partial load produces confident wrong numbers rather than an error.',
            $blog_id,
            count($rows),
            $actual
        ));
    }

    WP_CLI::log(sprintf('blog %d (%s): %d rows into %s', $blog_id, $known[$blog_id]->domain . $known[$blog_id]->path, $actual, $table));
    restore_current_blog();
}

WP_CLI::success(sprintf(
    '%d rows loaded across %d blogs. Counted total should read %d; anything reading %d is '
        . 'including the archived blog.',
    $loaded,
    count($rows_by_blog),
    $spec['expected']['pageviews'],
    $spec['expected']['pageviews_including_archived']
));
