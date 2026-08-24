<?php
if (!function_exists('add_action')) {
    exit();
}
?>

<div class="backdrop-container">
    <div class="wrap-slimstat">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]); ?>

        <?php wp_slimstat_admin::get_template('filters-and-daterange'); ?>

        <?php
        // Provider-aware GeoIP notice: show only if a DB-based provider is selected and the database file is missing
        $provider = wp_slimstat::resolve_geolocation_provider();
        $uses_db  = in_array($provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true);
        if ($uses_db && 'on' == wp_slimstat::$settings['notice_geolite']) {
            try {
                $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
                if (!file_exists($service->getProvider()->getDbPath())) {
                    wp_slimstat_admin::show_message(sprintf(__("GeoIP collection is not enabled. Please go to <a href='%s' class='noslimstat'>setting page</a> to enable GeoIP for getting more information and location (country) from the visitor.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
                }
            } catch (\Throwable $e) {
                wp_slimstat_admin::show_message(sprintf(__("GeoIP collection is not enabled. Please go to <a href='%s' class='noslimstat'>setting page</a> to enable GeoIP for getting more information and location (country) from the visitor.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
            }
        }

if (PHP_VERSION_ID >= 70100 && !file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt') && 'on' == wp_slimstat::$settings['notice_browscap']) {
    wp_slimstat_admin::show_message(sprintf(__("Install our <a href='%s' class='noslimstat'>Browscap Library</a> to identify your visitors' browser and operating system.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
}

// Browscap relies on Flysystem's LocalFilesystemAdapter, which constructs
// FinfoMimeTypeDetector unconditionally. Without ext-fileinfo the tracker
// REST endpoint returns HTTP 500 on every hit (#303). Warn the admin when
// Browscap is enabled but the extension is missing.
if ('on' == wp_slimstat::$settings['enable_browscap'] && !extension_loaded('fileinfo') && 'on' == wp_slimstat::$settings['notice_browscap_fileinfo']) {
    wp_slimstat_admin::show_message(
        sprintf(
            __("Slimstat's Browscap browser-detection library requires the PHP %1\$sfileinfo%2\$s extension, which is not enabled on your server. Browser detection has been safely disabled to keep tracking working — ask your host to enable %1\$sfileinfo%2\$s, or %3\$sturn off the Browscap Library%4\$s in the settings to hide this notice.", 'wp-slimstat'),
            '<code>',
            '</code>',
            "<a href='" . esc_url(self::$config_url . '2#wp-slimstat-third-party-libraries') . "' class='noslimstat'>",
            '</a>'
        ),
        'warning',
        'browscap_fileinfo'
    );
}

// Path to wp-content folder, used to detect caching plugins via advanced-cache.php
if (file_exists(dirname(plugin_dir_path(__FILE__), 4) . '/advanced-cache.php') && 'on' == wp_slimstat::$settings['notice_caching'] && (empty(wp_slimstat::$settings['javascript_mode']) || 'on' != wp_slimstat::$settings['javascript_mode'])) {
    wp_slimstat_admin::show_message(sprintf(__("A caching plugin might be enabled on your website. Please <a href='%s' target='_blank' class='noslimstat'>make sure to configure</a> Slimstat Analytics accordingly, to get accurate information.", 'wp-slimstat'), 'https://wp-slimstat.com/resources/i-am-using-w3-total-cache-or-wp-super-cache-hypercache-etc-and-it-looks-like-slimstat-is-not-tra'), 'warning', 'caching');
}

$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
if (!empty($filters_html)) {
    echo sprintf("<div id='slimstat-current-filters'>%s</div>", $filters_html);
}
?>

        <?php
        // Lightweight page framing so a screen's report boxes read as one feature,
        // not adjacent boxes (FN-13). Any screen that declares a 'lead' in its
        // $screens_info registry entry gets an H1 (from 'title') + lead; today
        // only Goals & Funnels does. Title pulled from the registry so it can't
        // drift from the menu label (cf. layout.php).
        $current_screen_info = wp_slimstat_admin::$screens_info[wp_slimstat_admin::$current_screen] ?? [];
        if (!empty($current_screen_info['lead'])) : ?>
            <div class="slimstat-gf-pageintro">
                <h1 class="slimstat-gf-pageintro__title"><?php echo esc_html($current_screen_info['title']); ?></h1>
                <p class="slimstat-gf-pageintro__lead"><?php echo esc_html($current_screen_info['lead']); ?></p>
            </div>
        <?php endif; ?>

        <div class="meta-box-sortables">
            <form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>"/></form><?php

    foreach (wp_slimstat_reports::$user_reports[wp_slimstat_admin::$current_screen] as $a_report_id) {
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
    <div id="slimstat-modal-dialog"></div>
</div>
