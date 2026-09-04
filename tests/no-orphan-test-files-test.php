<?php
/**
 * Every tests/*.php is invoked by something — a composer script, a workflow, or phpunit.
 *
 * Two gates sat in tests/ for months invoked by nothing: `browscap-fileinfo-preflight-test.php`
 * (a fileinfo-missing HTTP 500) and `consent-filter-context-test.php` (a DNT bypass in the
 * programmatic tracking path). Both test real shipped fixes; neither ran anywhere. A test file
 * that nothing runs carries the reassurance of coverage with none of the cost — which is worse
 * than the file not existing, because the next reader counts it (DoD 20).
 *
 * WHAT IS PINNED: for every `tests/*-test.php` (the runnable gates; `tests/lib`, `tests/Unit`
 * and `tests/verify` have their own runners), its path appears in a composer
 * `scripts` value or in a workflow `run:` block. A file wired only through a comment does not
 * count — comments are stripped before matching.
 *
 * Run: php tests/no-orphan-test-files-test.php
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

// One pass over `scripts`, strings and arrays at any depth. UNESCAPED_SLASHES is load-bearing:
// the default encoding writes `tests\\/x`, which no `tests/x` needle would find.
$composer = json_decode((string) file_get_contents($plugin_root . '/composer.json'), true);
$invoked  = (string) json_encode($composer['scripts'] ?? [], JSON_UNESCAPED_SLASHES);

foreach (glob($plugin_root . '/.github/workflows/*.yml') ?: [] as $workflow) {
    $invoked .= "\n" . slimstat_yaml_strip_comments((string) file_get_contents($workflow));
}

$scripts = glob($plugin_root . '/tests/*-test.php') ?: [];

foreach ($scripts as $path) {
    $rel = 'tests/' . basename($path);
    if (false === strpos($invoked, $rel)) {
        $failures[] = sprintf('%s is invoked by no composer script and no workflow. Wire it into a '
            . 'script that test:source-level or test:all reaches, or delete it — a test nothing '
            . 'runs is coverage that does not exist', $rel);
    }
}

// VACUITY FLOOR, DERIVED. Every `tests/X-test.php` path NAMED in the corpus must exist on disk:
// a glob that stopped matching leaves the named set larger than the found set. A remembered
// count ("at least 100") is the kind of number this suite keeps catching as stale.
preg_match_all('#tests/[a-z0-9-]+-test\.php#', $invoked, $named);
$missing = array_diff(
    array_unique($named[0]),
    array_map(static function (string $p): string { return 'tests/' . basename($p); }, $scripts)
);
if ($missing) {
    $failures[] = sprintf('%d path(s) are invoked but not found by the glob (%s) — the glob has '
        . 'stopped matching, so the orphan check above ran over a set smaller than the suite',
        count($missing), implode(', ', array_slice($missing, 0, 3)));
}

if ($failures) {
    fwrite(STDERR, 'FAIL: orphan test files (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: all %d tests/*-test.php scripts are invoked by a composer script or a workflow\n", count($scripts));
