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
// TEST 6: Safety net gate must run for any non-crawler type (#14843 v2).
// Previously only fired on type=0 — mobile bot UAs (type=2) slipped through.
// The gate must be `1 !== (int) $browser['browser_type']` so types 0, 2, 3
// all re-run the UA keyword check.
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    (bool) preg_match('/1\s*!==.*browser_type.*apply_bot_safety_net/s', $browscap_src),
    'TEST 6: Safety net call must be guarded by `1 !== (int) browser_type` (not `0 ===`)'
);
safety_assert(
    false === strpos($browscap_src, "0 === (int) \$browser['browser_type']"),
    'TEST 6b: Safety net must not use the old `0 === (int) browser_type` gate'
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

// ═══════════════════════════════════════════════════════════════════════════
// TEST 9: UADetector Bingbot regex must NOT use ^ or $ anchors
// Anchors prevent matching Chrome-based Bingbot UAs (same fix as Googlebot).
// ═══════════════════════════════════════════════════════════════════════════
safety_assert(
    false === strpos($uadetector_src, "bingbot\\.htm.\$#"),
    'TEST 9a: UADetector Bingbot regex must not use $ end-of-string anchor'
);
safety_assert(
    false === strpos($uadetector_src, "#^Mozilla.*bingbot"),
    'TEST 9b: UADetector Bingbot regex must not use ^ start-of-string anchor'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 10: Ajax.php must check ignore_bots in the follow-up event path
// ═══════════════════════════════════════════════════════════════════════════
$ajax_src = file_get_contents(dirname(__DIR__) . '/src/Tracker/Ajax.php');
safety_assert(false !== $ajax_src, 'Could not read src/Tracker/Ajax.php');
safety_assert(
    false !== strpos($ajax_src, "ignore_bots") && false !== strpos($ajax_src, 'Browscap::get_browser'),
    'TEST 10: Ajax.php must check ignore_bots via Browscap::get_browser() for follow-up events'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 11: BOT_GENERIC_REGEX must include the 16 new keywords (#14843 v2)
// Closes gaps for: Mediapartners-Google, Google-InspectionTool, Google-Site-
// Verification, Google Favicon, GoogleOther, GoogleAgent-Mariner, Google-Safety,
// DuplexWeb-Google, BingPreview, YandexDirect, YandexFavicons, WhatsApp,
// SkypeUriPreview, anthropic-ai, cohere-ai.
// ═══════════════════════════════════════════════════════════════════════════
$required_regex_tokens = [
    'mediapartners', 'inspectiontool', 'googleother', 'googleagent',
    'google-safety', 'duplexweb', 'bingpreview', 'yandex',
    'direct|favicons', 'anthropic-ai', 'cohere-ai', 'skypeuripreview',
    'whatsapp', 'favicon', 'verif',
];
foreach ($required_regex_tokens as $token) {
    safety_assert(
        false !== stripos($uadetector_src, $token),
        "TEST 11: BOT_GENERIC_REGEX must contain '{$token}' keyword"
    );
}

echo "All {$assertions} assertions passed in browscap-bot-safety-net-test.php\n";
