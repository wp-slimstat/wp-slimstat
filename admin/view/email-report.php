<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
    exit;
}

$is_pro = wp_slimstat::pro_is_installed();

if (!$is_pro) {
    // Free: the weekly email from the site's own last 7 days, then one inline panel; no blur and no modal (audit F1, QA C1).
    ?>
    <div class="wrap-slimstat">
        <?php
        wp_slimstat_admin::get_template('header', ['is_pro' => false, 'title' => __('Email Report', 'wp-slimstat')]);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which notice to show after handle_email_sample() redirects here.
        $sample = isset($_GET['sample']) ? sanitize_key($_GET['sample']) : '';
        if ('sent' === $sample) {
            /* translators: %s: the current user's email address */
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Sample sent to %s.', 'wp-slimstat'), wp_get_current_user()->user_email)) . '</p></div>';
        } elseif ('failed' === $sample) {
            echo '<div class="notice notice-error"><p>' . esc_html__('WordPress could not send the email. Check your site\'s mail settings.', 'wp-slimstat') . '</p></div>';
        }
        ?>
        <p><?php esc_html_e('This is your weekly email, built from your site\'s last 7 days.', 'wp-slimstat'); ?></p>
        <section class="slimstat-email-sample" aria-label="<?php esc_attr_e('Sample email', 'wp-slimstat'); ?>">
            <?php wp_slimstat_admin::get_template('email-sample', ['sections' => wp_slimstat_admin::email_sample_sections()]); ?>
        </section>
        <form class="slimstat-email-sample-send" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="slimstat_email_sample">
            <?php wp_nonce_field('slimstat_email_sample'); ?>
            <button type="submit" class="button"><?php esc_html_e('Send me a sample', 'wp-slimstat'); ?></button>
            <span><?php /* translators: %s: the current user's email address */ echo esc_html(sprintf(__('Goes to %s.', 'wp-slimstat'), wp_get_current_user()->user_email)); ?></span>
        </form>
        <?php
        wp_slimstat_admin::get_template('pro-adds', [
            'text'     => __('Pro sends a daily, weekly or monthly summary of your top pages, sources and goals to your team.', 'wp-slimstat'),
            'campaign' => 'email-report',
        ]);
        ?>
    </div>
    <?php
} else {
    // For premium users: show the actual email report content
    ?>
    <div class="backdrop-container">
        <div class="wrap-slimstat slimstat-email-report">
            <?php wp_slimstat_admin::get_template('header', ['is_pro' => true, 'title' => __('Email Report', 'wp-slimstat')]); ?>

            <div class="slimstat-email-report-content">
                <?php
                // Allow pro plugin to inject its email report settings here
                // The pro plugin should hook into this action to display its email report settings
                do_action('slimstat_email_report_content');
                ?>
            </div>
        </div>
    </div>
    <?php
}
?>
