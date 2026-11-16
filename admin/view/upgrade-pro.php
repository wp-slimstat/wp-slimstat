<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
if (!defined('ABSPATH')) { exit; }
$is_pro = wp_slimstat::pro_is_installed();
// Pro is active but locked (no key, wrong site, expired or unverified): the next step is its license, not a purchase.
$buyer = !$is_pro && !wp_slimstat::pro_plugin_active();
$commerce = \SlimStat\Ecommerce\Integration::available();
$pricing = \SlimStat\Utils\ProLinks::fromRequest();


// This site's own figures, read from the minute caches the admin bar already fills.
$views = (int) (wp_slimstat_admin::adminbar_today_stats()['views'] ?? 0);
$online = (int) wp_slimstat_admin::online_count();
$goals = count((array) get_option('slimstat_goals', []));
$max_goals = (int) apply_filters('slimstat_max_goals', 1);

$lead = $commerce ? __('Pro shows where your visitors click, where they drop off and what they buy.', 'wp-slimstat') : __('Pro shows where your visitors click, where they drop off and which campaigns bring them back.', 'wp-slimstat');
if ($views > 0) {
    /* translators: %s: number of pageviews recorded today */
    $lead = sprintf(_n('Your site recorded %s pageview today.', 'Your site recorded %s pageviews today.', $views, 'wp-slimstat'), number_format_i18n($views)) . ' ' . $lead;
}
/* translators: %s: number of visitors on the site in the last few minutes */
$live_line = $online > 0 ? sprintf(_n('%s visitor is on your site right now.', '%s visitors are on your site right now.', $online, 'wp-slimstat'), number_format_i18n($online)) : __('When the next visitor arrives, Pro shows it within a minute.', 'wp-slimstat');
$pro_goals = __('Pro tracks five goals and three funnels.', 'wp-slimstat');
if ($goals > 0) {
    /* translators: 1: goals in use, 2: goal limit on this site */
    $goal_line = sprintf(_n('You use %1$s of %2$s goal.', 'You use %1$s of %2$s goals.', $max_goals, 'wp-slimstat'), number_format_i18n($goals), number_format_i18n($max_goals)) . ($is_pro ? '' : ' ' . $pro_goals);
} else {
    $goal_line = $is_pro ? __('Track up to five goals and three funnels.', 'wp-slimstat') : __('Free tracks one goal.', 'wp-slimstat') . ' ' . $pro_goals;
}

// Licensed sites open the city drilldown's report, or the drilldown's own setup link; nothing is saved from here.
$city_setup  = \SlimStat\Reports\CountryCities::setupUrl();
$city_action = null === $city_setup ? [__('Open Audience', 'wp-slimstat'), admin_url('admin.php?page=slimview3')] : [__('Enable City Tracking', 'wp-slimstat'), $city_setup];

// Each feature: [dashicon, title, benefit, on Free, with Pro, optional note, optional [action label, admin URL]].
$ecommerce = ['cart', __('Ecommerce Pro', 'wp-slimstat'), __('Compare campaign and landing-page net sales, then explore devices, guest orders, coupons and refunds.', 'wp-slimstat'), __('Net sales and two revenue-driver rows', 'wp-slimstat'), __('All KPIs, revenue drivers, campaigns, devices, coupons and refunds', 'wp-slimstat'), $commerce ? __('Order attribution still depends on recorded visits, consent and available history. Pro does not recreate missing tracking data.', 'wp-slimstat') : __('For WooCommerce stores.', 'wp-slimstat')];
$converts = [
    ['flag', __('Goals & Funnels', 'wp-slimstat'), __('See where visitors complete each step and where they drop off.', 'wp-slimstat'), __('1 goal, no funnels', 'wp-slimstat'), __('5 goals and 3 funnels', 'wp-slimstat')],
    ['welcome-view-site', __('Heatmaps', 'wp-slimstat'), __('See click and scroll maps for each page on mobile, tablet, and desktop. Find dead clicks and rage clicks, and view campaign visitors separately.', 'wp-slimstat'), __('Two pages with click counts', 'wp-slimstat'), __('All pages, device insights and click overlays', 'wp-slimstat'), __('Pro includes desktop, tablet and mobile previews, goal and campaign visitor filters, CSV downloads and the Heatmap insights email report. Scroll depth and dead/rage clicks require full capture; available history follows your tracking and consent settings.', 'wp-slimstat'), [__('Open Heatmaps', 'wp-slimstat'), admin_url('admin.php?page=slimheatmap')]],
    ['chart-area', __('Channels and UTM Campaigns', 'wp-slimstat'), __('See every channel and campaign that brings visitors.', 'wp-slimstat'), __('Top two summaries; breakdowns need Pro', 'wp-slimstat'), __('Every group, up to your SQL limit', 'wp-slimstat')],
];
if ($commerce) {
    array_unshift($converts, $ecommerce);
} else {
    $converts[] = $ecommerce;
}
$groups = [
    ['converts', __('Find what converts', 'wp-slimstat'), $goal_line, $converts],
    ['ai', __('Read your reports with AI', 'wp-slimstat'), __('Understand your reports, choose what to do next.', 'wp-slimstat'), [
        ['lightbulb', __('AI Analysis', 'wp-slimstat'), __('Click AI Analysis on a report to see what its results mean and which next steps to consider, beside your data. Your latest analysis of each report is kept under Saved analyses.', 'wp-slimstat'), __('Copy Prompt, to paste into your own AI tool', 'wp-slimstat'), __('Analysis beside the report, saved to revisit', 'wp-slimstat'), __('Needs an active Pro license, WordPress 7.0 or newer with its AI features enabled, an AI provider connected in WordPress, and AI Analysis turned on in its settings. Your provider may charge for API use. A report is sent to it only when you ask for an analysis.', 'wp-slimstat'), $is_pro ? [__('Open AI Analysis', 'wp-slimstat'), admin_url('admin.php?page=slimai')] : null],
    ]],
    ['live', __('Watch visitors arrive', 'wp-slimstat'), $live_line, [
        ['clock', __('Real-Time Report', 'wp-slimstat'), __('Follow visits as they happen, without reloading the page.', 'wp-slimstat'), __('Recent visits list', 'wp-slimstat'), __('Live chart, minute by minute', 'wp-slimstat')],
        ['chart-bar', __('Admin Bar Widget', 'wp-slimstat'), __('Check today\'s visitors and pageviews from any screen.', 'wp-slimstat'), __('Visitors online and sessions', 'wp-slimstat'), __('Adds views, referrals and the live chart', 'wp-slimstat')],
    ]],
    ['people', __('Know who is behind the numbers', 'wp-slimstat'), __('Connect visits to the registered users, cities and networks behind them.', 'wp-slimstat'), [
        ['admin-users', __('User Overview', 'wp-slimstat'), __('See what each registered user viewed, and when.', 'wp-slimstat'), __('Not available', 'wp-slimstat'), __('Every registered user, with history', 'wp-slimstat')],
        ['location', __('City-Level Insights', 'wp-slimstat'), __('Select a country on the Audience Location map or in Top Countries to open Top Cities for it, ranked by pageviews for the same dates and filters.', 'wp-slimstat'), __('The top city in each country', 'wp-slimstat'), __('Every recorded city in the country', 'wp-slimstat'), __('Needs Geolocation precision set to City and a provider that supplies cities. Cities are recorded from the moment City precision is on, so earlier pageviews keep their country only, and the provider may not place every visitor.', 'wp-slimstat'), $is_pro ? $city_action : null],
        ['search', __('Advanced Whois', 'wp-slimstat'), __('Look up a visitor\'s location and network details.', 'wp-slimstat'), __('Link to a third-party lookup', 'wp-slimstat'), __('Detail panel inside your admin', 'wp-slimstat')],
    ]],
    ['share', __('Share and own your data', 'wp-slimstat'), __('Send the evidence to your team and keep your data where you choose.', 'wp-slimstat'), [
        ['email-alt', __('Email Reports', 'wp-slimstat'), __('Send the reports you choose to your team on a schedule.', 'wp-slimstat'), __('Not available', 'wp-slimstat'), __('Daily, weekly or monthly', 'wp-slimstat')],
        ['download', __('Data Export', 'wp-slimstat'), __('Download reports with your dates and filters.', 'wp-slimstat'), __('Not available', 'wp-slimstat'), __('CSV, with an optional tab delimiter', 'wp-slimstat')],
        ['database', __('Custom Database', 'wp-slimstat'), __('Store your analytics in a separate database.', 'wp-slimstat'), __('WordPress database only', 'wp-slimstat'), __('Any MySQL-compatible database', 'wp-slimstat')],
        ['networking', __('Network Analytics', 'wp-slimstat'), __('Read every site in a multisite network together.', 'wp-slimstat'), __('One site at a time', 'wp-slimstat'), __('Aggregated across the network', 'wp-slimstat')],
    ]],
];
?>
<div class="wrap-slimstat">
    <?php wp_slimstat_admin::get_template('header', ['is_pro' => $is_pro]); ?>
    <main class="ss-pro">
        <header class="ss-pro-hero ss-pro-reveal">
            <div>
                <p class="ss-pro-eyebrow"><?php esc_html_e('SlimStat Pro', 'wp-slimstat'); ?></p>
                <h1><?php echo esc_html($is_pro ? __('What Pro adds to your reports', 'wp-slimstat') : ($commerce ? __('Turn store activity into your next decision', 'wp-slimstat') : __('Turn traffic into your next decision', 'wp-slimstat'))); ?></h1>
                <?php if ($is_pro) : ?><p class="ss-pro-badge"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Pro is active on this site', 'wp-slimstat'); ?></p><?php endif; ?>
                <p class="ss-pro-lead"><?php echo esc_html($is_pro ? __('Go deeper into the reports you already use. Compare what works, investigate what needs attention, and share the results with your team.', 'wp-slimstat') : $lead); ?></p>
                <div class="ss-pro-actions">
                    <?php if ($is_pro) : ?>
                        <a class="button button-primary ss-pro-cta" href="<?php echo esc_url(admin_url('admin.php?page=slimconfig&tab=8')); ?>"><?php esc_html_e('Manage your license', 'wp-slimstat'); ?></a>
                        <?php if ($commerce && \SlimStat\Ecommerce\Report::canView()) : ?><a class="ss-pro-link" href="<?php echo esc_url(admin_url('admin.php?page=slimview7')); ?>"><?php esc_html_e('Open Ecommerce', 'wp-slimstat'); ?></a><?php endif; ?>
                    <?php elseif (!$buyer) : ?>
                        <?php // The license banner's own first step; users who cannot manage the license get none and the banner says why. ?>
                        <?php if ($license_step = \SlimStat\Services\ProLicense::action()) : ?><a class="button button-primary ss-pro-cta" href="<?php echo esc_url($license_step[1]); ?>"><?php echo esc_html($license_step[0]); ?></a><?php endif; ?>
                    <?php else : ?>
                        <a class="button ss-pro-cta" href="<?php echo esc_url(\SlimStat\Utils\ProLinks::fromRequest(true, 'hero')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Upgrade to Pro', 'wp-slimstat'); ?></a>
                        <a class="ss-pro-link" href="<?php echo esc_url($pricing); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare all plans', 'wp-slimstat'); ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($buyer) : ?><p class="ss-pro-proof"><span aria-hidden="true">&#9733;</span> <?php esc_html_e('4.8/5 on WordPress.org · Trusted by 70,000+ sites · 14-day money-back guarantee', 'wp-slimstat'); ?></p><?php endif; ?>
            </div>
            <nav class="ss-pro-toc" aria-labelledby="ss-pro-toc-title">
                <p id="ss-pro-toc-title"><?php echo esc_html($is_pro ? __('In your plan', 'wp-slimstat') : __('In Pro', 'wp-slimstat')); ?></p>
                <ol>
                    <?php foreach ($groups as $group) : ?><li><a href="#ss-pro-<?php echo esc_attr($group[0]); ?>"><?php echo esc_html($group[1]); ?></a><span><?php echo esc_html(sprintf(/* translators: %s: number of features in this group. */ _n('%s feature', '%s features', count($group[3]), 'wp-slimstat'), number_format_i18n(count($group[3])))); ?></span></li><?php endforeach; ?>
                </ol>
            </nav>
        </header>
        <?php foreach ($groups as $i => [$id, $title, $line, $features]) : ?>
        <section class="ss-pro-group ss-pro-reveal" id="ss-pro-<?php echo esc_attr($id); ?>" aria-labelledby="ss-pro-<?php echo esc_attr($id); ?>-title" style="--i: <?php echo (int) $i + 1; ?>">
            <div>
                <h2 id="ss-pro-<?php echo esc_attr($id); ?>-title"><?php echo esc_html($title); ?></h2>
                <p class="ss-pro-lede"><?php echo esc_html($line); ?></p>
            </div>
            <ul class="ss-pro-features">
                <?php foreach ($features as $feature) : [$icon, $name, $benefit, $free, $pro] = $feature; ?>
                <li class="ss-pro-feature">
                    <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <div>
                        <h3><?php echo esc_html($name); ?></h3>
                        <p><?php echo esc_html($benefit); ?></p><?php if (!empty($feature[6])) : ?><a class="button" href="<?php echo esc_url($feature[6][1]); ?>"><?php echo esc_html($feature[6][0]); ?></a><?php endif; ?>
                        <?php if ($is_pro) : ?>
                        <p class="ss-pro-included"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Included', 'wp-slimstat'); ?></p>
                        <?php else : ?>
                        <p class="ss-pro-delta"><span><b><?php esc_html_e('Free', 'wp-slimstat'); ?></b> <?php echo esc_html($free); ?></span><span class="is-pro"><span class="dashicons dashicons-arrow-right-alt ss-pro-arrow" aria-hidden="true"></span><b><?php esc_html_e('Pro', 'wp-slimstat'); ?></b> <?php echo esc_html($pro); ?></span></p>
                        <?php endif; ?>
                        <?php if (!empty($feature[5])) : ?><p class="ss-pro-note"><?php echo esc_html($feature[5]); ?></p><?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
        <?php if ($buyer) : ?>
        <section class="ss-pro-promise" aria-labelledby="ss-pro-promise-title">
            <h2 id="ss-pro-promise-title"><?php esc_html_e('Try Pro for 14 days. If it is not right for your site, get a full refund, no questions asked.', 'wp-slimstat'); ?></h2>
            <ul>
                <li><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Your data stays on your server.', 'wp-slimstat'); ?></li>
                <li><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e('Cancel renewal anytime.', 'wp-slimstat'); ?></li>
                <li><span class="dashicons dashicons-backup" aria-hidden="true"></span><?php esc_html_e('Your reports and settings carry over.', 'wp-slimstat'); ?></li>
            </ul>
            <div class="ss-pro-actions">
                <a class="button ss-pro-cta" href="<?php echo esc_url(\SlimStat\Utils\ProLinks::fromRequest(true, 'footer')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Upgrade to Pro', 'wp-slimstat'); ?></a>
                <a class="ss-pro-link" href="<?php echo esc_url($pricing); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare all plans', 'wp-slimstat'); ?></a>
            </div>
            <p class="ss-pro-note"><?php esc_html_e('One-site annual license. Review current pricing, renewal terms and taxes at checkout. Checkout opens in a new tab so you can return to your reports.', 'wp-slimstat'); ?></p>
        </section>
        <?php endif; ?>
        <?php if (!$is_pro) : ?>
        <section class="ss-pro-faq" aria-labelledby="ss-pro-faq-title">
            <h2 id="ss-pro-faq-title"><?php esc_html_e('Questions before you upgrade', 'wp-slimstat'); ?></h2>
            <div>
                <details><summary><span dir="auto"><?php esc_html_e('Will I lose my existing reports or settings?', 'wp-slimstat'); ?></span></summary><p><?php esc_html_e('No. Pro runs alongside the free plugin and reads the same data, so your reports, settings and history stay as they are.', 'wp-slimstat'); ?></p></details>
                <details><summary><span dir="auto"><?php esc_html_e('Does Pro send my analytics to a third party?', 'wp-slimstat'); ?></span></summary><p><?php esc_html_e('Only to services you choose. Pro reads the analytics already in your database. Advanced Whois uses the geolocation provider you chose in Settings, AI Analysis sends a report to your connected AI provider only when you ask for an analysis, and only license checks and updates contact wp-slimstat.com.', 'wp-slimstat'); ?></p></details>
                <details><summary><span dir="auto"><?php esc_html_e('What happens if my license expires?', 'wp-slimstat'); ?></span></summary><p><?php esc_html_e('The free plugin keeps working and your data stays in your database. Renew to keep receiving Pro updates.', 'wp-slimstat'); ?></p></details>
                <details><summary><span dir="auto"><?php esc_html_e('Which license do I need for several sites?', 'wp-slimstat'); ?></span></summary><p><?php esc_html_e('Each plan covers a set number of sites.', 'wp-slimstat'); ?> <a href="<?php echo esc_url($pricing); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('See plans for several sites', 'wp-slimstat'); ?></a></p></details>
            </div>
        </section>
        <?php endif; ?>
        <details class="ss-pro-setup" <?php if ($is_pro) { echo 'open'; } ?>>
            <summary><?php echo esc_html($is_pro ? __('Get the most from your Pro purchase', 'wp-slimstat') : __('Already purchased Pro?', 'wp-slimstat')); ?></summary>
            <ol>
                <?php if ($is_pro) : ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=slimconfig&tab=8')); ?>"><?php esc_html_e('Manage your license', 'wp-slimstat'); ?></a><p><?php esc_html_e('Verify your key to receive Pro updates for this site.', 'wp-slimstat'); ?></p></li>
                <?php if ($commerce && \SlimStat\Ecommerce\Report::canView()) : ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=slimview7#ss-ec-panel-campaign')); ?>"><?php esc_html_e('Compare campaign revenue', 'wp-slimstat'); ?></a><p><?php esc_html_e('Choose a date range, explore your campaigns and use View report to export the results.', 'wp-slimstat'); ?></p></li>
                <?php else : ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=slimview5#slimstat-utm-builder')); ?>"><?php esc_html_e('Build your first campaign link', 'wp-slimstat'); ?></a><p><?php esc_html_e('Tag an incoming link to see its recorded pageviews in UTM Campaigns.', 'wp-slimstat'); ?></p></li>
                <?php endif; ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=slimemail')); ?>"><?php esc_html_e('Set up email reports', 'wp-slimstat'); ?></a><p><?php esc_html_e('Choose the reports, recipients and schedule that work for your team.', 'wp-slimstat'); ?></p></li>
                <?php else : ?>
                <?php if ($buyer) : // Pro is already installed and active when only its license is missing. ?>
                <li><a href="<?php echo esc_url(\SlimStat\Utils\ProLinks::tag('https://my.wp-slimstat.com/account', 'pro-overview', 'download-pro')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download Pro from your account', 'wp-slimstat'); ?></a><p><?php esc_html_e('Your account contains the plugin ZIP and license key.', 'wp-slimstat'); ?></p></li>
                <li><?php if (current_user_can('install_plugins')) : ?><a href="<?php echo esc_url(admin_url('plugin-install.php?tab=upload')); ?>"><?php esc_html_e('Upload Pro in WordPress', 'wp-slimstat'); ?></a><?php else : ?><strong><?php esc_html_e('Ask your site administrator to install Pro', 'wp-slimstat'); ?></strong><?php endif; ?><p><?php esc_html_e('Install and activate the ZIP. Keep the free SlimStat plugin active too.', 'wp-slimstat'); ?></p></li>
                <?php endif; ?>
                <li><strong><?php esc_html_e('Enter your license in SlimStat Settings', 'wp-slimstat'); ?></strong><p><?php esc_html_e('After Pro is active, open the License tab, paste your key and select Save Changes. Then open a report to explore your data.', 'wp-slimstat'); ?></p></li>
                <?php endif; ?>
            </ol>
        </details>
    </main>
</div>
