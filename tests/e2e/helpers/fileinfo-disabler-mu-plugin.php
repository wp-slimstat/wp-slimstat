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
