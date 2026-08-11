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

        // ASYNC_LOAD OFF, OR THE HARNESS MEASURES NOTHING.
        //
        // `raw_results_to_html()` returns '' when `async_load` is 'on' and the request is not
        // AJAX — the report is meant to arrive over admin-ajax afterwards. `async_load` DEFAULTS
        // TO 'on' for new installs (EXPECTED-DIFFS R7), the bench container is a new install, and
        // 52 report definitions route through that method.
        //
        // MEASURED, on a 153,317-row corpus, before this line existed:
        //
        //     async_load 'on'   50 of 65 reports rendered NOTHING    98,724 bytes
        //     async_load 'no'    1 of 65                            320,017 bytes
        //
        // Two empty renders hash identically. So fifty reports were reporting PARITY on every run
        // regardless of what the code did — the oracle's largest blind spot, and one it could not
        // report, because "identical" is exactly what it saw. The capability gate above was
        // already handled with a comment saying "without a user every cell would render nothing
        // and report a flattering zero"; this is the same hazard, one line further down, and it
        // was not.
        //
        // Set on the in-memory settings rather than the option: the harness must not leave a
        // container configured differently from the product it is measuring.
        wp_slimstat::$settings['async_load'] = 'no';

        wp_slimstat_reports::init();

        return wp_slimstat_reports::$reports;
    }
}
