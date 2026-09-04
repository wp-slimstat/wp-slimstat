<?php
/**
 * E2E helper: force the warning-emitting (non-minified) jQuery Migrate build to
 * load regardless of SCRIPT_DEBUG.
 *
 * WordPress ships jquery-migrate.min.js by default (silent). The JQMIGRATE
 * console watchdog (wp56-jquery-migrate-console.spec.ts) needs the dev build,
 * which emits `JQMIGRATE:` console warnings for deprecated jQuery APIs —
 * otherwise the watchdog is a false-green. This mu-plugin rewrites the migrate
 * src to the dev build and guarantees jQuery is enqueued on admin pages.
 */

/**
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here. Ungated, this forces jQuery Migrate onto every page.
 * The file reaching wp-content/mu-plugins is not consent to run it: a helper
 * gets there by an interrupted test run as easily as a deliberate one.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

add_filter('script_loader_src', function ($src, $handle) {
    if ('jquery-migrate' === $handle && is_string($src)) {
        $src = preg_replace('/\.min\.js(\?|$)/', '.js$1', $src);
    }
    return $src;
}, 10, 2);

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('jquery');
}, 1);
