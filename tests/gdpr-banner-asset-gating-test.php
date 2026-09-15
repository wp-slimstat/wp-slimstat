<?php
/**
 * Regression test: the consent banner's stylesheet must load only when the banner does
 * (D45).
 *
 * `enqueue_gdpr_assets()` enqueued a 14.8 KB render-blocking stylesheet whenever the
 * banner feature was on. `getBannerHtml()` returns '' as soon as the visitor has made a
 * consent decision — so every visitor who had already accepted or denied kept
 * downloading and parsing that stylesheet, on every page, to style markup that was no
 * longer emitted.
 *
 * Verified against the running site before the fix:
 *
 *     cookie=none      banner markup present   stylesheet present    <- correct
 *     cookie=accepted  banner markup ABSENT    stylesheet present    <- the defect
 *
 * The fix is a single authority, `GDPRService::shouldRenderBanner()`, consumed by both
 * the markup and the stylesheet so they cannot disagree. That is what this test pins:
 *
 *   1. The decision is correct for every cookie state and both feature states.
 *   2. The markup path consults it.
 *   3. The enqueue path consults it, and cannot throw — it runs on
 *      login_enqueue_scripts, where a fatal locks the administrator out (issue #325).
 *   4. It fails OPEN. The cost of a wrong guess that way is one unused stylesheet; the
 *      other way is an unstyled consent banner, which is compliance-visible.
 *
 * @see src/Services/GDPRService.php
 * @see wp-slimstat.php  enqueue_gdpr_assets()
 */

declare(strict_types=1);

namespace {

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s)
    {
        return is_string($s) ? trim(strip_tags($s)) : '';
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v)
    {
        return is_string($v) ? stripslashes($v) : $v;
    }
}

// The renderer needs a few more WordPress helpers than the decision does. Declared
// individually rather than generated, because `php -l` is the commit gate here and it
// cannot see inside an eval'd string.
if (!function_exists('__')) {
    function __($t, $d = 'default') { return $t; }
}
if (!function_exists('esc_html')) {
    function esc_html($t) { return $t; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($t) { return $t; }
}
if (!function_exists('wp_kses')) {
    function wp_kses($s, $allowed) { return $s; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($s) { return $s; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}

// ── Harness ─────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function gba_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

require_once __DIR__ . '/lib/source-scan.php';
require_once __DIR__ . '/../src/Services/GDPRService.php';

use SlimStat\Services\GDPRService;

if (!method_exists(GDPRService::class, 'shouldRenderBanner')) {
    fwrite(STDERR, "FAIL: GDPRService::shouldRenderBanner() does not exist — the banner's\n"
        . "      markup and its stylesheet have no shared authority, so nothing stops\n"
        . "      them disagreeing about whether the banner appears\n");
    exit(1);
}

// ── 1. The decision, exercised directly ─────────────────────────────────────
//
// The stylesheet and the markup are both downstream of this one answer, so testing it
// covers both without needing to load the plugin bootstrap.
$cookie = GDPRService::CONSENT_COOKIE_NAME;

foreach ([
    // consent cookie, banner setting, expected
    ['none',     'on',  true,  'an undecided visitor sees the banner'],
    ['accepted', 'on',  false, 'a visitor who accepted does not'],
    ['denied',   'on',  false, 'a visitor who denied does not'],
    ['none',     'off', false, 'the feature being off wins over an undecided visitor'],
    ['accepted', 'off', false, 'the feature being off wins over a decided visitor'],
] as [$consent, $setting, $expected, $label]) {
    unset($_COOKIE[$cookie]);
    if ('none' !== $consent) {
        $_COOKIE[$cookie] = $consent;
    }

    $service = new GDPRService(['gdpr_enabled' => 'on', 'use_slimstat_banner' => $setting]);
    $actual  = $service->shouldRenderBanner();
    gba_assert($label, $actual === $expected, 'got ' . var_export($actual, true));
}

// The banner needs BOTH settings — wp-slimstat.php registers its hooks on that pair.
// Checking only `use_slimstat_banner` would let the decision answer "yes" for a site
// where no banner can ever appear, which is how the enqueue and the markup drifted
// apart in the first place.
unset($_COOKIE[$cookie]);
$gdpr_off = new GDPRService(['gdpr_enabled' => 'off', 'use_slimstat_banner' => 'on']);
gba_assert(
    'GDPR mode being off means no banner, whatever the banner setting says',
    $gdpr_off->shouldRenderBanner() === false,
    'got true — the decision ignores gdpr_enabled, so it can disagree with the hooks '
        . 'that decide whether the banner renders at all'
);

// ── 2. The markup path is downstream of the same decision ───────────────────
//
// Asserted behaviourally: a decided visitor gets no markup, an undecided one does.
foreach ([
    ['accepted', '', 'a decided visitor gets no banner markup'],
    ['none',     'slimstat-gdpr-banner', 'an undecided visitor gets banner markup'],
] as [$consent, $needle, $label]) {
    unset($_COOKIE[$cookie]);
    if ('none' !== $consent) {
        $_COOKIE[$cookie] = $consent;
    }

    $service = new GDPRService(['gdpr_enabled' => 'on', 'use_slimstat_banner' => 'on', 'opt_out_message' => 'Test message']);
    $html    = $service->getBannerHtml();

    gba_assert(
        $label,
        '' === $needle ? '' === $html : (is_string($html) && strpos($html, $needle) !== false),
        'markup was ' . ('' === $html ? 'empty' : strlen($html) . ' bytes')
    );
}

// ── 3-4. The enqueue consults it, and cannot take the login page down ───────
//
// enqueue_gdpr_assets() is a static on the plugin bootstrap, which is far too heavy to
// load here; these two are asserted on its source. They are narrow and durable: the
// enqueue must consult the shared decision, and must not be able to throw on a hook
// that also fires on wp-login.php.
$src  = (string) file_get_contents(__DIR__ . '/../wp-slimstat.php');
// Optional lookup so the curated diagnostic below survives: the default form throws on
// absence, which would replace this assertion's message with a stack trace.
$body = $src === '' ? null : slimstat_find_function_body($src, 'enqueue_gdpr_assets');

gba_assert('enqueue_gdpr_assets() exists', null !== $body, 'method not found');
$body = (string) $body;
gba_assert(
    'the enqueue consults the shared decision',
    strpos($body, 'shouldRenderBanner') !== false,
    'the stylesheet loads for every visitor, including those for whom getBannerHtml() '
        . 'emits nothing'
);
gba_assert(
    'the enqueue cannot throw on wp_enqueue_scripts',
    (bool) preg_match('/\btry\b/', $body) && (bool) preg_match('/\bcatch\b/', $body),
    'an unloadable GDPRService would fatal on login_enqueue_scripts and lock the '
        . 'administrator out (issue #325)'
);
// Fail OPEN: the enqueue call must sit outside the try, so a caught failure still
// reaches it. If the wp_enqueue_style() call were inside the try, a throw would skip it
// and the banner would render unstyled.
$catch_pos    = strpos($body, 'catch');
$enqueue_pos  = strpos($body, 'wp_enqueue_style');
gba_assert(
    'a failure to determine consent still enqueues',
    $catch_pos !== false && $enqueue_pos !== false && $enqueue_pos > $catch_pos,
    'the enqueue is inside the try, so an unreadable consent state would leave the '
        . 'banner unstyled'
);

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: GDPR banner asset gating (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: GDPR banner asset gating (%d assertions)\n", $passes);
exit(0);

}
