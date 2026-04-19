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
        $visitors = (int) ($step['visitors'] ?? 0);
        $pct      = (float) ($step['pct'] ?? 0);
        $dropoff  = (int) ($step['dropoff'] ?? 0);
        $width    = $step_one_visitors > 0 ? max(2, (int) round(($visitors / $step_one_visitors) * 100)) : 0;
        $step_num = $index + 1;
        $dropoff_pct = 0;
        if ($index > 0 && !empty($steps[$index - 1]['visitors'])) {
            $dropoff_pct = round(($dropoff / max(1, (int) $steps[$index - 1]['visitors'])) * 100, 1);
        }
        ?>
        <li class="slimstat-gf-step" data-step="<?php echo esc_attr((string) $step_num); ?>">
            <div class="slimstat-gf-step__head">
                <span class="slimstat-gf-step__name"><?php echo esc_html($step['name'] ?? ''); ?></span>
                <span class="slimstat-gf-step__count">
                    <?php echo esc_html(number_format_i18n($visitors)); ?>
                    <span class="slimstat-gf-step__pct">(<?php echo esc_html(number_format_i18n($pct, ((float) $pct == (int) $pct) ? 0 : 1)); ?>%)</span>
                </span>
            </div>
            <div class="slimstat-gf-step__track" role="presentation">
                <div class="slimstat-gf-step__fill"
                     data-step="<?php echo esc_attr((string) $step_num); ?>"
                     style="width:<?php echo esc_attr((string) $width); ?>%;"
                     role="progressbar"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr((string) (int) $pct); ?>"
                     aria-label="<?php echo esc_attr(sprintf(
                         /* translators: 1: step name, 2: visitors */
                         __('%1$s: %2$s visitors', 'wp-slimstat'),
                         (string) ($step['name'] ?? ''),
                         number_format_i18n($visitors)
                     )); ?>"></div>
            </div>
            <?php if ($index > 0 && $dropoff > 0) : ?>
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
