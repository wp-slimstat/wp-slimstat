<?php
/**
 * A default change must reach new installs and leave existing ones alone.
 *
 * Two defaults changed for v6: `ignore_bots` no -> on, and `async_load` no -> on.
 *
 * `ignore_bots` is the sensitive one. Bots are 27.7% of stored rows on the reference
 * dataset, so filtering them reclaims more storage than the whole normalisation phase —
 * but applying it to a site that has been counting them makes historical and new traffic
 * incomparable. The plan requires that never happen silently.
 *
 * It cannot, and the reason is structural rather than lucky:
 *
 *   - install writes get_fresh_defaults() -> init_options() into `slimstat_options`, so a
 *     NEW install stores the new value;
 *   - init() merges `array_merge(init_options(), $stored)` — defaults first, stored second,
 *     so a stored value WINS;
 *   - every existing install therefore already holds every key and keeps its own value.
 *
 * Verified on the reference install: all 110 keys stored, `ignore_bots` stored as 'no',
 * and it stays 'no'.
 *
 * This test pins the merge direction, because reversing it would silently rewrite settings
 * on every site on upgrade — the single most damaging thing this file can prevent.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = (string) @file_get_contents($plugin_root . '/wp-slimstat.php');
if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

// ── 1. Stored settings must win over defaults ───────────────────────────────
// array_merge(init_options(), $settings) -> later wins -> stored wins. Reversing the
// argument order would make every upgrade overwrite the site's own choices.
if (!preg_match('/array_merge\s*\(\s*self::init_options\(\)\s*,\s*self::\$settings\s*\)/', $source)) {
    $failures[] = 'the settings merge no longer reads array_merge(init_options(), $settings). '
        . 'Later arguments win in array_merge, so this exact order is what makes a stored '
        . 'value beat a default — reverse it and changing any default silently rewrites that '
        . 'setting on every existing install';
}

// ── 2. A fresh install must persist the defaults ────────────────────────────
// If it did not, a default change would leak into existing installs later, through
// whichever key happened to be absent.
$fresh = slimstat_function_body($source, 'get_fresh_defaults');
if ('' === $fresh || !preg_match('/init_options\(\)/', $fresh)) {
    $failures[] = 'get_fresh_defaults() no longer derives from init_options(), so a new '
        . 'install would not receive the shipped defaults';
}
// Match the ASSIGNMENT and the WRITE together. `update_option('slimstat_options'` appears
// elsewhere in this file, so looking for it anywhere passes even when the install path has
// stopped persisting anything.
if (!preg_match(
    "/=\s*self::get_fresh_defaults\(\)\s*;\s*self::update_option\(\s*'slimstat_options'/s",
    $source
)) {
    $failures[] = 'a fresh install no longer persists its defaults immediately after '
        . 'computing them. Unstored keys fall through to init_options() forever, so any '
        . 'future default change would reach existing sites silently';
}

// ── 3. The two changed defaults are what we think they are ──────────────────
$options = slimstat_function_body($source, 'init_options');
if ('' === $options) {
    $failures[] = 'init_options() not found';
} else {
    foreach (['ignore_bots' => 'on', 'async_load' => 'on'] as $key => $expected) {
        if (!preg_match("/'" . $key . "'\s*=>\s*'" . $expected . "'/", $options)) {
            $failures[] = "the default for {$key} is not '{$expected}'. If this was reverted "
                . 'deliberately, update this assertion and the release note together — the '
                . 'two must not drift';
        }
    }
}

// ── 4. Bot filtering must not be applied retroactively ──────────────────────
// Anything that writes ignore_bots into an existing install's stored options would defeat
// the whole mechanism above.
if (preg_match("/update_option\([^)]*'ignore_bots'|\\\$settings\['ignore_bots'\]\s*=/", $source)) {
    $failures[] = 'something assigns ignore_bots into the stored settings. That would apply '
        . 'bot filtering to an existing site on upgrade, making its historical and new '
        . 'traffic counts incomparable without the owner ever choosing it';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: default change safety (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: default change safety (stored settings beat defaults; fresh installs persist "
    . "them; bot filtering is not applied retroactively)\n";
exit(0);
