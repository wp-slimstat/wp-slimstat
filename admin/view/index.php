<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
    exit;
}

use SlimStat\Components\DateRangeHelper;
?>

<div class="backdrop-container">
    <div class="wrap-slimstat">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]); ?>

        <div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px"><?php echo wp_kses_post(__('<strong>AdBlock browser extension detected.</strong> If you see this notice, it means that your browser is not loading our stylesheet and/or JavaScript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://wp-slimstat.com/resources/the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat')); ?></div>

        <form action="<?php echo esc_url(wp_slimstat_reports::fs_url()); ?>" method="post" id="slimstat-filters-form">
            <fieldset id="slimstat-filters"><?php
                $filter_name_html = '<div class="form-field"><select name="f" id="slimstat-filter-name" aria-label="' . esc_attr__('Filter dimension', 'wp-slimstat') . '"><option value="" disabled selected>' . esc_html__('Dimension', 'wp-slimstat') . '</option>';
foreach (wp_slimstat_db::$columns_names as $a_filter_label => $a_filter_info) {
    $filter_name_html .= sprintf("<option value='%s'>%s</option>", esc_attr($a_filter_label), esc_html($a_filter_info[0]));
}
$filter_name_html .= '</select></div>';

$filter_operator_html = '<div class="form-field"><select name="o" id="slimstat-filter-operator" aria-label="' . esc_attr__('Filter operator', 'wp-slimstat') . '">';
foreach (wp_slimstat_db::$operator_names as $a_operator_label => $a_operator_name) {
    $filter_operator_html .= sprintf("<option value='%s'>%s</option>", esc_attr($a_operator_label), esc_html($a_operator_name));
}
$filter_operator_html .= '</select></div>';

$filter_value_html = '<div class="form-field">
    <input type="text" class="text" name="v" id="slimstat-filter-value" value="" size="20" aria-label="' . esc_attr__('Filter value', 'wp-slimstat') . '">
</div>';

if ('on' == wp_slimstat::$settings['enable_sov']) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed input/select markup; option values and labels escaped at construction above.
    echo $filter_value_html . $filter_operator_html . $filter_name_html;
} else {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same escaped option fragments, ordered for subject-object-verb languages.
    echo $filter_name_html . $filter_operator_html . $filter_value_html;
}

echo '<input type="submit" value="' . esc_attr__('Apply', 'wp-slimstat') . '" class="button-secondary">';

$saved_filters = get_option('slimstat_filters', []);
if (!empty($saved_filters)) {
    echo '<a href="#" id="slimstat-load-saved-filters" class="button-secondary noslimstat" title="' . esc_attr__('Saved Filters', 'wp-slimstat') . '">' . esc_html__('Saved Filters', 'wp-slimstat') . '</a>';
}
?></fieldset><!-- #slimstat-filters -->

            <?php
            $current_range = DateRangeHelper::get_current_date_range();
            wp_slimstat_admin::get_template('date-range-picker', [
                'label' => DateRangeHelper::format_date_range($current_range['start'], $current_range['end'], $current_range['preset']),
                'start' => gmdate('Y-m-d', (int) wp_slimstat_db::$filters_normalized['utime']['start']),
                'end'   => gmdate('Y-m-d', (int) wp_slimstat_db::$filters_normalized['utime']['end']),
            ]);
            ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['columns'] as $a_key => $a_details) : ?>
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars preserves literal entities in the escaped attribute. ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="<?php echo htmlspecialchars($a_details[0] . ' ' . $a_details[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"/>
            <?php endforeach ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['date'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter slimstat-date-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
            <?php endif;
            endforeach; ?>

            <?php foreach (wp_slimstat_db::$filters_normalized['misc'] as $a_key => $a_value) : if (!empty($a_value)) : ?>
                <input type="hidden" name="fs[<?php echo esc_attr($a_key); ?>]" class="slimstat-post-filter" value="equals <?php echo esc_attr($a_value) ?>"/>
            <?php endif;
            endforeach; ?>
        </form>

        <?php
        // Provider-aware GeoIP notice: show only if a DB-based provider is selected and the database file is missing
        $provider = wp_slimstat::resolve_geolocation_provider();
        $uses_db  = in_array($provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true);
        if ($uses_db && 'on' == wp_slimstat::$settings['notice_geolite']) {
            try {
                $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
                if (!file_exists($service->getProvider()->getDbPath())) {
                    /* translators: %s: URL of the geolocation settings section. */
                    wp_slimstat_admin::show_message(sprintf(__("Geolocation is off, so reports show no countries or cities. <a href='%s' class='noslimstat'>Turn it on in Settings</a>.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
                }
            } catch (\Throwable $e) {
                /* translators: %s: URL of the geolocation settings section. */
                wp_slimstat_admin::show_message(sprintf(__("Geolocation is off, so reports show no countries or cities. <a href='%s' class='noslimstat'>Turn it on in Settings</a>.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'geolite');
            }
        }

if (PHP_VERSION_ID >= 70100 && !file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt') && 'on' == wp_slimstat::$settings['notice_browscap']) {
    /* translators: %s: URL of the Browscap settings section. */
    wp_slimstat_admin::show_message(sprintf(__("Install our <a href='%s' class='noslimstat'>Browscap Library</a> to identify your visitors' browser and operating system.", 'wp-slimstat'), self::$config_url . '2#wp-slimstat-third-party-libraries'), 'warning', 'browscap');
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

        <?php if ('slimview5' === wp_slimstat_admin::$current_screen) {
            wp_slimstat_admin::get_template('utm-builder');
        } ?>

        <div class="meta-box-sortables">
            <form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo esc_attr(wp_create_nonce('meta-box-order')) ?>"/></form><?php

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
