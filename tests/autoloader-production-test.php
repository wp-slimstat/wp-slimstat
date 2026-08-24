<?php
/**
 * The COMMITTED autoloader must be the production one.
 *
 * ── The defect this exists to catch ──────────────────────────────────────────────────────────
 *
 * `wp-slimstat` ships a classmap-authoritative autoloader, and `wp-slimstat.php` requires
 * `vendor/autoload.php` at plugin boot. Running `composer dump-autoload` — which anyone must do
 * to run PHPUnit locally, and which Composer's own `post-install-cmd`/`post-update-cmd` run
 * without `--no-dev` — replaces it with the DEV autoloader. Measured on this tree:
 *
 *     committed      $files blocks 0   SlimStat entries 551   dev-tool entries 0
 *     after dump     $files blocks 1   SlimStat entries   0   dev-tool entries 961
 *
 * Two independent ways that breaks a shipped plugin:
 *
 *   1. The `$files` block eagerly `require`s (not `require_once`, no `file_exists` guard)
 *      Mockery's helpers, PHPUnit's assertion functions, Brain Monkey's api.php and more.
 *      `.distignore` strips `/vendor/mockery`, `/vendor/phpunit`, `/vendor/brain` and
 *      `/vendor/myclabs` from the release ZIP, so those paths do not exist on a user's site:
 *      **fatal on activation**. That is the shape of bug #325.
 *   2. Every `SlimStat\` class falls out of the classmap. Under an authoritative classmap a
 *      missing entry is not a slow path, it is a class that cannot be found at all.
 *
 * `composer run build:autoload` (`dump-autoload --no-dev -o`) is the correct form. This gate is
 * what makes forgetting it visible instead of shipped.
 *
 * ── Why it reads the committed blob ──────────────────────────────────────────────────────────
 *
 * The working tree legitimately holds the dev autoloader while someone is running PHPUnit — that
 * is the supported workflow, and failing on it would train people to ignore this gate. What must
 * never happen is COMMITTING it. So the subject is `git show HEAD:`, the same choice
 * `tests/classmap-completeness-test.php` makes and for the same reason: the bytes that ship are
 * the committed ones, and a gate that reads any other bytes is green about the wrong file
 * (PITFALLS 19 — check what the gate reads, not only what it asserts).
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$failures    = [];

/** The committed bytes of a path, or null when git cannot produce them. */
$committed = static function (string $relative) use ($plugin_root): ?string {
    $command = sprintf(
        'git -C %s show %s 2>/dev/null',
        escapeshellarg($plugin_root),
        escapeshellarg('HEAD:' . $relative)
    );
    $output = shell_exec($command);

    return (is_string($output) && '' !== $output) ? $output : null;
};

$static   = $committed('vendor/composer/autoload_static.php');
$classmap = $committed('vendor/composer/autoload_classmap.php');

if (null === $static || null === $classmap) {
    fwrite(STDERR, "SKIP: the committed autoloader is not readable (no git, or an unborn HEAD)\n");
    exit(0);
}

// ── 1. No eager file requires ───────────────────────────────────────────────
//
// The production dump has no `$files` block at all. Its presence means dev dependencies were
// included, and four of the six paths it requires are stripped from the release ZIP.
if (preg_match('/public\s+static\s+\$files\s*=/', $static)) {
    $failures[] = 'the committed autoload_static.php has a $files block, so vendor/autoload.php '
        . 'eagerly requires dev dependencies that .distignore strips from the ZIP — the plugin '
        . 'fatals on activation (bug #325). Run: composer run build:autoload';
}

// ── 2. The plugin's own classes are in the classmap ─────────────────────────
//
// The vacuity control for the check above: an empty or truncated file would satisfy it
// perfectly. Under an authoritative classmap, zero entries means nothing loads.
$slimstat = preg_match_all("/'SlimStat\\\\\\\\/", $classmap);
if ($slimstat < 100) {
    $failures[] = sprintf(
        'the committed autoload_classmap.php holds %d SlimStat entries. The classmap is '
        . 'authoritative, so a missing entry is a class that cannot be found at all. '
        . 'Run: composer run build:autoload',
        $slimstat
    );
}

// ── 3. No development tooling in the shipped classmap ───────────────────────
//
// Named vendors rather than a count, so the failure says which tree leaked in.
$dev_prefixes = ['Mockery', 'PHPUnit', 'PhpParser', 'Hamcrest', 'DeepCopy', 'Brain'];
$leaked       = [];
foreach ($dev_prefixes as $prefix) {
    $hits = preg_match_all("/'" . $prefix . "\\\\\\\\/", $classmap);
    if ($hits > 0) {
        $leaked[] = sprintf('%s (%d)', $prefix, $hits);
    }
}
if ([] !== $leaked) {
    $failures[] = 'the committed classmap carries development tooling: ' . implode(', ', $leaked)
        . '. Run: composer run build:autoload';
}

if ([] !== $failures) {
    fwrite(STDERR, sprintf("FAIL: committed autoloader is not the production one (%d problem(s))\n", count($failures)));
    foreach ($failures as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

printf(
    "PASS: committed autoloader is production (%d SlimStat entries, no \$files block, no dev tooling)\n",
    $slimstat
);
