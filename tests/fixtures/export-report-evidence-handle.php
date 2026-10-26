<?php
namespace SlimStat\Migration {
    // The preserved Free 5.5.1 arm has this class, but predates analyticsConnection().
    class MigrationService
    {
    }
}

namespace {
    class wpdb
    {
    }

    class wp_slimstat
    {
        public static $wpdb;
    }

    function apply_filters($hook, $value)
    {
        return $value;
    }

    $core = new wpdb();
    $custom = new wpdb();
    $GLOBALS['wpdb'] = $core;
    wp_slimstat::$wpdb = $custom;

    define('SLIMSTAT_EVIDENCE_EXPORT_LIBRARY', true);
    require dirname(__DIR__) . '/bench/export-report-evidence.php';

    if (slimstat_evidence_analytics_handle() !== $custom) {
        fwrite(STDERR, "FAIL: export evidence did not select the active custom analytics handle\n");
        exit(1);
    }

    echo "PASS: export evidence selects the old arm's custom analytics handle\n";
}
