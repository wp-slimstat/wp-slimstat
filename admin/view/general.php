<?php
if (!function_exists('add_action')) {
    exit();
}

use SlimStat\Helpers\GeneralPageData;

// wp_slimstat_db::$pageviews is populated in init(); the dashboard-widget path
// can render before that has happened, same guard used in get_overview_summary().
if (0 === wp_slimstat_db::$pageviews) {
    wp_slimstat_db::$pageviews = wp_slimstat_db::count_records();
}

$is_pro = wp_slimstat::pro_is_installed();

$unique_visitors    = wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1');
$single_page_visits = wp_slimstat_db::count_records_having('visit_id', 'browser_type <> 1', 'COUNT(id) = 1');

// The bucketed (approximate) average is the plugin's one existing source for
// this number — see get_visits_duration()'s trailing 'Average Visit Duration'
// row. Reused as-is rather than re-derived, since every duration primitive in
// wp_slimstat_db is network-merge-aware (NetworkMerge::isMerging()) and a new
// raw query here could silently disagree with the rest of the plugin on a
// merged multisite install. Its 7 duration-bucket rows each carry the
// human-visit count for that bucket, and every human visit falls into
// exactly one bucket — summing them reconstructs $total_human_visits for the
// bounce-rate math below without a second count_records() call for the same
// 'visit_id > 0 AND browser_type <> 1' condition get_visits_duration() already runs internally.
$duration_rows       = wp_slimstat_db::get_visits_duration();
$avg_duration_row    = end($duration_rows);
$avg_duration        = ($avg_duration_row['metric'] ?? '') === __('Average Visit Duration', 'wp-slimstat')
    ? $avg_duration_row['value']
    : '0:00';
$total_human_visits  = GeneralPageData::sumCounthits($duration_rows);
$bounce_rate         = GeneralPageData::bounceRate($total_human_visits, $single_page_visits);

// "Where visitors come from": Direct + Search are real, composable counts.
// Every other referrer is merged into one "Other referrers" row rather than
// classified into Social/Referral, which the plugin does not track (would
// require new URL-pattern matching against known social domains).
$direct_count = wp_slimstat_db::count_records('id', 'resource IS NULL');
$search_count = wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL');
$other_referrers_count = GeneralPageData::otherReferrersCount((int) wp_slimstat_db::$pageviews, $direct_count, $search_count);

$traffic_rows = GeneralPageData::trafficRows(
    $direct_count,
    $search_count,
    $other_referrers_count,
    __('Direct', 'wp-slimstat'),
    __('Search', 'wp-slimstat'),
    __('Other referrers', 'wp-slimstat')
);
$traffic_total = GeneralPageData::sumCounthits($traffic_rows);
[$traffic_shown, $traffic_blurred] = GeneralPageData::splitRows($traffic_rows, $is_pro);

// "Your most visited pages" — same call as slim_p1_08 "Top Web Pages".
$top_pages       = wp_slimstat_db::get_top('resource');
$top_pages_total = GeneralPageData::sumCounthits($top_pages);
[$pages_shown, $pages_blurred] = GeneralPageData::splitRows($top_pages, $is_pro);

// "Where your visitors are" — same call as slim_p1_13 "Top Countries".
$top_countries       = wp_slimstat_db::get_top('country');
$top_countries_total = GeneralPageData::sumCounthits($top_countries);
[$countries_shown, $countries_blurred] = GeneralPageData::splitRows($top_countries, $is_pro);

// One config per "top N" box, rendered by the single loop below instead of
// three near-identical copies of the same empty/rows/rows-blur/unlock-foot
// markup. 'field' is the row key holding the label text; 'show_count' hides
// the raw counthits number for the countries box, which shows percentage only.
$boxes = [
    [
        'title'          => __('Where visitors come from', 'wp-slimstat'),
        'field'          => 'label',
        'show_count'     => true,
        'empty_body'     => __('We will group your visitors by Direct, Search and other referrers once they arrive.', 'wp-slimstat'),
        'all_rows'       => $traffic_rows,
        'shown'          => $traffic_shown,
        'blurred'        => $traffic_blurred,
        'total'          => $traffic_total,
    ],
    [
        'title'          => __('Your most visited pages', 'wp-slimstat'),
        'field'          => 'resource',
        'show_count'     => true,
        'use_field_as_title_attr' => true,
        'empty_body'     => __('Your pages will be ranked here by how often they are viewed.', 'wp-slimstat'),
        'all_rows'       => $top_pages,
        'shown'          => $pages_shown,
        'blurred'        => $pages_blurred,
        'total'          => $top_pages_total,
    ],
    [
        'title'          => __('Where your visitors are', 'wp-slimstat'),
        'field'          => 'country',
        'show_count'     => false,
        'empty_body'     => __('Countries appear here as soon as someone visits.', 'wp-slimstat'),
        'all_rows'       => $top_countries,
        'shown'          => $countries_shown,
        'blurred'        => $countries_blurred,
        'total'          => $top_countries_total,
    ],
];

$lock_icon_sm = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

// Renders one box's row list (shown, optionally + blurred/unlock-foot) or its
// empty state. A closure, not a global function, for the same reason
// splitRows()/sumCounthits() moved to GeneralPageData: this file is
// include()d by wp_slimstat_include_general() and a bare `function`
// declaration here would fatal if the page ever rendered twice in one request.
$render_box_rows = static function (array $box) use ($lock_icon_sm): void {
    if (empty($box['all_rows'])) {
        echo '<div class="empty"><span class="big">' . esc_html__('No data yet', 'wp-slimstat') . '</span><span>' . esc_html($box['empty_body']) . '</span></div>';
        return;
    }

    $render_rows = static function (array $rows) use ($box): void {
        foreach ($rows as $a_row) {
            $pct = $box['total'] > 0 ? round(100 * $a_row['counthits'] / $box['total']) : 0;
            $title_attr = !empty($box['use_field_as_title_attr']) ? ' title="' . esc_attr($a_row[$box['field']]) . '"' : '';
            echo '<div class="row-i"' . $title_attr . '>';
            echo '<span class="bar" style="width:' . esc_attr($pct) . '%"></span>';
            echo '<span class="name"><span class="txt">' . esc_html($a_row[$box['field']]) . '</span></span>';
            if ($box['show_count']) {
                echo '<span class="n">' . esc_html(number_format_i18n($a_row['counthits'])) . '<span class="pct">' . esc_html($pct) . '%</span></span>';
            } else {
                echo '<span class="n">' . esc_html($pct) . '%</span>';
            }
            echo '</div>';
        }
    };

    echo '<div class="rows">';
    $render_rows($box['shown']);
    echo '</div>';

    if (!empty($box['blurred'])) {
        echo '<div class="rows rows-blur" aria-hidden="true">';
        $render_rows($box['blurred']);
        echo '</div>';
        echo '<button type="button" class="unlock-foot" data-upgrade>' . $lock_icon_sm . esc_html__('Unlock full report with Pro', 'wp-slimstat') . '</button>';
    }
};
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

                <div class="stats">
                    <div class="stat">
                        <span class="lbl"><?php esc_html_e('Unique Visitors', 'wp-slimstat'); ?></span>
                        <span class="val"><?php echo esc_html(number_format_i18n($unique_visitors)); ?></span>
                        <span class="sub"><?php esc_html_e('separate people, counted once each', 'wp-slimstat'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="lbl"><?php esc_html_e('Pageviews', 'wp-slimstat'); ?></span>
                        <span class="val"><?php echo esc_html(number_format_i18n(wp_slimstat_db::$pageviews)); ?></span>
                        <span class="sub"><?php esc_html_e('pages they looked at', 'wp-slimstat'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="lbl"><?php esc_html_e('Bounce Rate', 'wp-slimstat'); ?></span>
                        <span class="val"><?php echo esc_html(number_format_i18n($bounce_rate, 0)); ?>%</span>
                        <span class="sub"><?php esc_html_e('left after one page', 'wp-slimstat'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="lbl"><?php esc_html_e('Avg. Visit Duration', 'wp-slimstat'); ?></span>
                        <span class="val"><?php echo esc_html($avg_duration); ?></span>
                        <span class="sub"><?php esc_html_e('time spent per visit', 'wp-slimstat'); ?></span>
                    </div>
                </div>

                <?php
                if (class_exists('SlimStat\\Modules\\Chart')) {
                    $chart = new \SlimStat\Modules\Chart();
                    $chart->showChart([
                        'id'         => 'slim_general_pageviews',
                        'chart_data' => [
                            'data1' => 'COUNT( ip )',
                            'data2' => 'COUNT( DISTINCT ip )',
                        ],
                        'chart_labels' => [
                            __('Pageviews', 'wp-slimstat'),
                            (('on' == (wp_slimstat::$settings['hash_ip'] ?? 'off')) ? __('Unique Visitors', 'wp-slimstat') : __('Unique IPs', 'wp-slimstat')),
                        ],
                    ]);
                }
                ?>

                <div class="boxes">
                    <?php foreach ($boxes as $a_box) : ?>
                        <section class="box">
                            <div class="box-head"><span class="t"><?php echo esc_html($a_box['title']); ?></span></div>
                            <?php $render_box_rows($a_box); ?>
                        </section>
                    <?php endforeach; ?>

                    <section class="box">
                        <div class="box-head"><span class="t"><?php esc_html_e('Devices & browsers', 'wp-slimstat'); ?></span></div>
                        <div class="empty">
                            <span class="big"><?php esc_html_e('No data yet', 'wp-slimstat'); ?></span>
                            <span><?php esc_html_e('Device type is not tracked in this version of SlimStat.', 'wp-slimstat'); ?></span>
                        </div>
                    </section>
                </div>

                <section class="box" style="margin-bottom:18px">
                    <div class="box-head">
                        <span class="t"><?php esc_html_e('Campaigns you are running', 'wp-slimstat'); ?></span>
                        <span style="font-size:11px;color:var(--ss-text-muted)"><?php esc_html_e('from utm_source / utm_campaign', 'wp-slimstat'); ?></span>
                    </div>
                    <div class="empty">
                        <span class="big"><?php esc_html_e('No data yet', 'wp-slimstat'); ?></span>
                        <span><?php esc_html_e('Campaign tracking is not collected in this version of SlimStat.', 'wp-slimstat'); ?></span>
                    </div>
                </section>

                <?php if (!$is_pro) : ?>
                    <section class="pro-card" data-upgrade tabindex="0" role="button" aria-label="<?php esc_attr_e('Unlock Goals and Custom Events with Pro', 'wp-slimstat'); ?>">
                        <div class="blurred" aria-hidden="true">
                            <div style="font-size:15px;font-weight:700;margin-bottom:14px"><?php esc_html_e('Goals & Custom Events', 'wp-slimstat'); ?></div>
                        </div>
                        <div class="veil">
                            <span class="lock">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <span class="cap"><?php esc_html_e('See which actions turn visitors into customers', 'wp-slimstat'); ?></span>
                            <button class="btn-pro" type="button" data-upgrade><?php esc_html_e('Unlock with Pro', 'wp-slimstat'); ?></button>
                        </div>
                    </section>
                    <div class="slimstat-general-scrim"></div>
                    <?php wp_slimstat_admin::get_template('slimstat-pro-modal'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div id="slimstat-modal-dialog"></div>
</div>
