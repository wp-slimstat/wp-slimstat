<?php

/**
 * Integration test for #306 — android-app:// (Google Discover) referers must
 * survive the storage layer.
 *
 * Storage::insertRow() routes the `referer` key through sanitize_text_field()
 * (only `resource` uses sanitize_url()), so the value is preserved at this layer.
 * This proves the #306 fault was strictly upstream (Ajax::sanitizeReferer /
 * Processor server fallback used sanitize_url, which stripped the scheme) and
 * that the storage layer needs no change.
 *
 * Run: php tests/referer-android-app-storage-test.php
 */

declare(strict_types=1);

namespace SlimStat\Utils {

    class FakeQueryRecorder
    {
        public static array $values = [];
        public static int $executeCalls = 0;

        public static function reset(): void
        {
            self::$values       = [];
            self::$executeCalls = 0;
        }
    }

    class Query
    {
        public static function insert($table)
        {
            return new self();
        }

        public function ignore($flag = true)
        {
            return $this;
        }

        public function values($values)
        {
            FakeQueryRecorder::$values = $values;
            return $this;
        }

        public function execute()
        {
            FakeQueryRecorder::$executeCalls++;
            return 1;
        }
    }
}

namespace {

    $assertions = 0;

    function assert_same($expected, $actual, string $message): void
    {
        global $assertions;
        $assertions++;
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
            exit(1);
        }
    }

    function assert_not_contains(string $needle, string $haystack, string $message): void
    {
        global $assertions;
        $assertions++;
        if (strpos($haystack, $needle) !== false) {
            fwrite(STDERR, "FAIL: {$message}\n  Expected NOT to contain: '{$needle}'\n  In: '{$haystack}'\n");
            exit(1);
        }
    }

    // ─── WordPress function stubs (real-ish implementations) ───────────

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str)
        {
            $str = (string) $str;
            $str = strip_tags($str);
            $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
            return trim($str);
        }
    }

    if (!function_exists('sanitize_url')) {
        // Models WP esc_url_raw: strips schemes not in the default allowlist.
        // android-app is NOT in the default list — returns '' (the #306 bug).
        function sanitize_url($url)
        {
            $url = trim((string) $url);
            if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m)) {
                $allowed = ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn'];
                if (!in_array(strtolower($m[1]), $allowed, true)) {
                    return '';
                }
            }
            return strip_tags($url);
        }
    }

    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
    };

    require_once __DIR__ . '/../src/Tracker/Storage.php';

    $ANDROID_APP = 'android-app://com.google.android.googlequicksearchbox/';

    // ─── Test 1: insertRow preserves android-app:// referer verbatim ───

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::insertRow([
        'referer'  => $ANDROID_APP,
        'ip'       => '127.0.0.1',
    ], 'wp_slim_stats');
    assert_same(1, \SlimStat\Utils\FakeQueryRecorder::$executeCalls, 'insertRow executes once');
    assert_same(
        $ANDROID_APP,
        \SlimStat\Utils\FakeQueryRecorder::$values['referer'] ?? null,
        'Storage::insertRow must preserve android-app:// referer (routes through sanitize_text_field, not sanitize_url)'
    );

    // ─── Test 2: control — sanitize_url WOULD have stripped it ─────────
    // Documents the root cause: had the referer key used sanitize_url like
    // `resource` does, the value would be emptied.

    assert_same('', sanitize_url($ANDROID_APP), 'sanitize_url strips android-app:// — this is why the fix moved upstream off sanitize_url');

    // ─── Test 3: insertRow still strips HTML tags from referer ─────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::insertRow([
        'referer' => 'android-app://attacker/<script>alert(1)</script>',
        'ip'      => '127.0.0.1',
    ], 'wp_slim_stats');
    $stored = \SlimStat\Utils\FakeQueryRecorder::$values['referer'] ?? '';
    assert_not_contains('<script', $stored, 'sanitize_text_field strips script tags from referer');

    // ─── Test 4: http(s) referer baseline preserved ───────────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::insertRow([
        'referer' => 'https://example.com/?utm_source=x',
        'ip'      => '127.0.0.1',
    ], 'wp_slim_stats');
    assert_same(
        'https://example.com/?utm_source=x',
        \SlimStat\Utils\FakeQueryRecorder::$values['referer'] ?? null,
        'http(s) referer preserved (no regression)'
    );

    fwrite(STDOUT, "OK: {$assertions} assertions passed (Storage android-app:// referer preservation, #306)\n");
    exit(0);
}
