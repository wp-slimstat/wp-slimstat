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
 *   int    $gf_range_start        — SSR-resolved window start (utime), pinned to AJAX (#1)
 *   int    $gf_range_end          — SSR-resolved window end (utime), pinned to AJAX (#1)
 *
 * @var array  $funnels
 * @var int    $max_funnels
 * @var bool   $is_pro
 * @var array  $active_funnel_steps
 * @var array  $active_funnel_summary
 * @var int    $gf_range_start
 * @var int    $gf_range_end
 */

if (!defined('ABSPATH')) {
    exit;
}

$funnel_count = is_array($funnels) ? count($funnels) : 0;
$locked       = $max_funnels <= 0; // Free tier — never rendered via this partial for Pro

// Heading (title + subtitle) and actions (usage pill + Add CTA) now live in the
// postbox header — see wp_slimstat_admin::register_goals_funnels_header_hooks().
if ($locked) :
?>
<section class="slimstat-gf-card slimstat-gf-funnels slimstat-gf-funnels--locked" data-component="funnels" aria-label="<?php esc_attr_e('Funnels: Pro feature preview', 'wp-slimstat'); ?>">
    <div class="slimstat-gf-funnel-lock">
        <div class="slimstat-gf-funnel-mock" aria-hidden="true">
            <div class="slimstat-gf-funnel-bars">
                <div class="slimstat-gf-funnel-bar" style="width:100%;"></div>
                <div class="slimstat-gf-funnel-bar" style="width:72%;"></div>
                <div class="slimstat-gf-funnel-bar" style="width:48%;"></div>
                <div class="slimstat-gf-funnel-bar" style="width:28%;"></div>
                <div class="slimstat-gf-funnel-bar" style="width:12%;"></div>
            </div>
        </div>
        <div class="slimstat-gf-funnel-lock__overlay">
            <p class="slimstat-gf-funnel-lock__caption"><?php esc_html_e('Example funnel', 'wp-slimstat'); ?></p>
            <h3><?php esc_html_e('See where visitors drop off, step by step.', 'wp-slimstat'); ?></h3>
            <p><?php esc_html_e('Build 2 to 5 step funnels and see exactly where visitors drop off at each stage. Available in SlimStat Pro.', 'wp-slimstat'); ?></p>
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

$at_max = $funnel_count >= $max_funnels;

// Template cards — shared by the empty state and the "See templates" reveal that
// keeps templates reachable after the first funnel exists (#7). Each `key` must
// match a FUNNEL_TEMPLATES entry in admin/assets/js/goals-funnels.js. Strings
// are wrapped in __() inline so the WP i18n extractor still finds them.
$template_cards = [
    [
        'key'   => 'woocommerce_purchase',
        'title' => __('WooCommerce purchase', 'wp-slimstat'),
        'body'  => __('Product → Cart → Checkout → Order received', 'wp-slimstat'),
    ],
    [
        'key'   => 'checkout_completion',
        'title' => __('Checkout completion', 'wp-slimstat'),
        'body'  => __('Cart → Checkout → Order received', 'wp-slimstat'),
    ],
    [
        'key'   => 'landing_to_contact',
        'title' => __('Landing to contact', 'wp-slimstat'),
        'body'  => __('Landing → Contact (most form plugins don\'t redirect to thank-you)', 'wp-slimstat'),
    ],
    [
        'key'   => 'pricing_to_checkout',
        'title' => __('Homepage to pricing to checkout', 'wp-slimstat'),
        'body'  => __('Homepage → Pricing → Checkout', 'wp-slimstat'),
    ],
    [
        'key'   => 'landing_to_thanks',
        'title' => __('Landing to thank-you (advanced)', 'wp-slimstat'),
        'body'  => __('Landing → Form → Thank-you (only if you redirect after submit)', 'wp-slimstat'),
    ],
    [
        // The "build from scratch" entry is the primary call to action, not a
        // prefab template — render it as a solid blue button (like the Goals
        // empty-state CTA) instead of a muted dashed card, while keeping it in
        // the template grid. (#15)
        'key'      => 'blank',
        'title'    => __('+ Add funnel', 'wp-slimstat'),
        'body'     => '',
        'modifier' => 'slimstat-gf-template-card--cta',
    ],
];
?>
<section class="slimstat-gf-card slimstat-gf-funnels" data-component="funnels"
         data-gf-range-start="<?php echo esc_attr((string) ($gf_range_start ?? 0)); ?>"
         data-gf-range-end="<?php echo esc_attr((string) ($gf_range_end ?? 0)); ?>">
    <?php if (0 === $funnel_count) : ?>
        <div class="slimstat-gf-empty" data-role="funnels-empty">
            <h3 class="slimstat-gf-empty__title"><?php esc_html_e('Start from a template, or build from scratch', 'wp-slimstat'); ?></h3>
            <p class="slimstat-gf-empty__body"><?php esc_html_e('Templates pre-fill the dimension and operator. You fill in the URLs or events that match your site.', 'wp-slimstat'); ?></p>
            <?php include __DIR__ . '/funnel-template-picker.php'; ?>
        </div>
    <?php else : ?>
        <?php /* "See templates" keeps the prefab gallery reachable after the first
                 funnel exists (it used to vanish). The toggle button lives in the
                 postbox header beside "+ Add funnel" (render_funnels_card_actions);
                 this is the panel it reveals, wired by aria-controls. Gated on
                 !$at_max so we never offer a template that can't be saved. (#7) */ ?>
        <?php if (!$at_max) : ?>
            <div class="slimstat-gf-templates-reveal" data-role="funnels-templates">
                <div class="slimstat-gf-templates-reveal__panel" id="slimstat-gf-templates-panel" data-role="funnels-templates-panel" hidden>
                    <?php include __DIR__ . '/funnel-template-picker.php'; ?>
                </div>
            </div>
        <?php endif; ?>

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
