<?php
/** @license GPL-2.0-or-later */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by wp_slimstat_admin::wp_slimstat_include_videos(); local template variables.
if (!defined('ABSPATH')) {
    exit;
}
if (!wp_slimstat_admin::can_view_stats()) {
    return;
}

use SlimStat\Services\Admin\VideoTutorials;

// Every state is rendered here, translated, and hidden until admin/assets/js/videos.js shows it.
// The script fills #ss-videos-rows with DOM nodes built from the validated catalog, never HTML.
$browse = static function (): void {
    ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(VideoTutorials::WATCH_BASE); ?>"><?php esc_html_e('Browse the videos on wp-slimstat.com', 'wp-slimstat'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'wp-slimstat'); ?></span></a><?php
};
?>
<div class="backdrop-container"><div class="wrap-slimstat slimstat-videos">
    <?php wp_slimstat_admin::get_template('header', [
        'is_pro' => wp_slimstat::pro_is_installed(),
        'title'  => __('Video tutorials', 'wp-slimstat'),
        'lead'   => __('Short videos that show how to get more from SlimStat. Each one plays on wp-slimstat.com, in a new tab.', 'wp-slimstat'),
    ]); ?>
    <div id="slimstat-videos" class="ss-videos">
        <noscript>
            <p class="ss-videos-panel"><?php esc_html_e('The video list needs JavaScript.', 'wp-slimstat'); ?> <?php $browse(); ?></p>
        </noscript>

        <section class="ss-videos-panel" data-show="consent" aria-labelledby="ss-videos-consent-title" hidden>
            <h2 id="ss-videos-consent-title"><?php esc_html_e('Load the video list from wp-slimstat.com?', 'wp-slimstat'); ?></h2>
            <p><?php esc_html_e('The tutorials are published on wp-slimstat.com. To list them here, your browser downloads the list and its thumbnails from wp-slimstat.com. Like any web request, this shows wp-slimstat.com your IP address, your browser and the address of this site. SlimStat adds no analytics, cookies or account information.', 'wp-slimstat'); ?></p>
            <p class="ss-videos-actions">
                <button type="button" class="button button-primary" id="ss-videos-show"><?php esc_html_e('Show videos', 'wp-slimstat'); ?></button>
                <?php $browse(); ?>
            </p>
        </section>

        <div class="ss-videos-skeleton" data-show="loading" aria-hidden="true" hidden>
            <span></span><span></span><span></span><span></span>
        </div>

        <div class="ss-videos-panel ss-videos-panel--error" data-show="error" role="alert" hidden>
            <p><strong id="ss-videos-error-title"><?php esc_html_e('The video list could not be loaded.', 'wp-slimstat'); ?></strong> <?php esc_html_e('Check your connection. A firewall or security policy may also be blocking requests to wp-slimstat.com.', 'wp-slimstat'); ?></p>
            <p class="ss-videos-actions">
                <button type="button" class="button" id="ss-videos-retry"><?php esc_html_e('Try again', 'wp-slimstat'); ?></button>
                <?php $browse(); ?>
            </p>
        </div>

        <div class="ss-videos-panel" data-show="empty" hidden>
            <p><?php esc_html_e('No video tutorials have been published yet.', 'wp-slimstat'); ?></p>
        </div>

        <p class="ss-videos-stale" id="ss-videos-stale" hidden><?php esc_html_e('Showing the list saved in this browser, because wp-slimstat.com could not be reached.', 'wp-slimstat'); ?></p>

        <div id="ss-videos-rows" data-show="ready" hidden></div>

        <p id="ss-videos-status" class="screen-reader-text" role="status" aria-live="polite"></p>

        <div class="ss-videos-settings" data-show="consent ready empty error" hidden>
            <p>
                <input type="checkbox" id="ss-videos-auto" aria-describedby="ss-videos-auto-help">
                <label for="ss-videos-auto"><?php esc_html_e('Mark the SlimStat menu with ▶ when new videos are published', 'wp-slimstat'); ?></label>
            </p>
            <p class="description" id="ss-videos-auto-help"><?php esc_html_e('Your browser checks wp-slimstat.com at most once a day. This choice is saved in this browser only.', 'wp-slimstat'); ?></p>
            <p data-show="ready empty error" hidden><button type="button" class="button-link" id="ss-videos-forget"><?php esc_html_e('Stop loading videos in this browser', 'wp-slimstat'); ?></button></p>
        </div>
    </div>
</div></div>
