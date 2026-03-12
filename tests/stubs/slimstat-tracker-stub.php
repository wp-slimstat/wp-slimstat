<?php
/**
 * Namespaced stubs for SlimStat\Tracker and SlimStat\Services\Privacy.
 * Must be in a separate file because PHP requires namespace declarations at the top.
 */
namespace SlimStat\Tracker {
    if (!class_exists(__NAMESPACE__ . '\Tracker')) {
        class Tracker {
            public static function slimtrack_ajax() { return '1'; }
        }
    }
}

namespace SlimStat\Services\Privacy {
    if (!class_exists(__NAMESPACE__ . '\ConsentHandler')) {
        class ConsentHandler {
            public static function handleBannerConsent($json = true, $data = null) { return true; }
        }
    }
}
