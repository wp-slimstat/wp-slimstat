<?php
/**
 * Namespaced stubs for SlimStat\Tracker and SlimStat\Services\Privacy.
 * Must be in a separate file because PHP requires namespace declarations at the top.
 */
namespace SlimStat\Tracker {
    /**
     * Thrown by the Tracker stub to prevent exit() from killing the test process.
     * Tests catch this to verify $raw_post_array state after handle_tracking merges payloads.
     */
    class TrackerStubExitException extends \RuntimeException {}

    if (!class_exists(__NAMESPACE__ . '\Tracker')) {
        class Tracker {
            public static function slimtrack_ajax() {
                // Throw instead of returning — prevents handle_tracking from calling exit()
                throw new TrackerStubExitException('tracker_stub_exit');
            }
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
