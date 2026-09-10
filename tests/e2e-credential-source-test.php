<?php
/**
 * Source-level: E2E credentials come from helpers/env.ts, never from a literal in a spec.
 *
 * H-LOGIN, the second-largest family of the 132 in the uncapped census (Run 65): fifteen tests
 * across three files, every one of them a `waitForURL('**\/wp-admin/**')` that timed out after a
 * login submit. Not fifteen product bugs and not a slow runner — the specs typed `parhumm` /
 * `testpass123`, or `gerlando`, accounts that exist on one developer's LocalWP install and on no
 * CI lane. WordPress answered the wrong password with the login form again, the wait for a
 * wp-admin URL ran to its 45 s ceiling, and no assertion in any of those tests was ever reached.
 *
 * The same literal in a SETTING is the same defect wearing different clothes:
 * `setSlimstatSetting('ignore_users', 'parhumm')` excludes nobody on a lane whose admin is
 * `admin`, so the exclusion specs failed on their subject while the harness was what was wrong.
 *
 * WHAT IS PINNED, two ways round:
 *   1. The credential literals env.ts declares as ITS fallbacks appear nowhere else under
 *      tests/e2e/. Derived from env.ts, so renaming the developer account cannot go stale.
 *   2. No spec fills #user_login or #user_pass with a string literal at all — the general form,
 *      which also catches a NEW hardcoded account that env.ts has never heard of.
 *
 * Run: php tests/e2e-credential-source-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

$plugin_root = dirname(__DIR__);
$e2e_dir     = $plugin_root . '/tests/e2e';
$env_rel     = 'tests/e2e/helpers/env.ts';
$failures    = [];

$env_source = (string) file_get_contents($plugin_root . '/' . $env_rel);

// ── The literals env.ts owns ────────────────────────────────────────────────────────────
// Only the credential constants: BASE_URL's fallback is a URL every spec may name.
preg_match_all(
    '/export const (ADMIN|AUTHOR)_(USER|PASS)\s*=\s*process\.env\.\w+\s*\?\?\s*([\'"])(.+?)\3/',
    $env_source,
    $m,
    PREG_SET_ORDER
);

$owned = [];
foreach ($m as $hit) {
    $owned[$hit[4]] = true;
}
$owned = array_keys($owned);

// Positive control. A scan that derives its own denominator reports "all clean" loudest at the
// moment it has stopped reading anything — four recorded instances in this programme.
if (count($owned) < 3) {
    $failures[] = sprintf('only %d credential fallback(s) parsed out of %s; four constants are '
        . 'declared there. The scan is broken, not the tree, and an empty needle list makes '
        . 'every file below pass vacuously', count($owned), $env_rel);
}

// ── Walk every .ts under tests/e2e/ ─────────────────────────────────────────────────────
$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($e2e_dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ('ts' === strtolower($file->getExtension())) {
        $files[] = $file->getPathname();
    }
}
sort($files);

if (count($files) < 100) {
    $failures[] = sprintf('found only %d .ts file(s) under tests/e2e/; the suite has well over '
        . 'a hundred, so this walk is not reading the tree it claims to', count($files));
}

foreach ($files as $path) {
    $rel = ltrim(str_replace($plugin_root, '', $path), '/');
    if ($rel === $env_rel) {
        continue; // the one file allowed to spell them
    }

    $source = (string) file_get_contents($path);

    foreach ($owned as $literal) {
        foreach (["'" . $literal . "'", '"' . $literal . '"'] as $quoted) {
            if (false !== strpos($source, $quoted)) {
                $failures[] = sprintf('%s spells the credential %s that %s owns. Import the '
                    . 'constant instead: a literal here is an account that exists on one '
                    . 'machine, and on a lane it fails as a 45 s navigation timeout rather '
                    . 'than as a wrong password', $rel, $quoted, $env_rel);
            }
        }
    }

    if (preg_match_all('/fill\(\s*[\'"]#user_(?:login|pass)[\'"]\s*,\s*([\'"])(.*?)\1/', $source, $lit, PREG_SET_ORDER)) {
        foreach ($lit as $hit) {
            $failures[] = sprintf('%s fills a login field with the string literal %s%s%s. The '
                . 'credential must come from a constant — from env.ts, or from one the spec '
                . 'provisions itself with ensureWpUser()', $rel, $hit[1], $hit[2], $hit[1]);
        }
    }
}

// The standard CI job starts from a fresh wp-env site. Its admin exists by default, but the
// author account does not; global setup authenticates both before any spec can run. Pin the
// provisioning contract so a missing author is reported here instead of as a 60-second browser
// navigation timeout in every matrix lane.
$ci_source = (string) file_get_contents($plugin_root . '/.github/workflows/ci.yml');
if (!preg_match('/^  standard:\s*$.*?(?=^  [a-zA-Z0-9_-]+:\s*$|\z)/ms', $ci_source, $ci_match)) {
    $failures[] = 'cannot find the Tier 2 standard job in .github/workflows/ci.yml';
} else {
    $standard_job = $ci_match[0];
    $required_ci_fragments = [
        'WP_AUTHOR_USER:',
        'WP_AUTHOR_PASS:',
        'wp user create "$WP_AUTHOR_USER"',
        '--role=author',
        '--user_pass="$WP_AUTHOR_PASS"',
    ];
    foreach ($required_ci_fragments as $fragment) {
        if (false === strpos($standard_job, $fragment)) {
            $failures[] = "Tier 2 does not provision the configured author account: missing `{$fragment}`";
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: e2e credential source (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: %d credential literal(s) from %s appear in none of the %d other .ts file(s) under "
        . "tests/e2e/, and no spec fills a login field with a literal\n",
    count($owned),
    $env_rel,
    count($files) - 1
);
