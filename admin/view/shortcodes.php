<?php
/** @license GPL-2.0-or-later */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variables in wp_slimstat_include_shortcodes().
if (!defined('ABSPATH')) { exit; }
if (!wp_slimstat_admin::can_view_stats()) { return; }

use SlimStat\Shortcodes\Shortcode;

$catalog = Shortcode::catalog();
$is_pro = wp_slimstat::pro_is_installed();
$hints = [];
foreach ($catalog as $id => $item) { if (isset($item['hint'])) { $hints[$id] = get_user_setting('slimstat_sc_hint_' . $id, '') === 'seen'; } }
wp_localize_script('slimstat-shortcodes', 'SlimStatShortcodes', [
    // w="*" and w="count" stay valid in shortcodes but are aliases of "Pageview count" (id).
    'catalog' => array_values(array_diff_key($catalog, ['*' => 0, 'count' => 0])), 'pro' => $is_pro, 'dismissed' => $hints,
    'columns' => wp_slimstat_db::$columns_names, 'operators' => wp_slimstat_db::$operator_names,
    'pricing' => Shortcode::pricingUrl('panel'), 'hintPricing' => Shortcode::pricingUrl('hint'),
    'plugins' => admin_url('plugins.php'), 'compare' => current_user_can('manage_options') ? admin_url('admin.php?page=slimpro') : '',
]);
?>
<div class="backdrop-container"><div class="wrap-slimstat slimstat-shortcodes">
    <?php wp_slimstat_admin::get_template('header', [
        'is_pro' => $is_pro, 'title' => __('Shortcode Playground', 'wp-slimstat'),
        'lead' => __('Show your SlimStat reports on any page or post. Pick one, check the preview, copy the shortcode.', 'wp-slimstat'),
    ]); ?>
    <div class="ss-sc">
        <aside class="ss-sc-catalog" aria-label="<?php esc_attr_e('Shortcode catalog', 'wp-slimstat'); ?>">
            <label for="ss-sc-search"><?php esc_html_e('Search reports', 'wp-slimstat'); ?></label>
            <input type="search" id="ss-sc-search" autocomplete="off">
            <div id="ss-sc-no-results" hidden><p role="status"></p><button type="button" class="button"><?php esc_html_e('Clear search', 'wp-slimstat'); ?></button></div>
            <label class="ss-sc-mobile" for="ss-sc-select"><?php esc_html_e('Report', 'wp-slimstat'); ?></label>
            <select class="ss-sc-mobile" id="ss-sc-select"></select>
            <div id="ss-sc-rail"></div>
        </aside>
        <section class="ss-sc-workspace" aria-labelledby="ss-sc-title">
            <h2 id="ss-sc-title"><?php esc_html_e('Visitors online now', 'wp-slimstat'); ?></h2>
            <p id="ss-sc-description"></p>
            <p id="ss-sc-privacy" hidden><?php esc_html_e('Visible only to people who can view analytics. Visitors see nothing.', 'wp-slimstat'); ?></p>
            <div id="ss-sc-builder">
                <div class="ss-sc-controls">
                    <label id="ss-sc-display-label" for="ss-sc-display"><?php esc_html_e('Display', 'wp-slimstat'); ?><select id="ss-sc-display"></select></label>
                    <label for="ss-sc-period"><?php esc_html_e('Period', 'wp-slimstat'); ?><select id="ss-sc-period"><option value="-30"><?php esc_html_e('Last 30 days', 'wp-slimstat'); ?></option><option value="-7"><?php esc_html_e('Last 7 days', 'wp-slimstat'); ?></option><option value="-90"><?php esc_html_e('Last 90 days', 'wp-slimstat'); ?></option></select></label>
                    <label for="ss-sc-limit"><?php esc_html_e('Limit', 'wp-slimstat'); ?><input id="ss-sc-limit" type="number" min="1" max="100" value="10"></label>
                </div>
                <div id="ss-sc-filters"></div>
                <button type="button" class="button-link" id="ss-sc-add-filter"><?php esc_html_e('+ Add filter', 'wp-slimstat'); ?></button>
            </div>
            <section class="ss-sc-stage" aria-labelledby="ss-sc-preview-label">
                <h3 id="ss-sc-preview-label"><?php esc_html_e('How it looks on your site', 'wp-slimstat'); ?></h3>
                <p class="description"><?php esc_html_e("Your theme's fonts and colors apply on the live page.", 'wp-slimstat'); ?></p>
                <p id="ss-sc-sample" class="ss-sc-chip" hidden><?php esc_html_e('Sample data', 'wp-slimstat'); ?></p>
                <div id="ss-sc-preview" aria-busy="false"><?php echo Shortcode::render(['f' => 'live', 'w' => 'users']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped shortcode output. ?></div>
                <div id="ss-sc-error" role="status" hidden><p></p><button class="button" type="button" id="ss-sc-retry"><?php esc_html_e('Retry', 'wp-slimstat'); ?></button></div>
            </section>
            <div id="ss-sc-unlock" class="slimstat-pro-adds" hidden></div>
            <div id="ss-sc-hint" class="slimstat-pro-adds" hidden></div>
            <div class="ss-sc-codebar">
                <div id="ss-sc-manual" hidden><span><?php esc_html_e('Edited by hand', 'wp-slimstat'); ?></span> <button type="button" class="button-link" id="ss-sc-reset"><?php esc_html_e('Reset to builder', 'wp-slimstat'); ?></button></div>
                <label for="ss-sc-code"><?php esc_html_e('Shortcode', 'wp-slimstat'); ?></label>
                <textarea id="ss-sc-code" rows="3" spellcheck="false" dir="ltr" aria-describedby="ss-sc-code-message"></textarea>
                <p id="ss-sc-code-message" role="status"></p>
                <div class="ss-sc-actions"><button type="button" class="button" id="ss-sc-run"><?php esc_html_e('Preview', 'wp-slimstat'); ?></button><button type="button" class="button button-primary" id="ss-sc-copy"><?php esc_html_e('Copy', 'wp-slimstat'); ?></button><span id="ss-sc-status" class="screen-reader-text" aria-live="polite"></span></div>
            </div>
        </section>
    </div>
</div></div>
