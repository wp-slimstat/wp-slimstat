<?php
/**
 * Plugin Name: Slimstat E2E — fileinfo extension disabler
 * Description: Shadows extension_loaded('fileinfo') inside the
 *   SlimStat\Services namespace to simulate hosts without ext-fileinfo
 *   for issue #303 regression coverage. Loads before wp-slimstat so the
 *   namespaced function resolves before Browscap.php is autoloaded.
 *
 * The shim only intercepts the literal extension name 'fileinfo'; every
 * other lookup falls back to the real PHP built-in so unrelated runtime
 * checks (json, mbstring, etc.) remain truthful.
 */

namespace SlimStat\Services {
    /**
     * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here. Ungated, this
     * makes ext-fileinfo look absent to the whole SlimStat\Services namespace, so
     * Browscap takes its no-fileinfo path on a site nobody is testing.
     *
     * The guard is inside the braced namespace, and gates the DEFINITION rather than
     * returning: a top-level return inside a braced namespace block is not the same
     * statement it is in a plain file, and shadowing a built-in is not something to
     * be clever about. The file reaching wp-content/mu-plugins is not consent to run
     * it — a helper gets there by an interrupted test run as easily as a deliberate one.
     */
    if ((!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING)) {
        return;
    }

    if (!function_exists('SlimStat\\Services\\extension_loaded')) {
        function extension_loaded(string $extension): bool
        {
            if ('fileinfo' === $extension) {
                return false;
            }
            return \extension_loaded($extension);
        }
    }
}
