<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
/** Shared, capability-aware upgrade action. @license GPL-2.0-or-later */
if (!defined('ABSPATH')) { exit; }
$text = $text ?? '';
$campaign = $campaign ?? 'pro-adds';
$content = $content ?? 'report';
$activation = \SlimStat\Utils\ProLinks::activation();
?>
<div class="slimstat-pro-adds">
    <p><?php echo esc_html($text); ?></p>
    <?php if (current_user_can('manage_options')) : ?>
        <a class="button button-primary slimstat-upgrade-action" target="_blank" rel="noopener" href="<?php echo esc_url(\SlimStat\Utils\ProLinks::url($campaign, $content)); ?>"><?php echo esc_html($action ?? __('Upgrade to Pro', 'wp-slimstat')); ?></a>
        <?php if ($activation) : ?><a href="<?php echo esc_url($activation); ?>"><?php esc_html_e('Already have Pro? Activate the plugin', 'wp-slimstat'); ?></a><?php else : ?><a href="<?php echo esc_url(\SlimStat\Utils\ProLinks::url($campaign, $content, true)); ?>"><?php esc_html_e('Compare Free and Pro', 'wp-slimstat'); ?></a><?php endif; ?>
    <?php endif; ?>
</div>
