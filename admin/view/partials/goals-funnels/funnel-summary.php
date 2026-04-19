<?php
/**
 * Funnel summary line — shared by funnels-card.php (SSR'd active tab) and
 * goals-funnels.js (AJAX-rendered sibling tabs).
 *
 * Caller-scope variables:
 *   array $summary  — ['step_count' => int, 'total_cr' => int|null]
 *
 * When total_cr === null → "no matching visitors" state (must never render "100%").
 *
 * @var array $summary
 */

if (!defined('ABSPATH')) {
    exit;
}

$step_count = (int) ($summary['step_count'] ?? 0);
$total_cr   = $summary['total_cr'] ?? null;
?>
<?php if ($total_cr === null) : ?>
    <span class="slimstat-gf-summary slimstat-gf-summary--empty">
        <?php esc_html_e('No matching visitors in this date range.', 'wp-slimstat'); ?>
    </span>
<?php else : ?>
    <span class="slimstat-gf-summary">
        <?php echo esc_html(sprintf(
            /* translators: 1: number of steps, 2: conversion rate */
            __('%1$d-step funnel · %2$s%% conversion rate', 'wp-slimstat'),
            $step_count,
            number_format_i18n((float) $total_cr, (is_int($total_cr) ? 0 : 1))
        )); ?>
    </span>
<?php endif; ?>
