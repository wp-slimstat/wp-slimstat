<?php
/**
 * Source-level verification: Browscap fileinfo extension preflight (#303).
 *
 * Without ext-fileinfo, Flysystem's LocalFilesystemAdapter eagerly constructs
 * FinfoMimeTypeDetector (`new finfo(...)`), which fatals with a `Class "finfo"
 * not found` Error. The surrounding `catch (\Exception)` did not catch it, so
 * every tracker hit returned HTTP 500. The fix gates the Browscap path on
 * `extension_loaded('fileinfo')` and widens the catch to `\Throwable`.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/303
 */

declare(strict_types=1);

$assertions = 0;

function fileinfo_assert(bool $condition, string $msg): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$browscap_src = file_get_contents(dirname(__DIR__) . '/src/Services/Browscap.php');
fileinfo_assert(false !== $browscap_src, 'Could not read src/Services/Browscap.php');

fileinfo_assert(
    (bool) preg_match(
        "/function\\s+get_browser\\s*\\([^)]*\\)\\s*\\{[\\s\\S]*?enable_browscap[\\s\\S]*?extension_loaded\\(\\s*'fileinfo'\\s*\\)/",
        $browscap_src
    ),
    "TEST 1: get_browser() entry gate must call extension_loaded('fileinfo')"
);

// Defense-in-depth: get_browser_from_browscap() is `public static`, so
// third-party code or tests can call it directly bypassing the entry gate.
fileinfo_assert(
    (bool) preg_match(
        "/function\\s+get_browser_from_browscap\\s*\\([^)]*\\)\\s*\\{[\\s\\S]*?!\\s*extension_loaded\\(\\s*'fileinfo'\\s*\\)[\\s\\S]*?return\\s+\\\$_browser/",
        $browscap_src
    ),
    'TEST 2: get_browser_from_browscap() must early-return when ext-fileinfo missing'
);

fileinfo_assert(
    (bool) preg_match(
        "/function\\s+get_browser_from_browscap[\\s\\S]*?catch\\s*\\(\\s*\\\\?Throwable\\s+/",
        $browscap_src
    ),
    'TEST 3: get_browser_from_browscap() catch must be \\Throwable, not Exception'
);

fileinfo_assert(
    !(bool) preg_match(
        "/function\\s+get_browser_from_browscap[\\s\\S]*?catch\\s*\\(\\s*Exception\\s+/",
        $browscap_src
    ),
    'TEST 3b: Old narrow `catch (Exception ...)` must be removed from get_browser_from_browscap'
);

fileinfo_assert(
    (bool) preg_match(
        "/catch\\s*\\(\\s*\\\\?Throwable[\\s\\S]{0,200}?wp_slimstat::log\\s*\\(/",
        $browscap_src
    ),
    'TEST 4: \\Throwable catch must call wp_slimstat::log() (gated on WP_DEBUG), not raw error_log()'
);

$view_src = file_get_contents(dirname(__DIR__) . '/admin/view/index.php');
fileinfo_assert(false !== $view_src, 'Could not read admin/view/index.php');

fileinfo_assert(
    false !== strpos($view_src, "notice_browscap_fileinfo"),
    'TEST 5a: admin/view/index.php must reference notice_browscap_fileinfo setting'
);
fileinfo_assert(
    (bool) preg_match(
        "/enable_browscap[\\s\\S]{0,200}?!\\s*extension_loaded\\(\\s*'fileinfo'\\s*\\)[\\s\\S]{0,200}?notice_browscap_fileinfo/",
        $view_src
    ),
    'TEST 5b: Notice condition must combine enable_browscap, missing fileinfo, and notice flag'
);
fileinfo_assert(
    false !== strpos($view_src, "'browscap_fileinfo'"),
    'TEST 5c: show_message() call must use distinct dismiss handle browscap_fileinfo (not browscap)'
);

$plugin_src = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
fileinfo_assert(false !== $plugin_src, 'Could not read wp-slimstat.php');
fileinfo_assert(
    (bool) preg_match("/'notice_browscap_fileinfo'\\s*=>\\s*'on'/", $plugin_src),
    'TEST 6: wp-slimstat.php defaults must include notice_browscap_fileinfo => on'
);

$admin_src = file_get_contents(dirname(__DIR__) . '/admin/index.php');
fileinfo_assert(false !== $admin_src, 'Could not read admin/index.php');
fileinfo_assert(
    (bool) preg_match(
        "/'slimstat_notice_browscap_fileinfo'\\s*=>\\s*'notices_handler'/",
        $admin_src
    ),
    "TEST 7: admin/index.php must register slimstat_notice_browscap_fileinfo => notices_handler"
);

echo "All {$assertions} assertions passed in browscap-fileinfo-preflight-test.php\n";
