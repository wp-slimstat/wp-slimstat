<?php
// Brings the FULL report set into wp_slimstat_reports::$reports for the harness,
// with the request context the report system assumes.
//
// Reports live in two registries. The legacy array is populated by
// wp_slimstat_reports::init(). The newer OO registry (src/Reports/) is merged in
// by LegacyReportAdapter, which hooks `slimstat_reports_info` at priority 999 —
// but only once Bootstrap::init() has run, and admin/index.php:245-254 gates
// that on the request being a SlimStat admin screen or one of two AJAX actions.
//
// `wp eval-file` is neither, so without this the OO reports are simply absent,
// and the harness reports a clean run over a report set that silently excludes
// them. Scorecard v0 was captured with that gap: 65 legacy reports, and not
// slim_live_analytics.
//
// Calling Bootstrap::init() directly is enough — the gate lives in
// admin/index.php, not in Bootstrap, which reads no superglobals.
//
// No declare(strict_types=1): callers are eval()'d by `wp eval-file`.

if (!function_exists('slimstat_bench_bootstrap_reports')) {
    /**
     * @return array<string, array<string, mixed>> the merged report registry
     */
    function slimstat_bench_bootstrap_reports(): array
    {
        // Must precede the registry: ReportRegistry::__construct() calls
        // load_user_reports(), which reads per-user meta. Reports are also
        // capability-gated inside callback_wrapper(), so without a user every
        // cell would render nothing and report a flattering zero.
        if (!is_user_logged_in()) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if ($admins) {
                wp_set_current_user((int) $admins[0]);
            }
        }

        if (class_exists('\SlimStat\Reports\Bootstrap')) {
            try {
                \SlimStat\Reports\Bootstrap::get_instance()->init();
            } catch (\Throwable $e) {
                // A failure here means fewer reports, not wrong ones — surface it
                // rather than silently measuring a smaller set.
                echo 'WARNING: Reports\\Bootstrap::init() failed — OO reports will be missing ('
                    . $e->getMessage() . ")\n";
            }
        }

        if (!class_exists('wp_slimstat_reports')) {
            require_once SLIMSTAT_ANALYTICS_DIR . 'admin/view/wp-slimstat-reports.php';
        }

        wp_slimstat_reports::init();

        return wp_slimstat_reports::$reports;
    }
}
