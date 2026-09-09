<?php
/**
 * Free refuses to load below its declared PHP floor — and the envelope in which that refusal
 * can happen is proven by an interpreter and ratcheted by a scan.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * `wp-slimstat.php` declared `Requires PHP: 7.4` and enforced nothing. WordPress honours that
 * header only in the update/activation UI; an already-installed plugin on a downgraded host
 * loads regardless, and below the floor `vendor/autoload.php` and the Php80 polyfill are parse
 * errors — uncatchable, fatal to the whole site. Pro was fixed first (D5); the review of that
 * fix found free in the same state, with the Pro notice promising "the free plugin continues
 * to work" about a sibling that could not.
 *
 * ── THE ENVELOPE: PROVEN AT 7.0, RATCHETED AT 7.1 — AND WHY THOSE DIFFER ────────────────────
 *
 * PHP compiles a file in full before executing any of it, so the guard reaches only runtimes
 * that can parse the whole file. Where that begins was first stated from the scanner's table
 * ("`: void` is 7.1, therefore 7.1") and was wrong: a reviewer ran `php:7.0-cli` against the
 * file and it parsed — `void` was only RESERVED in 7.1; 7.0 reads it as a class name — while
 * `php:5.6-cli` failed on the first `??`. So:
 *
 *   - PROVEN: the guard reaches hosts on 7.0–7.3. Section 5 pins a real `php:7.0-cli` lint of
 *     this one file into the Tier 1 lane, because only an interpreter can say "parses".
 *   - RATCHETED: section 3 forbids adding anything the scanner dates after 7.1. The scanner
 *     dates constructs by when they became meaningful, not parseable, so 7.1 is the tightest
 *     floor it can express for a file that already carries `: void`. It stops the envelope
 *     shrinking silently; it does not measure where the envelope is.
 *
 * Below 7.0 the mitigation is WordPress: 5.5.0 also declared `Requires PHP: 7.4`
 * (`git show v5.5.0:wp-slimstat.php`), so WordPress ≥ 5.2 does not offer 6.0.0 to such a host.
 * Widening the envelope means making `wp-slimstat.php` a small loader and moving the class
 * out: 18 `__FILE__` sites, 24 tests that tokenise this file and 12 mutations anchored on it —
 * measured, and not worth a band the update UI already fences. Recorded, not done.
 *
 * Run: php tests/php-floor-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root    = dirname(__DIR__);
$bootstrap_path = $plugin_root . '/wp-slimstat.php';
$failures       = [];

$raw  = (string) file_get_contents($bootstrap_path);
$code = slimstat_blank_comments($raw);

// ── 0. The floor is the header's, read once ─────────────────────────────────────────────
//
// Three declarations of one number — header, guard, composer — and the header is the one
// WordPress reads, so it is the source. Hardcoding 7.4 here would let header and guard drift
// while every pairwise check stayed green.
$floor = slimstat_header_field($raw, 'Requires PHP');
if (null === $floor || !preg_match('/^(\d+)\.(\d+)$/', $floor, $fm)) {
    fwrite(STDERR, "FAIL: no `Requires PHP: N.N` in wp-slimstat.php header\n");
    exit(1);
}
$floor_id = (int) $fm[1] * 10000 + (int) $fm[2] * 100;

// ── 1. composer.json declares the same floor ────────────────────────────────────────────
$composer = json_decode((string) file_get_contents($plugin_root . '/composer.json'), true);
$declared = $composer['require']['php'] ?? null;

if (null === $declared) {
    $failures[] = 'composer.json declares no `php` constraint in `require`; a `composer install` '
        . 'on an older runtime resolves and produces a vendor/ that cannot be parsed there';
} elseif (false === strpos((string) $declared, $floor)) {
    $failures[] = sprintf('composer.json requires php "%s"; the header says %s. One number, three '
        . 'declarations, and they must not drift', $declared, $floor);
}

// ── 2. The guard exists, precedes every require it protects, and stops ──────────────────
//
// Ordering by byte offset: a check placed after the autoloader require is dead on every
// runtime it was written for. The `return` is looked for INSIDE THE GUARD'S OWN BLOCK — the
// first version searched a fixed 700-byte window, which reached the next block's `return`
// (`if (!file_exists(vendor/autoload.php)) { return; }`), so deleting the guard's own return
// stayed green. PF4 replays that.
if (!preg_match('/PHP_VERSION_ID\s*<\s*' . $floor_id . '\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
    $failures[] = sprintf('wp-slimstat.php has no `PHP_VERSION_ID < %d` check; loading on an older '
        . 'runtime is then a parse error inside vendor/autoload.php — uncatchable, fatal to the '
        . 'whole site including wp-admin', $floor_id);
} else {
    $guard_at = $m[0][1];

    foreach (['vendor/autoload.php', 'Polyfill/Php80/bootstrap.php'] as $required) {
        if (!preg_match('/require[^;\n]*' . preg_quote($required, '/') . '/', $code, $r, PREG_OFFSET_CAPTURE)) {
            $failures[] = sprintf('wp-slimstat.php no longer requires %s; section 2 cannot check '
                . 'the guard precedes it', $required);
        } elseif ($guard_at > $r[0][1]) {
            $failures[] = sprintf('the PHP_VERSION_ID guard appears AFTER the %s require, which is '
                . 'reached first and is a parse error on the runtimes the guard exists for', $required);
        }
    }

    if (!preg_match('/PHP_VERSION_ID\s*<\s*' . $floor_id . '\s*\)\s*\{(.*?)^\}/ms', $code, $block)
        || false === strpos($block[1], 'return')) {
        $failures[] = 'the PHP_VERSION_ID branch does not `return` inside its own block. Detecting '
            . 'an unsupported runtime and continuing into the autoloader is the same fatal with '
            . 'an extra notice attached';
    }
}

// ── 3. Ratchet: nothing the scanner dates after 7.1 may be added ────────────────────────
//
// 70100 because `: void` is already in the file and that is the scanner's date for it. The
// scanner stops at 7.4; 8.0+ is php80-syntax-scan-test.php's over the same file.
foreach (slimstat_php_constructs_newer_than($code, 70100) as $finding) {
    $failures[] = sprintf(
        'wp-slimstat.php line %d uses a %s (PHP %d.%d); the file must stay parseable on 7.0 or '
            . 'the floor guard is unreachable — see this file\'s header',
        $finding['line'],
        $finding['construct'],
        intdiv($finding['since'], 10000),
        intdiv($finding['since'] % 10000, 100)
    );
}

// ── 3b. The scanner must be able to find something ──────────────────────────────────────
$canary = "<?php\nfunction c(int \$a): string { return \$a ?? 'x'; }\nclass K { public int \$n; }\n";
$canary_findings = slimstat_php_constructs_newer_than($canary, 50600);

if (count($canary_findings) < 4) {
    $failures[] = sprintf('slimstat_php_constructs_newer_than() found only %d construct(s) in a '
        . 'canary carrying a scalar parameter type, a return type, a null-coalesce and a typed '
        . 'property — the scanner has stopped matching, so section 3 passes by finding nothing',
        count($canary_findings));
}

// ── 4. The notice callback exists and is hooked, not called bare ────────────────────────
if (!preg_match("/add_action\s*\(\s*'admin_notices'\s*,\s*'wp_slimstat_render_php_floor_notice'/", $code)) {
    $failures[] = "the floor branch does not hook 'wp_slimstat_render_php_floor_notice' to "
        . 'admin_notices; echoing from the branch emits markup before headers are sent';
}
if (!preg_match('/function\s+wp_slimstat_render_php_floor_notice\s*\(/', $code)) {
    $failures[] = 'wp_slimstat_render_php_floor_notice() is hooked but not defined, so the floor '
        . 'path produces a fatal of its own on the one runtime it must not';
}

// ── 5. A real interpreter says "parses": the php:7.0-cli lint step in Tier 1 ────────────
//
// The assertion sections 2–4 cannot make. `docker run php:7.0-cli php -l wp-slimstat.php` is
// one line; PITFALLS 54 already says so. Pinned here so the lane cannot be deleted quietly.
$ci_code = slimstat_yaml_strip_comments((string) file_get_contents($plugin_root . '/.github/workflows/ci.yml'));
$lint_steps = slimstat_ci_steps_containing(slimstat_ci_steps($ci_code), 'php:7.0-cli', 'wp-slimstat.php');
$lint_step  = 1 === count($lint_steps) ? $lint_steps[0] : '';

if ('' === $lint_step) {
    $failures[] = 'ci.yml has no step running `php:7.0-cli` against wp-slimstat.php. Sections 2–4 '
        . 'reason about parseability; only an interpreter proves it, and it was an interpreter '
        . 'that corrected this file\'s own envelope claim';
} else {
    if (!preg_match('/php\s+-l/', $lint_step)) {
        $failures[] = 'the php:7.0-cli step does not run `php -l`; a container that does not lint '
            . 'the file proves nothing about parsing it';
    }
    if (false !== strpos($lint_step, 'continue-on-error')) {
        $failures[] = 'the php:7.0-cli lint step is soft; a parse envelope that cannot fail the '
            . 'build is a note, not a gate';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: PHP floor (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf(
    "PASS: floor %s agrees across header, guard and composer; the guard precedes both requires "
        . "and returns; nothing newer than 7.1 was added (canary %d); Tier 1 lints the file on a "
        . "real php:7.0-cli\n",
    $floor,
    count($canary_findings)
);
