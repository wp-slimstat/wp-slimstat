<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by wp_slimstat_admin::wp_slimstat_include_heatmaps(); local template variables.
if (!defined('ABSPATH')) {
    exit;
}

use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;

$is_pro   = wp_slimstat::pro_is_installed();
$viewer   = has_filter('slimstat_heatmap_row_url');
$is_admin = current_user_can('manage_options');
$state    = get_option(Store::STATE, []);
// One page's heatmap (Pro) replaces the list. The key reaches only prepared queries and escaped output.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view selector; pageKey() normalises it.
$page_key = $viewer && isset($_GET['heatmap']) ? Query::pageKey((string) wp_unslash($_GET['heatmap'])) : '';
$error    = 'off' !== Store::level() ? (string) ($state['error'] ?? '') : '';

wp_localize_script('slimstat-heatmaps', 'SlimStatHeatmaps', [
    'route' => '/slimstat/v1/heatmap/pages',
    // Free: rows open the Pro modal. Pro without the row filter is an older Pro: ask to update.
    'mode'  => $viewer ? 'pro' : ($is_pro ? 'update' : 'free'),
    'home'  => home_url('/'),
]);

$columns = [
    'page'      => [__('Page', 'wp-slimstat'), __('Page title and address.', 'wp-slimstat')],
    'clicks'    => [__('Clicks', 'wp-slimstat'), __('Clicks recorded on this page in the date range.', 'wp-slimstat')],
    'pageviews' => [__('Pageviews', 'wp-slimstat'), __('Pageviews of this page in the date range.', 'wp-slimstat')],
    'cpp'       => [__('Clicks per pageview', 'wp-slimstat'), __('Average clicks each pageview of this page received.', 'wp-slimstat')],
    'devices'   => [__('Devices', 'wp-slimstat'), __('Clicks by device: desktop, tablet and mobile.', 'wp-slimstat')],
    'scroll'    => [__('Scroll depth', 'wp-slimstat'), __('How far down the page visitors scrolled, on average. Needs full tracking.', 'wp-slimstat')],
    'dead'      => [__('Dead & rage', 'wp-slimstat'), __('Dead clicks: clicks on things that aren\'t links or buttons. Rage clicks: 3 or more quick clicks in the same spot.', 'wp-slimstat')],
    'last'      => [__('Last click', 'wp-slimstat'), __('Date of the most recent click on this page.', 'wp-slimstat')],
    'full'      => [__('Tracking', 'wp-slimstat'), __('What the data covers: all clicks and scroll depth, or link and button clicks only.', 'wp-slimstat')],
];

$fix = '';
if ('' !== $error) {
    if (preg_match('/command denied|access denied|permission/i', $error)) {
        $reason = __('the database user is not allowed to create tables', 'wp-slimstat');
        $fix    = __('Ask your host to give the database user CREATE permission.', 'wp-slimstat');
    } elseif (preg_match('/is full|no space left|errno: ?28/i', $error)) {
        $reason = __('the database server is out of space', 'wp-slimstat');
        $fix    = __('The database server is out of space; contact your host.', 'wp-slimstat');
    } else {
        $reason = __('the database returned an error', 'wp-slimstat');
    }
}
?>
<div class="backdrop-container">
<div class="wrap-slimstat slimstat-heatmaps">
    <?php wp_slimstat_admin::get_template('header', ['is_pro' => $is_pro]); ?>
    <div class="ss-hm">
        <?php if ('' === $page_key) : ?>
        <div class="ss-hm-intro">
            <h1><?php esc_html_e('Heatmaps', 'wp-slimstat'); ?></h1>
            <p>
                <?php
                echo $viewer
                    ? esc_html__('See where visitors click on each page and how far they scroll.', 'wp-slimstat')
                    : esc_html__('See which pages get clicks, from the link and button clicks SlimStat already records. SlimStat Pro opens each page\'s click and scroll heatmap.', 'wp-slimstat');
                ?>
            </p>
        </div>
        <?php endif; ?>

        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the nonce-checked delete redirect. ?>
        <?php if (isset($_GET['deleted'])) : ?>
            <div class="ss-hm-card" role="status">
                <p><?php echo '1' === $_GET['deleted'] ? esc_html__('Heatmap data deleted. Link and button click history is kept.', 'wp-slimstat') : esc_html__('Heatmap data could not be deleted. Try again in a minute.', 'wp-slimstat'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
            </div>
        <?php endif; ?>

        <?php if ('' !== $error) : ?>
            <div class="ss-hm-card ss-hm-card--error" role="alert">
                <p>
                    <?php
                    /* translators: 1: why the heatmap tables could not be created, 2: what to do about it (may be empty). */
                    echo esc_html(trim(sprintf(__('Heatmap tables could not be created: %1$s. %2$s Link and button click history still works.', 'wp-slimstat'), $reason, $fix)));
                    ?>
                </p>
                <?php if ('' === $fix) : ?>
                    <details><summary><?php esc_html_e('Details', 'wp-slimstat'); ?></summary><code><?php echo esc_html($error); ?></code></details>
                <?php endif; ?>
                <?php if ($is_admin) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="slimstat_heatmap_retry">
                        <?php wp_nonce_field('slimstat_heatmap_retry'); ?>
                        <button type="submit" class="button"><?php esc_html_e('Retry', 'wp-slimstat'); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ('' !== $page_key) : ?>
            <?php
            /**
             * Renders one page's heatmap. Pro hooks it; the list below is skipped.
             *
             * @param string $page_key Page key, as Query::pageKey() returns it.
             */
            do_action('slimstat_heatmap_viewer', $page_key);
            ?>
        <?php else : ?>

        <?php do_action('slimstat_heatmap_content'); ?>

        <form class="ss-hm-toolbar" role="search" onsubmit="return false">
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Date range', 'wp-slimstat'); ?></span>
                <select name="range">
                    <option value="7"><?php esc_html_e('Last 7 days', 'wp-slimstat'); ?></option>
                    <option value="30" selected><?php esc_html_e('Last 30 days', 'wp-slimstat'); ?></option>
                    <option value="90"><?php esc_html_e('Last 90 days', 'wp-slimstat'); ?></option>
                    <option value="custom"><?php esc_html_e('Custom', 'wp-slimstat'); ?></option>
                </select>
            </label>
            <span class="ss-hm-custom" hidden>
                <label><?php esc_html_e('From', 'wp-slimstat'); ?> <input type="date" name="from"></label>
                <label><?php esc_html_e('To', 'wp-slimstat'); ?> <input type="date" name="to"></label>
            </span>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Device', 'wp-slimstat'); ?></span>
                <select name="device">
                    <option value=""><?php esc_html_e('All devices', 'wp-slimstat'); ?></option>
                    <option value="desktop"><?php esc_html_e('Desktop', 'wp-slimstat'); ?></option>
                    <option value="tablet"><?php esc_html_e('Tablet', 'wp-slimstat'); ?></option>
                    <option value="mobile"><?php esc_html_e('Mobile', 'wp-slimstat'); ?></option>
                </select>
            </label>
            <label class="ss-hm-search">
                <span class="screen-reader-text"><?php esc_html_e('Search pages', 'wp-slimstat'); ?></span>
                <input type="search" name="q" placeholder="<?php esc_attr_e('Search pages, e.g. /pricing', 'wp-slimstat'); ?>">
            </label>
            <span class="ss-hm-updated" aria-live="polite"></span>
            <button type="button" class="button-link ss-hm-refresh"><?php esc_html_e('Refresh', 'wp-slimstat'); ?></button>
        </form>

        <div class="ss-hm-table-wrap">
            <table class="ss-hm-table" aria-busy="true">
                <thead>
                    <tr>
                        <?php foreach ($columns as $key => [$label, $help]) : ?>
                            <th scope="col" data-sort="<?php echo esc_attr($key); ?>" <?php echo 'clicks' === $key ? 'aria-sort="descending"' : ''; ?>>
                                <button type="button" title="<?php echo esc_attr($help); ?>"><?php echo esc_html($label); ?></button>
                            </th>
                        <?php endforeach; ?>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'wp-slimstat'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < 5; $i++) : ?>
                        <tr class="ss-hm-skeleton"><td colspan="<?php echo count($columns) + 1; ?>"><span></span></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="ss-hm-empty" hidden role="status"></div>
        <template id="ss-hm-demo">
            <svg class="ss-hm-demo" viewBox="0 0 120 72" aria-hidden="true" focusable="false">
                <rect x="1" y="1" width="118" height="70" rx="6" class="ss-hm-demo-page"/>
                <rect x="10" y="10" width="44" height="6" rx="3" class="ss-hm-demo-line"/>
                <rect x="10" y="22" width="70" height="4" rx="2" class="ss-hm-demo-line"/>
                <rect x="10" y="30" width="60" height="4" rx="2" class="ss-hm-demo-line"/>
                <rect x="10" y="44" width="30" height="10" rx="5" class="ss-hm-demo-button"/>
                <circle cx="25" cy="49" r="10" class="ss-hm-demo-spot"/>
                <circle cx="96" cy="13" r="7" class="ss-hm-demo-spot ss-hm-demo-spot--2"/>
                <circle cx="70" cy="58" r="5" class="ss-hm-demo-spot ss-hm-demo-spot--3"/>
            </svg>
        </template>
        <nav class="ss-hm-pager" hidden aria-label="<?php esc_attr_e('Pages of results', 'wp-slimstat'); ?>">
            <button type="button" class="button" data-step="-1"><?php esc_html_e('Previous', 'wp-slimstat'); ?></button>
            <span></span>
            <button type="button" class="button" data-step="1"><?php esc_html_e('Next', 'wp-slimstat'); ?></button>
        </nav>

        <?php if ($is_admin && Store::ready()) : ?>
            <p class="ss-hm-delete"><button type="button" class="button-link" data-dialog="ss-hm-delete"><?php esc_html_e('Delete heatmap data', 'wp-slimstat'); ?></button></p>
            <dialog id="ss-hm-delete" class="ss-hm-dialog">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <p><?php esc_html_e('Delete all full-tracking heatmap data? Link and button click history is kept. This can\'t be undone.', 'wp-slimstat'); ?></p>
                    <input type="hidden" name="action" value="slimstat_heatmap_delete">
                    <?php wp_nonce_field('slimstat_heatmap_delete'); ?>
                    <div class="ss-hm-dialog-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Delete heatmap data', 'wp-slimstat'); ?></button>
                        <button type="submit" class="button" formmethod="dialog" formnovalidate><?php esc_html_e('Cancel', 'wp-slimstat'); ?></button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>

        <?php if (!$viewer) : ?>
            <dialog id="ss-hm-locked" class="ss-hm-dialog" aria-labelledby="ss-hm-locked-title">
                <form method="dialog">
                    <?php if ($is_pro) : ?>
                        <h2 id="ss-hm-locked-title"><?php esc_html_e('Heatmaps', 'wp-slimstat'); ?></h2>
                        <p><?php esc_html_e('Update SlimStat Pro to open heatmaps here. The on-page heatmap keeps working until you do.', 'wp-slimstat'); ?></p>
                        <div class="ss-hm-dialog-actions">
                            <a class="button button-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php esc_html_e('Go to Plugins', 'wp-slimstat'); ?></a>
                            <button class="button"><?php esc_html_e('Not now', 'wp-slimstat'); ?></button>
                        </div>
                    <?php else : ?>
                        <h2 id="ss-hm-locked-title" data-heading></h2>
                        <p data-body></p>
                        <div class="ss-hm-dialog-actions">
                            <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url('https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=heatmaps'); ?>"><?php esc_html_e('Get SlimStat Pro', 'wp-slimstat'); ?></a>
                            <button class="button"><?php esc_html_e('Not now', 'wp-slimstat'); ?></button>
                        </div>
                        <p><a href="<?php echo esc_url(admin_url('plugin-install.php?tab=upload')); ?>"><?php esc_html_e('Already have Pro? Install it', 'wp-slimstat'); ?></a></p>
                    <?php endif; ?>
                </form>
            </dialog>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>
