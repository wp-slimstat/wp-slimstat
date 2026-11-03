<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Services\Admin;

/**
 * SlimStat > Videos: tutorials listed from a static catalog on wp-slimstat.com.
 *
 * This class never touches the network or the database. The browser fetches the catalog
 * (admin/assets/js/video-catalog.js), and only after the user opts in on the Videos page; the
 * opt-in, the saved catalog and the record of which videos the user has seen all live in that
 * browser's storage. Here: where the catalog is, the built-in categories, and the enqueues.
 *
 * Publishing, editing or reordering videos is a change to the website's JSON file, never a
 * plugin release. The format is documented in docs/video-tutorials/.
 */
final class VideoTutorials
{
    public const SCREEN = 'slimvideos';

    /** The only host the feature talks to. Thumbnails must live here; watch links are built here. */
    public const ORIGIN = 'https://wp-slimstat.com';

    public const CATALOG_URL = self::ORIGIN . '/videos/videos.json';

    /** Watch pages are WATCH_BASE . $slug . '/'; the base on its own is the videos hub. */
    public const WATCH_BASE = self::ORIGIN . '/videos/';

    /**
     * The catalog client and the new-video menu marker: about 3 KB, footer, no dependencies.
     * It does nothing until this user has opted in to automatic checks.
     */
    public static function enqueue_client(): void
    {
        if (wp_script_is('slimstat-video-catalog')) {
            return;
        }
        wp_enqueue_script('slimstat-video-catalog', plugins_url('/admin/assets/js/video-catalog.js', SLIMSTAT_FILE), [], SLIMSTAT_ANALYTICS_VERSION, true);
        wp_localize_script('slimstat-video-catalog', 'SlimStatVideoConfig', [
            'catalogUrl' => self::CATALOG_URL,
            'origin'     => self::ORIGIN,
            'watchBase'  => self::WATCH_BASE,
            'screen'     => self::SCREEN,
            // Separate installs and subdirectory multisites can share one origin, and with it one
            // localStorage, so the browser state is keyed per site and per user. Hashed only so the
            // key does not spell out the user ID or the site address; it is never sent anywhere.
            'scope'      => substr(md5(home_url('/') . '|' . get_current_user_id()), 0, 12),
            'newVideos'  => __('New video tutorials', 'wp-slimstat'),
            'newPill'    => __('New', 'wp-slimstat'),
        ]);
    }

    /** The Videos screen: its stylesheet, its renderer and the copy the renderer needs. */
    public static function enqueue_page(): void
    {
        self::enqueue_client();
        wp_enqueue_style('wp-slimstat-videos', plugins_url('/admin/assets/css/videos.css', SLIMSTAT_FILE), ['wp-slimstat-tokens'], SLIMSTAT_ANALYTICS_VERSION);
        wp_enqueue_script('slimstat-videos', plugins_url('/admin/assets/js/videos.js', SLIMSTAT_FILE), ['slimstat-video-catalog'], SLIMSTAT_ANALYTICS_VERSION, true);

        $categories = [];
        foreach (self::categories() as $id => $label) {
            $categories[] = ['id' => $id, 'label' => $label];
        }
        wp_localize_script('slimstat-videos', 'SlimStatVideosPage', [
            'categories' => $categories,
            'more'       => __('More videos', 'wp-slimstat'),
            'newPill'    => __('New', 'wp-slimstat'),
            'newTab'     => __('(opens in a new tab)', 'wp-slimstat'),
            'loading'    => __('Loading videos…', 'wp-slimstat'),
            'loaded'     => __('Videos loaded.', 'wp-slimstat'),
            'failed'     => __('The video list could not be loaded.', 'wp-slimstat'),
            'offline'    => __('You appear to be offline.', 'wp-slimstat'),
        ]);
    }

    /**
     * Built-in categories, in their default order. The catalog can reorder them and add new
     * ones without a release; these labels win over the catalog's titles because they are the
     * ones that get translated.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'getting-started'   => __('Getting started', 'wp-slimstat'),
            'dashboard-reports' => __('Dashboard and reports', 'wp-slimstat'),
            'real-time'         => __('Real-time', 'wp-slimstat'),
            'woocommerce'       => __('WooCommerce', 'wp-slimstat'),
            'heatmaps'          => __('Heatmaps', 'wp-slimstat'),
            'goals-funnels'     => __('Goals and funnels', 'wp-slimstat'),
            'advanced'          => __('Advanced features', 'wp-slimstat'),
            'pro'               => __('SlimStat Pro', 'wp-slimstat'),
        ];
    }
}
