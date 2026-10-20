<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
/** Static report structure, never protected site data. @license GPL-2.0-or-later */
if (!defined('ABSPATH')) { exit; }
$text = $text ?? '';
$campaign = $campaign ?? 'barchart';
$content = $content ?? 'live-chart';
?>
<div class="slimstat-preview">
    <div class="slimstat-preview__chart" aria-hidden="true"><?php for ($i = 0; $i < 18; ++$i) { echo '<span></span>'; } ?></div>
    <?php wp_slimstat_admin::get_template('pro-adds', compact('text', 'campaign', 'content')); ?>
</div>
<p class="slimstat-preview-caption"><?php esc_html_e('Illustrative report preview, not measured activity.', 'wp-slimstat'); ?></p>
