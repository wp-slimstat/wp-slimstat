<?php
/**
 * Funnels card — modern admin layout for the Funnels section of slimview6.
 *
 * Rendered only from show_funnels() when $is_widget === false.
 *
 * Caller-scope variables:
 *   array  $funnels              — list of funnel records (id, name, steps[])
 *   int    $max_funnels          — from apply_filters('slimstat_max_funnels', 0); 0 on Free
 *   bool   $is_pro               — from wp_slimstat::pro_is_installed()
 *   array  $active_funnel_steps  — precomputed StepResult[] for funnels[0]
 *   array  $active_funnel_summary — ['step_count' => int, 'total_cr' => int|null]
 *
 * @var array  $funnels
 * @var int    $max_funnels
 * @var bool   $is_pro
 * @var array  $active_funnel_steps
 * @var array  $active_funnel_summary
 */

if (!defined('ABSPATH')) {
    exit;
}

$funnel_count = is_array($funnels) ? count($funnels) : 0;
$locked       = $max_funnels <= 0; // Free tier — never rendered via this partial for Pro

if ($locked) :
?>
<section class="slimstat-gf-card slimstat-gf-funnels slimstat-gf-funnels--locked" data-component="funnels" aria-label="<?php esc_attr_e('Funnels — Pro feature preview', 'wp-slimstat'); ?>">
    <header class="slimstat-gf-card__head">
        <div class="slimstat-gf-card__heading">
            <h2 class="slimstat-gf-card__title"><?php esc_html_e('Funnels', 'wp-slimstat'); ?></h2>
            <p class="slimstat-gf-card__subtitle"><?php esc_html_e('String 2–5 goals into a journey. A funnel shows you the conversion rate and exact drop-off at every stage.', 'wp-slimstat'); ?></p>
        </div>
    </header>
    <div class="slimstat-gf-funnel-lock">
        <div class="slimstat-gf-funnel-mock" aria-hidden="true">
            <div class="slimstat-gf-funnel-bars">
                <div class="slimstat-gf-funnel-bar" data-step="1" style="width:100%;"></div>
                <div class="slimstat-gf-funnel-bar" data-step="2" style="width:72%;"></div>
                <div class="slimstat-gf-funnel-bar" data-step="3" style="width:48%;"></div>
                <div class="slimstat-gf-funnel-bar" data-step="4" style="width:28%;"></div>
                <div class="slimstat-gf-funnel-bar" data-step="5" style="width:12%;"></div>
            </div>
        </div>
        <div class="slimstat-gf-funnel-lock__overlay">
            <h3><?php esc_html_e('See where visitors drop off, step by step.', 'wp-slimstat'); ?></h3>
            <p><?php esc_html_e('Unlock drop-off analysis. Build 2–5 step conversion funnels with per-step drop-off in SlimStat Pro.', 'wp-slimstat'); ?></p>
            <a class="button button-primary slimstat-gf-cta"
               href="https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=funnel"
               target="_blank"
               rel="noopener noreferrer">
                <?php esc_html_e('Upgrade to Pro', 'wp-slimstat'); ?>
            </a>
        </div>
    </div>
</section>
<?php
    return;
endif;

$at_max       = $funnel_count >= $max_funnels;
$show_add_cta = !$at_max;
?>
<section class="slimstat-gf-card slimstat-gf-funnels" data-component="funnels">
    <header class="slimstat-gf-card__head">
        <div class="slimstat-gf-card__heading">
            <h2 class="slimstat-gf-card__title"><?php esc_html_e('Funnels', 'wp-slimstat'); ?></h2>
            <p class="slimstat-gf-card__subtitle"><?php esc_html_e('String 2–5 goals into a journey. A funnel shows you the conversion rate and exact drop-off at every stage.', 'wp-slimstat'); ?></p>
        </div>
        <div class="slimstat-gf-card__actions">
            <span class="slimstat-gf-pill"
                  data-role="usage"
                  data-active="<?php echo esc_attr((string) $funnel_count); ?>"
                  data-max="<?php echo esc_attr((string) $max_funnels); ?>">
                <?php echo esc_html(sprintf(
                    /* translators: 1: used funnels, 2: maximum funnels */
                    __('%1$d of %2$d used', 'wp-slimstat'),
                    $funnel_count,
                    $max_funnels
                )); ?>
            </span>
            <?php if ($show_add_cta) : ?>
                <button type="button"
                        class="button button-primary slimstat-gf-cta"
                        data-action="open-funnel-builder"
                        data-mode="create">
                    <?php esc_html_e('+ Add Funnel', 'wp-slimstat'); ?>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (0 === $funnel_count) : ?>
        <div class="slimstat-gf-empty" data-role="funnels-empty">
            <h3 class="slimstat-gf-empty__title"><?php esc_html_e('Start from a template, or build from scratch', 'wp-slimstat'); ?></h3>
            <p class="slimstat-gf-empty__body"><?php esc_html_e('Templates pre-fill dimensions and operators — you just fill in the URLs or events that match your site.', 'wp-slimstat'); ?></p>
            <div class="slimstat-gf-template-picker" role="list">
                <button type="button"
                        class="slimstat-gf-template-card"
                        role="listitem"
                        data-action="open-funnel-builder"
                        data-mode="create"
                        data-template="ecommerce">
                    <span class="slimstat-gf-template-card__title"><?php esc_html_e('E-commerce checkout', 'wp-slimstat'); ?></span>
                    <span class="slimstat-gf-template-card__body"><?php esc_html_e('View product → Cart → Checkout → Thank-you', 'wp-slimstat'); ?></span>
                </button>
                <button type="button"
                        class="slimstat-gf-template-card"
                        role="listitem"
                        data-action="open-funnel-builder"
                        data-mode="create"
                        data-template="saas">
                    <span class="slimstat-gf-template-card__title"><?php esc_html_e('SaaS signup', 'wp-slimstat'); ?></span>
                    <span class="slimstat-gf-template-card__body"><?php esc_html_e('Pricing → Trial → Activation', 'wp-slimstat'); ?></span>
                </button>
                <button type="button"
                        class="slimstat-gf-template-card"
                        role="listitem"
                        data-action="open-funnel-builder"
                        data-mode="create"
                        data-template="content">
                    <span class="slimstat-gf-template-card__title"><?php esc_html_e('Blog engagement', 'wp-slimstat'); ?></span>
                    <span class="slimstat-gf-template-card__body"><?php esc_html_e('Landing → Article → Sign-up', 'wp-slimstat'); ?></span>
                </button>
                <button type="button"
                        class="slimstat-gf-template-card slimstat-gf-template-card--scratch"
                        role="listitem"
                        data-action="open-funnel-builder"
                        data-mode="create"
                        data-template="blank">
                    <span class="slimstat-gf-template-card__title"><?php esc_html_e('+ Blank funnel', 'wp-slimstat'); ?></span>
                    <span class="slimstat-gf-template-card__body"><?php esc_html_e('Define 2–5 custom steps.', 'wp-slimstat'); ?></span>
                </button>
            </div>
        </div>
    <?php else : ?>
        <?php if ($funnel_count > 1) : ?>
            <div class="slimstat-gf-tabs" role="tablist" aria-label="<?php esc_attr_e('Configured funnels', 'wp-slimstat'); ?>">
                <?php foreach ($funnels as $idx => $f) :
                    $is_active = (0 === $idx);
                    ?>
                    <button type="button"
                            class="slimstat-gf-tab<?php echo $is_active ? ' is-active' : ''; ?>"
                            role="tab"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                            data-funnel-id="<?php echo esc_attr((string) ($f['id'] ?? '')); ?>"
                            data-funnel-index="<?php echo esc_attr((string) $idx); ?>"
                            id="slimstat-gf-tab-<?php echo esc_attr((string) $idx); ?>">
                        <?php echo esc_html($f['name'] ?? ''); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($funnels as $idx => $f) :
            $is_active = (0 === $idx);
            $panel_id  = 'slimstat-gf-panel-' . $idx;
            $steps     = $is_active ? $active_funnel_steps : [];
            $summary   = $is_active ? $active_funnel_summary : ['step_count' => count($f['steps'] ?? []), 'total_cr' => null];
            ?>
            <article class="slimstat-gf-funnel-panel<?php echo $is_active ? ' is-active' : ''; ?>"
                     role="tabpanel"
                     id="<?php echo esc_attr($panel_id); ?>"
                     aria-labelledby="slimstat-gf-tab-<?php echo esc_attr((string) $idx); ?>"
                     data-funnel-id="<?php echo esc_attr((string) ($f['id'] ?? '')); ?>"
                     data-funnel-index="<?php echo esc_attr((string) $idx); ?>"
                     data-loaded="<?php echo $is_active ? 'true' : 'false'; ?>"
                     <?php echo $is_active ? '' : 'hidden'; ?>>
                <header class="slimstat-gf-funnel-panel__head">
                    <h3 class="slimstat-gf-funnel-panel__name"><?php echo esc_html($f['name'] ?? ''); ?></h3>
                    <div class="slimstat-gf-funnel-panel__meta">
                        <?php if ($is_active) : ?>
                            <?php include __DIR__ . '/funnel-summary.php'; ?>
                        <?php else : ?>
                            <span class="slimstat-gf-skeleton-text" aria-hidden="true"></span>
                        <?php endif; ?>
                    </div>
                    <div class="slimstat-gf-funnel-panel__actions">
                        <button type="button"
                                class="button-link slimstat-gf-funnel-edit"
                                data-action="open-funnel-builder"
                                data-mode="edit"
                                data-funnel='<?php echo esc_attr(wp_json_encode($f)); ?>'>
                            <?php esc_html_e('Edit', 'wp-slimstat'); ?>
                        </button>
                        <button type="button"
                                class="button-link slimstat-gf-funnel-delete"
                                data-action="delete-funnel"
                                data-funnel-id="<?php echo esc_attr((string) ($f['id'] ?? '')); ?>"
                                data-funnel-name="<?php echo esc_attr($f['name'] ?? ''); ?>">
                            <?php esc_html_e('Delete', 'wp-slimstat'); ?>
                        </button>
                    </div>
                </header>
                <div class="slimstat-gf-funnel-body">
                    <?php if ($is_active) :
                        include __DIR__ . '/funnel-bars.php';
                    else : ?>
                        <div class="slimstat-gf-skeleton" aria-hidden="true">
                            <div class="slimstat-gf-skeleton__row"></div>
                            <div class="slimstat-gf-skeleton__row"></div>
                            <div class="slimstat-gf-skeleton__row"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($at_max) : ?>
            <p class="slimstat-gf-hint">
                <?php echo esc_html(sprintf(
                    /* translators: 1: configured funnels, 2: max funnels */
                    __('%1$d of %2$d used · at limit', 'wp-slimstat'),
                    $funnel_count,
                    $max_funnels
                )); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
