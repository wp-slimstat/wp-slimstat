<?php
/**
 * Regression (issue #325): a missing/unloadable \SlimStat\Services\GDPRService
 * must NOT white-screen the front page or wp-login. Every consent check routes
 * through the fail-closed guard Consent::bannerHasConsentSafe(), and the tracker
 * script-enqueue banner-params block (wp-slimstat.php enqueue_tracker) is wrapped
 * in try/catch. This closes the gap the resilient-autoloader PR left open, where
 * a genuinely unloadable GDPRService fatally aborted:
 *   - enqueue_tracker()  -> wp_enqueue_scripts / login_enqueue_scripts (both modes)
 *   - Processor::process() -> Consent::canTrack()/piiAllowed(), Session, IPHash
 *     (server-side tracking mode: the `wp` / login_init hooks).
 *
 * Standalone (own process), 7.4-safe, no PHPUnit. Uses a self-registered
 * SlimStat\ autoloader that REFUSES to load GDPRService, simulating an unloadable
 * class exactly as the non-authoritative autoloader surfaces it (an \Error at the
 * point of use, not at file include).
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

// Load SlimStat\ classes from src/ (no dependency on vendor/), but REFUSE
// GDPRService so `new \SlimStat\Services\GDPRService()` raises \Error — this is
// the "unloadable class" condition under test.
spl_autoload_register(function ($class) use ($plugin_root) {
    if (strpos($class, 'SlimStat\\') !== 0) {
        return;
    }
    if ('SlimStat\\Services\\GDPRService' === $class) {
        return; // simulate missing/unloadable
    }
    $rel  = str_replace('\\', '/', substr($class, strlen('SlimStat\\')));
    $file = $plugin_root . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!defined('ABSPATH')) {
    define('ABSPATH', $plugin_root . '/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Minimal WP function stubs used by the Consent code paths under test.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v)
    {
        return is_string($v) ? trim($v) : $v;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v)
    {
        return $v;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value = null)
    {
        return $value;
    }
}

// Consent + the fail-soft catch reference the global \wp_slimstat.
class wp_slimstat
{
    /** Mirrors wp_slimstat's severity constants; the migration and tracker paths
     *  name them when recording, so a stub without them fatals rather than fails. */
    const DEGRADATION_LOAD = 'load';

    const DEGRADATION_OPERATIONAL = 'operational';

    public static $settings = [];
    public static $is_programmatic_tracking = false;
    public static $degradations = [];

    // Mirrors the real signature. The production version also PERSISTS the record
    // (see wp_slimstat::record_degradation) so the failure is visible without
    // WP_DEBUG; here we only need to prove the guard reaches it without throwing.
    public static function record_degradation($step, $e)
    {
        self::$degradations[$step] = $e instanceof \Throwable ? $e->getMessage() : (string) $e;
    }
}

$fails = 0;
function pass_($msg)
{
    fwrite(STDOUT, "  PASS  {$msg}\n");
}
function fail_($msg)
{
    global $fails;
    $fails++;
    fwrite(STDERR, "  FAIL  {$msg}\n");
}

// Settings that make Consent::getIntegrationKey() resolve to 'slimstat_banner'.
$bannerBase = [
    'gdpr_enabled'        => 'on',
    'use_slimstat_banner' => 'on',
    'consent_integration' => 'slimstat_banner',
    'do_not_track'        => 'off',
];

// --- 1) The centralized guard exists and fails CLOSED without throwing. ---
if (!method_exists('SlimStat\\Utils\\Consent', 'bannerHasConsentSafe')) {
    fail_('Consent::bannerHasConsentSafe() is missing (the centralized fail-soft guard)');
} else {
    try {
        $r = \SlimStat\Utils\Consent::bannerHasConsentSafe($bannerBase);
        if (false === $r) {
            pass_('bannerHasConsentSafe() returns false (fail-closed) when GDPRService is unloadable');
        } else {
            fail_('bannerHasConsentSafe() did not fail closed (expected false, got ' . var_export($r, true) . ')');
        }
    } catch (\Throwable $e) {
        fail_('bannerHasConsentSafe() threw instead of failing soft: ' . $e->getMessage());
    }
    // It must record a PERSISTED degradation, not just a WP_DEBUG-gated log line —
    // otherwise a broken consent path is invisible on a production site.
    if (isset(wp_slimstat::$degradations['banner_consent_check'])) {
        pass_('bannerHasConsentSafe() records a persisted degradation on failure');
    } else {
        fail_('bannerHasConsentSafe() did not record a degradation on failure');
    }
}

// --- 2) piiAllowed() — a server-side-render caller — must not throw (banner + anon mode). ---
wp_slimstat::$settings = $bannerBase + ['anonymous_tracking' => 'on'];
wp_slimstat::$is_programmatic_tracking = false;
$_COOKIE = [];
unset($_SERVER['HTTP_DNT']);
try {
    $r = \SlimStat\Utils\Consent::piiAllowed(false);
    pass_('piiAllowed() returns ' . var_export($r, true) . ' without throwing when GDPRService is unloadable');
} catch (\Throwable $e) {
    fail_('piiAllowed() threw (WSOD on server-side page render): ' . $e->getMessage());
}

// --- 3) canTrack() — the other server-side-render caller — must not throw (banner + standard mode). ---
wp_slimstat::$settings = $bannerBase + ['anonymous_tracking' => 'off', 'set_tracker_cookie' => 'on'];
try {
    $r = \SlimStat\Utils\Consent::canTrack();
    pass_('canTrack() returns ' . var_export($r, true) . ' without throwing when GDPRService is unloadable');
} catch (\Throwable $e) {
    fail_('canTrack() threw (WSOD on server-side page render): ' . $e->getMessage());
}

// --- 4) No raw `new GDPRService(` left in the tracking path except inside the guard. ---
$needle = 'new \\SlimStat\\Services\\GDPRService(';
$rawNew = 0;
foreach (['src/Utils/Consent.php', 'src/Tracker/Session.php', 'src/Providers/IPHashProvider.php'] as $f) {
    $rawNew += substr_count((string) file_get_contents($plugin_root . '/' . $f), $needle);
}
if (1 === $rawNew) {
    pass_('exactly one GDPRService instantiation remains in the tracking path (inside the guard)');
} else {
    fail_("expected 1 guarded GDPRService instantiation in the tracking path, found {$rawNew} (unguarded sites remain)");
}

// --- 5) enqueue_tracker banner-params block is try/catch(\Throwable) guarded. ---
$wpsrc = (string) file_get_contents($plugin_root . '/wp-slimstat.php');
$pos   = strpos($wpsrc, 'CONSENT_COOKIE_NAME');
if (false === $pos) {
    fail_('CONSENT_COOKIE_NAME reference not found in wp-slimstat.php');
} else {
    $before = substr($wpsrc, max(0, $pos - 1500), min($pos, 1500));
    $after  = substr($wpsrc, $pos, 1500);
    if (false !== strpos($before, 'try {') && false !== strpos($after, 'catch (\\Throwable')) {
        pass_('enqueue_tracker GDPRService reference is wrapped in try/catch (\\Throwable)');
    } else {
        fail_('enqueue_tracker GDPRService reference is NOT wrapped in try/catch (WSOD risk on enqueue)');
    }
}

if (0 === $fails) {
    fwrite(STDOUT, "OK: GDPRService-unloadable fail-soft verified (issue #325 — enqueue + server-side tracking)\n");
    exit(0);
}
fwrite(STDERR, "FAIL: {$fails} assertion(s) failed\n");
exit(1);
