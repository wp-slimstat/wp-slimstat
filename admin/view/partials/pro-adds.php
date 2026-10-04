<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed by get_template().
/**
 * One inline "Pro adds …" panel for Free (audit F1, F4): a sentence, Upgrade to Pro and a Compare link. No blur, no modal.
 *
 * @var string $text     What Pro adds here, one sentence.
 * @var string $campaign utm_campaign for the pricing link.
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slimstat-pro-adds">
    <p><?php echo esc_html($text); ?></p>
    <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url('https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=' . rawurlencode($campaign ?? 'pro-adds')); ?>"><?php esc_html_e('Upgrade to Pro', 'wp-slimstat'); ?></a>
    <?php if (current_user_can('manage_options')) : ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=slimpro')); ?>"><?php esc_html_e('Compare Free and Pro', 'wp-slimstat'); ?></a>
    <?php endif; ?>
</div>
