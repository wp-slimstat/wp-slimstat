<?php
/**
 * The shipped version is a bare X.Y.Z — because Pro compares against one.
 *
 * ── THE HAZARD ──────────────────────────────────────────────────────────────────────────────
 *
 * Pro gates two headline features on the free version: Network View's combined totals
 * (`NETWORK_MERGE_MIN_FREE = '6.0.0'`) and author-scoped e-mail reports
 * (`AUTHOR_SCOPED_MIN_FREE = '6.0.0'`), each via `version_compare(SLIMSTAT_ANALYTICS_VERSION,
 * '6.0.0', '<')`. PHP's version_compare sorts `6.0.0-beta1` and `6.0.0-rc1` BELOW `6.0.0`. So a
 * beta build carrying a suffix would run Pro 3.0.0 with both features silently switched off — no
 * error, no notice, just less data — on every cohort install. Pro's `NetworkViewAddon.php`
 * records the hazard in a comment; a comment is where it stayed. Rule 7 of a markdown release
 * command was the only guard.
 *
 * ── WHAT IS PINNED ──────────────────────────────────────────────────────────────────────────
 *
 * The three places free states its version — the plugin header, the
 * `SLIMSTAT_ANALYTICS_VERSION` define, and readme.txt's `Stable tag` — must each be a bare
 * `X.Y.Z` and must agree. Pre-release builds are identified some other way (a commit hash in the
 * ZIP name, which `/zip-wp-slimstat` already does), never in the version string Pro compares.
 *
 * Pro carries the twin: its floor constants must themselves be bare, and the comment recording
 * this hazard must survive.
 *
 * Run: php tests/version-suffix-contract-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

// The header is a COMMENT, so `Version:` can only be read raw; the define() is code and is
// read from the blanked copy so a commented-out define cannot satisfy it.
$bootstrap_raw = (string) file_get_contents($plugin_root . '/wp-slimstat.php');
$bootstrap     = slimstat_blank_comments($bootstrap_raw);
$readme        = (string) file_get_contents($plugin_root . '/readme.txt');

$sites = [];

if (null !== ($v = slimstat_header_field($bootstrap_raw, 'Version'))) {
    $sites['plugin header `Version:`'] = $v;
} else {
    $failures[] = 'wp-slimstat.php has no `Version:` header line — WordPress reads the version '
        . 'from it, and this gate cannot check what it cannot find';
}

if (preg_match("/define\(\s*'SLIMSTAT_ANALYTICS_VERSION'\s*,\s*'([^']*)'\s*\)/", $bootstrap, $m)) {
    $sites['`SLIMSTAT_ANALYTICS_VERSION`'] = $m[1];
} else {
    $failures[] = 'wp-slimstat.php no longer defines SLIMSTAT_ANALYTICS_VERSION as a literal — '
        . 'that constant is the one Pro compares against, so it is the one that must be bare';
}

if (null !== ($v = slimstat_header_field($readme, 'Stable tag'))) {
    $sites['readme.txt `Stable tag`'] = $v;
} else {
    $failures[] = 'readme.txt has no `Stable tag:` line';
}

// VACUITY FLOOR: three sites exist. Fewer means a regex above stopped matching.
if (count($sites) < 3) {
    $failures[] = sprintf('found only %d of the 3 version declarations; the missing one(s) are '
        . 'not being checked', count($sites));
}

foreach ($sites as $where => $version) {
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $failures[] = sprintf(
            '%s is `%s`, not a bare X.Y.Z. PHP\'s version_compare() sorts any suffixed form '
                . 'BELOW the bare one, and Pro gates Network View merging and author-scoped '
                . 'e-mail on `version_compare(SLIMSTAT_ANALYTICS_VERSION, \'6.0.0\', \'<\')` — so '
                . 'a suffixed build runs Pro with both switched off, silently, on every install. '
                . 'Identify pre-release builds in the ZIP name, never in the version string',
            $where,
            $version
        );
    }
}

if (count(array_unique(array_values($sites))) > 1) {
    $parts = [];
    foreach ($sites as $where => $version) {
        $parts[] = $where . ' = ' . $version;
    }
    $failures[] = 'the three version declarations disagree: ' . implode('; ', $parts)
        . '. Pro compares against the constant; WordPress reads the header; wordpress.org serves '
        . 'the stable tag. They must be one number';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: version suffix contract (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all 3 version declarations are the bare `' . reset($sites) . "`, which is the form "
    . "Pro's floors compare against\n";
