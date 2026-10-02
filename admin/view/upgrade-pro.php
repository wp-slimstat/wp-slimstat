<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
if (!defined('ABSPATH')) { exit; }
$is_pro = wp_slimstat::pro_is_installed();
$commerce = \SlimStat\Ecommerce\Integration::available();
$pricing = 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=plugin&utm_campaign=pro-overview';
$checkout = 'https://my.wp-slimstat.com/checkout/wp-slimstat-pro?tier=1-site&utm_source=wp-slimstat&utm_medium=plugin&utm_campaign=pro-overview';
?>
<div class="wrap-slimstat">
    <?php wp_slimstat_admin::get_template('header', ['is_pro' => $is_pro]); ?>
    <main class="ss-pro">
        <header class="ss-pro-intro">
            <p class="ss-pro-eyebrow"><?php esc_html_e('SlimStat Pro', 'wp-slimstat'); ?></p>
            <h1><?php echo esc_html($is_pro ? __('Your next insight starts here', 'wp-slimstat') : ($commerce ? __('Turn store activity into your next decision', 'wp-slimstat') : __('Turn traffic into your next decision', 'wp-slimstat'))); ?></h1>
            <p><?php esc_html_e('Go deeper into the reports you already use. Compare what works, investigate what needs attention, and share the results with your team.', 'wp-slimstat'); ?></p>
            <div class="ss-pro-actions">
                <?php if ($is_pro) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=slimconfig&tab=8')); ?>"><?php esc_html_e('Manage your license', 'wp-slimstat'); ?></a>
                    <?php if ($commerce && \SlimStat\Ecommerce\Report::canView()) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=slimview7')); ?>"><?php esc_html_e('Open Ecommerce', 'wp-slimstat'); ?></a><?php endif; ?>
                <?php else : ?>
                    <a class="button button-primary" href="<?php echo esc_url($checkout); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get Pro for one site', 'wp-slimstat'); ?></a>
                    <a class="button" href="<?php echo esc_url($pricing); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare all plans', 'wp-slimstat'); ?></a>
                <?php endif; ?>
            </div>
            <?php if (!$is_pro) : ?><p class="ss-pro-note"><?php esc_html_e('One-site annual license. Review current pricing, renewal terms and taxes at checkout. Checkout opens in a new tab so you can return to your reports.', 'wp-slimstat'); ?></p><?php endif; ?>
        </header>
        <section aria-labelledby="ss-pro-compare">
            <h2 id="ss-pro-compare"><?php esc_html_e('What changes with Pro', 'wp-slimstat'); ?></h2>
            <div class="ss-pro-table" tabindex="0" role="region" aria-label="<?php esc_attr_e('Free and Pro comparison', 'wp-slimstat'); ?>">
                <table><thead><tr><th scope="col"><?php esc_html_e('Your next question', 'wp-slimstat'); ?></th><th scope="col"><?php esc_html_e('Included in Free', 'wp-slimstat'); ?></th><th scope="col"><?php esc_html_e('Go further with Pro', 'wp-slimstat'); ?></th></tr></thead><tbody>
                <?php
                $benefits = [];
                if ($commerce) {
                    $benefits[] = [__('Which campaigns contribute to sales?', 'wp-slimstat'), __('Channel and source revenue, plus UTM traffic reports.', 'wp-slimstat'), __('Compare campaign and landing-page net sales and orders.', 'wp-slimstat')];
                    $benefits[] = [__('Where should I investigate next?', 'wp-slimstat'), __('Store totals, product revenue and the observed purchase journey.', 'wp-slimstat'), __('Explore devices, account and guest orders, coupon discounts and product refunds.', 'wp-slimstat')];
                }
                $benefits[] = [__('How can I share the evidence?', 'wp-slimstat'), __('Explore reports inside WordPress.', 'wp-slimstat'), __('Download CSV reports with your dates and filters, or schedule email reports.', 'wp-slimstat')];
                $benefits[] = [__('Where do visitors get stuck?', 'wp-slimstat'), __('Track one goal.', 'wp-slimstat'), __('Track up to five goals and three funnels, and explore clicks with heatmaps.', 'wp-slimstat')];
                foreach ($benefits as $row) : ?><tr><th scope="row"><?php echo esc_html($row[0]); ?></th><td><span class="ss-pro-column-label" aria-hidden="true"><?php esc_html_e('Included in Free', 'wp-slimstat'); ?></span><?php echo esc_html($row[1]); ?></td><td><span class="ss-pro-column-label" aria-hidden="true"><?php esc_html_e('Go further with Pro', 'wp-slimstat'); ?></span><?php echo esc_html($row[2]); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
            <p class="ss-pro-note"><?php esc_html_e('Keep your existing reports and settings. Order attribution still depends on recorded visits, consent and available history. Pro does not recreate missing tracking data.', 'wp-slimstat'); ?></p>
        </section>
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
                <li><a href="https://my.wp-slimstat.com/account" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download Pro from your account', 'wp-slimstat'); ?></a><p><?php esc_html_e('Your account contains the plugin ZIP and license key.', 'wp-slimstat'); ?></p></li>
                <li><?php if (current_user_can('install_plugins')) : ?><a href="<?php echo esc_url(admin_url('plugin-install.php?tab=upload')); ?>"><?php esc_html_e('Upload Pro in WordPress', 'wp-slimstat'); ?></a><?php else : ?><strong><?php esc_html_e('Ask your site administrator to install Pro', 'wp-slimstat'); ?></strong><?php endif; ?><p><?php esc_html_e('Install and activate the ZIP. Keep the free SlimStat plugin active too.', 'wp-slimstat'); ?></p></li>
                <li><strong><?php esc_html_e('Enter your license in SlimStat Settings', 'wp-slimstat'); ?></strong><p><?php esc_html_e('After Pro is active, open the License tab, paste your key and select Save Changes. Then open a report to explore your data.', 'wp-slimstat'); ?></p></li>
                <?php endif; ?>
            </ol>
        </details>
    </main>
</div>
