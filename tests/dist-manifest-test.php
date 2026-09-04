<?php
/**
 * The shipped ZIP contains what `.distignore` says and nothing more — gated in CI, not only in
 * a rehearsal container.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * Free's only packaging check lived in `tests/docker/build-free.sh` — a leak scan over a built
 * ZIP, reached solely by the Docker rehearsal cells, which had not run at the shipping heads.
 * On every push, nothing asked whether `.distignore` still excluded `tests/`, the PHPStan
 * configs, `composer.lock` or `node_modules`.
 *
 * ── HOW, WITHOUT AN ARTIFACT ────────────────────────────────────────────────────────────────
 *
 * The wordpress.org package is what `10up/action-wordpress-plugin-deploy` rsyncs from the
 * checkout with `--exclude-from=.distignore` — no `composer install`, no `npm ci` before it — so
 * the population is the TRACKED tree, and this simulates the package from `git ls-files` through
 * `.distignore` with the matcher `build-free.sh` applies to the real ZIP: an anchored entry
 * (`/tests`) matches by prefix, an unanchored one (`composer.lock`) matches any path segment.
 *
 * Two assertions over the surviving set. It must CONTAIN the runtime non-negotiables, and it
 * must contain NOTHING matching a denylist written HERE — because a check that asks
 * ".distignore excludes what .distignore excludes" is a tautology, which is the shape the Pro
 * persistence census was found in two days earlier.
 *
 * Section 1 additionally requires `.distignore` to name the populations `git ls-files` cannot
 * hold — `node_modules`, `vendor/phpunit`, `vendor/bin` — which a working-tree packager would
 * ship and the simulation cannot see.
 *
 * Run: php tests/dist-manifest-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

$plugin_root = dirname(__DIR__);
$failures    = [];

$distignore = (string) @file_get_contents($plugin_root . '/.distignore');
if ('' === trim($distignore)) {
    fwrite(STDERR, "FAIL: .distignore is missing or empty — the package would be the whole repository.\n");
    exit(1);
}

$entries = [];
foreach (preg_split('/\R/', $distignore) ?: [] as $line) {
    $line = trim($line);
    if ('' !== $line && '#' !== $line[0]) {
        $entries[$line] = true;
    }
}

// ── 1. Populations the simulation cannot see must be listed ─────────────────────────────
foreach (['/node_modules', '/vendor/phpunit', '/vendor/bin'] as $untracked) {
    if (!isset($entries[$untracked])) {
        $failures[] = sprintf('.distignore does not list `%s`; it is not tracked, so the simulation '
            . 'below cannot see it, and a working-tree packager would ship it', $untracked);
    }
}

// ── 2. Simulate the package from the tracked tree ───────────────────────────────────────
$tracked = [];
exec('cd ' . escapeshellarg($plugin_root) . ' && git ls-files 2>/dev/null', $tracked, $git_exit);
if (0 !== $git_exit || count($tracked) < 500) {
    fwrite(STDERR, sprintf("FAIL: git ls-files returned %d path(s) (exit %d); without the tracked set there "
        . "is nothing to simulate a package from.\n", count($tracked), $git_exit));
    exit(1);
}

/** build-free.sh's rule: `/x` is a prefix; `x` is any path segment. */
$is_shipped = static function (string $path) use ($entries): bool {
    $segments = explode('/', $path);
    foreach (array_keys($entries) as $entry) {
        if ('/' === $entry[0]) {
            $bare = substr($entry, 1);
            if ($path === $bare || 0 === strpos($path, $bare . '/')) {
                return false;
            }
        } elseif (in_array($entry, $segments, true)) {
            return false;
        }
    }
    return true;
};

$package = array_values(array_filter($tracked, $is_shipped));

// VACUITY FLOOR: if the matcher broke and excluded nothing, "leaks nothing" below would be asked
// of the whole repository, and the required-set check would still pass.
if (count($tracked) - count($package) < 200) {
    $failures[] = sprintf('the .distignore simulation removed only %d of %d tracked file(s); tests/ '
        . 'alone is hundreds, so the matcher has stopped matching', count($tracked) - count($package), count($tracked));
}

// ── 3. The runtime non-negotiables ship ─────────────────────────────────────────────────
$package_set = array_flip($package);
foreach (['wp-slimstat.php', 'uninstall.php', 'readme.txt', 'vendor/autoload.php',
    'vendor/composer/autoload_classmap.php', 'src/Schema/Schema.php', 'admin/index.php'] as $required) {
    if (!isset($package_set[$required])) {
        $failures[] = sprintf('the simulated package lacks `%s`; either .distignore excludes it or it '
            . 'is no longer tracked, and the ZIP would be broken on arrival', $required);
    }
}

// ── 4. …and nothing development-only. A DENYLIST WRITTEN HERE, on purpose ───────────────
$dev_only = '~^(tests|\.github|\.githooks|node_modules|vendor/phpunit|vendor/bin|build)/'
    . '|(^|/)(phpunit\.xml\.dist|phpstan\.neon\.dist|phpstan-baseline\.neon|composer\.lock|package(-lock)?\.json'
    . '|\.wp-env(\.override)?\.json|CONTRIBUTING\.md|\.distignore|\.gitignore|rector\.php|pint\.json)$'
    . '|\.(spec|test)\.ts$~';
$leaks = preg_grep($dev_only, $package) ?: [];

if ($leaks) {
    $failures[] = sprintf('%d development-only file(s) would ship, e.g. %s; .distignore no longer '
        . 'excludes them, or excludes them under a spelling the packager does not honour',
        count($leaks), implode(', ', array_slice(array_values($leaks), 0, 5)));
}

if ($failures) {
    fwrite(STDERR, 'FAIL: dist manifest (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: simulated package keeps %d of %d tracked files, carries the runtime set, and leaks "
    . "nothing development-only\n", count($package), count($tracked));
