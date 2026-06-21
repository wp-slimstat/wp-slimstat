<?php
/**
 * Funnel bars — renders per-step visitor bars + drop-off indicators.
 *
 * Caller-scope variables:
 *   array $steps — list of StepResult { name, visitors, pct, dropoff }
 *
 * @var array $steps
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($steps) || !is_array($steps)) {
    echo '<p class="slimstat-gf-empty__body">' . esc_html__('No data yet for this funnel.', 'wp-slimstat') . '</p>';
    return;
}

$step_one_visitors = (int) ($steps[0]['visitors'] ?? 0);
?>
<ol class="slimstat-gf-steps" role="list">
    <?php foreach ($steps as $index => $step) :
        $visitors    = (int) ($step['visitors'] ?? 0);
        $pct         = (float) ($step['pct'] ?? 0);
        $dropoff     = (int) ($step['dropoff'] ?? 0);
        $unreachable = !empty($step['unreachable']);
        $width       = $step_one_visitors > 0 ? max(2, (int) round(($visitors / $step_one_visitors) * 100)) : 0;
        $step_num    = $index + 1;
        // One formatted percentage, reused by the visible label and aria-valuetext
        // so the two can never drift (mirrors $pctLabel in goals-funnels.js).
        $pct_label   = number_format_i18n($pct, ((float) $pct == (int) $pct) ? 0 : 1);
        $dropoff_pct = 0;
        if ($index > 0 && !empty($steps[$index - 1]['visitors'])) {
            $dropoff_pct = round(($dropoff / max(1, (int) $steps[$index - 1]['visitors'])) * 100, 1);
        }
        ?>
        <li class="slimstat-gf-step<?php echo $unreachable ? ' slimstat-gf-step--unreachable' : ''; ?>" data-step="<?php echo esc_attr((string) $step_num); ?>">
            <div class="slimstat-gf-step__head">
                <span class="slimstat-gf-step__name"><?php echo esc_html($step['name'] ?? ''); ?></span>
                <span class="slimstat-gf-step__count" title="<?php esc_attr_e('Unique visitors who reached this step', 'wp-slimstat'); ?>">
                    <?php echo esc_html(number_format_i18n($visitors)); ?>
                    <span class="slimstat-gf-step__pct">(<?php echo esc_html($pct_label); ?>%)</span>
                </span>
            </div>
            <div class="slimstat-gf-step__track" role="presentation">
                <div class="slimstat-gf-step__fill"
                     <?php echo 0 === $visitors ? 'data-zero' : ''; ?>
                     style="width:<?php echo esc_attr((string) $width); ?>%;"
                     role="progressbar"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr((string) (int) $pct); ?>"
                     aria-valuetext="<?php echo esc_attr($pct_label . '%'); ?>"
                     aria-label="<?php echo esc_attr(sprintf(
                         /* translators: 1: step name, 2: visitors */
                         __('%1$s: %2$s visitors', 'wp-slimstat'),
                         (string) ($step['name'] ?? ''),
                         number_format_i18n($visitors)
                     )); ?>"></div>
            </div>
            <?php if ($unreachable) : ?>
                <?php /* A zero step after a non-zero one is a valid outcome (no
                         conversions in this window/order), not an error — keep the
                         message neutral and drop the warning glyph. (#9) */ ?>
                <div class="slimstat-gf-step__unreachable">
                    <?php esc_html_e('No visitors reached this step in the selected date range', 'wp-slimstat'); ?>
                </div>
            <?php elseif ($index > 0 && $dropoff > 0) : ?>
                <div class="slimstat-gf-step__dropoff">
                    <?php echo esc_html(sprintf(
                        /* translators: 1: visitors dropped, 2: drop-off percentage */
                        __('↓ %1$s dropped (%2$s%%)', 'wp-slimstat'),
                        number_format_i18n($dropoff),
                        number_format_i18n($dropoff_pct, 1)
                    )); ?>
                </div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
