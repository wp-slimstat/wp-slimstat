<?php
/**
 * Source-level verification: Browscap bot detection safety net.
 *
 * Ensures the defense-in-depth fix for #291 exists in the source:
 * 1. Browscap.php contains the apply_bot_safety_net() method
 * 2. Browscap.php calls apply_bot_safety_net() in get_browser()
 * 3. UADetector.php Googlebot regex does not use end-of-string anchor
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/291
 */

declare(strict_types=1);

$assertions = 0;

function safety_assert(bool $condition, string $msg): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Read source files
// ═══════════════════════════════════════════════════════════════════════════
$browscap_src = file_get_contents(dirname(__DIR__) . '/src/Services/Browscap.php');
$uadetector_src = file_get_contents(dirname(__DIR__) . '/src/Utils/UADetector.php');

safety_assert(false !== $browscap_src, 'Could not read src/Services/Browscap.php');
safety_assert(false !== $uadetector_src, 'Could not read src/Utils/UADetector.php');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: Browscap.php must define apply_bot_safety_net method
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false !== strpos($browscap_src, 'function apply_bot_safety_net'),
    'TEST 1: Browscap.php must contain apply_bot_safety_net() method'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: Browscap.php get_browser() must call apply_bot_safety_net
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    (bool) preg_match('/function\s+get_browser\s*\([^)]*\)\s*\{[\s\S]*?apply_bot_safety_net\s*\(/', $browscap_src),
    'TEST 2: Browscap.php get_browser() must call apply_bot_safety_net()'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: apply_bot_safety_net must use UADetector::BOT_GENERIC_REGEX constant
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false !== strpos($browscap_src, 'BOT_GENERIC_REGEX'),
    'TEST 3: apply_bot_safety_net must reference UADetector::BOT_GENERIC_REGEX constant'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: UADetector Googlebot regex must NOT end with \)$
// The $ anchor prevents matching Chrome-based Googlebot UAs.
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false === strpos($uadetector_src, "bot\\.html\\)\$#"),
    'TEST 4: UADetector Googlebot regex must not use $ end-of-string anchor after bot.html'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: UADetector Googlebot regex must still exist (without $ anchor)
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false !== strpos($uadetector_src, 'Googlebot'),
    'TEST 5: UADetector must still contain Googlebot detection pattern'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: Safety net must only run when browser_type is 0
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    (bool) preg_match('/0\s*===.*browser_type.*apply_bot_safety_net/s', $browscap_src),
    'TEST 6: Safety net call must be guarded by browser_type === 0 check'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 7: UADetector must define BOT_GENERIC_REGEX as a constant
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false !== strpos($uadetector_src, 'BOT_GENERIC_REGEX'),
    'TEST 7: UADetector must define BOT_GENERIC_REGEX constant'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 8: UADetector line 272 must use the constant, not inline regex
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false !== strpos($uadetector_src, 'self::BOT_GENERIC_REGEX'),
    'TEST 8: UADetector must use self::BOT_GENERIC_REGEX in the generic bot check'
);

echo "All {$assertions} assertions passed in browscap-bot-safety-net-test.php\n";
