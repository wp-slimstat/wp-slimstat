<?php

/**
 * Regression test for the #306 follow-up — the referer sanitizer must preserve
 * percent-encoded (%XX) query octets so that search-term extraction keeps
 * working for non-Latin / space-bearing search-engine referers.
 *
 * Background: #306 swapped the referer sanitizer from sanitize_url() to
 * sanitize_text_field() so that `android-app://…` survived. But WordPress's
 * sanitize_text_field() strips EVERY %XX octet (wp-includes/formatting.php:
 * `while (preg_match('/%[a-f0-9]{2}/i', …)) str_replace('', …)`), so a referer
 * like `https://yandex.ru/?text=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82` arrives at
 * Utils::getSearchTerms() as `…?text=` — the term is gone. The fix uses
 * sanitize_url() with `android-app` added to the protocols allow-list
 * (Processor::REFERER_ALLOWED_SCHEMES), which preserves %XX AND the app scheme
 * while still dropping disallowed schemes (javascript:, data:).
 *
 * This test models BOTH sanitizers with WordPress-faithful behavior and exercises
 * the real Ajax::sanitizeReferer(), proving the value reaching getSearchTerms now
 * keeps its %XX (and that the old sanitize_text_field path would have lost it).
 *
 * Run: php tests/referer-searchterms-encoding-test.php
 */

declare(strict_types=1);

namespace {

    $assertions = 0;

    function st_assert(bool $cond, string $message): void
    {
        global $assertions;
        $assertions++;
        if (!$cond) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ─── WordPress function models (faithful to wp-includes/formatting.php) ───

    if (!function_exists('sanitize_text_field')) {
        // Mirrors _sanitize_text_fields(): strips tags, collapses whitespace, and
        // — critically — removes every percent-encoded octet. This is the behavior
        // that silently dropped non-Latin search terms.
        function sanitize_text_field($str)
        {
            $str = (string) $str;
            if (strpos($str, '<') !== false) {
                $str = strip_tags($str);
            }
            $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
            $str = trim($str);
            while (preg_match('/%[a-f0-9]{2}/i', $str, $m)) {
                $str = str_replace($m[0], '', $str);
            }
            return $str;
        }
    }

    if (!function_exists('sanitize_url')) {
        // Models esc_url_raw(): drops any scheme not in $protocols (default list +
        // whatever the caller passes), strips tags, but PRESERVES %XX query octets.
        function sanitize_url($url, $protocols = null)
        {
            $url     = strip_tags(trim((string) $url));
            $allowed = $protocols ?: ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn'];
            if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m) && !in_array(strtolower($m[1]), $allowed, true)) {
                return '';
            }
            return $url;
        }
    }

    if (!function_exists('wp_unslash')) {
        function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
    }

    require_once __DIR__ . '/../src/Tracker/Utils.php';
    require_once __DIR__ . '/../src/Tracker/Processor.php';
    require_once __DIR__ . '/../src/Tracker/Ajax.php';

    $encode = static fn(string $url): string => \SlimStat\Tracker\Utils::base64UrlEncode($url);

    $YANDEX  = 'https://yandex.ru/search/?text=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82&lr=1';
    $SPACE   = 'https://www.bing.com/search?q=wp%20slimstat';
    $ANDROID = 'android-app://com.google.android.googlequicksearchbox/';

    // ─── 1. The fix: sanitizeReferer preserves %XX (regression guard) ──────────
    // Fails on the old sanitize_text_field path, which strips %XX.

    $out = \SlimStat\Tracker\Ajax::sanitizeReferer($encode($YANDEX));
    st_assert(is_string($out) && strpos($out, '%D0%BF') !== false, 'sanitizeReferer preserves percent-encoded query octets (non-Latin search term)');

    // The preserved %XX decodes back to the original term via parse_str — exactly
    // what Utils::getSearchTerms() relies on.
    @parse_str((string) parse_url($out, PHP_URL_QUERY), $vals);
    st_assert(($vals['text'] ?? '') === 'привет', 'preserved referer decodes to the original non-Latin search term');

    $outSpace = \SlimStat\Tracker\Ajax::sanitizeReferer($encode($SPACE));
    @parse_str((string) parse_url($outSpace, PHP_URL_QUERY), $valsSpace);
    st_assert(($valsSpace['q'] ?? '') === 'wp slimstat', 'preserved referer decodes %20 to a space in the search term');

    // ─── 2. Control: the OLD sanitize_text_field path loses the term ───────────
    // Documents the regression mechanism this fix reverses.

    $stripped = sanitize_text_field($YANDEX);
    @parse_str((string) parse_url($stripped, PHP_URL_QUERY), $strippedVals);
    st_assert(($strippedVals['text'] ?? '') === '', 'control: sanitize_text_field strips %XX, wiping the search term (the bug being fixed)');

    // ─── 3. #306 still satisfied: android-app survives ─────────────────────────

    st_assert(\SlimStat\Tracker\Ajax::sanitizeReferer($encode($ANDROID)) === $ANDROID, 'android-app:// referer still survives (issue #306)');

    // ─── 4. L1: disallowed schemes are dropped at the boundary ─────────────────
    // Covers the follow-up-event path that skips Processor's post-storage check.

    st_assert(\SlimStat\Tracker\Ajax::sanitizeReferer($encode('javascript:alert(document.cookie)//')) === '', 'javascript: referer is emptied at the sanitizer (defense in depth, all paths)');
    st_assert(\SlimStat\Tracker\Ajax::sanitizeReferer($encode('data:text/html,<b>x</b>')) === '', 'data: referer is emptied at the sanitizer');

    fwrite(STDOUT, "OK: {$assertions} assertions passed (referer %XX preservation + scheme allow-list, #306 follow-up)\n");
    exit(0);
}
