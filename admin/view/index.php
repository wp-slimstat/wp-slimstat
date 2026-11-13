<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
    exit;
}

use SlimStat\Components\DateRangeHelper;
?>

<div class="backdrop-container">
    <div class="wrap-slimstat">
        <?php
        // Title and lead come from the $screens_info registry so they cannot drift from the menu label.
        $current_screen_info = wp_slimstat_admin::$screens_info[wp_slimstat_admin::$current_screen] ?? [];
        wp_slimstat_admin::get_template('header', [
            'is_pro' => wp_slimstat::pro_is_installed(),
            'title'  => $current_screen_info['title'] ?? '',
            'lead'   => $current_screen_info['lead'] ?? '',
        ]);
        ?>

        <div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px"><?php echo wp_kses_post(__('<strong>AdBlock browser extension detected.</strong> If you see this notice, it means that your browser is not loading our stylesheet and/or JavaScript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://wp-slimstat.com/resources/the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat')); ?></div>

        <?php wp_slimstat_admin::get_template('report-filters'); ?>

        <?php
        // Provider-aware GeoIP notice: show only if a DB-based provider is selected and the database file is missing
        $provider = wp_slimstat::resolve_geolocation_provider();
        $uses_db  = in_array($provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true);
        if ($uses_db && 'on' == wp_slimstat::$settings['notice_geolite']) {
            try {
                $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
                if (!file_exists($service->getProvider()->getDbPath())) {
                    /* translators: %s: URL of the geolocation settings section. */
                    wp_slimstat_admin::show_message(sprintf(__("Geolocation is unavailable for new visits. Previously collected locations remain in reports. <a href='%s' class='noslimstat'>Check Settings</a>.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
                }
            } catch (\Throwable $e) {
                /* translators: %s: URL of the geolocation settings section. */
                wp_slimstat_admin::show_message(sprintf(__("Geolocation is unavailable for new visits. Previously collected locations remain in reports. <a href='%s' class='noslimstat'>Check Settings</a>.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
            }
        }

if (PHP_VERSION_ID >= 70100 && !file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt') && 'on' == wp_slimstat::$settings['notice_browscap']) {
    if ('on' === wp_slimstat::$settings['enable_browscap']) {
        /* translators: %s: URL of the Browscap settings section. */
        wp_slimstat_admin::show_message(sprintf(__("Browscap data is missing. SlimStat is using built-in browser detection. To retry the download now, save Browscap as On in <a href='%s' class='noslimstat'>Settings</a>.", 'wp-slimstat'), wp_slimstat_admin::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
    } else {
        /* translators: %s: URL of the Browscap settings section. */
        wp_slimstat_admin::show_message(sprintf(__("Install our <a href='%s' class='noslimstat'>Browscap Library</a> to identify your visitors' browser and operating system.", 'wp-slimstat'), wp_slimstat_admin::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
    }
}

// Browscap relies on Flysystem's LocalFilesystemAdapter, which constructs
// FinfoMimeTypeDetector unconditionally. Without ext-fileinfo the tracker
// REST endpoint returns HTTP 500 on every hit (#303). Warn the admin when
// Browscap is enabled but the extension is missing.
if ('on' == wp_slimstat::$settings['enable_browscap'] && !\SlimStat\Services\Browscap::has_fileinfo() && 'on' == wp_slimstat::$settings['notice_browscap_fileinfo']) {
    wp_slimstat_admin::show_message(
        sprintf(
            /* translators: 1: opening code tag, 2: closing code tag, 3: opening settings link, 4: closing link tag. */
            __("SlimStat's Browscap browser-detection library requires the PHP %1\$sfileinfo%2\$s extension, which is not enabled on your server. Browser detection has been safely disabled to keep tracking working. Ask your host to enable %1\$sfileinfo%2\$s, or %3\$sturn off the Browscap Library%4\$s in the settings to hide this notice.", 'wp-slimstat'),
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
    /* translators: %s: URL of the caching configuration documentation. */
    wp_slimstat_admin::show_message(sprintf(__("A caching plugin might be enabled on your website. Please <a href='%s' target='_blank' class='noslimstat'>make sure to configure</a> SlimStat Analytics accordingly, to get accurate information.", 'wp-slimstat'), 'https://wp-slimstat.com/resources/i-am-using-w3-total-cache-or-wp-super-cache-hypercache-etc-and-it-looks-like-slimstat-is-not-tra'), 'warning', 'caching');
}

$filters_html = wp_slimstat_reports::get_filters_html(wp_slimstat_db::$filters_normalized['columns']);
if (!empty($filters_html)) {
    echo sprintf("<div id='slimstat-current-filters'>%s</div>", wp_kses_post($filters_html));
}
?>

        <?php wp_slimstat_reports::get_started(); ?>

        <?php if ('slimview5' === wp_slimstat_admin::$current_screen) {
            wp_slimstat_admin::get_template('utm-builder');
        } ?>

        <div class="meta-box-sortables">
            <form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo esc_attr(wp_create_nonce('meta-box-order')) ?>"/></form><?php

    $screen_reports = wp_slimstat_reports::$user_reports[wp_slimstat_admin::$current_screen];
    if ('slimview5' === wp_slimstat_admin::$current_screen) {
        $first = array_values(array_intersect(['slim_p3_03', 'slim_p3_04'], $screen_reports));
        $screen_reports = array_merge($first, array_values(array_diff($screen_reports, $first)));
    }
    foreach ($screen_reports as $a_report_id) {
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
