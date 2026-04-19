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

$step_count        = (int) ($summary['step_count'] ?? 0);
$total_cr          = $summary['total_cr'] ?? null;
$unreachable_count = (int) ($summary['unreachable_count'] ?? 0);
$is_healthy_100    = ($total_cr !== null && (float) $total_cr === 100.0 && $unreachable_count === 0 && $step_count > 1);
?>
<?php if ($total_cr === null) : ?>
    <span class="slimstat-gf-summary slimstat-gf-summary--empty">
        <?php esc_html_e('No visitors matched in this date range', 'wp-slimstat'); ?>
    </span>
<?php elseif ($is_healthy_100) : ?>
    <span class="slimstat-gf-summary slimstat-gf-summary--success">
        <span class="slimstat-gf-summary__glyph" aria-hidden="true">✓</span>
        <?php echo esc_html(sprintf(
            /* translators: %d is the step count */
            __('Healthy pass-through · %d-step funnel', 'wp-slimstat'),
            $step_count
        )); ?>
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
<?php if ($unreachable_count > 0) : ?>
    <span class="slimstat-gf-summary slimstat-gf-summary--warn">
        <?php echo esc_html(sprintf(
            /* translators: %d is the number of unreachable steps */
            _n('%d step unreachable', '%d steps unreachable', $unreachable_count, 'wp-slimstat'),
            $unreachable_count
        )); ?>
    </span>
<?php endif; ?>
