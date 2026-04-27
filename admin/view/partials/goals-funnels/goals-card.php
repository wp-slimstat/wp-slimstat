<?php
/**
 * Goals card — modern admin layout for the Goals section of slimview6.
 *
 * Rendered only from show_goals() when $is_widget === false.
 *
 * Caller-scope variables:
 *   array  $goals             — list of goal records (id, name, dimension, operator, value, active)
 *   int    $max_goals         — from apply_filters('slimstat_max_goals', 1)
 *   int    $active_count      — count of goals with active=true
 *   array  $dimensions        — key => label
 *   array  $operators         — list of keys
 *   array  $operator_labels   — key => label
 *   bool   $is_pro            — from wp_slimstat::pro_is_installed()
 *   string $consent_notice    — prebuilt string or empty
 *
 * @var array  $goals
 * @var int    $max_goals
 * @var int    $active_count
 * @var array  $dimensions
 * @var array  $operators
 * @var array  $operator_labels
 * @var bool   $is_pro
 * @var string $consent_notice
 */

if (!defined('ABSPATH')) {
    exit;
}

// Heading (title + subtitle) and actions (usage pill + Add CTA) now live in the
// postbox header — see wp_slimstat_admin::register_goals_funnels_header_hooks()
// hooks on slimstat_report_header_after_title + slimstat_report_header_buttons.
$at_max      = $active_count >= $max_goals;
$show_upsell = $at_max && !$is_pro;
?>
<section class="slimstat-gf-card slimstat-gf-goals" data-component="goals">
    <?php if (empty($goals)) : ?>
        <div class="slimstat-gf-empty" data-role="goals-empty">
            <h3 class="slimstat-gf-empty__title"><?php esc_html_e('Measure what matters', 'wp-slimstat'); ?></h3>
            <p class="slimstat-gf-empty__body">
                <?php esc_html_e('Get started with conversion tracking. Track signup, checkout, and pricing views at the same time — plus funnels for drop-off analysis.', 'wp-slimstat'); ?>
            </p>
            <p class="slimstat-gf-empty__note">
                <?php esc_html_e('Retroactive — each goal evaluates your full history, no warm-up needed.', 'wp-slimstat'); ?>
            </p>
            <button type="button"
                    class="button button-primary slimstat-gf-cta"
                    data-action="open-goal-drawer"
                    data-mode="create">
                <?php esc_html_e('+ Add your first goal', 'wp-slimstat'); ?>
            </button>
        </div>
    <?php else : ?>
        <ul class="slimstat-gf-goal-list" role="list">
            <?php foreach ($goals as $goal) :
                $goal_active   = !empty($goal['active']);
                $dim_key       = $goal['dimension'] ?? '';
                $op_key        = $goal['operator'] ?? '';
                $dim_label     = $dimensions[$dim_key] ?? $dim_key;
                $op_label      = $operator_labels[$op_key] ?? $op_key;
                $value_display = (string) ($goal['value'] ?? '');
                $results       = wp_slimstat_db::get_goal_results($goal);
                $uniques       = (int) ($results['uniques'] ?? 0);
                $total         = (int) ($results['total'] ?? 0);
                $cr            = $results['cr'] ?? 0;
                $goal_id_attr  = esc_attr((string) ($goal['id'] ?? ''));
                ?>
                <li class="slimstat-gf-goal"
                    data-goal-id="<?php echo $goal_id_attr; ?>"
                    data-active="<?php echo $goal_active ? 'true' : 'false'; ?>">
                    <div class="slimstat-gf-goal__head">
                        <h3 class="slimstat-gf-goal__name">
                            <?php echo esc_html($goal['name'] ?? ''); ?>
                            <?php if (!$goal_active) : ?>
                                <span class="slimstat-gf-pill slimstat-gf-pill--paused"
                                      title="<?php esc_attr_e('Paused goals don\'t count against the limit', 'wp-slimstat'); ?>">
                                    <?php esc_html_e('Paused', 'wp-slimstat'); ?>
                                </span>
                            <?php endif; ?>
                        </h3>
                        <div class="slimstat-gf-goal__rule">
                            <span class="slimstat-gf-rule-chip">
                                <strong><?php echo esc_html($dim_label); ?></strong>
                                <em><?php echo esc_html($op_label); ?></em>
                                <?php if ('' !== $value_display) : ?>
                                    <code><?php echo esc_html($value_display); ?></code>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="slimstat-gf-goal__metrics">
                        <div class="slimstat-gf-metric">
                            <span class="slimstat-gf-metric__label"><?php esc_html_e('Uniques', 'wp-slimstat'); ?></span>
                            <span class="slimstat-gf-metric__value"><?php echo esc_html(number_format_i18n($uniques)); ?></span>
                        </div>
                        <div class="slimstat-gf-metric">
                            <span class="slimstat-gf-metric__label"><?php esc_html_e('Total', 'wp-slimstat'); ?></span>
                            <span class="slimstat-gf-metric__value"><?php echo esc_html(number_format_i18n($total)); ?></span>
                        </div>
                        <div class="slimstat-gf-metric">
                            <span class="slimstat-gf-metric__label"><?php esc_html_e('CR', 'wp-slimstat'); ?></span>
                            <span class="slimstat-gf-metric__value"><?php echo esc_html((string) $cr); ?>%</span>
                        </div>
                    </div>
                    <div class="slimstat-gf-goal__actions">
                        <button type="button"
                                class="button-link slimstat-gf-goal-edit"
                                data-action="open-goal-drawer"
                                data-mode="edit"
                                data-goal='<?php echo esc_attr(wp_json_encode($goal)); ?>'>
                            <?php esc_html_e('Edit', 'wp-slimstat'); ?>
                        </button>
                        <button type="button"
                                class="button-link slimstat-gf-goal-delete"
                                data-action="delete-goal"
                                data-goal-id="<?php echo $goal_id_attr; ?>"
                                data-goal-name="<?php echo esc_attr($goal['name'] ?? ''); ?>">
                            <?php esc_html_e('Delete', 'wp-slimstat'); ?>
                        </button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($show_upsell) : ?>
        <div class="slimstat-gf-upsell" role="note">
            <strong><?php esc_html_e('You\'ve hit the Free limit — 1 of 1 goals used.', 'wp-slimstat'); ?></strong>
            <?php echo wp_kses(
                sprintf(
                    /* translators: %s is a link */
                    __('%s to track up to 5 goals and unlock 3 drop-off funnels.', 'wp-slimstat'),
                    '<a href="https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=goals" target="_blank" rel="noopener noreferrer">' . esc_html__('Upgrade to Pro', 'wp-slimstat') . '</a>'
                ),
                ['a' => ['href' => [], 'target' => [], 'rel' => []]]
            ); ?>
        </div>
    <?php elseif ($at_max && $is_pro) : ?>
        <p class="slimstat-gf-hint">
            <?php echo esc_html(sprintf(
                /* translators: 1: active goals, 2: max goals */
                __('%1$d of %2$d used · at limit', 'wp-slimstat'),
                $active_count,
                $max_goals
            )); ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($consent_notice)) : ?>
        <p class="slimstat-gf-consent"><em><?php echo esc_html($consent_notice); ?></em></p>
    <?php endif; ?>
</section>
