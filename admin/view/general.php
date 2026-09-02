<?php
if (!function_exists('add_action')) {
    exit();
}

// wp_slimstat_db::$pageviews is populated in init(); the dashboard-widget path
// can render before that has happened, same guard used in get_overview_summary().
if (0 === wp_slimstat_db::$pageviews) {
    wp_slimstat_db::$pageviews = wp_slimstat_db::count_records();
}

$is_pro = wp_slimstat::pro_is_installed();
?>

<div class="backdrop-container">
    <div class="wrap-slimstat">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => $is_pro]); ?>

        <?php wp_slimstat_admin::get_template('filters-and-daterange'); ?>

        <?php
        $filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
        if (!empty($filters_html)) {
            echo sprintf("<div id='slimstat-current-filters'>%s</div>", $filters_html);
        }
        ?>

        <div class="slimstat-general-page">
            <div class="page">
                <div class="page-title">
                    <h3><?php esc_html_e('General', 'wp-slimstat'); ?></h3>
                </div>

                <?php
                // Same report_header()/callback_wrapper()/report_footer() loop
                // over wp_slimstat_reports::$user_reports every other screen
                // uses (admin/view/index.php) — General's boxes are real,
                // Customize-page-draggable report entries (slim_p10_01..08,
                // registered in wp_slimstat_reports::init()) instead of
                // hardcoded inline markup. This is also what makes the
                // pageviews chart's granularity dropdown work: the loop wraps
                // every box in the same .postbox > .inside structure
                // slimstat-chart.js's fetchChartData() requires to find its
                // refresh target.
                ?>
                <div class="meta-box-sortables">
                    <?php
                    foreach (wp_slimstat_reports::$user_reports['slimgeneral'] ?? [] as $a_report_id) {
                        // A report could have been deprecated...
                        if (empty(wp_slimstat_reports::$reports[$a_report_id])) {
                            continue;
                        }

                        wp_slimstat_reports::report_header($a_report_id);
                        wp_slimstat_reports::callback_wrapper(['id' => $a_report_id]);
                        wp_slimstat_reports::report_footer();
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <div id="slimstat-modal-dialog"></div>
</div>
